<?php
/**
 * Registers the `estandarFx` attribute on supported block types (server side).
 *
 * The editor registers the same attribute via the blocks.registerBlockType JS
 * filter; this server mirror keeps dynamic blocks and REST validation from
 * stripping it.
 *
 * @package Estandar\FX
 */

namespace Estandar\FX;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Attributes {

	const ATTRIBUTE = 'estandarFx';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init() {
		add_filter( 'register_block_type_args', array( $this, 'register_attribute' ), 10, 2 );
	}

	public function register_attribute( $args, $block_type ) {
		if ( ! in_array( $block_type, Manifest::supported_blocks(), true ) ) {
			return $args;
		}

		if ( ! isset( $args['attributes'] ) || ! is_array( $args['attributes'] ) ) {
			$args['attributes'] = array();
		}

		if ( ! isset( $args['attributes'][ self::ATTRIBUTE ] ) ) {
			$args['attributes'][ self::ATTRIBUTE ] = array(
				'type'    => 'object',
				'default' => array(),
			);
		}

		return $args;
	}
}
