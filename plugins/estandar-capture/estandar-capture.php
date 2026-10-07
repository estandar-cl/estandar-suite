<?php
/**
 * Plugin Name: Estandar Suite — Capture
 * Description: Captación omnicanal de Estandar Suite v2: ingesta normalizada,
 * atribución (click IDs + UTM), webhooks de ingreso y adopción de leads
 * históricos de los plugins originales (lectura read-only, first-writer-wins).
 * Producto aislado; NO reemplaza ni modifica los plugins originales.
 * Version: 3.4.6
 * Requires at least: 6.0
 * Tested up to: 7.0
 * Requires PHP: 8.0
 * Text Domain: estandar-suite-capture
 * Update URI: https://updates.estandar.cl/staging/v2/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
	return;
}

define( 'ESTANDAR_SUITE_CAPTURE_FILE', __FILE__ );
define( 'ESTANDAR_SUITE_CAPTURE_VERSION', '3.4.6');
define( 'ESTANDAR_SUITE_CAPTURE_PATH', plugin_dir_path( __FILE__ ) );

require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/Ingestion.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/Attribution.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/Fields.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/Spam.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/Webhook.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/Actions.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/SurveyDb.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/SurveyHandler.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/SurveyRenderer.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/SurveyAnalytics.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/IncidentDb.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/ImageOptimizer.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/Repeater.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/IncidentEngine.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/IncidentRenderer.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/GfImporter.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/GfPostventaBridge.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/GfNativeBridge.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/PostventaPanel.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/Forms.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/PixelManager.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/ConversationEngine.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/AiChat.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/AiChatPrompt.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/ChatConversation.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/ChatLog.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/ChatbotHealth.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/ChatbotFrontend.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/FormBridge.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/MetaLeadAds.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/Popups.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/PopupsFrontend.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/Adoption.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/FormsAdoption.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/ChatbotBridge.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/LeadsBridge.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/ChatbotAdoption.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/FormRenderer.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/OperatorGate.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/CamaraRegistros.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/LegacyFormShortcode.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/Rest.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/Cli.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/Admin.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/WhatsAppBot.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/WhatsAppAttribution.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/FloatingWhatsApp.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/Landing.php';
require_once ESTANDAR_SUITE_CAPTURE_PATH . 'includes/WhatsAppClickTracking.php';

register_activation_hook( __FILE__, array( 'Estandar\\Capture\\Ingestion', 'activate_schema' ) );
register_activation_hook( __FILE__, array( 'Estandar\\Capture\\Forms', 'activate_schema' ) );
register_activation_hook( __FILE__, array( 'Estandar\\Capture\\Popups', 'activate_schema' ) );
	register_activation_hook( __FILE__, array( 'Estandar\\Capture\\SurveyDb', 'activate_schema' ) );
	register_activation_hook( __FILE__, array( 'Estandar\\Capture\\IncidentDb', 'activate_schema' ) );
register_activation_hook( __FILE__, array( 'Estandar\\Capture\\ChatConversation', 'activate_schema' ) );
register_activation_hook( __FILE__, array( 'Estandar\\Capture\\ChatLog', 'activate_schema' ) );
register_activation_hook( __FILE__, array( 'Estandar\\Capture\\ChatbotAdoption', 'activate_mirror_schema' ) );
register_activation_hook( __FILE__, array( 'Estandar\\Capture\\WhatsAppClickTracking', 'activate_schema' ) );

add_action( 'plugins_loaded', function () {
	Estandar\Capture\Ingestion::maybe_migrate_schema();
	Estandar\Capture\Forms::maybe_migrate_schema();
	Estandar\Capture\Forms::maybe_migrate_notify_bodies();
	Estandar\Capture\Forms::maybe_migrate_project_ownership();
	Estandar\Capture\Popups::maybe_migrate_schema();
	Estandar\Capture\Popups::init();
	Estandar\Capture\PopupsFrontend::init();
	Estandar\Capture\SurveyDb::maybe_migrate_schema();
	Estandar\Capture\IncidentDb::maybe_migrate_schema();
	Estandar\Capture\ChatConversation::maybe_migrate_schema();
	Estandar\Capture\ChatLog::maybe_migrate_schema();
	Estandar\Capture\ChatbotAdoption::maybe_migrate_mirror_schema();
	Estandar\Capture\WhatsAppClickTracking::maybe_migrate_schema();
	// Absorb legacy ad credentials (Meta/Google) once, without overwriting anything.
	if ( get_option( Estandar\Capture\Admin::LEADS_ADOPT_FLAG, '' ) !== '1' ) {
		Estandar\Capture\Admin::adopt_leads_credentials();
	}
	// MAIL_IDENTITY_CANONICAL_V1: absorb legacy email identity (branding) once.
	if ( get_option( Estandar\Capture\Admin::LEADS_EMAIL_ADOPT_FLAG, '' ) !== '1' ) {
		Estandar\Capture\Admin::adopt_leads_email_identity();
	}
	Estandar\Capture\Rest::init();
	Estandar\Capture\Cli::init();
	Estandar\Capture\Admin::init();
	Estandar\Capture\Forms::init();
	Estandar\Capture\FormBridge::init();
	Estandar\Capture\MetaLeadAds::init();
	Estandar\Capture\ChatbotBridge::init();
	Estandar\Capture\LeadsBridge::init();
	Estandar\Capture\ChatbotFrontend::init();
	Estandar\Capture\Fields::register();
	Estandar\Capture\SurveyHandler::init();
	Estandar\Capture\FormRenderer::init();
	Estandar\Capture\OperatorGate::init();
	Estandar\Capture\CamaraRegistros::init();
	Estandar\Capture\PixelManager::init();
	Estandar\Capture\LegacyFormShortcode::init();
	Estandar\Capture\WhatsAppAttribution::init();
	Estandar\Capture\FloatingWhatsApp::init();
	Estandar\Capture\WhatsAppClickTracking::init();
	Estandar\Capture\Landing::init();
	Estandar\Capture\ConversationEngine::register();
	Estandar\Capture\GfPostventaBridge::init();
	Estandar\Capture\GfNativeBridge::init();
	Estandar\Capture\PostventaPanel::init();
}, 6 );

// Flush de rewrite rules una sola vez para /panel-postventa/.
add_action( 'wp_loaded', static function () {
	if ( ! get_option( 'estandar_suite_capture_postventa_rewrite_flushed' ) ) {
		flush_rewrite_rules();
		update_option( 'estandar_suite_capture_postventa_rewrite_flushed', '1', false );
	}
} );
