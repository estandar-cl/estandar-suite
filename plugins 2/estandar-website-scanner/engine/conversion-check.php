<?php
/**
 * Conversion signal checker.
 *
 * @package EstandarWebsiteScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detect basic conversion signals.
 */
class EWS_Conversion_Check {

	/**
	 * Analyze conversion signals.
	 *
	 * @param string $url Target URL.
	 * @return array<string, mixed>
	 */
	public static function analyze( string $url ): array {
		$site = EWS_Site_Fetcher::fetch( $url );
		$html = strtolower( (string) ( $site['body'] ?? '' ) );

		$signals = array(
			'cta'               => self::has_cta( $html ),
			'contact_form'      => self::has_contact_form( $html ),
			'whatsapp'          => ( false !== strpos( $html, 'wa.me/' ) || false !== strpos( $html, 'api.whatsapp.com' ) ),
			'phone_link'        => ( false !== strpos( $html, 'href="tel:' ) || false !== strpos( $html, "href='tel:" ) ),
			'value_proposition' => self::has_value_proposition( $html ),
		);

		$hits  = 0;
		$total = count( $signals );
		foreach ( $signals as $signal ) {
			$hits += $signal ? 1 : 0;
		}

		$score = round( ( $hits / max( 1, $total ) ) * 100, 2 );

		return array(
			'score'   => $score,
			'signals' => $signals,
			'url'     => esc_url_raw( $url ),
		);
	}

	/**
	 * Detect generic CTA patterns.
	 *
	 * @param string $html HTML content.
	 * @return bool
	 */
	private static function has_cta( string $html ): bool {
		$patterns = array( 'agendar', 'cotizar', 'contacto', 'solicitar', 'comenzar', 'hablemos', 'book', 'get started' );
		foreach ( $patterns as $pattern ) {
			if ( false !== strpos( $html, $pattern ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Detect contact form.
	 *
	 * @param string $html HTML content.
	 * @return bool
	 */
	private static function has_contact_form( string $html ): bool {
		return ( false !== strpos( $html, '<form' ) ) || ( false !== strpos( $html, 'wpforms' ) ) || ( false !== strpos( $html, 'contact-form-7' ) );
	}

	/**
	 * Detect value proposition block.
	 *
	 * @param string $html HTML content.
	 * @return bool
	 */
	private static function has_value_proposition( string $html ): bool {
		$patterns = array( 'te ayudamos', 'aumenta', 'mejora', 'resultados', 'solución', 'beneficio', 'estratégica' );
		$count    = 0;
		foreach ( $patterns as $pattern ) {
			if ( false !== strpos( $html, $pattern ) ) {
				++$count;
			}
		}

		return $count >= 2;
	}
}
