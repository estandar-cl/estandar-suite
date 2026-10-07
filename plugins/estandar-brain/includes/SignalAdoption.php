<?php

namespace Estandar\Brain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SignalAdoption — herencia read-only del plugin legacy estandar-signal-core.
 *
 * Copia `es_signal_clients`/`es_signal_events`/`es_signal_queue` a espejos v2
 * (`estandar_brain_signal_*`) para que el sub-tab Señales de Brain siga
 * mostrando el histórico SIN depender del plugin legacy. Mismo contrato que el
 * resto de adopciones de la suite:
 *   - solo SELECT sobre los originales (nunca escribe en es_signal_*);
 *   - first-writer-wins por legacy_id → idempotente + resumible;
 *   - rollback borra SOLO las filas adoptadas (origin='legacy');
 *   - lecturas v2-first con fallback legacy (source_table).
 */
class SignalAdoption
{
	const OPTION  = 'estandar_suite_brain_signal_schema_version';
	const VERSION = '1.0.0';

	/** Sufijo → tabla legacy (sin prefijo). */
	const MIRRORS = array(
		'clients' => 'es_signal_clients',
		'events'  => 'es_signal_events',
		'queue'   => 'es_signal_queue',
	);

	/** PURE: prefijo de BD (wpdb o 'wp_' en tests). */
	private static function prefix(): string
	{
		global $wpdb;
		return ( isset( $wpdb ) && ! empty( $wpdb->prefix ) ) ? $wpdb->prefix : 'wp_';
	}

	/** PURE: tabla espejo v2 para un sufijo. */
	public static function mirror_table( string $suffix ): string
	{
		return self::prefix() . 'estandar_brain_signal_' . sanitize_key( $suffix );
	}

	/** PURE: tabla legacy fuente para un sufijo. */
	public static function legacy_table( string $suffix ): string
	{
		return self::prefix() . ( self::MIRRORS[ $suffix ] ?? '' );
	}

	/** True cuando el sufijo es una superficie espejo conocida. */
	public static function is_mirror( string $suffix ): bool
	{
		return isset( self::MIRRORS[ sanitize_key( $suffix ) ] );
	}

	/** True cuando la tabla legacy existe. */
	public static function legacy_present( string $suffix ): bool
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || ! self::is_mirror( $suffix ) ) {
			return false;
		}
		$wpdb->get_results( 'SELECT 1 FROM ' . self::legacy_table( $suffix ) . ' LIMIT 1' );
		return empty( $wpdb->last_error );
	}

	/** True cuando la tabla espejo v2 existe. */
	public static function mirror_present( string $suffix ): bool
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || ! self::is_mirror( $suffix ) ) {
			return false;
		}
		$wpdb->get_results( 'SELECT 1 FROM ' . self::mirror_table( $suffix ) . ' LIMIT 1' );
		return empty( $wpdb->last_error );
	}

	/** Columnas reales de la tabla espejo (intersección tolerante al esquema). */
	private static function mirror_columns( string $suffix ): array
	{
		global $wpdb;
		static $cache = array();
		if ( isset( $cache[ $suffix ] ) ) {
			return $cache[ $suffix ];
		}
		$cols = array();
		if ( isset( $wpdb ) && self::mirror_present( $suffix ) ) {
			foreach ( (array) $wpdb->get_results( 'SHOW COLUMNS FROM ' . self::mirror_table( $suffix ) ) as $c ) {
				$cols[] = (string) ( $c->Field ?? $c['Field'] ?? '' );
			}
		}
		$cache[ $suffix ] = $cols;
		return $cols;
	}

	/** Crea las tablas espejo v2. */
	public static function activate_schema(): void
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		$clients = self::mirror_table( 'clients' );
		dbDelta( "CREATE TABLE {$clients} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			legacy_id bigint(20) unsigned NOT NULL DEFAULT 0,
			origin varchar(16) NOT NULL DEFAULT 'legacy',
			client_id varchar(120) NOT NULL DEFAULT '',
			client_name varchar(190) NOT NULL DEFAULT '',
			status varchar(32) NOT NULL DEFAULT 'active',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY legacy_id (legacy_id),
			UNIQUE KEY client_id (client_id),
			KEY status (status)
			) {$charset};" );

		$events = self::mirror_table( 'events' );
		dbDelta( "CREATE TABLE {$events} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			legacy_id bigint(20) unsigned NOT NULL DEFAULT 0,
			origin varchar(16) NOT NULL DEFAULT 'legacy',
			signal_uuid char(36) NOT NULL DEFAULT '',
			client_id varchar(120) NOT NULL DEFAULT '',
			project_slug varchar(190) NOT NULL DEFAULT '',
			lead_id bigint(20) unsigned DEFAULT NULL,
			submission_id bigint(20) unsigned DEFAULT NULL,
			source_event_id bigint(20) unsigned DEFAULT NULL,
			source_plugin varchar(64) NOT NULL DEFAULT '',
			event_name varchar(80) NOT NULL DEFAULT '',
			event_stage varchar(80) NOT NULL DEFAULT '',
			lifecycle_key varchar(160) NOT NULL DEFAULT '',
			idempotency_key char(64) NOT NULL DEFAULT '',
			occurrence_index int(10) unsigned NOT NULL DEFAULT 1,
			audit_only tinyint(1) NOT NULL DEFAULT 1,
			export_status varchar(32) NOT NULL DEFAULT 'audit_only',
			event_time datetime NOT NULL,
			value_decimal decimal(18,4) DEFAULT NULL,
			currency varchar(8) NOT NULL DEFAULT '',
			quality varchar(32) NOT NULL DEFAULT '',
			status varchar(32) NOT NULL DEFAULT '',
			email_hash char(64) NOT NULL DEFAULT '',
			phone_hash char(64) NOT NULL DEFAULT '',
			session_id varchar(190) NOT NULL DEFAULT '',
			gclid varchar(255) NOT NULL DEFAULT '',
			gbraid varchar(255) NOT NULL DEFAULT '',
			wbraid varchar(255) NOT NULL DEFAULT '',
			fbclid varchar(255) NOT NULL DEFAULT '',
			meta_fbc varchar(255) NOT NULL DEFAULT '',
			meta_fbp varchar(255) NOT NULL DEFAULT '',
			ttclid varchar(255) NOT NULL DEFAULT '',
			msclkid varchar(255) NOT NULL DEFAULT '',
			identity_json longtext,
			payload_json longtext,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY legacy_id (legacy_id),
			UNIQUE KEY idempotency_key (idempotency_key),
			KEY signal_uuid (signal_uuid),
			KEY client_project (client_id, project_slug),
			KEY lead_event (lead_id, event_name),
			KEY event_time (event_time),
			KEY export_status (export_status)
			) {$charset};" );

		$queue = self::mirror_table( 'queue' );
		dbDelta( "CREATE TABLE {$queue} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			legacy_id bigint(20) unsigned NOT NULL DEFAULT 0,
			origin varchar(16) NOT NULL DEFAULT 'legacy',
			signal_id bigint(20) unsigned NOT NULL,
			queue_status varchar(32) NOT NULL DEFAULT 'audit_only',
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			next_run_at datetime DEFAULT NULL,
			last_error text,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY legacy_id (legacy_id),
			UNIQUE KEY signal_id (signal_id),
			KEY queue_status (queue_status),
			KEY next_run_at (next_run_at)
			) {$charset};" );

		update_option( self::OPTION, self::VERSION, false );
	}

	public static function maybe_migrate_schema(): void
	{
		if ( get_option( self::OPTION, '' ) !== self::VERSION ) {
			self::activate_schema();
		}
	}

	private static function count_table( string $table ): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $table === '' ) {
			return 0;
		}
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table );
	}

	/** Estado de herencia por superficie. */
	public static function status(): array
	{
		$out = array();
		foreach ( array_keys( self::MIRRORS ) as $suffix ) {
			$out[ $suffix ] = array(
				'legacy'        => self::legacy_present( $suffix ) ? self::count_table( self::legacy_table( $suffix ) ) : -1,
				'adopted'       => self::mirror_present( $suffix ) ? self::count_table( self::mirror_table( $suffix ) ) : 0,
				'legacy_exists' => self::legacy_present( $suffix ),
			);
		}
		return $out;
	}

	/** Migra una superficie (idempotente por legacy_id). */
	public static function import_surface( string $suffix, bool $dry_run = true, int $limit = 100, int $offset = 0 ): array
	{
		global $wpdb;
		$counts = array( 'imported' => 0, 'existing' => 0, 'dry_run' => $dry_run );
		if ( ! isset( $wpdb ) || ! self::is_mirror( $suffix ) || ! self::legacy_present( $suffix ) ) {
			return $counts;
		}
		$legacy = self::legacy_table( $suffix );
		$mirror = self::mirror_table( $suffix );
		$rows   = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$legacy} ORDER BY id ASC LIMIT %d OFFSET %d", $limit, $offset
		), ARRAY_A );
		if ( ! $rows ) {
			return $counts;
		}
		foreach ( $rows as $row ) {
			$legacy_id = (int) ( $row['id'] ?? 0 );
			if ( $legacy_id <= 0 ) {
				continue;
			}
			$exists = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$mirror} WHERE legacy_id = %d LIMIT 1", $legacy_id
			) );
			if ( $exists ) {
				$counts['existing']++;
				continue;
			}
			if ( ! $dry_run && self::insert_mirror( $suffix, $row ) ) {
				$counts['imported']++;
			} elseif ( $dry_run ) {
				$counts['imported']++;
			}
		}
		return $counts;
	}

	private static function insert_mirror( string $suffix, array $row ): bool
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || ! self::is_mirror( $suffix ) ) {
			return false;
		}
		$mirror = self::mirror_table( $suffix );
		$cols   = self::mirror_columns( $suffix );
		$data   = array();
		$fmt    = array();
		foreach ( $row as $key => $value ) {
			if ( $key === 'id' ) {
				$data['legacy_id'] = (int) $value;
				$fmt[] = '%d';
				continue;
			}
			if ( ! in_array( $key, $cols, true ) ) {
				continue;
			}
			$data[ $key ] = $value;
			$fmt[] = '%s';
		}
		$data['origin'] = 'legacy';
		$fmt[] = '%s';
		return false !== $wpdb->insert( $mirror, $data, $fmt );
	}

	/** Migra todas las superficies. Dry-run default. */
	public static function import( bool $dry_run = true, int $limit = 100 ): array
	{
		$out = array();
		foreach ( array_keys( self::MIRRORS ) as $suffix ) {
			$surface = array( 'imported' => 0, 'existing' => 0, 'dry_run' => $dry_run );
			$offset  = 0;
			do {
				$batch = self::import_surface( $suffix, $dry_run, $limit, $offset );
				$surface['imported'] += $batch['imported'];
				$surface['existing'] += $batch['existing'];
				$offset += $limit;
			} while ( $batch['imported'] > 0 || $offset < 100000 );
			$out[ $suffix ] = $surface;
		}
		return $out;
	}

	/** Rollback: borra SOLO las filas adoptadas (origin='legacy'). */
	public static function rollback(): int
	{
		global $wpdb;
		$total = 0;
		if ( ! isset( $wpdb ) ) {
			return 0;
		}
		foreach ( array_keys( self::MIRRORS ) as $suffix ) {
			if ( ! self::mirror_present( $suffix ) ) {
				continue;
			}
			$total += (int) $wpdb->query( 'DELETE FROM ' . self::mirror_table( $suffix ) . " WHERE origin = 'legacy'" );
		}
		return $total;
	}

	/** Fuente preferida: espejo v2 si tiene datos, sino legacy. */
	public static function source_table( string $suffix ): string
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || ! self::is_mirror( $suffix ) ) {
			return '';
		}
		if ( self::mirror_present( $suffix ) && self::count_table( self::mirror_table( $suffix ) ) > 0 ) {
			return self::mirror_table( $suffix );
		}
		return self::legacy_present( $suffix ) ? self::legacy_table( $suffix ) : '';
	}

	/** Lee filas de la fuente preferida. */
	public static function rows( string $suffix, string $suffix_sql = 'ORDER BY id DESC LIMIT 50' ): array
	{
		global $wpdb;
		$table = self::source_table( $suffix );
		if ( ! isset( $wpdb ) || $table === '' ) {
			return array();
		}
		$rows = $wpdb->get_results( 'SELECT * FROM ' . $table . ' ' . $suffix_sql, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/** Cuenta filas de la fuente preferida. */
	public static function count( string $suffix ): int
	{
		global $wpdb;
		$table = self::source_table( $suffix );
		if ( ! isset( $wpdb ) || $table === '' ) {
			return 0;
		}
		return self::count_table( $table );
	}
}
