<?php

namespace Estandar\Brain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Signal ledger — canonical commercial signals (absorbs the Signal Core
 * capability). Own tables; idempotent via idempotency_key; never touches the
 * legacy es_signal_* tables.
 */
class SignalLedger
{
	const TABLE   = 'estandar_brain_signals';
	const QUEUE   = 'estandar_brain_signal_queue';
	const OPTION  = 'estandar_suite_brain_schema_version';
	const VERSION = '1.0.0';

	const EVENT_NAMES = array( 'lead_created', 'lead_qualified', 'meeting_booked', 'sale_closed', 'lead_rejected' );

	public static function activate_schema(): void
	{
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$wpdb->prefix}" . self::TABLE . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_name varchar(48) NOT NULL,
			event_time datetime NOT NULL,
			entity_type varchar(32) NOT NULL DEFAULT 'lead',
			entity_id bigint(20) unsigned NOT NULL DEFAULT 0,
			entity_uuid char(36) NOT NULL DEFAULT '',
			project_slug varchar(190) NOT NULL DEFAULT '',
			idempotency_key char(64) NOT NULL,
			value_decimal decimal(14,2) DEFAULT NULL,
			currency varchar(8) NOT NULL DEFAULT '',
			payload_json longtext,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_signal (idempotency_key),
			KEY event_name (event_name),
			KEY entity (entity_type, entity_id)
		) $charset;" );
		dbDelta( "CREATE TABLE {$wpdb->prefix}" . self::QUEUE . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			signal_id bigint(20) unsigned NOT NULL DEFAULT 0,
			queue_status varchar(24) NOT NULL DEFAULT 'pending',
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			last_error text,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY queue_status (queue_status),
			KEY signal_id (signal_id)
		) $charset;" );
		update_option( self::OPTION, self::VERSION, false );
	}

	public static function maybe_migrate_schema(): void
	{
		if ( get_option( self::OPTION, '' ) !== self::VERSION ) {
			self::activate_schema();
		}
	}

	/** PURE: deterministic idempotency key for a signal. */
	public static function idempotency_key( string $event_name, string $entity_type, int $entity_id, string $occurred_at ): string
	{
		return hash( 'sha256', $event_name . ':' . $entity_type . ':' . $entity_id . ':' . $occurred_at );
	}

	/** Record a canonical signal (idempotent). Returns signal id or 0. */
	public static function record( array $signal ): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return 0;
		}
		$name = strtolower( trim( (string) ( $signal['event_name'] ?? '' ) ) );
		if ( ! in_array( $name, self::EVENT_NAMES, true ) ) {
			return 0;
		}
		$entity_id   = (int) ( $signal['entity_id'] ?? 0 );
		$entity_type = sanitize_key( (string) ( $signal['entity_type'] ?? 'lead' ) );
		$occurred_at = (string) ( $signal['event_time'] ?? gmdate( 'Y-m-d H:i:s' ) );
		// Explicit stable key (adoption/import) wins over the time-derived one.
		$key = isset( $signal['idempotency_key'] ) && preg_match( '/^[a-f0-9]{64}$/', (string) $signal['idempotency_key'] )
			? (string) $signal['idempotency_key']
			: self::idempotency_key( $name, $entity_type, $entity_id, $occurred_at );

		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}" . self::TABLE . " WHERE idempotency_key = %s LIMIT 1", $key
		) );
		if ( $existing ) {
			return (int) $existing;
		}

		$wpdb->insert( $wpdb->prefix . self::TABLE, array(
			'event_name'     => $name,
			'event_time'     => $occurred_at,
			'entity_type'    => $entity_type,
			'entity_id'      => $entity_id,
			'entity_uuid'    => (string) ( $signal['entity_uuid'] ?? '' ),
			'project_slug'   => sanitize_title( (string) ( $signal['project_slug'] ?? '' ) ),
			'idempotency_key' => $key,
			'value_decimal'  => isset( $signal['value'] ) && is_numeric( $signal['value'] ) ? (float) $signal['value'] : null,
			'currency'       => strtoupper( substr( (string) ( $signal['currency'] ?? '' ), 0, 8 ) ),
			'payload_json'   => wp_json_encode( array(
				'event_name' => $name, 'entity_type' => $entity_type, 'entity_id' => $entity_id,
				'project_slug' => (string) ( $signal['project_slug'] ?? '' ),
			) ),
			'created_at'     => gmdate( 'Y-m-d H:i:s' ),
		), array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%f', '%s', '%s', '%s' ) );
		$id = (int) $wpdb->insert_id;
		if ( $id > 0 ) {
			$wpdb->insert( $wpdb->prefix . self::QUEUE, array(
				'signal_id' => $id, 'queue_status' => 'pending', 'created_at' => gmdate( 'Y-m-d H:i:s' ),
			), array( '%d', '%s', '%s' ) );
			do_action( 'estandar_suite_signal_recorded', $signal );
		}
		return $id;
	}

	/** Consume the Capture contract: score the capture and record a signal. */
	public static function on_capture( array $capture ): void
	{
		$id   = (int) ( $capture['id'] ?? 0 );
		$uuid = (string) ( $capture['capture_uuid'] ?? '' );
		if ( $id <= 0 ) {
			return;
		}
		$intent_points = IntentMap::derive( (string) ( $capture['intent'] ?? '' ) );
		$capture_score = 0;
		if ( trim( (string) ( $capture['phone_normalized'] ?? '' ) ) !== '' ) { $capture_score += 50; }
		if ( trim( (string) ( $capture['email_normalized'] ?? '' ) ) !== '' ) { $capture_score += 30; }
		if ( trim( (string) ( $capture['name'] ?? '' ) ) !== '' )             { $capture_score += 20; }

		$score = Scoring::calculate( array(
			'capture' => $capture_score,
			'qualification' => null,
			'behavior_events' => array(),
			'profile' => null,
			'intent' => $intent_points ? $intent_points['points'] : null,
		) );
		self::record( array(
			'event_name'  => 'lead_created',
			'entity_type' => 'capture',
			'entity_id'   => $id,
			'entity_uuid' => $uuid,
			'project_slug' => (string) ( $capture['project_slug'] ?? '' ),
			'event_time'  => (string) ( $capture['created_at'] ?? gmdate( 'Y-m-d H:i:s' ) ),
			'payload_json' => array( 'score' => $score['total'], 'temperature' => $score['temperature'] ),
		) );
	}

	/** Recent signals (read API). */
	public static function recent( int $limit = 50 ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}
		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT event_name, event_time, entity_type, entity_id, project_slug, value_decimal, currency
			 FROM {$wpdb->prefix}" . self::TABLE . " ORDER BY id DESC LIMIT %d", $limit
		), ARRAY_A );
	}
}
