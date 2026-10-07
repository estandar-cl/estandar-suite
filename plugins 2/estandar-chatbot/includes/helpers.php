<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ======================================================
 * HELPERS COMUNES DEL PLUGIN
 * Enfoque: seguridad, portabilidad, cero colisiones
 * ======================================================
 */

/**
 * Retorna ruta absoluta a storage interno (plugin/storage/...).
 */
function estandar_chatbot_storage_path( $relative = '' ) {
	// Usar uploads para portabilidad (no depender del path del plugin)
	if ( defined('ESTANDAR_CHATBOT_STORAGE') ) {
		$base = ESTANDAR_CHATBOT_STORAGE;
	} else {
		$uploads = wp_upload_dir();
		$base = trailingslashit( $uploads['basedir'] ) . 'estandar-chatbot/';
	}

	$relative = ltrim( (string) $relative, '/\\' );
	return $base . $relative;
}

/**
 * Devuelve un array de opciones normalizadas
 * (soporta valores serializados o JSON legacy).
 */
function estandar_chatbot_get_option_array( $key, $default = [] ) {
	$val = get_option( $key, $default );

	if ( is_array( $val ) ) {
		return $val;
	}

	if ( is_string( $val ) ) {
		$trim = trim( $val );
		if ( $trim !== '' && ( $trim[0] === '{' || $trim[0] === '[' ) ) {
			$decoded = json_decode( $trim, true );
			if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
				return $decoded;
			}
		}
	}

	return $default;
}

/**
 * Sanitiza texto multi-línea para prompts o contexto.
 */
function estandar_chatbot_sanitize_multiline_text( $text ) {
	$text = (string) $text;
	$text = wp_kses_post( $text );
	$text = str_replace( ["\r\n", "\r"], "\n", $text );
	return trim( $text );
}

/**
 * Sanitiza el prompt de sistema guardado en opciones (texto plano, longitud acotada).
 *
 * @param mixed $value Valor crudo desde el formulario de ajustes.
 * @return string
 */
function estandar_chatbot_sanitize_system_prompt_option( $value ) {
	$value = (string) wp_unslash( $value );
	$value = wp_check_invalid_utf8( $value );
	$value = str_replace( [ "\r\n", "\r" ], "\n", $value );
	if ( function_exists( 'mb_strlen' ) && mb_strlen( $value, '8bit' ) > 16000 ) {
		$value = mb_substr( $value, 0, 16000, 'UTF-8' );
	} elseif ( strlen( $value ) > 16000 ) {
		$value = substr( $value, 0, 16000 );
	}
	return trim( $value );
}

/**
 * Retorna una etiqueta de marca/sitio para usar en textos automáticos.
 *
 * @return string
 */
function estandar_chatbot_get_brand_label() {
	$site_name = trim( wp_strip_all_tags( (string) get_bloginfo( 'name' ) ) );
	return $site_name !== '' ? $site_name : 'nuestro equipo';
}

/**
 * Instrucciones por defecto del sistema (si el admin no define prompt personalizado).
 *
 * @param string $lang Código de idioma (p. ej. es, en).
 * @return string
 */
function estandar_chatbot_default_system_prompt_lines( $lang ) {
	$lang = sanitize_text_field( $lang );
	if ( $lang === '' ) {
		$lang = 'es';
	}
	// PROMPT_ARCHITECTURE_V1: el prompt contiene SOLO comportamiento. La
	// identidad (empresa/rubro/CTA/tono) vive en === PERFIL DEL NEGOCIO === y
	// los datos en === BASE DE CONOCIMIENTO === — aquí no se duplican.
	return "ROL DEL ASISTENTE\n"
		. "\n"
		. "Eres el asistente comercial de la empresa descrita en el bloque PERFIL DEL NEGOCIO. Tu objetivo es ayudar al usuario a resolver su necesidad de forma rápida, clara y útil, guiándolo hacia una acción concreta (cotizar, agendar, comprar o contactar a una persona).\n"
		. "\n"
		. "PRIORIDAD\n"
		. "\n"
		. "Conversión directa. Reducir fricción. Resolver ahora.\n"
		. "\n"
		. "REGLA CRÍTICA DE RESPUESTA (PRIORIDAD MÁXIMA)\n"
		. "\n"
		. "- Si una mejora automática agrega reglas de cierre o conversión, deben respetarse antes que cualquier bloque descriptivo posterior.\n"
		. "- Para preguntas repetidas, precio, pago, urgencia o siguiente paso: responder en máximo 3 o 4 líneas.\n"
		. "- Priorizar claridad, acción y cierre por sobre completitud.\n"
		. "\n"
		. "REGLAS GENERALES\n"
		. "\n"
		. "- Responde únicamente en {$lang}.\n"
		. "- Respuestas cortas, claras y accionables.\n"
		. "- No dar rodeos ni explicaciones largas.\n"
		. "- Siempre llevar a una acción concreta.\n"
		. "- No usar lenguaje técnico innecesario.\n"
		. "- Evitar empatía débil.\n"
		. "- No inventes información. Si no sabes algo, dilo explícitamente.\n"
		. "\n"
		. "ESTRUCTURA OBLIGATORIA DE RESPUESTA\n"
		. "\n"
		. "1. Respuesta directa a la necesidad.\n"
		. "2. Acción inmediata clara (cotizar, agendar, comprar o contactar).\n"
		. "3. Alternativa humana si ayuda a cerrar.\n"
		. "4. Cierre breve, sin fricción.\n"
		. "\n"
		. "MENSAJE BASE (PRIORIDAD ALTA)\n"
		. "\n"
		. "Perfecto.\n"
		. "\n"
		. "Vamos directo.\n"
		. "\n"
		. "Da un siguiente paso concreto y fácil de ejecutar.\n"
		. "\n"
		. "Si conviene, ofrece que una persona del equipo continúe la conversación.\n"
		. "\n"
		. "MANEJO DE DESCONFIANZA\n"
		. "\n"
		. "Si el usuario duda o muestra resistencia, no insistir agresivamente. Redirige a contacto humano y mantén la opción abierta sin presión.\n"
		. "\n"
		. "PROHIBIDO\n"
		. "\n"
		. "- Derivar a email como primer paso.\n"
		. "- Pedir pasos manuales antes del cierre.\n"
		. "- Explicar procesos internos.\n"
		. "- Generar fricción innecesaria.\n"
		. "\n"
		. "OBJETIVO FINAL\n"
		. "\n"
		. "Cerrar o derivar a contacto humano inmediato sin perder al usuario.";
}

/**
 * Parámetros comunes para llamadas a OpenAI (modelo, temperature, max_tokens).
 *
 * @return array{model: string, temperature: float, max_tokens: int}
 */
function estandar_chatbot_get_openai_params() {
	return [
		'model'       => (string) get_option( 'chatbot_openai_model', 'gpt-4o' ),
		'temperature' => max( 0.0, min( 2.0, (float) get_option( 'chatbot_openai_temperature', 0.4 ) ) ),
		'max_tokens'  => max( 100, min( 8000, (int) get_option( 'chatbot_openai_max_tokens', 500 ) ) ),
	];
}

/**
 * PROMPT_ARCHITECTURE_V1 — System Base interno del plugin.
 *
 * Meta-reglas de arquitectura de bloques, SIEMPRE presentes (con o sin prompt
 * personalizado). Deliberadamente corto y sin reglas conversacionales para no
 * duplicar prompts personalizados existentes (backward compatible).
 *
 * @param string $lang Idioma base.
 * @return string
 */
function estandar_chatbot_system_base_lines( $lang ) {
	$lang = sanitize_text_field( $lang );
	if ( $lang === '' ) {
		$lang = 'es';
	}
	return "Eres el asistente virtual de esta empresa. Responde únicamente en {$lang}.\n"
		. "\n"
		. "Arquitectura de la información que recibes (cada bloque tiene una única responsabilidad):\n"
		. "- CONTEXTO GLOBAL: reglas de comportamiento conversacional. Definen CÓMO conversar y tienen prioridad sobre los bloques descriptivos.\n"
		. "- PERFIL DEL NEGOCIO: identidad de la empresa (quién es, rubro, objetivo, CTA, tono, temas sensibles). Única fuente de identidad.\n"
		. "- CONTEXTO ESPECIALIZADO: reglas adicionales del contexto activo, si existe.\n"
		. "- BASE DE CONOCIMIENTO: datos del negocio (proyectos, servicios, precios, cobertura, horarios, políticas). Única fuente de datos.\n"
		. "\n"
		. "Usa exclusivamente la información de PERFIL DEL NEGOCIO y BASE DE CONOCIMIENTO como hechos. No inventes datos: si algo no está en esos bloques, dilo explícitamente y ofrece contacto humano.";
}

/**
 * PROMPT_ARCHITECTURE_V1 — bloque Perfil del negocio.
 *
 * Único responsable de describir quién es la empresa, rubro, objetivo
 * principal, CTA, tono y temas sensibles (opciones chatbot_business_*).
 * El prompt (custom o default) no debe repetir esta información.
 *
 * @return string Bloque formateado o '' si no hay ningún campo con valor.
 */
function estandar_chatbot_build_business_profile_block() {
	$fields = [
		'Empresa'                            => estandar_chatbot_get_brand_label(),
		'Rubro'                              => (string) get_option( 'chatbot_business_industry', '' ),
		'Objetivo principal'                 => (string) get_option( 'chatbot_business_goal', '' ),
		'CTA principal'                      => (string) get_option( 'chatbot_business_primary_cta', '' ),
		'Tono'                               => (string) get_option( 'chatbot_business_tone', '' ),
		'Temas sensibles (derivar a humano)' => (string) get_option( 'chatbot_business_sensitive_topics', '' ),
	];

	$lines = [];
	foreach ( $fields as $label => $value ) {
		$value = trim( sanitize_textarea_field( $value ) );
		if ( $value !== '' ) {
			$lines[] = '- ' . $label . ': ' . $value;
		}
	}

	if ( empty( $lines ) ) {
		return '';
	}

	return "=== PERFIL DEL NEGOCIO ===\n\n" . implode( "\n", $lines );
}

/**
 * Construye el mensaje de sistema completo para OpenAI.
 *
 * PROMPT_ARCHITECTURE_V1 — punto canónico ÚNICO de ensamblado. Toda llamada
 * al proveedor IA (proxy web, WhatsApp, preview admin) pasa por aquí.
 * Composición: System Base (interno) → Comportamiento (custom || default) →
 * Perfil del negocio → Contexto especializado → Conocimiento → Horario.
 * Precedencia sin cambios: el bloque de comportamiento sigue mandando sobre
 * los bloques descriptivos (regla declarada en System Base y en el default).
 *
 * @param string $lang     Idioma base.
 * @param string $context  Texto de conocimiento (p. ej. desde TXT o RAG).
 * @return string
 */
function estandar_chatbot_build_system_prompt( $lang, $context = '', $context_prompt = '' ) {
	$custom = trim( (string) get_option( 'chatbot_system_prompt', '' ) );
	$global_prompt = $custom !== '' ? $custom : estandar_chatbot_default_system_prompt_lines( $lang );

	$parts = [];
	$parts[] = "=== SYSTEM BASE ===\n\n" . estandar_chatbot_system_base_lines( $lang );
	$parts[] = "=== CONTEXTO GLOBAL ===\n\n" . $global_prompt;

	$profile_block = estandar_chatbot_build_business_profile_block();
	if ( $profile_block !== '' ) {
		$parts[] = $profile_block;
	}

	$context_prompt = trim( (string) $context_prompt );
	if ( $context_prompt !== '' ) {
		$parts[] = "=== CONTEXTO ESPECIALIZADO ===\n\n" . $context_prompt;
	}

	$context = trim( (string) $context );
	if ( $context !== '' ) {
		$parts[] = "=== BASE DE CONOCIMIENTO ===\n\n" . $context;
	}

	// OPERATIONAL_HOURS_V1: inject schedule awareness.
	$oh_block = estandar_chatbot_operational_hours_context();
	if ( $oh_block !== '' ) {
		$parts[] = $oh_block;
	}

	return implode( "\n\n", $parts );
}

function estandar_chatbot_operational_hours_context(): string {
	if ( ! class_exists( 'Estandar_Operational_Hours' ) ) {
		return '';
	}
	$config = Estandar_Operational_Hours::get_config();
	if ( empty( $config['enabled'] ) ) {
		return '';
	}

	$info = Estandar_Operational_Hours::human_message();
	$day_labels = array(
		'mon' => 'Lunes', 'tue' => 'Martes', 'wed' => 'Miércoles',
		'thu' => 'Jueves', 'fri' => 'Viernes', 'sat' => 'Sábado', 'sun' => 'Domingo',
	);

	$lines = array();
	$lines[] = "=== HORARIO OPERACIONAL ===\n";
	$lines[] = "Estado actual: " . ( $info['is_open'] ? 'ABIERTO (en horario de atención)' : 'CERRADO (fuera de horario)' );
	if ( ! $info['is_open'] && $info['next_window'] ) {
		$lines[] = "Próxima ventana de atención: " . $info['next_window'];
	}
	$lines[] = "\nHorario semanal:";
	foreach ( Estandar_Operational_Hours::WEEKDAYS as $day ) {
		$day_cfg = $config['schedule'][ $day ] ?? null;
		if ( $day_cfg === null ) {
			$lines[] = "- " . $day_labels[ $day ] . ": Cerrado";
		} else {
			$lines[] = "- " . $day_labels[ $day ] . ": " . $day_cfg['start'] . " a " . $day_cfg['end'];
		}
	}
	$lines[] = "Zona horaria: " . $config['timezone'];
	$lines[] = "\nReglas para responder sobre horarios:";
	$lines[] = "- Puedes informar el horario de atención y la próxima ventana.";
	$lines[] = "- Puedes confirmar que la solicitud fue registrada y será atendida.";
	$lines[] = "- NUNCA prometas plazos específicos de respuesta (ej: 'mañana te llamarán', 'en 2 horas').";
	$lines[] = "- NUNCA inventes disponibilidad de personas específicas.";

	return implode( "\n", $lines );
}

function estandar_chatbot_get_operational_hours_state(): array {
	if ( ! class_exists( 'Estandar_Operational_Hours' ) ) {
		return [ 'enabled' => false ];
	}
	$config = Estandar_Operational_Hours::get_config();
	if ( empty( $config['enabled'] ) ) {
		return [ 'enabled' => false ];
	}
	$info = Estandar_Operational_Hours::human_message();
	return [
		'enabled'    => true,
		'isOpen'     => (bool) $info['is_open'],
		'nextWindow' => $info['next_window'] ?? '',
		'message'    => $info['message'] ?? '',
	];
}

/**
 * Verifica nonce en requests AJAX o muere.
 */
function estandar_chatbot_verify_nonce_or_die() {
	$nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
	if ( ! wp_verify_nonce( $nonce, 'estandar_chatbot_nonce' ) ) {
		$action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';
		$headers = [
			'cache_control'   => isset( $_SERVER['HTTP_CACHE_CONTROL'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_CACHE_CONTROL'] ) ) : '',
			'pragma'          => isset( $_SERVER['HTTP_PRAGMA'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_PRAGMA'] ) ) : '',
			'cf_cache_status' => isset( $_SERVER['HTTP_CF_CACHE_STATUS'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CACHE_STATUS'] ) ) : '',
			'x_cache'         => isset( $_SERVER['HTTP_X_CACHE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_CACHE'] ) ) : '',
			'x_cache_hit'     => isset( $_SERVER['HTTP_X_CACHE_HIT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_CACHE_HIT'] ) ) : '',
		];
		estandar_chatbot_telemetry_event( 'warning', 'CHATBOT_NONCE_INVALID', 'Chatbot nonce validation failed.', [
			'endpoint'       => $action !== '' ? $action : 'unknown',
			'user_logged_in'  => is_user_logged_in() ? '1' : '0',
			'request_method'  => isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '',
			'request_headers' => $headers,
		] );
		wp_send_json_error(
			[ 'message' => 'Nonce inválido.', 'code' => 'nonce_invalid' ],
			403
		);
		exit;
	}
}

function estandar_chatbot_get_runtime_nonce_payload() {
	nocache_headers();
	return [
		'nonce' => wp_create_nonce( 'estandar_chatbot_nonce' ),
	];
}

/**
 * Retorna metadata básica de la request (best effort).
 */
function estandar_chatbot_get_request_meta() {
	$ua  = isset($_SERVER['HTTP_USER_AGENT'])
		? sanitize_text_field($_SERVER['HTTP_USER_AGENT'])
		: '';

	$url = '';
	if ( isset($_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI']) ) {
		$scheme = is_ssl() ? 'https://' : 'http://';
		$url = esc_url_raw( $scheme . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] );
	}

	return [
		'ip'  => estandar_chatbot_get_client_ip(),
		'ua'  => $ua,
		'url' => $url,
	];
}

/**
 * Normaliza host para comparar variantes con/sin www.
 *
 * @param string $host Host desde wp_parse_url.
 * @return string
 */
function estandar_chatbot_normalize_lead_source_host( $host ) {
	$host = strtolower( trim( (string) $host ) );
	if ( $host === '' ) {
		return '';
	}
	return (string) preg_replace( '/^www\./', '', $host );
}

/**
 * Valida la URL de la página donde el visitante interactuó con el chat (mismo sitio).
 * Rechaza hosts ajenos para evitar inyección de enlaces en correos / integraciones.
 *
 * @param mixed $raw Valor crudo (p. ej. desde POST).
 * @return string URL segura o cadena vacía.
 */
function estandar_chatbot_sanitize_source_page_url( $raw ) {
	$raw = (string) wp_unslash( $raw );
	$url = esc_url_raw( trim( $raw ) );
	if ( $url === '' ) {
		return '';
	}
	$parsed = wp_parse_url( $url );
	if ( empty( $parsed['host'] ) ) {
		return '';
	}
	$host_norm = estandar_chatbot_normalize_lead_source_host( $parsed['host'] );
	$allowed   = [];
	foreach ( [ home_url( '/' ), site_url( '/' ) ] as $base ) {
		$h = wp_parse_url( $base, PHP_URL_HOST );
		if ( $h ) {
			$allowed[ estandar_chatbot_normalize_lead_source_host( $h ) ] = true;
		}
	}
	if ( empty( $allowed ) || ! isset( $allowed[ $host_norm ] ) ) {
		return '';
	}
	return $url;
}

/**
 * Obtiene IP cliente.
 *
 * Por defecto usa REMOTE_ADDR (no spoofable).
 * Para entornos detrás de Cloudflare u otro CDN de confianza, define en wp-config.php:
 *   define( 'ESTANDAR_CHATBOT_TRUST_CDN_HEADERS', true );
 *
 * P2 — Patch de seguridad: CDN headers solo cuando se confía explícitamente.
 */
function estandar_chatbot_get_client_ip() {
	// Solo leer headers CDN si el operador los habilitó explícitamente
	if ( defined( 'ESTANDAR_CHATBOT_TRUST_CDN_HEADERS' ) && ESTANDAR_CHATBOT_TRUST_CDN_HEADERS === true ) {
		if ( isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$ip = trim( sanitize_text_field( (string) wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) );
			if ( $ip !== '' && filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}
		if ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			// Tomar solo el primer IP (cliente original, no proxies intermedios)
			$parts = explode( ',', (string) wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			$ip = trim( sanitize_text_field( $parts[0] ) );
			if ( $ip !== '' && filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}
	}

	if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
		$ip = trim( sanitize_text_field( (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) );
		if ( $ip !== '' && filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return $ip;
		}
	}

	return '';
}

/**
 * Evita CSV injection en celdas que Excel/Sheets interpretan como fórmula.
 */
function estandar_chatbot_csv_safe_cell( $value ) {
	$value = is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
	if ( $value === '' ) {
		return '';
	}
	$first = substr( $value, 0, 1 );
	if ( in_array( $first, [ '=', '+', '-', '@' ], true ) ) {
		return "'" . $value;
	}
	return $value;
}

/**
 * Fecha/hora local del sitio en formato MySQL.
 */
function estandar_chatbot_local_mysql_time( $timestamp = null ) {
	$timestamp = is_numeric( $timestamp ) ? (int) $timestamp : (int) current_time( 'timestamp' );
	return wp_date( 'Y-m-d H:i:s', $timestamp, wp_timezone() );
}

/**
 * Intenta adquirir un lock nombrado en DB para evitar carreras entre workers.
 */
function estandar_chatbot_acquire_db_lock( $name, $timeout = 5 ) {
	global $wpdb;

	$name = preg_replace( '/[^a-zA-Z0-9:_-]/', '', (string) $name );
	$name = substr( $name, 0, 64 );
	$timeout = max( 0, min( 30, (int) $timeout ) );
	if ( $name === '' ) {
		return false;
	}

	$result = $wpdb->get_var(
		$wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, $timeout )
	);

	return (int) $result === 1;
}

/**
 * Libera lock nombrado en DB.
 */
function estandar_chatbot_release_db_lock( $name ) {
	global $wpdb;

	$name = preg_replace( '/[^a-zA-Z0-9:_-]/', '', (string) $name );
	$name = substr( $name, 0, 64 );
	if ( $name === '' ) {
		return false;
	}

	$result = $wpdb->get_var(
		$wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name )
	);

	return (int) $result === 1;
}

/**
 * CSV: lee archivo a array de filas (máximo N filas).
 */
function estandar_chatbot_csv_read( $filepath, $max_rows = 5000 ) {
	$rows = [];
	if ( ! file_exists( $filepath ) ) return $rows;

	$fh = fopen( $filepath, 'r' );
	if ( ! $fh ) return $rows;

	if ( ! flock( $fh, LOCK_SH ) ) {
		fclose( $fh );
		return $rows;
	}

	$i = 0;
	while ( ( $data = fgetcsv( $fh ) ) !== false ) {
		$rows[] = $data;
		$i++;
		if ( $max_rows > 0 && $i >= $max_rows ) break;
	}
	flock( $fh, LOCK_UN );
	fclose( $fh );

	return $rows;
}

/**
 * CSV: append seguro (crea dir/archivo si no existe).
 */
function estandar_chatbot_csv_append( $filepath, array $row ) {
	$dir = dirname( $filepath );
	if ( ! file_exists( $dir ) ) {
		wp_mkdir_p( $dir );
	}

	$fh = fopen( $filepath, 'a' );
	if ( ! $fh ) return false;

	if ( ! flock( $fh, LOCK_EX ) ) {
		fclose( $fh );
		return false;
	}

	$result = fputcsv( $fh, $row );
	fflush( $fh );
	flock( $fh, LOCK_UN );
	fclose( $fh );

	return ( $result !== false );
}

/**
 * CSV: asegura cabecera (si el archivo está vacío o no existe).
 */
function estandar_chatbot_csv_ensure_header( $filepath, array $header ) {
	$dir = dirname( $filepath );
	if ( ! file_exists( $dir ) ) {
		wp_mkdir_p( $dir );
	}

	$fh = fopen( $filepath, 'c+' );
	if ( ! $fh ) return false;

	if ( ! flock( $fh, LOCK_EX ) ) {
		fclose( $fh );
		return false;
	}

	clearstatcache( true, $filepath );
	$size = filesize( $filepath );
	if ( ! $size || $size <= 0 ) {
		ftruncate( $fh, 0 );
		rewind( $fh );
		fputcsv( $fh, $header );
		fflush( $fh );
	}

	flock( $fh, LOCK_UN );
	fclose( $fh );

	return true;
}

/**
 * Descarga segura de CSV desde admin (leads/logs).
 */
function estandar_chatbot_force_download_csv( $filepath, $download_name = 'export.csv' ) {
	if ( ! function_exists( 'estandar_chatbot_user_can' ) || ! estandar_chatbot_user_can( 'export' ) ) {
		wp_die( 'No autorizado.' );
	}

	if ( ! file_exists( $filepath ) ) {
		wp_die( 'Archivo no encontrado.' );
	}

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . basename( $download_name ) . '"' );
	header( 'Content-Length: ' . filesize( $filepath ) );

	readfile( $filepath );
	exit;
}

/**
 * ======================================================
 * HELPER CENTRAL — ¿VERSIÓN PRO ACTIVA?
 * ======================================================
 *
 * Punto único de verdad para estado PRO.
 * - Hoy: opción local (manual / demo)
 * - Futuro: validación de licencia remota
 */

function estandar_chatbot_is_pro(): bool {

	$enabled = (int) get_option( 'chatbot_pro_enabled', 0 );
	if ( $enabled === 1 ) {
		return true;
	}

	$license = get_option( 'estandar_chatbot_license', [] );
	$status  = is_array( $license ) ? (string) ( $license['status'] ?? '' ) : '';

	return in_array( $status, [ 'active', 'demo' ], true );
}

function estandar_chatbot_telemetry_event( $severity, $code, $message, array $context = [] ) {
	try {
		$code = strtoupper( preg_replace( '/[^A-Z0-9_]/', '', strtoupper( (string) $code ) ) );
		do_action( 'estandar_telemetry_event', [
			'source_plugin' => 'estandar-chatbot',
			'type'          => 'business_event',
			'severity'      => sanitize_key( (string) $severity ),
			'code'          => $code,
			'message'       => sanitize_text_field( (string) $message ),
			'context'       => $context,
		] );
	} catch ( Throwable $e ) {
		return;
	}
}
