<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Endpoint ligero para registrar eventos de cliente (botones, etc.) sin bloquear UI.
 */

add_action( 'wp_ajax_estandar_chatbot_log_client_event', 'estandar_chatbot_handle_log_client_event' );
add_action( 'wp_ajax_nopriv_estandar_chatbot_log_client_event', 'estandar_chatbot_handle_log_client_event' );

function estandar_chatbot_handle_log_client_event() {
	estandar_chatbot_verify_nonce_or_die();

	$type = isset( $_POST['interaction_type'] ) ? sanitize_key( wp_unslash( $_POST['interaction_type'] ) ) : 'button_click';
	$allowed = [ 'button_click', 'system_response', 'question' ];
	if ( ! in_array( $type, $allowed, true ) ) {
		$type = 'button_click';
	}

	$label   = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
	$payload = isset( $_POST['payload'] ) ? sanitize_text_field( wp_unslash( $_POST['payload'] ) ) : '';
	$session = isset( $_POST['session_id'] ) && function_exists( 'estandar_chatbot_normalize_session_key' )
		? estandar_chatbot_normalize_session_key( wp_unslash( $_POST['session_id'] ) )
		: '';
	$channel = isset( $_POST['channel'] ) && function_exists( 'estandar_chatbot_normalize_channel' )
		? estandar_chatbot_normalize_channel( wp_unslash( $_POST['channel'] ) )
		: 'web';
	// ea_sid: ID de sesión de Estandar Analytics para correlacionar eventos.
	$ea_sid = function_exists( 'estandar_core_get_ea_sid' ) ? estandar_core_get_ea_sid( $_POST ) : '';
	if ( '' === $ea_sid ) {
		$ea_sid = isset( $_POST['ea_sid'] ) ? sanitize_text_field( wp_unslash( $_POST['ea_sid'] ) ) : '';
		if ( ! preg_match( '/^[a-zA-Z0-9\-]{8,64}$/', $ea_sid ) ) {
			$ea_sid = '';
		}
	}
	// chatbot_open: primer evento del hilo (label = 'chatbot_open' enviado por el JS).
	$is_open_event = ( $type === 'button_click' && $label === 'chatbot_open' );

	$page_url = '';
	if ( function_exists( 'estandar_chatbot_sanitize_source_page_url' ) ) {
		$page_url = estandar_chatbot_sanitize_source_page_url( isset( $_POST['page_url'] ) ? wp_unslash( $_POST['page_url'] ) : '' );
	}

	$lead_id = isset( $_POST['lead_identifier'] ) ? sanitize_text_field( wp_unslash( $_POST['lead_identifier'] ) ) : '';

	$meta_req = function_exists( 'estandar_chatbot_get_request_meta' ) ? estandar_chatbot_get_request_meta() : [ 'ip' => '', 'ua' => '', 'url' => '' ];

	$rl_key  = 'ecb_rl_logevt_' . md5( ( $meta_req['ip'] ?: 'unknown' ) . '|' . ( $session !== '' ? $session : 'anon' ) );
	$rl_hits = (int) get_transient( $rl_key );
	if ( $rl_hits >= 45 ) {
		wp_send_json_error( [ 'message' => 'rate_limited' ], 429 );
	}
	set_transient( $rl_key, $rl_hits + 1, 60 );

	$question = $type === 'button_click'
		? '[button] ' . ( $label !== '' ? $label : 'click' )
		: sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
	$answer = $payload !== '' ? $payload : ( $type === 'button_click' ? '[button_click]' : '' );

	if ( $question === '' && $answer === '' ) {
		wp_send_json_error( [ 'message' => 'empty' ], 400 );
	}

	if ( function_exists( 'estandar_chatbot_log_interaction' ) ) {
		estandar_chatbot_log_interaction(
			[
				'question'         => $question,
				'answer'           => $answer,
				'interaction_type' => $type,
				'session_key'      => $session,
				'lead_identifier'  => $lead_id,
				'page_url'         => $page_url,
				'server_request_url' => $meta_req['url'] ?? '',
				'ip'               => $meta_req['ip'] ?? '',
				'ua'               => $meta_req['ua'] ?? '',
				'channel'          => $channel,
			]
		);
	}

	// ------------------------------------------------------------------
	// Analytics: chatbot_open / chatbot_message
	// Solo emite si ea_sid está presente. Falla silenciosamente.
	// ------------------------------------------------------------------
	if ( $ea_sid !== '' && class_exists( 'EA_Events' ) ) {
		if ( $is_open_event ) {
			EA_Events::record_single( $ea_sid, [
				'type'     => 'chatbot_open',
				'label'    => sanitize_key( $channel ),
				'page_url' => $page_url,
				'value'    => '',
			] );
		} elseif ( $type === 'question' ) {
			EA_Events::record_single( $ea_sid, [
				'type'     => 'chatbot_message',
				'label'    => sanitize_key( $channel ),
				'page_url' => $page_url,
				'value'    => '',
			] );
		}
	}

	wp_send_json_success( [ 'ok' => true ] );
}
