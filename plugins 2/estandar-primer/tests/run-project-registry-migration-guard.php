<?php
/**
 * Project Registry price migration guard regression.
 *
 * Run: php tests/run-project-registry-migration-guard.php
 */

$file = __DIR__ . '/../includes/class-project-registry.php';
$code = file_get_contents( $file );

$failed = 0;

function epr_guard_check( bool $condition, string $label ): void {
	global $failed;
	echo ( $condition ? 'PASS' : 'FAIL' ) . ": {$label}\n";
	if ( ! $condition ) {
		$failed++;
	}
}

epr_guard_check( strpos( $code, 'function column_int_castable' ) !== false, 'castability guard exists' );
epr_guard_check( substr_count( $code, 'self::column_int_castable' ) >= 2, 'both price columns are guarded' );
epr_guard_check( strpos( $code, "REGEXP '^[0-9]+$'" ) !== false, 'only non-negative integer literals are castable' );
epr_guard_check( strpos( $code, 'function report_unsafe_price_migration' ) !== false, 'unsafe migration is reported, not coerced' );
epr_guard_check( strpos( $code, 'PROJECT_REGISTRY_PRICE_MIGRATION_SKIPPED' ) !== false, 'telemetry code identifies skipped migration' );

exit( $failed > 0 ? 1 : 0 );
