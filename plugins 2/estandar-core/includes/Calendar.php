<?php

namespace Estandar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Calendar
{
	const OPTION = 'estandar_suite_calendar_config';
	const TABLE  = 'estandar_core_calendar_events';

	const EVENT_TYPES = array( 'meeting', 'call', 'visit', 'webinar', 'videocall' );

	const EVENT_STATUSES = array( 'scheduled', 'confirmed', 'completed', 'cancelled', 'rescheduled' );

	public static function activate_schema(): void
	{
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$wpdb->prefix}" . self::TABLE . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_uuid char(36) NOT NULL DEFAULT '',
			event_type varchar(24) NOT NULL DEFAULT 'meeting',
			status varchar(24) NOT NULL DEFAULT 'scheduled',
			pipeline_id bigint(20) unsigned NOT NULL DEFAULT 0,
			operator_id varchar(190) NOT NULL DEFAULT '',
			lead_name varchar(190) NOT NULL DEFAULT '',
			lead_email varchar(190) NOT NULL DEFAULT '',
			scheduled_at datetime NOT NULL,
			duration_minutes int unsigned NOT NULL DEFAULT 30,
			location varchar(255) NOT NULL DEFAULT '',
			notes text,
			metadata_json longtext,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY (id),
			KEY pipeline (pipeline_id),
			KEY operator (operator_id),
			KEY scheduled (scheduled_at),
			KEY status (status)
		) $charset;" );
		update_option( 'estandar_suite_calendar_schema_version', '1.0.0', false );
	}

	public static function get_available_slots( string $date, string $operator_id = '', int $duration_minutes = 30 ): array
	{
		$config = get_option( self::OPTION, array() );
		$config = is_array( $config ) ? $config : array();
		$start_hour = (int) ( $config['business_hour_start'] ?? 9 );
		$end_hour   = (int) ( $config['business_hour_end'] ?? 18 );
		$buffer      = (int) ( $config['buffer_minutes'] ?? 15 );
		$exclude_days = (array) ( $config['exclude_days'] ?? array( 'Saturday', 'Sunday' ) );

		$day_of_week = gmdate( 'l', strtotime( $date ) );
		if ( in_array( $day_of_week, $exclude_days, true ) ) {
			return array();
		}

		$slots = array();
		$hour = $start_hour;
		while ( $hour + ( $duration_minutes / 60 ) <= $end_hour ) {
			$time = sprintf( '%02d:00', $hour );
			$slots[] = array( 'date' => $date, 'time' => $time, 'available' => true );
			$hour += ( $duration_minutes + $buffer ) / 60;
		}
		return $slots;
	}

	public static function create_event( array $data ): ?int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return null;
		}
		$event_uuid = $data['event_uuid'] ?? wp_generate_uuid4();
		$event_type = in_array( $data['event_type'] ?? '', self::EVENT_TYPES, true )
			? $data['event_type'] : 'meeting';

		$wpdb->insert( $wpdb->prefix . self::TABLE, array(
			'event_uuid'     => $event_uuid,
			'event_type'     => $event_type,
			'status'         => 'scheduled',
			'pipeline_id'    => (int) ( $data['pipeline_id'] ?? 0 ),
			'operator_id'    => sanitize_text_field( (string) ( $data['operator_id'] ?? '' ) ),
			'lead_name'      => sanitize_text_field( (string) ( $data['lead_name'] ?? '' ) ),
			'lead_email'     => sanitize_email( (string) ( $data['lead_email'] ?? '' ) ),
			'scheduled_at'   => sanitize_text_field( (string) ( $data['scheduled_at'] ?? '' ) ),
			'duration_minutes' => (int) ( $data['duration_minutes'] ?? 30 ),
			'location'       => sanitize_text_field( (string) ( $data['location'] ?? '' ) ),
			'notes'          => sanitize_textarea_field( (string) ( $data['notes'] ?? '' ) ),
			'metadata_json'  => wp_json_encode( (array) ( $data['metadata'] ?? array() ) ),
			'created_at'     => gmdate( 'Y-m-d H:i:s' ),
			'updated_at'     => gmdate( 'Y-m-d H:i:s' ),
		), array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ) );

		$event_id = (int) $wpdb->insert_id;
		do_action( 'estandar_suite_meeting_scheduled', $event_id, $data );
		return $event_id;
	}

	public static function update_status( int $event_id, string $status ): bool
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || ! in_array( $status, self::EVENT_STATUSES, true ) ) {
			return false;
		}
		$updated = $wpdb->update( $wpdb->prefix . self::TABLE,
			array( 'status' => $status, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ),
			array( 'id' => $event_id ), array( '%s', '%s' ), array( '%d' ) );
		if ( $updated ) {
			do_action( "estandar_suite_meeting_{$status}", $event_id );
		}
		return (bool) $updated;
	}

	public static function list( int $limit = 50 ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}
		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}" . self::TABLE . " ORDER BY scheduled_at DESC LIMIT %d", $limit
		), ARRAY_A );
	}
}
