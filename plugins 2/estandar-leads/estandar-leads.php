<?php
/**
 * Plugin Name: Estandar Leads
 * Description: Ciclo minimo de verdad comercial para calidad de leads.
 * Version: 13.0.173
 * Requires PHP: 7.4
 * Author: Luis Valdés de Estandar Studios CL
 * Text Domain: estandar-leads
 * Update URI: https://updates.estandar.cl
 */

if (!defined('ABSPATH')) {
    exit;
}

if (version_compare(PHP_VERSION, '7.4', '<')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>Estandar Leads requiere PHP 7.4 o superior.</p></div>';
    });
    return;
}

define("ESTANDAR_LEADS_VERSION", "13.0.173");
define('ESTANDAR_LEADS_FILE', __FILE__);
define('ESTANDAR_LEADS_PATH', plugin_dir_path(__FILE__));

// Bootstrap fallback for primer_is_active().
// Executes only when Primer has not yet loaded (WordPress plugin load order is not
// guaranteed). Skipped entirely once Primer is present — Primer's own definition
// takes ownership.
if (!function_exists('primer_is_active')) {
    function primer_is_active(): bool
    {
        return defined('PRIMER_VERSION');
    }
}

// Bootstrap fallback for resolve_project_display_name() — definida en Primer,
// llamada sin function_exists() guard en ~35 sitios de este plugin. Sin este
// polyfill, desactivar Primer produce un fatal (Call to undefined function)
// en vez de degradar con gracia (canon CORE_OPTIONALITY).
if (!function_exists('resolve_project_display_name')) {
    function resolve_project_display_name($slug, $fallback_area = ''): string
    {
        return '' !== (string) $fallback_area ? (string) $fallback_area : (string) $slug;
    }
}


require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-schema.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-normalizer.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-fit.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-fit-indicator.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-projects.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-payload-validator.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-attribution.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-qa-policy.php';
require_once ESTANDAR_LEADS_PATH . 'includes/lead-attribution-audit.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-repository.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-commercial-journey.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-email.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-journey-builder.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-portal.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-operator-workspace.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-operator-resolver.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-commercial-qualification-rules.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-commercial-qualification-engine.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-assessment-engine.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-assessment-ai.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-assessment-accuracy.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-ingestion.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-webhook-dispatcher.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-tokens.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-feedback.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-notifier.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-operator-hours.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-compliance.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-commercial-profile.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-intent-score.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-interest-adapter.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-contactability.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-interest-listener.php';
// [RETIRADO 2026-09-17] Estandar_Leads_Scoring → scorer canónico es Brain (LEAD_SCORE_CANONICAL_V1). Ver _retired/README.md.
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-recovery.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-sales-activation.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-commercial.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-transfer-service.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-whatsapp-redirect.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-whatsapp-alias.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-autoresponse.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-link-redirect.php';

require_once ESTANDAR_LEADS_PATH . 'includes/commercial-outcomes/class-estandar-commercial-money.php';
require_once ESTANDAR_LEADS_PATH . 'includes/commercial-outcomes/class-estandar-commercial-stage.php';
require_once ESTANDAR_LEADS_PATH . 'includes/commercial-outcomes/class-estandar-commercial-outcome.php';
require_once ESTANDAR_LEADS_PATH . 'includes/commercial-outcomes/class-estandar-commercial-value-provider.php';
require_once ESTANDAR_LEADS_PATH . 'includes/commercial-outcomes/class-estandar-commercial-outcomes-value-provider.php';
require_once ESTANDAR_LEADS_PATH . 'includes/commercial-outcomes/class-estandar-commercial-stages.php';
require_once ESTANDAR_LEADS_PATH . 'includes/commercial-outcomes/class-estandar-commercial-outcomes-log.php';
require_once ESTANDAR_LEADS_PATH . 'includes/commercial-outcomes/class-estandar-commercial-outcomes.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-meta-adapter.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-meta-payload-builder.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-meta-graph-client.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-meta-pixel.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-google-adapter.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-google-payload-builder.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-google-dm-client.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-advertising-event.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-conversion-backfill-service.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-customers.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-customer-repository.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-customer-matching.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-customer-service.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-customer-backfill.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-customers-admin.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-operators-admin.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-ads-feedback-observability.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-ai-context.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-seo-intelligence.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-seo-analytics.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-rest.php';
require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-admin.php';

register_activation_hook(__FILE__, array('Estandar_Leads_Schema', 'activate'));
register_activation_hook(__FILE__, array('Estandar_Leads_REST', 'register_capabilities'));

add_action('plugins_loaded', function () {
    Estandar_Leads_Schema::maybe_migrate();
    Estandar_Leads_Commercial::backfill_operator_uuids();
    Estandar_Leads_Whatsapp_Redirect::init();
    Estandar_Leads_Commercial::maybe_register_campaign_override();
    Estandar_Leads_Whatsapp_Alias::init();
    Estandar_Leads_Link_Redirect::init();
    Estandar_Leads_Operator_Workspace::init();
    Estandar_Leads_Ingestion::init();
    Estandar_Leads_Commercial_Journey::init();
    Estandar_Commercial_Qualification_Engine::init();
    Estandar_Leads_Assessment_Engine::init();
    Estandar_Leads_Assessment_AI::init();
    Estandar_Leads_Assessment_Accuracy::init();
    add_action('estandar_experience_completed', array('Estandar_Leads_Repository', 'persist_experience'), 10, 6);
    Estandar_Leads_Webhook_Dispatcher::init();
    Estandar_Leads_Feedback::init();
    Estandar_Leads_Notifier::init();
    Estandar_Leads_Autoresponse::init();
    Estandar_Leads_Interest_Listener::init();
    Estandar_Leads_Compliance::init();
    Estandar_Leads_Recovery::init();
    Estandar_Leads_Sales_Activation::init();

    Estandar_Commercial_Outcomes::init();
    Estandar_Leads_Meta_Adapter::init();
    Estandar_Leads_Meta_Pixel::init();
    // Google Ads (OCI_FOUNDATION_V1) — canonical Google ads-feedback runtime.
    // Complete mirror of the Meta adapter (durable es_google_queue, retries,
    // UNIQUE(lead_id,export_status) idempotency, immediate+cron dispatch).
    // Inert until configured: enabled DEFAULT OFF, dry_run DEFAULT ON.
    Estandar_Leads_Google_Adapter::init();
    // AdvertisingEvent emitter (Leads↔Signal decoupling, Phase 1): Leads decides
    // exportability and emits a normalized, domain-free event for Signal Core.
    Estandar_Leads_Advertising_Event::init();

    // Integration Registry (core opcional): registrar Meta como proveedor.
    // El require vive DENTRO del hook — el archivo extiende una clase del core
    // y solo puede cargarse cuando el registry existe. Sin core: no-op, sin fatal.
    add_action( 'estandar_integrations_register', function () {
        if ( ! class_exists( 'Estandar_Integration_Registry' ) ) {
            return;
        }
        require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-meta-integration-provider.php';
        if ( class_exists( 'Estandar_Leads_Meta_Integration_Provider' ) ) {
            Estandar_Integration_Registry::register( new Estandar_Leads_Meta_Integration_Provider() );
        }
        require_once ESTANDAR_LEADS_PATH . 'includes/class-estandar-leads-google-integration-provider.php';
        if ( class_exists( 'Estandar_Leads_Google_Integration_Provider' ) ) {
            Estandar_Integration_Registry::register( new Estandar_Leads_Google_Integration_Provider() );
        }
    } );
    Estandar_Leads_REST::init();
    Estandar_Leads_Admin::init();
    Estandar_Leads_Customers_Admin::init();

    // Mark feedback_status = 'received' when an operator completes feedback.
    add_action( 'estandar_lead_status_changed', array( 'Estandar_Leads_Compliance', 'on_lead_status_changed' ), 10, 3 );
});

add_action('admin_init', function () {
    if (!current_user_can('manage_options')) {
        return;
    }

    if (!isset($_GET['estandar_test_lead'])) {
        return;
    }

    do_action('estandar_lead_captured', [
        'schema_version' => '1.0',
        'source_plugin' => 'manual-test',
        'submitted_at' => gmdate('Y-m-d H:i:s'),
        'contact' => [
            'name' => 'Juan Perez',
            'email' => 'juan@example.com',
            'phone' => '+56912345678',
        ],
        'content' => [
            'message' => 'Quiero cotizar',
            'source_form_id' => 'demo-form',
        ],
        'attribution' => [
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'demo-campaign',
            'utm_term' => 'casas ejemplo',
        ],
        'request' => [
            'ip_address' => '127.0.0.1',
            'user_agent' => 'manual-test',
        ],
        'spam' => [
            'honeypot_filled' => false,
            'seconds_to_submit' => 9,
        ],
    ]);

    wp_die('Lead de prueba creado.');
});
