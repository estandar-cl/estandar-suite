<?php
/**
 * LEAD_ASSESSMENT_V1 — accuracy/learning tests (MS-A07).
 * Pure logic: classify/apply_counts/metrics are pure.
 *
 *   php tests/run-assessment-accuracy.php   → expect "0 failed"
 */

define('ABSPATH', __DIR__);
if (!function_exists('sanitize_key')) {
    function sanitize_key($k) { return strtolower((string) preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k))); }
}
if (!function_exists('get_option')) {
    function get_option($key, $default = array()) {
        global $__acc_opt;
        return isset($__acc_opt[$key]) ? $__acc_opt[$key] : $default;
    }
}
require dirname(__DIR__) . '/includes/class-estandar-leads-fit.php';
require dirname(__DIR__) . '/includes/class-estandar-leads-assessment-accuracy.php';

$passed = 0;
$failed = 0;
function check($label, $cond) {
    global $passed, $failed;
    if ($cond) { $passed++; echo "PASS  $label\n"; }
    else       { $failed++; echo "FAIL  $label\n"; }
}

$A = 'Estandar_Leads_Assessment_Accuracy';

// ── 1. Ground truth = outcome (operator NOT authoritative) ────────────────
$r = $A::classify(array('fit' => 'MATCH', 'status' => 'CLIENT', 'is_valid_lead' => 1, 'has_revenue' => false));
check('1a CLIENT → gt MATCH (outcome basis)', $r['verdict'] === 'resolved' && $r['ground_truth'] === 'MATCH' && $r['basis'] === 'outcome');
check('1b MATCH prediction correct', $r['prediction_correct'] === true);

// The key case: prediction + operator said wrong-fit, but the lead became CLIENT.
$r = $A::classify(array('fit' => 'FUERA_DE_PERFIL', 'status' => 'CLIENT', 'is_valid_lead' => 0, 'invalid_reason' => 'NOT_TARGET', 'has_revenue' => false));
check('1c outcome beats operator+prediction (false positive)', $r['ground_truth'] === 'MATCH' && $r['prediction_correct'] === false);

$r = $A::classify(array('fit' => 'MATCH', 'status' => 'DELIVERED', 'is_valid_lead' => null, 'has_revenue' => true));
check('1d revenue alone → gt MATCH', $r['ground_truth'] === 'MATCH' && $r['basis'] === 'outcome');

// ── 2. Operator negative verdict resolves wrong-fit ───────────────────────
$r = $A::classify(array('fit' => 'FUERA_DE_PERFIL', 'status' => 'INVALID', 'is_valid_lead' => 0, 'invalid_reason' => 'NOT_TARGET'));
check('2a operator negative → gt FUERA_DE_PERFIL', $r['ground_truth'] === 'FUERA_DE_PERFIL' && $r['basis'] === 'operator');
check('2b prediction correct', $r['prediction_correct'] === true);
$r = $A::classify(array('fit' => 'MATCH', 'status' => 'INVALID', 'is_valid_lead' => 0, 'invalid_reason' => 'NOT_TARGET'));
check('2c MATCH prediction on wrong-fit → false positive', $r['prediction_correct'] === false);

// ── 3. LOST alone is no evidence ──────────────────────────────────────────
$r = $A::classify(array('fit' => 'MATCH', 'status' => 'LOST', 'is_valid_lead' => null));
check('3a LOST → pending (no ground truth)', $r['verdict'] === 'pending');

// ── 4. Counters (binary decided axis) ─────────────────────────────────────
$agg = array();
$agg = $A::apply_counts($agg, $A::classify(array('fit' => 'MATCH', 'status' => 'CLIENT')));
check('4a tp MATCH', $agg['by_class']['MATCH']['tp'] === 1 && $agg['total'] === 1 && $agg['correct'] === 1);

$agg = $A::apply_counts($agg, $A::classify(array('fit' => 'FUERA_DE_PERFIL', 'status' => 'CLIENT')));
check('4b fp FUERA + fn MATCH', $agg['by_class']['FUERA_DE_PERFIL']['fp'] === 1 && $agg['by_class']['MATCH']['fn'] === 1 && $agg['correct'] === 1);

$agg = $A::apply_counts($agg, $A::classify(array('fit' => 'FUERA_DE_PERFIL', 'status' => 'INVALID', 'is_valid_lead' => 0, 'invalid_reason' => 'NOT_TARGET')));
check('4c tp FUERA', $agg['by_class']['FUERA_DE_PERFIL']['tp'] === 1 && $agg['correct'] === 2);

// Cautious prediction → undecided, excluded from precision.
$agg = $A::apply_counts($agg, $A::classify(array('fit' => 'MATCH_PARCIAL', 'status' => 'CLIENT')));
check('4d cautious → undecided', $agg['undecided'] === 1 && $agg['total'] === 4);

// ── 5. Metrics ────────────────────────────────────────────────────────────
$m = $A::metrics($agg);
check('5a accuracy computed', $m['accuracy'] === 50.0 && $m['total'] === 4);
check('5b MATCH precision 0', $m['by_class']['MATCH']['precision'] === 100.0);
check('5c MATCH recall 50', $m['by_class']['MATCH']['recall'] === 50.0);
check('5d MATCH f1', $m['by_class']['MATCH']['f1'] === 66.7);
check('5e FUERA precision 50', $m['by_class']['FUERA_DE_PERFIL']['precision'] === 50.0);

// ── 6. Empty metrics → null-safe ──────────────────────────────────────────
$m = $A::metrics(array());
check('6a empty metrics', $m['accuracy'] === null && $m['total'] === 0 && $m['by_class'] === array());

// ── 7. No prediction → nothing to learn ───────────────────────────────────
$r = $A::classify(array('fit' => '', 'status' => 'CLIENT'));
check('7a empty fit still classifies outcome (guard in record())', $r['ground_truth'] === 'MATCH');

// ── 8. Persisted metrics have a READER (FP-03: no dead data) ─────────────
global $__acc_opt;
$__acc_opt['estandar_leads_assessment_accuracy_install'] = array(
    'total' => 10, 'correct' => 8, 'undecided' => 2,
    'by_class' => array('MATCH' => array('tp' => 5, 'fp' => 1, 'fn' => 1)),
);
$mi = $A::metrics_install();
check('8a install metrics reader works', $mi['total'] === 10 && $mi['accuracy'] === 80.0);
$__acc_opt['estandar_leads_assessment_accuracy_project'] = array(
    'ak-contadores' => array('total' => 4, 'correct' => 3, 'by_class' => array()),
);
$mp = $A::metrics_project('ak-contadores');
check('8b project metrics reader works', $mp['total'] === 4 && $mp['accuracy'] === 75.0);
$mp2 = $A::metrics_project('desconocido');
check('8c unknown project → empty metrics', $mp2['total'] === 0 && $mp2['accuracy'] === null);
check('8d empty slug → empty metrics', $A::metrics_project('')['total'] === 0);
$__acc_opt['estandar_leads_assessment_accuracy_install'] = array();
check('8e install reader with no data → empty', $A::metrics_install()['total'] === 0);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
