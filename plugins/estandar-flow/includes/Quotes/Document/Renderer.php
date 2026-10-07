<?php

namespace Estandar\Flow\Quotes\Document;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renderer — HTML único del documento de cotización (canvas editable y PDF).
 *
 * `render()` produce el HTML con slots editables in-place:
 * `<span data-qfield="grupo.campo" data-qtype="tipo">valor</span>`.
 * `render_print()` produce EXACTAMENTE el mismo HTML/CSS y orden, sin los
 * atributos de edición (apto para dompdf).
 *
 * Dos layouts:
 * - `profile = real_estate@1`: réplica de la cotización inmobiliaria (branding
 *   empresa + proyecto, cliente, proyecto/propiedad, inversión, nota y
 *   condiciones, sala de venta, financiamiento, imágenes y firma).
 * - Resto: layout genérico (`items` + `totals` + condiciones) como fallback.
 *
 * PURE: sin BD/opciones. Tolerante a datos faltantes (nunca fataliza).
 */
class Renderer
{
	/** Perfil inmobiliario que activa la réplica Acacios. */
	const PROFILE_REAL_ESTATE = 'real_estate@1';

	/** HTML editable con slots `data-qfield`. */
	public static function render( array $doc, array $opts = array() ): string
	{
		return self::build( $doc, $opts, true );
	}

	/** Mismo HTML/CSS que `render()` sin chrome de edición (para PDF). */
	public static function render_print( array $doc ): string
	{
		return self::build( $doc, array(), false );
	}

	// ── Composición ──────────────────────────────────────────────────────────

	private static function build( array $doc, array $opts, bool $editable ): string
	{
		$issuer = self::effective_issuer( $doc );

		$profile = (string) self::val( $doc, 'profile', '' );
		if ( self::PROFILE_REAL_ESTATE === $profile ) {
			return self::build_real_estate( $doc, $issuer, $editable );
		}

		$currency = (string) self::val( $doc, 'meta.currency', (string) ( $opts['currency'] ?? 'CLP' ) );
		if ( '' === $currency ) {
			$currency = 'CLP';
		}

		$template = self::template( $doc );

		$html  = Template::page_open( $issuer, $template );
		$html .= self::re_footer( $doc, $template );
		$html .= self::header( $doc, $issuer, $editable );
		$html .= self::client_section( $doc, $editable );
		$html .= self::project_property_section( $doc, $editable );
		$html .= self::items_section( $doc, $currency, $editable );
		$html .= self::totals_section( $doc, $currency, $editable );
		$html .= self::financing_section( $doc, $currency, $editable );
		$html .= self::conditions_section( $doc, $editable );
		$html .= self::sales_room_section( $doc, $editable );
		$html .= self::footer_section( $doc, $editable );
		$html .= Template::page_close();

		return $html;
	}

	/** Composición de la cotización inmobiliaria (réplica de la referencia). */
	private static function build_real_estate( array $doc, array $issuer, bool $editable ): string
	{
		$template = self::template( $doc );

		// Banda de encabezado full-bleed (imagen + textos superpuestos). Si no
		// hay imagen de encabezado, el header queda en flujo como antes.
		$banner = self::re_banner( $doc, $editable );

		$html  = Template::page_open( $issuer, $template, $banner );
		$html .= self::re_footer( $doc, $template );
		if ( '' === $banner ) {
			$html .= self::re_header( $doc, $editable );
		}
		$html .= self::re_client( $doc, $editable );
		$html .= self::re_project_property( $doc, $editable );
		$html .= self::re_investment( $doc, $editable );
		$html .= self::re_notes_conditions( $doc, $editable );
		$html .= self::re_sales_room( $doc, $editable );
		$html .= self::re_financing( $doc, $editable );
		$html .= self::re_images( $doc, $editable );
		$html .= self::re_signature( $doc, $editable );
		$html .= Template::page_close();

		return $html;
	}

	// ── Secciones real_estate ────────────────────────────────────────────────

	/** Encabezado: branding empresa arriba, branding proyecto inmediatamente debajo; meta a la derecha. */
	private static function re_header( array $doc, bool $editable ): string
	{
		$company = self::branding( $doc, 'company' );
		$project = self::branding( $doc, 'project' );

		$brand_company = self::brand_block( $company, $editable, 'branding.company', 'company' );
		$brand_project = self::brand_block( $project, $editable, 'branding.project', 'project' );

		$meta = self::re_meta_table( $doc, $editable );

		return '<table class="esq-header"><tr>'
			. '<td class="esq-brand-cell"><div class="esq-band">' . $brand_company . $brand_project . '</div></td>'
			. '<td class="esq-meta-cell">' . $meta . '</td>'
			. '</tr></table>';
	}

	/**
	 * Banda de encabezado FULL-BLEED (imagen de borde a borde + textos encima).
	 *
	 * Devuelve '' si no hay imagen de encabezado (el header en flujo sigue
	 * mandando). La imagen se coloca absoluta de fondo dentro de un contenedor
	 * `position:relative` a ancho de página completo, y los textos (empresa,
	 * proyecto y meta) van en una capa relativa superior con `z-index`, de modo
	 * que la imagen quede DETRÁS del texto. La altura la define el contenido del
	 * overlay, así el bloque posterior nunca se solapa.
	 */
	private static function re_banner( array $doc, bool $editable ): string
	{
		$url = self::header_image( $doc );
		if ( '' === $url ) {
			return '';
		}

		$company = self::branding( $doc, 'company' );
		$project = self::branding( $doc, 'project' );

		$company_name = trim( (string) ( $company['name'] ?? '' ) );
		if ( '' === $company_name ) {
			$company_name = trim( (string) self::val( $doc, 'issuer.company_name', '' ) );
		}
		$project_name = trim( (string) ( $project['name'] ?? '' ) );
		if ( '' === $project_name ) {
			$project_name = trim( (string) self::val( $doc, 'project.name', '' ) );
		}

		$company_html = '' !== $company_name
			? self::wrap( 'branding.company.name', 'text', esc_html( $company_name ), $editable )
			: '&nbsp;';
		// Con imagen de encabezado los nombres (empresa/proyecto) son redundantes
		// visualmente: se ocultan con `color:transparent`, de modo que el texto
		// SIGUE presente y copiable en la capa de texto del PDF. NO usar
		// `display:none` ni `visibility:hidden` (perderían el texto). El bloque
		// meta continúa visible en negro.
		$company_style = 'font-size:15pt;font-weight:bold;line-height:1.15;letter-spacing:0.4px;color:transparent;';
		$project_style = 'font-size:12pt;font-weight:bold;line-height:1.15;margin-top:4px;color:transparent;';

		$project_html = '' !== $project_name
			? '<div style="' . esc_attr( $project_style ) . '">' . self::wrap( 'branding.project.name', 'text', esc_html( $project_name ), $editable ) . '</div>'
			: '';

		$left = '<div style="' . esc_attr( $company_style ) . '">' . $company_html . '</div>' . $project_html;
		$meta = self::re_meta_table( $doc, $editable );

		$overlay = '<div class="esq-banner-overlay" style="position:absolute;top:0;left:0;right:0;z-index:2;color:#1a1a1a;padding:0.9cm 1.5cm;">'
			. '<table style="width:100%;border-collapse:collapse;"><tr>'
			. '<td style="vertical-align:top;width:55%;padding:0 10px 0 0;">' . $left . '</td>'
			. '<td style="vertical-align:top;width:45%;text-align:right;">' . $meta . '</td>'
			. '</tr></table></div>';

		return '<div class="esq-banner" style="position:relative;width:' . Template::PAGE_WIDTH . ';max-width:100%;margin:0;padding:0;">'
			// Imagen EN FLUJO a ancho completo y alto automático: proporcional (sin
			// deformar), pegada al borde superior e izquierdo/derecho (0 margen).
			. '<img class="esq-banner-img" src="' . esc_url( $url ) . '" alt=""'
			. ' style="display:block;width:100%;height:auto;max-width:100%;margin:0;padding:0;border:0;z-index:1;">'
			. $overlay
			. '</div>';
	}

	/** Imagen de encabezado: proyecto y, si falta, empresa. '' si no hay. */
	private static function header_image( array $doc ): string
	{
		$project = self::branding( $doc, 'project' );
		$url     = trim( (string) ( $project['header_image_url'] ?? '' ) );
		if ( '' !== $url ) {
			return $url;
		}
		$company = self::branding( $doc, 'company' );
		return trim( (string) ( $company['header_image_url'] ?? '' ) );
	}

	/** Tabla de meta del encabezado (N° / Fecha / Vigencia / Valor U.F). */
	private static function re_meta_table( array $doc, bool $editable ): string
	{
		$uf_raw   = self::val( $doc, 'pricing.uf_value_clp', null );
		$uf_value = is_numeric( $uf_raw ) ? number_format( (float) $uf_raw, 2, ',', '.' ) : '';

		return '<table class="esq-meta">'
			. '<tr><td class="esq-meta-line"><span class="esq-meta-label">Cotización N°</span> '
				. self::slot( $doc, 'meta.quote_number', $editable ) . '</td></tr>'
			. '<tr><td class="esq-meta-line"><span class="esq-meta-label">Fecha:</span> '
				. self::slot_date( $doc, 'meta.issued_at', $editable ) . '</td></tr>'
			. '<tr><td class="esq-meta-line"><span class="esq-meta-label">VIGENCIA:</span> '
				. self::slot_date( $doc, 'meta.valid_until', $editable ) . '</td></tr>'
			. '<tr><td class="esq-meta-line"><span class="esq-meta-label">VALOR U.F:</span> '
				. ( '' !== $uf_value ? self::wrap( 'pricing.uf_value_clp', 'number', esc_html( $uf_value ), $editable ) : '' ) . '</td></tr>'
			. '</table>';
	}

	/** DATOS DEL CLIENTE — rejilla 2 columnas (Nombre/Teléfono, RUT/Email). */
	private static function re_client( array $doc, bool $editable ): string
	{
		return '<div class="esq-section"><h2 class="esq-title">DATOS DEL CLIENTE</h2>'
			. '<table class="esq-grid">'
			. '<tr>'
				. '<td class="esq-lbl" style="width:13%">Nombre</td><td style="width:37%">' . self::slot( $doc, 'client.name', $editable ) . '</td>'
				. '<td class="esq-lbl" style="width:14%">Teléfono:</td><td style="width:36%">' . self::slot( $doc, 'client.phone', $editable, '-' ) . '</td>'
			. '</tr>'
			. '<tr>'
				. '<td class="esq-lbl">RUT:</td><td>' . self::slot( $doc, 'client.tax_id', $editable, '-' ) . '</td>'
				. '<td class="esq-lbl">Email:</td><td>' . self::slot( $doc, 'client.email', $editable, '-' ) . '</td>'
			. '</tr>'
			. '</table></div>';
	}

	/** DATOS DEL PROYECTO Y LA PROPIEDAD — 2 columnas con rótulos de la referencia. */
	private static function re_project_property( array $doc, bool $editable ): string
	{
		$program = self::slot( $doc, 'property.bedrooms', $editable, '-' ) . ' D / '
			. self::slot( $doc, 'property.bathrooms', $editable, '-' ) . ' B';

		$total_area  = self::slot( $doc, 'property.area_total_m2', $editable, '-' );
		$usable_area = self::slot( $doc, 'property.area_usable_m2', $editable, '---' );

		// Valor de la propiedad (UF): editable; si falta, cae al total (retrocompat).
		$property_uf = self::val( $doc, 'pricing.property_value_uf', null );
		if ( ! is_numeric( $property_uf ) ) {
			$property_uf = self::val( $doc, 'pricing.total_uf', null );
		}
		$property_uf_slot = self::number_slot( $property_uf, 2, $editable, 'pricing.property_value_uf', null, '-' );

		$left = array(
			'Proyecto: ' . self::slot( $doc, 'project.name', $editable, '-' ),
			self::slot( $doc, 'property.unit_code', $editable, '-' ),
			'Modelo: ' . self::slot( $doc, 'property.model', $editable, '-' ),
			'Superficie Completa: ' . $total_area . '[m2] aprox.',
			'Piso(s): ' . self::slot( $doc, 'property.floors', $editable, '-' ),
			'Bodega: ' . self::slot( $doc, 'property.storage', $editable, '-' ),
			'Valor de la propiedad (UF): ' . $property_uf_slot,
		);
		$right = array(
			'Etapa: ' . self::slot( $doc, 'project.stage', $editable, '-' ),
			'Orientación: ' . self::slot( $doc, 'property.orientation', $editable, '-' ),
			'Programa: ' . $program,
			'Superficie útil: ' . $usable_area,
			'Estacionamiento: ' . self::slot( $doc, 'property.parking', $editable, '-' ),
			'',
			'',
		);

		$rows = '';
		$n    = max( count( $left ), count( $right ) );
		for ( $i = 0; $i < $n; $i++ ) {
			$rows .= '<tr><td style="width:50%">' . ( $left[ $i ] ?? '' ) . '</td>'
				. '<td style="width:50%">' . ( $right[ $i ] ?? '' ) . '</td></tr>';
		}

		return '<div class="esq-section"><h2 class="esq-title">DATOS DEL PROYECTO Y LA PROPIEDAD</h2>'
			. '<table class="esq-grid">' . $rows . '</table></div>';
	}

	/** VALOR DE LA INVERSIÓN — tabla Concepto | Total(UF) | Total.($) con total en negrita. */
	private static function re_investment( array $doc, bool $editable ): string
	{
		$rows = $doc['investment_rows'] ?? array();
		if ( is_string( $rows ) ) {
			$decoded = json_decode( $rows, true );
			$rows    = is_array( $decoded ) ? $decoded : array();
		}
		$rows = is_array( $rows ) ? $rows : array();

		$body        = '';
		$sum_uf      = 0.0;
		$sum_clp     = 0.0;
		$has_total   = false;

		foreach ( $rows as $i => $r ) {
			if ( ! is_array( $r ) ) {
				continue;
			}
			$concept = (string) ( $r['concept'] ?? '' );
			$is_total = (bool) preg_match( '/total/i', $concept );
			if ( $is_total ) {
				$has_total = true;
			}
			if ( isset( $r['total_uf'] ) && is_numeric( $r['total_uf'] ) ) {
				$sum_uf += (float) $r['total_uf'];
			}
			if ( isset( $r['total_clp'] ) && is_numeric( $r['total_clp'] ) ) {
				$sum_clp += (float) $r['total_clp'];
			}

			$body .= '<tr' . ( $is_total ? ' class="esq-total"' : '' ) . '>'
				. '<td>' . self::slot( $doc, "investment_rows.$i.concept", $editable, '', $r ) . '</td>'
				. '<td class="esq-right">' . self::number_slot( $r['total_uf'] ?? null, 2, $editable, "investment_rows.$i.total_uf", $r, '-' ) . '</td>'
				. '<td class="esq-right">' . self::number_slot( $r['total_clp'] ?? null, 0, $editable, "investment_rows.$i.total_clp", $r, '-' ) . '</td>'
				. '</tr>';
		}

		if ( ! $has_total ) {
			$body .= '<tr class="esq-total"><td>Valores Totales</td>'
				. '<td class="esq-right">' . esc_html( number_format( $sum_uf, 2, ',', '.' ) ) . '</td>'
				. '<td class="esq-right">' . esc_html( number_format( $sum_clp, 0, ',', '.' ) ) . '</td></tr>';
		}

		return '<div class="esq-section"><h2 class="esq-title">VALOR DE LA INVERSIÓN</h2>'
			. '<table class="esq-table"><colgroup><col style="width:50%"><col style="width:25%"><col style="width:25%"></colgroup>'
			. '<thead><tr>'
			. '<th>Concepto</th><th class="esq-right">Total(UF)</th><th class="esq-right">Total.($)</th>'
			. '</tr></thead><tbody>' . $body . '</tbody></table></div>';
	}

	/** Nota + condiciones numeradas `1.-`, `2.-`, `3.-`. */
	private static function re_notes_conditions( array $doc, bool $editable ): string
	{
		$notes        = (string) self::val( $doc, 'notes', '' );
		$notes_display = '' !== trim( $notes ) ? wp_kses_post( $notes ) : '&nbsp;';
		$notes_inner  = '<span class="esq-meta-label">Nota:</span> '
			. ( $editable ? '<span data-qfield="notes" data-qtype="textarea">' . $notes_display . '</span>' : $notes_display );

		$items_html = '';
		foreach ( self::conditions( $doc ) as $i => $c ) {
			$items_html .= '<div class="esq-cond-item">' . ( $i + 1 ) . '.- ' . wp_kses_post( (string) $c ) . '</div>';
		}

		$list = $editable
			? '<div class="esq-cond" data-qfield="conditions.list" data-qtype="textarea">' . $items_html . '</div>'
			: '<div class="esq-cond">' . $items_html . '</div>';

		return '<div class="esq-section"><div class="esq-cond-box">'
			. '<p class="esq-nota">' . $notes_inner . '</p>' . $list
			. '</div></div>';
	}

	/** DATOS SALA DE VENTA — Télefono/Email + Sala de Ventas/Horario. */
	private static function re_sales_room( array $doc, bool $editable ): string
	{
		$phone   = (string) self::val( $doc, 'project.sales_room_phone', '' );
		$email   = (string) self::val( $doc, 'project.sales_room_email', '' );
		$name    = (string) self::val( $doc, 'project.sales_room_name', '' );
		$address = (string) self::val( $doc, 'project.sales_room_address', '' );
		$hours   = (string) self::val( $doc, 'project.sales_room_hours', '' );

		if ( '' === trim( $phone . $email . $name . $address . $hours ) ) {
			return '';
		}

		$place = '' !== trim( $address ) ? $address : $name;

		return '<div class="esq-section"><h2 class="esq-title">DATOS SALA DE VENTA</h2>'
			. '<table class="esq-grid">'
			. '<tr>'
				. '<td style="width:50%"><span class="esq-lbl">Télefono:</span> ' . self::slot( $doc, 'project.sales_room_phone', $editable, '-' ) . '</td>'
				. '<td style="width:50%"><span class="esq-lbl">Email:</span> ' . self::slot( $doc, 'project.sales_room_email', $editable, '-' ) . '</td>'
			. '</tr>'
			. '<tr>'
				. '<td><span class="esq-lbl">Sala de Ventas:</span> ' . self::slot( $doc, 'project.sales_room_name', $editable, '', array( 'sales_room_name' => $place ) ) . '</td>'
				. '<td><span class="esq-lbl">Horario:</span> ' . self::slot( $doc, 'project.sales_room_hours', $editable, 'No Informa' ) . '</td>'
			. '</tr>'
			. '</table></div>';
	}

	/** FINANCIAMIENTO (página nueva) — simulación + tabla Años/Valor mes/UF/Valor mes/$/Renta Mínima. */
	private static function re_financing( array $doc, bool $editable ): string
	{
		$fin = (array) ( $doc['financing'] ?? array() );

		$total_uf = is_numeric( self::val( $doc, 'pricing.total_uf', null ) ) ? (float) self::val( $doc, 'pricing.total_uf', 0 ) : 0.0;
		$uf_value = is_numeric( self::val( $doc, 'pricing.uf_value_clp', null ) ) ? (float) self::val( $doc, 'pricing.uf_value_clp', 0 ) : 0.0;

		// Base del crédito: "Valor de la propiedad (UF)" (editable) y, si falta,
		// el total (retrocompatible).
		$property_uf = is_numeric( self::val( $doc, 'pricing.property_value_uf', null ) ) ? (float) self::val( $doc, 'pricing.property_value_uf', 0 ) : 0.0;
		$base_uf     = $property_uf > 0 ? $property_uf : $total_uf;

		$percent = isset( $fin['credit_percent'] ) && is_numeric( $fin['credit_percent'] ) ? (float) $fin['credit_percent'] : 0.0;
		$rate    = isset( $fin['annual_rate_percent'] ) && is_numeric( $fin['annual_rate_percent'] ) ? (float) $fin['annual_rate_percent'] : 0.0;

		// Con "Valor de la propiedad (UF)" el monto se calcula SIEMPRE desde ese
		// valor; sin él se conserva el `credit_uf` explícito (retrocompatible).
		if ( $property_uf > 0 ) {
			$credit = $base_uf * ( $percent / 100.0 );
		} elseif ( isset( $fin['credit_uf'] ) && is_numeric( $fin['credit_uf'] ) ) {
			$credit = (float) $fin['credit_uf'];
		} else {
			$credit = $base_uf * ( $percent / 100.0 );
		}
		$credit_str = ( floor( $credit ) === $credit ) ? (string) (int) $credit : number_format( $credit, 2, ',', '.' );

		$sim = isset( $fin['simulation_no'] ) && '' !== (string) $fin['simulation_no'] ? (string) $fin['simulation_no'] : '1';

		$line = 'Simulación Dividendo Nº ' . esc_html( $sim )
			. ' / Monto Crédito: ' . esc_html( $credit_str ) . ' U.F. ('
			. esc_html( rtrim( rtrim( number_format( $percent, 2, ',', '.' ), '0' ), ',' ) ) . '%) (Tasa '
			. esc_html( number_format( $rate, 2, ',', '.' ) ) . ')';

		$table = '';
		$rows  = Calculator::financing_rows( $fin, $total_uf, $uf_value, $property_uf );
		if ( ! empty( $rows ) ) {
			$body = '';
			foreach ( $rows as $r ) {
				$body .= '<tr>'
					. '<td>' . esc_html( (string) (int) $r['years'] ) . '</td>'
					. '<td class="esq-right">' . esc_html( 'U.F. ' . number_format( (float) $r['dividend_uf'], 1, ',', '.' ) ) . '</td>'
					. '<td class="esq-right">' . esc_html( Calculator::format_amount( (float) $r['dividend_clp'], 'CLP' ) ) . '</td>'
					. '<td class="esq-right">' . esc_html( Calculator::format_amount( (float) $r['min_income_clp'], 'CLP' ) ) . '</td>'
					. '</tr>';
			}
			$table = '<table class="esq-table"><colgroup><col style="width:16%"><col style="width:28%"><col style="width:28%"><col style="width:28%"></colgroup>'
				. '<thead><tr>'
				. '<th>Años</th><th class="esq-right">Valor mes/UF</th>'
				. '<th class="esq-right">Valor mes/$</th><th class="esq-right">Renta Mínima /$</th>'
				. '</tr></thead><tbody>' . $body . '</tbody></table>';
		}

		return '<div style="page-break-before: always;"></div>'
			. '<div class="esq-section"><h2 class="esq-title">FINANCIAMIENTO</h2>'
			. '<p class="esq-fin-line">' . $line . '</p>'
			. $table . '</div>';
	}

	/**
	 * IMÁGENES DEL PROYECTO — continúa la página del financiamiento (sin salto).
	 *
	 * Adaptativo: si NO hay imágenes devuelve '' (ni título ni espacio). Con
	 * 1..6 imágenes emite una rejilla de 2 columnas que ocupa solo el alto
	 * necesario (sin fila/celda de relleno). La altura máxima de cada imagen se
	 * reduce a medida que hay más, para no desbordar a una 3.ª página.
	 */
	private static function re_images( array $doc, bool $editable ): string
	{
		$images = $doc['images'] ?? array();
		if ( is_string( $images ) ) {
			$decoded = json_decode( $images, true );
			$images  = is_array( $decoded ) ? $decoded : array();
		}
		$images = is_array( $images ) ? $images : array();
		if ( empty( $images ) ) {
			$project = self::branding( $doc, 'project' );
			$images  = is_array( $project['images'] ?? null ) ? $project['images'] : array();
		}
		if ( empty( $images ) ) {
			// Sin imágenes: la sección completa desaparece (sin título ni hueco).
			return '';
		}

		$count = count( $images );
		$max_h = $count <= 2 ? '5.8cm' : ( $count <= 4 ? '4.8cm' : '3.8cm' );

		$html = '<div class="esq-section"><h2 class="esq-title">IMÁGENES DEL PROYECTO</h2>';
		$html .= '<table class="esq-images">';
		foreach ( array_chunk( $images, 2 ) as $chunk ) {
			$single = ( 1 === count( $chunk ) );
			$html  .= '<tr>';
			foreach ( $chunk as $img ) {
				$img     = is_array( $img ) ? $img : array();
				$url     = (string) ( $img['url'] ?? '' );
				$caption = (string) ( $img['caption'] ?? '' );
				$cell    = '';
				if ( '' !== $url ) {
					// `alt` vacío a propósito: dompdf pinta el `alt` cuando la
					// imagen no carga, lo que duplicaría el caption visible.
					$cell .= '<img class="esq-image" src="' . esc_url( $url ) . '" alt="" style="max-width:100%;max-height:' . esc_attr( $max_h ) . ';height:auto;">';
				}
				if ( '' !== $caption ) {
					$cell .= '<div class="esq-image-caption">' . esc_html( $caption ) . '</div>';
				}
				// Imagen sola: ocupa el ancho disponible (sin celda vacía fea).
				$td_style = $single ? ' style="width:100%;"' : '';
				$html    .= '<td' . $td_style . '>' . $cell . '</td>';
			}
			$html .= '</tr>';
		}
		$html .= '</table></div>';

		return $html;
	}

	/** Firma final: operador del proyecto y, debajo, su cargo (sin sitio web). */
	private static function re_signature( array $doc, bool $editable ): string
	{
		$operator = (string) self::val( $doc, 'operator.name', '' );
		if ( '' === trim( $operator ) ) {
			$operator = (string) self::val( $doc, 'issuer.authority_name', '' );
		}
		if ( '' === trim( $operator ) ) {
			$operator = (string) self::val( $doc, 'branding.company.name', '' );
		}
		if ( '' === trim( $operator ) ) {
			$operator = 'Luis Rodrigo Valdés M.';
		}

		$role = (string) self::val( $doc, 'operator.role', '' );
		if ( '' === trim( $role ) ) {
			$role = (string) self::val( $doc, 'issuer.authority_role', '' );
		}

		$role_html = '' !== trim( $role )
			? '<br>' . self::wrap( 'operator.role', 'text', esc_html( $role ), $editable )
			: '';

		return '<div class="esq-section esq-avoid-break"><table class="esq-sign"><tr><td class="esq-sign-line">'
			. self::wrap( 'operator.name', 'text', esc_html( $operator ), $editable )
			. $role_html
			. '</td><td></td></tr></table></div>';
	}

	/**
	 * Pie de página fijo (imagen de marca; fallback texto). Se repite en toda página.
	 *
	 * Si la plantilla trae `bottom_url`, la imagen inferior a ancho completo la
	 * reemplaza (la emite `Template::page_open` al inicio del body).
	 *
	 * @param array<string,mixed> $template Plantilla resuelta.
	 */
	private static function re_footer( array $doc, array $template = array() ): string
	{
		if ( '' !== trim( (string) ( $template['bottom_url'] ?? '' ) ) ) {
			return '';
		}

		$footer = self::val( $doc, 'branding.footer', array() );
		$footer = is_array( $footer ) ? $footer : array();

		$url = (string) ( $footer['image_url'] ?? '' );
		if ( '' === trim( $url ) ) {
			$url = (string) self::val( $doc, 'branding.footer.image_url', '' );
		}

		$inner = '';
		if ( '' !== trim( $url ) ) {
			$inner = '<img class="esq-footer-img" src="' . esc_url( $url ) . '" alt="estandar.cl" style="max-width:100%;height:auto;">';
		} else {
			$inner = 'estandar.cl';
		}

		return '<div class="esq-footer-fixed">' . $inner . '</div>';
	}

	// ── Secciones genéricas (fallback) ───────────────────────────────────────

	private static function header( array $doc, array $issuer, bool $editable ): string
	{
		$logo = '';
		$url  = (string) ( $issuer['logo_url'] ?? '' );
		if ( '' !== $url ) {
			$logo = '<img class="q-logo" src="' . esc_url( $url ) . '" alt="' . esc_attr( (string) ( $issuer['company_name'] ?? '' ) ) . '">';
		}

		$meta = '<table class="q-meta"><tr>'
			. '<td class="q-label">N°</td><td>' . self::slot( $doc, 'meta.quote_number', $editable ) . '</td>'
			. '<td class="q-label">Emitida</td><td>' . self::slot( $doc, 'meta.issued_at', $editable ) . '</td>'
			. '<td class="q-label">Válida hasta</td><td>' . self::slot( $doc, 'meta.valid_until', $editable ) . '</td>'
			. '</tr></table>';

		return '<div class="q-header">'
			. $logo
			. '<h1 class="q-title">' . self::slot( $doc, 'meta.document_title', $editable, 'COTIZACIÓN' ) . '</h1>'
			. '<div class="q-company">' . self::slot( $doc, 'issuer.company_name', $editable ) . '</div>'
			. $meta
			. '</div>';
	}

	private static function client_section( array $doc, bool $editable ): string
	{
		$rows = ''
			. self::row( 'Cliente', self::slot( $doc, 'client.name', $editable ) )
			. self::row( 'RUT', self::slot( $doc, 'client.tax_id', $editable ) )
			. self::row( 'Email', self::slot( $doc, 'client.email', $editable ) )
			. self::row( 'Teléfono', self::slot( $doc, 'client.phone', $editable ) )
			. self::row( 'Dirección', self::slot( $doc, 'client.address', $editable ) );

		return '<div class="q-section"><h2 class="q-section-title">Cliente</h2>'
			. '<table class="q-table">' . $rows . '</table></div>';
	}

	private static function project_property_section( array $doc, bool $editable ): string
	{
		$has_project  = self::has_group( $doc, 'project' );
		$has_property = self::has_group( $doc, 'property' );
		$has_pricing  = self::has_group( $doc, 'pricing' );
		if ( ! $has_project && ! $has_property && ! $has_pricing ) {
			return '';
		}

		$html = '<div class="q-section"><h2 class="q-section-title">Proyecto y propiedad</h2><table class="q-table">';

		if ( $has_project ) {
			$html .= self::row( 'Proyecto', self::slot( $doc, 'project.name', $editable ) );
			$html .= self::row( 'Etapa', self::slot( $doc, 'project.stage', $editable ) );
		}
		if ( $has_property ) {
			$html .= self::row( 'Unidad', self::slot( $doc, 'property.unit_code', $editable ) );
			$html .= self::row( 'Modelo', self::slot( $doc, 'property.model', $editable ) );
			$html .= self::row( 'Orientación', self::slot( $doc, 'property.orientation', $editable ) );
			$html .= self::row( 'Superficie total (m²)', self::slot( $doc, 'property.area_total_m2', $editable ) );
			$html .= self::row( 'Superficie útil (m²)', self::slot( $doc, 'property.area_usable_m2', $editable ) );
			$html .= self::row( 'Pisos', self::slot( $doc, 'property.floors', $editable ) );
			$html .= self::row( 'Dormitorios', self::slot( $doc, 'property.bedrooms', $editable ) );
			$html .= self::row( 'Baños', self::slot( $doc, 'property.bathrooms', $editable ) );
			$html .= self::row( 'Bodegas', self::slot( $doc, 'property.storage', $editable ) );
			$html .= self::row( 'Estacionamientos', self::slot( $doc, 'property.parking', $editable ) );
		}
		if ( $has_pricing ) {
			$price_currency = (string) self::val( $doc, 'meta.currency', 'UF' );
			$html .= self::row( 'Total', self::money_slot( $doc, 'pricing.total_uf', $editable, $price_currency ) );
			$html .= self::row( 'Valor UF (CLP)', self::money_slot( $doc, 'pricing.uf_value_clp', $editable, 'CLP' ) );
			$html .= self::row( 'Fecha valor UF', self::slot( $doc, 'pricing.uf_effective_date', $editable ) );
			$html .= self::row( 'Fuente UF', self::slot( $doc, 'pricing.uf_source', $editable ) );
		}

		return $html . '</table></div>';
	}

	private static function items_section( array $doc, string $currency, bool $editable ): string
	{
		$items = self::items( $doc );
		if ( empty( $items ) ) {
			return '';
		}

		$head = '<tr><th>Descripción</th><th>Detalle</th><th class="q-center">Unidad</th>'
			. '<th class="q-right">Cant.</th><th class="q-right">Precio unit.</th><th class="q-right">Total</th></tr>';

		$body = '';
		foreach ( $items as $i => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$line = isset( $item['line_total'] ) && is_numeric( $item['line_total'] )
				? (float) $item['line_total']
				: Calculator::line_total( $item );

			$body .= '<tr>'
				. '<td>' . self::slot( $doc, "items.$i.description", $editable, '', $item ) . '</td>'
				. '<td>' . self::slot( $doc, "items.$i.detail", $editable, '', $item ) . '</td>'
				. '<td class="q-center">' . self::slot( $doc, "items.$i.unit", $editable, '', $item ) . '</td>'
				. '<td class="q-right">' . self::slot( $doc, "items.$i.qty", $editable, '1', $item ) . '</td>'
				. '<td class="q-right">' . self::money_slot( $doc, "items.$i.unit_price", $editable, $currency, '0', $item ) . '</td>'
				. '<td class="q-right">' . self::money_slot( $doc, "items.$i.line_total", $editable, $currency, '0', $item, $line ) . '</td>'
				. '</tr>';
		}

		return '<div class="q-section"><h2 class="q-section-title">Ítems</h2>'
			. '<table class="q-table">' . $head . $body . '</table></div>';
	}

	private static function totals_section( array $doc, string $currency, bool $editable ): string
	{
		$items  = self::items( $doc );
		$totals = (array) ( $doc['totals'] ?? array() );

		$opts = array(
			'discount'      => $totals['discount'] ?? 0,
			'discount_mode' => $totals['discount_mode'] ?? 'amount',
			'tax_rate'      => $totals['tax_rate'] ?? 0,
			'tax_included'  => ! empty( $totals['tax_included'] ),
			'currency'      => $currency,
		);

		if ( $items ) {
			$calc = Calculator::compose_totals( $items, $opts );
		} else {
			$subtotal = is_numeric( $totals['subtotal'] ?? null ) ? (float) $totals['subtotal'] : 0.0;
			$discount = Calculator::discount_amount( $subtotal, $totals['discount'] ?? 0, (string) ( $totals['discount_mode'] ?? 'amount' ) );
			$net      = $subtotal - $discount;
			$tax_incl = ! empty( $totals['tax_included'] );
			$tax_amt  = Calculator::tax_amount( $net, $totals['tax_rate'] ?? 0, $tax_incl );
			$calc     = array(
				'subtotal'   => $subtotal,
				'discount'   => $discount,
				'net'        => $net,
				'tax_rate'   => is_numeric( $totals['tax_rate'] ?? null ) ? (float) $totals['tax_rate'] : 0.0,
				'tax_amount' => $tax_amt,
				'total'      => $tax_incl ? $net : ( $net + $tax_amt ),
				'currency'   => $currency,
			);
		}

		$rows = ''
			. self::total_row( 'Subtotal', Calculator::format_amount( (float) $calc['subtotal'], $currency ), 'totals.subtotal', $editable )
			. ( (float) $calc['discount'] > 0 ? self::total_row( 'Descuento', '- ' . Calculator::format_amount( (float) $calc['discount'], $currency ), 'totals.discount', $editable ) : '' )
			. self::total_row( 'Impuesto (' . rtrim( rtrim( number_format( (float) $calc['tax_rate'], 2, ',', '.' ), '0' ), ',' ) . '%)', Calculator::format_amount( (float) $calc['tax_amount'], $currency ), 'totals.tax_amount', $editable )
			. self::total_row( 'Total', Calculator::format_amount( (float) $calc['total'], $currency ), 'totals.total', $editable, true );

		return '<div class="q-section"><h2 class="q-section-title">Totales</h2>'
			. '<table class="q-table q-totals">' . $rows . '</table></div>';
	}

	private static function financing_section( array $doc, string $currency, bool $editable ): string
	{
		$profile = (string) self::val( $doc, 'profile', '' );
		if ( self::PROFILE_REAL_ESTATE === $profile ) {
			return '';
		}
		if ( ! self::has_group( $doc, 'financing' ) ) {
			return '';
		}

		$financing = (array) ( $doc['financing'] ?? array() );
		$total_uf  = is_numeric( self::val( $doc, 'pricing.total_uf', null ) ) ? (float) self::val( $doc, 'pricing.total_uf', 0 ) : 0.0;
		$uf_value  = is_numeric( self::val( $doc, 'pricing.uf_value_clp', null ) ) ? (float) self::val( $doc, 'pricing.uf_value_clp', 0 ) : 0.0;
		$property_uf = is_numeric( self::val( $doc, 'pricing.property_value_uf', null ) ) ? (float) self::val( $doc, 'pricing.property_value_uf', 0 ) : 0.0;

		$inputs = '<table class="q-table">'
			. self::row( 'Pie financiado (%)', self::slot( $doc, 'financing.credit_percent', $editable ) )
			. self::row( 'Tasa anual (%)', self::slot( $doc, 'financing.annual_rate_percent', $editable ) )
			. self::row( 'Múltiplo renta', self::slot( $doc, 'financing.income_multiple', $editable ) )
			. self::row( 'Plazo (años)', self::slot( $doc, 'financing.terms_years', $editable ) )
			. '</table>';

		$rows  = Calculator::financing_rows( $financing, $total_uf, $uf_value, $property_uf );
		$table = '';
		if ( ! empty( $rows ) ) {
			$table = '<table class="q-table"><tr><th>Plazo</th><th class="q-right">Crédito (UF)</th>'
				. '<th class="q-right">Dividendo (UF)</th><th class="q-right">Dividendo (CLP)</th>'
				. '<th class="q-right">Renta mínima (CLP)</th></tr>';
			foreach ( $rows as $r ) {
				$table .= '<tr>'
					. '<td>' . esc_html( (int) $r['years'] . ' años' ) . '</td>'
					. '<td class="q-right">' . esc_html( number_format( (float) $r['credit_uf'], 2, ',', '.' ) ) . '</td>'
					. '<td class="q-right">' . esc_html( number_format( (float) $r['dividend_uf'], 2, ',', '.' ) ) . '</td>'
					. '<td class="q-right">' . esc_html( Calculator::format_amount( (float) $r['dividend_clp'], 'CLP' ) ) . '</td>'
					. '<td class="q-right">' . esc_html( Calculator::format_amount( (float) $r['min_income_clp'], 'CLP' ) ) . '</td>'
					. '</tr>';
			}
			$table .= '</table>';
		}

		return '<div class="q-section"><h2 class="q-section-title">Inversión y financiamiento</h2>'
			. $inputs . $table . '</div>';
	}

	private static function conditions_section( array $doc, bool $editable ): string
	{
		$conditions = self::conditions( $doc );
		$notes      = (string) self::val( $doc, 'notes', '' );
		if ( empty( $conditions ) && '' === $notes ) {
			return '';
		}

		$inner = '';
		foreach ( $conditions as $c ) {
			$inner .= '<li>' . wp_kses_post( (string) $c ) . '</li>';
		}
		$list = '' !== $inner ? '<ul class="q-cond">' . $inner . '</ul>' : '';
		if ( $editable ) {
			$list = '<div data-qfield="conditions.list" data-qtype="textarea">' . $list . '</div>';
		}

		$notes_html = '' !== $notes ? '<p class="q-muted">' . wp_kses_post( $notes ) . '</p>' : '';

		return '<div class="q-section"><h2 class="q-section-title">Condiciones</h2>' . $list . $notes_html . '</div>';
	}

	private static function sales_room_section( array $doc, bool $editable ): string
	{
		if ( ! self::has_group( $doc, 'project' ) ) {
			return '';
		}
		$has_room = '' !== (string) self::val( $doc, 'project.sales_room_name', '' )
			|| '' !== (string) self::val( $doc, 'project.sales_room_address', '' )
			|| '' !== (string) self::val( $doc, 'project.sales_room_phone', '' )
			|| '' !== (string) self::val( $doc, 'project.sales_room_email', '' )
			|| '' !== (string) self::val( $doc, 'project.sales_room_hours', '' );
		if ( ! $has_room ) {
			return '';
		}

		return '<div class="q-section"><h2 class="q-section-title">Sala de venta</h2><table class="q-table">'
			. self::row( 'Sala', self::slot( $doc, 'project.sales_room_name', $editable ) )
			. self::row( 'Dirección', self::slot( $doc, 'project.sales_room_address', $editable ) )
			. self::row( 'Teléfono', self::slot( $doc, 'project.sales_room_phone', $editable ) )
			. self::row( 'Email', self::slot( $doc, 'project.sales_room_email', $editable ) )
			. self::row( 'Horario', self::slot( $doc, 'project.sales_room_hours', $editable ) )
			. '</table></div>';
	}

	private static function footer_section( array $doc, bool $editable ): string
	{
		$legal = (string) self::val( $doc, 'issuer.footer_legal', '' );
		$sign  = '<table class="q-sign"><tr><td class="q-sign-line">'
			. self::slot( $doc, 'issuer.authority_name', $editable )
			. '<br>' . self::slot( $doc, 'issuer.authority_role', $editable )
			. '<br>' . self::slot( $doc, 'issuer.authority_id', $editable )
			. '</td><td></td></tr></table>';

		return '<div class="q-section q-footer">' . $sign
			. ( '' !== $legal ? '<p class="q-muted">' . wp_kses_post( $legal ) . '</p>' : '' )
			. '</div>';
	}

	// ── Slots y helpers ──────────────────────────────────────────────────────

	/**
	 * Slot editable de un campo del catálogo.
	 *
	 * @param array       $doc      Documento.
	 * @param string      $key      Clave canónica (`grupo.campo` o `items.N.campo`).
	 * @param bool        $editable Añadir `data-qfield` (true) o no (false).
	 * @param string      $fallback Valor por defecto.
	 * @param array|null  $source   Fuente alternativa (p. ej. una fila de ítem).
	 */
	private static function slot( array $doc, string $key, bool $editable, string $fallback = '', ?array $source = null ): string
	{
		$raw = null !== $source ? self::resolve( $source, self::tail( $key ), null ) : self::val( $doc, $key, null );
		if ( null === $raw || '' === $raw || ( is_array( $raw ) && empty( $raw ) ) ) {
			$raw = $fallback;
		}
		if ( is_array( $raw ) ) {
			$raw = implode( ', ', array_map( 'strval', $raw ) );
		}

		$meta = self::field_meta( $key );
		$type = (string) ( $meta['type'] ?? 'text' );
		$html = ( 'textarea' === $type ) ? nl2br( wp_kses_post( (string) $raw ) ) : esc_html( (string) $raw );

		return self::wrap( $key, $type, $html, $editable );
	}

	/** Slot de fecha en formato largo español (`6 de Junio de 2026`). */
	private static function slot_date( array $doc, string $key, bool $editable, string $fallback = '---' ): string
	{
		$raw     = (string) self::val( $doc, $key, '' );
		$display = '' !== trim( $raw ) ? Template::date_es( $raw ) : $fallback;
		return self::wrap( $key, 'date', esc_html( $display ), $editable );
	}

	/** Slot numérico con separadores (`4.875,00` / `198.472.658`). */
	private static function number_slot( $value, int $decimals, bool $editable, string $key, ?array $source = null, string $fallback = '-' ): string
	{
		if ( null !== $source ) {
			$raw = self::resolve( $source, self::tail( $key ), null );
			if ( null !== $raw && is_numeric( $raw ) ) {
				$value = (float) $raw;
			}
		}
		$text = is_numeric( $value ) ? number_format( (float) $value, $decimals, ',', '.' ) : $fallback;
		return self::wrap( $key, 'number', esc_html( $text ), $editable );
	}

	/** Slot monetario formateado según moneda. `$override` fuerza el número a mostrar. */
	private static function money_slot( array $doc, string $key, bool $editable, string $currency, string $fallback = '0', ?array $source = null, $override = null ): string
	{
		if ( null !== $override && is_numeric( $override ) ) {
			$num = (float) $override;
		} else {
			$raw = null !== $source ? self::resolve( $source, self::tail( $key ), null ) : self::val( $doc, $key, null );
			if ( null === $raw ) {
				$raw = $fallback;
			}
			$num = is_numeric( $raw ) ? (float) $raw : 0.0;
		}
		$meta = self::field_meta( $key );
		$type = (string) ( $meta['type'] ?? 'number' );
		return self::wrap( $key, $type, esc_html( Calculator::format_amount( $num, $currency ) ), $editable );
	}

	/** Envuelve un valor HTML en un slot editable (o lo deja plano). */
	private static function wrap( string $key, string $type, string $html, bool $editable ): string
	{
		if ( ! $editable ) {
			return $html;
		}
		return '<span data-qfield="' . esc_attr( $key ) . '" data-qtype="' . esc_attr( $type ) . '">' . $html . '</span>';
	}

	/** Fila de tabla `etiqueta / valor` a 2 columnas. */
	private static function row( string $label, string $value ): string
	{
		return '<tr><td class="q-label">' . esc_html( $label ) . '</td><td>' . $value . '</td></tr>';
	}

	/** Fila de totales con celda de valor alineada a la derecha. */
	private static function total_row( string $label, string $value, string $key, bool $editable, bool $bold = false ): string
	{
		$class = $bold ? ' q-total-label' : '';
		$meta  = self::field_meta( $key );
		$type  = (string) ( $meta['type'] ?? 'number' );
		$html  = self::wrap( $key, $type, esc_html( $value ), $editable );
		return '<tr><td class="q-total-label' . $class . '">' . esc_html( $label ) . '</td><td class="q-right' . $class . '">' . $html . '</td></tr>';
	}

	/** Bloque de branding (imagen de banda > logo > nombre) con espacio dedicado. */
	private static function brand_block( array $zone, bool $editable, string $key_prefix, string $role ): string
	{
		$name   = (string) ( $zone['name'] ?? '' );
		$header = (string) ( $zone['header_image_url'] ?? '' );
		$logo   = (string) ( $zone['logo_url'] ?? '' );
		$slug   = (string) ( $zone['slug'] ?? '' );
		$color  = Template::safe_color( $zone['color'] ?? '', '' );

		$style = '' !== $color ? 'color:' . $color . ';' : '';

		$media = '';
		if ( '' !== $header ) {
			// Banda superior de calidad de impresión (ancho completo).
			$media = '<img class="esq-brand-header" src="' . esc_url( $header ) . '" alt="' . esc_attr( $name ) . '" style="max-width:100%;height:auto;">';
		} elseif ( '' !== $logo ) {
			$media = '<img class="esq-brand-logo" src="' . esc_url( $logo ) . '" alt="' . esc_attr( $name ) . '" style="max-width:100%;height:auto;">';
		} elseif ( '' !== trim( $name ) ) {
			$media = '<div class="esq-brand-name" style="' . esc_attr( $style ) . '">'
				. self::wrap( $key_prefix . '.name', 'text', esc_html( $name ), $editable ) . '</div>';
		} else {
			$media = '<div class="esq-brand-name" style="' . esc_attr( $style ) . '">&nbsp;</div>';
		}

		// El slug es metadato, no branding visible: solo se muestra si no hay nombre.
		$slug_html = ( '' === trim( $name ) && '' !== trim( $slug ) ) ? '<div class="esq-brand-name">' . esc_html( $slug ) . '</div>' : '';

		return '<div class="esq-brand esq-brand-' . esc_attr( $role ) . '">' . $media . $slug_html . '</div>';
	}

	/** Zona de branding normalizada (`company` o `project`) con fallback al emisor/proyecto. */
	private static function branding( array $doc, string $zone ): array
	{
		$data = self::val( $doc, 'branding.' . $zone, array() );
		if ( is_array( $data ) && ! empty( $data ) ) {
			return $data;
		}

		if ( 'project' === $zone ) {
			return array(
				'name'             => (string) self::val( $doc, 'project.name', '' ),
				'slug'             => (string) self::val( $doc, 'project.slug', '' ),
				'header_image_url' => '',
				'images'           => array(),
			);
		}

		$issuer = (array) ( $doc['issuer'] ?? array() );
		return array(
			'name'             => (string) ( $issuer['company_name'] ?? '' ),
			'logo_url'         => (string) ( $issuer['logo_url'] ?? '' ),
			'header_image_url' => (string) ( $issuer['header_image_url'] ?? '' ),
			'color'            => (string) ( $issuer['primary_color'] ?? '' ),
			'website'          => (string) ( $issuer['company_website'] ?? '' ),
		);
	}

	/** Plantilla normalizada del documento (`id`,`name`,`background_url`,`bottom_url`). */
	private static function template( array $doc ): array
	{
		$template = $doc['template'] ?? array();
		return is_array( $template ) ? $template : array();
	}

	/** Emisor efectivo: `issuer` con respaldo de `branding.company`. */
	private static function effective_issuer( array $doc ): array
	{
		$issuer  = (array) ( $doc['issuer'] ?? array() );
		$company = (array) ( $doc['branding']['company'] ?? array() );

		if ( '' === (string) ( $issuer['company_name'] ?? '' ) && '' !== (string) ( $company['name'] ?? '' ) ) {
			$issuer['company_name'] = $company['name'];
		}
		if ( '' === (string) ( $issuer['logo_url'] ?? '' ) && '' !== (string) ( $company['logo_url'] ?? '' ) ) {
			$issuer['logo_url'] = $company['logo_url'];
		}
		if ( '' === (string) ( $issuer['primary_color'] ?? '' ) && '' !== (string) ( $company['color'] ?? '' ) ) {
			$issuer['primary_color'] = $company['color'];
		}

		return $issuer;
	}

	/** Resuelve una clave punteada sobre un array (soporta claves planas). */
	private static function resolve( array $data, string $key, $default = null )
	{
		if ( array_key_exists( $key, $data ) ) {
			return $data[ $key ];
		}
		$cur = $data;
		foreach ( explode( '.', $key ) as $part ) {
			if ( is_array( $cur ) && array_key_exists( $part, $cur ) ) {
				$cur = $cur[ $part ];
			} else {
				return $default;
			}
		}
		return $cur;
	}

	/** Valor del documento para una clave punteada. */
	private static function val( array $doc, string $key, $default = '' )
	{
		return self::resolve( $doc, $key, $default );
	}

	/** Última parte de una clave punteada (`items.0.qty` → `qty`). */
	private static function tail( string $key ): string
	{
		$parts = explode( '.', $key );
		return (string) end( $parts );
	}

	/** Metadatos del catálogo para una clave (tolera claves de ítems indexadas). */
	private static function field_meta( string $key ): array
	{
		$all = Fields::all();
		if ( isset( $all[ $key ] ) ) {
			return $all[ $key ];
		}
		if ( preg_match( '/^items\.\d+\.(.+)$/', $key, $m ) ) {
			return $all[ 'items.' . $m[1] ] ?? array();
		}
		if ( preg_match( '/^investment_rows\.\d+\.(.+)$/', $key, $m ) ) {
			return $all[ 'investment_rows.' . $m[1] ] ?? array();
		}
		return array();
	}

	/** True si algún campo del grupo tiene valor en el documento. */
	private static function has_group( array $doc, string $group ): bool
	{
		$group_data = $doc[ $group ] ?? null;
		if ( is_array( $group_data ) ) {
			foreach ( $group_data as $v ) {
				if ( is_array( $v ) ? ! empty( $v ) : '' !== (string) $v ) {
					return true;
				}
			}
		}
		foreach ( Fields::group( $group ) as $key => $meta ) {
			$v = self::val( $doc, $key, null );
			if ( null !== $v && '' !== $v && ! ( is_array( $v ) && empty( $v ) ) ) {
				return true;
			}
		}
		return false;
	}

	/** Ítems del documento (array directo o JSON). */
	private static function items( array $doc ): array
	{
		$items = $doc['items'] ?? array();
		if ( is_string( $items ) ) {
			$decoded = json_decode( $items, true );
			$items   = is_array( $decoded ) ? $decoded : array();
		}
		return is_array( $items ) ? $items : array();
	}

	/** Condiciones normalizadas a lista de strings (array, JSON o texto multilínea). */
	private static function conditions( array $doc ): array
	{
		$raw = $doc['conditions'] ?? self::val( $doc, 'conditions.list', array() );
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$raw = $decoded;
			} else {
				$raw = preg_split( '/\r\n|\r|\n/', $raw );
			}
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $c ) {
			if ( is_array( $c ) ) {
				$c = implode( ' ', array_map( 'strval', $c ) );
			}
			$c = trim( (string) $c );
			if ( '' !== $c ) {
				$out[] = $c;
			}
		}
		return $out;
	}
}
