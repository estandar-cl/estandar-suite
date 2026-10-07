<?php
/**
 * Infrastructure checker module.
 *
 * @package EstandarWebsiteScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Evaluate infrastructure signals.
 */
class EWS_Infrastructure_Check {

	/**
	 * Analyze infrastructure readiness.
	 *
	 * @param string $url Target URL.
	 * @return array<string, mixed>
	 */
	public static function analyze( string $url ): array {
		$site    = EWS_Site_Fetcher::fetch( $url );
		$headers = is_array( $site['headers'] ?? null ) ? $site['headers'] : array();
		$parts   = wp_parse_url( $url );

		$is_https   = ( 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) ) );
		$ssl_status = self::check_ssl_certificate( (string) ( $parts['host'] ?? '' ), $is_https );
		$cdn_check  = self::check_cdn_headers( $headers );
		$speed      = self::response_time_score( (float) ( $site['response_time_ms'] ?? 0 ) );

		$score = 0;
		$score += $is_https ? 25 : 0;
		$score += ! empty( $ssl_status['valid'] ) ? 25 : 0;
		$score += ! empty( $cdn_check['passed'] ) ? 25 : 0;
		$score += (float) $speed['score'];

		return array(
			'score'  => round( min( 100, $score ), 2 ),
			'checks' => array(
				'https' => array(
					'label'   => 'HTTPS enabled',
					'passed'  => $is_https,
					'details' => $is_https ? 'Yes' : 'No',
				),
				'ssl_certificate' => $ssl_status,
				'cdn'             => $cdn_check,
				'response_time'   => $speed,
			),
			'url'    => esc_url_raw( $url ),
		);
	}

	/**
	 * Validate SSL cert status.
	 *
	 * @param string $host Target host.
	 * @param bool   $is_https Whether HTTPS is enabled.
	 * @return array<string, mixed>
	 */
	private static function check_ssl_certificate( string $host, bool $is_https ): array {
		if ( ! $is_https || empty( $host ) ) {
			return array(
				'label'   => 'SSL certificate',
				'valid'   => false,
				'passed'  => false,
				'details' => 'Not applicable (HTTP site).',
			);
		}

		// Safety: this module opens a TCP connection to host:443.
		// Validate host first to avoid SSRF-like internal probing.
		$host_for_url = $host;
		// If host is an IPv6 literal, wp_parse_url would keep ":"; synthesize a valid URL.
		if ( false !== strpos( $host_for_url, ':' ) && ! str_starts_with( $host_for_url, '[' ) ) {
			$host_for_url = '[' . $host_for_url . ']';
		}
		$synthetic_url = 'https://' . $host_for_url;
		if ( ! EWS_Url_Safety::is_safe_url( $synthetic_url ) ) {
			return array(
				'label'   => 'SSL certificate',
				'valid'   => false,
				'passed'  => false,
				'details' => 'Unsafe SSL target.',
			);
		}

		$context = stream_context_create(
			array(
				'ssl' => array(
					'capture_peer_cert' => true,
				),
			)
		);

		$client = @stream_socket_client( 'ssl://' . $host . ':443', $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $context );
		if ( ! $client ) {
			return array(
				'label'   => 'SSL certificate',
				'valid'   => false,
				'passed'  => false,
				'details' => ! empty( $errstr ) ? $errstr : 'Unable to validate certificate.',
			);
		}

		$params = stream_context_get_params( $client );
		fclose( $client );

		$cert = $params['options']['ssl']['peer_certificate'] ?? null;
		if ( empty( $cert ) ) {
			return array(
				'label'   => 'SSL certificate',
				'valid'   => false,
				'passed'  => false,
				'details' => 'Certificate not found.',
			);
		}

		$cert_info = openssl_x509_parse( $cert );
		$valid_to  = isset( $cert_info['validTo_time_t'] ) ? (int) $cert_info['validTo_time_t'] : 0;
		$is_valid  = ( $valid_to > time() );

		return array(
			'label'   => 'SSL certificate',
			'valid'   => $is_valid,
			'passed'  => $is_valid,
			'details' => $is_valid ? gmdate( 'Y-m-d', $valid_to ) : 'Expired or invalid.',
		);
	}

	/**
	 * Check presence of CDN-related headers.
	 *
	 * @param array<string, string> $headers Response headers.
	 * @return array<string, mixed>
	 */
	private static function check_cdn_headers( array $headers ): array {
		$server = strtolower( (string) ( $headers['server'] ?? '' ) );

		$matched = array();
		if ( isset( $headers['cf-cache-status'] ) || false !== strpos( $server, 'cloudflare' ) ) {
			$matched[] = 'cloudflare';
		}
		if ( isset( $headers['x-cache'] ) ) {
			$matched[] = 'x-cache';
		}
		if ( isset( $headers['via'] ) ) {
			$matched[] = 'via';
		}
		if ( false !== strpos( $server, 'fastly' ) || false !== strpos( $server, 'akamai' ) ) {
			$matched[] = 'edge-server';
		}

		$matched = array_values( array_unique( $matched ) );

		return array(
			'label'   => 'CDN signal',
			'passed'  => ! empty( $matched ),
			'details' => empty( $matched ) ? 'No clear CDN headers found.' : implode( ', ', $matched ),
		);
	}

	/**
	 * Score response time.
	 *
	 * @param float $response_time_ms Response time in milliseconds.
	 * @return array<string, mixed>
	 */
	private static function response_time_score( float $response_time_ms ): array {
		$score = 4;
		if ( $response_time_ms <= 600 ) {
			$score = 25;
		} elseif ( $response_time_ms <= 1200 ) {
			$score = 18;
		} elseif ( $response_time_ms <= 2000 ) {
			$score = 10;
		}

		return array(
			'label'   => 'Server response time',
			'passed'  => ( $response_time_ms <= 1200 ),
			'details' => round( $response_time_ms, 2 ) . ' ms',
			'score'   => $score,
		);
	}
}
