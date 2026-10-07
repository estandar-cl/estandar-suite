<?php

namespace Estandar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Destination engine (absorbs estandar-destinations): webhook/sheets/CRM export
 * with durable queue (estandar_core_deliveries), retry and idempotency. Pure
 * transport; policy lives in the producing product (Flow/Capture).
 */
class Destinations
{
	const TYPES = array( 'webhook', 'sheets', 'crm' );

	/** PURE: idempotency key for a delivery (per integration when bound). */
	public static function idempotency_key( string $event_type, string $entity_type, int $entity_id, int $integration_id = 0 ): string
	{
		return hash( 'sha256', $event_type . ':' . $entity_type . ':' . $entity_id . ':' . (int) $integration_id );
	}

	/** PURE: exponential backoff seconds after the nth failed attempt. */
	public static function backoff_seconds( int $attempts ): int
	{
		if ( $attempts <= 0 ) {
			return 0;
		}
		return min( 3600, 60 * ( 1 << ( $attempts - 1 ) ) );
	}

	public static function register_destination( string $slug, string $type, string $target_url, array $headers = array() ): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || ! in_array( $type, self::TYPES, true ) || $target_url === '' ) {
			return 0;
		}
		$wpdb->replace( $wpdb->prefix . 'estandar_core_destinations', array(
			'slug'         => sanitize_key( $slug ),
			'type'         => $type,
			'target_url'   => esc_url_raw( $target_url ),
			'headers_json' => wp_json_encode( $headers ),
			'created_at'   => gmdate( 'Y-m-d H:i:s' ),
			'updated_at'   => gmdate( 'Y-m-d H:i:s' ),
		), array( '%s', '%s', '%s', '%s', '%s', '%s' ) );
		// Portable id resolution (SQLite replace() does not reliably report insert_id).
		return self::id_by_slug( sanitize_key( $slug ) );
	}

	/** Register an adapter destination (OAuth connection + adapter config). */
	public static function register_adapter( string $slug, string $type, int $connection_id, array $config ): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || ! self::is_adapter_type( $type ) || $connection_id <= 0 ) {
			return 0;
		}
		$wpdb->replace( $wpdb->prefix . 'estandar_core_destinations', array(
			'slug'          => sanitize_key( $slug ),
			'type'          => sanitize_key( $type ),
			'target_url'    => '',
			'headers_json'  => '{}',
			'connection_id' => (int) $connection_id,
			'config_json'   => wp_json_encode( (array) $config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'created_at'    => gmdate( 'Y-m-d H:i:s' ),
			'updated_at'    => gmdate( 'Y-m-d H:i:s' ),
		), array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ) );
		return self::id_by_slug( sanitize_key( $slug ) );
	}

	/** PURE-ish: resolve a destination id by slug (portable across DB engines). */
	private static function id_by_slug( string $slug ): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $slug === '' ) {
			return 0;
		}
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}estandar_core_destinations WHERE slug = %s LIMIT 1", $slug
		) );
	}

	/** Update an adapter destination's config (keeps ids, refreshes mapping). */
	public static function update_adapter_config( int $destination_id, array $config ): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $destination_id <= 0 ) {
			return 0;
		}
		return (int) $wpdb->update( $wpdb->prefix . 'estandar_core_destinations',
			array( 'config_json' => wp_json_encode( (array) $config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ),
			array( 'id' => $destination_id ), array( '%s', '%s' ), array( '%d' ) );
	}

	/** Enqueue a delivery (idempotent per event/entity). */
	public static function enqueue( string $slug, string $event_type, string $entity_type, int $entity_id, array $payload, int $integration_id = 0 ): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return 0;
		}
		$dest = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, type, target_url FROM {$wpdb->prefix}estandar_core_destinations WHERE slug = %s AND enabled = 1 LIMIT 1",
			sanitize_key( $slug )
		), ARRAY_A );
		if ( ! is_array( $dest ) ) {
			return 0;
		}
		// Adapters use connection+config; URL-based destinations require a URL.
		if ( ! self::is_adapter_type( (string) $dest['type'] ) && $dest['target_url'] === '' ) {
			return 0;
		}
		$key = self::idempotency_key( $event_type, $entity_type, $entity_id, $integration_id );
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}estandar_core_deliveries WHERE destination_id = %d AND event_type = %s AND entity_type = %s AND entity_id = %d AND status IN ('pending','retry','sent') LIMIT 1",
			(int) $dest['id'], sanitize_key( $event_type ), sanitize_key( $entity_type ), $entity_id
		) );
		if ( $existing ) {
			return (int) $existing; // already queued/sent — idempotent.
		}
		$wpdb->insert( $wpdb->prefix . 'estandar_core_deliveries', array(
			'destination_id' => (int) $dest['id'],
			'integration_id' => (int) $integration_id,
			'event_type'     => sanitize_key( $event_type ),
			'entity_type'    => sanitize_key( $entity_type ),
			'entity_id'      => $entity_id,
			'payload_json'   => wp_json_encode( $payload ),
			'status'         => 'pending',
			'created_at'     => gmdate( 'Y-m-d H:i:s' ),
			'idempotency_key' => $key,
		), array( '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ) );
		return (int) $wpdb->insert_id;
	}

	/** PURE: is this destination type a registered adapter (not a URL POST)? */
	public static function is_adapter_type( string $type ): bool
	{
		if ( ! class_exists( 'Estandar\\Core\\Adapters' ) ) {
			return false;
		}
		return Adapters::get( $type ) !== null;
	}

	/**
	 * Deliver an adapter delivery: map the payload against the integration's
	 * field mapping and append one row via the adapter.
	 */
	public static function deliver_adapter( \Estandar\Core\Adapters\AdapterInterface $adapter, ?array $connection, array $config, array $mapping, array $payload ): array
	{
		if ( ! $connection ) {
			return array( 'ok' => false, 'error' => 'sin conexión OAuth', 'summary' => '' );
		}
		if ( empty( $mapping ) ) {
			return array( 'ok' => false, 'error' => 'sin mapeo de campos', 'summary' => '' );
		}
		$headers = array_values( array_filter( (array) ( $config['columns'] ?? array() ) ) );
		$system  = array_values( array_filter( (array) ( $config['system_fields'] ?? array() ) ) );
		$meta = array(
			'entry_id'           => (string) ( $payload['id'] ?? '' ),
			'form_id'            => (string) ( $payload['source_form_id'] ?? '' ),
			'created_at'         => (string) ( $payload['created_at'] ?? '' ),
			'source_url'         => (string) ( $payload['landing_url'] ?? $payload['referrer'] ?? '' ),
			'user_ip'            => FieldMap::anonymize_ip( (string) ( $payload['ip_address'] ?? '' ) ),
			'integration_status' => 'ok',
		);
		$row = FieldMap::build_row( $payload, $headers, $mapping, $system, $meta );
		return $adapter->write( $connection, $config, $row );
	}

	/** List destinations (read API). */
	public static function list(): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}
		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT id, slug, type, target_url, connection_id, enabled, updated_at FROM {$wpdb->prefix}estandar_core_destinations ORDER BY id ASC LIMIT %d",
			200
		), ARRAY_A );
	}

	/** Delete a destination (idempotent). Returns rows affected. */
	public static function delete( int $destination_id ): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $destination_id <= 0 ) {
			return 0;
		}
		return (int) $wpdb->delete( $wpdb->prefix . 'estandar_core_destinations', array( 'id' => $destination_id ), array( '%d' ) );
	}

	/** Canonical events a destination can subscribe to. */
	public static function canonical_events(): array
	{
		return array( 'capture', 'signal', 'lead_created', 'lead_qualified', 'meeting_booked', 'sale_closed', 'lead_rejected' );
	}

	/** Event→destinations map stored in one option (no schema change). */
	const EVENT_MAP_OPTION = 'estandar_suite_core_event_map';

	/** PURE: read the event→slug map. */
	public static function event_map(): array
	{
		$map = function_exists( 'get_option' ) ? get_option( self::EVENT_MAP_OPTION, array() ) : array();
		return is_array( $map ) ? $map : array();
	}

	/** Set the events a destination (by slug) subscribes to. */
	public static function map_events( string $slug, array $events ): void
	{
		$map = self::event_map();
		$slug = sanitize_key( $slug );
		foreach ( self::canonical_events() as $event ) {
			$map[ $event ] = array_values( array_filter( (array) ( $map[ $event ] ?? array() ), static function ( $s ) use ( $slug ) {
				return $s !== $slug;
			} ) );
		}
		foreach ( array_unique( array_filter( array_map( 'sanitize_key', $events ) ) ) as $event ) {
			if ( in_array( $event, self::canonical_events(), true ) ) {
				$map[ $event ][] = $slug;
			}
		}
		if ( function_exists( 'update_option' ) ) {
			update_option( self::EVENT_MAP_OPTION, $map, false );
		}
	}

	/** PURE: which slugs receive a given event. */
	public static function event_targets( string $event ): array
	{
		$map = self::event_map();
		return (array) ( $map[ $event ] ?? array() );
	}

	/** Dispatch an event to all mapped destinations (enqueue + drain). */
	public static function dispatch_event( string $event, string $entity_type, int $entity_id, array $payload ): array
	{
		$targets = self::event_targets( $event );
		$queued = 0;
		foreach ( $targets as $slug ) {
			if ( self::enqueue( $slug, $event, $entity_type, $entity_id, $payload ) > 0 ) {
				$queued++;
			}
		}
		if ( $queued > 0 ) {
			self::drain( $queued );
		}
		return array( 'targets' => count( $targets ), 'queued' => $queued );
	}

	/** Test a destination connection with a probe payload. */
	public static function test( int $destination_id ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array( 'ok' => false, 'http' => 0, 'error' => 'entorno sin DB' );
		}
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT slug, type, target_url, connection_id, config_json FROM {$wpdb->prefix}estandar_core_destinations WHERE id = %d LIMIT 1",
			$destination_id
		), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return array( 'ok' => false, 'http' => 0, 'error' => 'destino no encontrado' );
		}
		// Adapter destinations probe the real transport (needs HTTP).
		if ( self::is_adapter_type( (string) $row['type'] ) ) {
			if ( ! function_exists( 'wp_remote_request' ) ) {
				return array( 'ok' => false, 'http' => 0, 'error' => 'entorno sin HTTP' );
			}
			$adapter = Adapters::get( (string) $row['type'] );
			$conn    = Connections::get( (int) $row['connection_id'] );
			if ( ! $conn ) {
				return array( 'ok' => false, 'http' => 0, 'error' => 'conexión OAuth no encontrada' );
			}
			$config = json_decode( (string) ( $row['config_json'] ?? '{}' ), true );
			$r = $adapter->test( $conn, is_array( $config ) ? $config : array() );
			return array( 'ok' => $r['ok'], 'http' => $r['http'], 'error' => $r['error'] );
		}
		// URL-based destination: immediate POST.
		if ( ! function_exists( 'wp_remote_post' ) || $row['target_url'] === '' ) {
			return array( 'ok' => false, 'http' => 0, 'error' => 'destino sin URL' );
		}
		$resp = wp_remote_post( (string) $row['target_url'], array(
			'body'    => wp_json_encode( array( 'estandar_suite' => true, 'test' => true, 'slug' => $row['slug'], 'ts' => gmdate( 'c' ) ) ),
			'timeout' => 10,
			'headers' => array( 'Content-Type' => 'application/json' ),
		) );
		if ( is_wp_error( $resp ) ) {
			return array( 'ok' => false, 'http' => 0, 'error' => $resp->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		return array( 'ok' => $code >= 200 && $code < 300, 'http' => $code, 'error' => $code >= 200 && $code < 300 ? '' : 'HTTP ' . $code );
	}

	/** Drain pending deliveries (WP-CLI/cron). Returns [sent, failed, skipped]. */
	public static function drain( int $limit = 25 ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || ! function_exists( 'wp_remote_post' ) ) {
			return array( 0, 0, 0 );
		}
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT d.*, dst.type AS dst_type, dst.target_url, dst.connection_id AS dst_connection_id,
			        dst.config_json AS dst_config_json, integ.mapping_json AS integ_mapping_json
			 FROM {$wpdb->prefix}estandar_core_deliveries d
			 JOIN {$wpdb->prefix}estandar_core_destinations dst ON dst.id = d.destination_id
			 LEFT JOIN {$wpdb->prefix}estandar_core_integrations integ ON integ.id = d.integration_id
			 WHERE d.status IN ('pending','retry') AND (d.next_attempt_at IS NULL OR d.next_attempt_at <= NOW())
			 ORDER BY d.id ASC LIMIT %d",
			$limit
		), ARRAY_A );
		$sent = 0; $failed = 0;
		foreach ( (array) $rows as $row ) {
			$code = 0;
			$error = '';
			$adapter = self::is_adapter_type( (string) ( $row['dst_type'] ?? 'webhook' ) ) ? Adapters::get( (string) $row['dst_type'] ) : null;
			if ( $adapter ) {
				$config = json_decode( (string) ( $row['dst_config_json'] ?? '{}' ), true );
				$mapping = json_decode( (string) ( $row['integ_mapping_json'] ?? '{}' ), true );
				$payload = json_decode( (string) ( $row['payload_json'] ?? '{}' ), true );
				$conn = Connections::get( (int) ( $row['dst_connection_id'] ?? 0 ) );
				$res = self::deliver_adapter( $adapter, $conn, is_array( $config ) ? $config : array(), is_array( $mapping ) ? $mapping : array(), is_array( $payload ) ? $payload : array() );
				$code  = $res['ok'] ? 200 : 0;
				$error = $res['ok'] ? '' : (string) $res['error'];
			} else {
				$resp = wp_remote_post( (string) $row['target_url'], array(
					'body'    => (string) ( $row['payload_json'] ?? '{}' ),
					'timeout' => 10,
					'headers' => array( 'Content-Type' => 'application/json', 'X-Estandar-Suite-Idempotency' => (string) ( $row['idempotency_key'] ?? '' ) ),
				) );
				$code  = is_wp_error( $resp ) ? 0 : (int) wp_remote_retrieve_response_code( $resp );
				$error = is_wp_error( $resp ) ? $resp->get_error_message() : 'HTTP ' . $code;
			}
			if ( $code >= 200 && $code < 300 ) {
				$wpdb->update( $wpdb->prefix . 'estandar_core_deliveries',
					array( 'status' => 'sent', 'attempts' => (int) $row['attempts'] + 1 ),
					array( 'id' => (int) $row['id'] ), array( '%s', '%d' ), array( '%d' ) );
				$sent++;
			} else {
				$attempts = (int) $row['attempts'] + 1;
				$status = $attempts >= 5 ? 'dead' : 'retry';
				// Exponential backoff on retry (60s * 2^(attempts-1), capped 1h);
				// dead rows clear next_attempt_at so the guard query skips them.
				$next = $status === 'retry' ? gmdate( 'Y-m-d H:i:s', time() + self::backoff_seconds( $attempts ) ) : null;
				$wpdb->update( $wpdb->prefix . 'estandar_core_deliveries',
					array( 'status' => $status, 'attempts' => $attempts, 'last_error' => $error, 'next_attempt_at' => $next ),
					array( 'id' => (int) $row['id'] ), array( '%s', '%d', '%s', '%s' ), array( '%d' ) );
				$failed++;
			}
		}
		return array( $sent, $failed, count( $rows ) - $sent - $failed );
	}
}
