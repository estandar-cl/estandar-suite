<?php
/**
 * Plugin Name: Estandar Suite — Capture
 * Description: Captación omnicanal de Estandar Suite v2: ingesta normalizada,
 * atribución (click IDs + UTM), webhooks de ingreso y adopción de leads
 * históricos de los plugins originales (lectura read-only, first-writer-wins).
 * Producto aislado; NO reemplaza ni modifica los plugins originales.
 * Version: 13.0.173
 * Requires PHP: 7.4
 * Text Domain: estandar-suite-capture
 * Update URI: https://updates.estandar.cl/staging/v2/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	return;
}

define( 'ESTANDAR_SUITE_CAPTURE_FILE', __FILE__ );
define( 'ESTANDAR_SUITE_CAPTURE_VERSION', '13.0.173' );
define( 'ESTANDAR_SUITE_CAPTURE_PATH', plugin_dir_path( __FILE__ ) );

require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/Ingestion.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/Attribution.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/Forms.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/ConversationEngine.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/FormBridge.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/Popups.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/Adoption.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/FormsAdoption.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/FormRenderer.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/Rest.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/Cli.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/Admin.php';

register_activation_hook( __FILE__, array( 'Estandar\\Capture\\Ingestion', 'activate_schema' ) );
register_activation_hook( __FILE__, array( 'Estandar\\Capture\\Forms', 'activate_schema' ) );
register_activation_hook( __FILE__, array( 'Estandar\\Capture\\Popups', 'activate_schema' ) );

add_action( 'plugins_loaded', function () {
	Estandar\Capture\Ingestion::maybe_migrate_schema();
	Estandar\Capture\Forms::maybe_migrate_schema();
	Estandar\Capture\Popups::maybe_migrate_schema();
	// Absorb legacy ad credentials (Meta/Google) once, without overwriting anything.
	if ( get_option( Estandar\Capture\Admin::LEADS_ADOPT_FLAG, '' ) !== '1' ) {
		Estandar\Capture\Admin::adopt_leads_credentials();
	}
	Estandar\Capture\Rest::init();
	Estandar\Capture\Cli::init();
	Estandar\Capture\Admin::init();
	Estandar\Capture\FormBridge::init();
	Estandar\Capture\FormRenderer::init();
	Estandar\Capture\ConversationEngine::register();
}, 6 );
