<?php
/**
 * Plugin Name: Estandar Studio
 * Description: Puente "code-in-WP": sirve páginas de alto contenido visual generadas
 *   (HTML/CSS/JS code-first) dentro de WordPress, con features del Suite inyectadas en
 *   los puntos de conversión (formularios, chatbot, panel). Reusa el motor de motion de
 *   Estandar Experience y la captura de Estandar Capture.
 * Version: 0.1.2
 * Requires at least: 6.0
 * Tested up to: 7.1
 * Requires PHP: 8.0
 * Text Domain: estandar-studio
 * Update URI: https://updates.estandar.cl
 *
 * @package Estandar\Studio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
	return;
}

define( 'ESTANDAR_STUDIO_VERSION', '0.1.2' );
define( 'ESTANDAR_STUDIO_FILE', __FILE__ );
define( 'ESTANDAR_STUDIO_PATH', plugin_dir_path( __FILE__ ) );
define( 'ESTANDAR_STUDIO_URL', plugin_dir_url( __FILE__ ) );

require_once ESTANDAR_STUDIO_PATH . 'includes/class-studio-pages.php';
require_once ESTANDAR_STUDIO_PATH . 'includes/class-studio-render.php';
require_once ESTANDAR_STUDIO_PATH . 'includes/class-studio-admin.php';
require_once ESTANDAR_STUDIO_PATH . 'includes/class-studio-cli.php';

use Estandar\Studio\Admin;
use Estandar\Studio\Pages;
use Estandar\Studio\Render;

function estandar_studio_boot(): void {
	Pages::init();
	Render::init();
	if ( is_admin() ) {
		Admin::init();
	}
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		\WP_CLI::add_command( 'estandar-studio', '\Estandar\Studio\Cli' );
	}
	do_action( 'estandar_studio_ready' );
}
add_action( 'plugins_loaded', 'estandar_studio_boot', 20 );
