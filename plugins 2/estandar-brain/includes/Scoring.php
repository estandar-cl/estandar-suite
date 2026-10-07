<?php

namespace Estandar\Brain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scoring service — preserves the BEHAVIORAL_SCORE semantics (capture,
 * qualification, behavior + monotonic additive profile/intent) in the Brain
 * product. PURE calculate(); never writes to legacy tables.
 *
 * TOTAL = clamp( weighted_avg(capture, qualification, behavior) + wP*profile + wI*intent , 0, 100 )
 * Behavior 0 is a valid value (never excluded). Positive profile/intent never
 * lower the total (monotonic additive — same contract as the legacy engine).
 */
class Scoring
{
	const W_CAPTURE       = 0.30;
	const W_QUALIFICATION = 0.40;
	const W_BEHAVIOR      = 0.30;
	const W_PROFILE       = 0.20;
	const W_INTENT        = 0.10;
	const DEFAULT_HOT     = 70;
	const DEFAULT_WARM    = 40;

	/** PURE. */
	public static function calculate( array $in ): array
	{
		$capture = isset( $in['capture'] ) && $in['capture'] !== null ? self::clamp( (int) $in['capture'] ) : null;
		$qual    = isset( $in['qualification'] ) && $in['qualification'] !== null ? self::clamp( (int) $in['qualification'] ) : null;
		$profile = isset( $in['profile'] ) && $in['profile'] !== null ? self::clamp( (int) $in['profile'] ) : null;
		$intent  = isset( $in['intent'] ) && $in['intent'] !== null ? self::clamp( (int) $in['intent'] ) : null;

		// Behavior: weighted, time-decayed signals from a behavior_events list.
		$behavior = self::behavior_score( (array) ( $in['behavior_events'] ?? array() ) );

		$sum  = 0.0;
		$wsum = 0.0;
		if ( $capture !== null ) { $sum += self::W_CAPTURE * $capture;       $wsum += self::W_CAPTURE; }
		if ( $qual    !== null ) { $sum += self::W_QUALIFICATION * $qual;    $wsum += self::W_QUALIFICATION; }
		$sum  += self::W_BEHAVIOR * $behavior['value'];
		$wsum += self::W_BEHAVIOR;

		$base  = $wsum > 0 ? ( $sum / $wsum ) : 0.0;
		$boost = 0.0;
		if ( $profile !== null ) { $boost += self::W_PROFILE * $profile; }
		if ( $intent  !== null ) { $boost += self::W_INTENT * $intent; }
		$total = self::clamp( (int) round( $base + $boost ) );

		$hot  = isset( $in['thresholds']['hot'] ) ? (int) $in['thresholds']['hot'] : self::DEFAULT_HOT;
		$warm = isset( $in['thresholds']['warm'] ) ? (int) $in['thresholds']['warm'] : self::DEFAULT_WARM;
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
	public static function behavior_score( array $events ): array
	{
		$weights = array(
			'recontacted'          => 18,
			'autoresponse_engaged' => 15,
			'autoresponse_link'    => 12,
			'email_open'           => 3,
			'whatsapp_reply'       => 20,
		);
		$value = 0.0;
		foreach ( $events as $ev ) {
			$type = (string) ( $ev['type'] ?? '' );
			if ( ! isset( $weights[ $type ] ) ) {
				continue;
			}
			$age  = (float) ( $ev['age_days'] ?? 0 );
			$mult = self::decay( $age );
			if ( $mult > 0 ) {
				$value += $weights[ $type ] * $mult;
			}
		}
		return array( 'value' => self::clamp( (int) round( $value ) ) );
	}

	/** PURE: bucketed decay (full → half → 0 across 30 days). */
	public static function decay( float $age_days ): float
	{
		if ( $age_days <= 0 ) { return 1.0; }
		if ( $age_days < 15 ) { return 1.0; }
		if ( $age_days < 30 ) { return 0.5; }
		return 0.0;
	}

	private static function clamp( int $v ): int
	{
		return max( 0, min( 100, $v ) );
	}
}
