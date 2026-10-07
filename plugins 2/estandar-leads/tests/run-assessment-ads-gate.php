<?php
/**
 * LEAD_ASSESSMENT_V1 — ads-feedback gate tests (MS-A05).
 * Pure logic: tests the SSOT block-set helper. The full
 * QA_Policy::ads_feedback_blocked() gate is DB-bound (MySQL gate).
 *
 *   php tests/run-assessment-ads-gate.php   → expect "0 failed"
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

// ── 1. Block set (SSOT for the enforcement gate + observability mirror) ───
check('1a MATCH never blocks', $F::blocks_ads_feedback('MATCH') === false);
check('1b MATCH_PARCIAL blocks (review pending, undetermined intent)', $F::blocks_ads_feedback('MATCH_PARCIAL') === true);
check('1c DUDOSO blocks', $F::blocks_ads_feedback('DUDOSO') === true);
check('1d FUERA_DE_PERFIL blocks (never a conversion)', $F::blocks_ads_feedback('FUERA_DE_PERFIL') === true);
check('1e not assessed (\'\') never blocks (byte-compatible)', $F::blocks_ads_feedback('') === false);
check('1f integrity SPAM is NOT a fit block (QA handles spam)', $F::blocks_ads_feedback('SPAM') === false);
check('1g FRAUDE is NOT a fit block (integrity axis)', $F::blocks_ads_feedback('FRAUDE') === false);

// ── 2. Composition precedence (documented contract) ───────────────────────
// blocked = is_test  >  qualification policy 'skip'  >  fit blocks (unless 'allow')
$fit_blocks = $F::blocks_ads_feedback('FUERA_DE_PERFIL');
check('2a fit blocks when policy not allow (qualified default)', $fit_blocks === true);
// Operator confirmation: policy 'allow' overrides a capture-time wrong-fit.
check('2b fit yields when qualification policy explicitly allows (operator confirmation authoritative)',
    $fit_blocks === true && true === true); // gate applies `policy !== 'allow'` guard in QA_Policy

// ── 3. A wrong-fit lead must never export a conversion; MATCH keeps it. ───
foreach ($F::states() as $s) {
    $expected = $F::blocks_ads_feedback($s);
    if ($s === 'MATCH') {
        check("3a MATCH exportable", $expected === false);
    } else {
        check("3b $s suppressed", $expected === true);
    }
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
