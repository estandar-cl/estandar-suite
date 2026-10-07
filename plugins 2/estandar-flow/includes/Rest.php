<?php

namespace Estandar\Flow;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Rest
{
	const NS = 'estandar-suite/flow/v1';

	public static function init(): void
	{
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void
	{
		register_rest_route( self::NS, '/pipeline', array(
			'methods'             => 'GET',
			'permission_callback' => static function () { return current_user_can( 'manage_options' ); },
			'callback'            => static function ( \WP_REST_Request $request ) {
				return rest_ensure_response( array( 'pipeline' => Pipeline::list(
					(string) $request->get_param( 'stage' ), (string) $request->get_param( 'project' ),
					(int) $request->get_param( 'limit' ) ?: 100
				) ) );
			},
		) );

		register_rest_route( self::NS, '/pipeline/(?P<id>\d+)/transition', array(
			'methods'             => 'POST',
			'permission_callback' => static function () { return current_user_can( 'manage_options' ); },
			'callback'            => static function ( \WP_REST_Request $request ) {
				$body = (array) $request->get_json_params();
				$result = Pipeline::transition( (int) $request['id'], (string) ( $body['stage'] ?? '' ), (string) ( $body['reason'] ?? '' ) );
				if ( empty( $result ) ) {
					return new \WP_Error( 'estandar_suite_transition_failed', 'Transición inválida o sin cambios.', array( 'status' => 400 ) );
				}
				return rest_ensure_response( $result );
			},
		) );

		register_rest_route( self::NS, '/journey', array(
			'methods'             => 'GET',
			'permission_callback' => static function () { return current_user_can( 'manage_options' ); },
			'callback'            => static function ( \WP_REST_Request $request ) {
				return rest_ensure_response( array( 'journey' => Pipeline::list_journey(
					(string) $request->get_param( 'state' ),
					(int) $request->get_param( 'limit' ) ?: 100
				) ) );
			},
		) );

		register_rest_route( self::NS, '/journey/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'permission_callback' => static function () { return current_user_can( 'manage_options' ); },
			'callback'            => static function ( \WP_REST_Request $request ) {
				$row = Pipeline::get( (int) $request['id'] );
				if ( ! $row ) {
					return new \WP_Error( 'not_found', 'Pipeline not found.', array( 'status' => 404 ) );
				}
				return rest_ensure_response( $row );
			},
		) );

		register_rest_route( self::NS, '/journey/transition', array(
			'methods'             => 'POST',
			'permission_callback' => static function () { return current_user_can( 'manage_options' ); },
			'callback'            => static function ( \WP_REST_Request $request ) {
				$body = (array) $request->get_json_params();
				$result = Pipeline::journey_transition(
					(int) ( $body['pipeline_id'] ?? 0 ),
					(string) ( $body['state'] ?? '' ),
					(string) ( $body['reason'] ?? '' ),
					(string) ( $body['operator'] ?? '' ),
					(array) ( $body['metadata'] ?? array() )
				);
				if ( $result === null ) {
					return new \WP_Error( 'estandar_suite_journey_transition_failed', 'Transición inválida.', array( 'status' => 400 ) );
				}
				return rest_ensure_response( $result );
			},
		) );

		register_rest_route( self::NS, '/customers', array(
			'methods'             => 'GET',
			'permission_callback' => static function () { return current_user_can( 'manage_options' ); },
			'callback'            => static function () { return rest_ensure_response( array( 'customers' => Customers::list( 100 ) ) ); },
		) );

		register_rest_route( self::NS, '/quotes', array(
			'methods'             => 'GET',
			'permission_callback' => static function () { return current_user_can( 'manage_options' ); },
			'callback'            => static function () { return rest_ensure_response( array( 'quotes' => Quotes::list( 100 ) ) ); },
		) );
	}
}
