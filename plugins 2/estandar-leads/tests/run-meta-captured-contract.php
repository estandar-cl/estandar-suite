<?php
/**
 * Contract test: contrato de eventos CAPTURED/QUALIFIED (lado servidor).
 *
 * Flujo cubierto:
 *   estandar_lead_ingested (post-COMMIT) → enqueue CAPTURED (INSERT IGNORE,
 *   UNIQUE lead_id+status) → worker → Graph API con event_name 'Lead' y
 *   event_id <uuid>:CAPTURED. QUALIFIED viaja como QualifiedLead con
 *   <uuid>:QUALIFIED — nunca comparten event_id.
 *
 * Stubs $wpdb (emula UNIQUE en INSERT IGNORE) + HTTP — sin DB ni red. Run:
 *   php tests/run-meta-captured-contract.php
 */

error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ );

// ── WordPress stubs ──────────────────────────────────────────────────────────
$GLOBALS['__opts']       = array();
$GLOBALS['__http_calls'] = array();
$GLOBALS['__next']       = array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
$GLOBALS['__hooks']      = array();

class WP_Error {
	private $m;
	public function __construct( $c = '', $m = '' ) { $this->m = $m; }
	public function get_error_message() { return $this->m; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function get_option( $k, $d = false ) { return $GLOBALS['__opts'][ $k ] ?? $d; }
function add_option( $k, $v, $d = '', $a = 'yes' ) {
	if ( isset( $GLOBALS['__opts'][ $k ] ) ) { return false; }
	$GLOBALS['__opts'][ $k ] = $v;
	return true;
}
function update_option( $k, $v, $a = null ) { $GLOBALS['__opts'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['__opts'][ $k ] ); return true; }
function admin_url( $p = '' ) { return 'https://site.test/wp-admin/' . $p; }
function apply_filters( $h, $v ) { return $v; }
function wp_salt( $s = '' ) { return 'test-salt'; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function do_action() {}
function add_action( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['__hooks'][ $hook ][] = $cb; }
function wp_next_scheduled( $h ) { return time(); } // cron ya agendado → init no re-agenda
function wp_schedule_event( $t, $i, $h ) {}
function wp_remote_post( $url, $args ) {
	$GLOBALS['__http_calls'][] = array( 'url' => $url, 'args' => $args );
	return $GLOBALS['__next'];
}
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code'] ?? 0; }
function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }

// ── Fake wpdb: emula UNIQUE KEY (lead_id, export_status) en INSERT IGNORE ────
class FakeWpdb {
	public $prefix = 'wp_';
	public $queue_rows = array();   // clave "lead_id:status" → fila
	public $updates = array();
	public $leadRow = null;
	public $pendingItems = array();

	public function prepare( $q, ...$a ) { return array( 'q' => $q, 'a' => $a ); }
	public function query( $p ) {
		// enqueue() usa INSERT IGNORE con args (lead_id, status, event_time, created_at).
		if ( is_array( $p ) && strpos( $p['q'], 'INSERT IGNORE' ) !== false ) {
			$key = $p['a'][0] . ':' . $p['a'][1];
			if ( isset( $this->queue_rows[ $key ] ) ) {
				return 0; // UNIQUE absorbe el duplicado — cero filas insertadas
			}
			$this->queue_rows[ $key ] = array(
				'lead_id'       => $p['a'][0],
				'export_status' => $p['a'][1],
				'event_time'    => $p['a'][2],
			);
			return 1;
		}
		return 1;
	}
	public function get_results( $p ) { return $this->pendingItems; }
	public function get_row( $p ) { return $this->leadRow; }
	public function get_var( $p ) { return null; }
	public function update( $t, $d, $w, $f = null, $wf = null ) { $this->updates[] = array( 'data' => $d, 'where' => $w ); return 1; }
}
$GLOBALS['wpdb'] = new FakeWpdb();

$base = dirname( __DIR__ ) . '/includes/';
require_once $base . 'class-estandar-leads-attribution.php';
require_once $base . 'class-estandar-leads-qa-policy.php';
require_once $base . 'class-estandar-leads-meta-payload-builder.php';
require_once $base . 'class-estandar-leads-meta-graph-client.php';
require_once $base . 'class-estandar-leads-meta-adapter.php';

$pass = 0; $fail = 0;
function check( $label, $cond ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "PASS  $label\n"; }
	else { $fail++; echo "FAIL  $label\n"; }
}
function last_sent_event() {
	$calls = $GLOBALS['__http_calls'];
	if ( empty( $calls ) ) { return null; }
	$body = json_decode( end( $calls )['args']['body'], true );
	return $body['data'][0] ?? null;
}
function queue_item( $status ) {
	return (object) array(
		'id' => 1, 'lead_id' => 87, 'export_status' => $status,
		'event_time' => '2026-07-10 12:00:00', 'attempts' => 0, 'queue_status' => 'pending',
	);
}
$GLOBALS['wpdb']->leadRow = (object) array(
	'id' => 87, 'lead_uuid' => 'uuid-87', 'email' => 'x@y.com',
	'project_slug' => '', 'updated_at' => '2026-07-01 09:00:00', 'first_fbclid' => 'fb-paid-87',
);
$GLOBALS['__opts'] = array(
	'estandar_leads_meta_pixel_id'     => 'PIX1',
	'estandar_leads_meta_access_token' => 'TOK1',
);

// ── 1. init() se suscribe SOLO al evento post-persistencia ───────────────────
Estandar_Leads_Meta_Adapter::init();
check( 'init suscribe estandar_lead_ingested (post-COMMIT)', isset( $GLOBALS['__hooks']['estandar_lead_ingested'] ) );
check( 'init NO suscribe estandar_lead_captured (pre-persistencia)', ! isset( $GLOBALS['__hooks']['estandar_lead_captured'] ) );
check( 'init conserva estandar_lead_status_changed (pipeline comercial intacto)', isset( $GLOBALS['__hooks']['estandar_lead_status_changed'] ) );

// ── 2. Lead ingerido → job CAPTURED en la cola existente ─────────────────────
Estandar_Leads_Meta_Adapter::on_lead_ingested( 87, 501, true, array() );
check( 'on_lead_ingested encola job (lead 87, CAPTURED)', isset( $GLOBALS['wpdb']->queue_rows['87:CAPTURED'] ) );

// ── 3. Doble submit / reintento → UN solo job (UNIQUE + INSERT IGNORE) ──────
Estandar_Leads_Meta_Adapter::on_lead_ingested( 87, 502, false, array() );
Estandar_Leads_Meta_Adapter::on_lead_ingested( 87, 503, false, array() );
check( 'doble submit/reintento → sigue habiendo UN solo job CAPTURED', count( $GLOBALS['wpdb']->queue_rows ) === 1 );

$GLOBALS['wpdb']->queue_rows = array();
Estandar_Leads_Meta_Adapter::on_lead_ingested( 90, 590, true, array( 'qa' => array( 'is_test_lead' => true ) ) );
check( 'QA → captura Meta no se encola', $GLOBALS['wpdb']->queue_rows === array() );
Estandar_Leads_Meta_Adapter::on_lead_ingested( 87, 501, true, array() );

// ── 4. Worker despacha CAPTURED como Lead con <uuid>:CAPTURED ────────────────
$GLOBALS['wpdb']->pendingItems = array( queue_item( 'CAPTURED' ) );
Estandar_Leads_Meta_Adapter::process_queue();
$captured = last_sent_event();
check( "Server Lead: event_name = 'Lead'", $captured['event_name'] === 'Lead' );
check( 'Server Lead: event_id = uuid-87:CAPTURED', $captured['event_id'] === 'uuid-87:CAPTURED' );
check( 'capture_event_id() = misma derivación que el worker',
	Estandar_Leads_Meta_Adapter::capture_event_id( 'uuid-87' ) === $captured['event_id'] );

// ── 5. QUALIFIED viaja separado: QualifiedLead con <uuid>:QUALIFIED ─────────
$GLOBALS['__http_calls'] = array();
$GLOBALS['wpdb']->pendingItems = array( queue_item( 'QUALIFIED' ) );
Estandar_Leads_Meta_Adapter::process_queue();
$qualified = last_sent_event();
check( "Qualified: event_name = 'QualifiedLead' (nunca 'Lead')", $qualified['event_name'] === 'QualifiedLead' );
check( 'Qualified: event_id = uuid-87:QUALIFIED', $qualified['event_id'] === 'uuid-87:QUALIFIED' );

// ── 6. CAPTURED y QUALIFIED jamás comparten event_id ni event_name ──────────
check( 'CAPTURED y QUALIFIED no comparten event_id', $captured['event_id'] !== $qualified['event_id'] );
check( 'CAPTURED y QUALIFIED no comparten event_name (cero dedup cruzada)', $captured['event_name'] !== $qualified['event_name'] );

// ── 7. Gates: sin Server Events no se encola captura ─────────────────────────
$GLOBALS['__opts']['estandar_leads_meta_server_events'] = '0';
$GLOBALS['wpdb']->queue_rows = array();
Estandar_Leads_Meta_Adapter::on_lead_ingested( 88, 504, true, array() );
check( 'server_events=0 → captura no encola nada', $GLOBALS['wpdb']->queue_rows === array() );
$GLOBALS['__opts'] = array(); // sin configurar
Estandar_Leads_Meta_Adapter::on_lead_ingested( 89, 505, true, array() );
check( 'sin pixel/token → captura no encola nada', $GLOBALS['wpdb']->queue_rows === array() );

echo "\n$pass passed, $fail failed\n";
exit( $fail === 0 ? 0 : 1 );
