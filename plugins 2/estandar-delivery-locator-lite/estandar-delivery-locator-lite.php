<?php
/**
 * Plugin Name:       ESTANDAR Delivery Locator Lite
 * Plugin URI:        https://www.estandar.cl
 * Description:       Localizador de entregas ligero: mapa Leaflet, zona de despacho y contacto WhatsApp/correo.
 * Version: 13.0.173
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Luis Valdés de Estandar Studios CL
 * Author URI:        https://www.estandar.cl
 * License:           GPL v2 or later
 * Text Domain:       estandar-delivery-locator-lite
 * Update URI:        https://updates.estandar.cl
 *
 * @package Estandar_Delivery_Locator_Lite
 */

defined( 'ABSPATH' ) || exit;

define( 'ESTANDAR_DL_VERSION', '13.0.173' );
define( 'ESTANDAR_DL_PLUGIN_FILE', __FILE__ );
define( 'ESTANDAR_DL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ESTANDAR_DL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ESTANDAR_DL_BASENAME', plugin_basename( __FILE__ ) );

require_once ESTANDAR_DL_PLUGIN_DIR . 'includes/class-database.php';
require_once ESTANDAR_DL_PLUGIN_DIR . 'includes/class-settings.php';
require_once ESTANDAR_DL_PLUGIN_DIR . 'includes/class-n8n.php';
require_once ESTANDAR_DL_PLUGIN_DIR . 'includes/class-admin-orders.php';
require_once ESTANDAR_DL_PLUGIN_DIR . 'includes/class-ajax.php';
require_once ESTANDAR_DL_PLUGIN_DIR . 'includes/class-shortcode.php';
require_once ESTANDAR_DL_PLUGIN_DIR . 'includes/class-plugin.php';

/**
 * Activación: tabla y opciones por defecto.
 */
function estandar_dl_activate() {
	Estandar_Delivery_Locator_Database::create_table();
	$defaults = array(
		'whatsapp'           => '',
		'email'              => get_option( 'admin_email' ),
		'radius_km'          => 25,
		'center_lat'         => -34.586,
		'center_lng'         => -70.986,
		'n8n_webhook_url'    => '',
		'n8n_webhook_secret' => '',
		'n8n_notify_confirm' => 1,
		'n8n_notify_email'   => 0,
	);
	$existing = get_option( 'estandar_dl_settings', array() );
	if ( ! is_array( $existing ) ) {
		$existing = array();
	}
	update_option( 'estandar_dl_settings', array_merge( $defaults, $existing ) );
	update_option( 'estandar_dl_version', ESTANDAR_DL_VERSION );
}
register_activation_hook( __FILE__, 'estandar_dl_activate' );

/**
 * Migración ligera (dbDelta) al actualizar versión del plugin.
 */
function estandar_dl_upgrade_check() {
	$saved = get_option( 'estandar_dl_version', '' );
	if ( version_compare( (string) $saved, ESTANDAR_DL_VERSION, '>=' ) ) {
		return;
	}
	Estandar_Delivery_Locator_Database::create_table();
	update_option( 'estandar_dl_version', ESTANDAR_DL_VERSION );
}
add_action( 'plugins_loaded', 'estandar_dl_upgrade_check', 5 );

Estandar_Delivery_Locator_Plugin::instance();
