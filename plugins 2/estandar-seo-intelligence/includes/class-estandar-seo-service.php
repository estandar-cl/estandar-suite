<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Application service: orchestrates an adapter and the repository. */
final class Estandar_SEO_Service {
    /**
     * Search Console dates are commercial calendar dates, never UTC-offset math.
     * The three-day delay is a data-finality policy, not a timezone offset.
     */
    public static function sync_stable_day(): array { return self::run_scheduled( 'cron' ); }

    public static function stable_query_date( ?DateTimeInterface $now = null ): string {
        if ( ! $now ) { $now = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ); }
        $instant = new DateTimeImmutable( '@' . $now->getTimestamp() );
        return $instant->setTimezone( self::commercial_timezone() )->modify( '-' . self::stability_delay_days() . ' days' )->format( 'Y-m-d' );
    }

    public static function commercial_timezone(): DateTimeZone {
        $configured = apply_filters( 'estandar_seo_commercial_timezone', get_option( 'estandar_seo_commercial_timezone', 'America/Santiago' ) );
        try { return new DateTimeZone( (string) $configured ); }
        catch ( Exception $e ) { return new DateTimeZone( 'America/Santiago' ); }
    }
    public static function stability_delay_days(): int { return max( 1, min( 30, absint( apply_filters( 'estandar_seo_stability_delay_days', get_option( 'estandar_seo_stability_delay_days', 3 ) ) ) ) ); }
    public static function enabled(): bool { return get_option( 'estandar_seo_enabled', '0' ) === '1'; }

    /** Cron/retry entrypoint. Locking prevents overlapping WP-Cron executions. */
    public static function run_scheduled( string $trigger = 'cron' ): array {
        if ( ! self::enabled() ) { return array( 'success' => false, 'message' => 'SEO Intelligence is disabled.', 'rows_received' => 0, 'rows_inserted' => 0, 'rows_skipped' => 0 ); }
        if ( ! add_option( 'estandar_seo_sync_lock', gmdate( 'Y-m-d H:i:s' ), '', 'no' ) ) { return array( 'success' => false, 'message' => 'A SEO synchronization is already running.', 'rows_received' => 0, 'rows_inserted' => 0, 'rows_skipped' => 0 ); }
        try { return self::execute( self::stable_query_date(), $trigger ); } finally { delete_option( 'estandar_seo_sync_lock' ); }
    }

    /** Manual and backfill execution. The caller must authorize before invoking. */
    public static function execute( string $date, string $trigger = 'manual', $adapter = null ): array {
        $run_id = Estandar_SEO_Repository::start_run( $trigger, $date );
        try { $result = self::sync_date( $date, $adapter ); }
        catch ( Throwable $e ) { $result = array( 'success' => false, 'message' => 'Synchronization failed: ' . $e->getMessage(), 'rows_received' => 0, 'rows_inserted' => 0, 'rows_skipped' => 0 ); }
        $next = null;
        if ( empty( $result['success'] ) && ! empty( $result['temporary'] ) ) { $next = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ); if ( ! wp_next_scheduled( 'estandar_seo_intelligence_retry' ) ) { wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'estandar_seo_intelligence_retry' ); } }
        Estandar_SEO_Repository::finish_run( $run_id, $result, $next ); return $result;
    }
    public static function sync_date( string $date, $adapter = null ): array {
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) { return array( 'success' => false, 'temporary' => false, 'message' => 'Invalid date.', 'rows_received' => 0, 'rows_inserted' => 0, 'rows_skipped' => 0 ); }
        $adapter = $adapter ?: new Estandar_Search_Console_Adapter(); $pages = $adapter->pages( $date ); if ( empty( $pages['success'] ) ) { return array( 'success' => false, 'temporary' => ! empty( $pages['temporary'] ), 'message' => (string) ( $pages['message'] ?? 'Search Console failed.' ), 'rows_received' => 0, 'rows_inserted' => 0, 'rows_skipped' => 0 ); }
        $page_count = 0; $query_count = 0; $received = count( (array) $pages['rows'] ); $inserted = 0;
        foreach ( (array) $pages['rows'] as $page ) { $source_url = (string) ( $page['page'] ?? '' ); $url = Estandar_SEO_URL::normalize( $source_url ); $landing_id = Estandar_SEO_Repository::ensure_landing( $url ); if ( ! $landing_id ) { continue; } if ( Estandar_SEO_Repository::insert_landing_metrics( $landing_id, $date, $page ) ) { $inserted++; }
            // Search Console's page filter must receive its original dimension key;
            // normalized URLs are only the internal identity used by consumers.
            $queries = $adapter->queries_for_page( $date, $source_url );
            // A partial page/query sync is observable and retryable. Existing
            // inserts remain immutable; retry fills only the missing query rows.
            if ( empty( $queries['success'] ) ) { return array( 'success' => false, 'temporary' => ! empty( $queries['temporary'] ), 'message' => (string) ( $queries['message'] ?? 'Search Console query request failed.' ), 'pages' => $page_count, 'queries' => $query_count, 'rows_received' => $received, 'rows_inserted' => $inserted, 'rows_skipped' => max( 0, $received - $inserted ) ); }
            $received += count( (array) $queries['rows'] );
            foreach ( (array) $queries['rows'] as $query ) { if ( Estandar_SEO_Repository::insert_query( $landing_id, $date, array_merge( $query, array( 'query' => $query['query'] ?? '' ) ) ) ) { $inserted++; } $query_count++; }
            Estandar_SEO_Repository::mark_synced( $landing_id ); $page_count++;
        }
        return array( 'success' => true, 'temporary' => false, 'message' => 'OK', 'pages' => $page_count, 'queries' => $query_count, 'rows_received' => $received, 'rows_inserted' => $inserted, 'rows_skipped' => max( 0, $received - $inserted ) );
    }
    public static function schedule(): void { if ( ! wp_next_scheduled( 'estandar_seo_intelligence_daily_sync' ) ) { wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'estandar_seo_intelligence_daily_sync' ); } }
    public static function unschedule(): void { $timestamp = wp_next_scheduled( 'estandar_seo_intelligence_daily_sync' ); if ( $timestamp ) { wp_unschedule_event( $timestamp, 'estandar_seo_intelligence_daily_sync' ); } }
}
