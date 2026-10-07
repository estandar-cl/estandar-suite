<?php
/**
 * Plugin Name: Estandar Noticias
 * Description: Boletín diario de noticias reales: ingesta RSS/Atom, curación por perfil, publicación y envío por correo. Funciona con o sin Estandar Suite.
 * Version: 0.4.8
 * Requires at least: 6.0
 * Tested up to: 7.0
 * Requires PHP: 8.0
 * Author: Estandar
 * Text Domain: estandar-noticias
 * Update URI: https://updates.estandar.cl/staging/v2/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
	return;
}

define( 'ESTANDAR_NOTICIAS_FILE', __FILE__ );
define( 'ESTANDAR_NOTICIAS_VERSION', '0.4.8' );
define( 'ESTANDAR_NOTICIAS_PATH', plugin_dir_path( __FILE__ ) );
define( 'ESTANDAR_NOTICIAS_URL', plugin_dir_url( __FILE__ ) );

require_once ESTANDAR_NOTICIAS_PATH . 'includes/Settings.php';
require_once ESTANDAR_NOTICIAS_PATH . 'includes/Feeds.php';
require_once ESTANDAR_NOTICIAS_PATH . 'includes/Collect.php';
require_once ESTANDAR_NOTICIAS_PATH . 'includes/Curate.php';
require_once ESTANDAR_NOTICIAS_PATH . 'includes/Render.php';
require_once ESTANDAR_NOTICIAS_PATH . 'includes/Publish.php';
require_once ESTANDAR_NOTICIAS_PATH . 'includes/Ratings.php';
require_once ESTANDAR_NOTICIAS_PATH . 'includes/Mailer.php';
require_once ESTANDAR_NOTICIAS_PATH . 'includes/Pipeline.php';
require_once ESTANDAR_NOTICIAS_PATH . 'includes/Cli.php';
require_once ESTANDAR_NOTICIAS_PATH . 'includes/Rest.php';
require_once ESTANDAR_NOTICIAS_PATH . 'includes/Updater.php';
require_once ESTANDAR_NOTICIAS_PATH . 'includes/Admin.php';
require_once ESTANDAR_NOTICIAS_PATH . 'includes/Plugin.php';

register_activation_hook( __FILE__, array( 'Estandar\\Noticias\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Estandar\\Noticias\\Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Estandar\\Noticias\\Plugin', 'init' ) );
