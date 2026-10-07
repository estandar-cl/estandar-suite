<?php

namespace Estandar\Insights;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Analytics events — sessions/events/conversions (absorbs the ea_* analytics
 * capability). Own table. Closed event types; DB recording gated.
 */
class Events
{
	const TABLE   = 'estandar_insights_events';
	const OPTION  = 'estandar_suite_insights_schema_version';
	const VERSION = '1.1.0';

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
			source varchar(16) NOT NULL DEFAULT '',
			payload_json longtext,
			occurred_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY event_type (event_type),
			KEY channel (channel),
			KEY project (project_slug),
			KEY source (source),
			KEY occurred_at (occurred_at)
		) $charset;" );
		update_option( self::OPTION, self::VERSION, false );
	}

	public static function maybe_migrate_schema(): void
	{
		if ( get_option( self::OPTION, '' ) !== self::VERSION ) {
			self::activate_schema();
			self::backfill_source_legacy();
		}
	}

	/**
	 * One-time backfill: adopted events created before the `source` column
	 * carried an explicit marker (payload origin=legacy). Idempotent.
	 */
	private static function backfill_source_legacy(): void
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return;
		}
		$wpdb->query(
			"UPDATE {$wpdb->prefix}" . self::TABLE . " SET source = 'legacy' WHERE source = '' AND payload_json LIKE '%\"origin\":\"legacy\"%'"
		);
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
			'source'       => sanitize_key( (string) ( $event['source'] ?? '' ) ),
			'payload_json' => wp_json_encode( is_array( $event['payload'] ?? null ) ? $event['payload'] : array() ),
			'occurred_at'  => (string) ( $event['occurred_at'] ?? current_time( 'mysql' ) ),
		), array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ) );
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
			'occurred_at' => (string) ( $capture['created_at'] ?? current_time( 'mysql' ) ),
		) );
	}

	/** Record a signal as an analytics event (contract consumer). */
	public static function on_signal( array $signal ): void
	{
		self::record( array(
			'event_type' => 'signal',
			'entity_type' => (string) ( $signal['entity_type'] ?? 'lead' ),
			'entity_id'  => (int) ( $signal['entity_id'] ?? 0 ),
			'channel'    => self::channel_from_signal( $signal ),
			'project_slug' => (string) ( $signal['project_slug'] ?? '' ),
			'occurred_at' => (string) ( $signal['event_time'] ?? current_time( 'mysql' ) ),
		) );
	}

	/**
	 * PURE: canal inferido del payload de una señal (click ids / fbc-fbp).
	 * Sin claves de atribución devuelve '' (honesto: no se inventa el canal).
	 */
	public static function channel_from_payload( array $payload ): string
	{
		$ids = isset( $payload['click_ids'] ) && is_array( $payload['click_ids'] ) ? $payload['click_ids'] : array();
		foreach ( array( 'gclid', 'wbraid', 'gbraid' ) as $k ) {
			if ( ! empty( $ids[ $k ] ) ) {
				return 'google_ads';
			}
		}
		$ident = isset( $payload['identity'] ) && is_array( $payload['identity'] ) ? $payload['identity'] : $payload;
		if ( ! empty( $ident['fbc'] ) || ! empty( $ident['fbp'] ) ) {
			return 'meta_ads';
		}
		return '';
	}

	/**
	 * Canal de una señal: primero el payload (click ids / fbc-fbp) y, si no,
	 * la captura/lead de origen (utm). Deja '' si no hay evidencia.
	 */
	public static function channel_from_signal( array $signal ): string
	{
		$payload = isset( $signal['payload_json'] )
			? ( is_array( $signal['payload_json'] ) ? $signal['payload_json'] : json_decode( (string) $signal['payload_json'], true ) )
			: array();
		$payload = is_array( $payload ) ? $payload : array();

		$channel = self::channel_from_payload( $payload );
		if ( '' !== $channel ) {
			return $channel;
		}

		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return '';
		}
		$type = sanitize_key( (string) ( $signal['entity_type'] ?? 'lead' ) );
		$id   = (int) ( $signal['entity_id'] ?? 0 );
		if ( $id <= 0 ) {
			return '';
		}

		$sub = $wpdb->prefix . 'estandar_capture_submissions';
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sub ) ) !== $sub ) {
			return '';
		}
		$row = ( 'capture' === $type )
			? $wpdb->get_row( $wpdb->prepare( "SELECT utm_source, utm_medium, click_ids_json FROM {$sub} WHERE id = %d LIMIT 1", $id ), ARRAY_A )
			: $wpdb->get_row( $wpdb->prepare( "SELECT utm_source, utm_medium, click_ids_json FROM {$sub} WHERE origin_lead_id = %d ORDER BY id ASC LIMIT 1", $id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return '';
		}

		$ids = json_decode( (string) ( $row['click_ids_json'] ?? '' ), true );
		$c2  = self::channel_from_payload( array( 'click_ids' => is_array( $ids ) ? $ids : array() ) );
		if ( '' !== $c2 ) {
			return $c2;
		}
		if ( class_exists( 'Estandar\\Core\\Channels' ) ) {
			return (string) \Estandar\Core\Channels::resolve( (string) ( $row['utm_source'] ?? '' ), (string) ( $row['utm_medium'] ?? '' ) );
		}
		return '';
	}

	/**
	 * Rellena el canal de eventos de señal ya registrados con canal vacío
	 * (best-effort, idempotente). Devuelve cuántos se completaron.
	 */
	public static function backfill_channels( int $limit = 500 ): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return 0;
		}
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, entity_type, entity_id, payload_json FROM {$wpdb->prefix}" . self::TABLE . " WHERE event_type = 'signal' AND channel = '' ORDER BY id DESC LIMIT %d",
			max( 1, min( 5000, $limit ) )
		), ARRAY_A );

		$n = 0;
		foreach ( (array) $rows as $r ) {
			$ch = self::channel_from_signal( array(
				'entity_type'  => (string) $r['entity_type'],
				'entity_id'    => (int) $r['entity_id'],
				'payload_json' => (string) $r['payload_json'],
			) );
			if ( '' !== $ch ) {
				$wpdb->update( $wpdb->prefix . self::TABLE, array( 'channel' => $ch ), array( 'id' => (int) $r['id'] ), array( '%s' ), array( '%d' ) );
				$n++;
			}
		}
		return $n;
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
