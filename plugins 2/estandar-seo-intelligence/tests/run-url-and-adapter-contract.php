<?php
/** Run: php tests/run-url-and-adapter-contract.php */
define( 'ABSPATH', __DIR__ . '/' );
function get_option( $key, $default = false ) { return $default; }
function apply_filters( $hook, $value ) { return $value; }
function absint( $value ) { return abs( (int) $value ); }
require dirname(__DIR__) . '/includes/class-estandar-seo-url.php';
require dirname(__DIR__) . '/includes/class-estandar-seo-service.php';
$failed = 0;
function check( $label, $ok ) { global $failed; echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $label . "\n"; $failed += $ok ? 0 : 1; }

check( 'removes query and fragment', Estandar_SEO_URL::normalize( 'HTTPS://Example.test/alto/?utm_source=google#form' ) === 'https://example.test/alto/' );
check( 'normalizes duplicate slashes and trailing slash', Estandar_SEO_URL::normalize( 'https://example.test//alto' ) === 'https://example.test/alto/' );
check( 'keeps root canonical', Estandar_SEO_URL::normalize( 'https://example.test' ) === 'https://example.test/' );
check( 'rejects relative URL', Estandar_SEO_URL::normalize( '/alto/' ) === '' );
check( 'stable identity hash', Estandar_SEO_URL::hash( 'https://example.test/alto/' ) === Estandar_SEO_URL::hash( 'https://example.test/alto/' ) );
check( 'winter uses America/Santiago UTC-4 calendar', Estandar_SEO_Service::stable_query_date( new DateTimeImmutable( '2026-07-16T03:30:00Z' ) ) === '2026-07-12' );
check( 'summer uses America/Santiago UTC-3 calendar', Estandar_SEO_Service::stable_query_date( new DateTimeImmutable( '2026-01-16T03:30:00Z' ) ) === '2026-01-13' );
check( 'DST start observes UTC-3 after transition', ( new DateTimeImmutable( '2026-09-06T04:30:00Z' ) )->setTimezone( Estandar_SEO_Service::commercial_timezone() )->format( 'P' ) === '-03:00' );
check( 'DST end observes UTC-4 after transition', ( new DateTimeImmutable( '2026-04-05T03:30:00Z' ) )->setTimezone( Estandar_SEO_Service::commercial_timezone() )->format( 'P' ) === '-04:00' );
check( 'DST transition uses local calendar before offset change', Estandar_SEO_Service::stable_query_date( new DateTimeImmutable( '2026-04-05T02:30:00Z' ) ) === '2026-04-01' );
check( 'DST transition uses local calendar after offset change', Estandar_SEO_Service::stable_query_date( new DateTimeImmutable( '2026-04-05T03:30:00Z' ) ) === '2026-04-01' );
exit( $failed ? 1 : 0 );
