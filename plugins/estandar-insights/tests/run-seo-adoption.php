<?php
/**
 * ESTANDAR SUITE v2 — Insights · Harness SEO ADOPTION (es_seo_* read-only).
 *
 * Verifica en WordPress real que los espejos estandar_insights_seo_*:
 *  - detectan las tablas legacy es_seo_*;
 *  - import dry-run NO escribe; commit copia idempotente (first-writer-wins);
 *  - source_table prefiere el espejo cuando tiene datos y vuelve a legacy
 *    tras rollback; rollback borra SOLO lo adoptado y nunca toca es_seo_*.
 *
 * Uso: php tests/run-seo-adoption.php <wp-path>
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_NOTICE );

$wp_path = rtrim( $argv[1] ?? '', '/' );
if ( $wp_path === '' ) {
	$wp_path = dirname( __DIR__, 3 ) . '/Playground';
}
if ( ! file_exists( $wp_path . '/wp-load.php' ) ) {
	fwrite( STDERR, "wp-load.php no encontrado en {$wp_path}\n" );
	exit( 2 );
}
require $wp_path . '/wp-load.php';

if ( ! class_exists( '\Estandar\Insights\SeoAdoption' ) ) {
	fwrite( STDERR, "SeoAdoption no cargada — ¿estandar-insights activo?\n" );
	exit( 1 );
}

use Estandar\Insights\SeoAdoption as S;

$passed = 0; $failed = 0;
function check( $label, $cond ) {
	global $passed, $failed;
	if ( $cond ) { $passed++; echo "PASS  $label\n"; }
	else { $failed++; echo "FAIL  $label\n"; }
}

global $wpdb;
$prefix = $wpdb->prefix;
$mark   = 'seo-h-' . gmdate( 'YmdHis' ) . '-' . mt_rand( 1000, 9999 );

// ── Fixture legacy es_seo_landings + es_seo_landing_metrics ───────────────
$land = $prefix . 'es_seo_landings';
$metr = $prefix . 'es_seo_landing_metrics';
$wpdb->query( "DROP TABLE IF EXISTS {$land}" );
$wpdb->query( "DROP TABLE IF EXISTS {$metr}" );
$wpdb->query( 'DROP TABLE IF EXISTS ' . S::mirror_table( 'landings' ) );
$wpdb->query( 'DROP TABLE IF EXISTS ' . S::mirror_table( 'metrics' ) );

$wpdb->query( "CREATE TABLE {$land} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
	url text NOT NULL, normalized_url text NOT NULL, normalized_url_hash char(64) NOT NULL,
	last_sync datetime DEFAULT NULL, created_at datetime NOT NULL ) {$wpdb->get_charset_collate()}" );
$wpdb->query( "CREATE TABLE {$metr} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
	landing_id bigint(20) unsigned NOT NULL, date date NOT NULL,
	clicks bigint(20) unsigned NOT NULL DEFAULT 0, impressions bigint(20) unsigned NOT NULL DEFAULT 0,
	ctr decimal(12,8) NOT NULL DEFAULT 0, average_position decimal(12,4) NOT NULL DEFAULT 0,
	created_at datetime NOT NULL ) {$wpdb->get_charset_collate()}" );

$now = gmdate( 'Y-m-d H:i:s' );
$url = 'https://demo.test/' . $mark;
$hash = hash( 'sha256', $url );
$wpdb->insert( $land, array( 'url' => $url, 'normalized_url' => $url . '/', 'normalized_url_hash' => $hash, 'created_at' => $now ) );
$lid = (int) $wpdb->insert_id;
$wpdb->insert( $metr, array( 'landing_id' => $lid, 'date' => gmdate( 'Y-m-d' ), 'clicks' => 5, 'impressions' => 100, 'created_at' => $now ) );

// ── Schema v2 ──────────────────────────────────────────────────────────────
S::activate_schema();
check( '1a espejo landings creado', S::mirror_present( 'landings' ) );
check( '1b espejo metrics creado', S::mirror_present( 'metrics' ) );

// ── Dry-run ────────────────────────────────────────────────────────────────
$dry = S::import_surface( 'landings', true, 100, 0 );
check( '2a dry-run landings 1', (int) $dry['imported'] === 1 );
check( '2b dry-run NO persiste', (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . S::mirror_table( 'landings' ) ) === 0 );

// ── Commit + idempotencia ──────────────────────────────────────────────────
$com = S::import_surface( 'landings', false, 100, 0 );
check( '3a commit landings 1', (int) $com['imported'] === 1 );
S::import_surface( 'metrics', false, 100, 0 );
check( '3b commit metrics 1', S::count( 'metrics' ) === 1 );
$com2 = S::import_surface( 'landings', false, 100, 0 );
check( '3c re-commit idempotente', (int) $com2['imported'] === 0 && (int) $com2['existing'] === 1 );

// ── source_table v2-first ──────────────────────────────────────────────────
check( '4a source_table = mirror', S::source_table( 'landings' ) === S::mirror_table( 'landings' ) );
$rows = S::rows( 'landings', 'ORDER BY id ASC LIMIT 10' );
check( '4b rows desde mirror con url', strpos( (string) ( $rows[0]['url'] ?? '' ), $mark ) !== false );

// ── Rollback solo adoptado ─────────────────────────────────────────────────
$rolled = S::rollback();
check( '5a rollback borra adoptado', $rolled >= 1 );
check( '5b mirror vacío', (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . S::mirror_table( 'landings' ) ) === 0 );
check( '5c legacy intacta', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$land}" ) === 1 );
check( '5d source_table vuelve a legacy', S::source_table( 'landings' ) === $land );

// ── Cleanup ────────────────────────────────────────────────────────────────
$wpdb->query( "DROP TABLE IF EXISTS {$land}" );
$wpdb->query( "DROP TABLE IF EXISTS {$metr}" );
$wpdb->query( 'DROP TABLE IF EXISTS ' . S::mirror_table( 'landings' ) );
$wpdb->query( 'DROP TABLE IF EXISTS ' . S::mirror_table( 'metrics' ) );

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
