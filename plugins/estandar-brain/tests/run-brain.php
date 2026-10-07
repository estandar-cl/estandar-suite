<?php
/**
 * ESTANDAR SUITE v2 — Brain tests (pure logic + guarded DB paths).
 *
 *   php tests/run-brain.php   → expect "0 failed"
 */

define('ABSPATH', __DIR__);
if (!function_exists('sanitize_key')) { function sanitize_key($k) { return strtolower((string) preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k))); } }
if (!function_exists('sanitize_title')) { function sanitize_title($t) { return strtolower((string) preg_replace('/[^a-z0-9\-_]/', '-', (string) $t)); } }
if (!function_exists('get_option')) { function get_option() { return array(); } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($v) { return json_encode($v, JSON_UNESCAPED_UNICODE); } }

require dirname(__DIR__) . '/includes/Rules.php';
require dirname(__DIR__) . '/includes/Scoring.php';
require dirname(__DIR__) . '/includes/IntentPresets.php';
require dirname(__DIR__) . '/includes/IntentMap.php';
require dirname(__DIR__) . '/includes/SignalLedger.php';
require dirname(__DIR__) . '/includes/Adoption.php';
require dirname(__DIR__) . '/includes/Contactability.php';
require dirname(__DIR__) . '/includes/AssessmentEngine.php';
require dirname(__DIR__) . '/includes/GoogleOci.php';

$passed = 0; $failed = 0;
function check($label, $cond) { global $passed, $failed; if ($cond) { $passed++; echo "PASS  $label\n"; } else { $failed++; echo "FAIL  $label\n"; } }

use Estandar\Brain as B;

// ── 1. Rules: tiers, precedence, first-writer-wins, stop ──────────────────
check('1a free text tier 4', B\Rules::field_tier('content.message') === 4);
check('1b structured tier 1', B\Rules::field_tier('fields.tipo_cliente') === 1);
check('1c context tier 2', B\Rules::field_tier('context.project_slug') === 2);

$rules = array(
    B\Rules::normalize(array('id' => 'r-ft', 'conditions' => array(array('field' => 'content.message', 'op' => 'contains', 'value' => 'trabajo')), 'action' => array('commercial_fit' => 'FUERA_DE_PERFIL'), 'priority' => 1)),
    B\Rules::normalize(array('id' => 'r-struct', 'conditions' => array(array('field' => 'fields.tipo_cliente', 'op' => 'equals', 'value' => 'empresa')), 'action' => array('commercial_fit' => 'MATCH'), 'priority' => 1)),
);
$e = B\Rules::evaluate(array('content' => array('message' => 'busco trabajo'), 'fields' => array('tipo_cliente' => 'empresa')), $rules);
check('1d estructurado gana a texto libre (tier)', $e['action']['commercial_fit'] === 'MATCH');

$rules = array(
    B\Rules::normalize(array('id' => 'r-stop', 'conditions' => array(array('field' => 'has_phone', 'op' => 'equals', 'value' => '1')), 'action' => array('commercial_fit' => 'DUDOSO', 'stop' => true), 'priority' => 1)),
    B\Rules::normalize(array('id' => 'r-after', 'conditions' => array(array('field' => 'has_phone', 'op' => 'equals', 'value' => '1')), 'action' => array('commercial_fit' => 'MATCH'), 'priority' => 5)),
);
$e = B\Rules::evaluate(array('has_phone' => '1'), $rules);
check('1e stop corta', $e['action']['commercial_fit'] === 'DUDOSO');
check('1f matched incluye r-stop', in_array('r-stop', $e['matched'], true));

// 1g-1j: operadores de paridad con legacy (regex, min_length, max_length)
check('1g regex matchea', B\Rules::condition(array('field'=>'content.message','op'=>'regex','value'=>'/(cambiar contador|controller)/i'), array('content'=>array('message'=>'necesito cambiar de contador y controller'))));
check('1h regex sin match', ! B\Rules::condition(array('field'=>'content.message','op'=>'regex','value'=>'/(estilista)/i'), array('content'=>array('message'=>'quiero cambiar de contador'))));
check('1i regex inválido nunca matchea', ! B\Rules::condition(array('field'=>'content.message','op'=>'regex','value'=>'/['), array('content'=>array('message'=>'cualquier texto'))));
check('1j min_length / max_length', B\Rules::condition(array('field'=>'content.message','op'=>'min_length','value'=>'20'), array('content'=>array('message'=>str_repeat('a',25)))) && ! B\Rules::condition(array('field'=>'content.message','op'=>'max_length','value'=>'10'), array('content'=>array('message'=>str_repeat('a',25)))));

// ── 2. Scoring: monotonic, capture-only no CALIENTE ───────────────────────
$cap = B\Scoring::calculate(array('capture' => 100, 'qualification' => null, 'behavior_events' => array()));
check('2a capture-only 100 → no CALIENTE', $cap['temperature'] !== 'CALIENTE');
$plus = B\Scoring::calculate(array('capture' => 100, 'qualification' => null, 'behavior_events' => array(), 'intent' => 20));
check('2b intent añade (no baja)', $plus['total'] > $cap['total']);
$neg = B\Scoring::calculate(array('capture' => -5));
check('2c clamp inferior', $neg['total'] === 0);
$hi = B\Scoring::calculate(array('capture' => 120));
check('2d clamp superior capture', $hi['components']['capture'] === 100);

// ── 3. Scoring: behavior decay ────────────────────────────────────────────
check('3a decay fresco', B\Scoring::decay(0) === 1.0);
check('3b decay medio', B\Scoring::decay(20) === 0.5);
check('3c decay caduco', B\Scoring::decay(40) === 0.0);
$beh = B\Scoring::behavior_score(array(array('type' => 'recontacted', 'age_days' => 0)));
check('3d behavior recontacted 18', $beh['value'] === 18);

// ── 4. IntentMap ──────────────────────────────────────────────────────────
check('4a ventas → 20', B\IntentMap::derive('ventas')['points'] === 20);
check('4b desconocido → null', B\IntentMap::derive('zzz') === null);
check('4c vacío → null', B\IntentMap::derive('') === null);

// ── 4bis. Intents por ÁREA de negocio (sin hardcode de rubro) ─────────────
check('4d área por defecto = genérico', B\IntentMap::area() === 'generico');
check('4e preset genérico NO trae intents contables', ! array_key_exists('declaracion_renta', B\IntentMap::defaults()));
check('4f área inmobiliaria tiene preset propio', array_key_exists('agendar_visita', B\IntentPresets::preset('inmobiliaria')));
check('4g preset inmobiliaria NO trae intents contables', ! array_key_exists('cambio_contador', B\IntentPresets::preset('inmobiliaria')));
check('4h áreas incluyen inmobiliaria y contabilidad', B\IntentPresets::valid('inmobiliaria') && B\IntentPresets::valid('contabilidad'));
check('4i área inválida se normaliza al default', B\IntentMap::save(array('area' => 'no-existe', 'intents' => array('x' => 5)))['area'] === 'generico');
check('4j save normaliza puntos a 0-100', B\IntentMap::save(array('area' => 'retail', 'intents' => array('comprar' => 999)))['intents']['comprar'] === 100);

// ── 5. SignalLedger: idempotency + guards ─────────────────────────────────
$k = B\SignalLedger::idempotency_key('lead_created', 'lead', 7, '2026-08-01 00:00:00');
check('5a key estable', $k === B\SignalLedger::idempotency_key('lead_created', 'lead', 7, '2026-08-01 00:00:00'));
check('5b event inválido → 0 sin DB', B\SignalLedger::record(array('event_name' => 'nope', 'entity_id' => 1)) === 0);
check('5c recent vacío sin DB', B\SignalLedger::recent(5) === array());
check('5d click_ids desde el evento', B\SignalLedger::resolve_click_ids_for_capture( array( 'click_ids' => array( 'gclid' => 'G1', 'otro' => 'x' ) ) ) === array( 'gclid' => 'G1' ));
check('5e click_ids desde click_ids_json', B\SignalLedger::resolve_click_ids_for_capture( array( 'click_ids_json' => '{"wbraid":"W1"}' ) ) === array( 'wbraid' => 'W1' ));
check('5f sin click ids → vacío', B\SignalLedger::resolve_click_ids_for_capture( array() ) === array());

// ── 6. Adoption: equivalencias y proyección ───────────────────────────────
$eq = B\Adoption::event_equivalence();
check('6a recontacto mapeado', $eq['lead_recontacted'] === 'recontacted');
check('6b behavior_signals vacío sin DB', B\Adoption::behavior_signals(5) === array());
check('6c legacy_lead_score vacío sin DB', B\Adoption::legacy_lead_score(5) === array());
check('6d import no-op sin DB', B\Adoption::import_signals(5, true)['recorded'] === 0);

// ── 7. Safety gate ────────────────────────────────────────────────────────
check('7a tabla de señales propia', strpos(B\SignalLedger::TABLE, 'es_signal') !== 0);

// ── 8. Google OCI: click id + conversión (PURE, sin red) ──────────────────
$sig = array(
	'id'           => 7,
	'event_name'   => 'lead_created',
	'event_time'   => '2026-09-16 10:00:00',
	'entity_uuid'  => 'uuid-1',
	'value_decimal'=> 15000,
	'currency'     => 'CLP',
	'payload_json' => json_encode( array( 'click_ids' => array( 'gclid' => 'GCL-123' ) ) ),
);
check('8a click id extraído del payload', B\GoogleOci::click_id( $sig ) === array( 'type' => 'gclid', 'value' => 'GCL-123' ) );

$cfg = array( 'customer_id' => '1234567890', 'conv_lead_created' => '99887766' );
$conv = B\GoogleOci::build_conversion( $sig, $cfg );
check('8b build_conversion usa gclid + acción', is_array( $conv ) && $conv['gclid'] === 'GCL-123' && $conv['conversionAction'] === 'customers/1234567890/conversionActions/99887766' );
check('8c build_conversion incluye valor/moneda', $conv['conversionValue'] === 15000.0 && $conv['currencyCode'] === 'CLP' );

$no_click = $sig; $no_click['payload_json'] = json_encode( array() );
check('8d sin click id → null (no ensucia la cuenta)', B\GoogleOci::build_conversion( $no_click, $cfg ) === null );

$qualified = $sig; $qualified['event_name'] = 'lead_qualified';
check('8e lead_qualified no se sube a Google', B\GoogleOci::build_conversion( $qualified, $cfg ) === null );

check('8f supports() exige acción configurada (sin config → false)', B\GoogleOci::supports('lead_created') === false && B\GoogleOci::supports('lead_qualified') === false );
check('8g wbraid también sirve', B\GoogleOci::click_id( array( 'payload_json' => json_encode( array( 'wbraid' => 'W-9' ) ) ) )['type'] === 'wbraid' );

// ── 9. AssessmentEngine (calificación comercial v2) ───────────────────────
$rules_as = array( B\Rules::normalize( array(
	'id' => 'r-trabajo',
	'conditions' => array( array( 'field' => 'content.message', 'op' => 'contains', 'value' => 'trabajo' ) ),
	'action' => array( 'commercial_fit' => 'FUERA_DE_PERFIL' ),
	'priority' => 1,
) ) );
$a = B\AssessmentEngine::evaluate( array( 'message' => 'busco trabajo', 'content' => array( 'message' => 'busco trabajo' ), 'email' => 'ana@empresa.cl', 'name' => 'Ana' ), $rules_as );
check('9a regla → FUERA_DE_PERFIL', $a['commercial_fit'] === 'FUERA_DE_PERFIL');
check('9b hay evidencia usada', ! empty( $a['evidence_used'] ) );
check('9c integrity VALID con contacto válido', $a['integrity'] === 'VALID');

$b = B\AssessmentEngine::evaluate( array( 'message' => 'hola', 'email' => 'no-es-email', 'name' => 'X' ), array() );
check('9d email inválido → SPAM (delegado a Contactability)', $b['integrity'] === 'SPAM' && $b['commercial_fit'] === 'FUERA_DE_PERFIL' );

$c = B\AssessmentEngine::evaluate( array( 'message' => 'hola', 'email' => 'ok@dominio.cl', 'name' => 'Ana' ), array() );
check('9e sin reglas → DUDOSO y sin evidencia', $c['commercial_fit'] === 'DUDOSO' && empty( $c['evidence_used'] ) );
check('9f temperatura por defecto COLD', $c['temperature'] === 'COLD' );

$ld = B\AssessmentEngine::lead_data_from_capture( array( 'name' => 'Ana', 'email_normalized' => 'a@b.cl', 'phone_normalized' => '+56911111111', 'message' => 'hola' ) );
check('9g lead_data_from_capture mapea nombre/email/phone/mensaje', $ld['name'] === 'Ana' && $ld['email'] === 'a@b.cl' && $ld['phone'] === '+56911111111' && $ld['content']['message'] === 'hola' );

$GLOBALS['throws'] = false;
try { B\AssessmentEngine::on_capture( array( 'id' => 1, 'message' => 'x' ) ); } catch ( \Throwable $e ) { $GLOBALS['throws'] = true; }
check('9h on_capture es no-op sin Flow/DB (no lanza)', $GLOBALS['throws'] === false );

// ── 10. LeadScorer (LEAD_SCORE_CANONICAL_V1) ─────────────────────────────
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['__actions'][ $h ][ $p ] = $cb; return true; }
}
require dirname( __DIR__ ) . '/includes/LeadScorer.php';
check( '10a capture_score completo = 100', B\LeadScorer::capture_score( array( 'phone_normalized' => '+56911111111', 'email_normalized' => 'a@b.cl', 'name' => 'Ana' ) ) === 100 );
check( '10b capture_score solo email = 30', B\LeadScorer::capture_score( array( 'email' => 'a@b.cl' ) ) === 30 );
check( '10c capture_score vacío = 0', B\LeadScorer::capture_score( array() ) === 0 );
check( '10d phone/email crudos si no hay normalizados', B\LeadScorer::capture_score( array( 'phone' => '9', 'email' => 'x' ) ) === 80 );
B\LeadScorer::register();
check( '10e register engancha estandar_lead_ingested @20', ( $GLOBALS['__actions']['estandar_lead_ingested'][20] ?? null ) === array( 'Estandar\\Brain\\LeadScorer', 'on_lead_ingested' ) );
check( '10f score_lead sin tabla/DB no lanza', B\LeadScorer::score_lead( 0 ) === null );

// ── 11. LeadScorer QUALIFICATION_V1 (cualificación → score) ────────────────
$q_ideal_new = array(
	'cual_es_la_situacion_actual_de_tu_empresa' => 'esta_funcionando_y_facturando',
	'actualmente_tienes_contador'               => 'si_pero_quiero_cambiar',
	'cuando_necesitas_comenzar'                 => 'lo_antes_posible',
	'que_necesitas_resolver'                    => 'necesito_llevar_la_contabilidad_de_mi_empresa',
);
check( '11a ideal (sections) → 100', B\LeadScorer::qualification_from_fields( $q_ideal_new ) === 100 );

$q_ideal_old = array(
	'situacion_empresa'  => 'operando_facturando',
	'contador_actual'    => 'si_evaluando',
	'plazo_inicio'       => 'inmediato',
	'necesidad_principal' => 'contabilidad_mensual',
);
check( '11b ideal (fields_json legacy) → 100', B\LeadScorer::qualification_from_fields( $q_ideal_old ) === 100 );

$q_variant_c = array( 'tipo_cliente' => 'empresa_constituida', 'urgencia' => 'esta_semana', 'servicio' => 'asesoria-contable-mensual' );
check( '11c variante tipo_cliente/servicio/urgencia (guion) → 50', B\LeadScorer::qualification_from_fields( $q_variant_c ) === 50 );

$q_low = array(
	'cual_es_la_situacion_actual_de_tu_empresa' => 'aun_no_tengo_empresa',
	'actualmente_tienes_contador'               => 'no_necesito_contratar_uno',
	'cuando_necesitas_comenzar'                 => 'solo_estoy_evaluando_opciones',
	'que_necesitas_resolver'                    => 'quiero_emprender_pero_aun_no_se_que_necesito',
);
check( '11d anti-perfil (sin empresa/emprender) → 0', B\LeadScorer::qualification_from_fields( $q_low ) === 0 );

$q_capital = $q_ideal_new; $q_capital['hay_algo_mas_que_debamos_saber'] = 'Tengo un restaurán y me falta capital';
check( '11e L7 capital penaliza (100-35=65)', B\LeadScorer::qualification_from_fields( $q_capital ) === 65 );

$q_bet = $q_ideal_new; $q_bet['mensaje'] = 'quiero retirar dinero de betano';
check( '11f L6 apuestas penaliza (100-45=55)', B\LeadScorer::qualification_from_fields( $q_bet ) === 55 );

$q_h7 = $q_ideal_new; $q_h7['hay_algo_mas_que_debamos_saber'] = 'Somos un grupo de empresas con holding y matriz en el extranjero';
check( '11g H7 grupo internacional bonifica (clamp 100)', B\LeadScorer::qualification_from_fields( $q_h7 ) === 100 );

check( '11h sin preguntas de cualificación → null', B\LeadScorer::qualification_from_fields( array( 'nombre' => 'Ana' ) ) === null );

// 11m: gate de identidad semántica — mismo nombre de campo, otro vocabulario
// (caso estandar.cl `situacion_empresa=operando`) → null (sin regresión).
check( '11m vocabulario ajeno (situacion no reconocida) → null',
	B\LeadScorer::qualification_from_fields( array( 'situacion_empresa' => 'operando', 'urgencia' => 'este_mes' ) ) === null );

$intent_nece = B\LeadScorer::intent_from_fields( $q_ideal_new );
check( '11i intent derivado de la necesidad', $intent_nece === 'necesito_llevar_la_contabilidad_de_mi_empresa' );

$t_hot = B\Scoring::calculate( array( 'capture' => 100, 'qualification' => 100, 'behavior_events' => array() ) );
check( '11j ideal captura+cualificación → CALIENTE (≥70)', $t_hot['temperature'] === 'CALIENTE' );
$t_cold = B\Scoring::calculate( array( 'capture' => 100, 'qualification' => 0, 'behavior_events' => array() ) );
check( '11k captura alta sin cualificar → no CALIENTE', $t_cold['temperature'] !== 'CALIENTE' );
$t_old = B\Scoring::calculate( array( 'capture' => 100, 'qualification' => null, 'behavior_events' => array() ) );
check( '11l qualification null conserva comportamiento previo (50 TIBIO)', $t_old['total'] === 50 && $t_old['temperature'] === 'TIBIO' );

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
