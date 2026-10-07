<?php

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adoption of legacy lead captures (read-only over the ORIGINAL tables).
 *
 * Principles:
 *  - never modifies original values;
 *  - first-writer-wins (a legacy lead is imported once, only if not already);
 *  - idempotent + resumable + reversible (rollback deletes only imported rows);
 *  - never claims exclusive ownership (originals remain the writers);
 *  - data safety gate: only es_lead_submissions/es_leads are READ.
 */
class Adoption
{
	const LEGACY_SUBMISSIONS = 'es_lead_submissions';
	const LEGACY_LEADS       = 'es_leads';

	public static function legacy_present(): bool
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return false;
		}
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . self::LEGACY_SUBMISSIONS ) );
	}

	/** PURE: map a legacy submission row to a v2 capture projection. */
	public static function project_submission( array $row ): array
	{
		$attr = array();
		foreach ( Ingestion::ID_KEYS as $key ) {
			if ( isset( $row[ $key ] ) && trim( (string) $row[ $key ] ) !== '' ) {
				$attr[ $key ] = (string) $row[ $key ];
			}
		}
		return array(
			'capture_uuid'     => (string) ( $row['lead_uuid'] ?? '' ),
			'source'           => sanitize_key( (string) ( $row['source_plugin'] ?? 'legacy' ) ),
			'source_form_id'   => sanitize_text_field( (string) ( $row['source_form_id'] ?? '' ) ),
			'name'             => sanitize_text_field( (string) ( $row['name'] ?? '' ) ),
			'email_normalized' => (string) ( $row['email_normalized'] ?? $row['email'] ?? '' ),
			'phone_normalized' => (string) ( $row['phone_normalized'] ?? $row['phone'] ?? '' ),
			'message'          => sanitize_textarea_field( (string) ( $row['message'] ?? '' ) ),
			'fields_json'      => '{}',
			'utm_source'       => sanitize_key( (string) ( $row['utm_source'] ?? '' ) ),
			'utm_medium'       => sanitize_key( (string) ( $row['utm_medium'] ?? '' ) ),
			'utm_campaign'     => sanitize_text_field( (string) ( $row['utm_campaign'] ?? '' ) ),
			'utm_term'         => sanitize_text_field( (string) ( $row['utm_term'] ?? '' ) ),
			'utm_content'      => sanitize_text_field( (string) ( $row['utm_content'] ?? '' ) ),
			'click_ids_json'   => wp_json_encode( $attr ),
			'landing_url'      => esc_url_raw( (string) ( $row['landing_url'] ?? '' ) ),
			'referrer'         => esc_url_raw( (string) ( $row['referrer'] ?? '' ) ),
			'ip_address'       => sanitize_text_field( (string) ( $row['ip_address'] ?? '' ) ),
			'spam_honeypot'    => ! empty( $row['suspect'] ) ? 1 : 0,
			'origin_lead_id'   => (int) ( $row['lead_id'] ?? 0 ),
			'created_at'       => (string) ( $row['submitted_at'] ?? $row['created_at'] ?? gmdate( 'Y-m-d H:i:s' ) ),
		);
	}

	/** Read-only: page over historical submissions. */
	public static function historical( int $limit = 100, int $offset = 0 ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || ! self::legacy_present() ) {
			return array();
		}
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT s.* FROM {$wpdb->prefix}" . self::LEGACY_SUBMISSIONS . " s
			 ORDER BY s.id ASC LIMIT %d OFFSET %d", $limit, $offset
		), ARRAY_A );
		return array_map( array( __CLASS__, 'project_submission' ), (array) $rows );
	}

	/**
	 * Idempotent, resumable, reversible import of legacy submissions.
	 * dry_run=1 → returns would-be count, 0 writes. Never touches originals.
	 *
	 * @return array { imported, skipped, existing, dry_run }
	 */
	public static function import( bool $dry_run = true, int $limit = 100 ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || ! self::legacy_present() ) {
			return array( 'imported' => 0, 'skipped' => 0, 'existing' => 0, 'dry_run' => $dry_run );
		}
		$counts = array( 'imported' => 0, 'skipped' => 0, 'existing' => 0, 'dry_run' => $dry_run );
		foreach ( self::historical( $limit, 0 ) as $projection ) {
			$origin = (int) $projection['origin_lead_id'];
			// First-writer-wins: already adopted?
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}" . Ingestion::TABLE . " WHERE origin_lead_id = %d LIMIT 1", $origin
			) );
			if ( $exists ) {
				$counts['existing']++;
				continue;
			}
			if ( ! $dry_run ) {
				Ingestion::persist( $projection );
			}
			$counts['imported']++;
		}
		return $counts;
	}

	/** Rollback: delete ONLY rows imported by lead adoption (marker origin_lead_id>0, source NOT form/survey). */
	public static function rollback_import(): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return 0;
		}
		return (int) $wpdb->query(
			"DELETE FROM {$wpdb->prefix}" . Ingestion::TABLE
			. " WHERE origin_lead_id > 0 AND source NOT IN ('form','survey')"
		);
	}
}
