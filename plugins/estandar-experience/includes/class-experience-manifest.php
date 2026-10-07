<?php
/**
 * Experience manifest — fuente única del lado PHP para el plugin v2.
 *
 * Porta el manifiesto del legacy Estandar FX V3 (estandar-experience-blocks).
 * Un "módulo" es una capacidad FX cargable de forma independiente; declara qué
 * assets necesita (JS, CSS o ambos) para que el frontend enqueue solo lo usado.
 *
 * @package Estandar\Experience
 */

namespace Estandar\Experience;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Manifest {

	/**
	 * Módulos FX portados (espejo del legacy FX V3).
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
	 * Efectos solo-CSS por módulo → clase estampada por el frontend.
	 */
	const CSS_EFFECTS = array(
		'hover'   => array( 'lift', 'glow', 'shine', 'underline', 'zoom-media', 'frame', 'electric-border', 'dot-image-reveal', 'image-reveal', 'mesh-text' ),
		'ambient' => array( 'float', 'pulse', 'gradient', 'blob', 'noise', 'glass', 'spotlight-text' ),
	);

	/**
	 * Ajustes numéricos/color por efecto → variables CSS custom.
	 * config path => CSS var (el valor pasa por el sanitizador declarado).
	 */
	const CSS_VARS = array(
		'hover.color'       => array( 'var' => '--efx-accent', 'type' => 'color' ),
		'hover.colorB'      => array( 'var' => '--efx-accent-b', 'type' => 'color' ),
		'hover.intensity'   => array( 'var' => '--efx-hover-intensity', 'type' => 'unit_float' ),
		'ambient.color'     => array( 'var' => '--efx-accent', 'type' => 'color' ),
		'ambient.colorB'    => array( 'var' => '--efx-accent-b', 'type' => 'color' ),
		'ambient.intensity' => array( 'var' => '--efx-ambient-intensity', 'type' => 'unit_float' ),
		'ambient.speed'     => array( 'var' => '--efx-ambient-speed', 'type' => 'seconds' ),
	);

	/**
	 * Bloques estructurales portados (espejo del legacy FX V3).
	 * key = id de módulo en MODULES · dir = carpeta del bloque (block.json) ·
	 * slug = nombre de bloque registrado.
	 */
	const BLOCKS = array(
		'marquee'                      => array( 'dir' => 'marquee', 'slug' => 'estandar/marquee' ),
		'beforeafter'                  => array( 'dir' => 'before-after', 'slug' => 'estandar/before-after' ),
		'stack'                        => array( 'dir' => 'stack-cards', 'slug' => 'estandar/stack-cards' ),
		'coverflow'                    => array( 'dir' => 'coverflow-gallery', 'slug' => 'estandar/coverflow-gallery' ),
		'magnetic-carousel'            => array( 'dir' => 'magnetic-carousel', 'slug' => 'estandar/magnetic-carousel' ),
		'spatial-gallery'              => array( 'dir' => 'spatial-gallery', 'slug' => 'estandar/spatial-gallery' ),
		'cover-transition'             => array( 'dir' => 'cover-transition', 'slug' => 'estandar/cover-transition' ),
		'scroll-panels'                => array( 'dir' => 'scroll-panels', 'slug' => 'estandar/scroll-panels' ),
		'sticky-grid-scroll'           => array( 'dir' => 'sticky-grid-scroll', 'slug' => 'estandar/sticky-grid-scroll' ),
		'horizontal-parallax-gallery'  => array( 'dir' => 'horizontal-parallax-gallery', 'slug' => 'estandar/horizontal-parallax-gallery' ),
		'container-split-text'         => array( 'dir' => 'container-split-text', 'slug' => 'estandar/container-split-text' ),
		'pinned-image-reveal'          => array( 'dir' => 'pinned-image-reveal', 'slug' => 'estandar/pinned-image-reveal' ),
		'scroll-synced-carousel'       => array( 'dir' => 'scroll-synced-carousel', 'slug' => 'estandar/scroll-synced-carousel' ),
	);

	/**
	 * Bloques nativos que reciben el panel "Experiencia" + atributo.
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

		$all = array_merge( $native, array_column( self::BLOCKS, 'slug' ) );

		return apply_filters( 'estandar_experience_supported_blocks', $all );
	}

	public static function is_module( $id ) {
		return isset( self::MODULES[ $id ] );
	}

	/**
	 * Sanitizar un valor CSS var por su tipo declarado. '' si inválido.
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

	public static function structural_block_count(): int {
		return count( self::BLOCKS );
	}

	public static function module_count(): int {
		return count( self::MODULES );
	}
}
