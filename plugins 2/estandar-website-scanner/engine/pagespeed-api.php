<?php
/**
 * PageSpeed Insights API integration.
 *
 * @package EstandarWebsiteScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles PageSpeed Insights requests and parsing.
 */
class EWS_PageSpeed_API {

	/**
	 * API endpoint.
	 */
	private const ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

	/**
	 * Run desktop and mobile diagnostics.
	 *
	 * @param string $url Target URL.
	 * @return array<string, mixed>
	 */
	public static function run( string $url ): array {
		$url = esc_url_raw( $url );

		if ( empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return array(
				'available' => false,
				'errors'    => array( __( 'Invalid URL provided for PageSpeed scan.', 'estandar-website-scanner' ) ),
				'scores'    => array(
					'performance'   => null,
					'accessibility' => null,
				),
				'mobile'    => array(),
				'desktop'   => array(),
			);
		}

		$mobile  = self::get_report( $url, 'mobile' );
		$desktop = self::get_report( $url, 'desktop' );
		$errors  = array();

		if ( ! empty( $mobile['error'] ) ) {
			$errors[] = 'Mobile: ' . (string) $mobile['error'];
		}
		if ( ! empty( $desktop['error'] ) ) {
			$errors[] = 'Desktop: ' . (string) $desktop['error'];
		}

		$performance_avg = self::average_scores(
			array(
				$mobile['performance_score'] ?? null,
				$desktop['performance_score'] ?? null,
			)
		);

		$accessibility_avg = self::average_scores(
			array(
				$mobile['accessibility_score'] ?? null,
				$desktop['accessibility_score'] ?? null,
			)
		);

		return array(
			'available' => ( null !== $performance_avg || null !== $accessibility_avg ),
			'errors'    => array_values( array_unique( $errors ) ),
			'scores'    => array(
				'performance'   => $performance_avg,
				'accessibility' => $accessibility_avg,
			),
			'mobile'    => $mobile,
			'desktop'   => $desktop,
		);
	}

	/**
	 * Fetch and parse one strategy report.
	 *
	 * @param string $url Target URL.
	 * @param string $strategy mobile|desktop.
	 * @return array<string, mixed>
	 */
	private static function get_report( string $url, string $strategy ): array {
		$api_key = trim( (string) get_option( 'estandar_pagespeed_api_key', '' ) );
		if ( '' === $api_key ) {
			return array(
				'error' => __( 'PageSpeed API request failed. Missing API key in Estandar Scanner Settings.', 'estandar-website-scanner' ),
			);
		}

		$cache_key = 'ews_pagespeed_' . md5( $url . '|' . $strategy . '|' . $api_key );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$query = array(
			'url'      => $url,
			'strategy' => $strategy,
			'category' => array( 'performance', 'accessibility', 'seo', 'best-practices' ),
			'key'      => $api_key,
		);

		$request_url = add_query_arg( $query, self::ENDPOINT );
		$response    = self::request_with_retry( $request_url );

		if ( is_wp_error( $response ) ) {
			return array(
				'status'  => 'error',
				'message' => $response->get_error_message(),
				'error'   => $response->get_error_message(),
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );
		$data   = json_decode( $body, true );

		if ( 200 !== $status || ! is_array( $data ) ) {
			$error_message = sprintf(
				/* translators: %d response status. */
				__( 'PageSpeed API returned an invalid response (HTTP %d).', 'estandar-website-scanner' ),
				$status
			);
			if ( is_array( $data ) && ! empty( $data['error']['message'] ) ) {
				$error_message = (string) $data['error']['message'];
			}

			if ( false !== stripos( $error_message, 'quota' ) ) {
				$error_message = __( 'PageSpeed API request failed. Quota exceeded. Check your Google Cloud quota limits.', 'estandar-website-scanner' );
			} elseif ( false !== stripos( $error_message, 'key' ) ) {
				$error_message = __( 'PageSpeed API request failed. Invalid API key. Check Estandar Scanner Settings.', 'estandar-website-scanner' );
			} elseif ( false !== stripos( $error_message, 'timed out' ) ) {
				$error_message = __( 'PageSpeed API request failed due to timeout. Retry scan or increase server resources.', 'estandar-website-scanner' );
			}

			return array(
				'status'  => 'error',
				'message' => $error_message,
				'error'   => $error_message,
			);
		}

		$report = self::parse_report( $data );
		set_transient( $cache_key, $report, HOUR_IN_SECONDS );

		return $report;
	}

	/**
	 * Parse Lighthouse payload into normalized metrics.
	 *
	 * @param array<string, mixed> $payload Raw API payload.
	 * @return array<string, mixed>
	 */
	private static function parse_report( array $payload ): array {
		$lighthouse = $payload['lighthouseResult'] ?? array();
		$categories = $lighthouse['categories'] ?? array();
		$audits     = $lighthouse['audits'] ?? array();

		return array(
			'performance_score'   => self::read_score( $categories, 'performance' ),
			'accessibility_score' => self::read_score( $categories, 'accessibility' ),
			'seo_score'           => self::read_score( $categories, 'seo' ),
			// Lighthouse captures a small "screenshot thumbnail" during the run.
			// We embed it as a data URI (so DOMPDF/Web doesn't need remote fetching).
			'screenshot_data_uri' => self::read_screenshot_thumbnails_data_uri( $audits ),
			'metrics'             => array(
				'lcp'         => self::read_metric( $audits, 'largest-contentful-paint' ),
				'ttfb'        => self::read_metric( $audits, 'server-response-time' ),
				'cls'         => self::read_metric( $audits, 'cumulative-layout-shift' ),
				'fcp'         => self::read_metric( $audits, 'first-contentful-paint' ),
				'tbt'         => self::read_metric( $audits, 'total-blocking-time' ),
				'speed_index' => self::read_metric( $audits, 'speed-index' ),
				'inp'         => self::read_metric( $audits, 'interaction-to-next-paint' ),
			),
			'accessibility_audits'=> array(
				'color_contrast' => self::read_metric( $audits, 'color-contrast' ),
			),
			'opportunities'       => self::extract_opportunities( $audits ),
		);
	}

	/**
	 * Extract the Lighthouse "screenshot-thumbnails" audit as a data URI.
	 *
	 * @param array<string, mixed> $audits Lighthouse audits.
	 * @return string Data URI or empty string.
	 */
	private static function read_screenshot_thumbnails_data_uri( array $audits ): string {
		$audit = $audits['screenshot-thumbnails'] ?? array();
		if ( empty( $audit ) || ! is_array( $audit ) ) {
			return '';
		}

		$details = $audit['details'] ?? array();
		$items   = is_array( $details ) && isset( $details['items'] ) && is_array( $details['items'] ) ? $details['items'] : array();
		if ( empty( $items ) ) {
			return '';
		}

		// Use the first thumbnail.
		$item = $items[0] ?? array();
		if ( empty( $item ) || ! is_array( $item ) ) {
			return '';
		}

		$candidate = '';
		if ( isset( $item['data'] ) && is_string( $item['data'] ) ) {
			$candidate = trim( $item['data'] );
		} elseif ( isset( $item['base64'] ) && is_string( $item['base64'] ) ) {
			$candidate = trim( $item['base64'] );
		}

		$mime = '';
		if ( isset( $item['mimeType'] ) && is_string( $item['mimeType'] ) ) {
			$mime = trim( (string) $item['mimeType'] );
		} elseif ( isset( $item['mime'] ) && is_string( $item['mime'] ) ) {
			$mime = trim( (string) $item['mime'] );
		} elseif ( isset( $item['format'] ) && is_string( $item['format'] ) ) {
			$format = strtolower( trim( (string) $item['format'] ) );
			$map     = array(
				'jpg'  => 'image/jpeg',
				'jpeg' => 'image/jpeg',
				'png'  => 'image/png',
				'webp' => 'image/webp',
			);
			$mime    = $map[ $format ] ?? '';
		}

		if ( '' === $candidate ) {
			return '';
		}

		// Already embedded.
		if ( 0 === strpos( $candidate, 'data:' ) ) {
			$data_uri = $candidate;
			if ( strlen( $data_uri ) > 2_500_000 ) { // Safety cap.
				return '';
			}
			return $data_uri;
		}

		// Build data URI from base64-only candidate.
		$mime = '' !== $mime ? $mime : 'image/png';
		$data_uri = 'data:' . $mime . ';base64,' . $candidate;
		if ( strlen( $data_uri ) > 2_500_000 ) { // Safety cap.
			return '';
		}

		return $data_uri;
	}

	/**
	 * Convert category score (0-1) into percentage.
	 *
	 * @param array<string, mixed> $categories Lighthouse categories.
	 * @param string               $key Category key.
	 * @return float
	 */
	private static function read_score( array $categories, string $key ): float {
		$raw_score = $categories[ $key ]['score'] ?? 0;
		return round( (float) $raw_score * 100, 2 );
	}

	/**
	 * Read audit metric in a safe shape.
	 *
	 * @param array<string, mixed> $audits Lighthouse audits.
	 * @param string               $key Audit key.
	 * @return array<string, mixed>
	 */
	private static function read_metric( array $audits, string $key ): array {
		if ( empty( $audits[ $key ] ) || ! is_array( $audits[ $key ] ) ) {
			return array(
				'display' => null,
				'value'   => null,
				'score'   => null,
			);
		}

		return array(
			'display' => $audits[ $key ]['displayValue'] ?? null,
			'value'   => $audits[ $key ]['numericValue'] ?? null,
			'score'   => isset( $audits[ $key ]['score'] ) ? (float) $audits[ $key ]['score'] : null,
		);
	}

	/**
	 * Extract Lighthouse opportunities.
	 *
	 * @param array<string, mixed> $audits Lighthouse audits.
	 * @return array<int, array<string, mixed>>
	 */
	private static function extract_opportunities( array $audits ): array {
		$opportunities = array();

		foreach ( $audits as $audit ) {
			if ( ! is_array( $audit ) ) {
				continue;
			}

			$details = $audit['details'] ?? array();
			if ( ! is_array( $details ) || 'opportunity' !== ( $details['type'] ?? '' ) ) {
				continue;
			}

			$opportunities[] = array(
				'title'       => $audit['title'] ?? '',
				'description' => $audit['description'] ?? '',
				'savings_ms'  => $details['overallSavingsMs'] ?? null,
			);
		}

		return array_slice( $opportunities, 0, 8 );
	}

	/**
	 * Return average value with 2-decimal precision.
	 *
	 * @param array<int, float|int|null> $values Score values.
	 * @return float|null
	 */
	private static function average_scores( array $values ): ?float {
		$values = array_filter(
			$values,
			static function ( $value ) {
				return is_numeric( $value );
			}
		);

		if ( empty( $values ) ) {
			return null;
		}

		return round( array_sum( $values ) / count( $values ), 2 );
	}

	/**
	 * Perform API request with a single retry on timeout/network failure.
	 *
	 * @param string $request_url Request URL.
	 * @return array<string, mixed>|
	 */
	private static function request_with_retry( string $request_url ) {
		$args = array(
			'timeout'     => 40,
			'redirection' => 5,
			'httpversion' => '1.1',
			'blocking'    => true,
			'sslverify'   => true,
			'headers'     => array(
				'User-Agent' => 'Estandar Website Scanner/1.0',
			),
		);

		$response = wp_remote_get( $request_url, $args );
		if ( is_wp_error( $response ) ) {
			sleep( 2 );
			return wp_remote_get( $request_url, $args );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status >= 500 ) {
			sleep( 2 );
			return wp_remote_get( $request_url, $args );
		}

		return $response;
	}
}
