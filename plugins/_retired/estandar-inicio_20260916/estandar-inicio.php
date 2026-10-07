<?php
/**
 * Plugin Name: Estandar Inicio
 * Description: Capacidades base de Estandar (generar child theme, permitir SVG en la biblioteca). Mínimo y extensible: se aloja en Estandar Suite si está presente, y funciona igual si no está.
 * Version: 0.1.0
 * Requires at least: 6.0
 * Tested up to: 7.1
 * Requires PHP: 8.0
 * Author: Estandar
 * License: GPL-2.0-or-later
 * Text Domain: estandar-inicio
 * Update URI: https://updates.estandar.cl/
 *
 * @package Estandar\Inicio
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
	return;
}

define( 'ESTANDAR_INICIO_FILE', __FILE__ );
define( 'ESTANDAR_INICIO_VERSION', '0.1.0' );
define( 'ESTANDAR_INICIO_PATH', plugin_dir_path( __FILE__ ) );
define( 'ESTANDAR_INICIO_URL', plugin_dir_url( __FILE__ ) );
define( 'ESTANDAR_INICIO_SLUG', 'estandar-inicio' );

require_once ESTANDAR_INICIO_PATH . 'includes/Hub.php';
require_once ESTANDAR_INICIO_PATH . 'includes/ChildTheme.php';
require_once ESTANDAR_INICIO_PATH . 'includes/SvgUploads.php';

add_action(
	'plugins_loaded',
	function () {
		// Capacidades (funcionan con o sin el hub).
		\Estandar\Inicio\ChildTheme::init();
		\Estandar\Inicio\SvgUploads::init();

		// Integración con el hub (si está presente).
		\Estandar\Inicio\Hub::init();
	}
);
