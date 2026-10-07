<?php
/**
 * ESTANDAR_ADMIN_UI_V0 — V0.3 Notice filter (denylist, keep-by-default).
 *
 * Hides third-party MARKETING notices (Elegant Themes/Divi, Elementor upsells,
 * Rank Math promos, hosting promos) ONLY on Estandar admin screens.
 *
 * Design contract (ratificado V0):
 *  - KEEP BY DEFAULT. Solo se ocultan bloques que matchean una firma de la denylist.
 *    Cualquier notice desconocido (incluido WordPress core, seguridad, updates,
 *    PHP/runtime, y notices propios de Estandar) se preserva sin tocar.
 *  - Denylist por FUENTE conocida (no por severidad: WP no expone taxonomía de
 *    severidad). Sesgo deliberado: bajo recall, alta precisión — preferimos dejar
 *    pasar un marketing no listado antes que ocultar un aviso legítimo.
 *  - Scoped: solo actúa en pantallas Estandar (heurística por slug, filtrable).
 *  - Reversible: define( 'ESTANDAR_DISABLE_NOTICE_FILTER', true ) lo desactiva.
 *  - Fail-safe: ante cualquier fallo de parseo, devuelve el HTML original intacto.
 *
 * @package estandar-core-mu
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'estandar_core_admin_notice_filter_active' ) ) {
	/**
	 * Whether the current admin screen is an Estandar screen we should filter.
	 */
	function estandar_core_admin_notice_filter_active(): bool {
		if ( defined( 'ESTANDAR_DISABLE_NOTICE_FILTER' ) && ESTANDAR_DISABLE_NOTICE_FILTER ) {
			return false;
		}
		if ( ! is_admin() ) {
			return false;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $page ) {
			return false;
		}

		// ── Detección ROBUSTA (V0.6 P2): membresía exacta en el menú Estandar ──
		// Core registra todas sus páginas bajo ESTANDAR_CORE_MENU_SLUG. Verificar
		// pertenencia al submenú real es exacto y no depende de convenciones de
		// nombres (cubre estandar_campaigns, estandar_projects, ea-*, leads, etc.).
		if ( defined( 'ESTANDAR_CORE_MENU_SLUG' ) ) {
			$parent = ESTANDAR_CORE_MENU_SLUG;
			if ( $page === $parent ) {
				return true;
			}
			global $submenu;
			if ( isset( $submenu[ $parent ] ) && is_array( $submenu[ $parent ] ) ) {
				foreach ( $submenu[ $parent ] as $item ) {
					// índice 2 = menu_slug en la estructura de $submenu de WP.
					if ( isset( $item[2] ) && $page === (string) $item[2] ) {
						return true;
					}
				}
			}
		}

		/**
		 * Fallback por prefijo de slug (red de seguridad si el submenú aún no se
		 * pobló o una página vive fuera del menú Estandar). Pop (ep-) queda fuera.
		 *
		 * @param string[] $patterns
		 * @param string   $page
		 */
		$patterns = apply_filters(
			'estandar_admin_notice_filter_page_patterns',
			array( 'estandar', 'ecb', 'ea-', 'ea_' ),
			$page
		);

		foreach ( (array) $patterns as $prefix ) {
			$prefix = (string) $prefix;
			if ( '' !== $prefix && 0 === strpos( $page, $prefix ) ) {
				return true;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'estandar_core_admin_notice_denylist' ) ) {
	/**
	 * Regex signatures (case-insensitive) of third-party MARKETING notices to hide.
	 * Matched against each top-level notice block's outer HTML.
	 *
	 * @return string[]
	 */
	function estandar_core_admin_notice_denylist(): array {
		$default = array(
			// Elegant Themes / Divi (caso de las capturas: "subscription has expired").
			'/elegant\s*themes/i',
			'/\bet[_-](dashboard|core|epanel)\b/i',
			'/\bepanel\b/i',
			'/divi[_-]?(builder|theme|nag|notice)/i',
			// Elementor upsells (no el plugin en sí, solo promociones).
			'/elementor.{0,120}(go\s*pro|upgrade|connect\s*&\s*activate|subscription|renew|trial)/is',
			// Rank Math marketing.
			'/rank\s*math.{0,120}(pro|upgrade|business|trial|black\s*friday|discount)/is',
			// Yoast premium upsell.
			'/yoast.{0,120}(premium|upgrade|go\s*premium|sale)/is',
			// Hosting / generic upsell banners por clase conocida.
			'/class="[^"]*\b(upsell|upgrade-nag|ppro-notice|promo-notice|plugin-promo)\b/i',
			// "subscription has expired" genérico de bundles de tema (Elegant Themes).
			'/subscription\s+has\s+expired/i',
		);

		/**
		 * @param string[] $signatures
		 */
		return (array) apply_filters( 'estandar_admin_notice_denylist', $default );
	}
}

if ( ! function_exists( 'estandar_core_filter_admin_notice_html' ) ) {
	/**
	 * Remove denylisted notice blocks from a buffered admin-notices HTML string.
	 * KEEP BY DEFAULT: only blocks matching the denylist are removed.
	 *
	 * @param string $html Buffered HTML.
	 * @return string Filtered HTML (original HTML on any parse failure).
	 */
	function estandar_core_filter_admin_notice_html( string $html ): string {
		if ( '' === trim( $html ) ) {
			return $html;
		}

		$deny = estandar_core_admin_notice_denylist();
		if ( empty( $deny ) ) {
			return $html;
		}

		if ( ! class_exists( 'DOMDocument' ) ) {
			return $html; // fail-safe: keep everything.
		}

		$dom = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$loaded   = $dom->loadHTML(
			'<?xml encoding="utf-8"?><div id="estandar-nw-root">' . $html . '</div>',
			defined( 'LIBXML_HTML_NOIMPLIED' ) && defined( 'LIBXML_HTML_NODEFDTD' )
				? LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
				: 0
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return $html; // fail-safe.
		}

		$root = $dom->getElementById( 'estandar-nw-root' );
		if ( ! $root ) {
			return $html; // fail-safe.
		}

		$to_remove = array();
		foreach ( $root->childNodes as $node ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( XML_ELEMENT_NODE !== $node->nodeType ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				continue;
			}
			$outer = (string) $dom->saveHTML( $node );
			foreach ( $deny as $signature ) {
				if ( @preg_match( (string) $signature, $outer ) === 1 ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					$to_remove[] = $node;
					break;
				}
			}
		}

		if ( empty( $to_remove ) ) {
			return $html; // nothing matched — return untouched.
		}

		foreach ( $to_remove as $node ) {
			if ( $node->parentNode ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$node->parentNode->removeChild( $node ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			}
		}

		$out = '';
		foreach ( $root->childNodes as $node ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$out .= (string) $dom->saveHTML( $node );
		}

		return $out;
	}
}

if ( ! function_exists( 'estandar_core_admin_notice_buffer_start' ) ) {
	/**
	 * Start buffering admin notices on Estandar screens.
	 * Spans admin_notices → all_admin_notices (adjacent do_action calls in
	 * wp-admin/admin-header.php), so it captures both hooks' output.
	 */
	function estandar_core_admin_notice_buffer_start(): void {
		if ( ! estandar_core_admin_notice_filter_active() ) {
			return;
		}
		$GLOBALS['estandar_core_notice_buffering'] = true;
		ob_start();
	}
}

if ( ! function_exists( 'estandar_core_admin_notice_buffer_flush' ) ) {
	/**
	 * Flush the buffer, filtering out denylisted marketing notices.
	 */
	function estandar_core_admin_notice_buffer_flush(): void {
		if ( empty( $GLOBALS['estandar_core_notice_buffering'] ) ) {
			return;
		}
		$GLOBALS['estandar_core_notice_buffering'] = false;

		if ( ob_get_level() < 1 ) {
			return;
		}
		$html = (string) ob_get_clean();
		echo estandar_core_filter_admin_notice_html( $html ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

if ( ! function_exists( 'estandar_core_admin_notice_buffer_safety' ) ) {
	/**
	 * Safety net: if the buffer was opened but never flushed (e.g. another plugin
	 * removed all_admin_notices callbacks), flush it RAW so notices are never lost.
	 */
	function estandar_core_admin_notice_buffer_safety(): void {
		if ( empty( $GLOBALS['estandar_core_notice_buffering'] ) ) {
			return;
		}
		$GLOBALS['estandar_core_notice_buffering'] = false;
		if ( ob_get_level() >= 1 ) {
			ob_end_flush();
		}
	}
}

if ( ! function_exists( 'estandar_core_admin_body_class' ) ) {
	/**
	 * ESTANDAR_ADMIN_UI_V0.5 — marca el <body> de pantallas Estandar para que el
	 * CSS pueda scopear compresión de IA (notices compactos, etc.) sin afectar
	 * el resto de wp-admin. Reutiliza la misma detección que el filtro de notices.
	 *
	 * @param string $classes
	 * @return string
	 */
	function estandar_core_admin_body_class( $classes ) {
		if ( estandar_core_admin_notice_filter_active() ) {
			$classes = trim( (string) $classes . ' estandar-admin-screen' );
		}
		return $classes;
	}
}

// Wiring: start as early as possible on admin_notices, flush as late as possible
// on all_admin_notices. Safety flush on admin footer in case the flush never ran.
add_action( 'admin_notices', 'estandar_core_admin_notice_buffer_start', PHP_INT_MIN );
add_action( 'all_admin_notices', 'estandar_core_admin_notice_buffer_flush', PHP_INT_MAX );
add_action( 'in_admin_footer', 'estandar_core_admin_notice_buffer_safety', 0 );
add_filter( 'admin_body_class', 'estandar_core_admin_body_class' );
