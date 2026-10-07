<?php
/**
 * ESTANDAR SUITE v2 — Capture · Flujo AJAX HTTP real de encuesta nativa (F2).
 *
 * Requiere el servidor PHP del Playground en http://localhost:8080.
 * Usa curl contra /wp-admin/admin-ajax.php con nonce real → valida el flujo
 * end-to-end: start → answer inválida → answer ×2 → complete → estado DB.
 *
 * Uso: php tests/functional/survey-ajax-http.php <wp-path> [base_url]
 */
error_reporting( E_ALL & ~E_DEPRECATED & ~E_NOTICE );

$wp_path  = rtrim( $argv[1] ?? '', '/' );
$base_url = rtrim( $argv[2] ?? 'http://localhost:8080', '/' );
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

function ajax_http( string $base_url, string $action, array $payload ): array {
	$post = http_build_query( array_merge( array( 'action' => $action ), $payload ) );
	$ch = curl_init( $base_url . '/wp-admin/admin-ajax.php' );
	curl_setopt_array( $ch, array(
		CURLOPT_POST => true,
		CURLOPT_POSTFIELDS => $post,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT => 15,
	) );
	$body = curl_exec( $ch );
	curl_close( $ch );
	$decoded = json_decode( (string) $body, true );
	return is_array( $decoded ) ? $decoded : array( 'raw' => (string) $body );
}

$id = C\Forms::create( array(
	'name' => 'F2 AJAX HTTP',
	'form_type' => 'survey',
	'form_context' => 'generic',
	'status' => 'active',
	'config_json' => wp_json_encode( array(
		'fields_json' => array(
			array( 'name' => 'nps_q', 'label' => 'NPS', 'type' => 'nps', 'required' => 1 ),
			array( 'name' => 'rating_q', 'label' => 'Rating', 'type' => 'rating', 'max' => 5, 'required' => 1 ),
		),
		'survey_config' => array( 'identification_mode' => 'anonymous' ),
	) ),
) );
check( 'form survey creado', $id > 0 );

$token = wp_generate_uuid4();
$nonce = wp_create_nonce( C\SurveyHandler::NONCE );

$start = ajax_http( $base_url, 'estandar_capture_survey_start', array(
	'nonce' => $nonce, 'form_id' => $id, 'session_token' => $token,
	'total_questions' => 2, 'identification_mode' => 'anonymous',
) );
check( 'HTTP start success', ( $start['success'] ?? false ) === true );
$sid = (int) ( $start['data']['session_id'] ?? 0 );
check( 'HTTP start session_id', $sid > 0 );

$bad = ajax_http( $base_url, 'estandar_capture_survey_answer', array(
	'nonce' => $nonce, 'form_id' => $id, 'session_token' => $token,
	'question_index' => 0, 'question_label' => 'NPS', 'question_type' => 'nps',
	'answer_value' => '9', 'answer_label' => '9', 'time_spent_seconds' => 1,
) );
check( 'HTTP answer nps=9 rechazada (422)', ( $bad['success'] ?? true ) === false );

$ok1 = ajax_http( $base_url, 'estandar_capture_survey_answer', array(
	'nonce' => $nonce, 'form_id' => $id, 'session_token' => $token,
	'question_index' => 0, 'question_label' => 'NPS', 'question_type' => 'nps',
	'answer_value' => '7', 'answer_label' => '7', 'time_spent_seconds' => 3,
) );
check( 'HTTP answer nps=7 guardada', ( $ok1['success'] ?? false ) === true );

$ok2 = ajax_http( $base_url, 'estandar_capture_survey_answer', array(
	'nonce' => $nonce, 'form_id' => $id, 'session_token' => $token,
	'question_index' => 1, 'question_label' => 'Rating', 'question_type' => 'rating',
	'answer_value' => '3', 'answer_label' => '3', 'time_spent_seconds' => 2,
) );
check( 'HTTP answer rating=3 guardada', ( $ok2['success'] ?? false ) === true );

$done = ajax_http( $base_url, 'estandar_capture_survey_complete', array(
	'nonce' => $nonce, 'form_id' => $id, 'session_id' => $sid, 'session_token' => $token,
) );
check( 'HTTP complete success', ( $done['success'] ?? false ) === true );

$sess = C\SurveyDb::session_get( $token );
check( 'sesión status=completed', ( $sess['status'] ?? '' ) === 'completed' );
global $wpdb;
$count = (int) $wpdb->get_var( $wpdb->prepare(
	'SELECT COUNT(*) FROM ' . $wpdb->prefix . C\SurveyDb::RESPONSES_TABLE . ' WHERE session_token = %s', $token
) );
check( '2 respuestas persistidas', $count === 2 );
$linked = (int) $wpdb->get_var( $wpdb->prepare(
	'SELECT COUNT(*) FROM ' . $wpdb->prefix . C\SurveyDb::RESPONSES_TABLE . ' WHERE session_token = %s AND session_id = %d', $token, $sid
) );
check( 'respuestas linkeadas a la sesión', $linked === 2 );

C\Forms::delete( $id );

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
