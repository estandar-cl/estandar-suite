<?php
/**
 * Assets de admin (branding + tabs).
 *
 * @package Estandar\Suite
 */

namespace Estandar\Suite;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encola CSS/JS sólo en las pantallas del hub y de sus plugins alojados.
 */
class Assets {

	/**
	 * Init.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Encola assets si la pantalla actual es del hub o de un plugin alojado.
	 *
	 * @param string $hook Hook suffix de la pantalla actual.
	 * @return void
	 */
	public static function enqueue( string $hook ): void {
		if ( ! self::is_hub_screen( $hook ) ) {
			return;
		}

		wp_enqueue_style(
			'estandar-suite-admin',
			ESTANDAR_SUITE_URL . 'assets/admin/branding.css',
			array(),
			ESTANDAR_SUITE_VERSION
		);

		wp_add_inline_style( 'estandar-suite-admin', Branding::css_vars() );

		wp_enqueue_script(
			'estandar-suite-admin',
			ESTANDAR_SUITE_URL . 'assets/admin/tabs.js',
			array(),
			ESTANDAR_SUITE_VERSION,
			true
		);
	}

	/**
	 * ¿La pantalla pertenece al hub o a un plugin alojado?
	 *
	 * @param string $hook Hook suffix.
	 * @return bool
	 */
	public static function is_hub_screen( string $hook ): bool {
		if ( false !== strpos( $hook, ESTANDAR_SUITE_SLUG ) ) {
			return true;
		}

		foreach ( Registry::plugins() as $plugin ) {
			if ( ! empty( $plugin['page_slug'] ) && false !== strpos( $hook, (string) $plugin['page_slug'] ) ) {
				return true;
			}
		}

		return false;
	}
}
