<?php
/**
 * LEAD_ASSESSMENT_V1 — canonical taxonomy tests for Estandar_Leads_Fit.
 * Pure logic: no WP/DB required.
 *
 *   php tests/run-fit-taxonomy.php   → expect "0 failed"
 */

define('ABSPATH', __DIR__);
require dirname(__DIR__) . '/includes/class-estandar-leads-fit.php';

$passed = 0;
$failed = 0;
function check($label, $cond) {
    global $passed, $failed;
    if ($cond) { $passed++; echo "PASS  $label\n"; }
    else       { $failed++; echo "FAIL  $label\n"; }
}

$F = 'Estandar_Leads_Fit';

// 1. Closed enum — fit states (axis 1).
check('states are the 4 canonical fit states', $F::states() === array('MATCH', 'MATCH_PARCIAL', 'DUDOSO', 'FUERA_DE_PERFIL'));
check('FIT_NOT_ASSESSED is empty (absent = default)', $F::FIT_NOT_ASSESSED === '');
check('review_states = MATCH_PARCIAL + DUDOSO', $F::review_states() === array('MATCH_PARCIAL', 'DUDOSO'));

// 2. Reasons (axis 2) — canonical granular set.
check('reasons closed set', $F::reasons() === array(
    'OTHER_SERVICE', 'JOB_SEEKER', 'SUPPLIER', 'WRONG_INTENT', 'LOW_BUDGET',
    'OUTSIDE_REGION', 'PERSONAL_USE', 'STUDENT', 'UNKNOWN'
));

// 3. Integrity (axis 3) — separate from fit.
check('integrity states include OK/TEST/SPAM/FRAUDE', $F::integrity_states() === array('OK', 'TEST', 'SPAM', 'FRAUDE'));
check('SPAM is NOT a fit state', !in_array('SPAM', $F::states(), true));
check('FRAUDE is NOT a fit state', !in_array('FRAUDE', $F::states(), true));

// 4. Decision sources (explainability).
check('decision sources', $F::decision_sources() === array('deterministic', 'ai', 'manual', 'hybrid', 'none'));

// 5. Labels present for every canonical state/reason.
$labels = $F::labels();
foreach ($F::states() as $s) { check("label for state $s", isset($labels[$s]) && $labels[$s] !== ''); }
$rlabels = $F::reason_labels();
foreach ($F::reasons() as $r) { check("label for reason $r", isset($rlabels[$r]) && $rlabels[$r] !== ''); }

// 6. Validation / sanitization.
check('is_valid_state MATCH', $F::is_valid_state('MATCH'));
check('is_valid_state rejects SPAM (integrity, not fit)', !$F::is_valid_state('SPAM'));
check('is_valid_state rejects lowercase', !$F::is_valid_state('match'));
check('is_valid_reason JOB_SEEKER', $F::is_valid_reason('JOB_SEEKER'));
check('sanitize_state keeps canonical, rejects unknown', $F::sanitize_state('FUERA_DE_PERFIL') === 'FUERA_DE_PERFIL' && $F::sanitize_state('BOGUS') === '');
check('sanitize_reason keeps canonical, rejects unknown', $F::sanitize_reason('SUPPLIER') === 'SUPPLIER' && $F::sanitize_reason('X') === '');
check('sanitize_source', $F::sanitize_source('Manual') === 'manual' && $F::sanitize_source('x') === '');
check('sanitize_confidence clamps 0-100', $F::sanitize_confidence(150) === 100 && $F::sanitize_confidence(-5) === 0 && $F::sanitize_confidence(null) === null);
check('sanitize_confidence empty → null', $F::sanitize_confidence('') === null);

// 7. Default reason per state.
check('MATCH → empty reason', $F::default_reason_for_state('MATCH') === '');
check('FUERA_DE_PERFIL → UNKNOWN by default', $F::default_reason_for_state('FUERA_DE_PERFIL') === 'UNKNOWN');
check('DUDOSO → UNKNOWN by default', $F::default_reason_for_state('DUDOSO') === 'UNKNOWN');

// 8. Compatibility mappings to existing lost_reason vocabulary.
check('reason_to_lost_reason OTHER_SERVICE → OTHER_SERVICE', $F::reason_to_lost_reason('OTHER_SERVICE') === 'OTHER_SERVICE');
check('reason_to_lost_reason OUTSIDE_REGION → OUTSIDE_AREA', $F::reason_to_lost_reason('OUTSIDE_REGION') === 'OUTSIDE_AREA');
check('reason_to_lost_reason LOW_BUDGET → NO_BUDGET', $F::reason_to_lost_reason('LOW_BUDGET') === 'NO_BUDGET');
check('reason_to_lost_reason UNKNOWN → OTHER', $F::reason_to_lost_reason('UNKNOWN') === 'OTHER');
check('reason_to_lost_reason JOB_SEEKER → NOT_TARGET (best-effort)', $F::reason_to_lost_reason('JOB_SEEKER') === 'NOT_TARGET');
check('reason_to_lost_reason SUPPLIER → NOT_TARGET', $F::reason_to_lost_reason('SUPPLIER') === 'NOT_TARGET');
check('state_to_lost_reason FUERA_DE_PERFIL → NOT_TARGET', $F::state_to_lost_reason('FUERA_DE_PERFIL') === 'NOT_TARGET');
check('state_to_lost_reason MATCH → empty (not lost)', $F::state_to_lost_reason('MATCH') === '');
check('state_to_lost_reason DUDOSO → OTHER', $F::state_to_lost_reason('DUDOSO') === 'OTHER');

// 9. All reasons map to a valid lost-reason key in the existing vocabulary.
require dirname(__DIR__) . '/includes/class-estandar-leads-normalizer.php';
$known = array_keys(Estandar_Leads_Normalizer::lost_reason_labels());
$mapped_ok = true;
foreach ($F::reasons() as $r) {
    $lr = $F::reason_to_lost_reason($r);
    if (!in_array($lr, $known, true)) { $mapped_ok = false; echo "    (unmapped: $r → $lr)\n"; }
}
check('every reason maps to an existing lost_reason key', $mapped_ok);

// 10. Event vocabulary stable.
check('event names', $F::EVENT_ASSESSED === 'fit_assessed'
    && $F::EVENT_MANUAL_OVERRIDE === 'fit_manual_override'
    && $F::EVENT_ACCURACY === 'fit_accuracy_reviewed');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
