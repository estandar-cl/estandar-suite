<?php
/**
 * User presets — named experience configurations, stored site-wide.
 *
 * Built-in presets live in assets/editor/catalog.js (they are compositions,
 * not code). This class only persists user-defined presets and exposes them
 * to the editor.
 *
 * @package Estandar\FX
 */

namespace Estandar\FX;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Presets {

	const OPTION         = 'estandar_efx_presets';
	const REST_NAMESPACE = 'estandar-efx/v1';
	const SAVE_CAP       = 'edit_theme_options';
	const MAX_PRESETS    = 50;

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function get_presets() {
		$presets = get_option( self::OPTION, array() );
		return is_array( $presets ) ? array_values( $presets ) : array();
	}

	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/presets',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'rest_get' ),
					'permission_callback' => function () {
						return current_user_can( 'edit_posts' );
					},
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'rest_save' ),
					'permission_callback' => function () {
						return current_user_can( self::SAVE_CAP );
					},
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/presets/(?P<id>[a-z0-9-]+)',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'rest_delete' ),
				'permission_callback' => function () {
					return current_user_can( self::SAVE_CAP );
				},
			)
		);
	}

	public function rest_get() {
		return rest_ensure_response( $this->get_presets() );
	}

	public function rest_save( \WP_REST_Request $request ) {
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();

		$label = isset( $params['label'] ) ? sanitize_text_field( (string) $params['label'] ) : '';
		$config = isset( $params['config'] ) && is_array( $params['config'] ) ? $params['config'] : array();

		if ( '' === $label || empty( $config ) ) {
			return new \WP_Error( 'efx_invalid_preset', __( 'A preset needs a name and a configuration.', 'estandar-experience-blocks' ), array( 'status' => 400 ) );
		}

		$config = Frontend::instance()->sanitize_config( $config );
		$editor_config = $this->to_editor_config( $config );

		if ( empty( $editor_config ) ) {
			return new \WP_Error( 'efx_empty_preset', __( 'The configuration has no active effects.', 'estandar-experience-blocks' ), array( 'status' => 400 ) );
		}

		$id      = sanitize_title( $label );
		$presets = get_option( self::OPTION, array() );
		$presets = is_array( $presets ) ? $presets : array();

		if ( ! isset( $presets[ $id ] ) && count( $presets ) >= self::MAX_PRESETS ) {
			return new \WP_Error( 'efx_too_many_presets', __( 'Preset limit reached.', 'estandar-experience-blocks' ), array( 'status' => 400 ) );
		}

		$presets[ $id ] = array(
			'id'     => $id,
			'label'  => $label,
			'config' => $editor_config,
		);

		update_option( self::OPTION, $presets, false );

		return rest_ensure_response(
			array(
				'success' => true,
				'presets' => array_values( $presets ),
			)
		);
	}

	public function rest_delete( \WP_REST_Request $request ) {
		$id      = sanitize_title( (string) $request['id'] );
		$presets = get_option( self::OPTION, array() );
		$presets = is_array( $presets ) ? $presets : array();

		unset( $presets[ $id ] );
		update_option( self::OPTION, $presets, false );

		return rest_ensure_response(
			array(
				'success' => true,
				'presets' => array_values( $presets ),
			)
		);
	}

	/**
	 * Convert the flat sanitized payload back into the editor attribute shape
	 * (modules at the top level, exactly like `estandarFx`).
	 */
	private function to_editor_config( $sanitized ) {
		$config = array();

		foreach ( $sanitized['modules'] as $module => $options ) {
			$config[ $module ] = $options;
		}
		if ( ! empty( $sanitized['timing'] ) ) {
			$config['timing'] = $sanitized['timing'];
		}
		if ( ! empty( $sanitized['advanced'] ) ) {
			$config['advanced'] = $sanitized['advanced'];
		}

		return $config;
	}
}
