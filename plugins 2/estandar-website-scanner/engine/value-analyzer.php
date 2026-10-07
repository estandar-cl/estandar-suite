<?php
/**
 * Value analysis module.
 *
 * @package EstandarWebsiteScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build business-focused insights from technical checks.
 */
class EWS_Value_Analyzer {

	/**
	 * Generate value analysis payload.
	 *
	 * @param array<string, mixed> $context Scan context.
	 * @return array<string, mixed>
	 */
	public static function generate( array $context ): array {
		$score         = $context['score'] ?? array();
		$components    = $score['components'] ?? array();
		$diagnostics   = $context['diagnostics'] ?? array();
		$seo_checks    = $context['seo']['checks'] ?? array();
		$access_checks = $context['accessibility']['checks'] ?? array();
		$crawler_score = $components['crawler_structure'] ?? null;
		$conversion_score = $components['conversion_signals'] ?? null;
		$site_intent      = $context['site_intent'] ?? array();

		$priorities = array();

		if ( ! empty( $diagnostics['pagespeed_errors'] ) ) {
			$priorities[] = self::build_action(
				'Restaurar módulo de performance (PageSpeed API)',
				'High',
				'Low',
				'Hoy',
				'Configurar API key válida, revisar cuota diaria y reintentar escaneo para recuperar cobertura 100%.',
				100
			);
		}

		if ( isset( $seo_checks['meta_description']['passed'] ) && ! $seo_checks['meta_description']['passed'] ) {
			$priorities[] = self::build_action(
				'Crear meta description de alto CTR',
				'High',
				'Low',
				'24 horas',
				'Redactar meta description enfocada en intención de búsqueda y propuesta de valor.',
				84
			);
		}

		if ( isset( $seo_checks['h1']['passed'] ) && ! $seo_checks['h1']['passed'] ) {
			$priorities[] = self::build_action(
				'Corregir estructura H1 en homepage',
				'High',
				'Low',
				'24 horas',
				'Asegurar un H1 principal único, alineado a keyword objetivo y oferta central.',
				82
			);
		}

		if ( isset( $access_checks['images_alt']['passed'] ) && ! $access_checks['images_alt']['passed'] ) {
			$priorities[] = self::build_action(
				'Completar ALT en imágenes clave',
				'Medium',
				'Low',
				'48 horas',
				'Agregar textos ALT descriptivos para mejorar accesibilidad y contexto semántico.',
				70
			);
		}

		if ( isset( $access_checks['button_labels']['passed'] ) && ! $access_checks['button_labels']['passed'] ) {
			$priorities[] = self::build_action(
				'Etiquetar botones sin texto',
				'Medium',
				'Low',
				'48 horas',
				'Agregar texto visible o aria-label para navegación asistida y claridad de conversión.',
				68
			);
		}

		$performance = $components['performance'] ?? null;
		if ( is_numeric( $performance ) && (float) $performance < 70 ) {
			$priorities[] = self::build_action(
				'Plan de optimización Core Web Vitals',
				'High',
				'Medium',
				'7 días',
				'Atacar LCP/FCP/TBT con optimización de imágenes, carga diferida y reducción de JS bloqueante.',
				88
			);
		}
		if ( is_numeric( $crawler_score ) && (float) $crawler_score < 75 ) {
			$priorities[] = self::build_action(
				'Optimizar estructura SEO en páginas internas',
				'High',
				'Medium',
				'10 días',
				'Corregir plantillas con faltantes de title/meta/h1/canonical para mejorar indexación y consistencia.',
				86
			);
		}
		if ( is_numeric( $conversion_score ) && (float) $conversion_score < 70 ) {
			$priorities[] = self::build_action(
				'Elevar señales de conversión en homepage',
				'High',
				'Low',
				'3 días',
				'Fortalecer CTA principal, propuesta de valor y accesos de contacto para reducir fricción comercial.',
				80
			);
		}
		if ( ! empty( $site_intent['detected_intent'] ) && 'lead_generation' === (string) $site_intent['detected_intent'] && (float) ( $site_intent['alignment_score'] ?? 0 ) < 70 ) {
			$priorities[] = self::build_action(
				'Implementar captación inteligente de leads',
				'High',
				'Medium',
				'10 días',
				'Integrar formulario inteligente o chatbot para calificar leads y reducir fricción comercial.',
				92
			);
		}

		usort(
			$priorities,
			static function ( array $a, array $b ): int {
				return (int) ( $b['priority_score'] <=> $a['priority_score'] );
			}
		);

		$quick_wins = array();
		foreach ( $priorities as $action ) {
			if ( 'Low' === $action['effort'] ) {
				$quick_wins[] = $action['title'];
			}
		}

		$quick_wins = array_slice( array_values( array_unique( $quick_wins ) ), 0, 4 );

		$business_impact = self::build_business_impact( $priorities );
		$maturity_stage  = self::resolve_maturity_stage( (float) ( $score['health_score'] ?? 0 ) );

		return array(
			'maturity_stage'   => $maturity_stage,
			'executive_summary'=> self::build_summary_text( $score, $priorities ),
			'priority_actions' => array_slice( $priorities, 0, 6 ),
			'quick_wins'       => $quick_wins,
			'business_impact'  => $business_impact,
			'plan_30_days'     => self::build_30_day_plan( $priorities ),
		);
	}

	/**
	 * Create a normalized action item.
	 *
	 * @param string $title Action title.
	 * @param string $impact Impact level.
	 * @param string $effort Effort level.
	 * @param string $eta Target ETA.
	 * @param string $why Rationale.
	 * @param int    $priority_score Priority score.
	 * @return array<string, mixed>
	 */
	private static function build_action( string $title, string $impact, string $effort, string $eta, string $why, int $priority_score ): array {
		return array(
			'title'          => $title,
			'impact'         => $impact,
			'effort'         => $effort,
			'eta'            => $eta,
			'why'            => $why,
			'priority_score' => $priority_score,
		);
	}

	/**
	 * Build summary sentence.
	 *
	 * @param array<string, mixed>      $score Score data.
	 * @param array<int, array<string, mixed>> $priorities Priority actions.
	 * @return string
	 */
	private static function build_summary_text( array $score, array $priorities ): string {
		$coverage = (float) ( $score['coverage'] ?? 0 );
		$health   = (float) ( $score['health_score'] ?? 0 );
		if ( empty( $priorities ) ) {
			return 'El sitio presenta una base técnica estable. Mantener monitoreo y mejoras iterativas.';
		}

		return sprintf( 'Puntaje %.2f/10 con cobertura %.0f%%. Se priorizan %d acciones de alto impacto para acelerar conversión, SEO y experiencia de usuario.', $health, $coverage, count( $priorities ) );
	}

	/**
	 * Build business impact statements.
	 *
	 * @param array<int, array<string, mixed>> $priorities Priority actions.
	 * @return array<int, string>
	 */
	private static function build_business_impact( array $priorities ): array {
		$impact = array();
		foreach ( $priorities as $action ) {
			$title = (string) ( $action['title'] ?? '' );
			if ( false !== stripos( $title, 'meta description' ) || false !== stripos( $title, 'H1' ) ) {
				$impact[] = 'Mejora potencial en CTR orgánico y relevancia semántica en buscadores.';
			}
			if ( false !== stripos( $title, 'PageSpeed' ) || false !== stripos( $title, 'Core Web Vitals' ) ) {
				$impact[] = 'Mejora de experiencia de usuario y mayor probabilidad de conversión en móvil.';
			}
			if ( false !== stripos( $title, 'ALT' ) || false !== stripos( $title, 'botones' ) ) {
				$impact[] = 'Reducción de fricción de accesibilidad y mejor calidad de navegación asistida.';
			}
		}

		if ( empty( $impact ) ) {
			$impact[] = 'Mantener consistencia técnica para sostener posicionamiento y confiabilidad del sitio.';
		}

		return array_values( array_unique( $impact ) );
	}

	/**
	 * Build a 30-day action plan.
	 *
	 * @param array<int, array<string, mixed>> $priorities Priority actions.
	 * @return array<int, string>
	 */
	private static function build_30_day_plan( array $priorities ): array {
		$plan = array(
			'Semana 1: Resolver bloqueos críticos de datos (API/medición) y quick wins SEO.',
			'Semana 2: Corregir accesibilidad de alto impacto (ALT y etiquetas de botones).',
			'Semana 3: Ejecutar optimización de rendimiento y validar Core Web Vitals.',
			'Semana 4: Re-escanear, comparar resultados y cerrar backlog de mejoras.',
		);

		if ( empty( $priorities ) ) {
			$plan = array(
				'Semana 1-4: Monitoreo continuo y ajustes menores sobre contenido y rendimiento.',
			);
		}

		return $plan;
	}

	/**
	 * Resolve maturity stage label.
	 *
	 * @param float $health_score Score 0-10.
	 * @return string
	 */
	private static function resolve_maturity_stage( float $health_score ): string {
		if ( $health_score >= 9 ) {
			return 'Optimized';
		}
		if ( $health_score >= 7 ) {
			return 'Growing';
		}
		if ( $health_score >= 5 ) {
			return 'Foundation';
		}
		return 'At Risk';
	}
}
