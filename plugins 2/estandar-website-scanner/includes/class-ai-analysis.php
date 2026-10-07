<?php
/**
 * AI analysis layer.
 *
 * @package EstandarWebsiteScanner
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates strategic interpretation from scan results.
 */
class EWS_AI_Analysis {

	/**
	 * Preferred models in order.
	 *
	 * @var array<int, string>
	 */
	private const MODELS = array(
		'gpt-5',
		'gpt-4.1-mini',
		'gpt-4o-mini',
	);

	/**
	 * Competitor detection prompt.
	 *
	 * @var string
	 */
	private const COMPETITOR_SYSTEM_PROMPT = 'You are a senior SEO and competitive benchmarking consultant.';

	/**
	 * Design and marketing system prompt.
	 *
	 * @var string
	 */
	private const DESIGN_MARKETING_SYSTEM_PROMPT = 'You are a senior design, brand, and marketing consultant.';

	/**
	 * Analyze scan payload.
	 *
	 * @param array<string, mixed> $report Report payload.
	 * @return array<string, mixed>
	 */
	public static function analyze_scan_results( array $report ): array {
		$url = esc_url_raw( (string) ( $report['url'] ?? '' ) );
		$report_language = self::normalize_language( (string) ( $report['report_language'] ?? 'en' ) );
		if ( '' === $url ) {
			return array(
				'available' => false,
				'message'   => 'AI analysis unavailable.',
			);
		}

		$prompt_hash = md5(
			(string) get_option( 'ews_prompt_executive', '' ) .
			(string) get_option( 'ews_prompt_technical', '' ) .
			(string) get_option( 'ews_prompt_seo', '' ) .
			(string) get_option( 'ews_prompt_conversion', '' )
		);
		$cache_key = 'ews_ai_analysis_' . md5( $url . '|' . $report_language . '|' . $prompt_hash );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$api_key = trim( (string) get_option( 'estandar_openai_api_key', '' ) );
		if ( '' === $api_key ) {
			return array(
				'available' => false,
				'message'   => 'AI analysis unavailable: missing OpenAI API key in Settings.',
			);
		}

		$scan_json = self::build_ai_payload( $report );
		$prompt    = self::build_prompt( $scan_json, $report_language );

		$ai_result = self::request_ai_content( $api_key, $prompt );
		if ( empty( $ai_result['content'] ) ) {
			$error_message = (string) ( $ai_result['message'] ?? 'AI analysis unavailable.' );
			self::log_ai_issue( $error_message );
			return array(
				'available' => false,
				'message'   => $error_message,
				'error_code'=> (string) ( $ai_result['error_code'] ?? '' ),
			);
		}

		$result = array(
			'available' => true,
			'content'   => trim( (string) $ai_result['content'] ),
			'model'     => (string) ( $ai_result['model'] ?? '' ),
			'language'  => $report_language,
		);

		set_transient( $cache_key, $result, DAY_IN_SECONDS );
		return $result;
	}

	/**
	 * Generate a Design & Marketing narrative (draft) that requires approval.
	 *
	 * The output is intentionally separated from the technical diagnosis and
	 * focuses on brand/message, UX/visual direction, content marketing, and conversion.
	 *
	 * @param array<string, mixed> $report Report payload.
	 * @return array<string, mixed>
	 */
	public static function analyze_design_marketing_analysis( array $report ): array {
		$url = esc_url_raw( (string) ( $report['url'] ?? '' ) );
		$report_language = self::normalize_language( (string) ( $report['report_language'] ?? 'en' ) );
		if ( '' === $url ) {
			return array(
				'available' => false,
				'draft'     => '',
				'message'   => 'Design & marketing analysis unavailable.',
			);
		}

		$prompt_hash = md5(
			(string) get_option( 'ews_prompt_design_marketing', '' ) .
			(string) $report_language
		);
		$cache_key = 'ews_ai_design_marketing_' . md5( $url . '|' . $report_language . '|' . $prompt_hash );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$api_key = trim( (string) get_option( 'estandar_openai_api_key', '' ) );
		if ( '' === $api_key ) {
			return array(
				'available' => false,
				'draft'     => '',
				'message'   => 'Design & marketing analysis unavailable: missing OpenAI API key in Settings.',
			);
		}

		$scan_json = self::build_ai_payload( $report );
		$prompt    = self::build_design_marketing_prompt( $scan_json, $report_language );
		$ai_result = self::request_ai_content_with_system( $api_key, $prompt, self::DESIGN_MARKETING_SYSTEM_PROMPT );

		if ( empty( $ai_result['content'] ) ) {
			$error_message = (string) ( $ai_result['message'] ?? 'Design & marketing analysis unavailable.' );
			self::log_ai_issue( $error_message );
			return array(
				'available' => false,
				'draft'     => '',
				'message'   => $error_message,
				'error_code'=> (string) ( $ai_result['error_code'] ?? '' ),
			);
		}

		$result = array(
			'available' => true,
			'draft'     => trim( (string) $ai_result['content'] ),
			'model'     => (string) ( $ai_result['model'] ?? '' ),
			'language'  => $report_language,
		);
		set_transient( $cache_key, $result, DAY_IN_SECONDS );
		return $result;
	}

	/**
	 * Detect up to 3 competitor domains using AI and initial scan signals.
	 *
	 * @param string            $target_url Scanned website URL.
	 * @param array<string,mixed> $context Initial analysis signals.
	 * @param array<int,string> $exclude_domains Hostnames to exclude.
	 * @param string            $lang Language code (en|es).
	 * @param array<int,string> $fallback_competitors Manual competitors fallback.
	 * @param float|null        $deadline_ts Optional deadline timestamp (microtime true).
	 * @return array<int,string> Competitor domains (no scheme preferred).
	 */
	public static function detect_competitors_via_ai(
		string $target_url,
		array $context,
		array $exclude_domains,
		string $lang,
		array $fallback_competitors = array(),
		?float $deadline_ts = null
	): array {
		if ( null !== $deadline_ts && microtime( true ) > $deadline_ts ) {
			return array_values( array_slice( $fallback_competitors, 0, 3 ) );
		}

		$target_url = esc_url_raw( $target_url );
		$target_host = (string) wp_parse_url( $target_url, PHP_URL_HOST );
		$target_host = strtolower( trim( $target_host ) );

		$exclude_hosts = array_values( array_unique( array_filter( array_map( 'strtolower', $exclude_domains ) ) ) );
		if ( '' !== $target_host ) {
			$exclude_hosts[] = $target_host;
		}

		$cache_seed = array(
			'target_url' => $target_url,
			'lang'       => $lang,
			'context'    => array(
				'purpose'          => $context['purpose'] ?? $context['site_intent'] ?? null,
				'keywords'         => $context['keyword_signals'] ?? null,
				'conversion'       => $context['conversion'] ?? null,
				'tech'             => $context['tech'] ?? null,
				'opportunities'   => $context['seo_opportunities'] ?? null,
			),
		);

		$cache_key = 'ews_ai_competitors_' . md5( wp_json_encode( $cache_seed ) );
		$cached = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return array_values( array_slice( $cached, 0, 3 ) );
		}

		$api_key = trim( (string) get_option( 'estandar_openai_api_key', '' ) );
		if ( '' === $api_key ) {
			return array_values( array_slice( $fallback_competitors, 0, 3 ) );
		}

		$language_name = ( 'es' === $lang ) ? 'Spanish' : 'English';
		$user_context = array(
			'target_url' => $target_url,
			'analysis'   => $context,
			'exclude'    => $exclude_hosts,
			'output'     => array(
				'type' => 'json_array_of_domains',
				'max'  => 3,
			),
		);

		$prompt = <<<PROMPT
Return ONLY a valid JSON array of up to 3 competitor domains relevant to the target website.
Rules:
- Must be competitor domains, not the target domain.
- Output MUST be JSON only. No markdown, no explanation.
- Each item must be either a domain (example: "example.com") or a URL (you will be expected to return domains).
- Choose the 3 strongest competitors by intent and offering.

Language: {$language_name}

Input JSON:
PROMPT;

		$prompt .= "\n" . wp_json_encode( $user_context );

		if ( null !== $deadline_ts && microtime( true ) > $deadline_ts ) {
			return array_values( array_slice( $fallback_competitors, 0, 3 ) );
		}

		$result = self::request_competitor_content( $api_key, $prompt );
		$content = isset( $result['content'] ) ? (string) $result['content'] : '';
		$decoded = self::parse_competitor_json_array( $content );

		if ( empty( $decoded ) ) {
			return array_values( array_slice( $fallback_competitors, 0, 3 ) );
		}

		// Normalize, keep domains only, exclude original hosts.
		$normalized = array();
		foreach ( $decoded as $item ) {
			if ( ! is_string( $item ) ) {
				continue;
			}
			$val = trim( $item );
			if ( '' === $val ) {
				continue;
			}

			// Allow plain domains or full URLs.
			$as_url = $val;
			if ( 0 !== stripos( $val, 'http://' ) && 0 !== stripos( $val, 'https://' ) ) {
				$as_url = 'https://' . $val;
			}
			$host = (string) wp_parse_url( esc_url_raw( $as_url ), PHP_URL_HOST );
			$host = strtolower( trim( $host ) );
			if ( '' === $host ) {
				continue;
			}

			if ( in_array( $host, $exclude_hosts, true ) ) {
				continue;
			}

			// Fail-closed: only keep safe public hosts.
			$safe_url = 'https://' . $host;
			if ( ! EWS_Url_Safety::is_safe_url( $safe_url ) ) {
				continue;
			}

			$normalized[ $host ] = true;
			if ( count( $normalized ) >= 3 ) {
				break;
			}
		}

		$final = array_slice( array_keys( $normalized ), 0, 3 );
		set_transient( $cache_key, $final, DAY_IN_SECONDS );
		return $final;
	}

	/**
	 * Request competitor detection content (raw).
	 *
	 * @param string $api_key
	 * @param string $prompt
	 * @return array<string,string>
	 */
	private static function request_competitor_content( string $api_key, string $prompt ): array {
		// Reuse the same API infrastructure and model list.
		$last_error_message = 'Competitor detection unavailable.';
		$last_error_code    = '';

		foreach ( self::MODELS as $model ) {
			if ( ! empty( $last_error_code ) && 'model_not_found' === $last_error_code ) {
				continue;
			}

			$response = wp_remote_post(
				self::ENDPOINT,
				array(
					'timeout' => 60,
					'headers' => array(
						'Authorization' => 'Bearer ' . $api_key,
						'Content-Type'  => 'application/json',
					),
					'body'    => wp_json_encode(
						array(
							'model'    => $model,
							'messages' => array(
								array(
									'role'    => 'system',
									'content' => self::COMPETITOR_SYSTEM_PROMPT,
								),
								array(
									'role'    => 'user',
									'content' => $prompt,
								),
							),
						)
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				$last_error_message = 'OpenAI request failed: ' . $response->get_error_message();
				$last_error_code    = 'wp_error';
				continue;
			}

			$status = (int) wp_remote_retrieve_response_code( $response );
			$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			if ( 200 !== $status || ! is_array( $data ) ) {
				$error_code = (string) ( $data['error']['code'] ?? '' );
				$error_type = (string) ( $data['error']['type'] ?? '' );
				$error_msg  = (string) ( $data['error']['message'] ?? '' );
				$last_error_code = '' !== $error_code ? $error_code : (string) $status;
				$last_error_message = 'OpenAI API error: ' . ( '' !== $error_type ? '[' . $error_type . '] ' : '' ) . ( '' !== $error_msg ? $error_msg : 'Unknown error' );

				if ( 'model_not_found' === $error_code || 'invalid_model' === $error_code ) {
					continue;
				}

				return array(
					'content'    => '',
					'message'    => $last_error_message,
					'error_code' => $last_error_code,
				);
			}

			$content = (string) ( $data['choices'][0]['message']['content'] ?? '' );
			if ( '' === trim( $content ) ) {
				$content = (string) ( $data['choices'][0]['text'] ?? '' );
			}

			if ( '' !== trim( $content ) ) {
				return array(
					'content'    => trim( $content ),
					'message'    => '',
					'error_code' => '',
				);
			}

			$last_error_message = 'OpenAI returned empty competitor content.';
			$last_error_code    = 'empty_content';
		}

		return array(
			'content'    => '',
			'message'    => $last_error_message,
			'error_code' => $last_error_code,
		);
	}

	/**
	 * Parse JSON array of competitors from AI output.
	 *
	 * @param string $content
	 * @return array<int,mixed>
	 */
	private static function parse_competitor_json_array( string $content ): array {
		$content = trim( $content );
		if ( '' === $content ) {
			return array();
		}

		// Strip common code fences.
		$content = preg_replace( '/^```(?:json)?\\s*/i', '', (string) $content );
		$content = preg_replace( '/```\\s*$/i', '', (string) $content );

		// Extract the first JSON array found.
		if ( ! str_contains( $content, '[' ) ) {
			return array();
		}

		$first_bracket = strpos( $content, '[' );
		$last_bracket = strrpos( $content, ']' );
		if ( false === $first_bracket || false === $last_bracket || $last_bracket <= $first_bracket ) {
			return array();
		}

		$json_candidate = substr( $content, $first_bracket, $last_bracket - $first_bracket + 1 );
		$data = json_decode( (string) $json_candidate, true );
		if ( ! is_array( $data ) ) {
			return array();
		}

		return $data;
	}

	/**
	 * Build normalized AI payload.
	 *
	 * @param array<string, mixed> $report Report payload.
	 * @return array<string, mixed>
	 */
	private static function build_ai_payload( array $report ): array {
		$components = $report['score']['components'] ?? array();
		$issues     = array();

		$seo_checks = $report['seo']['checks'] ?? array();
		foreach ( $seo_checks as $check ) {
			if ( is_array( $check ) && isset( $check['label'], $check['passed'] ) && ! $check['passed'] ) {
				$issues[] = 'Missing or failing: ' . (string) $check['label'];
			}
		}

		$a11y_checks = $report['accessibility']['checks'] ?? array();
		foreach ( $a11y_checks as $check ) {
			if ( is_array( $check ) && isset( $check['label'], $check['passed'] ) && ! $check['passed'] ) {
				$issues[] = 'Accessibility: ' . (string) $check['label'];
			}
		}

		$broken_count = (int) ( $report['broken_links']['broken_count'] ?? 0 );
		if ( $broken_count > 0 ) {
			$issues[] = 'Broken links detected: ' . $broken_count;
		}

		$pagespeed_errors = $report['diagnostics']['pagespeed_errors'] ?? array();
		foreach ( $pagespeed_errors as $err ) {
			$issues[] = (string) $err;
		}

		return array(
			'url'   => (string) ( $report['url'] ?? '' ),
			'score' => (float) ( $report['score']['health_score'] ?? 0 ),
			'scores' => array(
				'performance'   => $components['performance'] ?? null,
				'seo'           => $components['seo_technical'] ?? null,
				'accessibility' => $components['accessibility'] ?? null,
				'infrastructure'=> $components['infrastructure'] ?? null,
				'architecture'  => $components['architecture'] ?? null,
				'crawler'       => $components['crawler_structure'] ?? null,
				'conversion'    => $components['conversion_signals'] ?? null,
			),
			'issues' => array_values( array_unique( $issues ) ),
			'stack'  => array_values( (array) ( $report['tech']['technologies'] ?? $report['technology']['technologies'] ?? array() ) ),
			'seo_opportunities' => array_values( (array) ( $report['seo_opportunities']['opportunities'] ?? array() ) ),
			'site_intent' => array(
				'detected_intent' => (string) ( $report['site_intent']['detected_intent'] ?? '' ),
				'expected_intent' => (string) ( $report['site_intent']['expected_intent'] ?? '' ),
				'alignment_score' => (float) ( $report['site_intent']['alignment_score'] ?? 0 ),
				'impact'          => (string) ( $report['site_intent']['impact'] ?? '' ),
				'missing_signals' => array_values( (array) ( $report['site_intent']['missing_signals'] ?? array() ) ),
				'recommendation'  => (string) ( $report['site_intent']['strategic_recommendation'] ?? '' ),
			),
		);
	}

	/**
	 * Call OpenAI and extract text content.
	 *
	 * @param string $api_key OpenAI API key.
	 * @param string $prompt Prompt.
	 * @return array<string, string>
	 */
	private static function request_ai_via_gateway( string $prompt, string $system = 'You are a senior SEO, web performance and digital strategy consultant.' ): array {
		if ( ! class_exists( 'Estandar_AI_Gateway' ) ) {
			return array( 'content' => '', 'message' => 'Gateway not available', 'error_code' => 'no_gateway', 'model' => '' );
		}
		$models = self::MODELS;
		foreach ( $models as $model ) {
			if ( ! Estandar_AI_Gateway::supports_model( $model ) ) {
				continue;
			}
			$result = Estandar_AI_Gateway::chat( array(
				'model'    => $model,
				'messages' => array(
					array( 'role' => 'system', 'content' => $system ),
					array( 'role' => 'user',   'content' => $prompt ),
				),
			) );
			if ( ! empty( $result['content'] ) ) {
				return array( 'content' => $result['content'], 'message' => '', 'error_code' => '', 'model' => $model );
			}
		}
		return array( 'content' => '', 'message' => 'AI analysis unavailable.', 'error_code' => '', 'model' => '' );
	}

	private static function request_ai_content( string $api_key, string $prompt ): array {
		$gateway = self::request_ai_via_gateway( $prompt, 'You are a senior SEO, web performance and digital strategy consultant.' );
		if ( ! empty( $gateway['content'] ) ) {
			return $gateway;
		}

		$last_error_message = 'AI analysis unavailable.';
		$last_error_code    = '';

		foreach ( self::MODELS as $model ) {
			$response = wp_remote_post(
				'https://api.openai.com/v1/chat/completions',
				array(
					'timeout' => 60,
					'headers' => array(
						'Authorization' => 'Bearer ' . $api_key,
						'Content-Type'  => 'application/json',
					),
					'body'    => wp_json_encode(
						array(
							'model'    => $model,
							'messages' => array(
								array(
									'role'    => 'system',
									'content' => 'You are a senior SEO, web performance and digital strategy consultant.',
								),
								array(
									'role'    => 'user',
									'content' => $prompt,
								),
							),
						),
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				$last_error_message = 'OpenAI request failed: ' . $response->get_error_message();
				$last_error_code    = 'wp_error';
				continue;
			}

			$status = (int) wp_remote_retrieve_response_code( $response );
			$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			if ( 200 !== $status || ! is_array( $data ) ) {
				$error_code = (string) ( $data['error']['code'] ?? '' );
				$error_type = (string) ( $data['error']['type'] ?? '' );
				$error_msg  = (string) ( $data['error']['message'] ?? '' );
				$last_error_code = '' !== $error_code ? $error_code : (string) $status;

				if ( 'model_not_found' === $error_code || 'invalid_model' === $error_code ) {
					continue;
				}
				if ( '' === $error_msg ) {
					$error_msg = 'HTTP ' . $status . ' from OpenAI API.';
				}
				if ( '' !== $error_type ) {
					$error_msg = '[' . $error_type . '] ' . $error_msg;
				}
				$last_error_message = 'OpenAI API error: ' . $error_msg;
				return array(
					'content'    => '',
					'message'    => $last_error_message,
					'error_code' => $last_error_code,
					'model'      => $model,
				);
			}

			$content = (string) ( $data['choices'][0]['message']['content'] ?? '' );
			if ( '' === trim( $content ) && ! empty( $data['choices'][0]['message']['content'][0]['text'] ) ) {
				$content = (string) $data['choices'][0]['message']['content'][0]['text'];
			}
			if ( '' === trim( $content ) && ! empty( $data['choices'][0]['text'] ) ) {
				$content = (string) $data['choices'][0]['text'];
			}

			if ( '' !== trim( $content ) ) {
				return array(
					'content'    => trim( $content ),
					'message'    => '',
					'error_code' => '',
					'model'      => $model,
				);
			}

			$last_error_message = 'OpenAI API returned empty content.';
			$last_error_code    = 'empty_content';
		}

		return array(
			'content'    => '',
			'message'    => $last_error_message,
			'error_code' => $last_error_code,
			'model'      => '',
		);
	}

	/**
	 * Call OpenAI with a custom system prompt.
	 *
	 * @param string $api_key OpenAI API key.
	 * @param string $prompt Prompt.
	 * @param string $system System message.
	 * @return array<string, string>
	 */
	private static function request_ai_content_with_system( string $api_key, string $prompt, string $system ): array {
		$gateway = self::request_ai_via_gateway( $prompt, $system );
		if ( ! empty( $gateway['content'] ) ) {
			return $gateway;
		}

		$last_error_message = 'AI analysis unavailable.';
		$last_error_code    = '';

		foreach ( self::MODELS as $model ) {
			$response = wp_remote_post(
				'https://api.openai.com/v1/chat/completions',
				array(
					'timeout' => 60,
					'headers' => array(
						'Authorization' => 'Bearer ' . $api_key,
						'Content-Type'  => 'application/json',
					),
					'body'    => wp_json_encode(
						array(
							'model'    => $model,
							'messages' => array(
								array(
									'role'    => 'system',
									'content' => $system,
								),
								array(
									'role'    => 'user',
									'content' => $prompt,
								),
							),
						)
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				$last_error_message = 'OpenAI request failed: ' . $response->get_error_message();
				$last_error_code    = 'wp_error';
				continue;
			}

			$status = (int) wp_remote_retrieve_response_code( $response );
			$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			if ( 200 !== $status || ! is_array( $data ) ) {
				$error_code = (string) ( $data['error']['code'] ?? '' );
				$error_type = (string) ( $data['error']['type'] ?? '' );
				$error_msg  = (string) ( $data['error']['message'] ?? '' );
				$last_error_code = '' !== $error_code ? $error_code : (string) $status;

				if ( 'model_not_found' === $error_code || 'invalid_model' === $error_code ) {
					continue;
				}
				if ( '' === $error_msg ) {
					$error_msg = 'HTTP ' . $status . ' from OpenAI API.';
				}
				if ( '' !== $error_type ) {
					$error_msg = '[' . $error_type . '] ' . $error_msg;
				}
				$last_error_message = 'OpenAI API error: ' . $error_msg;
				return array(
					'content'    => '',
					'message'    => $last_error_message,
					'error_code' => $last_error_code,
					'model'      => $model,
				);
			}

			$content = (string) ( $data['choices'][0]['message']['content'] ?? '' );
			if ( '' === trim( $content ) && ! empty( $data['choices'][0]['message']['content'][0]['text'] ) ) {
				$content = (string) $data['choices'][0]['message']['content'][0]['text'];
			}
			if ( '' === trim( $content ) && ! empty( $data['choices'][0]['text'] ) ) {
				$content = (string) $data['choices'][0]['text'];
			}

			if ( '' !== trim( $content ) ) {
				return array(
					'content'    => trim( $content ),
					'message'    => '',
					'error_code' => '',
					'model'      => $model,
				);
			}

			$last_error_message = 'OpenAI API returned empty content.';
			$last_error_code    = 'empty_content';
		}

		return array(
			'content'    => '',
			'message'    => $last_error_message,
			'error_code' => $last_error_code,
			'model'      => '',
		);
	}

	/**
	 * Log AI issues in scanner logs when logger exists.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private static function log_ai_issue( string $message ): void {
		if ( class_exists( 'EWS_Logger' ) ) {
			EWS_Logger::log( 'AI ERROR: ' . $message );
		}
	}

	/**
	 * Build prompt.
	 *
	 * @param array<string, mixed> $scan_json Structured scan payload.
	 * @param string               $report_language Report language.
	 * @return string
	 */
	private static function build_prompt( array $scan_json, string $report_language ): string {
		$language_name = ( 'es' === $report_language ) ? 'Spanish' : 'English';
		$executive  = self::get_prompt_template(
			'ews_prompt_executive',
			'Executive Summary: Interpret overall technical health, maturity level and most relevant business impact.'
		);
		$technical  = self::get_prompt_template(
			'ews_prompt_technical',
			'Technical Improvements: Prioritize performance, infrastructure, crawlability and accessibility fixes by impact.'
		);
		$seo        = self::get_prompt_template(
			'ews_prompt_seo',
			'SEO Opportunities: Detect strategic SEO opportunities and explain how to improve indexability and relevance.'
		);
		$conversion = self::get_prompt_template(
			'ews_prompt_conversion',
			'Conversion Opportunities: Recommend UX/content/CTA improvements with measurable lead impact.'
		);

		$template = <<<PROMPT
You are a senior SEO, web performance and digital strategy consultant.

Write the entire analysis strictly in {$language_name}. Do not mix languages.

Build a concise professional report with these sections:
1) Executive Summary
2) Site Purpose and Alignment
3) Top Priorities (3)
4) Technical Improvements
5) SEO Improvements
6) Conversion Opportunities

Use these prompt directives:
{$executive}
{$technical}
{$seo}
{$conversion}

Do not repeat raw findings line by line. Interpret and prioritize by impact.
Write in a consulting tone, with concise direct paragraphs. Avoid generic AI phrasing and avoid buzzwords.
Use plain text headings only. Do not use markdown symbols (#, *, bullets with hyphens, or code blocks).
If the detected purpose is lead generation and alignment is low, propose a lead qualification system (smart form or chatbot) when justified by the data.

Audit Data:
PROMPT;

		return $template . "\n" . wp_json_encode( $scan_json );
	}

	/**
	 * Build prompt for Design & Marketing analysis.
	 *
	 * @param array<string, mixed> $scan_json Structured scan payload.
	 * @param string               $report_language Language code.
	 * @return string
	 */
	private static function build_design_marketing_prompt( array $scan_json, string $report_language ): string {
		$language_name = ( 'es' === $report_language ) ? 'Spanish' : 'English';
		$custom         = trim( (string) get_option( 'ews_prompt_design_marketing', '' ) );

		$base = <<<PROMPT
You are a senior design, brand, and marketing consultant.

Write the entire analysis strictly in {$language_name}. Do not mix languages.

This section MUST be separate from the technical diagnosis. Do NOT talk about raw technical scores or performance metrics.
Focus on design, brand communication, UX direction, content marketing, and conversion copy/CTA strategy.

Provide a narrative written in plain text with simple headings (no markdown and no bullet points with hyphens).

Sections to include:
1) Brand & Messaging Direction
2) Visual & UX Recommendations
3) Content Marketing Strategy
4) Conversion Copy & CTA Strategy
5) Roadmap (next steps) and Marketing KPIs

Rules:
- Avoid generic AI phrasing.
- Use the audit data to make specific recommendations.
- When relevant, propose example CTA wording (1-3 options).
PROMPT;

		if ( '' !== $custom ) {
			$base .= "\n\nAdditional instructions:\n" . $custom;
		}

		$base .= "\n\nAudit Data:\n";
		$base .= wp_json_encode( $scan_json );
		return $base;
	}

	/**
	 * Get configurable prompt or default.
	 *
	 * @param string $option_key Option key.
	 * @param string $default Default text.
	 * @return string
	 */
	private static function get_prompt_template( string $option_key, string $default ): string {
		$value = trim( (string) get_option( $option_key, '' ) );
		return '' !== $value ? $value : $default;
	}

	/**
	 * Normalize language.
	 *
	 * @param string $lang Raw lang.
	 * @return string
	 */
	private static function normalize_language( string $lang ): string {
		$lang = strtolower( trim( $lang ) );
		return in_array( $lang, array( 'en', 'es' ), true ) ? $lang : 'en';
	}
}
