<?php
/**
 * SEO opportunity detector.
 *
 * @package EstandarWebsiteScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects strategic SEO opportunities.
 */
class EWS_SEO_Opportunity_Detector {

	/**
	 * Analyze opportunities from crawler and modules.
	 *
	 * @param array<string, mixed> $crawler Crawler payload.
	 * @param array<string, mixed> $seo SEO payload.
	 * @param array<string, mixed> $accessibility Accessibility payload.
	 * @param array<string, mixed> $structured_data Structured data payload.
	 * @return array<string, mixed>
	 */
	public static function analyze( array $crawler, array $seo, array $accessibility, array $structured_data ): array {
		$opportunities = array();
		$pages         = (array) ( $crawler['pages'] ?? array() );

		$missing_h1 = 0;
		$missing_meta = 0;
		$thin_content = 0;
		$link_gaps = 0;
		foreach ( $pages as $page ) {
			if ( empty( $page['h1'] ) ) {
				++$missing_h1;
			}
			if ( empty( $page['meta_description'] ) ) {
				++$missing_meta;
			}
			$html = strtolower( (string) ( $page['html'] ?? '' ) );
			$words = preg_split( '/\s+/', trim( wp_strip_all_tags( $html ) ) );
			$word_count = is_array( $words ) ? count( array_filter( $words ) ) : 0;
			if ( $word_count > 0 && $word_count < 200 ) {
				++$thin_content;
			}
			if ( isset( $page['internal_links'] ) && (int) $page['internal_links'] < 2 ) {
				++$link_gaps;
			}
		}

		if ( $missing_h1 > 0 ) {
			$opportunities[] = $missing_h1 . ' crawled pages are missing a primary H1 heading.';
		}
		if ( $missing_meta > 0 ) {
			$opportunities[] = $missing_meta . ' pages are missing meta descriptions.';
		}
		if ( $thin_content > 0 ) {
			$opportunities[] = $thin_content . ' pages appear to have thin content (<200 words).';
		}
		if ( empty( $structured_data['schema_detected'] ) ) {
			$opportunities[] = 'Structured data (JSON-LD) is missing across crawled pages.';
		}
		if ( $link_gaps > 0 ) {
			$opportunities[] = $link_gaps . ' pages have low internal linking depth and could be reinforced.';
		}

		$seo_checks = (array) ( $seo['checks'] ?? array() );
		if ( isset( $seo_checks['meta_description']['passed'] ) && ! $seo_checks['meta_description']['passed'] ) {
			$opportunities[] = 'Homepage meta description is missing or weak.';
		}
		if ( isset( $seo_checks['h1']['passed'] ) && ! $seo_checks['h1']['passed'] ) {
			$opportunities[] = 'Homepage does not have a clear H1 hierarchy.';
		}

		$a11y_checks = (array) ( $accessibility['checks'] ?? array() );
		if ( isset( $a11y_checks['images_alt']['passed'] ) && ! $a11y_checks['images_alt']['passed'] ) {
			$opportunities[] = 'Several images are missing ALT attributes.';
		}

		$opportunities = array_values( array_unique( $opportunities ) );
		$score = max( 0, 100 - ( count( $opportunities ) * 12 ) );

		return array(
			'score'         => (float) $score,
			'opportunities' => $opportunities,
			'total'         => count( $opportunities ),
		);
	}
}

