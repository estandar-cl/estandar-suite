<?php
/**
 * Estandar Suite — Capture · WhatsAppAttribution
 *
 * Atribución de WhatsApp: enriquece los enlaces de WhatsApp (wa.me /
 * api.whatsapp.com / go/whatsapp) con los click IDs de Google Ads
 * (gclid / gbraid / wbraid) capturados por la atribución del sitio.
 *
 * Por qué: las campañas "WhatsappMAX" generan conversaciones en WhatsApp que
 * hoy son invisibles para Google Ads (el clic paga, pero la conversación
 * ocurre fuera del sitio y sin GCLID ligado). Al embeder el click ID en el
 * mensaje prefabricado de WhatsApp, un bot de lectura (Baileys) puede
 * recuperarlo y reconstruir la atribución → OCI/Enhanced Conversions.
 *
 * Formato de ref (parseable por el bot):  ref:<tipo>:<click_id>
 *   p. ej.  ref:gclid:Cj0KCQ…   ref:gbraid:0A…   ref:wbraid:0B…
 *
 * Regla de atribución determinística: el click ID se lee (en orden) de
 *   1) URL (auto-tagging, lo más fiable)  2) localStorage/cookie de la
 *   atribución (gclid / ea_gclid / estandar_gclid / estandar_attribution_gclid)
 *   3) window.estandarAttribution (last_touch / first_touch).
 *
 * No muta nada en Google Ads ni en el sitio: solo reescribe el href de enlaces
 * de WhatsApp en el cliente. Sin script enriquecido presente → no hace nada.
 */

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WhatsAppAttribution
{
	const SHORTCODE = 'estandar_capture_whatsapp_attribution';

	/** Option que habilita el enriquecimiento (default OFF → cero impacto). */
	const OPTION = 'estandar_suite_capture_whatsapp_attribution';

	public static function init(): void
	{
		// Opt-in estricto: sin habilitación explícita del sitio no se imprime
		// nada. El script es aditivo (solo reescribe enlaces de WhatsApp) y
		// envuelto en try/catch: un fallo de JS no rompe la página.
		if ( ! self::enabled() ) {
			return;
		}
		// El script debe correr tras el DOM y tras la atribución de captura.
		add_action( 'wp_footer', array( __CLASS__, 'print_enrichment_script' ), 20 );
	}

	public static function enabled(): bool
	{
		// Opt-in desde la config de WhatsApp del plugin (pestaña WhatsApp del
		// admin) o desde la option/filtro directo.
		$wa_settings = get_option( 'estandar_suite_capture_whatsapp_settings', array() );
		$from_wa = ! empty( $wa_settings['attribution_enabled'] );
		$option = (string) get_option( self::OPTION, '' );
		$from_option = ( $option === '1' || strtolower( $option ) === 'yes' );
		$enabled = $from_wa || $from_option;
		// Un tema puede habilitarlo por sitio con add_filter(... , '__return_true').
		return (bool) apply_filters( 'estandar_suite_capture_whatsapp_attribution_enabled', $enabled );
	}

	public static function print_enrichment_script(): void
	{
		// Endpoint que registra el click con los click IDs server-side
		// (WhatsAppClickTracking). Con esto la atribución NO viaja en el
		// mensaje visible (ref:…) y el cliente ve solo el texto limpio.
		$endpoint_json = wp_json_encode( esc_url_raw( rest_url( WhatsAppClickTracking::NS . '/wa/click' ) ) );
		// 1. URL params (auto-tagging).
		$script = <<<'JS'
(function () {
	try {
		var FIELDS = ['gclid', 'gbraid', 'wbraid'];
		var url = new URLSearchParams(window.location.search || '');
		var ENDPOINT = __ENDPOINT__;

		function readParam(name) { try { return url.get(name) || ''; } catch (e) { return ''; } }

		function getStored(name) {
			var keys = [name, 'ea_' + name, 'estandar_' + name, 'estandar_attribution_' + name];
			for (var i = 0; i < keys.length; i++) {
				try {
					var c = document.cookie.match(new RegExp('(?:^|; )' + keys[i] + '=([^;]*)'));
					if (c && c[1]) { return decodeURIComponent(c[1]); }
				} catch (e) {}
				try {
					var l = window.localStorage ? window.localStorage.getItem(keys[i]) : '';
					if (l) { return l; }
				} catch (e) {}
			}
			return '';
		}

		function fromEngine(key) {
			try {
				var eng = window.estandarAttribution;
				if (eng) {
					if (eng.last_touch && eng.last_touch[key]) { return eng.last_touch[key]; }
					if (eng.first_touch && eng.first_touch[key]) { return eng.first_touch[key]; }
				}
			} catch (e) {}
			return '';
		}

		function clickIdFor(field) {
			return readParam(field) || getStored(field) || fromEngine(field);
		}

		var ids = {};
		var hasAny = false;
		for (var i = 0; i < FIELDS.length; i++) {
			var v = clickIdFor(FIELDS[i]);
			if (v) { ids[FIELDS[i]] = v; hasAny = true; }
		}
		if (!hasAny) { return; }

		// Atribución SIN contaminar el mensaje: los click IDs se registran
		// server-side (POST /wa/click) y el enlace abre con su texto limpio.
		function recordClick() {
			try {
				var sid = '';
				try {
					for (var i = 0; i < window.localStorage.length; i++) {
						var k = window.localStorage.key(i);
						if (k && k.indexOf('es_fv2_sid_') === 0) { var v = window.localStorage.getItem(k) || ''; if (v) { sid = v; break; } }
					}
				} catch (e) {}
				var payload = { page: window.location.pathname, device: 'unknown', ua: navigator.userAgent || '', session: sid };
				for (var f in ids) { if (Object.prototype.hasOwnProperty.call(ids, f)) { payload[f] = ids[f]; } }
				if (navigator.sendBeacon) {
					navigator.sendBeacon(ENDPOINT, new Blob([JSON.stringify(payload)], { type: 'application/json' }));
				} else {
					fetch(ENDPOINT, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
				}
			} catch (e) {}
		}

		document.querySelectorAll('a[href*="wa.me/"], a[href*="api.whatsapp.com/send"], a[href*="go/whatsapp"]').forEach(function (a) {
			try {
				var href = a.href || '';
				if (!href) { return; }
				// El botón flotante de la Suite ya registra su click (no duplicar).
				if (a.classList && a.classList.contains('estandar-wa-floating-btn')) { return; }
				if (href.indexOf('go/whatsapp') !== -1) {
					// Endpoint de redirect: los click IDs van como query params
					// (server-side, invisibles para el cliente).
					var u = new URL(href, window.location.origin);
					for (var f in ids) { if (Object.prototype.hasOwnProperty.call(ids, f)) { u.searchParams.set(f, ids[f]); } }
					a.href = u.toString();
				} else {
					// wa.me / api.whatsapp.com: NO se toca el text visible.
					a.addEventListener('click', recordClick);
				}
			} catch (e) {}
		});
	} catch (e) {}
})();
JS;
		$script = str_replace( '__ENDPOINT__', $endpoint_json, $script );
		// esc: el script no contiene datos del usuario; imprimir como inline.
		echo "<script>\n" . $script . "\n</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
