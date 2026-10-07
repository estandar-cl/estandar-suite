<?php
/**
 * MS2_ENGINE_CONTRACT — tests del contrato del Conversation Engine (ADR 0034).
 * Run: php tests/run-ms2-engine-contract.php  (desde el directorio del plugin)
 *
 * Cubre: taxonomía de experiencias · constantes de eventos de dominio ·
 * normalize_config (flow/scoring/completion/identification) · fuente de config
 * por tipo (survey_config vs conversation_config) · resolve_next_step
 * (reglas + fallback lineal) · evaluate_scoring (pesos + resultado + default).
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
   1 · TAXONOMÍA + EVENTOS
   ═══════════════════════════════════════════════════════════════════════ */
echo "\n--- 1 · taxonomía y eventos ---\n";

check( 'survey es experiencia conversacional', Estandar_Conversation_Engine::is_conversational( 'survey' ) );
check( 'lead_qualification es conversacional', Estandar_Conversation_Engine::is_conversational( 'lead_qualification' ) );
check( 'booking es conversacional', Estandar_Conversation_Engine::is_conversational( 'booking' ) );
check( 'lead NO es conversacional (pipeline síncrono)', ! Estandar_Conversation_Engine::is_conversational( 'lead' ) );
check( 'vacío NO es conversacional', ! Estandar_Conversation_Engine::is_conversational( '' ) );

$events = [
    Estandar_Conversation_Engine::EVENT_STARTED,
    Estandar_Conversation_Engine::EVENT_STEP_PRESENTED,
    Estandar_Conversation_Engine::EVENT_ANSWER_VALIDATED,
    Estandar_Conversation_Engine::EVENT_ANSWER_SAVED,
    Estandar_Conversation_Engine::EVENT_COMPLETED,
    Estandar_Conversation_Engine::EVENT_SURVEY_COMPLETED,
    Estandar_Conversation_Engine::EVENT_LEAD_QUALIFIED,
    Estandar_Conversation_Engine::EVENT_QUOTE_GENERATED,
    Estandar_Conversation_Engine::EVENT_BOOKING_REQUESTED,
    Estandar_Conversation_Engine::EVENT_POST_SALE_COMPLETED,
    Estandar_Conversation_Engine::EVENT_ASSESSMENT_COMPLETED,
];
$all_prefixed = true;
foreach ( $events as $e ) {
    if ( strpos( $e, 'estandar_' ) !== 0 ) {
        $all_prefixed = false;
    }
}
check( 'constantes de eventos definidas y con prefijo estandar_', count( $events ) === 11 && $all_prefixed );

/* ═══════════════════════════════════════════════════════════════════════
   2 · NORMALIZE_CONFIG
   ═══════════════════════════════════════════════════════════════════════ */
echo "\n--- 2 · normalize_config ---\n";

$cfg = Estandar_Conversation_Engine::normalize_config( wp_json_encode( [
    'flow' => [
        'mode'  => 'branching',
        'start' => 'step_1',
        'steps' => [
            [ 'id' => 'step_1', 'widget' => 'cards', 'fields' => ['objetivo'], 'next' => 'step_2' ],
            [ 'id' => 'step_2', 'widget' => 'text',  'fields' => ['detalle'], 'visible_when' => [ 'step_completed' => 'step_1' ] ],
        ],
        'rules' => [
            [ 'from' => 'step_1', 'if_answer' => 'subsidio', 'goto' => 'step_subsidio' ],
            [ 'from' => 'step_1', 'if_answer_in' => ['credito', 'otro'], 'goto' => '__end__' ],
        ],
    ],
    'scoring' => [
        'weights'       => [ 'objetivo' => 10, 'ingresos' => 5 ],
        'result_rules'  => [ [ 'min_score' => 80, 'segment' => 'hot', 'tier' => 'A' ] ],
        'default_result'=> [ 'segment' => 'cold', 'tier' => 'C' ],
    ],
    'completion_strategy' => [ 'type' => 'lead', 'lead_pipeline' => true, 'notify_emails' => true ],
    'identification' => 'optional',
] ) );

check( 'normalize: modo branching preservado', ( $cfg['flow']['mode'] ?? '' ) === 'branching' );
check( 'normalize: start preservado', ( $cfg['flow']['start'] ?? '' ) === 'step_1' );
check( 'normalize: 2 steps normalizados', count( $cfg['flow']['steps'] ?? [] ) === 2 );
check( 'normalize: visible_when normalizado', ( $cfg['flow']['steps'][1]['visible_when']['step_completed'] ?? '' ) === 'step_1' );
check( 'normalize: regla if_answer preservada', ( $cfg['flow']['rules'][0]['if_answer'] ?? '' ) === 'subsidio' );
check( 'normalize: regla if_answer_in normalizada', ( $cfg['flow']['rules'][1]['if_answer_in'] ?? [] ) === ['credito', 'otro'] );
check( 'normalize: scoring weights preservados', ( $cfg['scoring']['weights']['objetivo'] ?? null ) === 10.0 );
check( 'normalize: result_rule segment/tier', ( $cfg['scoring']['result_rules'][0]['segment'] ?? '' ) === 'hot' && ( $cfg['scoring']['result_rules'][0]['tier'] ?? '' ) === 'A' );
check( 'normalize: default_result', ( $cfg['scoring']['default_result']['segment'] ?? '' ) === 'cold' );
check( 'normalize: completion_strategy lead', ( $cfg['completion_strategy']['type'] ?? '' ) === 'lead' );
check( 'normalize: identification optional', ( $cfg['identification'] ?? '' ) === 'optional' );

$empty = Estandar_Conversation_Engine::normalize_config( '' );
check( 'normalize: vacío → []', $empty === [] );

$bad_mode = Estandar_Conversation_Engine::normalize_config( wp_json_encode( [ 'flow' => [ 'mode' => 'diagonal' ], 'completion_strategy' => [ 'type' => 'gift' ], 'identification' => 'raro' ] ) );
check( 'normalize: modo inválido → linear', ( $bad_mode['flow']['mode'] ?? '' ) === 'linear' );
check( 'normalize: completion inválido → email', ( $bad_mode['completion_strategy']['type'] ?? '' ) === 'email' );
check( 'normalize: identification inválido → anonymous', ( $bad_mode['identification'] ?? '' ) === 'anonymous' );

/* ═══════════════════════════════════════════════════════════════════════
   3 · FUENTE DE CONFIG POR TIPO
   ═══════════════════════════════════════════════════════════════════════ */
echo "\n--- 3 · fuente de config ---\n";

$survey_form  = (object) [ 'form_type' => 'survey', 'survey_config' => wp_json_encode( [ 'identification_mode' => 'optional' ] ), 'conversation_config' => null ];
$quote_form   = (object) [ 'form_type' => 'cotizacion', 'survey_config' => null, 'conversation_config' => wp_json_encode( [ 'identification' => 'identified' ] ) ];
$legacy_form  = (object) [ 'form_type' => 'lead', 'survey_config' => null, 'conversation_config' => null ];

check( 'survey lee survey_config', ( Estandar_Conversation_Engine::config_for_form( $survey_form )['identification_mode'] ?? '' ) === 'optional' );
check( 'experiencia nueva lee conversation_config', ( Estandar_Conversation_Engine::config_for_form( $quote_form )['identification'] ?? '' ) === 'identified' );
check( 'lead (no conversacional) sin config → []', Estandar_Conversation_Engine::config_for_form( $legacy_form ) === [] );

/* ═══════════════════════════════════════════════════════════════════════
   4 · RESOLVE_NEXT_STEP (contrato)
   ═══════════════════════════════════════════════════════════════════════ */
echo "\n--- 4 · resolve_next_step ---\n";

$flow_cfg = [ 'flow' => [
    'mode'  => 'branching',
    'steps' => [ [ 'id' => 'a' ], [ 'id' => 'b' ], [ 'id' => 'c' ] ],
    'rules' => [
        [ 'from' => 'a', 'if_answer' => 'subsidio', 'goto' => 'c' ],
        [ 'from' => 'a', 'if_answer_in' => ['x', 'y'], 'goto' => '__end__' ],
    ],
] ];

check( 'regla exacta → goto', Estandar_Conversation_Engine::resolve_next_step( $flow_cfg, 'a', 'subsidio', 0, 3 ) === 'c' );
check( 'regla if_answer_in → end', Estandar_Conversation_Engine::resolve_next_step( $flow_cfg, 'a', 'x', 0, 3 ) === null );
check( 'sin regla → fallback lineal siguiente', Estandar_Conversation_Engine::resolve_next_step( $flow_cfg, 'a', 'otra', 0, 3 ) === 'b' );
check( 'último paso sin regla → null (fin)', Estandar_Conversation_Engine::resolve_next_step( $flow_cfg, 'c', 'x', 2, 3 ) === null );
check( 'sin steps y sin reglas → null', Estandar_Conversation_Engine::resolve_next_step( [], 'a', '', 0, 0 ) === null );

/* ═══════════════════════════════════════════════════════════════════════
   5 · EVALUATE_SCORING (contrato)
   ═══════════════════════════════════════════════════════════════════════ */
echo "\n--- 5 · evaluate_scoring ---\n";

$scoring_cfg = [ 'scoring' => [
    'weights'       => [ 'objetivo' => 10, 'ingresos' => 5 ],
    'result_rules'  => [ [ 'min_score' => 80, 'max_score' => 100, 'segment' => 'hot', 'tier' => 'A' ] ],
    'default_result'=> [ 'segment' => 'cold', 'tier' => 'C' ],
] ];

$r1 = Estandar_Conversation_Engine::evaluate_scoring( $scoring_cfg, [ 'objetivo' => 6, 'ingresos' => 4 ] );
check( 'score = Σ peso×valor (60+20=80)', abs( ( $r1['score'] ?? 0 ) - 80.0 ) < 0.001 );
check( 'result_rule aplicada (segment hot)', ( $r1['segment'] ?? '' ) === 'hot' );

$r2 = Estandar_Conversation_Engine::evaluate_scoring( $scoring_cfg, [ 'objetivo' => 2, 'ingresos' => 1 ] );
check( 'score bajo → default_result', ( $r2['segment'] ?? '' ) === 'cold' && ( $r2['tier'] ?? '' ) === 'C' );

$r3 = Estandar_Conversation_Engine::evaluate_scoring( [], [ 'objetivo' => 5 ] );
check( 'sin scoring → []', $r3 === [] );

// QA MS-5: pesos por valor de elección (respuestas string).
$vm_cfg = [ 'scoring' => [
    'weights'       => [ 'rango_ingresos' => [ 'alta' => 35, 'media' => 10 ] ],
    'result_rules'  => [ [ 'min_score' => 30, 'max_score' => 100, 'segment' => 'hot', 'tier' => 'A' ] ],
    'default_result'=> [ 'segment' => 'cold', 'tier' => 'C' ],
] ];
$vm = Estandar_Conversation_Engine::evaluate_scoring( $vm_cfg, [ 'rango_ingresos' => 'alta' ] );
check( 'scoring por valor: alta → 35 → segment hot', abs( ( $vm['score'] ?? 0 ) - 35.0 ) < 0.001 && ( $vm['segment'] ?? '' ) === 'hot' );
$vm2 = Estandar_Conversation_Engine::evaluate_scoring( $vm_cfg, [ 'rango_ingresos' => 'media' ] );
check( 'scoring por valor: media → 10 → cold', abs( ( $vm2['score'] ?? 0 ) - 10.0 ) < 0.001 && ( $vm2['segment'] ?? '' ) === 'cold' );
$vm3 = Estandar_Conversation_Engine::evaluate_scoring( $vm_cfg, [ 'rango_ingresos' => 'otro' ] );
check( 'scoring por valor: valor no mapeado → 0', abs( ( $vm3['score'] ?? 0 ) - 0.0 ) < 0.001 );

// normalize_config preserva pesos por valor.
$vm_norm = Estandar_Conversation_Engine::normalize_config( [ 'scoring' => [ 'weights' => [ 'r' => [ 'alta' => 35, 'media' => 10 ] ] ] ] );
check( 'normalize: pesos por valor preservados', ( $vm_norm['scoring']['weights']['r']['alta'] ?? null ) === 35.0 );

/* ═══════════════════════════════════════════════════════════════════════
   Resultado
   ═══════════════════════════════════════════════════════════════════════ */
echo "\n=== {$passed} passed, {$failed} failed ===\n";
if ( $failed > 0 ) {
    exit( 1 );
}
exit( 0 );
