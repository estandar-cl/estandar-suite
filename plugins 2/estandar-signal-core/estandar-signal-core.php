<?php
/**
 * Plugin Name: Estandar Signal Core
 * Plugin URI: https://www.estandar.cl
 * Description: Canonical audit-only signal ledger for Estandar commercial events.
 * Version: 13.0.173
 * Author: Luis Valdes de Estandar Studios CL
 * Author URI: https://www.estandar.cl
 * Text Domain: estandar-signal-core
 * Requires PHP: 7.4
 * Update URI: https://updates.estandar.cl
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ESTANDAR_SIGNAL_CORE_VERSION', '13.0.173' );
define( 'ESTANDAR_SIGNAL_CORE_SCHEMA_VERSION', '0.2.0' );
define( 'ESTANDAR_SIGNAL_CORE_FILE', __FILE__ );
define( 'ESTANDAR_SIGNAL_CORE_PATH', plugin_dir_path( __FILE__ ) );

// Bootstrap fallback for primer_is_active().
// Executes only when Primer has not yet loaded (WordPress plugin load order is not
// guaranteed). Skipped entirely once Primer is present — Primer's own definition
// takes ownership.
if ( ! function_exists( 'primer_is_active' ) ) {
	function primer_is_active(): bool {
		return defined( 'PRIMER_VERSION' );
	}
}

require_once ESTANDAR_SIGNAL_CORE_PATH . 'includes/interface-estandar-signal-core-exporter.php';
require_once ESTANDAR_SIGNAL_CORE_PATH . 'includes/class-estandar-signal-core-schema.php';
require_once ESTANDAR_SIGNAL_CORE_PATH . 'includes/class-estandar-signal-core-repository.php';
require_once ESTANDAR_SIGNAL_CORE_PATH . 'includes/class-estandar-signal-core-hooks.php';
require_once ESTANDAR_SIGNAL_CORE_PATH . 'includes/class-estandar-signal-core-advertising-listener.php';
require_once ESTANDAR_SIGNAL_CORE_PATH . 'includes/class-estandar-signal-core-scheduler.php';
require_once ESTANDAR_SIGNAL_CORE_PATH . 'includes/class-estandar-signal-core-meta-capi.php';
require_once ESTANDAR_SIGNAL_CORE_PATH . 'includes/class-estandar-signal-core-google-oci.php';
require_once ESTANDAR_SIGNAL_CORE_PATH . 'includes/class-estandar-signal-core-admin.php';

register_activation_hook( __FILE__, array( 'Estandar_Signal_Core_Schema', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Estandar_Signal_Core_Scheduler', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		Estandar_Signal_Core_Schema::maybe_migrate();
		Estandar_Signal_Core_Hooks::init();
		Estandar_Signal_Core_Advertising_Listener::init();
		Estandar_Signal_Core_Scheduler::init();
		Estandar_Signal_Core_Admin::init();

		// ── Register exporters when credentials are configured ───────────────────
		add_filter(
			'estandar_signal_core_exporters',
			static function ( array $exporters ): array {

				// Meta CAPI
				$pixel_id = (string) get_option( 'estandar_signal_core_meta_pixel_id', '' );
				$token    = (string) get_option( 'estandar_signal_core_meta_access_token', '' );
				if ( $pixel_id !== '' && $token !== '' ) {
					$test_code   = (string) get_option( 'estandar_signal_core_meta_test_event_code', '' );
					$exporters[] = new Estandar_Signal_Core_Meta_Capi( $pixel_id, $token, $test_code );
				}

				// Google Ads OCI
				$g_dev_token    = (string) get_option( 'estandar_signal_core_google_developer_token', '' );
				$g_customer_id  = (string) get_option( 'estandar_signal_core_google_customer_id', '' );
				$g_refresh      = (string) get_option( 'estandar_signal_core_google_refresh_token', '' );
				if ( $g_dev_token !== '' && $g_customer_id !== '' && $g_refresh !== '' ) {
					$conv_map = array_filter( array(
						'lead_created'   => (string) get_option( 'estandar_signal_core_google_conv_lead_created', '' ),
						'meeting_booked' => (string) get_option( 'estandar_signal_core_google_conv_meeting_booked', '' ),
						'sale_closed'    => (string) get_option( 'estandar_signal_core_google_conv_sale_closed', '' ),
					) );
					if ( ! empty( $conv_map ) ) {
						$exporters[] = new Estandar_Signal_Core_Google_OCI(
							$g_dev_token,
							(string) get_option( 'estandar_signal_core_google_client_id', '' ),
							(string) get_option( 'estandar_signal_core_google_client_secret', '' ),
							$g_refresh,
							$g_customer_id,
							$conv_map
						);
					}
				}

				return $exporters;
			}
		);

		do_action(
			'estandar_module_registered',
			array(
				'id'            => 'estandar_signal_core',
				'name'          => 'Estandar Signal Core',
				'slug'          => 'estandar-signal-core',
				'version'       => ESTANDAR_SIGNAL_CORE_VERSION,
				'type'          => 'signal',
				'capability'    => 'manage_options',
				'standalone'    => false,
				'core_optional' => true,
				'supports'      => array( 'audit_only', 'signals', 'ledger', 'scheduler', 'meta_capi', 'google_ads_oci' ),
			)
		);
	}
);
