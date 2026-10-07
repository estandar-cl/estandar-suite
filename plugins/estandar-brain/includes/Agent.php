<?php

namespace Estandar\Brain;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Agent — asistente interno que ejecuta peticiones en lenguaje natural vía
 * WP-CLI dentro de la instalación.
 *
 * Reutiliza los motores de la suite (intents/reglas para la NLU, las APIs
 * oficiales v2 y los comandos WP-CLI para ejecutar) sin reimplementar nada:
 *  - plan():  clasifica una petición en acciones concretas (tools).
 *  - execute(): ejecuta una acción a través de su API/comando oficial.
 *  - run():   pipeline completo con dry-run + confirmación + auditoría.
 *
 * Seguridad: las lecturas corren directo; las escrituras exigen --confirm;
 * las destructivas exigen --confirm=destructive. Todo queda en
 * estandar_agent_logs (auditoría, sin huérfanos).
 */
class Agent
{
	/** Tabla de auditoría del agente (v2, esquema via maybe_migrate_schema). */
	const LOG_TABLE = 'estandar_agent_logs';
	const SCHEMA_OPTION = 'estandar_suite_agent_schema';
	const SCHEMA_VERSION = '1';

	/** Catálogo de tools. level: read | write | destructive · scope: internal | public. */
	public static function tools(): array
	{
		return array(
			// ── Públicas (solo lectura + enlaces de UI; las usa el perfil público) ──
			'conocer_sitio' => array(
				'level'          => 'read',
				'scope'          => 'public',
				'default_public' => true,
				'match'          => array(),
				'help'           => 'Responde usando el contenido publicado del sitio (páginas, posts) como fuente, con cita a la URL.',
			),
			'accion_whatsapp' => array(
				'level' => 'read',
				'scope' => 'public',
				'match' => array( 'abre whatsapp', 'abrir whatsapp', 'whatsapp', 'por whatsapp', 'escribeme por whatsapp', 'hablar por whatsapp', 'contactarme por whatsapp', 'tu whatsapp' ),
				'help'  => 'Devuelve el enlace para abrir WhatsApp del negocio (sin tocar el backend).',
			),
			'accion_email' => array(
				'level' => 'read',
				'scope' => 'public',
				'match' => array( 'enviar correo', 'escribir email', 'escribir correo', 'correo de contacto', 'email de contacto', 'mandar correo', 'escribeme al correo', 'email del negocio' ),
				'help'  => 'Devuelve el enlace de correo de contacto del negocio.',
			),
			'accion_ir_pagina' => array(
				'level' => 'read',
				'scope' => 'public',
				'match' => array( 'ir a la pagina', 'llevame a', 'navegar a', 'abrir la pagina', 'muestrame la pagina', 'ver la pagina', 'ir a ' ),
				'help'  => 'Devuelve el enlace a la página del sitio más relacionada.',
			),
			'accion_formulario' => array(
				'level' => 'read',
				'scope' => 'public',
				'match' => array( 'abrir formulario', 'llena el formulario', 'llenar el formulario', 'formulario de contacto', 'formulario de contactos', 'quiero un formulario' ),
				'help'  => 'Lleva al formulario de contacto de la página.',
			),

			// ── Internas (admin / WP-CLI) ──
			'crear_formulario' => array(
				'level'  => 'write',
				'scope'  => 'internal',
				'match'  => array( 'haz un formulario', 'crea un formulario', 'crear formulario', 'haz formulario', 'crea formulario', 'armame un formulario', 'creame un formulario', 'formulario para', 'formulario de' ),
				'help'   => 'Crea un formulario v2 (página/contexto/campos + notificaciones por email).',
			),
			'configurar_email_leads' => array(
				'level'  => 'write',
				'scope'  => 'internal',
				'match'  => array( 'notificaciones al correo', 'notificacion al correo', 'mande notificaciones', 'mandar notificaciones', 'correo del admin', 'al correo del admin', 'a mi gmail', 'al correo de', 'por correo', 'notificar por email', 'email de notificacion' ),
				'help'   => 'Configura el email de notificación de leads (remitente + destinatarios).',
			),
			'listar_formularios' => array(
				'level'  => 'read',
				'scope'  => 'internal',
				'match'  => array( 'listar formularios', 'lista de formularios', 'ver formularios', 'que formularios', 'los formularios' ),
				'help'   => 'Lista los formularios de la suite.',
			),
			'listar_leads' => array(
				'level'  => 'read',
				'scope'  => 'internal',
				'match'  => array( 'listar leads', 'lista de leads', 'ver leads', 'cuantos leads', 'los leads' ),
				'help'   => 'Lista los leads capturados.',
			),
			'listar_plugins' => array(
				'level'  => 'read',
				'scope'  => 'internal',
				'match'  => array( 'listar plugins', 'lista de plugins', 'ver plugins', 'que plugins', 'plugins instalados' ),
				'help'   => 'Lista los plugins de la instalación (WP-CLI).',
			),
			'listar_usuarios' => array(
				'level'  => 'read',
				'scope'  => 'internal',
				'match'  => array( 'listar usuarios', 'lista de usuarios', 'ver usuarios', 'usuarios del sitio' ),
				'help'   => 'Lista los usuarios del sitio.',
			),
			'info_sitio' => array(
				'level'  => 'read',
				'scope'  => 'internal',
				'match'  => array( 'estado del sitio', 'info del sitio', 'resumen del sitio', 'version de wordpress', 'que version', 'estado wordpress', 'diagnostico' ),
				'help'   => 'Resumen de la instalación (versión, URL, admin).',
			),
		);
	}

	/** Tools permitidas para un perfil (public → solo públicas; internal → todas). */
	public static function allowed_tools( string $profile = 'internal' ): array
	{
		$tools = self::tools();
		if ( $profile === 'public' ) {
			foreach ( $tools as $id => $t ) {
				if ( ( $t['scope'] ?? 'internal' ) !== 'public' ) {
					unset( $tools[ $id ] );
				}
			}
		}
		return $tools;
	}

	/** Datos de contacto del negocio para las acciones públicas. */
	public static function contact_data(): array
	{
		$chat = get_option( 'estandar_suite_capture_chatbot_settings', array() );
		$chat = is_array( $chat ) ? $chat : array();
		$email = trim( (string) ( $chat['notify_email_to'] ?? '' ) );
		if ( $email === '' ) {
			$email = get_option( 'admin_email', '' );
		}
		return array(
			'whatsapp' => trim( (string) ( $chat['human_whatsapp_number'] ?? '' ) ),
			'email'    => $email,
		);
	}

	/**
	 * Activación del agente y sus superficies.
	 * keys: bubble_admin (burbuja en backend), agent_internal (REST /agent),
	 *       agent_public (REST /agent-public + shortcode).
	 */
	public static function activation( string $key = '' )
	{
		$opt    = get_option( 'estandar_suite_brain_agent_settings', array() );
		$opt    = is_array( $opt ) ? $opt : array();
		$merged = array_merge( array(
			'bubble_admin'   => 1,
			'agent_internal' => 1,
			'agent_public'   => 1,
		), $opt );
		return $key !== '' ? (int) ( $merged[ $key ] ?? 1 ) : $merged;
	}

	/** Normaliza texto para NLU: minúsculas y sin acentos. */
	public static function normalize( string $text ): string
	{
		$map = array( 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n' );
		return strtolower( strtr( $text, $map ) );
	}

	/** Parte una petición en segmentos por conectores naturales. */
	public static function segments( string $request ): array
	{
		$parts = preg_split( '/(?:\s+(?:y|que|ademas|tambien|despues|luego)\s+|\s*,\s*)/u', $request );
		$out   = array();
		foreach ( (array) $parts as $p ) {
			$p = trim( $p );
			if ( $p !== '' ) {
				$out[] = $p;
			}
		}
		return $out ? $out : array( trim( $request ) );
	}

	/** Emails presentes en el texto (y 'correo del admin' / 'mi gmail'). */
	public static function collect_emails( string $request ): array
	{
		$emails = array();
		if ( preg_match_all( '/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/', $request, $m ) ) {
			foreach ( $m[0] as $e ) {
				$e = sanitize_email( $e );
				if ( $e !== '' && ! in_array( $e, $emails, true ) ) {
					$emails[] = $e;
				}
			}
		}
		$norm = self::normalize( $request );
		if ( preg_match( '/correo del admin|email del admin|correo del administrador/', $norm ) ) {
			$admin = get_option( 'admin_email', '' );
			if ( is_string( $admin ) && $admin !== '' && ! in_array( $admin, $emails, true ) ) {
				array_unshift( $emails, $admin );
			}
		}
		$me = '';
		if ( function_exists( 'wp_get_current_user' ) ) {
			$u  = wp_get_current_user();
			$me = $u && isset( $u->user_email ) ? $u->user_email : '';
		}
		if ( preg_match( '/mi gmail|mi correo|mi email/', $norm ) && $me !== '' && ! in_array( $me, $emails, true ) ) {
			$emails[] = $me;
		}
		return array_values( array_filter( $emails ) );
	}

	/** Clasifica un segmento en una tool del catálogo (null si no coincide). */
	public static function classify( string $segment, string $profile = 'internal' ): ?string
	{
		$norm = self::normalize( $segment );
		foreach ( self::allowed_tools( $profile ) as $id => $tool ) {
			foreach ( $tool['match'] as $kw ) {
				if ( strpos( $norm, self::normalize( $kw ) ) !== false ) {
					return $id;
				}
			}
		}
		return null;
	}

	/** Comandos WP-CLI nativos que el agente puede pasar tal cual. */
	public static function known_wpcli_prefixes(): array
	{
		return array( 'plugin ', 'theme ', 'post ', 'page ', 'user ', 'option ', 'site ', 'core ', 'db ', 'media ', 'term ', 'comment ', 'cache ', 'rewrite ', 'role ', 'menu ', 'widget ', 'cap ', 'cron ', 'config ', 'export ', 'import ', 'scaffold ', 'search-replace ', 'eval ', 'eval-file ', 'help ', 'cache ' );
	}

	/** Nivel de una passthrough WP-CLI (read | write | destructive). */
	public static function classify_passthrough( string $command ): string
	{
		$norm = self::normalize( $command );
		if ( preg_match( '/\b(delete|remove|drop|reset|uninstall|wipe|purge|empty|truncate)\b/', $norm ) ) {
			return 'destructive';
		}
		if ( preg_match( '/\b(update|set|create|add|insert|install|activate|deactivate|import|generate|edit|enable|disable|toggle)\b/', $norm ) ) {
			return 'write';
		}
		return 'read';
	}

	/** Plan de la petición: lista de [tool, params, segment]. */
	public static function plan( string $request, string $profile = 'internal' ): array
	{
		$emails = self::collect_emails( $request );
		$plan   = array();
		foreach ( self::segments( $request ) as $segment ) {
			$tool = self::classify( $segment, $profile );
			if ( $tool === null && $profile === 'public' ) {
				$tool = 'conocer_sitio'; // por defecto, el público responde con el sitio.
			}
			if ( $tool === null ) {
				continue; // interno: el segmento sobrante se atiende como passthrough.
			}
			$params = array( 'emails' => $emails );
			if ( $tool === 'crear_formulario' ) {
				$params = array_merge( $params, self::form_params( $segment ) );
			} elseif ( $tool === 'configurar_email_leads' ) {
				$params['emails'] = $emails ? $emails : array( get_option( 'admin_email', '' ) );
			} elseif ( $tool === 'conocer_sitio' ) {
				$params['query'] = $segment;
			} elseif ( in_array( $tool, array( 'accion_whatsapp', 'accion_email', 'accion_formulario', 'accion_ir_pagina' ), true ) ) {
				$params['query'] = $segment;
			}
			$plan[] = array( 'tool' => $tool, 'params' => $params, 'segment' => $segment );
		}
		return $plan;
	}

	/** Parámetros del formulario: nombre, página y contexto desde el texto. */
	private static function form_params( string $segment ): array
	{
		$norm = self::normalize( $segment );
		$page = '';
		if ( preg_match( '/pagina(?: de)? (?:la |el |los |las )?([a-z0-9_ -]+)/', $norm, $m ) ) {
			$page = trim( preg_replace( '/\s+/', ' ', $m[1] ) );
		}
		$name = '';
		if ( preg_match( '/formulario (?:para|de|contacto)/', $norm ) ) {
			if ( preg_match( '/formulario (?:para|de) (?:la |el |los |las )?([a-z0-9_ -]+)/', $norm, $m2 ) ) {
				$name = trim( preg_replace( '/\s+/', ' ', $m2[1] ) );
			}
		}
		$name = preg_replace( '/^pagina (?:de |de la |de el )?/', '', $name );
		if ( $name === '' && $page !== '' ) {
			$name = $page;
		}
		if ( $name === '' ) {
			$name = 'contacto';
		}
		$context = 'generic';
		foreach ( array( 'inmobiliaria' => 'inmobiliaria', 'contable' => 'accounting_services', 'contabilidad' => 'accounting_services', 'legal' => 'legal', 'retail' => 'commerce_retail' ) as $kw => $ctx ) {
			if ( strpos( $norm, $kw ) !== false ) {
				$context = $ctx;
				break;
			}
		}
		return array( 'name' => ucfirst( $name ), 'page' => $page, 'context' => $context );
	}

	/** Config JSON de un formulario de contacto (esquema v2 del maquetador). */
	private static function contact_form_config( array $emails ): string
	{
		$config = array(
			'fields_json' => array(
				array( 'type' => 'text', 'name' => 'nombre', 'label' => 'Nombre', 'required' => true, 'column_width' => '3' ),
				array( 'type' => 'email', 'name' => 'email', 'label' => 'Email', 'required' => true, 'column_width' => '3' ),
				array( 'type' => 'tel', 'name' => 'telefono', 'label' => 'Teléfono', 'required' => false, 'column_width' => '3' ),
				array( 'type' => 'textarea', 'name' => 'mensaje', 'label' => 'Mensaje', 'required' => false, 'column_width' => '3' ),
			),
			'survey_config'   => null,
			'submit_label'    => 'Enviar',
			'success_message' => '',
			'notify_emails'   => implode( ',', $emails ),
			'webhook_url'     => '',
			'require_recaptcha' => '0',
			'visual_theme'    => '',
			'actions_json'    => null,
			'incidents_config' => null,
		);
		return (string) wp_json_encode( $config );
	}

	/** Ejecuta una acción (con dry-run / confirmación). */
	public static function execute( array $action, bool $dry_run, bool $confirm, bool $destructive ): array
	{
		$tool_id = $action['tool'];
		$tools   = self::tools();
		$tool    = $tools[ $tool_id ] ?? null;
		if ( ! $tool ) {
			return array( 'ok' => false, 'error' => "Tool desconocida: {$tool_id}" );
		}
		$level = $tool['level'];
		if ( $dry_run ) {
			return array( 'ok' => true, 'dry_run' => true, 'summary' => self::summarize( $tool_id, $action['params'] ) );
		}
		if ( $level === 'write' && ! $confirm ) {
			return array( 'ok' => false, 'need_confirm' => true, 'summary' => self::summarize( $tool_id, $action['params'] ) );
		}
		if ( $level === 'destructive' && ! $destructive ) {
			return array( 'ok' => false, 'need_confirm' => true, 'destructive' => true, 'summary' => self::summarize( $tool_id, $action['params'] ) );
		}
		$method = 'do_' . $tool_id;
		if ( method_exists( __CLASS__, $method ) ) {
			return self::$method( $action['params'] );
		}
		return array( 'ok' => false, 'error' => "Sin ejecutor para {$tool_id}." );
	}

	/** Resumen legible de una acción planeada. */
	public static function summarize( string $tool_id, array $params ): string
	{
		switch ( $tool_id ) {
			case 'crear_formulario':
				return sprintf( 'Crear formulario "%s"%s', $params['name'] ?? '', $params['page'] ? " para la página «{$params['page']}»" : '' ) . ( $params['emails'] ? ' · notificar a ' . implode( ', ', $params['emails'] ) : '' );
			case 'configurar_email_leads':
				return 'Configurar email de notificación de leads · destinatarios: ' . implode( ', ', array_filter( $params['emails'] ) );
			case 'listar_formularios':
				return 'Listar formularios de la suite.';
			case 'listar_leads':
				return 'Listar leads capturados.';
			case 'listar_plugins':
				return 'Listar plugins de la instalación (WP-CLI).';
			case 'listar_usuarios':
				return 'Listar usuarios del sitio.';
			case 'info_sitio':
				return 'Resumen de la instalación.';
			case 'conocer_sitio':
				return 'Consultar el contenido del sitio: «' . ( $params['query'] ?? '' ) . '»';
			case 'accion_whatsapp':
				return 'Abrir WhatsApp del negocio.';
			case 'accion_email':
				return 'Abrir correo de contacto.';
			case 'accion_ir_pagina':
				return 'Ir a la página del sitio: «' . ( $params['query'] ?? '' ) . '»';
			case 'accion_formulario':
				return 'Llevar al formulario de contacto.';
			default:
				return 'Acción ' . $tool_id;
		}
	}

	// ── Ejecutores por tool (APIs oficiales de la suite / WP-CLI) ──

	private static function do_crear_formulario( array $p ): array
	{
		if ( ! class_exists( '\Estandar\Capture\Forms' ) ) {
			return array( 'ok' => false, 'error' => 'Capture no está activo; no se puede crear el formulario.' );
		}
		$config = self::contact_form_config( $p['emails'] ?? array() );
		$raw    = array(
			'name'         => $p['name'] ?? 'Contacto',
			'form_type'    => 'lead',
			'form_context' => $p['context'] ?? 'generic',
			'config_json'  => $config,
			'status'       => 'active',
		);
		$id = \Estandar\Capture\Forms::create( $raw );
		if ( ! $id ) {
			return array( 'ok' => false, 'error' => 'No se pudo crear el formulario (nombre requerido).' );
		}
		$msg = "Formulario creado #{$id} («" . ( $p['name'] ?? 'Contacto' ) . '»).';
		if ( ! empty( $p['page'] ) ) {
			$msg .= ' Destino: página «' . $p['page'] . '» (coloca el shortcode del form #' . $id . ').';
		}
		return array( 'ok' => true, 'message' => $msg, 'form_id' => $id );
	}

	private static function do_configurar_email_leads( array $p ): array
	{
		$option = 'estandar_suite_capture_leads_settings';
		$cur    = get_option( $option, array() );
		$cur    = is_array( $cur ) ? $cur : array();
		$emails = array_values( array_filter( $p['emails'] ?? array() ) );
		$sender = get_option( 'admin_email', '' );
		if ( $emails ) {
			// FEATURE_GROUP_NESTED_V1: identidad de correo en el grupo `email`
			// (con fallback a la raíz legacy).
			$email = ( isset( $cur['email'] ) && is_array( $cur['email'] ) ) ? $cur['email'] : array();
			$get   = function ( string $k ) use ( $cur, $email ) { return $email[ $k ] ?? ( $cur[ $k ] ?? '' ); };

			$email['sender_email'] = $sender;
			$email['cc_email']     = isset( $emails[1] ) ? $emails[1] : $emails[0];
			$email['sender_name']  = $get( 'sender_name' ) !== '' ? $get( 'sender_name' ) : get_bloginfo( 'name' );
			if ( $get( 'subject' ) === '' ) {
				$email['subject'] = 'Nuevo Lead Recibido';
			}
			$cur['email'] = $email;
			foreach ( array( 'sender_email', 'cc_email', 'sender_name', 'subject' ) as $lk ) {
				unset( $cur[ $lk ] );
			}
			update_option( $option, $cur );
			return array( 'ok' => true, 'message' => 'Email de notificación configurado · remitente ' . $sender . ' · CC ' . $email['cc_email'] . '.' );
		}
		return array( 'ok' => false, 'error' => 'No se indicó un correo destinatario.' );
	}

	private static function do_listar_formularios( array $p ): array
	{
		if ( ! class_exists( '\Estandar\Capture\Forms' ) ) {
			return array( 'ok' => false, 'error' => 'Capture no está activo.' );
		}
		$rows = \Estandar\Capture\Forms::list();
		return array( 'ok' => true, 'rows' => $rows, 'message' => count( $rows ) . ' formularios.' );
	}

	private static function do_listar_leads( array $p ): array
	{
		if ( ! class_exists( '\Estandar\Core\Admin' ) ) {
			return array( 'ok' => false, 'error' => 'Core no está activo.' );
		}
		$rows = \Estandar\Core\Admin::legacy_rows( 'es_leads', 'ORDER BY id DESC LIMIT 20' );
		return array( 'ok' => true, 'rows' => $rows, 'message' => count( $rows ) . ' leads.' );
	}

	private static function do_listar_plugins( array $p ): array
	{
		$out = self::run_wpcli( 'plugin list --fields=name,status,version' );
		return array( 'ok' => true, 'output' => $out, 'message' => 'Plugins de la instalación.' );
	}

	private static function do_listar_usuarios( array $p ): array
	{
		$out = self::run_wpcli( 'user list --fields=ID,user_login,user_email,roles' );
		return array( 'ok' => true, 'output' => $out, 'message' => 'Usuarios del sitio.' );
	}

	private static function do_info_sitio( array $p ): array
	{
		$version = self::run_wpcli( 'core version' );
		return array(
			'ok'      => true,
			'message' => sprintf( 'WordPress %s · %s · admin %s', trim( $version ), get_bloginfo( 'url' ), get_option( 'admin_email', '' ) ),
		);
	}

	// ── Ejecutores públicos (solo lectura del sitio + enlaces de UI) ──

	private static function do_conocer_sitio( array $p ): array
	{
		$sources = Knowledge::search( (string) ( $p['query'] ?? '' ), 3 );
		if ( ! $sources ) {
			return array( 'ok' => true, 'message' => 'No encontré información sobre eso en el sitio. Pregúntame por otra cosa o usa WhatsApp para contactar al equipo.', 'sources' => array() );
		}
		$excerpt = trim( (string) $sources[0]['excerpt'] );
		$message = $excerpt !== '' ? $excerpt . '…' : (string) $sources[0]['title'];
		return array( 'ok' => true, 'message' => $message, 'sources' => $sources );
	}

	private static function do_accion_whatsapp( array $p ): array
	{
		$wa = self::contact_data()['whatsapp'];
		if ( $wa === '' ) {
			return array( 'ok' => true, 'message' => 'Ahora mismo no tengo un número de WhatsApp configurado. Déjame tus datos y un ejecutivo te contacta.' );
		}
		$digits = preg_replace( '/[^0-9]/', '', $wa );
		$href   = 'https://wa.me/' . $digits . '?text=' . rawurlencode( 'Hola, vengo del sitio web y quiero más información.' );
		return array(
			'ok'      => true,
			'message' => 'Claro, te abro WhatsApp para hablar con un ejecutivo.',
			'actions' => array( array( 'type' => 'whatsapp', 'label' => 'Abrir WhatsApp', 'href' => $href ) ),
		);
	}

	private static function do_accion_email( array $p ): array
	{
		$email = self::contact_data()['email'];
		if ( $email === '' ) {
			return array( 'ok' => true, 'message' => 'No tengo un correo configurado para contactar. Intenta por WhatsApp.' );
		}
		return array(
			'ok'      => true,
			'message' => 'Te preparo el correo de contacto.',
			'actions' => array( array( 'type' => 'mailto', 'label' => 'Escribir correo', 'href' => 'mailto:' . $email ) ),
		);
	}

	private static function do_accion_ir_pagina( array $p ): array
	{
		$sources = Knowledge::search( (string) ( $p['query'] ?? '' ), 1 );
		if ( ! $sources ) {
			return array( 'ok' => true, 'message' => 'No encontré la página. ¿Sobre qué tema buscas?' );
		}
		$s = $sources[0];
		return array(
			'ok'      => true,
			'message' => 'Te llevo a la página.',
			'actions' => array( array( 'type' => 'url', 'label' => $s['title'], 'href' => $s['url'] ) ),
		);
	}

	private static function do_accion_formulario( array $p ): array
	{
		$sources = Knowledge::search( 'contacto', 1 );
		if ( $sources ) {
			$s = $sources[0];
			return array(
				'ok'      => true,
				'message' => 'Te llevo al formulario de contacto.',
				'actions' => array( array( 'type' => 'url', 'label' => 'Formulario de contacto', 'href' => $s['url'] ) ),
			);
		}
		return array( 'ok' => true, 'message' => 'El formulario de contacto está en la página de contacto del sitio.' );
	}

	/** Ejecuta un comando WP-CLI en el mismo proceso y devuelve su salida. */
	public static function run_wpcli( string $command ): string
	{
		if ( ! class_exists( '\WP_CLI' ) ) {
			return 'WP-CLI no disponible.';
		}
		$out = \WP_CLI::runcommand( $command, array( 'return' => true, 'exit_error' => false, 'launch' => false ) );
		return is_string( $out ) ? $out : (string) json_encode( $out );
	}

	/** Procesa una passthrough WP-CLI nativa (p. ej. `plugin list`). */
	public static function run_passthrough( string $command, bool $dry_run, bool $confirm, bool $destructive ): array
	{
		$command = trim( $command );
		$level   = self::classify_passthrough( $command );
		if ( $dry_run ) {
			return array( 'ok' => true, 'dry_run' => true, 'summary' => 'Ejecutar (WP-CLI): ' . $command );
		}
		if ( $level === 'write' && ! $confirm ) {
			return array( 'ok' => false, 'need_confirm' => true, 'summary' => 'Ejecutar (WP-CLI): ' . $command );
		}
		if ( $level === 'destructive' && ! $destructive ) {
			return array( 'ok' => false, 'need_confirm' => true, 'destructive' => true, 'summary' => 'Ejecutar (WP-CLI): ' . $command );
		}
		return array( 'ok' => true, 'output' => self::run_wpcli( $command ), 'summary' => 'Ejecutado (WP-CLI): ' . $command );
	}

	/** Pipeline completo: plan → ejecución → auditoría. */
	public static function run( string $request, array $opts ): array
	{
		$dry_run    = ! empty( $opts['dry_run'] );
		$confirm    = ! empty( $opts['confirm'] );
		$destructive = ! empty( $opts['destructive'] );
		$profile    = (string) ( $opts['profile'] ?? 'internal' );
		$request    = trim( $request );
		$plan       = self::plan( $request, $profile );
		$results    = array();
		foreach ( $plan as $action ) {
			$results[] = self::execute( $action, $dry_run, $confirm, $destructive );
		}
		// Passthrough: solo interno (el público jamás ejecuta WP-CLI).
		if ( $profile !== 'public' ) {
			foreach ( self::segments( $request ) as $segment ) {
				if ( self::classify( $segment, $profile ) !== null ) {
					continue;
				}
				$norm = self::normalize( $segment );
				$cmd  = '';
				foreach ( self::known_wpcli_prefixes() as $prefix ) {
					if ( strpos( $norm, $prefix ) === 0 ) {
						$cmd = $segment;
						break;
					}
				}
				if ( $cmd !== '' ) {
					$results[] = self::run_passthrough( $cmd, $dry_run, $confirm, $destructive );
				}
			}
		}
		self::log( $request, $plan, $results );
		return array( 'request' => $request, 'plan' => $plan, 'results' => $results );
	}

	// ── Auditoría (estandar_agent_logs) ──

	/** Respuesta conversacional de un run (para terminal y chat admin). */
	public static function respond( array $run ): string
	{
		$lines = array();
		foreach ( $run['results'] as $r ) {
			if ( isset( $r['dry_run'] ) ) {
				$lines[] = 'He planeado: ' . $r['summary'];
			} elseif ( isset( $r['need_confirm'] ) ) {
				$lines[] = 'Necesito tu confirmación para: ' . $r['summary'] . ( ! empty( $r['destructive'] ) ? ' (es destructivo)' : '' );
			} elseif ( ! empty( $r['output'] ) ) {
				$lines[] = ( $r['summary'] ?? $r['message'] ?? 'Listo.' );
				$lines[] = (string) $r['output'];
			} else {
				$lines[] = $r['message'] ?? $r['summary'] ?? 'Listo.';
			}
			if ( ! empty( $r['error'] ) ) {
				$lines[] = 'Error: ' . $r['error'];
			}
		}
		if ( ! $run['results'] ) {
			$lines[] = 'No entendí la petición. Prueba: crear_formulario, configurar_email_leads, listar_formularios, listar_leads, listar_plugins, listar_usuarios, info_sitio, o un comando WP-CLI como "option get siteurl".';
		}
		return implode( "\n", $lines );
	}

	public static function log( string $request, array $plan, array $results ): void
	{
		global $wpdb;
		$user = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
		$wpdb->insert(
			$wpdb->prefix . self::LOG_TABLE,
			array(
				'created_at' => current_time( 'mysql' ),
				'request'    => $request,
				'user_email' => $user && isset( $user->user_email ) ? $user->user_email : '',
				'status'     => ! empty( $results[0]['ok'] ) ? 'ok' : 'pending',
				'plan'       => wp_json_encode( $plan ),
				'result'     => wp_json_encode( $results ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/** Historial de auditoría (read-only). */
	public static function history( int $limit = 30 ): array
	{
		if ( ! self::table_exists() ) {
			return array();
		}
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}" . self::LOG_TABLE . ' ORDER BY id DESC LIMIT %d', $limit ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	private static function table_exists(): bool
	{
		global $wpdb;
		$t = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . self::LOG_TABLE ) );
		return $t === $wpdb->prefix . self::LOG_TABLE;
	}

	public static function maybe_migrate_schema(): void
	{
		if ( get_option( self::SCHEMA_OPTION, '' ) !== self::SCHEMA_VERSION ) {
			self::activate_schema();
		}
	}

	private static function activate_schema(): void
	{
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( "CREATE TABLE {$wpdb->prefix}" . self::LOG_TABLE . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			request text NOT NULL,
			user_email varchar(255) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'pending',
			plan longtext,
			result longtext,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY status (status)
		) {$wpdb->get_charset_collate()};" );
		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
	}
}
