<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * OPERATIONAL_HOURS_PER_OPERATOR_V1 — per-operator business hours gate.
 *
 * Each operator can define their own schedule (timezone, days, hours).
 * If no operator-level config exists, falls back to the global
 * estandar_operational_hours_v1 option. If the global option is also
 * absent, hours are disabled (always-open) for that operator.
 *
 * The global setting serves only as a default seed; once an operator
 * has their own config, it becomes the single source of truth.
 */
class Estandar_Leads_Operator_Hours {

	/**
	 * Get the operational hours config for a specific operator.
	 *
	 * @param object|array|null $operator Operator row from es_operators.
	 * @return array Normalized config (same shape as Estandar_Operational_Hours::DEFAULT_CONFIG).
	 */
	public static function config_for( $operator ): array {
		$config = null;

		if ( $operator ) {
			$raw = is_array( $operator ) ? ( $operator['operational_hours_json'] ?? null ) : ( $operator->operational_hours_json ?? null );
			if ( $raw !== null && $raw !== '' ) {
				$decoded = json_decode( $raw, true );
				if ( is_array( $decoded ) ) {
					$config = $decoded;
				}
			}
		}

		if ( $config === null && class_exists( 'Estandar_Operational_Hours' ) ) {
			$global = get_option( 'estandar_operational_hours_v1', array() );
			if ( is_array( $global ) && ! empty( $global ) ) {
				$config = $global;
			}
		}

		if ( $config === null ) {
			$config = class_exists( 'Estandar_Operational_Hours' )
				? Estandar_Operational_Hours::DEFAULT_CONFIG
				: array( 'enabled' => false, 'timezone' => 'America/Santiago', 'schedule' => array() );
		}

		return class_exists( 'Estandar_Operational_Hours' )
			? Estandar_Operational_Hours::normalize_config( $config )
			: $config;
	}

	/**
	 * Whether this operator is currently in business hours.
	 */
	public static function is_open( $operator, $at = null ): bool {
		if ( ! class_exists( 'Estandar_Operational_Hours' ) ) {
			return true;
		}
		return Estandar_Operational_Hours::is_open( $at, self::config_for( $operator ) );
	}

	/**
	 * Next open timestamp for this operator, or null if disabled.
	 */
	public static function next_open_timestamp( $operator, $from = null ): ?int {
		if ( ! class_exists( 'Estandar_Operational_Hours' ) ) {
			return null;
		}
		return Estandar_Operational_Hours::next_open_timestamp( $from, self::config_for( $operator ) );
	}

	/**
	 * Minutes until this operator's next business window.
	 */
	public static function deferred_minutes( $operator, $at = null ): int {
		if ( ! class_exists( 'Estandar_Operational_Hours' ) ) {
			return 0;
		}
		return Estandar_Operational_Hours::deferred_minutes( $at, self::config_for( $operator ) );
	}

	/**
	 * Seed the operator's config from the global setting if they don't have one yet.
	 * Returns true if a seed was written.
	 */
	public static function maybe_seed_from_global( int $operator_id ): bool {
		global $wpdb;
		if ( ! class_exists( 'Estandar_Operational_Hours' ) ) {
			return false;
		}

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT operational_hours_json FROM {$wpdb->prefix}es_operators WHERE id = %d LIMIT 1",
			$operator_id
		) );
		if ( ! $row || ( $row->operational_hours_json !== null && $row->operational_hours_json !== '' ) ) {
			return false;
		}

		$global = get_option( 'estandar_operational_hours_v1', array() );
		if ( ! is_array( $global ) || empty( $global ) ) {
			return false;
		}

		$normalized = Estandar_Operational_Hours::normalize_config( $global );
		$wpdb->update(
			$wpdb->prefix . 'es_operators',
			array( 'operational_hours_json' => wp_json_encode( $normalized ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ),
			array( 'id' => $operator_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		return true;
	}

	/**
	 * Release pending deferred notifications for an operator whose hours were disabled.
	 *
	 * Finds operator_notification_deferred events for this operator_id (in metadata)
	 * that don't have a matching operator_notified, and triggers immediate send.
	 *
	 * @return int Number of pending leads released.
	 */
	public static function release_pending_for_operator( int $operator_id ): int {
		global $wpdb;
		if ( $operator_id <= 0 ) {
			return 0;
		}

		$events_table = $wpdb->prefix . 'es_lead_events';
		$pending = $wpdb->get_results( $wpdb->prepare(
			"SELECT e.lead_id, e.submission_id, e.metadata
			 FROM {$events_table} e
			 WHERE e.event_type = 'operator_notification_deferred'
			   AND e.created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
			   AND (
			       e.metadata LIKE %s
			       OR JSON_EXTRACT(e.metadata, '$.operator_id') = %d
			   )
			   AND NOT EXISTS (
			       SELECT 1 FROM {$events_table} n
			       WHERE n.lead_id = e.lead_id
			         AND n.submission_id = e.submission_id
			         AND n.event_type = 'operator_notified'
			         AND n.created_at > e.created_at
			   )
			 ORDER BY e.created_at ASC
			 LIMIT 50",
			'%"operator_id":' . $operator_id . '%',
			$operator_id
		) );

		if ( empty( $pending ) ) {
			return 0;
		}

		$released = 0;
		foreach ( $pending as $row ) {
			$lead_id = (int) $row->lead_id;
			$submission_id = (int) ( $row->submission_id ?? 0 );

			if ( $lead_id < 1 || $submission_id < 1 ) {
				continue;
			}

			// Validate exact operator_id from the stored metadata payload.
			// The LIKE/JEXT is a prefilter; this is the authoritative check.
			$meta = json_decode( (string) $row->metadata, true );
			if ( ! is_array( $meta ) || (int) ( $meta['operator_id'] ?? 0 ) !== $operator_id ) {
				continue;
			}

			$already_notified = $wpdb->get_var( $wpdb->prepare(
				"SELECT 1 FROM {$events_table}
				 WHERE lead_id = %d AND submission_id = %d AND event_type = 'operator_notified'
				 AND operator_id = %d LIMIT 1",
				$lead_id, $submission_id, $operator_id
			) );
			if ( $already_notified ) {
				continue;
			}

			self::send_deferred_for_lead( $lead_id, $submission_id );
			$released++;
		}

		return $released;
	}

	/**
	 * Trigger immediate send for a deferred notification by reconstructing
	 * the payload and re-entering notify_operators.
	 */
	private static function send_deferred_for_lead( int $lead_id, int $submission_id ): void {
		global $wpdb;

		$lead_row = $wpdb->get_row( $wpdb->prepare(
			"SELECT name, email, phone FROM {$wpdb->prefix}es_leads WHERE id = %d LIMIT 1",
			$lead_id
		) );
		if ( ! $lead_row ) { return; }

		$sub_row = $wpdb->get_row( $wpdb->prepare(
			"SELECT context_json, form_fields_json, source_plugin, submitted_at
			 FROM {$wpdb->prefix}es_lead_submissions WHERE id = %d LIMIT 1",
			$submission_id
		) );
		if ( ! $sub_row ) { return; }

		$context_json = json_decode( (string) ( $sub_row->context_json ?? '{}' ), true );
		$context_json = is_array( $context_json ) ? $context_json : array();

		$payload = array(
			'contact' => array(
				'name'  => $lead_row->name ?? '',
				'email' => $lead_row->email ?? '',
				'phone' => $lead_row->phone ?? '',
			),
			'attribution' => array(),
			'context' => $context_json,
			'source_plugin' => $sub_row->source_plugin ?? '',
			'submitted_at' => $sub_row->submitted_at ?? '',
			'content' => array( 'message' => '' ),
			'meta' => array( 'sanitized_raw_fields' => array() ),
		);

		if ( class_exists( 'Estandar_Leads_Notifier' ) && method_exists( 'Estandar_Leads_Notifier', 'notify_operators' ) ) {
			Estandar_Leads_Notifier::notify_operators( $lead_id, $submission_id, false, $payload );
		}
	}
}
