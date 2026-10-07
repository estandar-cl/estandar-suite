<?php
/**
 * Structured data scanner module.
 *
 * @package EstandarWebsiteScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detect JSON-LD schema in crawled pages.
 */
class EWS_Structured_Data {

	/**
	 * Analyze schema presence/types.
	 *
	 * @param array<string, mixed> $crawler Crawler payload.
	 * @return array<string, mixed>
	 */
	public static function analyze( array $crawler ): array {
		$pages = $crawler['pages'] ?? array();
		$types = array();
		$detected_count = 0;
		foreach ( $pages as $page ) {
			$html = (string) ( $page['html'] ?? '' );
			if ( '' === $html ) {
				continue;
			}
			$page_types = self::extract_types( $html );
			if ( ! empty( $page_types ) ) {
				++$detected_count;
				$types = array_merge( $types, $page_types );
			}
		}

		$types = array_values( array_unique( $types ) );
		$score = 0;
		if ( $detected_count > 0 ) {
			$score = min( 100, ( $detected_count * 5 ) + ( count( $types ) * 12 ) );
		}

		$supported = array( 'Organization', 'Article', 'Product', 'LocalBusiness', 'BreadcrumbList' );
		$matched_supported = array_values( array_intersect( $supported, $types ) );
		if ( ! empty( $matched_supported ) ) {
			$score = min( 100, $score + 20 );
		}

		return array(
			'score'           => round( (float) $score, 2 ),
			'schema_detected' => ( $detected_count > 0 ),
			'types'           => $types,
			'supported_types' => $matched_supported,
			'pages_with_schema' => $detected_count,
		);
	}

	/**
	 * Extract schema @type values from JSON-LD scripts.
	 *
	 * @param string $html HTML.
	 * @return array<int, string>
	 */
	private static function extract_types( string $html ): array {
		preg_match_all( '/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches );
		$types = array();
		foreach ( $matches[1] ?? array() as $json_block ) {
			$decoded = json_decode( trim( (string) $json_block ), true );
			if ( null === $decoded ) {
				continue;
			}
			$types = array_merge( $types, self::collect_types( $decoded ) );
		}
		return array_values( array_unique( array_filter( $types ) ) );
	}

	/**
	 * Recursively collect @type values.
	 *
	 * @param mixed $node Node.
	 * @return array<int, string>
	 */
	private static function collect_types( $node ): array {
		$types = array();
		if ( is_array( $node ) ) {
			if ( isset( $node['@type'] ) ) {
				if ( is_array( $node['@type'] ) ) {
					foreach ( $node['@type'] as $type ) {
						$types[] = (string) $type;
					}
				} else {
					$types[] = (string) $node['@type'];
				}
			}
			foreach ( $node as $child ) {
				$types = array_merge( $types, self::collect_types( $child ) );
			}
		}
		return $types;
	}
}
