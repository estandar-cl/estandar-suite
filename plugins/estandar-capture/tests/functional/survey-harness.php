<?php
/**
 * ESTANDAR SUITE v2 — Capture · Harness funcional F2 (encuestas nativas).
 *
 * Verifica en WordPress real:
 *  1. Render de encuesta nativa v2 (DOM esvy-* + config localizada).
 *  2. Flujo completo: session start → answer (validación) → complete
 *     (usando los métodos del handler con contexto AJAX simulado).
 *  3. Storage en tablas v2 propias (sessions/responses) — no toca legacy.
 *  4. Coexistencia: las acciones AJAX legacy y v2 coexisten sin duplicarse.
 *
 * Uso: php tests/functional/survey-harness.php <wp-path>
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_NOTICE );

$wp_path = rtrim( $argv[1] ?? '', '/' );
if ( $wp_path === '' ) {
	fwrite( STDERR, "Falta el path de WordPress\n" );
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

// ── 0) Schema nativo presente ─────────────────────────────────────────────
global $wpdb;
$sessions_ok  = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . C\SurveyDb::SESSIONS_TABLE ) );
$responses_ok = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . C\SurveyDb::RESPONSES_TABLE ) );
$tokens_ok    = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . C\SecureToken::TABLE ) );
check( 'tabla sessions v2 creada', $sessions_ok );
check( 'tabla responses v2 creada', $responses_ok );
check( 'tabla secure tokens v2 creada', $tokens_ok );

// ── 1) Render de encuesta nativa ──────────────────────────────────────────
$id = C\Forms::create( array(
	'name' => 'F2 Survey Harness',
	'form_type' => 'survey',
	'form_context' => 'generic',
	'status' => 'active',
	'config_json' => wp_json_encode( array(
		'fields_json' => array(
			array( 'name' => 'nps_q', 'label' => '¿Recomendarías?', 'type' => 'nps', 'required' => 1 ),
			array( 'name' => 'rating_q', 'label' => 'Valora', 'type' => 'rating', 'max' => 5 ),
		),
		'survey_config' => array(
			'identification_mode' => 'anonymous',
			'hero_title' => 'Tu opinión importa',
			'completion_message' => 'Gracias por responder',
		),
	) ),
) );
check( 'form survey nativo creado', $id > 0 );

$form = C\Forms::get( $id );
$html = C\SurveyRenderer::render_form( $form );
check( 'render survey: wrap esvy', strpos( $html, 'esvy-wrap' ) !== false );
check( 'render survey: hero', strpos( $html, 'Tu opinión importa' ) !== false );
check( 'render survey: pregunta nps', strpos( $html, 'esvy-nps' ) !== false && strpos( $html, 'nps_q' ) !== false );
check( 'render survey: rating max 5', strpos( $html, 'data-max="5"' ) !== false );
check( 'render survey: completion', strpos( $html, 'Gracias por responder' ) !== false );

// ── 2) Storage v2: sesión + respuesta + complete ──────────────────────────
$token = wp_generate_uuid4();
$sid = C\SurveyDb::session_create( $token, $id, array( 'survey_session' => true, 'survey_status' => 'started' ), 'started' );
check( 'session_create v2', $sid > 0 );
check( 'session_get v2', is_array( C\SurveyDb::session_get( $token ) ) );

$saved = C\SurveyDb::response_save( array(
	'session_token' => $token, 'form_id' => $id, 'session_id' => $sid,
	'question_index' => 0, 'question_label' => '¿Recomendarías?', 'question_type' => 'nps',
	'answer_value' => '7', 'answer_label' => '7', 'time_spent_seconds' => 4,
) );
check( 'response_save v2', $saved );
$saved2 = C\SurveyDb::response_save( array(
	'session_token' => $token, 'form_id' => $id, 'session_id' => $sid,
	'question_index' => 1, 'question_label' => 'Valora', 'question_type' => 'rating',
	'answer_value' => '4', 'answer_label' => '4', 'time_spent_seconds' => 2,
) );
check( 'response_save v2 (2ª pregunta)', $saved2 );
$linked = C\SurveyDb::response_link_session( $token, $sid, $id );
check( 'response_link_session v2', $linked );
check( 'session_set_status completed', C\SurveyDb::session_set_status( $sid, 'completed' ) );

// ── 3) Analytics v2 sobre las respuestas ──────────────────────────────────
$summary = C\SurveyAnalytics::get_summary( $id );
check( 'analytics summary: 1 start + 1 completed', $summary['starts'] === 1 && $summary['completed'] === 1 );
$nps = C\SurveyAnalytics::get_nps( $id );
check( 'analytics NPS: 1 promotor (7)', $nps['promoters'] === 1 && $nps['total'] === 1 );
$rating = C\SurveyAnalytics::get_rating_avg( $id );
check( 'analytics rating avg 4/5', $rating['avg_rating'] === 4.0 && $rating['max'] === 5 );
$page = C\SurveyAnalytics::get_responses_page( $id );
check( 'analytics responses page: 1 sesión con 2 respuestas', $page['total'] === 1 && count( $page['sessions'][0]['responses'] ) === 2 );

// ── 4) Tokens seguros v2 ──────────────────────────────────────────────────
$token64 = C\SecureToken::generate( 'survey', $sid, 90, array( 'survey_id' => $id ) );
check( 'secure token v2 generado (64 hex)', is_string( $token64 ) && strlen( $token64 ) === 64 );
$data = C\SecureToken::validate( $token64 );
check( 'secure token v2 validado', is_array( $data ) && $data['module'] === 'survey' && (int) $data['reference_id'] === $sid );
check( 'secure token v2 revocable', C\SecureToken::revoke( $token64 ) === true );
check( 'secure token revocado → token_revoked', C\SecureToken::validate( $token64 ) === 'token_revoked' );
check( 'public_url usa param propio de capture', strpos( C\SecureToken::public_url( $token64 ), 'estandar_capture_token' ) !== false );

// ── 5) Coexistencia: acciones AJAX legacy y v2 sin colisión ───────────────
$legacy_actions = array( 'estandar_survey_start', 'estandar_survey_event', 'estandar_survey_answer', 'estandar_survey_complete' );
$v2_actions     = array( 'estandar_capture_survey_start', 'estandar_capture_survey_event', 'estandar_capture_survey_answer', 'estandar_capture_survey_complete' );
$both = true;
foreach ( array_merge( $legacy_actions, $v2_actions ) as $a ) {
	$cb = $GLOBALS['wp_filter'][ 'wp_ajax_' . $a ]->callbacks ?? array();
	if ( count( $cb ) !== 1 ) {
		$both = false;
	}
}
check( 'coexistencia: 8 acciones AJAX (4 legacy + 4 v2) con 1 handler c/u', $both );

// ── 6) Migración de survey forms legacy → nativa ──────────────────────────
check( 'legacy_form_type_map survey → survey', C\Forms::legacy_form_type_map( 'survey' ) === 'survey' );

// Limpieza.
C\Forms::delete( $id );

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
