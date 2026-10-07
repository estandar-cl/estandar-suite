<?php
/**
 * LEAD_ASSESSMENT_V1 — SSOT ads-export gate tests (AA-02/AA-07).
 * Regression for the CRITICAL defect: a wrong-fit lead with the DEFAULT
 * qualification decision (decision_source='default', policy='allow') was
 * exported as a conversion — the core business case failed.
 *
 * Pure logic: no WP/DB.
 *
 *   php tests/run-assessment-gate-ssot.php   → expect "0 failed"
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

// ── 1. CRITICAL regression: default decision must NOT lift the fit block ──
// Default decision: source='default', policy='allow'. Wrong-fit lead at NEW.
$blocked = $F::should_block_ads_export('FUERA_DE_PERFIL', 'NEW', null, 'default', 'allow');
check('1a default decision + FUERA_DE_PERFIL → BLOCKED (was the bug)', $blocked === true);

// ── 2. Not assessed never blocks (byte-compatible) ────────────────────────
check('2a fit empty → not blocked', $F::should_block_ads_export('', 'NEW', null, 'default', 'allow') === false);

// ── 3. MATCH never blocks ─────────────────────────────────────────────────
check('3a MATCH → not blocked', $F::should_block_ads_export('MATCH', 'NEW', null, 'default', 'allow') === false);

// ── 4. MATCH_PARCIAL / DUDOSO block at default ────────────────────────────
check('4a MATCH_PARCIAL → blocked', $F::should_block_ads_export('MATCH_PARCIAL', 'NEW', null, 'default', 'allow') === true);
check('4b DUDOSO → blocked', $F::should_block_ads_export('DUDOSO', 'NEW', null, 'default', 'allow') === true);

// ── 5. Operator confirmation lifts the block ──────────────────────────────
check('5a manual override source lifts', $F::should_block_ads_export('FUERA_DE_PERFIL', 'NEW', null, 'manual', 'allow') === false);
check('5b is_valid_lead=1 lifts', $F::should_block_ads_export('FUERA_DE_PERFIL', 'NEW', 1, 'default', 'allow') === false);
check('5c positive status lifts', $F::should_block_ads_export('FUERA_DE_PERFIL', 'QUALIFIED', null, 'default', 'allow') === false);

// ── 6. Explicit disqualification policy blocks everything ─────────────────
check('6a policy skip → blocked even on MATCH', $F::should_block_ads_export('MATCH', 'NEW', null, 'default', 'skip') === true);
check('6b policy skip + manual → blocked (skip is stronger)', $F::should_block_ads_export('MATCH', 'NEW', null, 'manual', 'skip') === true);

// ── 7. Integrity SPAM/FRAUDE are NOT fit gates (QA handles) ───────────────
check('7a SPAM not a fit block', $F::should_block_ads_export('SPAM', 'NEW', null, 'default', 'allow') === false);

// ── 8. Positive status list coherence ─────────────────────────────────────
check('8a QUALIFIED is positive', $F::is_positive_commercial_status('QUALIFIED'));
check('8b NEW is not positive', !$F::is_positive_commercial_status('NEW'));
check('8c LOST is not positive', !$F::is_positive_commercial_status('LOST'));
check('8d lowercase normalized', $F::is_positive_commercial_status('client'));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
