<?php
/**
 * MS1_HYGIENE — tests de higiene de captura y configuración segura (ADR 0034).
 * Run: php tests/run-ms1-hygiene.php  (desde el directorio del plugin)
 *
 * Cubre:
 *  - R9 · estandar_forms_merge_survey_config: preserva claves desconocidas,
 *    actualiza claves conocidas, tolera JSON inválido/vacío, nunca reconstruye.
 *  - R3 · estandar_forms_is_standard_submit_type: lead/lead_capture/general/
 *    experience/incident/email_direct → true; survey/feedback/postventa/soporte/
 *    desconocido/vacío → false.
 *  - R1 · regresión estructural: 0 referencias a `form_structure` en código;
 *    8 queries esperadas leen `fields_json` (handler 4 · analytics 2 · results 2).
 */

define( 'ABSPATH', __DIR__ . '/' );

/* ── WP stubs (mínimos, idénticos a run-form-engine.php) ───────────────── */
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
   1 · R9 — MERGE DE SURVEY_CONFIG
   ═══════════════════════════════════════════════════════════════════════ */
echo "\n--- 1 · R9 merge survey_config ---\n";

$existing = wp_json_encode( [
    'identification_mode' => 'optional',
    'hero_title'          => 'Título viejo',
    'custom_future_key'   => 'valor-desconocido-que-debe-sobrevivir',
    'nested'              => [ 'a' => 1 ],
] );

$merged = estandar_forms_merge_survey_config( $existing, [
    'identification_mode' => 'identified',
    'hero_title'          => 'Título nuevo',
    'completion_message'  => 'Gracias',
] );

check( 'R9: claves desconocidas preservadas', isset( $merged['custom_future_key'] ) && $merged['custom_future_key'] === 'valor-desconocido-que-debe-sobrevivir' );
check( 'R9: claves anidadas preservadas', isset( $merged['nested'] ) && is_array( $merged['nested'] ) && $merged['nested']['a'] === 1 );
check( 'R9: claves conocidas actualizadas', $merged['identification_mode'] === 'identified' && $merged['hero_title'] === 'Título nuevo' );
check( 'R9: claves nuevas añadidas', isset( $merged['completion_message'] ) && $merged['completion_message'] === 'Gracias' );

$from_empty = estandar_forms_merge_survey_config( '', [ 'identification_mode' => 'anonymous' ] );
check( 'R9: JSON vacío → solo claves conocidas', $from_empty === [ 'identification_mode' => 'anonymous' ] );

$from_invalid = estandar_forms_merge_survey_config( '{nope', [ 'hero_cta' => 'Comenzar' ] );
check( 'R9: JSON inválido → no lanza y aplica conocidas', is_array( $from_invalid ) && ( $from_invalid['hero_cta'] ?? '' ) === 'Comenzar' );

$no_overwrite = estandar_forms_merge_survey_config( 'not-json-at-all', [] );
check( 'R9: sin claves conocidas y JSON inválido → array vacío', $no_overwrite === [] );

/* ═══════════════════════════════════════════════════════════════════════
   2 · R3 — GUARDIA DE FORM_TYPE
   ═══════════════════════════════════════════════════════════════════════ */
echo "\n--- 2 · R3 guardia form_type ---\n";

$true_types  = [ 'lead', 'lead_capture', 'general', 'experience', 'incident', 'email_direct' ];
$false_types = [ 'survey', 'feedback', 'postventa', 'soporte', 'cotizacion', 'wizard', 'unknown', '' ];

$all_true  = true;
$all_false = true;
foreach ( $true_types as $t ) {
    if ( ! estandar_forms_is_standard_submit_type( $t ) ) { $all_true = false; }
}
foreach ( $false_types as $t ) {
    if ( estandar_forms_is_standard_submit_type( $t ) ) { $all_false = false; }
}
check( 'R3: pipeline estándar acepta lead/lead_capture/general/experience/incident/email_direct', $all_true );
check( 'R3: tipos conversacionales/desconocidos rechazados', $all_false );
check( 'R3: general es sinónimo legacy de lead (no se rompe el starter form)', estandar_forms_is_standard_submit_type( 'general' ) );
check( 'R3: survey no pasa por admin-post (usa AJAX)', ! estandar_forms_is_standard_submit_type( 'survey' ) );

/* ═══════════════════════════════════════════════════════════════════════
   3 · R1 — REGRESIÓN ESTRUCTURAL form_structure → fields_json
   ═══════════════════════════════════════════════════════════════════════ */
echo "\n--- 3 · R1 columna fantasma ---\n";

$files = [
    'includes/class-survey-handler.php',
    'includes/class-survey-analytics.php',
    'admin/screens/survey-results.php',
];

$ghost = 0;
$fields_json_queries = 0;
foreach ( $files as $rel ) {
    $content = (string) file_get_contents( __DIR__ . '/../' . $rel );
    if ( stripos( $content, 'form_structure' ) !== false ) {
        $ghost++;
    }
    $fields_json_queries += substr_count( $content, 'SELECT fields_json FROM' );
}

check( 'R1: 0 referencias a form_structure en código (handler/analytics/results)', $ghost === 0 );
check( 'R1: las 8 queries de estructura leen fields_json', $fields_json_queries === 8, "encontradas: $fields_json_queries" );

/* ═══════════════════════════════════════════════════════════════════════
   Resultado
   ═══════════════════════════════════════════════════════════════════════ */
echo "\n=== {$passed} passed, {$failed} failed ===\n";
if ( $failed > 0 ) {
    exit( 1 );
}
exit( 0 );
