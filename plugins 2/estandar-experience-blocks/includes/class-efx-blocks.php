<?php
/**
 * Structural motion blocks.
 *
 * Only effects that require a specific DOM structure become blocks; everything
 * else is an enhancement on native blocks. Each block is server-rendered
 * (render.php) and reuses the module pipeline for its assets.
 *
 * @package Estandar\FX
 */

namespace Estandar\FX;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Blocks {

	/**
	 * Block name → FX module powering it.
	 */
	const BLOCKS_MAP = array(
		'estandar/marquee'      => 'marquee',
		'estandar/before-after' => 'beforeafter',
		'estandar/stack-cards'  => 'stack',
		'estandar/coverflow-gallery' => 'coverflow',
		'estandar/magnetic-carousel' => 'magnetic-carousel',
		'estandar/spatial-gallery' => 'spatial-gallery',
		'estandar/cover-transition' => 'cover-transition',
		'estandar/scroll-panels' => 'scroll-panels',
		'estandar/sticky-grid-scroll' => 'sticky-grid-scroll',
		'estandar/horizontal-parallax-gallery' => 'horizontal-parallax-gallery',
		'estandar/container-split-text' => 'container-split-text',
		'estandar/pinned-image-reveal' => 'pinned-image-reveal',
		'estandar/scroll-synced-carousel' => 'scroll-synced-carousel',
	);

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init() {
		add_action( 'init', array( $this, 'register_blocks' ) );
	}

	public function register_blocks() {
		foreach ( self::BLOCKS_MAP as $name => $module ) {
			$dir = ESTANDAR_EFX_PATH . 'blocks/' . substr( $name, strlen( 'estandar/' ) );

			if ( file_exists( $dir . '/block.json' ) ) {
				register_block_type( $dir );
			}
		}
	}

	/**
	 * Shared render-time helper: enqueue the module of a structural block.
	 */
	public static function enqueue_for( $block_name ) {
		if ( isset( self::BLOCKS_MAP[ $block_name ] ) ) {
			Frontend::instance()->enqueue_module( self::BLOCKS_MAP[ $block_name ] );
		}
	}
}
