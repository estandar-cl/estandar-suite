<?php
/**
 * MS3_ENGINE_CAPABILITIES — tests de capacidades del motor sobre Survey (ADR 0034).
 * Run: php tests/run-ms3-engine-capabilities.php  (desde el directorio del plugin)
 *
 * Cubre: survey_flow_map (branching por nombre/índice, if_answer, if_answer_in,
 * catchall, end, goto irresoluble, sin reglas → []) · build_answers_from_responses
 * (índice→nombre) · normalize_answer_value (JSON compuesto) · completion_result
 * (scoring + completion_type, defaults).
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

$fields = [
    [ 'name' => 'objetivo', 'type' => 'cards' ],
    [ 'name' => 'credito', 'type' => 'radio' ],
    [ 'name' => 'contacto', 'type' => 'text' ],
    [ 'name' => 'fin', 'type' => 'text' ],
];

/* ═══════════════════════════════════════════════════════════════════════
   1 · SURVEY_FLOW_MAP
   ═══════════════════════════════════════════════════════════════════════ */
echo "\n--- 1 · survey_flow_map ---\n";

$config = Estandar_Conversation_Engine::normalize_config( [
    'flow' => [
        'mode' => 'branching',
        'rules' => [
            [ 'from' => 'objetivo', 'if_answer' => 'subsidio', 'goto' => 'credito' ],
            [ 'from' => 'objetivo', 'if_answer_in' => ['compra', 'venta'], 'goto' => '__end__' ],
            [ 'from' => 2, 'if_answer' => 'ok', 'goto' => 'fin' ],
            [ 'from' => 'inexistente', 'if_answer' => 'x', 'goto' => 'fin' ],
            [ 'from' => 'credito', 'goto' => 'contacto' ],
        ],
    ],
] );

$map = Estandar_Conversation_Engine::survey_flow_map( $config, $fields );

check( 'mapa con branching: entradas para índices 0,1,2', count( $map ) === 3 && isset( $map[0] ) && isset( $map[1] ) && isset( $map[2] ), 'claves: ' . implode(',', array_keys($map)) );
check( 'if_answer → goto índice 1 (credito)', $map[0]['rules'][0]['next'] === 1 );
check( 'if_answer_in → end', $map[0]['rules'][1]['next'] === 'end' );
check( 'regla catchall en índice 1 → índice 2 (contacto)', ( $map[1]['catchall'] ?? null ) === 2 );
check( 'regla con from por índice 2 → goto 3 (fin)', ( $map[2]['rules'][0]['next'] ?? null ) === 3 );
check( 'regla con from inexistente ignorada', ! isset( $map[3] ) );

$linear = Estandar_Conversation_Engine::survey_flow_map( [], $fields );
check( 'sin reglas → mapa vacío (lineal intacto)', $linear === [] );

/* ═══════════════════════════════════════════════════════════════════════
   2 · BUILD_ANSWERS_FROM_RESPONSES
   ═══════════════════════════════════════════════════════════════════════ */
echo "\n--- 2 · build_answers_from_responses ---\n";

$responses = [
    [ 'question_index' => 0, 'answer_value' => 'subsidio' ],
    [ 'question_index' => 1, 'answer_value' => '{"ds19":7,"ds49":5}' ],
    [ 'question_index' => 99, 'answer_value' => 'huerfano' ],
];
$answers = Estandar_Conversation_Engine::build_answers_from_responses( $responses, $fields );
check( 'answer index 0 → nombre objetivo', ( $answers['objetivo'] ?? '' ) === 'subsidio' );
check( 'answer index 1 → nombre credito', isset( $answers['credito'] ) );
check( 'respuesta con índice sin campo se omite', ! isset( $answers['huerfano'] ) );
check( 'JSON compuesto normalizado como string', is_string( $answers['credito'] ) && $answers['credito'][0] === '{' );

$plain = Estandar_Conversation_Engine::normalize_answer_value( '  valor simple  ' );
check( 'normalize: texto simple recortado', $plain === 'valor simple' );

/* ═══════════════════════════════════════════════════════════════════════
   3 · COMPLETION_RESULT
   ═══════════════════════════════════════════════════════════════════════ */
echo "\n--- 3 · completion_result ---\n";

$scored_cfg = Estandar_Conversation_Engine::normalize_config( [
    'scoring' => [ 'weights' => [ 'objetivo' => 10 ], 'default_result' => [ 'segment' => 'cold' ] ],
    'completion_strategy' => [ 'type' => 'lead' ],
] );
$cr = Estandar_Conversation_Engine::completion_result( $scored_cfg, [ 'objetivo' => 8 ] );
check( 'completion_type lead preservado', ( $cr['completion_type'] ?? '' ) === 'lead' );
check( 'scoring calculado', is_array( $cr['scoring'] ) && abs( ( $cr['scoring']['score'] ?? 0 ) - 80.0 ) < 0.001 );

$default_cfg = Estandar_Conversation_Engine::normalize_config( [] );
$cd = Estandar_Conversation_Engine::completion_result( $default_cfg, [] );
check( 'sin config → completion_type email (default)', ( $cd['completion_type'] ?? '' ) === 'email' );
check( 'sin scoring → scoring null', $cd['scoring'] === null );

/* ═══════════════════════════════════════════════════════════════════════
   Resultado
   ═══════════════════════════════════════════════════════════════════════ */
echo "\n=== {$passed} passed, {$failed} failed ===\n";
if ( $failed > 0 ) {
    exit( 1 );
}
exit( 0 );
