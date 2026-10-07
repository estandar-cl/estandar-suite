<?php
/**
 * LEAD_ASSESSMENT_V1 — deterministic engine tests (MS-A02).
 * Pure logic: no WP/DB required (class_exists/function_exists guards).
 *
 *   php tests/run-assessment-engine.php   → expect "0 failed"
 */

define('ABSPATH', __DIR__);
require dirname(__DIR__) . '/includes/class-estandar-leads-normalizer.php';
require dirname(__DIR__) . '/includes/class-estandar-leads-fit.php';
require dirname(__DIR__) . '/includes/class-estandar-commercial-qualification-rules.php';
require dirname(__DIR__) . '/includes/class-estandar-commercial-qualification-engine.php';
require dirname(__DIR__) . '/includes/class-estandar-leads-assessment-engine.php';

$passed = 0;
$failed = 0;
function check($label, $cond) {
    global $passed, $failed;
    if ($cond) { $passed++; echo "PASS  $label\n"; }
    else       { $failed++; echo "FAIL  $label\n"; }
}

$E = 'Estandar_Leads_Assessment_Engine';
$R = 'Estandar_Commercial_Qualification_Rules';

// Helper: build a normalized rule via the canonical normalizer.
function rule($id, $conditions, $action, $priority = 10, $source = 'global') {
    return Estandar_Commercial_Qualification_Rules::normalize_rule(array(
        'id' => $id, 'name' => $id, 'source' => $source, 'priority' => $priority,
        'conditions' => $conditions, 'action' => $action,
    ));
}

// ── 1. Absence = default ──────────────────────────────────────────────────
$out = $E::evaluate(array('fields.service' => 'contabilidad'), array());
check('1a no rules → absent', $out['absent'] === true);
check('1b absent → fit empty', $out['fit'] === '');
check('1c absent → decision_source none', $out['decision_source'] === 'none');
check('1d absent → confidence null', $out['confidence'] === null);

// ── 2. Free-text rule → FUERA_DE_PERFIL + reason ──────────────────────────
$rules = $R::sort_rules(array(rule('r-materials',
    array(array('field' => 'content.message', 'op' => 'contains', 'value' => 'materiales')),
    array('commercial_fit' => 'FUERA_DE_PERFIL', 'fit_reason' => 'JOB_SEEKER', 'fit_confidence' => 70)
)));
$out = $E::evaluate(array('content.message' => 'necesito ayuda para comprar materiales para mi trabajo'), $rules);
check('2a fit = FUERA_DE_PERFIL', $out['fit'] === 'FUERA_DE_PERFIL');
check('2b reason = JOB_SEEKER', $out['fit_reason'] === 'JOB_SEEKER');
check('2c confidence = 70', $out['confidence'] === 70);
check('2d decision_source deterministic', $out['decision_source'] === 'deterministic');
check('2e activated_rules', $out['activated_rules'] === array('r-materials'));
check('2f evidence_used', in_array('content.message', $out['evidence_used'], true));
check('2g not absent', $out['absent'] === false);
check('2h data_source free_text', ($out['data_sources']['content.message'] ?? '') === 'free_text');

// ── 3. Data-tier precedence: structured overrides free text ───────────────
$rules = $R::sort_rules(array(
    rule('r-ft', array(array('field' => 'content.message', 'op' => 'contains', 'value' => 'trabajo')),
        array('commercial_fit' => 'FUERA_DE_PERFIL', 'fit_reason' => 'JOB_SEEKER'), 1),
    rule('r-struct', array(array('field' => 'fields.tipo_cliente', 'op' => 'equals', 'value' => 'empresa')),
        array('commercial_fit' => 'MATCH'), 1),
));
$out = $E::evaluate(array('content.message' => 'busco trabajo', 'fields.tipo_cliente' => 'empresa'), $rules);
check('3a structured wins over free text (tier precedence)', $out['fit'] === 'MATCH');
check('3b MATCH reason empty', $out['fit_reason'] === '');

// ── 4. Priority within same tier ──────────────────────────────────────────
$rules = $R::sort_rules(array(
    rule('r-low', array(array('field' => 'fields.region', 'op' => 'equals', 'value' => 'x')),
        array('commercial_fit' => 'DUDOSO'), 20),
    rule('r-high', array(array('field' => 'fields.region', 'op' => 'equals', 'value' => 'x')),
        array('commercial_fit' => 'FUERA_DE_PERFIL', 'fit_reason' => 'OUTSIDE_REGION'), 5),
));
$out = $E::evaluate(array('fields.region' => 'x'), $rules);
check('4a lower priority number wins', $out['fit'] === 'FUERA_DE_PERFIL' && $out['fit_reason'] === 'OUTSIDE_REGION');

// ── 5. stop rule halts evaluation ─────────────────────────────────────────
$rules = $R::sort_rules(array(
    rule('r-stop', array(array('field' => 'has_phone', 'op' => 'equals', 'value' => '1')),
        array('commercial_fit' => 'FUERA_DE_PERFIL', 'fit_reason' => 'WRONG_INTENT', 'stop' => true), 1),
    rule('r-after', array(array('field' => 'fields.region', 'op' => 'equals', 'value' => 'y')),
        array('commercial_fit' => 'MATCH'), 5),
));
$out = $E::evaluate(array('has_phone' => '1', 'fields.region' => 'y'), $rules);
check('5a stop halts', $out['fit'] === 'FUERA_DE_PERFIL');
check('5b rule after stop not activated', !in_array('r-after', $out['activated_rules'], true));

// ── 6. Review states ──────────────────────────────────────────────────────
$rules = $R::sort_rules(array(rule('r-par', array(array('field' => 'has_email', 'op' => 'equals', 'value' => '1')),
    array('commercial_fit' => 'MATCH_PARCIAL'))));
$out = $E::evaluate(array('has_email' => '1'), $rules);
check('6a MATCH_PARCIAL → review_required true', $out['review_required'] === true);
$rules = $R::sort_rules(array(rule('r-mat', array(array('field' => 'has_email', 'op' => 'equals', 'value' => '1')),
    array('commercial_fit' => 'MATCH'))));
$out = $E::evaluate(array('has_email' => '1'), $rules);
check('6b MATCH → review_required false', $out['review_required'] === false);

// ── 7. Default reason UNKNOWN when not provided ───────────────────────────
$rules = $R::sort_rules(array(rule('r-no-reason', array(array('field' => 'fields.servicio', 'op' => 'empty', 'value' => '')),
    array('commercial_fit' => 'DUDOSO'))));
$out = $E::evaluate(array('fields.servicio' => ''), $rules);
check('7a DUDOSO default reason UNKNOWN', $out['fit_reason'] === 'UNKNOWN');

// ── 8. Integrity via rule action (separate axis) ──────────────────────────
$rules = $R::sort_rules(array(rule('r-fraud', array(array('field' => 'fields.rut', 'op' => 'regex', 'value' => '/^1[0-9]{7}-[0-9Kk]$/')),
    array('integrity' => 'FRAUDE', 'commercial_fit' => 'MATCH'))));
$out = $E::evaluate(array('fields.rut' => '11111111-1'), $rules);
check('8a integrity FRAUDE short-circuits fit', $out['integrity'] === 'FRAUDE' && $out['fit'] === '');
check('8b integrity output is absent', $out['absent'] === true);

// ── 9. Evidence missing ───────────────────────────────────────────────────
$rules = $R::sort_rules(array(rule('r-need', array(array('field' => 'fields.necesidad_principal', 'op' => 'equals', 'value' => 'contabilidad')),
    array('commercial_fit' => 'MATCH'))));
$out = $E::evaluate(array('has_email' => '1'), $rules);
check('9a rule did not match → absent', $out['absent'] === true);
$rules = $R::sort_rules(array(rule('r-need2', array(array('field' => 'fields.necesidad_principal', 'op' => 'field_exists', 'value' => '')),
    array('commercial_fit' => 'MATCH_PARCIAL'))));
$out = $E::evaluate(array('has_email' => '1'), $rules);
check('9b no match when referenced field absent', $out['absent'] === true);

// ── 10. assess() orchestration (standalone) ───────────────────────────────
$out = $E::assess(array(
    'source_plugin' => 'estandar-forms',
    'contact' => array('name' => 'Sandra Melisa', 'email' => 's@x.cl', 'phone' => '+56912345678'),
    'content' => array('message' => 'necesito ayuda para comprar materiales para mi trabajo'),
    'attribution' => array('utm_source' => 'google', 'utm_medium' => 'cpc'),
    'spam' => array('honeypot_filled' => false),
));
check('10a assess with no configured rules → absent', $out['absent'] === true && $out['fit'] === '');

// QA test short-circuit (simulated QA payload via explicit qa group not possible
// without QA_Policy; resolve_integrity returns OK when QA_Policy absent).
check('10b integrity OK when no spam/qa signals', $E::resolve_integrity(array('spam' => array('honeypot_filled' => false))) === 'OK');
check('10c honeypot → SPAM', $E::resolve_integrity(array('spam' => array('honeypot_filled' => true))) === 'SPAM');

// ── 11. field_source provenance ───────────────────────────────────────────
check('11a form source', $E::field_source('fields.servicio') === 'form');
check('11b message free_text', $E::field_source('content.message') === 'free_text');
check('11c derived', $E::field_source('has_email') === 'derived');
check('11d configured fallback', $E::field_source('custom_key') === 'configured');

// ── 12. Confidence clamp + default ────────────────────────────────────────
$rules = $R::sort_rules(array(rule('r-clamp', array(array('field' => 'has_phone', 'op' => 'equals', 'value' => '1')),
    array('commercial_fit' => 'FUERA_DE_PERFIL', 'fit_confidence' => 999))));
$out = $E::evaluate(array('has_phone' => '1'), $rules);
check('12a confidence clamped to 100', $out['confidence'] === 100);
$rules = $R::sort_rules(array(rule('r-def', array(array('field' => 'has_phone', 'op' => 'equals', 'value' => '1')),
    array('commercial_fit' => 'FUERA_DE_PERFIL'))));
$out = $E::evaluate(array('has_phone' => '1'), $rules);
check('12b default confidence 50', $out['confidence'] === 50);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
