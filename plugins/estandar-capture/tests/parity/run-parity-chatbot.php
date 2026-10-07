<?php
/**
 * ESTANDAR SUITE v2 — Capture · ORÁCULO DE PARIDAD Chatbot legacy ↔ capture v2
 *
 * La matriz que es EL definition of done de la fusión estandar-chatbot →
 * estandar-capture (roadmap sesión 007). Cada fila declara un estado
 * (native / partial / missing) y un *probe* que verifica la realidad del
 * código; el test falla si la declaración no coincide con la realidad.
 * Eso impide que el oráculo se vuelva mentira.
 *
 *   php tests/parity/run-parity-chatbot.php        → reporte + exit 0
 *   php tests/parity/run-parity-chatbot.php --gate → exit 1 hasta que TODO esté native
 *
 * Regla de retiro (F5): el chatbot puede pasar a pipeline=Capture (dejar de
 * escribir ecb_leads) solo cuando --gate pasa (100% de las filas native).
 */

define( 'ABSPATH', __DIR__ );
define( 'PARITY_ROOT', dirname( __DIR__, 2 ) ); // raíz del plugin estandar-capture
define( 'CHATBOT_ROOT', dirname( PARITY_ROOT ) . '/estandar-chatbot' );
$_SERVER['HTTP_REFERER'] = '';
$_SERVER['REMOTE_ADDR']  = '127.0.0.1';
$GLOBALS['__test_options'] = array();
if ( ! function_exists( 'sanitize_key' ) ) { function sanitize_key( $k ) { return strtolower( (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ) ); } }
if ( ! function_exists( 'sanitize_title' ) ) { function sanitize_title( $t ) { return strtolower( (string) preg_replace( '/[^a-z0-9\-_]/', '-', strtolower( trim( (string) $t ) ) ) ); } }
if ( ! function_exists( 'current_time' ) ) { function current_time( $t ) { return gmdate( 'Y-m-d H:i:s' ); } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $t ) { return trim( (string) strip_tags( (string) $t ) ); } }
if ( ! function_exists( 'sanitize_textarea_field' ) ) { function sanitize_textarea_field( $t ) { return trim( (string) $t ); } }
if ( ! function_exists( 'sanitize_email' ) ) { function sanitize_email( $e ) { $e = trim( (string) $e ); return is_email( $e ) ? strtolower( $e ) : ''; } }
if ( ! function_exists( 'esc_url_raw' ) ) { function esc_url_raw( $u ) { return (string) $u; } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $u ) { return (string) $u; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_js' ) ) { function esc_js( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); } }
if ( ! function_exists( 'is_email' ) ) { function is_email( $e ) { return filter_var( (string) $e, FILTER_VALIDATE_EMAIL ) !== false; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $v ) { return json_encode( $v, JSON_UNESCAPED_UNICODE ); } }
if ( ! function_exists( 'wp_generate_uuid4' ) ) { function wp_generate_uuid4() { return sprintf( '%04x%04x-%04x-4%03x-%04x-%04x%04x%04x', mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0x0fff ), mt_rand( 0, 0x3fff ) | 0x8000, mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ) ); } }
if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $tag, $value ) { return $value; } }
if ( ! function_exists( 'do_action' ) ) { function do_action() {} }
if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
if ( ! function_exists( 'add_shortcode' ) ) { function add_shortcode() {} }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() {} }
if ( ! function_exists( 'get_option' ) ) { function get_option( $k, $d = null ) { return array_key_exists( $k, $GLOBALS['__test_options'] ) ? $GLOBALS['__test_options'][ $k ] : $d; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( $k, $v, $a = null ) { $GLOBALS['__test_options'][ $k ] = $v; return true; } }

// Load capture (misma base que run-capture.php) + módulos de fusión chatbot.
require PARITY_ROOT . '/includes/Ingestion.php';
require PARITY_ROOT . '/includes/ChatbotBridge.php';
require PARITY_ROOT . '/includes/LeadsBridge.php';
require PARITY_ROOT . '/includes/ChatbotAdoption.php';
require PARITY_ROOT . '/includes/Forms.php';
require PARITY_ROOT . '/includes/Admin.php';

use Estandar\Capture as C;

// ── Helper: inspección estática de fuentes (no requiere WP) ────────────────
$GLOBALS['__src_cache'] = array();
function src_has( string $file, string $needle ): bool {
	$abs = PARITY_ROOT . '/includes/' . $file;
	if ( ! isset( $GLOBALS['__src_cache'][ $abs ] ) ) {
		$GLOBALS['__src_cache'][ $abs ] = (string) @file_get_contents( $abs );
	}
	return $GLOBALS['__src_cache'][ $abs ] !== '' && strpos( $GLOBALS['__src_cache'][ $abs ], $needle ) !== false;
}
function chat_src_has( string $file, string $needle ): bool {
	$abs = CHATBOT_ROOT . '/' . $file;
	if ( ! isset( $GLOBALS['__src_cache'][ $abs ] ) ) {
		$GLOBALS['__src_cache'][ $abs ] = (string) @file_get_contents( $abs );
	}
	return $GLOBALS['__src_cache'][ $abs ] !== '' && strpos( $GLOBALS['__src_cache'][ $abs ], $needle ) !== false;
}
/** Lee archivos en la RAÍZ del plugin capture (ej: estandar-capture.php). */
function root_src_has( string $file, string $needle ): bool {
	$abs = PARITY_ROOT . '/' . $file;
	if ( ! isset( $GLOBALS['__src_cache'][ $abs ] ) ) {
		$GLOBALS['__src_cache'][ $abs ] = (string) @file_get_contents( $abs );
	}
	return $GLOBALS['__src_cache'][ $abs ] !== '' && strpos( $GLOBALS['__src_cache'][ $abs ], $needle ) !== false;
}

/**
 * Status esperado de los checks del chatbot LEGACY (FUSION V1).
 * El plugin legacy `estandar-chatbot` fue empaquetado y retirado del set
 * ("NO USAR; SON LEGACY"). Cuando su fuente no está presente, la realidad es
 * 'missing' (el hook de fusión no existe) y el oráculo debe reflejarlo, no
 * fallar. Si el legacy vuelve a estar presente, se vuelve a exigir 'native'.
 */
function legacy_chatbot_status(): string {
	return is_dir( CHATBOT_ROOT ) ? 'native' : 'missing';
}

// ── MATRIZ DE PARIDAD ───────────────────────────────────────────────────────
$matrix = array();

function add_row( array &$m, string $id, string $area, string $type, string $option, string $status, int $weight, callable $probe ): void {	$m[] = array(
		'id'     => $id,
		'area'   => $area,
		'type'   => $type,
		'option' => $option,
		'status' => $status,
		'weight' => $weight,
		'probe'  => $probe,
	);
}

// ── A · ChatbotBridge (arista de fusión) ─────────────────────────────────
add_row( $matrix, 'cb.bridge.file', 'Bridge', 'chatbot', 'módulo ChatbotBridge.php existe', 'native', 2,
	fn() => class_exists( 'Estandar\Capture\ChatbotBridge' ) ? 'native' : 'missing' );
add_row( $matrix, 'cb.bridge.hook', 'Bridge', 'chatbot', 'hook estandar_lead_captured (prio 9)', 'native', 3,
	fn() => src_has( 'ChatbotBridge.php', "add_action( 'estandar_lead_captured', array( __CLASS__, 'bridge_lead' ), 9, 1 )" ) ? 'native' : 'missing' );
add_row( $matrix, 'cb.bridge.map', 'Bridge', 'chatbot', 'map_payload (payload legacy → raw ingest)', 'native', 3,
	fn() => method_exists( 'Estandar\Capture\ChatbotBridge', 'map_payload' ) ? 'native' : 'missing' );
add_row( $matrix, 'cb.bridge.ingest', 'Bridge', 'chatbot', 'bridge_lead → Ingestion::ingest(source=chatbot)', 'native', 4,
	fn() => src_has( 'ChatbotBridge.php', "Ingestion::ingest( \$raw )" ) && src_has( 'ChatbotBridge.php', "const SOURCE = 'chatbot';" ) ? 'native' : 'partial' );
add_row( $matrix, 'cb.bridge.loopguard', 'Bridge', 'chatbot', 'anti-bucle: ignora payloads con meta._capture_origin', 'native', 3,
	fn() => src_has( 'ChatbotBridge.php', "_capture_origin" ) ? 'native' : 'missing' );
add_row( $matrix, 'cb.bridge.dedupe', 'Bridge', 'chatbot', 'dedupe por identidad (contact_key) en modo capture', 'native', 3,
	fn() => method_exists( 'Estandar\Capture\ChatbotBridge', 'contact_key' ) && src_has( 'ChatbotBridge.php', 'self::$delegated' ) ? 'native' : 'partial' );
add_row( $matrix, 'cb.bridge.delegate', 'Bridge', 'chatbot', 'delegate_save (contrato status created|error)', 'native', 4,
	fn() => method_exists( 'Estandar\Capture\ChatbotBridge', 'delegate_save' ) && src_has( 'ChatbotBridge.php', "'status'  => \$id > 0 ? 'created' : 'error'" ) ? 'native' : 'partial' );
add_row( $matrix, 'cb.bridge.event', 'Bridge', 'chatbot', 'emite estandar_suite_chatbot_lead_bridged', 'native', 2,
	fn() => src_has( 'ChatbotBridge.php', 'estandar_suite_chatbot_lead_bridged' ) ? 'native' : 'missing' );

// ── B · LeadsBridge (puente opt-in → estandar-leads) ─────────────────────
add_row( $matrix, 'cb.leads.file', 'LeadsBridge', 'chatbot', 'módulo LeadsBridge.php existe', 'native', 2,
	fn() => class_exists( 'Estandar\Capture\LeadsBridge' ) ? 'native' : 'missing' );
add_row( $matrix, 'cb.leads.optin', 'LeadsBridge', 'chatbot', 'option opt-in estandar_suite_capture_leads_bridge', 'native', 2,
	fn() => defined( 'Estandar\Capture\LeadsBridge::OPTION' ) || src_has( 'LeadsBridge.php', "const OPTION = 'estandar_suite_capture_leads_bridge'" ) ? 'native' : 'missing' );
add_row( $matrix, 'cb.leads.hook', 'LeadsBridge', 'chatbot', 'hook estandar_suite_capture_event (prio 20)', 'native', 3,
	fn() => src_has( 'LeadsBridge.php', "add_action( 'estandar_suite_capture_event', array( __CLASS__, 'on_capture_event' ), 20, 1 )" ) ? 'native' : 'missing' );
add_row( $matrix, 'cb.leads.skip_chat', 'LeadsBridge', 'chatbot', 'NO re-emite source=chatbot (evita doble es_leads)', 'native', 3,
	fn() => src_has( 'LeadsBridge.php', "if ( \$source === '' || \$source === 'chatbot' )" ) ? 'native' : 'missing' );
add_row( $matrix, 'cb.leads.skip_identity', 'LeadsBridge', 'chatbot', 'NO re-emite sin identidad (email/phone)', 'native', 2,
	fn() => src_has( 'LeadsBridge.php', 'email_normalized' ) && src_has( 'LeadsBridge.php', 'phone_normalized' ) ? 'native' : 'partial' );
add_row( $matrix, 'cb.leads.loopguard', 'LeadsBridge', 'chatbot', 'guard estático $emitting anti-bucle', 'native', 3,
	fn() => src_has( 'LeadsBridge.php', 'private static $emitting' ) ? 'native' : 'missing' );
add_row( $matrix, 'cb.leads.payload', 'LeadsBridge', 'chatbot', 'build_payload (contrato legacy + meta._capture_origin)', 'native', 3,
	fn() => method_exists( 'Estandar\Capture\LeadsBridge', 'build_payload' ) && src_has( 'LeadsBridge.php', '_capture_lead_id' ) ? 'native' : 'partial' );
add_row( $matrix, 'cb.leads.detect', 'LeadsBridge', 'chatbot', 'detecta estandar-leads activo (ESTANDAR_LEADS_VERSION)', 'native', 2,
	fn() => src_has( 'LeadsBridge.php', 'ESTANDAR_LEADS_VERSION' ) ? 'native' : 'missing' );

// ── C · ChatbotAdoption (ecb_leads → submissions) ────────────────────────
add_row( $matrix, 'cb.adopt.file', 'Adoption', 'chatbot', 'módulo ChatbotAdoption.php existe', 'native', 2,
	fn() => class_exists( 'Estandar\Capture\ChatbotAdoption' ) ? 'native' : 'missing' );
add_row( $matrix, 'cb.adopt.table', 'Adoption', 'chatbot', 'legacy ecb_leads (data safety gate: solo lectura)', 'native', 3,
	fn() => defined( 'Estandar\Capture\ChatbotAdoption::LEGACY_LEADS' ) || src_has( 'ChatbotAdoption.php', "const LEGACY_LEADS = 'ecb_leads'" ) ? 'native' : 'missing' );
add_row( $matrix, 'cb.adopt.project', 'Adoption', 'chatbot', 'project_lead (decodifica data JSON → proyección v2)', 'native', 3,
	fn() => method_exists( 'Estandar\Capture\ChatbotAdoption', 'project_lead' ) ? 'native' : 'missing' );
add_row( $matrix, 'cb.adopt.import', 'Adoption', 'chatbot', 'import first-writer-wins por origin_lead_id + source=chatbot', 'native', 4,
	fn() => src_has( 'ChatbotAdoption.php', 'origin_lead_id' ) && src_has( 'ChatbotAdoption.php', "AND source = %s" ) ? 'native' : 'partial' );
add_row( $matrix, 'cb.adopt.rollback', 'Adoption', 'chatbot', 'rollback SOLO adoptados (marker)', 'native', 3,
	fn() => method_exists( 'Estandar\Capture\ChatbotAdoption', 'rollback' ) && src_has( 'ChatbotAdoption.php', 'origin_lead_id > 0 AND source = %s' ) ? 'native' : 'partial' );
add_row( $matrix, 'cb.adopt.counts', 'Adoption', 'chatbot', 'counts (legacy/adoptados/total source=chatbot)', 'native', 2,
	fn() => method_exists( 'Estandar\Capture\ChatbotAdoption', 'counts' ) ? 'native' : 'missing' );

// ── D · Admin / CLI / UI ─────────────────────────────────────────────────
add_row( $matrix, 'cb.admin.group', 'Admin', 'chatbot', 'grupo fusion en chatbot_settings_groups', 'native', 3,
	fn() => array_key_exists( 'fusion', C\Admin::chatbot_settings_groups() ) ? 'native' : 'missing' );
add_row( $matrix, 'cb.admin.pipeline', 'Admin', 'chatbot', 'pipeline_mode legacy|capture gestionado', 'native', 3,
	fn() => method_exists( 'Estandar\Capture\Admin', 'chatbot_pipeline_mode' ) ? 'native' : 'missing' );
add_row( $matrix, 'cb.admin.adopt_handlers', 'Admin', 'chatbot', 'admin_post adopción import/rollback', 'native', 3,
	fn() => src_has( 'Admin.php', 'estandar_suite_capture_chatbot_adoption_import' ) && src_has( 'Admin.php', 'estandar_suite_capture_chatbot_adoption_rollback' ) ? 'native' : 'missing' );
add_row( $matrix, 'cb.admin.data', 'Admin', 'chatbot', 'chatbot_fusion_data (estado del puente)', 'native', 2,
	fn() => method_exists( 'Estandar\Capture\Admin', 'chatbot_fusion_data' ) ? 'native' : 'missing' );
 add_row( $matrix, 'cb.admin.tab', 'Admin', 'chatbot', 'sub-tab Fusión en catálogo chatbot_ssubs (fuente única)', 'native', 3,
 	fn() => method_exists( 'Estandar\Capture\Admin', 'chatbot_ssubs' ) && isset( \Estandar\Capture\Admin::chatbot_ssubs()['fusion'] ) ? 'native' : 'missing' );
add_row( $matrix, 'cb.admin.cli', 'Admin', 'chatbot', 'comandos CLI bridge/adoption', 'native', 2,
	fn() => src_has( 'Cli.php', 'chatbot bridge status' ) && src_has( 'Cli.php', 'chatbot adoption import' ) ? 'native' : 'missing' );

// ── E · Chatbot legacy: hook opt-in (FUSION V1) ──────────────────────────
// Status dinámico: el legacy fue retirado del set → 'missing' si no está.
add_row( $matrix, 'cb.chat.helper', 'Chatbot', 'chatbot', 'helper estandar_chatbot_lead_pipeline_is_capture', legacy_chatbot_status(), 3,
	fn() => chat_src_has( 'includes/leads-handler.php', 'function estandar_chatbot_lead_pipeline_is_capture' ) ? 'native' : 'missing' );
add_row( $matrix, 'cb.chat.branch', 'Chatbot', 'chatbot', 'save_lead delega a ChatbotBridge en modo capture', legacy_chatbot_status(), 4,
	fn() => chat_src_has( 'includes/leads-handler.php', "ChatbotBridge::delegate_save" ) ? 'native' : 'missing' );
add_row( $matrix, 'cb.chat.fallback', 'Chatbot', 'chatbot', 'fallback silencioso a legacy si Capture falla', legacy_chatbot_status(), 3,
	fn() => chat_src_has( 'includes/leads-handler.php', 'Fallback silencioso a legacy' ) ? 'native' : 'missing' );
add_row( $matrix, 'cb.chat.default', 'Chatbot', 'chatbot', 'default legacy = comportamiento byte-idéntico', legacy_chatbot_status(), 4,
	fn() => chat_src_has( 'includes/leads-handler.php', "return isset( \$cur['pipeline_mode'] ) && \$cur['pipeline_mode'] === 'capture';" ) ? 'native' : 'missing' );
add_row( $matrix, 'cb.chat.storage', 'Chatbot', 'chatbot', 'almacenamiento ecb_leads intacto en modo legacy', legacy_chatbot_status(), 3,
	fn() => chat_src_has( 'includes/leads-handler.php', 'estandar_chatbot_get_leads_table()' ) ? 'native' : 'missing' );

// ── F · Wiring (bootstrap capture) ───────────────────────────────────────
add_row( $matrix, 'cb.wire.require', 'Wiring', 'chatbot', 'require de los 3 módulos de fusión', 'native', 3,
	fn() => root_src_has( 'estandar-capture.php', "includes/ChatbotBridge.php" ) && root_src_has( 'estandar-capture.php', "includes/LeadsBridge.php" ) && root_src_has( 'estandar-capture.php', "includes/ChatbotAdoption.php" ) ? 'native' : 'missing' );
add_row( $matrix, 'cb.wire.init', 'Wiring', 'chatbot', 'init() de ChatbotBridge y LeadsBridge en plugins_loaded', 'native', 3,
	fn() => root_src_has( 'estandar-capture.php', "ChatbotBridge::init();" ) && root_src_has( 'estandar-capture.php', "LeadsBridge::init();" ) ? 'native' : 'missing' );

// ── RUN ────────────────────────────────────────────────────────────────────
$gate = in_array( '--gate', $argv, true );
$passed = 0; $failed = 0;
$by_area = array();
$weighted_total = 0; $weighted_native = 0;

foreach ( $matrix as $row ) {
	$actual = (string) $row['probe']();
	$weighted_total += $row['weight'];
	if ( $row['status'] === 'native' ) {
		$weighted_native += $row['weight'];
	}
	$by_area[ $row['area'] ]['total'] = ( $by_area[ $row['area'] ]['total'] ?? 0 ) + 1;
	$by_area[ $row['area'] ][ $row['status'] ] = ( $by_area[ $row['area'] ][ $row['status'] ] ?? 0 ) + 1;
	$by_area[ $row['area'] ]['weighted_total'] = ( $by_area[ $row['area'] ]['weighted_total'] ?? 0 ) + $row['weight'];
	if ( $row['status'] === 'native' ) {
		$by_area[ $row['area'] ]['weighted_native'] = ( $by_area[ $row['area'] ]['weighted_native'] ?? 0 ) + $row['weight'];
	}

	$ok = ( $actual === $row['status'] );
	$in_gate = $gate && $row['status'] !== 'native';
	if ( $ok && ! $in_gate ) {
		$passed++;
		echo 'PASS  [' . str_pad( $row['id'], 28 ) . '] ' . str_pad( $row['status'], 7 ) . ' · ' . $row['type'] . ' · ' . $row['option'] . "\n";
	} else {
		$failed++;
		$reason = $gate && $row['status'] !== 'native' ? 'gate: pendiente (' . $row['status'] . ')' : 'oráculo desincronizado: realidad=' . $actual;
		echo 'FAIL  [' . str_pad( $row['id'], 28 ) . '] declarado=' . str_pad( $row['status'], 7 ) . ' · ' . $row['option'] . ' → ' . $reason . "\n";
	}
}

$pct = $weighted_total > 0 ? round( 100 * $weighted_native / $weighted_total, 1 ) : 0;

echo "\n=== RESUMEN POR ÁREA ===\n";
echo str_pad( 'Área', 12 ) . str_pad( 'filas', 6 ) . str_pad( 'native', 8 ) . str_pad( 'partial', 8 ) . str_pad( 'missing', 8 ) . "ponderado\n";
foreach ( $by_area as $area => $a ) {
	$apct = ! empty( $a['weighted_total'] ) ? round( 100 * ( $a['weighted_native'] ?? 0 ) / $a['weighted_total'], 1 ) : 0;
	echo str_pad( $area, 12 ) . str_pad( (string) $a['total'], 6 ) . str_pad( (string) ( $a['native'] ?? 0 ), 8 )
		. str_pad( (string) ( $a['partial'] ?? 0 ), 8 ) . str_pad( (string) ( $a['missing'] ?? 0 ), 8 ) . $apct . "%\n";
}

echo "\nParidad ponderada global: {$weighted_native}/{$weighted_total} = {$pct}% (oracle)\n";
echo $gate ? '(--gate: retiro de chatbot exige 100% native)\n' : '';
echo "\n{$passed} passed, {$failed} failed\n";

if ( $gate ) {
	echo $failed === 0 ? 'GATE PARITY: READY → el chatbot puede pasar a pipeline=Capture.\n' : 'GATE PARITY: NOT READY.\n';
}
exit( $failed > 0 ? 1 : 0 );
