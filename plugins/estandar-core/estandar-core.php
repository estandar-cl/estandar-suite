<?php
/**
 * Plugin Name: Estandar Suite — Core
 * Description: Infraestructura técnica reutilizable de Estandar Suite v2: framework,
 * contratos, telemetría, WP-CLI, REST, salud y adopción de datos legados.
 * Producto aislado (namespaces/tablas/options propios);
 * NO reemplaza ni modifica los plugins originales.
 * Version: 3.2.8
 * Requires at least: 6.0
 * Tested up to: 7.0
 * Requires PHP: 8.0
 * Text Domain: estandar-suite-core
 * Update URI: https://updates.estandar.cl/staging/v2/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
	return;
}

define( 'ESTANDAR_SUITE_CORE_FILE', __FILE__ );
define( 'ESTANDAR_SUITE_CORE_VERSION', '3.2.8');
define( 'ESTANDAR_SUITE_CORE_PATH', plugin_dir_path( __FILE__ ) );
define( 'ESTANDAR_SUITE_CORE_PREFIX', 'estandar_suite_core_' );

require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Contracts.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Crypto.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Embed.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Time.php';
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
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/SiteMap.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Telemetry.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Http.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/FieldMap.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/LeadsPanel.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Leads/AiContext.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Leads/AdminPage.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Leads/OperatorNotifier.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Leads/CrmIngestion.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Leads/MailTracking.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Leads/OperatorActivity.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Leads/ProjectRegistry.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Leads/ProjectCompat.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Leads/Operators.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Leads/LeadsDigest.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Conditions.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Duplicate.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/AdoptionProbe.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/AiKeys.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/AIDiscovery.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Channels.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Optimal.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Health.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/AutoDiag.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/LegacyMap.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Updates.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Rest.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Cli.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Admin.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Tools.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/JourneyTemplates.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/AdsIntelligence.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/AdsDataManager.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Interest/InterestSignals.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Interest/InterestChannel.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Interest/InterestState.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Interest/InterestPriority.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Interest/NextBestAction.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Interest/TimeContext.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Interest/InterestEngine.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Interest/InterestContext.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Interest/EngagementEngine.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Quality/ContactabilityEngine.php';
require_once ESTANDAR_SUITE_CORE_PATH . 'includes/Access.php';
register_activation_hook( __FILE__, array( 'Estandar\\Core\\Schema', 'activate' ) );
register_activation_hook( __FILE__, array( 'Estandar\\Core\\Access', 'activate' ) );
register_activation_hook( __FILE__, array( 'Estandar\\Core\\SiteMap', 'activate_schema' ) );
register_activation_hook( __FILE__, array( 'Estandar\\Core\\SiteMap', 'activate_sessions_schema' ) );

add_action( 'plugins_loaded', function () {
	Estandar\Core\Duplicate::init();
	Estandar\Core\Schema::maybe_migrate();
	Estandar\Core\SiteMap::maybe_migrate_schema();
	Estandar\Core\SiteMap::maybe_migrate_sessions_schema();
	Estandar\Core\SiteMap::init_tracking();
	add_action( 'rest_api_init', array( 'Estandar\\Core\\SiteMap', 'rest_routes' ) );
	Estandar\Core\Rest::init();
	Estandar\Core\Cli::init();
	Estandar\Core\Admin::init();
	// Acceso limitado + auditoría (ACCESS_V1): rol mínimo, gate de pantallas y log.
	Estandar\Core\Access::maybe_migrate();
	Estandar\Core\Access::init();
	// Herramientas de admin (SVG con allowlist DOM + generador de child theme),
	// portadas de estandar-inicio; configurables por sitio.
	Estandar\Core\Tools::init();
	// Hub (Backend Ordenado) ARCHIVADO (S12, 07/09): el agregador "Central" quedó
	// superseded por la navegación nativa de cada producto + la página Inicio
	// (que ya agrega); los módulos "inner" nunca se construyeron. Ya no se carga.
	// Referencia histórica: includes/Hub.php (ARCHIVED, fuera del boot).
	// Página admin de Leads (backend): reutiliza los datos del panel; la
	// vitrina pública /panel-leads/ no se toca.
	Estandar\Core\Leads\AdminPage::init();
	// Notificación al operador v2 (port del notifier legacy con email enriquecido).
	Estandar\Core\Leads\OperatorNotifier::init();
	// Panel de trazabilidad y uso por operador (OPERATOR_ACTIVITY_V1).
	Estandar\Core\Leads\OperatorActivity::init();
	// Ingestión CRM v2 (port de Estandar_Leads_Ingestion): toma el rol de
	// escribir es_leads solo cuando estandar-leads esté ausente (gate).
	Estandar\Core\Leads\CrmIngestion::init();
	// MAIL_TRACKING_V2: rutas de apertura (píxel) y clic (redirección) de la
	// autorespuesta de leads, con registro en es_lead_events.
	Estandar\Core\Leads\MailTracking::init();
	// Resumen periódico de leads por proyecto (LEAD_DIGEST_V1): diario + semanal
	// (lunes) + mensual (día 1). Opt-in por proyecto (Core → Configuración).
	Estandar\Core\Leads\LeadsDigest::init();
	// AI Discovery: motor compartido de descubrimiento para motores IA/SEO.
	Estandar\Core\AIDiscovery::init();
	Estandar\Core\Updates::boot();
	// Telemetry: daily retention sweep.
	Estandar\Core\Telemetry::init();
	// AutoDiag: refresco diario del snapshot de estado honesto.
	Estandar\Core\AutoDiag::init();

	// Correo global: si la Suite tiene SMTP nativo configurado, TODO wp_mail()
	// del sitio (incluidos plugins legacy) pasa por él. Sin plugin SMTP externo.
	Estandar\Core\Communications\Transports\SmtpTransport::register_global_mailer();

	// Legacy compatibility: re-register legacy shortcodes whose owning plugin
	// is deactivated (keeps the front intact during and after migration).
	add_action( 'init', array( 'Estandar\\Core\\LegacyMap', 'init' ), 20 );

	// Journey transition → Ads Intelligence (send conversions).
	add_action( 'estandar_suite_journey_transition', array( 'Estandar\\Core\\AdsIntelligence', 'on_journey_event' ), 10, 4 );

	// Ads Data Manager (OCI vía Data Manager API) — aditivo/opt-in.
	Estandar\Core\AdsDataManager::init();

	// Seed default Journey templates on first load.
	if ( ! get_option( 'estandar_suite_journey_templates_seeded' ) ) {
		Estandar\Core\JourneyTemplates::seed_defaults();
		update_option( 'estandar_suite_journey_templates_seeded', '1', false );
	}

	// Panel de Leads: URL amigable /panel-leads/ (misma protección que el REST).
	add_action( 'init', static function () {
		add_rewrite_rule( '^' . preg_quote( Estandar\Core\LeadsPanel::PANEL_SLUG, '/' ) . '/?$', 'index.php?estandar_suite_leads_panel=1', 'top' );
	}, 5 );
	add_filter( 'query_vars', static function ( array $qv ) {
		$qv[] = 'estandar_suite_leads_panel';
		return $qv;
	} );
	add_action( 'template_redirect', array( 'Estandar\\Core\\LeadsPanel', 'maybe_render_panel_page' ), 5 );
	// Calificación de leads v2 (FEEDBACK_V2): la URL /?estandar_feedback=1&token=…
	// antes la resolvía estandar-leads (retirado); ahora la maneja el panel v2.
	add_action( 'template_redirect', array( 'Estandar\\Core\\LeadsPanel', 'maybe_handle_feedback' ), 4 );
	// One-time flush so the rewrite rule takes effect (wp_loaded = rewrite listo).
	add_action( 'wp_loaded', static function () {
		if ( ! get_option( 'estandar_suite_core_rewrite_flushed' ) ) {
			flush_rewrite_rules();
			update_option( 'estandar_suite_core_rewrite_flushed', '1', false );
		}
	} );
}, 5 );
// Adoption probe default: originals present when their tables exist.
add_filter( 'estandar_suite_adoption_probe', array( 'Estandar\\Core\\AdoptionProbe', 'probe_legacy' ), 5 );
