<?php

namespace Estandar\Brain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adoption of legacy signals/intelligence. Read-only over the ORIGINAL tables
 * (es_lead_events, es_leads). Never modifies original values; no exclusive
 * ownership. Import is idempotent, reversible, first-writer-wins.
 */
class Adoption
{
	const LEGACY_EVENTS = 'es_lead_events';
	const LEGACY_LEADS  = 'es_leads';

	/** Behavior signal weight equivalence (legacy event_type → Brain type). */
	public static function event_equivalence(): array
	{
		return array(
			'lead_recontacted'               => 'recontacted',
			'autoresponse_engaged'           => 'autoresponse_engaged',
			'autoresponse_link_click'        => 'autoresponse_link',
			'autoresponse_email_open_signal' => 'email_open',
			'whatsapp_replied'               => 'whatsapp_reply',
		);
	}

	public static function legacy_present(): bool
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return false;
		}
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . self::LEGACY_EVENTS ) );
	}

	/** Read-only: project legacy behavior events into Brain signal tuples. */
	public static function behavior_signals( int $lead_id, int $limit = 200 ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || ! self::legacy_present() ) {
			return array();
		}
		$equiv = self::event_equivalence();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT event_type, metadata, created_at FROM {$wpdb->prefix}" . self::LEGACY_EVENTS . "
			 WHERE lead_id = %d ORDER BY id DESC LIMIT %d", $lead_id, $limit
		), ARRAY_A );
		$out = array();
		foreach ( (array) $rows as $row ) {
			$type = (string) ( $row['event_type'] ?? '' );
			if ( ! isset( $equiv[ $type ] ) ) {
				continue;
			}
			$meta = json_decode( (string) ( $row['metadata'] ?? '' ), true );
			$actor = is_array( $meta ) ? (string) ( $meta['actor_type'] ?? '' ) : '';
			if ( in_array( $actor, array( 'admin', 'operator', 'system' ), true ) ) {
				continue; // internal actors are not prospect signals.
			}
			$out[] = array(
				'type'       => $equiv[ $type ],
				'age_days'   => max( 0, ( time() - strtotime( (string) $row['created_at'] ) ) / DAY_IN_SECONDS ),
				'origin'     => 'legacy:' . $type,
			);
		}
		return $out;
	}

	/** Read-only: legacy lead scoring state (temperature/quality/fit). */
	public static function legacy_lead_score( int $lead_id ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || ! self::legacy_present() ) {
			return array();
		}
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT behavioral_score, temperature, quality, commercial_fit, lead_intent
			 FROM {$wpdb->prefix}" . self::LEGACY_LEADS . " WHERE id = %d LIMIT 1", $lead_id
		), ARRAY_A );
		return is_array( $row ) ? $row : array();
	}

	/** Idempotent import: one signal per lead carrying its behavior snapshot. */
	public static function import_signals( int $lead_id, bool $dry_run = true ): array
	{
		if ( $lead_id <= 0 ) {
			return array( 'recorded' => 0, 'dry_run' => $dry_run );
		}
		$signals = self::behavior_signals( $lead_id );
		if ( empty( $signals ) ) {
			return array( 'recorded' => 0, 'dry_run' => $dry_run );
		}
		$key = SignalLedger::idempotency_key( 'lead_created', 'lead', $lead_id, 'legacy:behavior' );
		if ( ! $dry_run ) {
			SignalLedger::record( array(
				'event_name'      => 'lead_created',
				'entity_type'     => 'lead',
				'entity_id'       => $lead_id,
				'event_time'      => gmdate( 'Y-m-d H:i:s' ),
				'idempotency_key' => $key,
				'payload_json'    => array( 'behavior' => $signals, 'origin' => 'legacy' ),
			) );
		}
		return array( 'recorded' => 1, 'dry_run' => $dry_run );
	}
}
