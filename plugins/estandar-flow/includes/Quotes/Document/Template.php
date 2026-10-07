<?php

namespace Estandar\Flow\Quotes\Document;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Template — base visual del documento de cotización.
 *
 * Un único HTML+CSS (subconjunto SEGURO para dompdf) que sirve para el canvas
 * editable en pantalla y para el PDF: sin flexbox/grid, sin `position:absolute`,
 * sin `@import` ni fuentes externas. Los colores y la tipografía del emisor se
 * inyectan dinámicamente desde `$issuer` con fallback seguro.
 *
 * Layout: página letter con `@page` (márgenes en cm) y contenedor `.esq-page`
 * a `width:100%` + `box-sizing:border-box` para NUNCA desbordar los márgenes.
 */
class Template
{
	/** Ancho de la página letter portrait (in). */
	const PAGE_WIDTH = '8.5in';

	/** Alto de la página letter portrait (in). */
	const PAGE_HEIGHT = '11in';

	/**
	 * CSS base estático (subconjunto seguro dompdf).
	 *
	 * Reglas de layout: solo tablas y anchos en %, sin flex/grid, sin
	 * `position:absolute`, imágenes con `max-width:100%`. `overflow-wrap` evita
	 * que cadenas largas (emails/URLs) rompan el ancho.
	 */
	public static function css(): string
	{
		return '
			@page { size: letter portrait; margin: 1.4cm 1.5cm 2.2cm 1.5cm; }
			.esq-page, .q-doc { color: #1a1a1a; font-size: 9.5pt; line-height: 1.35; padding-bottom: 0.4cm; }

			/* Encabezado + branding (empresa arriba, proyecto justo debajo). */
			.esq-header { width: 100%; border-collapse: collapse; margin: 0 0 10px; }
			.esq-header td { vertical-align: top; padding: 0; }
			.esq-brand-cell { width: 55%; padding-right: 10px; }
			.esq-meta-cell  { width: 45%; text-align: right; }
			/* Banda superior dedicada al branding (altura reservada, ~4.2cm). */
			.esq-band { min-height: 4.2cm; }
			.esq-brand { margin: 0 0 4px; line-height: 1.15; }
			.esq-brand-header { max-width: 100%; max-height: 2.4cm; height: auto; display: block; margin: 0 0 4px; }
			.esq-brand-logo { max-width: 100%; max-height: 52px; height: auto; margin: 0 0 3px; }
			.esq-brand-company { padding-bottom: 4px; }
			.esq-brand-project { padding-top: 4px; border-top: 1px solid #d9d9d9; }
			.esq-brand-name { font-size: 13pt; font-weight: bold; letter-spacing: 0.4px; }
			.esq-brand-company .esq-brand-name { font-size: 15pt; }
			.esq-brand-project .esq-brand-name { font-size: 12pt; }
			.esq-brand-slug { font-size: 8pt; color: #6b6b6b; text-transform: uppercase; letter-spacing: 0.6px; }

			.esq-meta { width: 100%; border-collapse: collapse; }
			.esq-meta td { text-align: right; padding: 1px 0; font-size: 9.5pt; color: #1a1a1a; }
			.esq-meta-line { white-space: nowrap; }
			.esq-meta-label { font-weight: bold; }

			/* Texto del overlay del banner SIEMPRE oscuro (legible sobre la imagen). */
			.esq-banner-overlay { color: #1a1a1a; }

			/* Títulos de sección (mayúsculas, subrayado fino). */
			.esq-section { margin: 0 0 12px; }
			.esq-title { font-size: 11pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.6px; margin: 0 0 6px; padding-bottom: 3px; border-bottom: 1px solid #c9c9c9; }
			.esq-sub { font-size: 9.5pt; margin: 0 0 6px; }

			/* Rejilla de datos (sin bordes): cliente, proyecto, sala de venta. */
			.esq-grid { width: 100%; border-collapse: collapse; }
			.esq-grid td { vertical-align: top; padding: 2px 8px 2px 0; font-size: 9.5pt; overflow-wrap: break-word; word-wrap: break-word; }
			.esq-grid .esq-lbl { font-weight: bold; white-space: nowrap; }

			/* Tablas con borde: inversión, financiamiento, ítems. */
			.esq-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
			.esq-table th, .esq-table td { padding: 4px 6px; font-size: 9.5pt; vertical-align: top; overflow-wrap: break-word; word-wrap: break-word; }
			.esq-table th { text-align: left; border-bottom: 1px solid #1a1a1a; font-weight: bold; }
			.esq-table td { border-bottom: 1px solid #e2e2e2; }
			.esq-table .esq-total td { font-weight: bold; border-top: 1px solid #1a1a1a; border-bottom: 0; }
			.esq-table th.esq-right, .esq-table td.esq-right { text-align: right; }
			.esq-right { text-align: right; }
			.esq-center { text-align: center; }

			.esq-nota { margin: 0 0 6px; font-size: 9.5pt; }
			/* Caja de Nota + condiciones: fondo 6% negro, SIN línea de contorno. */
			.esq-cond-box { background-color: #f0f0f0; border: 0; padding: 8pt 10pt; }
			.esq-cond { margin: 0; padding: 0; }
			.esq-cond-item { margin: 0 0 4px; font-size: 9.5pt; }
			.esq-fin-line { margin: 0 0 8px; font-size: 9.5pt; }

			.esq-images { width: 100%; border-collapse: collapse; }
			.esq-images td { width: 48%; padding: 4px; vertical-align: top; }
			.esq-image { max-width: 100%; max-height: 5.8cm; height: auto; }
			.esq-image-caption { font-size: 8.5pt; color: #6b6b6b; margin: 2px 0 0; }

			/* Pie fijo en TODAS las páginas (dompdf repite position:fixed). */
			.esq-footer-fixed { position: fixed; bottom: 0; left: 0; right: 0; text-align: center; font-size: 8.5pt; color: #6b6b6b; }
			.esq-footer-img { max-width: 45%; max-height: 1.1cm; height: auto; }

			/* Plantilla 1: fondo e imagen inferior a página completa (full-bleed).
			   dompdf repite los `position:fixed` en todas las páginas. El ancho/alto
			   de página se fija inline (letter) solo cuando hay plantilla, para no
			   alterar el documento por defecto. */
			.esq-bg-fixed { position: fixed; top: 0; left: 0; z-index: -1; }
			.esq-bottom-fixed { position: fixed; bottom: 0; left: 0; right: 0; width: 100%; z-index: -1; }

			.esq-sign { margin-top: 30px; width: 100%; border-collapse: collapse; }
			.esq-sign td { vertical-align: top; padding: 24px 0 0; font-size: 9.5pt; }
			.esq-sign-line { border-top: 1px solid #1a1a1a; width: 48%; padding-top: 4px; }
			.esq-avoid-break { page-break-inside: avoid; }
			.esq-accent { color: #b34700; }
			.esq-muted { color: #6b6b6b; }

			/* Layout genérico heredado (compatibilidad). */
			.q-header { border-bottom: 2px solid #e2e4e7; padding-bottom: 10px; margin-bottom: 14px; }
			.q-logo { max-width: 100%; max-height: 60px; height: auto; margin-bottom: 8px; }
			.q-title { font-size: 20px; margin: 0 0 6px; }
			.q-meta { width: 100%; border-collapse: collapse; margin: 6px 0; }
			.q-meta td { padding: 2px 8px 2px 0; font-size: 11px; }
			.q-label { color: #646970; font-weight: bold; }
			.q-company { color: #646970; font-size: 11px; }
			.q-section { margin-top: 16px; }
			.q-section-title { font-size: 12px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.4px; border-bottom: 1px solid #e2e4e7; padding-bottom: 4px; margin: 0 0 8px; }
			.q-table { width: 100%; border-collapse: collapse; }
			.q-table th, .q-table td { border: 1px solid #e2e4e7; padding: 6px 8px; font-size: 11px; vertical-align: top; overflow-wrap: break-word; word-wrap: break-word; }
			.q-table th { background: #f6f7f7; text-align: left; }
			.q-center { text-align: center; }
			.q-total-label { text-align: right; font-weight: bold; }
			.q-cond { margin: 0; padding-left: 18px; }
			.q-cond li { margin-bottom: 4px; }
			.q-muted { color: #646970; font-size: 11px; }
			.q-sign { margin-top: 34px; width: 100%; border-collapse: collapse; }
			.q-sign td { vertical-align: top; padding-top: 26px; font-size: 11px; }
			.q-sign-line { border-top: 1px solid #1d2327; width: 220px; padding-top: 4px; }
			.q-accent { color: #2271b1; }
			.q-accent-bg { background: #2271b1; color: #ffffff; }
			.q-primary { color: #1d2327; }
		';
	}

	/**
	 * Abre el contenedor del documento (letter + tipografía/colores del emisor).
	 *
	 * Si la plantilla trae `background_url`/`bottom_url`, se emiten al inicio del
	 * body como imágenes `position:fixed` (dompdf las repite en TODAS las páginas)
	 * y la página pasa a `margin:0`: el fondo queda de borde a borde y el respiro
	 * del texto lo aporta el `padding` del contenedor. Sin plantilla, el layout es
	 * EXACTAMENTE el de antes (`@page` con márgenes en cm).
	 *
	 * @param array<string,mixed> $template     Plantilla resuelta (`id`,`name`,`background_url`,`bottom_url`).
	 * @param string              $banner_html  Banda de encabezado full-bleed (imagen + textos
	 *                                          superpuestos). Si no está vacía, la página pasa a
	 *                                          `margin:0` y la banda se emite a ancho completo,
	 *                                          FUERA del padding del contenido (solo la 1.ª página).
	 */
	public static function page_open( array $issuer, array $template = array(), string $banner_html = '' ): string
	{
		$font    = self::font( $issuer );
		$primary = self::color( $issuer, 'primary_color', '#1d2327' );
		$accent  = self::color( $issuer, 'accent_color', '#2271b1' );

		$dynamic = '.esq-accent { color: ' . $accent . '; }'
			. '.esq-accent-bg { background: ' . $accent . '; color: #ffffff; }'
			. '.esq-primary { color: ' . $primary . '; }'
			. '.q-accent { color: ' . $accent . '; }'
			. '.q-accent-bg { background: ' . $accent . '; color: #ffffff; }'
			. '.q-primary { color: ' . $primary . '; }'
			. '.q-title { color: ' . $accent . '; }';

		$background = trim( (string) ( $template['background_url'] ?? '' ) );
		$bottom     = trim( (string) ( $template['bottom_url'] ?? '' ) );
		$bleed      = ( '' !== trim( $banner_html ) );
		$fullbleed  = ( '' !== $background || '' !== $bottom || $bleed );

		$page_css = '';
		$overlay  = '';
		if ( $fullbleed ) {
			// Página sin márgenes: el fondo llega al borde y el texto respira por padding.
			// El pie de texto (si sobrevive) se separa 0.4cm del borde inferior real.
			$page_css = '@page { margin: 0; }' . '.esq-footer-fixed { bottom: 0.4cm; }';

			// Fondo primero (queda detrás); imagen inferior después (encima del fondo).
			if ( '' !== $background ) {
				$overlay .= '<img class="esq-bg-fixed" src="' . esc_url( $background ) . '" alt=""'
					. ' style="position:fixed;top:0;left:0;width:' . self::PAGE_WIDTH . ';height:' . self::PAGE_HEIGHT . ';z-index:-1;">';
			}
			if ( '' !== $bottom ) {
				$overlay .= '<img class="esq-bottom-fixed" src="' . esc_url( $bottom ) . '" alt=""'
					. ' style="position:fixed;bottom:0;left:0;right:0;width:100%;height:auto;z-index:-1;">';
			}
		}

		if ( $fullbleed ) {
			// `width:auto` (no `100%`): el bloque descuenta el padding de los 8.5in
			// de la página, de modo que el texto respira sin desbordar por la derecha
			// (dompdf no resta el padding de un `width:100%`).
			$container_style = 'font-family:' . esc_attr( $font ) . ';width:auto;max-width:100%;margin:0 auto;box-sizing:border-box;overflow-wrap:break-word;padding:1.4cm 1.5cm 2.0cm;';
		} else {
			$container_style = 'font-family:' . esc_attr( $font ) . ';width:100%;max-width:100%;margin:0 auto;box-sizing:border-box;overflow-wrap:break-word;';
		}

		return '<style>' . self::css() . $dynamic . $page_css . '</style>'
			. $overlay
			. $banner_html
			. '<div class="esq-page q-doc" style="' . $container_style . '">';
	}

	/** Cierra el contenedor del documento. */
	public static function page_close(): string
	{
		return '</div>';
	}

	/** Tipografía segura del emisor (fallback Helvetica/Arial). */
	public static function font( array $issuer ): string
	{
		$font = (string) ( $issuer['font_family'] ?? '' );
		$font = trim( strip_tags( $font ) );
		if ( '' === $font || ! preg_match( '/^[A-Za-z0-9 ,\-_\'"]+$/', $font ) ) {
			return 'Helvetica,Arial,sans-serif';
		}
		return $font;
	}

	/** Color hex del emisor con fallback validado (evita inyección CSS). */
	public static function color( array $issuer, string $key, string $fallback ): string
	{
		$value = (string) ( $issuer[ $key ] ?? '' );
		if ( preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value ) ) {
			return $value;
		}
		return $fallback;
	}

	/**
	 * Valida un color hex recibido suelto (p. ej. `branding.company.color`),
	 * devolviendo `''` si no es un hex seguro. Sirve para estilos inline.
	 */
	public static function safe_color( $value, string $fallback = '' ): string
	{
		$value = (string) $value;
		if ( preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value ) ) {
			return $value;
		}
		return $fallback;
	}

	/** Fecha en formato largo español (`6 de Junio de 2026`). Pasa-through si no es Y-m-d. */
	public static function date_es( string $value ): string
	{
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $value, $m ) ) {
			return $value;
		}
		$months = array(
			1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
			'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre',
		);
		$month = (int) $m[2];
		if ( $month < 1 || $month > 12 ) {
			return $value;
		}
		return (int) $m[3] . ' de ' . $months[ $month ] . ' de ' . (int) $m[1];
	}
}
