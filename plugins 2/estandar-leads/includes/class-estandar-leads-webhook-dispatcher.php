<?php

if (!defined('ABSPATH')) {
    exit;
}

class Estandar_Leads_Webhook_Dispatcher
{
    const ROUTES_OPTION = 'estandar_leads_webhook_routes_v1';
    const MIGRATION_OPTION = 'estandar_leads_webhook_routes_migration_version';
    const MIGRATION_REPORT_OPTION = 'estandar_leads_webhook_routes_migration_report_v1';
    const OWNER_OPTION = 'estandar_leads_webhook_owner';
    const OWNER_LEADS = 'leads';
    const OWNER_LEGACY = 'legacy_forms';

    public static function init()
    {
        self::maybe_migrate_routes();
        add_action('estandar_lead_ingested', array(__CLASS__, 'dispatch'), 20, 4);
    }

    public static function owns_webhooks()
    {
        return get_option(self::OWNER_OPTION, self::OWNER_LEGACY) === self::OWNER_LEADS;
    }

    public static function maybe_migrate_routes()
    {
        if ((int) get_option(self::MIGRATION_OPTION, 0) >= 1) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'estandar_forms';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ((string) $exists !== $table) {
            return;
        }

        $forms = $wpdb->get_results(
            "SELECT id, name, webhook_url, webhook_payload_mode, context_schema
             FROM {$table}
             WHERE send_webhook = 1",
            ARRAY_A
        );
        $routes = get_option(self::ROUTES_OPTION, array());
        $routes = is_array($routes) ? $routes : array();
        $report = array(
            'migrated' => array(),
            'unresolved' => array(),
            'conflicts' => array(),
            'validated_at' => gmdate('c'),
        );

        foreach ((array) $forms as $form) {
            $routing = json_decode((string) ($form['context_schema'] ?? ''), true);
            $project_slug = sanitize_title(remove_accents((string) ($routing['routing']['project_slug'] ?? '')));
            $url = esc_url_raw(trim((string) ($form['webhook_url'] ?? '')));
            $mode = sanitize_key((string) ($form['webhook_payload_mode'] ?? 'estandar'));
            if (!in_array($mode, array('estandar', 'cf7_compat'), true)) {
                $mode = 'estandar';
            }

            if ($project_slug === '' || $url === '') {
                $report['unresolved'][] = array(
                    'form_id' => absint($form['id'] ?? 0),
                    'form_name' => sanitize_text_field((string) ($form['name'] ?? '')),
                    'reason' => $project_slug === '' ? 'missing_project_slug' : 'missing_webhook_url',
                );
                continue;
            }

            if (!empty($routes[$project_slug]['url']) && $routes[$project_slug]['url'] !== $url) {
                $report['conflicts'][] = array(
                    'form_id' => absint($form['id'] ?? 0),
                    'project_slug' => $project_slug,
                    'reason' => 'different_url_for_project',
                );
                unset($routes[$project_slug]);
                continue;
            }

            $routes[$project_slug] = array(
                'enabled' => true,
                'url' => $url,
                'payload_mode' => $mode,
                'source_form_id' => absint($form['id'] ?? 0),
                'validation' => self::validate_route($url),
            );
            $report['migrated'][] = array(
                'form_id' => absint($form['id'] ?? 0),
                'project_slug' => $project_slug,
            );
        }

        update_option(self::ROUTES_OPTION, $routes, false);
        update_option(self::MIGRATION_REPORT_OPTION, $report, false);
        update_option(self::MIGRATION_OPTION, 1, false);

        self::telemetry('info', 'WEBHOOK_ROUTES_MIGRATED', 'Webhook routes copied from Estandar Forms.', array(
            'migrated_count' => count($report['migrated']),
            'unresolved_count' => count($report['unresolved']),
            'conflict_count' => count($report['conflicts']),
        ));
    }

    public static function validate_route($url)
    {
        $url = esc_url_raw(trim((string) $url));
        $host = (string) wp_parse_url($url, PHP_URL_HOST);
        $valid_url = $url !== '' && wp_http_validate_url($url);
        $dns_valid = false;

        if ($valid_url && $host !== '') {
            if (function_exists('dns_get_record')) {
                $records = @dns_get_record($host, DNS_A + DNS_AAAA + DNS_CNAME);
                $dns_valid = is_array($records) && !empty($records);
            } else {
                $resolved = gethostbyname($host);
                $dns_valid = $resolved !== $host;
            }
        }

        return array(
            'status' => $valid_url && $dns_valid ? 'valid' : 'invalid',
            'url_valid' => $valid_url,
            'dns_valid' => $dns_valid,
            'checked_at' => gmdate('c'),
        );
    }

    public static function dispatch($lead_id, $submission_id, $is_new, $payload)
    {
        if (!self::owns_webhooks() || !is_array($payload)) {
            return;
        }
        if (class_exists('Estandar_Leads_QA_Policy') && !empty(Estandar_Leads_QA_Policy::from_payload($payload)['is_test_lead'])) {
            self::record_skip($lead_id, $submission_id, '', 'QA_ROUTING_SKIPPED');
            return;
        }

        $project_slug = sanitize_title(remove_accents((string) ($payload['context']['project_slug'] ?? '')));
        if ($project_slug === '' && class_exists('Estandar_Leads_Projects')) {
            $project_slug = Estandar_Leads_Projects::resolve_slug((string) ($payload['context']['project_area'] ?? ''));
        }

        $routes = get_option(self::ROUTES_OPTION, array());
        $route = is_array($routes) && isset($routes[$project_slug]) && is_array($routes[$project_slug])
            ? $routes[$project_slug]
            : array();

        if ($project_slug === '' || empty($route['enabled']) || empty($route['url'])) {
            self::record_skip($lead_id, $submission_id, $project_slug, 'WEBHOOK_ROUTE_NOT_FOUND');
            return;
        }

        $validation = isset($route['validation']) && is_array($route['validation'])
            ? $route['validation']
            : self::validate_route($route['url']);
        if (($validation['status'] ?? '') !== 'valid') {
            self::record_skip($lead_id, $submission_id, $project_slug, 'WEBHOOK_ROUTE_INVALID');
            return;
        }

        $url = esc_url_raw((string) $route['url']);
        $mode = sanitize_key((string) ($route['payload_mode'] ?? 'estandar'));
        $dedupe_hash = hash('sha256', absint($submission_id) . '|' . $project_slug . '|' . $url . '|' . $mode);
        $dedupe_key = 'estandar_leads_wh_' . substr($dedupe_hash, 0, 40);
        if (!add_option($dedupe_key, gmdate('c'), '', 'no')) {
            self::record_skip($lead_id, $submission_id, $project_slug, 'WEBHOOK_DUPLICATE_SKIPPED');
            return;
        }

        $body = $mode === 'cf7_compat'
            ? self::build_cf7_payload($payload)
            : self::build_payload($lead_id, $submission_id, $is_new, $payload, $project_slug);
        $args = array(
            'timeout' => 8,
            'blocking' => false,
            'sslverify' => true,
            'headers' => array(
                'X-Source' => 'estandar-leads',
                'X-Project-Slug' => $project_slug,
                'Idempotency-Key' => 'submission:' . absint($submission_id) . ':' . substr($dedupe_hash, 0, 16),
            ),
        );

        if ($mode === 'cf7_compat') {
            $args['body'] = $body;
        } else {
            $encoded = wp_json_encode($body);
            if (!$encoded) {
                delete_option($dedupe_key);
                self::record_skip($lead_id, $submission_id, $project_slug, 'WEBHOOK_PAYLOAD_ENCODE_FAILED');
                return;
            }
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = $encoded;
        }

        $response = wp_remote_post($url, $args);
        if (is_wp_error($response)) {
            delete_option($dedupe_key);
            Estandar_Leads_Repository::insert_event($lead_id, 'webhook_failed', array(
                'submission_id' => $submission_id,
                'reason' => 'transport_error',
                'metadata' => array('project_slug' => $project_slug),
            ));
            self::telemetry('error', 'WEBHOOK_DISPATCH_FAILED', 'Lead webhook transport failed.', array(
                'lead_id' => absint($lead_id),
                'submission_id' => absint($submission_id),
                'project_slug' => $project_slug,
            ));
            return;
        }

        Estandar_Leads_Repository::insert_event($lead_id, 'webhook_dispatched', array(
            'submission_id' => $submission_id,
            'metadata' => array(
                'project_slug' => $project_slug,
                'payload_mode' => $mode,
                'url_host' => wp_parse_url($url, PHP_URL_HOST),
            ),
        ));
    }

    private static function build_payload($lead_id, $submission_id, $is_new, array $payload, $project_slug)
    {
        return array(
            'schema_version' => '1.0',
            'event' => 'lead.submitted',
            'event_id' => 'submission:' . absint($submission_id),
            'occurred_at' => gmdate('c'),
            'lead' => array_merge(array(
                'id' => absint($lead_id),
                'is_new' => (bool) $is_new,
            ), (array) ($payload['contact'] ?? array())),
            'submission' => array(
                'id' => absint($submission_id),
                'source_plugin' => sanitize_key((string) ($payload['source_plugin'] ?? '')),
                'source_form_id' => sanitize_text_field((string) ($payload['content']['source_form_id'] ?? '')),
                'source_entry_id' => absint($payload['meta']['source_entry_id'] ?? 0),
                'message' => sanitize_textarea_field((string) ($payload['content']['message'] ?? '')),
            ),
            'context' => array_merge((array) ($payload['context'] ?? array()), array(
                'project_slug' => $project_slug,
            )),
            'attribution' => (array) ($payload['attribution'] ?? array()),
            'scoring' => (array) ($payload['scoring'] ?? array()),
            'chatbot' => (array) ($payload['chatbot'] ?? array()),
            'meta' => (array) ($payload['meta'] ?? array()),
        );
    }

    private static function build_cf7_payload(array $payload)
    {
        $fields = isset($payload['meta']['sanitized_raw_fields']) && is_array($payload['meta']['sanitized_raw_fields'])
            ? $payload['meta']['sanitized_raw_fields']
            : array();
        $contact = isset($payload['contact']) && is_array($payload['contact']) ? $payload['contact'] : array();

        return array(
            '_date' => date_i18n('j \d\e F \d\e Y', current_time('timestamp')),
            'your-name' => self::first_value($fields, array('your-name', 'nombre', 'name', 'first_name', 'firstname'), (string) ($contact['name'] ?? '')),
            'your-apellido' => self::first_value($fields, array('your-apellido', 'apellido', 'last_name', 'lastname')),
            'your-email' => self::first_value($fields, array('your-email', 'email', 'correo', 'mail'), (string) ($contact['email'] ?? '')),
            'your-phone' => self::sheets_safe_phone(self::first_value($fields, array('your-phone', 'telefono', 'phone', 'tel', 'celular', 'mobile'), (string) ($contact['phone'] ?? ''))),
            'tu-rut' => self::first_value($fields, array('tu-rut', 'rut', 'RUT')),
        );
    }

    /**
     * SHEETS_PHONE_EXPORT_V1 — export-safe phone for cf7_compat consumers
     * (n8n → Google Sheets). Normalized via Estandar_Leads_Normalizer::phone()
     * and emitted WITHOUT the leading '+' so spreadsheet clients never parse
     * it as a formula, regardless of write mode (RAW or USER_ENTERED).
     * Scope: cf7_compat export adapter only. Display surfaces (email) keep
     * the '+'. DB (phone / phone_normalized), capture, attribution and
     * WhatsApp links are untouched.
     */
    private static function sheets_safe_phone($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        $normalized = class_exists('Estandar_Leads_Normalizer')
            ? (string) Estandar_Leads_Normalizer::phone($value)
            : '';
        if ($normalized === '') {
            // Digits-only fallback: never forward non-numeric content to a
            // spreadsheet cell (blocks formula injection via =, +, -, @).
            $normalized = (string) preg_replace('/\D+/', '', $value);
        }
        return ltrim($normalized, '+');
    }

    private static function first_value(array $fields, array $keys, $fallback = '')
    {
        foreach ($keys as $key) {
            if (isset($fields[$key]) && is_scalar($fields[$key])) {
                $value = sanitize_text_field((string) $fields[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }
        return sanitize_text_field((string) $fallback);
    }

    private static function record_skip($lead_id, $submission_id, $project_slug, $code)
    {
        Estandar_Leads_Repository::insert_event($lead_id, 'webhook_skipped', array(
            'submission_id' => $submission_id,
            'reason' => $code,
            'metadata' => array('project_slug' => $project_slug),
        ));
        self::telemetry('warning', $code, 'Lead webhook skipped.', array(
            'lead_id' => absint($lead_id),
            'submission_id' => absint($submission_id),
            'project_slug' => $project_slug,
        ));
    }

    private static function telemetry($severity, $code, $message, array $context = array())
    {
        try {
            do_action('estandar_telemetry_event', array(
                'severity' => sanitize_key($severity),
                'code' => sanitize_key($code),
                'message' => sanitize_text_field($message),
                'context' => $context,
                'source_plugin' => 'estandar-leads',
            ));
        } catch (Throwable $e) {
            error_log('[estandar-leads] Webhook telemetry failed: ' . $e->getMessage());
        }
    }
}
