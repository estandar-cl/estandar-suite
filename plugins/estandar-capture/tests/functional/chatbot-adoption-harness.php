<?php
/**
 * ESTANDAR SUITE v2 — Capture · Harness CHATBOT ADOPTION (ecb_leads read-only).
 *
 * Verifica en WordPress real (estandar-chatbot activo, tabla ecb_leads) que:
 *  - la adopción detecta ecb_leads y proyecta correctamente cada fila;
 *  - import dry-run NO escribe; import commit adopta y es idempotente
 *    (first-writer-wins) y resumible;
 *  - rollback elimina SOLO lo adoptado y nunca toca ecb_leads;
 *  - counts() refleja legacy/adoptados/total source=chatbot.
 *
 * Uso: php tests/functional/chatbot-adoption-harness.php <wp-path>
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_NOTICE );

$wp_path = rtrim( $argv[1] ?? '', '/' );
if ( $wp_path === '' ) {
	$wp_path = dirname( __DIR__, 4 ) . '/Playground';
}
if ( ! file_exists( $wp_path . '/wp-load.php' ) ) {
	fwrite( STDERR, "WordPress no encontrado en $wp_path (pasa el path o levanta el Playground)\n" );
	exit( 2 );
}

define( 'WP_USE_THEMES', false );
require_once $wp_path . '/wp-load.php';

use Estandar\Capture as C;

$passed = 0; $failed = 0;
function check( $label, $cond ) {
	global $passed, $failed;
	if ( $cond ) { $passed++; echo "PASS  $label\n"; }
	else { $failed++; echo "FAIL  $label\n"; }
}

global $wpdb;
$subs  = $wpdb->prefix . 'estandar_capture_submissions';
$leads = $wpdb->prefix . 'ecb_leads';
$mark  = 'harness-ca-' . gmdate( 'YmdHis' ) . '-' . mt_rand( 1000, 9999 );

check( 'ChatbotAdoption existe', class_exists( 'Estandar\Capture\ChatbotAdoption' ) );
check( 'ecb_leads presente (legacy_present)', C\ChatbotAdoption::legacy_present() );

// ── Fixture: 2 filas en ecb_leads ─────────────────────────────────────────
$now = current_time( 'mysql' );
$wpdb->insert( $leads, array(
	'created_at'          => $now,
	'last_interaction_at' => $now,
	'email'               => $mark . 'a@test.estandar.local',
	'phone'               => '56911112222',
	'data'                => wp_json_encode( array(
		'name' => 'Fixture A', 'requirement' => 'Quiero info', 'source_channel' => 'web',
		'source_page_url' => 'https://test.local/a', 'analytics_session_id' => 'ca-sess-a',
	) ),
), array( '%s', '%s', '%s', '%s', '%s' ) );
$fid_a = (int) $wpdb->insert_id;
$wpdb->insert( $leads, array(
	'created_at'          => $now,
	'last_interaction_at' => $now,
	'email'               => $mark . 'b@test.estandar.local',
	'phone'               => '56933334444',
	'data'                => wp_json_encode( array(
		'name' => 'Fixture B', 'requirement' => 'WhatsApp', 'source_channel' => 'whatsapp',
	) ),
), array( '%s', '%s', '%s', '%s', '%s' ) );
$fid_b = (int) $wpdb->insert_id;

// ── project_lead (PURE) ───────────────────────────────────────────────────
$row_a = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$leads} WHERE id = %d", $fid_a ), ARRAY_A );
$proj  = C\ChatbotAdoption::project_lead( $row_a );
check( 'project_lead source=chatbot', $proj['source'] === 'chatbot' );
check( 'project_lead marcador origin_lead_id', (int) $proj['origin_lead_id'] === $fid_a );
check( 'project_lead name desde data', $proj['name'] === 'Fixture A' );
check( 'project_lead message desde requirement', $proj['message'] === 'Quiero info' );
check( 'project_lead phone normalizado 56', $proj['phone_normalized'] === '56911112222' );
check( 'project_lead landing_url desde source_page_url', $proj['landing_url'] === 'https://test.local/a' );

// ── import dry-run: NO escribe ────────────────────────────────────────────
$dry = C\ChatbotAdoption::import( true, 100 );
check( 'dry-run reporta importables', (int) $dry['imported'] >= 2 );
check( 'dry-run NO persiste', (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM {$subs} WHERE origin_lead_id IN (%d,%d) AND source = 'chatbot'", $fid_a, $fid_b ) ) === 0 );

// ── import commit + idempotencia ──────────────────────────────────────────
$c1 = C\ChatbotAdoption::import( false, 100 );
check( 'commit importa filas', (int) $c1['imported'] >= 2 );
check( 'filas persistidas con marcador', (int) $wpdb->get_var( $wpdb->prepare(
	"SELECT COUNT(*) FROM {$subs} WHERE origin_lead_id IN (%d,%d) AND source = 'chatbot'", $fid_a, $fid_b ) ) >= 2 );
$c2 = C\ChatbotAdoption::import( false, 100 );
check( 'segundo import: first-writer-wins (existing)', (int) $c2['imported'] === 0 && (int) $c2['existing'] >= 2 );

// ── counts ────────────────────────────────────────────────────────────────
$counts = C\ChatbotAdoption::counts();
check( 'counts legacy_total refleja fixture', (int) $counts['legacy_total'] >= 2 );
check( 'counts adopted >= 2', (int) $counts['adopted'] >= 2 );

// ── rollback: solo adoptados, ecb_leads intacta ───────────────────────────
$marker_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$leads}" );
$rolled = C\ChatbotAdoption::rollback();
check( 'rollback elimina adoptados', $rolled >= 2 );
check( 'rollback NO toca ecb_leads', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$leads}" ) === $marker_before );
check( 'adoptados quedan en 0 tras rollback', (int) C\ChatbotAdoption::counts()['adopted'] === 0 );

// ── Espejos v2 (conversaciones/logs/eventos) ───────────────────────────────
$conv = $wpdb->prefix . 'ecb_conversations';
$logs = $wpdb->prefix . 'ecb_logs';
$evt  = $wpdb->prefix . 'ecb_events';

// Fixtures legacy (DROP previo para idempotencia)
foreach ( array( $conv, $logs, $evt ) as $t ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$t}" );
	$mirror = C\ChatbotAdoption::mirror_table( strpos( $t, 'ecb_events' ) !== false ? 'events' : ( strpos( $t, 'ecb_logs' ) !== false ? 'logs' : 'conversations' ) );
	$wpdb->query( "DROP TABLE IF EXISTS {$mirror}" );
}
C\ChatbotAdoption::activate_mirror_schema();
$wpdb->query( "CREATE TABLE {$conv} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
	session_key varchar(190) NOT NULL, channel varchar(40) NOT NULL,
	user_id bigint(20) unsigned NULL, lead_email varchar(190) NULL, lead_phone varchar(40) NULL,
	conversation_status varchar(40) NOT NULL DEFAULT 'open', message_count int(10) unsigned NOT NULL DEFAULT 0,
	first_message_at datetime NOT NULL, last_message_at datetime NOT NULL,
	summary longtext NULL, messages longtext NOT NULL, meta longtext NOT NULL ) {$wpdb->get_charset_collate()}" );
$wpdb->query( "CREATE TABLE {$logs} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
	datetime datetime NOT NULL, question longtext NOT NULL, answer longtext NOT NULL,
	ip varchar(45) NULL, user_agent text NULL, url text NULL, meta longtext NULL ) {$wpdb->get_charset_collate()}" );
$wpdb->query( "CREATE TABLE {$evt} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
	created_at datetime NOT NULL, event_name varchar(90) NOT NULL, channel varchar(40) NOT NULL,
	session_key varchar(190) NULL, conversation_id bigint(20) unsigned NULL, lead_id bigint(20) unsigned NULL,
	lead_email varchar(190) NULL, lead_phone varchar(40) NULL, meta longtext NOT NULL ) {$wpdb->get_charset_collate()}" );

$wpdb->insert( $conv, array( 'session_key' => 'mirror-sess-1', 'channel' => 'web', 'lead_email' => $mark . 'a@test.estandar.local',
	'message_count' => 2, 'first_message_at' => $now, 'last_message_at' => $now,
	'messages' => wp_json_encode( array( array( 'role' => 'user', 'content' => 'hola' ), array( 'role' => 'bot', 'content' => 'buenas' ) ) ), 'meta' => '{}' ) );
$cid = (int) $wpdb->insert_id;
$wpdb->insert( $logs, array( 'datetime' => $now, 'question' => 'pregunta', 'answer' => 'respuesta' ) );
$lid = (int) $wpdb->insert_id;
$wpdb->insert( $evt, array( 'created_at' => $now, 'event_name' => 'conversation_started', 'channel' => 'web', 'conversation_id' => $cid, 'meta' => '{}' ) );
$eid = (int) $wpdb->insert_id;

check( 'mirror_status detecta legacy', C\ChatbotAdoption::mirror_status()['conversations']['legacy_exists'] );
$mirror_conv = C\ChatbotAdoption::mirror_table( 'conversations' );
$dry_m = C\ChatbotAdoption::import_surface( 'conversations', true, 100, 0 );
$mirror_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$mirror_conv}" );
check( 'dry-run espejo NO persiste', (int) $dry_m['imported'] === 1 && $mirror_count === 0 );
$c_m = C\ChatbotAdoption::import_surface( 'conversations', false, 100, 0 );
check( 'commit espejo conversaciones', (int) $c_m['imported'] === 1 );
check( 'espejo conversaciones con datos', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$mirror_conv}" ) === 1 );
C\ChatbotAdoption::import_surface( 'logs', false, 100, 0 );
C\ChatbotAdoption::import_surface( 'events', false, 100, 0 );
check( 'espejo logs con datos', C\ChatbotAdoption::count( 'logs' ) === 1 );
check( 'espejo events con datos', C\ChatbotAdoption::count( 'events' ) === 1 );
$src = C\ChatbotAdoption::source_table( 'conversations' );
check( 'source_table prefiere espejo', $src === C\ChatbotAdoption::mirror_table( 'conversations' ) );
$rows_ev = C\ChatbotAdoption::rows( 'events' );
check( 'rows events desde espejo', (string) ( $rows_ev[0]['event_name'] ?? '' ) === 'conversation_started' );
$c2_m = C\ChatbotAdoption::import_surface( 'conversations', false, 100, 0 );
check( 're-commit espejo idempotente', (int) $c2_m['imported'] === 0 && (int) $c2_m['existing'] === 1 );
$rolled_all = C\ChatbotAdoption::rollback_all();
check( 'rollback_all cubre espejos', $rolled_all >= 1 );
check( 'espejo vacío tras rollback_all', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$mirror_conv}" ) === 0 );
$src2 = C\ChatbotAdoption::source_table( 'conversations' );
check( 'source_table vuelve a legacy', $src2 === $conv );

// Limpieza de fixtures
$wpdb->query( $wpdb->prepare( "DELETE FROM {$leads} WHERE email LIKE %s", $mark . '%' ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$subs} WHERE email_normalized LIKE %s", $mark . '%' ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$conv} WHERE lead_email LIKE %s", $mark . '%' ) );
foreach ( array( $conv, $logs, $evt ) as $t ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$t}" );
	$suffix = strpos( $t, 'ecb_events' ) !== false ? 'events' : ( strpos( $t, 'ecb_logs' ) !== false ? 'logs' : 'conversations' );
	$wpdb->query( 'DROP TABLE IF EXISTS ' . C\ChatbotAdoption::mirror_table( $suffix ) );
}

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
