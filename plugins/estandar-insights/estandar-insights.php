<?php
/**
 * Plugin Name: Estandar Suite — Insights
 * Description: Análisis de Estandar Suite v2: eventos, tráfico, funnel comercial
 * y web, auditoría SEO técnica y adopción de datos analíticos históricos de los
 * plugins originales (read-only). Producto aislado; NO reemplaza ni modifica los
 * plugins originales.
 * Version: 2.8.9
 * Requires at least: 6.0
 * Tested up to: 7.0
 * Requires PHP: 8.0
 * Text Domain: estandar-suite-insights
 * Update URI: https://updates.estandar.cl/staging/v2/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
	return;
}

define( 'ESTANDAR_SUITE_INSIGHTS_FILE', __FILE__ );
define( 'ESTANDAR_SUITE_INSIGHTS_VERSION', '2.8.9');
define( 'ESTANDAR_SUITE_INSIGHTS_PATH', plugin_dir_path( __FILE__ ) );

require_once ESTANDAR_SUITE_INSIGHTS_PATH . 'includes/Events.php';
require_once ESTANDAR_SUITE_INSIGHTS_PATH . 'includes/Funnel.php';
require_once ESTANDAR_SUITE_INSIGHTS_PATH . 'includes/Visual.php';
require_once ESTANDAR_SUITE_INSIGHTS_PATH . 'includes/WebFunnel.php';
require_once ESTANDAR_SUITE_INSIGHTS_PATH . 'includes/Adoption.php';
require_once ESTANDAR_SUITE_INSIGHTS_PATH . 'includes/Analytics.php';
require_once ESTANDAR_SUITE_INSIGHTS_PATH . 'includes/ExternalDaily.php';
require_once ESTANDAR_SUITE_INSIGHTS_PATH . 'includes/SeoAdoption.php';
require_once ESTANDAR_SUITE_INSIGHTS_PATH . 'includes/SeoAudit.php';
require_once ESTANDAR_SUITE_INSIGHTS_PATH . 'includes/Rest.php';
require_once ESTANDAR_SUITE_INSIGHTS_PATH . 'includes/Cli.php';
require_once ESTANDAR_SUITE_INSIGHTS_PATH . 'includes/Admin.php';

register_activation_hook( __FILE__, array( 'Estandar\\Insights\\Events', 'activate_schema' ) );
register_activation_hook( __FILE__, array( 'Estandar\\Insights\\Analytics', 'activate_schema' ) );
register_activation_hook( __FILE__, array( 'Estandar\\Insights\\ExternalDaily', 'activate_schema' ) );
register_activation_hook( __FILE__, array( 'Estandar\\Insights\\SeoAdoption', 'activate_schema' ) );
register_activation_hook( __FILE__, array( 'Estandar\\Insights\\SeoAudit', 'activate_schema' ) );

add_action( 'plugins_loaded', function () {
	Estandar\Insights\Events::maybe_migrate_schema();
	Estandar\Insights\Analytics::maybe_migrate_schema();
	Estandar\Insights\ExternalDaily::maybe_migrate_schema();
	Estandar\Insights\SeoAdoption::maybe_migrate_schema();
	Estandar\Insights\SeoAudit::maybe_migrate_schema();
	Estandar\Insights\SeoAudit::init();
	Estandar\Insights\ExternalDaily::maybe_seed_host();
	Estandar\Insights\Rest::init();
	Estandar\Insights\Cli::init();
	Estandar\Insights\Admin::init();

	// Record insights events for the canonical contracts (capture / signal).
	add_action( 'estandar_suite_capture_event', array( 'Estandar\\Insights\\Events', 'on_capture' ), 10, 1 );
	add_action( 'estandar_suite_signal_recorded', array( 'Estandar\\Insights\\Events', 'on_signal' ), 10, 1 );
}, 9 );
