<?php
/**
 * MS4_WIDGET_CATALOG — tests del catálogo único de tipos de pregunta (ADR 0034, T2).
 * Run: php tests/run-ms4-widget-catalog.php  (desde el directorio del plugin)
 *
 * Cubre: question_type_catalog (10 tipos, incluye textarea y checkbox) ·
 * is_supported_question_type · consistencia estructural: el handler, el builder
 * PHP (survey-edit) y el linter (template) se basan en el catálogo del motor.
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
   1 · CATÁLOGO ÚNICO
   ═══════════════════════════════════════════════════════════════════════ */
echo "\n--- 1 · catálogo ---\n";

$catalog = Estandar_Conversation_Engine::question_type_catalog();
check( 'catálogo = 10 tipos', count( $catalog ) === 10, implode(',', $catalog) );
check( 'incluye textarea (faltaba en builder/linter)', in_array( 'textarea', $catalog, true ) );
check( 'incluye checkbox', in_array( 'checkbox', $catalog, true ) );
check( 'sin duplicados', count( $catalog ) === count( array_unique( $catalog ) ) );

$all_supported = true;
foreach ( $catalog as $t ) {
    if ( ! Estandar_Conversation_Engine::is_supported_question_type( $t ) ) {
        $all_supported = false;
    }
}
check( 'is_supported: todos los tipos del catálogo', $all_supported );
check( 'is_supported: tipo desconocido → false', ! Estandar_Conversation_Engine::is_supported_question_type( 'datepicker' ) );
check( 'is_supported: vacío → false', ! Estandar_Conversation_Engine::is_supported_question_type( '' ) );

/* ═══════════════════════════════════════════════════════════════════════
   2 · CONSISTENCIA ESTRUCTURAL (fuentes únicas)
   ═══════════════════════════════════════════════════════════════════════ */
echo "\n--- 2 · consistencia estructural ---\n";

$handler   = (string) file_get_contents( __DIR__ . '/../includes/class-survey-handler.php' );
$builder   = (string) file_get_contents( __DIR__ . '/../admin/screens/survey-edit.php' );
$template  = (string) file_get_contents( __DIR__ . '/../includes/class-template.php' );

check( 'handler delega en el catálogo del motor', strpos( $handler, 'Estandar_Conversation_Engine::question_type_catalog()' ) !== false );
check( 'builder PHP usa el catálogo del motor', strpos( $builder, 'Estandar_Conversation_Engine::question_type_catalog()' ) !== false );
check( 'template basa su catálogo en el motor', strpos( $template, 'Estandar_Conversation_Engine::question_type_catalog()' ) !== false );

/* ═══════════════════════════════════════════════════════════════════════
   Resultado
   ═══════════════════════════════════════════════════════════════════════ */
echo "\n=== {$passed} passed, {$failed} failed ===\n";
if ( $failed > 0 ) {
    exit( 1 );
}
exit( 0 );
