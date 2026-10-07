<?php

namespace Estandar\Flow\Journey;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LeadAdapter
{
	const LEGACY_TABLE    = 'es_leads';
	const LEGACY_OPTION   = 'estandar_commercial_qualification_rules_v1';

	/** Map 19+ legacy statuses to 17 journey states (best-effort). */
	public static function legacy_status_to_journey( string $legacy_status ): string
	{
		$map = array(
			'NEW'                    => 'NEW',
			'INBOX'                  => 'CAPTURED',
			'CONTACTED'              => 'CONTACTED',
			'CONTACT ATTEMPT'        => 'CONTACTED',
			'ENGAGED'                => 'ENGAGED',
			'IN PROGRESS'            => 'ENGAGED',
			'QUALIFIED'              => 'QUALIFIED',
			'MEETING_BOOKED'         => 'MEETING',
			'MEETING'                => 'MEETING',
			'PROPOSAL_SENT'          => 'PROPOSAL',
			'NEGOTIATION'            => 'NEGOTIATING',
			'CLIENT'                 => 'ACTIVE_CLIENT',
			'DELIVERED'              => 'ACTIVE_CLIENT',
			'CLIENTE'                => 'ACTIVE_CLIENT',
			'LOST'                   => 'LOST',
			'INVALID'                => 'INVALID',
			'SPAM'                   => 'INVALID',
			'TEST'                   => 'INVALID',
			'FRAUD'                  => 'INVALID',
		);
		return $map[ strtoupper( trim( $legacy_status ) ) ] ?? 'NEW';
	}

	public static function legacy_present(): bool
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return false;
		}
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . self::LEGACY_TABLE ) );
	}

	/** Read a legacy lead and project it as a JourneyLead array. */
	public static function from_legacy( int $lead_id ): ?array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || ! self::legacy_present() ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, lead_uuid, status, sales_stage, quality, project_slug, assigned_operator_name, created_at, updated_at
			 FROM {$wpdb->prefix}" . self::LEGACY_TABLE . " WHERE id = %d LIMIT 1", $lead_id
		), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		$legacy_stage = (string) ( $row['sales_stage'] ?? $row['status'] ?? 'NEW' );
		$journey      = self::legacy_status_to_journey( $legacy_stage );
		return array(
			'origin_lead_id'   => (int) $row['id'],
			'entity_uuid'      => (string) ( $row['lead_uuid'] ?? '' ),
			'journey_state'    => $journey,
			'legacy_status'    => $legacy_stage,
			'project_slug'     => sanctify( $row['project_slug'] ?? '' ),
			'assigned_operator'=> (string) ( $row['assigned_operator_name'] ?? '' ),
			'created_at'       => $row['created_at'] ?? gmdate( 'Y-m-d H:i:s' ),
			'updated_at'       => $row['updated_at'] ?? gmdate( 'Y-m-d H:i:s' ),
		);
	}

	/** After a journey transition, fire equivalent legacy hooks. */
	public static function fire_legacy_hooks( int $pipeline_id, string $from, string $to, string $reason = '' ): void
	{
		$legacy_status  = StateMachine::journey_to_legacy( $to );
		$pipeline_row   = \Estandar\Flow\Pipeline::get( $pipeline_id );
		if ( ! $pipeline_row || empty( $pipeline_row['origin_lead_id'] ) ) {
			return;
		}
		global $wpdb;
		$lead = $wpdb->get_row( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}" . self::LEGACY_TABLE . " WHERE id = %d LIMIT 1",
			$pipeline_row['origin_lead_id']
		) );
		if ( ! $lead ) {
			return;
		}
		do_action( 'estandar_lead_status_changed', (int) $lead->id, $legacy_status, $reason );
	}
}

if ( ! function_exists( 'Estandar\\Flow\\Journey\\sanctify' ) ) {
	function sanctify( string $v ): string {
		return strtolower( (string) preg_replace( '/[^a-z0-9\-_]/', '-', (string) $v ) );
	}
}
