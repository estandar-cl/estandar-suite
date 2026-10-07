<?php
/**
 * Basic website crawler and SEO deep checks.
 *
 * @package EstandarWebsiteScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Crawls internal pages and evaluates page-level SEO structure.
 */
class EWS_Crawler {

	/**
	 * Crawl a website and analyze pages.
	 *
	 * @param string $url Root URL.
	 * @param int    $limit Maximum pages to crawl.
	 * @param int|null $queue_id Queue ID for cancellation checks.
	 * @return array<string, mixed>
	 */
	public static function crawl( string $url, int $limit = 50, ?int $queue_id = null ): array {
		$root      = self::normalize_url( $url );
		$root_host = (string) wp_parse_url( $root, PHP_URL_HOST );
		$queue     = array(
			array(
				'url'    => $root,
				'depth'  => 0,
				'parent' => '',
			),
		);
		$visited        = array();
		$pages          = array();
		$inbound_counts = array( $root => 0 );
		$tree_edges     = array();

		while ( ! empty( $queue ) && count( $visited ) < $limit ) {
			$item    = array_shift( $queue );
			$current = (string) ( $item['url'] ?? '' );
			$depth   = (int) ( $item['depth'] ?? 0 );
			$parent  = (string) ( $item['parent'] ?? '' );

			if ( EWS_Scan_Queue::is_cancelled( $queue_id ) ) {
				throw new Exception( 'Scan cancelled.' );
			}

			if ( '' === $current || in_array( $current, $visited, true ) ) {
				continue;
			}

			// Extra safety: never fetch unsafe derived URLs (e.g. external links inside pages).
			if ( ! EWS_Url_Safety::is_safe_url( $current ) ) {
				$visited[] = $current;
				$pages[]    = array(
					'url'           => $current,
					'status_code'   => 0,
					'indexable'     => false,
					'error'         => 'Unsafe URL (crawler).',
					'depth'         => $depth,
					'inbound_links' => (int) ( $inbound_counts[ $current ] ?? 0 ),
					'links'         => array(),
					'html'          => '',
				);
				continue;
			}

			$response = wp_remote_get(
				$current,
				array(
					'timeout'     => 10,
					'redirection' => 3,
					'sslverify'   => true,
					'limit_response_size' => 1_500_000,
					'headers'     => array(
						'User-Agent' => 'Estandar Website Scanner/1.0',
					),
				)
			);

			if ( EWS_Scan_Queue::is_cancelled( $queue_id ) ) {
				throw new Exception( 'Scan cancelled.' );
			}

			$visited[] = $current;

			if ( '' !== $parent ) {
				$tree_edges[] = array( 'from' => $parent, 'to' => $current );
			}

			if ( is_wp_error( $response ) ) {
				$pages[] = array(
					'url'           => $current,
					'status_code'   => 0,
					'indexable'     => false,
					'error'         => $response->get_error_message(),
					'depth'         => $depth,
					'inbound_links' => (int) ( $inbound_counts[ $current ] ?? 0 ),
					'links'         => array(),
					'html'          => '',
				);
				continue;
			}

			$status = (int) wp_remote_retrieve_response_code( $response );
			$html   = (string) wp_remote_retrieve_body( $response );
			$links  = self::extract_internal_links( $html, $root, $root_host );

			foreach ( $links as $link ) {
				$inbound_counts[ $link ] = (int) ( $inbound_counts[ $link ] ?? 0 ) + 1;
				if ( ! in_array( $link, $visited, true ) && ! self::in_queue( $queue, $link ) ) {
					$queue[] = array(
						'url'    => $link,
						'depth'  => $depth + 1,
						'parent' => $current,
					);
				}
			}

			$page = self::analyze_page( $current, $status, $html, $root_host );
			$page['depth'] = $depth;
			$page['links'] = $links;
			$page['inbound_links'] = (int) ( $inbound_counts[ $current ] ?? 0 );
			$page['html'] = $html;
			$pages[] = $page;
		}

		return self::build_summary( $root, $pages, $tree_edges );
	}

	/**
	 * Analyze one crawled page.
	 *
	 * @param string $url Page URL.
	 * @param int    $status HTTP status.
	 * @param string $html HTML body.
	 * @param string $root_host Root host.
	 * @return array<string, mixed>
	 */
	private static function analyze_page( string $url, int $status, string $html, string $root_host ): array {
		preg_match( '/<title[^>]*>(.*?)<\/title>/is', $html, $title_match );
		$title = isset( $title_match[1] ) ? trim( wp_strip_all_tags( $title_match[1] ) ) : '';

		preg_match_all( '/<h1\b[^>]*>(.*?)<\/h1>/is', $html, $h1_matches );
		$h1 = '';
		if ( ! empty( $h1_matches[1][0] ) ) {
			$h1 = trim( wp_strip_all_tags( (string) $h1_matches[1][0] ) );
		}

		$meta_description = self::extract_meta_description( $html );
		$canonical        = self::has_tag_attribute( $html, 'link', 'rel', 'canonical' );
		$meta_robots      = self::extract_meta_robots( $html );
		$has_schema       = ( false !== strpos( strtolower( $html ), 'application/ld+json' ) );

		$links = self::extract_links( $html );
		$internal_links = 0;
		$external_links = 0;
		foreach ( $links as $link ) {
			$host = (string) wp_parse_url( $link, PHP_URL_HOST );
			if ( '' === $host || $host === $root_host ) {
				++$internal_links;
			} else {
				++$external_links;
			}
		}

		return array(
			'url'              => $url,
			'title'            => $title,
			'meta_description' => ( '' !== $meta_description ),
			'h1'               => $h1,
			'canonical'        => $canonical,
			'indexable'        => ( false === stripos( $meta_robots, 'noindex' ) ),
			'meta_robots'      => $meta_robots,
			'schema'           => $has_schema,
			'internal_links'   => $internal_links,
			'external_links'   => $external_links,
			'status_code'      => $status,
		);
	}

	/**
	 * Build crawl score and aggregates.
	 *
	 * @param string                             $root Root URL.
	 * @param array<int, array<string, mixed>>   $pages Crawled pages.
	 * @param array<int, array<string, string>>  $edges Parent-child edges.
	 * @return array<string, mixed>
	 */
	private static function build_summary( string $root, array $pages, array $edges ): array {
		$total = count( $pages );
		if ( 0 === $total ) {
			return array(
				'url'   => $root,
				'score' => 0,
				'pages' => array(),
				'tree_map' => array(),
			);
		}

		$points = 0;
		$max    = $total * 6;
		$max_depth = 0;
		foreach ( $pages as $page ) {
			$points += ! empty( $page['title'] ) ? 1 : 0;
			$points += ! empty( $page['meta_description'] ) ? 1 : 0;
			$points += ! empty( $page['h1'] ) ? 1 : 0;
			$points += ! empty( $page['canonical'] ) ? 1 : 0;
			$points += ! empty( $page['indexable'] ) ? 1 : 0;
			$points += ( isset( $page['status_code'] ) && (int) $page['status_code'] >= 200 && (int) $page['status_code'] < 400 ) ? 1 : 0;
			$max_depth = max( $max_depth, (int) ( $page['depth'] ?? 0 ) );
		}

		return array(
			'url'          => $root,
			'score'        => round( ( $points / max( 1, $max ) ) * 100, 2 ),
			'total_pages'  => $total,
			'max_depth'    => $max_depth,
			'pages'        => $pages,
			'tree_map'     => self::build_tree( $root, $edges ),
		);
	}

	/**
	 * Build tree map for visualization.
	 *
	 * @param string                            $root Root URL.
	 * @param array<int, array<string, string>> $edges Edges.
	 * @return array<string, mixed>
	 */
	private static function build_tree( string $root, array $edges ): array {
		$children_map = array();
		foreach ( $edges as $edge ) {
			$from = (string) ( $edge['from'] ?? '' );
			$to   = (string) ( $edge['to'] ?? '' );
			if ( '' === $from || '' === $to ) {
				continue;
			}
			if ( ! isset( $children_map[ $from ] ) ) {
				$children_map[ $from ] = array();
			}
			$children_map[ $from ][] = $to;
		}

		$visited = array();
		$build_node = static function ( string $url ) use ( &$build_node, &$children_map, &$visited ): array {
			if ( isset( $visited[ $url ] ) ) {
				return array( 'url' => $url, 'children' => array() );
			}
			$visited[ $url ] = true;
			$children = array();
			foreach ( array_values( array_unique( $children_map[ $url ] ?? array() ) ) as $child_url ) {
				$children[] = $build_node( (string) $child_url );
			}
			return array(
				'url'      => $url,
				'children' => $children,
			);
		};

		return $build_node( $root );
	}

	/**
	 * Check if URL is already queued.
	 *
	 * @param array<int, array<string, mixed>> $queue Queue.
	 * @param string                            $url URL.
	 * @return bool
	 */
	private static function in_queue( array $queue, string $url ): bool {
		foreach ( $queue as $item ) {
			if ( (string) ( $item['url'] ?? '' ) === $url ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Extract internal links from page HTML.
	 *
	 * @param string $html HTML content.
	 * @param string $root Root URL.
	 * @param string $root_host Root host.
	 * @return array<int, string>
	 */
	private static function extract_internal_links( string $html, string $root, string $root_host ): array {
		$links = self::extract_links( $html );
		$out   = array();

		foreach ( $links as $link ) {
			$normalized = self::normalize_link( $link, $root );
			if ( '' === $normalized ) {
				continue;
			}

			$host = (string) wp_parse_url( $normalized, PHP_URL_HOST );
			if ( '' === $host || $host === $root_host ) {
				$out[] = $normalized;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Extract links from HTML.
	 *
	 * @param string $html HTML content.
	 * @return array<int, string>
	 */
	private static function extract_links( string $html ): array {
		preg_match_all( '/<a\b[^>]*href=["\']([^"\']+)["\']/is', $html, $matches );
		if ( empty( $matches[1] ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'trim', $matches[1] ) ) );
	}

	/**
	 * Extract meta description content.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function extract_meta_description( string $html ): string {
		preg_match_all( '/<meta\b[^>]*>/is', $html, $meta_tags );
		foreach ( $meta_tags[0] ?? array() as $meta_tag ) {
			$name = self::extract_attribute( $meta_tag, 'name' );
			if ( 'description' !== strtolower( (string) $name ) ) {
				continue;
			}
			$content = self::extract_attribute( $meta_tag, 'content' );
			if ( '' !== trim( (string) $content ) ) {
				return trim( (string) $content );
			}
		}

		return '';
	}

	/**
	 * Extract meta robots content.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function extract_meta_robots( string $html ): string {
		preg_match_all( '/<meta\b[^>]*>/is', $html, $meta_tags );
		foreach ( $meta_tags[0] ?? array() as $meta_tag ) {
			$name = self::extract_attribute( $meta_tag, 'name' );
			if ( 'robots' !== strtolower( (string) $name ) ) {
				continue;
			}
			return (string) self::extract_attribute( $meta_tag, 'content' );
		}
		return '';
	}

	/**
	 * Check if tag with attribute and value exists.
	 *
	 * @param string $html HTML.
	 * @param string $tag Tag name.
	 * @param string $attribute Attribute name.
	 * @param string $value Attribute expected value.
	 * @return bool
	 */
	private static function has_tag_attribute( string $html, string $tag, string $attribute, string $value ): bool {
		$pattern = '/<' . preg_quote( $tag, '/' ) . '\b[^>]*>/is';
		preg_match_all( $pattern, $html, $tags );
		foreach ( $tags[0] ?? array() as $item ) {
			$attr = self::extract_attribute( $item, $attribute );
			if ( strtolower( (string) $attr ) === strtolower( $value ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Extract one HTML attribute.
	 *
	 * @param string $tag Tag text.
	 * @param string $attribute Attribute.
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

	/**
	 * Normalize absolute URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function normalize_url( string $url ): string {
		$url = esc_url_raw( trim( $url ) );
		return untrailingslashit( $url );
	}

	/**
	 * Normalize and absolutize one link.
	 *
	 * @param string $link Link href.
	 * @param string $root Root URL.
	 * @return string
	 */
	private static function normalize_link( string $link, string $root ): string {
		if ( '' === $link || 0 === strpos( $link, '#' ) || 0 === strpos( $link, 'mailto:' ) || 0 === strpos( $link, 'tel:' ) ) {
			return '';
		}

		if ( 0 === strpos( $link, 'http://' ) || 0 === strpos( $link, 'https://' ) ) {
			return untrailingslashit( esc_url_raw( $link ) );
		}

		if ( 0 === strpos( $link, '//' ) ) {
			$scheme = (string) wp_parse_url( $root, PHP_URL_SCHEME );
			return untrailingslashit( esc_url_raw( $scheme . ':' . $link ) );
		}

		$base = trailingslashit( $root );
		if ( 0 === strpos( $link, '/' ) ) {
			$host   = (string) wp_parse_url( $root, PHP_URL_HOST );
			$scheme = (string) wp_parse_url( $root, PHP_URL_SCHEME );
			return untrailingslashit( esc_url_raw( $scheme . '://' . $host . $link ) );
		}

		return untrailingslashit( esc_url_raw( $base . ltrim( $link, '/' ) ) );
	}
}
