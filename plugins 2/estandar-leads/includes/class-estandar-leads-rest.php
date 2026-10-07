<?php
/**
 * REST API endpoints for Estandar Leads.
 *
 * Read-only phase (MS-E01): GET leads with filters, GET lead by ID.
 * No writes. No OCI. No table changes.
 *
 * @package EstandarLeads
 * @since   13.0.154
 */

if (!defined('ABSPATH')) {
    exit;
}

class Estandar_Leads_REST
{
    const API_NAMESPACE = 'estandar/v1';
    const CAP_READ  = 'estandar_read';
    const CAP_WRITE = 'estandar_write';
    const CAP_ADMIN = 'estandar_admin';

    public static function init()
    {
        self::ensure_capabilities();
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    public static function ensure_capabilities()
    {
        $admin = get_role('administrator');
        if ($admin && !$admin->has_cap(self::CAP_READ)) {
            $admin->add_cap(self::CAP_READ);
            $admin->add_cap(self::CAP_WRITE);
            $admin->add_cap(self::CAP_ADMIN);
        }
    }

    public static function register_routes()
    {
        register_rest_route(self::API_NAMESPACE, '/leads', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'list_leads'),
            'permission_callback' => array(__CLASS__, 'check_read_permission'),
            'args'                => self::list_args(),
        ));

        register_rest_route(self::API_NAMESPACE, '/leads/(?P<id>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'get_lead'),
            'permission_callback' => array(__CLASS__, 'check_read_permission'),
            'args'                => array(
                'id' => array(
                    'required'          => true,
                    'validate_callback' => function ($value) {
                        return absint($value) > 0;
                    },
                    'sanitize_callback' => 'absint',
                ),
            ),
        ));

        register_rest_route(self::API_NAMESPACE, '/leads/schema', array(
            'methods'             => 'GET',
            'callback'            => array(__CLASS__, 'schema'),
            'permission_callback' => array(__CLASS__, 'check_read_permission'),
        ));
    }

    public static function register_capabilities()
    {
        $admin = get_role('administrator');
        if ($admin) {
            $admin->add_cap(self::CAP_READ);
            $admin->add_cap(self::CAP_WRITE);
            $admin->add_cap(self::CAP_ADMIN);
        }
    }

    public static function check_read_permission()
    {
        return current_user_can(self::CAP_READ) || current_user_can('manage_options');
    }

    public static function check_write_permission()
    {
        return current_user_can(self::CAP_WRITE) || current_user_can('manage_options');
    }

    // ── List ────────────────────────────────────────────────────────────────

    public static function list_leads(WP_REST_Request $request)
    {
        global $wpdb;

        $leads_table = $wpdb->prefix . 'es_leads';
        $where = array('1=1');
        $params = array();

        $status = sanitize_text_field($request->get_param('status'));
        if ($status !== '') {
            $where[] = 'l.status = %s';
            $params[] = strtoupper($status);
        }

        $quality = sanitize_text_field($request->get_param('quality'));
        if ($quality !== '') {
            $where[] = 'l.quality = %s';
            $params[] = strtoupper($quality);
        }

        $project = sanitize_key($request->get_param('project'));
        if ($project !== '') {
            $where[] = 'l.project_slug = %s';
            $params[] = $project;
        }

        $gclid = sanitize_text_field($request->get_param('gclid'));
        if ($gclid !== '') {
            $where[] = 'l.first_gclid = %s';
            $params[] = $gclid;
        }

        $date_from = sanitize_text_field($request->get_param('date_from'));
        if ($date_from !== '') {
            $where[] = 'l.created_at >= %s';
            $params[] = $date_from;
        }

        $date_to = sanitize_text_field($request->get_param('date_to'));
        if ($date_to !== '') {
            $where[] = 'l.created_at <= %s';
            $params[] = $date_to;
        }

        $search = sanitize_text_field($request->get_param('search'));
        if ($search !== '') {
            $where[] = '(l.name LIKE %s OR l.email LIKE %s OR l.phone LIKE %s)';
            $like = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $operator_id = absint($request->get_param('operator_id'));
        if ($operator_id > 0) {
            $where[] = 'l.assigned_operator_id = %d';
            $params[] = $operator_id;
        }

        $limit = min(100, max(1, absint($request->get_param('limit') ?: 50)));
        $offset = max(0, absint($request->get_param('offset') ?: 0));

        $order = sanitize_key($request->get_param('order') ?: 'desc');
        $order = in_array($order, array('asc', 'desc'), true) ? $order : 'desc';

        $orderby = sanitize_key($request->get_param('orderby') ?: 'created_at');
        $allowed_orderby = array('id', 'created_at', 'updated_at', 'last_seen_at', 'name');
        $orderby = in_array($orderby, $allowed_orderby, true) ? $orderby : 'created_at';

        $where_clause = implode(' AND ', $where);

        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$leads_table} l WHERE {$where_clause}",
                $params
            )
        );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT l.* FROM {$leads_table} l
                 WHERE {$where_clause}
                 ORDER BY l.{$orderby} {$order}
                 LIMIT %d OFFSET %d",
                array_merge($params, array($limit, $offset))
            ),
            ARRAY_A
        );

        $leads = array();
        foreach ((array) $rows as $row) {
            $leads[] = self::format_lead($row);
        }

        self::attach_latest_qualifications($leads);

        $response = new WP_REST_Response(array(
            'schema'  => 'estandar/v1/leads',
            'total'   => $total,
            'limit'   => $limit,
            'offset'  => $offset,
            'leads'   => $leads,
        ), 200);

        $response->header('X-WP-Total', (string) $total);
        $response->header('X-WP-TotalPages', (string) ceil($total / max(1, $limit)));

        return $response;
    }

    // ── Single ──────────────────────────────────────────────────────────────

    public static function get_lead(WP_REST_Request $request)
    {
        global $wpdb;

        $lead_id = absint($request->get_param('id'));

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}es_leads WHERE id = %d LIMIT 1",
                $lead_id
            ),
            ARRAY_A
        );

        if (!$row) {
            return self::error_response(
                'estandar_lead_not_found',
                "Lead with ID {$lead_id} not found.",
                404
            );
        }

        $submissions = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, source_plugin, source_form_id, submitted_at,
                        utm_source, utm_medium, utm_campaign, utm_term, utm_content,
                        gclid, gbraid, wbraid, fbclid, landing_url, referrer,
                        session_id, context_json, form_fields_json
                 FROM {$wpdb->prefix}es_lead_submissions
                 WHERE lead_id = %d
                 ORDER BY created_at ASC",
                $lead_id
            ),
            ARRAY_A
        );

        $latest_classification = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT quality, status, sales_stage, is_valid_lead, invalid_reason,
                        would_buy_again, classified_at, operator_id
                 FROM {$wpdb->prefix}es_lead_classifications
                 WHERE lead_id = %d
                 ORDER BY classified_at DESC, id DESC
                 LIMIT 1",
                $lead_id
            ),
            ARRAY_A
        );

        $lead = self::format_lead($row);
        $lead['submissions'] = array();
        foreach ((array) $submissions as $sub) {
            $context = json_decode((string) ($sub['context_json'] ?? ''), true);
            $fields  = json_decode((string) ($sub['form_fields_json'] ?? ''), true);
            unset($sub['context_json'], $sub['form_fields_json']);
            $sub['context']     = is_array($context) ? $context : null;
            $sub['form_fields'] = is_array($fields)  ? $fields  : null;
            $lead['submissions'][] = $sub;
        }

        if ($latest_classification) {
            $lead['latest_classification'] = array(
                'quality'         => (string) ($latest_classification['quality'] ?? ''),
                'status'          => (string) ($latest_classification['status'] ?? ''),
                'sales_stage'     => (string) ($latest_classification['sales_stage'] ?? ''),
                'is_valid_lead'   => isset($latest_classification['is_valid_lead']) ? (bool) $latest_classification['is_valid_lead'] : null,
                'invalid_reason'  => (string) ($latest_classification['invalid_reason'] ?? ''),
                'would_buy_again' => (string) ($latest_classification['would_buy_again'] ?? ''),
                'classified_at'   => (string) ($latest_classification['classified_at'] ?? ''),
                'operator_id'     => absint($latest_classification['operator_id'] ?? 0),
            );
        } else {
            $lead['latest_classification'] = null;
        }

        return new WP_REST_Response($lead, 200);
    }

    // ── Schema ───────────────────────────────────────────────────────────────

    public static function schema()
    {
        return new WP_REST_Response(array(
            'schema'     => 'estandar/v1/leads',
            'version'    => 1,
            'namespace'  => 'estandar/v1',
            'endpoints'  => array(
                'list'   => array(
                    'method'      => 'GET',
                    'path'        => '/estandar/v1/leads',
                    'description' => 'List leads with optional filters.',
                    'filters'     => self::schema_filters(),
                ),
                'single' => array(
                    'method'      => 'GET',
                    'path'        => '/estandar/v1/leads/{id}',
                    'description' => 'Get a single lead by ID, including submissions and latest classification.',
                ),
                'schema' => array(
                    'method'      => 'GET',
                    'path'        => '/estandar/v1/leads/schema',
                    'description' => 'Return this schema document.',
                ),
            ),
            'lead_shape' => array(
                'id', 'lead_uuid', 'name', 'email', 'phone',
                'status', 'quality', 'feedback_status',
                'project_slug', 'project_area', 'lead_intent', 'sales_stage',
                'is_valid_lead', 'invalid_reason', 'would_buy_again',
                'contact_attempts', 'assigned_operator_id', 'assigned_operator_name',
                'assigned_operator_email',
                'attribution', 'source_plugin',
                'first_seen_at', 'last_seen_at', 'first_contact_at',
                'last_classified_at', 'feedback_completed',
                'latest_qualification',
                'created_at', 'updated_at',
            ),
            'statuses'  => Estandar_Leads_Normalizer::statuses(),
            'qualities' => Estandar_Leads_Email::qualities(),
        ), 200);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Batch-attach the engine's effective qualification decision to each lead.
     *
     * The commercial qualification engine (COMMERCIAL_QUALIFICATION_V1) never
     * writes es_leads.quality — its decision is persisted as an event
     * (qualification_completed / qualification_manual_override) on
     * es_lead_events.metadata.quality. Consumers of the list endpoint (radar,
     * funnels) read `quality` from the leads table, which only holds the
     * operator feedback vocabulary. This helper surfaces the engine decision
     * as `latest_qualification` (same resolution as the engine:
     * manual override > most recent automatic).
     */
    private static function attach_latest_qualifications(array &$leads): void
    {
        if (empty($leads)) {
            return;
        }
        global $wpdb;

        $lead_ids = array();
        foreach ($leads as $lead) {
            $id = absint($lead['id'] ?? 0);
            if ($id > 0) {
                $lead_ids[] = $id;
            }
        }
        if (empty($lead_ids)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($lead_ids), '%d'));
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT e.lead_id, e.event_type, e.metadata, e.created_at
                 FROM {$wpdb->prefix}es_lead_events e
                 WHERE e.event_type IN (%s, %s)
                   AND e.lead_id IN ({$placeholders})
                 ORDER BY e.lead_id ASC,
                          (e.event_type = %s) DESC,
                          e.id DESC",
                array_merge(
                    array(
                        'qualification_completed',
                        'qualification_manual_override',
                        'qualification_manual_override',
                    ),
                    $lead_ids
                )
            ),
            ARRAY_A
        );

        $latest_by_lead = array();
        foreach ((array) $rows as $row) {
            $lead_id = absint($row['lead_id'] ?? 0);
            if ($lead_id <= 0 || isset($latest_by_lead[$lead_id])) {
                continue;
            }
            $decoded = json_decode((string) ($row['metadata'] ?? ''), true);
            $decision = is_array($decoded) ? ($decoded['qualification'] ?? null) : null;
            if (!is_array($decision)) {
                continue;
            }
            $latest_by_lead[$lead_id] = array(
                'decision_source'  => (string) ($decision['decision_source'] ?? ''),
                'decision'         => (string) ($decision['decision'] ?? ''),
                'qualification'    => (string) ($decision['qualification'] ?? ''),
                'commercial_fit'   => (string) ($decision['commercial_fit'] ?? ''),
                'routing_policy'   => (string) ($decision['routing_policy'] ?? ''),
                'quality'          => (string) ($decision['quality'] ?? ''),
                'confidence'       => isset($decision['confidence']) ? absint($decision['confidence']) : 0,
                'matched_rules'    => is_array($decision['matched_rules'] ?? null) ? $decision['matched_rules'] : array(),
                'reason'           => (string) ($decision['policy_reason'] ?? ''),
                'event_type'       => (string) ($row['event_type'] ?? ''),
                'qualified_at'     => (string) ($row['created_at'] ?? ''),
            );
        }

        foreach ($leads as &$lead) {
            $lead['latest_qualification'] = $latest_by_lead[absint($lead['id'] ?? 0)] ?? null;
        }
        unset($lead);
    }

    private static function format_lead(array $row): array
    {
        return array(
            'id'                => absint($row['id'] ?? 0),
            'lead_uuid'         => (string) ($row['lead_uuid'] ?? ''),
            'name'              => (string) ($row['name'] ?? ''),
            'email'             => (string) ($row['email'] ?? ''),
            'phone'             => (string) ($row['phone'] ?? ''),
            'status'            => (string) ($row['status'] ?? 'NEW'),
            'quality'           => (string) ($row['quality'] ?? ''),
            'feedback_status'   => (string) ($row['feedback_status'] ?? 'pending'),
            'project_slug'      => (string) ($row['project_slug'] ?? ''),
            'project_area'      => (string) ($row['project_area'] ?? ''),
            'lead_intent'       => (string) ($row['lead_intent'] ?? ''),
            'sales_stage'       => (string) ($row['sales_stage'] ?? ''),
            'is_valid_lead'     => isset($row['is_valid_lead']) ? (bool) $row['is_valid_lead'] : null,
            'invalid_reason'    => (string) ($row['invalid_reason'] ?? ''),
            'would_buy_again'   => (string) ($row['would_buy_again'] ?? ''),
            'contact_attempts'  => (int) ($row['contact_attempts'] ?? 0),
            'assigned_operator_id'   => absint($row['assigned_operator_id'] ?? 0),
            'assigned_operator_name' => (string) ($row['assigned_operator_name'] ?? ''),
            'assigned_operator_email' => (string) ($row['assigned_operator_email'] ?? ''),
            'attribution'       => array(
                'utm_source'   => (string) ($row['first_utm_source'] ?? ''),
                'utm_medium'   => (string) ($row['first_utm_medium'] ?? ''),
                'utm_campaign' => (string) ($row['first_utm_campaign'] ?? ''),
                'utm_term'     => (string) ($row['first_utm_term'] ?? ''),
                'utm_content'  => (string) ($row['first_utm_content'] ?? ''),
                'gclid'        => (string) ($row['first_gclid'] ?? ''),
                'gbraid'       => (string) ($row['first_gbraid'] ?? ''),
                'wbraid'       => (string) ($row['first_wbraid'] ?? ''),
                'fbclid'       => (string) ($row['first_fbclid'] ?? ''),
                'ttclid'       => (string) ($row['first_ttclid'] ?? ''),
                'msclkid'      => (string) ($row['first_msclkid'] ?? ''),
                'landing_url'  => (string) ($row['first_landing_url'] ?? ''),
                'referrer'     => (string) ($row['first_referrer'] ?? ''),
                'session_id'   => (string) ($row['first_session_id'] ?? ''),
            ),
            'source_plugin'  => (string) ($row['first_source_plugin'] ?? ''),
            'first_seen_at'  => (string) ($row['first_seen_at'] ?? ''),
            'last_seen_at'   => (string) ($row['last_seen_at'] ?? ''),
            'first_contact_at'     => $row['first_contact_at'] ?? null,
            'last_classified_at'    => $row['last_classified_at'] ?? null,
            'feedback_completed'    => (bool) ($row['feedback_completed'] ?? false),
            'latest_qualification'  => null,
            'created_at'            => (string) ($row['created_at'] ?? ''),
            'updated_at'            => (string) ($row['updated_at'] ?? ''),
        );
    }

    private static function list_args(): array
    {
        return array(
            'status'      => array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field'),
            'quality'     => array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field'),
            'project'     => array('type' => 'string', 'sanitize_callback' => 'sanitize_key'),
            'gclid'       => array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field'),
            'search'      => array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field'),
            'date_from'   => array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field'),
            'date_to'     => array('type' => 'string', 'sanitize_callback' => 'sanitize_text_field'),
            'operator_id' => array('type' => 'integer', 'sanitize_callback' => 'absint'),
            'limit'       => array('type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 50),
            'offset'      => array('type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 0),
            'order'       => array('type' => 'string', 'sanitize_callback' => 'sanitize_key', 'default' => 'desc'),
            'orderby'     => array('type' => 'string', 'sanitize_callback' => 'sanitize_key', 'default' => 'created_at'),
        );
    }

    private static function schema_filters(): array
    {
        return array(
            'status'      => array('type' => 'string', 'description' => 'Filter by lead operational status. Values: ' . implode(', ', Estandar_Leads_Normalizer::statuses())),
            'quality'     => array('type' => 'string', 'description' => 'Filter by lead quality. Values: ' . implode(', ', Estandar_Leads_Email::qualities())),
            'project'     => array('type' => 'string', 'description' => 'Filter by project slug.'),
            'gclid'       => array('type' => 'string', 'description' => 'Filter by exact GCLID match.'),
            'search'      => array('type' => 'string', 'description' => 'Full-text search across name, email, and phone.'),
            'date_from'   => array('type' => 'string', 'description' => 'Filter leads created on or after this date (Y-m-d H:i:s).'),
            'date_to'     => array('type' => 'string', 'description' => 'Filter leads created on or before this date (Y-m-d H:i:s).'),
            'operator_id' => array('type' => 'integer', 'description' => 'Filter by assigned operator ID.'),
            'limit'       => array('type' => 'integer', 'description' => 'Results per page (1-100, default 50).'),
            'offset'      => array('type' => 'integer', 'description' => 'Zero-based offset for pagination.'),
            'order'       => array('type' => 'string', 'description' => 'Sort direction: asc or desc. Default: desc.'),
            'orderby'     => array('type' => 'string', 'description' => 'Sort field: id, created_at, updated_at, last_seen_at, name. Default: created_at.'),
        );
    }

    private static function error_response(string $code, string $message, int $status): WP_REST_Response
    {
        return new WP_REST_Response(array(
            'error' => array(
                'code'    => $code,
                'message' => $message,
                'status'  => $status,
            ),
        ), $status);
    }
}
