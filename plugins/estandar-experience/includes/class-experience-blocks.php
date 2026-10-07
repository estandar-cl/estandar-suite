<?php
/**
 * Bloques estructurales de movimiento (v2).
 *
 * Solo los efectos que requieren una estructura DOM específica se vuelven
 * bloques; el resto es mejora sobre bloques nativos. Cada bloque se renderiza
 * en servidor (render.php) y reutiliza el pipeline de módulos para sus assets.
 *
 * Convivencia (regla de dos): el registro de bloques SOLO ocurre cuando la
 * guarda autoriza el frontend v2. Mientras el flag
 * `estandar_experience_frontend_enabled` esté OFF, el legacy
 * estandar-experience-blocks sigue siendo el dueño de estos nombres de bloque.
 *
 * @package Estandar\Experience
 */

namespace Estandar\Experience;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Blocks {

	/**
	 * Bloques que consumen InnerBlocks (su render.php usa $content).
	 */
	const INNER_BLOCKS = array( 'estandar/marquee', 'estandar/stack-cards' );

	/**
	 * Nombre de bloque → módulo FX que lo potencia.
	 */
	const BLOCKS_MAP = array(
		'estandar/marquee'               => 'marquee',
		'estandar/before-after'          => 'beforeafter',
		'estandar/stack-cards'           => 'stack',
		'estandar/coverflow-gallery'     => 'coverflow',
		'estandar/magnetic-carousel'     => 'magnetic-carousel',
		'estandar/spatial-gallery'       => 'spatial-gallery',
		'estandar/cover-transition'      => 'cover-transition',
		'estandar/scroll-panels'         => 'scroll-panels',
		'estandar/sticky-grid-scroll'    => 'sticky-grid-scroll',
		'estandar/horizontal-parallax-gallery' => 'horizontal-parallax-gallery',
		'estandar/container-split-text'  => 'container-split-text',
		'estandar/pinned-image-reveal'   => 'pinned-image-reveal',
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
		// Regla de dos: sin el flag, el legacy conserva la propiedad.
		if ( ! Guard::should_activate_v2_frontend() ) {
			return;
		}

		foreach ( self::BLOCKS_MAP as $name => $module ) {
			$dir = ESTANDAR_EXPERIENCE_PATH . 'blocks/' . substr( $name, strlen( 'estandar/' ) );

			if ( file_exists( $dir . '/block.json' ) ) {
				register_block_type( $dir );
			}
		}

		self::localize_editor_data();
	}

	/**
	 * Inyecta la metadata de los bloques al script del editor (sin build).
	 */
	private static function localize_editor_data(): void {
		if ( ! wp_script_is( 'estandar-experience-editor', 'registered' ) ) {
			return;
		}

		$data = array();

		foreach ( self::BLOCKS_MAP as $name => $module ) {
			$dir  = ESTANDAR_EXPERIENCE_PATH . 'blocks/' . substr( $name, strlen( 'estandar/' ) );
			$file = $dir . '/block.json';

			if ( ! file_exists( $file ) ) {
				continue;
			}

			$json = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( ! is_array( $json ) ) {
				continue;
			}

			$data[ $name ] = array(
				'title'       => $json['title'] ?? $name,
				'description' => $json['description'] ?? '',
				'icon'        => $json['icon'] ?? 'star-filled',
				'keywords'    => $json['keywords'] ?? array(),
				'attributes'  => $json['attributes'] ?? array(),
				'supports'    => $json['supports'] ?? array(),
				'innerBlocks' => in_array( $name, self::INNER_BLOCKS, true ),
			);
		}

		wp_localize_script( 'estandar-experience-editor', 'ESTANDAR_EXPERIENCE_BLOCKS', $data );
		wp_localize_script( 'estandar-experience-editor', 'ESTANDAR_EXPERIENCE_SUPPORTED', array_values( Manifest::supported_blocks() ) );
		wp_localize_script( 'estandar-experience-editor', 'ESTANDAR_EXPERIENCE_PREVIEW', array( 'url' => Preview::url() ) );
	}

	/**
	 * Helper de render compartido: enqueue el módulo de un bloque estructural.
	 */
	public static function enqueue_for( $block_name ) {
		if ( isset( self::BLOCKS_MAP[ $block_name ] ) ) {
			Frontend::instance()->enqueue_module( self::BLOCKS_MAP[ $block_name ] );
		}
	}
}
