<?php
/**
 * Plugin Name: Estandar Primer
 * Description: Runtime compartido para plugins Estandar: menú unificado + helpers + assets admin (UI).
 * Version: 13.0.173
 * Author: Luis Valdés de Estandar Studios CL
 * Author URI: https://www.estandar.cl
 * Update URI: https://updates.estandar.cl
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ESTANDAR_CORE_MU_VERSION', '13.0.173' );
define( 'ESTANDAR_CORE_MU_FILE', __FILE__ );

// ---------------------------------------------------------------------------
// PRIMER — canonical name for the Estandar WordPress runtime layer.
// ESTANDAR_CORE_MU_* constants remain for backward compatibility with
// consumer plugins that check defined('ESTANDAR_CORE_MU_VERSION').
// ---------------------------------------------------------------------------
define( 'PRIMER_VERSION', ESTANDAR_CORE_MU_VERSION );
define( 'PRIMER_FILE', ESTANDAR_CORE_MU_FILE );

if ( ! defined( 'ESTANDAR_ADMIN_MODE_LEGACY' ) ) {
	define( 'ESTANDAR_ADMIN_MODE_LEGACY', 'legacy' );
}
if ( ! defined( 'ESTANDAR_ADMIN_MODE_COMPAT' ) ) {
	define( 'ESTANDAR_ADMIN_MODE_COMPAT', 'compat' );
}
if ( ! defined( 'ESTANDAR_ADMIN_MODE_NATIVE' ) ) {
	define( 'ESTANDAR_ADMIN_MODE_NATIVE', 'native' );
}

/**
 * ======================================================
 * RUNTIME CONTRACTS (Phase 1, passive)
 * ======================================================
 *
 * Core owns shared infrastructure contracts: admin shell, shared UI tokens,
 * shared admin assets, and future storage/event/permission/diagnostic helpers.
 * Plugins own business logic, frontend behavior, plugin-specific settings,
 * plugin-specific assets, and data models unless a later migration explicitly
 * moves that responsibility into Core.
 *
 * This layer is intentionally additive and passive: no plugin migrations, no
 * DB writes, no directory creation, no cron/runtime orchestration.
 *
 * Admin render ownership is distinct from Core availability:
 * - legacy: plugin owns the full page render; Core may only list/route it.
 * - compat: Core may provide shell/layout; plugin still owns most content.
 * - native: Core owns shell/layout/contracts; plugin provides structured content.
 *
 * Future migrations should declare ownership before changing headers, wrappers,
 * branding, assets, or layout behavior.
 */

if ( ! function_exists( 'estandar_core_trailingslashit' ) ) {
	function estandar_core_trailingslashit( string $path ): string {
		return rtrim( $path, "/\\ \t\n\r\0\x0B" ) . '/';
	}
}

/**
 * ======================================================
 * PATH/URL RESOLUTION (MU safe, zip-safe)
 * ======================================================
 *
 * Goal: resolve assets deterministically without manual path fixes.
 *
 * Supported common layouts:
 * 1) /wp-content/mu-plugins/estandar-core.php
 *    /wp-content/mu-plugins/estandar-core/admin/admin.css
 *    /wp-content/mu-plugins/estandar-core/admin/admin.js
 *
 * 2) Legacy zip layouts (staging/export mistakes) where the folder is nested:
 *    /wp-content/mu-plugins/estandar-primer/estandar-core/admin/...
 *    /wp-content/mu-plugins/estandar-core-mu/estandar-core/admin/... (pre-rename installs)
 */

define(
	'ESTANDAR_CORE_MU_BASE_DIR',
	defined( 'WPMU_PLUGIN_DIR' )
		? estandar_core_trailingslashit( (string) WPMU_PLUGIN_DIR )
		: ( defined( 'WP_CONTENT_DIR' ) ? estandar_core_trailingslashit( WP_CONTENT_DIR . '/mu-plugins' ) : estandar_core_trailingslashit( dirname( __FILE__ ) ) )
);

define(
	'ESTANDAR_CORE_MU_BASE_URL',
	defined( 'WPMU_PLUGIN_URL' )
		? estandar_core_trailingslashit( (string) WPMU_PLUGIN_URL )
		: ( function_exists( 'content_url' ) ? estandar_core_trailingslashit( (string) content_url( 'mu-plugins' ) ) : estandar_core_trailingslashit( plugin_dir_url( __FILE__ ) ) )
);

if ( ! function_exists( 'estandar_core_mu_path_to_url' ) ) {
	function estandar_core_mu_path_to_url( string $abs_path ): string {
		$base_dir = estandar_core_trailingslashit( ESTANDAR_CORE_MU_BASE_DIR );
		$base_url = estandar_core_trailingslashit( ESTANDAR_CORE_MU_BASE_URL );

		$abs_path_norm = str_replace( '\\', '/', $abs_path );
		$base_dir_norm = str_replace( '\\', '/', $base_dir );

		if ( 0 === strpos( $abs_path_norm, $base_dir_norm ) ) {
			$rel = ltrim( substr( $abs_path_norm, strlen( $base_dir_norm ) ), '/' );
			return $base_url . $rel;
		}

		// Fallback: best effort based on current file URL (less ideal, but avoids fatals).
		return estandar_core_trailingslashit( plugin_dir_url( __FILE__ ) ) . ltrim( basename( $abs_path_norm ), '/' );
	}
}

if ( ! function_exists( 'estandar_core_mu_locate_assets_base' ) ) {
	/**
	 * @return array{dir:string,url:string,tried:string[]}
	 */
	function estandar_core_mu_locate_assets_base(): array {
		$relative_css = 'admin/admin.css';
		$relative_js  = 'admin/admin.js';

		$mu_dir = estandar_core_trailingslashit( ESTANDAR_CORE_MU_BASE_DIR );
		$here   = estandar_core_trailingslashit( dirname( __FILE__ ) );

		$candidates = [
			$mu_dir . 'estandar-core/',
			$here . 'estandar-core/',
			$mu_dir . 'estandar-primer/estandar-core/',
			$here . 'estandar-primer/estandar-core/',
			$mu_dir . 'estandar-core-mu/estandar-core/',
			$here . 'estandar-core-mu/estandar-core/',
			$here,
			$mu_dir,
		];

		$tried = [];
		foreach ( $candidates as $candidate_base ) {
			$candidate_base = estandar_core_trailingslashit( $candidate_base );
			$tried[] = $candidate_base;

			$css_ok = file_exists( $candidate_base . $relative_css );
			$js_ok  = file_exists( $candidate_base . $relative_js );
			if ( $css_ok || $js_ok ) {
				return [
					'dir'   => $candidate_base,
					'url'   => estandar_core_trailingslashit( estandar_core_mu_path_to_url( $candidate_base ) ),
					'tried' => $tried,
				];
			}
		}

		// Default to canonical expected location to keep predictable constants.
		return [
			'dir'   => $mu_dir . 'estandar-core/',
			'url'   => $mu_dir ? estandar_core_trailingslashit( ESTANDAR_CORE_MU_BASE_URL . 'estandar-core/' ) : estandar_core_trailingslashit( ESTANDAR_CORE_MU_BASE_URL ),
			'tried' => $tried,
		];
	}
}

$estandar_core_assets_base = estandar_core_mu_locate_assets_base();
define( 'ESTANDAR_CORE_MU_DIR', $estandar_core_assets_base['dir'] );
define( 'ESTANDAR_CORE_MU_URL', $estandar_core_assets_base['url'] );
define( 'PRIMER_DIR', ESTANDAR_CORE_MU_DIR );
define( 'PRIMER_URL', ESTANDAR_CORE_MU_URL );

/**
 * Parent slug único para agrupar plugins Estandar.
 *
 * Nota: se mantiene `estandar_main_menu` porque ya hay plugins usándolo.
 */
define( 'ESTANDAR_CORE_MENU_SLUG', 'estandar_main_menu' );
// Canonical alias. Value is unchanged — admin URLs and bookmarks are unaffected.
define( 'PRIMER_MENU_SLUG', ESTANDAR_CORE_MENU_SLUG );

if ( ! defined( 'ESTANDAR_SENDER_NAME' ) ) {
	define( 'ESTANDAR_SENDER_NAME', 'estandar_sender_name' );
}

if ( ! defined( 'ESTANDAR_SENDER_EMAIL' ) ) {
	define( 'ESTANDAR_SENDER_EMAIL', 'estandar_sender_email' );
}

if ( ! defined( 'ESTANDAR_CLIENT_SLUG_OPTION' ) ) {
	define( 'ESTANDAR_CLIENT_SLUG_OPTION', 'estandar_client_slug' );
}

if ( ! defined( 'ESTANDAR_CLIENT_NAME_OPTION' ) ) {
	define( 'ESTANDAR_CLIENT_NAME_OPTION', 'estandar_client_name' );
}

if ( ! defined( 'ESTANDAR_CLIENT_CONTEXT_RECORD_OPTION' ) ) {
	define( 'ESTANDAR_CLIENT_CONTEXT_RECORD_OPTION', 'estandar_client_context_record' );
}

if ( ! defined( 'ESTANDAR_SHARED_OPENAI_KEY' ) ) {
	define( 'ESTANDAR_SHARED_OPENAI_KEY', 'estandar_shared_openai_key' );
}

if ( ! defined( 'ESTANDAR_SHARED_ANTHROPIC_KEY' ) ) {
	define( 'ESTANDAR_SHARED_ANTHROPIC_KEY', 'estandar_shared_anthropic_key' );
}

if ( ! defined( 'ESTANDAR_SHARED_GOOGLE_AI_KEY' ) ) {
	define( 'ESTANDAR_SHARED_GOOGLE_AI_KEY', 'estandar_shared_google_ai_key' );
}

if ( ! defined( 'ESTANDAR_SHARED_PINECONE_KEY' ) ) {
	define( 'ESTANDAR_SHARED_PINECONE_KEY', 'estandar_shared_pinecone_key' );
}

if ( ! defined( 'ESTANDAR_SHARED_ELEVENLABS_KEY' ) ) {
	define( 'ESTANDAR_SHARED_ELEVENLABS_KEY', 'estandar_shared_elevenlabs_key' );
}

if ( ! defined( 'ESTANDAR_SHARED_RUNWAY_KEY' ) ) {
	define( 'ESTANDAR_SHARED_RUNWAY_KEY', 'estandar_shared_runway_key' );
}

/**
 * Admin Shell API version.
 * Incrementar en cambios incompatibles.
 * Plugins verifican con: estandar_core_supports('navigation')
 */
if ( ! defined( 'ESTANDAR_CORE_API_VERSION' ) ) {
	define( 'ESTANDAR_CORE_API_VERSION', 2 );
}

if ( ! defined( 'ESTANDAR_CORE_CAPABILITIES' ) ) {
	define( 'ESTANDAR_CORE_CAPABILITIES', array(
		'breadcrumb',
		'navigation',
		'toolbar',
		'header_slot',
		'footer_slot',
	) );
}

if ( ! function_exists( 'estandar_core_supports' ) ) {
	/**
	 * Verifica si Core soporta una capacidad del Admin Shell.
	 * Permite que plugins adapten su output al contrato disponible.
	 *
	 * @param string $capability  Una de: breadcrumb, navigation, toolbar, header_slot, footer_slot.
	 */
	function estandar_core_supports( string $capability ): bool {
		return in_array( $capability, ESTANDAR_CORE_CAPABILITIES, true );
	}
}

if ( ! function_exists( 'primer_is_active' ) ) {
	/** Canonical detection function for the Primer runtime layer. */
	function primer_is_active(): bool {
		return defined( 'PRIMER_VERSION' );
	}
}

// AI Gateway — provider-agnostic interface for all estandar-* plugins.
// Loaded early so consumers can register adapters via plugins_loaded.
require_once __DIR__ . '/includes/ai/interface-ai-adapter.php';
require_once __DIR__ . '/includes/ai/class-ai-gateway.php';
require_once __DIR__ . '/includes/ai/class-ai-adapter-openai.php';
require_once __DIR__ . '/includes/ai/class-ai-adapter-deepseek.php';

add_action( 'plugins_loaded', function () {
	Estandar_AI_Gateway::register( new Estandar_AI_Adapter_OpenAI() );
	Estandar_AI_Gateway::register( new Estandar_AI_Adapter_DeepSeek() );
}, 5 );

/**
 * Helper for consumers: shorthand for Estandar_AI_Gateway::chat().
 * Falls back to direct OpenAI call if gateway is unavailable (backward compat).
 */
if ( ! function_exists( 'estandar_ai_chat' ) ) {
	function estandar_ai_chat( array $params ): array {
		if ( class_exists( 'Estandar_AI_Gateway' ) ) {
			return Estandar_AI_Gateway::chat( $params );
		}
		// Legacy fallback — direct OpenAI call (removed in future versions)
		return array( 'content' => '', 'finish_reason' => 'error', 'usage' => null, 'model' => '' );
	}
}

// Shared implementation: estandar_core_mu_is_active() delegates to primer_is_active().
// Consumer plugins carry a bootstrap fallback for the same function; it executes only
// when Primer has not yet loaded and is skipped entirely once Primer is present.
if ( ! function_exists( 'estandar_core_mu_is_active' ) ) {
	function estandar_core_mu_is_active(): bool {
		return primer_is_active();
	}
}

if ( ! function_exists( 'estandar_core_get_sender_name' ) ) {
	function estandar_core_get_sender_name( string $plugin_override = '', string $fallback = '' ): string {
		$core = sanitize_text_field( (string) get_option( ESTANDAR_SENDER_NAME, '' ) );
		if ( '' !== $core ) {
			return $core;
		}

		$plugin_override = sanitize_text_field( $plugin_override );
		if ( '' !== $plugin_override ) {
			return $plugin_override;
		}

		$fallback = sanitize_text_field( $fallback );
		return '' !== $fallback ? $fallback : (string) get_bloginfo( 'name' );
	}
}

if ( ! function_exists( 'estandar_core_get_sender_email' ) ) {
	function estandar_core_get_sender_email( string $plugin_override = '', string $fallback = '' ): string {
		$core = sanitize_email( (string) get_option( ESTANDAR_SENDER_EMAIL, '' ) );
		if ( is_email( $core ) ) {
			return $core;
		}

		$plugin_override = sanitize_email( $plugin_override );
		if ( is_email( $plugin_override ) ) {
			return $plugin_override;
		}

		$fallback = sanitize_email( $fallback );
		return is_email( $fallback ) ? $fallback : sanitize_email( (string) get_option( 'admin_email', '' ) );
	}
}

if ( ! function_exists( 'estandar_core_get_client_slug' ) ) {
	function estandar_core_get_client_slug( string $fallback = '' ): string {
		$value = sanitize_title( remove_accents( (string) get_option( ESTANDAR_CLIENT_SLUG_OPTION, '' ) ) );
		if ( '' !== $value ) {
			return $value;
		}

		return sanitize_title( remove_accents( $fallback ) );
	}
}

if ( ! function_exists( 'estandar_core_get_client_name' ) ) {
	function estandar_core_get_client_name( string $fallback = '' ): string {
		$value = sanitize_text_field( (string) get_option( ESTANDAR_CLIENT_NAME_OPTION, '' ) );
		if ( '' !== $value ) {
			return $value;
		}

		return sanitize_text_field( $fallback );
	}
}

if ( ! defined( 'ESTANDAR_CLIENT_SLUG' ) ) {
	define( 'ESTANDAR_CLIENT_SLUG', estandar_core_get_client_slug() );
}

if ( ! defined( 'ESTANDAR_CLIENT_NAME' ) ) {
	define( 'ESTANDAR_CLIENT_NAME', estandar_core_get_client_name() );
}

if ( ! function_exists( 'estandar_core_textarea_to_lines' ) ) {
	function estandar_core_textarea_to_lines( $value ): array {
		$value = str_replace( [ "\r\n", "\r" ], "\n", (string) wp_unslash( $value ) );
		$lines = array_filter( array_map( 'trim', explode( "\n", $value ) ), static fn( string $line ): bool => $line !== '' );
		return array_values( array_map( 'sanitize_text_field', $lines ) );
	}
}

if ( ! function_exists( 'estandar_core_sanitize_client_context_record' ) ) {
	function estandar_core_sanitize_client_context_record( $value ): array {
		$value = is_array( $value ) ? wp_unslash( $value ) : [];
		$contact = static function ( array $data ): array {
			return [
				'name'  => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
				'email' => sanitize_email( (string) ( $data['email'] ?? '' ) ),
				'phone' => sanitize_text_field( (string) ( $data['phone'] ?? '' ) ),
			];
		};

		return [
			'company'             => sanitize_text_field( (string) ( $value['company'] ?? '' ) ),
			'business'            => sanitize_text_field( (string) ( $value['business'] ?? '' ) ),
			'website'             => esc_url_raw( (string) ( $value['website'] ?? '' ) ),
			'owner'               => sanitize_text_field( (string) ( $value['owner'] ?? '' ) ),
			'account_manager'     => sanitize_text_field( (string) ( $value['account_manager'] ?? '' ) ),
			'services'            => estandar_core_textarea_to_lines( $value['services'] ?? [] ),
			'primary_service'     => sanitize_text_field( (string) ( $value['primary_service'] ?? '' ) ),
			'lead_goal'           => sanitize_text_field( (string) ( $value['lead_goal'] ?? '' ) ),
			'success_definition'  => sanitize_text_field( (string) ( $value['success_definition'] ?? '' ) ),
			'contacts'            => [
				'commercial'  => $contact( is_array( $value['contacts']['commercial'] ?? null ) ? $value['contacts']['commercial'] : [] ),
				'operational' => $contact( is_array( $value['contacts']['operational'] ?? null ) ? $value['contacts']['operational'] : [] ),
				'escalation'  => $contact( is_array( $value['contacts']['escalation'] ?? null ) ? $value['contacts']['escalation'] : [] ),
			],
			'handoff'             => [
				'owner'      => sanitize_text_field( (string) ( $value['handoff']['owner'] ?? '' ) ),
				'fallback'   => sanitize_text_field( (string) ( $value['handoff']['fallback'] ?? '' ) ),
				'conditions' => estandar_core_textarea_to_lines( $value['handoff']['conditions'] ?? [] ),
			],
			'rules'               => estandar_core_textarea_to_lines( $value['rules'] ?? [] ),
			'client_rules'        => estandar_core_textarea_to_lines( $value['client_rules'] ?? [] ),
			'projects'            => estandar_core_textarea_to_lines( $value['projects'] ?? [] ),
			'notes'               => sanitize_textarea_field( (string) ( $value['notes'] ?? '' ) ),
			'updated_at'          => current_time( 'mysql' ),
		];
	}
}

if ( ! function_exists( 'estandar_core_get_client_context_record' ) ) {
	function estandar_core_get_client_context_record(): array {
		$record = get_option( ESTANDAR_CLIENT_CONTEXT_RECORD_OPTION, [] );
		return is_array( $record ) ? $record : [];
	}
}

if ( ! function_exists( 'estandar_core_format_client_context_record' ) ) {
	function estandar_core_format_client_context_record( ?array $record = null ): string {
		$record = $record ?? estandar_core_get_client_context_record();
		if ( empty( $record ) || ! is_array( $record ) ) {
			return '';
		}

		$lines = [];
		$add = static function ( string $label, $value ) use ( &$lines ): void {
			$value = is_array( $value ) ? implode( ', ', array_filter( array_map( 'strval', $value ) ) ) : trim( (string) $value );
			if ( $value !== '' ) {
				$lines[] = $label . ': ' . $value;
			}
		};

		$add( 'Empresa', $record['company'] ?? estandar_core_get_client_name() );
		$add( 'Rubro', $record['business'] ?? '' );
		$add( 'Sitio web', $record['website'] ?? '' );
		$add( 'Servicios', $record['services'] ?? [] );
		$add( 'Servicio principal', $record['primary_service'] ?? '' );
		$add( 'Objetivo comercial', $record['lead_goal'] ?? '' );
		$add( 'Definicion de exito', $record['success_definition'] ?? '' );
		$add( 'Responsable handoff', $record['handoff']['owner'] ?? '' );
		$add( 'Fallback handoff', $record['handoff']['fallback'] ?? '' );
		$add( 'Condiciones de handoff', $record['handoff']['conditions'] ?? [] );
		$add( 'Reglas operativas', $record['rules'] ?? [] );
		$add( 'Reglas del cliente', $record['client_rules'] ?? [] );
		$add( 'Proyectos / lineas', $record['projects'] ?? [] );
		$add( 'Notas', $record['notes'] ?? '' );

		foreach ( [ 'commercial' => 'Contacto comercial', 'operational' => 'Contacto operacional', 'escalation' => 'Contacto escalamiento' ] as $key => $label ) {
			$contact = is_array( $record['contacts'][ $key ] ?? null ) ? $record['contacts'][ $key ] : [];
			$add( $label, array_filter( [ $contact['name'] ?? '', $contact['email'] ?? '', $contact['phone'] ?? '' ] ) );
		}

		return trim( implode( "\n", $lines ) );
	}
}

if ( ! function_exists( 'estandar_core_get_ea_sid' ) ) {
	function estandar_core_get_ea_sid( ?array $source = null, string $key = 'ea_sid' ): string {
		$source = null === $source ? $_REQUEST : $source;
		if ( ! isset( $source[ $key ] ) || is_array( $source[ $key ] ) ) {
			return '';
		}
		$value = sanitize_text_field( wp_unslash( $source[ $key ] ) );
			return preg_match( '/^[a-zA-Z0-9_\-]{8,64}$/', $value ) ? $value : '';
	}
}

if ( ! function_exists( 'estandar_core_shared_secret_options' ) ) {
	/**
	 * @return array<string,string>
	 */
	function estandar_core_shared_secret_options(): array {
		return [
			'openai'     => ESTANDAR_SHARED_OPENAI_KEY,
			'anthropic'  => ESTANDAR_SHARED_ANTHROPIC_KEY,
			'google_ai'  => ESTANDAR_SHARED_GOOGLE_AI_KEY,
			'pinecone'   => ESTANDAR_SHARED_PINECONE_KEY,
			'elevenlabs' => ESTANDAR_SHARED_ELEVENLABS_KEY,
			'runway'     => ESTANDAR_SHARED_RUNWAY_KEY,
		];
	}
}

if ( ! function_exists( 'estandar_core_sanitize_secret' ) ) {
	function estandar_core_sanitize_secret( $value ): string {
		$value = (string) wp_unslash( $value );
		$value = str_replace( [ "\r", "\n", "\t" ], '', $value );
		return trim( $value );
	}
}

if ( ! function_exists( 'estandar_core_get_secret' ) ) {
	function estandar_core_get_secret( string $provider ): string {
		$provider = sanitize_key( $provider );
		$options  = estandar_core_shared_secret_options();
		if ( ! isset( $options[ $provider ] ) ) {
			return '';
		}

		return estandar_core_sanitize_secret( get_option( $options[ $provider ], '' ) );
	}
}

add_filter( 'pre_update_option_' . ESTANDAR_SHARED_OPENAI_KEY, 'estandar_core_preserve_empty_secret_update', 10, 2 );
add_filter( 'pre_update_option_' . ESTANDAR_SHARED_ANTHROPIC_KEY, 'estandar_core_preserve_empty_secret_update', 10, 2 );
add_filter( 'pre_update_option_' . ESTANDAR_SHARED_GOOGLE_AI_KEY, 'estandar_core_preserve_empty_secret_update', 10, 2 );
add_filter( 'pre_update_option_' . ESTANDAR_SHARED_PINECONE_KEY, 'estandar_core_preserve_empty_secret_update', 10, 2 );
add_filter( 'pre_update_option_' . ESTANDAR_SHARED_ELEVENLABS_KEY, 'estandar_core_preserve_empty_secret_update', 10, 2 );
add_filter( 'pre_update_option_' . ESTANDAR_SHARED_RUNWAY_KEY, 'estandar_core_preserve_empty_secret_update', 10, 2 );

if ( ! function_exists( 'estandar_core_preserve_empty_secret_update' ) ) {
	function estandar_core_preserve_empty_secret_update( $new_value, $old_value ) {
		$new_value = estandar_core_sanitize_secret( $new_value );
		return '' === $new_value ? $old_value : $new_value;
	}
}

add_filter( 'estandar_chatbot_openai_api_key', 'estandar_core_filter_chatbot_openai_api_key', 20 );

if ( ! function_exists( 'estandar_core_filter_chatbot_openai_api_key' ) ) {
	function estandar_core_filter_chatbot_openai_api_key( $key ) {
		$key = estandar_core_sanitize_secret( $key );
		return '' !== $key ? $key : estandar_core_get_secret( 'openai' );
	}
}

if ( ! function_exists( 'estandar_core_register_system_settings' ) ) {
	function estandar_core_register_system_settings(): void {
		register_setting(
			'estandar_core_sender_identity_settings',
			ESTANDAR_SENDER_NAME,
			[
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			]
		);
		register_setting(
			'estandar_core_sender_identity_settings',
			ESTANDAR_SENDER_EMAIL,
			[
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_email',
				'default'           => '',
			]
		);
		register_setting(
			'estandar_core_site_identity_settings',
			ESTANDAR_CLIENT_SLUG_OPTION,
			[
				'type'              => 'string',
				'sanitize_callback' => static function ( $value ): string {
					return sanitize_title( remove_accents( (string) $value ) );
				},
				'default'           => '',
			]
		);
		register_setting(
			'estandar_core_site_identity_settings',
			ESTANDAR_CLIENT_NAME_OPTION,
			[
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			]
		);
		register_setting(
			'estandar_core_client_context_settings',
			ESTANDAR_CLIENT_CONTEXT_RECORD_OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => 'estandar_core_sanitize_client_context_record',
				'default'           => [],
			]
		);
		foreach ( estandar_core_shared_secret_options() as $option ) {
			register_setting(
				'estandar_core_shared_credentials_settings',
				$option,
				[
					'type'              => 'string',
					'sanitize_callback' => 'estandar_core_sanitize_secret',
					'default'           => '',
				]
			);
		}
	}
}
add_action( 'admin_init', 'estandar_core_register_system_settings' );

/**
 * ======================================================
 * MENÚ UNIFICADO (source of truth)
 * ======================================================
 */

if ( ! function_exists( 'estandar_admin_menu_register_parent' ) ) {
	function estandar_admin_menu_register_parent(): void {
		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;

		$menu_title = 'Estandar';

		// Minimal indicator in the admin menu (admins only). Cached diagnostics; never blocks or mutates plugins.
		if ( function_exists( 'estandar_core_diag_get_summary' ) && current_user_can( 'manage_options' ) ) {
			$summary = estandar_core_diag_get_summary();
			$status  = isset( $summary['status'] ) ? (string) $summary['status'] : 'OK';
			$count   = isset( $summary['menu_count'] ) ? absint( $summary['menu_count'] ) : 0;

			if ( 'OK' !== $status && $count > 0 ) {
				$menu_title .= ' <span class="update-plugins count-' . $count . '"><span class="update-count">' . $count . '</span></span>';
			} elseif ( 'ERROR' === $status ) {
				$menu_title .= ' <span class="update-plugins count-1"><span class="update-count">!</span></span>';
			}
		}

		add_menu_page(
			'Estandar',
			$menu_title,
			'read',
			ESTANDAR_CORE_MENU_SLUG,
			'estandar_admin_menu_render_hub',
			'none',
			26
		);

	}
}

add_action( 'admin_head', static function (): void {
	echo '<style>#adminmenu .toplevel_page_' . esc_attr( ESTANDAR_CORE_MENU_SLUG ) . ' .wp-menu-image:before{content:"🚀";font-family:Arial,sans-serif;font-size:17px;line-height:20px;}</style>';
} );

if ( ! function_exists( 'estandar_admin_menu_render_hub' ) ) {
	/**
	 * Hub callback — Fase 2A (2026-05-29).
	 * El menú raíz `estandar_main_menu` se mantiene como ancla estructural.
	 * El contenido no se renderiza aquí: la redirección ocurre en admin_init
	 * (antes de que los headers HTTP sean enviados) vía
	 * estandar_core_hub_redirect_on_admin_init().
	 * Este callback es un no-op necesario para que add_menu_page funcione.
	 */
	function estandar_admin_menu_render_hub(): void {}
}

/**
 * Redirige estandar_main_menu → Estado antes de que se envíen headers.
 * admin_init es el punto correcto: WordPress aún no produjo output.
 */
add_action( 'admin_init', function (): void {
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	if ( ESTANDAR_CORE_MENU_SLUG !== $page ) {
		return;
	}
	wp_safe_redirect( admin_url( 'admin.php?page=estandar_core_diagnostics' ) );
	exit;
} );

if ( ! function_exists( 'estandar_core_call_registered_admin_page' ) ) {
	function estandar_core_get_registered_admin_page_callback( string $page_slug ) {
		global $wp_filter;

		$page_slug = sanitize_key( $page_slug );
		if ( '' === $page_slug ) {
			return null;
		}

		$hooks = [
			'toplevel_page_' . $page_slug,
			'tools_page_' . $page_slug,
			'settings_page_' . $page_slug,
			'admin_page_' . $page_slug,
		];

		foreach ( $hooks as $hook ) {
			if ( empty( $wp_filter[ $hook ] ) || ! isset( $wp_filter[ $hook ]->callbacks ) ) {
				continue;
			}

			foreach ( (array) $wp_filter[ $hook ]->callbacks as $callbacks ) {
				foreach ( (array) $callbacks as $callback_data ) {
					$callback = $callback_data['function'] ?? null;
					if ( is_string( $callback ) && 0 === strpos( $callback, 'estandar_core_' ) ) {
						continue;
					}
					if ( ! is_callable( $callback ) ) {
						continue;
					}

					return $callback;
				}
			}
		}

		return null;
	}
}

if ( ! function_exists( 'estandar_core_call_registered_admin_page' ) ) {
	function estandar_core_call_registered_admin_page( string $page_slug ): bool {
		$callback = estandar_core_get_registered_admin_page_callback( $page_slug );
		if ( ! is_callable( $callback ) ) {
			return false;
		}

		call_user_func( $callback );
		return true;
	}
}

if ( ! function_exists( 'estandar_core_call_admin_page_callback' ) ) {
	function estandar_core_call_admin_page_callback( string $page_slug, $callback ): bool {
		static $routing = [];

		$page_slug = sanitize_key( $page_slug );
		if ( '' === $page_slug || ! is_callable( $callback ) ) {
			return false;
		}
		if ( ! empty( $routing[ $page_slug ] ) ) {
			return true;
		}

		$routing[ $page_slug ] = true;
		call_user_func( $callback );
		$routing[ $page_slug ] = false;
		return true;
	}
}

if ( ! function_exists( 'estandar_core_redirect_admin_page_target' ) ) {
	function estandar_core_redirect_admin_page_target( string $target ): bool {
		$target = trim( (string) $target );
		if ( '' === $target || headers_sent() ) {
			return false;
		}

		wp_safe_redirect( admin_url( ltrim( $target, '/' ) ) );
		exit;

		return false;
	}
}

if ( ! function_exists( 'estandar_admin_menu_add_link' ) ) {
	/**
	 * Agrega un ítem bajo el menú Estandar que apunta a una URL admin existente.
	 *
	 * Importante: $target debe ser un string tipo 'admin.php?page=slug' o 'edit.php?...'
	 * para mantener los slugs/rutas existentes sin tocar callbacks ni menús internos.
	 */
	function estandar_admin_menu_add_link( string $page_title, string $menu_title, string $capability, string $target ): void {
		estandar_admin_menu_register_parent();

		$page_slug = '';
		$target_path = wp_parse_url( $target, PHP_URL_PATH );
		$target_query = wp_parse_url( $target, PHP_URL_QUERY );
		if ( in_array( $target_path, [ 'admin.php', 'tools.php' ], true ) && is_string( $target_query ) ) {
			parse_str( $target_query, $query_args );
			$page_slug = isset( $query_args['page'] ) ? sanitize_key( (string) $query_args['page'] ) : '';
		}

		if ( '' === $page_slug ) {
			$link_slug = sanitize_key( 'estandar-link-' . md5( $target ) );
			add_submenu_page(
				ESTANDAR_CORE_MENU_SLUG,
				$page_title,
				$menu_title,
				$capability,
				$link_slug,
				static function () use ( $capability, $target ): void {
					if ( ! current_user_can( $capability ) ) {
						wp_die( 'No autorizado.' );
					}

					$target = trim( (string) $target );
					if ( '' === $target ) {
						echo '<div class="wrap"><h1>' . esc_html__( 'Page not available', 'estandar-core-mu' ) . '</h1></div>';
						return;
					}

					wp_safe_redirect( admin_url( ltrim( $target, '/' ) ) );
					exit;
				}
			);
			return;
		}

		$page_callback = estandar_core_get_registered_admin_page_callback( $page_slug );

		add_submenu_page(
			ESTANDAR_CORE_MENU_SLUG,
			$page_title,
			$menu_title,
			$capability,
			$page_slug,
			static function () use ( $capability, $page_slug, $page_callback, $target ): void {
				if ( ! is_admin() || ! current_user_can( $capability ) ) {
					return;
				}

				if ( estandar_core_call_admin_page_callback( $page_slug, $page_callback ) ) {
					return;
				}

				if ( estandar_core_redirect_admin_page_target( $target ) ) {
					return;
				}

				echo '<div class="wrap"><h1>' . esc_html__( 'Page not available', 'estandar-core-mu' ) . '</h1></div>';
			}
		);
	}
}

/**
 * ======================================================
 * ADMIN UI RUNTIME (layout helpers)
 * ======================================================
 *
 * Contrato visual (transversal):
 * - Estándar definitivo: clases `.estandar-*` dentro de `.estandar-admin`.
 * - Compatibilidad temporal: `.ecb-*` (legado del chatbot) sigue soportado vía alias CSS,
 *   pero debe considerarse DEPRECATED (evitar usarlo en plugins nuevos).
 * - La hoja de estilos del Core está scopeada estrictamente bajo `.estandar-admin` para no
 *   interferir con wp-admin ni con otros plugins/themes/builders.
 */

if ( ! function_exists( 'estandar_render_layout_start' ) ) {
	/**
	 * Start the canonical Estandar admin layout wrapper.
	 *
	 * @param array{class?:string} $args
	 */
	function estandar_render_layout_start( array $args = [] ): void {
		$extra = isset( $args['class'] ) ? (string) $args['class'] : '';
		$class = 'wrap estandar-admin';
		if ( '' !== trim( $extra ) ) {
			$class .= ' ' . trim( $extra );
		}

		echo '<div class="' . esc_attr( $class ) . '">';
	}
}

if ( ! function_exists( 'estandar_render_layout_end' ) ) {
	/**
	 * End the canonical Estandar admin layout wrapper.
	 */
	function estandar_render_layout_end(): void {
		echo '</div>';
	}
}

if ( ! function_exists( 'estandar_render_surface_start' ) ) {
	/**
	 * Start a canonical "surface/card" container inside an Estandar admin layout.
	 *
	 * Uses `.estandar-surface` (new standard). Kept as a helper to avoid reintroducing `.ecb-*`.
	 */
	function estandar_render_surface_start(): void {
		echo '<div class="estandar-surface">';
	}
}

if ( ! function_exists( 'estandar_render_surface_end' ) ) {
	/**
	 * End the canonical surface container.
	 */
	function estandar_render_surface_end(): void {
		echo '</div>';
	}
}

if ( ! function_exists( 'estandar_core_enqueue_admin_ui' ) ) {
	/**
	 * Enqueue the shared Core admin UI when it is already registered.
	 *
	 * @param array<string,mixed> $args Reserved for future runtime options.
	 */
	function estandar_core_enqueue_admin_ui( array $args = [] ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( wp_style_is( 'estandar-admin-ui', 'registered' ) || wp_style_is( 'estandar-admin-ui', 'enqueued' ) ) {
			wp_enqueue_style( 'estandar-admin-ui' );
		}
	}
}

if ( ! function_exists( 'estandar_core_can' ) ) {
	function estandar_core_can( string $capability = 'manage_options' ): bool {
		$capability = '' !== trim( $capability ) ? $capability : 'manage_options';
		return function_exists( 'current_user_can' ) && current_user_can( $capability );
	}
}

if ( ! function_exists( 'estandar_core_storage_type_key' ) ) {
	function estandar_core_storage_type_key( string $type = '' ): string {
		$type = sanitize_key( $type );
		if ( '' === $type ) {
			return '';
		}

		$known = [ 'logs', 'exports', 'temp', 'cache' ];
		return in_array( $type, $known, true ) ? $type : $type;
	}
}

if ( ! function_exists( 'estandar_core_storage_base_path' ) ) {
	function estandar_core_storage_base_path(): string {
		$uploads = function_exists( 'wp_get_upload_dir' ) ? wp_get_upload_dir() : [];
		$basedir = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';
		if ( '' === $basedir ) {
			return '';
		}

		return trailingslashit( $basedir ) . 'estandar-core';
	}
}

if ( ! function_exists( 'estandar_core_storage_path' ) ) {
	function estandar_core_storage_path( string $type = '' ): string {
		$base = estandar_core_storage_base_path();
		if ( '' === $base ) {
			return '';
		}

		$type = estandar_core_storage_type_key( $type );
		return '' === $type ? $base : trailingslashit( $base ) . $type;
	}
}

if ( ! function_exists( 'estandar_core_storage_url' ) ) {
	function estandar_core_storage_url( string $type = '' ): string {
		$uploads = function_exists( 'wp_get_upload_dir' ) ? wp_get_upload_dir() : [];
		$baseurl = isset( $uploads['baseurl'] ) ? (string) $uploads['baseurl'] : '';
		if ( '' === $baseurl ) {
			return '';
		}

		$type = estandar_core_storage_type_key( $type );
		$url  = trailingslashit( $baseurl ) . 'estandar-core';
		return '' === $type ? $url : trailingslashit( $url ) . $type;
	}
}

if ( ! function_exists( 'estandar_core_event' ) ) {
	/**
	 * Emit a passive operational event. Phase 1 does not persist events.
	 *
	 * @param array<string,mixed> $meta
	 */
	function estandar_core_event( string $event_name, array $meta = [] ): void {
		$event_name = strtolower( preg_replace( '/[^a-zA-Z0-9_.:-]/', '', $event_name ) );
		if ( '' === $event_name ) {
			return;
		}

		$payload = [
			'name' => $event_name,
			'meta' => $meta,
			'time' => function_exists( 'time' ) ? time() : 0,
		];
		$payload = (array) apply_filters( 'estandar_core_event_payload', $payload, $event_name, $meta );

		do_action( 'estandar_core_event', $event_name, $meta, $payload );
	}
}

if ( ! function_exists( 'estandar_core_render_section' ) ) {
	/**
	 * Render a passive shared admin section wrapper.
	 *
	 * @param array{title?:string,description?:string,content?:string,class?:string} $args
	 */
	function estandar_core_render_section( array $args = [] ): void {
		$class = 'estandar-section';
		if ( ! empty( $args['class'] ) ) {
			$class .= ' ' . sanitize_html_class( (string) $args['class'] );
		}

		echo '<section class="' . esc_attr( $class ) . '">';
		if ( ! empty( $args['title'] ) ) {
			echo '<h2>' . esc_html( (string) $args['title'] ) . '</h2>';
		}
		if ( ! empty( $args['description'] ) ) {
			echo '<p class="description">' . esc_html( (string) $args['description'] ) . '</p>';
		}
		if ( isset( $args['content'] ) ) {
			echo wp_kses_post( (string) $args['content'] );
		}
		echo '</section>';
	}
}

if ( ! function_exists( 'estandar_core_render_card' ) ) {
	/**
	 * Render a passive shared admin card.
	 *
	 * @param array{title?:string,description?:string,content?:string,class?:string} $args
	 */
	function estandar_core_render_card( array $args = [] ): void {
		$class = 'estandar-card';
		if ( ! empty( $args['class'] ) ) {
			$class .= ' ' . sanitize_html_class( (string) $args['class'] );
		}

		echo '<div class="' . esc_attr( $class ) . '">';
		if ( ! empty( $args['title'] ) ) {
			echo '<h3>' . esc_html( (string) $args['title'] ) . '</h3>';
		}
		if ( ! empty( $args['description'] ) ) {
			echo '<p class="description">' . esc_html( (string) $args['description'] ) . '</p>';
		}
		if ( isset( $args['content'] ) ) {
			echo wp_kses_post( (string) $args['content'] );
		}
		echo '</div>';
	}
}

if ( ! function_exists( 'estandar_core_render_badge' ) ) {
	function estandar_core_render_badge( string $label, string $type = 'neutral' ): void {
		$type = sanitize_key( $type );
		if ( '' === $type ) {
			$type = 'neutral';
		}

		echo '<span class="estandar-badge estandar-badge--' . esc_attr( $type ) . '">' . esc_html( $label ) . '</span>';
	}
}

if ( ! function_exists( 'estandar_core_diagnostics_contracts' ) ) {
	/**
	 * Passive diagnostics contract registry. Evaluation remains unchanged.
	 *
	 * @return array<int|string,mixed>
	 */
	function estandar_core_diagnostics_contracts(): array {
		return (array) apply_filters( 'estandar_core_diagnostics_contracts', [] );
	}
}

if ( ! function_exists( 'estandar_render_tabs' ) ) {
	/**
	 * Render canonical Estandar admin tabs.
	 *
	 * @param array<string,array{label:string,url:string}> $tabs
	 */
	function estandar_render_tabs( array $tabs, string $active ): void {
		if ( empty( $tabs ) ) {
			return;
		}

		echo '<nav class="nav-tab-wrapper estandar-tabs estandar-module-nav" aria-label="' . esc_attr__( 'Secciones', 'estandar-core-mu' ) . '">';
		foreach ( $tabs as $key => $tab ) {
			$key   = sanitize_key( (string) $key );
			$label = isset( $tab['label'] ) ? (string) $tab['label'] : $key;
			$url   = isset( $tab['url'] ) ? (string) $tab['url'] : '';
			$class = 'nav-tab';
			if ( $key === $active ) {
				$class .= ' nav-tab-active';
			}

			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
	}
}

if ( ! function_exists( 'estandar_core_render_subtabs' ) ) {
	/**
	 * Subtabs canónicos para header_slot: resuelve un grupo del schema de
	 * navegación y lo renderiza con estandar_render_tabs. Implementación única
	 * compartida por Proyectos, Dashboard y Leads.
	 */
	function estandar_core_render_subtabs( string $group, string $active ): void {
		if ( ! function_exists( 'estandar_render_tabs' ) || ! function_exists( 'estandar_core_navigation_tabs' ) ) {
			return;
		}

		$items = array();
		foreach ( estandar_core_navigation_tabs( $group ) as $slug => $tab ) {
			$items[ $slug ] = array(
				'label' => (string) ( $tab['label'] ?? $slug ),
				'url'   => (string) ( $tab['url'] ?? '#' ),
			);
		}

		estandar_render_tabs( $items, $active );
	}
}

if ( ! function_exists( 'estandar_render_brand_bar' ) ) {
	function estandar_render_brand_bar( array $args = [] ): void {
		$brand_label    = isset( $args['brand_label'] ) ? (string) $args['brand_label'] : 'Estandar Studio';
		$brand_logo_url = isset( $args['brand_logo_url'] ) ? (string) $args['brand_logo_url'] : 'https://www.estandar.cl/wp-content/uploads/2024/07/estandar_logo_color-768x227.png';
		$brand_link_url = isset( $args['brand_link_url'] ) ? (string) $args['brand_link_url'] : 'https://www.estandar.cl';
		// HEADER_COMPACT_V1 — título de sección dentro de la barra naranja: [Logo] | [Bar Title].
		$bar_title = isset( $args['bar_title'] ) ? trim( (string) $args['bar_title'] ) : '';

		echo '<div class="estandar-utility-bar' . ( '' !== $bar_title ? ' estandar-utility-bar--titled' : '' ) . '">';
		echo '<a class="estandar-utility-bar__brand" href="' . esc_url( $brand_link_url ) . '" target="_blank" rel="noopener noreferrer">';
		echo '<img src="' . esc_url( $brand_logo_url ) . '" alt="' . esc_attr( $brand_label ) . '" />';
		echo '</a>';
		if ( '' !== $bar_title ) {
			echo '<span class="estandar-utility-bar__title" title="' . esc_attr( $bar_title ) . '">' . esc_html( $bar_title ) . '</span>';
		} else {
			echo '<div class="estandar-utility-bar__slot" aria-hidden="true"></div>';
		}
		echo '</div>';
	}
}

if ( ! function_exists( 'estandar_render_header' ) ) {
	/**
	 * Render the canonical Estandar admin header (based on estandar-chatbot).
	 *
	 * @param array{
	 *  title:string,
	 *  subtitle?:string,
	 *  metrics?:array<int,array{label:string,value:string|int,float?:bool}>,
	 *  brand_label?:string,
	 *  brand_logo_url?:string,
	 *  brand_link_url?:string
	 * } $args
	 */
	function estandar_render_header( array $args ): void {
		$title    = isset( $args['title'] ) ? (string) $args['title'] : '';
		$subtitle = isset( $args['subtitle'] ) ? (string) $args['subtitle'] : '';
		$metrics  = isset( $args['metrics'] ) && is_array( $args['metrics'] ) ? $args['metrics'] : [];
		// HEADER_COMPACT_V1 — cuando el título vive en la barra naranja, se elimina la
		// fila de título dedicada (estandar-topbar). Solo aplica al shell Core (que pasa
		// 'title_in_bar' => true). Los llamadores directos conservan el comportamiento previo.
		$title_in_bar  = ! empty( $args['title_in_bar'] );
		$show_identity = ! $title_in_bar;

		echo '<div class="estandar-shell">';
		estandar_render_brand_bar( $args );

		// La fila estandar-topbar solo se emite si hay título dedicado o métricas.
		if ( $show_identity || ! empty( $metrics ) ) {
			echo '<div class="estandar-topbar">';
			if ( $show_identity ) {
				echo '<div class="estandar-topbar__identity">';
				echo '<h1 class="estandar-title">' . esc_html( $title ) . '</h1>';
				if ( '' !== trim( $subtitle ) ) {
					echo '<p class="estandar-subtitle">' . esc_html( $subtitle ) . '</p>';
				}
				echo '</div>';
			}

			if ( ! empty( $metrics ) ) {
				echo '<div class="estandar-topbar__metrics">';
				foreach ( $metrics as $m ) {
					$label = isset( $m['label'] ) ? (string) $m['label'] : '';
					$value = isset( $m['value'] ) ? $m['value'] : '';
					echo '<div class="estandar-metric">';
					echo '<span class="estandar-metric__label">' . esc_html( $label ) . '</span>';
					echo '<strong class="estandar-metric__value">' . esc_html( (string) $value ) . '</strong>';
					echo '</div>';
				}
				echo '</div>';
			} elseif ( $show_identity ) {
				// Keep grid spacing stable when no metrics are provided.
				echo '<div></div>';
			}

			echo '</div>';
		}

		echo '</div>';
	}
}

if ( ! function_exists( 'estandar_core_output_has_admin_shell' ) ) {
	function estandar_core_output_has_admin_shell( string $output ): bool {
		return (bool) preg_match( '/class=(["\'])(?:(?!\1).)*\b(?:estandar-shell|estandar-topbar|ecb-admin-shell|ecb-admin-topbar)\b(?:(?!\1).)*\1/i', $output );
	}
}

if ( ! function_exists( 'estandar_core_is_rendering_admin_shell' ) ) {
	function estandar_core_is_rendering_admin_shell(): bool {
		return ! empty( $GLOBALS['estandar_core_admin_shell_rendering'] );
	}
}

if ( ! function_exists( 'estandar_core_is_embedded_admin' ) ) {
	function estandar_core_is_embedded_admin(): bool {
		return function_exists( 'estandar_core_is_rendering_admin_shell' ) && estandar_core_is_rendering_admin_shell();
	}
}

if ( ! function_exists( 'estandar_core_normalize_admin_mode' ) ) {
	function estandar_core_normalize_admin_mode( $mode ): string {
		$mode = is_scalar( $mode ) ? sanitize_key( (string) $mode ) : '';
		$allowed = [
			ESTANDAR_ADMIN_MODE_LEGACY,
			ESTANDAR_ADMIN_MODE_COMPAT,
			ESTANDAR_ADMIN_MODE_NATIVE,
		];

		return in_array( $mode, $allowed, true ) ? $mode : ESTANDAR_ADMIN_MODE_LEGACY;
	}
}

if ( ! function_exists( 'estandar_core_get_admin_render_mode' ) ) {
	function estandar_core_get_admin_render_mode(): string {
		$mode = apply_filters( 'estandar_core_admin_render_mode', ESTANDAR_ADMIN_MODE_LEGACY );
		return estandar_core_normalize_admin_mode( $mode );
	}
}

if ( ! function_exists( 'estandar_core_admin_mode_supports_shell' ) ) {
	function estandar_core_admin_mode_supports_shell( $mode ): bool {
		$mode = estandar_core_normalize_admin_mode( $mode );
		return in_array( $mode, [ ESTANDAR_ADMIN_MODE_COMPAT, ESTANDAR_ADMIN_MODE_NATIVE ], true );
	}
}

if ( ! function_exists( 'estandar_core_admin_mode_supports_plugin_branding' ) ) {
	function estandar_core_admin_mode_supports_plugin_branding( $mode ): bool {
		$mode = estandar_core_normalize_admin_mode( $mode );
		return in_array( $mode, [ ESTANDAR_ADMIN_MODE_LEGACY, ESTANDAR_ADMIN_MODE_COMPAT ], true );
	}
}

if ( ! function_exists( 'estandar_core_render_plugin_branding' ) ) {
	/**
	 * Render minimal standalone Estandar branding for plugins outside the Core shell.
	 *
	 * @param array{title?:string,subtitle?:string,logo_url?:string,class?:string} $args
	 */
	function estandar_core_render_plugin_branding( array $args = [] ): void {
		$title = isset( $args['title'] ) ? (string) $args['title'] : 'Estandar';
		$subtitle = isset( $args['subtitle'] ) ? (string) $args['subtitle'] : '';
		$logo_url = isset( $args['logo_url'] ) ? (string) $args['logo_url'] : 'https://www.estandar.cl/wp-content/uploads/2024/07/estandar_logo_color-768x227.png';
		$class = 'estandar-plugin-branding';
		if ( ! empty( $args['class'] ) ) {
			$class .= ' ' . sanitize_html_class( (string) $args['class'] );
		}

		echo '<div class="' . esc_attr( $class ) . '">';
		echo '<div class="estandar-plugin-branding__identity">';
		if ( '' !== trim( $logo_url ) ) {
			echo '<img class="estandar-plugin-branding__logo" src="' . esc_url( $logo_url ) . '" alt="' . esc_attr__( 'Estandar', 'estandar-core-mu' ) . '" />';
		}
		echo '<div>';
		echo '<h1 class="estandar-plugin-branding__title">' . esc_html( $title ) . '</h1>';
		if ( '' !== trim( $subtitle ) ) {
			echo '<p class="estandar-plugin-branding__subtitle">' . esc_html( $subtitle ) . '</p>';
		}
		echo '</div>';
		echo '</div>';
		echo '</div>';
	}
}

if ( ! function_exists( 'estandar_core_render_logo' ) ) {
	/**
	 * Render the official Estandar logo as a reusable admin UI element.
	 *
	 * @param array{logo_url?:string,href?:string,alt?:string,class?:string} $args
	 */
	function estandar_core_render_logo( array $args = [] ): void {
		$logo_url = isset( $args['logo_url'] ) ? (string) $args['logo_url'] : 'https://www.estandar.cl/wp-content/uploads/2024/07/estandar_logo_color-768x227.png';
		$href     = isset( $args['href'] ) ? (string) $args['href'] : '';
		$alt      = isset( $args['alt'] ) ? (string) $args['alt'] : __( 'Estandar Studio', 'estandar-core-mu' );
		$extra_class = '';

		if ( ! empty( $args['class'] ) ) {
			$extra_class = sanitize_html_class( (string) $args['class'] );
		}

		$link_class = 'estandar-core-logo__link';
		$img_class  = 'estandar-core-logo__img';
		if ( '' !== $extra_class ) {
			$link_class .= ' ' . $extra_class . '__link';
			$img_class  .= ' ' . $extra_class . '__img';
		}

		if ( '' !== trim( $href ) ) {
			echo '<a class="' . esc_attr( $link_class ) . '" href="' . esc_url( $href ) . '" target="_blank" rel="noopener noreferrer">';
		}

		echo '<img class="' . esc_attr( $img_class ) . '" src="' . esc_url( $logo_url ) . '" alt="' . esc_attr( $alt ) . '" />';

		if ( '' !== trim( $href ) ) {
			echo '</a>';
		}
	}
}

if ( ! function_exists( 'estandar_render_breadcrumb' ) ) {
	/**
	 * Renderiza un breadcrumb de navegación.
	 *
	 * @param string[] $items  Ej: ['Analytics', 'Funnel'] — el último es el activo.
	 */
	function estandar_render_breadcrumb( array $items ): void {
		if ( empty( $items ) ) {
			return;
		}
		echo '<nav class="estandar-breadcrumb" aria-label="' . esc_attr__( 'Ruta de navegación', 'estandar-core-mu' ) . '">';
		$last = count( $items ) - 1;
		foreach ( $items as $i => $item ) {
			if ( $i === $last ) {
				echo '<span class="estandar-breadcrumb__current">' . esc_html( (string) $item ) . '</span>';
			} else {
				echo '<span class="estandar-breadcrumb__item">' . esc_html( (string) $item ) . '</span>';
				echo '<span class="estandar-breadcrumb__sep" aria-hidden="true">›</span>';
			}
		}
		echo '</nav>';
	}
}

if ( ! function_exists( 'estandar_render_navigation' ) ) {
	function estandar_core_navigation_schema(): array {
		$schema = [
			'top_nav' => [
				'panel' => [ 'label' => 'Dashboard', 'bar_title' => 'Estandar Primer · Dashboard', 'url' => admin_url( 'admin.php?page=estandar_core_diagnostics' ), 'capability' => 'read', 'order' => 10 ],
				'forms' => [ 'label' => 'Forms', 'bar_title' => 'Formularios', 'url' => admin_url( 'admin.php?page=estandar-forms' ), 'capability' => 'manage_options', 'order' => 20 ],
				'estandar-chatbot' => [ 'label' => 'Chatbot', 'bar_title' => 'Estandar Chatbot', 'url' => admin_url( 'admin.php?page=estandar-chatbot' ), 'capability' => 'read', 'order' => 30 ],
				'estandar_projects' => [ 'label' => 'Proyectos', 'bar_title' => 'Proyectos', 'url' => admin_url( 'admin.php?page=estandar_projects' ), 'capability' => 'manage_options', 'order' => 40 ],
				'leads' => [ 'label' => 'Leads', 'url' => admin_url( 'admin.php?page=estandar-leads' ), 'capability' => 'manage_options', 'order' => 50 ],
				'estandar-destinations' => [ 'label' => 'Destinations', 'bar_title' => 'Estandar Destinations', 'url' => admin_url( 'admin.php?page=estandar-destinations' ), 'capability' => 'manage_options', 'order' => 60 ],
				'analytics' => [ 'label' => 'Analytics', 'url' => admin_url( 'admin.php?page=ea-funnel' ), 'capability' => 'manage_options', 'order' => 70 ],
			],
			'tabs' => [
				'dashboard_tabs' => [
					'configuracion' => [ 'label' => 'Configuración compartida', 'url' => admin_url( 'admin.php?page=estandar_core_diagnostics&tab=configuracion' ), 'capability' => 'manage_options', 'order' => 10 ],
					'comunicaciones' => [ 'label' => 'Comunicaciones', 'url' => admin_url( 'admin.php?page=estandar_communications' ), 'capability' => 'manage_options', 'order' => 20 ],
					'estado_ecosistema' => [ 'label' => 'Estado del ecosistema', 'url' => admin_url( 'admin.php?page=estandar_core_diagnostics&tab=estado_ecosistema' ), 'capability' => 'manage_options', 'order' => 30 ],
				],
				'estandar_projects' => [
					'estandar_projects' => [ 'label' => 'Proyectos', 'url' => admin_url( 'admin.php?page=estandar_projects' ), 'capability' => 'manage_options', 'order' => 10 ],
					'estandar_projects_integrations' => [ 'label' => 'Integraciones', 'url' => admin_url( 'admin.php?page=estandar_projects_integrations' ), 'capability' => 'manage_options', 'order' => 15 ],
					'estandar_campaigns' => [ 'label' => 'Campaign Discovery', 'url' => admin_url( 'admin.php?page=estandar_campaigns' ), 'capability' => 'manage_options', 'order' => 20 ],
					'estandar_projects_operators' => [ 'label' => 'Operadores', 'url' => admin_url( 'admin.php?page=estandar_projects_operators' ), 'capability' => 'manage_options', 'order' => 30 ],
				],
				'leads' => [
					'estandar-leads' => [ 'label' => 'Leads', 'url' => admin_url( 'admin.php?page=estandar-leads' ), 'capability' => 'manage_options', 'order' => 10 ],
					'estandar-leads-reports' => [ 'label' => 'Operaciones', 'url' => admin_url( 'admin.php?page=estandar-leads-reports' ), 'capability' => 'manage_options', 'order' => 20 ],
					'estandar-leads-settings' => [ 'label' => 'Configuración', 'url' => admin_url( 'admin.php?page=estandar-leads-settings' ), 'capability' => 'manage_options', 'order' => 30 ],
					'estandar-leads-autoresponse' => [ 'label' => 'Autorespuesta', 'url' => admin_url( 'admin.php?page=estandar-leads-autoresponse' ), 'capability' => 'manage_options', 'order' => 40 ],
					'estandar-leads-exports' => [ 'label' => 'Exportaciones', 'url' => admin_url( 'admin.php?page=estandar-leads-exports' ), 'capability' => 'manage_options', 'order' => 50 ],
					'estandar-customers' => [ 'label' => 'Clientes', 'url' => admin_url( 'admin.php?page=estandar-customers' ), 'capability' => 'manage_options', 'order' => 60 ],
				],
			],
			'pages' => [
				'estandar_core_diagnostics' => [ 'title' => 'Estandar Primer · Estado', 'menu_title' => 'Dashboard', 'visible' => true, 'shell' => true, 'bar_title' => 'Estandar Primer · Dashboard', 'module' => 'panel' ],
				'estandar_communications' => [ 'title' => 'Comunicaciones · Estandar', 'menu_title' => 'Comunicaciones', 'visible' => false, 'shell' => true, 'module' => 'configuracion' ],
				// visible=false: no son plugins instalados, son módulos de Primer/Leads.
				// Siguen accesibles vía el nav principal (top_nav, sin cambios) y sus
				// propias sub-tabs — solo se retiran del listado plano del sidebar de WP
				// y del dropdown de la Admin Bar.
				'estandar_projects' => [ 'title' => 'Proyectos · Estandar', 'menu_title' => 'Proyectos', 'visible' => false, 'shell' => true, 'module' => 'estandar_projects' ],
				'estandar_projects_operators' => [ 'title' => 'Operadores · Estandar', 'menu_title' => 'Operadores', 'visible' => false, 'shell' => true, 'module' => 'estandar_projects' ],
				'estandar_projects_integrations' => [ 'title' => 'Integraciones · Estandar', 'menu_title' => 'Integraciones', 'visible' => false, 'shell' => true, 'module' => 'estandar_projects' ],
				'estandar-leads' => [ 'title' => 'Estandar Leads', 'menu_title' => 'Leads', 'visible' => true, 'shell' => true, 'module' => 'leads' ],
				'estandar-leads-reports' => [ 'title' => 'Operaciones', 'menu_title' => 'Operaciones', 'visible' => false, 'shell' => true, 'module' => 'leads' ],
				'estandar-leads-settings' => [ 'title' => 'Configuración', 'menu_title' => 'Configuración', 'visible' => false, 'shell' => true, 'module' => 'leads' ],
				'estandar-leads-email' => [ 'title' => 'Email', 'menu_title' => 'Email', 'visible' => false, 'shell' => true, 'module' => 'leads' ],
				'estandar-leads-autoresponse' => [ 'title' => 'Autorespuesta', 'menu_title' => 'Autorespuesta', 'visible' => false, 'shell' => true, 'module' => 'leads' ],
				'estandar-leads-exports' => [ 'title' => 'Exportaciones', 'menu_title' => 'Exportaciones', 'visible' => false, 'shell' => true, 'module' => 'leads' ],
				'estandar-leads-portal' => [ 'title' => 'Portal', 'menu_title' => 'Portal', 'visible' => false, 'shell' => true, 'module' => 'leads' ],
				'estandar-forms' => [ 'title' => 'Estandar Forms', 'menu_title' => 'Forms', 'visible' => true, 'shell' => true, 'module' => 'forms' ],
				'estandar-forms-forms' => [ 'title' => 'Estandar Forms · Formularios', 'menu_title' => 'Formularios', 'visible' => false, 'shell' => true, 'module' => 'forms' ],
				'estandar-forms-entries' => [ 'title' => 'Estandar Forms · Entradas', 'menu_title' => 'Entradas', 'visible' => false, 'shell' => true, 'module' => 'forms' ],
				'estandar-surveys' => [ 'title' => 'Surveys', 'menu_title' => 'Surveys', 'visible' => true, 'shell' => true, 'module' => 'forms' ],
				'estandar-surveys-edit' => [ 'title' => 'Nueva Encuesta', 'menu_title' => 'Nueva Encuesta', 'visible' => false, 'shell' => true, 'module' => 'forms' ],
				'estandar-surveys-results' => [ 'title' => 'Resultados Survey', 'menu_title' => 'Resultados Survey', 'visible' => false, 'shell' => true, 'module' => 'forms' ],
				'estandar-surveys-analytics' => [ 'title' => 'Analytics Survey', 'menu_title' => 'Analytics Survey', 'visible' => false, 'shell' => true, 'module' => 'forms' ],
				'estandar-destinations' => [ 'title' => 'Destinations', 'menu_title' => 'Destinations', 'visible' => true, 'shell' => true, 'module' => 'estandar-destinations' ],
				'ea-funnel' => [ 'title' => 'Analytics', 'menu_title' => 'Analytics', 'visible' => true, 'shell' => true, 'module' => 'analytics' ],
				'estandar_campaigns' => [ 'title' => 'Campañas · Estandar', 'menu_title' => 'Campañas', 'visible' => false, 'shell' => true, 'module' => 'estandar_projects' ],
				'estandar-customers' => [ 'title' => 'Clientes', 'menu_title' => 'Clientes', 'visible' => false, 'shell' => true, 'module' => 'leads' ],
				'estandar_core_plugins' => [ 'title' => 'Plugins', 'menu_title' => 'Plugins', 'visible' => false, 'shell' => true, 'module' => 'sistema' ],
				'estandar_core_client_context' => [ 'title' => 'Client Context', 'menu_title' => 'Client Context', 'visible' => false, 'shell' => true, 'module' => 'sistema' ],
				'estandar_core_cloud' => [ 'title' => 'Estandar Cloud', 'menu_title' => 'Cloud', 'visible' => false, 'shell' => true, 'module' => 'sistema' ],
			],
		];

		return (array) apply_filters( 'estandar_core_navigation_schema', $schema );
	}

	function estandar_core_navigation_page_spec( string $slug ): array {
		$schema = estandar_core_navigation_schema();
		return (array) ( $schema['pages'][ $slug ] ?? array() );
	}

	function estandar_core_navigation_tabs( string $group ): array {
		$schema = estandar_core_navigation_schema();
		$tabs = (array) ( $schema['tabs'][ $group ] ?? array() );
		uasort( $tabs, static function ( array $a, array $b ): int {
			return (int) ( $a['order'] ?? 0 ) <=> (int) ( $b['order'] ?? 0 );
		} );
		return $tabs;
	}

	function estandar_core_current_admin_page(): string {
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
	}

	function estandar_core_global_nav_is_active( string $module, string $page, string $tab ): bool {
		if ( 'panel' === $module ) {
			return ( in_array( $page, [ 'estandar_core_diagnostics', ESTANDAR_CORE_MENU_SLUG ], true ) && ( '' === $tab || 'panel' === $tab ) );
		}
		if ( 'analytics' === $module ) {
			return 'estandar_campaigns' === $page || 0 === strpos( $page, 'ea-' );
		}
		if ( 'forms' === $module ) {
			return 0 === strpos( $page, 'estandar-forms' );
		}
		if ( 'estandar_projects' === $module ) {
			return in_array( $page, array( 'estandar_projects', 'estandar_projects_operators', 'estandar_projects_integrations', 'estandar_campaigns' ), true );
		}
		if ( 'leads' === $module ) {
			return 0 === strpos( $page, 'estandar-leads' );
		}
		if ( 'configuracion' === $module ) {
			return ( 'estandar_core_diagnostics' === $page && in_array( $tab, [ 'configuracion', 'estado_ecosistema' ], true ) )
				|| 'estandar_communications' === $page;
		}
		if ( 'configuracion_compartida' === $module ) {
			return 'estandar_core_diagnostics' === $page && 'configuracion' === $tab;
		}
		if ( 'estado_ecosistema' === $module ) {
			return 'estandar_core_diagnostics' === $page && 'estado_ecosistema' === $tab;
		}
		return $module === $page;
	}

	function estandar_core_global_navigation_items(): array {
		$page = estandar_core_current_admin_page();
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : '';
		$items = estandar_core_navigation_schema()['top_nav'];

		$resolve = static function ( array $item ) use ( $page, $tab, &$resolve ): array {
			$item['active'] = estandar_core_global_nav_is_active( (string) ( $item['module'] ?? '' ), $page, $tab );
			if ( ! empty( $item['children'] ) && is_array( $item['children'] ) ) {
				$item['children'] = array_values( array_filter( array_map( $resolve, $item['children'] ), static function ( array $c ): bool {
					return current_user_can( (string) ( $c['capability'] ?? 'manage_options' ) );
				} ) );
			}
			return $item;
		};
		return array_values( array_filter( array_map( $resolve, $items ), static function ( array $item ): bool {
			return current_user_can( (string) ( $item['capability'] ?? 'manage_options' ) );
		} ) );
	}

	/**
	 * HEADER_COMPACT_V1 — Título de la sección activa para la barra naranja.
	 * Misma fuente que la navegación (estandar_core_global_navigation_items()).
	 * Fallback: si la sección activa no define `bar_title`, usa su `label` (nav_label).
	 * Si ninguna sección está activa, retorna '' y el shell cae al título de página.
	 */
	function estandar_core_global_nav_active_bar_title(): string {
		$page = estandar_core_current_admin_page();
		foreach ( estandar_core_global_navigation_items() as $item ) {
			if ( ! empty( $item['active'] ) ) {
				$bar = isset( $item['bar_title'] ) ? trim( (string) $item['bar_title'] ) : '';
				if ( '' !== $bar ) {
					return $bar;
				}
				return (string) ( $item['label'] ?? '' ); // fallback bar_title = nav_label
			}
		}
		$schema = estandar_core_navigation_schema();
		if ( isset( $schema['pages'][ $page ]['bar_title'] ) ) {
			return (string) $schema['pages'][ $page ]['bar_title'];
		}
		return '';
	}

	function estandar_render_global_navigation(): void {
		$items = estandar_core_global_navigation_items();
		if ( empty( $items ) ) {
			return;
			}
			echo '<div class="estandar-platform-nav-wrap">';
			echo '<div class="estandar-platform-nav-title">Estandar</div>';
			echo '<nav class="estandar-platform-nav" aria-label="' . esc_attr__( 'Navegación de plataforma', 'estandar-core-mu' ) . '">';
		foreach ( $items as $item ) {
			$class = 'estandar-platform-nav__item';
			if ( ! empty( $item['active'] ) ) {
				$class .= ' is-active';
			}
			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( (string) ( $item['url'] ?? '#' ) ) . '">' . esc_html( (string) ( $item['label'] ?? '' ) ) . '</a>';
			if ( ! empty( $item['children'] ) && is_array( $item['children'] ) ) {
				foreach ( $item['children'] as $child ) {
					$child_class = 'estandar-platform-nav__subitem';
					if ( ! empty( $child['active'] ) ) {
						$child_class .= ' is-active';
					}
					echo '<a class="' . esc_attr( $child_class ) . '" href="' . esc_url( (string) ( $child['url'] ?? '#' ) ) . '">' . esc_html( (string) ( $child['label'] ?? '' ) ) . '</a>';
				}
			}
		}
		echo '</nav>';
		echo '</div>';
	}

	/**
	 * Renderiza la navegación primaria del shell (tabs o equivalente futuro).
	 * Core decide la presentación; los plugins solo entregan items.
	 *
	 * @param array{items: array<array{slug:string, label:string, url:string, active:bool}>} $navigation
	 */
	function estandar_render_navigation( array $navigation ): void {
		$items = $navigation['items'] ?? [];
		if ( empty( $items ) ) {
			return;
		}
		echo '<nav class="estandar-shell-nav estandar-module-nav nav-tab-wrapper" aria-label="' . esc_attr__( 'Navegación', 'estandar-core-mu' ) . '">';
		foreach ( $items as $item ) {
			$active = ! empty( $item['active'] ) ? ' nav-tab-active' : '';
			$slug   = isset( $item['slug'] ) ? ' data-slug="' . esc_attr( $item['slug'] ) . '"' : '';
			echo '<a class="nav-tab' . esc_attr( $active ) . '" href="' . esc_url( $item['url'] ?? '#' ) . '"' . $slug . '>'
				. esc_html( $item['label'] ?? '' )
				. '</a>';
		}
		echo '</nav>';
	}
}

if ( ! function_exists( 'estandar_render_toolbar' ) ) {
	/**
	 * Renderiza la barra de herramientas: filtros a la izquierda, acciones a la derecha.
	 *
	 * @param array{
	 *   left?:  array,
	 *   right?:  array,
	 *   filters?: array,
	 *   actions?: array
	 * } $toolbar
	 */
	function estandar_render_toolbar( array $toolbar ): void {
		// Compatibilidad: 'filters'/'actions' mapean a 'left'/'right'.
		$left  = $toolbar['left']    ?? $toolbar['filters'] ?? [];
		$right = $toolbar['right']   ?? $toolbar['actions'] ?? [];

		if ( empty( $left ) && empty( $right ) ) {
			return;
		}

		echo '<div class="estandar-toolbar">';

		if ( ! empty( $left ) ) {
			echo '<div class="estandar-toolbar__left">';
			foreach ( $left as $filter ) {
				$type    = $filter['type'] ?? 'select';
				$name    = esc_attr( $filter['name']    ?? '' );
				$label   = esc_html( $filter['label']   ?? '' );
				$current = $filter['current'] ?? '';

				if ( 'select' === $type ) {
					echo '<label class="estandar-toolbar__filter">';
					echo '<span class="screen-reader-text">' . $label . '</span>';
					echo '<select name="' . $name . '" class="estandar-toolbar__select">';
					echo '<option value="">' . $label . ': ' . esc_html__( 'Todos', 'estandar-core-mu' ) . '</option>';
					foreach ( (array) ( $filter['options'] ?? [] ) as $opt ) {
						$val   = is_array( $opt ) ? ( $opt['value'] ?? $opt['slug'] ?? '' ) : (string) $opt;
						$lbl   = is_array( $opt ) ? ( $opt['label'] ?? $opt['name']  ?? $val ) : (string) $opt;
						echo '<option value="' . esc_attr( $val ) . '"' . selected( $current, $val, false ) . '>' . esc_html( $lbl ) . '</option>';
					}
					echo '</select>';
					echo '</label>';
				} elseif ( 'date' === $type ) {
					echo '<label class="estandar-toolbar__filter">';
					echo '<span>' . $label . '</span>';
					echo '<input type="date" name="' . $name . '" value="' . esc_attr( (string) $current ) . '" class="estandar-toolbar__date">';
					echo '</label>';
				}
			}
			echo '</div>';
		}

		if ( ! empty( $right ) ) {
			echo '<div class="estandar-toolbar__right">';
			foreach ( $right as $action ) {
				$style = $action['style'] ?? 'secondary';
				$cls   = 'button' . ( 'primary' === $style ? ' button-primary' : '' );
				if ( ! empty( $action['url'] ) ) {
					echo '<a href="' . esc_url( $action['url'] ) . '" class="' . esc_attr( $cls ) . '">' . esc_html( $action['label'] ?? '' ) . '</a>';
				}
			}
			echo '</div>';
		}

		echo '</div>';
	}
}

if ( ! function_exists( 'estandar_core_prepare_admin_shell_content' ) ) {
	function estandar_core_prepare_admin_shell_content( string $output ): string {
		$trimmed = trim( $output );
		if ( preg_match( '/^<div\s+class=(["\'])(?=[^>]*\bwrap\b)(?=[^>]*\bestandar-admin\b)[^>]*>\s*(.*)\s*<\/div>\s*$/is', $trimmed, $m ) ) {
			return (string) $m[2];
		}

		return $output;
	}
}

if ( ! function_exists( 'estandar_core_render_admin_shell' ) ) {
	/**
	 * Render a shared Estandar admin shell around content-only plugin callbacks.
	 *
	 * @param array{
	 *  title:string,
	 *  subtitle?:string,
	 *  width?:string,
	 *  class?:string,
	 *  callback?:callable,
	 *  content?:string
	 * } $args
	 */
	function estandar_core_render_admin_shell( array $args ): void {
		$title    = isset( $args['title'] ) ? (string) $args['title'] : 'Estandar';
		$subtitle = isset( $args['subtitle'] ) ? (string) $args['subtitle'] : '';
		$width    = isset( $args['width'] ) ? sanitize_key( (string) $args['width'] ) : 'fluid';
		if ( ! in_array( $width, [ 'fluid', 'wide', 'constrained' ], true ) ) {
			$width = 'fluid';
		}

		$output = isset( $args['content'] ) ? (string) $args['content'] : '';
		if ( isset( $args['callback'] ) && is_callable( $args['callback'] ) ) {
			ob_start();
			$previous_shell_state = ! empty( $GLOBALS['estandar_core_admin_shell_rendering'] );
			$GLOBALS['estandar_core_admin_shell_rendering'] = true;
			try {
				call_user_func( $args['callback'] );
			} finally {
				if ( $previous_shell_state ) {
					$GLOBALS['estandar_core_admin_shell_rendering'] = true;
				} else {
					unset( $GLOBALS['estandar_core_admin_shell_rendering'] );
				}
			}
			$output = (string) ob_get_clean();
		}

		if ( estandar_core_output_has_admin_shell( $output ) ) {
			echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}
		$output = estandar_core_prepare_admin_shell_content( $output );

		$extra_class = 'estandar-admin--' . $width;
		if ( ! empty( $args['class'] ) ) {
			$extra_class .= ' ' . sanitize_html_class( (string) $args['class'] );
		}

		// V2 args — ignorados silenciosamente si no están presentes (compat V1).
		$breadcrumb  = isset( $args['breadcrumb'] )  && is_array( $args['breadcrumb'] )  ? $args['breadcrumb']  : [];
		$navigation  = isset( $args['navigation'] )  && is_array( $args['navigation'] )  ? $args['navigation']  : [];
		$toolbar     = isset( $args['toolbar'] )     && is_array( $args['toolbar'] )     ? $args['toolbar']     : [];
		$header_slot = isset( $args['header_slot'] ) && is_callable( $args['header_slot'] ) ? $args['header_slot'] : null;
		$footer_slot = isset( $args['footer_slot'] ) && is_callable( $args['footer_slot'] ) ? $args['footer_slot'] : null;

		estandar_render_layout_start( [ 'class' => $extra_class ] );
		// ESTANDAR_ADMIN_UI_V0.6 P4 — chrome superior (marca + título + nav) como
		// bloque sticky. Wrapper aditivo: NO reordena, NO reescribe render_header;
		// preserva el orden de origen (barra naranja → título → navegación).
		echo '<div class="estandar-sticky-top">';
		// HEADER_COMPACT_V1 — el título de la sección activa (fuente única = nav) se
		// muestra en la barra naranja y se elimina la fila de título. Fallback: si no
		// hay sección de nav activa, se usa el título de página del propio shell.
		$bar_title = function_exists( 'estandar_core_global_nav_active_bar_title' )
			? estandar_core_global_nav_active_bar_title()
			: '';
		if ( '' === $bar_title ) {
			$bar_title = $title;
		}
		estandar_render_header(
			[
				'title'        => $title,
				'subtitle'     => $subtitle,
				'bar_title'    => $bar_title,
				'title_in_bar' => true,
			]
		);
		if ( function_exists( 'estandar_render_global_navigation' ) ) {
			estandar_render_global_navigation();
		}
		echo '</div>';

		// ESTANDAR_ADMIN_UI_V0.7 — Marcador estándar de WordPress. core/common.js
		// reubica TODOS los admin notices justo antes de `.wp-header-end`. Sin este
		// marcador, WP los inyecta tras el primer <h1> (= nuestro título del shell),
		// metiéndolos DENTRO del header y rompiendo el layout. Con el marcador aquí,
		// los notices caen después del chrome (barra+nav+título) y antes del contenido.
		echo '<hr class="wp-header-end" />';

		// V2: zona entre header y contenido.
		if ( ! empty( $breadcrumb ) ) {
			estandar_render_breadcrumb( $breadcrumb );
		}
		if ( null !== $header_slot ) {
			call_user_func( $header_slot );
		}
		if ( ! empty( $navigation ) ) {
			estandar_render_navigation( $navigation );
		}
		if ( ! empty( $toolbar ) ) {
			estandar_render_toolbar( $toolbar );
		}

		estandar_render_surface_start();
		echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		estandar_render_surface_end();

		if ( null !== $footer_slot ) {
			call_user_func( $footer_slot );
		}

		estandar_render_layout_end();
	}
}

/**
 * API propuesta en tu convención (alias). Mantener ambas reduce fricción de migración.
 */
if ( ! function_exists( 'estandar_register_submenu' ) ) {
	/**
	 * @param array{slug:string,title:string,menu_title?:string,capability?:string,callback?:callable,target?:string,order?:int} $args
	 */
	function estandar_register_submenu( array $args ): void {
		estandar_register_navigation_page( $args );
	}
}

if ( ! function_exists( 'estandar_register_navigation_page' ) ) {
	/**
	 * @param array{slug:string,capability?:string,callback?:callable,target?:string,title?:string,menu_title?:string,order?:int} $args
	 */
	function estandar_register_navigation_page( array $args ): void {
		$slug       = (string) ( $args['slug'] ?? '' );
		$capability = (string) ( $args['capability'] ?? 'manage_options' );
		$spec       = function_exists( 'estandar_core_navigation_page_spec' ) ? estandar_core_navigation_page_spec( $slug ) : array();
		$title      = (string) ( $args['title'] ?? ( $spec['title'] ?? '' ) );
		$menu_title = (string) ( $args['menu_title'] ?? ( $spec['menu_title'] ?? $title ) );
		$visible    = array_key_exists( 'visible', $spec ) ? (bool) $spec['visible'] : true;
		$shell      = array_key_exists( 'shell', $spec ) ? (bool) $spec['shell'] : true;

		// Auto-register in admin bar registry (works on admin + frontend).
		// This is the canonical path — all compliant plugins get Admin Bar support for free.
		// Solo páginas visible=true (nivel plugin): las ocultas (Ajustes, Comunicaciones,
		// Configuración, submenús internos de módulos, etc.) inundaban el dropdown de la
		// admin bar con decenas de ítems que no son plugins.
		if ( $visible && '' !== $slug && '' !== $menu_title && function_exists( 'estandar_core_register_admin_bar_item' ) ) {
			$bar_url = ! empty( $args['target'] )
				? admin_url( ltrim( (string) $args['target'], '/' ) )
				: admin_url( 'admin.php?page=' . rawurlencode( $slug ) );
			estandar_core_register_admin_bar_item( [
				'slug'       => $slug,
				'title'      => $menu_title,
				'url'        => $bar_url,
				'capability' => $capability,
				'order'      => (int) ( $args['order'] ?? 50 ),
			] );
		}

		// Modo link (lo más seguro para no tocar callbacks).
		if ( ! empty( $args['target'] ) ) {
			estandar_admin_menu_add_link( $title, $menu_title, $capability, (string) $args['target'] );
			return;
		}

		// Modo callback (para plugins nuevos / migrados).
		$callback = $args['callback'] ?? null;
		if ( ! is_callable( $callback ) ) {
			return;
		}

		$register = static function () use ( $args, $callback, $title, $menu_title, $capability, $slug, $visible, $shell, $spec ): void {
			$render = $shell
				? static function () use ( $args, $callback, $title ): void {
					$shell_args = [
						'title'    => $title,
						'subtitle' => isset( $args['subtitle'] ) ? (string) $args['subtitle'] : 'Dashboard administrativo Estandar.',
						'width'    => isset( $args['width'] ) ? (string) $args['width'] : 'fluid',
						'callback' => $callback,
					];
					// Reenvía los args V2 del contrato del Admin Shell (breadcrumb/navigation/
					// toolbar/header_slot/footer_slot) cuando el plugin llamador los declara.
					// Sin esto, un plugin que pasa 'header_slot' a estandar_register_navigation_page()
					// nunca ve su submenú renderizado — el shell lo ignoraba silenciosamente.
					foreach ( [ 'breadcrumb', 'navigation', 'toolbar', 'header_slot', 'footer_slot' ] as $v2_key ) {
						if ( isset( $args[ $v2_key ] ) ) {
							$shell_args[ $v2_key ] = $args[ $v2_key ];
						}
					}
					estandar_core_render_admin_shell( $shell_args );
				}
				: $callback;

			if ( $visible ) {
				add_submenu_page(
					ESTANDAR_CORE_MENU_SLUG,
					$title,
					$menu_title,
					$capability,
					$slug,
					$render
				);
				return;
			}

			add_submenu_page(
				null,
				$title,
				$menu_title,
				$capability,
				$slug,
				$render
			);
		};

		estandar_admin_menu_register_parent();
		if ( did_action( 'admin_menu' ) ) {
			$register();
			return;
		}
		add_action( 'admin_menu', $register, 99 );
	}
}

/**
 * ======================================================
 * MÓDULOS (agrupación de páginas por plugin)
 * ======================================================
 *
 * WP admin no soporta sub-submenus nativos bajo un mismo top-level.
 * Para evitar mezclar páginas de plugins en el mismo nivel:
 * - Cada módulo crea una "página hub" (submenu visible).
 * - Las páginas del módulo se registran como admin pages normales (mismo slug/callback/capability),
 *   pero se ocultan del menú (siguen accesibles por URL directa).
 * - El hub del módulo muestra links a sus páginas (según capability).
 *
 * Esto no cambia slugs, callbacks ni URLs: solo reorganiza visibilidad del menú cuando se usa la API de módulos.
 */

if ( ! function_exists( 'estandar_core_modules_registry' ) ) {
	/**
	 * @return array<string,array{id:string,title:string,icon?:string,hub_slug:string}>
	 */
	function &estandar_core_modules_registry(): array {
		static $modules = [];
		return $modules;
	}
}

if ( ! function_exists( 'estandar_core_module_pages_registry' ) ) {
	/**
	 * @return array<int,array{module:string,slug:string,title:string,callback:callable,capability:string}>
	 */
	function &estandar_core_module_pages_registry(): array {
		static $pages = [];
		return $pages;
	}
}

if ( ! function_exists( 'estandar_core_module_hub_slug' ) ) {
	function estandar_core_module_hub_slug( string $module_id ): string {
		$module_id = sanitize_key( $module_id );
		return 'estandar_module_' . $module_id;
	}
}

if ( ! function_exists( 'estandar_core_register_module_internal' ) ) {
	/**
	 * @param array{id:string,title:string,icon?:string} $args
	 */
	function estandar_core_register_module_internal( array $args ): void {
		$id    = isset( $args['id'] ) ? sanitize_key( (string) $args['id'] ) : '';
		$title = isset( $args['title'] ) ? (string) $args['title'] : '';
		$icon  = isset( $args['icon'] ) ? (string) $args['icon'] : '';

		if ( '' === $id || '' === trim( $title ) ) {
			return;
		}

		$modules = &estandar_core_modules_registry();
		if ( isset( $modules[ $id ] ) ) {
			return;
		}

		$modules[ $id ] = [
			'id'       => $id,
			'title'    => $title,
			'icon'     => $icon,
			'hub_slug' => estandar_core_module_hub_slug( $id ),
		];

		// Stored in static registry by reference.
	}
}

if ( ! function_exists( 'estandar_core_register_module_page_internal' ) ) {
	/**
	 * @param array{module:string,slug:string,title:string,callback:callable,capability:string} $args
	 */
	function estandar_core_register_module_page_internal( array $args ): void {
		$module     = isset( $args['module'] ) ? sanitize_key( (string) $args['module'] ) : '';
		$slug       = isset( $args['slug'] ) ? (string) $args['slug'] : '';
		$title      = isset( $args['title'] ) ? (string) $args['title'] : '';
		$callback   = $args['callback'] ?? null;
		$capability = isset( $args['capability'] ) ? (string) $args['capability'] : '';

		if ( '' === $module || '' === $slug || '' === trim( $title ) || '' === $capability ) {
			return;
		}
		if ( ! is_callable( $callback ) ) {
			return;
		}

		// Allow registering pages even if module wasn't explicitly registered: create a safe minimal module.
		$modules = &estandar_core_modules_registry();
		if ( ! isset( $modules[ $module ] ) ) {
			estandar_core_register_module_internal(
				[
					'id'    => $module,
					'title' => ucfirst( str_replace( '-', ' ', $module ) ),
				]
			);
		}

		$pages = &estandar_core_module_pages_registry();
		$pages[] = [
			'module'     => $module,
			'slug'       => $slug,
			'title'      => $title,
			'callback'   => $callback,
			'capability' => $capability,
		];
	}
}

// Public API requested (global, for plugins).
if ( ! function_exists( 'estandar_register_module' ) ) {
	/**
	 * @param array{id:string,title:string,icon?:string} $args
	 */
	function estandar_register_module( array $args ): void {
		if ( function_exists( 'estandar_core_register_module_internal' ) ) {
			estandar_core_register_module_internal( $args );
		}
	}
}

if ( ! function_exists( 'estandar_register_module_page' ) ) {
	/**
	 * @param array{module:string,slug:string,title:string,callback:callable,capability:string} $args
	 */
	function estandar_register_module_page( array $args ): void {
		if ( function_exists( 'estandar_core_register_module_page_internal' ) ) {
			estandar_core_register_module_page_internal( $args );
		}
	}
}

if ( ! function_exists( 'estandar_core_render_module_hub_page' ) ) {
	function estandar_core_render_module_hub_page(): void {
		if ( ! current_user_can( 'read' ) ) {
			wp_die( 'No autorizado.' );
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$module_id = '';

		$modules = estandar_core_modules_registry();
		foreach ( $modules as $id => $m ) {
			if ( isset( $m['hub_slug'] ) && (string) $m['hub_slug'] === $page ) {
				$module_id = (string) $id;
				break;
			}
		}

		$title = $module_id && isset( $modules[ $module_id ]['title'] ) ? (string) $modules[ $module_id ]['title'] : 'Módulo';
		ob_start();

		echo '<div class="estandar-card">';
		echo '<p class="description">Páginas disponibles en este módulo:</p>';
		echo '<ul>';

		$pages = estandar_core_module_pages_registry();
		foreach ( $pages as $p ) {
			if ( (string) ( $p['module'] ?? '' ) !== $module_id ) {
				continue;
			}
			$cap = (string) ( $p['capability'] ?? 'read' );
			if ( ! current_user_can( $cap ) ) {
				continue;
			}
			$slug  = (string) ( $p['slug'] ?? '' );
			$title = (string) ( $p['title'] ?? $slug );
			$url   = admin_url( 'admin.php?page=' . rawurlencode( $slug ) );
			echo '<li><a href="' . esc_url( $url ) . '">' . esc_html( $title ) . '</a></li>';
		}

		echo '</ul>';
		echo '</div>';

		estandar_core_render_admin_shell(
			[
				'title'    => $title,
				'subtitle' => 'Páginas disponibles dentro del módulo.',
				'width'    => 'fluid',
				'content'  => (string) ob_get_clean(),
			]
		);
	}
}

if ( ! function_exists( 'estandar_core_register_modules_menu' ) ) {
	/**
	 * Registers module hubs and module pages late, after plugins had a chance to register into the registry.
	 */
	function estandar_core_register_modules_menu(): void {
		if ( ! is_admin() ) {
			return;
		}

		$modules = estandar_core_modules_registry();
		$pages   = estandar_core_module_pages_registry();

		if ( empty( $modules ) || empty( $pages ) ) {
			return;
		}

		estandar_admin_menu_register_parent();

		// Create hub submenu per module (visible).
		foreach ( $modules as $module_id => $m ) {
			$hub_slug = isset( $m['hub_slug'] ) ? (string) $m['hub_slug'] : estandar_core_module_hub_slug( (string) $module_id );
			$title    = isset( $m['title'] ) ? (string) $m['title'] : (string) $module_id;

			add_submenu_page(
				ESTANDAR_CORE_MENU_SLUG,
				$title,
				$title,
				'read',
				$hub_slug,
				'estandar_core_render_module_hub_page'
			);
		}

		// Register module pages (keep slug/callback/capability), but hide them from the menu to avoid mixing.
		foreach ( $pages as $p ) {
			$slug       = (string) ( $p['slug'] ?? '' );
			$title      = (string) ( $p['title'] ?? $slug );
			$callback   = $p['callback'] ?? null;
			$capability = (string) ( $p['capability'] ?? 'manage_options' );

			if ( '' === $slug || ! is_callable( $callback ) ) {
				continue;
			}

			add_submenu_page(
				ESTANDAR_CORE_MENU_SLUG,
				$title,
				$title,
				$capability,
				$slug,
				$callback
			);

			// Hide from sidebar menu, keep URL working.
			remove_submenu_page( ESTANDAR_CORE_MENU_SLUG, $slug );
		}
	}
}

	add_action( 'admin_menu', 'estandar_core_register_modules_menu', 999 );

	if ( ! function_exists( 'estandar_core_plugin_registry_rows' ) ) {
		function estandar_core_plugin_registry_rows(): array {
			if ( ! function_exists( 'get_plugins' ) && defined( 'ABSPATH' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			$plugins = function_exists( 'get_plugins' ) ? (array) get_plugins() : [];
			$active  = function_exists( 'wp_get_active_and_valid_plugins' ) ? array_map( 'wp_normalize_path', (array) wp_get_active_and_valid_plugins() ) : [];
			$rows    = [];
			$has_core = false;

			foreach ( $plugins as $plugin_file => $plugin_data ) {
				$name = (string) ( $plugin_data['Name'] ?? '' );
				if ( stripos( $name, 'Estandar' ) === false && strpos( (string) $plugin_file, 'estandar-' ) !== 0 ) {
					continue;
				}

				$absolute = wp_normalize_path( WP_PLUGIN_DIR . '/' . $plugin_file );
				if ( ( defined( 'ESTANDAR_CORE_MU_FILE' ) && $absolute === wp_normalize_path( ESTANDAR_CORE_MU_FILE ) ) || stripos( $name, 'Estandar Primer' ) !== false ) {
					$has_core = true;
				}
				$is_active = in_array( $absolute, $active, true )
					|| ( function_exists( 'is_plugin_active' ) && is_plugin_active( $plugin_file ) )
					|| ( function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( $plugin_file ) );

				$rows[] = [
					'slug'     => dirname( (string) $plugin_file ),
					'name'     => $name !== '' ? $name : (string) $plugin_file,
					'version'  => (string) ( $plugin_data['Version'] ?? '—' ),
					'file'     => (string) $plugin_file,
					'modified' => file_exists( $absolute ) ? (int) filemtime( $absolute ) : 0,
					'status'   => $is_active ? 'Activo' : 'Instalado',
				];
			}

			if ( defined( 'ESTANDAR_CORE_MU_FILE' ) && ! $has_core ) {
				$core_file = wp_normalize_path( ESTANDAR_CORE_MU_FILE );
				$rows[] = [
					'slug'     => 'estandar-core-mu',
					'name'     => 'Estandar Primer',
					'version'  => defined( 'ESTANDAR_CORE_MU_VERSION' ) ? (string) ESTANDAR_CORE_MU_VERSION : '—',
					'file'     => plugin_basename( $core_file ),
					'modified' => file_exists( $core_file ) ? (int) filemtime( $core_file ) : 0,
					'status'   => 'Activo',
				];
			}

			usort( $rows, static function ( array $a, array $b ): int {
				return strcasecmp( (string) $a['name'], (string) $b['name'] );
			} );

			return $rows;
		}
	}

	if ( ! function_exists( 'estandar_core_render_plugin_registry_page' ) ) {
		function estandar_core_render_plugin_registry_page(): void {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( 'No autorizado.' );
			}

			$rows = estandar_core_plugin_registry_rows();
			ob_start();
			?>
			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:16px;">
				<div class="card" style="max-width:none;"><strong>Estandar Primer</strong><br><?php echo esc_html( defined( 'ESTANDAR_CORE_MU_VERSION' ) ? ESTANDAR_CORE_MU_VERSION : '—' ); ?></div>
				<div class="card" style="max-width:none;"><strong>WordPress</strong><br><?php echo esc_html( get_bloginfo( 'version' ) ); ?></div>
				<div class="card" style="max-width:none;"><strong>PHP</strong><br><?php echo esc_html( PHP_VERSION ); ?></div>
			</div>
			<table class="widefat striped">
				<thead>
					<tr>
						<th>Plugin</th>
						<th>Versión instalada</th>
						<th>Fecha archivo principal</th>
						<th>Estado</th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td>
							<strong><?php echo esc_html( (string) $row['name'] ); ?></strong><br>
							<code><?php echo esc_html( (string) $row['slug'] ); ?></code>
						</td>
						<td><?php echo esc_html( (string) $row['version'] ); ?></td>
						<td><?php echo ! empty( $row['modified'] ) ? esc_html( date_i18n( 'Y-m-d H:i', (int) $row['modified'] ) ) : '—'; ?></td>
						<td><?php echo esc_html( (string) $row['status'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php
			$content = ob_get_clean();

			estandar_core_render_admin_shell(
				[
					'title'    => 'Sistema · Plugins',
					'subtitle' => 'Inventario local de plugins Estandar instalados.',
					'width'    => 'fluid',
					'callback' => static function () use ( $content ): void {
						echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					},
				]
			);
		}
	}

	if ( ! function_exists( 'estandar_core_register_plugin_registry_menu' ) ) {
		function estandar_core_register_plugin_registry_menu(): void {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			estandar_register_module(
				[
					'id'    => 'sistema',
					'title' => 'Sistema',
					'icon'  => 'dashicons-admin-tools',
				]
			);
			estandar_register_module_page(
				[
					'module'     => 'sistema',
					'slug'       => 'estandar_core_plugins',
					'title'      => 'Plugins',
					'callback'   => 'estandar_core_render_plugin_registry_page',
					'capability' => 'manage_options',
				]
			);
		}
	}

	add_action( 'admin_menu', 'estandar_core_register_plugin_registry_menu', 60 );

	if ( ! function_exists( 'estandar_core_render_cloud_architecture_page' ) ) {
		/**
		 * Documentation-only page. Preserves the future Estandar Cloud
		 * Fleet Management architecture so implementation can resume later
		 * without reconstructing the design from prior conversations.
		 *
		 * No runtime logic, no hooks besides menu registration, no remote
		 * communication, no options, no tables, no AJAX/JS. Static text only.
		 */
		function estandar_core_render_cloud_architecture_page(): void {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( 'No autorizado.' );
			}

			ob_start();
			?>
			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-bottom:16px;">
				<div class="card" style="max-width:none;"><strong>Arquitectura</strong><br>100% — declarada</div>
				<div class="card" style="max-width:none;"><strong>Implementación</strong><br>0% — no iniciada</div>
				<div class="card" style="max-width:none;"><strong>Runtime / Cloud / Agent / Comunicación</strong><br>No implementado</div>
			</div>

			<div class="card" style="max-width:none;margin-bottom:16px;">
				<p><strong>Esta página es intencionalmente un placeholder.</strong></p>
				<p>Su propósito es documentar la arquitectura futura del Estandar Cloud Control Plane para que el desarrollo pueda retomarse más adelante sin reconstruir el diseño desde conversaciones previas.</p>
				<p>Nada en esta página es funcional. No existe comunicación remota. No existe telemetría. No se ejecutan actualizaciones. No hay comandos remotos disponibles. Solo arquitectura.</p>
			</div>

			<div class="card" style="max-width:none;margin-bottom:16px;">
				<h2>Visión</h2>
				<p>Cada instalación de WordPress que corra el ecosistema Estandar eventualmente será un nodo administrado de una plataforma centralizada. En vez de gestionar decenas o cientos de sitios WordPress individualmente, Estandar Cloud operará como el control plane canónico del ecosistema completo.</p>
			</div>

			<div class="card" style="max-width:none;margin-bottom:16px;">
				<h2>Arquitectura de alto nivel</h2>
				<pre style="white-space:pre-wrap;">ESTANDAR CLOUD
      │
Fleet Management Platform
      │
Secure HTTPS Communication
      │
WordPress Installation
      │
Estandar Agent (Primer)
      │
Installed Estandar Plugins</pre>
			</div>

			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;">
				<div class="card" style="max-width:none;">
					<h2>Responsabilidades a largo plazo</h2>
					<ul style="list-style:disc;padding-left:20px;">
						<li>Fleet Management</li>
						<li>Release Management</li>
						<li>Automatic Updates</li>
						<li>Licensing</li>
						<li>Health Monitoring</li>
						<li>Plugin Inventory</li>
						<li>Feature Flags</li>
						<li>Remote Configuration</li>
						<li>Remote Commands</li>
						<li>Remote Diagnostics</li>
						<li>Remote Rollback</li>
						<li>Heartbeat Monitoring</li>
						<li>Compatibility Verification</li>
						<li>Deployment Channels</li>
						<li>Audit History</li>
					</ul>
				</div>

				<div class="card" style="max-width:none;">
					<h2>Futuros canales de release</h2>
					<ul style="list-style:disc;padding-left:20px;">
						<li>Development</li>
						<li>Beta</li>
						<li>Stable</li>
					</ul>
					<p class="description">Cada instalación se suscribirá a exactamente un canal. Las releases se promoverán entre canales. Las instalaciones Stable solo recibirán releases aprobadas.</p>
				</div>

				<div class="card" style="max-width:none;">
					<h2>Futuro heartbeat</h2>
					<p class="description">Cada instalación reportará periódicamente:</p>
					<ul style="list-style:disc;padding-left:20px;">
						<li>Site UUID</li>
						<li>Plugin versions</li>
						<li>WordPress version</li>
						<li>PHP version</li>
						<li>Server information</li>
						<li>Health status</li>
						<li>Installed plugins</li>
						<li>License status</li>
						<li>Last successful update</li>
						<li>Last communication</li>
					</ul>
				</div>

				<div class="card" style="max-width:none;">
					<h2>Futuro flujo de actualización</h2>
					<pre style="white-space:pre-wrap;">Release → Cloud → Channel → Eligible Sites → Download → Install → Activate → Verification → Heartbeat → Success / Failure</pre>
				</div>

				<div class="card" style="max-width:none;">
					<h2>Futuras operaciones remotas</h2>
					<ul style="list-style:disc;padding-left:20px;">
						<li>Enable plugin</li>
						<li>Disable plugin</li>
						<li>Install plugin</li>
						<li>Remove plugin</li>
						<li>Update plugin</li>
						<li>Rollback version</li>
						<li>Refresh licenses</li>
						<li>Refresh configuration</li>
						<li>Execute diagnostics</li>
						<li>Health check</li>
						<li>Cache clear</li>
					</ul>
					<p class="description">Operaciones futuras únicamente.</p>
				</div>

				<div class="card" style="max-width:none;">
					<h2>Futuros feature flags</h2>
					<p class="description">Las features eventualmente se habilitarán de forma remota, sin requerir una nueva release de plugin. Ejemplos:</p>
					<ul style="list-style:disc;padding-left:20px;">
						<li>New Dashboard</li>
						<li>New AI Engine</li>
						<li>Experimental Maps</li>
						<li>Commercial Intelligence V2</li>
						<li>Destinations V2</li>
						<li>Notifications</li>
					</ul>
				</div>

				<div class="card" style="max-width:none;">
					<h2>Futuro health</h2>
					<p class="description">Cloud sabrá:</p>
					<ul style="list-style:disc;padding-left:20px;">
						<li>Online / Offline</li>
						<li>Last heartbeat</li>
						<li>Update status</li>
						<li>Plugin versions</li>
						<li>Compatibility</li>
						<li>Failures</li>
						<li>Rollback availability</li>
					</ul>
				</div>

				<div class="card" style="max-width:none;">
					<h2>Futuro licensing</h2>
					<p class="description">Las licencias eventualmente se gestionarán de forma centralizada. Cloud decide:</p>
					<ul style="list-style:disc;padding-left:20px;">
						<li>Available plugins</li>
						<li>Enabled modules</li>
						<li>Subscription plan</li>
						<li>Allowed features</li>
					</ul>
					<p class="description">No hay lógica de licensing implementada todavía.</p>
				</div>
			</div>

			<div class="card" style="max-width:none;margin-top:16px;border-left:4px solid #d63638;">
				<h2>Importante</h2>
				<p>Esta página es solo documentación. Su propósito es preservar la intención arquitectónica. No debe ejecutar lógica de runtime. No debe registrar hooks funcionales. No debe comunicarse externamente. No debe modificar el comportamiento de ningún plugin. Debe permanecer completamente pasiva hasta que la fase de implementación comience oficialmente.</p>
			</div>
			<?php
			$content = ob_get_clean();

			estandar_core_render_admin_shell(
				[
					'title'    => 'Estandar Cloud',
					'subtitle' => 'Fleet Management & Control Plane — declaración de arquitectura (no implementado).',
					'width'    => 'fluid',
					'callback' => static function () use ( $content ): void {
						echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					},
				]
			);
		}
	}

	if ( ! function_exists( 'estandar_core_register_cloud_architecture_menu' ) ) {
		/**
		 * Registers the Estandar Cloud page as a hidden WP submenu
		 * (add_submenu_page + remove_submenu_page — same hidden-page pattern
		 * used by estandar_core_register_modules_menu()) purely so the URL
		 * resolves; it is not exposed as a native WP admin sidebar entry.
		 */
		function estandar_core_register_cloud_architecture_menu(): void {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			estandar_admin_menu_register_parent();

			add_submenu_page(
				ESTANDAR_CORE_MENU_SLUG,
				'Estandar Cloud',
				'Estandar Cloud',
				'manage_options',
				'estandar_core_cloud',
				'estandar_core_render_cloud_architecture_page'
			);
			remove_submenu_page( ESTANDAR_CORE_MENU_SLUG, 'estandar_core_cloud' );
		}
	}

	add_action( 'admin_menu', 'estandar_core_register_cloud_architecture_menu', 60 );

	if ( ! function_exists( 'estandar_core_render_client_context_page' ) ) {
		function estandar_core_render_client_context_page(): void {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( 'No autorizado.' );
			}

			$record = estandar_core_get_client_context_record();
			$contacts = is_array( $record['contacts'] ?? null ) ? $record['contacts'] : [];
			$handoff  = is_array( $record['handoff'] ?? null ) ? $record['handoff'] : [];
			$textarea = static function ( $value ): string {
				return is_array( $value ) ? implode( "\n", array_map( 'strval', $value ) ) : (string) $value;
			};
			$name = static function ( string $path ): string {
				return ESTANDAR_CLIENT_CONTEXT_RECORD_OPTION . $path;
			};

			ob_start();
			?>
			<form method="post" action="options.php">
				<?php settings_fields( 'estandar_core_client_context_settings' ); ?>
				<div class="estandar-card" style="max-width:none;">
					<p class="description">Registro operativo mínimo. Debe poder completarse manualmente en menos de 10 minutos.</p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="es-cc-company">Empresa</label></th>
							<td><input id="es-cc-company" class="regular-text" name="<?php echo esc_attr( $name( '[company]' ) ); ?>" value="<?php echo esc_attr( (string) ( $record['company'] ?? estandar_core_get_client_name() ) ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="es-cc-business">Rubro</label></th>
							<td><input id="es-cc-business" class="regular-text" name="<?php echo esc_attr( $name( '[business]' ) ); ?>" value="<?php echo esc_attr( (string) ( $record['business'] ?? '' ) ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="es-cc-website">Website</label></th>
							<td><input id="es-cc-website" type="url" class="regular-text" name="<?php echo esc_attr( $name( '[website]' ) ); ?>" value="<?php echo esc_attr( (string) ( $record['website'] ?? home_url() ) ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="es-cc-owner">Owner interno</label></th>
							<td><input id="es-cc-owner" class="regular-text" name="<?php echo esc_attr( $name( '[owner]' ) ); ?>" value="<?php echo esc_attr( (string) ( $record['owner'] ?? '' ) ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="es-cc-am">Account manager</label></th>
							<td><input id="es-cc-am" class="regular-text" name="<?php echo esc_attr( $name( '[account_manager]' ) ); ?>" value="<?php echo esc_attr( (string) ( $record['account_manager'] ?? '' ) ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="es-cc-services">Servicios</label></th>
							<td><textarea id="es-cc-services" class="large-text" rows="4" name="<?php echo esc_attr( $name( '[services]' ) ); ?>"><?php echo esc_textarea( $textarea( $record['services'] ?? [] ) ); ?></textarea></td>
						</tr>
						<tr>
							<th scope="row"><label for="es-cc-primary-service">Servicio principal</label></th>
							<td><input id="es-cc-primary-service" class="regular-text" name="<?php echo esc_attr( $name( '[primary_service]' ) ); ?>" value="<?php echo esc_attr( (string) ( $record['primary_service'] ?? '' ) ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="es-cc-lead-goal">Objetivo comercial</label></th>
							<td><input id="es-cc-lead-goal" class="regular-text" name="<?php echo esc_attr( $name( '[lead_goal]' ) ); ?>" value="<?php echo esc_attr( (string) ( $record['lead_goal'] ?? '' ) ); ?>" placeholder="agendar visita, reunion comercial, postventa"></td>
						</tr>
						<tr>
							<th scope="row"><label for="es-cc-success">Definición de éxito</label></th>
							<td><input id="es-cc-success" class="regular-text" name="<?php echo esc_attr( $name( '[success_definition]' ) ); ?>" value="<?php echo esc_attr( (string) ( $record['success_definition'] ?? '' ) ); ?>"></td>
						</tr>
					</table>
				</div>

				<div class="estandar-card" style="max-width:none;">
					<h2>Contactos</h2>
					<table class="widefat striped">
						<thead><tr><th>Tipo</th><th>Nombre</th><th>Email</th><th>Teléfono</th></tr></thead>
						<tbody>
							<?php foreach ( [ 'commercial' => 'Comercial', 'operational' => 'Operacional', 'escalation' => 'Escalamiento' ] as $key => $label ) : ?>
								<?php $contact = is_array( $contacts[ $key ] ?? null ) ? $contacts[ $key ] : []; ?>
								<tr>
									<td><strong><?php echo esc_html( $label ); ?></strong></td>
									<td><input class="regular-text" name="<?php echo esc_attr( $name( '[contacts][' . $key . '][name]' ) ); ?>" value="<?php echo esc_attr( (string) ( $contact['name'] ?? '' ) ); ?>"></td>
									<td><input type="email" class="regular-text" name="<?php echo esc_attr( $name( '[contacts][' . $key . '][email]' ) ); ?>" value="<?php echo esc_attr( (string) ( $contact['email'] ?? '' ) ); ?>"></td>
									<td><input class="regular-text" name="<?php echo esc_attr( $name( '[contacts][' . $key . '][phone]' ) ); ?>" value="<?php echo esc_attr( (string) ( $contact['phone'] ?? '' ) ); ?>"></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<div class="estandar-card" style="max-width:none;">
					<h2>Handoff y reglas</h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="es-cc-handoff-owner">Handoff owner</label></th>
							<td><input id="es-cc-handoff-owner" class="regular-text" name="<?php echo esc_attr( $name( '[handoff][owner]' ) ); ?>" value="<?php echo esc_attr( (string) ( $handoff['owner'] ?? '' ) ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="es-cc-handoff-fallback">Fallback</label></th>
							<td><input id="es-cc-handoff-fallback" class="regular-text" name="<?php echo esc_attr( $name( '[handoff][fallback]' ) ); ?>" value="<?php echo esc_attr( (string) ( $handoff['fallback'] ?? '' ) ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="es-cc-handoff-conditions">Condiciones</label></th>
							<td><textarea id="es-cc-handoff-conditions" class="large-text" rows="4" name="<?php echo esc_attr( $name( '[handoff][conditions]' ) ); ?>"><?php echo esc_textarea( $textarea( $handoff['conditions'] ?? [] ) ); ?></textarea></td>
						</tr>
						<tr>
							<th scope="row"><label for="es-cc-rules">Reglas operativas</label></th>
							<td><textarea id="es-cc-rules" class="large-text" rows="4" name="<?php echo esc_attr( $name( '[rules]' ) ); ?>"><?php echo esc_textarea( $textarea( $record['rules'] ?? [ 'No inventar informacion.', 'Escalar cuando no exista certeza.', 'Utilizar unicamente informacion validada.' ] ) ); ?></textarea></td>
						</tr>
						<tr>
							<th scope="row"><label for="es-cc-client-rules">Reglas cliente</label></th>
							<td><textarea id="es-cc-client-rules" class="large-text" rows="4" name="<?php echo esc_attr( $name( '[client_rules]' ) ); ?>"><?php echo esc_textarea( $textarea( $record['client_rules'] ?? [] ) ); ?></textarea></td>
						</tr>
						<tr>
							<th scope="row"><label for="es-cc-projects">Proyectos / líneas</label></th>
							<td><textarea id="es-cc-projects" class="large-text" rows="4" name="<?php echo esc_attr( $name( '[projects]' ) ); ?>"><?php echo esc_textarea( $textarea( $record['projects'] ?? [] ) ); ?></textarea></td>
						</tr>
						<tr>
							<th scope="row"><label for="es-cc-notes">Notas</label></th>
							<td><textarea id="es-cc-notes" class="large-text" rows="4" name="<?php echo esc_attr( $name( '[notes]' ) ); ?>"><?php echo esc_textarea( (string) ( $record['notes'] ?? '' ) ); ?></textarea></td>
						</tr>
					</table>
					<?php submit_button( 'Guardar Client Context Record' ); ?>
				</div>
			</form>
			<?php
			$content = ob_get_clean();

			estandar_core_render_admin_shell(
				[
					'title'    => 'Sistema · Client Context',
					'subtitle' => 'Registro operativo mínimo del cliente.',
					'width'    => 'fluid',
					'callback' => static function () use ( $content ): void {
						echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					},
				]
			);
		}
	}

	if ( ! function_exists( 'estandar_core_register_client_context_menu' ) ) {
		function estandar_core_register_client_context_menu(): void {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			estandar_register_module(
				[
					'id'    => 'sistema',
					'title' => 'Sistema',
					'icon'  => 'dashicons-admin-tools',
				]
			);
			estandar_register_module_page(
				[
					'module'     => 'sistema',
					'slug'       => 'estandar_core_client_context',
					'title'      => 'Client Context',
					'callback'   => 'estandar_core_render_client_context_page',
					'capability' => 'manage_options',
				]
			);
		}
	}

	add_action( 'admin_menu', 'estandar_core_register_client_context_menu', 61 );

	if ( ! function_exists( 'estandar_core_highlight_module_in_menu' ) ) {
	/**
	 * WordPress may pass NULL as $submenu_file on some admin screens (e.g. plugins.php).
	 * Keep this filter strictly non-fatal and non-invasive.
	 *
	 * @param mixed $submenu_file
	 * @return mixed
	 */
	function estandar_core_highlight_module_in_menu( $submenu_file ) {
		if ( ! is_string( $submenu_file ) ) {
			return $submenu_file;
		}
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( '' === $page ) {
			return $submenu_file;
		}

		$pages = estandar_core_module_pages_registry();
		foreach ( $pages as $p ) {
			if ( (string) ( $p['slug'] ?? '' ) !== $page ) {
				continue;
			}

			$module = (string) ( $p['module'] ?? '' );
			if ( '' === $module ) {
				return $submenu_file;
			}

			$modules = estandar_core_modules_registry();
			if ( isset( $modules[ $module ]['hub_slug'] ) ) {
				return (string) $modules[ $module ]['hub_slug'];
			}
		}
		if ( in_array( $page, array( 'estandar_projects_operators', 'estandar_projects_integrations' ), true ) ) {
			return 'estandar_projects';
		}

		return $submenu_file;
	}
}

add_filter( 'submenu_file', 'estandar_core_highlight_module_in_menu', 20 );

/**
 * ======================================================
 * UI/ASSETS ADMIN (scope seguro)
 * ======================================================
 */

if ( ! function_exists( 'estandar_core_should_enqueue_admin_assets' ) ) {
	function estandar_core_should_enqueue_admin_assets( string $hook = '' ): bool {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( '' === $page ) {
			return false;
		}

		if ( $page === ESTANDAR_CORE_MENU_SLUG ) {
			return true;
		}

		// Conservative: only Estandar pages by convention (estandar-*).
		return 0 === strpos( $page, 'estandar' );
	}
}

if ( ! function_exists( 'estandar_core_mu_asset_path' ) ) {
	function estandar_core_mu_asset_path( string $relative ): string {
		$relative = ltrim( $relative, '/' );
		$candidates = [
			estandar_core_trailingslashit( ESTANDAR_CORE_MU_DIR ) . $relative,
			estandar_core_trailingslashit( dirname( __FILE__ ) ) . $relative,
			estandar_core_trailingslashit( dirname( __FILE__ ) ) . 'estandar-core/' . $relative,
		];
		foreach ( $candidates as $candidate ) {
			if ( file_exists( $candidate ) ) {
				return $candidate;
			}
		}
		return $candidates[0];
	}
}

if ( ! function_exists( 'estandar_core_mu_asset_url' ) ) {
	function estandar_core_mu_asset_url( string $relative ): string {
		$relative = ltrim( $relative, '/' );
		$path     = estandar_core_mu_asset_path( $relative );
		$here     = estandar_core_trailingslashit( dirname( __FILE__ ) );
		$path_norm = str_replace( '\\', '/', $path );
		$here_norm = str_replace( '\\', '/', $here );
		if ( 0 === strpos( $path_norm, $here_norm ) ) {
			return plugins_url( ltrim( substr( $path_norm, strlen( $here_norm ) ), '/' ), __FILE__ );
		}
		return estandar_core_trailingslashit( ESTANDAR_CORE_MU_URL ) . $relative;
	}
}

add_action(
	'admin_enqueue_scripts',
	function ( string $hook ): void {
		$css     = estandar_core_mu_asset_path( 'admin/admin.css' );
		$css_url = estandar_core_mu_asset_url( 'admin/admin.css' );

		if ( file_exists( $css ) ) {
			wp_register_style(
				'estandar-admin-ui',
				$css_url,
				[],
				ESTANDAR_CORE_MU_VERSION
			);
		}

		if ( ! estandar_core_should_enqueue_admin_assets( $hook ) ) {
			return;
		}

		if ( file_exists( $css ) ) {
			wp_enqueue_style( 'estandar-admin-ui' );
		}

		$js     = estandar_core_mu_asset_path( 'admin/admin.js' );
		$js_url = estandar_core_mu_asset_url( 'admin/admin.js' );

		if ( file_exists( $js ) ) {
			wp_enqueue_script(
				'estandar-core-admin',
				$js_url,
				[],
				ESTANDAR_CORE_MU_VERSION,
				true
			);
		}
	},
	1
);

/**
 * ======================================================
 * OBSERVABILITY (safe admin signal)
 * ======================================================
 */

if ( ! function_exists( 'estandar_core_mu_is_active' ) ) {
	function estandar_core_mu_is_active(): bool {
		return true;
	}
}

add_action(
	'admin_notices',
	function (): void {
		if ( ! is_admin() ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( $page !== ESTANDAR_CORE_MENU_SLUG ) {
			return;
		}

		$css_ok = file_exists( estandar_core_mu_asset_path( 'admin/admin.css' ) );
		$js_ok  = file_exists( estandar_core_mu_asset_path( 'admin/admin.js' ) );

		if ( $css_ok && $js_ok ) {
			echo '<div class="notice notice-success"><p><strong>Estandar Primer</strong> activo.</p></div>';
			return;
		}

		echo '<div class="notice notice-warning"><p><strong>Estandar Primer</strong> activo, pero faltan assets.</p>';
		echo '<p>Se intentó resolver desde: <code>' . esc_html( ESTANDAR_CORE_MU_DIR ) . '</code></p>';
		echo '<p>Esperado: <code>admin/admin.css</code> y <code>admin/admin.js</code></p>';
		echo '</div>';
	},
	1
);

/**
 * ======================================================
 * DIAGNÓSTICOS (no invasivos)
 * ======================================================
 */

if ( ! function_exists( 'estandar_core_is_diagnostics_request' ) ) {
	function estandar_core_is_diagnostics_request(): bool {
		if ( ! is_admin() ) {
			return false;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		return 'estandar_core_diagnostics' === $page;
	}
}

if ( ! function_exists( 'estandar_core_diag_limit_http_request_args' ) ) {
	/**
	 * Keep diagnostics from waiting on slow remote services if any plugin code fires HTTP while this page renders.
	 *
	 * @param array<string,mixed> $args HTTP request args.
	 * @return array<string,mixed>
	 */
	function estandar_core_diag_limit_http_request_args( array $args ): array {
		if ( ! estandar_core_is_diagnostics_request() ) {
			return $args;
		}

		$timeout = isset( $args['timeout'] ) ? (float) $args['timeout'] : 2.0;
		$args['timeout']     = min( 2.0, max( 1.0, $timeout ) );
		$args['redirection'] = isset( $args['redirection'] ) ? min( 2, absint( $args['redirection'] ) ) : 2;
		$args['blocking']    = true;

		return $args;
	}
}

if ( ! function_exists( 'estandar_core_diag_prevent_http_cascade' ) ) {
	/**
	 * Hard stop for runaway remote checks during diagnostics.
	 *
	 * @param false|array|WP_Error $preempt Existing preempt value.
	 * @return false|array|WP_Error
	 */
	function estandar_core_diag_prevent_http_cascade( $preempt ) {
		if ( ! estandar_core_is_diagnostics_request() || false !== $preempt ) {
			return $preempt;
		}

		static $request_count = 0;
		$request_count++;

		if ( $request_count > 3 ) {
			return new WP_Error( 'estandar_core_diag_http_limit', 'Diagnostics HTTP request limit reached.' );
		}

		return false;
	}
}

if ( ! function_exists( 'estandar_core_diag_limit_http_curl' ) ) {
	function estandar_core_diag_limit_http_curl( $handle ): void {
		if ( ! estandar_core_is_diagnostics_request() ) {
			return;
		}

		if ( ! function_exists( 'curl_setopt' ) ) {
			return;
		}

		if ( defined( 'CURLOPT_CONNECTTIMEOUT' ) ) {
			curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT, 2 );
		}
		if ( defined( 'CURLOPT_TIMEOUT' ) ) {
			curl_setopt( $handle, CURLOPT_TIMEOUT, 2 );
		}
	}
}

add_filter( 'http_request_args', 'estandar_core_diag_limit_http_request_args', 10, 1 );
add_filter( 'pre_http_request', 'estandar_core_diag_prevent_http_cascade', 10, 1 );
add_action( 'http_api_curl', 'estandar_core_diag_limit_http_curl', 10, 1 );

if ( ! function_exists( 'estandar_core_register_diagnostics_menu' ) ) {
	function estandar_core_register_diagnostics_menu(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		estandar_admin_menu_register_parent();

		$menu_title = 'Dashboard';
		if ( function_exists( 'estandar_core_diag_get_summary' ) ) {
			$summary = estandar_core_diag_get_summary();
			$status  = isset( $summary['status'] ) ? (string) $summary['status'] : 'OK';
			if ( 'ERROR' === $status ) {
				$menu_title .= ' 🔴 Critical';
			} elseif ( 'OK' !== $status ) {
				$menu_title .= ' 🟡 Action required';
			} else {
				$menu_title .= ' 🟢 Healthy';
			}
		}

		add_submenu_page(
			ESTANDAR_CORE_MENU_SLUG,
			'Estandar Primer · Estado',
			$menu_title,
			'manage_options',
			'estandar_core_diagnostics',
			'estandar_core_render_diagnostics_page'
		);
	}
}

if ( ! function_exists( 'estandar_core_diag_rule_levels' ) ) {
	/**
	 * Canonical rule levels for the transversal contract.
	 *
	 * @return array{R1:string,R2:string,R3:string,R4:string,R5:string,R6:string}
	 */
	function estandar_core_diag_rule_levels(): array {
		return [
			'R1' => 'MEDIUM', // Weak core detection patterns can cause silent drift.
			'R2' => 'LOW',    // Wrapper is heuristic; missing it is visual inconsistency, usually non-breaking.
			'R3' => 'HIGH',   // Handle collisions can break admin UI/JS in unpredictable ways.
			'R4' => 'MEDIUM', // Duplicated helpers cause drift and future conflicts.
			'R5' => 'MEDIUM', // Generic/ambiguous handles are a real collision risk.
			'R6' => 'LOW',    // Legacy `.ecb-*` usage is transitional (warn only).
		];
	}
}

if ( ! function_exists( 'estandar_core_diag_level_rank' ) ) {
	function estandar_core_diag_level_rank( string $level ): int {
		$level = strtoupper( $level );
		if ( 'HIGH' === $level ) {
			return 3;
		}
		if ( 'MEDIUM' === $level ) {
			return 2;
		}
		return 1; // LOW / unknown
	}
}

if ( ! function_exists( 'estandar_core_diag_status_from_counts' ) ) {
	/**
	 * @param array{HIGH:int,MEDIUM:int,LOW:int,TOTAL:int} $counts
	 */
	function estandar_core_diag_status_from_counts( array $counts ): string {
		$high = isset( $counts['HIGH'] ) ? absint( $counts['HIGH'] ) : 0;
		$med  = isset( $counts['MEDIUM'] ) ? absint( $counts['MEDIUM'] ) : 0;
		if ( $high > 0 ) {
			return 'ERROR';
		}
		if ( $med > 0 ) {
			return 'WARN';
		}
		return 'OK';
	}
}

if ( ! function_exists( 'estandar_core_diag_summary_transient_key' ) ) {
	function estandar_core_diag_summary_transient_key(): string {
		// Bump suffix when logic changes to invalidate old caches.
			return 'estandar_core_diag_summary_v19';
		}
	}

	if ( ! function_exists( 'estandar_core_diag_site_identity' ) ) {
		function estandar_core_diag_site_identity(): array {
			$slug = estandar_core_get_client_slug();
			$name = estandar_core_get_client_name();
			$expected = defined( 'ESTANDAR_EXPECTED_CLIENT_SLUG' ) ? sanitize_title( remove_accents( (string) ESTANDAR_EXPECTED_CLIENT_SLUG ) ) : '';
			$expected = (string) apply_filters( 'estandar_core_expected_client_slug', $expected );
			$expected = sanitize_title( remove_accents( $expected ) );
			$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
			$host_key = preg_replace( '/[^a-z0-9]+/', '', strtolower( remove_accents( $host ) ) );
			$issues = [];

			if ( '' === $slug ) {
				$issues[] = 'Site Identity sin client_slug.';
			}
			if ( '' === $name ) {
				$issues[] = 'Site Identity sin client_name.';
			}
			if ( '' !== $expected && '' !== $slug && $slug !== $expected ) {
				$issues[] = 'Site Identity no coincide con el client_slug esperado.';
			}

			return [
				'client_slug'   => $slug,
				'client_name'   => $name,
				'expected_slug' => $expected,
				'host'          => $host,
				'issues'        => $issues,
			];
		}
	}

	if ( ! function_exists( 'estandar_core_diag_table_columns' ) ) {
		function estandar_core_diag_table_columns( string $table ): array {
			global $wpdb;
			$table = preg_replace( '/[^A-Za-z0-9_]/', '', $table );
			if ( '' === $table ) {
				return [];
			}
			$rows = $wpdb->get_results( 'SHOW COLUMNS FROM ' . $table, ARRAY_A );
			if ( ! is_array( $rows ) ) {
				return [];
			}
			return array_values( array_filter( array_map( static function ( $row ): string {
				return isset( $row['Field'] ) ? (string) $row['Field'] : '';
			}, $rows ) ) );
		}
	}

	if ( ! function_exists( 'estandar_core_diag_table_exists' ) ) {
		function estandar_core_diag_table_exists( string $table ): bool {
			global $wpdb;
			$table = preg_replace( '/[^A-Za-z0-9_]/', '', $table );
			if ( '' === $table ) {
				return false;
			}
			return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
		}
	}

	if ( ! function_exists( 'estandar_core_registry_health_slug' ) ) {
		function estandar_core_registry_health_slug( $value ): string {
			return sanitize_title( remove_accents( (string) $value ) );
		}
	}

	if ( ! function_exists( 'estandar_core_registry_health_row' ) ) {
		function estandar_core_registry_health_row( string $slug, string $label = '', int $count = 1 ): array {
			return [
				'slug'  => estandar_core_registry_health_slug( $slug ),
				'label' => sanitize_text_field( $label ),
				'count' => max( 0, $count ),
			];
		}
	}

	if ( ! function_exists( 'estandar_core_registry_health_add_slug' ) ) {
		function estandar_core_registry_health_add_slug( array &$bucket, string $slug, int $count = 1 ): void {
			$slug = estandar_core_registry_health_slug( $slug );
			if ( '' === $slug ) {
				return;
			}
			if ( ! isset( $bucket[ $slug ] ) ) {
				$bucket[ $slug ] = 0;
			}
			$bucket[ $slug ] += max( 1, $count );
		}
	}

	if ( ! function_exists( 'estandar_core_registry_health' ) ) {
		function estandar_core_registry_health(): array {
			global $wpdb;

			$health = [
				'registry'  => [ 'active' => 0, 'inactive' => 0, 'slugs' => [], 'inactive_slugs' => [] ],
				'operators' => [ 'active' => 0, 'valid' => 0, 'invalid' => 0, 'missing' => 0, 'issues' => [] ],
				'forms'     => [ 'total' => 0, 'valid' => 0, 'invalid_project' => 0, 'missing_operator' => 0, 'invalid_context' => 0, 'missing_project' => 0, 'issues' => [] ],
				'leads'     => [ 'total' => 0, 'valid' => 0, 'orphan' => 0, 'orphans' => [], 'unresolved' => 0 ],
				'campaigns' => [ 'total' => 0, 'valid' => 0, 'orphan' => 0, 'orphans' => [] ],
				'analytics' => [ 'sessions_valid' => 0, 'sessions_orphan' => 0, 'orphans' => [] ],
				'chatbot'   => [ 'unresolved_30d' => 0, 'items' => [] ],
				'tables'    => [],
			];

			$registry_table = $wpdb->prefix . 'estandar_project_registry';
			$registry_exists = estandar_core_diag_table_exists( $registry_table );
			$health['tables']['registry'] = $registry_exists;
			$registry_columns = $registry_exists ? estandar_core_diag_table_columns( $registry_table ) : [];
			if ( $registry_exists && ! array_diff( [ 'project_id', 'label', 'active' ], $registry_columns ) ) {
				$rows = $wpdb->get_results(
					"SELECT project_id, label, active FROM {$registry_table} ORDER BY active DESC, project_id ASC LIMIT 500",
					ARRAY_A
				);
				foreach ( is_array( $rows ) ? $rows : [] as $row ) {
					$slug = estandar_core_registry_health_slug( $row['project_id'] ?? '' );
					if ( '' === $slug ) {
						continue;
					}
					if ( ! empty( $row['active'] ) ) {
						$health['registry']['active']++;
						$health['registry']['slugs'][ $slug ] = sanitize_text_field( (string) ( $row['label'] ?? $slug ) );
					} else {
						$health['registry']['inactive']++;
						$health['registry']['inactive_slugs'][ $slug ] = sanitize_text_field( (string) ( $row['label'] ?? $slug ) );
					}
				}
			}
			$registry_slugs = array_keys( $health['registry']['slugs'] );
			$registry_lookup = array_fill_keys( $registry_slugs, true );

			$operator_projects = [];
			$operators_table = $wpdb->prefix . 'es_operators';
			$operators_exists = estandar_core_diag_table_exists( $operators_table );
			$health['tables']['operators'] = $operators_exists;
			$operator_columns = $operators_exists ? estandar_core_diag_table_columns( $operators_table ) : [];
			if ( $operators_exists && ! array_diff( [ 'id', 'name', 'project_slug', 'active' ], $operator_columns ) ) {
				$allowed_select = in_array( 'allowed_projects_json', $operator_columns, true ) ? 'allowed_projects_json' : "'' AS allowed_projects_json";
				$rows = $wpdb->get_results(
					"SELECT id, name, project_slug, {$allowed_select}, active FROM {$operators_table} ORDER BY active DESC, name ASC LIMIT 500",
					ARRAY_A
				);
				foreach ( is_array( $rows ) ? $rows : [] as $row ) {
					if ( empty( $row['active'] ) ) {
						continue;
					}
					$health['operators']['active']++;
					$projects = [];
					$primary = estandar_core_registry_health_slug( $row['project_slug'] ?? '' );
					if ( '' !== $primary ) {
						$projects[] = $primary;
					}
					$allowed = json_decode( (string) ( $row['allowed_projects_json'] ?? '' ), true );
					foreach ( is_array( $allowed ) ? $allowed : [] as $allowed_slug ) {
						$allowed_slug = estandar_core_registry_health_slug( $allowed_slug );
						if ( '' !== $allowed_slug ) {
							$projects[] = $allowed_slug;
						}
					}
					$projects = array_values( array_unique( $projects ) );
					if ( empty( $projects ) ) {
						$health['operators']['missing']++;
						$health['operators']['issues'][] = [
							'type' => 'Sin proyecto',
							'name' => sanitize_text_field( (string) ( $row['name'] ?? 'Operador' ) ),
							'slug' => '',
						];
						continue;
					}
					$has_valid = false;
					foreach ( $projects as $project_slug ) {
						if ( isset( $registry_lookup[ $project_slug ] ) ) {
							$has_valid = true;
							$operator_projects[ $project_slug ] = true;
						} else {
							$health['operators']['invalid']++;
							$health['operators']['issues'][] = [
								'type' => 'Proyecto inexistente',
								'name' => sanitize_text_field( (string) ( $row['name'] ?? 'Operador' ) ),
								'slug' => $project_slug,
							];
						}
					}
					if ( $has_valid ) {
						$health['operators']['valid']++;
					}
				}
			}

			$forms_table = $wpdb->prefix . 'estandar_forms';
			$forms_exists = estandar_core_diag_table_exists( $forms_table );
			$health['tables']['forms'] = $forms_exists;
			$form_columns = $forms_exists ? estandar_core_diag_table_columns( $forms_table ) : [];
			if ( $forms_exists && ! array_diff( [ 'id', 'name', 'form_type', 'context_schema' ], $form_columns ) ) {
				$rows = $wpdb->get_results(
					"SELECT id, name, form_type, context_schema FROM {$forms_table} ORDER BY id ASC LIMIT 500",
					ARRAY_A
				);
				foreach ( is_array( $rows ) ? $rows : [] as $row ) {
					$health['forms']['total']++;
					$form_type = sanitize_key( (string) ( $row['form_type'] ?? '' ) );
					$raw_context = trim( (string) ( $row['context_schema'] ?? '' ) );
					if ( '' === $raw_context ) {
						if ( in_array( $form_type, [ 'lead', 'lead_capture' ], true ) ) {
							$health['forms']['missing_project']++;
							$health['forms']['issues'][] = [ 'type' => 'Sin routing', 'name' => (string) ( $row['name'] ?? '' ), 'slug' => '', 'id' => absint( $row['id'] ?? 0 ) ];
						}
						continue;
					}
					$context = json_decode( $raw_context, true );
					if ( ! is_array( $context ) ) {
						$health['forms']['invalid_context']++;
						$health['forms']['issues'][] = [ 'type' => 'Context schema inválido', 'name' => (string) ( $row['name'] ?? '' ), 'slug' => '', 'id' => absint( $row['id'] ?? 0 ) ];
						continue;
					}
					$routing = isset( $context['routing'] ) && is_array( $context['routing'] ) ? $context['routing'] : [];
					$mode = sanitize_key( (string) ( $routing['mode'] ?? '' ) );
					$slug = estandar_core_registry_health_slug( $routing['project_slug'] ?? '' );
					if ( 'fixed_project' !== $mode ) {
						continue;
					}
					if ( '' === $slug ) {
						$health['forms']['missing_project']++;
						$health['forms']['issues'][] = [ 'type' => 'Sin proyecto', 'name' => (string) ( $row['name'] ?? '' ), 'slug' => '', 'id' => absint( $row['id'] ?? 0 ) ];
					} elseif ( ! isset( $registry_lookup[ $slug ] ) ) {
						$health['forms']['invalid_project']++;
						$health['forms']['issues'][] = [ 'type' => 'Proyecto inexistente', 'name' => (string) ( $row['name'] ?? '' ), 'slug' => $slug, 'id' => absint( $row['id'] ?? 0 ) ];
					} elseif ( ! isset( $operator_projects[ $slug ] ) ) {
						$health['forms']['missing_operator']++;
						$health['forms']['issues'][] = [ 'type' => 'Proyecto sin operador', 'name' => (string) ( $row['name'] ?? '' ), 'slug' => $slug, 'id' => absint( $row['id'] ?? 0 ) ];
					} else {
						$health['forms']['valid']++;
					}
				}
			}

			$since_90 = gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS );
			$leads_table = $wpdb->prefix . 'es_leads';
			$leads_exists = estandar_core_diag_table_exists( $leads_table );
			$health['tables']['leads'] = $leads_exists;
			$lead_columns = $leads_exists ? estandar_core_diag_table_columns( $leads_table ) : [];
			if ( $leads_exists && ! array_diff( [ 'project_slug', 'created_at' ], $lead_columns ) ) {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT project_slug, COUNT(*) AS total FROM {$leads_table} WHERE created_at >= %s AND project_slug <> '' GROUP BY project_slug ORDER BY total DESC LIMIT 100",
						$since_90
					),
					ARRAY_A
				);
				foreach ( is_array( $rows ) ? $rows : [] as $row ) {
					$slug = estandar_core_registry_health_slug( $row['project_slug'] ?? '' );
					$count = absint( $row['total'] ?? 0 );
					$health['leads']['total'] += $count;
					if ( isset( $registry_lookup[ $slug ] ) ) {
						$health['leads']['valid'] += $count;
					} else {
						$health['leads']['orphan'] += $count;
						$health['leads']['orphans'][] = estandar_core_registry_health_row( $slug, '', $count );
					}
					// PROJECT_REGISTRY_ENFORCEMENT_V1 — desde que resolve_slug() ya no
					// hace pass-through, los candidatos sin match quedan project_slug=''
					// (excluidos de la query de arriba). Sin este conteo, esos leads
					// desaparecían silenciosamente de Registry Health — visibles como
					// "pendientes de resolver" (INFO), no como falsos huérfanos (ERROR)
					// que invitaban a "crear proyecto" para un slug que nunca fue real.
					$health['leads']['unresolved'] = (int) $wpdb->get_var(
						$wpdb->prepare(
							"SELECT COUNT(*) FROM {$leads_table} WHERE created_at >= %s AND project_slug = ''",
							$since_90
						)
					);
				}
			}

			$campaigns_table = $wpdb->prefix . 'estandar_campaign_discovery';
			$campaigns_exists = estandar_core_diag_table_exists( $campaigns_table );
			$health['tables']['campaigns'] = $campaigns_exists;
			$campaign_columns = $campaigns_exists ? estandar_core_diag_table_columns( $campaigns_table ) : [];
			if ( $campaigns_exists && in_array( 'project_slug', $campaign_columns, true ) ) {
				$rows = $wpdb->get_results(
					"SELECT project_slug, COUNT(*) AS total FROM {$campaigns_table} WHERE project_slug <> '' GROUP BY project_slug ORDER BY total DESC LIMIT 100",
					ARRAY_A
				);
				foreach ( is_array( $rows ) ? $rows : [] as $row ) {
					$slug = estandar_core_registry_health_slug( $row['project_slug'] ?? '' );
					$count = absint( $row['total'] ?? 0 );
					$health['campaigns']['total'] += $count;
					if ( isset( $registry_lookup[ $slug ] ) ) {
						$health['campaigns']['valid'] += $count;
					} else {
						$health['campaigns']['orphan'] += $count;
						$health['campaigns']['orphans'][] = estandar_core_registry_health_row( $slug, '', $count );
					}
				}
			}

			$sessions_table = $wpdb->prefix . 'ea_sessions';
			$sessions_exists = estandar_core_diag_table_exists( $sessions_table );
			$health['tables']['analytics_sessions'] = $sessions_exists;
			$session_columns = $sessions_exists ? estandar_core_diag_table_columns( $sessions_table ) : [];
			if ( $sessions_exists && in_array( 'first_project_slug', $session_columns, true ) ) {
				$rows = $wpdb->get_results(
					"SELECT first_project_slug, COUNT(*) AS total FROM {$sessions_table} WHERE first_project_slug <> '' GROUP BY first_project_slug ORDER BY total DESC LIMIT 100",
					ARRAY_A
				);
				foreach ( is_array( $rows ) ? $rows : [] as $row ) {
					$slug = estandar_core_registry_health_slug( $row['first_project_slug'] ?? '' );
					$count = absint( $row['total'] ?? 0 );
					if ( isset( $registry_lookup[ $slug ] ) ) {
						$health['analytics']['sessions_valid'] += $count;
					} else {
						$health['analytics']['sessions_orphan'] += $count;
						$health['analytics']['orphans'][] = estandar_core_registry_health_row( $slug, '', $count );
					}
				}
			}

			$telemetry_table = $wpdb->prefix . 'estandar_errors';
			$telemetry_exists = estandar_core_diag_table_exists( $telemetry_table );
			$health['tables']['telemetry'] = $telemetry_exists;
			$telemetry_columns = $telemetry_exists ? estandar_core_diag_table_columns( $telemetry_table ) : [];
			if ( $telemetry_exists && ! array_diff( [ 'source_plugin', 'code', 'created_at' ], $telemetry_columns ) ) {
				$since_30 = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
				$health['chatbot']['unresolved_30d'] = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$telemetry_table} WHERE source_plugin = %s AND code = %s AND created_at >= %s",
						'estandar-chatbot',
						'CHATBOT_PROJECT_UNRESOLVED',
						$since_30
					)
				);
				$health['chatbot']['items'] = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT created_at, message, context_json FROM {$telemetry_table} WHERE source_plugin = %s AND code = %s AND created_at >= %s ORDER BY created_at DESC LIMIT 10",
						'estandar-chatbot',
						'CHATBOT_PROJECT_UNRESOLVED',
						$since_30
					),
					ARRAY_A
				) ?: [];
			}

			$health['registry']['without_operator'] = array_values( array_diff( $registry_slugs, array_keys( $operator_projects ) ) );
			return $health;
		}
	}

	if ( ! function_exists( 'estandar_core_render_registry_health_table' ) ) {
		function estandar_core_render_registry_health_table( array $rows, string $empty ): void {
			if ( empty( $rows ) ) {
				echo '<p class="description" style="margin:0;">' . esc_html( $empty ) . '</p>';
				return;
			}
			echo '<table class="widefat striped"><thead><tr><th>Tipo</th><th>Entidad</th><th>Slug</th><th>Conteo</th></tr></thead><tbody>';
			foreach ( array_slice( $rows, 0, 20 ) as $row ) {
				echo '<tr>';
				echo '<td>' . esc_html( (string) ( $row['type'] ?? 'ORPHAN' ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $row['name'] ?? $row['label'] ?? '' ) ) . '</td>';
				echo '<td><code>' . esc_html( (string) ( $row['slug'] ?? '' ) ) . '</code></td>';
				echo '<td>' . esc_html( (string) absint( $row['count'] ?? 1 ) ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
	}

	if ( ! function_exists( 'estandar_core_render_registry_health_section' ) ) {
		function estandar_core_render_registry_health_section(): void {
			$health = estandar_core_registry_health();
			$registry = $health['registry'];
			$operators = $health['operators'];
			$forms = $health['forms'];
			$leads = $health['leads'];
			$campaigns = $health['campaigns'];
			$analytics = $health['analytics'];
			$chatbot = $health['chatbot'];
			$without_operator = array_values( (array) ( $registry['without_operator'] ?? [] ) );
			$form_issues = array_values( (array) ( $forms['issues'] ?? [] ) );
			$lead_orphans = array_values( (array) ( $leads['orphans'] ?? [] ) );
			$operator_issues = array_values( (array) ( $operators['issues'] ?? [] ) );
			$campaign_orphans = array_values( (array) ( $campaigns['orphans'] ?? [] ) );
			$analytics_orphans = array_values( (array) ( $analytics['orphans'] ?? [] ) );

			$primary_issue_count = count( $without_operator ) + count( $form_issues ) + count( $lead_orphans );
			$secondary_issue_count = count( $operator_issues ) + count( $campaign_orphans ) + count( $analytics_orphans ) + absint( $chatbot['unresolved_30d'] ?? 0 );
			$global_status = absint( $leads['orphan'] ?? 0 ) > 0 ? 'FAIL' : ( $primary_issue_count > 0 || $secondary_issue_count > 0 ? 'WARN' : 'PASS' );
			$status_copy = 'PASS' === $global_status ? 'Todo correcto' : ( 'FAIL' === $global_status ? 'Impacto real detectado' : 'Requiere atención' );
			$status_color = 'PASS' === $global_status ? '#1a7f37' : ( 'FAIL' === $global_status ? '#d63638' : '#b7791f' );
			$status_bg = 'PASS' === $global_status ? '#ecfdf3' : ( 'FAIL' === $global_status ? '#fdecec' : '#fff8e5' );
			$status_border = 'PASS' === $global_status ? '#bbf7d0' : ( 'FAIL' === $global_status ? '#fecaca' : '#fde68a' );

			$render_anomaly = static function ( string $level, string $title, string $detail, string $slug, string $action_label, string $url ) use ( $global_status ): void {
				$level_color  = 'FAIL' === $level ? '#d63638' : '#b7791f';
				$level_bg     = 'FAIL' === $level ? '#fdecec' : '#fff8e5';
				$level_labels = [ 'FAIL' => 'Error', 'WARN' => 'Atención' ];
				$level_display = $level_labels[ $level ] ?? $level;
				?>
				<div class="es-registry-anomaly" style="display:grid;grid-template-columns:72px minmax(0,1fr) auto;gap:12px;align-items:center;padding:12px 0;border-bottom:1px solid #f3f4f6;">
					<span style="display:inline-flex;align-items:center;justify-content:center;border-radius:4px;background:<?php echo esc_attr( $level_bg ); ?>;color:<?php echo esc_attr( $level_color ); ?>;font-size:11px;font-weight:800;height:24px;"><?php echo esc_html( $level_display ); ?></span>
					<div style="min-width:0;">
						<strong style="display:block;color:#111827;font-size:13px;"><?php echo esc_html( $title ); ?></strong>
						<span style="display:block;color:#6b7280;font-size:12px;margin-top:2px;"><?php echo esc_html( $detail ); ?></span>
						<?php if ( '' !== $slug ) : ?>
							<code style="display:inline-block;margin-top:4px;font-size:11px;"><?php echo esc_html( $slug ); ?></code>
						<?php endif; ?>
					</div>
					<a class="button button-small" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $action_label ); ?></a>
				</div>
				<?php
			};
			?>
			<style>
			@media(max-width:700px){.es-registry-anomaly{grid-template-columns:1fr!important;align-items:flex-start!important}.es-registry-anomaly .button{width:max-content;max-width:100%;white-space:normal;text-align:center}}
			.es-registry-kpis{grid-template-columns:repeat(4,minmax(0,1fr));}
			.es-kpi-neutral{border-color:#e5e7eb!important;background:#f9fafb!important;}
			.es-kpi-neutral .es-kpi-value{color:#4b5563;}
			@media(max-width:900px){.es-registry-kpis{grid-template-columns:1fr!important;}}
			</style>
			<div class="es-dashboard-header">
				<div>
					<span class="es-dashboard-eyebrow">Estandar Primer</span>
					<h2 class="es-dashboard-title">Integridad Operacional</h2>
					<p class="es-dashboard-subtitle">Centro de anomalías entre proyectos, formularios, leads y operadores.</p>
				</div>
				<div style="border:1px solid <?php echo esc_attr( $status_border ); ?>;background:<?php echo esc_attr( $status_bg ); ?>;border-radius:8px;padding:10px 14px;min-width:210px;text-align:right;">
					<span style="display:block;color:#6b7280;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;">Estado general</span>
					<strong style="display:block;color:<?php echo esc_attr( $status_color ); ?>;font-size:24px;line-height:1;margin-top:3px;"><?php echo esc_html( $global_status ); ?></strong>
					<span style="display:block;color:#374151;font-size:12px;margin-top:4px;"><?php echo esc_html( $status_copy ); ?></span>
				</div>
			</div>

			<div class="es-diag-section">
				<div class="es-diag-section-header">Anomalías críticas</div>
				<div class="es-diag-section-body">
					<?php if ( 0 === $primary_issue_count ) : ?>
						<div style="padding:18px 0;color:#1a7f37;font-size:13px;font-weight:600;">Todo correcto. No hay anomalías críticas.</div>
					<?php else : ?>
						<p style="margin:0 0 8px;color:#374151;font-size:13px;font-weight:700;"><?php echo esc_html( (string) $primary_issue_count ); ?> problema<?php echo 1 === $primary_issue_count ? '' : 's'; ?> requiere<?php echo 1 === $primary_issue_count ? '' : 'n'; ?> atención</p>
						<?php foreach ( $without_operator as $slug ) : ?>
							<?php $render_anomaly( 'WARN', (string) $slug, 'Proyecto activo sin operador', '', 'Asignar operador', admin_url( 'admin.php?page=estandar_projects_operators&project_slug=' . rawurlencode( (string) $slug ) . '&diagnostic=missing_operator#operator-form' ) ); ?>
						<?php endforeach; ?>
						<?php foreach ( $form_issues as $row ) : ?>
							<?php
							$form_id = absint( $row['id'] ?? 0 );
							$form_url = $form_id > 0
								? admin_url( 'admin.php?page=estandar-forms&edit=' . $form_id . '&diagnostic=routing_issue#routing_project_slug' )
								: admin_url( 'admin.php?page=estandar-forms&diagnostic=routing_issue' );
							$render_anomaly( 'WARN', (string) ( $row['name'] ?? 'Formulario' ), (string) ( $row['type'] ?? 'Routing inválido' ), (string) ( $row['slug'] ?? '' ), 'Configurar routing', $form_url );
							?>
						<?php endforeach; ?>
						<?php foreach ( $lead_orphans as $row ) : ?>
							<?php $render_anomaly( 'FAIL', (string) ( $row['slug'] ?? 'Slug huérfano' ), absint( $row['count'] ?? 0 ) . ' lead(s) afectados en los últimos 90 días.', '', 'Crear proyecto', admin_url( 'admin.php?page=estandar_projects&action=edit&project_id=' . rawurlencode( (string) ( $row['slug'] ?? '' ) ) ) ); ?>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>
			</div>

			<div class="es-kpi-grid es-registry-kpis">
				<div class="es-kpi<?php echo count( $without_operator ) ? ' es-kpi-error' : ' es-kpi-success'; ?>"><span class="es-kpi-label">Proyectos sin operador</span><span class="es-kpi-value"><?php echo esc_html( (string) count( $without_operator ) ); ?></span></div>
				<div class="es-kpi<?php echo count( $form_issues ) ? ' es-kpi-error' : ' es-kpi-success'; ?>"><span class="es-kpi-label">Forms con routing inválido</span><span class="es-kpi-value"><?php echo esc_html( (string) count( $form_issues ) ); ?></span></div>
				<div class="es-kpi<?php echo absint( $leads['orphan'] ?? 0 ) ? ' es-kpi-error' : ' es-kpi-success'; ?>"><span class="es-kpi-label">Leads huérfanos 90 días</span><span class="es-kpi-value"><?php echo esc_html( (string) absint( $leads['orphan'] ?? 0 ) ); ?></span></div>
				<div class="es-kpi es-kpi-neutral" title="project_slug no coincidió con ningún proyecto del Registry — no se inventa un proyecto falso, queda pendiente de asignación manual."><span class="es-kpi-label">Leads sin resolver 90 días</span><span class="es-kpi-value"><?php echo esc_html( (string) absint( $leads['unresolved'] ?? 0 ) ); ?></span></div>
			</div>

			<div class="es-diag-section">
				<div class="es-diag-section-header">Impacto secundario</div>
				<div class="es-diag-section-body">
					<p style="margin:0;color:#374151;font-size:13px;">
						<strong>Campaigns · Analytics · Chatbot:</strong> <?php echo esc_html( (string) ( absint( $campaigns['orphan'] ?? 0 ) + absint( $analytics['sessions_orphan'] ?? 0 ) + absint( $chatbot['unresolved_30d'] ?? 0 ) ) ); ?> anomalías ·
						<strong>Operadores:</strong> <?php echo esc_html( (string) count( $operator_issues ) ); ?> anomalías
					</p>
					<p class="description" style="margin:6px 0 0;">Esta pantalla detecta anomalías; cada módulo dueño conserva el detalle y la corrección.</p>
				</div>
			</div>

			<details class="es-diag-section">
				<summary>Evidencia técnica</summary>
				<div class="es-diag-section-body">
					<div class="es-two-col">
							<div>
								<p><strong>Registry:</strong> <?php echo esc_html( (string) $registry['active'] ); ?> activos · <?php echo esc_html( (string) count( $without_operator ) ); ?> issues</p>
								<?php if ( ! empty( $without_operator ) ) : ?>
									<?php estandar_core_render_registry_health_table( array_map( static fn( $slug ) => [ 'type' => 'Sin operador', 'name' => '', 'slug' => $slug, 'count' => 1 ], $without_operator ), '' ); ?>
								<?php endif; ?>
							</div>
							<div>
								<p><strong>Forms:</strong> <?php echo esc_html( (string) $forms['valid'] ); ?> válidos · <?php echo esc_html( (string) count( $form_issues ) ); ?> issues</p>
								<?php if ( ! empty( $form_issues ) ) : ?>
									<?php estandar_core_render_registry_health_table( $form_issues, '' ); ?>
								<?php endif; ?>
							</div>
						</div>
						<div class="es-two-col" style="margin-top:12px;">
							<div>
								<p><strong>Leads:</strong> <?php echo esc_html( (string) $leads['valid'] ); ?> válidos · <?php echo esc_html( (string) $leads['orphan'] ); ?> huérfanos</p>
								<?php if ( ! empty( $lead_orphans ) ) : ?>
									<?php estandar_core_render_registry_health_table( $lead_orphans, '' ); ?>
								<?php endif; ?>
							</div>
							<div>
								<p><strong>Impacto secundario:</strong> <?php echo esc_html( (string) ( count( $campaign_orphans ) + count( $analytics_orphans ) ) ); ?> issues</p>
								<?php if ( ! empty( $campaign_orphans ) || ! empty( $analytics_orphans ) ) : ?>
									<?php estandar_core_render_registry_health_table( array_merge( $campaign_orphans, $analytics_orphans ), '' ); ?>
								<?php endif; ?>
							</div>
					</div>
				</div>
			</details>
			<?php
		}
	}

	if ( ! function_exists( 'estandar_core_diag_deployment_validator' ) ) {
		function estandar_core_diag_deployment_validator(): array {
			global $wpdb;

			$checks = [];
			$add = static function ( string $key, string $label, string $status, string $evidence, string $fix_url = '' ) use ( &$checks ): void {
				$checks[] = [
					'key'      => $key,
					'label'    => $label,
					'status'   => $status,
					'pass'     => 'FAIL' !== $status,
					'evidence' => $evidence,
					'fix_url'  => $fix_url,
				];
			};

			$site_identity = estandar_core_diag_site_identity();
			$add( 'site_identity', 'Site Identity configurada', empty( $site_identity['issues'] ) ? 'PASS' : 'REQUIRES ATTENTION', (string) ( $site_identity['client_slug'] ?? '' ) . ' / ' . (string) ( $site_identity['client_name'] ?? '' ) );

			$forms_render = WP_PLUGIN_DIR . '/estandar-forms/public/form-render.php';
			$forms_context = WP_PLUGIN_DIR . '/estandar-forms/includes/class-forms.php';
			$forms_active = is_readable( $forms_render ) && is_readable( $forms_context );
			$render_source = is_readable( $forms_render ) ? (string) file_get_contents( $forms_render ) : '';
			$context_source = is_readable( $forms_context ) ? (string) file_get_contents( $forms_context ) : '';
			$form_fields = [ 'lead_uuid', 'lead_status', 'client_slug', 'client_name', 'project_slug', 'project_name', 'created_at' ];
			$missing_form_fields = array_values( array_filter( $form_fields, static function ( string $field ) use ( $render_source ): bool {
				return false === strpos( $render_source, 'name="' . $field . '"' ) && false === strpos( $render_source, "name='" . $field . "'" );
			} ) );
			$add( 'forms_context_fields', 'Forms emite contexto oculto', ! $forms_active ? 'WARNING' : ( empty( $missing_form_fields ) ? 'PASS' : 'WARNING' ), ! $forms_active ? 'Estandar Forms no detectado; posible modo LEGACY/COMPAT.' : ( empty( $missing_form_fields ) ? implode( ', ', $form_fields ) : 'Faltan: ' . implode( ', ', $missing_form_fields ) ) );
			$form_project_trace = [];
			if ( $forms_active && function_exists( 'estandar_forms_routing_context' ) ) {
				$forms_table = $wpdb->prefix . 'estandar_forms';
				$form_rows = $wpdb->get_results( "SELECT id, name, slug, context_schema FROM {$forms_table} ORDER BY id ASC", ARRAY_A );
				$form_usage = [];
				$usage_posts = $wpdb->get_results(
					"SELECT ID, post_content
					 FROM {$wpdb->posts}
					 WHERE post_status = 'publish'
					   AND post_content LIKE '%[estandar_form%'",
					ARRAY_A
				);
				$shortcode_regex = get_shortcode_regex( [ 'estandar_form' ] );
				foreach ( (array) $usage_posts as $usage_post ) {
					if ( ! preg_match_all( '/' . $shortcode_regex . '/s', (string) ( $usage_post['post_content'] ?? '' ), $matches, PREG_SET_ORDER ) ) {
						continue;
					}
					foreach ( $matches as $match ) {
						$atts = shortcode_parse_atts( (string) ( $match[3] ?? '' ) );
						$atts = is_array( $atts ) ? $atts : [];
						$key = ! empty( $atts['id'] ) ? 'id:' . absint( $atts['id'] ) : ( ! empty( $atts['slug'] ) ? 'slug:' . sanitize_title( (string) $atts['slug'] ) : '' );
						if ( '' !== $key ) {
							$form_usage[ $key ][] = [
								'post_id'      => (int) ( $usage_post['ID'] ?? 0 ),
								'project_slug' => sanitize_title( remove_accents( (string) ( $atts['project_slug'] ?? '' ) ) ),
							];
						}
					}
				}
				foreach ( (array) $form_rows as $form_row ) {
					$routing = estandar_forms_routing_context( $form_row['context_schema'] ?? '' );
					$mode = (string) ( $routing['mode'] ?? 'none' );
					$source = 'UNKNOWN';
					$resolved = '';
					$usages = array_merge(
						(array) ( $form_usage[ 'id:' . (int) ( $form_row['id'] ?? 0 ) ] ?? [] ),
						(array) ( $form_usage[ 'slug:' . sanitize_title( (string) ( $form_row['slug'] ?? '' ) ) ] ?? [] )
					);
					if ( 'fixed_project' === $mode && ! empty( $routing['project_slug'] ) ) {
						$source = 'FORM';
						$resolved = sanitize_title( remove_accents( (string) $routing['project_slug'] ) );
					} elseif ( 'project_selector' === $mode ) {
						$source = 'URL';
						$resolved = 'dynamic';
					} elseif ( 'none' === $mode && ! empty( $usages ) ) {
						$usage_projects = array_values( array_unique( array_filter( array_column( $usages, 'project_slug' ) ) ) );
						if ( ! empty( $usage_projects ) ) {
							$source = 'URL';
							$resolved = implode( ', ', $usage_projects );
						} else {
							$source = 'CORE_FALLBACK';
							$resolved = (string) ( $site_identity['client_slug'] ?? '' );
						}
					} elseif ( 'none' === $mode && empty( $usages ) ) {
						// Sin routing y sin uso en posts publicados: formulario template/no desplegado.
						// No cuenta como error de routing.
						$source = 'TEMPLATE';
						$resolved = '—';
					}
					$form_project_trace[] = [
						'form_id'          => (int) ( $form_row['id'] ?? 0 ),
						'form_name'        => (string) ( $form_row['name'] ?? $form_row['slug'] ?? '' ),
						'resolved_project' => $resolved,
						'resolution_source'=> $source,
					];
				}
			}
			$form_trace_fallbacks = array_values( array_filter( $form_project_trace, static function ( array $row ): bool {
				// TEMPLATE = form sin routing y sin uso activo → no es un error de routing.
				return in_array( (string) ( $row['resolution_source'] ?? '' ), [ 'CORE_FALLBACK', 'UNKNOWN' ], true );
			} ) );
			$form_trace_deployed = array_values( array_filter( $form_project_trace, static function ( array $row ): bool {
				return ( $row['resolution_source'] ?? '' ) !== 'TEMPLATE';
			} ) );
			if ( empty( $form_project_trace ) ) {
				$form_trace_evidence = 'Sin formularios trazables.';
			} else {
				$parts = [];
				$with_project = count( $form_trace_deployed ) - count( $form_trace_fallbacks );
				if ( $with_project > 0 ) {
					$parts[] = $with_project . ' con proyecto propio';
				}
				if ( ! empty( $form_trace_fallbacks ) ) {
					$fb_names = array_map( function ( $r ) { return $r['form_name'] ?? ''; }, $form_trace_fallbacks );
					$parts[] = count( $form_trace_fallbacks ) . ' usando proyecto por defecto (' . implode( ', ', array_filter( $fb_names ) ) . ')';
				}
				$tmpl_count = count( $form_project_trace ) - count( $form_trace_deployed );
				if ( $tmpl_count > 0 ) {
					$parts[] = $tmpl_count . ' template';
				}
				$form_trace_evidence = count( $form_trace_deployed ) . ' formularios activos. ' . implode( '; ', $parts ) . '.';
			}
			$add( 'forms_project_resolution', 'Resolución de proyecto en formularios', ! $forms_active ? 'WARNING' : ( ! empty( $form_trace_fallbacks ) ? 'INFO' : 'PASS' ), $form_trace_evidence, ! empty( $form_trace_fallbacks ) ? admin_url( 'admin.php?page=estandar-forms' ) : '' );

			$forms_code_version = defined( 'ESTANDAR_FORMS_VERSION' ) ? (string) ESTANDAR_FORMS_VERSION : '';
			$forms_db_version   = (string) get_option( 'estandar_forms_db_version', '' );
			$forms_db_target    = class_exists( 'Estandar_Forms_DB' ) ? (string) Estandar_Forms_DB::DB_VERSION : '';
			$forms_columns      = estandar_core_diag_table_columns( $wpdb->prefix . 'estandar_forms' );
			$forms_required_columns = [ 'mail_sender_name' ];
			$forms_missing_columns = array_values( array_diff( $forms_required_columns, $forms_columns ) );
			$forms_db_drift     = $forms_active && (
				( '' !== $forms_db_target && version_compare( $forms_db_version, $forms_db_target, '<' ) )
				|| ! empty( $forms_missing_columns )
			);
			$add(
				'forms_db_migration_state',
				'Forms schema version',
				! $forms_active ? 'WARNING' : ( $forms_db_drift ? 'FAIL' : 'PASS' ),
				! $forms_active ? 'Estandar Forms no detectado.' : 'Expected: ' . ( $forms_db_target ?: 'unknown' ) . ' Current: ' . ( $forms_db_version ?: 'empty' ) . ( empty( $forms_missing_columns ) ? '' : ' Missing columns: ' . implode( ', ', $forms_missing_columns ) ) . ' Code: ' . ( $forms_code_version ?: 'unknown' ),
				admin_url( 'admin.php?page=estandar-forms' )
			);

			$required_statuses = [ 'NEW', 'CONTACTED', 'QUALIFIED', 'VISITED', 'RESERVED', 'DEED_SIGNED', 'LOST' ];
			$statuses = class_exists( 'Estandar_Leads_Normalizer' ) ? Estandar_Leads_Normalizer::statuses() : [];
			$missing_statuses = array_values( array_diff( $required_statuses, array_map( 'strval', (array) $statuses ) ) );
			$add( 'lead_lifecycle', 'Lifecycle comercial disponible', empty( $missing_statuses ) ? 'PASS' : 'WARNING', empty( $missing_statuses ) ? implode( ', ', $required_statuses ) : 'Faltan: ' . implode( ', ', $missing_statuses ) );

			$leads_cols = estandar_core_diag_table_columns( $wpdb->prefix . 'es_leads' );
			$submission_cols = estandar_core_diag_table_columns( $wpdb->prefix . 'es_lead_submissions' );
			$lead_required = [ 'project_slug', 'first_session_id' ];
			$submission_required = [ 'session_id', 'context_json' ];
			$missing_lead_cols = array_values( array_diff( $lead_required, $leads_cols ) );
			$missing_submission_cols = array_values( array_diff( $submission_required, $submission_cols ) );
			$missing_persistence = array_merge( $missing_lead_cols, $missing_submission_cols );
			$add( 'lead_persistence', 'Leads preserva proyecto, sesión y contexto', empty( $missing_persistence ) ? 'PASS' : 'WARNING', empty( $missing_persistence ) ? 'project_slug, session_id, context_json' : 'Faltan: ' . implode( ', ', $missing_persistence ) );

			$legacy_forms = [];
			if ( ! function_exists( 'is_plugin_active' ) && defined( 'ABSPATH' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			if ( defined( 'WPCF7_VERSION' ) || is_plugin_active( 'contact-form-7/wp-contact-form-7.php' ) ) {
				$legacy_forms[] = 'CF7';
			}
			if ( is_plugin_active( 'flamingo/flamingo.php' ) ) {
				$legacy_forms[] = 'Flamingo';
			}
			if ( is_plugin_active( 'metform/metform.php' ) ) {
				$legacy_forms[] = 'MetForm';
			}
			if ( is_plugin_active( 'gravityforms/gravityforms.php' ) ) {
				$legacy_forms[] = 'Gravity Forms';
			}
			if ( is_plugin_active( 'wordpress-popup/popover.php' ) || is_plugin_active( 'hustle/wordpress-popup.php' ) ) {
				$legacy_forms[] = 'Hustle';
			}

			$failed = array_values( array_filter( $checks, static function ( array $check ): bool {
				return isset( $check['status'] ) && 'FAIL' === (string) $check['status'];
			} ) );
			$warnings = array_values( array_filter( $checks, static function ( array $check ): bool {
				return isset( $check['status'] ) && in_array( (string) $check['status'], [ 'WARNING', 'REQUIRES ATTENTION' ], true );
			} ) );
			$is_native = $forms_active && empty( $warnings ) && empty( $failed );
			$mode = $is_native ? 'NATIVE' : ( ! empty( $legacy_forms ) ? 'LEGACY' : 'COMPAT' );
			$status = empty( $failed ) ? ( empty( $warnings ) ? 'PASS' : 'PASS WITH WARNINGS' ) : 'FAIL';

			return [
				'status'       => $status,
				'mode'         => $mode,
				'legacy_forms' => array_values( array_unique( $legacy_forms ) ),
				'form_project_trace' => $form_project_trace,
				'checks'       => $checks,
				'failed'       => $failed,
				'warnings'     => $warnings,
			];
		}
	}

if ( ! function_exists( 'estandar_core_diag_compute_summary' ) ) {
	/**
	 * @return array{
	 *   status:string,
	 *   counts:array{HIGH:int,MEDIUM:int,LOW:int,TOTAL:int},
	 *   menu_count:int,
	 *   generated_at:int,
	 *   details:array<string,mixed>
	 * }
	 */
	function estandar_core_diag_compute_summary(): array {
			$levels = estandar_core_diag_rule_levels();
			$counts = [ 'HIGH' => 0, 'MEDIUM' => 0, 'LOW' => 0, 'TOTAL' => 0 ];
				$site_identity = estandar_core_diag_site_identity();
				if ( ! empty( $site_identity['issues'] ) ) {
					$counts['HIGH'] += count( (array) $site_identity['issues'] );
					$counts['TOTAL'] += count( (array) $site_identity['issues'] );
				}
				$deployment_validator = estandar_core_diag_deployment_validator();
				foreach ( (array) ( $deployment_validator['failed'] ?? [] ) as $deployment_issue ) {
					$counts['HIGH']++;
					$counts['TOTAL']++;
				}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$active = array_values( (array) get_option( 'active_plugins', [] ) );
		$all    = function_exists( 'get_plugins' ) ? (array) get_plugins() : [];

		$per_plugin = [];
		$handle_to_plugins = [];

		foreach ( $active as $plugin_file ) {
			$plugin_file = (string) $plugin_file;
			if ( ! estandar_core_diag_is_estandar_plugin( $plugin_file ) ) {
				continue;
			}

			$plugin_data = isset( $all[ $plugin_file ] ) && is_array( $all[ $plugin_file ] ) ? $all[ $plugin_file ] : [];
			$files = estandar_core_diag_collect_plugin_php_files( $plugin_file );

				$agg = [
					'duplicate_helpers'       => false,
					'weak_core_detection'     => false,
					'mentions_admin_ui'       => false,
					'mentions_wrapper'        => false,
					'mentions_core_signal'    => false,
				'mentions_layout_helpers' => false,
				'mentions_core_menu'      => false,
				'generic_ui_handle'       => false,
				'mentions_ecb_classes'    => false,
				'mentions_estandar_classes' => false,
				'handles'                 => [],
				'collision_handles'       => [],
				'shared_core_handle_misuse' => [],
			];

			$scan = estandar_core_scan_plugin_files( $files );
				$agg['duplicate_helpers']       = (bool) $scan['duplicate_helpers'];
				$agg['weak_core_detection']     = (bool) $scan['weak_core_detection'];
				$agg['mentions_admin_ui']       = (bool) $scan['mentions_admin_ui'];
				$agg['mentions_wrapper']        = (bool) $scan['mentions_wrapper'];
			$agg['mentions_core_signal']    = (bool) $scan['mentions_core_signal'];
			$agg['mentions_layout_helpers'] = (bool) $scan['mentions_layout_helpers'];
			$agg['mentions_core_menu']      = (bool) $scan['mentions_core_menu'];
			$agg['generic_ui_handle']       = (bool) $scan['generic_ui_handle'];
			$agg['mentions_ecb_classes']    = (bool) $scan['mentions_ecb_classes'];
			$agg['mentions_estandar_classes'] = (bool) $scan['mentions_estandar_classes'];
			if ( ! empty( $scan['handles'] ) ) {
				$agg['handles'] = array_values( array_unique( (array) $scan['handles'] ) );
			}
			if ( ! empty( $scan['collision_handles'] ) ) {
				$agg['collision_handles'] = array_values( array_unique( (array) $scan['collision_handles'] ) );
			}
			if ( ! empty( $scan['shared_core_handle_misuse'] ) ) {
				$agg['shared_core_handle_misuse'] = array_values( array_unique( (array) $scan['shared_core_handle_misuse'] ) );
			}

			$prefixes = estandar_core_diag_expected_handle_prefixes( $plugin_file, $plugin_data );
			$is_core  = estandar_core_diag_is_core_plugin( $plugin_file, $plugin_data );

				// Compute rule results for the summary.
				$r1_ok = $is_core ? true : ! $agg['weak_core_detection'];
				$r2_ok = ! $agg['mentions_admin_ui'] || (bool) ( $agg['mentions_wrapper'] || $agg['mentions_layout_helpers'] || $agg['mentions_estandar_classes'] || $agg['mentions_core_menu'] );
				$r4_ok = $is_core ? true : ! $agg['duplicate_helpers'];
			$r5_ok = $is_core ? true : ! $agg['generic_ui_handle'];
			$r6_ok = $is_core ? true : ( ! $agg['mentions_ecb_classes'] || ( $agg['mentions_wrapper'] && $agg['mentions_estandar_classes'] ) );

			$bad_prefix = [];
			foreach ( (array) $agg['handles'] as $h ) {
				$h = (string) $h;
				if ( '' === $h ) {
					continue;
				}
				if ( estandar_core_diag_is_shared_core_handle( $h ) && ( $is_core || ! in_array( $h, $agg['shared_core_handle_misuse'], true ) ) ) {
					continue;
				}
				if ( ! estandar_core_diag_handle_prefix_ok( $h, $plugin_file, $prefixes ) ) {
					$bad_prefix[] = $h;
				}
			}
			$bad_prefix = array_values( array_unique( $bad_prefix ) );

			foreach ( (array) $agg['collision_handles'] as $h ) {
				$h = (string) $h;
				$is_customish = ( 0 === strpos( $h, 'estandar' ) ) || ( 0 === strpos( $h, 'egm' ) ) || ( 0 === strpos( $h, 'wpe' ) );
				if ( ! $is_customish ) {
					continue;
				}
				if ( ! isset( $handle_to_plugins[ $h ] ) ) {
					$handle_to_plugins[ $h ] = [];
				}
				$handle_to_plugins[ $h ][] = $plugin_file;
			}

			$confidence_level = ( ! empty( $bad_prefix ) || ! $r2_ok || ! $r6_ok ) ? 'low' : 'high';

			$per_plugin[ $plugin_file ] = [
				'name'        => isset( $plugin_data['Name'] ) ? (string) $plugin_data['Name'] : $plugin_file,
				'prefixes'    => $prefixes,
				'rules'       => [
					'R1' => $r1_ok,
					'R2' => $r2_ok,
					'R3' => true, // computed after collisions
					'R4' => $r4_ok,
					'R5' => $r5_ok,
					'R6' => $r6_ok,
				],
				'bad_prefix'  => $bad_prefix,
				'collisions'  => [],
				'confidence_level' => $confidence_level,
			];
		}

		$duplicate_handles = [];
		foreach ( $handle_to_plugins as $handle => $plugins ) {
			$plugins = array_values( array_unique( array_map( 'strval', (array) $plugins ) ) );
			if ( count( $plugins ) > 1 ) {
				$duplicate_handles[ (string) $handle ] = $plugins;
			}
		}

		// Apply collisions to plugins (R3).
		foreach ( $duplicate_handles as $handle => $plugins ) {
			foreach ( $plugins as $plugin_file ) {
				if ( isset( $per_plugin[ $plugin_file ] ) ) {
					$per_plugin[ $plugin_file ]['rules']['R3'] = false;
					$per_plugin[ $plugin_file ]['collisions'][] = (string) $handle;
				}
			}
		}

		// Count rule violations by their canonical level (one per plugin per rule).
		foreach ( $per_plugin as $plugin_file => $row ) {
			$rules = isset( $row['rules'] ) && is_array( $row['rules'] ) ? $row['rules'] : [];
			foreach ( $levels as $rule => $level ) {
				$ok = isset( $rules[ $rule ] ) ? (bool) $rules[ $rule ] : true;
				if ( $ok ) {
					continue;
				}
				$counts[ $level ] = isset( $counts[ $level ] ) ? (int) $counts[ $level ] + 1 : 1;
				$counts['TOTAL']++;
			}

			// Bad prefix is heuristic; count it as LOW (one per plugin) only when present.
			if ( ! empty( $row['bad_prefix'] ) ) {
				$counts['LOW']++;
				$counts['TOTAL']++;
			}
		}

		$status = estandar_core_diag_status_from_counts( $counts );

		// Menu count: show only HIGH+MEDIUM issues (low is heuristic/noise).
		$menu_count = absint( $counts['HIGH'] ) + absint( $counts['MEDIUM'] );

		return [
			'status'       => $status,
			'counts'       => $counts,
			'menu_count'   => $menu_count,
			'generated_at' => time(),
				'details'      => [
					'per_plugin'        => $per_plugin,
					'duplicate_handles' => $duplicate_handles,
					'levels'            => $levels,
					'site_identity'     => $site_identity,
					'deployment_validator' => $deployment_validator,
				],
			];
		}
}

if ( ! function_exists( 'estandar_core_diag_get_summary' ) ) {
	function estandar_core_diag_get_summary(): array {
		$key = estandar_core_diag_summary_transient_key();

		$force_refresh = isset( $_GET['estandar_core_diag_refresh'] ) && current_user_can( 'manage_options' );
		if ( ! $force_refresh ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) && isset( $cached['status'], $cached['counts'] ) ) {
				return $cached;
			}
		}

		$summary = estandar_core_diag_compute_summary();
		set_transient( $key, $summary, 10 * MINUTE_IN_SECONDS );
		return $summary;
	}
}

if ( ! function_exists( 'estandar_core_diag_summary_explain_rows' ) ) {
	/**
	 * MENU_WARNING_EXPLAINER_V1 — traduce el summary del auditor (la MISMA
	 * fuente que enciende el badge "Action required" del menú) a hallazgos
	 * legibles: qué se detectó, en qué plugin y cómo se arregla.
	 * Regla UX transversal: el explicador deriva del mismo dataset que el badge.
	 *
	 * @return array<int,array{level:string,plugin:string,msg:string,fix:string,fix_url:string}>
	 */
	function estandar_core_diag_summary_explain_rows( array $summary ): array {
		$rows    = [];
		$details = isset( $summary['details'] ) && is_array( $summary['details'] ) ? $summary['details'] : [];
		$levels  = isset( $details['levels'] ) && is_array( $details['levels'] ) ? $details['levels'] : [];

		$rule_texts = [
			'R1' => [ 'Detección de Primer frágil (patrón débil de core-detection).', 'Usar el polyfill dinámico estandar_core_mu_is_active() / primer_is_active() en vez de comprobaciones estáticas.' ],
			'R2' => [ 'Pantalla admin sin el contenedor visual estándar (.estandar-admin).', 'Envolver la pantalla con el shell de Primer (estandar_core_render_admin_shell). Solo inconsistencia visual.' ],
			'R3' => [ 'Colisión de identificadores JS/CSS con otro plugin.', 'Renombrar el handle con el prefijo propio del plugin. Riesgo real de romper la UI admin.' ],
			'R4' => [ 'Helpers de core duplicados dentro del plugin.', 'Eliminar la copia local y consumir el helper de Primer (riesgo de drift).' ],
			'R5' => [ 'Handles JS/CSS genéricos o ambiguos.', 'Prefijar los handles con el nombre del plugin para evitar colisiones futuras.' ],
			'R6' => [ 'Usa clases legadas .ecb-* sin su par canónico .estandar-*.', 'Migrar a clases .estandar-* (las .ecb-* son compatibilidad temporal).' ],
		];

		// 1) Identidad del sitio (config del usuario, no del desarrollador).
		$site_identity = isset( $details['site_identity'] ) && is_array( $details['site_identity'] ) ? $details['site_identity'] : [];
		foreach ( (array) ( $site_identity['issues'] ?? [] ) as $issue ) {
			$rows[] = [
				'level'   => 'HIGH',
				'plugin'  => 'Configuración',
				'msg'     => (string) $issue,
				'fix'     => 'Completar en Configuración compartida → Identidad.',
				'fix_url' => admin_url( 'admin.php?page=estandar_core_diagnostics&tab=configuracion' ),
			];
		}

		// 2) Validador de deployment (checks FAIL).
		$deployment = isset( $details['deployment_validator'] ) && is_array( $details['deployment_validator'] ) ? $details['deployment_validator'] : [];
		foreach ( (array) ( $deployment['failed'] ?? [] ) as $check ) {
			if ( ! is_array( $check ) ) {
				continue;
			}
			$rows[] = [
				'level'   => 'HIGH',
				'plugin'  => 'Deployment',
				'msg'     => (string) ( $check['label'] ?? $check['key'] ?? 'Check fallido' ) . ( '' !== (string) ( $check['evidence'] ?? '' ) ? ' — ' . (string) $check['evidence'] : '' ),
				'fix'     => 'Revisar el check en Estado del ecosistema.',
				'fix_url' => (string) ( $check['fix_url'] ?? '' ),
			];
		}

		// 3) Auditoría de integridad por plugin (reglas R1–R6 + prefijos).
		$per_plugin = isset( $details['per_plugin'] ) && is_array( $details['per_plugin'] ) ? $details['per_plugin'] : [];
		foreach ( $per_plugin as $plugin_file => $row ) {
			$plugin_name = (string) ( $row['name'] ?? $plugin_file );
			foreach ( (array) ( $row['rules'] ?? [] ) as $rule => $ok ) {
				if ( $ok || ! isset( $rule_texts[ $rule ] ) ) {
					continue;
				}
				$msg = $rule_texts[ $rule ][0];
				if ( 'R3' === $rule && ! empty( $row['collisions'] ) ) {
					$msg .= ' Handles: ' . implode( ', ', array_slice( array_map( 'strval', (array) $row['collisions'] ), 0, 5 ) ) . '.';
				}
				$rows[] = [
					'level'   => (string) ( $levels[ $rule ] ?? 'MEDIUM' ),
					'plugin'  => $plugin_name,
					'msg'     => $msg,
					'fix'     => $rule_texts[ $rule ][1],
					'fix_url' => '',
				];
			}
			if ( ! empty( $row['bad_prefix'] ) ) {
				$rows[] = [
					'level'   => 'LOW',
					'plugin'  => $plugin_name,
					'msg'     => 'Handles sin prefijo del plugin: ' . implode( ', ', array_slice( array_map( 'strval', (array) $row['bad_prefix'] ), 0, 6 ) ) . ( count( (array) $row['bad_prefix'] ) > 6 ? '…' : '' ) . '.',
					'fix'     => 'Prefijar los handles (heurístico, severidad baja).',
					'fix_url' => '',
				];
			}
		}

		// Severidad primero (HIGH → MEDIUM → LOW), igual que el conteo del menú.
		$order = [ 'HIGH' => 0, 'MEDIUM' => 1, 'LOW' => 2 ];
		usort( $rows, static function ( array $a, array $b ) use ( $order ): int {
			return ( $order[ $a['level'] ] ?? 3 ) <=> ( $order[ $b['level'] ] ?? 3 );
		} );

		return $rows;
	}
}

if ( ! function_exists( 'estandar_core_render_diag_menu_warning_explainer' ) ) {
	/**
	 * Renderiza el bloque "por qué el menú muestra Atención requerida".
	 * $compact=true → tira colapsable (<details>) para el Panel; false → lista
	 * completa para Estado del ecosistema. Sin JS, sin estado, misma fuente
	 * que el badge del menú (estandar_core_diag_get_summary()).
	 */
	function estandar_core_render_diag_menu_warning_explainer( bool $compact = true ): void {
		if ( ! current_user_can( 'manage_options' ) || ! function_exists( 'estandar_core_diag_get_summary' ) ) {
			return;
		}

		$summary = estandar_core_diag_get_summary();
		$status  = (string) ( $summary['status'] ?? 'OK' );
		if ( 'OK' === $status ) {
			return;
		}

		$rows = estandar_core_diag_summary_explain_rows( $summary );
		if ( empty( $rows ) ) {
			return;
		}

		$counts      = isset( $summary['counts'] ) && is_array( $summary['counts'] ) ? $summary['counts'] : [];
		$menu_count  = absint( $summary['menu_count'] ?? 0 );
		$refresh_url = add_query_arg( 'estandar_core_diag_refresh', '1' );
		$eco_url     = admin_url( 'admin.php?page=estandar_core_diagnostics&tab=estado_ecosistema' );
		$level_chip  = static function ( string $level ): string {
			$map = [
				'HIGH'   => 'background:#dc2626;color:#fff;',
				'MEDIUM' => 'background:#b7791f;color:#fff;',
				'LOW'    => 'background:#e5e7eb;color:#374151;',
			];
			$label = [ 'HIGH' => 'Alta', 'MEDIUM' => 'Media', 'LOW' => 'Baja' ][ $level ] ?? $level;
			return '<span style="flex-shrink:0;padding:1px 7px;border-radius:3px;font-size:10px;font-weight:700;letter-spacing:.04em;' . ( $map[ $level ] ?? $map['LOW'] ) . '">' . esc_html( $label ) . '</span>';
		};

		$list = '<div style="display:flex;flex-direction:column;gap:6px;">';
		$shown = $compact ? array_slice( $rows, 0, 8 ) : $rows;
		foreach ( $shown as $r ) {
			$list .= '<div style="display:flex;align-items:baseline;gap:8px;padding:7px 10px;background:#fff;border:1px solid #f0f0f1;border-radius:5px;font-size:12px;">';
			$list .= $level_chip( (string) $r['level'] );
			$list .= '<span style="color:#1d2327;font-weight:600;flex-shrink:0;">' . esc_html( (string) $r['plugin'] ) . '</span>';
			$list .= '<span style="color:#50575e;">' . esc_html( (string) $r['msg'] ) . ' <em style="color:#787c82;font-style:normal;">Cómo se arregla: ' . esc_html( (string) $r['fix'] ) . '</em></span>';
			if ( '' !== (string) $r['fix_url'] ) {
				$list .= '<a class="button button-small" style="margin-left:auto;flex-shrink:0;" href="' . esc_url( (string) $r['fix_url'] ) . '">Resolver</a>';
			}
			$list .= '</div>';
		}
		if ( $compact && count( $rows ) > 8 ) {
			$list .= '<a href="' . esc_url( $eco_url ) . '" style="font-size:12px;">Ver los ' . count( $rows ) . ' hallazgos →</a>';
		}
		$list .= '</div>';
		$list .= '<p style="margin:8px 0 0;font-size:11px;color:#787c82;">Este es el mismo análisis que enciende el aviso del menú lateral (se recalcula cada 10 minutos). '
			. '<a href="' . esc_url( $refresh_url ) . '">Re-verificar ahora</a>'
			. ( $compact ? ' · <a href="' . esc_url( $eco_url ) . '">Estado del ecosistema →</a>' : '' )
			. '</p>';

		$title = sprintf(
			'El menú muestra "Atención requerida" por %d hallazgo%s del auditor de integridad',
			$menu_count > 0 ? $menu_count : count( $rows ),
			( $menu_count > 0 ? $menu_count : count( $rows ) ) === 1 ? '' : 's'
		);
		$sub = sprintf(
			'%d alta · %d media · %d baja (el contador del menú suma alta+media)',
			absint( $counts['HIGH'] ?? 0 ),
			absint( $counts['MEDIUM'] ?? 0 ),
			absint( $counts['LOW'] ?? 0 )
		);

		if ( $compact ) {
			echo '<details style="margin-bottom:10px;background:#fff8e1;border:1px solid #fde68a;border-radius:6px;">';
			echo '<summary style="cursor:pointer;padding:9px 12px;font-size:12px;color:#92400e;font-weight:600;">🟡 ' . esc_html( $title ) . ' <span style="font-weight:400;color:#b7791f;">— ' . esc_html( $sub ) . ' · click para ver el detalle</span></summary>';
			echo '<div style="padding:4px 12px 12px;">' . $list . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</details>';
		} else {
			echo '<div class="es-diag-section" style="margin-bottom:12px;">';
			echo '<div class="es-diag-section-header">🟡 ' . esc_html( $title ) . '</div>';
			echo '<div class="es-diag-section-body" style="padding:10px 16px;">';
			echo '<p style="margin:0 0 8px;font-size:12px;color:#646970;">' . esc_html( $sub ) . '</p>';
			echo $list; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</div></div>';
		}
	}
}

if ( ! function_exists( 'estandar_core_audit_dev_enabled' ) ) {
	function estandar_core_audit_dev_enabled(): bool {
		return is_admin() && defined( 'WP_DEBUG' ) && WP_DEBUG;
	}
}

if ( ! function_exists( 'estandar_core_audit_strict_enabled' ) ) {
	function estandar_core_audit_strict_enabled(): bool {
		return estandar_core_audit_dev_enabled() && defined( 'ESTANDAR_AUDIT_STRICT' ) && ESTANDAR_AUDIT_STRICT;
	}
}

if ( ! function_exists( 'estandar_core_audit_dev_issues' ) ) {
	/**
	 * @return string[]
	 */
	function estandar_core_audit_dev_issues( array $summary ): array {
		$details = isset( $summary['details'] ) && is_array( $summary['details'] ) ? $summary['details'] : [];
		$rows    = isset( $details['per_plugin'] ) && is_array( $details['per_plugin'] ) ? $details['per_plugin'] : [];
		$issues  = [];

		foreach ( $rows as $plugin_file => $row ) {
			$plugin_file = (string) $plugin_file;
			$rules = isset( $row['rules'] ) && is_array( $row['rules'] ) ? $row['rules'] : [];
			$bad_prefix = isset( $row['bad_prefix'] ) && is_array( $row['bad_prefix'] ) ? array_values( array_filter( array_map( 'strval', $row['bad_prefix'] ) ) ) : [];

			if ( isset( $rules['R2'] ) && ! (bool) $rules['R2'] ) {
				$issues[] = $plugin_file . ': R2 falta .estandar-admin.';
			}
			if ( isset( $rules['R6'] ) && ! (bool) $rules['R6'] ) {
				$issues[] = $plugin_file . ': R6 uso .ecb-* sin par canonico .estandar-*.';
			}
			if ( ! empty( $bad_prefix ) ) {
				$issues[] = $plugin_file . ': handles no prefijados: ' . implode( ', ', array_slice( $bad_prefix, 0, 6 ) ) . ( count( $bad_prefix ) > 6 ? '...' : '' ) . '.';
			}
		}

		return array_values( array_unique( $issues ) );
	}
}

if ( ! function_exists( 'estandar_core_audit_dev_warn' ) ) {
	function estandar_core_audit_dev_warn(): void {
		if ( ! estandar_core_audit_dev_enabled() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$issues = estandar_core_audit_dev_issues( estandar_core_diag_get_summary() );
		if ( empty( $issues ) ) {
			return;
		}

		$hash = md5( implode( "\n", $issues ) );
		$key  = 'estandar_core_audit_dev_warn_' . $hash;
		if ( ! get_transient( $key ) ) {
			error_log( '[Estandar Audit] ' . implode( ' | ', $issues ) );
			set_transient( $key, 1, 10 * MINUTE_IN_SECONDS );
		}
	}
}

if ( ! function_exists( 'estandar_core_audit_admin_notice' ) ) {
	function estandar_core_audit_admin_notice(): void {
		if ( ! estandar_core_audit_dev_enabled() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Developer diagnostics only — do not surface on the Executive Dashboard or unrelated pages.
		$current_page = isset( $_GET['page'] ) ? sanitize_key( (string) $_GET['page'] ) : '';
		$current_tab  = isset( $_GET['tab'] )  ? sanitize_key( (string) $_GET['tab'] )  : 'panel';
		if ( $current_page !== 'estandar_core_diagnostics' || in_array( $current_tab, [ 'panel', 'configuracion' ], true ) ) {
			return;
		}

		$issues = estandar_core_audit_dev_issues( estandar_core_diag_get_summary() );
		if ( empty( $issues ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p><strong>Estandar Audit DEV:</strong> ' . esc_html( implode( ' ', array_slice( $issues, 0, 3 ) ) ) . '</p></div>';
	}
}

if ( ! function_exists( 'estandar_core_audit_plugin_file_from_src' ) ) {
	function estandar_core_audit_plugin_file_from_src( string $src ): string {
		if ( ! defined( 'WP_PLUGIN_URL' ) || ! defined( 'WP_PLUGIN_DIR' ) || '' === $src ) {
			return '';
		}

		$src_path = (string) wp_parse_url( $src, PHP_URL_PATH );
		$base_path = (string) wp_parse_url( (string) WP_PLUGIN_URL, PHP_URL_PATH );
		if ( '' === $src_path || '' === $base_path || 0 !== strpos( $src_path, rtrim( $base_path, '/' ) . '/' ) ) {
			return '';
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$rel = ltrim( substr( $src_path, strlen( rtrim( $base_path, '/' ) ) ), '/' );
		$active = array_values( (array) get_option( 'active_plugins', [] ) );
		$all = function_exists( 'get_plugins' ) ? (array) get_plugins() : [];
		foreach ( $active as $plugin_file ) {
			$plugin_file = (string) $plugin_file;
			if ( ! isset( $all[ $plugin_file ] ) || ! estandar_core_diag_is_estandar_plugin( $plugin_file ) ) {
				continue;
			}
			$plugin_dir = dirname( $plugin_file );
			if ( '.' !== $plugin_dir && 0 === strpos( $rel, $plugin_dir . '/' ) ) {
				return $plugin_file;
			}
		}

		return '';
	}
}

if ( ! function_exists( 'estandar_core_audit_strict_enforce_queue' ) ) {
	function estandar_core_audit_strict_enforce_queue(): void {
		if ( ! estandar_core_audit_strict_enabled() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen && method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) {
				return;
			}
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all = function_exists( 'get_plugins' ) ? (array) get_plugins() : [];
		$blocked = [];

		foreach ( [ 'script', 'style' ] as $type ) {
			$registry = ( 'script' === $type ) ? wp_scripts() : wp_styles();
			foreach ( array_values( (array) $registry->queue ) as $handle ) {
				$handle = (string) $handle;
				$registered = isset( $registry->registered[ $handle ] ) ? $registry->registered[ $handle ] : null;
				$src = ( $registered && isset( $registered->src ) ) ? (string) $registered->src : '';
				$plugin_file = estandar_core_audit_plugin_file_from_src( $src );
				if ( '' === $plugin_file || estandar_core_diag_is_core_plugin( $plugin_file, isset( $all[ $plugin_file ] ) && is_array( $all[ $plugin_file ] ) ? $all[ $plugin_file ] : [] ) ) {
					continue;
				}
				$prefixes = estandar_core_diag_expected_handle_prefixes( $plugin_file, isset( $all[ $plugin_file ] ) && is_array( $all[ $plugin_file ] ) ? $all[ $plugin_file ] : [] );
				if ( estandar_core_diag_handle_prefix_ok( $handle, $plugin_file, $prefixes ) ) {
					continue;
				}

				if ( 'script' === $type ) {
					wp_dequeue_script( $handle );
				} else {
					wp_dequeue_style( $handle );
				}
				$blocked[] = $plugin_file . ' / ' . $handle;
			}
		}

		if ( ! empty( $blocked ) ) {
			set_transient( 'estandar_core_audit_strict_blocked', array_values( array_unique( $blocked ) ), 2 * MINUTE_IN_SECONDS );
		}
	}
}

if ( ! function_exists( 'estandar_core_audit_strict_notice' ) ) {
	function estandar_core_audit_strict_notice(): void {
		if ( ! estandar_core_audit_strict_enabled() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$blocked = get_transient( 'estandar_core_audit_strict_blocked' );
		if ( empty( $blocked ) || ! is_array( $blocked ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p><strong>Estandar Audit STRICT:</strong> bloqueado enqueue admin no prefijado: ' . esc_html( implode( ', ', array_slice( array_map( 'strval', $blocked ), 0, 6 ) ) ) . '</p></div>';
	}
}

if ( is_admin() ) {
	add_action( 'admin_init', 'estandar_core_audit_dev_warn', 30 );
	add_action( 'admin_notices', 'estandar_core_audit_admin_notice' );
	add_action( 'admin_enqueue_scripts', 'estandar_core_audit_strict_enforce_queue', PHP_INT_MAX );
	add_action( 'admin_notices', 'estandar_core_audit_strict_notice' );
}

if ( ! function_exists( 'estandar_core_scan_plugin_file' ) ) {
	/**
	 * @return array{
	 *   duplicate_helpers:bool,
	 *   weak_core_detection:bool,
	 *   mentions_wrapper:bool,
	 *   mentions_core_signal:bool,
	 *   generic_ui_handle:bool,
	 *   mentions_ecb_classes:bool,
	 *   mentions_estandar_classes:bool,
	 *   handles:string[]
	 * }
	 */
	function estandar_core_scan_plugin_file( string $abs_file ): array {
		return estandar_core_scan_plugin_files( [ $abs_file ] );
	}
}

if ( ! function_exists( 'estandar_core_scan_plugin_files' ) ) {
	/**
	 * @param string[] $abs_files
	 * @return array{
	 *   duplicate_helpers:bool,
	 *   weak_core_detection:bool,
	 *   mentions_wrapper:bool,
	 *   mentions_core_signal:bool,
	 *   generic_ui_handle:bool,
	 *   mentions_ecb_classes:bool,
	 *   mentions_estandar_classes:bool,
	 *   handles:string[]
	 * }
	 */
	function estandar_core_scan_plugin_files( array $abs_files ): array {
		$content = '';
		$seen = [];
		$max_bytes = 2 * 1024 * 1024;

		foreach ( $abs_files as $abs_file ) {
			$abs_file = (string) $abs_file;
			if ( '' === $abs_file || isset( $seen[ $abs_file ] ) || ! is_readable( $abs_file ) || ! is_file( $abs_file ) ) {
				continue;
			}
			$seen[ $abs_file ] = true;

			$size = filesize( $abs_file );
			if ( false === $size || $size <= 0 || $size > $max_bytes ) {
				continue;
			}
			if ( strlen( $content ) + (int) $size > $max_bytes ) {
				break;
			}

			$file_content = (string) file_get_contents( $abs_file );
			if ( false !== strpos( $file_content, "\0" ) ) {
				continue;
			}
			$content .= "\n" . $file_content;
		}

		$class_content = estandar_core_strip_audit_comments( $content );

		$handles = [];
		$collision_handles = [];
		$shared_core_handle_misuse = [];
		preg_match_all(
			'/wp_(enqueue|register)_(style|script)\\s*\\(\\s*([\'"])([^\'"]+)\\3\\s*(,)?/',
			$content,
			$m,
			PREG_SET_ORDER
		);
		foreach ( $m as $match ) {
			$action = isset( $match[1] ) ? (string) $match[1] : '';
			$handle = isset( $match[4] ) ? (string) $match[4] : '';
			$has_more_args = ! empty( $match[5] );
			if ( '' === $handle ) {
				continue;
			}

			$handles[] = $handle;

			if ( estandar_core_diag_is_shared_core_handle( $handle ) ) {
				$is_consumer_only = ( 'enqueue' === $action && ! $has_more_args );
				if ( ! $is_consumer_only ) {
					$collision_handles[] = $handle;
					$shared_core_handle_misuse[] = $handle;
				}
				continue;
			}

			$collision_handles[] = $handle;
		}
		$handles = array_values( array_unique( $handles ) );
		$collision_handles = array_values( array_unique( $collision_handles ) );
		$shared_core_handle_misuse = array_values( array_unique( $shared_core_handle_misuse ) );

		return [
				'duplicate_helpers'       => (bool) preg_match( '/function\\s+estandar_admin_menu_register_parent\\s*\\(/', $content )
					|| (bool) preg_match( '/function\\s+estandar_admin_menu_add_link\\s*\\(/', $content )
					|| (bool) preg_match( '/function\\s+estandar_register_submenu\\s*\\(/', $content ),
				'weak_core_detection'     => false !== strpos( $content, "function_exists('estandar_admin_menu_register_parent')" )
					|| false !== strpos( $content, 'function_exists("estandar_admin_menu_register_parent")' )
					|| false !== strpos( $content, "function_exists('estandar_admin_menu_add_link')" )
					|| false !== strpos( $content, 'function_exists("estandar_admin_menu_add_link")' ),
				'mentions_admin_ui'       => (bool) preg_match( '/\\badd_(?:menu|submenu|options|management|theme|plugins|users|dashboard)_page\\s*\\(/', $content )
					|| false !== strpos( $content, 'admin_enqueue_scripts' )
					|| false !== strpos( $content, 'estandar_admin_menu_add_link' )
					|| false !== strpos( $content, 'estandar_register_submenu' )
					|| false !== strpos( $content, 'settings_fields(' ),
				'mentions_wrapper'        => false !== strpos( $class_content, 'estandar-admin' ),
				'mentions_core_signal'    => false !== strpos( $content, 'estandar_core_mu_is_active' ),
			'mentions_layout_helpers' => false !== strpos( $content, 'estandar_render_layout_start' )
				|| false !== strpos( $content, 'estandar_render_header' ),
			'mentions_core_menu'      => false !== strpos( $content, 'estandar_register_submenu' )
				|| false !== strpos( $content, 'estandar_main_menu' ),
			'generic_ui_handle'       => in_array( 'estandar-admin-ui', $shared_core_handle_misuse, true ) || (bool) array_filter( $handles, 'estandar_core_diag_is_ambiguous_handle' ),
			'mentions_ecb_classes'    => (bool) preg_match( '/\\becb-[a-z0-9_-]+\\b/i', $class_content ),
			'mentions_estandar_classes' => (bool) preg_match( '/\\bestandar-(?!admin\\b)[a-z0-9_-]+\\b/i', $class_content ),
			'handles'                 => $handles,
			'collision_handles'       => $collision_handles,
			'shared_core_handle_misuse' => $shared_core_handle_misuse,
		];
	}
}

if ( ! function_exists( 'estandar_core_diag_shared_core_handles' ) ) {
	/**
	 * @return string[]
	 */
	function estandar_core_diag_shared_core_handles(): array {
		return [ 'estandar-admin-ui' ];
	}
}

if ( ! function_exists( 'estandar_core_diag_is_shared_core_handle' ) ) {
	function estandar_core_diag_is_shared_core_handle( string $handle ): bool {
		return in_array( $handle, estandar_core_diag_shared_core_handles(), true );
	}
}

if ( ! function_exists( 'estandar_core_strip_audit_comments' ) ) {
	function estandar_core_strip_audit_comments( string $content ): string {
		$filtered = '';
		if ( function_exists( 'token_get_all' ) ) {
			foreach ( token_get_all( $content ) as $token ) {
				if ( is_array( $token ) ) {
					if ( in_array( $token[0], [ T_COMMENT, T_DOC_COMMENT ], true ) ) {
						$filtered .= ' ';
						continue;
					}
					$filtered .= (string) $token[1];
					continue;
				}
				$filtered .= (string) $token;
			}
		} else {
			$filtered = (string) preg_replace( '#/\\*.*?\\*/|//[^\\r\\n]*#s', ' ', $content );
		}

		return (string) preg_replace( '/<!--.*?-->/s', ' ', $filtered );
	}
}

if ( ! function_exists( 'estandar_core_diag_is_allowed_legacy_handle' ) ) {
	function estandar_core_diag_is_allowed_legacy_handle( string $handle, string $plugin_file ): bool {
		$plugin_file = (string) $plugin_file;
		$handle      = (string) $handle;

		$allowed = [
			'estandar-carrusel/estandar-carousel.php' => [
				'estandar-carousel',
				'estandar-carousel-admin-ui',
				'estandar-carousel-block-editor',
			],
			'estandar-cpt-manager/estandar-cpt-manager.php' => [
				'estandar-cpt-gallery-block',
				'estandar-cpt-category-gallery-block',
			],
			'estandar-forms/estandar-forms.php' => [
				'estandar-rut',
				'estandar-recaptcha',
			],
		];

		return isset( $allowed[ $plugin_file ] ) && in_array( $handle, $allowed[ $plugin_file ], true );
	}
}

if ( ! function_exists( 'estandar_core_diag_is_customish_handle' ) ) {
	function estandar_core_diag_is_customish_handle( string $handle ): bool {
		return ( 0 === strpos( $handle, 'estandar' ) )
			|| ( 0 === strpos( $handle, 'egm' ) )
			|| ( 0 === strpos( $handle, 'wpe' ) );
	}
}

if ( ! function_exists( 'estandar_core_diag_is_standard_asset_handle' ) ) {
	function estandar_core_diag_is_standard_asset_handle( string $handle ): bool {
		$handle = (string) $handle;
		if ( ! (bool) preg_match( '/^estandar-[a-z0-9]+-[a-z0-9]+(-[a-z0-9]+)?$/', $handle ) ) {
			return false;
		}

		$parts = explode( '-', $handle );
		if ( count( $parts ) < 3 ) {
			return false;
		}

		$generic_names = [ 'admin', 'style', 'script' ];
		if ( in_array( $parts[1], $generic_names, true ) ) {
			return false;
		}

		return ! ( 3 === count( $parts ) && in_array( $parts[2], [ 'admin', 'frontend', 'style', 'script' ], true ) );
	}
}

if ( ! function_exists( 'estandar_core_diag_is_ambiguous_handle' ) ) {
	function estandar_core_diag_is_ambiguous_handle( string $handle ): bool {
		$handle = (string) $handle;
		if ( 'admin-ui' === $handle ) {
			return true;
		}
		if ( 0 !== strpos( $handle, 'estandar-' ) ) {
			return false;
		}
		if ( estandar_core_diag_is_shared_core_handle( $handle ) || estandar_core_diag_is_standard_asset_handle( $handle ) ) {
			return false;
		}

		$parts = explode( '-', $handle );
		if ( count( $parts ) < 3 ) {
			return true;
		}

		$generic_names = [ 'admin', 'style', 'script' ];
		return in_array( $parts[1], $generic_names, true )
			|| ( 3 === count( $parts ) && in_array( $parts[2], [ 'admin', 'frontend', 'style', 'script' ], true ) );
	}
}

if ( ! function_exists( 'estandar_core_diag_handle_prefix_ok' ) ) {
	/**
	 * @param string[] $prefixes
	 */
	function estandar_core_diag_handle_prefix_ok( string $handle, string $plugin_file, array $prefixes ): bool {
		$handle = (string) $handle;
		if ( '' === $handle || ! estandar_core_diag_is_customish_handle( $handle ) ) {
			return true;
		}

		foreach ( $prefixes as $p ) {
			if ( 0 === strpos( $handle, (string) $p ) ) {
				return true;
			}
		}

		if ( estandar_core_diag_is_standard_asset_handle( $handle ) ) {
			return true;
		}

		$base_handle = basename( dirname( $plugin_file ) );
		if ( $handle === $base_handle || $handle === str_replace( '-', '_', $base_handle ) ) {
			return true;
		}

		return estandar_core_diag_is_allowed_legacy_handle( $handle, $plugin_file );
	}
}

if ( ! function_exists( 'estandar_core_diag_is_estandar_plugin' ) ) {
	function estandar_core_diag_is_estandar_plugin( string $plugin_file ): bool {
		$plugin_file = (string) $plugin_file;
		$dir  = dirname( $plugin_file );
		$base = basename( $dir );

		return ( 0 === strpos( $plugin_file, 'estandar-' ) )
			|| ( 0 === strpos( $base, 'estandar-' ) )
			|| ( 0 === strpos( $plugin_file, 'estandar_' ) )
			|| ( 0 === strpos( $base, 'estandar_' ) );
	}
}

if ( ! function_exists( 'estandar_core_diag_collect_plugin_php_files' ) ) {
	/**
	 * @return string[] absolute php paths (limited)
	 */
	function estandar_core_diag_collect_plugin_php_files( string $plugin_file ): array {
		if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
			return [];
		}

		$plugin_file = ltrim( (string) $plugin_file, '/' );
		$main_abs = WP_PLUGIN_DIR . '/' . $plugin_file;
		$plugin_dir = dirname( $main_abs );

		$files = [];
		if ( is_file( $main_abs ) ) {
			$files[] = $main_abs;
		}

		if ( ! is_dir( $plugin_dir ) ) {
			return $files;
		}

		// Diagnostics must be safe/fast: scan only a bounded set of PHP files in likely implementation paths.
		$max_files = 80;
		$exclude_dirs = [ '.git', 'node_modules', 'vendor', 'dist', 'build', 'languages' ];
		$include_hint = [ '/admin/', '/includes/', '/templates/' ];

		try {
			$it = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $plugin_dir, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::SELF_FIRST
			);
		} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			return $files;
		}

		foreach ( $it as $item ) {
			if ( count( $files ) >= $max_files ) {
				break;
			}
			if ( ! $item instanceof SplFileInfo ) {
				continue;
			}

			$path = (string) $item->getPathname();

			// Skip excluded dirs.
			foreach ( $exclude_dirs as $ex ) {
				if ( false !== strpos( $path, '/' . $ex . '/' ) ) {
					continue 2;
				}
			}

			if ( $item->isDir() ) {
				continue;
			}
			if ( strtolower( (string) $item->getExtension() ) !== 'php' ) {
				continue;
			}

			$hint_ok = false;
			foreach ( $include_hint as $hint ) {
				if ( false !== strpos( $path, $hint ) ) {
					$hint_ok = true;
					break;
				}
			}
			if ( ! $hint_ok ) {
				continue;
			}

			$files[] = $path;
		}

		return array_values( array_unique( $files ) );
	}
}

if ( ! function_exists( 'estandar_core_diag_is_core_plugin' ) ) {
	/**
	 * Detecta el Core mismo para evitar falsos positivos en diagnósticos (R1/R4/prefijos).
	 *
	 * @param array<string,mixed> $plugin_data
	 */
	function estandar_core_diag_is_core_plugin( string $plugin_file, array $plugin_data ): bool {
		$plugin_file = (string) $plugin_file;
		$name = isset( $plugin_data['Name'] ) ? (string) $plugin_data['Name'] : '';

		if ( '' !== $name && false !== stripos( $name, 'Estandar Primer' ) ) {
			return true;
		}

		return false !== strpos( $plugin_file, 'estandar-core-mu/' )
			|| false !== strpos( $plugin_file, 'estandar-core/' )
			|| false !== strpos( $plugin_file, 'estandar-core.php' );
	}
}

if ( ! function_exists( 'estandar_core_diag_expected_handle_prefixes' ) ) {
	/**
	 * @param array<string,mixed> $plugin_data
	 * @return string[] prefixes (each should include trailing '-' or '_')
	 */
	function estandar_core_diag_expected_handle_prefixes( string $plugin_file, array $plugin_data ): array {
		$dir = dirname( (string) $plugin_file );
		$base = basename( $dir );

		$prefixes = [
			$base . '-',
			str_replace( '-', '_', $base ) . '_',
		];

		// Core usa `estandar-core-*` por diseño; aceptar al evaluar prefijos en el Core mismo.
		if ( estandar_core_diag_is_core_plugin( $plugin_file, $plugin_data ) ) {
			$prefixes[] = 'estandar-core-';
			$prefixes[] = 'estandar_core_';
		}

		/**
		 * Filter to allow plugins to declare additional accepted prefixes (non-invasive).
		 *
		 * @param string[] $prefixes
		 */
		$prefixes = (array) apply_filters( 'estandar_core_expected_handle_prefixes', $prefixes, $plugin_file, $plugin_data );

		$prefixes = array_values(
			array_filter(
				array_map( 'strval', $prefixes ),
				static function ( string $p ): bool {
					return '' !== $p;
				}
			)
		);

		return array_values( array_unique( $prefixes ) );
	}
}

if ( ! function_exists( 'estandar_core_diag_project_context' ) ) {
	function estandar_core_diag_project_context(): array {
		global $wpdb;

		$registry = function_exists( 'estandar_chatbot_get_project_registry' )
			? estandar_chatbot_get_project_registry()
			: (array) get_option( 'chatbot_project_registry', [] );
		$active_projects = array_values( array_filter( (array) $registry, static function ( $project ): bool {
			return is_array( $project ) && (bool) ( $project['active'] ?? true );
		} ) );
		$active_slugs = array_values( array_filter( array_map( 'strval', array_column( $active_projects, 'project_slug' ) ) ) );

		$operators_table = $wpdb->prefix . 'es_operators';
		$table_exists    = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $operators_table ) );
			$op_rows         = $table_exists ? $wpdb->get_results( "SELECT project_slug, whatsapp_number, allowed_projects_json FROM {$operators_table} WHERE active = 1", ARRAY_A ) : [];
			$op_rows         = is_array( $op_rows ) ? $op_rows : [];
			$slugs_with_operator = [];
			$resolved_slugs      = [];
			foreach ( $op_rows as $op ) {
				$operator_slugs = [];
				$slug = sanitize_title( remove_accents( (string) ( $op['project_slug'] ?? '' ) ) );
				if ( '' !== $slug ) {
					$operator_slugs[] = $slug;
				}
				$allowed = json_decode( (string) ( $op['allowed_projects_json'] ?? '' ), true );
				if ( is_array( $allowed ) ) {
					foreach ( $allowed as $allowed_slug ) {
						$allowed_slug = sanitize_title( remove_accents( (string) $allowed_slug ) );
						if ( '' !== $allowed_slug ) {
							$operator_slugs[] = $allowed_slug;
						}
					}
				}
				foreach ( array_values( array_unique( $operator_slugs ) ) as $operator_slug ) {
					$slugs_with_operator[] = $operator_slug;
					if ( ! empty( $op['whatsapp_number'] ) ) {
						$resolved_slugs[] = $operator_slug;
					}
				}
			}
			$slugs_with_operator = array_values( array_unique( $slugs_with_operator ) );
			$resolved_slugs      = array_values( array_unique( $resolved_slugs ) );

		$missing_patterns = array_values( array_filter( $active_projects, static function ( $project ): bool {
			return empty( $project['url_patterns'] );
		} ) );

		return [
			'missing_whatsapp'     => array_values( array_diff( $active_slugs, $slugs_with_operator ) ),
			'missing_url_patterns' => array_values( array_filter( array_map( 'strval', array_column( $missing_patterns, 'project_slug' ) ) ) ),
			'routing_fallback'     => $table_exists ? array_values( array_diff( $active_slugs, $resolved_slugs ) ) : [],
		];
	}
}

if ( ! function_exists( 'estandar_core_diag_action_url' ) ) {
	function estandar_core_diag_action_url( string $diagnostic, array $projects ): string {
		if ( in_array( $diagnostic, [ 'missing_whatsapp', 'routing_fallback' ], true ) && ! estandar_core_leads_settings_available() ) {
			return '';
		}

		$focus_project = sanitize_key( (string) ( $projects[0] ?? '' ) );
		$args = [
			'diagnostic' => $diagnostic,
			'projects'   => implode( ',', array_map( 'strval', $projects ) ),
			'focus_project' => $focus_project,
		];
		if ( 'missing_url_patterns' === $diagnostic ) {
			$args = array_merge( [ 'page' => 'estandar_projects', 'action' => 'edit', 'project_id' => $focus_project ], $args );
		} elseif ( 'routing_fallback' === $diagnostic ) {
			$args = array_merge( [ 'page' => 'estandar-leads-settings', 'project_slug' => $focus_project ], $args );
		} else {
			$args = array_merge( [ 'page' => 'estandar-leads-settings', 'project_slug' => $focus_project ], $args );
		}
		$url = add_query_arg( $args, admin_url( 'admin.php' ) );
		return 'missing_url_patterns' === $diagnostic ? $url . '#estandar-project-url-patterns' : $url;
	}
}

if ( ! function_exists( 'estandar_core_leads_settings_available' ) ) {
	function estandar_core_leads_settings_available(): bool {
		return class_exists( 'Estandar_Leads_Admin' );
	}
}

if ( ! function_exists( 'estandar_core_diag_operations' ) ) {
	function estandar_core_diag_operations(): array {
		$leads_available = estandar_core_leads_settings_available();

		return [
			'missing_whatsapp'     => [
				'title'  => 'Proyecto sin responsable WhatsApp',
				'impact' => 'Los contactos de este proyecto no tienen una persona clara para recibir la conversación.',
				'action' => $leads_available
					? 'Asigne un operador activo con número de WhatsApp.'
					: 'Active Estandar Leads para configurar responsables y WhatsApp por proyecto.',
				'button' => $leads_available ? 'Asignar operador' : 'Estandar Leads inactivo',
			],
				'missing_url_patterns' => [
					'title'  => 'Proyecto sin rutas web configuradas',
					'impact' => 'WARNING: solo afecta atribución o resolución de proyecto basada en URL. Forms y Leads no dependen de rutas web cuando reciben project_slug explícito.',
					'action' => 'Abra el proyecto en Core > Proyectos y complete URL Patterns con una ruta por línea. Use la ruta real de landing o, si no hay landing específica, /project-slug.',
					'button' => 'Corregir rutas',
				],
			'routing_fallback'     => [
				'title'  => 'Proyecto derivando al canal general',
				'impact' => 'El contacto puede terminar en el WhatsApp general en vez del operador correcto.',
				'action' => $leads_available
					? 'Revise operador asignado y número WhatsApp.'
					: 'Active Estandar Leads para configurar derivación por operador.',
				'button' => $leads_available ? 'Corregir derivación' : 'Estandar Leads inactivo',
			],
		];
	}
}

if ( ! function_exists( 'estandar_core_render_config_status_section' ) ) {
	function estandar_core_render_config_status_section(): void {
		if ( ! current_user_can( 'manage_options' ) || ! function_exists( 'estandar_chatbot_get_config_status' ) ) {
			return;
		}

		$status      = estandar_chatbot_get_config_status();
		$warnings    = isset( $status['warnings'] ) ? absint( $status['warnings'] ) : 0;
		$context     = estandar_core_diag_project_context();
		$operations  = estandar_core_diag_operations();
		$open_issues = [];
		foreach ( $operations as $diagnostic => $copy ) {
			$projects = isset( $context[ $diagnostic ] ) ? array_values( array_filter( (array) $context[ $diagnostic ] ) ) : [];
			if ( empty( $projects ) ) {
				continue;
			}
			$open_issues[ $diagnostic ] = [
				'copy'     => $copy,
				'projects' => $projects,
				'url'      => estandar_core_diag_action_url( (string) $diagnostic, $projects ),
			];
		}

		echo '<div class="es-operational-status">';
		if ( empty( $open_issues ) ) {
			echo '<div class="es-operational-ok"><span class="es-status-dot es-status-dot-ok"></span><strong>Sin incidencias operativas</strong></div>';
			echo '<p class="description" style="margin:6px 0 0;">Proyectos, operadores, WhatsApp, rutas y derivación sin acciones pendientes.</p>';
		}
		foreach ( $open_issues as $issue ) {
			$copy     = (array) $issue['copy'];
			$projects = (array) $issue['projects'];
			echo '<div class="notice notice-warning" style="margin:12px 0;padding:10px 12px;">';
			echo '<p><strong>⚠ ' . esc_html( (string) $copy['title'] ) . '</strong></p>';
			echo '<p><strong>Proyectos afectados:</strong></p><ul style="margin-left:18px;list-style:disc;">';
			foreach ( $projects as $project ) {
				echo '<li>' . esc_html( (string) $project ) . '</li>';
			}
			echo '</ul>';
			echo '<p><strong>Impacto:</strong><br>' . esc_html( (string) $copy['impact'] ) . '</p>';
			echo '<p><strong>Acción:</strong><br>' . esc_html( (string) $copy['action'] ) . '</p>';
			if ( ! empty( $issue['url'] ) ) {
				echo '<p><a class="button button-primary" href="' . esc_url( (string) $issue['url'] ) . '">' . esc_html( (string) $copy['button'] ) . '</a></p>';
			} else {
				echo '<p><span class="button disabled" style="pointer-events:none;">' . esc_html( (string) $copy['button'] ) . '</span></p>';
			}
			echo '</div>';
		}

		if ( ! empty( $open_issues ) ) {
			echo '<table class="widefat striped"><thead><tr>';
			echo '<th>Proyecto</th><th>Problema</th><th>Impacto</th><th>Acción</th>';
			echo '</tr></thead><tbody>';
			foreach ( $open_issues as $diagnostic => $issue ) {
				$copy = (array) $issue['copy'];
				foreach ( (array) $issue['projects'] as $project ) {
					$project_url = estandar_core_diag_action_url( (string) $diagnostic, [ (string) $project ] );
					echo '<tr>';
					echo '<td><code>' . esc_html( (string) $project ) . '</code></td>';
					echo '<td>' . esc_html( (string) $copy['title'] ) . '</td>';
					echo '<td>' . esc_html( (string) $copy['impact'] ) . '</td>';
					if ( '' !== $project_url ) {
						echo '<td><a class="button button-small" href="' . esc_url( $project_url ) . '">' . esc_html( (string) $copy['button'] ) . '</a></td>';
					} else {
						echo '<td><span class="button button-small disabled" style="pointer-events:none;">' . esc_html( (string) $copy['button'] ) . '</span></td>';
					}
					echo '</tr>';
				}
			}
			echo '</tbody></table>';
		}
		if ( $warnings > 0 ) {
			echo '<p class="description" style="margin:10px 0 0;">' . esc_html( (string) $warnings ) . ' advertencia(s) de configuración detectada(s).</p>';
		}
		echo '</div>';
	}
}

if ( ! function_exists( 'estandar_core_version_health' ) ) {
	function estandar_core_version_line( string $version ): string {
		if ( preg_match( '/^(\d+\.\d+)\./', $version, $m ) ) {
			return (string) $m[1];
		}

		return $version;
	}

	function estandar_core_version_health(): array {
		if ( ! function_exists( 'get_plugins' ) && defined( 'ABSPATH' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = function_exists( 'get_plugins' ) ? (array) get_plugins() : [];
		$active  = function_exists( 'wp_get_active_and_valid_plugins' ) ? array_map( 'wp_normalize_path', (array) wp_get_active_and_valid_plugins() ) : [];
		$rows    = [];
		$counts  = [];

		foreach ( $plugins as $plugin_file => $plugin_data ) {
			$name = isset( $plugin_data['Name'] ) ? (string) $plugin_data['Name'] : '';
			if ( stripos( $name, 'Estandar' ) === false && strpos( (string) $plugin_file, 'estandar-' ) !== 0 ) {
				continue;
			}

			$absolute = wp_normalize_path( WP_PLUGIN_DIR . '/' . $plugin_file );
			$is_active = in_array( $absolute, $active, true )
				|| ( function_exists( 'is_plugin_active' ) && is_plugin_active( $plugin_file ) )
				|| ( function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( $plugin_file ) );

			if ( ! $is_active ) {
				continue;
			}

			$version = trim( (string) ( $plugin_data['Version'] ?? '' ) );
			$version = $version !== '' ? $version : 'unknown';
			$rows[] = [
				'name'    => $name !== '' ? $name : $plugin_file,
				'file'    => (string) $plugin_file,
				'version' => $version,
			];
			$counts[ $version ] = ( $counts[ $version ] ?? 0 ) + 1;
		}

		if ( defined( 'ESTANDAR_CORE_MU_VERSION' ) ) {
			$core_file = plugin_basename( __FILE__ );
			$has_core = false;
			foreach ( $rows as $row ) {
				if ( (string) $row['file'] === $core_file || stripos( (string) $row['name'], 'Estandar Primer' ) !== false ) {
					$has_core = true;
					break;
				}
			}
			if ( ! $has_core ) {
				$version = (string) ESTANDAR_CORE_MU_VERSION;
				$rows[] = [
					'name'    => 'Estandar Primer',
					'file'    => $core_file,
					'version' => $version,
				];
				$counts[ $version ] = ( $counts[ $version ] ?? 0 ) + 1;
			}
		}

		arsort( $counts );
		$dominant = $counts ? (string) array_key_first( $counts ) : 'unknown';
		$misaligned = 0;
		$incompatible = 0;
		$dominant_line = estandar_core_version_line( $dominant );
		foreach ( $rows as &$row ) {
			$row['aligned'] = (string) $row['version'] === $dominant;
			$row['compatible'] = 'unknown' !== $dominant
				&& 'unknown' !== (string) $row['version']
				&& estandar_core_version_line( (string) $row['version'] ) === $dominant_line;
			if ( ! $row['aligned'] ) {
				$misaligned++;
			}
			if ( ! $row['compatible'] ) {
				$incompatible++;
			}
		}
		unset( $row );

		return [
				'status'       => empty( $rows ) ? 'ERROR' : ( $incompatible > 0 ? 'ERROR' : 'OK' ),
			'dominant'     => $dominant,
			'line'         => $dominant_line,
			'total'        => count( $rows ),
			'misaligned'   => $misaligned,
			'incompatible' => $incompatible,
			'rows'         => $rows,
		];
	}
}

if ( ! function_exists( 'estandar_core_fetch_dashboard_banners' ) ) {
	function estandar_core_fetch_dashboard_banners(): array {
		$cached = get_transient( 'estandar_dashboard_banners_v1' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$response = wp_remote_get(
			'https://estandar.cl/assets/dashboard-banners/manifest.json',
			[ 'timeout' => 3, 'sslverify' => true, 'user-agent' => 'Estandar-Dashboard/1.0' ]
		);
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			set_transient( 'estandar_dashboard_banners_v1', [], HOUR_IN_SECONDS );
			return [];
		}
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		$banners = is_array( $decoded ) ? array_values( $decoded ) : [];
		set_transient( 'estandar_dashboard_banners_v1', $banners, 6 * HOUR_IN_SECONDS );
		return $banners;
	}
}

if ( ! function_exists( 'estandar_diag_icon' ) ) {
	function estandar_diag_icon( string $key ): string {
		$map = array(
			'identidad'                    => 'dashicons-admin-site-alt3',
			'comunicaciones'               => 'dashicons-email-alt',
			'horario'                      => 'dashicons-clock',
			'horario-operacional'          => 'dashicons-clock',
			'compliance'                   => 'dashicons-shield',
			'ia'                           => 'dashicons-superhero',
			'integraciones'                => 'dashicons-networking',
			'integraciones-publicitarias'  => 'dashicons-networking',
			'analytics'                    => 'dashicons-chart-area',
			'extensiones'                  => 'dashicons-admin-plugins',
			'deployment'                   => 'dashicons-upload',
			'incidencias'                  => 'dashicons-warning',
			'incidencias-del-sistema'      => 'dashicons-warning',
			'integridad'                   => 'dashicons-shield-alt',
			'auditor-de-integridad'        => 'dashicons-shield-alt',
			'operacional'                  => 'dashicons-dashboard',
			'estado-operacional'           => 'dashicons-dashboard',
			'plugins'                      => 'dashicons-admin-plugins',
			'ecosistema-de-plugins'         => 'dashicons-admin-plugins',
			'avanzado'                     => 'dashicons-admin-generic',
			'informacion-avanzada'         => 'dashicons-admin-generic',
			'identidad_com'                => 'dashicons-building',
			'leads'                        => 'dashicons-groups',
			'autoresponse'                 => 'dashicons-email-alt2',
			'compliance_com'               => 'dashicons-shield',
			'digest'                       => 'dashicons-list-view',
			'weekly'                       => 'dashicons-calendar-alt',
			'inventario'                   => 'dashicons-archive',
		);
		return $map[ $key ] ?? 'dashicons-admin-generic';
	}
}

if ( ! function_exists( 'estandar_core_render_diagnostics_page' ) ) {
	function estandar_core_render_diagnostics_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No autorizado.' );
		}

		global $wpdb;

		// ── Datos base ────────────────────────────────────────────────────────
		$summary      = estandar_core_diag_get_summary();
		$status       = isset( $summary['status'] ) ? (string) $summary['status'] : 'OK';
		$counts       = isset( $summary['counts'] ) && is_array( $summary['counts'] ) ? $summary['counts'] : [ 'HIGH' => 0, 'MEDIUM' => 0, 'LOW' => 0, 'TOTAL' => 0 ];
			$generated_at = isset( $summary['generated_at'] ) ? absint( $summary['generated_at'] ) : 0;
			$css_ok       = file_exists( estandar_core_mu_asset_path( 'admin/admin.css' ) );
			$js_ok        = file_exists( estandar_core_mu_asset_path( 'admin/admin.js' ) );
			$assets_ok    = $css_ok && $js_ok;

			$client_name  = sanitize_text_field( (string) get_option( ESTANDAR_CLIENT_NAME_OPTION, '' ) );
		$client_slug  = sanitize_text_field( (string) get_option( ESTANDAR_CLIENT_SLUG_OPTION, '' ) );
		$sender_name_val = sanitize_text_field( (string) get_option( ESTANDAR_SENDER_NAME, '' ) );
			$sender_email_val = (string) get_option( ESTANDAR_SENDER_EMAIL, '' );
			$sender_ok = is_email( $sender_email_val ) && strpos( $sender_email_val, '@gmail' ) === false && strpos( $sender_email_val, '@hotmail' ) === false && strpos( $sender_email_val, '@yahoo' ) === false;
			$site_domain  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
			$core_version = defined( 'ESTANDAR_CORE_MU_VERSION' ) ? ESTANDAR_CORE_MU_VERSION : '—';
				$shared_secret_labels = [
					'openai'     => 'OpenAI API Key',
					'anthropic'  => 'Anthropic API Key',
					'google_ai'  => 'Google AI API Key',
					'pinecone'   => 'Pinecone API Key',
					'elevenlabs' => 'ElevenLabs API Key',
					'runway'     => 'Runway API Key',
				];
				$shared_secret_help = [
					'openai'     => 'Consumidores: Chatbot (fallback), KickOff',
					'anthropic'  => 'Sin consumidores activos',
					'google_ai'  => 'Sin consumidores activos',
					'pinecone'   => 'Estado: pendiente de auditoría',
					'elevenlabs' => 'Sin consumidores activos',
					'runway'     => 'Sin consumidores activos',
				];

		$deployment_validator = isset( $summary['details']['deployment_validator'] ) && is_array( $summary['details']['deployment_validator'] )
			? $summary['details']['deployment_validator']
			: estandar_core_diag_deployment_validator();
		$deployment_status = isset( $deployment_validator['status'] ) ? (string) $deployment_validator['status'] : 'FAIL';
		$deployment_mode   = isset( $deployment_validator['mode'] )   ? (string) $deployment_validator['mode']   : 'COMPAT';

		$levels            = isset( $summary['details']['levels'] )     && is_array( $summary['details']['levels'] )     ? $summary['details']['levels']     : estandar_core_diag_rule_levels();
			$site_identity     = estandar_core_diag_site_identity();
			$version_health    = estandar_core_version_health();
			$operational_context = estandar_core_diag_project_context();
			$operational_incidents = 0;
			foreach ( $operational_context as $operational_projects ) {
				$operational_incidents += count( array_filter( (array) $operational_projects ) );
			}

		// ── Color helpers ─────────────────────────────────────────────────────
		$color = [ 'OK' => '#00a32a', 'WARN' => '#dba617', 'ERROR' => '#d63638', 'FAIL' => '#d63638', 'PASS' => '#00a32a' ];
		$dot_style   = fn( string $s ) => 'display:inline-block;width:10px;height:10px;border-radius:50%;background:' . ( $color[ $s ] ?? '#6b7280' ) . ';margin-right:6px;flex-shrink:0;';
		$badge_style = fn( string $s ) => 'display:inline-block;padding:2px 10px;border-radius:3px;font-size:11px;font-weight:700;letter-spacing:.5px;color:#fff;background:' . ( $color[ $s ] ?? '#6b7280' ) . ';';

		// ── Estado operativo y configuración ──────────────────────────────────
		// ── Componentes ───────────────────────────────────────────────────────
		$components = [
			'Core'      => defined( 'ESTANDAR_CORE_MU_VERSION' ),
			'Forms'     => defined( 'ESTANDAR_FORMS_VERSION' ),
			'Leads'     => defined( 'ESTANDAR_LEADS_VERSION' ),
			'Analytics' => defined( 'EA_VERSION' ) || defined( 'ESTANDAR_ANALYTICS_VERSION' ),
			'Chatbot'   => defined( 'ESTANDAR_CHATBOT_VERSION' ),
		];
		foreach ( $components as $component_label => $component_active ) {
			$component_state = $component_active ? 'OK' : 'WARN';
			foreach ( (array) $version_health['rows'] as $version_row ) {
				if (
					false !== stripos( (string) ( $version_row['name'] ?? '' ), $component_label )
					&& empty( $version_row['compatible'] )
				) {
					$component_state = 'WARN';
					break;
				}
			}
			$components[ $component_label ] = $component_state;
		}

		// ── Acciones recomendadas ─────────────────────────────────────────────
		$actions = [];
		if ( $client_slug === '' ) {
			$actions[] = [ 'level' => 'ERROR', 'msg' => 'Identificador de cliente no configurado.', 'fix_url' => admin_url( 'admin.php?page=estandar_core_diagnostics&tab=configuracion' ), 'fix_label' => 'Configurar →' ];
		}

		// Detectar proyectos sin client_id (datos heredados de otra instalación).
		$project_table = $wpdb->prefix . 'estandar_project_registry';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $project_table ) ) ) === $project_table ) {
			$foreign_projects = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$project_table} WHERE client_id = '' OR client_id IS NULL" );
			if ( $foreign_projects > 0 ) {
				$actions[] = [
					'level'     => 'WARN',
					'msg'       => "{$foreign_projects} proyecto(s) sin cliente asignado (posiblemente heredados de otra instalación). Revisa y asigna o elimina.",
					'fix_url'   => admin_url( 'admin.php?page=estandar_projects' ),
					'fix_label' => 'Revisar proyectos →',
				];
			}
		}
		$smtp_success = get_option( 'estandar_leads_smtp_last_success', null );
		$smtp_error   = get_option( 'estandar_leads_smtp_last_error', null );
		$smtp_has_err = is_array( $smtp_error ) && ! empty( $smtp_error['ts'] )
			&& ( ! is_array( $smtp_success ) || ! isset( $smtp_success['ts'] ) || strtotime( $smtp_error['ts'] ) > strtotime( $smtp_success['ts'] ) );
		if ( $smtp_has_err ) {
			$actions[] = [ 'level' => 'ERROR', 'msg' => 'Último envío SMTP falló: ' . ( $smtp_error['message'] ?? '' ), 'fix_url' => admin_url( 'admin.php?page=estandar-leads-email' ), 'fix_label' => 'Ver SMTP →' ];
		} elseif ( ! is_array( $smtp_success ) ) {
			$actions[] = [ 'level' => 'WARN', 'msg' => 'SMTP sin validar — envía un email de prueba.', 'fix_url' => admin_url( 'admin.php?page=estandar-leads-email' ), 'fix_label' => 'Validar →' ];
		}
		foreach ( (array) ( $deployment_validator['failed'] ?? [] ) as $failed_check ) {
			if ( (string) ( $failed_check['key'] ?? '' ) !== 'forms_db_migration_state' ) {
				continue;
			}
			$actions[] = [
				'level'     => 'ERROR',
				'msg'       => 'Forms requiere actualización de esquema.',
				'fix_url'   => (string) ( $failed_check['fix_url'] ?? admin_url( 'admin.php?page=estandar-forms' ) ),
				'fix_label' => 'Actualizar esquema →',
			];
		}

		// ── Operational Home V2: read-only derived view, no new persistence.
		$user = wp_get_current_user();
		$display_name = 'Usuario';
		if ( $user && $user->exists() ) {
			$name_parts = trim( (string) $user->first_name . ' ' . (string) $user->last_name );
			$display_name = $name_parts !== '' ? $name_parts : (string) $user->display_name;
			if ( $display_name === '' || ctype_digit( $display_name ) ) {
				$display_name = (string) $user->user_login;
			}
			if ( $display_name === '' || ctype_digit( $display_name ) ) {
				$display_name = 'Usuario';
			}
		}
		$user_role = $user && ! empty( $user->roles ) ? translate_user_role( wp_roles()->role_names[ $user->roles[0] ] ?? $user->roles[0] ) : 'Administrador';
		$today_start = get_gmt_from_date( wp_date( 'Y-m-d 00:00:00', current_time( 'timestamp' ) ) );
		$month_start = get_gmt_from_date( wp_date( 'Y-m-01 00:00:00', current_time( 'timestamp' ) ) );
			$leads_table = $wpdb->prefix . 'es_leads';
			$subs_table  = $wpdb->prefix . 'es_lead_submissions';
			$contacts_table = $wpdb->prefix . 'es_lead_contacts';
			$events_table = $wpdb->prefix . 'es_lead_events';
			$analytics_events_table = $wpdb->prefix . 'ea_events';
			$has_leads   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $leads_table ) ) ) === $leads_table;
			$has_subs    = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $subs_table ) ) ) === $subs_table;
			$has_contacts = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $contacts_table ) ) ) === $contacts_table;
			$has_events = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $events_table ) ) ) === $events_table;
			$has_analytics_events = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $analytics_events_table ) ) ) === $analytics_events_table;
			$analytics_events_count = $has_analytics_events ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$analytics_events_table}" ) : 0;
			$submissions_count = $has_subs ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$subs_table}" ) : 0;
			$compliance_settings = (array) get_option( 'estandar_leads_compliance_settings', [] );
			$compliance_active = ! empty( $compliance_settings['enable_compliance_engine'] ) || (bool) wp_next_scheduled( 'estandar_leads_compliance_daily' );
		$op = [
			'new_today' => 0, 'action_required' => 0, 'waiting_response' => 0,
			'classification_pending' => 0, 'reservations' => 0, 'sales' => 0,
			'recurrent' => 0, 'unreached' => 0, 'overdue' => 0,
		];
		$pipeline = [ 'entered' => 0, 'contacted' => 0, 'responded' => 0, 'reservations' => 0, 'sales' => 0 ];
		$operator_backlog_rows = [];
		$recent_activity_rows = [];
		if ( $has_leads ) {
			$op['new_today'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$leads_table} WHERE created_at >= %s", $today_start ) );
			$op['action_required'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$leads_table} WHERE feedback_completed = 0 AND status = 'NEW' AND contact_attempts = 0" );
			$op['waiting_response'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$leads_table} WHERE feedback_completed = 0 AND status = 'NEW' AND contact_attempts > 0" );
			$op['classification_pending'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$leads_table} WHERE feedback_completed = 0 AND status NOT IN ('NEW','RESERVED','DEED_SIGNED','DELIVERED','LOST','INVALID','NO_RESPONSE','UNREACHABLE')" );
			$op['reservations'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$leads_table} WHERE status = 'RESERVED' AND updated_at >= %s", $month_start ) );
			$op['sales'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$leads_table} WHERE status IN ('DEED_SIGNED','DELIVERED') AND updated_at >= %s", $month_start ) );
			$op['unreached'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$leads_table} WHERE feedback_completed = 0 AND contact_attempts = 0" );
			$op['overdue'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$leads_table} WHERE feedback_completed = 0 AND feedback_status IN ('overdue','escalated')" );
			if ( $has_contacts ) {
				$pipeline_row = $wpdb->get_row( $wpdb->prepare(
					"SELECT COUNT(DISTINCT l.id) AS entered,
						COUNT(DISTINCT CASE WHEN l.contact_attempts > 0 THEN l.id END) AS contacted,
						COUNT(DISTINCT CASE WHEN c.outcome IN ('Respondió','Interesado','No interesado','Volver a contactar') OR l.status IN ('QUALIFIED','PROPOSAL_SENT','NEGOTIATION','BANK_EVALUATION','MEETING_BOOKED','VISIT_SCHEDULED','VISITED','RESERVED','DEED_SIGNED','DELIVERED','LOST','INVALID','NO_RESPONSE','UNREACHABLE') THEN l.id END) AS responded,
						COUNT(DISTINCT CASE WHEN l.status = 'RESERVED' THEN l.id END) AS reservations,
						COUNT(DISTINCT CASE WHEN l.status IN ('DEED_SIGNED','DELIVERED') THEN l.id END) AS sales
					 FROM {$leads_table} l
					 LEFT JOIN {$contacts_table} c ON c.lead_id = l.id
					 WHERE l.created_at >= %s",
					$month_start
				), ARRAY_A );
			} else {
				$pipeline_row = $wpdb->get_row( $wpdb->prepare(
					"SELECT COUNT(*) AS entered,
						SUM(contact_attempts > 0) AS contacted,
						SUM(status IN ('QUALIFIED','PROPOSAL_SENT','NEGOTIATION','BANK_EVALUATION','MEETING_BOOKED','VISIT_SCHEDULED','VISITED','RESERVED','DEED_SIGNED','DELIVERED','LOST','INVALID','NO_RESPONSE','UNREACHABLE')) AS responded,
						SUM(status = 'RESERVED') AS reservations,
						SUM(status IN ('DEED_SIGNED','DELIVERED')) AS sales
					 FROM {$leads_table}
					 WHERE created_at >= %s",
					$month_start
				), ARRAY_A );
			}
			foreach ( $pipeline as $key => $_value ) {
				$pipeline[ $key ] = (int) ( $pipeline_row[ $key ] ?? 0 );
			}
			$operator_backlog_rows = $wpdb->get_results(
				"SELECT COALESCE(NULLIF(assigned_operator_name, ''), assigned_operator_email, 'Sin operador') AS operator_name, COUNT(*) AS backlog
				 FROM {$leads_table}
				 WHERE feedback_completed = 0
				 GROUP BY operator_name
				 HAVING backlog >= 10
				 ORDER BY backlog DESC
				 LIMIT 3"
			);
			if ( $has_subs ) {
				$op['recurrent'] = (int) $wpdb->get_var(
					"SELECT COUNT(*) FROM (
						SELECT s.lead_id
						FROM {$subs_table} s
						INNER JOIN {$leads_table} l ON l.id = s.lead_id
						WHERE l.feedback_completed = 0
						GROUP BY s.lead_id
						HAVING COUNT(*) >= 2
					) recurrent_leads"
				);
			}
			if ( $has_events ) {
				$recent_activity_rows = $wpdb->get_results(
					"SELECT e.event_type, e.to_status, e.reason, e.metadata, e.created_at, l.name, l.project_slug
					 FROM {$events_table} e
					 LEFT JOIN {$leads_table} l ON l.id = e.lead_id
					 WHERE e.event_type IN ('lead_created','lead_recontacted','contact_logged','status_changed','status_overridden','quality_changed','commercial_stage_changed','lead_classified','feedback_status_changed','compliance_escalation','operator_assigned')
					 ORDER BY e.created_at DESC
					 LIMIT 20"
				);
			}
		}
		$attention_total = $op['action_required'] + $op['waiting_response'] + $op['classification_pending'] + $op['recurrent'] + $op['overdue'];
		$operational_status_label = 'Operación normal';
		$operational_status_class = 'es-status-ok';
		if ( $op['overdue'] > 0 || $op['recurrent'] >= 10 ) {
			$operational_status_label = 'Atención crítica';
			$operational_status_class = 'es-status-critical';
		} elseif ( $attention_total > 0 || $op['action_required'] > 0 || $op['classification_pending'] > 0 ) {
			$operational_status_label = 'Atención requerida';
			$operational_status_class = 'es-status-warning';
		}
		$attention_cards = [
			[
				'count'  => $op['recurrent'],
				'title'  => 'Leads reincidentes',
				'reason' => 'Han intentado contactar más de una vez sin cierre.',
				'url'    => admin_url( 'admin.php?page=estandar-leads&view=recontact_no_action' ),
			],
			[
				'count'  => $op['unreached'],
				'title'  => 'Sin contacto',
				'reason' => 'Asignados, pero aún sin intento de contacto registrado.',
				'url'    => admin_url( 'admin.php?page=estandar-leads' ),
			],
			[
				'count'  => $op['overdue'],
				'title'  => 'Vencidos o escalados',
				'reason' => 'Requieren revisión por antigüedad o cumplimiento.',
				'url'    => admin_url( 'admin.php?page=estandar-leads-compliance' ),
			],
		];
		foreach ( $operator_backlog_rows as $row ) {
			$attention_cards[] = [
				'count'  => absint( $row->backlog ),
				'title'  => (string) $row->operator_name . ' con backlog alto',
				'reason' => 'Operador con más pendientes abiertos que el umbral.',
				'url'    => admin_url( 'admin.php?page=estandar-leads' ),
			];
		}
		$activity_labels = [
			'lead_created'             => 'Nuevo lead',
			'lead_recontacted'         => 'Lead reincidente',
			'contact_logged'           => 'Contacto registrado',
			'quality_changed'          => 'Clasificación',
			'commercial_stage_changed' => 'Clasificación',
			'lead_classified'          => 'Clasificación',
			'feedback_status_changed'  => 'Lead escalado',
			'compliance_escalation'    => 'Lead escalado',
			'operator_assigned'        => 'Operador asignado',
		];
		$recent_activity_rows = array_values(
			array_filter(
				$recent_activity_rows,
				static function ( $row ): bool {
					return 0 !== stripos( trim( (string) ( $row->name ?? '' ) ), 'codex' );
				}
			)
		);
		$latest_activity = $recent_activity_rows[0] ?? null;
		$latest_activity_label = '';
		$latest_activity_copy = 'Sin actividad comercial reciente';
		if ( $latest_activity ) {
			$latest_activity_type = (string) $latest_activity->event_type;
			$latest_activity_label = $activity_labels[ $latest_activity_type ] ?? 'Actividad';
			if ( in_array( $latest_activity_type, [ 'status_changed', 'status_overridden' ], true ) ) {
				$latest_to_status = (string) $latest_activity->to_status;
				$latest_activity_label = 'RESERVED' === $latest_to_status ? 'Reserva' : ( in_array( $latest_to_status, [ 'DEED_SIGNED', 'DELIVERED' ], true ) ? 'Venta' : 'Clasificación' );
			}
			$latest_activity_name = trim( (string) $latest_activity->name ) !== '' ? (string) $latest_activity->name : 'Lead sin nombre';
			$latest_activity_project = trim( (string) $latest_activity->project_slug ) !== '' ? (string) $latest_activity->project_slug : 'Sin proyecto';
			$latest_activity_copy = $latest_activity_label . ': ' . $latest_activity_name . ' · ' . $latest_activity_project;
		}

		// ── Tab activo ────────────────────────────────────────────────────────
		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'panel';
		// CONSOLIDACIÓN UX: Diagnóstico + Integridad Operacional → una sola vista "Estado del ecosistema".
		// Alias de URLs antiguas para no romper enlaces existentes.
		if ( in_array( $current_tab, [ 'registry_health', 'diagnostico' ], true ) ) {
			$current_tab = 'estado_ecosistema';
		}
		$tabs = [
					'panel'             => 'Dashboard',
				'configuracion'     => 'Configuración compartida',
				'estado_ecosistema' => 'Estado del ecosistema',
			];
		$base_url = admin_url( 'admin.php?page=estandar_core_diagnostics' );

		ob_start();
		?>

		<style>
			.es-tabs{display:flex;flex-wrap:wrap;gap:3px;margin:0 0 4px;padding:4px 6px;background:#fff;border:1px solid #e5e7eb;border-radius:6px;}
			.es-tab{min-height:28px;padding:4px 8px;font-size:12px;font-weight:600;line-height:1.2;color:#4b5563;text-decoration:none;border:1px solid transparent;border-radius:3px;background:transparent;transition:background-color .15s,border-color .15s,color .15s;}
			.es-tab:hover{background:#f3f4f6;border-color:#e5e7eb;color:#111827;text-decoration:none;}
			.es-tab.active{background:#f9fafb;border-color:#d1d5db;color:#111827;font-weight:600;}
		.es-diag-section{background:#fff;border:1px solid #e5e7eb;border-radius:8px;margin-bottom:12px;overflow:hidden;}
		.es-diag-section-header{padding:9px 16px;font-weight:600;font-size:12px;border-bottom:1px solid #f3f4f6;display:flex;align-items:center;justify-content:space-between;color:#111827;}
		.es-diag-section-body{padding:12px 16px;}
		.es-component-row{display:flex;align-items:center;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f3f4f6;font-size:13px;}
		.es-component-row:last-child{border-bottom:none;}
		details summary{cursor:pointer;padding:11px 18px;font-size:13px;font-weight:600;color:#374151;list-style:none;display:flex;align-items:center;gap:8px;}
		details summary::-webkit-details-marker{display:none;}
		details summary::before{content:"▶";font-size:9px;color:#9ca3af;transition:transform .15s;}
		details[open] summary::before{transform:rotate(90deg);}
		details .es-diag-section-body{border-top:1px solid #f3f4f6;}
		.es-two-col{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
		@media(max-width:900px){.es-two-col{grid-template-columns:1fr;}}
		.es-meta-badges{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;}
		.es-badge{background:#f3f4f6;border:1px solid #e5e7eb;border-radius:3px;padding:3px 9px;font-size:11px;color:#374151;}
		.es-badge strong{color:#111827;}
			.es-dashboard-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;padding:10px 16px;border:1px solid #e5e7eb;border-radius:8px;background:linear-gradient(135deg,#111827 0%,#2f1b12 58%,#f95b1f 170%);color:#fff;}
			.es-dashboard-eyebrow{display:block;margin-bottom:3px;color:#6b7280;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;}
			.es-dashboard-header .es-dashboard-eyebrow{color:#ffb38d;}
			.es-dashboard-title{margin:0;font-size:18px;font-weight:700;line-height:1.1;color:#fff;border-left:3px solid #f95b1f;padding-left:8px;}
			.es-dashboard-subtitle{margin:2px 0 0;color:#f8d8c7;font-size:12px;}
		.es-home-status{display:inline-flex;align-items:center;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:700;}
		.es-status-ok{background:#ecfdf3;color:#1a7f37;}
		.es-status-warning{background:#fff8e5;color:#996c00;}
		.es-status-critical{background:#fdecec;color:#b42318;}
			.es-kpi-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:6px;margin-bottom:6px;}
			.es-kpi-grid.es-kpi-grid-six{grid-template-columns:repeat(6,minmax(0,1fr));}
			.es-kpi{background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:7px 12px;}
		.es-kpi-success{border-color:#bbf7d0;background:#f7fef9;}
		.es-kpi-label{display:block;color:#6b7280;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;}
		.es-kpi-value{display:block;margin-top:2px;color:#111827;font-size:20px;font-weight:600;line-height:1;}
		.es-kpi-success .es-kpi-value{color:#1a7f37;}
		.es-kpi-warning .es-kpi-value{color:#b7791f;}
		.es-kpi-error .es-kpi-value{color:#d63638;}
				.es-kpi-detail{display:block;margin-top:2px;color:#6b7280;font-size:11px;line-height:1.3;}
				.es-kpi-detail strong{color:#374151;font-weight:600;}
				.es-analytics-map{margin-bottom:12px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;width:100%;}
					.es-analytics-map__visual{display:flex;align-items:stretch;justify-content:center;background:#f9fafb;padding:0;}
					.es-analytics-map__heatmap{width:100%;display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px;align-content:stretch;}
				.es-analytics-map__heatmap-cell{min-height:86px;border:1px solid rgba(249,91,31,calc(.16 + var(--heat, .12) * .42));border-radius:6px;background:rgba(249,91,31,calc(.06 + var(--heat, .12) * .24));padding:10px;display:flex;flex-direction:column;justify-content:space-between;}
				.es-analytics-map__heatmap-cell span{color:#374151;font-size:12px;font-weight:700;line-height:1.2;overflow-wrap:anywhere;}
				.es-analytics-map__heatmap-cell strong{color:#111827;font-size:20px;line-height:1;}
				.es-analytics-map__heatmap-cell em{color:#6b7280;font-size:11px;font-style:normal;}
				.es-analytics-map__empty{align-self:center;justify-self:center;color:#6b7280;font-size:13px;}
				.es-analytics-map__panel{padding:24px;display:flex;flex-direction:column;justify-content:center;}
				.es-analytics-map__label{margin:0 0 8px;color:#6b7280;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;}
				.es-analytics-map__title{margin:0;color:#111827;font-size:24px;line-height:1.2;}
				.es-analytics-map__copy{margin:10px 0 18px;color:#6b7280;font-size:13px;line-height:1.5;}
					.es-analytics-map__stats{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;}
				.es-analytics-map__stat{border:1px solid #e5e7eb;border-radius:6px;padding:10px;background:#f9fafb;}
				.es-analytics-map__stat span{display:block;color:#6b7280;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;}
					.es-analytics-map__stat strong{display:block;margin-top:4px;color:#111827;font-size:18px;line-height:1.15;overflow-wrap:anywhere;}
		.es-home-grid{display:grid;grid-template-columns:1.15fr .85fr;gap:14px;margin-bottom:14px;}
		.es-alert-list{display:flex;flex-direction:column;}
		.es-alert-row{display:flex;align-items:center;gap:10px;padding:7px 0;border-bottom:1px solid #f3f4f6;text-decoration:none;}
		.es-alert-row:last-child{border-bottom:none;}
		.es-alert-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;}
		.es-alert-dot-red{background:#d63638;}
		.es-alert-dot-amber{background:#dba617;}
		.es-alert-dot-blue{background:#2271b1;}
		.es-alert-count{font-size:18px;font-weight:700;color:#111827;min-width:34px;line-height:1;}
		.es-alert-body{flex:1;min-width:0;}
		.es-alert-title{font-size:12px;font-weight:600;color:#111827;display:block;}
		.es-alert-sub{font-size:11px;color:#6b7280;display:block;margin-top:1px;}
		.es-alert-action{font-size:11px;font-weight:700;color:#1d4ed8;white-space:nowrap;}
		.es-funnel{display:flex;flex-direction:column;gap:6px;}
		.es-funnel-row{display:grid;grid-template-columns:90px 1fr 44px;gap:8px;align-items:center;}
		.es-funnel-label{font-size:12px;color:#6b7280;}
		.es-funnel-track{height:22px;background:#f3f4f6;border-radius:3px;overflow:hidden;}
		.es-funnel-fill{height:100%;border-radius:3px;display:flex;align-items:center;padding-left:8px;}
		.es-funnel-fill span{font-size:11px;font-weight:600;color:#fff;white-space:nowrap;}
		.es-funnel-num{font-size:13px;font-weight:700;color:#111827;text-align:right;}
		.es-funnel-meta{display:flex;gap:16px;margin-top:10px;padding-top:8px;border-top:1px solid #f3f4f6;}
		.es-funnel-meta-item{font-size:11px;color:#6b7280;}
		.es-funnel-meta-item strong{color:#111827;font-weight:700;}
		.es-banner-strip{background:#fff;border:1px solid #e5e7eb;border-radius:8px;margin-bottom:12px;display:flex;align-items:center;justify-content:space-between;padding:0 14px;height:64px;overflow:hidden;}
		.es-banner-inner{display:flex;align-items:center;gap:12px;flex:1;min-width:0;}
		.es-banner-icon{width:32px;height:32px;border-radius:50%;background:#fff0e9;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:15px;color:#f95b1f;}
		.es-banner-text{font-size:12px;font-weight:600;color:#111827;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
		.es-banner-sub{font-size:11px;color:#6b7280;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
		.es-banner-nav{display:flex;align-items:center;gap:6px;flex-shrink:0;}
		.es-banner-pips{display:flex;gap:4px;align-items:center;}
		.es-banner-pip{width:5px;height:5px;border-radius:50%;background:#d1d5db;transition:background .2s;}
		.es-banner-pip.active{background:#f95b1f;}
		.es-banner-btn{width:22px;height:22px;border-radius:50%;border:1px solid #e5e7eb;background:#fff;cursor:pointer;font-size:10px;color:#374151;display:inline-flex;align-items:center;justify-content:center;}
		.es-banner-btn:hover{background:#f9fafb;}
		.es-technical-fold{margin-top:14px;}
		.es-activity-list{display:flex;flex-direction:column;gap:0;margin:0;padding:0;list-style:none;}
		.es-activity-item{display:grid;grid-template-columns:132px 1fr auto;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid #f3f4f6;font-size:13px;}
		.es-activity-item:last-child{border-bottom:0;}
		.es-activity-type{font-weight:700;color:#111827;}
		.es-activity-meta{color:#6b7280;}
		.es-activity-date{color:#9ca3af;font-size:12px;white-space:nowrap;}
		.es-activity-severity{display:inline-flex;align-items:center;gap:7px;}
		.es-activity-severity::before{content:"";width:8px;height:8px;border-radius:50%;background:#6b7280;}
		.es-activity-critical::before{background:#d63638;}
		.es-activity-warning::before{background:#dba617;}
		.es-activity-success::before{background:#1a7f37;}
		.es-activity-info::before{background:#2271b1;}
		.es-component-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px;}
		.es-component-item{display:flex;align-items:center;gap:8px;background:#f9fafb;border:1px solid #eef0f2;border-radius:4px;padding:10px 12px;font-size:13px;font-weight:500;}
		.es-status-dot{display:inline-block;width:9px;height:9px;border-radius:50%;flex-shrink:0;}
		.es-status-dot-ok{background:#00a32a;}
		.es-status-dot-warn{background:#dba617;}
		.es-operational-status{padding:2px 0;}
		.es-operational-ok{display:flex;align-items:center;gap:8px;color:#1a7f37;}
		.es-operational-components{margin-top:14px;padding-top:14px;border-top:1px solid #f3f4f6;}
		.es-operational-components-label{display:block;margin-bottom:8px;color:#6b7280;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;}
		.es-version-summary{display:grid;gap:8px;}
		.es-version-row{display:grid;grid-template-columns:18px minmax(220px,1fr) auto;align-items:center;gap:8px;padding:9px 0;border-bottom:1px solid #f3f4f6;font-size:13px;}
		.es-version-row:last-child{border-bottom:0;}
		.es-version-copy{color:#6b7280;font-size:12px;}
		.es-version-copy code{color:#374151;}
		.es-version-details{margin-top:12px;border-top:1px solid #f3f4f6;}
		.es-version-details summary{padding-left:0;padding-right:0;}
				@media(max-width:1100px){.es-kpi-grid.es-kpi-grid-six{grid-template-columns:repeat(3,1fr)}.es-home-grid{grid-template-columns:1fr;}}
				@media(max-width:900px){.es-kpi-grid,.es-kpi-grid.es-kpi-grid-six{grid-template-columns:repeat(2,1fr)}.es-component-grid{grid-template-columns:repeat(2,1fr)}.es-activity-item{grid-template-columns:1fr}.es-dashboard-header{flex-direction:column;align-items:flex-start;gap:8px;}.es-funnel-row{grid-template-columns:70px 1fr 36px;}.es-analytics-map{width:100%;}.es-analytics-map__visual{min-height:320px;}.es-analytics-map__heatmap{grid-template-columns:repeat(2,minmax(0,1fr));}.es-analytics-map__stats{grid-template-columns:1fr;}}
		</style>

<?php
/**
 * GEO_IP_LOOKUP_V1 — resolución IP→lat/lng compartida de Primer.
 * Delegación: si el chatbot está activo reutiliza su resolver (y su cache);
 * si no, implementación propia equivalente (ip-api.com + transient 14d,
 * negativos 1h, solo IPs públicas). Feature estética: fallos → [].
 * Proveedor cambiado de ipwho.is a ip-api.com (2026-07): mismo esfuerzo,
 * probando si mejora la precisión rural — sin garantía, limitación de datos
 * de origen del proveedor gratuito, no de este código.
 */
if ( ! function_exists( 'estandar_core_geo_ip_lookup' ) ) :
function estandar_core_geo_ip_lookup( string $ip ): array {
	if ( function_exists( 'estandar_chatbot_get_ip_geolocation' ) ) {
		return (array) estandar_chatbot_get_ip_geolocation( $ip );
	}

	$ip = trim( $ip );
	if ( '' === $ip || false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
		return [];
	}

	$cache_key = 'estandar_core_geo_ip_v2_' . md5( $ip );
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$response = wp_remote_get(
		'http://ip-api.com/json/' . rawurlencode( $ip ) . '?fields=status,country,regionName,city,lat,lon',
		[ 'timeout' => 2, 'redirection' => 1, 'user-agent' => 'EstandarPrimer/' . ( defined( 'PRIMER_VERSION' ) ? PRIMER_VERSION : '0' ) ]
	);
	$body = is_wp_error( $response ) ? null : json_decode( (string) wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $body ) || 'success' !== ( $body['status'] ?? '' ) || ! isset( $body['lat'], $body['lon'] ) ) {
		set_transient( $cache_key, [], HOUR_IN_SECONDS );
		return [];
	}

	$data = [
		'ip'      => $ip,
		'city'    => sanitize_text_field( (string) ( $body['city'] ?? '' ) ),
		'region'  => sanitize_text_field( (string) ( $body['regionName'] ?? '' ) ),
		'country' => sanitize_text_field( (string) ( $body['country'] ?? '' ) ),
		'lat'     => round( (float) $body['lat'], 4 ),
		'lng'     => round( (float) $body['lon'], 4 ),
	];
	set_transient( $cache_key, $data, 14 * DAY_IN_SECONDS );
	return $data;
}
endif;

/**
 * Geo Engine — canonical geographic data provider for Primer.
 *
 * @param string $provider  all_sessions|leads|chatbot|analytics|forms|customers|purchases
 * @param int    $days      Look-back window in days (0 = all time).
 * @return array{ markers: array, unique_places: int, total_interactions: int }
 */
if ( ! function_exists( 'estandar_core_geo_engine_data' ) ) :
function estandar_core_geo_engine_data( string $provider = 'all_sessions', int $days = 30 ): array {
	global $wpdb;

	$_city_latlng = [
		'arica' => [ -18.4783, -70.3126 ], 'iquique' => [ -20.2141, -70.1522 ], 'alto-hospicio' => [ -20.2656, -70.1045 ],
		'antofagasta' => [ -23.6509, -70.3975 ], 'calama' => [ -22.4560, -68.9297 ], 'copiapo' => [ -27.3668, -70.3323 ],
		'la-serena' => [ -29.9027, -71.2519 ], 'coquimbo' => [ -29.9533, -71.3436 ],
		'valparaiso' => [ -33.0458, -71.6197 ], 'vina-del-mar' => [ -33.0245, -71.5518 ],
		'santiago' => [ -33.4489, -70.6693 ], 'providencia' => [ -33.4328, -70.6108 ],
		'las-condes' => [ -33.4177, -70.5761 ], 'nunoa' => [ -33.4559, -70.5973 ],
		'rancagua' => [ -34.1703, -70.7444 ], 'talca' => [ -35.4264, -71.6553 ],
		'chillan' => [ -36.6067, -72.1034 ], 'concepcion' => [ -36.8270, -73.0498 ],
		'los-angeles' => [ -37.4696, -72.3534 ], 'temuco' => [ -38.7359, -72.5904 ],
		'valdivia' => [ -39.8142, -73.2459 ], 'osorno' => [ -40.5725, -73.1315 ],
		'puerto-montt' => [ -41.4693, -72.9424 ], 'coyhaique' => [ -45.5752, -72.0662 ],
		'punta-arenas' => [ -53.1638, -70.9171 ],
	];
	$_region_latlng = [
		'CLAP' => [ -18.4783, -70.3126 ], 'CLTA' => [ -20.2141, -70.1522 ], 'CLAN' => [ -23.6509, -70.3975 ],
		'CLAT' => [ -27.3668, -70.3323 ], 'CLCO' => [ -29.9027, -71.2519 ], 'CLRM' => [ -33.4489, -70.6693 ],
		'CLVS' => [ -33.0458, -71.6197 ], 'CLML' => [ -35.4264, -71.6553 ], 'CLLI' => [ -34.1703, -70.7444 ],
		'CLNB' => [ -36.6067, -72.1034 ], 'CLAR' => [ -38.7359, -72.5904 ], 'CLBI' => [ -36.8270, -73.0498 ],
		'CLLR' => [ -39.8142, -73.2459 ], 'CLLL' => [ -40.5725, -73.1315 ], 'CLAI' => [ -45.5752, -72.0662 ],
		'CLMA' => [ -53.1638, -70.9171 ],
	];

	$_slug = static function ( string $name ): string {
		return function_exists( 'estandar_core_registry_health_slug' )
			? estandar_core_registry_health_slug( $name )
			: sanitize_title( remove_accents( $name ) );
	};
	$_rid = static function ( string $region ) use ( $_slug ): string {
		$s = $_slug( $region );
		$s = str_replace( [ 'region-de-', 'region-del-', 'region-', 'de-', 'del-' ], '', $s );
		$m = [
			'arica-y-parinacota' => 'CLAP', 'tarapaca' => 'CLTA', 'antofagasta' => 'CLAN',
			'atacama' => 'CLAT', 'coquimbo' => 'CLCO', 'metropolitana-santiago' => 'CLRM',
			'metropolitana' => 'CLRM', 'ohiggins' => 'CLLI', 'libertador-general-bernardo-ohiggins' => 'CLLI',
			'maule' => 'CLML', 'nuble' => 'CLNB', 'biobio' => 'CLBI', 'bio-bio' => 'CLBI',
			'la-araucania' => 'CLAR', 'araucania' => 'CLAR', 'los-rios' => 'CLLR',
			'los-lagos' => 'CLLL', 'aysen' => 'CLAI', 'aisen' => 'CLAI',
			'aysen-general-carlos-ibanez-campo' => 'CLAI', 'aisen-general-carlos-ibanez-campo' => 'CLAI',
			'magallanes-antartica-chilena' => 'CLMA', 'magallanes' => 'CLMA',
		];
		return $m[ $s ] ?? '';
	};

	$markers = [];

	// GEO_ENGINE_UNIFIED_V1 — dos fuentes reales con IP propia (Chatbot: su log
	// de interacciones; Forms: es_lead_submissions filtrado por source_plugin,
	// única fuente con IP consistentemente poblada — ver hallazgo abajo).
	// 'all' (default) las suma en el mismo bucket de coordenadas. No incluye
	// leads con source_plugin=estandar-chatbot en es_lead_submissions: esa
	// columna casi nunca se puebla para el chatbot (auditado: 1/74 filas) —
	// el log de interacciones del chatbot es la fuente correcta para ese canal.
	if ( ! in_array( $provider, [ 'all', 'chatbot', 'forms' ], true ) ) {
		$provider = 'all';
	}

	$_chatbot_points = static function ( int $days ): array {
		if ( ! function_exists( 'estandar_chatbot_get_geo_interaction_points' ) ) {
			return [];
		}
		$geo    = (array) estandar_chatbot_get_geo_interaction_points( max( 1, $days ), 80 );
		$points = (array) ( $geo['points'] ?? [] );
		$out    = [];
		foreach ( $points as $p ) {
			$lat = (float) ( $p['lat'] ?? 0 ); $lng = (float) ( $p['lng'] ?? 0 );
			if ( $lat === 0.0 && $lng === 0.0 ) continue;
			$k = number_format( $lat, 2, '.', '' ) . '|' . number_format( $lng, 2, '.', '' );
			$out[ $k ] = [
				'label' => (string) ( $p['label'] ?? '' ), 'lat' => $lat, 'lng' => $lng,
				'n' => (int) ( $p['interactions'] ?? 0 ),
			];
		}
		return $out;
	};

	// Puntos por IP (Forms: es_lead_submissions con IP casi siempre presente).
	// Presupuesto de lookups no cacheados por render + caché de 15 min: el
	// resto se resuelve en visitas siguientes.
	$_form_points = static function ( int $days ) use ( $wpdb ): array {
		$_subt = $wpdb->prefix . 'es_lead_submissions';
		if ( ! function_exists( 'estandar_core_diag_table_exists' ) || ! estandar_core_diag_table_exists( $_subt ) ) {
			return [];
		}
		$_dcond = $days > 0 ? $wpdb->prepare( ' AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)', $days ) : '';
		$rows   = $wpdb->get_results(
			"SELECT ip_address, COUNT(*) AS n
			 FROM {$_subt}
			 WHERE ip_address <> '' AND source_plugin = 'estandar-forms' {$_dcond}
			 GROUP BY ip_address ORDER BY n DESC LIMIT 60",
			ARRAY_A
		);
		$budget = 15;
		$points = [];
		foreach ( (array) $rows as $row ) {
			$ip = (string) ( $row['ip_address'] ?? '' );
			$n  = absint( $row['n'] ?? 0 );
			if ( '' === $ip || $n < 1 ) continue;
			$was_cached = is_array( get_transient( 'estandar_core_geo_ip_v2_' . md5( $ip ) ) )
				|| ( function_exists( 'estandar_chatbot_get_ip_geolocation' ) && is_array( get_transient( ESTANDAR_CHATBOT_GEO_IP_CACHE_PREFIX . md5( $ip ) ) ) );
			if ( ! $was_cached ) {
				if ( $budget < 1 ) continue;
				$budget--;
			}
			$geo = estandar_core_geo_ip_lookup( $ip );
			if ( empty( $geo['lat'] ) || empty( $geo['lng'] ) ) continue;
			$k = number_format( (float) $geo['lat'], 2, '.', '' ) . '|' . number_format( (float) $geo['lng'], 2, '.', '' );
			if ( ! isset( $points[ $k ] ) ) {
				$label = trim( implode( ', ', array_filter( [ (string) ( $geo['city'] ?? '' ), (string) ( $geo['country'] ?? '' ) ] ) ) );
				$points[ $k ] = [ 'label' => $label, 'lat' => (float) $geo['lat'], 'lng' => (float) $geo['lng'], 'n' => 0 ];
			}
			$points[ $k ]['n'] += $n;
		}
		return $points;
	};

	if ( in_array( $provider, [ 'all', 'chatbot', 'forms' ], true ) ) {
		$cache_key = 'estandar_core_geo_v2_' . $provider . '_' . max( 0, $days );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$combined = [];
		if ( 'chatbot' === $provider || 'all' === $provider ) {
			foreach ( $_chatbot_points( $days ) as $k => $p ) {
				$combined[ $k ] = $combined[ $k ] ?? [ 'label' => $p['label'], 'lat' => $p['lat'], 'lng' => $p['lng'], 'n' => 0 ];
				$combined[ $k ]['n'] += $p['n'];
			}
		}
		if ( 'forms' === $provider || 'all' === $provider ) {
			foreach ( $_form_points( $days ) as $k => $p ) {
				$combined[ $k ] = $combined[ $k ] ?? [ 'label' => $p['label'], 'lat' => $p['lat'], 'lng' => $p['lng'], 'n' => 0 ];
				if ( '' === $combined[ $k ]['label'] ) {
					$combined[ $k ]['label'] = $p['label'];
				}
				$combined[ $k ]['n'] += $p['n'];
			}
		}

		$total = 0;
		$max_n = 1;
		foreach ( $combined as $p ) { $max_n = max( $max_n, (int) $p['n'] ); $total += (int) $p['n']; }
		foreach ( $combined as $p ) {
			$markers[] = [
				'city' => (string) $p['label'], 'sessions' => (int) $p['n'],
				'leads' => (int) $p['n'], 'conversion' => null,
				'lat' => (float) $p['lat'], 'lng' => (float) $p['lng'],
				'r' => (float) max( 6, min( 18, 4 + ( $p['n'] / $max_n * 14 ) ) ),
			];
		}

		$result = [
			'markers'            => $markers,
			'unique_places'      => count( $markers ),
			'total_interactions' => $total,
		];
		set_transient( $cache_key, $result, 15 * MINUTE_IN_SECONDS );
		return $result;
	}

	// Provider: all_sessions / analytics / forms / customers / purchases
	$_st = $wpdb->prefix . 'ea_sessions';
	$_rt = $wpdb->prefix . 'estandar_project_registry';
	$_lt = $wpdb->prefix . 'es_leads';
	if ( ! function_exists( 'estandar_core_diag_table_exists' ) || ! estandar_core_diag_table_exists( $_st ) || ! estandar_core_diag_table_exists( $_rt ) ) {
		return [ 'markers' => [], 'unique_places' => 0, 'total_interactions' => 0 ];
	}
	$_sc = function_exists( 'estandar_core_diag_table_columns' ) ? estandar_core_diag_table_columns( $_st ) : [];
	$_rc = function_exists( 'estandar_core_diag_table_columns' ) ? estandar_core_diag_table_columns( $_rt ) : [];
	if ( ! in_array( 'first_project_slug', $_sc, true ) || array_diff( [ 'project_id', 'city', 'commune', 'region' ], $_rc ) ) {
		return [ 'markers' => [], 'unique_places' => 0, 'total_interactions' => 0 ];
	}
	$_lc    = estandar_core_diag_table_exists( $_lt ) && function_exists( 'estandar_core_diag_table_columns' ) ? estandar_core_diag_table_columns( $_lt ) : [];
	$_dcond = $days > 0 ? $wpdb->prepare( ' AND s.created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)', $days ) : '';
	$rows   = $wpdb->get_results(
		"SELECT COALESCE(NULLIF(r.city,''),NULLIF(r.commune,''),r.region) AS city, r.region AS region, COUNT(*) AS sessions
		 FROM {$_st} s INNER JOIN {$_rt} r ON r.project_id = s.first_project_slug
		 WHERE s.first_project_slug <> '' AND COALESCE(NULLIF(r.city,''),NULLIF(r.commune,''),r.region) <> '' {$_dcond}
		 GROUP BY city, r.region ORDER BY sessions DESC LIMIT 10",
		ARRAY_A
	);
	$max_s = 0;
	foreach ( (array) $rows as $row ) { $max_s = max( $max_s, absint( $row['sessions'] ?? 0 ) ); }
	foreach ( (array) $rows as $row ) {
		$city = sanitize_text_field( (string) ( $row['city'] ?? '' ) );
		$n    = absint( $row['sessions'] ?? 0 );
		if ( '' === $city || $n <= 0 ) continue;
		$ck  = $_slug( $city );
		$rid = $_rid( sanitize_text_field( (string) ( $row['region'] ?? '' ) ) );
		$ll  = $_city_latlng[ $ck ] ?? ( $_region_latlng[ $rid ] ?? null );
		if ( ! $ll ) continue;
		$leads = null;
		if ( in_array( 'project_slug', $_lc, true ) ) {
			$leads = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$_lt} l INNER JOIN {$_rt} r ON r.project_id = l.project_slug
				 WHERE COALESCE(NULLIF(r.city,''),NULLIF(r.commune,''),r.region) = %s", $city
			) );
		}
		$markers[] = [
			'city' => $city, 'sessions' => $n, 'leads' => $leads,
			'conversion' => ( null !== $leads && $n > 0 ) ? ( $leads / $n * 100 ) : null,
			'lat' => (float) $ll[0], 'lng' => (float) $ll[1],
			'r' => (float) max( 6, min( 18, 4 + ( $max_s > 0 ? $n / $max_s * 14 : 0 ) ) ),
		];
	}
	return [ 'markers' => $markers, 'unique_places' => count( $markers ), 'total_interactions' => array_sum( array_column( $markers, 'sessions' ) ) ];
}
endif;
?>


		<?php if ( $current_tab === 'panel' ) : ?>

		<?php // ══ TAB: PANEL ════════════════════════════════════════════════════ ?>

<?php
		$_banners = function_exists( 'estandar_core_fetch_dashboard_banners' ) ? estandar_core_fetch_dashboard_banners() : [];
		if ( ! empty( $_banners ) ) :
			$_b = $_banners[0];
		?>
		<div class="es-banner-strip" id="es-banner-strip" data-banners="<?php echo esc_attr( wp_json_encode( $_banners ) ); ?>">
			<div class="es-banner-inner">
				<div class="es-banner-icon">&#9733;</div>
				<div style="min-width:0;">
					<div class="es-banner-text" id="es-banner-text"><?php echo esc_html( (string) ( $_b['title'] ?? '' ) ); ?></div>
					<div class="es-banner-sub" id="es-banner-sub"><?php echo esc_html( (string) ( $_b['subtitle'] ?? '' ) ); ?></div>
				</div>
			</div>
			<div class="es-banner-nav">
				<div class="es-banner-pips" id="es-banner-pips">
					<?php foreach ( $_banners as $_i => $_bi ) : ?>
						<span class="es-banner-pip<?php echo $_i === 0 ? ' active' : ''; ?>" data-idx="<?php echo esc_attr( (string) $_i ); ?>"></span>
					<?php endforeach; ?>
				</div>
				<button class="es-banner-btn" id="es-banner-prev" aria-label="Anterior">&lsaquo;</button>
				<button class="es-banner-btn" id="es-banner-next" aria-label="Siguiente">&rsaquo;</button>
			</div>
		</div>
		<script>
		(function(){
			var strip=document.getElementById('es-banner-strip');
			if(!strip)return;
			var banners=JSON.parse(strip.dataset.banners||'[]');
			if(banners.length<2)return;
			var idx=0,txt=document.getElementById('es-banner-text'),sub=document.getElementById('es-banner-sub'),pips=document.querySelectorAll('#es-banner-pips .es-banner-pip');
			function show(i){idx=(i+banners.length)%banners.length;txt.textContent=banners[idx].title||'';sub.textContent=banners[idx].subtitle||'';pips.forEach(function(p,n){p.classList.toggle('active',n===idx);});}
			document.getElementById('es-banner-prev').onclick=function(){show(idx-1);};
			document.getElementById('es-banner-next').onclick=function(){show(idx+1);};
			pips.forEach(function(p){p.onclick=function(){show(parseInt(p.dataset.idx,10));};});
			setInterval(function(){show(idx+1);},4000);
		})();
		</script>
		<?php endif; ?>

		<?php
			$_leads_url     = admin_url( 'admin.php?page=estandar-leads' );
			$_analytics_url = admin_url( 'admin.php?page=ea-funnel' );

			// ── Period selector: drives funnel query dates ─────────────────────
			$_panel_period = isset( $_GET['panel_period'] ) ? sanitize_key( (string) $_GET['panel_period'] ) : '30d';
			if ( ! in_array( $_panel_period, [ 'today', '7d', '30d', 'all' ], true ) ) {
				$_panel_period = '30d';
			}
			$_panel_from = '';
			$_panel_to   = '';
			switch ( $_panel_period ) {
				case 'today':
					$_panel_from = wp_date( 'Y-m-d' );
					$_panel_to   = wp_date( 'Y-m-d' );
					break;
				case '7d':
					$_panel_from = wp_date( 'Y-m-d', strtotime( '-6 days' ) );
					break;
				case '30d':
					$_panel_from = wp_date( 'Y-m-d', strtotime( '-29 days' ) );
					break;
				// 'all': from/to stay empty
			}

			$_panel_stages         = [];
			$_panel_intent_breakdown = [];
		if ( class_exists( 'EA_Dashboard' ) && method_exists( 'EA_Dashboard', 'get_funnel' ) ) {
			$_panel_funnel = EA_Dashboard::get_funnel( [
				'from'     => $_panel_from,
				'to'       => $_panel_to,
				'source'   => '',
				'campaign' => '',
				'project'  => '',
			] );
			$_panel_stages           = $_panel_funnel['stages']           ?? [];
			$_panel_intent_breakdown = $_panel_funnel['intent_breakdown'] ?? [];
			}
				$_panel_first = max( 1, (int) ( $_panel_stages[0]['count'] ?? 0 ) );
				$_dashboard_sessions = (int) ( $_panel_stages[0]['count'] ?? $analytics_events_count );
				$_dashboard_conversion = $_dashboard_sessions > 0 ? ( $op['sales'] / $_dashboard_sessions * 100 ) : null;
				// GEO_ENGINE_UNIFIED_V1 — fuentes reales con IP propia: 'all' (Chatbot +
				// Forms sumados, default), 'chatbot', 'forms'. Ya no existe un provider de
				// "sesiones de navegación": ea_sessions no guarda IP, así que no hay forma
				// de geolocalizar tráfico puro sin lead ni conversación (ver hallazgo:
				// requeriría agregar captura de IP a esa tabla, decisión aparte).
				$_geo_provider = isset( $_GET['geo_provider'] ) ? sanitize_key( (string) $_GET['geo_provider'] ) : 'all';
				$_geo_provider  = in_array( $_geo_provider, [ 'all', 'chatbot', 'forms' ], true ) ? $_geo_provider : 'all';
				$_geo_days      = [ 'today' => 1, '7d' => 7, '30d' => 30, 'all' => 0 ][ $_panel_period ] ?? 30;
				$_geo_data      = estandar_core_geo_engine_data( $_geo_provider, $_geo_days );
				$_city_markers  = $_geo_data['markers'];
				$_panel_intent_channels = [
				'whatsapp' => [ 'icon' => '💬', 'label' => 'WhatsApp',   'color' => '#25D366' ],
				'form'     => [ 'icon' => '📝', 'label' => 'Formulario', 'color' => '#2271b1' ],
			'chatbot'  => [ 'icon' => '🤖', 'label' => 'Chatbot',    'color' => '#8b5cf6' ],
			'other'    => [ 'icon' => '∙',  'label' => 'Otros',      'color' => '#a7aaad' ],
			];
			$_activity_forms = 0;
			$_activity_chatbot = 0;
			foreach ( (array) $_panel_intent_breakdown as $_intent_row ) {
				$_intent_key = strtolower( (string) ( $_intent_row['channel'] ?? $_intent_row['key'] ?? $_intent_row['label'] ?? $_intent_row['source'] ?? '' ) );
				$_intent_count = absint( $_intent_row['count'] ?? $_intent_row['total'] ?? $_intent_row['sessions'] ?? 0 );
				if ( false !== strpos( $_intent_key, 'form' ) || false !== strpos( $_intent_key, 'formulario' ) ) {
					$_activity_forms += $_intent_count;
				}
				if ( false !== strpos( $_intent_key, 'chat' ) || false !== strpos( $_intent_key, 'bot' ) ) {
					$_activity_chatbot += $_intent_count;
				}
			}
			?>
			<?php
			// ─── KPI row: period selector + funnel stage cards ───────────────
			$_dash_has_issues = ! empty( $actions );
			$_period_labels   = [ 'today' => 'Hoy', '7d' => 'Últimos 7 días', '30d' => 'Últimos 30 días', 'all' => 'Todo el tiempo' ];
			$_panel_base_url  = admin_url( 'admin.php?page=estandar_core_diagnostics&tab=panel' );
			?>

			<?php // ── MENU_WARNING_EXPLAINER_V1: por qué el menú marca atención ── ?>
			<?php if ( function_exists( 'estandar_core_render_diag_menu_warning_explainer' ) ) { estandar_core_render_diag_menu_warning_explainer( true ); } ?>

			<?php // ── Period selector ──────────────────────────────────────────── ?>
			<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:6px;margin-bottom:5px;">
				<div style="display:flex;gap:4px;flex-wrap:wrap;">
					<?php foreach ( $_period_labels as $_pk => $_pl ) :
						$_pactive = $_panel_period === $_pk;
					?>
					<a href="<?php echo esc_url( $_panel_base_url . '&panel_period=' . $_pk . ( $_geo_provider !== 'all' ? '&geo_provider=' . rawurlencode( $_geo_provider ) : '' ) ); ?>"
					   style="display:inline-block;padding:2px 8px;font-size:12px;border-radius:4px;text-decoration:none;<?php echo $_pactive ? 'background:#1d2327;color:#fff;font-weight:600;' : 'background:#f0f0f1;color:#646970;'; ?>">
						<?php echo esc_html( $_pl ); ?>
					</a>
					<?php endforeach; ?>
				</div>
				<a href="<?php echo esc_url( $_analytics_url ); ?>" style="font-size:12px;color:#2271b1;">Ver Analytics completo →</a>
			</div>

			<?php // ── KPI cards (funnel stages, one per card) ──────────────────── ?>
			<?php
			// DASHBOARD_KPI_SIMPLIFICATION (2026-07): el panel oculta etapas no
			// accionables para el operador ("Intención comercial" = métrica de sesión
			// sin ruta clara; "Asignados" = detalle operativo). El funnel completo
			// sigue disponible en Analytics. Los % se recalculan contra la etapa
			// VISIBLE anterior para no referenciar etapas ocultas (mismo dataset).
			$_panel_stage_rows = array_values( array_filter( array_slice( $_panel_stages, 0, 8 ), static function ( $_s ): bool {
				return ! in_array( (string) ( $_s['label'] ?? '' ), [ 'Intención comercial', 'Asignados' ], true );
			} ) );
			$_panel_stage_rows = array_slice( $_panel_stage_rows, 0, 6 );
			$_kpi_prev = null;
			foreach ( $_panel_stage_rows as $_kri => $_krs ) {
				$_krc = (int) ( $_krs['count'] ?? 0 );
				$_panel_stage_rows[ $_kri ]['pct_visible'] = ( null !== $_kpi_prev && $_kpi_prev > 0 )
					? round( $_krc / $_kpi_prev * 100, 1 )
					: null;
				$_kpi_prev = $_krc;
			}
			// Rutas por etiqueta (los índices cambian según haya etapas de ads).
			$_kpi_hrefs_by_label = [
				'Leads'       => admin_url( 'admin.php?page=estandar-leads' ),
				'Calificados' => admin_url( 'admin.php?page=estandar-leads&quality=VALID' ),
				'Reuniones'   => admin_url( 'admin.php?page=estandar-leads&status=MEETING_BOOKED' ),
				'Reservas'    => admin_url( 'admin.php?page=estandar-leads&status=RESERVED' ),
				'Ventas'      => admin_url( 'admin.php?page=estandar-leads' ),
			];
			?>
			<?php if ( empty( $_panel_stage_rows ) ) : ?>
			<div style="padding:14px 16px;background:#f9f9f9;border:1px solid #e5e7eb;border-radius:6px;color:#646970;font-size:13px;margin-bottom:12px;">
				Sin datos para el período seleccionado. <a href="<?php echo esc_url( $_analytics_url ); ?>">Abrir Analytics →</a>
			</div>
			<?php else : ?>
			<div class="es-kpi-grid" style="grid-template-columns:repeat(<?php echo min( 6, max( 1, count( $_panel_stage_rows ) ) ); ?>,1fr);margin-bottom:5px;">
			<?php foreach ( $_panel_stage_rows as $_ki => $_ks ) :
				$_kc     = (int) ( $_ks['count'] ?? 0 );
				$_klabel = (string) ( $_ks['label'] ?? '' );
				$_kpct   = $_ks['pct_visible'] ?? null;
				// Solo etapas de leads son navegables; sesiones/ads no tienen ruta.
				$_khref  = ( ( $_ks['tier'] ?? '' ) === 'lead' )
					? ( $_kpi_hrefs_by_label[ $_klabel ] ?? admin_url( 'admin.php?page=estandar-leads' ) )
					: '';
			?>
			<?php if ( $_khref !== '' ) : ?><a class="es-kpi" href="<?php echo esc_url( $_khref ); ?>" style="text-decoration:none;display:block;"><?php else : ?><div class="es-kpi"><?php endif; ?>
				<span class="es-kpi-label" style="font-size:11px;"><?php echo esc_html( $_klabel ); ?></span>
				<span class="es-kpi-value"><?php echo esc_html( number_format_i18n( $_kc ) ); ?></span>
				<?php if ( $_klabel === 'Sesiones' ) : ?>
				<span style="font-size:10px;color:#a7aaad;display:block;margin-top:3px;font-style:italic;">Visitas al sitio</span>
				<?php elseif ( null !== $_kpct ) : ?>
				<span style="font-size:11px;color:#a7aaad;display:block;margin-top:1px;"><?php echo esc_html( number_format_i18n( (float) $_kpct, 1 ) . ' %' ); ?> de ant.</span>
				<?php endif; ?>
			<?php echo $_khref !== '' ? '</a>' : '</div>'; ?>
			<?php endforeach; ?>
			</div>
			<?php endif; // end KPI cards ?>

			<?php // ─── MAP (after funnel, reduced height + context strip) ──────── ?>
			<div class="es-analytics-map" aria-label="Mapa de actividad geográfica" style="margin-bottom:12px;">
				<div style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px 8px;">
					<div>
						<span style="font-size:13px;font-weight:600;color:#1d2327;">Actividad geográfica</span>
						<span style="font-size:11px;color:#a7aaad;margin-left:10px;">
							<?php
							$_geo_label_map = [ 'all' => 'Todas', 'chatbot' => 'Chatbot', 'forms' => 'Forms' ];
							$_geo_points    = count( $_city_markers );
							echo esc_html( ( $_geo_label_map[ $_geo_provider ] ?? $_geo_provider ) . ' · ' . number_format_i18n( $_geo_points ) . ' ' . ( $_geo_points === 1 ? 'ciudad' : 'ciudades' ) );
							?>
						</span>
					</div>
					<form method="get" style="margin:0;">
						<?php foreach ( array_diff_key( (array) $_GET, [ 'geo_provider' => 1 ] ) as $_gpk => $_gpv ) : ?>
							<input type="hidden" name="<?php echo esc_attr( (string) $_gpk ); ?>" value="<?php echo esc_attr( (string) $_gpv ); ?>">
						<?php endforeach; ?>
						<select name="geo_provider" onchange="this.form.submit()" style="font-size:12px;padding:3px 6px;">
							<?php foreach ( $_geo_label_map as $_gpkey => $_gplabel ) : ?>
								<option value="<?php echo esc_attr( $_gpkey ); ?>"<?php selected( $_geo_provider, $_gpkey ); ?>><?php echo esc_html( $_gplabel ); ?></option>
							<?php endforeach; ?>
						</select>
					</form>
				</div>
				<div class="es-analytics-map__visual" style="position:relative;">
					<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="" />
					<?php if ( empty( $_city_markers ) ) : ?>
						<div class="es-analytics-map__empty" style="height:70vh;min-height:320px;display:flex;align-items:center;justify-content:center;background:#f9f9f9;color:#646970;"><?php
							if ( $_geo_provider === 'chatbot' ) {
								echo esc_html( 'Sin actividad del Chatbot para mostrar.' );
							} elseif ( $_geo_provider === 'forms' ) {
								echo esc_html( 'Sin ubicaciones aún: se pintan cuando llegan formularios con IP pública geolocalizable.' );
							} else {
								echo esc_html( 'Sin actividad para mostrar.' );
							}
							?></div>
					<?php else : ?>
						<div style="flex:1 1 100%;min-width:0;width:100%;">
							<div id="es-core-geo-map" style="height:70vh;min-height:320px;width:100%;overflow:hidden;border-top:0;border-radius:0 0 8px 8px;"></div>
						</div>
						<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
						<script src="https://unpkg.com/leaflet.heat/dist/leaflet-heat.js" crossorigin=""></script>
						<script>
						(function(){
							var markers = <?php echo wp_json_encode( $_city_markers ); ?>;
							var mapEl = document.getElementById('es-core-geo-map');
							if (!mapEl || !window.L || !Array.isArray(markers) || !markers.length) return;
							function escHtml(v) { return String(v==null?'':v).replace(/[&<>"']/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }
							var map = L.map(mapEl, { scrollWheelZoom: false, center: [-33.4489, -70.6693], zoom: 5 });
							L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 18, attribution: '&copy; OpenStreetMap contributors' }).addTo(map);
							var valid = markers.filter(function(m){ return m.lat != null && m.lng != null; });
							var maxS = Math.max.apply(null, valid.map(function(m){ return Number(m.sessions)||0; }));
							var bounds = [], heat = [];
							if (!valid.length) return;
							valid.forEach(function(m){
								var s = Number(m.sessions)||0;
								var r = Math.max(6, Math.min(18, 4 + (maxS > 0 ? s/maxS*14 : 0)));
								var conv = m.conversion !== null ? Math.round(m.conversion*10)/10 : null;
								var popup = '<strong>' + escHtml(m.city) + '</strong>'
									+ '<br>Sesiones: ' + escHtml(s)
									+ (m.leads !== null ? '<br>Leads: ' + escHtml(m.leads) : '')
									+ (conv !== null ? '<br>Conversión: ' + escHtml(conv) + '%' : '');
								L.circleMarker([m.lat, m.lng], { radius: r, color: '#0f172a', weight: 1, fillColor: '#2563eb', fillOpacity: 0.65 }).bindPopup(popup).addTo(map);
								bounds.push([m.lat, m.lng]);
								heat.push([m.lat, m.lng, Math.max(0.3, Math.min(1, s / Math.max(1, maxS)))]);
							});
							if (window.L.heatLayer && heat.length) { L.heatLayer(heat, { radius: 30, blur: 24, maxZoom: 10 }).addTo(map); }
							if (bounds.length === 1) { map.setView(bounds[0], 8); }
							else if (bounds.length > 1) { map.fitBounds(bounds, { padding: [24, 24] }); }
						})();
						</script>
					<?php endif; ?>
				</div>
			</div>

		<?php // ─── Operational alerts (below map) — resumen, no la lista completa.
		// El detalle vive en Estado del ecosistema (feed único con las anomalías
		// de registry); mostrarlo dos veces completo no ayudaba a "entender el
		// sistema" — un solo lugar es dueño del detalle, Panel solo avisa. ── ?>
			<?php if ( $_dash_has_issues ) : ?>
			<?php
				$_dash_errors = count( array_filter( $actions, static fn( $a ) => 'ERROR' === (string) ( $a['level'] ?? 'WARN' ) ) );
				$_dash_warns  = count( $actions ) - $_dash_errors;
				$_dash_eco_url = admin_url( 'admin.php?page=estandar_core_diagnostics&tab=estado_ecosistema' );
			?>
			<a href="<?php echo esc_url( $_dash_eco_url ); ?>" style="display:flex;align-items:center;gap:10px;margin-top:12px;padding:8px 12px;background:<?php echo $_dash_errors > 0 ? '#fdecec' : '#fff8e1'; ?>;border:1px solid <?php echo $_dash_errors > 0 ? '#fecaca' : '#fde68a'; ?>;border-radius:5px;font-size:12px;text-decoration:none;color:<?php echo $_dash_errors > 0 ? '#dc2626' : '#b7791f'; ?>;">
				<span style="font-size:14px;">⚠</span>
				<span style="flex:1;color:#374151;">
					<?php
					$_dash_parts = [];
					if ( $_dash_errors > 0 ) { $_dash_parts[] = $_dash_errors . ( 1 === $_dash_errors ? ' error' : ' errores' ); }
					if ( $_dash_warns > 0 )  { $_dash_parts[] = $_dash_warns . ( 1 === $_dash_warns ? ' atención' : ' atenciones' ); }
					echo esc_html( implode( ' · ', $_dash_parts ) . ' detectada(s) en el ecosistema' );
					?>
				</span>
				<span style="font-weight:600;">Ver detalle →</span>
			</a>
			<?php endif; ?>

		<?php elseif ( $current_tab === 'configuracion' ) : ?>

			<?php // ══ TAB: CONFIGURACIÓN COMPARTIDA ═══════════════════════════════ ?>

			<div style="margin-bottom:16px;">
				<h2 style="margin:0 0 4px;">Configuración compartida</h2>
				<p class="description" style="margin:0;">Identidad, comunicaciones, credenciales IA y horario operacional del ecosistema Estandar.</p>
			</div>

			<?php // ── Health summary — progressive disclosure ─────────────────── ?>
			<?php
			// GEO_CFG_CARDS_UNIFY_V1 — cada tarjeta de esta grilla ahora es
			// clickeable con un destino real: 'key' abre/cierra un panel EN esta
			// misma página (misma lógica de siempre); 'href' navega a la página
			// donde vive esa configuración (Analytics/Operaciones no tienen panel
			// propio aquí — antes quedaban como cajas sin acción). Horario
			// operacional y Extensiones existían como secciones sueltas al final
			// de la página, sin tarjeta que las representara arriba — se agregan
			// aquí para que TODA la página siga la misma lógica tarjeta→panel.
			$_oh_class_active = class_exists( 'Estandar_Operational_Hours' );
			$_oh_precheck      = $_oh_class_active && ! empty( Estandar_Operational_Hours::get_config()['enabled'] );
			$_ext_sections     = function_exists( 'estandar_get_settings_sections' ) ? estandar_get_settings_sections() : [];

			// ORDEN LÓGICO DE IMPLEMENTACIÓN (no orden de creación en código):
			// 1 Identidad → 2 Comunicaciones → 3 Horario → 4 Compliance → 5 IA →
			// 6 Integraciones publicitarias → 7 Analytics → 8 Extensiones → 9 Deployment.
			// Sigue el orden real en que se configura un sitio nuevo: primero quién
			// somos y cómo notificamos, luego las reglas que dependen de eso
			// (Compliance depende de Comunicaciones+Horario), luego credenciales de
			// canales (IA, Ads), luego medición (Analytics), y al final lo variable
			// (Extensiones) y la verificación de que todo quedó bien (Deployment).
			$_cfg_health = [
				'identidad' => [ 'key' => 'identidad', 'label' => 'Identidad', 'ok' => $client_slug !== '' && $client_name !== '',
					'desc' => 'Nombre e identificador del sitio. Sin esto, leads, telemetría y registry no pueden atribuirse a este cliente. · Primer' ],
				// COMMS_CARD_UNIFY_V1 — tarjeta-enlace, no panel propio. La página
				// completa estandar_communications (Identidad/Notif. Leads/Compliance/
				// Digest/Reportes/Autorespuesta/Inventario) ya existe y ya edita estos
				// mismos campos — un panel duplicado aquí generaba dos formularios
				// escribiendo en lugares distintos (Reply-To llegó a tener DOS opciones
				// activas a la vez). Single source: la página completa.
				'comunicaciones' => [ 'key' => '', 'href' => admin_url( 'admin.php?page=estandar_communications' ), 'label' => 'Comunicaciones', 'ok' => $sender_name_val !== '' && $sender_email_val !== '' && $sender_ok,
					'desc' => 'Remitente, reply-to, logo de emails, notificaciones, compliance, digest y reportes de leads. · Estandar Leads' ],
				'horario' => [ 'key' => $_oh_class_active ? 'horario' : '', 'label' => 'Horario operacional', 'ok' => $_oh_precheck,
					'desc' => 'Horario de atención comercial — fuera de horario, las notificaciones a operadores se difieren. · Estandar Leads' ],
				'compliance' => [ 'key' => function_exists( 'estandar_core_render_compliance_shadow_copy_card' ) ? 'compliance' : '', 'label' => 'Compliance', 'ok' => $compliance_active,
					'desc' => 'Motor de recordatorios: escala leads sin clasificar al operador y luego al supervisor. · Estandar Leads' ],
				'ia' => [ 'key' => 'ia', 'label' => 'IA', 'ok' => estandar_core_get_secret( 'openai' ) !== '',
					'desc' => 'Claves de proveedores de IA (OpenAI, etc.) compartidas — fallback cuando el plugin no trae clave propia. · Estandar Chatbot' ],
			];
			// Integraciones publicitarias (Meta CAPI / Google Ads) — ya centralizadas
			// en Proyectos → Integraciones (Estandar_Integration_Registry), pero sin
			// tarjeta acá nadie las descubría desde Configuración compartida.
			if ( class_exists( 'Estandar_Integration_Registry' ) ) {
				$_ads_configured = false;
				foreach ( Estandar_Integration_Registry::all() as $_ads_provider ) {
					if ( ! empty( $_ads_provider->status()['configured'] ) ) {
						$_ads_configured = true;
						break;
					}
				}
				$_cfg_health['integraciones'] = [ 'key' => '', 'href' => admin_url( 'admin.php?page=estandar_projects_integrations' ), 'label' => 'Integraciones publicitarias', 'ok' => $_ads_configured,
					'desc' => 'Credenciales de Meta CAPI y Google Ads para reportar conversiones. · Estandar Leads / Signal Core' ];
			}
			$_cfg_health['analytics'] = [ 'key' => '', 'href' => $_analytics_url ?? admin_url( 'admin.php?page=ea-funnel' ), 'label' => 'Analytics', 'ok' => $analytics_events_count > 0,
				'desc' => 'Tráfico, sesiones y funnel de conversión del sitio. · Estandar Analytics' ];
			if ( ! empty( $_ext_sections ) ) {
				$_cfg_health['extensiones'] = [ 'key' => 'extensiones', 'label' => 'Extensiones', 'ok' => true,
					'desc' => 'Bloques de configuración adicionales que otros plugins registran dinámicamente. · Varios plugins' ];
			}
			// 'Operaciones' medía $deployment_status (salud del deploy) pero enlazaba a
			// Leads → Reportes (negocio) — el semáforo y el destino no correspondían.
			// El Deployment Validator real vive en Estado del ecosistema → Información
			// avanzada; se corrige el destino para que coincida con lo que se mide.
			$_cfg_health['deployment'] = [ 'key' => '', 'href' => admin_url( 'admin.php?page=estandar_core_diagnostics&tab=estado_ecosistema' ), 'label' => 'Deployment', 'ok' => 'PASS' === $deployment_status,
				'desc' => 'Verifica que el sitio quedó correctamente desplegado (assets, versiones, esquema). · Primer' ];
			$_cfg_health = array_values( $_cfg_health );
			?>
			<style>
				.es-cfg-icon { font-size: 28px; width: 28px; height: 28px; flex-shrink: 0; opacity: .65; }
				@media (max-width: 782px) { .es-cfg-icon { font-size: 24px; width: 24px; height: 24px; } }
				.es-cfg-card[data-es-cfg-card]:hover { filter: brightness(0.98); }
				.es-cfg-card[data-es-cfg-card]:focus-visible { outline: 2px solid #2271b1; outline-offset: 1px; }
				[data-es-cfg-panel].es-cfg-opening { animation: esCfgSlide .18s ease-out; }
				@keyframes esCfgSlide { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: none; } }
			</style>
			<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:18px;">
			<?php foreach ( $_cfg_health as $_ch ) :
				$_ch_ok   = (bool) $_ch['ok'];
				$_ch_key  = (string) $_ch['key'];
				$_ch_href = (string) ( $_ch['href'] ?? '' );
				$_ch_tag  = '' !== $_ch_href ? 'a' : 'div';
			?>
			<<?php echo esc_html( $_ch_tag ); ?>
				<?php if ( '' !== $_ch_href ) : ?>
					href="<?php echo esc_url( $_ch_href ); ?>"
				<?php elseif ( $_ch_key !== '' ) : ?>
					class="es-cfg-card" data-es-cfg-card="<?php echo esc_attr( $_ch_key ); ?>" role="button" tabindex="0" aria-expanded="<?php echo $_ch_ok ? 'false' : 'true'; ?>" aria-controls="es-cfg-panel-<?php echo esc_attr( $_ch_key ); ?>"
				<?php endif; ?>
				style="display:flex;align-items:center;gap:8px;padding:8px 12px;background:<?php echo $_ch_ok ? '#f0fdf4' : '#fffbeb'; ?>;border:1px solid <?php echo $_ch_ok ? '#bbf7d0' : '#fde68a'; ?>;border-radius:6px;text-decoration:none;<?php echo ( $_ch_key !== '' || '' !== $_ch_href ) ? 'cursor:pointer;user-select:none;' : ''; ?>">
				<?php
				$_icon_key = $_ch_key !== '' ? $_ch_key : sanitize_title( (string) $_ch['label'] );
				$_icon_cls = estandar_diag_icon( $_icon_key );
				?>
				<span class="dashicons <?php echo esc_attr( $_icon_cls ); ?> es-cfg-icon" aria-hidden="true" style="color:<?php echo $_ch_ok ? '#166534' : '#92400e'; ?>;"></span>
				<div style="flex:1;min-width:0;">
					<span style="font-size:12px;font-weight:600;color:<?php echo $_ch_ok ? '#166534' : '#92400e'; ?>;"><?php echo esc_html( $_ch['label'] ); ?></span>
					<?php if ( ! empty( $_ch['desc'] ) ) : ?>
						<span style="font-size:11px;color:#6b7280;display:block;margin-top:1px;line-height:1.3;"><?php echo esc_html( (string) $_ch['desc'] ); ?></span>
					<?php endif; ?>
					<span style="font-size:11px;color:<?php echo $_ch_ok ? '#15803d' : '#b45309'; ?>;display:block;margin-top:2px;">
						<?php
						if ( '' !== $_ch_href ) {
							echo $_ch_ok ? 'Configurado · Click para ver' : 'Pendiente · Click para ver';
						} elseif ( '' === $_ch_key ) {
							echo $_ch_ok ? 'Configurado' : 'Pendiente';
						} else {
							echo $_ch_ok ? 'Configurado · Click para editar' : 'Incompleta · Completar configuración';
						}
						?>
					</span>
				</div>
				<?php if ( $_ch_key !== '' || '' !== $_ch_href ) : ?>
					<span class="es-cfg-chev" aria-hidden="true" style="font-size:10px;color:<?php echo $_ch_ok ? '#15803d' : '#b45309'; ?>;"><?php echo '' !== $_ch_href ? '↗' : ( $_ch_ok ? '▸' : '▾' ); ?></span>
				<?php endif; ?>
			</<?php echo esc_html( $_ch_tag ); ?>>
			<?php endforeach; ?>
			</div>

			<?php
			// Panel open/closed state derives from the SAME card predicates
			// (rule: card = summary, panel = editor; status lives on the card).
			$_cfg_ok = [];
			foreach ( $_cfg_health as $_ch ) {
				if ( '' !== (string) $_ch['key'] ) {
					$_cfg_ok[ (string) $_ch['key'] ] = (bool) $_ch['ok'];
				}
			}
			?>

			<?php // ── 1a. Identidad (panel: card "Identidad") ──────────────────── ?>
			<div class="es-diag-section" id="es-cfg-panel-identidad" data-es-cfg-panel="identidad" <?php if ( $_cfg_ok['identidad'] ) : ?>style="display:none;margin-bottom:12px;"<?php else : ?>style="margin-bottom:12px;"<?php endif; ?>>
				<div class="es-diag-section-header">Identidad</div>
				<div class="es-diag-section-body">
					<p class="description" style="margin-top:0;">Identifica este sitio en el ecosistema Estandar.</p>

					<?php if ( ! $_cfg_ok['identidad'] ) : ?>
						<div style="background:#fffbeb;border-left:3px solid #dba617;padding:8px 10px;margin:0 0 12px;font-size:12px;color:#92400e;border-radius:0 3px 3px 0;">
							<strong>Configuración incompleta.</strong>
							Falta: <?php
							$_cfg_missing = [];
							if ( $client_name === '' ) { $_cfg_missing[] = 'nombre de la empresa'; }
							if ( $client_slug === '' ) { $_cfg_missing[] = 'identificador'; }
							echo esc_html( implode( ' y ', $_cfg_missing ) );
							?>.
							Sin identidad, leads, telemetría y registry no pueden atribuirse a este cliente.
							Completar los campos y pulsar «Guardar identidad».
						</div>
					<?php endif; ?>

					<?php if ( ! empty( $site_identity['issues'] ) && is_array( $site_identity['issues'] ) ) : ?>
						<div class="notice notice-error" style="margin:0 0 12px;"><p><?php echo esc_html( implode( ' ', array_map( 'strval', $site_identity['issues'] ) ) ); ?></p></div>
					<?php endif; ?>

					<form method="post" action="options.php">
						<?php settings_fields( 'estandar_core_site_identity_settings' ); ?>
						<p style="margin:0 0 10px;">
							<label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;" for="<?php echo esc_attr( ESTANDAR_CLIENT_NAME_OPTION ); ?>">Nombre de la empresa</label>
							<input type="text" class="large-text" id="<?php echo esc_attr( ESTANDAR_CLIENT_NAME_OPTION ); ?>" name="<?php echo esc_attr( ESTANDAR_CLIENT_NAME_OPTION ); ?>" value="<?php echo esc_attr( $client_name ); ?>">
							<span style="font-size:11px;color:#9ca3af;display:block;margin-top:3px;">Ejemplo: Empresa Ejemplo</span>
						</p>
						<p style="margin:0 0 14px;">
							<label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;" for="<?php echo esc_attr( ESTANDAR_CLIENT_SLUG_OPTION ); ?>">Identificador</label>
							<input type="text" class="large-text" id="<?php echo esc_attr( ESTANDAR_CLIENT_SLUG_OPTION ); ?>" name="<?php echo esc_attr( ESTANDAR_CLIENT_SLUG_OPTION ); ?>" value="<?php echo esc_attr( $client_slug ); ?>">
							<span style="font-size:11px;color:#9ca3af;display:block;margin-top:3px;">No cambiar en producción salvo migración planificada. Ejemplo: ejemplo-cliente</span>
						</p>
						<?php submit_button( 'Guardar identidad', 'primary small', 'submit', false ); ?>
					</form>
				</div>
			</div>
			<?php // 1b. Comunicaciones — ya no tiene panel propio en esta página; la
			// tarjeta enlaza directo a estandar_communications (ver $_cfg_health). ?>

			<?php // ── 3. Credenciales IA (panel: card "IA") ─────────────────────── ?>
			<div class="es-diag-section" id="es-cfg-panel-ia" data-es-cfg-panel="ia" <?php if ( $_cfg_ok['ia'] ) : ?>style="display:none;margin-bottom:12px;"<?php else : ?>style="margin-bottom:12px;"<?php endif; ?>>
				<div class="es-diag-section-header">Credenciales IA</div>
				<div class="es-diag-section-body">
					<p class="description" style="margin-top:0;">Claves de proveedores IA usadas como fallback cuando el plugin consumidor no tiene clave propia configurada.</p>
					<?php if ( ! $_cfg_ok['ia'] ) : ?>
						<div style="background:#fffbeb;border-left:3px solid #dba617;padding:8px 10px;margin:0 0 12px;font-size:12px;color:#92400e;border-radius:0 3px 3px 0;">
							<strong>Configuración incompleta.</strong>
							Falta: clave OpenAI compartida.
							Sin ella, el chatbot solo funciona si el plugin tiene clave propia configurada.
							Pegar la clave y pulsar «Guardar credenciales».
						</div>
					<?php endif; ?>
					<form method="post" action="options.php">
						<?php settings_fields( 'estandar_core_shared_credentials_settings' ); ?>
						<?php
						$_ai_active = [];
						$_ai_avail  = [];
						foreach ( estandar_core_shared_secret_options() as $_aip => $_aio ) {
							if ( estandar_core_get_secret( (string) $_aip ) !== '' ) {
								$_ai_active[ $_aip ] = $_aio;
							} else {
								$_ai_avail[ $_aip ] = $_aio;
							}
						}
						?>

						<?php if ( ! empty( $_ai_active ) ) : ?>
						<p style="margin:0 0 8px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#166534;">● Activas</p>
						<?php foreach ( $_ai_active as $_aip => $_aio ) : ?>
						<p style="margin:0 0 10px;">
							<label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;" for="<?php echo esc_attr( $_aio ); ?>"><?php echo esc_html( $shared_secret_labels[ $_aip ] ?? $_aip ); ?></label>
							<input type="password" class="large-text" id="<?php echo esc_attr( $_aio ); ?>" name="<?php echo esc_attr( $_aio ); ?>" value="" autocomplete="off" placeholder="Configurada. Dejar vacío para conservar.">
							<span style="font-size:11px;color:#6b7280;display:block;margin-top:3px;"><?php echo esc_html( $shared_secret_help[ $_aip ] ?? '' ); ?></span>
						</p>
						<?php endforeach; ?>
						<?php endif; ?>

						<?php if ( ! empty( $_ai_avail ) ) : ?>
						<?php if ( ! empty( $_ai_active ) ) : ?><div style="border-top:1px solid #f3f4f6;margin:12px 0 10px;"></div><?php endif; ?>
						<p style="margin:0 0 8px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;">○ Disponibles</p>
						<?php foreach ( $_ai_avail as $_aip => $_aio ) : ?>
						<p style="margin:0 0 10px;">
							<label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;" for="<?php echo esc_attr( $_aio ); ?>"><?php echo esc_html( $shared_secret_labels[ $_aip ] ?? $_aip ); ?></label>
							<input type="password" class="large-text" id="<?php echo esc_attr( $_aio ); ?>" name="<?php echo esc_attr( $_aio ); ?>" value="" autocomplete="off" placeholder="">
							<span style="font-size:11px;color:#6b7280;display:block;margin-top:3px;"><?php echo esc_html( $shared_secret_help[ $_aip ] ?? '' ); ?></span>
						</p>
						<?php endforeach; ?>
						<?php endif; ?>

						<?php submit_button( 'Guardar credenciales', 'primary small', 'submit', false ); ?>
					</form>
				</div>
			</div>

			<?php // ── 4. Compliance (panel: card "Compliance") ──────────────────── ?>
			<?php if ( function_exists( 'estandar_core_render_compliance_shadow_copy_card' ) ) : ?>
			<div id="es-cfg-panel-compliance" data-es-cfg-panel="compliance" <?php if ( $_cfg_ok['compliance'] ) : ?>style="display:none;"<?php endif; ?>>
				<?php if ( ! $_cfg_ok['compliance'] ) : ?>
					<div style="background:#fffbeb;border-left:3px solid #dba617;padding:8px 10px;margin:0 0 8px;font-size:12px;color:#92400e;border-radius:0 3px 3px 0;">
						<strong>Configuración incompleta.</strong>
						Falta: activar el Compliance Engine (recordatorios de clasificación a operadores).
						Sin él, los leads sin clasificar no escalan y la clasificación depende de memoria humana.
						Completar: activarlo en la configuración de Leads.
					</div>
				<?php endif; ?>
				<div style="display:flex;align-items:center;gap:8px;padding:6px 14px;background:<?php echo $compliance_active ? '#f0fdf4' : '#fffbeb'; ?>;border:1px solid <?php echo $compliance_active ? '#bbf7d0' : '#fde68a'; ?>;border-radius:6px 6px 0 0;margin-bottom:0;">
					<span style="font-size:12px;font-weight:600;color:<?php echo $compliance_active ? '#166534' : '#92400e'; ?>;"><?php echo $compliance_active ? '● Compliance ejecutándose' : '⚠ Compliance pendiente de configuración'; ?></span>
				</div>
				<?php estandar_core_render_compliance_shadow_copy_card(); ?>
			</div>
				<?php endif; ?>

				<?php // ── Extensiones registradas por plugins (panel: card "Extensiones") ── ?>
				<?php if ( ! empty( $_ext_sections ) ) : ?>
				<div id="es-cfg-panel-extensiones" data-es-cfg-panel="extensiones" style="margin-top:20px;display:none;">
					<?php estandar_render_settings_sections(); ?>
				</div>
				<?php endif; ?>

				<?php // ── Horario Operacional ───────────────────���─────────────────── ?>
			<?php if ( class_exists( 'Estandar_Operational_Hours' ) ) : ?>
			<?php
				$_oh_config = Estandar_Operational_Hours::get_config();
				$_oh_day_labels = array(
					'mon' => 'Lunes', 'tue' => 'Martes', 'wed' => 'Miércoles',
					'thu' => 'Jueves', 'fri' => 'Viernes', 'sat' => 'Sábado', 'sun' => 'Domingo',
				);
				$_oh_status = Estandar_Operational_Hours::human_message();
			?>
			<div class="es-diag-section" id="es-cfg-panel-horario" data-es-cfg-panel="horario" style="margin-top:20px;<?php echo $_cfg_ok['horario'] ? 'display:none;' : ''; ?>">
				<div class="es-diag-section-header">
					Horario operacional
					<?php if ( $_oh_config['enabled'] ) : ?>
						<span style="background:<?php echo $_oh_status['is_open'] ? '#dcfce7' : '#fef3c7'; ?>;color:<?php echo $_oh_status['is_open'] ? '#166534' : '#92400e'; ?>;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;">
							<?php echo $_oh_status['is_open'] ? '● Abierto' : '○ Fuera de horario'; ?>
						</span>
					<?php else : ?>
						<span style="background:#f3f4f6;color:#6b7280;padding:2px 8px;border-radius:3px;font-size:11px;">Desactivado</span>
					<?php endif; ?>
				</div>
				<div class="es-diag-section-body">
					<p class="description" style="margin-top:0;">El horario operacional ahora se configura por operador en <strong>Leads → Operadores</strong>. Cada operador puede definir su propia zona horaria, días y horas de atención de forma independiente.</p>

					<?php if ( $_oh_config['enabled'] ) : ?>
					<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:14px;">
						<div style="padding:8px 10px;background:#f6f7f7;border-radius:4px;">
							<span style="font-size:11px;font-weight:600;color:#646970;text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:2px;">Zona horaria (default)</span>
							<span style="font-size:13px;color:#1d2327;"><?php echo esc_html( $_oh_config['timezone'] ?? 'America/Santiago' ); ?></span>
						</div>
						<div style="padding:8px 10px;background:<?php echo $_oh_status['is_open'] ? '#f0fdf4' : '#fffbeb'; ?>;border-radius:4px;">
							<span style="font-size:11px;font-weight:600;color:#646970;text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:2px;">Estado actual</span>
							<span style="font-size:13px;color:<?php echo $_oh_status['is_open'] ? '#166534' : '#92400e'; ?>;"><?php echo $_oh_status['is_open'] ? '● Abierto' : '○ Fuera de horario'; ?></span>
						</div>
						<div style="padding:8px 10px;background:#f6f7f7;border-radius:4px;">
							<span style="font-size:11px;font-weight:600;color:#646970;text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:2px;"><?php echo $_oh_status['is_open'] ? 'Próximo cierre' : 'Próxima apertura'; ?></span>
							<span style="font-size:13px;color:#1d2327;"><?php echo esc_html( $_oh_status['next_window'] ?? '—' ); ?></span>
						</div>
					</div>
					<?php endif; ?>

					<div style="padding:10px 14px;background:#f0f6fc;border:1px solid #c3d9ed;border-radius:4px;">
						<p style="margin:0;font-size:12px;color:#1d2327;">La configuración global se conserva como valor inicial para operadores que aún no tengan horario propio. Para modificar el horario de cada operador, usa la pantalla de <a href="<?php echo esc_url( admin_url( 'admin.php?page=estandar_projects_operators' ) ); ?>">Operadores</a>.</p>
					</div>
				</div>
			</div>
			<?php endif; ?>

			<?php // ── Progressive disclosure: cards ↔ panels (UI only) ─────────── ?>
			<script>
			(function(){
				var cards = [].slice.call(document.querySelectorAll("[data-es-cfg-card]"));
				if (!cards.length) { return; }

				function toggle(card) {
					var key   = card.getAttribute("data-es-cfg-card");
					var panel = document.querySelector('[data-es-cfg-panel="' + key + '"]');
					if (!panel) { return; }
					var isOpen = panel.style.display !== "none";
					if (isOpen) {
						panel.style.display = "none";
						panel.classList.remove("es-cfg-opening");
					} else {
						panel.style.display = "";
						panel.classList.add("es-cfg-opening");
						// keep scroll position; just make sure the editor is reachable
						panel.scrollIntoView({ behavior: "smooth", block: "nearest" });
					}
					card.setAttribute("aria-expanded", isOpen ? "false" : "true");
					var chev = card.querySelector(".es-cfg-chev");
					if (chev) { chev.textContent = isOpen ? "▸" : "▾"; }
				}

				cards.forEach(function(card){
					card.addEventListener("click", function(){ toggle(card); });
					card.addEventListener("keydown", function(e){
						if (e.key === "Enter" || e.key === " " || e.key === "Spacebar") {
							e.preventDefault();
							toggle(card);
						}
					});
				});
			})();
			</script>

		<?php elseif ( $current_tab === 'estado_ecosistema' ) : ?>

		<?php // ══ VISTA: ESTADO DEL ECOSISTEMA ══════════════════════════════════ ?>

		<?php
		$_eco_has_issues = ! empty( $actions ) || $version_health['incompatible'] > 0;
		$_eco_summary    = $_eco_has_issues
			? ( ! empty( $actions ) ? count( $actions ) . ( count( $actions ) === 1 ? ' incidencia detectada' : ' incidencias detectadas' ) : '' )
			  . ( $version_health['incompatible'] > 0 ? ( ! empty( $actions ) ? ' · ' : '' ) . $version_health['incompatible'] . ' plugin(s) incompatible(s)' : '' )
			: 'Todo verificado correctamente. Sin incidencias críticas.';
		?>

		<?php // ── 1. HERO ──────────────────────────────────────────────────────── ?>
		<div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:16px;padding-bottom:14px;border-bottom:1px solid #f0f0f1;">
			<div>
				<span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#646970;display:block;">Estandar Primer</span>
				<h2 style="margin:2px 0 4px;font-size:20px;line-height:1.2;">Estado del ecosistema</h2>
				<p style="margin:0;font-size:13px;color:<?php echo $_eco_has_issues ? '#92400e' : '#166534'; ?>;"><?php echo esc_html( $_eco_summary ); ?></p>
			</div>
			<span style="<?php echo esc_attr( $badge_style( ! $_eco_has_issues && 'PASS' === $deployment_status ? 'PASS' : ( $_eco_has_issues ? 'ERROR' : 'OK' ) ) ); ?>;font-size:12px;white-space:nowrap;margin-top:4px;">
				<?php echo ! $_eco_has_issues && 'PASS' === $deployment_status ? 'PASS' : ( $_eco_has_issues ? 'ATENCIÓN' : $deployment_status ); ?>
			</span>
		</div>

		<?php
		// CARDS_UNIFY_V1 — mismo patrón tarjeta→panel que Configuración compartida
		// y Comunicaciones. Antes esta pestaña era una fila fija de cards siempre
		// abiertas (Incidencias / Auditor / Estado operacional / Ecosistema) más
		// un <details> nativo aparte para "Información avanzada" — dos mecanismos
		// de progressive disclosure distintos en la misma página.
		$_integrity_findings = function_exists( 'estandar_core_integrity_findings' ) ? estandar_core_integrity_findings() : array( 'cards' => array() );
		$_registry_health    = function_exists( 'estandar_core_registry_health' ) ? estandar_core_registry_health() : array();
		$_registry_primary_issues = count( (array) ( $_registry_health['registry']['without_operator'] ?? array() ) )
			+ count( (array) ( $_registry_health['forms']['issues'] ?? array() ) )
			+ count( (array) ( $_registry_health['leads']['orphans'] ?? array() ) );

		// ORDEN LÓGICO: de lo más urgente/accionable a lo más profundo/técnico —
		// 1 Incidencias activas ahora → 2 Reglas estructurales del ecosistema →
		// 3 Salud funcional (Proyectos/Leads/Chatbot) → 4 Compatibilidad de
		// versiones entre plugins → 5 Detalle técnico para desarrolladores.
		$_eco_health = array(
			array( 'key' => 'incidencias',  'label' => 'Incidencias del sistema', 'ok' => empty( $actions ),
				'desc' => 'Alertas activas ahora: identidad sin configurar, SMTP fallando, o esquema de BD pendiente de migrar. · Primer + Estandar Leads + Estandar Forms' ),
			array( 'key' => 'integridad',   'label' => 'Auditor de integridad',   'ok' => empty( $_integrity_findings['cards'] ),
				'desc' => 'Reglas estructurales del ecosistema (nombres, capacidades, configuración) con auto-corrección cuando es seguro. · Primer' ),
			array( 'key' => 'operacional',  'label' => 'Estado operacional',      'ok' => 0 === $_registry_primary_issues,
				'desc' => 'Proyectos sin operador, routing de Forms inválido, leads huérfanos y estado de configuración del Chatbot. · Primer + Estandar Leads + Estandar Chatbot' ),
			array( 'key' => 'plugins',      'label' => 'Ecosistema de plugins',   'ok' => 0 === $version_health['incompatible'],
				'desc' => 'Qué versión corre cada plugin y si son compatibles entre sí — la base de la que depende todo lo demás en esta página. · Primer (audita los 19 plugins)' ),
			array( 'key' => 'avanzado',     'label' => 'Información avanzada',    'ok' => true,
				'desc' => 'Tabla de versiones, resultado del Deployment Validator, trazabilidad Forms→Proyecto y rutas de assets. Para desarrolladores. · Primer' ),
		);
		$_eco_ok = array();
		foreach ( $_eco_health as $_eh ) {
			$_eco_ok[ $_eh['key'] ] = (bool) $_eh['ok'];
		}
		?>
		<style>
			.es-cfg-icon { font-size: 28px; width: 28px; height: 28px; flex-shrink: 0; opacity: .65; }
			@media (max-width: 782px) { .es-cfg-icon { font-size: 24px; width: 24px; height: 24px; } }
			.es-cfg-card[data-es-cfg-card]:hover { filter: brightness(0.98); }
			.es-cfg-card[data-es-cfg-card]:focus-visible { outline: 2px solid #2271b1; outline-offset: 1px; }
			[data-es-cfg-panel].es-cfg-opening { animation: esCfgSlide .18s ease-out; }
			@keyframes esCfgSlide { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: none; } }
		</style>
		<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:18px;">
		<?php foreach ( $_eco_health as $_eh ) :
			$_eh_ok = (bool) $_eh['ok'];
		?>
			<div class="es-cfg-card" data-es-cfg-card="<?php echo esc_attr( $_eh['key'] ); ?>" role="button" tabindex="0" aria-expanded="<?php echo $_eh_ok ? 'false' : 'true'; ?>" aria-controls="es-cfg-panel-<?php echo esc_attr( $_eh['key'] ); ?>" style="display:flex;align-items:center;gap:8px;padding:8px 12px;background:<?php echo $_eh_ok ? '#f0fdf4' : '#fffbeb'; ?>;border:1px solid <?php echo $_eh_ok ? '#bbf7d0' : '#fde68a'; ?>;border-radius:6px;cursor:pointer;user-select:none;">
				<span class="dashicons <?php echo esc_attr( estandar_diag_icon( (string) $_eh['key'] ) ); ?> es-cfg-icon" aria-hidden="true" style="color:<?php echo $_eh_ok ? '#166534' : '#92400e'; ?>;"></span>
				<div style="flex:1;min-width:0;">
					<span style="font-size:12px;font-weight:600;color:<?php echo $_eh_ok ? '#166534' : '#92400e'; ?>;"><?php echo esc_html( $_eh['label'] ); ?></span>
					<?php if ( ! empty( $_eh['desc'] ) ) : ?>
						<span style="font-size:11px;color:#6b7280;display:block;margin-top:1px;line-height:1.3;"><?php echo esc_html( (string) $_eh['desc'] ); ?></span>
					<?php endif; ?>
					<span style="font-size:11px;color:<?php echo $_eh_ok ? '#15803d' : '#b45309'; ?>;display:block;margin-top:2px;"><?php echo $_eh_ok ? 'Sin novedad · Click para ver' : 'Requiere atención · Click para ver'; ?></span>
				</div>
				<span class="es-cfg-chev" aria-hidden="true" style="font-size:10px;color:<?php echo $_eh_ok ? '#15803d' : '#b45309'; ?>;"><?php echo $_eh_ok ? '▸' : '▾'; ?></span>
			</div>
		<?php endforeach; ?>
		</div>

		<?php // ── 2. Incidencias del sistema (config/SMTP/deploy) ──────────────── ?>
		<div class="es-diag-section" id="es-cfg-panel-incidencias" data-es-cfg-panel="incidencias" style="margin-bottom:12px;<?php echo $_eco_ok['incidencias'] ? 'display:none;' : ''; ?>">
			<div class="es-diag-section-header">Incidencias del sistema</div>
			<div class="es-diag-section-body" style="padding:12px 16px;">
			<?php if ( empty( $actions ) ) : ?>
				<p style="margin:0;font-size:13px;color:#166534;">Sin incidencias detectadas.</p>
			<?php else :
				$_action_state_labels = [ 'ERROR' => 'Error', 'WARN' => 'Atención', 'INFO' => 'Info' ];
				$_action_state_bg     = [ 'ERROR' => '#fdecec', 'WARN' => '#fff8e1', 'INFO' => '#eff6ff' ];
				$_action_state_border = [ 'ERROR' => '#fecaca', 'WARN' => '#fde68a', 'INFO' => '#bfdbfe' ];
				$_action_state_color  = [ 'ERROR' => '#dc2626', 'WARN' => '#b7791f', 'INFO' => '#1d4ed8' ];
			?>
				<div style="display:flex;flex-direction:column;gap:8px;">
				<?php foreach ( $actions as $action ) :
					$_alevel = (string) ( $action['level'] ?? 'WARN' );
					$_abg    = $_action_state_bg[ $_alevel ]     ?? '#fff8e1';
					$_abord  = $_action_state_border[ $_alevel ] ?? '#fde68a';
					$_acolor = $_action_state_color[ $_alevel ]  ?? '#b7791f';
					$_alabel = $_action_state_labels[ $_alevel ] ?? 'Atención';
				?>
					<div style="display:flex;align-items:center;gap:10px;padding:8px 12px;background:<?php echo esc_attr( $_abg ); ?>;border:1px solid <?php echo esc_attr( $_abord ); ?>;border-radius:5px;font-size:12px;">
						<span style="flex-shrink:0;padding:1px 7px;border-radius:3px;font-size:10px;font-weight:700;background:<?php echo esc_attr( $_acolor ); ?>;color:#fff;letter-spacing:.04em;"><?php echo esc_html( $_alabel ); ?></span>
						<span style="color:#374151;flex:1;"><?php echo esc_html( $action['msg'] ); ?></span>
						<?php if ( ! empty( $action['fix_url'] ) ) : ?>
							<a class="button button-small" href="<?php echo esc_url( $action['fix_url'] ); ?>"><?php echo esc_html( $action['fix_label'] ?? 'Resolver' ); ?></a>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
				</div>
			<?php endif; ?>
			</div>
		</div>

		<?php // ── 2b. INTEGRITY_AUTOFIX_V1 — consola de integridad accionable ──── ?>
		<div id="es-cfg-panel-integridad" data-es-cfg-panel="integridad" style="margin-bottom:12px;<?php echo $_eco_ok['integridad'] ? 'display:none;' : ''; ?>">
		<?php
		if ( function_exists( 'estandar_core_render_integrity_cards' ) ) {
			estandar_core_render_integrity_cards();
		} elseif ( function_exists( 'estandar_core_render_diag_menu_warning_explainer' ) ) {
			estandar_core_render_diag_menu_warning_explainer( false );
		}
		?>
		</div>

		<?php // ── 3. ESTADO OPERACIONAL ────────────────────────────────────────── ?>
		<div class="es-diag-section" id="es-cfg-panel-operacional" data-es-cfg-panel="operacional" style="margin-bottom:12px;<?php echo $_eco_ok['operacional'] ? 'display:none;' : ''; ?>">
			<div class="es-diag-section-header">Estado operacional</div>
			<div class="es-diag-section-body" style="padding:12px 16px;">
				<?php // Wrap to suppress the embedded "Integridad Operacional" dashboard header — status is already shown in the card above. ?>
				<div class="es-registry-health-embed">
				<style>.es-registry-health-embed .es-dashboard-header{display:none!important;}</style>
				<?php estandar_core_render_registry_health_section(); ?>
				</div>
				<?php if ( function_exists( 'estandar_chatbot_get_config_status' ) || function_exists( 'estandar_chatbot_render_config_status_section' ) ) : ?>
				<div style="margin-top:10px;">
					<?php
					if ( function_exists( 'estandar_chatbot_get_config_status' ) ) {
						estandar_core_render_config_status_section();
					} else {
						estandar_chatbot_render_config_status_section();
					}
					?>
				</div>
				<?php endif; ?>
				<?php if ( ! empty( $components ) ) : ?>
				<div style="margin-top:10px;border-top:1px solid #f0f0f1;padding-top:10px;">
					<span style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#646970;display:block;margin-bottom:6px;">Componentes</span>
					<div style="display:flex;flex-wrap:wrap;gap:6px;">
						<?php foreach ( $components as $label => $component_state ) : $cok = 'OK' === $component_state; ?>
						<span style="display:inline-flex;align-items:center;gap:4px;padding:3px 8px;background:<?php echo $cok ? '#f0fdf4' : '#fffbeb'; ?>;border:1px solid <?php echo $cok ? '#bbf7d0' : '#fde68a'; ?>;border-radius:4px;font-size:11px;color:<?php echo $cok ? '#166534' : '#92400e'; ?>;">
							<span style="width:6px;height:6px;border-radius:50%;background:<?php echo $cok ? '#22c55e' : '#f59e0b'; ?>;flex-shrink:0;"></span>
							<?php echo esc_html( $label ); ?>
						</span>
						<?php endforeach; ?>
					</div>
				</div>
				<?php endif; ?>
			</div>
		</div>

		<?php // ── 4. ECOSISTEMA (compat + updates merged) ─────────────────────── ?>
		<div class="es-diag-section" id="es-cfg-panel-plugins" data-es-cfg-panel="plugins" style="margin-bottom:12px;<?php echo $_eco_ok['plugins'] ? 'display:none;' : ''; ?>">
			<div class="es-diag-section-header">
				Ecosistema de plugins
				<span style="<?php echo esc_attr( $badge_style( $version_health['incompatible'] > 0 ? 'ERROR' : 'OK' ) ); ?>"><?php echo $version_health['incompatible'] > 0 ? 'INCOMPATIBLE' : 'COMPATIBLE'; ?></span>
			</div>
			<div class="es-diag-section-body" style="padding:10px 16px;">
				<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;">
					<div>
						<span style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#646970;display:block;margin-bottom:2px;">Plugins</span>
						<span style="font-size:22px;font-weight:700;color:#1d2327;line-height:1;"><?php echo esc_html( (string) absint( $version_health['total'] ) ); ?></span>
						<span style="font-size:11px;color:#646970;display:block;margin-top:2px;">Línea <?php echo esc_html( (string) $version_health['line'] ); ?>.x · <?php echo esc_html( (string) max( 0, absint( $version_health['total'] ) - absint( $version_health['incompatible'] ) ) ); ?> compatibles</span>
					</div>
					<div>
						<span style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#646970;display:block;margin-bottom:2px;">Incompatibles</span>
						<span style="font-size:22px;font-weight:700;color:<?php echo $version_health['incompatible'] > 0 ? '#dc2626' : '#22c55e'; ?>;line-height:1;"><?php echo esc_html( (string) absint( $version_health['incompatible'] ) ); ?></span>
					</div>
					<div>
						<span style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#646970;display:block;margin-bottom:2px;">Actualizaciones</span>
						<span style="font-size:22px;font-weight:700;color:<?php echo $version_health['misaligned'] > 0 ? '#f59e0b' : '#22c55e'; ?>;line-height:1;"><?php echo esc_html( (string) absint( $version_health['misaligned'] ) ); ?></span>
						<span style="font-size:11px;color:#646970;display:block;margin-top:2px;">recomendada(s)</span>
					</div>
				</div>
			</div>
		</div>

		<?php // ── 5. ADVANCED — mismo contenido, ahora dentro del panel de tarjeta ── ?>
		<div class="es-diag-section" id="es-cfg-panel-avanzado" data-es-cfg-panel="avanzado" style="margin-bottom:12px;display:none;">
			<div class="es-diag-section-header">Información avanzada <span style="font-weight:400;color:#646970;font-size:11px;">Runtime · Deployment · Forms · Desarrolladores</span></div>
			<div class="es-diag-section-body" style="padding:12px 16px;">

				<details class="es-version-details">
					<summary style="font-weight:600;">Runtime — Versiones de plugins</summary>
					<table class="widefat striped" style="margin-top:8px;">
						<thead><tr><th>Plugin</th><th>Versión</th><th>Estado</th></tr></thead>
						<tbody>
						<?php foreach ( (array) $version_health['rows'] as $row ) : ?>
							<tr>
								<td><?php echo esc_html( (string) ( $row['name'] ?? '' ) ); ?></td>
								<td><code><?php echo esc_html( (string) ( $row['version'] ?? '' ) ); ?></code></td>
								<td><?php echo ! empty( $row['compatible'] ) ? 'Compatible' : '<strong style="color:#b32d2e;">Actualizar</strong>'; ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</details>

				<details class="es-version-details" style="margin-top:10px;">
					<summary style="font-weight:600;">
						Deployment Validator
						<span style="margin-left:8px;<?php echo esc_attr( $badge_style( 'PASS' === $deployment_status ? 'PASS' : 'ERROR' ) ); ?>"><?php echo esc_html( $deployment_status ); ?></span>
					</summary>
					<p style="font-size:12px;color:#6b7280;margin:6px 0 8px;">Modo: <code><?php echo esc_html( $deployment_mode ); ?></code></p>
					<table class="widefat striped" style="font-size:13px;">
						<thead><tr><th>Capa</th><th>Estado</th><th>Evidencia</th></tr></thead>
						<tbody>
						<?php foreach ( (array) ( $deployment_validator['checks'] ?? [] ) as $check ) :
							$chk_st    = (string) ( $check['status'] ?? ( ! empty( $check['pass'] ) ? 'PASS' : 'FAIL' ) );
							$chk_color = 'PASS' === $chk_st ? '#1e7e34' : ( 'INFO' === $chk_st ? '#6b7280' : ( 'FAIL' === $chk_st ? '#b32d2e' : '#92600a' ) );
							$chk_label = [ 'INFO' => 'OK' ][ $chk_st ] ?? $chk_st;
						?>
							<tr>
								<td><?php echo esc_html( (string) ( $check['label'] ?? '' ) ); ?></td>
								<td><strong style="color:<?php echo esc_attr( $chk_color ); ?>;"><?php echo esc_html( $chk_label ); ?></strong></td>
								<td>
									<span style="font-size:12px;"><?php echo esc_html( (string) ( $check['evidence'] ?? '' ) ); ?></span>
									<?php if ( ! empty( $check['fix_url'] ) && ! in_array( $chk_st, [ 'PASS', 'INFO' ], true ) ) : ?>
										<br><a class="button button-small" href="<?php echo esc_url( (string) $check['fix_url'] ); ?>" style="margin-top:6px;"><?php echo 'FAIL' === $chk_st ? 'Corregir' : 'Ver formularios'; ?></a>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<?php $form_project_trace = (array) ( $deployment_validator['form_project_trace'] ?? [] ); ?>
					<?php if ( ! empty( $form_project_trace ) ) : ?>
						<h3 style="margin:16px 0 8px;font-size:13px;font-weight:600;">Forms — Project Trace</h3>
						<table class="widefat striped" style="font-size:13px;">
							<thead><tr><th>Form ID</th><th>Form Name</th><th>Resolved Project</th><th>Fuente</th></tr></thead>
							<tbody>
							<?php foreach ( $form_project_trace as $trace_row ) :
								$t_source      = (string) ( $trace_row['resolution_source'] ?? 'UNKNOWN' );
								$t_is_tmpl     = $t_source === 'TEMPLATE';
								$t_is_err      = $t_source === 'UNKNOWN';
								$t_is_fallback = $t_source === 'CORE_FALLBACK';
								$row_style     = $t_is_tmpl ? 'opacity:.55;' : '';
								$src_color     = $t_is_err ? '#b32d2e' : ( $t_is_fallback ? '#92600a' : ( $t_is_tmpl ? '#9ca3af' : '#1e7e34' ) );
								$source_labels = [
									'FORM'          => 'Proyecto asignado',
									'URL'           => 'Proyecto dinámico',
									'CORE_FALLBACK' => 'Proyecto por defecto del sitio',
									'TEMPLATE'      => 'Template',
									'UNKNOWN'       => 'Sin resolver',
								];
								$t_label = $source_labels[ $t_source ] ?? $t_source;
							?>
								<tr style="<?php echo esc_attr( $row_style ); ?>">
									<td><?php echo esc_html( (string) ( $trace_row['form_id'] ?? '' ) ); ?></td>
									<td>
										<?php echo esc_html( (string) ( $trace_row['form_name'] ?? '' ) ); ?>
										<?php if ( $t_is_tmpl ) : ?><span style="font-size:11px;color:#9ca3af;margin-left:4px;">(template)</span><?php endif; ?>
									</td>
									<td><code><?php echo esc_html( (string) ( $trace_row['resolved_project'] ?? '' ) ); ?></code></td>
									<td><strong style="color:<?php echo esc_attr( $src_color ); ?>;"><?php echo esc_html( $t_label ); ?></strong></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</details>

				<details class="es-version-details" style="margin-top:10px;" <?php echo $assets_ok ? '' : 'open'; ?>>
					<summary style="font-weight:600;">Desarrolladores — Assets Runtime</summary>
					<div style="margin-top:8px;">
						<p style="font-size:12px;color:#6b7280;margin:0 0 6px;">Assets Runtime: <strong><?php echo $assets_ok ? 'OK' : 'ERROR'; ?></strong></p>
						<?php if ( ! $assets_ok ) : ?>
						<p style="font-size:12px;color:#6b7280;margin:0 0 4px;">Base DIR: <code><?php echo esc_html( ESTANDAR_CORE_MU_DIR ); ?></code></p>
						<p style="font-size:12px;color:#6b7280;margin:0 0 4px;">Base URL: <code><?php echo esc_html( ESTANDAR_CORE_MU_URL ); ?></code></p>
						<ul style="font-size:12px;color:#6b7280;margin:0;padding-left:18px;">
							<li>Esperado CSS: <code><?php echo esc_html( estandar_core_mu_asset_path( 'admin/admin.css' ) ); ?></code></li>
							<li>Esperado JS: <code><?php echo esc_html( estandar_core_mu_asset_path( 'admin/admin.js' ) ); ?></code></li>
						</ul>
						<?php endif; ?>
					</div>
				</details>

			</div>
		</div>

		<?php // ── Toggle cards ↔ panels (mismo script que Configuración compartida) ─ ?>
		<script>
		(function(){
			var cards = [].slice.call(document.querySelectorAll("[data-es-cfg-card]"));
			if (!cards.length) { return; }
			function toggle(card) {
				var key   = card.getAttribute("data-es-cfg-card");
				var panel = document.querySelector('[data-es-cfg-panel="' + key + '"]');
				if (!panel) { return; }
				var isOpen = panel.style.display !== "none";
				if (isOpen) {
					panel.style.display = "none";
					panel.classList.remove("es-cfg-opening");
				} else {
					panel.style.display = "";
					panel.classList.add("es-cfg-opening");
					panel.scrollIntoView({ behavior: "smooth", block: "nearest" });
				}
				card.setAttribute("aria-expanded", isOpen ? "false" : "true");
				var chev = card.querySelector(".es-cfg-chev");
				if (chev) { chev.textContent = isOpen ? "▸" : "▾"; }
			}
			cards.forEach(function(card){
				card.addEventListener("click", function(){ toggle(card); });
				card.addEventListener("keydown", function(e){
					if (e.key === "Enter" || e.key === " " || e.key === "Spacebar") {
						e.preventDefault();
						toggle(card);
					}
				});
			});
		})();
		</script>

		<?php endif; // end tabs ?>

		<?php
		estandar_core_render_admin_shell(
			[
				'title'      => 'Estandar Primer · Estado',
				'subtitle'   => 'Centro de control operacional.',
				'width'      => 'fluid',
				'header_slot' => static function () use ( $current_tab ): void {
					estandar_core_render_subtabs( 'dashboard_tabs', $current_tab );
				},
				'content'    => (string) ob_get_clean(),
			]
		);
	}
}


add_action( 'admin_menu', 'estandar_core_register_diagnostics_menu', 2 );

/**
 * Garantiza que el menú padre exista incluso si ningún plugin llamó helpers todavía.
 */
add_action( 'admin_menu', 'estandar_admin_menu_register_parent', 1 );

/**
 * Elimina el submenú duplicado que WordPress auto-genera al primer add_submenu_page.
 * Debe ejecutarse en prioridad 9999, después de que todos los submenús estén registrados.
 * El slug ESTANDAR_CORE_MENU_SLUG sigue existiendo como ancla estructural.
 */
if ( ! function_exists( 'estandar_core_remove_duplicate_root_submenu' ) ) {
	function estandar_core_remove_duplicate_root_submenu(): void {
		remove_submenu_page( ESTANDAR_CORE_MENU_SLUG, ESTANDAR_CORE_MENU_SLUG );
	}
}
add_action( 'admin_menu', 'estandar_core_remove_duplicate_root_submenu', 9999 );

/**
 * ======================================================
 * ESTANDAR ADMIN FRAMEWORK 1.0
 * Componentes de UI compartidos para todos los plugins del ecosistema.
 * ======================================================
 */

/**
 * KPI Cards — fila de métricas destacadas.
 *
 * Uso:
 *   estandar_render_kpi_cards([
 *     [ 'label' => 'Nuevos',    'value' => 12,    'status' => 'neutral' ],
 *     [ 'label' => 'Reuniones', 'value' => 5,     'status' => 'info'    ],
 *     [ 'label' => 'Ventas',    'value' => 2,     'status' => 'success' ],
 *     [ 'label' => 'Sin asignar','value'=> 8,     'status' => 'warning' ],
 *     [ 'label' => 'Cobertura', 'value' => '72%', 'status' => 'danger', 'detail' => '72 / 100' ],
 *   ]);
 *
 * status: 'neutral' | 'success' | 'warning' | 'danger' | 'info'
 *
 * @param array<int,array{label:string,value:string|int,status?:string,detail?:string}> $kpis
 */
if ( ! function_exists( 'estandar_render_kpi_cards' ) ) {
	function estandar_render_kpi_cards( array $kpis ): void {
		if ( empty( $kpis ) ) {
			return;
		}

		echo '<div class="estandar-kpi-row">';
		foreach ( $kpis as $kpi ) {
			$label  = esc_html( (string) ( $kpi['label']  ?? '' ) );
			$value  = esc_html( (string) ( $kpi['value']  ?? '—' ) );
			$status = sanitize_key( (string) ( $kpi['status'] ?? 'neutral' ) );
			$detail = isset( $kpi['detail'] ) ? esc_html( (string) $kpi['detail'] ) : '';

			echo '<div class="estandar-kpi estandar-kpi--' . esc_attr( $status ) . '">';
			echo '<div class="estandar-kpi__value">' . $value . '</div>';
			echo '<div class="estandar-kpi__label">' . $label . '</div>';
			if ( $detail !== '' ) {
				echo '<div class="estandar-kpi__detail">' . $detail . '</div>';
			}
			echo '</div>';
		}
		echo '</div>';
	}
}

/**
 * Data Table — tabla de datos normalizada.
 *
 * Uso:
 *   estandar_render_data_table(
 *     [ 'Fuente', 'Leads', 'Ventas' ],
 *     [
 *       [ 'Google Ads', 120, 8 ],
 *       [ 'SEO',         45, 3 ],
 *     ],
 *     [ 'align' => [ 'left', 'right', 'right' ] ]
 *   );
 *
 * @param string[]               $headers
 * @param array<int,array>       $rows     Cada fila: array de valores escalares.
 * @param array{align?:string[]} $options  align: 'left'|'right'|'center' por columna.
 */
if ( ! function_exists( 'estandar_render_data_table' ) ) {
	function estandar_render_data_table( array $headers, array $rows, array $options = [] ): void {
		$align = isset( $options['align'] ) && is_array( $options['align'] ) ? $options['align'] : [];

		echo '<div class="estandar-data-table-wrap">';
		echo '<table class="estandar-data-table">';

		echo '<thead><tr>';
		foreach ( $headers as $i => $h ) {
			$a = $align[ $i ] ?? 'left';
			echo '<th style="text-align:' . esc_attr( $a ) . '">' . esc_html( (string) $h ) . '</th>';
		}
		echo '</tr></thead>';

		echo '<tbody>';
		if ( empty( $rows ) ) {
			echo '<tr><td colspan="' . count( $headers ) . '" class="estandar-data-table__empty">';
			esc_html_e( 'Sin datos para el período seleccionado.', 'estandar-core-mu' );
			echo '</td></tr>';
		} else {
			foreach ( $rows as $row ) {
				echo '<tr>';
				foreach ( (array) $row as $i => $cell ) {
					$a = $align[ $i ] ?? 'left';
					echo '<td style="text-align:' . esc_attr( $a ) . '">';
					// Celda tipo 'bar': array con 'type'=>'bar', 'value'=>int, 'max'=>int.
					if ( is_array( $cell ) && ( $cell['type'] ?? '' ) === 'bar' ) {
						$val = (int) ( $cell['value'] ?? 0 );
						$max = (int) ( $cell['max']   ?? 100 );
						$pct = $max > 0 ? round( $val / $max * 100 ) : 0;
						echo '<div class="estandar-bar" role="presentation">'
							. '<span style="width:' . esc_attr( (string) $pct ) . '%"></span>'
							. '</div>';
					// Cells may be pre-escaped HTML (e.g. <strong>, <code>).
					} elseif ( is_string( $cell ) && preg_match( '/<[a-z]/', $cell ) ) {
						echo wp_kses( $cell, [ 'strong' => [], 'em' => [], 'code' => [],
							'span' => [ 'style' => [], 'class' => [] ], 'a' => [ 'href' => [] ] ] );
					} else {
						echo esc_html( (string) $cell );
					}
					echo '</td>';
				}
				echo '</tr>';
			}
		}
		echo '</tbody>';
		echo '</table>';
		echo '</div>';
	}
}

/**
 * Empty State — estado vacío normalizado.
 *
 * Uso:
 *   estandar_render_empty_state([
 *     'message' => 'Sin datos para este período.',
 *     'detail'  => 'Los datos aparecerán cuando haya actividad.',
 *   ]);
 *
 * @param array{message?:string,detail?:string,icon?:string} $args
 */
if ( ! function_exists( 'estandar_render_chart_card' ) ) {
	/**
	 * Renderiza una card de gráfico con canvas o estado vacío.
	 * Transversal: Analytics, Scanner, TRAK, Leads (timeline).
	 *
	 * El canvas se identifica por $canvas_id — el script del plugin
	 * lo busca por ID y lo inicializa. Core solo provee el contenedor.
	 *
	 * @param string $canvas_id  ID del elemento <canvas>.
	 * @param string $title      Título de la card.
	 * @param bool   $empty      Si true, muestra estado vacío en lugar del canvas.
	 * @param array{message?:string} $options  Opciones adicionales.
	 */
	function estandar_render_chart_card( string $canvas_id, string $title, bool $empty = false, array $options = [] ): void {
		echo '<section class="estandar-chart-card">';
		echo '<header class="estandar-chart-card__head"><h2>' . esc_html( $title ) . '</h2></header>';

		if ( $empty ) {
			$message = $options['message'] ?? __( 'Aún no hay suficiente actividad para visualizar tendencia.', 'estandar-core-mu' );
			echo '<div class="estandar-chart-card__empty">';
			echo '<span class="estandar-chart-card__dash" aria-hidden="true">—</span>';
			echo '<p>' . esc_html( $message ) . '</p>';
			echo '</div>';
		} else {
			echo '<div class="estandar-chart-card__canvas-wrap">';
			echo '<canvas id="' . esc_attr( $canvas_id ) . '" class="estandar-chart" height="220" role="img" aria-label="' . esc_attr( $title ) . '"></canvas>';
			echo '</div>';
		}

		echo '</section>';
	}
}

if ( ! function_exists( 'estandar_render_empty_state' ) ) {
	function estandar_render_empty_state( array $args = [] ): void {
		$message = isset( $args['message'] ) ? (string) $args['message']
			: __( 'Sin datos para el período seleccionado.', 'estandar-core-mu' );
		$detail  = isset( $args['detail'] ) ? (string) $args['detail'] : '';
		$icon    = isset( $args['icon'] ) ? sanitize_html_class( (string) $args['icon'] ) : 'dashicons-chart-area';

		echo '<div class="estandar-empty-state">';
		echo '<span class="estandar-empty-state__icon dashicons ' . esc_attr( $icon ) . '"></span>';
		echo '<p class="estandar-empty-state__message">' . esc_html( $message ) . '</p>';
		if ( $detail !== '' ) {
			echo '<p class="estandar-empty-state__detail">' . esc_html( $detail ) . '</p>';
		}
		echo '</div>';
	}
}

/**
 * Filter Bar — barra de filtros normalizada.
 *
 * Uso:
 *   estandar_render_filter_bar([
 *     [ 'name' => 'ea_source',   'label' => 'Fuente',   'options' => $sources,   'current' => $filters['source'] ],
 *     [ 'name' => 'ea_campaign', 'label' => 'Campaña',  'options' => $campaigns, 'current' => $filters['campaign'] ],
 *     [ 'name' => 'ea_project',  'label' => 'Proyecto', 'options' => $projects,  'current' => $filters['project'] ],
 *   ], [ 'action' => admin_url('admin.php?page=ea-funnel') ]);
 *
 * @param array<int,array{name:string,label:string,options:string[],current:string}> $filters
 * @param array{action?:string,method?:string,hidden?:array<string,string>}          $options
 */
if ( ! function_exists( 'estandar_render_filter_bar' ) ) {
	function estandar_render_filter_bar( array $filters, array $options = [] ): void {
		if ( empty( $filters ) ) {
			return;
		}

		$action = esc_url( (string) ( $options['action'] ?? '' ) );
		$method = sanitize_key( (string) ( $options['method'] ?? 'get' ) );
		$hidden = isset( $options['hidden'] ) && is_array( $options['hidden'] ) ? $options['hidden'] : [];

		$has_active = false;
		foreach ( $filters as $f ) {
			if ( (string) ( $f['current'] ?? '' ) !== '' ) {
				$has_active = true;
				break;
			}
		}

		echo '<form class="estandar-filter-bar" method="' . esc_attr( $method ) . '"';
		if ( $action ) {
			echo ' action="' . $action . '"';
		}
		echo '>';

		foreach ( $hidden as $k => $v ) {
			echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
		}

		foreach ( $filters as $filter ) {
			$name    = sanitize_key( (string) ( $filter['name']    ?? '' ) );
			$label   = (string) ( $filter['label']   ?? $name );
			$opts    = is_array( $filter['options'] ?? null ) ? $filter['options'] : [];
			$current = (string) ( $filter['current'] ?? '' );

			echo '<label class="estandar-filter-bar__item">';
			echo '<span class="estandar-filter-bar__label">' . esc_html( $label ) . '</span>';
			echo '<select name="' . esc_attr( $name ) . '" onchange="this.form.submit()">';
			echo '<option value="">' . esc_html__( 'Todos', 'estandar-core-mu' ) . '</option>';
			foreach ( $opts as $opt ) {
				$opt = (string) $opt;
				echo '<option value="' . esc_attr( $opt ) . '"' . selected( $current, $opt, false ) . '>'
					. esc_html( $opt ) . '</option>';
			}
			echo '</select>';
			echo '</label>';
		}

		if ( $has_active ) {
			// Construir URL limpia quitando los parámetros de filtro activos.
			$clean_url = $action ?: ( is_admin() ? admin_url( 'admin.php?' . http_build_query( [ 'page' => sanitize_key( $_GET['page'] ?? '' ) ] ) ) : '' );
			echo '<a class="estandar-filter-bar__clear" href="' . esc_url( $clean_url ) . '">'
				. esc_html__( '✕ Limpiar', 'estandar-core-mu' ) . '</a>';
		}

		echo '</form>';
	}
}

/**
 * Settings Registry — sistema de secciones de configuración unificada.
 *
 * Los plugins registran secciones propias que Core renderiza en la pantalla
 * de Configuración unificada.
 *
 * Registro (en el plugin):
 *   add_filter( 'estandar_settings_sections', function( $sections ) {
 *     $sections['analytics'] = [
 *       'title'    => 'Analytics',
 *       'priority' => 20,
 *       'callback' => [ EA_Settings::class, 'render_admin_section' ],
 *     ];
 *     return $sections;
 *   });
 *
 * Render (en la pantalla de Core Settings):
 *   estandar_render_settings_sections();
 *
 * @return array<string,array{title:string,priority:int,callback:callable}>
 */
if ( ! function_exists( 'estandar_get_settings_sections' ) ) {
	function estandar_get_settings_sections(): array {
		$sections = (array) apply_filters( 'estandar_settings_sections', [] );
		uasort( $sections, static function ( $a, $b ) {
			return ( (int) ( $a['priority'] ?? 10 ) ) - ( (int) ( $b['priority'] ?? 10 ) );
		} );
		return $sections;
	}
}

if ( ! function_exists( 'estandar_render_settings_sections' ) ) {
	function estandar_render_settings_sections(): void {
		$sections = estandar_get_settings_sections();
		if ( empty( $sections ) ) {
			estandar_render_empty_state( [
				'message' => __( 'No hay secciones de configuración registradas.', 'estandar-core-mu' ),
				'icon'    => 'dashicons-admin-settings',
			] );
			return;
		}

		foreach ( $sections as $slug => $section ) {
			$title    = esc_html( (string) ( $section['title'] ?? $slug ) );
			$callback = $section['callback'] ?? null;
			if ( ! is_callable( $callback ) ) {
				continue;
			}

			echo '<section class="estandar-settings-section" id="estandar-settings-' . esc_attr( sanitize_key( (string) $slug ) ) . '">';
			echo '<h2>' . $title . '</h2>';
			call_user_func( $callback );
			echo '</section>';
		}
	}
}

/**
 * ======================================================
 * TELEMETRY MODULE
 * ======================================================
 */

$_estandar_telemetry_file = __DIR__ . '/includes/telemetry.php';
if ( file_exists( $_estandar_telemetry_file ) ) {
	require_once $_estandar_telemetry_file;
}
unset( $_estandar_telemetry_file );

$_estandar_compliance_shadow_copy_file = __DIR__ . '/includes/compliance-shadow-copy.php';
if ( file_exists( $_estandar_compliance_shadow_copy_file ) ) {
	require_once $_estandar_compliance_shadow_copy_file;
}
unset( $_estandar_compliance_shadow_copy_file );

// ESTANDAR_ADMIN_UI_V0 — V0.3 notice denylist (admin-only, keep-by-default, scoped).
if ( is_admin() ) {
	$_estandar_admin_notice_filter_file = __DIR__ . '/includes/admin-notice-filter.php';
	if ( file_exists( $_estandar_admin_notice_filter_file ) ) {
		require_once $_estandar_admin_notice_filter_file;
	}
	unset( $_estandar_admin_notice_filter_file );
}

/**
 * ======================================================
 * CAMPAIGN LABEL RESOLUTION
 * Shared read-only resolver backed by one wp_options store.
 * ======================================================
 */

$_estandar_campaign_resolver_file = __DIR__ . '/includes/class-campaign-label-resolver.php';
if ( file_exists( $_estandar_campaign_resolver_file ) ) {
	require_once $_estandar_campaign_resolver_file;
}
unset( $_estandar_campaign_resolver_file );

// CAPTURE_ATTRIBUTION_CONTEXT_V1 — canonical attribution contract shared by
// every capture source (Forms/Chatbot/WhatsApp). Chatbot emit points already
// call estandar_core_build_attribution_context() behind function_exists().
$_estandar_capture_attribution_file = __DIR__ . '/includes/capture-attribution.php';
if ( file_exists( $_estandar_capture_attribution_file ) ) {
	require_once $_estandar_capture_attribution_file;
}
unset( $_estandar_capture_attribution_file );

// INTEGRATION_REGISTRY_V1 — contrato común de proveedores publicitarios
// (Meta/Google/LinkedIn) + registro central. Los proveedores se registran
// vía hook 'estandar_integrations_register'; el storage queda encapsulado
// en cada proveedor (el resto de la Suite no toca option names).
$_estandar_integration_registry_file = __DIR__ . '/includes/class-integration-registry.php';
if ( file_exists( $_estandar_integration_registry_file ) ) {
	require_once $_estandar_integration_registry_file;
}
$_estandar_google_hub_file = __DIR__ . '/includes/class-google-integration-hub.php';
if ( file_exists( $_estandar_google_hub_file ) ) { require_once $_estandar_google_hub_file; }
if ( class_exists( 'Estandar_Google_Integration_Hub' ) ) { Estandar_Google_Integration_Hub::init(); }
unset( $_estandar_integration_registry_file );

// PROJECT_CALENDAR_V1 — shared project business-time util (read-only resolver).
$_estandar_project_calendar_file = __DIR__ . '/includes/class-project-calendar.php';
if ( file_exists( $_estandar_project_calendar_file ) ) {
	require_once $_estandar_project_calendar_file;
}
unset( $_estandar_project_calendar_file );

// CANONICAL_REDIRECT_V1 — host canonicalization (www → apex) with exact
// path + raw query preservation. Inert on hosts absent from its map.
$_estandar_canonical_redirect_file = __DIR__ . '/includes/class-canonical-redirect.php';
if ( file_exists( $_estandar_canonical_redirect_file ) ) {
	require_once $_estandar_canonical_redirect_file;
	if ( class_exists( 'Estandar_Canonical_Redirect' ) ) {
		Estandar_Canonical_Redirect::boot();
	}
}
unset( $_estandar_canonical_redirect_file );

// OPERATIONAL_HOURS_V1 — site-wide business hours gate.
$_estandar_operational_hours_file = __DIR__ . '/includes/class-operational-hours.php';
if ( file_exists( $_estandar_operational_hours_file ) ) {
	require_once $_estandar_operational_hours_file;
}
unset( $_estandar_operational_hours_file );

// ESTANDAR_UPDATES_V1 — self-hosted plugin updates.
$_estandar_updates_file = __DIR__ . '/includes/class-estandar-updates.php';
if ( file_exists( $_estandar_updates_file ) ) {
	require_once $_estandar_updates_file;
	if ( class_exists( 'Estandar_Updates' ) ) {
		Estandar_Updates::boot();
	}
}
unset( $_estandar_updates_file );

// ESTANDAR_DIAGNOSTICS_V1 (Phase 1) — read-only CLI diagnostics.
// Loaded ONLY under WP-CLI: zero impact on web runtime.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	$_estandar_diagnostics_file = __DIR__ . '/includes/diagnostics/class-estandar-diagnostics.php';
	if ( file_exists( $_estandar_diagnostics_file ) ) {
		require_once $_estandar_diagnostics_file;
		if ( class_exists( 'Estandar_Diagnostics' ) ) {
			Estandar_Diagnostics::boot();
		}
	}
	unset( $_estandar_diagnostics_file );
}

// INTEGRITY_AUTOFIX_V1 — Fix Provider framework (self-healing integrity
// console). Admin-only load path; admin-ajax.php also satisfies is_admin().
if ( is_admin() ) {
	$_estandar_integrity_file = __DIR__ . '/includes/integrity/class-integrity-controller.php';
	if ( file_exists( $_estandar_integrity_file ) ) {
		require_once $_estandar_integrity_file;
		if ( class_exists( 'Estandar_Integrity_Controller' ) ) {
			Estandar_Integrity_Controller::boot();
		}
	}
	unset( $_estandar_integrity_file );
}

// ESTANDAR_PROJECT_PROVISIONING_V1 (Phase 0) — scaffolding, inert.
// No provisioners registered → provision() is a no-op. Additive only.
$_estandar_provisioning_file = __DIR__ . '/includes/provisioning/class-estandar-project-provisioning.php';
if ( file_exists( $_estandar_provisioning_file ) ) {
	require_once $_estandar_provisioning_file;
	if ( class_exists( 'Estandar_Project_Provisioning' ) ) {
		Estandar_Project_Provisioning::boot();
	}
}
unset( $_estandar_provisioning_file );

$_estandar_campaign_discovery_file = __DIR__ . '/includes/class-campaign-discovery.php';
if ( file_exists( $_estandar_campaign_discovery_file ) ) {
	require_once $_estandar_campaign_discovery_file;
	if ( class_exists( 'Estandar_Campaign_Discovery' ) ) {
		Estandar_Campaign_Discovery::init();
	}
}
unset( $_estandar_campaign_discovery_file );

function estandar_core_maybe_install_campaign_discovery(): void {
	if ( class_exists( 'Estandar_Campaign_Discovery' ) ) {
		Estandar_Campaign_Discovery::maybe_install();
	}
}
add_action( 'plugins_loaded', 'estandar_core_maybe_install_campaign_discovery', 5 );

if ( is_admin() ) {
	$_estandar_campaign_admin_file = __DIR__ . '/admin/class-campaign-labels-admin.php';
	if ( file_exists( $_estandar_campaign_admin_file ) ) {
		require_once $_estandar_campaign_admin_file;
		if ( class_exists( 'Estandar_Campaign_Labels_Admin' ) ) {
			Estandar_Campaign_Labels_Admin::init();
		}
	}
	unset( $_estandar_campaign_admin_file );

	$_estandar_comms_admin_file = __DIR__ . '/admin/class-communications-admin.php';
	if ( file_exists( $_estandar_comms_admin_file ) ) {
		require_once $_estandar_comms_admin_file;
		if ( class_exists( 'Estandar_Communications_Admin' ) ) {
			Estandar_Communications_Admin::init();
		}
	}
	unset( $_estandar_comms_admin_file );
}

/**
 * ======================================================
 * PROJECT REGISTRY
 * Entidad central de proyectos: fuente única de verdad para
 * project_id, label, city, streets[], url_patterns[].
 * Consumidores: Forms (streets), Chatbot (url_patterns), Leads, Analytics.
 * ======================================================
 */

$_estandar_registry_file = __DIR__ . '/includes/class-project-registry.php';
if ( file_exists( $_estandar_registry_file ) ) {
	require_once $_estandar_registry_file;
}
unset( $_estandar_registry_file );

// Primer canonical alias for the project registry.
// Estandar_Core_Project_Registry remains the underlying class — existing
// consumer code using that name continues to work unchanged.
if ( class_exists( 'Estandar_Core_Project_Registry' ) && ! class_exists( 'Primer_Project_Registry', false ) ) {
	class_alias( 'Estandar_Core_Project_Registry', 'Primer_Project_Registry' );
}

// Admin CRUD para Project Registry (solo en contexto admin).
if ( is_admin() ) {
	$_estandar_projects_admin = __DIR__ . '/admin/class-project-registry-admin.php';
	if ( file_exists( $_estandar_projects_admin ) ) {
		require_once $_estandar_projects_admin;
		if ( class_exists( 'Estandar_Project_Registry_Admin' ) ) {
			Estandar_Project_Registry_Admin::init();
		}
	}
	unset( $_estandar_projects_admin );

	// Integraciones (subtab de Proyectos) — capa admin genérica sobre el
	// Integration Registry. Requiere el registry cargado (arriba).
	$_estandar_integrations_admin = __DIR__ . '/admin/class-integrations-admin.php';
	if ( file_exists( $_estandar_integrations_admin ) && class_exists( 'Estandar_Integration_Registry' ) ) {
		require_once $_estandar_integrations_admin;
		if ( class_exists( 'Estandar_Integrations_Admin' ) ) {
			Estandar_Integrations_Admin::init();
		}
	}
	unset( $_estandar_integrations_admin );
}

// Instalar / actualizar tabla en plugins_loaded si la clase está disponible.
if ( ! function_exists( 'estandar_core_maybe_install_project_registry' ) ) {
		function estandar_core_maybe_install_project_registry(): void {
			if ( class_exists( 'Estandar_Core_Project_Registry' ) ) {
				Estandar_Core_Project_Registry::maybe_install();
				Estandar_Core_Project_Registry::maybe_backfill_client_ids();
				// Seed proyecto default para instalaciones monoproyecto.
				// No hace nada si ya existen proyectos (multiproyecto) ni después del primer seed.
				Estandar_Core_Project_Registry::maybe_seed_default_project();
		}
	}
}
add_action( 'plugins_loaded', 'estandar_core_maybe_install_project_registry', 5 );

// También en admin_init para detectar upgrades que ocurren sin deactivate/activate.
add_action( 'admin_init', static function (): void {
	if (
		class_exists( 'Estandar_Core_Project_Registry' ) &&
		get_option( Estandar_Core_Project_Registry::VERSION_OPTION ) !== Estandar_Core_Project_Registry::SCHEMA_VERSION
	) {
		Estandar_Core_Project_Registry::install();
	}
}, 2 );

// ---------------------------------------------------------------------------
// Public API — funciones globales para consumidores del registry
// ---------------------------------------------------------------------------

if ( ! function_exists( 'estandar_core_get_projects' ) ) {
	/**
	 * Todos los proyectos activos.
	 *
	 * @return array<int, array{project_id:string, label:string, city:string, streets:string[], url_patterns:string[]}>
	 */
	function estandar_core_get_projects(): array {
		return class_exists( 'Estandar_Core_Project_Registry' )
			? Estandar_Core_Project_Registry::get_all()
			: [];
	}
}

if ( ! function_exists( 'estandar_core_get_project' ) ) {
	/**
	 * Un proyecto por su slug.
	 *
	 * @param string $project_id Slug del proyecto (ej. 'mi-proyecto').
	 * @return array|null
	 */
	function estandar_core_get_project( string $project_id ): ?array {
		return class_exists( 'Estandar_Core_Project_Registry' )
			? Estandar_Core_Project_Registry::get( $project_id )
			: null;
	}
}

if ( ! function_exists( 'resolve_project_display_name' ) ) {
	function resolve_project_display_name( $slug, $fallback_area = '' ): string {
		$slug          = trim( sanitize_title( remove_accents( (string) $slug ) ), "- \t\n\r\0\x0B" );
		$fallback_area = sanitize_text_field( (string) $fallback_area );
		static $names = null;

		if ( $names === null ) {
			$names = [];

			// Primary SSOT: Core Project Registry.
			if ( class_exists( 'Estandar_Core_Project_Registry' ) ) {
				foreach ( Estandar_Core_Project_Registry::get_all() as $project ) {
					$id   = trim( sanitize_title( remove_accents( (string) ( $project['project_id'] ?? '' ) ) ), "- \t\n\r\0\x0B" );
					$name = sanitize_text_field( (string) ( $project['label'] ?? '' ) );
					if ( $id !== '' && $name !== '' ) {
						$names[ $id ] = $name;
					}
				}
			}

			// Fallback: chatbot-derived registry for slugs not in Core Registry.
			foreach ( (array) apply_filters( 'estandar_chatbot_project_registry', [] ) as $project ) {
				if ( ! is_array( $project ) ) {
					continue;
				}
				$registered_slug = trim( sanitize_title( remove_accents( (string) ( $project['project_slug'] ?? $project['slug'] ?? '' ) ) ), "- \t\n\r\0\x0B" );
				$name = sanitize_text_field( (string) ( $project['name'] ?? $project['label'] ?? $project['area'] ?? '' ) );
				if ( $registered_slug !== '' && $name !== '' && ! isset( $names[ $registered_slug ] ) ) {
					$names[ $registered_slug ] = $name;
				}
			}
		}

		if ( $slug !== '' && isset( $names[ $slug ] ) ) {
			return $names[ $slug ];
		}

		return $fallback_area !== '' ? $fallback_area : $slug;
	}
}

if ( ! function_exists( 'estandar_core_get_project_streets' ) ) {
	/**
	 * Calles de un proyecto — array de strings para un <select>.
	 *
	 * @param string $project_id
	 * @return string[]
	 */
	function estandar_core_get_project_streets( string $project_id ): array {
		return class_exists( 'Estandar_Core_Project_Registry' )
			? Estandar_Core_Project_Registry::get_streets( $project_id )
			: [];
	}
}

if ( ! function_exists( 'estandar_core_get_project_url_patterns' ) ) {
	/**
	 * URL patterns de un proyecto — para el Chatbot.
	 *
	 * @param string $project_id
	 * @return string[]
	 */
	function estandar_core_get_project_url_patterns( string $project_id ): array {
		return class_exists( 'Estandar_Core_Project_Registry' )
			? Estandar_Core_Project_Registry::get_url_patterns( $project_id )
			: [];
	}
}

if ( ! function_exists( 'estandar_core_get_projects_for_select' ) ) {
	/**
	 * Proyectos formateados para <select>: [['value'=>slug, 'label'=>nombre], ...]
	 *
	 * @return array<int, array{value:string, label:string}>
	 */
	function estandar_core_get_projects_for_select(): array {
		return class_exists( 'Estandar_Core_Project_Registry' )
			? Estandar_Core_Project_Registry::get_for_select()
			: [];
	}
}

// ==========================================================================
// ADMIN BAR — Nodo Estandar en la barra superior de WordPress
// ==========================================================================
//
// Arquitectura: dinámica — Core como única fuente de verdad.
//
// Tres fuentes de ítems (en orden de precedencia):
//   1. Registry explícito — estandar_core_register_admin_bar_item().
//      Auto-poblado por estandar_register_submenu() (todos los plugins conformes).
//      Funciona en admin + frontend.
//   2. $submenu auto-discovery — captura plugins legados que usen add_submenu_page()
//      directamente sin pasar por estandar_register_submenu().
//      Solo activo en wp-admin (donde $submenu está poblado).
//
// Plugins que usen estandar_register_submenu() no requieren ningún cambio.
// Plugins legados (como estandar-leads) obtienen cobertura en admin automáticamente;
// para frontend deben llamar explícitamente a estandar_core_register_admin_bar_item().
// ==========================================================================

if ( ! function_exists( 'estandar_core_admin_bar_registry' ) ) {
	/**
	 * Static in-memory registry of Admin Bar items.
	 * Populated by estandar_register_submenu() (auto) and estandar_core_register_admin_bar_item() (explicit).
	 *
	 * @return array<string, array{slug:string, title:string, url:string, capability:string, order:int}>
	 */
	function &estandar_core_admin_bar_registry(): array {
		static $items = [];
		return $items;
	}
}

if ( ! function_exists( 'estandar_core_register_admin_bar_item' ) ) {
	/**
	 * Register an item to appear under the Estandar Admin Bar node.
	 * Called automatically by estandar_register_submenu().
	 * Plugins using add_submenu_page() directly should call this from their init hook
	 * to get frontend admin bar support.
	 *
	 * @param array{slug:string, title:string, url:string, capability?:string, order?:int} $args
	 */
	function estandar_core_register_admin_bar_item( array $args ): void {
		$slug       = sanitize_key( (string) ( $args['slug'] ?? '' ) );
		$title      = sanitize_text_field( (string) ( $args['title'] ?? '' ) );
		$url        = esc_url_raw( (string) ( $args['url'] ?? '' ) );
		$capability = (string) ( $args['capability'] ?? 'manage_options' );
		$order      = (int) ( $args['order'] ?? 50 );

		if ( '' === $slug || '' === $title || '' === $url ) {
			return;
		}

		$registry = &estandar_core_admin_bar_registry();
		if ( isset( $registry[ $slug ] ) ) {
			return; // First registration wins — no overrides.
		}
		$registry[ $slug ] = compact( 'slug', 'title', 'url', 'capability', 'order' );
	}
}

if ( ! function_exists( 'estandar_core_build_admin_bar' ) ) {
	/**
	 * Build the Estandar node in the WordPress admin bar.
	 * Fires on both wp-admin and frontend for logged-in users.
	 *
	 * Frontend gap fix (v10.0.5): compliant plugins call estandar_register_submenu()
	 * inside admin_menu hooks, which don't fire on frontend. We persist a raw-item
	 * snapshot to wp_options on every admin page load. Frontend reads from that cache.
	 * Cache is rebuilt fresh on each admin visit, so deactivated plugins fall out
	 * automatically after the next admin page load.
	 *
	 * @param WP_Admin_Bar $bar
	 */
	function estandar_core_build_admin_bar( WP_Admin_Bar $bar ): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$raw = []; // Unfiltered — capability checked below.

		// Source 1: explicit in-memory registry.
		// Populated by estandar_core_register_admin_bar_item() — fires on all requests
		// for plugins that register from init/plugins_loaded hooks (e.g., Leads).
		foreach ( estandar_core_admin_bar_registry() as $slug => $item ) {
			$raw[ $slug ] = $item;
		}

		// Source 2: $submenu auto-discovery — admin only.
		// Catches any plugin that used add_submenu_page(ESTANDAR_CORE_MENU_SLUG, ...) directly.
		if ( is_admin() && defined( 'ESTANDAR_CORE_MENU_SLUG' ) ) {
			global $submenu;
			foreach ( (array) ( $submenu[ ESTANDAR_CORE_MENU_SLUG ] ?? [] ) as $entry ) {
				$e_slug  = (string) ( $entry[2] ?? '' );
				$e_cap   = (string) ( $entry[1] ?? 'manage_options' );
				$e_title = (string) ( $entry[3] ?? $entry[0] ?? $e_slug );
				if ( '' === $e_slug || $e_slug === ESTANDAR_CORE_MENU_SLUG ) {
					continue;
				}
				if ( isset( $raw[ $e_slug ] ) ) {
					continue; // Explicit registry takes precedence.
				}
				$e_url = ( strpos( $e_slug, 'http' ) === 0 || strpos( $e_slug, '/' ) === 0 )
					? $e_slug
					: admin_url( 'admin.php?page=' . rawurlencode( $e_slug ) );
				$raw[ $e_slug ] = [
					'slug'       => $e_slug,
					'title'      => $e_title,
					'url'        => $e_url,
					'capability' => $e_cap,
					'order'      => 50,
				];
			}
		}

		// Source 3: options cache — frontend only.
		// Built on admin page loads (see below). Fills in plugins that only registered
		// inside admin_menu hooks and therefore aren't in the in-memory registry here.
		if ( ! is_admin() ) {
			$cached = get_option( 'estandar_core_admin_bar_items', [] );
			if ( is_array( $cached ) ) {
				foreach ( $cached as $slug => $item ) {
					if ( ! isset( $raw[ $slug ] ) ) {
						$raw[ $slug ] = $item;
					}
				}
			}
		}

		// Persist raw snapshot on admin — rebuilt every admin page load so deactivated
		// plugins fall out. autoload=false: only read on frontend requests.
		if ( is_admin() && ! empty( $raw ) ) {
			update_option( 'estandar_core_admin_bar_items', $raw, false );
		}

		// Canonical order map — centrally managed, no plugin changes needed.
		// Plugins without an entry here sort at their registered order (default: 50),
		// then alphabetically by title.
		$order_map = [
			'estandar-leads'          => 10,
			'ea-funnel'               => 20, // Analytics primary slug.
			'estandar-chatbot'        => 30,
			'estandar-forms'          => 40,
			'estandar-kickoff'        => 50,
			'estandar-wp-exporter'    => 70,
			'estandar-cpt-manager'    => 80,
			'estandar-duplicate-post' => 90,
		];
		foreach ( $raw as $slug => $item ) {
			if ( isset( $order_map[ $slug ] ) ) {
				$raw[ $slug ]['order'] = $order_map[ $slug ];
			}
		}

		// Capability filter.
		$items = [];
		foreach ( $raw as $slug => $item ) {
			if ( current_user_can( (string) ( $item['capability'] ?? 'manage_options' ) ) ) {
				$items[ $slug ] = $item;
			}
		}

		if ( empty( $items ) ) {
			return;
		}

		// Sort: by order ASC, then title ASC.
		uasort( $items, static function ( array $a, array $b ): int {
			$diff = ( (int) ( $a['order'] ?? 50 ) ) <=> ( (int) ( $b['order'] ?? 50 ) );
			return $diff !== 0 ? $diff : strcmp( (string) $a['title'], (string) $b['title'] );
		} );

		// Root node.
		$hub_href = defined( 'ESTANDAR_CORE_MENU_SLUG' )
			? admin_url( 'admin.php?page=' . ESTANDAR_CORE_MENU_SLUG )
			: admin_url();

		$bar->add_node( [
			'id'    => 'estandar-toolbar',
			'title' => '<span class="ab-icon" style="display:inline-flex;align-items:center;justify-content:center;height:32px;margin-right:4px;font-size:17px;line-height:1;vertical-align:top;" aria-hidden="true">🚀</span>'
			         . '<span class="ab-label">Estandar</span>',
			'href'  => $hub_href,
			'meta'  => [ 'class' => 'estandar-admin-bar-root' ],
		] );

			// First child: Dashboard — always links to the Estandar hub (order 0, hardcoded).
			$bar->add_node( [
				'id'     => 'estandar-bar-panel',
				'parent' => 'estandar-toolbar',
				'title'  => 'Dashboard',
				'href'   => $hub_href,
			] );

		// Remaining children — one per registered + authorized plugin.
		foreach ( $items as $slug => $item ) {
			$bar->add_node( [
				'id'     => 'estandar-bar-' . sanitize_key( $slug ),
				'parent' => 'estandar-toolbar',
				'title'  => esc_html( (string) ( $item['title'] ?? $slug ) ),
				'href'   => esc_url( (string) ( $item['url'] ?? '' ) ),
			] );
		}
	}
}

add_action( 'admin_bar_menu', 'estandar_core_build_admin_bar', 100 );
