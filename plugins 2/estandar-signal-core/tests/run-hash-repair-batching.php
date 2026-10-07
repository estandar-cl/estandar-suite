<?php
/**
 * Signal Core hash repair batching regression.
 *
 * Run: php tests/run-hash-repair-batching.php
 */

$file = __DIR__ . '/../includes/class-estandar-signal-core-schema.php';
$code = file_get_contents( $file );

$failed = 0;

function esc_hash_repair_check( bool $condition, string $label ): void {
	global $failed;
	echo ( $condition ? 'PASS' : 'FAIL' ) . ": {$label}\n";
	if ( ! $condition ) {
		$failed++;
	}
}

esc_hash_repair_check( strpos( $code, 'HASH_REPAIR_BATCH' ) !== false, 'batch size constant exists' );
esc_hash_repair_check( strpos( $code, 'HASH_REPAIR_MAX_PER_RUN' ) !== false, 'per-run cap exists' );
esc_hash_repair_check( strpos( $code, 'INNER JOIN' ) !== false, 'repair uses join instead of per-row lead lookup' );
esc_hash_repair_check( strpos( $code, 'LIMIT %d' ) !== false, 'repair query is bounded' );
esc_hash_repair_check( strpos( $code, 'HASH_REPAIR_CURSOR' ) !== false, 'repair cursor is persisted' );
esc_hash_repair_check( strpos( $code, 'wp_schedule_single_event' ) !== false, 'repair schedules continuation when capped' );

exit( $failed > 0 ? 1 : 0 );
