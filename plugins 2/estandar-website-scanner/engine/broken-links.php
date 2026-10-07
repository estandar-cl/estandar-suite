<?php
/**
 * Broken links detector.
 *
 * @package EstandarWebsiteScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detect broken and problematic links from crawler pages.
 */
class EWS_Broken_Links {

	/**
	 * Analyze links found by crawler.
	 *
	 * @param array<string, mixed> $crawler Crawler payload.
	 * @param int|null $queue_id Queue ID for cancellation checks.
	 * @return array<string, mixed>
	 */
	public static function analyze( array $crawler, ?int $queue_id = null ): array {
		$pages = $crawler['pages'] ?? array();
		$unique_links = array();
		foreach ( $pages as $page ) {
			foreach ( $page['links'] ?? array() as $link ) {
				if ( is_string( $link ) && '' !== $link ) {
					$unique_links[ $link ] = true;
				}
			}
		}

		// Hard cap to avoid excessive HEAD requests (DoS risk).
		$links = array_slice( array_keys( $unique_links ), 0, 120 );
		$broken = array();
		$redirect_chains = array();

		foreach ( $links as $link ) {
			if ( EWS_Scan_Queue::is_cancelled( $queue_id ) ) {
				throw new Exception( 'Scan cancelled.' );
			}
			$result = self::check_link( $link );
			$status = (int) ( $result['status'] ?? 0 );
			if ( $status >= 400 || ! empty( $result['timeout'] ) ) {
				$broken[] = array(
					'link'   => $link,
					'status' => $status,
					'error'  => $result['error'] ?? '',
				);
			}
			if ( ! empty( $result['redirect_chain'] ) ) {
				$redirect_chains[] = array(
					'link'   => $link,
					'status' => $status,
				);
			}
		}

		$total = max( 1, count( $links ) );
		$penalty = ( count( $broken ) * 2.0 ) + ( count( $redirect_chains ) * 0.8 );
		$score = max( 0, round( 100 - ( $penalty / $total ) * 100, 2 ) );

		return array(
			'score'           => $score,
			'total_links'     => count( $links ),
			'broken_count'    => count( $broken ),
			'redirect_chains' => count( $redirect_chains ),
			'broken_links'    => array_slice( $broken, 0, 50 ),
		);
	}

	/**
	 * Check link status via HEAD request.
	 *
	 * @param string $url URL.
	 * @return array<string, mixed>
	 */
	private static function check_link( string $url ): array {
		// Safety: broken-link checks can include external URLs found in HTML.
		if ( ! EWS_Url_Safety::is_safe_url( $url ) ) {
			return array(
				'status'   => 0,
				'error'    => 'Unsafe URL (broken links).',
				'timeout'  => false,
			);
		}

		$response = wp_remote_head(
			esc_url_raw( $url ),
			array(
				'timeout'     => 6,
				'redirection' => 5,
				'sslverify'   => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			$message = $response->get_error_message();
			return array(
				'status'   => 0,
				'error'    => $message,
				'timeout'  => ( false !== stripos( $message, 'timed out' ) ),
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$headers = wp_remote_retrieve_headers( $response );
		$redirect_chain = false;
		if ( is_object( $headers ) && method_exists( $headers, 'offsetGet' ) ) {
			$redirect_chain = ( null !== $headers->offsetGet( 'x-redirect-by' ) );
		}
		return array(
			'status'         => $status,
			'redirect_chain' => $redirect_chain,
		);
	}
}
