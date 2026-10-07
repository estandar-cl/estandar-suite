<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Estandar_Leads_Operator_Workspace {

    const TOKEN_TTL_DAYS = 365;
    const WAITING_RESPONSE_HOURS = 24;

    public static function init(): void {
        add_action( 'template_redirect', array( __CLASS__, 'maybe_handle' ), 2 );
    }

    public static function maybe_handle(): void {
        $token = self::token_from_request();

        if ( $token === '' ) {
            return;
        }

        $operator = self::operator_for_token( $token );

        if ( ! $operator ) {
            self::render_message( 'Acceso no disponible', 'Este enlace no es valido, esta desactivado o expiro.' );
        }

        $projects = self::allowed_projects( $operator );
        if ( empty( $projects ) ) {
            self::render_message( 'Sin proyectos asignados', 'Este operador no tiene proyectos habilitados.' );
        }

        $action = sanitize_key( $_GET['ow_action'] ?? '' );
        if ( $action !== '' ) {
            self::handle_action( $operator, $projects, $action, $token );
        }

        self::render_workspace( $operator, $projects, $token );
    }

    /**
     * Minimal contract for other plugins (e.g. estandar-quotes) that need to validate an
     * operator's identity/assignment without duplicating the Leads token/authorization model.
     * Read-only; does not expose the token hash or grant anything by itself.
     */
    public static function operator_authorized_for_submission( int $operator_id, int $submission_id ): bool {
        global $wpdb;

        $operator_id = absint( $operator_id );
        $submission_id = absint( $submission_id );
        if ( $operator_id === 0 || $submission_id === 0 ) {
            return false;
        }

        $operator = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, project_slug, allowed_projects_json, active FROM {$wpdb->prefix}es_operators WHERE id = %d LIMIT 1",
            $operator_id
        ) );
        if ( ! $operator || (int) $operator->active !== 1 ) {
            return false;
        }

        $projects = self::allowed_projects( $operator );
        $submission = self::get_submission_workspace_row( $submission_id );

        return $submission ? self::can_access_submission( $operator_id, $projects, $submission ) : false;
    }

    public static function create_token( int $operator_id, int $ttl_days = self::TOKEN_TTL_DAYS ): string {
        global $wpdb;

        $operator_id = absint( $operator_id );
        if ( $operator_id === 0 ) {
            return '';
        }

        $token = bin2hex( random_bytes( 32 ) );
        $wpdb->update(
            $wpdb->prefix . 'es_operators',
            array(
                'access_token_hash' => self::hash_token( $token ),
                'access_token_expires_at' => gmdate( 'Y-m-d H:i:s', time() + max( 1, $ttl_days ) * DAY_IN_SECONDS ),
                'updated_at' => gmdate( 'Y-m-d H:i:s' ),
            ),
            array( 'id' => $operator_id ),
            array( '%s', '%s', '%s' ),
            array( '%d' )
        );

        if ( is_admin() && get_current_user_id() ) {
            set_transient( 'estandar_leads_workspace_token_' . get_current_user_id() . '_' . $operator_id, $token, 15 * MINUTE_IN_SECONDS );
        }

        return $token;
    }

    public static function ensure_token( int $operator_id ): string {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT access_token_hash, access_token_expires_at FROM {$wpdb->prefix}es_operators WHERE id = %d LIMIT 1",
            $operator_id
        ) );

        if ( $row && ! empty( $row->access_token_hash ) && ! empty( $row->access_token_expires_at ) && $row->access_token_expires_at > gmdate( 'Y-m-d H:i:s' ) ) {
            return '';
        }

        return self::create_token( $operator_id );
    }

    public static function workspace_url( string $token ): string {
        return home_url( '/workspace/' . rawurlencode( $token ) );
    }

    public static function hash_token( string $token ): string {
        return hash_hmac( 'sha256', $token, Estandar_Leads_Schema::secret() );
    }

    public static function sanitize_projects( $value ): array {
        $items = is_array( $value ) ? $value : explode( ',', (string) $value );
        $projects = array();
        foreach ( $items as $project ) {
            $slug = Estandar_Leads_Projects::normalize_slug( $project );
            if ( $slug !== '' ) {
                $projects[] = $slug;
            }
        }
        return array_values( array_unique( $projects ) );
    }

    private static function token_from_request(): string {
        $raw_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
        $path = trim( (string) wp_parse_url( $raw_uri, PHP_URL_PATH ), '/' );
        $site_path = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );

        if ( $site_path !== '' && strpos( $path, $site_path . '/' ) === 0 ) {
            $path = substr( $path, strlen( $site_path ) + 1 );
        }

        if ( preg_match( '#^(workspace|operador)/([^/]+)$#', $path, $matches ) ) {
            return sanitize_text_field( rawurldecode( $matches[2] ) );
        }

        if ( ! empty( $_GET['estandar_operator_workspace'] ) ) {
            return sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) );
        }

        return '';
    }

    private static function operator_for_token( string $token ) {
        global $wpdb;

        if ( strlen( $token ) < 32 ) {
            return null;
        }

        $token_hash = self::hash_token( $token );
        $now_gmt = gmdate( 'Y-m-d H:i:s' );

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT id, operator_uuid, name, email, project_slug, allowed_projects_json, active, access_token_expires_at
             FROM {$wpdb->prefix}es_operators
             WHERE access_token_hash = %s
               AND active = 1
               AND access_token_expires_at > %s
             LIMIT 1",
            $token_hash,
            $now_gmt
        ) );
    }

    private static function allowed_projects( $operator ): array {
        $decoded = json_decode( (string) ( $operator->allowed_projects_json ?? '' ), true );
        $projects = self::sanitize_projects( is_array( $decoded ) ? $decoded : array() );
        if ( empty( $projects ) && ! empty( $operator->project_slug ) ) {
            $projects = self::sanitize_projects( array( $operator->project_slug ) );
        }
        return $projects;
    }

    private static function handle_action( $operator, array $projects, string $action, string $token ): void {
        global $wpdb;

        $submission_id = absint( $_GET['submission_id'] ?? 0 );
        $lead_id = absint( $_GET['lead_id'] ?? 0 );
        $lead = $submission_id ? self::get_submission_workspace_row( $submission_id ) : ( $lead_id ? self::get_lead( $lead_id ) : null );

        if ( ! $lead || ! self::can_access_submission( absint( $operator->id ?? 0 ), $projects, $lead ) ) {
            wp_safe_redirect( self::workspace_url( $token ) );
            exit;
        }
        $lead_id = absint( $lead->id ?? 0 );
        $submission_id = absint( $lead->submission_id ?? $submission_id );

        if ( $action === 'classify' ) {
            $feedback_token = Estandar_Leads_Tokens::create( $lead_id, absint( $operator->id ?? 0 ) );
            wp_redirect( $feedback_token !== '' ? Estandar_Leads_Tokens::url( $feedback_token ) : self::workspace_url( $token ) );
            exit;
        }

        $event_map = array(
            'whatsapp' => 'whatsapp_clicked',
            'email' => 'email_clicked',
            'call' => 'call_clicked',
            'view' => 'lead_viewed',
        );
        if ( ! isset( $event_map[ $action ] ) ) {
            wp_safe_redirect( self::workspace_url( $token ) );
            exit;
        }

        Estandar_Leads_Portal::log_portal_event( $lead_id, $event_map[ $action ], array(
            'project' => sanitize_key( $lead->project_slug ?? '' ),
            'workspace' => 'operator',
            'submission_id' => absint( $lead->submission_id ?? 0 ),
        ), absint( $operator->id ?? 0 ) );

        if ( $action === 'view' ) {
            self::render_lead( $operator, $projects, $lead, $token );
        }

        $target = self::workspace_url( $token );
        if ( $action === 'email' && ! empty( $lead->email ) ) {
            $target = 'mailto:' . sanitize_email( $lead->email )
                . '?subject=' . rawurlencode( self::email_subject( $lead ) )
                . '&body=' . rawurlencode( self::contact_message( $lead, $operator ) );
        } elseif ( $action === 'call' && ! empty( $lead->phone ) ) {
            $target = 'tel:' . Estandar_Leads_Normalizer::phone( $lead->phone );
        } elseif ( $action === 'whatsapp' && ! empty( $lead->phone ) ) {
            $target = 'https://wa.me/' . Estandar_Leads_Normalizer::phone_storage( $lead->phone )
                . '?text=' . rawurlencode( self::contact_message( $lead, $operator ) );
        }

        wp_redirect( $target );
        exit;
    }

    private static function get_lead( int $lead_id ) {
        global $wpdb;

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT l.id, l.name, l.email, l.phone, l.project_slug, l.status, l.quality, l.contact_attempts, l.first_contact_at, l.first_seen_at, l.last_seen_at,
                    (SELECT s.source_plugin FROM {$wpdb->prefix}es_lead_submissions s WHERE s.lead_id = l.id ORDER BY s.submitted_at DESC, s.id DESC LIMIT 1) AS latest_source_plugin,
                    (SELECT s.message FROM {$wpdb->prefix}es_lead_submissions s WHERE s.lead_id = l.id ORDER BY s.submitted_at DESC, s.id DESC LIMIT 1) AS latest_message,
                    (SELECT s.form_fields_json FROM {$wpdb->prefix}es_lead_submissions s WHERE s.lead_id = l.id ORDER BY s.submitted_at DESC, s.id DESC LIMIT 1) AS latest_form_fields_json,
                    (SELECT s.chatbot_json FROM {$wpdb->prefix}es_lead_submissions s WHERE s.lead_id = l.id ORDER BY s.submitted_at DESC, s.id DESC LIMIT 1) AS latest_chatbot_json
             FROM {$wpdb->prefix}es_leads l
             WHERE l.id = %d
             LIMIT 1",
            $lead_id
        ) );
    }

    public static function operator_backlog( int $operator_id, array $projects = array() ): array {
        global $wpdb;

        $operator_id = absint( $operator_id );
        if ( $operator_id === 0 ) {
            return self::empty_backlog();
        }

        if ( empty( $projects ) ) {
            $operator = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, project_slug, allowed_projects_json FROM {$wpdb->prefix}es_operators WHERE id = %d LIMIT 1",
                $operator_id
            ) );
            if ( $operator ) {
                $projects = self::allowed_projects( $operator );
            }
        }

        $worklist = self::worklist_for_operator( $operator_id, $projects );
        return $worklist['counts'];
    }

    private static function empty_backlog(): array {
        return array(
            'classification_pending' => 0,
            'action_required' => 0,
            'waiting_response' => 0,
            'closed' => 0,
        );
    }

    private static function worklist_for_operator( int $operator_id, array $projects ): array {
        if ( empty( $projects ) ) {
            return array(
                'leads_by_project' => array(),
                'queues' => array(
                    'classification_pending' => array(),
                    'action_required' => array(),
                    'waiting_response' => array(),
                    'closed' => array(),
                ),
                'counts' => self::empty_backlog(),
            );
        }

        $leads = self::submissions_by_project( $projects, $operator_id );
        $queues = array(
            'classification_pending' => array(),
            'action_required' => array(),
            'waiting_response' => array(),
            'closed' => array(),
        );

        foreach ( $projects as $project ) {
            foreach ( $leads[ $project ] ?? array() as $lead ) {
                $queue = self::queue_for_lead( $lead );
                $queues[ $queue ][] = $lead;
            }
        }

        return array(
            'leads_by_project' => $leads,
            'queues' => $queues,
            'counts' => array(
                'classification_pending' => count( $queues['classification_pending'] ),
                'action_required' => count( $queues['action_required'] ),
                'waiting_response' => count( $queues['waiting_response'] ),
                'closed' => count( $queues['closed'] ),
            ),
        );
    }

    private static function submissions_by_project( array $projects, int $operator_id = 0 ): array {
        global $wpdb;

        if ( empty( $projects ) ) {
            return array();
        }

        $rows = $wpdb->get_results(
            "SELECT s.id AS submission_id, s.context_json, s.source_plugin AS latest_source_plugin, s.message AS latest_message,
                    s.form_fields_json AS latest_form_fields_json, s.chatbot_json AS latest_chatbot_json, s.submitted_at, s.created_at AS submission_created_at,
                    l.id, l.name, l.email, l.phone, l.project_slug AS lead_project_slug, l.status, l.quality, l.sales_stage, l.feedback_status, l.feedback_completed, l.contact_attempts, l.first_contact_at, l.first_seen_at, l.last_seen_at, l.created_at, l.priority, l.temperature, l.score_components_json,
                    (SELECT MAX(e.created_at) FROM {$wpdb->prefix}es_lead_events e WHERE e.lead_id = l.id AND e.event_type IN ('whatsapp_clicked','email_clicked','call_clicked','contact_logged')) AS last_contact_action_at,
                    (SELECT MAX(e.created_at) FROM {$wpdb->prefix}es_lead_events e WHERE e.lead_id = l.id AND e.event_type = 'whatsapp_clicked') AS last_whatsapp_at,
                    (SELECT MAX(e.created_at) FROM {$wpdb->prefix}es_lead_events e WHERE e.lead_id = l.id AND e.event_type = 'call_clicked') AS last_call_at,
                    (SELECT MAX(e.created_at) FROM {$wpdb->prefix}es_lead_events e WHERE e.lead_id = l.id AND e.event_type = 'email_clicked') AS last_email_at,
                    (SELECT MAX(c.contacted_at) FROM {$wpdb->prefix}es_lead_contacts c WHERE c.lead_id = l.id AND c.channel = 'whatsapp') AS last_whatsapp_contact_at,
                    (SELECT MAX(c.contacted_at) FROM {$wpdb->prefix}es_lead_contacts c WHERE c.lead_id = l.id AND c.channel = 'phone') AS last_call_contact_at,
                    (SELECT MAX(c.contacted_at) FROM {$wpdb->prefix}es_lead_contacts c WHERE c.lead_id = l.id AND c.channel = 'email') AS last_email_contact_at,
                    (SELECT MAX(e.created_at) FROM {$wpdb->prefix}es_lead_events e WHERE e.lead_id = l.id AND e.event_type IN ('whatsapp_clicked','email_clicked','call_clicked','contact_logged','lead_viewed','status_changed','status_overridden','quality_changed','feedback_status_changed','commercial_stage_changed')) AS last_operator_action_at,
                    (SELECT e.event_type FROM {$wpdb->prefix}es_lead_events e WHERE e.lead_id = l.id AND e.event_type IN ('whatsapp_clicked','email_clicked','call_clicked','contact_logged','lead_viewed','status_changed','status_overridden','quality_changed','feedback_status_changed','commercial_stage_changed') ORDER BY e.created_at DESC, e.id DESC LIMIT 1) AS last_operator_action_type,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}es_lead_submissions sx WHERE sx.lead_id = l.id) AS submission_count
             FROM {$wpdb->prefix}es_lead_submissions s
             INNER JOIN {$wpdb->prefix}es_leads l ON l.id = s.lead_id
             ORDER BY s.created_at DESC, s.id DESC
             LIMIT 250"
        );

        $out = array_fill_keys( $projects, array() );
        foreach ( $rows ?: array() as $row ) {
            if ( ! self::can_access_submission( $operator_id, $projects, $row ) ) {
                continue;
            }
            $project = (string) $row->project_slug;
            $out[ $project ][] = $row;
        }
        return $out;
    }

    private static function get_submission_workspace_row( int $submission_id ) {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT s.id AS submission_id, s.context_json, s.source_plugin AS latest_source_plugin, s.message AS latest_message,
                    s.form_fields_json AS latest_form_fields_json, s.chatbot_json AS latest_chatbot_json, s.submitted_at, s.created_at AS submission_created_at,
                    l.id, l.name, l.email, l.phone, l.project_slug AS lead_project_slug, l.status, l.quality, l.sales_stage, l.feedback_status, l.feedback_completed, l.contact_attempts, l.first_contact_at, l.first_seen_at, l.last_seen_at, l.created_at
             FROM {$wpdb->prefix}es_lead_submissions s
             INNER JOIN {$wpdb->prefix}es_leads l ON l.id = s.lead_id
             WHERE s.id = %d
             LIMIT 1",
            $submission_id
        ) );

        return $row ? self::hydrate_submission_row( $row ) : null;
    }

    private static function can_access_submission( int $operator_id, array $projects, $submission ): bool {
        $submission = self::hydrate_submission_row( $submission );
        if ( ! $submission ) {
            return false;
        }
        $project = (string) ( $submission->project_slug ?? '' );
        if ( $project === '' || $project === Estandar_Leads_Repository::UNRESOLVED_PROJECT || ! in_array( $project, $projects, true ) ) {
            return false;
        }

        $assigned = Estandar_Leads_Repository::operator_for_submission( absint( $submission->id ?? 0 ), absint( $submission->submission_id ?? 0 ) );
        return ! $assigned || absint( $assigned->operator_id ?? 0 ) === $operator_id;
    }

    private static function hydrate_submission_row( $row ) {
        if ( is_array( $row ) ) {
            $row = (object) $row;
        }
        if ( ! is_object( $row ) ) {
            return null;
        }

        $row->project_slug = Estandar_Leads_Repository::project_for_submission( $row );
        return $row;
    }

    private static function queue_for_lead( $lead ): string {
        $status = strtoupper( (string) ( $lead->status ?? 'NEW' ) );
        $closed = array( 'RESERVED', 'DEED_SIGNED', 'DELIVERED', 'LOST', 'INVALID', 'NO_RESPONSE', 'UNREACHABLE' );
        if ( in_array( $status, $closed, true ) ) {
            return 'closed';
        }

        $has_contact = self::has_contact_attempt( $lead );
        $has_operator_action = self::has_operator_action( $lead );
        $has_partial_classification = $status !== 'NEW'
            || trim( (string) ( $lead->quality ?? '' ) ) !== ''
            || trim( (string) ( $lead->sales_stage ?? '' ) ) !== '';

        if ( $has_contact && ! $has_partial_classification ) {
            return 'waiting_response';
        }

        if ( $has_contact && $has_operator_action && $has_partial_classification ) {
            return 'classification_pending';
        }

        if ( $has_contact && self::is_waiting_response( $lead ) ) {
            return 'waiting_response';
        }

        return 'action_required';
    }

    private static function has_contact_attempt( $lead ): bool {
        $status = strtoupper( (string) ( $lead->status ?? 'NEW' ) );
        return (int) ( $lead->contact_attempts ?? 0 ) > 0
            || $status === 'CONTACTED'
            || ! empty( $lead->first_contact_at )
            || ! empty( $lead->last_contact_action_at );
    }

    private static function has_operator_action( $lead ): bool {
        $status = strtoupper( (string) ( $lead->status ?? 'NEW' ) );
        return self::has_contact_attempt( $lead )
            || $status !== 'NEW'
            || trim( (string) ( $lead->quality ?? '' ) ) !== ''
            || trim( (string) ( $lead->sales_stage ?? '' ) ) !== ''
            || ! empty( $lead->last_operator_action_at );
    }

    private static function is_waiting_response( $lead ): bool {
        $last = ! empty( $lead->last_contact_action_at )
            ? (string) $lead->last_contact_action_at
            : ( ! empty( $lead->first_contact_at ) ? (string) $lead->first_contact_at : '' );
        if ( $last === '' ) {
            return false;
        }
        $ts = strtotime( $last );
        return $ts && $ts < ( time() - self::WAITING_RESPONSE_HOURS * HOUR_IN_SECONDS );
    }

    private static function queue_label( string $queue ): string {
        $labels = array(
            'classification_pending' => 'Clasificación pendiente',
            'action_required' => 'Requieren acción',
            'waiting_response' => 'Esperando respuesta',
            'closed' => 'Cerrados',
        );
        return $labels[ $queue ] ?? $queue;
    }

    private static function action_label( string $event_type ): string {
        $labels = array(
            'whatsapp_clicked' => 'WhatsApp abierto',
            'email_clicked' => 'Email enviado',
            'call_clicked' => 'Llamada iniciada',
            'lead_viewed' => 'Workspace abierto',
            'contact_logged' => 'Contacto registrado',
            'status_changed' => 'Estado actualizado',
            'status_overridden' => 'Estado corregido',
            'quality_changed' => 'Calidad actualizada',
            'feedback_status_changed' => 'Feedback actualizado',
            'commercial_stage_changed' => 'Etapa actualizada',
        );
        return $labels[ $event_type ] ?? 'Sin acción';
    }

    private static function source_badge( $lead ): array {
        $source = sanitize_key( (string) ( $lead->latest_source_plugin ?? '' ) );
        if ( strpos( $source, 'chatbot' ) !== false ) {
            return array( 'icon' => '🤖', 'label' => 'Chatbot' );
        }
        if ( strpos( $source, 'form' ) !== false || $source === 'estandar-forms' ) {
            return array( 'icon' => '📝', 'label' => 'Formulario' );
        }
        if ( strpos( $source, 'manual' ) !== false ) {
            return array( 'icon' => '📞', 'label' => 'Manual' );
        }
        return array( 'icon' => '•', 'label' => $source !== '' ? $source : 'Origen no registrado' );
    }

    private static function captured_rows( $lead ): array {
        $fields = json_decode( (string) ( $lead->latest_form_fields_json ?? '' ), true );
        $rows = Estandar_Leads_Email::operator_form_rows( is_array( $fields ) ? $fields : array() );
        if ( ! empty( $lead->latest_message ) ) {
            $rows = array( 'Mensaje' => (string) $lead->latest_message ) + $rows;
        }
        return $rows;
    }

    private static function email_subject( $lead ): string {
        $project = resolve_project_display_name( $lead->project_slug ?? '', $lead->project_slug ?? '' ) ?: (string) ( $lead->project_slug ?? '' );
        return 'Informacion ' . $project;
    }

    private static function contact_message( $lead, $operator ): string {
        $project = resolve_project_display_name( $lead->project_slug ?? '', $lead->project_slug ?? '' ) ?: (string) ( $lead->project_slug ?? '' );
        $name = trim( (string) ( $lead->name ?? '' ) );
        $operator_name = trim( (string) ( $operator->name ?? '' ) );

        return trim( sprintf(
            "Hola %s, soy %s. Te contacto por tu interes en %s. Quedo atenta para ayudarte.",
            $name !== '' ? $name : '',
            $operator_name !== '' ? $operator_name : 'del equipo comercial',
            $project !== '' ? $project : 'nuestro proyecto'
        ) );
    }

    private static function render_workspace( $operator, array $projects, string $token ): void {
        $worklist = self::worklist_for_operator( absint( $operator->id ?? 0 ), $projects );
        $by_project = $worklist['leads_by_project'];
        foreach ( $projects as $project ) {
            foreach ( $by_project[ $project ] ?? array() as $lead ) {
                // workspace_opened: operator loaded their workspace and this lead was visible in the queue.
                // Distinct from lead_list_opened (admin portal) so display can label it "Workspace abierto".
                Estandar_Leads_Portal::log_portal_event( absint( $lead->id ), 'workspace_opened', array(
                    'project'   => $project,
                    'workspace' => 'operator',
                ), absint( $operator->id ?? 0 ) );
            }
        }

        self::header( 'Workspace' );
        echo '<main class="ow-wrap">';
        echo '<header class="ow-head"><div><span>Operadora</span><h1>' . esc_html( $operator->name ) . '</h1></div></header>';
        echo '<section class="ow-summary" aria-label="Resumen de pendientes">';
        foreach ( $worklist['counts'] as $key => $count ) {
            echo '<div data-ow-filter="' . esc_attr( $key ) . '" role="button" tabindex="0" aria-label="' . esc_attr( 'Filtrar: ' . self::queue_label( $key ) ) . '"><strong>' . esc_html( (string) $count ) . '</strong><span>' . esc_html( self::queue_label( $key ) ) . '</span></div>';
        }
        echo '</section>';
        echo '<section class="ow-search"><label for="ow-search-input">Buscar lead</label><input id="ow-search-input" type="search" placeholder="Buscar lead..." autocomplete="off"></section>';

        $order = array( 'classification_pending', 'action_required', 'waiting_response', 'closed' );
        foreach ( $order as $queue ) {
            $items = $worklist['queues'][ $queue ] ?? array();
            echo '<section class="ow-project ow-queue ow-queue-' . esc_attr( $queue ) . '" data-ow-queue="' . esc_attr( $queue ) . '">';
            echo '<h2>' . esc_html( self::queue_label( $queue ) ) . ' <span>' . esc_html( count( $items ) ) . '</span></h2>';
            if ( empty( $items ) ) {
                echo '<p class="ow-empty">Sin leads.</p>';
            }
            foreach ( $items as $lead ) {
                self::render_lead_row( $lead, $token, $queue, absint( $operator->id ?? 0 ) );
            }
            echo '</section>';
        }
        echo '</main>';
        self::footer();
    }

    private static function render_lead_row( $lead, string $token, string $queue = '', int $operator_id = 0 ): void {
        $back = self::workspace_url( $token );
        $project = resolve_project_display_name( $lead->project_slug ?? '', $lead->project_slug ?? '' ) ?: (string) ( $lead->project_slug ?? '' );
        $relative_time = self::relative_time( $lead->submitted_at ?? $lead->submission_created_at ?? $lead->created_at ?? $lead->last_seen_at ?? '' );
        $is_recurrent = (int) ( $lead->submission_count ?? 0 ) > 1 && $queue !== 'closed';

        $search = strtolower( trim( implode( ' ', array(
            (string) ( $lead->name ?? '' ),
            (string) ( $lead->phone ?? '' ),
            (string) ( $lead->email ?? '' ),
        ) ) ) );
        $visual = self::visual_state( $lead, $queue );
        echo '<article class="ow-lead ow-state-' . esc_attr( $visual['key'] ) . '" data-ow-search="' . esc_attr( $search ) . '">';
        echo '<div class="ow-lead-main">';
        echo '<strong>' . esc_html( $lead->name ?: '(sin nombre)' ) . '</strong>';
        echo '<span>' . esc_html( $project !== '' ? $project : 'Sin proyecto' ) . ' · ' . esc_html( $relative_time ) . '</span>';
        echo '</div>';
        echo '<div class="ow-badges">';
        echo '<em class="ow-state-label">' . esc_html( $visual['label'] ) . '</em>';
        echo '<em class="ow-priority">' . esc_html( self::priority_label( $queue ) ) . '</em>';
        if ( $is_recurrent ) {
            echo '<em class="ow-recurrent">Recontacto</em>';
        }
        $scoring = json_decode( (string) ( $lead->score_components_json ?? '' ), true );
        $behavior_score = is_array( $scoring ) ? (float) ( $scoring['behavior'] ?? 0 ) : 0;
        if ( $behavior_score > 0 ) {
            $temp_label = strtoupper( (string) ( $lead->temperature ?? '' ) );
            $score_badge = number_format( $behavior_score, 1 );
            if ( $temp_label !== '' ) {
                $score_badge .= ' ' . $temp_label;
            }
            echo '<em class="ow-score" style="background:#e8f5e9;color:#2e7d32;">' . esc_html( $score_badge ) . '</em>';
        }
        $priority_raw = strtoupper( (string) ( $lead->priority ?? '' ) );
        if ( $priority_raw !== '' && ! in_array( $priority_raw, array( 'MEDIA', 'BAJA' ), true ) ) {
            echo '<em class="ow-priority-db" style="background:#fce4ec;color:#c62828;">' . esc_html( $priority_raw ) . '</em>';
        }
        echo '</div>';
        echo '<div class="ow-contact-checks" aria-label="Contacto rapido">';
        self::render_contact_check( 'WhatsApp enviado', ! empty( $lead->last_whatsapp_at ) || ! empty( $lead->last_whatsapp_contact_at ) );
        self::render_contact_check( 'Llamada intentada', ! empty( $lead->last_call_at ) || ! empty( $lead->last_call_contact_at ) );
        self::render_contact_check( 'Email enviado', ! empty( $lead->last_email_at ) || ! empty( $lead->last_email_contact_at ) );
        echo '</div>';
        echo '<div class="ow-row-actions">';
        if ( ! empty( $lead->phone ) ) {
            echo '<a href="' . esc_url( self::action_url( $back, 'whatsapp', $lead ) ) . '" target="_blank" rel="noopener">WhatsApp</a>';
        }
        echo '<a class="ow-primary" href="' . esc_url( self::action_url( $back, 'classify', $lead ) ) . '">Clasificar</a>';
        $quote_url = apply_filters( 'estandar_leads_quote_create_url', '', array( 'submission_id' => absint( $lead->submission_id ?? 0 ), 'operator_id' => $operator_id ) );
        if ( $quote_url !== '' ) { echo '<a class="ow-secondary" href="' . esc_url( $quote_url ) . '">Cotizar</a>'; }
        if ( ! empty( $lead->phone ) ) {
            echo '<a class="ow-secondary" href="' . esc_url( self::action_url( $back, 'call', $lead ) ) . '">Llamar</a>';
        }
        if ( ! empty( $lead->email ) ) {
            echo '<a class="ow-secondary" href="' . esc_url( self::action_url( $back, 'email', $lead ) ) . '">Email</a>';
        }
        echo '<a class="ow-muted" href="' . esc_url( self::action_url( $back, 'view', $lead ) ) . '">Detalles</a>';
        echo '</div>';
        echo '</article>';
    }

    private static function action_url( string $back, string $action, $lead ): string {
        return add_query_arg( array(
            'ow_action' => $action,
            'lead_id' => absint( $lead->id ?? 0 ),
            'submission_id' => absint( $lead->submission_id ?? 0 ),
        ), $back );
    }

    private static function render_contact_check( string $label, bool $checked ): void {
        if ( ! $checked ) {
            return;
        }
        echo '<label><input type="checkbox" disabled' . checked( $checked, true, false ) . '> ' . esc_html( $label ) . '</label>';
    }

    private static function relative_time( $datetime ): string {
        $ts = strtotime( (string) $datetime );
        if ( ! $ts ) {
            return 'Sin fecha';
        }
        return human_time_diff( $ts, time() ) . ' atrás';
    }

    private static function priority_label( string $queue ): string {
        $labels = array(
            'classification_pending' => 'Media',
            'action_required'        => 'Alta',
            'waiting_response'       => 'Media',
            'closed'                 => 'Baja',
        );
        return $labels[ $queue ] ?? 'Media';
    }

    private static function visual_state( $lead, string $queue ): array {
        if ( (int) ( $lead->submission_count ?? 0 ) > 1 && $queue !== 'closed' ) {
            return array( 'key' => 'recurrent', 'label' => 'Recontacto' );
        }
        if ( $queue === 'closed' ) {
            return array( 'key' => 'classified', 'label' => 'Clasificado' );
        }
        if ( $queue === 'classification_pending' ) {
            return array( 'key' => 'pending', 'label' => 'Clasificación pendiente' );
        }
        if ( self::has_operator_action( $lead ) ) {
            return array( 'key' => 'active', 'label' => 'Actividad detectada' );
        }
        return array( 'key' => 'idle', 'label' => 'Sin actividad' );
    }

    private static function render_lead( $operator, array $projects, $lead, string $token ): void {
        $back = self::workspace_url( $token );
        self::header( 'Lead' );
        echo '<main class="ow-wrap">';
        echo '<p><a class="ow-back" href="' . esc_url( $back ) . '">Volver</a></p>';
        echo '<section class="ow-project ow-detail">';
        echo '<h1>' . esc_html( $lead->name ?: '(sin nombre)' ) . '</h1>';
        $source = self::source_badge( $lead );
        echo '<p>Lead #' . esc_html( (string) absint( $lead->id ?? 0 ) ) . ' · Submission #' . esc_html( (string) absint( $lead->submission_id ?? 0 ) ) . '</p>';
        echo '<p>' . esc_html( resolve_project_display_name( $lead->project_slug ?? '', $lead->project_slug ?? '' ) ?: ( $lead->project_slug ?? '' ) ) . '</p>';
        echo '<p><span title="' . esc_attr( $source['label'] ) . '">' . esc_html( $source['icon'] ) . '</span> ' . esc_html( $source['label'] ) . '</p>';
        $captured = self::captured_rows( $lead );
        if ( ! empty( $captured ) ) {
            echo '<div class="ow-captured"><h2>Datos capturados</h2>';
            foreach ( $captured as $label => $value ) {
                echo '<div><strong>' . esc_html( $label ) . '</strong><p>' . nl2br( esc_html( (string) $value ) ) . '</p></div>';
            }
            echo '</div>';
        }
        if ( class_exists( 'Estandar_Leads_SEO_Intelligence' ) ) { Estandar_Leads_SEO_Intelligence::render_context_block( (int) ( $lead->id ?? 0 ) ); }
        echo '<div class="ow-contact">';
        if ( ! empty( $lead->phone ) ) {
            echo '<a href="' . esc_url( self::action_url( $back, 'whatsapp', $lead ) ) . '" target="_blank" rel="noopener">WhatsApp</a>';
            echo '<a href="' . esc_url( self::action_url( $back, 'call', $lead ) ) . '">Llamar</a>';
        }
        if ( ! empty( $lead->email ) ) {
            echo '<a href="' . esc_url( self::action_url( $back, 'email', $lead ) ) . '">Email</a>';
        }
        echo '<a class="ow-primary" href="' . esc_url( self::action_url( $back, 'classify', $lead ) ) . '">Clasificar</a>';
        $quote_url = apply_filters( 'estandar_leads_quote_create_url', '', array( 'submission_id' => absint( $lead->submission_id ?? 0 ), 'operator_id' => absint( $operator->id ?? 0 ) ) );
        if ( $quote_url !== '' ) { echo '<a class="ow-secondary" href="' . esc_url( $quote_url ) . '">Crear cotización</a>'; }
        echo '</div>';
        echo '</section>';
        echo '</main>';
        self::footer();
        exit;
    }

    private static function render_message( string $title, string $message ): void {
        self::header( $title );
        echo '<main class="ow-wrap"><section class="ow-project"><h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $message ) . '</p></section></main>';
        self::footer();
        exit;
    }

    private static function header( string $title ): void {
        status_header( 200 );
        nocache_headers();
        echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>' . esc_html( $title ) . '</title><style>';
        echo 'body{margin:0;background:#f6f7f7;color:#1d2327;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif}.ow-wrap{max-width:860px;margin:0 auto;padding:14px}.ow-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}.ow-head span{font-size:12px;color:#646970;text-transform:uppercase}.ow-head h1{font-size:24px;margin:2px 0 0}.ow-summary{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:12px}.ow-summary div{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:10px}.ow-summary strong,.ow-summary span{display:block}.ow-summary strong{font-size:24px;line-height:1}.ow-summary span{font-size:12px;color:#646970;margin-top:4px}.ow-search{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:10px;margin-bottom:12px}.ow-search label{display:block;font-size:12px;font-weight:700;color:#50575e;margin-bottom:6px}.ow-search input{box-sizing:border-box;width:100%;font-size:16px;padding:10px 12px;border:1px solid #8c8f94;border-radius:6px}.ow-project{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px;margin-bottom:12px}.ow-project h2{font-size:18px;margin:0 0 10px}.ow-project h2 span{float:right;color:#8c8f94;font-size:15px}.ow-queue-classification_pending{border-left:4px solid #8e44ad}.ow-queue-action_required{border-left:4px solid #d63638}.ow-queue-waiting_response{border-left:4px solid #2271b1}.ow-queue-closed{border-left:4px solid #1e8e3e}.ow-lead{color:#1d2327;border:1px solid #e5e7eb;border-left-width:5px;border-radius:8px;padding:12px;margin-bottom:8px;background:#fbfbfc}.ow-state-idle{border-left-color:#8c8f94}.ow-state-active{border-left-color:#2271b1}.ow-state-pending{border-left-color:#dba617}.ow-state-classified{border-left-color:#1e8e3e}.ow-state-recurrent{border-left-color:#d9822b}.ow-state-label{display:inline-block;margin-left:6px;padding:3px 6px;border-radius:999px;background:#f0f0f1;color:#50575e;font-size:11px;font-style:normal;font-weight:700}.ow-state-active .ow-state-label{background:#e7f1fb;color:#0a4b78}.ow-state-pending .ow-state-label{background:#fcf3d9;color:#674e00}.ow-state-classified .ow-state-label{background:#e6f4ea;color:#1e5b2c}.ow-state-recurrent .ow-state-label{background:#fcefe3;color:#7a3b00}.ow-lead-main strong,.ow-lead-main span{display:block}.ow-lead-main span,.ow-empty,.ow-detail p{font-size:13px;color:#646970}.ow-contact-checks{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}.ow-contact-checks label{font-size:12px;color:#50575e;background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:5px 7px}.ow-contact-checks input{margin:0 4px 0 0}.ow-row-actions,.ow-contact{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}.ow-row-actions a,.ow-contact a,.ow-back{display:inline-block;text-decoration:none;background:#2271b1;color:#fff;border-radius:6px;padding:9px 10px;font-size:13px;font-weight:700}.ow-row-actions a.ow-primary,.ow-contact a.ow-primary{background:#1e8e3e}.ow-row-actions a.ow-muted,.ow-back{background:#50575e}.ow-detail h1{margin:0 0 4px;font-size:24px}.ow-captured{border-top:1px solid #eef0f2;margin-top:14px;padding-top:12px}.ow-captured h2{font-size:15px;margin:0 0 10px}.ow-captured div{border:1px solid #eef0f2;border-radius:6px;padding:10px;margin-bottom:8px}.ow-captured strong{font-size:12px;color:#50575e;text-transform:uppercase}.ow-captured p{margin:4px 0 0;white-space:pre-wrap;color:#1d2327}@media (max-width:620px){.ow-wrap{padding:10px}.ow-summary{grid-template-columns:repeat(2,1fr)}.ow-project{padding:12px}.ow-row-actions a,.ow-contact a{flex:1 1 42%;text-align:center}.ow-head h1{font-size:21px}}';
        echo '.ow-lead{min-height:60px;display:grid;grid-template-columns:minmax(170px,1fr) auto auto auto;gap:8px;align-items:center;padding:8px 10px;margin-bottom:6px}.ow-lead-main{min-width:0}.ow-lead-main strong{font-size:14px;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.ow-lead-main span{font-size:12px;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.ow-badges{display:flex;gap:4px;align-items:center;flex-wrap:wrap}.ow-state-label,.ow-priority,.ow-recurrent{display:inline-block;margin:0;padding:3px 6px;border-radius:999px;background:#f0f0f1;color:#50575e;font-size:10px;font-style:normal;font-weight:700;white-space:nowrap}.ow-priority{background:#eef4ff;color:#0a4b78}.ow-recurrent{background:#fcefe3;color:#7a3b00}.ow-contact-checks{margin:0;gap:4px}.ow-contact-checks label{font-size:10px;padding:3px 5px}.ow-contact-checks input{display:none}.ow-row-actions{margin:0;gap:4px;justify-content:flex-end;flex-wrap:nowrap}.ow-row-actions a{padding:6px 8px;font-size:12px;line-height:1.1}.ow-row-actions a.ow-secondary{background:#6b7280}.ow-row-actions a.ow-muted{background:#50575e}@media (max-width:720px){.ow-lead{grid-template-columns:1fr;align-items:start}.ow-row-actions{justify-content:flex-start;flex-wrap:wrap}.ow-lead{min-height:76px}}.ow-summary div{cursor:pointer;transition:box-shadow .15s}.ow-summary div.ow-filter-active{box-shadow:0 0 0 2px #2271b1;background:#f0f6fc}.ow-summary div:focus{outline:2px solid #2271b1;outline-offset:2px}';
        echo '</style></head><body>';
    }

    private static function footer(): void {
        echo '<script>
(function(){
    var PAGE_SIZE = 5;

    // Single source of truth for the list UI. Every interaction mutates state
    // and calls render(); render() alone decides what is visible. This is what
    // keeps search, pagination, the closed-queue collapse and the summary-card
    // filter from stepping on each other.
    var state = {
        query: "",          // active search text (lowercased)
        filter: "",         // active queue filter from summary cards ("" = none)
        closedExpanded: false,
        shown: {}           // queue key -> number of paginated rows visible
    };

    var sections = [].slice.call(document.querySelectorAll(".ow-queue[data-ow-queue]"));
    var meta = {}; // queue key -> { section, articles, bar, counter, toggle, empty }

    sections.forEach(function(section){
        var key = section.getAttribute("data-ow-queue");
        var articles = [].slice.call(section.querySelectorAll("article.ow-lead"));
        var m = { section: section, articles: articles, bar: null, counter: null, toggle: null };
        meta[key] = m;

        if (key === "closed") {
            if (!articles.length) { return; }
            var toggle = document.createElement("button");
            toggle.style.cssText = "margin-top:6px;border:1px solid #8c8f94;background:#fff;border-radius:6px;padding:5px 10px;font-size:12px;cursor:pointer;color:#50575e;";
            toggle.addEventListener("click", function(){
                state.closedExpanded = !state.closedExpanded;
                render();
            });
            var h2 = section.querySelector("h2");
            if (h2) { h2.insertAdjacentElement("afterend", toggle); }
            else { section.insertBefore(toggle, articles[0]); }
            m.toggle = toggle;
            return;
        }

        state.shown[key] = Math.min(PAGE_SIZE, articles.length);
        if (articles.length <= PAGE_SIZE) { return; }

        var bar = document.createElement("div");
        bar.style.cssText = "padding:8px 0 4px;display:flex;align-items:center;gap:12px;font-size:12px;color:#646970;";

        var counter = document.createElement("span");

        var btnMore = document.createElement("button");
        btnMore.textContent = "Mostrar más";
        btnMore.style.cssText = "border:1px solid #8c8f94;background:#fff;border-radius:6px;padding:4px 10px;font-size:12px;cursor:pointer;";
        btnMore.addEventListener("click", function(){
            state.shown[key] = Math.min(state.shown[key] + PAGE_SIZE, articles.length);
            render();
        });

        var btnAll = document.createElement("button");
        btnAll.textContent = "Ver todos";
        btnAll.style.cssText = "border:none;background:none;color:#2271b1;font-size:12px;cursor:pointer;text-decoration:underline;padding:0;";
        btnAll.addEventListener("click", function(){
            state.shown[key] = articles.length;
            render();
        });

        bar.appendChild(counter);
        bar.appendChild(btnMore);
        bar.appendChild(btnAll);
        section.appendChild(bar);
        m.bar = bar;
        m.counter = counter;
    });

    function render() {
        var q = state.query;
        var searching = q !== "";

        sections.forEach(function(section){
            var key = section.getAttribute("data-ow-queue");
            var m = meta[key];
            var visibleCount = 0;

            m.articles.forEach(function(a, i){
                var show;
                if (searching) {
                    // Search wins: match against every lead, ignoring pagination,
                    // the closed collapse and the 5-row limit.
                    show = (a.getAttribute("data-ow-search") || "").indexOf(q) !== -1;
                } else if (key === "closed") {
                    show = state.closedExpanded;
                } else {
                    show = i < state.shown[key];
                }
                a.style.display = show ? "" : "none";
                if (show) { visibleCount++; }
            });

            var sectionVisible;
            if (searching) {
                sectionVisible = visibleCount > 0;
            } else if (state.filter) {
                sectionVisible = key === state.filter;
            } else {
                sectionVisible = true;
            }
            section.style.display = sectionVisible ? "" : "none";

            if (m.bar) {
                if (searching || state.shown[key] >= m.articles.length) {
                    m.bar.style.display = "none";
                } else {
                    m.bar.style.display = "";
                    m.counter.textContent = "Mostrando " + state.shown[key] + " de " + m.articles.length;
                }
            }
            if (m.toggle) {
                m.toggle.style.display = searching ? "none" : "";
                m.toggle.textContent = state.closedExpanded
                    ? "Ocultar cerrados"
                    : "Ver cerrados (" + m.articles.length + ")";
            }
        });
    }

    var searchInput = document.getElementById("ow-search-input");
    if (searchInput) {
        searchInput.addEventListener("input", function(){
            state.query = searchInput.value.toLowerCase().trim();
            render();
        });
    }

    var btns = [].slice.call(document.querySelectorAll("[data-ow-filter]"));
    var handleKey = function(e){ if (e.key === "Enter" || e.key === " ") { e.preventDefault(); e.currentTarget.click(); } };
    btns.forEach(function(btn){
        btn.addEventListener("keydown", handleKey);
        btn.addEventListener("click", function(){
            var key = btn.getAttribute("data-ow-filter");
            var isActive = btn.classList.contains("ow-filter-active");
            btns.forEach(function(b){ b.classList.remove("ow-filter-active"); });
            if (isActive) {
                state.filter = "";
            } else {
                btn.classList.add("ow-filter-active");
                state.filter = key;
            }
            render();
        });
    });

    render();
})();
</script>';
        echo '</body></html>';
        exit;
    }
}
