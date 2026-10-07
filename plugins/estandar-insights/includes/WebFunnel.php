<?php

namespace Estandar\Insights;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Web funnel — sesiones → pageviews → leads (el equivalente GA de visitas + la
 * captura de leads de estandar-capture, unidos). NO toca el funnel comercial
 * (Funnel.php, dominio estandar-flow): este es tráfico web puro.
 *
 * Lectura de visitas REUTILIZA Analytics::series_between (misma fuente que el
 * Panorama: espejos legacy + tablas vivas estandar_core_sessions/pageviews +
 * relleno ExternalDaily), de modo que el embudo web nunca diverge del Panorama.
 * Leads lee directo de {$prefix}estandar_capture_submissions (fuente real de
 * capture), sin pasar por eventos: mismo criterio que AdsDataManager (no-spam,
 * status activo) para no contar doble ni inventar conversiones.
 *
 * Semántica de cuello (portada de v1 estandar-analytics admin/funnel.php):
 * tasa = etapa / etapa-anterior; se marca cuello cuando la tasa < 30%.
 */
class WebFunnel
{
	const STAGE_ORDER   = array( 'SESIONES', 'PAGEVIEWS', 'LEADS' );
	const TIERS         = array( 'SESIONES' => 'site', 'PAGEVIEWS' => 'site', 'LEADS' => 'lead' );
	const BOTTLENECK_PCT = 30;
	const LEADS_TABLE   = 'estandar_capture_submissions';
	const DEFAULT_DAYS  = 30;
	const MAX_POINTS    = 730;

	/** PURE: alinear un rango YYYY-MM-DD con saneo y clamp (patrón series_between). */
	public static function normalize_range( string $from, string $to = '' ): array
	{
		$today = function_exists( 'current_time' ) ? (string) current_time( 'Y-m-d' ) : gmdate( 'Y-m-d', time() );
		$to   = $to !== '' ? preg_replace( '/[^0-9-]/', '', $to ) : $today;
		$from = preg_replace( '/[^0-9-]/', '', $from );
		if ( $from === '' || $to === '' || $from > $to ) {
			$to   = $today;
			$from = gmdate( 'Y-m-d', strtotime( $today ) - ( ( self::DEFAULT_DAYS - 1 ) * DAY_IN_SECONDS ) );
		}
		if ( $from !== '' ) {
			$span = ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS;
			if ( $span > self::MAX_POINTS - 1 ) {
				$from = gmdate( 'Y-m-d', strtotime( $to ) - ( ( self::MAX_POINTS - 1 ) * DAY_IN_SECONDS ) );
			}
		}
		return array( $from, $to );
	}

	/** True cuando la tabla de submissions de capture existe en esta instalación. */
	private static function leads_table_present(): bool
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return false;
		}
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . self::LEADS_TABLE ) );
		return (bool) $found;
	}

	/**
	 * Serie diaria alineada (equivalent GA): sessions/pageviews/events vienen de
	 * Analytics::series_between (misma vista que el Panorama); leads se cuentan
	 * por día desde estandar_capture_submissions (no-spam, activos).
	 */
	public static function daily( string $from = '', string $to = '' ): array
	{
		list( $from, $to ) = self::normalize_range( $from, $to );
		$base   = Analytics::series_between( $from, $to );
		$labels = (array) ( $base['labels'] ?? array() );
		$out    = array(
			'labels'    => $labels,
			'sessions'  => (array) ( $base['sessions'] ?? array() ),
			'pageviews' => (array) ( $base['pageviews'] ?? array() ),
			'events'    => (array) ( $base['events'] ?? array() ),
			'leads'     => array(),
		);
		$out['leads'] = array_fill( 0, count( $labels ), 0 );
		if ( ! self::leads_table_present() ) {
			return $out;
		}
		global $wpdb;
		$table = $wpdb->prefix . self::LEADS_TABLE;
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE(created_at) AS d, COUNT(*) AS n
			 FROM {$table}
			 WHERE created_at >= %s AND created_at <= %s
			   AND spam_honeypot = 0 AND status = 'active'
			 GROUP BY DATE(created_at) ORDER BY d ASC",
			$from . ' 00:00:00',
			$to . ' 23:59:59'
		), ARRAY_A );
		$index = array_flip( $labels );
		foreach ( (array) $rows as $r ) {
			$d = (string) ( $r['d'] ?? '' );
			if ( $d !== '' && isset( $index[ $d ] ) ) {
				$out['leads'][ $index[ $d ] ] = (int) $r['n'];
			}
		}
		return $out;
	}

	/** PURE: totales por superficie desde una serie alineada. */
	public static function totals( array $daily ): array
	{
		return array(
			'sessions'  => (int) array_sum( (array) ( $daily['sessions'] ?? array() ) ),
			'pageviews' => (int) array_sum( (array) ( $daily['pageviews'] ?? array() ) ),
			'leads'     => (int) array_sum( (array) ( $daily['leads'] ?? array() ) ),
		);
	}

	/** PURE: normaliza claves de conteo a las etapas canónicas (mayúscula).
	 *  `totals()` devuelve claves en inglés (sessions/pageviews/leads) y las
	 *  etapas canónicas son SESIONES/PAGEVIEWS/LEADS; 'sessions' → 'SESIONES'. */
	private static function normalize_counts( array $counts ): array
	{
		$out = array();
		foreach ( $counts as $k => $v ) {
			$u = strtoupper( (string) $k );
			if ( $u === 'SESSIONS' ) {
				$u = 'SESIONES';
			}
			$out[ $u ] = $v;
		}
		return $out;
	}

	/** PURE: filas del embudo con tasas etapa-anterior y marca de cuello.
	 *  Acepta claves de etapa canónicas o las de totals() (EN). */
	public static function compute( array $counts ): array
	{
		$counts = self::normalize_counts( $counts );
		$rows  = array();
		$prev  = null;
		$first = null;
		foreach ( self::STAGE_ORDER as $stage ) {
			$count = (int) ( $counts[ $stage ] ?? 0 );
			$rate  = null;
			if ( $prev !== null && $prev > 0 ) {
				$rate = round( ( $count / $prev ) * 100, 1 );
			}
			if ( $first === null ) {
				$first = $count;
			}
			$rows[ $stage ] = array(
				'count'      => $count,
				'rate_from_prev' => $rate,
				'bottleneck' => ( $rate !== null && $rate < self::BOTTLENECK_PCT ),
				'tier'       => self::TIERS[ $stage ] ?? '',
			);
			$prev = $count;
		}
		return array( 'stages' => $rows, 'first' => $first, 'total' => array_sum( array_values( $counts ) ) );
	}

	/** PURE: tasa global leads/sesiones (0 cuando no hay sesiones).
	 *  Acepta claves canónicas o las de totals() (EN). */
	public static function conversion( array $counts ): ?float
	{
		$counts   = self::normalize_counts( $counts );
		$sessions = (int) ( $counts['SESIONES'] ?? 0 );
		$leads    = (int) ( $counts['LEADS'] ?? 0 );
		if ( $sessions <= 0 ) {
			return null;
		}
		return round( ( $leads / $sessions ) * 100, 2 );
	}

	/** Composición completa del embudo web para un rango. */
	public static function snapshot( string $from = '', string $to = '' ): array
	{
		list( $from, $to ) = self::normalize_range( $from, $to );
		$daily   = self::daily( $from, $to );
		$counts  = self::totals( $daily );
		$funnel  = self::compute( $counts );
		$funnel['conversion_sessions_to_leads'] = self::conversion( $counts );
		return array(
			'range'   => array( $from, $to ),
			'counts'  => $counts,
			'funnel'  => $funnel,
			'daily'   => $daily,
		);
	}
}
