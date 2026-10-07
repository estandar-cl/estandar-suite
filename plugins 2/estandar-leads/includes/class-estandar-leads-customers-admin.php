<?php
/**
 * CUSTOMER_REGISTRY_V2 — Admin UI
 *
 * Registers the "Clientes" menu and handles all customer admin pages:
 *   - List (with search + filters)
 *   - Editor (create / edit + reconcile)
 *   - Import (CSV / XLSX, preview → confirm)
 *   - Reconcile All
 *
 * No schema changes. No modifications to Leads, Scoring, AI Context,
 * Analytics, Compliance, Recovery, Notifier, or OCI.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Estandar_Leads_Customers_Admin {

    const PAGE        = 'estandar-customers';
    const CAP         = 'manage_options';
    const TRANS_PRE   = 'ecr_import_';
    const TRANS_TTL   = 1800; // 30 min

    // -------------------------------------------------------------------------
    // Bootstrap
    // -------------------------------------------------------------------------

    public static function init(): void {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );

        add_action( 'admin_post_estandar_customers_save',             array( __CLASS__, 'handle_save' ) );
        add_action( 'admin_post_estandar_customers_reconcile',        array( __CLASS__, 'handle_reconcile' ) );
        add_action( 'admin_post_estandar_customers_reconcile_all',    array( __CLASS__, 'handle_reconcile_all' ) );
        add_action( 'admin_post_estandar_customers_bulk_delete',      array( __CLASS__, 'handle_bulk_delete' ) );
        add_action( 'admin_post_estandar_customers_bulk_reconcile',   array( __CLASS__, 'handle_bulk_reconcile' ) );
        add_action( 'admin_post_estandar_customers_import_upload',    array( __CLASS__, 'handle_import_upload' ) );
        add_action( 'admin_post_estandar_customers_import_confirm',   array( __CLASS__, 'handle_import_confirm' ) );
        add_action( 'admin_post_estandar_customers_meta_backfill',    array( __CLASS__, 'handle_meta_backfill' ) );
        add_action( 'admin_post_estandar_customers_export_csv',       array( __CLASS__, 'handle_export_csv' ) );
    }

    public static function menu(): void {
        // Canonical registration — identical gate and helper to every other Leads
        // admin page (Estandar_Leads_Admin::menu). Using primer_is_active()
        // (the single, call-time source of truth for "is Core active", per
        // CORE_OPTIONALITY_V1.1) instead of a bespoke defined()/$admin_page_hooks gate
        // keeps this page's menu hook registered on the SAME parent as Leads. A hook
        // that fails to register makes WordPress fall back to current_user_can(<slug>)
        // — a capability no role holds — which is the "no tienes permisos" denial even
        // for a manage_options user. Same capability as Leads (manage_options).
        if ( primer_is_active() ) {
            estandar_admin_menu_register_parent();
            if ( function_exists( 'estandar_register_navigation_page' ) ) {
                estandar_register_navigation_page( [
                    'slug'       => self::PAGE,
                    'title'      => 'Clientes',
                    'capability' => self::CAP,
                    'callback'   => array( __CLASS__, 'page' ),
                ] );
            }
            return;
        }

        // Standalone (sin Primer): nest under the Leads top-level menu, which
        // is registered first (Estandar_Leads_Admin::init runs before this one). Mirrors
        // the standalone branch of Estandar_Leads_Admin::menu.
        global $admin_page_hooks;
        if ( isset( $admin_page_hooks['estandar-leads'] ) ) {
            add_submenu_page(
                'estandar-leads',
                'Clientes',
                'Clientes',
                self::CAP,
                self::PAGE,
                array( __CLASS__, 'page' )
            );
            return;
        }

        // True fallback: Leads menu absent (standalone Customers install).
        add_menu_page(
            'Clientes',
            'Clientes',
            self::CAP,
            self::PAGE,
            array( __CLASS__, 'page' ),
            'dashicons-groups',
            59
        );
    }

    // -------------------------------------------------------------------------
    // Page dispatcher
    // -------------------------------------------------------------------------

    public static function page(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( 'Sin permiso.' );
        }
        $view = sanitize_key( $_GET['view'] ?? 'list' );
        switch ( $view ) {
            case 'edit':   self::render_edit();   break;
            case 'import': self::render_import(); break;
            default:       self::render_list();   break;
        }
    }

    // -------------------------------------------------------------------------
    // List
    // -------------------------------------------------------------------------

    private static function render_list(): void {
        global $wpdb;

        $table   = $wpdb->prefix . 'es_customers';
        $search  = sanitize_text_field( $_GET['s']       ?? '' );
        $project = sanitize_key(        $_GET['project'] ?? '' );
        $stage   = sanitize_key(        $_GET['stage']   ?? '' );
        $matched = sanitize_key(        $_GET['matched'] ?? '' ); // '' | 'yes' | 'no'
        $paged   = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
        $per     = 50;
        $offset  = ( $paged - 1 ) * $per;

        $where  = array( '1=1' );
        $params = array();

        if ( $search !== '' ) {
            $like     = '%' . $wpdb->esc_like( $search ) . '%';
            $where[]  = '(first_name LIKE %s OR last_name LIKE %s OR email LIKE %s OR phone LIKE %s OR rut LIKE %s)';
            $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        }
        if ( $project !== '' ) { $where[] = 'project_slug = %s'; $params[] = $project; }
        if ( $stage   !== '' ) { $where[] = 'customer_stage = %s'; $params[] = $stage; }
        if ( $matched === 'yes' ) { $where[] = 'matched_lead_id IS NOT NULL'; }
        if ( $matched === 'no'  ) { $where[] = 'matched_lead_id IS NULL'; }

        $where_sql = implode( ' AND ', $where );

        $count_sql = "SELECT COUNT(*) FROM $table WHERE $where_sql";
        $total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );

        $data_params   = array_merge( $params, array( $per, $offset ) );
        $data_sql      = "SELECT * FROM $table WHERE $where_sql ORDER BY id DESC LIMIT %d OFFSET %d";
        $customers     = $params
            ? $wpdb->get_results( $wpdb->prepare( $data_sql, $data_params ), ARRAY_A )
            : $wpdb->get_results( $wpdb->prepare( $data_sql, $per, $offset ), ARRAY_A );
        $customers     = $customers ?: array();

        $projects = $wpdb->get_col( "SELECT DISTINCT project_slug FROM $table WHERE project_slug != '' ORDER BY project_slug" ) ?: array();
        $stages   = $wpdb->get_col( "SELECT DISTINCT customer_stage FROM $table WHERE customer_stage != '' ORDER BY customer_stage" ) ?: array();

        $total_pages = (int) ceil( $total / $per );

        $backfill = self::pull_backfill_report();

        include dirname( __DIR__ ) . '/templates/admin/customers/list.php';
    }

    // -------------------------------------------------------------------------
    // Editor
    // -------------------------------------------------------------------------

    private static function render_edit(): void {
        $id       = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        $customer = $id ? Estandar_Leads_Customer_Repository::find( $id ) : null;
        $lead     = null;

        if ( $customer && ! empty( $customer['matched_lead_id'] ) ) {
            $lead = self::get_lead_summary( (int) $customer['matched_lead_id'] );
        }

        $saved    = isset( $_GET['saved'] )    ? (int) $_GET['saved']    : 0;
        $rec_done = isset( $_GET['rec'] )      ? (int) $_GET['rec']      : -1;
        $error    = sanitize_text_field( $_GET['err'] ?? '' );

        include dirname( __DIR__ ) . '/templates/admin/customers/edit.php';
    }

    // -------------------------------------------------------------------------
    // Import
    // -------------------------------------------------------------------------

    private static function render_import(): void {
        $step   = sanitize_key( $_GET['step'] ?? 'upload' );
        $tk     = sanitize_key( $_GET['tk']   ?? '' );
        $result = null;
        $rows   = array();
        $headers = array();
        $error  = sanitize_text_field( $_GET['err'] ?? '' );

        if ( $step === 'preview' && $tk !== '' ) {
            $data    = get_transient( self::TRANS_PRE . $tk );
            $headers = $data['headers'] ?? array();
            $rows    = $data['rows']    ?? array();
            if ( empty( $rows ) ) {
                $step = 'upload';
                $error = 'La sesión de vista previa expiró. Sube el archivo nuevamente.';
            }
        }

        if ( $step === 'done' ) {
            $result = array(
                'imported'  => (int) ( $_GET['imported']  ?? 0 ),
                'updated'   => (int) ( $_GET['updated']   ?? 0 ),
                'matched'   => (int) ( $_GET['matched']   ?? 0 ),
                'unmatched' => (int) ( $_GET['unmatched'] ?? 0 ),
                'errors'    => (int) ( $_GET['errors']    ?? 0 ),
            );
        }

        include dirname( __DIR__ ) . '/templates/admin/customers/import.php';
    }

    // -------------------------------------------------------------------------
    // POST: Save
    // -------------------------------------------------------------------------

    public static function handle_save(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( 'Sin permiso.' );
        }
        check_admin_referer( 'estandar_customers_save' );

        $id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        $data = self::extract_form_data( $_POST );
        if ( $id > 0 ) {
            $data['id'] = $id;
        }
        $saved_id = Estandar_Leads_Customer_Service::save( $data );

        if ( $saved_id <= 0 ) {
            wp_safe_redirect( self::edit_url( $id, array( 'err' => 'save_failed' ) ) );
            exit;
        }
        wp_safe_redirect( self::edit_url( $saved_id, array( 'saved' => 1 ) ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // POST: Reconcile single
    // -------------------------------------------------------------------------

    public static function handle_reconcile(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( 'Sin permiso.' );
        }
        check_admin_referer( 'estandar_customers_reconcile' );

        $id  = absint( $_POST['id'] ?? 0 );
        $ok  = $id > 0 && Estandar_Leads_Customer_Service::reconcile( $id );

        wp_safe_redirect( self::edit_url( $id, array( 'rec' => (int) $ok ) ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // POST: Reconcile All
    // -------------------------------------------------------------------------

    public static function handle_reconcile_all(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( 'Sin permiso.' );
        }
        check_admin_referer( 'estandar_customers_reconcile_all' );

        if ( ! ini_get( 'safe_mode' ) ) {
            set_time_limit( 0 );
        }

        $batch     = 500;
        $batch_rows = Estandar_Leads_Customer_Repository::list( array( 'unmatched' => true, 'limit' => $batch ) );
        $matched    = 0;
        foreach ( $batch_rows as $c ) {
            if ( Estandar_Leads_Customer_Service::reconcile( (int) $c['id'] ) ) {
                $matched++;
            }
        }

        // Count how many unmatched remain after this batch.
        global $wpdb;
        $remaining = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}es_customers WHERE matched_lead_id IS NULL"
        );

        wp_safe_redirect( add_query_arg(
            array( 'page' => self::PAGE, 'rec_all' => $matched, 'rec_remaining' => $remaining ),
            admin_url( 'admin.php' )
        ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // POST: Bulk delete
    // -------------------------------------------------------------------------

    public static function handle_bulk_delete(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( 'Sin permiso.' );
        }
        check_admin_referer( 'estandar_customers_bulk' );

        $ids = array_map( 'absint', (array) ( $_POST['customer_ids'] ?? array() ) );
        $ids = array_filter( $ids );

        if ( ! empty( $ids ) ) {
            global $wpdb;
            $table        = $wpdb->prefix . 'es_customers';
            $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE id IN ($placeholders)", $ids ) );
        }

        wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'bulk_deleted' => count( $ids ) ), admin_url( 'admin.php' ) ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // POST: Bulk reconcile
    // -------------------------------------------------------------------------

    public static function handle_bulk_reconcile(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( 'Sin permiso.' );
        }
        check_admin_referer( 'estandar_customers_bulk' );

        $ids = array_map( 'absint', (array) ( $_POST['customer_ids'] ?? array() ) );
        $ids = array_filter( $ids );

        $matched = 0;
        foreach ( $ids as $id ) {
            if ( Estandar_Leads_Customer_Service::reconcile( $id ) ) {
                $matched++;
            }
        }

        wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'bulk_reconciled' => $matched ), admin_url( 'admin.php' ) ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // POST: Meta Purchase backfill (CUSTOMER_BACKFILL_V1)
    //
    // Historical, admin-triggered, one-shot. Dry-run is the default and the only
    // mode reachable without an explicit "send" intent. Never touches es_customers
    // or es_leads; never changes lead commercial status.
    // -------------------------------------------------------------------------

    public static function handle_meta_backfill(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( 'Sin permiso.' );
        }
        check_admin_referer( 'estandar_customers_meta_backfill' );

        if ( ! ini_get( 'safe_mode' ) ) {
            set_time_limit( 0 );
        }

        // Anything other than an explicit 'send' is treated as a simulation.
        $dry_run = sanitize_key( $_POST['mode'] ?? 'dry' ) !== 'send';

        // Anything other than an explicit 'historical' runs the original reconciled mode.
        $bf_mode = sanitize_key( $_POST['bf_backfill_mode'] ?? '' ) === Estandar_Leads_Customer_Backfill::MODE_HISTORICAL
            ? Estandar_Leads_Customer_Backfill::MODE_HISTORICAL
            : Estandar_Leads_Customer_Backfill::MODE_RECONCILED;

        $filters = array(
            'project_slug'   => sanitize_key( $_POST['bf_project'] ?? '' ),
            'customer_stage' => sanitize_key( $_POST['bf_stage']   ?? '' ),
        );
        // Global conversion date (historical mode only) — request-scoped, never
        // persisted. Strict format/future validation lives in the backfill class.
        if ( $bf_mode === Estandar_Leads_Customer_Backfill::MODE_HISTORICAL ) {
            $filters['fallback_completed_at'] = sanitize_text_field( wp_unslash( $_POST['bf_global_date'] ?? '' ) );
        }
        $limit = (int) ( $_POST['bf_limit'] ?? Estandar_Leads_Customer_Backfill::DEFAULT_LIMIT );

        $report = Estandar_Leads_Customer_Backfill::run( $filters, $limit, $dry_run, $bf_mode );

        // Preserve the exact request used by the simulation so the subsequent
        // send submits the same mode, global date and filters after the redirect.
        $report['request'] = array(
            'mode'        => $bf_mode,
            'global_date' => (string) ( $filters['fallback_completed_at'] ?? '' ),
            'project'     => (string) $filters['project_slug'],
            'stage'       => (string) $filters['customer_stage'],
            'limit'       => $limit,
        );

        set_transient( self::TRANS_PRE . 'bf_' . get_current_user_id(), $report, 300 );

        wp_safe_redirect( add_query_arg(
            array( 'page' => self::PAGE, 'bf' => 1 ),
            admin_url( 'admin.php' )
        ) );
        exit;
    }

    // ── CSV Export ──────────────────────────────────────────────────────────

    public static function handle_export_csv(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( 'Sin permiso.' );
        }
        check_admin_referer( 'estandar_customers_export_csv' );

        if ( ! ini_get( 'safe_mode' ) ) {
            set_time_limit( 0 );
        }

        $filters = array(
            'project_slug' => sanitize_key( $_GET['project'] ?? '' ),
            'customer_stage' => sanitize_key( $_GET['stage'] ?? '' ),
        );
        if ( isset( $_GET['matched'] ) && $_GET['matched'] !== '' ) {
            $filters['matched'] = sanitize_key( $_GET['matched'] );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'es_customers';
        $where = array( '1=1' );
        $args  = array();

        if ( $filters['project_slug'] !== '' ) {
            $where[] = 'project_slug = %s';
            $args[]  = $filters['project_slug'];
        }
        if ( $filters['customer_stage'] !== '' ) {
            $where[] = 'customer_stage = %s';
            $args[]  = $filters['customer_stage'];
        }
        if ( $filters['matched'] === 'yes' ) {
            $where[] = 'matched_lead_id IS NOT NULL';
        } elseif ( $filters['matched'] === 'no' ) {
            $where[] = 'matched_lead_id IS NULL';
        }

        // Search filter
        $search = trim( sanitize_text_field( $_GET['s'] ?? '' ) );
        if ( $search !== '' ) {
            $where[] = '(first_name LIKE %s OR last_name LIKE %s OR email LIKE %s OR phone LIKE %s OR rut LIKE %s)';
            $like    = '%' . $wpdb->esc_like( $search ) . '%';
            $args    = array_merge( $args, array( $like, $like, $like, $like, $like ) );
        }

        $sql  = "SELECT id, company_id, project_slug, external_id, customer_stage,
                        first_name, last_name, email, phone, rut, organization,
                        amount, started_at, completed_at, matched_lead_id, matched_by,
                        matched_confidence, matched_at, created_at, updated_at
                   FROM $table WHERE " . implode( ' AND ', $where ) . ' ORDER BY id ASC';

        if ( ! empty( $args ) ) {
            $sql = $wpdb->prepare( $sql, $args );
        }

        $customers = $wpdb->get_results( $sql, ARRAY_A );
        if ( ! is_array( $customers ) ) {
            $customers = array();
        }

        // CSV headers — bilingual (ES) matching the import column names.
        $headers_map = array(
            'id'                => 'id',
            'company_id'        => 'company_id',
            'project_slug'      => 'proyecto',
            'external_id'       => 'id_externo',
            'customer_stage'    => 'etapa',
            'first_name'        => 'nombre',
            'last_name'         => 'apellido',
            'email'             => 'email',
            'phone'             => 'telefono',
            'rut'               => 'rut',
            'organization'      => 'empresa',
            'amount'            => 'monto',
            'started_at'        => 'inicio',
            'completed_at'      => 'fin',
            'matched_lead_id'   => 'lead_id',
            'matched_by'        => 'coincide_por',
            'matched_confidence'=> 'confianza',
            'matched_at'        => 'coincide_en',
            'created_at'        => 'creado_en',
            'updated_at'        => 'actualizado_en',
        );
        $csv_headers = array_values( $headers_map );

        // Output CSV with UTF-8 BOM.
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=clientes-' . gmdate( 'Y-m-d' ) . '.csv' );
        $out = fopen( 'php://output', 'w' );
        fprintf( $out, "\xEF\xBB\xBF" ); // BOM
        fputcsv( $out, $csv_headers );

        foreach ( $customers as $row ) {
            $line = array();
            foreach ( array_keys( $headers_map ) as $col ) {
                $val = $row[ $col ] ?? '';
                // Prevent CSV formula injection: prefix =, +, -, @ with tab.
                if ( is_string( $val ) && $val !== '' && in_array( $val[0], array( '=', '+', '-', '@' ), true ) ) {
                    $val = "\t" . $val;
                }
                $line[] = $val;
            }
            fputcsv( $out, $line );
        }

        fclose( $out );
        exit;
    }

    /** One-shot read of the last backfill report (dry-run or real). */
    private static function pull_backfill_report(): ?array {
        if ( ! isset( $_GET['bf'] ) ) {
            return null;
        }
        $key    = self::TRANS_PRE . 'bf_' . get_current_user_id();
        $report = get_transient( $key );
        delete_transient( $key );
        return is_array( $report ) ? $report : null;
    }

    // -------------------------------------------------------------------------
    // POST: Import — upload + parse → preview
    // -------------------------------------------------------------------------

    public static function handle_import_upload(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( 'Sin permiso.' );
        }
        check_admin_referer( 'estandar_customers_import_upload' );

        if ( empty( $_FILES['import_file']['tmp_name'] ) ) {
            wp_safe_redirect( self::import_url( array( 'err' => 'no_file' ) ) );
            exit;
        }

        $file     = $_FILES['import_file'];
        $ext      = strtolower( pathinfo( sanitize_file_name( $file['name'] ), PATHINFO_EXTENSION ) );
        $tmp      = $file['tmp_name'];

        if ( $ext === 'xlsx' ) {
            wp_safe_redirect( self::import_url( array( 'err' => 'xlsx_disabled' ) ) );
            exit;
        }

        if ( $ext !== 'csv' ) {
            wp_safe_redirect( self::import_url( array( 'err' => 'bad_ext' ) ) );
            exit;
        }

        $all_rows = self::parse_csv( $tmp );
        if ( empty( $all_rows ) ) {
            wp_safe_redirect( self::import_url( array( 'err' => 'empty' ) ) );
            exit;
        }

        $headers  = array_map( 'strtolower', array_map( 'trim', $all_rows[0] ) );
        $data     = array_slice( $all_rows, 1 );

        $tk = wp_generate_uuid4();
        set_transient( self::TRANS_PRE . $tk, array( 'headers' => $headers, 'rows' => $data ), self::TRANS_TTL );

        wp_safe_redirect( self::import_url( array( 'step' => 'preview', 'tk' => $tk ) ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // POST: Import — confirm → process
    // -------------------------------------------------------------------------

    public static function handle_import_confirm(): void {
        if ( ! current_user_can( self::CAP ) ) {
            wp_die( 'Sin permiso.' );
        }
        check_admin_referer( 'estandar_customers_import_confirm' );

        $tk   = sanitize_key( $_POST['tk'] ?? '' );
        $data = $tk ? get_transient( self::TRANS_PRE . $tk ) : null;
        if ( ! $data ) {
            wp_safe_redirect( self::import_url( array( 'err' => 'expired' ) ) );
            exit;
        }
        delete_transient( self::TRANS_PRE . $tk );

        $headers  = $data['headers'];
        $rows     = $data['rows'];
        $counters = array( 'imported' => 0, 'updated' => 0, 'matched' => 0, 'unmatched' => 0, 'errors' => 0 );

        global $wpdb;
        $table = $wpdb->prefix . 'es_customers';

        $wpdb->query( 'START TRANSACTION' );

        try {
            foreach ( $rows as $raw ) {
                $mapped = self::map_row( $headers, $raw );
                if ( empty( $mapped ) ) { $counters['errors']++; continue; }

                // Check for existing record by external_id + project_slug to detect update vs create.
                $existing_id = 0;
                $ext_id  = $mapped['external_id'] ?? '';
                $proj    = $mapped['project_slug'] ?? '';
                if ( $ext_id !== '' ) {
                    $existing_id = (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT id FROM $table WHERE external_id = %s AND project_slug = %s LIMIT 1",
                        $ext_id, $proj
                    ) );
                }

                $save_payload = $mapped;
                if ( $existing_id > 0 ) {
                    $save_payload['id'] = $existing_id;
                    $saved_id           = Estandar_Leads_Customer_Service::save( $save_payload );
                    if ( $saved_id > 0 ) { $counters['updated']++; } else { $counters['errors']++; continue; }
                } else {
                    $saved_id = Estandar_Leads_Customer_Service::save( $save_payload );
                    if ( $saved_id > 0 ) { $counters['imported']++; } else { $counters['errors']++; continue; }
                }

                if ( Estandar_Leads_Customer_Service::reconcile( $saved_id ) ) {
                    $counters['matched']++;
                } else {
                    $counters['unmatched']++;
                }
            }

            $wpdb->query( 'COMMIT' );
        } catch ( Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            $counters['errors']++;
        }

        wp_safe_redirect( self::import_url( array_merge( array( 'step' => 'done' ), $counters ) ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // Helpers — data
    // -------------------------------------------------------------------------

    private static function get_lead_summary( int $lead_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, name, email, phone, quality, sales_stage, created_at
               FROM {$wpdb->prefix}es_leads WHERE id = %d LIMIT 1",
            $lead_id
        ), ARRAY_A );
        return $row ?: null;
    }

    private static function extract_form_data( array $post ): array {
        $str = array(
            'company_id', 'project_slug', 'external_id', 'customer_stage',
            'first_name', 'last_name', 'email', 'phone', 'rut', 'organization',
        );
        $data = array();
        foreach ( $str as $f ) {
            $data[ $f ] = sanitize_text_field( (string) ( $post[ $f ] ?? '' ) );
        }
        $data['amount']       = isset( $post['amount'] ) && $post['amount'] !== '' ? (float) $post['amount'] : null;
        $data['started_at']   = sanitize_text_field( $post['started_at']   ?? '' ) ?: null;
        $data['completed_at'] = sanitize_text_field( $post['completed_at'] ?? '' ) ?: null;
        $raw_meta = trim( $post['metadata_json'] ?? '' );
        $data['metadata_json'] = $raw_meta !== '' ? $raw_meta : null;
        return $data;
    }

    // -------------------------------------------------------------------------
    // Helpers — import parsing
    // -------------------------------------------------------------------------

    private static function parse_csv( string $path ): array {
        $rows   = array();
        $handle = fopen( $path, 'r' );
        if ( ! $handle ) {
            return $rows;
        }
        // Strip UTF-8 BOM
        $bom = fread( $handle, 3 );
        if ( $bom !== "\xEF\xBB\xBF" ) {
            rewind( $handle );
        }
        // Auto-detect delimiter from first line; rewind to data start after detection.
        $data_start = ftell( $handle );
        $first_line = fgets( $handle );
        $counts     = array(
            ','  => substr_count( (string) $first_line, ',' ),
            ';'  => substr_count( (string) $first_line, ';' ),
            "\t" => substr_count( (string) $first_line, "\t" ),
        );
        arsort( $counts );
        $delimiter = (string) array_key_first( $counts );
        fseek( $handle, $data_start );
        while ( ( $row = fgetcsv( $handle, 4096, $delimiter ) ) !== false ) {
            $rows[] = $row;
        }
        fclose( $handle );
        return $rows;
    }

    private static function parse_xlsx( string $path ): array {
        if ( ! class_exists( 'ZipArchive' ) ) {
            return array();
        }
        $zip = new ZipArchive();
        if ( $zip->open( $path ) !== true ) {
            return array();
        }

        // Shared strings
        $strings = array();
        $sst_xml = $zip->getFromName( 'xl/sharedStrings.xml' );
        if ( $sst_xml ) {
            $sst = simplexml_load_string( $sst_xml, 'SimpleXMLElement', LIBXML_COMPACT | LIBXML_NOERROR );
            if ( $sst ) {
                foreach ( $sst->si as $si ) {
                    if ( isset( $si->r[0] ) ) {
                        $t = '';
                        foreach ( $si->r as $r ) {
                            $t .= isset( $r->t ) ? (string) $r->t : '';
                        }
                        $strings[] = $t;
                    } else {
                        $strings[] = isset( $si->t ) ? (string) $si->t : '';
                    }
                }
            }
        }

        // First worksheet — try sheet1.xml then sheet.xml (LibreOffice fallback).
        $sheet_xml = $zip->getFromName( 'xl/worksheets/sheet1.xml' )
                  ?: $zip->getFromName( 'xl/worksheets/sheet.xml' );
        $zip->close();

        $rows = array();
        if ( ! $sheet_xml ) {
            return $rows;
        }
        $sheet = simplexml_load_string( $sheet_xml, 'SimpleXMLElement', LIBXML_COMPACT | LIBXML_NOERROR );
        if ( ! $sheet || ! isset( $sheet->sheetData ) ) {
            return $rows;
        }

        foreach ( $sheet->sheetData->row as $row ) {
            $sparse = array();
            foreach ( $row->c as $c ) {
                // Resolve sparse column: use cell ref (e.g. "C3" → col index 2).
                $col_ref = preg_replace( '/\d+/', '', (string) ( $c['r'] ?? '' ) );
                $col_idx = $col_ref !== '' ? self::col_letter_to_index( $col_ref ) : count( $sparse );
                $v       = isset( $c->v ) ? (string) $c->v : '';
                if ( (string) ( $c['t'] ?? '' ) === 's' ) {
                    $v = $strings[ (int) $v ] ?? '';
                }
                $sparse[ $col_idx ] = $v;
            }
            if ( ! empty( $sparse ) ) {
                ksort( $sparse );
                $max = max( array_keys( $sparse ) );
                $dense = array();
                for ( $i = 0; $i <= $max; $i++ ) {
                    $dense[] = $sparse[ $i ] ?? '';
                }
                $rows[] = $dense;
            }
        }
        return $rows;
    }

    /**
     * Convert an Excel column letter to a 0-based index.
     * A=0, B=1, …, Z=25, AA=26, AB=27, …
     */
    private static function col_letter_to_index( string $col ): int {
        $col   = strtoupper( $col );
        $index = 0;
        $len   = strlen( $col );
        for ( $i = 0; $i < $len; $i++ ) {
            $index = $index * 26 + ( ord( $col[ $i ] ) - ord( 'A' ) + 1 );
        }
        return $index - 1;
    }

    /**
     * Map a raw CSV/XLSX data row (array of values) onto customer field keys
     * using the header row for alignment.
     *
     * Accepted header names (case-insensitive):
     *   first_name | nombre       → first_name
     *   last_name  | apellido     → last_name
     *   email                     → email
     *   phone      | telefono     → phone
     *   rut                       → rut
     *   organization | empresa    → organization
     *   project_slug | proyecto   → project_slug
     *   company_id                → company_id
     *   external_id | id_externo  → external_id
     *   customer_stage | etapa    → customer_stage
     *   amount     | monto        → amount
     *   started_at | inicio       → started_at
     *   completed_at | fin        → completed_at
     */
    private static function map_row( array $headers, array $row ): array {
        $map = array(
            'first_name'     => array( 'first_name', 'nombre' ),
            'last_name'      => array( 'last_name', 'apellido' ),
            'email'          => array( 'email' ),
            'phone'          => array( 'phone', 'telefono', 'teléfono' ),
            'rut'            => array( 'rut' ),
            'organization'   => array( 'organization', 'empresa' ),
            'project_slug'   => array( 'project_slug', 'proyecto' ),
            'company_id'     => array( 'company_id' ),
            'external_id'    => array( 'external_id', 'id_externo' ),
            'customer_stage' => array( 'customer_stage', 'etapa' ),
            'amount'         => array( 'amount', 'monto' ),
            'started_at'     => array( 'started_at', 'inicio' ),
            'completed_at'   => array( 'completed_at', 'fin' ),
        );

        $out = array();
        foreach ( $map as $field => $aliases ) {
            foreach ( $aliases as $alias ) {
                $idx = array_search( $alias, $headers, true );
                if ( $idx !== false && isset( $row[ $idx ] ) ) {
                    $out[ $field ] = trim( (string) $row[ $idx ] );
                    break;
                }
            }
        }
        // Need at least one identity field
        if ( empty( $out['email'] ) && empty( $out['phone'] ) && empty( $out['rut'] )
             && ( empty( $out['first_name'] ) || empty( $out['last_name'] ) ) ) {
            return array();
        }
        return $out;
    }

    // -------------------------------------------------------------------------
    // Helpers — URL builders
    // -------------------------------------------------------------------------

    private static function list_url( array $extra = array() ): string {
        return add_query_arg( array_merge( array( 'page' => self::PAGE ), $extra ), admin_url( 'admin.php' ) );
    }

    public static function edit_url( int $id = 0, array $extra = array() ): string {
        $args = array_merge( array( 'page' => self::PAGE, 'view' => 'edit' ), $extra );
        if ( $id > 0 ) {
            $args['id'] = $id;
        }
        return add_query_arg( $args, admin_url( 'admin.php' ) );
    }

    private static function import_url( array $extra = array() ): string {
        return add_query_arg( array_merge( array( 'page' => self::PAGE, 'view' => 'import' ), $extra ), admin_url( 'admin.php' ) );
    }
}
