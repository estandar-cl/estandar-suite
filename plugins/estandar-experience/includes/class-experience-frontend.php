<?php
/**
 * Frontend pipeline (v2).
 *
 * Para cada bloque renderizado con atributo `estandarFx` no vacío:
 *   1. sanitiza el config a un payload runtime compacto,
 *   2. estampa el wrapper del bloque (clase `efx`, clases de efecto CSS, CSS
 *      vars y `data-efx` JSON para los módulos JS),
 *   3. enqueue el kernel + exactamente los módulos que la página usa.
 *
 * Páginas sin experiencias no envían ni un byte del runtime FX.
 *
 * @package Estandar\Experience
 */

namespace Estandar\Experience;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Frontend {

	private static $instance = null;

	/** Módulos ya encolados durante este request. */
	private $enqueued = array();

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init() {
		add_filter( 'render_block', array( $this, 'render_block' ), 20, 2 );
	}

	/* ---------------------------------------------------------------- */
	/*  Render                                                          */
	/* ---------------------------------------------------------------- */

	public function render_block( $block_content, $block ) {
		if ( is_admin() || ! is_string( $block_content ) || '' === $block_content ) {
			return $block_content;
		}

		if ( empty( $block['attrs'][ Attributes::ATTRIBUTE ] ) || ! is_array( $block['attrs'][ Attributes::ATTRIBUTE ] ) ) {
			return $block_content;
		}

		if ( ! in_array( $block['blockName'], Manifest::supported_blocks(), true ) ) {
			return $block_content;
		}

		$config = $this->sanitize_config( $block['attrs'][ Attributes::ATTRIBUTE ] );

		if ( empty( $config['modules'] ) ) {
			return $block_content;
		}

		foreach ( array_keys( $config['modules'] ) as $module ) {
			$this->enqueue_module( $module );
		}

		return $this->stamp_wrapper( $block_content, $config );
	}

	/**
	 * Enqueue el kernel más un módulo. Público para que los render callbacks
	 * de bloques estructurales reutilicen el mismo pipeline.
	 */
	public function enqueue_module( $module ) {
		if ( isset( $this->enqueued[ $module ] ) || ! Manifest::is_module( $module ) ) {
			return;
		}
		$this->enqueued[ $module ] = true;

		$spec = Manifest::MODULES[ $module ];

		wp_enqueue_style( 'estandar-experience-core-css' );
		wp_enqueue_style( 'estandar-experience-mobile' );

		if ( ! empty( $spec['js'] ) ) {
			wp_enqueue_script( 'estandar-experience-core' );
			wp_enqueue_script( Assets::script_handle( $module ) );
		}

		if ( ! empty( $spec['css'] ) ) {
			wp_enqueue_style( Assets::style_handle( $module ) );
		}
	}

	/* ---------------------------------------------------------------- */
	/*  Sanitization                                                    */
	/* ---------------------------------------------------------------- */

	/**
	 * Reduce el atributo crudo a un payload de confianza.
	 *
	 * array(
	 *   'modules'  => [ module => [ 'effect' => id, ...scalar opts ] ],
	 *   'timing'   => [...],
	 *   'advanced' => [...],
	 * )
	 */
	public function sanitize_config( $raw ) {
		$out = array(
			'modules'  => array(),
			'timing'   => array(),
			'advanced' => array(),
		);

		foreach ( Manifest::MODULES as $module => $spec ) {
			if ( empty( $raw[ $module ] ) || ! is_array( $raw[ $module ] ) ) {
				continue;
			}

			$effect = isset( $raw[ $module ]['effect'] ) ? sanitize_key( (string) $raw[ $module ]['effect'] ) : '';

			if ( '' === $effect ) {
				continue;
			}

			$out['modules'][ $module ] = $this->sanitize_options( $raw[ $module ] );
			$out['modules'][ $module ]['effect'] = $effect;
		}

		if ( isset( $raw['timing'] ) && is_array( $raw['timing'] ) ) {
			$t = $raw['timing'];

			if ( isset( $t['trigger'] ) && in_array( $t['trigger'], array( 'visible', 'load' ), true ) ) {
				$out['timing']['trigger'] = $t['trigger'];
			}
			foreach ( array( 'duration' => array( 50, 5000 ), 'delay' => array( 0, 5000 ), 'stagger' => array( 0, 500 ) ) as $key => $range ) {
				if ( isset( $t[ $key ] ) && is_numeric( $t[ $key ] ) ) {
					$out['timing'][ $key ] = (int) max( $range[0], min( $range[1], (int) $t[ $key ] ) );
				}
			}
			if ( isset( $t['easing'] ) ) {
				$easing = sanitize_key( (string) $t['easing'] );
				if ( in_array( $easing, array( 'expo', 'quint', 'spring', 'snap', 'linear' ), true ) ) {
					$out['timing']['easing'] = $easing;
				}
			}
		}

		if ( isset( $raw['advanced'] ) && is_array( $raw['advanced'] ) ) {
			$a = $raw['advanced'];
			$out['advanced']['once']   = ! isset( $a['once'] ) || (bool) $a['once'];
			$out['advanced']['mobile'] = ! isset( $a['mobile'] ) || (bool) $a['mobile'];
		} else {
			$out['advanced'] = array(
				'once'   => true,
				'mobile' => true,
			);
		}

		return $out;
	}

	/**
	 * Conserva solo opciones escalares con límites ajustados.
	 */
	public function sanitize_options( $options ) {
		$clean = array();

		foreach ( $options as $key => $value ) {
			if ( 'effect' === $key ) {
				continue;
			}

			$key = preg_replace( '/[^a-zA-Z0-9_]/', '', (string) $key );
			if ( '' === $key || strlen( $key ) > 40 ) {
				continue;
			}

			if ( is_bool( $value ) ) {
				$clean[ $key ] = $value;
			} elseif ( is_numeric( $value ) ) {
				$clean[ $key ] = max( -10000.0, min( 10000.0, (float) $value ) );
			} elseif ( is_string( $value ) && strlen( $value ) <= 100 ) {
				$value = trim( $value );
				if ( preg_match( '/^[#()%,.\s\w-]*$/u', $value ) ) {
					$clean[ $key ] = $value;
				}
			}
		}

		return $clean;
	}

	/* ---------------------------------------------------------------- */
	/*  Wrapper stamping                                                */
	/* ---------------------------------------------------------------- */

	private function stamp_wrapper( $html, $config ) {
		$processor = new \WP_HTML_Tag_Processor( $html );

		if ( ! $processor->next_tag() ) {
			return $html;
		}

		$processor->add_class( 'efx' );

		if ( empty( $config['advanced']['mobile'] ) ) {
			$processor->add_class( 'efx-no-mobile' );
		}

		$payload = array();
		$vars    = array();

		foreach ( $config['modules'] as $module => $options ) {
			$kind   = Manifest::MODULES[ $module ]['kind'];
			$effect = $options['effect'];

			if ( 'css' === $kind ) {
				if ( in_array( $effect, Manifest::CSS_EFFECTS[ $module ], true ) ) {
					$processor->add_class( 'efx-' . $module . '-' . $effect );
				}

				foreach ( Manifest::CSS_VARS as $path => $spec ) {
					list( $var_module, $var_key ) = explode( '.', $path, 2 );
					if ( $var_module === $module && isset( $options[ $var_key ] ) ) {
						$value = Manifest::sanitize_css_value( $options[ $var_key ], $spec['type'] );
						if ( '' !== $value ) {
							$vars[ $spec['var'] ] = $value;
						}
					}
				}
			} else {
				$payload[ $module ] = $options;
			}
		}

		if ( ! empty( $payload ) ) {
			$payload['timing']   = (object) $config['timing'];
			$payload['advanced'] = (object) $config['advanced'];
			$processor->set_attribute( 'data-efx', (string) wp_json_encode( $payload ) );
		}

		if ( ! empty( $vars ) ) {
			$style = (string) $processor->get_attribute( 'style' );
			$extra = '';
			foreach ( $vars as $name => $value ) {
				$extra .= $name . ':' . $value . ';';
			}
			$style = rtrim( $style, '; ' );
			$processor->set_attribute( 'style', ( '' !== $style ? $style . ';' : '' ) . $extra );
		}

		return $processor->get_updated_html();
	}
}
