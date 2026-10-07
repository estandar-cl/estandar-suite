<?php
/**
 * CrUX API integration.
 *
 * @package EstandarWebsiteScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetch real Core Web Vitals from Chrome UX Report.
 */
class EWS_CrUX_API {

	/**
	 * Endpoint.
	 */
	private const ENDPOINT = 'https://chromeuxreport.googleapis.com/v1/records:queryRecord';

	/**
	 * Query CrUX metrics.
	 *
	 * @param string $url Target URL.
	 * @return array<string, mixed>
	 */
	public static function query( string $url ): array {
		$url = esc_url_raw( $url );
		$key = trim( (string) get_option( 'estandar_crux_api_key', '' ) );
		if ( '' === $key ) {
			$key = trim( (string) get_option( 'estandar_pagespeed_api_key', '' ) );
		}

		if ( '' === $key ) {
			return array(
				'available' => false,
				'error'     => 'Missing CrUX API key.',
				'metrics'   => array(),
			);
		}

		$cache_key = 'ews_crux_' . md5( $url . '|' . $key );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$origin = self::origin_from_url( $url );
		$body   = wp_json_encode(
			array(
				'origin' => $origin,
			)
		);

		$response = wp_remote_post(
			add_query_arg( array( 'key' => $key ), self::ENDPOINT ),
			array(
				'timeout'     => 30,
				'redirection' => 3,
				'sslverify'   => true,
				'headers'     => array(
					'Content-Type' => 'application/json',
					'User-Agent'   => 'Estandar Website Scanner/1.0',
				),
				'body'        => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'available' => false,
				'error'     => $response->get_error_message(),
				'metrics'   => array(),
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $status || ! is_array( $data ) ) {
			return array(
				'available' => false,
				'error'     => 'CrUX API request failed.',
				'metrics'   => array(),
			);
		}

		$metrics = $data['record']['metrics'] ?? array();
		$result  = array(
			'available' => true,
			'error'     => null,
			'metrics'   => array(
				'lcp' => self::extract_percentile( $metrics, 'largest_contentful_paint' ),
				'cls' => self::extract_percentile( $metrics, 'cumulative_layout_shift' ),
				'inp' => self::extract_percentile( $metrics, 'interaction_to_next_paint' ),
			),
		);

		set_transient( $cache_key, $result, HOUR_IN_SECONDS );
		return $result;
	}

	/**
	 * Calculate CrUX quality score.
	 *
	 * @param array<string, mixed> $crux CrUX payload.
	 * @return float|null
	 */
	public static function score_from_metrics( array $crux ): ?float {
		if ( empty( $crux['available'] ) ) {
			return null;
		}

		$lcp = isset( $crux['metrics']['lcp'] ) ? (float) $crux['metrics']['lcp'] : null;
		$cls = isset( $crux['metrics']['cls'] ) ? (float) $crux['metrics']['cls'] : null;
		$inp = isset( $crux['metrics']['inp'] ) ? (float) $crux['metrics']['inp'] : null;

		if ( null === $lcp || null === $cls || null === $inp ) {
			return null;
		}

		$lcp_score = ( $lcp <= 2500 ) ? 100 : ( ( $lcp <= 4000 ) ? 70 : 35 );
		$cls_score = ( $cls <= 0.1 ) ? 100 : ( ( $cls <= 0.25 ) ? 70 : 35 );
		$inp_score = ( $inp <= 200 ) ? 100 : ( ( $inp <= 500 ) ? 70 : 35 );

		return round( ( $lcp_score + $cls_score + $inp_score ) / 3, 2 );
	}

	/**
	 * Extract percentile from CrUX metric object.
	 *
	 * @param array<string, mixed> $metrics Metric map.
	 * @param string               $key Metric key.
	 * @return float|null
	 */
	private static function extract_percentile( array $metrics, string $key ): ?float {
		if ( ! isset( $metrics[ $key ]['percentiles']['p75'] ) ) {
			return null;
		}

		return (float) $metrics[ $key ]['percentiles']['p75'];
	}

	/**
	 * Build origin from URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function origin_from_url( string $url ): string {
		$parts  = wp_parse_url( $url );
		$scheme = (string) ( $parts['scheme'] ?? 'https' );
		$host   = (string) ( $parts['host'] ?? '' );
		$port   = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		return $scheme . '://' . $host . $port;
	}
}
