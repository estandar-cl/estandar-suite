<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Survey AJAX handler.
 *
 * Storage model (SURVEYS_STORAGE_SIMPLIFICATION_V1):
 *   Sessions   → estandar_form_entries  (data_json blob keyed by survey_session=true)
 *   Responses  → estandar_survey_responses  (one row per question answered)
 *   Events     → ea_events via EA_Events::record_single()  (funnel: view/start/q_view/answered/completed/abandoned)
 *
 * The client generates session_token (UUID v4) and sends it with every request.
 * handle_start() returns entry_id so handle_complete() can UPDATE the right row.
 */
class Estandar_Survey_Handler {

    // Contrato canónico de status en estandar_form_entries para la experiencia Survey.
    // MS-4b (ADR 0034): valores únicos definidos por el RUNTIME
    // (Estandar_Conversation_Engine::SESSION_STATUS_*). Aliases BC de la experiencia.
    const STATUS_STARTED   = Estandar_Conversation_Engine::SESSION_STATUS_STARTED;
    const STATUS_COMPLETED = Estandar_Conversation_Engine::SESSION_STATUS_COMPLETED;
    const STATUS_ABANDONED = Estandar_Conversation_Engine::SESSION_STATUS_ABANDONED;

    public static function init(): void {
        add_action( 'wp_ajax_nopriv_estandar_survey_start',    [__CLASS__, 'handle_start'] );
        add_action( 'wp_ajax_estandar_survey_start',           [__CLASS__, 'handle_start'] );
        add_action( 'wp_ajax_nopriv_estandar_survey_event',    [__CLASS__, 'handle_event'] );
        add_action( 'wp_ajax_estandar_survey_event',           [__CLASS__, 'handle_event'] );
        add_action( 'wp_ajax_nopriv_estandar_survey_answer',   [__CLASS__, 'handle_answer'] );
        add_action( 'wp_ajax_estandar_survey_answer',          [__CLASS__, 'handle_answer'] );
        add_action( 'wp_ajax_nopriv_estandar_survey_complete', [__CLASS__, 'handle_complete'] );
        add_action( 'wp_ajax_estandar_survey_complete',        [__CLASS__, 'handle_complete'] );
        add_action( 'template_redirect',                      [__CLASS__, 'handle_secure_response_route'] );
    }

    /* ─── START ────────────────────────────────────────────────────────── */

    public static function handle_start(): void {
        check_ajax_referer( 'estandar_survey_nonce', 'nonce' );

        $form_id        = absint( $_POST['form_id'] ?? 0 );
        $session_token  = sanitize_text_field( $_POST['session_token'] ?? '' );
        $total_questions = absint( $_POST['total_questions'] ?? 0 );
        $id_mode        = sanitize_key( $_POST['identification_mode'] ?? 'anonymous' );
        $name           = sanitize_text_field( $_POST['respondent_name'] ?? '' );
        $email          = sanitize_email( $_POST['respondent_email'] ?? '' );
        $phone          = sanitize_text_field( $_POST['respondent_phone'] ?? '' );
        $identifier     = sanitize_text_field( $_POST['respondent_identifier'] ?? '' );
        $lead_id        = absint( $_POST['lead_id'] ?? 0 );
        $submission_id  = absint( $_POST['submission_id'] ?? 0 );
        $project_slug   = sanitize_title( $_POST['project_slug'] ?? '' );
        $operator_id    = absint( $_POST['operator_id'] ?? 0 );
        $ea_sid         = sanitize_text_field( $_POST['ea_sid'] ?? '' );

        if ( $form_id <= 0 ) {
            wp_send_json_error( ['message' => 'form_id requerido.'], 422 );
        }
        if ( $session_token === '' ) {
            $session_token = wp_generate_uuid4();
        }
        if ( ! in_array( $id_mode, ['anonymous', 'identified', 'optional'], true ) ) {
            $id_mode = 'anonymous';
        }
        // La config de la encuesta es autoritativa sobre el payload del cliente:
        // un payload manipulado (o un JS cacheado desactualizado) no puede volver
        // identificada una encuesta anónima ni viceversa.
        $config_mode = self::get_identification_mode( $form_id );
        if ( $config_mode !== '' ) {
            $id_mode = $config_mode;
        }

        global $wpdb;
        $entries_table = $wpdb->prefix . 'estandar_form_entries';
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, data_json FROM $entries_table WHERE form_id = %d AND data_json LIKE %s ORDER BY id DESC LIMIT 1",
            $form_id,
            '%' . $wpdb->esc_like( '"session_token":"' . $session_token . '"' ) . '%'
        ), ARRAY_A );

        $data = [
            'survey_session'       => true,
            'session_token'        => $session_token,
            'identification_mode'  => $id_mode,
            'respondent_name'      => null,
            'respondent_email'     => null,
            'respondent_phone'     => null,
            'respondent_identifier'=> null,
            'respondent_identity'  => [],
            'survey_status'        => 'started',
            'total_questions'      => $total_questions,
            'started_at'           => gmdate( 'Y-m-d H:i:s' ),
            'completed_at'         => null,
            'source_ip'            => self::get_ip(),
            'source_ua'            => sanitize_textarea_field( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ),
            'lead_id'              => $lead_id > 0 ? $lead_id : null,
            'submission_id'        => $submission_id > 0 ? $submission_id : null,
            'project_slug'         => $project_slug !== '' ? $project_slug : null,
            'operator_id'          => $operator_id > 0 ? $operator_id : null,
        ];

        if ( $existing ) {
            $data = array_merge(
                $data,
                (array) ( json_decode( (string) $existing['data_json'], true ) ?: [] )
            );
            $data['identification_mode'] = $id_mode;
            $data['total_questions'] = $total_questions;
        }

        if ( $id_mode !== 'anonymous' ) {
            $identity = [];
            $data['respondent_name'] = null;
            $data['respondent_email'] = null;
            $data['respondent_phone'] = null;
            $data['respondent_identifier'] = null;
            if ( $name !== '' ) {
                $identity['name'] = $name;
                $data['respondent_name'] = $name;
            }
            if ( is_email( $email ) ) {
                $identity['email'] = $email;
                $data['respondent_email'] = $email;
            }
            if ( $phone !== '' ) {
                $identity['phone'] = $phone;
                $data['respondent_phone'] = $phone;
            }
            if ( $identifier !== '' ) {
                $identity['identifier'] = $identifier;
                $data['respondent_identifier'] = $identifier;
            }
            $data['respondent_identity'] = $identity;
        } else {
            $data['respondent_name'] = null;
            $data['respondent_email'] = null;
            $data['respondent_phone'] = null;
            $data['respondent_identifier'] = null;
            $data['respondent_identity'] = [];
        }

        if ( $existing ) {
            // MS-4c (ADR 0034): escritura de captura delegada al motor (capa única).
            $updated = Estandar_Conversation_Engine::capture_session_update_data(
                (int) $existing['id'],
                (string) wp_json_encode( $data )
            );

            if ( ! $updated ) {
                wp_send_json_error( ['message' => 'No se pudo actualizar la identificación.'], 500 );
            }

            self::ea_record( 'survey_start', $form_id, $session_token, null, [], $ea_sid );
            self::emit_conversation_started( $form_id, $session_token, $id_mode, $data, $ea_sid );

            wp_send_json_success( [
                'entry_id'      => (int) $existing['id'],
                'session_token' => $session_token,
            ] );
        }

        $entry_id = Estandar_Conversation_Engine::capture_session_create(
            $form_id,
            (string) wp_json_encode( $data )
        );

        if ( $entry_id <= 0 ) {
            wp_send_json_error( ['message' => 'No se pudo iniciar la encuesta.'], 500 );
        }

        self::ea_record( 'survey_start', $form_id, $session_token, null, [], $ea_sid );
        self::emit_conversation_started( $form_id, $session_token, $id_mode, $data, $ea_sid );

        wp_send_json_success( [
            'entry_id'      => $entry_id,
            'session_token' => $session_token,
        ] );
    }

    /**
     * MS-3 (ADR 0034): emite el evento de dominio de sesión iniciada. Aditivo;
     * sin listeners es un no-op. Las integraciones lo consumen vía adaptadores.
     */
    private static function emit_conversation_started( int $form_id, string $session_token, string $id_mode, array $data, string $ea_sid ): void {
        if ( ! class_exists( 'Estandar_Conversation_Engine' ) ) {
            return;
        }
        Estandar_Conversation_Engine::emit( Estandar_Conversation_Engine::EVENT_STARTED, [
            'session_token'       => $session_token,
            'form_id'             => $form_id,
            'form_type'           => 'survey',
            'identification_mode' => $id_mode,
            'respondent'          => $data['respondent_identity'] ?? [],
            'project_slug'        => $data['project_slug'] ?? null,
            'lead_id'             => $data['lead_id'] ?? null,
            'submission_id'       => $data['submission_id'] ?? null,
            'ea_sid'              => $ea_sid,
        ] );
    }

    /**
     * Identification mode según la config del form (fuente autoritativa).
     * MS-5 (ADR 0034): lee por perfil — Survey → survey_config.identification_mode;
     * experiencias genéricas → conversation_config.identification. Devuelve '' si el
     * form no existe o no tiene config (se respeta el modo del payload).
     */
    private static function get_identification_mode( int $form_id ): string {
        global $wpdb;
        $form = $wpdb->get_row( $wpdb->prepare(
            "SELECT form_type, survey_config, conversation_config FROM {$wpdb->prefix}estandar_forms WHERE id = %d LIMIT 1",
            $form_id
        ) );
        if ( ! $form ) {
            return '';
        }
        $config = Estandar_Conversation_Engine::config_for_form( $form );
        $mode   = sanitize_key( (string) ( $config['identification_mode'] ?? ( $config['identification'] ?? '' ) ) );
        return in_array( $mode, ['anonymous', 'identified', 'optional'], true ) ? $mode : '';
    }

    /* ─── EVENT ─────────────────────────────────────────────────────────── */

    public static function handle_event(): void {
        check_ajax_referer( 'estandar_survey_nonce', 'nonce' );

        $form_id        = absint( $_POST['form_id'] ?? 0 );
        $entry_id       = absint( $_POST['entry_id'] ?? 0 );
        $session_token  = sanitize_text_field( $_POST['session_token'] ?? '' );
        $event_type     = sanitize_key( $_POST['event_type'] ?? '' );
        $question_index = isset( $_POST['question_index'] ) ? absint( $_POST['question_index'] ) : null;
        $ea_sid         = sanitize_text_field( $_POST['ea_sid'] ?? '' );

        $allowed_events = [
            'survey_view', 'survey_start', 'survey_question_view',
            'survey_question_answered', 'survey_completed', 'survey_abandoned',
        ];

        if ( $form_id <= 0 || ! in_array( $event_type, $allowed_events, true ) ) {
            wp_send_json_error( ['message' => 'Evento inválido.'], 422 );
        }

        // Persist abandoned state so analytics can count it from form_entries directly.
        // MS-4c (ADR 0034): escritura de captura delegada al motor (capa única).
        if ( $event_type === 'survey_abandoned' && $entry_id > 0 ) {
            Estandar_Conversation_Engine::capture_session_set_status(
                $entry_id,
                Estandar_Conversation_Engine::SESSION_STATUS_ABANDONED,
                $form_id
            );
        }

        self::ea_record( $event_type, $form_id, $session_token, $question_index, [], $ea_sid );

        wp_send_json_success( ['logged' => true] );
    }

    /* ─── ANSWER ─────────────────────────────────────────────────────────── */

    public static function handle_answer(): void {
        check_ajax_referer( 'estandar_survey_nonce', 'nonce' );

        $form_id       = absint( $_POST['form_id'] ?? 0 );
        $session_token = sanitize_text_field( $_POST['session_token'] ?? '' );
        $q_index       = absint( $_POST['question_index'] ?? 0 );
        $q_label       = sanitize_text_field( $_POST['question_label'] ?? '' );
        $q_type        = sanitize_key( $_POST['question_type'] ?? 'text' );
        $answer_value  = sanitize_text_field( $_POST['answer_value'] ?? '' );
        $answer_label  = sanitize_text_field( $_POST['answer_label'] ?? '' );
        $time_spent    = absint( $_POST['time_spent_seconds'] ?? 0 );
        $ea_sid        = sanitize_text_field( $_POST['ea_sid'] ?? '' );

        if ( $form_id <= 0 || $session_token === '' ) {
            wp_send_json_error( ['message' => 'form_id y session_token requeridos.'], 422 );
        }

        // MS-4 (ADR 0034): catálogo único de tipos del motor.
        $allowed_types = class_exists( 'Estandar_Conversation_Engine' )
            ? Estandar_Conversation_Engine::question_type_catalog()
            : ['nps', 'rating', 'emoji', 'cards', 'text', 'select', 'radio', 'textarea', 'matrix', 'checkbox'];
        if ( ! in_array( $q_type, $allowed_types, true ) ) {
            $q_type = 'text';
        }

        // MS-4b (ADR 0034): la validación/sanitización por tipo vive en el RUNTIME (motor),
        // no en la experiencia. El handler solo carga la definición del campo (max de rating).
        $answer_field = [];
        if ( $q_type === 'rating' ) {
            $structure_raw = $wpdb->get_var( $wpdb->prepare(
                "SELECT fields_json FROM {$wpdb->prefix}estandar_forms WHERE id = %d",
                $form_id
            ) );
            $structure = json_decode( (string) $structure_raw, true );
            if ( is_array( $structure ) && isset( $structure[ $q_index ] ) && is_array( $structure[ $q_index ] ) ) {
                $answer_field = $structure[ $q_index ];
            }
        }

        $validation = class_exists( 'Estandar_Conversation_Engine' )
            ? Estandar_Conversation_Engine::validate_answer_value( $q_type, $answer_value, $answer_field )
            : [ 'valid' => true, 'value' => $answer_value, 'error' => '' ];
        if ( ! $validation['valid'] ) {
            wp_send_json_error( ['message' => $validation['error']], 422 );
        }
        $answer_value = (string) ( $validation['value'] ?? $answer_value );

        global $wpdb;
        $table = $wpdb->prefix . 'estandar_survey_responses';

        $existing_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $table WHERE session_token = %s AND question_index = %d LIMIT 1",
            $session_token, $q_index
        ) );

        // MS-4c (ADR 0034): upsert de respuesta delegado al motor (capa única de captura).
        if ( $existing_id > 0 ) {
            Estandar_Conversation_Engine::capture_response_save(
                [
                    'answer_value'       => $answer_value,
                    'answer_label'       => $answer_label,
                    'answered_at'        => current_time( 'mysql', true ),
                    'time_spent_seconds' => $time_spent,
                ],
                [ 'id' => $existing_id ]
            );
        } else {
            Estandar_Conversation_Engine::capture_response_save( [
                'session_token'      => $session_token,
                'form_id'            => $form_id,
                'entry_id'           => null,
                'question_index'     => $q_index,
                'question_label'     => $q_label,
                'question_type'      => $q_type,
                'answer_value'       => $answer_value,
                'answer_label'       => $answer_label,
                'answered_at'        => current_time( 'mysql', true ),
                'time_spent_seconds' => $time_spent,
            ] );
        }

        self::ea_record( 'survey_question_answered', $form_id, $session_token, $q_index,
            [ 'question_type' => $q_type ], $ea_sid );

        // MS-3 (ADR 0034): evento de dominio de respuesta persistida (aditivo).
        if ( class_exists( 'Estandar_Conversation_Engine' ) ) {
            Estandar_Conversation_Engine::emit( Estandar_Conversation_Engine::EVENT_ANSWER_SAVED, [
                'session_token'      => $session_token,
                'form_id'            => $form_id,
                'step_index'         => $q_index,
                'question_type'      => $q_type,
                'answer_value'       => $answer_value,
                'answer_label'       => $answer_label,
                'time_spent_seconds' => $time_spent,
                'ea_sid'             => $ea_sid,
            ] );
        }

        wp_send_json_success( ['saved' => true] );
    }

    /* ─── COMPLETE ───────────────────────────────────────────────────────── */

    public static function handle_complete(): void {
        check_ajax_referer( 'estandar_survey_nonce', 'nonce' );

        $form_id       = absint( $_POST['form_id'] ?? 0 );
        $entry_id      = absint( $_POST['entry_id'] ?? 0 );
        $session_token = sanitize_text_field( $_POST['session_token'] ?? '' );
        $ea_sid        = sanitize_text_field( $_POST['ea_sid'] ?? '' );

        if ( $form_id <= 0 || $entry_id <= 0 ) {
            wp_send_json_error( ['message' => 'form_id y entry_id requeridos.'], 422 );
        }

        global $wpdb;
        $entries_table = $wpdb->prefix . 'estandar_form_entries';
        $forms_table   = $wpdb->prefix . 'estandar_forms';

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, data_json FROM $entries_table WHERE id = %d AND form_id = %d LIMIT 1",
            $entry_id, $form_id
        ), ARRAY_A );
        $form = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, name, notify_emails, mail_sender_name, survey_config, conversation_config, fields_json FROM $forms_table WHERE id = %d LIMIT 1",
            $form_id
        ) );

        if ( $row ) {
            $data                  = (array) ( json_decode( (string) $row['data_json'], true ) ?: [] );
            $notification_sent     = ! empty( $data['notification_sent_at'] );
            $data['survey_status'] = 'completed';
            $data['completed_at']  = gmdate( 'Y-m-d H:i:s' );

            // MS-4c (ADR 0034): finalización de sesión delegada al motor (capa única de captura).
            Estandar_Conversation_Engine::capture_session_complete(
                $entry_id,
                (string) wp_json_encode( $data )
            );

            // Link responses to this entry for export joins.
            Estandar_Conversation_Engine::capture_response_link_entry( $entry_id, $session_token, $form_id );

            if ( $form && ! $notification_sent ) {
                $notification_result = self::send_completion_email( $form, $entry_id, $session_token, $data );
                if ( true === $notification_result ) {
                    $data['notification_sent_at'] = gmdate( 'Y-m-d H:i:s' );
                    unset($data['notification_error']);
                } elseif ( is_wp_error( $notification_result ) && $notification_result->get_error_code() !== 'survey_email_no_recipients' ) {
                    $data['notification_error'] = $notification_result->get_error_message();
                }
                if ( isset($data['notification_sent_at']) || isset($data['notification_error']) ) {
                    Estandar_Conversation_Engine::capture_session_update_data( $entry_id, (string) wp_json_encode( $data ) );
                }
            }

            // MS-3 (ADR 0034): scoring + completion_strategy opt-in (solo si survey_config
            // define flow/scoring/completion_strategy). Encuestas por defecto → sin cambios.
            self::maybe_run_engine_completion( $form, $entry_id, $session_token, $data );
        }

        self::ea_record( 'survey_completed', $form_id, $session_token, null, [], $ea_sid );

        // MS-3 (ADR 0034): eventos de dominio de finalización (aditivos).
        if ( class_exists( 'Estandar_Conversation_Engine' ) ) {
            $completed_payload = [
                'session_token' => $session_token,
                'form_id'       => $form_id,
                'entry_id'      => $entry_id,
                'completed_at'  => gmdate( 'Y-m-d H:i:s' ),
                'respondent'    => $data['respondent_identity'] ?? [],
                'project_slug'  => $data['project_slug'] ?? null,
                'ea_sid'        => $ea_sid,
            ];
            Estandar_Conversation_Engine::emit( Estandar_Conversation_Engine::EVENT_COMPLETED, $completed_payload );
            Estandar_Conversation_Engine::emit( Estandar_Conversation_Engine::EVENT_SURVEY_COMPLETED, $completed_payload + ['survey_id' => $form_id] );
        }

        wp_send_json_success( ['completed' => true] );
    }

    /**
     * MS-3 (ADR 0034): capacidades opt-in del motor sobre una encuesta completada.
     * - scoring → persiste survey_result en la sesión y emite estandar_conversation_lead_qualified.
     * - completion_strategy.type = lead → emite estandar_lead_captured (ingesta canónica).
     * Sin config de flujo/scoring/completion en survey_config → no-op (encuesta actual).
     */
    private static function maybe_run_engine_completion( object $form, int $entry_id, string $session_token, array &$data ): void {
        if ( ! class_exists( 'Estandar_Conversation_Engine' ) ) {
            return;
        }
        $engine_cfg = Estandar_Conversation_Engine::normalize_config( Estandar_Conversation_Engine::config_for_form( $form ) );
        if ( $engine_cfg === [] ) {
            return;
        }

        global $wpdb;
        $responses = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT question_index, answer_value
             FROM {$wpdb->prefix}estandar_survey_responses
             WHERE form_id = %d AND session_token = %s",
            (int) $form->id,
            $session_token
        ), ARRAY_A );

        $fields = function_exists( 'estandar_forms_decode_fields' )
            ? estandar_forms_decode_fields( (string) ( $form->fields_json ?? '' ) )
            : json_decode( (string) ( $form->fields_json ?? '' ), true );
        $fields = is_array( $fields ) ? $fields : [];

        $completion = Estandar_Conversation_Engine::completion_result(
            $engine_cfg,
            Estandar_Conversation_Engine::build_answers_from_responses( $responses, $fields )
        );

        if ( $completion['scoring'] !== null ) {
            $data['survey_result'] = $completion['scoring'];
            Estandar_Conversation_Engine::capture_session_update_data( $entry_id, (string) wp_json_encode( $data ) );
            Estandar_Conversation_Engine::emit( Estandar_Conversation_Engine::EVENT_LEAD_QUALIFIED, [
                'session_token' => $session_token,
                'form_id'       => (int) $form->id,
                'entry_id'      => $entry_id,
                'scoring'       => $completion['scoring'],
            ] );
        }

        if ( $completion['completion_type'] === 'lead' ) {
            $lead_payload = self::build_lead_payload_from_entry( $form, $entry_id, $session_token, $data, $completion['scoring'] );
            if ( function_exists( 'do_action' ) ) {
                do_action( 'estandar_lead_captured', $lead_payload );
            }
        }
    }

    /**
     * Construye el payload CANÓNICO de estandar_lead_captured desde una sesión
     * conversacional (QA MS-5: validado por Estandar_Leads_Payload_Validator —
     * schema_version/source_plugin/contact requeridos).
     */
    private static function build_lead_payload_from_entry( object $form, int $entry_id, string $session_token, array $entry_data, ?array $scoring ): array {
        $name  = sanitize_text_field( (string) ( $entry_data['respondent_name'] ?? '' ) );
        $email = sanitize_email( (string) ( $entry_data['respondent_email'] ?? '' ) );
        $phone = sanitize_text_field( (string) ( $entry_data['respondent_phone'] ?? '' ) );

        $raw_fields = [];
        $system_keys = [
            'survey_session', 'session_token', 'identification_mode',
            'respondent_name', 'respondent_email', 'respondent_phone',
            'respondent_identifier', 'respondent_identity', 'survey_status',
            'total_questions', 'started_at', 'completed_at', 'source_ip',
            'source_ua', 'lead_id', 'submission_id', 'project_slug',
            'operator_id', 'notification_sent_at', 'notification_error', 'survey_result',
        ];
        foreach ( $entry_data as $key => $value ) {
            if ( ! in_array( $key, $system_keys, true ) && ! is_array( $value ) ) {
                $raw_fields[ $key ] = $value;
            }
        }

        return [
            'schema_version' => '1.0',
            'source_plugin'  => 'estandar-forms',
            'submitted_at'   => gmdate( 'c' ),
            'contact'        => [
                'name'  => $name,
                'email' => $email,
                'phone' => $phone,
            ],
            'content'        => [
                'message'        => '',
                'source_form_id' => (int) ( $form->id ?? 0 ),
            ],
            'attribution'    => [],
            'context'        => [
                'project_slug'   => sanitize_title( (string) ( $entry_data['project_slug'] ?? '' ) ),
                'intent'         => 'conversation',
                'source_page_url'=> '',
            ],
            'request'        => [
                'ip_address' => sanitize_text_field( (string) ( $entry_data['source_ip'] ?? '' ) ),
                'user_agent' => sanitize_textarea_field( (string) ( $entry_data['source_ua'] ?? '' ) ),
            ],
            'spam'           => [ 'honeypot_filled' => false, 'seconds_to_submit' => 0 ],
            'meta'           => [
                'source_entry_id'       => $entry_id,
                'lead_uuid'             => '',
                'qualification'         => $scoring,
                'conversation'          => true,
                'conversation_session'  => $session_token,
                'sanitized_raw_fields'  => $raw_fields,
            ],
        ];
    }

    private static function send_completion_email( object $form, int $entry_id, string $session_token, array $entry_data ) {
        $recipients = preg_split('/[\r\n,]+/', (string) ($form->notify_emails ?? ''));
        $recipients = array_values(array_unique(array_filter(array_map(static function ($email) {
            $email = sanitize_email((string) $email);
            return is_email($email) ? $email : '';
        }, (array) $recipients))));

        if ( empty($recipients) ) {
            return new WP_Error('survey_email_no_recipients', 'Encuesta sin destinatarios de notificación.');
        }

        global $wpdb;
        $responses = $wpdb->get_results( $wpdb->prepare(
            "SELECT question_index, question_label, question_type, answer_value, answer_label
             FROM {$wpdb->prefix}estandar_survey_responses
             WHERE form_id = %d AND session_token = %s
             ORDER BY question_index ASC",
            (int) $form->id,
            $session_token
        ), ARRAY_A );

        // Load survey structure for matrix row labels
        $survey_structure = [];
        $structure_raw = $wpdb->get_var( $wpdb->prepare(
            "SELECT fields_json FROM {$wpdb->prefix}estandar_forms WHERE id = %d",
            (int) $form->id
        ) );
        if ( $structure_raw ) {
            $decoded = json_decode( $structure_raw, true );
            if ( is_array( $decoded ) ) {
                foreach ( $decoded as $field ) {
                    if ( ( $field['type'] ?? '' ) === 'matrix' && isset( $field['rows'] ) ) {
                        foreach ( $field['rows'] as $row ) {
                            $survey_structure[ $row['id'] ] = $row['label'] ?? '';
                        }
                    }
                }
            }
        }

        $survey_name = sanitize_text_field((string) ($form->name ?? 'Encuesta'));
        $subject = 'Nueva respuesta de encuesta - ' . $survey_name;
        $sent_at = wp_date('Y-m-d H:i:s');

        // Generate secure token for public per-response view
        $response_url = '';
        if ( class_exists( 'Estandar_Secure_Token' ) ) {
            $token_expiry = (int) apply_filters( 'estandar_forms_secure_token_expiry_days', 90 );
            $meta = [ 'survey_id' => (int) $form->id ];
            $token = Estandar_Secure_Token::regenerate( 'survey', $entry_id, $token_expiry, $meta );
            if ( ! is_wp_error( $token ) ) {
                $response_url = Estandar_Secure_Token::public_url( $token );
            }
        }

        // Generate or reuse secure token for public survey results view
        $survey_results_url = '';
        if ( class_exists( 'Estandar_Secure_Token' ) ) {
            $token_expiry = (int) apply_filters( 'estandar_forms_secure_token_expiry_days', 90 );
            $existing = Estandar_Secure_Token::get_active_token( 'survey_results', (int) $form->id );
            if ( $existing ) {
                $survey_results_url = Estandar_Secure_Token::public_url( $existing );
            } else {
                $meta = [ 'survey_id' => (int) $form->id, 'survey_name' => $survey_name ];
                $s_token = Estandar_Secure_Token::generate( 'survey_results', (int) $form->id, $token_expiry, $meta );
                if ( ! is_wp_error( $s_token ) ) {
                    $survey_results_url = Estandar_Secure_Token::public_url( $s_token );
                }
            }
        }

        $respondent_name = sanitize_text_field((string) ($entry_data['respondent_name'] ?? ''));
        $respondent_email = sanitize_email((string) ($entry_data['respondent_email'] ?? ''));
        $respondent_phone = sanitize_text_field((string) ($entry_data['respondent_phone'] ?? ''));
        $project_slug = sanitize_title((string) ($entry_data['project_slug'] ?? ''));
        $answer_rows = [];
        foreach ( (array) $responses as $response ) {
            $q_type = $response['question_type'] ?? 'text';
            $label  = sanitize_text_field((string) ($response['question_label'] ?? 'Pregunta'));

            if ( $q_type === 'matrix' ) {
                // Parse JSON answer_value
                $raw_value = (string) ($response['answer_value'] ?? '{}');
                $decoded   = json_decode( $raw_value, true );
                if ( is_array( $decoded ) && ! empty( $decoded ) ) {
                    $rows_html = '';
                    $scores    = [];
                    foreach ( $decoded as $row_id => $score ) {
                        $row_label = $survey_structure[ $row_id ] ?? $row_id;
                        $score     = (int) $score;
                        $scores[]  = $score;
                        $rows_html .= '<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f3f4f6;font-size:14px;">';
                        $rows_html .= '<span style="color:#374151;">' . esc_html( $row_label ) . '</span>';
                        $rows_html .= '<span style="font-weight:700;color:#111827;">' . $score . '</span>';
                        $rows_html .= '</div>';
                    }
                    if ( count( $scores ) > 1 ) {
                        $avg = round( array_sum( $scores ) / count( $scores ), 1 );
                        $rows_html .= '<div style="display:flex;justify-content:space-between;padding:8px 0 0;font-size:13px;color:#6b7280;border-top:2px solid #e5e7eb;margin-top:4px">';
                        $rows_html .= '<span>Promedio</span>';
                        $rows_html .= '<span style="font-weight:600;">' . number_format( $avg, 1 ) . '</span>';
                        $rows_html .= '</div>';
                    }
                    $answer_rows[] = [ 'type' => 'matrix', 'label' => $label, 'html' => $rows_html ];
                } else {
                    $answer_rows[] = [ 'type' => 'text', 'label' => $label, 'html' => '<em style="color:#9ca3af">Sin respuesta</em>' ];
                }
            } elseif ( (string) ($response['question_type'] ?? '') === 'checkbox' ) {
                $raw_value = (string) ( $response['answer_value'] ?? '{}' );
                $decoded   = json_decode( $raw_value, true );
                if ( is_array( $decoded ) && ! empty( $decoded['checked'] ) ) {
                    $checked_labels = [];
                    foreach ( (array) ($decoded['checked'] ?? []) as $val ) {
                        $checked_labels[] = esc_html( sanitize_text_field( (string) $val ) );
                    }
                    $display = implode( ', ', $checked_labels );
                    if ( ! empty( $decoded['other_text'] ) ) {
                        $display .= ( $display !== '' ? ' + ' : '' ) . 'Otro: ' . esc_html( sanitize_text_field( $decoded['other_text'] ) );
                    }
                    $answer_rows[] = [ 'type' => 'checkbox', 'label' => $label, 'html' => $display !== '' ? $display : '<em style="color:#9ca3af">Sin respuesta</em>' ];
                } else {
                    $answer_rows[] = [ 'type' => 'text', 'label' => $label, 'html' => '<em style="color:#9ca3af">Sin respuesta</em>' ];
                }
            } else {
                $answer = (string) (($response['answer_label'] ?? '') !== '' ? $response['answer_label'] : ($response['answer_value'] ?? ''));
                $display = $answer !== '' ? esc_html( sanitize_text_field( $answer ) ) : '<em style="color:#9ca3af">Sin respuesta</em>';
                $answer_rows[] = [ 'type' => 'text', 'label' => $label, 'html' => $display ];
            }
        }

        $headers = [];
        $sender_email = function_exists('estandar_core_get_sender_email')
            ? estandar_core_get_sender_email('', (string) get_option('admin_email', ''))
            : sanitize_email((string) get_option('admin_email', ''));
        $sender_name = sanitize_text_field((string) ($form->mail_sender_name ?? ''));
        if ( $sender_name === '' && function_exists('estandar_core_get_sender_name') ) {
            $sender_name = estandar_core_get_sender_name('', (string) get_bloginfo('name'));
        }
        if ( $sender_name === '' ) {
            $sender_name = sanitize_text_field((string) get_bloginfo('name'));
        }
        if ( is_email($sender_email) ) {
            $headers[] = 'From: ' . $sender_name . ' <' . $sender_email . '>';
        }
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        if ( is_email($respondent_email) ) {
            $headers[] = 'Reply-To: ' . $respondent_email;
        }

        $html = self::build_completion_email_html(
            $survey_name,
            $sent_at,
            $entry_id,
            $session_token,
            $answer_rows,
            $response_url,
            $survey_results_url,
            [
                'respondent_name'  => $respondent_name,
                'respondent_email' => is_email($respondent_email) ? $respondent_email : '',
                'respondent_phone' => $respondent_phone,
                'project_slug'     => $project_slug,
            ]
        );

        $sent = wp_mail($recipients, $subject, $html, $headers);
        self::log_notification((int) $form->id, $entry_id, $sent ? 'sent' : 'failed', $recipients);

        return $sent ? true : new WP_Error('survey_email_send_failed', 'No se pudo enviar la notificación de encuesta.');
    }

    private static function build_completion_email_html(
        string $survey_name,
        string $sent_at,
        int $entry_id,
        string $session_token,
        array $answer_rows,
        string $response_url,
        string $survey_results_url,
        array $meta
    ): string {
        $brand_color = function_exists( 'estandar_forms_brand_color' ) ? estandar_forms_brand_color() : '#0069ff';

        // Logo: try to get from common location
        $logo_url = '';
        if ( function_exists( 'get_site_icon_url' ) ) {
            $logo_url = get_site_icon_url( 64 );
        }
        if ( $logo_url === '' && function_exists( 'get_theme_mod' ) ) {
            $custom_logo_id = get_theme_mod( 'custom_logo' );
            if ( $custom_logo_id ) {
                $logo_url = wp_get_attachment_image_url( $custom_logo_id, array( 64, 64 ) );
            }
        }
        // Filter for custom logo override
        $logo_url = (string) apply_filters( 'estandar_forms_email_logo_url', $logo_url );

        // Humanize date
        $timestamp   = strtotime( $sent_at );
        $human_date  = ( function_exists( 'wp_date' ) ? wp_date( 'l j \d\e F \d\e Y', $timestamp ) : date( 'l j \d\e F \d\e Y', $timestamp ) );
        $human_time  = ( function_exists( 'wp_date' ) ? wp_date( 'H:i', $timestamp ) : date( 'H:i', $timestamp ) );

        ob_start();
        ?>
<!doctype html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width">
<style type="text/css">
@media only screen and (max-width:480px){
    .esv-email-inner{ padding:0 12px !important; }
    .esv-email-content{ padding:16px !important; }
    .esv-email-header{ padding:16px 18px !important; }
    .esv-email-header h1{ font-size:18px !important; }
    .esv-answer-block{ padding:12px !important; }
}
</style>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;color:#1d2327;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f3f4f6;padding:24px 0;">
<tr><td align="center">
<table class="esv-email-inner" role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="max-width:600px;width:100%;">

    <!-- Header / brand bar -->
    <tr><td style="background:<?php echo esc_attr( $brand_color ); ?>;padding:20px 24px;border-radius:10px 10px 0 0;">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
        <tr>
            <?php if ( $logo_url !== '' ) : ?>
            <td width="36" style="padding:0 12px 0 0;vertical-align:middle;">
                <img src="<?php echo esc_url( $logo_url ); ?>" alt="Logo" width="36" height="36" style="display:block;border-radius:6px;max-width:36px;" />
            </td>
            <?php endif; ?>
            <td style="vertical-align:middle;">
                <div style="color:#ffffff;font-size:11px;text-transform:uppercase;letter-spacing:.08em;font-weight:600;margin-bottom:2px;">Estandar Forms</div>
                <h1 style="color:#ffffff;font-size:20px;font-weight:700;margin:0;line-height:1.25;">Nueva respuesta de encuesta</h1>
            </td>
        </tr>
        </table>
    </td></tr>

    <!-- Body -->
    <tr><td class="esv-email-content" style="background:#ffffff;padding:24px;border:1px solid #e5e7eb;border-top:0;border-radius:0 0 10px 10px;">

        <!-- Survey card -->
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-bottom:20px;">
            <tr><td style="padding:0 0 10px;font-size:20px;font-weight:700;color:#111827;"><?php echo esc_html( $survey_name ); ?></td></tr>
            <tr><td style="font-size:13px;color:#6b7280;padding:0;"><?php echo esc_html( $human_date ); ?> · <?php echo esc_html( $human_time ); ?> hrs</td></tr>
        </table>

        <!-- Respondent info (if available) -->
        <?php
        $info_lines = [];
        if ( ! empty( $meta['respondent_name'] ) ) {
            $info_lines[] = [ 'label' => 'Respondente', 'value' => $meta['respondent_name'] ];
        }
        if ( ! empty( $meta['respondent_email'] ) ) {
            $info_lines[] = [ 'label' => 'Email', 'value' => $meta['respondent_email'] ];
        }
        if ( ! empty( $meta['respondent_phone'] ) ) {
            $info_lines[] = [ 'label' => 'Teléfono', 'value' => $meta['respondent_phone'] ];
        }
        if ( ! empty( $meta['project_slug'] ) ) {
            $info_lines[] = [ 'label' => 'Proyecto', 'value' => $meta['project_slug'] ];
        }
        if ( ! empty( $info_lines ) ) :
        ?>
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-bottom:20px;">
            <?php foreach ( $info_lines as $il ) : ?>
            <tr>
                <td style="width:100px;padding:6px 0;font-size:12px;color:#6b7280;vertical-align:top;text-transform:uppercase;letter-spacing:.04em;"><?php echo esc_html( $il['label'] ); ?></td>
                <td style="padding:6px 0;font-size:14px;color:#111827;"><?php echo esc_html( $il['value'] ); ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>

        <!-- Answers -->
        <div style="border-top:2px solid #f3f4f6;padding-top:4px;"></div>
        <h2 style="font-size:14px;font-weight:700;color:#111827;margin:12px 0 12px;text-transform:uppercase;letter-spacing:.04em;">Respuestas</h2>

        <?php foreach ( $answer_rows as $row ) : ?>
        <div class="esv-answer-block" style="background:#f9fafb;border-radius:8px;padding:14px 16px;margin-bottom:10px;">
            <div style="font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;margin-bottom:8px;"><?php echo esc_html( $row['label'] ); ?></div>
            <div style="font-size:15px;color:#111827;line-height:1.5;">
                <?php echo $row['html']; // already escaped per type ?>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- Buttons -->
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-top:24px;">
            <?php if ( $response_url !== '' ) : ?>
            <tr><td align="center" style="padding-bottom:10px;">
                <a href="<?php echo esc_url( $response_url ); ?>" style="display:inline-block;background:#111827;color:#ffffff;text-decoration:none;border-radius:8px;padding:14px 28px;font-size:15px;font-weight:600;">Ver esta respuesta</a>
            </td></tr>
            <?php endif; ?>
            <?php if ( $survey_results_url !== '' ) : ?>
            <tr><td align="center">
                <a href="<?php echo esc_url( $survey_results_url ); ?>" style="display:inline-block;background:#ffffff;color:#111827;text-decoration:none;border-radius:8px;padding:12px 24px;font-size:14px;font-weight:600;border:2px solid #e5e7eb;">Ver resultados de la encuesta</a>
            </td></tr>
            <?php endif; ?>
        </table>

        <!-- Footer -->
        <div style="border-top:1px solid #e5e7eb;margin-top:28px;padding-top:16px;font-size:12px;color:#9ca3af;text-align:center;">
            <div style="margin-bottom:6px;">Encuestas y experiencia de clientes gestionadas por</div>
            <div><strong style="color:#6b7280">Estandar Forms</strong> &mdash; <a href="https://estandar.cl" style="color:#9ca3af;text-decoration:none;">estandar.cl</a></div>
            <div style="margin-top:8px;">Este correo fue generado automáticamente. No es necesario responder.</div>
        </div>

    </td></tr>
</table>
</td></tr></table>
</body>
</html>
        <?php
        return (string) ob_get_clean();
    }

    private static function log_notification( int $form_id, int $entry_id, string $status, array $recipients ): void {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'estandar_form_logs',
            [
                'form_id'      => $form_id,
                'event_type'   => 'survey.notification_' . $status,
                'level'        => $status === 'sent' ? 'info' : 'warning',
                'message'      => 'Survey notification ' . $status . '.',
                'context_json' => wp_json_encode([
                    'entry_id'   => $entry_id,
                    'recipients' => array_values($recipients),
                ]),
                'created_at'   => current_time('mysql'),
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s' ]
        );
    }

    /* ─── HELPERS ─────────────────────────────────────────────────────────── */

    /**
     * Fire a survey event into Estandar Analytics (ea_events) if the plugin is active.
     * Gracefully silent if Analytics is not installed.
     *
     * event_label = (string)$form_id  → indexed, queryable without JSON extraction.
     * event_value = (string)$q_index  → used by get_funnel() GROUP BY event_value.
     */
    private static function ea_record(
        string $event_type,
        int    $form_id,
        string $session_token,
        ?int   $q_index,
        array  $extra_meta,
        string $ea_sid
    ): void {
        if ( ! class_exists( 'EA_Events' ) ) {
            return;
        }

        $meta = array_filter( array_merge(
            [
                'form_id'              => (string) $form_id,
                'survey_session_token' => $session_token,
            ],
            $extra_meta
        ) );

        EA_Events::record_single( $ea_sid, [
            'type'  => $event_type,
            'label' => (string) $form_id,
            'value' => $q_index !== null ? (string) $q_index : '',
            'meta'  => $meta,
        ] );
    }

    private static function get_ip(): string {
        $ip = sanitize_text_field( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) );
        return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
    }

    /* ─── SECURE RESPONSE VIEWER ─────────────────────────────────────── */

    public static function handle_secure_response_route(): void {
        $raw_token = (string) ( $_GET['estandar_secure_token'] ?? '' );
        if ( $raw_token === '' ) {
            return;
        }

        if ( ! class_exists( 'Estandar_Secure_Token' ) ) {
            self::render_secure_error( 'El sistema de seguridad no está disponible.' );
            return;
        }

        $data = Estandar_Secure_Token::validate( $raw_token );
        if ( is_wp_error( $data ) ) {
            $message = match ( $data->get_error_code() ) {
                'invalid_token'    => 'El enlace no es válido.',
                'token_revoked'    => 'Este enlace fue revocado por el administrador.',
                'token_expired'    => 'Este enlace ha expirado.',
                default            => 'El enlace no está disponible.',
            };
            self::render_secure_error( $message );
            return;
        }

        if ( $data['module'] === 'survey' ) {
            self::render_secure_response( $data['reference_id'], $data['meta'] );
            return;
        }

        if ( $data['module'] === 'survey_results' ) {
            self::render_secure_survey_results( $data['reference_id'], $data['meta'] );
            return;
        }

        self::render_secure_error( 'Recurso no disponible.' );
    }

    private static function render_secure_response( int $entry_id, array $meta ): void {
        global $wpdb;
        $survey_id = (int) ( $meta['survey_id'] ?? 0 );

        nocache_headers();
        header( 'X-Robots-Tag: noindex, nofollow', true );
        echo '<!doctype html><html><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow"><title>Respuesta de encuesta</title>';
        echo '<meta name="viewport" content="width=device-width"><style>';
        echo 'body{margin:0;padding:0;background:#f3f4f6;color:#1d2327;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;line-height:1.5}';
        echo '.wrap{max-width:680px;margin:0 auto;padding:24px 14px}';
        echo '.header{background:' . esc_attr( function_exists( 'estandar_forms_brand_color' ) ? estandar_forms_brand_color() : '#0069ff' ) . ';color:#fff;padding:20px 24px;border-radius:10px 10px 0 0}';
        echo '.header h1{margin:4px 0 0;font-size:20px;font-weight:700}';
        echo '.header .sub{font-size:11px;text-transform:uppercase;letter-spacing:.08em;font-weight:600;opacity:.8}';
        echo '.body{background:#fff;padding:24px;border:1px solid #e5e7eb;border-top:0;border-radius:0 0 10px 10px}';
        echo '.survey-name{font-size:20px;font-weight:700;color:#111827;margin-bottom:4px}';
        echo '.survey-date{font-size:13px;color:#6b7280;margin-bottom:20px}';
        echo '.answer-block{background:#f9fafb;border-radius:8px;padding:14px 16px;margin-bottom:10px}';
        echo '.answer-q{font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;margin-bottom:8px}';
        echo '.answer-a{font-size:15px;color:#111827}';
        echo '.matrix-row{display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f3f4f6;font-size:14px}';
        echo '.matrix-row:last-child{border-bottom:none}';
        echo '.matrix-label{color:#374151}';
        echo '.matrix-score{font-weight:700;color:#111827}';
        echo '.matrix-avg{padding:8px 0 0;font-size:13px;color:#6b7280;border-top:2px solid #e5e7eb;margin-top:4px;display:flex;justify-content:space-between}';
        echo '@media(max-width:480px){.wrap{padding:16px 10px}.header{padding:16px 18px}.header h1{font-size:17px}.body{padding:16px}}';
        echo '</style></head><body><div class="wrap">';
        echo '<div class="header"><div class="sub">Estandar Forms</div><h1>Respuesta de encuesta</h1></div>';
        echo '<div class="body">';

        if ( $survey_id > 0 ) {
            $survey = $wpdb->get_row( $wpdb->prepare(
                "SELECT name FROM {$wpdb->prefix}estandar_forms WHERE id = %d",
                $survey_id
            ) );
            $survey_name = $survey ? sanitize_text_field( $survey->name ) : 'Encuesta';
            echo '<div class="survey-name">' . esc_html( $survey_name ) . '</div>';

            // Load survey structure for matrix row labels
            $matrix_row_labels = [];
            $structure_raw = $wpdb->get_var( $wpdb->prepare(
                "SELECT fields_json FROM {$wpdb->prefix}estandar_forms WHERE id = %d", $survey_id
            ) );
            if ( $structure_raw ) {
                $structure = json_decode( $structure_raw, true );
                if ( is_array( $structure ) ) {
                    foreach ( $structure as $field ) {
                        if ( ( $field['type'] ?? '' ) === 'matrix' && isset( $field['rows'] ) ) {
                            foreach ( $field['rows'] as $row_def ) {
                                $matrix_row_labels[ $row_def['id'] ] = $row_def['label'] ?? $row_def['id'];
                            }
                        }
                    }
                }
            }

            // Humanize date
            $entry_data = $wpdb->get_row( $wpdb->prepare(
                "SELECT created_at FROM {$wpdb->prefix}estandar_form_entries WHERE id = %d", $entry_id
            ) );
            if ( $entry_data && $entry_data->created_at ) {
                $ts = strtotime( $entry_data->created_at );
                echo '<div class="survey-date">' . esc_html( wp_date( 'l j \d\e F \d\e Y', $ts ) . ' · ' . wp_date( 'H:i', $ts ) . ' hrs' ) . '</div>';
            }

            // Get responses
            $responses = $wpdb->get_results( $wpdb->prepare(
                "SELECT question_label, question_type, answer_value, answer_label
                 FROM {$wpdb->prefix}estandar_survey_responses
                 WHERE form_id = %d AND entry_id = %d
                 ORDER BY question_index ASC",
                $survey_id, $entry_id
            ), ARRAY_A );

            if ( empty( $responses ) ) {
                // Fallback: query by session token
                $token_row = $wpdb->get_var( $wpdb->prepare(
                    "SELECT session_token FROM {$wpdb->prefix}estandar_form_entries WHERE id = %d", $entry_id
                ) );
                if ( $token_row ) {
                    $responses = $wpdb->get_results( $wpdb->prepare(
                        "SELECT question_label, question_type, answer_value, answer_label
                         FROM {$wpdb->prefix}estandar_survey_responses
                         WHERE form_id = %d AND session_token = %s
                         ORDER BY question_index ASC",
                        $survey_id, $token_row
                    ), ARRAY_A );
                }
            }

            foreach ( $responses as $resp ) {
                $q_type = $resp['question_type'] ?? 'text';
                $label  = sanitize_text_field( $resp['question_label'] ?? 'Pregunta' );
                echo '<div class="answer-block"><div class="answer-q">' . esc_html( $label ) . '</div><div class="answer-a">';

                if ( $q_type === 'matrix' ) {
                    $raw_value = (string) ( $resp['answer_value'] ?? '{}' );
                    $decoded   = json_decode( $raw_value, true );
                    if ( is_array( $decoded ) && ! empty( $decoded ) ) {
                        $scores = [];
                        foreach ( $decoded as $row_id => $score ) {
                            $row_label = $matrix_row_labels[ $row_id ] ?? $row_id;
                            $score     = (int) $score;
                            $scores[]  = $score;
                            echo '<div class="matrix-row"><span class="matrix-label">' . esc_html( $row_label ) . '</span><span class="matrix-score">' . $score . '</span></div>';
                        }
                        if ( count( $scores ) > 1 ) {
                            $avg = round( array_sum( $scores ) / count( $scores ), 1 );
                            echo '<div class="matrix-avg"><span>Promedio</span><span>' . number_format( $avg, 1 ) . '</span></div>';
                        }
                    } else {
                        echo '<em style="color:#9ca3af">Sin respuesta</em>';
                    }
                } elseif ( $q_type === 'checkbox' ) {
                    $raw_value = (string) ( $resp['answer_value'] ?? '{}' );
                    $decoded   = json_decode( $raw_value, true );
                    if ( is_array( $decoded ) && ! empty( $decoded['checked'] ) ) {
                        $parts = [];
                        foreach ( (array) ($decoded['checked'] ?? []) as $val ) {
                            $parts[] = esc_html( sanitize_text_field( (string) $val ) );
                        }
                        if ( ! empty( $decoded['other_text'] ) ) {
                            $parts[] = 'Otro: ' . esc_html( sanitize_text_field( $decoded['other_text'] ) );
                        }
                        echo implode( ', ', $parts );
                    } else {
                        echo '<em style="color:#9ca3af">Sin respuesta</em>';
                    }
                } else {
                    $answer = (string) ( ( $resp['answer_label'] ?? '' ) !== '' ? $resp['answer_label'] : ( $resp['answer_value'] ?? '' ) );
                    echo $answer !== '' ? esc_html( sanitize_text_field( $answer ) ) : '<em style="color:#9ca3af">Sin respuesta</em>';
                }

                echo '</div></div>';
            }
        }

        echo '<div style="text-align:center;font-size:12px;color:#9ca3af;margin-top:28px;padding-top:16px;border-top:1px solid #e5e7eb">';
        echo 'Este enlace fue generado automáticamente por Estandar Forms.';
        echo '</div></div></div></body></html>';
        exit;
    }

    private static function render_secure_survey_results( int $survey_id, array $meta ): void {
        global $wpdb;

        nocache_headers();
        header( 'X-Robots-Tag: noindex, nofollow', true );

        $brand_color = function_exists( 'estandar_forms_brand_color' ) ? estandar_forms_brand_color() : '#0069ff';
        $survey_name = $meta['survey_name'] ?? '';
        if ( $survey_name === '' ) {
            $s = $wpdb->get_var( $wpdb->prepare(
                "SELECT name FROM {$wpdb->prefix}estandar_forms WHERE id = %d", $survey_id
            ) );
            $survey_name = $s ? sanitize_text_field( $s ) : 'Encuesta';
        }

        // Get all completed entries for this survey
        $entries = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, created_at, data_json
             FROM {$wpdb->prefix}estandar_form_entries
             WHERE form_id = %d AND status = 'survey_completed'
             ORDER BY created_at DESC",
            $survey_id
        ), ARRAY_A );

        $total_responses = count( $entries );

        // Load matrix row labels
        $matrix_row_labels = [];
        $structure_raw = $wpdb->get_var( $wpdb->prepare(
            "SELECT fields_json FROM {$wpdb->prefix}estandar_forms WHERE id = %d", $survey_id
        ) );
        if ( $structure_raw ) {
            $structure = json_decode( $structure_raw, true );
            if ( is_array( $structure ) ) {
                foreach ( $structure as $field ) {
                    if ( ( $field['type'] ?? '' ) === 'matrix' && isset( $field['rows'] ) ) {
                        foreach ( $field['rows'] as $row_def ) {
                            $matrix_row_labels[ $row_def['id'] ] = $row_def['label'] ?? $row_def['id'];
                        }
                    }
                }
            }
        }

        ?><!doctype html>
        <html><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow">
        <meta name="viewport" content="width=device-width">
        <title>Resultados &mdash; <?php echo esc_html( $survey_name ); ?></title>
        <style>
        body{margin:0;padding:0;background:#f3f4f6;color:#1d2327;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;line-height:1.5}
        .wrap{max-width:720px;margin:0 auto;padding:24px 14px}
        .header{background:<?php echo esc_attr( $brand_color ); ?>;color:#fff;padding:20px 24px;border-radius:10px 10px 0 0}
        .header h1{margin:4px 0 0;font-size:20px;font-weight:700}
        .header .sub{font-size:11px;text-transform:uppercase;letter-spacing:.08em;font-weight:600;opacity:.8}
        .body{background:#fff;padding:24px;border:1px solid #e5e7eb;border-top:0;border-radius:0 0 10px 10px}
        .survey-title{font-size:20px;font-weight:700;color:#111827;margin-bottom:2px}
        .survey-count{font-size:13px;color:#6b7280;margin-bottom:24px}
        .resp-card{background:#f9fafb;border-radius:8px;padding:16px;margin-bottom:14px;border:1px solid #e5e7eb;border-left:3px solid <?php echo esc_attr( $brand_color ); ?>}
        .resp-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:6px}
        .resp-date{font-size:12px;color:#6b7280}
        .resp-num{font-size:12px;font-weight:700;color:<?php echo esc_attr( $brand_color ); ?>;text-transform:uppercase;letter-spacing:.04em}
        .resp-detail{margin-top:8px}
        .resp-q{font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;margin-bottom:3px}
        .resp-a{font-size:14px;color:#111827;margin-bottom:10px;padding-left:8px;border-left:2px solid #e5e7eb}
        .matrix-row{display:flex;justify-content:space-between;padding:4px 0;font-size:13px;border-bottom:1px solid #f3f4f6}
        .matrix-row:last-child{border-bottom:none}
        .matrix-label{color:#374151}
        .matrix-score{font-weight:700;color:#111827}
        .footer{text-align:center;font-size:12px;color:#9ca3af;margin-top:28px;padding-top:16px;border-top:1px solid #e5e7eb}
        @media(max-width:480px){.wrap{padding:16px 10px}.header{padding:16px 18px}.body{padding:16px}.resp-card{padding:12px}}
        </style>
        </head><body><div class="wrap">
        <div class="header"><div class="sub">Estandar Forms</div><h1>Resultados de encuesta</h1></div>
        <div class="body">
        <div class="survey-title"><?php echo esc_html( $survey_name ); ?></div>
        <div class="survey-count"><?php echo (int) $total_responses; ?> respuesta(s) recibida(s)</div>

        <?php if ( empty( $entries ) ) : ?>
        <p style="color:#6b7280;font-size:14px">Aún no se han recibido respuestas.</p>
        <?php else :
            $resp_num = 1;
            foreach ( $entries as $entry ) :
                $entry_id   = (int) $entry['id'];
                $date_str   = '';
                $respondent = [];

                if ( $entry['created_at'] ) {
                    $ts = is_numeric( $entry['created_at'] ) ? (int) $entry['created_at'] : strtotime( $entry['created_at'] );
                    if ( $ts ) {
                        $date_str = wp_date( 'l j \d\e F \d\e Y', $ts ) . ' · ' . wp_date( 'H:i', $ts ) . ' hrs';
                    }
                }

                // Get respondent info from data_json
                $entry_data = json_decode( $entry['data_json'] ?? '{}', true );
                if ( is_array( $entry_data ) ) {
                    if ( ! empty( $entry_data['respondent_name'] ) ) {
                        $respondent[] = sanitize_text_field( $entry_data['respondent_name'] );
                    }
                    if ( ! empty( $entry_data['respondent_email'] ) ) {
                        $respondent[] = sanitize_email( $entry_data['respondent_email'] );
                    }
                    if ( ! empty( $entry_data['project_slug'] ) ) {
                        $respondent[] = sanitize_title( $entry_data['project_slug'] );
                    }
                }

                // Get responses for this entry
                $responses = $wpdb->get_results( $wpdb->prepare(
                    "SELECT question_label, question_type, answer_value, answer_label
                     FROM {$wpdb->prefix}estandar_survey_responses
                     WHERE form_id = %d AND entry_id = %d
                     ORDER BY question_index ASC",
                    $survey_id, $entry_id
                ), ARRAY_A );

                if ( empty( $responses ) ) {
                    $token = $wpdb->get_var( $wpdb->prepare(
                        "SELECT session_token FROM {$wpdb->prefix}estandar_form_entries WHERE id = %d", $entry_id
                    ) );
                    if ( $token ) {
                        $responses = $wpdb->get_results( $wpdb->prepare(
                            "SELECT question_label, question_type, answer_value, answer_label
                             FROM {$wpdb->prefix}estandar_survey_responses
                             WHERE form_id = %d AND session_token = %s
                             ORDER BY question_index ASC",
                            $survey_id, $token
                        ), ARRAY_A );
                    }
                }
                ?>
                <div class="resp-card">
                    <div class="resp-header">
                        <span class="resp-num">Respuesta #<?php echo $resp_num++; ?></span>
                        <?php if ( $date_str !== '' ) : ?>
                        <span class="resp-date"><?php echo esc_html( $date_str ); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ( ! empty( $respondent ) ) : ?>
                    <div style="font-size:12px;color:#6b7280;margin-bottom:10px;display:flex;gap:8px;flex-wrap:wrap">
                        <?php foreach ( $respondent as $r_info ) : ?>
                        <span style="background:#f3f4f6;padding:2px 8px;border-radius:4px"><?php echo esc_html( $r_info ); ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <?php foreach ( $responses as $resp ) :
                        $q_type = $resp['question_type'] ?? 'text';
                        $label  = sanitize_text_field( $resp['question_label'] ?? 'Pregunta' );
                    ?>
                    <div class="resp-q"><?php echo esc_html( $label ); ?></div>
                    <div class="resp-a">
                        <?php if ( $q_type === 'matrix' ) :
                            $decoded = json_decode( (string) ( $resp['answer_value'] ?? '{}' ), true );
                            if ( is_array( $decoded ) && ! empty( $decoded ) ) :
                                foreach ( $decoded as $row_id => $score ) :
                                    $row_label = $matrix_row_labels[ $row_id ] ?? $row_id;
                                    echo '<div class="matrix-row"><span class="matrix-label">' . esc_html( $row_label ) . '</span><span class="matrix-score">' . (int) $score . '</span></div>';
                                endforeach;
                            else :
                                echo '<em style="color:#9ca3af">Sin respuesta</em>';
                            endif;
                        elseif ( $q_type === 'checkbox' ) :
                            $decoded = json_decode( (string) ( $resp['answer_value'] ?? '{}' ), true );
                            if ( is_array( $decoded ) && ! empty( $decoded['checked'] ) ) :
                                $parts = [];
                                foreach ( (array) ($decoded['checked'] ?? []) as $val ) {
                                    $parts[] = esc_html( sanitize_text_field( (string) $val ) );
                                }
                                if ( ! empty( $decoded['other_text'] ) ) {
                                    $parts[] = 'Otro: ' . esc_html( sanitize_text_field( $decoded['other_text'] ) );
                                }
                                echo implode( ', ', $parts );
                            else :
                                echo '<em style="color:#9ca3af">Sin respuesta</em>';
                            endif;
                        else :
                            $answer = (string) ( ( $resp['answer_label'] ?? '' ) !== '' ? $resp['answer_label'] : ( $resp['answer_value'] ?? '' ) );
                            echo $answer !== '' ? esc_html( sanitize_text_field( $answer ) ) : '<em style="color:#9ca3af">Sin respuesta</em>';
                        endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach;
        endif; ?>

        <div class="footer">
            Encuestas y experiencia de clientes gestionadas por <strong style="color:#6b7280">Estandar Forms</strong> &mdash; <a href="https://estandar.cl" style="color:#9ca3af;text-decoration:none;">estandar.cl</a>
        </div>
        </div></div></body></html>
        <?php
        exit;
    }

    private static function render_secure_error( string $message ): void {
        nocache_headers();
        header( 'X-Robots-Tag: noindex, nofollow', true );
        http_response_code( 404 );
        $brand_color = function_exists( 'estandar_forms_brand_color' ) ? estandar_forms_brand_color() : '#0069ff';
        ?>
<!doctype html>
<html><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow">
<meta name="viewport" content="width=device-width">
<title>Enlace no disponible</title>
<style>
body{margin:0;padding:0;background:#f3f4f6;color:#1d2327;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
.wrap{max-width:500px;margin:0 auto;padding:60px 20px;text-align:center}
.icon{font-size:48px;margin-bottom:16px;display:block}
h1{font-size:22px;font-weight:700;margin:0 0 12px;color:#111827}
p{font-size:15px;color:#6b7280;line-height:1.6;margin:0 0 24px}
.btn{display:inline-block;background:<?php echo esc_attr( $brand_color ); ?>;color:#fff;text-decoration:none;padding:12px 24px;border-radius:8px;font-weight:600;font-size:14px}
@media(max-width:480px){.wrap{padding:40px 16px}}
</style>
</head>
<body><div class="wrap">
<span class="icon">🔒</span>
<h1>Enlace no disponible</h1>
<p><?php echo esc_html( $message ); ?></p>
<p style="font-size:13px;color:#9ca3af">Si necesitas acceder nuevamente a esta respuesta, solicita un nuevo enlace al administrador.</p>
</div></body></html>
        <?php
        exit;
    }
}
