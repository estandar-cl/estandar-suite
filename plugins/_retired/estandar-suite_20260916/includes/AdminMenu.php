<?php
/**
 * Menú lateral de administración.
 *
 * @package Estandar\Suite
 */

namespace Estandar\Suite;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Menú lateral: una página única + accesos rápidos por plugin.
 */
class AdminMenu {

	/**
	 * Init.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register' ) );
	}

	/**
	 * Registra el menú top-level y los accesos rápidos.
	 *
	 * @return void
	 */
	public static function register(): void {
		$capability = 'manage_options';

		add_menu_page(
			__( 'Estandar Suite', 'estandar-suite' ),
			__( 'Estandar Suite', 'estandar-suite' ),
			$capability,
			ESTANDAR_SUITE_SLUG,
			array( Settings::class, 'render_page' ),
			'dashicons-screenoptions',
			58
		);

		add_submenu_page(
			ESTANDAR_SUITE_SLUG,
			__( 'Estandar Suite', 'estandar-suite' ),
			__( 'Inicio', 'estandar-suite' ),
			$capability,
			ESTANDAR_SUITE_SLUG,
			array( Settings::class, 'render_page' )
		);

		// Acceso rápido: cada plugin abre la página única en su tab (`&path=<id>`).
		foreach ( Registry::plugins() as $plugin ) {
			add_submenu_page(
				ESTANDAR_SUITE_SLUG,
				(string) $plugin['name'],
				(string) $plugin['menu_title'],
				(string) ( $plugin['capability'] ?? $capability ),
				ESTANDAR_SUITE_SLUG . '&path=' . rawurlencode( (string) $plugin['id'] ),
				array( Settings::class, 'render_page' )
			);
		}
	}
}
