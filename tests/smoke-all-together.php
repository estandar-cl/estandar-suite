<?php
/**
 * smoke-all-together.php — Smoke "todos juntos" para el release gate.
 *
 * Activa TODOS los plugins (26 Estandar + extras) en el Playground local y
 * verifica que la activación conjunta no genere errores fatales. Idempotente:
 * los plugins ya activos se cuentan como "ya-activos", no como fallos.
 *
 * Salida de éxito: "SMOKE_OK activados=N fallos=0 ya_activos=M"
 * Salida de fallo:  "SMOKE_FAIL ..." + exit(1)
 */

$_SERVER['HTTP_HOST'] = 'localhost:8080';
$_SERVER['REQUEST_URI'] = '/';
define( 'WP_USE_THEMES', false );

$suite_root = dirname( __DIR__ );
require $suite_root . '/Playground/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$debug_log = $suite_root . '/Playground/wp-content/debug.log';
$fatals_before = 0;
if ( is_file( $debug_log ) ) {
	$haystack = (string) file_get_contents( $debug_log );
	preg_match_all( '/Fatal error/', $haystack, $m );
	$fatals_before = count( $m[0] );
}

$activated = 0;
$failed    = 0;
$already   = 0;
$skipped   = 0;

$dirs = glob( WP_PLUGIN_DIR . '/*' ) ?: array();
foreach ( $dirs as $dir ) {
	if ( ! is_dir( $dir ) ) {
		continue;
	}
	$slug = basename( $dir );
	if ( $slug === '' || $slug[0] === '.' ) {
		continue;
	}

	$main = '';
	foreach ( glob( $dir . '/*.php' ) ?: array() as $candidate ) {
		$head = (string) file_get_contents( $candidate, false, null, 0, 4000 );
		if ( strpos( $head, 'Plugin Name:' ) !== false ) {
			$main = $candidate;
			break;
		}
	}
	if ( $main === '' ) {
		$skipped++;
		continue;
	}

	$rel = $slug . '/' . basename( $main );
	if ( is_plugin_active( $rel ) ) {
		$already++;
		continue;
	}

	$result = activate_plugin( $rel );
	if ( is_wp_error( $result ) ) {
		$failed++;
		echo "SMOKE_ACTIVATE_FAIL {$rel}: " . $result->get_error_message() . "\n";
		continue;
	}
	$activated++;
}

// Verificación posterior: sin fatals nuevos en debug.log.
$fatals_after = 0;
if ( is_file( $debug_log ) ) {
	$haystack = (string) file_get_contents( $debug_log );
	preg_match_all( '/Fatal error/', $haystack, $m );
	$fatals_after = count( $m[0] );
}

if ( $failed > 0 || $fatals_after > $fatals_before ) {
	echo "SMOKE_FAIL activados={$activated} fallos={$failed} ya_activos={$already} fatals_nuevos=" . ( $fatals_after - $fatals_before ) . "\n";
	exit( 1 );
}

echo "SMOKE_OK activados={$activated} fallos={$failed} ya_activos={$already} fatals_nuevos=" . ( $fatals_after - $fatals_before ) . "\n";
exit( 0 );
