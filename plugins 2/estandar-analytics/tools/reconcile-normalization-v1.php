<?php
/**
 * ANALYTICS_NORMALIZATION_V1 — Read-only reconciliation diff.
 *
 * Compares commercial stage bucket counts computed via legacy hardcoded status
 * sets versus the canonical Estandar_Analytics_Commercial_Stage_Map.
 *
 * Usage: php tools/reconcile-normalization-v1.php
 *
 * Safety: READ-ONLY. No writes, no options, no schema changes.
 * Outputs JSON to stdout. Exit code 0 = all deltas explainable.
 */

declare( strict_types=1 );

require_once dirname( __DIR__, 4 ) . '/wp-load.php';

$failures = array();
$results  = array();

global $wpdb;
$leads_table = $wpdb->prefix . 'es_leads';

if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$leads_table}'" ) ) {
	echo json_encode( array( 'error' => 'es_leads table not found' ), JSON_PRETTY_PRINT ) . "\n";
	exit( 1 );
}

// ─── Legacy status sets (from pre-NORMALIZATION_V1 code) ──────────────────
$legacy = array(
	'qualified' => array( 'CONTACTED', 'QUALIFIED', 'PROPOSAL_SENT', 'NEGOTIATION', 'BANK_EVALUATION' ),
	'meeting'   => array( 'MEETING_BOOKED', 'MEETING', 'VISIT_SCHEDULED', 'VISITED' ),
	'reserved'  => array( 'RESERVED' ),
	'sold'      => array( 'DEED_SIGNED', 'DELIVERED', 'CONVERTED', 'SALE' ),
	'lost'      => array( 'LOST' ),
);

// ─── Stage Map (canonical source) ─────────────────────────────────────────
if ( ! class_exists( 'Estandar_Analytics_Commercial_Stage_Map' ) ) {
	require_once dirname( __DIR__ ) . '/includes/class-estandar-analytics-commercial-stage-map.php';
}
$canonical = Estandar_Analytics_Commercial_Stage_Map::map();
$installed = is_array( get_option( Estandar_Analytics_Commercial_Stage_Map::OPTION, false ) );

echo "=== ANALYTICS_NORMALIZATION_V1 :: Reconciliation Diff ===\n";
echo "Run: " . gmdate( 'c' ) . "\n";
echo "DB:  {$wpdb->dbname}\n";
echo "Custom stage map installed: " . ( $installed ? 'YES' : 'NO' ) . "\n\n";

// ─── Per-stage comparison ─────────────────────────────────────────────────
$all_stages = array_unique( array_merge( array_keys( $legacy ), array_keys( $canonical ) ) );
sort( $all_stages );

$total_legacy   = 0;
$total_canonical = 0;

foreach ( $all_stages as $stage ) {
	$legacy_statuses   = $legacy[ $stage ] ?? array();
	$canonical_statuses = $canonical[ $stage ] ?? array();

	// Count legacy
	if ( ! empty( $legacy_statuses ) ) {
		$placeholders = implode( ',', array_fill( 0, count( $legacy_statuses ), '%s' ) );
		$legacy_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$leads_table} WHERE status IN ({$placeholders})",
				$legacy_statuses
			)
		);
	} else {
		$legacy_count = 0;
	}

	// Count canonical
	if ( ! empty( $canonical_statuses ) ) {
		$placeholders = implode( ',', array_fill( 0, count( $canonical_statuses ), '%s' ) );
		$canonical_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$leads_table} WHERE status IN ({$placeholders})",
				$canonical_statuses
			)
		);
	} else {
		$canonical_count = 0;
	}

	$delta       = $canonical_count - $legacy_count;
	$delta_label = $delta > 0 ? '+' . $delta : (string) $delta;

	// Check if canonical is a superset of legacy
	$extra_statuses = array_diff( $canonical_statuses, $legacy_statuses );
	$missing_statuses = array_diff( $legacy_statuses, $canonical_statuses );

	$explainable = true;
	$reason      = '';
	if ( $delta > 0 && ! empty( $extra_statuses ) ) {
		$reason = "canonical adds: " . implode( ', ', $extra_statuses );
		$explainable = false;
	} elseif ( $delta < 0 && ! empty( $missing_statuses ) ) {
		$reason = "legacy has extra: " . implode( ', ', $missing_statuses );
		$explainable = false;
	} elseif ( $delta === 0 ) {
		$reason = 'identical';
	}

	$results[ $stage ] = array(
		'legacy'             => $legacy_count,
		'canonical'          => $canonical_count,
		'delta'              => $delta,
		'delta_explainable'  => $explainable,
		'reason'             => $reason,
		'legacy_statuses'    => $legacy_statuses,
		'canonical_statuses' => $canonical_statuses,
		'extra_in_canonical' => array_values( $extra_statuses ),
		'missing_from_canonical' => array_values( $missing_statuses ),
	);

	$total_legacy   += $legacy_count;
	$total_canonical += $canonical_count;
}

// ─── Total leads ──────────────────────────────────────────────────────────
$total_leads = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$leads_table}" );

// ─── Unmapped statuses ────────────────────────────────────────────────────
$all_canonical_statuses = array();
foreach ( $canonical as $statuses ) {
	$all_canonical_statuses = array_merge( $all_canonical_statuses, $statuses );
}
$all_canonical_statuses = array_unique( $all_canonical_statuses );

$db_statuses = $wpdb->get_col( "SELECT DISTINCT status FROM {$leads_table} WHERE status <> '' AND status IS NOT NULL" );
$db_statuses = is_array( $db_statuses ) ? $db_statuses : array();

$unmapped = array();
foreach ( $db_statuses as $st ) {
	if ( ! in_array( $st, $all_canonical_statuses, true ) ) {
		$cnt = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$leads_table} WHERE status = %s", $st ) );
		if ( $cnt > 0 ) {
			$unmapped[ $st ] = $cnt;
		}
	}
}

// ─── Identity attribution coverage ────────────────────────────────────────
$with_session = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$leads_table} WHERE first_session_id <> '' AND first_session_id IS NOT NULL" );
$without_session = $total_leads - $with_session;

// ─── Output ───────────────────────────────────────────────────────────────
$output = array(
	'timestamp'          => gmdate( 'c' ),
	'database'           => $wpdb->dbname,
	'total_leads'        => $total_leads,
	'with_session'       => $with_session,
	'without_session'    => $without_session,
	'custom_map_active'  => $installed,
	'stages'             => $results,
	'total_legacy'       => $total_legacy,
	'total_canonical'    => $total_canonical,
	'total_delta'        => $total_canonical - $total_legacy,
	'unmapped_statuses'  => $unmapped,
);

echo json_encode( $output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";

// ─── Validation ───────────────────────────────────────────────────────────
$all_ok = true;

if ( $total_legacy !== $total_canonical ) {
	echo "\n⚠ WARNING: Legacy total ({$total_legacy}) != Canonical total ({$total_canonical})\n";
	echo "  Delta: " . ( $total_canonical - $total_legacy ) . "\n";
}

foreach ( $results as $stage => $r ) {
	if ( ! $r['delta_explainable'] ) {
		echo "⚠ Unexpected delta in '{$stage}': legacy={$r['legacy']} canonical={$r['canonical']} ({$r['reason']})\n";
		$all_ok = false;
	}
}

if ( ! empty( $unmapped ) ) {
	echo "⚠ Unmapped statuses found:\n";
	foreach ( $unmapped as $st => $cnt ) {
		echo "   '{$st}' — {$cnt} leads\n";
	}
}

if ( $all_ok && empty( $unmapped ) ) {
	echo "\n✓ ALL STAGES RECONCILED — 0 deltas, 0 unmapped statuses\n";
} else {
	echo "\n";
}

exit( $all_ok && empty( $unmapped ) ? 0 : 1 );
