<?php

namespace Estandar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sandbox virtual store — simulates destination files/workbooks so the whole
 * Integration Core (wizard, storage, queue, mapping, adapters) can be validated
 * end-to-end WITHOUT real OAuth credentials ("OAuth Pending" mode).
 *
 * One row = one virtual file (spreadsheet/workbook) with its columns and the
 * rows appended by the simulated adapter. Rows are persisted so drain,
 * idempotency and retries behave like the real transport.
 */
class Sandbox
{
	const TABLE = 'estandar_core_sandbox_files';

	public static function activate_schema(): void
	{
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$wpdb->prefix}" . self::TABLE . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			file_key varchar(80) NOT NULL,
			name varchar(190) NOT NULL DEFAULT '',
			sheet_title varchar(190) NOT NULL DEFAULT 'Datos',
			columns_json longtext,
			rows_json longtext,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY file_key (file_key)
		) $charset;" );
	}

	/** Create a virtual file. Returns the file_key ('' on failure). */
	public static function create( string $name, string $sheet_title, array $columns ): string
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $name === '' ) {
			return '';
		}
		$key = 'sandbox-' . substr( hash( 'sha256', $name . microtime( true ) ), 0, 16 );
		$wpdb->insert( $wpdb->prefix . self::TABLE, array(
			'file_key'     => $key,
			'name'         => sanitize_text_field( $name ),
			'sheet_title'  => sanitize_text_field( $sheet_title !== '' ? $sheet_title : 'Datos' ),
			'columns_json' => wp_json_encode( array_values( array_filter( array_map( 'strval', (array) $columns ) ) ) ),
			'rows_json'    => '[]',
			'created_at'   => gmdate( 'Y-m-d H:i:s' ),
			'updated_at'   => gmdate( 'Y-m-d H:i:s' ),
		), array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
		return $key;
	}

	/** Read a virtual file (decoded). Null when missing. */
	public static function get( string $file_key ): ?array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $file_key === '' ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}" . self::TABLE . " WHERE file_key = %s LIMIT 1", $file_key
		), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		$row['columns'] = json_decode( (string) ( $row['columns_json'] ?? '[]' ), true );
		$row['columns'] = is_array( $row['columns'] ) ? $row['columns'] : array();
		$row['rows']    = json_decode( (string) ( $row['rows_json'] ?? '[]' ), true );
		$row['rows']    = is_array( $row['rows'] ) ? $row['rows'] : array();
		return $row;
	}

	/** List virtual files for the picker. */
	public static function files(): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}
		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT file_key AS id, name, sheet_title, updated_at FROM {$wpdb->prefix}" . self::TABLE . " ORDER BY id ASC LIMIT %d",
			200
		), ARRAY_A );
	}

	/** Append one row (values aligned to columns). Returns new row count. */
	public static function append( string $file_key, array $row ): int
	{
		global $wpdb;
		$file = self::get( $file_key );
		if ( ! $file || ! isset( $wpdb ) ) {
			return -1;
		}
		$rows   = $file['rows'];
		$rows[] = array_values( array_map( 'strval', $row ) );
		$wpdb->update( $wpdb->prefix . self::TABLE,
			array( 'rows_json' => wp_json_encode( $rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ),
			array( 'file_key' => $file_key ), array( '%s', '%s' ), array( '%s' ) );
		return count( $rows );
	}

	/** Reset a file's rows (for repeatable validation). */
	public static function reset_rows( string $file_key ): bool
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return false;
		}
		$n = (int) $wpdb->update( $wpdb->prefix . self::TABLE,
			array( 'rows_json' => '[]', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ),
			array( 'file_key' => $file_key ), array( '%s', '%s' ), array( '%s' ) );
		return $n > 0;
	}
}
