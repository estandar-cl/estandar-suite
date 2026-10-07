<?php
/**
 * ESTANDAR SUITE v2 — Capture · Harness CHATBOT BRIDGE (coexistencia real).
 *
 * Verifica la arista de fusión ChatbotBridge en WordPress real con
 * estandar-chatbot + estandar-capture ACTIVOS:
 *  - el hook estandar_lead_captured está registrado (prio 9);
 *  - una emisión legacy se ingesta como submission source='chatbot' y emite
 *    estandar_suite_capture_event + estandar_suite_chatbot_lead_bridged;
 *  - anti-bucle: payloads con meta._capture_origin se ignoran;
 *  - anti-duplicado: delegate_save + emisión posterior del mismo lead → 1 fila;
 *  - delegate_save devuelve el contrato exacto de estandar_chatbot_save_lead.
 *
 * Uso: php tests/functional/chatbot-bridge-harness.php <wp-path>
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
$table = $wpdb->prefix . 'estandar_capture_submissions';
$mark  = 'harness-cb-' . gmdate( 'YmdHis' ) . '-' . mt_rand( 1000, 9999 );
$email = $mark . '@test.estandar.local';

echo "# mark=$mark\n";

// ── 1) Registro del puente ────────────────────────────────────────────────
check( 'ChatbotBridge existe', class_exists( 'Estandar\Capture\ChatbotBridge' ) );
if ( C\ChatbotBridge::legacy_chatbot_active() ) {
	check( 'legacy chatbot activo detectado', true );
} else {
	// El plugin legacy estandar-chatbot fue retirado del set ("NO USAR; SON
	// LEGACY"). El puente se valida igualmente emitiendo estandar_lead_captured
	// (rutas capture v2); la presencia del legacy es una precondición del
	// entorno, no del código del puente.
	echo "SKIP  legacy chatbot activo (legacy no presente; se validan rutas capture v2)\n";
}
$cb_callbacks = $GLOBALS['wp_filter']['estandar_lead_captured']->callbacks[9] ?? array();
$has_bridge = false;
foreach ( $cb_callbacks as $c ) {
	foreach ( (array) $c['function'] as $part ) {
		if ( is_string( $part ) && strpos( $part, 'ChatbotBridge' ) !== false ) { $has_bridge = true; }
	}
}
check( 'hook estandar_lead_captured @9 registrado', $has_bridge );

// ── 2) Emisión legacy → ingestión + evento ────────────────────────────────
$events = array();
add_action( 'estandar_suite_capture_event', function ( $c ) use ( &$events ) { $events[] = $c; }, 99, 1 );
$bridged = array();
add_action( 'estandar_suite_chatbot_lead_bridged', function ( $c ) use ( &$bridged ) { $bridged[] = $c; }, 99, 1 );

do_action( 'estandar_lead_captured', array(
	'schema_version' => '1.0',
	'source_plugin'  => 'estandar-chatbot',
	'submitted_at'   => gmdate( 'c' ),
	'contact'        => array( 'name' => 'Harness User', 'email' => $email, 'phone' => '+56 9 5555 1234' ),
	'content'        => array( 'message' => 'Quiero info del proyecto X', 'source_form_id' => 'chatbot' ),
	'context'        => array( 'project_slug' => 'test-project', 'project_area' => 'Test Area', 'source_channel' => 'web', 'intent' => 'contact' ),
	'chatbot'        => array( 'chatbot_status' => 'created', 'chatbot_lead_id' => 0, 'chatbot_session_key' => 'sess-abc', 'force_human' => false ),
	'scoring'        => array( 'lead_score' => 50, 'lead_tier' => 'warm', 'lead_route' => 'nurturing' ),
	'attribution'    => array( 'landing_url' => 'https://test.local/x', 'utm_source' => 'test', 'utm_campaign' => 'harness', 'session_id' => 'sess-abc' ),
	'request'        => array( 'ip_address' => '127.0.0.1', 'user_agent' => 'harness' ),
	'spam'           => array( 'honeypot_filled' => false ),
	'meta'           => array( 'source_channel' => 'web', 'lead_score' => 50 ),
) );

$row = $wpdb->get_row( $wpdb->prepare(
	"SELECT * FROM {$table} WHERE email_normalized = %s ORDER BY id DESC LIMIT 1", $email ), ARRAY_A );
check( 'emisión legacy → submission source=chatbot creada', is_array( $row ) && $row['source'] === 'chatbot' );
check( 'submission preserva source_form_id=chatbot', is_array( $row ) && $row['source_form_id'] === 'chatbot' );
check( 'submission tiene identidad normalizada', is_array( $row ) && $row['phone_normalized'] === '56955551234' );
check( 'submission lleva fields ricos (fields_json)', is_array( $row ) && strpos( (string) $row['fields_json'], 'test-project' ) !== false );
check( 'se emitió estandar_suite_capture_event', count( $events ) >= 1 );
check( 'se emitió estandar_suite_chatbot_lead_bridged', count( $bridged ) >= 1 );
$bridged_id = is_array( $row ) ? (int) $row['id'] : 0;

// ── 3) Anti-bucle: eco de LeadsBridge (_capture_origin) se ignora ─────────
$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE email_normalized = '{$email}'" );
do_action( 'estandar_lead_captured', array(
	'schema_version' => '1.0',
	'source_plugin'  => 'estandar-capture',
	'contact'        => array( 'name' => 'Echo', 'email' => $email, 'phone' => '' ),
	'meta'           => array( '_capture_origin' => 'estandar_suite_capture_event', '_capture_lead_id' => $bridged_id ),
) );
$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE email_normalized = '{$email}'" );
check( 'anti-bucle: eco con _capture_origin no duplica', $after === $before );

// ── 4) delegate_save + dedupe por identidad ───────────────────────────────
$clean = array(
	'name'            => 'Delegate User',
	'email'           => 'delegate-' . $mark . '@test.estandar.local',
	'phone'           => '56977778888',
	'email_raw'       => '',
	'phone_raw'       => '',
	'requirement'     => 'Necesito cotizar',
	'project_slug'    => 'test-project',
	'source_channel'  => 'web',
	'analytics_session_id' => 'sess-del',
	'lead_score'      => '60',
);
$del = C\ChatbotBridge::delegate_save( $clean );
check( 'delegate_save contrato status=created', is_array( $del ) && $del['status'] === 'created' );
check( 'delegate_save lead_id > 0', is_array( $del ) && (int) $del['lead_id'] > 0 );
check( 'delegate_save lead incluye capture_lead_id', is_array( $del ) && (int) ( $del['lead']['capture_lead_id'] ?? 0 ) > 0 );

$delegated_email = 'delegate-' . $mark . '@test.estandar.local';
$n_before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE email_normalized = %s", $delegated_email ) );
// Emisión posterior del MISMO lead (como hace el endpoint tras save_lead).
do_action( 'estandar_lead_captured', array(
	'schema_version' => '1.0',
	'source_plugin'  => 'estandar-chatbot',
	'contact'        => array( 'name' => 'Delegate User', 'email' => $delegated_email, 'phone' => '56977778888' ),
	'content'        => array( 'message' => 'Necesito cotizar', 'source_form_id' => 'chatbot' ),
	'context'        => array( 'source_channel' => 'web' ),
	'chatbot'        => array( 'chatbot_session_key' => 'sess-del' ),
	'meta'           => array(),
) );
$n_after = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE email_normalized = %s", $delegated_email ) );
check( 'dedupe: emisión post-delegación no duplica', $n_after === $n_before );

// ── Limpieza (solo filas de este harness) ─────────────────────────────────
$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE email_normalized LIKE %s", $mark . '%' ) );
if ( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}es_leads'" ) ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}es_leads WHERE email LIKE %s", $mark . '%' ) );
}
if ( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}es_lead_submissions'" ) ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}es_lead_submissions WHERE email LIKE %s", $mark . '%' ) );
}

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
