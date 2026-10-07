<?php

namespace Estandar\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin UI — top-level "Estandar Suite" menu + Core page (health, destinations,
 * secrets, adoption). Product pages (Capture/Brain/Flow/Insights) register as
 * submenus via the `estandar_suite_admin_register` hook (Core owns the menu).
 */
class Admin
{
	public static function init(): void
	{
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 9 );
		add_action( 'admin_post_estandar_suite_add_destination', array( __CLASS__, 'handle_add_destination' ) );
		add_action( 'admin_post_estandar_suite_delete_destination', array( __CLASS__, 'handle_delete_destination' ) );
		add_action( 'admin_post_estandar_suite_test_destination', array( __CLASS__, 'handle_test_destination' ) );
		add_action( 'admin_post_estandar_suite_save_event_map', array( __CLASS__, 'handle_save_event_map' ) );
		add_action( 'admin_post_estandar_suite_create_secret', array( __CLASS__, 'handle_create_secret' ) );
		add_action( 'admin_post_estandar_suite_save_telemetry_settings', array( __CLASS__, 'handle_save_telemetry_settings' ) );
		add_action( 'admin_post_estandar_suite_save_menu_icon', array( __CLASS__, 'handle_save_menu_icon' ) );
		add_action( 'admin_post_estandar_suite_save_visibility', array( __CLASS__, 'handle_save_visibility' ) );
		add_action( 'admin_post_estandar_suite_core_save_feature', array( __CLASS__, 'handle_save_feature' ) );
	}

	/** Per-functionality settings descriptors (key => field config). */
	public static function feature_fields(): array
	{
		return array(
			'secure' => array(
				'enabled'   => array( 'type' => 'bool', 'label' => 'Puente Secure Share habilitado', 'desc' => 'Exponer las entregas seguras legadas en la suite.', 'default' => 1 ),
				'ttl_hours' => array( 'type' => 'text', 'label' => 'Caducidad por defecto (horas)', 'desc' => 'Horas de validez de una entrega si no se consume.', 'default' => '24' ),
			),
		);
	}

	/** admin_post handler: persist feature settings (whitelisted fields). */
	public static function handle_save_feature(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_core_save_feature' );
		$feature = sanitize_key( (string) ( $_POST['feature'] ?? '' ) );
		$fields  = self::feature_fields();
		if ( ! isset( $fields[ $feature ] ) ) {
			wp_die( 'Feature desconocido.' );
		}
		$raw = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? $_POST['settings'] : array();
		update_option( 'estandar_suite_core_' . $feature . '_settings', self::sanitize_feature_fields( $fields[ $feature ], $raw ) );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite&tab=config&sub=' . $feature . '&feature_saved=1' ) );
		exit;
	}

	public static function menu(): void
	{
		add_menu_page(
			'Estandar Suite',
			'Estandar Suite',
			'manage_options',
			'estandar-suite',
			array( __CLASS__, 'render' ),
			self::menu_icon(),
			3
		);

		// Rename the implicit first item to "Core" (it is the Core product page).
		add_submenu_page(
			'estandar-suite',
			'Core',
			'Core',
			'manage_options',
			'estandar-suite',
			array( __CLASS__, 'render' )
		);

		// Submenus registered by the products (filter for extensibility).
		do_action( 'estandar_suite_admin_register' );

		// Quick access in the top admin bar (frontend + backend).
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 40 );
	}

	/** The configured emoji (single source of truth for sidebar + top bar). */
	public static function menu_emoji(): string
	{
		$emoji = (string) get_option( 'estandar_suite_core_menu_icon', '🐲' );
		if ( $emoji === '' ) {
			$emoji = '🐲';
		}
		return $emoji;
	}

	/** Configurable emoji icon (option `estandar_suite_core_menu_icon`). */
	public static function menu_icon(): string
	{
		$emoji = self::menu_emoji();
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><text y="15" font-size="15">' . $emoji . '</text></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	/** Top admin bar: "Estandar Suite" quick access with per-product links. */
	public static function admin_bar( \WP_Admin_Bar $bar ): void
	{
		if ( ! is_admin_bar_showing() ) {
			return;
		}
		$emoji = self::menu_emoji();
		$bar->add_node( array(
			'id'     => 'estandar-suite',
			'title'  => '<span style="font-size:14px;">' . esc_html( $emoji ) . '</span> <span class="ab-label">Estandar Suite</span>',
			'href'   => admin_url( 'admin.php?page=estandar-suite' ),
			'meta'   => array( 'title' => 'Estandar Suite' ),
		) );
		$links = array(
			'core'     => array( 'title' => 'Core', 'page' => 'estandar-suite' ),
			'capture'  => array( 'title' => 'Capture', 'page' => 'estandar-suite-capture' ),
			'brain'    => array( 'title' => 'Brain', 'page' => 'estandar-suite-brain' ),
			'flow'     => array( 'title' => 'Flow', 'page' => 'estandar-suite-flow' ),
			'insights' => array( 'title' => 'Insights', 'page' => 'estandar-suite-insights' ),
		);
		foreach ( $links as $id => $l ) {
			$bar->add_node( array(
				'id'     => 'estandar-suite-' . $id,
				'parent' => 'estandar-suite',
				'title'  => $l['title'],
				'href'   => admin_url( 'admin.php?page=' . $l['page'] ),
			) );
		}
	}

	/**
	 * Render the shared 4-tab navigation for an Estandar Suite page.
	 * The 4 visible tabs group the 7 internal functional sets:
	 * Estado (esquema+eventos) · Configuración (dominio+UI) · Datos (adopción) · Avanzado (REST+CLI).
	 *
	 * @param string $page   Menu/slug of the current page (admin.php?page=...).
	 * @param string $active Current tab key.
	 * @param array  $tabs   Map of tab key => label.
	 */
	public static function render_tabs( string $page, string $active, array $tabs ): void
	{
		if ( ! $tabs ) {
			return;
		}
		echo '<nav class="nav-tab-wrapper wp-clearfix" aria-label="Secciones de ' . esc_attr( $page ) . '">';
		foreach ( $tabs as $key => $label ) {
			$url = admin_url( 'admin.php?page=' . $page . '&tab=' . $key );
			echo '<a href="' . esc_url( $url ) . '" class="nav-tab' . ( $key === $active ? ' nav-tab-active' : '' ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
	}

	/** Normalize a requested tab against a whitelist. If the requested tab (or
	 *  the default) is not present in the list — e.g. hidden by visibility —
	 *  fall back to the first available tab. */
	public static function resolve_tab( array $tabs, string $default = 'estado' ): string
	{
		$tab = sanitize_key( (string) ( $_GET['tab'] ?? $default ) );
		if ( array_key_exists( $tab, $tabs ) ) {
			return $tab;
		}
		$keys = array_keys( $tabs );
		return $keys ? $keys[0] : $default;
	}

	/**
	 * Render a second-level sub-tab navigation inside a tab.
	 * Each sub-tab is one functional group within the tab.
	 *
	 * @param string $page    Menu/slug of the current page.
	 * @param string $tab     Active top-level tab key.
	 * @param string $active  Active sub-tab key.
	 * @param array  $subs    Map of sub-tab key => label.
	 */
	public static function render_subtabs( string $page, string $tab, string $active, array $subs ): void
	{
		if ( count( $subs ) < 2 ) {
			return; // single group: no need for sub-tabs.
		}
		echo '<nav class="nav-tab-wrapper wp-clearfix" style="background:#fff;border-bottom:none;margin-top:10px;padding:0 0 0 4px;" aria-label="Grupos de ' . esc_attr( $tab ) . '">';
		foreach ( $subs as $key => $label ) {
			$url = admin_url( 'admin.php?page=' . $page . '&tab=' . $tab . '&sub=' . $key );
			$cls = $key === $active ? ' nav-tab-active' : '';
			echo '<a href="' . esc_url( $url ) . '" class="nav-tab' . $cls . '" style="font-size:13px;padding:6px 12px;">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
	}

	/** Normalize a requested sub-tab; falls back to the first key. */
	public static function resolve_sub( array $subs, string $default = '' ): string
	{
		$sub = sanitize_key( (string) ( $_GET['sub'] ?? $default ) );
		if ( $sub === '' || ! array_key_exists( $sub, $subs ) ) {
			$keys = array_keys( $subs );
			$sub  = $keys ? $keys[0] : '';
		}
		return $sub;
	}

	/** Third-level nav inside a feature sub-tab (sub-sub-tabs). Uses ?ss=. */
	public static function render_ssubtabs( string $page, string $tab, string $sub, string $active, array $ssubs ): void
	{
		if ( count( $ssubs ) < 2 ) {
			return;
		}
		echo '<nav class="nav-tab-wrapper wp-clearfix" style="background:#fff;border-bottom:none;margin-top:10px;padding:0 0 0 4px;" aria-label="Secciones de ' . esc_attr( $sub ) . '">';
		foreach ( $ssubs as $key => $label ) {
			$url = admin_url( 'admin.php?page=' . $page . '&tab=' . $tab . '&sub=' . $sub . '&ss=' . $key );
			$cls = $key === $active ? ' nav-tab-active' : '';
			echo '<a href="' . esc_url( $url ) . '" class="nav-tab' . $cls . '" style="font-size:13px;padding:6px 12px;">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
	}

	/** Normalize a requested sub-sub-tab; falls back to the first key. */
	public static function resolve_ssub( array $ssubs, string $default = '', string $param = 'ss' ): string
	{
		$param = preg_replace( '/[^a-z_]/i', '', (string) $param );
		$ss = sanitize_key( (string) ( $_GET[ $param ] ?? $default ) );
		if ( $ss === '' || ! array_key_exists( $ss, $ssubs ) ) {
			$keys = array_keys( $ssubs );
			$ss   = $keys ? $keys[0] : '';
		}
		return $ss;
	}

	/**
	 * Nav de sub-tabs (nav-tab-wrapper) reutilizable.
	 *
	 * @param array  $tabs   array( 'key' => array( 'label' => string, 'url' => string ), ... )
	 * @param string $active Clave activa.
	 */
	public static function subtab_nav_html( array $tabs, string $active ): string
	{
		$out  = '<nav class="nav-tab-wrapper wp-clearfix" style="background:#fff;border-bottom:none;margin-top:10px;padding:0 0 0 4px;" aria-label="Secciones">';
		foreach ( $tabs as $k => $t ) {
			$url   = isset( $t['url'] ) ? esc_url( $t['url'] ) : '#';
			$label = isset( $t['label'] ) ? esc_html( $t['label'] ) : (string) $k;
			$out  .= '<a href="' . $url . '" class="nav-tab' . ( $k === $active ? ' nav-tab-active' : '' ) . '" style="font-size:13px;padding:6px 12px;" data-st="' . esc_attr( $k ) . '">' . $label . '</a>';
		}
		$out .= '</nav>';
		return $out;
	}

	// ── Inline help (?) for tabs / sub-tabs ──

	/** Render a (?) help toggle + collapsible explanation box (shared by the
	 *  whole suite: same markup for every product). */
	public static function help_html( string $id, string $text ): string
	{
		return '<button type="button" class="button-link estandar-suite-help" data-help="' . esc_attr( $id ) . '" aria-label="Ayuda" style="margin-left:6px;font-size:16px;text-decoration:none;vertical-align:middle;" title="Ayuda">(?)</button>'
			. '<div id="help-' . esc_attr( $id ) . '" class="estandar-suite-help-box" style="display:none;background:#f0f6ff;border:1px solid #c5d9f2;padding:12px 14px;border-radius:6px;margin:8px 0 16px;max-width:760px;">' . wp_kses_post( $text ) . '</div>';
	}

	/** Toggle script for every .estandar-suite-help button (print once per page). */
	public static function help_script(): string
	{
		return '<script>(function(){document.querySelectorAll(".estandar-suite-help").forEach(function(btn){btn.addEventListener("click",function(){var box=document.getElementById("help-"+btn.getAttribute("data-help"));if(box){box.style.display=box.style.display==="none"?"block":"none";}});});})();</script>';
	}

	// ── Shared read-only helpers over legacy (v1) tables ──────────────────
	// The v2 suite NEVER writes to legacy tables; these helpers only read.

	/** True when a legacy table exists in the current database. */
	public static function legacy_table( string $name ): bool
	{
		global $wpdb;
		$wpdb->get_results( 'SELECT 1 FROM ' . $wpdb->prefix . $name . ' LIMIT 1' );
		return empty( $wpdb->last_error );
	}

	/** Read rows from a legacy table (read-only). */
	public static function legacy_rows( string $name, string $suffix = 'ORDER BY id DESC LIMIT 50' ): array
	{
		if ( ! self::legacy_table( $name ) ) {
			return array();
		}
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . $wpdb->prefix . $name . ' ' . $suffix, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/** Count rows in a legacy table (read-only). */
	public static function legacy_count( string $name ): int
	{
		if ( ! self::legacy_table( $name ) ) {
			return 0;
		}
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . $name );
	}

	/** Group a legacy table by one column (read-only): key => count. */
	public static function legacy_group( string $name, string $column ): array
	{
		if ( ! self::legacy_table( $name ) ) {
			return array();
		}
		global $wpdb;
		$col  = preg_replace( '/[^a-z_]/i', '', $column );
		$rows = $wpdb->get_results(
			'SELECT ' . $col . ' AS k, COUNT(*) AS n FROM ' . $wpdb->prefix . $name . ' GROUP BY ' . $col . ' ORDER BY n DESC LIMIT 12',
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$key = (string) ( $r['k'] ?? '' );
			if ( $key === '' ) { $key = '(sin dato)'; }
			$out[ $key ] = (int) $r['n'];
		}
		return $out;
	}

	// ── Shared feature settings (per functionality sub-tab) ──────────────

	/**
	 * Render the settings form for one feature. Field types: bool | text |
	 * select (options as key => label). POSTs to admin-post with $action.
	 */
	public static function render_feature_settings( string $action, string $option, array $fields, string $feature = '', array $extra = array() ): void
	{
		$current = get_option( $option, array() );
		$current = is_array( $current ) ? $current : array();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="max-width:640px;">';
		wp_nonce_field( $action );
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		if ( $feature !== '' ) {
			echo '<input type="hidden" name="feature" value="' . esc_attr( $feature ) . '">';
		}
		foreach ( $extra as $k => $v ) {
			echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
		}
		echo '<table class="form-table" role="presentation">';
		foreach ( $fields as $key => $cfg ) {
			$val = isset( $current[ $key ] ) ? $current[ $key ] : ( $cfg['default'] ?? '' );
			$type = $cfg['type'] ?? 'text';
			echo '<tr><th scope="row"><label for="es-' . esc_attr( $key ) . '">' . esc_html( $cfg['label'] ) . '</label></th><td>';
			if ( $type === 'bool' ) {
				echo '<label><input type="checkbox" name="settings[' . esc_attr( $key ) . ']" value="1" ' . checked( (bool) $val, true, false ) . '> ' . esc_html( $cfg['desc'] ?? '' ) . '</label>';
			} elseif ( $type === 'select' ) {
				echo '<select id="es-' . esc_attr( $key ) . '" name="settings[' . esc_attr( $key ) . ']">';
				foreach ( ( $cfg['options'] ?? array() ) as $ok => $ol ) {
					echo '<option value="' . esc_attr( $ok ) . '" ' . selected( (string) $val, (string) $ok, false ) . '>' . esc_html( $ol ) . '</option>';
				}
				echo '</select> <span class="description">' . esc_html( $cfg['desc'] ?? '' ) . '</span>';
			} else {
				echo '<input id="es-' . esc_attr( $key ) . '" type="text" name="settings[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) $val ) . '" class="regular-text"> <span class="description">' . esc_html( $cfg['desc'] ?? '' ) . '</span>';
			}
			echo '</td></tr>';
		}
		echo '</table>';
		submit_button( 'Guardar settings', 'primary', 'save', false );
		echo '</form>';
	}

	/** Sanitize raw POST settings against the feature field descriptors. */
	public static function sanitize_feature_fields( array $fields, array $raw ): array
	{
		$clean = array();
		foreach ( $fields as $key => $cfg ) {
			$type = $cfg['type'] ?? 'text';
			if ( $type === 'bool' ) {
				$clean[ $key ] = ! empty( $raw[ $key ] ) ? 1 : 0;
			} elseif ( $type === 'select' ) {
				$v = sanitize_key( (string) ( $raw[ $key ] ?? '' ) );
				$clean[ $key ] = isset( $cfg['options'][ $v ] ) ? $v : ( $cfg['default'] ?? '' );
			} else {
				$clean[ $key ] = sanitize_text_field( (string) ( $raw[ $key ] ?? '' ) );
			}
		}
		return $clean;
	}

	// ── Tab/sub-tab visibility (visual only; never affects functionality) ──

	/** Option key storing the visible sub-tabs per tab for a product. */
	public static function visibility_option( string $product ): string
	{
		return 'estandar_suite_' . $product . '_visibility';
	}

	/**
	 * Read the saved visibility map. Format:
	 *   array( 'estado' => array( 'resumen', 'datos' ), 'config' => array( ... ), ... )
	 * A missing option means "everything visible".
	 */
	public static function get_visibility( string $product ): array
	{
		$saved = function_exists( 'get_option' ) ? get_option( self::visibility_option( $product ), array() ) : array();
		return is_array( $saved ) ? $saved : array();
	}

	/** True when a tab has at least one visible sub-tab (or map is empty = all visible). */
	public static function is_tab_visible( string $product, string $tab, array $subs ): bool
	{
		$map = self::get_visibility( $product );
		if ( empty( $map ) ) {
			return true;
		}
		$visible = isset( $map[ $tab ] ) && is_array( $map[ $tab ] ) ? $map[ $tab ] : array();
		if ( empty( $visible ) ) {
			return false;
		}
		return true;
	}

	/** Filter the full tab map to only visible tabs. A tab absent from the saved
	 *  map is treated as visible (default). A tab present with an empty list is
	 *  hidden. */
	public static function filter_tabs( string $product, array $tabs, array $subs_by_tab ): array
	{
		$map = self::get_visibility( $product );
		$out = array();
		foreach ( $tabs as $key => $label ) {
			if ( ! isset( $map[ $key ] ) ) {
				$out[ $key ] = $label; // not customized → visible.
			} elseif ( is_array( $map[ $key ] ) && ! empty( $map[ $key ] ) ) {
				$out[ $key ] = $label; // has visible sub-tabs.
			}
			// empty list → hidden.
		}
		return $out ? $out : $tabs; // never hide all tabs.
	}

	/** Filter a tab's sub-tabs to only the visible ones. A tab absent from the
	 *  saved map returns all sub-tabs (default). */
	public static function filter_subs( string $product, string $tab, array $subs ): array
	{
		$map = self::get_visibility( $product );
		if ( ! isset( $map[ $tab ] ) || ! is_array( $map[ $tab ] ) ) {
			return $subs;
		}
		$out = array();
		foreach ( $subs as $key => $label ) {
			if ( in_array( $key, $map[ $tab ], true ) ) {
				$out[ $key ] = $label;
			}
		}
		return $out ? $out : $subs; // never hide all sub-tabs.
	}

	/** Persist the visibility map from a POST payload. The payload must include
	 *  the full list of tabs (so an empty per-tab list = hidden tab). */
	public static function save_visibility( string $product, array $payload ): void
	{
		$clean = array();
		foreach ( $payload as $tab => $subs ) {
			$tab  = sanitize_key( (string) $tab );
			if ( $tab === '' ) {
				continue;
			}
			$clean[ $tab ] = array();
			if ( is_array( $subs ) ) {
				foreach ( $subs as $s ) {
					$s = sanitize_key( (string) $s );
					if ( $s !== '' ) {
						$clean[ $tab ][] = $s;
					}
				}
			}
		}
		if ( function_exists( 'update_option' ) ) {
			update_option( self::visibility_option( $product ), $clean, false );
		}
	}

	/** Map a product key to its admin page slug (used for redirects). */
	public static function visibility_page( string $product ): string
	{
		$pages = array(
			'core'     => 'estandar-suite',
			'capture'  => 'estandar-suite-capture',
			'brain'    => 'estandar-suite-brain',
			'flow'     => 'estandar-suite-flow',
			'insights' => 'estandar-suite-insights',
		);
		return isset( $pages[ $product ] ) ? $pages[ $product ] : 'estandar-suite';
	}

	/**
	 * Render the visual visibility manager for a product page: one group per tab
	 * with a checkbox per sub-tab. Used inside the Configuración tab.
	 *
	 * @param string $product   Product key ('core','capture',...).
	 * @param string $page      Admin page slug.
	 * @param array  $subs_by_tab Map of tab => map(sub => label).
	 */
	public static function render_visibility_manager( string $product, string $page, array $subs_by_tab ): void
	{
		$map = self::get_visibility( $product );
		echo '<div class="postbox" style="max-width:100%;margin-top:18px;">';
		echo '<div class="postbox-header"><h2 class="hndle" style="padding:8px 12px;margin:0;font-size:14px;">Visibilidad de pestañas</h2></div>';
		echo '<div class="inside" style="padding:12px;">';
		echo '<p class="description">Oculta o muestra los tabs y sub-tabs de este plugin. <strong>Solo afecta la visualización</strong>; ninguna función, setting o dato se pierde.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'estandar_suite_save_visibility' );
		echo '<input type="hidden" name="action" value="estandar_suite_save_visibility">';
		echo '<input type="hidden" name="product" value="' . esc_attr( $product ) . '">';
		// Always submit the full tab list so an all-unchecked tab is saved hidden.
		foreach ( array_keys( $subs_by_tab ) as $all_tab ) {
			echo '<input type="hidden" name="all_tabs[]" value="' . esc_attr( $all_tab ) . '">';
		}
		foreach ( $subs_by_tab as $tab => $subs ) {
			$active_subs = isset( $map[ $tab ] ) && is_array( $map[ $tab ] ) ? $map[ $tab ] : array_keys( $subs );
			echo '<h4 style="margin:14px 0 6px;">' . esc_html( ucfirst( $tab ) ) . '</h4>';
			echo '<div style="display:flex;flex-wrap:wrap;gap:4px 18px;">';
			foreach ( $subs as $sub => $label ) {
				// The visibility manager itself can never be hidden.
				$locked  = $sub === 'visibilidad';
				$checked = $locked || in_array( $sub, $active_subs, true );
				echo '<label style="white-space:nowrap;"><input type="checkbox" name="subs[' . esc_attr( $tab ) . '][]" value="' . esc_attr( $sub ) . '" ' . checked( $checked, true, false ) . ( $locked ? ' disabled title="Siempre visible (obligatorio)"' : '' ) . '> ' . esc_html( $label ) . ( $locked ? ' <span class="description">(fija)</span>' : '' ) . '</label>';
			}
			echo '</div>';
		}
		echo '<p style="margin-top:12px;">';
		submit_button( 'Guardar visibilidad', 'primary', 'save_visibility_btn', false );
		echo '</p>';
		echo '</form></div></div>';
	}

	/** admin_post handler: persist visibility for a product. */
	public static function handle_save_visibility(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_save_visibility' );
		$product = sanitize_key( (string) ( $_POST['product'] ?? '' ) );
		$all     = isset( $_POST['all_tabs'] ) && is_array( $_POST['all_tabs'] ) ? $_POST['all_tabs'] : array();
		$checked = isset( $_POST['subs'] ) && is_array( $_POST['subs'] ) ? $_POST['subs'] : array();
		// Build the full map: every tab is present; tabs with no checked sub-tab
		// become an empty list (= hidden tab).
		$map = array();
		foreach ( $all as $tab ) {
			$tab  = sanitize_key( (string) $tab );
			if ( $tab === '' ) {
				continue;
			}
			$map[ $tab ] = isset( $checked[ $tab ] ) && is_array( $checked[ $tab ] ) ? array_map( 'sanitize_key', $checked[ $tab ] ) : array();
		}
		// The visibility manager itself can never be hidden (server-side guard).
		if ( isset( $map['config'] ) && is_array( $map['config'] ) && ! in_array( 'visibilidad', $map['config'], true ) ) {
			$map['config'][] = 'visibilidad';
		}
		self::save_visibility( $product, $map );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::visibility_page( $product ) . '&tab=config&sub=visibilidad&visibility_saved=1' ) );
		exit;
	}

	/**
	 * Render the complete function inventory for a product: the 7 functional sets
	 * (Esquema · Dominio · Adopción · Eventos · REST · CLI · UI) each as a table.
	 * Guarantees no function is left out of the admin UI. Data source is the
	 * product's own static catalog (single source of truth).
	 *
	 * @param array $sets        Map of set-label => array of entries.
	 *                           Each entry: array( 'fn' => name, 'file' => source, 'desc' => purpose ).
	 * @param array $fn_settings Map of function-name => array of setting keys it uses
	 *                           (to mark which functions have settings).
	 */
	public static function render_inventory( array $sets, array $fn_settings = array() ): void
	{
		foreach ( $sets as $label => $entries ) {
			echo '<h3>' . esc_html( $label ) . '</h3>';
			echo '<table class="widefat striped"><thead><tr><th style="width:26%;">Función</th><th style="width:20%;">Origen</th><th>Propósito</th><th style="width:12%;">Settings</th></tr></thead><tbody>';
			foreach ( $entries as $e ) {
				$fn    = $e['fn'];
				$has   = ! empty( $fn_settings[ $fn ] ) ? $fn_settings[ $fn ] : array();
				$badge = $has ? '<span class="dashicons dashicons-admin-generic" style="color:#2271b1;" title="Usa settings"></span> ' . esc_html( implode( ', ', $has ) ) : '<span class="description">—</span>';
				echo '<tr>'
					. '<td><code>' . esc_html( $fn ) . '</code></td>'
					. '<td><code>' . esc_html( $e['file'] ) . '</code></td>'
					. '<td>' . esc_html( $e['desc'] ) . '</td>'
					. '<td>' . $badge . '</td>'
					. '</tr>';
			}
			echo '</tbody></table>';
		}
	}

	/**
	 * Render a settings panel for a tab. Each entry is a settings descriptor:
	 *   array(
	 *     'name'    => option key,
	 *     'label'   => human label,
	 *     'desc'    => purpose,
	 *     'editable'=> bool (has a mutation surface in this plugin),
	 *     'legacy'  => bool (owned by a legacy plugin),
	 *     'group'   => optional area label (own settings are grouped by it),
	 *   )
	 * Own settings are rendered grouped by 'group'; legacy settings are shown
	 * in a visually separated section below.
	 */
	public static function render_settings_panel( array $settings ): void
	{
		if ( ! $settings ) {
			return;
		}
		$own    = array_filter( $settings, function ( $s ) { return empty( $s['legacy'] ); } );
		$legacy = array_filter( $settings, function ( $s ) { return ! empty( $s['legacy'] ); } );

		$row_html = function ( array $s ) {
			$value = function_exists( 'get_option' ) ? get_option( $s['name'], null ) : null;
			$value = $value === null ? '' : $value;
			// Seguridad: las opciones heredadas con nombre sensible se muestran
			// enmascaradas (solo lectura, nunca se exponen tokens/claves).
			if ( ! empty( $s['legacy'] ) && $value !== '' && preg_match( '/(token|secret|password|private_?key|api[_-]?key|client_?secret)/i', (string) $s['name'] ) ) {
				$value = '•••••• (oculto)';
			}
			if ( is_array( $value ) || is_object( $value ) ) {
				$value = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
			}
			$state = ! empty( $s['editable'] )
				? '<span style="color:#2271b1;">editable</span>'
				: '<span class="description">read-only</span>';
			return '<tr>'
				. '<td><code>' . esc_html( $s['name'] ) . '</code></td>'
				. '<td>' . esc_html( $s['label'] ) . '<br><span class="description">' . esc_html( $s['desc'] ) . '</span></td>'
				. '<td><code style="word-break:break-all;">' . esc_html( (string) $value ) . '</code></td>'
				. '<td>' . $state . '</td>'
				. '</tr>';
		};
		$table_html = function ( array $list, string $title = '' ) use ( $row_html ) {
			if ( $title !== '' ) {
				echo '<h4 style="margin:18px 0 6px;">' . esc_html( $title ) . '</h4>';
			}
			echo '<table class="widefat striped"><thead><tr><th style="width:30%;">Option</th><th>Descripción</th><th style="width:34%;">Valor actual</th><th style="width:8%;">Estado</th></tr></thead><tbody>';
			foreach ( $list as $s ) {
				echo $row_html( $s );
			}
			echo '</tbody></table>';
		};

		echo '<div class="postbox" style="max-width:100%;margin-top:18px;">';
		echo '<div class="postbox-header"><h2 class="hndle ui-sortable-handle" style="padding:8px 12px;margin:0;font-size:14px;">Settings</h2></div>';
		echo '<div class="inside" style="padding:12px;">';

		// Group own settings by 'group' label, preserving declaration order.
		$groups = array();
		foreach ( $own as $s ) {
			$groups[ $s['group'] ?? 'General' ][] = $s;
		}
		foreach ( $groups as $g => $items ) {
			$table_html( $items, $g );
		}

		if ( $legacy ) {
			echo '<h4 style="margin:18px 0 6px;">Settings heredados (plugins anteriores)</h4>';
			echo '<p class="description" style="margin:0 0 8px;">Opciones que pertenecen a los plugins legados; el v2 solo las lee, nunca las modifica.</p>';
			$table_html( $legacy );
		}
		echo '</div></div>';
	}

	public static function render(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		$tabs = array(
			'inicio'   => 'Inicio',
			'estado'   => 'Estado',
			'config'   => 'Configuración',
			'datos'    => 'Datos',
			'avanzado' => 'Avanzado',
		);

		// ── Funciones que usan settings (marcadas en el inventario) ─────────
		$fn_settings = array(
			'settings'          => array( 'estandar_suite_core_telemetry_settings' ),
			'save_settings'     => array( 'estandar_suite_core_telemetry_settings' ),
			'map_events'        => array( 'estandar_suite_core_event_map' ),
			'event_map'         => array( 'estandar_suite_core_event_map' ),
			'event_targets'     => array( 'estandar_suite_core_event_map' ),
			'key'               => array( 'estandar_suite_core_secret_key' ),
			'create'            => array( 'estandar_suite_core_secret_key' ),
			'consume'           => array( 'estandar_suite_core_secret_key' ),
			'activate'          => array( 'estandar_suite_core_schema_version' ),
			'maybe_migrate'     => array( 'estandar_suite_core_schema_version' ),
		);

		// ── Settings mostrados en el panel del tab Configuración ────────────
		$settings = array(
			array( 'name' => 'estandar_suite_core_telemetry_settings', 'label' => 'Telemetría', 'desc' => 'Nivel mínimo, retención y habilitación del bus de eventos.', 'editable' => true, 'legacy' => false ),
			array( 'name' => 'estandar_suite_core_event_map', 'label' => 'Mapa de eventos → destinos', 'desc' => 'Qué eventos avisan a cada destino.', 'editable' => true, 'legacy' => false ),
			array( 'name' => 'estandar_suite_core_secret_key', 'label' => 'Clave AES-256-GCM', 'desc' => 'Clave maestra de cifrado de secretos (generada al instalar).', 'editable' => false, 'legacy' => false ),
			array( 'name' => 'estandar_suite_core_schema_version', 'label' => 'Versión de esquema', 'desc' => 'Versión actual de las tablas estandar_core_*.', 'editable' => false, 'legacy' => false ),
		);
		$tab        = self::resolve_tab( $tabs, 'inicio' );
		$subs = array(
			'inicio'   => array( 'bienvenida' => 'Bienvenida' ),
			'estado'   => array( 'resumen' => 'Resumen', 'datos' => 'Datos', 'esquema' => 'Esquema' ),
			'config'   => array( 'telemetria' => 'Telemetría', 'destinos' => 'Destinos', 'integraciones' => 'Integraciones', 'secretos' => 'Secretos', 'secure' => 'Secure Share', 'emoji' => 'Emoji', 'visibilidad' => 'Visibilidad' ),
			'datos'    => array( 'adopcion' => 'Adopción', 'heredados' => 'Settings heredados' ),
			'avanzado' => array( 'rest' => 'REST', 'cli' => 'WP-CLI', 'inventario' => 'Inventario' ),
		);
		// ── Visibilidad: filter tabs/sub-tabs (visual only) ─────────────────
		$visible_tabs = self::filter_tabs( 'core', $tabs, $subs );
		foreach ( $subs as $t => $s_list ) {
			$subs[ $t ] = self::filter_subs( 'core', $t, $s_list );
		}
		$tabs = $visible_tabs;
		// Always keep the visibility manager reachable in Configuración.
		if ( isset( $tabs['config'] ) ) {
			$subs['config']['visibilidad'] = 'Visibilidad';
		}
		// The suite intro tab is always reachable.
		if ( isset( $tabs['inicio'] ) ) {
			$subs['inicio']['bienvenida'] = 'Bienvenida';
		}
		$tab = self::resolve_tab( $tabs, 'inicio' );
		$sub = isset( $subs[ $tab ] ) ? self::resolve_sub( $subs[ $tab ] ) : '';
		$health    = Health::snapshot();
		$dest      = Destinations::list();
		$event_map = Destinations::event_map();
		$events    = Destinations::canonical_events();
		$secrets   = Secrets::recent( 10 );
		$telemetry = Telemetry::recent( 25 );
		$legacy    = AdoptionProbe::probe_legacy();
		$dest_saved = isset( $_GET['dest_saved'] );
		$dest_del   = isset( $_GET['dest_del'] );
		$secret_ok  = isset( $_GET['secret_ok'] );
		$test_id    = isset( $_GET['test_id'] ) ? (int) $_GET['test_id'] : 0;
		$test_ok    = isset( $_GET['test_ok'] );
		$test_http  = isset( $_GET['test_http'] ) ? (int) $_GET['test_http'] : 0;
		$test_err   = isset( $_GET['test_err'] ) ? sanitize_text_field( wp_unslash( $_GET['test_err'] ) ) : '';
		$map_saved  = isset( $_GET['map_saved'] );
		$telemetry_saved = isset( $_GET['telemetry_saved'] );
		$integrations_connections = Connections::list();
		$integration_adapters     = Adapters::catalog();
		$integration_bindings     = Integrations::list();

		// ── Inventario completo de funciones (cobertura 100%) ──────────────
		$sets = array(
			'Esquema (ciclo de vida y tablas)' => array(
				array( 'fn' => 'activate', 'file' => 'Schema.php', 'desc' => 'Crea tablas y opciones propias al activar el plugin.' ),
				array( 'fn' => 'create_tables', 'file' => 'Schema.php', 'desc' => 'Crea las tablas estandar_core_* si no existen.' ),
				array( 'fn' => 'maybe_migrate', 'file' => 'Schema.php', 'desc' => 'Aplica migraciones de esquema según versión guardada.' ),
				array( 'fn' => 'protected_legacy_prefixes', 'file' => 'Schema.php', 'desc' => 'Lista de prefijos legados (es_/ea_/ecb_/estandar_leads_) que nunca se escriben.' ),
			),
			'Dominio (lógica de negocio)' => array(
				array( 'fn' => 'defaults', 'file' => 'Telemetry.php', 'desc' => 'Valores por defecto de la configuración de telemetría.' ),
				array( 'fn' => 'settings', 'file' => 'Telemetry.php', 'desc' => 'Lee y normaliza la configuración de telemetría.' ),
				array( 'fn' => 'save_settings', 'file' => 'Telemetry.php', 'desc' => 'Persiste la configuración de telemetría.' ),
				array( 'fn' => 'emit', 'file' => 'Telemetry.php', 'desc' => 'Registra un evento técnico sin PII.' ),
				array( 'fn' => 'level_allows', 'file' => 'Telemetry.php', 'desc' => 'Decide si un nivel supera el mínimo configurado.' ),
				array( 'fn' => 'prune', 'file' => 'Telemetry.php', 'desc' => 'Elimina eventos viejos según retención.' ),
				array( 'fn' => 'recent', 'file' => 'Telemetry.php', 'desc' => 'Devuelve los últimos eventos técnicos.' ),
				array( 'fn' => 'sanitize_context', 'file' => 'Telemetry.php', 'desc' => 'Limpia el contexto para no registrar PII.' ),
				array( 'fn' => 'create', 'file' => 'Secrets.php', 'desc' => 'Crea un secreto cifrado de un solo uso.' ),
				array( 'fn' => 'token', 'file' => 'Secrets.php', 'desc' => 'Genera el token del secreto.' ),
				array( 'fn' => 'consume', 'file' => 'Secrets.php', 'desc' => 'Consume un secreto (una sola lectura).' ),
				array( 'fn' => 'consume_link', 'file' => 'Secrets.php', 'desc' => 'Construye el enlace de consumo del secreto.' ),
				array( 'fn' => 'cleanup', 'file' => 'Secrets.php', 'desc' => 'Limpia secretos usados o expirados.' ),
				array( 'fn' => 'key', 'file' => 'Secrets.php', 'desc' => 'Genera/deriva la clave de cifrado del secreto.' ),
				array( 'fn' => 'status', 'file' => 'Secrets.php', 'desc' => 'Estado de un secreto (activo/usado/expirado).' ),
				array( 'fn' => 'register_destination', 'file' => 'Destinations.php', 'desc' => 'Registra un destino (webhook/sheets/CRM).' ),
				array( 'fn' => 'enqueue', 'file' => 'Destinations.php', 'desc' => 'Encola una entrega para un destino.' ),
				array( 'fn' => 'dispatch_event', 'file' => 'Destinations.php', 'desc' => 'Entrega un evento a sus destinos mapeados.' ),
				array( 'fn' => 'drain', 'file' => 'Destinations.php', 'desc' => 'Procesa entregas pendientes.' ),
				array( 'fn' => 'test', 'file' => 'Destinations.php', 'desc' => 'Prueba la conexión con un destino.' ),
				array( 'fn' => 'idempotency_key', 'file' => 'Destinations.php', 'desc' => 'Clave para evitar entregas duplicadas.' ),
				array( 'fn' => 'canonical_events', 'file' => 'Destinations.php', 'desc' => 'Lista de eventos canónicos de la suite.' ),
				array( 'fn' => 'map_events', 'file' => 'Destinations.php', 'desc' => 'Mapea eventos a un destino.' ),
				array( 'fn' => 'event_map', 'file' => 'Destinations.php', 'desc' => 'Devuelve el mapa evento→destino.' ),
				array( 'fn' => 'event_targets', 'file' => 'Destinations.php', 'desc' => 'Devuelve los destinos objetivo de un evento.' ),
				array( 'fn' => 'list', 'file' => 'Destinations.php', 'desc' => 'Lista destinos registrados.' ),
				array( 'fn' => 'delete', 'file' => 'Destinations.php', 'desc' => 'Elimina un destino.' ),
				array( 'fn' => 'resolve', 'file' => 'Channels.php', 'desc' => 'Resuelve el canal de una captura.' ),
				array( 'fn' => 'extract_click_ids', 'file' => 'Channels.php', 'desc' => 'Extrae click IDs de una captura.' ),
				array( 'fn' => 'has_click_evidence', 'file' => 'Channels.php', 'desc' => 'Indica si hay evidencia de click.' ),
				array( 'fn' => 'is_optimal', 'file' => 'Optimal.php', 'desc' => 'Compara un valor contra el óptimo de fábrica.' ),
				array( 'fn' => 'diff', 'file' => 'Optimal.php', 'desc' => 'Describe la diferencia respecto al óptimo.' ),
				array( 'fn' => 'note', 'file' => 'Optimal.php', 'desc' => 'Etiqueta visual de cercanía al óptimo.' ),
				array( 'fn' => 'snapshot', 'file' => 'Health.php', 'desc' => 'Diagnóstico estructurado de salud.' ),
				array( 'fn' => 'plugin_status', 'file' => 'Health.php', 'desc' => 'Estado de salud de un plugin de la suite.' ),
				array( 'fn' => 'render_text', 'file' => 'Health.php', 'desc' => 'Representación textual del diagnóstico.' ),
				array( 'fn' => 'record', 'file' => 'Contracts.php', 'desc' => 'Contrato TelemetryBus: registrar un evento.' ),
				array( 'fn' => 'persist', 'file' => 'Contracts.php', 'desc' => 'Contrato CaptureSink: persistir una captura.' ),
				array( 'fn' => 'status_label', 'file' => 'Contracts.php', 'desc' => 'Contrato CommercialStageMap: etiqueta de etapa.' ),
			),
			'Adopción (datos legados)' => array(
				array( 'fn' => 'probe_legacy', 'file' => 'AdoptionProbe.php', 'desc' => 'Detecta tablas legadas de la instalación original.' ),
				array( 'fn' => 'is_legacy_table', 'file' => 'AdoptionProbe.php', 'desc' => 'Comprueba si un prefijo de tabla es legado protegido.' ),
				array( 'fn' => 'check_option', 'file' => 'Health.php', 'desc' => 'Valida el estado de una opción de la suite.' ),
				array( 'fn' => 'init', 'file' => 'LegacyMap.php', 'desc' => 'Puente de migración: re-registra shortcodes y REST legados cuando su plugin queda inactivo.' ),
				array( 'fn' => 'legacy_option', 'file' => 'LegacyMap.php', 'desc' => 'Lee settings/tokens legados sin tocarlos.' ),
				array( 'fn' => 'has_legacy_install', 'file' => 'LegacyMap.php', 'desc' => 'True si existen tablas legadas (producción-like).' ),
				array( 'fn' => 'verify_coverage', 'file' => 'LegacyMap.php', 'desc' => 'Reporte de cobertura de migración (read-only).' ),
			),
			'Integración legada (solo lectura)' => array(
				array( 'fn' => 'legacy_table', 'file' => 'Admin.php', 'desc' => 'Verifica que exista una tabla legada.' ),
				array( 'fn' => 'legacy_rows', 'file' => 'Admin.php', 'desc' => 'Lee filas de una tabla legada (read-only).' ),
				array( 'fn' => 'legacy_count', 'file' => 'Admin.php', 'desc' => 'Cuenta filas de una tabla legada (read-only).' ),
				array( 'fn' => 'legacy_group', 'file' => 'Admin.php', 'desc' => 'Agrupa por columna una tabla legada (read-only).' ),
				array( 'fn' => 'render_feature_settings', 'file' => 'Admin.php', 'desc' => 'Renderiza el formulario de settings de una feature.' ),
				array( 'fn' => 'sanitize_feature_fields', 'file' => 'Admin.php', 'desc' => 'Sanitiza los settings de una feature contra su whitelist.' ),
			),
			'Eventos (integración)' => array(
				array( 'fn' => 'init', 'file' => 'Admin.php', 'desc' => 'Registra menú, hooks admin_post y el evento estandar_suite_admin_register.' ),
				array( 'fn' => 'init_frontend', 'file' => 'Secrets.php', 'desc' => 'Registra la ruta pública de consumo de secretos.' ),
			),
			'REST API' => array(
				array( 'fn' => 'routes', 'file' => 'Rest.php', 'desc' => 'Define las rutas REST del Core (health/telemetry/secrets).' ),
				array( 'fn' => 'init', 'file' => 'Rest.php', 'desc' => 'Registra las rutas REST en rest_api_init.' ),
			),
			'WP-CLI' => array(
				array( 'fn' => 'doctor', 'file' => 'Cli.php', 'desc' => 'Diagnóstico de la suite.' ),
				array( 'fn' => 'telemetry', 'file' => 'Cli.php', 'desc' => 'Ver eventos técnicos.' ),
				array( 'fn' => 'telemetry_status', 'file' => 'Cli.php', 'desc' => 'Estado de la telemetría.' ),
				array( 'fn' => 'telemetry_settings', 'file' => 'Cli.php', 'desc' => 'Configuración de telemetría vía CLI.' ),
				array( 'fn' => 'purge_secrets', 'file' => 'Cli.php', 'desc' => 'Eliminar secretos usados/expirados.' ),
				array( 'fn' => 'secrets_list', 'file' => 'Cli.php', 'desc' => 'Listar secretos.' ),
				array( 'fn' => 'secrets_create', 'file' => 'Cli.php', 'desc' => 'Crear un secreto.' ),
				array( 'fn' => 'destinations_list', 'file' => 'Cli.php', 'desc' => 'Listar destinos.' ),
				array( 'fn' => 'destinations_add', 'file' => 'Cli.php', 'desc' => 'Registrar un destino.' ),
				array( 'fn' => 'destinations_delete', 'file' => 'Cli.php', 'desc' => 'Eliminar un destino.' ),
				array( 'fn' => 'destinations_test', 'file' => 'Cli.php', 'desc' => 'Probar un destino.' ),
				array( 'fn' => 'drain', 'file' => 'Cli.php', 'desc' => 'Enviar entregas pendientes.' ),
				array( 'fn' => 'probe', 'file' => 'Cli.php', 'desc' => 'Detectar instalación legada.' ),
				array( 'fn' => 'option_get', 'file' => 'Cli.php', 'desc' => 'Leer una opción de la suite.' ),
				array( 'fn' => 'option_set', 'file' => 'Cli.php', 'desc' => 'Escribir una opción de la suite.' ),
				array( 'fn' => 'option_delete', 'file' => 'Cli.php', 'desc' => 'Eliminar una opción de la suite.' ),
				array( 'fn' => 'is_settable_option', 'file' => 'Cli.php', 'desc' => 'Valida que una opción sea modificable.' ),
				array( 'fn' => 'feature_settings_show', 'file' => 'Cli.php', 'desc' => 'Muestra los settings de una feature (CLI, compartido).' ),
				array( 'fn' => 'feature_settings_set', 'file' => 'Cli.php', 'desc' => 'Establece un setting de feature (CLI, compartido).' ),
				array( 'fn' => 'secure_list', 'file' => 'Cli.php', 'desc' => 'Lista entregas seguras legadas.' ),
				array( 'fn' => 'secure_settings_get', 'file' => 'Cli.php', 'desc' => 'Muestra settings del puente Secure Share.' ),
				array( 'fn' => 'secure_settings_set', 'file' => 'Cli.php', 'desc' => 'Establece un setting del puente Secure Share.' ),
			),
			'UI (interfaz de administración)' => array(
				array( 'fn' => 'menu', 'file' => 'Admin.php', 'desc' => 'Crea el menú raíz "Estandar Suite" y dispara el registro de submenús.' ),
				array( 'fn' => 'render', 'file' => 'Admin.php', 'desc' => 'Renderiza la página de Core con sus 4 tabs.' ),
				array( 'fn' => 'render_tabs', 'file' => 'Admin.php', 'desc' => 'Nav compartida de 4 tabs (Estado/Configuración/Datos/Avanzado).' ),
				array( 'fn' => 'render_inventory', 'file' => 'Admin.php', 'desc' => 'Renderiza el inventario completo de funciones por conjunto.' ),
				array( 'fn' => 'render_ssubtabs', 'file' => 'Admin.php', 'desc' => 'Nav de tercer nivel (sub-sub-tabs) dentro de un feature.' ),
				array( 'fn' => 'resolve_ssub', 'file' => 'Admin.php', 'desc' => 'Normaliza el sub-sub-tab solicitado (?ss=).' ),
				array( 'fn' => 'resolve_tab', 'file' => 'Admin.php', 'desc' => 'Valida el tab solicitado contra la whitelist.' ),
				array( 'fn' => 'handle_add_destination', 'file' => 'Admin.php', 'desc' => 'Handler admin_post para registrar destino.' ),
				array( 'fn' => 'handle_delete_destination', 'file' => 'Admin.php', 'desc' => 'Handler admin_post para eliminar destino.' ),
				array( 'fn' => 'handle_test_destination', 'file' => 'Admin.php', 'desc' => 'Handler admin_post para probar destino.' ),
				array( 'fn' => 'handle_save_event_map', 'file' => 'Admin.php', 'desc' => 'Handler admin_post para guardar mapa de eventos.' ),
				array( 'fn' => 'handle_create_secret', 'file' => 'Admin.php', 'desc' => 'Handler admin_post para crear secreto.' ),
				array( 'fn' => 'handle_save_telemetry_settings', 'file' => 'Admin.php', 'desc' => 'Handler admin_post para guardar telemetría.' ),
				array( 'fn' => 'feature_fields', 'file' => 'Admin.php', 'desc' => 'Descriptor de settings de cada feature (secure).' ),
				array( 'fn' => 'handle_save_feature', 'file' => 'Admin.php', 'desc' => 'Handler admin_post para guardar settings de features.' ),
				array( 'fn' => 'maybe_render', 'file' => 'Secrets.php', 'desc' => 'Render condicional del consumo de secreto en frontend.' ),
				array( 'fn' => 'render_page', 'file' => 'Secrets.php', 'desc' => 'Renderiza la página de consumo de un secreto.' ),
			),
		);

		// ── Feature data for the Secure Share sub-tab (read-only legacy) ────
		$feature_fields     = self::feature_fields();
		$secure_fields      = $feature_fields['secure'];
		$secure_deliveries  = array();
		$feature_saved      = isset( $_GET['feature_saved'] );
		if ( $tab === 'config' && $sub === 'secure' ) {
			$secure_deliveries = self::legacy_rows( 'estandar_secure_deliveries', 'ORDER BY id DESC LIMIT 50' );
		}

		include ESTANDAR_SUITE_CORE_PATH . 'includes/templates/core-admin.php';
	}

	/** Emoji catalog grouped by category (label for search). */
	public static function emoji_catalog(): array
	{
		return array(
			'Emojis' => array(
				array( 'emoji' => '😀', 'label' => 'cara sonriente' ), array( 'emoji' => '😁', 'label' => 'cara feliz' ),
				array( 'emoji' => '😂', 'label' => 'cara llorando de risa' ), array( 'emoji' => '🤣', 'label' => 'rodando de risa' ),
				array( 'emoji' => '😊', 'label' => 'cara sonriente con ojos' ), array( 'emoji' => '😍', 'label' => 'ojos de corazon' ),
				array( 'emoji' => '😎', 'label' => 'gafas de sol' ), array( 'emoji' => '🤔', 'label' => 'pensando' ),
				array( 'emoji' => '😴', 'label' => 'dormido' ), array( 'emoji' => '🤯', 'label' => 'cabeza explotando' ),
				array( 'emoji' => '🥳', 'label' => 'fiesta' ), array( 'emoji' => '😭', 'label' => 'llorando' ),
				array( 'emoji' => '😡', 'label' => 'enojado' ), array( 'emoji' => '👍', 'label' => 'pulgar arriba' ),
				array( 'emoji' => '👎', 'label' => 'pulgar abajo' ), array( 'emoji' => '👏', 'label' => 'aplausos' ),
				array( 'emoji' => '🙏', 'label' => 'gracias' ), array( 'emoji' => '🤝', 'label' => 'apreton de manos' ),
				array( 'emoji' => '✌️', 'label' => 'victoria' ), array( 'emoji' => '🤞', 'label' => 'cruzar dedos' ),
				array( 'emoji' => '💪', 'label' => 'musculo' ), array( 'emoji' => '👀', 'label' => 'ojos' ),
				array( 'emoji' => '🗣️', 'label' => 'hablando' ), array( 'emoji' => '💬', 'label' => 'burbuja de dialogo' ),
			),
			'Animales y naturaleza' => array(
				array( 'emoji' => '🐲', 'label' => 'dragon' ), array( 'emoji' => '🐉', 'label' => 'dragon chino' ),
				array( 'emoji' => '🦖', 'label' => 'dinosaurio' ), array( 'emoji' => '🐺', 'label' => 'lobo' ),
				array( 'emoji' => '🦊', 'label' => 'zorro' ), array( 'emoji' => '🐱', 'label' => 'gato' ),
				array( 'emoji' => '🐶', 'label' => 'perro' ), array( 'emoji' => '🦁', 'label' => 'leon' ),
				array( 'emoji' => '🐯', 'label' => 'tigre' ), array( 'emoji' => '🐼', 'label' => 'panda' ),
				array( 'emoji' => '🐧', 'label' => 'pinguino' ), array( 'emoji' => '🦅', 'label' => 'aguila' ),
				array( 'emoji' => '🦉', 'label' => 'buho' ), array( 'emoji' => '🐢', 'label' => 'tortuga' ),
				array( 'emoji' => '🐳', 'label' => 'ballena' ), array( 'emoji' => '🦋', 'label' => 'mariposa' ),
				array( 'emoji' => '🌵', 'label' => 'cactus' ), array( 'emoji' => '🌴', 'label' => 'palmera' ),
				array( 'emoji' => '🌸', 'label' => 'flor' ), array( 'emoji' => '🌺', 'label' => 'hibisco' ),
				array( 'emoji' => '🍀', 'label' => 'trébol' ), array( 'emoji' => '🌍', 'label' => 'tierra' ),
				array( 'emoji' => '🔥', 'label' => 'fuego' ), array( 'emoji' => '⚡', 'label' => 'rayo' ),
				array( 'emoji' => '🌧️', 'label' => 'lluvia' ), array( 'emoji' => '❄️', 'label' => 'nieve' ),
				array( 'emoji' => '☀️', 'label' => 'sol' ), array( 'emoji' => '🌙', 'label' => 'luna' ),
				array( 'emoji' => '⭐', 'label' => 'estrella' ), array( 'emoji' => '🌈', 'label' => 'arcoiris' ),
			),
			'Objetos' => array(
				array( 'emoji' => '🚀', 'label' => 'cohete' ), array( 'emoji' => '🛠️', 'label' => 'herramientas' ),
				array( 'emoji' => '⚙️', 'label' => 'engranaje' ), array( 'emoji' => '🧰', 'label' => 'caja de herramientas' ),
				array( 'emoji' => '💻', 'label' => 'computadora' ), array( 'emoji' => '🖥️', 'label' => 'monitor' ),
				array( 'emoji' => '📱', 'label' => 'telefono' ), array( 'emoji' => '🧠', 'label' => 'cerebro' ),
				array( 'emoji' => '💡', 'label' => 'bombilla' ), array( 'emoji' => '🔧', 'label' => 'llave inglesa' ),
				array( 'emoji' => '🔩', 'label' => 'tornillo' ), array( 'emoji' => '🧩', 'label' => 'pieza de puzzle' ),
				array( 'emoji' => '📦', 'label' => 'paquete' ), array( 'emoji' => '📊', 'label' => 'grafico de barras' ),
				array( 'emoji' => '📈', 'label' => 'grafico ascendente' ), array( 'emoji' => '📉', 'label' => 'grafico descendente' ),
				array( 'emoji' => '🗂️', 'label' => 'archivador' ), array( 'emoji' => '📁', 'label' => 'carpeta' ),
				array( 'emoji' => '📎', 'label' => 'clip' ), array( 'emoji' => '🔑', 'label' => 'llave' ),
				array( 'emoji' => '🔒', 'label' => 'candado' ), array( 'emoji' => '🔓', 'label' => 'candado abierto' ),
				array( 'emoji' => '🖇️', 'label' => 'clips' ), array( 'emoji' => '📋', 'label' => 'portapapeles' ),
				array( 'emoji' => '📌', 'label' => 'chincheta' ), array( 'emoji' => '🗑️', 'label' => 'papelera' ),
			),
			'Símbolos' => array(
				array( 'emoji' => '✅', 'label' => 'check' ), array( 'emoji' => '❌', 'label' => 'equis' ),
				array( 'emoji' => '⚠️', 'label' => 'advertencia' ), array( 'emoji' => '🚨', 'label' => 'alerta' ),
				array( 'emoji' => '💯', 'label' => 'cien' ), array( 'emoji' => '🎯', 'label' => 'objetivo' ),
				array( 'emoji' => '🎯', 'label' => 'diana' ), array( 'emoji' => '💼', 'label' => 'maletin' ),
				array( 'emoji' => '🏗️', 'label' => 'construccion' ), array( 'emoji' => '🚧', 'label' => 'obras' ),
				array( 'emoji' => '🔍', 'label' => 'lupa' ), array( 'emoji' => '🔎', 'label' => 'lupa ampliar' ),
				array( 'emoji' => '🔄', 'label' => 'recargar' ), array( 'emoji' => '🔁', 'label' => 'repetir' ),
				array( 'emoji' => '⚡', 'label' => 'energia' ), array( 'emoji' => '💎', 'label' => 'diamante' ),
				array( 'emoji' => '🏆', 'label' => 'trofeo' ), array( 'emoji' => '🥇', 'label' => 'medalla oro' ),
				array( 'emoji' => '🏅', 'label' => 'medalla' ), array( 'emoji' => '📢', 'label' => 'altavoz' ),
				array( 'emoji' => '🔔', 'label' => 'campana' ), array( 'emoji' => '🎉', 'label' => 'celebracion' ),
				array( 'emoji' => '🎊', 'label' => 'confeti' ), array( 'emoji' => '✨', 'label' => 'destello' ),
				array( 'emoji' => '🌟', 'label' => 'brillante' ), array( 'emoji' => '🚦', 'label' => 'semáforo' ),
				array( 'emoji' => '🛡️', 'label' => 'escudo' ), array( 'emoji' => '🧪', 'label' => 'laboratorio' ),
			),
		);
	}

	/** Public accessor for the settings descriptors (used by templates). */
	public static function get_settings(): array
	{
		return array(
			// ── Infraestructura (bus técnico) ──────────────────────────────
			array( 'name' => 'estandar_suite_core_telemetry_settings', 'label' => 'Telemetría', 'desc' => 'Nivel mínimo, retención y habilitación del bus de eventos.', 'editable' => true, 'legacy' => false, 'group' => 'Infraestructura' ),
			array( 'name' => 'estandar_suite_core_secret_key', 'label' => 'Clave AES-256-GCM', 'desc' => 'Clave maestra de cifrado de secretos (generada al instalar).', 'editable' => false, 'legacy' => false, 'group' => 'Infraestructura' ),
			// ── Operación (destinos y exportación) ─────────────────────────
			array( 'name' => 'estandar_suite_core_event_map', 'label' => 'Mapa de eventos → destinos', 'desc' => 'Qué eventos avisan a cada destino.', 'editable' => true, 'legacy' => false, 'group' => 'Operación' ),
			// ── Esquema ────────────────────────────────────────────────────
			array( 'name' => 'estandar_suite_core_schema_version', 'label' => 'Versión de esquema', 'desc' => 'Versión actual de las tablas estandar_core_*.', 'editable' => false, 'legacy' => false, 'group' => 'Esquema' ),
			// ── UI (identidad del menú) ────────────────────────────────────
			array( 'name' => 'estandar_suite_core_menu_icon', 'label' => 'Emoji del menú', 'desc' => 'Emoji que identifica a Estandar Suite en el panel y la barra superior.', 'editable' => true, 'legacy' => false, 'group' => 'UI' ),
		);
	}

	/** Settings inherited from the legacy plugins (read-only bridge). */
	public static function get_legacy_settings(): array
	{
		return array(
			array( 'name' => 'estandar_kickoff_security', 'label' => 'KickOff · Seguridad', 'desc' => 'Login, XML-RPC, headers y rate-limiting del sitio.', 'editable' => false, 'legacy' => true ),
			array( 'name' => 'estandar_kickoff_maintenance', 'label' => 'KickOff · Mantenimiento', 'desc' => 'Modo mantenimiento: mensaje, título e IPs permitidas.', 'editable' => false, 'legacy' => true ),
			array( 'name' => 'estandar_kickoff_modules_enabled', 'label' => 'KickOff · Módulos activos', 'desc' => 'Qué módulos de KickOff están habilitados.', 'editable' => false, 'legacy' => true ),
			array( 'name' => 'estandar_core_admin_bar_items', 'label' => 'Primer · Barra admin', 'desc' => 'Items personalizados de la barra de administración.', 'editable' => false, 'legacy' => true ),
		);
	}

	// ── admin-post handlers (capability + nonce + sanitize) ─────────────────

	public static function handle_add_destination(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_add_destination' );
		$slug  = sanitize_key( (string) ( $_POST['slug'] ?? '' ) );
		$type  = sanitize_key( (string) ( $_POST['type'] ?? 'webhook' ) );
		$url   = esc_url_raw( (string) ( $_POST['target_url'] ?? '' ) );
		if ( $slug !== '' && $url !== '' ) {
			Destinations::register_destination( $slug, $type, $url );
			Telemetry::emit( 'info', 'CORE_DESTINATION_ADDED', 'Destination registered.', array( 'source_plugin' => 'estandar-core', 'slug' => $slug ) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite&dest_saved=1' ) );
		exit;
	}

	public static function handle_delete_destination(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_delete_destination' );
		Destinations::delete( (int) ( $_POST['destination_id'] ?? 0 ) );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite&dest_del=1' ) );
		exit;
	}

	public static function handle_create_secret(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_create_secret' );
		$payload = sanitize_textarea_field( (string) ( $_POST['payload'] ?? '' ) );
		$purpose = sanitize_key( (string) ( $_POST['purpose'] ?? 'share' ) );
		$ttl     = isset( $_POST['ttl'] ) ? max( 60, min( 2592000, (int) $_POST['ttl'] ) ) : 86400;
		$created = Secrets::create( $payload, $purpose, $ttl );
		if ( ! empty( $created['token'] ) ) {
			$created['link'] = Secrets::consume_link( $created['token'] );
			set_transient( 'estandar_suite_last_secret_' . get_current_user_id(), $created, 600 );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite&tab=secretos&secret_ok=1' ) );
		exit;
	}

	/** Test a destination connection (immediate probe POST). */
	public static function handle_test_destination(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_test_destination' );
		$id = (int) ( $_POST['destination_id'] ?? 0 );
		$r  = Destinations::test( $id );
		$args = array( 'page' => 'estandar-suite', 'tab' => 'destinos', 'test_id' => $id );
		if ( $r['ok'] ) {
			$args['test_ok'] = '1';
			$args['test_http'] = (int) $r['http'];
		} else {
			$args['test_http'] = (int) $r['http'];
			$args['test_err']  = rawurlencode( (string) $r['error'] );
		}
		wp_safe_redirect( admin_url( 'admin.php?' . build_query( $args ) ) );
		exit;
	}

	/** Save event→destination mapping for one destination. */
	public static function handle_save_event_map(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_save_event_map' );
		$slug = sanitize_key( (string) ( $_POST['slug'] ?? '' ) );
		$events = array_map( 'sanitize_key', (array) ( $_POST['events'] ?? array() ) );
		if ( $slug !== '' ) {
			Destinations::map_events( $slug, $events );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite&tab=modulos&map_saved=1' ) );
		exit;
	}

	/** Save the telemetry settings (Operación tab). */
	public static function handle_save_telemetry_settings(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_save_telemetry_settings' );
		Telemetry::save_settings( is_array( $_POST ) ? $_POST : array() );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite&tab=infra&telemetry_saved=1' ) );
		exit;
	}

	/** Save the menu emoji (UI group). */
	public static function handle_save_menu_icon(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_save_menu_icon' );
		$custom = trim( (string) ( $_POST['menu_icon_custom'] ?? '' ) );
		$emoji  = $custom !== '' ? $custom : trim( (string) ( $_POST['menu_icon'] ?? '' ) );
		if ( $emoji === '' ) {
			$emoji = '🐲';
		}
		// Keep only a single emoji-ish token (allow multi-codepoint emoji).
		$emoji = mb_substr( $emoji, 0, 8 );
		update_option( 'estandar_suite_core_menu_icon', $emoji, false );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite&tab=config&icon_saved=1' ) );
		exit;
	}
}
