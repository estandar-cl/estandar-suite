<?php
if ( ! defined('ABSPATH') ) {
    exit;
}

/**
 * =====================================================
 * Validación RUT Chile (DV real, algoritmo oficial)
 * Compatible PHP 8.1+
 * Uso: estandar_validar_rut('12.345.678-5')
 * =====================================================
 */
function estandar_validar_rut( string $rut ): bool {

    // Normalizar: solo números y K, en mayúscula
    $rut = strtoupper( preg_replace('/[^0-9K]/', '', $rut) );

    // Largo mínimo razonable (ej: 1234567K)
    if ( $rut === '' || strlen($rut) < 8 ) {
        return false;
    }

    // Separar cuerpo y dígito verificador
    $dv  = substr($rut, -1);
    $num = substr($rut, 0, -1);

    // El cuerpo debe ser numérico
    if ( ! ctype_digit($num) ) {
        return false;
    }

    $suma = 0;
    $mul  = 2;

    // Algoritmo módulo 11
    for ( $i = strlen($num) - 1; $i >= 0; $i-- ) {
        $suma += $mul * (int) $num[$i];
        $mul   = ($mul < 7) ? $mul + 1 : 2;
    }

    $res = 11 - ( $suma % 11 );

    if ( $res === 11 ) {
        $dv_calc = '0';
    } elseif ( $res === 10 ) {
        $dv_calc = 'K';
    } else {
        $dv_calc = (string) $res;
    }

    return $dv === $dv_calc;
}

function estandar_forms_country_options(): array {
    return [
        'CL' => 'Chile',
        'AR' => 'Argentina',
        'PE' => 'Peru',
        'MX' => 'Mexico',
        'ES' => 'Espana',
        'US' => 'Estados Unidos',
    ];
}

function estandar_forms_get_form_country($form): string {
    $country = 'CL';

    if ( is_object($form) && ! empty($form->form_country) ) {
        $country = (string) $form->form_country;
    } elseif ( is_array($form) && ! empty($form['form_country']) ) {
        $country = (string) $form['form_country'];
    }

    $country = strtoupper(sanitize_key($country));
    $options = estandar_forms_country_options();

    return isset($options[$country]) ? $country : 'CL';
}

function estandar_forms_phone_specs(): array {
    return [
        'CL' => [
            'placeholder' => '+56 9 1234 5678',
            'pattern'     => '^(\+?56)?[2-9]\d{8}$',
            'minlength'   => 9,
            'maxlength'   => 11,
            'message'     => 'Ingresa un telefono valido de Chile.',
        ],
        'AR' => [
            'placeholder' => '+54 11 1234 5678',
            'pattern'     => '^(\+?54)?\d{10}$',
            'minlength'   => 10,
            'maxlength'   => 12,
            'message'     => 'Ingresa un telefono valido de Argentina.',
        ],
        'PE' => [
            'placeholder' => '+51 912 345 678',
            'pattern'     => '^(\+?51)?9\d{8}$',
            'minlength'   => 9,
            'maxlength'   => 11,
            'message'     => 'Ingresa un telefono valido de Peru.',
        ],
        'MX' => [
            'placeholder' => '+52 55 1234 5678',
            'pattern'     => '^(\+?52)?\d{10}$',
            'minlength'   => 10,
            'maxlength'   => 12,
            'message'     => 'Ingresa un telefono valido de Mexico.',
        ],
        'ES' => [
            'placeholder' => '+34 612 345 678',
            'pattern'     => '^(\+?34)?[6-9]\d{8}$',
            'minlength'   => 9,
            'maxlength'   => 11,
            'message'     => 'Ingresa un telefono valido de Espana.',
        ],
        'US' => [
            'placeholder' => '+1 202 555 0123',
            'pattern'     => '^(\+?1)?\d{10}$',
            'minlength'   => 10,
            'maxlength'   => 11,
            'message'     => 'Ingresa un telefono valido de Estados Unidos.',
        ],
    ];
}

function estandar_forms_get_phone_spec(string $country): array {
    $specs = estandar_forms_phone_specs();
    return $specs[$country] ?? [
        'placeholder' => '+56 9 1234 5678',
        'pattern'     => '^\+?56?\d{9,12}$',
        'minlength'   => 9,
        'maxlength'   => 15,
        'message'     => 'Ingresa un telefono chileno valido (ej: +569XXXXXXXX).',
    ];
}

/**
 * Returns HTML5 validation attributes for a phone input field.
 */
function estandar_forms_phone_input_attrs(string $country): array {
    $spec = estandar_forms_get_phone_spec($country);
    return [
        'type'         => 'tel',
        'pattern'      => '[0-9+\-\s()]{8,}',
        'minlength'    => '8',
        'maxlength'    => '15',
        'placeholder'  => $spec['placeholder'],
        'title'        => $spec['message'],
    ];
}

function estandar_forms_validate_phone(string $value, string $country): bool {
    $spec = estandar_forms_get_phone_spec($country);
    $normalized = preg_replace('/[^\d+]/', '', trim($value));

    if ( ! is_string($normalized) || $normalized === '' ) {
        return false;
    }

    if ( str_starts_with($normalized, '00') ) {
        $normalized = '+' . substr($normalized, 2);
    }

    return (bool) preg_match('/' . $spec['pattern'] . '/', $normalized);
}

function estandar_forms_normalize_conditional_logic($raw): array {
    if ( ! is_array($raw) ) {
        return [];
    }

    $field = sanitize_key((string) ($raw['field'] ?? ''));
    $operator = sanitize_key((string) ($raw['operator'] ?? 'equals'));
    $value = sanitize_text_field((string) ($raw['value'] ?? ''));
    $allowed = ['equals', 'not_equals', 'contains', 'not_empty', 'empty'];

    if ( $field === '' || ! in_array($operator, $allowed, true) ) {
        return [];
    }

    return [
        'field'    => $field,
        'operator' => $operator,
        'value'    => $value,
    ];
}

function estandar_forms_is_truthy($value): bool {
    if ( is_array($value) ) {
        return ! empty($value);
    }

    if ( is_bool($value) ) {
        return $value;
    }

    $normalized = strtolower(trim((string) $value));
    return ! in_array($normalized, ['', '0', 'false', 'no', 'off'], true);
}

function estandar_forms_evaluate_condition(array $logic, array $source): bool {
    if ( empty($logic['field']) || empty($logic['operator']) ) {
        return true;
    }

    $field = sanitize_key((string) $logic['field']);
    $operator = sanitize_key((string) $logic['operator']);
    $expected = (string) ($logic['value'] ?? '');
    $actual = $source[$field] ?? '';

    if ( is_array($actual) ) {
        $actual = implode(',', array_map('strval', $actual));
    } elseif ( is_bool($actual) ) {
        $actual = $actual ? '1' : '';
    } else {
        $actual = trim((string) $actual);
    }

    switch ( $operator ) {
        case 'not_equals':
            return $actual !== $expected;

        case 'contains':
            return $expected !== '' && str_contains($actual, $expected);

        case 'not_empty':
            return estandar_forms_is_truthy($actual);

        case 'empty':
            return ! estandar_forms_is_truthy($actual);

        case 'equals':
        default:
            return $actual === $expected;
    }
}

/* =====================================================================
   FORM ENGINE — actions_json.flow / actions_json.conditions / validators
   Fuente canónica del flujo progresivo y las condiciones dinámicas.
   actions_json admite dos formas:
   - Lista JSON  → pipeline de acciones post-submit (Action Engine, incident).
   - Objeto JSON → config del motor frontend: claves flow / conditions /
                   validators (+ opcional "actions" con el pipeline clásico).
===================================================================== */

function estandar_forms_engine_config($raw): array {
    if ( is_string($raw) ) {
        $raw = trim($raw);
        if ( $raw === '' ) {
            return [];
        }
        $decoded = json_decode($raw, true);
    } elseif ( is_array($raw) ) {
        $decoded = $raw;
    } else {
        return [];
    }

    if ( ! is_array($decoded) || $decoded === [] || array_is_list($decoded) ) {
        return [];
    }

    $config = [];

    $steps_raw = isset($decoded['flow']['steps']) && is_array($decoded['flow']['steps'])
        ? $decoded['flow']['steps']
        : [];
    $steps = [];
    foreach ( $steps_raw as $step ) {
        if ( ! is_array($step) ) {
            continue;
        }
        $step_id = sanitize_key((string) ($step['id'] ?? ''));
        $fields = [];
        foreach ( (array) ($step['fields'] ?? []) as $field_name ) {
            $field_name = sanitize_key((string) $field_name);
            if ( $field_name !== '' ) {
                $fields[] = $field_name;
            }
        }
        if ( $step_id === '' || $fields === [] ) {
            continue;
        }
        $normalized_step = [
            'id'     => $step_id,
            'fields' => $fields,
        ];
        if ( ! empty($step['label']) && is_string($step['label']) ) {
            $normalized_step['label'] = sanitize_text_field($step['label']);
        }
        if ( ! empty($step['next_label']) && is_string($step['next_label']) ) {
            $normalized_step['next_label'] = sanitize_text_field($step['next_label']);
        }
        $gate = sanitize_key((string) ($step['visible_when']['step_completed'] ?? ''));
        if ( $gate !== '' ) {
            $normalized_step['visible_when'] = ['step_completed' => $gate];
        }
        $steps[] = $normalized_step;
    }
    if ( $steps !== [] ) {
        $mode = sanitize_key((string) ($decoded['flow']['mode'] ?? 'progressive'));
        $config['flow'] = [
            'mode'  => $mode !== '' ? $mode : 'progressive',
            'steps' => $steps,
        ];
    }

    $conditions = [];
    foreach ( (array) ($decoded['conditions'] ?? []) as $condition ) {
        if ( ! is_array($condition) ) {
            continue;
        }
        $when = estandar_forms_engine_normalize_when((array) ($condition['when'] ?? []));
        if ( $when === [] ) {
            continue;
        }
        $then_actions = estandar_forms_engine_normalize_actions((array) ($condition['then'] ?? []));
        $else_actions = estandar_forms_engine_normalize_actions((array) ($condition['else'] ?? []));
        if ( $then_actions === [] && $else_actions === [] ) {
            continue;
        }
        $normalized_condition = ['when' => $when, 'then' => $then_actions, 'else' => $else_actions];
        $condition_id = sanitize_key((string) ($condition['id'] ?? ''));
        if ( $condition_id !== '' ) {
            $normalized_condition['id'] = $condition_id;
        }
        $conditions[] = $normalized_condition;
    }
    if ( $conditions !== [] ) {
        $config['conditions'] = $conditions;
    }

    $validators = [];
    foreach ( (array) ($decoded['validators'] ?? []) as $validator ) {
        if ( ! is_array($validator) ) {
            continue;
        }
        $field = sanitize_key((string) ($validator['field'] ?? ''));
        if ( $field === '' ) {
            continue;
        }
        $normalized_validator = ['field' => $field];
        $rule = sanitize_key((string) ($validator['rule'] ?? ''));
        if ( $rule !== '' ) {
            $normalized_validator['rule'] = $rule;
        }
        if ( ! empty($validator['message']) && is_string($validator['message']) ) {
            $normalized_validator['message'] = sanitize_text_field($validator['message']);
        }
        $validators[] = $normalized_validator;
    }
    if ( $validators !== [] ) {
        $config['validators'] = $validators;
    }

    return $config;
}

function estandar_forms_engine_normalize_when(array $when): array {
    $field = sanitize_key((string) ($when['field'] ?? ''));
    $operator = sanitize_key((string) ($when['operator'] ?? 'equals'));
    $allowed = ['equals', 'not_equals', 'contains', 'not_empty', 'empty', 'in', 'not_in'];

    if ( $field === '' || ! in_array($operator, $allowed, true) ) {
        return [];
    }

    $value = $when['value'] ?? '';
    if ( in_array($operator, ['in', 'not_in'], true) ) {
        $value = array_values(array_filter(array_map(
            static fn($item) => sanitize_text_field((string) $item),
            is_array($value) ? $value : [$value]
        ), static fn($item) => $item !== ''));
    } else {
        $value = sanitize_text_field((string) $value);
    }

    return ['field' => $field, 'operator' => $operator, 'value' => $value];
}

function estandar_forms_engine_normalize_actions(array $actions): array {
    $allowed = ['show', 'hide', 'clear', 'require', 'optional', 'set_lead_quality', 'set_lead_reason'];
    $normalized = [];

    foreach ( $actions as $action ) {
        if ( ! is_array($action) ) {
            continue;
        }
        $type = sanitize_key((string) ($action['action'] ?? ''));
        if ( ! in_array($type, $allowed, true) ) {
            continue;
        }
        $entry = ['action' => $type];
        if ( in_array($type, ['set_lead_quality', 'set_lead_reason'], true) ) {
            $entry['value'] = sanitize_text_field((string) ($action['value'] ?? ''));
        } else {
            $target = sanitize_key((string) ($action['target'] ?? ''));
            if ( $target === '' ) {
                continue;
            }
            $entry['target'] = $target;
        }
        $normalized[] = $entry;
    }

    return $normalized;
}

function estandar_forms_engine_when_matches(array $when, array $source): bool {
    $operator = (string) ($when['operator'] ?? 'equals');

    if ( in_array($operator, ['in', 'not_in'], true) ) {
        $actual = $source[(string) $when['field']] ?? '';
        if ( is_array($actual) ) {
            $actual = implode(',', array_map('strval', $actual));
        } else {
            $actual = trim((string) $actual);
        }
        $found = in_array($actual, (array) ($when['value'] ?? []), true);
        return $operator === 'in' ? $found : ! $found;
    }

    return estandar_forms_evaluate_condition($when, $source);
}

/**
 * Evalúa el motor contra los valores enviados y devuelve el estado efectivo
 * server-side. `hidden` incluye campos ocultos o limpiados por condición:
 * su valor no se valida ni se persiste (misma semántica que conditional_logic).
 */
function estandar_forms_engine_evaluate(array $engine, array $source): array {
    $state = [
        'hidden'       => [],
        'required'     => [],
        'lead_quality' => '',
        'lead_reason'  => '',
    ];

    foreach ( (array) ($engine['validators'] ?? []) as $validator ) {
        if ( ($validator['rule'] ?? '') === 'required' && ! empty($validator['field']) ) {
            $state['required'][(string) $validator['field']] = true;
        }
    }

    foreach ( (array) ($engine['conditions'] ?? []) as $condition ) {
        $branch = estandar_forms_engine_when_matches((array) ($condition['when'] ?? []), $source)
            ? (array) ($condition['then'] ?? [])
            : (array) ($condition['else'] ?? []);

        foreach ( $branch as $action ) {
            $type = (string) ($action['action'] ?? '');
            $target = (string) ($action['target'] ?? '');
            switch ( $type ) {
                case 'show':
                    unset($state['hidden'][$target]);
                    break;
                case 'hide':
                case 'clear':
                    $state['hidden'][$target] = true;
                    break;
                case 'require':
                    $state['required'][$target] = true;
                    break;
                case 'optional':
                    $state['required'][$target] = false;
                    break;
                case 'set_lead_quality':
                    // Literal: el valor configurado se respeta tal cual
                    // (sanitize_key transformaría p.ej. mayúsculas — prohibido aquí).
                    $state['lead_quality'] = sanitize_text_field((string) ($action['value'] ?? ''));
                    break;
                case 'set_lead_reason':
                    $state['lead_reason'] = sanitize_text_field((string) ($action['value'] ?? ''));
                    break;
            }
        }
    }

    return $state;
}

function estandar_forms_render_template(string $template, array $context): string {
    return (string) preg_replace_callback(
        '/{{\s*([a-zA-Z0-9_]+)\s*}}/',
        static function (array $matches) use ($context): string {
            $key = sanitize_key((string) ($matches[1] ?? ''));
            if ( $key === '' || ! array_key_exists($key, $context) ) {
                return '';
            }

            $value = $context[$key];
            if ( is_array($value) ) {
                return wp_json_encode($value);
            }

            return (string) $value;
        },
        $template
    );
}

function estandar_forms_preset_field_defaults(): array {
    return [
        'location_chile' => [
            'region' => [
                'type'        => 'select',
                'placeholder' => 'Seleccione una región',
                'required'    => true,
                'dataset'     => 'cl_regions',
            ],
            'city' => [
                'type'        => 'dependent_select',
                'placeholder' => 'Seleccione una comuna',
                'required'    => true,
                'dataset'     => 'cl_regions_communes',
                'depends_on'  => 'region',
            ],
        ],
    ];
}

function estandar_forms_apply_preset_defaults(array $field, string $type, string $name): array {
    foreach ( estandar_forms_preset_field_defaults() as $defaults ) {
        if ( empty($defaults[$name]) || ! is_array($defaults[$name]) ) {
            continue;
        }

        $field_defaults = $defaults[$name];
        if ( ! empty($field_defaults['type']) && $type !== $field_defaults['type'] ) {
            continue;
        }

        foreach ( $field_defaults as $key => $value ) {
            if ( $key === 'type' ) {
                continue;
            }
            if ( ! isset($field[$key]) ) {
                $field[$key] = $value;
            }
        }
    }

    return $field;
}

/**
 * Convierte definiciones antiguas de campos a una estructura normalizada.
 *
 * @param mixed $raw
 * @return array<int,array<string,mixed>>
 */
function estandar_forms_decode_fields($raw): array {
    if ( is_array($raw) ) {
        $decoded = $raw;
    } else {
        $json = is_string($raw) ? trim($raw) : '';
        if ( $json === '' ) {
            return [];
        }

        $decoded = json_decode($json, true);
        if ( is_string($decoded) ) {
            $decoded = json_decode($decoded, true);
        }
    }

    if ( ! is_array($decoded) ) {
        return [];
    }

    if ( isset($decoded['fields']) && is_array($decoded['fields']) ) {
        $decoded = $decoded['fields'];
    }

    $normalized = [];
    foreach ( $decoded as $field ) {
        if ( ! is_array($field) ) {
            continue;
        }

        $type = sanitize_key((string) ($field['type'] ?? $field['field_type'] ?? $field['input_type'] ?? ''));
        if ( in_array($type, ['select-cards', 'select_cards', 'card'], true) ) {
            $type = 'cards';
        }

        if ( $type === '' ) {
            if ( isset($field['html']) || isset($field['content']) ) {
                $type = 'html';
            } elseif ( isset($field['level']) || isset($field['text']) ) {
                $type = 'heading';
            } else {
                $type = 'text';
            }
        }

        $item = ['type' => $type];

        if ( in_array($type, ['heading', 'html'], true) ) {
            if ( $type === 'heading' ) {
                $item['text'] = wp_kses_post((string) ($field['text'] ?? $field['title'] ?? $field['label'] ?? ''));
                $item['level'] = sanitize_key((string) ($field['level'] ?? 'h3'));
                $item['align'] = sanitize_key((string) ($field['align'] ?? 'left'));
            } else {
                $item['html'] = wp_kses_post((string) ($field['html'] ?? $field['content'] ?? $field['text'] ?? ''));
            }
            $normalized[] = $item;
            continue;
        }

        $name = sanitize_key((string) ($field['name'] ?? $field['key'] ?? $field['slug'] ?? $field['id'] ?? ''));
        if ( $name === '' ) {
            continue;
        }

        $field = estandar_forms_apply_preset_defaults($field, $type, $name);

        $item['name'] = $name;
        $item['label'] = sanitize_text_field((string) ($field['label'] ?? $field['title'] ?? $name));
        $item['required'] = ! empty($field['required']) || ! empty($field['obligatorio']);
        $item['error_message'] = sanitize_text_field((string) ($field['error_message'] ?? ''));
        $column_width = (string) ($field['column_width'] ?? $field['width'] ?? '3');
        $item['column_width'] = in_array($column_width, ['1', '2', '3'], true) ? $column_width : '3';
        $conditional_logic = estandar_forms_normalize_conditional_logic($field['conditional_logic'] ?? []);
        if ( ! empty($conditional_logic) ) {
            $item['conditional_logic'] = $conditional_logic;
        }

        if ( in_array($type, ['select', 'radio', 'emoji', 'cards', 'checkbox'], true) ) {
            $item['options'] = [];
            $options = $field['options'] ?? [];

            if ( is_string($options) ) {
                $lines = preg_split('/\R+/', $options);
                $options = is_array($lines) ? $lines : [];
            }

            if ( is_array($options) ) {
                foreach ( $options as $option ) {
                    if ( is_array($option) ) {
                        $value = sanitize_text_field((string) ($option['value'] ?? ''));
                        $label = sanitize_text_field((string) ($option['label'] ?? $value));
                    } else {
                        $value = sanitize_text_field((string) $option);
                        $label = $value;
                    }

                    if ( $value !== '' ) {
                        $normalized_option = [
                            'value' => $value,
                            'label' => $label,
                        ];
                        if ( $type === 'emoji' && is_array($option) ) {
                            $normalized_option['emoji'] = sanitize_text_field((string) ($option['emoji'] ?? ''));
                        }
                        if ( $type === 'cards' && is_array($option) ) {
                            $normalized_option['description'] = sanitize_text_field((string) ($option['description'] ?? ''));
                            if ( isset($option['icon']) ) {
                                $normalized_option['icon'] = sanitize_text_field((string) $option['icon']);
                            }
                        }
                        $item['options'][] = $normalized_option;
                    }
                }
            }

            // Dataset-backed select: preserve dataset key, placeholder, and drop the empty options array.
            if ( ! empty( $field['dataset'] ) ) {
                $item['dataset'] = sanitize_key( (string) $field['dataset'] );
                if ( isset( $field['placeholder'] ) && (string) $field['placeholder'] !== '' ) {
                    $item['placeholder'] = sanitize_text_field( (string) $field['placeholder'] );
                }
                unset( $item['options'] );
            }
        }

        // Atributos específicos de tipos Forms 4.0 — deben sobrevivir la normalización.
        if ( $type === 'dependent_select' ) {
            $item['depends_on']  = sanitize_key( (string) ( $field['depends_on'] ?? '' ) );
            $item['placeholder'] = sanitize_text_field( (string) ( $field['placeholder'] ?? '' ) );
            if ( ! empty( $field['dataset'] ) )    $item['dataset']     = sanitize_key( (string) $field['dataset'] );
            if ( ! empty( $field['value_key'] ) )  $item['value_key']   = sanitize_key( (string) $field['value_key'] );
            if ( ! empty( $field['label_key'] ) )  $item['label_key']   = sanitize_key( (string) $field['label_key'] );
            if ( ! empty( $field['parent_key'] ) ) $item['parent_key']  = sanitize_key( (string) $field['parent_key'] );
        }

        if ( $type === 'project_select' ) {
            $item['placeholder'] = sanitize_text_field( (string) ( $field['placeholder'] ?? '' ) );
        }

        if ( $type === 'rating' ) {
            $max = absint( $field['max'] ?? 5 );
            $item['max'] = in_array($max, [3, 4, 5, 7, 10], true) ? $max : 5;
        }

        if ( $type === 'matrix' ) {
            $item['scale']       = absint( $field['scale'] ?? 7 );
            $item['label_min']   = sanitize_text_field( (string) ( $field['label_min'] ?? '' ) );
            $item['label_max']   = sanitize_text_field( (string) ( $field['label_max'] ?? '' ) );
            $item['column_labels'] = [];
            if ( is_array( $field['column_labels'] ?? null ) ) {
                foreach ( $field['column_labels'] as $k => $v ) {
                    $item['column_labels'][ absint( $k ) ] = sanitize_text_field( (string) $v );
                }
            }
            $item['rows'] = [];
            if ( is_array( $field['rows'] ?? null ) ) {
                foreach ( $field['rows'] as $row ) {
                    if ( is_array( $row ) && ! empty( $row['id'] ) ) {
                        $item['rows'][] = [
                            'id'    => sanitize_key( (string) $row['id'] ),
                            'label' => sanitize_text_field( (string) ( $row['label'] ?? '' ) ),
                        ];
                    }
                }
            }
        }

        if ( $type === 'checkbox' ) {
            $item['allow_other'] = ! empty( $field['allow_other'] );
        }

        $normalized[] = $item;
    }

    return $normalized;
}

/**
 * Brand color for email templates and public surfaces.
 * Overridable via filter so themes and mu-plugins can set a custom color
 * without modifying plugin code.
 */
function estandar_forms_brand_color(): string {
    return (string) apply_filters( 'estandar_forms_brand_color', '#0069ff' );
}

function estandar_forms_telemetry_event(string $severity, string $code, string $message, array $context = []): void {
    try {
        $code = strtoupper(preg_replace('/[^A-Z0-9_]/', '', strtoupper($code)));
        do_action('estandar_telemetry_event', [
            'source_plugin' => 'estandar-forms',
            'type'          => 'business_event',
            'severity'      => sanitize_key($severity),
            'code'          => $code,
            'message'       => sanitize_text_field($message),
            'context'       => $context,
        ]);
    } catch (Throwable $e) {
        return;
    }
}

/**
 * Parsea el context_schema del formulario y devuelve el routing context normalizado.
 *
 * Modos soportados (Routing V2):
 *   none             — sin routing
 *   fixed_project    — proyecto único, slug fijo
 *   project_selector — N proyectos del Registry; selector generado por el sistema
 *
 * @param mixed $raw  JSON string o array.
 * @return array{
 *   mode: string,
 *   project_slug: string,
 *   project_name: string,
 *   project_area: string,
 *   intent: string,
 *   selector_projects: list<array{slug:string,label:string}>
 * }
 */
function estandar_forms_routing_context($raw): array {
    $data = is_array($raw) ? $raw : json_decode((string) $raw, true);
    $data = is_array($data) ? $data : [];
    $routing = isset($data['routing']) && is_array($data['routing']) ? $data['routing'] : $data;

    $mode = sanitize_key((string) ($routing['mode'] ?? $routing['routing_mode'] ?? 'none'));
    // Migrate legacy user_selector → project_selector at read time (no write-side migration needed)
    if ( $mode === 'user_selector' ) {
        $mode = 'project_selector';
    }
    if ( ! in_array($mode, ['none', 'fixed_project', 'project_selector'], true) ) {
        $mode = 'none';
    }

	$selector_projects = [];
	if ( $mode === 'project_selector' ) {
		$registry_index = [];
		if ( function_exists('estandar_forms_project_registry') ) {
			foreach ( estandar_forms_project_registry() as $project ) {
				$registry_slug = sanitize_title(remove_accents((string) ($project['project_slug'] ?? '')));
				if ( $registry_slug !== '' ) {
					$registry_index[$registry_slug] = sanitize_text_field((string) ($project['name'] ?? $registry_slug));
				}
			}
		}
		$raw_projects = isset($routing['projects']) && is_array($routing['projects'])
			? $routing['projects']
			: [];
		foreach ( $raw_projects as $p ) {
			if ( ! is_array($p) ) continue;
			$slug  = sanitize_title(remove_accents((string) ($p['slug']  ?? '')));
			if ( $slug !== '' ) {
				$label = $registry_index[$slug] ?? $slug;
				$selector_projects[] = ['slug' => $slug, 'label' => $label];
			}
		}
	}

    return [
        'mode'              => $mode,
        'project_slug'      => sanitize_title(remove_accents((string) ($routing['project_slug'] ?? ''))),
        'project_name'      => sanitize_text_field((string) ($routing['project_name'] ?? '')),
        'project_area'      => sanitize_text_field((string) ($routing['project_area'] ?? '')),
        'intent'            => sanitize_key((string) ($routing['intent'] ?? '')),
        'selector_projects' => $selector_projects,
    ];
}

function estandar_forms_current_client_id(): string {
    if ( function_exists('estandar_core_get_client_slug') ) {
        return sanitize_key(sanitize_title(remove_accents((string) estandar_core_get_client_slug())));
    }

    return '';
}

function estandar_forms_project_registry(): array {
    if ( class_exists('Estandar_Core_Project_Registry') ) {
        $client_id = estandar_forms_current_client_id();
        $projects = ( $client_id !== '' && method_exists('Estandar_Core_Project_Registry', 'get_all_for_client') )
            ? Estandar_Core_Project_Registry::get_all_for_client($client_id)
            : [];

        $out = [];
        foreach ( $projects as $project ) {
            $slug = sanitize_title(remove_accents((string) ($project['project_id'] ?? '')));
            if ( $slug !== '' ) {
                $out[] = [
                    'project_slug' => $slug,
                    'name'         => sanitize_text_field((string) ($project['label'] ?? $slug)),
                    'city'         => sanitize_text_field((string) ($project['city'] ?? '')),
                ];
            }
        }
        return $out;
    }

    if ( class_exists('Estandar_Leads_Projects') ) {
        return Estandar_Leads_Projects::registry();
    }

    return [];
}

function estandar_forms_project_belongs_to_current_client(string $project_slug): bool {
    $project_slug = sanitize_title(remove_accents($project_slug));
    if ( $project_slug === '' ) {
        return false;
    }

    if ( class_exists('Estandar_Core_Project_Registry') && method_exists('Estandar_Core_Project_Registry', 'project_belongs_to_client') ) {
        $client_id = estandar_forms_current_client_id();
        return $client_id !== '' && Estandar_Core_Project_Registry::project_belongs_to_client($project_slug, $client_id);
    }

    foreach ( estandar_forms_project_registry() as $project ) {
        if ( (string) ($project['project_slug'] ?? '') === $project_slug ) {
            return true;
        }
    }

    return false;
}

function estandar_forms_project_operator_preview(string $project_slug): array {
    $project_slug = class_exists('Estandar_Leads_Projects')
        ? Estandar_Leads_Projects::resolve_slug($project_slug)
        : sanitize_title(remove_accents($project_slug));

    $preview = [
        'project_slug'  => $project_slug,
        'operator_name' => '',
        'operator_email'=> '',
        'status'        => $project_slug !== '' ? 'missing_operator' : 'missing_project',
    ];

    if ( $project_slug === '' ) {
        return $preview;
    }

    $operator = class_exists('Estandar_Leads_Operator_Resolver')
        ? Estandar_Leads_Operator_Resolver::first_for_project($project_slug, array(
            'require_email' => true,
            'include_fallback' => false,
        ))
        : null;

    if ( $operator ) {
        $preview['operator_name'] = sanitize_text_field((string) $operator->name);
        $preview['operator_email'] = sanitize_email((string) $operator->email);
        $preview['status'] = $preview['operator_email'] !== '' ? 'ok' : 'missing_email';
    }

    return $preview;
}

/* =====================================================================
   MS-1 HIGIENE — guards de captura y configuración segura
   ===================================================================== */

/**
 * ¿El form_type se gestiona por el pipeline síncrono estándar de submit?
 * (R3 · ADR 0034): evita el "éxito silencioso" para tipos sin pipeline.
 * 'general' es sinónimo legacy de 'lead' (starter form). Los tipos
 * conversacionales (survey y futuros) usan el runtime AJAX, no admin-post.
 */
function estandar_forms_is_standard_submit_type( string $form_type ): bool {
    return in_array(
        sanitize_key( $form_type ),
        ['lead', 'lead_capture', 'general', 'experience', 'incident', 'email_direct'],
        true
    );
}

/**
 * Fusión determinística de survey_config (R9 · ADR 0034, patrón merge_config_json).
 * Preserva claves desconocidas existentes; actualiza solo las claves conocidas
 * del POST. Nunca reconstruye la config desde un set fijo de claves.
 *
 * @param string $current_raw JSON actual ('' o inválido → []).
 * @param array  $known       Claves conocidas ya sanitizadas del POST.
 */
function estandar_forms_merge_survey_config( string $current_raw, array $known ): array {
    $current = json_decode( $current_raw, true );
    $current = is_array( $current ) ? $current : [];
    foreach ( $known as $key => $value ) {
        $key = sanitize_key( (string) $key );
        if ( $key !== '' ) {
            $current[ $key ] = $value;
        }
    }
    return $current;
}
