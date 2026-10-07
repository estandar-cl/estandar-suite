<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Persistence boundary. Historical metric and query rows are insert-only. */
final class Estandar_SEO_Repository {
    private static function tables(): array {
        global $wpdb;
        return array( 'landings' => $wpdb->prefix . 'es_seo_landings', 'metrics' => $wpdb->prefix . 'es_seo_landing_metrics', 'queries' => $wpdb->prefix . 'es_seo_queries', 'runs' => $wpdb->prefix . 'es_seo_sync_runs' );
    }

    public static function ensure_landing( $url ): int {
        global $wpdb;
        $normalized = Estandar_SEO_URL::normalize( $url );
        if ( $normalized === '' ) { return 0; }
        $t = self::tables(); $hash = Estandar_SEO_URL::hash( $normalized );
        $id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t['landings']} WHERE normalized_url_hash = %s", $hash ) );
        if ( $id ) { return $id; }
        $wpdb->insert( $t['landings'], array( 'url' => (string) $url, 'normalized_url' => $normalized, 'normalized_url_hash' => $hash, 'created_at' => gmdate( 'Y-m-d H:i:s' ) ), array( '%s', '%s', '%s', '%s' ) );
        return (int) $wpdb->insert_id;
    }

    public static function insert_landing_metrics( int $landing_id, string $date, array $row ): bool {
        global $wpdb; if ( ! $landing_id || self::metric_exists( $landing_id, $date ) ) { return false; }
        $t = self::tables();
        return $wpdb->insert( $t['metrics'], self::metric_row( $landing_id, $date, $row ), array( '%d', '%s', '%d', '%d', '%f', '%f', '%s' ) ) !== false;
    }

    public static function insert_query( int $landing_id, string $date, array $row ): bool {
        global $wpdb; $query = trim( (string) ( $row['query'] ?? '' ) ); if ( ! $landing_id || $query === '' ) { return false; }
        $t = self::tables(); $hash = hash( 'sha256', $query );
        $exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t['queries']} WHERE landing_id = %d AND date = %s AND query_hash = %s", $landing_id, $date, $hash ) );
        if ( $exists ) { return false; }
        return $wpdb->insert( $t['queries'], array_merge( self::metric_row( $landing_id, $date, $row ), array( 'query_text' => $query, 'query_hash' => $hash ) ), array( '%d', '%s', '%d', '%d', '%f', '%f', '%s', '%s', '%s' ) ) !== false;
    }

    private static function metric_exists( int $landing_id, string $date ): bool {
        global $wpdb; $t = self::tables();
        return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t['metrics']} WHERE landing_id = %d AND date = %s", $landing_id, $date ) );
    }
    private static function metric_row( int $landing_id, string $date, array $row ): array {
        return array( 'landing_id' => $landing_id, 'date' => $date, 'clicks' => max( 0, (int) ( $row['clicks'] ?? 0 ) ), 'impressions' => max( 0, (int) ( $row['impressions'] ?? 0 ) ), 'ctr' => max( 0, (float) ( $row['ctr'] ?? 0 ) ), 'average_position' => max( 0, (float) ( $row['average_position'] ?? 0 ) ), 'created_at' => gmdate( 'Y-m-d H:i:s' ) );
    }
    public static function mark_synced( int $landing_id ): void { global $wpdb; $t = self::tables(); $wpdb->update( $t['landings'], array( 'last_sync' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $landing_id ), array( '%s' ), array( '%d' ) ); }

    public static function landing_metrics( $url, $date = '' ): array {
        global $wpdb; $id = self::landing_id( $url ); if ( ! $id ) { return array(); } $t = self::tables();
        $sql = "SELECT date, clicks, impressions, ctr, average_position FROM {$t['metrics']} WHERE landing_id = %d"; $args = array( $id );
        if ( $date !== '' ) { $sql .= ' AND date = %s'; $args[] = $date; }
        $sql .= ' ORDER BY date DESC'; return (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
    }
    public static function landing_queries( $url, $date = '', $limit = 100 ): array {
        global $wpdb; $id = self::landing_id( $url ); if ( ! $id ) { return array(); } $t = self::tables();
        $sql = "SELECT date, query_text AS query, clicks, impressions, ctr, average_position FROM {$t['queries']} WHERE landing_id = %d"; $args = array( $id );
        if ( $date !== '' ) { $sql .= ' AND date = %s'; $args[] = $date; }
        $sql .= ' ORDER BY date DESC, clicks DESC, impressions DESC LIMIT %d'; $args[] = max( 1, min( 25000, (int) $limit ) );
        return (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
    }
    /** Local-only aggregate used by consumers; no adapter or remote API is involved. */
    public static function landing_context( $url, int $days = 28, int $limit = 5 ): array {
        global $wpdb; $normalized = Estandar_SEO_URL::normalize( $url ); $id = self::landing_id( $url );
        if ( ! $id ) { return array( 'found' => false, 'normalized_landing_url' => $normalized ); }
        $t = self::tables(); $latest = (string) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(date) FROM {$t['metrics']} WHERE landing_id = %d", $id ) );
        if ( $latest === '' ) { return array( 'found' => false, 'normalized_landing_url' => $normalized ); }
        $from = gmdate( 'Y-m-d', strtotime( $latest . ' -' . max( 1, min( 365, $days ) - 1 ) . ' days' ) );
        $summary = (array) $wpdb->get_row( $wpdb->prepare( "SELECT MIN(date) AS period_from, MAX(date) AS period_to, SUM(clicks) AS clicks, SUM(impressions) AS impressions, CASE WHEN SUM(impressions) > 0 THEN SUM(ctr * impressions) / SUM(impressions) ELSE 0 END AS ctr, CASE WHEN SUM(impressions) > 0 THEN SUM(average_position * impressions) / SUM(impressions) ELSE AVG(average_position) END AS average_position FROM {$t['metrics']} WHERE landing_id = %d AND date >= %s AND date <= %s", $id, $from, $latest ), ARRAY_A );
        $queries = (array) $wpdb->get_results( $wpdb->prepare( "SELECT query_text AS query, SUM(clicks) AS clicks, SUM(impressions) AS impressions, CASE WHEN SUM(impressions) > 0 THEN SUM(ctr * impressions) / SUM(impressions) ELSE 0 END AS ctr, CASE WHEN SUM(impressions) > 0 THEN SUM(average_position * impressions) / SUM(impressions) ELSE AVG(average_position) END AS average_position FROM {$t['queries']} WHERE landing_id = %d AND date >= %s AND date <= %s GROUP BY query_hash, query_text ORDER BY clicks DESC, impressions DESC, query_text ASC LIMIT %d", $id, $from, $latest, max( 1, min( 20, $limit ) ) ), ARRAY_A );
        return array( 'found' => true, 'normalized_landing_url' => $normalized, 'latest_commercial_date' => $latest, 'period' => $summary, 'top_queries' => $queries );
    }
    /** Bounded local aggregate for the Phase 4 read model. */
    public static function conversion_rows( array $period, array $filters = array(), bool $queries = false ): array {
        global $wpdb; $t = self::tables(); $from = (string) ( $period['from'] ?? '' ); $to = (string) ( $period['to'] ?? '' );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) { return array(); }
        $table = $queries ? $t['queries'] : $t['metrics']; $query_column = $queries ? ', q.query_text AS query' : ''; $alias = $queries ? 'q' : 'm';
        $sql = "SELECT l.normalized_url AS landing_url{$query_column}, MIN({$alias}.date) AS period_from, MAX({$alias}.date) AS period_to, SUM({$alias}.clicks) AS clicks, SUM({$alias}.impressions) AS impressions, CASE WHEN SUM({$alias}.impressions)>0 THEN SUM({$alias}.clicks)/SUM({$alias}.impressions) ELSE 0 END AS ctr, CASE WHEN SUM({$alias}.impressions)>0 THEN SUM({$alias}.average_position*{$alias}.impressions)/SUM({$alias}.impressions) ELSE 0 END AS average_position FROM {$table} {$alias} INNER JOIN {$t['landings']} l ON l.id={$alias}.landing_id WHERE {$alias}.date >= %s AND {$alias}.date <= %s";
        $args = array( $from, $to );
        if ( ! empty( $filters['landing'] ) ) { $sql .= ' AND l.normalized_url_hash = %s'; $args[] = Estandar_SEO_URL::hash( Estandar_SEO_URL::normalize( $filters['landing'] ) ); }
        $sql .= $queries ? ' GROUP BY l.id, q.query_hash, q.query_text' : ' GROUP BY l.id';
        $sql .= ' ORDER BY clicks DESC, impressions DESC LIMIT %d'; $args[] = $queries ? 500 : 1000;
        return (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
    }
    private static function landing_id( $url ): int { global $wpdb; $normalized = Estandar_SEO_URL::normalize( $url ); if ( $normalized === '' ) { return 0; } $t = self::tables(); return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t['landings']} WHERE normalized_url_hash = %s", Estandar_SEO_URL::hash( $normalized ) ) ); }

    public static function start_run( string $trigger, $commercial_date = null ): int {
        global $wpdb; $t = self::tables(); $now = gmdate( 'Y-m-d H:i:s' );
        $wpdb->insert( $t['runs'], array( 'run_uuid' => wp_generate_uuid4(), 'trigger_type' => $trigger, 'commercial_date' => $commercial_date ?: null, 'status' => 'running', 'started_at' => $now, 'created_at' => $now ), array( '%s', '%s', '%s', '%s', '%s', '%s' ) );
        return (int) $wpdb->insert_id;
    }
    public static function finish_run( int $id, array $result, $next_attempt_at = null ): void {
        global $wpdb; if ( ! $id ) { return; } $t = self::tables(); $success = ! empty( $result['success'] );
        $wpdb->update( $t['runs'], array( 'status' => $success ? 'succeeded' : 'failed', 'finished_at' => gmdate( 'Y-m-d H:i:s' ), 'rows_received' => (int) ( $result['rows_received'] ?? 0 ), 'rows_inserted' => (int) ( $result['rows_inserted'] ?? 0 ), 'rows_skipped' => (int) ( $result['rows_skipped'] ?? 0 ), 'error_message' => $success ? '' : self::safe_error( (string) ( $result['message'] ?? 'Unknown error.' ) ), 'next_attempt_at' => $next_attempt_at ), array( 'id' => $id ), array( '%s', '%s', '%d', '%d', '%d', '%s', '%s' ), array( '%d' ) );
    }
    public static function recent_runs( int $limit = 10 ): array { global $wpdb; $t = self::tables(); return (array) $wpdb->get_results( $wpdb->prepare( "SELECT trigger_type, commercial_date, status, started_at, finished_at, rows_received, rows_inserted, rows_skipped, error_message, next_attempt_at FROM {$t['runs']} ORDER BY id DESC LIMIT %d", max( 1, min( 50, $limit ) ) ), ARRAY_A ); }
    public static function latest_run(): array { $rows = self::recent_runs( 1 ); return $rows[0] ?? array(); }
    private static function safe_error( string $message ): string { $message = preg_replace( '/(client_secret|refresh_token|access_token|authorization)\\s*[:=]\\s*[^\\s]+/i', '$1=[redacted]', $message ); return substr( trim( (string) $message ), 0, 1000 ); }
}
