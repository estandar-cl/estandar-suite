<?php

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AiChat — motor de IA de la Suite v2 (Capture).
 *
 * Provider-agnostic chat completions para los 5 productos v2. NO depende de
 * estandar-primer ni del gateway legacy: resuelve la clave desde el vault
 * compartido `Estandar\Core\AiKeys` (Core v2) y llama directamente al endpoint
 * del proveedor activo (formato OpenAI-compatible).
 *
 * Proveedores soportados:
 *   - deepseek → https://api.deepseek.com/v1/chat/completions
 *   - openai   → https://api.openai.com/v1/chat/completions
 *
 * Este es el motor que consumirán el chatbot (frontend portado), Brain (agente
 * público con NLU generativa) y cualquier flujo futuro que necesite LLM. Documentado
 * en la auditoría de sesión 007 como el punto de integración único de `AiKeys::get()`.
 */
class AiChat
{
	const RATE_KEY = 'ecb_ai_chat_rl_';

	/** @return array<string,string> provider → chat completions endpoint. */
	public static function endpoints(): array
	{
		return array(
			'deepseek' => 'https://api.deepseek.com/v1/chat/completions',
			'openai'   => 'https://api.openai.com/v1/chat/completions',
		);
	}

	/** Provider activo (delegado al vault). */
	public static function provider(): string
	{
		return class_exists( 'Estandar\Core\AiKeys' ) ? \Estandar\Core\AiKeys::active_provider() : 'deepseek';
	}

	/** Modelo por defecto del proveedor activo. */
	public static function default_model(): string
	{
		return class_exists( 'Estandar\Core\AiKeys' ) ? \Estandar\Core\AiKeys::default_model() : 'deepseek-chat';
	}

	/** Clave del proveedor activo (vault v2 con fallback legacy pre-adopción). */
	public static function api_key( string $provider = '' ): string
	{
		$provider = $provider !== '' ? $provider : self::provider();
		if ( ! class_exists( 'Estandar\Core\AiKeys' ) ) {
			return '';
		}
		return \Estandar\Core\AiKeys::get( $provider );
	}

	/**
	 * Chat completion (no streaming). Devuelve el mismo contrato del adapter:
	 * content / finish_reason / usage / model (+ error en message).
	 *
	 * @param array  $messages Lista de mensajes {role, content}.
	 * @param array  $opts     model, temperature, max_tokens, response_format.
	 * @param string $provider Override de proveedor (default: activo).
	 * @return array{content:string,finish_reason:string,usage:?array,model:string,error?:string}
	 */
	public static function chat( array $messages, array $opts = array(), string $provider = '' ): array
	{
		$provider = $provider !== '' ? sanitize_key( $provider ) : self::provider();
		$endpoints = self::endpoints();
		if ( ! isset( $endpoints[ $provider ] ) ) {
			return self::fail( 'Proveedor IA no soportado.', 'unsupported_provider' );
		}
		$api_key = self::api_key( $provider );
		if ( $api_key === '' ) {
			return self::fail( 'IA no configurada. Agrega la clave en Core → Configuración → Claves API.', 'missing_api_key' );
		}

		$model = (string) ( $opts['model'] ?? self::default_model() );
		if ( $model === '' ) {
			$model = self::default_model();
		}
		$body = array(
			'model'    => $model,
			'messages' => self::sanitize_messages( $messages ),
		);
		if ( isset( $opts['temperature'] ) ) {
			$body['temperature'] = max( 0.0, min( 2.0, (float) $opts['temperature'] ) );
		}
		if ( isset( $opts['max_tokens'] ) ) {
			$body['max_tokens'] = max( 1, min( 8000, (int) $opts['max_tokens'] ) );
		}
		if ( ! empty( $opts['response_format'] ) && is_array( $opts['response_format'] ) ) {
			$body['response_format'] = $opts['response_format'];
		}

		$response = wp_remote_post( $endpoints[ $provider ], array(
			'timeout' => 60,
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
		) );

		return self::parse( $response, $model );
	}

	/**
	 * Streaming chat: invoca $callback(string $delta, string $finish_reason)
	 * por cada fragmento. Devuelve el contenido acumulado.
	 *
	 * STREAM_REAL_V1 (2026-09-27): usa cURL con callback de escritura para emitir
	 * cada fragmento EN CUANTO llega del proveedor. Si cURL no está disponible,
	 * cae al modo bufferizado (mismo resultado, sin progresividad).
	 */
	public static function chat_stream( array $messages, callable $callback, array $opts = array(), string $provider = '' ): array
	{
		$provider = $provider !== '' ? sanitize_key( $provider ) : self::provider();
		$endpoints = self::endpoints();
		if ( ! isset( $endpoints[ $provider ] ) ) {
			return array( 'content' => '', 'model' => '', 'error' => 'Proveedor IA no soportado.' );
		}
		$api_key = self::api_key( $provider );
		if ( $api_key === '' ) {
			return array( 'content' => '', 'model' => '', 'error' => 'IA no configurada.' );
		}
		$model = (string) ( $opts['model'] ?? self::default_model() );
		if ( $model === '' ) {
			$model = self::default_model();
		}
		$body = array(
			'model'    => $model,
			'messages' => self::sanitize_messages( $messages ),
			'stream'   => true,
		);
		if ( isset( $opts['temperature'] ) ) {
			$body['temperature'] = max( 0.0, min( 2.0, (float) $opts['temperature'] ) );
		}
		if ( isset( $opts['max_tokens'] ) ) {
			$body['max_tokens'] = max( 1, min( 8000, (int) $opts['max_tokens'] ) );
		}
		$endpoint = $endpoints[ $provider ];
		$payload  = wp_json_encode( $body );

		// STREAM_REAL_V1: parsing reutilizable de líneas SSE `data: {...}`.
		$hunk = static function ( string $line, string &$content, callable $callback ): void {
			$line = trim( $line );
			if ( $line === '' || strpos( $line, 'data: ' ) !== 0 ) {
				return;
			}
			$data = substr( $line, 6 );
			if ( $data === '[DONE]' ) {
				return;
			}
			$json = json_decode( $data, true );
			if ( ! is_array( $json ) ) {
				return;
			}
			$delta  = (string) ( $json['choices'][0]['delta']['content'] ?? '' );
			$finish = (string) ( $json['choices'][0]['finish_reason'] ?? '' );
			if ( $delta !== '' ) {
				$content .= $delta;
				$callback( $delta, $finish );
			}
		};

		if ( function_exists( 'curl_init' ) ) {
			$content = '';
			$buffer  = '';
			$err     = '';
			$curl    = curl_init( $endpoint );
			curl_setopt_array( $curl, array(
				CURLOPT_POST           => true,
				CURLOPT_HTTPHEADER     => array(
					'Authorization: Bearer ' . $api_key,
					'Content-Type: application/json',
					'Accept: text/event-stream',
				),
				CURLOPT_POSTFIELDS     => $payload,
				CURLOPT_RETURNTRANSFER => false,
				CURLOPT_TIMEOUT        => 120,
				CURLOPT_CONNECTTIMEOUT => 15,
				CURLOPT_WRITEFUNCTION  => function ( $ch, $chunk ) use ( &$content, &$buffer, $hunk, $callback ) {
					$buffer .= $chunk;
					while ( ( $pos = strpos( $buffer, "\n" ) ) !== false ) {
						$line   = substr( $buffer, 0, $pos );
						$buffer = substr( $buffer, $pos + 1 );
						$hunk( $line, $content, $callback );
					}
					return strlen( $chunk );
				},
			) );
			curl_exec( $curl );
			if ( curl_errno( $curl ) ) {
				$err = (string) curl_error( $curl );
			}
			curl_close( $curl );
			if ( $err !== '' && $content === '' ) {
				return array( 'content' => '', 'model' => $model, 'error' => $err );
			}
			return array( 'content' => $content, 'model' => $model );
		}

		// Fallback bufferizado (sin cURL): mismo resultado, sin progresividad.
		$response = wp_remote_post( $endpoint, array(
			'timeout' => 120,
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			),
			'body'    => $payload,
		) );
		if ( is_wp_error( $response ) ) {
			return array( 'content' => '', 'model' => $model, 'error' => $response->get_error_message() );
		}
		$content = '';
		foreach ( explode( "\n", (string) wp_remote_retrieve_body( $response ) ) as $line ) {
			$hunk( $line, $content, $callback );
		}
		return array( 'content' => $content, 'model' => $model );
	}

	/** Parse una respuesta no-streaming. */
	private static function parse( $response, string $model ): array
	{
		if ( is_wp_error( $response ) ) {
			return self::fail( $response->get_error_message(), 'wp_error', $model );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $status !== 200 || ! is_array( $data ) ) {
			$msg = (string) ( $data['error']['message'] ?? 'Error de la API de IA.' );
			return self::fail( $msg, (string) ( $data['error']['code'] ?? 'http_' . $status ), $model );
		}
		$content = trim( (string) ( $data['choices'][0]['message']['content'] ?? '' ) );
		if ( $content === '' ) {
			return self::fail( 'El modelo devolvió una respuesta vacía.', 'empty_content', $model );
		}
		return array(
			'content'       => $content,
			'finish_reason' => (string) ( $data['choices'][0]['finish_reason'] ?? 'stop' ),
			'usage'         => is_array( $data['usage'] ?? null ) ? $data['usage'] : null,
			'model'         => (string) ( $data['model'] ?? $model ),
		);
	}

	/** Normaliza mensajes a {role, content} con roles permitidos y límite. */
	private static function sanitize_messages( array $messages ): array
	{
		$clean = array();
		foreach ( array_slice( $messages, -20 ) as $m ) {
			if ( ! isset( $m['role'], $m['content'] ) ) {
				continue;
			}
			$role = sanitize_key( (string) $m['role'] );
			if ( ! in_array( $role, array( 'system', 'user', 'assistant' ), true ) ) {
				continue;
			}
			$content = sanitize_textarea_field( (string) $m['content'] );
			if ( function_exists( 'mb_substr' ) ) {
				$content = mb_substr( $content, 0, 4000 );
			} else {
				$content = substr( $content, 0, 4000 );
			}
			if ( $content === '' ) {
				continue;
			}
			$clean[] = array( 'role' => $role, 'content' => $content );
		}
		return $clean;
	}

	/** Respuesta de error con contrato estable. */
	private static function fail( string $message, string $code, string $model = '' ): array
	{
		return array(
			'content'       => '',
			'finish_reason' => 'error',
			'usage'         => null,
			'model'         => $model,
			'error'         => $message,
			'error_code'    => $code,
		);
	}
}
