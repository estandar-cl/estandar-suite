<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Stable internal read API. Consumers never call Search Console or tables. */
final class Estandar_SEO_Intelligence {
    public static function get_organic_conversion_summary( array $period, array $filters = array() ): array { return Estandar_SEO_Conversion_Analytics::get_organic_conversion_summary( $period, $filters ); }
    public static function get_landing_conversion_metrics( $url, array $period, array $filters = array() ): array { return Estandar_SEO_Conversion_Analytics::get_landing_conversion_metrics( $url, $period, $filters ); }
    public static function get_project_organic_performance( $project_id, array $period, array $filters = array() ): array { return Estandar_SEO_Conversion_Analytics::get_project_organic_performance( $project_id, $period, $filters ); }
    public static function get_query_landing_performance( $url, array $period, array $filters = array() ): array { return Estandar_SEO_Conversion_Analytics::get_query_landing_performance( $url, $period, $filters ); }
    public static function get_landing_metrics( $url ): array { return Estandar_SEO_Repository::landing_metrics( $url ); }
    public static function get_landing_queries( $url ): array { return Estandar_SEO_Repository::landing_queries( $url, '', 100 ); }
    public static function get_top_queries( $url, $limit = 10 ): array { return Estandar_SEO_Repository::landing_queries( $url, '', $limit ); }
    public static function get_queries_by_date( $url, $date ): array { return Estandar_SEO_Repository::landing_queries( $url, (string) $date, 100 ); }
    /** Stable read-only consumer contract over already persisted Search Console data. */
    public static function get_landing_organic_context( $url, $days = 28, $limit = 5 ): array {
        if ( ! Estandar_SEO_Service::enabled() ) { return self::context( 'disabled', $url ); }
        $cache_key = 'estandar_seo_context_' . md5( Estandar_SEO_URL::normalize( $url ) . '|' . (int) $days . '|' . (int) $limit );
        $cached = function_exists( 'wp_cache_get' ) ? wp_cache_get( $cache_key, 'estandar_seo' ) : false;
        if ( is_array( $cached ) ) { return $cached; }
        $data = Estandar_SEO_Repository::landing_context( $url, (int) $days, (int) $limit );
        if ( empty( $data['found'] ) ) { return self::context( 'no_data', $url, $data['normalized_landing_url'] ?? '' ); }
        $latest = (string) $data['latest_commercial_date']; $today = new DateTimeImmutable( 'now', Estandar_SEO_Service::commercial_timezone() ); $age = max( 0, (int) $today->diff( new DateTimeImmutable( $latest, Estandar_SEO_Service::commercial_timezone() ) )->format( '%r%a' ) * -1 );
        $stale = $age > Estandar_SEO_Service::stability_delay_days() + 2;
        $result = array( 'status' => $stale ? 'stale' : 'available', 'landing_url' => (string) $url, 'normalized_landing_url' => (string) $data['normalized_landing_url'], 'period' => array( 'from' => (string) ( $data['period']['period_from'] ?? '' ), 'to' => (string) ( $data['period']['period_to'] ?? '' ), 'timezone' => Estandar_SEO_Service::commercial_timezone()->getName() ), 'metrics' => array( 'clicks' => (int) ( $data['period']['clicks'] ?? 0 ), 'impressions' => (int) ( $data['period']['impressions'] ?? 0 ), 'ctr' => (float) ( $data['period']['ctr'] ?? 0 ), 'average_position' => (float) ( $data['period']['average_position'] ?? 0 ) ), 'top_queries' => array_values( $data['top_queries'] ), 'freshness' => array( 'latest_commercial_date' => $latest, 'age_days' => $age, 'stale' => $stale ), 'attribution' => array( 'source' => 'google_search_console', 'scope' => 'landing_aggregate', 'exact_query_known' => false ) );
        if ( function_exists( 'wp_cache_set' ) ) { wp_cache_set( $cache_key, $result, 'estandar_seo', 300 ); } return $result;
    }
    private static function context( string $status, $url, string $normalized = '' ): array { return array( 'status' => $status, 'landing_url' => (string) $url, 'normalized_landing_url' => $normalized ?: Estandar_SEO_URL::normalize( $url ), 'period' => array( 'from' => '', 'to' => '', 'timezone' => Estandar_SEO_Service::commercial_timezone()->getName() ), 'metrics' => array( 'clicks' => 0, 'impressions' => 0, 'ctr' => 0, 'average_position' => 0 ), 'top_queries' => array(), 'freshness' => array( 'latest_commercial_date' => '', 'age_days' => null, 'stale' => false ), 'attribution' => array( 'source' => 'google_search_console', 'scope' => 'landing_aggregate', 'exact_query_known' => false ) ); }
}
