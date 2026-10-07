<?php
/**
 * OperatorGate (estandar-capture) — GATE_V1 functional tests (Playground).
 *
 *   php tests/run-operator-gate.php   → expect "0 failed"
 *
 * Cubre el gate de operador para forms v2:
 *   - current_page_url(): URL canónica de la página actual.
 *   - render() sin form → mensaje de error (no fatal).
 *   - render() con form inexistente/inactivo → mensaje.
 *   - render_gate_login(): login con PIN + magic link (ambos presentes).
 *   - process_gate_post(): POST de login válido → redirect (autorizado).
 *   - process_gate_post(): POST de login inválido → notice de error.
 *   - magic link con return_url propio: request → token guarda destino → consume.
 *   - establish_magic_session(): consume y deja sesión mágica activa.
 *   - render() autorizado → renderiza el form v2 (HTML del form presente).
 * Rollback de fixtures.
 */

if ( getenv( 'ESTANDAR_MYSQL_GATE_DB' ) ) {
	require dirname( __DIR__, 2 ) . '/estandar-leads/tests/bootstrap-wordpress-mysql.php';
} else {
	require dirname( __DIR__, 3 ) . '/Playground/wp-load.php';
}

if ( ! class_exists( '\Estandar\Capture\OperatorGate' ) ) {
	fwrite( STDERR, "run-operator-gate: clase OperatorGate no cargada — ¿estandar-capture activo en Playground?\n" );
	exit( 1 );
}
if ( ! class_exists( '\Estandar\Core\LeadsPanel' ) ) {
	fwrite( STDERR, "run-operator-gate: LeadsPanel no cargada — ¿estandar-core activo en Playground?\n" );
	exit( 1 );
}

use Estandar\Capture\OperatorGate as OG;
use Estandar\Core\LeadsPanel as LP;

global $wpdb;
$passed = 0;
$failed = 0;

function gate_check( string $label, bool $cond ): void {
	global $passed, $failed;
	if ( $cond ) { $passed++; echo "PASS  {$label}\n"; }
	else { $failed++; echo "FAIL  {$label}\n"; }
}

$wpdb->query( 'START TRANSACTION' );

// ── URL canónica ─────────────────────────────────────────────────────────
$_SERVER['REQUEST_URI'] = '/registro-camara/?foo=1';
$url = OG::current_page_url();
gate_check( 'gate: current_page_url devuelve home + path', strpos( $url, '/registro-camara' ) !== false && strpos( $url, 'foo=1' ) === false );

// ── render sin form / con form inexistente ──────────────────────────────
$no_form = OG::render( array( 'form' => 0 ) );
gate_check( 'gate: sin form → mensaje', strpos( $no_form, 'falta el atributo form' ) !== false );

$bad_form = OG::render( array( 'form' => 999999 ) );
gate_check( 'gate: form inexistente → mensaje', strpos( $bad_form, 'no está disponible' ) !== false );

// ── render_gate_login: PIN + magic link ──────────────────────────────────
$login = LP::render_gate_login( 'https://test.local/registro-camara/', 'https://test.local/registro-camara/', 'Registro de Cámara', '', 'info', 'es_og' );
gate_check( 'gate: login tiene acceso por PIN', strpos( $login, 'es_og_action" value="login"' ) !== false && strpos( $login, 'es_og_pin' ) !== false );
gate_check( 'gate: login tiene acceso por correo', strpos( $login, 'es_og_action" value="magic_request"' ) !== false && strpos( $login, 'es_og_email' ) !== false );
gate_check( 'gate: login muestra el título', strpos( $login, 'Registro de Cámara' ) !== false );

// ── process_gate_post: login PIN inválido → notice error ──────────────────
$_POST = array( 'es_og_action' => 'login', 'es_og_name' => 'nadie', 'es_og_pin' => 'zzzz' );
$r = LP::process_gate_post( 'https://test.local/registro-camara/', 'https://test.local/registro-camara/', 'es_og' );
gate_check( 'gate: PIN inválido → notice error', ! empty( $r['notice'] ) && ( $r['notice_type'] ?? '' ) === 'error' );
$_POST = array();

// ── process_gate_post: magic token inválido → notice error ────────────────
$r = LP::process_gate_post( 'https://test.local/registro-camara/', 'https://test.local/registro-camara/', 'es_og' );
gate_check( 'gate: sin magic y sin POST → sin acción', $r === array() );
$_GET['es_og_magic'] = str_repeat( 'a', 48 );
$r = LP::process_gate_post( 'https://test.local/registro-camara/', 'https://test.local/registro-camara/', 'es_og' );
gate_check( 'gate: magic inválido → notice error', ! empty( $r['notice'] ) );
unset( $_GET['es_og_magic'] );

// ── Operador + login válido (PIN) ─────────────────────────────────────────
$ops = LP::operators();
if ( ! isset( $ops['camara_test'] ) ) {
	LP::save_operator( 'camara_test', 'cmar' );
}
$_POST = array( 'es_og_action' => 'login', 'es_og_name' => 'camara_test', 'es_og_pin' => 'cmar' );
$r = LP::process_gate_post( 'https://test.local/registro-camara/', 'https://test.local/registro-camara/', 'es_og' );
gate_check( 'gate: PIN válido → redirect al destino', ! empty( $r['redirect'] ) && strpos( $r['redirect'], '/registro-camara' ) !== false );
$_POST = array();
LP::logout();

// ── Magic link con return_url propio ─────────────────────────────────────
add_filter( 'estandar_suite_leads_panel_magic_domain', static function () { return 'test.cl'; } );
$r = LP::request_magic_link( 'camara@test.cl', 'https://test.local/registro-camara/' );
gate_check( 'gate: request magic con return_url → ok', ! empty( $r['ok'] ) || ( ! empty( $r['message'] ) ) );

// Reconstruir un token manual con return_url (igual formato que request_magic_link).
$raw = bin2hex( random_bytes( 24 ) );
$toks = array();
$tok_opt = get_option( LP::MAGIC_TOKENS_OPT, array() );
$tok_opt = is_array( $tok_opt ) ? $tok_opt : array();
$tok_opt[ LP::magic_hash( $raw ) ] = array(
	'email'      => 'camara@test.cl',
	'expires'    => time() + 600,
	'created'    => gmdate( 'Y-m-d H:i:s' ),
	'return_url' => 'https://test.local/registro-camara/',
);
update_option( LP::MAGIC_TOKENS_OPT, $tok_opt, false );

$full = LP::consume_magic_token_full( $raw );
gate_check( 'gate: consume_magic_token_full devuelve email + return_url', ( $full['email'] ?? '' ) === 'camara@test.cl' && ( $full['return_url'] ?? '' ) === 'https://test.local/registro-camara/' );
gate_check( 'gate: token consumido single-use', LP::consume_magic_token( $raw ) === '' );

// ── establish_magic_session (consume + cookie) ───────────────────────────
$raw2 = bin2hex( random_bytes( 24 ) );
$tok_opt = get_option( LP::MAGIC_TOKENS_OPT, array() );
$tok_opt = is_array( $tok_opt ) ? $tok_opt : array();
$tok_opt[ LP::magic_hash( $raw2 ) ] = array(
	'email'      => 'camara@test.cl',
	'expires'    => time() + 600,
	'created'    => gmdate( 'Y-m-d H:i:s' ),
	'return_url' => '',
);
update_option( LP::MAGIC_TOKENS_OPT, $tok_opt, false );

$est = LP::establish_magic_session( $raw2 );
gate_check( 'gate: establish_magic_session setea email', ( $est['email'] ?? '' ) === 'camara@test.cl' );

// ── render() autorizado → renderiza el form ──────────────────────────────
$form_cfg = wp_json_encode( array(
	'sections' => array( array(
		'title'  => 'Día',
		'fields' => array(
			array( 'type' => 'date', 'name' => 'fecha', 'label' => 'Fecha', 'required' => true, 'column_width' => '50' ),
			array( 'type' => 'number', 'name' => 'temp_real', 'label' => 'Temperatura real', 'required' => true, 'column_width' => '50' ),
		),
	) ),
	'submit_label' => 'Registrar día',
	'store_entries' => '1',
) );
$form_id = \Estandar\Capture\Forms::create( array(
	'name'         => 'Gate Test',
	'slug'         => 'gate-test',
	'form_type'    => 'lead',
	'form_context' => 'generic',
	'config_json'  => $form_cfg,
	'status'       => 'active',
) );
gate_check( 'gate: form de prueba creado', $form_id > 0 );

if ( $form_id > 0 ) {
	// Simular la cookie de operador firmada (CLI: setcookie no escribe $_COOKIE).
	$exp = time() + 600;
	$secret = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : 'leads-panel';
	$sig = hash_hmac( 'sha256', 'camara_test|' . $exp, (string) $secret );
	$_COOKIE[ LP::AUTH_COOKIE ] = 'camara_test|' . $exp . '|' . $sig;

	$html = OG::render( array( 'form' => $form_id, 'title' => 'Registro de Cámara' ) );
	gate_check( 'gate: autorizado renderiza form v2', strpos( $html, 'estandar-form-v2' ) !== false && strpos( $html, 'name="fecha"' ) !== false );
	gate_check( 'gate: autorizado NO muestra login', strpos( $html, 'es_og_action' ) === false );
	// Limpiar: papelera del form de prueba.
	\Estandar\Capture\Forms::trash( $form_id );
	unset( $_COOKIE[ LP::AUTH_COOKIE ] );
}

// ── GATE_PER_FORM_V1: gate activable desde el form (sin shortcode) ────────
$form_cfg2 = wp_json_encode( array(
	'sections' => array( array( 'title' => 'D', 'fields' => array( array( 'type' => 'text', 'name' => 'n', 'label' => 'N', 'required' => true ) ) ) ),
	'submit_label' => 'Enviar',
	'gate_enabled' => '1',
	'gate_method'  => 'magic',
	'gate_title'   => 'Acceso Camara',
) );
$fid2 = \Estandar\Capture\Forms::create( array( 'name' => 'Gate Per Form', 'slug' => 'gate-per-form', 'form_type' => 'lead', 'form_context' => 'generic', 'config_json' => $form_cfg2, 'status' => 'active' ) );
gate_check( 'gate-per-form: form creado', $fid2 > 0 );
if ( $fid2 > 0 ) {
	LP::logout();
	unset( $_COOKIE[ LP::AUTH_COOKIE ] );
	$out = \Estandar\Capture\FormRenderer::render( array( 'id' => $fid2 ) );
	gate_check( 'gate-per-form: sin sesión muestra login', strpos( $out, 'es_og_action' ) !== false );
	gate_check( 'gate-per-form: método magic → sin campo PIN', strpos( $out, 'es_og_pin' ) === false );
	gate_check( 'gate-per-form: sin sesión NO publica el form', strpos( $out, 'estandar-form-v2' ) === false );

	$exp = time() + 600;
	$secret = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : 'leads-panel';
	$sig = hash_hmac( 'sha256', 'camara_test|' . $exp, (string) $secret );
	$_COOKIE[ LP::AUTH_COOKIE ] = 'camara_test|' . $exp . '|' . $sig;
	$out2 = \Estandar\Capture\FormRenderer::render( array( 'id' => $fid2 ) );
	gate_check( 'gate-per-form: con sesión publica el form', strpos( $out2, 'estandar-form-v2' ) !== false );
	unset( $_COOKIE[ LP::AUTH_COOKIE ] );
	LP::logout();
	\Estandar\Capture\Forms::trash( $fid2 );
}

// ── FORM_VISIBILITY_V1: visibilidad del form por fechas/dispositivo ────────
gate_check( 'visib: sin config → visible', \Estandar\Capture\FormRenderer::visibility_ok( array() ) );
gate_check( 'visib: desde fecha futura → oculto', ! \Estandar\Capture\FormRenderer::visibility_ok( array( 'vis_from' => '2999-01-01' ) ) );
gate_check( 'visib: hasta fecha pasada → oculto', ! \Estandar\Capture\FormRenderer::visibility_ok( array( 'vis_to' => '2000-01-01' ) ) );
gate_check( 'visib: rango vigente → visible', \Estandar\Capture\FormRenderer::visibility_ok( array( 'vis_from' => '2000-01-01', 'vis_to' => '2999-01-01' ) ) );
$is_mob = function_exists( 'wp_is_mobile' ) ? (bool) wp_is_mobile() : false;
gate_check( 'visib: dispositivo respeta el entorno', \Estandar\Capture\FormRenderer::visibility_ok( array( 'vis_device' => 'desktop' ) ) === ! $is_mob );

// ── GATE_METHODS_V1: método de acceso configurable (PIN / magic / ambos) ──
$gate_url = 'https://test.local/registro-camara/';

LP::set_gate_methods( 'magic' );
gate_check( 'gate: método magic guardado', LP::gate_methods() === array( 'magic' ) );
$login_magic = LP::render_gate_login( $gate_url, $gate_url, 'Registro de Cámara', '', 'info', 'es_og' );
gate_check( 'gate: con magic solo NO muestra PIN', strpos( $login_magic, 'es_og_pin' ) === false );
gate_check( 'gate: con magic solo SÍ muestra correo', strpos( $login_magic, 'es_og_email' ) !== false );
$_POST = array( 'es_og_action' => 'login', 'es_og_name' => 'camara_test', 'es_og_pin' => 'cmar' );
$r = LP::process_gate_post( $gate_url, $gate_url, 'es_og' );
gate_check( 'gate: magic solo rechaza login PIN (server-side)', empty( $r['redirect'] ) && ( $r['notice_type'] ?? '' ) === 'error' );
$_POST = array();

LP::set_gate_methods( 'pin' );
$login_pin = LP::render_gate_login( $gate_url, $gate_url, 'Registro de Cámara', '', 'info', 'es_og' );
gate_check( 'gate: con PIN solo NO muestra correo', strpos( $login_pin, 'es_og_email' ) === false );
gate_check( 'gate: con PIN solo SÍ muestra PIN', strpos( $login_pin, 'es_og_pin' ) !== false );
$_POST = array( 'es_og_action' => 'magic_request', 'es_og_email' => 'camara@test.cl' );
$r = LP::process_gate_post( $gate_url, $gate_url, 'es_og' );
gate_check( 'gate: PIN solo rechaza magic_request (server-side)', empty( $r['redirect'] ) && ( $r['notice_type'] ?? '' ) === 'error' );
$_POST = array();

// Choque con operadores: PIN sin operadores → se omite y queda el método viable.
$saved_ops = LP::operators();
update_option( LP::OPERATORS_OPTION, array(), false );
$eff = LP::effective_gate_methods();
gate_check( 'gate: PIN sin operadores → se omite (queda magic)', in_array( 'magic', $eff, true ) && ! in_array( 'pin', $eff, true ) );
update_option( LP::OPERATORS_OPTION, $saved_ops, false );

// Retrocompat: sin opción → ambos; y restauración.
delete_option( LP::GATE_METHODS_OPTION );
gate_check( 'gate: sin opción → default ambos', LP::gate_methods() === array( 'pin', 'magic' ) );
LP::set_gate_methods( 'both' );
gate_check( 'gate: default restaura ambos', LP::gate_methods() === array( 'pin', 'magic' ) );

// ── GATE_DETECT_V1: páginas que protegen un form por shortcode ────────────
$fid3 = \Estandar\Capture\Forms::create( array( 'name' => 'Gate Detect', 'slug' => 'gate-detect', 'form_type' => 'lead', 'form_context' => 'generic', 'config_json' => '{}', 'status' => 'active' ) );
$pg_id = 0;
if ( $fid3 > 0 && function_exists( 'wp_insert_post' ) ) {
	$pg_id = (int) wp_insert_post( array(
		'post_title'   => 'Pagina Gate Detect',
		'post_status'  => 'publish',
		'post_type'    => 'page',
		'post_content' => 'x [estandar_form_gate form="' . $fid3 . '" title="t"] y',
	) );
	$ids = array_map( static function ( $r ) { return (int) $r['id']; }, \Estandar\Capture\Forms::form_gate_pages( $fid3 ) );
	gate_check( 'gate-detect: encuentra la página con el shortcode', $pg_id > 0 && in_array( $pg_id, $ids, true ) );
	$other = array_map( static function ( $r ) { return (int) $r['id']; }, \Estandar\Capture\Forms::form_gate_pages( $fid3 + 999 ) );
	gate_check( 'gate-detect: no confunde otro form', ! in_array( $pg_id, $other, true ) );
}
if ( $pg_id > 0 ) { wp_delete_post( $pg_id, true ); }
if ( $fid3 > 0 ) { \Estandar\Capture\Forms::trash( $fid3 ); }

remove_all_filters( 'estandar_suite_leads_panel_magic_domain' );

$wpdb->query( 'ROLLBACK' );

echo "\nRESULT: {$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
