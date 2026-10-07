<?php
/**
 * Registro de plugins y pestañas alojados en el hub.
 *
 * @package Estandar\Suite
 */

namespace Estandar\Suite;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registro central (aditivo): los plugins alojados se declaran aquí.
 */
class Registry {

	/**
	 * Plugins registrados, indexados por id.
	 *
	 * @var array<string, array>
	 */
	private static array $plugins = array();

	/**
	 * Registra un plugin en el hub.
	 *
	 * @param array $args Datos del plugin.
	 * @return bool
	 */
	public static function register( array $args ): bool {
		$defaults = array(
			'id'          => '',
			'name'        => '',
			'menu_title'  => '',
			'version'     => '',
			'description' => '',
			'capability'  => 'manage_options',
			'icon'        => 'dashicons-admin-generic',
			'position'    => 70,
			'page_slug'   => '',
			'callback'    => null,
			'tabs'        => array(),
			'standalone'  => true,
		);

		$args = array_merge( $defaults, $args );

		$args['id'] = sanitize_key( (string) $args['id'] );
		if ( '' === $args['id'] || '' === (string) $args['name'] ) {
			return false;
		}

		if ( '' === (string) $args['page_slug'] ) {
			$args['page_slug'] = $args['id'];
		}
		$args['page_slug'] = sanitize_key( (string) $args['page_slug'] );

		if ( '' === (string) $args['menu_title'] ) {
			$args['menu_title'] = (string) $args['name'];
		}

		$tabs = array();
		foreach ( (array) $args['tabs'] as $tab ) {
			$tab = self::normalize_tab( $tab );
			if ( null !== $tab ) {
				$tabs[ $tab['id'] ] = $tab;
			}
		}
		$args['tabs'] = $tabs;

		self::$plugins[ $args['id'] ] = $args;

		return true;
	}

	/**
	 * Agrega una pestaña a un plugin ya registrado.
	 *
	 * @param string $plugin_id Id del plugin.
	 * @param array  $tab       Definición de la pestaña.
	 * @return bool
	 */
	public static function add_tab( string $plugin_id, array $tab ): bool {
		$plugin_id = sanitize_key( $plugin_id );

		if ( ! isset( self::$plugins[ $plugin_id ] ) ) {
			return false;
		}

		$tab = self::normalize_tab( $tab );
		if ( null === $tab ) {
			return false;
		}

		self::$plugins[ $plugin_id ]['tabs'][ $tab['id'] ] = $tab;

		return true;
	}

	/**
	 * Normaliza la definición de una pestaña.
	 *
	 * @param mixed $tab Datos de la pestaña.
	 * @return array|null
	 */
	private static function normalize_tab( $tab ): ?array {
		if ( ! is_array( $tab ) || empty( $tab['id'] ) || empty( $tab['label'] ) ) {
			return null;
		}

		$children = array();
		$raw      = isset( $tab['children'] ) ? $tab['children'] : ( $tab['items'] ?? array() );
		foreach ( (array) $raw as $child ) {
			$child = self::normalize_tab( $child );
			if ( null !== $child ) {
				$children[ $child['id'] ] = $child;
			}
		}

		return array(
			'id'         => sanitize_key( (string) $tab['id'] ),
			'label'      => (string) $tab['label'],
			'capability' => isset( $tab['capability'] ) ? (string) $tab['capability'] : 'manage_options',
			'callback'   => $tab['callback'] ?? null,
			'order'      => isset( $tab['order'] ) ? (int) $tab['order'] : 50,
			'children'   => $children,
		);
	}

	/**
	 * Devuelve los plugins registrados, ordenados por posición y nombre.
	 *
	 * @return array<string, array>
	 */
	public static function plugins(): array {
		$plugins = self::$plugins;

		uasort(
			$plugins,
			static function ( $a, $b ) {
				if ( (int) $a['position'] === (int) $b['position'] ) {
					return strcasecmp( (string) $a['name'], (string) $b['name'] );
				}
				return (int) $a['position'] <=> (int) $b['position'];
			}
		);

		/**
		 * Filtra la lista de plugins alojados.
		 *
		 * @param array $plugins Plugins registrados.
		 */
		return apply_filters( 'estandar_suite_plugins', $plugins );
	}

	/**
	 * Ordena (recursivamente) una lista de tabs por `order` y luego label.
	 *
	 * @param array $tabs Tabs a ordenar.
	 * @return array
	 */
	public static function sort_tabs( array $tabs ): array {
		foreach ( $tabs as &$tab ) {
			if ( ! empty( $tab['children'] ) && is_array( $tab['children'] ) ) {
				$tab['children'] = self::sort_tabs( $tab['children'] );
			}
		}
		unset( $tab );

		uasort(
			$tabs,
			static function ( $a, $b ) {
				if ( (int) $a['order'] === (int) $b['order'] ) {
					return strcasecmp( (string) $a['label'], (string) $b['label'] );
				}
				return (int) $a['order'] <=> (int) $b['order'];
			}
		);

		return $tabs;
	}

	/**
	 * Devuelve un plugin por id.
	 *
	 * @param string $id Id del plugin.
	 * @return array|null
	 */
	public static function get( string $id ): ?array {
		$id = sanitize_key( $id );

		return self::$plugins[ $id ] ?? null;
	}

	/**
	 * Indica si un plugin está registrado.
	 *
	 * @param string $id Id del plugin.
	 * @return bool
	 */
	public static function has( string $id ): bool {
		return isset( self::$plugins[ sanitize_key( $id ) ] );
	}
}
