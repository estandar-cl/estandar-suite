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
		// WP-CLI NO admite un callable/Clase con subcomandos cuando hay acción
		// por defecto (una clase con __invoke se registra como comando simple).
		// Para convivir con `funnel` sin el fatal "can't have subcommands" se usa
		// un nombre HERMANO: `funnel-web`.
		\WP_CLI::add_command( 'estandar-suite insights funnel', array( __CLASS__, 'funnel' ) );
		\WP_CLI::add_command( 'estandar-suite insights funnel-web', array( __CLASS__, 'funnel_web' ) );
		\WP_CLI::add_command( 'estandar-suite insights visits', array( __CLASS__, 'visits' ) );
		\WP_CLI::add_command( 'estandar-suite insights events backfill-channel', array( __CLASS__, 'events_backfill_channel' ) );
		\WP_CLI::add_command( 'estandar-suite insights adoption import', array( __CLASS__, 'adoption_import' ) );
		\WP_CLI::add_command( 'estandar-suite insights adoption rollback', array( __CLASS__, 'adoption_rollback' ) );
		\WP_CLI::add_command( 'estandar-suite insights adoption status', array( __CLASS__, 'adoption_status' ) );
		\WP_CLI::add_command( 'estandar-suite insights seo adoption status', array( __CLASS__, 'seo_adoption_status' ) );
		\WP_CLI::add_command( 'estandar-suite insights seo adoption import', array( __CLASS__, 'seo_adoption_import' ) );
		\WP_CLI::add_command( 'estandar-suite insights seo adoption rollback', array( __CLASS__, 'seo_adoption_rollback' ) );
		\WP_CLI::add_command( 'estandar-suite insights analytics summary', array( __CLASS__, 'analytics_summary' ) );
		\WP_CLI::add_command( 'estandar-suite insights analytics traffic', array( __CLASS__, 'analytics_traffic' ) );
		\WP_CLI::add_command( 'estandar-suite insights analytics pages', array( __CLASS__, 'analytics_pages' ) );
		\WP_CLI::add_command( 'estandar-suite insights analytics trend', array( __CLASS__, 'analytics_trend' ) );
		\WP_CLI::add_command( 'estandar-suite insights analytics events', array( __CLASS__, 'analytics_events' ) );
		\WP_CLI::add_command( 'estandar-suite insights seo list', array( __CLASS__, 'seo_list' ) );
		\WP_CLI::add_command( 'estandar-suite insights seo summary', array( __CLASS__, 'seo_summary' ) );
		\WP_CLI::add_command( 'estandar-suite insights seo scan', array( __CLASS__, 'seo_scan' ) );
		\WP_CLI::add_command( 'estandar-suite insights seo audit', array( __CLASS__, 'seo_audit' ) );
		\WP_CLI::add_command( 'estandar-suite insights seo settings get', array( __CLASS__, 'seo_settings_get' ) );
		\WP_CLI::add_command( 'estandar-suite insights seo settings set', array( __CLASS__, 'seo_settings_set' ) );
										\WP_CLI::add_command( 'estandar-suite insights visibility get', array( __CLASS__, 'visibility_get' ) );
		\WP_CLI::add_command( 'estandar-suite insights visibility set', array( __CLASS__, 'visibility_set' ) );
		\WP_CLI::add_command( 'estandar-suite insights visibility reset', array( __CLASS__, 'visibility_reset' ) );
		\WP_CLI::add_command( 'estandar-suite insights external import', array( __CLASS__, 'external_import' ) );
		\WP_CLI::add_command( 'estandar-suite insights external status', array( __CLASS__, 'external_status' ) );
		\WP_CLI::add_command( 'estandar-suite insights external purge', array( __CLASS__, 'external_purge' ) );
	}

	/** Importa relleno externo diario desde un archivo JSON. --metric=… --file=… [--origin=…] */
	public static function external_import( array $args, array $assoc ): void
	{
		$file   = (string) ( $assoc['file'] ?? '' );
		$metric = (string) ( $assoc['metric'] ?? '' );
		$origin = (string) ( $assoc['origin'] ?? 'siteground' );
		if ( $metric !== '' ) {
			$metric = in_array( $metric, ExternalDaily::METRICS, true ) ? $metric : '';
		}
		if ( $file === '' ) {
			$host = ExternalDaily::current_host();
			$file = ExternalDaily::seed_dir() . $host . '.json';
			\WP_CLI::log( "Sin --file: uso seed por host {$file}" );
		}
		if ( $metric !== '' ) {
			$data = json_decode( (string) file_get_contents( $file ), true );
			$data = is_array( $data ) ? array( $metric => is_array( $data[ $metric ] ?? null ) ? $data[ $metric ] : array() ) : array();
			$c    = ExternalDaily::import_dataset( $data, $origin );
		} else {
			$c = ExternalDaily::import_file( $file, $origin );
		}
		if ( isset( $c['error'] ) ) {
			\WP_CLI::error( $c['error'] . ( isset( $c['path'] ) ? ' — ' . $c['path'] : '' ) );
		}
		\WP_CLI::success( sprintf( 'Externo importado: nuevos=%d actualizados=%d', $c['imported'] ?? 0, $c['updated'] ?? 0 ) );
	}

	public static function external_status( array $args, array $assoc ): void
	{
		$s = ExternalDaily::status();
		if ( empty( $s ) ) {
			\WP_CLI::log( 'Tabla externa sin datos o sin esquema.' );
			return;
		}
		foreach ( $s as $metric => $info ) {
			\WP_CLI::log( sprintf( '%-10s filas=%d total=%d rango=%s → %s', $metric, $info['rows'], $info['total'], $info['min'], $info['max'] ) );
		}
	}

	public static function external_purge( array $args, array $assoc ): void
	{
		$metric = (string) ( $assoc['metric'] ?? '' );
		$n      = ExternalDaily::purge( $metric );
		\WP_CLI::success( "Externo purgado: {$n} filas" . ( $metric !== '' ? " (métrica {$metric})" : '' ) );
	}

	public static function funnel( array $args, array $assoc ): void
	{
		$counts = array();
		if ( class_exists( 'Estandar\\Flow\\Pipeline' ) && method_exists( 'Estandar\\Flow\\Pipeline', 'counts_by_stage' ) ) {
			$counts = (array) \Estandar\Flow\Pipeline::counts_by_stage();
		}
		$f = Funnel::compute( $counts );
		foreach ( $f['stages'] as $stage => $info ) {
			\WP_CLI::log( sprintf( '%-14s %4d%s', $stage, $info['count'], $info['rate_from_prev'] !== null ? '  (' . $info['rate_from_prev'] . '%)' : '' ) );
		}
	}

	/** Embudo web visitas→leads (rango opcional --from/--to YYYY-MM-DD). */
	public static function funnel_web( array $args, array $assoc ): void
	{
		$from = (string) ( $assoc['from'] ?? '' );
		$to   = (string) ( $assoc['to'] ?? '' );
		list( $rfrom, $rto ) = WebFunnel::normalize_range( $from, $to );
		$daily   = WebFunnel::daily( $rfrom, $rto );
		$counts  = WebFunnel::totals( $daily );
		$funnel  = WebFunnel::compute( $counts );
		\WP_CLI::log( sprintf( 'Rango: %s → %s', $rfrom, $rto ) );
		foreach ( $funnel['stages'] as $stage => $info ) {
			$mark = $info['bottleneck'] ? '  ⚠ CUELLO' : '';
			\WP_CLI::log( sprintf( '%-10s %5d%s%s', $stage, $info['count'], $info['rate_from_prev'] !== null ? '  (' . $info['rate_from_prev'] . '%)' : '', $mark ) );
		}
		$conv = WebFunnel::conversion( $counts );
		\WP_CLI::log( 'Conversión sesiones→leads: ' . ( $conv !== null ? "{$conv}%" : 'sin sesiones' ) );
	}

	/** Visitas diarias equivalentes GA (sesiones/pageviews/leads) en rango. */
	public static function visits( array $args, array $assoc ): void
	{
		$from  = (string) ( $assoc['from'] ?? '' );
		$to    = (string) ( $assoc['to'] ?? '' );
		$daily = WebFunnel::daily( $from, $to );
		foreach ( (array) $daily['labels'] as $i => $d ) {
			\WP_CLI::log( sprintf( "%s\tS=%d\tPV=%d\tL=%d",
				$d,
				(int) ( $daily['sessions'][ $i ] ?? 0 ),
				(int) ( $daily['pageviews'][ $i ] ?? 0 ),
				(int) ( $daily['leads'][ $i ] ?? 0 )
			) );
		}
	}

	public static function adoption_import( array $args, array $assoc ): void
	{
		$dry = empty( $assoc['commit'] );
		$r   = Analytics::import( $dry, (int) ( $assoc['limit'] ?? 100 ) );
		foreach ( $r as $surface => $c ) {
			\WP_CLI::log( sprintf( '%-12s %s → importados=%d ya_existentes=%d fallidos=%d',
				$surface, $dry ? 'DRY-RUN' : 'IMPORTADO', $c['imported'], $c['existing'], (int) ( $c['failed'] ?? 0 ) ) );
		}
	}

	public static function adoption_status( array $args, array $assoc ): void
	{
		foreach ( Analytics::status() as $surface => $st ) {
			$legacy = $st['legacy_exists'] ? (string) $st['legacy'] : '—';
			\WP_CLI::log( sprintf( '%-12s legacy=%s adoptado=%d', $surface, $legacy, (int) $st['adopted'] ) );
		}
	}

	public static function adoption_rollback( array $args, array $assoc ): void
	{
		if ( empty( $assoc['confirm'] ) || $assoc['confirm'] !== 'yes' ) {
			\WP_CLI::error( 'Requiere --confirm=yes.' );
		}
		$n = Analytics::rollback();
		\WP_CLI::success( "Eventos adoptados eliminados: {$n}" );
	}

	public static function seo_adoption_status( array $args, array $assoc ): void
	{
		foreach ( SeoAdoption::status() as $surface => $st ) {
			$legacy = $st['legacy_exists'] ? (string) $st['legacy'] : '—';
			\WP_CLI::log( sprintf( '%-10s legacy=%s adoptado=%d', $surface, $legacy, (int) $st['adopted'] ) );
		}
	}

	public static function seo_adoption_import( array $args, array $assoc ): void
	{
		$dry = empty( $assoc['commit'] );
		foreach ( SeoAdoption::import( $dry, (int) ( $assoc['limit'] ?? 100 ) ) as $surface => $c ) {
			\WP_CLI::log( sprintf( '%-10s %s → importados=%d ya_existentes=%d fallidos=%d',
				$surface, $dry ? 'DRY-RUN' : 'IMPORTADO', $c['imported'], $c['existing'], (int) ( $c['failed'] ?? 0 ) ) );
		}
	}

	public static function seo_adoption_rollback( array $args, array $assoc ): void
	{
		if ( empty( $assoc['confirm'] ) || $assoc['confirm'] !== 'yes' ) {
			\WP_CLI::error( 'Requiere --confirm=yes.' );
		}
		\WP_CLI::success( "Filas SEO adoptadas eliminadas: " . SeoAdoption::rollback() );
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
		$sessions = Analytics::count( 'sessions' );
		$pv       = Analytics::count( 'pageviews' );
		$ev       = Analytics::count( 'events' );
		$conv     = Analytics::count( 'conversions' );
		\WP_CLI::log( sprintf( 'sesiones=%d pageviews=%d eventos=%d conversiones=%d', $sessions, $pv, $ev, $conv ) );
	}

	public static function analytics_traffic(): void
	{
		$by_source = Analytics::group( 'sessions', 'source' );
		$by_medium = Analytics::group( 'sessions', 'medium' );
		if ( ! $by_source && ! $by_medium ) {
			\WP_CLI::log( 'Sin datos de tráfico.' );
			return;
		}
		foreach ( $by_source as $k => $n ) { \WP_CLI::log( sprintf( 'fuente=%s : %d', $k, $n ) ); }
		foreach ( $by_medium as $k => $n ) { \WP_CLI::log( sprintf( 'medio=%s : %d', $k, $n ) ); }
	}

	public static function analytics_pages(): void
	{
		$top = Analytics::group( 'pageviews', 'page_url' );
		if ( ! $top ) {
			\WP_CLI::log( 'Sin páginas vistas.' );
			return;
		}
		foreach ( $top as $k => $n ) { \WP_CLI::log( sprintf( '%d\t%s', $n, $k ) ); }
	}

	public static function analytics_trend(): void
	{
		$rows = Analytics::rows( 'aggregates', 'ORDER BY aggregate_date DESC, metric_key ASC LIMIT 30' );
		if ( ! $rows ) {
			\WP_CLI::log( 'Sin agregados diarios.' );
			return;
		}
		foreach ( $rows as $r ) { \WP_CLI::log( sprintf( '%s\t%s\t%d', $r['aggregate_date'], $r['metric_key'], (int) $r['metric_value'] ) ); }
	}

	public static function analytics_events( array $args, array $assoc ): void
	{
		$rows = Analytics::rows( 'events', 'ORDER BY id DESC LIMIT 50' );
		if ( ! $rows ) {
			\WP_CLI::log( 'Sin eventos.' );
			return;
		}
		foreach ( $rows as $e ) {
			\WP_CLI::log( sprintf( '%s [%s] %s @ %s', $e['occurred_at'], $e['event_type'], $e['event_label'], $e['page_url'] ) );
		}
	}

	public static function seo_list(): void
	{
		$rows = SeoAdoption::rows( 'landings', 'ORDER BY id DESC LIMIT 50' );
		if ( ! $rows ) {
			\WP_CLI::log( 'Sin landings SEO.' );
			return;
		}
		foreach ( $rows as $l ) {
			\WP_CLI::log( sprintf( '#%d %s (sync %s)', (int) $l['id'], $l['normalized_url'] ? $l['normalized_url'] : $l['url'], $l['last_sync'] ) );
		}
	}

	public static function seo_summary(): void
	{
		\WP_CLI::log( sprintf( 'landings=%d metricas=%d queries=%d',
			SeoAdoption::count( 'landings' ),
			SeoAdoption::count( 'metrics' ),
			SeoAdoption::count( 'queries' )
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

	/** wp estandar-suite insights events backfill-channel [--limit=N] — completa el canal de señales antiguas. */
	public static function events_backfill_channel( array $args, array $assoc ): void
	{
		$n = \Estandar\Insights\Events::backfill_channels( (int) ( $assoc['limit'] ?? 500 ) );
		\WP_CLI::success( "Canales completados: {$n}" );
	}

	/** wp estandar-suite insights seo scan [--limit=N] — auditoría técnica del sitio (Tier 1). */
	public static function seo_scan( array $args, array $assoc ): void
	{
		$limit = (int) ( $assoc['limit'] ?? 50 );
		\WP_CLI::log( 'Auditando hasta ' . max( 1, min( 500, $limit ) ) . ' páginas…' );
		$r = \Estandar\Insights\SeoAudit::scan( $limit, ! empty( $assoc['dry-run'] ) );
		foreach ( $r['results'] as $row ) {
			\WP_CLI::log( sprintf( '  %3d  %s', (int) $row['score'], (string) $row['url'] ) );
			if ( ! empty( $row['issues'] ) ) {
				\WP_CLI::log( '        · ' . implode( ' · ', (array) $row['issues'] ) );
			}
		}
		foreach ( (array) ( $r['site'] ?? array() ) as $f => $chk ) {
			\WP_CLI::log( sprintf( '  %-12s %s', $f, ! empty( $chk['ok'] ) ? 'OK' : 'NO (' . (int) $chk['code'] . ')' ) );
		}
		\WP_CLI::success( "Auditoría SEO: {$r['pages']} páginas · score medio {$r['avg']}" );
	}

	/** wp estandar-suite insights seo audit — muestra la última auditoría guardada. */
	public static function seo_audit( array $args, array $assoc ): void
	{
		$rows = \Estandar\Insights\SeoAudit::rows( (int) ( $assoc['limit'] ?? 50 ) );
		if ( ! $rows ) {
			\WP_CLI::warning( 'Sin auditoría. Corre: wp estandar-suite insights seo scan' );
			return;
		}
		foreach ( $rows as $r ) {
			\WP_CLI::log( sprintf( '  %3d  %s', (int) $r['score'], (string) $r['url'] ) );
		}
		$last = \Estandar\Insights\SeoAudit::last_run();
		\WP_CLI::success( "Última: {$last['at']} · {$last['pages']} páginas · medio {$last['avg']}" );
	}

}

