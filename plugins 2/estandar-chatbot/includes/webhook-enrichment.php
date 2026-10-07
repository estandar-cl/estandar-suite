<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Enriquecimiento de payloads webhook (handoff / CRM) con contexto de conversación.
 *
 * @param array  $payload     Payload base (lead.captured, etc.).
 * @param string $session_key Sesión normalizada.
 * @param string $channel     Canal.
 * @param array  $lead        Fila lead fusionada.
 * @return array
 */
function estandar_chatbot_enrich_lead_webhook_payload( array $payload, $session_key, $channel, array $lead ) {
	$session_key = function_exists( 'estandar_chatbot_normalize_session_key' )
		? estandar_chatbot_normalize_session_key( (string) $session_key )
		: '';
	$channel       = function_exists( 'estandar_chatbot_normalize_channel' )
		? estandar_chatbot_normalize_channel( (string) $channel )
		: 'web';
	$page_url      = (string) ( $lead['source_page_url'] ?? $payload['source_page_url'] ?? '' );

	$payload['source_page_url'] = $page_url;
	$payload['page_url']        = $page_url;

	if ( $session_key !== '' && function_exists( 'estandar_chatbot_get_conversation_messages' ) ) {
		$messages                               = estandar_chatbot_get_conversation_messages( $session_key, $channel, 40 );
		$payload['conversation']                = [
			'session_id' => $session_key,
			'channel'    => $channel,
			'messages'   => is_array( $messages ) ? $messages : [],
		];
		$payload['conversation_message_count'] = is_array( $messages ) ? count( $messages ) : 0;
	}

	$payload['handoff_context'] = [
		'page_url'   => $page_url,
		'lead_id'    => (int) ( $payload['lead']['_db_id'] ?? $payload['lead_id'] ?? 0 ),
		'escalation' => ! empty( $payload['escalation_requested'] ) || ! empty( $payload['force_human'] ),
	];

	/**
	 * Payload final hacia n8n/Zapier (handoff enriquecido).
	 *
	 * @param array $payload
	 */
	$payload = apply_filters( 'estandar_chatbot_handoff_webhook_payload', $payload, $session_key, $channel );

	$is_handoff = ! empty( $payload['escalation_requested'] ) || ! empty( $payload['force_human'] );
	if ( $is_handoff ) {
		/**
		 * Solo en derivación a humano: integraciones externas (CRM, helpdesk).
		 *
		 * @param array  $payload
		 * @param string $session_key
		 * @param string $channel
		 */
		do_action( 'estandar_chatbot_handoff_webhook', $payload, $session_key, $channel );
	}

	return $payload;
}
