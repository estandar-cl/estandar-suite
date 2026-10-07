<?php

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capture ingestion — normalizes and persists a capture (form/chat/landing/
 * popup/omnichannel) into estandar_capture_submissions and emits the canonical
 * `estandar_suite_capture_event` contract consumed by Brain/Flow/Insights.
 *
 * PURE normalize() is unit-testable; persist() is DB-bound and never writes to
 * legacy es_* tables (data safety gate).
 */
class Ingestion
{
	const TABLE   = 'estandar_capture_submissions';
	const OPTION  = 'estandar_suite_capture_schema_version';
	const VERSION = '1.1.0';

	/** Click/attribution identifiers the Capture product owns. */
	const ID_KEYS = array( 'gclid', 'gbraid', 'wbraid', 'fbclid', 'meta_fbc', 'meta_fbp', 'ttclid', 'msclkid' );

	public static function activate_schema(): void
	{
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$wpdb->prefix}" . self::TABLE . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			capture_uuid char(36) NOT NULL DEFAULT '',
			source varchar(40) NOT NULL DEFAULT 'form',
			source_form_id varchar(128) NOT NULL DEFAULT '',
			name varchar(190) NOT NULL DEFAULT '',
			email_normalized varchar(190) NOT NULL DEFAULT '',
			phone_normalized varchar(64) NOT NULL DEFAULT '',
			message text,
			fields_json longtext,
			utm_source varchar(190) NOT NULL DEFAULT '',
			utm_medium varchar(190) NOT NULL DEFAULT '',
			utm_campaign varchar(190) NOT NULL DEFAULT '',
			utm_term varchar(190) NOT NULL DEFAULT '',
			utm_content varchar(190) NOT NULL DEFAULT '',
			click_ids_json text,
			landing_url text,
			referrer text,
			ip_address varchar(45) NOT NULL DEFAULT '',
			spam_honeypot tinyint(1) NOT NULL DEFAULT 0,
			repeat_reason varchar(64) NOT NULL DEFAULT '',
			repeat_note text,
			origin_lead_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY capture_uuid (capture_uuid),
			KEY source (source),
			KEY email (email_normalized),
			KEY created_at (created_at)
		) $charset;" );
		update_option( self::OPTION, self::VERSION, false );
	}

	public static function maybe_migrate_schema(): void
	{
		if ( get_option( self::OPTION, '' ) !== self::VERSION ) {
			self::activate_schema();
		}
	}

	/**
	 * PURE normalize. Input: arbitrary capture payload. Output: canonical
	 * capture array with normalized identity + attribution + spam flags.
	 */
	public static function normalize( array $raw ): array
	{
		$contact = is_array( $raw['contact'] ?? null ) ? $raw['contact'] : array();
		$content = is_array( $raw['content'] ?? null ) ? $raw['content'] : array();
		$attr    = is_array( $raw['attribution'] ?? null ) ? $raw['attribution'] : array();
		$spam    = is_array( $raw['spam'] ?? null ) ? $raw['spam'] : array();

		$email = strtolower( trim( (string) ( $contact['email'] ?? '' ) ) );
		$phone = preg_replace( '/\D+/', '', (string) ( $contact['phone'] ?? '' ) );

		$click_ids = array();
		foreach ( self::ID_KEYS as $key ) {
			$value = trim( (string) ( $attr[ $key ] ?? '' ) );
			if ( $value !== '' ) {
				$click_ids[ $key ] = $value;
			}
		}

		return array(
			'capture_uuid'     => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : self::fallback_uuid(),
			'source'           => sanitize_key( (string) ( $raw['source'] ?? 'form' ) ),
			'source_form_id'   => sanitize_text_field( (string) ( $content['source_form_id'] ?? '' ) ),
			'name'             => sanitize_text_field( (string) ( $contact['name'] ?? '' ) ),
			'email_normalized' => is_email( $email ) ? $email : '',
			'phone_normalized' => self::normalize_phone( $phone ),
			'message'          => sanitize_textarea_field( (string) ( $content['message'] ?? '' ) ),
			'fields_json'      => wp_json_encode( is_array( $raw['fields'] ?? null ) ? $raw['fields'] : array() ),
			'utm_source'       => sanitize_key( (string) ( $attr['utm_source'] ?? '' ) ),
			'utm_medium'       => sanitize_key( (string) ( $attr['utm_medium'] ?? '' ) ),
			'utm_campaign'     => sanitize_text_field( (string) ( $attr['utm_campaign'] ?? '' ) ),
			'utm_term'         => sanitize_text_field( (string) ( $attr['utm_term'] ?? '' ) ),
			'utm_content'      => sanitize_text_field( (string) ( $attr['utm_content'] ?? '' ) ),
			'click_ids_json'   => wp_json_encode( $click_ids ),
			'landing_url'      => esc_url_raw( (string) ( $attr['landing_url'] ?? '' ) ),
			'referrer'         => esc_url_raw( (string) ( $attr['referrer'] ?? '' ) ),
			'ip_address'       => sanitize_text_field( (string) ( $raw['ip'] ?? '' ) ),
			'spam_honeypot'    => ! empty( $spam['honeypot_filled'] ) ? 1 : 0,
			'created_at'       => gmdate( 'Y-m-d H:i:s' ),
		);
	}

	public static function fallback_uuid(): string
	{
		return sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
			mt_rand( 0, 0x0fff ) | 0x4000, mt_rand( 0, 0x3fff ) | 0x8000,
			mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ) );
	}

	/** Pure phone → E.164-style digits (56 prefix heuristic, no region hardcode). */
	public static function normalize_phone( string $digits ): string
	{
		$digits = preg_replace( '/\D+/', '', $digits );
		if ( $digits === '' ) {
			return '';
		}
		if ( strpos( $digits, '0056' ) === 0 ) {
			$digits = substr( $digits, 2 );
		}
		if ( strlen( $digits ) === 8 || strlen( $digits ) === 9 ) {
			return '56' . $digits;
		}
		return $digits;
	}

	/** Column→format map (single source for insert + row_for_insert). */
	public static function columns(): array
	{
		return array(
			'capture_uuid'     => '%s',
			'source'           => '%s',
			'source_form_id'   => '%s',
			'name'             => '%s',
			'email_normalized' => '%s',
			'phone_normalized' => '%s',
			'message'          => '%s',
			'fields_json'      => '%s',
			'utm_source'       => '%s',
			'utm_medium'       => '%s',
			'utm_campaign'     => '%s',
			'utm_term'         => '%s',
			'utm_content'      => '%s',
			'click_ids_json'   => '%s',
			'landing_url'      => '%s',
			'referrer'         => '%s',
			'ip_address'       => '%s',
			'spam_honeypot'    => '%d',
			'repeat_reason'    => '%s',
			'repeat_note'      => '%s',
			'origin_lead_id'   => '%d',
			'created_at'       => '%s',
		);
	}

	/** PURE: build the insert row (data + formats) from any capture shape. */
	public static function row_for_insert( array $capture ): array
	{
		$data    = array();
		$formats = array();
		foreach ( self::columns() as $column => $format ) {
			$data[ $column ] = isset( $capture[ $column ] ) ? $capture[ $column ] : ( $format === '%d' ? 0 : '' );
			$formats[]       = $format;
		}
		return array( 'data' => $data, 'formats' => $formats );
	}

	/** Persist a normalized capture. Returns id (0 on failure). */
	public static function persist( array $capture ): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return 0;
		}
		$row = self::row_for_insert( $capture );
		$wpdb->insert( $wpdb->prefix . self::TABLE, $row['data'], $row['formats'] );
		return (int) $wpdb->insert_id;
	}

	/** Ingest a raw capture end-to-end: normalize → persist → emit contract. */
	public static function ingest( array $raw ): array
	{
		$capture = self::normalize( $raw );
		$id      = self::persist( $capture );
		if ( $id > 0 ) {
			$capture['id'] = $id;
			do_action( 'estandar_suite_capture_event', $capture );
		}
		return array( 'id' => $id, 'capture' => $capture );
	}

	/** Read API for a capture. */
	public static function get( int $id ): ?array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $id <= 0 ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}" . self::TABLE . " WHERE id = %d LIMIT 1", $id
		), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}
}
