<?php

namespace Estandar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SITE_MAP_V1 — Diagrama de flujo del sitio completo.
 *
 * Construye un grafo de todas las páginas/publicaciones/landings del sitio
 * (raíz = portada) con sus vínculos reales (menú + jerarquía de páginas +
 * enlaces internos del contenido), clasifica cada nodo como activo/inactivo
 * según publicación + tráfico, y adjunta a cada tarjeta: visitas (30 días),
 * total de visitas, última vista, autor, fecha de publicación, captura de
 * pantalla (automática con fallback manual) y acción "enviar a la papelera".
 *
 * Fuente de tráfico (por precedencia):
 *   1. Hook `estandar_suite_sitemap_page_stats` — un conector externo
 *      (p. ej. Google Analytics) puede inyectar métricas históricas.
 *   2. Analytics de la Suite: espejo v2 `estandar_insights_analytics_pageviews`
 *      (con fallback al legacy `ea_pageviews`).
 *   3. Tracking ligero propio `estandar_core_pageviews` (beacon async, opt-in),
 *      sumado al histórico.
 *
 * Las funciones marcadas PURE son testables sin WP.
 */
class SiteMap
{
	const TABLE          = 'estandar_core_pageviews';
	const OPTION_SCHEMA  = 'estandar_suite_core_sitemap_schema_version';
	const OPTION_SETTINGS = 'estandar_suite_core_sitemap_settings';
	const OPTION_SHOTS   = 'estandar_suite_core_sitemap_shots';
	const OPTION_REDIRECTS = 'estandar_suite_core_sitemap_redirects';
	const SCHEMA_VERSION = '1.0.0';
	const REST_NS        = 'estandar-suite/core/v1';

	// Sesiones vivas v2 (fuente real del Panorama de insights tras el retiro
	// del tracker legacy; par del patrón pageviews vivas de esta misma clase).
	const TABLE_SESSIONS         = 'estandar_core_sessions';
	const OPTION_SCHEMA_SESSIONS = 'estandar_suite_core_sitemap_sessions_schema_version';
	const SCHEMA_SESSIONS_VERSION = '1.0.0';
	const SESSION_IDLE_SECS      = 1800; // 30 min sin actividad cierran la sesión (JS + servidor)
	const RATE_HITS              = 25;   // tope anti-flood por IP/min (permite navegación real)

	const CARD_W = 260;
	const CARD_H = 348;
	const GAP_X  = 72;
	const GAP_Y  = 30;
	const PAD    = 18;

	/** GA4 Data API v1beta (lectura de pageviews por pagePath). */
	const GA4_API   = 'https://analyticsdata.googleapis.com/v1beta';
	const GA4_SCOPE = 'https://www.googleapis.com/auth/analytics.readonly';
	const GA4_TTL   = 1800; // 30 min de caché del reporte por property+rango

	/** Rango por defecto para estadísticas (días). */
	public static function default_days(): int
	{
		return 30;
	}

	// ── Schema ─────────────────────────────────────────────────────────────

	public static function table(): string
	{
		global $wpdb;
		return ( isset( $wpdb ) && ! empty( $wpdb->prefix ) ? $wpdb->prefix : 'wp_' ) . self::TABLE;
	}

	public static function activate_schema(): void
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$table   = self::table();
		dbDelta( "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			page_url varchar(2048) NOT NULL DEFAULT '',
			page_title varchar(500) NOT NULL DEFAULT '',
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			viewed_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY page_url (page_url(191)),
			KEY viewed_at (viewed_at),
			KEY post_id (post_id)
		) {$charset};" );
		update_option( self::OPTION_SCHEMA, self::SCHEMA_VERSION, false );
	}

	public static function maybe_migrate_schema(): void
	{
		if ( get_option( self::OPTION_SCHEMA, '' ) !== self::SCHEMA_VERSION ) {
			self::activate_schema();
		}
	}

	// ── Sesiones vivas (tracker beacon) ────────────────────────────────────

	/** Nombre completo (prefijo + tabla) de la tabla de sesiones vivas. */
	public static function sessions_table(): string
	{
		global $wpdb;
		return ( isset( $wpdb ) && ! empty( $wpdb->prefix ) ? $wpdb->prefix : 'wp_' ) . self::TABLE_SESSIONS;
	}

	/** Crea la tabla de sesiones vivas (dbDelta, idempotente). */
	public static function activate_sessions_schema(): void
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$table   = self::sessions_table();
		dbDelta( "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id varchar(64) NOT NULL,
			visitor_hash varchar(64) NOT NULL DEFAULT '',
			first_seen datetime NOT NULL,
			last_activity datetime NOT NULL,
			duration int(10) unsigned NOT NULL DEFAULT 0,
			page_count smallint(5) unsigned NOT NULL DEFAULT 0,
			is_bounce tinyint(1) NOT NULL DEFAULT 1,
			landing_page varchar(2048) NOT NULL DEFAULT '',
			exit_page varchar(2048) NOT NULL DEFAULT '',
			is_returning tinyint(1) NOT NULL DEFAULT 0,
			source varchar(100) NOT NULL DEFAULT '',
			medium varchar(100) NOT NULL DEFAULT '',
			campaign varchar(255) NOT NULL DEFAULT '',
			referrer_domain varchar(255) NOT NULL DEFAULT '',
			traffic_type varchar(50) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY session_id (session_id),
			KEY visitor_hash (visitor_hash),
			KEY first_seen (first_seen),
			KEY source (source)
		) {$charset};" );
		update_option( self::OPTION_SCHEMA_SESSIONS, self::SCHEMA_SESSIONS_VERSION, false );
	}

	public static function maybe_migrate_sessions_schema(): void
	{
		if ( get_option( self::OPTION_SCHEMA_SESSIONS, '' ) !== self::SCHEMA_SESSIONS_VERSION ) {
			self::activate_sessions_schema();
		}
	}

	/** True cuando la tabla de sesiones vivas existe. */
	public static function sessions_table_present(): bool
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return false;
		}
		$wpdb->get_results( 'SELECT 1 FROM ' . self::sessions_table() . ' LIMIT 1' );
		return empty( $wpdb->last_error );
	}

	/** PURE: normaliza un id (vid/sid) a [A-Za-z0-9._-] y acota largo. */
	private static function clean_id( string $raw, int $max = 64 ): string
	{
		$raw = preg_replace( '/[^A-Za-z0-9._-]/', '', $raw );
		return substr( (string) $raw, 0, $max );
	}

	/**
	 * PURE: clasifica tráfico de una vista a partir de su URL (UTM/click ids)
	 * y del referrer. Devuelve las columnas de la sesión que dependen de esto.
	 */
	private static function parse_traffic( string $url, string $ref = '' ): array
	{
		$q = array();
		$parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url );
		if ( ! empty( $parts['query'] ) ) {
			parse_str( (string) $parts['query'], $q );
		}
		$src  = trim( (string) ( $q['utm_source'] ?? '' ) );
		$med  = trim( (string) ( $q['utm_medium'] ?? '' ) );
		$camp = trim( (string) ( $q['utm_campaign'] ?? '' ) );
		$gclid  = (string) ( $q['gclid'] ?? '' );
		$fbclid = (string) ( $q['fbclid'] ?? '' );
		$msclkid = (string) ( $q['msclkid'] ?? '' );

		$referrer_domain = '';
		if ( $ref !== '' ) {
			$rh = function_exists( 'wp_parse_url' ) ? wp_parse_url( $ref, PHP_URL_HOST ) : parse_url( $ref, PHP_URL_HOST );
			if ( is_string( $rh ) && $rh !== '' ) {
				$referrer_domain = strtolower( $rh );
			}
		}

		if ( $src === '' ) {
			if ( $gclid !== '' ) {
				$src = 'google';
			} elseif ( $fbclid !== '' ) {
				$src = 'facebook';
			} elseif ( $msclkid !== '' ) {
				$src = 'bing';
			}
		}
		if ( $src === '' && $referrer_domain === '' ) {
			$src  = '(direct)';
			$med  = '(none)';
		} elseif ( $src === '' ) {
			$src  = $referrer_domain;
			$med  = 'referral';
		}

		$type = 'referral';
		$m    = strtolower( $med );
		if ( $src === '(direct)' ) {
			$type = 'direct';
		} elseif ( strpos( $m, 'cpc' ) !== false || strpos( $m, 'paid' ) !== false || $m === 'ppc' ) {
			$type = 'paid';
		} elseif ( $m === 'organic' || strpos( $m, 'organic' ) !== false ) {
			$type = 'organic';
		} elseif ( $m === 'social' ) {
			$type = 'social';
		} elseif ( $m === 'email' ) {
			$type = 'email';
		}

		return array(
			'source'          => mb_substr( $src, 0, 100 ),
			'medium'          => mb_substr( $med, 0, 100 ),
			'campaign'        => mb_substr( $camp, 0, 255 ),
			'referrer_domain' => mb_substr( $referrer_domain, 0, 255 ),
			'traffic_type'    => $type,
		);
	}

	/**
	 * Registra/actualiza una sesión viva (upsert por session_id). La primera
	 * vista de la sesión la crea; las siguientes la actualizan (page_count,
	 * última actividad, salida, duración aproximada, rebote si ya no lo es).
	 */
	public static function record_session( string $url, string $vid = '', string $sid = '', string $ref = '' ): bool
	{
		global $wpdb;
		$vid = self::clean_id( $vid );
		$sid = self::clean_id( $sid );
		if ( ! isset( $wpdb ) || $vid === '' || $sid === '' || ! self::sessions_table_present() ) {
			return false;
		}
		$table = self::sessions_table();
		$url   = mb_substr( $url, 0, 2048 );
		$now   = current_time( 'mysql' );
		$row   = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, first_seen, page_count FROM {$table} WHERE session_id = %s LIMIT 1",
			$sid
		) );
		if ( $row ) {
			$pc = (int) $row->page_count + 1;
			$wpdb->update(
				$table,
				array(
					'last_activity' => $now,
					'page_count'    => $pc,
					'is_bounce'     => $pc >= 2 ? 0 : 1,
					'exit_page'     => $url,
					'duration'      => max( 0, (int) ( strtotime( $now ) - strtotime( (string) $row->first_seen ) ) ),
				),
				array( 'id' => (int) $row->id ),
				array( '%s', '%d', '%d', '%s', '%d' ),
				array( '%d' )
			);
			return true;
		}
		// Nueva sesión.
		$is_returning = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE visitor_hash = %s AND session_id <> %s LIMIT 1",
			$vid,
			$sid
		) );
		$meta = self::parse_traffic( $url, $ref );
		$ok = $wpdb->insert(
			$table,
			array_merge(
				array(
					'session_id'   => $sid,
					'visitor_hash' => $vid,
					'first_seen'   => $now,
					'last_activity'=> $now,
					'duration'     => 0,
					'page_count'   => 1,
					'is_bounce'    => 1,
					'landing_page' => $url,
					'exit_page'    => $url,
					'is_returning' => $is_returning ? 1 : 0,
				),
				$meta
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		return false !== $ok;
	}

	// ── Settings ───────────────────────────────────────────────────────────

	/** PURE: valores por defecto de configuración. */
	public static function default_settings(): array
	{
		return array(
			'days'               => self::default_days(),
			'track_enabled'      => 1,
			'exclude_self'       => 1,
			'exclude_ips'        => array(),
			'screenshots_enabled' => 1,
			'screenshot_service' => 'mshots',
			'post_types'         => array( 'page' ),
			'ga4_enabled'        => 0,
			'ga4_connection_id'  => 0,
			'ga4_property_id'    => '',
		);
	}

	public static function settings(): array
	{
		$opt = get_option( self::OPTION_SETTINGS, array() );
		$opt = is_array( $opt ) ? $opt : array();
		return array_merge( self::default_settings(), $opt );
	}

	/** PURE: sanitiza un payload de settings contra el whitelist. */
	public static function sanitize_settings( array $raw ): array
	{
		$def   = self::default_settings();
		$days  = (int) ( $raw['days'] ?? $def['days'] );
		if ( ! in_array( $days, array( 7, 30, 90, 365 ), true ) ) {
			$days = $def['days'];
		}
		$service = sanitize_key( (string) ( $raw['screenshot_service'] ?? $def['screenshot_service'] ) );
		if ( ! in_array( $service, array( 'mshots', 'none' ), true ) ) {
			$service = $def['screenshot_service'];
		}
		return array(
			'days'               => $days,
			'track_enabled'      => empty( $raw['track_enabled'] ) ? 0 : 1,
			'exclude_self'       => empty( $raw['exclude_self'] ) ? 0 : 1,
			'exclude_ips'        => self::sanitize_ips( $raw['exclude_ips'] ?? array() ),
			'screenshots_enabled' => empty( $raw['screenshots_enabled'] ) ? 0 : 1,
			'screenshot_service' => $service,
			'post_types'         => self::sanitize_post_types( $raw['post_types'] ?? $def['post_types'] ),
			'ga4_enabled'        => empty( $raw['ga4_enabled'] ) ? 0 : 1,
			'ga4_connection_id'  => max( 0, (int) ( $raw['ga4_connection_id'] ?? $def['ga4_connection_id'] ) ),
			'ga4_property_id'    => sanitize_text_field( (string) ( $raw['ga4_property_id'] ?? $def['ga4_property_id'] ) ),
		);
	}

	/** PURE: normaliza una lista de IPs propias (string CSV o array) → array. */
	public static function sanitize_ips( $raw ): array
	{
		if ( is_string( $raw ) ) {
			$raw = array_map( 'trim', explode( ',', $raw ) );
		}
		$raw  = is_array( $raw ) ? $raw : array();
		$out  = array();
		foreach ( $raw as $ip ) {
			$ip = filter_var( trim( (string) $ip ), FILTER_VALIDATE_IP );
			if ( $ip !== false ) {
				$out[] = $ip;
			}
			if ( count( $out ) >= 20 ) {
				break;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * ¿La petición es del propio operador (tráfico propio, excluible)?
	 * True cuando el toggle `exclude_self` está activo y la petición viene de un
	 * admin logueado o de una IP marcada como propia en `exclude_ips`. Por defecto
	 * el tráfico propio NO se registra (default exclude_self=1).
	 */
	public static function is_self_request( string $ip = '' ): bool
	{
		$s = self::settings();
		if ( empty( $s['exclude_self'] ) ) {
			return false;
		}
		if ( function_exists( 'is_user_logged_in' ) && function_exists( 'current_user_can' )
			&& is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			return true;
		}
		if ( $ip !== '' ) {
			foreach ( (array) ( $s['exclude_ips'] ?? array() ) as $e ) {
				if ( $e === $ip ) {
					return true;
				}
			}
		}
		return false;
	}

	/** PURE: valida slugs de post types (solo los que existen) y fuerza `page`. */
	public static function sanitize_post_types( $raw ): array
	{
		$raw  = is_array( $raw ) ? $raw : ( is_string( $raw ) && $raw !== '' ? array( $raw ) : array() );
		$out  = array();
		foreach ( $raw as $t ) {
			$t = sanitize_key( (string) $t );
			if ( $t === '' || ! post_type_exists( $t ) ) {
				continue;
			}
			$out[ $t ] = true;
		}
		$out['page'] = true; // páginas siempre incluidas
		return array_keys( $out );
	}

	public static function save_settings( array $raw ): array
	{
		$clean = self::sanitize_settings( $raw );
		update_option( self::OPTION_SETTINGS, $clean, false );
		return $clean;
	}

	// ── Tracking ligero (frontend, beacon async) ───────────────────────────

	public static function init_tracking(): void
	{
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_tracker' ), 20 );
	}

	/** Registra el script liviano del tracker solo si está habilitado. */
	public static function enqueue_tracker(): void
	{
		$s = self::settings();
		if ( empty( $s['track_enabled'] ) ) {
			return;
		}
		$file = ESTANDAR_SUITE_CORE_PATH . 'includes/assets/estandar-sitemap-track-v2.js';
		$ver  = file_exists( $file ) ? (string) filemtime( $file ) : ESTANDAR_SUITE_CORE_VERSION;
		$post = get_queried_object();
		$pid  = ( $post instanceof \WP_Post ) ? (int) $post->ID : 0;
		wp_register_script(
			'estandar-sitemap-track',
			plugins_url( 'includes/assets/estandar-sitemap-track-v2.js', ESTANDAR_SUITE_CORE_FILE ),
			array(),
			$ver,
			true
		);
		wp_localize_script( 'estandar-sitemap-track', 'ES_SITE_MAP', array(
			'endpoint' => esc_url_raw( rest_url( self::REST_NS . '/sitemap/pageview' ) ),
			'post_id'  => $pid,
		) );
		wp_enqueue_script( 'estandar-sitemap-track' );
	}

	/** PURE: tolerancia anti-flood por IP (conteo por ventana). */
	private static function rate_limited( string $ip, int $max = 25, int $window = 60 ): bool
	{
		if ( $ip === '' ) {
			return false;
		}
		$k = 'es_sm_pv_' . md5( $ip );
		$n = (int) get_transient( $k );
		if ( $n >= $max ) {
			return true;
		}
		set_transient( $k, $n + 1, $window );
		return false;
	}

	/** REST público (sin auth): registra vista + sesión viva. Rate-limited por IP. */
	public static function rest_pageview( \WP_REST_Request $request ): \WP_REST_Response
	{
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( (string) $_SERVER['REMOTE_ADDR'] ) : '';
		if ( self::is_self_request( $ip ) ) {
			return rest_ensure_response( array( 'ok' => true, 'ignored' => 'self' ) );
		}
		if ( self::rate_limited( $ip, self::RATE_HITS ) ) {
			return rest_ensure_response( array( 'ok' => true, 'ignored' => 'rate' ) );
		}
		$url    = esc_url_raw( (string) $request->get_param( 'url' ) );
		$title  = sanitize_text_field( (string) $request->get_param( 'title' ) );
		$post   = absint( (int) $request->get_param( 'post_id' ) );
		$vid    = sanitize_text_field( (string) $request->get_param( 'v' ) );
		$sid    = sanitize_text_field( (string) $request->get_param( 's' ) );
		$ref    = esc_url_raw( (string) $request->get_param( 'ref' ) );
		if ( $url === '' ) {
			return rest_ensure_response( array( 'ok' => false, 'error' => 'url vacía' ) );
		}
		self::record_pageview( $url, $title, $post );
		if ( $vid !== '' && $sid !== '' ) {
			self::record_session( $url, $vid, $sid, $ref );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/** Inserta una vista en la tabla propia del tracker. */
	public static function record_pageview( string $url, string $title = '', int $post_id = 0 ): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || ! self::table_present() ) {
			return 0;
		}
		$url = mb_substr( $url, 0, 2048 );
		$wpdb->insert( self::table(), array(
			'page_url'   => $url,
			'page_title' => mb_substr( $title, 0, 500 ),
			'post_id'    => $post_id,
			'viewed_at'  => current_time( 'mysql' ),
		), array( '%s', '%s', '%d', '%s' ) );
		return (int) $wpdb->insert_id;
	}

	public static function table_present(): bool
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return false;
		}
		$wpdb->get_results( 'SELECT 1 FROM ' . self::table() . ' LIMIT 1' );
		return empty( $wpdb->last_error );
	}

	// ── Fuente de analytics (espejos v2 → legacy ea_*) ─────────────────────

	/** Nombre de tabla de pageviews preferida (v2 mirror → legacy ea_pageviews). */
	public static function analytics_pageviews_table(): string
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return '';
		}
		if ( class_exists( '\\Estandar\\Insights\\Analytics' ) && method_exists( '\\Estandar\\Insights\\Analytics', 'source_table' ) ) {
			$t = \Estandar\Insights\Analytics::source_table( 'pageviews' );
			if ( $t !== '' ) {
				return $t;
			}
		}
		$legacy = ( isset( $wpdb->prefix ) ? $wpdb->prefix : 'wp_' ) . 'ea_pageviews';
		$wpdb->get_results( 'SELECT 1 FROM ' . $legacy . ' LIMIT 1' );
		return empty( $wpdb->last_error ) ? $legacy : '';
	}

	/** Describe la fuente de tráfico en uso (para la UI). Nuestra analytics es
	 *  la fuente principal; GA4 solo aparece como extra de referencia. */
	public static function analytics_source_label( ?array $ga4 = null ): string
	{
		$label = 'sin fuente';
		$t     = self::analytics_pageviews_table();
		if ( $t !== '' ) {
			$label = strpos( $t, 'estandar_insights_analytics' ) !== false
				? 'Estandar Analytics (servidor)'
				: 'Estandar Analytics histórico (ea_pageviews)';
		} elseif ( self::table_present() ) {
			$label = 'Tracking ligero (servidor)';
		}

		$cfg = self::ga4_config();
		if ( ! empty( $cfg['enabled'] ) ) {
			if ( is_array( $ga4 ) && ! empty( $ga4['ok'] ) ) {
				$label .= ' + GA4 (extra)';
			} elseif ( $cfg['property_id'] !== '' && $cfg['connection_id'] > 0 && ! self::ga4_connection_scopes_ok( $cfg['connection_id'] ) ) {
				$label .= ' · GA4 pendiente (scope analytics.readonly)';
			} elseif ( $cfg['property_id'] !== '' ) {
				$label .= ' · GA4 (' . (string) ( $ga4['error'] ?? 'error' ) . ')';
			}
		}
		return $label;
	}

	/** True si un conector externo (GA) inyecta métricas. */
	public static function has_external_stats(): bool
	{
		return has_filter( 'estandar_suite_sitemap_page_stats' );
	}

	// ── GA4 (Google Analytics 4 Data API, reutiliza la conexión Google) ────

	/** Config GA4 normalizada desde settings. */
	public static function ga4_config(): array
	{
		$s = self::settings();
		return array(
			'enabled'        => ! empty( $s['ga4_enabled'] ),
			'connection_id'  => (int) $s['ga4_connection_id'],
			'property_id'    => trim( (string) $s['ga4_property_id'] ),
			'ready'          => false,
		);
	}

	/** True si la conexión Google ya incluye el scope analytics.readonly. */
	public static function ga4_connection_scopes_ok( int $connection_id ): bool
	{
		if ( $connection_id <= 0 || ! class_exists( '\\Estandar\\Core\\Connections' ) ) {
			return false;
		}
		$row = Connections::get( $connection_id );
		if ( ! $row || ( $row['provider'] ?? '' ) !== 'google' ) {
			return false;
		}
		$scopes = json_decode( (string) ( $row['scopes_json'] ?? '[]' ), true );
		return is_array( $scopes ) && in_array( self::GA4_SCOPE, $scopes, true );
	}

	/** PURE: path normalizado (minúsculas, barra final) de una URL. */
	public static function ga4_path_of( string $url ): string
	{
		$n  = self::normalize_url( $url );
		$p  = (string) ( parse_url( $n, PHP_URL_PATH ) ?: '/' );
		$p  = rtrim( $p, '/' );
		return strtolower( $p === '' ? '/' : $p . '/' );
	}

	/**
	 * Reporte GA4 por pagePath para el rango pedido + todo el historial.
	 * Resultado cacheado (transient) para no golpear la API por página.
	 */
	public static function ga4_report( int $days ): array
	{
		$cfg = self::ga4_config();
		if ( ! $cfg['enabled'] || $cfg['property_id'] === '' || $cfg['connection_id'] <= 0 ) {
			return array( 'ok' => false, 'paths' => array(), 'total' => 0, 'count' => 0, 'error' => 'GA4 no configurado' );
		}
		if ( ! self::ga4_connection_scopes_ok( $cfg['connection_id'] ) ) {
			return array( 'ok' => false, 'paths' => array(), 'total' => 0, 'count' => 0, 'error' => 'scope' );
		}

		$cache_key = 'es_sm_ga4_' . $cfg['property_id'] . '_' . (int) $days;
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$tok = Connections::access_token( $cfg['connection_id'] );
		if ( empty( $tok['ok'] ) || $tok['token'] === '' ) {
			return array( 'ok' => false, 'paths' => array(), 'total' => 0, 'count' => 0, 'error' => 'token' );
		}
		$property = preg_replace( '/[^0-9]/', '', $cfg['property_id'] );
		if ( $property === '' ) {
			return array( 'ok' => false, 'paths' => array(), 'total' => 0, 'count' => 0, 'error' => 'property' );
		}

		$resp = Http::post( self::GA4_API . '/properties/' . $property . ':runReport', $tok['token'], array(
			'json' => array(
				'dateRanges'  => array(
					array( 'startDate' => (int) $days . 'daysAgo', 'endDate' => 'today' ),
					array( 'startDate' => '2000-01-01', 'endDate' => 'today' ),
				),
				'dimensions'  => array( array( 'name' => 'pagePath' ) ),
				'metrics'     => array( array( 'name' => 'screenPageViews' ) ),
				'limit'       => 10000,
			),
			'timeout' => 30,
		) );
		if ( empty( $resp['ok'] ) ) {
			return array( 'ok' => false, 'paths' => array(), 'total' => 0, 'count' => 0, 'error' => $resp['error'] ?? 'http' );
		}

		$paths = array();
		$total = 0;
		$count = 0;
		foreach ( (array) ( $resp['body']['rows'] ?? array() ) as $row ) {
			$path = strtolower( (string) ( $row['dimensionValues'][0]['value'] ?? '' ) );
			$v30  = (int) ( $row['metricValues'][0]['value'] ?? 0 );
			$vall = (int) ( $row['metricValues'][1]['value'] ?? $v30 );
			if ( $path === '' || $path === '(other)' ) {
				continue;
			}
			$path = rtrim( $path, '/' ) . '/';
			$paths[ $path ] = array( $v30, $vall );
			$total += $vall;
			$count++;
		}

		$out = array( 'ok' => true, 'paths' => $paths, 'total' => $total, 'count' => $count, 'error' => '' );
		set_transient( $cache_key, $out, self::GA4_TTL );
		return $out;
	}

	/** Valida conexión + scope + propiedad GA4 (para el botón "Probar"). */
	public static function ga4_test(): array
	{
		$cfg = self::ga4_config();
		if ( $cfg['property_id'] === '' ) {
			return array( 'ok' => false, 'error' => 'Falta el Property ID de GA4.' );
		}
		if ( $cfg['connection_id'] <= 0 ) {
			return array( 'ok' => false, 'error' => 'No hay cuenta Google seleccionada.' );
		}
		if ( ! self::ga4_connection_scopes_ok( $cfg['connection_id'] ) ) {
			return array( 'ok' => false, 'error' => 'La cuenta no tiene el scope analytics.readonly. Reconecta con "Conectar Google Analytics".' );
		}
		$r = self::ga4_report( (int) self::settings()['days'] );
		if ( empty( $r['ok'] ) ) {
			return array( 'ok' => false, 'error' => $r['error'] === 'scope' ? 'Scope insuficiente: reconecta con analytics.readonly.' : ( 'GA4 respondió: ' . (string) $r['error'] ) );
		}
		return array(
			'ok'    => true,
			'property_id' => $cfg['property_id'],
			'paths' => $r['count'],
			'total' => $r['total'],
		);
	}

	/** PURE: variantes de una URL para match tolerante (barra final). */
	public static function url_variants( string $url ): array
	{
		$url  = self::normalize_url( $url );
		$no_s = rtrim( $url, '/' );
		$out  = array( $url );
		if ( $no_s !== $url && $no_s !== '' ) {
			$out[] = $no_s;
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Métricas de una URL: [views_total, views_30, last_viewed].
	 * Nuestra analytics (servidor, directa) es la fuente PRIMARIA; GA4 queda
	 * como EXTRA de referencia (no reemplaza) cuando está configurado.
	 * Precedencia: 1) hook externo (override total); 2) espejos analytics v2/
	 * legacy + tracker propio; 3) GA4 solo como campos ga4_views_* adicionales.
	 */
	public static function page_stats( string $url, int $days, ?array $ga4 = null ): array
	{
		$days = $days > 0 ? $days : self::default_days();
		$ext  = apply_filters( 'estandar_suite_sitemap_page_stats', null, $url, $days );
		if ( is_array( $ext ) && isset( $ext['views_total'] ) ) {
			return array(
				'views_total' => max( 0, (int) $ext['views_total'] ),
				'views_30'    => max( 0, (int) ( $ext['views_30'] ?? 0 ) ),
				'last_viewed' => (string) ( $ext['last_viewed'] ?? '' ),
				'source'      => 'external',
			);
		}

		$vars  = self::url_variants( $url );
		$total = 0;
		$v30   = 0;
		$last  = '';

		$table = self::analytics_pageviews_table();
		if ( $table !== '' ) {
			global $wpdb;
			$in    = implode( ',', array_fill( 0, count( $vars ), '%s' ) );
			$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
			$sql   = "SELECT COUNT(*) AS n,
				COALESCE(SUM(viewed_at >= %s),0) AS n30,
				MAX(viewed_at) AS last
				FROM {$table} WHERE page_url IN ({$in})";
			$args  = array_merge( array( $since ), $vars );
			$row   = $wpdb->get_row( $wpdb->prepare( $sql, $args ), ARRAY_A );
			if ( $row ) {
				$total = (int) ( $row['n'] ?? 0 );
				$v30   = (int) ( $row['n30'] ?? 0 );
				$last  = (string) ( $row['last'] ?? '' );
			}
		}

		if ( self::table_present() ) {
			global $wpdb;
			$in    = implode( ',', array_fill( 0, count( $vars ), '%s' ) );
			$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
			$table = self::table();
			$row   = $wpdb->get_row( $wpdb->prepare(
				"SELECT COUNT(*) AS n, COALESCE(SUM(viewed_at >= %s),0) AS n30, MAX(viewed_at) AS last
				 FROM {$table} WHERE page_url IN ({$in})",
				array_merge( array( $since ), $vars )
			), ARRAY_A );
			if ( $row ) {
				$total += (int) ( $row['n'] ?? 0 );
				$v30   += (int) ( $row['n30'] ?? 0 );
				$raw    = (string) ( $row['last'] ?? '' );
				if ( $raw > $last ) {
					$last = $raw;
				}
			}
		}

		$out = array(
			'views_total' => $total,
			'views_30'    => $v30,
			'last_viewed' => $last,
			'source'      => $table !== '' ? 'suite' : 'tracker',
		);

		// GA4 = extra de referencia (no suma ni reemplaza las cifras propias).
		if ( is_array( $ga4 ) && ! empty( $ga4['ok'] ) && ! empty( $ga4['paths'] ) ) {
			$path = self::ga4_path_of( $url );
			if ( isset( $ga4['paths'][ $path ] ) ) {
				list( $v30g, $vallg ) = $ga4['paths'][ $path ];
				$out['ga4_views_30']    = (int) $v30g;
				$out['ga4_views_total'] = (int) $vallg;
			}
		}

		return $out;
	}

	// ── Normalización / enlaces internos (PURE) ────────────────────────────

	/** PURE: normaliza una URL a scheme://host+path (sin query/fragment). */
	public static function normalize_url( string $url, string $base = '' ): string
	{
		if ( $base !== '' && strpos( $url, 'http' ) !== 0 && strpos( $url, '//' ) !== 0 ) {
			$url = rtrim( $base, '/' ) . '/' . ltrim( $url, '/' );
		}
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return trim( $url );
		}
		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) . '://' : '//';
		$path   = isset( $parts['path'] ) && $parts['path'] !== '' ? $parts['path'] : '/';
		$path   = preg_replace( '#/+#', '/', $path );
		return $scheme . strtolower( (string) $parts['host'] ) . $path;
	}

	/** PURE: extrae enlaces internos del HTML (href resolvibles al mismo host). */
	public static function internal_links( string $html, string $home_url ): array
	{
		$out = array();
		$home_host = strtolower( (string) ( parse_url( $home_url, PHP_URL_HOST ) ?: '' ) );
		if ( ! preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\']/i', $html, $m ) ) {
			return $out;
		}
		foreach ( $m[1] as $raw ) {
			$href = html_entity_decode( trim( $raw ), ENT_QUOTES );
			if ( $href === '' || strpos( $href, '#' ) === 0 ) {
				continue;
			}
			if ( strpos( $href, 'mailto:' ) === 0 || strpos( $href, 'tel:' ) === 0 || strpos( $href, 'javascript:' ) === 0 ) {
				continue;
			}
			$host = strtolower( (string) ( parse_url( $href, PHP_URL_HOST ) ?: '' ) );
			if ( $host !== '' && $host !== $home_host ) {
				continue;
			}
			$out[] = self::normalize_url( $href, $home_url );
		}
		return array_values( array_unique( $out ) );
	}

	/** PURE: extrae URLs de shortcodes de Divi (button_url, url, image_url, …). */
	public static function divi_links( string $html, string $home_url ): array
	{
		$out = array();
		$home_host = strtolower( (string) ( parse_url( $home_url, PHP_URL_HOST ) ?: '' ) );
		$attr = '(?:button_url|url|link_url|href|button_link|video_url|image_url|slide_image)';
		if ( ! preg_match_all( '/(?:' . $attr . ')\s*=\s*(["\'])(.*?)\1/i', $html, $m ) ) {
			return $out;
		}
		foreach ( $m[2] as $raw ) {
			$href = html_entity_decode( trim( $raw ), ENT_QUOTES );
			if ( $href === '' || strpos( $href, '#' ) === 0 || strpos( $href, 'url(' ) === 0 ) {
				continue;
			}
			if ( strpos( $href, 'mailto:' ) === 0 || strpos( $href, 'tel:' ) === 0 || strpos( $href, 'javascript:' ) === 0 ) {
				continue;
			}
			$host = strtolower( (string) ( parse_url( $href, PHP_URL_HOST ) ?: '' ) );
			if ( $host !== '' && $host !== $home_host ) {
				continue;
			}
			$out[] = self::normalize_url( $href, $home_url );
		}
		return array_values( array_unique( $out ) );
	}

	// ── Construcción del grafo ──────────────────────────────────────────────

	/** PURE: clasifica un nodo (active / visible / draft / trash / home). */
	public static function classify_node( array $node, bool $extra_traffic = false ): string
	{
		$status = (string) ( $node['status'] ?? '' );
		if ( $status === 'trash' ) {
			return 'trash';
		}
		if ( $status !== 'publish' ) {
			return 'draft';
		}
		if ( ! empty( $node['is_front'] ) ) {
			return 'home';
		}
		$traffic = ( (int) ( $node['views_total'] ?? 0 ) > 0 ) || ( (int) ( $node['views_30'] ?? 0 ) > 0 ) || $extra_traffic;
		return $traffic ? 'active' : 'visible';
	}

	/** PURE: construye los edges entre nodos (menu + jerarquía + contenido) y
	 *  la lista de vínculos sin destino. `$redirect_map` mapea URLs normalizadas
	 *  → key de nodo destino (redirects ya resueltos). Devuelve:
	 *  [ 'edges' => […], 'unresolved' => [ {from, from_url, from_title, target, kind} ] ]. */
	public static function build_edges( array $nodes, array $menu_items = array(), array $redirect_map = array() ): array
	{
		$edges      = array();
		$unresolved = array();
		$seen       = array();
		$by_id      = array();
		$by_url     = array();
		$key_info   = array();
		$root_key   = '';
		foreach ( $nodes as $n ) {
			$by_id[ (int) ( $n['id'] ?? 0 ) ] = $n['key'];
			$key_info[ $n['key'] ]            = $n;
			if ( ! empty( $n['url'] ) ) {
				$by_url[ self::normalize_url( (string) $n['url'] ) ] = $n['key'];
			}
			if ( ! empty( $n['is_front'] ) ) {
				$root_key = $n['key'];
			}
		}
		if ( $root_key === '' && isset( $nodes[0]['key'] ) ) {
			$root_key = $nodes[0]['key'];
		}

		$unresolved_for = static function ( string $target, string $from_key, string $kind ) use ( &$unresolved, $key_info ) {
			$unresolved[] = array(
				'from'       => $from_key,
				'from_url'   => (string) ( $key_info[ $from_key ]['url'] ?? '' ),
				'from_title' => (string) ( $key_info[ $from_key ]['title'] ?? ( $from_key === 'menu' ? 'Menú de navegación' : '' ) ),
				'target'     => $target,
				'kind'       => $kind,
			);
		};

		// Jerarquía de páginas (post_parent).
		foreach ( $nodes as $n ) {
			$pid = (int) ( $n['id'] ?? 0 );
			$par = (int) ( $n['parent'] ?? 0 );
			if ( $par > 0 && isset( $by_id[ $pid ] ) && isset( $by_id[ $par ] ) ) {
				self::push_edge( $edges, $seen, $by_id[ $par ], $by_id[ $pid ], 'hierarchy' );
			}
		}

		// Menú de navegación.
		if ( $menu_items ) {
			$item_page = array();
			foreach ( $menu_items as $mi ) {
				$item_page[ (int) $mi->ID ] = (int) ( $mi->object_id ?? 0 );
			}
			foreach ( $menu_items as $mi ) {
				$child = (int) ( $mi->object_id ?? 0 );
				$ppar  = (int) ( $mi->menu_item_parent ?? 0 );
				$type  = (string) ( $mi->type ?? '' );
				if ( $child <= 0 && $type === 'custom' ) {
					// Link personalizado del menú: resolver contra nodos/redirects.
					$mi_url = (string) ( $mi->url ?? '' );
					if ( $mi_url !== '' ) {
						$norm = self::normalize_url( $mi_url );
						$to   = $by_url[ $norm ] ?? ( $redirect_map[ $norm ] ?? '' );
						if ( $to !== '' && $to !== $root_key ) {
							self::push_edge( $edges, $seen, $root_key, $to, 'menu' );
						} else {
							$unresolved_for( $norm, $root_key, 'menu' );
						}
					}
					continue;
				}
				if ( $child <= 0 || ! isset( $by_id[ $child ] ) ) {
					continue;
				}
				if ( $ppar > 0 && isset( $item_page[ $ppar ] ) && isset( $by_id[ $item_page[ $ppar ] ] ) ) {
					$src = $by_id[ $item_page[ $ppar ] ];
				} else {
					$src = $root_key;
				}
				if ( $src !== '' && $src !== $by_id[ $child ] ) {
					self::push_edge( $edges, $seen, $src, $by_id[ $child ], 'menu' );
				}
			}
		}

		// Enlaces internos del contenido.
		foreach ( $nodes as $n ) {
			if ( empty( $n['links'] ) || empty( $n['url'] ) ) {
				continue;
			}
			$from = $n['key'];
			foreach ( (array) $n['links'] as $lnk ) {
				$norm = self::normalize_url( (string) $lnk );
				$to   = $by_url[ $norm ] ?? ( $redirect_map[ $norm ] ?? '' );
				if ( $to !== '' && $to !== $from ) {
					self::push_edge( $edges, $seen, $from, $to, 'content' );
				} elseif ( $to === '' ) {
					$unresolved_for( $norm, $from, 'content' );
				}
			}
		}

		return array( 'edges' => $edges, 'unresolved' => $unresolved );
	}

	/** PURE: agrega un edge sin duplicados (from,to,kind). */
	private static function push_edge( array &$edges, array &$seen, string $from, string $to, string $kind ): void
	{
		$sig = $from . '>' . $to . '>' . $kind;
		if ( isset( $seen[ $sig ] ) ) {
			return;
		}
		$seen[ $sig ] = 1;
		$edges[] = array( 'from' => $from, 'to' => $to, 'kind' => $kind );
	}

	/** Menú(s) de navegación del sitio (read-only). Lee locations Y todos los
	 *  menús (los headers de Divi Theme Builder usan un menú sin location). */
	public static function nav_menu_items(): array
	{
		$items = array();
		if ( ! function_exists( 'wp_get_nav_menu_items' ) ) {
			return $items;
		}
		$seen_ids = array();
		$add = static function ( $it ) use ( &$items, &$seen_ids ) {
			if ( ! is_array( $it ) ) {
				return;
			}
			foreach ( $it as $mi ) {
				$iid = (int) ( $mi->ID ?? 0 );
				if ( $iid > 0 && isset( $seen_ids[ $iid ] ) ) {
					continue;
				}
				if ( $iid > 0 ) {
					$seen_ids[ $iid ] = 1;
				}
				$items[] = $mi;
			}
		};
		$locations = get_nav_menu_locations();
		if ( is_array( $locations ) ) {
			foreach ( $locations as $loc => $menu_id ) {
				$add( (int) $menu_id > 0 ? wp_get_nav_menu_items( (int) $menu_id ) : false );
			}
		}
		if ( function_exists( 'wp_get_nav_menus' ) ) {
			$menus = wp_get_nav_menus();
			foreach ( (array) $menus as $menu ) {
				if ( isset( $menu->term_id ) ) {
					$add( wp_get_nav_menu_items( (int) $menu->term_id ) );
				}
			}
		}
		return $items;
	}

	/** Tipos de contenido del mapa (settings). `page` siempre incluida. */
	public static function map_post_types(): array
	{
		$types = (array) ( self::settings()['post_types'] ?? array( 'page' ) );
		$types = array_values( array_filter( $types, static function ( $t ) {
			return is_string( $t ) && $t !== '';
		} ) );
		if ( ! in_array( 'page', $types, true ) ) {
			array_unshift( $types, 'page' );
		}
		return array_values( array_unique( $types ) );
	}

	/** Tipos seleccionables en la UI: page (fija) + post + CPT públicos. */
	public static function available_post_types(): array
	{
		$out = array( 'page' => 'Páginas' );
		foreach ( (array) get_post_types( array( 'public' => true ), 'objects' ) as $t => $obj ) {
			if ( in_array( $t, array( 'page', 'attachment' ), true ) ) {
				continue;
			}
			$out[ $t ] = $t === 'post' ? 'Entradas' : (string) ( $obj->label ?? $t );
		}
		return $out;
	}

	/** Colecciona los posts del mapa (published/draft/private/future/pending). */
	public static function collect_posts(): array
	{
		$types    = self::map_post_types();
		$statuses = array( 'publish', 'draft', 'private', 'future', 'pending' );
		$args     = array(
			'post_type'        => $types,
			'post_status'      => $statuses,
			'posts_per_page'   => 1500,
			'orderby'          => 'post_date',
			'order'            => 'DESC',
			'suppress_filters' => true,
		);
		$posts = get_posts( $args );
		return is_array( $posts ) ? $posts : array();
	}

	/** Posts en la papelera (secundario, para el bloque "Papelera"). */
	public static function trashed_posts(): array
	{
		$posts = get_posts( array(
			'post_type'        => self::map_post_types(),
			'post_status'      => 'trash',
			'posts_per_page'   => 300,
			'orderby'          => 'post_date',
			'order'            => 'DESC',
			'suppress_filters' => true,
		) );
		return is_array( $posts ) ? $posts : array();
	}

	/** PURE: reduce un WP_Post a nodo (metadatos + enlaces ya resueltos). */
	public static function post_to_node( $post, string $home_url, array $in_menu_ids = array() ): array
	{
		$id    = (int) ( $post->ID ?? 0 );
		$url   = $id > 0 ? (string) get_permalink( $post ) : home_url( '/' );
		$stat  = (string) ( $post->post_status ?? 'publish' );
		$links = array();
		if ( is_string( $post->post_content ?? null ) && $post->post_content !== '' ) {
			$links = array_merge(
				self::internal_links( $post->post_content, $home_url ),
				self::divi_links( $post->post_content, $home_url )
			);
			$links = array_values( array_unique( $links ) );
		}
		return array(
			'key'        => 'p' . $id,
			'id'         => $id,
			'title'      => (string) ( $post->post_title ?? '' ),
			'url'        => $url,
			'status'     => $stat,
			'type'       => (string) ( $post->post_type ?? 'page' ),
			'parent'     => (int) ( $post->post_parent ?? 0 ),
			'author'     => self::post_author_name( $post ),
			'date'       => (string) ( $post->post_date ?? '' ),
			'modified'   => (string) ( $post->post_modified ?? '' ),
			'in_menu'    => $id > 0 && isset( $in_menu_ids[ $id ] ),
			'is_front'   => false,
			'is_landing' => false,
			'links'      => $links,
		);
	}

	/** Nombre visible del autor de un post. */
	private static function post_author_name( $post ): string
	{
		$uid = (int) ( $post->post_author ?? 0 );
		if ( $uid <= 0 || ! function_exists( 'get_userdata' ) ) {
			return '';
		}
		$u = get_userdata( $uid );
		return $u ? (string) $u->display_name : '';
	}

	/** Portada del sitio (estática o índice virtual). */
	public static function front_node( array $nodes ): array
	{
		$home = home_url( '/' );
		foreach ( $nodes as $n ) {
			if ( ! empty( $n['is_front'] ) ) {
				return $n;
			}
		}
		$page_on_front = (int) get_option( 'page_on_front' );
		if ( $page_on_front > 0 ) {
			foreach ( $nodes as $n ) {
				if ( (int) ( $n['id'] ?? 0 ) === $page_on_front ) {
					$n['is_front'] = true;
					return $n;
				}
			}
		}
		// Índice de entradas: nodo virtual "home".
		return array(
			'key'        => 'home',
			'id'         => 0,
			'title'      => 'Portada (índice de entradas)',
			'url'        => $home,
			'status'     => 'publish',
			'type'       => 'home',
			'parent'     => 0,
			'author'     => '',
			'date'       => '',
			'modified'   => '',
			'in_menu'    => true,
			'is_front'   => true,
			'is_landing' => false,
			'links'      => array(),
		);
	}

	/** Captura de pantalla de un nodo (manual → automática). */
	public static function shot_url( array $node, ?array $settings = null ): array
	{
		$settings = is_array( $settings ) ? $settings : self::settings();
		$shots    = get_option( self::OPTION_SHOTS, array() );
		$shots    = is_array( $shots ) ? $shots : array();
		$id       = (int) ( $node['id'] ?? 0 );

		if ( $id > 0 && ! empty( $shots[ $id ] ) && function_exists( 'wp_get_attachment_image_url' ) ) {
			$img = wp_get_attachment_image_url( (int) $shots[ $id ], 'medium' );
			if ( $img ) {
				return array( 'url' => $img, 'manual' => true );
			}
		}
		if ( empty( $settings['screenshots_enabled'] ) || $settings['screenshot_service'] !== 'mshots' || empty( $node['url'] ) ) {
			return array( 'url' => '', 'manual' => false );
		}
		return array(
			'url'    => 'https://s0.wp.com/mshots/v1/' . rawurlencode( (string) $node['url'] ) . '?w=480&h=300',
			'manual' => false,
		);
	}

	/**
	 * Resuelve redirects de una URL (WP context). Resultado cacheado por path
	 * en una option (path → URL final) para no golpear el sitio por cada build.
	 * Devuelve la URL final normalizada, o '' si no se pudo resolver.
	 */
	public static function resolve_redirect_url( string $url ): string
	{
		$url   = self::normalize_url( $url );
		$key   = self::ga4_path_of( $url );
		$cache = get_option( self::OPTION_REDIRECTS, array() );
		$cache = is_array( $cache ) ? $cache : array();
		if ( array_key_exists( $key, $cache ) ) {
			return (string) $cache[ $key ];
		}

		$current = $url;
		$final   = '';
		for ( $i = 0; $i < 5; $i++ ) {
			$resp = wp_remote_head( $current, array(
				'timeout'     => 8,
				'redirection' => 0,
				'sslverify'   => true,
			) );
			if ( is_wp_error( $resp ) ) {
				break;
			}
			$code = (int) wp_remote_retrieve_response_code( $resp );
			if ( $code >= 300 && $code < 400 ) {
				$loc = (string) wp_remote_retrieve_header( $resp, 'location' );
				if ( $loc === '' ) {
					break;
				}
				$current = self::normalize_url( $loc, $current );
				continue;
			}
			$final = $current;
			break;
		}
		$cache[ $key ] = $final;
		update_option( self::OPTION_REDIRECTS, $cache, false );
		return $final;
	}

	/** Grafo completo: nodos + edges + métricas. */
	public static function graph( int $days = 0 ): array
	{
		$days     = $days > 0 ? $days : self::default_days();
		$home_url = home_url( '/' );
		$posts    = self::collect_posts();
		$menu     = self::nav_menu_items();
		$in_menu  = array();
		foreach ( $menu as $mi ) {
			$oid = (int) ( $mi->object_id ?? 0 );
			if ( $oid > 0 ) {
				$in_menu[ $oid ] = 1;
			}
		}

		$nodes = array();
		foreach ( $posts as $post ) {
			$nodes[] = self::post_to_node( $post, $home_url, $in_menu );
		}
		$nodes[] = self::front_node( $nodes );

		// Mapa URL normalizada → key de nodo.
		$node_urls = array();
		foreach ( $nodes as $n ) {
			$node_urls[ self::normalize_url( (string) $n['url'] ) ] = $n['key'];
		}

		// Resolver redirects de links sin nodo directo (content + menú custom),
		// con tope por build para no golpear el sitio.
		$need_resolve = array();
		foreach ( $nodes as $n ) {
			foreach ( (array) ( $n['links'] ?? array() ) as $lnk ) {
				$norm = self::normalize_url( (string) $lnk );
				if ( ! isset( $node_urls[ $norm ] ) ) {
					$need_resolve[ $norm ] = true;
				}
			}
		}
		foreach ( $menu as $mi ) {
			if ( (string) ( $mi->type ?? '' ) !== 'custom' ) {
				continue;
			}
			$mi_url = (string) ( $mi->url ?? '' );
			if ( $mi_url === '' ) {
				continue;
			}
			$norm = self::normalize_url( $mi_url, $home_url );
			if ( ! isset( $node_urls[ $norm ] ) ) {
				$need_resolve[ $norm ] = true;
			}
		}
		$redirect_map = array();
		$resolved     = 0;
		foreach ( array_keys( $need_resolve ) as $target ) {
			if ( $resolved >= 40 ) {
				break;
			}
			$final = self::resolve_redirect_url( $target );
			if ( $final !== '' && isset( $node_urls[ $final ] ) ) {
				$redirect_map[ $target ] = $node_urls[ $final ];
				$resolved++;
			}
		}

		$built = self::build_edges( $nodes, $menu, $redirect_map );
		$edges = $built['edges'];
		$unresolved = $built['unresolved'];

		// Enriquecer con métricas y clasificación (los links ya se consumieron).
		$settings = self::settings();
		$ga4      = self::ga4_report( $days );
		foreach ( $nodes as &$n ) {
			$stats = self::page_stats( (string) $n['url'], $days, $ga4 );
			$n['views_total'] = (int) $stats['views_total'];
			$n['views_30']    = (int) $stats['views_30'];
			$n['last_viewed'] = (string) $stats['last_viewed'];
			if ( isset( $stats['ga4_views_total'] ) ) {
				$n['ga4_views_30']    = (int) $stats['ga4_views_30'];
				$n['ga4_views_total'] = (int) $stats['ga4_views_total'];
			}
			$n['is_landing']  = $n['status'] === 'publish' && ! $n['in_menu'] && (int) ( $n['parent'] ?? 0 ) === 0 && (int) ( $n['id'] ?? 0 ) > 0;
			$shot = self::shot_url( $n, $settings );
			$n['shot']               = (string) $shot['url'];
			$n['has_manual_shot']    = ! empty( $shot['manual'] );
			$extra_traffic = ! empty( $ga4['ok'] ) && (int) ( $n['views_total'] ?? 0 ) === 0 && (int) ( $n['ga4_views_total'] ?? 0 ) > 0;
			$n['class']              = self::classify_node( $n, $extra_traffic );
			unset( $n['links'] );
		}
		unset( $n );

		$meta = array(
			'days'             => $days,
			'home_url'         => $home_url,
			'total_published'  => 0,
			'total_active'     => 0,
			'total_visible'    => 0,
			'total_drafts'     => 0,
			'total_trash'      => count( self::trashed_posts() ),
			'total_views'      => 0,
			'unresolved_count' => count( $unresolved ),
			'analytics_source' => self::analytics_source_label( $ga4 ),
			'ga4'              => array( 'enabled' => ! empty( $ga4['ok'] ), 'error' => (string) ( $ga4['error'] ?? '' ), 'paths' => (int) ( $ga4['count'] ?? 0 ), 'total' => (int) ( $ga4['total'] ?? 0 ) ),
			'external_stats'   => self::has_external_stats(),
			'tracking'         => ! empty( self::settings()['track_enabled'] ),
		);
		foreach ( $nodes as $n ) {
			$meta['total_views'] += (int) $n['views_total'];
			if ( $n['class'] === 'trash' ) {
				continue;
			}
			if ( $n['class'] === 'draft' ) {
				$meta['total_drafts']++;
			} elseif ( $n['status'] === 'publish' ) {
				$meta['total_published']++;
				if ( $n['class'] === 'active' || $n['class'] === 'home' ) {
					$meta['total_active']++;
				} elseif ( $n['class'] === 'visible' ) {
					$meta['total_visible']++;
				}
			}
		}

		return array(
			'nodes'      => array_values( $nodes ),
			'edges'      => $edges,
			'unresolved' => $unresolved,
			'meta'       => $meta,
		);
	}

	// ── Layout (PURE, BFS por capas desde la portada) ──────────────────────

	/**
	 * PURE: posiciona los nodos en capas BFS desde la raíz.
	 * Devuelve la posición en px de cada nodo colocado, los edges con ambos
	 * extremos colocados, la lista de claves colocadas y las huérfanas.
	 */
	public static function layout( array $graph ): array
	{
		$nodes = array_values( $graph['nodes'] );
		$edges = isset( $graph['edges'] ) ? array_values( $graph['edges'] ) : array();

		$keys = array();
		foreach ( $nodes as $n ) {
			$keys[] = (string) ( $n['key'] ?? '' );
		}
		if ( ! $keys ) {
			return array( 'pos' => array(), 'edges' => array(), 'placed' => array(), 'unplaced' => array(), 'width' => 0, 'height' => 0 );
		}

		$adj = array();
		$undir = array();
		foreach ( $keys as $k ) {
			$adj[ $k ] = array();
			$undir[ $k ] = array();
		}
		foreach ( $edges as $e ) {
			if ( isset( $adj[ $e['from'] ] ) && isset( $adj[ $e['to'] ] ) ) {
				$adj[ $e['from'] ][] = $e['to'];
				$undir[ $e['from'] ][] = $e['to'];
				$undir[ $e['to'] ][] = $e['from'];
			}
		}

		// Raíz: nodo is_front o 'home', sino el primero.
		$root = '';
		foreach ( $nodes as $n ) {
			if ( ! empty( $n['is_front'] ) ) {
				$root = (string) $n['key'];
				break;
			}
		}
		if ( $root === '' && isset( $keys[0] ) ) {
			$root = $keys[0];
		}

		// BFS NO dirigido desde la raíz: alcanza cualquier página vinculada
		// (también las que solo enlazan de vuelta a la portada).
		$depth = array();
		$order = array();
		$queue = array( $root );
		$depth[ $root ] = 0;
		$order[ $root ] = 0;
		$qi = 0;
		while ( $qi < count( $queue ) ) {
			$cur = $queue[ $qi++ ];
			$curDepth = $depth[ $cur ];
			foreach ( $undir[ $cur ] as $neighbor ) {
				if ( ! isset( $depth[ $neighbor ] ) ) {
					$depth[ $neighbor ] = $curDepth + 1;
					$order[ $neighbor ] = count( $order );
					$queue[] = $neighbor;
				}
			}
		}

		// Ordenar dentro de cada capa por orden de descubrimiento BFS.
		$layers = array();
		foreach ( $keys as $k ) {
			if ( isset( $depth[ $k ] ) ) {
				$layers[ $depth[ $k ] ][] = $k;
			}
		}
		ksort( $layers );
		foreach ( $layers as $d => &$layer ) {
			usort( $layer, function ( $a, $b ) use ( $order ) {
				return ( $order[ $a ] ?? 0 ) <=> ( $order[ $b ] ?? 0 );
			} );
		}
		unset( $layer );

		$pos = array();
		$maxW = 0;
		$maxCount = 0;
		foreach ( $layers as $d => $layer ) {
			$maxW = max( $maxW, $d );
			$maxCount = max( $maxCount, count( $layer ) );
			foreach ( $layer as $i => $k ) {
				$pos[ $k ] = array(
					'x' => self::PAD + $d * ( self::CARD_W + self::GAP_X ),
					'y' => self::PAD + $i * ( self::CARD_H + self::GAP_Y ),
				);
			}
		}

		$placed = array_keys( $pos );
		$unplaced = array_values( array_diff( $keys, $placed ) );

		$placedEdges = array();
		foreach ( $edges as $e ) {
			if ( isset( $pos[ $e['from'] ] ) && isset( $pos[ $e['to'] ] ) ) {
				$placedEdges[] = $e;
			}
		}

		$width  = $maxW * ( self::CARD_W + self::GAP_X ) + self::CARD_W + self::PAD * 2;
		$height = $maxCount * ( self::CARD_H + self::GAP_Y ) + self::PAD * 2;

		return array(
			'pos'      => $pos,
			'edges'    => $placedEdges,
			'placed'   => $placed,
			'unplaced' => $unplaced,
			'width'    => max( $width, 900 ),
			'height'   => $height,
		);
	}

	// ── REST ────────────────────────────────────────────────────────────────

	public static function rest_routes(): void
	{
		register_rest_route( self::REST_NS, '/sitemap', array(
			'methods'             => 'GET',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => static function ( \WP_REST_Request $request ) {
				$days = (int) $request->get_param( 'days' );
				$g    = SiteMap::graph( $days );
				return rest_ensure_response( array(
					'graph'  => $g,
					'layout' => SiteMap::layout( $g ),
				) );
			},
		) );

		register_rest_route( self::REST_NS, '/sitemap/pageview', array(
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => array( __CLASS__, 'rest_pageview' ),
		) );

		register_rest_route( self::REST_NS, '/sitemap/trash', array(
			'methods'             => 'POST',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => static function ( \WP_REST_Request $request ) {
				$post_id = absint( (int) $request->get_param( 'post_id' ) );
				$post    = get_post( $post_id );
				if ( ! $post ) {
					return new \WP_Error( 'es_sitemap_no_post', 'No existe la página.', array( 'status' => 404 ) );
				}
				if ( $post->post_status === 'trash' ) {
					return new \WP_Error( 'es_sitemap_already_trashed', 'La página ya está en la papelera.', array( 'status' => 400 ) );
				}
				wp_trash_post( $post_id );
				return rest_ensure_response( array( 'ok' => true, 'post_id' => $post_id ) );
			},
		) );

		register_rest_route( self::REST_NS, '/sitemap/restore', array(
			'methods'             => 'POST',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => static function ( \WP_REST_Request $request ) {
				$post_id = absint( (int) $request->get_param( 'post_id' ) );
				$post    = get_post( $post_id );
				if ( ! $post ) {
					return new \WP_Error( 'es_sitemap_no_post', 'No existe la página.', array( 'status' => 404 ) );
				}
				if ( $post->post_status !== 'trash' ) {
					return new \WP_Error( 'es_sitemap_not_trashed', 'La página no está en la papelera.', array( 'status' => 400 ) );
				}
				wp_untrash_post( $post_id );
				return rest_ensure_response( array( 'ok' => true, 'post_id' => $post_id ) );
			},
		) );

		register_rest_route( self::REST_NS, '/sitemap/shot', array(
			'methods'             => 'POST',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => static function ( \WP_REST_Request $request ) {
				$post_id       = absint( (int) $request->get_param( 'post_id' ) );
				$attachment_id = absint( (int) $request->get_param( 'attachment_id' ) );
				if ( $post_id <= 0 || ! get_post( $post_id ) ) {
					return new \WP_Error( 'es_sitemap_no_post', 'No existe la página.', array( 'status' => 404 ) );
				}
				$shots = get_option( SiteMap::OPTION_SHOTS, array() );
				$shots = is_array( $shots ) ? $shots : array();
				if ( $attachment_id > 0 ) {
					$shots[ $post_id ] = $attachment_id;
				} else {
					unset( $shots[ $post_id ] );
				}
				update_option( SiteMap::OPTION_SHOTS, $shots, false );
				return rest_ensure_response( array( 'ok' => true, 'post_id' => $post_id, 'attachment_id' => $attachment_id ) );
			},
		) );

		register_rest_route( self::REST_NS, '/sitemap/settings', array(
			'methods'             => 'POST',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => static function ( \WP_REST_Request $request ) {
				$raw   = $request->get_params();
				$clean = SiteMap::save_settings( $raw );
				return rest_ensure_response( array( 'ok' => true, 'settings' => $clean ) );
			},
		) );

		register_rest_route( self::REST_NS, '/sitemap/ga4/test', array(
			'methods'             => 'POST',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => static function () {
				return rest_ensure_response( SiteMap::ga4_test() );
			},
		) );
	}

	// ── Render del sub-tab "Mapa del sitio" ─────────────────────────────────

	public static function render_admin(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		$settings = self::settings();
		$initial  = self::graph( (int) $settings['days'] );
		$layout   = self::layout( $initial );

		echo '<style>' . self::admin_css() . '</style>';
		echo '<div class="wrap" id="es-sitemap-root">';

		// ── Toolbar ──
		echo '<div class="es-sm-toolbar">';
		echo '<div class="es-sm-summary">';
		echo '<span class="es-sm-chip es-sm-chip-active"><strong>' . (int) $initial['meta']['total_active'] . '</strong> activas</span>';
		echo '<span class="es-sm-chip es-sm-chip-visible"><strong>' . (int) $initial['meta']['total_visible'] . '</strong> publicadas sin tráfico</span>';
		echo '<span class="es-sm-chip es-sm-chip-draft"><strong>' . (int) $initial['meta']['total_drafts'] . '</strong> borradores</span>';
		echo '<span class="es-sm-chip es-sm-chip-trash"><strong>' . (int) $initial['meta']['total_trash'] . '</strong> papelera</span>';
		echo '<span class="es-sm-chip"><strong>' . number_format_i18n( (int) $initial['meta']['total_views'] ) . '</strong> visitas</span>';
		echo '</div>';
		echo '<div class="es-sm-controls">';
		echo '<label>Rango <select id="es-sm-days">';
		foreach ( array( 7, 30, 90, 365 ) as $d ) {
			echo '<option value="' . $d . '"' . selected( (int) $settings['days'], $d, false ) . '>' . $d . ' días</option>';
		}
		echo '</select></label>';
		echo '<button type="button" class="button" id="es-sm-refresh">Actualizar</button>';
		echo '<button type="button" class="button" id="es-sm-settings-toggle">Ajustes</button>';
		echo '<span class="es-sm-source">Fuente: ' . esc_html( (string) ( $initial['meta']['analytics_source'] ?? '' ) ) . '</span>';
		echo '</div>';
		echo '</div>';

		// ── Settings (collapsible) ──
		echo '<div id="es-sm-settings" style="display:none;" class="es-sm-settings">';
		echo '<h3>Ajustes del mapa del sitio</h3>';
		echo '<label style="display:block;margin:6px 0;"><input type="checkbox" id="es-sm-track" ' . checked( ! empty( $settings['track_enabled'] ), true, false ) . '> Tracking ligero de visitas (beacon async)</label>';
		echo '<label style="display:block;margin:6px 0;"><input type="checkbox" id="es-sm-exclude-self" ' . checked( ! empty( $settings['exclude_self'] ), true, false ) . '> Excluir mis propias visitas (admin logueado / IPs propias)</label>';
		echo '<label style="display:block;margin:6px 0;">IPs propias adicionales a excluir (separadas por coma) <input id="es-sm-exclude-ips" type="text" value="' . esc_attr( implode( ', ', (array) ( $settings['exclude_ips'] ?? array() ) ) ) . '" placeholder="186.79.x.x, 201.215.x.x" style="width:280px;"></label>';
		echo '<p class="description">Por defecto el tráfico propio no se cuenta en sesiones/pageviews (desmarca la casilla para incluirlo).</p>';
		echo '<label style="display:block;margin:6px 0;"><input type="checkbox" id="es-sm-shots" ' . checked( ! empty( $settings['screenshots_enabled'] ), true, false ) . '> Capturas de pantalla automáticas (mshots)</label>';
		echo '<p class="description">Puedes subir una captura manual por página desde su tarjeta (fallback). La captura automática se genera bajo demanda la primera vez.</p>';

		self::render_post_types_settings( $settings );

		self::render_ga4_settings( $settings );

		echo '<button type="button" class="button button-primary" id="es-sm-save-settings">Guardar ajustes</button>';
		echo ' <span id="es-sm-ga4-status" class="description"></span>';
		echo '</div>';

		// ── Leyenda ──
		echo '<div class="es-sm-legend">';
		echo '<span class="es-sm-legend-item es-sm-legend-home">Portada</span>';
		echo '<span class="es-sm-legend-item es-sm-legend-active">Activa (publicada + tráfico)</span>';
		echo '<span class="es-sm-legend-item es-sm-legend-visible">Publicada sin tráfico</span>';
		echo '<span class="es-sm-legend-item es-sm-legend-draft">Borrador / privada</span>';
		echo '<span class="es-sm-legend-item es-sm-legend-trash">Papelera</span>';
		echo '</div>';

		// ── Canvas ──
		echo '<div class="es-sm-canvas-wrap"><div id="es-sm-canvas" class="es-sm-canvas"></div></div>';

		// ── Secundarias: borradores, papelera y vínculos sin destino ──
		self::render_secondary_lists();
		self::render_unresolved( $initial );

		self::admin_assets();
		echo '</div>';

		// Estado inicial embebido para el JS.
		echo '<script type="application/json" id="es-sm-graph">' . self::safe_json( $initial ) . '</script>';
		echo '<script type="application/json" id="es-sm-layout">' . self::safe_json( $layout ) . '</script>';
	}

	/** PURE: JSON con `</script>` escapado para incrustar en HTML. */
	public static function safe_json( $value ): string
	{
		return str_replace( '</', '<\\/', (string) wp_json_encode( $value ) );
	}

	/** Bloque "Tipos de contenido en el mapa" dentro de los ajustes. */
	private static function render_post_types_settings( array $settings ): void
	{
		echo '<hr style="margin:14px 0;border:none;border-top:1px solid #e4e5e7;">';
		echo '<h3 style="margin:0 0 6px;">Tipos de contenido en el mapa <span class="description">— por defecto solo páginas</span></h3>';
		echo '<p class="description">El mapa muestra <strong>Páginas</strong> siempre. Marca otros tipos (Entradas, proyectos, etc.) para incluirlos como nodos.</p>';
		$selected = (array) ( $settings['post_types'] ?? array( 'page' ) );
		foreach ( self::available_post_types() as $slug => $label ) {
			$fixed = $slug === 'page';
			$name  = $fixed ? 'es-sm-pt-fixed' : 'es-sm-pt';
			echo '<label style="display:block;margin:5px 0;"><input type="checkbox" name="' . $name . '" value="' . esc_attr( $slug ) . '" '
				. ( $fixed ? 'checked disabled' : checked( in_array( $slug, $selected, true ), true, false ) )
				. '> ' . esc_html( $label ) . ( $fixed ? ' <span class="description">(fija)</span>' : '' ) . '</label>';
		}
	}

	/** Bloque de configuración GA4 dentro de los ajustes del mapa. */
	private static function render_ga4_settings( array $settings ): void
	{
		echo '<hr style="margin:14px 0;border:none;border-top:1px solid #e4e5e7;">';
		echo '<h3 style="margin:0 0 6px;">Google Analytics (GA4) <span class="description">— extra opcional de referencia</span></h3>';
		echo '<p class="description">Nuestra analytics (lectura directa del servidor) es la <strong>fuente principal</strong> y la más precisa. GA4 es un <strong>extra de referencia</strong> que se muestra aparte en cada tarjeta (no reemplaza ni suma a nuestras cifras). GTM no guarda datos: las visitas de referencia viven en GA4. Conecta la cuenta con scope <code>analytics.readonly</code> y escribe el <strong>Property ID</strong> (GA4 → Admin → Propiedad → ID de propiedad). El reporte se cachea 30 min.</p>';

		$connections = array();
		if ( class_exists( '\\Estandar\\Core\\Connections' ) && method_exists( '\\Estandar\\Core\\Connections', 'list' ) ) {
			$connections = Connections::list( 'google' );
		}
		$scope_ok = (int) $settings['ga4_connection_id'] > 0 && self::ga4_connection_scopes_ok( (int) $settings['ga4_connection_id'] );

		echo '<label style="display:block;margin:8px 0 4px;"><input type="checkbox" id="es-sm-ga4-enabled" ' . checked( ! empty( $settings['ga4_enabled'] ), true, false ) . '> Usar GA4 como referencia extra</label>';
		echo '<label style="display:block;margin:6px 0;">Cuenta Google ';
		echo '<select id="es-sm-ga4-conn">';
		echo '<option value="0">— elige cuenta —</option>';
		foreach ( $connections as $c ) {
			$scopes = json_decode( (string) ( $c['scopes_json'] ?? '[]' ), true );
			$has    = is_array( $scopes ) && in_array( self::GA4_SCOPE, $scopes, true );
			$badge  = $has ? ' · con GA4' : ' · sin scope GA4';
			echo '<option value="' . (int) $c['id'] . '"' . selected( (int) $settings['ga4_connection_id'], (int) $c['id'], false ) . '>' . esc_html( (string) ( $c['label'] ?? 'Google' ) . ' (#' . (int) $c['id'] . $badge . ')' ) . '</option>';
		}
		echo '</select> '
			. '<button type="button" class="button" id="es-sm-ga4-connect">' . ( empty( $connections ) ? 'Conectar Google Analytics' : 'Reconectar (añadir scope GA4)' ) . '</button></label>';
		echo '<label style="display:block;margin:6px 0;">Property ID GA4 <input id="es-sm-ga4-property" type="text" value="' . esc_attr( (string) $settings['ga4_property_id'] ) . '" placeholder="123456789" style="width:160px;"></label>';
		echo '<p style="margin:4px 0;">'
			. '<button type="button" class="button" id="es-sm-ga4-test">Probar conexión GA4</button> '
			. '<span id="es-sm-ga4-test-result" class="description"></span></p>';
		if ( ! empty( $settings['ga4_enabled'] ) && (int) $settings['ga4_connection_id'] > 0 && ! $scope_ok ) {
			echo '<div class="notice notice-warning inline" style="margin:8px 0;"><p>La cuenta seleccionada no tiene el scope <code>analytics.readonly</code>. Usa "Reconectar" para autorizar GA4 (misma cuenta, no necesitas nuevas credenciales).</p></div>';
		}
	}

	/** Vínculos del contenido/menú que no resuelven a ningún nodo del mapa. */
	private static function render_unresolved( array $graph ): void
	{
		$list = (array) ( $graph['unresolved'] ?? array() );
		if ( empty( $list ) ) {
			return;
		}
		echo '<div class="es-sm-secondary es-sm-unresolved">';
		echo '<h3>Vínculos sin destino (' . count( $list ) . ') <span class="description">— enlaces que no resuelven a ninguna página del mapa (404, redirect no resuelto, URL huérfana)</span></h3>';
		echo '<table class="widefat striped"><thead><tr><th>Desde</th><th>Origen</th><th>Destino</th><th>Tipo</th></tr></thead><tbody>';
		$from_cache = array();
		foreach ( $list as $u ) {
			$from  = (string) ( $u['from'] ?? '' );
			$furl  = (string) ( $u['from_url'] ?? '' );
			$ftit  = (string) ( $u['from_title'] ?? '' );
			$tgt   = (string) ( $u['target'] ?? '' );
			$kind  = (string) ( $u['kind'] ?? 'content' );
			if ( ! isset( $from_cache[ $from ] ) ) {
				$from_cache[ $from ] = $furl !== '' ? '<a href="' . esc_url( $furl ) . '" target="_blank" rel="noopener">' . esc_html( $ftit !== '' ? $ftit : $furl ) . '</a>' : esc_html( $from );
			}
			echo '<tr><td>' . $from_cache[ $from ] . '</td>'
				. '<td><code>' . esc_html( $furl ) . '</code></td>'
				. '<td><code>' . esc_html( $tgt ) . '</code></td>'
				. '<td>' . esc_html( $kind ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/** Listas secundarias de borradores y papelera. */
	private static function render_secondary_lists(): void
	{
		$trash = self::trashed_posts();
		$drafts = get_posts( array(
			'post_type'      => self::map_post_types(),
			'post_status'    => array( 'draft', 'private', 'future', 'pending' ),
			'posts_per_page' => 200,
			'orderby'        => 'post_date',
			'order'          => 'DESC',
			'suppress_filters' => true,
		) );

		echo '<div class="es-sm-secondary">';

		echo '<h3>Borradores y no publicadas (' . count( $drafts ) . ')</h3>';
		echo '<table class="widefat striped">';
		echo '<thead><tr><th>Título</th><th>Tipo</th><th>Autor</th><th>Modificada</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>';
		foreach ( $drafts as $d ) {
			echo '<tr>'
				. '<td>' . esc_html( (string) $d->post_title ) . '</td>'
				. '<td>' . esc_html( (string) $d->post_type ) . '</td>'
				. '<td>' . esc_html( self::post_author_name( $d ) ) . '</td>'
				. '<td>' . esc_html( (string) $d->post_modified ) . '</td>'
				. '<td>' . esc_html( (string) $d->post_status ) . '</td>'
				. '<td><a class="button button-small" href="' . esc_url( get_edit_post_link( (int) $d->ID ) ) . '">Editar</a> '
				. '<button type="button" class="button button-small es-sm-trash" data-id="' . (int) $d->ID . '">Enviar a papelera</button></td>'
				. '</tr>';
		}
		if ( empty( $drafts ) ) {
			echo '<tr><td colspan="6">Sin borradores.</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h3>Papelera (' . count( $trash ) . ')</h3>';
		echo '<table class="widefat striped">';
		echo '<thead><tr><th>Título</th><th>Tipo</th><th>Fecha</th><th>Acciones</th></tr></thead><tbody>';
		foreach ( $trash as $t ) {
			echo '<tr>'
				. '<td>' . esc_html( (string) $t->post_title ) . '</td>'
				. '<td>' . esc_html( (string) $t->post_type ) . '</td>'
				. '<td>' . esc_html( (string) $t->post_date ) . '</td>'
				. '<td><button type="button" class="button button-small es-sm-restore" data-id="' . (int) $t->ID . '">Restaurar</button> '
				. '<a class="button button-link-delete button-small" href="' . esc_url( get_delete_post_link( (int) $t->ID ) ) . '">Eliminar definitivamente</a></td>'
				. '</tr>';
		}
		if ( empty( $trash ) ) {
			echo '<tr><td colspan="4">La papelera está vacía.</td></tr>';
		}
		echo '</tbody></table>';

		echo '</div>';
	}

	/** CSS inline del mapa (admin no pasa por SG combine). */
	public static function admin_css(): string
	{
		return '
.es-sm-toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:14px 0 10px;}
.es-sm-summary{display:flex;gap:8px;flex-wrap:wrap;}
.es-sm-chip{background:#fff;border:1px solid #dcdcde;border-radius:14px;padding:3px 12px;font-size:12px;}
.es-sm-chip-active{background:#ecf7ed;border-color:#9bd6a2;}
.es-sm-chip-visible{background:#fdf6e3;border-color:#e2c76a;}
.es-sm-chip-draft{background:#f3f3f3;border-color:#c3c4c7;}
.es-sm-chip-trash{background:#fbeaea;border-color:#e1a9a9;}
.es-sm-controls{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
.es-sm-source{font-size:11px;color:#646970;}
.es-sm-settings{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:14px 16px;margin:10px 0;max-width:720px;}
.es-sm-legend{display:flex;gap:14px;flex-wrap:wrap;font-size:12px;color:#50575e;margin:8px 0 6px;}
.es-sm-legend-item::before{content:"";display:inline-block;width:12px;height:12px;border-radius:3px;margin-right:6px;vertical-align:-1px;}
.es-sm-legend-home::before{background:#7b5cd6;}
.es-sm-legend-active::before{background:#2fb457;}
.es-sm-legend-visible::before{background:#e2b93b;}
.es-sm-legend-draft::before{background:#a7aaad;}
.es-sm-legend-trash::before{background:#d63638;}
.es-sm-canvas-wrap{overflow:auto;background:#fff;border:1px solid #dcdcde;border-radius:8px;margin:10px 0;padding:4px;}
.es-sm-canvas{position:relative;}
.es-sm-card{position:absolute;width:' . self::CARD_W . 'px;height:' . self::CARD_H . 'px;background:#fff;border:1px solid #dcdcde;border-left:4px solid #c3c4c7;border-radius:8px;box-shadow:0 1px 2px rgba(0,0,0,.06);overflow:hidden;display:flex;flex-direction:column;}
.es-sm-card.es-c-home{border-left-color:#7b5cd6;}
.es-sm-card.es-c-active{border-left-color:#2fb457;}
.es-sm-card.es-c-visible{border-left-color:#e2b93b;}
.es-sm-card.es-c-draft{border-left-color:#a7aaad;opacity:.75;}
.es-sm-card.es-c-trash{border-left-color:#d63638;opacity:.6;}
.es-sm-shot{height:120px;background:#f0f0f1;background-size:cover;background-position:center top;position:relative;}
.es-sm-shot img{width:100%;height:100%;object-fit:cover;}
.es-sm-shot .es-sm-no-shot{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#8c8f94;font-size:11px;}
.es-sm-body{padding:8px 10px;flex:1;display:flex;flex-direction:column;gap:3px;overflow:hidden;}
.es-sm-title{font-weight:600;font-size:13px;line-height:1.25;max-height:34px;overflow:hidden;}
.es-sm-url{font-size:10px;color:#646970;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.es-sm-meta{font-size:11px;color:#50575e;display:flex;flex-wrap:wrap;gap:2px 8px;}
.es-sm-stats{margin-top:auto;display:flex;gap:6px;flex-wrap:wrap;}
.es-sm-stat{background:#f6f7f7;border:1px solid #e4e5e7;border-radius:6px;padding:2px 8px;font-size:11px;}
.es-sm-stat strong{display:block;font-size:13px;}
.es-sm-stat-ga4{background:#eef6ff;border-color:#c5d9f2;color:#2271b1;}
.es-sm-actions{display:flex;gap:4px;padding:6px 10px;border-top:1px solid #f0f0f1;background:#fafafa;}
.es-sm-actions .button{font-size:11px;min-height:24px;line-height:1.4;}
.es-sm-badge{display:inline-block;font-size:9px;border-radius:8px;padding:1px 6px;background:#eef;color:#444;}
.es-sm-badge.es-b-menu{background:#eef6ff;color:#2271b1;}
.es-sm-badge.es-b-landing{background:#fde8e8;color:#b32d2e;}
.es-sm-badge.es-b-home{background:#efe9fb;color:#7b5cd6;}
.es-sm-secondary{margin-top:16px;}
.es-sm-secondary table{max-width:1000px;}
';
	}

	/** Enqueue el renderer del diagrama (asset estático + config inline). */
	public static function admin_assets(): void
	{
		if ( ! function_exists( 'wp_register_script' ) ) {
			return;
		}
		$file = ESTANDAR_SUITE_CORE_PATH . 'includes/assets/estandar-sitemap-admin.js';
		$ver  = file_exists( $file ) ? (string) filemtime( $file ) : ESTANDAR_SUITE_CORE_VERSION;
		wp_register_script(
			'estandar-sitemap-admin',
			plugins_url( 'includes/assets/estandar-sitemap-admin.js', ESTANDAR_SUITE_CORE_FILE ),
			array(),
			$ver,
			true
		);
		wp_add_inline_script( 'estandar-sitemap-admin', 'window.ES_SITEMAP_ADMIN = ' . wp_json_encode( array(
			'rest'   => rest_url( self::REST_NS ),
			'nonce'  => wp_create_nonce( 'wp_rest' ),
			'wp_base'=> admin_url(),
			'cardW'  => self::CARD_W,
			'cardH'  => self::CARD_H,
		) ) . ';', 'before' );
		wp_enqueue_script( 'estandar-sitemap-admin' );
	}
}
