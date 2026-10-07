<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * CONVERSATION_ENGINE_V1 — Contrato del motor conversacional (ADR 0034).
 *
 * Abstracción arquitectónica principal para todas las experiencias conversacionales
 * de Estandar Forms. Survey es la primera experiencia implementada; el runtime
 * (sesión, pasos, validación, branching, scoring, persistencia, eventos) pertenece
 * al motor y no a la experiencia.
 *
 * Estado MS-2: este archivo define el CONTRATO (eventos canónicos, taxonomía de
 * experiencias, normalización de `conversation_config` y fuente de config por tipo).
 * Las capacidades de runtime se activan en MS-3 (branching/scoring/completion/eventos)
 * y MS-4 (factorización). Nada se invoca en MS-2 desde el flujo existente.
 *
 * Reglas permanentes (Conocimiento Transversal Aprobado):
 *  - El motor solo EMITE eventos de dominio; las integraciones reaccionan vía
 *    Core\Destinations (adaptadores). Nunca acoplar el runtime a un destino.
 *  - Motor único de captura: la diferencia entre experiencias es configuración.
 *  - Contratos estables: no se altera form_type, survey_config, AJAX, hooks.
 */
class Estandar_Conversation_Engine {

    /* ─── Eventos de dominio canónicos (ADR 0034) ──────────────────────── */

    const EVENT_STARTED        = 'estandar_conversation_started';
    const EVENT_STEP_PRESENTED = 'estandar_conversation_step_presented';
    const EVENT_ANSWER_VALIDATED = 'estandar_answer_validated';
    const EVENT_ANSWER_SAVED   = 'estandar_answer_saved';
    const EVENT_COMPLETED      = 'estandar_conversation_completed';

    // Especializaciones de experiencia (derivadas de ConversationCompleted).
    const EVENT_SURVEY_COMPLETED      = 'estandar_survey_completed';
    const EVENT_LEAD_QUALIFIED        = 'estandar_conversation_lead_qualified';
    const EVENT_QUOTE_GENERATED       = 'estandar_quote_generated';
    const EVENT_BOOKING_REQUESTED     = 'estandar_booking_requested';
    const EVENT_POST_SALE_COMPLETED   = 'estandar_post_sale_completed';
    const EVENT_ASSESSMENT_COMPLETED  = 'estandar_assessment_completed';

    /**
     * Taxonomía de experiencias conversacionales del motor.
     * `survey` es la única implementada hoy; el resto son config del motor (futuras).
     */
    public static function conversational_form_types(): array {
        return [
            'survey',
            'lead_qualification',
            'postventa',
            'cotizacion',
            'wizard',
            'assessment',
            'quiz',
            'booking',
            'diagnostico',
            'onboarding',
        ];
    }

    public static function is_conversational( string $form_type ): bool {
        return in_array( sanitize_key( $form_type ), self::conversational_form_types(), true );
    }

    /* ─── Emisión de eventos de dominio ────────────────────────────────── */

    /**
     * Emite un evento de dominio del motor. Additivo y seguro: sin listeners es un
     * no-op. Las integraciones consumen vía adaptadores (Core\Destinations), nunca
     * dentro del runtime.
     */
    public static function emit( string $event, array $payload ): void {
        if ( function_exists( 'do_action' ) ) {
            do_action( $event, $payload );
        }
    }

    /* ─── Fuente de configuración por tipo ─────────────────────────────── */

    /**
     * Fuente canónica de la definición conversacional según form_type:
     *  - survey → survey_config (contrato existente, MS-0.5).
     *  - nuevas experiencias → conversation_config (columna aditiva, MS-2).
     */
    public static function config_for_form( object $form ): array {
        $form_type = sanitize_key( (string) ( $form->form_type ?? '' ) );
        if ( $form_type === 'survey' ) {
            $raw = (string) ( $form->survey_config ?? '' );
        } else {
            $raw = (string) ( $form->conversation_config ?? '' );
        }
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    /* ─── Normalización de conversation_config (contrato MS-2) ─────────── */

    /**
     * Valida y normaliza `conversation_config`. Devuelve [] si no es objeto config.
     * Schema canónico (FORM-JSON-SCHEMA.md · conversation_config):
     *  flow.mode = linear|branching|progressive (default linear)
     *  flow.start (string)
     *  flow.steps[] = {id, widget, fields[], next, visible_when}
     *  flow.rules[] = {from, if_answer, if_answer_in[], goto}
     *  scoring.weights{} · scoring.result_rules[] {min_score, max_score, segment, tier} · scoring.default_result{}
     *  completion_strategy.type = email|lead|request|artefacto|reporte|calendario (default email)
     *  identification = anonymous|identified|optional (default anonymous)
     */
    public static function normalize_config( $raw ): array {
        if ( is_string( $raw ) ) {
            $raw = trim( $raw );
            if ( $raw === '' ) {
                return [];
            }
            $decoded = json_decode( $raw, true );
        } elseif ( is_array( $raw ) ) {
            $decoded = $raw;
        } else {
            return [];
        }

        if ( ! is_array( $decoded ) || $decoded === [] ) {
            return [];
        }

        $config = [];

        // ── flow ────────────────────────────────────────────────────────
        $flow = [];
        if ( isset( $decoded['flow'] ) && is_array( $decoded['flow'] ) ) {
            $mode = sanitize_key( (string) ( $decoded['flow']['mode'] ?? 'linear' ) );
            if ( ! in_array( $mode, ['linear', 'branching', 'progressive'], true ) ) {
                $mode = 'linear';
            }
            $flow['mode'] = $mode;

            $start = sanitize_key( (string) ( $decoded['flow']['start'] ?? '' ) );
            if ( $start !== '' ) {
                $flow['start'] = $start;
            }

            $steps = [];
            foreach ( (array) ( $decoded['flow']['steps'] ?? [] ) as $step ) {
                if ( ! is_array( $step ) ) {
                    continue;
                }
                $id = sanitize_key( (string) ( $step['id'] ?? '' ) );
                if ( $id === '' ) {
                    continue;
                }
                $norm = [ 'id' => $id ];
                $widget = sanitize_key( (string) ( $step['widget'] ?? 'text' ) );
                if ( $widget !== '' ) {
                    $norm['widget'] = $widget;
                }
                $fields = [];
                foreach ( (array) ( $step['fields'] ?? [] ) as $f ) {
                    $f = sanitize_key( (string) $f );
                    if ( $f !== '' ) {
                        $fields[] = $f;
                    }
                }
                if ( $fields !== [] ) {
                    $norm['fields'] = $fields;
                }
                $next = sanitize_key( (string) ( $step['next'] ?? '' ) );
                if ( $next !== '' ) {
                    $norm['next'] = $next;
                }
                $gate = sanitize_key( (string) ( $step['visible_when']['step_completed'] ?? '' ) );
                if ( $gate !== '' ) {
                    $norm['visible_when'] = [ 'step_completed' => $gate ];
                }
                $steps[] = $norm;
            }
            if ( $steps !== [] ) {
                $flow['steps'] = $steps;
            }

            $rules = [];
            foreach ( (array) ( $decoded['flow']['rules'] ?? [] ) as $rule ) {
                if ( ! is_array( $rule ) ) {
                    continue;
                }
                $from = sanitize_key( (string) ( $rule['from'] ?? '' ) );
                if ( $from === '' ) {
                    continue;
                }
                $nr = [ 'from' => $from ];
                if ( isset( $rule['if_answer'] ) && is_scalar( $rule['if_answer'] ) ) {
                    $nr['if_answer'] = sanitize_text_field( (string) $rule['if_answer'] );
                }
                $in = [];
                foreach ( (array) ( $rule['if_answer_in'] ?? [] ) as $v ) {
                    if ( is_scalar( $v ) ) {
                        $v = sanitize_text_field( (string) $v );
                        if ( $v !== '' ) {
                            $in[] = $v;
                        }
                    }
                }
                if ( $in !== [] ) {
                    $nr['if_answer_in'] = $in;
                }
                $goto = sanitize_key( (string) ( $rule['goto'] ?? '__end__' ) );
                $nr['goto'] = $goto !== '' ? $goto : '__end__';
                $rules[] = $nr;
            }
            if ( $rules !== [] ) {
                $flow['rules'] = $rules;
            }
        }
        if ( $flow !== [] ) {
            $config['flow'] = $flow;
        }

        // ── scoring ─────────────────────────────────────────────────────
        if ( isset( $decoded['scoring'] ) && is_array( $decoded['scoring'] ) ) {
            $scoring = [];
            $weights = [];
            foreach ( (array) ( $decoded['scoring']['weights'] ?? [] ) as $k => $w ) {
                $k = sanitize_key( (string) $k );
                if ( $k === '' ) {
                    continue;
                }
                if ( is_numeric( $w ) ) {
                    $weights[ $k ] = (float) $w;
                } elseif ( is_array( $w ) ) {
                    // Peso por valor de elección: weights[field] = { "valor": peso }.
                    $map = [];
                    foreach ( $w as $val => $p ) {
                        if ( is_scalar( $val ) && is_numeric( $p ) ) {
                            $map[ (string) $val ] = (float) $p;
                        }
                    }
                    if ( $map !== [] ) {
                        $weights[ $k ] = $map;
                    }
                }
            }
            if ( $weights !== [] ) {
                $scoring['weights'] = $weights;
            }
            $result_rules = [];
            foreach ( (array) ( $decoded['scoring']['result_rules'] ?? [] ) as $rr ) {
                if ( ! is_array( $rr ) ) {
                    continue;
                }
                $nrr = [];
                if ( isset( $rr['min_score'] ) && is_numeric( $rr['min_score'] ) ) {
                    $nrr['min_score'] = (float) $rr['min_score'];
                }
                if ( isset( $rr['max_score'] ) && is_numeric( $rr['max_score'] ) ) {
                    $nrr['max_score'] = (float) $rr['max_score'];
                }
                $segment = sanitize_key( (string) ( $rr['segment'] ?? '' ) );
                if ( $segment !== '' ) {
                    $nrr['segment'] = $segment;
                }
                $tier = sanitize_text_field( (string) ( $rr['tier'] ?? '' ) );
                if ( $tier !== '' ) {
                    $nrr['tier'] = $tier;
                }
                if ( $nrr !== [] ) {
                    $result_rules[] = $nrr;
                }
            }
            if ( $result_rules !== [] ) {
                $scoring['result_rules'] = $result_rules;
            }
            if ( isset( $decoded['scoring']['default_result'] ) && is_array( $decoded['scoring']['default_result'] ) ) {
                $dr = [];
                $seg = sanitize_key( (string) ( $decoded['scoring']['default_result']['segment'] ?? '' ) );
                if ( $seg !== '' ) {
                    $dr['segment'] = $seg;
                }
                $tier = sanitize_text_field( (string) ( $decoded['scoring']['default_result']['tier'] ?? '' ) );
                if ( $tier !== '' ) {
                    $dr['tier'] = $tier;
                }
                if ( $dr !== [] ) {
                    $scoring['default_result'] = $dr;
                }
            }
            if ( $scoring !== [] ) {
                $config['scoring'] = $scoring;
            }
        }

        // ── completion_strategy ─────────────────────────────────────────
        $completion = [];
        $cs = $decoded['completion_strategy'] ?? [];
        if ( is_array( $cs ) ) {
            $type = sanitize_key( (string) ( $cs['type'] ?? 'email' ) );
            if ( ! in_array( $type, ['email', 'lead', 'request', 'artefacto', 'reporte', 'calendario'], true ) ) {
                $type = 'email';
            }
            $completion['type'] = $type;
            foreach ( ['lead_pipeline', 'notify_emails'] as $bool_key ) {
                if ( isset( $cs[ $bool_key ] ) ) {
                    $completion[ $bool_key ] = ! empty( $cs[ $bool_key ] );
                }
            }
        }
        if ( $completion !== [] ) {
            $config['completion_strategy'] = $completion;
        }

        // ── identification ──────────────────────────────────────────────
        $id = sanitize_key( (string) ( $decoded['identification'] ?? 'anonymous' ) );
        if ( ! in_array( $id, ['anonymous', 'identified', 'optional'], true ) ) {
            $id = 'anonymous';
        }
        $config['identification'] = $id;

        return $config;
    }

    /* ─── Contrato de runtime (implementado en MS-3/MS-4) ─────────────── */

    /**
     * CONTRATO (no operativo en MS-2): resuelve el siguiente paso según config flow.
     * Reglas evaluadas top-down; primera coincidencia gana. lineal → next explícito
     * o siguiente índice. Implementación efectiva en MS-3 (branching sobre Survey).
     */
    public static function resolve_next_step( array $config, string $from_step, string $answer, int $from_index, int $total ): ?string {
        $flow = $config['flow'] ?? [];
        foreach ( (array) ( $flow['rules'] ?? [] ) as $rule ) {
            if ( ( $rule['from'] ?? '' ) !== $from_step ) {
                continue;
            }
            if ( isset( $rule['if_answer'] ) && $rule['if_answer'] !== $answer ) {
                continue;
            }
            if ( isset( $rule['if_answer_in'] ) && ! in_array( $answer, (array) $rule['if_answer_in'], true ) ) {
                continue;
            }
            $goto = (string) ( $rule['goto'] ?? '__end__' );
            return $goto === '__end__' ? null : $goto;
        }
        // Sin regla: flujo lineal → siguiente paso del array.
        $steps = array_values( (array) ( $flow['steps'] ?? [] ) );
        $next_index = $from_index + 1;
        if ( isset( $steps[ $next_index ]['id'] ) ) {
            return (string) $steps[ $next_index ]['id'];
        }
        return null;
    }

    /**
     * CONTRATO (implementado en MS-3): calcula el resultado de scoring de una sesión.
     * score = Σ(weight[step_field] × value) · resultado = primera regla cuyo rango
     * incluye el score, o default_result. Devuelve array {score, segment, tier} o [].
     */
    public static function evaluate_scoring( array $config, array $answers ): array {
        $scoring = $config['scoring'] ?? [];
        if ( $scoring === [] || ! isset( $scoring['weights'] ) ) {
            return [];
        }
        $score = 0.0;
        foreach ( (array) $scoring['weights'] as $field => $weight ) {
            $value = $answers[ $field ] ?? null;
            if ( is_array( $weight ) ) {
                // Peso por valor de elección: weights[field] = { "valor": peso } (QA MS-5).
                $score += (float) ( $weight[ (string) $value ] ?? 0 );
            } elseif ( is_numeric( $value ) ) {
                $score += (float) $weight * (float) $value;
            } elseif ( $value === true || $value === 'true' || $value === '1' ) {
                $score += (float) $weight;
            }
        }
        $segment = (string) ( $scoring['default_result']['segment'] ?? '' );
        $tier    = (string) ( $scoring['default_result']['tier'] ?? '' );
        foreach ( (array) ( $scoring['result_rules'] ?? [] ) as $rr ) {
            if ( isset( $rr['min_score'] ) && $score < (float) $rr['min_score'] ) {
                continue;
            }
            if ( isset( $rr['max_score'] ) && $score > (float) $rr['max_score'] ) {
                continue;
            }
            if ( isset( $rr['segment'] ) ) {
                $segment = (string) $rr['segment'];
            }
            if ( isset( $rr['tier'] ) ) {
                $tier = (string) $rr['tier'];
            }
            break;
        }
        return [ 'score' => $score, 'segment' => $segment, 'tier' => $tier ];
    }

    /**
     * Catálogo ÚNICO de tipos de pregunta del motor (MS-4 · T2 · ADR 0034).
     * Fuente canónica para el runtime (handler), el builder PHP y el linter.
     * La UI admin JS mantiene su propia definición visual pero debe reflejar este set.
     */
    public static function question_type_catalog(): array {
        return [ 'nps', 'rating', 'matrix', 'emoji', 'cards', 'checkbox', 'text', 'select', 'radio', 'textarea' ];
    }

    public static function is_supported_question_type( string $type ): bool {
        return in_array( sanitize_key( $type ), self::question_type_catalog(), true );
    }

    /* ─── Estado de sesión (MS-4b · T4 · ADR 0034) ─────────────────────── */

    // Valores de status en estandar_form_entries — CONTRATO (no cambiar).
    // 'survey_*' se conserva por compatibilidad; los labels generales se añaden.
    const SESSION_STATUS_STARTED    = 'active';
    const SESSION_STATUS_COMPLETED  = 'survey_completed';
    const SESSION_STATUS_ABANDONED  = 'survey_abandoned';

    /**
     * Etiqueta genérica de un estado de sesión (runtime → experiencia).
     */
    public static function session_status_label( string $status ): string {
        if ( $status === self::SESSION_STATUS_COMPLETED ) {
            return 'completed';
        }
        if ( $status === self::SESSION_STATUS_ABANDONED ) {
            return 'abandoned';
        }
        return 'started';
    }

    /* ─── Validación y sanitización de respuestas (MS-4b · ADR 0034) ───── */

    /**
     * Valida y sanitiza el valor de una respuesta según el tipo de pregunta.
     * Lógica genérica del RUNTIME (no específica de Survey). Devuelve
     * ['valid', 'value', 'error'] — el error se usa si !valid.
     *
     * @param string $type  Tipo de pregunta (catálogo del motor).
     * @param string $value Valor bruto ya sanitizado básicamente.
     * @param array  $field Definición de la pregunta (p. ej. ['max'=>5] para rating).
     */
    public static function validate_answer_value( string $type, string $value, array $field = [] ): array {
        $type = self::is_supported_question_type( $type ) ? $type : 'text';

        switch ( $type ) {
            case 'checkbox':
                $decoded = json_decode( $value, true );
                if ( is_array( $decoded ) ) {
                    $checked   = array_map( 'sanitize_text_field', $decoded );
                    $other_val = sanitize_text_field( (string) ( $decoded['_other_text'] ?? '' ) );
                    unset( $checked['_other_text'] );
                    $sanitized = [ 'checked' => array_values( $checked ) ];
                    if ( $other_val !== '' ) {
                        $sanitized['other_text'] = $other_val;
                    }
                    return [ 'valid' => true, 'value' => wp_json_encode( $sanitized, JSON_UNESCAPED_UNICODE ), 'error' => '' ];
                }
                return [ 'valid' => true, 'value' => sanitize_text_field( $value ), 'error' => '' ];

            case 'nps':
                if ( $value !== '' ) {
                    $v = (int) $value;
                    if ( $v < 0 || $v > 7 ) {
                        return [ 'valid' => false, 'value' => $value, 'error' => 'Valor NPS fuera de rango (0-7).' ];
                    }
                }
                return [ 'valid' => true, 'value' => $value, 'error' => '' ];

            case 'rating':
                if ( $value !== '' ) {
                    $v   = (int) $value;
                    $max = (int) ( $field['max'] ?? 5 );
                    if ( $v < 1 || $v > $max ) {
                        return [ 'valid' => false, 'value' => $value, 'error' => "Valor de estrellas fuera de rango (1-{$max})." ];
                    }
                }
                return [ 'valid' => true, 'value' => $value, 'error' => '' ];

            case 'matrix':
                // MS-6 (ADR 0034): valida JSON {row_id: score} — cada score en 1..scale.
                if ( $value !== '' ) {
                    $decoded = json_decode( $value, true );
                    if ( is_array( $decoded ) ) {
                        $scale = (int) ( $field['scale'] ?? 7 );
                        foreach ( $decoded as $score ) {
                            $s = (int) $score;
                            if ( $s < 1 || $s > $scale ) {
                                return [ 'valid' => false, 'value' => $value, 'error' => "Valor de evaluación fuera de rango (1-{$scale})." ];
                            }
                        }
                    }
                }
                return [ 'valid' => true, 'value' => $value, 'error' => '' ];

            default:
                return [ 'valid' => true, 'value' => $value, 'error' => '' ];
        }
    }

    /* ─── CAPTURE (MS-4c · ADR 0034) — capa única de persistencia de sesión ──
       Primitive writes a estandar_form_entries / estandar_survey_responses.
       Las experiencias construyen su payload y DELEGAN aquí; nunca escriben
       directamente. Mecanismo único de captura (P4). */

    public static function capture_session_create( int $form_id, string $data_json ): int {
        global $wpdb;
        $ok = $wpdb->insert(
            $wpdb->prefix . 'estandar_form_entries',
            [ 'form_id' => $form_id, 'data_json' => $data_json, 'status' => self::SESSION_STATUS_STARTED ],
            [ '%d', '%s', '%s' ]
        );
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    public static function capture_session_update_data( int $entry_id, string $data_json ): bool {
        global $wpdb;
        return false !== $wpdb->update(
            $wpdb->prefix . 'estandar_form_entries',
            [ 'data_json' => $data_json ],
            [ 'id' => $entry_id ],
            [ '%s' ],
            [ '%d' ]
        );
    }

    public static function capture_session_set_status( int $entry_id, string $status, int $form_id = 0 ): bool {
        global $wpdb;
        $where     = [ 'id' => $entry_id ];
        $where_fmt = [ '%d' ];
        if ( $form_id > 0 ) {
            $where['form_id'] = $form_id;
            $where_fmt[]      = '%d';
        }
        return false !== $wpdb->update(
            $wpdb->prefix . 'estandar_form_entries',
            [ 'status' => $status ],
            $where,
            [ '%s' ],
            $where_fmt
        );
    }

    public static function capture_session_complete( int $entry_id, string $data_json ): bool {
        global $wpdb;
        return false !== $wpdb->update(
            $wpdb->prefix . 'estandar_form_entries',
            [ 'data_json' => $data_json, 'status' => self::SESSION_STATUS_COMPLETED ],
            [ 'id' => $entry_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
    }

    public static function capture_response_save( array $response, array $update_where = [] ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'estandar_survey_responses';
        if ( $update_where !== [] ) {
            return false !== $wpdb->update(
                $table,
                $response,
                $update_where,
                [ '%s', '%s', '%s', '%s', '%d' ],
                [ '%s', '%d' ]
            );
        }
        return (bool) $wpdb->insert(
            $table,
            $response,
            [ '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d' ]
        );
    }

    public static function capture_response_link_entry( int $entry_id, string $session_token, int $form_id ): bool {
        global $wpdb;
        return false !== $wpdb->update(
            $wpdb->prefix . 'estandar_survey_responses',
            [ 'entry_id' => $entry_id ],
            [ 'session_token' => $session_token, 'form_id' => $form_id ],
            [ '%d' ],
            [ '%s', '%d' ]
        );
    }

    /* ─── MS-3 · Capacidades sobre Survey (branching, scoring, completion) ── */

    /**
     * Mapa de branching genérico (MS-4d · ADR 0034): traduce flow.rules
     * (referencias por nombre de campo o índice) a un mapa por índice de paso
     * consumible por el frontend. Sin reglas → [] (comportamiento lineal).
     * Reglas irresolubles se ignoran (fallback lineal). `catchall` = regla sin
     * if_answer (primera gana).
     *
     * @return array<int, array{rules: array<int, array{answers: string[], next: int|string}>, catchall: int|string|null}>
     */
    public static function flow_map( array $config, array $fields ): array {
        $flow = $config['flow'] ?? [];
        if ( empty( $flow['rules'] ) ) {
            return [];
        }

        $by_name = [];
        foreach ( $fields as $i => $f ) {
            if ( is_array( $f ) ) {
                $name = sanitize_key( (string) ( $f['name'] ?? '' ) );
                if ( $name !== '' ) {
                    $by_name[ $name ] = (int) $i;
                }
            }
        }
        $resolve = static function ( string $ref ) use ( $by_name, $fields ) {
            if ( $ref === '' || $ref === '__end__' ) {
                return null;
            }
            if ( is_numeric( $ref ) && isset( $fields[ (int) $ref ] ) ) {
                return (int) $ref;
            }
            return $by_name[ $ref ] ?? null;
        };

        $map = [];
        foreach ( (array) $flow['rules'] as $rule ) {
            if ( ! is_array( $rule ) ) {
                continue;
            }
            $from = sanitize_key( (string) ( $rule['from'] ?? '' ) );
            $from_idx = $resolve( $from );
            if ( $from_idx === null ) {
                continue;
            }
            $answers = [];
            if ( isset( $rule['if_answer'] ) && is_scalar( $rule['if_answer'] ) ) {
                $a = sanitize_text_field( (string) $rule['if_answer'] );
                if ( $a !== '' ) {
                    $answers[] = $a;
                }
            }
            foreach ( (array) ( $rule['if_answer_in'] ?? [] ) as $v ) {
                if ( is_scalar( $v ) ) {
                    $a = sanitize_text_field( (string) $v );
                    if ( $a !== '' ) {
                        $answers[] = $a;
                    }
                }
            }

            $goto_ref = sanitize_key( (string) ( $rule['goto'] ?? '__end__' ) );
            if ( $goto_ref === '' || $goto_ref === '__end__' ) {
                $next = 'end';
            } else {
                $goto_idx = $resolve( $goto_ref );
                if ( $goto_idx === null ) {
                    continue; // goto irresoluble → ignorar la regla (fallback lineal).
                }
                $next = $goto_idx;
            }

            if ( $answers === [] ) {
                if ( ! isset( $map[ $from_idx ]['catchall'] ) ) {
                    $map[ $from_idx ]['catchall'] = $next;
                }
            } else {
                $map[ $from_idx ]['rules'][] = [
                    'answers' => array_values( array_unique( $answers ) ),
                    'next'    => $next,
                ];
            }
        }

        $out = [];
        foreach ( $map as $idx => $data ) {
            $out[ (int) $idx ] = [
                'rules'    => $data['rules'] ?? [],
                'catchall' => $data['catchall'] ?? null,
            ];
        }
        return $out;
    }

    /**
     * Alias BC de flow_map() (MS-4d). Se conserva por compatibilidad interna
     * (survey-render); el nombre canónico es flow_map (sin acoplamiento a Survey).
     */
    public static function survey_flow_map( array $config, array $fields ): array {
        return self::flow_map( $config, $fields );
    }

    /**
     * Construye answers por nombre de campo desde las respuestas persistidas
     * (estandar_survey_responses) y la definición de preguntas (fields_json).
     */
    public static function build_answers_from_responses( array $responses, array $fields ): array {
        $by_index = [];
        foreach ( $fields as $i => $f ) {
            if ( is_array( $f ) ) {
                $name = sanitize_key( (string) ( $f['name'] ?? '' ) );
                if ( $name !== '' ) {
                    $by_index[ (int) $i ] = $name;
                }
            }
        }
        $answers = [];
        foreach ( $responses as $r ) {
            if ( ! is_array( $r ) ) {
                continue;
            }
            $idx = (int) ( $r['question_index'] ?? -1 );
            $name = $by_index[ $idx ] ?? '';
            if ( $name === '' ) {
                continue;
            }
            $answers[ $name ] = self::normalize_answer_value( (string) ( $r['answer_value'] ?? '' ) );
        }
        return $answers;
    }

    /**
     * Valor de respuesta normalizado (JSON compuesto se deja como JSON string).
     */
    public static function normalize_answer_value( string $value ): string {
        $v = trim( $value );
        if ( $v !== '' && $v[0] === '{' ) {
            $decoded = json_decode( $v, true );
            return is_array( $decoded ) ? (string) wp_json_encode( $decoded ) : $v;
        }
        return $v;
    }

    /**
     * Resultado de finalización de una sesión: scoring (si config lo define) y
     * estrategia de finalización (email por defecto; lead/request/… opt-in).
     *
     * @return array{scoring: array|null, completion_type: string}
     */
    public static function completion_result( array $engine_cfg, array $answers ): array {
        $completion = $engine_cfg['completion_strategy'] ?? [];
        $type = (string) ( $completion['type'] ?? 'email' );
        $result = [
            'scoring'         => null,
            'completion_type' => in_array( $type, ['email', 'lead', 'request', 'artefacto', 'reporte', 'calendario'], true ) ? $type : 'email',
        ];
        if ( ! empty( $engine_cfg['scoring'] ) ) {
            $scored = self::evaluate_scoring( $engine_cfg, $answers );
            if ( $scored !== [] ) {
                $result['scoring'] = $scored;
            }
        }
        return $result;
    }
}
