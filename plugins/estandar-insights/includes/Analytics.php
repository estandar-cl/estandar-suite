<?php

namespace Estandar\Insights;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Analytics inheritance from the legacy estandar-analytics plugin.
 *
 * Owns v2 mirror tables (estandar_insights_analytics_*) that replicate the
 * legacy ea_* schema, so the dashboard/CLI/panel read from v2-owned data and
 * NOTHING depends on the legacy plugin (which can be deleted). The legacy
 * tables are read-only sources for the migration and stay as backup.
 *
 * Migration is idempotent (first-writer-wins by legacy_id) with dry-run/commit
 * and rollback (deletes only adopted rows).
 */
class Analytics
{
	const OPTION   = 'estandar_suite_insights_analytics_schema_version';
	const VERSION  = '1.0.0';

	/** Mirror suffix => legacy table name (without $wpdb->prefix). */
	const MIRRORS = array(
		'sessions'   => 'ea_sessions',
		'pageviews'  => 'ea_pageviews',
		'events'     => 'ea_events',
		'conversions'=> 'ea_conversions',
		'aggregates' => 'ea_daily_aggregates',
	);

	/** PURE: v2-owned mirror table name for a mirror suffix. */
	public static function mirror_table( string $suffix ): string
	{
		return self::prefix() . 'estandar_insights_analytics_' . sanitize_key( $suffix );
	}

	/** PURE: legacy source table name for a mirror suffix. */
	public static function legacy_table( string $suffix ): string
	{
		return self::prefix() . ( self::MIRRORS[ $suffix ] ?? '' );
	}

	/** PURE: database prefix (wpdb when available, 'wp_' in tests). */
	private static function prefix(): string
	{
		global $wpdb;
		return ( isset( $wpdb ) && ! empty( $wpdb->prefix ) ) ? $wpdb->prefix : 'wp_';
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

	/** True when the mirror suffix is a known analytics surface. */
	public static function is_mirror( string $suffix ): bool
	{
		return isset( self::MIRRORS[ sanitize_key( $suffix ) ] );
	}

	/** Create the v2-owned mirror tables (dbDelta, idempotent). */
	public static function activate_schema(): void
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		$sessions = self::mirror_table( 'sessions' );
		dbDelta( "CREATE TABLE {$sessions} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			legacy_id bigint(20) unsigned NOT NULL DEFAULT 0,
			origin varchar(16) NOT NULL DEFAULT 'legacy',
			session_id varchar(64) NOT NULL,
			visitor_hash varchar(64) NOT NULL DEFAULT '',
			first_seen datetime NOT NULL,
			last_activity datetime NOT NULL,
			duration int(10) unsigned NOT NULL DEFAULT 0,
			page_count smallint(5) unsigned NOT NULL DEFAULT 0,
			is_bounce tinyint(1) NOT NULL DEFAULT 1,
			landing_page varchar(2048) NOT NULL DEFAULT '',
			exit_page varchar(2048) NOT NULL DEFAULT '',
			is_returning tinyint(1) NOT NULL DEFAULT 0,
			source varchar(100) NOT NULL DEFAULT '',
			medium varchar(100) NOT NULL DEFAULT '',
			campaign varchar(255) NOT NULL DEFAULT '',
			content varchar(255) NOT NULL DEFAULT '',
			term varchar(255) NOT NULL DEFAULT '',
			campaign_id varchar(190) NOT NULL DEFAULT '',
			adset_id varchar(190) NOT NULL DEFAULT '',
			ad_id varchar(190) NOT NULL DEFAULT '',
			adgroup_id varchar(190) NOT NULL DEFAULT '',
			asset_group_id varchar(190) NOT NULL DEFAULT '',
			creative_id varchar(190) NOT NULL DEFAULT '',
			keyword varchar(255) NOT NULL DEFAULT '',
			match_type varchar(32) NOT NULL DEFAULT '',
			network varchar(32) NOT NULL DEFAULT '',
			google_device varchar(32) NOT NULL DEFAULT '',
			placement varchar(255) NOT NULL DEFAULT '',
			campaign_type varchar(64) NOT NULL DEFAULT '',
			gclid varchar(255) NOT NULL DEFAULT '',
			gbraid varchar(255) NOT NULL DEFAULT '',
			wbraid varchar(255) NOT NULL DEFAULT '',
			fbclid varchar(255) NOT NULL DEFAULT '',
			meta_fbp varchar(255) NOT NULL DEFAULT '',
			meta_fbc varchar(255) NOT NULL DEFAULT '',
			ttclid varchar(255) NOT NULL DEFAULT '',
			msclkid varchar(255) NOT NULL DEFAULT '',
			referrer varchar(2048) NOT NULL DEFAULT '',
			referrer_domain varchar(255) NOT NULL DEFAULT '',
			traffic_type varchar(50) NOT NULL DEFAULT '',
			first_project_slug varchar(190) NOT NULL DEFAULT '',
			first_project_page varchar(2048) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY legacy_id (legacy_id),
			KEY session_id (session_id),
			KEY first_seen (first_seen),
			KEY first_project_slug (first_project_slug),
			KEY source (source)
			) {$charset};" );

		$pageviews = self::mirror_table( 'pageviews' );
		dbDelta( "CREATE TABLE {$pageviews} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			legacy_id bigint(20) unsigned NOT NULL DEFAULT 0,
			origin varchar(16) NOT NULL DEFAULT 'legacy',
			session_id varchar(64) NOT NULL,
			page_url varchar(2048) NOT NULL DEFAULT '',
			page_title varchar(500) NOT NULL DEFAULT '',
			viewed_at datetime NOT NULL,
			time_on_page int(10) unsigned NOT NULL DEFAULT 0,
			scroll_depth tinyint(3) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY legacy_id (legacy_id),
			KEY session_id (session_id),
			KEY viewed_at (viewed_at)
			) {$charset};" );

		$events = self::mirror_table( 'events' );
		dbDelta( "CREATE TABLE {$events} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			legacy_id bigint(20) unsigned NOT NULL DEFAULT 0,
			origin varchar(16) NOT NULL DEFAULT 'legacy',
			event_id varchar(64) DEFAULT NULL,
			session_id varchar(64) NOT NULL,
			event_type varchar(50) NOT NULL,
			event_label varchar(255) NOT NULL DEFAULT '',
			page_url varchar(2048) NOT NULL DEFAULT '',
			event_value varchar(500) NOT NULL DEFAULT '',
			meta_json longtext,
			occurred_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY legacy_id (legacy_id),
			UNIQUE KEY event_id (event_id),
			KEY session_id (session_id),
			KEY event_type (event_type),
			KEY occurred_at (occurred_at)
			) {$charset};" );

		$conversions = self::mirror_table( 'conversions' );
		dbDelta( "CREATE TABLE {$conversions} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			legacy_id bigint(20) unsigned NOT NULL DEFAULT 0,
			origin varchar(16) NOT NULL DEFAULT 'legacy',
			session_id varchar(64) NOT NULL,
			conversion_key varchar(100) NOT NULL,
			conversion_label varchar(255) NOT NULL DEFAULT '',
			page_url varchar(2048) NOT NULL DEFAULT '',
			project_slug varchar(190) NOT NULL DEFAULT '',
			source varchar(100) NOT NULL DEFAULT '',
			medium varchar(100) NOT NULL DEFAULT '',
			campaign varchar(255) NOT NULL DEFAULT '',
			landing_page varchar(2048) NOT NULL DEFAULT '',
			conversion_value int(10) unsigned NOT NULL DEFAULT 0,
			converted_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY legacy_id (legacy_id),
			KEY session_id (session_id),
			KEY conversion_key (conversion_key),
			KEY converted_at (converted_at),
			KEY source (source),
			KEY project_slug (project_slug)
			) {$charset};" );

		$aggregates = self::mirror_table( 'aggregates' );
		dbDelta( "CREATE TABLE {$aggregates} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			legacy_id bigint(20) unsigned NOT NULL DEFAULT 0,
			origin varchar(16) NOT NULL DEFAULT 'legacy',
			aggregate_date date NOT NULL,
			metric_key varchar(100) NOT NULL,
			metric_value bigint(20) NOT NULL DEFAULT 0,
			dimension_key varchar(100) NOT NULL DEFAULT '',
			dimension_value varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY legacy_id (legacy_id),
			UNIQUE KEY agg_unique (aggregate_date, metric_key, dimension_key(50), dimension_value(100)),
			KEY aggregate_date (aggregate_date),
			KEY metric_key (metric_key)
			) {$charset};" );

		update_option( self::OPTION, self::VERSION, false );
	}

	public static function maybe_migrate_schema(): void
	{
		if ( get_option( self::OPTION, '' ) !== self::VERSION ) {
			self::activate_schema();
		}
	}

	/** True when the legacy source table exists in the current database. */
	public static function legacy_present( string $suffix ): bool
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || ! self::is_mirror( $suffix ) ) {
			return false;
		}
		$table = self::legacy_table( $suffix );
		$wpdb->get_results( 'SELECT 1 FROM ' . $table . ' LIMIT 1' );
		return empty( $wpdb->last_error );
	}

	/** True when the v2 mirror table exists. */
	public static function mirror_present( string $suffix ): bool
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || ! self::is_mirror( $suffix ) ) {
			return false;
		}
		$table = self::mirror_table( $suffix );
		$wpdb->get_results( 'SELECT 1 FROM ' . $table . ' LIMIT 1' );
		return empty( $wpdb->last_error );
	}

	/** Count rows in a table (mirror or legacy source). */
	private static function count_table( string $table ): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $table === '' ) {
			return 0;
		}
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table );
	}

	/**
	 * Per-surface inheritance status: legacy rows vs adopted rows vs total.
	 * keyed by mirror suffix.
	 */
	public static function status(): array
	{
		$out = array();
		foreach ( array_keys( self::MIRRORS ) as $suffix ) {
			$out[ $suffix ] = array(
				'legacy'  => self::legacy_present( $suffix ) ? self::count_table( self::legacy_table( $suffix ) ) : -1,
				'adopted' => self::mirror_present( $suffix ) ? self::count_table( self::mirror_table( $suffix ) ) : 0,
				'legacy_exists' => self::legacy_present( $suffix ),
			);
		}
		return $out;
	}

	/**
	 * Idempotent migration of one surface. Returns [imported, existing, dry_run].
	 * Copy order: oldest first so re-runs continue where they left off.
	 */
	public static function import_surface( string $suffix, bool $dry_run = true, int $limit = 100, int $offset = 0 ): array
	{
		global $wpdb;
		$counts = array( 'imported' => 0, 'existing' => 0, 'failed' => 0, 'dry_run' => $dry_run );
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
			if ( $dry_run ) {
				$counts['imported']++;
			} elseif ( self::insert_mirror( $suffix, $row ) ) {
				$counts['imported']++;
			} else {
				$counts['failed']++;
			}
		}
		return $counts;
	}

	/** Insert one legacy row into its mirror (origin='legacy', legacy_id preserved). */
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

	/** Migrate all surfaces. Dry-run by default. Returns per-surface counts. */
	public static function import( bool $dry_run = true, int $limit = 100 ): array
	{
		$out = array();
		foreach ( array_keys( self::MIRRORS ) as $suffix ) {
			$surface = array( 'imported' => 0, 'existing' => 0, 'failed' => 0, 'dry_run' => $dry_run );
			$offset  = 0;
			do {
				$batch = self::import_surface( $suffix, $dry_run, $limit, $offset );
				$surface['imported'] += $batch['imported'];
				$surface['existing'] += $batch['existing'];
				$surface['failed']   += (int) ( $batch['failed'] ?? 0 );
				$offset += $limit;
			} while ( $batch['imported'] > 0 || $batch['existing'] > 0 || ! empty( $batch['failed'] ) );
			$out[ $suffix ] = $surface;
		}
		return $out;
	}

	/** Rollback: delete ONLY adopted rows (origin='legacy'). */
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

	// ── Read helpers (v2-first with legacy fallback) ────────────────────────

	/**
	 * Preferred source table for a surface: the v2 mirror when it exists AND
	 * has rows (inheritance complete), otherwise the legacy source. This lets
	 * the dashboard/CLI work pre-migration (legacy) and post-migration (v2)
	 * without code changes.
	 */
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

	/** Read rows from the preferred source (mirror or legacy). */
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

	/** Count rows in the preferred source. */
	public static function count( string $suffix ): int
	{
		global $wpdb;
		$table = self::source_table( $suffix );
		if ( ! isset( $wpdb ) || $table === '' ) {
			return 0;
		}
		return self::count_table( $table );
	}

	/** Count rows in the preferred source within a date range (inclusive).
	 *  $date_col: fecha (p.ej. converted_at). Vacío = sin límite. */
	public static function count_between( string $suffix, string $date_col, string $from = '', string $to = '' ): int
	{
		global $wpdb;
		$table = self::source_table( $suffix );
		if ( ! isset( $wpdb ) || $table === '' ) {
			return 0;
		}
		$col = preg_replace( '/[^a-z_]/i', '', $date_col );
		$sql = 'SELECT COUNT(*) FROM ' . $table;
		$clauses = array();
		if ( $from !== '' ) {
			$clauses[] = $wpdb->prepare( $col . ' >= %s', $from . ' 00:00:00' );
		}
		if ( $to !== '' ) {
			$clauses[] = $wpdb->prepare( $col . ' <= %s', $to . ' 23:59:59' );
		}
		if ( $clauses ) {
			$sql .= ' WHERE ' . implode( ' AND ', $clauses );
		}
		return (int) $wpdb->get_var( $sql );
	}

	/** Group the preferred source by one column: key => count. */
	public static function group( string $suffix, string $column ): array
	{
		global $wpdb;
		$table = self::source_table( $suffix );
		if ( ! isset( $wpdb ) || $table === '' ) {
			return array();
		}
		$col  = preg_replace( '/[^a-z_]/i', '', $column );
		$rows = $wpdb->get_results(
			'SELECT ' . $col . ' AS k, COUNT(*) AS n FROM ' . $table . ' GROUP BY ' . $col . ' ORDER BY n DESC LIMIT 12',
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$key = (string) ( $r['k'] ?? '' );
			if ( $key === '' ) {
				$key = '(sin dato)';
			}
			$out[ $key ] = (int) $r['n'];
		}
		return $out;
	}

	/**
	 * Serie diaria unificada para el gráfico "Panorama": un solo punto por día
	 * con las 3 series (sesiones · páginas vistas · eventos). Lee de la fuente
	 * preferida (espejo v2-first, fallback legacy) agrupando por la columna de
	 * fecha de cada superficie, y SUMA las fuentes vivas del v2
	 * (estandar_core_pageviews y estandar_insights_events) para que los rangos
	 * recientes no queden vacíos (el espejo legacy dejó de capturar al retirarse
	 * el tracker v1). Devuelve etiquetas + series alineadas (ceros completados),
	 * listas para Chart.js.
	 *
	 * @param int $days  Ventana en días (7/14/30 típico).
	 * @return array{labels: string[], sessions: int[], pageviews: int[], events: int[]}
	 */
	public static function daily_series( int $days = 14 ): array
	{
		$to = function_exists( 'current_time' ) ? (string) current_time( 'Y-m-d' ) : gmdate( 'Y-m-d', time() );
		$from = gmdate( 'Y-m-d', strtotime( $to ) - ( ( max( 1, (int) $days ) - 1 ) * DAY_IN_SECONDS ) );
		return self::series_between( $from, $to );
	}

	/** Serie diaria entre dos fechas inclusivas (YYYY-MM-DD). 'all' usa el
	 *  mínimo real de todas las superficies (legacy + vivas). */
	public static function series_between( string $from, string $to = '' ): array
	{
		global $wpdb;
		$today = function_exists( 'current_time' ) ? (string) current_time( 'Y-m-d' ) : gmdate( 'Y-m-d', time() );
		$to = $to !== '' ? $to : $today;

		if ( $from === 'all' || $from === '' ) {
			$min = self::earliest_date();
			$from = $min !== '' ? $min : gmdate( 'Y-m-d', strtotime( $today ) - ( 29 * DAY_IN_SECONDS ) );
		}
		// Sanitize + clamp: máximo 730 puntos (dos años) para no disparar el gráfico.
		$from = preg_replace( '/[^0-9-]/', '', (string) $from );
		$to   = preg_replace( '/[^0-9-]/', '', (string) $to );
		if ( $from === '' || $to === '' || $from > $to ) {
			$to   = $today;
			$from = gmdate( 'Y-m-d', strtotime( $today ) - ( 13 * DAY_IN_SECONDS ) );
		}
		$span = ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS;
		if ( $span > 730 ) {
			$from = gmdate( 'Y-m-d', strtotime( $to ) - ( 729 * DAY_IN_SECONDS ) );
		}

		$labels = array();
		$series = array( 'sessions' => array(), 'pageviews' => array(), 'events' => array() );
		$cursor = strtotime( $from );
		$end    = strtotime( $to );
		while ( $cursor <= $end ) {
			$d = gmdate( 'Y-m-d', $cursor );
			$labels[] = $d;
			foreach ( array_keys( $series ) as $k ) {
				$series[ $k ][ $d ] = 0;
			}
			$cursor += DAY_IN_SECONDS;
		}

		if ( ! isset( $wpdb ) ) {
			return array( 'labels' => $labels, 'sessions' => array(), 'pageviews' => array(), 'events' => array() );
		}
		$since = $from . ' 00:00:00';
		$until = $to . ' 23:59:59';

		// Superficies y sus fuentes: espejo/legacy + vivas. Una superficie puede
		// tener varias fuentes (p.ej. pageviews = espejo legacy + core_pageviews).
		$surfaces = array(
			'sessions' => array( 'col' => 'first_seen' ),
			'pageviews'=> array( 'col' => 'viewed_at' ),
			'events'   => array( 'col' => 'occurred_at' ),
		);
		$tables = array(
			'sessions' => array( self::source_table( 'sessions' ), self::live_table( 'estandar_core_sessions' ) ),
			'pageviews'=> array( self::source_table( 'pageviews' ), self::live_table( 'estandar_core_pageviews' ) ),
			'events'   => array( self::source_table( 'events' ), self::live_table( 'estandar_insights_events' ) ),
		);

		foreach ( $surfaces as $key => $cfg ) {
			$col = preg_replace( '/[^a-z_]/i', '', $cfg['col'] );
			foreach ( (array) $tables[ $key ] as $table ) {
				if ( $table === '' ) {
					continue;
				}
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT DATE(' . $col . ') AS d, COUNT(*) AS n FROM ' . $table . ' WHERE ' . $col . ' >= %s AND ' . $col . ' <= %s GROUP BY DATE(' . $col . ') ORDER BY d ASC',
						$since,
						$until
					),
					ARRAY_A
				);
				foreach ( (array) $rows as $r ) {
					$d = (string) ( $r['d'] ?? '' );
					if ( $d !== '' && isset( $series[ $key ][ $d ] ) ) {
						$series[ $key ][ $d ] += (int) $r['n'];
					}
				}
			}
		}

		// Relleno externo (hosting, p. ej. SiteGround): solo días en los que la
		// fuente real quedó en 0 (hueco histórico sin tracker). Nunca pisa datos reales.
		if ( class_exists( 'Estandar\Insights\ExternalDaily' ) ) {
			foreach ( array( 'sessions', 'pageviews' ) as $surface ) {
				if ( ! isset( $series[ $surface ] ) ) {
					continue;
				}
				$ext = ExternalDaily::values( $surface, $from, $to );
				foreach ( array_keys( $series[ $surface ] ) as $d ) {
					if ( (int) $series[ $surface ][ $d ] === 0 && isset( $ext[ $d ] ) ) {
						$series[ $surface ][ $d ] = (int) $ext[ $d ];
					}
				}
			}
		}

		return array(
			'labels'    => $labels,
			'sessions'  => array_values( $series['sessions'] ),
			'pageviews' => array_values( $series['pageviews'] ),
			'events'    => array_values( $series['events'] ),
		);
	}

	/** Tabla "viva" del v2 si existe (prefijo aplicado) o vacío. No es espejo. */
	private static function live_table( string $name ): string
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return '';
		}
		$full = $wpdb->prefix . $name;
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) );
		return $found ? $full : '';
	}

	/** Fecha más antigua (YYYY-MM-DD) entre todas las superficies y sus fuentes
	 *  (espejo/legacy + vivas); vacío si no hay ninguna tabla con datos. */
	public static function earliest_date(): string
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return '';
		}
		$candidates = array(
			array( 'table' => self::source_table( 'sessions' ),  'col' => 'first_seen' ),
			array( 'table' => self::source_table( 'pageviews' ), 'col' => 'viewed_at' ),
			array( 'table' => self::source_table( 'events' ),    'col' => 'occurred_at' ),
			array( 'table' => self::live_table( 'estandar_core_sessions' ), 'col' => 'first_seen' ),
			array( 'table' => self::live_table( 'estandar_core_pageviews' ), 'col' => 'viewed_at' ),
			array( 'table' => self::live_table( 'estandar_insights_events' ), 'col' => 'occurred_at' ),
		);
		$min = '';
		foreach ( $candidates as $c ) {
			if ( $c['table'] === '' ) {
				continue;
			}
			$col = preg_replace( '/[^a-z_]/i', '', $c['col'] );
			$d = (string) $wpdb->get_var( 'SELECT MIN(' . $col . ') FROM ' . $c['table'] );
			if ( $d !== '' && $d !== null && ( $min === '' || $d < $min ) ) {
				$min = $d;
			}
		}
		return $min !== '' ? substr( $min, 0, 10 ) : '';
	}
}
