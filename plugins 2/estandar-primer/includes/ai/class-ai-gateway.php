<?php
/**
 * Estandar AI Gateway — central registry and dispatch for AI operations.
 *
 * All estandar-* plugins call AI exclusively through this gateway.
 * No plugin consumer knows the provider.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/interface-ai-adapter.php';

class Estandar_AI_Gateway {

	const OPTION_PROVIDER = 'estandar_ai_active_provider';
	const OPTION_MODEL    = 'estandar_ai_default_model';

	/** @var array<string, Estandar_AI_Adapter> */
	private static array $adapters = array();

	/** @var ?Estandar_AI_Adapter */
	private static ?Estandar_AI_Adapter $active = null;

	/** Register an adapter (idempotent by provider_id). */
	public static function register( Estandar_AI_Adapter $adapter ): void {
		$id = $adapter->provider_id();
		if ( $id !== '' ) {
			self::$adapters[ $id ] = $adapter;
		}
	}

	/** @return Estandar_AI_Adapter[] */
	public static function adapters(): array {
		return array_values( self::$adapters );
	}

	/** Get the active provider adapter. */
	public static function active(): ?Estandar_AI_Adapter {
		if ( self::$active !== null ) {
			return self::$active;
		}
		$provider = (string) get_option( self::OPTION_PROVIDER, 'openai' );
		if ( isset( self::$adapters[ $provider ] ) ) {
			self::$active = self::$adapters[ $provider ];
			return self::$active;
		}
		// Fallback to first registered adapter
		$all = self::adapters();
		return $all[0] ?? null;
	}

	/** Resolve an API key for the active provider. */
	public static function resolve_api_key(): string {
		$active = self::active();
		if ( ! $active ) {
			return '';
		}
		$pid = $active->provider_id();

		// 1. Provider-specific filter (for backward compat)
		$key = (string) apply_filters( 'estandar_ai_api_key_' . $pid, '' );

		// 2. Shared secret from Primer
		if ( $key === '' && function_exists( 'estandar_core_get_secret' ) ) {
			$key = (string) estandar_core_get_secret( $pid );
		}

		return $key;
	}

	/** Chat completion. */
	public static function chat( array $params ): array {
		$adapter = self::active();
		if ( ! $adapter ) {
			return array( 'content' => '', 'finish_reason' => 'error', 'usage' => null, 'model' => '' );
		}
		$params['model'] = $params['model'] ?? self::default_model();
		return $adapter->chat( $params );
	}

	/** Streaming chat. */
	public static function chat_stream( array $params, callable $callback ): array {
		$adapter = self::active();
		if ( ! $adapter ) {
			return array( 'content' => '', 'model' => '' );
		}
		$params['model'] = $params['model'] ?? self::default_model();
		return $adapter->chat_stream( $params, $callback );
	}

	/** JSON chat. */
	public static function chat_json( array $params ): array {
		$adapter = self::active();
		if ( ! $adapter ) {
			return array( 'content' => '', 'finish_reason' => 'error', 'usage' => null, 'model' => '' );
		}
		$params['model'] = $params['model'] ?? self::default_model();
		return $adapter->chat_json( $params );
	}

	/** Embeddings. */
	public static function embeddings( $input, string $model = '' ): array {
		$adapter = self::active();
		if ( ! $adapter ) {
			return array( 'vectors' => array(), 'model' => '', 'usage' => null );
		}
		return $adapter->embeddings( $input, $model ?: self::default_model() );
	}

	/** Check model support. */
	public static function supports_model( string $model ): bool {
		$adapter = self::active();
		return $adapter ? $adapter->supports_model( $model ) : false;
	}

	/** Default model for active provider. */
	public static function default_model(): string {
		$model = (string) get_option( self::OPTION_MODEL, '' );
		if ( $model !== '' ) {
			return $model;
		}
		$adapter = self::active();
		return $adapter ? $adapter->default_model() : 'gpt-4o-mini';
	}

	/** Available models for active provider. */
	public static function available_models(): array {
		$adapter = self::active();
		return $adapter ? $adapter->available_models() : array();
	}

	/** Active provider id string. */
	public static function active_provider_id(): string {
		$adapter = self::active();
		return $adapter ? $adapter->provider_id() : '';
	}

	/** Active provider label. */
	public static function active_provider_label(): string {
		$adapter = self::active();
		return $adapter ? $adapter->provider_label() : '';
	}
}
