<?php
/**
 * SEO checker module.
 *
 * @package EstandarWebsiteScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Run SEO checks (homepage + deep crawl).
 */
class EWS_SEO_Checker {

	/**
	 * Analyze SEO signals.
	 *
	 * @param string               $url Target URL.
	 * @param array<string, mixed> $crawler Crawler payload.
	 * @return array<string, mixed>
	 */
	public static function analyze( string $url, array $crawler = array() ): array {
		$site = EWS_Site_Fetcher::fetch( $url );
		$html = (string) ( $site['body'] ?? '' );
		$base = self::base_url( $url );

		$checks = array(
			'title'            => self::check_title( $html ),
			'meta_description' => self::check_meta_description( $html ),
			'h1'               => self::check_h1( $html ),
			'robots_txt'       => self::check_resource_available( trailingslashit( $base ) . 'robots.txt' ),
			'sitemap_xml'      => self::check_resource_available( trailingslashit( $base ) . 'sitemap.xml' ),
		);

		$homepage_score = self::score_checks( $checks );
		$deep_analysis  = self::analyze_crawled_pages( $crawler['pages'] ?? array() );
		$score          = round( ( $homepage_score * 0.45 ) + ( (float) $deep_analysis['score'] * 0.55 ), 2 );

		return array(
			'score'          => $score,
			'homepage_score' => $homepage_score,
			'deep_score'     => (float) $deep_analysis['score'],
			'checks'         => $checks,
			'deep_analysis'  => $deep_analysis,
			'url'            => esc_url_raw( $url ),
		);
	}

	/**
	 * Compute score from basic checks.
	 *
	 * @param array<string, array<string, mixed>> $checks Checks.
	 * @return float
	 */
	private static function score_checks( array $checks ): float {
		$passed = 0;
		foreach ( $checks as $check ) {
			$passed += ! empty( $check['passed'] ) ? 1 : 0;
		}

		return round( ( $passed / max( 1, count( $checks ) ) ) * 100, 2 );
	}

	/**
	 * Analyze crawled pages for structural SEO.
	 *
	 * @param array<int, array<string, mixed>> $pages Crawled pages.
	 * @return array<string, mixed>
	 */
	private static function analyze_crawled_pages( array $pages ): array {
		$total = count( $pages );
		if ( 0 === $total ) {
			return array(
				'score'   => 0,
				'summary' => array(),
				'pages'   => array(),
			);
		}

		$issues = array(
			'missing_title'       => 0,
			'missing_description' => 0,
			'missing_h1'          => 0,
			'missing_canonical'   => 0,
			'noindex_pages'       => 0,
			'error_status_pages'  => 0,
		);

		$points = 0;
		$max    = $total * 6;

		foreach ( $pages as $page ) {
			$has_title = ! empty( $page['title'] );
			$has_desc  = ! empty( $page['meta_description'] );
			$has_h1    = ! empty( $page['h1'] );
			$has_canon = ! empty( $page['canonical'] );
			$indexable = ! empty( $page['indexable'] );
			$status_ok = isset( $page['status_code'] ) && (int) $page['status_code'] >= 200 && (int) $page['status_code'] < 400;

			$points += $has_title ? 1 : 0;
			$points += $has_desc ? 1 : 0;
			$points += $has_h1 ? 1 : 0;
			$points += $has_canon ? 1 : 0;
			$points += $indexable ? 1 : 0;
			$points += $status_ok ? 1 : 0;

			$issues['missing_title'] += $has_title ? 0 : 1;
			$issues['missing_description'] += $has_desc ? 0 : 1;
			$issues['missing_h1'] += $has_h1 ? 0 : 1;
			$issues['missing_canonical'] += $has_canon ? 0 : 1;
			$issues['noindex_pages'] += $indexable ? 0 : 1;
			$issues['error_status_pages'] += $status_ok ? 0 : 1;
		}

		return array(
			'score'   => round( ( $points / max( 1, $max ) ) * 100, 2 ),
			'summary' => array_merge( array( 'total_pages' => $total ), $issues ),
			'pages'   => $pages,
		);
	}

	/**
	 * Validate title presence.
	 *
	 * @param string $html HTML content.
	 * @return array<string, mixed>
	 */
	private static function check_title( string $html ): array {
		preg_match( '/<title[^>]*>(.*?)<\/title>/is', $html, $matches );
		$title = isset( $matches[1] ) ? trim( wp_strip_all_tags( $matches[1] ) ) : '';

		return array(
			'label'   => 'Title tag',
			'passed'  => ! empty( $title ),
			'details' => $title,
		);
	}

	/**
	 * Validate meta description presence.
	 *
	 * @param string $html HTML content.
	 * @return array<string, mixed>
	 */
	private static function check_meta_description( string $html ): array {
		$description = '';
		preg_match_all( '/<meta\b[^>]*>/is', $html, $meta_tags );
		if ( ! empty( $meta_tags[0] ) ) {
			foreach ( $meta_tags[0] as $meta_tag ) {
				$name_value = self::extract_attribute( $meta_tag, 'name' );
				if ( 'description' !== strtolower( (string) $name_value ) ) {
					continue;
				}

				$content_value = self::extract_attribute( $meta_tag, 'content' );
				if ( '' !== trim( (string) $content_value ) ) {
					$description = trim( wp_strip_all_tags( (string) $content_value ) );
					break;
				}
			}
		}

		return array(
			'label'   => 'Meta description',
			'passed'  => ! empty( $description ),
			'details' => $description,
		);
	}

	/**
	 * Validate H1 presence.
	 *
	 * @param string $html HTML content.
	 * @return array<string, mixed>
	 */
	private static function check_h1( string $html ): array {
		preg_match_all( '/<h1\b[^>]*>(.*?)<\/h1>/is', $html, $matches );
		$headings = array();
		if ( ! empty( $matches[1] ) ) {
			foreach ( $matches[1] as $heading ) {
				$text = trim( wp_strip_all_tags( (string) $heading ) );
				if ( '' !== $text ) {
					$headings[] = $text;
				}
			}
		}
		$h1 = ! empty( $headings ) ? $headings[0] : '';

		return array(
			'label'   => 'H1 heading',
			'passed'  => ! empty( $h1 ),
			'details' => ! empty( $headings ) ? implode( ' | ', $headings ) : '',
		);
	}

	/**
	 * Verify a public resource exists.
	 *
	 * @param string $resource_url Resource URL.
	 * @return array<string, mixed>
	 */
	private static function check_resource_available( string $resource_url ): array {
		$response = wp_remote_get(
			esc_url_raw( $resource_url ),
			array(
				'timeout'     => 8,
				'redirection' => 3,
				'sslverify'   => true,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'label'   => basename( $resource_url ),
				'passed'  => false,
				'details' => $response->get_error_message(),
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		return array(
			'label'   => basename( $resource_url ),
			'passed'  => ( $status >= 200 && $status < 400 ),
			'details' => 'HTTP ' . $status,
		);
	}

	/**
	 * Get base URL (scheme + host + optional port).
	 *
	 * @param string $url Full URL.
	 * @return string
	 */
	private static function base_url( string $url ): string {
		$parts  = wp_parse_url( $url );
		$scheme = (string) ( $parts['scheme'] ?? 'https' );
		$host   = (string) ( $parts['host'] ?? '' );
		$port   = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';

		if ( '' === $host ) {
			return $url;
		}

		return $scheme . '://' . $host . $port;
	}

	/**
	 * Extract attribute value from an HTML tag.
	 *
	 * @param string $tag HTML tag text.
	 * @param string $attribute Attribute name.
	 * @return string|null
	 */
	private static function extract_attribute( string $tag, string $attribute ): ?string {
		$quoted_pattern = '/\b' . preg_quote( $attribute, '/' ) . '\s*=\s*([\'\"])(.*?)\1/is';
		if ( preg_match( $quoted_pattern, $tag, $matches ) ) {
			return (string) $matches[2];
		}

		$unquoted_pattern = '/\b' . preg_quote( $attribute, '/' ) . '\s*=\s*([^\s>]+)/is';
		if ( preg_match( $unquoted_pattern, $tag, $matches ) ) {
			return (string) $matches[1];
		}

		return null;
	}
}
