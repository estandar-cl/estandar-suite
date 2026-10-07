<?php

namespace Estandar\Brain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Rest
{
	const NS = 'estandar-suite/brain/v1';

	/** Rate limit simple por IP: 30 peticiones por minuto. */
	public static function public_rate_ok( string $ip ): bool
	{
		$key  = 'estandar_agent_public_' . md5( $ip );
		$cur  = (int) get_transient( $key );
		if ( $cur >= 30 ) {
			return false;
		}
		set_transient( $key, $cur + 1, MINUTE_IN_SECONDS );
		return true;
	}

	public static function init(): void
	{
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void
	{
		register_rest_route( self::NS, '/score', array(
			'methods'             => 'POST',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback' => static function ( \WP_REST_Request $request ) {
				$in = (array) $request->get_json_params();
				return rest_ensure_response( array( 'score' => Scoring::calculate( $in ) ) );
			},
		) );

		register_rest_route( self::NS, '/signals', array(
			'methods'             => 'GET',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback' => static function ( \WP_REST_Request $request ) {
				return rest_ensure_response( array( 'signals' => SignalLedger::recent( (int) $request->get_param( 'limit' ) ?: 50 ) ) );
			},
		) );

		// ── Herencia v2: adopción signal core legacy (es_signal_*) ─────────
		register_rest_route( self::NS, '/signal/adoption', array(
			'methods'             => 'GET',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => static function () {
				return rest_ensure_response( SignalAdoption::status() );
			},
		) );

		register_rest_route( self::NS, '/signal/adoption/import', array(
			'methods'             => 'POST',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => static function ( \WP_REST_Request $request ) {
				$dry   = empty( $request->get_param( 'commit' ) );
				$limit = max( 10, (int) $request->get_param( 'limit' ) ?: 100 );
				return rest_ensure_response( SignalAdoption::import( $dry, $limit ) + array( 'commit' => ! $dry ) );
			},
		) );

		register_rest_route( self::NS, '/signal/adoption/rollback', array(
			'methods'             => 'POST',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => static function () {
				return rest_ensure_response( array( 'rolled' => SignalAdoption::rollback() ) );
			},
		) );

		register_rest_route( self::NS, '/agent', array(
			'methods'             => 'POST',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback' => static function ( \WP_REST_Request $request ) {
				if ( ! Agent::activation( 'agent_internal' ) ) {
					return new \WP_Error( 'disabled', 'Agente interno desactivado.', array( 'status' => 403 ) );
				}
				$in         = (array) $request->get_json_params();
				$text       = trim( (string) ( $in['request'] ?? '' ) );
				if ( $text === '' ) {
					return rest_ensure_response( array( 'reply' => 'Escribe una petición en lenguaje natural.' ) );
				}
				$run = Agent::run( $text, array(
					'confirm'     => ! empty( $in['confirm'] ),
					'destructive' => ! empty( $in['destructive'] ),
				) );
				$need = false;
				foreach ( $run['results'] as $r ) {
					if ( ! empty( $r['need_confirm'] ) ) {
						$need = true;
					}
				}
				return rest_ensure_response( array(
					'reply'        => Agent::respond( $run ),
					'results'      => $run['results'],
					'need_confirm' => $need,
				) );
			},
		) );

		register_rest_route( self::NS, '/agent-public', array(
			'methods'             => 'POST',
			'permission_callback' => '__return_true', // público, pero limitado por IP.
			'callback'            => static function ( \WP_REST_Request $request ) {
				if ( ! Agent::activation( 'agent_public' ) ) {
					return new \WP_Error( 'disabled', 'Agente público desactivado.', array( 'status' => 403 ) );
				}
				$ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? 'cli' );
				if ( ! self::public_rate_ok( $ip ) ) {
					return new \WP_Error( 'rate_limited', 'Demasiadas solicitudes. Intenta en un minuto.', array( 'status' => 429 ) );
				}
				$in      = (array) $request->get_json_params();
				$message = trim( (string) ( $in['message'] ?? '' ) );
				if ( $message === '' ) {
					return new \WP_Error( 'empty', 'Escribe un mensaje.', array( 'status' => 400 ) );
				}
				$message = function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 500 ) : substr( $message, 0, 500 );
				$run     = Agent::run( $message, array( 'profile' => 'public' ) );
				$reply   = '';
				$actions = array();
				$sources = array();
				foreach ( $run['results'] as $r ) {
					if ( ! empty( $r['message'] ) ) {
						$reply = (string) $r['message'];
					}
					if ( ! empty( $r['actions'] ) && is_array( $r['actions'] ) ) {
						$actions = array_merge( $actions, $r['actions'] );
					}
					if ( ! empty( $r['sources'] ) && is_array( $r['sources'] ) ) {
						$sources = array_merge( $sources, $r['sources'] );
					}
				}
				if ( $reply === '' ) {
					$reply = 'No entendí tu mensaje. Pregúntame por la información del sitio o pídeme abrir WhatsApp.';
				}
				return rest_ensure_response( array(
					'reply'   => $reply,
					'actions' => $actions,
					'sources' => $sources,
					'profile' => 'public',
				) );
			},
		) );

		register_rest_route( self::NS, '/rules', array(
			'methods'             => 'POST',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback' => static function ( \WP_REST_Request $request ) {
				$in = (array) $request->get_json_params();
				$rules = Rules::load( (string) ( $in['project'] ?? '' ) );
				return rest_ensure_response( array(
					'matched' => array_keys( $rules ? Rules::evaluate( (array) ( $in['input'] ?? array() ), $rules )['sources'] : array() ),
					'action'  => Rules::evaluate( (array) ( $in['input'] ?? array() ), $rules )['action'],
				) );
			},
		) );
	}
}
