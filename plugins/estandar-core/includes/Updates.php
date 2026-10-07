<?php
/**
 * Estandar Suite v2 — Updates (canal staging)
 *
 * Self-hosted plugin updates via WordPress native Update URI (WP 5.8+).
 * Canal aislado: https://updates.estandar.cl/staging/v2/ — nunca lee el feed
 * legacy de producción (https://updates.estandar.cl/versions.json).
 *
 * AUTO-UPDATE (2026-09-07, sesión 025): además del mecanismo nativo de WP, el
 * plugin corre su propio cron (cada 6 h) que, con política `auto`, refresca el
 * manifiesto del canal y actualiza solo los plugins de la Suite que tengan
 * versión mayor. Así un release publicado en el canal llega solo a las
 * instalaciones en pocas horas, sin depender del ritmo de auto-update de WP.
 * Política off/pin bloquean (is_blocked). Candado anti-concurrencia.
 */

namespace Estandar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Updates {

	const BASE_URL  = 'https://updates.estandar.cl/staging/v2/';
	const HOSTNAME  = 'updates.estandar.cl';
	const CACHE_KEY = 'estandar_suite_updates_staging_versions';
	const CACHE_TTL = HOUR_IN_SECONDS;
	const LAST_CHECK_OPTION = 'estandar_suite_updates_staging_last_checked';
	const OPTION_POLICY = 'estandar_suite_updates_policy';
	const CRON_HOOK = 'estandar_suite_updates_daily_refresh';
	// Auto-update propio de la Suite (no depende del cron nativo de WP).
	const AUTO_HOOK = 'estandar_suite_updates_auto';
	const AUTO_SCHEDULE = 'estandar_suite_updates_auto_interval';
	const AUTO_INTERVAL = 6 * HOUR_IN_SECONDS; // cada 6 h
	const AUTO_LOCK_OPTION = 'estandar_suite_updates_auto_lock';
	const AUTO_LOCK_TTL = 15 * MINUTE_IN_SECONDS;
	// Siembra única del interruptor nativo de WP (auto_update_plugins): marca que
	// ya se añadieron los plugins de la Suite al toggle de WP para que el usuario
	// pueda apagarlos individualmente en Plugins → Actualizaciones automáticas.
	// v2: re-siembra una vez tras volver `slugs()` filtrable (incluye plugins
	// externos como `veloz` en el toggle nativo de auto-update de WP).
	const AUTO_SEED_OPTION = 'estandar_suite_updates_auto_seeded_v2';

	const SUITE_SLUGS = array( 'estandar-core', 'estandar-capture', 'estandar-brain', 'estandar-flow', 'estandar-insights', 'estandar-experience' );

	/**
	 * Slugs que participan del update self-hosted (manifest + Update URI +
	 * auto-update). Base = SUITE_SLUGS; plugins externos (p. ej. `veloz`,
	 * fuera de la suite) se suman vía el filtro `estandar_suite_update_slugs`
	 * sin acoplar el core a ellos. Configurable en producción, no hardcodeado.
	 */
	public static function slugs(): array {
		$slugs = apply_filters( 'estandar_suite_update_slugs', self::SUITE_SLUGS );
		$clean = array();
		foreach ( (array) $slugs as $s ) {
			$s = (string) $s;
			if ( '' !== $s && ! in_array( $s, $clean, true ) ) {
				$clean[] = $s;
			}
		}
		return $clean;
	}

	private static $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		add_filter( 'update_plugins_' . self::HOSTNAME, array( __CLASS__, 'check_update' ), 10, 4 );
		// Auto-update de la Suite SOLO cuando la política local es auto (off/pin
		// bloquean). La WP decide el ritmo (su cron de actualizaciones).
		add_filter( 'auto_update_plugin', array( __CLASS__, 'auto_update_plugin' ), 10, 2 );
		// Cron diario de refresh: evita que el manifiesto quede viejo (caso
		// rodrigovaldes.cl 2026-09-06) cuando nadie abre el panel. Se re-agenda
		// solo si falta → sobrevive a overwrites/reinstalaciones.
		add_action( self::CRON_HOOK, array( __CLASS__, 'refresh' ) );
		// Auto-update propio (sesión 025): cron cada 6 h que actualiza SOLO los
		// plugins de la Suite con versión mayor en el canal (política auto).
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( self::AUTO_HOOK, array( __CLASS__, 'auto_run' ) );
		self::ensure_cron();
		// La siembra del toggle nativo se difiere a `init` (después de que TODOS
		// los plugins registren sus filtros) para que `slugs()` ya incluya los
		// plugins externos (veloz) vía `estandar_suite_update_slugs`.
		add_action( 'init', array( __CLASS__, 'ensure_auto_seed' ), 20 );
	}

	/**
	 * Siembra el interruptor nativo de WP (auto_update_plugins) con los plugins
	 * instalados de la Suite cuando la política es auto. Así WP muestra
	 * "Actualizaciones automáticas activadas" con su interruptor real y el
	 * usuario puede apagarlo por plugin.
	 *
	 * AUTO_REPAIR_SEED: la firma (md5 del conjunto de `slugs()`) se guarda; si
	 * cambia (se sumó un plugin externo como veloz) se re-siembra una vez. Esto
	 * repara el orden "core actualizado antes que el plugin externo" y respeta
	 * los apagados manuales mientras el conjunto no cambie.
	 */
	public static function ensure_auto_seed(): void {
		if ( ! self::is_auto() ) {
			return;
		}
		if ( ! function_exists( 'get_site_option' ) || ! function_exists( 'update_site_option' ) ) {
			return;
		}
		$sig = md5( implode( ',', self::slugs() ) );
		if ( (string) get_option( self::AUTO_SEED_OPTION, '' ) === $sig ) {
			return;
		}
		$membership = array_values( array_filter( array_map( 'strval', (array) get_site_option( 'auto_update_plugins', array() ) ) ) );
		$changed    = false;
		foreach ( self::slugs() as $slug ) {
			$file = self::plugin_file( $slug );
			if ( ! file_exists( WP_PLUGIN_DIR . '/' . $file ) || in_array( $file, $membership, true ) ) {
				continue;
			}
			$membership[] = $file;
			$changed      = true;
		}
		if ( $changed ) {
			update_site_option( 'auto_update_plugins', $membership );
		}
		update_option( self::AUTO_SEED_OPTION, $sig, false );
	}

	/** Lista de plugins de la Suite habilitados en el interruptor de WP. */
	public static function auto_membership(): array {
		if ( ! function_exists( 'get_site_option' ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'strval', (array) get_site_option( 'auto_update_plugins', array() ) ) ) );
	}

	/**
	 * PURE-ish: ¿el plugin debe auto-actualizarse? Requiere política auto y que
	 * el interruptor de WP (auto_update_plugins) lo tenga habilitado. Antes de
	 * la siembra única (AUTO_SEED_OPTION) el default es auto (compatibilidad).
	 */
	public static function is_auto_updatable( string $slug ): bool {
		if ( ! self::is_auto() ) {
			return false;
		}
		$file = self::plugin_file( $slug );
		if ( '' === (string) get_option( self::AUTO_SEED_OPTION, '' ) ) {
			return true; // pre-siembra → auto por defecto.
		}
		return in_array( $file, self::auto_membership(), true );
	}

	/**
	 * Agenda el cron diario de refresh si no existe (idempotente; se re-crea
	 * tras reinstalaciones/deploys si llegó a perderse).
	 */
	public static function ensure_cron(): void {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
			return;
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, 'daily', self::CRON_HOOK );
		}
		// Auto-update propio: primera pasada ~10 min tras el arranque (para que
		// un sitio recién actualizado detecte releases nuevos casi de inmediato)
		// y luego cada 6 h.
		if ( ! wp_next_scheduled( self::AUTO_HOOK ) ) {
			wp_schedule_event( time() + 600, self::AUTO_SCHEDULE, self::AUTO_HOOK );
		}
	}

	/** Registra el intervalo de cron del auto-update propio. */
	public static function cron_schedules( array $schedules ): array {
		$schedules[ self::AUTO_SCHEDULE ] = array(
			'interval' => self::AUTO_INTERVAL,
			'display'  => 'Estandar Suite — auto-update (cada 6 h)',
		);
		return $schedules;
	}

	/** PURE: ¿la instalación está en política auto (permite auto-update)? */
	public static function is_auto(): bool {
		return self::policy()['mode'] === 'auto';
	}

	/** PURE: slugs con update pendiente en un manifiesto (plugin → fila). */
	public static function candidates( array $plugins_rows ): array {
		$out = array();
		foreach ( self::slugs() as $slug ) {
			$row = $plugins_rows[ $slug ] ?? array();
			if ( ! empty( $row['update'] ) ) {
				$out[] = $slug;
			}
		}
		return $out;
	}

	/**
	 * Auto-update propio (cron). Con política auto actualiza secuencialmente los
	 * plugins de la Suite con versión mayor en el canal. Candado anti-
	 * concurrencia (TTL); nunca actualiza con política off/pin.
	 */
	public static function auto_run(): void {
		if ( ! function_exists( 'wp_next_scheduled' ) || self::lock_active() || ! self::is_auto() ) {
			return;
		}
		self::acquire_lock();
		try {
			$manifest = self::refresh();
			if ( ! function_exists( 'is_plugin_active' ) && defined( 'ABSPATH' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			foreach ( self::candidates( $manifest['plugins'] ?? array() ) as $slug ) {
				if ( ! self::is_auto() ) {
					break; // política cambió a off/pin en mitad de la pasada.
				}
				if ( ! self::is_auto_updatable( $slug ) ) {
					continue; // usuario apagó el auto-update de este plugin (WP).
				}
				// UPDATE_PRESERVE_STATE_V1: actualiza y reactiva si estaba activo
				// (el upgrader de WP lo desactiva y en cron no lo reactiva solo).
				$res = self::update_plugin_preserving_state( $slug );
				if ( empty( $res['ok'] ) && function_exists( 'error_log' ) ) {
					error_log( '[estandar-suite updates] auto-update ' . $slug . ' falló: ' . (string) ( $res['message'] ?? 'desconocido' ) );
				}
				sleep( 3 );
			}
		} catch ( \Throwable $e ) {
			if ( function_exists( 'error_log' ) ) {
				error_log( '[estandar-suite updates] auto_run error: ' . $e->getMessage() );
			}
		} finally {
			self::release_lock();
		}
	}

	private static function lock_active(): bool {
		$t = (int) get_option( self::AUTO_LOCK_OPTION, 0 );
		return $t > 0 && ( time() - $t ) < self::AUTO_LOCK_TTL;
	}

	private static function acquire_lock(): void {
		update_option( self::AUTO_LOCK_OPTION, time(), false );
	}

	private static function release_lock(): void {
		delete_option( self::AUTO_LOCK_OPTION );
	}

	/**
	 * Permite que WP actualice automáticamente la Suite en background SOLO si la
	 * política de esta instalación es `auto` (no off ni pin) Y el plugin está
	 * habilitado en el interruptor nativo de WP (Plugins → Actualizaciones
	 * automáticas). Así el usuario puede apagar el automático por plugin.
	 * Devuelve false en cualquier otro caso para no tocar decisiones de WP sobre
	 * otros plugins.
	 */
	public static function auto_update_plugin( $update, $item ) {
		if ( ! is_object( $item ) || empty( $item->plugin ) ) {
			return $update;
		}
		$slug = dirname( (string) $item->plugin );
		if ( ! in_array( $slug, self::slugs(), true ) ) {
			return $update;
		}
		$remote = (string) ( $item->new_version ?? '' );
		if ( self::is_blocked( $remote ) ) {
			return false;
		}
		return self::is_auto_updatable( $slug ) ? true : $update;
	}

	/**
	 * WP 5.8+ per-plugin filter: fired when the plugin Update URI hostname matches.
	 * Only answers for Suite v2 slugs so a mixed install is never cross-fed.
	 *
	 * @return array|false Update data or false (no update).
	 */
	public static function check_update( $update, array $plugin_data, string $plugin_file, array $locales ) {
		$slug = dirname( $plugin_file );
		if ( ! in_array( $slug, self::slugs(), true ) ) {
			return $update;
		}

		$versions = self::fetch_versions();
		if ( ! isset( $versions[ $slug ] ) ) {
			return $update;
		}

		$remote_version = $versions[ $slug ];
		$local_version  = $plugin_data['Version'] ?? '0';

		if ( ! version_compare( $remote_version, $local_version, '>' ) ) {
			return $update;
		}
		if ( self::is_blocked( $remote_version ) ) {
			return $update;
		}

		return array(
			'slug'    => $slug,
			'version' => $remote_version,
			'package' => self::BASE_URL . $slug . '.zip',
		);
	}

	// ── Política de actualización por instalación ──────────────────────────

	/**
	 * Política de updates de esta instalación:
	 *  - auto          → ofrece updates normalmente (default).
	 *  - off           → esta instalación NO ofrece updates de la Suite.
	 *  - pin:X.Y.Z     → se congela en X.Y.Z (no ofrece versiones superiores).
	 * Sirve para controlar qué sitios se actualizan y cuáles no (rollout), sin
	 * tocar el canal. Opción: estandar_suite_updates_policy.
	 */
	public static function policy(): array
	{
		$raw = (string) get_option( self::OPTION_POLICY, 'auto' );
		if ( $raw === 'off' ) {
			return array( 'mode' => 'off', 'pin' => '' );
		}
		if ( strpos( $raw, 'pin:' ) === 0 ) {
			$pin = trim( substr( $raw, 4 ) );
			if ( $pin !== '' ) {
				return array( 'mode' => 'pin', 'pin' => $pin );
			}
		}
		return array( 'mode' => 'auto', 'pin' => '' );
	}

	/** PURE: valida una política cruda y devuelve la forma canónica o ''. */
	public static function normalize_policy( string $raw ): string
	{
		$raw = trim( strtolower( $raw ) );
		if ( $raw === 'auto' || $raw === 'off' ) {
			return $raw;
		}
		if ( strpos( $raw, 'pin:' ) === 0 ) {
			$pin = trim( substr( $raw, 4 ) );
			if ( preg_match( '/^\d+\.\d+(\.\d+)?([a-z0-9.\-]+)?$/', $pin ) ) {
				return 'pin:' . $pin;
			}
		}
		return '';
	}

	/** Guarda la política. Devuelve false si el valor es inválido. */
	public static function set_policy( string $raw ): bool
	{
		$norm = self::normalize_policy( $raw );
		if ( $norm === '' ) {
			return false;
		}
		update_option( self::OPTION_POLICY, $norm, false );
		if ( function_exists( 'delete_site_transient' ) ) {
			delete_site_transient( 'update_plugins' );
		}
		return true;
	}

	/** PURE: ¿la versión remota está bloqueada por la política local? */
	public static function is_blocked( string $remote_version ): bool
	{
		$p = self::policy();
		if ( $p['mode'] === 'off' ) {
			return true;
		}
		if ( $p['mode'] === 'pin' && $p['pin'] !== '' && $remote_version !== ''
			&& version_compare( $remote_version, $p['pin'], '>' ) ) {
			return true;
		}
		return false;
	}

	private static function fetch_versions(): array {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get( self::BASE_URL . 'versions.json', array( 'timeout' => 10 ) );
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return array();
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return array();
		}

		set_transient( self::CACHE_KEY, $data, self::CACHE_TTL );
		update_option( self::LAST_CHECK_OPTION, time(), false );
		return $data;
	}

	// ── Panel de Actualizaciones (equivalente a update-core.php) ──────────

	/** Ruta del archivo principal del plugin dentro de wp-content/plugins/. */
	public static function plugin_file( string $slug ): string {
		return $slug . '/' . $slug . '.php';
	}

	/** Timestamp de la última comprobación exitosa contra updates.estandar.cl (0 = nunca). */
	public static function last_checked(): int {
		return (int) get_option( self::LAST_CHECK_OPTION, 0 );
	}

	/** Fuerza una comprobación nueva (descarta la caché) y devuelve el manifiesto. */
	public static function refresh(): array {
		delete_transient( self::CACHE_KEY );
		self::fetch_versions();
		return self::manifest();
	}

	/**
	 * Manifiesto local vs remoto de la suite: versiones, actualización pendiente
	 * y paquete. Usado por el panel de Actualizaciones.
	 *
	 * @return array{ last_checked:int, plugins:array<string,array> }
	 */
	public static function manifest(): array {
		$versions = self::fetch_versions();
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = function_exists( 'get_plugins' ) ? get_plugins() : array();

		$rows = array();
		foreach ( self::slugs() as $slug ) {
			$file  = self::plugin_file( $slug );
			$local = '';
			foreach ( $plugins as $f => $d ) {
				if ( $f === $file ) {
					$local = (string) ( $d['Version'] ?? '' );
					break;
				}
			}
			$remote = (string) ( $versions[ $slug ] ?? '' );
			$newer  = $remote !== '' && $local !== '' && version_compare( $remote, $local, '>' );
			$blocked = $newer && self::is_blocked( $remote );
			$rows[ $slug ] = array(
				'slug'    => $slug,
				'file'    => $file,
				'local'   => $local,
				'remote'  => $remote,
				'update'  => $newer && ! $blocked,
				'blocked' => $blocked,
				'package' => $remote !== '' ? self::BASE_URL . $slug . '.zip' : '',
			);
		}
		return array( 'last_checked' => self::last_checked(), 'plugins' => $rows, 'policy' => self::policy() );
	}

	/**
	 * Inyecta las actualizaciones de la suite en el transient de WP (response)
	 * sin depender de que api.wordpress.org responda. Así upgrade_plugin() puede
	 * leer el paquete desde updates.estandar.cl de forma determinista.
	 */
	public static function ensure_update_transient(): void {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$updates = get_site_transient( 'update_plugins' );
		if ( ! is_object( $updates ) ) {
			$updates = new \stdClass();
		}
		if ( ! isset( $updates->response ) || ! is_array( $updates->response ) ) {
			$updates->response = array();
		}
		foreach ( self::slugs() as $slug ) {
			$file = self::plugin_file( $slug );
			if ( ! file_exists( WP_PLUGIN_DIR . '/' . $file ) ) {
				continue;
			}
			$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $file );
			$update      = self::check_update( false, $plugin_data, $file, array() );
			if ( is_array( $update ) && ! empty( $update ) ) {
				$u = (object) $update;
				$u->id          = (string) ( $plugin_data['UpdateURI'] ?? '' );
				$u->plugin      = $file;
				$u->new_version = $u->version;
				$updates->response[ $file ] = $u;
			}
		}
		set_site_transient( 'update_plugins', $updates );
	}

	/**
	 * Actualiza un plugin de la suite desde updates.estandar.cl vía el upgrader
	 * nativo (API de CLASES `Plugin_Upgrader`, no la función procedural
	 * `upgrade_plugin()` que WordPress 7.1 eliminó → era un fatal en producción).
	 */
	/**
	 * UPDATE_PRESERVE_STATE_V1: actualiza un plugin y, si estaba ACTIVO, lo
	 * reactiva. El upgrader de WP desactiva el plugin y en contexto headless
	 * (admin-post/cron) no lo reactiva solo → sin esto, actualizar desde el admin
	 * dejaba core/capture inactivos (bug reincidente en akcontadores).
	 * Devuelve ['ok','message','reactivated'].
	 */
	public static function update_plugin_preserving_state( string $slug ): array {
		if ( ! function_exists( 'is_plugin_active' ) && defined( 'ABSPATH' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$file       = self::plugin_file( $slug );
		$was_active = function_exists( 'is_plugin_active' ) && is_plugin_active( $file );

		$r = self::update_plugin( $slug );
		if ( empty( $r['ok'] ) ) {
			return array( 'ok' => false, 'message' => (string) ( $r['message'] ?? 'Error' ), 'reactivated' => false );
		}

		$reactivated = false;
		if ( $was_active && function_exists( 'activate_plugin' ) ) {
			$act = activate_plugin( $file, '', false, true );
			if ( is_wp_error( $act ) ) {
				return array(
					'ok'          => true,
					'message'     => (string) ( $r['message'] ?? '' ) . ' (no se pudo reactivar: ' . $act->get_error_message() . ')',
					'reactivated' => false,
				);
			}
			$reactivated = true;
		}

		return array( 'ok' => true, 'message' => (string) ( $r['message'] ?? '' ), 'reactivated' => $reactivated );
	}

	public static function update_plugin( string $slug ): array {
		if ( ! in_array( $slug, self::slugs(), true ) ) {
			return array( 'ok' => false, 'message' => 'Plugin no soportado.' );
		}
		$file = self::plugin_file( $slug );
		if ( ! file_exists( WP_PLUGIN_DIR . '/' . $file ) ) {
			return array( 'ok' => false, 'message' => 'Plugin no instalado.' );
		}
		$plugin = self::manifest()['plugins'][ $slug ];
		if ( ! $plugin['update'] ) {
			return array( 'ok' => false, 'message' => 'No hay actualización disponible (local ' . $plugin['local'] . ' ≥ remoto ' . ( $plugin['remote'] !== '' ? $plugin['remote'] : 'desconocido' ) . ').' );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader-skin.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';

		// WP_UPGRADER_API_V1: guard por si WP eliminara la clase o el plugin no
		// estuviera en el transient de actualizaciones → respuesta controlada,
		// jamás un fatal.
		self::ensure_update_transient();
		if ( ! class_exists( 'Plugin_Upgrader' ) || ! class_exists( 'Plugin_Upgrader_Skin' ) ) {
			return array( 'ok' => false, 'message' => 'El upgrader de WordPress no está disponible.' );
		}

		// Plugin_Upgrader::upgrade() vuelve a true/array en éxito y WP_Error en
		// fallo (el método NO depende de la función procedural eliminada).
		$upgrader = new \Plugin_Upgrader( new \Plugin_Upgrader_Skin( array( 'plugin' => $file ) ) );
		$result   = $upgrader->upgrade( $file );
		if ( is_wp_error( $result ) ) {
			return array( 'ok' => false, 'message' => $result->get_error_message() );
		}
		$remote = (string) ( self::manifest()['plugins'][ $slug ]['remote'] ?? '' );
		return array( 'ok' => true, 'message' => 'Actualizado a ' . ( $remote !== '' ? $remote : 'la última versión' ) );
	}
}
