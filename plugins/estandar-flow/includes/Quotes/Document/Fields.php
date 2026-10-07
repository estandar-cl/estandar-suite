<?php

namespace Estandar\Flow\Quotes\Document;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fields — catálogo PURE de los campos del documento de cotización.
 *
 * Única fuente de verdad de la forma del modelo: cada campo declara su
 * etiqueta, tipo, grupo, origen (lead/operador/ajustes/calculado), si es
 * obligatorio y si es editable. No toca BD ni opciones.
 *
 * Tipos soportados: `text|textarea|number|date|select|bool`.
 * Grupos: `meta|issuer|client|project|property|pricing|financing|items|totals|conditions`.
 * Origen: `lead|operator|settings|computed`.
 */
class Fields
{
	/**
	 * Devuelve el catálogo completo (clave canónica `grupo.campo` => metadatos).
	 *
	 * @return array<string,array{label:string,type:string,group:string,source:string,required:bool,editable:bool}>
	 */
	public static function all(): array
	{
		static $catalog = null;
		if ( is_array( $catalog ) ) {
			return $catalog;
		}

		$catalog = array();

		// meta — encabezado y control documental.
		$catalog['meta.title']           = self::def( 'Título', 'text', 'meta', 'operator', true );
		$catalog['meta.document_title']  = self::def( 'Título del documento', 'text', 'meta', 'settings' );
		$catalog['meta.quote_number']    = self::def( 'Número de cotización', 'text', 'meta', 'computed', true );
		$catalog['meta.issued_at']       = self::def( 'Fecha de emisión', 'date', 'meta', 'operator' );
		$catalog['meta.valid_until']     = self::def( 'Válida hasta', 'date', 'meta', 'computed' );
		$catalog['meta.currency']        = self::def( 'Moneda', 'select', 'meta', 'operator' );

		// issuer — emisor/branding/firmante (settings).
		$catalog['issuer.company_name']    = self::def( 'Empresa', 'text', 'issuer', 'settings' );
		$catalog['issuer.company_website'] = self::def( 'Sitio web', 'text', 'issuer', 'settings' );
		$catalog['issuer.company_phone']   = self::def( 'Teléfono', 'text', 'issuer', 'settings' );
		$catalog['issuer.company_email']   = self::def( 'Email', 'text', 'issuer', 'settings' );
		$catalog['issuer.company_address'] = self::def( 'Dirección', 'textarea', 'issuer', 'settings' );
		$catalog['issuer.logo_url']        = self::def( 'Logo (URL)', 'text', 'issuer', 'settings' );
		$catalog['issuer.primary_color']   = self::def( 'Color primario', 'text', 'issuer', 'settings' );
		$catalog['issuer.accent_color']    = self::def( 'Color de acento', 'text', 'issuer', 'settings' );
		$catalog['issuer.font_family']     = self::def( 'Tipografía', 'text', 'issuer', 'settings' );
		$catalog['issuer.authority_name']  = self::def( 'Nombre firmante', 'text', 'issuer', 'settings' );
		$catalog['issuer.authority_role']  = self::def( 'Cargo firmante', 'text', 'issuer', 'settings' );
		$catalog['issuer.authority_id']    = self::def( 'RUT firmante', 'text', 'issuer', 'settings' );
		$catalog['issuer.footer_legal']    = self::def( 'Pie legal', 'textarea', 'issuer', 'settings' );

		// branding — zonas de marca empresa/proyecto (documento inmobiliario).
		$catalog['branding.company.name']     = self::def( 'Empresa (branding)', 'text', 'branding', 'settings' );
		$catalog['branding.company.logo_url'] = self::def( 'Logo empresa (URL)', 'text', 'branding', 'settings' );
		$catalog['branding.company.color']    = self::def( 'Color empresa', 'text', 'branding', 'settings' );
		$catalog['branding.company.website']  = self::def( 'Sitio empresa (branding)', 'text', 'branding', 'settings' );
		$catalog['branding.project.name']     = self::def( 'Proyecto (branding)', 'text', 'branding', 'settings' );
		$catalog['branding.project.logo_url'] = self::def( 'Logo proyecto (URL)', 'text', 'branding', 'settings' );
		$catalog['branding.project.color']    = self::def( 'Color proyecto', 'text', 'branding', 'settings' );
		$catalog['branding.project.slug']     = self::def( 'Slug proyecto', 'text', 'branding', 'settings' );

		// client — heredado del lead (editable por el operador).
		$catalog['client.name']    = self::def( 'Cliente', 'text', 'client', 'lead', true );
		$catalog['client.tax_id']  = self::def( 'RUT', 'text', 'client', 'lead' );
		$catalog['client.email']   = self::def( 'Email', 'text', 'client', 'lead' );
		$catalog['client.phone']   = self::def( 'Teléfono', 'text', 'client', 'lead' );
		$catalog['client.address'] = self::def( 'Dirección', 'text', 'client', 'operator' );

		// project — obra/proyecto y sala de venta.
		$catalog['project.name']                = self::def( 'Proyecto', 'text', 'project', 'lead' );
		$catalog['project.stage']               = self::def( 'Etapa', 'text', 'project', 'lead' );
		$catalog['project.slug']                = self::def( 'Slug del proyecto', 'text', 'project', 'settings' );
		$catalog['project.sales_room_name']     = self::def( 'Sala de venta', 'text', 'project', 'settings' );
		$catalog['project.sales_room_address']  = self::def( 'Dirección sala', 'text', 'project', 'settings' );
		$catalog['project.sales_room_phone']    = self::def( 'Teléfono sala', 'text', 'project', 'settings' );
		$catalog['project.sales_room_email']    = self::def( 'Email sala', 'text', 'project', 'settings' );
		$catalog['project.sales_room_hours']    = self::def( 'Horario sala', 'text', 'project', 'settings' );

		// property — inmobiliario (perfil real_estate).
		$catalog['property.unit_code']       = self::def( 'Unidad', 'text', 'property', 'operator' );
		$catalog['property.model']           = self::def( 'Modelo', 'text', 'property', 'operator' );
		$catalog['property.orientation']     = self::def( 'Orientación', 'text', 'property', 'operator' );
		$catalog['property.area_total_m2']   = self::def( 'Superficie total (m²)', 'number', 'property', 'operator' );
		$catalog['property.area_usable_m2']  = self::def( 'Superficie útil (m²)', 'number', 'property', 'operator' );
		$catalog['property.floors']          = self::def( 'Pisos', 'number', 'property', 'operator' );
		$catalog['property.bedrooms']        = self::def( 'Dormitorios', 'number', 'property', 'operator' );
		$catalog['property.bathrooms']       = self::def( 'Baños', 'number', 'property', 'operator' );
		$catalog['property.storage']         = self::def( 'Bodega', 'text', 'property', 'operator' );
		$catalog['property.parking']         = self::def( 'Estacionamiento', 'text', 'property', 'operator' );

		// pricing — inmobiliario.
		$catalog['pricing.total_uf']         = self::def( 'Total UF', 'number', 'pricing', 'operator' );
		$catalog['pricing.property_value_uf'] = self::def( 'Valor de la propiedad (UF)', 'number', 'pricing', 'operator' );
		$catalog['pricing.uf_value_clp']     = self::def( 'Valor UF (CLP)', 'number', 'pricing', 'operator' );
		$catalog['pricing.uf_effective_date'] = self::def( 'Fecha valor UF', 'date', 'pricing', 'operator' );
		$catalog['pricing.uf_source']        = self::def( 'Fuente UF', 'text', 'pricing', 'settings' );

		// investment_rows — tabla de inversión inmobiliaria.
		$catalog['investment_rows.concept']   = self::def( 'Concepto', 'text', 'investment_rows', 'operator' );
		$catalog['investment_rows.total_uf']  = self::def( 'Total (UF)', 'number', 'investment_rows', 'computed', false, false );
		$catalog['investment_rows.total_clp'] = self::def( 'Total ($)', 'number', 'investment_rows', 'computed', false, false );

		// financing — inmobiliario.
		$catalog['financing.credit_percent']      = self::def( 'Pie financiado (%)', 'number', 'financing', 'operator' );
		$catalog['financing.annual_rate_percent'] = self::def( 'Tasa anual (%)', 'number', 'financing', 'operator' );
		$catalog['financing.income_multiple']     = self::def( 'Múltiplo renta', 'number', 'financing', 'operator' );
		$catalog['financing.terms_years']         = self::def( 'Plazo (años)', 'number', 'financing', 'operator' );

		// items — líneas genéricas de la cotización.
		$catalog['items.description'] = self::def( 'Descripción', 'text', 'items', 'operator' );
		$catalog['items.detail']      = self::def( 'Detalle', 'textarea', 'items', 'operator' );
		$catalog['items.unit']        = self::def( 'Unidad', 'text', 'items', 'operator' );
		$catalog['items.qty']         = self::def( 'Cantidad', 'number', 'items', 'operator' );
		$catalog['items.unit_price']  = self::def( 'Precio unitario', 'number', 'items', 'operator' );
		$catalog['items.line_total']  = self::def( 'Total línea', 'number', 'items', 'computed', false, false );

		// totals — calculados.
		$catalog['totals.subtotal']   = self::def( 'Subtotal', 'number', 'totals', 'computed', false, false );
		$catalog['totals.discount']   = self::def( 'Descuento', 'number', 'totals', 'operator' );
		$catalog['totals.tax_rate']   = self::def( 'Tasa de impuesto (%)', 'number', 'totals', 'operator' );
		$catalog['totals.tax_amount'] = self::def( 'Impuesto', 'number', 'totals', 'computed', false, false );
		$catalog['totals.total']      = self::def( 'Total', 'number', 'totals', 'computed', false, false );

		// conditions — condiciones comerciales (texto).
		$catalog['conditions.list'] = self::def( 'Condiciones', 'textarea', 'conditions', 'operator' );

		// operator — operador firmante y su cargo (editables en el wizard/editor).
		$catalog['operator.name'] = self::def( 'Operador', 'text', 'operator', 'operator' );
		$catalog['operator.role'] = self::def( 'Cargo del operador', 'text', 'operator', 'operator' );

		return $catalog;
	}

	/**
	 * Subconjunto del catálogo por grupo.
	 *
	 * @return array<string,array>
	 */
	public static function group( string $g ): array
	{
		$out = array();
		foreach ( self::all() as $key => $meta ) {
			if ( ( $meta['group'] ?? '' ) === $g ) {
				$out[ $key ] = $meta;
			}
		}
		return $out;
	}

	/**
	 * Claves heredadas del lead (source = lead), en orden de catálogo.
	 *
	 * @return string[]
	 */
	public static function lead_inherited(): array
	{
		$out = array();
		foreach ( self::all() as $key => $meta ) {
			if ( ( $meta['source'] ?? '' ) === 'lead' ) {
				$out[] = $key;
			}
		}
		return $out;
	}

	/** Definición normalizada de un campo. */
	private static function def( string $label, string $type, string $group, string $source, bool $required = false, bool $editable = true ): array
	{
		return array(
			'label'    => $label,
			'type'     => $type,
			'group'    => $group,
			'source'   => $source,
			'required' => $required,
			'editable' => $editable,
		);
	}
}
