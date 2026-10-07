<?php
/**
 * DeepSeek adapter for the Estandar AI Gateway.
 *
 * DeepSeek implements the OpenAI-compatible chat completions format,
 * so this adapter reuses the same request/response structure with
 * a different endpoint and model set.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Estandar_AI_Adapter_DeepSeek implements Estandar_AI_Adapter {

	const ENDPOINT = 'https://api.deepseek.com/v1/chat/completions';

	public function provider_id(): string { return 'deepseek'; }
	public function provider_label(): string { return 'DeepSeek'; }

	public function capabilities(): array {
		return array( 'chat', 'stream', 'json', 'tools' );
	}

	public function available_models(): array {
		return array( 'deepseek-chat', 'deepseek-reasoner' );
	}

	public function default_model(): string { return 'deepseek-chat'; }

	public function supports_model( string $model ): bool {
		return in_array( $model, $this->available_models(), true );
	}

	public function chat( array $params ): array {
		$api_key = $this->resolve_key();
		if ( $api_key === '' ) {
			return array( 'content' => '', 'finish_reason' => 'error', 'usage' => null, 'model' => '' );
		}
		$body = $this->build_body( $params );
		$response = wp_remote_post( self::ENDPOINT, array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
			'timeout' => 60,
		) );
		return $this->parse_response( $response, $params['model'] ?? $this->default_model() );
	}

	public function chat_stream( array $params, callable $callback ): array {
		$api_key = $this->resolve_key();
		if ( $api_key === '' ) {
			return array( 'content' => '', 'model' => '' );
		}
		$body = $this->build_body( $params );
		$body['stream'] = true;
		// DeepSeek does NOT support stream_options.include_usage

		$response = wp_remote_post( self::ENDPOINT, array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
			'timeout' => 120,
		) );
		return $this->parse_stream_response( $response, $callback, $params['model'] ?? $this->default_model() );
	}

	public function chat_json( array $params ): array {
		// DeepSeek supports response_format via chat completions.
		// If not supported natively, fall back to instructing JSON in the prompt.
		$params['response_format'] = array( 'type' => 'json_object' );
		return $this->chat( $params );
	}

	public function embeddings( $input, string $model = '' ): array {
		// DeepSeek does not currently expose an embeddings API.
		// Return empty result gracefully.
		return array( 'vectors' => array(), 'model' => $model ?: 'deepseek-chat', 'usage' => null );
	}

	private function resolve_key(): string {
		return Estandar_AI_Gateway::resolve_api_key();
	}

	private function build_body( array $params ): array {
		$body = array(
			'model'    => $params['model'] ?? $this->default_model(),
			'messages' => $params['messages'] ?? array(),
		);
		if ( isset( $params['temperature'] ) ) {
			$body['temperature'] = max( 0.0, min( 2.0, (float) $params['temperature'] ) );
		}
		if ( isset( $params['max_tokens'] ) ) {
			$body['max_tokens'] = (int) $params['max_tokens'];
		}
		if ( isset( $params['response_format'] ) ) {
			$body['response_format'] = $params['response_format'];
		}
		if ( isset( $params['tools'] ) ) {
			$body['tools'] = $params['tools'];
		}
		return $body;
	}

	private function parse_response( $response, string $model ): array {
		if ( is_wp_error( $response ) ) {
			return array( 'content' => '', 'finish_reason' => 'error', 'usage' => null, 'model' => $model );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['choices'][0]['message']['content'] ) ) {
			return array( 'content' => '', 'finish_reason' => 'error', 'usage' => null, 'model' => $model );
		}
		return array(
			'content'       => (string) $data['choices'][0]['message']['content'],
			'finish_reason' => (string) ( $data['choices'][0]['finish_reason'] ?? 'stop' ),
			'usage'         => $data['usage'] ?? null,
			'model'         => $data['model'] ?? $model,
		);
	}

	private function parse_stream_response( $response, callable $callback, string $model ): array {
		$content = '';
		if ( is_wp_error( $response ) ) {
			return array( 'content' => '', 'model' => $model );
		}
		$body = wp_remote_retrieve_body( $response );
		$lines = explode( "\n", $body );
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( $line === '' || strpos( $line, 'data: ' ) !== 0 ) {
				continue;
			}
			$json = json_decode( substr( $line, 6 ), true );
			if ( ! $json ) {
				continue;
			}
			$delta = $json['choices'][0]['delta']['content'] ?? '';
			$finish = $json['choices'][0]['finish_reason'] ?? '';
			if ( $delta !== '' ) {
				$content .= $delta;
			}
			$callback( $delta, $finish );
		}
		return array( 'content' => $content, 'model' => $model );
	}
}
