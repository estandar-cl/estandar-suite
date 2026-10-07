<?php
/**
 * Score calculator module.
 *
 * @package EstandarWebsiteScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Calculate website health score.
 */
class EWS_Score_Calculator {

	/**
	 * Calculate normalized 0-10 score.
	 *
	 * @param array<string, float|int|null> $scores Category scores (0-100).
	 * @return array<string, mixed>
	 */
	public static function calculate( array $scores ): array {
		$components = array(
			'performance'          => self::normalize_nullable_score( $scores['performance'] ?? null ),
			'seo_technical'        => self::normalize_nullable_score( $scores['seo_technical'] ?? null ),
			'accessibility'        => self::normalize_nullable_score( $scores['accessibility'] ?? null ),
			'infrastructure'       => self::normalize_nullable_score( $scores['infrastructure'] ?? null ),
			'architecture'         => self::normalize_nullable_score( $scores['architecture'] ?? null ),
			'crawler_structure'    => self::normalize_nullable_score( $scores['crawler_structure'] ?? null ),
			'conversion_signals'   => self::normalize_nullable_score( $scores['conversion_signals'] ?? null ),
			'broken_links'         => self::normalize_nullable_score( $scores['broken_links'] ?? null ),
			'structured_data'      => self::normalize_nullable_score( $scores['structured_data'] ?? null ),
			'keyword_signals'      => self::normalize_nullable_score( $scores['keyword_signals'] ?? null ),
		);

		$weights = array(
			'performance'          => 0.20,
			'seo_technical'        => 0.20,
			'accessibility'        => 0.10,
			'infrastructure'       => 0.10,
			'architecture'         => 0.10,
			'crawler_structure'    => 0.10,
			'conversion_signals'   => 0.08,
			'broken_links'         => 0.05,
			'structured_data'      => 0.04,
			'keyword_signals'      => 0.03,
		);

		$weighted_sum       = 0.0;
		$effective_weight   = 0.0;
		$unavailable_scores = array();

		foreach ( $weights as $component => $weight ) {
			$value = $components[ $component ];
			if ( null === $value ) {
				$unavailable_scores[] = $component;
				continue;
			}

			$weighted_sum += ( $value * $weight );
			$effective_weight += $weight;
		}

		$normalized_percentage = ( $effective_weight > 0 ) ? ( $weighted_sum / $effective_weight ) : 0;
		$health_score          = round( $normalized_percentage / 10, 2 );

		return array(
			'health_score'           => $health_score,
			'status'                 => self::get_status_label( $health_score ),
			'components'             => $components,
			'unavailable_components' => $unavailable_scores,
			'coverage'               => round( $effective_weight * 100, 2 ),
		);
	}

	/**
	 * Return nullable score inside 0-100 range.
	 *
	 * @param float|int|null $value Raw score.
	 * @return float|null
	 */
	private static function normalize_nullable_score( $value ): ?float {
		if ( ! is_numeric( $value ) ) {
			return null;
		}

		return max( 0, min( 100, round( (float) $value, 2 ) ) );
	}

	/**
	 * Resolve score status label.
	 *
	 * @param float $score Health score in 0-10 range.
	 * @return string
	 */
	private static function get_status_label( float $score ): string {
		if ( $score >= 9 ) {
			return 'Excelente';
		}

		if ( $score >= 8 ) {
			return 'Muy bueno';
		}

		if ( $score >= 7 ) {
			return 'Bueno';
		}

		if ( $score >= 5 ) {
			return 'Mejorable';
		}

		if ( $score >= 4 ) {
			return 'Debil';
		}

		return 'Critico';
	}
}
