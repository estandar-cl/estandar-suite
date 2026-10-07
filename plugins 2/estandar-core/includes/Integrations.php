<?php

namespace Estandar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Integration engine — binds a form to a destination (via estandar_core_destinations
 * rows that reference an OAuth connection + adapter config) and dispatches
 * canonical form entries into the durable queue. The queue drain (CLI/cron) does
 * the actual transport, so a form submission never blocks on outbound calls.
 */
class Integrations
{
	const TABLE = 'estandar_core_integrations';

	const FORM_SOURCES = array( 'cf7', 'gravity', 'jotform', 'v2' );

	const MODES = array( 'parallel', 'sequential', 'conditional' );
	const ERROR_POLICIES = array( 'retry', 'dead', 'ignore' );

	/** Bind a form→destination. Idempotent per (source, form, destination). */
	public static function bind( string $form_source, int $form_id, int $destination_id, array $mapping = array(), bool $enabled = true, array $opts = array() ): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $form_id <= 0 || $destination_id <= 0 || ! in_array( $form_source, self::FORM_SOURCES, true ) ) {
			return 0;
		}
		$mode_key     = (string) ( $opts['mode'] ?? 'parallel' );
		$mode         = in_array( $mode_key, self::MODES, true ) ? sanitize_key( $mode_key ) : 'parallel';
		$priority     = max( 0, min( 999, (int) ( $opts['priority'] ?? 10 ) ) );
		$policy_key   = (string) ( $opts['error_policy'] ?? 'retry' );
		$error_policy = in_array( $policy_key, self::ERROR_POLICIES, true ) ? sanitize_key( $policy_key ) : 'retry';
		$condition    = isset( $opts['condition'] ) && is_array( $opts['condition'] ) ? Conditions::sanitize( $opts['condition'] ) : array();
		$existing = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}" . self::TABLE . " WHERE form_source = %s AND form_id = %d AND destination_id = %d LIMIT 1",
			$form_source, $form_id, $destination_id
		) );
		$now = gmdate( 'Y-m-d H:i:s' );
		$fields = array(
			'mapping_json' => wp_json_encode( (array) $mapping, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'enabled'      => $enabled ? 1 : 0,
			'mode'         => $mode,
			'priority'     => $priority,
			'error_policy' => $error_policy,
			'condition_json' => $condition ? wp_json_encode( $condition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : '',
			'updated_at'   => $now,
		);
		if ( $existing > 0 ) {
			$wpdb->update( $wpdb->prefix . self::TABLE, $fields, array( 'id' => $existing ), array( '%s', '%d', '%s', '%d', '%s', '%s', '%s' ), array( '%d' ) );
			return $existing;
		}
		$wpdb->insert( $wpdb->prefix . self::TABLE, array_merge( array(
			'form_source'   => $form_source,
			'form_id'       => $form_id,
			'destination_id'=> $destination_id,
			'created_at'    => $now,
		), $fields ), array( '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s' ) );
		return (int) $wpdb->insert_id;
	}

	/** Remove a binding. Returns rows affected. */
	public static function unbind( int $id ): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $id <= 0 ) {
			return 0;
		}
		return (int) $wpdb->delete( $wpdb->prefix . self::TABLE, array( 'id' => $id ), array( '%d' ) );
	}

	public static function get( int $id ): ?array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $id <= 0 ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}" . self::TABLE . " WHERE id = %d LIMIT 1", $id
		), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		$row['mapping']    = json_decode( (string) ( $row['mapping_json'] ?? '{}' ), true );
		$row['mapping']    = is_array( $row['mapping'] ) ? $row['mapping'] : array();
		$row['condition']  = json_decode( (string) ( $row['condition_json'] ?? '{}' ), true );
		$row['condition']  = is_array( $row['condition'] ) ? $row['condition'] : array();
		return $row;
	}

	/** List bindings, optionally filtered by form; joins destination info. */
	public static function list( string $form_source = '', int $form_id = 0 ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}
		$sql = "SELECT i.*, dst.slug AS dest_slug, dst.type AS dest_type, dst.connection_id AS dest_connection_id
		        FROM {$wpdb->prefix}" . self::TABLE . " i
		        LEFT JOIN {$wpdb->prefix}estandar_core_destinations dst ON dst.id = i.destination_id";
		$where = array();
		$args  = array();
		if ( $form_source !== '' && in_array( $form_source, self::FORM_SOURCES, true ) ) {
			$where[] = 'i.form_source = %s';
			$args[]  = $form_source;
		}
		if ( $form_id > 0 ) {
			$where[] = 'i.form_id = %d';
			$args[]  = $form_id;
		}
		if ( $where ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}
		$sql .= ' ORDER BY i.id DESC LIMIT 200';
		$rows = $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql, ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$r['mapping']   = json_decode( (string) ( $r['mapping_json'] ?? '{}' ), true );
			$r['mapping']   = is_array( $r['mapping'] ) ? $r['mapping'] : array();
			$r['condition'] = json_decode( (string) ( $r['condition_json'] ?? '{}' ), true );
			$r['condition'] = is_array( $r['condition'] ) ? $r['condition'] : array();
			$out[] = $r;
		}
		return $out;
	}

	/** Active bindings for a form, each with its destination + adapter key. */
	public static function for_form( string $form_source, int $form_id ): array
	{
		$rows = self::list( $form_source, $form_id );
		$out  = array();
		foreach ( $rows as $r ) {
			if ( (int) $r['enabled'] !== 1 || (int) ( $r['destination_id'] ?? 0 ) <= 0 ) {
				continue;
			}
			$r['adapter'] = self::adapter_key( $r['dest_type'] ?? '' );
			$out[] = $r;
		}
		return $out;
	}

	/** Map a destination type to its adapter key ('' when not an adapter). */
	public static function adapter_key( string $type ): string
	{
		return Destinations::is_adapter_type( $type ) ? $type : '';
	}

	/** Enable/disable a binding. Returns rows affected. */
	public static function set_enabled( int $id, bool $enabled ): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $id <= 0 ) {
			return 0;
		}
		return (int) $wpdb->update( $wpdb->prefix . self::TABLE,
			array( 'enabled' => $enabled ? 1 : 0, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ),
			array( 'id' => $id ), array( '%d', '%s' ), array( '%d' ) );
	}

	/** Update the per-form field mapping. Returns rows affected. */
	public static function update_mapping( int $id, array $mapping ): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $id <= 0 ) {
			return 0;
		}
		return (int) $wpdb->update( $wpdb->prefix . self::TABLE,
			array( 'mapping_json' => wp_json_encode( (array) $mapping, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ),
			array( 'id' => $id ), array( '%s', '%s' ), array( '%d' ) );
	}

	/** Dispatch a capture to every active binding of the form (async). */
	public static function dispatch_for_form( string $form_source, int $form_id, array $capture ): array
	{
		$bindings = self::for_form( $form_source, $form_id );
		// Order by priority (sequential/conditional honor the declared order).
		usort( $bindings, static function ( $a, $b ) {
			return (int) ( $a['priority'] ?? 10 ) <=> (int) ( $b['priority'] ?? 10 );
		} );
		$queued   = 0;
		$skipped  = 0;
		$total    = 0;
		foreach ( $bindings as $b ) {
			$total++;
			// Conditional mode: only run when the entry satisfies the rules.
			if ( (string) ( $b['mode'] ?? 'parallel' ) === 'conditional' && ! Conditions::evaluate( (array) ( $b['condition'] ?? array() ), $capture ) ) {
				$skipped++;
				continue;
			}
			if ( ! empty( $b['dest_slug'] ) && Destinations::enqueue(
				(string) $b['dest_slug'],
				'capture',
				'capture',
				(int) ( $capture['id'] ?? 0 ),
				$capture,
				(int) $b['id']
			) > 0 ) {
				$queued++;
			}
		}
		if ( $queued > 0 ) {
			self::schedule_drain();
		}
		return array( 'bindings' => $total, 'queued' => $queued, 'skipped' => $skipped );
	}

	/** Hook for the canonical form-entry event (fired by Capture form bridges). */
	public static function handle_form_entry( $capture, string $form_source, $form_id ): void
	{
		if ( ! is_array( $capture ) ) {
			return;
		}
		self::dispatch_for_form( sanitize_key( $form_source ), (int) $form_id, $capture );
	}

	/** Schedule a non-blocking drain shortly after enqueue. */
	public static function schedule_drain(): void
	{
		if ( ! function_exists( 'wp_schedule_single_event' ) ) {
			return;
		}
		wp_schedule_single_event( time() + 5, 'estandar_suite_core_drain' );
	}

	// ── Execution history (integration_logs) ────────────────────────────────

	public static function log( int $integration_id, int $entry_id, string $status, string $action, string $response_summary = '', string $error = '', int $duration_ms = 0 ): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return 0;
		}
		$wpdb->insert( $wpdb->prefix . 'estandar_core_integration_logs', array(
			'integration_id'   => (int) $integration_id,
			'entry_id'         => (int) $entry_id,
			'status'           => sanitize_key( $status ) ?: 'success',
			'action'           => sanitize_key( $action ) ?: 'write',
			'response_summary' => substr( sanitize_text_field( $response_summary ), 0, 500 ),
			'error'            => sanitize_textarea_field( $error ),
			'duration_ms'      => (int) $duration_ms,
			'created_at'       => gmdate( 'Y-m-d H:i:s' ),
		), array( '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s' ) );
		return (int) $wpdb->insert_id;
	}

	public static function logs( int $integration_id, int $limit = 20 ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}
		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT id, entry_id, status, action, response_summary, error, duration_ms, created_at
			 FROM {$wpdb->prefix}" . self::TABLE . "_logs
			 WHERE integration_id = %d ORDER BY id DESC LIMIT %d",
			$integration_id, $limit
		), ARRAY_A );
	}
}
