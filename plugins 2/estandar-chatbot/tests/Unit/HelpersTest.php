<?php
/**
 * Tests unitarios para helpers del plugin.
 */

use PHPUnit\Framework\TestCase;

class HelpersTest extends TestCase {

	public function test_default_system_prompt_includes_language() {
		$prompt = estandar_chatbot_default_system_prompt_lines( 'es' );
		$this->assertStringContainsString( 'es', $prompt );
		$this->assertStringContainsString( 'asistente', strtolower( $prompt ) );
	}

	public function test_default_system_prompt_fallback_to_es_when_empty() {
		$prompt = estandar_chatbot_default_system_prompt_lines( '' );
		$this->assertStringContainsString( 'es', $prompt );
	}

	public function test_sanitize_system_prompt_option_trims() {
		$out = estandar_chatbot_sanitize_system_prompt_option( "  \n  texto  \n  " );
		$this->assertSame( 'texto', $out );
	}

	public function test_sanitize_system_prompt_option_normalizes_line_endings() {
		$out = estandar_chatbot_sanitize_system_prompt_option( "a\r\nb\rc" );
		$this->assertStringContainsString( "\n", $out );
		$this->assertStringNotContainsString( "\r", $out );
	}

	public function test_sanitize_system_prompt_option_caps_length() {
		$long = str_repeat( 'x', 20000 );
		$out  = estandar_chatbot_sanitize_system_prompt_option( $long );
		$this->assertLessThanOrEqual( 16000, strlen( $out ) );
	}

	public function test_build_system_prompt_uses_custom_when_set() {
		$original = get_option( 'chatbot_system_prompt', '' );
		update_option( 'chatbot_system_prompt', 'Soy un bot de pruebas.', false );

		$prompt = estandar_chatbot_build_system_prompt( 'es', '' );
		$this->assertStringContainsString( 'pruebas', $prompt );

		update_option( 'chatbot_system_prompt', $original, false );
	}

	public function test_build_system_prompt_appends_context() {
		$prompt = estandar_chatbot_build_system_prompt( 'es', 'Contexto de negocio aquí.' );
		$this->assertStringContainsString( 'BASE DE CONOCIMIENTO', $prompt );
		$this->assertStringContainsString( 'Contexto de negocio aquí', $prompt );
	}

	public function test_build_system_prompt_includes_system_base_and_profile() {
		$prompt = estandar_chatbot_build_system_prompt( 'es', '' );
		$this->assertStringContainsString( 'SYSTEM BASE', $prompt );
		$this->assertStringContainsString( 'CONTEXTO GLOBAL', $prompt );
		// PROMPT_ARCHITECTURE_V1: el perfil se inyecta como bloque propio.
		$this->assertStringContainsString( 'PERFIL DEL NEGOCIO', $prompt );
	}

	public function test_get_openai_params_returns_expected_keys() {
		$params = estandar_chatbot_get_openai_params();
		$this->assertArrayHasKey( 'model', $params );
		$this->assertArrayHasKey( 'temperature', $params );
		$this->assertArrayHasKey( 'max_tokens', $params );
		$this->assertGreaterThanOrEqual( 0, $params['temperature'] );
		$this->assertLessThanOrEqual( 2, $params['temperature'] );
		$this->assertGreaterThanOrEqual( 100, $params['max_tokens'] );
		$this->assertLessThanOrEqual( 8000, $params['max_tokens'] );
	}

	public function test_sanitize_source_page_url_empty() {
		$this->assertSame( '', estandar_chatbot_sanitize_source_page_url( '' ) );
		$this->assertSame( '', estandar_chatbot_sanitize_source_page_url( '   ' ) );
	}

	public function test_sanitize_source_page_url_rejects_foreign_host() {
		$this->assertSame( '', estandar_chatbot_sanitize_source_page_url( 'https://example.com/phishing' ) );
	}

	public function test_sanitize_source_page_url_accepts_same_site() {
		$url = home_url( '/producto-demo/?utm=test' );
		$this->assertSame( $url, estandar_chatbot_sanitize_source_page_url( $url ) );
	}

	public function test_allowed_channels_includes_telegram_slot() {
		if ( ! function_exists( 'estandar_chatbot_get_allowed_channels' ) ) {
			$this->markTestSkipped( 'channels-registry no cargado' );
		}
		$ch = estandar_chatbot_get_allowed_channels();
		$this->assertContains( 'web', $ch );
		$this->assertContains( 'whatsapp', $ch );
		$this->assertContains( 'telegram', $ch );
	}

	public function test_normalize_channel_accepts_registered_slugs() {
		if ( ! function_exists( 'estandar_chatbot_normalize_channel' ) ) {
			$this->markTestSkipped( 'conversations-handler no cargado' );
		}
		$this->assertSame( 'telegram', estandar_chatbot_normalize_channel( 'telegram' ) );
		$this->assertSame( 'web', estandar_chatbot_normalize_channel( 'unknown_xyz' ) );
	}

	public function test_guess_potential_faq_heuristic() {
		if ( ! function_exists( 'estandar_chatbot_guess_potential_faq' ) ) {
			$this->markTestSkipped( 'logs-handler no cargado' );
		}
		$this->assertFalse( estandar_chatbot_guess_potential_faq( 'ok' ) );
		$this->assertTrue( estandar_chatbot_guess_potential_faq( '¿Cuál es el plazo de entrega?' ) );
	}
}
