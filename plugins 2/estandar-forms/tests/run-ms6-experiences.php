<?php
/**
 * MS6_EXPERIENCES — catálogo de experiencias + builder universal + matrix (ADR 0034).
 * Run: php tests/run-ms6-experiences.php  (desde el directorio del plugin)
 *
 * Cubre: validación de matriz (Evaluación múltiple — cada score en 1..scale) ·
 * catálogo de experiencias (Survey, Lead Qualification, Postventa, Cotización,
 * Wizard, Assessment, Quiz, Booking) · builder universal (selector de tipo +
 * editor conversation_config) · el mismo runtime para todas.
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
   1 · EVALUACIÓN MÚLTIPLE (matrix) — validación de rango
   ═══════════════════════════════════════════════════════════════════════ */
echo "\n--- 1 · matrix ---\n";

$ok = Estandar_Conversation_Engine::validate_answer_value( 'matrix', '{"row1":7,"row2":5}', [ 'scale' => 7 ] );
check( 'matrix: valores 7 y 5 en escala 7 válidos', $ok['valid'] === true );

$bad = Estandar_Conversation_Engine::validate_answer_value( 'matrix', '{"row1":8}', [ 'scale' => 7 ] );
check( 'matrix: 8 fuera de escala 7 rechazado', $bad['valid'] === false && strpos( $bad['error'], '1-7' ) !== false );

$zero = Estandar_Conversation_Engine::validate_answer_value( 'matrix', '{"row1":0}', [ 'scale' => 7 ] );
check( 'matrix: 0 fuera de rango rechazado', $zero['valid'] === false );

$empty = Estandar_Conversation_Engine::validate_answer_value( 'matrix', '' );
check( 'matrix: vacío válido (puede omitirse)', $empty['valid'] === true );

$default_scale = Estandar_Conversation_Engine::validate_answer_value( 'matrix', '{"r":7}' );
check( 'matrix: sin scale → default 7, 7 válido', $default_scale['valid'] === true );

/* ═══════════════════════════════════════════════════════════════════════
   2 · CATÁLOGO DE EXPERIENCIAS (mismo runtime)
   ═══════════════════════════════════════════════════════════════════════ */
echo "\n--- 2 · catálogo ---\n";

$expected = [ 'survey', 'lead_qualification', 'postventa', 'cotizacion', 'wizard', 'assessment', 'quiz', 'booking' ];
$catalog  = Estandar_Conversation_Engine::conversational_form_types();
$all_present = true;
foreach ( $expected as $e ) {
    if ( ! in_array( $e, $catalog, true ) ) {
        $all_present = false;
    }
}
check( 'catálogo contiene las 8 experiencias', $all_present, implode( ',', $catalog ) );
$all_conv = true;
foreach ( $expected as $e ) {
    if ( ! Estandar_Conversation_Engine::is_conversational( $e ) ) {
        $all_conv = false;
    }
}
check( 'todas son conversacionales (mismo runtime)', $all_conv );

/* ═══════════════════════════════════════════════════════════════════════
   3 · BUILDER UNIVERSAL
   ═══════════════════════════════════════════════════════════════════════ */
echo "\n--- 3 · builder universal ---\n";

$builder = (string) file_get_contents( __DIR__ . '/../admin/screens/survey-edit.php' );
check( 'builder ofrece selector de experiencia', strpos( $builder, 'experience_type' ) !== false );
check( 'builder ofrece lead_qualification', strpos( $builder, 'lead_qualification' ) !== false );
check( 'builder edita conversation_config_json', strpos( $builder, 'conversation_config_json' ) !== false );
check( 'builder persiste conversation_config', strpos( $builder, "data['conversation_config']" ) !== false );

/* ═══════════════════════════════════════════════════════════════════════
   Resultado
   ═══════════════════════════════════════════════════════════════════════ */
echo "\n=== {$passed} passed, {$failed} failed ===\n";
if ( $failed > 0 ) {
    exit( 1 );
}
exit( 0 );
