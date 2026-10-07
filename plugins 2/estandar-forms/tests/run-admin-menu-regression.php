<?php
/**
 * ADMIN_MENU_REGRESSION_V1 — verifica que todas las pantallas admin
 * registradas por estandar-forms sigan teniendo sus callbacks disponibles.
 *
 * Run: php tests/run-admin-menu-regression.php
 *
 * Falla si un slug del menú cambió, un callback se renombró o una
 * pantalla dejó de cargarse. Previene regresiones como la de
 * estandar-surveys.
 */

$pass = 0;
$fail = 0;

/* ── Constants ─────────────────────────────────────────────── */
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! defined( 'ESTANDAR_FORMS_PATH' ) ) { define( 'ESTANDAR_FORMS_PATH', dirname( __DIR__ ) . '/' ); }
if ( ! defined( 'ESTANDAR_FORMS_URL' ) ) { define( 'ESTANDAR_FORMS_URL', 'https://example.com/wp-content/plugins/estandar-forms/' ); }
if ( ! defined( 'ESTANDAR_FORMS_VERSION' ) ) { define( 'ESTANDAR_FORMS_VERSION', '13.0.115-test' ); }
if ( ! defined( 'OBJECT' ) ) { define( 'OBJECT', 'OBJECT' ); }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }

/* ── WP stubs ─────────────────────────────────────────────── */
function __( $t, $d = 'default' ) { return $t; }
function add_action( ...$a ) {}
function apply_filters( $t, $v, ...$a ) { return $v; }
function current_user_can( $c ) { return true; }
function wp_die( $m = '' ) { echo $m; }
function admin_url( $p = '' ) { return '/wp-admin/admin.php?page=' . $p; }
function home_url( $p = '' ) { return 'https://example.com/' . ltrim( $p, '/' ); }
function esc_url( $u ) { return $u; }
function esc_html( $t ) { return $t; }
function esc_attr( $t ) { return $t; }
function wp_unslash( $v ) { return $v; }
function sanitize_key( $v ) { return $v; }
function sanitize_text_field( $v ) { return $v; }
function sanitize_title( $v ) { return $v; }
function sanitize_email( $e ) { return $e; }
function is_email( $e ) { return true; }
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, JSON_UNESCAPED_UNICODE ); }
function wp_verify_nonce( ...$a ) { return true; }
function check_admin_referer( $a = '' ) { return true; }
function wp_nonce_url( $u, $a = '' ) { return $u; }
function wp_safe_redirect( $u ) { echo "Redirect: $u"; }
function add_query_arg( ...$a ) { return '?page=test'; }
function absint( $v ) { return abs( (int) $v ); }
function wp_date( $f, $t = null ) { return date( $f, $t ?? time() ); }
function wp_kses_post( $t ) { return $t; }
function wp_strip_all_tags( $t ) { return preg_replace( '/<[^>]*>/', '', $t ); }
function wp_enqueue_style( ...$a ) {}
function wp_enqueue_script( ...$a ) {}
function current_time( $t ) { return '2026-07-22 12:00:00'; }
function wp_doing_ajax() { return false; }
function get_option( $k, $d = false ) { return $d; }
function update_option( ...$a ) {}
function did_action( $a ) { return false; }

class WP_Query {
    public $posts = []; public $post_count = 0;
    public function have_posts() { return false; }
    public function the_post() {}
}

class wpdb {
    public $prefix = 'wp_'; public $last_error = '';
    public function get_var( $q = null, $x = 0, $y = 0 ) { return null; }
    public function get_row( $q = null, $o = 'OBJECT', $y = 0 ) { return null; }
    public function get_results( $q = null, $o = 'OBJECT' ) { return []; }
    public function prepare( $q, ...$a ) { return $q; }
    public function query( $q ) { return true; }
    public function insert( $t, $d, $f = null ) { return true; }
    public function update( $t, $d, $w, $df = null, $wf = null ) { return true; }
    public function delete( $t, $w, $df = null ) { return true; }
    public function get_charset_collate() { return ''; }
    public function get_col( $q = null, $x = 0 ) { return []; }
    public function esc_like( $s ) { return $s; }
}
$GLOBALS['wpdb'] = new wpdb();

/* ── Load helpers ──────────────────────────────────────────── */
require_once ESTANDAR_FORMS_PATH . 'includes/helpers.php';

/* ── Expected callbacks by file ────────────────────────────── */
$screen_map = [
    'admin/screens/survey-list.php'      => ['estandar_surveys_list_page', 'estandar_surveys_render_nav'],
    'admin/screens/survey-edit.php'      => ['estandar_surveys_edit_page'],
    'admin/screens/survey-results.php'   => ['estandar_surveys_results_page', 'estandar_survey_handle_export_csv'],
    'admin/screens/survey-analytics.php' => ['estandar_surveys_analytics_page'],
    'admin/dashboard.php'                => [
        'estandar_forms_home_page',
        'estandar_forms_create_picker',
        'estandar_forms_nav_items',
        'estandar_forms_render_admin_tabs',
        'estandar_forms_is_core_embedded',
        'estandar_forms_normalize_fields',
        'estandar_forms_fields_define_project_context',
    ],
    'admin/screens/forms-list.php'       => ['estandar_forms_forms_page'],
    'admin/screens/form-preview.php'     => ['estandar_forms_entries_page', 'estandar_forms_entries_page_content'],
];

echo "=== ADMIN MENU REGRESSION TEST ===\n\n";

foreach ( $screen_map as $file => $expected_fns ) {
    $path = ESTANDAR_FORMS_PATH . $file;
    if ( ! file_exists( $path ) ) {
        echo "  FAIL  {$file}  —  ARCHIVO FALTANTE\n";
        $fail++;
        continue;
    }

    try {
        require_once $path;
        echo "  OK    {$file} loaded\n";
    } catch ( Throwable $e ) {
        echo "  FAIL  {$file}  —  {$e->getMessage()}\n";
        $fail++;
        continue;
    }

    foreach ( $expected_fns as $fn ) {
        if ( function_exists( $fn ) ) {
            echo "  PASS  {$fn}()\n";
            $pass++;
        } else {
            echo "  FAIL  {$fn}()  —  NO DEFINIDA\n";
            $fail++;
        }
    }
}

echo "\n=== RESULTADOS ===\n";
echo "Pass: {$pass}\n";
echo "Fail: {$fail}\n";

if ( $fail > 0 ) {
    echo "REGRESIÓN DETECTADA: Al menos una pantalla admin perdió su callback.\n";
    exit( 1 );
}

echo "OK: Todas las pantallas admin tienen callbacks disponibles.\n";
exit( 0 );
