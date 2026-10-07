<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Estandar_Secure_Token
 *
 * Sistema reutilizable de enlaces públicos seguros para toda la Suite Estandar.
 *
 * Módulos previstos:
 *   survey  → referencia a estandar_form_entries (entry_id)
 *   quote   → futura integración
 *   form    → futura integración
 *   report  → futura integración
 *
 * @see class-db.php  — esquema de la tabla estandar_secure_tokens
 */
class Estandar_Secure_Token {

	const TABLE = 'estandar_secure_tokens';
	const TOKEN_BYTES = 32; // 64 hex chars

	/**
	 * Generar un token seguro para un recurso.
	 *
	 * @param string   $module         Identificador del módulo ('survey', 'quote', etc.).
	 * @param int      $reference_id   ID del recurso (entry_id, quote_id, etc.).
	 * @param int|null $expires_in_days  Días hasta expiración (null = nunca expira).
	 * @param array    $meta           Datos adicionales opcionales (ej. survey_id).
	 *
	 * @return string|WP_Error  Token en hex o WP_Error.
	 */
	public static function generate( string $module, int $reference_id, ?int $expires_in_days = null, array $meta = [] ) {
		global $wpdb;

		$token = bin2hex( random_bytes( self::TOKEN_BYTES ) );

		$created_at = current_time( 'mysql', true );
		$expires_at = null;
		if ( $expires_in_days !== null && $expires_in_days > 0 ) {
			$expires_at = gmdate( 'Y-m-d H:i:s', time() + $expires_in_days * DAY_IN_SECONDS );
		}

		$inserted = $wpdb->insert(
			$wpdb->prefix . self::TABLE,
			[
				'module'         => $module,
				'reference_id'   => $reference_id,
				'token'          => $token,
				'created_at'     => $created_at,
				'expires_at'     => $expires_at,
				'meta_json'      => ! empty( $meta ) ? wp_json_encode( $meta ) : null,
			],
			[ '%s', '%d', '%s', '%s', '%s', '%s' ]
		);

		if ( ! $inserted ) {
			return new WP_Error( 'token_generation_failed', 'No se pudo generar el token seguro.' );
		}

		return $token;
	}

	/**
	 * Validar un token y devolver sus datos.
	 *
	 * @param string $token  Token en hex.
	 *
	 * @return array|WP_Error  {module, reference_id, meta, expires_at} o WP_Error.
	 */
	public static function validate( string $token ) {
		global $wpdb;

		if ( strlen( $token ) !== self::TOKEN_BYTES * 2 || ! ctype_xdigit( $token ) ) {
			return new WP_Error( 'invalid_token', 'Token inválido.' );
		}

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT module, reference_id, expires_at, revoked_at, meta_json
			 FROM {$wpdb->prefix}" . self::TABLE . " WHERE token = %s",
			$token
		), ARRAY_A );

		if ( ! $row ) {
			return new WP_Error( 'token_not_found', 'El enlace no existe o fue eliminado.' );
		}

		if ( $row['revoked_at'] !== null ) {
			return new WP_Error( 'token_revoked', 'Este enlace fue revocado por el administrador.' );
		}

		if ( $row['expires_at'] !== null && strtotime( $row['expires_at'] ) < time() ) {
			return new WP_Error( 'token_expired', 'Este enlace ha expirado.' );
		}

		return [
			'module'       => $row['module'],
			'reference_id' => (int) $row['reference_id'],
			'meta'         => $row['meta_json'] ? (array) json_decode( $row['meta_json'], true ) : [],
			'expires_at'   => $row['expires_at'],
		];
	}

	/**
	 * Revocar un token específico.
	 */
	public static function revoke( string $token ): bool {
		global $wpdb;
		return (bool) $wpdb->update(
			$wpdb->prefix . self::TABLE,
			[ 'revoked_at' => current_time( 'mysql', true ) ],
			[ 'token' => $token ],
			[ '%s' ],
			[ '%s' ]
		);
	}

	/**
	 * Revocar todos los tokens de un recurso.
	 */
	public static function revoke_by_reference( string $module, int $reference_id ): bool {
		global $wpdb;
		return (bool) $wpdb->update(
			$wpdb->prefix . self::TABLE,
			[ 'revoked_at' => current_time( 'mysql', true ) ],
			[ 'module' => $module, 'reference_id' => $reference_id, 'revoked_at' => null ],
			[ '%s' ],
			[ '%s', '%d', '%s' ]
		);
	}

	/**
	 * Regenerar token: revoca los anteriores y crea uno nuevo.
	 *
	 * @return string|WP_Error  Nuevo token.
	 */
	public static function regenerate( string $module, int $reference_id, ?int $expires_in_days = null, array $meta = [] ) {
		self::revoke_by_reference( $module, $reference_id );
		return self::generate( $module, $reference_id, $expires_in_days, $meta );
	}

	/**
	 * Obtener el token activo de un recurso (si existe).
	 */
	public static function get_active_token( string $module, int $reference_id ): ?string {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare(
			"SELECT token FROM {$wpdb->prefix}" . self::TABLE . "
			 WHERE module = %s AND reference_id = %d AND revoked_at IS NULL
			   AND (expires_at IS NULL OR expires_at > %s)
			 ORDER BY id DESC LIMIT 1",
			$module, $reference_id, current_time( 'mysql', true )
		) );
	}

	/**
	 * Public URL for a given token.
	 */
	public static function public_url( string $token ): string {
		return home_url( '/?estandar_secure_token=' . urlencode( $token ) );
	}
}
