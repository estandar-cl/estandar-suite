<?php
/**
 * CANONICAL_REDIRECT_V1 — host canonicalization with exact URL preservation.
 *
 * Redirects mapped hosts (www.akcontadores.cl → akcontadores.cl) to their
 * canonical HTTPS host, preserving path and raw query string byte-for-byte.
 *
 * Contract (frozen):
 *  - GET/HEAD only.
 *  - Target host comes ONLY from the internal map (no user input → no open
 *    redirects). Map is filterable but every target is re-validated.
 *  - Path taken verbatim from REQUEST_URI; query taken verbatim from
 *    $_SERVER['QUERY_STRING']. No parsing, no add_query_arg, no re-encoding.
 *  - Fleet-safe: keyed on the incoming Host header, so the shipped default
 *    is inert on every site that is not akcontadores.cl.
 *  - Configurable status (301/302/307/308), default 301.
 *  - Debug off by default (ESTANDAR_CANONICAL_REDIRECT_DEBUG).
 *  - Exits immediately after sending the redirect.
 *
 * @package Estandar_Core_MU
 * @since   CANONICAL_REDIRECT_V1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Estandar_Canonical_Redirect' ) ) :

class Estandar_Canonical_Redirect {

	const DEFAULT_STATUS = 301;

	/** Shipped canonical host map: request host → canonical host. */
	const DEFAULT_MAP = array(
		'www.akcontadores.cl' => 'akcontadores.cl',
	);

	public static function boot(): void {
		// After all plugins have registered their filters, before init/query.
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_redirect' ), 1 );
	}

	/**
	 * Evaluate the current request. Pure: returns the redirect decision
	 * (or null) without side effects, so it is testable without exiting.
	 *
	 * @param array $server Typically $_SERVER.
	 * @return array{location:string,status:int}|null
	 */
	public static function evaluate( array $server ) {
		$method = strtoupper( (string) ( $server['REQUEST_METHOD'] ?? '' ) );
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return null;
		}

		// Host with a port (localhost:8000, staging) never matches the map.
		$host = strtolower( trim( (string) ( $server['HTTP_HOST'] ?? '' ) ) );
		if ( '' === $host ) {
			return null;
		}

		$map = apply_filters( 'estandar_canonical_redirect_map', self::DEFAULT_MAP );
		$map = is_array( $map ) ? $map : array();

		if ( empty( $map[ $host ] ) ) {
			return null;
		}

		// Targets come from config, never from the request — re-validate anyway
		// so a broken filter can never introduce an open redirect.
		$target_host = strtolower( trim( (string) $map[ $host ] ) );
		if ( '' === $target_host || $target_host === $host || ! preg_match( '/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $target_host ) ) {
			return null;
		}

		$request_uri = (string) ( $server['REQUEST_URI'] ?? '/' );

		// Path verbatim: everything before the first '?'. Not query parsing —
		// the query is re-attached raw below.
		$question = strpos( $request_uri, '?' );
		$path     = false === $question ? $request_uri : substr( $request_uri, 0, $question );
		if ( '' === $path || '/' !== $path[0] ) {
			$path = '/' . ltrim( $path, '/' );
		}

		$query = (string) ( $server['QUERY_STRING'] ?? '' );

		// Raw request-line values cannot contain CR/LF; if anything does,
		// refuse to redirect rather than mutate the URL.
		if ( preg_match( '/[\r\n\0]/', $path . $query ) ) {
			return null;
		}

		$status = (int) apply_filters( 'estandar_canonical_redirect_status', self::DEFAULT_STATUS );
		if ( ! in_array( $status, array( 301, 302, 307, 308 ), true ) ) {
			$status = self::DEFAULT_STATUS;
		}

		return array(
			'location' => 'https://' . $target_host . $path . ( '' !== $query ? '?' . $query : '' ),
			'status'   => $status,
		);
	}

	/**
	 * Apply the decision for the live request and exit.
	 */
	public static function maybe_redirect(): void {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() || headers_sent() ) {
			return;
		}

		$decision = self::evaluate( $_SERVER );
		if ( null === $decision ) {
			return;
		}

		if ( defined( 'ESTANDAR_CANONICAL_REDIRECT_DEBUG' ) && ESTANDAR_CANONICAL_REDIRECT_DEBUG ) {
			header( 'X-Estandar-Canonical-Redirect: ' . $decision['status'] );
			error_log( '[estandar-primer] canonical redirect ' . $decision['status'] . ' → ' . $decision['location'] );
		}

		header( 'Location: ' . $decision['location'], true, $decision['status'] );
		exit;
	}
}

endif;
