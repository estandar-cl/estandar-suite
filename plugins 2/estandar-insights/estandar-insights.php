<?php
/**
 * Plugin Name: Estandar Suite — Insights
 * Description: Análisis y observabilidad de Estandar Suite v2: eventos, sesiones,
 * funnel comercial, retención y adopción de datos analíticos históricos de los
 * plugins originales (read-only). Producto aislado; NO reemplaza ni modifica los
 * plugins originales.
 * Version: 13.0.173
 * Requires PHP: 7.4
 * Text Domain: estandar-suite-insights
 * Update URI: https://updates.estandar.cl/staging/v2/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	return;
}

define( 'ESTANDAR_SUITE_INSIGHTS_FILE', __FILE__ );
define( 'ESTANDAR_SUITE_INSIGHTS_VERSION', '13.0.173' );
define( 'ESTANDAR_SUITE_INSIGHTS_PATH', plugin_dir_path( __FILE__ ) );

require_once ESTANDAR_SUITE_INSIGHTS_PATH . 'includes/Events.php';
require_once ESTANDAR_SUITE_INSIGHTS_PATH . 'includes/Funnel.php';
require_once ESTANDAR_SUITE_INSIGHTS_PATH . 'includes/Observability.php';
require_once ESTANDAR_SUITE_INSIGHTS_PATH . 'includes/Adoption.php';
require_once ESTANDAR_SUITE_INSIGHTS_PATH . 'includes/Rest.php';
require_once ESTANDAR_SUITE_INSIGHTS_PATH . 'includes/Cli.php';
require_once ESTANDAR_SUITE_INSIGHTS_PATH . 'includes/Admin.php';

register_activation_hook( __FILE__, array( 'Estandar\\Insights\\Events', 'activate_schema' ) );

add_action( 'plugins_loaded', function () {
	Estandar\Insights\Events::maybe_migrate_schema();
	Estandar\Insights\Rest::init();
	Estandar\Insights\Cli::init();
	Estandar\Insights\Admin::init();

	// Observability: record insights events for the canonical contracts.
	add_action( 'estandar_suite_capture_event', array( 'Estandar\\Insights\\Events', 'on_capture' ), 10, 1 );
	add_action( 'estandar_suite_signal_recorded', array( 'Estandar\\Insights\\Events', 'on_signal' ), 10, 1 );
}, 9 );
