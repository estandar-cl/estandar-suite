<?php
/**
 * Stateless tracked-link redirect endpoint.
 *
 * LEAD_RESPONSE_ACCELERATION_V1 (Fase 2).
 * Ownership: estandar-leads.
 * Route: GET /wp-json/estandar-leads/v1/go/{token}
 *
 * {token} is an HMAC-signed, stateless payload created by
 * Estandar_Leads_Tokens::sign_link() — no DB row, no estandar-leads/v1/go
 * table. verify_link() checks signature + expiry and returns the original
 * target_url + resource_type ('pdf' | 'landing' | 'pixel').
 *
 * - resource_type = 'pdf' | 'landing' → records `autoresponse_link_click`
 *   (metadata.resource_type) + derived `autoresponse_engaged` (first of
 *   pdf/landing/whatsapp), then 302-redirects to the original URL.
 * - resource_type = 'pixel' → records `autoresponse_email_open_signal`
 *   (referencial only, ver §4 spec) and outputs a 1x1 transparent GIF —
 *   no redirect.
 * - invalid/expired token → 404 wp_die(), no event recorded.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Estandar_Leads_Link_Redirect
{
    const ROUTE_NAMESPACE = 'estandar-leads/v1';
    const ROUTE_PATH = '/go/(?P<token>.+)';

    // 1x1 transparent GIF.
    const PIXEL_GIF_BASE64 = 'R0lGODlhAQABAAAAACwAAAAAAQABAAACAkQBADs=';

    public static function init(): void
    {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    public static function register_routes(): void
    {
        register_rest_route(
            self::ROUTE_NAMESPACE,
            self::ROUTE_PATH,
            array(
                'methods' => 'GET',
                'callback' => array(__CLASS__, 'handle'),
                'permission_callback' => '__return_true',
                'args' => array(
                    'token' => array(
                        'required' => true,
                    ),
                ),
            )
        );
    }

    public static function handle(WP_REST_Request $request): void
    {
        $token = (string) $request->get_param('token');
        $verified = Estandar_Leads_Tokens::verify_link($token);

        if ($verified === false) {
            wp_die(esc_html__('Enlace inválido o vencido.', 'estandar-leads'), '', array('response' => 404));
        }

        $lead_id = $verified['lead_id'];
        $submission_id = $verified['submission_id'];
        $resource_type = $verified['resource_type'];
        $target_url = $verified['target_url'];
        $project_slug = $verified['project_slug'];

        if ($resource_type === 'operator_email_opened') {
            self::record_operator_event($verified, 'operator_email_opened', true);
            self::output_pixel();
            exit;
        }

        if ($resource_type === 'pixel') {
            Estandar_Leads_Autoresponse::record_open_signal($lead_id, $submission_id, $project_slug);
            self::output_pixel();
            exit;
        }

        $operator_events = array(
            'operator_clicked_call',
            'operator_clicked_whatsapp',
            'operator_clicked_email',
            'operator_clicked_lead',
            'operator_clicked_maps',
            'operator_feedback_started',
        );
        if (in_array($resource_type, $operator_events, true)) {
            self::record_operator_event($verified, $resource_type, $resource_type === 'operator_feedback_started');
            self::allow_redirect_host($target_url);
            self::redirect($target_url);
            exit;
        }

        // ACTOR_AUDIT_FIX_V1 — context is captured inside record_link_click()
        // from $_SERVER globals, no need to pass explicitly
        Estandar_Leads_Autoresponse::record_link_click($lead_id, $submission_id, $resource_type, $project_slug);

        self::allow_redirect_host($target_url);
        self::redirect($target_url);
        exit;
    }

    private static function record_operator_event(array $verified, string $event_type, bool $once): void
    {
        $lead_id = absint($verified['lead_id'] ?? 0);
        if ($lead_id < 1) {
            return;
        }

        $submission_id = absint($verified['submission_id'] ?? 0);
        $operator_id = absint($verified['operator_id'] ?? 0);
        if ($once && self::operator_event_exists($lead_id, $submission_id, $operator_id, $event_type)) {
            return;
        }

        Estandar_Leads_Repository::insert_event($lead_id, $event_type, array(
            'submission_id' => $submission_id,
            'operator_id'   => $operator_id,
            'metadata'      => array(
                'project_slug' => sanitize_key($verified['project_slug'] ?? ''),
                'target_hash'  => hash('sha256', (string) ($verified['target_url'] ?? '')),
                'source'       => 'operator_notification',
            ),
            'ip_address'    => sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '')),
            'user_agent'    => sanitize_textarea_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? '')),
        ));
    }

    private static function operator_event_exists(int $lead_id, int $submission_id, int $operator_id, string $event_type): bool
    {
        global $wpdb;

        $where = "lead_id = %d AND event_type = %s";
        $args = array($lead_id, $event_type);
        if ($submission_id > 0) {
            $where .= " AND submission_id = %d";
            $args[] = $submission_id;
        }
        if ($operator_id > 0) {
            $where .= " AND operator_id = %d";
            $args[] = $operator_id;
        }

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}es_lead_events WHERE {$where} LIMIT 1",
            $args
        )) > 0;
    }

    private static function allow_redirect_host(string $target_url): void
    {
        $host = wp_parse_url($target_url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return;
        }
        add_filter('allowed_redirect_hosts', static function (array $hosts) use ($host): array {
            $hosts[] = $host;
            return array_values(array_unique($hosts));
        });
    }

    private static function redirect(string $target_url): void
    {
        $scheme = strtolower((string) wp_parse_url($target_url, PHP_URL_SCHEME));
        if (in_array($scheme, array('mailto', 'tel'), true)) {
            wp_redirect($target_url, 302);
            return;
        }
        wp_safe_redirect($target_url, 302);
    }

    private static function output_pixel(): void
    {
        $gif = base64_decode(self::PIXEL_GIF_BASE64);

        header('Content-Type: image/gif');
        header('Content-Length: ' . strlen($gif));
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        echo $gif;
    }
}
