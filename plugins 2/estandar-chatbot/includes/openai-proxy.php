<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ======================================================
 * OPENAI PROXY — ESTANDAR CHATBOT IA
 * Versión: PRODUCCIÓN ESTABLE (API KEY FIXED)
 * ======================================================
 * - Nonce obligatorio
 * - Rate limit por IP
 * - Contexto local (TXT / Pinecone-ready)
 * - Memoria corta controlada
 * - Logs compatibles
 * - API Key desde ADMIN (fallback a constant)
 * - Respuesta estable para frontend
 */

function estandar_chatbot_handle_ai_request() {
	$started_at = microtime( true );

	/* ======================================================
	 * 1. SEGURIDAD BÁSICA
	 * ====================================================== */

	estandar_chatbot_verify_nonce_or_die();

	if ( empty( $_POST['message'] ) ) {
		wp_send_json_error([ 'message' => 'Mensaje vacío.' ], 400);
	}

	$message = sanitize_textarea_field( wp_unslash( $_POST['message'] ) );
	if ( $message === '' ) {
		wp_send_json_error([ 'message' => 'Mensaje inválido.' ], 400);
	}

	// P3 — clamp message to 2000 chars (anti prompt-inflation)
	$message = function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 2000 ) : substr( $message, 0, 2000 );

	$lang = isset($_POST['lang']) ? sanitize_text_field($_POST['lang']) : 'es';
	$session_key = isset( $_POST['session_id'] ) ? estandar_chatbot_normalize_session_key( wp_unslash( $_POST['session_id'] ) ) : '';
	$channel = isset( $_POST['channel'] ) ? estandar_chatbot_normalize_channel( wp_unslash( $_POST['channel'] ) ) : 'web';

	$page_url = '';
	if ( function_exists( 'estandar_chatbot_sanitize_source_page_url' ) ) {
		$page_url = estandar_chatbot_sanitize_source_page_url( isset( $_POST['page_url'] ) ? wp_unslash( $_POST['page_url'] ) : '' );
	}
	$lead_identifier = isset( $_POST['lead_identifier'] ) ? sanitize_text_field( wp_unslash( $_POST['lead_identifier'] ) ) : '';

	/* ======================================================
	 * 2. RATE LIMIT (ANTI-ABUSO)
	 * ====================================================== */

	$meta = estandar_chatbot_get_request_meta();
	$ip   = $meta['ip'] ?: 'unknown';
	$session_fingerprint = $session_key !== '' ? $session_key : 'anon';

	$rl_key  = 'ecb_rl_' . md5( $ip . '|' . $session_fingerprint );
	$rl_hits = (int) get_transient( $rl_key );

	if ( $rl_hits >= 20 ) {
		wp_send_json_error([
			'message' => 'Demasiadas solicitudes. Intenta nuevamente en unos minutos.'
		], 429);
	}

	set_transient( $rl_key, $rl_hits + 1, 60 ); // 20 req / minuto

	$direct_lead = estandar_chatbot_extract_direct_lead_from_message( $message );
	if ( estandar_chatbot_direct_lead_is_complete( $direct_lead ) ) {
		estandar_chatbot_capture_direct_lead_from_ai_request( $direct_lead, $message, $session_key, $channel, $page_url, $meta );
	}

	/* ======================================================
	 * 3. HISTORIAL (MEMORIA CORTA CONTROLADA)
	 * P5 — Fuente autoritativa: servidor primero, cliente como fallback
	 *       solo cuando no hay session_key y el servidor no devuelve nada.
	 *       Roles permitidos: user | assistant (nunca system).
	 *       Contenido clampeado a 2000 chars por mensaje.
	 * ====================================================== */

	$history = [];

	// Fuente primaria: historial almacenado en servidor
	if ( $session_key !== '' && function_exists( 'estandar_chatbot_get_conversation_messages' ) ) {
		$stored = estandar_chatbot_get_conversation_messages( $session_key, $channel, 12 );
		foreach ( $stored as $item ) {
			if ( empty( $item['role'] ) || empty( $item['content'] ) ) {
				continue;
			}
			$role = sanitize_key( (string) $item['role'] );
			if ( ! in_array( $role, [ 'user', 'assistant' ], true ) ) {
				continue;
			}
			$content = sanitize_textarea_field( (string) $item['content'] );
			$content = function_exists( 'mb_substr' ) ? mb_substr( $content, 0, 2000 ) : substr( $content, 0, 2000 );
			if ( $content === '' ) {
				continue;
			}
			$history[] = [ 'role' => $role, 'content' => $content ];
		}
	}

	// Fallback: historial del cliente SOLO si el servidor no devolvió nada
	// (session_key presente pero servidor vacío = primera conversación o tabla ausente)
	if ( empty( $history ) && ! empty( $_POST['history'] ) ) {
		$decoded = json_decode( wp_unslash( $_POST['history'] ), true );
		if ( is_array( $decoded ) ) {
			foreach ( array_slice( $decoded, -12 ) as $h ) {
				if ( ! isset( $h['role'], $h['content'] ) ) {
					continue;
				}
				$role = sanitize_key( (string) $h['role'] );
				if ( ! in_array( $role, [ 'user', 'assistant' ], true ) ) {
					continue;
				}
				$content = sanitize_textarea_field( (string) $h['content'] );
				$content = function_exists( 'mb_substr' ) ? mb_substr( $content, 0, 2000 ) : substr( $content, 0, 2000 );
				if ( $content === '' ) {
					continue;
				}
				$history[] = [ 'role' => $role, 'content' => $content ];
			}
		}
	}

		/* ======================================================
		 * 4. API KEY (LOCAL -> OPTIONAL SHARED FALLBACK)
		 * ====================================================== */

		$api_key = function_exists( 'estandar_chatbot_get_openai_api_key' )
			? estandar_chatbot_get_openai_api_key()
			: (string) get_option( 'chatbot_openai_api_key', '' );

	if ( empty( $api_key ) ) {
		wp_send_json_error([
			'message' => 'OpenAI no está configurado. Agrega tu API Key en el panel del chatbot.'
		], 500);
	}

	/* ======================================================
	 * 5. CONTEXTO LOCAL (LOCAL-FIRST)
	 * ====================================================== */

	$context = '';
	if ( function_exists( 'estandar_chatbot_get_context' ) ) {
		$context = trim( (string) estandar_chatbot_get_context() );
	}

	$context_slug = function_exists( 'estandar_chatbot_contexts_validate_slug' )
		? estandar_chatbot_contexts_validate_slug( (string) ( $_POST['context_slug'] ?? '' ) )
		: '';
	$context_name = ( $context_slug !== '' && function_exists( 'estandar_chatbot_contexts_get_name' ) )
		? estandar_chatbot_contexts_get_name( $context_slug )
		: '';
	$context_prompt = ( $context_slug !== '' && function_exists( 'estandar_chatbot_contexts_get_prompt' ) )
		? estandar_chatbot_contexts_get_prompt( $context_slug )
		: '';

	$commercial_safety_answer = estandar_chatbot_get_commercial_safety_answer( $message, $context );

	/* ======================================================
	 * 6. PROMPT DE SISTEMA (ANTI-ALUCINACIÓN)
	 * ====================================================== */

	$system_prompt = function_exists( 'estandar_chatbot_build_system_prompt' )
		? estandar_chatbot_build_system_prompt( $lang, $context, $context_prompt )
		: ( 'Eres un asistente profesional. Responde en ' . sanitize_text_field( $lang ) . '.' );

	/* ======================================================
	 * 7. CONSTRUCCIÓN DE MENSAJES
	 * ====================================================== */

	$messages = [
		[ 'role' => 'system', 'content' => $system_prompt ],
	];

	foreach ( $history as $h ) {
		$messages[] = $h;
	}

	$messages[] = [
		'role'    => 'user',
		'content' => $message,
	];

	if ( $commercial_safety_answer !== '' ) {
		$answer = $commercial_safety_answer;
	} else {
		/* ======================================================
		 * 8. LLAMADA A IA (PROVIDER-AGNOSTIC via Gateway)
		 * ====================================================== */
		$ai_params = estandar_chatbot_get_openai_params();
		$ai_params['messages'] = $messages;

		if ( class_exists( 'Estandar_AI_Gateway' ) ) {
			$result = Estandar_AI_Gateway::chat( $ai_params );
		} else {
			// Legacy fallback — direct OpenAI call
			$api_key = function_exists( 'estandar_chatbot_get_openai_api_key' )
				? estandar_chatbot_get_openai_api_key()
				: (string) get_option( 'chatbot_openai_api_key', '' );
			if ( empty( $api_key ) ) {
				wp_send_json_error([ 'message' => 'IA no configurada.' ], 500);
			}
			$response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
				'timeout' => 30,
				'headers' => [ 'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $api_key, ],
				'body'    => wp_json_encode( array_merge( $ai_params, [ 'messages' => $messages ] ) ),
			] );
			$result = array( 'content' => '', 'finish_reason' => 'error', 'usage' => null, 'model' => '' );
			if ( ! is_wp_error( $response ) ) {
				$data = json_decode( wp_remote_retrieve_body( $response ), true );
				$result['content'] = trim( $data['choices'][0]['message']['content'] ?? '' );
				$result['finish_reason'] = $data['choices'][0]['finish_reason'] ?? 'error';
			}
		}

		if ( empty( $result['content'] ) ) {
			estandar_chatbot_telemetry_event( 'error', 'CHATBOT_AI_FAILURE', 'AI upstream returned an empty answer.', [
				'stage' => 'empty_answer', 'channel' => $channel,
			] );
			wp_send_json_error([ 'message' => 'El modelo devolvió una respuesta vacía.' ], 500);
		}
		$answer = $result['content'];
	}

	/* ======================================================
	 * 9. LOG (SI EXISTE HANDLER)
	 * ====================================================== */

	if ( function_exists( 'estandar_chatbot_log_interaction' ) ) {
		$pf = function_exists( 'estandar_chatbot_guess_potential_faq' ) && estandar_chatbot_guess_potential_faq( $message );
		estandar_chatbot_log_interaction([
			'question'           => $message,
			'answer'             => $answer,
			'ip'                 => $ip,
			'ua'                 => $meta['ua'],
			'server_request_url' => $meta['url'],
			'page_url'           => $page_url,
			'session_key'        => $session_key,
			'lead_identifier'    => $lead_identifier,
			'interaction_type'   => 'question',
			'potential_faq'      => $pf,
			'channel'            => $channel,
		]);
	}

	$conversation_id = 0;
	if ( $session_key !== '' && function_exists( 'estandar_chatbot_append_conversation_message' ) ) {
		$msg_meta = [ 'lang' => $lang ];
		if ( $context_slug !== '' ) {
			$msg_meta['context_slug'] = $context_slug;
			$msg_meta['context_name'] = $context_name;
		}

		// CONVERSATION_AI_META_V1 — capture AI metadata for this exchange
		if ( function_exists( 'estandar_chatbot_ai_conversation_meta' ) ) {
			$ai_params = function_exists( 'estandar_chatbot_get_openai_params' ) ? estandar_chatbot_get_openai_params() : [];
			$ai_meta = estandar_chatbot_ai_conversation_meta( array_merge( $ai_params, [
				'context_slug' => $context_slug,
			] ) );
			$msg_meta = array_merge( $msg_meta, $ai_meta );
		}

		$conversation_id = (int) estandar_chatbot_append_conversation_message( $session_key, $channel, 'user', $message, $msg_meta );
		$conversation_id = (int) estandar_chatbot_append_conversation_message( $session_key, $channel, 'assistant', $answer, $msg_meta );

		if ( function_exists( 'estandar_chatbot_upsert_conversation' ) ) {
			estandar_chatbot_upsert_conversation( $session_key, $channel, [
				'meta' => $ai_meta ?? [],
			] );
		}
	}

	if ( function_exists( 'estandar_chatbot_log_event' ) ) {
		estandar_chatbot_log_event([
			'event_name'      => 'ai.reply_generated',
			'channel'         => $channel,
			'session_key'     => $session_key,
			'conversation_id' => $conversation_id,
			'meta'            => [
				'lang'         => $lang,
				'latency_ms'   => (int) round( ( microtime( true ) - $started_at ) * 1000 ),
				'model'        => (string) ( $openai_params['model'] ?? '' ),
				'temperature'  => (float) ( $openai_params['temperature'] ?? 0.4 ),
				'max_tokens'   => (int) ( $openai_params['max_tokens'] ?? 500 ),
				'context_slug' => $context_slug,
				'context_name' => $context_name,
			],
		]);
	}

	/* ======================================================
	 * 10. RESPUESTA FINAL
	 * ====================================================== */

	wp_send_json_success([
		'answer' => $answer,
		'session_id' => $session_key,
	]);
}

function estandar_chatbot_extract_direct_lead_from_message( $message ) {
	$source = trim( (string) $message );
	$lead = [
		'name'  => '',
		'email' => '',
		'phone' => '',
	];

	if ( preg_match( '/([^\s@]+@[^\s@]+\.[^\s@]+)/i', $source, $m ) ) {
		$lead['email'] = sanitize_email( (string) $m[1] );
	}

	if ( preg_match( '/(\+?\d[\d\s().-]{6,}\d)/', $source, $m ) ) {
		$lead['phone'] = sanitize_text_field( preg_replace( '/[^\d+]/', '', (string) $m[1] ) );
	}

	$normalized = preg_replace( '/\s+/', ' ', $source );
	$patterns = [
		'/nombre\s*:\s*([a-z0-9áéíóúñ]+(?:\s+[a-z0-9áéíóúñ]+){0,3})(?=\s+(?:correo|email|tel[eé]fono|fono|celular|whatsapp)\s*:|$)/iu',
		'/mi nombre es\s+([a-z0-9áéíóúñ]+(?:\s+[a-z0-9áéíóúñ]+){0,3})/iu',
		'/me llamo\s+([a-z0-9áéíóúñ]+(?:\s+[a-z0-9áéíóúñ]+){0,3})/iu',
	];
	foreach ( $patterns as $pattern ) {
		if ( preg_match( $pattern, $normalized, $m ) ) {
			$lead['name'] = sanitize_text_field( (string) $m[1] );
			break;
		}
	}

	return $lead;
}

function estandar_chatbot_direct_lead_is_complete( array $lead ) {
	$name = trim( (string) ( $lead['name'] ?? '' ) );
	$contact = function_exists( 'estandar_chatbot_evaluate_contact' )
		? estandar_chatbot_evaluate_contact( $lead['email'] ?? '', $lead['phone'] ?? '' )
		: [ 'contact_quality' => CONTACT_REJECTED ];
	return $name !== '' && $contact['contact_quality'] !== CONTACT_REJECTED;
}

function estandar_chatbot_capture_direct_lead_from_ai_request( array $lead, $message, $session_key, $channel, $page_url, array $meta ) {
	if ( ! function_exists( 'estandar_chatbot_save_lead' ) ) {
		return;
	}

	$lead['intent'] = 'contact';
	$lead['lead_intent'] = 'contacto_humano';
	$lead['requirement'] = sanitize_textarea_field( (string) $message );
	$lead['last_user_message'] = sanitize_textarea_field( (string) $message );
	$lead['conversation_summary'] = 'Usuario entregó datos de contacto desde chatbot web.';
	$lead['source_channel'] = sanitize_key( (string) $channel );
	if ( $page_url !== '' ) {
		$lead['source_page_url'] = $page_url;
	}
	if ( $session_key !== '' ) {
		$lead['session_id'] = $session_key;
	}
	if ( function_exists( 'estandar_chatbot_detect_lead_context' ) ) {
		$lead = estandar_chatbot_detect_lead_context( $lead );
	}

	$result = estandar_chatbot_save_lead( $lead );
	if ( ! is_array( $result ) || ( $result['status'] ?? '' ) === 'error' ) {
		return;
	}

	$saved_lead = is_array( $result['lead'] ?? null ) ? $result['lead'] : [];
	$landing_url = (string) ( $lead['source_page_url'] ?? '' );

	if ( function_exists( 'estandar_core_build_attribution_context' ) ) {
		$attribution_context = estandar_core_build_attribution_context( [
			'landing_url' => esc_url_raw( $landing_url ),
		] );
	} else {
		// Minimal shape: UTM/click-id fields cannot be derived from the
		// landing URL without the canonical core builder. Make the
		// degradation visible instead of failing silently.
		$attribution_context = [
			'landing_url' => esc_url_raw( $landing_url ),
		];
		if ( function_exists( 'estandar_chatbot_telemetry_event' ) ) {
			estandar_chatbot_telemetry_event( 'warning', 'CHATBOT_ATTRIBUTION_CONTEXT_FALLBACK', 'Canonical attribution builder unavailable; direct lead emitted with minimal attribution shape (URL params not derived).', [
				'channel'     => sanitize_key( (string) $channel ),
				'has_landing' => $landing_url !== '',
			] );
		}
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
			'message'        => sanitize_textarea_field( (string) ( $lead['requirement'] ?? '' ) ),
			'source_form_id' => 'chatbot',
		],
		'context'        => [
			'project_slug'      => function_exists( 'estandar_chatbot_normalize_project_slug' ) ? estandar_chatbot_normalize_project_slug( (string) ( $lead['project_slug'] ?? $lead['project_area'] ?? '' ) ) : sanitize_key( (string) ( $lead['project_slug'] ?? '' ) ),
			'project_area'      => sanitize_text_field( (string) ( $lead['project_area'] ?? '' ) ),
			'intent'            => sanitize_key( (string) ( $lead['intent'] ?? '' ) ),
			'lead_intent'       => sanitize_key( (string) ( $lead['lead_intent'] ?? '' ) ),
			'requirement'       => sanitize_textarea_field( (string) ( $lead['requirement'] ?? '' ) ),
			'source_page_url'   => esc_url_raw( (string) ( $lead['source_page_url'] ?? '' ) ),
			'source_channel'    => sanitize_key( (string) $channel ),
			'contact_quality'   => sanitize_key( (string) ( $saved_lead['contact_quality'] ?? CONTACT_VALID ) ),
			'contact_warning'   => sanitize_key( (string) ( $saved_lead['contact_warning'] ?? CONTACT_WARNING_NONE ) ),
		],
		'chatbot'        => [
			'chatbot_status'      => sanitize_key( (string) ( $result['status'] ?? '' ) ),
			'chatbot_lead_id'     => (int) ( $result['lead_id'] ?? 0 ),
			'chatbot_session_key' => $session_key,
		],
		'attribution'    => $attribution_context,
		'request'        => [
			'ip_address' => sanitize_text_field( (string) ( $meta['ip'] ?? '' ) ),
			'user_agent' => sanitize_text_field( (string) ( $meta['ua'] ?? '' ) ),
		],
		'meta'           => [
			'contact_quality' => sanitize_key( (string) ( $saved_lead['contact_quality'] ?? CONTACT_VALID ) ),
			'contact_warning' => sanitize_key( (string) ( $saved_lead['contact_warning'] ?? CONTACT_WARNING_NONE ) ),
			'suspect_contacts'=> ( $saved_lead['contact_quality'] ?? '' ) === CONTACT_SUSPECT ? 1 : 0,
			'email_raw'        => sanitize_text_field( (string) ( $saved_lead['email_raw'] ?? '' ) ),
			'phone_raw'        => sanitize_text_field( (string) ( $saved_lead['phone_raw'] ?? '' ) ),
		],
	] );

	if ( $session_key !== '' && function_exists( 'estandar_chatbot_bind_conversation_to_lead' ) ) {
		estandar_chatbot_bind_conversation_to_lead( $session_key, $channel, (array) ( $result['lead'] ?? [] ) );
	}

	if ( $session_key !== '' && function_exists( 'estandar_chatbot_append_conversation_message' ) ) {
		estandar_chatbot_append_conversation_message( $session_key, $channel, 'user', (string) $message, [] );
		estandar_chatbot_append_conversation_message( $session_key, $channel, 'assistant', 'Gracias. Hemos recibido tus datos y un ejecutivo se pondrá en contacto contigo pronto.', [] );
	}

	wp_send_json_success([
		'answer'     => 'Gracias. Hemos recibido tus datos y un ejecutivo se pondrá en contacto contigo pronto.',
		'session_id' => $session_key,
		'lead_id'    => (int) ( $result['lead_id'] ?? 0 ),
	]);
}

function estandar_chatbot_get_commercial_safety_answer( $message, $context ) {
	$message = estandar_chatbot_normalize_commercial_safety_text( $message );
	if ( $message === '' || ! estandar_chatbot_is_commercial_sensitive_question( $message ) ) {
		return '';
	}

	$context = estandar_chatbot_normalize_commercial_safety_text( $context );
	if ( $context !== '' && estandar_chatbot_context_has_commercial_evidence( $message, $context ) ) {
		return '';
	}

	return "No tengo ese dato confirmado ahora.\n\nSi quieres, te conecto con una persona del equipo para revisarlo con información actualizada.";
}

function estandar_chatbot_normalize_commercial_safety_text( $value ) {
	$value = strtolower( remove_accents( (string) $value ) );
	return trim( preg_replace( '/\s+/', ' ', $value ) );
}

function estandar_chatbot_is_commercial_sensitive_question( $message ) {
	// 2.0.92: Added 'subsidio' — was in evidence groups but missing from detection regex.
	return (bool) preg_match(
		'/\b(precio|precios|costo|costos|valor|vale|cuanto cuesta|cotizar|disponibilidad|disponible|quedan|stock|entrega|entregan|plazo|plazos|financiamiento|financiar|credito|dividendo|pie|diferencia|diferencias|comparar|subsidio)\b/u',
		$message
	);
}

function estandar_chatbot_context_has_commercial_evidence( $message, $context ) {
	// 2.0.92: 'cuanto cuesta' and 'vale' added to price group so they map to evidence lookup.
	// Previously these triggered is_sensitive but had no group → fell through to return true (always PASS).
	$groups = [
		'price'        => [ 'precio', 'precios', 'costo', 'costos', 'valor', 'vale', 'cuanto cuesta', 'uf', '$', 'desde', 'cotizar' ],
		'availability' => [ 'disponibilidad', 'disponible', 'stock', 'quedan', 'unidades', 'cupos' ],
		'delivery'     => [ 'entrega', 'entregan', 'plazo', 'fecha', 'recepcion', 'inmediata' ],
		'financing'    => [ 'financiamiento', 'financiar', 'credito', 'hipotecario', 'subsidio', 'dividendo', 'pie' ],
		'differences'  => [ 'diferencia', 'diferencias', 'comparar', 'versus', 'vs', 'incluye', 'caracteristicas' ],
	];

	foreach ( $groups as $terms ) {
		$asked = false;
		foreach ( $terms as $term ) {
			if ( strpos( $message, $term ) !== false ) {
				$asked = true;
				break;
			}
		}
		if ( ! $asked ) {
			continue;
		}
		foreach ( $terms as $term ) {
			if ( strpos( $context, $term ) !== false ) {
				return true;
			}
		}
		return false;
	}

	return true;
}
