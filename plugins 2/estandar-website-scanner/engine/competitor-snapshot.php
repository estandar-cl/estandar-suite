<?php
/**
 * Competitor snapshot analyzer.
 *
 * @package EstandarWebsiteScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build lightweight benchmark against competitor domains.
 */
class EWS_Competitor_Snapshot {

	/**
	 * Analyze competitors.
	 *
	 * @param string                    $target_url Target site URL.
	 * @param array<int, string>        $competitors Competitor domains or URLs.
	 * @param array<string, mixed>      $site_context Current site context.
	 * @return array<string, mixed>
	 */
	public static function analyze( string $target_url, array $competitors, array $site_context, ?float $deadline_ts = null, ?int $queue_id = null ): array {
		$rows = array();
		$site_health = isset( $site_context['score']['health_score'] ) ? (float) $site_context['score']['health_score'] : 0;

		foreach ( array_slice( $competitors, 0, 3 ) as $competitor ) {
			if ( EWS_Scan_Queue::is_cancelled( $queue_id ) ) {
				throw new Exception( 'Scan cancelled.' );
			}
			if ( null !== $deadline_ts && microtime( true ) > $deadline_ts ) {
				break;
			}
			$url = self::normalize_competitor_url( $competitor );
			if ( '' === $url ) {
				continue;
			}
			if ( ! EWS_Url_Safety::is_safe_url( $url ) ) {
				continue;
			}
			$ps       = EWS_PageSpeed_API::run( $url );
			$crawler  = EWS_Crawler::crawl( $url, 15, $queue_id );
			$seo      = EWS_SEO_Checker::analyze( $url, $crawler );
			$access   = EWS_Accessibility_Check::analyze( $url, $ps );
			$infra    = EWS_Infrastructure_Check::analyze( $url );
			$tech     = EWS_Tech_Detector::analyze( $url );
			$conv     = EWS_Conversion_Check::analyze( $url );
			$broken   = EWS_Broken_Links::analyze( $crawler, $queue_id );
			$structure= EWS_Internal_Structure::analyze( $crawler );
			$schema   = EWS_Structured_Data::analyze( $crawler );
			$keyword  = EWS_Keyword_Signals::analyze( $url );
			$perf     = isset( $ps['scores']['performance'] ) && is_numeric( $ps['scores']['performance'] ) ? (float) $ps['scores']['performance'] : null;

			$health = EWS_Score_Calculator::calculate(
				array(
					'performance'        => $perf,
					'seo_technical'      => $seo['score'] ?? 0,
					'accessibility'      => $access['score'] ?? 0,
					'infrastructure'     => $infra['score'] ?? 0,
					'architecture'       => $tech['score'] ?? 0,
					'crawler_structure'  => $structure['score'] ?? 0,
					'conversion_signals' => $conv['score'] ?? 0,
					'broken_links'       => $broken['score'] ?? 0,
					'structured_data'    => $schema['score'] ?? 0,
					'keyword_signals'    => $keyword['score'] ?? 0,
				)
			);
			$health_score = (float) ( $health['health_score'] ?? 0 );
			$difference = ( $site_health > 0 ) ? round( ( ( $health_score - $site_health ) / $site_health ) * 100, 2 ) : 0;

			$rows[] = array(
				'domain'       => (string) wp_parse_url( $url, PHP_URL_HOST ),
				'performance'  => $perf,
				'seo'          => $seo['score'] ?? null,
				'health_score' => $health_score,
				'difference'   => $difference,
			);
		}

		$avg_health = $site_health * 10;
		if ( ! empty( $rows ) ) {
			$sum = 0.0;
			foreach ( $rows as $row ) {
				$sum += (float) ( $row['health_score'] ?? 0 );
			}
			$avg_health = round( ( $sum / count( $rows ) ) * 10, 2 );
		}

		return array(
			'score'       => $avg_health,
			'site_score'  => $site_health,
			'competitors' => $rows,
			'message'     => empty( $rows ) ? 'No competitors were provided for benchmarking.' : '',
		);
	}

	/**
	 * Normalize competitor input.
	 *
	 * @param string $raw Raw domain/url.
	 * @return string
	 */
	private static function normalize_competitor_url( string $raw ): string {
		$raw = trim( sanitize_text_field( $raw ) );
		if ( '' === $raw ) {
			return '';
		}
		if ( 0 !== strpos( $raw, 'http://' ) && 0 !== strpos( $raw, 'https://' ) ) {
			$raw = 'https://' . $raw;
		}
		$url = esc_url_raw( $raw );
		return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : '';
	}
}
