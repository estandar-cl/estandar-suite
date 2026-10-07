<?php
/**
 * PixelManager — inyección de píxeles de tracking en el frontend (v2).
 *
 * Restaura el comportamiento que la cadena legacy tenía y se perdió al retirar
 * estandar-leads: el Meta/Facebook Pixel (fbq) y la implementación de Google
 * (gtag.js: Google Analytics 4 + Google Ads conversion) se cargaban desde
 * `estandar_leads_meta_pixel_id` y las opciones de ad legacy. La opción quedó
 * seteada en producción pero NINGÚN código v2 la consumía → el píxel dejó de
 * correr.
 *
 * Lee la opción `estandar_suite_capture_leads_settings[integraciones]` (Admin →
 * Leads → Integraciones) con fallback a las opciones legacy, y emite los
 * snippets en wp_head. Meta Pixel: dispara si `meta_pixel_id` no está vacío.
 * Google: dispara gtag.js si hay `google_ga4_id` (GA4) y/o `google_enabled` +
 * `google_conversion_action_id` (Google Ads).
 *
 * @package Estandar\Capture
 */

namespace Estandar\Capture;

class PixelManager
{
	const PIXEL_KEY = 'estandar_suite_capture_leads_settings';

	/**
	 * GA4_LEAD_EVENT_V1: evento GA4 de conversión (key event) que se dispara al
	 * confirmarse un envío. Configurable en Leads → Integraciones (`ga4_lead_event`);
	 * este es solo el default cuando el ajuste está vacío. Debe coincidir con el
	 * key event creado en la propiedad GA4.
	 */
	const DEFAULT_GA4_LEAD_EVENT = 'generate_lead';

	/** Hook en wp_head (frontend, fuera de admin/AJAX). */
	public static function init(): void
	{
		add_action( 'wp_head', array( __CLASS__, 'inject_pixels' ), 99 );
	}

	/** Opción de leads settings (array con grupos, incluido 'integraciones'). */
	public static function settings(): array
	{
		$s = get_option( self::PIXEL_KEY, array() );
		return is_array( $s ) ? $s : array();
	}

	/** Emite los snippets de píxeles si hay IDs configurados. */
	public static function inject_pixels(): void
	{
		if ( is_admin() || wp_doing_ajax() || wp_is_json_request() || function_exists( 'wp_doing_rest' ) && wp_doing_rest() ) {
			return;
		}
		$settings = self::settings();
		$ig       = (array) ( $settings['integraciones'] ?? array() );
		// FEATURE_GROUP_NESTED_V1: se acepta la estructura anidada canónica
		// (`['integraciones'][key]`) y también la plana legacy (`[key]`), para que
		// el píxel configurado desde el panel o la CLI (que pueden escribir plano)
		// SÍ se cargue. El valor anidado tiene prioridad.
		$igv = static function ( string $key ) use ( $ig, $settings ) {
			if ( array_key_exists( $key, $ig ) && '' !== (string) $ig[ $key ] ) {
				return $ig[ $key ];
			}
			return $settings[ $key ] ?? '';
		};

		$meta_pixel_id = trim( (string) $igv( 'meta_pixel_id' ) );
		if ( $meta_pixel_id === '' ) {
			$meta_pixel_id = trim( (string) get_option( 'estandar_leads_meta_pixel_id', '' ) );
		}
		if ( $meta_pixel_id !== '' ) {
			echo self::meta_pixel_snippet( $meta_pixel_id ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo self::wa_click_script(); // phpcs:ignore WordPress.Security.EscapeOutput
		}

		$ga4_id    = trim( (string) $igv( 'google_ga4_id' ) );
		$ga4_event = trim( (string) $igv( 'ga4_lead_event' ) );
		if ( $ga4_event === '' ) {
			$ga4_event = self::DEFAULT_GA4_LEAD_EVENT; // configurable; default generate_lead.
		}
		$ads_on    = ! empty( $igv( 'google_enabled' ) );
		$ads_id    = trim( (string) $igv( 'google_conversion_action_id' ) );
		$ads_full  = $ads_on && $ads_id !== '' ? $ads_id : '';
		if ( $ga4_id !== '' || $ads_full !== '' ) {
			echo self::gtag_snippet( $ga4_id, $ads_full ); // phpcs:ignore WordPress.Security.EscapeOutput
		}

		// Eventos de conversión al enviar un form v2 (éxito o reenvío): ayuda a
		// Meta/Google a seguir aprendiendo (Lead / GA4 key event / Ads conversion).
		if ( $meta_pixel_id !== '' || $ga4_id !== '' || $ads_full !== '' ) {
			echo self::conversion_script( $ads_full, $ga4_id !== '' ? $ga4_event : '' ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	/** Snippet base del Meta/Facebook Pixel. */
	public static function meta_pixel_snippet( string $pixel_id ): string
	{
		$id = esc_js( $pixel_id );
		return '<!-- Estandar: Meta Pixel ' . esc_html( $pixel_id ) . " -->\n"
			. '<script>' . "\n"
			. "!function(f,b,e,v,n,t,s)\n{if(f.fbq)return;n=f.fbq=function(){n.callMethod?\n"
			. 'n.callMethod.apply(n,arguments):n.queue.push(arguments)};' . "\n"
			. "if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';\n"
			. "n.queue=[];t=b.createElement(e);t.async=!0;\n"
			. "t.src=v;s=b.getElementsByTagName(e)[0];\n"
			. "s.parentNode.insertBefore(t,s)}(window, document,'script',\n"
			. "'https://connect.facebook.net/en_US/fbevents.js');\n"
			. "fbq('init', '{$id}');\n"
			. "fbq('track', 'PageView');\n"
			. "</script>\n";
	}

	/**
	 * Snippet base de gtag.js. Emite GA4 si hay Measurement ID y/o el tag de
	 * Google Ads si hay conversion action ID (AW-…). El base se imprime una vez.
	 */
	public static function gtag_snippet( string $ga4_id, string $ads_conversion_id ): string
	{
		$out  = '<!-- Estandar: Google gtag.js -->' . "\n";
		$out .= '<script async src="https://www.googletagmanager.com/gtag/js?id=' . esc_js( $ga4_id ) . '"></script>' . "\n";
		$out .= '<script>' . "\n"
			. 'window.dataLayer = window.dataLayer || [];' . "\n"
			. 'function gtag(){dataLayer.push(arguments);}' . "\n"
			. "gtag('js', new Date());\n";
		if ( $ga4_id !== '' ) {
			$out .= "gtag('config', '" . esc_js( $ga4_id ) . "');\n";
		}
		if ( $ads_conversion_id !== '' ) {
			$out .= "gtag('config', 'AW-" . esc_js( $ads_conversion_id ) . "');\n";
		}
		$out .= "</script>\n";
		return $out;
	}

	/**
	 * Evento de conversión al confirmarse un envío de form v2. Dispara
	 * `fbq('track','Lead')` (Meta) y/o `gtag('event','conversion',…)` (Google
	 * Ads) SOLO cuando la página se renderiza como resultado de un envío real
	 * (vista de éxito `estandar-form-v2-done`).
	 *
	 * IMPORTANTE (CONVERSION_ONCE_V1): NO se dispara con los estados de
	 * reingreso (`es2-already-sent` / `es2-project-done-block`) porque esos
	 * divs se imprimen en CADA carga de página y generaban un `Lead` falso en
	 * cada visita (sobreconteo ~5-8x en Meta; el objetivo "Leads" optimizaba
	 * así por visitas, no por envíos).
	 */
	public static function conversion_script( string $ads_conversion_id = '', string $ga4_event = '' ): string
	{
		$fire = "try{if(window.fbq){fbq('track','Lead');}}catch(e){}";
		if ( $ga4_event !== '' ) {
			$fire .= "try{if(window.gtag){gtag('event'," . wp_json_encode( $ga4_event ) . ");}}catch(e){}";
		}
		if ( $ads_conversion_id !== '' ) {
			$fire .= "try{if(window.gtag){gtag('event','conversion',{send_to:" . wp_json_encode( 'AW-' . $ads_conversion_id ) . "});}}catch(e){}";
		}
		$out  = '<!-- Estandar: conversión por form -->' . "\n";
		$out .= '<script>(function(){try{'
			. 'var fire=function(){'
			. $fire
			. '};'
			. 'var detect=function(){'
			. 'var el=document.querySelector(".estandar-form-v2-done:not(.es2-project-done-block):not(.es2-already-sent)");'
			. 'if(el&&!window.__estandarCvFired){window.__estandarCvFired=1;fire();}'
			. '};'
			. 'if(document.readyState==="complete"||document.readyState==="interactive"){detect();}'
			. 'else{document.addEventListener("DOMContentLoaded",detect);}'
			. 'window.addEventListener("load",function(){setTimeout(detect,300);});'
			. '}catch(e){}})();</script>' . "\n";
		return $out;
	}

	/**
	 * WA_CLICK_LEAD_V1 (2026-09-14): un clic deliberado a WhatsApp desde
	 * cualquier enlace del sitio (wa.me / api.whatsapp.com / go/whatsapp) es una
	 * señal de conversión real y, en inmobiliaria, el canal principal. Este
	 * script:
	 *   - dispara `fbq('track','Lead', {content_category:'whatsapp'})` UNA vez por
	 *     carga de página. Antes el píxel solo disparaba `Lead` en la vista de
	 *     éxito del form (que casi nunca ocurría) → la campaña quedaba en
	 *     "aprendizaje limitado" sin señal;
	 *   - registra el clic (best-effort) en /wa/click y emite `whatsapp_click` a
	 *     dataLayer;
	 *   - se coordina con el botón flotante por el flag `__esWaTracked` para no
	 *     duplicar el registro.
	 */
	public static function wa_click_script(): string
	{
		$endpoint = esc_url_raw( rest_url( 'estandar-suite/capture/v1/wa/click' ) );
		$ep       = wp_json_encode( $endpoint );
		return '<!-- Estandar: WhatsApp click -> Lead -->' . "\n"
			. '<script>(function(){try{'
			. 'var ep=' . $ep . ';var lead=false;'
			. 'function ids(){var o={};try{var q=new URLSearchParams(location.search),'
			. 'ks=["gclid","gbraid","wbraid","fbclid","ttclid"];'
			. 'ks.forEach(function(k){var v=q.get(k)||"";'
			. 'if(!v){try{v=localStorage.getItem("ea_"+k)||localStorage.getItem("estandar_"+k)||"";}catch(e){}}'
			. 'if(v){o[k]=v;}});}catch(e){}return o;}'
			. 'function dev(){try{return /Mobi|Android|iPhone|iPad/i.test(navigator.userAgent)?"mobile":"desktop";}catch(e){return "unknown";}}'
			. 'function sid(){try{for(var i=0;i<localStorage.length;i++){var k=localStorage.key(i);'
			. 'if(k&&k.indexOf("es_fv2_sid_")===0){var v=localStorage.getItem(k)||"";if(v){return v;}}}}catch(e){}return "";}'
			. 'function rec(a){var c=ids();var p={page:location.pathname,device:dev(),'
			. 'ua:(navigator.userAgent||"").slice(0,250),session:sid()};'
			. 'try{var u=new URL(a.href);var t=u.searchParams.get("text")||"";if(t){p.message=t.slice(0,500);}}catch(e){}'
			. 'Object.keys(c).forEach(function(k){p[k]=c[k];});'
			. 'try{if(navigator.sendBeacon&&ep){navigator.sendBeacon(ep,JSON.stringify(p));}'
			. 'else if(ep){fetch(ep,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(p),keepalive:true});}}catch(e){}'
			. 'try{if(window.fbq&&!lead){lead=true;fbq("track","Lead",{content_category:"whatsapp"});}}catch(e){}'
			. 'try{if(window.dataLayer){window.dataLayer.push({event:"whatsapp_click",page_path:location.pathname,device:dev()});}}catch(e){}'
			. '}'
			. 'document.addEventListener("click",function(e){try{'
			. 'var a=e.target&&e.target.closest?e.target.closest(\'a[href*="wa.me/"],a[href*="api.whatsapp.com/send"],a[href*="go/whatsapp"]\'):null;'
			. 'if(!a)return;if(a.__esWaTracked)return;a.__esWaTracked=true;rec(a);'
			. '}catch(err){}},true);'
			. '}catch(e){}})();</script>' . "\n";
	}
}
