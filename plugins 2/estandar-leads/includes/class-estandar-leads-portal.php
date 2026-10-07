<?php
/**
 * Operator portal — query layer for the lead management dashboard.
 *
 * Every public method returns an array of stdClass rows ready for the template.
 * All queries guard against SQL injection via $wpdb->prepare().
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Estandar_Leads_Portal {

    // -------------------------------------------------------------------------
    // View constants
    // -------------------------------------------------------------------------

    const VIEW_ALL        = 'all';
	    const VIEW_PENDING    = 'pending';
	    const VIEW_ESCALATED  = 'escalated';
	    const VIEW_UNREACHED  = 'unreached';
	    const VIEW_HOT        = 'hot';
	    const VIEW_RECONTACT_NO_ACTION = 'recontact_no_action';
	    const VIEW_ASSIGNED_NO_WS = 'assigned_no_workspace';
	    const VIEW_WS_NO_MGMT     = 'workspace_no_management';
	    const VIEW_WORK_NOW   = 'work_now';
	    const VIEW_HISTORY    = 'history';

	    const ISSUE_UNASSIGNED_24H  = 'unassigned_24h';
	    const ISSUE_UNASSIGNED_72H  = 'unassigned_72h';
	    const ISSUE_STALE_7D        = 'stale_7d';
	    const ISSUE_NEW_24H         = 'new_24h';
	    const ISSUE_NO_SOURCE       = 'no_source';
	    const ISSUE_CONVERTED_NO_ATTR = 'converted_no_attr';

    public static function views() {
        return array(
            self::VIEW_ALL       => 'Todos',
	            self::VIEW_PENDING   => 'Sin clasificar',
	            self::VIEW_ESCALATED => 'Escalados',
		            self::VIEW_UNREACHED => 'Sin contacto',
		            self::VIEW_HOT       => 'Calientes',
		            self::VIEW_RECONTACT_NO_ACTION => 'Recontactados sin acción',
	            self::VIEW_ASSIGNED_NO_WS => 'Asignado sin abrir workspace',
	            self::VIEW_WS_NO_MGMT     => 'Workspace abierto sin gestión',
		            self::VIEW_HISTORY   => 'Historial',
	        );
	    }

    // -------------------------------------------------------------------------
    // Filters (parsed from GET)
    // -------------------------------------------------------------------------

    /**
     * Parse and sanitize GET filters.
     * Returns an array usable by get_leads() and build_where().
     */
    public static function parse_filters() {
        return array(
            'view'        => sanitize_key( $_GET['view']        ?? self::VIEW_ALL ),
            'project'     => sanitize_key( $_GET['project']     ?? '' ),
            'quality'     => strtoupper( sanitize_key( $_GET['quality'] ?? '' ) ),
            'status'      => strtoupper( sanitize_key( $_GET['status']  ?? '' ) ),
	            'operator_id' => absint( $_GET['operator_id'] ?? 0 ),
	            'source'      => sanitize_key( $_GET['source']      ?? '' ),
	            'issue'       => self::sanitize_issue( $_GET['issue'] ?? '' ),
	            'date_from'   => sanitize_text_field( $_GET['date_from'] ?? '' ),
	            'date_to'     => sanitize_text_field( $_GET['date_to']   ?? '' ),
	            'search'      => sanitize_text_field( $_GET['search']    ?? '' ),
	        );
	    }

	    public static function issue_labels() {
	        return array(
	            self::ISSUE_UNASSIGNED_24H    => 'Sin operador <24h',
		            self::ISSUE_UNASSIGNED_72H    => 'Sin operador >24h',
		            self::ISSUE_STALE_7D          => 'Leads +7d sin clasificar',
		            self::ISSUE_NEW_24H           => 'Nuevos <24h',
		            self::ISSUE_NO_SOURCE         => 'Leads sin fuente atribuida',
	            self::ISSUE_CONVERTED_NO_ATTR => 'Ventas sin atribución',
	        );
	    }

	    private static function sanitize_issue( $issue ) {
	        $issue = sanitize_key( (string) $issue );
	        return array_key_exists( $issue, self::issue_labels() ) ? $issue : '';
	    }

    // -------------------------------------------------------------------------
    // Main query
    // -------------------------------------------------------------------------

    /**
     * Fetch leads for the portal.
     *
     * @param array $filters  From parse_filters().
     * @param int   $per_page
     * @param int   $page     1-based.
     * @return array { rows: stdClass[], total: int }
     */
    public static function get_leads( array $filters, $per_page = 50, $page = 1 ) {
        global $wpdb;

        $leads       = $wpdb->prefix . 'es_leads';
        $submissions = $wpdb->prefix . 'es_lead_submissions';
        $cls         = $wpdb->prefix . 'es_lead_classifications';

        list( $where_sql, $where_vals ) = self::build_where( $filters, $leads );

        // COUNT query
        $count_sql  = "SELECT COUNT(DISTINCT {$leads}.id)
                       FROM {$leads}
                       LEFT JOIN {$cls} ON {$cls}.lead_id = {$leads}.id
                       WHERE {$where_sql}";
        $total = (int) $wpdb->get_var( $where_vals
            ? $wpdb->prepare( $count_sql, $where_vals )
            : $count_sql
        );

	        // Data query
	        $operators = $wpdb->prefix . 'es_operators';
	        $offset = max( 0, ( $page - 1 ) ) * $per_page;
	        list( $order_sql, $order_vals ) = self::build_order( $filters, $leads, $submissions );
	        $data_sql = "SELECT
                {$leads}.id,
                {$leads}.status,
                {$leads}.quality,
                {$leads}.sales_stage,
                {$leads}.invalid_reason,
                {$leads}.name,
                {$leads}.email,
                {$leads}.phone,
                {$leads}.project_slug,
                {$leads}.project_area,
                {$leads}.first_utm_campaign,
                {$leads}.first_utm_term,
                {$leads}.first_source_plugin,
                {$leads}.first_seen_at,
                {$leads}.last_seen_at,
                {$leads}.feedback_completed,
                {$leads}.assigned_operator_id,
                {$leads}.assigned_operator_name,
                {$leads}.assigned_at,
                {$leads}.contact_attempts,
                {$leads}.first_contact_at,
                {$leads}.feedback_status,
                {$leads}.created_at,
                {$leads}.last_classified_at,
                {$leads}.last_classification_operator_id,
	                op_cls.name AS last_classifier_name,
	                (SELECT COUNT(*) FROM {$submissions} WHERE {$submissions}.lead_id = {$leads}.id) AS submission_count,
	                (SELECT MAX(submitted_at) FROM {$submissions} WHERE {$submissions}.lead_id = {$leads}.id) AS last_submitted_at,
	                (SELECT COUNT(*) FROM {$wpdb->prefix}es_lead_events e WHERE e.lead_id = {$leads}.id AND e.event_type = 'routing_fallback_used') AS routing_fallback_count,
	                (SELECT COUNT(*) FROM {$cls} WHERE {$cls}.lead_id = {$leads}.id) AS classification_count,
	                (SELECT form_fields_json FROM {$submissions} WHERE lead_id = {$leads}.id ORDER BY submitted_at DESC LIMIT 1) AS last_form_fields_json
            FROM {$leads}
            LEFT JOIN {$cls} ON {$cls}.lead_id = {$leads}.id
	            LEFT JOIN {$operators} op_cls ON op_cls.id = {$leads}.last_classification_operator_id
	            WHERE {$where_sql}
	            GROUP BY {$leads}.id
		            ORDER BY {$order_sql}
		            LIMIT %d OFFSET %d";

	        $vals = array_merge( $where_vals, $order_vals, array( $per_page, $offset ) );
        $rows = $wpdb->get_results( $wpdb->prepare( $data_sql, $vals ) );

        return array(
            'rows'       => $rows ?: array(),
            'total'      => $total,
            'per_page'   => $per_page,
            'page'       => $page,
            'page_count' => (int) ceil( $total / $per_page ),
        );
    }

    // -------------------------------------------------------------------------
    // Single lead detail
    // -------------------------------------------------------------------------

    public static function get_lead_detail( $lead_id ) {
        global $wpdb;

        $lead_id = absint( $lead_id );
        if ( $lead_id === 0 ) {
            return null;
        }

        $leads     = $wpdb->prefix . 'es_leads';
        $subs      = $wpdb->prefix . 'es_lead_submissions';
        $events    = $wpdb->prefix . 'es_lead_events';
        $cls       = $wpdb->prefix . 'es_lead_classifications';
        $contacts  = $wpdb->prefix . 'es_lead_contacts';
        $operators = $wpdb->prefix . 'es_operators';

        $lead = $wpdb->get_row( $wpdb->prepare(
            "SELECT l.*, o.name AS current_operator_name
             FROM {$leads} l
             LEFT JOIN {$operators} o ON o.id = l.assigned_operator_id
             WHERE l.id = %d LIMIT 1",
            $lead_id
        ) );

        if ( ! $lead ) {
            return null;
        }

        $submissions = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, source_plugin, source_form_id, source_entry_id, schema_version, submitted_at, created_at, message,
                    utm_source, utm_medium, utm_campaign, utm_term, utm_content,
                    gclid, gbraid, wbraid, fbclid, ttclid,
                    landing_url, referrer, session_id,
                    first_touch_timestamp, last_touch_timestamp,
                    suspect, context_json, scoring_json, chatbot_json, form_fields_json
             FROM {$subs} WHERE lead_id = %d ORDER BY submitted_at DESC LIMIT 20",
            $lead_id
        ) );
        foreach ( $submissions ?: array() as $submission ) {
            $project = class_exists( 'Estandar_Leads_Repository' ) ? Estandar_Leads_Repository::canonical_project( $lead, $submission ) : (string) ( $lead->project_slug ?? '' );
            if ( class_exists( 'Estandar_Leads_Repository' ) && $project === Estandar_Leads_Repository::UNRESOLVED_PROJECT ) {
                $project = '';
            }
            $submission->canonical_project = $project;
        }

        $submission_stats = $wpdb->get_row( $wpdb->prepare(
            "SELECT COUNT(*) AS submission_count, MIN(submitted_at) AS first_submitted_at, MAX(submitted_at) AS last_submitted_at
             FROM {$subs} WHERE lead_id = %d",
            $lead_id
        ) );

        $history = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.*, o.name AS operator_name
             FROM {$events} e
             LEFT JOIN {$operators} o ON o.id = e.operator_id
             WHERE e.lead_id = %d ORDER BY e.created_at DESC LIMIT 30",
            $lead_id
        ) );

        $classifications = $wpdb->get_results( $wpdb->prepare(
            "SELECT c.*, o.name AS operator_name
             FROM {$cls} c
             LEFT JOIN {$operators} o ON o.id = c.operator_id
             WHERE c.lead_id = %d ORDER BY c.classified_at DESC",
            $lead_id
        ) );

        $contact_log = $wpdb->get_results( $wpdb->prepare(
            "SELECT ct.*, o.name AS operator_name
             FROM {$contacts} ct
             LEFT JOIN {$operators} o ON o.id = ct.operator_id
             WHERE ct.lead_id = %d ORDER BY ct.contacted_at DESC",
            $lead_id
        ) );
        $oci = self::table_exists( 'es_oci_queue' )
            ? $wpdb->get_row( $wpdb->prepare(
                "SELECT queue_status AS oci_status, export_status, last_error AS oci_error_message
                 FROM {$wpdb->prefix}es_oci_queue
                 WHERE lead_id = %d
                 ORDER BY created_at DESC, id DESC LIMIT 1",
                $lead_id
            ) )
            : null;

        return array(
            'lead'            => $lead,
            'submissions'     => $submissions ?: array(),
            'submission_stats'=> $submission_stats,
            'history'         => $history ?: array(),
            'classifications' => $classifications ?: array(),
            'contact_log'     => $contact_log ?: array(),
            'oci'             => $oci,
            'person_summary'  => self::person_summary( $lead ),
            'commercial_timeline' => self::commercial_timeline( $lead, $submissions ?: array(), $history ?: array(), $classifications ?: array(), $contact_log ?: array() ),
        );
    }

    private static function table_exists( string $table ): bool {
        global $wpdb;
        $table = sanitize_key( $table );
        return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $table ) ) === $wpdb->prefix . $table;
    }

    private static function person_summary( $lead ): array {
        global $wpdb;

        $lead_id = absint( $lead->id ?? 0 );
        if ( $lead_id === 0 ) {
            return array();
        }

        $where = array( 'id = %d' );
        $vals  = array( $lead_id );
        foreach ( array( 'email_hash', 'phone_hash' ) as $field ) {
            $value = trim( (string) ( $lead->{$field} ?? '' ) );
            if ( $value !== '' ) {
                $where[] = "{$field} = %s";
                $vals[] = $value;
            }
        }
        if ( self::column_exists( 'es_leads', 'rut' ) ) {
            $rut = trim( (string) ( $lead->rut ?? '' ) );
            if ( $rut !== '' ) {
                $where[] = 'rut = %s';
                $vals[] = $rut;
            }
        }

        $leads = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, project_slug, last_seen_at FROM {$wpdb->prefix}es_leads WHERE " . implode( ' OR ', $where ) . ' ORDER BY last_seen_at DESC, id DESC',
            $vals
        ) );
        $ids = array_values( array_unique( array_map( 'absint', wp_list_pluck( $leads ?: array(), 'id' ) ) ) );
        if ( empty( $ids ) ) {
            $ids = array( $lead_id );
        }

        $id_ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $submission_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}es_lead_submissions WHERE lead_id IN ({$id_ph})",
            $ids
        ) );
        $customer_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}es_customers WHERE matched_lead_id IN ({$id_ph})",
            $ids
        ) );

        $projects = array();
        $latest = '';
        foreach ( $leads ?: array() as $row ) {
            $project = sanitize_key( (string) ( $row->project_slug ?? '' ) );
            if ( $project !== '' ) {
                $projects[ $project ] = true;
            }
            $date = (string) ( $row->last_seen_at ?? '' );
            if ( $date > $latest ) {
                $latest = $date;
            }
        }

        return array(
            'lead_count' => count( $ids ),
            'lead_ids' => $ids,
            'submission_count' => $submission_count,
            'project_count' => count( $projects ),
            'projects' => array_keys( $projects ),
            'customer_matched' => $customer_count > 0,
            'customer_count' => $customer_count,
            'latest_activity' => $latest,
        );
    }

    private static function commercial_timeline( $lead, array $submissions, array $history, array $classifications, array $contact_log ): array {
        global $wpdb;

        $items = array();
        $add = static function ( $date, $label, $detail = '' ) use ( &$items ) {
            $date = (string) $date;
            $label = sanitize_text_field( (string) $label );
            $detail = sanitize_text_field( (string) $detail );
            if ( $date === '' || $label === '' ) {
                return;
            }
            $items[] = array( 'date' => $date, 'label' => $label, 'detail' => $detail );
        };

        foreach ( array_reverse( $submissions ) as $sub ) {
            $project = (string) ( $sub->canonical_project ?? '' );
            $add( $sub->submitted_at ?: $sub->created_at ?? '', 'New submission', $project );
        }

        $event_labels = array(
            'lead_created' => 'Lead created',
            'operator_assigned' => 'Operator assigned',
            'contact_attempt' => 'First contact',
            'contact_logged' => 'Contact logged',
            'status_changed' => 'Status changed',
            'status_overridden' => 'Status changed',
            'quality_changed' => 'Qualified',
            'commercial_stage_changed' => 'Commercial stage changed',
            'feedback_status_changed' => 'Compliance updated',
            'compliance_escalation' => 'Compliance escalation',
            'lead_recontacted' => 'Returned',
            'lead_second_attempt_requested' => 'Second attempt requested',
            'lead_recovery_email_sent' => 'Recovery email sent',
            'lead_lost_reason_set' => 'Lost',
            'operator_email_opened' => 'Operator email opened',
            'operator_clicked_call' => 'Operator clicked call',
            'operator_clicked_whatsapp' => 'Operator clicked WhatsApp',
            'operator_clicked_email' => 'Operator clicked email',
            'operator_clicked_lead' => 'Operator opened lead',
            'operator_clicked_maps' => 'Operator opened maps',
            'operator_feedback_started' => 'Feedback started',
            'operator_feedback_completed' => 'Feedback completed',
            'sales_activation_recommendation_generated' => 'Sales recommendation generated',
            'sales_activation_email_sent' => 'Sales activation sent',
            'sales_activation_email_failed' => 'Sales activation failed',
        );
        foreach ( $history as $event ) {
            $type = (string) ( $event->event_type ?? '' );
            if ( empty( $event_labels[ $type ] ) ) {
                continue;
            }
            $detail = trim( implode( ' -> ', array_filter( array( (string) ( $event->from_status ?? '' ), (string) ( $event->to_status ?? '' ) ) ) ) );
            if ( $detail === '' ) {
                $detail = (string) ( $event->operator_name ?? '' );
            }
            $add( $event->created_at ?? '', $event_labels[ $type ], $detail );
        }

        foreach ( $classifications as $row ) {
            $label = (string) ( $row->status ?? '' );
            if ( $label === '' ) {
                $label = (string) ( $row->quality ?? '' );
            }
            $add( $row->classified_at ?? '', $label !== '' ? $label : 'Classified', (string) ( $row->operator_name ?? '' ) );
        }

        foreach ( $contact_log as $row ) {
            $add( $row->contacted_at ?? '', (string) ( $row->outcome ?? 'Contact' ), (string) ( $row->channel ?? '' ) );
        }

        $matched = $wpdb->get_row( $wpdb->prepare(
            "SELECT matched_at, project_slug FROM {$wpdb->prefix}es_customers WHERE matched_lead_id = %d ORDER BY matched_at DESC, id DESC LIMIT 1",
            absint( $lead->id ?? 0 )
        ) );
        if ( $matched && ! empty( $matched->matched_at ) ) {
            $add( $matched->matched_at, 'Customer matched', (string) ( $matched->project_slug ?? '' ) );
        }

        usort( $items, static function ( $a, $b ) {
            return strcmp( (string) $a['date'], (string) $b['date'] );
        } );

        $out = array();
        $seen = array();
        foreach ( $items as $item ) {
            $key = substr( (string) $item['date'], 0, 16 ) . '|' . $item['label'] . '|' . $item['detail'];
            if ( isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;
            $out[] = $item;
        }
        return $out;
    }

    private static function column_exists( string $table, string $column ): bool {
        global $wpdb;
        static $cache = array();
        $table = sanitize_key( $table );
        $key = $table . '.' . $column;
        if ( ! array_key_exists( $key, $cache ) ) {
            $cache[ $key ] = in_array( $column, (array) $wpdb->get_col( "DESC {$wpdb->prefix}{$table}", 0 ), true );
        }
        return $cache[ $key ];
    }

    // -------------------------------------------------------------------------
    // Stats bar (shown at top of portal)
    // -------------------------------------------------------------------------

    public static function get_stats() {
        global $wpdb;
        $t = $wpdb->prefix . 'es_leads';

	        return array(
	            'total'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ),
		            'pending'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE feedback_completed = 0" ),
		            'escalated'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE feedback_status = 'escalated' AND feedback_completed = 0" ),
		            'stale_7d'   => self::count_stale_7d(),
		            'new_24h'    => self::count_new_24h(),
		            'new'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE status = 'NEW'" ),
		            'recurrent'  => self::count_recurrent(),
		            'customers'  => self::count_customers(),
		            'waiting_24h'=> self::count_waiting_hours(24),
		            'waiting_72h'=> self::count_waiting_hours(72),
		            'unreached'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE contact_attempts = 0 AND feedback_completed = 0" ),
		            'hot'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE quality = 'HOT'" ),
		            'qualified'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE quality = 'QUALIFIED'" ),
		            'recontact_no_action' => self::count_recontact_without_action(),
	            'assigned_no_workspace'  => self::count_assigned_without_workspace(),
	            'workspace_no_management' => self::count_workspace_without_management(),
	            'converted'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE status IN ('DEED_SIGNED','DELIVERED')" ),
	        );
	    }

	    private static function terminal_statuses() {
	        if ( class_exists( 'Estandar_Leads_Compliance' ) && defined( 'Estandar_Leads_Compliance::FEEDBACK_TERMINAL_STATUSES' ) ) {
	            return Estandar_Leads_Compliance::FEEDBACK_TERMINAL_STATUSES;
	        }
	        return array( 'NO_RESPONSE', 'UNREACHABLE', 'QUALIFIED', 'MEETING_BOOKED', 'PROPOSAL_SENT', 'NEGOTIATION', 'CONVERTED', 'LOST', 'INVALID', 'VISIT_SCHEDULED', 'VISITED', 'BANK_EVALUATION', 'RESERVED', 'DEED_SIGNED', 'DELIVERED' );
	    }

		    /** Operator management actions (gestión) — used by buckets + ficha. */
		    public static function management_event_types(): array {
		        return array( 'status_changed', 'status_overridden', 'quality_changed', 'feedback_status_changed', 'commercial_stage_changed', 'contact_logged' );
		    }

		    /** Prospect-side observable interactions — used by ficha. */
		    public static function interaction_event_types(): array {
		        return array( 'autoresponse_link_click', 'autoresponse_email_open_signal', 'autoresponse_engaged', 'whatsapp_clicked', 'email_clicked', 'call_clicked' );
		    }

		    // OBSERVABILITY_DELTA buckets. COUNT is order-independent; the list views
		    // order by created_at (oldest first) as a monotonic proxy for business
		    // elapsed time (exact business-time sort would require PHP post-sort).
		    private static function count_assigned_without_workspace() {
		        global $wpdb;
		        $leads = $wpdb->prefix . 'es_leads';
		        $events = $wpdb->prefix . 'es_lead_events';
		        $terminal = self::terminal_statuses();
		        $placeholders = implode( ',', array_fill( 0, count( $terminal ), '%s' ) );
		        $sql = "SELECT COUNT(*) FROM {$leads} l
		            WHERE l.assigned_operator_id IS NOT NULL AND l.assigned_operator_id != 0
		              AND l.status NOT IN ({$placeholders})
		              AND NOT EXISTS (SELECT 1 FROM {$events} e WHERE e.lead_id = l.id AND e.event_type = 'lead_list_opened')";
		        return (int) $wpdb->get_var( $wpdb->prepare( $sql, $terminal ) );
		    }

		    private static function count_workspace_without_management() {
		        global $wpdb;
		        $leads = $wpdb->prefix . 'es_leads';
		        $events = $wpdb->prefix . 'es_lead_events';
		        $terminal = self::terminal_statuses();
		        $mgmt = self::management_event_types();
		        $t_ph = implode( ',', array_fill( 0, count( $terminal ), '%s' ) );
		        $m_ph = implode( ',', array_fill( 0, count( $mgmt ), '%s' ) );
		        $sql = "SELECT COUNT(*) FROM {$leads} l
		            WHERE l.status NOT IN ({$t_ph})
		              AND EXISTS (SELECT 1 FROM {$events} e WHERE e.lead_id = l.id AND e.event_type = 'lead_list_opened')
		              AND NOT EXISTS (SELECT 1 FROM {$events} e2 WHERE e2.lead_id = l.id AND e2.event_type IN ({$m_ph}))";
		        return (int) $wpdb->get_var( $wpdb->prepare( $sql, array_merge( $terminal, $mgmt ) ) );
		    }

		    private static function count_recontact_without_action() {
		        global $wpdb;
		        $leads = $wpdb->prefix . 'es_leads';
	        $subs  = $wpdb->prefix . 'es_lead_submissions';
	        $terminal = self::terminal_statuses();
	        $placeholders = implode( ',', array_fill( 0, count( $terminal ), '%s' ) );
	        $sql = "SELECT COUNT(*) FROM {$leads} l
	            WHERE COALESCE(l.feedback_status, '') != 'received'
	              AND l.status NOT IN ({$placeholders})
	              AND (SELECT COUNT(*) FROM {$subs} s WHERE s.lead_id = l.id) >= 2";
		        return (int) $wpdb->get_var( $wpdb->prepare( $sql, $terminal ) );
		    }

		    private static function count_stale_7d() {
		        global $wpdb;
		        $leads = $wpdb->prefix . 'es_leads';
		        $cutoff = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
		        return (int) $wpdb->get_var( $wpdb->prepare(
		            "SELECT COUNT(*) FROM {$leads}
		             WHERE status = 'NEW' AND feedback_completed = 0 AND last_seen_at < %s",
		            $cutoff
		        ) );
		    }

		    private static function count_new_24h() {
		        global $wpdb;
		        $leads = $wpdb->prefix . 'es_leads';
		        $cutoff = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		        return (int) $wpdb->get_var( $wpdb->prepare(
		            "SELECT COUNT(*) FROM {$leads}
		             WHERE status = 'NEW' AND feedback_completed = 0 AND created_at >= %s",
		            $cutoff
		        ) );
		    }

		    private static function count_recurrent() {
		        global $wpdb;
		        $leads = $wpdb->prefix . 'es_leads';
		        $subs  = $wpdb->prefix . 'es_lead_submissions';
		        return (int) $wpdb->get_var(
		            "SELECT COUNT(*) FROM {$leads} l
		             WHERE (SELECT COUNT(*) FROM {$subs} s WHERE s.lead_id = l.id) >= 2"
		        );
		    }

		    private static function count_customers() {
		        global $wpdb;
		        $table = $wpdb->prefix . 'es_customers';
		        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE matched_lead_id IS NOT NULL" );
		    }

		    private static function count_waiting_hours( int $hours ) {
		        global $wpdb;
		        $leads = $wpdb->prefix . 'es_leads';
		        $cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 1, $hours ) * HOUR_IN_SECONDS );
		        return (int) $wpdb->get_var( $wpdb->prepare(
		            "SELECT COUNT(*) FROM {$leads}
		             WHERE contact_attempts > 0
		               AND feedback_completed = 0
		               AND COALESCE(first_contact_at, last_seen_at) <= %s",
		            $cutoff
		        ) );
		    }

    public static function get_autoresponse_observability( array $lead_ids ): array {
        global $wpdb;

        $lead_ids = array_values( array_unique( array_filter( array_map( 'absint', $lead_ids ) ) ) );
        if ( empty( $lead_ids ) ) {
            return array();
        }

        $placeholders = implode( ',', array_fill( 0, count( $lead_ids ), '%d' ) );
        $types = self::observable_signal_types();
        $type_placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
        $sql = "SELECT lead_id, submission_id, event_type, reason, metadata, created_at
                FROM {$wpdb->prefix}es_lead_events
                WHERE lead_id IN ({$placeholders})
                  AND event_type IN ({$type_placeholders})
                ORDER BY created_at DESC, id DESC";
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $lead_ids, $types ) ) );

        $out = array();
        foreach ( $lead_ids as $lead_id ) {
            $out[ $lead_id ] = self::empty_autoresponse_observability();
        }

        foreach ( $rows ?: array() as $row ) {
            $lead_id = absint( $row->lead_id ?? 0 );
            if ( ! isset( $out[ $lead_id ] ) ) {
                continue;
            }

            $type = (string) ( $row->event_type ?? '' );
            $meta = json_decode( (string) ( $row->metadata ?? '' ), true );
            $meta = is_array( $meta ) ? $meta : array();
            $date = (string) ( $row->created_at ?? '' );

            if ( $type === 'autoresponse_engaged' ) {
                self::set_latest_signal( $out[ $lead_id ], 'engaged', $date );
            }

            $signal = self::normalize_observable_signal( $type, $meta );
            if ( $signal !== '' ) {
                self::set_latest_signal( $out[ $lead_id ], $signal, $date );
                $out[ $lead_id ]['count']++;
                if ( $date > (string) $out[ $lead_id ]['last'] ) {
                    $out[ $lead_id ]['last'] = $date;
                }
            }
        }

        return $out;
    }

    public static function get_autoresponse_stats( $days = 7 ): array {
        global $wpdb;

        $since = gmdate( 'Y-m-d H:i:s', time() - max( 1, absint( $days ) ) * DAY_IN_SECONDS );
        $types = array(
            'autoresponse_email_sent',
            'autoresponse_email_failed',
            'autoresponse_skipped',
            'autoresponse_link_click',
            'whatsapp_click',
            'autoresponse_engaged',
        );
        $placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT lead_id, submission_id, event_type, reason, metadata
             FROM {$wpdb->prefix}es_lead_events
             WHERE created_at >= %s AND event_type IN ({$placeholders})",
            array_merge( array( $since ), $types )
        ) );

        $stats = array(
            'sent' => 0,
            'pdf_clicks' => 0,
            'whatsapp_clicks' => 0,
            'engaged_leads' => 0,
            'operational_failures' => 0,
            'throttled' => 0,
            'skipped_no_email' => 0,
        );
        $engaged_leads = array();
        $explicit_whatsapp = array();

        foreach ( $rows ?: array() as $row ) {
            if ( (string) ( $row->event_type ?? '' ) === 'whatsapp_click' ) {
                $explicit_whatsapp[ absint( $row->lead_id ?? 0 ) . ':' . absint( $row->submission_id ?? 0 ) ] = true;
            }
        }

        foreach ( $rows ?: array() as $row ) {
            $type = (string) ( $row->event_type ?? '' );
            $meta = json_decode( (string) ( $row->metadata ?? '' ), true );
            $meta = is_array( $meta ) ? $meta : array();
            $reason = sanitize_key( (string) ( $row->reason ?: ( $meta['reason'] ?? '' ) ) );

            if ( $type === 'autoresponse_email_sent' ) {
                $stats['sent']++;
            } elseif ( $type === 'autoresponse_skipped' ) {
                $stats['skipped_no_email']++;
            } elseif ( $type === 'autoresponse_email_failed' ) {
                if ( $reason === 'throttled' ) {
                    $stats['throttled']++;
                } elseif ( $reason === 'no_email' ) {
                    // SEMANTIC_STATUS_V1 (Lead #478): filas históricas
                    // failed/no_email cuentan como omitidas, nunca como fallo
                    // operacional ("No incluye leads sin email" ahora es cierto).
                    $stats['skipped_no_email']++;
                } elseif ( $reason !== 'suspect' ) {
                    $stats['operational_failures']++;
                }
            } elseif ( $type === 'autoresponse_link_click' && sanitize_key( (string) ( $meta['resource_type'] ?? '' ) ) === 'pdf' ) {
                $stats['pdf_clicks']++;
            } elseif ( $type === 'whatsapp_click' ) {
                $stats['whatsapp_clicks']++;
            } elseif (
                $type === 'autoresponse_engaged'
                && sanitize_key( (string) ( $meta['channel'] ?? '' ) ) === 'whatsapp'
                && empty( $explicit_whatsapp[ absint( $row->lead_id ?? 0 ) . ':' . absint( $row->submission_id ?? 0 ) ] )
            ) {
                $stats['whatsapp_clicks']++;
            }

            if ( $type === 'autoresponse_engaged' ) {
                $engaged_leads[ absint( $row->lead_id ?? 0 ) ] = true;
            }
        }

        unset( $engaged_leads[0] );
        $stats['engaged_leads'] = count( $engaged_leads );
        return $stats;
    }

    private static function empty_autoresponse_observability(): array {
        return array(
            'sent' => '',
            'pdf' => '',
            'landing' => '',
            'whatsapp' => '',
            'engaged' => '',
            'open' => '',
            'recontact' => '',
            'classified' => '',
            'assigned' => '',
            'notified' => '',
            'count' => 0,
            'last' => '',
        );
    }

    public static function get_interaction_timeline( $lead_id ): array {
        global $wpdb;

        $lead_id = absint( $lead_id );
        if ( $lead_id === 0 ) {
            return array();
        }

        $types = self::observable_signal_types();
        $placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT event_type, metadata, created_at
             FROM {$wpdb->prefix}es_lead_events
             WHERE lead_id = %d AND event_type IN ({$placeholders})
             ORDER BY created_at ASC, id ASC",
            array_merge( array( $lead_id ), $types )
        ) );

        $timeline = array();
        foreach ( $rows ?: array() as $row ) {
            $meta = json_decode( (string) ( $row->metadata ?? '' ), true );
            $meta = is_array( $meta ) ? $meta : array();
            $label = self::observable_signal_label( (string) ( $row->event_type ?? '' ), $meta );
            if ( $label === '' ) {
                continue;
            }
            $timeline[] = array(
                'label' => $label,
                'date'  => (string) ( $row->created_at ?? '' ),
            );
        }

        return $timeline;
    }

    public static function get_last_commercial_actions( array $lead_ids ): array {
        global $wpdb;

        $lead_ids = array_values( array_unique( array_filter( array_map( 'absint', $lead_ids ) ) ) );
        if ( empty( $lead_ids ) ) {
            return array();
        }

        $out = array();
        foreach ( $lead_ids as $lead_id ) {
            $out[ $lead_id ] = array( 'label' => 'Nunca contactado', 'date' => '' );
        }

        $placeholders = implode( ',', array_fill( 0, count( $lead_ids ), '%d' ) );
        $event_types = array( 'whatsapp_clicked', 'email_clicked', 'call_clicked', 'status_changed', 'quality_changed' );
        $event_placeholders = implode( ',', array_fill( 0, count( $event_types ), '%s' ) );
        $events = $wpdb->get_results( $wpdb->prepare(
            "SELECT lead_id, event_type, created_at
             FROM {$wpdb->prefix}es_lead_events
             WHERE lead_id IN ({$placeholders}) AND event_type IN ({$event_placeholders})
             ORDER BY created_at DESC, id DESC",
            array_merge( $lead_ids, $event_types )
        ) );

        foreach ( $events ?: array() as $event ) {
            $lead_id = absint( $event->lead_id ?? 0 );
            $date = (string) ( $event->created_at ?? '' );
            if ( ! isset( $out[ $lead_id ] ) || (string) $out[ $lead_id ]['date'] > $date ) {
                continue;
            }
            $out[ $lead_id ] = array(
                'label' => self::commercial_action_label( (string) ( $event->event_type ?? '' ) ),
                'date'  => $date,
            );
        }

        $contacts = $wpdb->get_results( $wpdb->prepare(
            "SELECT lead_id, channel, contacted_at
             FROM {$wpdb->prefix}es_lead_contacts
             WHERE lead_id IN ({$placeholders})
             ORDER BY contacted_at DESC, id DESC",
            $lead_ids
        ) );

        foreach ( $contacts ?: array() as $contact ) {
            $lead_id = absint( $contact->lead_id ?? 0 );
            $date = (string) ( $contact->contacted_at ?? '' );
            if ( ! isset( $out[ $lead_id ] ) || (string) $out[ $lead_id ]['date'] > $date ) {
                continue;
            }
            $channel = sanitize_key( (string) ( $contact->channel ?? '' ) );
            $out[ $lead_id ] = array(
                'label' => $channel === 'call' ? 'Llamado registrado' : 'Contacto registrado',
                'date'  => $date,
            );
        }

        return $out;
    }

    private static function commercial_action_label( string $event_type ): string {
        $labels = array(
            'whatsapp_clicked' => 'WhatsApp enviado',
            'email_clicked'    => 'Email enviado',
            'call_clicked'     => 'Llamada iniciada',
            'status_changed'   => 'Clasificado',
            'quality_changed'  => 'Clasificado',
        );
        return $labels[ $event_type ] ?? 'Gestión registrada';
    }

    private static function observable_signal_types(): array {
        return array(
            'autoresponse_email_sent',
            'autoresponse_email_open_signal',
            'autoresponse_link_click',
            'autoresponse_engaged',
            'lead_whatsapp_opened',
            'whatsapp_click',
            'whatsapp_clicked',
            'lead_recontacted',
            'operator_assigned',
            'operator_notified',
            'operator_email_opened',
            'operator_clicked_call',
            'operator_clicked_whatsapp',
            'operator_clicked_email',
            'operator_clicked_lead',
            'operator_clicked_maps',
            'operator_feedback_started',
            'operator_feedback_completed',
            'status_changed',
            'quality_changed',
        );
    }

    private static function normalize_observable_signal( string $event_type, array $meta ): string {
        if ( $event_type === 'autoresponse_link_click' ) {
            $resource = sanitize_key( (string) ( $meta['resource_type'] ?? '' ) );
            return in_array( $resource, array( 'pdf', 'landing' ), true ) ? $resource : '';
        }
        if (
            $event_type === 'whatsapp_click'
            || $event_type === 'whatsapp_clicked'
            || $event_type === 'lead_whatsapp_opened'
            || ( $event_type === 'autoresponse_engaged' && sanitize_key( (string) ( $meta['channel'] ?? '' ) ) === 'whatsapp' )
        ) {
            return 'whatsapp';
        }
        $map = array(
            'autoresponse_email_sent' => 'sent',
            'autoresponse_email_open_signal' => 'open',
            'lead_recontacted' => 'recontact',
            'status_changed' => 'classified',
            'quality_changed' => 'classified',
            'operator_assigned' => 'assigned',
            'operator_notified' => 'notified',
            'operator_email_opened' => 'operator_open',
            'operator_clicked_call' => 'operator_call',
            'operator_clicked_whatsapp' => 'operator_whatsapp',
            'operator_clicked_email' => 'operator_email',
            'operator_clicked_lead' => 'operator_lead',
            'operator_clicked_maps' => 'operator_maps',
            'operator_feedback_started' => 'operator_feedback_started',
            'operator_feedback_completed' => 'operator_feedback_completed',
        );
        return $map[ $event_type ] ?? '';
    }

    private static function observable_signal_label( string $event_type, array $meta ): string {
        if ( $event_type === 'autoresponse_link_click' ) {
            $resource = sanitize_key( (string) ( $meta['resource_type'] ?? '' ) );
            if ( $resource === 'pdf' ) {
                return 'PDF descargado';
            }
            if ( $resource === 'landing' ) {
                return 'Ficha revisada';
            }
            return '';
        }
        if (
            $event_type === 'whatsapp_click'
            || $event_type === 'whatsapp_clicked'
            || $event_type === 'lead_whatsapp_opened'
            || ( $event_type === 'autoresponse_engaged' && sanitize_key( (string) ( $meta['channel'] ?? '' ) ) === 'whatsapp' )
        ) {
            return 'WhatsApp abierto';
        }
        $map = array(
            'autoresponse_email_sent' => 'Autorespuesta enviada',
            'autoresponse_email_open_signal' => 'Autorespuesta abierta',
            'lead_recontacted' => 'Recontactó',
            'status_changed' => 'Clasificado',
            'quality_changed' => 'Clasificado',
            'operator_assigned' => 'Operador asignado',
            'operator_notified' => 'Operador notificado',
            'operator_email_opened' => 'Email operador abierto',
            'operator_clicked_call' => 'Operador abrió llamada',
            'operator_clicked_whatsapp' => 'Operador abrió WhatsApp',
            'operator_clicked_email' => 'Operador abrió email',
            'operator_clicked_lead' => 'Operador abrió lead',
            'operator_clicked_maps' => 'Operador abrió mapa',
            'operator_feedback_started' => 'Feedback iniciado',
            'operator_feedback_completed' => 'Feedback completado',
        );
        return $map[ $event_type ] ?? '';
    }

    private static function set_latest_signal( array &$signals, string $key, string $date ): void {
        if ( isset( $signals[ $key ] ) && ( $signals[ $key ] === '' || $date > $signals[ $key ] ) ) {
            $signals[ $key ] = $date;
        }
    }

    public static function log_portal_event( $lead_id, $event_type, array $metadata = array(), $operator_id = 0 ): int {
        $lead_id = absint( $lead_id );
        if ( $lead_id === 0 ) {
            return 0;
        }

        $event_type = sanitize_key( $event_type );
        $operator_id = absint( $operator_id );
        $dedupe_key = 'es_portal_evt_' . md5( $lead_id . '|' . $event_type . '|' . $operator_id . '|' . get_current_user_id() );
        if ( get_transient( $dedupe_key ) ) {
            return 0;
        }
        set_transient( $dedupe_key, 1, 5 * MINUTE_IN_SECONDS );

        $metadata = array_merge( array(
            'source' => 'operator_portal',
            'wp_user_id' => get_current_user_id(),
            'device' => wp_is_mobile() ? 'mobile' : 'desktop',
        ), $metadata );

        return Estandar_Leads_Repository::insert_event( $lead_id, $event_type, array(
            'operator_id' => $operator_id ?: null,
            'metadata' => $metadata,
            'ip_address' => sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) ),
            'user_agent' => sanitize_textarea_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ),
        ) );
    }

    public static function log_list_open( array $lead_ids, array $filters = array() ): void {
        $operator_id = absint( $filters['operator_id'] ?? 0 );
        $metadata = array(
            'project' => sanitize_key( $filters['project'] ?? '' ),
            'view' => sanitize_key( $filters['view'] ?? '' ),
        );

        foreach ( array_slice( array_filter( array_map( 'absint', $lead_ids ) ), 0, 50 ) as $lead_id ) {
            self::log_portal_event( $lead_id, 'lead_list_opened', $metadata, $operator_id );
        }
    }

	    // -------------------------------------------------------------------------
	    // WHERE builder (private)
	    // -------------------------------------------------------------------------

		    private static function build_order( array $filters, $table, $submissions ) {
		        if ( $filters['view'] === self::VIEW_RECONTACT_NO_ACTION ) {
		            return array( 'submission_count DESC, last_submitted_at DESC', array() );
		        }

		        // OBSERVABILITY_DELTA worklists: oldest assignment/creation first =
		        // monotonic proxy for most business time elapsed without action.
		        if ( $filters['view'] === self::VIEW_ASSIGNED_NO_WS || $filters['view'] === self::VIEW_WS_NO_MGMT ) {
		            return array( "{$table}.created_at ASC", array() );
		        }

		        if ( $filters['view'] !== self::VIEW_WORK_NOW ) {
		            return array( "{$table}.last_seen_at DESC", array() );
		        }

		        $terminal = self::terminal_statuses();
		        $placeholders = implode( ',', array_fill( 0, count( $terminal ), '%s' ) );
		        $recontact_sql = "COALESCE({$table}.feedback_status, '') != 'received'
		            AND {$table}.status NOT IN ({$placeholders})
		            AND (SELECT COUNT(*) FROM {$submissions} s WHERE s.lead_id = {$table}.id) >= 2";
		        $stale_cutoff = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
		        $new_cutoff = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		        $order = "CASE
		                WHEN ({$recontact_sql}) THEN 1
		                WHEN ({$table}.feedback_status = 'escalated' AND {$table}.feedback_completed = 0) THEN 2
		                WHEN ({$table}.status = 'NEW' AND {$table}.feedback_completed = 0 AND {$table}.last_seen_at < %s) THEN 3
		                WHEN ({$table}.status = 'NEW' AND {$table}.feedback_completed = 0 AND {$table}.created_at >= %s) THEN 4
		                ELSE 9
		            END ASC,
		            submission_count DESC,
		            {$table}.last_seen_at DESC";
		        return array( $order, array_merge( $terminal, array( $stale_cutoff, $new_cutoff ) ) );
		    }

	    private static function build_where( array $f, $table ) {
        $wheres = array( '1=1' );
        $vals   = array();

        // View-level filters
	        switch ( $f['view'] ) {
	            case self::VIEW_PENDING:
	                $wheres[] = "{$table}.feedback_completed = 0";
	                break;
	            case self::VIEW_ESCALATED:
	                $wheres[] = "{$table}.feedback_status = 'escalated'";
	                $wheres[] = "{$table}.feedback_completed = 0";
	                break;
            case self::VIEW_UNREACHED:
                $wheres[] = "{$table}.contact_attempts = 0";
                $wheres[] = "{$table}.feedback_completed = 0";
                break;
	            case self::VIEW_HOT:
	                $wheres[] = "{$table}.quality = 'HOT'";
	                break;
		            case self::VIEW_RECONTACT_NO_ACTION:
		                global $wpdb;
		                $terminal = self::terminal_statuses();
	                $placeholders = implode( ',', array_fill( 0, count( $terminal ), '%s' ) );
	                $wheres[] = "COALESCE({$table}.feedback_status, '') != 'received'";
	                $wheres[] = "{$table}.status NOT IN ({$placeholders})";
	                foreach ( $terminal as $status ) {
	                    $vals[] = $status;
	                }
		                $wheres[] = "(SELECT COUNT(*) FROM {$wpdb->prefix}es_lead_submissions s WHERE s.lead_id = {$table}.id) >= 2";
		                break;
		            case self::VIEW_ASSIGNED_NO_WS:
		                global $wpdb;
		                $terminal = self::terminal_statuses();
		                $placeholders = implode( ',', array_fill( 0, count( $terminal ), '%s' ) );
		                $wheres[] = "({$table}.assigned_operator_id IS NOT NULL AND {$table}.assigned_operator_id != 0)";
		                $wheres[] = "{$table}.status NOT IN ({$placeholders})";
		                foreach ( $terminal as $status ) {
		                    $vals[] = $status;
		                }
		                $wheres[] = "NOT EXISTS (SELECT 1 FROM {$wpdb->prefix}es_lead_events e WHERE e.lead_id = {$table}.id AND e.event_type = 'lead_list_opened')";
		                break;
		            case self::VIEW_WS_NO_MGMT:
		                global $wpdb;
		                $terminal = self::terminal_statuses();
		                $mgmt = self::management_event_types();
		                $t_ph = implode( ',', array_fill( 0, count( $terminal ), '%s' ) );
		                $m_ph = implode( ',', array_fill( 0, count( $mgmt ), '%s' ) );
		                $wheres[] = "{$table}.status NOT IN ({$t_ph})";
		                foreach ( $terminal as $status ) {
		                    $vals[] = $status;
		                }
		                $wheres[] = "EXISTS (SELECT 1 FROM {$wpdb->prefix}es_lead_events e WHERE e.lead_id = {$table}.id AND e.event_type = 'lead_list_opened')";
		                $wheres[] = "NOT EXISTS (SELECT 1 FROM {$wpdb->prefix}es_lead_events e2 WHERE e2.lead_id = {$table}.id AND e2.event_type IN ({$m_ph}))";
		                foreach ( $mgmt as $mev ) {
		                    $vals[] = $mev;
		                }
		                break;
		            case self::VIEW_WORK_NOW:
		                global $wpdb;
		                $terminal = self::terminal_statuses();
		                $placeholders = implode( ',', array_fill( 0, count( $terminal ), '%s' ) );
		                $stale_cutoff = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
		                $new_cutoff = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		                $wheres[] = "(
		                    (
		                        COALESCE({$table}.feedback_status, '') != 'received'
		                        AND {$table}.status NOT IN ({$placeholders})
		                        AND (SELECT COUNT(*) FROM {$wpdb->prefix}es_lead_submissions s WHERE s.lead_id = {$table}.id) >= 2
		                    )
		                    OR ({$table}.feedback_status = 'escalated' AND {$table}.feedback_completed = 0)
		                    OR ({$table}.status = 'NEW' AND {$table}.feedback_completed = 0 AND {$table}.last_seen_at < %s)
		                    OR ({$table}.status = 'NEW' AND {$table}.feedback_completed = 0 AND {$table}.created_at >= %s)
		                )";
		                foreach ( $terminal as $status ) {
		                    $vals[] = $status;
		                }
		                $vals[] = $stale_cutoff;
		                $vals[] = $new_cutoff;
		                break;
		            case self::VIEW_HISTORY:
		                $wheres[] = "{$table}.feedback_completed = 1";
			                break;
	        }

	        $now = time();
	        switch ( $f['issue'] ?? '' ) {
	            case self::ISSUE_UNASSIGNED_24H:
	                $wheres[] = "({$table}.assigned_operator_id IS NULL OR {$table}.assigned_operator_id = 0)";
	                $wheres[] = "{$table}.status = 'NEW'";
	                $wheres[] = "{$table}.created_at >= %s";
	                $vals[]   = gmdate( 'Y-m-d H:i:s', $now - DAY_IN_SECONDS );
	                break;
	            case self::ISSUE_UNASSIGNED_72H:
	                $wheres[] = "({$table}.assigned_operator_id IS NULL OR {$table}.assigned_operator_id = 0)";
	                $wheres[] = "{$table}.status = 'NEW'";
	                $wheres[] = "{$table}.created_at < %s";
	                $vals[]   = gmdate( 'Y-m-d H:i:s', $now - DAY_IN_SECONDS );
	                break;
		            case self::ISSUE_STALE_7D:
		                $wheres[] = "{$table}.status = 'NEW'";
		                $wheres[] = "{$table}.feedback_completed = 0";
		                $wheres[] = "{$table}.last_seen_at < %s";
		                $vals[]   = gmdate( 'Y-m-d H:i:s', $now - 7 * DAY_IN_SECONDS );
		                break;
		            case self::ISSUE_NEW_24H:
		                $wheres[] = "{$table}.status = 'NEW'";
		                $wheres[] = "{$table}.feedback_completed = 0";
		                $wheres[] = "{$table}.created_at >= %s";
		                $vals[]   = gmdate( 'Y-m-d H:i:s', $now - DAY_IN_SECONDS );
		                break;
	            case self::ISSUE_NO_SOURCE:
	                $wheres[] = "({$table}.first_session_id IS NULL OR {$table}.first_session_id = '')";
	                break;
	            case self::ISSUE_CONVERTED_NO_ATTR:
	                $wheres[] = "{$table}.status IN ('DEED_SIGNED','DELIVERED')";
	                $wheres[] = "({$table}.first_session_id IS NULL OR {$table}.first_session_id = '')";
	                break;
	        }

	        // URL param filters
        if ( $f['project'] !== '' ) {
            $wheres[] = "{$table}.project_slug = %s";
            $vals[]   = $f['project'];
        }
        if ( $f['quality'] !== '' && in_array( $f['quality'], Estandar_Leads_Email::qualities(), true ) ) {
            $wheres[] = "{$table}.quality = %s";
            $vals[]   = $f['quality'];
        }
        if ( $f['status'] !== '' && in_array( $f['status'], Estandar_Leads_Normalizer::statuses(), true ) ) {
            $wheres[] = "{$table}.status = %s";
            $vals[]   = $f['status'];
        }
        if ( $f['operator_id'] > 0 ) {
            // INTEREST_ASSIGNMENT_V1 (Fase 1, D2): "mis leads" incluye tanto el
            // operador del lead (assigned_operator_id, lead-level, fallback/legacy)
            // como cualquier interés (submission) cuyo evento operator_assigned
            // más reciente apunte a este operador. Additivo — no se modifica
            // assigned_operator_id.
            global $wpdb;
            $wheres[] = "(
                {$table}.assigned_operator_id = %d
                OR EXISTS (
                    SELECT 1 FROM {$wpdb->prefix}es_lead_events e
                    WHERE e.lead_id = {$table}.id
                      AND e.event_type = 'operator_assigned'
                      AND e.operator_id = %d
                )
            )";
            $vals[]   = $f['operator_id'];
            $vals[]   = $f['operator_id'];
        }
        if ( $f['source'] !== '' ) {
            $wheres[] = "{$table}.first_source_plugin = %s";
            $vals[]   = $f['source'];
        }
        if ( $f['date_from'] !== '' ) {
            $wheres[] = "DATE({$table}.first_seen_at) >= %s";
            $vals[]   = $f['date_from'];
        }
        if ( $f['date_to'] !== '' ) {
            $wheres[] = "DATE({$table}.first_seen_at) <= %s";
            $vals[]   = $f['date_to'];
        }
        if ( $f['search'] !== '' ) {
            global $wpdb;
            $term = $f['search'];
            // Búsqueda por Lead ID numérico exacto (ej. "224", "#224").
            $numeric_id = (int) ltrim( $term, '#' );
            if ( $numeric_id > 0 && preg_match( '/^#?\d+$/', trim( $term ) ) ) {
                $wheres[] = "{$table}.id = %d";
                $vals[]   = $numeric_id;
            } else {
                $like     = '%' . $wpdb->esc_like( $term ) . '%';
                $wheres[] = "({$table}.name LIKE %s OR {$table}.email LIKE %s OR {$table}.phone LIKE %s)";
                $vals[]   = $like;
                $vals[]   = $like;
                $vals[]   = $like;
            }
        }

        return array( implode( ' AND ', $wheres ), $vals );
    }

    // -------------------------------------------------------------------------
    // Contact log helpers
    // -------------------------------------------------------------------------

    public static function log_contact( $lead_id, $operator_id, $channel, $outcome, $notes = '', array $metadata = array() ) {
        global $wpdb;

        $lead_id     = absint( $lead_id );
        $operator_id = absint( $operator_id );
        $now         = gmdate( 'Y-m-d H:i:s' );

        $wpdb->insert( $wpdb->prefix . 'es_lead_contacts', array(
            'lead_id'     => $lead_id,
            'operator_id' => $operator_id ?: null,
            'channel'     => sanitize_key( $channel ),
            'outcome'     => sanitize_text_field( $outcome ),
            'notes'       => sanitize_textarea_field( $notes ),
            'contacted_at' => $now,
        ) );

        // Increment attempt counter + set first_contact_at on first contact.
	        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->prefix}es_leads
             SET contact_attempts = contact_attempts + 1,
                 first_contact_at = COALESCE(first_contact_at, %s),
                 updated_at = %s
             WHERE id = %d",
	            $now, $now, $lead_id
	        ) );

        Estandar_Leads_Repository::insert_event( $lead_id, 'contact_logged', array(
            'operator_id' => $operator_id ?: null,
            'reason' => $notes,
            'metadata' => array_merge( array(
                'source' => 'operator_portal',
                'channel' => sanitize_key( $channel ),
                'outcome' => sanitize_text_field( $outcome ),
            ), $metadata ),
        ) );
	    }

    // -------------------------------------------------------------------------
    // Quality vocabulary (delegates to Email class)
    // -------------------------------------------------------------------------

    public static function quality_badge( $quality ) {
        $labels = Estandar_Leads_Email::quality_labels();
        $colors = Estandar_Leads_Email::quality_colors();
        if ( ! isset( $labels[ $quality ] ) ) {
            return '';
        }
        $color = $colors[ $quality ];
        return '<span style="display:inline-block;background:' . esc_attr( $color ) . ';color:#fff;'
             . 'font-size:11px;font-weight:600;padding:2px 8px;border-radius:99px;letter-spacing:.3px;">'
             . esc_html( $quality ) . '</span>';
    }
}
