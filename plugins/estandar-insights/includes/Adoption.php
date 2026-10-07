<?php

namespace Estandar\Insights;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adoption of legacy analytics. Read-only over the ORIGINAL tables (ea_events,
 * ea_sessions, es_lead_events). Projection + idempotent import (dry-run by
 * default; rollback deletes only adopted rows).
 */
class Adoption
{
	const LEGACY_EVENTS   = 'ea_events';
	const LEGACY_SESSIONS = 'ea_sessions';
	const LEGACY_LEAD_EVENTS = 'es_lead_events';

	public static function legacy_present(): bool
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return false;
		}
		$wpdb->get_results( 'SELECT 1 FROM ' . $wpdb->prefix . self::LEGACY_EVENTS . ' LIMIT 1' );
		return empty( $wpdb->last_error );
	}

	/** PURE: project a legacy analytics event row into an Insights event. */
	public static function project( array $row ): array
	{
		$type = Events::sanitize_type( (string) ( $row['event_type'] ?? ( $row['event_name'] ?? '' ) ) );
		if ( $type === '' ) {
			$type = 'pageview'; // legacy analytics rows are pageview-like by default.
		}
		return array(
			'event_type'   => $type,
			'entity_type'  => sanitize_key( (string) ( $row['entity_type'] ?? 'legacy' ) ),
			'entity_id'    => (int) ( $row['entity_id'] ?? $row['id'] ?? 0 ),
			'channel'      => sanitize_key( (string) ( $row['channel'] ?? ( $row['source'] ?? 'direct' ) ) ),
			'project_slug' => sanitize_title( (string) ( $row['project_slug'] ?? '' ) ),
			'source'       => 'legacy',
			'payload_json' => wp_json_encode( array(
				'origin' => 'legacy',
				'label'  => (string) ( $row['event_label'] ?? '' ),
				'url'    => (string) ( $row['page_url'] ?? '' ),
				'value'  => (string) ( $row['event_value'] ?? '' ),
			) ),
			'occurred_at'  => (string) ( $row['occurred_at'] ?? $row['event_time'] ?? $row['created_at'] ?? gmdate( 'Y-m-d H:i:s' ) ),
		);
	}

	/** Read-only: page over legacy analytics events (projection). */
	public static function historical( int $limit = 100, int $offset = 0 ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || ! self::legacy_present() ) {
			return array();
		}
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, event_type, event_label, page_url, event_value, occurred_at
			 FROM {$wpdb->prefix}" . self::LEGACY_EVENTS . " ORDER BY id ASC LIMIT %d OFFSET %d", $limit, $offset
		), ARRAY_A );
		$out = array();
		foreach ( (array) $rows as $row ) {
			$proj = self::project( $row );
			if ( $proj['channel'] === '' || $proj['channel'] === 'direct' ) {
				$proj['channel'] = 'direct';
			}
			$out[] = $proj;
		}
		return $out;
	}

	/** Idempotent import of legacy analytics events (dry-run default). */
	public static function import( bool $dry_run = true, int $limit = 100 ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || ! self::legacy_present() ) {
			return array( 'imported' => 0, 'existing' => 0, 'dry_run' => $dry_run );
		}
		$counts = array( 'imported' => 0, 'existing' => 0, 'failed' => 0, 'dry_run' => $dry_run );
		foreach ( self::historical( $limit, 0 ) as $proj ) {
			// First-writer-wins by (type, entity, occurred_at).
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}" . Events::TABLE . "
				 WHERE event_type = %s AND entity_type = %s AND entity_id = %d AND occurred_at = %s LIMIT 1",
				$proj['event_type'], $proj['entity_type'], $proj['entity_id'], $proj['occurred_at']
			) );
			if ( $exists ) {
				$counts['existing']++;
				continue;
			}
			if ( $dry_run ) {
				$counts['imported']++;
			} elseif ( Events::record( $proj ) > 0 ) {
				$counts['imported']++;
			} else {
				$counts['failed']++;
			}
		}
		return $counts;
	}

	/** Rollback: delete ONLY adopted events (explicit source column). */
	public static function rollback(): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return 0;
		}
		return (int) $wpdb->query(
			"DELETE FROM {$wpdb->prefix}" . Events::TABLE . " WHERE source = 'legacy'"
		);
	}
}
