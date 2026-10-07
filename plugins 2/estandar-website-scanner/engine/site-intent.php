<?php
/**
 * Site intent and alignment analyzer.
 *
 * @package EstandarWebsiteScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detect the likely business intent of the site and alignment quality.
 */
class EWS_Site_Intent {

	/**
	 * Analyze site purpose.
	 *
	 * @param string               $url Target URL.
	 * @param array<string, mixed> $site_probe Homepage fetch payload.
	 * @param array<string, mixed> $crawler Crawler payload.
	 * @param array<string, mixed> $conversion Conversion payload.
	 * @param array<string, mixed> $keyword_signals Keyword payload.
	 * @return array<string, mixed>
	 */
	public static function analyze(
		string $url,
		array $site_probe = array(),
		array $crawler = array(),
		array $conversion = array(),
		array $keyword_signals = array()
	): array {
		$html  = strtolower( (string) ( $site_probe['body'] ?? '' ) );
		$pages = (array) ( $crawler['pages'] ?? array() );

		$signal_counts = array(
			'cta_count'           => self::count_keywords( $html, array( 'contacto', 'cotiza', 'agendar', 'agenda', 'solicitar', 'hablemos', 'asesoria', 'asesoría', 'consultoria', 'consulta', 'demo', 'book', 'contact', 'quote' ) ),
			'form_count'          => substr_count( $html, '<form' ),
			'commerce_signals'    => self::count_keywords( $html, array( 'checkout', 'add to cart', 'carrito', 'woocommerce', 'shop', 'tienda', 'producto', 'price' ) ),
			'booking_signals'     => self::count_keywords( $html, array( 'agendar', 'agenda', 'book now', 'book a call', 'calendly', 'reserva' ) ),
			'lead_signals'        => self::count_keywords( $html, array( 'cotiza', 'cotizar', 'contacto', 'asesoria', 'asesoría', 'hablemos', 'consulta', 'solicitar', 'demo' ) ),
			'content_signals'     => self::count_keywords( $html, array( 'blog', 'articulo', 'artículo', 'noticias', 'news', 'guia', 'guía', 'resources' ) ),
			'chatbot_signals'     => self::count_keywords( $html, array( 'chatbot', 'intercom', 'drift', 'tidio', 'crisp.chat', 'zendesk' ) ),
			'qualification_signals' => self::count_keywords( $html, array( 'multi-step', 'multistep', 'wizard', 'typeform', 'hubspot', 'quiz', 'diagnostico', 'diagnóstico' ) ),
			'service_signals'     => self::count_service_pages( $pages ),
		);

		$intent_scores = array(
			'lead_generation'   => ( $signal_counts['lead_signals'] * 3 ) + ( $signal_counts['form_count'] * 2 ) + $signal_counts['cta_count'] + ( $signal_counts['service_signals'] * 2 ),
			'ecommerce'         => ( $signal_counts['commerce_signals'] * 4 ) + ( $signal_counts['cta_count'] * 0.5 ),
			'service_booking'   => ( $signal_counts['booking_signals'] * 4 ) + ( $signal_counts['form_count'] * 1.5 ),
			'content_marketing' => ( $signal_counts['content_signals'] * 3 ) + ( $signal_counts['cta_count'] * 0.5 ),
			'brand_presence'    => 1 + ( $signal_counts['cta_count'] * 0.5 ),
		);
		arsort( $intent_scores );

		$detected_intent = (string) key( $intent_scores );
		$expected_intent = self::infer_expected_intent( $keyword_signals, $signal_counts );

		$alignment = self::calculate_alignment( $detected_intent, $conversion, $signal_counts );
		$impact    = self::alignment_impact( $alignment );
		$gaps      = self::missing_signals( $detected_intent, $conversion, $signal_counts );

		return array(
			'url'               => esc_url_raw( $url ),
			'detected_intent'   => $detected_intent,
			'expected_intent'   => $expected_intent,
			'alignment_score'   => $alignment,
			'alignment_label'   => self::alignment_label( $alignment ),
			'aligned'           => ( $detected_intent === $expected_intent ) && $alignment >= 70,
			'impact'            => $impact,
			'signal_counts'     => $signal_counts,
			'missing_signals'   => $gaps,
			'strategic_recommendation' => self::build_recommendation( $detected_intent, $alignment, $gaps ),
		);
	}

	/**
	 * Count keyword hits.
	 *
	 * @param string            $text Source text.
	 * @param array<int, string> $keywords Keywords.
	 * @return int
	 */
	private static function count_keywords( string $text, array $keywords ): int {
		$hits = 0;
		foreach ( $keywords as $keyword ) {
			if ( false !== strpos( $text, strtolower( $keyword ) ) ) {
				++$hits;
			}
		}
		return $hits;
	}

	/**
	 * Count service-like URLs in crawler pages.
	 *
	 * @param array<int, array<string, mixed>> $pages Crawled pages.
	 * @return int
	 */
	private static function count_service_pages( array $pages ): int {
		$count = 0;
		foreach ( $pages as $page ) {
			$url = strtolower( (string) ( $page['url'] ?? '' ) );
			if ( false !== strpos( $url, '/servicio' ) || false !== strpos( $url, '/service' ) || false !== strpos( $url, '/asesoria' ) || false !== strpos( $url, '/consult' ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Infer expected intent from semantic signals.
	 *
	 * @param array<string, mixed> $keyword_signals Keyword signals.
	 * @param array<string, int>   $signal_counts Signal counts.
	 * @return string
	 */
	private static function infer_expected_intent( array $keyword_signals, array $signal_counts ): string {
		$keyword = strtolower( (string) ( $keyword_signals['keyword'] ?? '' ) );
		if ( false !== strpos( $keyword, 'compr' ) || false !== strpos( $keyword, 'tienda' ) || $signal_counts['commerce_signals'] >= 2 ) {
			return 'ecommerce';
		}
		if ( false !== strpos( $keyword, 'agenda' ) || $signal_counts['booking_signals'] >= 2 ) {
			return 'service_booking';
		}
		if ( false !== strpos( $keyword, 'contab' ) || false !== strpos( $keyword, 'asesor' ) || false !== strpos( $keyword, 'servicio' ) || $signal_counts['service_signals'] >= 1 ) {
			return 'lead_generation';
		}
		if ( $signal_counts['content_signals'] >= 3 ) {
			return 'content_marketing';
		}
		return 'brand_presence';
	}

	/**
	 * Score alignment from required signals.
	 *
	 * @param string               $intent Intent key.
	 * @param array<string, mixed> $conversion Conversion data.
	 * @param array<string, int>   $signal_counts Signals.
	 * @return float
	 */
	private static function calculate_alignment( string $intent, array $conversion, array $signal_counts ): float {
		$signals = (array) ( $conversion['signals'] ?? array() );
		$got     = 0;
		$total   = 0;

		$rules = array(
			'lead_generation' => array(
				( $signal_counts['cta_count'] >= 3 ),
				( $signal_counts['form_count'] >= 1 ),
				! empty( $signals['value_proposition'] ),
				( ! empty( $signals['whatsapp'] ) || ! empty( $signals['phone_link'] ) ),
				( $signal_counts['chatbot_signals'] >= 1 || $signal_counts['qualification_signals'] >= 1 ),
			),
			'ecommerce' => array(
				( $signal_counts['commerce_signals'] >= 2 ),
				( $signal_counts['cta_count'] >= 2 ),
				! empty( $signals['value_proposition'] ),
			),
			'service_booking' => array(
				( $signal_counts['booking_signals'] >= 2 ),
				( $signal_counts['form_count'] >= 1 ),
				! empty( $signals['cta'] ),
			),
			'content_marketing' => array(
				( $signal_counts['content_signals'] >= 2 ),
				! empty( $signals['cta'] ),
			),
			'brand_presence' => array(
				( $signal_counts['cta_count'] >= 1 ),
				! empty( $signals['value_proposition'] ),
			),
		);

		$current_rules = $rules[ $intent ] ?? $rules['brand_presence'];
		foreach ( $current_rules as $passed ) {
			++$total;
			$got += $passed ? 1 : 0;
		}

		return round( ( $got / max( 1, $total ) ) * 100, 2 );
	}

	/**
	 * Build missing signals list by intent.
	 *
	 * @param string               $intent Intent key.
	 * @param array<string, mixed> $conversion Conversion payload.
	 * @param array<string, int>   $signal_counts Signals.
	 * @return array<int, string>
	 */
	private static function missing_signals( string $intent, array $conversion, array $signal_counts ): array {
		$signals = (array) ( $conversion['signals'] ?? array() );
		$missing = array();

		if ( 'lead_generation' === $intent ) {
			if ( $signal_counts['cta_count'] < 3 ) {
				$missing[] = 'CTA principal poco visible o insuficiente.';
			}
			if ( $signal_counts['form_count'] < 1 ) {
				$missing[] = 'No se detecta formulario de contacto funcional.';
			}
			if ( empty( $signals['value_proposition'] ) ) {
				$missing[] = 'Propuesta de valor débil o poco explícita.';
			}
			if ( empty( $signals['whatsapp'] ) && empty( $signals['phone_link'] ) ) {
				$missing[] = 'Faltan canales de contacto inmediato (teléfono/WhatsApp).';
			}
			if ( $signal_counts['chatbot_signals'] < 1 && $signal_counts['qualification_signals'] < 1 ) {
				$missing[] = 'No existe mecanismo de calificación temprana de leads (chatbot/formulario inteligente).';
			}
		}

		return $missing;
	}

	/**
	 * Generate focused strategy recommendation.
	 *
	 * @param string            $intent Detected intent.
	 * @param float             $alignment Alignment score.
	 * @param array<int, string> $gaps Gaps.
	 * @return string
	 */
	private static function build_recommendation( string $intent, float $alignment, array $gaps ): string {
		if ( 'lead_generation' === $intent && $alignment < 70 ) {
			return 'El sitio está orientado a captación de leads, pero el embudo actual es limitado. Se recomienda implementar un formulario inteligente o chatbot de diagnóstico inicial para filtrar consultas, guiar al usuario y elevar la calidad del lead antes de notificar al equipo comercial.';
		}

		if ( $alignment < 60 ) {
			return 'La arquitectura y señales de conversión no están completamente alineadas con el objetivo detectado. Priorizar una ruta de conversión principal y remover fricción en pasos críticos.';
		}

		if ( ! empty( $gaps ) ) {
			return 'La base estratégica es correcta, pero faltan señales clave para consolidar resultados. Resolver brechas de conversión y medición incrementará el impacto del sitio.';
		}

		return 'El objetivo del sitio y su implementación actual muestran una alineación adecuada. Mantener iteración continua para mejorar eficiencia de conversión.';
	}

	/**
	 * Alignment label.
	 *
	 * @param float $score Alignment score.
	 * @return string
	 */
	private static function alignment_label( float $score ): string {
		if ( $score >= 85 ) {
			return 'High';
		}
		if ( $score >= 70 ) {
			return 'Medium';
		}
		return 'Low';
	}

	/**
	 * Alignment impact summary.
	 *
	 * @param float $score Alignment score.
	 * @return string
	 */
	private static function alignment_impact( float $score ): string {
		if ( $score >= 85 ) {
			return 'El modelo de conversión actual está bien alineado con el objetivo del sitio.';
		}
		if ( $score >= 70 ) {
			return 'Existe alineación parcial: hay base de conversión, pero persisten fricciones en captura y calificación.';
		}
		return 'La alineación estratégica es baja: la implementación actual reduce calidad y volumen de conversiones potenciales.';
	}
}

