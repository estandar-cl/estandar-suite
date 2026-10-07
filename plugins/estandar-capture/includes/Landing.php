<?php
/**
 * Estandar Suite — Capture · Landing (página de destino por sucursal)
 *
 * Landing diferenciada por ciudad/sucursal: hero con mensaje local, botón de
 * WhatsApp con el número de la sucursal y formulario de captura con la ciudad
 * preseleccionada (y contexto de rama). Pensado para que las campañas PMax
 * regionales (p. ej. "WhatsappMAX Temuco") apunten a una página coherente con
 * el anuncio → menos rebote, leads de empresas de la zona.
 *
 * Uso (shortcode):
 *   [estandar_capture_landing city="temuco"]
 *   [estandar_capture_landing city="temuco" form="<id_form>"]
 *
 * La rama define: nombre, ciudad/región, dirección, WhatsApp, headline,
 * subheadline y texto del botón. El número de WhatsApp es configurable por
 * rama (filtro `estandar_suite_capture_landing_branches`).
 *
 * El form embebido usa `[estandar_form_v2 preset_city=temuco]` (FormRenderer
 * preselecciona la ciudad y agrega contexto de rama a la captura).
 */

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Landing
{
	const SHORTCODE = 'estandar_capture_landing';

	/** Rama por defecto (si el shortcode no indica city). */
	const DEFAULT_BRANCH = 'temuco';

	public static function init(): void
	{
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render' ) );
		// Swap del número de WhatsApp por rama (?branch=temuco → número de la
		// sucursal) sobre una página existente, sin página duplicada.
		add_action( 'wp_footer', array( __CLASS__, 'print_branch_wa_swap' ), 21 );
		// Banner "AHORA EN <CIUDAD>" cuando la URL trae ?branch= (misma landing
		// del home, con la sucursal en grande). Aditivo: solo corre con ?branch=.
		add_action( 'wp_body_open', array( __CLASS__, 'print_branch_banner' ), 5 );
	}

	/**
	 * Rama activa: por ?branch=<rama> o por el slug de la página actual
	 * (una página copiada de la landing con slug de la sucursal, p. ej.
	 * /temuco/ o /temuco_inicio/). Devuelve el key de la rama o ''.
	 */
	public static function current_branch(): string
	{
		$branch = sanitize_key( (string) ( $_GET['branch'] ?? '' ) );
		$branches = self::branches();
		if ( $branch !== '' && isset( $branches[ $branch ] ) ) {
			return $branch;
		}
		// Slug de la página: match exacto o prefijo "rama_" / "rama-"
		// (temuco_inicio → temuco).
		$obj = get_queried_object();
		$slug = ( $obj instanceof \WP_Post ) ? sanitize_key( (string) $obj->post_name ) : '';
		if ( $slug !== '' ) {
			if ( isset( $branches[ $slug ] ) ) {
				return $slug;
			}
			foreach ( array_keys( $branches ) as $key ) {
				if ( strpos( $slug, $key . '_' ) === 0 || strpos( $slug, $key . '-' ) === 0 ) {
					return $key;
				}
			}
		}
		return '';
	}

	/**
	 * Banner superior "AHORA EN <CIUDAD>" (grande) cuando la página es de una
	 * sucursal (?branch=<rama> o slug de rama). La landing es una COPIA de la
	 * del home; solo se añade la sucursal en grande.
	 */
	public static function print_branch_banner(): void
	{
		$branch = self::current_branch();
		if ( $branch === '' ) {
			return;
		}
		$b = self::branch( $branch );
		$city = (string) ( $b['city'] ?? '' );
		if ( $city === '' ) {
			return;
		}
		$sub = (string) ( $b['address'] ?? '' ) . ' · ' . (string) ( $b['region'] ?? '' );
		$html = '<div class="estandar-branch-banner" style="background:#0b3d2e;color:#fff;text-align:center;padding:14px 16px;font-family:inherit;">'
			. '<span style="font-weight:800;font-size:26px;letter-spacing:1px;text-transform:uppercase;color:#ffd76a;">Ahora en ' . esc_html( $city ) . '</span>'
			. '<span style="display:block;font-size:13px;opacity:.9;margin-top:4px;">' . esc_html( $sub ) . '</span>'
			. '</div>';
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_html en los valores
	}

	/**
	 * Cuando la URL trae ?branch=<rama>, reescribe los enlaces wa.me /
	 * api.whatsapp.com de la página al número de esa sucursal. Aditivo: si la
	 * rama no existe o no hay botones de WhatsApp, no hace nada.
	 */
	public static function print_branch_wa_swap(): void
	{
		$branch = self::current_branch();
		if ( $branch === '' ) {
			return;
		}
		$b = self::branch( $branch );
		$number = preg_replace( '/\D/', '', (string) ( $b['whatsapp'] ?? '' ) );
		$email  = sanitize_email( (string) ( $b['email'] ?? '' ) );
		if ( $number === '' && $email === '' ) {
			return;
		}
		// Selectores de secciones a ocultar y de barras fijas de WhatsApp:
		// configurados POR SITIO (filtros), no hardcodeados en el plugin.
		$hide_js    = wp_json_encode( self::hide_selectors() );
		$sticky_js  = wp_json_encode( self::sticky_whatsapp_selectors() );
		$from_js    = wp_json_encode( self::default_whatsapp() );
		$number_js  = $number !== '' ? "var num='{$number}';" : "var num='';";
		$email_js   = $email !== '' ? "var mail='{$email}';" : "var mail='';";
		$script = <<<JS
<script>
(function () {
	try {
		{$number_js}
		{$email_js}
		var hideSel = {$hide_js};
		var stickySel = {$sticky_js};
		var fromNum = {$from_js};
		function applySwaps() {
			try {
				// Limpieza configurable: ocultar secciones redundantes con el form.
				hideSel.forEach(function (sel) {
					document.querySelectorAll(sel).forEach(function (el) { el.style.display = 'none'; });
				});
				// Reapuntar CTA roto al form y corregir links WhatsApp/correo.
				document.querySelectorAll('a[href*="wa.me/"], a[href*="api.whatsapp.com/send"], a[href^="mailto:"], a[href*="go/whatsapp"]').forEach(function (a) {
					try {
						var u = new URL(a.href, window.location.origin);
						if (u.hostname === 'wa.me' && num) {
							var parts = u.pathname.split('/');
							if (parts.length >= 2 && parts[1]) { parts[1] = num; u.pathname = parts.join('/'); }
						} else if (u.hostname === 'api.whatsapp.com' && u.searchParams.get('phone') && num) {
							u.searchParams.set('phone', num);
						} else if (u.protocol === 'mailto:' && mail) {
							u.pathname = mail;
						} else if (u.pathname.indexOf('/go/whatsapp') !== -1) {
							a.href = '#estandar-form';
						}
						a.href = u.toString();
					} catch (e) {}
				});
				// Barras fijas de WhatsApp (configurable): corregir número en href o texto.
				if (num && stickySel.length) {
					document.querySelectorAll(stickySel.join(', ')).forEach(function (el) {
						try {
							var a = el.tagName === 'A' ? el : el.querySelector('a[href*="wa.me/"], a[href*="api.whatsapp.com/send"]');
							if (a) {
								var u = new URL(a.href, window.location.origin);
								if (u.hostname === 'wa.me') {
									var parts = u.pathname.split('/');
									if (parts.length >= 2 && parts[1]) { parts[1] = num; u.pathname = parts.join('/'); }
								} else if (u.searchParams && u.searchParams.get('phone')) {
									u.searchParams.set('phone', num);
								}
								a.href = u.toString();
							}
							var txt = el.innerText || '';
							if (fromNum && txt.indexOf(fromNum) !== -1) {
								el.innerHTML = el.innerHTML.split(fromNum).join(num);
							}
						} catch (e) {}
					});
				}
			} catch (e) {}
		}
		applySwaps();
		// Re-chequear (los widgets/JS pueden inyectar el botón después).
		setTimeout(applySwaps, 800);
		setTimeout(applySwaps, 2500);
		new MutationObserver(applySwaps).observe(document.documentElement, { childList: true, subtree: true });
	} catch (e) {}
})();
</script>
JS;
		echo $script; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- script con datos escapados (wp_json_encode) y valores de la rama configurada
	}

	/**
	 * Ramas/sucursales (CONFIGURACIÓN POR SITIO, no se hardcodea el cliente).
	 * Cada sitio define sus ramas vía el filtro `estandar_suite_capture_landing_branches`
	 * (p. ej. desde un snippet del sitio: AK define Temuco con su WhatsApp/email).
	 * Estructura por rama:
	 *   name, city, region, address, whatsapp (E.164 dígitos), email, headline,
	 *   subheadline, wa_text.
	 */
	public static function branches(): array
	{
		return (array) apply_filters( 'estandar_suite_capture_landing_branches', array() );
	}

	/**
	 * Selectores CSS de secciones a ocultar en las landings de rama (por sitio
	 * vía filtro). Default vacío → el plugin no conoce las clases de ningún
	 * cliente.
	 */
	public static function hide_selectors(): array
	{
		return (array) apply_filters( 'estandar_suite_capture_landing_hide_selectors', array() );
	}

	/**
	 * Selectores de barras fijas de WhatsApp (móvil) a corregir con el número de
	 * la rama (por sitio vía filtro). Default vacío.
	 */
	public static function sticky_whatsapp_selectors(): array
	{
		return (array) apply_filters( 'estandar_suite_capture_landing_sticky_whatsapp', array() );
	}

	/**
	 * Número WhatsApp "por defecto" del sitio (el que se reemplaza por el de la
	 * rama en el texto visible de las barras). Configurable por sitio vía filtro;
	 * default vacío → no se reemplaza texto, solo hrefs.
	 */
	public static function default_whatsapp(): string
	{
		return preg_replace( '/\D/', '', (string) apply_filters( 'estandar_suite_capture_landing_default_whatsapp', '' ) );
	}

	public static function branch( string $city ): array
	{
		$branches = self::branches();
		$key      = sanitize_key( $city );
		$default  = array(
			'city'        => ucfirst( $key ),
			'name'        => '',
			'region'      => '',
			'address'     => '',
			'whatsapp'    => '',
			'email'       => '',
			'headline'    => '',
			'subheadline' => '',
			'wa_text'     => '',
		);
		if ( ! isset( $branches[ $key ] ) ) {
			return $default;
		}
		return array_merge( $default, $branches[ $key ] );
	}

	public static function render( $atts ): string
	{
		$atts = shortcode_atts( array(
			'city' => self::DEFAULT_BRANCH,
			'form' => 0,
		), (array) $atts, self::SHORTCODE );

		$b = self::branch( (string) $atts['city'] );

		$wa_number = preg_replace( '/\D/', '', (string) $b['whatsapp'] );
		$wa_url    = 'https://wa.me/' . $wa_number . '?text=' . rawurlencode( (string) $b['wa_text'] );

		$form_id = (int) $atts['form'];
		$form    = $form_id > 0
			? do_shortcode( '[estandar_form_v2 id="' . $form_id . '" preset_city="' . esc_attr( $b['city'] ) . '"]' )
			: '';

		// Contexto de rama para la captura (el botón y la URL ya la distinguen).
		$branch_ctx = '<span class="es2-branch" data-branch="' . esc_attr( strtolower( $b['city'] ) ) . '"></span>';

		$html = '<div class="estandar-landing estandar-landing--' . esc_attr( strtolower( $b['city'] ) ) . '">';
		$html .= '<section class="estandar-landing__hero">';
		$html .= '<h1>' . esc_html( $b['headline'] ) . '</h1>';
		$html .= '<p class="estandar-landing__sub">' . esc_html( $b['subheadline'] ) . '</p>';
		$html .= '<p class="estandar-landing__addr">' . esc_html( $b['address'] ) . ' · ' . esc_html( $b['region'] ) . '</p>';
		$html .= '<p class="estandar-landing__contact">Teléfono/WhatsApp: <a href="' . esc_url( $wa_url ) . '">+56 '
			. esc_html( $b['whatsapp'] ) . '</a> · Email: <a href="mailto:' . esc_attr( $b['email'] ) . '">'
			. esc_html( $b['email'] ) . '</a></p>';
		$html .= '<div class="estandar-landing__cta">';
		$html .= '<a class="estandar-landing__wa" href="' . esc_url( $wa_url ) . '" rel="noopener" target="_blank">'
			. 'Escríbenos por WhatsApp en ' . esc_html( $b['city'] ) . '</a>';
		if ( $form_id > 0 ) {
			$html .= '<a class="estandar-landing__form-link" href="#estandar-landing-form">O completa el formulario</a>';
		}
		$html .= '</div></section>';

		if ( $form_id > 0 ) {
			$html .= '<section id="estandar-landing-form" class="estandar-landing__form">' . $form . '</section>';
		}
		$html .= $branch_ctx;
		$html .= '</div>';

		return $html;
	}
}
