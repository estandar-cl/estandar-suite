<?php
/**
 * run-gate-wp-api-compat.php — autorregresión del gate de compatibilidad.
 *
 * Verifica que gate-wp-api-compat.php detecta una llamada a una función que no
 * existe en WP 7.1 (upgrade_plugin, eliminada) y que NO reporta falsos
 * positivos con una función de WP core real (get_transient) ni con una
 * protegida por function_exists().
 *
 *   php tests/run-gate-wp-api-compat.php   → expect "0 failed"
 */

$root = dirname( __DIR__ );
$fixture = $root . '/plugins/estandar-gate-fixture';
$plugins = $root . '/plugins';

if ( file_exists( $fixture ) ) {
	shell_exec( 'rm -rf ' . escapeshellarg( $fixture ) );
}
mkdir( $fixture, 0777, true );

$plugin_dir = $fixture;

$clean_file = $plugin_dir . '/fixture.php';
file_put_contents( $clean_file, "<?php\n// solo WP core + guard\nfunction gf_ok(){ return get_transient('x'); }\nif ( function_exists('estandar_ghost_ok') ) { estandar_ghost_ok(); }\n" );

$bad_file = $plugin_dir . '/fixture-bad.php';
file_put_contents( $bad_file, "<?php\nfunction gf_bad(){ return upgrade_plugin('x'); }\n" );

$passed = 0;
$failed = 0;
function gate_check( string $label, bool $cond ): void {
	global $passed, $failed;
	if ( $cond ) { $passed++; echo "PASS  {$label}\n"; }
	else { $failed++; echo "FAIL  {$label}\n"; }
}

// Correr el gate contra el fixture (scope = el plugin de fixture).
$cmd = 'php ' . escapeshellarg( $root . '/tests/gate-wp-api-compat.php' ) . ' --scope=estandar-gate-fixture';
exec( $cmd . ' 2>&1', $out, $rc );

// El fixture "bueno" solo usa get_transient (whitelist) y estandar_ghost_ok
// (guard function_exists) → no debe reportar. El fixture "malo" usa
// upgrade_plugin (eliminada) → debe reportar y fallar.
$joined = implode( "\n", $out );
gate_check( 'gate detecta función ausente (upgrade_plugin) y falla', $rc !== 0 && strpos( $joined, 'upgrade_plugin' ) !== false );
gate_check( 'gate NO acusa get_transient (WP core real)', strpos( $joined, 'get_transient' ) === false );
gate_check( 'gate NO acusa función protegida por function_exists', strpos( $joined, 'estandar_ghost_ok' ) === false );

shell_exec( 'rm -rf ' . escapeshellarg( $fixture ) );

echo "\nRESULT: {$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
