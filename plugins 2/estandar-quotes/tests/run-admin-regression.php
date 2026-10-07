<?php
/**
 * Confirms the frontend Operator Gateway (V1) does not regress the existing
 * wp-admin workspace: capabilities are still granted the same way, the admin
 * menu/navigation registration is untouched, Estandar_Quotes_Admin still
 * composes Estandar_Quotes_Document_Form (no duplicated field renderer), and
 * the gateway class itself never touches WordPress roles/capabilities/admin
 * menu hooks — it is purely a template_redirect front-end controller.
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );

function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $v ) ); }
function sanitize_text_field( $v ) { return trim( (string) $v ); }
function sanitize_email( $v ) { return trim( strtolower( (string) $v ) ); }
function sanitize_textarea_field( $v ) { return trim( (string) $v ); }
function wp_unslash( $v ) { return $v; }
function absint( $v ) { return abs( (int) $v ); }
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( $v, $d = '' ) { return esc_html( $v ); }
function esc_attr( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $v ) { return $v; }
function esc_textarea( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function add_query_arg( $args, $url ) { $sep = ( strpos( $url, '?' ) === false ) ? '?' : '&'; return $url . $sep . http_build_query( $args ); }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . $path; }
function get_option( $k, $default = false ) { return $GLOBALS['options'][ $k ] ?? $default; }
function register_setting( ...$a ) {}
function add_settings_section( ...$a ) {}
function add_settings_field( ...$a ) {}
function add_action( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['actions'][ $hook ][] = $cb; }
function add_filter( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['filters'][ $hook ][] = $cb; }
function apply_filters( $hook, $value ) { return $value; }
function do_action( $hook, ...$args ) {}

/** wp_die must be observable, not fatal, so render_workspace() can be exercised without exit(). */
class Wp_Die_Signal extends Exception {}
function wp_die( $message = '', $code = '' ) { throw new Wp_Die_Signal( (string) $message ); }

/** Fake role registry: mirrors get_role()->add_cap() exactly as WP_Role does. */
class Fake_Role {
	public $capabilities = array();
	public function add_cap( $cap ) { $this->capabilities[ $cap ] = true; }
	public function has_cap( $cap ) { return ! empty( $this->capabilities[ $cap ] ); }
}
$GLOBALS['roles'] = array( 'administrator' => new Fake_Role() );
function get_role( $role ) { return $GLOBALS['roles'][ $role ] ?? null; }

$GLOBALS['current_user_caps'] = array();
function current_user_can( $cap ) { return ! empty( $GLOBALS['current_user_caps'][ $cap ] ); }

$GLOBALS['registered_admin_menu_pages'] = array();
function estandar_register_navigation_page( array $args ) { $GLOBALS['registered_admin_menu_pages'][] = $args; }

$pass = 0; $fail = 0;
function check( $name, $ok ) { global $pass, $fail; if ( $ok ) { ++$pass; echo "PASS $name\n"; } else { ++$fail; echo "FAIL $name\n"; } }

require dirname( __DIR__ ) . '/includes/class-estandar-quotes-document-form.php';
require dirname( __DIR__ ) . '/includes/class-estandar-quotes-admin.php';

// ---------------------------------------------------------------------
// 1) Capabilities: admin activation still grants the same six capabilities
//    to Administrator, independent of the frontend gateway.
// ---------------------------------------------------------------------
Estandar_Quotes_Admin::activate_capabilities();
$expected_caps = array(
	Estandar_Quotes_Admin::CAP_VIEW, Estandar_Quotes_Admin::CAP_EDIT, Estandar_Quotes_Admin::CAP_ISSUE,
	Estandar_Quotes_Admin::CAP_PDF, Estandar_Quotes_Admin::CAP_SEND, Estandar_Quotes_Admin::CAP_SETTINGS,
);
$role = get_role( 'administrator' );
$all_granted = true;
foreach ( $expected_caps as $cap ) { if ( ! $role->has_cap( $cap ) ) { $all_granted = false; } }
check( 'administrator role still receives all 6 Quotes capabilities on activation', $all_granted );

// ---------------------------------------------------------------------
// 2) Navigation schema: the admin workspace/settings pages are still
//    registered gated by CAP_VIEW / CAP_SETTINGS, unaffected by the gateway.
// ---------------------------------------------------------------------
$schema = Estandar_Quotes_Admin::navigation_schema( array( 'top_nav' => array(), 'pages' => array() ) );
check( 'workspace nav entry still requires estandar_quotes_view', $schema['top_nav']['quotes']['capability'] === Estandar_Quotes_Admin::CAP_VIEW );
check( 'workspace page still registered under the existing slug', isset( $schema['pages']['estandar-quotes'] ) );
check( 'settings page still registered under the existing slug', isset( $schema['pages']['estandar-quotes-settings'] ) );

// ---------------------------------------------------------------------
// 3) menu() still wires both pages through the canonical navigation
//    registrar with unchanged capabilities (no route added/removed).
// ---------------------------------------------------------------------
Estandar_Quotes_Admin::menu();
$slugs = array_column( $GLOBALS['registered_admin_menu_pages'], 'slug' );
check( 'menu() still registers the workspace page', in_array( 'estandar-quotes', $slugs, true ) );
check( 'menu() still registers the settings page', in_array( 'estandar-quotes-settings', $slugs, true ) );
foreach ( $GLOBALS['registered_admin_menu_pages'] as $page ) {
	if ( $page['slug'] === 'estandar-quotes' ) { check( 'workspace route capability unchanged', $page['capability'] === Estandar_Quotes_Admin::CAP_VIEW ); }
	if ( $page['slug'] === 'estandar-quotes-settings' ) { check( 'settings route capability unchanged', $page['capability'] === Estandar_Quotes_Admin::CAP_SETTINGS ); }
}

// ---------------------------------------------------------------------
// 4) render_workspace() still gates on current_user_can(CAP_VIEW); a user
//    without it is refused exactly as before (wp_die), gateway or not.
// ---------------------------------------------------------------------
$GLOBALS['current_user_caps'] = array();
$denied = false;
try { Estandar_Quotes_Admin::render_workspace(); } catch ( Wp_Die_Signal $e ) { $denied = true; }
check( 'render_workspace still denies a user without estandar_quotes_view', $denied );

// ---------------------------------------------------------------------
// 5) Estandar_Quotes_Admin still composes Document_Form rather than a
//    duplicated field renderer (source-level check: no re-implementation).
// ---------------------------------------------------------------------
$admin_src = file_get_contents( dirname( __DIR__ ) . '/includes/class-estandar-quotes-admin.php' );
check( 'Estandar_Quotes_Admin still delegates field rendering to Document_Form::render_fields', strpos( $admin_src, 'Estandar_Quotes_Document_Form::render_fields' ) !== false );
check( 'Estandar_Quotes_Admin still delegates request parsing to Document_Form::document_from_request', strpos( $admin_src, 'Estandar_Quotes_Document_Form::document_from_request' ) !== false );

// ---------------------------------------------------------------------
// 6) The frontend gateway itself must never touch WordPress
//    roles/capabilities or the admin menu — it is a template_redirect-only
//    controller layered on top of the existing admin surface, not a
//    replacement for it.
// ---------------------------------------------------------------------
$gateway_src = file_get_contents( dirname( __DIR__ ) . '/includes/class-estandar-quotes-operator-gateway.php' );
check( 'gateway never calls add_cap/get_role (no capability mutation)', strpos( $gateway_src, 'add_cap' ) === false && strpos( $gateway_src, 'get_role' ) === false );
check( 'gateway never hooks admin_menu (no admin route registration)', strpos( $gateway_src, 'admin_menu' ) === false );
check( 'gateway never calls current_user_can (identity is Leads-operator based, not a WP user)', strpos( $gateway_src, 'current_user_can' ) === false );
check( 'gateway only hooks template_redirect (front-end only, no admin surface)', strpos( $gateway_src, "add_action( 'template_redirect'" ) !== false );

// ---------------------------------------------------------------------
// 7) Admin handle_handoff() now peeks operator handoffs and redirects
//    them to the frontend Gateway (/cotizacion/{token}) instead of
//    consuming them inside wp-admin with get_current_user_id(), which
//    would always fail because the transient stores a Leads operator_id.
//    Legacy/admin-only handoffs (no operator_id) still work as before.
// ---------------------------------------------------------------------
check( 'admin handle_handoff still references peek_handoff for operator detection', strpos( $admin_src, 'peek_handoff' ) !== false );
check( 'admin redirects operator handoffs to the frontend Gateway URL', strpos( $admin_src, "home_url( '/cotizacion/'" ) !== false );
check( 'consume_handoff in admin still gated for non-operator (legacy) path', strpos( $admin_src, 'consume_handoff' ) !== false );

exit( $fail ? 1 : 0 );
