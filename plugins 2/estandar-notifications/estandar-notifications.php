<?php
/**
 * Plugin Name: Estandar Notifications
 * Plugin URI:  https://estandar.cl
 * Description: Architecture bootstrap for the Estandar notification subsystem.
 * Version: 13.0.173
 * Author:      Estandar Studios CL
 * Author URI:  https://estandar.cl
 * Text Domain: estandar-notifications
 * Requires PHP: 7.4
 * Update URI: https://updates.estandar.cl
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ESTANDAR_NOTIFICATIONS_VERSION', '13.0.173' );
define( 'ESTANDAR_NOTIFICATIONS_PATH', plugin_dir_path( __FILE__ ) );
define( 'ESTANDAR_NOTIFICATIONS_URL', plugin_dir_url( __FILE__ ) );

require_once ESTANDAR_NOTIFICATIONS_PATH . 'includes/interface-estandar-notification.php';
require_once ESTANDAR_NOTIFICATIONS_PATH . 'includes/class-estandar-notifications-registry.php';
require_once ESTANDAR_NOTIFICATIONS_PATH . 'includes/class-estandar-notifications-engine.php';
require_once ESTANDAR_NOTIFICATIONS_PATH . 'includes/class-estandar-notifications-health.php';
require_once ESTANDAR_NOTIFICATIONS_PATH . 'includes/class-estandar-notifications-settings.php';

add_action( 'plugins_loaded', array( 'Estandar_Notifications_Engine', 'init' ), 20 );
add_action( 'admin_menu', array( 'Estandar_Notifications_Settings', 'register_menu' ) );

if ( ! function_exists( 'primer_is_active' ) ) {
	function primer_is_active(): bool {
		return defined( 'PRIMER_VERSION' );
	}
}
