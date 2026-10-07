<?php
/**
 * COMMERCIAL_QUALIFICATION_V1 — manual override precedence suite.
 * Run: php tests/run-manual-override.php
 *
 * Canonical precedence: MANUAL operator decision > SYSTEM decision > DEFAULT.
 * A persisted manual override is authoritative for its (lead, submission)
 * pair regardless of event chronology — consumers never read a stale
 * automatic decision after an override. Also verifies the Review lifecycle
 * (routing held, notification skipped, auditable event) and the AI Context
 * Commercial Policy rendering for Manual Override / Review / Disqualified /
 * Qualified (deterministic diagnostics, never rendered as FAIL).
 */

define('ABSPATH', __DIR__ . '/');

function absint($v) { return abs((int) $v); }
function sanitize_text_field($v) { return is_scalar($v) ? trim((string) $v) : ''; }
function sanitize_key($v) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $v)); }
function sanitize_email($v) { return trim((string) $v); }
function sanitize_textarea_field($v) { return is_scalar($v) ? trim((string) $v) : ''; }
function add_action(...$a) {}
function do_action(...$a) { $GLOBALS['__actions'][] = $a; }
function apply_filters($tag, $value, ...$a) { return $value; }
function get_option($key, $default = false) { return $GLOBALS['__options'][$key] ?? $default; }
function set_transient(...$a) {}
function get_transient($k) { return false; }
function delete_transient($k) {}
function wp_json_encode($v) { return json_encode($v); }

/**
 * Repository stub: records events AND mirrors them into the fake events
 * table so the engine's persisted read path is exercised end-to-end.
 */
class Estandar_Leads_Repository {
    public static $events = array();
    public static function insert_event($lead_id, $event_type, $args = array()) {
        $id = count($GLOBALS['db_events']) + 1;
        self::$events[] = array('lead_id' => $lead_id, 'type' => $event_type, 'args' => $args);
        $GLOBALS['db_events'][] = array(
            'id'            => $id,
            'lead_id'       => (int) $lead_id,
            'submission_id' => (int) ($args['submission_id'] ?? 0),
            'event_type'    => (string) $event_type,
            'metadata'      => json_encode($args['metadata'] ?? array()),
        );
        return $id;
    }
}

/** wpdb stub: persisted-decision lookup + Commercial Policy rows. */
class Override_Test_DB {
    public $prefix = 'wp_';
    public function prepare($q, ...$a) { return array('q' => $q, 'a' => $a); }
    public function get_var($q) {
        $sql = is_array($q) ? $q['q'] : $q;
        $a = is_array($q) ? $q['a'] : array();
        if (strpos($sql, 'COUNT(*)') !== false) { return 0; }
        if (strpos($sql, 'SELECT metadata') === false) { return null; }
        // Engine persisted_decision: prefer manual override, then latest id.
        list($lead_id, $submission_id, $type_a, $type_b, $preferred) = array_pad($a, 5, null);
        $best = null;
        foreach ($GLOBALS['db_events'] as $row) {
            if ($row['lead_id'] !== (int) $lead_id || $row['submission_id'] !== (int) $submission_id) { continue; }
            if (!in_array($row['event_type'], array($type_a, $type_b), true)) { continue; }
            if ($best === null) { $best = $row; continue; }
            $best_pref = $best['event_type'] === $preferred ? 1 : 0;
            $row_pref  = $row['event_type'] === $preferred ? 1 : 0;
            if ($row_pref > $best_pref || ($row_pref === $best_pref && $row['id'] > $best['id'])) { $best = $row; }
        }
        return $best ? $best['metadata'] : null;
    }
    public function get_results($q) { return $GLOBALS['policy_rows'] ?? array(); }
    public function get_row($q) { return null; }
    public function get_col($q) { return array(); }
    public function suppress_errors($v) { return false; }
}

$GLOBALS['db_events'] = array();
$GLOBALS['__options'] = array();
$GLOBALS['wpdb'] = new Override_Test_DB();

require dirname(__DIR__) . '/includes/class-estandar-commercial-qualification-rules.php';
require dirname(__DIR__) . '/includes/class-estandar-commercial-qualification-engine.php';
require dirname(__DIR__) . '/includes/class-estandar-leads-notifier.php';
require dirname(__DIR__) . '/includes/class-estandar-leads-ai-context.php';

$failed = 0;
function check($label, $ok) {
    global $failed;
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . "\n";
    $failed += $ok ? 0 : 1;
}
function last_event_of_type($type) {
    foreach (array_reverse(Estandar_Leads_Repository::$events) as $ev) {
        if ($ev['type'] === $type) { return $ev; }
    }
    return null;
}

// ── 1 · Backward compatibility — no rules, no override → default unchanged ──
$d = Estandar_Commercial_Qualification_Engine::decision_for(1, 11);
check('compat: default decision qualified', $d['decision'] === 'qualified');
check('compat: default routing route', $d['routing_policy'] === 'route');
check('compat: default notification notify', $d['notification_policy'] === 'notify');
check('compat: decision source is default', $d['decision_source'] === 'default');
check('compat: is_default_decision still true', Estandar_Commercial_Qualification_Engine::is_default_decision($d));

// ── 2 · Automatic DISQUALIFIED + manual QUALIFIED → effective QUALIFIED ─────
$GLOBALS['__options'][Estandar_Commercial_Qualification_Rules::OPTION] = array(
    'global' => array(array(
        'id' => 'dq-1',
        'conditions' => array(array('field' => 'message', 'op' => 'contains', 'value' => 'ofrezco')),
        'action' => array('decision' => 'disqualified', 'invalid_reason' => 'provider_offer'),
    )),
);
Estandar_Commercial_Qualification_Engine::on_lead_ingested(2, 21, true, array(
    'content' => array('message' => 'ofrezco servicios'),
));
$d = Estandar_Commercial_Qualification_Engine::decision_for(2, 21);
check('system: rule disqualifies', $d['decision'] === 'disqualified' && $d['decision_source'] === 'system');

Estandar_Commercial_Qualification_Engine::reset_cache();
$d = Estandar_Commercial_Qualification_Engine::decision_for(2, 21);
check('system: persisted decision survives cache reset', $d['decision'] === 'disqualified');

$d = Estandar_Commercial_Qualification_Engine::apply_manual_override(2, 21, array(
    'decision' => 'qualified',
    'reason'   => 'operator_validated',
));
check('override: effective qualified', $d['decision'] === 'qualified' && !empty($d['eligible']));
check('override: routing re-derived to route', $d['routing_policy'] === 'route');
check('override: notification re-derived to notify', $d['notification_policy'] === 'notify');
check('override: decision source manual', $d['decision_source'] === 'manual');
check('override: reason preserved', $d['policy_reason'] === 'operator_validated');
check('override: matched rules kept for audit', $d['matched_rules'] === array('dq-1'));

$ev = last_event_of_type('qualification_manual_override');
check('override: event always persisted', $ev !== null && ($ev['args']['submission_id'] ?? 0) === 21);
check('override: event carries previous system decision', ($ev['args']['metadata']['previous']['decision'] ?? '') === 'disqualified');
check('override: event lists overridden fields', ($ev['args']['metadata']['overridden'] ?? array()) === array('decision', 'reason'));

Estandar_Commercial_Qualification_Engine::reset_cache();
$d = Estandar_Commercial_Qualification_Engine::decision_for(2, 21);
check('override: persisted manual wins after cache reset', $d['decision'] === 'qualified' && $d['decision_source'] === 'manual');

// Stale guard: a LATER automatic event must never shadow the manual decision.
Estandar_Leads_Repository::insert_event(2, 'qualification_completed', array(
    'submission_id' => 21,
    'metadata'      => array('qualification' => array('decision' => 'disqualified', 'decision_source' => 'system')),
));
Estandar_Commercial_Qualification_Engine::reset_cache();
$d = Estandar_Commercial_Qualification_Engine::decision_for(2, 21);
check('override: manual authoritative over newer automatic event', $d['decision'] === 'qualified' && $d['decision_source'] === 'manual');

// ── 3 · Automatic QUALIFIED + manual DISQUALIFIED → effective DISQUALIFIED ──
$d = Estandar_Commercial_Qualification_Engine::apply_manual_override(3, 31, array(
    'decision'       => 'disqualified',
    'invalid_reason' => 'spam_confirmed',
));
check('override-dq: effective disqualified', $d['decision'] === 'disqualified' && empty($d['eligible']));
check('override-dq: routing skip', $d['routing_policy'] === 'skip');
check('override-dq: notification skip', $d['notification_policy'] === 'skip');
check('override-dq: invalid reason kept', $d['invalid_reason'] === 'spam_confirmed');
Estandar_Commercial_Qualification_Engine::reset_cache();
$d = Estandar_Commercial_Qualification_Engine::decision_for(3, 31);
check('override-dq: persisted', $d['decision'] === 'disqualified' && $d['decision_source'] === 'manual');

// ── 4 · Manual ROUTING-ONLY override keeps the system decision ──────────────
$d = Estandar_Commercial_Qualification_Engine::apply_manual_override(4, 41, array(
    'routing_policy' => 'skip',
));
check('routing-only: decision unchanged (qualified)', $d['decision'] === 'qualified');
check('routing-only: routing skipped manually', $d['routing_policy'] === 'skip');
check('routing-only: notification forced skip (invariant)', $d['notification_policy'] === 'skip');
check('routing-only: decision source manual', $d['decision_source'] === 'manual');

// ── 5 · Manual REVIEW → review lifecycle (held, not notified, auditable) ────
$d = Estandar_Commercial_Qualification_Engine::apply_manual_override(5, 51, array(
    'decision' => 'review',
    'reason'   => 'operator_wants_review',
));
check('review: decision review (neither qualified nor disqualified)', $d['decision'] === 'review' && !empty($d['eligible']));
check('review: routing held for review', $d['routing_policy'] === 'review');
check('review: notification skipped', $d['notification_policy'] === 'skip');
check('review: review_required flagged', !empty($d['review_required']));

$gate = new ReflectionMethod('Estandar_Leads_Notifier', 'routing_policy_gate');
$before = count(Estandar_Leads_Repository::$events);
$proceed = $gate->invoke(null, 5, 51, $d);
$ev = Estandar_Leads_Repository::$events[$before] ?? array();
check('review: gate stops routing', $proceed === false);
check('review: qualification_review event recorded', ($ev['type'] ?? '') === 'qualification_review');
check('review: review reason preserved', ($ev['args']['reason'] ?? '') === 'operator_wants_review');

// ── 6 · AI Context — Commercial Policy renders every state deterministically ─
$policy = new ReflectionMethod('Estandar_Leads_AI_Context', 'build_commercial_policy');
$row = static function ($type, array $meta, $reason = '') {
    return (object) array('event_type' => $type, 'reason' => $reason, 'metadata' => json_encode($meta), 'created_at' => '2026-07-11 00:00:00');
};

// 6a — QUALIFIED (default, no events).
$GLOBALS['policy_rows'] = array();
$md = $policy->invoke(null, 9);
// ADMISSION_POLICY_V1: sin decisión del engine, la admisión por política nunca
// se rotula "qualified" — QUALIFIED queda reservado al operador.
check('ctx-default: qualification pending, admission allowed', strpos($md, '**Qualification:** PENDING (no operator qualification recorded)') !== false
    && strpos($md, '**Admission Policy:** ALLOWED') !== false
    && strpos($md, '**Admission Reason:** no exclusion rule matched') !== false
    && strpos($md, 'DEFAULT (qualified') === false);
check('ctx-default: decision source DEFAULT', strpos($md, '**Decision Source:** DEFAULT (no rules matched)') !== false);
check('ctx-default: manual override NONE', strpos($md, '**Manual Override:** NONE') !== false);
check('ctx-default: default policies', strpos($md, 'ROUTE (default policy)') !== false && strpos($md, 'NOTIFY (default policy)') !== false);

// 6b — DISQUALIFIED by system + routing skipped by policy.
$dq = array('decision' => 'disqualified', 'qualification' => 'disqualified', 'eligible' => false,
    'matched_rules' => array('dq-1'), 'rule_sources' => array('dq-1' => 'global'),
    'confidence' => 100, 'review_required' => false, 'invalid_reason' => 'provider_offer', 'policy_reason' => 'matched_rules:dq-1');
$GLOBALS['policy_rows'] = array(
    $row('qualification_completed', array('qualification' => $dq)),
    $row('routing_skipped_by_policy', array(), 'matched_rules:dq-1'),
);
$md = $policy->invoke(null, 9);
check('ctx-dq: decision DISQUALIFIED', strpos($md, '**Decision:** DISQUALIFIED') !== false);
check('ctx-dq: decision source SYSTEM', strpos($md, '**Decision Source:** SYSTEM (qualification engine)') !== false);
check('ctx-dq: routing SKIPPED_BY_POLICY (intentional, not FAIL)', strpos($md, 'SKIPPED_BY_POLICY') !== false && strpos($md, 'FAIL') === false);

// 6c — REVIEW: explicit policy state, never rendered as FAIL.
$rv = array('decision' => 'review', 'qualification' => 'review', 'eligible' => true,
    'matched_rules' => array('rv-1'), 'rule_sources' => array('rv-1' => 'global'),
    'confidence' => 50, 'review_required' => true, 'invalid_reason' => '', 'policy_reason' => 'needs_human_look');
$GLOBALS['policy_rows'] = array(
    $row('qualification_completed', array('qualification' => $rv)),
    $row('qualification_review', array(), 'needs_human_look'),
);
$md = $policy->invoke(null, 9);
check('ctx-review: decision REVIEW', strpos($md, '**Decision:** REVIEW') !== false);
check('ctx-review: routing held by policy', strpos($md, 'REVIEW (held by policy)') !== false);
check('ctx-review: notification pending review', strpos($md, 'SKIPPED (pending commercial review)') !== false);
check('ctx-review: never rendered as FAIL', strpos($md, 'FAIL') === false);

// 6d — MANUAL OVERRIDE authoritative over the earlier system decision.
$mq = array('decision' => 'qualified', 'qualification' => 'standard', 'eligible' => true,
    'decision_source' => 'manual', 'routing_policy' => 'route', 'notification_policy' => 'notify',
    'autoresponse_policy' => 'allow', 'matched_rules' => array('dq-1'), 'rule_sources' => array('dq-1' => 'global'),
    'confidence' => 100, 'review_required' => false, 'invalid_reason' => '', 'policy_reason' => 'operator_validated');
$GLOBALS['policy_rows'] = array(
    $row('qualification_completed', array('qualification' => $dq)),
    $row('routing_skipped_by_policy', array(), 'matched_rules:dq-1'),
    $row('qualification_manual_override', array('qualification' => $mq, 'overridden' => array('decision', 'reason'))),
);
$md = $policy->invoke(null, 9);
check('ctx-manual: decision source MANUAL_OVERRIDE', strpos($md, '**Decision Source:** MANUAL_OVERRIDE (operator decision — authoritative)') !== false);
check('ctx-manual: overridden fields listed', strpos($md, '**Manual Override:** fields: decision, reason') !== false);
check('ctx-manual: effective decision QUALIFIED (not stale disqualified)', strpos($md, '**Decision:** QUALIFIED') !== false);
check('ctx-manual: routing reflects override', strpos($md, '**Routing Decision:** ROUTE (manual override)') !== false);
check('ctx-manual: notification reflects override', strpos($md, '**Notification Decision:** NOTIFY (manual override)') !== false);
check('ctx-manual: policy reason from operator', strpos($md, '**Policy Reason:** operator_validated') !== false);
check('ctx-manual: no stale SKIPPED_BY_POLICY decision line', strpos($md, '**Routing Decision:** SKIPPED_BY_POLICY') === false);

echo "\n" . ($failed === 0 ? "ALL PASS\n" : $failed . " FAILED\n");
exit($failed === 0 ? 0 : 1);
