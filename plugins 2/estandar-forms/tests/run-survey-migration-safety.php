<?php
/**
 * Survey migration safety regression.
 *
 * Run: php tests/run-survey-migration-safety.php
 */

$file = __DIR__ . '/../includes/class-survey-db.php';
$code = file_get_contents( $file );

$failed = 0;

function ef_survey_safety_check( bool $condition, string $label ): void {
	global $failed;
	echo ( $condition ? 'PASS' : 'FAIL' ) . ": {$label}\n";
	if ( ! $condition ) {
		$failed++;
	}
}

ef_survey_safety_check( strpos( $code, 'DROP TABLE' ) === false, 'migration never drops survey tables' );
ef_survey_safety_check( strpos( $code, 'function retire_table' ) !== false, 'retire_table helper exists' );
ef_survey_safety_check( strpos( $code, '_legacy_' ) !== false, 'occupied legacy table gets timestamped backup name' );
ef_survey_safety_check( strpos( $code, 'RENAME TABLE' ) !== false, 'migration retires old tables by rename' );

exit( $failed > 0 ? 1 : 0 );
