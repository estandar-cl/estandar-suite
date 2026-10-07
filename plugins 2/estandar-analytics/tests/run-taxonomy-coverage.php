<?php
/**
 * Engineering diagnostic — Acquisition Intelligence commercial taxonomy coverage.
 * Run: php tests/run-taxonomy-coverage.php
 *
 * Asserts every canonical status (Estandar_Leads_Normalizer::statuses()) maps to
 * exactly one funnel/non-funnel bucket, aliases stay isolated, no duplicates, and
 * a future unmapped canonical status FAILS fast. Not exposed in production UI.
 */

define( 'ABSPATH', __DIR__ . '/' );

function get_option( $k, $d = false ) { return $d; }
function apply_filters( $h, $v ) { return $v; }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $v ) ); }
function esc_sql( $v ) { return addslashes( (string) $v ); }
function wp_json_encode( $v ) { return json_encode( $v ); }

// Stub of the canonical vocabulary source of truth (mirrors Normalizer::statuses()).
class Estandar_Leads_Normalizer {
	public static $extra = array();
	public static function statuses() {
		return array_merge( array(
			'NEW', 'CONTACTED', 'NO_RESPONSE', 'UNREACHABLE', 'QUALIFIED',
			'MEETING_BOOKED', 'MEETING', 'PROPOSAL_SENT', 'NEGOTIATION', 'CONVERTED',
			'LOST', 'INVALID',
			'VISIT_SCHEDULED', 'VISITED', 'BANK_EVALUATION', 'RESERVED',
			'DEED_SIGNED', 'DELIVERED', 'SALE',
		), self::$extra );
	}
}

require dirname( __DIR__ ) . '/includes/class-estandar-analytics-commercial-stage-map.php';
require dirname( __DIR__ ) . '/includes/class-dashboard.php';

$fail = 0;

// ── Report (TASK 5) ──────────────────────────────────────────────────────────
$r = EA_Dashboard::ai_taxonomy_self_test();
echo "Acquisition Intelligence — Taxonomy Coverage\n";
echo "--------------------------------------------\n";
echo "Canonical statuses : " . $r['canonical_count'] . "\n";
echo "Mapped (funnel)    : " . $r['mapped'] . "\n";
echo "Explicit non-funnel: " . $r['non_funnel'] . "\n";
echo "Aliases            : " . implode( ', ', $r['aliases'] ) . "\n";
echo "Unknown            : " . ( empty( $r['unknown'] ) ? '0' : implode( ', ', $r['unknown'] ) ) . "\n";
echo "Unmapped           : " . ( empty( $r['unmapped'] ) ? '0' : implode( ', ', $r['unmapped'] ) ) . "\n";
echo "Duplicates         : " . ( empty( $r['duplicates'] ) ? '0' : count( $r['duplicates'] ) ) . "\n";
echo "Coverage %         : " . $r['coverage_pct'] . "\n\n";

$checks = array(
	'100% coverage'          => $r['coverage_pct'] === 100.0,
	'no unmapped'            => empty( $r['unmapped'] ),
	'no duplicates'          => empty( $r['duplicates'] ),
	'no unknown'             => empty( $r['unknown'] ),
	'aliases isolated'       => $r['aliases'] === array( 'CLOSED_LOST' ),
	'self-test ok'           => $r['ok'] === true,
	'assert() does not throw'=> ( function () { try { EA_Dashboard::ai_taxonomy_assert(); return true; } catch ( Throwable $e ) { return false; } } )(),
);

// ── Fail-fast on a future unmapped canonical status (TASK 3) ─────────────────
Estandar_Leads_Normalizer::$extra = array( 'FOLLOW_UP_REQUIRED' );
$r2 = EA_Dashboard::ai_taxonomy_self_test();
$threw = false;
$msg = '';
try {
	EA_Dashboard::ai_taxonomy_assert();
} catch ( Throwable $e ) {
	$threw = true;
	$msg = $e->getMessage();
}
$checks['future status FAILS fast'] = $threw && strpos( $msg, 'Unmapped canonical status: FOLLOW_UP_REQUIRED' ) !== false;
$checks['future status not defaulted'] = in_array( 'FOLLOW_UP_REQUIRED', $r2['unmapped'], true );
Estandar_Leads_Normalizer::$extra = array();

foreach ( $checks as $label => $ok ) {
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . "\n";
	$fail += $ok ? 0 : 1;
}

exit( $fail === 0 ? 0 : 1 );
