<?php
/** Run: php tests/run-operational-contract.php */
define( 'ABSPATH', __DIR__ . '/' ); define( 'HOUR_IN_SECONDS', 3600 );
$options = array( 'estandar_seo_enabled' => '1', 'estandar_seo_stability_delay_days' => 5, 'estandar_seo_commercial_timezone' => 'America/Santiago' ); $scheduled = array(); $lock = false;
function get_option( $key, $default = false ) { global $options; return $options[ $key ] ?? $default; }
function add_option( $key, $value ) { global $lock; if ( $key === 'estandar_seo_sync_lock' && $lock ) { return false; } if ( $key === 'estandar_seo_sync_lock' ) { $lock = true; } return true; }
function delete_option( $key ) { global $lock; if ( $key === 'estandar_seo_sync_lock' ) { $lock = false; } return true; }
function apply_filters( $hook, $value ) { return $value; }
function absint( $value ) { return abs( (int) $value ); }
function wp_next_scheduled( $hook ) { global $scheduled; return $scheduled[ $hook ] ?? false; }
function wp_schedule_single_event( $time, $hook ) { global $scheduled; $scheduled[ $hook ] = $time; return true; }
function wp_schedule_event() { return true; } function wp_unschedule_event() { return true; }
function wp_generate_uuid4() { return '00000000-0000-4000-8000-000000000001'; }

class Estandar_SEO_URL { public static function normalize( $url ) { return $url; } }
class Estandar_SEO_Repository {
    public static $metric_seen = array(); public static $query_seen = array(); public static $runs = array(); public static $finished = array();
    public static function ensure_landing( $url ) { return $url === '' ? 0 : 1; }
    public static function insert_landing_metrics( $id, $date, $row ) { $key = $id . $date; if ( isset( self::$metric_seen[ $key ] ) ) { return false; } self::$metric_seen[ $key ] = true; return true; }
    public static function insert_query( $id, $date, $row ) { $key = $id . $date . $row['query']; if ( isset( self::$query_seen[ $key ] ) ) { return false; } self::$query_seen[ $key ] = true; return true; }
    public static function mark_synced() {}
    public static function start_run( $trigger, $date ) { self::$runs[] = array( $trigger, $date ); return count( self::$runs ); }
    public static function finish_run( $id, $result, $next = null ) { self::$finished[] = array( $result, $next ); }
}
require dirname(__DIR__) . '/includes/class-estandar-seo-service.php';
$failed = 0; function check( $label, $ok ) { global $failed; echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . "\n"; $failed += $ok ? 0 : 1; }
class Fake_GSC {
    public $fail = false;
    public function pages( $date ) { return $this->fail ? array( 'success' => false, 'temporary' => true, 'message' => 'remote unavailable' ) : array( 'success' => true, 'rows' => array( array( 'page' => 'https://example.test/a/', 'clicks' => 3 ) ) ); }
    public function queries_for_page() { return array( 'success' => true, 'rows' => array( array( 'query' => 'parcelas', 'clicks' => 2 ), array( 'query' => 'alto', 'clicks' => 1 ) ) ); }
}
$adapter = new Fake_GSC(); $first = Estandar_SEO_Service::execute( '2026-07-10', 'manual', $adapter );
check( 'manual execution records received and inserted rows', $first['success'] && $first['rows_received'] === 3 && $first['rows_inserted'] === 3 && $first['rows_skipped'] === 0 );
$repeat = Estandar_SEO_Service::execute( '2026-07-10', 'backfill', $adapter );
check( 'repeated backfill is insert-only and idempotent', $repeat['success'] && $repeat['rows_inserted'] === 0 && $repeat['rows_skipped'] === 3 );
$adapter->fail = true; $failed_remote = Estandar_SEO_Service::execute( '2026-07-11', 'manual', $adapter );
check( 'remote error schedules recovery and records next attempt', ! $failed_remote['success'] && ! empty( $scheduled['estandar_seo_intelligence_retry'] ) && ! empty( Estandar_SEO_Repository::$finished[2][1] ) );
$adapter->fail = false; $recovered = Estandar_SEO_Service::execute( '2026-07-11', 'retry', $adapter );
check( 'recovery execution succeeds', $recovered['success'] && Estandar_SEO_Repository::$runs[3][0] === 'retry' );
check( 'commercial zone and configured delay are applied', Estandar_SEO_Service::stable_query_date( new DateTimeImmutable( '2026-07-16T03:30:00Z' ) ) === '2026-07-10' );
$lock = true; $busy = Estandar_SEO_Service::run_scheduled( 'cron' ); check( 'cron lock is idempotent', ! $busy['success'] && strpos( $busy['message'], 'already running' ) !== false );
check( 'execution metrics persisted for every operation', count( Estandar_SEO_Repository::$finished ) === 4 );
exit( $failed ? 1 : 0 );
