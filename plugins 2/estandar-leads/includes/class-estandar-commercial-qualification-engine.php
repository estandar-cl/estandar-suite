<?php
/**
 * COMMERCIAL_QUALIFICATION_V1 — Canonical commercial qualification engine.
 *
 * Runs AFTER capture + attribution and BEFORE routing:
 *   estandar_lead_ingested @ priority 15
 *   (meta-adapter=10 · THIS ENGINE=15 · autoresponse/scoring/webhook=20 · notifier=25)
 *
 * Responsibilities:
 *   - evaluate commercial eligibility
 *   - classify commercial intent (qualification label)
 *   - determine routing / notification / autoresponse policy
 *   - expose deterministic diagnostics (matched_rules, rule_sources, reason)
 *
 * The engine knows NOTHING about any specific business. Projects contribute
 * rules (Estandar_Commercial_Qualification_Rules); the engine contributes
 * behavior. With no rules configured the decision is the DEFAULT decision
 * (qualified / route / notify / allow) and the pipeline behaves exactly as
 * before this engine existed — backward compatibility by construction.
 *
 * The decision is a plain array snapshot: evaluate() returns a fresh copy on
 * every call and the engine never mutates a decision after emitting it.
 *
 * Observability (no unnecessary telemetry): a `qualification_completed`
 * event is persisted ONLY when at least one rule matched or the decision
 * differs from the default. Absence of the event = default policy.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Estandar_Commercial_Qualification_Engine
{
    const ENGINE_VERSION = 'COMMERCIAL_QUALIFICATION_V1';

    const DECISION_QUALIFIED    = 'qualified';
    const DECISION_REVIEW       = 'review';
    const DECISION_DISQUALIFIED = 'disqualified';
    const DECISION_TEST         = 'test';

    const ROUTING_ROUTE  = 'route';
    const ROUTING_REVIEW = 'review';
    const ROUTING_SKIP   = 'skip';

    const NOTIFY   = 'notify';
    const SKIP     = 'skip';
    const ALLOW    = 'allow';

    const EVENT_COMPLETED       = 'qualification_completed';
    const EVENT_REVIEW          = 'qualification_review';
    const EVENT_MANUAL_OVERRIDE = 'qualification_manual_override';

    const SOURCE_DEFAULT = 'default';
    const SOURCE_SYSTEM  = 'system';
    const SOURCE_MANUAL  = 'manual';

    /** @var array<string,array> per-request decision cache keyed by "lead:submission". */
    private static $cache = array();

    public static function init(): void
    {
        add_action('estandar_lead_ingested', array(__CLASS__, 'on_lead_ingested'), 15, 4);
    }

    /**
     * Hook entrypoint. Best-effort: qualification must never block capture.
     */
    public static function on_lead_ingested($lead_id, $submission_id, $is_new, $payload): void
    {
        try {
            $payload = is_array($payload) ? $payload : array();
            $decision = self::qualify($payload);
            self::$cache[self::cache_key($lead_id, $submission_id)] = $decision;
            self::persist_decision((int) $lead_id, (int) $submission_id, $decision);
        } catch (Throwable $e) {
            error_log('[estandar-leads][qualification] ' . $e->getMessage());
        }
    }

    /**
     * Evaluate the canonical decision for a capture payload.
     * Deterministic: same payload + same rule configuration → same decision.
     */
    public static function qualify(array $payload): array
    {
        if (class_exists('Estandar_Leads_QA_Policy')) {
            $qa = Estandar_Leads_QA_Policy::from_payload($payload);
            if (!empty($qa['is_test_lead'])) {
                return self::test_decision($qa);
            }
        }
        $input = self::normalize_input($payload);
        $rules = class_exists('Estandar_Commercial_Qualification_Rules')
            ? Estandar_Commercial_Qualification_Rules::load((string) $input['project'])
            : array();
        return self::evaluate($input, $rules);
    }

    /**
     * Pure evaluation (no WP/DB) — unit-testable standalone.
     *
     * @param array $input normalized input map (see normalize_input()).
     * @param array $rules normalized rules (see Estandar_Commercial_Qualification_Rules).
     */
    public static function evaluate(array $input, array $rules): array
    {
        $matched = array();
        $sources = array();
        $action = array();

        foreach ($rules as $rule) {
            if (!Estandar_Commercial_Qualification_Rules::matches($rule, $input)) {
                continue;
            }
            $matched[] = (string) $rule['id'];
            $sources[(string) $rule['id']] = (string) $rule['source'];

            // First-writer-wins: a higher-precedence rule's value is final.
            foreach ((array) $rule['action'] as $key => $value) {
                if ($key === 'stop') {
                    continue;
                }
                if (!array_key_exists($key, $action)) {
                    $action[$key] = $value;
                }
            }
            if (!empty($rule['action']['stop'])) {
                break;
            }
        }

        return self::decision_from_action($action, $matched, $sources);
    }

    /** The decision produced when no rules match — current platform behavior. */
    public static function default_decision(): array
    {
        return self::decision_from_action(array(), array(), array());
    }

    private static function test_decision(array $qa): array
    {
        return array(
            'engine_version' => self::ENGINE_VERSION,
            'decision_source' => self::SOURCE_SYSTEM,
            'eligible' => false,
            'score_eligible' => false,
            'decision' => self::DECISION_TEST,
            'qualification' => 'test',
            'commercial_fit' => '',
            'routing_policy' => self::ROUTING_SKIP,
            'notification_policy' => self::SKIP,
            'autoresponse_policy' => ($qa['autoresponse'] ?? 'skip') === self::ALLOW ? self::ALLOW : self::SKIP,
            'ads_feedback_policy' => self::SKIP,
            'sales_stage' => '',
            'quality' => '',
            'invalid_reason' => '',
            'matched_rules' => array('qa_protection'),
            'rule_sources' => array('qa_protection' => self::SOURCE_SYSTEM),
            'confidence' => 100,
            'review_required' => false,
            'policy_reason' => (string) ($qa['test_reason'] ?? 'qa_protection'),
            'sla_policy' => self::SKIP,
            'escalation_policy' => self::SKIP,
        );
    }

    /**
     * Derive the immutable decision structure from merged rule actions.
     * Every value is deterministic; policies default from the decision class.
     */
    private static function decision_from_action(array $action, array $matched, array $sources): array
    {
        $decision = strtolower(trim((string) ($action['decision'] ?? self::DECISION_QUALIFIED)));
        if (!in_array($decision, array(self::DECISION_QUALIFIED, self::DECISION_REVIEW, self::DECISION_DISQUALIFIED), true)) {
            $decision = self::DECISION_QUALIFIED;
        }

        switch ($decision) {
            case self::DECISION_DISQUALIFIED:
                $routing_default = self::ROUTING_SKIP;
                $autoresponse_default = self::SKIP;
                // OCI whitelist canon: invalid/spam never feed ads platforms.
                $ads_feedback_default = self::SKIP;
                $confidence_default = 100;
                break;
            case self::DECISION_REVIEW:
                $routing_default = self::ROUTING_REVIEW;
                $autoresponse_default = self::ALLOW;
                // Undetermined commercial intent is not exportable signal.
                $ads_feedback_default = self::SKIP;
                $confidence_default = 50;
                break;
            default:
                $routing_default = self::ROUTING_ROUTE;
                $autoresponse_default = self::ALLOW;
                $ads_feedback_default = self::ALLOW;
                $confidence_default = 100;
        }

        $routing = strtolower(trim((string) ($action['routing_policy'] ?? $routing_default)));
        if (!in_array($routing, array(self::ROUTING_ROUTE, self::ROUTING_REVIEW, self::ROUTING_SKIP), true)) {
            $routing = $routing_default;
        }

        $notification_default = $routing === self::ROUTING_ROUTE ? self::NOTIFY : self::SKIP;
        $notification = strtolower(trim((string) ($action['notification_policy'] ?? $notification_default)));
        if (!in_array($notification, array(self::NOTIFY, self::SKIP), true)) {
            $notification = $notification_default;
        }
        // Routing skip/review means no operator is engaged — notification cannot fire.
        if ($routing !== self::ROUTING_ROUTE) {
            $notification = self::SKIP;
        }

        $autoresponse = strtolower(trim((string) ($action['autoresponse_policy'] ?? $autoresponse_default)));
        if (!in_array($autoresponse, array(self::ALLOW, self::SKIP), true)) {
            $autoresponse = $autoresponse_default;
        }

        $ads_feedback = strtolower(trim((string) ($action['ads_feedback_policy'] ?? $ads_feedback_default)));
        if (!in_array($ads_feedback, array(self::ALLOW, self::SKIP), true)) {
            $ads_feedback = $ads_feedback_default;
        }

        $confidence = isset($action['confidence']) && is_numeric($action['confidence'])
            ? max(0, min(100, (int) $action['confidence']))
            : $confidence_default;

        $reason = trim((string) ($action['reason'] ?? ''));
        if ($reason === '' && !empty($matched)) {
            $reason = 'matched_rules:' . implode(',', $matched);
        }
        if ($reason === '') {
            $reason = 'default_policy';
        }

        return array(
            'engine_version'      => self::ENGINE_VERSION,
            'decision_source'     => empty($matched) ? self::SOURCE_DEFAULT : self::SOURCE_SYSTEM,
            'eligible'            => $decision !== self::DECISION_DISQUALIFIED,
            'score_eligible'      => true,
            'decision'            => $decision,
            'qualification'       => trim((string) ($action['qualification'] ?? ($decision === self::DECISION_QUALIFIED ? 'standard' : $decision))),
            'commercial_fit'      => trim((string) ($action['commercial_fit'] ?? '')),
            'routing_policy'      => $routing,
            'notification_policy' => $notification,
            'autoresponse_policy' => $autoresponse,
            'ads_feedback_policy' => $ads_feedback,
            'sales_stage'         => trim((string) ($action['sales_stage'] ?? '')),
            'quality'             => trim((string) ($action['quality'] ?? '')),
            'invalid_reason'      => $decision === self::DECISION_DISQUALIFIED
                ? trim((string) ($action['invalid_reason'] ?? 'disqualified_by_rule'))
                : '',
            'matched_rules'       => array_values($matched),
            'rule_sources'        => $sources,
            'confidence'          => $confidence,
            'review_required'     => array_key_exists('review_required', $action)
                ? (bool) $action['review_required']
                : $decision === self::DECISION_REVIEW,
            'policy_reason'       => $reason,
        );
    }

    /** True when the decision is exactly the default — nothing worth persisting. */
    public static function is_default_decision(array $decision): bool
    {
        $default = self::default_decision();
        return empty($decision['matched_rules'])
            && ($decision['decision'] ?? '') === $default['decision']
            && ($decision['routing_policy'] ?? '') === $default['routing_policy']
            && ($decision['notification_policy'] ?? '') === $default['notification_policy']
            && ($decision['autoresponse_policy'] ?? '') === $default['autoresponse_policy']
            && ($decision['ads_feedback_policy'] ?? $default['ads_feedback_policy']) === $default['ads_feedback_policy'];
    }

    /**
     * Normalize the capture payload into a flat, deterministic input map.
     * Accepts everything the platform already produces: contact, content,
     * context, attribution (post-merge), scoring, chatbot, form fields.
     */
    public static function normalize_input(array $payload): array
    {
        $map = array();

        $map['source_plugin'] = strtolower(trim((string) ($payload['source_plugin'] ?? '')));
        $map['schema_version'] = trim((string) ($payload['schema_version'] ?? ''));

        foreach (array('contact', 'content', 'context', 'attribution', 'scoring', 'chatbot', 'spam', 'request') as $group) {
            $data = is_array($payload[$group] ?? null) ? $payload[$group] : array();
            foreach ($data as $key => $value) {
                if (is_scalar($value) || $value === null) {
                    $map[$group . '.' . $key] = is_bool($value) ? ($value ? '1' : '') : trim((string) $value);
                }
            }
        }

        $fields = is_array($payload['meta']['sanitized_raw_fields'] ?? null)
            ? $payload['meta']['sanitized_raw_fields']
            : array();
        foreach ($fields as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $map['fields.' . (string) $key] = is_bool($value) ? ($value ? '1' : '') : trim((string) $value);
            } elseif (is_array($value)) {
                $map['fields.' . (string) $key] = implode(', ', array_map('strval', array_filter($value, 'is_scalar')));
            }
        }

        // Convenience aliases used by rule authors.
        $project_area = (string) ($map['context.project_area'] ?? '');
        $project_slug = (string) ($map['context.project_slug'] ?? '');
        $map['project'] = class_exists('Estandar_Leads_Projects')
            ? Estandar_Leads_Projects::resolve_slug($project_slug, $project_area)
            : strtolower(trim($project_slug));
        $map['intent'] = (string) ($map['context.intent'] ?? '');
        $map['service'] = (string) ($map['fields.service'] ?? ($map['fields.service_category'] ?? ''));
        $map['message'] = (string) ($map['content.message'] ?? '');

        if (class_exists('Estandar_Leads_Normalizer')) {
            $map['contact.email'] = Estandar_Leads_Normalizer::email($map['contact.email'] ?? '');
            $map['contact.phone_normalized'] = Estandar_Leads_Normalizer::phone_storage($map['contact.phone'] ?? '');
        } else {
            $map['contact.phone_normalized'] = preg_replace('/\D+/', '', (string) ($map['contact.phone'] ?? ''));
        }
        $map['has_email'] = trim((string) ($map['contact.email'] ?? '')) !== '' ? '1' : '';
        $map['has_phone'] = trim((string) ($map['contact.phone_normalized'] ?? '')) !== '' ? '1' : '';

        return $map;
    }

    /**
     * Canonical read point for consumers (notifier, autoresponse, diagnostics).
     *
     * Resolution order:
     *   1. per-request cache (same request as ingestion)
     *   2. persisted qualification_completed event (deferred/cron re-entry)
     *   3. recompute from payload when provided (deterministic)
     *   4. default decision
     */
    public static function decision_for($lead_id, $submission_id, $payload = null): array
    {
        $key = self::cache_key($lead_id, $submission_id);
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $persisted = self::persisted_decision((int) $lead_id, (int) $submission_id);
        if ($persisted !== null) {
            self::$cache[$key] = $persisted;
            return $persisted;
        }

        if (is_array($payload) && !empty($payload)) {
            $decision = self::qualify($payload);
            self::$cache[$key] = $decision;
            return $decision;
        }

        return self::default_decision();
    }

    /**
     * MANUAL_OVERRIDE — operator decisions are authoritative.
     *
     * Precedence: manual > system > default. The override is persisted as a
     * `qualification_manual_override` event (always, even when it equals the
     * default — manual decisions are always auditable) and becomes the
     * effective decision returned by decision_for() from this moment on:
     * consumers never see the stale automatic decision again.
     *
     * Merge semantics (deterministic):
     *  - override sets `decision`    → routing/notification/autoresponse
     *    re-derive from the manual decision class unless the override pins
     *    them explicitly (a manual "qualified" routes and notifies again).
     *  - override without `decision` → the current effective decision is
     *    kept and only the specified fields are replaced (a manual routing
     *    decision overrides automatic routing alone).
     *
     * Existing invariants still apply (routing != route forces notification
     * skip) because the result flows through decision_from_action().
     *
     * @param array $override subset of: decision, qualification,
     *        commercial_fit, routing_policy, notification_policy,
     *        autoresponse_policy, ads_feedback_policy, sales_stage, quality,
     *        invalid_reason, confidence, review_required, reason
     *        (short aliases routing/notification/autoresponse/ads_feedback ok).
     * @return array the new effective decision.
     */
    public static function apply_manual_override($lead_id, $submission_id, array $override): array
    {
        $override = class_exists('Estandar_Commercial_Qualification_Rules')
            ? Estandar_Commercial_Qualification_Rules::normalize_action($override)
            : $override;
        $allowed = array(
            'decision', 'qualification', 'commercial_fit', 'routing_policy',
            'notification_policy', 'autoresponse_policy', 'ads_feedback_policy',
            'sales_stage', 'quality', 'invalid_reason',
            'confidence', 'review_required', 'reason',
        );
        $manual = array();
        foreach ($allowed as $key) {
            if (array_key_exists($key, $override)) {
                $manual[$key] = $override[$key];
            }
        }

        $previous = self::decision_for($lead_id, $submission_id);

        if (array_key_exists('decision', $manual)) {
            $action = $manual;
        } else {
            $action = array_merge(array(
                'decision'            => $previous['decision'],
                'qualification'       => $previous['qualification'],
                'commercial_fit'      => $previous['commercial_fit'] ?? '',
                'routing_policy'      => $previous['routing_policy'],
                'notification_policy' => $previous['notification_policy'],
                'autoresponse_policy' => $previous['autoresponse_policy'],
                'ads_feedback_policy' => $previous['ads_feedback_policy'] ?? null,
                'sales_stage'         => $previous['sales_stage'],
                'quality'             => $previous['quality'],
                'invalid_reason'      => $previous['invalid_reason'],
                'confidence'          => $previous['confidence'],
                'review_required'     => $previous['review_required'],
            ), $manual);
        }
        if (trim((string) ($action['reason'] ?? '')) === '') {
            $action['reason'] = 'manual_override';
        }

        $decision = self::decision_from_action(
            $action,
            (array) ($previous['matched_rules'] ?? array()),
            (array) ($previous['rule_sources'] ?? array())
        );
        $decision['decision_source'] = self::SOURCE_MANUAL;
        $decision['manual_override'] = array('fields' => array_keys($manual));

        self::$cache[self::cache_key($lead_id, $submission_id)] = $decision;

        if ((int) $lead_id > 0 && class_exists('Estandar_Leads_Repository')) {
            Estandar_Leads_Repository::insert_event((int) $lead_id, self::EVENT_MANUAL_OVERRIDE, array(
                'submission_id' => (int) $submission_id,
                'to_status'     => (string) $decision['decision'],
                'reason'        => (string) $decision['policy_reason'],
                'metadata'      => array(
                    'qualification' => $decision,
                    'previous'      => $previous,
                    'overridden'    => array_keys($manual),
                ),
            ));
            self::telemetry('info', 'LEADS_QUALIFICATION_MANUAL_OVERRIDE', 'Manual qualification override recorded.', array(
                'lead_id'       => (int) $lead_id,
                'submission_id' => (int) $submission_id,
                'decision'      => (string) $decision['decision'],
                'overridden'    => array_keys($manual),
            ));
        }

        return $decision;
    }

    /** Persist the decision as an auditable event — only when non-default. */
    private static function persist_decision(int $lead_id, int $submission_id, array $decision): void
    {
        if ($lead_id <= 0 || !class_exists('Estandar_Leads_Repository')) {
            return;
        }
        if (self::is_default_decision($decision)) {
            return; // default policy → no event noise; absence = default.
        }

        Estandar_Leads_Repository::insert_event($lead_id, self::EVENT_COMPLETED, array(
            'submission_id' => $submission_id,
            'to_status'     => (string) $decision['decision'],
            'reason'        => (string) $decision['policy_reason'],
            'metadata'      => array(
                'qualification' => $decision,
                'is_test_lead' => ($decision['decision'] ?? '') === self::DECISION_TEST,
                'score_eligible' => !array_key_exists('score_eligible', $decision) || !empty($decision['score_eligible']),
            ),
        ));

        self::telemetry('info', 'LEADS_QUALIFICATION_COMPLETED', 'Commercial qualification decision recorded.', array(
            'lead_id'         => $lead_id,
            'submission_id'   => $submission_id,
            'decision'        => (string) $decision['decision'],
            'routing_policy'  => (string) $decision['routing_policy'],
            'matched_rules'   => $decision['matched_rules'],
        ));
    }

    /**
     * @return array|null persisted effective decision. A manual override is
     * authoritative over any automatic decision regardless of recency —
     * consumers never read a stale system decision after an override.
     */
    private static function persisted_decision(int $lead_id, int $submission_id)
    {
        if ($lead_id <= 0 || !isset($GLOBALS['wpdb'])) {
            return null;
        }
        global $wpdb;
        $metadata = $wpdb->get_var($wpdb->prepare(
            "SELECT metadata FROM {$wpdb->prefix}es_lead_events
             WHERE lead_id = %d AND submission_id = %d AND event_type IN (%s, %s)
             ORDER BY (event_type = %s) DESC, id DESC LIMIT 1",
            $lead_id,
            $submission_id,
            self::EVENT_COMPLETED,
            self::EVENT_MANUAL_OVERRIDE,
            self::EVENT_MANUAL_OVERRIDE
        ));
        if (!is_string($metadata) || $metadata === '') {
            return null;
        }
        $decoded = json_decode($metadata, true);
        $decision = is_array($decoded) ? ($decoded['qualification'] ?? null) : null;
        return is_array($decision) ? $decision : null;
    }

    private static function cache_key($lead_id, $submission_id): string
    {
        return absint($lead_id) . ':' . absint($submission_id);
    }

    /** Test-only: reset the per-request cache. */
    public static function reset_cache(): void
    {
        self::$cache = array();
    }

    private static function telemetry($severity, $code, $message, $context = array()): void
    {
        try {
            do_action('estandar_telemetry_event', array(
                'source_plugin' => 'estandar-leads',
                'type' => 'business_event',
                'severity' => $severity,
                'code' => $code,
                'message' => $message,
                'context' => is_array($context) ? $context : array(),
            ));
        } catch (Throwable $e) {
            return;
        }
    }
}
