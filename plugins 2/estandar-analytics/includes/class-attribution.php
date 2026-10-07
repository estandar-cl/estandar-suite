<?php
/**
 * Deterministic project attribution resolver for Estandar Analytics.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EA_Attribution {

	/**
	 * Resolve a canonical project slug from a URL.
	 *
	 * Reads active projects from Core Registry via:
	 *   estandar_core_get_projects()
	 *
	 * Resolution order per project (first match wins, outer loop):
	 *   1. If project has url_patterns[]: exact path match or path-prefix match per pattern.
	 *   2. Fallback (no url_patterns): project slug as a path segment.
	 *
	 * Matching rules:
	 *   - Pattern '/altacruz' matches '/altacruz' (exact) and '/altacruz/any/sub/page'.
	 *   - Pattern '/altacruz' does NOT match '/altacruz-premium' (different segment).
	 *   - Trailing slashes are stripped before comparison (normalize_page).
	 *
	 * Active projects only.
	 * Returns '' when the registry is empty, unavailable, or no project matches.
	 * Result is cached per URL in the WP object cache for the lifetime of the request.
	 *
	 * @param string $url Full URL or path (e.g. 'https://example.com/altacruz/' or '/altacruz').
	 * @return string Matched project slug, or '' on no match.
	 */
	public static function resolve_project_slug( $url ) {
		$url = (string) $url;
		if ( $url === '' ) {
			return '';
		}

		// Per-request object-cache (no TTL = evicted at end of request, never stale).
		$cache_key = 'ea_ps_' . md5( $url );
		$cached    = wp_cache_get( $cache_key, 'ea_attribution' );
		if ( $cached !== false ) {
			return (string) $cached;
		}

		$registry = function_exists( 'estandar_core_get_projects' ) ? (array) estandar_core_get_projects() : array();
		if ( empty( $registry ) ) {
			wp_cache_set( $cache_key, '', 'ea_attribution' );
			return '';
		}

		// Normalized path: leading slash, no trailing slash. e.g. '/altacruz'.
		$path = EA_Utils::normalize_page( $url );

		$slug = '';

		foreach ( $registry as $project ) {
			if ( ! is_array( $project ) ) {
				continue;
			}

			$project_slug = trim( (string) ( $project['project_id'] ?? '' ) );
			if ( $project_slug === '' ) {
				continue;
			}

			$url_patterns = isset( $project['url_patterns'] ) && is_array( $project['url_patterns'] )
				? $project['url_patterns']
				: array();

			if ( ! empty( $url_patterns ) ) {
				// Match normalized path against each configured pattern.
				foreach ( $url_patterns as $pattern ) {
					$pattern = trim( (string) $pattern );
					if ( $pattern === '' ) {
						continue;
					}
					$norm = EA_Utils::normalize_page( $pattern );
					// Reject degenerate patterns that would match everything.
					if ( $norm === '' || $norm === '/' ) {
						continue;
					}
					// Exact match OR path starts with pattern followed by '/'.
					if ( $path === $norm || strpos( $path, $norm . '/' ) === 0 ) {
						$slug = $project_slug;
						break 2;
					}
				}
			} else {
				// Fallback: treat the project slug itself as a path segment.
				// Matches '/altacruz' (exact) and '/altacruz/anything'.
				// Does NOT match '/altacruz-premium'.
				if ( $path === '/' . $project_slug || strpos( $path, '/' . $project_slug . '/' ) !== false ) {
					$slug = $project_slug;
					break;
				}
			}
		}

		wp_cache_set( $cache_key, $slug, 'ea_attribution' );
		return $slug;
	}
}
