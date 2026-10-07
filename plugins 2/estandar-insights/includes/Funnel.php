<?php

namespace Estandar\Insights;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Commercial funnel — PURE computation from stage counts (the "Funnel" product
 * capability, now part of Insights). Consumers pass counts; no business logic
 * here beyond the canonical stage order.
 */
class Funnel
{
	const STAGE_ORDER = array( 'NEW', 'CONTACTED', 'QUALIFIED', 'MEETING_BOOKED', 'PROPOSAL_SENT', 'NEGOTIATION', 'CLIENT' );

	/** PURE: funnel rows with conversion rates from a stage→count map. */
	public static function compute( array $counts ): array
	{
		$rows   = array();
		$prev   = null;
		$first  = null;
		foreach ( self::STAGE_ORDER as $stage ) {
			$count = (int) ( $counts[ $stage ] ?? 0 );
			$rate  = null;
			if ( $prev !== null && $prev > 0 ) {
				$rate = round( ( $count / $prev ) * 100, 1 );
			}
			if ( $first === null ) {
				$first = $count;
			}
			$rows[ $stage ] = array( 'count' => $count, 'rate_from_prev' => $rate );
			$prev = $count;
		}
		return array( 'stages' => $rows, 'first' => $first, 'total' => array_sum( array_values( $counts ) ) );
	}

	/** PURE: overall conversion NEW→CLIENT (0 when no NEW). */
	public static function conversion( array $counts ): ?float
	{
		$new    = (int) ( $counts['NEW'] ?? 0 );
		$client = (int) ( $counts['CLIENT'] ?? 0 );
		if ( $new <= 0 ) {
			return null;
		}
		return round( ( $client / $new ) * 100, 1 );
	}

	/** PURE: channel mix from a channel→count map (percentages). */
	public static function channel_mix( array $channels ): array
	{
		$total = array_sum( array_values( $channels ) );
		$out   = array();
		foreach ( $channels as $channel => $count ) {
			$out[ $channel ] = $total > 0 ? round( ( (int) $count / $total ) * 100, 1 ) : 0.0;
		}
		return $out;
	}
}
