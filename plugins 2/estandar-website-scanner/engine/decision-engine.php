<?php
/**
 * Operational decision engine.
 *
 * @package EstandarWebsiteScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds structured evidence and prioritized decisions from scan payloads.
 */
class EWS_Decision_Engine {

	private const EVIDENCE_MODEL_VERSION  = 'ews-evidence-v1';
	private const DECISIONS_MODEL_VERSION = 'ews-decisions-v1';

	/**
	 * Build decision layer payload.
	 *
	 * @param array<string, mixed> $report Scan payload.
	 * @return array<string, mixed>
	 */
	public static function analyze( array $report ): array {
		$site_intent = self::resolve_site_intent( $report );
		$evidence    = self::build_evidence( $report );
		$debug       = self::evaluate_decisions( $evidence, $site_intent );
		$blockers    = self::select_blockers( $debug );

		return array(
			'evidence_model_version'  => self::EVIDENCE_MODEL_VERSION,
			'decisions_model_version' => self::DECISIONS_MODEL_VERSION,
			'decision_site_intent'    => $site_intent,
			'blockers'                => $blockers,
			'evidence'                => $evidence,
			'top_decisions'           => self::select_top_decisions( $debug ),
			'all_decisions_debug'     => $debug,
		);
	}

	/**
	 * Build normalized evidence from existing scan data only.
	 *
	 * @param array<string, mixed> $report Scan payload.
	 * @return array<string, array<string, mixed>>
	 */
	private static function build_evidence( array $report ): array {
		$diagnostics        = (array) ( $report['diagnostics'] ?? array() );
		$seo_deep_summary   = (array) ( $report['seo']['deep_analysis']['summary'] ?? array() );
		$access_checks      = (array) ( $report['accessibility']['checks'] ?? array() );
		$conversion_signals = (array) ( $report['conversion']['signals'] ?? array() );
		$internal_structure = (array) ( $report['internal_structure'] ?? array() );
		$structured_data    = (array) ( $report['structured_data'] ?? array() );
		$broken_links       = (array) ( $report['broken_links'] ?? array() );
		$crux               = (array) ( $report['crux'] ?? array() );

		$pagespeed_available = ! empty( $diagnostics['pagespeed_available'] );
		$crux_available      = ! empty( $diagnostics['crux_available'] );

		$missing_h1 = self::first_numeric(
			array(
				$seo_deep_summary['missing_h1'] ?? null,
				self::homepage_check_failed_count( $report, 'h1' ),
			)
		);
		$missing_meta = self::first_numeric(
			array(
				$seo_deep_summary['missing_description'] ?? null,
				self::homepage_check_failed_count( $report, 'meta_description' ),
			)
		);
		$missing_alt = self::extract_missing_count( (array) ( $access_checks['images_alt'] ?? array() ) );

		return array(
			'pagespeed_available' => self::evidence_item( 'pagespeed_available', 'measurement', (bool) $pagespeed_available, 'boolean', true, 'high', 85, 'low', 'site', 'pagespeed', null, 0.95 ),
			'crux_available'      => self::evidence_item( 'crux_available', 'measurement', (bool) $crux_available, 'boolean', true, 'medium', 70, 'low', 'site', 'crux', null, 0.95 ),
			'performance_lcp'     => self::evidence_item( 'performance_lcp', 'performance', self::metric_value( $report, $crux, 'lcp' ), 'ms', 2500, 'high', 75, 'medium', 'site', self::metric_source( $report, $crux, 'lcp' ), null, self::metric_confidence( $report, $crux, 'lcp' ) ),
			'performance_cls'     => self::evidence_item( 'performance_cls', 'performance', self::metric_value( $report, $crux, 'cls' ), 'score', 0.1, 'medium', 60, 'medium', 'site', self::metric_source( $report, $crux, 'cls' ), null, self::metric_confidence( $report, $crux, 'cls' ) ),
			'missing_h1'          => self::evidence_item( 'missing_h1', 'seo', $missing_h1, 'count', 0, 'high', ( $missing_h1 > 0 ? 70 : 0 ), 'low', 'site', 'crawler', null, 0.85 ),
			'missing_meta_description' => self::evidence_item( 'missing_meta_description', 'seo', $missing_meta, 'count', 0, 'medium', ( $missing_meta > 0 ? 62 : 0 ), 'low', 'site', 'crawler', null, 0.85 ),
			'broken_links'        => self::evidence_item( 'broken_links', 'structure', (int) ( $broken_links['broken_count'] ?? 0 ), 'count', 0, 'high', self::severity_by_count( (int) ( $broken_links['broken_count'] ?? 0 ), 65 ), 'low', 'site', 'crawler', null, 0.85 ),
			'internal_link_depth' => self::evidence_item( 'internal_link_depth', 'structure', (int) ( $internal_structure['max_depth'] ?? 0 ), 'depth', 3, 'medium', ( (int) ( $internal_structure['max_depth'] ?? 0 ) > 3 ? 55 : 0 ), 'medium', 'site', 'crawler', null, 0.8 ),
			'cta_presence'        => self::evidence_item( 'cta_presence', 'conversion', ! empty( $conversion_signals['cta'] ), 'boolean', true, 'high', empty( $conversion_signals['cta'] ) ? 80 : 0, 'low', 'site', 'html', null, 0.75 ),
			'contact_path_presence' => self::evidence_item( 'contact_path_presence', 'conversion', self::has_contact_path( $conversion_signals ), 'boolean', true, 'high', self::has_contact_path( $conversion_signals ) ? 0 : 82, 'low', 'site', 'html', null, 0.8 ),
			'structured_data_presence' => self::evidence_item( 'structured_data_presence', 'seo', ! empty( $structured_data['schema_detected'] ), 'boolean', true, 'medium', empty( $structured_data['schema_detected'] ) ? 45 : 0, 'medium', 'site', 'crawler', null, 0.75 ),
			'image_alt_missing'   => self::evidence_item( 'image_alt_missing', 'accessibility', $missing_alt, 'count', 0, 'medium', self::severity_by_count( $missing_alt, 34 ), 'low', 'page', 'html', null, 0.75 ),
		);
	}

	/**
	 * @param array<string, array<string, mixed>> $evidence Evidence map.
	 * @return array<int, array<string, mixed>>
	 */
	private static function evaluate_decisions( array $evidence, string $site_intent ): array {
		$definitions = self::decision_definitions();
		$evaluated   = array();

		foreach ( $definitions as $definition ) {
			$refs       = (array) ( $definition['evidence_refs'] ?? array() );
			$confidence = self::min_confidence_for_refs( $evidence, $refs );
			$active     = self::condition_matches( (string) $definition['condition'], $evidence, $site_intent ) && $confidence >= (float) $definition['min_confidence'];
			$severity   = self::severity_for_refs( $evidence, $refs, (float) $definition['severity_score'] );
			$priority   = self::final_priority( $severity, (string) $definition['fix_cost'], (string) $definition['impact_type'] );

			$evaluated[] = array_merge(
				$definition,
				array(
					'active'         => $active,
					'confidence'     => $confidence,
					'severity_score' => $severity,
					'site_intent'    => $site_intent,
					'final_priority' => $active ? $priority : 0,
				)
			);
		}

		return $evaluated;
	}

	/**
	 * @param array<int, array<string, mixed>> $evaluated Evaluated decisions.
	 * @return array<int, array<string, mixed>>
	 */
	private static function select_top_decisions( array $evaluated ): array {
		$active = array_values(
			array_filter(
				$evaluated,
				static function ( array $decision ): bool {
					return ! empty( $decision['active'] ) && empty( $decision['is_blocker'] );
				}
			)
		);

		$by_group = array();
		foreach ( $active as $decision ) {
			$group = (string) ( $decision['decision_group'] ?? '' );
			if ( '' === $group ) {
				$group = (string) ( $decision['decision_id'] ?? '' );
			}
			if ( ! isset( $by_group[ $group ] ) || (float) $decision['final_priority'] > (float) $by_group[ $group ]['final_priority'] ) {
				$by_group[ $group ] = $decision;
			}
		}

		$blocked = array();
		foreach ( $by_group as $decision ) {
			foreach ( (array) ( $decision['blocks'] ?? array() ) as $blocked_id ) {
				$blocked[ (string) $blocked_id ] = true;
			}
		}

		$selected = array_values(
			array_filter(
				$by_group,
				static function ( array $decision ) use ( $blocked ): bool {
					return empty( $blocked[ (string) ( $decision['decision_id'] ?? '' ) ] );
				}
			)
		);

		usort(
			$selected,
			static function ( array $a, array $b ): int {
				$stage_a = self::stage_weight( (string) ( $a['decision_stage'] ?? '' ) );
				$stage_b = self::stage_weight( (string) ( $b['decision_stage'] ?? '' ) );
				if ( $stage_a !== $stage_b ) {
					return $stage_b <=> $stage_a;
				}
				return (float) $b['final_priority'] <=> (float) $a['final_priority'];
			}
		);

		$selected = self::apply_category_caps( self::complete_minimum_output( $selected ) );

		return array_map( array( __CLASS__, 'public_decision_shape' ), array_slice( $selected, 0, 3 ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $evaluated Evaluated decisions.
	 * @return array<int, array<string, mixed>>
	 */
	private static function select_blockers( array $evaluated ): array {
		$blockers = array_values(
			array_filter(
				$evaluated,
				static function ( array $decision ): bool {
					return ! empty( $decision['active'] ) && ! empty( $decision['is_blocker'] );
				}
			)
		);

		usort(
			$blockers,
			static function ( array $a, array $b ): int {
				return (float) $b['final_priority'] <=> (float) $a['final_priority'];
			}
		);

		return array_map( array( __CLASS__, 'public_decision_shape' ), $blockers );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function decision_definitions(): array {
		return array(
			self::decision( 'restore_measurement_integrity', 'measurement_integrity', 'foundation', 'measurement', 'measurement', 'measurement_unavailable', 85, 'low', 0.9, array( 'fix_lcp_mobile', 'fix_cls' ), 'Sin medición confiable, cualquier optimización de performance es especulativa.', array( 'pagespeed_available', 'crux_available' ), true ),
			self::decision( 'fix_lcp_mobile', 'performance_core', 'optimization', 'performance', 'user', 'lcp_over_threshold', 75, 'medium', 0.7, array(), 'Mejora velocidad percibida, experiencia móvil y probabilidad de conversión.', array( 'performance_lcp' ) ),
			self::decision( 'fix_cls', 'performance_core', 'optimization', 'performance', 'user', 'cls_over_threshold', 60, 'medium', 0.7, array(), 'Reduce fricción visual y mejora estabilidad de experiencia.', array( 'performance_cls' ) ),
			self::decision( 'add_home_h1', 'seo_structure', 'foundation', 'seo', 'technical', 'missing_h1', 70, 'low', 0.7, array(), 'Aclara el tema principal para usuarios y motores de búsqueda.', array( 'missing_h1' ) ),
			self::decision( 'add_meta_descriptions', 'seo_snippet', 'foundation', 'seo', 'technical', 'missing_meta_description', 62, 'low', 0.7, array(), 'Mejora presentación en resultados de búsqueda y potencial CTR.', array( 'missing_meta_description' ) ),
			self::decision( 'fix_broken_links', 'structure_integrity', 'foundation', 'structure', 'user', 'broken_links', 65, 'low', 0.7, array( 'reduce_navigation_depth' ), 'Evita pérdida de confianza, errores de navegación y desperdicio de rastreo.', array( 'broken_links' ) ),
			self::decision( 'reduce_navigation_depth', 'structure_integrity', 'optimization', 'structure', 'user', 'internal_depth', 55, 'medium', 0.7, array(), 'Hace más accesibles páginas importantes y mejora descubrimiento interno.', array( 'internal_link_depth' ) ),
			self::decision( 'add_contact_path', 'conversion_base', 'foundation', 'conversion', 'business', 'missing_contact_path', 82, 'low', 0.7, array( 'add_primary_cta' ), 'Reduce fricción comercial y pérdida de leads.', array( 'contact_path_presence' ) ),
			self::decision( 'add_primary_cta', 'conversion_base', 'foundation', 'conversion', 'business', 'missing_cta', 80, 'low', 0.7, array(), 'Convierte tráfico pasivo en acción medible.', array( 'cta_presence' ) ),
			self::decision( 'add_structured_data', 'seo_semantics', 'enhancement', 'seo', 'technical', 'missing_structured_data', 45, 'medium', 0.7, array(), 'Mejora comprensión semántica del sitio y elegibilidad para resultados enriquecidos.', array( 'structured_data_presence' ) ),
			self::decision( 'fix_missing_alt_text', 'accessibility_base', 'enhancement', 'accessibility', 'technical', 'missing_alt', 34, 'low', 0.7, array(), 'Mejora accesibilidad y contexto semántico de contenidos visuales.', array( 'image_alt_missing' ) ),
		);
	}

	private static function decision( string $id, string $group, string $stage, string $category, string $impact_type, string $condition, float $severity, string $fix_cost, float $min_confidence, array $blocks, string $business_reason, array $evidence_refs, bool $is_blocker = false ): array {
		return array(
			'decision_id'     => $id,
			'decision_group'  => $group,
			'decision_stage'  => $stage,
			'category'        => $category,
			'impact_type'     => $impact_type,
			'condition'       => $condition,
			'severity_score'  => $severity,
			'fix_cost'        => $fix_cost,
			'min_confidence'  => $min_confidence,
			'blocks'          => $blocks,
			'business_reason' => $business_reason,
			'evidence_refs'   => $evidence_refs,
			'is_blocker'      => $is_blocker,
		);
	}

	private static function evidence_item( string $type, string $category, $value, string $unit, $threshold, string $impact, float $severity_score, string $fix_cost, string $affected_scope, string $source, ?string $evidence_url, float $confidence ): array {
		return array(
			'type'             => $type,
			'category'         => $category,
			'value'            => $value,
			'unit'             => $unit,
			'threshold'        => $threshold,
			'impact'           => $impact,
			'severity_score'   => round( max( 0, min( 100, $severity_score ) ), 2 ),
			'fix_cost'         => $fix_cost,
			'affected_scope'   => $affected_scope,
			'source'           => $source,
			'evidence_url'     => $evidence_url,
			'detected_at'      => current_time( 'mysql' ),
			'confidence'       => round( max( 0, min( 1, $confidence ) ), 2 ),
		);
	}

	private static function public_decision_shape( array $decision ): array {
		return array(
			'decision_id'     => (string) ( $decision['decision_id'] ?? '' ),
			'decision_group'  => (string) ( $decision['decision_group'] ?? '' ),
			'decision_stage'  => (string) ( $decision['decision_stage'] ?? '' ),
			'category'        => (string) ( $decision['category'] ?? '' ),
			'impact_type'     => (string) ( $decision['impact_type'] ?? '' ),
			'severity_score'  => round( (float) ( $decision['severity_score'] ?? 0 ), 2 ),
			'fix_cost'        => (string) ( $decision['fix_cost'] ?? '' ),
			'min_confidence'  => round( (float) ( $decision['min_confidence'] ?? 0 ), 2 ),
			'confidence'      => round( (float) ( $decision['confidence'] ?? 0 ), 2 ),
			'blocks'          => (array) ( $decision['blocks'] ?? array() ),
			'final_priority'  => round( (float) ( $decision['final_priority'] ?? 0 ), 2 ),
			'business_reason' => (string) ( $decision['business_reason'] ?? '' ),
			'evidence_refs'   => (array) ( $decision['evidence_refs'] ?? array() ),
		);
	}

	private static function condition_matches( string $condition, array $evidence, string $site_intent ): bool {
		switch ( $condition ) {
			case 'measurement_unavailable':
				return empty( $evidence['pagespeed_available']['value'] ) || empty( $evidence['crux_available']['value'] );
			case 'lcp_over_threshold':
				return self::numeric_over_threshold( $evidence, 'performance_lcp' );
			case 'cls_over_threshold':
				return self::numeric_over_threshold( $evidence, 'performance_cls' );
			case 'missing_h1':
				return self::numeric_over_threshold( $evidence, 'missing_h1' );
			case 'missing_meta_description':
				return self::numeric_over_threshold( $evidence, 'missing_meta_description' );
			case 'broken_links':
				return self::numeric_over_threshold( $evidence, 'broken_links' );
			case 'internal_depth':
				return self::numeric_over_threshold( $evidence, 'internal_link_depth' );
			case 'missing_contact_path':
				if ( ! in_array( $site_intent, array( 'commercial', 'leadgen' ), true ) ) {
					return false;
				}
				return empty( $evidence['contact_path_presence']['value'] );
			case 'missing_cta':
				if ( ! in_array( $site_intent, array( 'commercial', 'leadgen', 'hybrid' ), true ) ) {
					return false;
				}
				return empty( $evidence['cta_presence']['value'] );
			case 'missing_structured_data':
				return empty( $evidence['structured_data_presence']['value'] );
			case 'missing_alt':
				return self::numeric_over_threshold( $evidence, 'image_alt_missing' );
			default:
				return false;
		}
	}

	private static function numeric_over_threshold( array $evidence, string $key ): bool {
		if ( ! isset( $evidence[ $key ] ) || ! is_array( $evidence[ $key ] ) ) {
			return false;
		}
		$value     = $evidence[ $key ]['value'] ?? null;
		$threshold = $evidence[ $key ]['threshold'] ?? null;
		return is_numeric( $value ) && is_numeric( $threshold ) && (float) $value > (float) $threshold;
	}

	private static function min_confidence_for_refs( array $evidence, array $refs ): float {
		$values = array();
		foreach ( $refs as $ref ) {
			if ( isset( $evidence[ $ref ]['confidence'] ) && is_numeric( $evidence[ $ref ]['confidence'] ) ) {
				$values[] = (float) $evidence[ $ref ]['confidence'];
			}
		}
		return empty( $values ) ? 0 : min( $values );
	}

	private static function severity_for_refs( array $evidence, array $refs, float $fallback ): float {
		$values = array();
		foreach ( $refs as $ref ) {
			if ( isset( $evidence[ $ref ]['severity_score'] ) && is_numeric( $evidence[ $ref ]['severity_score'] ) ) {
				$values[] = (float) $evidence[ $ref ]['severity_score'];
			}
		}
		return empty( $values ) ? $fallback : max( $values );
	}

	private static function final_priority( float $severity, string $fix_cost, string $impact_type ): float {
		$cost_weights = array(
			'low'    => 1,
			'medium' => 2,
			'high'   => 3,
		);
		$impact_weights = array(
			'technical'   => 0.6,
			'user'        => 1.0,
			'business'    => 1.2,
			'measurement' => 1.3,
		);
		$cost_weight   = (float) ( $cost_weights[ $fix_cost ] ?? 2 );
		$impact_weight = (float) ( $impact_weights[ $impact_type ] ?? 0.6 );
		return round( ( $severity / max( 1, $cost_weight ) ) * $impact_weight, 2 );
	}

	private static function stage_weight( string $stage ): int {
		$weights = array(
			'foundation'   => 3,
			'optimization' => 2,
			'enhancement'  => 1,
		);
		return (int) ( $weights[ $stage ] ?? 0 );
	}

	/**
	 * Keep top output in the earliest active intervention stage.
	 *
	 * @param array<int, array<string, mixed>> $decisions Decisions.
	 * @return array<int, array<string, mixed>>
	 */
	private static function filter_to_current_stage( array $decisions ): array {
		$max_stage = 0;
		foreach ( $decisions as $decision ) {
			$max_stage = max( $max_stage, self::stage_weight( (string) ( $decision['decision_stage'] ?? '' ) ) );
		}
		if ( $max_stage <= 0 ) {
			return $decisions;
		}

		return array_values(
			array_filter(
				$decisions,
				static function ( array $decision ) use ( $max_stage ): bool {
					return self::stage_weight( (string) ( $decision['decision_stage'] ?? '' ) ) === $max_stage;
				}
			)
		);
	}

	/**
	 * Keep earliest-stage decisions first, then complete with next valid decisions.
	 *
	 * @param array<int, array<string, mixed>> $decisions Sorted decisions.
	 * @return array<int, array<string, mixed>>
	 */
	private static function complete_minimum_output( array $decisions ): array {
		$minimum = 2;
		$current_stage = self::filter_to_current_stage( $decisions );
		if ( count( $current_stage ) >= $minimum ) {
			return $current_stage;
		}

		$selected_ids = array();
		foreach ( $current_stage as $decision ) {
			$selected_ids[ (string) ( $decision['decision_id'] ?? '' ) ] = true;
		}

		$completed = $current_stage;
		foreach ( $decisions as $decision ) {
			$decision_id = (string) ( $decision['decision_id'] ?? '' );
			if ( isset( $selected_ids[ $decision_id ] ) ) {
				continue;
			}
			$completed[] = $decision;
			$selected_ids[ $decision_id ] = true;
			if ( count( $completed ) >= $minimum ) {
				break;
			}
		}

		return $completed;
	}

	/**
	 * Apply category-level caps after grouping and stage filtering.
	 *
	 * @param array<int, array<string, mixed>> $decisions Decisions.
	 * @return array<int, array<string, mixed>>
	 */
	private static function apply_category_caps( array $decisions ): array {
		$caps = array(
			'seo' => 2,
		);
		$counts = array();
		$kept   = array();

		foreach ( $decisions as $decision ) {
			$category = (string) ( $decision['category'] ?? '' );
			$limit    = (int) ( $caps[ $category ] ?? 0 );
			$current  = (int) ( $counts[ $category ] ?? 0 );
			if ( $limit > 0 && $current >= $limit ) {
				continue;
			}

			$kept[] = $decision;
			$counts[ $category ] = $current + 1;
		}

		return $kept;
	}

	/**
	 * Resolve a simple V1 product intent from existing scan signals.
	 *
	 * @param array<string, mixed> $report Scan payload.
	 * @return string
	 */
	private static function resolve_site_intent( array $report ): string {
		$detected = (string) ( $report['site_intent']['detected_intent'] ?? '' );
		if ( in_array( $detected, array( 'lead_generation', 'service_booking' ), true ) ) {
			return 'leadgen';
		}
		if ( 'ecommerce' === $detected ) {
			return 'commercial';
		}
		if ( 'content_marketing' === $detected ) {
			return 'informational';
		}

		$signals = (array) ( $report['conversion']['signals'] ?? array() );
		$has_contact_form = ! empty( $signals['contact_form'] );
		$has_fast_contact = ! empty( $signals['whatsapp'] ) || ! empty( $signals['phone_link'] );
		$has_cta          = ! empty( $signals['cta'] );
		$has_value        = ! empty( $signals['value_proposition'] );
		$keyword     = strtolower( (string) ( $report['keyword_signals']['keyword'] ?? '' ) );

		if ( false !== strpos( $keyword, 'compr' ) || false !== strpos( $keyword, 'precio' ) || false !== strpos( $keyword, 'tienda' ) ) {
			return 'commercial';
		}
		if ( $has_fast_contact && $has_cta ) {
			return 'leadgen';
		}
		if ( $has_fast_contact || ( $has_contact_form && $has_cta && $has_value ) || ( $has_cta && $has_value ) ) {
			return 'hybrid';
		}

		return 'informational';
	}

	private static function homepage_check_failed_count( array $report, string $check_key ): int {
		$passed = $report['seo']['checks'][ $check_key ]['passed'] ?? null;
		return ( false === $passed ) ? 1 : 0;
	}

	private static function first_numeric( array $values ): int {
		foreach ( $values as $value ) {
			if ( is_numeric( $value ) ) {
				return (int) $value;
			}
		}
		return 0;
	}

	private static function extract_missing_count( array $check ): int {
		$details = (string) ( $check['details'] ?? '' );
		if ( preg_match( '/^(\d+)\//', $details, $matches ) ) {
			return (int) $matches[1];
		}
		return empty( $check['passed'] ) && isset( $check['passed'] ) ? 1 : 0;
	}

	private static function severity_by_count( int $count, int $base ): int {
		if ( $count <= 0 ) {
			return 0;
		}
		return min( 100, $base + max( 0, $count - 1 ) * 5 );
	}

	private static function has_contact_path( array $signals ): bool {
		return ! empty( $signals['contact_form'] ) || ! empty( $signals['whatsapp'] ) || ! empty( $signals['phone_link'] );
	}

	private static function metric_value( array $report, array $crux, string $metric ) {
		if ( isset( $crux['metrics'][ $metric ] ) && is_numeric( $crux['metrics'][ $metric ] ) ) {
			return (float) $crux['metrics'][ $metric ];
		}
		$pagespeed_key = ( 'lcp' === $metric ) ? 'lcp' : 'cls';
		$mobile_metric = $report['pagespeed']['mobile']['metrics'][ $pagespeed_key ]['value'] ?? null;
		if ( is_numeric( $mobile_metric ) ) {
			return (float) $mobile_metric;
		}
		return null;
	}

	private static function metric_source( array $report, array $crux, string $metric ): string {
		if ( isset( $crux['metrics'][ $metric ] ) && is_numeric( $crux['metrics'][ $metric ] ) ) {
			return 'crux';
		}
		$pagespeed_key = ( 'lcp' === $metric ) ? 'lcp' : 'cls';
		if ( isset( $report['pagespeed']['mobile']['metrics'][ $pagespeed_key ]['value'] ) ) {
			return 'pagespeed';
		}
		return 'pagespeed|crux';
	}

	private static function metric_confidence( array $report, array $crux, string $metric ): float {
		if ( isset( $crux['metrics'][ $metric ] ) && is_numeric( $crux['metrics'][ $metric ] ) ) {
			return 0.9;
		}
		$pagespeed_key = ( 'lcp' === $metric ) ? 'lcp' : 'cls';
		if ( isset( $report['pagespeed']['mobile']['metrics'][ $pagespeed_key ]['value'] ) ) {
			return 0.8;
		}
		return 0.2;
	}
}
