<?php

if (!defined('ABSPATH')) {
    exit;
}

class Estandar_Leads_Feedback
{
    public static function init()
    {
        add_action('init', array(__CLASS__, 'maybe_handle'));
    }

    public static function maybe_handle()
    {
        if (empty($_GET['estandar_feedback'])) {
            return;
        }

        $token = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';
        $record = self::record($token);
        if (!$record) {
            self::telemetry('warning', 'LEADS_FEEDBACK_INVALID_TOKEN', 'Feedback link rejected because token is invalid or expired.', array(
                'has_token' => $token !== '',
            ));
            self::message('Enlace no disponible', 'Este enlace no es valido o ya expiro.');
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            self::post($record);
        }

        self::mark_seen($record->token_id);
        self::record_operator_feedback_event($record, 'operator_feedback_started');
        self::form($record, $token);
    }

    private static function post($record)
    {
        global $wpdb;

        if (!isset($_POST['estandar_leads_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['estandar_leads_nonce'])), 'estandar_leads_feedback_' . $record->token_id)) {
            self::message('No pudimos confirmar', 'Vuelve a abrir el enlace e intentalo nuevamente.');
        }

        $contact_action = self::sanitize_contact_action($_POST['contact_action'] ?? '');
        $conversation = isset($_POST['conversation_happened']) ? sanitize_key(wp_unslash($_POST['conversation_happened'])) : '';
        $target_fit = isset($_POST['is_valid_lead']) ? sanitize_key(wp_unslash($_POST['is_valid_lead'])) : '';
        $commercial_result = isset($_POST['commercial_result']) ? sanitize_key(wp_unslash($_POST['commercial_result'])) : '';
        $more_like_this = isset($_POST['would_buy_again']) ? sanitize_key(wp_unslash($_POST['would_buy_again'])) : '';
        self::normalize_minimal_feedback($contact_action, $conversation, $target_fit, $commercial_result, $more_like_this);

        $need_value = self::sanitize_need_value($_POST['primary_need'] ?? '', $_POST['primary_need_text'] ?? '');
        $missing_context = self::sanitize_context_gap_values($_POST['context_gap'] ?? 'NO');
        $comment = isset($_POST['reason']) ? sanitize_textarea_field(wp_unslash($_POST['reason'])) : '';
        $_POST['reason'] = self::compose_feedback_notes($need_value, $missing_context['labels'], $comment);

        $is_valid_raw = isset($_POST['is_valid_lead']) ? sanitize_key(wp_unslash($_POST['is_valid_lead'])) : '';
        $is_valid_lead = $is_valid_raw === 'yes' ? 1 : ($is_valid_raw === 'no' ? 0 : null);
        $invalid_reason = isset($_POST['invalid_reason']) ? strtoupper(sanitize_key(wp_unslash($_POST['invalid_reason']))) : '';
        $would_buy_again = isset($_POST['would_buy_again']) ? strtoupper(sanitize_key(wp_unslash($_POST['would_buy_again']))) : '';
        $sales_stage = isset($_POST['sales_stage']) ? strtoupper(sanitize_key(wp_unslash($_POST['sales_stage']))) : '';
        $status = self::status_from_sales_stage($sales_stage, $is_valid_lead);
	        $reason  = isset($_POST['reason']) ? sanitize_textarea_field(wp_unslash($_POST['reason'])) : '';
	        $context_gap = Estandar_Leads_Repository::sanitize_context_gap($missing_context['primary']);
	        $context_gap_meta = Estandar_Leads_Repository::context_gap_metadata($context_gap);
	        $contact_result = self::sanitize_contact_result($_POST['contact_result'] ?? '');
        $minimal_stop = ($contact_action === 'none' || $conversation === 'no');
        if ($minimal_stop) {
            $status = (string) ($record->status ?: 'NEW');
            $sales_stage = '';
        }
        if ($is_valid_lead === null || !in_array($would_buy_again, array_keys(self::would_buy_again_labels()), true) || (!$minimal_stop && !in_array($sales_stage, self::all_valid_sales_stage_keys(), true))) {
            self::message('Falta una respuesta', 'Completa las preguntas obligatorias.');
        }
        // invalid_reason is optional — operator may leave it blank even when marking as invalid.
        if ($is_valid_lead === 0 && $invalid_reason !== '' && !in_array($invalid_reason, array_keys(self::invalid_reason_labels()), true)) {
            $invalid_reason = ''; // Silently discard unrecognized value.
        }
        if ($is_valid_lead === 1) {
            $invalid_reason = '';
        }
        $quality = isset($_POST['quality']) ? strtoupper(sanitize_key(wp_unslash($_POST['quality']))) : '';
        if (!in_array($quality, Estandar_Leads_Email::qualities(), true)) {
            $quality = '';
        }
        if (!in_array($status, Estandar_Leads_Normalizer::statuses(), true) || (!$minimal_stop && $status === 'NEW')) {
            self::message('Falta una respuesta', 'Selecciona una opcion.');
        }

        if ($record->feedback_completed && $record->status === $status) {
            self::message('Sin cambios', 'Este lead ya fue clasificado con ese estado.');
        }

        if ($record->feedback_completed && $record->status !== $status && empty($_POST['confirm_override'])) {
            self::confirm($record, $status, $reason, array(
                'is_valid_lead' => $is_valid_lead,
                'invalid_reason' => $invalid_reason,
                'would_buy_again' => $would_buy_again,
                'quality' => $quality,
                'sales_stage' => $sales_stage,
                'context_gap' => $context_gap,
                'contact_action' => $contact_action,
                'contact_result' => $contact_result,
            ));
        }

        $now = gmdate('Y-m-d H:i:s');
        $from = $record->status;
        $event_type = $record->feedback_completed && $from !== $status ? 'status_overridden' : 'status_changed';
        $metadata = array();
        if ($event_type === 'status_overridden') {
            $metadata = array(
                'previous_operator_id' => (int) $record->latest_operator_id,
                'previous_status' => $from,
                'new_operator_id' => (int) $record->operator_id,
                'new_status' => $status,
            );
        }

        $lead_data = array(
            'status'              => $status,
            'feedback_completed'  => 1,
            'first_feedback_at'   => $record->first_feedback_at ?: $now,
            'latest_feedback_at'  => $now,
            'latest_operator_id'  => $record->operator_id ?: null,
            'latest_feedback_reason' => $reason,
            'last_classified_at'  => $now,
            'last_classification_operator_id' => $record->operator_id ?: null,
            'is_valid_lead'       => $is_valid_lead,
            'invalid_reason'      => $invalid_reason,
            'would_buy_again'     => $would_buy_again,
            'sales_stage'         => $sales_stage,
            'updated_at'          => $now,
        );
        if ($quality !== '') {
            $lead_data['quality'] = $quality;
        }
        $lead_updated = $wpdb->update($wpdb->prefix . 'es_leads', $lead_data, array('id' => $record->lead_id));
	        if ($lead_updated === false) {
            self::telemetry('error', 'LEADS_FEEDBACK_PERSISTENCE_FAILED', 'Feedback status update failed.', array(
                'lead_id' => absint($record->lead_id),
                'operator_id' => absint($record->operator_id),
                'target_status' => $status,
                'stage' => 'lead_update',
                'db_error' => sanitize_text_field($wpdb->last_error),
            ));
            self::message('No pudimos guardar', 'Vuelve a intentarlo mas tarde.');
	        }

	        self::maybe_log_contact_state($record, $contact_action, $contact_result, $reason, $context_gap_meta);

        // ── IEE-Q: recálculo de calidad con evidencia estructurada del operador ──
        // LEAD_QUALITY_RECALC_V1 (caso AK #94). Convierte el feedback libre en
        // evidencia estructurada (contactability + reason), recalcula las
        // dimensiones afectadas con precedencia humana y persiste provenance.
        // ADITIVO: si core no tiene el motor, se omite sin cambiar el flujo.
        self::maybe_recalculate_quality($record, $contact_result, $reason, $quality, $is_valid_lead, $sales_stage, $invalid_reason);
	
	        $token_updated = $wpdb->update($wpdb->prefix . 'es_feedback_tokens', array(
            'used_at' => $record->used_at ?: $now,
            'updated_at' => $now,
        ), array('id' => $record->token_id));
        if ($token_updated === false) {
            self::telemetry('error', 'LEADS_FEEDBACK_PERSISTENCE_FAILED', 'Feedback token update failed.', array(
                'lead_id' => absint($record->lead_id),
                'operator_id' => absint($record->operator_id),
                'stage' => 'token_update',
                'db_error' => sanitize_text_field($wpdb->last_error),
            ));
            self::message('No pudimos guardar', 'Vuelve a intentarlo mas tarde.');
        }

        $event_id = Estandar_Leads_Repository::insert_event($record->lead_id, $event_type, array(
            'operator_id' => $record->operator_id,
            'from_status' => $from,
            'to_status' => $status,
            'reason' => $reason,
            'metadata' => array_merge($metadata, array(
                'is_valid_lead' => $is_valid_lead,
                'invalid_reason' => $invalid_reason,
                'would_buy_again' => $would_buy_again,
                'lead_quality' => $quality,
                'sales_stage' => $sales_stage,
                'context_gap' => $context_gap_meta['context_gap'],
                'context_gap_label' => $context_gap_meta['context_gap_label'],
                'context_gap_question' => $context_gap_meta['context_gap_question'],
                'conversion_signal' => self::conversion_signal($is_valid_lead, $would_buy_again, $quality, $sales_stage),
            )),
            'ip_address' => sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent' => sanitize_textarea_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
        ));
        if (!$event_id) {
            self::telemetry('error', 'LEADS_FEEDBACK_PERSISTENCE_FAILED', 'Feedback event insert failed.', array(
                'lead_id' => absint($record->lead_id),
                'operator_id' => absint($record->operator_id),
                'event_type' => $event_type,
                'stage' => 'event_insert',
            ));
            self::message('No pudimos guardar', 'Vuelve a intentarlo mas tarde.');
        }
        self::record_operator_feedback_event($record, 'operator_feedback_completed');

        // BUG-001: auditar cambio de calidad en es_lead_events.
        $previous_quality = (string) ($record->quality ?? '');
        if ($quality !== '' && $quality !== $previous_quality) {
            Estandar_Leads_Repository::insert_event($record->lead_id, 'quality_changed', array(
                'operator_id' => $record->operator_id ?: null,
                'reason'      => $reason,
                'metadata'    => array(
                    'from_quality' => $previous_quality,
                    'to_quality'   => $quality,
                    'context_gap'  => $context_gap_meta['context_gap'],
                    'context_gap_label' => $context_gap_meta['context_gap_label'],
                    'context_gap_question' => $context_gap_meta['context_gap_question'],
                    'source'       => 'operator_feedback_token',
                ),
                'ip_address'  => sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? ''),
                'user_agent'  => sanitize_textarea_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
            ));
        }

        // Write to immutable classification history.
        $wpdb->insert($wpdb->prefix . 'es_lead_classifications', array(
            'lead_id'          => absint($record->lead_id),
            'operator_id'      => $record->operator_id ? absint($record->operator_id) : null,
            'token_id'         => absint($record->token_id),
            'is_valid_lead'    => $is_valid_lead,
            'invalid_reason'   => $invalid_reason,
            'would_buy_again'  => $would_buy_again,
            'previous_quality' => $previous_quality,
            'quality'          => $quality,
            'sales_stage'      => $sales_stage,
            'status'           => $status,
            'contacted'        => 0,
            'notes'            => $reason,
            'classified_at'    => $now,
        ));

        // Emit status change event. Subscribers (OCI, analytics, etc.) hook here.
        // Do NOT add direct calls to specific integrations below this line.
        do_action( 'estandar_lead_status_changed', $record->lead_id, $from, $status, $now );

        if ($event_type === 'status_overridden') {
            self::telemetry('info', 'LEADS_FEEDBACK_OVERRIDE', 'Lead feedback status was overridden.', array(
                'lead_id' => absint($record->lead_id),
                'operator_id' => absint($record->operator_id),
                'from_status' => sanitize_key($from),
                'to_status' => sanitize_key($status),
            ));
        }

        self::message('Gracias', 'Tu respuesta quedo guardada.');
    }

    private static function record($token)
    {
        global $wpdb;

        error_log(
            '[FEEDBACK_RECORD_START] token_len=' . strlen($token) .
            ' token_prefix=' . substr($token,0,12)
        );

        if ($token === '') {
            error_log( '[FEEDBACK_FAIL_EMPTY_TOKEN]' );
            return null;
        }

        $hash = Estandar_Leads_Tokens::hash($token);
        error_log(
            '[FEEDBACK_HASH] hash_prefix=' . substr($hash,0,12)
        );

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT t.id AS token_id, t.lead_id, t.operator_id, t.token_hash, t.expires_at, t.used_at,
                l.status, l.quality, l.name, l.email, l.phone, l.project_slug, l.project_area, l.feedback_completed, l.first_feedback_at, l.latest_operator_id,
                s.message, s.context_json, s.scoring_json, s.chatbot_json, s.form_fields_json,
                o.name AS operator_name,
                prev.name AS latest_operator_name
            FROM {$wpdb->prefix}es_feedback_tokens t
            INNER JOIN {$wpdb->prefix}es_leads l ON l.id = t.lead_id
            LEFT JOIN {$wpdb->prefix}es_lead_submissions s ON s.id = (
                SELECT s2.id FROM {$wpdb->prefix}es_lead_submissions s2
                WHERE s2.lead_id = l.id
                ORDER BY s2.submitted_at DESC, s2.id DESC
                LIMIT 1
            )
            LEFT JOIN {$wpdb->prefix}es_operators o ON o.id = t.operator_id
            LEFT JOIN {$wpdb->prefix}es_operators prev ON prev.id = l.latest_operator_id
            WHERE t.token_hash = %s
            LIMIT 1",
            $hash
        ));

        error_log(
            '[FEEDBACK_DB] found=' . ($row ? 'YES' : 'NO')
        );

        if (!$row) {
            error_log( '[FEEDBACK_FAIL_HASH_NOT_FOUND]' );
            return null;
        }

        if (!hash_equals($row->token_hash, $hash)) {
            error_log( '[FEEDBACK_FAIL_HASH_MISMATCH]' );
            return null;
        }

        if (strtotime($row->expires_at . ' UTC') < time()) {
            error_log( '[FEEDBACK_FAIL_EXPIRED]' );
            return null;
        }

        error_log( '[FEEDBACK_SUCCESS]' );
        return $row;
    }

    private static function mark_seen($token_id)
    {
        global $wpdb;

        $now = gmdate('Y-m-d H:i:s');
        $wpdb->update($wpdb->prefix . 'es_feedback_tokens', array(
            'last_seen_at' => $now,
            'updated_at' => $now,
        ), array('id' => absint($token_id)));
    }

    private static function record_operator_feedback_event($record, string $event_type): void
    {
        global $wpdb;

        $lead_id = absint($record->lead_id ?? 0);
        if ($lead_id < 1) {
            return;
        }

        $operator_id = absint($record->operator_id ?? 0);
        $exists = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}es_lead_events
             WHERE lead_id = %d AND event_type = %s AND operator_id = %d
             LIMIT 1",
            $lead_id,
            sanitize_key($event_type),
            $operator_id
        ));
        if ($exists > 0) {
            return;
        }

        Estandar_Leads_Repository::insert_event($lead_id, $event_type, array(
            'operator_id' => $operator_id,
            'metadata' => array(
                'source' => 'feedback_token',
                'token_id' => absint($record->token_id ?? 0),
                'project_slug' => sanitize_key((string) ($record->project_slug ?? '')),
            ),
            'ip_address' => sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent' => sanitize_textarea_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
        ));
    }

    private static function form($lead, $token)
    {
        $context_rows = self::evaluation_context_rows($lead);
        $invalid_reason_labels = self::invalid_reason_labels();
        $would_buy_again_labels = self::would_buy_again_labels();
        $sales_stage_labels = self::sales_stage_labels();
        $context_gap_template = Estandar_Leads_Repository::context_gap_template();
        $need_options = self::primary_need_options($lead);
        $latest_context_gap = Estandar_Leads_Repository::latest_context_gap(absint($lead->lead_id ?? 0));
        $context_rows['Contexto faltante'] = !empty($latest_context_gap)
            ? (string) ($latest_context_gap['label'] ?? Estandar_Leads_Repository::context_gap_label('NO'))
            : 'Sin registro';
        require ESTANDAR_LEADS_PATH . 'templates/feedback-page.php';
        exit;
    }

    private static function confirm($lead, $new_status, $reason, $signals = array())
    {
        $labels = Estandar_Leads_Normalizer::status_labels();
        $old_label = $labels[$lead->status] ?? $lead->status;
        $new_label = $labels[$new_status] ?? $new_status;
        $same_operator = (int) $lead->latest_operator_id === (int) $lead->operator_id;
        $previous_operator = $lead->latest_operator_name ?: 'otro operador';
        require ESTANDAR_LEADS_PATH . 'templates/feedback-confirm.php';
        exit;
    }

    private static function message($title, $message)
    {
        require ESTANDAR_LEADS_PATH . 'templates/feedback-message.php';
        exit;
    }

    private static function telemetry($severity, $code, $message, $context = array())
    {
        try {
            do_action('estandar_telemetry_event', array(
                'source_plugin' => 'estandar-leads',
                'type' => 'business_event',
                'severity' => $severity,
                'code' => $code,
                'message' => $message,
                'context' => is_array($context) ? $context : array(),
            ));
        } catch (Throwable $e) {
            return;
        }
    }


    private static function evaluation_context_rows($lead): array
    {
        $fields = self::decode_json($lead->form_fields_json ?? '');

        $rows = array();
        $base_name = trim((string) ($lead->name ?? ''));
        if ($base_name === '') {
            foreach (array('nombre', 'name', 'first_name', 'firstname', 'your-name') as $name_key) {
                if (!empty($fields[$name_key]) && is_scalar($fields[$name_key])) {
                    $base_name = trim((string) $fields[$name_key]);
                    break;
                }
            }
        }
        if ($base_name !== '') {
            $rows['Nombre'] = method_exists('Estandar_Leads_Email', 'full_lead_name')
                ? Estandar_Leads_Email::full_lead_name($base_name, $fields)
                : $base_name;
        }
        if (!empty($lead->email)) {
            $rows['Correo'] = $lead->email;
        }
        if (!empty($lead->phone)) {
            $rows['Teléfono'] = $lead->phone;
        }
        $project = resolve_project_display_name( $lead->project_slug ?? '', $lead->project_area ?? '' );
        if ($project !== '') {
            $rows['Proyecto'] = $project;
        }

        $context = self::decode_json($lead->context_json ?? '');
        foreach (array('requirement' => 'Requerimiento', 'city' => 'Ciudad', 'intent' => 'Intención') as $key => $label) {
            if (!empty($context[$key])) {
                $rows[$label] = $context[$key];
            }
        }

        // Name/surname already merged into the Nombre row above.
        $name_keys = array('nombre', 'name', 'first_name', 'firstname', 'your-name', 'apellido', 'apellidos', 'last_name', 'lastname', 'surname');
        $email_keys = array('email', 'correo', 'e-mail', 'mail', 'your-email');
        $phone_keys = array('telefono', 'phone', 'tel', 'celular', 'telephone', 'your-phone');
        $lead_email = strtolower(trim((string) ($lead->email ?? '')));
        $lead_phone = preg_replace('/\D+/', '', (string) ($lead->phone ?? ''));

        foreach ($fields as $key => $value) {
            if (!self::is_visible_evaluation_field($key, $value)) {
                continue;
            }
            $clean_key = sanitize_key((string) $key);
            if (in_array($clean_key, $name_keys, true)) {
                continue;
            }
            $scalar = is_scalar($value) ? trim((string) $value) : '';
            if ($lead_email !== '' && in_array($clean_key, $email_keys, true) && strtolower($scalar) === $lead_email) {
                continue;
            }
            if (in_array($clean_key, $phone_keys, true)) {
                $digits = preg_replace('/\D+/', '', $scalar);
                if ($lead_phone !== '' && $digits !== ''
                    && ($digits === $lead_phone
                        || (strlen($digits) >= 6 && strlen($lead_phone) >= 6
                            && (substr($lead_phone, -strlen($digits)) === $digits
                                || substr($digits, -strlen($lead_phone)) === $lead_phone)))) {
                    continue;
                }
            }
            $rows[self::field_label($key)] = $value;
        }

        if (!empty($lead->message)) {
            $rows['Mensaje'] = $lead->message;
        }

        return array_slice($rows, 0, 12, true);
    }

    private static function is_visible_evaluation_field($key, $value): bool
    {
        $key = sanitize_key((string) $key);
        $value = is_scalar($value) ? trim((string) $value) : '';
        if ($key === '' || $value === '') {
            return false;
        }
        $blocked = array(
            'action', 'form_id', 'estandar_form_id', 'estandar_form_nonce', '_estandar_token', '_redirect',
            'estandar_ajax', 'estandar_ts', 'lead_id', 'lead_uuid', 'lead_status', 'session_id',
            'current_page_url', 'landing_url', 'referrer', 'first_touch_timestamp', 'last_touch_timestamp',
            'gclid', 'gbraid', 'wbraid', 'fbclid', 'ttclid', 'nonce', '_wpnonce',
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        );
        if (in_array($key, $blocked, true)) {
            return false;
        }
        foreach (array('token', 'nonce', 'tracking_', 'conversation_', 'internal') as $needle) {
            if (strpos($key, $needle) !== false) {
                return false;
            }
        }
        return true;
    }

    private static function decode_json($json): array
    {
        $data = is_string($json) && $json !== '' ? json_decode($json, true) : array();
        return is_array($data) ? $data : array();
    }

    private static function field_label($key): string
    {
        return ucwords(str_replace(array('_', '-'), ' ', sanitize_key((string) $key)));
    }

    private static function normalize_minimal_feedback(string $contact_action, string $conversation, string $target_fit, string $commercial_result, string $more_like_this): void
    {
        if ($contact_action === 'none') {
            $_POST['is_valid_lead'] = 'yes';
            $_POST['would_buy_again'] = 'NEUTRAL';
            $_POST['sales_stage'] = 'CONTACTED';
            $_POST['contact_result'] = 'no_response';
            return;
        }

        if ($conversation === 'no') {
            $_POST['is_valid_lead'] = 'yes';
            $_POST['would_buy_again'] = 'NEUTRAL';
            $_POST['sales_stage'] = 'CONTACTED';
            $_POST['contact_result'] = 'no_response';
            return;
        }

        if ($target_fit === 'no') {
            $_POST['is_valid_lead'] = 'no';
            $_POST['would_buy_again'] = 'DEFINITELY_NOT';
            $_POST['sales_stage'] = 'INVALID';
            $_POST['contact_result'] = 'not_interested';
            return;
        }

        if ($target_fit === 'yes') {
            $_POST['is_valid_lead'] = 'yes';
        }

        $_POST['would_buy_again'] = $more_like_this === 'yes' ? 'DEFINITELY_YES' : ($more_like_this === 'no' ? 'DEFINITELY_NOT' : 'NEUTRAL');
        $stage_map = array(
            'info_only' => 'CONTACTED',
            'opportunity' => 'NEGOTIATION',
            'next_step' => 'MEETING_BOOKED',
            'customer' => 'CONVERTED',
            'lost' => 'LOST',
        );
        $_POST['sales_stage'] = $stage_map[$commercial_result] ?? '';
        $_POST['contact_result'] = $commercial_result === 'lost' ? 'not_interested' : ($commercial_result === 'next_step' ? 'follow_up' : 'responded');
    }

    private static function sanitize_need_value($selected, $free_text): string
    {
        $selected = sanitize_text_field(wp_unslash($selected));
        if ($selected === '__other') {
            return sanitize_text_field(wp_unslash($free_text));
        }
        return $selected;
    }

    private static function sanitize_context_gap_values($raw): array
    {
        $values = is_array($raw) ? $raw : array($raw);
        $keys = array();
        foreach ($values as $value) {
            $key = Estandar_Leads_Repository::sanitize_context_gap($value);
            if ($key !== '' && !in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }
        if (empty($keys)) {
            $keys[] = 'NO';
        }
        $labels = array();
        foreach ($keys as $key) {
            if ($key !== 'NO') {
                $labels[] = Estandar_Leads_Repository::context_gap_label($key);
            }
        }
        return array('primary' => $keys[0], 'labels' => $labels);
    }

    private static function compose_feedback_notes(string $need, array $missing_labels, string $comment): string
    {
        $parts = array();
        if ($need !== '') {
            $parts[] = 'Necesidad principal: ' . $need;
        }
        if (!empty($missing_labels)) {
            $parts[] = 'Información faltante: ' . implode(', ', $missing_labels);
        }
        if ($comment !== '') {
            $parts[] = $comment;
        }
        return implode("\n", $parts);
    }

    private static function primary_need_options($lead): array
    {
        $context = self::decode_json($lead->context_json ?? '');
        foreach (array('primary_need_options', 'need_options', 'service_options', 'commercial_need_options') as $key) {
            if (!empty($context[$key]) && is_array($context[$key])) {
                return array_filter(array_map('sanitize_text_field', $context[$key]));
            }
        }
        return array();
    }

    private static function invalid_reason_labels(): array
    {
        return array(
            'SPAM' => 'Spam',
            'WRONG_CONTACT' => 'Datos incorrectos',
            'OTHER_SERVICE' => 'Servicio incorrecto',
            'OUTSIDE_AREA' => 'Fuera de cobertura',
            'OTHER' => 'Otro',
        );
    }

    private static function would_buy_again_labels(): array
    {
        return array(
            'DEFINITELY_YES' => 'Definitivamente sí',
            'PROBABLY_YES' => 'Probablemente sí',
            'NEUTRAL' => 'Neutral',
            'PROBABLY_NOT' => 'Probablemente no',
            'DEFINITELY_NOT' => 'Definitivamente no',
        );
    }

    /**
     * Display labels — used in the feedback form dropdown (no legacy duplicates).
     */
	    private static function sales_stage_labels(): array
	    {
        return array(
            'CONTACTED'     => 'Contactado',
            'QUALIFIED'     => 'Calificado',
            'MEETING_BOOKED'  => 'Reunión agendada',
            'PROPOSAL_SENT'   => 'Propuesta enviada',
            'NEGOTIATION'   => 'En negociación',
            'VISIT_SCHEDULED' => 'Visita agendada',
            'VISITED'       => 'Visita realizada',
            'RESERVED'      => 'Reserva confirmada',
            'CLIENT'        => 'Cliente',
            'CLIENT_SIGNED' => 'Cliente firmado',
            'DEED_SIGNED'   => 'Escritura firmada',
            'DELIVERED'     => 'Entregado',
            'LOST'          => 'Perdido',
            'INVALID'       => 'Spam / No válido',
        );
	    }

	    private static function sanitize_contact_action($value): string
	    {
	        $value = sanitize_key(wp_unslash($value));
	        $allowed = array('whatsapp', 'phone', 'email', 'none');
	        return in_array($value, $allowed, true) ? $value : '';
	    }

	    private static function sanitize_contact_result($value): string
	    {
	        $value = sanitize_key(wp_unslash($value));
	        $allowed = array('no_response', 'responded', 'interested', 'not_interested', 'follow_up');
	        return in_array($value, $allowed, true) ? $value : '';
	    }

	    private static function contact_result_label(string $value): string
	    {
	        $labels = array(
	            'no_response' => 'Sin respuesta',
	            'responded' => 'Respondió',
	            'interested' => 'Interesado',
	            'not_interested' => 'No interesado',
	            'follow_up' => 'Volver a contactar',
	        );
	        return $labels[$value] ?? 'Contacto registrado';
	    }

	    private static function maybe_log_contact_state($record, string $action, string $result, string $notes, array $context_gap_meta): void
	    {
	        if ($action === '' || $action === 'none') {
	            return;
	        }
	        $outcome = self::contact_result_label($result);
	        Estandar_Leads_Portal::log_contact(
	            absint($record->lead_id),
	            absint($record->operator_id),
	            $action,
	            $outcome,
	            $notes,
	            array_merge($context_gap_meta, array(
	                'source' => 'feedback_form',
	                'contact_action' => $action,
	                'contact_result' => $result,
	            ))
	        );
	    }

	    /**
	     * All valid stage keys for server-side validation — includes legacy for in-flight tokens.
     */
    private static function all_valid_sales_stage_keys(): array
    {
        return array_merge(
            array_keys(self::sales_stage_labels()),
            array('MEETING_SCHEDULED', 'CUSTOMER')
        );
    }

    private static function status_from_sales_stage($sales_stage, $is_valid_lead): string
    {
        if ($is_valid_lead === 0) {
            return 'INVALID';
        }
        $map = array(
            // Generic (current)
            'CONTACTED'       => 'CONTACTED',
            'QUALIFIED'       => 'QUALIFIED',
            'MEETING_BOOKED'  => 'MEETING_BOOKED',
            'PROPOSAL_SENT'   => 'PROPOSAL_SENT',
            'NEGOTIATION'     => 'NEGOTIATION',
            'CONVERTED'       => 'CONVERTED',
            'VISIT_SCHEDULED' => 'VISIT_SCHEDULED',
            'VISITED'         => 'VISITED',
            'RESERVED'        => 'RESERVED',
            'CLIENT'          => 'CLIENT',
            'CLIENT_SIGNED'   => 'CLIENT_SIGNED',
            'DEED_SIGNED'     => 'DEED_SIGNED',
            'DELIVERED'       => 'DELIVERED',
            'LOST'            => 'LOST',
            'INVALID'         => 'INVALID',
            // Legacy (backward compat for tokens already sent)
            'MEETING_SCHEDULED' => 'MEETING_BOOKED',
            'CUSTOMER'          => 'CONVERTED',
        );
        return $map[$sales_stage] ?? '';
    }

    private static function conversion_signal($is_valid_lead, $would_buy_again, $quality, $sales_stage): array
    {
        $value = 0;
        if (in_array($sales_stage, array('CONVERTED', 'CUSTOMER'), true)) {
            $value = 100;
        } elseif ($sales_stage === 'NEGOTIATION') {
            $value = 40;
        } elseif ($sales_stage === 'PROPOSAL_SENT') {
            $value = 30;
        } elseif (in_array($sales_stage, array('MEETING_BOOKED', 'MEETING_SCHEDULED'), true)) {
            $value = 20;
        } elseif ($is_valid_lead === 1 && in_array($would_buy_again, array('DEFINITELY_YES', 'PROBABLY_YES'), true)) {
            $value = 10;
        }
        if ($quality === 'JUNK' || $is_valid_lead === 0) {
            $value = 0;
        }
        return array('name' => $value > 0 ? 'lead_quality_signal' : 'lead_rejected_signal', 'value' => $value);
    }

    /**
     * LEAD_QUALITY_RECALC_V1 — recálculo aditivo de calidad tras feedback.
     *
     * Convierte la nota libre del operador en evidencia estructurada y
     * recalcula las dimensiones afectadas (precedencia humana), persistiendo
     * provenance en un evento `quality_recalculated`. NO borra historial.
     * Ausencia = default: si core no tiene el motor, no hace nada.
     *
     * @param object $record    token de feedback.
     * @param string $contact_result resultado de contacto canónico v1.
     * @param string $reason    nota libre del operador.
     * @param string $quality   calidad enviada por el operador (HIGH/MEDIUM/LOW/'').
     * @param int|null $is_valid_lead bool legacy.
     * @param string $sales_stage
     * @param string $invalid_reason legacy uppercase.
     */
    private static function maybe_recalculate_quality($record, $contact_result, $reason, $quality, $is_valid_lead, $sales_stage, $invalid_reason): void
    {
        if (!class_exists('Estandar\\Core\\Quality\\OperatorFeedbackParser')
            || !class_exists('Estandar\\Core\\Quality\\LeadQualityRecalculator')
            || !class_exists('Estandar\\Core\\Quality\\ContactabilityEngine')) {
            return; // Ausencia = default: sin motor, sin recálculo.
        }
        global $wpdb;

        $lead_id = absint($record->lead_id);
        $lead_row = $wpdb->get_row($wpdb->prepare(
            "SELECT quality, temperature, is_valid_lead, commercial_qualification
             FROM {$wpdb->prefix}es_leads WHERE id = %d LIMIT 1",
            $lead_id
        ), ARRAY_A);
        if (!is_array($lead_row)) {
            return;
        }

        // Contactabilidad técnica del lead (sin escribir nada).
        $phone_validation = Estandar\Core\Quality\ContactabilityEngine::phone((string) $wpdb->get_var($wpdb->prepare(
            "SELECT phone FROM {$wpdb->prefix}es_leads WHERE id = %d LIMIT 1",
            $lead_id
        )));
        $email_validation = Estandar\Core\Quality\ContactabilityEngine::email((string) $wpdb->get_var($wpdb->prepare(
            "SELECT email FROM {$wpdb->prefix}es_leads WHERE id = %d LIMIT 1",
            $lead_id
        )));

        $feedback = Estandar\Core\Quality\OperatorFeedbackParser::parse(
            (string) $contact_result,
            (string) $reason,
            array('phone' => $phone_validation, 'email' => $email_validation)
        );

        $current_quality = $quality !== '' ? $quality : (string) ($lead_row['quality'] ?? '');
        $recalc = Estandar\Core\Quality\LeadQualityRecalculator::recalculate(array(
            'lead_quality' => $current_quality,
            'temperature'  => (string) ($lead_row['temperature'] ?? ''),
            'commercial_qualification' => (string) ($lead_row['commercial_qualification'] ?? 'pending'),
            'is_valid_lead' => array_key_exists('is_valid_lead', $lead_row) ? $lead_row['is_valid_lead'] : $is_valid_lead,
        ), $feedback);

        if (empty($recalc['recalculated'])) {
            return; // Sin cambios: no persiste nada.
        }

        // Persistir dimensiones recalculadas (sin pisar historial: solo las afectadas).
        $update = array('updated_at' => gmdate('Y-m-d H:i:s'));
        if ($recalc['lead_quality'] !== $current_quality) {
            $update['quality'] = $recalc['lead_quality'];
        }
        if (array_key_exists('temperature', $recalc) && $recalc['temperature'] !== (string) ($lead_row['temperature'] ?? '')) {
            $update['temperature'] = $recalc['temperature'];
        }
        $wpdb->update($wpdb->prefix . 'es_leads', $update, array('id' => $lead_id));

        // Provenance en evento.
        Estandar_Leads_Repository::insert_event($lead_id, 'quality_recalculated', array(
            'operator_id' => absint($record->operator_id ?? 0),
            'reason'      => (string) ($recalc['quality_reason'] ?? ''),
            'metadata'    => array(
                'engine'     => Estandar\Core\Quality\LeadQualityRecalculator::VERSION,
                'reason'     => (string) ($recalc['quality_reason'] ?? ''),
                'provenance' => (array) $recalc['provenance'],
                'feedback'   => $feedback,
                'lead_quality' => (string) $recalc['lead_quality'],
                'temperature'  => (string) $recalc['temperature'],
            ),
        ));
    }
}
