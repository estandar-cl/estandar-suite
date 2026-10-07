<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * EXPERIENCE_ENGINE_V1
 *
 * Experiences live as rows in estandar_forms with form_type='experience'.
 * The definition JSON lives in experience_config_json.
 * Answers persist in es_leads.experience_data_json (keyed by slug+version).
 * Audit trail in es_lead_events (experience_started / experience_completed).
 * Analytics in ea_events.
 *
 * Three trigger types declared; only after_form_submit implemented in V1.
 * Lead score / lead segment are reserved V1 fields for OCI/CAPI readiness.
 */
class Estandar_Experience_Engine {
    public static function after_form_submit_snapshot( $form, array $data ): array {
        $def = self::definition_from_form($form);
        $slug = sanitize_key((string) ($def['experience_slug'] ?? ($def['slug'] ?? ($form->slug ?? ''))));
        if ($slug === '') {
            $slug = 'real_estate_prequalification_v1';
        }

        $answers = self::answers_from_data($data);

        return [
            'experience_slug' => $slug,
            'started_at'      => gmdate('c'),
            'completed_at'    => gmdate('c'),
            'answers'         => $answers,
            'result'          => self::resolve_result($slug, $answers, $def),
        ];
    }

    public static function definition_from_form( $form ): array {
        $raw = (string) ($form->experience_config_json ?? '');
        $def = $raw !== '' ? json_decode($raw, true) : [];
        if (is_array($def) && !empty($def)) {
            return $def;
        }

        $slug = sanitize_key((string) ($form->slug ?? ''));
        return $slug === 'real_estate_prequalification_v1' ? self::real_estate_prequalification_v1_config() : [];
    }

    public static function real_estate_prequalification_v1_config(): array {
        return [
            'experience_slug' => 'real_estate_prequalification_v1',
            'version' => '1',
            'trigger' => ['type' => 'after_form_submit'],
            'questions' => [
                'subsidio',
                'tipo_subsidio',
                'credito_aprobado',
                'rango_ingresos',
                'codeudor',
            ],
            'result_rules' => [
                [
                    'if' => ['subsidio' => true, 'tipo_subsidio' => 'ds19'],
                    'result' => ['segment' => 'subsidio_ds19', 'priority' => 'high'],
                ],
                [
                    'if' => ['subsidio' => true, 'tipo_subsidio' => 'ds49'],
                    'result' => ['segment' => 'subsidio_ds49', 'priority' => 'medium'],
                ],
            ],
            'default_result' => ['segment' => 'sin_subsidio', 'priority' => 'normal'],
        ];
    }

    private static function answers_from_data(array $data): array {
        return [
            'subsidio'          => self::bool_answer($data['subsidio'] ?? null),
            'tipo_subsidio'     => sanitize_key((string) ($data['tipo_subsidio'] ?? ($data['que_subsidio'] ?? ''))),
            'credito_aprobado'  => self::bool_answer($data['credito_aprobado'] ?? ($data['credito'] ?? null)),
            'rango_ingresos'    => sanitize_key((string) ($data['rango_ingresos'] ?? ($data['ingresos'] ?? ''))),
            'codeudor'          => self::bool_answer($data['codeudor'] ?? null),
        ];
    }

    private static function bool_answer($value): bool {
        if (is_bool($value)) {
            return $value;
        }
        $value = sanitize_key((string) $value);
        return in_array($value, ['1', 'si', 's', 'yes', 'true'], true);
    }

    private static function resolve_result(string $slug, array $answers, array $def): array {
        foreach ((array) ($def['result_rules'] ?? []) as $rule) {
            $match = true;
            foreach ((array) ($rule['if'] ?? []) as $key => $expected) {
                if (($answers[$key] ?? null) !== $expected) {
                    $match = false;
                    break;
                }
            }
            if ($match && is_array($rule['result'] ?? null)) {
                return self::sanitize_result($rule['result']);
            }
        }

        if (is_array($def['default_result'] ?? null)) {
            return self::sanitize_result($def['default_result']);
        }

        if ($slug === 'real_estate_prequalification_v1' && !empty($answers['subsidio']) && $answers['tipo_subsidio'] !== '') {
            return ['segment' => 'subsidio_' . sanitize_key($answers['tipo_subsidio']), 'priority' => 'high'];
        }

        return ['segment' => 'unqualified', 'priority' => 'normal'];
    }

    private static function sanitize_result(array $result): array {
        return [
            'segment'  => sanitize_key((string) ($result['segment'] ?? '')),
            'priority' => sanitize_key((string) ($result['priority'] ?? 'normal')),
        ];
    }

    // ─── Definition CRUD ──────────────────────────────────────────────────────

    public static function get_definition( string $slug ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT experience_config_json FROM {$wpdb->prefix}estandar_forms
             WHERE form_type = 'experience' AND slug = %s LIMIT 1",
            $slug
        ) );
        if ( ! $row || empty( $row->experience_config_json ) ) {
            return null;
        }
        $def = json_decode( $row->experience_config_json, true );
        return is_array( $def ) ? $def : null;
    }

    public static function get_all(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT id, name, slug, experience_config_json, created_at
             FROM {$wpdb->prefix}estandar_forms
             WHERE form_type = 'experience'
             ORDER BY id ASC"
        );
        return is_array( $rows ) ? $rows : [];
    }

    public static function get_by_id( int $id ): ?object {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}estandar_forms WHERE id = %d AND form_type = 'experience' LIMIT 1",
            $id
        ) ) ?: null;
    }

    public static function save( array $data ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'estandar_forms';
        $now   = gmdate( 'Y-m-d H:i:s' );

        $row = [
            'name'                  => sanitize_text_field( $data['name'] ?? '' ),
            'slug'                  => sanitize_key( $data['slug'] ?? '' ),
            'form_type'             => 'experience',
            'experience_config_json'=> wp_json_encode( $data['config'] ?? [] ),
            'updated_at'            => $now,
        ];

        if ( ! empty( $data['id'] ) ) {
            $wpdb->update( $table, $row, [ 'id' => (int) $data['id'] ], null, [ '%d' ] );
            return (int) $data['id'];
        }

        $row['created_at'] = $now;
        $wpdb->insert( $table, $row );
        return (int) $wpdb->insert_id;
    }

    // ─── Flow resolver (conditional branches V1) ──────────────────────────────

    /**
     * Returns next step_id string, or null if experience ends.
     * Rules evaluated top-to-bottom; first match wins.
     * Operators: if_answer (exact) | if_answer_in (array) | (no condition = always)
     */
    public static function resolve_next_step( array $def, string $from_step_id, string $answer ): ?string {
        $rules = $def['flow']['rules'] ?? [];
        foreach ( $rules as $rule ) {
            if ( ( $rule['from'] ?? '' ) !== $from_step_id ) continue;

            if ( isset( $rule['if_answer'] ) && $rule['if_answer'] !== $answer ) continue;
            if ( isset( $rule['if_answer_in'] ) && ! in_array( $answer, (array) $rule['if_answer_in'], true ) ) continue;

            $goto = (string) ( $rule['goto'] ?? '__end__' );
            return $goto === '__end__' ? null : $goto;
        }
        return null;
    }

    public static function get_step( array $def, string $step_id ): ?array {
        foreach ( $def['steps'] ?? [] as $step ) {
            if ( ( $step['id'] ?? '' ) === $step_id ) return $step;
        }
        return null;
    }

    public static function get_start_step_id( array $def ): string {
        $start = $def['flow']['start'] ?? '';
        if ( $start !== '' ) return $start;
        return (string) ( $def['steps'][0]['id'] ?? '' );
    }

    public static function estimate_total_steps( array $def ): int {
        return count( $def['steps'] ?? [] );
    }

    // ─── Trigger contract (V1 = after_form_submit; popup/chatbot declared) ────

    public static function get_trigger( array $def ): array {
        return $def['trigger'] ?? [ 'type' => 'after_form_submit', 'config' => [] ];
    }

    // ─── Reserved fields for OCI/CAPI readiness ───────────────────────────────

    public static function get_lead_score( array $def ): int {
        return (int) ( $def['lead_score'] ?? 0 );
    }

    public static function get_lead_segment( array $def ): string {
        return (string) ( $def['lead_segment'] ?? '' );
    }

    // ─── Shortcode ────────────────────────────────────────────────────────────

    public static function shortcode( $atts ): string {
        $atts = shortcode_atts( [
            'slug'    => '',
            'form_id' => '',
        ], (array) $atts, 'estandar_experience' );

        $slug    = sanitize_key( (string) $atts['slug'] );
        $form_id = sanitize_key( (string) $atts['form_id'] );
        if ( $slug === '' ) return '';

        $def = self::get_definition( $slug );
        if ( ! $def ) return '';

        $nonce    = wp_create_nonce( 'estandar_experience_' . $slug );
        $ajax_url = admin_url( 'admin-ajax.php' );
        $version  = (string) ( $def['version'] ?? '1' );

        $css_file = ESTANDAR_FORMS_PATH . 'assets/css/experience.css';
        $js_file  = ESTANDAR_FORMS_PATH . 'assets/js/experience.js';

        wp_enqueue_style(
            'estandar-forms-experience',
            ESTANDAR_FORMS_URL . 'assets/css/experience.css',
            [],
            file_exists( $css_file ) ? (string) filemtime( $css_file ) : ESTANDAR_FORMS_VERSION
        );
        wp_enqueue_script(
            'estandar-forms-experience',
            ESTANDAR_FORMS_URL . 'assets/js/experience.js',
            [],
            file_exists( $js_file ) ? (string) filemtime( $js_file ) : ESTANDAR_FORMS_VERSION,
            true
        );
        wp_localize_script( 'estandar-forms-experience', 'estandarExperienceConfig', [
            'ajaxUrl' => $ajax_url,
        ] );

        return sprintf(
            '<div class="ee-mount" data-experience-slug="%s" data-experience-version="%s" data-trigger-form-id="%s" data-nonce="%s" hidden></div>',
            esc_attr( $slug ),
            esc_attr( $version ),
            esc_attr( $form_id ),
            esc_attr( $nonce )
        );
    }

    // ─── AJAX: first / next step ──────────────────────────────────────────────

    public static function ajax_step(): void {
        $slug      = sanitize_key( (string) ( $_POST['slug'] ?? '' ) );
        $from_step = sanitize_key( (string) ( $_POST['from_step'] ?? '' ) );
        $answer    = sanitize_text_field( (string) wp_unslash( $_POST['answer'] ?? '' ) );

        if ( $slug === '' ) { wp_send_json_error( [ 'error' => 'missing_slug' ], 400 ); }

        if ( ! wp_verify_nonce( (string) ( $_POST['nonce'] ?? '' ), 'estandar_experience_' . $slug ) ) {
            wp_send_json_error( [ 'error' => 'invalid_nonce' ], 403 );
        }

        $def = self::get_definition( $slug );
        if ( ! $def ) { wp_send_json_error( [ 'error' => 'not_found' ], 404 ); }

        if ( $from_step === '' ) {
            $next_id = self::get_start_step_id( $def );
        } else {
            $next_id = self::resolve_next_step( $def, $from_step, $answer );
        }

        if ( $next_id === null ) {
            wp_send_json_success( [ 'step' => null, 'is_last' => true ] );
        }

        $step = self::get_step( $def, $next_id );
        if ( ! $step ) {
            wp_send_json_success( [ 'step' => null, 'is_last' => true ] );
        }

        wp_send_json_success( [
            'step'           => $step,
            'is_last'        => false,
            'total_estimate' => self::estimate_total_steps( $def ),
        ] );
    }

    // ─── AJAX: save completed experience ──────────────────────────────────────

    public static function ajax_submit(): void {
        $slug       = sanitize_key( (string) ( $_POST['slug'] ?? '' ) );
        $session_id = sanitize_text_field( (string) wp_unslash( $_POST['session_id'] ?? '' ) );
        $lead_id    = (int) ( $_POST['lead_id'] ?? 0 );
        $sub_id     = (int) ( $_POST['submission_id'] ?? 0 );
        $answers_raw = wp_unslash( $_POST['answers'] ?? '{}' );
        $steps_raw   = wp_unslash( $_POST['steps_shown'] ?? '[]' );
        $duration    = (int) ( $_POST['duration_seconds'] ?? 0 );

        if ( $slug === '' ) { wp_send_json_error( [ 'error' => 'missing_slug' ], 400 ); }
        if ( ! wp_verify_nonce( (string) ( $_POST['nonce'] ?? '' ), 'estandar_experience_' . $slug ) ) {
            wp_send_json_error( [ 'error' => 'invalid_nonce' ], 403 );
        }

        $def = self::get_definition( $slug );
        if ( ! $def ) { wp_send_json_error( [ 'error' => 'not_found' ], 404 ); }

        $answers    = json_decode( $answers_raw, true );
        $steps_shown = json_decode( $steps_raw, true );
        if ( ! is_array( $answers ) )     $answers     = [];
        if ( ! is_array( $steps_shown ) ) $steps_shown = [];

        $version = (string) ( $def['version'] ?? '1' );

        // Resolve lead_id from session if not passed
        if ( $lead_id <= 0 && $session_id !== '' ) {
            $lead_id = self::resolve_lead_id_from_session( $session_id );
            if ( $lead_id > 0 && $sub_id <= 0 ) {
                $sub_id = self::resolve_submission_id_from_session( $session_id );
            }
        }

        // Fire hook — estandar-leads handles persistence into experience_data_json + es_lead_events
        do_action( 'estandar_experience_completed', $lead_id, $sub_id, $slug, $version, $answers, [
            'steps_shown'      => $steps_shown,
            'duration_seconds' => $duration,
            'session_id'       => $session_id,
            'lead_score'       => self::get_lead_score( $def ),
            'lead_segment'     => self::get_lead_segment( $def ),
        ] );

        wp_send_json_success( [ 'saved' => true ] );
    }

    // ─── AJAX: analytics events ───────────────────────────────────────────────

    public static function ajax_analytics(): void {
        $slug       = sanitize_key( (string) ( $_POST['experience_slug'] ?? '' ) );
        $event_type = sanitize_key( (string) ( $_POST['event_type'] ?? '' ) );
        $step_id    = sanitize_key( (string) ( $_POST['step_id'] ?? '' ) );
        $answer     = sanitize_text_field( (string) wp_unslash( $_POST['answer'] ?? '' ) );
        $session_id = sanitize_text_field( (string) wp_unslash( $_POST['session_id'] ?? '' ) );

        $allowed = [ 'experience_started', 'experience_step_answered', 'experience_completed', 'experience_abandoned' ];
        if ( ! in_array( $event_type, $allowed, true ) || $slug === '' ) {
            wp_send_json_success( [ 'logged' => false ] );
        }

        if ( ! wp_verify_nonce( (string) ( $_POST['nonce'] ?? '' ), 'estandar_experience_' . $slug ) ) {
            wp_send_json_success( [ 'logged' => false ] );
        }

        global $wpdb;
        $ea_events = $wpdb->prefix . 'ea_events';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$ea_events'" ) !== $ea_events ) {
            wp_send_json_success( [ 'logged' => false ] );
        }

        $meta = [ 'experience_slug' => $slug ];
        if ( $step_id !== '' ) $meta['step_id'] = $step_id;
        if ( $answer !== '' )  $meta['answer']   = $answer;

        $wpdb->insert( $ea_events, [
            'session_id'   => $session_id,
            'event_type'   => $event_type,
            'event_label'  => $slug,
            'page_url'     => sanitize_url( (string) wp_unslash( $_SERVER['HTTP_REFERER'] ?? '' ) ),
            'meta_json'    => wp_json_encode( $meta ),
            'occurred_at'  => gmdate( 'Y-m-d H:i:s' ),
        ] );

        wp_send_json_success( [ 'logged' => true ] );
    }

    // ─── Internal helpers ─────────────────────────────────────────────────────

    private static function resolve_lead_id_from_session( string $session_id ): int {
        global $wpdb;
        $subs = $wpdb->prefix . 'es_lead_submissions';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$subs'" ) !== $subs ) return 0;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT lead_id FROM $subs WHERE session_id = %s ORDER BY created_at DESC LIMIT 1",
            $session_id
        ) );
    }

    private static function resolve_submission_id_from_session( string $session_id ): int {
        global $wpdb;
        $subs = $wpdb->prefix . 'es_lead_submissions';
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $subs WHERE session_id = %s ORDER BY created_at DESC LIMIT 1",
            $session_id
        ) );
    }
}
