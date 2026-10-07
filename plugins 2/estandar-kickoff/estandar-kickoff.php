<?php
/**
 * Plugin Name: Estandar KickOff
 * Plugin URI: https://www.estandar.cl
 * Description: Swiss knife para WordPress. Subir SVG, personalizar login y más capacidades comunes en un solo plugin modular.
 * Version: 13.0.173
 * Author: Luis Valdés de Estandar Studios CL
 * Author URI: https://www.estandar.cl
 * License: GPLv2 or later
 * Text Domain: estandar-kickoff
 * Update URI: https://updates.estandar.cl
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ======================================================
 * CONSTANTES
 * ====================================================== */

define( 'ESTANDAR_KICKOFF_VERSION', '13.0.173' );
define( 'ESTANDAR_KICKOFF_PATH', plugin_dir_path( __FILE__ ) );
define( 'ESTANDAR_KICKOFF_URL', plugin_dir_url( __FILE__ ) );
define( 'ESTANDAR_KICKOFF_MODULES_PATH', ESTANDAR_KICKOFF_PATH . 'modules/' );

// Bootstrap fallback for primer_is_active().
// Executes only when Primer has not yet loaded (WordPress plugin load order is not
// guaranteed). Skipped entirely once Primer is present — Primer's own definition
// takes ownership.
if ( ! function_exists( 'primer_is_active' ) ) {
	function primer_is_active(): bool {
		return defined( 'PRIMER_VERSION' );
	}
}

if ( ! function_exists( 'kickoff_get_option' ) ) {
	function kickoff_get_option( $key, $default ) {
		$val = get_option( $key, null );
		return null === $val ? $default : $val;
	}
}

/* ======================================================
 * CARGA DEL SISTEMA DE MÓDULOS
 * ====================================================== */

require_once ESTANDAR_KICKOFF_PATH . 'includes/class-kickoff-admin.php';
require_once ESTANDAR_KICKOFF_PATH . 'includes/class-kickoff-ai-api.php';

if ( file_exists( plugin_dir_path( __FILE__ ) . 'modules/progressive-reveal/progressive-reveal.php' ) ) {
	require_once plugin_dir_path( __FILE__ ) . 'modules/progressive-reveal/progressive-reveal.php';
}

register_activation_hook( __FILE__, function () {
	flush_rewrite_rules();
} );

/*
 * One-time migration: remove stale keys from the modules-enabled option
 * after disable-comments, database-cleanup and regenerate-thumbnails were
 * removed in v1.1.0.
 */
add_action( 'plugins_loaded', function () {
	if ( get_option( 'estandar_kickoff_migration_110' ) ) {
		return;
	}
	$saved = get_option( 'estandar_kickoff_modules_enabled', [] );
	if ( is_array( $saved ) ) {
		foreach ( [ 'disable-comments', 'database-cleanup', 'regenerate-thumbnails' ] as $slug ) {
			unset( $saved[ $slug ] );
		}
		update_option( 'estandar_kickoff_modules_enabled', $saved );
	}
	delete_option( 'estandar_kickoff_disable_comments' );
	update_option( 'estandar_kickoff_migration_110', true );
}, 1 );

// Load translations after theme setup and before module configs call __().
add_action( 'after_setup_theme', function () {
	load_plugin_textdomain( 'estandar-kickoff', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}, 0 );

add_action( 'after_setup_theme', function () {
	Estandar_KickOff_AI_Api::init();
	Estandar_KickOff_Admin::get_instance();
}, 1 );
