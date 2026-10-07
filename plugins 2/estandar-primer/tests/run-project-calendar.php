<?php
/**
 * Standalone unit test for PROJECT_CALENDAR_V1 business-time math.
 * Run: php tests/run-project-calendar.php   (no WP runtime required)
 *
 * Acceptance: Fri 17:55 -> Mon 09:05 must read ~10 business minutes,
 * NOT ~63 calendar hours.
 */

define( 'ABSPATH', __DIR__ );

// Minimal WP stubs (business_elapsed/normalize_calendar use only native PHP;
// these cover the symbols referenced elsewhere in the class file).
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) { return $default; }
}

require __DIR__ . '/../includes/class-project-calendar.php';

$UTC = array( 'timezone' => 'UTC', 'business_days' => array( 'mon','tue','wed','thu','fri' ), 'start_time' => '09:00', 'end_time' => '18:00' );
$SCL = array_merge( $UTC, array( 'timezone' => 'America/Santiago' ) );

$cases = array(
	// label, start_utc, end_utc, calendar, expected_minutes
	array( 'A acceptance Fri17:55->Mon09:05 (UTC cal)', '2026-06-19 17:55:00', '2026-06-22 09:05:00', $UTC, 10 ),
	array( 'B same-day 10:00->11:30',                   '2026-06-19 10:00:00', '2026-06-19 11:30:00', $UTC, 90 ),
	array( 'C before open 08:00->10:00 (clamp)',        '2026-06-19 08:00:00', '2026-06-19 10:00:00', $UTC, 60 ),
	array( 'D after close 17:00->20:00 (clamp)',        '2026-06-19 17:00:00', '2026-06-19 20:00:00', $UTC, 60 ),
	array( 'E weekend Sat10->Sat12',                    '2026-06-20 10:00:00', '2026-06-20 12:00:00', $UTC, 0 ),
	array( 'F full week Mon09->Fri18',                  '2026-06-15 09:00:00', '2026-06-19 18:00:00', $UTC, 2700 ),
	array( 'G end<=start',                              '2026-06-19 12:00:00', '2026-06-19 11:00:00', $UTC, 0 ),
	// Santiago June = UTC-4: local Fri 17:55 = 21:55Z, local Mon 09:05 = 13:05Z
	array( 'H Santiago local Fri17:55->Mon09:05',       '2026-06-19 21:55:00', '2026-06-22 13:05:00', $SCL, 10 ),
);

$pass = 0; $fail = 0;
foreach ( $cases as $c ) {
	list( $label, $s, $e, $cal, $expect ) = $c;
	$out = Estandar_Project_Calendar::business_elapsed( $s, $e, $cal );
	$got = (int) $out['business_elapsed_minutes'];
	$ok  = ( $got === $expect );
	printf( "[%s] %-44s got=%d expect=%d\n", $ok ? 'PASS' : 'FAIL', $label, $got, $expect );
	$ok ? $pass++ : $fail++;
}

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail === 0 ? 0 : 1 );
