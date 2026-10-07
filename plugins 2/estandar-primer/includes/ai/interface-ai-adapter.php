<?php
/**
 * Estandar AI Gateway — provider-agnostic adapter interface.
 *
 * Every AI provider adapter implements this contract.
 * Consumers (chatbot, scanner, etc.) depend ONLY on this interface.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

interface Estandar_AI_Adapter {

	/** Stable provider id: 'openai', 'deepseek', etc. */
	public function provider_id(): string;

	/** Human-readable label */
	public function provider_label(): string;

	/** Capabilities this provider supports */
	public function capabilities(): array;

	/**
	 * Chat completion (non-streaming).
	 * @param array $params { model, messages, temperature?, max_tokens?, response_format?, tools?, stream? }
	 * @return array { content: string, finish_reason: string, usage: array|null, model: string }
	 */
	public function chat( array $params ): array;

	/**
	 * Chat completion (streaming). Calls $callback for each delta.
	 * @param array    $params   same as chat()
	 * @param callable $callback fn(string $delta, string $finish_reason): void
	 * @return array { content: string, model: string }
	 */
	public function chat_stream( array $params, callable $callback ): array;

	/**
	 * Structured JSON output (response_format={type:'json_object'}).
	 * Falls back to parsing chat() response when provider lacks native support.
	 */
	public function chat_json( array $params ): array;

	/**
	 * Text embeddings.
	 * @param string|string[] $input
	 * @param string          $model
	 * @return array{ vectors: array, model: string, usage: array|null }
	 */
	public function embeddings( $input, string $model = '' ): array;

	/**
	 * Check if a model name is valid for this provider.
	 */
	public function supports_model( string $model ): bool;

	/** Default model for this provider. */
	public function default_model(): string;

	/** List of recommended models for this provider. */
	public function available_models(): array;
}
