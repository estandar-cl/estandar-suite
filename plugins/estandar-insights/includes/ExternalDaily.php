<?php

namespace Estandar\Insights;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ExternalDaily — relleno de días sin registro con métricas diarias externas.
 *
 * Problema: al retirarse el tracker legacy (24-ago-2026) quedaron días sin
 * sesiones/pageviews reales hasta que la fuente viva v2 arrancó (beacon). Para
 * que el Panorama no muestre huecos en cero, se pueden importar métricas
 * diarias del hosting (p. ej. "visitantes únicos" de las estadísticas de
 * SiteGround, que se calculan de los registros del servidor) en esta tabla.
 *
 * Regla de honestidad: Analytics::series_between usa estos valores SOLO para
 * días en los que la fuente real (espejo + viva) es 0. Nunca pisa datos reales
 * y es reversible (borrar filas = volver a cero).
 *
 * Carga automática: en el boot se busca un seed por host en
 * `includes/data/external-sessions/{host}.json` y se importa UNA vez (opción
 * guardada por archivo) → "carga solo con desplegar". También hay CLI
 * (`wp estandar-suite insights external import|status|purge`).
 */
class ExternalDaily
{
	const TABLE   = 'estandar_insights_external_daily';
	const OPTION  = 'estandar_suite_insights_external_daily_schema_version';
	const VERSION = '1.0.0';
	const OPTION_SEEDED = 'estandar_suite_insights_external_seeded_files';

	/** Métricas soportadas (misma semántica que las superficies del Panorama). */
	const METRICS = array( 'sessions', 'pageviews' );

	public static function table(): string
	{
		global $wpdb;
		return ( isset( $wpdb ) && ! empty( $wpdb->prefix ) ? $wpdb->prefix : 'wp_' ) . self::TABLE;
	}

	public static function activate_schema(): void
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$table   = self::table();
		dbDelta( "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			metric varchar(20) NOT NULL DEFAULT 'sessions',
			agg_date date NOT NULL,
			value int(10) unsigned NOT NULL DEFAULT 0,
			origin varchar(40) NOT NULL DEFAULT 'siteground',
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY metric_date (metric, agg_date),
			KEY metric (metric),
			KEY agg_date (agg_date)
		) {$charset};" );
		update_option( self::OPTION, self::VERSION, false );
	}

	public static function maybe_migrate_schema(): void
	{
		if ( get_option( self::OPTION, '' ) !== self::VERSION ) {
			self::activate_schema();
		}
	}

	/** PURE: mapa date→value para una métrica en el rango [from,to]. */
	public static function values( string $metric, string $from, string $to ): array
	{
		global $wpdb;
		$metric = in_array( $metric, self::METRICS, true ) ? $metric : 'sessions';
		if ( ! isset( $wpdb ) || ! self::table_present() ) {
			return array();
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT agg_date, value FROM ' . self::table() . ' WHERE metric = %s AND agg_date >= %s AND agg_date <= %s',
				$metric, $from, $to
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[ (string) $r['agg_date'] ] = (int) $r['value'];
		}
		return $out;
	}

	private static function table_present(): bool
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return false;
		}
		$wpdb->get_results( 'SELECT 1 FROM ' . self::table() . ' LIMIT 1' );
		return empty( $wpdb->last_error );
	}

	/** Inserta o actualiza un valor diario (idempotente por metric+date). */
	public static function set( string $metric, string $date, int $value, string $origin = 'siteground' ): bool
	{
		global $wpdb;
		$metric = in_array( $metric, self::METRICS, true ) ? $metric : 'sessions';
		if ( ! isset( $wpdb ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || $value < 0 ) {
			return false;
		}
		$origin = substr( sanitize_key( $origin ), 0, 40 );
		$now    = current_time( 'mysql' );
		$exists = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . self::table() . ' WHERE metric = %s AND agg_date = %s LIMIT 1',
			$metric, $date
		) );
		if ( $exists ) {
			return false !== $wpdb->update( self::table(), array(
				'value'      => $value,
				'origin'     => $origin,
				'updated_at' => $now,
			), array( 'id' => $exists ), array( '%d', '%s', '%s' ), array( '%d' ) );
		}
		return false !== $wpdb->insert( self::table(), array(
			'metric'     => $metric,
			'agg_date'   => $date,
			'value'      => $value,
			'origin'     => $origin,
			'updated_at' => $now,
		), array( '%s', '%s', '%d', '%s', '%s' ) );
	}

	/**
	 * Importa un dataset { metric: { "YYYY-MM-DD": value } }.
	 * Devuelve [imported, updated].
	 */
	public static function import_dataset( array $data, string $origin = 'siteground' ): array
	{
		$c = array( 'imported' => 0, 'updated' => 0 );
		foreach ( $data as $metric => $days ) {
			if ( ! in_array( $metric, self::METRICS, true ) || ! is_array( $days ) ) {
				continue;
			}
			foreach ( $days as $date => $value ) {
				$value = (int) $value;
				if ( $value <= 0 ) {
					continue;
				}
				$date = (string) $date;
				if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
					continue;
				}
				$exists = self::has( $metric, $date );
				if ( self::set( $metric, $date, $value, $origin ) ) {
					if ( $exists ) {
						$c['updated']++;
					} else {
						$c['imported']++;
					}
				}
			}
		}
		return $c;
	}

	/** True si ya existe un valor externo para metric+date. */
	public static function has( string $metric, string $date ): bool
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || ! self::table_present() ) {
			return false;
		}
		return (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . self::table() . ' WHERE metric = %s AND agg_date = %s LIMIT 1',
			$metric, $date
		) ) > 0;
	}

	/** Purga valores de una métrica (todas si $metric vacío). */
	public static function purge( string $metric = '' ): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || ! self::table_present() ) {
			return 0;
		}
		if ( $metric !== '' && in_array( $metric, self::METRICS, true ) ) {
			return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE metric = %s', $metric ) );
		}
		return (int) $wpdb->query( 'DELETE FROM ' . self::table() );
	}

	/**
	 * Ruta del directorio de seeds por host. **Configurable, NO embebido**:
	 * por defecto vacío (auto-seed deshabilitado). Un operador apunta a su
	 * directorio con el filtro `estandar_insights_external_seed_dir` o importa
	 * por CLI (`wp estandar-suite insights external import --file=…`).
	 * Esto evita que datos de hosting de un cliente viajen en el plugin genérico.
	 */
	public static function seed_dir(): string
	{
		$dir = function_exists( 'apply_filters' )
			? (string) apply_filters( 'estandar_insights_external_seed_dir', '' )
			: '';
		return $dir !== '' ? rtrim( $dir, '/\\' ) . '/' : '';
	}

	/** Host actual (p. ej. acacios.cl). */
	public static function current_host(): string
	{
		$u = function_exists( 'home_url' ) ? home_url() : '';
		if ( function_exists( 'wp_parse_url' ) && $u !== '' ) {
			$h = wp_parse_url( $u, PHP_URL_HOST );
			return is_string( $h ) ? strtolower( $h ) : '';
		}
		return '';
	}

	/**
	 * Importa el seed por host UNA sola vez (para "cargar con el deploy").
	 * Opción guardada: nombre de archivos ya sembrados (por si se agregan más).
	 */
	public static function maybe_seed_host(): array
	{
		if ( ! self::table_present() ) {
			self::activate_schema();
		}
		$host = self::current_host();
		if ( $host === '' ) {
			return array( 'skipped' => 'no-host' );
		}
		$dir = self::seed_dir();
		if ( $dir === '' ) {
			return array( 'skipped' => 'seed-disabled', 'host' => $host );
		}
		$file = $dir . $host . '.json';
		if ( ! file_exists( $file ) ) {
			return array( 'skipped' => 'no-file', 'host' => $host );
		}
		$done = (array) get_option( self::OPTION_SEEDED, array() );
		if ( in_array( $host, $done, true ) ) {
			return array( 'skipped' => 'already-seeded', 'host' => $host );
		}
		$raw = file_get_contents( $file );
		$data = json_decode( (string) $raw, true );
		if ( ! is_array( $data ) || empty( $data ) ) {
			return array( 'error' => 'invalid-seed', 'host' => $host );
		}
		$c = self::import_dataset( $data, 'siteground' );
		$done[] = $host;
		update_option( self::OPTION_SEEDED, array_values( array_unique( $done ) ), false );
		return array( 'host' => $host, 'imported' => $c['imported'], 'updated' => $c['updated'] );
	}

	/** Importa desde un archivo JSON (para CLI). */
	public static function import_file( string $path, string $origin = 'siteground' ): array
	{
		if ( ! file_exists( $path ) ) {
			return array( 'error' => 'file-not-found', 'path' => $path );
		}
		$data = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $data ) || empty( $data ) ) {
			return array( 'error' => 'invalid-json' );
		}
		return self::import_dataset( $data, $origin );
	}

	/** Resumen por métrica: filas y rango. */
	public static function status(): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || ! self::table_present() ) {
			return array();
		}
		$out = array();
		foreach ( self::METRICS as $m ) {
			$row = $wpdb->get_row( $wpdb->prepare(
				'SELECT COUNT(*) AS n, MIN(agg_date) AS min_d, MAX(agg_date) AS max_d, SUM(value) AS total FROM ' . self::table() . ' WHERE metric = %s',
				$m
			), ARRAY_A );
			$out[ $m ] = array(
				'rows'  => (int) ( $row['n'] ?? 0 ),
				'min'   => (string) ( $row['min_d'] ?? '' ),
				'max'   => (string) ( $row['max_d'] ?? '' ),
				'total' => (int) ( $row['total'] ?? 0 ),
			);
		}
		return $out;
	}
}
