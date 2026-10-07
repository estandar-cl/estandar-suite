<?php
/**
 * Colectores E1 del Site Audit. Read-only, sin APIs externas.
 * Cada colector implementa Estandar_KickOff_Site_Audit_Collector
 * y retorna un Estandar_KickOff_Site_Audit_Result.
 * Regla canon: sin evidencia → reportar ausencia, nunca inferir.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Estandar_KickOff_Site_Audit_Core_Collector implements Estandar_KickOff_Site_Audit_Collector {

	public function domain() {
		return 'core';
	}

	public function collect() {
		global $wp_version;
		$data = [
			'wp_version'   => $wp_version,
			'site_url'     => site_url(),
			'home_url'     => home_url(),
			'is_multisite' => is_multisite(),
			'is_ssl'       => is_ssl(),
			'blog_public'  => (int) get_option( 'blog_public' ),
			'timezone'     => wp_timezone_string(),
			'locale'       => get_locale(),
			'wp_debug'     => defined( 'WP_DEBUG' ) && WP_DEBUG,
		];
		$result = new Estandar_KickOff_Site_Audit_Result( $data );
		if ( 0 === $data['blog_public'] ) {
			$result->add_risk( 'core_noindex', 'P0', 'get_option(blog_public)=0', 'El sitio pide a los buscadores no indexarlo.' );
		}
		if ( ! $data['is_ssl'] ) {
			$result->add_risk( 'core_no_ssl', 'P1', 'is_ssl()=false', 'Admin servido sin SSL.' );
		}
		if ( $data['wp_debug'] ) {
			$result->add_risk( 'core_wp_debug', 'P1', 'WP_DEBUG=true', 'WP_DEBUG activo (¿entorno de producción?).' );
		}
		return $result;
	}
}

class Estandar_KickOff_Site_Audit_Php_Server_Collector implements Estandar_KickOff_Site_Audit_Collector {

	public function domain() {
		return 'php_server';
	}

	public function collect() {
		$data = [
			'php_version'         => PHP_VERSION,
			'server_software'     => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '',
			'memory_limit'        => ini_get( 'memory_limit' ),
			'max_execution_time'  => ini_get( 'max_execution_time' ),
			'upload_max_filesize' => ini_get( 'upload_max_filesize' ),
			'post_max_size'       => ini_get( 'post_max_size' ),
			'extensions'          => [
				'curl'     => extension_loaded( 'curl' ),
				'gd'       => extension_loaded( 'gd' ),
				'imagick'  => extension_loaded( 'imagick' ),
				'mbstring' => extension_loaded( 'mbstring' ),
				'zip'      => extension_loaded( 'zip' ),
			],
		];
		$result = new Estandar_KickOff_Site_Audit_Result( $data );
		if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
			$result->add_risk( 'php_eol', 'P1', 'PHP_VERSION=' . PHP_VERSION, 'Versión PHP antigua (< 8.1).' );
		}
		if ( ! $data['extensions']['curl'] ) {
			$result->add_risk( 'php_no_curl', 'P1', 'extension_loaded(curl)=false', 'Sin cURL: webhooks y APIs remotas fallarán.' );
		}
		return $result;
	}
}

class Estandar_KickOff_Site_Audit_Theme_Collector implements Estandar_KickOff_Site_Audit_Collector {

	public function domain() {
		return 'theme';
	}

	public function collect() {
		$theme = wp_get_theme();
		$data  = [
			'name'        => $theme->get( 'Name' ),
			'version'     => $theme->get( 'Version' ),
			'template'    => $theme->get_template(),
			'stylesheet'  => $theme->get_stylesheet(),
			'is_child'    => $theme->parent() !== false,
			'parent_name' => $theme->parent() ? $theme->parent()->get( 'Name' ) : null,
		];
		$result = new Estandar_KickOff_Site_Audit_Result( $data );
		if ( ! $data['is_child'] ) {
			$result->add_risk( 'theme_no_child', 'P3', 'wp_get_theme()->parent()=false', 'Tema activo sin child theme.' );
		}
		return $result;
	}
}

class Estandar_KickOff_Site_Audit_Plugins_Collector implements Estandar_KickOff_Site_Audit_Collector {

	public function domain() {
		return 'plugins';
	}

	public function collect() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all    = get_plugins();
		$active = (array) get_option( 'active_plugins', [] );
		$list   = [];
		foreach ( $all as $file => $meta ) {
			$list[] = [
				'file'    => $file,
				'name'    => $meta['Name'],
				'version' => $meta['Version'],
				'active'  => in_array( $file, $active, true ),
			];
		}
		$inactive = array_filter( $list, function ( $p ) { return ! $p['active']; } );
		$data = [
			'total'    => count( $list ),
			'active'   => count( $list ) - count( $inactive ),
			'inactive' => count( $inactive ),
			'list'     => $list,
		];
		$result = new Estandar_KickOff_Site_Audit_Result( $data );
		if ( count( $inactive ) > 5 ) {
			$result->add_risk( 'plugins_many_inactive', 'P2', 'inactive=' . count( $inactive ), 'Más de 5 plugins inactivos instalados.' );
		}
		return $result;
	}
}

class Estandar_KickOff_Site_Audit_Estandar_Plugins_Collector implements Estandar_KickOff_Site_Audit_Collector {

	public function domain() {
		return 'estandar_plugins';
	}

	public function collect() {
		// Detección por constantes/clases conocidas (canon CORE_OPTIONALITY: nunca asumir presencia).
		$known = [
			'estandar-primer'   => defined( 'PRIMER_VERSION' ) ? constant( 'PRIMER_VERSION' ) : null,
			'estandar-kickoff'  => defined( 'ESTANDAR_KICKOFF_VERSION' ) ? constant( 'ESTANDAR_KICKOFF_VERSION' ) : null,
			'estandar-leads'    => defined( 'ESTANDAR_LEADS_VERSION' ) ? constant( 'ESTANDAR_LEADS_VERSION' ) : null,
			'estandar-chatbot'  => class_exists( 'Estandar_Chatbot' ) || defined( 'ESTANDAR_CHATBOT_VERSION' ) ? ( defined( 'ESTANDAR_CHATBOT_VERSION' ) ? constant( 'ESTANDAR_CHATBOT_VERSION' ) : 'detected' ) : null,
			'estandar-forms'    => defined( 'ESTANDAR_FORMS_VERSION' ) ? constant( 'ESTANDAR_FORMS_VERSION' ) : ( class_exists( 'Estandar_Forms' ) ? 'detected' : null ),
			'estandar-analytics'=> defined( 'ESTANDAR_ANALYTICS_VERSION' ) ? constant( 'ESTANDAR_ANALYTICS_VERSION' ) : ( function_exists( 'estandar_analytics_get_attribution_by_session' ) ? 'detected' : null ),
		];
		$detected = array_filter( $known, function ( $v ) { return null !== $v; } );
		$data = [
			'detected'     => $detected,
			'not_detected' => array_values( array_diff( array_keys( $known ), array_keys( $detected ) ) ),
		];
		$result = new Estandar_KickOff_Site_Audit_Result( $data );
		if ( ! isset( $detected['estandar-primer'] ) ) {
			$result->add_risk( 'estandar_no_core', 'P3', 'PRIMER_VERSION undefined', 'Estandar Primer no detectado (opcional por CORE_OPTIONALITY; informativo).' );
		}
		return $result;
	}
}

/**
 * E2 — Tracking. Orden de detección: (1) options/config conocida (snippets),
 * (2) HTML renderizado del home (loopback wp_remote_get, sin APIs externas).
 * Read-only. Riesgos solo con evidencia.
 */
class Estandar_KickOff_Site_Audit_Tracking_Collector implements Estandar_KickOff_Site_Audit_Collector {

	public function domain() {
		return 'tracking';
	}

	public function collect() {
		$trackers = [
			'gtm'        => [ 'label' => 'GTM',        'patterns' => [ '/GTM-[A-Z0-9]{4,}/' ] ],
			'ga4'        => [ 'label' => 'GA4',        'patterns' => [ '/\bG-[A-Z0-9]{6,}\b/' ] ],
			'google_ads' => [ 'label' => 'Google Ads', 'patterns' => [ '/\bAW-[0-9]{6,}\b/' ] ],
			'meta_pixel' => [ 'label' => 'Meta Pixel', 'patterns' => [ '/fbq\(\s*[\'"]init[\'"]\s*,\s*[\'"]([0-9]{6,})[\'"]/', '/connect\.facebook\.net\/[a-zA-Z_]+\/fbevents\.js/' ] ],
		];

		$found = [];
		foreach ( array_keys( $trackers ) as $key ) {
			$found[ $key ] = [ 'detected' => false, 'ids' => [], 'sources' => [] ];
		}

		// 1) Options/configuración conocida: snippets de KickOff (head/body_open/footer).
		$snippets     = get_option( 'estandar_kickoff_snippets', [] );
		$snippets_txt = is_array( $snippets ) ? implode( "\n", array_map( 'strval', $snippets ) ) : '';
		if ( '' !== $snippets_txt ) {
			$this->scan( $snippets_txt, 'snippets_option', $trackers, $found );
		}

		// 2) HTML renderizado del home (request loopback al propio sitio; no es API externa).
		$home_fetched = false;
		$home_error   = '';
		$response     = wp_remote_get( home_url( '/' ), [ 'timeout' => 10, 'redirection' => 3 ] );
		if ( is_wp_error( $response ) ) {
			$home_error = $response->get_error_message();
		} else {
			$code = (int) wp_remote_retrieve_response_code( $response );
			$html = (string) wp_remote_retrieve_body( $response );
			if ( $code >= 200 && $code < 300 && '' !== $html ) {
				$home_fetched = true;
				$this->scan( $html, 'home_html', $trackers, $found );
			} else {
				$home_error = 'HTTP ' . $code;
			}
		}

		// 3) Contenedor GTM: si el home carga GTM, GA4/Ads/Pixel pueden vivir solo dentro
		// del contenedor (invisibles en el HTML). Se descarga el JS público del contenedor
		// y se escanea con los mismos patrones (fuente 'gtm_container').
		$gtm_fetch_errors = [];
		foreach ( array_slice( $found['gtm']['ids'], 0, 2 ) as $gtm_id ) {
			$container = wp_remote_get( 'https://www.googletagmanager.com/gtm.js?id=' . rawurlencode( $gtm_id ), [ 'timeout' => 10 ] );
			$body      = is_wp_error( $container ) ? '' : (string) wp_remote_retrieve_body( $container );
			if ( '' === $body ) {
				$gtm_fetch_errors[ $gtm_id ] = is_wp_error( $container ) ? $container->get_error_message() : ( 'HTTP ' . wp_remote_retrieve_response_code( $container ) );
				continue;
			}
			// Custom HTML dentro del contenedor viene JSON-escapado; escanear ambas formas.
			$this->scan( $body . "\n" . stripcslashes( $body ), 'gtm_container:' . $gtm_id, $trackers, $found );
		}

		$data = [
			'home_html_fetched' => $home_fetched,
			'home_fetch_error'  => $home_error,
			'gtm_fetch_errors'  => $gtm_fetch_errors,
			'trackers'          => $found,
		];

		$result = new Estandar_KickOff_Site_Audit_Result( $data );

		foreach ( $gtm_fetch_errors as $gtm_id => $err ) {
			$result->add_risk( 'tracking_gtm_container_unreachable', 'P2', 'gtm.js(' . $gtm_id . ')=' . $err, 'GTM detectado pero no se pudo leer el contenedor; GA4/Ads/Pixel dentro de GTM quedan NO DETERMINABLES.' );
		}

		if ( ! $home_fetched ) {
			$result->add_risk( 'tracking_home_unreachable', 'P2', 'wp_remote_get(home)=' . $home_error, 'No se pudo leer el HTML del home; detección de tracking limitada a configuración.' );
		}

		// Configurado en snippets pero ausente del HTML renderizado (solo con home leído = evidencia).
		if ( $home_fetched ) {
			foreach ( $found as $key => $info ) {
				if ( in_array( 'snippets_option', $info['sources'], true ) && ! in_array( 'home_html', $info['sources'], true ) ) {
					$result->add_risk( 'tracking_' . $key . '_not_rendered', 'P2', 'snippets_option=match, home_html=no match', $trackers[ $key ]['label'] . ' configurado en snippets pero no aparece en el HTML del home.' );
				}
			}
		}

		return $result;
	}

	private function scan( $content, $source, array $trackers, array &$found ) {
		foreach ( $trackers as $key => $def ) {
			foreach ( $def['patterns'] as $pattern ) {
				if ( preg_match_all( $pattern, $content, $matches ) ) {
					$found[ $key ]['detected'] = true;
					if ( ! in_array( $source, $found[ $key ]['sources'], true ) ) {
						$found[ $key ]['sources'][] = $source;
					}
					$ids = ! empty( $matches[1] ) ? $matches[1] : $matches[0];
					foreach ( $ids as $id ) {
						if ( preg_match( '/^(GTM-|G-|AW-|[0-9]+$)/', $id ) && ! in_array( $id, $found[ $key ]['ids'], true ) ) {
							$found[ $key ]['ids'][] = $id;
						}
					}
				}
			}
		}
	}
}

/**
 * E2 — Forms. Audita formularios Estandar y chatbot Estandar.
 * Solo se auditan componentes detectados; riesgos solo con evidencia.
 */
class Estandar_KickOff_Site_Audit_Forms_Collector implements Estandar_KickOff_Site_Audit_Collector {

	const HIDDEN_FIELDS = [ 'gclid', 'gbraid', 'wbraid', 'fbclid', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'lead_uuid', 'estandar_form_nonce', 'estandar_recaptcha_token' ];

	public function domain() {
		return 'forms';
	}

	public function collect() {
		$data   = [ 'estandar_forms' => $this->audit_forms(), 'estandar_chatbot' => $this->audit_chatbot() ];
		$result = new Estandar_KickOff_Site_Audit_Result( $data );

		$forms = $data['estandar_forms'];
		if ( $forms['detected'] ) {
			if ( false === $forms['endpoint_registered'] ) {
				$result->add_risk( 'forms_endpoint_missing', 'P1', 'has_action(admin_post_nopriv_estandar_form_submit)=false', 'Plugin Forms detectado pero el endpoint de submit no está registrado.' );
			}
			if ( is_array( $forms['hidden_fields'] ) ) {
				$missing = array_keys( array_filter( $forms['hidden_fields'], function ( $v ) { return ! $v; } ) );
				if ( in_array( 'estandar_form_nonce', $missing, true ) ) {
					$result->add_risk( 'forms_nonce_missing', 'P1', 'form-render.php sin estandar_form_nonce', 'El render del formulario no incluye campo nonce.' );
				}
				$attribution_missing = array_values( array_intersect( $missing, [ 'gclid', 'gbraid', 'wbraid', 'fbclid', 'utm_source', 'utm_medium', 'utm_campaign' ] ) );
				if ( $attribution_missing ) {
					$result->add_risk( 'forms_attribution_fields_missing', 'P2', 'form-render.php sin: ' . implode( ',', $attribution_missing ), 'Faltan hidden fields de atribución en el render del formulario.' );
				}
			}
			if ( false === $forms['recaptcha_configured'] ) {
				$result->add_risk( 'forms_no_captcha', 'P3', 'ESTANDAR_FORMS_RECAPTCHA_SITE_KEY undefined/empty', 'reCAPTCHA no configurado en Forms (informativo).' );
			}
		}

		$chatbot = $data['estandar_chatbot'];
		if ( $chatbot['detected'] ) {
			if ( false === $chatbot['endpoint_registered'] ) {
				$result->add_risk( 'chatbot_endpoint_missing', 'P1', 'has_action(wp_ajax_nopriv_estandar_chatbot_save_lead)=false', 'Chatbot detectado pero el endpoint de captura de lead no está registrado.' );
			}
			if ( false === $chatbot['nonce_endpoint_registered'] ) {
				$result->add_risk( 'chatbot_nonce_endpoint_missing', 'P2', 'has_action(wp_ajax_nopriv_estandar_chatbot_runtime_nonce)=false', 'Endpoint de runtime nonce del chatbot no registrado.' );
			}
		}

		return $result;
	}

	private function audit_forms() {
		$detected = defined( 'ESTANDAR_FORMS_VERSION' ) || class_exists( 'Estandar_Forms' );
		$info     = [
			'detected'            => $detected,
			'version'             => defined( 'ESTANDAR_FORMS_VERSION' ) ? constant( 'ESTANDAR_FORMS_VERSION' ) : null,
			'endpoint'            => null,
			'endpoint_registered' => null,
			'hidden_fields'       => null,
			'recaptcha_configured'=> null,
		];
		if ( ! $detected ) {
			return $info;
		}
		$info['endpoint']            = 'admin-post.php?action=estandar_form_submit';
		$info['endpoint_registered'] = false !== has_action( 'admin_post_nopriv_estandar_form_submit' );
		$info['recaptcha_configured'] = defined( 'ESTANDAR_FORMS_RECAPTCHA_SITE_KEY' ) && defined( 'ESTANDAR_FORMS_RECAPTCHA_SECRET_KEY' )
			&& ESTANDAR_FORMS_RECAPTCHA_SITE_KEY && ESTANDAR_FORMS_RECAPTCHA_SECRET_KEY;

		// Evidencia estática: hidden fields declarados en el render del plugin (read-only).
		$render = WP_PLUGIN_DIR . '/estandar-forms/public/form-render.php';
		if ( is_readable( $render ) ) {
			$src    = (string) file_get_contents( $render );
			$fields = [];
			foreach ( self::HIDDEN_FIELDS as $field ) {
				$fields[ $field ] = false !== strpos( $src, "'" . $field . "'" ) || false !== strpos( $src, '"' . $field . '"' ) || false !== strpos( $src, 'name="' . $field . '"' );
			}
			$info['hidden_fields'] = $fields;
		}
		return $info;
	}

	private function audit_chatbot() {
		$detected = defined( 'ESTANDAR_CHATBOT_VERSION' ) || class_exists( 'Estandar_Chatbot' );
		$info     = [
			'detected'                  => $detected,
			'version'                   => defined( 'ESTANDAR_CHATBOT_VERSION' ) ? constant( 'ESTANDAR_CHATBOT_VERSION' ) : null,
			'endpoint'                  => null,
			'endpoint_registered'       => null,
			'nonce_endpoint_registered' => null,
		];
		if ( ! $detected ) {
			return $info;
		}
		$info['endpoint']                  = 'admin-ajax.php?action=estandar_chatbot_save_lead';
		$info['endpoint_registered']       = false !== has_action( 'wp_ajax_nopriv_estandar_chatbot_save_lead' );
		$info['nonce_endpoint_registered'] = false !== has_action( 'wp_ajax_nopriv_estandar_chatbot_runtime_nonce' );
		return $info;
	}
}
