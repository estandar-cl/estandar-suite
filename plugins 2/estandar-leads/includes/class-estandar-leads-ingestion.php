<?php

if (!defined('ABSPATH')) {
    exit;
}

class Estandar_Leads_Ingestion
{
    public static function init()
    {
        add_action('estandar_lead_captured', array(__CLASS__, 'capture'), 10, 1);
    }

    public static function capture($payload)
    {
        global $wpdb;

        // El snapshot confirma SOLO la captura actual: sin reset, un proceso
        // que ingesta varias veces (p.ej. reconciliación de entries huérfanas
        // por CLI) leería la confirmación de una captura anterior.
        self::$last_ingested = null;

        if (!Estandar_Leads_Payload_Validator::is_valid($payload)) {
            self::telemetry('error', 'LEADS_INVALID_PAYLOAD_REJECTED', 'Lead payload rejected by validator.', array(
                'source_plugin' => sanitize_key($payload['source_plugin'] ?? ''),
            ));
            error_log('[estandar-leads] Invalid payload');
            return 0;
        }

        $now = gmdate('Y-m-d H:i:s');
        $contact = isset($payload['contact']) && is_array($payload['contact']) ? $payload['contact'] : array();
        $content = isset($payload['content']) && is_array($payload['content']) ? $payload['content'] : array();
        $attr = isset($payload['attribution']) && is_array($payload['attribution']) ? $payload['attribution'] : array();
        $attr = Estandar_Leads_Attribution::merge_analytics($attr);
        $payload['attribution'] = $attr;
        $payload_hash = Estandar_Leads_Normalizer::payload_hash($payload);
        $request = isset($payload['request']) && is_array($payload['request']) ? $payload['request'] : array();
        $spam = isset($payload['spam']) && is_array($payload['spam']) ? $payload['spam'] : array();
        $context = isset($payload['context']) && is_array($payload['context']) ? $payload['context'] : array();
        $scoring = isset($payload['scoring']) && is_array($payload['scoring']) ? $payload['scoring'] : array();
        $chatbot = isset($payload['chatbot']) && is_array($payload['chatbot']) ? $payload['chatbot'] : array();
        $form_fields = isset($payload['meta']['sanitized_raw_fields']) && is_array($payload['meta']['sanitized_raw_fields'])
            ? $payload['meta']['sanitized_raw_fields']
            : array();
        $source_entry_id = isset($payload['meta']['source_entry_id']) ? absint($payload['meta']['source_entry_id']) : 0;
        $lead_uuid = self::lead_uuid($payload['meta']['lead_uuid'] ?? ($form_fields['lead_uuid'] ?? ''));
        $lead_uuid = self::ensure_unique_lead_uuid($lead_uuid);

        $source_plugin = sanitize_key($payload['source_plugin']);
        $source_form_id = sanitize_text_field($content['source_form_id'] ?? '');
        $schema_version = sanitize_text_field($payload['schema_version']);
        $submitted_at = Estandar_Leads_Normalizer::submitted_at($payload['submitted_at'] ?? '');
        $name = sanitize_text_field($contact['name'] ?? '');
        $name = Estandar_Leads_Normalizer::name( $name );
        $email = Estandar_Leads_Normalizer::email($contact['email'] ?? '');
        $phone = sanitize_text_field($contact['phone'] ?? '');
        $phone_normalized = Estandar_Leads_Normalizer::phone_storage($phone);
        $email_hash = Estandar_Leads_Normalizer::hash($email);
        $phone_hash = Estandar_Leads_Normalizer::hash($phone_normalized);
        $legacy_phone_hash = Estandar_Leads_Normalizer::hash(Estandar_Leads_Normalizer::phone($phone));
        $message = sanitize_textarea_field($content['message'] ?? '');
        $utm_source = sanitize_text_field($attr['utm_source'] ?? '');
        $utm_medium = sanitize_text_field($attr['utm_medium'] ?? '');
        $utm_campaign = sanitize_text_field($attr['utm_campaign'] ?? '');
        $utm_term = sanitize_text_field($attr['utm_term'] ?? '');
        $utm_content = sanitize_text_field($attr['utm_content'] ?? '');
        $gclid = sanitize_text_field($attr['gclid'] ?? '');
	        $gbraid = sanitize_text_field($attr['gbraid'] ?? '');
	        $wbraid = sanitize_text_field($attr['wbraid'] ?? '');
	        $fbclid = sanitize_text_field($attr['fbclid'] ?? '');
	        $meta_fbc = sanitize_text_field($attr['meta_fbc'] ?? ($attr['_fbc'] ?? ''));
	        $meta_fbp = sanitize_text_field($attr['meta_fbp'] ?? ($attr['_fbp'] ?? ''));
	        $ttclid = sanitize_text_field($attr['ttclid'] ?? '');
	        $msclkid = sanitize_text_field($attr['msclkid'] ?? '');
	        $landing_url = esc_url_raw($attr['landing_url'] ?? '');
        $referrer = sanitize_textarea_field($attr['referrer'] ?? '');
        $first_touch_timestamp = sanitize_text_field($attr['first_touch_timestamp'] ?? '');
        $last_touch_timestamp = sanitize_text_field($attr['last_touch_timestamp'] ?? '');
        $session_id = sanitize_text_field($attr['session_id'] ?? '');
        $gclid_method = sanitize_key($attr['gclid_capture_method'] ?? 'missing');
        $fbclid_method = sanitize_key($attr['fbclid_capture_method'] ?? 'missing');
        $attribution_method = sanitize_key($attr['attribution_capture_method'] ?? 'payload');
        $ip_address = sanitize_text_field($request['ip_address'] ?? '');
        $user_agent = sanitize_textarea_field($request['user_agent'] ?? '');
        // F2: señal de red de primera captura (write-once, solo insert inicial).
        // REMOTE_ADDR directo — jamás X-Forwarded-For (spoofeable).
        $first_ip_address = self::first_capture_ip();
        $first_user_agent = self::first_capture_user_agent();
        $project_area = sanitize_text_field($context['project_area'] ?? '');
        $project_slug = self::project_slug($context['project_slug'] ?? '', $project_area);
        $lead_intent = sanitize_key($context['intent'] ?? '');
        $context['project_slug'] = $project_slug;
        $payload['context'] = $context;
		$value_track_fields = array( 'campaign_id', 'adgroup_id', 'asset_group_id', 'creative_id', 'keyword', 'match_type', 'network', 'device', 'placement', 'campaign_type' );
		$context['google_ads_attribution'] = array();
		foreach ($value_track_fields as $field) {
			$context['google_ads_attribution'][$field] = sanitize_text_field($attr[$field] ?? '');
		}
		$context['google_ads_attribution_provenance'] = array();
		foreach ((array) ($attr['attribution_provenance'] ?? array()) as $field => $provenance) {
			if (in_array($field, $value_track_fields, true)) {
				$context['google_ads_attribution_provenance'][$field] = sanitize_key($provenance);
			}
		}
        $qa = Estandar_Leads_QA_Policy::evaluate($payload);
        $is_test_lead = !empty($qa['is_test_lead']);
        $context['qa_protection'] = $qa;
        $scoring['score_eligible'] = !empty($qa['score_eligible']);
        $scoring['qualification'] = (string) ($qa['qualification'] ?? '');
        $payload['context'] = $context;
        $payload['scoring'] = $scoring;
        $payload['qa'] = $qa;
        $suspect_reasons = self::suspect_reasons($spam, $request);
        $suspect = empty($suspect_reasons) ? 0 : 1;

        // CHATBOT_SUBMISSION_EVIDENCE_V1 (Lead #478): el chatbot no envía
        // meta.sanitized_raw_fields, por lo que form_fields_json quedaba []
        // para capturas reales. Deriva evidencia canónica desde el payload
        // (contact/context/content), sin exigir email. Solo variable local:
        // $payload no se toca → payload_hash y dedupe quedan byte-idénticos.
        // sanitize_form_fields() re-sanitiza en el INSERT como siempre.
        if (empty($form_fields) && $source_plugin === 'estandar-chatbot') {
            $evidence = array(
                'nombre'      => $name,
                'telefono'    => $phone,
                'email'       => $email,
                'intent'      => $lead_intent,
                'requirement' => sanitize_textarea_field($context['requirement'] ?? '') !== ''
                    ? sanitize_textarea_field($context['requirement'] ?? '')
                    : $message,
            );
            foreach ($evidence as $evidence_key => $evidence_value) {
                if (trim((string) $evidence_value) !== '') {
                    $form_fields[$evidence_key] = $evidence_value;
                }
            }
        }
        $identity_mutex = self::acquire_identity_mutex($email_hash, $phone_hash);
        if ($identity_mutex === false) {
            self::telemetry('warning', 'LEADS_IDENTITY_MUTEX_UNAVAILABLE', 'Canonical identity mutex unavailable.', array(
                'source_plugin' => $source_plugin,
                'has_email_hash' => $email_hash !== '',
                'has_phone_hash' => $phone_hash !== '',
            ));
            error_log('[estandar-leads] Canonical identity lock unavailable');
            return 0;
        }

        // MUTEX_LEAK_FIX_V1: la sección crítica (transacción, persistencia,
        // eventos y hooks post-commit) va bajo try/finally para garantizar una
        // única liberación del mutex de identidad en TODA salida —incluida una
        // excepción no prevista—. Antes, un Throwable entre la adquisición y el
        // COMMIT dejaba el lock activo hasta expirar → el siguiente submit de la
        // misma identidad recibía LEADS_IDENTITY_MUTEX_UNAVAILABLE.
        $in_transaction = false;
        $mutex_released = false;
        try {
        $wpdb->query('START TRANSACTION');
        $in_transaction = true;

        $lead_id = Estandar_Leads_Repository::find_lead_id($email_hash, $phone_hash, $legacy_phone_hash);
        $is_new = $lead_id <= 0;

        if ($is_new) {
            $ok = $wpdb->insert($wpdb->prefix . 'es_leads', array(
                'lead_uuid' => $lead_uuid,
                'status' => 'NEW',
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'email_normalized' => $email,
                'phone_normalized' => $phone_normalized,
                'email_hash' => $email_hash,
                'phone_hash' => $phone_hash,
                'first_source_plugin' => $source_plugin,
                'latest_source_plugin' => $source_plugin,
                'project_slug' => $project_slug,
                'project_area' => $project_area,
                'lead_intent' => $lead_intent,
                'first_utm_source' => $utm_source,
                'first_utm_medium' => $utm_medium,
                'first_utm_campaign' => $utm_campaign,
                'first_utm_term' => $utm_term,
	                'first_utm_content' => $utm_content,
	                'first_gclid' => $gclid,
	                'first_gbraid' => $gbraid,
	                'first_wbraid' => $wbraid,
	                'first_fbclid' => $fbclid,
	                'first_meta_fbc' => $meta_fbc,
	                'first_meta_fbp' => $meta_fbp,
	                'first_ttclid' => $ttclid,
	                'first_msclkid' => $msclkid,
	                'first_landing_url' => $landing_url,
	                'first_referrer' => $referrer,
                'first_session_id' => $session_id,
                'first_ip_address' => $first_ip_address,
                'first_user_agent' => $first_user_agent,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ));
            $lead_id = absint($wpdb->insert_id);
            if (!$ok || !$lead_id) {
                self::rollback('LEADS_ROLLBACK_LEAD_INSERT_FAILED', 'Lead transaction rolled back after canonical lead insert failure.', array(
                    'source_plugin' => $source_plugin,
                    'db_error' => sanitize_text_field($wpdb->last_error),
                ));
                error_log('[estandar-leads] Lead insert failed: ' . $wpdb->last_error);
                return 0;
            }
            if (!Estandar_Leads_Repository::insert_event($lead_id, 'lead_created', array(
                'ip_address' => $ip_address,
                'user_agent' => $user_agent,
                'metadata' => array(
                    'is_test_lead' => $is_test_lead,
                    'test_reason' => (string) ($qa['test_reason'] ?? ''),
                    'score_eligible' => !empty($qa['score_eligible']),
                ),
            ))) {
                self::rollback('LEADS_ROLLBACK_CREATED_EVENT_FAILED', 'Lead transaction rolled back after lead_created event failure.', array(
                    'lead_id' => $lead_id,
                    'source_plugin' => $source_plugin,
                ));
                return 0;
            }
        } else {
            // T2: read operator fields before constructing update
	            $existing = $wpdb->get_row($wpdb->prepare(
	                "SELECT lead_uuid, status, project_slug, latest_source_plugin, last_classified_at, name, email, phone, email_normalized, phone_normalized, email_hash, phone_hash,
                            first_session_id, first_utm_source, first_utm_medium, first_utm_campaign,
	                        first_utm_term, first_utm_content, first_gclid, first_gbraid, first_wbraid,
	                        first_fbclid, first_meta_fbc, first_meta_fbp, first_ttclid, first_msclkid,
	                        first_landing_url, first_referrer,
	                        assigned_operator_id, assigned_operator_email, assigned_operator_name, assigned_at
	                 FROM {$wpdb->prefix}es_leads WHERE id = %d LIMIT 1",
	                $lead_id
	            ), ARRAY_A);

            $active_project  = (string) ($existing['project_slug'] ?? '');
            $project_locked  = self::project_locked_by_transfer($lead_id);
            $project_changed = !$is_test_lead && $active_project !== '' && $project_slug !== '' && $project_slug !== $active_project;
            $new_operator_id    = null;
            $new_operator_email = null;
            $new_operator_name  = null;
            $overwrite_blocked  = false;
            $operator_desassigned = false;

            $lead_update = $is_test_lead
                ? array('updated_at' => $now)
                : array(
                    'latest_source_plugin' => $source_plugin,
                    'lead_intent'          => $lead_intent,
                    'last_seen_at'         => $now,
                    'updated_at'           => $now,
                );
            $lead_update_formats = $is_test_lead ? array('%s') : array('%s', '%s', '%s', '%s');

            if (!$is_test_lead && !$project_locked) {
                if ($project_slug !== '') {
                    $lead_update['project_slug'] = $project_slug;
                    $lead_update['project_area'] = $project_area;
                    $lead_update_formats[]       = '%s';
                    $lead_update_formats[]       = '%s';
                }

                if ($project_changed && class_exists('Estandar_Leads_Operator_Resolver')) {
                    $resolved = Estandar_Leads_Operator_Resolver::first_for_project($project_slug, array('require_email' => false));
                    if (!empty($resolved)) {
                        $new_operator_id    = (int)    ($resolved['id']    ?? 0);
                        $new_operator_email = (string) ($resolved['email'] ?? '');
                        $new_operator_name  = (string) ($resolved['name']  ?? '');
                        $lead_update['assigned_operator_id']    = $new_operator_id ?: null;
                        $lead_update['assigned_operator_email'] = $new_operator_email;
                        $lead_update['assigned_operator_name']  = $new_operator_name;
                        $lead_update['assigned_at']             = $now;
                        $lead_update_formats[]                  = '%d';
                        $lead_update_formats[]                  = '%s';
                        $lead_update_formats[]                  = '%s';
                        $lead_update_formats[]                  = '%s';
                    } elseif ($project_slug !== '') {
                        // No operator for new project: clear assignment and set flag for event emission.
                        $operator_desassigned                   = true;
                        $lead_update['assigned_operator_id']    = null;
                        $lead_update['assigned_operator_email'] = '';
                        $lead_update['assigned_operator_name']  = '';
                        $lead_update['assigned_at']             = null;
                        $lead_update_formats[]                  = '%d';
                        $lead_update_formats[]                  = '%s';
                        $lead_update_formats[]                  = '%s';
                        $lead_update_formats[]                  = '%s';
                        self::telemetry('warning', 'LEADS_RECONTACT_NO_OPERATOR', 'Recontact project change: no operator found for new project; assignment cleared.', array(
                            'lead_id'          => $lead_id,
                            'previous_project' => $active_project,
                            'new_project'      => $project_slug,
                            'source_plugin'    => $source_plugin,
                        ));
                    }
                }
            } elseif (!$is_test_lead) {
                if ($project_changed) {
                    $overwrite_blocked = true;
                    self::telemetry('info', 'LEADS_PROJECT_OVERWRITE_BLOCKED', 'Recontact project change blocked: lead is locked by manual transfer.', array(
                        'lead_id'           => $lead_id,
                        'locked_project'    => $active_project,
                        'attempted_project' => $project_slug,
                        'source_plugin'     => $source_plugin,
                    ));
                }
            }
	            if (self::is_duplicate_interaction($lead_id, $source_plugin, $source_form_id, $source_entry_id, $payload_hash)) {
	                $wpdb->query('ROLLBACK');
	                // Interacción duplicada idempotente: el lead YA está
	                // persistido — confirmarlo en el snapshot para que el
	                // emisor (Forms) no lo reporte como fallo de ingesta. El
	                // flag duplicate evita re-emitir eventos derivados (Meta
	                // browser event) sobre la misma interacción.
	                self::$last_ingested = array(
	                    'lead_id'   => (int) $lead_id,
	                    'lead_uuid' => self::canonical_lead_uuid($lead_id, $lead_uuid),
	                    'duplicate' => true,
	                );
	                return $lead_id;
	            }
	            $existing_uuid = (string) ($existing['lead_uuid'] ?? '');
	            if ($existing_uuid === '' && $lead_uuid !== '') {
	                $lead_update['lead_uuid'] = $lead_uuid;
	                $lead_update_formats[] = '%s';
	            }
	            foreach (self::identity_update_for_empty_lead((array) $existing, array(
	                'name' => $name,
	                'email' => $email,
	                'phone' => $phone,
	                'email_normalized' => $email,
	                'phone_normalized' => $phone_normalized,
	                'email_hash' => $email_hash,
	                'phone_hash' => $phone_hash,
	            )) as $field => $value) {
	                $lead_update[$field] = $value;
	                $lead_update_formats[] = '%s';
	            }
	            foreach (self::identity_update_for_empty_lead((array) $existing, array(
	                'first_session_id' => $session_id,
	                'first_utm_source' => $utm_source,
	                'first_utm_medium' => $utm_medium,
	                'first_utm_campaign' => $utm_campaign,
	                'first_utm_term' => $utm_term,
	                'first_utm_content' => $utm_content,
	                'first_gclid' => $gclid,
	                'first_gbraid' => $gbraid,
	                'first_wbraid' => $wbraid,
	                'first_fbclid' => $fbclid,
	                'first_meta_fbc' => $meta_fbc,
	                'first_meta_fbp' => $meta_fbp,
	                'first_ttclid' => $ttclid,
	                'first_msclkid' => $msclkid,
	                'first_landing_url' => $landing_url,
	                'first_referrer' => $referrer,
	            )) as $field => $value) {
	                $lead_update[$field] = $value;
	                $lead_update_formats[] = '%s';
	            }
	            $updated = $wpdb->update($wpdb->prefix . 'es_leads', $lead_update, array('id' => $lead_id), $lead_update_formats, array('%d'));
            if ($updated === false) {
                self::rollback('LEADS_ROLLBACK_LEAD_UPDATE_FAILED', 'Lead transaction rolled back after canonical lead update failure.', array(
                    'lead_id' => $lead_id,
                    'source_plugin' => $source_plugin,
                    'db_error' => sanitize_text_field($wpdb->last_error),
                ));
                error_log('[estandar-leads] Lead update failed: ' . $wpdb->last_error);
                return 0;
            }

        }

        $ok = $wpdb->insert($wpdb->prefix . 'es_lead_submissions', array(
            'lead_id' => $lead_id,
            'lead_uuid' => $lead_uuid,
            'source_plugin' => $source_plugin,
            'source_form_id' => $source_form_id,
            'source_entry_id' => $source_entry_id > 0 ? $source_entry_id : null,
            'schema_version' => $schema_version,
            'submitted_at' => $submitted_at,
            'created_at' => $now,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'email_normalized' => $email,
            'phone_normalized' => $phone_normalized,
            'email_hash' => $email_hash,
            'phone_hash' => $phone_hash,
            'message' => $message,
            'utm_source' => $utm_source,
            'utm_medium' => $utm_medium,
            'utm_campaign' => $utm_campaign,
            'utm_term' => $utm_term,
            'utm_content' => $utm_content,
            'gclid' => $gclid,
	            'gbraid' => $gbraid,
	            'wbraid' => $wbraid,
	            'fbclid' => $fbclid,
	            'meta_fbc' => $meta_fbc,
	            'meta_fbp' => $meta_fbp,
	            'ttclid' => $ttclid,
	            'msclkid' => $msclkid,
	            'landing_url' => $landing_url,
            'referrer' => $referrer,
            'first_touch_timestamp' => $first_touch_timestamp,
            'last_touch_timestamp' => $last_touch_timestamp,
            'session_id' => $session_id,
            'gclid_capture_method' => $gclid_method,
            'fbclid_capture_method' => $fbclid_method,
            'attribution_capture_method' => $attribution_method,
            'ip_address' => $ip_address,
            'user_agent' => $user_agent,
            'suspect' => $suspect,
            'suspect_reasons' => implode(',', $suspect_reasons),
            'payload_hash' => $payload_hash,
            'context_json' => wp_json_encode(self::sanitize_context($context)),
            'scoring_json' => wp_json_encode(self::sanitize_scoring($scoring)),
            'chatbot_json' => wp_json_encode(self::sanitize_chatbot($chatbot)),
            'form_fields_json' => wp_json_encode(self::sanitize_form_fields($form_fields)),
        ));
        $submission_id = absint($wpdb->insert_id);
        if (!$ok || !$submission_id) {
            self::rollback('LEADS_ROLLBACK_SUBMISSION_INSERT_FAILED', 'Lead transaction rolled back after submission insert failure.', array(
                'lead_id' => $lead_id,
                'source_plugin' => $source_plugin,
                'db_error' => sanitize_text_field($wpdb->last_error),
            ));
            error_log('[estandar-leads] Submission insert failed: ' . $wpdb->last_error);
            return 0;
        }

        if (!Estandar_Leads_Repository::insert_event($lead_id, 'lead_submitted', array(
            'submission_id' => $submission_id,
            'ip_address' => $ip_address,
            'user_agent' => $user_agent,
            'metadata' => array(
                'suspect_reasons' => $suspect_reasons,
                'is_test_lead' => $is_test_lead,
                'test_reason' => (string) ($qa['test_reason'] ?? ''),
                'score_eligible' => !empty($qa['score_eligible']),
            ),
        ))) {
            self::rollback('LEADS_ROLLBACK_SUBMITTED_EVENT_FAILED', 'Lead transaction rolled back after lead_submitted event failure.', array(
                'lead_id' => $lead_id,
                'submission_id' => $submission_id,
                'source_plugin' => $source_plugin,
            ));
            return 0;
        }

        if (!$is_new && !$is_test_lead) {
            self::record_recontact_event($lead_id, $submission_id, $project_slug, $source_plugin, $utm_campaign, $submitted_at);
            self::maybe_reopen_terminal_lead($lead_id, $submission_id, (array) $existing, array(
                'project_slug' => $project_slug,
                'source_plugin' => $source_plugin,
                'utm_campaign' => $utm_campaign,
                'session_id' => $session_id,
                'submitted_at' => $submitted_at,
                'source_form_id' => $source_form_id,
                'source_entry_id' => $source_entry_id,
                'payload_hash' => $payload_hash,
                'now' => $now,
            ));

            // T2: operator re-sync and lock events — emitted with submission_id
            $prev_operator_id = (int) ($existing['assigned_operator_id'] ?? 0);

            if ($project_changed && !$project_locked) {
                if ($new_operator_id !== null && $prev_operator_id !== $new_operator_id) {
                    Estandar_Leads_Repository::insert_event($lead_id, 'operator_reassigned', array(
                        'submission_id'       => $submission_id,
                        'from_operator_id'    => $prev_operator_id ?: null,
                        'from_operator_email' => (string) ($existing['assigned_operator_email'] ?? ''),
                        'from_operator_name'  => (string) ($existing['assigned_operator_name'] ?? ''),
                        'to_operator_id'      => $new_operator_id,
                        'to_operator_email'   => $new_operator_email,
                        'to_operator_name'    => $new_operator_name,
                        'source'              => 'recontact_project_change',
                        'previous_project'    => $active_project,
                        'new_project'         => $project_slug,
                    ));
                } elseif ($operator_desassigned) {
                    Estandar_Leads_Repository::insert_event($lead_id, 'operator_reassigned', array(
                        'submission_id'       => $submission_id,
                        'from_operator_id'    => $prev_operator_id ?: null,
                        'from_operator_email' => (string) ($existing['assigned_operator_email'] ?? ''),
                        'from_operator_name'  => (string) ($existing['assigned_operator_name'] ?? ''),
                        'to_operator_id'      => 0,
                        'to_operator_email'   => '',
                        'to_operator_name'    => '',
                        'source'              => 'recontact_project_change_no_operator',
                        'previous_project'    => $active_project,
                        'new_project'         => $project_slug,
                    ));
                }
            }

            if ($overwrite_blocked) {
                Estandar_Leads_Repository::insert_event($lead_id, 'project_overwrite_blocked', array(
                    'submission_id'     => $submission_id,
                    'locked_project'    => $active_project,
                    'attempted_project' => $project_slug,
                    'source_plugin'     => $source_plugin,
                ));
            }
        } elseif (!$is_new) {
            Estandar_Leads_Repository::insert_event($lead_id, 'qa_submission_recorded', array(
                'submission_id' => $submission_id,
                'reason' => (string) ($qa['test_reason'] ?? ''),
                'metadata' => array(
                    'is_test_lead' => true,
                    'test_reason' => (string) ($qa['test_reason'] ?? ''),
                    'score_eligible' => false,
                ),
            ));
        }

        if (is_array($payload['experience'] ?? null)) {
            Estandar_Leads_Repository::persist_experience_snapshot($lead_id, $submission_id, $payload['experience']);
        }

        $wpdb->query('COMMIT');
        $in_transaction = false;

        // MUTEX_RELEASE_TIMING_V1: orden exacto COMMIT → release → listeners.
        // Persistido el lead, el mutex ya no protege nada: se libera de
        // inmediato ANTES de $last_ingested / do_action / telemetría post-
        // ingesta, para no retener el lock de identidad mientras corren los
        // listeners (notifier/autoresponse/analytics) ni tareas post-commit.
        // Esto permite además la reentrada de la MISMA identidad desde
        // estandar_lead_ingested. El flag evita una segunda liberación lógica
        // en el finally.
        self::release_identity_mutex($identity_mutex);
        $mutex_released = true;

        // Snapshot canónico post-persistencia: expone lead_id + lead_uuid REAL
        // (el de la fila, no el posteado — puede diferir por regeneración
        // anti-colisión o por recontacto sobre un lead existente). Lo consumen
        // los emisores de respuesta (p.ej. forms → meta_lead_event_id).
        self::$last_ingested = array(
            'lead_id'   => (int) $lead_id,
            'lead_uuid' => self::canonical_lead_uuid($lead_id, $lead_uuid),
        );

        do_action('estandar_lead_ingested', $lead_id, $submission_id, $is_new, $payload);

        if ($utm_source === 'google' && $utm_medium === 'cpc' && $gclid === '' && $gbraid === '' && $wbraid === '') {
            self::telemetry('critical', 'LEADS_MISSING_CLICK_IDENTIFIER', 'Missing Click Identifier: Google CPC lead created without any supported click identifier. Expected at least one of gclid, gbraid or wbraid. Offline Conversion Import and attribution cannot associate this lead with its originating click. Measurement integrity is compromised.', array(
                'lead_uuid' => $lead_uuid,
                'lead_id' => $lead_id,
                'submission_id' => $submission_id,
                'session_id' => $session_id,
                'landing_url' => $landing_url,
                'source' => $utm_source,
                'medium' => $utm_medium,
                'campaign' => $utm_campaign,
                'timestamp' => $submitted_at,
            ));
        }

        return $lead_id;
        } catch (Throwable $e) {
            // Salida por excepción no prevista dentro de la sección crítica.
            // Revierte solo si la transacción sigue activa (un Throwable
            // post-COMMIT no debe deshacer datos ya persistidos), registra
            // telemetría técnica SIN PII/secretos (solo clase, mensaje y
            // presencia de hashes) y relanza para preservar el manejo actual
            // de Forms (FORMS_LEAD_EMIT_FAILURE / POST_INGEST_LISTENER_FAILURE).
            if ($in_transaction) {
                $wpdb->query('ROLLBACK');
                $in_transaction = false;
            }
            self::telemetry('error', 'LEADS_INGESTION_EXCEPTION', 'Uncaught throwable during canonical lead ingestion; transaction rolled back if active and identity mutex released.', array(
                'source_plugin'  => $source_plugin,
                'exception'      => get_class($e),
                'message'        => sanitize_text_field($e->getMessage()),
                'has_email_hash' => $email_hash !== '',
                'has_phone_hash' => $phone_hash !== '',
            ));
            throw $e;
        } finally {
            // Red de seguridad: libera SOLO si aún no se liberó (retornos
            // pre-COMMIT: éxito de error, duplicado idempotente, y el Throwable
            // pre-COMMIT). En el camino feliz el release ya ocurrió tras el
            // COMMIT ($mutex_released === true) → aquí no hay segunda
            // liberación lógica. release_identity_mutex() es además idempotente.
            if ($mutex_released === false) {
                self::release_identity_mutex($identity_mutex);
            }
        }
    }

	    private static function rollback($code, $message, $context = array())
    {
        global $wpdb;
        $wpdb->query('ROLLBACK');
        self::telemetry('error', $code, $message, $context);
	    }

	    private static function identity_update_for_empty_lead(array $lead, array $candidate): array
	    {
	        $update = array();
	        foreach ($candidate as $field => $value) {
	            if (trim((string)($lead[$field] ?? '')) === '' && trim((string)$value) !== '') {
	                $update[$field] = (string)$value;
	            }
	        }

	        return $update;
	    }

	    private static function record_recontact_event($lead_id, $submission_id, $project_slug, $source_plugin, $campaign, $submitted_at): void
    {
        global $wpdb;

        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS submission_count, MIN(submitted_at) AS first_submitted_at,
                    (SELECT s2.submitted_at FROM {$wpdb->prefix}es_lead_submissions s2
                     WHERE s2.lead_id = %d AND s2.id != %d
                     ORDER BY s2.submitted_at DESC, s2.id DESC LIMIT 1) AS previous_submitted_at
             FROM {$wpdb->prefix}es_lead_submissions
             WHERE lead_id = %d",
            absint($lead_id),
            absint($submission_id),
            absint($lead_id)
        ), ARRAY_A);
        $submission_count = (int) ($stats['submission_count'] ?? 0);
        if ($submission_count < 2) {
            return;
        }

        $first_ts = strtotime((string) ($stats['first_submitted_at'] ?? ''));
        $this_ts  = strtotime((string) $submitted_at);
        $prev_ts  = strtotime((string) ($stats['previous_submitted_at'] ?? ''));
        $days_since_first = ($first_ts && $this_ts) ? max(0, (int) floor(($this_ts - $first_ts) / DAY_IN_SECONDS)) : 0;
        $project_name = function_exists('resolve_project_display_name')
            ? resolve_project_display_name($project_slug, '')
            : $project_slug;

        $session_window_seconds = (int) apply_filters('estandar_leads_recontact_session_window_seconds', 3 * HOUR_IN_SECONDS);
        $gap_seconds = ($prev_ts && $this_ts) ? max(0, $this_ts - $prev_ts) : 0;
        $is_same_session = ($prev_ts && $this_ts) ? ($gap_seconds <= $session_window_seconds) : false;

        if ($is_same_session) {
            Estandar_Leads_Repository::insert_event($lead_id, 'contact_session_extended', array(
                'submission_id' => absint($submission_id),
                'metadata' => array(
                    'project_slug' => sanitize_text_field((string) $project_slug),
                    'project_name' => sanitize_text_field((string) $project_name),
                    'submission_count' => $submission_count,
                    'gap_seconds' => $gap_seconds,
                    'session_window_seconds' => $session_window_seconds,
                    'source' => sanitize_key((string) $source_plugin),
                    'campaign' => sanitize_text_field((string) $campaign),
                ),
            ));
        } else {
            Estandar_Leads_Repository::insert_event($lead_id, 'lead_recontacted', array(
                'submission_id' => absint($submission_id),
                'metadata' => array(
                    'project_slug' => sanitize_text_field((string) $project_slug),
                    'project_name' => sanitize_text_field((string) $project_name),
                    'submission_count' => $submission_count,
                    'days_since_first_submission' => $days_since_first,
                    'gap_seconds' => $gap_seconds,
                    'session_window_seconds' => $session_window_seconds,
                    'source' => sanitize_key((string) $source_plugin),
                    'campaign' => sanitize_text_field((string) $campaign),
                ),
            ));
        }
    }

    private static function is_duplicate_interaction($lead_id, $source_plugin, $source_form_id, $source_entry_id, $payload_hash): bool
    {
        global $wpdb;

        if (absint($source_entry_id) > 0) {
            $exists = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}es_lead_submissions
                 WHERE lead_id = %d AND source_entry_id = %d AND source_plugin = %s AND source_form_id = %s",
                absint($lead_id),
                absint($source_entry_id),
                sanitize_key((string) $source_plugin),
                sanitize_text_field((string) $source_form_id)
            ));
            if ($exists > 0) {
                return true;
            }
        }

        $payload_hash = sanitize_text_field((string) $payload_hash);
        if ($payload_hash !== '') {
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}es_lead_submissions
                 WHERE lead_id = %d AND payload_hash <> '' AND payload_hash = %s",
                absint($lead_id),
                $payload_hash
            )) > 0;
        }

        return false;
    }

    private static function maybe_reopen_terminal_lead($lead_id, $submission_id, array $lead, array $current): void
    {
        global $wpdb;

        $previous_status = (string) ($lead['status'] ?? '');
        if (!in_array($previous_status, array('INVALID', 'LOST'), true)) {
            return;
        }

        $terminal = $wpdb->get_row($wpdb->prepare(
            "SELECT status, classified_at FROM {$wpdb->prefix}es_lead_classifications
             WHERE lead_id = %d AND status IN ('INVALID','LOST')
             ORDER BY classified_at DESC, id DESC LIMIT 1",
            absint($lead_id)
        ), ARRAY_A);
        $classified_at = (string) ($terminal['classified_at'] ?? ($lead['last_classified_at'] ?? ''));
        $submission_ts = strtotime((string) ($current['submitted_at'] ?? ''));
        $classified_ts = strtotime($classified_at);
        if (!$submission_ts || !$classified_ts || $submission_ts <= $classified_ts) {
            return;
        }

        $duplicate = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}es_lead_submissions
             WHERE lead_id = %d AND id <> %d AND (
                 (source_entry_id IS NOT NULL AND source_entry_id = %d AND source_plugin = %s AND source_form_id = %s)
                 OR (payload_hash <> '' AND payload_hash = %s)
             )",
            absint($lead_id),
            absint($submission_id),
            absint($current['source_entry_id'] ?? 0),
            sanitize_key((string) ($current['source_plugin'] ?? '')),
            sanitize_text_field((string) ($current['source_form_id'] ?? '')),
            sanitize_text_field((string) ($current['payload_hash'] ?? ''))
        ));
        if ($duplicate > 0) {
            return;
        }

        $previous = $wpdb->get_row($wpdb->prepare(
            "SELECT source_plugin, utm_campaign, session_id, context_json
             FROM {$wpdb->prefix}es_lead_submissions
             WHERE lead_id = %d AND id <> %d
             ORDER BY submitted_at DESC, created_at DESC, id DESC LIMIT 1",
            absint($lead_id),
            absint($submission_id)
        ));
        $previous_project = $previous && class_exists('Estandar_Leads_Repository') ? Estandar_Leads_Repository::project_for_submission($previous) : (string) ($lead['project_slug'] ?? '');
        $cooldown_days = (int) get_option('estandar_leads_terminal_reopen_cooldown_days', 30);
        $cooldown_elapsed = $cooldown_days > 0 && floor(($submission_ts - $classified_ts) / DAY_IN_SECONDS) >= $cooldown_days;
        $changed = $previous && (
            (string) $previous_project !== (string) ($current['project_slug'] ?? '')
            || (string) ($previous->source_plugin ?? '') !== (string) ($current['source_plugin'] ?? '')
            || (string) ($previous->utm_campaign ?? '') !== (string) ($current['utm_campaign'] ?? '')
            || ((string) ($current['session_id'] ?? '') !== '' && (string) ($previous->session_id ?? '') !== (string) ($current['session_id'] ?? ''))
        );
        if (!$changed && !$cooldown_elapsed) {
            return;
        }

        $updated = $wpdb->update($wpdb->prefix . 'es_leads', array(
            'status' => 'NEW',
            'feedback_completed' => 0,
            'updated_at' => (string) ($current['now'] ?? gmdate('Y-m-d H:i:s')),
        ), array('id' => absint($lead_id)), array('%s', '%d', '%s'), array('%d'));
        if ($updated === false) {
            return;
        }

        Estandar_Leads_Repository::insert_event($lead_id, 'lead_operational_reopened', array(
            'submission_id' => absint($submission_id),
            'from_status' => $previous_status,
            'to_status' => 'NEW',
            'reason' => 'New commercial interaction after terminal classification.',
            'metadata' => array(
                'previous_terminal_status' => (string) ($terminal['status'] ?? $previous_status),
                'previous_classified_at' => $classified_at,
                'project_changed' => $previous && (string) $previous_project !== (string) ($current['project_slug'] ?? ''),
                'source_changed' => $previous && (string) ($previous->source_plugin ?? '') !== (string) ($current['source_plugin'] ?? ''),
                'campaign_changed' => $previous && (string) ($previous->utm_campaign ?? '') !== (string) ($current['utm_campaign'] ?? ''),
                'new_analytics_session' => $previous && (string) ($current['session_id'] ?? '') !== '' && (string) ($previous->session_id ?? '') !== (string) ($current['session_id'] ?? ''),
                'cooldown_elapsed' => $cooldown_elapsed,
            ),
        ));
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

    private static function acquire_identity_mutex($email_hash, $phone_hash)
    {
        global $wpdb;

        $keys = array();
        if ($email_hash !== '') {
            $keys[] = 'email:' . $email_hash;
        }
        if ($phone_hash !== '') {
            $keys[] = 'phone:' . $phone_hash;
        }
        $keys = array_values(array_unique($keys));
        sort($keys, SORT_STRING);

        if (empty($keys)) {
            return false;
        }

        $token = wp_generate_uuid4();
        $locks = array();
        foreach ($keys as $key) {
            $transient = 'estandar_leads_identity_' . md5($key);
            $value_key = '_transient_' . $transient;
            $value = wp_json_encode(array('token' => $token, 'expires' => time() + 30));

            if (!add_option($value_key, $value, '', 'no')) {
                $current = get_option($value_key);
                $current_data = is_string($current) ? json_decode($current, true) : null;
                $expires = is_array($current_data) ? (int) ($current_data['expires'] ?? 0) : 0;
                $key_type = strpos($key, 'email:') === 0 ? 'email' : 'phone';

                if ($expires > 0 && $expires <= time()) {
                    // EXPIRED_LOCK_RECOVERY_V1: el lock caducó pero quedó en
                    // wp_options. Se elimina por NOMBRE con la API de WP
                    // (coherente con caché). El DELETE previo por `option_value`
                    // exacto fallaba en silencio ante desajuste caché/
                    // serialización y dejaba el lock huérfano indefinidamente
                    // (evidencia prod: seconds_remaining muy negativo, -1902).
                    // delete_option() reporta el resultado REAL del borrado; su
                    // valor de retorno decide la etapa exacta si algo falla.
                    $deleted = delete_option($value_key);
                    // Reintento ÚNICO de adquisición (no es un reintento general
                    // de ingesta). add_option → INSERT ... ON DUPLICATE KEY
                    // UPDATE sobrescribe el expirado con nuestro token.
                    if (add_option($value_key, $value, '', 'no')) {
                        $locks[] = $transient;
                        continue;
                    }
                    // Ni el borrado ni la sobrescritura liberaron el lock →
                    // fail-closed con la etapa EXACTA según la primitiva que
                    // falló (delete_failed = el borrado no removió fila;
                    // reacquire_failed = se borró pero no se pudo re-adquirir).
                    self::telemetry('warning', 'LEADS_IDENTITY_MUTEX_RECOVERY_FAILED', 'Expired identity lock recovery failed.', array(
                        'stage'               => $deleted ? 'reacquire_failed' : 'delete_failed',
                        'key_type'            => $key_type,
                        'expired_seconds_ago' => time() - $expires,
                    ));
                    self::release_identity_mutex(array('token' => $token, 'locks' => $locks));
                    return false;
                }

                // Lock vigente (no expirado) → contención real de identidad.
                // Fail-closed; seconds_remaining positivo distingue esta
                // contención legítima de la fuga por expirado ya corregida.
                self::telemetry('warning', 'LEADS_IDENTITY_MUTEX_CONTENDED', 'Active identity lock held by a concurrent capture.', array(
                    'stage'             => 'active_lock',
                    'key_type'          => $key_type,
                    'seconds_remaining' => $expires > 0 ? $expires - time() : null,
                ));
                self::release_identity_mutex(array('token' => $token, 'locks' => $locks));
                return false;
            }
            $locks[] = $transient;
        }

        return array('token' => $token, 'locks' => $locks);
    }

    private static function release_identity_mutex($mutex)
    {
        $token = is_array($mutex) ? (string) ($mutex['token'] ?? '') : '';
        $locks = is_array($mutex) ? (array) ($mutex['locks'] ?? array()) : (array) $mutex;
        foreach ($locks as $lock) {
            $value_key = '_transient_' . $lock;
            $current = get_option($value_key);
            $current_data = is_string($current) ? json_decode($current, true) : null;
            if ($token !== '' && (!is_array($current_data) || (string) ($current_data['token'] ?? '') !== $token)) {
                continue;
            }
            delete_option($value_key);
        }
    }

    private static function suspect_reasons($spam, $request)
    {
        $reasons = array();
        if (!empty($spam['honeypot_filled'])) {
            $reasons[] = 'honeypot';
        }
        if (isset($spam['seconds_to_submit']) && $spam['seconds_to_submit'] !== null && (float) $spam['seconds_to_submit'] < 3) {
            $reasons[] = 'too_fast';
        }
        if (empty($request['user_agent'])) {
            $reasons[] = 'missing_user_agent';
        }
        return $reasons;
    }

    /** @var array{lead_id:int,lead_uuid:string}|null Último lead persistido en este request. */
    private static $last_ingested = null;

    /**
     * Snapshot read-only del último lead persistido correctamente en este
     * request (post-COMMIT). null si ningún lead se persistió — los emisores
     * de eventos frontend NO deben disparar nada en ese caso.
     *
     * @return array{lead_id:int,lead_uuid:string}|null
     */
    public static function last_ingested()
    {
        return self::$last_ingested;
    }

    /**
     * UUID real de la fila del lead (fuente de verdad post-persistencia).
     * Fallback al uuid en memoria si la lectura falla.
     */
    private static function first_capture_ip()
    {
        $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '';
    }

    private static function first_capture_user_agent()
    {
        $ua = sanitize_text_field((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        return substr($ua, 0, 255);
    }

    private static function canonical_lead_uuid($lead_id, $fallback_uuid)
    {
        global $wpdb;
        $stored = $wpdb->get_var($wpdb->prepare(
            "SELECT lead_uuid FROM {$wpdb->prefix}es_leads WHERE id = %d LIMIT 1",
            (int) $lead_id
        ));
        $stored = is_string($stored) ? trim($stored) : '';
        return $stored !== '' ? $stored : (string) $fallback_uuid;
    }

    private static function lead_uuid($value)
    {
        $value = sanitize_text_field((string) $value);
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $value)) {
            return strtolower($value);
        }
        return wp_generate_uuid4();
    }

    /**
     * Guarantee the UUID is not already present in es_leads.
     * Handles page-cache collisions, payload manipulation, and external imports.
     * Performs at most 3 attempts before giving up (birthday-paradox probability is negligible).
     */
    private static function ensure_unique_lead_uuid($uuid)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'es_leads';
        $attempts = 0;
        while ($attempts < 3) {
            $exists = $wpdb->get_var(
                $wpdb->prepare("SELECT id FROM {$table} WHERE lead_uuid = %s LIMIT 1", $uuid)
            );
            if ($exists === null) {
                return $uuid;
            }
            $uuid = wp_generate_uuid4();
            $attempts++;
        }
        return $uuid;
    }

    private static function sanitize_context($context)
    {
        $clean = array(
            'project_area' => sanitize_text_field($context['project_area'] ?? ''),
            'project_slug' => self::project_slug($context['project_slug'] ?? '', $context['project_area'] ?? ''),
            'city' => sanitize_text_field($context['city'] ?? ''),
            'intent' => sanitize_key($context['intent'] ?? ''),
            'requirement' => sanitize_textarea_field($context['requirement'] ?? ''),
            'source_page_url' => esc_url_raw($context['source_page_url'] ?? ''),
            'intent_detection_source' => sanitize_key($context['intent_detection_source'] ?? ''),
            'source_match_slug' => sanitize_key($context['source_match_slug'] ?? ''),
            'source_match_type' => sanitize_key($context['source_match_type'] ?? ''),
            'source_channel' => sanitize_key($context['source_channel'] ?? ''),
        );
		$value_track_fields = array( 'campaign_id', 'adgroup_id', 'asset_group_id', 'creative_id', 'keyword', 'match_type', 'network', 'device', 'placement', 'campaign_type' );
		$clean['google_ads_attribution'] = array();
		foreach ($value_track_fields as $field) {
			$clean['google_ads_attribution'][$field] = sanitize_text_field($context['google_ads_attribution'][$field] ?? '');
		}
		$clean['google_ads_attribution_provenance'] = array();
		foreach ((array) ($context['google_ads_attribution_provenance'] ?? array()) as $field => $provenance) {
			if (in_array($field, $value_track_fields, true)) {
				$clean['google_ads_attribution_provenance'][$field] = sanitize_key($provenance);
			}
		}
        if (is_array($context['qa_protection'] ?? null)) {
            $qa = $context['qa_protection'];
            $clean['qa_protection'] = array(
                'is_test_lead' => !empty($qa['is_test_lead']),
                'test_reason' => sanitize_text_field($qa['test_reason'] ?? ''),
                'score_eligible' => !empty($qa['score_eligible']),
                'qualification' => sanitize_key($qa['qualification'] ?? ''),
                'routing' => sanitize_key($qa['routing'] ?? ''),
                'notification' => sanitize_key($qa['notification'] ?? ''),
                'autoresponse' => sanitize_key($qa['autoresponse'] ?? ''),
                'ads_feedback' => sanitize_key($qa['ads_feedback'] ?? ''),
                'sla' => sanitize_key($qa['sla'] ?? ''),
                'escalation' => sanitize_key($qa['escalation'] ?? ''),
            );
        }
        return $clean;
    }

    private static function sanitize_scoring($scoring)
    {
        return array(
            'lead_score' => isset($scoring['lead_score']) ? (int) $scoring['lead_score'] : 0,
            'lead_tier' => sanitize_key($scoring['lead_tier'] ?? ''),
            'lead_route' => sanitize_key($scoring['lead_route'] ?? ''),
            'score_eligible' => !isset($scoring['score_eligible']) || !empty($scoring['score_eligible']),
            'qualification' => sanitize_key($scoring['qualification'] ?? ''),
        );
    }

    private static function sanitize_chatbot($chatbot)
    {
        return array(
            'chatbot_status' => sanitize_key($chatbot['chatbot_status'] ?? ''),
            'chatbot_lead_id' => isset($chatbot['chatbot_lead_id']) ? (int) $chatbot['chatbot_lead_id'] : 0,
            'chatbot_session_key' => sanitize_text_field($chatbot['chatbot_session_key'] ?? ''),
            'force_human' => !empty($chatbot['force_human']),
            'escalation_requested' => !empty($chatbot['escalation_requested']),
            'escalation_channel' => sanitize_key($chatbot['escalation_channel'] ?? ''),
            'conversation_summary' => sanitize_textarea_field($chatbot['conversation_summary'] ?? ''),
        );
    }

    private static function sanitize_form_fields($fields)
    {
        $clean = array();
        foreach ((array) $fields as $key => $value) {
            $key = is_string($key) ? sanitize_key($key) : '';
            if ($key === '') {
                continue;
            }
            $value = self::format_form_field_value($value);
            if ($value === '') {
                continue;
            }
            $clean[$key] = $value;
        }
        return $clean;
    }

    private static function format_form_field_value($value): string
    {
        if (is_array($value)) {
            $parts = array();
            foreach ($value as $item) {
                $item = self::format_form_field_value($item);
                if ($item !== '') {
                    $parts[] = $item;
                }
            }
            return implode(', ', $parts);
        }
        if (is_bool($value)) {
            return $value ? 'Si' : 'No';
        }
        if (!is_scalar($value)) {
            return '';
        }
        return trim(sanitize_textarea_field((string) $value));
    }

    private static function project_slug($slug, $fallback_area = '')
    {
        if (class_exists('Estandar_Leads_Projects')) {
            return Estandar_Leads_Projects::resolve_slug($slug, $fallback_area);
        }

        $slug = sanitize_title(remove_accents((string) $slug));
        if ($slug === '') {
            $slug = sanitize_title(remove_accents((string) $fallback_area));
        }
        return trim($slug, "- \t\n\r\0\x0B");
    }

    private static function project_locked_by_transfer(int $lead_id): bool
    {
        global $wpdb;
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}es_lead_events WHERE lead_id = %d AND event_type = 'lead_transferred' LIMIT 1",
            $lead_id
        ));
        return $count > 0;
    }
}
