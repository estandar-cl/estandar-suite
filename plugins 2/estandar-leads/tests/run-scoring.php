<?php
/**
 * BEHAVIORAL_SCORE_V1 — pure-logic tests for Estandar_Leads_Scoring::calculate().
 * No WP / DB required: calculate() is a pure function.
 *
 *   php tests/run-scoring.php   → expect "0 failed"
 */

define('ABSPATH', __DIR__);
require dirname(__DIR__) . '/includes/class-estandar-leads-scoring.php';

$passed = 0;
$failed = 0;
function check($label, $cond) {
    global $passed, $failed;
    if ($cond) { $passed++; echo "PASS  $label\n"; }
    else       { $failed++; echo "FAIL  $label\n"; }
}

$S = 'Estandar_Leads_Scoring';

// Helper: prospect behavioral event.
function ev($type, $age = 0.0, $actor = 'anonymous_token') {
    return array('type' => $type, 'actor_type' => $actor, 'age_days' => $age);
}
function base($over = array()) {
    return array_merge(array(
        'capture' => null, 'qualification' => null, 'behavior_events' => array(),
        'is_valid_lead' => 1, 'quality' => '', 'status' => 'NEW', 'sales_stage' => '',
        'gestion' => array('contact_attempts' => 0, 'feedback_completed' => 0, 'workspace_opened' => false),
        'thresholds' => array('hot' => 70, 'warm' => 40),
    ), $over);
}

// 1. Capture only → behavior=0 pondera igual: (0.3*50 + 0.3*0)/0.6 = 25.
$r = $S::calculate(base(array('capture' => 50)));
check('1 capture only → 25 (behavior=0 weighs in)', $r['total'] === 25);

// 1b. BUG FIX (contrato funcional): capture=100, behavior=0 (behavior_detail=[]),
//     qualification=null → nunca CALIENTE ni ALTA/CRITICA.
$r100 = $S::calculate(base(array('capture' => 100)));
check('1b capture-only 100: no CALIENTE, no ALTA',
    $r100['temperature'] !== 'CALIENTE'
    && $r100['priority'] !== 'ALTA' && $r100['priority'] !== 'CRITICA'
    && $r100['components']['behavior'] === 0
    && $r100['components']['behavior_detail'] === array()
    && $r100['components']['qualification'] === null);

// 2. Behavior only → TOTAL = behavior value (recent engaged = 15).
$r = $S::calculate(base(array('behavior_events' => array(ev('autoresponse_engaged', 0)))));
check('2 behavior only → 15', $r['total'] === 15);

// 3. Reponderación (capture+behavior, qual absent) → (0.3*60 + 0.3*15)/0.6 = 37.5 → 38.
$r = $S::calculate(base(array('capture' => 60, 'behavior_events' => array(ev('autoresponse_engaged', 0)))));
check('3 reweight capture+behavior → 38', $r['total'] === 38);

// 4. Clamp 0–100 en el componente (capture 120 → 100; total = 0.3*100/0.6 = 50).
$hi = $S::calculate(base(array('capture' => 120)));
$lo = $S::calculate(base(array('capture' => -20)));
check('4a clamp upper: capture 120 → 100 (total 50)', $hi['components']['capture'] === 100 && $hi['total'] === 50);
check('4b clamp lower → 0', $lo['total'] === 0);

// 5. Recontacto incrementa score (behavior-only).
$without = $S::calculate(base(array('behavior_events' => array(ev('autoresponse_email_open_signal', 0, 'system')))));
$with    = $S::calculate(base(array('behavior_events' => array(
    ev('autoresponse_email_open_signal', 0, 'system'), ev('lead_recontacted', 0),
))));
check('5 recontact raises total', $with['total'] > $without['total']);

// 6. actor_type=admin no influye (capture 40, behavior 0 → (0.3*40)/0.6 = 20).
$capOnly = $S::calculate(base(array('capture' => 40)));
$withAdmin = $S::calculate(base(array('capture' => 40, 'behavior_events' => array(ev('lead_recontacted', 0, 'admin')))));
check('6 admin event ignored', $withAdmin['total'] === $capOnly['total'] && $withAdmin['total'] === 20);

// 7. actor_type=operator no influye.
$withOperator = $S::calculate(base(array('capture' => 40, 'behavior_events' => array(ev('lead_recontacted', 0, 'operator')))));
check('7 operator event ignored', $withOperator['total'] === 20);

$withUser = $S::calculate(base(array('capture' => 40, 'behavior_events' => array(ev('lead_recontacted', 0, 'user')))));
$withSystem = $S::calculate(base(array('capture' => 40, 'behavior_events' => array(ev('lead_recontacted', 0, 'system')))));
check('7b user/system events ignored', $withUser['total'] === 20 && $withSystem['total'] === 20);
$ineligibleEvent = ev('lead_recontacted');
$ineligibleEvent['score_eligible'] = false;
$withIneligible = $S::calculate(base(array('capture' => 40, 'behavior_events' => array($ineligibleEvent))));
check('7c score_eligible=false event ignored', $withIneligible['total'] === 20);

// 8. Decay reduce score (recent > partial > old).
$recent  = $S::calculate(base(array('behavior_events' => array(ev('autoresponse_engaged', 0)))));     // mult 1.0 → 15
$partial = $S::calculate(base(array('behavior_events' => array(ev('autoresponse_engaged', 4)))));     // mult 0.5 → 8
$old     = $S::calculate(base(array('behavior_events' => array(ev('autoresponse_engaged', 10)))));    // mult 0   → 0
check('8 decay: recent > partial > old', $recent['total'] > $partial['total'] && $partial['total'] > $old['total'] && $old['total'] === 0);

// 9. Lead inválido → score 0.
$r = $S::calculate(base(array('capture' => 90, 'is_valid_lead' => 0)));
check('9 invalid → 0 / FRIO / BAJA', $r['total'] === 0 && $r['temperature'] === 'FRIO' && $r['priority'] === 'BAJA');
$rj = $S::calculate(base(array('capture' => 90, 'quality' => 'JUNK')));
check('9b quality JUNK → 0', $rj['total'] === 0);
$rqa = $S::calculate(base(array('capture' => 100, 'qualification' => 100, 'score_eligible' => false)));
check('9c QA score ineligible → 0', $rqa['total'] === 0 && $rqa['components']['excluded'] === 'test');

// 10. Terminal → score 0.
$rc = $S::calculate(base(array('capture' => 90, 'status' => 'CONVERTED')));
$rl = $S::calculate(base(array('capture' => 90, 'status' => 'LOST')));
$rs = $S::calculate(base(array('capture' => 90, 'sales_stage' => 'LOST')));
check('10 terminal (status/ stage) → 0', $rc['total'] === 0 && $rl['total'] === 0 && $rs['total'] === 0);

// Extra invariants ----------------------------------------------------------

// E1. Safety override: recontact + deep engagement → CALIENTE even when avg < warm.
$ov = $S::calculate(base(array('capture' => 10, 'behavior_events' => array(
    ev('lead_recontacted', 0), ev('autoresponse_engaged', 0),
))));
check('E1 override → CALIENTE', $ov['temperature'] === 'CALIENTE' && $ov['components']['temperature_override'] === true);

// E2. CALIENTE + sin gestión + recontacto → CRITICA.
check('E2 priority CRITICA', $ov['priority'] === 'CRITICA');

// E3. No components present → 0.
$none = $S::calculate(base());
check('E3 nothing present → 0 / FRIO / BAJA', $none['total'] === 0 && $none['priority'] === 'BAJA');

// E4. open_signal cap respected (3 opens → capped 6, not 9).
$capped = $S::calculate(base(array('behavior_events' => array(
    ev('autoresponse_email_open_signal', 0),
    ev('autoresponse_email_open_signal', 0),
    ev('autoresponse_email_open_signal', 0),
))));
check('E4 open_signal cap → 6', $capped['total'] === 6);

echo "\n" . ($passed) . " passed, " . $failed . " failed\n";
exit($failed === 0 ? 0 : 1);
