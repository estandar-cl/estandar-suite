<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ======================================================
 * CONTEXT LOADER — ESTANDAR CHATBOT IA
 * Versión: MEJORADA / LOCAL FIRST
 * ======================================================
 */

/**
 * Retorna el contexto completo para el chatbot.
 * Orden de prioridad:
 * 1) TXT local
 * 2) Pinecone (si está activado)
 */
function estandar_chatbot_get_context() {

	// Flag de uso Pinecone (0 / 1)
	$use_pinecone = (int) get_option( 'chatbot_use_pinecone', 0 );

	// 1) TXT LOCAL
	$local_context = estandar_chatbot_get_local_context();
	if ( $local_context !== '' ) {
		return $local_context;
	}

	// 2) CLIENT CONTEXT RECORD (Core, manual)
	if ( function_exists( 'estandar_core_format_client_context_record' ) ) {
		$core_context = estandar_core_format_client_context_record();
		if ( is_string( $core_context ) && trim( $core_context ) !== '' ) {
			return trim( $core_context );
		}
	}

	// 3) PINECONE (opcional)
	if ( $use_pinecone && function_exists( 'estandar_chatbot_get_pinecone_context' ) ) {
		$pinecone_context = estandar_chatbot_get_pinecone_context();
		if ( is_string( $pinecone_context ) && trim( $pinecone_context ) !== '' ) {
			return trim( $pinecone_context );
		}
	}

	// Fallback explícito
	return '';
}

/**
 * Lee el archivo TXT local cargado desde el admin.
 */
function estandar_chatbot_get_local_context() {

	$path = get_option( 'chatbot_context_txt_path', '' );
	if ( empty( $path ) ) return '';

	$path = wp_normalize_path( $path );

	// Seguridad: solo permitir dentro de uploads o storage
	$uploads = wp_get_upload_dir();
	$allowed = [
		wp_normalize_path( $uploads['basedir'] ),
		wp_normalize_path( ESTANDAR_CHATBOT_STORAGE ),
	];

	$is_allowed = false;
	foreach ( $allowed as $base ) {
		if ( strpos( $path, $base ) === 0 ) {
			$is_allowed = true;
			break;
		}
	}

	if ( ! $is_allowed || ! file_exists( $path ) ) {
		return '';
	}

	$content = file_get_contents( $path );
	if ( $content === false ) return '';

	$content = estandar_chatbot_sanitize_multiline_text( $content );

	// Limitar tamaño (anti prompts gigantes)
	$max_chars = 12000;
	if ( mb_strlen( $content ) > $max_chars ) {
		$content = mb_substr( $content, 0, $max_chars );
	}

	return trim( $content );
}

/**
 * Placeholder Pinecone (no obligatorio).
 * Implementación real se agrega solo si el cliente lo requiere.
 */
function estandar_chatbot_get_pinecone_context() {
	/**
	 * Aquí iría:
	 * - embedding del mensaje
	 * - query Pinecone
	 * - concatenación de chunks
	 */
	return '';
}
