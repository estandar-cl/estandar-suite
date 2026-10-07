<?php

namespace Estandar\Insights;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cli
{
	public static function init(): void
	{
		if ( ! class_exists( 'WP_CLI' ) ) {
			return;
		}
		\WP_CLI::add_command( 'estandar-suite insights funnel', array( __CLASS__, 'funnel' ) );
		\WP_CLI::add_command( 'estandar-suite insights retention', array( __CLASS__, 'retention' ) );
		\WP_CLI::add_command( 'estandar-suite insights adoption import', array( __CLASS__, 'adoption_import' ) );
		\WP_CLI::add_command( 'estandar-suite insights adoption rollback', array( __CLASS__, 'adoption_rollback' ) );
		\WP_CLI::add_command( 'estandar-suite insights analytics summary', array( __CLASS__, 'analytics_summary' ) );
		\WP_CLI::add_command( 'estandar-suite insights analytics traffic', array( __CLASS__, 'analytics_traffic' ) );
		\WP_CLI::add_command( 'estandar-suite insights analytics pages', array( __CLASS__, 'analytics_pages' ) );
		\WP_CLI::add_command( 'estandar-suite insights analytics trend', array( __CLASS__, 'analytics_trend' ) );
		\WP_CLI::add_command( 'estandar-suite insights analytics events', array( __CLASS__, 'analytics_events' ) );
		\WP_CLI::add_command( 'estandar-suite insights analytics settings get', array( __CLASS__, 'analytics_settings_get' ) );
		\WP_CLI::add_command( 'estandar-suite insights analytics settings set', array( __CLASS__, 'analytics_settings_set' ) );
		\WP_CLI::add_command( 'estandar-suite insights analytics settings set', array( __CLASS__, 'analytics_settings_set' ) );
		\WP_CLI::add_command( 'estandar-suite insights seo list', array( __CLASS__, 'seo_list' ) );
		\WP_CLI::add_command( 'estandar-suite insights seo summary', array( __CLASS__, 'seo_summary' ) );
		\WP_CLI::add_command( 'estandar-suite insights seo settings get', array( __CLASS__, 'seo_settings_get' ) );
		\WP_CLI::add_command( 'estandar-suite insights seo settings set', array( __CLASS__, 'seo_settings_set' ) );
		\WP_CLI::add_command( 'estandar-suite insights scanner list', array( __CLASS__, 'scanner_list' ) );
		\WP_CLI::add_command( 'estandar-suite insights scanner summary', array( __CLASS__, 'scanner_summary' ) );
		\WP_CLI::add_command( 'estandar-suite insights scanner settings get', array( __CLASS__, 'scanner_settings_get' ) );
		\WP_CLI::add_command( 'estandar-suite insights scanner settings set', array( __CLASS__, 'scanner_settings_set' ) );
		\WP_CLI::add_command( 'estandar-suite insights visibility get', array( __CLASS__, 'visibility_get' ) );
		\WP_CLI::add_command( 'estandar-suite insights visibility set', array( __CLASS__, 'visibility_set' ) );
		\WP_CLI::add_command( 'estandar-suite insights visibility reset', array( __CLASS__, 'visibility_reset' ) );
	}

	public static function funnel( array $args, array $assoc ): void
	{
		$counts = array();
		if ( class_exists( 'Estandar\\Flow\\Pipeline' ) ) {
			foreach ( \Estandar\Flow\Pipeline::list() as $row ) {
				$counts[ $row['stage'] ] = (int) ( $counts[ $row['stage'] ] ?? 0 ) + 1;
			}
		}
		$f = Funnel::compute( $counts );
		foreach ( $f['stages'] as $stage => $info ) {
			\WP_CLI::log( sprintf( '%-14s %4d%s', $stage, $info['count'], $info['rate_from_prev'] !== null ? '  (' . $info['rate_from_prev'] . '%)' : '' ) );
		}
	}

	public static function retention( array $args, array $assoc ): void
	{
		$days = array( '2026-08-01' => array( 0 => 5, 1 => 3, 2 => 2 ), '2026-08-02' => array( 0 => 4 ) );
		$r = Events::retention( $days );
		foreach ( $r as $day => $remaining ) {
			\WP_CLI::log( "$day → {$remaining}" );
		}
	}

	public static function adoption_import( array $args, array $assoc ): void
	{
		$dry = empty( $assoc['commit'] );
		$r   = Adoption::import( $dry, (int) ( $assoc['limit'] ?? 100 ) );
		\WP_CLI::log( ( $dry ? 'DRY-RUN' : 'IMPORTADO' ) . " → importados={$r['imported']} ya_existentes={$r['existing']}" );
	}

	public static function adoption_rollback( array $args, array $assoc ): void
	{
		if ( empty( $assoc['confirm'] ) || $assoc['confirm'] !== 'yes' ) {
			\WP_CLI::error( 'Requiere --confirm=yes.' );
		}
		$n = Adoption::rollback();
		\WP_CLI::success( "Eventos adoptados eliminados: {$n}" );
	}

	public static function visibility_get( array $args, array $assoc ): void
	{
		$map = \Estandar\Core\Admin::get_visibility( 'insights' );
		if ( empty( $map ) ) {
			\WP_CLI::log( 'Sin configuración guardada → todos los tabs/sub-tabs visibles.' );
			return;
		}
		foreach ( $map as $tab => $subs ) {
			\WP_CLI::log( sprintf( '%s: %s', $tab, $subs ? implode( ', ', $subs ) : '(ninguno)' ) );
		}
	}

	public static function visibility_set( array $args, array $assoc ): void
	{
		$tab  = isset( $args[0] ) ? sanitize_key( $args[0] ) : '';
		$subs = array_slice( $args, 1 );
		if ( $tab === '' || ! $subs ) {
			\WP_CLI::error( 'Uso: wp estandar-suite insights visibility set <tab> <sub1> [<sub2> ...]' );
		}
		$subs = array_map( 'sanitize_key', $subs );
		$map = \Estandar\Core\Admin::get_visibility( 'insights' );
		$map[ $tab ] = $subs;
		\Estandar\Core\Admin::save_visibility( 'insights', $map );
		\WP_CLI::success( sprintf( 'insights > %s: visibles = %s', $tab, implode( ', ', $subs ) ) );
	}

	public static function visibility_reset( array $args, array $assoc ): void
	{
		delete_option( \Estandar\Core\Admin::visibility_option( 'insights' ) );
		\WP_CLI::success( 'insights: visibilidad restaurada (todo visible).' );
	}

	public static function analytics_summary(): void
	{
		$sessions = \Estandar\Core\Admin::legacy_count( 'ea_sessions' );
		$pv       = \Estandar\Core\Admin::legacy_count( 'ea_pageviews' );
		$ev       = \Estandar\Core\Admin::legacy_count( 'ea_events' );
		$conv     = \Estandar\Core\Admin::legacy_count( 'ea_conversions' );
		\WP_CLI::log( sprintf( 'sesiones=%d pageviews=%d eventos=%d conversiones=%d', $sessions, $pv, $ev, $conv ) );
	}

	public static function analytics_traffic(): void
	{
		$by_source = \Estandar\Core\Admin::legacy_group( 'ea_sessions', 'source' );
		$by_medium = \Estandar\Core\Admin::legacy_group( 'ea_sessions', 'medium' );
		if ( ! $by_source && ! $by_medium ) {
			\WP_CLI::log( 'Sin datos de tráfico.' );
			return;
		}
		foreach ( $by_source as $k => $n ) { \WP_CLI::log( sprintf( 'fuente=%s : %d', $k, $n ) ); }
		foreach ( $by_medium as $k => $n ) { \WP_CLI::log( sprintf( 'medio=%s : %d', $k, $n ) ); }
	}

	public static function analytics_pages(): void
	{
		$top = \Estandar\Core\Admin::legacy_group( 'ea_pageviews', 'page_url' );
		if ( ! $top ) {
			\WP_CLI::log( 'Sin páginas vistas.' );
			return;
		}
		foreach ( $top as $k => $n ) { \WP_CLI::log( sprintf( '%d\t%s', $n, $k ) ); }
	}

	public static function analytics_trend(): void
	{
		$rows = \Estandar\Core\Admin::legacy_rows( 'ea_daily_aggregates', 'ORDER BY aggregate_date DESC, metric_key ASC LIMIT 30' );
		if ( ! $rows ) {
			\WP_CLI::log( 'Sin agregados diarios.' );
			return;
		}
		foreach ( $rows as $r ) { \WP_CLI::log( sprintf( '%s\t%s\t%d', $r['aggregate_date'], $r['metric_key'], (int) $r['metric_value'] ) ); }
	}

	public static function analytics_events( array $args, array $assoc ): void
	{
		$rows = \Estandar\Core\Admin::legacy_rows( 'ea_events', 'ORDER BY id DESC LIMIT 50' );
		if ( ! $rows ) {
			\WP_CLI::log( 'Sin eventos legados.' );
			return;
		}
		foreach ( $rows as $e ) {
			\WP_CLI::log( sprintf( '%s [%s] %s @ %s', $e['occurred_at'], $e['event_type'], $e['event_label'], $e['page_url'] ) );
		}
	}

	public static function analytics_settings_get(): void
	{
		\WP_CLI::log( 'Analytics settings:' );
		\Estandar\Core\Cli::feature_settings_show( 'estandar_suite_insights_analytics_settings', \Estandar\Insights\Admin::analytics_settings_fields() );
	}

	public static function analytics_settings_set( array $args, array $assoc ): void
	{
		\Estandar\Core\Cli::feature_settings_set( 'estandar_suite_insights_analytics_settings', \Estandar\Insights\Admin::analytics_settings_fields(), $assoc );
	}

	public static function seo_list(): void
	{
		$rows = \Estandar\Core\Admin::legacy_rows( 'es_seo_landings', 'ORDER BY id DESC LIMIT 50' );
		if ( ! $rows ) {
			\WP_CLI::log( 'Sin landings SEO legadas.' );
			return;
		}
		foreach ( $rows as $l ) {
			\WP_CLI::log( sprintf( '#%d %s (sync %s)', (int) $l['id'], $l['normalized_url'] ? $l['normalized_url'] : $l['url'], $l['last_sync'] ) );
		}
	}

	public static function seo_summary(): void
	{
		\WP_CLI::log( sprintf( 'landings=%d metricas=%d queries=%d',
			\Estandar\Core\Admin::legacy_count( 'es_seo_landings' ),
			\Estandar\Core\Admin::legacy_count( 'es_seo_landing_metrics' ),
			\Estandar\Core\Admin::legacy_count( 'es_seo_queries' )
		) );
	}

	public static function seo_settings_get(): void
	{
		\WP_CLI::log( 'SEO settings:' );
		\Estandar\Core\Cli::feature_settings_show( 'estandar_suite_insights_seo_settings', \Estandar\Insights\Admin::feature_fields()['seo'] );
	}

	public static function seo_settings_set( array $args, array $assoc ): void
	{
		\Estandar\Core\Cli::feature_settings_set( 'estandar_suite_insights_seo_settings', \Estandar\Insights\Admin::feature_fields()['seo'], $assoc );
	}

	public static function scanner_list(): void
	{
		$rows = \Estandar\Core\Admin::legacy_rows( 'estandar_scans', 'ORDER BY id DESC LIMIT 30' );
		if ( ! $rows ) {
			\WP_CLI::log( 'Sin escaneos legados.' );
			return;
		}
		foreach ( $rows as $s ) {
			\WP_CLI::log( sprintf( '#%d %s (score %s, %s)', (int) $s['id'], $s['site_url'], $s['score_total'], $s['scan_date'] ) );
		}
	}

	public static function scanner_summary(): void
	{
		\WP_CLI::log( sprintf( 'escaneos=%d cola=%d',
			\Estandar\Core\Admin::legacy_count( 'estandar_scans' ),
			\Estandar\Core\Admin::legacy_count( 'estandar_scan_queue' )
		) );
	}

	public static function scanner_settings_get(): void
	{
		\WP_CLI::log( 'Scanner settings:' );
		\Estandar\Core\Cli::feature_settings_show( 'estandar_suite_insights_scanner_settings', \Estandar\Insights\Admin::feature_fields()['scanner'] );
	}

	public static function scanner_settings_set( array $args, array $assoc ): void
	{
		\Estandar\Core\Cli::feature_settings_set( 'estandar_suite_insights_scanner_settings', \Estandar\Insights\Admin::feature_fields()['scanner'], $assoc );
	}
}
