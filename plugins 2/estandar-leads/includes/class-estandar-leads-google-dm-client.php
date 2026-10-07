<?php
/**
 * Google Data Manager API client (transport only) for Estandar Leads.
 *
 * Sole responsibility: send already-built Event resources to the Data Manager
 * API and return a normalized response array. It builds nothing, decides
 * nothing, persists nothing beyond the short-lived access-token transient.
 *
 * Transport contract — this class NEVER: updates queue rows, changes lead
 * status, writes telemetry/logs, mutates payloads, retries, decides business
 * logic, or touches $wpdb. Failures are returned as data, never thrown.
 * Secrets (client_secret, refresh_token, access_token) never appear in any
 * returned message or body passed upward.
 *
 * Auth: OAuth 2.0 refresh-token grant (installed-app / web flow credentials).
 * Scope required on the refresh token: https://www.googleapis.com/auth/datamanager
 * No developer token is needed — that is a Google Ads API concept; the Data
 * Manager API only needs OAuth.
 *
 * @package EstandarLeads
 * @since   13.0.59
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Estandar_Leads_Google_DM_Client {

	const OPTION_PREFIX = 'estandar_leads_google_';

	/** Centralized endpoints — never duplicate these strings. */
	private const API_BASE  = 'https://datamanager.googleapis.com/v1';
	private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

	private const TOKEN_TRANSIENT = 'estandar_leads_google_dm_token';
	private const TIMEOUT         = 15;

	/**
	 * Send a batch of prepared Event resources to events:ingest.
	 *
	 * @param array $events         Already-built Event resources (≤ 2000 per spec).
	 * @param bool  $validate_only  true → Google validates without recording (dry-run).
	 * @return array { success, temporary, configured, http_code, message, body, request_id }
	 */
	public static function ingest( array $events, $validate_only = true ) {
		if ( empty( $events ) ) {
			return self::response( true, false, true, 0, 'No events to send.', '', '' );
		}

		$destination = self::destination();
		if ( empty( $destination ) ) {
			return self::response( false, false, false, 0, 'Google Data Manager not configured.', '', '' );
		}

		$auth = self::access_token();
		if ( $auth['token'] === '' ) {
			// Auth failure is temporary only when the token endpoint itself was
			// unreachable; invalid_grant / bad credentials are permanent.
			return self::response( false, $auth['temporary'], true, $auth['http_code'], $auth['message'], '', '' );
		}

		$body = array(
			'destinations' => array( $destination ),
			'encoding'     => 'HEX',
			'events'       => array_values( $events ),
			'validateOnly' => (bool) $validate_only,
		);

		$response = wp_remote_post( self::API_BASE . '/events:ingest', array(
			'timeout' => self::TIMEOUT,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $auth['token'],
			),
			'body'    => wp_json_encode( $body ),
		) );

		if ( is_wp_error( $response ) ) {
			return self::response( false, true, true, 0, $response->get_error_message(), '', '' );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );

		if ( $code === 200 ) {
			$data       = json_decode( $raw, true );
			$request_id = is_array( $data ) ? (string) ( $data['requestId'] ?? '' ) : '';
			return self::response( true, false, true, 200, $validate_only ? 'validateOnly OK' : 'OK', $raw, $request_id );
		}

		// 401 → token likely stale/revoked: drop the cache so the next attempt
		// re-authenticates; classified temporary (one retry re-auths cleanly).
		if ( $code === 401 ) {
			delete_transient( self::TOKEN_TRANSIENT );
			return self::response( false, true, true, 401, self::extract_message( $raw, 401 ), $raw, '' );
		}

		// 429 (rate limit) and 5xx are transient; remaining 4xx are permanent.
		$temporary = ( $code === 429 || $code >= 500 );

		return self::response( false, $temporary, true, $code, self::extract_message( $raw, $code ), $raw, '' );
	}

	/**
	 * Retrieve processing diagnostics for a previous ingest (reconciliation).
	 *
	 * @param string $request_id  requestId returned by a non-validateOnly ingest.
	 * @return array { success, temporary, configured, http_code, message, body, request_id }
	 */
	public static function request_status( $request_id ) {
		$request_id = trim( (string) $request_id );
		if ( $request_id === '' ) {
			return self::response( false, false, true, 0, 'Missing request_id.', '', '' );
		}

		$auth = self::access_token();
		if ( $auth['token'] === '' ) {
			return self::response( false, $auth['temporary'], true, $auth['http_code'], $auth['message'], '', $request_id );
		}

		$response = wp_remote_get(
			self::API_BASE . '/requestStatus:retrieve?requestId=' . rawurlencode( $request_id ),
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array( 'Authorization' => 'Bearer ' . $auth['token'] ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::response( false, true, true, 0, $response->get_error_message(), '', $request_id );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );

		if ( $code === 200 ) {
			return self::response( true, false, true, 200, 'OK', $raw, $request_id );
		}
		return self::response( false, ( $code === 429 || $code >= 500 ), true, $code, self::extract_message( $raw, $code ), $raw, $request_id );
	}

	// ── OAuth (refresh-token grant, cached access token) ─────────────────────────

	/**
	 * Resolve a live access token. Cached in a transient until ~60s before
	 * expiry. Returns { token, temporary, http_code, message } — token '' on
	 * failure. The refresh token and client secret never leave this method.
	 */
	private static function access_token() {
		if ( class_exists( 'Estandar_Google_Integration_Hub' ) ) {
			$hub = Estandar_Google_Integration_Hub::get_access_token( 'data_manager' );
			if ( ! empty( $hub['token'] ) ) { return array( 'token' => $hub['token'], 'temporary' => false, 'http_code' => 200, 'message' => '' ); }
		}
		$cached = get_transient( self::TOKEN_TRANSIENT );
		if ( is_string( $cached ) && $cached !== '' ) {
			return array( 'token' => $cached, 'temporary' => false, 'http_code' => 0, 'message' => '' );
		}

		$client_id     = self::option( 'oauth_client_id' );
		$client_secret = self::option( 'oauth_client_secret' );
		$refresh_token = self::option( 'oauth_refresh_token' );

		if ( $client_id === '' || $client_secret === '' || $refresh_token === '' ) {
			return array( 'token' => '', 'temporary' => false, 'http_code' => 0, 'message' => 'OAuth credentials not configured.' );
		}

		$response = wp_remote_post( self::TOKEN_URL, array(
			'timeout' => self::TIMEOUT,
			'body'    => array(
				'grant_type'    => 'refresh_token',
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
				'refresh_token' => $refresh_token,
			),
		) );

		if ( is_wp_error( $response ) ) {
			return array( 'token' => '', 'temporary' => true, 'http_code' => 0, 'message' => 'Token endpoint unreachable: ' . $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		$token = is_array( $data ) ? trim( (string) ( $data['access_token'] ?? '' ) ) : '';
		if ( $code === 200 && $token !== '' ) {
			$expires = is_array( $data ) ? (int) ( $data['expires_in'] ?? 3600 ) : 3600;
			set_transient( self::TOKEN_TRANSIENT, $token, max( 60, $expires - 60 ) );
			return array( 'token' => $token, 'temporary' => false, 'http_code' => 200, 'message' => '' );
		}

		// Sanitized: only the OAuth error CODE (e.g. invalid_grant) is surfaced —
		// never the raw body, which could echo credential fragments.
		$oauth_error = is_array( $data ) ? (string) ( $data['error'] ?? '' ) : '';
		return array(
			'token'     => '',
			'temporary' => $code >= 500,
			'http_code' => $code,
			'message'   => 'OAuth token exchange failed' . ( $oauth_error !== '' ? ' (' . $oauth_error . ')' : ' (HTTP ' . $code . ')' ),
		);
	}

	// ── Internal helpers ─────────────────────────────────────────────────────────

	/**
	 * Destination block from saved settings. Empty array when the destination
	 * is not fully configured (missing CID or conversion action).
	 */
	private static function destination() {
		$customer_id          = preg_replace( '/\D+/', '', self::option( 'customer_id' ) );
		$conversion_action_id = preg_replace( '/\D+/', '', self::option( 'conversion_action_id' ) );

		if ( $customer_id === '' || $conversion_action_id === '' ) {
			return array();
		}

		$destination = array(
			'operatingAccount'     => array(
				'accountType' => 'GOOGLE_ADS',
				'accountId'   => $customer_id,
			),
			'productDestinationId' => $conversion_action_id,
		);

		$login_id = preg_replace( '/\D+/', '', self::option( 'login_customer_id' ) );
		if ( $login_id !== '' ) {
			$destination['loginAccount'] = array(
				'accountType' => 'GOOGLE_ADS',
				'accountId'   => $login_id,
			);
		}

		return $destination;
	}

	/**
	 * Pull a human message out of a Google error body, or fall back to the code.
	 * google.rpc.Status shape: { "error": { "code", "message", "status" } }.
	 */
	private static function extract_message( $raw, $code ) {
		$data = json_decode( (string) $raw, true );
		if ( is_array( $data ) && isset( $data['error']['message'] ) && $data['error']['message'] !== '' ) {
			return substr( (string) $data['error']['message'], 0, 300 );
		}
		return 'HTTP ' . (int) $code;
	}

	private static function option( $key ) {
		return (string) get_option( self::OPTION_PREFIX . $key, '' );
	}

	private static function response( $success, $temporary, $configured, $http_code, $message, $body, $request_id ) {
		return array(
			'success'    => (bool) $success,
			'temporary'  => (bool) $temporary,
			'configured' => (bool) $configured,
			'http_code'  => (int) $http_code,
			'message'    => (string) $message,
			'body'       => (string) $body,
			'request_id' => (string) $request_id,
		);
	}
}
