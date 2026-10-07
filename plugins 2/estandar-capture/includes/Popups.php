<?php

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * V2-managed popups (create / update / delete / list). Owns its own table
 * estandar_capture_popups; NEVER writes to the legacy CPT (estandar_pop).
 */
class Popups
{
	const TABLE   = 'estandar_capture_popups';
	const OPTION  = 'estandar_suite_capture_popups_schema_version';
	const VERSION = '1.0.0';

	public static function activate_schema(): void
	{
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$wpdb->prefix}" . self::TABLE . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(190) NOT NULL DEFAULT '',
			slug varchar(190) NOT NULL DEFAULT '',
			status varchar(40) NOT NULL DEFAULT 'draft',
			config_json longtext,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY slug (slug)
		) $charset;" );
		update_option( self::OPTION, self::VERSION, false );
	}

	public static function maybe_migrate_schema(): void
	{
		if ( get_option( self::OPTION, '' ) !== self::VERSION ) {
			self::activate_schema();
		}
	}

	public static function sanitize( array $raw ): array
	{
		$name   = sanitize_text_field( (string) ( $raw['name'] ?? '' ) );
		$slug   = sanitize_title( (string) ( $raw['slug'] ?? '' ) );
		if ( $slug === '' ) {
			$slug = sanitize_title( $name );
		}
		$status = sanitize_key( (string) ( $raw['status'] ?? 'draft' ) );
		$status = in_array( $status, array( 'active', 'draft', 'archived' ), true ) ? $status : 'draft';
		$config = trim( (string) ( $raw['config_json'] ?? '' ) );
		if ( $config === '' ) {
			$config = '{}';
		}
		json_decode( $config );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$config = '{}';
		}
		return array( 'name' => $name, 'slug' => $slug, 'status' => $status, 'config_json' => $config );
	}

	public static function list(): array
	{
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . $wpdb->prefix . self::TABLE . ' ORDER BY id DESC', ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public static function get( int $id ): ?array
	{
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . self::TABLE . ' WHERE id = %d', $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	public static function create( array $raw ): int
	{
		global $wpdb;
		$data = self::sanitize( $raw );
		if ( $data['name'] === '' ) {
			return 0;
		}
		$data['created_at'] = current_time( 'mysql' );
		$data['updated_at'] = $data['created_at'];
		$wpdb->insert( $wpdb->prefix . self::TABLE, $data, array( '%s', '%s', '%s', '%s', '%s', '%s' ) );
		return (int) $wpdb->insert_id;
	}

	public static function update( int $id, array $raw ): bool
	{
		global $wpdb;
		if ( ! self::get( $id ) ) {
			return false;
		}
		$data = self::sanitize( $raw );
		$data['updated_at'] = current_time( 'mysql' );
		$wpdb->update( $wpdb->prefix . self::TABLE, $data, array( 'id' => $id ), array( '%s', '%s', '%s', '%s', '%s' ), array( '%d' ) );
		return true;
	}

	public static function delete( int $id ): bool
	{
		global $wpdb;
		if ( ! self::get( $id ) ) {
			return false;
		}
		$wpdb->delete( $wpdb->prefix . self::TABLE, array( 'id' => $id ), array( '%d' ) );
		return true;
	}
}
