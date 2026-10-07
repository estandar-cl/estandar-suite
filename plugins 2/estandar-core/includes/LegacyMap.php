<?php

namespace Estandar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * LegacyMap — migration bridge for the ORIGINAL (v1) plugins.
 *
 * Goal: the v2 suite must replace the legacy plugins with ZERO friction:
 *  - no data loss (tables, options, tokens, CPT entries);
 *  - no broken shortcodes after legacy deactivation;
 *  - no broken REST endpoints;
 *  - settings/tokens/APIs keep working while legacy is active AND after it is
 *    deactivated.
 *
 * This module NEVER modifies legacy files. Compatibility strategy:
 *  - While a legacy plugin is ACTIVE, it owns its shortcodes (no collision).
 *  - When a legacy plugin is DEACTIVATED, its main file still exists on disk.
 *    The bridge include_once's it, so the legacy code re-registers its own
 *    shortcodes (functions AND closures) exactly as before — no reimplementation,
 *    no duplication, no modification of the legacy source.
 */

class LegacyMap
{
	/** Main files of the legacy plugins whose shortcodes must survive deactivation. */
	const LEGACY_PLUGINS = array(
		'estandar-chatbot/estandar-chatbot.php',
		'estandar-cpt-manager/estandar-cpt-manager.php',
		'estandar-secure-share/estandar-secure-share.php',
		'estandar-firmas/estandar-signature-generator.php',
		'estandar-forms/estandar-forms.php',
		'estandar-kickoff/estandar-kickoff.php',
	);

	/** Legacy plugins that expose REST endpoints consumed by external systems. */
	const LEGACY_REST_PLUGINS = array(
		'estandar-leads/estandar-leads.php',
		'estandar-analytics/estandar-analytics.php',
		'estandar-pop/estandar-pop.php',
		'estandar-experience-blocks/estandar-experience-blocks.php',
	);

	/**
	 * Extra legacy files (beyond the main plugin file) that register shortcodes.
	 * Needed because some legacy plugins register shortcodes from modules that
	 * are not loaded by the main file (e.g. kickoff modules).
	 */
	const LEGACY_EXTRA_FILES = array(
		'estandar-kickoff/modules/login-personalizer/class-login-personalizer.php',
	);

	/** Shortcodes owned by each legacy plugin (for the coverage report). */
	const LEGACY_SHORTCODES = array(
		'estandar_chatbot'            => 'estandar-chatbot/estandar-chatbot.php',
		'estandar_gallery'            => 'estandar-cpt-manager/estandar-cpt-manager.php',
		'estandar_product_categories' => 'estandar-cpt-manager/estandar-cpt-manager.php',
		'estandar_secure_reveal'      => 'estandar-secure-share/estandar-secure-share.php',
		'estandar_signature_generator' => 'estandar-firmas/estandar-signature-generator.php',
		'estandar_form'               => 'estandar-forms/estandar-forms.php',
		'estandar_login'              => 'estandar-kickoff/estandar-kickoff.php',
	);

	/**
	 * Register the compatibility layer. Called on `init` (priority 20, after
	 * legacy plugins register theirs on their own `init`, so no collision).
	 */
	public static function init(): void
	{
		if ( ! function_exists( 'is_plugin_active' ) ) {
			return;
		}
		foreach ( self::LEGACY_PLUGINS as $main ) {
			if ( is_plugin_active( $main ) ) {
				continue; // legacy plugin is active and owns its shortcodes.
			}
			$file = WP_PLUGIN_DIR . '/' . $main;
			if ( is_readable( $file ) ) {
				// Re-load the legacy main file so its add_shortcode() calls run.
				// The plugin is inactive, so no activation hooks fire. This is a
				// pure read of legacy code — no modification, no reimplementation.
				include_once $file;
			}
		}
		foreach ( self::LEGACY_REST_PLUGINS as $main ) {
			if ( is_plugin_active( $main ) ) {
				continue;
			}
			$file = WP_PLUGIN_DIR . '/' . $main;
			if ( ! is_readable( $file ) ) {
				continue;
			}
			// Legacy main files register their bootstraps on `plugins_loaded`.
			// That hook already fired, so capture the current set of callbacks,
			// include the file, then run only the newly added callbacks.
			$known = self::snapshot_hook_callbacks( 'plugins_loaded' );
			include_once $file;
			self::run_new_hook_callbacks( 'plugins_loaded', $known );
		}
		foreach ( self::LEGACY_EXTRA_FILES as $extra ) {
			$file = WP_PLUGIN_DIR . '/' . $extra;
			if ( is_readable( $file ) ) {
				include_once $file;
			}
		}
		// Singleton modules that register shortcodes in their constructor only
		// register when get_instance() is called. Bootstrap them explicitly.
		if ( ! is_plugin_active( 'estandar-kickoff/estandar-kickoff.php' ) && class_exists( '\Estandar_KickOff_Login_Personalizer' ) ) {
			\Estandar_KickOff_Login_Personalizer::get_instance();
		}
		// The re-included legacy main files may register their own admin menus.
		// The v2 suite owns the sidebar: remove every legacy `estandar-*` menu
		// (its pages stay reachable by URL; the UI lives only in the suite tabs).
		add_action( 'admin_menu', array( __CLASS__, 'strip_legacy_menus' ), 999 );
	}

	/**
	 * Remove legacy admin menus re-surfaced by the compat bridge.
	 * Keeps the v2 suite pages (`estandar-suite`, `estandar-suite-*`) intact.
	 *
	 * Las páginas legacy se quitan del sidebar pero NUNCA de `$_registered_pages`:
	 * si se las des-registra, `user_can_access_admin_page()` responde 403
	 * ("Lo siento, no tienes permisos para acceder a esta página") al abrir la
	 * URL directa, porque tras el strip el parent no se resuelve y WP calcula
	 * `admin_page_<slug>` mientras la página quedó registrada bajo el hook del
	 * parent legacy. Para conservar el acceso por URL (y su callback), cada
	 * página legacy eliminada se re-homeless bajo `admin.php`.
	 */
	public static function strip_legacy_menus(): void
	{
		global $menu, $submenu;
		if ( ! is_array( $menu ) ) {
			return;
		}

		$rehome = array(); // slug => array( 'hook' => hook original, 'cap' => capability )

		foreach ( (array) $menu as $item ) {
			$slug = isset( $item[2] ) ? (string) $item[2] : '';
			if ( self::is_legacy_slug( $slug ) ) {
				$rehome[ $slug ] = array(
					'hook' => self::find_legacy_hook( $slug ),
					'cap'  => isset( $item[1] ) ? (string) $item[1] : 'read',
				);
				remove_menu_page( $slug );
			}
		}

		// Drop legacy submenu entries that surfaced under non-legacy parents.
		if ( is_array( $submenu ) ) {
			foreach ( (array) $submenu as $parent => $items ) {
				if ( strpos( (string) $parent, 'estandar-suite' ) === 0 ) {
					continue; // suite submenus are ours.
				}
				foreach ( (array) $items as $k => $sub ) {
					$slug = isset( $sub[2] ) ? (string) $sub[2] : '';
					if ( self::is_legacy_slug( $slug ) ) {
						$rehome[ $slug ] = array(
							'hook' => self::find_legacy_hook( $slug ),
							'cap'  => isset( $sub[1] ) ? (string) $sub[1] : 'read',
						);
						unset( $submenu[ $parent ][ $k ] );
					}
				}
			}
		}

		// Re-register stripped legacy pages under admin.php so their URL stays
		// reachable (sin 403) y el callback original sigue renderizando.
		foreach ( $rehome as $slug => $info ) {
			self::rehome_legacy_page( $slug, (string) $info['hook'], (string) $info['cap'] );
		}
	}

	/**
	 * Original hookname of a registered page, e.g. `estandar_page_estandar-forms`.
	 * Buscado en `$_registered_pages` (toplevel_ / *_page_ ) porque el parent real
	 * puede no coincidir con el slug del menú (shell legacy re-mapea parents).
	 */
	private static function find_legacy_hook( string $slug ): string
	{
		if ( ! is_array( $GLOBALS['_registered_pages'] ?? null ) ) {
			return '';
		}
		$suffix = '_page_' . $slug;
		foreach ( array_keys( $GLOBALS['_registered_pages'] ) as $key ) {
			if ( $key === 'toplevel_page_' . $slug || substr( $key, -strlen( $suffix ) ) === $suffix ) {
				return (string) $key;
			}
		}
		return '';
	}

	/**
	 * Re-home a stripped legacy page under `admin.php`:
	 *  - `$_registered_pages['admin_page_<slug>']` queda seteado (acceso por URL);
	 *  - el callback original (registrado en el hook legacy) se re-adjunta, para
	 *    que la página renderice igual que antes del strip.
	 */
	private static function rehome_legacy_page( string $slug, string $old_hook, string $capability ): void
	{
		if ( $old_hook === '' || $old_hook === 'admin_page_' . $slug ) {
			return; // ya alcanzable bajo admin.php; el strip no la rompe.
		}
		if ( empty( $GLOBALS['wp_filter'][ $old_hook ] ) ) {
			$GLOBALS['_registered_pages'][ 'admin_page_' . $slug ] = true;
			return;
		}
		$cap = ( $capability !== '' ) ? $capability : 'read';
		add_submenu_page( null, '', '', $cap, $slug, function () use ( $old_hook ) {
			do_action( $old_hook );
		} );
	}

	/** A slug belongs to a legacy (v1) estandar menu? Keeps v2 suite pages. */
	private static function is_legacy_slug( string $slug ): bool
	{
		if ( $slug === '' || strpos( $slug, 'estandar-suite' ) === 0 ) {
			return false;
		}
		$slug = strtolower( $slug );
		return strpos( $slug, 'estandar-' ) === 0 || strpos( $slug, 'estandar_' ) === 0;
	}

	/** Snapshot the serialized callbacks currently registered on a hook. */
	private static function snapshot_hook_callbacks( string $hook ): array
	{
		$wf = $GLOBALS['wp_filter'][ $hook ] ?? null;
		if ( ! $wf instanceof \WP_Hook ) {
			return array();
		}
		$known = array();
		foreach ( $wf->callbacks as $cb_list ) {
			foreach ( $cb_list as $c ) {
				$known[] = self::serialize_callback( $c['function'] ?? '' );
			}
		}
		return $known;
	}

	/** Run callbacks newly added to a hook since a snapshot (once, guarded). */
	private static function run_new_hook_callbacks( string $hook, array $known ): void
	{
		$wf = $GLOBALS['wp_filter'][ $hook ] ?? null;
		if ( ! $wf instanceof \WP_Hook ) {
			return;
		}
		foreach ( $wf->callbacks as $cb_list ) {
			foreach ( $cb_list as $c ) {
				$fn = $c['function'] ?? '';
				if ( ! in_array( self::serialize_callback( $fn ), $known, true ) && is_callable( $fn ) ) {
					call_user_func( $fn );
				}
			}
		}
	}

	/** Normalize a callback into a comparable string (closures → spl hash). */
	private static function serialize_callback( $cb ): string
	{
		if ( is_array( $cb ) ) {
			return ( is_object( $cb[0] ) ? spl_object_hash( $cb[0] ) : (string) $cb[0] ) . '::' . (string) $cb[1];
		}
		if ( $cb instanceof \Closure ) {
			return 'closure:' . spl_object_hash( $cb );
		}
		return (string) $cb;
	}

	/**
	 * Read a legacy option (user settings / token / API config) without
	 * touching it. Returns the raw value or $default.
	 */
	public static function legacy_option( string $name, $default = null )
	{
		if ( function_exists( 'get_option' ) ) {
			$v = get_option( $name, null );
			if ( $v !== null && $v !== false ) {
				return $v;
			}
		}
		return $default;
	}

	/** True when any legacy estandar table is present (production-like install). */
	public static function has_legacy_install(): bool
	{
		return AdoptionProbe::probe_legacy();
	}

	/**
	 * Verify migration coverage (read-only). For each legacy plugin reports:
	 *  - is it active now;
	 *  - will its main file survive (exists on disk) for the compat bridge;
	 *  - for each legacy shortcode, is it covered (owner active OR file present).
	 */
	public static function verify_coverage(): array
	{
		$report = array(
			'legacy_install'    => self::has_legacy_install(),
			'legacy_plugins'    => array(),
			'shortcodes'        => array(),
			'orphan_shortcodes' => array(),
		);
		$plugins = function_exists( 'get_option' ) ? (array) get_option( 'active_plugins', array() ) : array();
		foreach ( $plugins as $p ) {
			if ( stripos( $p, 'estandar-' ) !== 0 ) {
				continue;
			}
			if ( in_array( $p, array( 'estandar-core/estandar-core.php', 'estandar-capture/estandar-capture.php', 'estandar-brain/estandar-brain.php', 'estandar-flow/estandar-flow.php', 'estandar-insights/estandar-insights.php' ), true ) ) {
				continue;
			}
			$report['legacy_plugins'][] = $p;
		}
		foreach ( self::LEGACY_SHORTCODES as $tag => $main ) {
			$owner_active = is_plugin_active( $main );
			$file_ok      = is_readable( WP_PLUGIN_DIR . '/' . $main );
			$covered      = $owner_active || $file_ok;
			$report['shortcodes'][ $tag ] = $covered;
			if ( ! $covered ) {
				$report['orphan_shortcodes'][] = $tag;
			}
		}
		return $report;
	}
}
