<?php
/**
 * Barra superior de WordPress (admin bar).
 *
 * @package Estandar\Suite
 */

namespace Estandar\Suite;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Nodo "Estandar Suite" con navegación por plugins y sus submenús.
 */
class AdminBar {

	/**
	 * Profundidad máxima de submenús en la barra.
	 */
	private const MAX_DEPTH = 3;

	/**
	 * Init.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_bar_menu', array( __CLASS__, 'render' ), 80 );
	}

	/**
	 * Render del nodo en la barra superior.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar Instancia de la barra.
	 * @return void
	 */
	public static function render( $wp_admin_bar ): void {
		if ( ! is_object( $wp_admin_bar ) || ! is_admin_bar_showing() ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$wp_admin_bar->add_node(
			array(
				'id'    => ESTANDAR_SUITE_SLUG,
				'title' => '<span class="ab-icon dashicons dashicons-screenoptions"></span><span class="ab-label">' . esc_html__( 'Estandar Suite', 'estandar-suite' ) . '</span>',
				'href'  => self::url( '' ),
				'meta'  => array( 'title' => esc_attr__( 'Estandar Suite', 'estandar-suite' ) ),
			)
		);

		foreach ( Registry::plugins() as $plugin ) {
			if ( ! current_user_can( (string) ( $plugin['capability'] ?? 'manage_options' ) ) ) {
				continue;
			}

			$node_id = ESTANDAR_SUITE_SLUG . '-' . (string) $plugin['id'];

			$wp_admin_bar->add_node(
				array(
					'id'     => $node_id,
					'parent' => ESTANDAR_SUITE_SLUG,
					'title'  => esc_html( (string) $plugin['name'] ),
					'href'   => self::url( (string) $plugin['id'] ),
				)
			);

			self::add_tabs( $wp_admin_bar, (array) $plugin['tabs'], array( (string) $plugin['id'] ), $node_id, 1 );
		}
	}

	/**
	 * Agrega los submenús (tabs) de un plugin a la barra.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar Barra.
	 * @param array         $tabs         Tabs del nivel.
	 * @param array         $prefix       Segmentos de ruta.
	 * @param string        $parent_id    Nodo padre.
	 * @param int           $depth        Profundidad actual.
	 * @return void
	 */
	private static function add_tabs( $wp_admin_bar, array $tabs, array $prefix, string $parent_id, int $depth ): void {
		if ( $depth > self::MAX_DEPTH ) {
			return;
		}

		foreach ( Registry::sort_tabs( $tabs ) as $tab ) {
			if ( ! current_user_can( (string) ( $tab['capability'] ?? 'manage_options' ) ) ) {
				continue;
			}

			$id      = (string) $tab['id'];
			$node_id = $parent_id . '-' . $id;

			$wp_admin_bar->add_node(
				array(
					'id'     => $node_id,
					'parent' => $parent_id,
					'title'  => esc_html( (string) $tab['label'] ),
					'href'   => self::url( implode( '/', array_merge( $prefix, array( $id ) ) ) ),
				)
			);

			if ( ! empty( $tab['children'] ) ) {
				self::add_tabs( $wp_admin_bar, (array) $tab['children'], array_merge( $prefix, array( $id ) ), $node_id, $depth + 1 );
			}
		}
	}

	/**
	 * URL de una ruta del hub.
	 *
	 * @param string $path Ruta (vacío = página del hub).
	 * @return string
	 */
	private static function url( string $path ): string {
		$args = array( 'page' => ESTANDAR_SUITE_SLUG );
		if ( '' !== $path ) {
			$args['path'] = $path;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}
}
