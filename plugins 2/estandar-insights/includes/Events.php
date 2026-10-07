<?php

namespace Estandar\Insights;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Analytics events — sessions/events/conversions (absorbs the ea_* analytics
 * capability). Own table. PURE funnel/retention helpers; DB recording gated.
 */
class Events
{
	const TABLE   = 'estandar_insights_events';
	const OPTION  = 'estandar_suite_insights_schema_version';
	const VERSION = '1.0.0';

	const EVENT_TYPES = array( 'capture', 'pageview', 'conversion', 'signal', 'adopted' );

	public static function activate_schema(): void
	{
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$wpdb->prefix}" . self::TABLE . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_type varchar(24) NOT NULL,
			entity_type varchar(32) NOT NULL DEFAULT '',
			entity_id bigint(20) unsigned NOT NULL DEFAULT 0,
			channel varchar(32) NOT NULL DEFAULT '',
			project_slug varchar(190) NOT NULL DEFAULT '',
			payload_json longtext,
			occurred_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY event_type (event_type),
			KEY channel (channel),
			KEY project (project_slug),
			KEY occurred_at (occurred_at)
		) $charset;" );
		update_option( self::OPTION, self::VERSION, false );
	}

	public static function maybe_migrate_schema(): void
	{
		if ( get_option( self::OPTION, '' ) !== self::VERSION ) {
			self::activate_schema();
		}
	}

	/** PURE: sanitize an event type against the closed set. */
	public static function sanitize_type( string $type ): string
	{
		$type = strtolower( trim( $type ) );
		return in_array( $type, self::EVENT_TYPES, true ) ? $type : '';
	}

	/** Record an analytics event. Returns id or 0. */
	public static function record( array $event ): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return 0;
		}
		$type = self::sanitize_type( (string) ( $event['event_type'] ?? '' ) );
		if ( $type === '' ) {
			return 0;
		}
		$wpdb->insert( $wpdb->prefix . self::TABLE, array(
			'event_type'   => $type,
			'entity_type'  => sanitize_key( (string) ( $event['entity_type'] ?? '' ) ),
			'entity_id'    => (int) ( $event['entity_id'] ?? 0 ),
			'channel'      => sanitize_key( (string) ( $event['channel'] ?? '' ) ),
			'project_slug' => sanitize_title( (string) ( $event['project_slug'] ?? '' ) ),
			'payload_json' => wp_json_encode( is_array( $event['payload'] ?? null ) ? $event['payload'] : array() ),
			'occurred_at'  => (string) ( $event['occurred_at'] ?? gmdate( 'Y-m-d H:i:s' ) ),
		), array( '%s', '%s', '%d', '%s', '%s', '%s', '%s' ) );
		return (int) $wpdb->insert_id;
	}

	/** Record a capture as an analytics event (contract consumer). */
	public static function on_capture( array $capture ): void
	{
		$id = (int) ( $capture['id'] ?? 0 );
		if ( $id <= 0 ) {
			return;
		}
		$channel = 'direct';
		if ( class_exists( 'Estandar\\Core\\Channels' ) ) {
			$channel = \Estandar\Core\Channels::resolve( (string) ( $capture['utm_source'] ?? '' ), (string) ( $capture['utm_medium'] ?? '' ) );
		}
		self::record( array(
			'event_type' => 'capture',
			'entity_type' => 'capture',
			'entity_id'  => $id,
			'channel'    => $channel,
			'project_slug' => (string) ( $capture['project_slug'] ?? '' ),
			'occurred_at' => (string) ( $capture['created_at'] ?? gmdate( 'Y-m-d H:i:s' ) ),
		) );
	}

	/** Record a signal as an analytics event (contract consumer). */
	public static function on_signal( array $signal ): void
	{
		self::record( array(
			'event_type' => 'signal',
			'entity_type' => (string) ( $signal['entity_type'] ?? 'lead' ),
			'entity_id'  => (int) ( $signal['entity_id'] ?? 0 ),
			'project_slug' => (string) ( $signal['project_slug'] ?? '' ),
			'occurred_at' => (string) ( $signal['event_time'] ?? gmdate( 'Y-m-d H:i:s' ) ),
		) );
	}

	/** PURE: retention cohort from an ordered per-day event map. */
	public static function retention( array $days, int $window_days = 7 ): array
	{
		$out = array();
		foreach ( $days as $day => $cohort ) {
			$remaining = 0;
			foreach ( $cohort as $offset => $count ) {
				if ( (int) $offset <= $window_days ) {
					$remaining += (int) $count;
				}
			}
			$out[ $day ] = $remaining;
		}
		return $out;
	}

	/** Recent analytics events (read API). */
	public static function recent( int $limit = 50 ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}
		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT event_type, entity_type, entity_id, channel, project_slug, occurred_at
			 FROM {$wpdb->prefix}" . self::TABLE . " ORDER BY id DESC LIMIT %d", $limit
		), ARRAY_A );
	}
}
