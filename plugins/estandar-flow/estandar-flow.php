<?php
/**
 * Plugin Name: Estandar Suite — Flow
 * Description: Operación comercial de Estandar Suite v2: pipeline/CRM, etapas
 * canónicas, clientes, cotizaciones y tareas. Adopción de datos históricos de
 * los plugins originales (read-only, first-writer-wins). Producto aislado;
 * NO reemplaza ni modifica los plugins originales.
 * Version: 3.0.0
 * Requires at least: 6.0
 * Tested up to: 7.0
 * Requires PHP: 8.0
 * Text Domain: estandar-suite-flow
 * Update URI: https://updates.estandar.cl/staging/v2/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
	return;
}

define( 'ESTANDAR_SUITE_FLOW_FILE', __FILE__ );
define( 'ESTANDAR_SUITE_FLOW_VERSION', '3.0.0');
define( 'ESTANDAR_SUITE_FLOW_PATH', plugin_dir_path( __FILE__ ) );

require_once ESTANDAR_SUITE_FLOW_PATH . 'includes/Pipeline.php';
require_once ESTANDAR_SUITE_FLOW_PATH . 'includes/Journey/StateMachine.php';
require_once ESTANDAR_SUITE_FLOW_PATH . 'includes/Customers.php';
require_once ESTANDAR_SUITE_FLOW_PATH . 'includes/Quotes.php';
require_once ESTANDAR_SUITE_FLOW_PATH . 'includes/QuotesAdoption.php';
require_once ESTANDAR_SUITE_FLOW_PATH . 'includes/Quotes/Schema.php';
require_once ESTANDAR_SUITE_FLOW_PATH . 'includes/Quotes/Repository.php';
require_once ESTANDAR_SUITE_FLOW_PATH . 'includes/Quotes/Document/Fields.php';
require_once ESTANDAR_SUITE_FLOW_PATH . 'includes/Quotes/Document/Calculator.php';
require_once ESTANDAR_SUITE_FLOW_PATH . 'includes/Quotes/Document/Template.php';
require_once ESTANDAR_SUITE_FLOW_PATH . 'includes/Quotes/Document/Renderer.php';
require_once ESTANDAR_SUITE_FLOW_PATH . 'includes/Quotes/Document/Pdf.php';
require_once ESTANDAR_SUITE_FLOW_PATH . 'includes/Quotes/Document/PdfStore.php';
require_once ESTANDAR_SUITE_FLOW_PATH . 'includes/Quotes/Artifacts.php';
// M5: configuración tipada del módulo + documento canónico que consume Renderer.
require_once ESTANDAR_SUITE_FLOW_PATH . 'includes/Quotes/Settings.php';
require_once ESTANDAR_SUITE_FLOW_PATH . 'includes/Quotes/DocumentBuilder.php';
// M6: editor WYSIWYG tipo SII (canvas editable + normalize/save por post).
require_once ESTANDAR_SUITE_FLOW_PATH . 'includes/Quotes/Editor.php';
// M7: ciclo de vida de la cotización + cron diario de expiración.
require_once ESTANDAR_SUITE_FLOW_PATH . 'includes/Quotes/Lifecycle.php';
require_once ESTANDAR_SUITE_FLOW_PATH . 'includes/Quotes/Expiry.php';
// M8: gateway público del cliente (/cotizacion/{token}) — rewrite + template_redirect.
require_once ESTANDAR_SUITE_FLOW_PATH . 'includes/Quotes/Gateway.php';
require_once ESTANDAR_SUITE_FLOW_PATH . 'includes/Adoption.php';
require_once ESTANDAR_SUITE_FLOW_PATH . 'includes/Rest.php';
require_once ESTANDAR_SUITE_FLOW_PATH . 'includes/Cli.php';
require_once ESTANDAR_SUITE_FLOW_PATH . 'includes/Admin.php';

register_activation_hook( __FILE__, array( 'Estandar\\Flow\\Pipeline', 'activate_schema' ) );
// Capa de datos aditiva del módulo de cotizaciones (M1): tablas satélite +
// columnas nuevas. Idempotente y aditiva; no toca la activación existente.
register_activation_hook( __FILE__, array( 'Estandar\\Flow\\Quotes\\Schema', 'activate' ) );
// M7: cron diario de expiración de cotizaciones (hook propio, aditivo).
register_activation_hook( __FILE__, array( 'Estandar\\Flow\\Quotes\\Expiry', 'schedule' ) );
register_deactivation_hook( __FILE__, array( 'Estandar\\Flow\\Quotes\\Expiry', 'unschedule' ) );
// M8: registra la regla de rewrite y hace flush SOLO en activación (aditivo).
register_activation_hook( __FILE__, array( 'Estandar\\Flow\\Quotes\\Gateway', 'activate' ) );
// UF: cron diario que refresca el valor UF del SII (hook propio, aditivo).
register_activation_hook( __FILE__, array( 'Estandar\\Flow\\Quotes\\Settings', 'schedule_uf_cron' ) );
register_deactivation_hook( __FILE__, array( 'Estandar\\Flow\\Quotes\\Settings', 'unschedule_uf_cron' ) );

add_action( 'plugins_loaded', function () {
	Estandar\Flow\Pipeline::maybe_migrate_schema();
	Estandar\Flow\Quotes\Schema::maybe_migrate();
	Estandar\Flow\Rest::init();
	Estandar\Flow\Cli::init();
	Estandar\Flow\Admin::init();

	// M8: gateway público del cliente (rewrite en `init` + template_redirect).
	Estandar\Flow\Quotes\Gateway::init();

	// Open a pipeline record when a capture is recorded (commercial continuity).
	add_action( 'estandar_suite_capture_event', array( 'Estandar\\Flow\\Pipeline', 'on_capture' ), 10, 1 );

	// Operación real del CRM: mueve el journey según las señales canónicas
	// (lead_qualified → QUALIFIED, meeting_booked → MEETING, sale_closed →
	// CLOSED_WON, lead_rejected → INVALID). Aditivo y best-effort.
	add_action( 'estandar_suite_signal_recorded', array( 'Estandar\\Flow\\Pipeline', 'on_signal' ), 20, 1 );

	// M7: cron diario que expira cotizaciones vencidas (hook propio).
	add_action( \Estandar\Flow\Quotes\Expiry::HOOK, array( 'Estandar\\Flow\\Quotes\\Expiry', 'run' ) );

	// UF: cron diario que refresca el valor UF del SII (hook propio).
	add_action( \Estandar\Flow\Quotes\Settings::UF_CRON_HOOK, array( 'Estandar\\Flow\\Quotes\\Settings', 'cron_refresh' ) );
	// Auto-reparación: si el evento se perdió, se reprograma (idempotente).
	\Estandar\Flow\Quotes\Settings::schedule_uf_cron();
}, 8 );
