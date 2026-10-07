<?php
/**
 * Asset registry — registra cada handle del runtime una sola vez; aquí nada se
 * enqueue. El enqueue lo decide Frontend por render (frontend) y los hooks del
 * editor por pantalla (F3).
 *
 * Handle scheme (v2):
 *   estandar-experience-core            kernel (ticker/visibility/pointer/scroll/registry)
 *   estandar-experience-core-css        base tokens + shared states
 *   estandar-experience-<module>        one per FX module (see Manifest::MODULES)
 *   estandar-experience-<module>-css
 *
 * @package Estandar\Experience
 */

namespace Estandar\Experience;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Assets {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init() {
		add_action( 'init', array( $this, 'register_assets' ) );
		add_filter( 'block_categories_all', array( $this, 'register_block_category' ) );
	}

	/**
	 * Lista de categorías de bloques con la de Estandar Experience.
	 *
	 * Defensivo: si la categoría ya existe (p. ej. la registra el legacy
	 * estandar-experience-blocks con el mismo slug), NO se duplica.
	 *
	 * @param array $categories
	 * @return array
	 */
	public static function category_list( array $categories ): array {
		$slugs = array_column( $categories, 'slug' );
		if ( in_array( 'estandar-experience', $slugs, true ) ) {
			return $categories;
		}
		return array_merge(
			array(
				array(
					'slug'  => 'estandar-experience',
					'title' => __( 'Estandar Experience', 'estandar-experience' ),
					'icon'  => 'star-filled',
				),
			),
			$categories
		);
	}

	public function register_block_category( $categories ) {
		return self::category_list( is_array( $categories ) ? $categories : array() );
	}

	public static function script_handle( $module ) {
		return 'estandar-experience-' . $module;
	}

	public static function style_handle( $module ) {
		return 'estandar-experience-' . $module . '-css';
	}

	public function register_assets() {
		wp_register_script(
			'estandar-experience-core',
			ESTANDAR_EXPERIENCE_URL . 'assets/core/core.js',
			array(),
			self::ver( 'assets/core/core.js' ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_register_style( 'estandar-experience-core-css', ESTANDAR_EXPERIENCE_URL . 'assets/core/core.css', array(), self::ver( 'assets/core/core.css' ) );

		/* Mobile boost: amplifica experiencias en pantallas pequeñas (móvil ≥
		   desktop). Aditivo y seguro; se enqueue solo cuando hay FX en uso. */
		wp_register_style( 'estandar-experience-mobile', ESTANDAR_EXPERIENCE_URL . 'assets/core/mobile.css', array( 'estandar-experience-core-css' ), self::ver( 'assets/core/mobile.css' ) );

		/* Editor (Gutenberg / FSE): catálogo + registro cliente de bloques + panel.
		   Sin build: la metadata se inyecta desde PHP (ver Blocks::localize_editor_data). */
		wp_register_script(
			'estandar-experience-catalog',
			ESTANDAR_EXPERIENCE_URL . 'assets/editor/catalog.js',
			array(),
			self::ver( 'assets/editor/catalog.js' ),
			true
		);

		wp_register_script(
			'estandar-experience-editor',
			ESTANDAR_EXPERIENCE_URL . 'assets/editor/editor.js',
			array( 'estandar-experience-catalog', 'wp-blocks', 'wp-block-editor', 'wp-element', 'wp-i18n', 'wp-components', 'wp-compose', 'wp-hooks' ),
			self::ver( 'assets/editor/editor.js' ),
			true
		);

		wp_register_style( 'estandar-experience-editor-css', ESTANDAR_EXPERIENCE_URL . 'assets/editor/editor.css', array(), self::ver( 'assets/editor/editor.css' ) );

		foreach ( Manifest::MODULES as $module => $spec ) {
			if ( ! empty( $spec['js'] ) ) {
				wp_register_script(
					self::script_handle( $module ),
					ESTANDAR_EXPERIENCE_URL . $spec['js'],
					array( 'estandar-experience-core' ),
					self::ver( $spec['js'] ),
					array(
						'in_footer' => true,
						'strategy'  => 'defer',
					)
				);
			}

			if ( ! empty( $spec['css'] ) ) {
				wp_register_style(
					self::style_handle( $module ),
					ESTANDAR_EXPERIENCE_URL . $spec['css'],
					array( 'estandar-experience-core-css' ),
					self::ver( $spec['css'] )
				);
			}
		}
	}

	/**
	 * Versión de un asset = versión del plugin + mtime del archivo. Cache-busting
	 * automático en cada cambio de código (no depende del bump de versión).
	 *
	 * @param string $rel Ruta relativa al plugin.
	 * @return string
	 */
	private static function ver( string $rel ): string {
		$file  = ESTANDAR_EXPERIENCE_PATH . $rel;
		$mtime = @filemtime( $file );
		return $mtime ? ESTANDAR_EXPERIENCE_VERSION . '.' . $mtime : ESTANDAR_EXPERIENCE_VERSION;
	}
}
