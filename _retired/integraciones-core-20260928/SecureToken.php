<?php

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SecureToken — enlaces públicos seguros v2 (F2).
 *
 * Puerto del Estandar_Secure_Token legacy al producto nativo, con tabla propia
 * (`estandar_capture_secure_tokens`) y query param propio (`estandar_capture_token`)
 * para NO colisionar con la ruta pública del legacy activo en producción.
 * Módulos: survey (respuesta individual), survey_results (resultados agregados).
 */
class SecureToken
{
	const TABLE = 'estandar_capture_secure_tokens';
	const OPTION = 'estandar_capture_secure_tokens_schema_version';
	const VERSION = '1.0.0';
	const TOKEN_BYTES = 32; // 64 hex chars

	public static function activate_schema(): void
	{
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$wpdb->prefix}" . self::TABLE . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			module varchar(40) NOT NULL DEFAULT '',
			reference_id bigint(20) unsigned NOT NULL,
			token varchar(128) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			expires_at datetime NULL,
			revoked_at datetime NULL,
			meta_json text,
			PRIMARY KEY  (id),
			UNIQUE KEY token (token),
			KEY module_ref (module, reference_id)
		) $charset;" );
		update_option( self::OPTION, self::VERSION, false );
	}

	public static function maybe_migrate_schema(): void
	{
		if ( get_option( self::OPTION, '' ) !== self::VERSION ) {
			self::activate_schema();
		}
	}

	/** Genera un token seguro. @return string|false token (64 hex). */
	public static function generate( string $module, int $reference_id, ?int $expires_in_days = null, array $meta = array() )
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return false;
		}
		$token = function_exists( 'random_bytes' ) ? bin2hex( random_bytes( self::TOKEN_BYTES ) ) : wp_generate_uuid4() . wp_generate_uuid4();
		$expires_at = null;
		if ( $expires_in_days !== null && $expires_in_days > 0 ) {
			$expires_at = gmdate( 'Y-m-d H:i:s', time() + $expires_in_days * DAY_IN_SECONDS );
		}
		$inserted = $wpdb->insert(
			$wpdb->prefix . self::TABLE,
			array(
				'module'       => $module,
				'reference_id' => $reference_id,
				'token'        => $token,
				'created_at'   => current_time( 'mysql', true ),
				'expires_at'   => $expires_at,
				'meta_json'    => $meta !== array() ? wp_json_encode( $meta ) : null,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s' )
		);
		return $inserted ? $token : false;
	}

	/** Valida un token. @return array|string datos o código de error. */
	public static function validate( string $token )
	{
		global $wpdb;
		if ( strlen( $token ) !== self::TOKEN_BYTES * 2 || ! ctype_xdigit( $token ) ) {
			return 'invalid_token';
		}
		if ( ! isset( $wpdb ) ) {
			return 'token_not_found';
		}
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT module, reference_id, expires_at, revoked_at, meta_json FROM ' . $wpdb->prefix . self::TABLE . ' WHERE token = %s',
			$token
		), ARRAY_A );
		if ( ! $row ) {
			return 'token_not_found';
		}
		if ( $row['revoked_at'] !== null ) {
			return 'token_revoked';
		}
		if ( $row['expires_at'] !== null && strtotime( $row['expires_at'] ) < time() ) {
			return 'token_expired';
		}
		return array(
			'module'       => $row['module'],
			'reference_id' => (int) $row['reference_id'],
			'meta'         => $row['meta_json'] ? (array) json_decode( $row['meta_json'], true ) : array(),
			'expires_at'   => $row['expires_at'],
		);
	}

	/** Revoca un token. */
	public static function revoke( string $token ): bool
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return false;
		}
		return (bool) $wpdb->update(
			$wpdb->prefix . self::TABLE,
			array( 'revoked_at' => current_time( 'mysql', true ) ),
			array( 'token' => $token ),
			array( '%s' ),
			array( '%s' )
		);
	}

	/** Regenera (revoca anteriores + crea uno nuevo). @return string|false */
	public static function regenerate( string $module, int $reference_id, ?int $expires_in_days = null, array $meta = array() )
	{
		global $wpdb;
		if ( isset( $wpdb ) ) {
			$wpdb->update(
				$wpdb->prefix . self::TABLE,
				array( 'revoked_at' => current_time( 'mysql', true ) ),
				array( 'module' => $module, 'reference_id' => $reference_id, 'revoked_at' => null ),
				array( '%s' ),
				array( '%s', '%d', '%s' )
			);
		}
		return self::generate( $module, $reference_id, $expires_in_days, $meta );
	}

	/** URL pública del token (query param PROPIO de capture). */
	public static function public_url( string $token ): string
	{
		return home_url( '/?estandar_capture_token=' . urlencode( $token ) );
	}
}
