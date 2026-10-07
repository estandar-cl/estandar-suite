<?php

if (!defined('ABSPATH')) {
    exit;
}

/** Canonical, configurable QA/test protection policy. */
class Estandar_Leads_QA_Policy
{
    const OPTION = 'estandar_leads_qa_policy_v1';

    public static function evaluate(array $payload, $config = null): array
    {
        $project = strtolower(trim((string) ($payload['context']['project_slug'] ?? '')));
        if ($config === null) {
            $config = function_exists('get_option') ? get_option(self::OPTION, array()) : array();
        }
        $config = is_array($config) ? $config : array();
        if (isset($config['projects'][$project]) && is_array($config['projects'][$project])) {
            $config = array_replace_recursive($config, $config['projects'][$project]);
        }
        if (function_exists('apply_filters')) {
            $config = apply_filters('estandar_leads_qa_policy', $config, $project, $payload);
        }
        $config = is_array($config) ? $config : array();
        $enabled = array_key_exists('enabled', $config) ? !empty($config['enabled']) : !empty($config);
        $reasons = array();

        if ($enabled) {
            if (!empty($config['detect_authenticated_users']) && function_exists('is_user_logged_in') && is_user_logged_in()) {
                $reasons[] = 'authenticated_user';
            }

            $actor = self::actor($payload);
            $actors = array_map(array(__CLASS__, 'key'), (array) ($config['internal_actors'] ?? array()));
            if ($actor !== '' && in_array($actor, $actors, true)) {
                $reasons[] = 'internal_actor:' . $actor;
            }

            foreach ((array) ($config['explicit_flags'] ?? array()) as $path) {
                if (self::truthy(self::value($payload, (string) $path))) {
                    $reasons[] = 'explicit_flag:' . self::key((string) $path);
                    break;
                }
            }

            $email = strtolower(trim((string) ($payload['contact']['email'] ?? '')));
            $domain = strpos($email, '@') !== false ? substr(strrchr($email, '@'), 1) : '';
            foreach ((array) ($config['email_domains'] ?? array()) as $configured) {
                $configured = ltrim(strtolower(trim((string) $configured)), '@');
                if ($configured !== '' && ($domain === $configured || substr($domain, -strlen('.' . $configured)) === '.' . $configured)) {
                    $reasons[] = 'email_domain:' . $configured;
                    break;
                }
            }

            $phone = preg_replace('/\D+/', '', (string) ($payload['contact']['phone'] ?? ''));
            foreach ((array) ($config['phone_patterns'] ?? array()) as $pattern) {
                if (self::matches_pattern($phone, (string) $pattern)) {
                    $reasons[] = 'phone_pattern';
                    break;
                }
            }

            $haystack = strtolower(trim((string) ($payload['contact']['name'] ?? '') . ' ' . (string) ($payload['content']['message'] ?? '')));
            foreach ((array) ($config['tokens'] ?? array()) as $token) {
                $token = strtolower(trim((string) $token));
                if ($token !== '' && strpos($haystack, $token) !== false) {
                    $reasons[] = 'configured_token:' . self::key($token);
                    break;
                }
            }
        }

        $is_test = !empty($reasons);
        $autoresponse = strtolower(trim((string) ($config['autoresponse'] ?? 'skip')));
        if (!in_array($autoresponse, array('skip', 'allow'), true)) {
            $autoresponse = 'skip';
        }
        $result = array(
            'is_test_lead'  => $is_test,
            'test_reason'   => $is_test ? implode(',', array_values(array_unique($reasons))) : '',
            'score_eligible' => !$is_test,
            'qualification' => $is_test ? 'test' : '',
            'routing'       => $is_test ? 'skip' : 'route',
            'notification'  => $is_test ? 'skip' : 'notify',
            'autoresponse'  => $is_test ? $autoresponse : 'allow',
            'ads_feedback'  => $is_test ? 'skip' : 'allow',
            'sla'           => $is_test ? 'skip' : 'allow',
            'escalation'    => $is_test ? 'skip' : 'allow',
        );
        return function_exists('apply_filters')
            ? (array) apply_filters('estandar_leads_qa_decision', $result, $payload, $config)
            : $result;
    }

    public static function from_payload(array $payload): array
    {
        return is_array($payload['qa'] ?? null) ? $payload['qa'] : self::evaluate($payload);
    }

    public static function is_test_lead(int $lead_id): bool
    {
        if ($lead_id <= 0 || !isset($GLOBALS['wpdb'])) {
            return false;
        }
        global $wpdb;
        $json = $wpdb->get_var($wpdb->prepare(
            "SELECT context_json FROM {$wpdb->prefix}es_lead_submissions WHERE lead_id = %d ORDER BY id ASC LIMIT 1",
            $lead_id
        ));
        $context = json_decode((string) $json, true);
        return is_array($context) && !empty($context['qa_protection']['is_test_lead']);
    }

    /**
     * Canonical ads-feedback enforcement gate — the single decision point both
     * the Meta and Google adapters consult immediately before enqueue/send.
     *
     * It does NOT re-implement any policy: it composes the canonical
     * sources verbatim —
     *   1. QA/test protection (this class) — payload is authoritative at the
     *      ingestion moment, the persisted context_json otherwise;
     *   2. the commercial qualification decision (decision_for) — read only,
     *      the `ads_feedback_policy` field;
     *   3. the Lead Assessment fit axis (LEAD_ASSESSMENT_V1) — a wrong-fit /
     *      undetermined fit blocks export UNLESS the qualification decision
     *      explicitly allows it (operator confirmation overrides a capture-time
     *      wrong-fit assessment — the operator's positive verdict is
     *      authoritative for the fit axis too).
     *
     * Precedence (blocked = true wins):
     *   is_test  >  qualification policy 'skip'  >  fit blocks (unless policy 'allow')
     *
     * Mirrors the observability resolver's eligibility rule
     * (eligible = !is_test && policy !== 'skip' && !fit_blocking) so the SSOT
     * panel reflects the enforced state with no second definition of "eligible".
     *
     * @param int   $lead_id
     * @param int   $submission_id  0 = resolve the lead's latest submission.
     * @param array $payload        capture payload when available (ingestion).
     * @return bool true = suppress the ads-feedback export.
     */
    public static function ads_feedback_blocked(int $lead_id, int $submission_id = 0, array $payload = array()): bool
    {
        if ($lead_id <= 0) {
            return false;
        }

        // Primary QA signal is the persisted context_json (same one the
        // observability resolver reads). The capture payload is an additional
        // ingestion-moment signal — checked only as a fallback, so a QA lead is
        // never under-detected.
        $is_test = self::is_test_lead($lead_id);
        if (!$is_test && !empty($payload)) {
            $is_test = !empty(self::from_payload($payload)['is_test_lead']);
        }
        if ($is_test) {
            return true;
        }

        if (!class_exists('Estandar_Commercial_Qualification_Engine')) {
            return false;
        }
        if ($submission_id <= 0) {
            $submission_id = self::latest_submission_id($lead_id);
        }
        $decision = Estandar_Commercial_Qualification_Engine::decision_for(
            $lead_id,
            $submission_id,
            !empty($payload) ? $payload : null
        );
        $policy = strtolower(trim((string) ($decision['ads_feedback_policy'] ?? '')));
        if ($policy === 'skip') {
            return true;
        }

        // LEAD_ASSESSMENT_V1 — fit axis gate. Composed via the SSOT resolver so
        // the enforced state and the observability panel can never diverge. The
        // DEFAULT qualification decision is NOT operator confirmation: a
        // capture-time wrong-fit blocks export until a human confirms otherwise.
        if (class_exists('Estandar_Leads_Fit')) {
            $signal = self::fit_gate_signal($lead_id);
            return Estandar_Leads_Fit::should_block_ads_export(
                $signal['fit'],
                $signal['status'],
                $signal['is_valid_lead'],
                (string) ($decision['decision_source'] ?? 'default'),
                $policy
            );
        }
        return false;
    }

    /** One query: the fit gate inputs for a lead (fit + status + validity). */
    private static function fit_gate_signal(int $lead_id): array
    {
        $empty = array('fit' => '', 'status' => '', 'is_valid_lead' => null);
        if (!isset($GLOBALS['wpdb'])) {
            return $empty;
        }
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT commercial_fit, status, is_valid_lead FROM {$wpdb->prefix}es_leads WHERE id = %d LIMIT 1",
            (int) $lead_id
        ), ARRAY_A);
        if (!is_array($row)) {
            return $empty;
        }
        return array(
            'fit'            => is_string($row['commercial_fit'] ?? null) ? strtoupper(trim($row['commercial_fit'])) : '',
            'status'         => (string) ($row['status'] ?? ''),
            'is_valid_lead'  => isset($row['is_valid_lead']) ? (int) $row['is_valid_lead'] : null,
        );
    }

    /** Latest submission for a lead — same resolution the observability resolver uses. */
    private static function latest_submission_id(int $lead_id): int
    {
        if ($lead_id <= 0 || !isset($GLOBALS['wpdb'])) {
            return 0;
        }
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}es_lead_submissions WHERE lead_id = %d ORDER BY id DESC LIMIT 1",
            $lead_id
        ));
    }

    private static function actor(array $payload): string
    {
        foreach (array('actor_type', 'actor') as $key) {
            if (is_scalar($payload[$key] ?? null)) {
                return self::key((string) $payload[$key]);
            }
            if (is_array($payload[$key] ?? null) && is_scalar($payload[$key]['type'] ?? null)) {
                return self::key((string) $payload[$key]['type']);
            }
        }
        foreach (array('context', 'request', 'meta') as $group) {
            $data = is_array($payload[$group] ?? null) ? $payload[$group] : array();
            foreach (array('actor_type', 'actor') as $key) {
                if (is_scalar($data[$key] ?? null)) {
                    return self::key((string) $data[$key]);
                }
                if (is_array($data[$key] ?? null) && is_scalar($data[$key]['type'] ?? null)) {
                    return self::key((string) $data[$key]['type']);
                }
            }
        }
        return '';
    }

    private static function value(array $payload, string $path)
    {
        $value = $payload;
        foreach (array_filter(explode('.', $path), 'strlen') as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }
        return $value;
    }

    private static function truthy($value): bool
    {
        return $value === true || $value === 1 || in_array(strtolower(trim((string) $value)), array('1', 'true', 'yes', 'on', 'qa', 'test'), true);
    }

    private static function matches_pattern(string $value, string $pattern): bool
    {
        $pattern = trim($pattern);
        if ($value === '' || $pattern === '') {
            return false;
        }
        if ($pattern[0] === '/' && @preg_match($pattern, '') !== false) {
            return preg_match($pattern, $value) === 1;
        }
        $normalized = preg_replace('/\D+/', '', $pattern);
        return $normalized !== '' && strpos($value, $normalized) !== false;
    }

    private static function key(string $value): string
    {
        return strtolower(trim((string) preg_replace('/[^a-zA-Z0-9_.-]+/', '_', $value), '_'));
    }
}
