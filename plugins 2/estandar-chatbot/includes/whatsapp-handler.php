<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'rest_api_init', 'estandar_chatbot_register_whatsapp_routes' );

function estandar_chatbot_register_whatsapp_routes() {
	register_rest_route( 'estandar-chatbot/v1', '/whatsapp/webhook', [
		[
			'methods'             => 'GET',
			'callback'            => 'estandar_chatbot_whatsapp_verify_webhook',
			'permission_callback' => '__return_true',
		],
		[
			'methods'             => 'POST',
			'callback'            => 'estandar_chatbot_whatsapp_receive_webhook',
			'permission_callback' => '__return_true',
		],
	] );
}

function estandar_chatbot_whatsapp_is_enabled() {
	return (int) get_option( 'chatbot_whatsapp_enabled', 0 ) === 1;
}

function estandar_chatbot_whatsapp_has_signature_guard() {
	return trim( (string) get_option( 'chatbot_whatsapp_app_secret', '' ) ) !== '';
}

function estandar_chatbot_whatsapp_verify_webhook( WP_REST_Request $request ) {
	$mode      = sanitize_text_field( (string) $request->get_param( 'hub_mode' ) );
	$token     = sanitize_text_field( (string) $request->get_param( 'hub_verify_token' ) );
	$challenge = sanitize_text_field( (string) $request->get_param( 'hub_challenge' ) );

	if ( $mode === '' ) {
		$mode = sanitize_text_field( (string) $request->get_param( 'hub.mode' ) );
	}
	if ( $token === '' ) {
		$token = sanitize_text_field( (string) $request->get_param( 'hub.verify_token' ) );
	}
	if ( $challenge === '' ) {
		$challenge = sanitize_text_field( (string) $request->get_param( 'hub.challenge' ) );
	}

	$expected = (string) get_option( 'chatbot_whatsapp_verify_token', '' );
	if ( $mode === 'subscribe' && $expected !== '' && hash_equals( $expected, $token ) ) {
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo $challenge;
		exit;
	}

	return new WP_REST_Response( [ 'ok' => false, 'message' => 'invalid_verify_token' ], 403 );
}

function estandar_chatbot_whatsapp_receive_webhook( WP_REST_Request $request ) {
	if ( ! estandar_chatbot_whatsapp_is_enabled() ) {
		return new WP_REST_Response( [ 'ok' => true, 'message' => 'whatsapp_disabled' ], 200 );
	}
	if ( ! estandar_chatbot_whatsapp_has_signature_guard() ) {
		return new WP_REST_Response( [ 'ok' => false, 'message' => 'missing_app_secret' ], 503 );
	}

	$raw = (string) $request->get_body();
	$raw = trim( $raw );
	if ( $raw === '' ) {
		return new WP_REST_Response( [ 'ok' => false, 'message' => 'empty_body' ], 400 );
	}

	if ( ! estandar_chatbot_whatsapp_validate_signature( $raw ) ) {
		return new WP_REST_Response( [ 'ok' => false, 'message' => 'invalid_signature' ], 403 );
	}

	$data = json_decode( $raw, true );
	if ( ! is_array( $data ) ) {
		return new WP_REST_Response( [ 'ok' => false, 'message' => 'invalid_json' ], 400 );
	}

	$count = estandar_chatbot_whatsapp_process_payload( $data );
	return new WP_REST_Response( [ 'ok' => true, 'processed' => $count ], 200 );
}

function estandar_chatbot_whatsapp_validate_signature( $raw_body ) {
	$app_secret = (string) get_option( 'chatbot_whatsapp_app_secret', '' );
	if ( $app_secret === '' ) {
		return true;
	}

	$header = '';
	if ( isset( $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ) ) {
		$header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ) );
	}
	if ( $header === '' || strpos( $header, 'sha256=' ) !== 0 ) {
		return false;
	}

	$given = substr( $header, 7 );
	$calc  = hash_hmac( 'sha256', $raw_body, $app_secret );
	return hash_equals( $calc, $given );
}

function estandar_chatbot_whatsapp_process_payload( array $payload ) {
	$processed = 0;
	$entries = isset( $payload['entry'] ) && is_array( $payload['entry'] ) ? $payload['entry'] : [];
	foreach ( $entries as $entry ) {
		$changes = isset( $entry['changes'] ) && is_array( $entry['changes'] ) ? $entry['changes'] : [];
		foreach ( $changes as $change ) {
			$value = isset( $change['value'] ) && is_array( $change['value'] ) ? $change['value'] : [];
			$messages = isset( $value['messages'] ) && is_array( $value['messages'] ) ? $value['messages'] : [];
			$contacts = isset( $value['contacts'] ) && is_array( $value['contacts'] ) ? $value['contacts'] : [];

			$contact_map = [];
			foreach ( $contacts as $contact ) {
				$waid = sanitize_text_field( (string) ( $contact['wa_id'] ?? '' ) );
				if ( $waid === '' ) continue;
				$contact_map[ $waid ] = sanitize_text_field( (string) ( $contact['profile']['name'] ?? '' ) );
			}

			foreach ( $messages as $message ) {
				$msg_id = sanitize_text_field( (string) ( $message['id'] ?? '' ) );
				if ( $msg_id !== '' ) {
					$key = 'ecb_wa_msg_' . md5( $msg_id );
					if ( get_transient( $key ) ) {
						continue;
					}
					set_transient( $key, 1, 6 * HOUR_IN_SECONDS );
				}

				$from = sanitize_text_field( (string) ( $message['from'] ?? '' ) );
				if ( $from === '' ) continue;

				$text = estandar_chatbot_whatsapp_extract_message_text( $message );
				if ( $text === '' ) continue;

				// P1 — rate limit per phone: 10 msg/min, silent skip, keep 200 OK
				$wa_rl_key = 'ecb_wa_rl_' . md5( $from );
				$wa_hits   = (int) get_transient( $wa_rl_key );
				if ( $wa_hits >= 10 ) { continue; }
				set_transient( $wa_rl_key, $wa_hits + 1, 60 );

				$name = isset( $contact_map[ $from ] ) ? $contact_map[ $from ] : '';
				$reply = '';
				$session_key = 'wa_' . $from;
				$conversation_id = 0;

				if ( function_exists( 'estandar_chatbot_append_conversation_message' ) ) {
					$conversation_id = (int) estandar_chatbot_append_conversation_message(
						$session_key,
						'whatsapp',
						'user',
						$text,
						[ 'phone' => $from, 'name' => $name ]
					);
				}

				if ( estandar_chatbot_whatsapp_is_human_handoff_request( $text ) ) {
					$attribution = estandar_chatbot_whatsapp_resolve_sid_attribution( $text );
					$reply = estandar_chatbot_whatsapp_handle_human_handoff( $from, $name, $text, $session_key, $attribution );
				} else {
					$ai = estandar_chatbot_whatsapp_generate_ai_answer( $text, (string) get_option( 'chatbot_idioma', 'es' ), $session_key );
					$reply = ! empty( $ai['ok'] ) ? (string) $ai['answer'] : 'Gracias por tu mensaje. Te responderemos en breve.';
				}

				$reply = trim( $reply );
				if ( $reply !== '' ) {
					estandar_chatbot_whatsapp_send_text( $from, $reply );
					if ( function_exists( 'estandar_chatbot_append_conversation_message' ) ) {
						$conversation_id = (int) estandar_chatbot_append_conversation_message(
							$session_key,
							'whatsapp',
							'assistant',
							$reply,
							[ 'phone' => $from ]
						);
					}
				}

				if ( function_exists( 'estandar_chatbot_log_interaction' ) ) {
					estandar_chatbot_log_interaction([
						'question' => $text,
						'answer'   => $reply,
						'ip'       => 'whatsapp',
						'ua'       => 'meta-webhook',
						'url'      => rest_url( 'estandar-chatbot/v1/whatsapp/webhook' ),
					]);
				}

				if ( function_exists( 'estandar_chatbot_log_event' ) ) {
					estandar_chatbot_log_event([
						'event_name'      => 'whatsapp.message_processed',
						'channel'         => 'whatsapp',
						'session_key'     => $session_key,
						'conversation_id' => $conversation_id,
						'lead_phone'      => $from,
						'meta'            => [
							'message_id' => $msg_id,
							'name'       => $name,
						],
					]);
				}

				$processed++;
			}
		}
	}

	return $processed;
}

function estandar_chatbot_whatsapp_extract_message_text( array $message ) {
	$type = sanitize_key( (string) ( $message['type'] ?? '' ) );
	if ( $type === 'text' ) {
		return sanitize_textarea_field( (string) ( $message['text']['body'] ?? '' ) );
	}
	if ( $type === 'button' ) {
		return sanitize_textarea_field( (string) ( $message['button']['text'] ?? '' ) );
	}
	if ( $type === 'interactive' ) {
		$button = (string) ( $message['interactive']['button_reply']['title'] ?? '' );
		$list   = (string) ( $message['interactive']['list_reply']['title'] ?? '' );
		$raw    = $button !== '' ? $button : $list;
		return sanitize_textarea_field( $raw );
	}
	return '';
}

function estandar_chatbot_whatsapp_is_human_handoff_request( $text ) {
	$text = strtolower( (string) $text );
	$tokens = [ 'humano', 'asesor', 'agente', 'persona real', 'ejecutivo' ];
	foreach ( $tokens as $token ) {
		if ( strpos( $text, $token ) !== false ) {
			return true;
		}
	}
	return false;
}

/**
 * Resolve the sid_ref embedded in an inbound WhatsApp message (appended by
 * Estandar_Leads_Whatsapp_Redirect's outbound message, see
 * class-estandar-leads-whatsapp-redirect.php) and recover the original click
 * attribution (gclid/gbraid/wbraid/utm/landing_url) it points to.
 *
 * sid_ref -> wp_es_whatsapp_sessions (resolve session_id/project_slug)
 *         -> estandar_analytics_get_attribution_by_session() (recover attribution)
 *
 * Returns an all-empty attribution array when no sid_ref is present in the
 * text or it does not resolve — i.e. a genuinely organic inbound message,
 * which must remain unattributed.
 */
function estandar_chatbot_whatsapp_resolve_sid_attribution( $text ) {
	// Field-list shape is required below to drive the recovered-attribution merge
	// loop, not just as default values — degrades to a literal list if Primer
	// (optional dependency) is inactive, per CORE_OPTIONALITY_V1.1.
	$empty = function_exists( 'estandar_core_build_attribution_context' )
		? estandar_core_build_attribution_context()
		: [
			'utm_source' => '', 'utm_medium' => '', 'utm_campaign' => '', 'utm_term' => '', 'utm_content' => '',
			'gclid' => '', 'gbraid' => '', 'wbraid' => '', 'fbclid' => '',
			'landing_url' => '', 'referrer' => '', 'session_id' => '',
		];

	if ( ! preg_match( '/\b([A-Z]{2}-[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{6})\b/i', (string) $text, $matches ) ) {
		return $empty;
	}
	$sid_ref = strtoupper( $matches[1] );

	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare(
		"SELECT session_id FROM {$wpdb->prefix}es_whatsapp_sessions WHERE sid_ref = %s LIMIT 1",
		$sid_ref
	), ARRAY_A );

	if ( empty( $row ) ) {
		return $empty;
	}

	$session_id = sanitize_text_field( (string) ( $row['session_id'] ?? '' ) );
	if ( $session_id === '' || ! function_exists( 'estandar_analytics_get_attribution_by_session' ) ) {
		$empty['session_id'] = $session_id;
		return $empty;
	}

	$recovered   = estandar_analytics_get_attribution_by_session( $session_id );
	$attribution = $empty;
	if ( is_array( $recovered ) ) {
		foreach ( $empty as $field => $default ) {
			if ( $field === 'session_id' ) {
				continue;
			}
			$attribution[ $field ] = (string) ( $recovered[ $field ] ?? '' );
		}
	}
	$attribution['session_id'] = $session_id;

	return $attribution;
}

function estandar_chatbot_whatsapp_handle_human_handoff( $phone, $name, $requirement, $session_key = '', array $attribution = [] ) {
	$lead = [
		'phone'                => (string) $phone,
		'name'                 => (string) $name,
		'intent'               => 'humano',
		'requirement'          => (string) $requirement,
		'force_human'          => '1',
		'escalation_requested' => '1',
		'escalation_channel'   => 'whatsapp',
	];

	$score_data = function_exists( 'estandar_chatbot_score_and_route_lead' )
		? estandar_chatbot_score_and_route_lead( $lead )
		: [ 'score' => 0, 'tier' => 'cold', 'route' => 'human_handoff' ];

	$lead['lead_score'] = (string) (int) ( $score_data['score'] ?? 0 );
	$lead['lead_tier']  = (string) ( $score_data['tier'] ?? 'cold' );
	$lead['lead_route'] = (string) ( $score_data['route'] ?? 'human_handoff' );

	if ( function_exists( 'estandar_chatbot_save_lead' ) ) {
			$result = estandar_chatbot_save_lead( $lead );
			if ( is_array( $result ) && in_array( (string) ( $result['status'] ?? '' ), [ 'created', 'updated' ], true ) ) {
				estandar_chatbot_whatsapp_emit_lead_captured( $lead, $result, $session_key, $attribution );
				if ( $session_key !== '' && function_exists( 'estandar_chatbot_bind_conversation_to_lead' ) ) {
					estandar_chatbot_bind_conversation_to_lead( $session_key, 'whatsapp', (array) ( $result['lead'] ?? [] ) );
				}
			$email_delivery = [
				'delivery_channel' => 'email',
				'status'           => 'skipped',
				'message'          => 'not_available',
				'recipients_count' => 0,
			];
			if ( function_exists( 'estandar_chatbot_notify_lead_by_email_result' ) ) {
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
			if ( function_exists( 'estandar_chatbot_log_lead_delivery_status' ) ) {
				estandar_chatbot_log_lead_delivery_status( $result, 'whatsapp', $session_key, $email_delivery );
			}
			if ( function_exists( 'estandar_chatbot_dispatch_lead_webhook' ) ) {
				estandar_chatbot_dispatch_lead_webhook([
					'event'                => 'lead.human_handoff',
					'timestamp'            => current_time( 'mysql' ),
					'site'                 => home_url( '/' ),
					'status'               => $result['status'],
					'score'                => (int) ( $score_data['score'] ?? 0 ),
					'tier'                 => (string) ( $score_data['tier'] ?? 'cold' ),
					'route'                => (string) ( $score_data['route'] ?? 'human_handoff' ),
					'intent'               => 'humano',
					'requirement'          => (string) $requirement,
					'escalation_requested' => true,
					'force_human'          => true,
					'channel'              => 'whatsapp',
					'session_id'           => $session_key,
					'source_page_url'      => '',
					'lead'                 => (array) ( $result['lead'] ?? [] ),
				]);
			}
			if ( function_exists( 'estandar_chatbot_dispatch_crm_lead' ) ) {
				$crm = estandar_chatbot_dispatch_crm_lead( $result, [
					'channel'    => 'whatsapp',
					'session_id' => $session_key,
					'intent'     => 'humano',
				] );
				if ( ! empty( $result['lead_id'] ) && function_exists( 'estandar_chatbot_mark_lead_crm_sync' ) ) {
					estandar_chatbot_mark_lead_crm_sync(
						(int) $result['lead_id'],
						! empty( $crm['ok'] ) ? 'synced' : 'failed',
						(string) ( $crm['message'] ?? '' )
					);
				}
			}
		}
	}

	return (string) get_option( 'chatbot_human_escalation_text', 'Te derivaré ahora con una persona del equipo.' );
}

function estandar_chatbot_whatsapp_emit_lead_captured( array $lead, array $result, $session_key, array $attribution = [] ) {
	$saved_lead = is_array( $result['lead'] ?? null ) ? $result['lead'] : [];

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
			'message'        => sanitize_textarea_field( (string) ( $lead['requirement'] ?? '' ) ),
			'source_form_id' => 'chatbot_whatsapp',
		],
		'context'        => [
			'project_slug'            => function_exists( 'estandar_chatbot_normalize_project_slug' ) ? estandar_chatbot_normalize_project_slug( (string) ( $lead['project_slug'] ?? $lead['project_area'] ?? '' ) ) : sanitize_key( (string) ( $lead['project_slug'] ?? '' ) ),
			'project_area'            => sanitize_text_field( (string) ( $lead['project_area'] ?? '' ) ),
			'intent'                  => sanitize_key( (string) ( $lead['intent'] ?? '' ) ),
			'lead_intent'             => sanitize_key( (string) ( $lead['lead_intent'] ?? $lead['intent'] ?? '' ) ),
			'requirement'             => sanitize_textarea_field( (string) ( $lead['requirement'] ?? '' ) ),
			'source_page_url'         => '',
			'source_channel'          => 'whatsapp',
			'escalation_channel'      => 'whatsapp',
			'contact_quality'         => sanitize_key( (string) ( $saved_lead['contact_quality'] ?? CONTACT_VALID ) ),
			'contact_warning'         => sanitize_key( (string) ( $saved_lead['contact_warning'] ?? CONTACT_WARNING_NONE ) ),
		],
		'chatbot'        => [
			'chatbot_status'       => sanitize_key( (string) ( $result['status'] ?? '' ) ),
			'chatbot_lead_id'      => (int) ( $result['lead_id'] ?? 0 ),
			'chatbot_session_key'  => sanitize_text_field( (string) $session_key ),
			'force_human'          => true,
			'escalation_requested' => true,
			'escalation_channel'   => 'whatsapp',
			'contact_quality'      => sanitize_key( (string) ( $saved_lead['contact_quality'] ?? CONTACT_VALID ) ),
			'contact_warning'      => sanitize_key( (string) ( $saved_lead['contact_warning'] ?? CONTACT_WARNING_NONE ) ),
			'suspect_contacts'     => ( $saved_lead['contact_quality'] ?? '' ) === CONTACT_SUSPECT ? 1 : 0,
		],
		'meta'           => [
			'source_channel'       => 'whatsapp',
			'chatbot_lead_id'      => (int) ( $result['lead_id'] ?? 0 ),
			'chatbot_session_key'  => sanitize_text_field( (string) $session_key ),
			'force_human'          => true,
			'escalation_requested' => true,
			'escalation_channel'   => 'whatsapp',
		],
		// $attribution already carries the full canonical shape — resolve_sid_attribution()
		// guarantees it regardless of Primer availability. Re-running it through the
		// shared builder here (when available) just normalizes any unexpected keys.
		'attribution'    => function_exists( 'estandar_core_build_attribution_context' )
			? estandar_core_build_attribution_context( $attribution )
			: $attribution,
		'request'        => [
			'ip_address' => 'whatsapp',
			'user_agent' => 'meta-webhook',
		],
	] );
}

function estandar_chatbot_whatsapp_generate_ai_answer( $message, $lang = 'es', $session_key = '' ) {
	$message = sanitize_textarea_field( (string) $message );
	// P4 — clamp to 1000 chars before OpenAI call
	$message = function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 1000 ) : substr( $message, 0, 1000 );
	$lang    = sanitize_text_field( (string) $lang );
	if ( $message === '' ) {
		return [ 'ok' => false, 'answer' => '', 'message' => 'empty_message' ];
	}

	$api_key = function_exists( 'estandar_chatbot_get_openai_api_key' )
		? estandar_chatbot_get_openai_api_key()
		: (string) get_option( 'chatbot_openai_api_key', '' );
	if ( $api_key === '' ) {
		return [ 'ok' => false, 'answer' => '', 'message' => 'missing_api_key' ];
	}

	$context = '';
	if ( function_exists( 'estandar_chatbot_get_context' ) ) {
		$context = trim( (string) estandar_chatbot_get_context() );
	}

	$system_prompt = function_exists( 'estandar_chatbot_build_system_prompt' )
		? estandar_chatbot_build_system_prompt( $lang, $context )
		: ( 'Eres un asistente profesional. Responde en ' . sanitize_text_field( $lang ) . '.' );

	$messages = [ [ 'role' => 'system', 'content' => $system_prompt ] ];
	if ( $session_key !== '' && function_exists( 'estandar_chatbot_get_conversation_messages' ) ) {
		$history = estandar_chatbot_get_conversation_messages( $session_key, 'whatsapp', 10 );
		foreach ( $history as $item ) {
			$role = sanitize_key( (string) ( $item['role'] ?? '' ) );
			$content = sanitize_textarea_field( (string) ( $item['content'] ?? '' ) );
			if ( $content === '' || ! in_array( $role, [ 'user', 'assistant' ], true ) ) {
				continue;
			}
			$messages[] = [ 'role' => $role, 'content' => $content ];
		}
	}
	$messages[] = [ 'role' => 'user', 'content' => $message ];

	$response = wp_remote_post(
		'https://api.openai.com/v1/chat/completions',
		[
			'timeout' => 30,
			'headers' => [
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
			],
			'body' => wp_json_encode( array_merge(
				estandar_chatbot_get_openai_params(),
				[ 'messages' => $messages ]
			) ),
		]
	);

	if ( is_wp_error( $response ) ) {
		return [ 'ok' => false, 'answer' => '', 'message' => $response->get_error_message() ];
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = (string) wp_remote_retrieve_body( $response );
	$data = json_decode( $body, true );
	$answer = trim( (string) ( $data['choices'][0]['message']['content'] ?? '' ) );

	if ( $code !== 200 || $answer === '' ) {
		$openai_message = 'invalid_openai_response';
		if ( is_array( $data ) && isset( $data['error']['message'] ) ) {
			$openai_message = (string) $data['error']['message'];
		}
		return [ 'ok' => false, 'answer' => '', 'message' => 'invalid_openai_response' ];
	}

	if ( function_exists( 'mb_substr' ) && function_exists( 'mb_strlen' ) && mb_strlen( $answer ) > 1000 ) {
		$answer = mb_substr( $answer, 0, 1000 ) . '...';
	} elseif ( strlen( $answer ) > 1000 ) {
		$answer = substr( $answer, 0, 1000 ) . '...';
	}

	return [ 'ok' => true, 'answer' => $answer, 'message' => 'ok' ];
}

function estandar_chatbot_whatsapp_send_text( $to, $text ) {
	$to = preg_replace( '/\D+/', '', (string) $to );
	$text = sanitize_textarea_field( (string) $text );
	if ( $to === '' || $text === '' ) {
		return [ 'ok' => false, 'status' => 0, 'message' => 'missing_to_or_text' ];
	}

	$phone_number_id = sanitize_text_field( (string) get_option( 'chatbot_whatsapp_phone_number_id', '' ) );
	$access_token = sanitize_text_field( (string) get_option( 'chatbot_whatsapp_access_token', '' ) );
	if ( $phone_number_id === '' || $access_token === '' ) {
		return [ 'ok' => false, 'status' => 0, 'message' => 'missing_whatsapp_credentials' ];
	}

	$url = 'https://graph.facebook.com/v22.0/' . rawurlencode( $phone_number_id ) . '/messages';
	$response = wp_remote_post( $url, [
		'timeout' => 20,
		'headers' => [
			'Content-Type'  => 'application/json',
			'Authorization' => 'Bearer ' . $access_token,
		],
		'body' => wp_json_encode([
			'messaging_product' => 'whatsapp',
			'to'                => $to,
			'type'              => 'text',
			'text'              => [
				'preview_url' => false,
				'body'        => $text,
			],
		]),
	] );

	if ( is_wp_error( $response ) ) {
		return [ 'ok' => false, 'status' => 0, 'message' => $response->get_error_message() ];
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	return [
		'ok'      => $status >= 200 && $status < 300,
		'status'  => $status,
		'message' => (string) wp_remote_retrieve_body( $response ),
	];
}
