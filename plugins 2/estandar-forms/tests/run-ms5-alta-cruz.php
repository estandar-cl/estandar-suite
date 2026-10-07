<?php
/**
 * MS5_ALTA_CRUZ — Lead Qualification como primera Experience por configuración (ADR 0034).
 * Run: php tests/run-ms5-alta-cruz.php  (desde el directorio del plugin)
 *
 * HIPÓTESIS (validar): una Experience se define únicamente por configuración:
 *   conversation_config (flow + scoring + completion) + fields_json (widgets)
 *   + identification + integraciones. Sin runtime nuevo, sin builder nuevo.
 *
 * Cubre: definición AltaCruz completa → normalize_config + flow_map + completion_result ·
 * configuración por perfil (config_for_form) · generalización estructural del handler
 * (sin form_type='survey' hardcodeado) · dispatch conversacional por is_conversational.
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
   1 · DEFINICIÓN ALTA CRUZ (conversation_config) — hipótesis
   ═══════════════════════════════════════════════════════════════════════ */
echo "\n--- 1 · definición AltaCruz por config ---\n";

$alta_config = Estandar_Conversation_Engine::normalize_config( wp_json_encode( [
    'flow' => [
        'mode'  => 'branching',
        'steps' => [
            [ 'id' => 'objetivo',  'widget' => 'cards', 'fields' => [ 'objetivo' ] ],
            [ 'id' => 'ingresos',  'widget' => 'radio', 'fields' => [ 'rango_ingresos' ] ],
            [ 'id' => 'contacto',  'widget' => 'text',  'fields' => [ 'nombre', 'email' ] ],
        ],
        'rules' => [
            [ 'from' => 'objetivo', 'if_answer' => 'subsidio', 'goto' => 'rango_ingresos' ],
            [ 'from' => 'objetivo', 'if_answer_in' => [ 'arriendo', 'venta' ], 'goto' => 'contacto' ],
        ],
    ],
    'scoring' => [
        'weights'       => [ 'rango_ingresos' => 10 ],
        'result_rules'  => [ [ 'min_score' => 30, 'max_score' => 100, 'segment' => 'hot', 'tier' => 'A' ] ],
        'default_result'=> [ 'segment' => 'cold', 'tier' => 'C' ],
    ],
    'completion_strategy' => [ 'type' => 'lead', 'lead_pipeline' => true ],
    'identification' => 'identified',
] ) );

check( 'flow branching + start steps normalizados', ( $alta_config['flow']['mode'] ?? '' ) === 'branching' && count( $alta_config['flow']['steps'] ?? [] ) === 3 );
check( 'reglas AltaCruz normalizadas (objetivo→ingresos/contacto)', count( $alta_config['flow']['rules'] ?? [] ) === 2 );
check( 'scoring weights + result_rules', ( $alta_config['scoring']['weights']['rango_ingresos'] ?? null ) === 10.0 && ( $alta_config['scoring']['result_rules'][0]['segment'] ?? '' ) === 'hot' );
check( 'completion_strategy lead', ( $alta_config['completion_strategy']['type'] ?? '' ) === 'lead' );
check( 'identification identified', ( $alta_config['identification'] ?? '' ) === 'identified' );

$alta_fields = [
    [ 'name' => 'objetivo', 'type' => 'cards', 'options' => [ [ 'value' => 'subsidio', 'label' => 'Subsidio' ], [ 'value' => 'arriendo', 'label' => 'Arriendo' ] ] ],
    [ 'name' => 'rango_ingresos', 'type' => 'radio', 'options' => [ [ 'value' => 'alta', 'label' => 'Alto' ] ] ],
    [ 'name' => 'contacto', 'type' => 'text' ],
];
$map = Estandar_Conversation_Engine::flow_map( $alta_config, $alta_fields );
check( 'flow_map: objetivo→ingresos por regla', ( $map[0]['rules'][0]['next'] ?? null ) === 1 );
check( 'flow_map: catchall/segunda regla → contacto', ( $map[0]['rules'][1]['next'] ?? null ) === 2 );

$cr = Estandar_Conversation_Engine::completion_result( $alta_config, [ 'rango_ingresos' => 8 ] );
check( 'completion: lead (habilita estandar_lead_captured)', ( $cr['completion_type'] ?? '' ) === 'lead' );
check( 'completion: scoring 80 → segment hot', ( $cr['scoring']['segment'] ?? '' ) === 'hot' );

/* ═══════════════════════════════════════════════════════════════════════
   2 · CONFIG POR PERFIL (Survey vs lead_qualification)
   ═══════════════════════════════════════════════════════════════════════ */
echo "\n--- 2 · config por perfil ---\n";

$alta_form  = (object) [ 'form_type' => 'lead_qualification', 'survey_config' => null, 'conversation_config' => wp_json_encode( [ 'identification' => 'identified' ] ) ];
$survey_form = (object) [ 'form_type' => 'survey', 'survey_config' => wp_json_encode( [ 'identification_mode' => 'optional' ] ), 'conversation_config' => null ];

$alta_cfg = Estandar_Conversation_Engine::config_for_form( $alta_form );
$survey_cfg = Estandar_Conversation_Engine::config_for_form( $survey_form );
check( 'lead_qualification lee conversation_config', ( $alta_cfg['identification'] ?? '' ) === 'identified' );
check( 'survey lee survey_config (intacto)', ( $survey_cfg['identification_mode'] ?? '' ) === 'optional' );
check( 'is_conversational(lead_qualification) y is_conversational(survey)', Estandar_Conversation_Engine::is_conversational( 'lead_qualification' ) && Estandar_Conversation_Engine::is_conversational( 'survey' ) );

/* ═══════════════════════════════════════════════════════════════════════
   3 · GENERALIZACIÓN ESTRUCTURAL (sin hardcode survey en el runtime)
   ═══════════════════════════════════════════════════════════════════════ */
echo "\n--- 3 · generalización estructural ---\n";

$handler  = (string) file_get_contents( __DIR__ . '/../includes/class-survey-handler.php' );
$shortcode = (string) file_get_contents( __DIR__ . '/../includes/class-shortcode.php' );
$render   = (string) file_get_contents( __DIR__ . '/../public/survey-render.php' );

check( 'handler sin form_type=survey hardcodeado', strpos( $handler, "form_type = 'survey'" ) === false );
check( 'handler lee config por perfil (config_for_form)', strpos( $handler, 'Estandar_Conversation_Engine::config_for_form' ) !== false );
check( 'shortcode despacha por is_conversational', strpos( $shortcode, 'Estandar_Conversation_Engine::is_conversational' ) !== false );
check( 'render lee config por perfil', strpos( $render, 'Estandar_Conversation_Engine::config_for_form' ) !== false );

// QA MS-5: el payload de lead debe ser el CANÓNICO (schema_version/source_plugin/contact)
// para pasar Estandar_Leads_Payload_Validator.
check( 'payload lead canónico: schema_version', strpos( $handler, "'schema_version' => '1.0'" ) !== false );
check( 'payload lead canónico: source_plugin', strpos( $handler, "'source_plugin'  => 'estandar-forms'" ) !== false );
check( 'payload lead canónico: contact', strpos( $handler, "'contact'        => [" ) !== false );

/* ═══════════════════════════════════════════════════════════════════════
   Resultado
   ═══════════════════════════════════════════════════════════════════════ */
echo "\n=== {$passed} passed, {$failed} failed ===\n";
if ( $failed > 0 ) {
    exit( 1 );
}
exit( 0 );
