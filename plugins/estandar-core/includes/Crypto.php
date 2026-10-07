<?php

namespace Estandar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared AES-256-GCM crypto for any value at rest (OAuth tokens, secrets,
 * adapter config). The key is derived from one option
 * (estandar_suite_core_secret_key) so all ciphertext in the suite is
 * decryptable by a single rotation point. Format: base64(iv(12) . tag(16) . data).
 *
 * Kept in sync with Secrets.php (which delegates here); never log plaintext.
 */
class Crypto
{
	const KEY_OPTION = 'estandar_suite_core_secret_key';

	/** Read or lazily generate the encryption key (64 hex chars = 32 bytes). */
	public static function key(): string
	{
		$key = get_option( self::KEY_OPTION, '' );
		if ( $key === '' ) {
			$key = bin2hex( random_bytes( 32 ) );
			update_option( self::KEY_OPTION, $key, false );
		}
		return $key;
	}

	/** Encrypt a plaintext string → base64(iv . tag . cipher). '' → ''. */
	public static function encrypt( string $plain ): string
	{
		if ( $plain === '' ) {
			return '';
		}
		$iv     = random_bytes( 12 );
		$cipher = openssl_encrypt( $plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );
		if ( $cipher === false ) {
			return '';
		}
		return base64_encode( $iv . $tag . $cipher );
	}

	/** Decrypt base64(iv . tag . cipher) → plaintext. '' on failure/malformed. */
	public static function decrypt( string $stored ): string
	{
		if ( $stored === '' ) {
			return '';
		}
		$raw = base64_decode( $stored, true );
		if ( $raw === false || strlen( $raw ) < 29 ) {
			return '';
		}
		$iv    = substr( $raw, 0, 12 );
		$tag   = substr( $raw, 12, 16 );
		$data  = substr( $raw, 28 );
		$plain = openssl_decrypt( $data, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );
		return is_string( $plain ) ? $plain : '';
	}
}
