<?php
if ( ! defined('ABSPATH') ) {
    exit;
}

class Estandar_Forms_Qualification_Profiles {
    public static function get(string $profile_id, string $profile_version = ''): array {
        $profile_id = sanitize_key($profile_id);
        $profiles = self::profiles();

        if ( empty($profiles[$profile_id]) ) {
            return [];
        }

        $profile = $profiles[$profile_id];
        if ($profile_version !== '' && (string) ($profile['version'] ?? '') !== $profile_version) {
            return [];
        }

        return $profile;
    }

    private static function profiles(): array {
        return [
            'real_estate_residential_v1' => [
                'id' => 'real_estate_residential_v1',
                'version' => '1.0.0',
                'result_messages' => [
                    'qualified' => 'Gracias. Tu perfil es compatible para avanzar. Un ejecutivo te contactará para continuar.',
                    'qualified_with_conditions' => 'Gracias. Tu caso parece viable, pero requiere validar algunos antecedentes antes de avanzar.',
                    'needs_review' => 'Gracias. Recibimos tus antecedentes. Un ejecutivo revisará tu caso para orientarte correctamente.',
                    'not_compatible_now' => 'Gracias. Con la información entregada, puede que este no sea el mejor momento para avanzar. Te contactaremos para revisar alternativas.',
                ],
                'thresholds' => [
                    'A' => 80,
                    'B' => 60,
                    'C' => 40,
                    'D' => 0,
                ],
                'fields' => [
                    'project' => 'proyecto_interes',
                    'financing' => 'tipo_financiamiento',
                    'income' => 'renta_mensual',
                    'down_payment' => 'pie_disponible',
                    'debt' => 'deuda_mensual',
                    'guarantor' => 'tiene_aval',
                    'timeline' => 'plazo_compra',
                ],
                'scores' => [
                    'income' => [
                        'lt_800k' => 5,
                        '800k_1200k' => 12,
                        '1200k_1800k' => 20,
                        '1800k_plus' => 30,
                    ],
                    'down_payment' => [
                        'lt_5m' => 4,
                        '5m_10m' => 10,
                        '10m_20m' => 16,
                        '20m_plus' => 20,
                    ],
                    'debt' => [
                        'none' => 15,
                        'low' => 11,
                        'medium' => 6,
                        'high' => 0,
                    ],
                    'financing' => [
                        'contado' => 15,
                        'credito' => 12,
                        'subsidio' => 8,
                        'no_sabe' => 4,
                    ],
                    'guarantor' => [
                        'si' => 10,
                        'posible' => 6,
                        'no' => 0,
                    ],
                    'timeline' => [
                        '0_3' => 10,
                        '3_6' => 8,
                        '6_12' => 5,
                        '12_plus' => 2,
                    ],
                ],
                'project_recommendations' => [
                    'A' => '',
                    'B' => '',
                    'C' => '',
                    'D' => '',
                ],
            ],
        ];
    }
}
