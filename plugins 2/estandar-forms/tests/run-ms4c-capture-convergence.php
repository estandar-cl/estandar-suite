<?php
/**
 * MS4C_CAPTURE_CONVERGENCE — tests de la capa única de captura (ADR 0034, MS-4c).
 * Run: php tests/run-ms4c-capture-convergence.php  (desde el directorio del plugin)
 *
 * Verifica que todas las escrituras de sesión/respuesta pasan por las primitivas
 * del motor (single capture) y que las tablas/formats coinciden con el contrato.
 * El handler de Survey ya NO escribe directamente (regresión estructural).
 */

define( 'ABSPATH', __DIR__ . '/' );

/* ── WP stubs (mínimos) ────────────────────────────────────────────────── */
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

/* ── Fake $wpdb (graba llamadas) ───────────────────────────────────────── */
class Fake_WPDB_Capture {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $calls = [];
    public function insert( $table, $data, $fmt = [] ) {
        $this->calls[] = [ 'insert', $table, $data, $fmt ];
        $this->insert_id++;
        return 1;
    }
    public function update( $table, $data, $where, $fmt = [], $where_fmt = [] ) {
        $this->calls[] = [ 'update', $table, $data, $where, $fmt, $where_fmt ];
        return 1;
    }
    public function get_var( $q = null, ...$a ) { return null; }
    public function get_results( $q = null, $x = null ) { return []; }
    public function get_row( $q = null, $x = null, $y = 0 ) { return null; }
    public function prepare( $q, ...$a ) { return $q; }
}
$GLOBALS['wpdb'] = new Fake_WPDB_Capture();

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

function last_call(): ?array {
    $calls = $GLOBALS['wpdb']->calls;
    return $calls ? $calls[ count( $calls ) - 1 ] : null;
}

/* ═══════════════════════════════════════════════════════════════════════
   1 · CAPTURE SESSION
   ═══════════════════════════════════════════════════════════════════════ */
echo "\n--- 1 · capture session ---\n";

$id = Estandar_Conversation_Engine::capture_session_create( 42, '{"a":1}' );
$c = last_call();
check( 'session_create → insert a estandar_form_entries', $c && $c[1] === 'wp_estandar_form_entries' );
check( 'session_create: form_id y data_json', $c && $c[2]['form_id'] === 42 && $c[2]['data_json'] === '{"a":1}' );
check( 'session_create: status started', $c && $c[2]['status'] === 'active' );
check( 'session_create: devuelve insert_id', $id === 1 );

Estandar_Conversation_Engine::capture_session_update_data( 1, '{"b":2}' );
$c = last_call();
check( 'session_update_data → update data_json', $c && $c[1] === 'wp_estandar_form_entries' && $c[2]['data_json'] === '{"b":2}' && $c[3] === [ 'id' => 1 ] );

Estandar_Conversation_Engine::capture_session_set_status( 5, 'survey_abandoned', 42 );
$c = last_call();
check( 'session_set_status: status + where id/form_id', $c && $c[2]['status'] === 'survey_abandoned' && $c[3] === [ 'id' => 5, 'form_id' => 42 ] );

Estandar_Conversation_Engine::capture_session_complete( 5, '{"done":1}' );
$c = last_call();
check( 'session_complete: data_json + status completed', $c && $c[2]['status'] === 'survey_completed' && $c[2]['data_json'] === '{"done":1}' && $c[3] === [ 'id' => 5 ] );

/* ═══════════════════════════════════════════════════════════════════════
   2 · CAPTURE RESPONSE
   ═══════════════════════════════════════════════════════════════════════ */
echo "\n--- 2 · capture response ---\n";

$ok = Estandar_Conversation_Engine::capture_response_save( [
    'session_token'      => 'tok',
    'form_id'            => 42,
    'entry_id'           => null,
    'question_index'     => 3,
    'question_label'     => 'Q',
    'question_type'      => 'nps',
    'answer_value'       => '7',
    'answer_label'       => '7',
    'answered_at'        => '2026-08-04 00:00:00',
    'time_spent_seconds' => 5,
] );
$c = last_call();
check( 'response_save insert → estandar_survey_responses', $ok === true && $c && $c[1] === 'wp_estandar_survey_responses' );
check( 'response_save insert: 10 columnas', $c && count( $c[2] ) === 10 && count( $c[3] ) === 10 );

Estandar_Conversation_Engine::capture_response_save(
    [ 'answer_value' => '8', 'answer_label' => '8', 'answered_at' => 'x', 'time_spent_seconds' => 2 ],
    [ 'id' => 9 ]
);
$c = last_call();
check( 'response_save update: where id', $c && $c[0] === 'update' && $c[3] === [ 'id' => 9 ] && $c[2]['answer_value'] === '8' );

Estandar_Conversation_Engine::capture_response_link_entry( 5, 'tok', 42 );
$c = last_call();
check( 'response_link_entry: update entry_id por token+form', $c && $c[2]['entry_id'] === 5 && $c[3] === [ 'session_token' => 'tok', 'form_id' => 42 ] );

/* ═══════════════════════════════════════════════════════════════════════
   3 · REGRESIÓN ESTRUCTURAL (el handler ya no escribe directo)
   ═══════════════════════════════════════════════════════════════════════ */
echo "\n--- 3 · regresión estructural ---\n";

$handler = (string) file_get_contents( __DIR__ . '/../includes/class-survey-handler.php' );
check( 'handler delega session_create', strpos( $handler, 'capture_session_create' ) !== false );
check( 'handler delega response_save', strpos( $handler, 'capture_response_save' ) !== false );
check( 'handler delega session_complete', strpos( $handler, 'capture_session_complete' ) !== false );
check( 'handler delega link_entry', strpos( $handler, 'capture_response_link_entry' ) !== false );

/* ═══════════════════════════════════════════════════════════════════════
   Resultado
   ═══════════════════════════════════════════════════════════════════════ */
echo "\n=== {$passed} passed, {$failed} failed ===\n";
if ( $failed > 0 ) {
    exit( 1 );
}
exit( 0 );
