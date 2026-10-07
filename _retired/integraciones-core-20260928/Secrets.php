<?php

namespace Estandar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One-time secrets (absorbs estandar-secure-share): AES-256-GCM, single-use,
 * auto-expiry, audit trail. Payload ciphertext stored server-side; the token
 * (random 32-byte hex) is the only value shared with the recipient.
 */
class Secrets
{
	const KEY_OPTION = 'estandar_suite_core_secret_key';

	/** Pure: build a token (no DB). */
	public static function token(): string
	{
		return bin2hex( random_bytes( 24 ) );
	}

	/** Create a one-time secret. Returns array{token, id, expires_at} or array(). */
	public static function create( string $payload, string $purpose = 'share', int $ttl_seconds = 86400 ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $payload === '' ) {
			return array();
		}
		$token   = self::token();
		$stored  = Crypto::encrypt( $payload );
		if ( $stored === '' ) {
			return array();
		}
		$expires = gmdate( 'Y-m-d H:i:s', time() + max( 60, (int) $ttl_seconds ) );
		$wpdb->insert( $wpdb->prefix . 'estandar_core_secrets', array(
			'secret_hash'       => hash( 'sha256', $token ),
			'purpose'           => sanitize_key( $purpose ),
			'payload_ciphertext' => $stored,
			'expires_at'        => $expires,
			'created_at'        => gmdate( 'Y-m-d H:i:s' ),
		), array( '%s', '%s', '%s', '%s', '%s' ) );
		$id = (int) $wpdb->insert_id;
		if ( $id <= 0 ) {
			return array();
		}
		return array( 'token' => $token, 'id' => $id, 'expires_at' => $expires );
	}

	/**
	 * Read + destroy (one-time). Returns the payload string or '' when missing,
	 * expired or already used. Deleting the row = rollback-safe single use.
	 */
	public static function consume( string $token ): string
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $token === '' ) {
			return '';
		}
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, payload_ciphertext, expires_at, used_at FROM {$wpdb->prefix}estandar_core_secrets
			 WHERE secret_hash = %s LIMIT 1",
			hash( 'sha256', $token )
		), ARRAY_A );
		if ( ! is_array( $row ) || ! empty( $row['used_at'] ) ) {
			return '';
		}
		if ( strtotime( (string) $row['expires_at'] ) < time() ) {
			return '';
		}
		$wpdb->update( $wpdb->prefix . 'estandar_core_secrets',
			array( 'used_at' => gmdate( 'Y-m-d H:i:s' ) ),
			array( 'id' => (int) $row['id'] ), array( '%s' ), array( '%d' ) );

		return Crypto::decrypt( (string) $row['payload_ciphertext'] );
	}

	/** Recent secrets (read API — never returns ciphertext payloads). */
	public static function recent( int $limit = 10 ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}
		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT id, purpose, expires_at, used_at, created_at FROM {$wpdb->prefix}estandar_core_secrets ORDER BY id DESC LIMIT %d",
			$limit
		), ARRAY_A );
	}

	/** Hourly cleanup of expired/used secrets. */
	public static function cleanup(): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return 0;
		}
		$rows = (int) $wpdb->query(
			"DELETE FROM {$wpdb->prefix}estandar_core_secrets
			 WHERE used_at IS NOT NULL OR expires_at < NOW()"
		);
		return $rows;
	}

	// ── Public one-time consume page (front-end) ───────────────────────────

	/** Register the `?estandar_secret=TOKEN` route (front-end page). */
	public static function init_frontend(): void
	{
		add_filter( 'query_vars', static function ( array $vars ) {
			$vars[] = 'estandar_secret';
			return $vars;
		} );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ) );
	}

	/** Recipient-facing link for a token. */
	public static function consume_link( string $token ): string
	{
		return function_exists( 'home_url' ) ? home_url( '/?estandar_secret=' . rawurlencode( $token ) ) : '';
	}

	/** Read-only status of a token: available | used | expired | missing. */
	public static function status( string $token ): string
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $token === '' ) {
			return 'missing';
		}
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT expires_at, used_at FROM {$wpdb->prefix}estandar_core_secrets WHERE secret_hash = %s LIMIT 1",
			hash( 'sha256', $token )
		), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return 'missing';
		}
		if ( ! empty( $row['used_at'] ) ) {
			return 'used';
		}
		if ( strtotime( (string) $row['expires_at'] ) < time() ) {
			return 'expired';
		}
		return 'available';
	}

	/** Intercept the route and render a minimal one-time reveal page. */
	public static function maybe_render(): void
	{
		$token = isset( $_GET['estandar_secret'] ) ? sanitize_text_field( wp_unslash( $_GET['estandar_secret'] ) ) : '';
		if ( $token === '' ) {
			return;
		}
		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );

		$revealed = false;
		$payload  = '';
		$error    = '';
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) && check_admin_referer( 'estandar_suite_secret_reveal', 'nonce' ) ) {
			$payload = self::consume( $token ); // one-time: destroys on access.
			$revealed = true;
			if ( $payload === '' ) {
				$error = 'El secreto ya fue consumido, expiró o el enlace no es válido.';
			}
		} elseif ( self::status( $token ) !== 'available' ) {
			// GET of a consumed/expired/missing secret → fail fast (no form).
			$revealed = true;
			$error = 'Este enlace ya fue usado, expiró o no es válido. No se puede consultar de nuevo.';
		}
		self::render_page( $token, $revealed, $payload, $error );
		exit;
	}

	/** Minimal standalone HTML (no theme, no assets). */
	private static function render_page( string $token, bool $revealed, string $payload, string $error ): void
	{
		$nonce_action = 'estandar_suite_secret_reveal';
		$nonce_field  = function_exists( 'wp_nonce_field' ) ? wp_nonce_field( $nonce_action, 'nonce', true, false ) : '';
		echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="robots" content="noindex,nofollow"><title>Acceso seguro</title>';
		echo '<style>body{font-family:system-ui,-apple-system,sans-serif;background:#f6f7f7;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;}'
			. '.card{background:#fff;max-width:520px;width:90%;padding:32px;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.08);text-align:center;}'
			. '.btn{display:inline-block;background:#1d2327;color:#fff;border:0;padding:12px 20px;border-radius:6px;font-size:14px;cursor:pointer;}'
			. 'pre{background:#f0f0f1;padding:14px;border-radius:6px;text-align:left;overflow-wrap:anywhere;font-size:14px;}'
			. '.ok{color:#007017;font-weight:600;}.err{color:#b32d2e;font-weight:600;}</style></head><body><div class="card">';
		if ( $revealed ) {
			if ( $payload !== '' ) {
				echo '<h2>Contenido</h2><pre>' . esc_html( $payload ) . '</pre>';
				echo '<p class="ok">Este secreto ya fue revelado y se eliminó. No se puede volver a consultar.</p>';
			} else {
				echo '<p class="err">' . esc_html( $error ) . '</p>';
			}
		} else {
			echo '<h2>Acceso de un solo uso</h2>';
			echo '<p>Este enlace contiene un dato protegido. Al revelarlo, el dato se mostrará una sola vez y luego se eliminará permanentemente.</p>';
			echo '<form method="post" action="' . esc_url( self::consume_link( $token ) ) . '">' . $nonce_field . '<button class="btn" type="submit">Revelar secreto</button></form>';
		}
		echo '</div></body></html>';
	}
}
