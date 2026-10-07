<?php

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Rest
{
	const NS = 'estandar-suite/capture/v1';

	public static function init(): void
	{
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void
	{
		register_rest_route( self::NS, '/captures', array(
			'methods'             => 'POST',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => static function ( \WP_REST_Request $request ) {
				$raw = (array) $request->get_json_params();
				$result = Ingestion::ingest( $raw );
				if ( empty( $result['id'] ) ) {
					return new \WP_Error( 'estandar_suite_capture_failed', 'Captura no persistida.', array( 'status' => 400 ) );
				}
				return rest_ensure_response( array( 'id' => $result['id'], 'capture_uuid' => $result['capture']['capture_uuid'] ) );
			},
		) );

		register_rest_route( self::NS, '/adoption/historical', array(
			'methods'             => 'GET',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => static function ( \WP_REST_Request $request ) {
				return rest_ensure_response( array(
					'legacy_present' => Adoption::legacy_present(),
					'historical'     => Adoption::historical( (int) $request->get_param( 'limit' ) ?: 100, 0 ),
				) );
			},
		) );

		register_rest_route( self::NS, '/forms/adoption', array(
			'methods'             => 'GET',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => static function ( \WP_REST_Request $request ) {
				$limit = (int) $request->get_param( 'limit' ) ?: 100;
				return rest_ensure_response( array(
					'legacy_present'  => FormsAdoption::legacy_present(),
					'resources'       => FormsAdoption::resource_counts(),
					'entries'         => FormsAdoption::historical_entries( $limit, 0 ),
					'responses'       => FormsAdoption::historical_responses( $limit, 0 ),
				) );
			},
		) );

		register_rest_route( self::NS, '/forms', array(
			'methods'             => 'GET',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => static function ( \WP_REST_Request $request ) {
				$source = sanitize_key( (string) $request->get_param( 'source' ) );
				return rest_ensure_response( array( 'source' => $source, 'forms' => FormBridge::forms( $source ) ) );
			},
		) );

		register_rest_route( self::NS, '/forms/fields', array(
			'methods'             => 'GET',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => static function ( \WP_REST_Request $request ) {
				$source  = sanitize_key( (string) $request->get_param( 'source' ) );
				$form_id = (int) $request->get_param( 'form_id' );
				return rest_ensure_response( array( 'fields' => FormBridge::fields( $source, $form_id ) ) );
			},
		) );

		register_rest_route( self::NS, '/forms/bindings', array(
			'methods'             => 'GET',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => static function ( \WP_REST_Request $request ) {
				$source = sanitize_key( (string) $request->get_param( 'source' ) );
				$form_id = (int) $request->get_param( 'form_id' );
				return rest_ensure_response( array( 'bindings' => \Estandar\Core\Integrations::list( $source, $form_id ) ) );
			},
		) );

		register_rest_route( self::NS, '/forms/jotform', array(
			'methods'             => 'POST',
			'permission_callback' => static function () {
				return true; // public — authenticated by shared webhook key
			},
			'callback'            => static function ( \WP_REST_Request $request ) {
				$provided = (string) $request->get_header( 'X-Estandar-Webhook-Key' );
				if ( $provided === '' ) {
					$provided = sanitize_text_field( (string) $request->get_param( 'key' ) );
				}
				if ( $provided === '' || ! hash_equals( FormBridge::webhook_key(), $provided ) ) {
					return new \WP_Error( 'estandar_suite_webhook_unauthorized', 'Clave de webhook inválida.', array( 'status' => 401 ) );
				}
				$payload = (array) $request->get_json_params();
				$result  = FormBridge::handle_jotform( $payload );
				if ( empty( $result['id'] ) ) {
					return new \WP_Error( 'estandar_suite_jotform_failed', 'Entrada no persistida.', array( 'status' => 400 ) );
				}
				return rest_ensure_response( array( 'id' => $result['id'], 'capture_uuid' => $result['capture']['capture_uuid'] ?? '' ) );
			},
		) );
	}
}
