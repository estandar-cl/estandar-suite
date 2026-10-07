<?php

namespace Estandar\Flow\Quotes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schema — capa de datos ADITIVA del módulo de cotizaciones (M1).
 *
 * Sólo añade: columnas nuevas en `estandar_flow_quotes` (vía `SHOW COLUMNS`) y
 * tres tablas satélite nuevas (`dbDelta`). Nunca hace DROP/RENAME/TRUNCATE ni
 * MODIFY destructivo. Todo es idempotente: puede correr N veces sin efecto.
 *
 * Guardarraíles (runbook §1): migración 100% aditiva; si falta `$wpdb` o la
 * tabla base, no fataliza (degradación silenciosa).
 */
class Schema
{
	/** Tabla base (compartida con la fachada Quotes). */
	const TABLE = 'estandar_flow_quotes';

	/** Tablas satélite nuevas (nombres sin prefijo; ver `name()`). */
	const REVISIONS = 'estandar_flow_quote_revisions';
	const EVENTS    = 'estandar_flow_quote_events';
	const ARTIFACTS = 'estandar_flow_quote_artifacts';

	/** Guard de `maybe_migrate()` (corre una sola vez por request). */
	private static $migrated = false;

	/**
	 * Nombres (sin prefijo) de las tablas satélite del módulo.
	 * Determinista y sin dependencia de `$wpdb` (apto para tests puros).
	 *
	 * @return string[]
	 */
	public static function satellite_tables(): array
	{
		return array( self::REVISIONS, self::EVENTS, self::ARTIFACTS );
	}

	/** Nombre físico de una tabla (prefijo del sitio) — puro si no hay `$wpdb`. */
	public static function name( string $base ): string
	{
		global $wpdb;
		$prefix = ( isset( $wpdb ) && ! empty( $wpdb->prefix ) ) ? (string) $wpdb->prefix : 'wp_';
		return $prefix . $base;
	}

	/** True si la tabla (por nombre base) existe. Sin `$wpdb` → false. */
	public static function exists( string $base ): bool
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return false;
		}
		$table = self::name( $base );
		return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * Añade las columnas nuevas de `estandar_flow_quotes` que falten.
	 * Idempotente: chequea `SHOW COLUMNS` antes de cada `ALTER TABLE ADD COLUMN`.
	 * El índice `public_token` se añade sólo si falta (`SHOW INDEX`).
	 */
	public static function ensure_columns(): void
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return;
		}
		$table = $wpdb->prefix . self::TABLE;
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return; // la tabla base aún no existe (la crea Pipeline::activate_schema()).
		}

		$cols = array();
		foreach ( (array) $wpdb->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A ) as $c ) {
			$cols[] = (string) $c['Field'];
		}

		// Todas con DEFAULT; ninguna NOT NULL sin default (aditivo seguro).
		$add = array(
			'quote_number'     => "ADD COLUMN quote_number VARCHAR(32) NOT NULL DEFAULT ''",
			'profile_key'      => "ADD COLUMN profile_key VARCHAR(64) NOT NULL DEFAULT ''",
			'valid_until'      => 'ADD COLUMN valid_until DATETIME NULL',
			'subtotal'         => 'ADD COLUMN subtotal DECIMAL(14,2) NULL',
			'tax_amount'       => 'ADD COLUMN tax_amount DECIMAL(14,2) NULL',
			'discount_amount'  => 'ADD COLUMN discount_amount DECIMAL(14,2) NULL',
			'total'            => 'ADD COLUMN total DECIMAL(14,2) NULL',
			'public_token'     => "ADD COLUMN public_token VARCHAR(64) NOT NULL DEFAULT ''",
			'client_tax_id'    => "ADD COLUMN client_tax_id VARCHAR(32) NOT NULL DEFAULT ''",
			'document_json'    => 'ADD COLUMN document_json LONGTEXT NULL',
			'revision_counter' => 'ADD COLUMN revision_counter INT NOT NULL DEFAULT 0',
			'accepted_at'      => 'ADD COLUMN accepted_at DATETIME NULL',
			'rejected_at'      => 'ADD COLUMN rejected_at DATETIME NULL',
			'reject_reason'    => "ADD COLUMN reject_reason VARCHAR(255) NOT NULL DEFAULT ''",
		);
		foreach ( $add as $col => $sql ) {
			if ( ! in_array( $col, $cols, true ) ) {
				$wpdb->query( "ALTER TABLE {$table} {$sql}" );
			}
		}

		$indexes = array();
		foreach ( (array) $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A ) as $idx ) {
			$indexes[] = (string) ( $idx['Key_name'] ?? '' );
		}
		if ( ! in_array( 'public_token', $indexes, true ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD KEY public_token (public_token)" );
		}
	}

	/**
	 * Crea las tablas satélite (`dbDelta`, idempotente) y asegura las columnas.
	 * Sin `$wpdb`/`dbDelta` no fataliza (degradación limpia).
	 */
	public static function activate(): void
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return;
		}
		$upgrade = ABSPATH . 'wp-admin/includes/upgrade.php';
		if ( file_exists( $upgrade ) ) {
			require_once $upgrade;
		}
		if ( function_exists( 'dbDelta' ) ) {
			$charset = $wpdb->get_charset_collate();

			dbDelta( "CREATE TABLE {$wpdb->prefix}" . self::REVISIONS . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				quote_id bigint(20) unsigned NOT NULL DEFAULT 0,
				revision_no int NOT NULL DEFAULT 0,
				revision_uuid char(36) NOT NULL DEFAULT '',
				snapshot_json longtext NULL,
				snapshot_hash char(64) NOT NULL DEFAULT '',
				created_by varchar(190) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY qrev (quote_id, revision_no),
				KEY quote_id (quote_id)
			) $charset;" );

			dbDelta( "CREATE TABLE {$wpdb->prefix}" . self::EVENTS . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				quote_id bigint(20) unsigned NOT NULL DEFAULT 0,
				event_type varchar(64) NOT NULL DEFAULT '',
				actor varchar(190) NOT NULL DEFAULT '',
				metadata_json longtext NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY quote_id (quote_id)
			) $charset;" );

			dbDelta( "CREATE TABLE {$wpdb->prefix}" . self::ARTIFACTS . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				quote_id bigint(20) unsigned NOT NULL DEFAULT 0,
				revision_uuid char(36) NOT NULL DEFAULT '',
				source_hash char(64) NOT NULL DEFAULT '',
				renderer varchar(32) NOT NULL DEFAULT '',
				pdf_sha256 char(64) NOT NULL DEFAULT '',
				byte_size bigint(20) unsigned NOT NULL DEFAULT 0,
				storage_key varchar(255) NOT NULL DEFAULT '',
				status varchar(24) NOT NULL DEFAULT 'ready',
				generated_at datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY art (quote_id, revision_uuid, renderer, source_hash)
			) $charset;" );
		}

		self::ensure_columns();
	}

	/**
	 * Migra una vez por request si la tabla base existe. Guard estático.
	 * Pensado para correr en `plugins_loaded` (idempotente y barato).
	 */
	public static function maybe_migrate(): void
	{
		if ( self::$migrated ) {
			return;
		}
		self::$migrated = true;
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return;
		}
		$table = $wpdb->prefix . self::TABLE;
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return; // la tabla base aún no existe; se reintenta en el próximo request.
		}
		self::ensure_columns();
		self::activate();
	}
}
