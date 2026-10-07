<?php
/**
 * Contract test: ajax_meta_diagnostics() (botón "Probar conexión").
 * Cubre: payload sintético VÁLIDO (user_data.em SHA-256 + event_source_url) ·
 * sin PII real ni token/hash/payload hacia la UI · test_event_code top-level ·
 * error_user_title/error_user_msg sanitizados · cero escrituras comerciales ·
 * PASS solo con HTTP 200. Stubs — sin DB, sin red, sin WP. Run:
 *   php tests/run-meta-diagnostics.php
 */

error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ );
define( 'MINUTE_IN_SECONDS', 60 );

// ── WordPress stubs ──────────────────────────────────────────────────────────
$GLOBALS['__opts']       = array();
$GLOBALS['__http_calls'] = array();
$GLOBALS['__next']       = array( 'response' => array( 'code' => 200 ), 'body' => '{"events_received":1,"fbtrace_id":"AbC123"}' );
$GLOBALS['__transients'] = array();
$GLOBALS['__json']       = null;

class WP_Error {
	private $m;
	public function __construct( $c = '', $m = '' ) { $this->m = $m; }
	public function get_error_message() { return $this->m; }
}
class JsonSent extends Exception {}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function get_option( $k, $d = false ) { return $GLOBALS['__opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opt_writes'][] = $k; return true; }
function home_url( $p = '' ) { return 'https://sitio.test' . $p; }
function admin_url( $p = '' ) { return 'https://sitio.test/wp-admin/' . $p; }
function wp_generate_uuid4() { return 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'; }
function sanitize_text_field( $s ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $s ) ) ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function current_user_can( $c ) { return true; }
function check_ajax_referer( $a ) { return true; }
function set_transient( $k, $v, $ttl ) { $GLOBALS['__transients'][ $k ] = $v; return true; }
function wp_send_json_success( $data ) { $GLOBALS['__json'] = array( 'success' => true, 'data' => $data ); throw new JsonSent(); }
function wp_send_json_error( $data, $code = 0 ) { $GLOBALS['__json'] = array( 'success' => false, 'data' => $data ); throw new JsonSent(); }
function do_action() {}
function add_action() {}
function add_filter() {}
function wp_remote_post( $url, $args ) {
	$GLOBALS['__http_calls'][] = array( 'url' => $url, 'args' => $args );
	return $GLOBALS['__next'];
}
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code'] ?? 0; }
function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }

// ── Fake wpdb: cualquier escritura queda registrada ──────────────────────────
class FakeWpdb {
	public $prefix = 'wp_';
	public $writes = array();
	public function prepare( $q, ...$a ) { return array( 'q' => $q, 'a' => $a ); }
	public function query( $p ) { $this->writes[] = $p; return 1; }
	public function insert( ...$a ) { $this->writes[] = $a; return 1; }
	public function update( ...$a ) { $this->writes[] = $a; return 1; }
	public function get_results( $p ) { return array(); }
	public function get_row( $p ) { return null; }
	public function get_var( $p ) { return null; }
}
$GLOBALS['wpdb'] = new FakeWpdb();

$base = dirname( __DIR__ ) . '/includes/';
require_once $base . 'class-estandar-leads-meta-payload-builder.php';
require_once $base . 'class-estandar-leads-meta-graph-client.php';
require_once $base . 'class-estandar-leads-meta-adapter.php';
require_once $base . 'class-estandar-leads-admin.php';

$pass = 0; $fail = 0;
function check( $label, $cond ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "PASS  $label\n"; }
	else { $fail++; echo "FAIL  $label\n"; }
}
function run_diagnostics() {
	$GLOBALS['__http_calls'] = array();
	$GLOBALS['__json']       = null;
	try {
		Estandar_Leads_Admin::ajax_meta_diagnostics();
	} catch ( JsonSent $e ) {
		// respuesta capturada
	}
	return $GLOBALS['__json'];
}
$GLOBALS['__opts'] = array(
	'estandar_leads_meta_pixel_id'     => '1234567890',
	'estandar_leads_meta_access_token' => 'EAA-SECRET-TOKEN-XYZ' . str_repeat( 'x', 90 ),
);
$expected_em = hash( 'sha256', 'diagnostic@example.com' );

// ── 1. Payload de prueba VÁLIDO (los dos requisitos que causaban el 400/100) ─
$json = run_diagnostics();
$sent = json_decode( $GLOBALS['__http_calls'][0]['args']['body'], true );
$ev   = $sent['data'][0];
check( "event_name = 'Lead'", $ev['event_name'] === 'Lead' );
check( "action_source = 'website'", $ev['action_source'] === 'website' );
check( 'event_source_url = home_url(/)', $ev['event_source_url'] === 'https://sitio.test/' );
check( 'user_data.em presente = SHA-256 del email sintético normalizado', ( $ev['user_data']['em'] ?? '' ) === $expected_em );
check( 'em es hex de 64 (nunca email en claro)', preg_match( '/^[a-f0-9]{64}$/', $ev['user_data']['em'] ) === 1 );
check( "event_id = 'diagnostic-<uuid>'", $ev['event_id'] === 'diagnostic-aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee' );
check( 'HTTP 200 → status READY (único PASS)', $json['data']['status'] === 'READY' );
check( 'checks payload: user_data y event_source_url = PASS', $json['data']['payload']['user_data'] === 'PASS' && $json['data']['payload']['event_source_url'] === 'PASS' );

// ── 2. Sin PII real / sin secretos hacia la UI ───────────────────────────────
$body_raw = $GLOBALS['__http_calls'][0]['args']['body'];
check( 'HTTP body sin email sintético en claro', strpos( $body_raw, 'diagnostic@example.com' ) === false );
$ui = json_encode( $json );
check( 'respuesta UI sin access token', strpos( $ui, 'EAA-SECRET-TOKEN-XYZ' ) === false );
check( 'respuesta UI sin email sintético', strpos( $ui, 'diagnostic@example.com' ) === false );
check( 'respuesta UI sin hash em', strpos( $ui, $expected_em ) === false );
check( 'respuesta UI sin payload (solo flags PASS/FAIL)', strpos( $ui, 'user_data' ) !== false && strpos( $ui, '"em"' ) === false );
$tr = json_encode( $GLOBALS['__transients'] );
check( 'transient sin token/email/hash', strpos( $tr, 'EAA-SECRET' ) === false && strpos( $tr, 'diagnostic@example.com' ) === false && strpos( $tr, $expected_em ) === false );

// ── 3. test_event_code: top-level del request, nunca dentro del evento ───────
$GLOBALS['__opts']['estandar_leads_meta_test_event_code'] = 'TEST42';
run_diagnostics();
$sent = json_decode( $GLOBALS['__http_calls'][0]['args']['body'], true );
check( 'test_event_code top-level cuando está configurado', ( $sent['test_event_code'] ?? '' ) === 'TEST42' );
check( 'test_event_code NO dentro del evento', ! isset( $sent['data'][0]['test_event_code'] ) );
unset( $GLOBALS['__opts']['estandar_leads_meta_test_event_code'] );

// ── 4. Error de Meta sanitizado (error_user_title / error_user_msg) ─────────
$GLOBALS['__next'] = array(
	'response' => array( 'code' => 400 ),
	'body'     => json_encode( array( 'error' => array(
		'code'             => 100,
		'message'          => 'Invalid parameter',
		'error_user_title' => '<b>Evento inválido</b>',
		'error_user_msg'   => "Falta <script>alert(1)</script> user_data\nen el evento.",
	) ) ),
);
$json = run_diagnostics();
$err  = $json['data']['error'];
check( 'HTTP 400 → status BLOCKED', $json['data']['status'] === 'BLOCKED' );
check( 'error.code y message expuestos', $err['code'] === 100 && $err['message'] === 'Invalid parameter' );
check( 'error_user_title sanitizado (sin markup)', $err['user_title'] === 'Evento inválido' );
check( 'error_user_msg sanitizado (sin script/saltos)', $err['user_msg'] === 'Falta alert(1) user_data en el evento.' );
check( 'reason usa el user_msg de Meta', $json['data']['reason'] === $err['user_msg'] );
$GLOBALS['__next'] = array( 'response' => array( 'code' => 200 ), 'body' => '{"events_received":1}' );

// ── 5. Cero escrituras comerciales (no encola, no leads, no outcomes) ───────
$GLOBALS['wpdb']->writes = array();
$GLOBALS['__opt_writes'] = array();
run_diagnostics();
check( 'diagnóstico → 0 escrituras en DB (sin cola, sin leads, sin outcomes)', $GLOBALS['wpdb']->writes === array() );
check( 'diagnóstico → 0 update_option', ( $GLOBALS['__opt_writes'] ?? array() ) === array() );
check( 'único efecto persistente = transient del último diagnóstico', array_keys( $GLOBALS['__transients'] ) === array( 'estandar_meta_diag_last' ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail === 0 ? 0 : 1 );
