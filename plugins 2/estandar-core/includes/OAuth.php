<?php

namespace Estandar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generic OAuth2 provider registry + flows. NOT coupled to Google/Microsoft:
 * a provider is a URL template + token endpoint + scope separator. Supports
 * multiple tenants/orgs/accounts per install (per-connection params override).
 * Scopes come from the calling adapter — never hardcoded here.
 *
 * Authorize flows are pure (URL building is testable); token exchange/refresh
 * use wp_remote_post and are guarded so tests run without HTTP.
 */
class OAuth
{
	const APPS_OPTION = 'estandar_suite_core_oauth_apps';

	/** @var array<string,array> provider key → definition */
	private static $defs = array();

	/** Register (or override) a provider definition. */
	public static function register_provider( string $key, array $def ): void
	{
		self::$defs[ $key ] = array_merge( array(
			'label'        => $key,
			'authorize'    => '',
			'token'        => '',
			'scope_sep'    => ' ',
			'refresh'      => false,
			'auth_header'  => false, // send client_id/secret as Basic auth header
			'client_in_body' => true, // else client_id/secret in form body
		), $def );
	}

	/** Registered provider keys. */
	public static function providers(): array
	{
		return array_keys( self::$defs );
	}

	/** Provider definition or null. */
	public static function def( string $key ): ?array
	{
		return self::$defs[ $key ] ?? null;
	}

	/** App credentials per provider (option) — per-connection overrides allowed. */
	public static function app_config( string $provider ): array
	{
		$apps = get_option( self::APPS_OPTION, array() );
		$cfg  = is_array( $apps ) && is_array( $apps[ $provider ] ?? null ) ? $apps[ $provider ] : array();
		return $cfg;
	}

	/** Persist app credentials for a provider (client_id/secret/redirect). */
	public static function save_app_config( string $provider, array $cfg ): void
	{
		$apps        = get_option( self::APPS_OPTION, array() );
		$apps        = is_array( $apps ) ? $apps : array();
		$apps[ $provider ] = array(
			'client_id'     => sanitize_text_field( (string) ( $cfg['client_id'] ?? '' ) ),
			'client_secret' => sanitize_text_field( (string) ( $cfg['client_secret'] ?? '' ) ),
			'redirect_uri'  => esc_url_raw( (string) ( $cfg['redirect_uri'] ?? '' ) ),
		);
		update_option( self::APPS_OPTION, $apps, false );
	}

	// ── OAuth state (CSRF) ─────────────────────────────────────────────────

	const STATE_TTL = 600;

	/** Transient key for a state value (hashed, never the raw state). */
	public static function state_key( string $state ): string
	{
		return 'estandar_oauth_state_' . hash( 'sha256', $state );
	}

	/**
	 * Begin an authorization: store a state bound to provider+user and return it.
	 * The returned state is passed in authorize_url() and must be echoed back by
	 * the provider on the callback.
	 */
	public static function begin_state( string $provider, array $meta = array() ): string
	{
		if ( ! function_exists( 'set_transient' ) ) {
			return '';
		}
		$state = bin2hex( random_bytes( 16 ) );
		set_transient( self::state_key( $state ), wp_json_encode( array_merge( array(
			'provider' => sanitize_key( $provider ),
			'user_id'  => (int) ( $meta['user_id'] ?? 0 ),
			'label'    => sanitize_text_field( (string) ( $meta['label'] ?? '' ) ),
		), array_filter( $meta, static function ( $k ) {
			return in_array( $k, array( 'label', 'scopes', 'tenant', 'adapter' ), true );
		}, ARRAY_FILTER_USE_KEY ) ) ), self::STATE_TTL );
		return $state;
	}

	/** Consume a state (validates + removes). Returns stored meta or null. */
	public static function consume_state( string $state ): ?array
	{
		if ( ! function_exists( 'get_transient' ) || ! function_exists( 'delete_transient' ) || $state === '' ) {
			return null;
		}
		$raw = get_transient( self::state_key( $state ) );
		if ( ! is_string( $raw ) || $raw === '' ) {
			return null;
		}
		delete_transient( self::state_key( $state ) );
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : null;
	}

	/** REST callback URL for the built-in OAuth callback endpoint. */
	public static function callback_url(): string
	{
		return function_exists( 'rest_url' ) ? rest_url( 'estandar-suite/core/v1/oauth/callback' ) : '';
	}

	/**
	 * PURE: substitute {placeholders} in a URL template from $params.
	 */
	public static function template( string $url, array $params ): string
	{
		foreach ( $params as $k => $v ) {
			$url = str_replace( '{' . $k . '}', rawurlencode( (string) $v ), $url );
		}
		return $url;
	}

	/**
	 * PURE: build an authorization URL.
	 *
	 * @param string $provider provider key
	 * @param array  $client   {client_id, redirect_uri} (+ optional per-connection overrides)
	 * @param string $state    signed CSRF state
	 * @param array  $scopes   scopes requested by the adapter
	 * @param array  $extra    per-connection params (tenant, org, prompt, access_type, ...)
	 */
	public static function authorize_url( string $provider, array $client, string $state, array $scopes, array $extra = array() ): string
	{
		$def = self::def( $provider );
		if ( ! $def || empty( $def['authorize'] ) || empty( $client['client_id'] ) || $state === '' ) {
			return '';
		}
		$params = array_merge( array(
			'client_id'     => (string) $client['client_id'],
			'redirect_uri'  => (string) ( $client['redirect_uri'] ?? '' ),
			'response_type' => 'code',
			'state'         => $state,
			'scope'         => implode( $def['scope_sep'], array_values( array_unique( array_filter( $scopes ) ) ) ),
			'access_type'   => 'offline',
			'prompt'        => 'consent',
		), $extra );
		return self::template( $def['authorize'], $extra ) . '?' . http_build_query( $params );
	}

	/** PURE: token endpoint URL for a provider (+ per-connection params). */
	public static function token_url( string $provider, array $extra = array() ): string
	{
		$def = self::def( $provider );
		if ( ! $def ) {
			return '';
		}
		return self::template( $def['token'], $extra );
	}

	/**
	 * Exchange an authorization code for tokens. Returns:
	 * array{ok:bool, tokens:array, error:string}
	 */
	public static function exchange( string $provider, string $code, string $redirect_uri, array $client = array(), array $extra = array() ): array
	{
		if ( ! function_exists( 'wp_remote_post' ) ) {
			return array( 'ok' => false, 'tokens' => array(), 'error' => 'entorno sin HTTP' );
		}
		$def = self::def( $provider );
		if ( ! $def || $code === '' ) {
			return array( 'ok' => false, 'tokens' => array(), 'error' => 'proveedor o código inválido' );
		}
		$url   = self::token_url( $provider, $extra );
		$body  = array(
			'grant_type'   => 'authorization_code',
			'code'         => $code,
			'redirect_uri' => $redirect_uri,
			'client_id'    => (string) ( $client['client_id'] ?? '' ),
		);
		if ( ! empty( $def['client_in_body'] ) ) {
			$body['client_secret'] = (string) ( $client['client_secret'] ?? '' );
		}
		return self::request_tokens( $url, $body, $def, $client );
	}

	/** Refresh an access token with a refresh token. */
	public static function refresh( string $provider, string $refresh_token, array $client = array(), array $extra = array() ): array
	{
		if ( ! function_exists( 'wp_remote_post' ) ) {
			return array( 'ok' => false, 'tokens' => array(), 'error' => 'entorno sin HTTP' );
		}
		$def = self::def( $provider );
		if ( ! $def || $refresh_token === '' ) {
			return array( 'ok' => false, 'tokens' => array(), 'error' => 'proveedor o refresh token inválido' );
		}
		$url  = self::token_url( $provider, $extra );
		$body = array(
			'grant_type'    => 'refresh_token',
			'refresh_token' => $refresh_token,
			'client_id'     => (string) ( $client['client_id'] ?? '' ),
		);
		if ( ! empty( $def['client_in_body'] ) ) {
			$body['client_secret'] = (string) ( $client['client_secret'] ?? '' );
		}
		return self::request_tokens( $url, $body, $def, $client );
	}

	/** PURE: normalize a token response into a stable shape. */
	public static function normalize_tokens( array $raw ): array
	{
		$tokens = array();
		if ( isset( $raw['access_token'] ) ) {
			$tokens['access_token'] = (string) $raw['access_token'];
		}
		if ( ! empty( $raw['refresh_token'] ) ) {
			$tokens['refresh_token'] = (string) $raw['refresh_token'];
		}
		if ( ! empty( $raw['id_token'] ) ) {
			$tokens['id_token'] = (string) $raw['id_token'];
		}
		if ( isset( $raw['expires_in'] ) ) {
			$tokens['expires_in'] = (int) $raw['expires_in'];
		}
		if ( ! empty( $raw['scope'] ) ) {
			$tokens['scope'] = (string) $raw['scope'];
		}
		return $tokens;
	}

	/** Shared POST to a token endpoint with proper auth placement. */
	private static function request_tokens( string $url, array $body, array $def, array $client ): array
	{
		$headers = array( 'Content-Type' => 'application/x-www-form-urlencoded' );
		if ( ! empty( $def['auth_header'] ) ) {
			$headers['Authorization'] = 'Basic ' . base64_encode( (string) ( $client['client_id'] ?? '' ) . ':' . (string) ( $client['client_secret'] ?? '' ) );
		}
		$resp = wp_remote_post( $url, array(
			'body'    => http_build_query( $body ),
			'headers' => $headers,
			'timeout' => 20,
		) );
		if ( is_wp_error( $resp ) ) {
			return array( 'ok' => false, 'tokens' => array(), 'error' => $resp->get_error_message() );
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		$data = is_array( $data ) ? $data : array();
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( $code < 200 || $code >= 300 || empty( $data['access_token'] ) ) {
			return array( 'ok' => false, 'tokens' => array(), 'error' => (string) ( $data['error_description'] ?? $data['error'] ?? ( 'HTTP ' . $code ) ) );
		}
		return array( 'ok' => true, 'tokens' => self::normalize_tokens( $data ), 'error' => '' );
	}

	/** Register the built-in providers (google, microsoft). */
	public static function init(): void
	{
		self::register_provider( 'google', array(
			'label'      => 'Google',
			'authorize'  => 'https://accounts.google.com/o/oauth2/v2/auth',
			'token'      => 'https://oauth2.googleapis.com/token',
			'scope_sep'  => ' ',
			'refresh'    => true,
			'client_in_body' => true,
		) );
		self::register_provider( 'microsoft', array(
			'label'      => 'Microsoft',
			'authorize'  => 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/authorize',
			'token'      => 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token',
			'scope_sep'  => ' ',
			'refresh'    => true,
			'client_in_body' => true,
		) );
		self::register_provider( 'sandbox', array(
			'label'      => 'Sandbox (sin OAuth)',
			'authorize'  => '',
			'token'      => '',
			'scope_sep'  => ' ',
			'refresh'    => false,
			'client_in_body' => true,
		) );
	}
}
