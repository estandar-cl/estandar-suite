<?php
/**
 * Plugin Name: Estandar Suite
 * Description: Contenedor (hub) mínimo de Estandar Suite. Agrupa y ordena los plugins de Estandar en el menú lateral y en la barra superior de WordPress, aplica el branding común en sus páginas de settings y provee el sistema de pestañas. Es puramente aditivo: si se desinstala, los plugins alojados siguen funcionando igual.
 * Version: 0.2.2
 * Requires at least: 6.0
 * Tested up to: 7.1
 * Requires PHP: 8.0
 * Author: Estandar
 * License: GPL-2.0-or-later
 * Text Domain: estandar-suite
 * Update URI: https://updates.estandar.cl/
 *
 * @package Estandar\Suite
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
	return;
}

define( 'ESTANDAR_SUITE_FILE', __FILE__ );
define( 'ESTANDAR_SUITE_VERSION', '0.2.2' );
define( 'ESTANDAR_SUITE_PATH', plugin_dir_path( __FILE__ ) );
define( 'ESTANDAR_SUITE_URL', plugin_dir_url( __FILE__ ) );
define( 'ESTANDAR_SUITE_SLUG', 'estandar-suite' );

require_once ESTANDAR_SUITE_PATH . 'includes/Registry.php';
require_once ESTANDAR_SUITE_PATH . 'includes/Branding.php';
require_once ESTANDAR_SUITE_PATH . 'includes/Settings.php';
require_once ESTANDAR_SUITE_PATH . 'includes/Assets.php';
require_once ESTANDAR_SUITE_PATH . 'includes/AdminMenu.php';
require_once ESTANDAR_SUITE_PATH . 'includes/AdminBar.php';
require_once ESTANDAR_SUITE_PATH . 'includes/Updates.php';

/**
 * API pública (global) para plugins alojados.
 *
 * Los plugins que quieran alojarse dentro del hub deben llamar estas funciones
 * sólo si existen, de forma que sigan funcionando si el hub no está presente:
 *
 *     add_action( 'plugins_loaded', function () {
 *         if ( function_exists( 'estandar_suite_register_plugin' ) ) {
 *             estandar_suite_register_plugin( array( 'id' => 'mi-plugin', 'name' => 'Mi Plugin' ) );
 *         }
 *     } );
 */

if ( ! function_exists( 'estandar_suite_register_plugin' ) ) {
	/**
	 * Registra un plugin dentro del hub.
	 *
	 * @param array $args Datos del plugin.
	 * @return bool True si se registró.
	 */
	function estandar_suite_register_plugin( array $args ): bool {
		return \Estandar\Suite\Registry::register( $args );
	}
}

if ( ! function_exists( 'estandar_suite_register_tab' ) ) {
	/**
	 * Agrega una pestaña de settings a un plugin registrado.
	 *
	 * @param string $plugin_id Id del plugin.
	 * @param array  $tab       Definición de la pestaña.
	 * @return bool True si se agregó.
	 */
	function estandar_suite_register_tab( string $plugin_id, array $tab ): bool {
		return \Estandar\Suite\Registry::add_tab( $plugin_id, $tab );
	}
}

if ( ! function_exists( 'estandar_suite_brand_header' ) ) {
	/**
	 * Imprime la cabecera de marca (para usar dentro de páginas de settings).
	 *
	 * @param array $args Opciones de la cabecera.
	 * @return void
	 */
	function estandar_suite_brand_header( array $args = array() ): void {
		\Estandar\Suite\Branding::header( $args );
	}
}

if ( ! function_exists( 'estandar_suite_registered_plugins' ) ) {
	/**
	 * Devuelve los plugins registrados en el hub.
	 *
	 * @return array
	 */
	function estandar_suite_registered_plugins(): array {
		return \Estandar\Suite\Registry::plugins();
	}
}

/**
 * Boot del hub. Se engancha en `plugins_loaded` (prioridad 20, después de que
 * los productos carguen) y luego dispara `estandar_suite_register` para que los
 * plugins alojados se registren.
 */
add_action(
	'plugins_loaded',
	function () {
		\Estandar\Suite\Branding::init();
		\Estandar\Suite\Assets::init();
		\Estandar\Suite\AdminMenu::init();
		\Estandar\Suite\AdminBar::init();
		\Estandar\Suite\Updates::init();

		/**
		 * Permite a los plugins alojados registrarse en el hub.
		 *
		 * @param \Estandar\Suite\Registry $registry Instancia del registro.
		 */
		do_action( 'estandar_suite_register' );
	},
	20
);
