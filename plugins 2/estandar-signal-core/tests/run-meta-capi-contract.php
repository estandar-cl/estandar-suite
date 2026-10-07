<?php
/**
 * Focused tests — Meta CAPI exporter consumes ONLY the persisted AdvertisingEvent
 * technical contract (Phase 3). Stubs WP + $wpdb + HTTP. No real network.
 * Run: php tests/run-meta-capi-contract.php
 */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'OBJECT', 'OBJECT' );

$LEADS  = dirname( __DIR__, 1 ) . '/../estandar-leads/includes/';
$SIGNAL = dirname( __DIR__ ) . '/includes/';

// ── WP stubs ─────────────────────────────────────────────────────────────────
function get_option( $k, $d = false ) { return $d; }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) ); }
function sanitize_title( $v ) { return strtolower( preg_replace( '/[^a-z0-9\-]/i', '-', (string) $v ) ); }
function sanitize_text_field( $v ) { return is_string( $v ) ? trim( $v ) : $v; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_generate_uuid4() { return 'uuid-' . mt_rand( 1000, 9999 ); }
function apply_filters( $t, $v ) { return $v; }
function add_filter( $t, $cb, $p = 10, $a = 1 ) {}
function do_action( $t, ...$a ) {}
function home_url( $p = '' ) { return 'https://demo.cl' . $p; }
class WP_Error {}
function is_wp_error( $r ) { return $r instanceof WP_Error; }
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? ( $r['code'] ?? 0 ) : 0; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? ( $r['body'] ?? '' ) : ''; }

$GLOBALS['__http'] = array( 'body' => null, 'resp' => array( 'code' => 200, 'body' => '{}' ), 'wp_error' => false );
function wp_remote_post( $url, $args = array() ) {
	$GLOBALS['__http']['body'] = $args['body'] ?? null;
	if ( ! empty( $GLOBALS['__http']['wp_error'] ) ) { return new WP_Error(); }
	return $GLOBALS['__http']['resp'];
}

class Estandar_Leads_QA_Policy {
	public static function ads_feedback_blocked( int $l, int $s = 0, array $p = array() ): bool { return false; }
	public static function is_test_lead( int $l ): bool { return false; }
	public static function from_payload( array $p ): array { return array( 'is_test_lead' => false ); }
}
class Estandar_Signal_Core_Schema {
	public static function events_table() { return 'wp_es_signal_events'; }
	public static function queue_table() { return 'wp_es_signal_queue'; }
	public static function clients_table() { return 'wp_es_signal_clients'; }
}

class PersistWpdb {
	public $prefix = 'wp_';
	public $lead;
	public $events = array();
	public $insert_id = 1;
	public function prepare( $q, ...$a ) { foreach ( $a as $v ) { $q = preg_replace( '/%[dsf]/', is_string($v)?addslashes($v):(string)$v, $q, 1 ); } return $q; }
	public function get_row( $q, $o = OBJECT ) { return strpos( $q, 'wp_es_leads' ) !== false ? (object) $this->lead : null; }
	public function get_var( $q ) { return null; }
	public function insert( $t, $d ) { if ( strpos( $t, 'es_signal_events' ) !== false ) { $this->events[] = $d; } return 1; }
	public function query( $q ) { return 1; }
}
class SpyWpdb {
	public $prefix = 'wp_';
	public $calls = 0;
	public function __call( $n, $a ) { $this->calls++; return null; }
}

require $LEADS  . 'class-estandar-leads-advertising-event.php';
require $SIGNAL . 'class-estandar-signal-core-repository.php';
require $SIGNAL . 'interface-estandar-signal-core-exporter.php';
require $SIGNAL . 'class-estandar-signal-core-meta-capi.php';

$pass = 0; $fail = 0;
function check( $l, $c ) { global $pass,$fail; if ( $c ) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } }
function lead_row() {
	return array(
		'id' => 7, 'lead_uuid' => 'uuid-7', 'project_slug' => 'demo', 'status' => 'CONVERTED', 'quality' => 'high',
		'email_normalized' => 'a@b.cl', 'phone_normalized' => '56911112222', 'first_session_id' => 'sess-1',
		'first_gclid' => '', 'first_gbraid' => '', 'first_wbraid' => '', 'first_fbclid' => 'FBX',
		'first_meta_fbc' => 'fb.c.1', 'first_meta_fbp' => 'fb.p.1', 'first_ttclid' => '', 'first_msclkid' => '',
	);
}
$meta = new Estandar_Signal_Core_Meta_Capi( 'PIXEL', 'TOKEN', '' );

// Persist a real AdvertisingEvent (lead_created → Meta 'Lead') to get the stored row.
$GLOBALS['wpdb'] = new PersistWpdb(); $GLOBALS['wpdb']->lead = lead_row();
$ev = Estandar_Leads_Advertising_Event::build( 7, 'lead_created', 0, '', '2026-07-13 10:00:00' );
Estandar_Signal_Core_Repository::record_from_event( $ev );
$row = $GLOBALS['wpdb']->events[0];

function sent_event() { $d = json_decode( (string) $GLOBALS['__http']['body'], true ); return $d['data'][0] ?? array(); }

echo "== 1. Payload built only from persisted row ==\n";
$GLOBALS['wpdb'] = new SpyWpdb();
$ok = $meta->export( $row );
$e = sent_event();
check( 'export succeeded', $ok === true );
check( 'event_name mapped Lead', ( $e['event_name'] ?? '' ) === 'Lead' );
check( 'action_source website', ( $e['action_source'] ?? '' ) === 'website' );
check( 'custom_data.project_slug from row', ( $e['custom_data']['project_slug'] ?? '' ) === 'demo' );
check( 'fbc/fbp from persisted row', ( $e['user_data']['fbc'] ?? '' ) === 'fb.c.1' && ( $e['user_data']['fbp'] ?? '' ) === 'fb.p.1' );

echo "== 2. Exporter never reads es_leads / es_lead_submissions / any DB ==\n";
check( 'zero \$wpdb calls during export', $GLOBALS['wpdb']->calls === 0 );

echo "== 3. No QA / status dependency ==\n";
$noStatus = $row; unset( $noStatus['status'] );
$GLOBALS['wpdb'] = new SpyWpdb();
check( 'export works with no status field', $meta->export( $noStatus ) === true );

echo "== 4. event_name used directly (mapping) ==\n";
$GLOBALS['wpdb'] = new SpyWpdb();
$map = array( 'lead_created' => 'Lead', 'meeting_booked' => 'Schedule', 'sale_closed' => 'Purchase' );
foreach ( $map as $name => $meta_name ) {
	$meta->export( array( 'event_name' => $name, 'event_time' => '2026-07-13 10:00:00', 'signal_uuid' => 'u-' . $name, 'identity_json' => '{}' ) );
	check( "$name → $meta_name", ( sent_event()['event_name'] ?? '' ) === $meta_name );
}
check( 'unmapped event → skip true, no send', $meta->export( array( 'event_name' => 'lead_qualified' ) ) === true );

echo "== 5. event_id preserved (Browser/Server dedup) ==\n";
$GLOBALS['wpdb'] = new SpyWpdb();
$meta->export( $row );
check( 'event_id == signal_uuid', ( sent_event()['event_id'] ?? '' ) === $row['signal_uuid'] );

echo "== 6. Persisted hashes reused, not recomputed ==\n";
check( 'row.email_hash = SHA-256(email)', ( $row['email_hash'] ?? '' ) === hash( 'sha256', 'a@b.cl' ) );
$e = sent_event();
check( 'user_data.em == persisted email_hash', ( $e['user_data']['em'][0] ?? '' ) === $row['email_hash'] );
check( 'user_data.ph == persisted phone_hash', ( $e['user_data']['ph'][0] ?? '' ) === $row['phone_hash'] );
check( 'external_id = SHA-256(persisted lead_uuid)', ( $e['user_data']['external_id'][0] ?? '' ) === hash( 'sha256', 'uuid-7' ) );

echo "== 7. Dedup key stable ==\n";
check( 'event_id equals signal_uuid (stable)', ( sent_event()['event_id'] ?? '' ) === $row['signal_uuid'] );

echo "== 8. Retry / error semantics intact ==\n";
$GLOBALS['wpdb'] = new SpyWpdb();
$GLOBALS['__http']['wp_error'] = true;
check( 'network error → false (retry)', $meta->export( $row ) === false );
$GLOBALS['__http']['wp_error'] = false;
$GLOBALS['__http']['resp'] = array( 'code' => 500, 'body' => '{}' );
check( '5xx → false (retry)', $meta->export( $row ) === false );
$GLOBALS['__http']['resp'] = array( 'code' => 400, 'body' => '{"error":{"message":"bad"}}' );
$threw = false; try { $meta->export( $row ); } catch ( \Exception $ex ) { $threw = true; }
check( '4xx → throws permanent Exception', $threw === true );
$GLOBALS['__http']['resp'] = array( 'code' => 200, 'body' => '{}' );
check( '200 → true', $meta->export( $row ) === true );

echo "== 9. Batching contract: one event per export call ==\n";
$GLOBALS['wpdb'] = new SpyWpdb();
$meta->export( $row );
$d = json_decode( (string) $GLOBALS['__http']['body'], true );
check( 'exactly one event in data[]', is_array( $d['data'] ?? null ) && count( $d['data'] ) === 1 );

echo "\n$pass passed, $fail failed\n";
exit( $fail === 0 ? 0 : 1 );
