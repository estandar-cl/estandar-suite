<?php
/**
 * Focused tests — AdvertisingEvent contract + Phase 1 hardening.
 * Stubs WP + $wpdb. No DB, no HTTP. Run: php tests/run-advertising-event-contract.php
 *
 * PII policy tested: the event LEGITIMATELY carries normalized identity
 * (email_normalized/phone_normalized) for Enhanced Matching. The guarantee is
 * that PII is absent from OBSERVABILITY (persisted json, the recorded hook
 * payload — proxies for logs/telemetry/debug), not absent from the event.
 */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'OBJECT', 'OBJECT' );

$LEADS  = dirname( __DIR__, 1 ) . '/../estandar-leads/includes/';
$SIGNAL = dirname( __DIR__ ) . '/includes/';

// ── WP stubs ─────────────────────────────────────────────────────────────────
$GLOBALS['__opts'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['__opts'][ $k ] ?? $d; }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) ); }
function sanitize_title( $v ) { return strtolower( preg_replace( '/[^a-z0-9\-]/i', '-', (string) $v ) ); }
function sanitize_text_field( $v ) { return is_string( $v ) ? trim( $v ) : $v; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_generate_uuid4() { return sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x', mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0x0fff)|0x4000,mt_rand(0,0x3fff)|0x8000,mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff) ); }
function home_url() { return 'https://demo.cl'; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }

// Hook + filter registry (real dispatch).
$GLOBALS['__hooks'] = array();
$GLOBALS['__filters'] = array();
function add_action( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['__hooks'][ $h ][] = $cb; }
function do_action( $h, ...$args ) { foreach ( $GLOBALS['__hooks'][ $h ] ?? array() as $cb ) { call_user_func_array( $cb, $args ); } }
function add_filter( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['__filters'][ $h ][] = $cb; }
function apply_filters( $h, $v, ...$args ) { foreach ( $GLOBALS['__filters'][ $h ] ?? array() as $cb ) { $v = call_user_func_array( $cb, array_merge( array( $v ), $args ) ); } return $v; }

// ── Domain policy stub ───────────────────────────────────────────────────────
class Estandar_Leads_QA_Policy {
	public static $blocked = false;
	public static $is_test = false;
	public static function ads_feedback_blocked( int $lead_id, int $submission_id = 0, array $payload = array() ): bool { return self::$blocked; }
	public static function is_test_lead( int $lead_id ): bool { return self::$is_test; }
	public static function from_payload( array $p ): array { return array( 'is_test_lead' => self::$is_test ); }
}
class Estandar_Signal_Core_Schema {
	public static function events_table() { return 'wp_es_signal_events'; }
	public static function queue_table() { return 'wp_es_signal_queue'; }
	public static function clients_table() { return 'wp_es_signal_clients'; }
}

// ── Fake wpdb ────────────────────────────────────────────────────────────────
class FakeWpdb {
	public $prefix = 'wp_';
	public $lead;                 // assoc row for es_leads
	public $events = array();
	public $es_leads_reads = 0;
	public $existing = array();
	public $insert_id = 0;
	private $seq = 0;
	public function prepare( $q, ...$a ) { foreach ( $a as $v ) { $q = preg_replace( '/%[dsf]/', is_string($v)?addslashes($v):(string)$v, $q, 1 ); } return $q; }
	public function get_row( $q, $output = OBJECT ) {
		if ( strpos( $q, 'wp_es_leads' ) !== false ) {
			$this->es_leads_reads++;
			return $output === ARRAY_A ? $this->lead : (object) $this->lead;
		}
		return null;
	}
	public function get_var( $q ) {
		if ( strpos( $q, 'idempotency_key' ) !== false ) {
			foreach ( $this->existing as $k => $id ) { if ( strpos( $q, $k ) !== false ) { return $id; } }
			return null;
		}
		if ( strpos( $q, 'COUNT(*)' ) !== false ) { return 0; }
		return null;
	}
	public function insert( $table, $data ) {
		if ( strpos( $table, 'es_signal_events' ) !== false ) {
			$this->events[] = $data;
			$this->existing[ $data['idempotency_key'] ] = ++$this->seq;
			$this->insert_id = $this->seq;
		}
		return 1;
	}
	public function query( $q ) { return 1; }
}

require $LEADS  . 'class-estandar-leads-advertising-event.php';
require $SIGNAL . 'class-estandar-signal-core-repository.php';
require $SIGNAL . 'class-estandar-signal-core-advertising-listener.php';
require $SIGNAL . 'class-estandar-signal-core-hooks.php';

Estandar_Signal_Core_Advertising_Listener::init();

// Capture the observability hook payload.
$GLOBALS['__recorded'] = array();
add_action( 'estandar_signal_core_recorded', function ( $id, $name, $payload ) { $GLOBALS['__recorded'][] = $payload; }, 10, 3 );

$pass = 0; $fail = 0;
function check( $label, $cond ) { global $pass,$fail; if ( $cond ) { $pass++; echo "  ok   $label\n"; } else { $fail++; echo "  FAIL $label\n"; } }
function fresh_lead() {
	return array(
		'id' => 7, 'lead_uuid' => 'uuid-7', 'project_slug' => 'demo', 'status' => 'QUALIFIED', 'quality' => 'high',
		'email_normalized' => 'a@b.cl', 'phone_normalized' => '56911112222', 'first_session_id' => 'sess-1',
		'first_gclid' => 'GCL123', 'first_gbraid' => '', 'first_wbraid' => '', 'first_fbclid' => '',
		'first_meta_fbc' => '', 'first_meta_fbp' => '', 'first_ttclid' => '', 'first_msclkid' => '',
	);
}
function legacy_reachable() { // mirror Hooks::legacy_superseded()
	return apply_filters( 'estandar_advertising_event_emitter_available', false ) === true;
}

// ── CAPABILITY (must run BEFORE init(), which flips $ready permanently) ───────
echo "== 1. Leads emitter absent/unavailable → fallback active ==\n";
check( 'capability defaults false → legacy_superseded false', legacy_reachable() === false );
$GLOBALS['wpdb'] = new FakeWpdb(); $GLOBALS['wpdb']->lead = fresh_lead();
Estandar_Signal_Core_Hooks::on_status_changed( 7, 'NEW', 'QUALIFIED', '2026-07-13 10:00:00' );
check( 'legacy fallback persisted the fact', count( $GLOBALS['wpdb']->events ) === 1 );

echo "== 2. Class loaded but init() not run → fallback active ==\n";
check( 'class present', class_exists( 'Estandar_Leads_Advertising_Event' ) );
check( 'capability still false (no init) → fallback active', legacy_reachable() === false );

echo "== 3. capability=true → legacy inactive ==\n";
Estandar_Leads_Advertising_Event::init(); // registers capability filter LAST
check( 'capability now true', legacy_reachable() === true );
$GLOBALS['wpdb'] = new FakeWpdb(); $GLOBALS['wpdb']->lead = fresh_lead();
Estandar_Signal_Core_Hooks::on_status_changed( 7, 'NEW', 'QUALIFIED', '2026-07-13 10:00:00' );
check( 'legacy stood down (0 persisted)', count( $GLOBALS['wpdb']->events ) === 0 );

// Build a canonical event (capability now true for the rest).
$GLOBALS['wpdb'] = new FakeWpdb(); $GLOBALS['wpdb']->lead = fresh_lead();
$ev = Estandar_Leads_Advertising_Event::build( 7, 'lead_qualified', 0, 'QUALIFIED', '2026-07-13 10:00:00' );

echo "== 4. AdvertisingEvent carries normalized identity (PII by design) ==\n";
check( 'identity.email_normalized present', ( $ev['identity']['email_normalized'] ?? '' ) === 'a@b.cl' );
check( 'identity.phone_normalized present', ( $ev['identity']['phone_normalized'] ?? '' ) === '56911112222' );
check( 'no raw status/sales_stage in event', ! array_key_exists( 'status', $ev ) && ! array_key_exists( 'sales_stage', $ev ) );

echo "== 5. PII absent from observability (persisted json + recorded hook) ==\n";
$GLOBALS['wpdb'] = new FakeWpdb(); $GLOBALS['wpdb']->lead = fresh_lead();
$GLOBALS['__recorded'] = array();
add_filter( 'estandar_signal_core_exporters', function ( $exporters ) {
	$exporters[] = new class {
		public function supports_event( string $event_name ): bool { return true; }
	};
	return $exporters;
} );
Estandar_Signal_Core_Repository::record_from_event( $ev );
$row = $GLOBALS['wpdb']->events[0];
check( 'new AdvertisingEvent remains audit_only even with exporter configured', (int) $row['audit_only'] === 1 && $row['export_status'] === 'audit_only' );
check( 'identity_json has no plaintext email/phone', strpos( (string) $row['identity_json'], 'a@b.cl' ) === false && strpos( (string) $row['identity_json'], '56911112222' ) === false );
check( 'payload_json has no PII', strpos( (string) $row['payload_json'], 'a@b.cl' ) === false && strpos( (string) $row['payload_json'], '56911112222' ) === false );
$rec = wp_json_encode( $GLOBALS['__recorded'] );
check( 'recorded hook payload (telemetry proxy) has no PII', strpos( $rec, 'a@b.cl' ) === false && strpos( $rec, '56911112222' ) === false );
check( 'email persisted only as SHA-256 hash', ( $row['email_hash'] ?? '' ) === hash( 'sha256', 'a@b.cl' ) );

echo "== 6. Signal does not read es_leads.status (new flow) ==\n";
check( 'record_from_event did NOT SELECT es_leads', $GLOBALS['wpdb']->es_leads_reads === 0 );
check( 'stored status column empty', ( $row['status'] ?? 'X' ) === '' );

echo "== 7. Dedup + exactly-once ==\n";
$GLOBALS['wpdb'] = new FakeWpdb(); $GLOBALS['wpdb']->lead = fresh_lead();
$id1 = Estandar_Signal_Core_Repository::record_from_event( $ev );
$id2 = Estandar_Signal_Core_Repository::record_from_event( $ev );
check( 'second identical event deduped', count( $GLOBALS['wpdb']->events ) === 1 && $id2 === $id1 );

echo "== 8. capability=true but no emission → no error, no dupes ==\n";
$GLOBALS['wpdb'] = new FakeWpdb(); $GLOBALS['wpdb']->lead = fresh_lead();
check( 'capability true', legacy_reachable() === true );
check( 'no emission → 0 persisted', count( $GLOBALS['wpdb']->events ) === 0 );

echo "== 9. Emitter gates (non-exportable / policy-blocked) ==\n";
$GLOBALS['wpdb'] = new FakeWpdb(); $GLOBALS['wpdb']->lead = fresh_lead();
Estandar_Leads_QA_Policy::$blocked = false;
Estandar_Leads_Advertising_Event::on_status_changed( 7, 'NEW', 'PENDING', '2026-07-13 10:00:00' );
check( 'non-exportable status → 0 signals', count( $GLOBALS['wpdb']->events ) === 0 );
$GLOBALS['wpdb'] = new FakeWpdb(); $GLOBALS['wpdb']->lead = fresh_lead();
Estandar_Leads_QA_Policy::$blocked = true;
Estandar_Leads_Advertising_Event::on_status_changed( 7, 'NEW', 'QUALIFIED', '2026-07-13 10:00:00' );
check( 'policy-blocked → 0 signals', count( $GLOBALS['wpdb']->events ) === 0 );
Estandar_Leads_QA_Policy::$blocked = false;

echo "== 10. Real persisted row — exhaustive PII scan ==\n";
$GLOBALS['wpdb'] = new FakeWpdb();
$lead = fresh_lead();
$lead['email_normalized'] = 'secret.person@example.cl';
$lead['phone_normalized'] = '56999887766';
$GLOBALS['wpdb']->lead = $lead;
$GLOBALS['__recorded'] = array();
// Build via the emitter (as production does), then persist via the repository.
$ev2 = Estandar_Leads_Advertising_Event::build( 7, 'lead_qualified', 0, 'QUALIFIED', '2026-07-13 10:00:00' );
Estandar_Signal_Core_Repository::record_from_event( $ev2 );
$prow = $GLOBALS['wpdb']->events[0]; // the exact column=>value map passed to $wpdb->insert()

$EMAIL = 'secret.person@example.cl';
$PHONE = '56999887766';
// Scan EVERY persisted column value for plaintext PII.
$leaks = array();
foreach ( $prow as $col => $val ) {
	$s = is_scalar( $val ) ? (string) $val : wp_json_encode( $val );
	if ( strpos( $s, $EMAIL ) !== false || strpos( $s, $PHONE ) !== false ) {
		$leaks[] = $col;
	}
}
check( 'no persisted column contains plaintext email/phone', $leaks === array() );
check( 'email_hash = SHA-256(email)', ( $prow['email_hash'] ?? '' ) === hash( 'sha256', $EMAIL ) );
check( 'phone_hash = SHA-256(phone)', ( $prow['phone_hash'] ?? '' ) === hash( 'sha256', $PHONE ) );
check( 'identity_json present but no plaintext PII', isset( $prow['identity_json'] ) && strpos( (string) $prow['identity_json'], $EMAIL ) === false && strpos( (string) $prow['identity_json'], $PHONE ) === false );
check( 'payload_json has no plaintext PII', strpos( (string) ( $prow['payload_json'] ?? '' ), $EMAIL ) === false && strpos( (string) ( $prow['payload_json'] ?? '' ), $PHONE ) === false );
// Observability channel: the recorded hook payload (telemetry/debug proxy).
$rec2 = wp_json_encode( $GLOBALS['__recorded'] );
check( 'recorded hook payload has no plaintext PII', strpos( $rec2, $EMAIL ) === false && strpos( $rec2, $PHONE ) === false );
// The hashes are the ONLY carriers of identity in persistence.
check( 'hashes are present where identity is expected', ( $prow['email_hash'] ?? '' ) !== '' && ( $prow['phone_hash'] ?? '' ) !== '' );

echo "\n$pass passed, $fail failed\n";
exit( $fail === 0 ? 0 : 1 );
