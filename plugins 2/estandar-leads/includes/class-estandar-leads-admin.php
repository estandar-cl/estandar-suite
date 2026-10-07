<?php

if (!defined('ABSPATH')) {
    exit;
}

class Estandar_Leads_Admin
{
    public static function init()
    {
        // Register in Estandar Admin Bar (explicit — covers frontend where $submenu isn't populated).
        // On wp-admin, Leads also appears via $submenu auto-discovery in estandar_core_build_admin_bar().
        if ( function_exists( 'estandar_core_register_admin_bar_item' ) ) {
            estandar_core_register_admin_bar_item( [
                'slug'       => 'estandar-leads',
                'title'      => 'Leads',
                'url'        => admin_url( 'admin.php?page=estandar-leads' ),
                'capability' => 'manage_options',
                'order'      => 20,
            ] );
        }

        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_filter('admin_title', array(__CLASS__, 'fix_admin_title'), 10, 2);
        add_action('admin_post_estandar_leads_save_operator',       array(__CLASS__, 'save_operator'));
        add_action('admin_post_estandar_leads_toggle_operator',     array(__CLASS__, 'toggle_operator'));
        add_action('admin_post_estandar_leads_rotate_operator_token', array(__CLASS__, 'rotate_operator_token'));
        add_action('admin_post_estandar_leads_view_operator_workspace', array(__CLASS__, 'view_operator_workspace'));
        add_action('admin_post_estandar_leads_create_link',         array(__CLASS__, 'create_link'));
        add_action('admin_post_estandar_leads_export_lead',         array(__CLASS__, 'export_lead'));
        add_action('admin_post_estandar_leads_contact_action',      array(__CLASS__, 'contact_action'));
        add_action('admin_post_estandar_leads_save_operation',      array(__CLASS__, 'save_operation'));
        add_action('admin_post_estandar_leads_save_email_settings', array(__CLASS__, 'save_email_settings'));
        add_action('admin_post_estandar_leads_save_autoresponse_settings', array(__CLASS__, 'save_autoresponse_settings'));
        add_action('admin_post_estandar_leads_preview_autoresponse', array(__CLASS__, 'preview_autoresponse'));
        add_action('admin_post_estandar_leads_test_autoresponse', array(__CLASS__, 'test_autoresponse'));
        add_action('admin_post_estandar_leads_save_whatsapp_settings', array(__CLASS__, 'save_whatsapp_settings'));
        add_action('admin_post_estandar_leads_save_context_gap_settings', array(__CLASS__, 'save_context_gap_settings'));
        add_action('admin_post_estandar_leads_save_ads_settings',           array(__CLASS__, 'save_ads_settings'));
        add_action('admin_post_estandar_leads_save_meta_settings',          array(__CLASS__, 'save_meta_settings'));
       add_action('admin_post_estandar_leads_save_fit_indicator_settings', array(__CLASS__, 'save_fit_indicator_settings'));
       add_action('admin_post_estandar_leads_save_assessment_ai_settings', array(__CLASS__, 'save_assessment_ai_settings'));
        add_action('admin_post_estandar_leads_save_compliance_settings',  array('Estandar_Leads_Compliance', 'save_settings_handler'));
        add_action('admin_post_estandar_leads_run_bootstrap',             array(__CLASS__, 'run_compliance_bootstrap'));
        add_action('admin_post_estandar_leads_save_qualification_rules',  array(__CLASS__, 'save_qualification_rules'));
        add_action('admin_post_estandar_leads_reset_qualification_rules', array(__CLASS__, 'reset_qualification_rules'));
        add_action('admin_post_estandar_leads_bulk_delete',               array(__CLASS__, 'bulk_delete'));
        add_action('admin_post_estandar_leads_export_csv',                array(__CLASS__, 'export_csv'));
        add_action('admin_post_estandar_leads_update_project',            array(__CLASS__, 'update_lead_project'));
        add_action('admin_post_estandar_leads_delete_operator',           array(__CLASS__, 'delete_operator'));
        add_action('admin_post_estandar_leads_recover_orphan_operators',  array(__CLASS__, 'recover_orphan_operators'));
        add_action('wp_ajax_estandar_assign_operator',              array(__CLASS__, 'ajax_assign_operator'));
        add_action('wp_ajax_estandar_leads_preview_email',          array(__CLASS__, 'ajax_preview_email'));
        add_action('wp_ajax_estandar_leads_test_email',             array(__CLASS__, 'ajax_test_email'));
        add_action('wp_ajax_estandar_leads_ai_context',             array(__CLASS__, 'ajax_get_ai_context'));
        add_action('wp_ajax_estandar_leads_meta_diagnostics',       array(__CLASS__, 'ajax_meta_diagnostics'));
        add_action('admin_enqueue_scripts',                          array(__CLASS__, 'enqueue_autoresponse_assets'));
        add_action('admin_init',                                     array(__CLASS__, 'maybe_redirect_portal_alias'));
        add_action('admin_init',                                     array(__CLASS__, 'maybe_redirect_operator_alias'));
    }

    /**
     * Alias de compatibilidad estandar-leads-portal → estandar-leads.
     * Se ejecuta en admin_init (antes de cualquier salida) para evitar
     * "Cannot modify header information" del redirect en el render callback.
     */
    public static function maybe_redirect_portal_alias()
    {
        if ( ( $_GET['page'] ?? '' ) !== 'estandar-leads-portal' ) {
            return;
        }
        // Preserva todos los query params (view, lead_id, filters, etc.).
        $params = $_GET;
        unset( $params['page'] );
        $url = admin_url( 'admin.php?page=estandar-leads' );
        if ( ! empty( $params ) ) {
            $url = add_query_arg( $params, $url );
        }
        wp_safe_redirect( $url );
        exit;
    }

    public static function maybe_redirect_operator_alias()
    {
        if ( ( $_GET['page'] ?? '' ) !== 'estandar-leads-settings' ) {
            return;
        }
        if ( ! isset( $_GET['edit_operator'] ) && ! isset( $_GET['project_slug'] ) && ! isset( $_GET['diagnostic'] ) ) {
            return;
        }

        $params = $_GET;
        unset( $params['page'] );
        $url = admin_url( 'admin.php?page=estandar_projects_operators' );
        if ( ! empty( $params ) ) {
            $url = add_query_arg( $params, $url );
        }
        $url .= '#operator-form';
        wp_safe_redirect( $url );
        exit;
    }

    public static function menu()
    {
        if (primer_is_active()) {
            estandar_admin_menu_register_parent();
            // Leads: ítem visible canónico. Renderiza operator-portal.php internamente.
            if ( function_exists( 'estandar_register_navigation_page' ) ) {
                estandar_register_navigation_page( [ 'slug' => 'estandar-leads', 'capability' => 'manage_options', 'callback' => array( __CLASS__, 'leads' ) ] );
                estandar_register_navigation_page( [ 'slug' => 'estandar-leads-reports', 'capability' => 'manage_options', 'callback' => array( __CLASS__, 'reports' ) ] );
                estandar_register_navigation_page( [ 'slug' => 'estandar-leads-settings', 'capability' => 'manage_options', 'callback' => array( __CLASS__, 'settings' ) ] );
                estandar_register_navigation_page( [ 'slug' => 'estandar-leads-email', 'capability' => 'manage_options', 'callback' => array( __CLASS__, 'email_settings' ) ] );
                estandar_register_navigation_page( [ 'slug' => 'estandar-leads-autoresponse', 'capability' => 'manage_options', 'callback' => array( __CLASS__, 'autoresponse_settings' ) ] );
                estandar_register_navigation_page( [ 'slug' => 'estandar-leads-exports', 'capability' => 'manage_options', 'callback' => array( __CLASS__, 'exports' ) ] );
            }
            // Portal: alias hidden para compatibilidad de URLs existentes → redirige a Leads.
            if ( function_exists( 'estandar_register_navigation_page' ) ) {
                estandar_register_navigation_page( [ 'slug' => 'estandar-leads-portal', 'capability' => 'manage_options', 'callback' => array( __CLASS__, 'portal' ) ] );
            }
        } else {
            // Standalone (sin Primer): Leads como landing principal.
            add_menu_page('Estandar Leads', 'Estandar Leads', 'manage_options', 'estandar-leads', array(__CLASS__, 'leads'), 'dashicons-chart-line', 58);
            add_submenu_page('estandar-leads', 'Leads',         'Leads',         'manage_options', 'estandar-leads',          array(__CLASS__, 'leads'));
            add_submenu_page('estandar-leads', 'Reportes',      'Reportes',      'manage_options', 'estandar-leads-reports',  array(__CLASS__, 'reports'));
            add_submenu_page('estandar-leads', 'Configuración', 'Configuración', 'manage_options', 'estandar-leads-settings', array(__CLASS__, 'settings'));
            // Portal y Email: alias hidden → redirigen a Leads.
            add_submenu_page(null, 'Portal', 'Portal', 'manage_options', 'estandar-leads-portal', array(__CLASS__, 'portal'));
            add_submenu_page(null, 'Email',  'Email',  'manage_options', 'estandar-leads-email',  array(__CLASS__, 'email_settings'));
            add_submenu_page(null, 'Autorespuesta', 'Autorespuesta', 'manage_options', 'estandar-leads-autoresponse', array(__CLASS__, 'autoresponse_settings'));
            add_submenu_page(null, 'Exportaciones', 'Exportaciones', 'manage_options', 'estandar-leads-exports',      array(__CLASS__, 'exports'));
        }
    }

    /**
     * Una sola barra de navegación: Leads | Reportes | Configuración | Portal.
     * Email se accede desde Configuración.
     */
    public static function render_tabs($active)
    {
        if (primer_is_active() && estandar_core_is_rendering_admin_shell()) {
            return;
        }

        // CORE_OPTIONALITY_V1: la rama standalone no puede asumir símbolos de core.
        if (function_exists('estandar_render_tabs')) {
            estandar_render_tabs(self::get_navigation_tabs(), self::resolve_active_tab($active));
            return;
        }
        $resolved = self::resolve_active_tab($active);
        echo '<h2 class="nav-tab-wrapper">';
        foreach (self::get_navigation_tabs() as $slug => $tab) {
            printf(
                '<a href="%s" class="nav-tab%s">%s</a>',
                esc_url($tab['url'] ?? ''),
                $slug === $resolved ? ' nav-tab-active' : '',
                esc_html($tab['label'] ?? '')
            );
        }
        echo '</h2>';
    }

    private static function get_navigation_tabs()
    {
        if ( function_exists( 'estandar_core_navigation_tabs' ) ) {
            return estandar_core_navigation_tabs( 'leads' );
        }

        return array(
            'estandar-leads' => array(
                'label' => 'Leads',
                'url'   => admin_url('admin.php?page=estandar-leads'),
            ),
            'estandar-leads-reports' => array(
                'label' => 'Operaciones',
                'url'   => admin_url('admin.php?page=estandar-leads-reports'),
            ),
            'estandar-leads-settings' => array(
                'label' => 'Configuración',
                'url'   => admin_url('admin.php?page=estandar-leads-settings'),
            ),
            'estandar-leads-autoresponse' => array(
                'label' => 'Autorespuesta',
                'url'   => admin_url('admin.php?page=estandar-leads-autoresponse'),
            ),
            'estandar-leads-exports' => array(
                'label' => 'Exportaciones',
                'url'   => admin_url('admin.php?page=estandar-leads-exports'),
            ),
        );
    }

    private static function resolve_active_tab($active)
    {
        $resolved_active = $active;
        if ($active === 'estandar-leads-email') {
            $resolved_active = 'estandar-leads-settings';
        }

        return $resolved_active;
    }

    private static function get_shell_navigation()
    {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : 'estandar-leads';
        $active = self::resolve_active_tab($page);

        $items = array();
        foreach (self::get_navigation_tabs() as $slug => $tab) {
            $items[] = array(
                'slug'   => $slug,
                'label'  => $tab['label'],
                'url'    => $tab['url'],
                'active' => $slug === $active,
            );
        }

        return array('items' => $items);
    }

    private static function diagnostic_projects()
    {
        $raw = isset($_GET['projects']) ? sanitize_text_field(wp_unslash((string) $_GET['projects'])) : '';
        $projects = array_filter(array_map('sanitize_title', array_map('trim', explode(',', $raw))));
        return array_values(array_unique($projects));
    }

    private static function render_diagnostic_notice($expected, $title, $message, $action)
    {
        $diagnostic = isset($_GET['diagnostic']) ? sanitize_key(wp_unslash((string) $_GET['diagnostic'])) : '';
        if ($diagnostic !== $expected) {
            return;
        }

        $projects = self::diagnostic_projects();
        echo '<div class="notice notice-warning"><p><strong>Está corrigiendo una incidencia detectada por Estado.</strong><br>' . esc_html($title) . '</p>';
        if (!empty($projects)) {
            echo '<p><strong>Proyecto:</strong></p>';
            echo '<ul style="margin-left:18px;list-style:disc;">';
            foreach ($projects as $project) {
                echo '<li>' . esc_html($project) . '</li>';
            }
            echo '</ul>';
        }
        echo '<p><strong>Acción requerida:</strong><br>' . esc_html($action) . '</p>';
        echo '<p class="description">' . esc_html($message) . '</p>';
        echo '</div>';
    }

    public static function leads()
    {
        // Leads es la UI canónica — renderiza operator-portal.php directamente.
        self::render_shell('Leads', array(__CLASS__, 'portal_content'));
    }

    public static function reports()
    {
        self::render_shell('Reportes', array(__CLASS__, 'reports_content'));
    }

    public static function reports_content()
    {
        global $wpdb;

        $since = gmdate('Y-m-d H:i:s', time() - (30 * DAY_IN_SECONDS));
        $campaigns = $wpdb->get_results($wpdb->prepare(
            "SELECT COALESCE(NULLIF(s.utm_campaign, ''), '(sin campana)') AS label,
                COUNT(*) AS total,
                SUM(l.status = 'QUALIFIED') AS qualified,
                SUM(l.status = 'LOST') AS disqualified,
                SUM(l.status = 'NO_RESPONSE') AS unreachable,
                SUM(l.status IN ('MEETING_BOOKED','VISIT_SCHEDULED','VISITED')) AS meeting,
                SUM(l.status = 'RESERVED') AS reservations,
                SUM(l.status IN ('DEED_SIGNED','DELIVERED')) AS sales
            FROM {$wpdb->prefix}es_lead_submissions s
            INNER JOIN {$wpdb->prefix}es_leads l ON l.id = s.lead_id
            WHERE s.created_at >= %s AND s.suspect = 0
            GROUP BY label
            ORDER BY total DESC",
            $since
        ));
        $terms = $wpdb->get_results($wpdb->prepare(
            "SELECT COALESCE(NULLIF(s.utm_term, ''), '(sin keyword)') AS label,
                COUNT(*) AS total,
                SUM(l.status = 'QUALIFIED') AS qualified,
                SUM(l.status = 'RESERVED') AS reservations,
                SUM(l.status IN ('DEED_SIGNED','DELIVERED')) AS sales
            FROM {$wpdb->prefix}es_lead_submissions s
            INNER JOIN {$wpdb->prefix}es_leads l ON l.id = s.lead_id
            WHERE s.created_at >= %s AND s.suspect = 0
            GROUP BY label
            ORDER BY total DESC",
            $since
        ));
        // KEYWORDS_CLEANUP_V1: utm_term solo es keyword de búsqueda en Google/Bing.
        // Meta manda ahí IDs o nombres de conjunto de anuncios — fuera de esta tabla
        // (esos se etiquetan en Campañas). Filtra IDs numéricos de plataforma.
        $terms = array_values( array_filter( (array) $terms, static function ( $t ) {
            $label = (string) ( $t->label ?? '' );
            return ! ( ctype_digit( $label ) && strlen( $label ) >= 8 );
        } ) );
        $pending = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}es_leads WHERE feedback_completed = 0");
        $suspect = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}es_lead_submissions WHERE created_at >= %s AND suspect = 1", $since));

        // ── Stage Age acumulado (edad desde creación del lead) ───────────────
        // Responde: ¿qué tan viejo llega un lead a cada estado?
        // Útil como contexto del ciclo total, no para diagnosticar cuellos.
        $stage_age_cumulative = $wpdb->get_results(
            "SELECT
                e1.from_status,
                e1.to_status,
                COUNT(*)                                                       AS transitions,
                ROUND( AVG( TIMESTAMPDIFF(HOUR, l.created_at, e1.created_at) / 24.0 ), 1 ) AS avg_days,
                ROUND( MIN( TIMESTAMPDIFF(HOUR, l.created_at, e1.created_at) / 24.0 ), 1 ) AS min_days,
                ROUND( MAX( TIMESTAMPDIFF(HOUR, l.created_at, e1.created_at) / 24.0 ), 1 ) AS max_days
            FROM {$wpdb->prefix}es_lead_events e1
            INNER JOIN {$wpdb->prefix}es_leads l ON l.id = e1.lead_id
            WHERE e1.event_type = 'status_change'
              AND e1.from_status != ''
              AND e1.to_status   != ''
            GROUP BY e1.from_status, e1.to_status
            ORDER BY FIELD(e1.to_status,'CONTACTED','NO_RESPONSE','QUALIFIED','VISIT_SCHEDULED','VISITED','BANK_EVALUATION','RESERVED','DEED_SIGNED','DELIVERED','LOST')"
        );

        // ── Stage Age diferencial (tiempo entre estados consecutivos) ─────────
        // Responde: ¿dónde se ralentiza el proceso? Diagnóstica cuellos.
        // Para cada transición busca el evento inmediatamente anterior del mismo
        // lead (subquery correlacionada). Si no hay evento previo usa created_at
        // del lead como baseline (correcto para la primera transición NEW→X).
        $stage_age_differential = $wpdb->get_results(
            "SELECT
                e1.from_status,
                e1.to_status,
                COUNT(*)  AS transitions,
                ROUND( AVG(
                    TIMESTAMPDIFF( HOUR,
                        COALESCE(
                            ( SELECT ep.created_at
                              FROM {$wpdb->prefix}es_lead_events ep
                              WHERE ep.lead_id     = e1.lead_id
                                AND ep.event_type  = 'status_change'
                                AND ep.created_at  < e1.created_at
                              ORDER BY ep.created_at DESC
                              LIMIT 1 ),
                            l.created_at
                        ),
                        e1.created_at
                    ) / 24.0
                ), 1 ) AS avg_days,
                ROUND( MIN(
                    TIMESTAMPDIFF( HOUR,
                        COALESCE(
                            ( SELECT ep2.created_at
                              FROM {$wpdb->prefix}es_lead_events ep2
                              WHERE ep2.lead_id    = e1.lead_id
                                AND ep2.event_type = 'status_change'
                                AND ep2.created_at < e1.created_at
                              ORDER BY ep2.created_at DESC
                              LIMIT 1 ),
                            l.created_at
                        ),
                        e1.created_at
                    ) / 24.0
                ), 1 ) AS min_days,
                ROUND( MAX(
                    TIMESTAMPDIFF( HOUR,
                        COALESCE(
                            ( SELECT ep3.created_at
                              FROM {$wpdb->prefix}es_lead_events ep3
                              WHERE ep3.lead_id    = e1.lead_id
                                AND ep3.event_type = 'status_change'
                                AND ep3.created_at < e1.created_at
                              ORDER BY ep3.created_at DESC
                              LIMIT 1 ),
                            l.created_at
                        ),
                        e1.created_at
                    ) / 24.0
                ), 1 ) AS max_days
            FROM {$wpdb->prefix}es_lead_events e1
            INNER JOIN {$wpdb->prefix}es_leads l ON l.id = e1.lead_id
            WHERE e1.event_type = 'status_change'
              AND e1.from_status != ''
              AND e1.to_status   != ''
            GROUP BY e1.from_status, e1.to_status
            ORDER BY FIELD(e1.to_status,'CONTACTED','NO_RESPONSE','QUALIFIED','VISIT_SCHEDULED','VISITED','BANK_EVALUATION','RESERVED','DEED_SIGNED','DELIVERED','LOST')"
        );

        // ── Conversión por operador ───────────────────────────────────────────
        $operator_perf = $wpdb->get_results(
            "SELECT
                COALESCE( NULLIF( assigned_operator_name, '' ), '(sin asignar)' ) AS operador,
                COUNT(*)                                                   AS leads,
                SUM( feedback_completed = 1
                     OR ( quality IS NOT NULL AND quality != '' )
                     OR last_classified_at IS NOT NULL )                   AS clasificados,
                SUM( status = 'QUALIFIED' )                                AS calificados,
                SUM( status IN ('MEETING_BOOKED','VISIT_SCHEDULED','VISITED') ) AS reuniones,
                SUM( status = 'RESERVED' )                                 AS reservas,
                SUM( status IN ('DEED_SIGNED','DELIVERED') )               AS ventas,
                ROUND( SUM( status IN ('DEED_SIGNED','DELIVERED') ) / COUNT(*) * 100, 1 ) AS cierre_pct
            FROM {$wpdb->prefix}es_leads
            GROUP BY assigned_operator_name
            ORDER BY ventas DESC, reuniones DESC, leads DESC"
        );

        // ── Latencia por operador ─────────────────────────────────────────────
        // Tiempo promedio desde asignación hasta primera clasificación.
        // Detecta operadores que reciben leads pero tardan en actuar.
        $seven_days_ago = gmdate( 'Y-m-d H:i:s', time() - ( 7 * DAY_IN_SECONDS ) );
        $operator_latency = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    COALESCE( NULLIF( assigned_operator_name, '' ), '(sin asignar)' ) AS operador,
                    COUNT(*)                                                           AS leads_asignados,
                    SUM( status = 'NEW' AND assigned_at IS NOT NULL )                 AS pendientes_new,
                    SUM( status = 'NEW' AND assigned_at IS NOT NULL
                         AND assigned_at < %s )                                       AS vencidos_7d,
                    ROUND( AVG(
                        CASE WHEN last_classified_at IS NOT NULL AND assigned_at IS NOT NULL
                             THEN TIMESTAMPDIFF( HOUR, assigned_at, last_classified_at ) / 24.0
                        END
                    ), 1 )                                                             AS avg_days_to_classify
                FROM {$wpdb->prefix}es_leads
                WHERE assigned_operator_name != ''
                GROUP BY assigned_operator_name
                ORDER BY vencidos_7d DESC, pendientes_new DESC",
                $seven_days_ago
            )
        );

        require ESTANDAR_LEADS_PATH . 'templates/admin/reports.php';
    }

    public static function settings()
    {
        self::render_shell('Ajustes', array(__CLASS__, 'settings_content'));
    }

    public static function settings_content()
    {
        $whatsapp_prefill_message = (string) get_option('estandar_leads_whatsapp_prefill_message', '');
        $context_gap_template = Estandar_Leads_Repository::context_gap_template();
        require ESTANDAR_LEADS_PATH . 'templates/admin/settings.php';
    }

    public static function operator_screen_context(): array
    {
        global $wpdb;

        $operators = $wpdb->get_results("SELECT id, name, email, project_slug, area, whatsapp_number, active, access_token_hash, access_token_expires_at, allowed_projects_json, operational_hours_json, created_at, updated_at FROM {$wpdb->prefix}es_operators ORDER BY active DESC, name ASC");
        $project_registry = Estandar_Leads_Projects::registry();
        $registry_slugs = array_column($project_registry, 'project_slug');
        $focus_project_slug = isset($_GET['project_slug']) ? sanitize_title(wp_unslash((string) $_GET['project_slug'])) : '';
        $diagnostic = isset($_GET['diagnostic']) ? sanitize_key(wp_unslash((string) $_GET['diagnostic'])) : '';
        $return_to      = isset($_GET['return_to']) ? sanitize_key(wp_unslash((string) $_GET['return_to'])) : '';
        $return_project = isset($_GET['return_project']) ? sanitize_title(wp_unslash((string) $_GET['return_project'])) : '';
        $focus_project_label = $focus_project_slug;

        foreach ( $project_registry as $project ) {
            if ( (string) ( $project['project_slug'] ?? '' ) === $focus_project_slug ) {
                $focus_project_label = (string) ( $project['name'] ?? $focus_project_slug );
                break;
            }
        }

        $registry_count  = count( $project_registry );
        $registry_ok     = $registry_count > 0;
        $ops_warnings = 0;
        $slugs_with_operator = array();

        foreach ( $operators as $op ) {
            $op_projects = self::allowed_projects_from_operator( $op );
            if ( empty( $op_projects ) && ! empty( $op->project_slug ) ) {
                $op_projects = Estandar_Leads_Operator_Workspace::sanitize_projects( array( $op->project_slug ) );
            }
            foreach ( $op_projects as $slug ) {
                if ( $slug !== '' && ! in_array( $slug, $registry_slugs, true ) ) {
                    $ops_warnings++;
                }
                if ( ! empty( $op->active ) && $slug !== '' ) {
                    $slugs_with_operator[] = $slug;
                }
            }
        }

        $slugs_with_operator = array_values( array_unique( $slugs_with_operator ) );
        $active_operators = array_values( array_filter( (array) $operators, static function ( $operator ) {
            return ! empty( $operator->active );
        } ) );
        $active_registry_slugs = array();
        foreach ( $project_registry as $project ) {
            if ( isset( $project['active'] ) && ! $project['active'] ) {
                continue;
            }
            $slug = (string) ( $project['project_slug'] ?? '' );
            if ( $slug !== '' ) {
                $active_registry_slugs[] = $slug;
            }
        }

        $projects_without_operator = array_values( array_diff( $active_registry_slugs, $slugs_with_operator ) );
        $lead_project_slugs = array();
        $leads_table = $wpdb->prefix . 'es_leads';
        $leads_table_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $leads_table ) );
        if ( $leads_table_exists ) {
            $lead_project_slugs = $wpdb->get_col( "SELECT DISTINCT project_slug FROM {$leads_table} WHERE project_slug <> '' ORDER BY project_slug ASC" );
            $lead_project_slugs = is_array( $lead_project_slugs ) ? array_map( 'strval', $lead_project_slugs ) : array();
        }
        $lead_projects_without_operator = array_values( array_diff( $lead_project_slugs, $slugs_with_operator ) );

        if ( ! $registry_ok ) {
            $audit_state = 'FAIL';
        } elseif ( $ops_warnings > 0 || ! empty( $projects_without_operator ) || ! empty( $lead_projects_without_operator ) ) {
            $audit_state = 'WARNING';
        } else {
            $audit_state = 'PASS';
        }

        $banner_colors = array(
            'PASS'    => array( 'border' => '#00a32a', 'bg' => '#f0fdf4', 'badge_bg' => '#00a32a', 'badge_text' => '#fff' ),
            'WARNING' => array( 'border' => '#dba617', 'bg' => '#fffbeb', 'badge_bg' => '#dba617', 'badge_text' => '#fff' ),
            'FAIL'    => array( 'border' => '#d63638', 'bg' => '#fef2f2', 'badge_bg' => '#d63638', 'badge_text' => '#fff' ),
        );
        $bc = $banner_colors[ $audit_state ];
        $core_projects_admin_active = class_exists( 'Estandar_Project_Registry_Admin' );
        $manage_url      = $core_projects_admin_active
            ? admin_url( 'admin.php?page=estandar_projects' )
            : admin_url( 'admin.php?page=estandar-chatbot&tab=leads-captacion' );
        $manage_label    = 'Administrar proyectos →';
        $origen_label    = $core_projects_admin_active ? 'Estandar → Proyectos' : 'Chatbot → Captura y salida';
        $chatbot_active  = function_exists( 'estandar_chatbot_get_project_registry' );
        $chatbot_page_url = admin_url( 'admin.php?page=estandar-chatbot' );
        $operator_lead_counts = self::operator_lead_counts();
        $orphan_operator_report = self::orphan_operator_report();
        $editing = null;
        $edit_id = absint($_GET['edit_operator'] ?? 0);
        if ($edit_id > 0) {
            $editing = $wpdb->get_row($wpdb->prepare(
                "SELECT id, name, email, project_slug, area, whatsapp_number, active, access_token_hash, access_token_expires_at, allowed_projects_json, operational_hours_json FROM {$wpdb->prefix}es_operators WHERE id = %d LIMIT 1",
                $edit_id
            ));
        }

        return compact(
            'operators',
            'project_registry',
            'registry_slugs',
            'focus_project_slug',
            'diagnostic',
            'return_to',
            'return_project',
            'focus_project_label',
            'registry_count',
            'registry_ok',
            'ops_warnings',
            'slugs_with_operator',
            'active_operators',
            'active_registry_slugs',
            'projects_without_operator',
            'lead_project_slugs',
            'lead_projects_without_operator',
            'banner_colors',
            'bc',
            'core_projects_admin_active',
            'manage_url',
            'manage_label',
            'origen_label',
            'chatbot_active',
            'chatbot_page_url',
            'operator_lead_counts',
            'orphan_operator_report',
            'editing'
        );
    }

    private static function allowed_projects_from_operator( $operator ): array
    {
        $decoded = json_decode( (string) ( $operator->allowed_projects_json ?? '' ), true );
        $projects = Estandar_Leads_Operator_Workspace::sanitize_projects( is_array( $decoded ) ? $decoded : array() );
        if ( empty( $projects ) && ! empty( $operator->project_slug ) ) {
            $projects = Estandar_Leads_Operator_Workspace::sanitize_projects( array( $operator->project_slug ) );
        }

        return $projects;
    }

    public static function save_operator()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Sin permisos.');
        }
        check_admin_referer('estandar_leads_save_operator');

        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $operator_id      = absint($_POST['operator_id'] ?? 0);
        $name             = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $email            = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $area             = sanitize_text_field(wp_unslash($_POST['area'] ?? ''));
        $allowed_projects = Estandar_Leads_Operator_Workspace::sanitize_projects(wp_unslash($_POST['allowed_projects'] ?? array()));
        if ($operator_id > 0 && empty($allowed_projects)) {
            $existing_allowed = $wpdb->get_row($wpdb->prepare(
                "SELECT project_slug, allowed_projects_json FROM {$wpdb->prefix}es_operators WHERE id = %d LIMIT 1",
                $operator_id
            ));
            if ($existing_allowed) {
                $decoded_existing = json_decode((string) ($existing_allowed->allowed_projects_json ?? ''), true);
                $allowed_projects = Estandar_Leads_Operator_Workspace::sanitize_projects(is_array($decoded_existing) ? $decoded_existing : array());
                if (empty($allowed_projects) && !empty($existing_allowed->project_slug)) {
                    $allowed_projects = Estandar_Leads_Operator_Workspace::sanitize_projects(array($existing_allowed->project_slug));
                }
            }
        }
        $project_slug     = $allowed_projects[0] ?? Estandar_Leads_Projects::normalize_slug(wp_unslash($_POST['project_slug'] ?? ''), $area);
        $whatsapp_number  = preg_replace('/\D+/', '', wp_unslash($_POST['whatsapp_number'] ?? ''));
        $active           = empty($_POST['active']) ? 0 : 1;
        $operational_hours_enabled = empty($_POST['operational_hours_enabled']) ? 0 : 1;
        $operational_hours_tz      = sanitize_text_field( wp_unslash( $_POST['operational_hours_tz'] ?? 'America/Santiago' ) );
        $operational_hours_days    = wp_unslash( $_POST['operational_hours_days'] ?? array() );

        $hours_config = null;
        if ( $operational_hours_enabled ) {
            $schedule = array();
            foreach ( array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ) as $day ) {
                $day_data = is_array( $operational_hours_days[ $day ] ?? null ) ? $operational_hours_days[ $day ] : array();
                $start = preg_match( '/^\d{1,2}:\d{2}$/', (string) ( $day_data['start'] ?? '' ) ) ? $day_data['start'] : '';
                $end   = preg_match( '/^\d{1,2}:\d{2}$/', (string) ( $day_data['end'] ?? '' ) ) ? $day_data['end'] : '';
                if ( $start !== '' && $end !== '' ) {
                    $schedule[ $day ] = array( 'start' => $start, 'end' => $end );
                } else {
                    $schedule[ $day ] = null;
                }
            }
            $hours_config = class_exists( 'Estandar_Operational_Hours' )
                ? Estandar_Operational_Hours::normalize_config( array(
                    'enabled'  => true,
                    'timezone' => $operational_hours_tz,
                    'schedule' => $schedule,
                ) )
                : array( 'enabled' => true, 'timezone' => $operational_hours_tz, 'schedule' => $schedule );
        } else {
            $hours_config = array( 'enabled' => false, 'timezone' => $operational_hours_tz, 'schedule' => array() );
        }
        $hours_json = wp_json_encode( $hours_config );

        if ($name !== '') {
            // Detect hours disable for queue release
            $was_enabled = false;
            if ( $operator_id > 0 ) {
                $old_row = $wpdb->get_row( $wpdb->prepare(
                    "SELECT operational_hours_json FROM {$wpdb->prefix}es_operators WHERE id = %d LIMIT 1",
                    $operator_id
                ) );
                if ( $old_row ) {
                    $old_config = json_decode( (string) ( $old_row->operational_hours_json ?? '' ), true );
                    $was_enabled = ! empty( $old_config['enabled'] );
                }
            }

            if ($operator_id > 0) {
                $wpdb->update($wpdb->prefix . 'es_operators', array(
                    'name'            => $name,
                    'email'           => $email,
                    'project_slug'    => $project_slug,
                    'area'            => $area,
                    'whatsapp_number' => $whatsapp_number,
                    'allowed_projects_json' => wp_json_encode($allowed_projects),
                    'operational_hours_json' => $hours_json,
                    'active'          => $active,
                    'updated_at'      => $now,
                ), array('id' => $operator_id), array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s'), array('%d'));
            } else {
                $wpdb->insert($wpdb->prefix . 'es_operators', array(
                    'name'            => $name,
                    'email'           => $email,
                    'project_slug'    => $project_slug,
                    'area'            => $area,
                    'whatsapp_number' => $whatsapp_number,
                    'allowed_projects_json' => wp_json_encode($allowed_projects),
                    'operational_hours_json' => $hours_json,
                    'active'          => $active,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ));
                $operator_id = (int) $wpdb->insert_id;
            }

            // Queue release: if operator had hours enabled and now disabled,
            // send all pending deferred notifications immediately.
            if ( $was_enabled && ! $operational_hours_enabled && class_exists( 'Estandar_Leads_Operator_Hours' ) ) {
                Estandar_Leads_Operator_Hours::release_pending_for_operator( $operator_id );
            }

            if ($operator_id > 0 && class_exists('Estandar_Leads_Commercial')) {
                Estandar_Leads_Commercial::ensure_operator_uuid($operator_id);
            }
            if ($operator_id > 0) {
                Estandar_Leads_Operator_Workspace::ensure_token($operator_id);
            }
        }

        // Contextual return: if invoked from the Project Registry "crear operador
        // para este proyecto" CTA, go back to that project's ficha instead of settings.
        $return_to      = sanitize_key(wp_unslash($_POST['return_to'] ?? ''));
        $return_project = sanitize_title(wp_unslash($_POST['return_project'] ?? ''));
        if ($return_to === 'project' && $return_project !== '') {
            $projects_page = class_exists('Estandar_Project_Registry_Admin')
                ? Estandar_Project_Registry_Admin::PAGE_SLUG
                : 'estandar_projects';
            wp_safe_redirect(add_query_arg(array(
                'page'             => $projects_page,
                'action'           => 'edit',
                'project_id'       => $return_project,
                'operator_created' => 1,
            ), admin_url('admin.php')));
            exit;
        }

        wp_safe_redirect(admin_url('admin.php?page=estandar_projects_operators&updated=1'));
        exit;
    }

    public static function rotate_operator_token()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Sin permisos.');
        }
        check_admin_referer('estandar_leads_rotate_operator_token');

        $operator_id = absint($_POST['operator_id'] ?? 0);
        if ($operator_id > 0) {
            Estandar_Leads_Operator_Workspace::create_token($operator_id);
        }

        wp_safe_redirect(admin_url('admin.php?page=estandar_projects_operators&updated=1'));
        exit;
    }

    public static function view_operator_workspace()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Sin permisos.');
        }
        check_admin_referer('estandar_leads_view_operator_workspace');

        $operator_id = absint($_POST['operator_id'] ?? 0);
        if ($operator_id === 0 || !class_exists('Estandar_Leads_Operator_Workspace')) {
            wp_safe_redirect(admin_url('admin.php?page=estandar_projects_operators'));
            exit;
        }

        $token = Estandar_Leads_Operator_Workspace::create_token($operator_id);
        if ($token === '') {
            wp_safe_redirect(admin_url('admin.php?page=estandar_projects_operators'));
            exit;
        }

        wp_safe_redirect(Estandar_Leads_Operator_Workspace::workspace_url($token));
        exit;
    }

    public static function save_whatsapp_settings()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Sin permisos.');
        }
        check_admin_referer('estandar_leads_save_whatsapp_settings');

        $message = sanitize_textarea_field(wp_unslash($_POST['whatsapp_prefill_message'] ?? ''));
        update_option('estandar_leads_whatsapp_prefill_message', $message, false);

        wp_safe_redirect(admin_url('admin.php?page=estandar-leads-settings&whatsapp_saved=1'));
        exit;
    }

    public static function save_context_gap_settings()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Sin permisos.');
        }
        check_admin_referer('estandar_leads_save_context_gap_settings');
        Estandar_Leads_Repository::save_context_gap_template($_POST);
        wp_safe_redirect(admin_url('admin.php?page=estandar-leads-settings&context_gap_saved=1'));
        exit;
    }

    /**
     * Save canonical commercial qualification rules (payload v0 / H-L).
     * Additive, non-destructive: validates and normalizes the JSON payload
     * against the shared rule contract before writing the option. No lead,
     * entry, conversation or setting is touched.
     */
    public static function save_qualification_rules()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Sin permisos.');
        }
        check_admin_referer('estandar_leads_save_qualification_rules');
        $raw = isset($_POST['rules_json']) ? wp_unslash((string) $_POST['rules_json']) : '';
        $rules = json_decode($raw, true);
        if (!is_array($rules)) {
            wp_safe_redirect(admin_url('admin.php?page=estandar-leads-settings&rules_error=1'));
            exit;
        }
        $stored = array('global' => array(), 'project' => array(), 'manual' => array());
        foreach (array('global', 'project', 'manual') as $bucket) {
            foreach ((array) ($rules[$bucket] ?? array()) as $rule) {
                if (!is_array($rule)) {
                    continue;
                }
                $normalized = class_exists('Estandar_Commercial_Qualification_Rules')
                    ? Estandar_Commercial_Qualification_Rules::normalize_rule($rule, $bucket)
                    : null;
                if ($normalized !== null) {
                    $stored[$bucket][] = $normalized;
                }
            }
        }
        update_option(Estandar_Commercial_Qualification_Rules::OPTION, $stored, false);
        // Sync v2 option so both engines read from the same payload (2026-08-05).
        if ( is_array( $stored ) && ! empty( $stored['global'] ) ) {
            update_option( 'estandar_suite_brain_rules', array( 'global' => $stored['global'], 'manual' => array() ), false );
        }
        wp_safe_redirect(admin_url('admin.php?page=estandar-leads-settings&rules_saved=1'));
        exit;
    }

    /** Reset commercial qualification rules to none (restores default decision). */
    public static function reset_qualification_rules()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Sin permisos.');
        }
        check_admin_referer('estandar_leads_reset_qualification_rules');
        delete_option(Estandar_Commercial_Qualification_Rules::OPTION);
        delete_option( 'estandar_suite_brain_rules' );  // v2 parity (2026-08-05)
        wp_safe_redirect(admin_url('admin.php?page=estandar-leads-settings&rules_reset=1'));
        exit;
    }

    public static function save_ads_settings()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Sin permisos.');
        }
        check_admin_referer('estandar_leads_save_ads_settings');

        ( new Estandar_Google_Ads_Destination() )->save_settings( $_POST );

        wp_safe_redirect(admin_url('admin.php?page=estandar-leads-settings&ads_saved=1'));
        exit;
    }

    public static function save_meta_settings()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Sin permisos.');
        }
        check_admin_referer('estandar_leads_save_meta_settings');

        Estandar_Leads_Meta_Adapter::save_settings($_POST);

        wp_safe_redirect(admin_url('admin.php?page=estandar-leads-settings&meta_saved=1'));
        exit;
    }

    /** LEAD_ASSESSMENT_V1 — save the fit indicator settings (admin-post). */
    public static function save_fit_indicator_settings()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Sin permisos.');
        }
        check_admin_referer('estandar_leads_save_fit_indicator_settings');

        if (class_exists('Estandar_Leads_Fit_Indicator')) {
            Estandar_Leads_Fit_Indicator::save_settings(is_array($_POST) ? $_POST : array());
        }

        wp_safe_redirect(admin_url('admin.php?page=estandar-leads-settings&fit_saved=1'));
        exit;
    }

    /** LEAD_ASSESSMENT_V1 — save the AI assessment settings (admin-post). */
    public static function save_assessment_ai_settings()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Sin permisos.');
        }
        check_admin_referer('estandar_leads_save_assessment_ai_settings');

        if (class_exists('Estandar_Leads_Assessment_AI')) {
            Estandar_Leads_Assessment_AI::save_settings(is_array($_POST) ? $_POST : array());
        }

        wp_safe_redirect(admin_url('admin.php?page=estandar-leads-settings&ai_saved=1'));
        exit;
    }

    public static function ajax_meta_diagnostics()
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Sin permisos.' ), 403 );
        }
        check_ajax_referer( 'estandar_leads_meta_diagnostics' );

        if ( ! class_exists( 'Estandar_Leads_Meta_Adapter' )
            || ! class_exists( 'Estandar_Leads_Meta_Graph_Client' )
            || ! class_exists( 'Estandar_Leads_Meta_Payload_Builder' ) ) {
            wp_send_json_error( array( 'message' => 'Clases Meta no disponibles.' ) );
        }

        $cfg = Estandar_Leads_Meta_Adapter::get_settings();

        // ── Step 1: Configuration ─────────────────────────────────────────────
        $pixel_ok = $cfg['pixel_id']     !== '';
        $token_ok = $cfg['access_token'] !== '';
        $config   = array(
            'pixel' => $pixel_ok ? 'PASS' : 'FAIL',
            'token' => $token_ok ? 'PASS' : 'FAIL',
        );

        // ── Step 2: Format validation ─────────────────────────────────────────
        // Pixel ID: 10–20 digits. Token: starts with 'EAA' or 100+ chars.
        $pixel_fmt = $pixel_ok && preg_match( '/^\d{10,20}$/', $cfg['pixel_id'] )       ? 'PASS' : ( $pixel_ok ? 'FAIL' : 'N/A' );
        $token_fmt = $token_ok && ( str_starts_with( $cfg['access_token'], 'EAA' ) || strlen( $cfg['access_token'] ) >= 100 ) ? 'PASS' : ( $token_ok ? 'FAIL' : 'N/A' );
        $format    = array( 'pixel' => $pixel_fmt, 'token' => $token_fmt );

        // ── Step 3: Payload build in-memory (sintético — jamás datos reales) ──
        // El MISMO payload que se envía en Step 4: CAPI exige user_data con al
        // menos un parámetro de cliente y event_source_url con action_source
        // 'website' — el builder canónico los produce (em = SHA-256 del email
        // sintético normalizado; event_source_url = home_url).
        $synth                    = new stdClass();
        $synth->email             = 'diagnostic@example.com';
        $synth->first_landing_url = home_url( '/' );
        $synth->event_time        = (string) time();

        $payload_built = Estandar_Leads_Meta_Payload_Builder::build( $synth, 'CAPTURED' );
        $payload_ok    = ! empty( $payload_built ) && isset( $payload_built['user_data']['em'], $payload_built['event_source_url'] );
        $payload       = array(
            'build'            => ! empty( $payload_built )                     ? 'PASS' : 'FAIL',
            'event_name'       => ( $payload_built['event_name'] ?? '' ) === 'Lead' ? 'PASS' : 'FAIL',
            'event_time'       => isset( $payload_built['event_time'] )         ? 'PASS' : 'FAIL',
            'user_data'        => isset( $payload_built['user_data']['em'] )    ? 'PASS' : 'FAIL',
            'event_source_url' => isset( $payload_built['event_source_url'] )   ? 'PASS' : 'FAIL',
        );

        // ── Step 4: Connectivity — envía el payload válido construido arriba ──
        $http = null; $latency = null; $events_received = null;
        $fbtrace_id = null; $error = null;

        if ( $pixel_ok && $token_ok && $payload_ok ) {
            // test_event_code lo aplica el Graph Client (campo top-level del
            // request, por spec de Meta) — nunca dentro del evento. Diagnóstico
            // directo al client: no encola, no toca leads ni outcomes.
            $diag_id = 'diagnostic-' . wp_generate_uuid4();

            $t0       = microtime( true );
            $response = Estandar_Leads_Meta_Graph_Client::send( $payload_built, $diag_id );
            $latency  = (int) round( ( microtime( true ) - $t0 ) * 1000 );
            $http     = $response['http_code'] ?? 0;
            $raw      = $response['body'] ?? '';
            $body     = $raw !== '' ? json_decode( $raw, true ) : null;

            if ( is_array( $body ) ) {
                $events_received = $body['events_received'] ?? null;
                $fbtrace_id      = $body['fbtrace_id']      ?? null;
                if ( isset( $body['error'] ) ) {
                    // Sanitizado: los mensajes de Meta pueden traer markup.
                    $error = array(
                        'code'       => $body['error']['code'] ?? null,
                        'message'    => isset( $body['error']['message'] )
                            ? sanitize_text_field( (string) $body['error']['message'] ) : null,
                        'user_title' => isset( $body['error']['error_user_title'] )
                            ? sanitize_text_field( (string) $body['error']['error_user_title'] ) : null,
                        'user_msg'   => isset( $body['error']['error_user_msg'] )
                            ? sanitize_text_field( (string) $body['error']['error_user_msg'] ) : null,
                    );
                }
            }
        }

        // ── Step 5: Overall status ────────────────────────────────────────────
        $conn_ok = ( $http === 200 );
        $status  = ( $pixel_ok && $token_ok && $conn_ok ) ? 'READY' : 'BLOCKED';
        $reason  = '';
        if ( ! $pixel_ok || ! $token_ok ) { $reason = 'Pixel ID o Access Token no configurados.'; }
        elseif ( ! $payload_ok )          { $reason = 'Payload de diagnóstico incompleto — revisar Payload Builder.'; }
        elseif ( ! $conn_ok )             { $reason = $error['user_msg'] ?? $error['message'] ?? ( 'HTTP ' . (string) $http ); }

        // ── Transient (TTL 5 min, no credentials, no payload) ────────────────
        set_transient( 'estandar_meta_diag_last', array(
            'timestamp'       => time(),
            'status'          => $status,
            'http'            => $http,
            'latency_ms'      => $latency,
            'events_received' => $events_received,
            'fbtrace_id'      => $fbtrace_id,
            'error'           => $error,
        ), 5 * MINUTE_IN_SECONDS );

        wp_send_json_success( array(
            'config'          => $config,
            'format'          => $format,
            'payload'         => $payload,
            'http'            => $http,
            'latency_ms'      => $latency,
            'events_received' => $events_received,
            'fbtrace_id'      => $fbtrace_id,
            'error'           => $error,
            'status'          => $status,
            'reason'          => $reason,
        ) );
    }

    public static function toggle_operator()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Sin permisos.');
        }
        check_admin_referer('estandar_leads_toggle_operator');

        global $wpdb;
        $operator_id = absint($_POST['operator_id'] ?? 0);
        $active = empty($_POST['active']) ? 0 : 1;
        if ($operator_id > 0) {
            $wpdb->update($wpdb->prefix . 'es_operators', array(
                'active' => $active,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ), array('id' => $operator_id), array('%d', '%s'), array('%d'));
        }

        wp_safe_redirect(admin_url('admin.php?page=estandar-leads-settings&updated=1'));
        exit;
    }

    public static function ajax_assign_operator()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Sin permisos.'), 403);
        }
        check_ajax_referer('estandar_assign_operator', 'nonce');

        global $wpdb;
        $lead_id    = absint($_POST['lead_id'] ?? 0);
        $operator_id = absint($_POST['operator_id'] ?? 0);

        if ($lead_id === 0) {
            wp_send_json_error(array('message' => 'Lead inválido.'), 400);
        }

        $lead_row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, assigned_operator_id, assigned_operator_name FROM {$wpdb->prefix}es_leads WHERE id = %d LIMIT 1",
            $lead_id
        ));
        if (!$lead_row) {
            wp_send_json_error(array('message' => 'Lead no encontrado.'), 404);
        }
        $from_operator_id   = (int) ($lead_row->assigned_operator_id ?? 0);
        $from_operator_name = (string) ($lead_row->assigned_operator_name ?? '');

        $operator_name  = '';
        $operator_email = '';

        if ($operator_id > 0) {
            $operator = $wpdb->get_row($wpdb->prepare(
                "SELECT id, name, email FROM {$wpdb->prefix}es_operators WHERE id = %d AND active = 1 LIMIT 1",
                $operator_id
            ));
            if (!$operator) {
                wp_send_json_error(array('message' => 'Operador no encontrado o inactivo.'), 404);
            }
            $operator_name  = (string) $operator->name;
            $operator_email = sanitize_email($operator->email);
        }

        $now = gmdate('Y-m-d H:i:s');

        if ($operator_id > 0) {
            $wpdb->update(
                $wpdb->prefix . 'es_leads',
                array(
                    'assigned_operator_id'    => $operator_id,
                    'assigned_operator_email' => $operator_email,
                    'assigned_operator_name'  => $operator_name,
                    'assigned_at'             => $now,
                ),
                array('id' => $lead_id),
                array('%d', '%s', '%s', '%s'),
                array('%d')
            );
        } else {
            // Clear assignment.
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}es_leads
                 SET assigned_operator_id = NULL,
                     assigned_operator_email = '',
                     assigned_operator_name  = '',
                     assigned_at = NULL
                 WHERE id = %d",
                $lead_id
            ));
        }

        // ACTOR_AUDIT_FIX_V2: trazabilidad de reasignación manual.
        // Additivo — no envía email, no dispara compliance, no toca assigned_at.
        // insert_event() adjunta actor_type='admin' + actor_user_id/actor_email
        // automáticamente (with_actor_context) por estar en sesión admin.
        if ($from_operator_id !== $operator_id) {
            Estandar_Leads_Repository::insert_event($lead_id, 'operator_reassigned', array(
                'operator_id' => $operator_id ?: 0,
                'metadata' => array(
                    'from_operator_id'   => $from_operator_id,
                    'from_operator_name' => $from_operator_name,
                    'to_operator_id'     => $operator_id,
                    'to_operator_name'   => $operator_name,
                    'source'             => 'manual',
                ),
            ));
        }

        wp_send_json_success(array(
            'operator_id'   => $operator_id,
            'operator_name' => $operator_name,
            'assigned_at'   => $operator_id > 0 ? $now : null,
        ));
    }

    public static function create_link()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Sin permisos.');
        }
        check_admin_referer('estandar_leads_create_link');

	        global $wpdb;
	        $lead_id = absint($_POST['lead_id'] ?? 0);
	        $redirect_to = ! empty($_POST['redirect_to'])
	            ? wp_validate_redirect((string) wp_unslash($_POST['redirect_to']), admin_url('admin.php?page=estandar-leads'))
	            : admin_url('admin.php?page=estandar-leads');

	        if ($lead_id === 0) {
	            wp_safe_redirect(add_query_arg('el_error', 'no_lead', $redirect_to));
	            exit;
	        }

        // Load the persisted operator assignment.
        $lead = $wpdb->get_row($wpdb->prepare(
            "SELECT l.id, l.name, l.email, l.phone, l.first_utm_campaign, l.first_utm_term, l.project_area, l.project_slug,
                    l.assigned_operator_id, l.quality,
                    (SELECT COUNT(*) FROM {$wpdb->prefix}es_lead_submissions s WHERE s.lead_id = l.id) AS submission_count
             FROM {$wpdb->prefix}es_leads l WHERE l.id = %d LIMIT 1",
            $lead_id
        ));

	        if (!$lead) {
	            wp_safe_redirect(add_query_arg('el_error', 'no_lead', $redirect_to));
	            exit;
	        }

        $operator_id = absint($lead->assigned_operator_id ?? 0);
	        if ($operator_id === 0) {
	            wp_safe_redirect(add_query_arg('el_error', 'no_operator', $redirect_to));
	            exit;
	        }

        // Load fresh operator data (email may have changed since assignment).
        $operator = $wpdb->get_row($wpdb->prepare(
            "SELECT id, name, email FROM {$wpdb->prefix}es_operators WHERE id = %d AND active = 1 LIMIT 1",
            $operator_id
        ));

	        if (!$operator) {
	            wp_safe_redirect(add_query_arg('el_error', 'operator_inactive', $redirect_to));
	            exit;
	        }

        $operator_email = sanitize_email($operator->email);
	        if ($operator_email === '') {
	            wp_safe_redirect(add_query_arg('el_error', 'no_operator_email', $redirect_to));
	            exit;
	        }

        $token = Estandar_Leads_Tokens::create($lead_id, $operator_id);
	        if ($token === '') {
	            wp_safe_redirect(add_query_arg('el_error', 'token_failed', $redirect_to));
	            exit;
	        }

        $feedback_url = Estandar_Leads_Tokens::url($token);
        $workspace_url = '';
        $workspace_backlog = array();
        if (class_exists('Estandar_Leads_Operator_Workspace')) {
            $workspace_token = Estandar_Leads_Operator_Workspace::create_token($operator_id);
            $workspace_url = $workspace_token !== '' ? Estandar_Leads_Operator_Workspace::workspace_url($workspace_token) : '';
            $workspace_backlog = Estandar_Leads_Operator_Workspace::operator_backlog($operator_id);
        }
        $latest_submission = $wpdb->get_row($wpdb->prepare(
            "SELECT id, context_json, source_plugin, source_form_id, source_entry_id, utm_source, utm_medium, utm_campaign, utm_term, utm_content, landing_url, submitted_at, message
             FROM {$wpdb->prefix}es_lead_submissions
             WHERE lead_id = %d
             ORDER BY submitted_at DESC
             LIMIT 1",
            $lead_id
        ));
        $submission_stats = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS submission_count, MIN(submitted_at) AS first_submitted_at, MAX(submitted_at) AS last_submitted_at
             FROM {$wpdb->prefix}es_lead_submissions
             WHERE lead_id = %d",
            $lead_id
        ));

        // Send email to the assigned operator using the configured HTML template.
        $es_settings = Estandar_Leads_Email::get_settings();
        $submission_count = (int) ($submission_stats->submission_count ?? $lead->submission_count ?? 0);
        $commercial_risk_signal = $submission_count > 1 && trim((string) ($lead->quality ?? '')) === '';
        $previous_status = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT from_status FROM {$wpdb->prefix}es_lead_events
             WHERE lead_id = %d AND event_type IN ('status_changed','status_overridden') AND from_status != ''
             ORDER BY created_at DESC, id DESC LIMIT 1",
            $lead_id
        ));
        $matched_customer = class_exists('Estandar_Leads_Customer_Service')
            ? Estandar_Leads_Customer_Service::get_by_lead((int) $lead_id)
            : null;
        $canonical_project = $latest_submission
            ? Estandar_Leads_Repository::canonical_project($lead, $latest_submission)
            : Estandar_Leads_Repository::canonical_project($lead, null);
        if ($canonical_project === Estandar_Leads_Repository::UNRESOLVED_PROJECT) {
            $canonical_project = '';
        }
        $html = Estandar_Leads_Email::render(array(
            'operator_name' => sanitize_text_field($operator->name),
            'operator_id'   => absint($operator_id),
            'lead_name'     => sanitize_text_field((string) $lead->name),
            'lead_email'    => sanitize_email((string) $lead->email),
            'lead_phone'    => sanitize_text_field((string) $lead->phone),
            'project_area'  => sanitize_text_field((string) ($lead->project_area ?? '')),
            'project_slug'  => sanitize_text_field((string) $canonical_project),
            'campaign'      => sanitize_text_field((string) ($latest_submission->utm_campaign ?? $lead->first_utm_campaign)),
            'ad'            => sanitize_text_field((string) ($latest_submission->utm_content ?? $lead->first_utm_content)),
            'keyword'       => sanitize_text_field((string) ($latest_submission->utm_term ?? $lead->first_utm_term)),
            'utm_source'    => sanitize_text_field((string) ($latest_submission->utm_source ?? '')),
            'utm_medium'    => sanitize_text_field((string) ($latest_submission->utm_medium ?? '')),
            'landing_url'   => esc_url_raw((string) ($latest_submission->landing_url ?? '')),
            'submitted_at'  => sanitize_text_field((string) ($latest_submission->submitted_at ?? '')),
            'submission_count' => $submission_count,
            'lead_type' => $submission_count > 1 ? 'Recurrente' : 'Nuevo',
            'submission_position' => $submission_count,
            'previous_status' => sanitize_text_field($previous_status),
            'customer_matched' => !empty($matched_customer),
            'first_submitted_at' => sanitize_text_field((string) ($submission_stats->first_submitted_at ?? '')),
            'last_submitted_at' => sanitize_text_field((string) ($submission_stats->last_submitted_at ?? '')),
            'commercial_risk_signal' => $commercial_risk_signal,
            'message'       => sanitize_textarea_field((string) ($latest_submission->message ?? '')),
            'lead_id'       => absint($lead_id),
            'submission_id' => absint($latest_submission->id ?? 0),
            'source_plugin' => sanitize_key((string) ($latest_submission->source_plugin ?? $lead->latest_source_plugin ?? $lead->first_source_plugin ?? '')),
            'source_form_id' => sanitize_text_field((string) ($latest_submission->source_form_id ?? '')),
            'source_entry_id' => absint($latest_submission->source_entry_id ?? 0),
            'feedback_url'  => $feedback_url,
            'workspace_url' => $workspace_url,
            'workspace_backlog' => $workspace_backlog,
            'consolidated_sheet_url' => esc_url_raw((string) get_option('estandar_leads_consolidated_sheet_url', '')),
        ), $es_settings);
        Estandar_Leads_Email::send(
            $operator_email,
            sanitize_text_field($es_settings['subject']),
            $html,
            $es_settings
        );

        // Show the link and a confirmation notice in the admin.
        set_transient(
            'estandar_leads_link_' . get_current_user_id(),
            $feedback_url . '||' . sanitize_text_field($operator->name),
            300
        );

	        wp_safe_redirect($redirect_to);
	        exit;
	    }

    public static function fix_admin_title( $admin_title, $title )
    {
        $page_titles = array(
            'estandar-leads-settings' => 'Configuración — Estandar Leads',
            'estandar-leads-reports'  => 'Reportes — Estandar Leads',
            'estandar-leads-email'    => 'Email — Estandar Leads',
            'estandar-leads-autoresponse' => 'Autorespuesta — Estandar Leads',
        );
        $page = sanitize_key( (string) ( $_GET['page'] ?? '' ) );
        if ( isset( $page_titles[ $page ] ) ) {
            return $page_titles[ $page ] . ' &lsaquo; ' . get_bloginfo( 'name' );
        }
        return $admin_title;
    }

    public static function delete_operator()
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'No autorizado.' );
        }
        check_admin_referer( 'estandar_leads_delete_operator' );

        global $wpdb;
        $operator_id = absint( $_POST['operator_id'] ?? 0 );

        if ( $operator_id > 0 ) {
            $lead_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}es_leads WHERE assigned_operator_id = %d",
                $operator_id
            ) );
            if ( $lead_count > 0 && empty( $_POST['force_delete'] ) ) {
                $wpdb->update(
                    $wpdb->prefix . 'es_operators',
                    array(
                        'active'     => 0,
                        'updated_at' => gmdate( 'Y-m-d H:i:s' ),
                    ),
                    array( 'id' => $operator_id ),
                    array( '%d', '%s' ),
                    array( '%d' )
                );
                wp_safe_redirect( admin_url( 'admin.php?page=estandar_projects_operators&historical_operator=1&operator_leads=' . $lead_count ) );
                exit;
            }
            $wpdb->delete(
                $wpdb->prefix . 'es_operators',
                array( 'id' => $operator_id ),
                array( '%d' )
            );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=estandar_projects_operators&deleted=1' ) );
        exit;
    }

    public static function recover_orphan_operators()
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'No autorizado.' );
        }
        check_admin_referer( 'estandar_leads_recover_orphan_operators' );

        global $wpdb;
        $updated = 0;
        foreach ( self::orphan_operator_report()['recoverable_rows'] as $row ) {
            $operator = $row['recovery_operator'];
            $affected = (int) $wpdb->update(
                $wpdb->prefix . 'es_leads',
                array(
                    'assigned_operator_id'    => (int) $operator->id,
                    'assigned_operator_name'  => sanitize_text_field( (string) $operator->name ),
                    'assigned_operator_email' => sanitize_email( (string) $operator->email ),
                ),
                array( 'id' => (int) $row['id'] ),
                array( '%d', '%s', '%s' ),
                array( '%d' )
            );
            $updated += $affected;

            // ACTOR_AUDIT_FIX_V2: trazabilidad de recuperación de huérfanos.
            // Additivo — no envía email, no dispara compliance, no toca assigned_at.
            if ( $affected > 0 ) {
                Estandar_Leads_Repository::insert_event( (int) $row['id'], 'operator_reassigned', array(
                    'operator_id' => (int) $operator->id,
                    'metadata' => array(
                        'from_operator_id'   => (int) $row['assigned_operator_id'],
                        'from_operator_name' => (string) $row['assigned_operator_name'],
                        'to_operator_id'     => (int) $operator->id,
                        'to_operator_name'   => (string) $operator->name,
                        'source'             => 'orphan_recovery',
                    ),
                ) );
            }
        }

        wp_safe_redirect( admin_url( 'admin.php?page=estandar_projects_operators&orphan_recovered=' . $updated ) );
        exit;
    }

    private static function operator_lead_counts()
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT assigned_operator_id, COUNT(*) AS leads
             FROM {$wpdb->prefix}es_leads
             WHERE assigned_operator_id IS NOT NULL AND assigned_operator_id != 0
             GROUP BY assigned_operator_id",
            OBJECT_K
        );

        $counts = array();
        foreach ( $rows as $operator_id => $row ) {
            $counts[(int) $operator_id] = (int) $row->leads;
        }
        return $counts;
    }

    private static function orphan_operator_report()
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT l.id, l.project_slug, l.assigned_operator_id, l.assigned_operator_name, l.assigned_operator_email, l.created_at
             FROM {$wpdb->prefix}es_leads l
             LEFT JOIN {$wpdb->prefix}es_operators o ON o.id = l.assigned_operator_id
             WHERE l.assigned_operator_id IS NOT NULL
               AND l.assigned_operator_id != 0
               AND o.id IS NULL
             ORDER BY l.created_at ASC",
            ARRAY_A
        );
        $operators = $wpdb->get_results(
            "SELECT id, name, email, project_slug, area, allowed_projects_json
             FROM {$wpdb->prefix}es_operators
             WHERE active = 1 AND email <> ''
             ORDER BY name ASC"
        );

        $by_project = array();
        $by_orphan_operator = array();
        $recoverable = array();
        foreach ( $rows as $row ) {
            $project = sanitize_key( (string) ( $row['project_slug'] ?? '' ) );
            $project_key = $project !== '' ? $project : '(sin proyecto)';
            $by_project[$project_key] = ( $by_project[$project_key] ?? 0 ) + 1;

            $orphan_key = (int) $row['assigned_operator_id'] . '|' . (string) $row['assigned_operator_name'] . '|' . (string) $row['assigned_operator_email'];
            if ( ! isset( $by_orphan_operator[$orphan_key] ) ) {
                $by_orphan_operator[$orphan_key] = array(
                    'operator_id' => (int) $row['assigned_operator_id'],
                    'name'        => (string) $row['assigned_operator_name'],
                    'email'       => (string) $row['assigned_operator_email'],
                    'count'       => 0,
                );
            }
            $by_orphan_operator[$orphan_key]['count']++;

            $match = self::recovery_operator_for_project( $operators, $project );
            if ( $match ) {
                $row['recovery_operator'] = $match;
                $recoverable[] = $row;
            }
        }

        arsort( $by_project );
        uasort( $by_orphan_operator, static function ( $a, $b ) {
            return (int) $b['count'] <=> (int) $a['count'];
        } );

        return array(
            'total'              => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}es_leads" ),
            'orphan_count'       => count( $rows ),
            'by_project'         => $by_project,
            'by_orphan_operator' => $by_orphan_operator,
            'recoverable_count'  => count( $recoverable ),
            'recoverable_rows'   => $recoverable,
        );
    }

    private static function recovery_operator_for_project( $operators, $project_slug )
    {
        $project_slug = Estandar_Leads_Projects::normalize_slug( $project_slug );
        if ( $project_slug === '' ) {
            return null;
        }

        $routing = Estandar_Leads_Operator_Resolver::resolve_for_project( $project_slug, array(
            'operators'         => $operators,
            'include_fallback'  => false,
        ) );

        if ( ! in_array( $routing['routing_mode'] ?? '', array( 'project_match', 'allowed_project_match' ), true ) ) {
            return null;
        }

        return count( $routing['operators'] ?? array() ) === 1 ? $routing['operators'][0] : null;
    }

    public static function update_lead_project()
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'No autorizado.' );
        }
        check_admin_referer( 'estandar_leads_update_project' );

        global $wpdb;

        $lead_id     = absint( $_POST['lead_id'] ?? 0 );
        $project_slug = sanitize_text_field( wp_unslash( $_POST['project_slug'] ?? '' ) );
        $redirect_to = esc_url_raw( wp_unslash( $_POST['redirect_to'] ?? '' ) );

        // LEAD_TRANSFER_V1 — formal transfer: active project + operator reassignment +
        // events (lead_transferred + legacy project_assigned_manually) via the service.
        if ( $lead_id > 0 && class_exists( 'Estandar_Leads_Transfer_Service' ) ) {
            Estandar_Leads_Transfer_Service::transfer( $lead_id, $project_slug, array(
                'reason' => sanitize_text_field( wp_unslash( $_POST['transfer_reason'] ?? '' ) ),
            ) );
        }

        $base = admin_url( 'admin.php?page=estandar-leads&view=detail&lead_id=' . $lead_id );
        wp_safe_redirect( $redirect_to !== '' ? $redirect_to : $base );
        exit;
    }

    public static function bulk_delete()
    {
        if (!current_user_can('manage_options')) {
            wp_die('No autorizado.');
        }

        check_admin_referer('estandar_leads_bulk_delete');

        $raw_ids = $_POST['lead_ids'] ?? [];
        if (!is_array($raw_ids) || empty($raw_ids)) {
            wp_safe_redirect(add_query_arg('el_bulk', 'empty', wp_get_referer() ?: admin_url('admin.php?page=estandar-leads')));
            exit;
        }

        $ids = array_filter(array_map('absint', $raw_ids));
        if (empty($ids)) {
            wp_safe_redirect(add_query_arg('el_bulk', 'empty', wp_get_referer() ?: admin_url('admin.php?page=estandar-leads')));
            exit;
        }

        $bulk_action = sanitize_key( $_POST['bulk_action'] ?? '' );
        if ( in_array( $bulk_action, array( 'export_basic', 'export_ai_context', 'export_ai_context_jsonl' ), true ) ) {
            self::bulk_export_csv( $ids, $bulk_action );
        }
        if ( $bulk_action !== 'delete' ) {
            wp_safe_redirect(add_query_arg('el_bulk', 'empty', wp_get_referer() ?: admin_url('admin.php?page=estandar-leads')));
            exit;
        }

        global $wpdb;

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        // Borrar en cascada: events, submissions, classifications, contacts, tokens, leads
        foreach ([
            'es_lead_events',
            'es_lead_submissions',
            'es_lead_classifications',
            'es_lead_contacts',
            'es_feedback_tokens',
        ] as $child_table) {
            $table = $wpdb->prefix . $child_table;
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE lead_id IN ({$placeholders})", $ids));
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $deleted = $wpdb->query(
            $wpdb->prepare("DELETE FROM {$wpdb->prefix}es_leads WHERE id IN ({$placeholders})", $ids)
        );

        $redirect = remove_query_arg(['el_bulk', 'el_deleted'], wp_get_referer() ?: admin_url('admin.php?page=estandar-leads'));
        wp_safe_redirect( add_query_arg( [
            'el_bulk'    => 'deleted',
            'el_deleted' => (int) $deleted,
        ], $redirect ) );
        exit;
    }

    private static function bulk_export_csv( array $ids, string $mode ): void
    {
        global $wpdb;

        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $sql = "SELECT
            l.id AS lead_id, l.lead_uuid, l.created_at, l.first_seen_at, l.last_seen_at,
            l.name, l.email, l.phone, l.project_slug AS proyecto, op.name AS operador,
            l.status AS estado_comercial, l.quality AS calidad, l.sales_stage,
            l.is_valid_lead, l.assigned_operator_id, l.first_contact_at,
            s.source_plugin, s.form_fields_json, s.utm_campaign, s.utm_medium, s.utm_source,
            s.fbclid, s.gclid, s.gbraid, s.wbraid, s.meta_fbc, s.meta_fbp
        FROM {$wpdb->prefix}es_leads l
        LEFT JOIN {$wpdb->prefix}es_operators op ON op.id = l.assigned_operator_id
        LEFT JOIN {$wpdb->prefix}es_lead_submissions s ON s.id = (
            SELECT s2.id FROM {$wpdb->prefix}es_lead_submissions s2
            WHERE s2.lead_id = l.id
            ORDER BY COALESCE(s2.submitted_at, s2.created_at) DESC, s2.id DESC
            LIMIT 1
        )
        WHERE l.id IN ({$placeholders})
        ORDER BY l.id DESC";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $ids ), ARRAY_A ) ?: array();

        nocache_headers();
        if ( $mode === 'export_ai_context_jsonl' ) {
            nocache_headers();
            header( 'Content-Type: application/x-ndjson; charset=utf-8' );
            header( 'Content-Disposition: attachment; filename="leads-seleccionados-contexto-ia-' . gmdate( 'Ymd-His' ) . '-' . count( $rows ) . 'rows.jsonl"' );
            $out = fopen( 'php://output', 'w' );
            self::export_ai_context_jsonl_rows( $out, $rows );
            fclose( $out );
            exit;
        }

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="leads-seleccionados-' . ( $mode === 'export_ai_context' ? 'contexto-ia' : 'basico' ) . '-' . gmdate( 'Ymd-His' ) . '-' . count( $rows ) . 'rows.csv"' );

        $out = fopen( 'php://output', 'w' );
        fprintf( $out, chr(0xEF) . chr(0xBB) . chr(0xBF) );

        if ( $mode === 'export_basic' ) {
            self::export_csv_client_rows( $out, $rows );
            fclose( $out );
            exit;
        }

        self::export_ai_context_csv_rows( $out, $rows );

        fclose( $out );
        exit;
    }

    private static function ai_context_export_headers(): array
    {
        return array(
            'lead_id', 'nombre', 'email', 'telefono', 'proyecto', 'operador',
            'estado', 'calidad', 'sales_stage', 'contexto_ia',
        );
    }

    private static function ai_context_export_row( array $row ): array
    {
        return array(
            'lead_id'      => (string) ( $row['lead_id'] ?? '' ),
            'nombre'       => (string) ( $row['name'] ?? '' ),
            'email'        => (string) ( $row['email'] ?? '' ),
            'telefono'     => (string) ( $row['phone'] ?? '' ),
            'proyecto'     => (string) ( $row['proyecto'] ?? '' ),
            'operador'     => (string) ( $row['operador'] ?? '' ),
            'estado'       => (string) ( $row['estado_comercial'] ?? '' ),
            'calidad'      => (string) ( $row['calidad'] ?? '' ),
            'sales_stage'  => (string) ( $row['sales_stage'] ?? '' ),
            'contexto_ia'  => class_exists( 'Estandar_Leads_AI_Context' ) ? (string) Estandar_Leads_AI_Context::build_for_lead( (int) ( $row['lead_id'] ?? 0 ), 'commercial' ) : '',
        );
    }

    private static function export_ai_context_csv_rows( $out, array $rows ): void
    {
        fputcsv( $out, self::ai_context_export_headers(), ',', '"', '' );
        foreach ( $rows as $row ) {
            $export_row = self::ai_context_export_row( (array) $row );
            fputcsv( $out, array_values( $export_row ), ',', '"', '' );
        }
    }

    private static function export_ai_context_jsonl_rows( $out, array $rows ): void
    {
        foreach ( $rows as $row ) {
            fwrite( $out, wp_json_encode( self::ai_context_export_row( (array) $row ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n" );
        }
    }

    public static function export_lead()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Sin permisos.');
        }
        check_admin_referer('estandar_leads_export_lead');

        $lead_id = absint($_GET['lead_id'] ?? 0);
        $format = sanitize_key($_GET['format'] ?? 'json');
        if ($lead_id === 0) {
            wp_die('Lead inválido.');
        }

        $detail = Estandar_Leads_Portal::get_lead_detail($lead_id);
        if (!$detail || empty($detail['lead'])) {
            wp_die('Lead no encontrado.');
        }

        $lead = $detail['lead'];
        $filename = 'lead-' . absint($lead->id) . '-' . gmdate('Ymd-His');

        if ($format === 'csv') {
            nocache_headers();
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '.csv"');

            $out = fopen('php://output', 'w');
            fputcsv($out, array('field', 'value'));
            foreach (array(
                'lead_id' => $lead->id,
                'lead_uuid' => $lead->lead_uuid ?? '',
                'name' => $lead->name ?? '',
                'email' => $lead->email ?? '',
                'phone' => $lead->phone ?? '',
                'status' => $lead->status ?? '',
                'quality' => $lead->quality ?? '',
                'operator' => $lead->current_operator_name ?: ($lead->assigned_operator_name ?? ''),
                'project_slug' => $lead->project_slug ?? '',
                'project_area' => $lead->project_area ?? '',
                'first_seen_at' => $lead->first_seen_at ?? '',
                'last_seen_at' => $lead->last_seen_at ?? '',
                'contact_attempts' => $lead->contact_attempts ?? '',
                'feedback_completed' => $lead->feedback_completed ?? '',
            ) as $field => $value) {
                fputcsv($out, array($field, $value));
            }
            fclose($out);
            exit;
        }

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.json"');
        echo wp_json_encode($detail, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function save_operation()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Sin permisos.');
        }
        check_admin_referer('estandar_leads_save_operation');

        global $wpdb;

        $lead_id = absint($_POST['lead_id'] ?? 0);
        $redirect_to = ! empty($_POST['redirect_to'])
            ? wp_validate_redirect((string) wp_unslash($_POST['redirect_to']), admin_url('admin.php?page=estandar-leads'))
            : admin_url('admin.php?page=estandar-leads');
        if ($lead_id === 0) {
            wp_safe_redirect(add_query_arg('el_error', 'no_lead', $redirect_to));
            exit;
        }

        $lead = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status, quality, sales_stage, invalid_reason, assigned_operator_id FROM {$wpdb->prefix}es_leads WHERE id = %d LIMIT 1",
            $lead_id
        ));
        if (!$lead) {
            wp_safe_redirect(add_query_arg('el_error', 'no_lead', $redirect_to));
            exit;
        }

        $status = strtoupper(sanitize_key(wp_unslash($_POST['status'] ?? '')));
        $quality = strtoupper(sanitize_key(wp_unslash($_POST['quality'] ?? '')));
        $commercial_stage = strtoupper(sanitize_key(wp_unslash($_POST['commercial_stage'] ?? '')));
        $lost_reason = strtoupper(sanitize_key(wp_unslash($_POST['lost_reason'] ?? '')));
        $contact_channel = sanitize_key(wp_unslash($_POST['contact_channel'] ?? ''));
        $contact_outcome = sanitize_text_field(wp_unslash($_POST['contact_outcome'] ?? ''));
        $context_gap = Estandar_Leads_Repository::sanitize_context_gap($_POST['context_gap'] ?? 'NO');
        $context_gap_meta = Estandar_Leads_Repository::context_gap_metadata($context_gap);
        $notes = sanitize_textarea_field(wp_unslash($_POST['notes'] ?? ''));
        $operator_id = absint($lead->assigned_operator_id ?? 0);
        $now = gmdate('Y-m-d H:i:s');

        $interaction_statuses = array(
            'CONTACTED',
            'QUALIFIED',
            'MEETING_BOOKED',
            'MEETING_SCHEDULED',
            'VISIT_SCHEDULED',
            'VISITED',
            'PROPOSAL_SENT',
            'LOST',
        );
        $requires_contact = in_array($status, $interaction_statuses, true)
            || in_array($commercial_stage, $interaction_statuses, true);

        if ($requires_contact && $contact_channel === '') {
            wp_safe_redirect(add_query_arg('el_error', 'contact_channel_required', $redirect_to));
            exit;
        }
        if ($requires_contact && $contact_outcome === '') {
            wp_safe_redirect(add_query_arg('el_error', 'contact_outcome_required', $redirect_to));
            exit;
        }

        // Quality validation — JUNK no longer requires a note (operator's judgment is sufficient).
        $quality_is_valid   = $quality !== '' && in_array($quality, Estandar_Leads_Email::qualities(), true);
        $quality_is_changed = $quality_is_valid && $quality !== (string) $lead->quality;

        // Lost reason handling.
        // If lost_reason = OTHER, use the free-text field as the stored reason.
        $lost_reason_other = sanitize_text_field(wp_unslash($_POST['lost_reason_other'] ?? ''));
        if ($lost_reason === 'OTHER' && $lost_reason_other !== '') {
            $lost_reason = 'OTHER: ' . $lost_reason_other;
        }
        $stage_labels = Estandar_Leads_Normalizer::commercial_stage_labels();
        $lost_labels  = Estandar_Leads_Normalizer::lost_reason_labels();
        $stage_is_valid = $commercial_stage !== '' && isset($stage_labels[$commercial_stage]);

        // Lost_reason is optional — operator can leave it empty even for LOST stage.
        // Validate only if a reason was explicitly provided.
        if ($lost_reason !== '' && !isset($lost_labels[$lost_reason]) && strpos($lost_reason, 'OTHER: ') !== 0) {
            $lost_reason = ''; // Discard invalid value silently.
        }
        $lost_stage_keys = array('LOST', 'DISQUALIFIED');
        if ($stage_is_valid && !in_array($commercial_stage, $lost_stage_keys, true)) {
            $lost_reason = ''; // Clear reason when stage is not a loss/disqualification.
        }

        $lead_data = array('updated_at' => $now);
        $formats = array('%s');

        if ($status !== '' && in_array($status, Estandar_Leads_Normalizer::statuses(), true)) {
            $lead_data['status'] = $status;
            $lead_data['feedback_completed'] = 1;
            $lead_data['latest_feedback_at'] = $now;
            $lead_data['latest_operator_id'] = $operator_id ?: null;
            $lead_data['latest_feedback_reason'] = $notes;
            $lead_data['last_classified_at'] = $now;
            $lead_data['last_classification_operator_id'] = $operator_id ?: null;
            $formats = array_merge($formats, array('%s', '%d', '%s', '%d', '%s', '%s', '%d'));
        }

        if ($quality_is_valid) {
            $lead_data['quality'] = $quality;
            $formats[] = '%s';
        }

        if ($stage_is_valid) {
            $lead_data['sales_stage'] = $commercial_stage;
            $lead_data['invalid_reason'] = $lost_reason;
            $mapped_status = Estandar_Leads_Normalizer::status_from_commercial_stage($commercial_stage);
            if ($status === '' && $mapped_status !== '') {
                $status = $mapped_status;
                $lead_data['status'] = $status;
                $lead_data['feedback_completed'] = 1;
                $lead_data['latest_feedback_at'] = $now;
                $lead_data['latest_operator_id'] = $operator_id ?: null;
                $lead_data['latest_feedback_reason'] = $notes;
                $lead_data['last_classified_at'] = $now;
                $lead_data['last_classification_operator_id'] = $operator_id ?: null;
                $formats = array_merge($formats, array('%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%d'));
            } else {
                $formats = array_merge($formats, array('%s', '%s'));
            }
        }

        if (count($lead_data) > 1) {
            $wpdb->update($wpdb->prefix . 'es_leads', $lead_data, array('id' => $lead_id), $formats, array('%d'));
        }

        if ($contact_channel !== '' || $contact_outcome !== '') {
            Estandar_Leads_Portal::log_contact(
                $lead_id,
                $operator_id,
                $contact_channel ?: 'manual',
                $contact_outcome ?: 'contacted',
                $notes,
                $context_gap_meta
            );
        }

        if ($status !== '' && in_array($status, Estandar_Leads_Normalizer::statuses(), true) && $status !== (string) $lead->status) {
            Estandar_Leads_Repository::insert_event($lead_id, 'status_changed', array(
                'operator_id' => $operator_id ?: null,
                'from_status' => (string) $lead->status,
                'to_status' => $status,
                'reason' => $notes,
                'metadata' => array(
                    'source' => 'operator_portal',
                    'lead_quality' => $quality,
                    'commercial_stage' => $commercial_stage,
                    'context_gap' => $context_gap_meta['context_gap'],
                    'context_gap_label' => $context_gap_meta['context_gap_label'],
                    'context_gap_question' => $context_gap_meta['context_gap_question'],
                ),
            ));

            // Notify subscribers (compliance engine, OCI, etc.) of the status transition.
            // Must mirror the signature used by class-estandar-leads-feedback.php.
            do_action( 'estandar_lead_status_changed', $lead_id, (string) $lead->status, $status, $now );
        }

        // BUG-001: auditar cambio de calidad en es_lead_events.
        if ($quality_is_changed) {
            Estandar_Leads_Repository::insert_event($lead_id, 'quality_changed', array(
                'operator_id' => $operator_id ?: null,
                'reason' => $notes,
                'metadata' => array(
                    'from_quality' => (string) $lead->quality,
                    'to_quality'   => $quality,
                    'context_gap'  => $context_gap_meta['context_gap'],
                    'context_gap_label' => $context_gap_meta['context_gap_label'],
                    'context_gap_question' => $context_gap_meta['context_gap_question'],
                    'source'       => 'operator_portal',
                ),
            ));
        }

        if ($stage_is_valid && $commercial_stage !== (string) $lead->sales_stage) {
            Estandar_Leads_Repository::insert_event($lead_id, 'commercial_stage_changed', array(
                'operator_id' => $operator_id ?: null,
                'reason' => $notes,
                'metadata' => array(
                    'from_stage' => (string) $lead->sales_stage,
                    'to_stage' => $commercial_stage,
                    'lost_reason' => $lost_reason,
                    'context_gap' => $context_gap_meta['context_gap'],
                    'context_gap_label' => $context_gap_meta['context_gap_label'],
                    'context_gap_question' => $context_gap_meta['context_gap_question'],
                    'source' => 'operator_portal',
                ),
            ));
        }

        if ($status !== '' || $quality_is_valid || $stage_is_valid) {
            $wpdb->insert($wpdb->prefix . 'es_lead_classifications', array(
                'lead_id'          => $lead_id,
                'operator_id'      => $operator_id ?: null,
                'invalid_reason'   => $lost_reason,
                'previous_quality' => (string) $lead->quality,
                'quality'          => $quality_is_valid ? $quality : (string) $lead->quality,
                'sales_stage'      => $stage_is_valid ? $commercial_stage : (string) $lead->sales_stage,
                'status'           => $status ?: (string) $lead->status,
                'contacted'        => ($contact_channel !== '' || $contact_outcome !== '') ? 1 : 0,
                'notes'            => $notes,
                'classified_at'    => $now,
            ));
        }

        wp_safe_redirect($redirect_to);
        exit;
    }

    public static function contact_action()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Sin permisos.');
        }
        check_admin_referer('estandar_leads_contact_action');

        global $wpdb;

        $lead_id = absint($_GET['lead_id'] ?? 0);
        $channel = sanitize_key(wp_unslash($_GET['channel'] ?? ''));
        $lead = $lead_id ? $wpdb->get_row($wpdb->prepare(
            "SELECT id, name, email, phone, project_slug, assigned_operator_id FROM {$wpdb->prefix}es_leads WHERE id = %d LIMIT 1",
            $lead_id
        )) : null;

        if (!$lead || !in_array($channel, array('whatsapp', 'email', 'call'), true)) {
            wp_safe_redirect(admin_url('admin.php?page=estandar-leads'));
            exit;
        }

        $event_map = array(
            'whatsapp' => 'whatsapp_clicked',
            'email'    => 'email_clicked',
            'call'     => 'call_clicked',
        );
        Estandar_Leads_Portal::log_portal_event($lead_id, $event_map[$channel], array(
            'project' => sanitize_key($lead->project_slug ?? ''),
            'channel' => $channel,
        ), absint($lead->assigned_operator_id ?? 0));

        $target = '';
        if ($channel === 'email' && !empty($lead->email)) {
            $subject = rawurlencode('Seguimiento ' . ($lead->name ?: 'lead'));
            $target = 'mailto:' . sanitize_email($lead->email) . '?subject=' . $subject;
        } elseif ($channel === 'call' && !empty($lead->phone)) {
            $target = 'tel:' . Estandar_Leads_Normalizer::phone($lead->phone);
        } elseif ($channel === 'whatsapp' && !empty($lead->phone)) {
            $target = 'https://wa.me/' . Estandar_Leads_Normalizer::phone_storage($lead->phone);
        }

        if ($target === '') {
            $target = admin_url('admin.php?page=estandar-leads&view=detail&lead_id=' . $lead_id);
        }

        wp_redirect($target);
        exit;
    }

    // -------------------------------------------------------------------------
    // Exports page — LEADS_EXPORT_CENTER_V1
    // -------------------------------------------------------------------------

    public static function exports()
    {
        self::render_shell('Exportaciones', array(__CLASS__, 'exports_content'));
    }

    public static function exports_content()
    {
        global $wpdb;

        // ── Operators for filter dropdown ──────────────────────────────────────
        $operators = $wpdb->get_results(
            "SELECT id, name FROM {$wpdb->prefix}es_operators WHERE active = 1 ORDER BY name ASC"
        );

        // ── Projects for filter dropdown ───────────────────────────────────────
        $projects = $wpdb->get_results(
            "SELECT DISTINCT project_slug FROM {$wpdb->prefix}es_leads WHERE project_slug != '' ORDER BY project_slug ASC"
        );

        // ── Parse filters ─────────────────────────────────────────────────────
        $f = self::exports_parse_filters();

        // Log preview filters with hash — must match export_filters hash in the CSV log.
        $filter_hash = substr( md5( wp_json_encode( $f ) ), 0, 8 );
        error_log( sprintf(
            '[estandar-leads] exports_preview: filter_hash=%s filters=%s user=%d',
            $filter_hash,
            wp_json_encode( $f ),
            get_current_user_id()
        ) );

        // ── Preview query (first 10 rows) ─────────────────────────────────────
        list( $sql, $vals ) = self::exports_build_query( $f );
        $count_sql  = "SELECT COUNT(*) FROM ({$sql}) AS _c";
        $total      = (int) $wpdb->get_var( $vals ? $wpdb->prepare( $count_sql, $vals ) : $count_sql );
        $preview    = $wpdb->get_results(
            $vals
                ? $wpdb->prepare( $sql . ' LIMIT 10', $vals )
                : $sql . ' LIMIT 10',
            ARRAY_A
        ) ?: array();

        require ESTANDAR_LEADS_PATH . 'templates/admin/exports.php';
    }

    /**
     * Parse export filters from a given input bag.
     * Pass $_GET for the preview page, $_POST for the CSV download handler.
     * Same logic, same defaults — single source of truth for filter parsing.
     *
     * @param array $input Associative array of raw input values (e.g. $_GET or $_POST).
     */
    private static function exports_parse_filters( array $input = array() ): array
    {
        if ( empty( $input ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $input = $_GET;
        }

        $presets = array(
            '24h'  => gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) ),
            '7d'   => gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) ),
            '30d'  => gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) ),
            '90d'  => gmdate( 'Y-m-d H:i:s', strtotime( '-90 days' ) ),
            'all'  => '',
        );
        $preset = isset( $input['preset'] ) ? sanitize_key( $input['preset'] ) : 'all';
        if ( ! isset( $presets[ $preset ] ) ) {
            $preset = 'all';
        }

        return array(
            'preset'       => $preset,
            'date_from'    => $preset !== 'all' ? $presets[ $preset ] : ( isset( $input['date_from'] ) ? sanitize_text_field( $input['date_from'] ) : '' ),
            'date_to'      => isset( $input['date_to'] ) ? sanitize_text_field( $input['date_to'] ) : '',
            'source'       => isset( $input['source'] ) ? sanitize_key( $input['source'] ) : '',
            'project'      => isset( $input['project'] ) ? sanitize_key( $input['project'] ) : '',
            'operator_id'  => isset( $input['operator_id'] ) ? (int) $input['operator_id'] : -1,
            'status'       => isset( $input['status'] ) ? sanitize_key( $input['status'] ) : '',
            'utm_source'   => isset( $input['utm_source'] ) ? sanitize_text_field( $input['utm_source'] ) : '',
            'utm_medium'   => isset( $input['utm_medium'] ) ? sanitize_text_field( $input['utm_medium'] ) : '',
            'utm_campaign' => isset( $input['utm_campaign'] ) ? sanitize_text_field( $input['utm_campaign'] ) : '',
        );
    }

    private static function exports_build_query( array $f ): array
    {
        global $wpdb;
        $l = $wpdb->prefix . 'es_leads';
        $s = $wpdb->prefix . 'es_lead_submissions';
        $o = $wpdb->prefix . 'es_operators';

        $wheres = array( '1=1' );
        $vals   = array();

        if ( $f['date_from'] !== '' ) {
            $wheres[] = "s.submitted_at >= %s";
            $vals[]   = $f['date_from'];
        }
        if ( $f['date_to'] !== '' ) {
            $wheres[] = "s.submitted_at <= %s";
            $vals[]   = $f['date_to'] . ' 23:59:59';
        }
        if ( $f['source'] !== '' ) {
            $wheres[] = "s.source_plugin = %s";
            $vals[]   = $f['source'];
        }
        if ( $f['project'] !== '' ) {
            $wheres[] = "l.project_slug = %s";
            $vals[]   = $f['project'];
        }
        if ( $f['operator_id'] === 0 ) {
            $wheres[] = "l.assigned_operator_id IS NULL OR l.assigned_operator_id = 0";
        } elseif ( $f['operator_id'] > 0 ) {
            $wheres[] = "l.assigned_operator_id = %d";
            $vals[]   = $f['operator_id'];
        }
        if ( $f['status'] !== '' ) {
            $status_map = array(
                'none'       => array( 'NEW' ),
                'contacted'  => array( 'CONTACTED', 'NO_RESPONSE' ),
                'qualified'  => array( 'QUALIFIED', 'MEETING_BOOKED', 'PROPOSAL_SENT', 'VISIT_SCHEDULED', 'VISITED' ),
                'reserved'   => array( 'RESERVED' ),
                'converted'  => array( 'DEED_SIGNED', 'DELIVERED' ),
                'lost'       => array( 'LOST', 'INVALID', 'SPAM' ),
            );
            if ( isset( $status_map[ $f['status'] ] ) ) {
                $phs      = implode( ',', array_fill( 0, count( $status_map[ $f['status'] ] ), '%s' ) );
                $wheres[] = "l.status IN ({$phs})";
                $vals     = array_merge( $vals, $status_map[ $f['status'] ] );
            }
        }
        if ( $f['utm_source'] !== '' ) {
            $wheres[] = "s.utm_source = %s";
            $vals[]   = $f['utm_source'];
        }
        if ( $f['utm_medium'] !== '' ) {
            $wheres[] = "s.utm_medium = %s";
            $vals[]   = $f['utm_medium'];
        }
        if ( $f['utm_campaign'] !== '' ) {
            $wheres[] = "s.utm_campaign = %s";
            $vals[]   = $f['utm_campaign'];
        }

        $where_sql = implode( ' AND ', $wheres );

        $sql = "SELECT
            l.id                    AS lead_id,
            s.id                    AS submission_id,
            l.lead_uuid,
            l.created_at,
            s.submitted_at,
            l.name,
            l.email,
            l.phone,
            l.project_slug          AS proyecto,
            op.name                 AS operador,
            s.source_plugin,
            s.source_form_id,
            s.form_fields_json,
            s.utm_source,
            s.utm_medium,
            s.utm_campaign,
            s.utm_term,
            s.utm_content,
            s.fbclid,
            s.gclid,
            s.landing_url,
            s.referrer,
            s.session_id,
            l.status                AS estado_comercial,
            l.quality               AS calidad,
            l.sales_stage,
            l.is_valid_lead,
            l.assigned_operator_id,
            l.first_contact_at,
            l.last_seen_at,
            s.gbraid,
            s.wbraid,
            s.meta_fbc,
            s.meta_fbp
        FROM {$s} s
        INNER JOIN {$l} l ON l.id = s.lead_id
        LEFT JOIN {$o} op ON op.id = l.assigned_operator_id
        WHERE {$where_sql}
        ORDER BY s.submitted_at DESC";

        return array( $sql, $vals );
    }

    public static function export_csv()
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Sin permisos.' );
        }
        check_admin_referer( 'estandar_leads_export_csv' );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $mode = sanitize_key( $_POST['export_mode'] ?? 'full' );

        // Filters come from POST (hidden fields in the download form).
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $f = self::exports_parse_filters( $_POST );
        list( $sql, $vals ) = self::exports_build_query( $f );

        global $wpdb;
        $rows = $wpdb->get_results(
            $vals ? $wpdb->prepare( $sql, $vals ) : $sql,
            ARRAY_A
        ) ?: array();

        $row_count   = count( $rows );
        $filter_hash = substr( md5( wp_json_encode( $f ) ), 0, 8 );

        // Log: filter_hash debe coincidir con el hash del preview anterior.
        // Si filter_hash difiere → los filtros que vio el preview ≠ los que generaron el CSV.
        error_log( sprintf(
            '[estandar-leads] export_csv: filter_hash=%s filters=%s row_count=%d user=%d',
            $filter_hash,
            wp_json_encode( $f ),
            $row_count,
            get_current_user_id()
        ) );

        $surname_aliases = array( 'apellido', 'apellidos', 'last_name', 'lastname', 'surname' );

        // Build filter summary for CSV header (human-readable, matches log line).
        $active_filter_parts = array();
        if ( $f['source'] !== '' )       $active_filter_parts[] = 'source=' . $f['source'];
        if ( $f['project'] !== '' )      $active_filter_parts[] = 'project=' . $f['project'];
        if ( $f['operator_id'] === 0 )   $active_filter_parts[] = 'operator=none';
        elseif ( $f['operator_id'] > 0 ) $active_filter_parts[] = 'operator=' . $f['operator_id'];
        if ( $f['status'] !== '' )       $active_filter_parts[] = 'status=' . $f['status'];
        if ( $f['utm_campaign'] !== '' ) $active_filter_parts[] = 'utm_campaign=' . $f['utm_campaign'];
        $active_filter_parts[] = 'period=' . $f['preset'];
        if ( $f['date_from'] !== '' )    $active_filter_parts[] = 'from=' . substr( $f['date_from'], 0, 10 );
        if ( $f['date_to'] !== '' )      $active_filter_parts[] = 'to=' . $f['date_to'];

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="leads-export' . ( $mode === 'client' ? '-cliente' : '' ) . '-' . gmdate( 'Ymd-His' ) . '-' . $row_count . 'rows.csv"' );
        header( 'X-Export-Row-Count: ' . $row_count );
        header( 'X-Export-Filter-Hash: ' . $filter_hash );
        $out = fopen( 'php://output', 'w' );
        fprintf( $out, chr(0xEF) . chr(0xBB) . chr(0xBF) ); // UTF-8 BOM para Excel

        // Audit header block — correlates CSV ↔ server log ↔ preview screen.
        // Lines prefixed with # are ignored by standard CSV parsers; Excel skips them.
        fwrite( $out, '# ESTANDAR LEADS EXPORT' . "\n" );
        fwrite( $out, '# Filter Hash: ' . $filter_hash . "\n" );
        fwrite( $out, '# Exported Rows: ' . $row_count . "\n" );
        fwrite( $out, '# Filters: ' . implode( ' | ', $active_filter_parts ) . "\n" );
        fwrite( $out, '# Generated: ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC' . "\n" );
        fwrite( $out, '#' . "\n" );

        // Client export = subset operacional sin datos técnicos. Reusa misma query/filtros.
        if ( $mode === 'client' ) {
            self::export_csv_client_rows( $out, $rows );
            fclose( $out );
            exit;
        }

        fputcsv( $out, array(
            'lead_id', 'submission_id', 'lead_uuid',
            'created_at', 'submitted_at',
            'nombre', 'apellido', 'nombre_completo',
            'email', 'telefono',
            'proyecto', 'operador',
            'source_plugin', 'source_form_id',
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
            'fbclid', 'gclid',
            'landing_url', 'referrer', 'session_id',
            'estado_comercial', 'calidad',
        ) );

        foreach ( $rows as $row ) {
            $fields  = array();
            $raw_json = $row['form_fields_json'] ?? '';
            if ( $raw_json !== '' ) {
                $decoded = json_decode( $raw_json, true );
                if ( is_array( $decoded ) ) {
                    $fields = $decoded;
                }
            }

            $apellido = '';
            foreach ( $surname_aliases as $alias ) {
                if ( ! empty( $fields[ $alias ] ) && is_scalar( $fields[ $alias ] ) ) {
                    $apellido = trim( (string) $fields[ $alias ] );
                    break;
                }
            }
            $nombre         = trim( (string) ( $row['name'] ?? '' ) );
            $nombre_completo = $apellido !== '' && stripos( $nombre, $apellido ) === false
                ? trim( $nombre . ' ' . $apellido )
                : $nombre;

            fputcsv( $out, array(
                $row['lead_id']         ?? '',
                $row['submission_id']   ?? '',
                $row['lead_uuid']       ?? '',
                $row['created_at']      ?? '',
                $row['submitted_at']    ?? '',
                $nombre,
                $apellido,
                $nombre_completo,
                $row['email']           ?? '',
                $row['phone']           ?? '',
                $row['proyecto']        ?? '',
                $row['operador']        ?? '',
                $row['source_plugin']   ?? '',
                $row['source_form_id']  ?? '',
                $row['utm_source']      ?? '',
                $row['utm_medium']      ?? '',
                $row['utm_campaign']    ?? '',
                $row['utm_term']        ?? '',
                $row['utm_content']     ?? '',
                $row['fbclid']          ?? '',
                $row['gclid']           ?? '',
                $row['landing_url']     ?? '',
                $row['referrer']        ?? '',
                $row['session_id']      ?? '',
                $row['estado_comercial'] ?? '',
                $row['calidad']         ?? '',
            ) );
        }

        fclose( $out );
        exit;
    }

    /** Client CSV: operational columns only, no technical tracking. Reuses $rows. */
    private static function export_csv_client_rows( $out, array $rows ): void
    {
        $surname_aliases = array( 'apellido', 'apellidos', 'last_name', 'lastname', 'surname' );

        fputcsv( $out, array(
            'Fecha contacto', 'Nombre', 'Teléfono', 'Email', 'Proyecto', 'Operador asignado',
            'Fuente Lead', 'Canal', 'Campaña', 'Estado', 'Calidad', 'Sales Stage',
            'Lead válido', 'Clasificar Lead', 'Última actividad', 'Días sin gestión',
        ) );

        $now = time();
        foreach ( $rows as $row ) {
            $fields  = array();
            $raw_json = $row['form_fields_json'] ?? '';
            if ( $raw_json !== '' ) {
                $decoded = json_decode( $raw_json, true );
                if ( is_array( $decoded ) ) {
                    $fields = $decoded;
                }
            }
            $apellido = '';
            foreach ( $surname_aliases as $alias ) {
                if ( ! empty( $fields[ $alias ] ) && is_scalar( $fields[ $alias ] ) ) {
                    $apellido = trim( (string) $fields[ $alias ] );
                    break;
                }
            }
            $nombre          = trim( (string) ( $row['name'] ?? '' ) );
            $nombre_completo = $apellido !== '' && stripos( $nombre, $apellido ) === false
                ? trim( $nombre . ' ' . $apellido )
                : $nombre;

            $valido     = $row['is_valid_lead'] ?? null;
            $valido_lbl = ( $valido === null || $valido === '' ) ? '' : ( (int) $valido === 1 ? 'Sí' : 'No' );

            // Mismo enlace de clasificación que usan Notifier (Clasificar), Workspace y emails.
            $clasificar_url = '';
            if ( class_exists( 'Estandar_Leads_Tokens' ) && ! empty( $row['lead_id'] ) ) {
                $cls_token = Estandar_Leads_Tokens::create( (int) $row['lead_id'], (int) ( $row['assigned_operator_id'] ?? 0 ) );
                if ( $cls_token !== '' ) {
                    $clasificar_url = Estandar_Leads_Tokens::url( $cls_token );
                }
            }

            $base = ! empty( $row['first_contact_at'] ) ? $row['first_contact_at'] : ( $row['created_at'] ?? '' );
            $bt   = $base ? strtotime( (string) $base ) : 0;
            $dias = $bt ? (string) max( 0, (int) floor( ( $now - $bt ) / DAY_IN_SECONDS ) ) : '';

            fputcsv( $out, array(
                $row['created_at']       ?? '',
                $nombre_completo,
                $row['phone']            ?? '',
                $row['email']            ?? '',
                $row['proyecto']         ?? '',
                $row['operador']         ?? '',
                self::client_source_label( (string) ( $row['source_plugin'] ?? '' ) ),
                self::client_channel_label( $row ),
                $row['utm_campaign']     ?? '',
                $row['estado_comercial'] ?? '',
                $row['calidad']          ?? '',
                $row['sales_stage']      ?? '',
                $valido_lbl,
                $clasificar_url,
                $row['last_seen_at']     ?? '',
                $dias,
            ) );
        }
    }

    private static function client_source_label( string $source_plugin ): string
    {
        if ( $source_plugin === 'estandar-chatbot' ) { return 'Chatbot'; }
        if ( $source_plugin === 'estandar-forms' )   { return 'Formulario'; }
        if ( in_array( $source_plugin, array( 'manual', 'manual_import', 'admin' ), true ) ) { return 'Manual'; }
        return 'Otro';
    }

    /** Canal comercial desde el resolver canónico; conserva etiquetas del CSV. */
    private static function client_channel_label( array $row ): string
    {
        $labels = array(
            'google_ads' => 'Google Ads', 'google_organic' => 'Orgánico',
            'meta_ads' => 'Meta Ads', 'organic' => 'Orgánico',
            'direct' => 'Directo', 'unknown' => 'Otro',
            'form_web' => 'Formulario Web',
        );
        $channel = Estandar_Leads_Attribution::resolve_channel( $row );
        return $labels[ $channel ] ?? 'Otro';
    }

    // -------------------------------------------------------------------------
    // Portal page
    // -------------------------------------------------------------------------

    public static function portal()
    {
        // Alias de compatibilidad. El redirect se ejecuta en admin_init
        // (maybe_redirect_portal_alias) antes de cualquier salida.
        // Este render callback ya no redirige: nunca llega a ejecutarse.
        return;
    }

    public static function portal_content()
    {
        global $wpdb;

        $filters       = Estandar_Leads_Portal::parse_filters();
        $page          = max(1, absint($_GET['paged'] ?? 1));
        $result        = Estandar_Leads_Portal::get_leads($filters, 50, $page);
        $lead_detail   = $filters['view'] === 'detail'
            ? Estandar_Leads_Portal::get_lead_detail(absint($_GET['lead_id'] ?? 0))
            : null;
        $stats         = Estandar_Leads_Portal::get_stats();
        $visible_lead_ids = array_map(
            static function ( $row ) {
                return absint( $row->id ?? 0 );
            },
            $result['rows'] ?? array()
	        );
        $attribution_health_leads = $result['rows'] ?? array();
        if ( ! empty( $visible_lead_ids ) ) {
            $ids = array_values( array_filter( array_unique( $visible_lead_ids ) ) );
            $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            $latest_submissions = $wpdb->get_results( $wpdb->prepare(
                "SELECT s1.lead_id, s1.utm_source, s1.utm_medium, s1.utm_campaign, s1.gclid, s1.gbraid, s1.wbraid, s1.fbclid, s1.landing_url, s1.referrer
                 FROM {$wpdb->prefix}es_lead_submissions s1
                 INNER JOIN (
                    SELECT lead_id, MAX(id) AS id FROM {$wpdb->prefix}es_lead_submissions WHERE lead_id IN ({$placeholders}) GROUP BY lead_id
                 ) latest ON latest.id = s1.id",
                $ids
            ) );
            $technical_by_lead = array();
            foreach ( $latest_submissions ?: array() as $submission ) {
                $technical_by_lead[ absint( $submission->lead_id ?? 0 ) ] = get_object_vars( $submission );
            }
            $oci_table = $wpdb->prefix . 'es_oci_queue';
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $oci_table ) ) === $oci_table ) {
                $oci_rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT q1.lead_id, q1.queue_status AS oci_status, q1.export_status, q1.last_error AS oci_error_message
                     FROM {$oci_table} q1
                     INNER JOIN (
                        SELECT lead_id, MAX(id) AS id FROM {$oci_table} WHERE lead_id IN ({$placeholders}) GROUP BY lead_id
                     ) latest ON latest.id = q1.id",
                    $ids
                ) );
                foreach ( $oci_rows ?: array() as $oci_row ) {
                    $lead_id = absint( $oci_row->lead_id ?? 0 );
                    $technical_by_lead[ $lead_id ] = array_merge( $technical_by_lead[ $lead_id ] ?? array(), get_object_vars( $oci_row ) );
                }
            }
            $attribution_health_leads = array_map(
                static function ( $row ) use ( $technical_by_lead ) {
                    return array_merge( get_object_vars( $row ), $technical_by_lead[ absint( $row->id ?? 0 ) ] ?? array() );
                },
                $result['rows'] ?? array()
            );
        }
        $attribution_health_summary = function_exists( 'build_leads_attribution_health_summary' )
            ? build_leads_attribution_health_summary( $attribution_health_leads )
            : array();
		        $autoresponse_by_lead = Estandar_Leads_Portal::get_autoresponse_observability( $visible_lead_ids );
		        $last_commercial_actions = Estandar_Leads_Portal::get_last_commercial_actions( $visible_lead_ids );
		        $autoresponse_stats = Estandar_Leads_Portal::get_autoresponse_stats( 7 );
                $context_gap_by_lead = Estandar_Leads_Repository::context_gaps_for_leads( $visible_lead_ids );
                $context_gap_summary = Estandar_Leads_Repository::context_gap_summary( 30 );
                $context_gap_template = Estandar_Leads_Repository::context_gap_template();
	        $detail_lead_id = absint( $lead_detail['lead']->id ?? 0 );
		        $autoresponse_detail = $detail_lead_id
		            ? ( Estandar_Leads_Portal::get_autoresponse_observability( array( $detail_lead_id ) )[ $detail_lead_id ] ?? array() )
		            : array();
                $context_gap_detail = $detail_lead_id ? Estandar_Leads_Repository::latest_context_gap( $detail_lead_id ) : array();
	        $interaction_timeline = $detail_lead_id ? Estandar_Leads_Portal::get_interaction_timeline( $detail_lead_id ) : array();
	        if ( $detail_lead_id ) {
            Estandar_Leads_Portal::log_portal_event( $detail_lead_id, 'lead_viewed', array(
                'project' => sanitize_key( $lead_detail['lead']->project_slug ?? '' ),
            ), absint( $lead_detail['lead']->assigned_operator_id ?? 0 ) );
        } else {
            Estandar_Leads_Portal::log_list_open( $visible_lead_ids, $filters );
        }
	        $operators     = $wpdb->get_results("SELECT id, name FROM {$wpdb->prefix}es_operators WHERE active = 1 ORDER BY name ASC");
	        $created_link          = '';
	        $created_link_operator = '';
	        $raw_transient = get_transient('estandar_leads_link_' . get_current_user_id());
	        if ($raw_transient) {
	            delete_transient('estandar_leads_link_' . get_current_user_id());
	            $parts = explode('||', $raw_transient, 2);
	            $created_link          = $parts[0];
	            $created_link_operator = isset($parts[1]) ? $parts[1] : '';
	        }
	        $projects      = Estandar_Leads_Projects::registry();
        $views         = Estandar_Leads_Portal::views();
        $quality_labels = Estandar_Leads_Email::quality_labels();
        $quality_colors = Estandar_Leads_Email::quality_colors();
        $status_labels  = Estandar_Leads_Normalizer::status_labels();

        require ESTANDAR_LEADS_PATH . 'templates/admin/operator-portal.php';
    }

    // -------------------------------------------------------------------------
    // Email settings page
    // -------------------------------------------------------------------------

    public static function email_settings()
    {
        self::render_shell('Email', array(__CLASS__, 'email_settings_content'));
    }

    public static function email_settings_content()
    {
        $s = Estandar_Leads_Email::get_settings();
        require ESTANDAR_LEADS_PATH . 'templates/admin/email-settings.php';
    }

    public static function save_email_settings()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Sin permisos.');
        }
        check_admin_referer('estandar_leads_save_email_settings');

        Estandar_Leads_Email::save_settings($_POST);

        wp_safe_redirect(admin_url('admin.php?page=estandar-leads-email&email_saved=1'));
        exit;
    }

    public static function autoresponse_settings()
    {
        self::render_shell('Autorespuesta', array(__CLASS__, 'autoresponse_settings_content'));
    }

    public static function autoresponse_settings_content()
    {
        $projects = Estandar_Leads_Projects::registry();
        $preview = null;
        $preview_project = sanitize_key(wp_unslash($_GET['preview_project'] ?? ''));
        if ($preview_project !== '') {
            $preview = get_transient('estandar_leads_autoresponse_preview_' . get_current_user_id() . '_' . $preview_project);
            $preview = is_array($preview) ? $preview : null;
        }
        $last_tests = get_option('estandar_leads_autoresponse_test_log', array());
        $last_tests = is_array($last_tests) ? $last_tests : array();
        require ESTANDAR_LEADS_PATH . 'templates/admin/autoresponse-settings.php';
    }

    public static function save_autoresponse_settings()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Sin permisos.');
        }
        check_admin_referer('estandar_leads_save_autoresponse_settings');

        $project_slug = sanitize_key(wp_unslash($_POST['project_slug'] ?? ''));
        $project_uuid = sanitize_text_field(wp_unslash($_POST['project_uuid'] ?? ''));
        $project = null;
        foreach (Estandar_Leads_Projects::registry() as $candidate) {
            if (
                (string) ($candidate['project_slug'] ?? '') === $project_slug
                && (string) ($candidate['project_uuid'] ?? '') === $project_uuid
            ) {
                $project = $candidate;
                break;
            }
        }
        if (!$project || $project_slug === '') {
            wp_die('Proyecto inválido.');
        }

        $pdf_attachment_id = absint($_POST['pdf_attachment_id'] ?? 0);
        if ($pdf_attachment_id && get_post_mime_type($pdf_attachment_id) !== 'application/pdf') {
            $pdf_attachment_id = 0;
        }

        Estandar_Leads_Email::save_autoresponse_settings(
            array(
                'enabled' => !empty($_POST['enabled']) ? 1 : 0,
                'subject' => wp_unslash($_POST['subject'] ?? ''),
                'body' => wp_unslash($_POST['body'] ?? ''),
                'pdf_attachment_id' => $pdf_attachment_id,
            ),
            $project_slug,
            $project_uuid
        );

        wp_safe_redirect(add_query_arg(
            array('page' => 'estandar-leads-autoresponse', 'saved' => '1', 'project' => $project_slug),
            admin_url('admin.php')
        ));
        exit;
    }

    public static function preview_autoresponse()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Sin permisos.');
        }
        check_admin_referer('estandar_leads_preview_autoresponse');

        $project = self::posted_autoresponse_project();
        $message = Estandar_Leads_Autoresponse::build_test_message($project['project_slug'], $project['name'] ?? $project['project_slug']);
        set_transient(
            'estandar_leads_autoresponse_preview_' . get_current_user_id() . '_' . $project['project_slug'],
            array(
                'project_slug' => $project['project_slug'],
                'project_name' => $project['name'] ?? $project['project_slug'],
                'subject' => $message['subject'],
                'body' => $message['body'],
                'html' => $message['html'],
            ),
            10 * MINUTE_IN_SECONDS
        );

        wp_safe_redirect(add_query_arg(
            array('page' => 'estandar-leads-autoresponse', 'preview_project' => $project['project_slug']),
            admin_url('admin.php')
        ));
        exit;
    }

    public static function test_autoresponse()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Sin permisos.');
        }
        check_admin_referer('estandar_leads_test_autoresponse');

        $project = self::posted_autoresponse_project();
        $to = sanitize_email(wp_unslash($_POST['test_email'] ?? ''));
        if (!is_email($to)) {
            wp_die('Correo destino inválido.');
        }

        $message = Estandar_Leads_Autoresponse::build_test_message($project['project_slug'], $project['name'] ?? $project['project_slug']);
        $sent = Estandar_Leads_Email::send($to, $message['subject'], $message['html'], $message['settings'], array(), $message['attachments']);

        $log = get_option('estandar_leads_autoresponse_test_log', array());
        $log = is_array($log) ? $log : array();
        $log[$project['project_slug']] = array(
            'ts' => current_time('mysql'),
            'to' => $to,
            'subject' => sanitize_text_field((string) $message['subject']),
            'project_name' => sanitize_text_field((string) ($project['name'] ?? $project['project_slug'])),
            'ok' => $sent ? 1 : 0,
        );
        update_option('estandar_leads_autoresponse_test_log', $log, false);

        wp_safe_redirect(add_query_arg(
            array(
                'page' => 'estandar-leads-autoresponse',
                'test_project' => $project['project_slug'],
                'test_sent' => $sent ? '1' : '0',
            ),
            admin_url('admin.php')
        ));
        exit;
    }

    private static function posted_autoresponse_project()
    {
        $project_slug = sanitize_key(wp_unslash($_POST['project_slug'] ?? ''));
        $project_uuid = sanitize_text_field(wp_unslash($_POST['project_uuid'] ?? ''));
        foreach (Estandar_Leads_Projects::registry() as $project) {
            if (
                (string) ($project['project_slug'] ?? '') === $project_slug
                && (string) ($project['project_uuid'] ?? '') === $project_uuid
            ) {
                return $project;
            }
        }
        wp_die('Proyecto inválido.');
    }

    public static function enqueue_autoresponse_assets($hook)
    {
        $page = sanitize_key((string) ($_GET['page'] ?? ''));
        if ($page === 'estandar-leads-autoresponse') {
            wp_enqueue_media();
        }
    }

    // -------------------------------------------------------------------------
    // Email AJAX handlers
    // -------------------------------------------------------------------------

    public static function ajax_preview_email()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Sin permisos.'), 403);
        }
        check_ajax_referer('estandar_leads_email_preview', 'nonce');

        $html = Estandar_Leads_Email::preview();
        wp_send_json_success(array('html' => $html));
    }

    public static function ajax_test_email()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Sin permisos.'), 403);
        }
        check_ajax_referer('estandar_leads_email_preview', 'nonce');

        $to = sanitize_email(wp_unslash($_POST['test_to'] ?? ''));
        if ($to === '') {
            wp_send_json_error(array('message' => 'Email inválido.'));
        }

        $settings = Estandar_Leads_Email::get_settings();
        $html     = Estandar_Leads_Email::preview($settings);
        $sent     = Estandar_Leads_Email::send(
            $to,
            '[PRUEBA] ' . $settings['subject'],
            $html,
            $settings
        );

        if ($sent) {
            wp_send_json_success(array('message' => 'Enviado.'));
        } else {
            wp_send_json_error(array('message' => 'wp_mail() devolvió false. Revisa la configuración de SMTP.'));
        }
    }

    public static function ajax_get_ai_context()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Sin permisos.'));
        }
        $lead_id = absint($_POST['lead_id'] ?? 0);
        if (!$lead_id) {
            wp_send_json_error(array('message' => 'Lead ID requerido.'));
        }
        // RC3 — commercial view by default; technical export keeps all telemetry.
        $view = (isset($_POST['view']) && $_POST['view'] === 'technical') ? 'technical' : 'commercial';
        $context = Estandar_Leads_AI_Context::build_for_lead($lead_id, $view);
        if (!$context) {
            wp_send_json_error(array('message' => 'No se pudo generar contexto.'));
        }
        wp_send_json_success(array('context' => $context));
    }

    private static function render_shell($title, $callback)
    {
        // Igual que Proyectos: renderizar el shell propio SIN chequear el flag
        // estandar_core_admin_shell_rendering. Bajo core activo, las páginas
        // llegan envueltas por el shell de estandar_register_navigation_page
        // (flag=true); el shell externo detecta este shell interno vía
        // estandar_core_output_has_admin_shell() y hace passthrough — así el
        // header_slot (subtabs) sí se renderiza. Con el chequeo previo, esta
        // rama nunca corría y Leads quedaba sin subtabs.
        if (primer_is_active()) {
            $args = array(
                'title' => $title,
                'subtitle' => 'Ciclo comercial de calidad de leads.',
                'width' => 'fluid', // ESTANDAR_ADMIN_UI_V0.4 — full-width admin shell.
                'callback' => $callback,
            );
            // Subtabs canónicos (misma implementación que Proyectos). Cores
            // antiguos sin el helper conservan la ruta 'navigation' previa.
            if (function_exists('estandar_core_render_subtabs')) {
                $args['header_slot'] = static function (): void {
                    $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : 'estandar-leads';
                    estandar_core_render_subtabs('leads', self::resolve_active_tab($page));
                };
            } else {
                $args['navigation'] = self::get_shell_navigation();
            }
            estandar_core_render_admin_shell($args);
            return;
        }

        call_user_func($callback);
    }

    /**
     * Handle the retroactive bootstrap POST action.
     * Runs the bootstrap, stores results, and redirects back to the settings page.
     */
    public static function run_compliance_bootstrap()
    {
        if (!current_user_can('manage_options')) {
            wp_die('No autorizado.');
        }
        check_admin_referer('estandar_leads_run_bootstrap');

        if (!class_exists('Estandar_Leads_Compliance')) {
            wp_safe_redirect(add_query_arg(
                array('page' => 'estandar-leads-settings', 'bootstrap_error' => '1'),
                admin_url('admin.php')
            ));
            exit;
        }

        $dry_run = ! empty( $_POST['dry_run'] );
        $result  = Estandar_Leads_Compliance::run_bootstrap( $dry_run );

        set_transient('estandar_leads_bootstrap_result', $result, 300);

        $redirect_args = array( 'page' => 'estandar-leads-settings', 'bootstrap_done' => '1' );
        if ( $dry_run ) {
            $redirect_args['dry_run'] = '1';
        }
        wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
        exit;
    }

}
