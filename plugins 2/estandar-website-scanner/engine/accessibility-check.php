<?php
/**
 * Accessibility module.
 *
 * @package EstandarWebsiteScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Evaluate accessibility indicators.
 */
class EWS_Accessibility_Check {

	/**
	 * Analyze accessibility.
	 *
	 * @param string               $url Target URL.
	 * @param array<string, mixed> $pagespeed PageSpeed payload.
	 * @return array<string, mixed>
	 */
	public static function analyze( string $url, array $pagespeed ): array {
		$site       = EWS_Site_Fetcher::fetch( $url );
		$html       = (string) ( $site['body'] ?? '' );
		$has_psi    = isset( $pagespeed['scores']['accessibility'] ) && is_numeric( $pagespeed['scores']['accessibility'] );
		$psi_score  = $has_psi ? (float) $pagespeed['scores']['accessibility'] : null;
		$img_check  = self::images_alt_check( $html );
		$btn_check  = self::buttons_label_check( $html );
		$contrast   = self::contrast_score( $pagespeed );

		$weighted_sum     = 0.0;
		$effective_weight = 0.0;
		if ( null !== $psi_score ) {
			$weighted_sum += ( $psi_score * 0.70 );
			$effective_weight += 0.70;
		}
		$weighted_sum += ( $img_check['score'] * 0.15 );
		$effective_weight += 0.15;
		$weighted_sum += ( $btn_check['score'] * 0.10 );
		$effective_weight += 0.10;
		$weighted_sum += ( $contrast['score'] * 0.05 );
		$effective_weight += 0.05;

		$score = ( $effective_weight > 0 ) ? ( $weighted_sum / $effective_weight ) : 0;

		return array(
			'score'  => round( $score, 2 ),
			'checks' => array(
				'psi_accessibility' => array(
					'label'   => 'Lighthouse accessibility score',
					'passed'  => ( null !== $psi_score && $psi_score >= 80 ),
					'details' => ( null !== $psi_score ) ? $psi_score : 'Unavailable (PageSpeed request failed).',
					'available' => ( null !== $psi_score ),
				),
				'images_alt'        => $img_check,
				'button_labels'     => $btn_check,
				'color_contrast'    => $contrast,
			),
			'url'    => esc_url_raw( $url ),
		);
	}

	/**
	 * Detect missing image ALT attributes.
	 *
	 * @param string $html HTML content.
	 * @return array<string, mixed>
	 */
	private static function images_alt_check( string $html ): array {
		preg_match_all( '/<img\b[^>]*>/i', $html, $images );
		$total   = isset( $images[0] ) ? count( $images[0] ) : 0;
		$missing = 0;

		if ( ! empty( $images[0] ) ) {
			foreach ( $images[0] as $img_tag ) {
				if ( ! preg_match( '/\balt\s*=\s*["\'](.*?)["\']/i', $img_tag, $alt ) ) {
					++$missing;
					continue;
				}

				if ( '' === trim( (string) $alt[1] ) ) {
					++$missing;
				}
			}
		}

		$score = ( 0 === $total ) ? 100 : max( 0, round( ( ( $total - $missing ) / $total ) * 100, 2 ) );

		return array(
			'label'   => 'Images with ALT text',
			'passed'  => ( $missing === 0 ),
			'details' => sprintf( '%1$d/%2$d missing', $missing, $total ),
			'score'   => $score,
		);
	}

	/**
	 * Detect button elements without labels.
	 *
	 * @param string $html HTML content.
	 * @return array<string, mixed>
	 */
	private static function buttons_label_check( string $html ): array {
		preg_match_all( '/<button\b[^>]*>(.*?)<\/button>/is', $html, $buttons, PREG_SET_ORDER );
		$total   = is_array( $buttons ) ? count( $buttons ) : 0;
		$missing = 0;

		if ( ! empty( $buttons ) ) {
			foreach ( $buttons as $button ) {
				$button_tag = $button[0] ?? '';
				$text       = trim( wp_strip_all_tags( (string) ( $button[1] ?? '' ) ) );

				$has_aria  = (bool) preg_match( '/\baria-label\s*=\s*["\']([^"\']+)["\']/i', $button_tag );
				$has_title = (bool) preg_match( '/\btitle\s*=\s*["\']([^"\']+)["\']/i', $button_tag );

				if ( '' === $text && ! $has_aria && ! $has_title ) {
					++$missing;
				}
			}
		}

		$score = ( 0 === $total ) ? 100 : max( 0, round( ( ( $total - $missing ) / $total ) * 100, 2 ) );

		return array(
			'label'   => 'Buttons with labels',
			'passed'  => ( $missing === 0 ),
			'details' => sprintf( '%1$d/%2$d missing label', $missing, $total ),
			'score'   => $score,
		);
	}

	/**
	 * Read contrast-related Lighthouse signal.
	 *
	 * @param array<string, mixed> $pagespeed PageSpeed payload.
	 * @return array<string, mixed>
	 */
	private static function contrast_score( array $pagespeed ): array {
		$mobile  = $pagespeed['mobile']['accessibility_audits']['color_contrast'] ?? array();
		$desktop = $pagespeed['desktop']['accessibility_audits']['color_contrast'] ?? array();

		$raw_scores = array();
		if ( isset( $mobile['score'] ) && is_numeric( $mobile['score'] ) ) {
			$raw_scores[] = (float) $mobile['score'] * 100;
		}
		if ( isset( $desktop['score'] ) && is_numeric( $desktop['score'] ) ) {
			$raw_scores[] = (float) $desktop['score'] * 100;
		}

		$score = empty( $raw_scores ) ? 100 : round( array_sum( $raw_scores ) / count( $raw_scores ), 2 );

		return array(
			'label'   => 'Color contrast',
			'passed'  => ( $score >= 80 ),
			'details' => $score,
			'score'   => $score,
		);
	}
}
