<?php
/**
 * Plugin Name: Estandar Quotes
 * Description: Bounded context for auditable commercial quotations.
 * Version: 13.0.173
 * Requires PHP: 7.4
 * Text Domain: estandar-quotes
 * Update URI: https://updates.estandar.cl
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( version_compare( PHP_VERSION, '7.4', '<' ) ) { return; }
define( 'ESTANDAR_QUOTES_VERSION', '13.0.173' );
define( 'ESTANDAR_QUOTES_SCHEMA_VERSION', '0.1.7' );
define( 'ESTANDAR_QUOTES_FILE', __FILE__ );
define( 'ESTANDAR_QUOTES_PATH', plugin_dir_path( __FILE__ ) );
require_once ESTANDAR_QUOTES_PATH . 'includes/class-estandar-quotes-schema.php';
require_once ESTANDAR_QUOTES_PATH . 'includes/class-estandar-quotes-state-machine.php';
require_once ESTANDAR_QUOTES_PATH . 'includes/class-estandar-quotes-profiles.php';
require_once ESTANDAR_QUOTES_PATH . 'includes/class-estandar-quotes-real-estate-profile.php';
require_once ESTANDAR_QUOTES_PATH . 'includes/class-estandar-quotes-acacios-profile.php';
require_once ESTANDAR_QUOTES_PATH . 'includes/class-estandar-quotes-canonical-json.php';
require_once ESTANDAR_QUOTES_PATH . 'includes/class-estandar-quotes-calculator.php';
require_once ESTANDAR_QUOTES_PATH . 'includes/class-estandar-quotes-view-model.php';
require_once ESTANDAR_QUOTES_PATH . 'includes/class-estandar-quotes-document-definitions.php';
require_once ESTANDAR_QUOTES_PATH . 'includes/class-estandar-quotes-document-engine.php';
require_once ESTANDAR_QUOTES_PATH . 'includes/class-estandar-quotes-preview-service.php';
require_once ESTANDAR_QUOTES_PATH . 'includes/class-estandar-quotes-preview-admin.php';
require_once ESTANDAR_QUOTES_PATH . 'includes/interface-estandar-quotes-pdf-adapter.php';
require_once ESTANDAR_QUOTES_PATH . 'includes/class-estandar-quotes-pdf-dompdf-adapter.php';
require_once ESTANDAR_QUOTES_PATH . 'includes/class-estandar-quotes-pdf-fake-adapter.php';
require_once ESTANDAR_QUOTES_PATH . 'includes/class-estandar-quotes-artifacts.php';
require_once ESTANDAR_QUOTES_PATH . 'includes/interface-estandar-quotes-mail-transport.php';
require_once ESTANDAR_QUOTES_PATH . 'includes/class-estandar-quotes-delivery-email.php';
require_once ESTANDAR_QUOTES_PATH . 'includes/class-estandar-quotes-fake-mail-transport.php';
require_once ESTANDAR_QUOTES_PATH . 'includes/class-estandar-quotes-wp-mail-transport.php';
require_once ESTANDAR_QUOTES_PATH . 'includes/class-estandar-quotes-delivery-service.php';
require_once ESTANDAR_QUOTES_PATH . 'includes/class-estandar-quotes-repository.php';
require_once ESTANDAR_QUOTES_PATH . 'includes/class-estandar-quotes-lead-integration.php';
require_once ESTANDAR_QUOTES_PATH . 'includes/class-estandar-quotes-document-form.php';
require_once ESTANDAR_QUOTES_PATH . 'includes/class-estandar-quotes-operator-session.php';
require_once ESTANDAR_QUOTES_PATH . 'includes/interface-estandar-quotes-hydration-stage.php';
require_once ESTANDAR_QUOTES_PATH . 'includes/class-estandar-quotes-quote-draft.php';
require_once ESTANDAR_QUOTES_PATH . 'includes/class-estandar-quotes-hydration-pipeline.php';
require_once ESTANDAR_QUOTES_PATH . 'includes/class-estandar-quotes-lead-stage.php';
require_once ESTANDAR_QUOTES_PATH . 'includes/class-estandar-quotes-context-stage.php';
require_once ESTANDAR_QUOTES_PATH . 'includes/class-estandar-quotes-field-transformer.php';
require_once ESTANDAR_QUOTES_PATH . 'includes/class-estandar-quotes-field-mapping-registry.php';
require_once ESTANDAR_QUOTES_PATH . 'includes/class-estandar-quotes-form-stage.php';
require_once ESTANDAR_QUOTES_PATH . 'includes/class-estandar-quotes-settings-stage.php';
require_once ESTANDAR_QUOTES_PATH . 'includes/class-estandar-quotes-unit-stage.php';
require_once ESTANDAR_QUOTES_PATH . 'includes/class-estandar-quotes-finance-stage.php';
require_once ESTANDAR_QUOTES_PATH . 'includes/class-estandar-quotes-validation-stage.php';
require_once ESTANDAR_QUOTES_PATH . 'includes/class-estandar-quotes-snapshot-stage.php';
require_once ESTANDAR_QUOTES_PATH . 'includes/class-estandar-quotes-admin-actions.php';
require_once ESTANDAR_QUOTES_PATH . 'includes/class-estandar-quotes-admin.php';
require_once ESTANDAR_QUOTES_PATH . 'includes/class-estandar-quotes-operator-gateway.php';
register_activation_hook( __FILE__, array( 'Estandar_Quotes_Schema', 'activate' ) );
register_activation_hook( __FILE__, array( 'Estandar_Quotes_Preview_Admin', 'activate_capability' ) );
register_activation_hook( __FILE__, array( 'Estandar_Quotes_Admin', 'activate_capabilities' ) );
add_action( 'plugins_loaded', static function (): void {
	Estandar_Quotes_Schema::maybe_migrate();
	Estandar_Quotes_Real_Estate_Profile::init();
	$client_slug = function_exists( 'estandar_core_get_client_slug' ) ? estandar_core_get_client_slug() : '';
	if ( $client_slug === 'acacios' ) {
		Estandar_Quotes_Acacios_Profile::init();
	}
	Estandar_Quotes_Lead_Integration::init();
	Estandar_Quotes_Admin_Actions::init();
	Estandar_Quotes_Admin::init();
	Estandar_Quotes_Preview_Admin::init();
	Estandar_Quotes_Operator_Gateway::init();
	$default_mappings = Estandar_Quotes_Field_Mapping_Registry::defaults();
	Estandar_Quotes_Field_Mapping_Registry::register( 'real_estate@1', $default_mappings );
	Estandar_Quotes_Field_Mapping_Registry::register( 'acacios_real_estate@1', $default_mappings );

	Estandar_Quotes_Hydration_Pipeline::register( new Estandar_Quotes_Lead_Stage() );
	Estandar_Quotes_Hydration_Pipeline::register( new Estandar_Quotes_Context_Stage() );
	Estandar_Quotes_Hydration_Pipeline::register( new Estandar_Quotes_Form_Stage() );
	Estandar_Quotes_Hydration_Pipeline::register( new Estandar_Quotes_Unit_Stage() );
	Estandar_Quotes_Hydration_Pipeline::register( new Estandar_Quotes_Settings_Stage() );
	Estandar_Quotes_Hydration_Pipeline::register( new Estandar_Quotes_Finance_Stage() );
	Estandar_Quotes_Hydration_Pipeline::register( new Estandar_Quotes_Validation_Stage() );
	Estandar_Quotes_Hydration_Pipeline::register( new Estandar_Quotes_Snapshot_Stage() );

	do_action( 'estandar_quotes_hydrator_stages' );

	do_action( 'estandar_module_registered', array(
		'id' => 'estandar_quotes', 'name' => 'Estandar Quotes', 'slug' => 'estandar-quotes',
		'version' => ESTANDAR_QUOTES_VERSION, 'type' => 'commercial', 'capability' => 'manage_options',
		'standalone' => false, 'core_optional' => true,
		'supports' => array( 'quote_drafts', 'immutable_revisions', 'lead_snapshot', 'profile_contracts' ),
	) );
} );


