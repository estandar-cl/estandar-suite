<?php

namespace Estandar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Connection registry — one OAuth account per row (multiple tenants/orgs/
 * accounts per install are supported via provider_config_json). Tokens are
 * AES-256-GCM at rest and never returned by the read API (only masked).
 */
class Connections
{
	const TABLE = 'estandar_core_connections';

	public static function register( string $provider, string $label, array $tokens, array $scopes = array(), array $provider_config = array() ): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || ! OAuth::def( $provider ) || empty( $tokens['access_token'] ) ) {
			return 0;
		}
		$stored = Crypto::encrypt( wp_json_encode( $tokens, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		if ( $stored === '' ) {
			return 0;
		}
		$expires = ! empty( $tokens['expires_in'] ) ? gmdate( 'Y-m-d H:i:s', time() + (int) $tokens['expires_in'] ) : null;
		$wpdb->insert( $wpdb->prefix . self::TABLE, array(
			'provider'            => sanitize_key( $provider ),
			'label'               => sanitize_text_field( $label ),
			'status'              => 'active',
			'tokens_encrypted'    => $stored,
			'scopes_json'         => wp_json_encode( array_values( array_filter( array_map( 'sanitize_text_field', (array) $scopes ) ) ) ),
			'provider_config_json' => wp_json_encode( (array) $provider_config ),
			'expires_at'          => $expires,
			'created_at'          => gmdate( 'Y-m-d H:i:s' ),
			'updated_at'          => gmdate( 'Y-m-d H:i:s' ),
		), array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
		return (int) $wpdb->insert_id;
	}

	/** Internal row with decrypted tokens (NEVER expose this array outside). */
	public static function get( int $id ): ?array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $id <= 0 ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}" . self::TABLE . " WHERE id = %d LIMIT 1", $id
		), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		$tokens = json_decode( (string) Crypto::decrypt( (string) ( $row['tokens_encrypted'] ?? '' ) ), true );
		$row['tokens']   = is_array( $tokens ) ? $tokens : array();
		$row['provider_config'] = json_decode( (string) ( $row['provider_config_json'] ?? '{}' ), true );
		$row['provider_config'] = is_array( $row['provider_config'] ) ? $row['provider_config'] : array();
		return $row;
	}

	/** Read-only list with tokens masked (never decrypted). */
	public static function list( string $provider = '' ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}
		$sql = "SELECT id, provider, label, status, scopes_json, provider_config_json, expires_at, created_at, updated_at
		        FROM {$wpdb->prefix}" . self::TABLE;
		if ( $provider !== '' ) {
			$sql .= $wpdb->prepare( ' WHERE provider = %s', sanitize_key( $provider ) );
		}
		$sql .= ' ORDER BY id DESC LIMIT 200';
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$r['provider_config'] = json_decode( (string) ( $r['provider_config_json'] ?? '{}' ), true );
			$r['provider_config'] = is_array( $r['provider_config'] ) ? $r['provider_config'] : array();
			$out[] = $r;
		}
		return $out;
	}

	/** Mark a connection revoked (disconnect). Returns rows affected. */
	public static function revoke( int $id ): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $id <= 0 ) {
			return 0;
		}
		return (int) $wpdb->update( $wpdb->prefix . self::TABLE,
			array( 'status' => 'revoked', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ),
			array( 'id' => $id ), array( '%s', '%s' ), array( '%d' ) );
	}

	/** Re-activate a connection after re-authorization. */
	public static function reactivate( int $id ): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $id <= 0 ) {
			return 0;
		}
		return (int) $wpdb->update( $wpdb->prefix . self::TABLE,
			array( 'status' => 'active', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ),
			array( 'id' => $id ), array( '%s', '%s' ), array( '%d' ) );
	}

	/** Status resolution: revoked / expired / active (expiry-aware). */
	public static function status( int $id ): string
	{
		$row = self::get( $id );
		if ( ! $row ) {
			return 'missing';
		}
		if ( $row['status'] === 'revoked' ) {
			return 'revoked';
		}
		if ( ! empty( $row['expires_at'] ) && strtotime( (string) $row['expires_at'] ) < time() ) {
			return 'expired';
		}
		return 'active';
	}

	/** Persist refreshed tokens. Returns rows affected. */
	public static function update_tokens( int $id, array $tokens ): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $id <= 0 ) {
			return 0;
		}
		$current = self::get( $id );
		$merged  = is_array( $current['tokens'] ?? null ) ? array_merge( $current['tokens'], $tokens ) : $tokens;
		if ( empty( $merged['refresh_token'] ) ) {
			unset( $merged['refresh_token'] );
		}
		$stored = Crypto::encrypt( wp_json_encode( $merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		if ( $stored === '' ) {
			return 0;
		}
		$expires = ! empty( $merged['expires_in'] ) ? gmdate( 'Y-m-d H:i:s', time() + (int) $merged['expires_in'] ) : null;
		return (int) $wpdb->update( $wpdb->prefix . self::TABLE,
			array( 'tokens_encrypted' => $stored, 'expires_at' => $expires, 'status' => 'active', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ),
			array( 'id' => $id ), array( '%s', '%s', '%s', '%s' ), array( '%d' ) );
	}

	/** Client credentials for a connection (app config + per-connection overrides). */
	public static function client_config( array $row ): array
	{
		$app  = OAuth::app_config( $row['provider'] );
		$cfg  = is_array( $row['provider_config'] ?? null ) ? $row['provider_config'] : array();
		return array_merge( array(
			'client_id'     => (string) ( $app['client_id'] ?? '' ),
			'client_secret' => (string) ( $app['client_secret'] ?? '' ),
			'redirect_uri'  => (string) ( $app['redirect_uri'] ?? '' ),
		), array(
			'client_id'     => (string) ( $cfg['client_id'] ?? ( $app['client_id'] ?? '' ) ),
			'client_secret' => (string) ( $cfg['client_secret'] ?? ( $app['client_secret'] ?? '' ) ),
			'redirect_uri'  => (string) ( $cfg['redirect_uri'] ?? ( $app['redirect_uri'] ?? '' ) ),
		) );
	}

	/** Refresh tokens if the access token is near expiry (or always). Returns {ok, changed, error}. */
	public static function refresh_if_needed( int $id, int $leeway_seconds = 120 ): array
	{
		$row = self::get( $id );
		if ( ! $row ) {
			return array( 'ok' => false, 'changed' => false, 'error' => 'conexión no encontrada' );
		}
		if ( ! empty( $row['expires_at'] ) && strtotime( (string) $row['expires_at'] ) > time() + $leeway_seconds ) {
			return array( 'ok' => true, 'changed' => false, 'error' => '' );
		}
		$refresh_token = (string) ( $row['tokens']['refresh_token'] ?? '' );
		if ( $refresh_token === '' ) {
			return array( 'ok' => false, 'changed' => false, 'error' => 'sin refresh token (requiere reconexión)' );
		}
		$extra = is_array( $row['provider_config'] ?? null ) ? $row['provider_config'] : array();
		$res   = OAuth::refresh( $row['provider'], $refresh_token, self::client_config( $row ), $extra );
		if ( empty( $res['ok'] ) || empty( $res['tokens'] ) ) {
			return array( 'ok' => false, 'changed' => false, 'error' => $res['error'] ?? 'refresh fallido' );
		}
		self::update_tokens( $id, $res['tokens'] );
		return array( 'ok' => true, 'changed' => true, 'error' => '' );
	}

	/** Fresh access token for an outbound call (refreshes when needed). */
	public static function access_token( int $id ): array
	{
		$row = self::get( $id );
		if ( ! $row ) {
			return array( 'ok' => false, 'token' => '', 'error' => 'conexión no encontrada' );
		}
		if ( empty( $row['tokens']['access_token'] ) ) {
			return array( 'ok' => false, 'token' => '', 'error' => 'sin access token' );
		}
		$refreshed = self::refresh_if_needed( $id );
		if ( empty( $refreshed['ok'] ) ) {
			return array( 'ok' => false, 'token' => '', 'error' => $refreshed['error'] ?? 'refresh fallido' );
		}
		$row = self::get( $id );
		return array( 'ok' => true, 'token' => (string) ( $row['tokens']['access_token'] ?? '' ), 'error' => '' );
	}

	/** Mask a token string for display: "Ab12…xyz4". */
	public static function mask_token( string $token ): string
	{
		if ( $token === '' ) {
			return '';
		}
		$head = substr( $token, 0, 4 );
		$tail = substr( $token, -4 );
		return $head . '…' . $tail;
	}
}
