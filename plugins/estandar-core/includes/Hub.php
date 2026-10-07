<?php

namespace Estandar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hub — "Backend Ordenado" (ARCHIVADO — S12, 07/09).
 *
 * ⚠ ARCHIVADO: ya NO se carga en el boot de estandar-core (estandar-core.php).
 * Queda como REFERENCIA HISTÓRICA del agregador "Central Estandar".
 * Motivo: el agregador quedó superseded por la navegación nativa de cada
 * producto + la página Inicio (que ya agrega todo); los módulos "inner" nunca
 * se construyeron y el prototipo con aliases de páginas completas duplicaba
 * chrome y memoria. No reactivar sin un caso de uso real y módulos "inner".
 *
 * (Diseño original, sin cambios: página central que agrupa y ordena módulos ya
 * existentes de los 5 productos. Nada nuevo de funcionamiento: cada módulo se
 * registra con un callable `render` que el producto dueño provee.)
 */
class Hub
{
	const SLUG = 'estandar-central';

	const MODULES_FILTER = 'estandar_suite_hub_modules';

	/** Hook de arranque (plugins_loaded). */
	public static function init(): void
	{
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_filter( self::MODULES_FILTER, array( __CLASS__, 'core_pilot_modules' ) );
	}

	/** Registra el menú "Central" (top-level, aditivo). */
	public static function menu(): void
	{
		add_menu_page(
			'Central Estandar',
			'Central',
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-layout',
			2.5
		);
	}

	/** Registro central de módulos: array( product => list ). */
	public static function modules(): array
	{
		$raw = (array) apply_filters( self::MODULES_FILTER, array() );
		$out = array();
		foreach ( $raw as $product => $list ) {
			$product = sanitize_key( (string) $product );
			foreach ( (array) $list as $m ) {
				if ( ! is_array( $m ) || empty( $m['id'] ) || empty( $m['label'] ) || empty( $m['render'] ) ) {
					continue;
				}
				$out[ $product . ':' . sanitize_key( $m['id'] ) ] = array(
					'product' => $product,
					'id'      => sanitize_key( $m['id'] ),
					'label'   => (string) $m['label'],
					'group'   => sanitize_key( (string) ( $m['group'] ?? 'General' ) ),
					'desc'    => (string) ( $m['desc'] ?? '' ),
					'cap'     => (string) ( $m['cap'] ?? 'manage_options' ),
					'render'  => $m['render'],
				);
			}
		}
		return $out;
	}

	/** Módulos agrupados por group (orden de registro). */
	public static function groups(): array
	{
		$groups = array();
		foreach ( self::modules() as $key => $m ) {
			$groups[ $m['group'] ][ $key ] = $m;
		}
		return $groups;
	}

	/** Piloto Core: SIN módulos por ahora. Los primeros candidatos eran aliases
	 *  de páginas admin completas (SiteMap::render_admin / AdminPage::render_embedded)
	 *  que re-renderizan todo el shell de WordPress → duplican chrome y memoria.
	 *  El hub solo aceptará módulos con render "inner" (contenido acotado), que
	 *  se irán construyendo en el Backend Ordenado (Fase B). */
	public static function core_pilot_modules( array $modules ): array
	{
		return $modules;
	}

	/** Página central: navegación por grupo + módulos del grupo activo. */
	public static function render(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos para acceder aquí.', 'estandar-suite-core' ) );
		}
		// Barra superior de marca de la suite (naranja + branding Estandar).
		\Estandar\Core\Admin::render_product_header();
		$groups = self::groups();
		if ( ! $groups ) {
			echo '<div class="wrap"><h1>Central</h1><p class="description">Sin módulos registrados todavía.</p></div>';
			return;
		}
		$keys    = array_keys( $groups );
		$current = sanitize_key( (string) ( $_GET['g'] ?? '' ) );
		if ( ! isset( $groups[ $current ] ) ) {
			$current = $keys[0];
		}
		echo '<div class="wrap">';
		echo '<h1>Central — Backend Ordenado</h1>';
		echo '<nav class="nav-tab-wrapper" style="margin-bottom:14px;">';
		foreach ( $keys as $g ) {
			$active = $g === $current ? ' nav-tab-active' : '';
			printf(
				'<a class="nav-tab%s" href="%s">%s</a>',
				esc_attr( $active ),
				esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&g=' . rawurlencode( $g ) ) ),
				esc_html( ucwords( str_replace( '-', ' ', $g ) ) )
			);
		}
		echo '</nav>';
		foreach ( $groups[ $current ] as $key => $m ) {
			if ( ! current_user_can( $m['cap'] ) ) {
				continue;
			}
			echo '<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 18px;margin:0 0 18px;max-width:1100px;">';
			echo '<h2 style="margin-top:0;">' . esc_html( $m['label'] ) . '</h2>';
			if ( $m['desc'] !== '' ) {
				echo '<p class="description" style="margin:0 0 10px;">' . esc_html( $m['desc'] ) . '</p>';
			}
			$html = call_user_func( $m['render'] );
			if ( is_string( $html ) && $html !== '' ) {
				echo $html; // renderers de Core imprimen o devuelven HTML; el hub acepta ambos.
			}
			echo '</div>';
		}
		echo '</div>';
	}
}
