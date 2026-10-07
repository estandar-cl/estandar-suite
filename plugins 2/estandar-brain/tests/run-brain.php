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
require dirname(__DIR__) . '/includes/IntentMap.php';
require dirname(__DIR__) . '/includes/SignalLedger.php';
require dirname(__DIR__) . '/includes/Adoption.php';

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

// ── 5. SignalLedger: idempotency + guards ─────────────────────────────────
$k = B\SignalLedger::idempotency_key('lead_created', 'lead', 7, '2026-08-01 00:00:00');
check('5a key estable', $k === B\SignalLedger::idempotency_key('lead_created', 'lead', 7, '2026-08-01 00:00:00'));
check('5b event inválido → 0 sin DB', B\SignalLedger::record(array('event_name' => 'nope', 'entity_id' => 1)) === 0);
check('5c recent vacío sin DB', B\SignalLedger::recent(5) === array());

// ── 6. Adoption: equivalencias y proyección ───────────────────────────────
$eq = B\Adoption::event_equivalence();
check('6a recontacto mapeado', $eq['lead_recontacted'] === 'recontacted');
check('6b behavior_signals vacío sin DB', B\Adoption::behavior_signals(5) === array());
check('6c legacy_lead_score vacío sin DB', B\Adoption::legacy_lead_score(5) === array());
check('6d import no-op sin DB', B\Adoption::import_signals(5, true)['recorded'] === 0);

// ── 7. Safety gate ────────────────────────────────────────────────────────
check('7a tabla de señales propia', strpos(B\SignalLedger::TABLE, 'es_signal') !== 0);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
