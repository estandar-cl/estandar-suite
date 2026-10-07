<?php
/**
 * MS4B_RUNTIME_EXTRACTION — tests de la factorización del runtime (ADR 0034, MS-4b).
 * Run: php tests/run-ms4b-runtime-extraction.php  (desde el directorio del plugin)
 *
 * Cubre: contrato de estado de sesión (SESSION_STATUS_* + session_status_label) ·
 * validate_answer_value (checkbox JSON + other_text, NPS 0-7, rating max, tipo desconocido
 * → text) · consistencia estructural: el handler delega validación y estado en el motor.
 */

define( 'ABSPATH', __DIR__ . '/' );

/* ── WP stubs ──────────────────────────────────────────────────────────── */
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ); }
function sanitize_text_field( $v ) { return is_scalar( $v ) ? trim( preg_replace( '/[\r\n\t]+/', ' ', (string) $v ) ) : ''; }
function sanitize_textarea_field( $v ) { return is_scalar( $v ) ? trim( (string) $v ) : ''; }
function sanitize_title( $v ) {
    $v = strtolower( trim( (string) $v ) );
    $v = preg_replace( '/[^a-z0-9\s\-_]/', '', $v );
    return trim( preg_replace( '/[\s_]+/', '-', $v ), '-' );
}
function remove_accents( $v ) { return (string) $v; }
function wp_json_encode( $v, $flags = 0 ) { return json_encode( $v, $flags | JSON_UNESCAPED_UNICODE ); }
function apply_filters( $tag, $value, ...$a ) { return $value; }
function add_action( ...$a ) {}
function do_action( ...$a ) {}
function get_option( $k, $d = false ) { return $d; }

require __DIR__ . '/../includes/helpers.php';
require __DIR__ . '/../includes/class-conversation-engine.php';

/* ── Harness ───────────────────────────────────────────────────────────── */
$passed = 0;
$failed = 0;
function check( string $label, bool $ok, string $detail = '' ): void {
    global $passed, $failed;
    if ( $ok ) {
        $passed++;
        echo "  PASS: {$label}\n";
    } else {
        $failed++;
        echo "  FAIL: {$label}" . ( $detail !== '' ? " ($detail)" : '' ) . "\n";
    }
}

/* ═══════════════════════════════════════════════════════════════════════
   1 · ESTADO DE SESIÓN (contrato del runtime)
   ═══════════════════════════════════════════════════════════════════════ */
echo "\n--- 1 · estado de sesión ---\n";

check( 'status started = active (contrato intacto)', Estandar_Conversation_Engine::SESSION_STATUS_STARTED === 'active' );
check( 'status completed = survey_completed (BC)', Estandar_Conversation_Engine::SESSION_STATUS_COMPLETED === 'survey_completed' );
check( 'status abandoned = survey_abandoned (BC)', Estandar_Conversation_Engine::SESSION_STATUS_ABANDONED === 'survey_abandoned' );
check( 'label completed', Estandar_Conversation_Engine::session_status_label( 'survey_completed' ) === 'completed' );
check( 'label abandoned', Estandar_Conversation_Engine::session_status_label( 'survey_abandoned' ) === 'abandoned' );
check( 'label started (active u otro)', Estandar_Conversation_Engine::session_status_label( 'active' ) === 'started' );
check( 'label desconocido → started (conservador)', Estandar_Conversation_Engine::session_status_label( 'whatever' ) === 'started' );

/* ═══════════════════════════════════════════════════════════════════════
   2 · VALIDATE_ANSWER_VALUE
   ═══════════════════════════════════════════════════════════════════════ */
echo "\n--- 2 · validate_answer_value ---\n";

// Checkbox
$cb = Estandar_Conversation_Engine::validate_answer_value( 'checkbox', '{"instalacion":1,"_other_text":"extra","otro":1}' );
check( 'checkbox: valid', $cb['valid'] === true );
$cb_decoded = json_decode( $cb['value'], true );
check( 'checkbox: checked normalizado (sin _other_text)', isset( $cb_decoded['checked'] ) && ! isset( $cb_decoded['_other_text'] ) && ! isset( $cb_decoded['instalacion'] ) );
check( 'checkbox: other_text preservado', ( $cb_decoded['other_text'] ?? '' ) === 'extra' );

$cb_empty = Estandar_Conversation_Engine::validate_answer_value( 'checkbox', 'texto-plano' );
check( 'checkbox: no-JSON → text sanitizado', $cb_empty['valid'] === true && $cb_empty['value'] === 'texto-plano' );

// NPS
$nps_ok  = Estandar_Conversation_Engine::validate_answer_value( 'nps', '7' );
$nps_bad = Estandar_Conversation_Engine::validate_answer_value( 'nps', '8' );
$nps_zero = Estandar_Conversation_Engine::validate_answer_value( 'nps', '0' );
check( 'nps: 7 válido', $nps_ok['valid'] === true );
check( 'nps: 8 rechazado', $nps_bad['valid'] === false && strpos( $nps_bad['error'], 'NPS' ) !== false );
check( 'nps: 0 válido (escala 0-7)', $nps_zero['valid'] === true );

// Rating
$r_ok  = Estandar_Conversation_Engine::validate_answer_value( 'rating', '5', [ 'max' => 5 ] );
$r_bad = Estandar_Conversation_Engine::validate_answer_value( 'rating', '6', [ 'max' => 5 ] );
$r_def = Estandar_Conversation_Engine::validate_answer_value( 'rating', '9' );
check( 'rating: 5 de 5 válido', $r_ok['valid'] === true );
check( 'rating: 6 de 5 rechazado', $r_bad['valid'] === false && strpos( $r_bad['error'], '1-5' ) !== false );
check( 'rating: sin campo max → default 5, 9 rechazado', $r_def['valid'] === false );

// Tipo desconocido → text
$u = Estandar_Conversation_Engine::validate_answer_value( 'datepicker', '2026-01-01' );
check( 'tipo desconocido → text (passthrough)', $u['valid'] === true && $u['value'] === '2026-01-01' );

// Matrix (JSON) — passthrough sin mutación
$m = Estandar_Conversation_Engine::validate_answer_value( 'matrix', '{"row1":7,"row2":5}' );
check( 'matrix: passthrough JSON', $m['valid'] === true && $m['value'] === '{"row1":7,"row2":5}' );

/* ═══════════════════════════════════════════════════════════════════════
   3 · CONSISTENCIA ESTRUCTURAL (el handler delega en el motor)
   ═══════════════════════════════════════════════════════════════════════ */
echo "\n--- 3 · consistencia estructural ---\n";

$handler = (string) file_get_contents( __DIR__ . '/../includes/class-survey-handler.php' );
check( 'handler delega validación en el motor', strpos( $handler, 'Estandar_Conversation_Engine::validate_answer_value' ) !== false );
check( 'handler usa SESSION_STATUS del motor', strpos( $handler, 'Estandar_Conversation_Engine::SESSION_STATUS_' ) !== false );
check( 'handler ya no sanitiza checkbox inline', strpos( $handler, '_other_text' ) === false || strpos( $handler, 'validate_answer_value' ) < strpos( $handler, 'Estandar_Conversation_Engine::validate_answer_value' ) );

/* ═══════════════════════════════════════════════════════════════════════
   Resultado
   ═══════════════════════════════════════════════════════════════════════ */
echo "\n=== {$passed} passed, {$failed} failed ===\n";
if ( $failed > 0 ) {
    exit( 1 );
}
exit( 0 );
