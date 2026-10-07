<?php
if ( ! defined('ABSPATH') ) {
    exit;
}

class Estandar_Forms_Qualification_Engine {
    public static function maybe_evaluate(object $form, array $data): ?array {
        try {
            $config = self::config($form);
            if ( empty($config['enabled']) ) {
                return null;
            }

            $profile_id = sanitize_key((string) ($config['profile_id'] ?? ''));
            $profile_version = sanitize_text_field((string) ($config['profile_version'] ?? ''));
            if ($profile_id === '' || ! class_exists('Estandar_Forms_Qualification_Profiles')) {
                return null;
            }

            $profile = Estandar_Forms_Qualification_Profiles::get($profile_id, $profile_version);
            if ( empty($profile) ) {
                self::warn('FORMS_QUALIFICATION_PROFILE_MISSING', $form, ['profile_id' => $profile_id]);
                return null;
            }

            return self::evaluate_profile($profile, $data);
        } catch (Throwable $e) {
            self::warn('FORMS_QUALIFICATION_FAILURE', $form, ['exception' => get_class($e)]);
            return null;
        }
    }

    public static function public_message(array $qualification): string {
        return sanitize_text_field((string) ($qualification['public_message'] ?? ''));
    }

    private static function config(object $form): array {
        $schema = json_decode((string) ($form->context_schema ?? ''), true);
        $schema = is_array($schema) ? $schema : [];
        $qualification = isset($schema['qualification']) && is_array($schema['qualification'])
            ? $schema['qualification']
            : [];

        return [
            'enabled' => ! empty($qualification['enabled']),
            'profile_id' => sanitize_key((string) ($qualification['profile_id'] ?? '')),
            'profile_version' => sanitize_text_field((string) ($qualification['profile_version'] ?? '')),
        ];
    }

    private static function evaluate_profile(array $profile, array $data): array {
        $fields = is_array($profile['fields'] ?? null) ? $profile['fields'] : [];
        $scores = is_array($profile['scores'] ?? null) ? $profile['scores'] : [];
        $score = 0;

        foreach ($scores as $dimension => $values) {
            $field = (string) ($fields[$dimension] ?? '');
            $value = sanitize_key((string) ($data[$field] ?? ''));
            $score += (int) ($values[$value] ?? 0);
        }

        $score = max(0, min(100, $score));
        $tier = self::tier($score, (array) ($profile['thresholds'] ?? []));
        $result = self::result($tier, $data, $fields);
        $recommended_project = self::recommended_project($tier, $data, $fields, (array) ($profile['project_recommendations'] ?? []));
        $routing_hint = self::routing_hint($tier, $result);
        $summary = self::summary($tier, $result, $recommended_project);
        $messages = is_array($profile['result_messages'] ?? null) ? $profile['result_messages'] : [];

        return [
            'profile_id' => sanitize_key((string) ($profile['id'] ?? '')),
            'profile_version' => sanitize_text_field((string) ($profile['version'] ?? '')),
            'score' => $score,
            'tier' => $tier,
            'result' => $result,
            'recommended_project' => $recommended_project,
            'recommended_service' => '',
            'routing_hint' => $routing_hint,
            'summary' => $summary,
            'public_message' => sanitize_text_field((string) ($messages[$result] ?? '')),
            'evaluated_at' => gmdate('c'),
        ];
    }

    private static function tier(int $score, array $thresholds): string {
        $thresholds = array_merge(['A' => 80, 'B' => 60, 'C' => 40, 'D' => 0], $thresholds);
        foreach (['A', 'B', 'C'] as $tier) {
            if ($score >= (int) $thresholds[$tier]) {
                return $tier;
            }
        }
        return 'D';
    }

    private static function result(string $tier, array $data, array $fields): string {
        $financing = sanitize_key((string) ($data[$fields['financing'] ?? ''] ?? ''));
        $guarantor = sanitize_key((string) ($data[$fields['guarantor'] ?? ''] ?? ''));

        if ($tier === 'B' || $financing === 'subsidio' || $guarantor === 'posible') {
            return 'qualified_with_conditions';
        }
        if ($tier === 'A') {
            return 'qualified';
        }
        if ($tier === 'C') {
            return 'needs_review';
        }
        return 'not_compatible_now';
    }

    private static function recommended_project(string $tier, array $data, array $fields, array $recommendations): string {
        $selected = sanitize_key((string) ($data[$fields['project'] ?? ''] ?? ''));
        if ($selected !== '' && $tier !== 'D') {
            return $selected;
        }
        return sanitize_key((string) ($recommendations[$tier] ?? ''));
    }

    private static function routing_hint(string $tier, string $result): string {
        if ($tier === 'A') {
            return 'high_priority_sales';
        }
        if ($result === 'qualified_with_conditions') {
            return 'financial_review';
        }
        if ($tier === 'D') {
            return 'low_priority_nurture';
        }
        return 'standard_sales';
    }

    private static function summary(string $tier, string $result, string $project): string {
        $project_text = $project !== '' ? ' Proyecto sugerido: ' . $project . '.' : '';
        if ($result === 'qualified') {
            return 'Alta compatibilidad para avanzar.' . $project_text;
        }
        if ($result === 'qualified_with_conditions') {
            return 'Perfil viable, requiere validar condiciones financieras o aval.' . $project_text;
        }
        if ($result === 'needs_review') {
            return 'Caso requiere revisión comercial antes de orientar el siguiente paso.' . $project_text;
        }
        return 'Compatibilidad baja con las condiciones declaradas actualmente.';
    }

    private static function warn(string $code, object $form, array $context = []): void {
        if (function_exists('estandar_forms_telemetry_event')) {
            estandar_forms_telemetry_event('warning', $code, 'Forms qualification skipped safely.', array_merge([
                'form_id' => absint($form->id ?? 0),
            ], $context));
        }
    }
}
