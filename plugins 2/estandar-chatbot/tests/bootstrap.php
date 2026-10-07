<?php
/**
 * PHPUnit bootstrap para Estandar Chatbot IA.
 * Carga WordPress cuando está disponible (Docker, CI o instalación local).
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

// Asegurar que el plugin esté cargado (por si no está activo en la BD de tests)
if ( ! function_exists( 'estandar_chatbot_storage_path' ) ) {
	require_once $plugin_dir . '/includes/helpers.php';
}
