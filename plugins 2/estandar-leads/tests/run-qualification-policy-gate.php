<?php
/**
 * COMMERCIAL_QUALIFICATION_V1 — notifier routing policy gate suite.
 * Run: php tests/run-qualification-policy-gate.php
 *
 * Verifies the notifier's policy gate: route → proceeds silently;
 * review → stops with qualification_review event; skip → stops with
 * routing_skipped_by_policy event carrying full policy diagnostics.
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
function get_option($key, $default = false) { return $default; }
function set_transient(...$a) {}
function get_transient($k) { return false; }
function delete_transient($k) {}

class Estandar_Leads_Repository {
    public static $events = array();
    public static function insert_event($lead_id, $event_type, $args = array()) {
        self::$events[] = array('lead_id' => $lead_id, 'type' => $event_type, 'args' => $args);
        return count(self::$events);
    }
}

require dirname(__DIR__) . '/includes/class-estandar-commercial-qualification-rules.php';
require dirname(__DIR__) . '/includes/class-estandar-commercial-qualification-engine.php';
require dirname(__DIR__) . '/includes/class-estandar-leads-notifier.php';

$failed = 0;
function check($label, $ok) {
    global $failed;
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . "\n";
    $failed += $ok ? 0 : 1;
}

$gate = new ReflectionMethod('Estandar_Leads_Notifier', 'routing_policy_gate');

// ── route → proceed, zero events ─────────────────────────────────────────────
Estandar_Leads_Repository::$events = array();
$decision = Estandar_Commercial_Qualification_Engine::default_decision();
$proceed = $gate->invoke(null, 7, 11, $decision);
check('route: gate allows routing', $proceed === true);
check('route: no policy events emitted', Estandar_Leads_Repository::$events === array());

// ── skip → stop + routing_skipped_by_policy with diagnostics ─────────────────
Estandar_Leads_Repository::$events = array();
$decision = Estandar_Commercial_Qualification_Engine::evaluate(
    array('message' => 'ofrezco servicios'),
    array(Estandar_Commercial_Qualification_Rules::normalize_rule(array(
        'id' => 'dq-1',
        'conditions' => array(array('field' => 'message', 'op' => 'contains', 'value' => 'ofrezco')),
        'action' => array('decision' => 'disqualified', 'invalid_reason' => 'provider_offer'),
    ), 'project'))
);
$proceed = $gate->invoke(null, 7, 11, $decision);
$ev = Estandar_Leads_Repository::$events[0] ?? array();
check('skip: gate stops routing', $proceed === false);
check('skip: routing_skipped_by_policy event', ($ev['type'] ?? '') === 'routing_skipped_by_policy');
check('skip: event bound to submission', ($ev['args']['submission_id'] ?? 0) === 11);
check('skip: metadata carries matched rules', ($ev['args']['metadata']['matched_rules'] ?? array()) === array('dq-1'));
check('skip: metadata carries rule sources', ($ev['args']['metadata']['rule_sources']['dq-1'] ?? '') === 'project');
check('skip: metadata carries invalid_reason', ($ev['args']['metadata']['invalid_reason'] ?? '') === 'provider_offer');
check('skip: single event (no duplicates)', count(Estandar_Leads_Repository::$events) === 1);

// ── review → stop + qualification_review event ───────────────────────────────
Estandar_Leads_Repository::$events = array();
$decision = Estandar_Commercial_Qualification_Engine::evaluate(
    array('message' => ''),
    array(Estandar_Commercial_Qualification_Rules::normalize_rule(array(
        'id' => 'rv-1',
        'conditions' => array(array('field' => 'message', 'op' => 'empty')),
        'action' => array('decision' => 'review', 'reason' => 'needs_human_look'),
    ), 'global'))
);
$proceed = $gate->invoke(null, 7, 11, $decision);
$ev = Estandar_Leads_Repository::$events[0] ?? array();
check('review: gate stops routing', $proceed === false);
check('review: qualification_review event', ($ev['type'] ?? '') === 'qualification_review');
check('review: reason preserved', ($ev['args']['reason'] ?? '') === 'needs_human_look');

echo "\n" . ($failed === 0 ? "ALL PASS\n" : $failed . " FAILED\n");
exit($failed === 0 ? 0 : 1);
