<?php

namespace Estandar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared API-key vault for Estandar Suite v2.
 *
 * Lives in Core and is consumed by the 5 v2 products (Capture/Brain/Flow/
 * Insights + Core itself). Keys are stored in a single Core-owned option
 * (`estandar_suite_core_ai_settings`) and shared across the installation.
 *
 * Legacy coexistence contract:
 * - This vault NEVER writes to legacy options (estandar-shared-*, chatbot,
 *   website-scanner). It only READS them once, during adoption, to seed the
 *   v2 vault as a natural starting point.
 * - Adoption is one-time and idempotent: it fills only empty v2 slots and
 *   records the origin option name for auditability.
 * - Legacy plugins keep working untouched: they still read their own options.
 */
class AiKeys
{
	const OPTION      = 'estandar_suite_core_ai_settings';
	const ADOPTED_FLAG = 'estandar_suite_core_ai_adopted_from';

	/**
	 * Providers managed by the v2 vault (label => short help).
	 *
	 * Only DeepSeek + OpenAI today (the two adapters present in the AI
	 * gateway). Keys mirror the provider ids used elsewhere.
	 *
	 * @return array<string,string>
	 */
	public static function providers(): array
	{
		return array(
			'deepseek' => 'DeepSeek API Key',
			'openai'   => 'OpenAI API Key',
		);
	}

	/** Option names in legacy plugins that can seed the vault (read-only). */
	public static function legacy_sources(): array
	{
		return array(
			'deepseek' => array(
				// No legacy deepseek key exists today; future-proof slot.
			),
			'openai' => array(
				'estandar_shared_openai_key',    // Estandar Primer
				'chatbot_openai_api_key',        // Estandar Chatbot (legacy)
				'estandar_openai_api_key',       // Estandar Website Scanner
			),
		);
	}

	/**
	 * Read the raw v2 vault.
	 *
	 * @return array{deepseek?:string,openai?:string}
	 */
	public static function get_settings(): array
	{
		$settings = get_option( self::OPTION, array() );
		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Get a provider key from the v2 vault, with read-only legacy fallback.
	 *
	 * Priority: v2 stored value → legacy source value (only while the vault has
	 * not been adopted yet) → ''. After adoption, legacy is never re-used as a
	 * fallback: a deleted key stays deleted (no resurrection from legacy).
	 * Never writes to legacy options.
	 */
	public static function get( string $provider ): string
	{
		$provider = sanitize_key( $provider );
		$settings = self::get_settings();
		$key      = (string) ( $settings[ $provider ] ?? '' );
		$key      = self::normalize_key( $key );
		if ( $key !== '' ) {
			return $key;
		}
		if ( self::is_adopted() ) {
			return '';
		}
		return self::get_legacy( $provider );
	}

	/**
	 * Resolve the active provider id.
	 *
	 * Defaults to 'deepseek' when no explicit choice is stored. The default is
	 * stored in the vault only after a save, so this stays stateless.
	 */
	public static function active_provider(): string
	{
		$settings = self::get_settings();
		$active   = sanitize_key( (string) ( $settings['active_provider'] ?? 'deepseek' ) );
		return isset( self::providers()[ $active ] ) ? $active : 'deepseek';
	}

	/** Default model for the active provider. */
	public static function default_model(): string
	{
		$settings = self::get_settings();
		$model    = (string) ( $settings['default_model'] ?? '' );
		if ( $model !== '' ) {
			return $model;
		}
		return self::active_provider() === 'openai' ? 'gpt-4o-mini' : 'deepseek-chat';
	}

	/** Persist the vault (admin only; sanitized by the caller). */
	public static function update_settings( array $settings ): void
	{
		update_option( self::OPTION, self::sanitize_settings( $settings ), false );
	}

	/**
	 * One-time adoption: seed empty v2 slots from legacy options (read-only).
	 *
	 * Called on every admin load for the keys tab, but only performs work the
	 * first time. The gate is the persistent adoption flag (not the transient
	 * `adopted` field in the vault, which is reset by every save) so a deleted
	 * key is never re-seeded from legacy. Idempotent: never overwrites a value
	 * the user already saved in v2.
	 */
	public static function adopt_legacy_once(): array
	{
		$settings = self::get_settings();
		if ( self::is_adopted() ) {
			return $settings;
		}

		$adopted = array();
		foreach ( self::legacy_sources() as $provider => $options ) {
			foreach ( $options as $option ) {
				$value = self::normalize_key( (string) get_option( $option, '' ) );
				if ( $value === '' ) {
					continue;
				}
				if ( empty( $settings[ $provider ] ) ) {
					$settings[ $provider ] = $value;
				}
				$adopted[ $provider ] = isset( $adopted[ $provider ] ) ? $adopted[ $provider ] : $option;
				break;
			}
		}

		$settings['adopted'] = 1;
		update_option( self::OPTION, self::sanitize_settings( $settings ), false );
		update_option( self::ADOPTED_FLAG, $adopted, false );
		return $settings;
	}

	/** True once the vault has been adopted (persistent flag exists). */
	public static function is_adopted(): bool
	{
		return get_option( self::ADOPTED_FLAG, null ) !== null;
	}

	/** Read a provider key from legacy options only (read-only). */
	private static function get_legacy( string $provider ): string
	{
		foreach ( ( self::legacy_sources()[ $provider ] ?? array() ) as $option ) {
			$value = self::normalize_key( (string) get_option( $option, '' ) );
			if ( $value !== '' ) {
				return $value;
			}
		}
		return '';
	}

	/** Sanitize the vault shape (providers + meta). */
	private static function sanitize_settings( array $raw ): array
	{
		$clean = array();
		foreach ( self::providers() as $provider => $label ) {
			$clean[ $provider ] = self::normalize_key( (string) ( $raw[ $provider ] ?? '' ) );
		}
		$active = sanitize_key( (string) ( $raw['active_provider'] ?? '' ) );
		$clean['active_provider'] = isset( self::providers()[ $active ] ) ? $active : 'deepseek';
		$clean['default_model']   = sanitize_text_field( (string) ( $raw['default_model'] ?? '' ) );
		$clean['adopted']         = empty( $raw['adopted'] ) ? 0 : 1;
		return $clean;
	}

	/** Normalize a stored key (trim whitespace/line breaks; no transformation). */
	private static function normalize_key( string $value ): string
	{
		return trim( str_replace( array( "\r", "\n", "\t" ), '', $value ) );
	}
}
