<?php
if ( ! defined('ABSPATH') ) {
    exit;
}

class Estandar_Forms_Handler {
    private const FEEDBACK_TTL = 600;
    private const DEFAULT_SUCCESS_MESSAGE = 'Gracias. Hemos recibido tu información correctamente. Pronto nos pondremos en contacto contigo.';
    private static $request_feedback_loaded = false;
    private static $request_feedback = null;
    private static $recontact_context = null;
    // FORMS_PIPELINE_OBSERVABILITY_V1: stage/context of the failure that is
    // about to be reported, so the error response can be correlated by
    // debug_ref with a server-side telemetry record.
    private static $failure_context = null;

    public static function init() {
        add_action('admin_post_estandar_form_submit', [__CLASS__, 'handle_submit']);
        add_action('admin_post_nopriv_estandar_form_submit', [__CLASS__, 'handle_submit']);
        add_action('estandar_lead_ingested', [__CLASS__, 'capture_recontact_context'], 10, 4);
    }

    public static function capture_recontact_context( int $lead_id, int $submission_id, bool $is_new, array $payload ): void {
        if ( $is_new ) {
            return;
        }
        $project_slug = sanitize_key( $payload['context']['project_slug'] ?? '' );
        $project_name = '';
        if ( function_exists( 'resolve_project_display_name' ) ) {
            $project_name = resolve_project_display_name( $project_slug, '' );
        }
        self::$recontact_context = [
            'lead_id'        => $lead_id,
            'submission_id'  => $submission_id,
            'project_slug'   => $project_slug,
            'project_name'   => $project_name,
        ];
    }

    public static function handle_submit() {

        global $wpdb;

        /* =====================
           REFERER / REDIRECT
        ====================== */
        $referer = wp_get_referer();
        if ( ! is_string($referer) || $referer === '' ) {
            $referer = home_url('/');
        }

        $redirect_candidate = ! empty($_POST['_redirect'])
            ? (string) wp_unslash($_POST['_redirect'])
            : $referer;
        $redirect = wp_validate_redirect($redirect_candidate, $referer);

        $post = wp_unslash($_POST);
        $form_id = isset($post['estandar_form_id']) ? (int) $post['estandar_form_id'] : 0;

        if ( $form_id <= 0 ) {
            estandar_forms_telemetry_event('error', 'FORMS_SYSTEMIC_VALIDATION_FAILURE', 'Forms rejected submission because form id was invalid.', [
                'stage' => 'form_id',
            ]);
            self::redirect_with_feedback($redirect, 0, 'error', ['Formulario inválido.']);
        }

        if (
            empty($post['estandar_form_nonce']) ||
            ! wp_verify_nonce((string) $post['estandar_form_nonce'], 'estandar_form_submit')
        ) {
            self::redirect_with_feedback($redirect, $form_id, 'error', ['Error de seguridad. Recarga la página e inténtalo nuevamente.']);
        }

        /* =====================
           DEFINICIÓN DEL FORM
        ====================== */
        $form = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}estandar_forms WHERE id = %d",
                $form_id
            )
        );

        if ( ! $form || empty($form->fields_json) ) {
            estandar_forms_telemetry_event('error', 'FORMS_SYSTEMIC_VALIDATION_FAILURE', 'Forms rejected submission because form definition was unavailable.', [
                'form_id' => absint($form_id),
                'stage'   => 'form_lookup',
            ]);
            self::redirect_with_feedback($redirect, $form_id, 'error', ['El formulario no está disponible.']);
        }

        $fields = function_exists('estandar_forms_decode_fields')
            ? estandar_forms_decode_fields((string) $form->fields_json)
            : json_decode((string) $form->fields_json, true);
        if ( ! is_array($fields) ) {
            estandar_forms_telemetry_event('error', 'FORMS_SYSTEMIC_VALIDATION_FAILURE', 'Forms rejected submission because field configuration was invalid.', [
                'form_id' => absint($form_id),
                'stage'   => 'field_config',
            ]);
            self::redirect_with_feedback($redirect, $form_id, 'error', ['La configuración del formulario es inválida.']);
        }
        $form_country = function_exists('estandar_forms_get_form_country')
            ? estandar_forms_get_form_country($form)
            : 'CL';

        // R3 · ADR 0034: guardia temprana de form_type. Los tipos sin pipeline síncrono
        // (survey y futuras experiencias conversacionales) se rechazan ANTES de perseguir
        // spam/rate-limit y ANTES de persistir una entry. Nunca "éxito silencioso".
        $submit_form_type = sanitize_key((string) ($form->form_type ?? 'lead_capture'));
        if ( ! estandar_forms_is_standard_submit_type($submit_form_type) ) {
            estandar_forms_telemetry_event('warning', 'FORMS_UNHANDLED_FORM_TYPE',
                'Forms rejected synchronous submission for a form_type without a standard pipeline.',
                ['form_id' => absint($form_id), 'form_type' => $submit_form_type]);
            self::redirect_with_feedback($redirect, $form_id, 'error',
                ['Este tipo de formulario no se envía por esta vía.'], []);
        }

        if ( self::is_spam_request($post, $form_id) ) {
            self::redirect_with_feedback($redirect, $form_id, 'success', [self::DEFAULT_SUCCESS_MESSAGE]);
        }

        if ( self::is_rate_limited($form_id) ) {
            $minutes = max(1, (int) ceil(self::get_rate_limit_window() / MINUTE_IN_SECONDS));
            self::redirect_with_feedback(
                $redirect,
                $form_id,
                'error',
                [sprintf('Has realizado demasiados envíos. Intenta nuevamente en %d minutos.', $minutes)]
            );
        }

        $require_recaptcha = ! empty($form->require_recaptcha);
        $require_recaptcha = (bool) apply_filters('estandar_forms_require_recaptcha', $require_recaptcha, $form, $post);
        if ( self::is_recaptcha_enabled() && $require_recaptcha && ! self::verify_recaptcha($post) ) {
            self::redirect_with_feedback(
                $redirect,
                $form_id,
                'error',
                ['No pudimos verificar la protección anti-spam. Intenta nuevamente.']
            );
        }

        do_action('estandar_forms_before_validate', $form, $fields, $post);

        /* =====================
           PROCESAMIENTO
        ====================== */
        // Form Engine: estado efectivo server-side (fuente canónica actions_json).
        // Espeja el estado del cliente: campos ocultos no se validan ni persisten,
        // y los overrides require/optional se aplican sobre fields_json.required.
        $engine_config = function_exists('estandar_forms_engine_config')
            ? estandar_forms_engine_config($form->actions_json ?? '')
            : [];
        $engine_state = ! empty($engine_config) && function_exists('estandar_forms_engine_evaluate')
            ? estandar_forms_engine_evaluate($engine_config, $post)
            : ['hidden' => [], 'required' => [], 'lead_quality' => '', 'lead_reason' => ''];

        $data = [];
        $errors = [];
        $field_errors = [];
        $old_input = [];
        $spam_scan_values = [];
        $submit_started_at = isset($post['estandar_ts']) ? (int) $post['estandar_ts'] : 0;
        $seconds_to_submit = $submit_started_at > 0 ? max(0, time() - $submit_started_at) : 0;
        $honeypot_filled = sanitize_text_field((string) ($post['estandar_website'] ?? '')) !== '';

        foreach ( $fields as $field ) {

            if ( ! is_array($field) || empty($field['name']) || empty($field['type']) ) {
                continue;
            }

            $name = sanitize_key((string) $field['name']);
            $type = (string) $field['type'];
            $value = $post[$name] ?? '';
            $required = ! empty($field['required']);
            $label = ! empty($field['label']) ? sanitize_text_field((string) $field['label']) : $name;
            $error_message = ! empty($field['error_message']) ? sanitize_text_field((string) $field['error_message']) : '';
            $conditional_logic = function_exists('estandar_forms_normalize_conditional_logic')
                ? estandar_forms_normalize_conditional_logic($field['conditional_logic'] ?? [])
                : [];

            if (
                ! empty($conditional_logic) &&
                function_exists('estandar_forms_evaluate_condition') &&
                ! estandar_forms_evaluate_condition($conditional_logic, $post)
            ) {
                $data[$name] = $type === 'checkbox' ? 0 : '';
                continue;
            }

            if ( ! empty($engine_state['hidden'][$name]) ) {
                $data[$name] = $type === 'checkbox' ? 0 : '';
                continue;
            }

            if ( array_key_exists($name, $engine_state['required']) ) {
                $required = (bool) $engine_state['required'][$name];
            }

	            $field_error = static function (string $default) use ($errors, $field_errors, $name, $label, $error_message) {
	                $message = $error_message !== ''
	                    ? $error_message
	                    : ( str_contains($default, '%s') ? sprintf($default, $label) : $default );
                $errors[$name] = $message;
                $field_errors[$name] = $message;
                return [$errors, $field_errors];
            };

            if ( $name === '' ) {
                continue;
            }

            switch ( $type ) {

                case 'checkbox':
                    $checked = isset($post[$name]);
                    if ( $required && ! $checked ) {
                        [$errors, $field_errors] = $field_error('Debes aceptar "%s".');
                    }
                    $data[$name] = $checked ? 1 : 0;
                    $old_input[$name] = $data[$name];
                    break;

                case 'file':
                    $has_file = isset($_FILES[$name]) && is_array($_FILES[$name]);
                    if ( $required && ! $has_file ) {
                        [$errors, $field_errors] = $field_error('Debes adjuntar un archivo en "%s".');
                    }
                    if (
                        $has_file &&
                        is_uploaded_file($_FILES[$name]['tmp_name']) &&
                        function_exists('estandar_forms_handle_upload')
                    ) {
	                        $upload = estandar_forms_handle_upload($_FILES[$name]);
	                        if ( ! is_wp_error($upload) ) {
	                            $data[$name] = $upload;
	                        } else {
	                            $message = $upload->get_error_message();
	                            [$errors, $field_errors] = $field_error($message !== '' ? $message : 'No fue posible subir el archivo en "%s".');
	                        }
                    } elseif ( $required ) {
                        [$errors, $field_errors] = $field_error('No fue posible subir el archivo en "%s".');
                    }
                    break;

                case 'textarea':
                    $clean_textarea = sanitize_textarea_field((string) $value);
                    if ( $required && $clean_textarea === '' ) {
                        [$errors, $field_errors] = $field_error('El campo "%s" es obligatorio.');
                    } elseif ( self::is_over_length($clean_textarea, 'textarea', $field) ) {
                        [$errors, $field_errors] = $field_error('El campo "%s" supera el largo permitido.');
                    }
                    $data[$name] = $clean_textarea;
                    $old_input[$name] = $clean_textarea;
                    $spam_scan_values[$name] = $clean_textarea;
                    break;

                case 'email':
                    $clean_email = sanitize_email((string) $value);
                    if ( $required && $clean_email === '' ) {
                        [$errors, $field_errors] = $field_error('El campo "%s" es obligatorio.');
                    }
                    if ( $clean_email !== '' && ! is_email((string) $value) ) {
                        [$errors, $field_errors] = $field_error('El campo "%s" debe ser un correo válido.');
                    } elseif ( self::is_over_length($clean_email, 'email', $field) ) {
                        [$errors, $field_errors] = $field_error('El campo "%s" supera el largo permitido.');
                    }
                    $data[$name] = $clean_email;
                    $old_input[$name] = $clean_email;
                    $spam_scan_values[$name] = $clean_email;
                    break;

                case 'number':
                    $clean_number = trim((string) $value);
                    if ( $required && $clean_number === '' ) {
                        [$errors, $field_errors] = $field_error('El campo "%s" es obligatorio.');
                    } elseif ( $clean_number !== '' && ! is_numeric($clean_number) ) {
                        [$errors, $field_errors] = $field_error('El campo "%s" debe ser numérico.');
                    } elseif ( self::is_over_length($clean_number, 'number', $field) ) {
                        [$errors, $field_errors] = $field_error('El campo "%s" supera el largo permitido.');
                    }
                    $data[$name] = $clean_number;
                    $old_input[$name] = $clean_number;
                    break;

                case 'tel':
                    $clean_tel = preg_replace('/[^0-9+\-\s()]/', '', (string) $value);
                    if ( $required && $clean_tel === '' ) {
                        [$errors, $field_errors] = $field_error('El campo "%s" es obligatorio.');
                    } elseif ( $clean_tel !== '' && function_exists('estandar_forms_validate_phone') && ! estandar_forms_validate_phone($clean_tel, $form_country) ) {
                        [$errors, $field_errors] = $field_error('El campo "%s" no contiene un telefono valido para el pais del formulario.');
                    } elseif ( self::is_over_length($clean_tel, 'tel', $field) ) {
                        [$errors, $field_errors] = $field_error('El campo "%s" supera el largo permitido.');
                    }
                    $data[$name] = sanitize_text_field((string) $clean_tel);
                    $old_input[$name] = $data[$name];
                    $spam_scan_values[$name] = $data[$name];
                    break;

                case 'date':
                    $clean_date = sanitize_text_field((string) $value);
                    if ( $required && $clean_date === '' ) {
                        [$errors, $field_errors] = $field_error('El campo "%s" es obligatorio.');
                    } elseif ( $clean_date !== '' ) {
                        $timestamp = strtotime($clean_date);
                        if ( false === $timestamp ) {
                            [$errors, $field_errors] = $field_error('El campo "%s" tiene una fecha inválida.');
                        }
                    }
                    $data[$name] = $clean_date;
                    $old_input[$name] = $clean_date;
                    break;

                case 'rut':
                    $clean_rut = sanitize_text_field((string) $value);
                    if ( $required && $clean_rut === '' ) {
                        [$errors, $field_errors] = $field_error('El campo "%s" es obligatorio.');
                    }
                    if ( $clean_rut !== '' && function_exists('estandar_validar_rut') && ! estandar_validar_rut($clean_rut) ) {
                        [$errors, $field_errors] = $field_error('El campo "%s" no contiene un RUT válido.');
                    } elseif ( self::is_over_length($clean_rut, 'rut', $field) ) {
                        [$errors, $field_errors] = $field_error('El campo "%s" supera el largo permitido.');
                    }
                    $data[$name] = $clean_rut;
                    $old_input[$name] = $clean_rut;
                    $spam_scan_values[$name] = $clean_rut;
                    break;

                case 'radio':
                case 'select':
                    $clean_value = sanitize_text_field((string) $value);
                    if ( $required && $clean_value === '' ) {
                        [$errors, $field_errors] = $field_error('El campo "%s" es obligatorio.');
                    }

                    $allowed = [];
                    if ( ! empty($field['options']) && is_array($field['options']) ) {
                        foreach ( $field['options'] as $option ) {
                            if ( is_array($option) ) {
                                $allowed[] = sanitize_text_field((string) ($option['value'] ?? ''));
                            } elseif ( is_string($option) ) {
                                $allowed[] = sanitize_text_field($option);
                            }
                        }
                    }

                    if ( $clean_value !== '' && ! empty($allowed) && ! in_array($clean_value, $allowed, true) ) {
                        [$errors, $field_errors] = $field_error('La opción seleccionada en "%s" no es válida.');
                    }

                    $data[$name] = $clean_value;
                    $old_input[$name] = $clean_value;
                    $spam_scan_values[$name] = $clean_value;
                    break;

                default:
                    $clean_value = sanitize_text_field((string) $value);
                    if ( $required && $clean_value === '' ) {
                        [$errors, $field_errors] = $field_error('El campo "%s" es obligatorio.');
                    } elseif ( self::is_over_length($clean_value, $type, $field) ) {
                        [$errors, $field_errors] = $field_error('El campo "%s" supera el largo permitido.');
                    }
                    $data[$name] = $clean_value;
                    $old_input[$name] = $clean_value;
                    $spam_scan_values[$name] = $clean_value;
                    break;
            }
        }

        if (
            isset($data['email'], $data['email_confirmacion']) &&
            is_scalar($data['email']) &&
            is_scalar($data['email_confirmacion']) &&
            sanitize_email((string) $data['email']) !== sanitize_email((string) $data['email_confirmacion'])
        ) {
            $errors['email_confirmacion'] = 'La confirmación de email no coincide.';
            $field_errors['email_confirmacion'] = 'La confirmación de email no coincide.';
        }

        if ( self::contains_spam_content($spam_scan_values, $form_id) ) {
            do_action('estandar_forms_spam_blocked', 'content_rules', $form_id, $post);
            estandar_forms_telemetry_event('warning', 'FORMS_SPAM_BLOCKED', 'Forms blocked a spam submission.', [
                'form_id' => absint($form_id),
                'reason'  => 'content_rules',
            ]);
            self::redirect_with_feedback($redirect, $form_id, 'success', [self::DEFAULT_SUCCESS_MESSAGE]);
        }

	        $errors = apply_filters('estandar_forms_validation_errors', $errors, $form, $fields, $post, $data);
	        if ( is_array($errors) ) {
	            foreach ( $errors as $error_key => $error_message ) {
	                if ( is_string($error_key) && $error_key !== '' && is_string($error_message) ) {
	                    $field_errors[$error_key] = $error_message;
	                }
	            }
	        }

        if ( ! empty($errors) ) {
            self::redirect_with_feedback($redirect, $form_id, 'error', array_values($errors), $old_input, $field_errors);
        }

        // Form Engine: clasificación declarativa (set_lead_quality / set_lead_reason).
        // Se recalcula server-side desde actions_json — el POST del cliente no es autoridad.
        if ( $engine_state['lead_quality'] !== '' ) {
            $data['lead_quality'] = $engine_state['lead_quality'];
        }
        if ( $engine_state['lead_reason'] !== '' ) {
            $data['lead_reason'] = $engine_state['lead_reason'];
        }

        $data = apply_filters('estandar_forms_pre_insert_data', $data, $form, $fields, $post);
        $qualification = null;
        if ( class_exists('Estandar_Forms_Qualification_Engine') ) {
            $qualification = Estandar_Forms_Qualification_Engine::maybe_evaluate($form, $data);
            if ( is_array($qualification) && ! empty($qualification) ) {
                $data['_qualification'] = $qualification;
            }
        }
        $incident_submission = null;
        if ( self::is_incident_form($form) ) {
            $incident_submission = self::prepare_incident_submission($form, $data, $post, $_FILES);
            if ( is_wp_error($incident_submission) ) {
                self::redirect_with_feedback($redirect, $form_id, 'error', [$incident_submission->get_error_message()], $old_input);
            }
            $data['_incidents'] = $incident_submission['incidents'];
        }

	        if ( ! self::validate_submission_token($post, $form_id) ) {
	            self::redirect_with_feedback($redirect, $form_id, 'error', ['Solicitud duplicada o inválida. Intenta nuevamente.'], $old_input);
	        }

	        if ( (string) ($form->context_schema ?? '') !== '' && function_exists('estandar_forms_routing_context') ) {
	            $routing = estandar_forms_routing_context((string) $form->context_schema);
	            if ( ($routing['mode'] ?? '') === 'project_selector' ) {
	                $allowed_slugs  = array_column($routing['selector_projects'] ?? [], 'slug');
	                $submitted_slug = sanitize_title(remove_accents(self::post_value($post, '__project_routing')));

	                if ( $submitted_slug === '' || ! in_array($submitted_slug, $allowed_slugs, true) ) {
	                    estandar_forms_telemetry_event('error', 'FORMS_PROJECT_SELECTOR_UNRESOLVED',
	                        'project_selector submission rejected: submitted slug not in allowed list.',
	                        [ 'submitted' => $submitted_slug, 'allowed' => implode(',', $allowed_slugs) ]
	                    );
	                    self::redirect_with_feedback($redirect, $form_id, 'error', ['Seleccione una opción válida.'], $old_input);
	                }
	                $post['project_slug'] = $submitted_slug;
	            }
	        }

	        $entry_id = self::store_entry($form_id, $data);
        if ( is_wp_error($entry_id) ) {
            estandar_forms_telemetry_event('error', 'FORMS_ENTRY_STORE_FAILURE', $entry_id->get_error_message(), [
                'form_id' => absint($form_id),
            ]);
            self::redirect_with_feedback($redirect, $form_id, 'error', ['No fue posible guardar el formulario. Intenta nuevamente.'], $old_input);
        }

        if ( is_array($incident_submission) ) {
            $saved = self::handle_incident_submission($form, $data, $post, $entry_id, $incident_submission);
            if ( is_wp_error($saved) ) {
                estandar_forms_telemetry_event('error', 'FORMS_INCIDENT_SUBMIT_FAILURE', $saved->get_error_message(), [
                    'form_id'  => absint($form_id),
                    'entry_id' => absint($entry_id),
                ]);
                self::mark_failure_stage('incident_submission', [
                    'form_id'  => absint($form_id),
                    'entry_id' => absint($entry_id),
                    'error'    => sanitize_text_field($saved->get_error_message()),
                ]);
                self::redirect_with_feedback($redirect, $form_id, 'error', ['No fue posible enviar el formulario. Intenta nuevamente.'], $old_input);
            }

            self::mark_submission_token_used($post, $form_id);
            self::redirect_with_feedback($redirect, $form_id, 'success', [self::DEFAULT_SUCCESS_MESSAGE]);
        }

        if ( isset($form->form_type) && (string) $form->form_type === 'email_direct' ) {
            $sent = self::send_direct_email($form, $data, $fields);
            if ( is_wp_error($sent) ) {
                estandar_forms_telemetry_event('error', 'FORMS_DIRECT_EMAIL_FAILURE', $sent->get_error_message(), [
                    'form_id'  => absint($form_id),
                    'entry_id' => absint($entry_id),
                ]);
                self::mark_failure_stage('direct_email', [
                    'form_id'  => absint($form_id),
                    'entry_id' => absint($entry_id),
                    'error'    => sanitize_text_field($sent->get_error_message()),
                ]);
                self::redirect_with_feedback($redirect, $form_id, 'error', ['No fue posible enviar el formulario. Intenta nuevamente.'], $old_input);
            }

            self::mark_submission_token_used($post, $form_id);
            self::redirect_with_feedback($redirect, $form_id, 'success', [self::DEFAULT_SUCCESS_MESSAGE]);
        }

        $form_type = sanitize_key((string) ($form->form_type ?? 'lead_capture'));
        // R3 · ADR 0034: red de seguridad — nunca éxito silencioso. 'general' es
        // sinónimo legacy de 'lead' (starter form). Este punto es inalcanzable para
        // tipos no estándar tras el guard temprano; se mantiene por defensa en profundidad.
        if ( ! in_array($form_type, ['lead', 'lead_capture', 'general', 'experience'], true) ) {
            estandar_forms_telemetry_event('error', 'FORMS_UNHANDLED_FORM_TYPE',
                'Forms reached lead pipeline for a form_type outside the standard set.',
                ['form_id' => absint($form_id), 'form_type' => $form_type]);
            self::mark_submission_token_used($post, $form_id);
            self::redirect_with_feedback($redirect, $form_id, 'error',
                ['Este tipo de formulario no se envía por esta vía.'], []);
        }

	        $payload = self::build_lead_payload($data, $fields, $form_id, $entry_id, $post, $redirect, $honeypot_filled, $seconds_to_submit, (string) ($form->context_schema ?? ''));
	        if ( $form_type === 'experience' && class_exists('Estandar_Experience_Engine') ) {
	            $payload['experience'] = Estandar_Experience_Engine::after_form_submit_snapshot($form, $data);
	        }
        try {
            do_action('estandar_lead_captured', $payload);
        } catch (Throwable $e) {
            $exception_context = [
                'form_id'           => absint($form_id),
                'entry_id'          => absint($entry_id),
                'project_slug'      => sanitize_key((string) ($payload['context']['project_slug'] ?? '')),
                'exception'         => get_class($e),
                'exception_message' => sanitize_text_field($e->getMessage()),
            ];
            $ingested = self::confirmed_ingestion();
            if (is_array($ingested)) {
                // Lead + submission ya COMMITTED en la ingesta canónica: lo que
                // falló fue un listener post-commit (notifier/scoring/webhook/…).
                // Reportar error aquí sería falso negativo e induce reintentos
                // del visitante que generan submissions duplicadas.
                estandar_forms_telemetry_event('error', 'FORMS_POST_INGEST_LISTENER_FAILURE', 'A post-ingestion listener failed after the lead was committed; submission reported as success.', $exception_context + [
                    'lead_id' => absint($ingested['lead_id']),
                ]);
            } else {
                estandar_forms_telemetry_event('error', 'FORMS_LEAD_EMIT_FAILURE', 'Forms failed while emitting lead capture event.', $exception_context);
                self::mark_failure_stage('lead_pipeline_exception', $exception_context);
                self::redirect_with_feedback($redirect, $form_id, 'error', ['No fue posible enviar el formulario. Intenta nuevamente.'], $old_input);
            }
        }

        if (self::leads_ingestion_listening() && !is_array(self::confirmed_ingestion())) {
            // La ingesta canónica corrió sin lanzar excepción pero NO confirmó
            // persistencia (rollback interno, payload rechazado, mutex). Antes
            // este caso se reportaba como éxito: entry huérfana silenciosa sin
            // lead, sin routing y sin notificación.
            $not_confirmed_context = [
                'form_id'      => absint($form_id),
                'entry_id'     => absint($entry_id),
                'project_slug' => sanitize_key((string) ($payload['context']['project_slug'] ?? '')),
            ];
            estandar_forms_telemetry_event('error', 'FORMS_INGESTION_NOT_CONFIRMED', 'Canonical lead ingestion did not confirm persistence; entry stored without submission/lead.', $not_confirmed_context);
            self::mark_failure_stage('lead_ingestion_not_confirmed', $not_confirmed_context);
            self::redirect_with_feedback($redirect, $form_id, 'error', ['No fue posible enviar el formulario. Intenta nuevamente.'], $old_input);
        }

        self::mark_submission_token_used($post, $form_id);
        $success_message = self::DEFAULT_SUCCESS_MESSAGE;
        if ( is_array($qualification) && class_exists('Estandar_Forms_Qualification_Engine') ) {
            $qualification_message = Estandar_Forms_Qualification_Engine::public_message($qualification);
            if ($qualification_message !== '') {
                $success_message = $qualification_message;
            }
        }

        if ( is_array( self::$recontact_context ) ) {
            self::redirect_with_feedback( $redirect, $form_id, 'recontact', [ $success_message ], [], [], self::$recontact_context );
        } else {
            self::redirect_with_feedback($redirect, $form_id, 'success', [$success_message]);
        }
    }

    private static function is_incident_form(object $form): bool {
        return sanitize_key((string) ($form->form_type ?? '')) === 'incident';
    }

    private static function incident_config(object $form): array {
        $config = json_decode((string) ($form->incidents_config ?? ''), true);
        $config = is_array($config) ? $config : [];
        return [
            'min'               => max(0, (int) ($config['min'] ?? 1)),
            'max'               => max(0, (int) ($config['max'] ?? 4)),
            'conditional_field' => sanitize_key((string) ($config['conditional_field'] ?? '')),
            'conditional_value' => sanitize_text_field((string) ($config['conditional_value'] ?? '')),
        ];
    }

    private static function incidents_required(array $config, array $data): bool {
        $field = (string) ($config['conditional_field'] ?? '');
        if ($field === '') {
            return true;
        }
        return sanitize_text_field((string) ($data[$field] ?? '')) === (string) ($config['conditional_value'] ?? '');
    }

    private static function prepare_incident_submission(object $form, array $data, array $post, array $files) {
        if ( ! class_exists('Estandar_Forms_Repeater') ) {
            return new WP_Error('forms_incident_repeater_missing', 'El motor de problemas no está disponible.');
        }

        $config = self::incident_config($form);
        $required = self::incidents_required($config, $data);
        $parsed = Estandar_Forms_Repeater::parse($post, $files);
        $incidents = (array) ($parsed['incidents'] ?? []);

        if ( ! empty($parsed['errors']) ) {
            return new WP_Error('forms_incident_invalid', 'Completa lugar y título de cada problema.');
        }
        if ( $required && count($incidents) < (int) $config['min'] ) {
            return new WP_Error('forms_incident_required', 'Agrega al menos un problema.');
        }
        if ( (int) $config['max'] > 0 && count($incidents) > (int) $config['max'] ) {
            return new WP_Error('forms_incident_max', 'Puedes agregar hasta ' . (int) $config['max'] . ' problemas.');
        }

        return [
            'required'       => $required,
            'incidents'      => $required ? $incidents : [],
            'incident_files' => $required ? (array) ($parsed['incident_files'] ?? []) : [],
        ];
    }

    private static function handle_incident_submission(object $form, array $data, array $post, int $entry_id, array $incident_submission) {
        if ( ! class_exists('Estandar_Incident_Engine') || ! class_exists('Estandar_Forms_Action_Engine') ) {
            return new WP_Error('forms_incident_engine_missing', 'El motor de solicitudes no está disponible.');
        }

        $form_id = absint($form->id ?? 0);
        $request = [
            'form_id'      => $form_id,
            'form_type'    => 'incident',
            'form_context' => sanitize_key((string) ($form->form_context ?? 'inmobiliaria')),
            'request_type' => sanitize_key((string) ($data['tipo_solicitud'] ?? $form->request_type ?? 'postventa')),
            'owner'        => [
                'name'      => trim(self::first_field_value($data, ['nombre', 'name']) . ' ' . self::first_field_value($data, ['apellido', 'apellidos'])),
                'rut'       => self::first_field_value($data, ['rut']),
                'dv'        => self::first_field_value($data, ['dv']),
                'email'     => self::lead_email_value($data, []),
                'phone'     => self::first_field_value($data, ['telefono', 'phone']),
            ],
            'context'      => [
                'project_id'      => self::first_field_value($data, ['project_id', 'proyecto']),
                'project_label'   => self::project_label(self::first_field_value($data, ['project_id', 'proyecto'])),
                'street'          => self::first_field_value($data, ['street', 'calle']),
                'house_number'    => self::first_field_value($data, ['house_number', 'numero_vivienda']),
                'request_label'   => self::first_field_value($data, ['tipo_solicitud']),
                'document_type'   => self::first_field_value($data, ['tipo_documento']),
            ],
            'meta'         => [
                'entry_id'     => $entry_id,
                'data'         => $data,
                'submitted_at' => gmdate('c'),
            ],
            'source_ip'    => self::get_client_ip(),
            'source_ua'    => sanitize_textarea_field((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')),
        ];

        $stored = Estandar_Incident_Engine::store_all($request, (array) $incident_submission['incidents'], (array) $incident_submission['incident_files']);
        if ( is_wp_error($stored) ) {
            return $stored;
        }

        $payload = [
            'request_id'   => (int) $stored['request_id'],
            'request_ref'  => (string) $stored['request_ref'],
            'form_id'      => $form_id,
            'entry_id'     => $entry_id,
            'submitted_at' => gmdate('c'),
            'request'      => $request,
            'incidents'    => self::incidents_with_ids((array) $incident_submission['incidents'], (array) $stored['incidents']),
            'attachments'  => self::attachments_for_request((int) $stored['request_id']),
        ];

        $actions = json_decode((string) ($form->actions_json ?? ''), true);
        $actions = is_array($actions) ? $actions : [];
        // Forma objeto (Form Engine): el pipeline post-submit vive en la clave "actions".
        if ( $actions !== [] && ! array_is_list($actions) ) {
            $actions = isset($actions['actions']) && is_array($actions['actions']) ? $actions['actions'] : [];
        }
        $result = Estandar_Forms_Action_Engine::run($payload, $actions, $form_id);
        return is_wp_error($result) ? $result : $stored;
    }

    private static function project_label(string $project_id): string {
        if ($project_id !== '' && function_exists('estandar_core_get_projects_for_select')) {
            foreach ((array) estandar_core_get_projects_for_select() as $project) {
                if ((string) ($project['value'] ?? '') === $project_id) {
                    return sanitize_text_field((string) ($project['label'] ?? $project_id));
                }
            }
        }
        return sanitize_text_field($project_id);
    }

    private static function incidents_with_ids(array $incidents, array $ids): array {
        foreach ($incidents as $index => $incident) {
            $incidents[$index]['incident_id'] = absint($ids[$index] ?? 0);
        }
        return $incidents;
    }

    private static function attachments_for_request(int $request_id): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, request_id, incident_id, file_name, file_size, mime_type, file_path
                 FROM {$wpdb->prefix}estandar_request_attachments
                 WHERE request_id = %d
                 ORDER BY id ASC",
                $request_id
            ),
            ARRAY_A
        );

        return array_map(static function ($row) {
            return [
                'attachment_id' => absint($row['id'] ?? 0),
                'request_id'    => absint($row['request_id'] ?? 0),
                'incident_id'   => absint($row['incident_id'] ?? 0),
                'file_name'     => sanitize_file_name((string) ($row['file_name'] ?? '')),
                'file_size'     => absint($row['file_size'] ?? 0),
                'mime_type'     => sanitize_mime_type((string) ($row['mime_type'] ?? '')),
                'file_path'     => sanitize_text_field((string) ($row['file_path'] ?? '')),
            ];
        }, (array) $rows);
    }

    public static function get_feedback_from_request(int $form_id = 0) {
        if ( ! self::$request_feedback_loaded ) {
            self::$request_feedback_loaded = true;

            $feedback_id = isset($_GET['ef_feedback'])
                ? sanitize_text_field((string) wp_unslash($_GET['ef_feedback']))
                : '';

            if ( $feedback_id !== '' ) {
                $key = 'estandar_form_feedback_' . $feedback_id;
                $feedback = get_transient($key);
                delete_transient($key);
                self::$request_feedback = is_array($feedback) ? $feedback : null;
            }
        }

        if ( ! is_array(self::$request_feedback) ) {
            return null;
        }

        if ( $form_id > 0 && (int) (self::$request_feedback['form_id'] ?? 0) !== $form_id ) {
            return null;
        }

        return self::$request_feedback;
    }

    /**
     * event_id del Lead de captura para la respuesta AJAX — derivación ÚNICA,
     * siempre server-side (el frontend solo consume este valor).
     *
     *  - estandar-leads ACTIVO: solo desde el lead realmente persistido
     *    (Estandar_Leads_Ingestion::last_ingested(), seteado post-COMMIT). Si
     *    la persistencia falló → '' → el browser NO emite Lead. El uuid de la
     *    fila puede diferir del posteado (regeneración anti-colisión /
     *    recontacto) — por eso jamás se deriva del POST en esta rama.
     *  - estandar-leads AUSENTE (forms standalone, sin CAPI): fallback
     *    estrictamente equivalente desde el uuid v4 posteado — no hay Server
     *    Lead con quien deduplicar, el id solo identifica el evento browser.
     */
    public static function meta_lead_event_id_for_response(): string {
        if ( class_exists( 'Estandar_Leads_Ingestion' ) ) {
            if ( ! method_exists( 'Estandar_Leads_Ingestion', 'last_ingested' ) ) {
                return '';
            }
            $ingested = Estandar_Leads_Ingestion::last_ingested();
            if ( ! is_array( $ingested ) || empty( $ingested['lead_uuid'] ) ) {
                return ''; // sin persistencia confirmada no hay hecho → no hay evento
            }
            if ( ! empty( $ingested['duplicate'] ) ) {
                return ''; // interacción duplicada idempotente: no re-emitir Lead browser event
            }
            return class_exists( 'Estandar_Leads_Meta_Adapter' )
                ? (string) Estandar_Leads_Meta_Adapter::capture_event_id( $ingested['lead_uuid'] )
                : $ingested['lead_uuid'] . ':CAPTURED';
        }

        $posted_uuid = sanitize_text_field( wp_unslash( (string) ( $_POST['lead_uuid'] ?? '' ) ) );
        if ( ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $posted_uuid ) ) {
            return '';
        }
        return strtolower( $posted_uuid ) . ':CAPTURED';
    }

    private static function redirect_with_feedback(
        string $redirect,
        int $form_id,
        string $status,
        array $messages = [],
        array $old_input = [],
        array $field_errors = [],
        array $recontact_context = []
    ): void {
        $messages = array_values(array_filter($messages, 'is_string'));
        $code = self::get_feedback_code($status, $messages, $field_errors);
        $user_message = self::get_user_message($status, $messages, $code);
        $debug_ref = self::create_debug_ref();

        // FORMS_PIPELINE_OBSERVABILITY_V1: hasta ahora debug_ref solo viajaba
        // al cliente — imposible correlacionarlo con un registro del servidor.
        // Todo error de pipeline (etapa marcada) o error residual genérico
        // (submission_error) deja un evento con debug_ref + etapa + contexto.
        // Los errores de validación de campos (user-normal) no se registran.
        if ( $status === 'error' && function_exists('estandar_forms_telemetry_event') && ( is_array(self::$failure_context) || $code === 'submission_error' ) ) {
            estandar_forms_telemetry_event('error', 'FORMS_SUBMISSION_ERROR_RESPONSE', 'Forms returned an error response to the visitor.', array_merge(
                [ 'stage' => 'unknown' ],
                is_array(self::$failure_context) ? self::$failure_context : [],
                [
                    'debug_ref' => $debug_ref,
                    'form_id'   => absint($form_id),
                    'code'      => sanitize_key($code),
                ]
            ));
        }

        if ( self::is_ajax_request() ) {
            $payload = [
                'status'       => $status,
                'code'         => $code,
                'debug_ref'    => $debug_ref,
                'user_message' => $user_message,
                'message'      => $user_message,
                'messages'     => $messages,
                'field_errors' => $field_errors,
                'form_id'      => $form_id,
            ];

            // Contrato CAPTURED: el event_id del Lead browser lo genera el
            // servidor — el frontend NUNCA lo reconstruye. Si el id está
            // ausente, el browser no emite Lead (sin hecho persistido no hay
            // evento). Ver meta_lead_event_id_for_response().
            if ( $status === 'success' || $status === 'recontact' ) {
                $meta_event_id = self::meta_lead_event_id_for_response();
                if ( $meta_event_id !== '' ) {
                    $payload['meta_lead_event_id'] = $meta_event_id;
                }
            }

            if ( $status === 'recontact' && ! empty( $recontact_context ) ) {
                $payload['recontact'] = true;
                $payload['recontact_project'] = $recontact_context['project_name'] ?: $recontact_context['project_slug'];
                wp_send_json_success( $payload );
            }

            if ( $status === 'success' || $status === 'recontact' ) {
                wp_send_json_success($payload);
            }

            wp_send_json_error($payload, 422);
        }

        $feedback_id = wp_generate_uuid4();
        set_transient(
            'estandar_form_feedback_' . $feedback_id,
            [
                'form_id'   => $form_id,
                'status'    => $status,
                'messages'  => $messages,
                'old_input' => $old_input,
                'field_errors' => $field_errors,
            ],
            self::FEEDBACK_TTL
        );

        $target = add_query_arg(
            ['ef_feedback' => $feedback_id],
            remove_query_arg(['sent', 'ef_feedback'], $redirect)
        );

        wp_safe_redirect($target);
        exit;
    }

    private static function build_lead_payload(
        array $data,
        array $fields,
        int $form_id,
        int $entry_id,
        array $post,
        string $landing_url,
        bool $honeypot_filled,
        int $seconds_to_submit,
        string $context_schema = ''
    ): array {
        return [
            'schema_version' => '1.0',
            'source_plugin'  => 'estandar-forms',
            'submitted_at'   => gmdate('c'),
            'contact'        => [
                'name'  => self::first_field_value($data, ['nombre', 'name', 'full_name', 'fullname']),
                'email' => self::lead_email_value($data, $fields),
                'phone' => self::first_field_value($data, ['telefono', 'phone', 'tel', 'celular', 'mobile']),
            ],
            'content'        => [
                'message'        => self::first_field_value($data, ['mensaje', 'message', 'comentario', 'comments']),
                'source_form_id' => $form_id,
            ],
            'attribution'    => [
                'utm_source'            => self::post_value($post, 'utm_source'),
                'utm_medium'            => self::post_value($post, 'utm_medium'),
                'utm_campaign'          => self::post_value($post, 'utm_campaign'),
                'utm_term'              => self::post_value($post, 'utm_term'),
                'utm_content'           => self::post_value($post, 'utm_content'),
                'gclid'                 => self::post_value($post, 'gclid'),
	                'gbraid'                => self::post_value($post, 'gbraid'),
	                'wbraid'                => self::post_value($post, 'wbraid'),
	                'fbclid'                => self::post_value($post, 'fbclid'),
	                'meta_fbc'              => self::post_value($post, 'meta_fbc') ?: self::post_value($post, '_fbc'),
	                'meta_fbp'              => self::post_value($post, 'meta_fbp') ?: self::post_value($post, '_fbp'),
	                'ttclid'                => self::post_value($post, 'ttclid'),
	                'msclkid'               => self::post_value($post, 'msclkid'),
	                'campaign_id'           => self::post_value($post, 'campaign_id'),
	                'adgroup_id'            => self::post_value($post, 'adgroup_id'),
	                'asset_group_id'        => self::post_value($post, 'asset_group_id'),
	                'creative_id'           => self::post_value($post, 'creative_id'),
	                'keyword'               => self::post_value($post, 'keyword'),
	                'match_type'            => self::post_value($post, 'match_type'),
	                'network'               => self::post_value($post, 'network'),
	                'device'                => self::post_value($post, 'device'),
	                'placement'             => self::post_value($post, 'placement'),
	                'campaign_type'         => self::post_value($post, 'campaign_type'),
                'landing_url'           => esc_url_raw(self::post_value($post, 'current_page_url') ?: self::post_value($post, 'landing_url') ?: $landing_url),
                'referrer'              => esc_url_raw(self::post_value($post, 'referrer') ?: (string) ($_SERVER['HTTP_REFERER'] ?? '')),
                'first_touch_timestamp' => self::post_value($post, 'first_touch_timestamp'),
                'last_touch_timestamp'  => self::post_value($post, 'last_touch_timestamp'),
                'session_id'            => function_exists('estandar_core_get_ea_sid') ? estandar_core_get_ea_sid($post, 'session_id') : self::post_value($post, 'session_id'),
            ],
            'context'        => self::lead_context_with_qualification(self::build_lead_context($post, $context_schema), $data),
            'request'        => [
                'ip_address' => self::get_client_ip(),
                'user_agent' => sanitize_text_field((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')),
            ],
            'spam'           => [
                'honeypot_filled'  => $honeypot_filled,
                'seconds_to_submit' => $seconds_to_submit,
            ],
            'meta'           => [
                'source_entry_id'      => $entry_id,
                'lead_uuid'            => self::post_value($post, 'lead_uuid'),
                'sanitized_raw_fields' => $data,
                'qualification'         => is_array($data['_qualification'] ?? null) ? $data['_qualification'] : null,
            ],
        ];
    }

    private static function lead_context_with_qualification(array $context, array $data): array {
        if ( is_array($data['_qualification'] ?? null) ) {
            $context['qualification_summary'] = sanitize_text_field((string) ($data['_qualification']['summary'] ?? ''));
        }
        return $context;
    }

    private static function store_entry(int $form_id, array $data) {
        global $wpdb;

        $table = $wpdb->prefix . 'estandar_form_entries';
        $json = wp_json_encode($data, JSON_UNESCAPED_UNICODE);
        if ( false === $json ) {
            return new WP_Error('forms_entry_json_failed', 'No se pudo serializar la entrada.');
        }

        $ok = $wpdb->insert(
            $table,
            [
                'form_id'   => $form_id,
                'data_json' => $json,
                'status'    => 'active',
            ],
            ['%d', '%s', '%s']
        );

        if ( false === $ok ) {
            return new WP_Error('forms_entry_insert_failed', 'No se pudo guardar la entrada local.');
        }

        return absint($wpdb->insert_id);
    }

    private static function send_direct_email(object $form, array $data, array $fields) {
        $recipients = preg_split('/[\r\n,]+/', (string) ($form->notify_emails ?? ''));
        $recipients = array_values(array_filter(array_map('sanitize_email', (array) $recipients)));

        if ( empty($recipients) ) {
            return new WP_Error('forms_direct_email_missing_recipient', 'Formulario sin correo destinatario.');
        }

        $form_name = sanitize_text_field((string) ($form->name ?? 'Formulario'));
        $subject_template = trim((string) ($form->mail_subject ?? ''));
        $subject = $subject_template !== '' ? $subject_template : 'Nuevo mensaje desde {{form_name}}';
        $subject = str_replace('{{form_name}}', $form_name, $subject);

        $body_lines = [
            'Formulario: ' . $form_name,
            'Fecha: ' . wp_date('Y-m-d H:i:s'),
            '',
        ];

        foreach ( $fields as $field ) {
            if ( ! is_array($field) ) {
                continue;
            }
            $name = sanitize_key((string) ($field['name'] ?? ''));
            if ( $name === '' || ! array_key_exists($name, $data) ) {
                continue;
            }
            $label = sanitize_text_field((string) ($field['label'] ?? $name));
            $value = is_scalar($data[$name]) ? (string) $data[$name] : wp_json_encode($data[$name]);
            $body_lines[] = $label . ': ' . sanitize_textarea_field($value);
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

        $reply_to = self::first_field_value($data, ['email', 'correo', 'mail']);
        if ( is_email($reply_to) ) {
            $headers[] = 'Reply-To: ' . $reply_to;
        }

        return wp_mail($recipients, $subject, implode("\n", $body_lines), $headers)
            ? true
            : new WP_Error('forms_direct_email_send_failed', 'No se pudo enviar el email directo.');
    }

    private static function build_lead_context(array $post, string $context_schema = ''): array {
        $session_id = function_exists('estandar_core_get_ea_sid')
            ? estandar_core_get_ea_sid($post, 'session_id')
            : self::post_value($post, 'session_id');
        $current_page_url = esc_url_raw(self::post_value($post, 'current_page_url'));
        $analytics = [];
        if ($session_id !== '' && function_exists('estandar_analytics_get_attribution_by_session')) {
            $analytics = estandar_analytics_get_attribution_by_session($session_id);
            $analytics = is_array($analytics) ? $analytics : [];
        }
        if ($current_page_url === '') {
            $current_page_url = esc_url_raw((string) ($analytics['first_project_page'] ?? ''));
        }
        $project_value = sanitize_text_field(self::post_value($post, 'project'));
        $project_name = sanitize_text_field(self::post_value($post, 'project_name'));
        if ($project_name === '') {
            $project_name = $project_value;
        }
        $project_area = sanitize_text_field(self::post_value($post, 'project_area'));
        if ($project_area === '') {
            $project_area = $project_name;
        }
        $form_project_slug = sanitize_title(remove_accents(self::post_value($post, 'project_slug')));
        $client_slug = function_exists('estandar_core_get_client_slug')
            ? estandar_core_get_client_slug()
            : self::post_value($post, 'client_slug');
        $client_slug = sanitize_title(remove_accents($client_slug));
        $project_slug = $form_project_slug;

        // Guard: project_selector mode (Routing V2) — resolve project_slug from persisted
        // context_schema (DB), never trust POST project_slug.
        if ( $context_schema !== '' && function_exists('estandar_forms_routing_context') ) {
            $routing = estandar_forms_routing_context($context_schema);
            if ( ($routing['mode'] ?? '') === 'project_selector' ) {
                $allowed_slugs  = array_column( $routing['selector_projects'] ?? [], 'slug' );
                $submitted_slug = sanitize_title( remove_accents( self::post_value($post, '__project_routing') ) );

                if ( $submitted_slug === '' || ! in_array($submitted_slug, $allowed_slugs, true) ) {
                    estandar_forms_telemetry_event('error', 'FORMS_PROJECT_SELECTOR_UNRESOLVED',
                        'project_selector submission rejected: submitted slug not in allowed list.',
                        [ 'submitted' => $submitted_slug, 'allowed' => implode(',', $allowed_slugs) ]
                    );
                    wp_safe_redirect( add_query_arg(
                        [ 'estandar_form_id'    => $form_id ?? 0,
                          'estandar_status'      => 'error',
                          'estandar_messages'    => rawurlencode('Seleccione una opción válida.') ],
                        self::post_value($post, '_redirect') ?: home_url('/')
                    ) );
                    exit;
                }
                $project_slug = $submitted_slug;
            }
        }

        return [
            'project_slug'        => $project_slug,
            'project_slug_source' => $form_project_slug !== '' ? 'form_project_slug' : '',
            'project_area'        => $project_area,
            'project_name'        => $project_name,
            'intent'              => sanitize_key(self::post_value($post, 'intent')),
            'client_slug'         => $client_slug,
            'client_name'         => sanitize_text_field(self::post_value($post, 'client_name')),
            'lead_status'         => sanitize_key(self::post_value($post, 'lead_status')),
            'source_page_url'     => $current_page_url,
            'client_context'      => self::build_client_context_snapshot(),
        ];
    }

    private static function build_client_context_snapshot(): array {
        if ( ! function_exists('estandar_core_get_client_context_record') ) {
            return [];
        }

        $record = estandar_core_get_client_context_record();
        if ( ! is_array($record) || empty($record) ) {
            return [];
        }

        $handoff = is_array($record['handoff'] ?? null) ? $record['handoff'] : [];

        return array_filter([
            'source'             => 'estandar_core_client_context_record',
            'company'            => sanitize_text_field((string) ($record['company'] ?? '')),
            'business'           => sanitize_text_field((string) ($record['business'] ?? '')),
            'services'           => array_values(array_filter(array_map('sanitize_text_field', (array) ($record['services'] ?? [])))),
            'primary_service'    => sanitize_text_field((string) ($record['primary_service'] ?? '')),
            'lead_goal'          => sanitize_text_field((string) ($record['lead_goal'] ?? '')),
            'success_definition' => sanitize_text_field((string) ($record['success_definition'] ?? '')),
            'handoff'            => array_filter([
                'owner'      => sanitize_text_field((string) ($handoff['owner'] ?? '')),
                'fallback'   => sanitize_text_field((string) ($handoff['fallback'] ?? '')),
                'conditions' => array_values(array_filter(array_map('sanitize_text_field', (array) ($handoff['conditions'] ?? [])))),
            ]),
            'rules'              => array_values(array_filter(array_map('sanitize_text_field', (array) ($record['rules'] ?? [])))),
            'client_rules'       => array_values(array_filter(array_map('sanitize_text_field', (array) ($record['client_rules'] ?? [])))),
            'projects'           => array_values(array_filter(array_map('sanitize_text_field', (array) ($record['projects'] ?? [])))),
        ]);
    }

    private static function first_field_value(array $data, array $keys): string {
        foreach ( $keys as $key ) {
            if ( isset($data[$key]) && is_scalar($data[$key]) && trim((string) $data[$key]) !== '' ) {
                return sanitize_text_field((string) $data[$key]);
            }
        }

        return '';
    }

    private static function lead_email_value(array $data, array $fields): string {
        foreach ( $fields as $field ) {
            if ( ! is_array($field) || sanitize_key((string) ($field['type'] ?? '')) !== 'email' ) {
                continue;
            }

            $name = sanitize_key((string) ($field['name'] ?? ''));
            if ( $name === '' || ! isset($data[$name]) || ! is_scalar($data[$name]) ) {
                continue;
            }

            $email = sanitize_email((string) $data[$name]);
            if ( is_email($email) ) {
                return $email;
            }
        }

        foreach ( ['email', 'correo', 'your-email'] as $key ) {
            if ( isset($data[$key]) && is_scalar($data[$key]) ) {
                $email = sanitize_email((string) $data[$key]);
                if ( is_email($email) ) {
                    return $email;
                }
            }
        }

        return '';
    }

    private static function post_value(array $post, string $key): string {
        return isset($post[$key]) && is_scalar($post[$key])
            ? sanitize_text_field((string) $post[$key])
            : '';
    }

    private static function get_feedback_code(string $status, array $messages, array $field_errors): string {
        if ( $status === 'success' || $status === 'recontact' ) {
            return 'success';
        }

        $text = remove_accents(strtolower(implode(' ', array_merge($messages, array_values($field_errors)))));

        if ( str_contains($text, 'correo valido') ) {
            return 'invalid_email';
        }

        if ( str_contains($text, 'obligatorio') || str_contains($text, 'debes ') ) {
            return 'required_fields';
        }

        if ( str_contains($text, 'duplicada') || str_contains($text, 'duplicado') ) {
            return 'duplicate_request';
        }

        if ( str_contains($text, 'seguridad') ) {
            return 'security_error';
        }

        if ( str_contains($text, 'demasiados envios') ) {
            return 'rate_limited';
        }

        if ( str_contains($text, 'anti-spam') || str_contains($text, 'spam') ) {
            return 'spam_check_failed';
        }

        if ( ! empty($field_errors) ) {
            return 'validation_error';
        }

        return 'submission_error';
    }

    private static function get_user_message(string $status, array $messages, string $code): string {
        if ( $status === 'success' || $status === 'recontact' ) {
            return $messages[0] ?? self::DEFAULT_SUCCESS_MESSAGE;
        }

        switch ( $code ) {
            case 'invalid_email':
                return 'El correo no es válido.';
            case 'required_fields':
                return 'Completa los campos obligatorios.';
            case 'duplicate_request':
                return 'Ya se envió este formulario, intenta nuevamente.';
            case 'security_error':
                return 'Error de seguridad. Recarga la página e inténtalo nuevamente.';
            case 'rate_limited':
                return $messages[0] ?? 'Has realizado demasiados envíos. Intenta nuevamente en unos minutos.';
            case 'spam_check_failed':
                return 'No pudimos verificar la protección anti-spam. Intenta nuevamente.';
            case 'validation_error':
                return 'Revisa los campos ingresados.';
        }

        return 'No fue posible enviar el formulario. Intenta nuevamente.';
    }

    private static function create_debug_ref(): string {
        return 'EF-' . strtoupper(substr(str_replace('-', '', wp_generate_uuid4()), 0, 10));
    }

    private static function mark_failure_stage( string $stage, array $context = [] ): void {
        self::$failure_context = array_merge( [ 'stage' => sanitize_key( $stage ) ], $context );
    }

    /**
     * Snapshot de la ingesta canónica confirmada en este request (post-COMMIT),
     * o null si la ingesta no corrió / no confirmó persistencia. Guardado por
     * CORE_OPTIONALITY: sin estandar-leads activo devuelve null.
     */
    private static function confirmed_ingestion(): ?array {
        if ( ! class_exists( 'Estandar_Leads_Ingestion' ) || ! method_exists( 'Estandar_Leads_Ingestion', 'last_ingested' ) ) {
            return null;
        }
        $ingested = Estandar_Leads_Ingestion::last_ingested();
        return ( is_array( $ingested ) && ! empty( $ingested['lead_id'] ) ) ? $ingested : null;
    }

    private static function leads_ingestion_listening(): bool {
        return class_exists( 'Estandar_Leads_Ingestion' )
            && method_exists( 'Estandar_Leads_Ingestion', 'last_ingested' )
            && false !== has_action( 'estandar_lead_captured', [ 'Estandar_Leads_Ingestion', 'capture' ] );
    }

    /**
     * FORMS_ORPHAN_RECONCILIATION_V1 — re-emite una entry persistida que nunca
     * llegó a submission canónica (el caso "entry guardada, lead nulo").
     *
     * Idempotente: si ya existe una submission vinculada a la entry
     * (source_plugin=estandar-forms + source_entry_id), no re-emite. La
     * dedupe por persona de la ingesta (email/phone hash) evita leads
     * duplicados en cualquier caso.
     *
     * Limitaciones documentadas (la entry solo persiste data_json):
     * - Atribución del request original (UTM/click ids/session) no recuperable.
     * - Routing project_selector: la elección vivía en POST → irrecuperable;
     *   solo formularios fixed_project recuperan proyecto desde context_schema.
     *
     * Uso: `wp eval 'var_dump( Estandar_Forms_Handler::reemit_entry( 521 ) );'`
     *
     * @return array|WP_Error Snapshot {lead_id, lead_uuid} o WP_Error.
     */
    public static function reemit_entry( int $entry_id ) {
        global $wpdb;

        $entry_id = absint( $entry_id );
        if ( $entry_id <= 0 ) {
            return new WP_Error( 'forms_entry_invalid', 'Entry id inválido.' );
        }

        $entry = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}estandar_form_entries WHERE id = %d",
            $entry_id
        ) );
        if ( ! $entry ) {
            return new WP_Error( 'forms_entry_not_found', 'Entry no encontrada.' );
        }

        $form = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}estandar_forms WHERE id = %d",
            (int) $entry->form_id
        ) );
        if ( ! $form || empty( $form->fields_json ) ) {
            return new WP_Error( 'forms_form_not_found', 'Formulario no disponible para la entry.' );
        }

        if ( class_exists( 'Estandar_Leads_Ingestion' ) ) {
            $linked = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}es_lead_submissions WHERE source_plugin = %s AND source_entry_id = %d LIMIT 1",
                'estandar-forms',
                $entry_id
            ) );
            if ( $linked ) {
                return new WP_Error( 'forms_entry_already_linked', sprintf( 'La entry ya está vinculada a la submission #%d.', (int) $linked ) );
            }
        }

        $data = json_decode( (string) $entry->data_json, true );
        if ( ! is_array( $data ) ) {
            return new WP_Error( 'forms_entry_data_invalid', 'data_json de la entry es ilegible.' );
        }
        $fields = function_exists( 'estandar_forms_decode_fields' )
            ? estandar_forms_decode_fields( (string) $form->fields_json )
            : json_decode( (string) $form->fields_json, true );
        if ( ! is_array( $fields ) ) {
            return new WP_Error( 'forms_fields_invalid', 'Configuración de campos del formulario inválida.' );
        }

        // Routing recuperable solo en modo fixed_project (vive en el form, no
        // en el POST perdido). Se sintetiza el contexto que el hidden field
        // habría enviado; context_schema se pasa vacío a build_lead_payload
        // para no activar la validación project_selector (POST-dependiente).
        $synthetic_post = [];
        if ( (string) ( $form->context_schema ?? '' ) !== '' && function_exists( 'estandar_forms_routing_context' ) ) {
            $routing = estandar_forms_routing_context( (string) $form->context_schema );
            if ( ( $routing['mode'] ?? '' ) === 'fixed_project' && $routing['project_slug'] !== '' ) {
                $synthetic_post = [
                    'project_slug' => $routing['project_slug'],
                    'project_name' => $routing['project_name'],
                    'project_area' => $routing['project_area'],
                    'intent'       => $routing['intent'],
                ];
            }
        }

        $payload = self::build_lead_payload( $data, $fields, (int) $entry->form_id, $entry_id, $synthetic_post, '', false, 0, '' );
        // Reconciliación fuera del request original: sin señales de timing ni
        // user agent del visitante — su ausencia no debe marcar suspect.
        $payload['spam'] = [ 'honeypot_filled' => false, 'seconds_to_submit' => null ];
        if ( (string) ( $payload['request']['user_agent'] ?? '' ) === '' ) {
            $payload['request']['user_agent'] = 'estandar-forms-reconciliation';
        }
        $original_ts = strtotime( (string) ( $entry->created_at ?? '' ) );
        if ( $original_ts ) {
            $payload['submitted_at'] = gmdate( 'c', $original_ts );
        }
        $payload['meta']['reconciliation'] = true;

        do_action( 'estandar_lead_captured', $payload );

        $ingested = self::confirmed_ingestion();
        if ( is_array( $ingested ) ) {
            return $ingested;
        }
        return new WP_Error( 'forms_reconciliation_not_confirmed', 'La ingesta canónica no confirmó persistencia para la entry re-emitida.' );
    }

    private static function is_ajax_request(): bool {
        return isset($_POST['estandar_ajax']) && sanitize_text_field((string) wp_unslash($_POST['estandar_ajax'])) === '1';
    }

    private static function validate_submission_token(array $post, int $form_id): bool {
        $token = isset($post['_estandar_token'])
            ? sanitize_text_field((string) $post['_estandar_token'])
            : '';

        if ( $token === '' ) {
            return ! self::is_ajax_request();
        }

        if ( ! preg_match('/^[A-Za-z0-9-]{8,128}$/', $token) ) {
            return false;
        }

        $key = 'estandar_form_submit_' . md5($form_id . '|' . $token);
        return ! get_transient($key);
    }

    private static function mark_submission_token_used(array $post, int $form_id): void {
        $token = isset($post['_estandar_token'])
            ? sanitize_text_field((string) $post['_estandar_token'])
            : '';

        if ( $token === '' || ! preg_match('/^[A-Za-z0-9-]{8,128}$/', $token) ) {
            return;
        }

        $key = 'estandar_form_submit_' . md5($form_id . '|' . $token);
        set_transient($key, 1, MINUTE_IN_SECONDS);
    }

    private static function is_spam_request(array $post, int $form_id): bool {
        $honeypot = sanitize_text_field((string) ($post['estandar_website'] ?? ''));
        if ( $honeypot !== '' ) {
            do_action('estandar_forms_spam_blocked', 'honeypot', $form_id, $post);
            estandar_forms_telemetry_event('warning', 'FORMS_SPAM_BLOCKED', 'Forms blocked a spam submission.', [
                'form_id' => absint($form_id),
                'reason'  => 'honeypot',
            ]);
            return true;
        }

        $timestamp = isset($post['estandar_ts']) ? (int) $post['estandar_ts'] : 0;
        $minimum_seconds = (int) apply_filters('estandar_forms_min_submit_seconds', 3);
        if ( $timestamp <= 0 || (time() - $timestamp) < $minimum_seconds ) {
            do_action('estandar_forms_spam_blocked', 'submit_speed', $form_id, $post);
            estandar_forms_telemetry_event('warning', 'FORMS_SPAM_BLOCKED', 'Forms blocked a spam submission.', [
                'form_id' => absint($form_id),
                'reason'  => 'submit_speed',
            ]);
            return true;
        }

        return false;
    }

    private static function contains_spam_content(array $values, int $form_id): bool {
        $max_urls = (int) apply_filters('estandar_forms_spam_max_urls', 2, $form_id);
        $keywords = (array) apply_filters(
            'estandar_forms_spam_keywords',
            [],
            $form_id
        );
        $keywords = array_filter(array_map('strtolower', array_map('trim', $keywords)));

        foreach ( $values as $value ) {
            if ( ! is_string($value) || $value === '' ) {
                continue;
            }

            $plain = strtolower(wp_strip_all_tags($value));
            if ( preg_match_all('/https?:\/\/|www\./i', $plain) > $max_urls ) {
                return true;
            }

            if ( preg_match('/(.)\1{11,}/u', $plain) ) {
                return true;
            }

            foreach ( $keywords as $keyword ) {
                if ( $keyword !== '' && str_contains($plain, $keyword) ) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function is_over_length(string $value, string $type, array $field): bool {
        $default_limit = $type === 'textarea' ? 5000 : 255;
        if ( in_array($type, ['tel', 'rut', 'date', 'number'], true) ) {
            $default_limit = 32;
        }

        $limit = isset($field['max_length']) ? (int) $field['max_length'] : $default_limit;
        $limit = (int) apply_filters('estandar_forms_max_length', $limit, $type, $field);

        return $limit > 0 && strlen($value) > $limit;
    }

    private static function is_rate_limited(int $form_id): bool {
        $ip = self::get_client_ip();
        if ( $ip === '' ) {
            return false;
        }

        $window = self::get_rate_limit_window();
        $max_attempts = (int) apply_filters('estandar_forms_rate_limit_max_attempts', 5, $form_id, $ip);
        $max_attempts = max(1, $max_attempts);

        $key = 'estandar_forms_rate_limit_' . md5($form_id . '|' . $ip);
        $attempts = (int) get_transient($key);
        $attempts++;
        set_transient($key, $attempts, $window);

        return $attempts > $max_attempts;
    }

    private static function get_rate_limit_window(): int {
        $window = (int) apply_filters('estandar_forms_rate_limit_window', 10 * MINUTE_IN_SECONDS);
        return max(MINUTE_IN_SECONDS, $window);
    }

    private static function get_client_ip(): string {
        $raw_ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        $ip = sanitize_text_field($raw_ip);
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    private static function is_recaptcha_enabled(): bool {
        return defined('ESTANDAR_FORMS_RECAPTCHA_SITE_KEY')
            && defined('ESTANDAR_FORMS_RECAPTCHA_SECRET_KEY')
            && ESTANDAR_FORMS_RECAPTCHA_SITE_KEY
            && ESTANDAR_FORMS_RECAPTCHA_SECRET_KEY;
    }

    private static function verify_recaptcha(array $post): bool {
        $token = sanitize_text_field((string) ($post['estandar_recaptcha_token'] ?? ''));
        if ( $token === '' ) {
            return false;
        }

        $request = wp_remote_post(
            'https://www.google.com/recaptcha/api/siteverify',
            [
                'timeout' => 8,
                'body'    => [
                    'secret'   => (string) ESTANDAR_FORMS_RECAPTCHA_SECRET_KEY,
                    'response' => $token,
                    'remoteip' => self::get_client_ip(),
                ],
            ]
        );

        if ( is_wp_error($request) ) {
            return false;
        }

        $body = json_decode((string) wp_remote_retrieve_body($request), true);
        if ( ! is_array($body) || empty($body['success']) ) {
            return false;
        }

        $minimum_score = (float) apply_filters('estandar_forms_recaptcha_min_score', 0.5);
        $score = isset($body['score']) ? (float) $body['score'] : 0.0;
        $action = isset($body['action']) ? sanitize_text_field((string) $body['action']) : '';
        $expected_action = (string) apply_filters('estandar_forms_recaptcha_action', 'estandar_form_submit');

        if ( $action !== '' && $expected_action !== '' && $action !== $expected_action ) {
            return false;
        }

        return $score >= $minimum_score;
    }
}

Estandar_Forms_Handler::init();
