<?php

namespace Estandar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AutoDiag — estado honesto por instalación (MODULO_A / AUTO_DIAG_V1).
 *
 * Cada instalación detecta sola cuándo algo deja de funcionar y lo muestra:
 * tracker sin registros recientes, capturas detenidas, esquema desactualizado,
 * cron caído, errores técnicos recientes. Estados honestos: ausencia de
 * evidencia → UNKNOWN (nunca se inventa éxito). El snapshot se persiste por
 * cron diario y bajo demanda, y es legible por admin / REST / CLI (radar).
 *
 * Convención de checks (par con ChatbotHealth/WhatsAppBot):
 *   { key, group, label, status, level, desc }
 *   level ∈ ok | warn | bad | unknown · overall ∈ OK | ATENCION | FALLO | UNKNOWN
 *
 * El cómputo es PURE sobre `evidence` (testeable standalone); `collect()`
 * reúne la evidencia con guards de DB/WP (nunca lanza).
 */
class AutoDiag
{
	const VERSION = '1.0.0';

	const OPTION = 'estandar_suite_core_selfcheck_snapshot';

	const CRON = 'estandar_suite_selfcheck_daily';

	const LEVELS = array( 'ok', 'warn', 'bad', 'unknown' );

	/** Ventana de sospecha (días): qué tan reciente debe ser un dato para dar OK. */
	const WINDOW_DAYS = 7;

	/** TTL del snapshot cacheado (horas) antes de regenerarlo bajo demanda. */
	const TTL_HOURS = 12;

	const PRODUCT_SLUGS = array(
		'estandar-core',
		'estandar-capture',
		'estandar-brain',
		'estandar-flow',
		'estandar-insights',
		'estandar-experience',
	);

	public static function state_label( string $level ): string
	{
		$map = array( 'ok' => 'OK', 'warn' => 'ATENCION', 'bad' => 'FALLO', 'unknown' => 'UNKNOWN' );
		return $map[ $level ] ?? strtoupper( $level );
	}

	/** PURE: nivel de severidad del overall a partir de los checks. */
	public static function overall_from_checks( array $checks ): string
	{
		$score = array( 'bad' => 3, 'warn' => 2, 'unknown' => 1, 'ok' => 0 );
		$max   = 0;
		$has_ok = false;
		foreach ( $checks as $c ) {
			$lvl = $c['level'] ?? 'unknown';
			$s   = $score[ $lvl ] ?? 1;
			if ( $s > $max ) {
				$max = $s;
			}
			if ( $lvl === 'ok' ) {
				$has_ok = true;
			}
		}
		$level = 'ok';
		foreach ( array( 'bad', 'warn', 'unknown' ) as $l ) {
			if ( $max >= $score[ $l ] ) {
				$level = $l;
				break;
			}
		}
		// unknown no degrada si algo ya está verificado OK (NA informativo).
		if ( $level === 'unknown' && $has_ok ) {
			$level = 'ok';
		}
		return $level;
	}

	// ── Núcleo PURE: cada detector resuelve un check desde evidencia ───────

	public static function default_evidence(): array
	{
		return array(
			'window_days'           => self::WINDOW_DAYS,
			'track_enabled'         => false,
			'sessions_recent'       => 0,
			'sessions_total'        => 0,
			'sessions_last'         => '',
			'pageviews_recent'      => 0,
			'pageviews_total'       => 0,
			'pageviews_last'        => '',
			'external_recent'       => false,
			'external_max'          => '',
			'capture_active'        => false,
			'submissions_recent'    => 0,
			'submissions_total'     => 0,
			'submissions_last'      => '',
			'schemas'               => array(),
			'connections'           => array( 'total' => 0, 'active' => 0, 'inactive' => 0 ),
			'destinations'          => 0,
			'telemetry_errors'      => 0,
			'cron_scheduled'        => false,
			'cron_next'             => 0,
			'snapshot_age_h'        => null,
			'plugins'               => array(),
		);
	}

	public static function check_plugins( array $ev ): array
	{
		$plugins = (array) ( $ev['plugins'] ?? array() );
		if ( ! $plugins ) {
			return array(
				'key' => 'plugins', 'group' => 'sistema', 'label' => 'Plugins de la Suite',
				'status' => 'Sin detectar', 'level' => 'unknown',
				'desc' => 'No se pudo enumerar los productos de la Suite. No se inventa éxito.',
			);
		}
		$missing = array();
		$active  = 0;
		foreach ( $plugins as $slug => $st ) {
			if ( ! empty( $st['installed'] ) && empty( $st['active'] ) ) {
				$missing[] = $slug;
			}
			if ( ! empty( $st['active'] ) ) {
				$active++;
			}
		}
		if ( $missing ) {
			return array(
				'key' => 'plugins', 'group' => 'sistema', 'label' => 'Plugins de la Suite',
				'status' => 'Instalado inactivo', 'level' => 'warn',
				'desc' => 'Instalados pero inactivos: ' . implode( ', ', $missing ) . '. Un overwrite de deploy puede dejar estandar-core inactivo.',
			);
		}
		return array(
			'key' => 'plugins', 'group' => 'sistema', 'label' => 'Plugins de la Suite',
			'status' => sprintf( '%d activos', $active ), 'level' => 'ok',
			'desc' => count( $plugins ) . ' productos de la Suite detectados; todos activos.',
		);
	}

	/** PURE: estado del tracker de visitas (beacon + tablas vivas + sospecha externa). */
	public static function check_tracker( array $ev ): array
	{
		$w  = max( 1, (int) ( $ev['window_days'] ?? self::WINDOW_DAYS ) );
		$sr = (int) ( $ev['sessions_recent'] ?? 0 );
		$pr = (int) ( $ev['pageviews_recent'] ?? 0 );
		$st = (int) ( $ev['sessions_total'] ?? 0 );
		$pt = (int) ( $ev['pageviews_total'] ?? 0 );
		$own = $sr + $pr;
		$total = $st + $pt;
		$last = (string) ( $ev['sessions_last'] ?? '' );
		if ( (string) ( $ev['pageviews_last'] ?? '' ) > $last ) {
			$last = (string) $ev['pageviews_last'];
		}

		if ( empty( $ev['track_enabled'] ) ) {
			return array(
				'key' => 'tracker', 'group' => 'tracker', 'label' => 'Tracker de visitas',
				'status' => 'Desactivado', 'level' => 'warn',
				'desc' => 'track_enabled=0 en Mapa del sitio: el beacon no captura. El Mapa y las cifras propias de visitas quedarán en 0 aunque el sitio reciba tráfico.',
			);
		}
		if ( $own > 0 ) {
			return array(
				'key' => 'tracker', 'group' => 'tracker', 'label' => 'Tracker de visitas',
				'status' => sprintf( '%d sesiones / %d vistas (7d)', $sr, $pr ), 'level' => 'ok',
				'desc' => 'El beacon registra en los últimos ' . $w . ' días' . ( $last !== '' ? ' · última señal ' . $last : '' ) . '.',
			);
		}
		if ( $total === 0 ) {
			return array(
				'key' => 'tracker', 'group' => 'tracker', 'label' => 'Tracker de visitas',
				'status' => 'Sin datos aún', 'level' => 'unknown',
				'desc' => 'track_enabled=1 pero sin sesiones ni pageviews registrados (instalación reciente o sin visitas aún). No se inventa éxito.',
			);
		}
		if ( ! empty( $ev['external_recent'] ) ) {
			return array(
				'key' => 'tracker', 'group' => 'tracker', 'label' => 'Tracker de visitas',
				'status' => 'Sospecha de ceguera', 'level' => 'bad',
				'desc' => 'track_enabled=1 y hay tráfico de relleno externo reciente (' . (string) $ev['external_max'] . '), pero el beacon no registró nada en ' . $w . ' días. Revisa caché combinada de JS (SG), adblock o el beacon.',
			);
		}
		return array(
			'key' => 'tracker', 'group' => 'tracker', 'label' => 'Tracker de visitas',
			'status' => 'Sin registros recientes', 'level' => 'warn',
			'desc' => 'track_enabled=1 y hay historial previo, pero nada en los últimos ' . $w . ' días' . ( $last !== '' ? ' · último dato ' . $last : '' ) . '. Si el sitio recibe tráfico, algo interrumpe el beacon.',
		);
	}

	/** PURE: actividad de captura (submissions no-spam). */
	public static function check_capturas( array $ev ): array
	{
		$w = max( 1, (int) ( $ev['window_days'] ?? self::WINDOW_DAYS ) );
		if ( empty( $ev['capture_active'] ) ) {
			return array(
				'key' => 'capturas', 'group' => 'captura', 'label' => 'Captura de leads',
				'status' => 'No activo', 'level' => 'unknown',
				'desc' => 'estandar-capture no está activo en esta instalación. Sin capturas que vigilar.',
			);
		}
		$recent = (int) ( $ev['submissions_recent'] ?? 0 );
		$total  = (int) ( $ev['submissions_total'] ?? 0 );
		if ( $recent > 0 ) {
			return array(
				'key' => 'capturas', 'group' => 'captura', 'label' => 'Captura de leads',
				'status' => sprintf( '%d envíos (7d)', $recent ), 'level' => 'ok',
				'desc' => 'estandar_capture_submissions con ' . $recent . ' envíos no-spam en los últimos ' . $w . ' días (total ' . $total . ').',
			);
		}
		if ( $total === 0 ) {
			return array(
				'key' => 'capturas', 'group' => 'captura', 'label' => 'Captura de leads',
				'status' => 'Sin capturas aún', 'level' => 'unknown',
				'desc' => 'estandar-capture activo pero la tabla de submissions está vacía (sin envíos nunca). No se inventa éxito.',
			);
		}
		return array(
			'key' => 'capturas', 'group' => 'captura', 'label' => 'Captura de leads',
			'status' => 'En pausa', 'level' => 'warn',
			'desc' => 'Último envío registrado: ' . (string) $ev['submissions_last'] . ' (hace más de ' . $w . ' días). Si esperabas formularios/chatbot/WhatsApp, revisa los catchers.',
		);
	}

	/** PURE: un check por entrada de esquema. */
	public static function check_schema( array $entry ): array
	{
		$actual   = (string) ( $entry['actual'] ?? '' );
		$expected = (string) ( $entry['expected'] ?? '' );
		$label    = (string) ( $entry['label'] ?? 'Esquema' );
		$key      = (string) ( $entry['key'] ?? 'schema' );
		if ( $expected === '' || empty( $entry['active'] ) ) {
			return array(
				'key' => $key, 'group' => 'esquema', 'label' => $label,
				'status' => 'No aplica', 'level' => 'unknown',
				'desc' => 'Producto no instalado/activo o sin versión de esquema legible. No se evalúa.',
			);
		}
		if ( $actual === '' ) {
			return array(
				'key' => $key, 'group' => 'esquema', 'label' => $label,
				'status' => 'Sin migrar', 'level' => 'bad',
				'desc' => 'No existe la opción de versión (esperada ' . $expected . '). Falta correr maybe_migrate/activación.',
			);
		}
		if ( $actual !== $expected ) {
			return array(
				'key' => $key, 'group' => 'esquema', 'label' => $label,
				'status' => 'Desactualizado', 'level' => 'bad',
				'desc' => 'Esquema en ' . $actual . ', código espera ' . $expected . '. Corre la migración (maybe_migrate).',
			);
		}
		return array(
			'key' => $key, 'group' => 'esquema', 'label' => $label,
			'status' => 'Al día', 'level' => 'ok',
			'desc' => 'Versión de esquema ' . $expected . ' coincide con la del código.',
		);
	}

	public static function schema_entries(): array
	{
		return array(
			'core'      => array(
				'key' => 'schema_core', 'label' => 'Esquema Core',
				'option' => \Estandar\Core\Schema::OPTION, 'expected' => \Estandar\Core\Schema::VERSION, 'always' => true,
			),
			'pageviews' => array(
				'key' => 'schema_pageviews', 'label' => 'Esquema tracker (pageviews)',
				'option' => \Estandar\Core\SiteMap::OPTION_SCHEMA, 'expected' => \Estandar\Core\SiteMap::SCHEMA_VERSION, 'always' => true,
			),
			'sessions'  => array(
				'key' => 'schema_sessions', 'label' => 'Esquema sesiones',
				'option' => \Estandar\Core\SiteMap::OPTION_SCHEMA_SESSIONS, 'expected' => \Estandar\Core\SiteMap::SCHEMA_SESSIONS_VERSION, 'always' => true,
			),
			'capture'   => array(
				'key' => 'schema_capture', 'label' => 'Esquema Capture',
				'class' => '\\Estandar\\Capture\\Ingestion', 'option' => 'estandar_suite_capture_schema_version', 'expected' => '1.4.0',
			),
			'insights_events' => array(
				'key' => 'schema_insights_events', 'label' => 'Esquema Insights (eventos)',
				'class' => '\\Estandar\\Insights\\Events', 'option' => 'estandar_suite_insights_schema_version', 'expected' => '1.0.0',
			),
			'insights_analytics' => array(
				'key' => 'schema_insights_analytics', 'label' => 'Esquema Insights (analytics)',
				'class' => '\\Estandar\\Insights\\Analytics', 'option' => 'estandar_suite_insights_analytics_schema_version', 'expected' => '1.0.0',
			),
			'external_daily' => array(
				'key' => 'schema_external_daily', 'label' => 'Esquema Insights (relleno externo)',
				'class' => '\\Estandar\\Insights\\ExternalDaily', 'option' => 'estandar_suite_insights_external_daily_schema_version', 'expected' => '1.0.0',
			),
		);
	}

	/** PURE: junta los checks de esquema con la evidencia de cada entrada. */
	public static function schema_checks( array $ev ): array
	{
		$out = array();
		foreach ( (array) ( $ev['schemas'] ?? array() ) as $entry ) {
			$out[] = self::check_schema( $entry );
		}
		return $out;
	}

	public static function check_conexiones( array $ev ): array
	{
		$c = (array) ( $ev['connections'] ?? array() );
		$total = (int) ( $c['total'] ?? 0 );
		$inact = (int) ( $c['inactive'] ?? 0 );
		if ( $total === 0 ) {
			return array(
				'key' => 'conexiones', 'group' => 'integraciones', 'label' => 'Cuentas conectadas',
				'status' => 'Sin configurar', 'level' => 'unknown',
				'desc' => 'No hay cuentas conectadas (API-Connect). No es una falla si no usas integraciones externas; si deberían existir, revísalas.',
			);
		}
		if ( $inact > 0 ) {
			return array(
				'key' => 'conexiones', 'group' => 'integraciones', 'label' => 'Cuentas conectadas',
				'status' => $inact . ' inactiva(s)', 'level' => 'warn',
				'desc' => $total . ' cuentas registradas, ' . $inact . ' con estado distinto de active (revocada/vencida). Reautentica en API-Connect.',
			);
		}
		return array(
			'key' => 'conexiones', 'group' => 'integraciones', 'label' => 'Cuentas conectadas',
			'status' => $total . ' activa(s)', 'level' => 'ok',
			'desc' => 'Las ' . $total . ' cuentas registradas están en estado active.',
		);
	}

	public static function check_destinos( array $ev ): array
	{
		$n = (int) ( $ev['destinations'] ?? 0 );
		if ( $n === 0 ) {
			return array(
				'key' => 'destinos', 'group' => 'integraciones', 'label' => 'Destinos',
				'status' => 'Sin configurar', 'level' => 'unknown',
				'desc' => 'No hay destinos (webhooks/adaptadores). Normal si la instalación no reenvía a n8n/hojas.',
			);
		}
		return array(
			'key' => 'destinos', 'group' => 'integraciones', 'label' => 'Destinos',
			'status' => $n . ' configurado(s)', 'level' => 'ok',
			'desc' => $n . ' destinos registrados para reenvío de eventos.',
		);
	}

	public static function check_telemetria( array $ev ): array
	{
		$n = (int) ( $ev['telemetry_errors'] ?? 0 );
		if ( $n > 0 ) {
			return array(
				'key' => 'telemetria', 'group' => 'sistema', 'label' => 'Errores técnicos recientes',
				'status' => $n . ' en 7d', 'level' => 'warn',
				'desc' => 'La telemetría registró ' . $n . ' evento(s) error/critical en los últimos ' . (int) ( $ev['window_days'] ?? self::WINDOW_DAYS ) . ' días. Revisa el bloque Datos → Eventos técnicos.',
			);
		}
		return array(
			'key' => 'telemetria', 'group' => 'sistema', 'label' => 'Errores técnicos recientes',
			'status' => 'Sin errores', 'level' => 'ok',
			'desc' => 'Sin eventos error/critical en telemetría en la ventana.',
		);
	}

	public static function check_cron( array $ev ): array
	{
		$age = $ev['snapshot_age_h'];
		if ( empty( $ev['cron_scheduled'] ) ) {
			return array(
				'key' => 'cron', 'group' => 'sistema', 'label' => 'Refresco diario',
				'status' => 'Sin cron', 'level' => 'bad',
				'desc' => 'El cron ' . self::CRON . ' no está programado; el snapshot no se refrescará solo. Revisa la activación del plugin.',
			);
		}
		if ( $age !== null && $age > 48 ) {
			return array(
				'key' => 'cron', 'group' => 'sistema', 'label' => 'Refresco diario',
				'status' => 'Snapshot viejo', 'level' => 'warn',
				'desc' => 'El último snapshot tiene ' . (int) $age . ' h. WP-cron corre con visitas: sin tráfico el refresco se hace bajo demanda (REST/CLI/admin).',
			);
		}
		return array(
			'key' => 'cron', 'group' => 'sistema', 'label' => 'Refresco diario',
			'status' => 'Programado', 'level' => 'ok',
			'desc' => 'Cron diario ' . self::CRON . ' programado; el snapshot se refresca a diario y bajo demanda.',
		);
	}

	// ── Recolección de evidencia (runtime, guards; nunca lanza) ────────────

	public static function collect(): array
	{
		$ev = self::default_evidence();

		// Tracker settings + tabla vivas (core).
		if ( class_exists( '\\Estandar\\Core\\SiteMap' ) ) {
			$settings = method_exists( '\\Estandar\\Core\\SiteMap', 'settings' ) ? \Estandar\Core\SiteMap::settings() : array();
			$ev['track_enabled'] = ! empty( $settings['track_enabled'] );
		}
		$since = self::since_local( self::WINDOW_DAYS );
		if ( self::table_exists( \Estandar\Core\SiteMap::sessions_table() ) ) {
			$ev['sessions_total']  = self::q_count( \Estandar\Core\SiteMap::sessions_table() );
			$ev['sessions_recent'] = self::q_count( \Estandar\Core\SiteMap::sessions_table(), 'first_seen', $since );
			$ev['sessions_last']   = self::q_max( \Estandar\Core\SiteMap::sessions_table(), 'first_seen' );
		}
		if ( self::table_exists( \Estandar\Core\SiteMap::table() ) ) {
			$ev['pageviews_total']  = self::q_count( \Estandar\Core\SiteMap::table() );
			$ev['pageviews_recent'] = self::q_count( \Estandar\Core\SiteMap::table(), 'viewed_at', $since );
			$ev['pageviews_last']   = self::q_max( \Estandar\Core\SiteMap::table(), 'viewed_at' );
		}

		// Relleno externo (insights, opcional): señal de tráfico real para la sospecha.
		$ev['external_recent'] = false;
		if ( class_exists( '\\Estandar\\Insights\\ExternalDaily' ) && method_exists( '\\Estandar\\Insights\\ExternalDaily', 'status' ) ) {
			try {
				$st = \Estandar\Insights\ExternalDaily::status();
				$ses = $st['sessions'] ?? array();
				$mx = (string) ( $ses['max'] ?? '' );
				$ev['external_max'] = $mx;
				if ( $mx !== '' && self::is_after( $mx, $since ) ) {
					$ev['external_recent'] = true;
				}
			} catch ( \Throwable $e ) {
				$ev['external_recent'] = false;
			}
		}

		// Capturas (estandar-capture).
		$ev['capture_active'] = self::plugin_active( 'estandar-capture/estandar-capture.php' );
		if ( $ev['capture_active'] ) {
			$subs = self::prefix() . 'estandar_capture_submissions';
			if ( self::table_exists( $subs ) ) {
				$ev['submissions_total']  = self::q_count( $subs, '', '', "spam_honeypot = 0 AND status = 'active'" );
				$ev['submissions_recent'] = self::q_count( $subs, 'created_at', $since, "spam_honeypot = 0 AND status = 'active'" );
				$ev['submissions_last']   = self::q_max( $subs, 'created_at', "spam_honeypot = 0 AND status = 'active'" );
			}
		}

		// Esquemas.
		$ev['schemas'] = self::schema_entries();
		foreach ( $ev['schemas'] as $k => $entry ) {
			$active = ! empty( $entry['always'] );
			if ( empty( $entry['always'] ) ) {
				$class  = (string) ( $entry['class'] ?? '' );
				$active = $class !== '' && class_exists( $class );
			}
			$option   = (string) ( $entry['option'] ?? '' );
			$actual   = $option !== '' ? (string) get_option( $option, '' ) : '';
			$expected = (string) ( $entry['expected'] ?? '' );
			if ( ! empty( $entry['class'] ) && class_exists( (string) $entry['class'] ) ) {
				$const_opt = (string) $entry['class'] . '::OPTION';
				$const_ver = (string) $entry['class'] . '::VERSION';
				if ( defined( $const_opt ) && defined( $const_ver ) ) {
					$option   = (string) constant( $const_opt );
					$expected = (string) constant( $const_ver );
					$actual   = $option !== '' ? (string) get_option( $option, '' ) : '';
				}
			}
			$ev['schemas'][ $k ] = array(
				'key' => $entry['key'], 'label' => $entry['label'],
				'active' => $active, 'actual' => $actual, 'expected' => $expected,
			);
		}

		// Telemetría: errores recientes.
		$tel = self::prefix() . 'estandar_core_telemetry';
		if ( self::table_exists( $tel ) ) {
			$ev['telemetry_errors'] = self::q_count( $tel, 'created_at', $since, "severity IN ('error','critical')" );
		}

		// Cron del propio diagnóstico + edad del snapshot guardado.
		if ( function_exists( 'wp_next_scheduled' ) ) {
			$next = wp_next_scheduled( self::CRON );
			$ev['cron_scheduled'] = (bool) $next;
			$ev['cron_next']      = $next ? (int) $next : 0;
		}
		$old = get_option( self::OPTION, array() );
		if ( is_array( $old ) && ! empty( $old['checked_at'] ) ) {
			$ev['snapshot_age_h'] = self::hours_since( (string) $old['checked_at'] );
		}

		// Plugins de la Suite instalados vs activos.
		foreach ( self::PRODUCT_SLUGS as $slug ) {
			$path = $slug . '/' . $slug . '.php';
			$ev['plugins'][ $slug ] = array(
				'installed' => self::plugin_installed( $path ),
				'active'    => self::plugin_active( $path ),
			);
		}

		return $ev;
	}

	/** PURE: compone el snapshot desde evidencia. */
	public static function snapshot_from_evidence( array $ev ): array
	{
		$checks = array();
		$checks[] = self::check_plugins( $ev );
		$checks[] = self::check_tracker( $ev );
		$checks[] = self::check_capturas( $ev );
		foreach ( self::schema_checks( $ev ) as $c ) {
			$checks[] = $c;
		}
		$checks[] = self::check_conexiones( $ev );
		$checks[] = self::check_destinos( $ev );
		$checks[] = self::check_telemetria( $ev );
		$checks[] = self::check_cron( $ev );

		$level = self::overall_from_checks( $checks );
		return array(
			'product'     => 'estandar-core',
			'check'       => 'autodiagnostico',
			'version'     => self::VERSION,
			'window_days' => max( 1, (int) ( $ev['window_days'] ?? self::WINDOW_DAYS ) ),
			'checked_at'  => self::now_mysql(),
			'overall'     => array( 'level' => $level, 'state' => self::state_label( $level ) ),
			'checks'      => $checks,
		);
	}

	/** Snapshot (persistido) del estado honesto. $force=true regenera. */
	public static function snapshot( bool $force = false ): array
	{
		if ( ! $force ) {
			$cached = get_option( self::OPTION, array() );
			if ( is_array( $cached ) && ! empty( $cached['checked_at'] ) && ! empty( $cached['overall'] ) && ! empty( $cached['checks'] ) ) {
				$age = self::hours_since( (string) $cached['checked_at'] );
				if ( $age < self::TTL_HOURS ) {
					return $cached;
				}
			}
		}
		$snap = self::snapshot_from_evidence( self::collect() );
		update_option( self::OPTION, $snap, false );
		return $snap;
	}

	// ── Cron / ciclo de vida ───────────────────────────────────────────────

	public static function init(): void
	{
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}
		add_action( self::CRON, array( __CLASS__, 'cron_refresh' ) );
		if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_event' ) ) {
			if ( ! wp_next_scheduled( self::CRON ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
			}
		}
	}

	public static function cron_refresh(): void
	{
		self::snapshot( true );
	}

	// ── Salida legible (CLI / copia) ──────────────────────────────────────

	public static function render_text( array $snap ): string
	{
		$lines   = array();
		$lines[] = sprintf( 'AUTODIAGNÓSTICO · %s · %s · ventana %dd · %s',
			(string) ( $snap['checked_at'] ?? '' ),
			(string) ( $snap['overall']['state'] ?? 'UNKNOWN' ),
			(int) ( $snap['window_days'] ?? self::WINDOW_DAYS ),
			(string) ( $snap['version'] ?? '' )
		);
		foreach ( (array) ( $snap['checks'] ?? array() ) as $c ) {
			$lines[] = sprintf( '[%s] %-34s %-24s %s', str_pad( (string) ( $c['level'] ?? 'unknown' ), 7 ), (string) ( $c['label'] ?? '' ), (string) ( $c['status'] ?? '' ), (string) ( $c['desc'] ?? '' ) );
		}
		return implode( "\n", $lines );
	}

	// ── Helpers de DB/WP (guards; standalone → vacío) ─────────────────────

	private static function prefix(): string
	{
		global $wpdb;
		return ( isset( $wpdb ) && ! empty( $wpdb->prefix ) ) ? $wpdb->prefix : 'wp_';
	}

	private static function table_exists( string $table ): bool
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $table === '' ) {
			return false;
		}
		$wpdb->get_results( 'SELECT 1 FROM ' . $table . ' LIMIT 1' );
		return empty( $wpdb->last_error );
	}

	private static function q_count( string $table, string $date_col = '', string $since = '', string $where_extra = '' ): int
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $table === '' ) {
			return 0;
		}
		$where = array();
		if ( $date_col !== '' && $since !== '' ) {
			$where[] = $wpdb->prepare( $date_col . ' >= %s', $since );
		}
		if ( $where_extra !== '' ) {
			$where[] = $where_extra;
		}
		$sql = 'SELECT COUNT(*) FROM ' . $table;
		if ( $where ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}
		return (int) $wpdb->get_var( $sql );
	}

	private static function q_max( string $table, string $col, string $where_extra = '' ): string
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $table === '' ) {
			return '';
		}
		$sql = 'SELECT MAX(' . $col . ') FROM ' . $table;
		if ( $where_extra !== '' ) {
			$sql .= ' WHERE ' . $where_extra;
		}
		$v = $wpdb->get_var( $sql );
		return (string) $v;
	}

	private static function since_local( int $days ): string
	{
		$now = function_exists( 'current_time' ) ? (int) current_time( 'timestamp' ) : time();
		return gmdate( 'Y-m-d H:i:s', $now - $days * 86400 );
	}

	private static function hours_since( string $datetime ): ?float
	{
		if ( $datetime === '' ) {
			return null;
		}
		$ts = strtotime( $datetime );
		if ( ! $ts ) {
			return null;
		}
		$now = function_exists( 'current_time' ) ? (int) current_time( 'timestamp' ) : time();
		return max( 0, ( $now - $ts ) / 3600 );
	}

	private static function is_after( string $date, string $since ): bool
	{
		return strtotime( $date ) !== false && strtotime( $since ) !== false && strtotime( $date ) >= strtotime( $since );
	}

	private static function plugin_active( string $path ): bool
	{
		if ( function_exists( 'is_plugin_active' ) ) {
			return (bool) is_plugin_active( $path );
		}
		return false;
	}

	private static function plugin_installed( string $path ): bool
	{
		return defined( 'WP_PLUGIN_DIR' ) && file_exists( rtrim( (string) WP_PLUGIN_DIR, '/' ) . '/' . $path );
	}

	private static function now_mysql(): string
	{
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}
}
