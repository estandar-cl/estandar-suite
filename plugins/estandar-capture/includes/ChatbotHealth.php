<?php

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ChatbotHealth — estado de salud del chatbot v2 (semáforo del Resumen).
 *
 * Combina: configuración activa (activar), clave IA configurada (AiKeys),
 * motor disponible (AiChat) y persistencia lista (tabla de conversaciones).
 * También expone `appearance()` con los valores de apariencia para la vista
 * previa del sub-tab Apariencia.
 */
class ChatbotHealth
{
	/** Snapshot de checks {clave => label, status, desc, level ok|warn|bad}. */
	public static function snapshot(): array
	{
		$s = AiChatPrompt::settings();
		$checks = array();

		// 1. Configuración activa.
		$enabled = isset( $s['activar'] ) ? (int) $s['activar'] : 1;
		$checks['config'] = array(
			'label'  => 'Configuración',
			'status' => $enabled ? 'Activado' : 'Desactivado',
			'desc'   => $enabled ? 'El chatbot está habilitado en el sitio.' : 'Actívalo en Conversación para mostrarlo.',
			'level'  => $enabled ? 'ok' : 'warn',
		);

		// 2. Clave IA.
		$provider = AiChat::provider();
		$key      = AiChat::api_key( $provider );
		$checks['ai'] = array(
			'label'  => 'Motor IA',
			'status' => $key !== '' ? 'Conectado (' . $provider . ')' : 'Sin clave',
			'desc'   => $key !== '' ? 'El motor responderá con ' . $provider . '.' : 'Agrega la clave en Core → Configuración → Claves API.',
			'level'  => $key !== '' ? 'ok' : 'bad',
		);

		// 3. Persistencia (tabla de conversaciones).
		$table_ok = self::table_exists( ChatConversation::TABLE );
		$checks['persist'] = array(
			'label'  => 'Persistencia',
			'status' => $table_ok ? 'Tabla lista' : 'Tabla pendiente',
			'desc'   => $table_ok ? 'Las conversaciones se guardan.' : 'Activa el plugin para crear la tabla.',
			'level'  => $table_ok ? 'ok' : 'warn',
		);

		// 4. Nombre del asistente.
		$name = trim( (string) ( $s['nombre'] ?? '' ) );
		$checks['identity'] = array(
			'label'  => 'Identidad',
			'status' => $name !== '' ? $name : 'Sin nombre',
			'desc'   => $name !== '' ? 'Asistente con nombre configurado.' : 'Define el nombre en Conversación.',
			'level'  => $name !== '' ? 'ok' : 'warn',
		);

		return array( 'checks' => $checks );
	}

	/** Valores de apariencia para la vista previa. */
	public static function appearance(): array
	{
		$s      = AiChatPrompt::settings();
		$legacy = get_option( 'chatbot_branding_config', array() );
		$legacy = is_array( $legacy ) ? $legacy : array();
		$val    = function ( $v2, $lk, $def ) use ( $s, $legacy ) {
			if ( isset( $s[ $v2 ] ) && $s[ $v2 ] !== '' ) {
				return (string) $s[ $v2 ];
			}
			if ( isset( $legacy[ $lk ] ) && $legacy[ $lk ] !== '' ) {
				return (string) $legacy[ $lk ];
			}
			return $def;
		};
		return array(
			'accent'      => (string) ( $s['theme_accent'] ?? get_option( 'chatbot_theme_accent', '#111827' ) ),
			'bot_name'    => (string) ( $s['nombre'] ?? get_option( 'chatbot_nombre', 'Asistente' ) ),
			'bot_bubble'  => $val( 'branding_bot_bubble', 'bot_bubble_color', '#f3f4f6' ),
			'user_bubble' => $val( 'branding_user_bubble', 'user_bubble_color', (string) ( $s['theme_accent'] ?? '#e8f0fe' ) ),
			'button'      => $val( 'branding_button_color', 'button_color', '#2563eb' ),
			'bg'          => $val( 'branding_bg_color', 'bg_color', '#ffffff' ),
			'text'        => $val( 'branding_text_color', 'text_color', '#111827' ),
			'avatar'      => $val( 'branding_avatar', 'bot_avatar', '' ),
			'position'    => (string) ( $s['theme_position'] ?? get_option( 'chatbot_theme_position', 'right' ) ),
			'size'        => max( 40, min( 88, (int) ( $s['bubble_size'] ?? get_option( 'chatbot_bubble_size', 58 ) ) ) ),
			// CHATBOT_PREVIEW_V1: imagen real de la burbuja (setting o GIF por defecto),
			// para que la vista previa refleje el frontend.
			'bubble_image' => self::resolve_bubble_image( $s ),
		);
	}

	/** Imagen de la burbuja: setting v2 → legacy → GIF por defecto del plugin. */
	private static function resolve_bubble_image( array $s ): string
	{
		$url = trim( (string) ( $s['bubble_image_url'] ?? get_option( 'chatbot_bubble_image_url', '' ) ) );
		if ( $url !== '' ) {
			return $url;
		}
		if ( defined( 'ESTANDAR_SUITE_CAPTURE_FILE' ) ) {
			return plugin_dir_url( ESTANDAR_SUITE_CAPTURE_FILE ) . 'assets/animations/bubble-default.gif';
		}
		return '';
	}

	/** ¿Existe la tabla? */
	private static function table_exists( string $table ): bool
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return false;
		}
		$wpdb->get_results( 'SELECT 1 FROM ' . $wpdb->prefix . $table . ' LIMIT 1' );
		return empty( $wpdb->last_error );
	}
}
