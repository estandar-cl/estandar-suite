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
				if ( class_exists( 'Estandar\\Flow\\Pipeline' ) && method_exists( 'Estandar\\Flow\\Pipeline', 'counts_by_stage' ) ) {
					$counts = (array) \Estandar\Flow\Pipeline::counts_by_stage();
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

		// ── Analytics web (equivalente GA): embudo visitas→leads + serie diaria ──
		register_rest_route( self::NS, '/funnel/web', array(
			'methods'             => 'GET',
			'permission_callback' => static function () { return current_user_can( 'manage_options' ); },
			'callback'            => static function ( \WP_REST_Request $request ) {
				$from = (string) $request->get_param( 'from' );
				$to   = (string) $request->get_param( 'to' );
				return rest_ensure_response( WebFunnel::snapshot( $from, $to ) );
			},
		) );

		register_rest_route( self::NS, '/analytics/visits', array(
			'methods'             => 'GET',
			'permission_callback' => static function () { return current_user_can( 'manage_options' ); },
			'callback'            => static function ( \WP_REST_Request $request ) {
				$from  = (string) $request->get_param( 'from' );
				$to    = (string) $request->get_param( 'to' );
				$daily = WebFunnel::daily( $from, $to );
				return rest_ensure_response( array(
					'range'  => WebFunnel::normalize_range( $from, $to ),
					'totals' => WebFunnel::totals( $daily ),
					'daily'  => $daily,
				) );
			},
		) );

		// ── Herencia v2: adopción de datos legacy (analytics + seo) ─────────
		register_rest_route( self::NS, '/adoption', array(
			'methods'             => 'GET',
			'permission_callback' => static function () { return current_user_can( 'manage_options' ); },
			'callback'            => static function () {
				return rest_ensure_response( array(
					'analytics' => Analytics::status(),
					'seo'       => SeoAdoption::status(),
				) );
			},
		) );

		register_rest_route( self::NS, '/adoption/import', array(
			'methods'             => 'POST',
			'permission_callback' => static function () { return current_user_can( 'manage_options' ); },
			'callback'            => static function ( \WP_REST_Request $request ) {
				$dry   = empty( $request->get_param( 'commit' ) );
				$limit = max( 10, (int) $request->get_param( 'limit' ) ?: 100 );
				return rest_ensure_response( array(
					'analytics' => Analytics::import( $dry, $limit ),
					'seo'       => SeoAdoption::import( $dry, $limit ),
					'commit'    => ! $dry,
				) );
			},
		) );

		register_rest_route( self::NS, '/adoption/rollback', array(
			'methods'             => 'POST',
			'permission_callback' => static function () { return current_user_can( 'manage_options' ); },
			'callback'            => static function () {
				return rest_ensure_response( array(
					'analytics_rolled' => Analytics::rollback(),
					'seo_rolled'       => SeoAdoption::rollback(),
				) );
			},
		) );
	}
}
