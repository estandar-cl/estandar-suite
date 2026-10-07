<?php
/**
 * Technology detector module.
 *
 * @package EstandarWebsiteScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detect website technologies.
 */
class EWS_Tech_Detector {

	/**
	 * Analyze technology stack.
	 *
	 * @param string $url Target URL.
	 * @return array<string, mixed>
	 */
	public static function analyze( string $url ): array {
		$site = EWS_Site_Fetcher::fetch( $url );
		$html = strtolower( (string) ( $site['body'] ?? '' ) );

		$headers = is_array( $site['headers'] ?? null ) ? $site['headers'] : array();
		$server  = strtolower( (string) ( $headers['server'] ?? '' ) );

		$flags = array(
			'wordpress'        => ( false !== strpos( $html, '/wp-content/' ) ),
			'elementor'        => ( false !== strpos( $html, 'elementor' ) ),
			'astra_theme'      => ( false !== strpos( $html, 'astra-theme' ) ),
			'cloudflare'       => isset( $headers['cf-cache-status'] ) || ( false !== strpos( $server, 'cloudflare' ) ),
			'google_analytics' => ( false !== strpos( $html, 'google-analytics.com' ) || false !== strpos( $html, 'gtag/js' ) ),
			'google_tag_manager' => ( false !== strpos( $html, 'googletagmanager.com/gtm.js' ) ),
			'facebook_pixel'   => ( false !== strpos( $html, 'connect.facebook.net' ) || false !== strpos( $html, 'fbq(' ) ),
			'tidio_chat'       => ( false !== strpos( $html, 'tidio.co' ) || false !== strpos( $html, 'code.tidio' ) ),
		);

		$technologies = array();
		foreach ( $flags as $slug => $enabled ) {
			if ( ! $enabled ) {
				continue;
			}

			$technologies[] = $slug;
		}

		$score = 20;
		$score += $flags['wordpress'] ? 10 : 0;
		$score += $flags['cloudflare'] ? 25 : 0;
		$score += ( $flags['google_analytics'] || $flags['google_tag_manager'] ) ? 20 : 0;
		$score += $flags['elementor'] ? 10 : 0;
		$score += $flags['astra_theme'] ? 5 : 0;
		$score += $flags['facebook_pixel'] ? 5 : 0;
		$score += $flags['tidio_chat'] ? 5 : 0;

		return array(
			'score'         => min( 100, (float) $score ),
			'flags'         => $flags,
			'technologies'  => $technologies,
			'headers'       => array(
				'server'          => $headers['server'] ?? '',
				'cf-cache-status' => $headers['cf-cache-status'] ?? '',
				'x-powered-by'    => $headers['x-powered-by'] ?? '',
				'x-cache'         => $headers['x-cache'] ?? '',
				'via'             => $headers['via'] ?? '',
			),
			'url'           => esc_url_raw( $url ),
		);
	}
}
