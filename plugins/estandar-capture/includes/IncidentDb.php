<?php

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * IncidentDb — storage nativo v2 de solicitudes/incidencias/adjuntos (F3).
 *
 * Traslado del esquema legacy (estandar_requests/incidents/attachments) a
 * tablas propias de capture. NUNCA escribe tablas legacy.
 */
class IncidentDb
{
	const REQUESTS_TABLE = 'estandar_capture_requests';
	const INCIDENTS_TABLE = 'estandar_capture_incidents';
	const ATTACHMENTS_TABLE = 'estandar_capture_request_attachments';
	const OPTION = 'estandar_capture_incidents_schema_version';
	const VERSION = '1.0.0';

	public static function activate_schema(): void
	{
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		dbDelta( "CREATE TABLE {$wpdb->prefix}" . self::REQUESTS_TABLE . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			request_ref varchar(40) NOT NULL DEFAULT '',
			form_id bigint(20) unsigned NOT NULL,
			form_type varchar(40) NOT NULL DEFAULT 'incident',
			form_context varchar(64) NOT NULL DEFAULT 'generic',
			request_type varchar(64) NOT NULL DEFAULT '',
			status varchar(40) NOT NULL DEFAULT 'open',
			owner_json longtext,
			context_json longtext,
			meta_json longtext,
			source_ip varchar(45) NOT NULL DEFAULT '',
			source_ua varchar(500) NOT NULL DEFAULT '',
			origin_lead_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY request_ref (request_ref),
			KEY form_id (form_id),
			KEY status (status),
			KEY origin (origin_lead_id)
		) $charset;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}" . self::INCIDENTS_TABLE . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			request_id bigint(20) unsigned NOT NULL,
			sort_order smallint(5) unsigned NOT NULL DEFAULT 0,
			location varchar(190) NOT NULL DEFAULT '',
			title varchar(190) NOT NULL DEFAULT '',
			description text,
			status varchar(40) NOT NULL DEFAULT 'open',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY request_id (request_id)
		) $charset;" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}" . self::ATTACHMENTS_TABLE . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			request_id bigint(20) unsigned NOT NULL,
			incident_id bigint(20) unsigned NOT NULL,
			file_path varchar(500) NOT NULL DEFAULT '',
			file_name varchar(190) NOT NULL DEFAULT '',
			file_size bigint(20) unsigned NOT NULL DEFAULT 0,
			mime_type varchar(100) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY request_id (request_id),
			KEY incident_id (incident_id)
		) $charset;" );

		update_option( self::OPTION, self::VERSION, false );
	}

	public static function maybe_migrate_schema(): void
	{
		if ( get_option( self::OPTION, '' ) !== self::VERSION ) {
			self::activate_schema();
		}
	}

	/** Inserta una request. @return int id (0 si falla). */
	public static function request_create( array $data ): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return 0;
		}
		$now = current_time( 'mysql' );
		$ok = $wpdb->insert(
			$wpdb->prefix . self::REQUESTS_TABLE,
			array(
				'request_ref'   => self::temporary_request_ref(),
				'form_id'       => (int) ( $data['form_id'] ?? 0 ),
				'form_type'     => sanitize_key( (string) ( $data['form_type'] ?? 'incident' ) ),
				'form_context'  => sanitize_key( (string) ( $data['form_context'] ?? 'generic' ) ),
				'request_type'  => sanitize_key( (string) ( $data['request_type'] ?? '' ) ),
				'status'        => sanitize_key( (string) ( $data['status'] ?? 'open' ) ),
				'owner_json'    => wp_json_encode( (array) ( $data['owner'] ?? array() ) ),
				'context_json'  => wp_json_encode( (array) ( $data['context'] ?? array() ) ),
				'meta_json'     => wp_json_encode( (array) ( $data['meta'] ?? array() ) ),
				'source_ip'     => sanitize_text_field( (string) ( $data['source_ip'] ?? '' ) ),
				'source_ua'     => sanitize_textarea_field( (string) ( $data['source_ua'] ?? '' ) ),
				'origin_lead_id' => (int) ( $data['origin_lead_id'] ?? 0 ),
				'created_at'    => $now,
				'updated_at'    => $now,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/** Folio REQ-xxxxxx desde el id. */
	public static function request_ref_from_id( int $request_id ): string
	{
		return 'REQ-' . str_pad( (string) $request_id, 6, '0', STR_PAD_LEFT );
	}

	private static function temporary_request_ref(): string
	{
		return 'TMP-' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 20 );
	}

	public static function request_set_ref( int $request_id, string $ref ): bool
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $request_id <= 0 ) {
			return false;
		}
		return false !== $wpdb->update(
			$wpdb->prefix . self::REQUESTS_TABLE,
			array( 'request_ref' => $ref ),
			array( 'id' => $request_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	public static function incident_create( int $request_id, int $sort_order, array $incident ): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $request_id <= 0 ) {
			return 0;
		}
		$ok = $wpdb->insert(
			$wpdb->prefix . self::INCIDENTS_TABLE,
			array(
				'request_id'  => $request_id,
				'sort_order'  => $sort_order,
				'location'    => sanitize_text_field( (string) ( $incident['location'] ?? '' ) ),
				'title'       => sanitize_text_field( (string) ( $incident['title'] ?? '' ) ),
				'description' => sanitize_textarea_field( (string) ( $incident['description'] ?? '' ) ),
				'status'      => sanitize_key( (string) ( $incident['status'] ?? 'open' ) ),
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function attachment_create( int $request_id, int $incident_id, array $file ): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $request_id <= 0 || $incident_id <= 0 ) {
			return 0;
		}
		$ok = $wpdb->insert(
			$wpdb->prefix . self::ATTACHMENTS_TABLE,
			array(
				'request_id'  => $request_id,
				'incident_id' => $incident_id,
				'file_path'   => (string) ( $file['path'] ?? '' ),
				'file_name'   => (string) ( $file['name'] ?? '' ),
				'file_size'   => (int) ( $file['size'] ?? 0 ),
				'mime_type'   => (string) ( $file['type'] ?? '' ),
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%d', '%s', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function request_get( int $request_id ): ?array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $request_id <= 0 ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . $wpdb->prefix . self::REQUESTS_TABLE . ' WHERE id = %d LIMIT 1', $request_id
		), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}
}
