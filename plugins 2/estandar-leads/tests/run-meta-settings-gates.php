<?php
/**
 * Contract test: gates de configuración de Estandar_Leads_Meta_Adapter.
 * Cubre: test_mode retiene/despacha la cola · defaults retrocompatibles
 * (enabled/server_events '' = ON) · master switch · save/get settings ·
 * telemetría sin PII ni token (incluye META_DEBUG_SEND).
 * Stubs $wpdb + HTTP + options — sin DB ni red. Run:
 *   php tests/run-meta-settings-gates.php
 */

error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ );

// ── WordPress stubs ──────────────────────────────────────────────────────────
$GLOBALS['__opts']       = array();
$GLOBALS['__http_calls'] = array();
$GLOBALS['__next']       = array( 'response' => array( 'code' => 200 ), 'body' => '{"events_received":1}' );
$GLOBALS['__telemetry']  = array();

class WP_Error {
	private $m;
	public function __construct( $c = '', $m = '' ) { $this->m = $m; }
	public function get_error_message() { return $this->m; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function get_option( $k, $d = false ) { return $GLOBALS['__opts'][ $k ] ?? $d; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['__opts'][ $k ] = $v; return true; }
function add_option( $k, $v, $d = '', $a = 'yes' ) {
	if ( isset( $GLOBALS['__opts'][ $k ] ) ) { return false; }
	$GLOBALS['__opts'][ $k ] = $v;
	return true;
}
function delete_option( $k ) { unset( $GLOBALS['__opts'][ $k ] ); return true; }
function admin_url( $p = '' ) { return 'https://site.test/wp-admin/' . $p; }
function apply_filters( $h, $v ) { return $v; }
function wp_salt( $s = '' ) { return 'test-salt'; }
function add_action() {}
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function wp_unslash( $s ) { return $s; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function do_action( $hook = '', ...$args ) {
	if ( $hook === 'estandar_telemetry_event' ) {
		$GLOBALS['__telemetry'][] = $args[0] ?? array();
	}
}
function wp_remote_post( $url, $args ) {
	$GLOBALS['__http_calls'][] = array( 'url' => $url, 'args' => $args );
	return $GLOBALS['__next'];
}
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code'] ?? 0; }
function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }

// ── Fake wpdb ────────────────────────────────────────────────────────────────
class FakeWpdb {
	public $prefix = 'wp_';
	public $queries = array();
	public $updates = array();
	public $selects = 0;
	public $pendingItems = array();
	public $leadRow = null;
	public $heldCount = 0; // valor del COUNT(*) que ve el hold de test_mode

	public function prepare( $q, ...$a ) { return array( 'q' => $q, 'a' => $a ); }
	public function query( $p ) { $this->queries[] = $p; return 1; }
	public function get_results( $p ) { $this->selects++; return $this->pendingItems; }
	public function get_row( $p ) { return $this->leadRow; }
	public function get_var( $p ) { return $this->heldCount; }
	public function update( $t, $data, $where, $f = null, $wf = null ) {
		$this->updates[] = array( 'data' => $data, 'where' => $where );
		return 1;
	}
	public function reset() { $this->queries = array(); $this->updates = array(); $this->selects = 0; }
}
$GLOBALS['wpdb'] = new FakeWpdb();

$base = dirname( __DIR__ ) . '/includes/';
require_once $base . 'class-estandar-leads-attribution.php';
require_once $base . 'class-estandar-leads-meta-payload-builder.php';
require_once $base . 'class-estandar-leads-meta-graph-client.php';
require_once $base . 'class-estandar-leads-meta-adapter.php';

$pass = 0; $fail = 0;
function check( $label, $cond ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "PASS  $label\n"; }
	else { $fail++; echo "FAIL  $label\n"; }
}
function configure( array $extra = array() ) {
	$GLOBALS['__opts'] = array_merge( array(
		'estandar_leads_meta_pixel_id'     => 'PIX1',
		'estandar_leads_meta_access_token' => 'SECRET-TOKEN-XYZ',
	), $extra );
}
function reset_all() {
	$GLOBALS['wpdb']->reset();
	$GLOBALS['__http_calls'] = array();
	$GLOBALS['__telemetry']  = array();
}
function pendingItem() {
	return (object) array(
		'id'            => 1,
		'lead_id'       => 87,
		'export_status' => 'RESERVED',
		'event_time'    => '2026-06-20 12:00:00',
		'attempts'      => 0,
		'queue_status'  => 'pending',
	);
}
$GLOBALS['wpdb']->leadRow = (object) array(
	'id'           => 87,
	'lead_uuid'    => 'uuid-87',
	'email'        => 'persona.real@example.com',
	'phone'        => '+56911112222',
	'project_slug' => '',
	'first_fbclid' => 'fb-paid-87',
	'updated_at'   => '2026-06-25 09:00:00',
);

// ── 1. test_mode='1' retiene la cola: ni SELECT ni HTTP ─────────────────────
configure( array( 'estandar_leads_meta_test_mode' => '1' ) );
$GLOBALS['wpdb']->pendingItems = array( pendingItem() );
$GLOBALS['wpdb']->heldCount    = 1; // hay 1 job retenido en la cola
reset_all();
Estandar_Leads_Meta_Adapter::process_queue();
check( 'test_mode=1 → worker no consulta la cola (0 SELECT)', $GLOBALS['wpdb']->selects === 0 );
check( 'test_mode=1 → 0 HTTP hacia Meta', count( $GLOBALS['__http_calls'] ) === 0 );
check( 'test_mode=1 → 0 transiciones de cola (items siguen pending)', count( $GLOBALS['wpdb']->updates ) === 0 );

// Regresión sesión 2026-07-10: el hold debe ser observable SIN debug_logging —
// silencioso, un job CAPTURED retenido es indistinguible de un worker roto.
$hold = array_values( array_filter( $GLOBALS['__telemetry'], function ( $e ) {
	return ( $e['code'] ?? '' ) === 'META_TEST_MODE_HOLD';
} ) );
check( 'test_mode=1 + jobs retenidos → emite META_TEST_MODE_HOLD sin debug_logging', count( $hold ) === 1 );
check( 'META_TEST_MODE_HOLD reporta held_jobs', ( $hold[0]['context']['held_jobs'] ?? null ) === 1 );

// Cola vacía → sin ruido horario: el hold no loguea nada.
$GLOBALS['wpdb']->heldCount = 0;
reset_all();
Estandar_Leads_Meta_Adapter::process_queue();
check( 'test_mode=1 + cola vacía → sin META_TEST_MODE_HOLD (cero ruido)', count( array_filter( $GLOBALS['__telemetry'], function ( $e ) {
	return ( $e['code'] ?? '' ) === 'META_TEST_MODE_HOLD';
} ) ) === 0 );

// ── 2. test_mode desactivado → la MISMA cola se despacha ────────────────────
configure( array( 'estandar_leads_meta_test_mode' => '0' ) );
reset_all();
Estandar_Leads_Meta_Adapter::process_queue();
check( 'test_mode=0 → worker consulta la cola (SELECT ejecutado)', $GLOBALS['wpdb']->selects === 1 );
check( 'test_mode=0 → item pendiente se envía (1 HTTP)', count( $GLOBALS['__http_calls'] ) === 1 );
$last_update = end( $GLOBALS['wpdb']->updates );
check( 'test_mode=0 → item transiciona a completed', ( $last_update['data']['queue_status'] ?? '' ) === 'completed' );

// ── 3. Defaults retrocompatibles: '' (nunca guardado) = habilitado ──────────
configure(); // sin enabled/server_events/test_mode → todos ''
reset_all();
check( "default '' → is_enabled() = true", Estandar_Leads_Meta_Adapter::is_enabled() === true );
check( "default '' → server_events_enabled() = true", Estandar_Leads_Meta_Adapter::server_events_enabled() === true );
Estandar_Leads_Meta_Adapter::process_queue();
check( "instalación pre-toggle configurada sigue enviando (1 HTTP)", count( $GLOBALS['__http_calls'] ) === 1 );

// ── 4. Master switch y gate de Server Events ────────────────────────────────
configure( array( 'estandar_leads_meta_enabled' => '0' ) );
reset_all();
Estandar_Leads_Meta_Adapter::on_status_changed( 87, 'CONTACTED', 'RESERVED', '2026-06-20 12:00:00' );
Estandar_Leads_Meta_Adapter::process_queue();
check( 'enabled=0 → on_status_changed no encola (0 INSERT)', count( $GLOBALS['wpdb']->queries ) === 0 );
check( 'enabled=0 → worker inactivo (0 SELECT, 0 HTTP)', $GLOBALS['wpdb']->selects === 0 && count( $GLOBALS['__http_calls'] ) === 0 );

configure( array( 'estandar_leads_meta_server_events' => '0' ) );
reset_all();
Estandar_Leads_Meta_Adapter::on_status_changed( 87, 'CONTACTED', 'RESERVED', '2026-06-20 12:00:00' );
Estandar_Leads_Meta_Adapter::process_queue();
check( 'server_events=0 → no encola y worker inactivo', count( $GLOBALS['wpdb']->queries ) === 0 && $GLOBALS['wpdb']->selects === 0 );

configure();
reset_all();
Estandar_Leads_Meta_Adapter::on_status_changed( 87, 'CONTACTED', 'RESERVED', '2026-06-20 12:00:00' );
check( 'enabled+configurado → on_status_changed encola (1 INSERT)', count( $GLOBALS['wpdb']->queries ) === 1 );

// ── 5. save_settings: campos de texto + checkboxes explícitos '1'/'0' ───────
$GLOBALS['__opts'] = array();
Estandar_Leads_Meta_Adapter::save_settings( array(
	'meta_pixel_id'        => ' 999888777 ',
	'meta_access_token'    => 'TOK-NEW',
	'meta_test_event_code' => 'TEST42',
	'meta_enabled'         => '1',
	'meta_browser_events'  => '1',
	// server_events / debug_logging / test_mode ausentes (checkbox sin marcar)
) );
$s = Estandar_Leads_Meta_Adapter::get_settings();
check( 'save: pixel_id sanitizado (trim)', $s['pixel_id'] === '999888777' );
check( 'save: access_token persistido', $s['access_token'] === 'TOK-NEW' );
check( 'save: test_event_code persistido', $s['test_event_code'] === 'TEST42' );
check( "save: checkboxes marcados → '1'", $s['enabled'] === '1' && $s['browser_events'] === '1' );
check( "save: checkboxes ausentes → '0' explícito", $s['server_events'] === '0' && $s['debug_logging'] === '0' && $s['test_mode'] === '0' );

// ── 6. Telemetría sin PII ni token (incluye META_DEBUG_SEND) ─────────────────
configure( array( 'estandar_leads_meta_debug_logging' => '1' ) );
reset_all();
Estandar_Leads_Meta_Adapter::process_queue();
$debug = array_values( array_filter( $GLOBALS['__telemetry'], function ( $e ) {
	return ( $e['code'] ?? '' ) === 'META_DEBUG_SEND';
} ) );
check( 'debug_logging=1 → emite META_DEBUG_SEND', count( $debug ) === 1 );
$all_telemetry = json_encode( $GLOBALS['__telemetry'] );
check( 'telemetría sin email del lead', strpos( $all_telemetry, 'persona.real@example.com' ) === false );
check( 'telemetría sin teléfono del lead', strpos( $all_telemetry, '56911112222' ) === false );
check( 'telemetría sin access token', strpos( $all_telemetry, 'SECRET-TOKEN-XYZ' ) === false );
$ctx = $debug[0]['context'] ?? array();
check( 'META_DEBUG_SEND expone solo campos operativos', array_keys( $ctx ) === array( 'lead_id', 'event_id', 'event_name', 'http_code', 'duration_ms', 'temporary', 'message' ) );
check( 'META_DEBUG_SEND incluye duration_ms numérico', is_int( $ctx['duration_ms'] ?? null ) );

// ── 7. debug_logging=0 → sin META_DEBUG_SEND ─────────────────────────────────
configure();
reset_all();
Estandar_Leads_Meta_Adapter::process_queue();
$debug_off = array_filter( $GLOBALS['__telemetry'], function ( $e ) {
	return ( $e['code'] ?? '' ) === 'META_DEBUG_SEND';
} );
check( 'debug_logging=0 → sin META_DEBUG_SEND', count( $debug_off ) === 0 );

echo "\n$pass passed, $fail failed\n";
exit( $fail === 0 ? 0 : 1 );
