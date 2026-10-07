<?php
/**
 * Keyword signals analyzer.
 *
 * @package EstandarWebsiteScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Analyze primary keyword presence/density.
 */
class EWS_Keyword_Signals {

	/**
	 * Analyze homepage keyword signals.
	 *
	 * @param string $url URL.
	 * @return array<string, mixed>
	 */
	public static function analyze( string $url ): array {
		$site = EWS_Site_Fetcher::fetch( $url );
		$html = (string) ( $site['body'] ?? '' );

		$title = self::extract_title( $html );
		$h1    = self::extract_h1( $html );
		$meta  = self::extract_meta_description( $html );
		$body  = strtolower( trim( wp_strip_all_tags( $html ) ) );

		$keyword = self::extract_keyword( $title, $h1 );
		$keyword_lc = strtolower( $keyword );

		$in_title = ( '' !== $keyword_lc && false !== strpos( strtolower( $title ), $keyword_lc ) );
		$in_h1    = ( '' !== $keyword_lc && false !== strpos( strtolower( $h1 ), $keyword_lc ) );
		$in_meta  = ( '' !== $keyword_lc && false !== strpos( strtolower( $meta ), $keyword_lc ) );

		$body_words = preg_split( '/\s+/', $body ) ?: array();
		$body_count = count( $body_words );
		$occurs     = ( '' === $keyword_lc ) ? 0 : substr_count( $body, $keyword_lc );
		$density    = ( $body_count > 0 ) ? round( ( $occurs / $body_count ) * 100, 2 ) : 0;

		$score = 30;
		$score += $in_title ? 25 : 0;
		$score += $in_h1 ? 25 : 0;
		$score += $in_meta ? 15 : 0;
		$score += ( $density >= 0.3 && $density <= 2.5 ) ? 5 : 0;

		return array(
			'score'      => min( 100, (float) $score ),
			'keyword'    => $keyword,
			'in_title'   => $in_title,
			'in_h1'      => $in_h1,
			'in_meta'    => $in_meta,
			'density'    => $density,
		);
	}

	private static function extract_title( string $html ): string {
		preg_match( '/<title[^>]*>(.*?)<\/title>/is', $html, $m );
		return isset( $m[1] ) ? trim( wp_strip_all_tags( (string) $m[1] ) ) : '';
	}

	private static function extract_h1( string $html ): string {
		preg_match( '/<h1\b[^>]*>(.*?)<\/h1>/is', $html, $m );
		return isset( $m[1] ) ? trim( wp_strip_all_tags( (string) $m[1] ) ) : '';
	}

	private static function extract_meta_description( string $html ): string {
		preg_match_all( '/<meta\b[^>]*>/is', $html, $meta_tags );
		foreach ( $meta_tags[0] ?? array() as $meta_tag ) {
			if ( false === stripos( $meta_tag, 'name="description"' ) && false === stripos( $meta_tag, "name='description'" ) ) {
				continue;
			}
			if ( preg_match( '/content\s*=\s*(["\'])(.*?)\1/is', $meta_tag, $match ) ) {
				return trim( (string) $match[2] );
			}
		}
		return '';
	}

	private static function extract_keyword( string $title, string $h1 ): string {
		$text = trim( strtolower( $h1 ?: $title ) );
		$text = preg_replace( '/[^\p{L}\p{N}\s]/u', ' ', $text );
		$tokens = preg_split( '/\s+/', (string) $text ) ?: array();
		$stop_words = array( 'de', 'la', 'el', 'y', 'en', 'para', 'con', 'a', 'the', 'and', 'of', 'for', 'to' );
		$filtered = array();
		foreach ( $tokens as $token ) {
			if ( strlen( $token ) < 3 || in_array( $token, $stop_words, true ) ) {
				continue;
			}
			$filtered[] = $token;
		}
		$keyword_tokens = array_slice( $filtered, 0, 3 );
		return trim( implode( ' ', $keyword_tokens ) );
	}
}
