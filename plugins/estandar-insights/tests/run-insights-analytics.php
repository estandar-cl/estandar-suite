<?php
/**
 * Analytics inheritance — functional test against the local Playground.
 *
 * Creates legacy ea_* fixtures, runs the v2 mirror migration (dry-run →
 * commit), verifies parity/idempotency, source_table preference, and rollback.
 *
 *   php tests/run-insights-analytics.php   → expect "0 failed"
 */

if ( getenv( 'ESTANDAR_MYSQL_GATE_DB' ) ) {
	require dirname( __DIR__, 2 ) . '/estandar-leads/tests/bootstrap-wordpress-mysql.php';
} else {
	require dirname( __DIR__, 3 ) . '/Playground/wp-load.php';
}

if ( ! class_exists( '\Estandar\Insights\Analytics' ) ) {
	fwrite( STDERR, "run-insights-analytics: clase Analytics no cargada — ¿estandar-insights activo en Playground?\n" );
	exit( 1 );
}

use Estandar\Insights\Analytics;

global $wpdb;
$passed = 0;
$failed = 0;

function ins_check( string $label, bool $cond ): void {
	global $passed, $failed;
	if ( $cond ) { $passed++; echo "PASS  {$label}\n"; }
	else { $failed++; echo "FAIL  {$label}\n"; }
}

$prefix = $wpdb->prefix;

// ── Fixture: legacy ea_sessions / ea_pageviews / ea_events ─────────────────
$legacy = array(
	'sessions' => array(
		array( 'session_id' => 'sess-1001', 'source' => 'google', 'medium' => 'cpc', 'page_count' => 3, 'duration' => 120, 'first_seen' => '2026-01-01 10:00:00', 'last_activity' => '2026-01-01 10:02:00', 'first_project_slug' => 'demo' ),
		array( 'session_id' => 'sess-1002', 'source' => 'facebook', 'medium' => 'social', 'page_count' => 1, 'duration' => 30, 'first_seen' => '2026-01-02 11:00:00', 'last_activity' => '2026-01-02 11:00:30', 'first_project_slug' => 'demo' ),
		array( 'session_id' => 'sess-1003', 'source' => 'direct', 'medium' => 'none', 'page_count' => 2, 'duration' => 60, 'first_seen' => '2026-01-03 12:00:00', 'last_activity' => '2026-01-03 12:01:00', 'first_project_slug' => '' ),
	),
	'pageviews' => array(
		array( 'session_id' => 'sess-1001', 'page_url' => 'https://cliente1.example.com/demo/', 'page_title' => 'demo', 'viewed_at' => '2026-01-01 10:00:00' ),
		array( 'session_id' => 'sess-1001', 'page_url' => 'https://cliente1.example.com/demo/contacto/', 'page_title' => 'Contacto', 'viewed_at' => '2026-01-01 10:01:00' ),
		array( 'session_id' => 'sess-1002', 'page_url' => 'https://cliente1.example.com/demo/', 'page_title' => 'demo', 'viewed_at' => '2026-01-02 11:00:00' ),
	),
	'events' => array(
		array( 'event_id' => 'evt-1', 'session_id' => 'sess-1001', 'event_type' => 'conversion', 'event_label' => 'form_submit', 'page_url' => 'https://cliente1.example.com/demo/contacto/', 'event_value' => '42', 'occurred_at' => '2026-01-01 10:01:30' ),
		array( 'event_id' => 'evt-2', 'session_id' => 'sess-1003', 'event_type' => 'pageview', 'event_label' => '', 'page_url' => 'https://cliente1.example.com/', 'event_value' => '', 'occurred_at' => '2026-01-03 12:00:00' ),
	),
	'conversions' => array(
		array( 'session_id' => 'sess-1001', 'conversion_key' => 'lead', 'conversion_label' => 'Lead', 'project_slug' => 'demo', 'source' => 'google', 'medium' => 'cpc', 'converted_at' => '2026-01-01 10:01:30' ),
	),
	'aggregates' => array(
		array( 'aggregate_date' => '2026-01-01', 'metric_key' => 'sessions', 'metric_value' => 1, 'dimension_key' => '', 'dimension_value' => '' ),
		array( 'aggregate_date' => '2026-01-02', 'metric_key' => 'sessions', 'metric_value' => 1, 'dimension_key' => '', 'dimension_value' => '' ),
	),
);

// Drop prior fixtures (idempotent test run).
foreach ( array_keys( $legacy ) as $surface ) {
	$t = $prefix . 'ea_' . ( $surface === 'aggregates' ? 'daily_aggregates' : $surface );
	$wpdb->query( "DROP TABLE IF EXISTS {$t}" );
	$mirror = Analytics::mirror_table( $surface );
	$wpdb->query( "DROP TABLE IF EXISTS {$mirror}" );
}

// Create legacy tables with the exact installer columns (subset columns are
// enough — Analytics copies by column name, so we replicate the real schema).
$cols = array(
	'sessions' => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
		session_id varchar(64) NOT NULL, visitor_hash varchar(64) NOT NULL DEFAULT '',
		first_seen datetime NOT NULL, last_activity datetime NOT NULL,
		duration int(10) unsigned NOT NULL DEFAULT 0, page_count smallint(5) unsigned NOT NULL DEFAULT 0,
		is_bounce tinyint(1) NOT NULL DEFAULT 1, landing_page varchar(2048) NOT NULL DEFAULT '',
		exit_page varchar(2048) NOT NULL DEFAULT '', is_returning tinyint(1) NOT NULL DEFAULT 0,
		source varchar(100) NOT NULL DEFAULT '', medium varchar(100) NOT NULL DEFAULT '',
		campaign varchar(255) NOT NULL DEFAULT '', content varchar(255) NOT NULL DEFAULT '',
		term varchar(255) NOT NULL DEFAULT '', campaign_id varchar(190) NOT NULL DEFAULT '',
		adset_id varchar(190) NOT NULL DEFAULT '', ad_id varchar(190) NOT NULL DEFAULT '',
		adgroup_id varchar(190) NOT NULL DEFAULT '', asset_group_id varchar(190) NOT NULL DEFAULT '',
		creative_id varchar(190) NOT NULL DEFAULT '', keyword varchar(255) NOT NULL DEFAULT '',
		match_type varchar(32) NOT NULL DEFAULT '', network varchar(32) NOT NULL DEFAULT '',
		google_device varchar(32) NOT NULL DEFAULT '', placement varchar(255) NOT NULL DEFAULT '',
		campaign_type varchar(64) NOT NULL DEFAULT '', gclid varchar(255) NOT NULL DEFAULT '',
		gbraid varchar(255) NOT NULL DEFAULT '', wbraid varchar(255) NOT NULL DEFAULT '',
		fbclid varchar(255) NOT NULL DEFAULT '', meta_fbp varchar(255) NOT NULL DEFAULT '',
		meta_fbc varchar(255) NOT NULL DEFAULT '', ttclid varchar(255) NOT NULL DEFAULT '',
		msclkid varchar(255) NOT NULL DEFAULT '', referrer varchar(2048) NOT NULL DEFAULT '',
		referrer_domain varchar(255) NOT NULL DEFAULT '', traffic_type varchar(50) NOT NULL DEFAULT '',
		first_project_slug varchar(190) NOT NULL DEFAULT '', first_project_page varchar(2048) NOT NULL DEFAULT ''",
	'pageviews' => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
		session_id varchar(64) NOT NULL, page_url varchar(2048) NOT NULL DEFAULT '',
		page_title varchar(500) NOT NULL DEFAULT '', viewed_at datetime NOT NULL,
		time_on_page int(10) unsigned NOT NULL DEFAULT 0, scroll_depth tinyint(3) unsigned NOT NULL DEFAULT 0",
	'events' => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
		event_id varchar(64) DEFAULT NULL, session_id varchar(64) NOT NULL,
		event_type varchar(50) NOT NULL, event_label varchar(255) NOT NULL DEFAULT '',
		page_url varchar(2048) NOT NULL DEFAULT '', event_value varchar(500) NOT NULL DEFAULT '',
		meta_json longtext, occurred_at datetime NOT NULL",
	'conversions' => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
		session_id varchar(64) NOT NULL, conversion_key varchar(100) NOT NULL,
		conversion_label varchar(255) NOT NULL DEFAULT '', page_url varchar(2048) NOT NULL DEFAULT '',
		project_slug varchar(190) NOT NULL DEFAULT '', source varchar(100) NOT NULL DEFAULT '',
		medium varchar(100) NOT NULL DEFAULT '', campaign varchar(255) NOT NULL DEFAULT '',
		landing_page varchar(2048) NOT NULL DEFAULT '', conversion_value int(10) unsigned NOT NULL DEFAULT 0,
		converted_at datetime NOT NULL",
	'aggregates' => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
		aggregate_date date NOT NULL, metric_key varchar(100) NOT NULL,
		metric_value bigint(20) NOT NULL DEFAULT 0, dimension_key varchar(100) NOT NULL DEFAULT '',
		dimension_value varchar(255) NOT NULL DEFAULT ''",
);

foreach ( $cols as $surface => $definition ) {
	$legacy_name = $surface === 'aggregates' ? 'ea_daily_aggregates' : 'ea_' . $surface;
	$wpdb->query( "CREATE TABLE {$prefix}{$legacy_name} ({$definition}) {$wpdb->get_charset_collate()}" );
	foreach ( $legacy[ $surface ] as $row ) {
		$wpdb->insert( $prefix . $legacy_name, $row );
	}
}

$expected = array( 'sessions' => 3, 'pageviews' => 3, 'events' => 2, 'conversions' => 1, 'aggregates' => 2 );

// ── Schema v2 ──────────────────────────────────────────────────────────────
Analytics::activate_schema();
ins_check( '1a espejo sessions creado', Analytics::mirror_present( 'sessions' ) );
ins_check( '1b espejo events creado', Analytics::mirror_present( 'events' ) );
ins_check( '1c espejo aggregates creado', Analytics::mirror_present( 'aggregates' ) );

// ── Dry-run no toca nada ───────────────────────────────────────────────────
$dry = Analytics::import( true, 100 );
ins_check( '2a dry-run sessions 3', $dry['sessions']['imported'] === 3 && $dry['sessions']['existing'] === 0 );
ins_check( '2b dry-run events 2', $dry['events']['imported'] === 2 );
$after_dry = Analytics::status();
ins_check( '2c dry-run no inserta', $after_dry['sessions']['adopted'] === 0 );

// ── Commit ─────────────────────────────────────────────────────────────────
$com = Analytics::import( false, 100 );
foreach ( $expected as $surface => $n ) {
	ins_check( "3a commit {$surface} = {$n}", $com[ $surface ]['imported'] === $n );
}
$st = Analytics::status();
ins_check( '3b adoptado sessions = legacy', $st['sessions']['adopted'] === $st['sessions']['legacy'] );
ins_check( '3c adoptado events = legacy', $st['events']['adopted'] === $st['events']['legacy'] );

// ── Idempotencia: segundo commit no duplica ────────────────────────────────
$com2 = Analytics::import( false, 100 );
$total2 = 0;
foreach ( $com2 as $c ) { $total2 += $c['imported']; }
ins_check( '4a re-commit no importa', $total2 === 0 );
$st2 = Analytics::status();
ins_check( '4b adoptado sigue = legacy', $st2['sessions']['adopted'] === $st2['sessions']['legacy'] );

// ── source_table prefiere espejo (v2-first) ────────────────────────────────
$src = Analytics::source_table( 'sessions' );
ins_check( '5a source_table = mirror', $src === Analytics::mirror_table( 'sessions' ) );
ins_check( '5b rows leen del mirror', Analytics::count( 'sessions' ) === 3 );
$by = Analytics::group( 'sessions', 'source' );
ins_check( '5c group por fuente', ( $by['google'] ?? 0 ) === 1 && ( $by['facebook'] ?? 0 ) === 1 );
$top = Analytics::group( 'pageviews', 'page_url' );
ins_check( '5d top pages', ( $top['https://cliente1.example.com/demo/'] ?? 0 ) === 2 );
$ev = Analytics::rows( 'events', 'ORDER BY id ASC LIMIT 10' );
ins_check( '5e eventos con label', (string) ( $ev[0]['event_label'] ?? '' ) === 'form_submit' );

// ── Rollback solo adoptado (legacy intacta) ────────────────────────────────
$rolled = Analytics::rollback();
ins_check( '6a rollback borra adoptado', $rolled >= 9 );
$st3 = Analytics::status();
ins_check( '6b mirror vacío tras rollback', $st3['sessions']['adopted'] === 0 );
ins_check( '6c legacy intacta', $st3['sessions']['legacy'] === 3 );
$src3 = Analytics::source_table( 'sessions' );
ins_check( '6d source_table vuelve a legacy', $src3 === $prefix . 'ea_sessions' );

// ── Serie diaria unificada (Panorama) tras rollback lee del legacy ───────
$ser = Analytics::series_between( '2026-01-01', '2026-01-03' );
ins_check( '7a series_between 3 etiquetas', count( $ser['labels'] ) === 3 );
ins_check( '7b sessions por día 1/1/1', $ser['sessions'] === array( 1, 1, 1 ) );
ins_check( '7c pageviews por día 2/1/0', $ser['pageviews'] === array( 2, 1, 0 ) );
ins_check( '7d events por día 1/0/1', $ser['events'] === array( 1, 0, 1 ) );
$ser14 = Analytics::daily_series( 14 );
ins_check( '7e daily_series 14 etiquetas', count( $ser14['labels'] ) === 14 );
ins_check( '7f daily_series series alineadas (14)', count( $ser14['sessions'] ) === 14 && count( $ser14['pageviews'] ) === 14 && count( $ser14['events'] ) === 14 );

// ── Conteo por rango (conversiones de la ventana del Panorama) ───────────
$c1 = Analytics::count_between( 'conversions', 'converted_at', '2026-01-01', '2026-01-02' );
ins_check( '8a count_between ventana 1 conv', $c1 === 1 );
$c2 = Analytics::count_between( 'conversions', 'converted_at', '', '' );
ins_check( '8b count_between sin límites = total', $c2 === 1 );

// ── Cleanup fixtures ───────────────────────────────────────────────────────
foreach ( array_keys( $legacy ) as $surface ) {
	$t = $prefix . ( $surface === 'aggregates' ? 'ea_daily_aggregates' : 'ea_' . $surface );
	$wpdb->query( "DROP TABLE IF EXISTS {$t}" );
	$wpdb->query( 'DROP TABLE IF EXISTS ' . Analytics::mirror_table( $surface ) );
}

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
