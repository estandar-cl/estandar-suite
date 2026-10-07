<?php

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adoption of legacy Estandar Forms resources (read-only over the ORIGINAL
 * tables), with the SAME contract as the Leads adoption (`Adoption`):
 *  - never modifies original values;
 *  - first-writer-wins (a legacy entry/response is imported once);
 *  - idempotent + resumable + reversible (rollback deletes only imported rows);
 *  - never claims exclusive ownership (originals remain the writers);
 *  - data safety gate: only estandar_forms* tables are READ.
 *
 * Resources projected as v2 captures: form entries (submissions) and survey
 * responses. `origin_lead_id` stores the legacy row id and `source` the origin
 * type (`form` | `survey`) so rollback is scoped and never touches lead imports.
 */
class FormsAdoption
{
	const LEGACY_FORMS      = 'estandar_forms';
	const LEGACY_ENTRIES    = 'estandar_form_entries';
	const LEGACY_RESPONSES  = 'estandar_survey_responses';

	public static function legacy_present(): bool
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return false;
		}
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . self::LEGACY_ENTRIES ) );
	}

	public static function legacy_responses_present(): bool
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return false;
		}
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . self::LEGACY_RESPONSES ) );
	}

	/** PURE: map a legacy form entry row to a v2 capture projection. */
	public static function project_entry( array $row ): array
	{
		$fields = array();
		$raw    = (string) ( $row['data_json'] ?? '' );
		if ( $raw !== '' && $raw !== 'null' ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$fields = $decoded;
			}
		}
		$pick = static function ( array $keys ) use ( $fields ): string {
			foreach ( $keys as $key ) {
				foreach ( $fields as $k => $v ) {
					if ( is_scalar( $v ) && strcasecmp( (string) $k, $key ) === 0 ) {
						return (string) $v;
					}
				}
			}
			return '';
		};
		return array(
			'capture_uuid'     => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : Ingestion::fallback_uuid(),
			'source'           => 'form',
			'source_form_id'   => (string) ( $row['form_id'] ?? '' ),
			'name'             => sanitize_text_field( $pick( array( 'name', 'nombre', 'nombre_completo', 'full_name' ) ) ),
			'email_normalized' => sanitize_text_field( strtolower( $pick( array( 'email', 'correo', 'correo_electronico' ) ) ) ),
			'phone_normalized' => Ingestion::normalize_phone( $pick( array( 'phone', 'telefono', 'celular', 'whatsapp' ) ) ),
			'message'          => sanitize_textarea_field( $pick( array( 'message', 'mensaje', 'comentario', 'consulta' ) ) ),
			'fields_json'      => wp_json_encode( $fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'origin_lead_id'   => (int) ( $row['id'] ?? 0 ),
			'created_at'       => (string) ( $row['created_at'] ?? gmdate( 'Y-m-d H:i:s' ) ),
		);
	}

	/** PURE: map a legacy survey response row to a v2 capture projection. */
	public static function project_response( array $row ): array
	{
		return array(
			'capture_uuid'     => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : Ingestion::fallback_uuid(),
			'source'           => 'survey',
			'source_form_id'   => (string) ( $row['form_id'] ?? '' ),
			'name'             => sanitize_text_field( (string) ( $row['session_token'] ?? '' ) ),
			'message'          => sanitize_textarea_field( (string) ( $row['answer_value'] ?? '' ) ),
			'fields_json'      => wp_json_encode( array(
				'question_index' => (int) ( $row['question_index'] ?? 0 ),
				'question_label' => (string) ( $row['question_label'] ?? '' ),
				'question_type'  => (string) ( $row['question_type'] ?? 'text' ),
				'answer_value'   => (string) ( $row['answer_value'] ?? '' ),
				'answer_label'   => (string) ( $row['answer_label'] ?? '' ),
				'entry_id'       => (int) ( $row['entry_id'] ?? 0 ),
				'time_spent'     => (int) ( $row['time_spent_seconds'] ?? 0 ),
			), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'origin_lead_id'   => (int) ( $row['id'] ?? 0 ),
			'created_at'       => (string) ( $row['answered_at'] ?? gmdate( 'Y-m-d H:i:s' ) ),
		);
	}

	/** Read-only: page over historical form entries (projected). */
	public static function historical_entries( int $limit = 100, int $offset = 0 ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || ! self::legacy_present() ) {
			return array();
		}
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}" . self::LEGACY_ENTRIES . " ORDER BY id ASC LIMIT %d OFFSET %d", $limit, $offset
		), ARRAY_A );
		return array_map( array( __CLASS__, 'project_entry' ), (array) $rows );
	}

	/** Read-only: page over historical survey responses (projected). */
	public static function historical_responses( int $limit = 100, int $offset = 0 ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || ! self::legacy_responses_present() ) {
			return array();
		}
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}" . self::LEGACY_RESPONSES . " ORDER BY id ASC LIMIT %d OFFSET %d", $limit, $offset
		), ARRAY_A );
		return array_map( array( __CLASS__, 'project_response' ), (array) $rows );
	}

	/**
	 * Idempotent, resumable, reversible import of legacy form entries.
	 * dry_run=1 → returns would-be count, 0 writes. Never touches originals.
	 *
	 * @return array { imported, skipped, existing, dry_run }
	 */
	public static function import_entries( bool $dry_run = true, int $limit = 100 ): array
	{
		return self::import_rows( self::historical_entries( $limit, 0 ), 'form', $dry_run );
	}

	/**
	 * Idempotent, resumable, reversible import of legacy survey responses.
	 *
	 * @return array { imported, skipped, existing, dry_run }
	 */
	public static function import_responses( bool $dry_run = true, int $limit = 100 ): array
	{
		return self::import_rows( self::historical_responses( $limit, 0 ), 'survey', $dry_run );
	}

	/** Shared importer: first-writer-wins by origin marker + source. */
	private static function import_rows( array $projections, string $source, bool $dry_run ): array
	{
		global $wpdb;
		$counts = array( 'imported' => 0, 'skipped' => 0, 'existing' => 0, 'dry_run' => $dry_run );
		if ( ! isset( $wpdb ) || ! $projections ) {
			return $counts;
		}
		foreach ( $projections as $projection ) {
			$origin = (int) $projection['origin_lead_id'];
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}" . Ingestion::TABLE
				. " WHERE origin_lead_id = %d AND source = %s LIMIT 1", $origin, $source
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

	/** Rollback: delete ONLY form entries imported by adoption (marker). */
	public static function rollback_entries(): int
	{
		return self::rollback_source( 'form' );
	}

	/** Rollback: delete ONLY survey responses imported by adoption (marker). */
	public static function rollback_responses(): int
	{
		return self::rollback_source( 'survey' );
	}

	private static function rollback_source( string $source ): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return 0;
		}
		return (int) $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}" . Ingestion::TABLE . " WHERE origin_lead_id > 0 AND source = %s", $source
		) );
	}

	/** Read-only resource counts across the legacy Forms surface. */
	public static function resource_counts(): array
	{
		global $wpdb;
		$tables = array( 'estandar_forms', 'estandar_form_entries', 'estandar_survey_responses', 'estandar_incidents', 'estandar_form_logs', 'estandar_requests', 'estandar_attachments', 'estandar_tokens' );
		$out    = array();
		if ( ! isset( $wpdb ) ) {
			foreach ( $tables as $t ) {
				$out[ $t ] = 0;
			}
			return $out;
		}
		foreach ( $tables as $t ) {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $t ) );
			$out[ $t ] = $exists ? (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . $t ) : 0;
		}
		return $out;
	}
}
