<?php
/**
 * Report generation module.
 *
 * @package EstandarWebsiteScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build final report payload.
 */
class EWS_Report_Generator {

	/**
	 * Option key for report index.
	 */
	private const REPORT_INDEX_OPTION = 'ews_reports_index';

	/**
	 * Build and persist a normalized report array.
	 *
	 * @param array<string, mixed> $payload Raw scan data.
	 * @return array<string, mixed>
	 */
	public static function build( array $payload ): array {
		$report_id  = self::generate_report_id();
		$timestamp  = current_time( 'mysql' );
		$score_data = is_array( $payload['score'] ?? null ) ? $payload['score'] : array();

		$report = array(
			'report_id'    => $report_id,
			'generated_at' => $timestamp,
			'url'          => esc_url_raw( (string) ( $payload['url'] ?? '' ) ),
			'score'        => $score_data,
			'performance'  => $payload['pagespeed'] ?? array(),
			'technology'   => $payload['tech'] ?? array(),
			'seo'          => $payload['seo'] ?? array(),
			'accessibility'=> $payload['accessibility'] ?? array(),
			'infrastructure' => $payload['infra'] ?? array(),
			'crawler'        => $payload['crawler'] ?? array(),
			'internal_structure' => $payload['internal_structure'] ?? array(),
			'broken_links'   => $payload['broken_links'] ?? array(),
			'structured_data'=> $payload['structured_data'] ?? array(),
			'keyword_signals'=> $payload['keyword_signals'] ?? array(),
			'site_intent'    => $payload['site_intent'] ?? array(),
			'competitor_snapshot' => $payload['competitor_snapshot'] ?? array(),
			'crux'           => $payload['crux'] ?? array(),
			'conversion'     => $payload['conversion'] ?? array(),
			'seo_opportunities' => $payload['seo_opportunities'] ?? array(),
			'diagnostics'    => $payload['diagnostics'] ?? array(),
			'value_analysis' => $payload['value_analysis'] ?? array(),
			'ai_analysis'    => $payload['ai_analysis'] ?? array(),
			// Preserve data URIs (e.g. PageSpeed/Lighthouse embedded images).
			'custom_cover_image'   => ( isset( $payload['custom_cover_image'] ) && 0 === strpos( (string) $payload['custom_cover_image'], 'data:' ) )
				? (string) $payload['custom_cover_image']
				: esc_url_raw( (string) ( $payload['custom_cover_image'] ?? '' ) ),
			'custom_website_image' => ( isset( $payload['custom_website_image'] ) && 0 === strpos( (string) $payload['custom_website_image'], 'data:' ) )
				? (string) $payload['custom_website_image']
				: esc_url_raw( (string) ( $payload['custom_website_image'] ?? '' ) ),
			'progress_timeline'    => is_array( $payload['progress_timeline'] ?? null ) ? $payload['progress_timeline'] : array(),
			'design_marketing_analysis_draft'    => sanitize_textarea_field( (string) ( $payload['design_marketing_analysis_draft'] ?? '' ) ),
			'design_marketing_analysis_approved' => sanitize_textarea_field( (string) ( $payload['design_marketing_analysis_approved'] ?? '' ) ),
			'design_marketing_analysis_status'   => sanitize_text_field( (string) ( $payload['design_marketing_analysis_status'] ?? '' ) ),
			'evidence_model_version'  => sanitize_text_field( (string) ( $payload['evidence_model_version'] ?? '' ) ),
			'decisions_model_version' => sanitize_text_field( (string) ( $payload['decisions_model_version'] ?? '' ) ),
			'decision_site_intent'    => sanitize_text_field( (string) ( $payload['decision_site_intent'] ?? '' ) ),
			'blockers'                => is_array( $payload['blockers'] ?? null ) ? $payload['blockers'] : array(),
			'evidence'                => is_array( $payload['evidence'] ?? null ) ? $payload['evidence'] : array(),
			'top_decisions'           => is_array( $payload['top_decisions'] ?? null ) ? $payload['top_decisions'] : array(),
			'all_decisions_debug'     => is_array( $payload['all_decisions_debug'] ?? null ) ? $payload['all_decisions_debug'] : array(),
			'report_language'=> $payload['report_language'] ?? 'en',
		);

		$report['summary']         = self::build_summary( $report );
		$report['recommendations'] = self::build_recommendations( $report );

		self::store_report( $report );

		return $report;
	}

	/**
	 * Get report by ID.
	 *
	 * @param string $report_id Report ID.
	 * @return array<string, mixed>|null
	 */
	public static function get_report( string $report_id ): ?array {
		$report_id = sanitize_text_field( $report_id );
		$report    = get_transient( 'ews_report_' . $report_id );

		if ( false === $report || ! is_array( $report ) ) {
			return null;
		}

		return $report;
	}

	/**
	 * Update stored report payload.
	 *
	 * @param array<string, mixed> $report Report payload.
	 * @return void
	 */
	public static function update_report( array $report ): void {
		$report_id = isset( $report['report_id'] ) ? sanitize_text_field( (string) $report['report_id'] ) : '';
		if ( '' === $report_id ) {
			return;
		}
		set_transient( 'ews_report_' . $report_id, $report, WEEK_IN_SECONDS * 4 );
	}

	/**
	 * Return indexed report metadata.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_reports_index(): array {
		$index = get_option( self::REPORT_INDEX_OPTION, array() );
		return is_array( $index ) ? $index : array();
	}

	/**
	 * Persist full report and index metadata.
	 *
	 * @param array<string, mixed> $report Report payload.
	 * @return void
	 */
	private static function store_report( array $report ): void {
		$report_id = (string) ( $report['report_id'] ?? '' );
		if ( '' === $report_id ) {
			return;
		}

		set_transient( 'ews_report_' . $report_id, $report, WEEK_IN_SECONDS * 4 );

		$index = self::get_reports_index();
		array_unshift(
			$index,
			array(
				'report_id'     => $report_id,
				'url'           => $report['url'] ?? '',
				'generated_at'  => $report['generated_at'] ?? '',
				'health_score'  => $report['score']['health_score'] ?? 0,
				'status'        => $report['score']['status'] ?? '',
			)
		);

		$index = array_slice( $index, 0, 50 );
		update_option( self::REPORT_INDEX_OPTION, $index, false );
	}

	/**
	 * Build strengths and opportunities summary.
	 *
	 * @param array<string, mixed> $report Report payload.
	 * @return array<string, array<int, string>>
	 */
	private static function build_summary( array $report ): array {
		$strengths     = array();
		$opportunities = array();

		$components = $report['score']['components'] ?? array();
		$unavailable = $report['score']['unavailable_components'] ?? array();
		foreach ( $components as $name => $value ) {
			$label = ucwords( str_replace( '_', ' ', (string) $name ) );
			if ( in_array( $name, $unavailable, true ) || ! is_numeric( $value ) ) {
				$opportunities[] = $label . ' score is unavailable due to missing data source.';
				continue;
			}

			if ( (float) $value >= 80 ) {
				$strengths[] = $label . ' is strong (' . round( (float) $value, 2 ) . '/100).';
			} elseif ( (float) $value < 60 ) {
				$opportunities[] = $label . ' needs improvement (' . round( (float) $value, 2 ) . '/100).';
			}
		}

		if ( empty( $strengths ) ) {
			$strengths[] = 'No major strengths detected yet.';
		}
		if ( empty( $opportunities ) ) {
			$opportunities[] = 'No critical issues detected in the scanned modules.';
		}

		return array(
			'strengths'     => $strengths,
			'opportunities' => $opportunities,
		);
	}

	/**
	 * Build practical recommendations.
	 *
	 * @param array<string, mixed> $report Report payload.
	 * @return array<int, string>
	 */
	private static function build_recommendations( array $report ): array {
		$recommendations = array();
		$seo_checks      = $report['seo']['checks'] ?? array();
		$infra_checks    = $report['infrastructure']['checks'] ?? array();
		$diagnostics     = $report['diagnostics'] ?? array();

		if ( isset( $seo_checks['meta_description']['passed'] ) && ! $seo_checks['meta_description']['passed'] ) {
			$recommendations[] = 'Add a clear meta description to improve search snippets.';
		}
		if ( isset( $seo_checks['h1']['passed'] ) && ! $seo_checks['h1']['passed'] ) {
			$recommendations[] = 'Add a primary H1 heading on the homepage.';
		}
		if ( isset( $infra_checks['https']['passed'] ) && ! $infra_checks['https']['passed'] ) {
			$recommendations[] = 'Enable HTTPS and force redirection to secure URLs.';
		}
		if ( ! empty( $diagnostics['pagespeed_errors'] ) ) {
			$recommendations[] = 'PageSpeed API returned errors. Add a valid PSI API key and rerun the scan.';
		}
		if ( isset( $report['conversion']['signals']['value_proposition'] ) && ! $report['conversion']['signals']['value_proposition'] ) {
			$recommendations[] = 'Strengthen the hero value proposition to improve conversion intent clarity.';
		}
		if ( isset( $report['crawler']['score'] ) && is_numeric( $report['crawler']['score'] ) && (float) $report['crawler']['score'] < 70 ) {
			$recommendations[] = 'Improve internal SEO structure across crawled pages (title/meta/H1/canonical/indexability).';
		}
		if ( isset( $report['broken_links']['broken_count'] ) && (int) $report['broken_links']['broken_count'] > 0 ) {
			$recommendations[] = 'Fix broken links (4xx/5xx) and reduce redirect chains to improve crawl efficiency.';
		}
		if ( isset( $report['structured_data']['schema_detected'] ) && ! $report['structured_data']['schema_detected'] ) {
			$recommendations[] = 'Implement JSON-LD structured data (Organization, LocalBusiness or relevant schema types).';
		}
		if ( isset( $report['keyword_signals']['in_meta'] ) && ! $report['keyword_signals']['in_meta'] ) {
			$recommendations[] = 'Include primary keyword phrase in meta description to improve topical relevance.';
		}
		if ( ! empty( $report['seo_opportunities']['opportunities'] ) && is_array( $report['seo_opportunities']['opportunities'] ) ) {
			foreach ( array_slice( $report['seo_opportunities']['opportunities'], 0, 3 ) as $item ) {
				$recommendations[] = 'SEO opportunity: ' . (string) $item;
			}
		}
		if ( ! empty( $report['site_intent']['strategic_recommendation'] ) ) {
			$recommendations[] = 'Strategic alignment: ' . (string) $report['site_intent']['strategic_recommendation'];
		}

		$priority_actions = $report['value_analysis']['priority_actions'] ?? array();
		if ( ! empty( $priority_actions ) ) {
			foreach ( array_slice( $priority_actions, 0, 2 ) as $action ) {
				$title = trim( (string) ( $action['title'] ?? '' ) );
				if ( '' !== $title ) {
					$recommendations[] = 'Priority action: ' . $title . '.';
				}
			}
		}

		$opportunities = $report['performance']['mobile']['opportunities'] ?? array();
		if ( ! empty( $opportunities ) ) {
			foreach ( array_slice( $opportunities, 0, 3 ) as $opportunity ) {
				$title = trim( (string) ( $opportunity['title'] ?? '' ) );
				if ( '' !== $title ) {
					$recommendations[] = 'Performance: ' . $title . '.';
				}
			}
		}

		if ( empty( $recommendations ) ) {
			$recommendations[] = 'Maintain monitoring and rerun scans after major content or plugin changes.';
		}

		return array_values( array_unique( $recommendations ) );
	}

	/**
	 * Generate report identifier.
	 *
	 * @return string
	 */
	private static function generate_report_id(): string {
		return str_replace( '.', '', uniqid( 'ews_', true ) );
	}
}
