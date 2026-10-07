<?php
define( 'ABSPATH', __DIR__ );
require dirname( __DIR__ ) . '/includes/class-whatsapp.php';

$passed = 0; $failed = 0;
function check_health( string $label, bool $condition ): void {
	global $passed, $failed;
	$condition ? $passed++ : $failed++;
	echo ( $condition ? 'PASS  ' : 'FAIL  ' ) . $label . "\n";
}

$base = array( 'detector_active' => true, 'bridge_active' => true, 'direct_links' => 0, 'total' => 10, 'observed' => 5, 'confirmed' => 5, 'errors' => 0 );
check_health( 'healthy tracking has no recommendations', EA_WhatsApp::diagnostics( $base ) === array() );
check_health( 'bridge without detector is diagnosed', count( EA_WhatsApp::diagnostics( array_merge( $base, array( 'detector_active' => false ) ) ) ) === 1 );
check_health( 'direct links outside bridge are diagnosed', count( EA_WhatsApp::diagnostics( array_merge( $base, array( 'direct_links' => 2 ) ) ) ) === 1 );
check_health( 'unused configured bridge is diagnosed', count( EA_WhatsApp::diagnostics( array_merge( $base, array( 'total' => 0 ) ) ) ) === 1 );
check_health( 'observed confirmed gap is diagnosed', count( EA_WhatsApp::diagnostics( array_merge( $base, array( 'observed' => 10, 'confirmed' => 2 ) ) ) ) === 1 );
check_health( 'recorder errors are diagnosed', count( EA_WhatsApp::diagnostics( array_merge( $base, array( 'errors' => 1 ) ) ) ) === 1 );
echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed ? 1 : 0 );
