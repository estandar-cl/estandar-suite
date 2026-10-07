<?php
/**
 * Focused tests — Google OCI exporter consumes ONLY the persisted AdvertisingEvent
 * technical contract (Phase 2). Stubs WP + $wpdb + HTTP. No real network.
 * Run: php tests/run-google-oci-contract.php
 */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'OBJECT', 'OBJECT' );
define( 'MINUTE_IN_SECONDS', 60 );

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
function get_transient( $k ) { return 'cached-token'; }   // skip token refresh HTTP
function set_transient( $k, $v, $t ) { return true; }
function delete_transient( $k ) { return true; }
class WP_Error {}
function is_wp_error( $r ) { return $r instanceof WP_Error; }
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? ( $r['code'] ?? 0 ) : 0; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? ( $r['body'] ?? '' ) : ''; }

// HTTP transport stub — captures the Ads request body, returns a controllable response.
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

// Fake wpdb for the PERSIST phase (captures the row Signal writes).
class PersistWpdb {
	public $prefix = 'wp_';
	public $lead;
	public $events = array();
	private $seq = 0;
	public function prepare( $q, ...$a ) { foreach ( $a as $v ) { $q = preg_replace( '/%[dsf]/', is_string($v)?addslashes($v):(string)$v, $q, 1 ); } return $q; }
	public function get_row( $q, $o = OBJECT ) { return strpos( $q, 'wp_es_leads' ) !== false ? (object) $this->lead : null; }
	public function get_var( $q ) { return null; }
	public function insert( $t, $d ) { if ( strpos( $t, 'es_signal_events' ) !== false ) { $this->events[] = $d; } return 1; }
	public function query( $q ) { return 1; }
	public $insert_id = 1;
}

// Spy wpdb for the EXPORT phase — any DB touch is a failure (exporter must not read es_leads).
class SpyWpdb {
	public $prefix = 'wp_';
	public $calls = 0;
	public function __call( $n, $a ) { $this->calls++; return null; }
}

require $LEADS  . 'class-estandar-leads-advertising-event.php';
require $SIGNAL . 'class-estandar-signal-core-repository.php';
require $SIGNAL . 'interface-estandar-signal-core-exporter.php';
require $SIGNAL . 'class-estandar-signal-core-google-oci.php';

$pass = 0; $fail = 0;
function check( $l, $c ) { global $pass,$fail; if ( $c ) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } }
function lead_row() {
	return array(
		'id' => 7, 'lead_uuid' => 'uuid-7', 'project_slug' => 'demo', 'status' => 'QUALIFIED', 'quality' => 'high',
		'email_normalized' => 'a@b.cl', 'phone_normalized' => '56911112222', 'first_session_id' => 'sess-1',
		'first_gclid' => 'GCL123', 'first_gbraid' => '', 'first_wbraid' => '', 'first_fbclid' => '',
		'first_meta_fbc' => '', 'first_meta_fbp' => '', 'first_ttclid' => '', 'first_msclkid' => '',
	);
}
$MAP = array(
	'lead_created'   => 'customers/123/conversionActions/1',
	'lead_contacted' => 'customers/123/conversionActions/2',
	'lead_qualified' => 'customers/123/conversionActions/3',
	'meeting_booked' => 'customers/123/conversionActions/4',
	'sale_closed'    => 'customers/123/conversionActions/5',
	'lead_rejected'  => 'customers/123/conversionActions/6',
);
$google = new Estandar_Signal_Core_Google_OCI( 'dev', 'client', 'secret', 'refresh', '123', $MAP );

// ── Persist a real AdvertisingEvent to obtain the exact stored row ────────────
$GLOBALS['wpdb'] = new PersistWpdb(); $GLOBALS['wpdb']->lead = lead_row();
$ev  = Estandar_Leads_Advertising_Event::build( 7, 'lead_qualified', 0, 'QUALIFIED', '2026-07-13 10:00:00' );
Estandar_Signal_Core_Repository::record_from_event( $ev );
$row = $GLOBALS['wpdb']->events[0];   // the persisted es_signal_events row

echo "== 1. Payload built from persisted AdvertisingEvent ==\n";
$GLOBALS['wpdb'] = new SpyWpdb();
$GLOBALS['__http']['resp'] = array( 'code' => 200, 'body' => '{}' );
$ok = $google->export( $row );
$sent = json_decode( (string) $GLOBALS['__http']['body'], true );
$conv = $sent['conversions'][0] ?? array();
check( 'export succeeded', $ok === true );
check( 'conversionAction from mapping', ( $conv['conversionAction'] ?? '' ) === 'customers/123/conversionActions/3' );
check( 'gclid from persisted row', ( $conv['gclid'] ?? '' ) === 'GCL123' );
check( 'conversionDateTime from event_time', ( $conv['conversionDateTime'] ?? '' ) === '2026-07-13 10:00:00+00:00' );

echo "== 2. Exporter never reads es_leads / any DB ==\n";
check( 'zero \$wpdb calls during export', $GLOBALS['wpdb']->calls === 0 );

echo "== 3. No dependency on commercial status ==\n";
$noStatus = $row; unset( $noStatus['status'] );
$GLOBALS['wpdb'] = new SpyWpdb();
check( 'export works with no status field', $google->export( $noStatus ) === true );

echo "== 4. All event_name → Conversion Action mappings ==\n";
foreach ( $MAP as $name => $action ) {
	$GLOBALS['wpdb'] = new SpyWpdb();
	$google->export( array( 'event_name' => $name, 'gclid' => 'g', 'event_time' => '2026-07-13 10:00:00', 'signal_uuid' => 'u-' . $name ) );
	$c = json_decode( (string) $GLOBALS['__http']['body'], true )['conversions'][0] ?? array();
	check( "$name → $action", ( $c['conversionAction'] ?? '' ) === $action );
}

echo "== 5. Persisted hashes reused (not recomputed) ==\n";
check( 'row.email_hash = SHA-256(email)', ( $row['email_hash'] ?? '' ) === hash( 'sha256', 'a@b.cl' ) );
$GLOBALS['wpdb'] = new SpyWpdb();
$google->export( $row );
$conv = json_decode( (string) $GLOBALS['__http']['body'], true )['conversions'][0] ?? array();
$uids = $conv['userIdentifiers'] ?? array();
check( 'hashedEmail == persisted email_hash', ( $uids[0]['hashedEmail'] ?? '' ) === $row['email_hash'] );
check( 'hashedPhoneNumber == persisted phone_hash', ( $uids[1]['hashedPhoneNumber'] ?? '' ) === $row['phone_hash'] );

echo "== 6. Dedup key = signal_uuid (orderId) ==\n";
check( 'orderId == signal_uuid', ( $conv['orderId'] ?? '' ) === $row['signal_uuid'] );

echo "== 7. Retry semantics intact ==\n";
$GLOBALS['wpdb'] = new SpyWpdb();
$GLOBALS['__http']['wp_error'] = true;
check( 'network error → false (retry)', $google->export( $row ) === false );
$GLOBALS['__http']['wp_error'] = false;
$GLOBALS['__http']['resp'] = array( 'code' => 500, 'body' => '{}' );
check( '5xx → false (retry)', $google->export( $row ) === false );
$GLOBALS['__http']['resp'] = array( 'code' => 200, 'body' => '{}' );
check( '200 → true', $google->export( $row ) === true );

echo "== 8. Batching contract: one conversion per export call ==\n";
$GLOBALS['wpdb'] = new SpyWpdb();
$google->export( $row );
$sent = json_decode( (string) $GLOBALS['__http']['body'], true );
check( 'exactly one conversion in request', is_array( $sent['conversions'] ?? null ) && count( $sent['conversions'] ) === 1 );
check( 'partialFailure preserved', ( $sent['partialFailure'] ?? null ) === true );

echo "\n$pass passed, $fail failed\n";
exit( $fail === 0 ? 0 : 1 );
