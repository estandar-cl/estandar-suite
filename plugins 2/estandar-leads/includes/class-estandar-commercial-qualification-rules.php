<?php
/**
 * COMMERCIAL_QUALIFICATION_V1 — Rule engine (generic, deterministic, config-driven).
 *
 * The engine contains ZERO business knowledge. Rules are contributed by
 * configuration (wp_option) or by code through a filter; the engine only
 * evaluates conditions against a normalized input map and merges actions.
 *
 * Rule shape (all keys optional except id):
 *   id          string   unique, stable identifier
 *   name        string   human label
 *   enabled     bool     default true
 *   priority    int      default 10 — LOWER number = HIGHER precedence
 *   source      string   global | project | manual | system (provenance)
 *   match       string   all | any (condition combinator, default all)
 *   conditions  array    [ ['field'=>'context.intent','op'=>'equals','value'=>'x'], ... ]
 *   action      array    decision / qualification / commercial_fit /
 *                        routing_policy / notification_policy /
 *                        autoresponse_policy / ads_feedback_policy /
 *                        sales_stage / quality / invalid_reason /
 *                        confidence / review_required / reason / stop
 *                        (short aliases accepted: routing, notification,
 *                        autoresponse, ads_feedback)
 *
 * Condition operators: field_exists, field_missing, empty, not_empty,
 * equals, not_equals, contains, not_contains, starts_with, regex,
 * in, not_in, gt, gte, lt, lte, min_length, max_length.
 *
 * DATA-SOURCE PRECEDENCE (transversal, no business knowledge):
 * every rule is classified by the WEAKEST data source its conditions
 * reference — structured data (1) → persisted context (2) → configured /
 * custom keys (3) → free text (4, fallback only). Rules evaluate in
 * (data_tier ASC, priority ASC, id ASC) order, so a free-text rule can
 * never override a value already decided from structured data, regardless
 * of its configured priority. Within a tier, priority order is unchanged.
 *
 * Determinism: actions merge first-writer-wins (a higher-precedence rule's
 * value is never overridden by a lower-precedence rule); 'stop' => true
 * halts evaluation.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Estandar_Commercial_Qualification_Rules
{
    const OPTION = 'estandar_commercial_qualification_rules_v1';

    const SOURCES = array('global', 'project', 'manual', 'system');

    /**
     * Load the effective rule set for a project.
     *
     * Sources, in provenance order:
     *  - system: contributed by code via the filter (plugins extending the platform)
     *  - global: option entry 'global' (applies to every project)
     *  - project: option entry keyed by project slug (or project uuid)
     *  - manual: option entry 'manual' (ad-hoc operator overrides)
     *
     * Projects contribute configuration; the engine contributes behavior.
     * No project slug is ever hardcoded here.
     *
     * @return array normalized, enabled rules sorted deterministically.
     */
    public static function load(string $project_slug = ''): array
    {
        $stored = function_exists('get_option') ? get_option(self::OPTION, array()) : array();
        $stored = is_array($stored) ? $stored : array();

        $rules = array();
        foreach (self::bucket($stored, 'global', 'global') as $rule) {
            $rules[] = $rule;
        }
        foreach (self::bucket($stored, 'manual', 'manual') as $rule) {
            $rules[] = $rule;
        }
        if ($project_slug !== '') {
            foreach (self::bucket($stored, $project_slug, 'project') as $rule) {
                $rules[] = $rule;
            }
            $uuid = self::project_uuid($project_slug);
            if ($uuid !== '' && isset($stored[$uuid])) {
                foreach (self::bucket($stored, $uuid, 'project') as $rule) {
                    $rules[] = $rule;
                }
            }
        }

        // Code-level contribution (system provenance unless declared otherwise).
        if (function_exists('apply_filters')) {
            $contributed = apply_filters('estandar_commercial_qualification_rules', array(), $project_slug);
            foreach (is_array($contributed) ? $contributed : array() as $rule) {
                $rule = self::normalize_rule($rule, 'system');
                if ($rule !== null) {
                    $rules[] = $rule;
                }
            }
        }

        return self::sort_rules(array_filter($rules, static function ($rule) {
            return !empty($rule['enabled']);
        }));
    }

    /** @return array<int,array> normalized rules from one option bucket. */
    private static function bucket(array $stored, string $key, string $default_source): array
    {
        $raw = isset($stored[$key]) && is_array($stored[$key]) ? $stored[$key] : array();
        $out = array();
        foreach ($raw as $rule) {
            $rule = self::normalize_rule($rule, $default_source);
            if ($rule !== null) {
                $out[] = $rule;
            }
        }
        return $out;
    }

    private static function project_uuid(string $project_slug): string
    {
        if (!class_exists('Estandar_Leads_Projects')) {
            return '';
        }
        foreach ((array) Estandar_Leads_Projects::registry() as $project) {
            if ((string) ($project['project_slug'] ?? '') === $project_slug) {
                return (string) ($project['project_uuid'] ?? '');
            }
        }
        return '';
    }

    /** @return array|null null when the rule is structurally unusable. */
    public static function normalize_rule($rule, string $default_source = 'global')
    {
        if (!is_array($rule)) {
            return null;
        }
        $id = trim((string) ($rule['id'] ?? ''));
        if ($id === '') {
            return null;
        }
        $source = strtolower(trim((string) ($rule['source'] ?? $default_source)));
        if (!in_array($source, self::SOURCES, true)) {
            $source = $default_source;
        }
        $conditions = array();
        foreach (is_array($rule['conditions'] ?? null) ? $rule['conditions'] : array() as $condition) {
            if (!is_array($condition)) {
                continue;
            }
            $field = trim((string) ($condition['field'] ?? ''));
            $op = strtolower(trim((string) ($condition['op'] ?? ($condition['operator'] ?? ''))));
            if ($field === '' || $op === '') {
                continue;
            }
            $conditions[] = array(
                'field' => $field,
                'op'    => $op,
                'value' => $condition['value'] ?? '',
            );
        }
        $normalized = array(
            'id'         => $id,
            'name'       => trim((string) ($rule['name'] ?? $id)),
            'enabled'    => !array_key_exists('enabled', $rule) || !empty($rule['enabled']),
            'priority'   => isset($rule['priority']) ? (int) $rule['priority'] : 10,
            'source'     => $source,
            'match'      => strtolower((string) ($rule['match'] ?? 'all')) === 'any' ? 'any' : 'all',
            'conditions' => $conditions,
            'action'     => self::normalize_action(is_array($rule['action'] ?? null) ? $rule['action'] : array()),
        );
        $normalized['data_tier'] = self::rule_data_tier($normalized);
        return $normalized;
    }

    /**
     * Canonicalize action keys. Short aliases (routing, notification,
     * autoresponse, ads_feedback) map to their *_policy canonical form;
     * an explicit canonical key always wins over its alias.
     */
    public static function normalize_action(array $action): array
    {
        $aliases = array(
            'routing'      => 'routing_policy',
            'notification' => 'notification_policy',
            'autoresponse' => 'autoresponse_policy',
            'ads_feedback' => 'ads_feedback_policy',
        );
        foreach ($aliases as $alias => $canonical) {
            if (array_key_exists($alias, $action)) {
                if (!array_key_exists($canonical, $action)) {
                    $action[$canonical] = $action[$alias];
                }
                unset($action[$alias]);
            }
        }
        return $action;
    }

    /**
     * DATA-SOURCE TIERS — namespace-based, zero business knowledge:
     *   1 structured data   (fields.*, contact.*, scoring.*, request.*,
     *                        spam.*, has_email, has_phone, source_plugin,
     *                        schema_version, service)
     *   2 persisted context (context.*, attribution.*, chatbot.*, project, intent)
     *   3 configured/custom keys (anything contributed outside the known namespaces)
     *   4 free text — fallback only (content.*, message)
     */
    public static function field_tier(string $field): int
    {
        $field = strtolower(trim($field));
        if ($field === '') {
            return 3;
        }
        foreach (array('content.', 'message') as $prefix) {
            if (strpos($field, $prefix) === 0) {
                return 4;
            }
        }
        foreach (array('fields.', 'contact.', 'scoring.', 'request.', 'spam.') as $prefix) {
            if (strpos($field, $prefix) === 0) {
                return 1;
            }
        }
        if (in_array($field, array('has_email', 'has_phone', 'source_plugin', 'schema_version', 'service'), true)) {
            return 1;
        }
        foreach (array('context.', 'attribution.', 'chatbot.') as $prefix) {
            if (strpos($field, $prefix) === 0) {
                return 2;
            }
        }
        if (in_array($field, array('project', 'intent'), true)) {
            return 2;
        }
        return 3;
    }

    /** Weakest referenced source decides: a rule that needs free text IS a free-text rule. */
    public static function rule_data_tier(array $rule): int
    {
        $tier = 0;
        foreach (is_array($rule['conditions'] ?? null) ? $rule['conditions'] : array() as $condition) {
            $tier = max($tier, self::field_tier((string) ($condition['field'] ?? '')));
        }
        return $tier > 0 ? $tier : 3;
    }

    /** Deterministic order: data_tier ASC (source precedence), then priority ASC, then id ASC. */
    public static function sort_rules(array $rules): array
    {
        $rules = array_values($rules);
        usort($rules, static function ($a, $b) {
            $tier_a = isset($a['data_tier']) ? (int) $a['data_tier'] : self::rule_data_tier($a);
            $tier_b = isset($b['data_tier']) ? (int) $b['data_tier'] : self::rule_data_tier($b);
            if ($tier_a !== $tier_b) {
                return $tier_a <=> $tier_b;
            }
            if ((int) $a['priority'] !== (int) $b['priority']) {
                return (int) $a['priority'] <=> (int) $b['priority'];
            }
            return strcmp((string) $a['id'], (string) $b['id']);
        });
        return $rules;
    }

    /** @return bool whether every/any condition matches the input map. */
    public static function matches(array $rule, array $input): bool
    {
        $conditions = $rule['conditions'] ?? array();
        if (empty($conditions)) {
            return false; // a rule without conditions never fires — no implicit catch-alls.
        }
        $any = ($rule['match'] ?? 'all') === 'any';
        foreach ($conditions as $condition) {
            $ok = self::condition_matches($condition, $input);
            if ($any && $ok) {
                return true;
            }
            if (!$any && !$ok) {
                return false;
            }
        }
        return !$any;
    }

    public static function condition_matches(array $condition, array $input): bool
    {
        $field = (string) $condition['field'];
        $op = (string) $condition['op'];
        $expected = $condition['value'] ?? '';
        $exists = array_key_exists($field, $input);
        $actual = $exists ? $input[$field] : null;
        $actual_str = self::comparable($actual);

        switch ($op) {
            case 'field_exists':
                return $exists && trim((string) $actual) !== '';
            case 'field_missing':
            case 'empty':
                return !$exists || trim((string) $actual) === '';
            case 'not_empty':
                return $exists && trim((string) $actual) !== '';
            case 'equals':
                return $actual_str === self::comparable($expected);
            case 'not_equals':
                return $actual_str !== self::comparable($expected);
            case 'contains':
                $needle = self::comparable($expected);
                return $needle !== '' && strpos($actual_str, $needle) !== false;
            case 'not_contains':
                $needle = self::comparable($expected);
                return $needle === '' || strpos($actual_str, $needle) === false;
            case 'starts_with':
                $needle = self::comparable($expected);
                return $needle !== '' && strpos($actual_str, $needle) === 0;
            case 'regex':
                $pattern = (string) $expected;
                if ($pattern === '' || @preg_match($pattern, '') === false) {
                    return false; // invalid pattern never matches — deterministic, no notices.
                }
                return (bool) preg_match($pattern, (string) ($actual ?? ''));
            case 'in':
                return in_array($actual_str, self::comparable_list($expected), true);
            case 'not_in':
                return !in_array($actual_str, self::comparable_list($expected), true);
            case 'gt':
                return is_numeric($actual) && (float) $actual > (float) $expected;
            case 'gte':
                return is_numeric($actual) && (float) $actual >= (float) $expected;
            case 'lt':
                return is_numeric($actual) && (float) $actual < (float) $expected;
            case 'lte':
                return is_numeric($actual) && (float) $actual <= (float) $expected;
            case 'min_length':
                return mb_strlen(trim((string) ($actual ?? ''))) >= (int) $expected;
            case 'max_length':
                return mb_strlen(trim((string) ($actual ?? ''))) <= (int) $expected;
        }
        return false; // unknown operator never matches.
    }

    /** Canonical comparable form: trimmed, lowercased, accent-stripped. */
    public static function comparable($value): string
    {
        $value = trim((string) ($value ?? ''));
        if (function_exists('remove_accents')) {
            $value = remove_accents($value);
        }
        return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
    }

    /** @return array<int,string> */
    private static function comparable_list($expected): array
    {
        $list = is_array($expected) ? $expected : explode(',', (string) $expected);
        return array_map(array(__CLASS__, 'comparable'), $list);
    }
}
