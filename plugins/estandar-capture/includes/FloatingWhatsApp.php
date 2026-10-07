<?php
/**
 * Estandar Suite — Capture · FloatingWhatsApp
 *
 * Botón flotante de WhatsApp (barra inferior fija en responsive y desktop),
 * con el mismo diseño visual del sitio del cliente (barra completa abajo) pero
 * con número y mensaje CONFIGURABLES POR PROYECTO/RAMA desde el BACKEND
 * (admin del sitio, tab WhatsApp → sección «Botón flotante») o por filtros.
 *
 * Precedencia de resolución del número/mensaje:
 *   1) Regla de página (path o slug) configurada en backend
 *   2) Rama activa (?branch= / slug de sucursal vía Landing::branches)
 *   3) Número global (settings de backend)
 *
 * Opt-in estricto: sin habilitación no se imprime nada (cero impacto).
 * Configuración:
 *   - Backend: option `estandar_suite_capture_floating_whatsapp_settings`
 *     (array guardable desde el admin; ver save_settings()).
 *   - Filtros (override opcional, si un tema/snippet quiere forzar):
 *     estandar_suite_capture_floating_whatsapp_enabled (bool)
 *     estandar_suite_capture_floating_whatsapp_number (E.164 dígitos)
 *     estandar_suite_capture_floating_whatsapp_message
 *     estandar_suite_capture_floating_whatsapp_selectors (array)
 *     estandar_suite_capture_floating_whatsapp_padding_selector (string)
 *     estandar_suite_capture_floating_whatsapp_page_rules (array de reglas)
 *     estandar_suite_capture_floating_whatsapp_hide_paths (array de paths
 *       donde el widget NO se muestra, p.ej. landing dedicada con form propio)
 *     estandar_suite_capture_floating_whatsapp_show_desktop (bool)
 *     estandar_suite_capture_floating_whatsapp_show_mobile (bool)
 *
 * Compatibilidad: la option legacy `estandar_suite_capture_floating_whatsapp`
 * ('1'/'yes') sigue habilitando el widget.
 *
 * Aditivo: no rompe nada desplegado; si el JS falla, la página sigue normal
 * (try/catch). No muta Google Ads.
 */

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FloatingWhatsApp
{
	/** Option legacy ('1'/'yes' = habilitado) — compatibilidad. */
	const OPTION = 'estandar_suite_capture_floating_whatsapp';
	/** Option de settings guardable desde el admin (array). */
	const SETTINGS_OPTION = 'estandar_suite_capture_floating_whatsapp_settings';

	public static function defaults(): array
	{
		return array(
			'enabled'          => 0,
			'number'           => '',
			'message'          => 'Hola, llegué desde su sitio web y me gustaría conversar.',
			'show_desktop'     => 1,
			'show_mobile'      => 1,
			'open_new_tab'     => 1,
			'track_clicks'     => 0,
			'reserve_space'    => 1,        // reserva hueco en <body> para no tapar el footer
			'selectors'        => '',       // texto: un selector por línea
			'padding_selector' => '',
			'rules'            => array(),  // array de reglas por página
			'hide_paths'       => '',       // texto: un path por línea (widget oculto ahí)
		);
	}

	public static function settings(): array
	{
		$saved = get_option( self::SETTINGS_OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		return array_merge( self::defaults(), $saved );
	}

	public static function save_settings( array $raw ): void
	{
		$d     = self::defaults();
		$saved = get_option( self::SETTINGS_OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();

		$saved['enabled']          = ! empty( $raw['enabled'] ) ? 1 : 0;
		$saved['number']           = preg_replace( '/\D/', '', (string) ( $raw['number'] ?? '' ) );
		$saved['message']          = sanitize_text_field( (string) ( $raw['message'] ?? $d['message'] ) );
		$saved['show_desktop']     = ! empty( $raw['show_desktop'] ) ? 1 : 0;
		$saved['show_mobile']      = ! empty( $raw['show_mobile'] ) ? 1 : 0;
		$saved['open_new_tab']     = ! empty( $raw['open_new_tab'] ) ? 1 : 0;
		$saved['track_clicks']     = ! empty( $raw['track_clicks'] ) ? 1 : 0;
		$saved['reserve_space']    = ! empty( $raw['reserve_space'] ) ? 1 : 0;
		$saved['selectors']        = sanitize_textarea_field( (string) ( $raw['selectors'] ?? '' ) );
		$saved['padding_selector'] = sanitize_text_field( (string) ( $raw['padding_selector'] ?? '' ) );
		$saved['hide_paths']       = sanitize_textarea_field( (string) ( $raw['hide_paths'] ?? '' ) );

		// Reglas: filas [match, path, slug, number, message].
		$rules = array();
		$raw_rules = ( $raw['rules'] ?? array() );
		if ( is_array( $raw_rules ) ) {
			foreach ( $raw_rules as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$match  = sanitize_key( (string) ( $row['match'] ?? 'path' ) );
				$number = preg_replace( '/\D/', '', (string) ( $row['number'] ?? '' ) );
				if ( $number === '' ) {
					continue;
				}
				$rules[] = array(
					'match'   => ( $match === 'slug' ) ? 'slug' : 'path',
					'path'    => sanitize_text_field( (string) ( $row['path'] ?? '' ) ),
					'slug'    => sanitize_key( (string) ( $row['slug'] ?? '' ) ),
					'number'  => $number,
					'message' => sanitize_text_field( (string) ( $row['message'] ?? '' ) ),
				);
			}
		}
		$saved['rules'] = $rules;

		update_option( self::SETTINGS_OPTION, $saved, false );
	}

	public static function init(): void
	{
		if ( ! self::enabled() ) {
			return;
		}
		add_action( 'wp_footer', array( __CLASS__, 'print_widget' ), 19 );
	}

	/** Opt-in: settings de backend (enabled) o option legacy o filter. */
	public static function enabled(): bool
	{
		$settings = self::settings();
		$from_settings = ! empty( $settings['enabled'] );
		$legacy = (string) get_option( self::OPTION, '' );
		$from_legacy = ( $legacy === '1' || strtolower( $legacy ) === 'yes' );
		return (bool) apply_filters( 'estandar_suite_capture_floating_whatsapp_enabled', $from_settings || $from_legacy );
	}

	/** Número global (E.164 dígitos) — settings o filter. */
	public static function number(): string
	{
		$settings = self::settings();
		$num = (string) ( $settings['number'] ?? '' );
		$num = (string) apply_filters( 'estandar_suite_capture_floating_whatsapp_number', $num );
		return preg_replace( '/\D/', '', $num );
	}

	/** Mensaje global — settings o filter. */
	public static function message(): string
	{
		$settings = self::settings();
		$msg = (string) ( $settings['message'] ?? self::defaults()['message'] );
		return (string) apply_filters( 'estandar_suite_capture_floating_whatsapp_message', $msg );
	}

	/** ¿Mostrar en desktop? (≥782px). */
	public static function show_desktop(): bool
	{
		$settings = self::settings();
		return (bool) apply_filters( 'estandar_suite_capture_floating_whatsapp_show_desktop', ! empty( $settings['show_desktop'] ) );
	}

	/** ¿Mostrar en móvil? (<782px). */
	public static function show_mobile(): bool
	{
		$settings = self::settings();
		return (bool) apply_filters( 'estandar_suite_capture_floating_whatsapp_show_mobile', ! empty( $settings['show_mobile'] ) );
	}

	/** ¿Abrir WhatsApp en una pestaña nueva? (default ON). */
	public static function open_new_tab(): bool
	{
		$settings = self::settings();
		return (bool) apply_filters( 'estandar_suite_capture_floating_whatsapp_open_new_tab', ! empty( $settings['open_new_tab'] ) );
	}

	/** ¿Registrar el click al botón de WhatsApp (tabla + dataLayer)? */
	public static function track_clicks(): bool
	{
		$settings = self::settings();
		return (bool) apply_filters( 'estandar_suite_capture_floating_whatsapp_track_clicks', ! empty( $settings['track_clicks'] ) );
	}

	/**
	 * ¿Reservar espacio en <body> con la altura real del botón?
	 *
	 * Evita que la barra fija tape el final del contenido (p. ej. las
	 * direcciones del footer) en móvil. A diferencia de `padding_selector`,
	 * se aplica al propio <body> y se calcula dinámicamente con la altura
	 * medida del widget, así que funciona aunque el footer quede fuera del
	 * contenedor configurado. Solo actúa donde el widget se muestra.
	 */
	public static function reserve_space(): bool
	{
		$settings = self::settings();
		return (bool) apply_filters( 'estandar_suite_capture_floating_whatsapp_reserve_space', ! empty( $settings['reserve_space'] ) );
	}

	/** Selectores de barras flotantes del tema que este widget oculta. */
	public static function selectors(): array
	{
		$settings = self::settings();
		$raw = (string) ( $settings['selectors'] ?? '' );
		$arr = array();
		foreach ( preg_split( '/[\r\n]+/', $raw ) as $line ) {
			$line = trim( $line );
			if ( $line !== '' ) {
				$arr[] = $line;
			}
		}
		return (array) apply_filters( 'estandar_suite_capture_floating_whatsapp_selectors', $arr );
	}

	/** Selector del contenedor al que se añade padding-bottom. */
	public static function padding_selector(): string
	{
		$settings = self::settings();
		return (string) apply_filters( 'estandar_suite_capture_floating_whatsapp_padding_selector', (string) ( $settings['padding_selector'] ?? '' ) );
	}

	/**
	 * Paths donde el widget NO debe mostrarse (texto, un path por línea) o filter.
	 * P. ej. una landing dedicada que usa su propio form como única vía de
	 * contacto: ahí se oculta la barra de WhatsApp para no dividir la conversión.
	 */
	public static function hide_paths(): array
	{
		$settings = self::settings();
		$raw      = (string) ( $settings['hide_paths'] ?? '' );
		$arr      = array();
		foreach ( preg_split( '/[\r\n]+/', $raw ) as $line ) {
			$line = trim( $line );
			if ( $line !== '' ) {
				$arr[] = $line;
			}
		}
		return (array) apply_filters( 'estandar_suite_capture_floating_whatsapp_hide_paths', $arr );
	}

	/** ¿El widget está oculto en la página actual (por hide_paths)? */
	public static function is_hidden_on_current(): bool
	{
		$paths = self::hide_paths();
		if ( empty( $paths ) ) {
			return false;
		}
		$path = self::current_path();
		foreach ( $paths as $hp ) {
			$hp = trim( (string) $hp );
			if ( $hp === '' ) {
				continue;
			}
			$norm = '/' . trim( $hp, '/' ) . '/';
			if ( $norm === '//' ) {
				continue; // evitar ocultar todo el sitio con un "/" suelto
			}
			if ( $path === $norm || strpos( $path, $norm ) === 0 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Paths crudos (sin merge de defaults) para edición no destructiva.
	 * Evita persistir defaults cuando se añade/quita un path vía CLI.
	 */
	public static function raw_hide_paths(): array
	{
		$saved = get_option( self::SETTINGS_OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$raw   = (string) ( $saved['hide_paths'] ?? '' );
		$arr   = array();
		foreach ( preg_split( '/[\r\n]+/', $raw ) as $line ) {
			$line = trim( $line );
			if ( $line !== '' ) {
				$arr[] = $line;
			}
		}
		return $arr;
	}

	/** Normaliza un path de ocultamiento (p. ej. "/temuco_inicio" → "/temuco_inicio/"). */
	public static function normalize_hide_path( string $path ): string
	{
		$path = trim( (string) $path );
		if ( $path === '' || $path === '/' ) {
			return '';
		}
		return '/' . trim( $path, '/' ) . '/';
	}

	/** Añade un path a hide_paths (persiste, no destructivo). Devuelve los paths finales. */
	public static function add_hide_path( string $path ): array
	{
		$norm = self::normalize_hide_path( $path );
		if ( $norm === '' ) {
			return self::raw_hide_paths();
		}
		$paths   = self::raw_hide_paths();
		$exists  = false;
		foreach ( $paths as $hp ) {
			if ( self::normalize_hide_path( $hp ) === $norm ) {
				$exists = true;
				break;
			}
		}
		if ( ! $exists ) {
			$paths[] = $norm;
			self::persist_hide_paths( $paths );
		}
		return $paths;
	}

	/** Quita un path de hide_paths (persiste, no destructivo). Devuelve los paths finales. */
	public static function remove_hide_path( string $path ): array
	{
		$norm  = self::normalize_hide_path( $path );
		$paths = self::raw_hide_paths();
		$out   = array();
		foreach ( $paths as $hp ) {
			if ( $norm !== '' && self::normalize_hide_path( $hp ) === $norm ) {
				continue;
			}
			$out[] = $hp;
		}
		if ( count( $out ) !== count( $paths ) ) {
			self::persist_hide_paths( $out );
		}
		return $out;
	}

	/** Persiste solo hide_paths conservando el resto de la option (no destructivo). */
	public static function persist_hide_paths( array $paths ): void
	{
		$saved = get_option( self::SETTINGS_OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$saved['hide_paths'] = implode( "\n", $paths );
		update_option( self::SETTINGS_OPTION, $saved, false );
	}

	/**
	 * Reglas por PÁGINA/SLUG (número y mensaje por página) — settings o filter.
	 * Precedencia de resolución: regla de página → rama activa → global.
	 */
	public static function page_rules(): array
	{
		$settings = self::settings();
		return (array) apply_filters( 'estandar_suite_capture_floating_whatsapp_page_rules', (array) ( $settings['rules'] ?? array() ) );
	}

	/** Pathname actual (sin query, con trailing slash normalizado). */
	public static function current_path(): string
	{
		$uri = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
		$path = (string) parse_url( $uri, PHP_URL_PATH );
		if ( $path === '' || $path === '/' ) {
			return '/';
		}
		return '/' . trim( $path, '/' ) . '/';
	}

	/** Regla de página que matchea la página actual, o null. */
	public static function page_rule_for_current(): ?array
	{
		$rules = self::page_rules();
		if ( empty( $rules ) ) {
			return null;
		}
		$path = self::current_path();
		$obj  = get_queried_object();
		$slug = ( is_object( $obj ) && isset( $obj->post_name ) )
			? sanitize_key( (string) $obj->post_name ) : '';
		foreach ( $rules as $rule ) {
			$match = (string) ( $rule['match'] ?? 'path' );
			if ( $match === 'slug' ) {
				$rule_slug = sanitize_key( (string) ( $rule['slug'] ?? '' ) );
				if ( $rule_slug !== '' && $rule_slug === $slug ) {
					return $rule;
				}
				continue;
			}
			$rule_path = (string) ( $rule['path'] ?? '' );
			if ( $rule_path === '' ) {
				continue;
			}
			$norm = '/' . trim( $rule_path, '/' ) . '/';
			if ( $path === $norm || strpos( $path, $norm ) === 0 ) {
				return $rule;
			}
		}
		return null;
	}

	/**
	 * Número/mensaje resueltos para la página actual.
	 * Precedencia: regla de página → rama activa → número global.
	 * Devuelve array [number, message].
	 */
	public static function resolved_values(): array
	{
		$number  = self::number();
		$message = self::message();

		$rule = self::page_rule_for_current();
		if ( $rule ) {
			$rule_number = preg_replace( '/\D/', '', (string) ( $rule['number'] ?? '' ) );
			$rule_msg    = (string) ( $rule['message'] ?? '' );
			if ( $rule_number !== '' ) {
				$number = $rule_number;
			}
			if ( $rule_msg !== '' ) {
				$message = $rule_msg;
			}
			return array( $number, $message );
		}

		if ( class_exists( __NAMESPACE__ . '\\Landing' ) ) {
			$branch = Landing::current_branch();
			if ( $branch !== '' ) {
				$b = Landing::branch( $branch );
				$branch_number = preg_replace( '/\D/', '', (string) ( $b['whatsapp'] ?? '' ) );
				$branch_text   = (string) ( $b['wa_text'] ?? '' );
				if ( $branch_number !== '' ) {
					$number = $branch_number;
				}
				if ( $branch_text !== '' ) {
					$message = $branch_text;
				}
				return array( $number, $message );
			}
		}

		return array( $number, $message );
	}

	/**
	 * Slug de ENTRADA resuelto para la página actual (etiqueta de origen).
	 * Precedencia espejo de resolved_values(): regla de página → rama → 'global'.
	 *
	 * Sirve para etiquetar el click en `estandar_wa_clicks.slug` y reconocer
	 * la fuente del WhatsApp (campaña/landing/branch) aunque el contacto esté
	 * guardado por nombre en el WhatsApp del cliente (teléfono oculto).
	 * Devuelve el slug de la regla si existe; si la regla es por path sin slug,
	 * deriva el slug del basename del path.
	 */
	public static function resolved_slug(): string
	{
		$rule = self::page_rule_for_current();
		if ( $rule ) {
			$slug = sanitize_key( (string) ( $rule['slug'] ?? '' ) );
			if ( $slug !== '' ) {
				return $slug;
			}
			$path = (string) ( $rule['path'] ?? '' );
			if ( $path !== '' ) {
				$base = trim( (string) parse_url( $path, PHP_URL_PATH ), '/' );
				if ( $base !== '' ) {
					$derived = sanitize_key( basename( $base ) );
					if ( $derived !== '' ) {
						return $derived;
					}
				}
			}
			return 'page';
		}
		if ( class_exists( __NAMESPACE__ . '\\Landing' ) ) {
			$branch = Landing::current_branch();
			if ( $branch !== '' ) {
				return sanitize_key( $branch );
			}
		}
		return 'global';
	}

	/** Número/mensaje resueltos (alias de resolved_values, compatibilidad). */
	public static function branch_values(): array
	{
		return self::resolved_values();
	}

	public static function print_widget(): void
	{
		if ( self::is_hidden_on_current() ) {
			return;
		}
		list( $number, $message ) = self::resolved_values();
		if ( $number === '' ) {
			return;
		}
		$show_desktop = self::show_desktop();
		$show_mobile  = self::show_mobile();
		if ( ! $show_desktop && ! $show_mobile ) {
			return;
		}
		$selectors  = wp_json_encode( self::selectors() );
		$padding_el = wp_json_encode( self::padding_selector() );
		$url        = 'https://wa.me/' . $number . '?text=' . rawurlencode( $message );
		$aria       = esc_attr( 'Conversar por WhatsApp (' . self::display_number( $number ) . ')' );
		$cls_d      = $show_desktop ? 'es-wa-d' : '';
		$cls_m      = $show_mobile ? 'es-wa-m' : '';
		$track      = self::track_clicks();
		$reserve    = self::reserve_space() ? '1' : '0';
		$click_endpoint = esc_url_raw( rest_url( WhatsAppClickTracking::NS . '/wa/click' ) );
		$click_flag     = $track ? '1' : '0';
		$wa_slug    = esc_attr( self::resolved_slug() );
		$wa_msg     = esc_attr( $message );
		$target_attr    = self::open_new_tab() ? ' target="_blank" rel="noopener nofollow"' : '';

		$widget = <<<HTML
<div id="estandar-wa-floating" class="estandar-wa-floating {$cls_d} {$cls_m}" data-wa-track="{$click_flag}" data-wa-reserve="{$reserve}" data-wa-endpoint="{$click_endpoint}" data-wa-slug="{$wa_slug}" data-wa-message="{$wa_msg}">
	<a href="{$url}"{$target_attr}
	   class="estandar-wa-floating-btn" aria-label="{$aria}">
		<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51l-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.71.306 1.263.489 1.694.625.712.227 1.36.195 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347M12.05 21.785h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.98 1-3.65a9.79 9.79 0 01-1.553-5.266C2.36 8.09 6.7 3.75 12.052 3.75c2.53 0 4.91.986 6.7 2.776a9.39 9.39 0 012.777 6.69c0 2.53-.986 4.909-2.777 6.7a9.42 9.42 0 01-6.7 2.869z"/></svg>
		<span>WhatsApp</span>
	</a>
</div>
<script>
(function () {
	try {
		var root = document.getElementById('estandar-wa-floating');
		var selectors = {$selectors};
		var paddingEl = {$padding_el};
		var reserve = root && root.getAttribute('data-wa-reserve') === '1';
		function reserveSpace() {
			// Reserva en <body> la ALTURA REAL del botón (medida en vivo) para
			// que la barra fija no tape el final del contenido (footer, avisos
			// legales...) en móvil. Si el widget no se muestra en este viewport
			// su altura es 0 y no se reserva nada. Viewport-aware: al girar o
			// redimensionar se recalcula. No pisa un padding-bottom inline del
			// tema: usa una custom property + clase en <html>.
			try {
				if (!root || !reserve) { return; }
				var h = root.getBoundingClientRect ? root.getBoundingClientRect().height : 0;
				var doc = document.documentElement;
				if (h > 0) {
					doc.style.setProperty('--es-wa-h', Math.ceil(h) + 'px');
					doc.classList.add('es-wa-reserve');
				} else {
					doc.classList.remove('es-wa-reserve');
					doc.style.removeProperty('--es-wa-h');
				}
			} catch (e) {}
		}
		function apply() {
			try {
				if (selectors && selectors.length) {
					document.querySelectorAll(selectors.join(', ')).forEach(function (el) { el.style.display = 'none'; });
				}
				if (paddingEl) {
					var wrap = document.querySelector(paddingEl);
					if (wrap) {
						var hh = root && root.getBoundingClientRect ? Math.ceil(root.getBoundingClientRect().height) : 0;
						wrap.style.paddingBottom = (hh > 0 ? hh : 76) + 'px';
					}
				}
				reserveSpace();
			} catch (e) {}
		}
		apply();
		setTimeout(apply, 800);
		setTimeout(apply, 2500);
		window.addEventListener('resize', reserveSpace);
		window.addEventListener('orientationchange', reserveSpace);
		new MutationObserver(apply).observe(document.documentElement, { childList: true, subtree: true });
	} catch (e) {}
})();
</script>
<style>html.es-wa-reserve body{padding-bottom:var(--es-wa-h,76px) !important}</style>
<script>
(function () {
	try {
		var root = document.getElementById('estandar-wa-floating');
		if (!root) { return; }
		var track = root.getAttribute('data-wa-track') === '1';
		if (!track) { return; }
		var endpoint = root.getAttribute('data-wa-endpoint') || '';
		function clickIds() {
			var out = {};
			try {
				var url = new URLSearchParams(window.location.search || '');
				['gclid', 'gbraid', 'wbraid', 'fbclid', 'ttclid'].forEach(function (k) {
					var v = url.get(k) || '';
					if (!v) {
						try { var l = window.localStorage.getItem('ea_' + k) || window.localStorage.getItem('estandar_' + k) || ''; v = l || ''; } catch (e) {}
					}
					if (v) { out[k] = v; }
				});
			} catch (e) {}
			return out;
		}
		function device() {
			try {
				return /Mobi|Android|iPhone|iPad/i.test(navigator.userAgent) ? 'mobile' : 'desktop';
			} catch (e) { return 'unknown'; }
		}
		function sessionId() {
			// Misma llave de sesión que usan los forms (es_fv2_sid_<form>):
			// permite cruzar el click de WhatsApp con una captura de la misma
			// sesión → backfill de atribución GCLID en URL limpia.
			try {
				for (var i = 0; i < window.localStorage.length; i++) {
					var k = window.localStorage.key(i);
					if (k && k.indexOf('es_fv2_sid_') === 0) {
						var v = window.localStorage.getItem(k) || '';
						if (v) { return v; }
					}
				}
			} catch (e) {}
			return '';
		}
		function anchorInfo(a) {
			// Etiqueta de entrada + prefill del clic. En el botón flotante se
			// leen los data attrs resueltos en servidor (slug + mensaje por
			// página/rama). En un enlace wa.me suelto se extrae el ?text= del
			// propio enlace (mensaje que AK preconfiguró para esa entrada).
			var meta = { slug: '', message: '' };
			try {
				if (a && root && root.contains(a)) {
					meta.slug = (root.getAttribute('data-wa-slug') || '').slice(0, 60);
					meta.message = (root.getAttribute('data-wa-message') || '').slice(0, 500);
				} else if (a) {
					var u = new URL(a.href);
					meta.message = (u.searchParams.get('text') || '').slice(0, 500);
					meta.slug = 'link';
				}
			} catch (e) {}
			return meta;
		}
		function record(a) {
			var ids = clickIds();
			var meta = anchorInfo(a);
			var payload = {
				page: window.location.pathname,
				device: device(),
				ua: navigator.userAgent ? navigator.userAgent.slice(0, 250) : '',
				session: sessionId(),
				slug: meta.slug,
				message: meta.message
			};
			Object.keys(ids).forEach(function (k) { payload[k] = ids[k]; });
			try {
				if (navigator.sendBeacon && endpoint) {
					navigator.sendBeacon(endpoint, JSON.stringify(payload));
				} else if (endpoint) {
					fetch(endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
				}
			} catch (e) {}
			// Evento para GTM/GA4 → puede convertirse en conversión en Google Ads.
			try {
				if (window.dataLayer) {
					var ev = { event: 'whatsapp_click', page_path: window.location.pathname, device: device(), slug: meta.slug };
					if (meta.message) { ev.wa_message = meta.message.slice(0, 200); }
					Object.keys(ids).forEach(function (k) { ev[k] = ids[k]; });
					window.dataLayer.push(ev);
				}
			} catch (e) {}
		}
		document.addEventListener('click', function (e) {
			try {
				var a = e.target && e.target.closest ? e.target.closest('a[href*="wa.me/"], a[href*="api.whatsapp.com/send"], a[href*="go/whatsapp"]') : null;
				if (a) { if (a.__esWaTracked) { return; } a.__esWaTracked = true; record(a); }
			} catch (err) {}
		});
	} catch (e) {}
})();
</script>
<style>
.estandar-wa-floating{display:none}
@media(max-width:781px){
.estandar-wa-floating.es-wa-m{display:block;position:fixed;left:0;right:0;bottom:0;z-index:9998;padding:8px 12px calc(8px + env(safe-area-inset-bottom,0px));background:rgba(255,255,255,.96);box-shadow:0 -4px 16px rgba(0,0,0,.12)}
}
@media(min-width:782px){
.estandar-wa-floating.es-wa-d{display:block;position:fixed;left:0;right:0;bottom:0;z-index:9998;padding:8px 12px calc(8px + env(safe-area-inset-bottom,0px));background:rgba(255,255,255,.96);box-shadow:0 -4px 16px rgba(0,0,0,.12)}
.estandar-wa-floating.es-wa-d .estandar-wa-floating-btn{max-width:480px;margin:0 auto}
}
.estandar-wa-floating-btn{display:flex;align-items:center;justify-content:center;gap:9px;width:100%;min-height:52px;background:var(--ak-accent, #A3CB27);color:#0e1a05;font-weight:800;font-size:16px;text-decoration:none;border-radius:var(--ak-radius-sm, 8px)}
.estandar-wa-floating-btn svg{flex:0 0 auto}
</style>
HTML;

		echo $widget; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML con esc_attr en atributos y valores de configuración del sitio
	}

	/** +56 9 4290 0458 (formato de display, solo lectura). */
	private static function display_number( string $digits ): string
	{
		$digits = preg_replace( '/\D/', '', $digits );
		if ( strlen( $digits ) === 11 && strpos( $digits, '56' ) === 0 ) {
			$digits = substr( $digits, 2 );
		}
		if ( strlen( $digits ) === 9 ) {
			return '+56 9 ' . substr( $digits, 1, 4 ) . ' ' . substr( $digits, 5 );
		}
		return '+56 ' . $digits;
	}
}
