<?php
/**
 * Internal link structure analyzer.
 *
 * @package EstandarWebsiteScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Analyze crawl architecture metrics.
 */
class EWS_Internal_Structure {

	/**
	 * Analyze crawler structure.
	 *
	 * @param array<string, mixed> $crawler Crawler payload.
	 * @return array<string, mixed>
	 */
	public static function analyze( array $crawler ): array {
		$pages = $crawler['pages'] ?? array();
		$total = count( $pages );
		if ( 0 === $total ) {
			return array(
				'score' => 0,
			);
		}

		$orphan = 0;
		$max_depth = 0;
		$link_distribution = array();
		foreach ( $pages as $page ) {
			$depth = (int) ( $page['depth'] ?? 0 );
			$max_depth = max( $max_depth, $depth );
			$inbound = (int) ( $page['inbound_links'] ?? 0 );
			if ( $depth > 0 && 0 === $inbound ) {
				++$orphan;
			}
			$link_distribution[] = (int) ( $page['internal_links'] ?? 0 );
		}

		$avg_links = ( ! empty( $link_distribution ) ) ? round( array_sum( $link_distribution ) / count( $link_distribution ), 2 ) : 0;
		$depth_score = ( $max_depth <= 3 ) ? 100 : ( ( $max_depth <= 5 ) ? 75 : 45 );
		$orphan_score = max( 0, 100 - round( ( $orphan / max( 1, $total ) ) * 100, 2 ) );
		$link_score = ( $avg_links >= 3 ) ? 100 : ( ( $avg_links >= 1 ) ? 70 : 35 );

		$score = round( ( $depth_score * 0.4 ) + ( $orphan_score * 0.4 ) + ( $link_score * 0.2 ), 2 );

		return array(
			'score'              => $score,
			'pages'              => $total,
			'orphan_pages'       => $orphan,
			'max_depth'          => $max_depth,
			'avg_internal_links' => $avg_links,
		);
	}
}
