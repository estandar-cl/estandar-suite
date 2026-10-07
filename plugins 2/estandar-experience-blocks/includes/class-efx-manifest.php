<?php
/**
 * FX module manifest — single source of truth on the PHP side.
 *
 * A "module" is an independently loadable FX capability. Each module declares
 * which assets it needs (JS, CSS, or both) so the frontend can enqueue exactly
 * what a page uses and nothing else.
 *
 * The editor mirror of this manifest (effects, controls, presets) lives in
 * assets/editor/catalog.js.
 *
 * @package Estandar\FX
 */

namespace Estandar\FX;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Manifest {

	/**
	 * Config keys (inside the `estandarFx` attribute) that map to modules.
	 * Order matters only for class output stability.
	 *
	 * kind:
	 *  - js   → behavior driven by the runtime registry (needs core kernel)
	 *  - css  → pure CSS effect; frontend stamps classes, no JS shipped
	 *  - both → CSS base + JS behavior
	 */
	const MODULES = array(
		'reveal'    => array(
			'kind' => 'js',
			'js'   => 'assets/fx/reveal.js',
			'css'  => null,
		),
		'text'      => array(
			'kind' => 'both',
			'js'   => 'assets/fx/text.js',
			'css'  => 'assets/fx/text.css',
		),
		'scroll'    => array(
			'kind' => 'js',
			'js'   => 'assets/fx/scroll.js',
			'css'  => null,
		),
		'pointer'   => array(
			'kind' => 'both',
			'js'   => 'assets/fx/pointer.js',
			'css'  => 'assets/fx/pointer.css',
		),
		'hover'     => array(
			'kind' => 'css',
			'js'   => null,
			'css'  => 'assets/fx/hover.css',
		),
		'ambient'   => array(
			'kind' => 'css',
			'js'   => null,
			'css'  => 'assets/fx/ambient.css',
		),
		'particles' => array(
			'kind' => 'js',
			'js'   => 'assets/fx/particles.js',
			'css'  => null,
		),
		/* Structural blocks reuse the same pipeline. */
		'marquee'   => array(
			'kind' => 'both',
			'js'   => 'assets/fx/marquee.js',
			'css'  => 'assets/fx/marquee.css',
		),
		'beforeafter' => array(
			'kind' => 'both',
			'js'   => 'assets/fx/before-after.js',
			'css'  => 'assets/fx/before-after.css',
		),
		'stack'     => array(
			'kind' => 'both',
			'js'   => 'assets/fx/stack-cards.js',
			'css'  => 'assets/fx/stack-cards.css',
		),
		'coverflow' => array(
			'kind' => 'both',
			'js'   => 'assets/fx/coverflow-gallery.js',
			'css'  => 'assets/fx/coverflow-gallery.css',
		),
		'magnetic-carousel' => array(
			'kind' => 'both',
			'js'   => 'assets/fx/magnetic-carousel.js',
			'css'  => 'assets/fx/magnetic-carousel.css',
		),
		'spatial-gallery' => array(
			'kind' => 'both',
			'js'   => 'assets/fx/spatial-gallery.js',
			'css'  => 'assets/fx/spatial-gallery.css',
		),
		'cover-transition' => array(
			'kind' => 'both',
			'js'   => 'assets/fx/cover-transition.js',
			'css'  => 'assets/fx/cover-transition.css',
		),
		'scroll-panels' => array(
			'kind' => 'both',
			'js'   => 'assets/fx/scroll-panels.js',
			'css'  => 'assets/fx/scroll-panels.css',
		),
		'sticky-grid-scroll' => array(
			'kind' => 'both',
			'js'   => 'assets/fx/sticky-grid-scroll.js',
			'css'  => 'assets/fx/sticky-grid-scroll.css',
		),
		'horizontal-parallax-gallery' => array(
			'kind' => 'both',
			'js'   => 'assets/fx/horizontal-parallax-gallery.js',
			'css'  => 'assets/fx/horizontal-parallax-gallery.css',
		),
		'container-split-text' => array(
			'kind' => 'both',
			'js'   => 'assets/fx/container-split-text.js',
			'css'  => 'assets/fx/container-split-text.css',
		),
		'pinned-image-reveal' => array(
			'kind' => 'both',
			'js'   => 'assets/fx/pinned-image-reveal.js',
			'css'  => 'assets/fx/pinned-image-reveal.css',
		),
		'scroll-synced-carousel' => array(
			'kind' => 'both',
			'js'   => 'assets/fx/scroll-synced-carousel.js',
			'css'  => 'assets/fx/scroll-synced-carousel.css',
		),
	);

	/**
	 * CSS-only effects per module → class stamped by the frontend.
	 * JS modules are dispatched from data-efx; CSS modules act purely on class.
	 */
	const CSS_EFFECTS = array(
		'hover'   => array( 'lift', 'glow', 'shine', 'underline', 'zoom-media', 'frame', 'electric-border', 'dot-image-reveal', 'image-reveal', 'mesh-text' ),
		'ambient' => array( 'float', 'pulse', 'gradient', 'blob', 'noise', 'glass', 'spotlight-text' ),
	);

	/**
	 * Per-effect numeric/color settings surfaced to CSS as custom properties.
	 * config path => CSS var (value passes through the given sanitizer).
	 */
	const CSS_VARS = array(
		'hover.color'        => array( 'var' => '--efx-accent', 'type' => 'color' ),
		'hover.colorB'       => array( 'var' => '--efx-accent-b', 'type' => 'color' ),
		'hover.intensity'    => array( 'var' => '--efx-hover-intensity', 'type' => 'unit_float' ),
		'ambient.color'      => array( 'var' => '--efx-accent', 'type' => 'color' ),
		'ambient.colorB'     => array( 'var' => '--efx-accent-b', 'type' => 'color' ),
		'ambient.intensity'  => array( 'var' => '--efx-ambient-intensity', 'type' => 'unit_float' ),
		'ambient.speed'      => array( 'var' => '--efx-ambient-speed', 'type' => 'seconds' ),
	);

	/**
	 * Native blocks that receive the Experience panel + attribute.
	 * Structural estandar blocks are added automatically.
	 */
	public static function supported_blocks() {
		$native = array(
			'core/paragraph',
			'core/heading',
			'core/list',
			'core/quote',
			'core/pullquote',
			'core/image',
			'core/gallery',
			'core/cover',
			'core/group',
			'core/columns',
			'core/column',
			'core/media-text',
			'core/buttons',
			'core/button',
			'core/video',
			'core/embed',
			'core/separator',
		);

		$all = array_merge( $native, array_keys( Blocks::BLOCKS_MAP ) );

		/**
		 * Filter the block types that expose the Experience panel.
		 *
		 * @param string[] $all Block type names.
		 */
		return apply_filters( 'estandar_efx_supported_blocks', $all );
	}

	/**
	 * Whether a module id exists.
	 */
	public static function is_module( $id ) {
		return isset( self::MODULES[ $id ] );
	}

	/**
	 * Sanitize a CSS var value by declared type. Returns '' when invalid.
	 */
	public static function sanitize_css_value( $value, $type ) {
		switch ( $type ) {
			case 'color':
				$value = is_string( $value ) ? trim( $value ) : '';
				if ( preg_match( '/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{4}|[A-Fa-f0-9]{6}|[A-Fa-f0-9]{8})$/', $value ) ) {
					return $value;
				}
				if ( preg_match( '/^var\(--[a-zA-Z0-9_-]+\)$/', $value ) ) {
					return $value;
				}
				return '';

			case 'unit_float':
				if ( is_numeric( $value ) ) {
					return (string) max( 0.0, min( 3.0, (float) $value ) );
				}
				return '';

			case 'seconds':
				if ( is_numeric( $value ) ) {
					return max( 0.1, min( 60.0, (float) $value ) ) . 's';
				}
				return '';
		}

		return '';
	}
}
