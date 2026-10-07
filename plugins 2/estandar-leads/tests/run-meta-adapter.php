<?php
/**
 * Worker-level contract test for Estandar_Leads_Meta_Adapter (P1–P5).
 * Stubs $wpdb + the WordPress HTTP API — no real DB, no real network.
 * Run: php tests/run-meta-adapter.php
 */

error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ );

// ── Capture PHP notices/warnings (for dormant-deploy assertion) ──────────────
$GLOBALS['__notices'] = array();
set_error_handler( function ( $no, $str ) {
	$GLOBALS['__notices'][] = $str;
	return true;
} );

// ── WordPress stubs ──────────────────────────────────────────────────────────
$GLOBALS['__opts']       = array();
$GLOBALS['__http_calls'] = array();
$GLOBALS['__next']       = array( 'response' => array( 'code' => 200 ), 'body' => '{}' );

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
function add_action() {}
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
	public $get_results_called = false;
	public $pendingItems = array();
	public $leadRow = null;

	public function prepare( $q, ...$a ) { return array( 'q' => $q, 'a' => $a ); }
	public function query( $p ) { $this->queries[] = $p; return 1; }
	public function get_results( $p ) { $this->get_results_called = true; return $this->pendingItems; }
	public function get_row( $p ) { return $this->leadRow; }
	public function update( $t, $data, $where, $f = null, $wf = null ) {
		$this->updates[] = array( 'data' => $data, 'where' => $where );
		return 1;
	}
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
function configure() {
	$GLOBALS['__opts'] = array(
		'estandar_leads_meta_pixel_id'     => 'PIX1',
		'estandar_leads_meta_access_token' => 'TOK1',
	);
}
function leadRow() {
	return (object) array(
		'id'           => 87,
		'lead_uuid'    => 'uuid-87',
		'email'        => 'x@y.com',
		'project_slug' => '',
		'first_fbclid' => 'fb-paid-87',
		'updated_at'   => '2026-06-25 09:00:00', // deliberately != queue event_time
	);
}
function item( $status, $event_time = '2026-06-20 12:00:00', $attempts = 0 ) {
	return (object) array(
		'id'            => 1,
		'lead_id'       => 87,
		'export_status' => $status,
		'event_time'    => $event_time,
		'attempts'      => $attempts,
		'queue_status'  => 'pending',
	);
}
function last_body() {
	$c = end( $GLOBALS['__http_calls'] );
	return json_decode( $c['args']['body'], true );
}
function any_update_processing( $wpdb ) {
	foreach ( $wpdb->updates as $u ) {
		if ( ( $u['data']['queue_status'] ?? '' ) === 'processing' ) { return true; }
	}
	return false;
}

$wpdb = $GLOBALS['wpdb'];

// ── P5 / P4: DORMANT (no pixel_id / access_token) ─────────────────────────────
$GLOBALS['__opts'] = array();
$wpdb->queries = array(); $wpdb->updates = array(); $wpdb->get_results_called = false;
$GLOBALS['__http_calls'] = array(); $GLOBALS['__notices'] = array();

Estandar_Leads_Meta_Adapter::on_status_changed( 87, 'NEW', 'RESERVED', '2026-06-20 12:00:00' );
check( 'P5 dormant: no enqueue (no INSERT)', count( $wpdb->queries ) === 0 );

Estandar_Leads_Meta_Adapter::process_queue();
check( 'P4 dormant: worker exits before SELECT', $wpdb->get_results_called === false );
check( 'P5 dormant: no HTTP request', count( $GLOBALS['__http_calls'] ) === 0 );
check( 'P5 dormant: no PHP notices/warnings', count( $GLOBALS['__notices'] ) === 0 );

// ── P4 enabled: enqueue persists canonical event_time ─────────────────────────
configure();
$wpdb->leadRow = leadRow();
$wpdb->queries = array();
Estandar_Leads_Meta_Adapter::on_status_changed( 87, 'NEW', 'RESERVED', '2026-06-20 12:00:00' );
check( 'P4 enabled: enqueue writes one INSERT', count( $wpdb->queries ) === 1 );
check( 'enqueue uses INSERT IGNORE into es_meta_queue',
	strpos( $wpdb->queries[0]['q'], 'INSERT IGNORE' ) !== false
	&& strpos( $wpdb->queries[0]['q'], 'es_meta_queue' ) !== false );
check( 'enqueue persists queue event_time', $wpdb->queries[0]['a'][2] === '2026-06-20 12:00:00' );

// ── Worker full chain: P1 + P2 + P3 ───────────────────────────────────────────
configure();
$wpdb->leadRow = leadRow();
$wpdb->pendingItems = array( item( 'RESERVED' ) );
$wpdb->updates = array();
$GLOBALS['__http_calls'] = array();
$GLOBALS['__next'] = array( 'response' => array( 'code' => 200 ), 'body' => '{}' );

Estandar_Leads_Meta_Adapter::process_queue();

// P1: first update increments attempts WITHOUT a 'processing' transition.
check( 'P1: first update sets attempts, no queue_status',
	isset( $wpdb->updates[0]['data']['attempts'] )
	&& $wpdb->updates[0]['data']['attempts'] === 1
	&& ! array_key_exists( 'queue_status', $wpdb->updates[0]['data'] ) );
check( 'P1: never writes queue_status=processing', any_update_processing( $wpdb ) === false );
check( 'P1: terminal transition = completed (success)',
	( end( $wpdb->updates )['data']['queue_status'] ?? '' ) === 'completed' );

// P2: payload event_time comes from the queue (2026-06-20), not lead.updated_at (2026-06-25).
$body = last_body();
check( 'P2: event_time from queue, not live lead',
	$body['data'][0]['event_time'] === strtotime( '2026-06-20 12:00:00 UTC' )
	&& $body['data'][0]['event_time'] !== strtotime( '2026-06-25 09:00:00 UTC' ) );

// P3: deterministic event_id = lead_uuid:STATUS.
check( 'P3: event_id = uuid-87:RESERVED', ( $body['data'][0]['event_id'] ?? '' ) === 'uuid-87:RESERVED' );
check( 'no duplicated sends (1 HTTP per item)', count( $GLOBALS['__http_calls'] ) === 1 );

// P3: same lead + same status → same id; different status → different id.
function run_and_eventid( $status ) {
	$wpdb = $GLOBALS['wpdb'];
	$wpdb->leadRow = leadRow();
	$wpdb->pendingItems = array( item( $status ) );
	$wpdb->updates = array();
	$GLOBALS['__http_calls'] = array();
	$GLOBALS['__next'] = array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
	Estandar_Leads_Meta_Adapter::process_queue();
	return last_body()['data'][0]['event_id'];
}
$r1 = run_and_eventid( 'RESERVED' );
$r2 = run_and_eventid( 'RESERVED' );
$r3 = run_and_eventid( 'VISITED' );
check( 'P3: same lead+status → same event_id', $r1 === $r2 );
check( 'P3: different status → different event_id', $r1 !== $r3 );

// ── No orphan on transient failure: row goes to retry (re-selectable) ─────────
configure();
$wpdb->leadRow = leadRow();
$wpdb->pendingItems = array( item( 'RESERVED', '2026-06-20 12:00:00', 0 ) );
$wpdb->updates = array();
$GLOBALS['__http_calls'] = array();
$GLOBALS['__next'] = array( 'response' => array( 'code' => 500 ), 'body' => '{"error":{"message":"server"}}' );

Estandar_Leads_Meta_Adapter::process_queue();
check( 'No orphan: transient failure → retry (re-selectable)',
	( end( $wpdb->updates )['data']['queue_status'] ?? '' ) === 'retry' );
check( 'No orphan: no processing state on failure path', any_update_processing( $wpdb ) === false );

echo "\n$pass passed, $fail failed\n";
exit( $fail === 0 ? 0 : 1 );
