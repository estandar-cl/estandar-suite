<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Google Ads Offline Conversion Import (OCI) exporter for Signal Core.
 *
 * Sends click conversions to Google Ads API via REST (v17).
 * Requires a gclid, gbraid, or wbraid on the signal — events without
 * any click ID are silently skipped (not errors).
 *
 * Authentication: OAuth2 refresh_token flow.
 * The refresh_token is generated once (e.g. via Google OAuth Playground)
 * and stored as a wp_option. This class auto-refreshes the access_token
 * before each API call using a WP transient as cache.
 *
 * Required wp_options:
 *   estandar_signal_core_google_developer_token  — From Google Ads API Center
 *   estandar_signal_core_google_client_id        — OAuth2 client ID (Google Cloud Console)
 *   estandar_signal_core_google_client_secret    — OAuth2 client secret
 *   estandar_signal_core_google_refresh_token    — Long-lived refresh token
 *   estandar_signal_core_google_customer_id      — Google Ads account ID (digits only, no dashes)
 *
 * Optional (conversion action resource names per event):
 *   estandar_signal_core_google_conv_lead_created    — customers/{id}/conversionActions/{id}
 *   estandar_signal_core_google_conv_meeting_booked  — customers/{id}/conversionActions/{id}
 *   estandar_signal_core_google_conv_sale_closed     — customers/{id}/conversionActions/{id}
 */
class Estandar_Signal_Core_Google_OCI implements Estandar_Signal_Core_Exporter_Interface {

	const API_VERSION    = 'v17';
	const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
	const ADS_BASE_URL   = 'https://googleads.googleapis.com';
	const TOKEN_TRANSIENT = 'estandar_signal_core_google_access_token';

	private string $developer_token;
	private string $client_id;
	private string $client_secret;
	private string $refresh_token;
	private string $customer_id;

	/** event_name => conversion action resource name */
	private array $conversion_map;

	public function __construct(
		string $developer_token,
		string $client_id,
		string $client_secret,
		string $refresh_token,
		string $customer_id,
		array  $conversion_map = array()
	) {
		$this->developer_token = $developer_token;
		$this->client_id       = $client_id;
		$this->client_secret   = $client_secret;
		$this->refresh_token   = $refresh_token;
		$this->customer_id     = preg_replace( '/[^0-9]/', '', $customer_id ); // strip dashes
		$this->conversion_map  = $conversion_map;
	}

	public function get_id(): string {
		return 'google_ads_oci';
	}

	/**
	 * Only export events that have a configured conversion action.
	 */
	public function supports_event( string $event_name ): bool {
		return isset( $this->conversion_map[ $event_name ] ) &&
		       $this->conversion_map[ $event_name ] !== '';
	}

	/**
	 * Upload a click conversion to Google Ads.
	 *
	 * Phase 2: consumes ONLY the persisted technical contract row from
	 * es_signal_events. It never reads es_leads, never inspects commercial
	 * status/sales_stage, never reconstructs event_name, and applies no business
	 * logic. QA/test exclusion is decided upstream at emission time (a QA lead
	 * never produces an AdvertisingEvent, so it never reaches this row).
	 * The event_name → Google Conversion Action mapping is localized here.
	 *
	 * @param  array $signal Full row from es_signal_events (technical contract).
	 * @return bool          True = exported or skipped (no click ID). False = retry.
	 * @throws \Exception    Permanent error (bad credentials, invalid conversion action, etc.)
	 */
	public function export( array $signal ): bool {
		$event_name       = (string) ( $signal['event_name'] ?? '' );
		$conversion_action = (string) ( $this->conversion_map[ $event_name ] ?? '' );
		if ( $conversion_action === '' ) {
			return true; // No mapping configured — treat as success.
		}

		// ── Click ID: at least one required ──────────────────────────────────────
		$gclid  = (string) ( $signal['gclid']  ?? '' );
		$gbraid = (string) ( $signal['gbraid'] ?? '' );
		$wbraid = (string) ( $signal['wbraid'] ?? '' );

		if ( $gclid === '' && $gbraid === '' && $wbraid === '' ) {
			// No click ID on this lead — organic/direct traffic, cannot attribute.
			// Not an error: mark as exported so it doesn't retry forever.
			return true;
		}

		// ── Conversion datetime ───────────────────────────────────────────────────
		// Google Ads requires format: "yyyy-MM-dd HH:mm:ss+ZZ:ZZ"
		$event_time = (string) ( $signal['event_time'] ?? '' );
		$timestamp  = $event_time !== '' ? strtotime( $event_time ) : time();
		if ( ! $timestamp ) {
			$timestamp = time();
		}
		$conversion_dt = gmdate( 'Y-m-d H:i:s', $timestamp ) . '+00:00';

		// ── Build conversion object ───────────────────────────────────────────────
		$conversion = array(
			'conversionAction'   => $conversion_action,
			'conversionDateTime' => $conversion_dt,
			'orderId'            => (string) ( $signal['signal_uuid'] ?? '' ), // dedup key
		);

		if ( $gclid !== '' ) {
			$conversion['gclid'] = $gclid;
		} elseif ( $gbraid !== '' ) {
			$conversion['gbraid'] = $gbraid;
		} elseif ( $wbraid !== '' ) {
			$conversion['wbraid'] = $wbraid;
		}

		// Value (optional — only for Purchase-equivalent events).
		$value = (float) ( $signal['value_decimal'] ?? 0 );
		if ( $value > 0 ) {
			$conversion['conversionValue'] = $value;
			$conversion['currencyCode']    = (string) ( $signal['currency'] ?: 'CLP' );
		}

		// Enhanced conversions: hashed user data alongside the click.
		$user_identifiers = array();
		$email_hash = (string) ( $signal['email_hash'] ?? '' );
		if ( $email_hash !== '' ) {
			$user_identifiers[] = array( 'hashedEmail' => $email_hash );
		}
		$phone_hash = (string) ( $signal['phone_hash'] ?? '' );
		if ( $phone_hash !== '' ) {
			$user_identifiers[] = array( 'hashedPhoneNumber' => $phone_hash );
		}
		if ( ! empty( $user_identifiers ) ) {
			$conversion['userIdentifiers'] = $user_identifiers;
		}

		// ── Get access token ──────────────────────────────────────────────────────
		$access_token = $this->get_access_token();
		if ( $access_token === '' ) {
			return false; // Could not refresh — transient failure.
		}

		// ── API call ──────────────────────────────────────────────────────────────
		$url = sprintf(
			'%s/%s/customers/%s:uploadClickConversions',
			self::ADS_BASE_URL,
			self::API_VERSION,
			$this->customer_id
		);

		$response = wp_remote_post(
			$url,
			array(
				'body'    => wp_json_encode( array(
					'conversions'    => array( $conversion ),
					'partialFailure' => true,
				) ),
				'headers' => array(
					'Content-Type'    => 'application/json',
					'Authorization'   => 'Bearer ' . $access_token,
					'developer-token' => $this->developer_token,
				),
				'timeout' => 20,
			)
		);

		// ── Response handling ─────────────────────────────────────────────────────
		if ( is_wp_error( $response ) ) {
			return false; // Network error — transient.
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );
		$body_raw  = wp_remote_retrieve_body( $response );
		$decoded   = json_decode( $body_raw, true );

		if ( $http_code === 200 ) {
			// Check for partial failures in the response.
			if ( ! empty( $decoded['partialFailureError'] ) ) {
				$err = (string) ( $decoded['partialFailureError']['message'] ?? 'partial_failure' );
				// Partial failures are typically permanent (bad gclid, expired click, etc.)
				throw new \Exception( "Google Ads OCI partial failure: {$err}" );
			}
			return true;
		}

		// 401 / 403: credentials issue — permanent.
		if ( in_array( $http_code, array( 401, 403 ), true ) ) {
			delete_transient( self::TOKEN_TRANSIENT ); // Force refresh next attempt.
			$api_message = (string) ( $decoded['error']['message'] ?? "HTTP {$http_code}" );
			throw new \Exception( "Google Ads OCI auth error: {$api_message}" );
		}

		// 400: bad request — permanent (invalid conversion action, malformed data).
		if ( $http_code === 400 ) {
			$api_message = (string) ( $decoded['error']['message'] ?? "HTTP 400" );
			throw new \Exception( "Google Ads OCI bad request: {$api_message}" );
		}

		// 5xx or other: transient — retry.
		return false;
	}

	// ── OAuth2 token management ───────────────────────────────────────────────────

	/**
	 * Returns a valid access_token, refreshing it if expired.
	 * Caches in a WP transient (55 minutes — tokens last 60).
	 */
	private function get_access_token(): string {
		$cached = (string) get_transient( self::TOKEN_TRANSIENT );
		if ( $cached !== '' ) {
			return $cached;
		}

		$response = wp_remote_post(
			self::TOKEN_ENDPOINT,
			array(
				'body'    => array(
					'grant_type'    => 'refresh_token',
					'client_id'     => $this->client_id,
					'client_secret' => $this->client_secret,
					'refresh_token' => $this->refresh_token,
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );
		if ( $http_code !== 200 ) {
			return '';
		}

		$body    = json_decode( wp_remote_retrieve_body( $response ), true );
		$token   = (string) ( $body['access_token'] ?? '' );
		$expires = (int) ( $body['expires_in'] ?? 3600 );

		if ( $token !== '' ) {
			// Cache for 55 minutes regardless of actual expiry to stay safe.
			set_transient( self::TOKEN_TRANSIENT, $token, min( $expires - 300, 55 * MINUTE_IN_SECONDS ) );
		}

		return $token;
	}
}
