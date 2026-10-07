<?php
/**
 * OPERATIONAL_HOURS_V1 — unit tests.
 *
 * Run: php tests/run-operational-hours.php
 * Expected: all assertions pass, exit 0.
 */

// Minimal WP stubs for standalone execution.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../../../../' );
}

$_test_options = array();

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		global $_test_options;
		return $_test_options[ $key ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value, $autoload = null ) {
		global $_test_options;
		$_test_options[ $key ] = $value;
		return true;
	}
}

require_once __DIR__ . '/../includes/class-operational-hours.php';

$passed = 0;
$failed = 0;

function assert_test( bool $condition, string $label ): void {
	global $passed, $failed;
	if ( $condition ) {
		$passed++;
		echo "  PASS: {$label}\n";
	} else {
		$failed++;
		echo "  FAIL: {$label}\n";
	}
}

echo "=== OPERATIONAL_HOURS_V1 Tests ===\n\n";

// --- Test 1: Disabled = always open ---
echo "Test 1: Disabled = always open\n";
update_option( 'estandar_operational_hours_v1', array( 'enabled' => false ) );
$sunday_23 = new DateTime( '2026-06-21 23:00:00', new DateTimeZone( 'America/Santiago' ) );
assert_test( Estandar_Operational_Hours::is_open( $sunday_23 ) === true, 'Disabled: Sunday 23:00 = open' );
assert_test( Estandar_Operational_Hours::next_open_window( $sunday_23 ) === null, 'Disabled: next_open_window = null' );
assert_test( Estandar_Operational_Hours::deferred_minutes( $sunday_23 ) === 0, 'Disabled: deferred_minutes = 0' );

// --- Test 2: Enabled, Sunday closed ---
echo "\nTest 2: Enabled, Sunday 23:00 (closed)\n";
update_option( 'estandar_operational_hours_v1', array(
	'enabled'  => true,
	'timezone' => 'America/Santiago',
	'schedule' => array(
		'mon' => array( 'start' => '09:00', 'end' => '18:00' ),
		'tue' => array( 'start' => '09:00', 'end' => '18:00' ),
		'wed' => array( 'start' => '09:00', 'end' => '18:00' ),
		'thu' => array( 'start' => '09:00', 'end' => '18:00' ),
		'fri' => array( 'start' => '09:00', 'end' => '18:00' ),
		'sat' => null,
		'sun' => null,
	),
) );
assert_test( Estandar_Operational_Hours::is_open( $sunday_23 ) === false, 'Sunday 23:00 = closed' );
$next = Estandar_Operational_Hours::next_open_window( $sunday_23 );
assert_test( $next === '2026-06-22 09:00', 'Next open = Monday 09:00 (got: ' . ($next ?? 'null') . ')' );

// --- Test 3: Monday 10:00 = open ---
echo "\nTest 3: Monday 10:00 (open)\n";
$monday_10 = new DateTime( '2026-06-22 10:00:00', new DateTimeZone( 'America/Santiago' ) );
assert_test( Estandar_Operational_Hours::is_open( $monday_10 ) === true, 'Monday 10:00 = open' );
assert_test( Estandar_Operational_Hours::deferred_minutes( $monday_10 ) === 0, 'Monday 10:00 deferred = 0' );

// --- Test 4: Monday 08:59 = closed (before open) ---
echo "\nTest 4: Monday 08:59 (before open)\n";
$monday_0859 = new DateTime( '2026-06-22 08:59:00', new DateTimeZone( 'America/Santiago' ) );
assert_test( Estandar_Operational_Hours::is_open( $monday_0859 ) === false, 'Monday 08:59 = closed' );
$next4 = Estandar_Operational_Hours::next_open_window( $monday_0859 );
assert_test( $next4 === '2026-06-22 09:00', 'Next open = same day 09:00 (got: ' . ($next4 ?? 'null') . ')' );

// --- Test 5: Friday 18:01 → next Monday ---
echo "\nTest 5: Friday 18:01 (closed, weekend ahead)\n";
$friday_1801 = new DateTime( '2026-06-26 18:01:00', new DateTimeZone( 'America/Santiago' ) );
assert_test( Estandar_Operational_Hours::is_open( $friday_1801 ) === false, 'Friday 18:01 = closed' );
$next5 = Estandar_Operational_Hours::next_open_window( $friday_1801 );
assert_test( $next5 === '2026-06-29 09:00', 'Next open = Monday 09:00 (got: ' . ($next5 ?? 'null') . ')' );

// --- Test 6: Deferred minutes calculation ---
echo "\nTest 6: Deferred minutes (Sunday 23:00 → Monday 09:00)\n";
$deferred = Estandar_Operational_Hours::deferred_minutes( $sunday_23 );
assert_test( $deferred === 600, 'Deferred = 600 min (10h) (got: ' . $deferred . ')' );

// --- Test 7: Friday 17:55 → same day still open ---
echo "\nTest 7: Friday 17:55 (still open)\n";
$friday_1755 = new DateTime( '2026-06-26 17:55:00', new DateTimeZone( 'America/Santiago' ) );
assert_test( Estandar_Operational_Hours::is_open( $friday_1755 ) === true, 'Friday 17:55 = open' );

// --- Test 8: human_message outside hours ---
echo "\nTest 8: human_message outside hours\n";
$msg = Estandar_Operational_Hours::human_message( $sunday_23 );
assert_test( $msg['is_open'] === false, 'human_message: is_open = false' );
assert_test( strpos( $msg['message'], 'lunes' ) !== false, 'human_message mentions lunes' );
assert_test( strpos( $msg['message'], '09:00' ) !== false, 'human_message mentions 09:00' );

// --- Test 9: Saturday with hours configured ---
echo "\nTest 9: Saturday with hours (custom schedule)\n";
update_option( 'estandar_operational_hours_v1', array(
	'enabled'  => true,
	'timezone' => 'America/Santiago',
	'schedule' => array(
		'mon' => array( 'start' => '09:00', 'end' => '18:00' ),
		'tue' => array( 'start' => '09:00', 'end' => '18:00' ),
		'wed' => array( 'start' => '09:00', 'end' => '18:00' ),
		'thu' => array( 'start' => '09:00', 'end' => '18:00' ),
		'fri' => array( 'start' => '09:00', 'end' => '18:00' ),
		'sat' => array( 'start' => '10:00', 'end' => '14:00' ),
		'sun' => null,
	),
) );
$sat_11 = new DateTime( '2026-06-27 11:00:00', new DateTimeZone( 'America/Santiago' ) );
assert_test( Estandar_Operational_Hours::is_open( $sat_11 ) === true, 'Saturday 11:00 with hours = open' );
$sat_15 = new DateTime( '2026-06-27 15:00:00', new DateTimeZone( 'America/Santiago' ) );
assert_test( Estandar_Operational_Hours::is_open( $sat_15 ) === false, 'Saturday 15:00 past close = closed' );

// --- Test 10: next_open_timestamp returns UTC unix ---
echo "\nTest 10: next_open_timestamp\n";
update_option( 'estandar_operational_hours_v1', array(
	'enabled'  => true,
	'timezone' => 'America/Santiago',
	'schedule' => array(
		'mon' => array( 'start' => '09:00', 'end' => '18:00' ),
		'tue' => array( 'start' => '09:00', 'end' => '18:00' ),
		'wed' => array( 'start' => '09:00', 'end' => '18:00' ),
		'thu' => array( 'start' => '09:00', 'end' => '18:00' ),
		'fri' => array( 'start' => '09:00', 'end' => '18:00' ),
		'sat' => null,
		'sun' => null,
	),
) );
$ts = Estandar_Operational_Hours::next_open_timestamp( $sunday_23 );
assert_test( $ts !== null, 'next_open_timestamp not null' );
$expected_dt = new DateTime( '2026-06-22 09:00:00', new DateTimeZone( 'America/Santiago' ) );
assert_test( $ts === $expected_dt->getTimestamp(), 'Timestamp matches Monday 09:00 Santiago (got: ' . $ts . ' expected: ' . $expected_dt->getTimestamp() . ')' );

// --- Summary ---
echo "\n=== Results: {$passed} passed, {$failed} failed ===\n";
exit( $failed > 0 ? 1 : 0 );
