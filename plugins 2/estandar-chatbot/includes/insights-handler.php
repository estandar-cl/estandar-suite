<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function estandar_chatbot_get_insights_since_mysql( $days = 30 ) {
	$days = max( 1, (int) $days );
	return estandar_chatbot_local_mysql_time( current_time( 'timestamp' ) - ( $days * DAY_IN_SECONDS ) );
}

function estandar_chatbot_extract_log_meta( array $row ) {
	$meta = json_decode( (string) ( $row['meta'] ?? '{}' ), true );
	return is_array( $meta ) ? $meta : [];
}

function estandar_chatbot_normalize_insight_text( $text ) {
	$text = strtolower( trim( wp_strip_all_tags( (string) $text ) ) );
	$text = preg_replace( '/\s+/', ' ', $text );
	return trim( (string) $text );
}

/**
 * Returns true if the normalized question is noise/greeting that should be
 * excluded from friction analysis (top questions, fallback detection).
 */
function estandar_chatbot_is_noise_question( $normalized ) {
	static $noise_patterns = null;
	if ( $noise_patterns === null ) {
		$noise_patterns = [
			// Greetings
			'hola', 'hola!', 'hi', 'hello', 'hey', 'buenas', 'buenos dias',
			'buenos días', 'buenas tardes', 'buenas noches', 'buen dia', 'buen día',
			// Minimal input
			'?', '??', '???', '.', '..', '...', '!', 'ok', 'si', 'sí', 'no',
			// Thanks
			'gracias', 'gracias!', 'muchas gracias', 'ok gracias',
			// Test/spam
			'test', 'prueba', 'probando',
		];
		// Merge with admin-configured excluded patterns
		$custom = (string) get_option( 'chatbot_excluded_question_patterns', '' );
		if ( $custom !== '' ) {
			foreach ( preg_split( '/[\n,]+/', $custom ) as $pattern ) {
				$p = estandar_chatbot_normalize_insight_text( $pattern );
				if ( $p !== '' ) {
					$noise_patterns[] = $p;
				}
			}
		}
		$noise_patterns = array_unique( $noise_patterns );
	}
	if ( strlen( $normalized ) <= 2 ) {
		return true;
	}
	return in_array( $normalized, $noise_patterns, true );
}

function estandar_chatbot_is_fallback_answer( $answer ) {
	$answer = estandar_chatbot_normalize_insight_text( $answer );
	if ( $answer === '' ) {
		return true;
	}
	$patterns = [
		'no pude',
		'no tengo conexión',
		'intenta nuevamente',
		'intenta de nuevo',
		'error',
		'fail',
		'respuesta vacía',
	];
	foreach ( $patterns as $pattern ) {
		if ( strpos( $answer, $pattern ) !== false ) {
			return true;
		}
	}
	return false;
}

function estandar_chatbot_get_logs_friction_insights( $days = 30 ) {
	global $wpdb;

	if ( ! function_exists( 'estandar_chatbot_ensure_logs_table' ) ) {
		return [
			'top_questions' => [],
			'fallbacks' => [],
			'unresolved' => [],
			'abandonments' => [],
		];
	}

	estandar_chatbot_ensure_logs_table();
	estandar_chatbot_ensure_conversations_table();

	$since = estandar_chatbot_get_insights_since_mysql( $days );
	$logs_table = estandar_chatbot_get_logs_table();
	$conversations_table = estandar_chatbot_get_conversations_table();

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, datetime, question, answer, url, meta FROM {$logs_table} WHERE datetime >= %s ORDER BY id DESC LIMIT 1200",
			$since
		),
		ARRAY_A
	);
	$rows = is_array( $rows ) ? $rows : [];

	$questions = [];
	$fallbacks = [];
	$unresolved = [];

	foreach ( $rows as $row ) {
		$meta = estandar_chatbot_extract_log_meta( $row );
		$type = sanitize_key( (string) ( $meta['interaction_type'] ?? 'question' ) );
		$question = trim( (string) ( $row['question'] ?? '' ) );
		$answer = trim( (string) ( $row['answer'] ?? '' ) );
		$normalized_question = estandar_chatbot_normalize_insight_text( $question );

		if ( $type === 'question' && $normalized_question !== ''
			&& ! estandar_chatbot_is_noise_question( $normalized_question ) ) {
			if ( ! isset( $questions[ $normalized_question ] ) ) {
				$questions[ $normalized_question ] = [
					'question' => $question,
					'count' => 0,
				];
			}
			$questions[ $normalized_question ]['count']++;
		}

		if ( estandar_chatbot_is_fallback_answer( $answer ) ) {
			$fallbacks[] = [
				'question' => $question,
				'answer' => $answer,
				'datetime' => (string) ( $row['datetime'] ?? '' ),
			];
		}

		if ( $type === 'question' && $question !== '' && ( $answer === '' || estandar_chatbot_is_fallback_answer( $answer ) ) ) {
			$unresolved[] = [
				'question' => $question,
				'datetime' => (string) ( $row['datetime'] ?? '' ),
				'page_url' => (string) ( $meta['page_url'] ?? ( $row['url'] ?? '' ) ),
			];
		}
	}

	uasort(
		$questions,
		function( $a, $b ) {
			return (int) $b['count'] <=> (int) $a['count'];
		}
	);

	$abandon_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, session_key, channel, lead_email, lead_phone, last_message_at, messages, summary
			FROM {$conversations_table}
			WHERE last_message_at >= %s
			ORDER BY last_message_at DESC
			LIMIT 600",
			$since
		),
		ARRAY_A
	);
	$abandon_rows = is_array( $abandon_rows ) ? $abandon_rows : [];
	$abandonments = [];
	foreach ( $abandon_rows as $row ) {
		$messages = json_decode( (string) ( $row['messages'] ?? '[]' ), true );
		$messages = is_array( $messages ) ? $messages : [];
		$last = ! empty( $messages ) ? end( $messages ) : null;
		if ( ! is_array( $last ) ) {
			continue;
		}
		$role = sanitize_key( (string) ( $last['role'] ?? '' ) );
		if ( $role !== 'user' ) {
			continue;
		}
		if ( ! empty( $row['lead_email'] ) || ! empty( $row['lead_phone'] ) ) {
			continue;
		}
		$abandonments[] = [
			'session_key' => (string) ( $row['session_key'] ?? '' ),
			'channel' => (string) ( $row['channel'] ?? '' ),
			'last_message' => (string) ( $last['content'] ?? '' ),
			'last_message_at' => (string) ( $row['last_message_at'] ?? '' ),
			'summary' => (string) ( $row['summary'] ?? '' ),
		];
		if ( count( $abandonments ) >= 10 ) {
			break;
		}
	}

	return [
		'top_questions' => array_slice( array_values( $questions ), 0, 10 ),
		'fallbacks' => array_slice( $fallbacks, 0, 10 ),
		'unresolved' => array_slice( $unresolved, 0, 10 ),
		'abandonments' => $abandonments,
	];
}

function estandar_chatbot_get_conversation_learning_insights( $days = 30 ) {
	$conversations = function_exists( 'estandar_chatbot_get_conversations' )
		? estandar_chatbot_get_conversations( 600, 0 )
		: [];
	$since_ts = strtotime( estandar_chatbot_get_insights_since_mysql( $days ) );
	$high_keywords = [ 'precio', 'pagar', 'contratar', 'quiero hacerlo', 'como pago', 'cómo pago', 'urgente', 'ahora', 'hagamoslo', 'hagámoslo' ];
	$medium_keywords = [ 'informacion', 'información', 'duda', 'ayuda', 'opciones', 'detalle', 'servicio', 'producto' ];
	$high = 0;
	$medium = 0;
	$low = 0;
	$lost = [];

	foreach ( (array) $conversations as $conversation ) {
		$ts = strtotime( (string) ( $conversation['last_message_at'] ?? '' ) );
		if ( $since_ts && $ts && $ts < $since_ts ) {
			continue;
		}
		$messages = is_array( $conversation['messages'] ?? null ) ? $conversation['messages'] : [];
		$combined = strtolower( trim( (string) ( $conversation['summary'] ?? '' ) ) );
		foreach ( $messages as $message ) {
			if ( sanitize_key( (string) ( $message['role'] ?? '' ) ) === 'user' ) {
				$combined .= ' ' . estandar_chatbot_normalize_insight_text( $message['content'] ?? '' );
			}
		}

		$intent = 'low';
		foreach ( $high_keywords as $keyword ) {
			if ( strpos( $combined, estandar_chatbot_normalize_insight_text( $keyword ) ) !== false ) {
				$intent = 'high';
				break;
			}
		}
		if ( $intent !== 'high' ) {
			foreach ( $medium_keywords as $keyword ) {
				if ( strpos( $combined, estandar_chatbot_normalize_insight_text( $keyword ) ) !== false ) {
					$intent = 'medium';
					break;
				}
			}
		}

		if ( $intent === 'high' ) {
			$high++;
		} elseif ( $intent === 'medium' ) {
			$medium++;
		} else {
			$low++;
		}

		$converted = ! empty( $conversation['lead_email'] ) || ! empty( $conversation['lead_phone'] ) || in_array( (string) ( $conversation['conversation_status'] ?? '' ), [ 'qualified', 'human_handoff', 'closed' ], true );
		if ( $intent === 'high' && ! $converted ) {
			$last_user = '';
			foreach ( array_reverse( $messages ) as $message ) {
				if ( sanitize_key( (string) ( $message['role'] ?? '' ) ) === 'user' ) {
					$last_user = (string) ( $message['content'] ?? '' );
					break;
				}
			}
			$lost[] = [
				'id' => (int) ( $conversation['id'] ?? 0 ),
				'session_key' => (string) ( $conversation['session_key'] ?? '' ),
				'channel' => (string) ( $conversation['channel'] ?? '' ),
				'last_message' => $last_user,
				'last_message_at' => (string) ( $conversation['last_message_at'] ?? '' ),
			];
		}
	}

	return [
		'high_intent' => $high,
		'medium_intent' => $medium,
		'low_intent' => $low,
		'lost_high_intent' => array_slice( $lost, 0, 12 ),
	];
}

function estandar_chatbot_get_lead_conversion_insights( $days = 30 ) {
	$leads = function_exists( 'estandar_chatbot_get_leads' )
		? estandar_chatbot_get_leads( 600, 0 )
		: [];
	$since_ts = strtotime( estandar_chatbot_get_insights_since_mysql( $days ) );
	$hot_messages = [];
	$channel_counts = [];
	$page_counts = [];
	foreach ( (array) $leads as $lead ) {
		$ts = strtotime( (string) ( $lead['created_at'] ?? '' ) );
		if ( $since_ts && $ts && $ts < $since_ts ) {
			continue;
		}
		$tier = strtolower( (string) ( $lead['lead_tier'] ?? '' ) );
		if ( $tier !== 'hot' ) {
			continue;
		}
		$message = trim( (string) ( $lead['requirement'] ?? $lead['intent'] ?? '' ) );
		if ( $message !== '' ) {
			$key = estandar_chatbot_normalize_insight_text( $message );
			if ( ! isset( $hot_messages[ $key ] ) ) {
				$hot_messages[ $key ] = [ 'label' => $message, 'count' => 0 ];
			}
			$hot_messages[ $key ]['count']++;
		}
		$channel = sanitize_key( (string) ( $lead['source_channel'] ?? 'web' ) );
		if ( ! isset( $channel_counts[ $channel ] ) ) {
			$channel_counts[ $channel ] = 0;
		}
		$channel_counts[ $channel ]++;
		$page = (string) ( $lead['source_page_url'] ?? '' );
		if ( $page !== '' ) {
			if ( ! isset( $page_counts[ $page ] ) ) {
				$page_counts[ $page ] = 0;
			}
			$page_counts[ $page ]++;
		}
	}
	uasort( $hot_messages, function( $a, $b ) { return (int) $b['count'] <=> (int) $a['count']; } );
	arsort( $channel_counts );
	arsort( $page_counts );
	return [
		'top_messages' => array_slice( array_values( $hot_messages ), 0, 8 ),
		'top_channels' => array_slice( $channel_counts, 0, 5, true ),
		'top_pages' => array_slice( $page_counts, 0, 5, true ),
	];
}

function estandar_chatbot_get_learning_suggestions( $days = 30 ) {
	$friction = estandar_chatbot_get_logs_friction_insights( $days );
	$conversations = estandar_chatbot_get_conversation_learning_insights( $days );
	$leads = estandar_chatbot_get_lead_conversion_insights( $days );
	$suggestions = [];

	$top_question = $friction['top_questions'][0] ?? null;
	if ( $top_question && (int) ( $top_question['count'] ?? 0 ) >= 3 ) {
		$suggestions[] = [
			'id' => 'price_cta',
			'title' => 'Responder preguntas repetidas con CTA más directo',
			'problem' => 'Hay preguntas repetidas que obligan al usuario a insistir antes de actuar.',
			'evidence' => 'Pregunta detectada: "' . (string) $top_question['question'] . '" (' . (int) $top_question['count'] . ' veces).',
			'suggestion' => 'Responder precio o siguiente paso de forma corta y con CTA visible.',
			'prompt_append' => "Cuando el usuario haga una pregunta repetida sobre precio, pago o siguiente paso, responde en máximo 3 o 4 líneas. Usa esta estructura: 1) respuesta directa, 2) solución o rango de precio si existe, 3) CTA visible a cotizar, agendar o hablar con una persona, 4) cierre opcional muy corto. Evita múltiples párrafos, bloques secundarios, alternativas complejas y explicaciones largas antes de la acción.",
		];
	}

	if ( count( $friction['fallbacks'] ) >= 3 ) {
		$suggestions[] = [
			'id' => 'fallback_handoff',
			'title' => 'Reducir fricción después de respuestas fallback',
			'problem' => 'Hay varias respuestas tipo fallback o sin salida clara.',
			'evidence' => 'Fallbacks detectados: ' . count( $friction['fallbacks'] ) . '.',
			'suggestion' => 'Después del primer fallback, ofrecer cierre corto o derivación humana sin repetir explicación.',
			'prompt_append' => "Si no puedes responder con claridad en el primer intento, evita repetir explicación genérica. Ofrece una salida concreta en máximo 3 líneas: CTA a cotizar, agendar o derivación humana inmediata. Prioriza claridad y acción sobre completitud.",
		];
	}

	if ( count( $conversations['lost_high_intent'] ) >= 2 ) {
		$suggestions[] = [
			'id' => 'urgent_close',
			'title' => 'Cerrar más rápido en sesiones con intención alta',
			'problem' => 'Hay conversaciones con intención alta que no terminan en lead o contacto.',
			'evidence' => 'Leads perdidos de alta intención: ' . count( $conversations['lost_high_intent'] ) . '.',
			'suggestion' => 'Priorizar cierre directo, pago o contacto humano cuando detectes urgencia.',
			'prompt_append' => "Si detectas urgencia o intención alta de compra, prioriza cierre directo y responde en máximo 3 o 4 líneas. Orden obligatorio: 1) acción inmediata, 2) solución o precio, 3) link de pago, 4) alternativa humana opcional. No abras múltiples caminos ni des explicaciones largas.",
		];
	}

	$top_message = $leads['top_messages'][0] ?? null;
	if ( $top_message && (int) ( $top_message['count'] ?? 0 ) >= 2 ) {
		$suggestions[] = [
			'id' => 'hot_pattern',
			'title' => 'Reforzar los mensajes que ya generan leads HOT',
			'problem' => 'Hay patrones que convierten bien pero no están explicitados como regla del bot.',
			'evidence' => 'Mensaje que más convierte a HOT: "' . (string) $top_message['label'] . '" (' . (int) $top_message['count'] . ').',
			'suggestion' => 'Usar ese patrón como atajo hacia CTA o derivación.',
			'prompt_append' => "Cuando el usuario exprese necesidad similar a '" . sanitize_text_field( (string) $top_message['label'] ) . "', llévalo rápido a una acción concreta en vez de seguir explicando. Responde corto, directo y con CTA visible; evita más de 4 líneas.",
		];
	}

	return $suggestions;
}

function estandar_chatbot_get_applied_suggestions() {
	$applied = get_option( 'estandar_chatbot_applied_suggestions', [] );
	return is_array( $applied ) ? $applied : [];
}

function estandar_chatbot_get_prompt_history() {
	$history = get_option( 'estandar_chatbot_prompt_history', [] );
	return is_array( $history ) ? $history : [];
}

function estandar_chatbot_record_prompt_history( $source, $before, $after, array $meta = [] ) {
	$before = estandar_chatbot_sanitize_system_prompt_option( $before );
	$after  = estandar_chatbot_sanitize_system_prompt_option( $after );
	if ( $before === $after ) {
		return;
	}

	$history = estandar_chatbot_get_prompt_history();
	array_unshift(
		$history,
		[
			'at'     => current_time( 'mysql' ),
			'source' => sanitize_key( (string) $source ),
			'before' => $before,
			'after'  => $after,
			'meta'   => $meta,
		]
	);
	update_option( 'estandar_chatbot_prompt_history', array_slice( $history, 0, 20 ), false );
}

function estandar_chatbot_rollback_last_prompt_change() {
	$history = estandar_chatbot_get_prompt_history();
	if ( empty( $history ) ) {
		return [ 'ok' => false, 'message' => 'No hay cambios automáticos para revertir.' ];
	}

	$last = array_shift( $history );
	$before = estandar_chatbot_sanitize_system_prompt_option( (string) ( $last['before'] ?? '' ) );
	update_option( 'chatbot_system_prompt', $before );
	update_option( 'estandar_chatbot_prompt_history', array_values( $history ), false );

	return [ 'ok' => true, 'message' => 'Se revirtió el último cambio automático del prompt.' ];
}

function estandar_chatbot_normalize_prompt_heading( $value ) {
	$value = remove_accents( strtolower( (string) $value ) );
	$value = preg_replace( '/\s+/', ' ', $value );
	return trim( (string) $value );
}

function estandar_chatbot_find_prompt_heading_index( array $lines, $heading ) {
	$target = estandar_chatbot_normalize_prompt_heading( $heading );
	foreach ( $lines as $index => $line ) {
		if ( estandar_chatbot_normalize_prompt_heading( trim( (string) $line ) ) === $target ) {
			return (int) $index;
		}
	}
	return -1;
}

function estandar_chatbot_is_prompt_heading_line( $line ) {
	$line = trim( (string) $line );
	if ( $line === '' ) {
		return false;
	}
	$normalized = remove_accents( $line );
	return strtoupper( $normalized ) === $normalized && strpos( $line, ':' ) === false;
}

function estandar_chatbot_upsert_auto_rule_in_prompt( $prompt, $suggestion_id, $rule_text ) {
	$prompt = estandar_chatbot_sanitize_system_prompt_option( $prompt );
	$rule_text = trim( (string) $rule_text );
	$suggestion_id = sanitize_key( (string) $suggestion_id );

	if ( $rule_text === '' || $suggestion_id === '' ) {
		return $prompt;
	}

	$heading = 'REGLA CRÍTICA DE RESPUESTA (PRIORIDAD MÁXIMA)';
	$marker = '[AUTO:' . $suggestion_id . ']';
	$rule_line = '- ' . $marker . ' ' . $rule_text;
	$lines = preg_split( "/\n/", $prompt );
	$lines = is_array( $lines ) ? $lines : [];

	$section_index = estandar_chatbot_find_prompt_heading_index( $lines, $heading );
	if ( $section_index < 0 ) {
		$priority_index = estandar_chatbot_find_prompt_heading_index( $lines, 'PRIORIDAD' );
		$insert_index = count( $lines );
		if ( $priority_index >= 0 ) {
			$insert_index = $priority_index + 1;
			while ( isset( $lines[ $insert_index ] ) && ! estandar_chatbot_is_prompt_heading_line( $lines[ $insert_index ] ) ) {
				$insert_index++;
			}
		}
		array_splice( $lines, $insert_index, 0, [ '', $heading, '' ] );
		$section_index = estandar_chatbot_find_prompt_heading_index( $lines, $heading );
	}

	$next_heading_index = count( $lines );
	for ( $i = $section_index + 1; $i < count( $lines ); $i++ ) {
		if ( estandar_chatbot_is_prompt_heading_line( $lines[ $i ] ) ) {
			$next_heading_index = $i;
			break;
		}
	}

	$existing_index = -1;
	for ( $i = $section_index + 1; $i < $next_heading_index; $i++ ) {
		if ( strpos( (string) $lines[ $i ], $marker ) !== false ) {
			$existing_index = $i;
			break;
		}
	}

	if ( $existing_index >= 0 ) {
		$lines[ $existing_index ] = $rule_line;
	} else {
		$insert_rule_at = $next_heading_index;
		while ( $insert_rule_at > $section_index + 1 && trim( (string) $lines[ $insert_rule_at - 1 ] ) === '' ) {
			$insert_rule_at--;
		}
		array_splice( $lines, $insert_rule_at, 0, [ $rule_line ] );
	}

	return estandar_chatbot_sanitize_system_prompt_option( implode( "\n", $lines ) );
}

function estandar_chatbot_mark_suggestion_applied( $suggestion_id ) {
	$applied = estandar_chatbot_get_applied_suggestions();
	$suggestion_id = sanitize_key( (string) $suggestion_id );
	if ( $suggestion_id === '' ) {
		return;
	}
	if ( ! in_array( $suggestion_id, $applied, true ) ) {
		$applied[] = $suggestion_id;
		update_option( 'estandar_chatbot_applied_suggestions', array_values( $applied ), false );
	}
}

function estandar_chatbot_apply_learning_suggestion( $suggestion_id, $days = 30 ) {
	$suggestions = estandar_chatbot_get_learning_suggestions( $days );
	foreach ( $suggestions as $suggestion ) {
		if ( sanitize_key( (string) ( $suggestion['id'] ?? '' ) ) !== sanitize_key( (string) $suggestion_id ) ) {
			continue;
		}
		$append = trim( (string) ( $suggestion['prompt_append'] ?? '' ) );
		if ( $append === '' ) {
			return [ 'ok' => false, 'message' => 'La sugerencia no tiene un cambio automático disponible.' ];
		}
		$current = trim( (string) get_option( 'chatbot_system_prompt', '' ) );
		if ( $current === '' && function_exists( 'estandar_chatbot_default_system_prompt_lines' ) ) {
			$current = estandar_chatbot_default_system_prompt_lines( (string) get_option( 'chatbot_idioma', 'es' ) );
		}
		$next = estandar_chatbot_upsert_auto_rule_in_prompt( $current, $suggestion_id, $append );
		update_option( 'chatbot_system_prompt', $next );
		estandar_chatbot_record_prompt_history(
			'auto_suggestion',
			$current,
			$next,
			[
				'suggestion_id' => sanitize_key( (string) $suggestion_id ),
				'title' => (string) ( $suggestion['title'] ?? '' ),
			]
		);
		estandar_chatbot_mark_suggestion_applied( $suggestion_id );
		return [ 'ok' => true, 'message' => 'Mejora aplicada al prompt del chatbot.' ];
	}
	return [ 'ok' => false, 'message' => 'Sugerencia no encontrada.' ];
}

add_action( 'admin_post_estandar_chatbot_apply_suggestion', 'estandar_chatbot_apply_suggestion_admin_action' );
function estandar_chatbot_apply_suggestion_admin_action() {
	if ( ! estandar_chatbot_user_can_view_tab( 'ia-contexto' ) ) {
		wp_die( 'No autorizado.' );
	}
	check_admin_referer( 'estandar_chatbot_apply_suggestion' );
	$suggestion_id = isset( $_POST['suggestion_id'] ) ? sanitize_key( wp_unslash( $_POST['suggestion_id'] ) ) : '';
	$result = estandar_chatbot_apply_learning_suggestion( $suggestion_id, 30 );
	$status = ! empty( $result['ok'] ) ? 'ok' : 'error';
	$msg = ! empty( $result['message'] ) ? (string) $result['message'] : 'No se pudo aplicar la sugerencia.';
	wp_safe_redirect( admin_url( 'admin.php?page=estandar-chatbot&tab=sugerencias&ecb_status=' . rawurlencode( $status ) . '&ecb_msg=' . rawurlencode( $msg ) ) );
	exit;
}

add_action( 'admin_post_estandar_chatbot_rollback_prompt_change', 'estandar_chatbot_rollback_prompt_change_admin_action' );
function estandar_chatbot_rollback_prompt_change_admin_action() {
	if ( ! estandar_chatbot_user_can_view_tab( 'ia-contexto' ) ) {
		wp_die( 'No autorizado.' );
	}
	check_admin_referer( 'estandar_chatbot_rollback_prompt_change' );
	$result = estandar_chatbot_rollback_last_prompt_change();
	$status = ! empty( $result['ok'] ) ? 'ok' : 'error';
	$msg = ! empty( $result['message'] ) ? (string) $result['message'] : 'No se pudo revertir el cambio.';
	wp_safe_redirect( admin_url( 'admin.php?page=estandar-chatbot&tab=ia-contexto&ecb_status=' . rawurlencode( $status ) . '&ecb_msg=' . rawurlencode( $msg ) ) );
	exit;
}
