<?php
/**
 * PHPUnit bootstrap para Estandar KickOff.
 * Carga WordPress cuando está disponible.
 */

$plugin_dir = dirname( __DIR__ );

$wp_load_candidates = array_filter( [
	getenv( 'WP_LOAD_PATH' ),
	'/var/www/html/wp-load.php',
	$plugin_dir . '/../../../wp-load.php',
] );

foreach ( $wp_load_candidates as $path ) {
	if ( is_string( $path ) && file_exists( $path ) ) {
		require_once $path;
		break;
	}
}

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "WordPress no encontrado. Define WP_LOAD_PATH o ejecuta desde Docker.\n" );
	exit( 1 );
}

require_once $plugin_dir . '/estandar-kickoff.php';
require_once $plugin_dir . '/modules/svg-upload/class-svg-upload.php';
