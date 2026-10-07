<?php
/**
 * MS4D_EXPERIENCE_DECOUPLING — tests de desacoplamiento del runtime de Survey (ADR 0034).
 * Run: php tests/run-ms4d-experience-decoupling.php  (desde el directorio del plugin)
 *
 * Verifica: flow_map() como método canónico genérico (survey_flow_map = alias BC) ·
 * el runtime del motor NO tiene acoplamiento nominal a Survey salvo el alias ·
 * el render usa flow_map.
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
    [ 'name' => 'contacto', 'type' => 'text' ],
];
$config = Estandar_Conversation_Engine::normalize_config( [
    'flow' => [ 'rules' => [ [ 'from' => 'objetivo', 'if_answer' => 'subsidio', 'goto' => 'contacto' ] ] ],
] );

/* ═══════════════════════════════════════════════════════════════════════
   1 · FLOW_MAP genérico + alias BC
   ═══════════════════════════════════════════════════════════════════════ */
echo "\n--- 1 · flow_map ---\n";

$generic = Estandar_Conversation_Engine::flow_map( $config, $fields );
$alias   = Estandar_Conversation_Engine::survey_flow_map( $config, $fields );
check( 'flow_map devuelve regla resuelta', ( $generic[0]['rules'][0]['next'] ?? null ) === 1 );
check( 'survey_flow_map es alias de flow_map', $generic === $alias );
check( 'sin reglas → []', Estandar_Conversation_Engine::flow_map( [], $fields ) === [] );

/* ═══════════════════════════════════════════════════════════════════════
   2 · RUNTIME SIN ACOPLAMIENTO NOMINAL A SURVEY
   ═══════════════════════════════════════════════════════════════════════ */
echo "\n--- 2 · runtime sin acoplamiento ---\n";

$engine = (string) file_get_contents( __DIR__ . '/../includes/class-conversation-engine.php' );

// Métodos públicos del motor (público + static function).
preg_match_all( '/public static function\s+(\w+)\s*\(/', $engine, $m );
$methods = $m[1] ?? [];

$survey_named = array_values( array_filter( $methods, static function ( $meth ) {
    return stripos( $meth, 'survey' ) !== false;
} ) );
check( 'solo el alias survey_flow_map conserva el nombre survey', $survey_named === [ 'survey_flow_map' ], implode( ',', $survey_named ) );

// Ninguna constante de runtime con 'survey' en el NOMBRE salvo EVENT_SURVEY_COMPLETED
// (evento de experiencia legítimo, contrato público). SESSION_STATUS_* ya son genéricos.
preg_match_all( '/const\s+(\w+)\s*=/', $engine, $cm );
$consts = $cm[1] ?? [];
$survey_consts = array_values( array_filter( $consts, static function ( $c ) {
    return stripos( $c, 'survey' ) !== false;
} ) );
check( 'constantes de runtime survey-named solo EVENT_SURVEY_COMPLETED', $survey_consts === [ 'EVENT_SURVEY_COMPLETED' ], implode( ',', $survey_consts ) );

/* ═══════════════════════════════════════════════════════════════════════
   3 · EL RENDER USA FLOW_MAP (no survey_flow_map)
   ═══════════════════════════════════════════════════════════════════════ */
echo "\n--- 3 · render ---\n";

$render = (string) file_get_contents( __DIR__ . '/../public/survey-render.php' );
check( 'render usa Estandar_Conversation_Engine::flow_map', strpos( $render, 'Estandar_Conversation_Engine::flow_map' ) !== false );
check( 'render NO llama survey_flow_map', strpos( $render, 'Estandar_Conversation_Engine::survey_flow_map' ) === false );

/* ═══════════════════════════════════════════════════════════════════════
   Resultado
   ═══════════════════════════════════════════════════════════════════════ */
echo "\n=== {$passed} passed, {$failed} failed ===\n";
if ( $failed > 0 ) {
    exit( 1 );
}
exit( 0 );
