<?php
/** Run: php tests/run-landing-context-contract.php */
define( 'ABSPATH', __DIR__ . '/' );
function wp_cache_get() { return false; } function wp_cache_set() {} function get_option( $k, $d = false ) { return $d; } function apply_filters( $h, $v ) { return $v; } function absint( $v ) { return abs( (int) $v ); }
class Estandar_SEO_URL { public static function normalize( $u ) { return 'https://example.test/alto/'; } }
class Estandar_SEO_Service { public static function enabled() { return true; } public static function commercial_timezone() { return new DateTimeZone( 'America/Santiago' ); } public static function stability_delay_days() { return 3; } }
class Estandar_SEO_Repository { public static function landing_context( $url, $days, $limit ) { return array( 'found' => true, 'normalized_landing_url' => 'https://example.test/alto/', 'latest_commercial_date' => ( new DateTimeImmutable( 'now', new DateTimeZone( 'America/Santiago' ) ) )->format( 'Y-m-d' ), 'period' => array( 'period_from' => '2026-06-01', 'period_to' => '2026-06-28', 'clicks' => 20, 'impressions' => 200, 'ctr' => .1, 'average_position' => 2.1 ), 'top_queries' => array( array( 'query' => 'alto jaramillo', 'clicks' => 12 ), array( 'query' => 'parcelas alto jaramillo', 'clicks' => 8 ) ) ); } }
require dirname(__DIR__) . '/includes/class-estandar-seo-intelligence.php';
$failed = 0; function check( $label, $ok ) { global $failed; echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . "\n"; $failed += $ok ? 0 : 1; }
$context = Estandar_SEO_Intelligence::get_landing_organic_context( 'https://example.test/alto/?utm=x', 28, 5 );
check( 'context is landing aggregate only', $context['attribution']['scope'] === 'landing_aggregate' && $context['attribution']['exact_query_known'] === false );
check( 'context exposes metrics, period and ordered queries', $context['metrics']['clicks'] === 20 && $context['period']['from'] === '2026-06-01' && $context['top_queries'][0]['query'] === 'alto jaramillo' );
check( 'context exposes normalized landing and freshness', $context['normalized_landing_url'] === 'https://example.test/alto/' && $context['freshness']['stale'] === false );
exit( $failed ? 1 : 0 );
