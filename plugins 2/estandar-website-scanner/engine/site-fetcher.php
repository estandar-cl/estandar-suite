<?php
/**
 * Shared website fetch utilities.
 *
 * @package EstandarWebsiteScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetch HTML and headers for a target URL with cache.
 */
class EWS_Site_Fetcher {

	/**
	 * Get normalized site payload.
	 *
	 * @param string $url Target URL.
	 * @return array<string, mixed>
	 */
	public static function fetch( string $url ): array {
		$url = esc_url_raw( $url );

		if ( empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return array(
				'error' => __( 'Invalid URL.', 'estandar-website-scanner' ),
			);
		}

		if ( ! EWS_Url_Safety::is_safe_url( $url ) ) {
			return array(
				'error' => __( 'Unsafe URL.', 'estandar-website-scanner' ),
				'url'   => $url,
			);
		}

		$cache_key = 'ews_site_fetch_' . md5( $url );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$start    = microtime( true );
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 8,
				'redirection' => 5,
				'sslverify'   => true,
				// Hard limit to avoid memory blowups on huge HTML responses.
				'limit_response_size' => 2 * 1024 * 1024,
				'headers'     => array(
					'User-Agent' => 'EstandarScanner/1.0 (+WordPress)',
				),
			)
		);
		$elapsed = ( microtime( true ) - $start ) * 1000;

		if ( is_wp_error( $response ) ) {
			return array(
				'error'            => $response->get_error_message(),
				'url'              => $url,
				'response_time_ms' => round( $elapsed, 2 ),
			);
		}

		$headers = wp_remote_retrieve_headers( $response );
		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$headers = $headers->getAll();
		} elseif ( is_object( $headers ) && method_exists( $headers, 'to_array' ) ) {
			$headers = $headers->to_array();
		}

		$normalized_headers = array();
		if ( is_array( $headers ) ) {
			foreach ( $headers as $key => $value ) {
				$header_key = strtolower( (string) $key );
				if ( is_array( $value ) ) {
					$normalized_headers[ $header_key ] = implode( ', ', array_map( 'strval', $value ) );
				} else {
					$normalized_headers[ $header_key ] = (string) $value;
				}
			}
		}

		$payload = array(
			'url'              => $url,
			'final_url'        => $url,
			'status_code'      => (int) wp_remote_retrieve_response_code( $response ),
			'body'             => (string) wp_remote_retrieve_body( $response ),
			'headers'          => $normalized_headers,
			'response_time_ms' => round( $elapsed, 2 ),
		);

		set_transient( $cache_key, $payload, 10 * MINUTE_IN_SECONDS );
		return $payload;
	}
}
