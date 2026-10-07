<?php
/**
 * ESTANDAR SUITE v2 — Brain · Harness SIGNAL ADOPTION (es_signal_* read-only).
 *
 * Verifica en WordPress real que los espejos estandar_brain_signal_*:
 *  - detectan las tablas legacy es_signal_*;
 *  - import dry-run NO escribe; commit copia idempotente (first-writer-wins);
 *  - source_table prefiere el espejo cuando tiene datos y vuelve a legacy
 *    tras rollback; rollback borra SOLO lo adoptado y nunca toca es_signal_*.
 *
 * Uso: php tests/run-signal-adoption.php <wp-path>
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

if ( ! class_exists( '\Estandar\Brain\SignalAdoption' ) ) {
	fwrite( STDERR, "SignalAdoption no cargada — ¿estandar-brain activo?\n" );
	exit( 1 );
}

use Estandar\Brain\SignalAdoption as SA;

$passed = 0; $failed = 0;
function check( $label, $cond ) {
	global $passed, $failed;
	if ( $cond ) { $passed++; echo "PASS  $label\n"; }
	else { $failed++; echo "FAIL  $label\n"; }
}

global $wpdb;
$prefix = $wpdb->prefix;
$mark   = 'sig-h-' . gmdate( 'YmdHis' ) . '-' . mt_rand( 1000, 9999 );

// ── Fixture legacy es_signal_events ────────────────────────────────────────
$evt = $prefix . 'es_signal_events';
$wpdb->query( "DROP TABLE IF EXISTS {$evt}" );
$wpdb->query( 'DROP TABLE IF EXISTS ' . SA::mirror_table( 'events' ) );

$wpdb->query( "CREATE TABLE {$evt} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
	signal_uuid char(36) NOT NULL DEFAULT '', client_id varchar(120) NOT NULL DEFAULT '',
	project_slug varchar(190) NOT NULL DEFAULT '', lead_id bigint(20) unsigned DEFAULT NULL,
	submission_id bigint(20) unsigned DEFAULT NULL, source_event_id bigint(20) unsigned DEFAULT NULL,
	source_plugin varchar(64) NOT NULL DEFAULT '', event_name varchar(80) NOT NULL DEFAULT '',
	event_stage varchar(80) NOT NULL DEFAULT '', lifecycle_key varchar(160) NOT NULL DEFAULT '',
	idempotency_key char(64) NOT NULL DEFAULT '', occurrence_index int(10) unsigned NOT NULL DEFAULT 1,
	audit_only tinyint(1) NOT NULL DEFAULT 1, export_status varchar(32) NOT NULL DEFAULT 'audit_only',
	event_time datetime NOT NULL, value_decimal decimal(18,4) DEFAULT NULL, currency varchar(8) NOT NULL DEFAULT '',
	quality varchar(32) NOT NULL DEFAULT '', status varchar(32) NOT NULL DEFAULT '',
	email_hash char(64) NOT NULL DEFAULT '', phone_hash char(64) NOT NULL DEFAULT '',
	session_id varchar(190) NOT NULL DEFAULT '', gclid varchar(255) NOT NULL DEFAULT '',
	gbraid varchar(255) NOT NULL DEFAULT '', wbraid varchar(255) NOT NULL DEFAULT '',
	fbclid varchar(255) NOT NULL DEFAULT '', meta_fbc varchar(255) NOT NULL DEFAULT '',
	meta_fbp varchar(255) NOT NULL DEFAULT '', ttclid varchar(255) NOT NULL DEFAULT '',
	msclkid varchar(255) NOT NULL DEFAULT '', identity_json longtext, payload_json longtext,
	created_at datetime NOT NULL, updated_at datetime NOT NULL ) {$wpdb->get_charset_collate()}" );

$now = gmdate( 'Y-m-d H:i:s' );
$uuid = 'sig-' . $mark;
$wpdb->insert( $evt, array( 'signal_uuid' => $uuid, 'client_id' => 'demo', 'project_slug' => 'demo',
	'lead_id' => 999, 'event_name' => 'lead_created', 'event_stage' => 'captured',
	'idempotency_key' => hash( 'sha256', $mark ), 'event_time' => $now, 'created_at' => $now, 'updated_at' => $now ) );

// ── Schema v2 ──────────────────────────────────────────────────────────────
SA::activate_schema();
check( '1a espejo events creado', SA::mirror_present( 'events' ) );

// ── Dry-run ────────────────────────────────────────────────────────────────
$dry = SA::import_surface( 'events', true, 100, 0 );
check( '2a dry-run events 1', (int) $dry['imported'] === 1 );
check( '2b dry-run NO persiste', (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . SA::mirror_table( 'events' ) ) === 0 );

// ── Commit + idempotencia ──────────────────────────────────────────────────
$com = SA::import_surface( 'events', false, 100, 0 );
check( '3a commit events 1', (int) $com['imported'] === 1 );
$com2 = SA::import_surface( 'events', false, 100, 0 );
check( '3b re-commit idempotente', (int) $com2['imported'] === 0 && (int) $com2['existing'] === 1 );

// ── source_table v2-first ──────────────────────────────────────────────────
check( '4a source_table = mirror', SA::source_table( 'events' ) === SA::mirror_table( 'events' ) );
$rows = SA::rows( 'events', 'ORDER BY id ASC LIMIT 10' );
check( '4b rows desde mirror', (string) ( $rows[0]['event_name'] ?? '' ) === 'lead_created' );

// ── Rollback solo adoptado ─────────────────────────────────────────────────
$rolled = SA::rollback();
check( '5a rollback borra adoptado', $rolled >= 1 );
check( '5b mirror vacío', (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . SA::mirror_table( 'events' ) ) === 0 );
check( '5c legacy intacta', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$evt}" ) === 1 );
check( '5d source_table vuelve a legacy', SA::source_table( 'events' ) === $evt );

// ── Cleanup ────────────────────────────────────────────────────────────────
$wpdb->query( "DROP TABLE IF EXISTS {$evt}" );
$wpdb->query( 'DROP TABLE IF EXISTS ' . SA::mirror_table( 'events' ) );

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
