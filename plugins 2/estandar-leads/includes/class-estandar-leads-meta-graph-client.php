<?php
/**
 * Meta Graph API client (transport only) for Estandar Leads.
 *
 * Sole responsibility: send an already-built event payload to the Meta
 * Conversions API and return a normalized response array. It builds nothing,
 * decides nothing, persists nothing.
 *
 * Transport contract — this class NEVER: updates queue rows, updates attempts,
 * changes lead status, writes telemetry/logs, mutates payloads, retries,
 * decides business logic, or touches the database. It only performs one HTTP
 * POST and normalizes the outcome. Failures are returned as data, never thrown.
 *
 * WordPress surface (intentionally minimal): get_option() + wp_remote_post()
 * and the wp_remote_retrieve_* helpers. No $wpdb, no hooks, no cron.
 *
 * @package EstandarLeads
 * @since   10.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Estandar_Leads_Meta_Graph_Client {

	const OPTION_PREFIX = 'estandar_leads_meta_';

	/** Centralized Graph API version — never duplicate this string. */
	private const API_VERSION = 'v23.0';

	/** Request timeout, consistent with OCI's upload convention. */
	private const TIMEOUT = 15;

	/**
	 * Send one prepared event payload to the Meta Conversions API.
	 *
	 * @param array       $payload   A single, already-built Meta event (associative).
	 * @param string|null $event_id  Optional upstream event identifier (for dedup).
	 * @return array { success:bool, temporary:bool, configured:bool, http_code:int, message:string, body:string }
	 */
	public static function send( array $payload, ?string $event_id = null ) {
		$pixel_id     = self::option( 'pixel_id' );
		$access_token = self::option( 'access_token' );

		if ( $pixel_id === '' || $access_token === '' ) {
			return self::response( false, false, false, 0, 'Meta adapter not configured.', '' );
		}

		// Wrap the single event as { "data": [ event ] }. Work on a local copy —
		// the incoming payload is never mutated.
		$event = $payload;
		if ( $event_id !== null && $event_id !== '' ) {
			$event['event_id'] = $event_id;
		}

		$body = array(
			'data'         => array( $event ),
			// AUTH NOTE (evaluated 2026-07): Meta also accepts `Authorization:
			// Bearer <token>`. Kept as a body param deliberately — it is the
			// officially documented CAPI form, works on every install today,
			// and moving it to a header buys no functional gain while risking
			// proxies/WAFs that strip Authorization headers. Re-evaluate only
			// if Meta deprecates the parameter.
			'access_token' => $access_token,
		);

		// Test Event Code: when configured, every send is routed to Events
		// Manager → Test Events. Top-level request field, sibling of `data`
		// (per Meta CAPI spec) — never inside the event payload itself.
		$test_event_code = self::option( 'test_event_code' );
		if ( $test_event_code !== '' ) {
			$body['test_event_code'] = $test_event_code;
		}

		$response = wp_remote_post( self::endpoint( $pixel_id ), array(
			'timeout' => self::TIMEOUT,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $body ),
		) );

		// Transport-level failure (timeout, DNS, connection refused) → retryable.
		if ( is_wp_error( $response ) ) {
			return self::response( false, true, true, 0, $response->get_error_message(), '' );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );

		if ( $code === 200 ) {
			return self::response( true, false, true, 200, 'OK', $raw );
		}

		// 429 (rate limit) and 5xx are transient; 4xx (400/401/403/invalid) are not.
		$temporary = ( $code === 429 || $code >= 500 );

		return self::response( false, $temporary, true, $code, self::extract_message( $raw, $code ), $raw );
	}

	// ── Internal helpers ─────────────────────────────────────────────────────────

	/**
	 * Build the events endpoint from the centralized API version.
	 */
	private static function endpoint( $pixel_id ) {
		return sprintf(
			'https://graph.facebook.com/%s/%s/events',
			self::API_VERSION,
			rawurlencode( (string) $pixel_id )
		);
	}

	/**
	 * Pull a human message out of a Meta error body, or fall back to the code.
	 */
	private static function extract_message( $raw, $code ) {
		$data = json_decode( (string) $raw, true );
		if ( is_array( $data ) && isset( $data['error']['message'] ) && $data['error']['message'] !== '' ) {
			return (string) $data['error']['message'];
		}
		return 'HTTP ' . (int) $code;
	}

	/**
	 * Read a Meta option (read-only). Never writes or creates settings.
	 */
	private static function option( $key ) {
		return (string) get_option( self::OPTION_PREFIX . $key, '' );
	}

	/**
	 * The single normalized response shape — the only thing this class returns.
	 */
	private static function response( $success, $temporary, $configured, $http_code, $message, $body ) {
		return array(
			'success'    => (bool) $success,
			'temporary'  => (bool) $temporary,
			'configured' => (bool) $configured,
			'http_code'  => (int) $http_code,
			'message'    => (string) $message,
			'body'       => (string) $body,
		);
	}
}
