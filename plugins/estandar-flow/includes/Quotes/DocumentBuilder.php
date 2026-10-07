<?php

namespace Estandar\Flow\Quotes;

use Estandar\Flow\Quotes\Document\Calculator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DocumentBuilder — construye el documento canónico que consume `Renderer` (M5).
 *
 * Fusiona la fila de la cotización (`Quotes::get`), el lead y los settings del
 * módulo (`Settings`) en un único array `profile/meta/issuer/client/project/
 * items/totals/...`. Tolerante a fila nula (sin DB): devuelve el documento base
 * con defaults, sin fatalizar.
 */
class DocumentBuilder
{
	/**
	 * Documento canónico de una cotización.
	 *
	 * @param int   $quote_id Id de la cotización (0 → documento base).
	 * @param array $lead     Datos del lead (preceden sobre la fila en cliente).
	 * @return array
	 */
	public static function for_quote( int $quote_id, array $lead = array() ): array
	{
		$row = array();
		if ( $quote_id > 0 && class_exists( 'Estandar\\Flow\\Quotes' ) ) {
			$found = \Estandar\Flow\Quotes::get( $quote_id );
			if ( is_array( $found ) ) {
				$row = $found;
			}
		}

		$has_settings = class_exists( 'Estandar\\Flow\\Quotes\\Settings' );
		$s            = $has_settings ? Settings::all() : array();

		// El documento editado manda también para resolver el branding del proyecto.
		$stored       = self::stored_document( $row );
		$project_slug = self::project_slug( $row, $lead, $stored );

		// Datos efectivos: settings del proyecto sobre los globales del módulo.
		$eff = $has_settings ? Settings::effective_data( $project_slug ) : $s;

		$currency = (string) ( $row['currency'] ?? ( $s['currency'] ?? 'CLP' ) );
		if ( '' === $currency ) {
			$currency = 'CLP';
		}
		$vat = $s['vat'] ?? '19';

		$issuer_data = $has_settings ? Settings::issuer() : array();

		$eff_title      = trim( (string) ( $eff['document_title'] ?? '' ) );
		$settings_title = '' !== $eff_title
			? $eff_title
			: (string) ( $issuer_data['document_title'] ?? ( $s['document_title'] ?? '' ) );

		$title = (string) ( $row['title'] ?? '' );
		if ( '' === $title ) {
			$title = '' !== $settings_title ? $settings_title : 'Cotización';
		}
		$document_title = '' !== $settings_title ? $settings_title : $title;

		$issued_at = (string) ( $row['issued_at'] ?? '' );
		if ( '' === $issued_at ) {
			$issued_at = (string) ( $row['created_at'] ?? '' );
		}
		if ( '' === $issued_at ) {
			$issued_at = date( 'Y-m-d' );
		}
		$issued_at = substr( $issued_at, 0, 10 );

		$validity    = self::validity_days( $row, $has_settings );
		$valid_until = (string) ( $row['valid_until'] ?? '' );
		if ( '' === $valid_until ) {
			$valid_until = self::add_days( $issued_at, $validity );
		}

		$quote_number = (string) ( $row['quote_number'] ?? '' );
		if ( '' === $quote_number ) {
			$quote_number = 'COT-' . $quote_id;
		}

		$items = self::normalize_items( $row );

		$project_data    = self::project( $row, $lead, $eff, $project_slug );
		$property_data   = self::ensure_property( self::group_from( array( $row, $lead ), 'property' ), $row, $lead );
		$pricing_data    = self::pricing( $row, $lead, $eff );
		$branding        = $has_settings ? Settings::branding( $project_slug ) : array();
		$investment_rows = self::build_investment_rows( $project_data, $property_data, $pricing_data );
		$template        = $has_settings ? Settings::template_for_project( $project_slug ) : array();
		// Opciones de campos configurables en la plantilla (menús desplegables del editor).
		$field_options   = $has_settings ? Settings::field_options( $project_slug ) : array();

		// Imagen inferior del proyecto (wizard "Proyectos"): si el proyecto la
		// definió, gana sobre el `bottom_url` de la plantilla global.
		$template_brief = self::template_brief( (array) $template );
		$project_images = array();
		if ( $has_settings ) {
			$p_settings = Settings::project_settings( $project_slug );
			$p_bottom   = trim( (string) ( $p_settings['bottom_image_url'] ?? '' ) );
			if ( '' === $p_bottom ) {
				$p_bottom = Settings::attachment_url( $p_settings['bottom_image_id'] ?? 0 );
			}
			if ( '' !== $p_bottom ) {
				$template_brief['bottom_url'] = $p_bottom;
			}
			// Galería editable del documento: se prefija desde los settings del
			// proyecto (id/url/caption). El documento guardado manda cuando ya
			// trae galería (ver `merge_document`).
			if ( isset( $p_settings['images'] ) && is_array( $p_settings['images'] ) ) {
				$project_images = array_values( $p_settings['images'] );
			}
		}

		// Branding y plantilla vivos (settings de admin). Son SIEMPRE la fuente
		// de verdad y no deben ser pisados por el `document_json` guardado.
		$live_branding = $branding;
		$live_template = $template_brief;

		// Perfil: `profile_key` de la fila > `profile` del documento > activo.
		$default_profile = $has_settings ? Settings::active_profile() : 'generic@1';
		$profile = trim( (string) ( $row['profile_key'] ?? '' ) );
		if ( '' === $profile ) {
			$profile = trim( (string) ( $row['profile'] ?? '' ) );
		}
		if ( '' === $profile ) {
			$profile = (string) $default_profile;
		}

		$notes = trim( (string) ( $row['notes'] ?? '' ) );
		if ( '' === $notes ) {
			$notes = (string) ( $eff['notes'] ?? '' );
		}

		$doc = array(
			'profile' => $profile,
			'meta'    => array(
				'title'          => $title,
				'document_title' => $document_title,
				'quote_number'   => $quote_number,
				'issued_at'      => $issued_at,
				'valid_until'    => $valid_until,
				'currency'       => $currency,
			),
			'issuer'          => $issuer_data,
			'branding'        => $branding,
			'operator'        => self::operator( $project_slug, $issuer_data, $row, $lead, $eff ),
			'client'          => self::client( $row, $lead ),
			'project'         => $project_data,
			'items'           => $items,
			'totals'          => Calculator::compose_totals( $items, array( 'tax_rate' => $vat, 'currency' => $currency ) ),
			'conditions'      => $has_settings ? self::conditions_lines_from( $eff ) : array(),
			'notes'           => $notes,
			'images'          => $project_images,
			'property'        => $property_data,
			'pricing'         => $pricing_data,
			'investment_rows' => $investment_rows,
			'financing'       => self::financing( $row, $lead, $has_settings, $eff ),
			'template'        => $template_brief,
			'field_options'   => $field_options,
		);

		// El documento editado en el WYSIWYG (`document_json`) es la base; los
		// datos vivos (fila/lead/settings) solo rellenan lo que falte.
		if ( ! empty( $stored ) ) {
			$doc = self::merge_document( $stored, $doc );
		}

		// Branding y plantilla SIEMPRE desde los settings vivos (admin config):
		// el wizard puede guardar `branding` (vacío) en `document_json`, y ese
		// valor no debe pisar el header/bottom configurado en el proyecto.
		$doc['branding'] = $live_branding;
		$doc['template'] = $live_template;
		// Las opciones de campos SIEMPRE desde los settings vivos del proyecto.
		$doc['field_options'] = $field_options;

		// "Valor de la propiedad (UF)": doc guardado > settings del proyecto >
		// fallback al total (retrocompatible). Se resuelve al final para que el
		// fallback use el `total_uf` efectivo tras la fusión.
		$doc['pricing'] = self::with_property_value_uf( (array) ( $doc['pricing'] ?? array() ), (array) ( $doc['property'] ?? array() ) );

		return $doc;
	}

	/**
	 * Garantiza `pricing.property_value_uf`: si no viene un valor positivo usa
	 * `pricing.total_uf` y, en su defecto, el `total_uf` de la unidad/propiedad.
	 * PURE y tolerante (no inventa valores inexistentes).
	 */
	public static function with_property_value_uf( array $pricing, array $property = array() ): array
	{
		$value = isset( $pricing['property_value_uf'] ) && is_numeric( $pricing['property_value_uf'] )
			? (float) $pricing['property_value_uf']
			: 0.0;
		if ( $value > 0 ) {
			return $pricing;
		}

		$fallback = 0.0;
		if ( isset( $pricing['total_uf'] ) && is_numeric( $pricing['total_uf'] ) ) {
			$fallback = (float) $pricing['total_uf'];
		}
		if ( $fallback <= 0 && isset( $property['total_uf'] ) && is_numeric( $property['total_uf'] ) ) {
			$fallback = (float) $property['total_uf'];
		}
		if ( $fallback > 0 ) {
			$pricing['property_value_uf'] = $fallback;
		}

		return $pricing;
	}

	/** Documento guardado en la fila (`document_json`), o array vacío si no es JSON válido. */
	private static function stored_document( array $row ): array
	{
		$raw = $row['document_json'] ?? null;
		if ( is_array( $raw ) ) {
			return $raw;
		}
		$raw = (string) $raw;
		if ( '' === trim( $raw ) ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return ( is_array( $decoded ) && ! empty( $decoded ) ) ? $decoded : array();
	}

	/**
	 * Fusiona el documento guardado (`$stored`, manda) con el documento vivo
	 * compuesto por el builder (`$live`, rellena huecos). PURE y tolerante.
	 *
	 * @param array $stored Documento canónico persistido (document_json).
	 * @param array $live   Documento vivo (fila/lead/settings).
	 * @return array
	 */
	public static function merge_document( array $stored, array $live ): array
	{
		if ( empty( $stored ) ) {
			return $live;
		}

		$out = $stored;

		foreach ( array( 'meta', 'issuer', 'branding', 'operator', 'client', 'project', 'property', 'pricing', 'financing' ) as $group ) {
			$base = ( isset( $out[ $group ] ) && is_array( $out[ $group ] ) ) ? $out[ $group ] : array();
			$fill = ( isset( $live[ $group ] ) && is_array( $live[ $group ] ) ) ? $live[ $group ] : array();
			$out[ $group ] = self::fill_missing( $base, $fill );
		}

		foreach ( array( 'items', 'investment_rows', 'conditions', 'template' ) as $key ) {
			if ( empty( $out[ $key ] ) && ! empty( $live[ $key ] ) ) {
				$out[ $key ] = $live[ $key ];
			}
		}

		// Galería: el documento guardado manda SIEMPRE que traiga la clave (aunque
		// venga vacía, p. ej. el operador quitó todas las imágenes). Si no la trae,
		// se prefija desde los settings vivos del proyecto.
		if ( ! array_key_exists( 'images', $out ) && ! empty( $live['images'] ) ) {
			$out['images'] = $live['images'];
		}

		if ( empty( $out['notes'] ) && ! empty( $live['notes'] ) ) {
			$out['notes'] = $live['notes'];
		}
		if ( empty( $out['profile'] ) && ! empty( $live['profile'] ) ) {
			$out['profile'] = $live['profile'];
		}

		// Totales: derivados de los ítems (readonly en el editor) → recalcular.
		if ( ! empty( $out['items'] ) && is_array( $out['items'] ) ) {
			$t        = ( isset( $out['totals'] ) && is_array( $out['totals'] ) ) ? $out['totals'] : array();
			$currency = (string) ( $out['meta']['currency'] ?? ( $live['meta']['currency'] ?? 'CLP' ) );
			$out['totals'] = Calculator::compose_totals( $out['items'], array(
				'discount'      => $t['discount'] ?? 0,
				'discount_mode' => $t['discount_mode'] ?? 'amount',
				'tax_rate'      => $t['tax_rate'] ?? ( $live['totals']['tax_rate'] ?? 0 ),
				'tax_included'  => ! empty( $t['tax_included'] ),
				'currency'      => $currency,
			) );
		}

		return $out;
	}

	/** Rellena recursivamente `$base` con los valores no vacíos que falten de `$fill`. */
	private static function fill_missing( array $base, array $fill ): array
	{
		foreach ( $fill as $key => $value ) {
			if ( ! array_key_exists( $key, $base ) || self::is_blank( $base[ $key ] ) ) {
				$base[ $key ] = $value;
				continue;
			}
			if ( is_array( $base[ $key ] ) && is_array( $value ) ) {
				$base[ $key ] = self::fill_missing( $base[ $key ], $value );
			}
		}
		return $base;
	}

	/** True si el valor está vacío (null, '' o array vacío). */
	private static function is_blank( $value ): bool
	{
		if ( null === $value || is_object( $value ) ) {
			return true;
		}
		if ( is_array( $value ) ) {
			return empty( $value );
		}
		return '' === trim( (string) $value );
	}

	/**
	 * Filas de la tabla "VALOR DE LA INVERSIÓN" (PURE).
	 *
	 * Una fila por propiedad/unidad. El `Renderer` añade él mismo la fila
	 * "Valores Totales", por lo que aquí NO se duplica.
	 *
	 * @param array $project  Datos del proyecto (`name`...).
	 * @param array $property Grupo propiedad (o `units` con varias unidades).
	 * @param array $pricing  Grupo pricing (`total_uf`, `uf_value_clp`...).
	 * @return array<int,array{concept:string,total_uf:float,total_clp:float}>
	 */
	public static function build_investment_rows( array $project, array $property, array $pricing ): array
	{
		$units = array();
		if ( isset( $property['units'] ) && is_array( $property['units'] ) ) {
			foreach ( $property['units'] as $unit ) {
				if ( is_array( $unit ) ) {
					$units[] = $unit;
				}
			}
		} elseif ( ! empty( $property ) ) {
			$units[] = $property;
		}

		if ( empty( $units ) ) {
			return array();
		}

		$uf_value = isset( $pricing['uf_value_clp'] ) && is_numeric( $pricing['uf_value_clp'] )
			? (float) $pricing['uf_value_clp'] : 0.0;
		$base_uf  = isset( $pricing['total_uf'] ) && is_numeric( $pricing['total_uf'] )
			? (float) $pricing['total_uf'] : 0.0;

		$rows = array();
		foreach ( $units as $unit ) {
			$unit_uf = isset( $unit['total_uf'] ) && is_numeric( $unit['total_uf'] )
				? (float) $unit['total_uf']
				: ( 1 === count( $units ) ? $base_uf : 0.0 );

			$rows[] = array(
				'concept'   => self::unit_concept( $project, $unit ),
				'total_uf'  => $unit_uf,
				'total_clp' => Calculator::uf_to_clp( $unit_uf, $uf_value ),
			);
		}
		return $rows;
	}

	/** Concepto de una fila de inversión (`Casa: C-10` o nombre del proyecto). */
	private static function unit_concept( array $project, array $unit ): string
	{
		$type = trim( (string) ( $unit['type'] ?? $unit['property_type'] ?? '' ) );
		$type = '' !== $type ? $type : 'Casa';
		$code = trim( (string) ( $unit['unit_code'] ?? $unit['code'] ?? '' ) );

		if ( '' !== $code ) {
			if ( 0 === stripos( $code, $type ) ) {
				$code = trim( ltrim( substr( $code, strlen( $type ) ), " :-" ) );
			}
			return '' !== $code ? $type . ': ' . $code : $type;
		}

		$project_name = trim( (string) ( $project['name'] ?? '' ) );
		return '' !== $project_name ? $project_name : 'Propiedad';
	}

	/** Slug del proyecto del lead/fila (claves explícitas primero; lead precede). */
	private static function project_slug( array $row, array $lead, array $stored = array() ): string
	{
		// 1) El documento editado (WYSIWYG) manda: project.slug / project.name.
		$sp = $stored['project'] ?? array();
		if ( is_array( $sp ) ) {
			foreach ( array( 'slug', 'name' ) as $k ) {
				$v = $sp[ $k ] ?? '';
				if ( is_scalar( $v ) && '' !== trim( (string) $v ) ) {
					return self::slugify( (string) $v );
				}
			}
		}
		foreach ( array( 'project_slug', 'project_id' ) as $key ) {
			foreach ( array( $lead, $row ) as $source ) {
				$value = $source[ $key ] ?? '';
				if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
					return self::slugify( (string) $value );
				}
			}
		}
		foreach ( array( $lead, $row ) as $source ) {
			$value = $source['project'] ?? '';
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return self::slugify( (string) $value );
			}
		}
		return '';
	}

	/** Completa `bedrooms`/`bathrooms` del grupo propiedad desde la fila/lead. */
	private static function ensure_property( array $property, array $row, array $lead ): array
	{
		foreach ( array( 'bedrooms', 'bathrooms' ) as $key ) {
			if ( isset( $property[ $key ] ) && '' !== (string) $property[ $key ] ) {
				continue;
			}
			$value = self::first( array( $row[ $key ] ?? '', $lead[ $key ] ?? '' ) );
			if ( '' !== $value ) {
				$property[ $key ] = $value;
			}
		}
		return $property;
	}

	/** Etiqueta legible de un proyecto (registry o el slug). */
	private static function project_label( string $slug ): string
	{
		if ( class_exists( 'Estandar\\Core\\Leads\\ProjectRegistry' ) && method_exists( 'Estandar\\Core\\Leads\\ProjectRegistry', 'label_for' ) ) {
			$label = trim( (string) \Estandar\Core\Leads\ProjectRegistry::label_for( $slug ) );
			if ( '' !== $label ) {
				return $label;
			}
		}
		return $slug;
	}

	/** Slugify tolerante (delega en ProjectRegistry si está cargado). */
	private static function slugify( string $value ): string
	{
		if ( class_exists( 'Estandar\\Core\\Leads\\ProjectRegistry' ) ) {
			return (string) \Estandar\Core\Leads\ProjectRegistry::normalize_slug( $value );
		}
		$value = strtolower( trim( $value ) );
		if ( function_exists( 'sanitize_title' ) ) {
			return (string) sanitize_title( $value );
		}
		$value = (string) preg_replace( '/[^a-z0-9\-_]/', '-', $value );
		$value = (string) preg_replace( '/-+/', '-', $value );
		return trim( $value, '-' );
	}

	// ── Composición de grupos ───────────────────────────────────────────────

	/** Cliente: lead > fila (customer_*). */
	private static function client( array $row, array $lead ): array
	{
		return array(
			'name'    => self::first( array( $lead['name'] ?? '', $row['customer_name'] ?? '' ) ),
			'tax_id'  => self::first( array( $lead['tax_id'] ?? '', $lead['rut'] ?? '', $row['customer_tax_id'] ?? '' ) ),
			'email'   => self::first( array( $lead['email'] ?? '', $row['customer_email'] ?? '' ) ),
			'phone'   => self::first( array( $lead['phone'] ?? '', $row['customer_phone'] ?? '' ) ),
			'address' => self::first( array( $lead['address'] ?? '', $row['customer_address'] ?? '' ) ),
		);
	}

	/** Proyecto y sala de venta: fila > lead > datos efectivos del proyecto. */
	private static function project( array $row, array $lead, array $s, string $slug = '' ): array
	{
		if ( '' === $slug ) {
			$slug = self::project_slug( $row, $lead );
		}
		$name = self::first( array( $row['project_name'] ?? '', $lead['project_name'] ?? '', $s['project_name'] ?? '', $lead['project'] ?? '' ) );
		if ( '' === $name && '' !== $slug ) {
			// Sin nombre explícito: etiqueta del registry o el propio slug, para
			// que el selector de "Proyecto" muestre el proyecto actual.
			$name = self::project_label( $slug );
		}
		return array(
			'name'                => $name,
			'stage'               => self::first( array( $row['stage'] ?? '', $lead['stage'] ?? '', $lead['sales_stage'] ?? '' ) ),
			'slug'                => $slug,
			'sales_room_name'     => self::first( array( $row['sales_room_name'] ?? '', $lead['sales_room_name'] ?? '', $s['sales_room_name'] ?? '' ) ),
			'sales_room_address'  => self::first( array( $row['sales_room_address'] ?? '', $lead['sales_room_address'] ?? '', $s['sales_room_address'] ?? '' ) ),
			'sales_room_phone'    => self::first( array( $row['sales_room_phone'] ?? '', $lead['sales_room_phone'] ?? '', $s['sales_room_phone'] ?? '' ) ),
			'sales_room_email'    => self::first( array( $row['sales_room_email'] ?? '', $lead['sales_room_email'] ?? '', $s['sales_room_email'] ?? '' ) ),
			'sales_room_hours'    => self::first( array( $row['sales_room_hours'] ?? '', $lead['sales_room_hours'] ?? '', $s['sales_room_hours'] ?? '' ) ),
		);
	}

	/**
	 * Operador del proyecto: `data.operator_name` del proyecto (editable en el
	 * wizard) manda; si no, `operator_for` → asignado → autoridad → empresa.
	 * El cargo (`operator.role`) sale de `data.operator_role`.
	 *
	 * @param array<string,mixed> $eff Datos efectivos del proyecto (settings + data).
	 */
	private static function operator( string $slug, array $issuer, array $row = array(), array $lead = array(), array $eff = array() ): array
	{
		$name = trim( (string) ( $eff['operator_name'] ?? '' ) );
		if ( '' === $name && '' !== $slug && class_exists( 'Estandar\\Core\\Leads\\ProjectRegistry' )
			&& method_exists( 'Estandar\\Core\\Leads\\ProjectRegistry', 'operator_for' ) ) {
			$op   = (array) \Estandar\Core\Leads\ProjectRegistry::operator_for( $slug );
			$name = trim( (string) ( $op['name'] ?? '' ) );
		}
		if ( '' === $name ) {
			foreach ( array( $row, $lead ) as $src ) {
				$v = $src['assigned_operator'] ?? '';
				if ( is_scalar( $v ) && '' !== trim( (string) $v ) ) {
					$name = trim( (string) $v );
					break;
				}
			}
		}
		if ( '' === $name ) {
			$name = trim( (string) ( $issuer['authority_name'] ?? '' ) );
		}
		if ( '' === $name ) {
			$name = trim( (string) ( $issuer['company_name'] ?? '' ) );
		}

		$role = trim( (string) ( $eff['operator_role'] ?? '' ) );

		return array( 'name' => $name, 'role' => $role );
	}

	/**
	 * Pricing: fila/lead primero y, para huecos, los datos efectivos del
	 * proyecto (`uf_value_clp`, `uf_source`). Si no hay valor explícito, usa el
	 * UF EFECTIVO del módulo (caché SII → override manual). Nunca hardcodea.
	 */
	private static function pricing( array $row, array $lead, array $eff ): array
	{
		$base = self::group_from( array( $row, $lead ), 'pricing' );
		foreach ( array( 'uf_value_clp', 'uf_source', 'property_value_uf' ) as $key ) {
			if ( isset( $base[ $key ] ) && '' !== trim( (string) $base[ $key ] ) ) {
				continue;
			}
			$value = $eff[ $key ] ?? '';
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				$base[ $key ] = $value;
			}
		}

		$has_uf = isset( $base['uf_value_clp'] ) && is_numeric( $base['uf_value_clp'] ) && (float) $base['uf_value_clp'] > 0;

		if ( class_exists( 'Estandar\\Flow\\Quotes\\Settings' ) ) {
			$eff_uf = Settings::uf_effective();
			if ( ! $has_uf && $eff_uf['value'] > 0 ) {
				// Sin valor explícito (fila/lead/proyecto): UF efectivo del módulo.
				$base['uf_value_clp'] = $eff_uf['value'];
				if ( '' !== (string) $eff_uf['source'] ) {
					$base['uf_source'] = $eff_uf['source'];
				}
				if ( '' !== (string) $eff_uf['date'] ) {
					$base['uf_effective_date'] = $eff_uf['date'];
				}
			} else {
				// Valor explícito: completa fuente/fecha solo si faltan.
				if ( ( ! isset( $base['uf_source'] ) || '' === trim( (string) $base['uf_source'] ) ) && '' !== (string) $eff_uf['source'] ) {
					$base['uf_source'] = $eff_uf['source'];
				}
				if ( ( ! isset( $base['uf_effective_date'] ) || '' === trim( (string) $base['uf_effective_date'] ) ) && '' !== (string) $eff_uf['date'] ) {
					$base['uf_effective_date'] = $eff_uf['date'];
				}
			}
		}
		return $base;
	}

	/** Financiamiento: fila > lead > datos efectivos > defaults globales. */
	private static function financing( array $row, array $lead, bool $has_settings, array $eff = array() ): array
	{
		foreach ( array( $row, $lead ) as $source ) {
			if ( isset( $source['financing'] ) && is_array( $source['financing'] ) && ! empty( $source['financing'] ) ) {
				return $source['financing'];
			}
		}

		$defaults = $has_settings ? Settings::financing_defaults() : array();
		$project  = ( isset( $eff['financing'] ) && is_array( $eff['financing'] ) ) ? $eff['financing'] : array();
		foreach ( $project as $key => $value ) {
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				$defaults[ $key ] = $value;
			}
		}
		return $defaults;
	}

	/** Condiciones como lista, aceptando lista (proyecto) o texto (global). */
	private static function conditions_lines_from( array $data ): array
	{
		$raw = $data['conditions'] ?? array();
		if ( ! is_array( $raw ) ) {
			$raw = preg_split( '/\r\n|\r|\n/', (string) $raw );
		}
		$out = array();
		foreach ( (array) $raw as $line ) {
			if ( ! is_scalar( $line ) ) {
				continue;
			}
			$line = trim( (string) $line );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return $out;
	}

	/** Proyección mínima de la plantilla para el documento canónico. */
	private static function template_brief( array $template ): array
	{
		return array(
			'id'             => (string) ( $template['id'] ?? '' ),
			'name'           => (string) ( $template['name'] ?? '' ),
			'background_url' => (string) ( $template['background_url'] ?? '' ),
			'bottom_url'     => (string) ( $template['bottom_url'] ?? '' ),
		);
	}

	/** Un grupo (property/pricing) de la fila o del lead, si es array. */
	private static function group_from( array $sources, string $key ): array
	{
		foreach ( $sources as $source ) {
			if ( isset( $source[ $key ] ) && is_array( $source[ $key ] ) ) {
				return $source[ $key ];
			}
		}
		return array();
	}

	/** Items normalizados a `description/detail/unit/qty/unit_price/line_total`. */
	private static function normalize_items( array $row ): array
	{
		$raw = $row['items'] ?? null;
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();
		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$qty   = isset( $item['qty'] ) && is_numeric( $item['qty'] ) ? (float) $item['qty'] : 1.0;
			$price = 0.0;
			if ( isset( $item['unit_price'] ) && is_numeric( $item['unit_price'] ) ) {
				$price = (float) $item['unit_price'];
			} elseif ( isset( $item['amount'] ) && is_numeric( $item['amount'] ) ) {
				$price = (float) $item['amount'];
			}
			$out[] = array(
				'description' => (string) ( $item['description'] ?? $item['label'] ?? '' ),
				'detail'      => (string) ( $item['detail'] ?? '' ),
				'unit'        => (string) ( $item['unit'] ?? '' ),
				'qty'         => $qty,
				'unit_price'  => $price,
				'line_total'  => Calculator::line_total( array( 'qty' => $qty, 'unit_price' => $price ) ),
			);
		}
		return $out;
	}

	// ── Helpers PURE ────────────────────────────────────────────────────────

	/** Días de validez: fila > settings > 15. */
	private static function validity_days( array $row, bool $has_settings ): int
	{
		$days = (int) ( $row['validity_days'] ?? 0 );
		if ( $days > 0 ) {
			return $days;
		}
		if ( $has_settings ) {
			return Settings::validity_days();
		}
		return 15;
	}

	/** Suma días a una fecha `Y-m-d` (tolerante a fecha inválida). */
	private static function add_days( string $date, int $days ): string
	{
		$ts = strtotime( $date );
		if ( false === $ts ) {
			$ts = time();
		}
		return date( 'Y-m-d', $ts + ( max( 0, $days ) * 86400 ) );
	}

	/** Primer valor no vacío (sanitizado a string). */
	private static function first( array $values ): string
	{
		foreach ( $values as $value ) {
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return (string) $value;
			}
		}
		return '';
	}
}
