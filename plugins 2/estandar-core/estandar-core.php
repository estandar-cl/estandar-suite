<?php
/**
 * Plugin Name: Estandar Suite — Core
 * Description: Infraestructura técnica reutilizable de Estandar Suite v2: framework,
 * contratos, telemetría, secretos one-time, motor de destinos, WP-CLI, REST, salud
 * y adopción de datos legados. Producto aislado (namespaces/tablas/options propios);
 * NO reemplaza ni modifica los plugins originales.
 * Version: 13.0.173
 * Requires PHP: 7.4
 * Text Domain: estandar-suite-core
 * Update URI: https://updates.estandar.cl/staging/v2/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	return;
}

define( 'ESTANDAR_SUITE_CORE_FILE', __FILE__ );
define( 'ESTANDAR_SUITE_CORE_VERSION', '13.0.173' );
define( 'ESTANDAR_SUITE_CORE_PATH', plugin_dir_path( __FILE__ ) );
define( 'ESTANDAR_SUITE_CORE_PREFIX', 'estandar_suite_core_' );

require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Contracts.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Crypto.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Embed.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Mail.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Communications/Transports/TransportInterface.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Communications/Transports/WpMailTransport.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Communications/Transports/SmtpTransport.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Communications/Transports/BrevoTransport.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Communications/TransportRegistry.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Communications/DeliveryLog.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Communications/Documents/DocumentInterface.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Communications/Documents/Pdf.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Communications/Inbound/Contracts.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Schema.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Telemetry.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Secrets.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/OAuth.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Connections.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Http.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/FieldMap.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Conditions.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Adapters.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Adapters/AdapterInterface.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Adapters/GoogleSheets.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Adapters/ExcelOnline.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Adapters/Simulated.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Destinations.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Integrations.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/IntegrationsRest.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Sandbox.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/AdoptionProbe.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Channels.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Optimal.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Health.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/LegacyMap.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Updates.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Rest.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Cli.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Admin.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/JourneyTemplates.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Calendar.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/AdsIntelligence.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Interest/InterestSignals.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Interest/InterestChannel.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Interest/InterestState.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Interest/InterestPriority.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Interest/NextBestAction.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Interest/InterestEngine.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Interest/InterestEventLog.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Interest/InterestContext.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Interest/EngagementEngine.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Quality/ContactabilityEngine.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Quality/LeadQualityModel.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Quality/OperatorFeedbackParser.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Quality/LeadQualityRecalculator.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Quality/LeadQualityConsistency.php';

register_activation_hook( __FILE__, array( 'Estandar\\Core\\Schema', 'activate' ) );
register_activation_hook( __FILE__, array( 'Estandar\\Core\\Calendar', 'activate_schema' ) );

add_action( 'plugins_loaded', function () {
	Estandar\Core\OAuth::init();
	Estandar\Core\Adapters::init();
	Estandar\Core\Schema::maybe_migrate();
	Estandar\Core\Rest::init();
	Estandar\Core\Cli::init();
	Estandar\Core\Admin::init();
	Estandar\Core\Updates::boot();
	// Telemetry: daily retention sweep.
	Estandar\Core\Telemetry::init();
	// Public one-time secret page (?estandar_secret=TOKEN).
	Estandar\Core\Secrets::init_frontend();

	// Integration engine: async drain via scheduled event.
	add_action( 'estandar_suite_core_drain', function () {
		Estandar\Core\Destinations::drain( 25 );
	} );

	// Legacy compatibility: re-register legacy shortcodes whose owning plugin
	// is deactivated (keeps the front intact during and after migration).
	add_action( 'init', array( 'Estandar\\Core\\LegacyMap', 'init' ), 20 );

	// Dispatch canonical events to mapped destinations (event → destination).
	add_action( 'estandar_suite_capture_event', function ( $capture ) {
		Estandar\Core\Destinations::dispatch_event( 'capture', 'capture', (int) ( $capture['id'] ?? 0 ), array( 'id' => $capture['id'] ?? 0, 'source' => $capture['source'] ?? '' ) );
	}, 20 );
	add_action( 'estandar_suite_signal_recorded', function ( $signal ) {
		Estandar\Core\Destinations::dispatch_event( (string) ( $signal['event_name'] ?? 'signal' ), (string) ( $signal['entity_type'] ?? 'lead' ), (int) ( $signal['entity_id'] ?? 0 ), $signal );
	}, 20 );

	// Canonical form-entry event → per-form integrations (async queue).
	add_action( 'estandar_suite_form_entry_created', array( 'Estandar\\Core\\Integrations', 'handle_form_entry' ), 20, 3 );

	// Journey transition → Ads Intelligence (send conversions).
	add_action( 'estandar_suite_journey_transition', array( 'Estandar\\Core\\AdsIntelligence', 'on_journey_event' ), 10, 4 );

	// Seed default Journey templates on first load.
	if ( ! get_option( 'estandar_suite_journey_templates_seeded' ) ) {
		Estandar\Core\JourneyTemplates::seed_defaults();
		update_option( 'estandar_suite_journey_templates_seeded', '1', false );
	}
}, 5 );

// Adoption probe default: originals present when their tables exist.
add_filter( 'estandar_suite_adoption_probe', array( 'Estandar\\Core\\AdoptionProbe', 'probe_legacy' ), 5 );
