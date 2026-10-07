<?php

namespace Estandar\Flow\Journey;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StateMachine
{
	/** Valid forward transitions in the Commercial Journey. */
	const TRANSITIONS = array(
		'NEW'             => array( 'CAPTURED' ),
		'CAPTURED'        => array( 'CLASSIFIED', 'LOST', 'INVALID' ),
		'CLASSIFIED'      => array( 'CONTACTED', 'LOST', 'INVALID' ),
		'CONTACTED'       => array( 'ENGAGED', 'LOST' ),
		'ENGAGED'         => array( 'QUALIFIED', 'LOST' ),
		'QUALIFIED'       => array( 'MEETING', 'LOST' ),
		'MEETING'         => array( 'PROPOSAL', 'LOST' ),
		'PROPOSAL'        => array( 'NEGOTIATING', 'LOST' ),
		'NEGOTIATING'     => array( 'CLOSED_WON', 'LOST' ),
		'CLOSED_WON'      => array( 'POST_SALE' ),
		'POST_SALE'       => array( 'ACTIVE_CLIENT', 'CHURNING' ),
		'ACTIVE_CLIENT'   => array( 'CHURNING' ),
		'CHURNING'        => array( 'RECOVERY', 'CHURNED' ),
		'RECOVERY'        => array( 'ENGAGED', 'LOST' ),
		'CHURNED'         => array( 'RECOVERY' ),
		'LOST'            => array( 'RECOVERY' ),
		'INVALID'         => array(),
	);

	public static function validate_transition( string $from, string $to ): bool
	{
		$from = strtoupper( $from );
		$to   = strtoupper( $to );
		$allowed = self::TRANSITIONS[ $from ] ?? array();
		return in_array( $to, $allowed, true );
	}

	public static function allowed_transitions( string $from ): array
	{
		return self::TRANSITIONS[ strtoupper( $from ) ] ?? array();
	}

	/** Invariants that must hold before every transition. */
	public static function validate_invariants( array $pipeline_row, string $to ): ?string
	{
		$to = strtoupper( $to );

		if ( $to === 'CONTACTED' && empty( $pipeline_row['assigned_operator'] ) ) {
			return 'Operator must be assigned before CONTACTED.';
		}

		if ( $to === 'QUALIFIED' && empty( $pipeline_row['commercial_fit'] ) ) {
			return 'Assessment (commercial_fit) required before QUALIFIED.';
		}

		if ( $to === 'CLOSED_WON' ) {
			$meta = $pipeline_row['metadata_json'] ? json_decode( $pipeline_row['metadata_json'], true ) : array();
			if ( empty( $meta['quote_id'] ) ) {
				return 'Quote required before CLOSED_WON.';
			}
		}

		if ( $to === 'INVALID' && $pipeline_row['stage'] === 'CLIENT' ) {
			return 'Cannot invalidate an active client.';
		}

		return null;
	}

	/** Map legacy stage names (9) to journey states. */
	public static function legacy_to_journey( string $legacy_stage ): string
	{
		$map = array(
			'NEW'              => 'NEW',
			'CONTACTED'        => 'CONTACTED',
			'QUALIFIED'        => 'QUALIFIED',
			'MEETING_BOOKED'   => 'MEETING',
			'PROPOSAL_SENT'    => 'PROPOSAL',
			'NEGOTIATION'      => 'NEGOTIATING',
			'CLIENT'           => 'CLOSED_WON',
			'LOST'             => 'LOST',
			'INVALID'          => 'INVALID',
		);
		return $map[ strtoupper( $legacy_stage ) ] ?? 'NEW';
	}

	public static function journey_to_legacy( string $journey_state ): string
	{
		$map = array(
			'NEW'           => 'NEW',
			'CAPTURED'      => 'NEW',
			'CLASSIFIED'    => 'NEW',
			'CONTACTED'     => 'CONTACTED',
			'ENGAGED'       => 'CONTACTED',
			'QUALIFIED'     => 'QUALIFIED',
			'MEETING'       => 'MEETING_BOOKED',
			'PROPOSAL'      => 'PROPOSAL_SENT',
			'NEGOTIATING'   => 'NEGOTIATION',
			'CLOSED_WON'    => 'CLIENT',
			'POST_SALE'     => 'CLIENT',
			'ACTIVE_CLIENT' => 'CLIENT',
			'CHURNING'      => 'LOST',
			'RECOVERY'      => 'LOST',
			'CHURNED'       => 'LOST',
			'LOST'          => 'LOST',
			'INVALID'       => 'INVALID',
		);
		return $map[ strtoupper( $journey_state ) ] ?? 'NEW';
	}
}
