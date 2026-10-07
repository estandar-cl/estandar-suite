<?php

namespace Estandar\Brain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scoring service — BEHAVIORAL_SCORE v2 (capture, qualification, behavior +
 * monotonic additive profile/intent). PURE calculate(); never writes to legacy.
 *
 * TOTAL = clamp( weighted_avg(capture, qualification, behavior) + wP*profile + wI*intent , 0, 100 )
 * Behavior 0 is a valid value (never excluded). Positive profile/intent never
 * lower the total (monotonic additive — same contract as the legacy engine).
 *
 * Pesos, umbrales y decaimiento son **configurables por sitio** (opción
 * `estandar_suite_brain_scoring_settings`); los valores de fábrica son los
 * históricos y se muestran como "óptimos" en la UI.
 */
class Scoring
{
	const OPTION = 'estandar_suite_brain_scoring_settings';

	/** Valores de fábrica (históricos). */
	public static function defaults(): array
	{
		return array(
			'w_capture'        => 0.30,
			'w_qualification'  => 0.40,
			'w_behavior'       => 0.30,
			'w_profile'        => 0.20,
			'w_intent'         => 0.10,
			'hot'              => 70,
			'warm'             => 40,
			'decay_half_days'  => 15,
			'decay_zero_days'  => 30,
			'behavior_weights' => array(
				'recontacted'          => 18,
				'autoresponse_engaged' => 15,
				'autoresponse_link'    => 12,
				'email_open'           => 3,
				'whatsapp_reply'       => 20,
			),
		);
	}

	/**
	 * Configuración efectiva (defaults + opción, saneada).
	 *
	 * @return array
	 */
	public static function config(): array
	{
		$def = self::defaults();
		if ( ! function_exists( 'get_option' ) ) {
			return $def;
		}
		$opt = get_option( self::OPTION, array() );
		if ( ! is_array( $opt ) ) {
			return $def;
		}

		$out = $def;
		foreach ( array( 'w_capture', 'w_qualification', 'w_behavior', 'w_profile', 'w_intent' ) as $k ) {
			if ( isset( $opt[ $k ] ) && is_numeric( $opt[ $k ] ) ) {
				$out[ $k ] = max( 0.0, min( 1.0, (float) $opt[ $k ] ) );
			}
		}
		foreach ( array( 'hot', 'warm' ) as $k ) {
			if ( isset( $opt[ $k ] ) && is_numeric( $opt[ $k ] ) ) {
				$out[ $k ] = max( 0, min( 100, (int) $opt[ $k ] ) );
			}
		}
		foreach ( array( 'decay_half_days', 'decay_zero_days' ) as $k ) {
			if ( isset( $opt[ $k ] ) && is_numeric( $opt[ $k ] ) ) {
				$out[ $k ] = max( 1, min( 365, (int) $opt[ $k ] ) );
			}
		}
		if ( $out['warm'] > $out['hot'] ) {
			$tmp         = $out['warm'];
			$out['warm'] = $out['hot'];
			$out['hot']  = $tmp;
		}
		if ( $out['decay_half_days'] > $out['decay_zero_days'] ) {
			$tmp                    = $out['decay_half_days'];
			$out['decay_half_days'] = $out['decay_zero_days'];
			$out['decay_zero_days'] = $tmp;
		}
		if ( isset( $opt['behavior_weights'] ) && is_array( $opt['behavior_weights'] ) ) {
			foreach ( $opt['behavior_weights'] as $k => $v ) {
				$k = sanitize_key( (string) $k );
				if ( $k !== '' && is_numeric( $v ) ) {
					$out['behavior_weights'][ $k ] = max( 0, min( 100, (int) $v ) );
				}
			}
		}

		return $out;
	}

	/**
	 * Normaliza y persiste la configuración.
	 *
	 * @param array $in Datos de entrada.
	 * @return array
	 */
	public static function save( array $in ): array
	{
		$cur = function_exists( 'get_option' ) ? get_option( self::OPTION, array() ) : array();
		$cur = is_array( $cur ) ? $cur : array();
		foreach ( $in as $k => $v ) {
			if ( is_array( $v ) ) {
				$cur[ $k ] = $v;
				continue;
			}
			if ( is_numeric( $v ) ) {
				$cur[ $k ] = $v;
			}
		}
		if ( function_exists( 'update_option' ) ) {
			update_option( self::OPTION, $cur, false );
		}
		return self::config();
	}

	/** PURE. */
	public static function calculate( array $in ): array
	{
		$cfg = self::config();

		$capture = isset( $in['capture'] ) && $in['capture'] !== null ? self::clamp( (int) $in['capture'] ) : null;
		$qual    = isset( $in['qualification'] ) && $in['qualification'] !== null ? self::clamp( (int) $in['qualification'] ) : null;
		$profile = isset( $in['profile'] ) && $in['profile'] !== null ? self::clamp( (int) $in['profile'] ) : null;
		$intent  = isset( $in['intent'] ) && $in['intent'] !== null ? self::clamp( (int) $in['intent'] ) : null;

		$behavior = self::behavior_score( (array) ( $in['behavior_events'] ?? array() ), (array) $cfg['behavior_weights'], (int) $cfg['decay_half_days'], (int) $cfg['decay_zero_days'] );

		$w_capture = (float) $cfg['w_capture'];
		$w_qual    = (float) $cfg['w_qualification'];
		$w_behav   = (float) $cfg['w_behavior'];
		$w_profile = (float) $cfg['w_profile'];
		$w_intent  = (float) $cfg['w_intent'];

		$sum  = 0.0;
		$wsum = 0.0;
		if ( $capture !== null ) { $sum += $w_capture * $capture; $wsum += $w_capture; }
		if ( $qual    !== null ) { $sum += $w_qual * $qual;       $wsum += $w_qual; }
		$sum  += $w_behav * $behavior['value'];
		$wsum += $w_behav;

		$base  = $wsum > 0 ? ( $sum / $wsum ) : 0.0;
		$boost = 0.0;
		if ( $profile !== null ) { $boost += $w_profile * $profile; }
		if ( $intent  !== null ) { $boost += $w_intent * $intent; }
		$total = self::clamp( (int) round( $base + $boost ) );

		$hot  = isset( $in['thresholds']['hot'] ) ? (int) $in['thresholds']['hot'] : (int) $cfg['hot'];
		$warm = isset( $in['thresholds']['warm'] ) ? (int) $in['thresholds']['warm'] : (int) $cfg['warm'];
		$temperature = $total >= $hot ? 'CALIENTE' : ( $total >= $warm ? 'TIBIO' : 'FRIO' );

		return array(
			'total'       => $total,
			'temperature' => $temperature,
			'components'  => array(
				'total' => $total, 'capture' => $capture, 'qualification' => $qual,
				'behavior' => $behavior['value'], 'profile' => $profile, 'intent' => $intent,
				'temperature' => $temperature,
			),
		);
	}

	/** PURE: weighted behavior from a signal list. */
	public static function behavior_score( array $events, ?array $weights = null, ?int $half_days = null, ?int $zero_days = null ): array
	{
		$weights   = $weights ?? self::config()['behavior_weights'];
		$half_days = $half_days ?? (int) self::config()['decay_half_days'];
		$zero_days = $zero_days ?? (int) self::config()['decay_zero_days'];

		$value = 0.0;
		foreach ( $events as $ev ) {
			$type = (string) ( $ev['type'] ?? '' );
			if ( ! isset( $weights[ $type ] ) ) {
				continue;
			}
			$age  = (float) ( $ev['age_days'] ?? 0 );
			$mult = self::decay( $age, $half_days, $zero_days );
			if ( $mult > 0 ) {
				$value += $weights[ $type ] * $mult;
			}
		}
		return array( 'value' => self::clamp( (int) round( $value ) ) );
	}

	/** PURE: bucketed decay (full → half → 0). */
	public static function decay( float $age_days, ?int $half_days = null, ?int $zero_days = null ): float
	{
		$half_days = $half_days ?? (int) self::config()['decay_half_days'];
		$zero_days = $zero_days ?? (int) self::config()['decay_zero_days'];
		if ( $age_days <= 0 ) { return 1.0; }
		if ( $age_days < $half_days ) { return 1.0; }
		if ( $age_days < $zero_days ) { return 0.5; }
		return 0.0;
	}

	private static function clamp( int $v ): int
	{
		return max( 0, min( 100, $v ) );
	}
}
