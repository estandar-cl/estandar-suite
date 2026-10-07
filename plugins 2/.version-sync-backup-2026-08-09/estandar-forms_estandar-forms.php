<?php
/*
Plugin Name: Estandar Forms
Plugin URI: https://www.estandar.cl
Description: Formularios livianos con dashboard, shortcode y captura hacia Estandar Leads.
Version: 13.0.172
Author: Luis Valdés de Estandar Studios CL
Author URI: https://www.estandar.cl
Text Domain: estandar-forms
Update URI: https://updates.estandar.cl
*/

if ( ! defined('ABSPATH') ) {
    exit;
}

add_action( 'plugins_loaded', function () {
    load_plugin_textdomain( 'estandar-forms', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}, 1 );

/* ======================================================
   CONSTANTES
====================================================== */

define('ESTANDAR_FORMS_PATH', plugin_dir_path(__FILE__));
define('ESTANDAR_FORMS_URL',  plugin_dir_url(__FILE__));
define('ESTANDAR_FORMS_VERSION', '13.0.158');

// Bootstrap fallback for primer_is_active().
// Executes only when Primer has not yet loaded (WordPress plugin load order is not
// guaranteed). Skipped entirely once Primer is present — Primer's own definition
// takes ownership.
if ( ! function_exists('primer_is_active') ) {
    function primer_is_active(): bool {
        return defined( 'PRIMER_VERSION' );
    }
}

// Bootstrap fallback for resolve_project_display_name() — definida en Primer,
// llamada sin function_exists() guard en este plugin. Sin este polyfill,
// desactivar Primer produce un fatal en vez de degradar con gracia.
if ( ! function_exists('resolve_project_display_name') ) {
    function resolve_project_display_name( $slug, $fallback_area = '' ): string {
        return '' !== (string) $fallback_area ? (string) $fallback_area : (string) $slug;
    }
}


/* ======================================================
   INCLUDES (ORDENADOS Y SIN DUPLICAR)
====================================================== */

// Helpers globales
require_once ESTANDAR_FORMS_PATH . 'includes/helpers.php';
require_once ESTANDAR_FORMS_PATH . 'includes/helpers-files.php';
require_once ESTANDAR_FORMS_PATH . 'includes/class-qualification-profiles.php';
require_once ESTANDAR_FORMS_PATH . 'includes/class-qualification-engine.php';

// Core
require_once ESTANDAR_FORMS_PATH . 'includes/class-db.php';
require_once ESTANDAR_FORMS_PATH . 'includes/class-forms.php';
require_once ESTANDAR_FORMS_PATH . 'includes/class-shortcode.php';
require_once ESTANDAR_FORMS_PATH . 'includes/class-webhook.php';
require_once ESTANDAR_FORMS_PATH . 'includes/class-experience-engine.php';
Estandar_Forms_Webhook::init();

// Forms 4.0 — Dependent Select (project_select + dependent_select field types)
require_once ESTANDAR_FORMS_PATH . 'includes/class-dependent-select.php';
Estandar_Forms_Dependent_Select::init();

// Forms 4.0 — Repeater contract layer (incidents[] + incident_files[])
require_once ESTANDAR_FORMS_PATH . 'includes/class-repeater.php';

// Forms 4.0 — Incident Engine persistence layer
require_once ESTANDAR_FORMS_PATH . 'includes/class-incident-engine.php';

// Forms 4.0 — Action Engine (distribution + logs)
require_once ESTANDAR_FORMS_PATH . 'includes/class-action-engine.php';
require_once ESTANDAR_FORMS_PATH . 'includes/class-action-database.php';
require_once ESTANDAR_FORMS_PATH . 'includes/class-action-email.php';
require_once ESTANDAR_FORMS_PATH . 'includes/class-action-webhook.php';

// Forms 4.1 — Template System (export / import / duplicate)
require_once ESTANDAR_FORMS_PATH . 'includes/class-template.php';
add_action( 'admin_post_estandar_forms_export',           [ 'Estandar_Forms_Template', 'handle_export' ] );
add_action( 'admin_post_estandar_forms_import',           [ 'Estandar_Forms_Template', 'handle_import' ] );
add_action( 'admin_post_estandar_forms_import_preflight', [ 'Estandar_Forms_Template', 'handle_import_preflight' ] );
add_action( 'admin_post_estandar_forms_import_confirmed', [ 'Estandar_Forms_Template', 'handle_import_confirmed' ] );
add_action( 'admin_post_estandar_forms_import_resolve',   [ 'Estandar_Forms_Template', 'handle_import_resolve' ] );
add_action( 'admin_post_estandar_forms_duplicate',        [ 'Estandar_Forms_Template', 'handle_duplicate' ] );

// JSON_LIFECYCLE_V1 — editor JSON canónico (todas las rutas pasan por Estandar_Forms_Template)
add_action( 'wp_ajax_estandar_forms_json_export',   [ 'Estandar_Forms_Template', 'handle_ajax_export' ] );
add_action( 'wp_ajax_estandar_forms_json_validate', [ 'Estandar_Forms_Template', 'handle_ajax_validate' ] );
add_action( 'wp_ajax_estandar_forms_json_diff',     [ 'Estandar_Forms_Template', 'handle_ajax_diff' ] );
add_action( 'wp_ajax_estandar_forms_json_apply',    [ 'Estandar_Forms_Template', 'handle_ajax_apply' ] );
add_action( 'wp_ajax_estandar_forms_json_history',  [ 'Estandar_Forms_Template', 'handle_ajax_history' ] );

// GF_IMPORT_V1 — Gravity Forms import adapter
require_once ESTANDAR_FORMS_PATH . 'includes/class-gf-importer.php';

// SURVEYS_V1 — Isolated survey engine (new tables, separate analytics, no lead pipeline)
require_once ESTANDAR_FORMS_PATH . 'includes/class-survey-db.php';
require_once ESTANDAR_FORMS_PATH . 'includes/class-survey-handler.php';
require_once ESTANDAR_FORMS_PATH . 'includes/class-survey-analytics.php';
Estandar_Survey_Handler::init();

// CONVERSATION_ENGINE_V1 (ADR 0034) — contrato del motor conversacional. MS-2:
// solo contrato (eventos, taxonomía, normalize_config); runtime en MS-3/MS-4.
require_once ESTANDAR_FORMS_PATH . 'includes/class-conversation-engine.php';

if ( is_admin() ) {
    require_once ESTANDAR_FORMS_PATH . 'admin/screens/survey-list.php';
    require_once ESTANDAR_FORMS_PATH . 'admin/screens/survey-edit.php';
    require_once ESTANDAR_FORMS_PATH . 'admin/screens/survey-results.php';
    require_once ESTANDAR_FORMS_PATH . 'admin/screens/survey-analytics.php';
}

if ( is_admin() ) {
    require_once ESTANDAR_FORMS_PATH . 'admin/dashboard.php';
    require_once ESTANDAR_FORMS_PATH . 'admin/screens/gf-import.php';
}

add_action('admin_init', function () {
    Estandar_Survey_DB::maybe_install();
});

/* ======================================================
   ACTIVACIÓN
====================================================== */
register_activation_hook(__FILE__, ['Estandar_Forms_DB', 'bootstrap']);

/* ======================================================
   MIGRACIÓN SEGURA
   admin_init: evita correr dbDelta en requests de frontend.
====================================================== */
add_action('admin_init', function () {
    $installed = (string) get_option(Estandar_Forms_DB::DB_OPTION, '');
    if ( version_compare($installed, Estandar_Forms_DB::DB_VERSION, '<') || Estandar_Forms_DB::needs_schema_repair() ) {
        Estandar_Forms_DB::bootstrap();
    }
});

/* ======================================================
   ADMIN MENU
====================================================== */
if ( is_admin() ) {
    add_action('admin_menu', function () {

        $core_active = function_exists( 'primer_is_active' ) && primer_is_active();
        if ( $core_active && function_exists( 'estandar_register_navigation_page' ) ) {
            $forms_nav = function_exists( 'estandar_forms_nav_items' ) ? 'estandar_forms_nav_items' : null;
            estandar_register_navigation_page( [ 'slug' => 'estandar-forms', 'capability' => 'manage_options', 'callback' => 'estandar_forms_home_page', 'navigation' => $forms_nav ? $forms_nav( 'estandar-forms' ) : [] ] );
            estandar_register_navigation_page( [ 'slug' => 'estandar-forms-forms', 'capability' => 'manage_options', 'callback' => 'estandar_forms_forms_page', 'navigation' => $forms_nav ? $forms_nav( 'estandar-forms' ) : [] ] );
            estandar_register_navigation_page( [ 'slug' => 'estandar-forms-entries', 'capability' => 'manage_options', 'callback' => 'estandar_forms_entries_page', 'navigation' => $forms_nav ? $forms_nav( 'estandar-forms-entries' ) : [] ] );
            estandar_register_navigation_page( [ 'slug' => 'estandar-surveys', 'capability' => 'manage_options', 'callback' => 'estandar_surveys_list_page', 'navigation' => $forms_nav ? $forms_nav( 'estandar-surveys' ) : [] ] );
            estandar_register_navigation_page( [ 'slug' => 'estandar-surveys-edit', 'capability' => 'manage_options', 'callback' => 'estandar_surveys_edit_page', 'navigation' => $forms_nav ? $forms_nav( 'estandar-surveys' ) : [] ] );
            estandar_register_navigation_page( [ 'slug' => 'estandar-surveys-results', 'capability' => 'manage_options', 'callback' => 'estandar_surveys_results_page', 'navigation' => $forms_nav ? $forms_nav( 'estandar-surveys' ) : [] ] );
            estandar_register_navigation_page( [ 'slug' => 'estandar-surveys-analytics', 'capability' => 'manage_options', 'callback' => 'estandar_surveys_analytics_page', 'navigation' => $forms_nav ? $forms_nav( 'estandar-surveys' ) : [] ] );
            // GF_IMPORT_V1 — hidden sub-page under Forms
            estandar_register_navigation_page( [ 'slug' => 'estandar-gf-import', 'capability' => 'manage_options', 'callback' => 'estandar_forms_gf_import_page', 'navigation' => $forms_nav ? $forms_nav( 'estandar-forms' ) : [] ] );
            return;
        }

        // Fallback (sin Core): menú propio del plugin (mantiene slugs/callbacks/URLs intactas).
        add_menu_page(
            'Estandar Forms',
            'Estandar Forms',
            'manage_options',
            'estandar-forms',
            'estandar_forms_home_page',
            'dashicons-feedback',
            26
        );

        add_submenu_page(
            'estandar-forms',
                'Formularios',
                'Formularios',
            'manage_options',
            'estandar-forms',
            'estandar_forms_home_page'
        );

        add_submenu_page(
            'estandar-forms',
            'Formularios',
            'Formularios',
            'manage_options',
            'estandar-forms-forms',
            'estandar_forms_forms_page'
        );

        add_submenu_page(
            'estandar-forms',
            'Entradas',
            'Entradas',
            'manage_options',
            'estandar-forms-entries',
            'estandar_forms_entries_page'
        );

        // Surveys V1 — visible submenu in standalone mode
        add_submenu_page(
            'estandar-forms',
            'Surveys',
            'Surveys',
            'manage_options',
            'estandar-surveys',
            'estandar_surveys_list_page'
        );
        add_submenu_page(
            null,
            'Nueva Encuesta',
            'Nueva Encuesta',
            'manage_options',
            'estandar-surveys-edit',
            'estandar_surveys_edit_page'
        );
        add_submenu_page(
            null,
            'Resultados Survey',
            'Resultados Survey',
            'manage_options',
            'estandar-surveys-results',
            'estandar_surveys_results_page'
        );
        add_submenu_page(
            null,
            'Analytics Survey',
            'Analytics Survey',
            'manage_options',
            'estandar-surveys-analytics',
            'estandar_surveys_analytics_page'
        );

    });
}
