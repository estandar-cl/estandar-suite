<?php
/**
 * LEAD_ASSESSMENT_V1 — persistence/explainability tests (MS-A03).
 * Pure logic (no WP/DB): guards keep DB paths inert without $wpdb.
 *
 *   php tests/run-assessment-persistence.php   → expect "0 failed"
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

function rule($id, $conditions, $action, $priority = 10) {
    return Estandar_Commercial_Qualification_Rules::normalize_rule(array(
        'id' => $id, 'name' => $id, 'source' => 'global', 'priority' => $priority,
        'conditions' => $conditions, 'action' => $action,
    ));
}

// ── 1. evidence_payload: no PII, canonical keys ───────────────────────────
$out = $E::evaluate(array('content.message' => 'materiales'), $R::sort_rules(array(rule('r1',
    array(array('field' => 'content.message', 'op' => 'contains', 'value' => 'materiales')),
    array('commercial_fit' => 'FUERA_DE_PERFIL', 'fit_reason' => 'JOB_SEEKER', 'fit_confidence' => 70)))));
$ep = $E::evidence_payload($out);
check('1a evidence payload has fit', $ep['fit'] === 'FUERA_DE_PERFIL');
check('1b has reason/confidence/source', $ep['fit_reason'] === 'JOB_SEEKER' && $ep['confidence'] === 70 && $ep['decision_source'] === 'deterministic');
check('1c has activated rules', $ep['activated_rules'] === array('r1'));
check('1d has evidence + sources', in_array('content.message', $ep['evidence_used'], true) && ($ep['data_sources']['content.message'] ?? '') === 'free_text');
check('1e no PII keys in payload', !isset($ep['email']) && !isset($ep['phone']) && !isset($ep['contact']));
check('1f absent output payload → fit empty', $E::evidence_payload($E::default_output())['fit'] === '');

// ── 2. absent → persist is a no-op without $wpdb ──────────────────────────
check('2a persist no-op when absent', $E::persist(1, 1, $E::default_output()) === null);

// ── 3. manual override merge (pure, lead_id=0 skips DB) ───────────────────
$mo = $E::apply_manual_override(0, array('fit' => 'FUERA_DE_PERFIL', 'fit_reason' => 'SUPPLIER', 'confidence' => 90, 'reason' => 'operador verificó'));
check('3a manual fit applied', $mo['fit'] === 'FUERA_DE_PERFIL' && $mo['fit_reason'] === 'SUPPLIER');
check('3b manual decision_source', $mo['decision_source'] === 'manual');
check('3c manual confidence 90', $mo['confidence'] === 90);
check('3d manual not absent', $mo['absent'] === false);
check('3e manual policy_reason kept', $mo['policy_reason'] === 'operador verificó');

$mo2 = $E::apply_manual_override(0, array('fit' => 'MATCH'));
check('3f manual MATCH → reason empty + not review', $mo2['fit_reason'] === '' && $mo2['review_required'] === false);
$mo3 = $E::apply_manual_override(0, array('fit' => 'DUDOSO'));
check('3g manual DUDOSO → review required', $mo3['review_required'] === true);
$mo4 = $E::apply_manual_override(0, array('fit' => ''));
check('3h manual empty fit → absent', $mo4['absent'] === true);
$mo5 = $E::apply_manual_override(0, array('fit' => 'SPAM'));
check('3i manual rejects non-fit state (SPAM is integrity)', $mo5['absent'] === true);

// ── 4. read_lead_fit inert without $wpdb ──────────────────────────────────
check('4a read_lead_fit returns [] without $wpdb', $E::read_lead_fit(1) === array());

// ── 5. explainability completeness on a non-absent decision ───────────────
$required = array('fit', 'fit_reason', 'confidence', 'decision_source', 'activated_rules',
    'evidence_used', 'evidence_missing', 'data_sources', 'review_required', 'absent');
$complete = true;
foreach ($required as $k) {
    if (!array_key_exists($k, $out)) { $complete = false; echo "    (missing: $k)\n"; }
}
check('5a all explainability keys present', $complete);
check('5b decision_source is deterministic', $out['decision_source'] === 'deterministic');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
