<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Short-lived, revocable, single-quote-scoped sessions for Leads operators.
 * This is not a WordPress capability grant: the token proves nothing beyond
 * "the bearer was handed off this quote"; every mutating action still
 * re-validates the underlying Leads operator/assignment on top of it.
 */
class Estandar_Quotes_Operator_Session {
	const TTL_SECONDS = 4 * HOUR_IN_SECONDS;

	public static function create( int $quote_id, int $operator_id ): string {
		global $wpdb;
		$token = bin2hex( random_bytes( 32 ) );
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert( Estandar_Quotes_Schema::operator_sessions_table(), array(
			'session_token_hash' => self::hash( $token ),
			'quote_id' => absint( $quote_id ),
			'operator_id' => absint( $operator_id ),
			'created_at' => $now,
			'expires_at' => gmdate( 'Y-m-d H:i:s', time() + self::TTL_SECONDS ),
			'last_used_at' => $now,
		) );
		return $token;
	}

	/** Returns the session row for a valid, unexpired, unrevoked token, or null. Refreshes last_used_at as a side effect. */
	public static function resolve( string $token ): ?array {
		global $wpdb;
		if ( strlen( $token ) < 32 ) { return null; }
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . Estandar_Quotes_Schema::operator_sessions_table() . ' WHERE session_token_hash = %s AND revoked_at IS NULL AND expires_at > %s LIMIT 1',
			self::hash( $token ), gmdate( 'Y-m-d H:i:s' )
		), ARRAY_A );
		if ( ! is_array( $row ) ) { return null; }
		$wpdb->update( Estandar_Quotes_Schema::operator_sessions_table(), array( 'last_used_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => (int) $row['id'] ) );
		return $row;
	}

	public static function revoke( string $token ): void {
		global $wpdb;
		if ( strlen( $token ) < 32 ) { return; }
		$wpdb->update( Estandar_Quotes_Schema::operator_sessions_table(), array( 'revoked_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'session_token_hash' => self::hash( $token ) ) );
	}

	/** Per-render, per-action anti-CSRF value. Derived from the bearer token itself, so it cannot be replayed for a different action, quote or session. */
	public static function action_token( string $token, string $quote_uuid, string $action ): string {
		return hash_hmac( 'sha256', $action . '|' . $quote_uuid, $token . wp_salt( 'auth' ) );
	}

	public static function verify_action_token( string $token, string $quote_uuid, string $action, string $candidate ): bool {
		if ( $candidate === '' ) { return false; }
		return hash_equals( self::action_token( $token, $quote_uuid, $action ), $candidate );
	}

	public static function url( string $token ): string {
		return home_url( '/cotizacion/' . rawurlencode( $token ) );
	}

	private static function hash( string $token ): string {
		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
	}
}
