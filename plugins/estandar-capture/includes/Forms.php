<?php

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * V2-managed forms (create / update / delete / list). Owns its own table
 * estandar_capture_forms; NEVER writes to the legacy wp_estandar_forms.
 * Types are extensible: lead, incident, survey (+ filter). config_json holds
 * the type-specific configuration (e.g. survey_config).
 */
class Forms
{
	const TABLE   = 'estandar_capture_forms';
	const OPTION  = 'estandar_suite_capture_forms_schema_version';
	const VERSION = '1.0.0';

	/** Cron del envío DIFERIDO de la autorespuesta (AUTORESPONSE_DELAYED_V1). */
	const CRON_DELAYED = 'estandar_suite_capture_autoresponse_delayed';
	/** Option global por proyecto: desfase del envío diferido. */
	const OPTION_DELAY = 'estandar_suite_capture_autoresponse_delay';
	/** Prefijo del transient de dedupe (1 envío diferido por submission). */
	const TRANSIENT_DONE = 'es_ar_delayed_sent_';
	/** Prefijo del transient de dedupe de autorespuesta (EMAIL_DEDUPE_V1). */
	const TRANSIENT_AR_DEDUPE = 'es_autoresponse_dedupe_';
	/** Prefijo del transient de dedupe de la notificación interna (EMAIL_DEDUPE_V1). */
	const TRANSIENT_NOTIFY_DEDUPE = 'es_notify_dedupe_';

	/** LEAD_RESPONSE_META_V1: autorespuesta de LEADS por proyecto/campaña (con PDF). */
	const OPTION_LEADS_AR = 'estandar_suite_capture_leads_autoresponse';
	/** Marca de adopción única desde el legacy `estandar_leads_autoresponse_settings`. */
	const LEADS_AR_ADOPT_FLAG = 'estandar_suite_capture_leads_autoresponse_adopted';
	/** MAIL_TRACKING_V2: registrar aperturas (píxel) y clics (redirección). */
	const OPTION_LEADS_AR_TRACK = 'estandar_suite_capture_leads_autoresponse_tracking';

	/** Registra el handler del cron del envío diferido (se llama en plugins_loaded). */
	public static function init(): void
	{
		add_action( self::CRON_DELAYED, array( __CLASS__, 'autoresponse_delayed_cron' ), 10, 3 );
		// LEAD_RESPONSE_META_V1: los leads de Meta (formularios instantáneos)
		// entran al pipeline por `estandar_suite_form_entry_created` con
		// $source='facebook'; usan la autorespuesta de LEADS por proyecto
		// (independiente de la autorespuesta de los forms web).
		add_action( 'estandar_suite_form_entry_created', array( __CLASS__, 'leads_autoresponse_on_entry' ), 20, 3 );
	}

	/**
	 * LEAD_RESPONSE_META_V1 — disparador de la autorespuesta de LEADS.
	 * Solo actúa para leads de Meta ($source='facebook'); resuelve el proyecto
	 * desde el propio lead y envía la autorespuesta configurada para ese
	 * proyecto/campaña, adjuntando su PDF. Nunca lanza: un fallo aquí no debe
	 * romper la ingesta (ya persistida).
	 */
	public static function leads_autoresponse_on_entry( $capture, $source, $form_id ): void
	{
		try {
			if ( (string) $source !== 'facebook' || ! is_array( $capture ) ) {
				return;
			}
			// La captura CANÓNICA de Ingestion::normalize() NO trae `fields`:
			// trae `project_slug` en el nivel superior y los campos crudos como
			// `fields_json` (string JSON). Se aceptan las tres formas.
			$slug = '';
			if ( ! empty( $capture['project_slug'] ) ) {
				$slug = (string) $capture['project_slug'];
			} elseif ( is_array( $capture['fields'] ?? null ) && ! empty( $capture['fields']['project_slug'] ) ) {
				$slug = (string) $capture['fields']['project_slug'];
			} elseif ( ! empty( $capture['fields_json'] ) ) {
				$f = json_decode( (string) $capture['fields_json'], true );
				if ( is_array( $f ) && ! empty( $f['project_slug'] ) ) {
					$slug = (string) $f['project_slug'];
				}
			}
			$slug = function_exists( 'sanitize_key' ) ? sanitize_key( $slug ) : strtolower( (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $slug ) ) );
			$result = self::send_leads_autoresponse( $capture, $slug );
			do_action( 'estandar_suite_capture_leads_autoresponse_sent', $capture, $result, $form_id );
		} catch ( \Throwable $e ) {
			// swallow: la ingesta del lead ya ocurrió.
		}
	}

	/**
	 * LEAD_RESPONSE_META_V1 — envío inmediato de la autorespuesta de LEADS para
	 * un proyecto/campaña (config `OPTION_LEADS_AR`), con PDF adjunto. Reutiliza
	 * el motor Core\Mail y las variables de plantilla de la Suite.
	 */
	public static function send_leads_autoresponse( array $capture, string $project_slug ): array
	{
		$cfg = self::leads_autoresponse_for( $project_slug );
		if ( empty( $cfg ) || empty( $cfg['enabled'] ) ) {
			return array( 'ok' => false, 'kind' => 'leads_autoresponse', 'project' => $project_slug, 'error' => 'disabled' );
		}
		if ( ! class_exists( 'Estandar\\Core\\Mail' ) ) {
			return array( 'ok' => false, 'kind' => 'leads_autoresponse', 'project' => $project_slug, 'error' => 'sin_motor' );
		}
		$m  = self::mail_settings( array() );
		$to = self::resolve_autoresponse_recipient( $capture, (string) $m['autoresponse']['email_field'] );
		if ( ! \Estandar\Core\Mail::validate_address( $to ) ) {
			return array( 'ok' => false, 'kind' => 'leads_autoresponse', 'project' => $project_slug, 'error' => 'email_receptor_invalido' );
		}
		$form_id = (int) ( $capture['source_form_id'] ?? 0 );
		// EMAIL_DEDUPE_V1 (scope propio de leads): un envío por email+proyecto.
		if ( ! self::email_dedupe_exempt( strtolower( $to ) ) && ! self::dedupe_email( self::TRANSIENT_AR_DEDUPE, 'lead_' . strtolower( $to ) . '_' . $project_slug, $form_id ) ) {
			return array( 'ok' => false, 'kind' => 'leads_autoresponse', 'project' => $project_slug, 'error' => 'dedupe_window' );
		}

		$identity = self::mail_identity( array(), $project_slug );
		$vars     = self::submission_vars( 0, array(), $capture );
		$vars     = array_merge( $vars, self::brand_vars( $identity ) );

		$subject = trim( (string) ( $cfg['subject'] ?? '' ) );
		if ( $subject === '' ) {
			$subject = (string) $m['autoresponse']['subject'];
		}
		// Adjunto PDF + URL, ANTES del render (el cuerpo puede enlazar el PDF).
		$attachments = array();
		$pdf_link    = '';
		$attachment_id = absint( $cfg['pdf_attachment_id'] ?? 0 );
		if ( $attachment_id > 0 ) {
			$file = is_callable( 'get_attached_file' ) ? get_attached_file( $attachment_id ) : false;
			if ( is_string( $file ) && is_readable( $file ) ) {
				$attachments[] = $file;
			}
			if ( is_callable( 'wp_get_attachment_url' ) ) {
				$pdf_url = (string) wp_get_attachment_url( $attachment_id );
				if ( $pdf_url !== '' ) {
					$pdf_link = $pdf_url;
				}
			}
		}
		$link_tokens = array(
			'pdf_link'      => $pdf_link,
			'landing_link'  => (string) ( $cfg['landing_url'] ?? '' ),
			'whatsapp_link' => self::project_whatsapp_link( $project_slug ),
		);

		// MAIL_TRACKING_V2: píxel de apertura + enlace del PDF trackeado (si hay
		// motor de tracking y no se desactivó en la config).
		$track = array();
		$tracking_on = get_option( self::OPTION_LEADS_AR_TRACK, '1' ) !== '0'
			&& class_exists( 'Estandar\\Core\\Leads\\MailTracking' );
		if ( $tracking_on ) {
			$track = array(
				'lead_id'       => \Estandar\Core\Leads\MailTracking::resolve_lead_id( $capture, $to, (string) ( $capture['phone_normalized'] ?? '' ) ),
				'submission_id' => 0,
				'capture_id'    => (int) ( $capture['id'] ?? 0 ),
				'project'       => $project_slug,
				'resource'      => 'pdf',
			);
			if ( $pdf_link !== '' ) {
				$tracked = \Estandar\Core\Leads\MailTracking::click_url( $pdf_link, $track );
				if ( is_string( $tracked ) && $tracked !== '' ) {
					$pdf_link               = $tracked;
					$link_tokens['pdf_link'] = $tracked;
				}
			}
		}

		$body = trim( (string) ( $cfg['body'] ?? '' ) );
		if ( $body === '' ) {
			$body = self::autoresponse_body_default();
		} elseif ( strpos( $body, '<' ) === false ) {
			// Cuerpo de texto plano: escapa y convierte saltos, pero los tokens
			// de link con texto ({{pdf_link|Etiqueta}}) se vuelven hipervínculos.
			$body = '<div style="max-width:560px;margin:0 auto;font-family:Helvetica,Arial,sans-serif;color:#1d2327;font-size:14px;line-height:1.6;">'
				. '{{logo}}<div style="padding:16px 0;">' . self::apply_link_tokens( $body, $link_tokens, true ) . '</div>{{footer_estandar}}</div>';
		} else {
			$body = self::apply_link_tokens( $body, $link_tokens, false );
		}
		if ( ! empty( $track ) ) {
			$pixel = \Estandar\Core\Leads\MailTracking::pixel( $track );
			if ( is_string( $pixel ) && $pixel !== '' ) {
				$body .= $pixel;
			}
		}

		// Firma por el OPERADOR del proyecto (p. ej. Alto Jaramillo → Marta
		// Navarro): nombre, correo y teléfono resueltos del registry/operadores.
		$operator = array();
		if ( class_exists( 'Estandar\\Core\\Leads\\ProjectRegistry' ) && method_exists( 'Estandar\\Core\\Leads\\ProjectRegistry', 'operator_for' ) ) {
			$operator = (array) \Estandar\Core\Leads\ProjectRegistry::operator_for( $project_slug );
		}
		$op_name  = trim( (string) ( $operator['name'] ?? '' ) );
		$op_email = trim( (string) ( $operator['email'] ?? '' ) );
		$op_phone = trim( (string) ( $operator['phone'] ?? '' ) );
		if ( $op_phone !== '' ) {
			$op_phone = self::phone_display( $op_phone );
		}
		$op_default = (string) ( $identity['sender_name'] ?? ( is_callable( 'get_bloginfo' ) ? get_bloginfo( 'name' ) : '' ) );

		$project_name = (string) ( $identity['project_name'] ?? '' );
		if ( $project_name === '' ) { $project_name = $project_slug; }
		$vars = array_merge( $vars, array(
			'nombre'         => (string) ( $vars['name'] ?? '' ),
			'proyecto'       => $project_name,
			'mensaje'        => (string) ( $vars['message'] ?? '' ),
			'telefono'       => (string) ( $vars['phone'] ?? '' ),
			'operador'       => $op_name !== '' ? $op_name : $op_default,
			'cargo'          => '',
			'operator_email' => $op_email,
			'operator_phone' => $op_phone,
			'website'        => is_callable( 'home_url' ) ? home_url( '/' ) : '',
			'pdf_link'       => $pdf_link,
			'landing_link'   => (string) ( $cfg['landing_url'] ?? '' ),
			'whatsapp_link'  => self::project_whatsapp_link( $project_slug ),
		) );

		$r = \Estandar\Core\Mail::send( array(
			'to'          => $to,
			'subject'     => $subject,
			'html'        => $body,
			'alt'         => wp_strip_all_tags( $body ),
			'from_name'   => (string) $m['autoresponse']['sender'],
			'from_email'  => (string) ( $m['autoresponse']['from_email'] ?? '' ),
			'vars'        => $vars,
			'attachments' => $attachments,
		) );
		// MAIL_TRACKING_V2: registra el envío (denominador de tasas) si salió.
		if ( ! empty( $track ) && ! empty( $r['ok'] ) ) {
			\Estandar\Core\Leads\MailTracking::record( 'autoresponse_email_sent', $track, null );
		}
		return array_merge(
			array( 'kind' => 'leads_autoresponse', 'project' => $project_slug, 'pdf_attached' => (bool) $attachments ),
			$r
		);
	}

	/**
	 * LINK_TEXT_V1 — convierte tokens de link CON TEXTO en hipervínculos:
	 *   {{pdf_link|Descargar ficha}}      → <a href="URL-del-PDF">Descargar ficha</a>
	 *   {{landing_link|Ver proyecto}}     → <a href="URL-landing">Ver proyecto</a>
	 *   {{whatsapp_link|Escríbenos}}      → <a href="URL-wa">Escríbenos</a>
	 * Si la URL viene vacía, deja solo la etiqueta (sin enlace roto).
	 * Los tokens SIN texto ({{pdf_link}}) siguen resolviéndose como URL cruda en
	 * el motor de variables (compatibilidad).
	 *
	 * @param array<string,string> $links token => URL
	 */
	private static function apply_link_tokens( string $body, array $links, bool $plain ): string
	{
		$ph = array();
		$body = preg_replace_callback(
			'/\{\{\s*(pdf_link|landing_link|whatsapp_link)\s*\|\s*([^}]+?)\s*\}\}/',
			static function ( $m ) use ( &$ph, $links ) {
				$token = (string) $m[1];
				$label = (string) $m[2];
				$url   = (string) ( $links[ $token ] ?? '' );
				$key   = '%%LNK' . count( $ph ) . '%%';
				$ph[ $key ] = $url !== ''
					? '<a href="' . esc_url( $url ) . '" style="color:#0a6cb5;text-decoration:underline;">' . esc_html( $label ) . '</a>'
					: esc_html( $label );
				return $key;
			},
			$body
		);
		if ( $plain ) {
			$body = nl2br( esc_html( $body ) );
		}
		return strtr( $body, $ph );
	}

	/**
	 * LEAD_RESPONSE_META_V1 — mapa de autorespuesta de LEADS por proyecto.
	 * Adopta UNA vez el legacy `estandar_leads_autoresponse_settings` (sin
	 * pisar lo ya configurado y sin tocar el legacy) para no perder los PDF
	 * ya definidos.
	 *
	 * @return array<string,array{enabled:bool,subject:string,body:string,pdf_attachment_id:int}>
	 */
	public static function leads_autoresponse_map(): array
	{
		$map = get_option( self::OPTION_LEADS_AR, array() );
		if ( ! is_array( $map ) ) {
			$map = array();
		}
		if ( empty( $map ) && get_option( self::LEADS_AR_ADOPT_FLAG, '0' ) !== '1' ) {
			$map = self::leads_autoresponse_adopt();
		}
		return $map;
	}

	/** Config de autorespuesta de LEADS para un proyecto (o array vacío). */
	public static function leads_autoresponse_for( string $project_slug ): array
	{
		if ( $project_slug === '' ) {
			return array();
		}
		$map = self::leads_autoresponse_map();
		$row = $map[ $project_slug ] ?? null;
		if ( ! is_array( $row ) ) {
			return array();
		}
		return array(
			'enabled'           => ! empty( $row['enabled'] ),
			'subject'           => (string) ( $row['subject'] ?? '' ),
			'body'              => (string) ( $row['body'] ?? '' ),
			'pdf_attachment_id' => absint( $row['pdf_attachment_id'] ?? 0 ),
			'landing_url'       => (string) ( $row['landing_url'] ?? '' ),
		);
	}

	/**
	 * Adopta el legacy `estandar_leads_autoresponse_settings` al mapa nuevo.
	 * Cuando hay varias entradas del mismo proyecto, gana la de mayor calidad
	 * (con PDF); así no vuelve a colarse una entrada vacía duplicada.
	 */
	public static function leads_autoresponse_adopt(): array
	{
		$legacy = get_option( 'estandar_leads_autoresponse_settings', array() );
		$map    = array();
		if ( is_array( $legacy ) ) {
			foreach ( $legacy as $entry ) {
				if ( ! is_array( $entry ) || empty( $entry['enabled'] ) ) {
					continue;
				}
				$slug = function_exists( 'sanitize_key' ) ? sanitize_key( (string) ( $entry['project_slug'] ?? '' ) ) : strtolower( (string) ( $entry['project_slug'] ?? '' ) );
				if ( $slug === '' ) {
					continue;
				}
				$cand = array(
					'enabled'           => true,
					'subject'           => (string) ( $entry['subject'] ?? '' ),
					'body'              => (string) ( $entry['body'] ?? '' ),
					'pdf_attachment_id' => absint( $entry['pdf_attachment_id'] ?? 0 ),
					'landing_url'       => (string) ( $entry['landing_url'] ?? '' ),
				);
				if ( ! isset( $map[ $slug ] ) || ( $cand['pdf_attachment_id'] > 0 && empty( $map[ $slug ]['pdf_attachment_id'] ) ) ) {
					$map[ $slug ] = $cand;
				}
			}
		}
		update_option( self::OPTION_LEADS_AR, $map, false );
		update_option( self::LEADS_AR_ADOPT_FLAG, '1', false );
		return $map;
	}

	/**
	 * Extensible form types. INCIDENT_TYPE_RETIRED: solo Formulario (`lead`) y
	 * Encuesta (`survey`). El tipo `incident`/postventa ya no se ofrece (postventa
	 * se reemplazó por un form normal). Los forms que aún tengan form_type
	 * `incident` siguen renderizando; al guardarlos, `sanitize()` los pasa a lead.
	 */
	public static function types(): array
	{
		$types = array( 'lead' => 'Lead', 'survey' => 'Encuesta' );
		$filtered = apply_filters( 'estandar_suite_capture_form_types', $types );
		return is_array( $filtered ) && $filtered ? $filtered : $types;
	}

	/** Closed list of business contexts (max 5 + "otros"). */
	public static function contexts(): array
	{
		$contexts = array(
			'generic'            => 'General',
			'inmobiliaria'       => 'Inmobiliaria',
			'accounting_services' => 'Servicios contables',
			'legal'              => 'Legal',
			'retail'             => 'Comercio / Retail',
			'otros'              => 'Otros',
		);
		$filtered = apply_filters( 'estandar_suite_capture_form_contexts', $contexts );
		return is_array( $filtered ) && $filtered ? $filtered : $contexts;
	}

	/** Human label for a context key (falls back to "Otros"). */
	public static function context_label( string $key ): string
	{
		$ctx = self::contexts();
		return isset( $ctx[ $key ] ) ? $ctx[ $key ] : ( $ctx['otros'] ?? $key );
	}

	const CAP_OPTION = 'estandar_suite_capture_product_caps';

	/**
	 * Capability catalog per product type. All products share the same engine
	 * (fields/config); capabilities toggle which features each type exposes.
	 * Enabled by default from the catalog; per-type overrides in CAP_OPTION.
	 */
	public static function product_capabilities( string $type ): array
	{
		$common = array(
			'fields_builder' => array( 'label' => 'Constructor de campos', 'desc' => 'Editor de campos/preguntas del producto.', 'default' => 1 ),
			'submit'         => array( 'label' => 'Botón de envío', 'desc' => 'Botón para enviar/terminar.', 'default' => 1 ),
			'webhook'        => array( 'label' => 'Webhook de salida', 'desc' => 'Enviar la captura a un destino externo (n8n, Zapier).', 'default' => 1 ),
			'notify_email'   => array( 'label' => 'Notificación por email', 'desc' => 'Avisar por correo al completar.', 'default' => 0 ),
			'recaptcha'      => array( 'label' => 'reCAPTCHA', 'desc' => 'Protección anti-spam.', 'default' => 1 ),
		);
		$survey_extra = array(
			'step_by_step'   => array( 'label' => 'Paso a paso (Typeform)', 'desc' => 'Muestra las preguntas una a una con animaciones.', 'default' => 1 ),
			'hero'           => array( 'label' => 'Pantalla de inicio (hero)', 'desc' => 'Título, subtítulo y CTA inicial.', 'default' => 1 ),
			'identification' => array( 'label' => 'Modo de identificación', 'desc' => 'Anónimo u opcional con identidad.', 'default' => 1 ),
			'completion'     => array( 'label' => 'Pantalla de cierre', 'desc' => 'Mensaje de agradecimiento al terminar.', 'default' => 1 ),
		);
		$postventa_extra = array(
			'incident_fields' => array( 'label' => 'Campos de incidente', 'desc' => 'Campos específicos de caso/incidente.', 'default' => 1 ),
			'attachments'     => array( 'label' => 'Adjuntos', 'desc' => 'Permite adjuntar archivos.', 'default' => 1 ),
			'follow_up'       => array( 'label' => 'Seguimiento', 'desc' => 'Seguimiento del caso de postventa.', 'default' => 1 ),
			// Motor único: postventa también puede renderizar POR ETAPAS (igual
			// que survey) sin cambiar de tipo; es la misma capacidad del motor.
			'step_by_step'    => array( 'label' => 'Paso a paso (etapas)', 'desc' => 'Muestra los campos una a una con animaciones (mismo motor que Survey).', 'default' => 0 ),
		);
		$lead_extra = array(
			// Motor único: un form de captación (lead) también puede revelar
			// los campos uno a uno (step_by_step) o condicionalmente (show_when)
			// con el mismo motor conversacional de Survey, sin cambiar de tipo.
			'step_by_step'    => array( 'label' => 'Paso a paso (revelar campos)', 'desc' => 'Muestra los campos uno a uno con animaciones (mismo motor que Survey).', 'default' => 0 ),
		);
		$base = $common;
		if ( $type === 'survey' ) {
			$base = array_merge( $base, $survey_extra );
		} elseif ( $type === 'incident' ) {
			$base = array_merge( $base, $postventa_extra );
		} elseif ( $type === 'lead' ) {
			$base = array_merge( $base, $lead_extra );
		}
		$filtered = apply_filters( 'estandar_suite_capture_product_capabilities', $base, $type );
		return is_array( $filtered ) && $filtered ? $filtered : $base;
	}

	/** Enabled overrides per type (array( $type => array( cap => 0|1 ) )). */
	public static function caps_overrides(): array
	{
		$saved = get_option( self::CAP_OPTION, array() );
		return is_array( $saved ) ? $saved : array();
	}

	public static function is_capability_enabled( string $type, string $cap ): bool
	{
		$catalog = self::product_capabilities( $type );
		$default = isset( $catalog[ $cap ]['default'] ) ? (int) $catalog[ $cap ]['default'] : 0;
		$over    = self::caps_overrides();
		$value   = isset( $over[ $type ][ $cap ] ) ? (int) $over[ $type ][ $cap ] : $default;
		return $value === 1;
	}

	public static function set_capability( string $type, string $cap, bool $enabled ): void
	{
		$over  = self::caps_overrides();
		$over[ $type ][ $cap ] = $enabled ? 1 : 0;
		update_option( self::CAP_OPTION, $over, false );
	}

	/**
	 * Union capability catalog across catcher types, for the Settings matrix.
	 * Format: cap => array( 'label', 'desc', 'types' => array( type => true ) ).
	 */
	public static function capability_matrix(): array
	{
		$types = array( 'lead', 'survey' );
		$union = array();
		foreach ( $types as $t ) {
			foreach ( self::product_capabilities( $t ) as $cap => $cfg ) {
				if ( ! isset( $union[ $cap ] ) ) {
					$union[ $cap ] = array( 'label' => $cfg['label'], 'desc' => $cfg['desc'], 'types' => array() );
				}
				$union[ $cap ]['types'][ $t ] = true;
			}
		}
		return $union;
	}

	public static function activate_schema(): void
	{
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$wpdb->prefix}" . self::TABLE . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(190) NOT NULL DEFAULT '',
			slug varchar(190) NOT NULL DEFAULT '',
			form_type varchar(40) NOT NULL DEFAULT 'lead',
			form_context varchar(190) NOT NULL DEFAULT 'generic',
			config_json longtext,
			status varchar(40) NOT NULL DEFAULT 'active',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY slug (slug),
			KEY form_type (form_type)
		) $charset;" );
		update_option( self::OPTION, self::VERSION, false );
	}

	public static function maybe_migrate_schema(): void
	{
		if ( get_option( self::OPTION, '' ) !== self::VERSION ) {
			self::activate_schema();
		}
	}

	/** PROJECT_OWNERSHIP_V1 · versión del backfill de actualización. */
	const PROJECT_OWNERSHIP_V1 = '1';

	/** ¿El config de un catcher ya declara pertenencia a algún proyecto? */
	public static function form_has_project( array $config ): bool
	{
		if ( self::form_project( $config ) !== '' ) {
			return true;
		}
		$ctx = (string) ( $config['context_schema'] ?? '' );
		if ( $ctx !== '' ) {
			$schema  = json_decode( $ctx, true );
			$routing = ( is_array( $schema ) && is_array( $schema['routing'] ?? null ) ) ? $schema['routing'] : array();
			$mode    = sanitize_key( (string) ( $routing['mode'] ?? '' ) );
			if ( in_array( $mode, array( 'fixed_project', 'project_selector' ), true ) && ! empty( $routing['project_slug'] ) ) {
				return true;
			}
			if ( $mode === 'project_selector' ) {
				return true;
			}
		}
		return false;
	}

	/** Catchers cuya propietario actual es <slug> (form_project o routing fixed). */
	public static function forms_of_project( string $slug ): array
	{
		global $wpdb;
		$slug = sanitize_key( remove_accents( $slug ) );
		if ( $slug === '' || ! isset( $wpdb ) ) {
			return array();
		}
		$table = $wpdb->prefix . self::TABLE;
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}
		$out = array();
		foreach ( (array) $wpdb->get_results( "SELECT id, name, config_json FROM {$table} ORDER BY id ASC", ARRAY_A ) as $row ) {
			$config = json_decode( (string) ( $row['config_json'] ?? '{}' ), true );
			if ( ! is_array( $config ) ) {
				$config = array();
			}
			if ( ! self::config_belongs_to( $config, $slug ) ) {
				continue;
			}
			$out[] = array(
				'id'   => (int) $row['id'],
				'name' => (string) ( $row['name'] ?? '' ),
			);
		}
		return $out;
	}

	/** ¿Este config pertenece a <slug>? (form_project o routing fixed_project). */
	public static function config_belongs_to( array $config, string $slug ): bool
	{
		$slug = sanitize_key( remove_accents( $slug ) );
		if ( $slug === '' ) {
			return false;
		}
		$own = sanitize_key( remove_accents( (string) ( $config['form_project'] ?? '' ) ) );
		if ( $own === $slug ) {
			return true;
		}
		$ctx = (string) ( $config['context_schema'] ?? '' );
		if ( $ctx !== '' ) {
			$schema  = json_decode( $ctx, true );
			$routing = ( is_array( $schema ) && is_array( $schema['routing'] ?? null ) ) ? $schema['routing'] : array();
			if ( sanitize_key( (string) ( $routing['mode'] ?? '' ) ) === 'fixed_project'
				&& sanitize_key( remove_accents( (string) ( $routing['project_slug'] ?? '' ) ) ) === $slug ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Reasigna la propiedad de todos los catchers de <from> a <to> (con su label
	 * en form_project_area). Devuelve cuántos se actualizaron. No borra nada.
	 */
	public static function reassign_project_ownership( string $from, string $to, string $to_label = '' ): int
	{
		global $wpdb;
		$from = sanitize_key( remove_accents( $from ) );
		$to   = sanitize_key( remove_accents( $to ) );
		if ( $from === '' || $to === '' || $from === $to || ! isset( $wpdb ) ) {
			return 0;
		}
		$table = $wpdb->prefix . self::TABLE;
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return 0;
		}
		$count = 0;
		foreach ( (array) $wpdb->get_results( "SELECT id, config_json FROM {$table}", ARRAY_A ) as $row ) {
			$config = json_decode( (string) ( $row['config_json'] ?? '{}' ), true );
			if ( ! is_array( $config ) ) {
				$config = array();
			}
			if ( ! self::config_belongs_to( $config, $from ) ) {
				continue;
			}
			$config['form_project']      = $to;
			$config['form_project_area'] = sanitize_text_field( $to_label !== '' ? $to_label : $to );
			$ctx = (string) ( $config['context_schema'] ?? '' );
			if ( $ctx !== '' ) {
				$schema  = json_decode( $ctx, true );
				if ( is_array( $schema ) ) {
					$routing = isset( $schema['routing'] ) && is_array( $schema['routing'] ) ? $schema['routing'] : array();
					if ( (string) ( $routing['mode'] ?? '' ) === 'fixed_project' && sanitize_key( remove_accents( (string) ( $routing['project_slug'] ?? '' ) ) ) === $from ) {
						$routing['project_slug'] = $to;
						$schema['routing']       = $routing;
						$config['context_schema'] = (string) wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
					}
				}
			}
			$wpdb->update(
				$table,
				array( 'config_json' => (string) wp_json_encode( $config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ),
				array( 'id' => (int) $row['id'] )
			);
			$count++;
		}
		return $count;
	}

	/**
	 * PROJECT_OWNERSHIP_V1 · backfill de ACTUALIZACIÓN (corre UNA vez): todo
	 * catcher existente SIN proyecto (ni form_project ni routing a proyecto) se
	 * asigna al proyecto por defecto de la web (auto, nombre del sitio). No borra
	 * ni pisa nada: solo agrega form_project/form_project_area a forms que no
	 * tenían ninguno. Llamado en plugins_loaded.
	 */
	public static function maybe_migrate_project_ownership(): void
	{
		$opt = 'estandar_suite_capture_project_ownership_v1';
		if ( get_option( $opt, '' ) === self::PROJECT_OWNERSHIP_V1 ) {
			return;
		}
		$PR = '\Estandar\Core\Leads\ProjectRegistry';
		if ( ! class_exists( $PR ) ) {
			update_option( $opt, self::PROJECT_OWNERSHIP_V1, false );
			return;
		}
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			update_option( $opt, self::PROJECT_OWNERSHIP_V1, false );
			return;
		}
		$needs = array();
		$rows  = $wpdb->get_results( "SELECT id, config_json FROM {$table}", ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$config = json_decode( (string) ( $row['config_json'] ?? '{}' ), true );
			if ( ! is_array( $config ) ) {
				$config = array();
			}
			if ( ! self::form_has_project( $config ) ) {
				$needs[] = (int) $row['id'];
			}
		}
		if ( $needs ) {
			$PR::maybe_install();
			$slug = $PR::auto_project_slug();
			if ( $slug !== '' ) {
				$label = $PR::label_for( $slug );
				foreach ( $needs as $fid ) {
					$cur = $wpdb->get_var( $wpdb->prepare( "SELECT config_json FROM {$table} WHERE id = %d", $fid ) );
					$cfg = json_decode( (string) $cur, true );
					if ( ! is_array( $cfg ) ) {
						$cfg = array();
					}
					if ( self::form_has_project( $cfg ) ) {
						continue;
					}
					$cfg['form_project']      = $slug;
					$cfg['form_project_area'] = sanitize_text_field( (string) $label );
					$wpdb->update( $table, array( 'config_json' => (string) wp_json_encode( $cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ), array( 'id' => $fid ) );
				}
			}
		}
		update_option( $opt, self::PROJECT_OWNERSHIP_V1, false );
	}

	/** Sanitize + default a form payload (name required). */
	public static function sanitize( array $raw ): array
	{
		$types  = self::types();
		$type   = sanitize_key( (string) ( $raw['form_type'] ?? 'lead' ) );
		$type   = isset( $types[ $type ] ) ? $type : 'lead';
		$name   = sanitize_text_field( (string) ( $raw['name'] ?? '' ) );
		$slug   = sanitize_title( (string) ( $raw['slug'] ?? '' ) );
		if ( $slug === '' ) {
			$slug = sanitize_title( $name );
		}
		$context = sanitize_key( (string) ( $raw['form_context'] ?? 'generic' ) );
		if ( ! isset( self::contexts()[ $context ] ) ) {
			$context = 'otros';
		}
		$status  = sanitize_key( (string) ( $raw['status'] ?? 'active' ) );
		$status  = in_array( $status, array( 'active', 'draft', 'archived' ), true ) ? $status : 'active';
		$config  = trim( (string) ( $raw['config_json'] ?? '' ) );
		if ( $config === '' ) {
			$config = '{}';
		}
		json_decode( $config );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$config = '{}';
		} else {
			// CONFIG_GUARD_V1: saneo preventivo al guardar. Elimina marcadores
			// internos de TinyMCE (<!--mce:...-->) y documentos HTML completos
			// pegados por error (caso acacios form 17: una página de error de WP
			// quedó incrustada en el body del correo). Solo toca cadenas con esos
			// marcadores; el resto del config se conserva intacto.
			$parsed = json_decode( $config, true );
			if ( is_array( $parsed ) && $parsed ) {
				// PROJECT_GUARD_V1: ningún form puede persistir SIN proyecto
				// (un form sin proyecto no rutea a operador → entregas perdidas,
				// caso acacios). Si falta, se asigna el proyecto del sitio.
				$parsed = self::assign_config_project( $parsed );
				// MAQ_TYPE_GUARD_V1: repara en el servidor campos dependientes
				// degradados (depends_on + type no dependiente → dependent_select).
				$parsed = self::repair_dependent_fields( $parsed );
				// NOTIFY_ALWAYS_V1: el aviso al operador es obligatorio → se fuerza
				// activo en cada guardado (no se puede persistir apagado).
				$parsed['send_email'] = 1;
				$config = (string) wp_json_encode( self::sanitize_config_tree( $parsed ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			}
		}
		return array(
			'name'         => $name,
			'slug'         => $slug,
			'form_type'    => $type,
			'form_context' => $context,
			'config_json'  => $config,
			'status'       => $status,
		);
	}

	/** PURE: quita marcadores de protección de TinyMCE y corta documentos HTML
	 *  completos pegados por error dentro de un texto de config. */
	public static function sanitize_config_text( string $value ): string
	{
		$s = $value;
		$s = (string) preg_replace( '/<!--mce:[^>]*-->/i', '', $s );
		$s = (string) preg_replace( '/<!--mce:[^>]*>/i', '', $s );
		$s = (string) preg_replace( '/<!--mce:[^>]*$/i', '', $s );
		$i = stripos( $s, '<!DOCTYPE' );
		if ( $i === false ) {
			$i = stripos( $s, '<html' );
		}
		if ( $i !== false ) {
			$s = trim( substr( $s, 0, $i ) );
		}
		return trim( $s );
	}

	/** PURE: recorre el config (recursivo) y sanea solo las cadenas que traen
	 *  marcadores TinyMCE o documentos HTML completos. */
	public static function sanitize_config_tree( array $config ): array
	{
		foreach ( $config as $k => $v ) {
			if ( is_array( $v ) ) {
				$config[ $k ] = self::sanitize_config_tree( $v );
			} elseif ( is_string( $v ) ) {
				if ( $k === 'mail_body' && self::is_corrupt_notify_body( $v ) ) {
					// NOTIFY_BODY_HEAL_V1: guard al guardar — repara la plantilla
					// corrupta para que no se vuelva a persistir dañada.
					$config[ $k ] = self::repair_notify_body( $v );
				} elseif ( preg_match( '/<!--mce:|<!DOCTYPE|<html/i', $v ) ) {
					$config[ $k ] = self::sanitize_config_text( $v );
				}
			}
		}
		return $config;
	}

	/** MAQ_TYPE_GUARD_V1 (PURE): un campo que declara `depends_on` (dependiente de
	 *  otro campo) SOLO puede ser `dependent_select`/`project_select`. Si el
	 *  config llega con `depends_on` y un tipo que no soporta dependencia (p. ej.
	 *  `text`), es una degradación del maquetador (JS viejo/caché reescribe el tipo
	 *  al no listarlo): el servidor lo repara a `dependent_select`. Un `text` con
	 *  depends_on no tiene sentido en el motor y rompería el desplegable de calles.
	 *  Se aplica sobre sections y fields_json (ambas representaciones de campos). */
	public static function repair_dependent_fields( array $config ): array
	{
		foreach ( array( 'sections', 'fields_json' ) as $container ) {
			if ( ! is_array( $config[ $container ] ?? null ) ) {
				continue;
			}
			foreach ( $config[ $container ] as $ci => $c ) {
				if ( ! is_array( $c ) ) {
					continue;
				}
				$fields = $c['fields'] ?? null;
				if ( $container === 'fields_json' ) {
					// fields_json es una lista plana de campos (no [{fields}…]).
					if ( is_array( $config[ $container ][ $ci ] ?? null ) && isset( $config[ $container ][ $ci ]['name'] ) ) {
						$config[ $container ][ $ci ] = self::repair_single_field( $config[ $container ][ $ci ] );
					}
					continue;
				}
				if ( ! is_array( $fields ) ) {
					continue;
				}
				foreach ( $fields as $fi => $f ) {
					if ( is_array( $f ) ) {
						$config[ $container ][ $ci ]['fields'][ $fi ] = self::repair_single_field( $f );
					}
				}
			}
		}
		return $config;
	}

	/** MAQ_TYPE_GUARD_V1 (PURE): repara UN campo si su tipo no corresponde a un
	 *  campo con depends_on. */
	private static function repair_single_field( array $f ): array
	{
		$type      = strtolower( (string) ( $f['type'] ?? 'text' ) );
		$depends   = sanitize_key( (string) ( $f['depends_on'] ?? '' ) );
		$dependent_types = array( 'dependent_select', 'project_select', 'select' );
		if ( $depends !== '' && ! in_array( $type, $dependent_types, true ) ) {
			$f['type'] = 'dependent_select';
		}
		return $f;
	}

	/** PURE-ish: garantiza que el config tenga proyecto asignado. Si falta,
	 *  asigna el proyecto del sitio (ProjectRegistry, con instalación) y su área.
	 *  Sin ProjectRegistry disponible → se deja igual (no inventa). */
	public static function assign_config_project( array $config ): array
	{
		if ( ! empty( $config['form_project'] ) ) {
			return $config;
		}
		$PR = '\Estandar\Core\Leads\ProjectRegistry';
		if ( ! class_exists( $PR ) ) {
			return $config;
		}
		$PR::maybe_install();
		$slug = $PR::auto_project_slug();
		if ( $slug === '' ) {
			return $config;
		}
		$config['form_project']      = $slug;
		$config['form_project_area'] = sanitize_text_field( (string) $PR::label_for( $slug ) );
		return $config;
	}

	public static function list( string $type = '' ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}
		$table = $wpdb->prefix . self::TABLE;
		$sql = "SELECT * FROM {$table}";
		if ( $type !== '' && isset( self::types()[ $type ] ) ) {
			$sql .= $wpdb->prepare( ' WHERE form_type = %s', $type );
		}
		$sql .= ' ORDER BY id DESC';
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public static function get( int $id ): ?array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $id <= 0 ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . self::TABLE . ' WHERE id = %d', $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	public static function get_by_slug( string $slug ): ?array
	{
		global $wpdb;
		$slug = sanitize_title( (string) $slug );
		if ( ! isset( $wpdb ) || $slug === '' ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . self::TABLE . ' WHERE slug = %s LIMIT 1', $slug ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	public static function create( array $raw ): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return 0;
		}
		$data = self::sanitize( $raw );
		if ( $data['name'] === '' ) {
			return 0;
		}
		$data['created_at'] = current_time( 'mysql' );
		$data['updated_at'] = $data['created_at'];
		$wpdb->insert( $wpdb->prefix . self::TABLE, $data, array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Detecta pérdida de contenido de config (protección permanente).
	 *
	 * @param array|null $current Config actual.
	 * @param array|null $new     Config que se intenta escribir.
	 * @return string '' si es seguro; motivo si la escritura borraría contenido
	 *                sin haberlo solicitado explícitamente.
	 */
	public static function config_loss_reason( $current, $new ): string
	{
		if ( ! is_array( $new ) ) {
			return 'config_invalida';
		}
		$cur_content = is_array( $current ) && ( ! empty( $current['sections'] ) || ! empty( $current['fields_json'] ) );
		$new_content = ! empty( $new['sections'] ) || ! empty( $new['fields_json'] );
		if ( $cur_content && ! $new_content ) {
			return 'perdida_de_contenido_no_solicitada';
		}
		// Pérdida de LAYOUT dentro de campos: si el documento actual define
		// column_width y el nuevo los omite/resetea, bloquear (no degradar).
		if ( self::layout_loss_detected( $current, $new ) ) {
			return 'perdida_de_anchos_de_campos_no_solicitada';
		}
		return '';
	}

	/**
	 * ¿El nuevo documento pierde los column_width de campos existentes?
	 *
	 * Compara, por `name` de campo, si el actual tenía `column_width` distinto
	 * del default y el nuevo lo omite o lo deja en default (1). No cuenta como
	 * pérdida si el nuevo conserva el mismo valor o uno distinto explícito.
	 */
	private static function layout_loss_detected( $current, $new ): bool
	{
		if ( ! is_array( $current ) || ! is_array( $new ) ) {
			return false;
		}
		$cur_fields = self::flatten_fields( $current['sections'] ?? array() );
		$new_fields = self::flatten_fields( $new['sections'] ?? array() );
		if ( empty( $cur_fields ) ) {
			return false;
		}
		$new_by_name = array();
		foreach ( $new_fields as $f ) {
			if ( is_array( $f ) ) {
				$new_by_name[ sanitize_key( (string) ( $f['name'] ?? '' ) ) ] = $f;
			}
		}
		foreach ( $cur_fields as $f ) {
			if ( ! is_array( $f ) ) {
				continue;
			}
			$n = sanitize_key( (string) ( $f['name'] ?? '' ) );
			if ( $n === '' || ! isset( $new_by_name[ $n ] ) ) {
				continue; // campo eliminado a propósito no es pérdida de layout.
			}
			$cur_cw = $f['column_width'] ?? 1;
			if ( ! is_numeric( $cur_cw ) || (int) $cur_cw <= 1 ) {
				continue;
			}
			$new_f  = $new_by_name[ $n ];
			$has_cw = array_key_exists( 'column_width', $new_f );
			$new_cw = $new_f['column_width'] ?? 1;
			if ( ! $has_cw || $new_cw === '' || ! is_numeric( $new_cw ) || (int) $new_cw <= 1 ) {
				return true;
			}
		}
		return false;
	}

	/** Aplana los campos de todas las secciones (para análisis de layout). */
	private static function flatten_fields( array $sections ): array
	{
		$out = array();
		foreach ( $sections as $sec ) {
			if ( ! is_array( $sec ) ) {
				continue;
			}
			$fields = $sec['fields'] ?? array();
			if ( is_array( $fields ) ) {
				foreach ( $fields as $f ) {
					if ( is_array( $f ) ) {
						$out[] = $f;
					}
				}
			}
		}
		return $out;
	}

	/**
	 * Actualiza un form. $allow_reset=false (default): NUNCA permite que una
	 * escritura degrade config_json con contenido a un documento vacío/{} —
	 * protege cualquier editor/subtab futura. Solo acciones explícitas de
	 * "Reset Form / Vaciar Formulario" llaman con $allow_reset=true.
	 */
	public static function update( int $id, array $raw, bool $allow_reset = false ): bool
	{
		global $wpdb;
		$current = self::get( $id );
		if ( ! $current ) {
			return false;
		}
		// PRESERVE_ON_PARTIAL_V1: un update parcial (solo algunas claves, p. ej.
		// solo config_json) NO debe borrar el resto (name/slug/tipo/estado/contexto)
		// rellenando defaults vacíos. Los campos ausentes se conservan del actual.
		foreach ( array( 'name', 'slug', 'form_type', 'form_context', 'status', 'config_json' ) as $pk ) {
			if ( ! array_key_exists( $pk, $raw ) && isset( $current[ $pk ] ) ) {
				$raw[ $pk ] = $current[ $pk ];
			}
		}
		$data = self::sanitize( $raw );
		// El slug no debe regenerarse desde el nombre si el payload no lo envía:
		// renombrar el form no debe cambiar un slug ya definido.
		if ( trim( (string) ( $raw['slug'] ?? '' ) ) === '' && trim( (string) ( $current['slug'] ?? '' ) ) !== '' ) {
			$data['slug'] = sanitize_title( (string) $current['slug'] );
		}
		$reason = self::config_loss_reason(
			json_decode( (string) $current['config_json'], true ),
			json_decode( (string) $data['config_json'], true )
		);
		if ( $reason !== '' && ! $allow_reset ) {
			if ( class_exists( 'Estandar\\Core\\Telemetry' ) ) {
				\Estandar\Core\Telemetry::emit( 'error', 'SUITE_FORMS_WRITE_BLOCKED', 'Escritura bloqueada: ' . $reason . ' (form ' . $id . ').', array( 'source_plugin' => 'estandar-capture', 'form_id' => $id, 'reason' => $reason ) );
			}
			return false;
		}
		$data['updated_at'] = current_time( 'mysql' );
		$wpdb->update( $wpdb->prefix . self::TABLE, $data, array( 'id' => $id ), array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' ), array( '%d' ) );
		return true;
	}

	public static function delete( int $id ): bool
	{
		global $wpdb;
		if ( ! self::get( $id ) ) {
			return false;
		}
		$wpdb->delete( $wpdb->prefix . self::TABLE, array( 'id' => $id ), array( '%d' ) );
		return true;
	}

	/** Move a form to the papelera (soft delete). */
	public static function trash( int $id ): bool
	{
		global $wpdb;
		if ( ! self::get( $id ) ) {
			return false;
		}
		$wpdb->update( $wpdb->prefix . self::TABLE, array( 'status' => 'trashed', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $id ), array( '%s', '%s' ), array( '%d' ) );
		return true;
	}

	/** Restore a trashed form back to active. */
	public static function restore( int $id ): bool
	{
		global $wpdb;
		if ( ! self::get( $id ) ) {
			return false;
		}
		$wpdb->update( $wpdb->prefix . self::TABLE, array( 'status' => 'active', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $id ), array( '%s', '%s' ), array( '%d' ) );
		return true;
	}

	/** List trashed forms, optionally by product type. */
	public static function list_trashed( string $type = '' ): array
	{
		global $wpdb;
		$sql = 'SELECT * FROM ' . $wpdb->prefix . self::TABLE . ' WHERE status = %s';
		$args = array( 'trashed' );
		if ( $type !== '' ) {
			$sql .= ' AND form_type = %s';
			$args[] = $type;
		}
		$sql .= ' ORDER BY updated_at DESC';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/** Duplicate a form: new row with "(copia)", unique slug, status draft. */
	public static function duplicate( int $id ): int
	{
		global $wpdb;
		$f = self::get( $id );
		if ( ! $f ) {
			return 0;
		}
		$name = $f['name'] . ' (copia)';
		$base = sanitize_title( $name );
		$slug = $base;
		$n = 2;
		while ( self::slug_exists( $slug ) ) {
			$slug = $base . '-' . $n;
			$n++;
		}
		$data = array(
			'name'         => $name,
			'slug'         => $slug,
			'form_type'    => $f['form_type'],
			'form_context' => $f['form_context'],
			'config_json'  => $f['config_json'],
			'status'       => 'draft',
			'created_at'   => current_time( 'mysql' ),
			'updated_at'   => current_time( 'mysql' ),
		);
		$wpdb->insert( $wpdb->prefix . self::TABLE, $data, array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
		return (int) $wpdb->insert_id;
	}

	private static function slug_exists( string $slug ): bool
	{
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . $wpdb->prefix . self::TABLE . ' WHERE slug = %s LIMIT 1', $slug ) );
	}

	/**
	 * Adopt legacy (v1) forms/surveys into the v2 table as native entries.
	 * Preserves the legacy id so historical entries (estandar_form_entries) and
	 * survey responses (estandar_survey_responses) keep linking by form_id.
	 * Idempotent by slug. Never touches the legacy table.
	 *
	 * Compat: mantiene el comportamiento original (migra TODOS los tipos). La
	 * migración controlada del micro-sprint (solo Forms) vive en migrate_legacy().
	 */
	public static function adopt_legacy( bool $dry_run = false ): array
	{
		return self::migrate_legacy( $dry_run, 0, false );
	}

	/** Marcador interno de procedencia para migración controlada. */
	const MIG_MARKER = '_migrated_legacy';

	/**
	 * LEAD_PHOTO_V1: guarda un archivo subido en un form LEAD (campos type=file)
	 * bajo uploads/estandar-capture/survey/ — misma validación y directorio que el
	 * survey (SurveyHandler::handle_upload), pero síncrono en el submit (sin AJAX).
	 * @return array{url:string,error:string}
	 */
	public static function store_uploaded_file( array $file ): array
	{
		if ( (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK ) {
			return array( 'url' => '', 'error' => 'Error al subir el archivo.' );
		}
		$tmp_name = (string) ( $file['tmp_name'] ?? '' );
		if ( $tmp_name === '' || ! file_exists( $tmp_name ) ) {
			return array( 'url' => '', 'error' => 'Archivo temporal no disponible.' );
		}
		$max_size = (int) apply_filters( 'estandar_forms_upload_max_size', 5 * 1024 * 1024, $file );
		if ( ! empty( $file['size'] ) && (int) $file['size'] > $max_size ) {
			return array( 'url' => '', 'error' => 'El archivo supera el tamaño máximo (5 MB).' );
		}
		$allowed_mimes = array(
			'jpg|jpeg' => 'image/jpeg',
			'png'      => 'image/png',
			'webp'     => 'image/webp',
			'pdf'      => 'application/pdf',
		);
		if ( ! function_exists( 'wp_check_filetype_and_ext' ) ) {
			return array( 'url' => '', 'error' => 'Servicio de subida no disponible.' );
		}
		$original_name = sanitize_file_name( (string) ( $file['name'] ?? 'foto.jpg' ) );
		$filetype      = wp_check_filetype_and_ext( $tmp_name, $original_name, $allowed_mimes );
		if ( empty( $filetype['ext'] ) || empty( $filetype['type'] ) ) {
			return array( 'url' => '', 'error' => 'Solo se aceptan imágenes (JPG, PNG o WebP) o PDF.' );
		}
		$real_mime = '';
		if ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			if ( $finfo ) {
				$real_mime = (string) finfo_file( $finfo, $tmp_name );
				finfo_close( $finfo );
			}
		}
		if ( $real_mime !== '' && ! in_array( $real_mime, array_values( $allowed_mimes ), true ) ) {
			return array( 'url' => '', 'error' => 'El archivo no es una imagen válida.' );
		}
		$is_pdf = ( $real_mime === 'application/pdf' ) || (bool) preg_match( '/\.pdf$/i', $original_name );
		if ( ! $is_pdf && ! is_array( @getimagesize( $tmp_name ) ) ) {
			return array( 'url' => '', 'error' => 'La imagen no se pudo leer.' );
		}
		$upload_dir = wp_upload_dir();
		if ( empty( $upload_dir['basedir'] ) || empty( $upload_dir['baseurl'] ) ) {
			return array( 'url' => '', 'error' => 'Directorio de subidas no disponible.' );
		}
		$target_dir = trailingslashit( $upload_dir['basedir'] ) . 'estandar-capture/survey/';
		if ( ! wp_mkdir_p( $target_dir ) || ! is_writable( $target_dir ) ) {
			return array( 'url' => '', 'error' => 'No se pudo crear el directorio de subidas.' );
		}
		$final_name  = wp_unique_filename( $target_dir, $original_name );
		$destination = $target_dir . $final_name;
		$moved       = is_uploaded_file( $tmp_name ) ? move_uploaded_file( $tmp_name, $destination ) : false;
		if ( ! $moved ) {
			return array( 'url' => '', 'error' => 'No se pudo guardar la imagen.' );
		}
		@chmod( $destination, 0644 );
		if ( class_exists( 'Estandar\\Capture\\ImageOptimizer' ) ) {
			$opt = ImageOptimizer::optimize_file( $destination, $final_name, (string) $filetype['type'] );
			$destination = (string) $opt['path'];
			$final_name  = (string) $opt['name'];
		}
		return array(
			'url'   => trailingslashit( $upload_dir['baseurl'] ) . 'estandar-capture/survey/' . $final_name,
			'error' => '',
		);
	}

	/**
	 * Mapa form_type legacy → tipo v2. F2: surveys migrables. F3: incidentes.
	 */
	public static function legacy_form_type_map( string $legacy_type ): string
	{
		$map = array(
			'general'      => 'lead',
			'lead_capture' => 'lead',
			'lead'         => 'lead',
			'survey'       => 'survey',
			'incident'     => 'incident',
			'postventa'    => 'incident',
		);
		return $map[ strtolower( trim( (string) $legacy_type ) ) ] ?? '';
	}

	/** ¿Ya existe un formulario nativo con este id legacy (migrado/adoptado)? */
	public static function is_migrated( int $legacy_id ): bool
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $legacy_id <= 0 ) {
			return false;
		}
		return (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT id FROM ' . $wpdb->prefix . self::TABLE . ' WHERE id = %d LIMIT 1', $legacy_id
		) );
	}

	/** Legacy row → payload nativo v2 (puro). Incluye marcador de procedencia. */
	public static function legacy_to_native( array $lf, string $type ): array
	{
		$slug = sanitize_title( (string) ( $lf['slug'] ?? '' ) );
		if ( $slug === '' ) {
			$slug = sanitize_title( (string) $lf['name'] );
		}
		$config = array();
		foreach ( array(
			'fields_json', 'survey_config', 'incidents_config', 'actions_json',
			'notify_emails', 'webhook_url', 'success_message', 'submit_label',
			'success_redirect', 'require_recaptcha',
			'after_submit_text', 'mail_subject', 'mail_body', 'mail_sender_name',
			'mail_from_email', 'mail_reply_to_field', 'send_email', 'send_webhook', 'webhook_payload_mode',
			'mail_contract',
			'form_country', 'store_entries', 'visual_theme', 'context_schema',
			'experience_config_json', 'request_type',
			'form_project', 'form_project_area', 'form_funnel_mode',
		) as $k ) {
			if ( isset( $lf[ $k ] ) && $lf[ $k ] !== '' && $lf[ $k ] !== 'null' ) {
				$config[ $k ] = $lf[ $k ];
			}
		}
		$config[ self::MIG_MARKER ] = array(
			'id'          => (int) $lf['id'],
			'legacy_type' => (string) ( $lf['form_type'] ?? '' ),
			'migrated_at' => gmdate( 'Y-m-d H:i:s' ),
		);
		return array(
			'id'           => (int) $lf['id'],
			'name'         => sanitize_text_field( (string) $lf['name'] ),
			'slug'         => $slug,
			'form_type'    => $type !== '' ? $type : 'lead',
			'form_context' => sanitize_key( (string) ( $lf['form_context'] ?? 'generic' ) ) ?: 'generic',
			'config_json'  => wp_json_encode( $config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'status'       => 'active',
			'created_at'   => (string) ( $lf['created_at'] ?? current_time( 'mysql' ) ),
			'updated_at'   => (string) ( $lf['created_at'] ?? current_time( 'mysql' ) ),
		);
	}

	/**
	 * Migración controlada de formularios legacy → nativos v2 (Forms).
	 *  - dry_run por defecto (0 escrituras); --commit aplica.
	 *  - form_id > 0 selecciona un formulario; 0 migra todos los elegibles.
	 *  - only_forms=true (default) filtra solo Forms (general/lead_capture).
	 *  - Idempotente por id legacy; preserva el id (entries históricas siguen
	 *    linkeando por form_id). Nunca toca la tabla legacy.
	 *
	 * @return array { migrated, skipped, existing, not_forms, total, dry_run, forms }
	 */
	public static function migrate_legacy( bool $dry_run = true, int $form_id = 0, bool $only_forms = true ): array
	{
		global $wpdb;
		$legacy = \Estandar\Core\Admin::legacy_rows( 'estandar_forms', 'ORDER BY id ASC' );
		$stats  = array(
			'migrated'  => 0,
			'skipped'   => 0,
			'existing'  => 0,
			'not_forms' => 0,
			'total'     => count( $legacy ),
			'dry_run'   => $dry_run,
			'forms'     => array(),
		);
		foreach ( $legacy as $lf ) {
			$lfid = (int) $lf['id'];
			if ( $form_id > 0 && $lfid !== $form_id ) {
				continue;
			}
			$type = self::legacy_form_type_map( (string) ( $lf['form_type'] ?? '' ) );
			if ( ! $only_forms ) {
				// Compat adopt_legacy: conserva tipos v2 válidos (survey, etc.).
				$type = (string) ( $lf['form_type'] ?? 'lead' );
				if ( ! isset( self::types()[ $type ] ) ) {
					$type = 'lead';
				}
			}
			if ( $only_forms && $type === '' ) {
				$stats['not_forms']++;
				$stats['skipped']++;
				continue;
			}
			if ( self::is_migrated( $lfid ) ) {
				$stats['existing']++;
				continue;
			}
			$native = self::legacy_to_native( $lf, $type );
			$stats['forms'][] = array(
				'id'     => $lfid,
				'name'   => $native['name'],
				'type'   => $native['form_type'],
				'parity' => self::parity_diff( $lf, $native ),
			);
			if ( ! $dry_run && isset( $wpdb ) ) {
				$wpdb->insert( $wpdb->prefix . self::TABLE, $native, array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
			}
			$stats['migrated']++;
		}
		if ( ! $dry_run && isset( $wpdb ) && $stats['migrated'] > 0 ) {
			// Evitar colisión de IDs: los formularios nativos nuevos deben usar
			// ids > max(id legacy) para no pisar entradas históricas que linkean
			// por form_id (= id legacy).
			$max_legacy = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id),0) FROM ' . $wpdb->prefix . 'estandar_forms' );
			$wpdb->query( 'ALTER TABLE ' . $wpdb->prefix . self::TABLE . ' AUTO_INCREMENT = ' . ( $max_legacy + 1 ) );
		}
		return $stats;
	}

	/**
	 * Reporte de paridad legacy ↔ nativo (claves de config conservadas).
	 * Pure; no accede a DB.
	 */
	public static function parity_diff( array $lf, array $native ): array
	{
		$cfg = json_decode( (string) ( $native['config_json'] ?? '{}' ), true );
		$cfg = is_array( $cfg ) ? $cfg : array();
		unset( $cfg[ self::MIG_MARKER ] );
		$missing = array();
		$mapped  = array();
		foreach ( $cfg as $k => $v ) {
			if ( array_key_exists( $k, $lf ) ) {
				if ( (string) $lf[ $k ] === (string) $v ) {
					$mapped[ $k ] = true;
				} else {
					$missing[ $k ] = 'distinto (legacy=' . substr( (string) $lf[ $k ], 0, 40 ) . ' vs native=' . substr( (string) $v, 0, 40 ) . ')';
				}
			}
		}
		return array(
			'parity'  => empty( $missing ),
			'mapped'  => array_keys( $mapped ),
			'diffs'   => $missing,
		);
	}

	/**
	 * Localiza usos de un formulario legacy en el frontend: shortcodes
	 * `[estandar_form id/slug=…]` en posts (contenido) y postmeta. Read-only.
	 */
	public static function usages( int $legacy_id ): array
	{
		global $wpdb;
		$uses = array();
		if ( ! isset( $wpdb ) || $legacy_id <= 0 ) {
			return $uses;
		}
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT slug, name FROM ' . $wpdb->prefix . 'estandar_forms WHERE id = %d LIMIT 1', $legacy_id
		), ARRAY_A );
		$needles = array( 'id="' . $legacy_id . '"', "id='" . $legacy_id . "'" );
		if ( $row && ! empty( $row['slug'] ) ) {
			$needles[] = 'slug="' . $row['slug'] . '"';
			$needles[] = "slug='" . $row['slug'] . "'";
		}
		$posts = $wpdb->get_results(
			"SELECT ID, post_title, post_type, post_status FROM {$wpdb->prefix}posts WHERE post_content LIKE '%[estandar_form%'",
			ARRAY_A
		);
		foreach ( (array) $posts as $p ) {
			$content = $wpdb->get_var( $wpdb->prepare(
				'SELECT post_content FROM ' . $wpdb->prefix . 'posts WHERE ID = %d', (int) $p['ID']
			) );
			foreach ( $needles as $n ) {
				if ( $content !== null && strpos( (string) $content, $n ) !== false ) {
					$uses[] = array(
						'id'    => (int) $p['ID'],
						'title' => (string) $p['post_title'],
						'type'  => (string) $p['post_type'],
						'status'=> (string) $p['post_status'],
					);
					break;
				}
			}
		}
		return $uses;
	}

	/**
	 * ¿Dónde está colocado este form en el sitio? Escanea el contenido
	 * publicado (páginas/entradas de cualquier post type con contenido) en
	 * busca de shortcodes que referencien el form por su id o slug:
	 *   [estandar_form_v2 id/slug=…], [estandar_form id/slug=…] (legacy→nativo),
	 *   [estandar_form_gate form=…], [estandar_camara_registros form=…],
	 *   [estandar_capture_landing form=…]
	 * Read-only. Devuelve un registro por página (con su URL y el/los shortcode/s).
	 */
	public static function placements( array $form ): array
	{
		global $wpdb;
		$out = array();
		if ( ! isset( $wpdb ) ) {
			return $out;
		}
		$form_id   = (int) ( $form['id'] ?? 0 );
		$form_slug = (string) ( $form['slug'] ?? '' );
		if ( $form_id <= 0 ) {
			return $out;
		}
		$tags = array( 'estandar_form_v2', 'estandar_form', 'estandar_form_gate', 'estandar_camara_registros', 'estandar_capture_landing' );
		$posts = $wpdb->get_results(
			"SELECT ID, post_title, post_type, post_status, post_content
			 FROM {$wpdb->prefix}posts
			 WHERE post_status IN ('publish','private')
			   AND post_type NOT IN ('revision','attachment','nav_menu_item','custom_css','customize_changeset','wp_block','oembed_cache','user_request')
			   AND post_content LIKE '%[estandar_%'
			 ORDER BY post_type ASC, post_title ASC",
			ARRAY_A
		);
		foreach ( (array) $posts as $p ) {
			$content = (string) $p['post_content'];
			$matched = array();
			foreach ( $tags as $tag ) {
				if ( ! preg_match_all( '#\[' . preg_quote( $tag, '#' ) . '(\s[^\]]*)?\]#', $content, $m ) ) {
					continue;
				}
				foreach ( (array) $m[1] as $attrs ) {
					$ref = false;
					if ( preg_match( '#\b(id|form)\s*=\s*([\'"])?' . preg_quote( (string) $form_id, '#' ) . '\2#', $attrs ) ) {
						$ref = true;
					} elseif ( $form_slug !== '' && preg_match( '#\bslug\s*=\s*([\'"])' . preg_quote( $form_slug, '#' ) . '\1#', $attrs ) ) {
						$ref = true;
					}
					if ( $ref ) {
						$attrs = trim( (string) $attrs );
						$matched[] = '[' . $tag . ( $attrs !== '' ? ' ' . $attrs : '' ) . ']';
					}
				}
			}
			$matched = array_values( array_unique( $matched ) );
			if ( $matched ) {
				$out[] = array(
					'id'      => (int) $p['ID'],
					'title'   => (string) $p['post_title'],
					'type'    => (string) $p['post_type'],
					'status'  => (string) $p['post_status'],
					'url'     => function_exists( 'get_permalink' ) ? (string) get_permalink( (int) $p['ID'] ) : '',
					'matched' => $matched,
				);
			}
		}
		return $out;
	}

	/**
	 * Submissions nativas v2 de un formulario (registro operativo).
	 * Filtro estricto por source='form' y source_form_id = id del form.
	 * Read-only sobre estandar_capture_submissions.
	 */
	public static function submissions( int $form_id, int $limit = 100, int $offset = 0 ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $form_id <= 0 ) {
			return array();
		}
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}" . Ingestion::TABLE
			. " WHERE source = 'form' AND source_form_id = %s ORDER BY id DESC LIMIT %d OFFSET %d",
			(string) $form_id, $limit, $offset
		), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * GATE_DETECT_V1 (2026-09-21): páginas que protegen ESTE form con el
	 * shortcode `[estandar_form_gate form="ID"]` (o `id="ID"`). Sirve para que
	 * el editor muestre la protección REAL aunque el flag por-form no esté
	 * activo (la protección por shortcode vive en el contenido, no en el form).
	 *
	 * @return array<int, array{id:int,title:string}>
	 */
	public static function form_gate_pages( int $form_id ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $form_id <= 0 ) {
			return array();
		}
		$candidates = $wpdb->get_results(
			"SELECT ID, post_title, post_content FROM {$wpdb->posts}"
			. " WHERE post_status IN ('publish','draft','private','pending')"
			. " AND post_type NOT IN ('revision','nav_menu_item','attachment')"
			. " AND post_content LIKE '%estandar_form_gate%' LIMIT 200",
			ARRAY_A
		);
		$re = '#\[estandar_form_gate\b[^\]]*\b(?:form|id)\s*=\s*[\'"]?' . $form_id . '[\'"]?(?:\s|\])#';
		$out = array();
		foreach ( (array) $candidates as $row ) {
			if ( preg_match( $re, (string) ( $row['post_content'] ?? '' ) ) ) {
				$out[] = array( 'id' => (int) $row['ID'], 'title' => (string) $row['post_title'] );
			}
		}
		return $out;
	}

	/** Nº de submissions nativas de un form (source='form' → source_form_id). */
	public static function entries_count( int $form_id ): int	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $form_id <= 0 ) {
			return 0;
		}
		return (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . $wpdb->prefix . Ingestion::TABLE
			. " WHERE source = 'form' AND source_form_id = %s",
			(string) $form_id
		) );
	}

	/** Impacto de eliminar un form: submissions + páginas donde está publicado. */
	public static function impact( int $id ): array
	{
		$f = self::get( $id );
		if ( ! $f ) {
			return array( 'ok' => false, 'error' => 'Formulario no encontrado.' );
		}
		$pl = self::placements( $f );
		return array(
			'ok'           => true,
			'form'         => $f,
			'submissions'  => self::entries_count( $id ),
			'pages'        => count( $pl ),
			'placements'   => $pl,
		);
	}

	/**
	 * Candidatos a recibir la migración de un form: mismo tipo y activos,
	 * ordenados por compatibilidad (mismo contexto primero). Read-only.
	 */
	public static function consolidation_candidates( int $id ): array
	{
		$f = self::get( $id );
		if ( ! $f ) {
			return array();
		}
		$out = array();
		foreach ( (array) self::list() as $row ) {
			if ( (int) $row['id'] === (int) $id ) {
				continue;
			}
			if ( (string) $row['form_type'] !== (string) $f['form_type'] ) {
				continue;
			}
			if ( (string) $row['status'] !== 'active' ) {
				continue;
			}
			$score = 100;
			$badge = 'Compatible';
			if ( (string) $row['form_context'] === (string) $f['form_context'] ) {
				$score += 10;
				$badge = 'Óptimo (mismo contexto)';
			}
			$out[] = array(
				'form'         => $row,
				'score'        => $score,
				'badge'        => $badge,
				'submissions'  => self::entries_count( (int) $row['id'] ),
				'pages'        => count( self::placements( $row ) ),
			);
		}
		usort( $out, static function ( $a, $b ) {
			return $b['score'] <=> $a['score'];
		} );
		return $out;
	}

	/** Backup portable de un form: config + submissions, en uploads/…/backups. */
	public static function backup_payload( int $id ): array
	{
		$f = self::get( $id );
		if ( ! $f ) {
			return array( 'ok' => false, 'error' => 'Formulario no encontrado.' );
		}
		$subs = self::submissions( $id, 100000 );
		$dirs = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : array( 'basedir' => '', 'baseurl' => '' );
		$dir  = trailingslashit( (string) $dirs['basedir'] ) . 'estandar-suite-backups';
		if ( $dirs['basedir'] === '' || ! function_exists( 'wp_mkdir_p' ) ) {
			return array( 'ok' => false, 'error' => 'Sin directorio de uploads disponible.' );
		}
		if ( ! wp_mkdir_p( $dir ) ) {
			return array( 'ok' => false, 'error' => 'No se pudo crear el directorio de backups.' );
		}
		$stamp = gmdate( 'Ymd-His' );
		$name  = 'form-' . (int) $id . '-' . sanitize_title( (string) $f['slug'] ) . '-' . $stamp . '.json';
		$file  = trailingslashit( $dir ) . $name;
		$data  = array(
			'type'        => 'estandar_suite_form_backup',
			'generated_at'=> current_time( 'mysql' ),
			'form_id'     => (int) $id,
			'form'        => $f,
			'submissions' => $subs,
		);
		$written = file_put_contents( $file, wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		if ( $written === false ) {
			return array( 'ok' => false, 'error' => 'No se pudo escribir el backup.' );
		}
		return array(
			'ok'   => true,
			'file' => $file,
			'name' => $name,
			'url'  => trailingslashit( (string) $dirs['baseurl'] ) . 'estandar-suite-backups/' . $name,
		);
	}

	/**
	 * Re-escribe en el contenido publicado/draft los shortcodes que referencian
	 * al form origen para que apunten al destino. Devuelve las páginas tocadas.
	 * Misma semántica de detección que placements(): [estandar_form_v2],
	 * [estandar_form] (legacy→nativo), gate/cámara/landing por id.
	 */
	public static function rewrite_form_shortcodes( array $from, array $to, bool $apply = true ): array
	{
		global $wpdb;
		$updated = array();
		if ( ! isset( $wpdb ) ) {
			return $updated;
		}
		$rows = $wpdb->get_results(
			"SELECT ID, post_title, post_type, post_status, post_content
			 FROM {$wpdb->prefix}posts
			 WHERE post_type NOT IN ('revision','attachment','nav_menu_item','custom_css','customize_changeset','wp_block','oembed_cache','user_request')
			   AND post_content LIKE '%[estandar_%'",
			ARRAY_A
		);
		$from_id   = (int) ( $from['id'] ?? 0 );
		$from_slug = (string) ( $from['slug'] ?? '' );
		$to_id     = (int) ( $to['id'] ?? 0 );
		$to_slug   = (string) ( $to['slug'] ?? '' );
		$id_re    = '#(\[(?:estandar_form_v2|estandar_form|estandar_form_gate|estandar_camara_registros|estandar_capture_landing)\b[^\]]*?)\b(id|form)\s*=\s*([\'"])' . preg_quote( (string) $from_id, '#' ) . '\3([^\]]*\])#';
		$slug_re  = '#(\[(?:estandar_form_v2|estandar_form)\b[^\]]*?)\bslug\s*=\s*([\'"])' . preg_quote( $from_slug, '#' ) . '\2([^\]]*\])#';
		foreach ( (array) $rows as $p ) {
			$content = (string) $p['post_content'];
			$content = preg_replace_callback( $id_re, static function ( $m ) use ( $to_id ) {
				return $m[1] . $m[2] . '=' . $m[3] . (string) $to_id . $m[3] . $m[4];
			}, $content );
			if ( $from_slug !== '' ) {
				$content = preg_replace_callback( $slug_re, static function ( $m ) use ( $to_slug ) {
					return $m[1] . 'slug=' . $m[2] . $to_slug . $m[2] . $m[3];
				}, $content );
			}
			// Reemplazo literal reforzado: cubre comillas simples/dobles y
			// entidades (&quot;) por si el contenido vino de un builder.
			$literals = array();
			foreach ( array( '"', "'", '&quot;' ) as $q ) {
				if ( $from_slug !== '' ) {
					$literals[] = array(
						'[estandar_form slug=' . $q . $from_slug . $q . ']',
						'[estandar_form slug=' . $q . $to_slug . $q . ']',
					);
					$literals[] = array(
						'[estandar_form_v2 slug=' . $q . $from_slug . $q . ']',
						'[estandar_form_v2 slug=' . $q . $to_slug . $q . ']',
					);
				}
				if ( $to_id > 0 && $from_id > 0 ) {
					$literals[] = array(
						'[estandar_form_v2 id=' . $q . (string) $from_id . $q . ']',
						'[estandar_form_v2 id=' . $q . (string) $to_id . $q . ']',
					);
					$literals[] = array(
						'[estandar_form id=' . $q . (string) $from_id . $q . ']',
						'[estandar_form id=' . $q . (string) $to_id . $q . ']',
					);
				}
			}
			foreach ( $literals as $pair ) {
				$content = str_replace( $pair[0], $pair[1], $content );
			}
			if ( $content === (string) $p['post_content'] ) {
				continue;
			}
			if ( $apply ) {
				$wpdb->update(
					$wpdb->prefix . 'posts',
					array( 'post_content' => $content ),
					array( 'ID' => (int) $p['ID'] ),
					array( '%s' ),
					array( '%d' )
				);
			}
			$updated[] = array(
				'id'     => (int) $p['ID'],
				'title'  => (string) $p['post_title'],
				'type'   => (string) $p['post_type'],
				'status' => (string) $p['post_status'],
				'url'    => function_exists( 'get_permalink' ) ? (string) get_permalink( (int) $p['ID'] ) : '',
			);
		}
		return $updated;
	}

	/** Elimina las submissions nativas de un form (borrado form + entries). */
	public static function purge_submissions( int $form_id ): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $form_id <= 0 ) {
			return 0;
		}
		return (int) $wpdb->query( $wpdb->prepare(
			'DELETE FROM ' . $wpdb->prefix . Ingestion::TABLE
			. " WHERE source = 'form' AND source_form_id = %s",
			(string) $form_id
		) );
	}

	/**
	 * CONSOLIDA el form origen en el destino: mueve submissions, re-escribe los
	 * shortcodes de páginas y (en apply) deja el origen en papelera.
	 * Con dry_run=true no escribe nada y devuelve el reporte esperado.
	 */
	public static function consolidate( int $from_id, int $to_id, bool $dry_run = true ): array
	{
		$from = self::get( $from_id );
		$to   = self::get( $to_id );
		if ( ! $from || ! $to ) {
			return array( 'ok' => false, 'error' => 'Formulario origen o destino no encontrado.' );
		}
		if ( (int) $from_id === (int) $to_id ) {
			return array( 'ok' => false, 'error' => 'Origen y destino son el mismo formulario.' );
		}
		if ( (string) $from['form_type'] !== (string) $to['form_type'] ) {
			return array( 'ok' => false, 'error' => 'Los forms deben ser del mismo tipo (' . $from['form_type'] . ' vs ' . $to['form_type'] . ').' );
		}
		if ( (string) $to['status'] !== 'active' ) {
			return array( 'ok' => false, 'error' => 'El formulario destino debe estar activo.' );
		}

		$report = array(
			'ok'                 => true,
			'dry'                => $dry_run,
			'from'               => array( 'id' => (int) $from_id, 'name' => $from['name'], 'slug' => $from['slug'] ),
			'to'                 => array( 'id' => (int) $to_id, 'name' => $to['name'], 'slug' => $to['slug'] ),
			'submissions_moved'  => self::entries_count( $from_id ),
			'pages_updated'      => array(),
			'backup'             => null,
		);

		// Dry-run: nada se escribe; solo se informa el impacto esperado.
		if ( $dry_run ) {
			$report['pages_updated'] = self::rewrite_form_shortcodes( $from, $to, false );
			return $report;
		}

		// 1) Backup completo ANTES de tocar nada (captura submissions + config).
		$b = self::backup_payload( $from_id );
		if ( empty( $b['ok'] ) ) {
			return array( 'ok' => false, 'error' => $b['error'] ?? 'No se pudo generar el backup; se aborta la consolidación.' );
		}
		$report['backup'] = $b;

		// 2) Re-escribir shortcodes en páginas (fuente → destino).
		$report['pages_updated'] = self::rewrite_form_shortcodes( $from, $to, true );

		// 3) Re-apuntar submissions nativas (fuente → destino).
		global $wpdb;
		if ( isset( $wpdb ) ) {
			$wpdb->query( $wpdb->prepare(
				'UPDATE ' . $wpdb->prefix . Ingestion::TABLE
				. " SET source_form_id = %s WHERE source = 'form' AND source_form_id = %s",
				(string) $to_id, (string) $from_id
			) );
		}

		// 4) Origen a la papelera (soft delete; no toca sus datos ya migrados).
		self::trash( $from_id );
		return $report;
	}

	/**
	 * Entradas de un formulario por `source_form_id` con filtros/orden
	 * (ENTRIES_ADMIN_V1): estado (active/trash), búsqueda por contacto y
	 * orden por columna.
	 */
	public static function submissions_for_form( int $form_id, int $limit = 100, int $offset = 0, string $status = 'active', string $search = '', string $orderby = 'id', string $order = 'DESC' ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $form_id <= 0 ) {
			return array();
		}
		$table  = $wpdb->prefix . Ingestion::TABLE;
		$where  = $wpdb->prepare( 'source_form_id = %s', (string) $form_id );
		if ( $status !== '' ) {
			$where .= $wpdb->prepare( ' AND status = %s', $status );
		}
		if ( $search !== '' ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$where .= $wpdb->prepare( ' AND (name LIKE %s OR email_normalized LIKE %s OR phone_normalized LIKE %s)', $like, $like, $like );
		}
		$allowed = array( 'id', 'created_at', 'name', 'email_normalized', 'status' );
		$orderby = in_array( $orderby, $allowed, true ) ? $orderby : 'id';
		$order   = strtoupper( $order ) === 'ASC' ? 'ASC' : 'DESC';
		$rows = $wpdb->get_results(
			"SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} {$order} LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset,
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/** Conteo de entradas (para paginación/filtros). */
	public static function submissions_count( int $form_id, string $status = 'active', string $search = '' ): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $form_id <= 0 ) {
			return 0;
		}
		$table = $wpdb->prefix . Ingestion::TABLE;
		$where = $wpdb->prepare( 'source_form_id = %s', (string) $form_id );
		if ( $status !== '' ) {
			$where .= $wpdb->prepare( ' AND status = %s', $status );
		}
		if ( $search !== '' ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$where .= $wpdb->prepare( ' AND (name LIKE %s OR email_normalized LIKE %s OR phone_normalized LIKE %s)', $like, $like, $like );
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
	}

	/** Soft delete / restore de una entrada. */
	public static function set_submission_status( int $id, string $status ): bool
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $id <= 0 ) {
			return false;
		}
		$status = in_array( $status, array( 'active', 'trash' ), true ) ? $status : 'active';
		return (bool) $wpdb->update(
			$wpdb->prefix . Ingestion::TABLE,
			array( 'status' => $status ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/** Eliminación definitiva de una entrada. */
	public static function delete_submission( int $id ): bool
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $id <= 0 ) {
			return false;
		}
		return (bool) $wpdb->delete( $wpdb->prefix . Ingestion::TABLE, array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Lee una fila de estandar_capture_submissions (OPERATOR_V1 / EDIT_V1).
	 * Devuelve array o null.
	 */
	public static function get_submission( int $id ): ?array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $id <= 0 ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}" . Ingestion::TABLE . " WHERE id = %d LIMIT 1",
			$id
		), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * OPERATOR_V1 / EDIT_V1: actualiza los campos (fields_json) y el operador de
	 * una submission existente (edición manual desde el visor). No toca identidad
	 * ni atribución. Devuelve true si hubo cambio.
	 */
	public static function update_submission_fields( int $id, array $fields, string $operator_name = '', string $operator_email = '' ): bool
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $id <= 0 ) {
			return false;
		}
		$update = array(
			'fields_json' => wp_json_encode( $fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
		);
		$formats = array( '%s' );
		if ( $operator_name !== '' ) {
			$update['operator_name'] = sanitize_text_field( $operator_name );
			$formats[] = '%s';
		}
		if ( $operator_email !== '' ) {
			$update['operator_email'] = sanitize_email( $operator_email );
			$formats[] = '%s';
		}
		return (bool) $wpdb->update(
			$wpdb->prefix . Ingestion::TABLE,
			$update,
			array( 'id' => $id ),
			$formats,
			array( '%d' )
		);
	}

	/**
	 * Entradas de un formulario por `source_form_id` (relación canónica, cualquier
	 * source: form/survey/incident). Usado por el editor (subtab Entradas).
	 * (Definida más arriba con filtros/orden ENTRIES_ADMIN_V1.)
	 */

	/** Caché por request del chequeo de existencia de la tabla del pipeline. */
	private static $pipeline_table_exists = null;

	/**
	 * ¿Existe la tabla `estandar_flow_pipeline`? La tabla es propiedad de
	 * estandar-flow; capture la lee por SQL directo, de modo que si flow nunca
	 * se instaló no debe ejecutar la query (evita errores SQL registrados).
	 * Resultado cacheado en estático por request.
	 */
	private static function pipeline_table_exists(): bool
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return false;
		}
		if ( self::$pipeline_table_exists !== null ) {
			return self::$pipeline_table_exists;
		}
		$table = $wpdb->prefix . 'estandar_flow_pipeline';
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		self::$pipeline_table_exists = ( $found === $table );
		return self::$pipeline_table_exists;
	}

	/** Lead de pipeline (estandar_flow_pipeline) asociado a una capture v2. */
	public static function pipeline_for_capture( int $capture_id ): ?array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $capture_id <= 0 ) {
			return null;
		}
		if ( ! self::pipeline_table_exists() ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, stage, entity_uuid, updated_at FROM {$wpdb->prefix}estandar_flow_pipeline
			 WHERE entity_type = 'capture' AND entity_id = %d LIMIT 1", $capture_id
		), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Plantilla corporativa por defecto de la NOTIFICACIÓN interna.
	 * HTML limpio (evita caídas a spam), logo de la instalación y footer
	 * discreto de Estandar con CTA. Variables: {{form_name}}, {{name}},
	 * {{email}}, {{phone}}, {{message}}, {{fields}}, {{date}}, {{logo}},
	 * {{brand}}, {{footer_estandar}}.
	 */
	public static function notification_body_default(): string
	{
		return '<div style="max-width:560px;margin:0 auto;font-family:Helvetica,Arial,sans-serif;color:#1d2327;font-size:14px;line-height:1.5;">'
			. '{{logo}}'
			. '<h2 style="font-size:18px;margin:16px 0 6px;color:#1d2327;">Nuevo envío: {{form_name}}</h2>'
			. '<p style="margin:0 0 14px;color:#555;">Recibimos un nuevo mensaje desde el formulario del sitio.</p>'
			. '{{fields}}'
			. '<p style="color:#999;font-size:12px;margin-top:10px;">Enviado el {{date}}.</p>'
			. '{{footer_estandar}}'
			. '</div>';
	}

	/**
	 * NOTIFY_BODY_HEAL_V1: repara plantillas de correo corruptas EN TIEMPO DE
	 * RENDER. El editor (bloques/TinyMCE) rompe a veces el `<div style="…">`
	 * inicial y deja el atributo `style=` como texto visible
	 * (`<div>` + salto + `style="…"`). Como el dato puede volver a corromperse,
	 * el saneo se hace al enviar: se repara quirúrgicamente y, si no se puede,
	 * se cae a la plantilla por defecto. Nunca lanza. Idempotente.
	 */
	public static function repair_notify_body( string $body ): string
	{
		if ( trim( $body ) === '' ) {
			return self::notification_body_default();
		}
		$fixed = (string) preg_replace( '/<div>\s*style\s*=/i', '<div style=', $body );
		// Si tras reparar queda un `style=` suelto al inicio (texto visible), la
		// etiqueta se perdió más allá de reparación → plantilla por defecto.
		if ( preg_match( '/^(?:\s|\R)*style\s*=/i', $fixed ) ) {
			return self::notification_body_default();
		}
		return $fixed;
	}

	/** ¿el mail_body presenta la corrupción conocida? (para guard/migración). */
	public static function is_corrupt_notify_body( string $body ): bool
	{
		return (bool) preg_match( '/<div>\s*style\s*=/i', $body ) || (bool) preg_match( '/^(?:\s|\R)*style\s*=/i', $body );
	}

	/** Normaliza un teléfono para mostrar (formato chileno cuando aplica). */
	public static function phone_display( string $phone ): string
	{
		$digits = preg_replace( '/\D+/', '', $phone );
		if ( ! is_string( $digits ) || $digits === '' ) {
			return $phone;
		}
		if ( strlen( $digits ) === 9 && strpos( $digits, '9' ) === 0 ) {
			$digits = '56' . $digits;
		}
		if ( strlen( $digits ) === 11 && strpos( $digits, '56' ) === 0 ) {
			return '+56 ' . substr( $digits, 2, 1 ) . ' ' . substr( $digits, 3, 4 ) . ' ' . substr( $digits, 7 );
		}
		return $phone;
	}

	/** NOTIFY_BODY_MIGRATION_V1: ¿el mail_body es la plantilla default VIEJA
	 *  (tabla hardcodeada Nombre/Email/Teléfono/Mensaje + "Detalle completo" +
	 *  {{fields}})? Con NOTIFY_FIELDS_V1, {{fields}} ya incluye esos campos →
	 *  los repite (duplicación real vista en producción). Detectada por
	 *  marcadores (no toca cuerpos custom sin esos markers). PURE. */
	public static function is_legacy_notify_body( string $body ): bool
	{
		return strpos( $body, '<strong>Nombre</strong>' ) !== false
			&& strpos( $body, 'Detalle completo' ) !== false
			&& strpos( $body, '{{fields}}' ) !== false;
	}

	/** NOTIFY_BODY_MIGRATION_V1: reemplaza los mail_body legacy (default viejo)
	 *  por el nuevo default (una sola tabla {{fields}}). Idempotente; devuelve
	 *  cuántos forms se actualizaron. */
	public static function migrate_legacy_notify_bodies(): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return 0;
		}
		$table   = $wpdb->prefix . 'estandar_capture_forms';
		$rows    = $wpdb->get_results( "SELECT id, config_json FROM {$table}", ARRAY_A );
		$new     = self::notification_body_default();
		$updated = 0;
		foreach ( (array) $rows as $row ) {
			$config = json_decode( (string) ( $row['config_json'] ?? '{}' ), true );
			if ( ! is_array( $config ) ) {
				continue;
			}
			$body = (string) ( $config['mail_body'] ?? '' );
			if ( $body === '' || ! self::is_legacy_notify_body( $body ) ) {
				continue;
			}
			$config['mail_body'] = $new;
			$wpdb->update(
				$table,
				array( 'config_json' => wp_json_encode( $config, JSON_UNESCAPED_UNICODE ), 'updated_at' => current_time( 'mysql' ) ),
				array( 'id' => (int) $row['id'] )
			);
			$updated++;
		}
		return $updated;
	}

	/** NOTIFY_BODY_MIGRATION_V1: corre una sola vez (opción de guard). */
	public static function maybe_migrate_notify_bodies(): void
	{
		if ( get_option( 'estandar_suite_capture_notify_body_migrated_v1', '' ) ) {
			return;
		}
		$n = self::migrate_legacy_notify_bodies();
		update_option( 'estandar_suite_capture_notify_body_migrated_v1', (string) $n, false );
	}

	/**
	 * Plantilla corporativa por defecto de la AUTORESPUESTA al remitente.
	 * Variables: {{name}}, {{form_name}}, {{message}}, {{logo}}, {{footer_estandar}}.
	 */
	public static function autoresponse_body_default(): string
	{
		return '<div style="max-width:560px;margin:0 auto;font-family:Helvetica,Arial,sans-serif;color:#1d2327;font-size:14px;line-height:1.6;">'
			. '{{logo}}'
			. '<p style="margin:16px 0 0;">Hola <strong>{{name}}</strong>,</p>'
			. '<p>Gracias por escribirnos a través de {{form_name}}. Recibimos tu mensaje correctamente y un ejecutivo te contactará a la brevedad.</p>'
			. '<p style="padding:12px;border-left:3px solid #e2e4e7;background:#fafafa;color:#555;">{{message}}</p>'
			. '{{footer_estandar}}'
			. '</div>';
	}

	/**
	 * Identidad de correo por Form (EMAIL_IDENTITY_V1 + PROJECT_BRANDING_V1).
	 *
	 * Default = identidad compartida de la instalación (Capture → Leads → Email,
	 * via `Core\Mail::identity()`), con skin del proyecto (si $project_slug trae
	 * branding) y override por form: `mail_brand_color`, `mail_logo_url`,
	 * `mail_header_text`, `mail_footer_text` (vacío = default).
	 */
	public static function mail_identity( array $config, string $project_slug = '' ): array
	{
		if ( class_exists( 'Estandar\\Core\\Mail' ) ) {
			$identity = \Estandar\Core\Mail::identity( array(), $project_slug );
		} else {
			$identity = array(
				'site_name'    => '',
				'brand_color'  => '#000000',
				'logo_url'     => '',
				'header_text'  => '',
				'footer_text'  => '',
				'preheader'    => '',
				'sender_name'  => '',
				'sender_email' => '',
			);
		}
		foreach ( array( 'brand_color', 'logo_url', 'header_text', 'footer_text' ) as $k ) {
			$v = (string) ( $config[ 'mail_' . $k ] ?? '' );
			if ( $v !== '' ) { $identity[ $k ] = $v; }
		}
		return $identity;
	}

	/** Variables de branding para las plantillas (cabecera + footer compartidos). */
	public static function brand_vars( array $identity = array() ): array
	{
		if ( empty( $identity ) ) {
			$identity = self::mail_identity( array() );
		}
		if ( class_exists( 'Estandar\\Core\\Mail' ) ) {
			return array(
				'logo'            => \Estandar\Core\Mail::header_html( $identity ),
				'footer_estandar' => \Estandar\Core\Mail::footer_html( $identity ),
			);
		}
		// Fallback sin Core (tests/aislado): solo footer textual.
		$footer = 'Sitio impulsado por <a href="https://estandar.cl" style="color:#007cba;text-decoration:none;">Estándar.cl</a> — Soluciones empresariales <a href="https://estandar.cl" style="color:#007cba;text-decoration:none;">Conoce Estandar Studio →</a>';
		return array(
			'logo'            => '',
			'footer_estandar' => $footer,
		);
	}

	/** MAIL_TEMPLATES_V1 · Fase 2 (PURE): aplica la plantilla de correo elegida
	 *  por el form (config.mail_template), por el proyecto o el default global
	 *  (vía Core\Mail::resolve_template). El body del form se inyecta en el slot
	 *  {{content}} de la plantilla. Para no duplicar la marca, cuando hay
	 *  plantilla se eliminan del body los tokens {{logo}}/{{footer_estandar}}
	 *  (el header/footer ahora los pone la plantilla). Los tokens de identidad
	 *  de la plantilla ({{site_name}}, {{brand_color}}, {{footer_text}}) se
	 *  resuelven con la identidad recibida (salida lista para enviar).
	 *  SIN plantilla → devuelve el body intacto (retrocompatible, OFF). */
	public static function decorate_with_template( string $body, array $config, string $project_slug = '', array $identity = array() ): string
	{
		if ( ! class_exists( 'Estandar\\Core\\Mail' ) ) {
			return $body;
		}
		$template = \Estandar\Core\Mail::resolve_template( $config, $project_slug );
		if ( ! is_array( $template ) ) {
			return $body;
		}
		// Si el body ya es un documento completo (contrato GF <html>) → no tocar.
		if ( preg_match( '/<html/i', $body ) ) {
			return $body;
		}
		$content = str_replace( array( '{{logo}}', '{{footer_estandar}}' ), '', $body );
		$html    = \Estandar\Core\Mail::wrap_template( $template, $content );
		$site_name = (string) ( $identity['site_name'] ?? '' );
		if ( $site_name === '' && function_exists( 'get_bloginfo' ) ) {
			$site_name = (string) get_bloginfo( 'name' );
		}
		$brand_color = (string) ( $identity['brand_color'] ?? '' );
		$brand_color = $brand_color !== '' ? $brand_color : '#000000';
		$footer_text = (string) ( $identity['footer_text'] ?? '' );
		if ( $footer_text === '' ) {
			$footer_text = 'Sitio impulsado por <a href="https://estandar.cl" style="color:#007cba;text-decoration:none;">Estándar.cl</a> — Soluciones empresariales <a href="https://estandar.cl" style="color:#007cba;text-decoration:none;">Conoce Estandar Studio →</a>';
		}
		return strtr( $html, array(
			'{{site_name}}'   => esc_html( $site_name ),
			'{{brand_color}}' => esc_attr( $brand_color ),
			'{{footer_text}}' => $footer_text,
		) );
	}

	/**
	 * Modo de destino del lead del form (FUNNEL_V1).
	 *   ''       → heredado (comportamiento actual: notificación simple del form
	 *              + ingesta a es_leads y correo enriquecido del operador si aplica).
	 *   'funnel' → embudo del proyecto: SOLO el correo del operador enriquecido
	 *              (se suprime la notificación simple del form).
	 *   'simple' → SOLO correo simple del form (se registra en es_leads pero no
	 *              se envía el correo enriquecido del operador).
	 */
	public static function funnel_mode( array $config ): string
	{
		$mode = sanitize_key( (string) ( $config['form_funnel_mode'] ?? '' ) );
		return ( $mode === 'funnel' || $mode === 'simple' ) ? $mode : '';
	}

	/** Proyecto fijo del form (slug) para integración con embudo del proyecto. */
	public static function form_project( array $config ): string
	{
		return sanitize_title( (string) ( $config['form_project'] ?? '' ) );
	}

	/**
	 * NOTIFY_GUARANTEE_V1: ¿el form tiene un destinatario designado para el aviso
	 * del lead? Se cumple si hay (a) correos en `notify_emails`, (b) un campo de
	 * tipo `routing` (los correos los define la opción elegida) o (c) operadores
	 * activos con email en el proyecto del form. Un form sin ninguna de las tres
	 * NO puede guardarse (evita leads que nunca notifican).
	 */
	public static function form_has_designated_recipient( array $config ): bool
	{
		if ( trim( (string) ( $config['notify_emails'] ?? '' ) ) !== '' ) {
			return true;
		}
		foreach ( self::renderable_fields( $config ) as $f ) {
			if ( is_array( $f ) && strtolower( (string) ( $f['type'] ?? '' ) ) === 'routing' ) {
				return true;
			}
		}
		$slug = self::form_project( $config );
		if ( $slug !== '' ) {
			global $wpdb;
			if ( isset( $wpdb ) ) {
				$t = $wpdb->prefix . 'es_operators';
				if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t ) {
					$n = (int) $wpdb->get_var( $wpdb->prepare(
						"SELECT COUNT(*) FROM {$t} WHERE active = 1 AND email <> '' AND (project_slug = %s OR allowed_projects_json LIKE %s)",
						$slug,
						'%' . $wpdb->esc_like( $slug ) . '%'
					) );
					if ( $n > 0 ) {
						return true;
					}
				}
			}
		}
		return false;
	}

	/** Configuración de correo por Form (notificación + autorespuesta). */
	public static function mail_settings( array $config ): array
	{
		$ar = is_array( $config['autoresponse'] ?? null ) ? $config['autoresponse'] : array();
		// Remitente por defecto: identidad compartida de la Suite (Capture →
		// Leads → Email) → nombre del sitio + admin email. El From header vacío
		// caía en el default de wp_mail ("WordPress <wordpress@dominio>").
		$identity = array();
		if ( class_exists( 'Estandar\\Core\\Mail' ) ) {
			$identity = \Estandar\Core\Mail::identity();
		}
		$site_name = (string) ( $identity['site_name'] ?? '' );
		$site_email = (string) ( $identity['sender_email'] ?? '' );
		if ( $site_email === '' && function_exists( 'get_option' ) ) {
			$site_email = sanitize_email( (string) get_option( 'admin_email', '' ) );
		}
		$default_name  = (string) ( $identity['sender_name'] ?? $site_name );
		$notify_sender = (string) ( $config['mail_sender_name'] ?? '' );
		$notify_from   = sanitize_email( (string) ( $config['mail_from_email'] ?? '' ) );
		$ar_sender     = (string) ( $ar['sender'] ?? '' );
		$ar_from       = sanitize_email( (string) ( $ar['from_email'] ?? '' ) );
		// AUTORESPONSE_SUBJECT_V1: un subject VACÍO cae al default (antes el
		// `??` solo cubría clave ausente; "subject":"" rompía el envío con
		// "Asunto o HTML vacío").
		$ar_subject = trim( (string) ( $ar['subject'] ?? '' ) );
		return array(
			'notify' => array(
				// NOTIFY_ALWAYS_V1: el aviso de llegada del lead/form/mensaje es
				// OBLIGATORIO y NUNCA se apaga (antes dependía del switch
				// "send_email"; un form con el switch apagado perdía el aviso sin
				// registro alguno — caso rodrigovaldes form 1). Los destinatarios
				// los resuelve notify_submission (notify_emails / routing /
				// operadores del proyecto); sin destinatario devuelve
				// 'sin_destinatarios' (y el guardado de forms sin operador queda
				// bloqueado en Admin).
				'enabled'  => true,
				'to'       => (string) ( $config['notify_emails'] ?? '' ),
				'subject'  => (string) ( $config['mail_subject'] ?? 'Nuevo envío: {{form_name}}' ),
				// NOTIFY_BODY_HEAL_V1: sanea la plantilla al render (por si se
				// corrompió al editar; ver repair_notify_body).
				'body'     => self::repair_notify_body( (string) ( $config['mail_body'] ?? '' ) ),
				'sender'   => $notify_sender !== '' ? $notify_sender : $default_name,
				'from_email' => $notify_from !== '' ? $notify_from : $site_email,
				'reply_to' => (string) ( $config['mail_reply_to_field'] ?? '' ),
				// NOTIFY_ROUTING_V1: routing por valor de campo (ej. selector
				// "Empresa" → cada opción envía a un destinatario distinto).
				'route_field' => (string) ( $config['notify_route_field'] ?? '' ),
				'route_map'   => self::parse_route_map( (string) ( $config['notify_route_map_text'] ?? '' ) ),
				// NOTIFY_CC_ADMIN_V1: copia al administrador de la página,
				// activada POR DEFECTO (la clave ausente = activada).
				'cc_admin'    => ! isset( $config['notify_cc_admin'] ) || ! empty( $config['notify_cc_admin'] ),
				// NOTIFY_CC_ALWAYS_V1: copias SIEMPRE (independiente del
				// routing por campo): correos que reciben todo aunque el To
				// cambie según la selección del usuario.
				'cc_always'   => (string) ( $config['notify_cc_always'] ?? '' ),
			),
			'autoresponse' => array(
				// AUTORESPONSE_DEFAULT_V1: la confirmación al remitente está
				// ACTIVADA POR DEFECTO (la clave ausente = activada); solo un
				// "enabled":"0" explícito la desactiva. Los forms siempre
				// confirman el envío a quien escribió.
				'enabled'     => ! isset( $ar['enabled'] ) || ! empty( $ar['enabled'] ),
				'email_field' => (string) ( $ar['email_field'] ?? 'mail' ),
				'subject'     => $ar_subject !== '' ? $ar_subject : 'Gracias por contactarnos',
				'body'        => (string) ( $ar['body'] ?? '' ),
				'sender'      => $ar_sender !== '' ? $ar_sender : $default_name,
				'from_email'  => $ar_from !== '' ? $ar_from : $site_email,
			),
		);
	}

	/**
	 * Parsea el mapa de routing textual (una línea por regla):
	 *   valor=correo1,correo2
	 * Líneas vacías y comentarios (#) se ignoran. Matching sin distinción
	 * de mayúsculas/minúsculas sobre el valor normalizado.
	 */
	private static function parse_route_map( string $text ): array
	{
		$map = array();
		foreach ( preg_split( "/\r\n|\n|\r/", $text ) as $line ) {
			$line = trim( $line );
			if ( $line === '' || $line[0] === '#' ) {
				continue;
			}
			$parts = explode( '=', $line, 2 );
			if ( count( $parts ) !== 2 ) {
				continue;
			}
			$val    = self::route_key( $parts[0] );
			$emails = trim( $parts[1] );
			if ( $val !== '' && $emails !== '' ) {
				$map[ $val ] = $emails;
			}
		}
		return $map;
	}

	/** Normaliza una clave de routing (minúsculas, sin espacios extremos). */
	private static function route_key( string $value ): string
	{
		return function_exists( 'mb_strtolower' )
			? mb_strtolower( trim( $value ), 'UTF-8' )
			: strtolower( trim( $value ) );
	}

	/**
	 * ROUTING_URL_V1: resuelve la opción seleccionada de un campo tipo
	 * 'routing' (ROUTING_FIELD_V1) y devuelve su comportamiento completo:
	 * proyecto (registry) · correos extra (mail) · URL de redirección ·
	 * pipeline. Las claves `emails` y `url` NO son excluyentes: una opción
	 * puede notificar por correo y además redirigir a una web.
	 *
	 * PURE (solo stubs: sanitize_title/sanitize_text_field/esc_url_raw),
	 * reusable por FormRenderer (form nativo) sin depender del contexto del
	 * POST. Sin match o sin opciones → array vacío.
	 *
	 * @param array  $rf  Campo routing (con `options`).
	 * @param string $sel Valor seleccionado por el visitante.
	 * @return array{project_slug:string,project_area:string,emails:string,redirect:string,pipeline:bool}|array{}
	 */
	public static function routing_option( array $rf, string $sel ): array
	{
		$ropts = is_array( $rf['options'] ?? null ) ? $rf['options'] : array();
		foreach ( $ropts as $ro ) {
			if ( ! is_array( $ro ) || (string) ( $ro['value'] ?? '' ) !== $sel ) {
				continue;
			}
			$project = sanitize_title( (string) ( $ro['project'] ?? '' ) );
			return array(
				'project_slug' => $project,
				'project_area' => sanitize_text_field( (string) ( $ro['project'] ?? $project ) ),
				'emails'       => sanitize_text_field( (string) ( $ro['emails'] ?? '' ) ),
				'redirect'     => esc_url_raw( (string) ( $ro['url'] ?? '' ) ),
				'pipeline'     => ! empty( $ro['pipeline'] ),
			);
		}
		return array();
	}

	// ── REGION_ROUTING_V1 ─────────────────────────────────────────────────
	// Routing por lista de región (campo de ciudad, select o texto libre):
	// el valor capturado se normaliza y se busca en la lista de la región; si
	// coincide con una localidad, se resuelve la zona → proyecto + redirect.

	/** Option canónica de listas de regiones (key => [ {name, zone} ]). */
	const REGION_LISTS_OPTION = 'estandar_suite_capture_region_lists';

	/**
	 * Lista de localidades de una región (de la option o del data embebido).
	 * Devuelve array de [ 'name' => string, 'zone' => string ] o vacío.
	 */
	public static function region_list( string $key ): array
	{
		$key   = sanitize_key( $key );
		$lists = get_option( self::REGION_LISTS_OPTION, array() );
		$lists = is_array( $lists ) ? $lists : array();
		if ( isset( $lists[ $key ] ) && is_array( $lists[ $key ] ) ) {
			return $lists[ $key ];
		}
		$file = ( defined( 'ESTANDAR_SUITE_CAPTURE_PATH' ) ? ESTANDAR_SUITE_CAPTURE_PATH : dirname( __DIR__ ) . '/' )
			. 'includes/data/regions/' . $key . '.php';
		if ( is_file( $file ) ) {
			$data = require $file;
			return is_array( $data ) ? $data : array();
		}
		return array();
	}

	/** Sembra (persiste) la lista de una región desde el data embebido. */
	public static function region_seed( string $key, bool $commit = true ): array
	{
		$key  = sanitize_key( $key );
		$file = ( defined( 'ESTANDAR_SUITE_CAPTURE_PATH' ) ? ESTANDAR_SUITE_CAPTURE_PATH : dirname( __DIR__ ) . '/' )
			. 'includes/data/regions/' . $key . '.php';
		if ( ! is_file( $file ) ) {
			return array( 'ok' => false, 'error' => 'Región no disponible: ' . $key );
		}
		$data  = require $file;
		$data  = is_array( $data ) ? $data : array();
		$count = count( $data );
		if ( ! $commit ) {
			return array( 'ok' => true, 'dry' => true, 'region' => $key, 'count' => $count );
		}
		$lists = get_option( self::REGION_LISTS_OPTION, array() );
		$lists = is_array( $lists ) ? $lists : array();
		$lists[ $key ] = $data;
		update_option( self::REGION_LISTS_OPTION, $lists, false );
		return array( 'ok' => true, 'dry' => false, 'region' => $key, 'count' => $count );
	}

	/** Normaliza un valor de localidad para el match (minúsculas, sin acentos). PURE. */
	public static function region_normalize( string $value ): string
	{
		$accent_map = array(
			'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ü' => 'u', 'Ñ' => 'n',
			'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
		);
		$v = strtr( (string) $value, $accent_map );
		$v = function_exists( 'mb_strtolower' )
			? mb_strtolower( trim( $v ), 'UTF-8' )
			: strtolower( trim( $v ) );
		return $v;
	}

	/** Zona de una localidad en una región ('temuco' | 'villarrica' | ''). PURE. */
	public static function region_zone( string $key, string $value ): string
	{
		if ( $value === '' ) {
			return '';
		}
		$needle = self::region_normalize( $value );
		if ( $needle === '' ) {
			return '';
		}
		foreach ( self::region_list( $key ) as $entry ) {
			if ( self::region_normalize( (string) ( $entry['name'] ?? '' ) ) === $needle ) {
				return sanitize_key( (string) ( $entry['zone'] ?? '' ) );
			}
		}
		return '';
	}

	/** Config `region_routing` del form (objeto o JSON). */
	public static function region_routing_config( array $config ): array
	{
		$rr = $config['region_routing'] ?? null;
		if ( is_string( $rr ) ) {
			$rr = json_decode( $rr, true );
		}
		return is_array( $rr ) ? $rr : array();
	}

	/**
	 * Resuelve el routing por región para una submission: lee el valor del
	 * campo ciudad (value_field, con fallback_field), busca la zona y devuelve
	 * el mapeo de la zona ({project_slug, project_area, redirect, pipeline, zone}).
	 * Sin match → array vacío. PURE.
	 */
	public static function region_routing_resolve( array $config, array $posted ): array
	{
		$rr = self::region_routing_config( $config );
		if ( empty( $rr['list'] ) || empty( $rr['zones'] ) || ! is_array( $rr['zones'] ) ) {
			return array();
		}
		$value = '';
		foreach ( array( 'value_field', 'fallback_field' ) as $fk ) {
			$f = sanitize_key( (string) ( $rr[ $fk ] ?? '' ) );
			if ( $f !== '' && isset( $posted[ $f ] ) ) {
				$value = (string) $posted[ $f ];
				if ( $value !== '' ) {
					break;
				}
			}
		}
		if ( $value === '' ) {
			return array();
		}
		$zone = self::region_zone( (string) $rr['list'], $value );
		if ( $zone === '' || empty( $rr['zones'][ $zone ] ) || ! is_array( $rr['zones'][ $zone ] ) ) {
			return array();
		}
		$zc = $rr['zones'][ $zone ];
		return array(
			'project_slug' => sanitize_title( (string) ( $zc['project'] ?? '' ) ),
			'project_area' => sanitize_text_field( (string) ( $zc['project_area'] ?? ( $zc['project'] ?? '' ) ) ),
			'redirect'     => esc_url_raw( (string) ( $zc['redirect'] ?? '' ) ),
			'pipeline'     => ! empty( $zc['pipeline'] ),
			'zone'         => $zone,
		);
	}

	/**
	 * Form completo con config decodificada (lectura remota segura). Devuelve
	 * array con las columnas del form + `config` (array decodificado) o null.
	 */
	public static function form_config( int $id ): ?array
	{
		$form = self::get( $id );
		if ( ! $form ) {
			return null;
		}
		$config = json_decode( (string) ( $form['config_json'] ?? '{}' ), true );
		$form['config'] = is_array( $config ) ? $config : array();
		return $form;
	}

	/**
	 * Guarda (merge) la configuración `region_routing` de un form. Valida la
	 * estructura, hace merge no destructivo en config_json y persiste.
	 * Devuelve array {ok, error?, form_id, count}.
	 */
	public static function region_routing_save( int $id, array $rr ): array
	{
		$form = self::get( $id );
		if ( ! $form ) {
			return array( 'ok' => false, 'error' => 'Formulario inexistente: ' . $id );
		}
		$list = sanitize_key( (string) ( $rr['list'] ?? '' ) );
		if ( $list === '' ) {
			return array( 'ok' => false, 'error' => 'region_routing.list requerido.' );
		}
		$value_field    = sanitize_key( (string) ( $rr['value_field'] ?? '' ) );
		$fallback_field = sanitize_key( (string) ( $rr['fallback_field'] ?? '' ) );
		if ( $value_field === '' && $fallback_field === '' ) {
			return array( 'ok' => false, 'error' => 'region_routing requiere value_field o fallback_field.' );
		}
		$zones = array();
		foreach ( (array) ( $rr['zones'] ?? array() ) as $z => $zc ) {
			$z = sanitize_key( (string) $z );
			if ( $z === '' || ! is_array( $zc ) ) {
				continue;
			}
			$zones[ $z ] = array(
				'project'      => sanitize_title( (string) ( $zc['project'] ?? '' ) ),
				'project_area' => sanitize_text_field( (string) ( $zc['project_area'] ?? '' ) ),
				'redirect'     => esc_url_raw( (string) ( $zc['redirect'] ?? '' ) ),
				'pipeline'     => ! empty( $zc['pipeline'] ),
			);
		}
		if ( empty( $zones ) ) {
			return array( 'ok' => false, 'error' => 'region_routing.zones requerido (al menos una zona).' );
		}

		$config = json_decode( (string) ( $form['config_json'] ?? '{}' ), true );
		$config = is_array( $config ) ? $config : array();
		$config['region_routing'] = array(
			'list'           => $list,
			'value_field'    => $value_field,
			'fallback_field' => $fallback_field,
			'zones'          => $zones,
		);
		$ok = self::update( $id, array( 'config_json' => wp_json_encode( $config, JSON_UNESCAPED_UNICODE ) ) );
		if ( ! $ok ) {
			return array( 'ok' => false, 'error' => 'No se pudo persistir (bloqueo de pérdida de config).' );
		}
		return array( 'ok' => true, 'form_id' => $id, 'zones' => array_keys( $zones ), 'count' => count( $zones ) );
	}

	/**
	 * Merge remoto de claves de config de un form (operación sin SSH). Acepta
	 * un patch con claves de config_json (actions_json, experience_config_json,
	 * submit_label, success_message, region_routing, sections, etc.). Merge no
	 * destructivo: claves ausentes del patch se conservan; sections se fusionan
	 * campo a campo. Devuelve array {ok, error?, form_id}.
	 */
	public static function config_merge_save( int $id, array $patch ): array
	{
		$form = self::get( $id );
		if ( ! $form ) {
			return array( 'ok' => false, 'error' => 'Formulario inexistente: ' . $id );
		}
		if ( empty( $patch ) || ! is_array( $patch ) ) {
			return array( 'ok' => false, 'error' => 'Patch vacío.' );
		}
		$config = json_decode( (string) ( $form['config_json'] ?? '{}' ), true );
		$config = is_array( $config ) ? $config : array();

		foreach ( $patch as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( $key === '' ) {
				continue;
			}
			if ( $key === 'sections' ) {
				if ( is_array( $value ) ) {
					$config['sections'] = self::merge_sections_fieldwise( is_array( $config['sections'] ?? null ) ? $config['sections'] : array(), $value );
				}
				continue;
			}
			if ( $key === 'region_routing' && is_array( $value ) ) {
				$config['region_routing'] = $value;
				continue;
			}
			if ( is_string( $value ) ) {
				$config[ $key ] = $value;
			} elseif ( is_array( $value ) ) {
				$config[ $key ] = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			}
		}

		$ok = self::update( $id, array( 'config_json' => wp_json_encode( $config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) );
		if ( ! $ok ) {
			return array( 'ok' => false, 'error' => 'No se pudo persistir (bloqueo de pérdida de config).' );
		}
		return array( 'ok' => true, 'form_id' => $id );
	}

	/**
	 * SECTIONS_FIELDWISE_V1 — merge de secciones preservando campos: actualiza
	 * SOLO los campos del patch (por name, merge de propiedades), conservando
	 * el resto de campos de la sección. Evita que un patch parcial (ej. tocar
	 * un solo campo por REST) borre los demás de la sección.
	 */
	private static function merge_sections_fieldwise( array $current, array $posted ): array
	{
		$cur_by_name = array();
		foreach ( (array) $current as $sec ) {
			if ( ! is_array( $sec ) ) {
				continue;
			}
			$n = sanitize_key( (string) ( $sec['name'] ?? '' ) );
			if ( $n === '' ) {
				$n = 'title:' . sanitize_key( (string) ( $sec['title'] ?? '' ) );
			}
			if ( $n !== '' ) {
				$cur_by_name[ $n ] = $sec;
			}
		}
		$out          = array();
		$posted_names = array();
		foreach ( (array) $posted as $sec ) {
			if ( ! is_array( $sec ) ) {
				continue;
			}
			$n = sanitize_key( (string) ( $sec['name'] ?? '' ) );
			if ( $n === '' ) {
				$n = 'title:' . sanitize_key( (string) ( $sec['title'] ?? '' ) );
			}
			$posted_names[] = $n;
			if ( ! isset( $cur_by_name[ $n ] ) ) {
				$out[] = $sec;
				continue;
			}
			$base       = $cur_by_name[ $n ];
			$cur_fields = is_array( $base['fields'] ?? null ) ? $base['fields'] : array();
			$new_fields = is_array( $sec['fields'] ?? null ) ? $sec['fields'] : array();
			$cur_by_field = array();
			foreach ( $cur_fields as $f ) {
				if ( is_array( $f ) ) {
					$fn = sanitize_key( (string) ( $f['name'] ?? '' ) );
					if ( $fn !== '' ) {
						$cur_by_field[ $fn ] = $f;
					}
				}
			}
			$merged = array();
			$seen   = array();
			foreach ( $new_fields as $f ) {
				if ( ! is_array( $f ) ) {
					$merged[] = $f;
					continue;
				}
				$fn = sanitize_key( (string) ( $f['name'] ?? '' ) );
				if ( $fn !== '' && isset( $cur_by_field[ $fn ] ) ) {
					// Merge no destructivo: cur como base, new prevalece por prop.
					$merged[] = array_merge( $cur_by_field[ $fn ], $f );
					$seen[ $fn ] = true;
				} else {
					$merged[] = $f;
					if ( $fn !== '' ) {
						$seen[ $fn ] = true;
					}
				}
			}
			foreach ( $cur_by_field as $fn => $f ) {
				if ( ! isset( $seen[ $fn ] ) ) {
					$merged[] = $f;
				}
			}
			$base['fields'] = $merged;
			$out[] = $base;
		}
		foreach ( $cur_by_name as $n => $sec ) {
			if ( ! in_array( $n, $posted_names, true ) ) {
				$out[] = $sec;
			}
		}
		return $out;
	}

	// ── PROGRESSIVE_FORM_V1 ────────────────────────────────────────────────
	// Ejecución del flujo progresivo (`actions_json.flow.mode=progressive`):
	// agrupa los campos del form en pasos (step) y revela el siguiente solo al
	// completar el anterior. Config heredada del legacy Form Engine; el motor
	// nativo ahora lo ejecuta (render por pasos + JS de navegación).

	/**
	 * Pasos progresivos normalizados de un form desde actions_json.flow.
	 * Devuelve array de steps {id, label, fields:[], initial, next_label}
	 * o array vacío si no hay flujo progresivo configurado.
	 */
	public static function form_flow_steps( array $config ): array
	{
		$actions = $config['actions_json'] ?? '{}';
		if ( is_string( $actions ) ) {
			$actions = json_decode( $actions, true );
		}
		$actions = is_array( $actions ) ? $actions : array();
		$flow    = is_array( $actions['flow'] ?? null ) ? $actions['flow'] : array();
		if ( (string) ( $flow['mode'] ?? '' ) !== 'progressive' || empty( $flow['steps'] ) || ! is_array( $flow['steps'] ) ) {
			return array();
		}
		$steps = array();
		foreach ( $flow['steps'] as $s ) {
			if ( ! is_array( $s ) || empty( $s['id'] ) ) {
				continue;
			}
			$fields = array();
			foreach ( (array) ( $s['fields'] ?? array() ) as $f ) {
				$f = sanitize_key( (string) $f );
				if ( $f !== '' && ! in_array( $f, $fields, true ) ) {
					$fields[] = $f;
				}
			}
			if ( ! $fields ) {
				continue;
			}
			$steps[] = array(
				'id'         => sanitize_key( (string) $s['id'] ),
				'label'      => sanitize_text_field( (string) ( $s['label'] ?? '' ) ),
				'fields'     => $fields,
				'initial'    => ! empty( $s['initial'] ),
				'next_label' => sanitize_text_field( (string) ( $s['next_label'] ?? 'Continuar' ) ),
			);
		}
		return $steps;
	}

	/**
	 * Mapa campo → índice de paso para el render progresivo. Asigna cada campo
	 * renderizable a su paso; si un campo significativo (no heading/html) queda
	 * fuera de los pasos, devuelve null (el form se renderiza sin pasos, no se
	 * oculta nada). PURE.
	 *
	 * @param array $fields Campos renderizables (Forms::renderable_fields).
	 * @param array $steps  Pasos de Forms::form_flow_steps.
	 * @return array|null   [ field_name => step_index ] o null si no aplica.
	 */
	public static function progressive_step_map( array $fields, array $steps ): ?array
	{
		if ( count( $steps ) < 2 ) {
			return null;
		}
		$names = array();
		foreach ( $fields as $f ) {
			$n = sanitize_key( (string) ( $f['name'] ?? '' ) );
			if ( $n === '' ) {
				continue;
			}
			$names[] = $n;
		}
		$map = array();
		foreach ( $steps as $i => $st ) {
			foreach ( $st['fields'] as $fn ) {
				if ( in_array( $fn, $names, true ) && ! isset( $map[ $fn ] ) ) {
					$map[ $fn ] = $i;
				}
			}
		}
		if ( ! $map ) {
			return null;
		}
		$last = count( $steps ) - 1;
		$skippable = array( 'heading', 'html' );
		foreach ( $fields as $f ) {
			$n   = sanitize_key( (string) ( $f['name'] ?? '' ) );
			$typ = strtolower( (string) ( $f['type'] ?? '' ) );
			if ( $n === '' || in_array( $typ, $skippable, true ) ) {
				continue;
			}
			if ( ! isset( $map[ $n ] ) ) {
				return null; // campo significativo sin paso → no aplicar pasos.
			}
		}
		return $map;
	}

	/** Emails de los operadores activos de un proyecto (es_operators). */
	private static function project_operator_emails( string $project_slug ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $project_slug === '' ) {
			return array();
		}
		$table = $wpdb->prefix . 'es_operators';
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT email FROM {$table} WHERE active = 1 AND email <> ''
			 AND (project_slug = %s OR area = %s OR allowed_projects_json LIKE %s)",
			$project_slug, $project_slug, '%' . $wpdb->esc_like( $project_slug ) . '%'
		), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$out = array();
		foreach ( $rows as $r ) {
			$e = sanitize_email( (string) ( $r['email'] ?? '' ) );
			if ( $e !== '' && is_email( $e ) && ! in_array( $e, $out, true ) ) {
				$out[] = $e;
			}
		}
		return $out;
	}

	/**
	 * Enlace de WhatsApp del proyecto para las autorespuestas (WHATSAPP_LINK_V1).
	 *
	 * Fuente: (1) número del primer operador activo del proyecto en
	 * `es_operators.whatsapp_number`; (2) número del botón flotante
	 * (FloatingWhatsApp settings/filter). Devuelve `https://wa.me/<digits>`
	 * o '' si no hay número configurado (el cuerpo deja el enlace vacío).
	 */
	private static function project_whatsapp_link( string $project_slug ): string
	{
		$number = '';
		global $wpdb;
		if ( isset( $wpdb ) && $project_slug !== '' ) {
			$table = $wpdb->prefix . 'es_operators';
			try {
				if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
					$number = (string) $wpdb->get_var( $wpdb->prepare(
						"SELECT whatsapp_number FROM {$table}
						 WHERE active = 1 AND whatsapp_number <> ''
						 AND (project_slug = %s OR area = %s OR allowed_projects_json LIKE %s)
						 ORDER BY id ASC LIMIT 1",
						$project_slug, $project_slug, '%' . $wpdb->esc_like( $project_slug ) . '%'
					) );
				}
			} catch ( \Exception $e ) {
				$number = '';
			}
		}
		if ( $number === '' && class_exists( 'Estandar\\Capture\\FloatingWhatsApp' ) && method_exists( 'Estandar\\Capture\\FloatingWhatsApp', 'number' ) ) {
			$number = \Estandar\Capture\FloatingWhatsApp::number();
		}
		$digits = preg_replace( '/\D+/', '', (string) $number );
		if ( $digits === '' ) {
			return '';
		}
		return 'https://wa.me/' . $digits;
	}

	/**
	 * Resuelve los destinatarios de la notificación interna de un form.
	 *
	 * NOTIFY_ROUTING_V1: si el form define routing por valor de respuesta
	 * (`notify_route_field` + `notify_route_map_text`), el valor capturado de
	 * ese campo decide los destinatarios. Sin regla que matchee → fallback a
	 * `notify_emails`. Compartido por el camino de form nativo
	 * (`notify_submission`) y por el cierre de surveys por etapas
	 * (`SurveyHandler`), para que la bifurcación de correos sea una capacidad
	 * configurable por form, usable en cualquier caso.
	 *
	 * @param array $config Config del form (keys de mail_settings).
	 * @param array $capture Capture v2 (lee `$capture['fields'][route_field]`).
	 */
	public static function resolve_notify_to( array $config, array $capture ): string
	{
		$m  = self::mail_settings( $config );
		$to = (string) $m['notify']['to'];

		if ( $m['notify']['route_field'] !== '' && ! empty( $m['notify']['route_map'] ) ) {
			$route_value = (string) ( $capture['fields'][ $m['notify']['route_field'] ] ?? '' );
			$key         = self::route_key( $route_value );
			if ( $key !== '' && isset( $m['notify']['route_map'][ $key ] ) ) {
				$routed = trim( (string) $m['notify']['route_map'][ $key ] );
				if ( $routed !== '' ) {
					$to = $routed;
				}
			}
		}
		return $to;
	}

	/**
	 * EMAIL_DEDUPE_V1: ventana de dedupe para envíos de correo (autorespuesta,
	 * autorespuesta diferida y notificación interna). Evita 2-3× correos
	 * idénticos al mismo destinatario/lead cuando el form se reenvía (resubmit,
	 * reintento de red, doble disparo), igual que el debounce del notifier del
	 * operador (300s por defecto, filtrable).
	 *
	 * @param string $prefix  Prefijo de transient por scope (AR / AR_DELAYED / NOTIFY).
	 * @param string $key     Identificador del destinatario o lead (email, contacto, sesión).
	 * @param int    $form_id Form al que pertenece el envío.
	 * @return bool true si el envío debe proceder (fuera de la ventana).
	 */
	private static function dedupe_email( string $prefix, string $key, int $form_id ): bool
	{
		if ( ! function_exists( 'get_transient' ) || ! function_exists( 'set_transient' ) ) {
			return true;
		}
		$window = (int) apply_filters( 'estandar_suite_capture_email_dedupe_seconds', 300 );
		if ( $window <= 0 ) {
			return true;
		}
		$transient_key = $prefix . $form_id . '_' . md5( $key );
		if ( get_transient( $transient_key ) ) {
			return false;
		}
		set_transient( $transient_key, 1, $window );
		return true;
	}

	/** EMAIL_DEDUPE_EXEMPT_V1: correos que nunca se deduplican (pruebas del
	 *  dueño/desarrollador). Al enviar con uno de estos remitentes se repite la
	 *  notificación/autorespuesta aunque caiga en la ventana de 5 min — útil
	 *  para validar un form varias veces seguidas con la misma identidad.
	 *  Lista filtrable: `estandar_suite_capture_email_dedupe_exempt`. */
	public static function email_dedupe_exempt( string $email ): bool
	{
		$email = strtolower( trim( $email ) );
		if ( $email === '' ) {
			return false;
		}
		$list = (array) apply_filters( 'estandar_suite_capture_email_dedupe_exempt', array( 'rodrigovaldes@me.com' ) );
		foreach ( $list as $e ) {
			if ( is_string( $e ) && strtolower( trim( $e ) ) === $email ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * RECONTACT_AR_V1: ¿la captura es un recontacto? Cuenta las submissions v2
	 * de la MISMA identidad (email O teléfono normalizados). La ingestión
	 * persiste ANTES de las acciones de correo (Actions::run), por lo que la
	 * captura actual ya está contada → count >= 2 = volvió a contactar.
	 * Sin identidad → false (no se puede saber, se usa la autorespuesta normal).
	 */
	public static function capture_is_recontact( array $capture ): bool
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return false;
		}
		$email = strtolower( trim( (string) ( $capture['email_normalized'] ?? $capture['contact']['email'] ?? '' ) ) );
		$phone = preg_replace( '/\D+/', '', (string) ( $capture['phone_normalized'] ?? $capture['contact']['phone'] ?? '' ) );
		if ( $email === '' && $phone === '' ) {
			return false;
		}
		$table = $wpdb->prefix . 'estandar_capture_submissions';
		$sql   = "SELECT COUNT(*) FROM {$table} WHERE ";
		$conds = array();
		$args  = array();
		if ( $email !== '' ) {
			$conds[] = 'email_normalized = %s';
			$args[]  = $email;
		}
		if ( $phone !== '' ) {
			$conds[] = 'phone_normalized = %s';
			$args[]  = $phone;
		}
		$count = (int) $wpdb->get_var( $wpdb->prepare( $sql . implode( ' OR ', $conds ), $args ) );
		return $count >= 2;
	}

	/**
	 * NOTIFY_CC_V1: compone la lista CC final de la notificación interna
	 * (admin + copias de gestión + cc_always), deduplicada entre sí y contra
	 * el To. PURE (solo stubs WP: sanitize_email/is_email/get_option/
	 * apply_filters), reusable por el form nativo y por el cierre de surveys.
	 *
	 * @param array  $m_notify Sub-array 'notify' de Forms::mail_settings().
	 * @param string $to       Destinatarios To separados por coma.
	 * @param array  $cc       CC inicial (para reusar en otros caminos).
	 * @return array Lista de direcciones CC (únicas, sin las del To).
	 */
	public static function notify_cc( array $m_notify, string $to, array $cc = array() ): array
	{
		$to_list = array_values( array_filter( array_map( 'sanitize_email', preg_split( '/[\s,;]+/', $to ) ) ) );

		// NOTIFY_CC_ADMIN_V1: copia al administrador de la página (por defecto
		// activada). No duplica si el admin ya está entre los destinatarios.
		if ( ! empty( $m_notify['cc_admin'] ) ) {
			$admin = sanitize_email( (string) get_option( 'admin_email', '' ) );
			if ( $admin !== '' && is_email( $admin ) && ! in_array( $admin, $cc, true ) && ! in_array( $admin, $to_list, true ) ) {
				$cc[] = $admin;
			}
		}

		// NOTIFY_CC_GESTION_V1: copia adicional configurable por sitio para
		// seguimiento central (sin dirección por defecto; se define por filtro
		// o config de cada sitio, nunca hardcodeada en el plugin).
		$extra_cc = (array) apply_filters( 'estandar_suite_capture_notify_cc', array() );
		foreach ( $extra_cc as $addr ) {
			$addr = sanitize_email( (string) $addr );
			if ( $addr === '' || ! is_email( $addr ) ) {
				continue;
			}
			if ( ! in_array( $addr, $cc, true ) && ! in_array( $addr, $to_list, true ) ) {
				$cc[] = $addr;
			}
		}

		// NOTIFY_CC_ALWAYS_V1: correos que SIEMPRE reciben copia, aunque el
		// routing por campo cambie el To (ej. "siempre a cm@cliente1.example.com").
		foreach ( array_map( 'sanitize_email', preg_split( '/[\s,;]+/', (string) ( $m_notify['cc_always'] ?? '' ) ) ) as $addr ) {
			if ( $addr === '' || ! is_email( $addr ) ) {
				continue;
			}
			if ( ! in_array( $addr, $cc, true ) && ! in_array( $addr, $to_list, true ) ) {
				$cc[] = $addr;
			}
		}
		return $cc;
	}

	/** Variables dinámicas de una submission para plantillas de correo. */
	/** NOTIFY_FIELDS_V1: ¿campo oculto/sistema que NO va al detalle del correo? PURE. */
	public static function is_hidden_field( string $name, string $type ): bool
	{
		$name = strtolower( trim( $name ) );
		if ( $name === '' || $type === 'hidden' || $type === 'heading' || $type === 'html' ) {
			return true;
		}
		if ( strpos( $name, '_' ) === 0 || strpos( $name, 'estandar_' ) === 0 ) {
			return true;
		}
		return in_array( $name, array(
			'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
			'gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid',
			'project_slug', 'project_area', 'session_id', 'time_on_page', 'referrer', 'landing',
		), true );
	}

	/** NOTIFY_FIELDS_V1: label de la opción seleccionada (select/routing/radio). PURE. */
	public static function field_option_label( array $field, string $value ): string
	{
		$type = strtolower( (string) ( $field['type'] ?? '' ) );
		if ( $type === 'matrix' ) {
			return $value;
		}
		foreach ( (array) ( $field['options'] ?? array() ) as $o ) {
			if ( is_array( $o ) && isset( $o['value'] ) && (string) $o['value'] === $value ) {
				return (string) ( $o['label'] ?? $value );
			}
		}
		return $value;
	}

	/**
	 * NOTIFY_DATE_WEEKDAY_V1: formatea una fecha como "Viernes 05/09/2026"
	 * (día de la semana en español + fecha d/m/Y). PURE. La fecha cruda del
	 * form (input date → Y-m-d) se transforma para el correo y la tabla.
	 */
	public static function weekday_label( string $value ): string
	{
		$ts = strtotime( (string) $value );
		if ( $ts === false ) {
			return $value;
		}
		$name = self::weekday_name( $value );
		$date = gmdate( 'd/m/Y', $ts );
		return $name !== '' ? $name . ' ' . $date : $date;
	}

	/** WEEKDAY_NAME_V1: solo el día de la semana en español ("Lunes", "Martes"…).
	 *  PURE. Se usa en la tabla del visor (CamaraRegistros); weekday_label()
	 *  conserva día + fecha para el correo. */
	public static function weekday_name( string $value ): string
	{
		$ts = strtotime( (string) $value );
		if ( $ts === false ) {
			return $value;
		}
		$dias = array( 0 => 'Domingo', 1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado' );
		$w    = (int) gmdate( 'w', $ts );
		return isset( $dias[ $w ] ) ? $dias[ $w ] : '';
	}

	/** TIME_DISPLAY_V1: hora legible "HH:MM" (24 h). Acepta "H:MM", "HH:MM" y
	 *  "HH:MM:SS". PURE; si no reconoce el formato devuelve el valor tal cual. */
	public static function time_display( string $value ): string
	{
		$value = trim( $value );
		if ( preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', $value, $m ) ) {
			return str_pad( $m[1], 2, '0', STR_PAD_LEFT ) . ':' . $m[2];
		}
		return $value;
	}

	public static function submission_vars( int $form_id, array $config, array $capture ): array
	{
		$fields  = self::renderable_fields( $config );
		$posted  = is_array( $capture['fields'] ?? null ) ? $capture['fields'] : array();
		$contact = is_array( $capture['contact'] ?? null ) ? $capture['contact'] : array();
		$content = is_array( $capture['content'] ?? null ) ? $capture['content'] : array();
		// El nombre del form vive en la fila (columna name), no en config_json:
		// el legacy_to_native no lo copia al config. Fallback a la fila real.
		$form_name = (string) ( $config['name'] ?? '' );
		if ( $form_name === '' && $form_id > 0 ) {
			$row = self::get( $form_id );
			$form_name = is_array( $row ) ? (string) ( $row['name'] ?? '' ) : '';
		}

		// NOTIFY_FIELDS_V1: {{fields}} se construye con los campos REALES del
		// form (renderable_fields → sections, no el fields_json legacy), con sus
		// labels y el label de la opción seleccionada (select/routing), excluyendo
		// hidden/sistema. Así el correo muestra el interés, el apellido, el RUT,
		// etc., y no filtra _routing_emails/utm.
		$rows = array();
		$text = array();
		foreach ( (array) $fields as $f ) {
			if ( ! is_array( $f ) ) {
				continue;
			}
			$fn   = (string) ( $f['name'] ?? '' );
			$type = strtolower( (string) ( $f['type'] ?? 'text' ) );
			$lab  = (string) ( $f['label'] ?? $fn );
			if ( self::is_hidden_field( $fn, $type ) || ! array_key_exists( $fn, $posted ) ) {
				continue;
			}
			$pv  = $posted[ $fn ];
			$val = is_scalar( $pv ) ? (string) $pv : ( is_array( $pv ) ? implode( ', ', array_filter( array_map( 'strval', $pv ) ) ) : '' );
			if ( $val === '' ) {
				continue;
			}
			$val = self::field_option_label( $f, $val );
			// NOTIFY_PHONE_FMT_V1: teléfonos legibles en el correo (+56 9 XXXX XXXX).
			if ( in_array( $type, array( 'tel', 'phone' ), true ) || in_array( strtolower( $fn ), array( 'telefono', 'teléfono', 'phone', 'phone_number', 'tel', 'celular', 'mobile', 'fono' ), true ) ) {
				$val = self::phone_display( (string) $val );
			}

			// NOTIFY_DATE_WEEKDAY_V1: un campo de fecha muestra "Viernes 05/09/2026"
			// (día de la semana en español + fecha) en vez de la fecha cruda.
			if ( $type === 'date' && strtotime( $val ) !== false ) {
				$val = self::weekday_label( $val );
			}
			// NOTIFY_TIME_FMT_V1: un campo de hora muestra "HH:MM" (24 h) legible.
			if ( $type === 'time' ) {
				$val = self::time_display( (string) $val );
			}
			// NOTIFY_CHECKBOX_V1: un checkbox marcado (valor "1") se muestra como
			// "✓" (más limpio que "1") en el detalle del correo.
			if ( $type === 'checkbox' ) {
				$val = ( (string) $val === '1' ) ? '✓' : ( $val !== '' ? $val : '' );
			}

			$text[] = $lab . ': ' . $val;
			$rows[] = '<tr><td style="border:1px solid #e2e4e7;background:#f6f7f7;padding:6px 10px;vertical-align:top;"><strong>' . esc_html( $lab ) . '</strong></td>'
				. '<td style="border:1px solid #e2e4e7;padding:6px 10px;">' . esc_html( $val ) . '</td></tr>';
		}
		$fields_html = $rows
			? '<table cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin-top:8px;">' . implode( '', $rows ) . '</table>'
			: '';

		return array(
			'form_name'     => $form_name,
			'name'          => (string) ( $capture['name'] ?? $contact['name'] ?? '' ),
			'email'         => (string) ( $capture['email_normalized'] ?? $contact['email'] ?? '' ),
			'phone'         => (string) ( $capture['phone_normalized'] ?? $contact['phone'] ?? '' ),
			'message'       => (string) ( $capture['message'] ?? $content['message'] ?? '' ),
			'fields'        => $fields_html,
			'fields_text'   => implode( "\n", $text ),
			'submission_id' => (string) ( $capture['id'] ?? '' ),
			'date'          => current_time( 'mysql' ),
		);
	}

	/**
	 * Notificación interna por Form (usa el motor único Core\Mail).
	 *
	 * @param array $opts SUBMISSION_NOTIFY_RESEND_V1: overrides opcionales —
	 *                    'to' (string CSV, reemplaza el To final) y
	 *                    'skip_dedupe' (bool, salta la ventana de dedupe).
	 */
	public static function notify_submission( int $form_id, array $config, array $capture, array $opts = array() ): array
	{
		$m      = self::mail_settings( $config );
		$posted = is_array( $capture['fields'] ?? null ) ? $capture['fields'] : array();
		// ROUTING_INDEPENDENT_V1: un campo de tipo 'routing' designa POR SÍ MISMO
		// el destino de la notificación (dropdown del frontend → _routing_emails).
		// Esa notificación por routing NO depende del switch genérico "enviar
		// aviso interno" (send_email): si se apaga el aviso genérico (p. ej. para
		// evitar el solape con el correo del operador), la notificación por
		// routing debe seguir saliendo. Sin esto, se apagaban ambas y se perdía
		// el aviso por completo.
		$has_routing = isset( $posted['_routing_emails'] ) && trim( (string) $posted['_routing_emails'] ) !== '';
		if ( empty( $m['notify']['enabled'] ) && ! $has_routing ) {
			return array( 'ok' => false, 'kind' => 'notify', 'form_id' => $form_id, 'error' => 'disabled' );
		}
		// NOTIFICACION_SIMPLE_V1 (simplificación): el aviso "Nuevo envío" SIEMPRE
		// se envía a los correos configurados del form (notify_emails + emails de
		// la opción de routing), sin importar el modo funnel/pipeline. El correo
		// enriquecido del operador es un EXTRA que envía OperatorNotifier cuando
		// el proyecto tiene operadores activos. Sin supresión silenciosa.

		// NOTIFY_ROUTING_V1: si el form define routing por valor de campo,
		// el valor capturado decide los destinatarios (fallback = notify_emails).
		// ROUTING_FIELD_V1: si se seleccionó una opción de un campo 'routing',
		// los destinatarios son los correos extra de la opción (+ fallback).
		// ROUTING_FORM_AUTOCONFIG_V1: en un form que DECLARA un campo 'routing'
		// el aviso genérico (notify_emails) queda APAGADO por diseño: los
		// destinatarios son SOLO los del routing. Sin selección → no se envía.
		// Los operadores del proyecto NO van aquí: reciben su propio correo
		// enriquecido (OperatorNotifier) — sin duplicados.
		$has_routing_field = self::has_routing_field( $config );
		$normalize_list    = static function ( $raw ) {
			if ( class_exists( 'Estandar\\Core\\Mail' ) ) {
				return \Estandar\Core\Mail::normalize_addresses( (string) $raw );
			}
			return array_filter( array_map( 'sanitize_email', preg_split( '/[\s,;]+/', (string) $raw ) ) );
		};
		if ( $has_routing_field ) {
			$to = $has_routing ? implode( ',', (array) $normalize_list( $posted['_routing_emails'] ) ) : '';
		} else {
			$to = self::resolve_notify_to( $config, $capture );
			if ( isset( $posted['_routing_emails'] ) ) {
				$extra = $normalize_list( $posted['_routing_emails'] );
				if ( $extra ) {
					$to = implode( ',', $extra );
				}
				if ( trim( $to ) === '' ) {
					$to = self::resolve_notify_to( $config, $capture );
				}
			}
		}
		// SUBMISSION_NOTIFY_RESEND_V1: To explícito (reenvío dirigido). Tiene la
		// última palabra sobre notify_emails y sobre el routing por campo.
		if ( ! empty( $opts['to'] ) ) {
			$to = (string) $opts['to'];
		}
		if ( trim( $to ) === '' ) {
			return array( 'ok' => false, 'kind' => 'notify', 'form_id' => $form_id, 'error' => $has_routing_field ? 'routing_sin_destino' : 'sin_destinatarios' );
		}

		// NOTIFY_TO_OPERATOR_V1 (13.0.348): si la submission fue hecha por un
		// operador (registro de cámara vía PIN/magic link), la notificación va AL
		// OPERADOR (el que llenó el form) y los correos configurables del form
		// (notify_emails + cc) van como copia. Sin operador → comportamiento
		// clásico (To = configurables). Con To explícito (reenvío) NO se desvía.
		$configurable_to = $to;
		$operator_email = (string) ( $capture['operator_email'] ?? '' );
		if ( $operator_email === '' && ! empty( $capture['id'] ) ) {
			$row = self::get_submission( (int) $capture['id'] );
			$operator_email = is_array( $row ) ? (string) ( $row['operator_email'] ?? '' ) : '';
		}
		$operator_email = sanitize_email( $operator_email );
		if ( empty( $opts['to'] ) && $operator_email !== '' && is_email( $operator_email ) && strpos( $operator_email, 'test' ) !== 0 ) {
			$to = $operator_email;
		}

		// EMAIL_DEDUPE_V1: una sola notificación interna por lead+form en la
		// ventana de reintentos (resubmit/doble disparo → no repetir el aviso).
		// EMAIL_DEDUPE_EXEMPT_V1: remitentes exentos (pruebas del dueño) saltan
		// la ventana para que su envío repetido vuelva a notificar.
		// SUBMISSION_NOTIFY_RESEND_V1: un reenvío explícito salta la ventana.
		$lead_sig = md5( strtolower( sanitize_email( (string) ( $capture['email_normalized'] ?? $capture['contact']['email'] ?? '' ) ) )
			. '|' . sanitize_text_field( (string) ( $capture['phone_normalized'] ?? $capture['contact']['phone'] ?? '' ) ) );
		$dedupe_lead_email = strtolower( sanitize_email( (string) ( $capture['email_normalized'] ?? $capture['contact']['email'] ?? '' ) ) );
		if ( empty( $opts['skip_dedupe'] ) && ! self::email_dedupe_exempt( $dedupe_lead_email ) && ! self::dedupe_email( self::TRANSIENT_NOTIFY_DEDUPE, 'notify_' . $lead_sig, $form_id ) ) {
			return array( 'ok' => false, 'kind' => 'notify', 'form_id' => $form_id, 'error' => 'dedupe_window' );
		}

		// NOTIFY_CC_V1: copias de la notificación (admin + gestión + cc_always),
		// deduplicadas entre sí y contra el To. Reusable (form nativo y survey).
		// NOTIFY_TO_OPERATOR_V1: si el To pasó al operador, los destinatarios
		// configurables del form (notify_emails) van también en CC.
		$cc = self::notify_cc( $m['notify'], $to, array() );
		if ( $operator_email !== '' && $configurable_to !== '' && $configurable_to !== $to ) {
			foreach ( array_map( 'sanitize_email', preg_split( '/[\s,;]+/', $configurable_to ) ) as $addr ) {
				if ( $addr !== '' && is_email( $addr ) && ! in_array( $addr, $cc, true ) ) {
					$cc[] = $addr;
				}
			}
		}

		$vars = self::submission_vars( $form_id, $config, $capture );
		$project_slug = (string) ( is_array( $capture['fields'] ?? null ) ? ( $capture['fields']['project_slug'] ?? '' ) : '' );
		$vars = array_merge( $vars, self::brand_vars( self::mail_identity( $config, $project_slug ) ) );

		// CONTRATO GF (postventa): el correo debe ser byte-idéntico al de
		// Gravity Forms (subject + tabla Label:Value), porque la plataforma
		// externa del cliente lo parsea. mail_contract='gf_postventa'.
		$contract = sanitize_key( (string) ( $config['mail_contract'] ?? '' ) );
		if ( $contract === 'gf_postventa' && class_exists( 'Estandar\\Capture\\GfPostventaBridge' ) ) {
			$c = \Estandar\Capture\GfPostventaBridge::contract_from_capture( $capture, $config );
			$body    = (string) $c['body'];
			$subject = (string) $c['subject'];
		} else {
			$body = $m['notify']['body'] !== ''
				? $m['notify']['body']
				: self::notification_body_default();
			$subject = $m['notify']['subject'];
		}

		// REPLY_TO_LEAD_V1: el botón "Responder" del operador va al email del
		// lead por defecto. mail_reply_to_field puede apuntar a un campo del
		// form (su valor) o a un email fijo.
		$reply_to = '';
		$reply_cfg = trim( (string) ( $config['mail_reply_to_field'] ?? '' ) );
		if ( $reply_cfg !== '' ) {
			$fv = (string) ( $capture['fields'][ $reply_cfg ] ?? '' );
			$reply_to = ( $fv !== '' && \Estandar\Core\Mail::validate_address( $fv ) ) ? $fv
				: ( \Estandar\Core\Mail::validate_address( $reply_cfg ) ? $reply_cfg : '' );
		}
		if ( $reply_to === '' ) {
			$lead_email = (string) ( $capture['email_normalized'] ?? ( $capture['contact']['email'] ?? '' ) );
			if ( \Estandar\Core\Mail::validate_address( $lead_email ) ) {
				$reply_to = $lead_email;
			}
		}

		// NOTIFY_FIELDS_V1: el alt (texto plano) usa {{fields_text}} (líneas
		// "Label: value") en vez de la tabla HTML, para clientes sin HTML.
		// Mail::send renderiza las variables sobre el alt igual que sobre el html.
		$alt_template = str_replace( '{{fields}}', '{{fields_text}}', $body );

		// MAIL_BACKGROUND_V1: fondo de página opcional del correo (configurable
		// en el maquetador, clave mail_background_color). Envuelve el cuerpo en
		// un contenedor con ese fondo; sin configurar → sin wrapper (retrocompatible).
		$mail_bg = (string) ( $config['mail_background_color'] ?? '' );
		if ( $mail_bg !== '' && preg_match( '/^#[0-9a-fA-F]{3,8}$/', $mail_bg ) ) {
			$body = '<div style="background:' . esc_attr( $mail_bg ) . ';padding:20px 12px;">'
				. '<div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:10px;overflow:hidden;border:1px solid #e2e4e7;">'
				. $body
				. '</div></div>';
		}

		// MAIL_TEMPLATES_V1 · Fase 2: si el form/proyecto eligió plantilla de
		// correo, inyectar el body en su slot {{content}} (se quita la marca del
		// body; el header/footer los pone la plantilla). OFF si no hay plantilla
		// o si es contrato GF (el cuerpo <html> se conserva intacto).
		// ROUTING_FORM_AUTOCONFIG_V1: en forms con campo 'routing' el correo usa
		// SIEMPRE el template DEFAULT global (ignora form/proyecto).
		if ( $contract !== 'gf_postventa' ) {
			if ( $has_routing_field ) {
				$body = self::decorate_with_template( $body, array(), '', self::mail_identity( $config, $project_slug ) );
			} else {
				$body = self::decorate_with_template( $body, $config, $project_slug, self::mail_identity( $config, $project_slug ) );
			}
		}

		// LEAD_PHOTO_V1: los campos type=file del form (lead) suben el archivo y
		// el campo guarda la URL; aquí esos archivos se ADJUNTAN al correo del
		// operador (además de la fila "Fotografía…: URL" que arma el contrato).
		// Resuelve URL → ruta local bajo uploads (mismo patrón que el survey).
		$photo_paths = array();
		if ( class_exists( 'Estandar\\Capture\\SurveyHandler' ) ) {
			$urls = array();
			foreach ( self::renderable_fields( $config ) as $pf ) {
				if ( strtolower( (string) ( $pf['type'] ?? '' ) ) !== 'file' ) {
					continue;
				}
				$u = trim( (string) ( $posted[ (string) ( $pf['name'] ?? '' ) ] ?? '' ) );
				if ( $u !== '' ) {
					$urls[] = $u;
				}
			}
			if ( $urls ) {
				$up = function_exists( 'wp_get_upload_dir' ) ? wp_get_upload_dir() : array();
				$photo_paths = \Estandar\Capture\SurveyHandler::resolve_attachment_paths( $urls, (string) ( $up['baseurl'] ?? '' ), (string) ( $up['basedir'] ?? '' ) );
				$photo_paths = array_values( array_filter( $photo_paths, static function ( $p ) {
					return is_string( $p ) && is_file( $p ) && is_readable( $p );
				} ) );
			}
		}

		$r = \Estandar\Core\Mail::send( array(
			'to'        => $to,
			'subject'   => $subject,
			'html'      => $body,
			'alt'       => wp_strip_all_tags( $alt_template ),
			'from_name' => $m['notify']['sender'],
			'from_email' => (string) ( $m['notify']['from_email'] ?? '' ),
			'reply_to'  => $reply_to,
			'cc'        => $cc,
			'vars'      => $vars,
			// LEAD_PHOTO_V1: fotos subidas adjuntas (además de su fila URL).
			'attachments' => $photo_paths,
		) );
		return array_merge( array( 'kind' => 'notify', 'form_id' => $form_id ), $r );
	}

	/**
	 * SUBMISSION_NOTIFY_RESEND_V1: reconstruye el contexto (form + capture) de un
	 * envío YA registrado, a partir de su id en estandar_capture_submissions.
	 * Reutilizable por el reenvío de notificación (CLI) y su dry-run.
	 *
	 * @return array{form_id:int,config:array,capture:array}|null null si no existe.
	 */
	public static function submission_context( int $submission_id )
	{
		$row = self::get_submission( $submission_id );
		if ( ! $row ) {
			return null;
		}
		$form_id = (int) ( $row['source_form_id'] ?? 0 );
		$form    = $form_id > 0 ? self::get( $form_id ) : null;
		if ( ! $form ) {
			return null;
		}
		$config = json_decode( (string) $form['config_json'], true );
		$config = is_array( $config ) ? $config : array();
		$fields = json_decode( (string) ( $row['fields_json'] ?? '' ), true );
		$capture = array(
			'id'               => (int) $row['id'],
			'name'             => (string) $row['name'],
			'email_normalized' => (string) $row['email_normalized'],
			'phone_normalized' => (string) $row['phone_normalized'],
			'message'          => (string) $row['message'],
			'fields'           => is_array( $fields ) ? $fields : array(),
			'operator_name'    => (string) ( $row['operator_name'] ?? '' ),
			'operator_email'   => (string) ( $row['operator_email'] ?? '' ),
		);
		return array( 'form_id' => $form_id, 'config' => $config, 'capture' => $capture );
	}

	/**
	 * SUBMISSION_NOTIFY_RESEND_V1: reenvía la notificación interna de un envío
	 * ya registrado (aviso perdido, destinatario añadido después, etc.) sin
	 * volver a enviar el formulario. Salta la ventana de dedupe por defecto.
	 *
	 * @param int   $submission_id Id en estandar_capture_submissions.
	 * @param array $opts 'to' (CSV) reemplaza el To; 'skip_dedupe' (bool).
	 * @return array Resultado de notify_submission (+ submission_id) o error.
	 */
	public static function notify_existing_submission( int $submission_id, array $opts = array() ): array
	{
		$ctx = self::submission_context( $submission_id );
		if ( ! $ctx ) {
			return array( 'ok' => false, 'kind' => 'notify', 'submission_id' => $submission_id, 'error' => 'submission_no_existe' );
		}
		if ( ! array_key_exists( 'skip_dedupe', $opts ) ) {
			$opts['skip_dedupe'] = true;
		}
		$res = self::notify_submission( $ctx['form_id'], $ctx['config'], $ctx['capture'], $opts );
		$res['submission_id'] = $submission_id;
		return $res;
	}

	/**
	 * SUBMISSION_NOTIFY_RESEND_V1: destinatarios que recibiría el reenvío, sin
	 * enviar (para --dry-run del CLI). Usa la misma resolución que notify_submission.
	 *
	 * @return array{to:array,cc:array,subject:string,enabled:bool}|null
	 */
	public static function preview_existing_notification( int $submission_id, array $opts = array() )
	{
		$ctx = self::submission_context( $submission_id );
		if ( ! $ctx ) {
			return null;
		}
		$m = self::mail_settings( $ctx['config'] );
		$to = self::resolve_notify_to( $ctx['config'], $ctx['capture'] );
		if ( ! empty( $opts['to'] ) ) {
			$to = (string) $opts['to'];
		}
		if ( trim( $to ) === '' ) {
			$to = (string) $m['notify']['to'];
		}
		$to_list = array_values( array_filter( array_map( 'sanitize_email', preg_split( '/[\s,;]+/', $to ) ) ) );
		$cc_list = self::notify_cc( $m['notify'], $to, array() );
		return array(
			'to'      => $to_list,
			'cc'      => array_values( $cc_list ),
			'subject' => (string) $m['notify']['subject'],
			'enabled' => ! empty( $m['notify']['enabled'] ),
		);
	}

	/**
	 * Resuelve el correo del remitente para la autorespuesta.
	 *
	 * Prioridad: campo configurado (`autoresponse.email_field`) → alternativas
	 * comunes (email / mail / correo / email_address / correo_electronico /
	 * email_normalized) → `email_normalized` de la captura → `contact.email`.
	 * Así la confirmación funciona aunque el form nombre su campo distinto
	 * (form #2 usa `mail`, los forms de proyecto usan `email`).
	 */
	private static function resolve_autoresponse_recipient( array $capture, string $field ): string
	{
		$candidates = array();
		foreach ( array_merge( array( $field ), array( 'email', 'mail', 'correo', 'email_address', 'correo_electronico', 'email_normalized' ) ) as $alt ) {
			$alt = trim( (string) $alt );
			if ( $alt !== '' && ! in_array( $alt, $candidates, true ) ) {
				$candidates[] = $alt;
			}
		}
		foreach ( $candidates as $k ) {
			if ( isset( $capture['fields'][ $k ] ) && is_scalar( $capture['fields'][ $k ] ) ) {
				$v = trim( (string) $capture['fields'][ $k ] );
				if ( $v !== '' ) {
					return $v;
				}
			}
		}
		if ( ! empty( $capture['email_normalized'] ) ) {
			return (string) $capture['email_normalized'];
		}
		if ( ! empty( $capture['contact']['email'] ) ) {
			return (string) $capture['contact']['email'];
		}
		return '';
	}

	/** Autorespuesta al remitente por Form (independiente de la notificación). */
	public static function autoresponse_submission( int $form_id, array $config, array $capture ): array
	{
		$m = self::mail_settings( $config );
		if ( empty( $m['autoresponse']['enabled'] ) ) {
			return array( 'ok' => false, 'kind' => 'autoresponse', 'form_id' => $form_id, 'error' => 'disabled' );
		}
		$field = $m['autoresponse']['email_field'];
		$to    = self::resolve_autoresponse_recipient( $capture, $field );
		if ( ! class_exists( 'Estandar\\Core\\Mail' ) ) {
			return array( 'ok' => false, 'kind' => 'autoresponse', 'form_id' => $form_id, 'error' => 'sin_motor' );
		}
		if ( ! \Estandar\Core\Mail::validate_address( $to ) ) {
			return array( 'ok' => false, 'kind' => 'autoresponse', 'form_id' => $form_id, 'error' => 'email_receptor_invalido' );
		}

		// EMAIL_DEDUPE_V1: una sola autorespuesta por destinatario+form en la
		// ventana de reintentos (resubmit del form → NO repetir la confirmación).
		// EMAIL_DEDUPE_EXEMPT_V1: remitentes exentos (pruebas del dueño) saltan
		// la ventana para que su envío repetido vuelva a confirmar.
		if ( ! self::email_dedupe_exempt( strtolower( $to ) ) && ! self::dedupe_email( self::TRANSIENT_AR_DEDUPE, 'ar_' . strtolower( $to ), $form_id ) ) {
			return array( 'ok' => false, 'kind' => 'autoresponse', 'form_id' => $form_id, 'error' => 'dedupe_window' );
		}
		$vars = self::submission_vars( $form_id, $config, $capture );
		$project_slug = (string) ( is_array( $capture['fields'] ?? null ) ? ( $capture['fields']['project_slug'] ?? '' ) : '' );
		$identity = self::mail_identity( $config, $project_slug );
		$vars = array_merge( $vars, self::brand_vars( $identity ) );

		// LEGACY_RICH_V1: restaurar la autorespuesta marketera por proyecto que
		// el ecosistema legacy (LEAD_RESPONSE_ACCELERATION_V1) tenía (cuerpo con
		// invitación + PDF adjunto). Se usa SOLO si el form no define su propio
		// subject/cuerpo: el config del form siempre manda.
		$legacy = self::legacy_project_autoresponse( $project_slug );
		$ar_raw = is_array( $config['autoresponse'] ?? null ) ? $config['autoresponse'] : array();
		$form_subject = trim( (string) ( $ar_raw['subject'] ?? '' ) );
		$form_body    = trim( (string) ( $ar_raw['body'] ?? '' ) );

		$subject = $form_subject !== '' ? $form_subject : ( ! empty( $legacy['subject'] ) ? $legacy['subject'] : $m['autoresponse']['subject'] );
		if ( $form_body !== '' ) {
			$body = $form_body;
		} elseif ( ! empty( $legacy['body'] ) ) {
			$body = '<div style="max-width:560px;margin:0 auto;font-family:Helvetica,Arial,sans-serif;color:#1d2327;font-size:14px;line-height:1.6;">'
				. '{{logo}}'
				. '<div style="padding:16px 0;">' . nl2br( esc_html( $legacy['body'] ) ) . '</div>'
				. '{{footer_estandar}}'
				. '</div>';
		} else {
			$body = $m['autoresponse']['body'] !== ''
				? $m['autoresponse']['body']
				: self::autoresponse_body_default();
		}

		// RECONTACT_AR_V1: variante de autorespuesta para recontactos (reconoce
		// el contacto previo). Config por form (`autoresponse.body_recontact` /
		// `subject_recontact`) o por proyecto (opción legacy por proyecto, mismos
		// marcadores {{…}}). Si no se configura → se mantiene la autorespuesta
		// normal (comportamiento actual, cero configuración = cero cambio).
		if ( self::capture_is_recontact( $capture ) ) {
			$rc_form   = trim( (string) ( $ar_raw['body_recontact'] ?? '' ) );
			$rc_legacy = trim( (string) ( $legacy['body_recontact'] ?? '' ) );
			if ( $rc_form !== '' ) {
				$body = $rc_form;
			} elseif ( $rc_legacy !== '' ) {
				$body = '<div style="max-width:560px;margin:0 auto;font-family:Helvetica,Arial,sans-serif;color:#1d2327;font-size:14px;line-height:1.6;">'
					. '{{logo}}'
					. '<div style="padding:16px 0;">' . nl2br( esc_html( $rc_legacy ) ) . '</div>'
					. '{{footer_estandar}}'
					. '</div>';
			}
			if ( $rc_form !== '' || $rc_legacy !== '' ) {
				$rc_subject = trim( (string) ( $ar_raw['subject_recontact'] ?? '' ) );
				if ( $rc_subject === '' ) { $rc_subject = trim( (string) ( $legacy['subject_recontact'] ?? '' ) ); }
				if ( $rc_subject !== '' ) { $subject = $rc_subject; }
			}
		}

		// Adjunto PDF (config del form `autoresponse.attachment_id` o legacy
		// `pdf_attachment_id` por proyecto) + {{pdf_link}} para el cuerpo.
		$attachments = array();
		$pdf_link    = '';
		$attachment_id = absint( $m['autoresponse']['attachment_id'] ?? 0 );
		if ( $attachment_id <= 0 && ! empty( $legacy['pdf_attachment_id'] ) ) {
			$attachment_id = (int) $legacy['pdf_attachment_id'];
		}
		if ( $attachment_id > 0 ) {
			$file = is_callable( 'get_attached_file' ) ? get_attached_file( $attachment_id ) : false;
			if ( is_string( $file ) && is_readable( $file ) ) {
				$attachments[] = $file;
			}
			if ( is_callable( 'wp_get_attachment_url' ) ) {
				$pdf_url = (string) wp_get_attachment_url( $attachment_id );
				if ( $pdf_url !== '' ) {
					$pdf_link = $pdf_url;
				}
			}
		}

		// Alias legacy de variables: el cuerpo marketero usa {{nombre}}/{{proyecto}}
		// /{{mensaje}}/{{operador}}/{{pdf_link}}/{{landing_link}}… (Core\Mail::render
		// reemplaza {{clave}} por cada clave del array de vars).
		$project_name = (string) ( $identity['project_name'] ?? '' );
		if ( $project_name === '' ) {
			$project_name = $project_slug;
		}
		$vars = array_merge( $vars, array(
			'nombre'         => (string) ( $vars['name'] ?? '' ),
			'proyecto'       => $project_name,
			'mensaje'        => (string) ( $vars['message'] ?? '' ),
			'telefono'       => (string) ( $vars['phone'] ?? '' ),
			'operador'       => (string) ( $identity['sender_name'] ?? ( is_callable( 'get_bloginfo' ) ? get_bloginfo( 'name' ) : '' ) ),
			'cargo'          => '',
			'operator_email' => '',
			'operator_phone' => '',
			'website'        => is_callable( 'home_url' ) ? home_url( '/' ) : '',
			'pdf_link'       => $pdf_link,
			'landing_link'   => (string) ( $legacy['landing_url'] ?? '' ),
			'whatsapp_link'  => self::project_whatsapp_link( $project_slug ),
		) );

		// MAIL_TEMPLATES_V1 · Fase 2: si el form/proyecto eligió plantilla,
		// inyectar el body de la autorespuesta en su slot {{content}}. OFF si no
		// hay plantilla (retrocompatible) o si el body es un doc completo.
		$body = self::decorate_with_template( $body, $config, $project_slug, $identity );

		$r = \Estandar\Core\Mail::send( array(
			'to'          => $to,
			'subject'     => $subject,
			'html'        => $body,
			'alt'         => wp_strip_all_tags( $body ),
			'from_name'   => $m['autoresponse']['sender'],
			'from_email'  => (string) ( $m['autoresponse']['from_email'] ?? '' ),
			'vars'        => $vars,
			'attachments' => $attachments,
		) );
		return array_merge(
			array( 'kind' => 'autoresponse', 'form_id' => $form_id, 'project' => $project_slug, 'pdf_attached' => (bool) $attachments ),
			$r
		);
	}

	/**
	 * Autorespuesta rica del ecosistema legacy (LEAD_RESPONSE_ACCELERATION_V1).
	 *
	 * Vive en wp_options `estandar_leads_autoresponse_settings` (keyed por UUID,
	 * cada entrada con `project_slug`): cuerpo marketero + PDF adjunto + landing
	 * por proyecto. Restaura el formato que se perdió en la migración v2 para
	 * los proyectos que lo tenían configurado (p. ej. demo: Parronales con
	 * preevaluación D.S.19, demo con ficha PDF adjunta, etc.).
	 *
	 * @return array{subject:string, body:string, pdf_attachment_id:int, landing_url:string}
	 */
	private static function legacy_project_autoresponse( string $project_slug ): array
	{
		if ( $project_slug === '' || ! function_exists( 'get_option' ) ) {
			return array();
		}
		$all = get_option( 'estandar_leads_autoresponse_settings', array() );
		if ( ! is_array( $all ) ) {
			return array();
		}
		// Bug fix: con entradas duplicadas del mismo proyecto, gana la de mayor
		// calidad (la que SÍ tiene PDF), no la primera que aparezca (una entrada
		// sin PDF podía enmascarar a la buena).
		$best  = array();
		$best_q = -1;
		foreach ( $all as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['enabled'] ) ) {
				continue;
			}
			$slug = sanitize_key( (string) ( $entry['project_slug'] ?? '' ) );
			if ( $slug !== $project_slug ) {
				continue;
			}
			$cand = array(
				'subject'           => (string) ( $entry['subject'] ?? '' ),
				'body'              => (string) ( $entry['body'] ?? '' ),
				'pdf_attachment_id' => absint( $entry['pdf_attachment_id'] ?? 0 ),
				'landing_url'       => (string) ( $entry['landing_url'] ?? '' ),
				// RECONTACT_AR_V1: variante opcional para recontactos.
				'subject_recontact' => (string) ( $entry['subject_recontact'] ?? '' ),
				'body_recontact'    => (string) ( $entry['body_recontact'] ?? '' ),
			);
			$q = ( $cand['pdf_attachment_id'] > 0 ? 2 : 0 )
				+ ( $cand['subject'] !== '' ? 1 : 0 )
				+ ( $cand['body'] !== '' ? 1 : 0 );
			if ( $q > $best_q ) {
				$best_q = $q;
				$best   = $cand;
			}
		}
		return $best;
	}

	/**
	 * Config del envío DIFERIDO de la autorespuesta (AUTORESPONSE_DELAYED_V1).
	 *
	 * Precedencia: (1) bloque del form `autoresponse_delayed` (si enabled) →
	 * (2) option global por proyecto `estandar_suite_capture_autoresponse_delay`
	 * (`[project_slug] => {enabled, delay_minutes, sender, from_email}`).
	 * El CONTENIDO (subject/body/pdf) se resuelve en el envío (form → legacy
	 * por proyecto → default).
	 */
	public static function delayed_autoresponse_config( array $config, string $project_slug = '' ): array
	{
		$out = array( 'enabled' => false, 'delay_minutes' => 0, 'sender' => '', 'from_email' => '' );
		$fd  = is_array( $config['autoresponse_delayed'] ?? null ) ? $config['autoresponse_delayed'] : array();
		if ( ! empty( $fd['enabled'] ) ) {
			$out['enabled']        = true;
			$out['delay_minutes']  = max( 1, (int) ( $fd['delay_minutes'] ?? 0 ) );
			$out['from_form']      = true;
			$out['subject']        = (string) ( $fd['subject'] ?? '' );
			$out['body']           = (string) ( $fd['body'] ?? '' );
			$out['attachment_id']  = absint( $fd['attachment_id'] ?? 0 );
			$out['landing_url']    = (string) ( $fd['landing_url'] ?? '' );
			$out['sender']         = (string) ( $fd['sender'] ?? '' );
			$out['from_email']     = sanitize_email( (string) ( $fd['from_email'] ?? '' ) );
			return $out;
		}
		if ( function_exists( 'get_option' ) && $project_slug !== '' ) {
			$all = get_option( self::OPTION_DELAY, array() );
			$p   = is_array( $all ) ? ( $all[ $project_slug ] ?? null ) : null;
			if ( is_array( $p ) && ! empty( $p['enabled'] ) ) {
				$out['enabled']       = true;
				$out['delay_minutes'] = max( 1, (int) ( $p['delay_minutes'] ?? 0 ) );
				$out['from_project']  = true;
				$out['sender']        = (string) ( $p['sender'] ?? '' );
				$out['from_email']    = sanitize_email( (string) ( $p['from_email'] ?? '' ) );
			}
		}
		return $out;
	}

	/**
	 * Agenda el envío diferido de la autorespuesta (WP-Cron, un evento único).
	 * Best-effort: nunca bloquea el submit. Se llama tras la ingesta.
	 */
	public static function schedule_delayed_autoresponse( int $form_id, array $config, array $capture ): array
	{
		// ROUTING_FORM_AUTOCONFIG_V1: en forms con campo 'routing' el correo
		// diferido queda apagado automáticamente (evita solape con el routing).
		if ( self::has_routing_field( $config ) ) {
			return array( 'ok' => false, 'error' => 'routing_form' );
		}
		if ( ! function_exists( 'wp_schedule_single_event' ) ) {
			return array( 'ok' => false, 'error' => 'sin_wp_cron' );
		}
		$project_slug = (string) ( is_array( $capture['fields'] ?? null ) ? ( $capture['fields']['project_slug'] ?? '' ) : '' );
		$d = self::delayed_autoresponse_config( $config, $project_slug );
		if ( empty( $d['enabled'] ) || (int) $d['delay_minutes'] <= 0 ) {
			return array( 'ok' => false, 'error' => 'disabled' );
		}
		$capture_id = (int) ( $capture['id'] ?? 0 );
		if ( $capture_id <= 0 ) {
			return array( 'ok' => false, 'error' => 'sin_submission' );
		}
		$minutes = (int) $d['delay_minutes'];
		$when    = time() + $minutes * ( defined( 'MINUTE_IN_SECONDS' ) ? MINUTE_IN_SECONDS : 60 );
		$scheduled = wp_schedule_single_event( $when, self::CRON_DELAYED, array( $capture_id, $form_id, $project_slug ) );
		return array(
			'ok'          => (bool) $scheduled,
			'when'        => gmdate( 'Y-m-d H:i:s', $when ),
			'minutes'     => $minutes,
			'capture_id'  => $capture_id,
			'project'     => $project_slug,
		);
	}

	/** Handler del cron: envía la autorespuesta diferida (1 solo envío por submission). */
	public static function autoresponse_delayed_cron( $capture_id, $form_id, $project_slug ): void
	{
		$capture_id = absint( $capture_id );
		$form_id    = absint( $form_id );
		$project_slug = sanitize_key( (string) $project_slug );
		if ( $capture_id <= 0 ) {
			return;
		}
		if ( get_transient( self::TRANSIENT_DONE . $capture_id ) ) {
			return;
		}
		$row = self::submission_row( $capture_id );
		if ( ! is_array( $row ) ) {
			return;
		}
		$form = self::get( $form_id );
		$config = is_array( $form ) ? ( json_decode( (string) ( $form['config_json'] ?? '{}' ), true ) ?: array() ) : array();
		$capture = self::capture_from_submission( $row );
		// Guarda: lead cerrado (CLIENT/INVALID) → no enviar.
		$email = (string) ( $row['email_normalized'] ?? '' );
		if ( $email !== '' && self::lead_is_closed( $email ) ) {
			return;
		}
		$result = self::send_delayed_autoresponse( $config, $capture, $project_slug );
		if ( ! empty( $result['ok'] ) ) {
			set_transient( self::TRANSIENT_DONE . $capture_id, 1, WEEK_IN_SECONDS );
		}
		do_action( 'estandar_suite_capture_autoresponse_delayed_sent', $capture_id, $result );
	}

	/**
	 * Envía la autorespuesta diferida: contenido marketero (form → legacy por
	 * proyecto → default), PDF adjunto y FIRMADA POR EL OPERADOR (From/Reply-To
	 * = operador asignado en es_leads; si no hay, identidad de la Suite).
	 */
	public static function send_delayed_autoresponse( array $config, array $capture, string $project_slug = '' ): array
	{
		$m = self::mail_settings( $config );
		if ( ! class_exists( 'Estandar\\Core\\Mail' ) ) {
			return array( 'ok' => false, 'error' => 'sin_motor' );
		}
		$to = self::resolve_autoresponse_recipient( $capture, (string) $m['autoresponse']['email_field'] );
		if ( ! \Estandar\Core\Mail::validate_address( $to ) ) {
			return array( 'ok' => false, 'error' => 'email_receptor_invalido' );
		}

		// EMAIL_DEDUPE_V1: una sola autorespuesta DIFERIDA por destinatario+form
		// (los reintentos del form no deben duplicar el correo marketero). Scope
		// separado del envío inmediato: ambos pueden convivir por diseño.
		$df_form_id = (int) ( $capture['content']['source_form_id'] ?? 0 );
		// EMAIL_DEDUPE_EXEMPT_V1: remitentes exentos (pruebas del dueño) saltan
		// la ventana para que su envío repetido vuelva a enviar la diferida.
		if ( ! self::email_dedupe_exempt( strtolower( $to ) ) && ! self::dedupe_email( self::TRANSIENT_AR_DEDUPE, 'ar_delayed_' . strtolower( $to ), $df_form_id ) ) {
			return array( 'ok' => false, 'kind' => 'autoresponse_delayed', 'form_id' => $df_form_id, 'error' => 'dedupe_window' );
		}
		$vars = self::submission_vars( (int) ( $capture['content']['source_form_id'] ?? 0 ), $config, $capture );
		$identity = self::mail_identity( $config, $project_slug );
		$vars = array_merge( $vars, self::brand_vars( $identity ) );

		// Contenido: bloque del form → legacy por proyecto → autorespuesta del form → default.
		$d   = self::delayed_autoresponse_config( $config, $project_slug );
		$legacy = self::legacy_project_autoresponse( $project_slug );
		$ar_raw = is_array( $config['autoresponse'] ?? null ) ? $config['autoresponse'] : array();

		$subject = trim( (string) ( $d['subject'] ?? '' ) );
		if ( $subject === '' ) { $subject = trim( (string) ( $ar_raw['subject'] ?? '' ) ); }
		if ( $subject === '' ) { $subject = (string) ( $legacy['subject'] ?? '' ); }
		if ( $subject === '' ) { $subject = $m['autoresponse']['subject']; }

		$body = trim( (string) ( $d['body'] ?? '' ) );
		if ( $body === '' ) { $body = trim( (string) ( $ar_raw['body'] ?? '' ) ); }
		if ( $body === '' && ! empty( $legacy['body'] ) ) {
			$body = '<div style="max-width:560px;margin:0 auto;font-family:Helvetica,Arial,sans-serif;color:#1d2327;font-size:14px;line-height:1.6;">'
				. '{{logo}}'
				. '<div style="padding:16px 0;">' . nl2br( esc_html( $legacy['body'] ) ) . '</div>'
				. '{{footer_estandar}}'
				. '</div>';
		}
		if ( $body === '' ) { $body = self::autoresponse_body_default(); }

		// RECONTACT_AR_V1 (diferida): misma variante de recontacto que la
		// inmediata — config por form o por proyecto, cero configuración = cero
		// cambio.
		if ( self::capture_is_recontact( $capture ) ) {
			$rc_form   = trim( (string) ( $ar_raw['body_recontact'] ?? '' ) );
			$rc_legacy = trim( (string) ( $legacy['body_recontact'] ?? '' ) );
			if ( $rc_form !== '' ) {
				$body = $rc_form;
			} elseif ( $rc_legacy !== '' ) {
				$body = '<div style="max-width:560px;margin:0 auto;font-family:Helvetica,Arial,sans-serif;color:#1d2327;font-size:14px;line-height:1.6;">'
					. '{{logo}}'
					. '<div style="padding:16px 0;">' . nl2br( esc_html( $rc_legacy ) ) . '</div>'
					. '{{footer_estandar}}'
					. '</div>';
			}
			if ( $rc_form !== '' || $rc_legacy !== '' ) {
				$rc_subject = trim( (string) ( $ar_raw['subject_recontact'] ?? '' ) );
				if ( $rc_subject === '' ) { $rc_subject = trim( (string) ( $legacy['subject_recontact'] ?? '' ) ); }
				if ( $rc_subject !== '' ) { $subject = $rc_subject; }
			}
		}

		// PDF adjunto + {{pdf_link}}.
		$attachments = array();
		$pdf_link    = '';
		$attachment_id = (int) ( $d['attachment_id'] ?? 0 );
		if ( $attachment_id <= 0 ) { $attachment_id = (int) ( $ar_raw['attachment_id'] ?? 0 ); }
		if ( $attachment_id <= 0 ) { $attachment_id = (int) ( $legacy['pdf_attachment_id'] ?? 0 ); }
		if ( $attachment_id > 0 ) {
			$file = is_callable( 'get_attached_file' ) ? get_attached_file( $attachment_id ) : false;
			if ( is_string( $file ) && is_readable( $file ) ) {
				$attachments[] = $file;
			}
			if ( is_callable( 'wp_get_attachment_url' ) ) {
				$pdf_url = (string) wp_get_attachment_url( $attachment_id );
				if ( $pdf_url !== '' ) { $pdf_link = $pdf_url; }
			}
		}

		// Firma: operador asignado (From/Reply-To = operador) → identidad Suite.
		$operator = self::resolve_operator_identity( $to, $project_slug );
		$from_name  = (string) ( $d['from_email'] !== '' ? ( $d['sender'] ?? '' ) : ( $operator['name'] !== '' ? $operator['name'] : $m['autoresponse']['sender'] ) );
		$from_email = (string) ( $d['from_email'] !== '' ? $d['from_email'] : ( $operator['email'] !== '' ? $operator['email'] : $m['autoresponse']['from_email'] ) );
		$reply_to   = (string) ( $operator['email'] !== '' ? $operator['email'] : $m['autoresponse']['from_email'] );

		// Alias legacy de variables.
		$project_name = (string) ( $identity['project_name'] ?? '' );
		if ( $project_name === '' ) { $project_name = $project_slug; }
		$vars = array_merge( $vars, array(
			'nombre'         => (string) ( $vars['name'] ?? '' ),
			'proyecto'       => $project_name,
			'mensaje'        => (string) ( $vars['message'] ?? '' ),
			'telefono'       => (string) ( $vars['phone'] ?? '' ),
			'operador'       => $from_name !== '' ? $from_name : (string) ( $identity['sender_name'] ?? '' ),
			'cargo'          => '',
			'operator_email' => $from_email,
			'operator_phone' => '',
			'website'        => is_callable( 'home_url' ) ? home_url( '/' ) : '',
			'pdf_link'       => $pdf_link,
			'landing_link'   => (string) ( ( $d['landing_url'] ?? '' ) !== '' ? $d['landing_url'] : ( $legacy['landing_url'] ?? '' ) ),
			'whatsapp_link'  => self::project_whatsapp_link( $project_slug ),
		) );

		$r = \Estandar\Core\Mail::send( array(
			'to'          => $to,
			'subject'     => $subject,
			'html'        => $body,
			'alt'         => wp_strip_all_tags( $body ),
			'from_name'   => $from_name,
			'from_email'  => $from_email,
			'reply_to'    => $reply_to,
			'vars'        => $vars,
			'attachments' => $attachments,
		) );
		return array_merge( array( 'kind' => 'autoresponse_delayed', 'project' => $project_slug, 'pdf_attached' => (bool) $attachments, 'from_email' => $from_email ), $r );
	}

	/** Lee una fila de estandar_capture_submissions. */
	public static function submission_row( int $capture_id ): ?array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $capture_id <= 0 ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . Ingestion::TABLE . ' WHERE id = %d', $capture_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/** Reconstruye un payload de captura desde una fila de submission. */
	public static function capture_from_submission( array $row ): array
	{
		return array(
			'source'    => (string) ( $row['source'] ?? 'form' ),
			'content'   => array( 'source_form_id' => (string) ( $row['source_form_id'] ?? '' ), 'message' => (string) ( $row['message'] ?? '' ) ),
			'contact'   => array(
				'name'  => (string) ( $row['name'] ?? '' ),
				'email' => (string) ( $row['email_normalized'] ?? '' ),
				'phone' => (string) ( $row['phone_normalized'] ?? '' ),
			),
			'fields'    => json_decode( (string) ( $row['fields_json'] ?? '{}' ), true ) ?: array(),
			'attribution' => array(
				'utm_source'   => (string) ( $row['utm_source'] ?? '' ),
				'utm_medium'   => (string) ( $row['utm_medium'] ?? '' ),
				'utm_campaign' => (string) ( $row['utm_campaign'] ?? '' ),
				'landing_url'  => (string) ( $row['landing_url'] ?? '' ),
				'referrer'     => (string) ( $row['referrer'] ?? '' ),
			),
			'email_normalized' => (string) ( $row['email_normalized'] ?? '' ),
			'id'          => (int) ( $row['id'] ?? 0 ),
		);
	}

	/** Lead cerrado (CLIENT/INVALID) para un email → no enviar diferido. */
	private static function lead_is_closed( string $email ): bool
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $email === '' ) {
			return false;
		}
		$status = $wpdb->get_var( $wpdb->prepare(
			'SELECT status FROM ' . $wpdb->prefix . 'es_leads WHERE email = %s ORDER BY id DESC LIMIT 1', $email
		) );
		return in_array( strtoupper( (string) $status ), array( 'CLIENT', 'INVALID' ), true );
	}

	/** Resuelve nombre/email del operador asignado (es_leads) para firmar el envío. */
	private static function resolve_operator_identity( string $email, string $project_slug ): array
	{
		global $wpdb;
		$out = array( 'name' => '', 'email' => '' );
		if ( ! isset( $wpdb ) || $email === '' ) {
			return $out;
		}
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT assigned_operator_name, assigned_operator_email FROM ' . $wpdb->prefix . 'es_leads
			 WHERE email = %s AND assigned_operator_email <> \'\' ORDER BY id DESC LIMIT 1', $email
		), ARRAY_A );
		if ( is_array( $row ) ) {
			$out['name']  = sanitize_text_field( (string) ( $row['assigned_operator_name'] ?? '' ) );
			$out['email'] = sanitize_email( (string) ( $row['assigned_operator_email'] ?? '' ) );
		}
		if ( $out['email'] !== '' || $out['name'] !== '' ) {
			return $out;
		}
		// Fallback: operador activo del proyecto (es_operators).
		if ( $project_slug !== '' ) {
			$op = $wpdb->get_row( $wpdb->prepare(
				"SELECT name, email FROM {$wpdb->prefix}es_operators WHERE active = 1 AND project_slug = %s AND email <> '' ORDER BY name ASC LIMIT 1",
				$project_slug
			), ARRAY_A );
			if ( is_array( $op ) ) {
				$out['name']  = sanitize_text_field( (string) ( $op['name'] ?? '' ) );
				$out['email'] = sanitize_email( (string) ( $op['email'] ?? '' ) );
			}
		}
		return $out;
	}

	/**
	 * Rollback de migración: elimina formularios nativos migrados (marcador) y
	 * los adoptados previamente (id presente en estandar_forms). Opcionalmente
	 * scoped a un id. Nunca toca la tabla legacy.
	 */
	public static function rollback_migration( int $form_id = 0 ): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return 0;
		}
		$table = $wpdb->prefix . self::TABLE;
		$legacy_table = $wpdb->prefix . 'estandar_forms';
		$sql = "DELETE FROM {$table} WHERE config_json LIKE '%\"" . self::MIG_MARKER . "\"%' OR id IN (SELECT id FROM {$legacy_table})";
		if ( $form_id > 0 ) {
			$sql .= $wpdb->prepare( ' AND id = %d', $form_id );
		}
		return (int) $wpdb->query( $sql );
	}

	/**
	 * Campos renderizables de un config (fuente canónica única).
	 *
	 * Normaliza `sections` (nativo v2) o `fields_json` (legacy plano) a una lista
	 * plana de campos. Usado por el renderer y la ingestión; independiente del id.
	 */
	public static function renderable_fields( array $config ): array
	{
		if ( isset( $config['sections'] ) && is_array( $config['sections'] ) && $config['sections'] ) {
			$fields = array();
			foreach ( $config['sections'] as $sec ) {
				if ( ! is_array( $sec ) || empty( $sec['fields'] ) || ! is_array( $sec['fields'] ) ) {
					continue;
				}
				foreach ( $sec['fields'] as $f ) {
					if ( is_array( $f ) ) {
						$fields[] = $f;
					}
				}
			}
			return $fields;
		}
		$flat = isset( $config['fields_json'] ) ? $config['fields_json'] : array();
		if ( is_string( $flat ) ) {
			$flat = json_decode( $flat, true );
		}
		return is_array( $flat ) ? $flat : array();
	}

	/**
	 * ROUTING_FORM_AUTOCONFIG_V1: ¿el form declara un campo de tipo 'routing'?
	 * Un campo `routing` (dropdown del frontend) designa por sí mismo el destino
	 * de la notificación (`_routing_emails`), el proyecto y el modo funnel/simple.
	 * Estos forms tienen un comportamiento de correo especial y automático:
	 *   - se APAGA el aviso genérico (no se usa notify_emails) y el correo
	 *     diferido; se MANTIENE la autorespuesta al que completó;
	 *   - el correo que sale (el del routing) usa el TEMPLATE DEFAULT global.
	 */
	public static function has_routing_field( array $config ): bool
	{
		foreach ( self::renderable_fields( $config ) as $f ) {
			if ( is_array( $f ) && strtolower( (string) ( $f['type'] ?? '' ) ) === 'routing' ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Fusión determinística y no destructiva de config_json.
	 *
	 * Protege contra guardados parciales/vacíos: NUNCA reemplaza secciones
	 * ausentes del POST por arrays vacíos ni deja que un post vacío borre un
	 * documento existente. Las claves del post prevalecen; las claves del actual
	 * ausentes en el post se conservan.
	 *
	 * @return string JSON resultante (o el actual si el post es inválido/vacío).
	 */
	public static function merge_config_json( string $current, string $posted ): string
	{
		$cur = json_decode( $current, true );
		$cur = is_array( $cur ) ? $cur : array();
		$pos = json_decode( $posted, true );
		if ( ! is_array( $pos ) || ! $pos ) {
			// Post vacío/inválido: conservar el documento actual.
			return wp_json_encode( $cur, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
		foreach ( $pos as $key => $value ) {
			if ( $key === 'sections' ) {
				if ( is_array( $value ) && ! $value && isset( $cur['sections'] ) && ! empty( $cur['sections'] ) ) {
					// Post con sections vacías sobre un documento con contenido:
					// se conserva (no borrar por un guardado parcial).
					continue;
				}
				$cur['sections'] = self::merge_sections( $cur['sections'] ?? array(), $value );
			} else {
				$cur[ $key ] = $value;
			}
		}
		return wp_json_encode( $cur, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Fusión profunda de sections (campos) NO destructiva.
	 *
	 * Protege el layout de los campos: si el payload entrante (p. ej. un
	 * guardado que solo renombró el form) reenvía un campo SIN su
	 * `column_width` o con el valor por defecto, se conserva el `column_width`
	 * del documento existente. Evita que un cambio no relacionado desconfigure
	 * los anchos de columnas de todo el formulario.
	 *
	 * Matching de campos: por `name` (estable). Los campos nuevos del payload
	 * se agregan tal cual; los ausentes en el payload se conservan.
	 */
	private static function merge_sections( array $current, $posted ): array
	{
		if ( ! is_array( $posted ) ) {
			return is_array( $current ) ? $current : array();
		}
		$out = array();
		$cur_by_name = array();
		foreach ( (array) $current as $sec ) {
			if ( ! is_array( $sec ) ) {
				continue;
			}
			$name = sanitize_key( (string) ( $sec['name'] ?? '' ) );
			if ( $name === '' ) {
				$name = 'title:' . sanitize_key( (string) ( $sec['title'] ?? '' ) );
			}
			$cur_by_name[ $name ] = $sec;
		}
		foreach ( $posted as $sec ) {
			if ( ! is_array( $sec ) ) {
				continue;
			}
			$name = sanitize_key( (string) ( $sec['name'] ?? '' ) );
			if ( $name === '' ) {
				$name = 'title:' . sanitize_key( (string) ( $sec['title'] ?? '' ) );
			}
			if ( $name === '' ) {
				$out[] = $sec;
				continue;
			}
			if ( isset( $cur_by_name[ $name ] ) ) {
				$sec = self::merge_fields_layout( $cur_by_name[ $name ], $sec );
			}
			$out[] = $sec;
		}
		// Campos presentes en el documento actual pero ausentes en el payload:
		// se conservan (protege contra guardados parciales).
		$posted_names = array();
		foreach ( (array) $posted as $sec ) {
			if ( is_array( $sec ) ) {
				$n = sanitize_key( (string) ( $sec['name'] ?? '' ) );
				if ( $n === '' ) {
					$n = 'title:' . sanitize_key( (string) ( $sec['title'] ?? '' ) );
				}
				$posted_names[] = $n;
			}
		}
		foreach ( $cur_by_name as $name => $sec ) {
			if ( ! in_array( $name, $posted_names, true ) ) {
				$out[] = $sec;
			}
		}
		return $out;
	}

	/**
	 * Protege el layout de los campos de una sección al fusionar.
	 *
	 * Regla: para cada campo con el mismo `name`, si el entrante NO trae
	 * `column_width` o lo trae como vacío/default (1), se conserva el
	 * `column_width` del documento existente. Cualquier otra propiedad del
	 * entrante prevalece (ediciones reales de label/tipo/opciones).
	 */
	private static function merge_fields_layout( array $existing_sec, array $new_sec ): array
	{
		$cur_fields = isset( $existing_sec['fields'] ) && is_array( $existing_sec['fields'] ) ? $existing_sec['fields'] : array();
		$new_fields = isset( $new_sec['fields'] ) && is_array( $new_sec['fields'] ) ? $new_sec['fields'] : array();
		if ( empty( $cur_fields ) || empty( $new_fields ) ) {
			return $new_sec;
		}
		$cur_by_name = array();
		foreach ( $cur_fields as $f ) {
			if ( is_array( $f ) ) {
				$n = sanitize_key( (string) ( $f['name'] ?? '' ) );
				if ( $n !== '' ) {
					$cur_by_name[ $n ] = $f;
				}
			}
		}
		if ( empty( $cur_by_name ) ) {
			return $new_sec;
		}
		$merged = array();
		foreach ( $new_fields as $i => $f ) {
			if ( ! is_array( $f ) ) {
				$merged[] = $f;
				continue;
			}
			$n = sanitize_key( (string) ( $f['name'] ?? '' ) );
			$has_cw = array_key_exists( 'column_width', $f );
			$cw     = $f['column_width'] ?? 1;
			if ( isset( $cur_by_name[ $n ] ) && ( ! $has_cw || $cw === '' || (int) $cw <= 1 ) ) {
				$f['column_width'] = $cur_by_name[ $n ]['column_width'] ?? $f['column_width'] ?? 1;
			}
			$merged[] = $f;
		}
		$new_sec['fields'] = $merged;
		return $new_sec;
	}

	/**
	 * Política canónica de visibilidad tras una respuesta (por formulario).
	 *
	 *   repeat_visibility.mode      → always_visible | hide_temporarily
	 *   repeat_visibility.duration  → número
	 *   repeat_visibility.unit      → minutes | hours | days
	 *   repeat_visibility.behavior  → show_again (al expirar) | show_again
	 *   repeat_visibility.message   → mensaje alternativo mientras oculto
	 *
	 * Fallback seguro: si no existe o es inválido → always_visible (el formulario
	 * siempre se muestra). Nunca ocultamiento global.
	 */
	public static function repeat_visibility( array $config ): array
	{
		$rv = isset( $config['repeat_visibility'] ) && is_array( $config['repeat_visibility'] ) ? $config['repeat_visibility'] : array();
		$mode = sanitize_key( (string) ( $rv['mode'] ?? '' ) );
		$mode = $mode === 'hide_temporarily' ? 'hide_temporarily' : 'always_visible';
		$unit = sanitize_key( (string) ( $rv['unit'] ?? '' ) );
		$unit = in_array( $unit, array( 'minutes', 'hours', 'days' ), true ) ? $unit : 'minutes';
		$duration = (int) ( $rv['duration'] ?? 0 );
		if ( $duration < 0 ) {
			$duration = 0;
		}
		$behavior = sanitize_key( (string) ( $rv['behavior'] ?? '' ) );
		$behavior = in_array( $behavior, array( 'show_again' ), true ) ? $behavior : 'show_again';
		$message  = trim( (string) ( $rv['message'] ?? '' ) );
		return array(
			'mode'     => $mode,
			'duration' => $duration,
			'unit'     => $unit,
			'behavior' => $behavior,
			'message'  => $message !== '' ? $message : 'Gracias, ya recibimos tu mensaje. Vuelve pronto.',
			'enabled'  => $mode === 'hide_temporarily' && $duration > 0,
		);
	}

	/** Segundos de duración según unidad (determinístico). */
	public static function repeat_visibility_seconds( array $policy ): int
	{
		$mult = array( 'minutes' => 60, 'hours' => 3600, 'days' => 86400 );
		$u = isset( $mult[ $policy['unit'] ] ) ? $mult[ $policy['unit'] ] : 60;
		return (int) $policy['duration'] * $u;
	}

	/**
	 * Motivos de recontacto. Configurables por formulario vía
	 * `repeat_reason_options_text` (una línea por opción: "codigo=Etiqueta" o
	 * solo "Etiqueta" → código auto-slug). Sin configuración se usan los
	 * códigos canónicos (contrato estable con repeat_priority()).
	 */
	public static function repeat_reasons( array $config = array() ): array
	{
		$canonical = array(
			'not_contacted_yet' => 'Aún no me contactan',
			'add_info'          => 'Quiero agregar información',
			'request_changed'   => 'Mi solicitud cambió',
			'urgent'            => 'Necesito atención urgente',
			'other'             => 'Otro',
		);
		$text = trim( (string) ( $config['repeat_reason_options_text'] ?? '' ) );
		if ( $text === '' ) {
			return $canonical;
		}
		$out = array();
		foreach ( preg_split( "/\r\n|\n|\r/", $text ) as $line ) {
			$line = trim( $line );
			if ( $line === '' || $line[0] === '#' ) {
				continue;
			}
			$parts = explode( '=', $line, 2 );
			if ( count( $parts ) === 2 ) {
				$code  = sanitize_key( $parts[0] );
				$label = trim( $parts[1] );
			} else {
				$label = $line;
				$code  = self::route_key( $label );
				$code  = sanitize_key( preg_replace( '/\s+/', '_', $code ) );
			}
			if ( $code !== '' && $label !== '' ) {
				$out[ $code ] = $label;
			}
		}
		return $out !== array() ? $out : $canonical;
	}

	/**
	 * Animación de éxito (SUCCESS_ANIMATION_V1).
	 * Config: success_animation = 'default' (GIF embebido, POR DEFECTO) |
	 * 'none' (sin animación) | 'custom' (success_animation_url).
	 * @return array { mode, url }
	 */
	public static function success_animation( array $config ): array
	{
		$mode = sanitize_key( (string) ( $config['success_animation'] ?? 'default' ) );
		if ( ! in_array( $mode, array( 'default', 'none', 'custom' ), true ) ) {
			$mode = 'default';
		}
		$url = '';
		if ( $mode === 'custom' ) {
			$raw = trim( (string) ( $config['success_animation_url'] ?? '' ) );
			$url = esc_url_raw( $raw );
			if ( $url === '' ) {
				$mode = 'default';
			}
		}
		if ( $mode === 'default' ) {
			$file = defined( 'ESTANDAR_SUITE_CAPTURE_FILE' ) ? ESTANDAR_SUITE_CAPTURE_FILE : __FILE__;
			$url  = plugin_dir_url( $file ) . 'assets/animations/success-default.gif';
		}
		return array( 'mode' => $mode, 'url' => $url );
	}

	/**
	 * Menú de recontacto tras reenvío (REPEAT_MENU_V1).
	 * Activado POR DEFECTO: clave repeat_reason.enabled ausente o truthy.
	 */
	public static function repeat_menu_enabled( array $config ): bool
	{
		return ! isset( $config['repeat_reason']['enabled'] ) || ! empty( $config['repeat_reason']['enabled'] );
	}

	/**
	 * Prioridad/escalación determinística ante recontacto. Sin IA.
	 *
	 * @return array { score_delta, escalation, reason, notes }
	 */
	public static function repeat_priority( string $code, int $submission_count, bool $has_contact ): array
	{
		$code = sanitize_key( (string) $code );
		$escalate = false;
		$notes = '';
		$delta = 5;
		if ( $code === 'not_contacted_yet' ) {
			// Recontacto sin contacto comercial previo → escalación.
			$delta = 30;
			$escalate = ! $has_contact;
			$notes = $escalate ? 'recontacto sin contacto comercial previo' : '';
		} elseif ( $code === 'urgent' ) {
			$delta = 50;
			$escalate = true;
			$notes = 'atención urgente solicitada';
		} elseif ( $code === 'request_changed' ) {
			$delta = 20;
		} elseif ( $code === 'add_info' ) {
			$delta = 10;
		}
		return array(
			'score_delta' => $delta,
			'escalation'  => $escalate,
			'reason'      => $code,
			'notes'       => $notes,
		);
	}

	/**
	 * Registra el motivo de un envío repetido asociado a un submission.
	 *
	 * Persiste en la submission, emite evento auditable, recalcula prioridad y
	 * notifica al operador (Core\Communications) UNA sola vez por submission.
	 *
	 * @return array { ok, code, capture_id, priority, notified, error? }
	 */
	public static function record_repeat_reason( int $capture_id, string $code, string $note = '', array $config = array() ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $capture_id <= 0 ) {
			return array( 'ok' => false, 'error' => 'no_db' );
		}
		$reasons = self::repeat_reasons( $config );
		$code = sanitize_key( (string) $code );
		if ( ! isset( $reasons[ $code ] ) ) {
			return array( 'ok' => false, 'code' => $code, 'capture_id' => $capture_id, 'error' => 'reason_invalido' );
		}
		$sub = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . Ingestion::TABLE . ' WHERE id = %d', $capture_id ), ARRAY_A );
		if ( ! is_array( $sub ) ) {
			return array( 'ok' => false, 'code' => $code, 'capture_id' => $capture_id, 'error' => 'submission_inexistente' );
		}
		if ( ! empty( $sub['repeat_reason'] ) ) {
			return array( 'ok' => false, 'code' => $code, 'capture_id' => $capture_id, 'error' => 'ya_registrado' );
		}
		$note = trim( sanitize_textarea_field( (string) $note ) );

		$wpdb->update(
			$wpdb->prefix . Ingestion::TABLE,
			array( 'repeat_reason' => $code, 'repeat_note' => $note ),
			array( 'id' => $capture_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		// Contexto determinístico: count de submissions por email + contacto previo.
		$count = 0;
		$has_contact = false;
		if ( $sub['email_normalized'] !== '' ) {
			$count = (int) $wpdb->get_var( $wpdb->prepare(
				'SELECT COUNT(*) FROM ' . $wpdb->prefix . Ingestion::TABLE . ' WHERE email_normalized = %s AND source = %s',
				$sub['email_normalized'], (string) ( $sub['source'] ?? 'form' )
			) );
			$has_contact = false;
			if ( self::pipeline_table_exists() ) {
				$has_contact = (bool) $wpdb->get_var( $wpdb->prepare(
					'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'estandar_flow_pipeline
					 WHERE entity_type = %s AND entity_id IN (
					   SELECT id FROM ' . $wpdb->prefix . Ingestion::TABLE . ' WHERE email_normalized = %s
					 ) AND stage <> %s',
					'capture', $sub['email_normalized'], 'NEW'
				) );
			}
		}
		$priority = self::repeat_priority( $code, $count, $has_contact );

		// Evento comercial auditable.
		$payload = array(
			'event'        => 'capture_repeat_reason',
			'capture_id'   => $capture_id,
			'uuid'         => (string) $sub['capture_uuid'],
			'code'         => $code,
			'label'        => $reasons[ $code ],
			'note'         => $note,
			'email'        => (string) $sub['email_normalized'],
			'submissions'  => $count,
			'first_at'     => (string) $sub['created_at'],
			'priority'     => $priority,
		);
		if ( class_exists( 'Estandar\\Core\\Telemetry' ) ) {
			\Estandar\Core\Telemetry::emit( 'info', 'CAPTURE_REPEAT', 'Motivo de recontacto: ' . $reasons[ $code ], $payload );
		}
		do_action( 'estandar_suite_capture_repeat_reason', $payload );

		// Notificación contextual al operador (motor único Core\Mail), una sola vez.
		$notified = array( 'sent' => false, 'to' => array(), 'error' => 'disabled' );
		$lead = self::pipeline_for_capture( $capture_id );
		$operator = '';
		if ( $lead && self::pipeline_table_exists() ) {
			$op = $wpdb->get_var( $wpdb->prepare(
				'SELECT assigned_operator FROM ' . $wpdb->prefix . 'estandar_flow_pipeline WHERE id = %d', (int) $lead['id']
			) );
			$operator = is_string( $op ) ? $op : '';
		}
		$to = array();
		if ( $operator !== '' && \Estandar\Core\Mail::validate_address( $operator ) ) {
			$to[] = $operator;
		}
		$ms_to = \Estandar\Core\Mail::normalize_addresses( self::mail_settings( $config )['notify']['to'] );
		if ( is_array( $ms_to ) ) {
			$to = array_merge( $to, $ms_to );
		}
		$to = array_values( array_unique( array_filter( $to, static function ( $a ) { return \Estandar\Core\Mail::validate_address( $a ); } ) ) );
		if ( $to ) {
			$form_name = '';
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT name FROM ' . $wpdb->prefix . self::TABLE . ' WHERE id = %d', (int) $sub['source_form_id'] ), ARRAY_A );
			$form_name = $row['name'] ?? '';
			$link = admin_url( 'admin.php?page=estandar-suite-flow&lead=' . ( $lead ? (int) $lead['id'] : 0 ) );
			// REPEAT_NOTIFY_V1: correo de la empresa (mismo remitente/formato que
			// el resto de la Suite): cabecera + "declara" + detalle del motivo.
			$ms = self::mail_settings( $config );
			$sub_fields = json_decode( (string) ( $sub['fields_json'] ?? '{}' ), true );
			$sub_fields = is_array( $sub_fields ) ? $sub_fields : array();
			$vars = array_merge( self::brand_vars( self::mail_identity( $config, (string) ( $sub_fields['project_slug'] ?? '' ) ) ), array(
				'reason_label'      => esc_html( $reasons[ $code ] ),
				'reason_note_block' => $note !== '' ? '<p><strong>Detalle:</strong> ' . esc_html( $note ) . '</p>' : '',
				'form_name'         => esc_html( $form_name ),
				'email'             => esc_html( (string) $sub['email_normalized'] ),
				'count'             => (int) $count,
				'lead_link'         => esc_url( $link ),
			) );
			$html = '<div style="max-width:560px;margin:0 auto;font-family:Helvetica,Arial,sans-serif;color:#1d2327;font-size:14px;line-height:1.6;">{{logo}}'
				. '<p style="margin:16px 0 12px;"><strong>Recontacto declarado</strong> — la persona que ya había enviado este formulario volvió a enviarlo y declaró que:</p>'
				. '<p style="padding:12px;border-left:4px solid #f95b1f;background:#fff7f2;border-radius:6px;margin:0 0 12px;"><strong>{{reason_label}}</strong></p>'
				. '{{reason_note_block}}'
				. '<p><strong>Formulario:</strong> {{form_name}}<br><strong>Email:</strong> {{email}}<br><strong>Envíos de esta persona:</strong> {{count}}</p>'
				. '<p><a href="{{lead_link}}">Ver lead</a></p>'
				. '{{footer_estandar}}</div>';
			$r = \Estandar\Core\Mail::send( array(
				'to'         => $to,
				'subject'    => '[Recontacto] ' . $reasons[ $code ] . ' — ' . ( $form_name !== '' ? $form_name : 'form #' . (int) $sub['source_form_id'] ),
				'html'       => $html,
				'alt'        => wp_strip_all_tags( $html ),
				'from_name'  => $ms['notify']['sender'],
				'from_email' => $ms['notify']['from_email'],
				'reply_to'   => (string) $sub['email_normalized'],
				'vars'       => $vars,
			) );
			$notified = array( 'sent' => ! empty( $r['ok'] ), 'to' => $to, 'error' => $r['error'] ?? '' );
		}

		return array(
			'ok'       => true,
			'code'     => $code,
			'capture_id' => $capture_id,
			'priority' => $priority,
			'notified' => $notified,
		);
	}

	/**
	 * Recuperación idempotente y no destructiva desde legacy.
	 *
	 * Reconstruye el config nativo desde estandar_forms y lo FUSIONA con el actual
	 * (preserva claves no-legacy como sections, repeat_visibility, autoresponse).
	 * $dry_run=true (default) NO escribe: solo calcula y reporta el diff.
	 * Con $dry_run=false persiste si hay diferencia. Nunca destruye datos.
	 *
	 * @return array { ok, id, restored, merged, diff, reasons, wrote, origin }
	 */
	public static function restore_legacy_config( int $legacy_id, bool $dry_run = true ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array( 'ok' => false, 'id' => $legacy_id, 'reasons' => array( 'no_db' ) );
		}
		$legacy = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'estandar_forms WHERE id = %d', $legacy_id ), ARRAY_A );
		if ( ! is_array( $legacy ) ) {
			return array( 'ok' => false, 'id' => $legacy_id, 'reasons' => array( 'legacy_inexistente' ) );
		}
		$current = self::get( $legacy_id );
		if ( ! $current ) {
			return array( 'ok' => false, 'id' => $legacy_id, 'reasons' => array( 'v2_inexistente' ) );
		}
		$type = self::legacy_form_type_map( (string) ( $legacy['form_type'] ?? '' ) );
		$rebuilt = self::legacy_to_native( $legacy, $type );
		$rebuilt_cfg = json_decode( (string) $rebuilt['config_json'], true );
		$rebuilt_cfg = is_array( $rebuilt_cfg ) ? $rebuilt_cfg : array();
		$current_cfg = json_decode( (string) $current['config_json'], true );
		$current_cfg = is_array( $current_cfg ) ? $current_cfg : array();

		$has_content = ! empty( $current_cfg['sections'] ) || ! empty( $current_cfg['fields_json'] );
		$merged = $current_cfg;
		foreach ( $rebuilt_cfg as $k => $v ) {
			if ( ! array_key_exists( $k, $merged ) || $merged[ $k ] === '' || $merged[ $k ] === null ) {
				$merged[ $k ] = $v;
			}
		}
		// Protección: si el actual ya tiene contenido de campos, no lo pisamos.
		if ( $has_content && isset( $merged['fields_json'] ) && ! empty( $current_cfg['fields_json'] ) ) {
			$merged['fields_json'] = $current_cfg['fields_json'];
		}
		$before = wp_json_encode( $current_cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$after  = wp_json_encode( $merged, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$restored = $before !== $after;
		$wrote = false;
		if ( ! $dry_run && $restored ) {
			$wpdb->update(
				$wpdb->prefix . self::TABLE,
				array( 'config_json' => $after, 'updated_at' => current_time( 'mysql' ) ),
				array( 'id' => (int) $legacy_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			$wrote = true;
		}
		$merged_fields = self::renderable_fields( $merged );
		return array(
			'ok'           => true,
			'id'           => (int) $legacy_id,
			'restored'     => $restored,
			'wrote'        => $wrote,
			'dry_run'      => $dry_run,
			'origin'       => 'estandar_forms#' . (int) $legacy['id'],
			'merged'       => $merged,
			'fields'       => $merged_fields,
			'fields_count' => count( $merged_fields ),
			'sections'     => isset( $merged['sections'] ) && is_array( $merged['sections'] ) ? count( $merged['sections'] ) : ( $merged_fields ? 1 : 0 ),
			'diff'         => array( 'before' => $before, 'after' => $after ),
			'reasons'      => array(),
		);
	}

	/**
	 * Diagnóstico del formulario (inspección completa, sin escrituras).
	 * @return array estructura legible para CLI/inspect.
	 */
	public static function inspect( int $id ): array
	{
		$f = self::get( $id );
		if ( ! $f ) {
			return array( 'ok' => false, 'id' => $id, 'error' => 'inexistente' );
		}
		$cfg = json_decode( (string) $f['config_json'], true );
		$cfg = is_array( $cfg ) ? $cfg : array();
		$fields = self::renderable_fields( $cfg );
		return array(
			'ok'             => true,
			'id'             => (int) $f['id'],
			'name'           => $f['name'],
			'slug'           => $f['slug'],
			'form_type'      => $f['form_type'],
			'form_context'   => $f['form_context'],
			'status'         => $f['status'],
			'config_len'     => strlen( (string) $f['config_json'] ),
			'config_keys'    => array_keys( $cfg ),
			'sections'       => isset( $cfg['sections'] ) && is_array( $cfg['sections'] ) ? count( $cfg['sections'] ) : 0,
			'fields_total'   => count( $fields ),
			'fields'         => $fields,
			'has_fields'     => count( $fields ) > 0,
			'has_content'    => ! empty( $cfg['sections'] ) || ! empty( $cfg['fields_json'] ),
			'repeat_visibility' => self::repeat_visibility( $cfg ),
		);
	}

	/**
	 * Validación de integridad del documento (pure).
	 * @return array { ok, valid, errors }
	 */
	public static function validate( int $id ): array
	{
		$f = self::get( $id );
		if ( ! $f ) {
			return array( 'ok' => false, 'valid' => false, 'errors' => array( 'inexistente' ) );
		}
		$errors = array();
		$cfg = json_decode( (string) $f['config_json'], true );
		if ( ! is_array( $cfg ) ) {
			return array( 'ok' => false, 'valid' => false, 'errors' => array( 'config_no_es_objeto' ) );
		}
		$has_sections = isset( $cfg['sections'] );
		$has_flat     = isset( $cfg['fields_json'] );
		if ( ! $has_sections && ! $has_flat ) {
			$errors[] = 'sin_secciones_ni_campos';
		}
		if ( $has_sections ) {
			if ( ! is_array( $cfg['sections'] ) ) {
				$errors[] = 'sections_no_es_array';
			} else {
				foreach ( $cfg['sections'] as $i => $sec ) {
					if ( ! is_array( $sec ) || ! isset( $sec['fields'] ) || ! is_array( $sec['fields'] ) ) {
						$errors[] = 'seccion_' . $i . '_sin_campos';
					}
				}
			}
		}
		$rv = self::repeat_visibility( $cfg );
		if ( $rv['mode'] === 'hide_temporarily' && $rv['duration'] <= 0 ) {
			$errors[] = 'repeat_visibility_duracion_invalida';
		}
		return array(
			'ok'     => empty( $errors ),
			'valid'  => empty( $errors ),
			'errors' => $errors,
			'id'     => $id,
		);
	}

	/** Snapshot del config (para diff/restore); sin escrituras. */
	public static function snapshot_config( int $id ): ?array
	{
		$f = self::get( $id );
		if ( ! $f ) {
			return null;
		}
		return array(
			'id'          => (int) $f['id'],
			'name'        => $f['name'],
			'snapshot_at' => gmdate( 'Y-m-d H:i:s' ),
			'config_json' => (string) $f['config_json'],
		);
	}

	/** Clave de metadata donde se preserva la config exclusiva de un tipo. */
	const TYPE_PRESERVE = '_type_preserved';

	/** Claves de config exclusivas de un tipo (se preservan al convertir). */
	public static function type_exclusive_keys( string $type ): array
	{
		if ( $type === 'survey' ) {
			return array( 'survey_config', 'step_by_step', 'hero_title', 'completion_message', 'identification_mode' );
		}
		if ( $type === 'incident' ) {
			return array( 'incidents_config', 'incident_fields', 'follow_up', 'attachments_enabled' );
		}
		return array();
	}

	/**
	 * Reporte de compatibilidad de conversión de tipo (sin escrituras).
	 * Documenta la brecha real: el renderer frontend v2 solo implementa 'lead'.
	 */
	public static function type_conversion_info( int $id, string $new_type ): array
	{
		$f = self::get( $id );
		if ( ! $f ) {
			return array( 'ok' => false, 'id' => $id, 'error' => 'inexistente' );
		}
		$new_type = sanitize_key( (string) $new_type );
		if ( ! isset( self::types()[ $new_type ] ) ) {
			return array( 'ok' => false, 'id' => $id, 'error' => 'tipo_invalido' );
		}
		$old = (string) $f['form_type'];
		$warnings = array();
		if ( $old !== $new_type ) {
			if ( $new_type !== 'lead' ) {
				$warnings[] = 'frontend_renderer_lead_only';
			}
			if ( in_array( $old, array( 'survey', 'incident' ), true ) ) {
				$warnings[] = 'preservara_config_exclusiva';
			}
		}
		return array(
			'ok'       => true,
			'id'       => $id,
			'changed'  => $old !== $new_type,
			'old_type' => $old,
			'new_type' => $new_type,
			'warnings' => $warnings,
		);
	}

	/**
	 * Conversión NO destructiva entre tipos del mismo motor.
	 *
	 * - Conserva identidad, campos compatibles (sections/fields_json), submissions,
	 *   historial, notificaciones y repeat_visibility.
	 * - Mueve la config exclusiva del tipo anterior a `_type_preserved` (metadata
	 *   recuperable, nunca se borra).
	 * - Reactiva la config exclusiva del nuevo tipo si estaba preservada.
	 * - Valida tipo canónico, registra evento `estandar_suite_capture_form_type_changed`
	 *   y es reversible (convertir de vuelta restaura el bloque preservado).
	 *
	 * @return array { ok, changed, old_type, new_type, preserved, rollback }
	 */
	public static function convert_type( int $id, string $new_type, array $raw ): array
	{
		global $wpdb;
		$f = self::get( $id );
		if ( ! $f ) {
			return array( 'ok' => false, 'error' => 'inexistente' );
		}
		$new_type = sanitize_key( (string) $new_type );
		if ( ! isset( self::types()[ $new_type ] ) ) {
			return array( 'ok' => false, 'error' => 'tipo_invalido' );
		}
		$old = (string) $f['form_type'];
		if ( $old === $new_type ) {
			$ok = self::update( $id, $raw );
			return array( 'ok' => $ok, 'changed' => false, 'old_type' => $old, 'new_type' => $new_type, 'preserved' => array(), 'rollback' => $old );
		}

		$cfg = json_decode( (string) ( $raw['config_json'] ?? '{}' ), true );
		$cfg = is_array( $cfg ) ? $cfg : array();

		// 1) Mover config exclusiva del tipo anterior a metadata preservada.
		$preserved = array();
		foreach ( self::type_exclusive_keys( $old ) as $k ) {
			if ( array_key_exists( $k, $cfg ) ) {
				$preserved[ $k ] = $cfg[ $k ];
				unset( $cfg[ $k ] );
			}
		}
		// 2) Reactivar config exclusiva del nuevo tipo si estaba preservada.
		$block = isset( $cfg[ self::TYPE_PRESERVE ] ) && is_array( $cfg[ self::TYPE_PRESERVE ] ) ? $cfg[ self::TYPE_PRESERVE ] : array();
		$restored = array();
		if ( isset( $block[ $new_type ] ) && is_array( $block[ $new_type ] ) ) {
			foreach ( self::type_exclusive_keys( $new_type ) as $k ) {
				if ( array_key_exists( $k, $block[ $new_type ] ) ) {
					$cfg[ $k ] = $block[ $new_type ][ $k ];
					unset( $block[ $new_type ][ $k ] );
					$restored[] = $k;
				}
			}
			if ( empty( $block[ $new_type ] ) ) {
				unset( $block[ $new_type ] );
			}
		}
		// 3) Guardar bloque preservado (metadata inactiva recuperable).
		if ( $preserved ) {
			$block[ $old ] = $preserved;
		}
		if ( $block ) {
			$cfg[ self::TYPE_PRESERVE ] = $block;
		} else {
			unset( $cfg[ self::TYPE_PRESERVE ] );
		}

		$raw['config_json'] = wp_json_encode( $cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$raw['form_type']   = $new_type;
		$ok = self::update( $id, $raw );

		$payload = array(
			'event'      => 'capture_form_type_changed',
			'form_id'    => $id,
			'old_type'   => $old,
			'new_type'   => $new_type,
			'preserved'  => array_keys( $preserved ),
			'restored'   => $restored,
		);
		if ( class_exists( 'Estandar\\Core\\Telemetry' ) ) {
			\Estandar\Core\Telemetry::emit( 'info', 'CAPTURE_FORM_TYPE', 'Tipo cambiado: ' . $old . ' → ' . $new_type, $payload );
		}
		do_action( 'estandar_suite_capture_form_type_changed', $payload );

		return array(
			'ok'        => $ok,
			'changed'   => true,
			'old_type'  => $old,
			'new_type'  => $new_type,
			'preserved' => array_keys( $preserved ),
			'restored'  => $restored,
			'rollback'  => $old,
		);
	}
}
