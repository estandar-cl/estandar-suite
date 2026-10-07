<?php
/**
 * Plugin Name: Estandar Suite — Flow
 * Description: Operación comercial de Estandar Suite v2: pipeline/CRM, etapas
 * canónicas, clientes, cotizaciones y tareas. Adopción de datos históricos de
 * los plugins originales (read-only, first-writer-wins). Producto aislado;
 * NO reemplaza ni modifica los plugins originales.
 * Version: 13.0.172
 * Requires PHP: 7.4
 * Text Domain: estandar-suite-flow
 * Update URI: https://updates.estandar.cl/staging/v2/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	return;
}

define( 'ESTANDAR_SUITE_FLOW_FILE', __FILE__ );
define( 'ESTANDAR_SUITE_FLOW_VERSION', '1.0.11' );
define( 'ESTANDAR_SUITE_FLOW_PATH', plugin_dir_path( __FILE__ ) );

require_once ESTANDAR_SUITE_FLOW_PATH . 'includes/Pipeline.php';
require_once ESTANDAR_SUITE_FLOW_PATH . 'includes/Journey/StateMachine.php';
require_once ESTANDAR_SUITE_FLOW_PATH . 'includes/Journey/LeadAdapter.php';
require_once ESTANDAR_SUITE_FLOW_PATH . 'includes/Journey/InterestAdapter.php';
require_once ESTANDAR_SUITE_FLOW_PATH . 'includes/Journey/ReEngagement.php';
require_once ESTANDAR_SUITE_FLOW_PATH . 'includes/Journey/Recovery.php';
require_once ESTANDAR_SUITE_FLOW_PATH . 'includes/Journey/Outcomes.php';
require_once ESTANDAR_SUITE_FLOW_PATH . 'includes/Customers.php';
require_once ESTANDAR_SUITE_FLOW_PATH . 'includes/Quotes.php';
require_once ESTANDAR_SUITE_FLOW_PATH . 'includes/Adoption.php';
require_once ESTANDAR_SUITE_FLOW_PATH . 'includes/Rest.php';
require_once ESTANDAR_SUITE_FLOW_PATH . 'includes/Cli.php';
require_once ESTANDAR_SUITE_FLOW_PATH . 'includes/Admin.php';

register_activation_hook( __FILE__, array( 'Estandar\\Flow\\Pipeline', 'activate_schema' ) );
register_activation_hook( __FILE__, array( 'Estandar\\Flow\\Journey\\Outcomes', 'activate_schema' ) );

add_action( 'plugins_loaded', function () {
	Estandar\Flow\Pipeline::maybe_migrate_schema();
	Estandar\Flow\Rest::init();
	Estandar\Flow\Cli::init();
	Estandar\Flow\Admin::init();

	// Open a pipeline record when a capture is recorded (commercial continuity).
	add_action( 'estandar_suite_capture_event', array( 'Estandar\\Flow\\Pipeline', 'on_capture' ), 10, 1 );
}, 8 );
