<?php
/**
 * COMMERCIAL_QUALIFICATION_V1 — standalone suite.
 * Run: php tests/run-qualification-engine.php
 *
 * Covers: qualified / review / disqualified decisions, routing / notification /
 * autoresponse policies, phone-only chatbot lead, email optionality, rule
 * provenance, priority precedence, determinism, backward-compatible default.
 */

define('ABSPATH', __DIR__ . '/');

function absint($v) { return abs((int) $v); }
function sanitize_text_field($v) { return is_scalar($v) ? trim((string) $v) : ''; }
function sanitize_key($v) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $v)); }
function remove_accents($v) { return strtr((string) $v, array('á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ñ'=>'N')); }
function add_action(...$a) {}
function do_action(...$a) {}
function apply_filters($tag, $value, ...$a) { return $GLOBALS['__filters'][$tag] ?? $value; }
function get_option($key, $default = false) { return $GLOBALS['__options'][$key] ?? $default; }

class Estandar_Leads_Normalizer {
    public static function email($v) { return strtolower(trim((string) $v)); }
    public static function phone_storage($v) { return preg_replace('/\D+/', '', (string) $v); }
}
class Estandar_Leads_Projects {
    public static function resolve_slug($slug, $fallback = '') {
        $slug = strtolower(trim((string) $slug));
        return $slug !== '' ? $slug : strtolower(trim((string) $fallback));
    }
    public static function registry() { return array(); }
}

require dirname(__DIR__) . '/includes/class-estandar-leads-qa-policy.php';
require dirname(__DIR__) . '/includes/class-estandar-commercial-qualification-rules.php';
require dirname(__DIR__) . '/includes/class-estandar-commercial-qualification-engine.php';

$failed = 0;
function check($label, $ok) {
    global $failed;
    echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . "\n";
    $failed += $ok ? 0 : 1;
}

function payload(array $overrides = []): array {
    return array_replace_recursive(array(
        'schema_version' => '1.0',
        'source_plugin' => 'estandar-forms',
        'contact' => array('name' => 'Ana Prueba', 'email' => 'ana@example.test', 'phone' => '+56 9 1234 5678'),
        'content' => array('message' => 'Quiero cotizar', 'source_form_id' => 'f1'),
        'context' => array('project_slug' => 'demo-project', 'project_area' => 'Demo', 'intent' => 'lead_capture'),
        'attribution' => array('utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => 'c-1'),
        'scoring' => array('lead_score' => 40, 'lead_tier' => 'b', 'lead_route' => ''),
        'chatbot' => array(),
        'meta' => array('sanitized_raw_fields' => array('service' => 'contabilidad mensual')),
    ), $overrides);
}

// ── 1. Backward compatibility: no rules configured → default decision ───────
$GLOBALS['__options'] = array();
$GLOBALS['__filters'] = array();
$d = Estandar_Commercial_Qualification_Engine::qualify(payload());
check('default: decision qualified',      $d['decision'] === 'qualified');
check('default: eligible',                $d['eligible'] === true);
check('default: routing route',           $d['routing_policy'] === 'route');
check('default: notification notify',     $d['notification_policy'] === 'notify');
check('default: autoresponse allow',      $d['autoresponse_policy'] === 'allow');
check('default: confidence 100',          $d['confidence'] === 100);
check('default: no matched rules',        $d['matched_rules'] === array());
check('default: review not required',     $d['review_required'] === false);
check('default: is_default_decision',     Estandar_Commercial_Qualification_Engine::is_default_decision($d));

// QA protection reuses the same canonical decision consumed by routing.
$GLOBALS['__options'][Estandar_Leads_QA_Policy::OPTION] = array(
    'enabled' => true,
    'internal_actors' => array('admin'),
    'explicit_flags' => array('meta.qa_test'),
    'tokens' => array('qa-only'),
);
$qa_actor = Estandar_Commercial_Qualification_Engine::qualify(payload(array('actor_type' => 'admin')));
check('QA actor: qualification test', $qa_actor['decision'] === 'test' && $qa_actor['qualification'] === 'test' && $qa_actor['score_eligible'] === false);
check('QA actor: no routing/notification/ads/SLA',
    $qa_actor['routing_policy'] === 'skip' && $qa_actor['notification_policy'] === 'skip'
    && $qa_actor['ads_feedback_policy'] === 'skip' && $qa_actor['sla_policy'] === 'skip'
    && $qa_actor['escalation_policy'] === 'skip');
$qa_flag = Estandar_Commercial_Qualification_Engine::qualify(payload(array('meta' => array('qa_test' => true))));
check('QA explicit flag', $qa_flag['decision'] === 'test');
$qa_pattern = Estandar_Commercial_Qualification_Engine::qualify(payload(array('content' => array('message' => 'QA-ONLY transversal'))));
check('QA configurable token', $qa_pattern['decision'] === 'test');
unset($GLOBALS['__options'][Estandar_Leads_QA_Policy::OPTION]);

// ── 2. Phone-only chatbot lead (email optional) stays qualified ─────────────
$d = Estandar_Commercial_Qualification_Engine::qualify(payload(array(
    'source_plugin' => 'estandar-chatbot',
    'contact' => array('name' => 'Solo Fono', 'email' => '', 'phone' => '+56911112222'),
    'chatbot' => array('chatbot_status' => 'complete', 'force_human' => false),
)));
check('phone-only chatbot: qualified',    $d['decision'] === 'qualified' && $d['routing_policy'] === 'route');

// input map exposes email optionality deterministically
$in = Estandar_Commercial_Qualification_Engine::normalize_input(payload(array(
    'contact' => array('email' => '', 'phone' => '+56 9 1111-2222'),
)));
check('input: has_email empty flag',      $in['has_email'] === '' && $in['has_phone'] === '1');
check('input: phone normalized',          $in['contact.phone_normalized'] === '56911112222');
check('input: project resolved',          $in['project'] === 'demo-project');
check('input: fields flattened',          $in['fields.service'] === 'contabilidad mensual');

// ── 3. Disqualification rule (project-provided config, generic engine) ──────
$GLOBALS['__options'][Estandar_Commercial_Qualification_Rules::OPTION] = array(
    'global' => array(
        array(
            'id' => 'no-message-review',
            'priority' => 20,
            'conditions' => array(array('field' => 'message', 'op' => 'empty')),
            'action' => array('decision' => 'review', 'reason' => 'empty_message_review'),
        ),
    ),
    'demo-project' => array(
        array(
            'id' => 'provider-offer-disqualify',
            'priority' => 10,
            'conditions' => array(array('field' => 'message', 'op' => 'contains', 'value' => 'ofrezco servicios')),
            'action' => array('decision' => 'disqualified', 'invalid_reason' => 'provider_offer', 'quality' => 'JUNK'),
        ),
    ),
);

$d = Estandar_Commercial_Qualification_Engine::qualify(payload(array(
    'content' => array('message' => 'Hola, OFREZCO Servicios de marketing'),
)));
check('disqualified: decision',           $d['decision'] === 'disqualified');
check('disqualified: not eligible',       $d['eligible'] === false);
check('disqualified: routing skip',       $d['routing_policy'] === 'skip');
check('disqualified: notification skip',  $d['notification_policy'] === 'skip');
check('disqualified: autoresponse skip',  $d['autoresponse_policy'] === 'skip');
check('disqualified: invalid_reason',     $d['invalid_reason'] === 'provider_offer');
check('disqualified: quality from rule',  $d['quality'] === 'JUNK');
check('disqualified: matched rule id',    $d['matched_rules'] === array('provider-offer-disqualify'));
check('disqualified: provenance project', ($d['rule_sources']['provider-offer-disqualify'] ?? '') === 'project');

// ── 4. Review rule ───────────────────────────────────────────────────────────
$d = Estandar_Commercial_Qualification_Engine::qualify(payload(array(
    'content' => array('message' => ''),
)));
check('review: decision',                 $d['decision'] === 'review');
check('review: eligible (not invalid)',   $d['eligible'] === true);
check('review: routing review',           $d['routing_policy'] === 'review');
check('review: notification skip',        $d['notification_policy'] === 'skip');
check('review: autoresponse allow',       $d['autoresponse_policy'] === 'allow');
check('review: review_required',          $d['review_required'] === true);
check('review: confidence 50',            $d['confidence'] === 50);
check('review: provenance global',        ($d['rule_sources']['no-message-review'] ?? '') === 'global');
check('review: policy reason',            $d['policy_reason'] === 'empty_message_review');

// ── 5. Priority precedence: lower number wins; first-writer-wins on actions ─
$rules = Estandar_Commercial_Qualification_Rules::sort_rules(array(
    Estandar_Commercial_Qualification_Rules::normalize_rule(array(
        'id' => 'b-late', 'priority' => 30,
        'conditions' => array(array('field' => 'intent', 'op' => 'equals', 'value' => 'lead_capture')),
        'action' => array('decision' => 'disqualified'),
    ), 'global'),
    Estandar_Commercial_Qualification_Rules::normalize_rule(array(
        'id' => 'a-early', 'priority' => 5,
        'conditions' => array(array('field' => 'intent', 'op' => 'equals', 'value' => 'lead_capture')),
        'action' => array('decision' => 'qualified', 'qualification' => 'high_intent'),
    ), 'project'),
));
$in = Estandar_Commercial_Qualification_Engine::normalize_input(payload());
$d = Estandar_Commercial_Qualification_Engine::evaluate($in, $rules);
check('priority: higher precedence wins', $d['decision'] === 'qualified' && $d['qualification'] === 'high_intent');
check('priority: both rules matched',     $d['matched_rules'] === array('a-early', 'b-late'));

// stop halts evaluation
$rules[0]['action']['stop'] = true;
$d = Estandar_Commercial_Qualification_Engine::evaluate($in, $rules);
check('stop: halts at first rule',        $d['matched_rules'] === array('a-early'));

// ── 6. Notification-only skip (routing allowed, no email) ────────────────────
$d = Estandar_Commercial_Qualification_Engine::evaluate($in, array(
    Estandar_Commercial_Qualification_Rules::normalize_rule(array(
        'id' => 'quiet-hours',
        'conditions' => array(array('field' => 'source_plugin', 'op' => 'equals', 'value' => 'estandar-forms')),
        'action' => array('notification_policy' => 'skip', 'reason' => 'notify_muted'),
    ), 'manual'),
));
check('notify-skip: still routes',        $d['routing_policy'] === 'route');
check('notify-skip: notification skip',   $d['notification_policy'] === 'skip');
check('notify-skip: still qualified',     $d['decision'] === 'qualified');
check('notify-skip: manual provenance',   ($d['rule_sources']['quiet-hours'] ?? '') === 'manual');
check('notify-skip: non-default',         !Estandar_Commercial_Qualification_Engine::is_default_decision($d));

// ── 7. System rules via filter ───────────────────────────────────────────────
$GLOBALS['__options'] = array();
$GLOBALS['__filters']['estandar_commercial_qualification_rules'] = array(
    array(
        'id' => 'sys-honeypot',
        'conditions' => array(array('field' => 'spam.honeypot_filled', 'op' => 'equals', 'value' => '1')),
        'action' => array('decision' => 'disqualified', 'invalid_reason' => 'honeypot'),
    ),
);
$d = Estandar_Commercial_Qualification_Engine::qualify(payload(array('spam' => array('honeypot_filled' => true))));
check('system rule: fires',               $d['decision'] === 'disqualified' && $d['invalid_reason'] === 'honeypot');
check('system rule: provenance system',   ($d['rule_sources']['sys-honeypot'] ?? '') === 'system');
$d = Estandar_Commercial_Qualification_Engine::qualify(payload(array('spam' => array('honeypot_filled' => false))));
check('system rule: no false positive',   $d['decision'] === 'qualified');
$GLOBALS['__filters'] = array();

// ── 8. Condition operators ───────────────────────────────────────────────────
$C = 'Estandar_Commercial_Qualification_Rules';
$map = array('a' => 'Café Con Leche', 'n' => '42', 'e' => '');
check('op equals (accents/case)',   $C::condition_matches(array('field' => 'a', 'op' => 'equals', 'value' => 'cafe con leche'), $map));
check('op contains',                $C::condition_matches(array('field' => 'a', 'op' => 'contains', 'value' => 'con'), $map));
check('op regex',                   $C::condition_matches(array('field' => 'n', 'op' => 'regex', 'value' => '/^\d+$/'), $map));
check('op regex invalid = no match', !$C::condition_matches(array('field' => 'n', 'op' => 'regex', 'value' => '(unclosed'), $map));
check('op in (csv)',                $C::condition_matches(array('field' => 'n', 'op' => 'in', 'value' => '41,42,43'), $map));
check('op gt',                      $C::condition_matches(array('field' => 'n', 'op' => 'gt', 'value' => 40), $map));
check('op lt fails',                !$C::condition_matches(array('field' => 'n', 'op' => 'lt', 'value' => 40), $map));
check('op field_missing',           $C::condition_matches(array('field' => 'zz', 'op' => 'field_missing'), $map));
check('op empty on empty string',   $C::condition_matches(array('field' => 'e', 'op' => 'empty'), $map));
check('op field_exists',            $C::condition_matches(array('field' => 'a', 'op' => 'field_exists'), $map));
check('unknown op never matches',   !$C::condition_matches(array('field' => 'a', 'op' => 'wat'), $map));
check('rule without conditions never fires', !$C::matches(array('match' => 'all', 'conditions' => array()), $map));

// match any
check('match any',                  $C::matches(array('match' => 'any', 'conditions' => array(
    array('field' => 'a', 'op' => 'equals', 'value' => 'nope'),
    array('field' => 'n', 'op' => 'equals', 'value' => '42'),
)), $map));

// ── 9. Determinism + immutability of emitted decision ───────────────────────
$in = Estandar_Commercial_Qualification_Engine::normalize_input(payload());
$r = array(Estandar_Commercial_Qualification_Rules::normalize_rule(array(
    'id' => 'det', 'conditions' => array(array('field' => 'project', 'op' => 'equals', 'value' => 'demo-project')),
    'action' => array('decision' => 'review'),
), 'global'));
$d1 = Estandar_Commercial_Qualification_Engine::evaluate($in, $r);
$d2 = Estandar_Commercial_Qualification_Engine::evaluate($in, $r);
check('determinism: identical output',    $d1 === $d2);
$d1['decision'] = 'mutated';
$d3 = Estandar_Commercial_Qualification_Engine::evaluate($in, $r);
check('immutability: fresh copy per call', $d3['decision'] === 'review');

echo "\n" . ($failed === 0 ? "ALL PASS\n" : $failed . " FAILED\n");
exit($failed === 0 ? 0 : 1);
