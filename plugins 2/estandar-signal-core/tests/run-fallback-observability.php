<?php
/**
 * Focused tests — Phase 4: legacy fallback observability + compatibility matrix.
 * Pure observation; no functional change. Stubs WP. Run: php tests/run-fallback-observability.php
 */
error_reporting( E_ALL );
define( 'ABSPATH', __DIR__ . '/' );

function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) ); }

$GLOBALS['__filters'] = array();
$GLOBALS['__hooks']   = array();
function add_filter( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['__filters'][ $h ][] = $cb; }
function apply_filters( $h, $v, ...$a ) { foreach ( $GLOBALS['__filters'][ $h ] ?? array() as $cb ) { $v = call_user_func_array( $cb, array_merge( array( $v ), $a ) ); } return $v; }
function add_action( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['__hooks'][ $h ][] = $cb; }
function do_action( $h, ...$a ) { foreach ( $GLOBALS['__hooks'][ $h ] ?? array() as $cb ) { call_user_func_array( $cb, $a ); } }

class Estandar_Leads_QA_Policy {
	public static function is_test_lead( int $l ): bool { return false; }
	public static function from_payload( array $p ): array { return array( 'is_test_lead' => false ); }
}
// Fake ledger repository — counts legacy persistences without touching a DB.
class Estandar_Signal_Core_Repository {
	public static $records = 0;
	public static function record_from_lead( int $lead_id, string $event_name, array $args = array() ): int { self::$records++; return self::$records; }
}

require dirname( __DIR__ ) . '/includes/class-estandar-signal-core-hooks.php';

// Capture the canonical telemetry channel.
$GLOBALS['__tel'] = array();
add_action( 'estandar_telemetry_event', function ( $e ) { $GLOBALS['__tel'][] = $e; } );

$pass = 0; $fail = 0;
function check( $l, $c ) { global $pass,$fail; if ( $c ) { $pass++; echo "  ok   $l\n"; } else { $fail++; echo "  FAIL $l\n"; } }

function reset_scenario() {
	$GLOBALS['__filters'] = array();
	// keep the telemetry listener registered; only clear captured events + capability
	$GLOBALS['__tel'] = array();
	Estandar_Signal_Core_Hooks::reset_observability();
	Estandar_Signal_Core_Repository::$records = 0;
}
function set_capability_true() { add_filter( 'estandar_advertising_event_emitter_available', function () { return true; } ); }
function last_tel() { $t = $GLOBALS['__tel']; return end( $t ) ?: array(); }

// Reinstate the telemetry capture after reset_scenario clears filters (actions kept).
// (actions live in __hooks, untouched by reset_scenario)

echo "== 1. Leads ausente (no capability) → fallback ACTIVE ==\n";
reset_scenario();
Estandar_Signal_Core_Hooks::on_lead_ingested( 7, 10, true, array() );
$t = last_tel();
check( 'legacy persisted (records=1)', Estandar_Signal_Core_Repository::$records === 1 );
check( 'telemetry code = FALLBACK_ACTIVE', ( $t['code'] ?? '' ) === 'SIGNAL_LEGACY_FALLBACK_ACTIVE' );
check( 'context.capability_available=false', ( $t['context']['capability_available'] ?? null ) === false );
check( 'context.legacy_active=true', ( $t['context']['legacy_active'] ?? null ) === true );
check( 'context.reason=emitter_capability_absent', ( $t['context']['reason'] ?? '' ) === 'emitter_capability_absent' );
check( 'context.new_listener_registered=false (Signal listener not defined yet)', ( $t['context']['new_listener_registered'] ?? null ) === false );

echo "== 2. Leads antiguo (clase sin init → capability ausente) → fallback ACTIVE ==\n";
reset_scenario();
Estandar_Signal_Core_Hooks::on_status_changed( 7, 'NEW', 'QUALIFIED', '2026-07-13 10:00:00' );
$t = last_tel();
check( 'legacy persisted', Estandar_Signal_Core_Repository::$records === 1 );
check( 'code = FALLBACK_ACTIVE', ( $t['code'] ?? '' ) === 'SIGNAL_LEGACY_FALLBACK_ACTIVE' );

echo "== 3. Observability fires once per request ==\n";
reset_scenario();
Estandar_Signal_Core_Hooks::on_lead_ingested( 7, 10, true, array() );
Estandar_Signal_Core_Hooks::on_status_changed( 7, 'NEW', 'QUALIFIED', '2026-07-13 10:00:00' );
check( 'single telemetry event per request', count( $GLOBALS['__tel'] ) === 1 );

// Signal NUEVO: the new listener class now exists (conditional → not hoisted).
if ( true ) { class Estandar_Signal_Core_Advertising_Listener {} }

echo "== 4. Leads nuevo (capability=true) → fallback SUPERSEDED ==\n";
reset_scenario();
set_capability_true();
Estandar_Signal_Core_Hooks::on_lead_ingested( 7, 10, true, array() );
$t = last_tel();
check( 'legacy stood down (records=0)', Estandar_Signal_Core_Repository::$records === 0 );
check( 'telemetry code = FALLBACK_SUPERSEDED', ( $t['code'] ?? '' ) === 'SIGNAL_LEGACY_FALLBACK_SUPERSEDED' );
check( 'context.capability_available=true', ( $t['context']['capability_available'] ?? null ) === true );
check( 'context.legacy_active=false', ( $t['context']['legacy_active'] ?? null ) === false );
check( 'context.reason=emitter_capability_true', ( $t['context']['reason'] ?? '' ) === 'emitter_capability_true' );
check( 'context.new_listener_registered=true (Signal nuevo)', ( $t['context']['new_listener_registered'] ?? null ) === true );

echo "== 5. capability=true on status_changed also stands down ==\n";
reset_scenario();
set_capability_true();
Estandar_Signal_Core_Hooks::on_status_changed( 7, 'NEW', 'CONVERTED', '2026-07-13 10:00:00' );
check( 'no legacy persistence', Estandar_Signal_Core_Repository::$records === 0 );
check( 'code = SUPERSEDED', ( last_tel()['code'] ?? '' ) === 'SIGNAL_LEGACY_FALLBACK_SUPERSEDED' );

echo "== 6. Functional behavior unchanged (capability=false still records) ==\n";
reset_scenario();
Estandar_Signal_Core_Hooks::on_status_changed( 7, 'NEW', 'CONVERTED', '2026-07-13 10:00:00' );
check( 'sale_closed persisted via legacy', Estandar_Signal_Core_Repository::$records === 1 );

echo "\n$pass passed, $fail failed\n";
exit( $fail === 0 ? 0 : 1 );
