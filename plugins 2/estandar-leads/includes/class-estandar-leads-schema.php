<?php

if (!defined('ABSPATH')) {
    exit;
}

class Estandar_Leads_Schema
{
    const SCHEMA_VERSION = '3.8.0';
    const SECRET_OPTION = 'estandar_leads_secret';

    public static function activate()
    {
        self::ensure_secret();
        self::create_tables();
        update_option('estandar_leads_schema_version', self::SCHEMA_VERSION, false);
    }

    public static function maybe_migrate()
    {
        self::ensure_secret();
        if (get_option('estandar_leads_schema_version') !== self::SCHEMA_VERSION) {
            self::create_tables();
            update_option('estandar_leads_schema_version', self::SCHEMA_VERSION, false);
        }
        // Idempotent backfills — safe on every load, skip records already complete.
        self::backfill_operator_uuids();
        self::backfill_lead_uuids();
        self::backfill_legacy_classifications();
        self::backfill_identity_fields();
        self::migrate_legacy_commercial_log();
        self::migrate_customer_unique_external();
    }

    /**
     * One-time, idempotent drain of the legacy option-based Commercial log into the
     * dedicated table. Execution logs may not live in wp_options; this removes the
     * old option after import. No-op once the option is gone (almost always empty,
     * since the Meta path is dormant until configured).
     */
    public static function migrate_legacy_commercial_log()
    {
        global $wpdb;

        $legacy = get_option('estandar_commercial_conversions_log', null);
        if ($legacy === null) {
            return; // nothing to migrate
        }

        $table = $wpdb->prefix . 'es_commercial_outcomes_log';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table))) {
            foreach ((array) $legacy as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $context = isset($entry['context']) && is_array($entry['context']) ? $entry['context'] : array();
                $wpdb->insert($table, array(
                    'lead_id'       => isset($context['lead_id']) ? (int) $context['lead_id'] : 0,
                    'company_id'    => null,
                    'project_id'    => null,
                    'stage'         => isset($context['status']) ? (string) $context['status'] : '',
                    'adapter'       => 'meta',
                    'event'         => isset($entry['code']) ? (string) $entry['code'] : '',
                    'payload_json'  => wp_json_encode($context),
                    'response_json' => wp_json_encode(array('message' => $entry['message'] ?? '')),
                    'http_code'     => isset($context['http_code']) ? (int) $context['http_code'] : 0,
                    'status'        => 'migrated',
                    'created_at'    => isset($entry['ts']) ? gmdate('Y-m-d H:i:s', strtotime($entry['ts']) ?: time()) : gmdate('Y-m-d H:i:s'),
                ));
            }
        }

        delete_option('estandar_commercial_conversions_log');
    }

    /**
     * Backfill operator_uuid for any operator row that has an empty value.
     * Idempotent, additive, and non-destructive — existing UUIDs are never touched.
     */
    public static function backfill_operator_uuids()
    {
        if (!class_exists('Estandar_Leads_Commercial')) {
            return;
        }
        global $wpdb;
        $ids = $wpdb->get_col(
            "SELECT id FROM {$wpdb->prefix}es_operators WHERE operator_uuid = '' OR operator_uuid IS NULL"
        );
        foreach ((array) $ids as $id) {
            Estandar_Leads_Commercial::ensure_operator_uuid((int) $id);
        }
    }

    public static function backfill_lead_uuids()
    {
        global $wpdb;

        $leads = $wpdb->prefix . 'es_leads';
        $submissions = $wpdb->prefix . 'es_lead_submissions';

        $lead_rows = $wpdb->get_results("SELECT id FROM {$leads} WHERE lead_uuid = '' OR lead_uuid IS NULL LIMIT 500", ARRAY_A);
        foreach ((array) $lead_rows as $row) {
            $wpdb->update(
                $leads,
                array('lead_uuid' => wp_generate_uuid4()),
                array('id' => absint($row['id'] ?? 0)),
                array('%s'),
                array('%d')
            );
        }

        $submission_rows = $wpdb->get_results(
            "SELECT s.id, l.lead_uuid
             FROM {$submissions} s
             LEFT JOIN {$leads} l ON l.id = s.lead_id
             WHERE s.lead_uuid = '' OR s.lead_uuid IS NULL
             LIMIT 500",
            ARRAY_A
        );
        foreach ((array) $submission_rows as $row) {
            $uuid = sanitize_text_field((string)($row['lead_uuid'] ?? ''));
            if ($uuid === '') {
                $uuid = wp_generate_uuid4();
            }
            $wpdb->update(
                $submissions,
                array('lead_uuid' => $uuid),
                array('id' => absint($row['id'] ?? 0)),
                array('%s'),
                array('%d')
            );
        }
    }

    /**
     * v3.5.3 backfill: mark pre-v10.1.2 classification records as legacy.
     *
     * Criteria: is_legacy = 0 AND no corresponding quality_changed event in es_lead_events
     * within 60 seconds of classified_at. These are records created before the audit trail
     * was introduced and cannot be trusted for quality transition history.
     *
     * Idempotent: skips rows already marked is_legacy = 1 or rows with an audit event.
     * Runs in batches of 200 to avoid long-running queries on large datasets.
     */
    public static function backfill_legacy_classifications(): void {
        global $wpdb;

        $cls    = $wpdb->prefix . 'es_lead_classifications';
        $events = $wpdb->prefix . 'es_lead_events';

        // Identify legacy classifications: pre-v10.1.2 records that have no audit trail.
        //
        // Two conditions required (AND logic — reduces false positives):
        //
        // 1. previous_quality = '' — pre-v10.1.2 column default. Modern records always
        //    store the lead's quality at save time; only truly legacy rows have empty string
        //    here (the column didn't exist and was backfilled with DEFAULT '').
        //
        // 2. No quality_changed event within ±60s — confirms no audit event was written.
        //    Modern records where quality DID change always have a matching event.
        //    Modern records where quality did NOT change have previous_quality != '' (for
        //    established leads), so condition #1 already excludes them.
        //
        // Edge case accepted: a modern first-classification on a lead that never had quality
        // set (previous_quality='') where the operator also left quality blank — no event,
        // both conditions met. These records contain no quality information anyway and
        // the legacy flag is semantically accurate (nothing to trace).
        $seconds_diff_sql = (defined('DB_ENGINE') && DB_ENGINE === 'sqlite') || (defined('DATABASE_TYPE') && DATABASE_TYPE === 'sqlite')
            ? "ABS(strftime('%s', e.created_at) - strftime('%s', c.classified_at))"
            : 'ABS(TIMESTAMPDIFF(SECOND, e.created_at, c.classified_at))';

        $ids = $wpdb->get_col(
            "SELECT c.id
             FROM {$cls} c
             WHERE c.is_legacy = 0
               AND c.previous_quality = ''
               AND NOT EXISTS (
                   SELECT 1 FROM {$events} e
                   WHERE e.lead_id = c.lead_id
                     AND e.event_type = 'quality_changed'
                     AND {$seconds_diff_sql} <= 60
               )
             LIMIT 200"
        );

        if ( empty( $ids ) ) {
            return;
        }

        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$cls} SET is_legacy = 1 WHERE id IN ({$placeholders})",
            $ids
        ) );
    }

    public static function backfill_identity_fields(): void {
        global $wpdb;

        $leads = $wpdb->prefix . 'es_leads';
        $subs  = $wpdb->prefix . 'es_lead_submissions';
        $sessions = $wpdb->prefix . 'ea_sessions';
        $session_cols = self::table_columns($sessions);

        $rows = $wpdb->get_results(
            "SELECT id, first_session_id, first_utm_source, first_utm_medium, first_utm_campaign,
                    first_utm_term, first_utm_content, first_gclid, first_gbraid, first_wbraid,
                    first_fbclid, first_meta_fbc, first_meta_fbp, first_ttclid, first_msclkid,
                    first_landing_url, first_referrer
             FROM {$leads}
             WHERE first_session_id = '' OR first_session_id IS NULL
                OR first_gclid = '' OR first_gclid IS NULL
                OR first_gbraid = '' OR first_gbraid IS NULL
                OR first_wbraid = '' OR first_wbraid IS NULL
                OR first_fbclid = '' OR first_fbclid IS NULL
                OR first_meta_fbc = '' OR first_meta_fbc IS NULL
                OR first_meta_fbp = '' OR first_meta_fbp IS NULL
                OR first_ttclid = '' OR first_ttclid IS NULL
                OR first_msclkid = '' OR first_msclkid IS NULL
             LIMIT 500",
            ARRAY_A
        );

        foreach ((array) $rows as $lead) {
            $candidate = array();
            $submissions = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT session_id, utm_source, utm_medium, utm_campaign, utm_term, utm_content,
                            gclid, gbraid, wbraid, fbclid, meta_fbc, meta_fbp, ttclid, msclkid,
                            landing_url, referrer
                     FROM {$subs}
                     WHERE lead_id = %d
                     ORDER BY created_at ASC, id ASC",
                    absint($lead['id'])
                ),
                ARRAY_A
            );

            foreach ((array) $submissions as $submission) {
                $candidate = self::merge_identity_candidate($candidate, self::lead_identity_from_submission($submission));
                $sid = (string)($submission['session_id'] ?? '');
                if ($sid !== '' && !empty($session_cols)) {
                    $session = self::session_identity($sessions, $session_cols, $sid);
                    $candidate = self::merge_identity_candidate($candidate, $session);
                }
            }

            $update = self::identity_update_for_empty_lead($lead, $candidate);
            if (!empty($update)) {
                $updated = $wpdb->update($leads, $update, array('id' => absint($lead['id'])));
                if ($updated !== false && class_exists('Estandar_Leads_Scoring')) {
                    Estandar_Leads_Scoring::recompute(absint($lead['id']));
                }
            }
        }
    }

    private static function lead_identity_from_submission(array $row): array {
        return array(
            'first_session_id'   => (string)($row['session_id'] ?? ''),
            'first_utm_source'   => (string)($row['utm_source'] ?? ''),
            'first_utm_medium'   => (string)($row['utm_medium'] ?? ''),
            'first_utm_campaign' => (string)($row['utm_campaign'] ?? ''),
            'first_utm_term'     => (string)($row['utm_term'] ?? ''),
            'first_utm_content'  => (string)($row['utm_content'] ?? ''),
            'first_gclid'        => (string)($row['gclid'] ?? ''),
            'first_gbraid'       => (string)($row['gbraid'] ?? ''),
            'first_wbraid'       => (string)($row['wbraid'] ?? ''),
            'first_fbclid'       => (string)($row['fbclid'] ?? ''),
            'first_meta_fbc'     => (string)($row['meta_fbc'] ?? ''),
            'first_meta_fbp'     => (string)($row['meta_fbp'] ?? ''),
            'first_ttclid'       => (string)($row['ttclid'] ?? ''),
            'first_msclkid'      => (string)($row['msclkid'] ?? ''),
            'first_landing_url'  => (string)($row['landing_url'] ?? ''),
            'first_referrer'     => (string)($row['referrer'] ?? ''),
        );
    }

    private static function session_identity(string $table, array $columns, string $session_id): array {
        global $wpdb;

        if ($session_id === '' || !in_array('session_id', $columns, true)) {
            return array();
        }

        $map = array(
            'source' => 'first_utm_source',
            'medium' => 'first_utm_medium',
            'campaign' => 'first_utm_campaign',
            'term' => 'first_utm_term',
            'content' => 'first_utm_content',
            'gclid' => 'first_gclid',
            'gbraid' => 'first_gbraid',
            'wbraid' => 'first_wbraid',
            'fbclid' => 'first_fbclid',
            'meta_fbc' => 'first_meta_fbc',
            'meta_fbp' => 'first_meta_fbp',
            'ttclid' => 'first_ttclid',
            'msclkid' => 'first_msclkid',
            'landing_page' => 'first_landing_url',
            'referrer' => 'first_referrer',
        );
        $select = array('session_id');
        foreach (array_keys($map) as $column) {
            if (in_array($column, $columns, true)) {
                $select[] = $column;
            }
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT ' . implode(', ', array_map('sanitize_key', $select)) . " FROM {$table} WHERE session_id = %s LIMIT 1",
                $session_id
            ),
            ARRAY_A
        );
        if (!is_array($row)) {
            return array();
        }

        $out = array('first_session_id' => (string)($row['session_id'] ?? ''));
        foreach ($map as $from => $to) {
            $out[$to] = (string)($row[$from] ?? '');
        }

        return $out;
    }

    private static function merge_identity_candidate(array $base, array $candidate): array {
        foreach ($candidate as $field => $value) {
            if (trim((string)($base[$field] ?? '')) === '' && trim((string)$value) !== '') {
                $base[$field] = (string)$value;
            }
        }

        return $base;
    }

    private static function identity_update_for_empty_lead(array $lead, array $candidate): array {
        $update = array();
        foreach ($candidate as $field => $value) {
            if (trim((string)($lead[$field] ?? '')) === '' && trim((string)$value) !== '') {
                $update[$field] = (string)$value;
            }
        }

        return $update;
    }

    private static function table_columns(string $table): array {
        global $wpdb;

        if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return array();
        }

        $rows = $wpdb->get_results("SHOW COLUMNS FROM {$table}", ARRAY_A);
        $is_sqlite = (defined('DB_ENGINE') && DB_ENGINE === 'sqlite')
            || (defined('DATABASE_TYPE') && DATABASE_TYPE === 'sqlite');
        if (empty($rows) && $is_sqlite) {
            $rows = $wpdb->get_results("PRAGMA table_info({$table})", ARRAY_A);
        }

        $out = array();
        foreach ((array) $rows as $row) {
            $name = (string)($row['Field'] ?? ($row['name'] ?? ''));
            if ($name !== '') {
                $out[] = $name;
            }
        }

        return $out;
    }

    public static function ensure_secret()
    {
        // Strict false check: only create the secret when the option is truly absent.
        // add_option is a no-op if the option already exists — never regenerates an existing secret.
        if ( false === get_option( self::SECRET_OPTION, false ) ) {
            add_option( self::SECRET_OPTION, bin2hex( random_bytes( 32 ) ), '', false );
        }
    }

    public static function secret()
    {
        self::ensure_secret();
        return (string) get_option(self::SECRET_OPTION);
    }

    public static function create_tables()
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $leads = $wpdb->prefix . 'es_leads';
        $submissions = $wpdb->prefix . 'es_lead_submissions';
        $events = $wpdb->prefix . 'es_lead_events';
        $journey_events = $wpdb->prefix . 'es_commercial_journey_events';
        $operators = $wpdb->prefix . 'es_operators';
        $tokens = $wpdb->prefix . 'es_feedback_tokens';

        dbDelta("CREATE TABLE $leads (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            lead_uuid char(36) NOT NULL DEFAULT '',
            status varchar(32) NOT NULL DEFAULT 'NEW',
            feedback_status varchar(32) NOT NULL DEFAULT 'pending',
            name varchar(190) DEFAULT '',
            email varchar(190) DEFAULT '',
            phone varchar(64) DEFAULT '',
            email_normalized varchar(190) DEFAULT '',
            phone_normalized varchar(64) DEFAULT '',
            email_hash char(64) DEFAULT '',
            phone_hash char(64) DEFAULT '',
            first_source_plugin varchar(64) DEFAULT '',
            latest_source_plugin varchar(64) DEFAULT '',
            project_slug varchar(190) DEFAULT '',
            project_area varchar(190) DEFAULT '',
            lead_intent varchar(64) DEFAULT '',
            first_utm_source varchar(190) DEFAULT '',
            first_utm_medium varchar(190) DEFAULT '',
            first_utm_campaign varchar(190) DEFAULT '',
            first_utm_term varchar(190) DEFAULT '',
            first_utm_content varchar(190) DEFAULT '',
            first_gclid varchar(255) DEFAULT '',
            first_gbraid varchar(255) DEFAULT '',
            first_wbraid varchar(255) DEFAULT '',
            first_fbclid varchar(255) DEFAULT '',
            first_meta_fbc varchar(255) DEFAULT '',
            first_meta_fbp varchar(255) DEFAULT '',
            first_ttclid varchar(255) DEFAULT '',
            first_msclkid varchar(255) DEFAULT '',
            first_landing_url text,
            first_referrer text,
            first_session_id varchar(190) DEFAULT '',
            first_ip_address varchar(64) DEFAULT '',
            first_user_agent varchar(255) DEFAULT '',
            is_valid_lead tinyint(1) DEFAULT NULL,
            invalid_reason varchar(64) DEFAULT '',
            would_buy_again varchar(32) DEFAULT '',
            sales_stage varchar(64) DEFAULT '',
            first_seen_at datetime NOT NULL,
            last_seen_at datetime NOT NULL,
            feedback_completed tinyint(1) NOT NULL DEFAULT 0,
            first_feedback_at datetime DEFAULT NULL,
            latest_feedback_at datetime DEFAULT NULL,
            latest_operator_id bigint(20) unsigned DEFAULT NULL,
            latest_feedback_reason text,
            assigned_operator_id bigint(20) unsigned DEFAULT NULL,
            assigned_operator_email varchar(190) NOT NULL DEFAULT '',
            assigned_operator_name varchar(190) NOT NULL DEFAULT '',
            assigned_at datetime DEFAULT NULL,
            quality varchar(20) NOT NULL DEFAULT '',
            contact_attempts smallint(5) unsigned NOT NULL DEFAULT 0,
            first_contact_at datetime DEFAULT NULL,
            last_classified_at datetime DEFAULT NULL,
            last_classification_operator_id bigint(20) unsigned DEFAULT NULL,
            experience_data_json longtext,
            behavioral_score smallint(5) unsigned DEFAULT NULL,
            temperature varchar(8) NOT NULL DEFAULT '',
            priority varchar(8) NOT NULL DEFAULT '',
            score_updated_at datetime DEFAULT NULL,
            score_components_json longtext,
            commercial_fit varchar(32) NOT NULL DEFAULT '',
            fit_reason varchar(40) NOT NULL DEFAULT '',
            fit_confidence tinyint(3) unsigned DEFAULT NULL,
            fit_decision_source varchar(16) NOT NULL DEFAULT '',
            fit_review_required tinyint(1) NOT NULL DEFAULT 0,
            fit_updated_at datetime DEFAULT NULL,
            fit_evidence_json longtext,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY lead_uuid (lead_uuid),
            KEY status (status),
            KEY quality (quality),
            KEY project_slug (project_slug),
            KEY email_identity (email_hash),
            KEY phone_identity (phone_hash),
            KEY first_seen_at (first_seen_at),
            KEY last_seen_at (last_seen_at),
            KEY assigned_operator_id (assigned_operator_id),
            KEY feedback_status (feedback_status)
        ) $charset;");

        dbDelta("CREATE TABLE $submissions (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            lead_id bigint(20) unsigned NOT NULL,
            lead_uuid char(36) NOT NULL DEFAULT '',
            source_plugin varchar(64) NOT NULL,
            source_form_id varchar(128) DEFAULT '',
            source_entry_id bigint(20) unsigned DEFAULT NULL,
            schema_version varchar(16) NOT NULL DEFAULT '1.0',
            submitted_at datetime NOT NULL,
            created_at datetime NOT NULL,
            name varchar(190) DEFAULT '',
            email varchar(190) DEFAULT '',
            phone varchar(64) DEFAULT '',
            email_normalized varchar(190) DEFAULT '',
            phone_normalized varchar(64) DEFAULT '',
            email_hash char(64) DEFAULT '',
            phone_hash char(64) DEFAULT '',
            message longtext,
            utm_source varchar(190) DEFAULT '',
            utm_medium varchar(190) DEFAULT '',
            utm_campaign varchar(190) DEFAULT '',
            utm_term varchar(190) DEFAULT '',
            utm_content varchar(190) DEFAULT '',
            gclid varchar(255) DEFAULT '',
            gbraid varchar(255) DEFAULT '',
            wbraid varchar(255) DEFAULT '',
            fbclid varchar(255) DEFAULT '',
            meta_fbc varchar(255) DEFAULT '',
            meta_fbp varchar(255) DEFAULT '',
            ttclid varchar(255) DEFAULT '',
            msclkid varchar(255) DEFAULT '',
            landing_url text,
            referrer text,
            first_touch_timestamp varchar(64) DEFAULT '',
            last_touch_timestamp varchar(64) DEFAULT '',
            session_id varchar(190) DEFAULT '',
            gclid_capture_method varchar(32) DEFAULT 'missing',
            fbclid_capture_method varchar(32) DEFAULT 'missing',
            attribution_capture_method varchar(32) DEFAULT 'missing',
            ip_address varchar(45) DEFAULT '',
            user_agent text,
            suspect tinyint(1) NOT NULL DEFAULT 0,
            suspect_reasons text,
            payload_hash char(64) DEFAULT '',
            context_json longtext,
            scoring_json longtext,
            chatbot_json longtext,
            form_fields_json longtext,
            PRIMARY KEY  (id),
            KEY lead_uuid (lead_uuid),
            KEY lead_id (lead_id),
            KEY source_entry_id (source_entry_id),
            KEY created_at (created_at),
            KEY source_quality (source_plugin,created_at),
            KEY campaign_quality (utm_campaign,created_at),
            KEY term_quality (utm_term,created_at),
            KEY payload_hash (payload_hash)
        ) $charset;");

        dbDelta("CREATE TABLE $events (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            lead_id bigint(20) unsigned NOT NULL,
            submission_id bigint(20) unsigned DEFAULT NULL,
            operator_id bigint(20) unsigned DEFAULT NULL,
            event_type varchar(64) NOT NULL,
            from_status varchar(32) DEFAULT '',
            to_status varchar(32) DEFAULT '',
            reason text,
            metadata longtext,
            ip_address varchar(45) DEFAULT '',
            user_agent text,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY lead_id (lead_id),
            KEY submission_id (submission_id),
            KEY operator_id (operator_id),
            KEY event_type (event_type),
            KEY created_at (created_at)
        ) $charset;");

        dbDelta("CREATE TABLE $journey_events (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_id char(36) NOT NULL,
            event_name varchar(64) NOT NULL,
            event_version smallint(5) unsigned NOT NULL DEFAULT 1,
            lead_id bigint(20) unsigned NOT NULL,
            lead_uuid char(36) NOT NULL DEFAULT '',
            project_slug varchar(190) NOT NULL DEFAULT '',
            source_plugin varchar(64) NOT NULL DEFAULT 'estandar-leads',
            source_event_id bigint(20) unsigned NOT NULL,
            correlation_id varchar(190) NOT NULL DEFAULT '',
            sequence_no bigint(20) unsigned NOT NULL,
            idempotency_key char(64) NOT NULL,
            actor_json longtext,
            data_json longtext,
            envelope_json longtext NOT NULL,
            occurred_at datetime NOT NULL,
            recorded_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY event_id (event_id),
            UNIQUE KEY idempotency_key (idempotency_key),
            UNIQUE KEY source_event (source_event_id,event_name),
            KEY lead_sequence (lead_id,sequence_no),
            KEY event_name (event_name),
            KEY occurred_at (occurred_at)
        ) $charset;");

        dbDelta("CREATE TABLE $operators (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            operator_uuid char(36) NOT NULL DEFAULT '',
            name varchar(190) NOT NULL,
            email varchar(190) DEFAULT '',
            project_slug varchar(190) DEFAULT '',
            area varchar(190) DEFAULT '',
            whatsapp_number varchar(50) NOT NULL DEFAULT '',
            access_token_hash char(64) NOT NULL DEFAULT '',
            access_token_expires_at datetime DEFAULT NULL,
            allowed_projects_json longtext,
            operational_hours_json longtext DEFAULT NULL,
            active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY active (active),
            KEY project_slug (project_slug),
            KEY area (area),
            KEY email (email),
            KEY operator_uuid (operator_uuid),
            KEY access_token_hash (access_token_hash)
        ) $charset;");

        $whatsapp_sessions = $wpdb->prefix . 'es_whatsapp_sessions';
        dbDelta("CREATE TABLE $whatsapp_sessions (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            sid_ref varchar(20) NOT NULL,
            session_id varchar(190) NOT NULL DEFAULT '',
            project_slug varchar(190) NOT NULL DEFAULT '',
            operator_uuid char(36) NOT NULL DEFAULT '',
            whatsapp_number varchar(50) NOT NULL DEFAULT '',
            source varchar(50) NOT NULL DEFAULT '',
            routing_mode varchar(50) NOT NULL DEFAULT '',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_sid_ref (sid_ref),
            KEY idx_session_id (session_id),
            KEY idx_project_created (project_slug, created_at),
            KEY idx_operator_uuid (operator_uuid),
            KEY idx_created_at (created_at)
        ) $charset;");

        dbDelta("CREATE TABLE $tokens (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            lead_id bigint(20) unsigned NOT NULL,
            operator_id bigint(20) unsigned DEFAULT NULL,
            token_hash char(64) NOT NULL,
            expires_at datetime NOT NULL,
            used_at datetime DEFAULT NULL,
            last_seen_at datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY token_hash (token_hash),
            KEY lead_id (lead_id),
            KEY operator_id (operator_id),
            KEY expires_at (expires_at)
        ) $charset;");

        $classifications = $wpdb->prefix . 'es_lead_classifications';
        dbDelta("CREATE TABLE $classifications (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            lead_id bigint(20) unsigned NOT NULL,
            operator_id bigint(20) unsigned DEFAULT NULL,
            token_id bigint(20) unsigned DEFAULT NULL,
            is_valid_lead tinyint(1) DEFAULT NULL,
            invalid_reason varchar(64) DEFAULT '',
            would_buy_again varchar(32) DEFAULT '',
            previous_quality varchar(20) NOT NULL DEFAULT '',
            quality varchar(20) NOT NULL DEFAULT '',
            sales_stage varchar(64) DEFAULT '',
            status varchar(32) NOT NULL DEFAULT '',
            contacted tinyint(1) NOT NULL DEFAULT 0,
            notes text,
            is_legacy tinyint(1) NOT NULL DEFAULT 0,
            classified_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY lead_id (lead_id),
            KEY operator_id (operator_id),
            KEY quality (quality),
            KEY classified_at (classified_at),
            KEY is_legacy (is_legacy)
        ) $charset;");

        $contacts = $wpdb->prefix . 'es_lead_contacts';
        dbDelta("CREATE TABLE $contacts (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            lead_id bigint(20) unsigned NOT NULL,
            operator_id bigint(20) unsigned DEFAULT NULL,
            channel varchar(32) NOT NULL DEFAULT 'manual',
            outcome varchar(64) NOT NULL DEFAULT '',
            notes text,
            contacted_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY lead_id (lead_id),
            KEY operator_id (operator_id),
            KEY contacted_at (contacted_at)
        ) $charset;");

        // Meta Ads export queue — persistent, idempotent. Mirrors es_oci_queue.
        // UNIQUE KEY on (lead_id, export_status) prevents double-counting when a
        // lead changes to the same qualifying status more than once.
        // Note: the 'processing' enum value is reserved but unused — the worker
        // keeps rows in pending/retry until a terminal outcome (no orphans).
        $meta_queue = $wpdb->prefix . 'es_meta_queue';
        dbDelta("CREATE TABLE $meta_queue (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            lead_id bigint(20) unsigned NOT NULL,
            export_status varchar(32) NOT NULL,
            event_time varchar(32) NOT NULL DEFAULT '',
            queue_status enum('pending','processing','completed','retry','dead') NOT NULL DEFAULT 'pending',
            attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
            last_attempted_at datetime DEFAULT NULL,
            last_error text,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY lead_export (lead_id, export_status),
            KEY queue_status (queue_status, attempts),
            KEY created_at (created_at)
        ) $charset;");

        // Google Data Manager export outbox — durable, idempotent. Mirrors
        // es_meta_queue and adds: next_attempt_at (exponential backoff, replaces
        // the fixed retry gap) and dm_request_id (Google's requestId for
        // requestStatus:retrieve reconciliation). Extra queue_status 'validated'
        // = dry-run (validateOnly) pass — re-dispatched for real when dry-run
        // is turned off. UNIQUE (lead_id, export_status) = one conversion per
        // lead per milestone, forever.
        $google_queue = $wpdb->prefix . 'es_google_queue';
        dbDelta("CREATE TABLE $google_queue (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            lead_id bigint(20) unsigned NOT NULL,
            export_status varchar(32) NOT NULL,
            event_time varchar(32) NOT NULL DEFAULT '',
            queue_status enum('pending','processing','validated','completed','retry','dead') NOT NULL DEFAULT 'pending',
            attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
            next_attempt_at datetime DEFAULT NULL,
            last_attempted_at datetime DEFAULT NULL,
            dm_request_id varchar(64) NOT NULL DEFAULT '',
            last_error text,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY lead_export (lead_id, export_status),
            KEY queue_status (queue_status, attempts),
            KEY next_attempt_at (next_attempt_at),
            KEY created_at (created_at)
        ) $charset;");

        // Commercial Outcomes execution log — dedicated table (logs never in wp_options).
        // One row per outcome dispatched by an adapter (Meta, OCI, future). Additive.
        $commercial_log = $wpdb->prefix . 'es_commercial_outcomes_log';
        dbDelta("CREATE TABLE $commercial_log (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            lead_id bigint(20) unsigned NOT NULL,
            company_id varchar(190) DEFAULT NULL,
            project_id varchar(190) DEFAULT NULL,
            stage varchar(32) NOT NULL DEFAULT '',
            adapter varchar(32) NOT NULL DEFAULT '',
            event varchar(64) NOT NULL DEFAULT '',
            payload_json longtext,
            response_json longtext,
            http_code smallint(5) unsigned NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY lead_id (lead_id),
            KEY stage (stage),
            KEY adapter (adapter),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset;");

        // Conversion Backfill V1 — audited historical Purchase dispatches.
        $conversion_backfill = $wpdb->prefix . 'es_conversion_backfill_audit';
        dbDelta("CREATE TABLE $conversion_backfill (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            conversion_uuid char(36) NOT NULL DEFAULT '',
            buyer_uuid char(36) NOT NULL DEFAULT '',
            lead_uuid char(36) NOT NULL DEFAULT '',
            adapter varchar(32) NOT NULL DEFAULT '',
            event_name varchar(64) NOT NULL DEFAULT '',
            event_id varchar(190) NOT NULL DEFAULT '',
            payload_hash char(64) NOT NULL DEFAULT '',
            buyer_json longtext,
            sent_at datetime DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            response_code smallint(5) unsigned NOT NULL DEFAULT 0,
            response_body longtext,
            retry_count tinyint(3) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY event_id (event_id),
            KEY buyer_uuid (buyer_uuid),
            KEY lead_uuid (lead_uuid),
            KEY status (status),
            KEY sent_at (sent_at)
        ) $charset;");

        // Compliance engine notification log.
        // Tracks every escalation email sent, preventing duplicates and providing an audit trail.
        // is_retroactive: set to 1 for notifications generated by the bootstrap sweep.
        $notifications = $wpdb->prefix . 'estandar_lead_notifications';
        dbDelta("CREATE TABLE $notifications (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            lead_id bigint(20) unsigned NOT NULL,
            operator_id bigint(20) unsigned DEFAULT NULL,
            escalation_level varchar(32) NOT NULL DEFAULT '',
            notification_type varchar(64) NOT NULL DEFAULT '',
            recipient_email varchar(190) NOT NULL DEFAULT '',
            cc_emails text,
            token_hash char(64) NOT NULL DEFAULT '',
            is_retroactive tinyint(1) NOT NULL DEFAULT 0,
            metadata longtext,
            sent_at datetime NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY lead_id (lead_id),
            KEY operator_id (operator_id),
            KEY escalation_level (escalation_level),
            KEY sent_at (sent_at),
            KEY is_retroactive (is_retroactive),
            KEY lead_escalation (lead_id, escalation_level)
        ) $charset;");

        // CUSTOMER_REGISTRY_V1 — canonical customer records with full commercial lifecycle.
        $customers = $wpdb->prefix . 'es_customers';
        dbDelta("CREATE TABLE $customers (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            company_id varchar(190) NOT NULL DEFAULT '',
            project_slug varchar(190) NOT NULL DEFAULT '',
            external_id varchar(190) NOT NULL DEFAULT '',
            customer_stage varchar(64) NOT NULL DEFAULT '',
            first_name varchar(190) NOT NULL DEFAULT '',
            last_name varchar(190) NOT NULL DEFAULT '',
            email varchar(190) NOT NULL DEFAULT '',
            phone varchar(64) NOT NULL DEFAULT '',
            rut varchar(32) NOT NULL DEFAULT '',
            organization varchar(255) NOT NULL DEFAULT '',
            amount decimal(14,2) DEFAULT NULL,
            started_at datetime DEFAULT NULL,
            completed_at datetime DEFAULT NULL,
            metadata_json longtext DEFAULT NULL,
            matched_lead_id bigint(20) unsigned DEFAULT NULL,
            matched_by varchar(32) DEFAULT NULL,
            matched_confidence varchar(16) DEFAULT NULL,
            matched_at datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY project_slug (project_slug),
            KEY company_id (company_id),
            KEY external_id (external_id),
            KEY rut (rut),
            KEY email (email),
            KEY customer_stage (customer_stage),
            KEY matched_lead_id (matched_lead_id)
        ) $charset;");
    }

    /**
     * Idempotent UNIQUE KEY migration for es_customers(external_id, project_slug).
     * Deduplicates existing data first, then adds the constraint.
     */
    public static function migrate_customer_unique_external()
    {
        global $wpdb;
        $customers = $wpdb->prefix . 'es_customers';

        $unique_key = "SHOW INDEX FROM $customers WHERE Key_name = 'uq_external_project'";
        if ( $wpdb->get_var( $unique_key ) ) {
            return; // already migrated
        }

        // Remove duplicates before adding the constraint: keep the first row
        // for each (external_id, project_slug) pair where both are non-empty.
        $wpdb->query(
            "DELETE t1 FROM $customers t1
             INNER JOIN $customers t2
             WHERE t1.id > t2.id
               AND t1.external_id = t2.external_id
               AND t1.project_slug = t2.project_slug
               AND t1.external_id != ''
               AND t1.project_slug != ''"
        );

        $wpdb->query(
            "ALTER TABLE $customers ADD UNIQUE KEY uq_external_project (external_id, project_slug)"
        );
    }
}
