<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ======================================================
 * LEADS ENDPOINT — ESTANDAR CHATBOT IA
 * Versión: MEJORADA / PRODUCCIÓN
 * ======================================================
 */

add_action( 'wp_ajax_estandar_chatbot_save_lead', 'estandar_chatbot_handle_save_lead' );
add_action( 'wp_ajax_nopriv_estandar_chatbot_save_lead', 'estandar_chatbot_handle_save_lead' );

function estandar_chatbot_handle_save_lead() {

	// --------------------------------------------------
	// Seguridad
	// --------------------------------------------------
	estandar_chatbot_verify_nonce_or_die();

	if ( empty($_POST['lead']) || ! is_array($_POST['lead']) ) {
		estandar_chatbot_telemetry_event( 'warning', 'CHATBOT_MALFORMED_LEAD_PAYLOAD', 'Chatbot lead endpoint received malformed lead payload.', [
			'channel' => isset( $_POST['channel'] ) ? sanitize_key( wp_unslash( $_POST['channel'] ) ) : '',
		] );
		wp_send_json_error([ 'message' => 'Datos de lead inválidos.' ], 400);
	}

	$lead_raw = wp_unslash( $_POST['lead'] );
	$lead = [];
	if ( is_array( $lead_raw ) ) {
		foreach ( $lead_raw as $k => $v ) {
			$key = sanitize_key( $k );
			if ( is_array( $v ) ) {
				$lead[$key] = array_map( 'sanitize_text_field', $v );
			} else {
				$lead[$key] = sanitize_text_field( $v );
			}
		}
		}
		$lead['intent'] = isset( $_POST['intent'] ) ? sanitize_key( wp_unslash( $_POST['intent'] ) ) : '';
		$lead['requirement'] = isset( $_POST['requirement'] ) ? sanitize_textarea_field( wp_unslash( $_POST['requirement'] ) ) : '';
		$lead['lead_intent'] = isset( $_POST['lead_intent'] ) ? sanitize_key( wp_unslash( $_POST['lead_intent'] ) ) : '';
		$lead['conversation_summary'] = isset( $_POST['conversation_summary'] ) ? sanitize_textarea_field( wp_unslash( $_POST['conversation_summary'] ) ) : '';
		$lead['last_user_message'] = isset( $_POST['last_user_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['last_user_message'] ) ) : '';
		$lead['conversation_recent'] = isset( $_POST['conversation_recent'] ) ? sanitize_textarea_field( wp_unslash( $_POST['conversation_recent'] ) ) : '';
		if ( $lead['lead_intent'] === '' && $lead['intent'] !== '' ) {
			$lead['lead_intent'] = $lead['intent'];
		}
		$session_key = isset( $_POST['session_id'] ) ? estandar_chatbot_normalize_session_key( wp_unslash( $_POST['session_id'] ) ) : '';
	$ea_sid = function_exists( 'estandar_core_get_ea_sid' ) ? estandar_core_get_ea_sid( $_POST ) : '';
	if ( '' === $ea_sid ) {
		$ea_sid = isset( $_POST['ea_sid'] ) ? sanitize_text_field( wp_unslash( $_POST['ea_sid'] ) ) : '';
		if ( ! preg_match( '/^[a-zA-Z0-9\-]{8,64}$/', $ea_sid ) ) {
			$ea_sid = '';
		}
	}
	$channel = isset( $_POST['channel'] ) ? estandar_chatbot_normalize_channel( wp_unslash( $_POST['channel'] ) ) : 'web';

	$source_page_url = estandar_chatbot_sanitize_source_page_url( isset( $_POST['source_page_url'] ) ? wp_unslash( $_POST['source_page_url'] ) : '' );
	if ( $source_page_url !== '' ) {
		$lead['source_page_url'] = $source_page_url;
	}
	$context_slug_raw = isset( $_POST['context_slug'] ) ? sanitize_key( wp_unslash( $_POST['context_slug'] ) ) : '';
	if ( $context_slug_raw !== '' && function_exists( 'estandar_chatbot_contexts_validate_slug' ) ) {
		$context_slug_raw = estandar_chatbot_contexts_validate_slug( $context_slug_raw );
	}
	if ( $context_slug_raw !== '' ) {
		$lead['context_slug'] = $context_slug_raw;
		if ( function_exists( 'estandar_chatbot_contexts_get_name' ) ) {
			$context_name_raw = estandar_chatbot_contexts_get_name( $context_slug_raw );
			if ( $context_name_raw !== '' ) {
				$lead['context_name'] = $context_name_raw;
			}
		}
	}
	if ( $session_key !== '' ) {
		$lead['session_id'] = $session_key;
	}
	if ( $ea_sid !== '' ) {
		$lead['analytics_session_id'] = $ea_sid;
	}
	if ( function_exists( 'estandar_chatbot_detect_lead_context' ) ) {
		$lead = estandar_chatbot_detect_lead_context( $lead );
	}
	estandar_chatbot_telemetry_event( 'info', 'CHATBOT_PROJECT_RESOLUTION', 'Chatbot resolved lead project context.', [
		'chatbot_session_id' => $session_key,
		'analytics_session_id' => $ea_sid,
		'project_slug' => sanitize_key( (string) ( $lead['project_slug'] ?? '' ) ),
		'resolution_source' => sanitize_key( (string) ( $lead['project_resolution_source'] ?? 'fallback' ) ),
	] );
	$intent_classification = function_exists( 'estandar_chatbot_classify_lead_intent' )
		? estandar_chatbot_classify_lead_intent( $lead )
		: [ 'category' => 'otro', 'outside_service' => false ];
	$lead['intent_category'] = sanitize_key( (string) ( $intent_classification['category'] ?? 'otro' ) );
	$lead['intent_mismatch'] = function_exists( 'estandar_chatbot_has_intent_mismatch' ) && estandar_chatbot_has_intent_mismatch( $lead, $intent_classification ) ? '1' : '0';
	if ( ! empty( $intent_classification['outside_service'] ) ) {
		estandar_chatbot_telemetry_event( 'info', 'CHATBOT_LEAD_BLOCKED_OUTSIDE_SERVICE', 'Chatbot blocked outside-service lead capture.', [
			'channel' => $channel,
			'session_id' => $session_key,
			'intent_category' => $lead['intent_category'],
			'intent_mismatch' => $lead['intent_mismatch'],
		] );
		$outside_message = apply_filters( 'estandar_chatbot_outside_service_message',
			'Este servicio no está disponible para esta consulta. Nuestro equipo se especializa en asesoría contable, tributaria, laboral y empresarial.'
		);
		wp_send_json_success([
			'blocked' => true,
			'reason'  => 'outside_service',
			'message' => sanitize_text_field( $outside_message ),
		]);
	}

	$force_human_post = isset( $_POST['force_human'] ) ? (int) wp_unslash( $_POST['force_human'] ) : 0;
	$force_human_lead = isset( $lead['force_human'] ) ? (int) $lead['force_human'] : 0;
	$force_human      = ( $force_human_post === 1 || $force_human_lead === 1 );
	if ( $force_human ) {
		$lead['force_human'] = '1';
		if ( empty( $lead['escalation_requested'] ) ) {
			$lead['escalation_requested'] = '1';
		}
		if ( empty( $lead['escalation_channel'] ) ) {
			$lead['escalation_channel'] = 'chat';
		}
	}

	$score_data = function_exists( 'estandar_chatbot_score_and_route_lead' )
		? estandar_chatbot_score_and_route_lead( $lead )
		: [ 'score' => 0, 'tier' => 'cold', 'route' => 'nurturing', 'reasons' => [] ];

	$lead['lead_score'] = (string) (int) $score_data['score'];
	$lead['lead_tier']  = (string) $score_data['tier'];
	$lead['lead_route'] = (string) $score_data['route'];
	$lead['lead_quality'] = function_exists( 'estandar_chatbot_lead_quality_label' ) ? estandar_chatbot_lead_quality_label( (int) $score_data['score'] ) : '';
	$lead['commercial_priority'] = function_exists( 'estandar_chatbot_commercial_priority_label' ) ? estandar_chatbot_commercial_priority_label( (int) $score_data['score'] ) : '';
	$lead['source_channel'] = $channel;

	// --------------------------------------------------
	// Rate limit simple por IP
	// --------------------------------------------------
	$meta = estandar_chatbot_get_request_meta();
	$ip   = $meta['ip'] ?: 'unknown';
	$session_fingerprint = $session_key !== '' ? $session_key : 'anon';

	$key  = 'ecb_rl_lead_' . md5( $ip . '|' . $session_fingerprint );
	$hits = (int) get_transient($key);

	if ( $hits >= 10 ) {
		wp_send_json_error([
			'message' => 'Demasiadas solicitudes. Intenta más tarde.'
		], 429);
	}

	set_transient( $key, $hits + 1, 60 ); // 10 req/min

	// --------------------------------------------------
	// Guardar lead (handler robusto)
	// --------------------------------------------------
	if ( ! function_exists('estandar_chatbot_save_lead') ) {
		estandar_chatbot_telemetry_event( 'error', 'CHATBOT_LEAD_SAVE_FAILED', 'Chatbot local lead save handler is unavailable.', [
			'channel' => $channel,
			'has_email' => ! empty( $lead['email'] ),
			'has_phone' => ! empty( $lead['phone'] ),
			'status' => 'handler_missing',
		] );
		wp_send_json_error([
			'message' => 'Sistema de leads no disponible.'
		], 500);
	}

	$result = estandar_chatbot_save_lead( $lead );

	if ( ! is_array($result) || ($result['status'] ?? '') === 'error' ) {
		$error_code = sanitize_key( (string) ( $result['code'] ?? '' ) );
		estandar_chatbot_telemetry_event( 'error', 'CHATBOT_LEAD_SAVE_FAILED', 'Chatbot local lead save failed.', [
			'channel' => $channel,
			'has_email' => ! empty( $lead['email'] ),
			'has_phone' => ! empty( $lead['phone'] ),
			'status' => sanitize_key( (string) ( $result['status'] ?? 'invalid_result' ) ),
		] );
		wp_send_json_error([
			'code'    => $error_code,
			'message' => $result['message'] ?? 'Error al guardar lead.',
		], $error_code === 'contact_rejected' ? 422 : 500);
	}
	$dedupe_key = '';
	$phone_norm = function_exists( 'estandar_chatbot_normalize_phone' ) ? estandar_chatbot_normalize_phone( $lead['phone'] ?? '' ) : sanitize_text_field( (string) ( $lead['phone'] ?? '' ) );
	if ( $session_key !== '' && $phone_norm !== '' ) {
		$dedupe_key = 'ecb_lead_notice_' . md5( $session_key . '|' . $phone_norm );
	}
	$is_duplicate_notice = $dedupe_key !== '' && (bool) get_transient( $dedupe_key );
	if ( $dedupe_key !== '' && ! $is_duplicate_notice ) {
		set_transient( $dedupe_key, 1, 15 * MINUTE_IN_SECONDS );
	}

	try {
		$saved_lead = is_array( $result['lead'] ?? null ) ? $result['lead'] : [];
		$landing_url = (string) ( $lead['source_page_url'] ?? '' );
		if ( $landing_url === '' ) {
			$landing_url = (string) ( $meta['url'] ?? '' );
		}

		if ( function_exists( 'estandar_core_build_attribution_context' ) ) {
			$attribution_context = estandar_core_build_attribution_context( [
				'landing_url' => esc_url_raw( $landing_url ),
				'session_id'  => $ea_sid,
			] );
		} else {
			// Minimal shape: UTM/click-id fields cannot be derived from the
			// landing URL without the canonical core builder. Make the
			// degradation visible instead of failing silently.
			$attribution_context = [
				'landing_url' => esc_url_raw( $landing_url ),
				'session_id'  => $ea_sid,
			];
			estandar_chatbot_telemetry_event( 'warning', 'CHATBOT_ATTRIBUTION_CONTEXT_FALLBACK', 'Canonical attribution builder unavailable; lead emitted with minimal attribution shape (URL params not derived).', [
				'channel'     => $channel,
				'has_landing' => $landing_url !== '',
			] );
		}

		do_action( 'estandar_lead_captured', [
			'schema_version' => '1.0',
			'source_plugin'  => 'estandar-chatbot',
			'submitted_at'   => gmdate( 'c' ),
			'contact'        => [
				'name'  => sanitize_text_field( (string) ( $saved_lead['name'] ?? $lead['name'] ?? '' ) ),
				'email' => sanitize_email( (string) ( $saved_lead['email'] ?? $lead['email'] ?? '' ) ),
				'phone' => sanitize_text_field( (string) ( $saved_lead['phone'] ?? $lead['phone'] ?? '' ) ),
			],
				'content'        => [
					'message'        => sanitize_textarea_field( (string) ( $lead['message'] ?? $lead['requirement'] ?? '' ) ),
					'source_form_id' => 'chatbot',
				],
				'context'        => [
				'project_slug'            => function_exists( 'estandar_chatbot_normalize_project_slug' ) ? estandar_chatbot_normalize_project_slug( (string) ( $lead['project_slug'] ?? $lead['project_area'] ?? '' ) ) : sanitize_key( (string) ( $lead['project_slug'] ?? '' ) ),
				'project_area'            => sanitize_text_field( (string) ( $lead['project_area'] ?? '' ) ),
				'city'                    => sanitize_text_field( (string) ( $lead['city'] ?? '' ) ),
					'intent'                  => sanitize_key( (string) ( $lead['intent'] ?? '' ) ),
					'lead_intent'             => sanitize_key( (string) ( $lead['lead_intent'] ?? '' ) ),
					'intent_category'         => sanitize_key( (string) ( $lead['intent_category'] ?? '' ) ),
					'intent_mismatch'         => ! empty( $lead['intent_mismatch'] ),
					'requirement'             => sanitize_textarea_field( (string) ( $lead['requirement'] ?? '' ) ),
					'conversation_summary'    => sanitize_textarea_field( (string) ( $lead['conversation_summary'] ?? '' ) ),
					'last_user_message'       => sanitize_textarea_field( (string) ( $lead['last_user_message'] ?? '' ) ),
					'source_page_url'         => esc_url_raw( (string) ( $lead['source_page_url'] ?? '' ) ),
					'intent_detection_source' => sanitize_key( (string) ( $lead['intent_detection_source'] ?? '' ) ),
				'source_match_slug'       => sanitize_key( (string) ( $lead['source_match_slug'] ?? '' ) ),
				'source_match_type'       => sanitize_key( (string) ( $lead['source_match_type'] ?? '' ) ),
				'project_resolution_source' => sanitize_key( (string) ( $lead['project_resolution_source'] ?? 'fallback' ) ),
				'source_channel'          => sanitize_key( (string) $channel ),
				'contact_quality'         => sanitize_key( (string) ( $saved_lead['contact_quality'] ?? CONTACT_VALID ) ),
				'contact_warning'         => sanitize_key( (string) ( $saved_lead['contact_warning'] ?? CONTACT_WARNING_NONE ) ),
			],
			'scoring'        => [
				'lead_score' => (int) ( $score_data['score'] ?? 0 ),
				'lead_tier'  => sanitize_key( (string) ( $score_data['tier'] ?? '' ) ),
				'lead_route' => sanitize_key( (string) ( $score_data['route'] ?? '' ) ),
			],
			'chatbot'        => [
				'chatbot_status'       => sanitize_key( (string) ( $result['status'] ?? '' ) ),
				'chatbot_lead_id'      => (int) ( $result['lead_id'] ?? 0 ),
				'chatbot_session_key'  => $session_key,
				'force_human'          => (bool) $force_human,
				'escalation_requested' => ! empty( $lead['escalation_requested'] ),
				'escalation_channel'   => sanitize_key( (string) ( $lead['escalation_channel'] ?? '' ) ),
			],
			'attribution'    => $attribution_context,
			'request'        => [
				'ip_address' => sanitize_text_field( (string) ( $meta['ip'] ?? '' ) ),
				'user_agent' => sanitize_text_field( (string) ( $meta['ua'] ?? '' ) ),
			],
			'spam'           => [
				'honeypot_filled'   => false,
				'seconds_to_submit' => null,
			],
			'meta'           => [
				'chatbot_status'       => sanitize_key( (string) ( $result['status'] ?? '' ) ),
				'lead_score'           => (int) ( $score_data['score'] ?? 0 ),
				'lead_tier'            => sanitize_key( (string) ( $score_data['tier'] ?? '' ) ),
					'lead_route'           => sanitize_key( (string) ( $score_data['route'] ?? '' ) ),
					'lead_quality'         => sanitize_key( (string) ( $lead['lead_quality'] ?? '' ) ),
					'commercial_priority'  => sanitize_key( (string) ( $lead['commercial_priority'] ?? '' ) ),
					'source_channel'       => sanitize_key( (string) $channel ),
					'lead_intent'          => sanitize_key( (string) ( $lead['lead_intent'] ?? '' ) ),
					'intent_category'      => sanitize_key( (string) ( $lead['intent_category'] ?? '' ) ),
					'intent_mismatch'      => ! empty( $lead['intent_mismatch'] ),
					'conversation_summary' => sanitize_textarea_field( (string) ( $lead['conversation_summary'] ?? '' ) ),
					'last_user_message'    => sanitize_textarea_field( (string) ( $lead['last_user_message'] ?? '' ) ),
					'conversation_recent'  => sanitize_textarea_field( (string) ( $lead['conversation_recent'] ?? '' ) ),
					'force_human'          => (bool) $force_human,
					'escalation_requested' => ! empty( $lead['escalation_requested'] ),
				'escalation_channel'   => sanitize_key( (string) ( $lead['escalation_channel'] ?? '' ) ),
				'chatbot_lead_id'      => (int) ( $result['lead_id'] ?? 0 ),
					'chatbot_session_key'  => $session_key,
					'contact_quality'      => sanitize_key( (string) ( $saved_lead['contact_quality'] ?? CONTACT_VALID ) ),
					'contact_warning'      => sanitize_key( (string) ( $saved_lead['contact_warning'] ?? CONTACT_WARNING_NONE ) ),
					'suspect_contacts'     => ( $saved_lead['contact_quality'] ?? '' ) === CONTACT_SUSPECT ? 1 : 0,
					'email_raw'            => sanitize_text_field( (string) ( $saved_lead['email_raw'] ?? '' ) ),
					'phone_raw'            => sanitize_text_field( (string) ( $saved_lead['phone_raw'] ?? '' ) ),
			],
		] );
	} catch ( Throwable $e ) {
		estandar_chatbot_telemetry_event( 'error', 'CHATBOT_LEAD_EMIT_EXCEPTION', 'Chatbot failed while emitting lead to Estandar Leads.', [
			'channel' => $channel,
			'lead_id' => (int) ( $result['lead_id'] ?? 0 ),
			'exception' => get_class( $e ),
		] );
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[estandar-chatbot] estandar_lead_captured emission failed: ' . $e->getMessage() );
		}
	}

	$email_delivery = [
		'delivery_channel' => 'email',
		'status'           => 'skipped',
		'message'          => 'not_available',
		'recipients_count' => 0,
	];
		if ( $is_duplicate_notice ) {
			$email_delivery['message'] = 'duplicate_session_phone_window';
		} elseif ( sanitize_key( (string) get_option( 'chatbot_notification_mode', 'legacy' ) ) === 'leads' ) {
			$email_delivery['message'] = 'leads_notification_mode';
		} elseif ( function_exists( 'estandar_chatbot_notify_lead_by_email_result' ) ) {
			$email_result = estandar_chatbot_notify_lead_by_email_result( $result );
		$email_delivery['status'] = (string) ( $email_result['status'] ?? 'failed' );
		$email_delivery['message'] = (string) ( $email_result['message'] ?? '' );
		$email_delivery['recipients_count'] = (int) ( $email_result['recipients_count'] ?? 0 );
	} elseif ( function_exists( 'estandar_chatbot_notify_lead_by_email' ) ) {
		$email_enabled = (int) get_option( 'chatbot_notify_email_enabled', 1 ) === 1;
		$email_ok = (bool) estandar_chatbot_notify_lead_by_email( $result );
		$email_delivery['status'] = $email_ok ? 'success' : ( $email_enabled ? 'failed' : 'skipped' );
		$email_delivery['message'] = $email_ok ? 'sent' : ( $email_enabled ? 'wp_mail_false' : 'disabled' );
	}

	if ( $session_key !== '' && function_exists( 'estandar_chatbot_bind_conversation_to_lead' ) ) {
		estandar_chatbot_bind_conversation_to_lead( $session_key, $channel, (array) ( $result['lead'] ?? [] ) );
	}

	$webhook = [ 'ok' => false, 'status' => 0, 'message' => 'not_executed' ];
	if ( $is_duplicate_notice ) {
		$webhook['message'] = 'duplicate_session_phone_window';
	} elseif ( function_exists( 'estandar_chatbot_dispatch_lead_webhook' ) ) {
		$webhook_payload = [
			'event'       => 'lead.captured',
			'timestamp'   => current_time( 'mysql' ),
			'site'        => home_url( '/' ),
			'status'      => $result['status'],
			'score'       => (int) $score_data['score'],
			'tier'        => (string) $score_data['tier'],
			'route'       => (string) $score_data['route'],
			'channel'     => $channel,
			'session_id'  => $session_key,
				'source_page_url' => (string) ( $lead['source_page_url'] ?? '' ),
				'intent'      => (string) ( $lead['intent'] ?? '' ),
				'lead_intent' => (string) ( $lead['lead_intent'] ?? '' ),
				'requirement' => (string) ( $lead['requirement'] ?? '' ),
				'conversation_summary' => (string) ( $lead['conversation_summary'] ?? '' ),
				'last_user_message' => (string) ( $lead['last_user_message'] ?? '' ),
				'conversation_recent' => (string) ( $lead['conversation_recent'] ?? '' ),
				'escalation_requested' => $force_human,
				'force_human'          => $force_human,
			'lead'        => (array) ( $result['lead'] ?? [] ),
			'lead_id'     => (int) ( $result['lead_id'] ?? 0 ),
		];
		if ( function_exists( 'estandar_chatbot_enrich_lead_webhook_payload' ) ) {
			$webhook_payload = estandar_chatbot_enrich_lead_webhook_payload(
				$webhook_payload,
				$session_key,
				$channel,
				(array) ( $result['lead'] ?? [] )
			);
		}
		$webhook = estandar_chatbot_dispatch_lead_webhook( $webhook_payload );
	}
	$webhook_enabled = (int) get_option( 'chatbot_webhook_enabled', 0 ) === 1;
	$webhook_delivery = [
		'delivery_channel' => 'webhook',
		'status'           => ! empty( $webhook['ok'] ) ? 'success' : ( $webhook_enabled ? 'failed' : 'skipped' ),
		'message'          => (string) ( $webhook['message'] ?? '' ),
		'http_status'      => (int) ( $webhook['status'] ?? 0 ),
	];

	if ( function_exists( 'estandar_chatbot_log_interaction' ) ) {
		$lr      = (array) ( $result['lead'] ?? [] );
		$lead_id = trim( (string) ( $lr['email'] ?? '' ) );
		if ( $lead_id === '' ) {
			$lead_id = (string) ( $lr['phone'] ?? '' );
		}
		estandar_chatbot_log_interaction(
			[
				'question'           => '[lead_capture]',
				'answer'             => 'lead_id:' . (int) ( $result['lead_id'] ?? 0 ) . ';status:' . (string) ( $result['status'] ?? '' ),
				'interaction_type'   => 'lead_capture',
				'session_key'        => $session_key,
				'lead_identifier'    => $lead_id,
				'page_url'           => (string) ( $lead['source_page_url'] ?? '' ),
				'ip'                 => $ip,
				'ua'                 => $meta['ua'] ?? '',
				'server_request_url' => $meta['url'] ?? '',
				'channel'            => $channel,
			]
		);
	}

	$crm = [ 'ok' => false, 'status' => 0, 'message' => 'not_executed' ];
	if ( $is_duplicate_notice ) {
		$crm['message'] = 'duplicate_session_phone_window';
	} elseif ( function_exists( 'estandar_chatbot_dispatch_crm_lead' ) ) {
			$crm = estandar_chatbot_dispatch_crm_lead( $result, [
				'channel'         => $channel,
				'session_id'      => $session_key,
				'intent'          => (string) ( $lead['intent'] ?? '' ),
				'lead_intent'     => (string) ( $lead['lead_intent'] ?? '' ),
				'conversation_summary' => (string) ( $lead['conversation_summary'] ?? '' ),
				'last_user_message' => (string) ( $lead['last_user_message'] ?? '' ),
				'source_page_url' => (string) ( $lead['source_page_url'] ?? '' ),
			] );
		if ( ! empty( $result['lead_id'] ) && function_exists( 'estandar_chatbot_mark_lead_crm_sync' ) ) {
			estandar_chatbot_mark_lead_crm_sync(
				(int) $result['lead_id'],
				! empty( $crm['ok'] ) ? 'synced' : 'failed',
				(string) ( $crm['message'] ?? '' )
			);
		}
	}
	$crm_enabled = (int) get_option( 'chatbot_crm_enabled', 0 ) === 1;
	$crm_delivery = [
		'delivery_channel' => 'crm',
		'status'           => ! empty( $crm['ok'] ) ? 'success' : ( $crm_enabled ? 'failed' : 'skipped' ),
		'message'          => (string) ( $crm['message'] ?? '' ),
		'http_status'      => (int) ( $crm['status'] ?? 0 ),
	];
	if (
		$force_human &&
		$email_delivery['status'] !== 'success' &&
		$webhook_delivery['status'] !== 'success' &&
		$crm_delivery['status'] !== 'success'
	) {
		estandar_chatbot_telemetry_event( 'error', 'CHATBOT_ESCALATION_FAILURE', 'Chatbot human escalation had no successful delivery channel.', [
			'lead_id' => (int) ( $result['lead_id'] ?? 0 ),
			'channel' => $channel,
			'email_status' => sanitize_key( (string) $email_delivery['status'] ),
			'webhook_status' => sanitize_key( (string) $webhook_delivery['status'] ),
			'crm_status' => sanitize_key( (string) $crm_delivery['status'] ),
		] );
	}

	if ( function_exists( 'estandar_chatbot_log_event' ) ) {
		$lead_event_id    = (int) ( $result['lead_id'] ?? 0 );
		$lead_event_email = (string) ( $result['lead']['email'] ?? '' );
		$lead_event_phone = (string) ( $result['lead']['phone'] ?? '' );
		estandar_chatbot_log_event([
			'event_name' => $force_human ? 'lead.human_handoff_requested' : 'lead.captured',
			'channel'    => $channel,
			'session_key'=> $session_key,
			'lead_id'    => $lead_event_id,
			'lead_email' => $lead_event_email,
			'lead_phone' => $lead_event_phone,
				'meta'       => [
					'status' => (string) ( $result['status'] ?? '' ),
					'lead_score' => (int) ( $score_data['score'] ?? 0 ),
					'tier'   => (string) $score_data['tier'],
					'route'  => (string) $score_data['route'],
					'lead_quality' => (string) ( $lead['lead_quality'] ?? '' ),
					'commercial_priority' => (string) ( $lead['commercial_priority'] ?? '' ),
					'intent_category' => (string) ( $lead['intent_category'] ?? '' ),
					'intent_mismatch' => ! empty( $lead['intent_mismatch'] ),
					'source_page_url' => (string) ( $lead['source_page_url'] ?? '' ),
					'contact_quality' => (string) ( $result['lead']['contact_quality'] ?? CONTACT_VALID ),
					'contact_warning' => (string) ( $result['lead']['contact_warning'] ?? CONTACT_WARNING_NONE ),
					'suspect_contacts' => ( $result['lead']['contact_quality'] ?? '' ) === CONTACT_SUSPECT ? 1 : 0,
				],
		]);

		foreach ( [ $email_delivery, $webhook_delivery, $crm_delivery ] as $delivery ) {
			if ( function_exists( 'estandar_chatbot_log_lead_delivery_status' ) ) {
				estandar_chatbot_log_lead_delivery_status( $result, $channel, $session_key, $delivery );
			} else {
				estandar_chatbot_log_event([
					'event_name' => 'lead.delivery_status',
					'channel'    => $channel,
					'session_key'=> $session_key,
					'lead_id'    => $lead_event_id,
					'lead_email' => $lead_event_email,
					'lead_phone' => $lead_event_phone,
					'meta'       => [
						'lead_id'          => $lead_event_id,
						'delivery_channel' => (string) ( $delivery['delivery_channel'] ?? '' ),
						'status'           => (string) ( $delivery['status'] ?? '' ),
						'message'          => (string) ( $delivery['message'] ?? '' ),
						'http_status'      => (int) ( $delivery['http_status'] ?? 0 ),
						'timestamp'        => current_time( 'mysql' ),
					],
				]);
			}
		}
	}

	// --------------------------------------------------
	// Analytics: chatbot_lead_created
	// Emite evento normalizado hacia Estandar Analytics si ea_sid está presente.
	// No bloquea el flujo — falla silenciosamente.
	// --------------------------------------------------
	if ( $ea_sid !== '' && function_exists( 'estandar_analytics_record_event' ) ) {
		estandar_analytics_record_event( $ea_sid, 'chatbot_lead_created', [
			'label'               => sanitize_key( $channel ),
			'page_url'            => (string) ( $lead['source_page_url'] ?? '' ),
			'value'               => sanitize_key( (string) ( $score_data['tier'] ?? '' ) ),
			'source'              => 'chatbot',
			'chatbot_session_key' => $session_key,
			'lead_id'             => (int) ( $result['lead_id'] ?? 0 ),
			'project_slug'        => sanitize_key( (string) ( $lead['project_slug'] ?? '' ) ),
		] );
	}

	// --------------------------------------------------
	// Respuesta final
	// --------------------------------------------------
	wp_send_json_success([
		'status'  => $result['status'],
		'message' => $result['message'],
		'score'   => (int) $score_data['score'],
		'tier'    => (string) $score_data['tier'],
		'route'   => (string) $score_data['route'],
		'escalation_requested' => $force_human,
		'webhook' => $webhook,
		'crm'     => $crm,
		'lead_id' => (int) ( $result['lead_id'] ?? 0 ),
	]);
}
