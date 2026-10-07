<?php

namespace Estandar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mail — motor único de correo de la Suite.
 *
 * Centraliza: plantillas HTML, variables, texto alternativo, remitente, reply-to,
 * CC/BCC, validación de direcciones, preview, transporte (wp_mail), envío de
 * prueba y log de resultados. Reutilizable por Forms (notificaciones y
 * autorespuesta), Surveys, Leads, Cotizaciones, Flow y futuros correos.
 *
 * No se crean motores separados: notificación y autorespuesta usan este motor.
 */
class Mail
{
	/** Valida una dirección de correo. */
	public static function validate_address( $email ): bool
	{
		if ( ! is_string( $email ) ) {
			return false;
		}
		$email = trim( $email );
		return $email !== '' && is_email( $email );
	}

	/** Normaliza una lista de destinatarios (string separado por comas o array). */
	public static function normalize_addresses( $list ): array
	{
		$out = array();
		$items = is_array( $list ) ? $list : preg_split( '/[\s,;]+/', (string) $list );
		foreach ( (array) $items as $item ) {
			$item = trim( (string) $item );
			if ( $item !== '' && self::validate_address( $item ) && ! in_array( $item, $out, true ) ) {
				$out[] = $item;
			}
		}
		return $out;
	}

	/** Opción de copias de monitoreo (BCC) de todos los correos de la Suite. */
	const MONITOR_BCC_OPTION = 'estandar_suite_core_mail_monitor_bcc';
	/** Switch maestro: '1' activa, '0' desactiva, '' = sin configurar (legacy). */
	const MONITOR_ENABLED_OPTION = 'estandar_suite_core_mail_monitor_enabled';

	/** MAIL_IDENTITY_CANONICAL_V1: color de marca por defecto cuando NO hay
	 *  branding configurado (ni form, ni skin de proyecto, ni Leads→Email).
	 *  Neutro (negro) a propósito: si no se configuró, no se inventa marca. */
	const DEFAULT_BRAND_COLOR = '#000000';

	/**
	 * Copia de monitoreo (BCC): correos que reciben copia oculta de TODOS los
	 * correos de la Suite para monitorear lo que se envía. Se lee de
	 * Core → Configuración → Correo (switch maestro + dirección). Si la opción
	 * aún no existe, se siembra desde el legacy
	 * `estandar_leads_notification_cc_email` (copias que se perdieron). Un
	 * desactivado explícito ('0') siempre devuelve sin copia.
	 */
	public static function monitor_bcc(): array
	{
		if ( (string) get_option( self::MONITOR_ENABLED_OPTION, '' ) === '0' ) {
			return array();
		}
		$opt = (string) get_option( self::MONITOR_BCC_OPTION, '' );
		if ( $opt === '' ) {
			$legacy = (string) get_option( 'estandar_leads_notification_cc_email', '' );
			if ( $legacy !== '' ) {
				return self::normalize_addresses( $legacy );
			}
		}
		return self::normalize_addresses( $opt );
	}

	/** Reemplaza variables {{clave}} en una plantilla HTML o texto. */
	public static function render( string $template, array $vars ): string
	{
		$map = array();
		foreach ( $vars as $k => $v ) {
			$map[ '{{' . $k . '}}' ] = (string) ( is_scalar( $v ) ? $v : '' );
		}
		return strtr( $template, $map );
	}

	/**
	 * Envía un correo con el motor de la Suite, enrutado por TransportRegistry.
	 *
	 * El transporte efectivo se resuelve (acción → default Suite → heredado →
	 * wp_mail) y se registra en el resultado y en DeliveryLog. El resultado
	 * SIEMPRE incluye 'transport'.
	 *
	 * @param array $opts {
	 *   to, subject, html, alt, from_name, from_email, reply_to, cc, bcc, vars
	 * }
	 * @return array { ok, to, subject, error, transport }
	 */
	public static function send( array $opts ): array
	{
		return \Estandar\Core\Communications\TransportRegistry::send( $opts );
	}

	/** TEST_REDIRECT_V1: dirección de redirección en modo pruebas ('' = off). */
	public static function test_notify_redirect(): string
	{
		$addr = get_option( 'estandar_suite_test_notify_email', '' );
		if ( ! is_string( $addr ) || trim( $addr ) === '' && defined( 'ESTANDAR_TEST_NOTIFY_EMAIL' ) ) {
			$addr = defined( 'ESTANDAR_TEST_NOTIFY_EMAIL' ) ? ESTANDAR_TEST_NOTIFY_EMAIL : '';
		}
		return sanitize_email( trim( (string) $addr ) );
	}

	/**
	 * Envío de bajo nivel vía wp_mail (usado por WpMailTransport/SmtpTransport).
	 * No registra en DeliveryLog (lo hace TransportRegistry::send). No resuelve
	 * transporte: siempre wp_mail.
	 */
	public static function send_raw( array $opts ): array
	{
		$to      = self::normalize_addresses( $opts['to'] ?? array() );
		$subject = sanitize_text_field( (string) ( $opts['subject'] ?? '' ) );
		$html    = (string) ( $opts['html'] ?? '' );
		$alt     = (string) ( $opts['alt'] ?? wp_strip_all_tags( $html ) );
		$vars    = is_array( $opts['vars'] ?? null ) ? $opts['vars'] : array();

		// TEST_REDIRECT_V1: modo pruebas → TODOS los correos salientes de la
		// Suite van SOLO a la dirección configurada (option
		// `estandar_suite_test_notify_email` o constante `ESTANDAR_TEST_NOTIFY_EMAIL`).
		// Evita enviar a operadores/clientes reales durante pruebas. Vacío = sin
		// redirect (comportamiento normal).
		$test_to = self::test_notify_redirect();
		if ( $test_to !== '' ) {
			$to      = array( $test_to );
			$opts['cc']  = '';
			$opts['bcc'] = '';
			if ( strpos( $subject, '[TEST]' ) !== 0 ) {
				$subject = '[TEST] ' . $subject;
			}
		}

		if ( ! $to ) {
			return array( 'ok' => false, 'to' => $to, 'subject' => $subject, 'error' => 'Sin destinatarios válidos.' );
		}
		if ( $subject === '' || $html === '' ) {
			return array( 'ok' => false, 'to' => $to, 'subject' => $subject, 'error' => 'Asunto o HTML vacío.' );
		}

		$html = self::render( $html, $vars );
		$alt  = self::render( $alt, $vars );
		$subject = self::render( $subject, $vars );

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		if ( ! empty( $opts['from_name'] ) || ! empty( $opts['from_email'] ) ) {
			$from_name  = sanitize_text_field( (string) ( $opts['from_name'] ?? '' ) );
			$from_email = sanitize_email( (string) ( $opts['from_email'] ?? '' ) );
			if ( $from_email !== '' ) {
				$headers[] = 'From: ' . ( $from_name !== '' ? $from_name . ' <' . $from_email . '>' : $from_email );
			}
		}
		if ( ! empty( $opts['reply_to'] ) ) {
			$reply = self::normalize_addresses( $opts['reply_to'] );
			if ( $reply ) {
				$headers[] = 'Reply-To: ' . implode( ',', $reply );
			}
		}
		foreach ( array( 'cc', 'bcc' ) as $h ) {
			$addrs = self::normalize_addresses( $opts[ $h ] ?? array() );
			// MONITOR_BCC_V1: copia de monitoreo global (oculta) de la Suite.
			if ( $h === 'bcc' ) {
				$addrs = array_merge( $addrs, self::monitor_bcc() );
			}
			if ( $addrs ) {
				$headers[] = ucfirst( $h ) . ': ' . implode( ',', $addrs );
			}
		}
		$attachments = array_values( array_filter( (array) ( $opts['attachments'] ?? array() ) ) );

		try {
			$sent = wp_mail( $to, $subject, $html, $headers, $attachments );
			return array( 'ok' => (bool) $sent, 'to' => $to, 'subject' => $subject, 'error' => $sent ? '' : 'wp_mail devolvió false.' );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'to' => $to, 'subject' => $subject, 'error' => $e->getMessage() );
		}
	}

	/** Envío de prueba seguro (valida la configuración sin efectos externos reales si se desea). */
	public static function test( array $opts ): array
	{
		return self::send( $opts );
	}

	/** Preview (render de variables) sin enviar. */
	public static function preview( array $opts ): array
	{
		$vars = is_array( $opts['vars'] ?? null ) ? $opts['vars'] : array();
		return array(
			'html'    => self::render( (string) ( $opts['html'] ?? '' ), $vars ),
			'alt'     => self::render( (string) ( $opts['alt'] ?? wp_strip_all_tags( (string) ( $opts['html'] ?? '' ) ) ), $vars ),
			'subject' => self::render( (string) ( $opts['subject'] ?? '' ), $vars ),
		);
	}

	/** Registro de éxito/error (via telemetría de Core si existe). */
	public static function log( array $result ): void
	{
		if ( class_exists( 'Estandar\\Core\\Telemetry' ) ) {
			\Estandar\Core\Telemetry::emit(
				$result['ok'] ? 'info' : 'error',
				'SUITE_MAIL',
				$result['ok'] ? 'Email enviado.' : 'Email fallido.',
				array(
					'source_plugin' => 'estandar-core',
					'to'       => implode( ',', (array) ( $result['to'] ?? array() ) ),
					'subject'  => (string) ( $result['subject'] ?? '' ),
					'error'    => (string) ( $result['error'] ?? '' ),
				)
			);
		}
	}

	// ── Identidad compartida de correo (EMAIL_IDENTITY_V1) ───────────────────
	//
	// Fuente única de la cabecera/footer que usan por defecto los correos de la
	// Suite (notificación y autorespuesta de forms, notificación del operador):
	// Capture → Configuración → Leads → Email (`estandar_suite_capture_leads_settings`).
	// Cada correo puede sobre-escribir con sus propios settings (override); un
	// campo vacío en el override = usa el default compartido.

	/**
	 * Skin (branding) de un proyecto desde la opción alias
	 * `estandar_suite_core_project_branding` (Core → Configuración → Proyectos).
	 * Un mismo motor de correo, distintas skins por proyecto.
	 */
	public static function project_skin( string $project_slug ): array
	{
		$all = get_option( 'estandar_suite_core_project_branding', array() );
		$all = is_array( $all ) ? $all : array();
		$s   = is_array( $all[ $project_slug ] ?? null ) ? $all[ $project_slug ] : array();
		return array(
			'brand_color' => (string) ( $s['brand_color'] ?? '' ),
			'logo_url'    => (string) ( $s['logo_url'] ?? '' ),
			'header_text' => (string) ( $s['header_text'] ?? '' ),
			'footer_text' => (string) ( $s['footer_text'] ?? '' ),
		);
	}

	/**
	 * Identidad de correo compartida (cabecera + footer + remitente).
	 *
	 * Resuelve: site_name (bloginfo), brand_color, logo_url, header_text,
	 * footer_text, preheader, sender_name, sender_email.
	 * MAIL_IDENTITY_CANONICAL_V1: la ÚNICA fuente editable es Leads → Email
	 * (`estandar_suite_capture_leads_settings`). Se retiraron la precedencia
	 * legacy (`estandar_leads_email_settings`, sin editor) y el fallback del
	 * Core "Identidad" (`estandar_suite_core_brand_color`/`default_logo`), que
	 * duplicaban y confundían. Precedencia final: overrides del correo → skin
	 * del proyecto → Leads→Email → default (color negro; logo = Custom Logo del
	 * tema).
	 *
	 * @param array  $overrides   Claves a sobre-escribir (no vacías) por correo.
	 * @param string $project_slug Skin del proyecto (PROJECT_BRANDING_V1): si el
	 *                             proyecto tiene branding definido, gana sobre
	 *                             el default del sitio (y antes de $overrides).
	 * @return array
	 */
	public static function identity( array $overrides = array(), string $project_slug = '' ): array
	{
		$capture = get_option( 'estandar_suite_capture_leads_settings', array() );
		$capture = is_array( $capture ) ? $capture : array();
		// FEATURE_GROUP_NESTED_V1: la identidad de correo vive en el grupo
		// `email`; se acepta la raíz plana legacy como fallback (migración en
		// guardado desde el panel/CLI).
		$cap_email = ( isset( $capture['email'] ) && is_array( $capture['email'] ) ) ? $capture['email'] : array();
		$capv      = function ( string $k ) use ( $capture, $cap_email ) {
			return $cap_email[ $k ] ?? ( $capture[ $k ] ?? '' );
		};

		$site_name = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '';

		$brand_color = (string) $capv( 'brand_color' );
		if ( $brand_color === '' || ! preg_match( '/^#[0-9a-fA-F]{3,8}$/', $brand_color ) ) {
			$brand_color = self::DEFAULT_BRAND_COLOR;
		}

		// Logo: Leads→Email → Custom Logo del tema. (Sin fallback de Core.)
		$logo_url = (string) $capv( 'logo_url' );
		if ( $logo_url === '' && function_exists( 'get_theme_mod' ) ) {
			$custom_logo_id = (int) get_theme_mod( 'custom_logo', 0 );
			if ( $custom_logo_id > 0 && function_exists( 'wp_get_attachment_image_url' ) ) {
				$logo_url = (string) wp_get_attachment_image_url( $custom_logo_id, 'medium' );
			}
		}

		$footer_text = (string) $capv( 'footer_text' );
		if ( $footer_text === '' ) {
			$footer_text = 'Sitio impulsado por <a href="https://estandar.cl" style="color:#007cba;text-decoration:none;">Estándar.cl</a> — Soluciones empresariales'
				. ' <a href="https://estandar.cl" style="color:#007cba;text-decoration:none;">Conoce Estandar Studio →</a>';
		}

		$identity = array(
			'site_name'    => $site_name,
			'brand_color'  => $brand_color,
			'logo_url'     => function_exists( 'esc_url_raw' ) ? esc_url_raw( $logo_url ) : $logo_url,
			'header_text'  => (string) $capv( 'header_text' ),
			'footer_text'  => $footer_text,
			'preheader'    => (string) $capv( 'preheader' ),
			'sender_name'  => (string) ( $capv( 'sender_name' ) ?: $site_name ),
			'sender_email' => (string) $capv( 'sender_email' ),
		);

		// PROJECT_BRANDING_V1: skin del proyecto (alias del mismo motor) — si el
		// proyecto definió branding, sustituye el default del sitio.
		if ( $project_slug !== '' ) {
			$skin = self::project_skin( $project_slug );
			foreach ( array( 'brand_color', 'logo_url', 'header_text', 'footer_text' ) as $k ) {
				if ( $skin[ $k ] !== '' ) {
					$identity[ $k ] = $skin[ $k ];
				}
			}
		}

		foreach ( $overrides as $k => $v ) {
			if ( $v !== '' ) { $identity[ $k ] = $v; }
		}
		return $identity;
	}

	/**
	 * Banda de cabecera de marca (logo o nombre del sitio sobre color).
	 * HTML fragment (div) usable dentro de cualquier cuerpo de correo.
	 */
	public static function header_html( array $identity ): string
	{
		$brand = esc_attr( (string) ( $identity['brand_color'] ?? self::DEFAULT_BRAND_COLOR ) );
		$logo  = esc_url( (string) ( $identity['logo_url'] ?? '' ) );
		if ( $logo !== '' ) {
			$content = '<img src="' . $logo . '" alt="" style="max-height:44px;width:auto;display:block;">';
		} else {
			$name = esc_html( (string) ( $identity['header_text'] ?? $identity['site_name'] ?? '' ) );
			$content = '<span style="font-size:18px;font-weight:700;color:#ffffff;letter-spacing:-.3px;'
				. 'font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Arial,sans-serif;">' . $name . '</span>';
		}
		return '<div style="padding:14px 16px;border-radius:8px 8px 0 0;background:' . $brand . ';">' . $content . '</div>';
	}

	/** Footer Estandar compartido (HTML fragment). */
	public static function footer_html( array $identity ): string
	{
		$text = (string) ( $identity['footer_text'] ?? '' );
		return '<div style="margin-top:22px;padding-top:10px;border-top:1px solid #e2e4e7;color:#888;font-size:12px;">'
			. $text . '</div>';
	}

	// ── Plantillas de correo (MAIL_TEMPLATES_V1, Fase 1 — registro + editor) ──
	//
	// Base de datos: opción única `estandar_suite_core_mail_templates` = array
	//   slug => { name, header_html, content_style, footer_html }
	// Cada form/notificación podrá (más adelante, por tipología) elegir una
	// plantilla (o 'none') y su contenido se inyecta en el slot {{content}}.
	// FASE 1: TODO es aditivo y en OFF — ninguna ruta de envío consume plantillas
	// todavía. Solo registro + editor + preview + resolución pura.

	/** Opción única del registro de plantillas. */
	const TEMPLATES_OPTION = 'estandar_suite_core_mail_templates';
	/** Opción del slug por defecto a nivel global ('' = ninguna). */
	const TEMPLATE_DEFAULT_OPTION = 'estandar_suite_core_mail_template_default';
	/** MAIL_TEMPLATES_SEED_V2: marca de siembra del set por defecto (aditivo). */
	const TEMPLATES_SEEDED_OPTION = 'estandar_suite_core_mail_templates_seeded';

	/** Set de plantillas por defecto (MAIL_TEMPLATES_SEED_V2). Semilla neutra:
	 *  usan tokens {{site_name}} / {{brand_color}} / {{footer_text}} / {{logo}},
	 *  así reflejan la identidad configurada. Aditivo: nunca pisa lo editado. */
	public static function default_mail_templates(): array
	{
		return array(
			'base' => array(
				'name'          => 'Básica (neutra)',
				'header_html'   => '<div style="padding:16px 20px;background:{{brand_color}};border-radius:8px 8px 0 0;">'
					. '<span style="font-size:17px;font-weight:700;color:#ffffff;">{{site_name}}</span></div>',
				'content_style' => 'padding:20px;font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;'
					. 'font-size:14px;line-height:1.55;color:#1d2327;',
				'footer_html'   => '<div style="padding:12px 20px;color:#888888;font-size:12px;'
					. 'border-top:1px solid #e2e4e7;">{{footer_text}}</div>',
			),
			'corporativa' => array(
				'name'          => 'Corporativa',
				'wrapper_style' => 'max-width:600px;margin:0 auto;background:#eef0f2;border-radius:8px;overflow:hidden;'
					. 'font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Arial,sans-serif;',
				'header_html'   => '<div style="padding:20px 24px;background:{{brand_color}};">'
					. '<span style="font-size:18px;font-weight:700;letter-spacing:-.3px;color:#ffffff;">{{site_name}}</span></div>',
				'content_style' => 'padding:24px 24px 8px;font-size:14px;line-height:1.6;color:#1d2327;',
				'footer_html'   => '<div style="padding:14px 24px;color:#6b7280;font-size:10px;">{{footer_text}}</div>',
			),
			'minimal' => array(
				'name'          => 'Minimal',
				'header_html'   => '<div style="padding:18px 20px 0;">'
					. '<span style="font-size:16px;font-weight:700;color:#1d2327;">{{site_name}}</span></div>',
				'content_style' => 'padding:14px 20px 20px;font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;'
					. 'font-size:14px;line-height:1.6;color:#333333;',
				'footer_html'   => '<div style="padding:10px 20px;color:#9ca3af;font-size:12px;">{{footer_text}}</div>',
			),
			'boletin' => array(
				'name'          => 'Boletín',
				'header_html'   => '<div style="padding:22px;background:{{brand_color}};border-radius:8px 8px 0 0;text-align:center;">'
					. '<span style="font-size:20px;font-weight:700;letter-spacing:.4px;text-transform:uppercase;color:#ffffff;">{{site_name}}</span></div>',
				'content_style' => 'padding:22px 24px;font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;'
					. 'font-size:14px;line-height:1.65;color:#1d2327;',
				'footer_html'   => '<div style="padding:16px 24px;text-align:center;color:#888888;font-size:12px;'
					. 'border-top:2px solid {{brand_color}};">{{footer_text}}</div>',
			),
		);
	}

	/** Semilla de la plantilla básica (compat: creación de plantilla nueva). */
	public static function mail_template_seed(): array
	{
		$all = self::default_mail_templates();
		return $all['base'];
	}

	/** Registro completo de plantillas. Siembra el set por defecto si está vacío
	 *  y lo agrega (aditivo, sin pisar) una vez en instalaciones existentes. */
	public static function mail_templates(): array
	{
		$all = get_option( self::TEMPLATES_OPTION, array() );
		$all = is_array( $all ) ? $all : array();
		if ( empty( $all ) ) {
			$all = self::default_mail_templates();
			update_option( self::TEMPLATES_OPTION, $all, false );
			update_option( self::TEMPLATES_SEEDED_OPTION, '2', false );
			return $all;
		}
		if ( (string) get_option( self::TEMPLATES_SEEDED_OPTION, '' ) !== '2' ) {
			foreach ( self::default_mail_templates() as $slug => $t ) {
				if ( ! isset( $all[ $slug ] ) ) {
					$all[ $slug ] = $t;
				}
			}
			update_option( self::TEMPLATES_OPTION, $all, false );
			update_option( self::TEMPLATES_SEEDED_OPTION, '2', false );
		}
		return $all;
	}

	/** Una plantilla por slug (o null). */
	public static function mail_template( string $slug ): ?array
	{
		if ( $slug === '' || $slug === 'none' ) {
			return null;
		}
		$all = self::mail_templates();
		$t   = $all[ $slug ] ?? null;
		return is_array( $t ) ? $t : null;
	}

	/** Guarda el registro (aditivo/reescribe por slug). Devuelve el registro. */
	public static function save_mail_templates( array $all ): array
	{
		$clean = array();
		foreach ( $all as $slug => $t ) {
			$slug = sanitize_key( (string) $slug );
			if ( $slug === '' || ! is_array( $t ) ) {
				continue;
			}
			$header = (string) ( $t['header_html'] ?? '' );
			$footer = (string) ( $t['footer_html'] ?? '' );
			// wp_kses_post solo existe en WP real (protege el HTML del header/
			// footer). En el harness puro de tests se omite el kses.
			if ( function_exists( 'wp_kses_post' ) ) {
				$header = wp_kses_post( $header );
				$footer = wp_kses_post( $footer );
			}
			$clean[ $slug ] = array(
				'name'          => sanitize_text_field( (string) ( $t['name'] ?? '' ) ),
				'wrapper_style' => sanitize_text_field( (string) ( $t['wrapper_style'] ?? '' ) ),
				'header_html'   => $header,
				'content_style' => sanitize_text_field( (string) ( $t['content_style'] ?? '' ) ),
				'footer_html'   => $footer,
			);
		}
		update_option( self::TEMPLATES_OPTION, $clean, false );
		return $clean;
	}

	/** MAIL_TEMPLATES_V1 (PURE): envuelve el contenido en la plantilla. El slot
	 *  {{content}} se reemplaza por el contenido; los tokens de identidad
	 *  ({{site_name}}, {{footer_text}}, {{brand_color}}…) los resuelve el llamador
	 *  antes (vars de Mail::render) o quedan para render posterior. */
	public static function wrap_template( array $template, string $content ): string
	{
		$header = (string) ( $template['header_html'] ?? '' );
		$footer = (string) ( $template['footer_html'] ?? '' );
		$style  = (string) ( $template['content_style'] ?? '' );
		$inner  = '<div style="' . esc_attr( $style ) . '">{{content}}</div>';
		// MAIL_TEMPLATES_WRAPPER_V1: el contenedor (600px) es configurable por
		// plantilla; si no se define, se usa el default neutro (blanco).
		$wrapper = (string) ( $template['wrapper_style'] ?? '' );
		if ( trim( $wrapper ) === '' ) {
			$wrapper = 'max-width:600px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,'
				. '\'Segoe UI\',Arial,sans-serif;background:#ffffff;';
		}
		$layout = '<div style="' . esc_attr( $wrapper ) . '">'
			. $header . $inner . $footer . '</div>';
		return strtr( $layout, array( '{{content}}' => $content ) );
	}

	/** MAIL_TEMPLATES_V1 (PURE): resuelve qué plantilla usa un envío.
	 *  Precedencia: config del form (`mail_template` slug o 'none') → skin del
	 *  proyecto (`mail_template`) → default global ('' = ninguna, OFF). */
	public static function resolve_template( array $form_config = array(), string $project_slug = '' ): ?array
	{
		$candidate = '';
		$from_form = (string) ( $form_config['mail_template'] ?? '' );
		if ( $from_form !== '' ) {
			$candidate = $from_form;
		} elseif ( $project_slug !== '' ) {
			$skin = self::project_skin( $project_slug );
			$from_skin = (string) ( $skin['mail_template'] ?? '' );
			if ( $from_skin !== '' ) {
				$candidate = $from_skin;
			}
		}
		if ( $candidate === '' ) {
			$candidate = (string) get_option( self::TEMPLATE_DEFAULT_OPTION, '' );
		}
		if ( $candidate === '' || $candidate === 'none' ) {
			return null;
		}
		return self::mail_template( $candidate );
	}
}
