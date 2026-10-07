<?php
/**
 * Asset registry — registers every runtime handle once; nothing is enqueued
 * here. Enqueueing is decided per render by Frontend (frontend) and per screen
 * by the editor hooks below.
 *
 * Handle scheme:
 *   estandar-efx-core            kernel (ticker/visibility/pointer/scroll/registry)
 *   estandar-efx-core-css        base tokens + shared states
 *   estandar-efx-<module>        one per FX module (see Manifest::MODULES)
 *   estandar-efx-<module>-css
 *   estandar-efx-catalog         editor manifest (effects/controls/presets)
 *   estandar-efx-panel           editor Experience panel
 *   estandar-efx-blocks-editor   structural blocks (marquee/before-after/stack)
 *
 * @package Estandar\FX
 */

namespace Estandar\FX;

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
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );
		add_action( 'enqueue_block_assets', array( $this, 'enqueue_canvas_styles' ) );
		add_filter( 'block_categories_all', array( $this, 'register_block_category' ) );
	}

	/**
	 * Handle for a module script.
	 */
	public static function script_handle( $module ) {
		return 'estandar-efx-' . $module;
	}

	/**
	 * Handle for a module style.
	 */
	public static function style_handle( $module ) {
		return 'estandar-efx-' . $module . '-css';
	}

	public function register_assets() {
		$v = ESTANDAR_EFX_VERSION;

		wp_register_script(
			'estandar-efx-core',
			ESTANDAR_EFX_URL . 'assets/core/core.js',
			array(),
			$v,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_register_style( 'estandar-efx-core-css', ESTANDAR_EFX_URL . 'assets/core/core.css', array(), $v );

		foreach ( Manifest::MODULES as $module => $spec ) {
			if ( ! empty( $spec['js'] ) ) {
				wp_register_script(
					self::script_handle( $module ),
					ESTANDAR_EFX_URL . $spec['js'],
					array( 'estandar-efx-core' ),
					$v,
					array(
						'in_footer' => true,
						'strategy'  => 'defer',
					)
				);
			}

			if ( ! empty( $spec['css'] ) ) {
				wp_register_style(
					self::style_handle( $module ),
					ESTANDAR_EFX_URL . $spec['css'],
					array( 'estandar-efx-core-css' ),
					$v
				);
			}
		}

		/* Editor-side assets. */
		wp_register_script( 'estandar-efx-catalog', ESTANDAR_EFX_URL . 'assets/editor/catalog.js', array(), $v, true );

		wp_register_script(
			'estandar-efx-panel',
			ESTANDAR_EFX_URL . 'assets/editor/panel.js',
			array(
				'estandar-efx-catalog',
				'estandar-efx-core',
				self::script_handle( 'reveal' ),
				self::script_handle( 'text' ),
				'wp-blocks',
				'wp-element',
				'wp-block-editor',
				'wp-components',
				'wp-compose',
				'wp-hooks',
				'wp-data',
				'wp-i18n',
				'wp-api-fetch',
			),
			$v,
			true
		);

		wp_register_style( 'estandar-efx-panel-css', ESTANDAR_EFX_URL . 'assets/editor/panel.css', array( 'wp-components' ), $v );

		wp_register_script(
			'estandar-efx-blocks-editor',
			ESTANDAR_EFX_URL . 'assets/editor/blocks.js',
			array(
				'wp-blocks',
				'wp-element',
				'wp-block-editor',
				'wp-components',
				'wp-i18n',
				'wp-server-side-render',
			),
			$v,
			true
		);
	}

	/**
	 * Editor chrome (parent frame): panel + catalog + runtime for previews.
	 */
	public function enqueue_editor_assets() {
		wp_enqueue_script( 'estandar-efx-panel' );
		wp_enqueue_style( 'estandar-efx-panel-css' );

		wp_localize_script(
			'estandar-efx-panel',
			'estandarEfxEditor',
			array(
				'supportedBlocks' => array_values( Manifest::supported_blocks() ),
				'userPresets'     => Presets::instance()->get_presets(),
				'canSavePresets'  => current_user_can( Presets::SAVE_CAP ),
				'restNamespace'   => Presets::REST_NAMESPACE,
			)
		);
	}

	/**
	 * Styles that must exist inside the editor canvas (iframe) so CSS-only
	 * effects (hover, ambient) and pre-states preview truthfully.
	 * Runs on the frontend too — bail there; Frontend enqueues per use.
	 */
	public function enqueue_canvas_styles() {
		if ( ! is_admin() ) {
			return;
		}

		wp_enqueue_style( 'estandar-efx-core-css' );

		foreach ( Manifest::MODULES as $module => $spec ) {
			if ( ! empty( $spec['css'] ) ) {
				wp_enqueue_style( self::style_handle( $module ) );
			}
		}
	}

	public function register_block_category( $categories ) {
		return array_merge(
			array(
				array(
					'slug'  => 'estandar-experience',
					'title' => __( 'Estandar Experience', 'estandar-experience-blocks' ),
					'icon'  => 'star-filled',
				),
			),
			$categories
		);
	}
}
