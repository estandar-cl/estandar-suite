<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Registro de canales de conversación / captación.
 *
 * WhatsApp está implementado (Cloud API + webhook). Web y API están activos.
 * Telegram, Instagram DM, etc. pueden añadirse:
 * - Ampliando la lista por filtro `estandar_chatbot_allowed_channels`.
 * - Registrando webhook REST propio y llamando a las mismas funciones que el web:
 *   estandar_chatbot_append_conversation_message(), estandar_chatbot_handle_ai_request()
 *   (vía refactor interno) o duplicando el flujo OpenAI como hace whatsapp-handler.php.
 *
 * @package EstandarChatbot
 */

/**
 * Canales reconocidos por defecto (slug => etiqueta admin).
 *
 * @return array<string, string>
 */
function estandar_chatbot_default_channel_labels() {
	return [
		'web'       => 'Web',
		'whatsapp'  => 'WhatsApp',
		'telegram'  => 'Telegram',
		'api'       => 'API',
	];
}

/**
 * Lista de slugs de canal permitidos en sesiones, conversaciones y analítica.
 *
 * @return string[]
 */
function estandar_chatbot_get_allowed_channels() {
	$default = array_keys( estandar_chatbot_default_channel_labels() );
	/**
	 * Ampliar canales (p. ej. telegram, instagram_dm, messenger).
	 * Cada integración debe normalizar su propio session_key y persistir con el slug acordado.
	 *
	 * @param string[] $channels Slugs sanitizables con sanitize_key().
	 */
	$channels = apply_filters( 'estandar_chatbot_allowed_channels', $default );
	if ( ! is_array( $channels ) || empty( $channels ) ) {
		return $default;
	}
	$out = [];
	foreach ( $channels as $ch ) {
		$k = sanitize_key( (string) $ch );
		if ( $k !== '' && ! in_array( $k, $out, true ) ) {
			$out[] = $k;
		}
	}
	return ! empty( $out ) ? $out : $default;
}

/**
 * Etiqueta legible para UI admin.
 *
 * @param string $channel Slug.
 * @return string
 */
function estandar_chatbot_get_channel_label( $channel ) {
	$channel = sanitize_key( (string) $channel );
	$labels  = estandar_chatbot_default_channel_labels();
	/**
	 * @param array<string, string> $labels
	 */
	$labels = apply_filters( 'estandar_chatbot_channel_labels', $labels );
	if ( isset( $labels[ $channel ] ) && is_string( $labels[ $channel ] ) ) {
		return (string) $labels[ $channel ];
	}
	return $channel !== '' ? $channel : '—';
}
