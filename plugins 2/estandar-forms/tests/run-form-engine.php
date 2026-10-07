<?php
/**
 * FORM_ENGINE_V1 — suite del motor de flujo progresivo y condiciones dinámicas.
 * Run: php tests/run-form-engine.php
 *
 * Cubre: parsing/normalización de actions_json (flow/conditions/validators) ·
 * back-compat lista de acciones (Action Engine) · evaluación server-side de
 * condiciones (show/hide/clear/require/optional/set_lead_quality/set_lead_reason)
 * · operadores equals/not_equals/contains/empty/not_empty/in/not_in ·
 * semántica de campos ocultos (no se validan ni persisten) · formularios
 * legacy sin config (no-op).
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

/* ── Harness ───────────────────────────────────────────────────────────── */
$passed = 0;
$failed = 0;
function check( string $label, bool $ok ): void {
    global $passed, $failed;
    if ( $ok ) {
        $passed++;
        echo "  ✓ {$label}\n";
    } else {
        $failed++;
        echo "  ✗ {$label}\n";
    }
}

/* ── Fixture: config estilo caso de aceptación (genérica, sin IDs) ─────── */
$fixture = [
    'flow' => [
        'mode'  => 'progressive',
        'steps' => [
            [ 'id' => 'perfil', 'label' => 'Tu necesidad', 'next_label' => 'Continuar',
              'fields' => [ 'necesidad_principal', 'situacion_empresa', 'contador_actual', 'plazo_inicio' ] ],
            [ 'id' => 'contact', 'fields' => [ 'nombre', 'email', 'telefono', 'ciudad', 'otra_ciudad', 'mensaje' ],
              'visible_when' => [ 'step_completed' => 'perfil' ] ],
        ],
    ],
    'conditions' => [
        [ 'id' => 'ciudad_otra',
          'when' => [ 'field' => 'ciudad', 'operator' => 'equals', 'value' => 'otra' ],
          'then' => [
              [ 'action' => 'show', 'target' => 'otra_ciudad' ],
              [ 'action' => 'require', 'target' => 'otra_ciudad' ],
          ],
          'else' => [
              [ 'action' => 'hide', 'target' => 'otra_ciudad' ],
              [ 'action' => 'clear', 'target' => 'otra_ciudad' ],
              [ 'action' => 'optional', 'target' => 'otra_ciudad' ],
          ] ],
        [ 'id' => 'necesidad_orientacion',
          'when' => [ 'field' => 'necesidad_principal', 'operator' => 'in',
                      'value' => [ 'financiamiento_subsidio', 'orientacion_emprender' ] ],
          'then' => [
              [ 'action' => 'require', 'target' => 'mensaje' ],
              [ 'action' => 'set_lead_quality', 'value' => 'low_quality' ],
              [ 'action' => 'set_lead_reason', 'value' => 'Necesidad requiere orientación previa' ],
          ],
          'else' => [
              [ 'action' => 'optional', 'target' => 'mensaje' ],
          ] ],
    ],
    'validators' => [
        [ 'field' => 'necesidad_principal', 'rule' => 'required', 'message' => 'Selecciona tu necesidad principal.' ],
        [ 'field' => 'mensaje', 'message' => 'Cuéntanos qué necesitas para orientarte.' ],
    ],
];
$fixture_json = wp_json_encode( $fixture );

/* ── 1 · Parsing / normalización ───────────────────────────────────────── */
echo "1 · estandar_forms_engine_config\n";

check( 'vacío → sin config', estandar_forms_engine_config( '' ) === [] );
check( 'null → sin config', estandar_forms_engine_config( null ) === [] );
check( 'JSON inválido → sin config', estandar_forms_engine_config( '{nope' ) === [] );
check( 'lista de acciones (legacy Action Engine) → sin config',
    estandar_forms_engine_config( '[{"type":"email","to":"x@y.cl"}]' ) === [] );
check( 'objeto sin flow/conditions/validators → sin config',
    estandar_forms_engine_config( '{"actions":[{"type":"email"}]}' ) === [] );

$config = estandar_forms_engine_config( $fixture_json );
check( 'fixture: flow presente con 2 pasos', count( $config['flow']['steps'] ?? [] ) === 2 );
check( 'fixture: modo progressive', ( $config['flow']['mode'] ?? '' ) === 'progressive' );
check( 'fixture: paso 1 conserva fields en orden',
    ( $config['flow']['steps'][0]['fields'] ?? [] ) === [ 'necesidad_principal', 'situacion_empresa', 'contador_actual', 'plazo_inicio' ] );
check( 'fixture: paso 2 conserva visible_when.step_completed',
    ( $config['flow']['steps'][1]['visible_when']['step_completed'] ?? '' ) === 'perfil' );
check( 'fixture: next_label saneado', ( $config['flow']['steps'][0]['next_label'] ?? '' ) === 'Continuar' );
check( 'fixture: 2 condiciones normalizadas', count( $config['conditions'] ?? [] ) === 2 );
check( 'fixture: operador in conserva lista de valores',
    ( $config['conditions'][1]['when']['value'] ?? [] ) === [ 'financiamiento_subsidio', 'orientacion_emprender' ] );
check( 'fixture: 2 validators', count( $config['validators'] ?? [] ) === 2 );

$dirty = estandar_forms_engine_config( wp_json_encode( [
    'flow' => [ 'steps' => [
        [ 'id' => '', 'fields' => [ 'x' ] ],                    // sin id → drop
        [ 'id' => 'ok', 'fields' => [] ],                        // sin fields → drop
        [ 'id' => 'valid', 'fields' => [ 'Campo Raro!', '' ] ],  // saneo de nombres
    ] ],
    'conditions' => [
        [ 'when' => [ 'field' => '', 'operator' => 'equals', 'value' => 'x' ], 'then' => [ [ 'action' => 'hide', 'target' => 'a' ] ] ], // when inválido → drop
        [ 'when' => [ 'field' => 'a', 'operator' => 'launch_missiles', 'value' => 'x' ], 'then' => [ [ 'action' => 'hide', 'target' => 'b' ] ] ], // operador desconocido → drop
        [ 'when' => [ 'field' => 'a', 'operator' => 'equals', 'value' => 'x' ],
          'then' => [ [ 'action' => 'explode', 'target' => 'b' ], [ 'action' => 'hide', 'target' => 'b' ], [ 'action' => 'require' ] ] ], // action desconocida y sin target → drop
    ],
] ) );
check( 'saneo: solo sobrevive el paso válido y sanea nombres',
    count( $dirty['flow']['steps'] ?? [] ) === 1 && $dirty['flow']['steps'][0]['fields'] === [ 'camporaro' ] );
check( 'saneo: solo sobrevive la condición válida con 1 acción',
    count( $dirty['conditions'] ?? [] ) === 1 && count( $dirty['conditions'][0]['then'] ) === 1 );

/* ── 2 · Operadores when ───────────────────────────────────────────────── */
echo "2 · estandar_forms_engine_when_matches\n";

$w = static fn( string $op, $value ) => [ 'field' => 'f', 'operator' => $op, 'value' => $value ];
check( 'equals true', estandar_forms_engine_when_matches( $w( 'equals', 'x' ), [ 'f' => 'x' ] ) );
check( 'equals false', ! estandar_forms_engine_when_matches( $w( 'equals', 'x' ), [ 'f' => 'y' ] ) );
check( 'not_equals', estandar_forms_engine_when_matches( $w( 'not_equals', 'x' ), [ 'f' => 'y' ] ) );
check( 'contains', estandar_forms_engine_when_matches( $w( 'contains', 'tia' ), [ 'f' => 'santiago' ] ) );
check( 'empty', estandar_forms_engine_when_matches( $w( 'empty', '' ), [ 'f' => '' ] ) );
check( 'not_empty', estandar_forms_engine_when_matches( $w( 'not_empty', '' ), [ 'f' => 'v' ] ) );
check( 'in match', estandar_forms_engine_when_matches( $w( 'in', [ 'a', 'b' ] ), [ 'f' => 'b' ] ) );
check( 'in no-match', ! estandar_forms_engine_when_matches( $w( 'in', [ 'a', 'b' ] ), [ 'f' => 'c' ] ) );
check( 'not_in', estandar_forms_engine_when_matches( $w( 'not_in', [ 'a' ] ), [ 'f' => 'c' ] ) );
check( 'in con campo ausente → no-match', ! estandar_forms_engine_when_matches( $w( 'in', [ 'a' ] ), [] ) );

/* ── 3 · Evaluación server-side (semántica del caso de aceptación) ─────── */
echo "3 · estandar_forms_engine_evaluate\n";

// Caso: ciudad normal, necesidad core → otra_ciudad oculta+opcional, mensaje opcional.
$state = estandar_forms_engine_evaluate( $config, [
    'necesidad_principal' => 'contabilidad_mensual', 'ciudad' => 'santiago', 'otra_ciudad' => 'no debería persistir',
] );
check( 'ciudad≠otra: otra_ciudad oculta', ! empty( $state['hidden']['otra_ciudad'] ) );
check( 'ciudad≠otra: otra_ciudad opcional', $state['required']['otra_ciudad'] === false );
check( 'necesidad core: mensaje opcional', $state['required']['mensaje'] === false );
check( 'necesidad core: sin lead_quality', $state['lead_quality'] === '' && $state['lead_reason'] === '' );
check( 'validators rule=required aplica', $state['required']['necesidad_principal'] === true );

// Caso: ciudad=otra → otra_ciudad visible y requerida.
$state = estandar_forms_engine_evaluate( $config, [ 'necesidad_principal' => 'contabilidad_mensual', 'ciudad' => 'otra' ] );
check( 'ciudad=otra: otra_ciudad visible', empty( $state['hidden']['otra_ciudad'] ) );
check( 'ciudad=otra: otra_ciudad requerida', $state['required']['otra_ciudad'] === true );

// Caso: necesidad especial → mensaje requerido + clasificación.
$state = estandar_forms_engine_evaluate( $config, [ 'necesidad_principal' => 'financiamiento_subsidio', 'ciudad' => 'santiago' ] );
check( 'necesidad especial: mensaje requerido', $state['required']['mensaje'] === true );
check( 'necesidad especial: set_lead_quality', $state['lead_quality'] === 'low_quality' );
check( 'necesidad especial: set_lead_reason', $state['lead_reason'] === 'Necesidad requiere orientación previa' );

$state = estandar_forms_engine_evaluate( $config, [ 'necesidad_principal' => 'orientacion_emprender', 'ciudad' => 'otra' ] );
check( 'segunda necesidad especial también clasifica', $state['lead_quality'] === 'low_quality' && $state['required']['mensaje'] === true );

/* ── 3b · Literalidad: el valor configurado NUNCA se transforma ─────────── */
echo "3b · Literalidad de set_lead_quality / set_lead_reason\n";

// Contrato: falla si low_quality llega transformado (nurture, low-quality,
// lowquality, LOW_QUALITY o cualquier otra derivación) en cualquier capa.
check( 'literal: low_quality atraviesa config sin transformarse',
    ( $config['conditions'][1]['then'][1]['value'] ?? '' ) === 'low_quality' );
$state = estandar_forms_engine_evaluate( $config, [ 'necesidad_principal' => 'financiamiento_subsidio' ] );
check( 'literal: evaluate entrega exactamente low_quality',
    $state['lead_quality'] === 'low_quality'
    && $state['lead_quality'] !== 'nurture'
    && $state['lead_quality'] !== 'lowquality'
    && $state['lead_quality'] !== 'low-quality' );

// sanitize_key habría minusculizado/mutilado estos valores: deben salir literales.
$literal_cfg = estandar_forms_engine_config( wp_json_encode( [
    'conditions' => [ [
        'when' => [ 'field' => 'f', 'operator' => 'equals', 'value' => 'x' ],
        'then' => [
            [ 'action' => 'set_lead_quality', 'value' => 'Low_Quality B2B' ],
            [ 'action' => 'set_lead_reason', 'value' => 'Razón CON Mayúsculas y acentos' ],
        ],
    ] ],
] ) );
$literal_state = estandar_forms_engine_evaluate( $literal_cfg, [ 'f' => 'x' ] );
check( 'literal: mayúsculas/espacios se preservan en lead_quality',
    $literal_state['lead_quality'] === 'Low_Quality B2B' );
check( 'literal: lead_reason se preserva', $literal_state['lead_reason'] === 'Razón CON Mayúsculas y acentos' );

// El servidor no confía en el POST: un lead_quality inyectado por el cliente
// jamás entra a $data — solo el recálculo desde actions_json lo hace.
$state = estandar_forms_engine_evaluate( $config, [
    'necesidad_principal' => 'contabilidad_mensual', // rama else: sin clasificación
    'lead_quality' => 'hot',                          // inyección del cliente
    'lead_reason'  => 'spoofed',
] );
$data = [];
if ( $state['lead_quality'] !== '' ) { $data['lead_quality'] = $state['lead_quality']; }
if ( $state['lead_reason'] !== '' ) { $data['lead_reason'] = $state['lead_reason']; }
check( 'anti-spoof: POST con lead_quality no produce clasificación', $data === [] );

// Semántica del handler: campo oculto ⇒ skip de validación + valor vacío.
$state = estandar_forms_engine_evaluate( $config, [ 'necesidad_principal' => 'contabilidad_mensual', 'ciudad' => 'santiago' ] );
$field = [ 'name' => 'otra_ciudad', 'type' => 'text', 'required' => false ];
$data = [];
if ( ! empty( $state['hidden'][ $field['name'] ] ) ) {
    $data[ $field['name'] ] = $field['type'] === 'checkbox' ? 0 : '';
}
check( 'handler: campo oculto persiste vacío sin validar', $data === [ 'otra_ciudad' => '' ] );

// Semántica del handler: override de required (mensaje base opcional → requerido).
$state = estandar_forms_engine_evaluate( $config, [ 'necesidad_principal' => 'financiamiento_subsidio', 'ciudad' => 'santiago' ] );
$required = false; // fields_json.required de mensaje
if ( array_key_exists( 'mensaje', $state['required'] ) ) {
    $required = (bool) $state['required']['mensaje'];
}
check( 'handler: override eleva required de mensaje', $required === true );

/* ── 4 · Legacy: formularios sin engine quedan intactos ────────────────── */
echo "4 · Back-compat\n";

$legacy_state = estandar_forms_engine_evaluate( [], [ 'ciudad' => 'otra' ] );
check( 'sin config: estado neutro',
    $legacy_state === [ 'hidden' => [], 'required' => [], 'lead_quality' => '', 'lead_reason' => '' ] );

// Forma objeto con clave actions: el pipeline clásico sigue extraíble.
$mixed = json_decode( wp_json_encode( [
    'flow' => $fixture['flow'],
    'actions' => [ [ 'type' => 'email', 'to' => 'ops@x.cl' ] ],
] ), true );
$pipeline = ( ! array_is_list( $mixed ) && isset( $mixed['actions'] ) && is_array( $mixed['actions'] ) ) ? $mixed['actions'] : [];
check( 'forma objeto: pipeline post-submit accesible vía clave actions', $pipeline === [ [ 'type' => 'email', 'to' => 'ops@x.cl' ] ] );

/* ── Resultado ─────────────────────────────────────────────────────────── */
echo "\n{$passed} passed · {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
