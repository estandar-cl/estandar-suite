<?php

namespace Estandar\Insights;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Rest
{
	const NS = 'estandar-suite/insights/v1';

	public static function init(): void
	{
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void
	{
		register_rest_route( self::NS, '/funnel', array(
			'methods'             => 'GET',
			'permission_callback' => static function () { return current_user_can( 'manage_options' ); },
			'callback'            => static function () {
				$counts = array();
				if ( class_exists( 'Estandar\\Flow\\Pipeline' ) ) {
					foreach ( \Estandar\Flow\Pipeline::list() as $row ) {
						$counts[ $row['stage'] ] = (int) ( $counts[ $row['stage'] ] ?? 0 ) + 1;
					}
				}
				return rest_ensure_response( Funnel::compute( $counts ) );
			},
		) );

		register_rest_route( self::NS, '/events', array(
			'methods'             => 'GET',
			'permission_callback' => static function () { return current_user_can( 'manage_options' ); },
			'callback'            => static function ( \WP_REST_Request $request ) {
				global $wpdb;
				if ( ! isset( $wpdb ) ) {
					return rest_ensure_response( array( 'events' => array() ) );
				}
				$limit = (int) $request->get_param( 'limit' ) ?: 100;
				$rows  = $wpdb->get_results( $wpdb->prepare(
					"SELECT event_type, entity_type, entity_id, channel, project_slug, occurred_at
					 FROM {$wpdb->prefix}" . Events::TABLE . " ORDER BY id DESC LIMIT %d", $limit
				), ARRAY_A );
				return rest_ensure_response( array( 'events' => (array) $rows ) );
			},
		) );

		register_rest_route( self::NS, '/observability', array(
			'methods'             => 'GET',
			'permission_callback' => static function () { return current_user_can( 'manage_options' ); },
			'callback'            => static function () {
				return rest_ensure_response( Observability::resolve_state( array() ) );
			},
		) );
	}
}
