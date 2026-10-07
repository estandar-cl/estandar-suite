<?php
/**
 * LEAD_ASSESSMENT_V1 — AI adapter merge tests (MS-A06).
 * Pure logic: merge_ai is pure; AI class guarded for standalone.
 *
 *   php tests/run-assessment-ai.php   → expect "0 failed"
 */

define('ABSPATH', __DIR__);
if (!function_exists('sanitize_key')) {
    function sanitize_key($k) { return strtolower((string) preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k))); }
}
if (!function_exists('get_option')) { function get_option() { return array(); } }
if (!function_exists('update_option')) { function update_option() { return true; } }
require dirname(__DIR__) . '/includes/class-estandar-leads-normalizer.php';
require dirname(__DIR__) . '/includes/class-estandar-leads-fit.php';
require dirname(__DIR__) . '/includes/class-estandar-commercial-qualification-rules.php';
require dirname(__DIR__) . '/includes/class-estandar-commercial-qualification-engine.php';
require dirname(__DIR__) . '/includes/class-estandar-leads-assessment-engine.php';
require dirname(__DIR__) . '/includes/class-estandar-leads-assessment-ai.php';

$passed = 0;
$failed = 0;
function check($label, $cond) {
    global $passed, $failed;
    if ($cond) { $passed++; echo "PASS  $label\n"; }
    else       { $failed++; echo "FAIL  $label\n"; }
}

$E = 'Estandar_Leads_Assessment_Engine';
$R = 'Estandar_Commercial_Qualification_Rules';

function rule($id, $conditions, $action, $priority = 10) {
    return Estandar_Commercial_Qualification_Rules::normalize_rule(array(
        'id' => $id, 'name' => $id, 'source' => 'global', 'priority' => $priority,
        'conditions' => $conditions, 'action' => $action,
    ));
}

// Baseline outputs.
$absent  = $E::default_output();
$decided = $E::evaluate(array('content.message' => 'materiales'), $R::sort_rules(array(rule('r1',
    array(array('field' => 'content.message', 'op' => 'contains', 'value' => 'materiales')),
    array('commercial_fit' => 'FUERA_DE_PERFIL', 'fit_reason' => 'JOB_SEEKER', 'fit_confidence' => 80)))));

// ── 1. AI never overrides a rules-decided fit ─────────────────────────────
$ai = array('fit' => 'MATCH', 'fit_reason' => '', 'confidence' => 90, 'note' => 'IA cree que es match');
$m = $E::merge_ai($decided, $ai);
check('1a rules fit kept', $m['fit'] === 'FUERA_DE_PERFIL');
check('1b rules reason kept', $m['fit_reason'] === 'JOB_SEEKER');
check('1c ai_assessment attached', is_array($m['ai_assessment']) && ($m['ai_assessment']['note'] ?? '') !== '');
check('1d decision_source → hybrid', $m['decision_source'] === 'hybrid');
check('1e confidence NOT raised by AI', $m['confidence'] === 80);

// ── 2. AI may LOWER confidence ────────────────────────────────────────────
$m = $E::merge_ai($decided, array('fit' => 'FUERA_DE_PERFIL', 'confidence' => 40));
check('2a confidence lowered', $m['confidence'] === 40);

// ── 3. AI review flag ─────────────────────────────────────────────────────
$m = $E::merge_ai($decided, array('review' => true));
check('3a AI review flag sets review_required', $m['review_required'] === true);

// ── 4. AI missing_evidence hints appended ────────────────────────────────
$m = $E::merge_ai($decided, array('missing_evidence' => array('fields.situacion_empresa')));
check('4a missing evidence appended', in_array('fields.situacion_empresa', $m['evidence_missing'], true));

// ── 5. AI cannot create a fit when absent (no propose) ────────────────────
$m = $E::merge_ai($absent, array('fit' => 'FUERA_DE_PERFIL', 'confidence' => 60));
check('5a absent stays absent without propose flag', $m['absent'] === true && $m['fit'] === '');

// ── 6. AI proposal applied only when explicitly allowed ───────────────────
$m = $E::merge_ai($absent, array('fit' => 'DUDOSO', 'fit_reason' => 'WRONG_INTENT', 'confidence' => 55), true);
check('6a ai proposes fit', $m['absent'] === false && $m['fit'] === 'DUDOSO' && $m['fit_reason'] === 'WRONG_INTENT');
check('6b decision_source = ai', $m['decision_source'] === 'ai');
check('6c ai-proposed always review', $m['review_required'] === true);
check('6d ai-proposed confidence', $m['confidence'] === 55);

// ── 7. AI result sanitization (no raw free text leak) ─────────────────────
$ai = array('fit' => 'MATCH', 'fit_reason' => 'UNKNOWN', 'confidence' => 999, 'note' => str_repeat('x', 300));
$s = Estandar_Leads_Assessment_AI::sanitize_result($ai);
check('7a sanitize clamps confidence', $s['confidence'] === 100);
check('7b sanitize trims note', mb_strlen($s['note']) <= 200);
check('7c sanitize only canonical fit', $s['fit'] === 'MATCH');
check('7d sanitize drops unknown reason', $s['fit_reason'] === 'UNKNOWN');

// ── 8. current_output rebuild from read_lead_fit shape ────────────────────
$current = array(
    'fit' => 'FUERA_DE_PERFIL', 'fit_reason' => 'SUPPLIER', 'confidence' => 70,
    'decision_source' => 'deterministic', 'review_required' => false, 'updated_at' => '2026-08-01',
    'evidence' => array('activated_rules' => array('r1'), 'evidence_used' => array('content.message')),
);
$co = Estandar_Leads_Assessment_AI::current_output($current);
check('8a rebuild fit', $co['fit'] === 'FUERA_DE_PERFIL');
check('8b rebuild not absent', $co['absent'] === false);
check('8c rebuild evidence', $co['activated_rules'] === array('r1'));
$co2 = Estandar_Leads_Assessment_AI::current_output(array('fit' => '', 'evidence' => null));
check('8d rebuild absent when fit empty', $co2['absent'] === true);

// ── 9. Defaults: AI OFF ───────────────────────────────────────────────────
$s = Estandar_Leads_Assessment_AI::defaults();
check('9a ai disabled by default', $s['enabled'] === 0);
check('9b dry_run default ON', $s['dry_run'] === 1);
check('9c provider empty', $s['provider'] === '');

// ── 10. Provider selection is honest (AA-02 regression) ───────────────────
$prov = array('id' => 'my-provider', 'callable' => function() { return null; });
check('10a empty selection → active (backward compat)', Estandar_Leads_Assessment_AI::is_provider_active('', $prov) === true);
check('10b matching id → active', Estandar_Leads_Assessment_AI::is_provider_active('my-provider', $prov) === true);
check('10c mismatch id → inactive (settings must select honestly)', Estandar_Leads_Assessment_AI::is_provider_active('other-provider', $prov) === false);
check('10d case-insensitive id match', Estandar_Leads_Assessment_AI::is_provider_active('MY-PROVIDER', $prov) === true);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
