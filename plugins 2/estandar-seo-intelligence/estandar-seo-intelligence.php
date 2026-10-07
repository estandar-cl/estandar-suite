<?php
/**
 * Plugin Name: Estandar SEO Intelligence
 * Description: Canonical organic-search intelligence for Estandar Suite.
 * Version: 13.0.173
 * Requires PHP: 7.4
 * Author: Estandar Studios CL
 * Update URI: https://updates.estandar.cl
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'ESTANDAR_SEO_INTELLIGENCE_VERSION', '13.0.173' );
define( 'ESTANDAR_SEO_INTELLIGENCE_PATH', plugin_dir_path( __FILE__ ) );

require_once ESTANDAR_SEO_INTELLIGENCE_PATH . 'includes/class-estandar-seo-url.php';
require_once ESTANDAR_SEO_INTELLIGENCE_PATH . 'includes/class-estandar-seo-schema.php';
require_once ESTANDAR_SEO_INTELLIGENCE_PATH . 'includes/class-estandar-seo-repository.php';
require_once ESTANDAR_SEO_INTELLIGENCE_PATH . 'includes/class-estandar-search-console-adapter.php';
require_once ESTANDAR_SEO_INTELLIGENCE_PATH . 'includes/class-estandar-seo-service.php';
require_once ESTANDAR_SEO_INTELLIGENCE_PATH . 'includes/class-estandar-seo-intelligence.php';
require_once ESTANDAR_SEO_INTELLIGENCE_PATH . 'includes/class-estandar-seo-conversion-analytics.php';
require_once ESTANDAR_SEO_INTELLIGENCE_PATH . 'includes/class-estandar-seo-operations.php';

register_activation_hook( __FILE__, array( 'Estandar_SEO_Schema', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Estandar_SEO_Service', 'unschedule' ) );

add_action( 'plugins_loaded', static function (): void {
    Estandar_SEO_Schema::maybe_migrate();
    Estandar_SEO_Service::schedule();

    // Core is optional. The domain, sync and read API do not require its UI.
    add_action( 'estandar_integrations_register', static function (): void {
        if ( ! class_exists( 'Estandar_Integration_Registry' ) ) { return; }
        require_once ESTANDAR_SEO_INTELLIGENCE_PATH . 'includes/class-estandar-search-console-integration-provider.php';
        Estandar_Integration_Registry::register( new Estandar_Search_Console_Integration_Provider() );
    } );
} );

add_action( 'estandar_seo_intelligence_daily_sync', array( 'Estandar_SEO_Service', 'sync_stable_day' ) );
add_action( 'estandar_seo_intelligence_retry', static function (): void { Estandar_SEO_Service::run_scheduled( 'retry' ); } );
Estandar_SEO_Operations::init();
