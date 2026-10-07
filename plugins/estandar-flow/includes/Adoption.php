<?php

namespace Estandar\Flow;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adoption of legacy commercial state. Read-only over the ORIGINAL tables
 * (es_leads, es_customers, es_commercial_outcomes_log, es_quotes). Never
 * modifies originals; idempotent import with first-writer-wins + rollback.
 */
class Adoption
{
	const LEGACY_LEADS    = 'es_leads';
	const LEGACY_CUSTOMERS = 'es_customers';
	const LEGACY_OUTCOMES = 'es_commercial_outcomes_log';
	const LEGACY_QUOTES   = 'es_quotes';

	public static function legacy_present(): bool
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return false;
		}
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . self::LEGACY_LEADS ) );
	}

	/** PURE: map a legacy lead row to a pipeline projection (stages preserved). */
	public static function project_lead( array $row ): array
	{
		$stage = (string) ( $row['sales_stage'] ?? $row['status'] ?? 'NEW' );
		if ( ! Pipeline::is_valid_stage( $stage ) ) {
			$stage = 'NEW';
		}
		return array(
			'entity_type'    => 'lead',
			'entity_id'      => (int) ( $row['id'] ?? 0 ),
			'entity_uuid'    => (string) ( $row['lead_uuid'] ?? '' ),
			'stage'          => $stage,
			'project_slug'   => sanitize_title( (string) ( $row['project_slug'] ?? '' ) ),
			'assigned_operator' => (string) ( $row['assigned_operator_name'] ?? '' ),
			'origin_lead_id' => (int) ( $row['id'] ?? 0 ),
			'created_at'     => (string) ( $row['created_at'] ?? $row['first_seen_at'] ?? gmdate( 'Y-m-d H:i:s' ) ),
			'updated_at'     => (string) ( $row['updated_at'] ?? $row['last_seen_at'] ?? gmdate( 'Y-m-d H:i:s' ) ),
		);
	}

	/** Read-only: page over legacy leads (projection). */
	public static function historical_leads( int $limit = 100, int $offset = 0 ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || ! self::legacy_present() ) {
			return array();
		}
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, lead_uuid, status, sales_stage, project_slug, assigned_operator_name, created_at, updated_at, first_seen_at, last_seen_at
			 FROM {$wpdb->prefix}" . self::LEGACY_LEADS . " ORDER BY id ASC LIMIT %d OFFSET %d", $limit, $offset
		), ARRAY_A );
		return array_map( array( __CLASS__, 'project_lead' ), (array) $rows );
	}

	/** Idempotent import of pipeline records from legacy leads. */
	public static function import_pipeline( bool $dry_run = true, int $limit = 100 ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || ! self::legacy_present() ) {
			return array( 'imported' => 0, 'existing' => 0, 'dry_run' => $dry_run );
		}
		$counts = array( 'imported' => 0, 'existing' => 0, 'dry_run' => $dry_run );
		foreach ( self::historical_leads( $limit, 0 ) as $projection ) {
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}" . Pipeline::TABLE . " WHERE origin_lead_id = %d LIMIT 1", $projection['origin_lead_id']
			) );
			if ( $exists ) {
				$counts['existing']++;
				continue;
			}
			if ( ! $dry_run ) {
				$wpdb->insert( $wpdb->prefix . Pipeline::TABLE, $projection, array( '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ) );
			}
			$counts['imported']++;
		}
		return $counts;
	}

	/** Rollback: delete only adopted pipeline records (origin_lead_id > 0). */
	public static function rollback_pipeline(): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return 0;
		}
		return (int) $wpdb->query(
			"DELETE FROM {$wpdb->prefix}" . Pipeline::TABLE . " WHERE origin_lead_id > 0"
		);
	}
}
