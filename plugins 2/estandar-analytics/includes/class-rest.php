<?php
/**
 * REST ingestion endpoint.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EA_REST {

	const MAX_BODY_BYTES   = 65536;
	const MAX_EVENTS       = 30;
	const RATE_LIMIT       = 120;
	const RATE_WINDOW      = 60;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			'ea/v1',
			'/collect',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'collect' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
			)
		);
	}

	public static function permission( $request ) {
		unset( $request );
		return true;
	}

	public static function collect( WP_REST_Request $request ) {
		if ( ! EA_Privacy::should_track() ) {
			return new WP_REST_Response( array( 'ok' => false, 'reason' => 'disabled' ), 200 );
		}

		if ( ! self::check_rate_limit() ) {
			return new WP_REST_Response( array( 'ok' => false, 'reason' => 'rate_limited' ), 429 );
		}

		$raw = (string) $request->get_body();
		if ( strlen( $raw ) > self::MAX_BODY_BYTES ) {
			return new WP_REST_Response( array( 'ok' => false, 'reason' => 'payload_too_large' ), 413 );
		}

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = json_decode( $raw, true );
		}
		if ( ! is_array( $body ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 400 );
		}

		$payload = EA_Privacy::sanitize_payload( $body );
		$session_id = EA_Utils::sanitize_text( $payload['session_id'] ?? '', 64 );
		if ( ! self::is_valid_session_id( $session_id ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 400 );
		}

		if ( ! empty( $payload['events'] ) && is_array( $payload['events'] ) ) {
			$payload['events'] = array_slice( $payload['events'], 0, self::MAX_EVENTS );
		}

		EA_Session::upsert( $payload );

		if ( ! empty( $payload['device'] ) && is_array( $payload['device'] ) ) {
			EA_Session::store_device( $session_id, $payload['device'] );
		}

		if ( ! empty( $payload['pageview'] ) && is_array( $payload['pageview'] ) ) {
			EA_Session::record_pageview( $session_id, $payload['pageview'] );
			EA_Events::maybe_record_conversion( $session_id, 'pageview', $payload['pageview'] );
		}

		if ( ! empty( $payload['events'] ) && is_array( $payload['events'] ) ) {
			EA_Events::record_events( $session_id, $payload['events'] );
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	private static function is_valid_session_id( $session_id ) {
		return (bool) preg_match( '/^[a-zA-Z0-9\-]{8,64}$/', $session_id );
	}

	private static function check_rate_limit() {
		$ip = EA_Utils::get_client_ip();
		if ( $ip === '' ) {
			return true;
		}

		$key   = 'ea_rl_' . md5( $ip );
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_LIMIT ) {
			return false;
		}

		set_transient( $key, $count + 1, self::RATE_WINDOW );
		return true;
	}
}
