<?php

if (!defined('ABSPATH')) {
    exit;
}

class Estandar_Leads_Repository
{
    const CONTEXT_GAP_OPTION = 'estandar_leads_context_gap_template';
    const UNRESOLVED_PROJECT = 'unresolved';

    public static function project_for_submission($submission): string
    {
        global $wpdb;

        $row = is_object($submission) ? get_object_vars($submission) : (is_array($submission) ? $submission : array());
        $context = self::submission_context($row['context_json'] ?? array());

        $project_slug = Estandar_Leads_Projects::resolve_slug((string) ($context['project_slug'] ?? ''));
        if ($project_slug !== '') {
            return $project_slug;
        }

        $project_slug = Estandar_Leads_Projects::resolve_slug((string) ($context['project_area'] ?? ''));
        if ($project_slug !== '') {
            return $project_slug;
        }

        $legacy_slug = (string) ($row['lead_project_slug'] ?? ($row['project_slug'] ?? ''));
        if ($legacy_slug === '' && !empty($row['lead_id'])) {
            $legacy_slug = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT project_slug FROM {$wpdb->prefix}es_leads WHERE id = %d LIMIT 1",
                absint($row['lead_id'])
            ));
        }

        $project_slug = Estandar_Leads_Projects::resolve_slug($legacy_slug);
        return $project_slug !== '' ? $project_slug : self::UNRESOLVED_PROJECT;
    }

    public static function canonical_project($lead = null, $submission = null): string
    {
        $sub_row = is_object($submission) ? get_object_vars($submission) : (is_array($submission) ? $submission : array());
        $context = self::submission_context($sub_row['context_json'] ?? array());
        $project_slug = Estandar_Leads_Projects::resolve_slug((string) ($context['project_slug'] ?? ''));
        if ($project_slug !== '') {
            return $project_slug;
        }

        if (!empty($sub_row)) {
            $project_slug = self::project_for_submission($submission);
            if ($project_slug !== '' && $project_slug !== self::UNRESOLVED_PROJECT) {
                return $project_slug;
            }
        }

        $lead_row = is_object($lead) ? get_object_vars($lead) : (is_array($lead) ? $lead : array());
        $project_slug = Estandar_Leads_Projects::resolve_slug((string) ($lead_row['project_slug'] ?? ($lead_row['lead_project_slug'] ?? '')));
        return $project_slug !== '' ? $project_slug : self::UNRESOLVED_PROJECT;
    }

    public static function health_report(int $limit = 100): array
    {
        global $wpdb;

        $limit = max(1, min(500, absint($limit)));
        $issues = array(
            'capture_null_with_submissions' => array(),
            'submission_without_lead' => array(),
            'lead_without_operator' => array(),
            'project_mismatch' => array(),
            'timeline_disorder' => array(),
            'duplicate_identity' => array(),
            'customer_mismatch' => array(),
        );

        $rows = $wpdb->get_results(
            "SELECT l.id, l.score_components_json, COUNT(s.id) AS submission_count
             FROM {$wpdb->prefix}es_leads l
             INNER JOIN {$wpdb->prefix}es_lead_submissions s ON s.lead_id = l.id
             GROUP BY l.id
             HAVING submission_count > 0
             LIMIT {$limit}"
        );
        foreach ($rows ?: array() as $row) {
            $components = json_decode((string) ($row->score_components_json ?? ''), true);
            if (!is_array($components) || !array_key_exists('capture', $components) || $components['capture'] === null) {
                $issues['capture_null_with_submissions'][] = absint($row->id ?? 0);
            }
        }

        $issues['submission_without_lead'] = array_map('absint', (array) $wpdb->get_col(
            "SELECT s.id
             FROM {$wpdb->prefix}es_lead_submissions s
             LEFT JOIN {$wpdb->prefix}es_leads l ON l.id = s.lead_id
             WHERE l.id IS NULL
             LIMIT {$limit}"
        ));

        $issues['lead_without_operator'] = array_map('absint', (array) $wpdb->get_col(
            "SELECT id FROM {$wpdb->prefix}es_leads
             WHERE assigned_operator_id IS NULL OR assigned_operator_id = 0
             LIMIT {$limit}"
        ));

        $project_rows = $wpdb->get_results(
            "SELECT s.id, s.lead_id, s.context_json, l.project_slug AS lead_project_slug
             FROM {$wpdb->prefix}es_lead_submissions s
             INNER JOIN {$wpdb->prefix}es_leads l ON l.id = s.lead_id
             ORDER BY s.id DESC
             LIMIT {$limit}"
        );
        foreach ($project_rows ?: array() as $row) {
            $canonical = self::canonical_project(null, $row);
            $lead_project = Estandar_Leads_Projects::resolve_slug((string) ($row->lead_project_slug ?? ''));
            if ($canonical !== self::UNRESOLVED_PROJECT && $lead_project !== '' && $canonical !== $lead_project) {
                $issues['project_mismatch'][] = array(
                    'submission_id' => absint($row->id ?? 0),
                    'lead_id' => absint($row->lead_id ?? 0),
                    'canonical_project' => $canonical,
                    'lead_project' => $lead_project,
                );
            }
        }

        $events = $wpdb->get_results(
            "SELECT lead_id, id, created_at
             FROM {$wpdb->prefix}es_lead_events
             ORDER BY lead_id ASC, id ASC
             LIMIT " . ($limit * 10)
        );
        $last_by_lead = array();
        foreach ($events ?: array() as $event) {
            $lead_id = absint($event->lead_id ?? 0);
            $created_at = (string) ($event->created_at ?? '');
            if (isset($last_by_lead[$lead_id]) && $created_at < $last_by_lead[$lead_id]) {
                $issues['timeline_disorder'][] = array('lead_id' => $lead_id, 'event_id' => absint($event->id ?? 0));
            }
            $last_by_lead[$lead_id] = $created_at;
        }

        $identity_sql = array(
            "SELECT 'email_hash' AS type, email_hash AS identity_value, COUNT(*) AS count, GROUP_CONCAT(id ORDER BY id ASC) AS lead_ids
             FROM {$wpdb->prefix}es_leads
             WHERE email_hash != ''
             GROUP BY email_hash
             HAVING count > 1",
            "SELECT 'phone_hash' AS type, phone_hash AS identity_value, COUNT(*) AS count, GROUP_CONCAT(id ORDER BY id ASC) AS lead_ids
             FROM {$wpdb->prefix}es_leads
             WHERE phone_hash != ''
             GROUP BY phone_hash
             HAVING count > 1",
        );
        if (self::table_column_exists('es_leads', 'rut')) {
            $identity_sql[] = "SELECT 'rut' AS type, rut AS identity_value, COUNT(*) AS count, GROUP_CONCAT(id ORDER BY id ASC) AS lead_ids
             FROM {$wpdb->prefix}es_leads
             WHERE rut != ''
             GROUP BY rut
             HAVING count > 1";
        }
        $dupes = $wpdb->get_results( implode(' UNION ALL ', $identity_sql) . " LIMIT {$limit}" );
        foreach ($dupes ?: array() as $dupe) {
            $issues['duplicate_identity'][] = array(
                'type' => (string) ($dupe->type ?? ''),
                'count' => (int) ($dupe->count ?? 0),
                'lead_ids' => (string) ($dupe->lead_ids ?? ''),
            );
        }

        $customer_rows = $wpdb->get_results(
            "SELECT c.id, c.matched_lead_id, c.project_slug, l.project_slug AS lead_project_slug
             FROM {$wpdb->prefix}es_customers c
             LEFT JOIN {$wpdb->prefix}es_leads l ON l.id = c.matched_lead_id
             WHERE c.matched_lead_id IS NOT NULL
             LIMIT {$limit}"
        );
        foreach ($customer_rows ?: array() as $row) {
            $lead_id = absint($row->matched_lead_id ?? 0);
            $customer_project = Estandar_Leads_Projects::resolve_slug((string) ($row->project_slug ?? ''));
            $lead_project = Estandar_Leads_Projects::resolve_slug((string) ($row->lead_project_slug ?? ''));
            if ($lead_id === 0 || $lead_project === '' || ($customer_project !== '' && $customer_project !== $lead_project)) {
                $issues['customer_mismatch'][] = array(
                    'customer_id' => absint($row->id ?? 0),
                    'lead_id' => $lead_id,
                    'customer_project' => $customer_project,
                    'lead_project' => $lead_project,
                );
            }
        }

        $counts = array();
        foreach ($issues as $key => $rows) {
            $counts[$key] = count($rows);
        }

        return array(
            'generated_at' => gmdate('Y-m-d H:i:s'),
            'counts' => $counts,
            'issues' => $issues,
        );
    }

    private static function submission_context($context_json): array
    {
        if (is_array($context_json)) {
            return $context_json;
        }

        if (is_object($context_json)) {
            return get_object_vars($context_json);
        }

        $decoded = json_decode((string) $context_json, true);
        return is_array($decoded) ? $decoded : array();
    }

    private static function table_column_exists(string $table, string $column): bool
    {
        global $wpdb;
        static $cache = array();
        $key = $table . '.' . $column;
        if (!array_key_exists($key, $cache)) {
            $cache[$key] = in_array($column, (array) $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}{$table}", 0), true);
        }
        return $cache[$key];
    }

    public static function find_lead_id($email_hash, $phone_hash, $legacy_phone_hash = '')
    {
        global $wpdb;

        if ($email_hash !== '') {
            $found = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}es_leads WHERE email_hash = %s ORDER BY id ASC LIMIT 1",
                $email_hash
            ));
            if ($found) {
                return absint($found);
            }
        }

        if ($phone_hash !== '') {
            $found = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}es_leads WHERE phone_hash = %s ORDER BY id ASC LIMIT 1",
                $phone_hash
            ));
            if ($found) {
                return absint($found);
            }
        }

        if ($legacy_phone_hash !== '' && $legacy_phone_hash !== $phone_hash) {
            $found = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}es_leads WHERE phone_hash = %s ORDER BY id ASC LIMIT 1",
                $legacy_phone_hash
            ));
            if ($found) {
                return absint($found);
            }
        }

        return 0;
    }

    public static function insert_event($lead_id, $event_type, $args = array())
    {
        global $wpdb;

        $operator_id = !empty($args['operator_id']) ? absint($args['operator_id']) : 0;
        $metadata = is_array($args['metadata'] ?? null) ? $args['metadata'] : array();
        $metadata = self::with_actor_context($metadata, $operator_id);
        $metadata = self::with_canonical_metadata($metadata, $args, $event_type);

        $inserted = $wpdb->insert($wpdb->prefix . 'es_lead_events', array(
            'lead_id' => absint($lead_id),
            'submission_id' => !empty($args['submission_id']) ? absint($args['submission_id']) : null,
            'operator_id' => $operator_id ?: null,
            'event_type' => sanitize_key($event_type),
            'from_status' => sanitize_text_field($args['from_status'] ?? ''),
            'to_status' => sanitize_text_field($args['to_status'] ?? ''),
            'reason' => sanitize_textarea_field($args['reason'] ?? ''),
            'metadata' => wp_json_encode($metadata),
            'ip_address' => sanitize_text_field($args['ip_address'] ?? ''),
            'user_agent' => sanitize_textarea_field($args['user_agent'] ?? ''),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ));

        $event_id = $inserted ? absint($wpdb->insert_id) : 0;

        // Additive: notify listeners (e.g. Estandar_Leads_Scoring) that an event was
        // recorded. Fired only on successful insert; does not alter return value or
        // any existing behavior. Behavioral Score V1 substrate (Fase A).
        if ($event_id > 0) {
            do_action('estandar_lead_event_recorded', $event_id, absint($lead_id), sanitize_key($event_type), $metadata);

            // Phase 1 atomicity guard: a mapped source event is valid only when its
            // shadow Journey row exists. Compensate only the just-created source row
            // on a Journey write failure; disabled shadow mode preserves legacy flow.
            if (class_exists('Estandar_Leads_Commercial_Journey')
                && Estandar_Leads_Commercial_Journey::is_enabled()
                && Estandar_Leads_Commercial_Journey::canonical_name((string) $event_type) !== ''
                && !Estandar_Leads_Commercial_Journey::has_source_event($event_id)
            ) {
                $wpdb->delete(
                    $wpdb->prefix . 'es_lead_events',
                    array('id' => $event_id),
                    array('%d')
                );
                return 0;
            }
        }

        return $event_id;
    }

    // Additive normalization: aliases canonical keys onto data already present in
    // $args/$metadata/derived actor context. Never queries or fabricates a value —
    // if a canonical key has no existing source, it is simply left unset.
    private static function with_canonical_metadata(array $metadata, array $args, string $event_type): array
    {
        if (empty($metadata['lead_uuid'])) {
            $lead_uuid = trim((string) ($args['lead_uuid'] ?? ''));
            if ($lead_uuid !== '') {
                $metadata['lead_uuid'] = $lead_uuid;
            }
        }

        if (empty($metadata['project_uuid'])) {
            $project_uuid = trim((string) ($args['project_uuid'] ?? ''));
            if ($project_uuid !== '') {
                $metadata['project_uuid'] = $project_uuid;
            }
        }

        if (empty($metadata['commercial_stage'])) {
            if ($event_type === 'commercial_stage_changed') {
                $to_status = trim((string) ($args['to_status'] ?? ''));
                if ($to_status !== '') {
                    $metadata['commercial_stage'] = $to_status;
                }
            } elseif (!empty($args['commercial_stage'])) {
                $metadata['commercial_stage'] = $args['commercial_stage'];
            }
        }

        if (empty($metadata['revenue'])) {
            $revenue = $args['revenue'] ?? $args['conversion_value'] ?? $metadata['conversion_value'] ?? null;
            if ($revenue !== null && $revenue !== '') {
                $metadata['revenue'] = $revenue;
            }
        }

        if (empty($metadata['currency'])) {
            $currency = trim((string) ($args['currency'] ?? ''));
            if ($currency !== '') {
                $metadata['currency'] = strtoupper($currency);
            }
        }

        if (empty($metadata['source'])) {
            $source = trim((string) ($args['source'] ?? ''));
            if ($source !== '') {
                $metadata['source'] = $source;
            }
        }

        if (empty($metadata['actor'])) {
            $actor = trim((string) ($metadata['actor_name'] ?? ''));
            if ($actor === '') {
                $actor = trim((string) ($metadata['actor_type'] ?? ''));
            }
            if ($actor !== '') {
                $metadata['actor'] = $actor;
            }
        }

        return $metadata;
    }

    public static function persist_experience($lead_id, $submission_id, $slug, $version, $answers, $meta = array()): bool
    {
        $snapshot = array(
            'experience_slug' => sanitize_key((string) $slug),
            'started_at' => gmdate('c'),
            'completed_at' => gmdate('c'),
            'answers' => is_array($answers) ? $answers : array(),
            'result' => array(
                'segment' => sanitize_key((string) ($meta['lead_segment'] ?? '')),
                'priority' => sanitize_key((string) ($meta['priority'] ?? 'normal')),
            ),
        );

        return self::persist_experience_snapshot($lead_id, $submission_id, $snapshot);
    }

    public static function persist_experience_snapshot($lead_id, $submission_id, array $snapshot): bool
    {
        global $wpdb;

        $lead_id = absint($lead_id);
        if ($lead_id <= 0) {
            return false;
        }

        $snapshot = self::sanitize_experience_snapshot($snapshot);
        $updated = $wpdb->update(
            $wpdb->prefix . 'es_leads',
            array(
                'experience_data_json' => wp_json_encode($snapshot),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ),
            array('id' => $lead_id),
            array('%s', '%s'),
            array('%d')
        );

        if ($updated === false) {
            return false;
        }

        self::insert_event($lead_id, 'experience_started', array(
            'submission_id' => absint($submission_id),
            'metadata' => array(
                'experience_slug' => $snapshot['experience_slug'],
                'started_at' => $snapshot['started_at'],
            ),
        ));

        self::insert_event($lead_id, 'experience_completed', array(
            'submission_id' => absint($submission_id),
            'metadata' => array(
                'experience_slug' => $snapshot['experience_slug'],
                'completed_at' => $snapshot['completed_at'],
                'result' => $snapshot['result'],
            ),
        ));

        return true;
    }

    private static function sanitize_experience_snapshot(array $snapshot): array
    {
        $answers = array();
        foreach ((array) ($snapshot['answers'] ?? array()) as $key => $value) {
            $key = sanitize_key((string) $key);
            if ($key === '') {
                continue;
            }
            if (is_bool($value)) {
                $answers[$key] = $value;
            } else {
                $answers[$key] = sanitize_text_field((string) $value);
            }
        }

        $result = is_array($snapshot['result'] ?? null) ? $snapshot['result'] : array();

        return array(
            'experience_slug' => sanitize_key((string) ($snapshot['experience_slug'] ?? '')),
            'started_at' => sanitize_text_field((string) ($snapshot['started_at'] ?? '')),
            'completed_at' => sanitize_text_field((string) ($snapshot['completed_at'] ?? '')),
            'answers' => $answers,
            'result' => array(
                'segment' => sanitize_key((string) ($result['segment'] ?? '')),
                'priority' => sanitize_key((string) ($result['priority'] ?? 'normal')),
            ),
        );
    }

    private static function with_actor_context(array $metadata, int $operator_id): array
    {
        $logged_in = is_user_logged_in();
        $user_id = $logged_in ? get_current_user_id() : 0;

        if (empty($metadata['execution_source'])) {
            if (defined('WP_CLI') && WP_CLI) {
                $metadata['execution_source'] = 'cli';
            } elseif (defined('DOING_CRON') && DOING_CRON) {
                $metadata['execution_source'] = 'cron';
            } elseif (defined('REST_REQUEST') && REST_REQUEST) {
                $metadata['execution_source'] = 'api';
            } elseif (($metadata['triggered_by'] ?? '') === 'compliance') {
                $metadata['execution_source'] = 'automation';
            } elseif (($metadata['source'] ?? '') === 'operator_portal') {
                $metadata['execution_source'] = 'workspace';
            } elseif (is_admin()) {
                $metadata['execution_source'] = 'wp-admin';
            } else {
                $metadata['execution_source'] = 'unknown';
            }
        }

        if (empty($metadata['actor_type'])) {
            if ($logged_in && $user_id > 0) {
                $metadata['actor_type'] = 'user';
            } elseif ($operator_id > 0) {
                $metadata['actor_type'] = 'operator';
            } elseif (in_array($metadata['execution_source'], array('cron', 'cli', 'automation'), true)) {
                $metadata['actor_type'] = 'system';
            } else {
                $metadata['actor_type'] = 'unknown';
            }
        }

        if (!array_key_exists('actor_id', $metadata)) {
            if ($metadata['actor_type'] === 'user' && $user_id > 0) {
                $metadata['actor_id'] = $user_id;
            } elseif ($metadata['actor_type'] === 'operator' && $operator_id > 0) {
                $metadata['actor_id'] = $operator_id;
            } else {
                $metadata['actor_id'] = null;
            }
        }

        // Resolve actor_name once and store it in metadata for display.
        if (!array_key_exists('actor_name', $metadata) || $metadata['actor_name'] === '') {
            $type = $metadata['actor_type'];
            if ($type === 'user') {
                if ($user_id > 0) {
                    $metadata['actor_user_id'] = $user_id;
                    $user = get_user_by('id', $user_id);
                    if ($user) {
                        $metadata['actor_name']  = sanitize_text_field($user->display_name ?: $user->user_login);
                        $metadata['actor_email'] = sanitize_email($user->user_email);
                    }
                }
            } elseif ($type === 'operator' && $operator_id > 0) {
                // Operator name: look up from es_operators with a simple request-scoped static cache.
                static $op_name_cache = [];
                if (!isset($op_name_cache[$operator_id])) {
                    global $wpdb;
                    $op_name_cache[$operator_id] = (string) $wpdb->get_var(
                        $wpdb->prepare("SELECT name FROM {$wpdb->prefix}es_operators WHERE id = %d LIMIT 1", $operator_id)
                    );
                }
                $metadata['actor_name'] = sanitize_text_field($op_name_cache[$operator_id]);
            }
            if (empty($metadata['actor_name'])) {
                $metadata['actor_name'] = null;
            }
        }

        if ($operator_id > 0 && empty($metadata['actor_operator_id'])) {
            $metadata['actor_operator_id'] = $operator_id;
        }
        if (empty($metadata['ip_address'])) {
            $metadata['ip_address'] = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? ''));
        }
        if (empty($metadata['user_agent'])) {
            $metadata['user_agent'] = sanitize_textarea_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? ''));
        }
        if (empty($metadata['referer'])) {
            $metadata['referer'] = sanitize_textarea_field(wp_unslash($_SERVER['HTTP_REFERER'] ?? ''));
        }

        return $metadata;
    }

    public static function context_gap_template(): array
    {
        $stored = get_option(self::CONTEXT_GAP_OPTION, array());
        $stored = is_array($stored) ? $stored : array();
        $question = sanitize_text_field($stored['question'] ?? '');
        $options = is_array($stored['options'] ?? null) ? $stored['options'] : array();

        if ($question === '') {
            $question = '¿Qué información faltó?';
        }

        $clean = array('NO' => 'No faltó información');
        foreach ($options as $key => $label) {
            $key = strtoupper(sanitize_key((string) $key));
            $label = sanitize_text_field((string) $label);
            if ($key === '' || $key === 'NO' || $key === 'OTHER' || $label === '') {
                continue;
            }
            $clean[$key] = $label;
            if (count($clean) >= 4) {
                break;
            }
        }
        $clean['OTHER'] = 'Otro';

        return array(
            'question' => $question,
            'options' => array_slice($clean, 0, 5, true),
        );
    }

    public static function save_context_gap_template($raw): void
    {
        $question = sanitize_text_field(wp_unslash($raw['context_gap_question'] ?? ''));
        $lines = preg_split('/\r\n|\r|\n/', (string) wp_unslash($raw['context_gap_options'] ?? ''));
        $options = array();

        foreach ($lines ?: array() as $line) {
            $label = sanitize_text_field(trim((string) $line));
            if ($label === '') {
                continue;
            }
            $key = self::context_gap_key($label);
            if ($key === '' || $key === 'NO' || $key === 'OTHER') {
                continue;
            }
            $options[$key] = $label;
            if (count($options) >= 3) {
                break;
            }
        }

        update_option(self::CONTEXT_GAP_OPTION, array(
            'question' => $question !== '' ? $question : '¿Qué información faltó?',
            'options' => $options,
        ), false);
    }

    public static function context_gap_options(): array
    {
        return self::context_gap_template()['options'];
    }

    public static function sanitize_context_gap($value): string
    {
        $value = strtoupper(sanitize_key(wp_unslash($value)));
        return isset(self::context_gap_options()[$value]) ? $value : 'NO';
    }

    public static function context_gap_label(string $value): string
    {
        $options = self::context_gap_options();
        return $options[$value] ?? $options['NO'] ?? 'No faltó información';
    }

    public static function context_gap_metadata(string $value): array
    {
        $value = self::sanitize_context_gap($value);
        return array(
            'context_gap' => $value,
            'context_gap_label' => self::context_gap_label($value),
            'context_gap_question' => (string) (self::context_gap_template()['question'] ?? '¿Qué información faltó?'),
        );
    }

    public static function latest_context_gap(int $lead_id): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT metadata, created_at
             FROM {$wpdb->prefix}es_lead_events
             WHERE lead_id = %d
               AND event_type IN ('status_changed', 'status_overridden', 'quality_changed', 'commercial_stage_changed', 'contact_logged')
             ORDER BY created_at DESC, id DESC
             LIMIT 20",
            absint($lead_id)
        ));

        foreach ($rows ?: array() as $row) {
            $gap = self::context_gap_from_event_row($row);
            if (!empty($gap)) {
                return $gap;
            }
        }

        return array();
    }

    public static function context_gaps_for_leads(array $lead_ids): array
    {
        global $wpdb;

        $lead_ids = array_values(array_unique(array_filter(array_map('absint', $lead_ids))));
        if (empty($lead_ids)) {
            return array();
        }

        $placeholders = implode(',', array_fill(0, count($lead_ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT lead_id, metadata, created_at
             FROM {$wpdb->prefix}es_lead_events
             WHERE lead_id IN ({$placeholders})
               AND event_type IN ('status_changed', 'status_overridden', 'quality_changed', 'commercial_stage_changed', 'contact_logged')
             ORDER BY created_at DESC, id DESC",
            $lead_ids
        ));

        $out = array();
        foreach ($rows ?: array() as $row) {
            $lead_id = absint($row->lead_id ?? 0);
            if ($lead_id === 0 || isset($out[$lead_id])) {
                continue;
            }
            $gap = self::context_gap_from_event_row($row);
            if (!empty($gap)) {
                $out[$lead_id] = $gap;
            }
        }

        return $out;
    }

    public static function context_gap_summary(int $days = 30): array
    {
        global $wpdb;

        $since = gmdate('Y-m-d H:i:s', time() - max(1, $days) * DAY_IN_SECONDS);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT lead_id, metadata, created_at
             FROM {$wpdb->prefix}es_lead_events
             WHERE created_at >= %s
               AND event_type IN ('status_changed', 'status_overridden', 'quality_changed', 'commercial_stage_changed', 'contact_logged')
             ORDER BY created_at DESC, id DESC",
            $since
        ));

        $counts = array();
        $seen_leads = array();
        foreach ($rows ?: array() as $row) {
            $lead_id = absint($row->lead_id ?? 0);
            if ($lead_id === 0 || isset($seen_leads[$lead_id])) {
                continue;
            }
            $gap = self::context_gap_from_event_row($row);
            if (empty($gap)) {
                continue;
            }
            $seen_leads[$lead_id] = true;
            $key = (string) ($gap['value'] ?? 'NO');
            if (!isset($counts[$key])) {
                $counts[$key] = array('value' => $key, 'label' => (string) ($gap['label'] ?? self::context_gap_label($key)), 'count' => 0);
            }
            $counts[$key]['count']++;
        }

        uasort($counts, static function ($a, $b) {
            return (int) $b['count'] <=> (int) $a['count'];
        });

        return array_values($counts);
    }

    private static function context_gap_from_event_row($row): array
    {
        if (!$row) {
            return array();
        }

        $meta = json_decode((string) ($row->metadata ?? ''), true);
        $meta = is_array($meta) ? $meta : array();
        if (empty($meta['context_gap'])) {
            return array();
        }

        $value = strtoupper(sanitize_key((string) $meta['context_gap']));
        $label = sanitize_text_field((string) ($meta['context_gap_label'] ?? ''));
        if ($label === '') {
            $label = self::context_gap_label($value);
        }

        return array(
            'value' => $value,
            'label' => $label,
            'date' => (string) ($row->created_at ?? ''),
        );
    }

    private static function context_gap_key(string $label): string
    {
        $key = strtoupper(sanitize_key(remove_accents($label)));
        return $key !== '' ? $key : '';
    }

    /**
     * INTEREST_ASSIGNMENT_V1 (Fase 1, lectura additiva).
     *
     * Devuelve, por submission_id, el operador del evento 'operator_assigned'
     * más reciente para ese lead. No reemplaza assigned_operator_id
     * (lead-level, fallback/legacy) — es la capa "por interés" encima.
     *
         * @return array<int, object{submission_id:int, operator_id:int, operator_name:string, operator_email:string, whatsapp_number:string, active:int, assigned_at:string}>
     *         indexado por submission_id.
     */
    public static function operators_by_submission($lead_id)
    {
        global $wpdb;

        $lead_id = absint($lead_id);
        if ($lead_id === 0) {
            return array();
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT e.submission_id, e.operator_id, e.to_status AS project_slug, o.operator_uuid, o.name AS operator_name, o.email AS operator_email, o.whatsapp_number, o.active, e.created_at AS assigned_at
             FROM {$wpdb->prefix}es_lead_events e
             INNER JOIN (
                 SELECT submission_id, MAX(created_at) AS max_created
                 FROM {$wpdb->prefix}es_lead_events
                 WHERE lead_id = %d AND event_type = 'operator_assigned' AND submission_id IS NOT NULL
                 GROUP BY submission_id
             ) latest ON latest.submission_id = e.submission_id AND latest.max_created = e.created_at
             LEFT JOIN {$wpdb->prefix}es_operators o ON o.id = e.operator_id
             WHERE e.lead_id = %d AND e.event_type = 'operator_assigned' AND e.submission_id IS NOT NULL",
            $lead_id,
            $lead_id
        ));

        $by_submission = array();
        foreach ($rows ?: array() as $row) {
            $by_submission[(int) $row->submission_id] = $row;
        }

        return $by_submission;
    }

    /**
     * INTEREST_ASSIGNMENT_V1 (Fase 1, lectura additiva).
     *
     * Operador asignado a UN interés (submission_id) específico, o null si
     * no hay evento 'operator_assigned' registrado para esa submission
     * (leads/submissions previos a Fase 1 — sin backfill, D3).
     */
    public static function operator_for_submission($lead_id, $submission_id)
    {
        $submission_id = absint($submission_id);
        if ($submission_id === 0) {
            return null;
        }

        $by_submission = self::operators_by_submission($lead_id);

        return $by_submission[$submission_id] ?? null;
    }

    /**
     * LEAD_RESPONSE_ACCELERATION_V1 (Fase 1) — throttle check.
     *
     * Throttle key = (submission email_hash, project_slug). Events are joined
     * to their canonical submission so phone-based lead dedupe cannot make one
     * email suppress a different email attached to the same lead.
     *
     * @return bool  true if an autoresponse was already sent for this
     *               email+project within the window.
     */
    public static function has_recent_autoresponse($email_hash, $project_slug, $window_hours = 24)
    {
        global $wpdb;

        $email_hash = sanitize_text_field((string) $email_hash);
        $project_slug = sanitize_key($project_slug);
        if ($email_hash === '' || $project_slug === '') {
            return false;
        }

        $window_hours = max(0, (int) $window_hours);
        $since = gmdate('Y-m-d H:i:s', time() - ($window_hours * HOUR_IN_SECONDS));

        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT e.id
             FROM {$wpdb->prefix}es_lead_events e
             INNER JOIN {$wpdb->prefix}es_lead_submissions s ON s.id = e.submission_id
             WHERE s.email_hash = %s
               AND e.event_type = 'autoresponse_email_sent'
               AND e.to_status = %s AND e.created_at >= %s
             LIMIT 1",
            $email_hash,
            $project_slug,
            $since
        ));

        return $found !== null;
    }

    /**
     * Atomically reserve one autoresponse slot for email+project.
     *
     * Uses an atomic conditional INSERT (INSERT ... SELECT ... WHERE NOT EXISTS)
     * to guarantee correctness on both MySQL (UNIQUE constraint on option_name)
     * and SQLite (no UNIQUE constraint on wp_options.option_name in the WP SQLite
     * integration). This avoids the race inherent in add_option's check-then-insert
     * pattern which relies on the UNIQUE constraint for atomicity.
     *
     * @return string|false Reservation token, or false when throttled.
     */
    public static function reserve_autoresponse($email_hash, $project_slug, $window_hours = 24)
    {
        global $wpdb;

        $email_hash = sanitize_text_field((string) $email_hash);
        $project_slug = sanitize_key($project_slug);
        $window_hours = max(0, (int) $window_hours);
        if ($email_hash === '' || $project_slug === '') {
            return false;
        }

        if (self::has_recent_autoresponse($email_hash, $project_slug, $window_hours)) {
            return false;
        }

        $option_name = self::autoresponse_reservation_name($email_hash, $project_slug);
        $token = wp_generate_uuid4();
        $value = wp_json_encode(array(
            'token' => $token,
            'expires' => time() + max(1, $window_hours * HOUR_IN_SECONDS),
        ));

        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
             SELECT %s, %s, 'no'
             WHERE NOT EXISTS (
                 SELECT 1 FROM {$wpdb->options} WHERE option_name = %s
             )",
            $option_name,
            $value,
            $option_name
        ));

        if ($inserted) {
            wp_cache_delete($option_name, 'options');
            wp_cache_delete('alloptions', 'options');
            return $token;
        }

        $current = get_option($option_name);
        $current_data = is_string($current) ? json_decode($current, true) : null;
        $expires = is_array($current_data) ? (int) ($current_data['expires'] ?? 0) : 0;
        if ($expires > 0 && $expires < time()) {
            $deleted = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
                $option_name,
                $current
            ));
            wp_cache_delete($option_name, 'options');
            wp_cache_delete('alloptions', 'options');
            if ($deleted) {
                return self::reserve_autoresponse($email_hash, $project_slug, $window_hours);
            }
        }

        return false;
    }

    public static function release_autoresponse_reservation($email_hash, $project_slug, $token)
    {
        global $wpdb;

        $option_name = self::autoresponse_reservation_name($email_hash, $project_slug);
        $current = get_option($option_name);
        $current_data = is_string($current) ? json_decode($current, true) : null;
        if (!is_array($current_data) || !hash_equals((string) ($current_data['token'] ?? ''), (string) $token)) {
            return;
        }

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
            $option_name,
            $current
        ));
        wp_cache_delete($option_name, 'options');
        wp_cache_delete('alloptions', 'options');
    }

    private static function autoresponse_reservation_name($email_hash, $project_slug)
    {
        return 'estandar_leads_ar_' . substr(hash('sha256', $email_hash . '|' . $project_slug), 0, 40);
    }

    /**
     * LEAD_RESPONSE_ACCELERATION_V1 (Fase 2) — engagement de-dup check.
     *
     * Returns true if an `autoresponse_engaged` event already exists for
     * this (lead_id, submission_id) pair — used to fire the derived event
     * only once per interest, regardless of which channel (pdf/landing/
     * whatsapp) triggered it first.
     */
    public static function has_autoresponse_engagement($lead_id, $submission_id)
    {
        global $wpdb;

        $lead_id = absint($lead_id);
        $submission_id = absint($submission_id);
        if ($lead_id === 0) {
            return false;
        }

        if ($submission_id > 0) {
            $found = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}es_lead_events
                 WHERE lead_id = %d AND submission_id = %d AND event_type = 'autoresponse_engaged'
                 LIMIT 1",
                $lead_id,
                $submission_id
            ));
        } else {
            $found = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}es_lead_events
                 WHERE lead_id = %d AND submission_id IS NULL AND event_type = 'autoresponse_engaged'
                 LIMIT 1",
                $lead_id
            ));
        }

        return $found !== null;
    }
}
