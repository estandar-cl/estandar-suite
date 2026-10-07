<?php
/** Run: php tests/run-admin-guard-contract.php */
$source = file_get_contents( dirname(__DIR__) . '/includes/class-estandar-seo-operations.php' ); $failed = 0;
function check( $label, $ok ) { global $failed; echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . "\n"; $failed += $ok ? 0 : 1; }
foreach ( array( 'test_connection', 'sync_now', 'backfill' ) as $action ) { check( $action . ' requires authorization boundary', (bool) preg_match( '/public static function ' . $action . '\(\): void \{\s*self::authorize\(/', $source ) ); }
check( 'authorization checks capability', strpos( $source, "current_user_can( self::CAP )" ) !== false );
check( 'authorization checks nonce', strpos( $source, 'check_admin_referer( $nonce )' ) !== false );
check( 'operation panel emits nonce fields', substr_count( $source, 'wp_nonce_field( $nonce )' ) === 1 );
exit( $failed ? 1 : 0 );
