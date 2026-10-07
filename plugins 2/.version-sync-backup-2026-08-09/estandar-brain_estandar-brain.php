<?php
/**
 * Plugin Name: Estandar Suite — Brain
 * Description: Inteligencia transversal de Estandar Suite v2: reglas
 * deterministas, scoring, intents, ledgers de señales canónicas y adopción de
 * señales históricas de los plugins originales. Producto aislado; NO reemplaza
 * ni modifica los plugins originales.
 * Version: 13.0.172
 * Requires PHP: 7.4
 * Text Domain: estandar-suite-brain
 * Update URI: https://updates.estandar.cl/staging/v2/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	return;
}

define( 'ESTANDAR_SUITE_BRAIN_FILE', __FILE__ );
define( 'ESTANDAR_SUITE_BRAIN_VERSION', '1.0.11' );
define( 'ESTANDAR_SUITE_BRAIN_PATH', plugin_dir_path( __FILE__ ) );

require_once ESTANDAR_SUITE_BRAIN_PATH . 'includes/Rules.php';
require_once ESTANDAR_SUITE_BRAIN_PATH . 'includes/AssessmentEngine.php';
require_once ESTANDAR_SUITE_BRAIN_PATH . 'includes/Scoring.php';
require_once ESTANDAR_SUITE_BRAIN_PATH . 'includes/IntentMap.php';
require_once ESTANDAR_SUITE_BRAIN_PATH . 'includes/Contactability.php';
require_once ESTANDAR_SUITE_BRAIN_PATH . 'includes/SignalLedger.php';
require_once ESTANDAR_SUITE_BRAIN_PATH . 'includes/Adoption.php';
require_once ESTANDAR_SUITE_BRAIN_PATH . 'includes/Agent.php';
require_once ESTANDAR_SUITE_BRAIN_PATH . 'includes/Knowledge.php';
require_once ESTANDAR_SUITE_BRAIN_PATH . 'includes/PublicChat.php';
require_once ESTANDAR_SUITE_BRAIN_PATH . 'includes/Rest.php';
require_once ESTANDAR_SUITE_BRAIN_PATH . 'includes/Cli.php';
require_once ESTANDAR_SUITE_BRAIN_PATH . 'includes/Admin.php';

register_activation_hook( __FILE__, array( 'Estandar\\Brain\\SignalLedger', 'activate_schema' ) );

	add_action( 'plugins_loaded', function () {
	Estandar\Brain\SignalLedger::maybe_migrate_schema();
	Estandar\Brain\Agent::maybe_migrate_schema();
	Estandar\Brain\Knowledge::maybe_migrate_schema();
	Estandar\Brain\PublicChat::init();
	Estandar\Brain\Rest::init();
	Estandar\Brain\Cli::init();
	Estandar\Brain\Admin::init();

	// Absorb legacy credentials (Meta/Google) once, without overwriting anything.
	if ( get_option( Estandar\Brain\Admin::ADOPT_FLAG, '' ) !== '1' ) {
		Estandar\Brain\Admin::adopt_legacy_credentials();
	}

	// Consume the Capture contract: score + record signal for each capture.
	add_action( 'estandar_suite_capture_event', array( 'Estandar\\Brain\\SignalLedger', 'on_capture' ), 10, 1 );
}, 7 );
