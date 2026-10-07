<?php

if (!defined('ABSPATH')) {
    exit;
}

class Estandar_Leads_Operator_Resolver
{
    public static function resolve_for_project(string $project_slug, array $args = array()): array
    {
        $project_slug = Estandar_Leads_Projects::normalize_slug($project_slug);
        $operators = isset($args['operators']) && is_array($args['operators'])
            ? $args['operators']
            : self::active_operators(!empty($args['require_email']), !empty($args['require_whatsapp']));
        $include_fallback = array_key_exists('include_fallback', $args) ? (bool) $args['include_fallback'] : true;

        if ($project_slug === '') {
            return array('operators' => $operators, 'fallback' => false, 'routing_mode' => 'no_slug');
        }

        $matched_primary = array();
        $matched_allowed = array();

        foreach ($operators as $operator) {
            $operator_slug = Estandar_Leads_Projects::normalize_slug($operator->project_slug ?? '', $operator->area ?? '');
            if ($operator_slug !== '' && $operator_slug === $project_slug) {
                $matched_primary[] = $operator;
                continue;
            }

            $allowed_raw = json_decode((string) ($operator->allowed_projects_json ?? ''), true);
            foreach (is_array($allowed_raw) ? $allowed_raw : array() as $entry) {
                if (Estandar_Leads_Projects::normalize_slug((string) $entry) === $project_slug) {
                    $matched_allowed[] = $operator;
                    break;
                }
            }
        }

        if (!empty($matched_primary)) {
            return array('operators' => self::prefer_registry_operator($matched_primary, $project_slug), 'fallback' => false, 'routing_mode' => 'project_match');
        }
        if (!empty($matched_allowed)) {
            return array('operators' => self::prefer_registry_operator($matched_allowed, $project_slug), 'fallback' => false, 'routing_mode' => 'allowed_project_match');
        }

        $fallback_limit = max(1, (int) apply_filters('estandar_leads_fallback_broadcast_limit', 5));
        return array(
            'operators' => $include_fallback ? array_slice($operators, 0, $fallback_limit) : array(),
            'fallback' => $include_fallback,
            'routing_mode' => $include_fallback ? 'fallback' : 'missing_operator',
        );
    }

    public static function first_for_project(string $project_slug, array $args = array())
    {
        $args['include_fallback'] = $args['include_fallback'] ?? false;
        $routing = self::resolve_for_project($project_slug, $args);
        return $routing['operators'][0] ?? null;
    }

    private static function active_operators(bool $require_email, bool $require_whatsapp): array
    {
        global $wpdb;

        $where = array('active = 1');
        if ($require_email) {
            $where[] = "email <> ''";
        }
        if ($require_whatsapp) {
            $where[] = "whatsapp_number <> ''";
        }

        return (array) $wpdb->get_results(
            "SELECT id, operator_uuid, name, email, whatsapp_number, project_slug, area, allowed_projects_json, active
             FROM {$wpdb->prefix}es_operators
             WHERE " . implode(' AND ', $where) . '
             ORDER BY name ASC, id ASC'
        );
    }

    private static function prefer_registry_operator(array $operators, string $project_slug): array
    {
        $preferred_email = '';
        $preferred_name = '';
        $preferred_phone = '';

        if (class_exists('Estandar_Core_Project_Registry') && method_exists('Estandar_Core_Project_Registry', 'get')) {
            $project = Estandar_Core_Project_Registry::get($project_slug);
            if (is_array($project)) {
                $preferred_email = strtolower(sanitize_email((string) ($project['operator_email'] ?? '')));
                $preferred_name = strtolower(sanitize_text_field((string) ($project['operator_name'] ?? '')));
                $preferred_phone = preg_replace('/\D+/', '', (string) ($project['operator_phone'] ?? ''));
            }
        }

        usort($operators, static function ($a, $b) use ($preferred_email, $preferred_name, $preferred_phone): int {
            return self::preference_score($b, $preferred_email, $preferred_name, $preferred_phone)
                <=> self::preference_score($a, $preferred_email, $preferred_name, $preferred_phone);
        });

        return $operators;
    }

    private static function preference_score($operator, string $email, string $name, string $phone): int
    {
        $score = 0;
        $operator_email = strtolower(sanitize_email((string) ($operator->email ?? '')));
        $operator_name = strtolower(sanitize_text_field((string) ($operator->name ?? '')));
        $operator_phone = preg_replace('/\D+/', '', (string) ($operator->whatsapp_number ?? ''));

        if ($email !== '' && $operator_email === $email) {
            $score += 100;
        }
        if ($phone !== '' && $operator_phone === $phone) {
            $score += 50;
        }
        if ($name !== '' && $operator_name === $name) {
            $score += 10;
        }

        return $score;
    }
}
