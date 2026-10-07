<?php
/**
 * OpenAI adapter for the Estandar AI Gateway.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Estandar_AI_Adapter_OpenAI implements Estandar_AI_Adapter {

	const ENDPOINT = 'https://api.openai.com/v1/chat/completions';
	const EMBEDDINGS_ENDPOINT = 'https://api.openai.com/v1/embeddings';

	public function provider_id(): string { return 'openai'; }
	public function provider_label(): string { return 'OpenAI'; }

	public function capabilities(): array {
		return array( 'chat', 'stream', 'json', 'vision', 'embeddings', 'tools', 'audio', 'image' );
	}

	public function available_models(): array {
		return array( 'gpt-4o-mini', 'gpt-4o', 'gpt-4-turbo', 'gpt-4', 'gpt-3.5-turbo' );
	}

	public function default_model(): string { return 'gpt-4o-mini'; }

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
		$body['stream_options'] = array( 'include_usage' => true );

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
		$params['response_format'] = array( 'type' => 'json_object' );
		return $this->chat( $params );
	}

	public function embeddings( $input, string $model = '' ): array {
		$api_key = $this->resolve_key();
		if ( $api_key === '' ) {
			return array( 'vectors' => array(), 'model' => '', 'usage' => null );
		}
		$model = $model ?: 'text-embedding-3-small';
		$inputs = is_string( $input ) ? array( $input ) : $input;
		$response = wp_remote_post( self::EMBEDDINGS_ENDPOINT, array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			),
			'body' => wp_json_encode( array(
				'model' => $model,
				'input' => $inputs,
			) ),
			'timeout' => 60,
		) );
		if ( is_wp_error( $response ) ) {
			return array( 'vectors' => array(), 'model' => $model, 'usage' => null );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$vectors = array();
		foreach ( ( $data['data'] ?? array() ) as $item ) {
			$vectors[] = $item['embedding'] ?? array();
		}
		return array(
			'vectors' => $vectors,
			'model'   => $data['model'] ?? $model,
			'usage'   => $data['usage'] ?? null,
		);
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
		if ( ! empty( $params['stream'] ) ) {
			$body['stream'] = true;
		}
		return $body;
	}

	private function parse_response( $response, string $model ): array {
		if ( is_wp_error( $response ) ) {
			return array( 'content' => '', 'finish_reason' => 'error', 'usage' => null, 'model' => $model );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['choices'][0]['message']['content'] ) ) {
			$error = $data['error']['message'] ?? 'empty response';
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
