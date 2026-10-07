<?php

namespace Estandar\Flow\Quotes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings — lectura tipada de la configuración del módulo de cotizaciones (M5).
 *
 * Única fuente de verdad en runtime de `estandar_suite_flow_quotes_settings`
 * (grupos documento/finanzas/sala_ventas/autoridad/email de `Admin`) más los
 * flags de feature (`enabled`/`currency`/`vat`). Todo con defaults explícitos y
 * tolerante a la ausencia de WordPress (tests puros / CLI): nunca fataliza.
 */
class Settings
{
	/** Opción única de configuración del módulo de cotizaciones. */
	const OPTION = 'estandar_suite_flow_quotes_settings';

	/** Opción de branding por proyecto (mapa `slug => ['name','logo_id','logo_url','color']`). */
	const PROJECT_BRANDING_OPTION = 'estandar_suite_flow_quotes_project_branding';

	/** Opción de plantillas globales de documento (mapa `id => ['id','name','background_*','bottom_*','*_color']`). */
	const TEMPLATES_OPTION = 'estandar_suite_flow_quotes_templates';

	/** Opción de settings por proyecto (mapa `slug => ['template','data','images','header_image_*']`). */
	const PROJECTS_OPTION = 'estandar_suite_flow_quotes_projects';

	/** Opción de caché del valor UF (SII): `['value'=>float,'date'=>'Y-m-d','source'=>string]`. */
	const UF_OPTION = 'estandar_suite_flow_quotes_uf';

	/** Hook WP-Cron propio para refrescar el valor UF diario. */
	const UF_CRON_HOOK = 'estandar_suite_flow_quotes_uf_refresh';

	/** Fuente pública que publica el valor UF del SII (mindicador.cl). */
	const UF_ENDPOINT = 'https://mindicador.cl/api/uf';

	/** Límite duro de imágenes de galería por proyecto. */
	const MAX_PROJECT_IMAGES = 6;

	/** Límite duro de opciones por campo (`field_options`). */
	const MAX_FIELD_OPTIONS = 50;

	/** Nota por defecto de una cotización inmobiliaria (si el proyecto no define). */
	public static function default_notes(): string
	{
		return 'Disponibilidad y precios sujetos a confirmación en sala de ventas.';
	}

	/** Condiciones por defecto de una cotización inmobiliaria (si el proyecto no define). */
	public static function default_conditions(): array
	{
		return array(
			'La tasa de interés es sólo referencial. Las instituciones financieras cursan los créditos con la tasa vigente a la fecha de escrituración en que se formalice la compraventa.',
			'Validez cotización 7 días corridos a contar de esta fecha, y no constituye reserva de compra.',
			'Serán de cargo exclusivo del comprador, todos los gastos que genere esta operación, tales como los gastos asociados al crédito escrituración notariales y de inscripción en el Conservador de Bienes Raíces.',
		);
	}

	/**
	 * Opciones por defecto de los campos configurables (menús desplegables).
	 * Solo los campos con una lista razonable de valores; el resto queda libre.
	 *
	 * @return array<string,string[]>
	 */
	public static function default_field_options(): array
	{
		return array(
			'property.floors'    => array( '1', '2', '3' ),
			'property.bedrooms'  => array( '1', '2', '3', '4' ),
			'property.bathrooms' => array( '1', '2', '3' ),
			'property.storage'   => array( 'Sí', 'No' ),
			'property.parking'   => array( 'Sí', 'No' ),
		);
	}

	/**
	 * Catálogo de campos con `field_options` editables en la plantilla (path => etiqueta).
	 *
	 * @return array<string,string>
	 */
	public static function option_field_catalog(): array
	{
		return array(
			'project.name'           => 'Proyecto',
			'project.stage'          => 'Etapa',
			'property.unit_code'     => 'Unidad / casa',
			'property.model'         => 'Modelo',
			'property.area_total_m2' => 'Superficie completa (m²)',
			'property.area_usable_m2' => 'Superficie útil (m²)',
			'property.floors'        => 'Piso(s)',
			'property.storage'       => 'Bodega',
			'property.parking'       => 'Estacionamiento',
			'property.orientation'   => 'Orientación',
			'property.bedrooms'      => 'Dormitorios',
			'property.bathrooms'     => 'Baños',
		);
	}

	/**
	 * Opciones efectivas de un proyecto: defaults + override/merge del proyecto.
	 * Slug vacío → solo defaults. Nunca fatal.
	 *
	 * @return array<string,string[]>
	 */
	public static function field_options( string $slug ): array
	{
		$defaults = self::default_field_options();
		$key      = self::slug_key( $slug );
		if ( '' === $key ) {
			return $defaults;
		}

		$stored = self::project_settings( $key )['field_options'] ?? array();
		if ( ! is_array( $stored ) || empty( $stored ) ) {
			return $defaults;
		}

		$out = $defaults;
		foreach ( $stored as $path => $list ) {
			$path = (string) $path;
			if ( '' === $path || ! is_array( $list ) ) {
				continue;
			}
			$out[ $path ] = array_values( $list );
		}
		return $out;
	}

	/**
	 * Sanea el mapa `field_options`: clave = path de campo (`grupo.campo`), valor =
	 * lista de strings (acepta array o texto con una opción por línea). Sin vacíos,
	 * dedup, máx. `MAX_FIELD_OPTIONS`. Claves inválidas se descartan.
	 *
	 * @param mixed $raw
	 * @return array<string,string[]>
	 */
	public static function sanitize_field_options( $raw ): array
	{
		$raw = is_array( $raw ) ? $raw : array();
		$out = array();
		foreach ( $raw as $path => $list ) {
			$path = self::sanitize_field_path( (string) $path );
			if ( '' === $path ) {
				continue;
			}
			if ( is_string( $list ) ) {
				$list = preg_split( '/\r\n|\r|\n/', $list );
			}
			$items = self::sanitize_text_list( $list );
			$items = array_values( array_unique( $items ) );
			$items = array_slice( $items, 0, self::MAX_FIELD_OPTIONS );
			if ( ! empty( $items ) ) {
				$out[ $path ] = $items;
			}
		}
		return $out;
	}

	/** Valida un path de campo (`grupo.campo`); inválido → '' (se descarta). */
	private static function sanitize_field_path( string $path ): string
	{
		$path = strtolower( trim( $path ) );
		if ( '' === $path || ! preg_match( '/^[a-z0-9_]+(\.[a-z0-9_]+)*$/', $path ) ) {
			return '';
		}
		return $path;
	}

	/**
	 * Defaults canónicos de TODAS las claves del módulo.
	 *
	 * Si el `Admin` del módulo está cargado, sus descriptores mandan (fuente
	 * única); sin WP se usa este mapa embebido idéntico.
	 */
	public static function defaults(): array
	{
		$defaults = array(
			// documento
			'company_name'    => '',
			'company_website' => '',
			'company_phone'   => '',
			'company_email'   => '',
			'company_address' => '',
			'primary_color'   => '#1d2327',
			'accent_color'    => '#2271b1',
			'document_title'  => 'Cotización',
			'conditions'      => implode( "\n", self::default_conditions() ),
			'notes'           => self::default_notes(),
			'footer_legal'    => '',
			'renderer'        => 'dompdf',
			'active_profile'  => 'real_estate@1',
			// finanzas
			'validity_days'       => '7',
			'uf_source'           => 'manual',
			'uf_value_clp'        => '',
			'annual_rate_percent' => '',
			'credit_percent'      => '80',
			'income_multiple'     => '4',
			// sala_ventas
			'sales_room_name'    => '',
			'sales_room_address' => '',
			'sales_room_phone'   => '',
			'sales_room_email'   => '',
			'sales_room_hours'   => '',
			// autoridad
			'authority_name' => '',
			'authority_role' => '',
			'authority_id'   => '',
			'operator_copy'  => '',
			// email
			'sender_name'            => '',
			'sender_email'           => '',
			'email_subject_template' => 'Cotización',
			'email_body'             => "Estimado(a) {client_name},\n\nAdjuntamos su cotización {quote_number}.\n\nSaludos,\n{company_name}",
			'email_cc'               => '',
			'email_bcc'              => '',
		);

		// Fuente única: si Admin está cargado, sus defaults prevalecen.
		if ( class_exists( 'Estandar\\Flow\\Admin' ) ) {
			if ( method_exists( 'Estandar\\Flow\\Admin', 'quotes_settings_groups' ) ) {
				foreach ( \Estandar\Flow\Admin::quotes_settings_groups() as $fields ) {
					foreach ( (array) $fields as $key => $field ) {
						$defaults[ $key ] = is_array( $field ) ? ( $field['default'] ?? '' ) : '';
					}
				}
			}
			if ( method_exists( 'Estandar\\Flow\\Admin', 'feature_fields' ) ) {
				$ff = \Estandar\Flow\Admin::feature_fields();
				foreach ( (array) ( $ff['quotes'] ?? array() ) as $key => $field ) {
					$defaults[ $key ] = is_array( $field ) ? ( $field['default'] ?? '' ) : '';
				}
			}
		}

		// Mínimos canónicos y claves extra soportadas (no pisan lo ya definido).
		$extras = array(
			'enabled'                  => 1,
			'currency'                 => 'CLP',
			'vat'                      => '19',
			'font_family'              => '',
			'company_logo_id'          => '',
			'logo_url'                 => '',
			'company_header_image_id'  => '',
			'company_header_image_url' => '',
			'footer_image_id'          => '',
			'footer_image_url'         => '',
			'terms_years'              => '',
		);
		foreach ( $extras as $key => $value ) {
			if ( ! array_key_exists( $key, $defaults ) ) {
				$defaults[ $key ] = $value;
			}
		}

		return $defaults;
	}

	/** Configuración completa = defaults + opción guardada. Nunca fatal. */
	public static function all(): array
	{
		$defaults = self::defaults();
		$stored   = array();
		if ( function_exists( 'get_option' ) ) {
			$option = get_option( self::OPTION, array() );
			$stored = is_array( $option ) ? $option : array();
		}
		if ( function_exists( 'wp_parse_args' ) ) {
			return wp_parse_args( $stored, $defaults );
		}
		return array_merge( $defaults, $stored );
	}

	/** ¿Módulo habilitado? Default true; solo un `false` explícito lo apaga. */
	public static function enabled(): bool
	{
		$all = self::all();
		if ( ! array_key_exists( 'enabled', $all ) ) {
			return true;
		}
		$value = $all['enabled'];
		if ( is_bool( $value ) ) {
			return $value;
		}
		$value = strtolower( trim( (string) $value ) );
		return ! in_array( $value, array( '0', 'false', 'no', 'off', 'deshabilitado' ), true );
	}

	/**
	 * Perfil activo del documento (`profile_key`). Default `real_estate@1`;
	 * vacío explícito también cae al default. Nunca fatal.
	 */
	public static function active_profile(): string
	{
		$profile = trim( (string) ( self::all()['active_profile'] ?? '' ) );
		return '' !== $profile ? $profile : 'real_estate@1';
	}

	// ── Valor UF efectivo (SII) ─────────────────────────────────────────────

	/** Caché saneada del valor UF: `['value'=>float,'date'=>'Y-m-d','source'=>string]`. */
	public static function uf_cached(): array
	{
		$default = array( 'value' => 0.0, 'date' => '', 'source' => '' );
		if ( ! function_exists( 'get_option' ) ) {
			return $default;
		}
		$option = get_option( self::UF_OPTION, array() );
		if ( ! is_array( $option ) ) {
			return $default;
		}
		$value  = isset( $option['value'] ) && is_numeric( $option['value'] ) ? (float) $option['value'] : 0.0;
		$date   = isset( $option['date'] ) ? trim( (string) $option['date'] ) : '';
		$source = isset( $option['source'] ) ? trim( (string) $option['source'] ) : '';
		return array(
			'value'  => $value > 0 ? $value : 0.0,
			'date'   => $date,
			'source' => $source,
		);
	}

	/**
	 * Valor UF efectivo (CLP por 1 UF). Prioridad: (1) caché SII/servicio;
	 * (2) override manual `uf_value_clp` de los settings; (3) 0.
	 */
	public static function uf_value(): float
	{
		$cached = self::uf_cached();
		if ( $cached['value'] > 0 ) {
			return (float) $cached['value'];
		}
		$manual = (float) ( self::all()['uf_value_clp'] ?? 0 );
		return $manual > 0 ? $manual : 0.0;
	}

	/**
	 * Metadatos del UF efectivo (`value`, `date`, `source`). La `date`/`source`
	 * describen de dónde salió el valor efectivo (caché SII o manual).
	 */
	public static function uf_effective(): array
	{
		$cached = self::uf_cached();
		if ( $cached['value'] > 0 ) {
			return array(
				'value'  => (float) $cached['value'],
				'date'   => (string) $cached['date'],
				'source' => '' !== $cached['source'] ? (string) $cached['source'] : 'sii',
			);
		}
		$manual = (float) ( self::all()['uf_value_clp'] ?? 0 );
		return array(
			'value'  => $manual > 0 ? $manual : 0.0,
			'date'   => '',
			'source' => $manual > 0 ? 'manual' : '',
		);
	}

	/**
	 * Refresca el valor UF desde la fuente pública (mindicador.cl → SII) y lo
	 * cachea en `UF_OPTION`. Sin red o respuesta inválida MANTIENE el valor
	 * anterior (nunca fatal). Devuelve la caché resultante.
	 */
	public static function refresh_uf_value(): array
	{
		$current = self::uf_cached();
		if ( ! function_exists( 'wp_remote_get' ) ) {
			return $current;
		}

		$response = wp_remote_get( self::UF_ENDPOINT, array(
			'timeout' => 8,
			'headers' => array(
				'Accept'     => 'application/json',
				'User-Agent' => 'EstandarSuite/' . ( defined( 'ESTANDAR_SUITE_FLOW_VERSION' ) ? ESTANDAR_SUITE_FLOW_VERSION : 'flow' ) . ' (+https://estandar.cl)',
			),
		) );

		if ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) {
			return $current;
		}
		$code = function_exists( 'wp_remote_retrieve_response_code' ) ? (int) wp_remote_retrieve_response_code( $response ) : 200;
		if ( $code < 200 || $code >= 300 ) {
			return $current;
		}
		$body = function_exists( 'wp_remote_retrieve_body' ) ? (string) wp_remote_retrieve_body( $response ) : '';
		$data = is_string( $body ) && '' !== $body ? json_decode( $body, true ) : null;
		if ( ! is_array( $data ) ) {
			return $current;
		}

		$serie = ( isset( $data['serie'] ) && is_array( $data['serie'] ) ) ? $data['serie'] : array();
		$first = ( isset( $serie[0] ) && is_array( $serie[0] ) ) ? $serie[0] : array();
		$value = ( isset( $first['valor'] ) && is_numeric( $first['valor'] ) ) ? (float) $first['valor'] : 0.0;
		if ( $value <= 0 ) {
			return $current;
		}
		$date   = isset( $first['fecha'] ) ? substr( trim( (string) $first['fecha'] ), 0, 10 ) : date( 'Y-m-d' );
		$cached = array( 'value' => $value, 'date' => $date, 'source' => 'mindicador' );
		if ( function_exists( 'update_option' ) ) {
			update_option( self::UF_OPTION, $cached, false );
		}
		return $cached;
	}

	/** Callback del WP-Cron: refresca el UF. Guardado y nunca fatal. */
	public static function cron_refresh(): void
	{
		self::refresh_uf_value();
	}

	/** Programa el cron diario del UF (idempotente). Sin WP-Cron no fatal. */
	public static function schedule_uf_cron(): void
	{
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
			return;
		}
		if ( ! wp_next_scheduled( self::UF_CRON_HOOK ) ) {
			wp_schedule_event( time() + 3600, 'daily', self::UF_CRON_HOOK );
		}
	}

	/** Desprograma el cron del UF. Sin WP-Cron no fatal. */
	public static function unschedule_uf_cron(): void
	{
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::UF_CRON_HOOK );
		}
	}

	/**
	 * Datos del emisor para el documento (branding + firmante + pie legal).
	 *
	 * @param array $overrides Valores no vacíos que sobre-escriben los settings.
	 */
	public static function issuer( array $overrides = array() ): array
	{
		$s = self::all();

		$logo_url = (string) ( $s['logo_url'] ?? '' );
		if ( '' === $logo_url ) {
			$logo_id = (int) ( $s['company_logo_id'] ?? 0 );
			if ( $logo_id > 0 && function_exists( 'wp_get_attachment_image_url' ) ) {
				$logo_url = (string) wp_get_attachment_image_url( $logo_id, 'medium' );
			}
		}

		$issuer = array(
			'company_name'    => (string) ( $s['company_name'] ?? '' ),
			'company_website' => (string) ( $s['company_website'] ?? '' ),
			'company_phone'   => (string) ( $s['company_phone'] ?? '' ),
			'company_email'   => (string) ( $s['company_email'] ?? '' ),
			'company_address' => (string) ( $s['company_address'] ?? '' ),
			'primary_color'   => (string) ( $s['primary_color'] ?? '' ),
			'accent_color'    => (string) ( $s['accent_color'] ?? '' ),
			'font_family'     => (string) ( $s['font_family'] ?? '' ),
			'logo_url'        => $logo_url,
			'document_title'  => (string) ( $s['document_title'] ?? '' ),
			'authority_name'  => (string) ( $s['authority_name'] ?? '' ),
			'authority_role'  => (string) ( $s['authority_role'] ?? '' ),
			'authority_id'    => (string) ( $s['authority_id'] ?? '' ),
			'footer_legal'    => (string) ( $s['footer_legal'] ?? '' ),
		);

		foreach ( $overrides as $key => $value ) {
			if ( null === $value || '' === $value ) {
				continue;
			}
			$issuer[ $key ] = $value;
		}

		return $issuer;
	}

	/**
	 * URL de un adjunto por id (helper reutilizable, nunca fatal).
	 *
	 * Prefiere `wp_get_attachment_image_url` (tamaño `full`, calidad de
	 * impresión) y cae a `wp_get_attachment_url`. Sin WordPress o id inválido
	 * devuelve `''`.
	 */
	public static function attachment_url( $id ): string
	{
		$id = (int) $id;
		if ( $id <= 0 ) {
			return '';
		}
		if ( function_exists( 'wp_get_attachment_image_url' ) ) {
			$url = (string) wp_get_attachment_image_url( $id, 'full' );
			if ( '' !== $url ) {
				return $url;
			}
		}
		if ( function_exists( 'wp_get_attachment_url' ) ) {
			return (string) wp_get_attachment_url( $id );
		}
		return '';
	}

	/**
	 * Branding de la empresa (marca del emisor): nombre, logo, banda superior
	 * (`header_image_url`) y color.
	 *
	 * @return array{name:string,logo_url:string,header_image_url:string,color:string,website:string}
	 */
	public static function company_branding(): array
	{
		$s = self::all();

		$logo_url = (string) ( $s['logo_url'] ?? '' );
		if ( '' === $logo_url ) {
			$logo_url = self::attachment_url( $s['company_logo_id'] ?? 0 );
		}

		$header_url = trim( (string) ( $s['company_header_image_url'] ?? '' ) );
		if ( '' === $header_url ) {
			$header_url = self::attachment_url( $s['company_header_image_id'] ?? 0 );
		}

		return array(
			'name'             => (string) ( $s['company_name'] ?? '' ),
			'logo_url'         => $logo_url,
			'header_image_url' => $header_url,
			'color'            => (string) ( $s['primary_color'] ?? '' ),
			'website'          => (string) ( $s['company_website'] ?? '' ),
		);
	}

	/**
	 * Branding del pie de página fijo (`footer_image_url` o resuelto por id).
	 *
	 * @return array{image_url:string}
	 */
	public static function footer_branding(): array
	{
		$s   = self::all();
		$url = trim( (string) ( $s['footer_image_url'] ?? '' ) );
		if ( '' === $url ) {
			$url = self::attachment_url( $s['footer_image_id'] ?? 0 );
		}
		return array( 'image_url' => $url );
	}

	/**
	 * Branding de un proyecto: opción guardada o respaldo del registry/etiqueta.
	 *
	 * @param string $slug Slug del proyecto.
	 * @return array{name:string,logo_url:string,header_image_url:string,color:string,slug:string,images:array}
	 */
	public static function project_branding( string $slug ): array
	{
		$key      = self::slug_key( $slug );
		$projects = self::project_settings_all();

		// Prefiere los settings nuevos; si el slug aún no se migró, lee el mapa viejo.
		if ( '' !== $key && isset( $projects[ $key ] ) && is_array( $projects[ $key ] ) ) {
			$entry = $projects[ $key ];
		} else {
			$all   = self::project_branding_all();
			$entry = ( '' !== $key && isset( $all[ $key ] ) && is_array( $all[ $key ] ) ) ? $all[ $key ] : array();
		}

		$name = trim( (string) ( $entry['name'] ?? '' ) );
		if ( '' === $name ) {
			$label = '';
			if ( class_exists( 'Estandar\\Core\\Leads\\ProjectRegistry' )
				&& method_exists( 'Estandar\\Core\\Leads\\ProjectRegistry', 'label_for' ) ) {
				$label = trim( (string) \Estandar\Core\Leads\ProjectRegistry::label_for( $key ) );
			}
			$name = '' !== $label ? $label : ( '' !== $key ? ucfirst( $key ) : '' );
		}

		$logo_url = (string) ( $entry['logo_url'] ?? '' );
		if ( '' === $logo_url ) {
			$logo_url = self::attachment_url( $entry['logo_id'] ?? 0 );
		}

		$header_url = trim( (string) ( $entry['header_image_url'] ?? '' ) );
		if ( '' === $header_url ) {
			$header_url = self::attachment_url( $entry['header_image_id'] ?? 0 );
		}

		return array(
			'name'             => $name,
			'logo_url'         => $logo_url,
			'header_image_url' => $header_url,
			'color'            => (string) ( $entry['color'] ?? '' ),
			'slug'             => $key,
			'images'           => self::normalize_images( $entry['images'] ?? array() ),
		);
	}

	/** Branding completo del documento: `['company'=>..., 'project'=>..., 'footer'=>...]`. */
	public static function branding( string $slug ): array
	{
		return array(
			'company' => self::company_branding(),
			'project' => self::project_branding( $slug ),
			'footer'  => self::footer_branding(),
		);
	}

	/** Mapa completo de branding por proyecto (sanitizado, tolerante sin WP). */
	public static function project_branding_all(): array
	{
		$stored = array();
		if ( function_exists( 'get_option' ) ) {
			$option = get_option( self::PROJECT_BRANDING_OPTION, array() );
			$stored = is_array( $option ) ? $option : array();
		}

		$out = array();
		foreach ( $stored as $slug => $entry ) {
			$key = self::slug_key( (string) $slug );
			if ( '' === $key || ! is_array( $entry ) ) {
				continue;
			}
			$out[ $key ] = self::sanitize_branding( $entry );
		}
		return $out;
	}

	/** Guarda el mapa de branding por proyecto (sanitizado). Nunca fatal. */
	public static function save_project_branding( array $map ): void
	{
		$clean = array();
		foreach ( $map as $slug => $entry ) {
			$key = self::slug_key( (string) $slug );
			if ( '' === $key || ! is_array( $entry ) ) {
				continue;
			}
			$clean[ $key ] = self::sanitize_branding( $entry );
		}

		if ( function_exists( 'update_option' ) ) {
			update_option( self::PROJECT_BRANDING_OPTION, $clean, false );
		}
	}

	// ── Plantillas globales de documento ─────────────────────────────────────

	/**
	 * Plantillas por defecto. Hoy una sola (`tpl1`); el diseño vive en sus
	 * campos (fondo, imagen inferior y colores).
	 */
	public static function default_templates(): array
	{
		return array(
			'tpl1' => array(
				'id'             => 'tpl1',
				'name'           => 'Plantilla 1',
				'background_id'  => 0,
				'background_url' => '',
				'bottom_id'      => 0,
				'bottom_url'     => '',
				'primary_color'  => '',
				'accent_color'   => '',
			),
		);
	}

	/** Id de la plantilla por defecto (fallback de resolución). */
	public static function default_template_id(): string
	{
		return 'tpl1';
	}

	/** Mapa de plantillas (defaults + opción guardada, saneado). Siempre incluye `tpl1`. */
	public static function templates(): array
	{
		$defaults = self::default_templates();
		$stored   = array();
		if ( function_exists( 'get_option' ) ) {
			$option = get_option( self::TEMPLATES_OPTION, array() );
			$stored = is_array( $option ) ? $option : array();
		}

		$out = array();
		foreach ( $stored as $id => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			if ( '' === (string) ( $entry['id'] ?? '' ) ) {
				$entry['id'] = (string) $id;
			}
			$clean               = self::sanitize_template( $entry );
			$out[ $clean['id'] ] = $clean;
		}

		if ( ! isset( $out[ self::default_template_id() ] ) ) {
			$out = array_merge(
				array( self::default_template_id() => $defaults[ self::default_template_id() ] ),
				$out
			);
		}

		return $out;
	}

	/** Plantilla por id; si no existe cae a `tpl1` (nunca fatal). */
	public static function template( string $id ): array
	{
		$all = self::templates();
		$id  = self::slug_key( $id );
		if ( '' !== $id && isset( $all[ $id ] ) ) {
			return $all[ $id ];
		}
		return $all[ self::default_template_id() ];
	}

	/** Guarda el mapa de plantillas (saneado). Nunca fatal. */
	public static function save_templates( array $map ): void
	{
		$clean = array();
		foreach ( $map as $id => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			if ( '' === (string) ( $entry['id'] ?? '' ) ) {
				$entry['id'] = (string) $id;
			}
			$tpl               = self::sanitize_template( $entry );
			$clean[ $tpl['id'] ] = $tpl;
		}

		if ( function_exists( 'update_option' ) ) {
			update_option( self::TEMPLATES_OPTION, $clean, false );
		}
	}

	// ── Settings por proyecto (plantilla + datos + imágenes) ─────────────────

	/** Estructura completa (vacía) de los settings de un proyecto. */
	public static function default_project_settings(): array
	{
		return array(
			'template'         => self::default_template_id(),
			'name'             => '',
			'logo_id'          => 0,
			'logo_url'         => '',
			'color'            => '',
			'header_image_id'  => 0,
			'header_image_url' => '',
			'bottom_image_id'  => 0,
			'bottom_image_url' => '',
			'images'           => array(),
			'field_options'    => array(),
			'data'             => array(
				'project_name'    => '',
				'operator_name'   => '',
				'operator_role'   => '',
				'sales_room_name' => '',
				'sales_room_address' => '',
				'sales_room_phone' => '',
				'sales_room_email' => '',
				'sales_room_hours' => '',
				'document_title'  => '',
				'uf_value_clp'    => '',
				'property_value_uf' => '',
				'uf_source'       => '',
				'conditions'      => self::default_conditions(),
				'notes'           => self::default_notes(),
				'financing'       => array(
					'credit_percent'      => '',
					'annual_rate_percent' => '',
					'income_multiple'     => '',
					'terms_years'         => '',
				),
			),
		);
	}

	/** Settings de un proyecto (opción + defaults). Slug vacío → defaults. */
	public static function project_settings( string $slug ): array
	{
		$defaults = self::default_project_settings();
		$key      = self::slug_key( $slug );
		if ( '' === $key ) {
			return $defaults;
		}

		$all   = self::project_settings_all();
		$entry = ( isset( $all[ $key ] ) && is_array( $all[ $key ] ) ) ? $all[ $key ] : array();
		if ( empty( $entry ) ) {
			return $defaults;
		}

		$merged = $defaults;
		foreach ( $entry as $k => $v ) {
			if ( is_array( $v ) && isset( $merged[ $k ] ) && is_array( $merged[ $k ] ) ) {
				$merged[ $k ] = array_merge( $merged[ $k ], $v );
			} else {
				$merged[ $k ] = $v;
			}
		}
		return $merged;
	}

	/** Mapa completo de settings por proyecto (sanitizado). */
	public static function project_settings_all(): array
	{
		$stored = array();
		if ( function_exists( 'get_option' ) ) {
			$option = get_option( self::PROJECTS_OPTION, array() );
			$stored = is_array( $option ) ? $option : array();
		}

		$out = array();
		foreach ( $stored as $slug => $entry ) {
			$key = self::slug_key( (string) $slug );
			if ( '' === $key || ! is_array( $entry ) ) {
				continue;
			}
			$out[ $key ] = self::sanitize_project_settings( $entry );
		}
		return $out;
	}

	/**
	 * Guarda el mapa de settings por proyecto (saneado) y migra, de forma
	 * aditiva y una sola vez, el mapa antiguo de branding a `projects`.
	 */
	public static function save_project_settings( array $map ): void
	{
		$clean = array();
		foreach ( $map as $slug => $entry ) {
			$key = self::slug_key( (string) $slug );
			if ( '' === $key || ! is_array( $entry ) ) {
				continue;
			}
			$clean[ $key ] = self::sanitize_project_settings( $entry );
		}

		// Migración aditiva del mapa viejo: no pisa lo ya definido en projects.
		foreach ( self::project_branding_all() as $slug => $entry ) {
			if ( ! isset( $clean[ $slug ] ) ) {
				$clean[ $slug ] = self::project_settings_from_branding( $entry );
			}
		}

		if ( function_exists( 'update_option' ) ) {
			update_option( self::PROJECTS_OPTION, $clean, false );
		}
	}

	/**
	 * Guarda/actualiza UN proyecto sin perder el resto del mapa. Fusiona la
	 * entrada (no vacía) sobre la existente y reescribe el mapa completo vía
	 * `save_project_settings` (que sanea). Aditivo y tolerante.
	 */
	public static function save_project_entry( string $slug, array $entry ): void
	{
		$key = self::slug_key( $slug );
		if ( '' === $key ) {
			return;
		}
		$all     = self::project_settings_all();
		$current = ( isset( $all[ $key ] ) && is_array( $all[ $key ] ) ) ? $all[ $key ] : self::default_project_settings();
		$merged  = $current;
		foreach ( $entry as $k => $v ) {
			$merged[ $k ] = $v;
		}
		$all[ $key ] = $merged;
		self::save_project_settings( $all );
	}

	/**
	 * PURE: extrae los datos "prefijables" de un proyecto desde un documento
	 * canónico (editor de plantilla). Solo proyecta lo que vive en el proyecto:
	 * datos del proyecto/sala, UF, título, condiciones, notas y financiamiento.
	 * No persiste datos de emisor/cliente (no son del proyecto).
	 */
	public static function project_data_from_document( array $doc ): array
	{
		$project   = ( isset( $doc['project'] ) && is_array( $doc['project'] ) ) ? $doc['project'] : array();
		$meta      = ( isset( $doc['meta'] ) && is_array( $doc['meta'] ) ) ? $doc['meta'] : array();
		$pricing   = ( isset( $doc['pricing'] ) && is_array( $doc['pricing'] ) ) ? $doc['pricing'] : array();
		$financing = ( isset( $doc['financing'] ) && is_array( $doc['financing'] ) ) ? $doc['financing'] : array();
		$conditions = $doc['conditions'] ?? array();
		$operator   = ( isset( $doc['operator'] ) && is_array( $doc['operator'] ) ) ? $doc['operator'] : array();

		return array(
			'project_name'       => (string) ( $project['name'] ?? '' ),
			'operator_name'      => (string) ( $operator['name'] ?? '' ),
			'operator_role'      => (string) ( $operator['role'] ?? '' ),
			'sales_room_name'    => (string) ( $project['sales_room_name'] ?? '' ),
			'sales_room_address' => (string) ( $project['sales_room_address'] ?? '' ),
			'sales_room_phone'   => (string) ( $project['sales_room_phone'] ?? '' ),
			'sales_room_email'   => (string) ( $project['sales_room_email'] ?? '' ),
			'sales_room_hours'   => (string) ( $project['sales_room_hours'] ?? '' ),
			'document_title'     => (string) ( $meta['document_title'] ?? $meta['title'] ?? '' ),
			'uf_value_clp'       => (string) ( $pricing['uf_value_clp'] ?? '' ),
			'property_value_uf'  => (string) ( $pricing['property_value_uf'] ?? '' ),
			'uf_source'          => (string) ( $pricing['uf_source'] ?? '' ),
			'conditions'         => is_array( $conditions ) ? $conditions : preg_split( '/\r\n|\r|\n/', (string) $conditions ),
			'notes'              => (string) ( $doc['notes'] ?? '' ),
			'financing'          => $financing,
		);
	}

	/** Plantilla elegida por un proyecto (o `tpl1` si no define/`slug` inválido). */
	public static function template_for_project( string $slug ): array
	{
		$settings = self::project_settings( $slug );
		$id       = (string) ( $settings['template'] ?? '' );
		return self::template( '' !== $id ? $id : self::default_template_id() );
	}

	/**
	 * Datos efectivos del proyecto: `project_settings()['data']` sobre
	 * `all()` (lo del proyecto manda; lo global rellena huecos).
	 */
	public static function effective_data( string $slug ): array
	{
		$out  = self::all();
		$data = self::project_settings( $slug )['data'];
		if ( ! is_array( $data ) ) {
			return $out;
		}

		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				if ( ! empty( $value ) ) {
					$out[ $key ] = $value;
				} elseif ( ! array_key_exists( $key, $out ) ) {
					$out[ $key ] = $value;
				}
				continue;
			}
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				$out[ $key ] = $value;
			} elseif ( ! array_key_exists( $key, $out ) ) {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	/** Convierte una entrada del mapa viejo de branding en settings de proyecto. */
	private static function project_settings_from_branding( array $entry ): array
	{
		$settings = self::default_project_settings();
		$settings['name']             = (string) ( $entry['name'] ?? '' );
		$settings['logo_id']          = (int) ( $entry['logo_id'] ?? 0 );
		$settings['logo_url']         = (string) ( $entry['logo_url'] ?? '' );
		$settings['color']            = (string) ( $entry['color'] ?? '' );
		$settings['header_image_id']  = (int) ( $entry['header_image_id'] ?? 0 );
		$settings['header_image_url'] = (string) ( $entry['header_image_url'] ?? '' );
		$settings['images']           = (array) ( $entry['images'] ?? array() );
		return self::sanitize_project_settings( $settings );
	}

	/** Saneo completo de los settings de un proyecto. */
	private static function sanitize_project_settings( array $entry ): array
	{
		$template = self::slug_key( (string) ( $entry['template'] ?? '' ) );
		if ( '' === $template ) {
			$template = self::default_template_id();
		}

		$name = trim( (string) ( $entry['name'] ?? '' ) );
		if ( '' !== $name && function_exists( 'sanitize_text_field' ) ) {
			$name = sanitize_text_field( $name );
		}

		return array(
			'template'         => $template,
			'name'             => $name,
			'logo_id'          => self::absint( $entry['logo_id'] ?? 0 ),
			'logo_url'         => self::sanitize_url( $entry['logo_url'] ?? '' ),
			'color'            => self::sanitize_color( $entry['color'] ?? '' ),
			'header_image_id'  => self::absint( $entry['header_image_id'] ?? 0 ),
			'header_image_url' => self::sanitize_url( $entry['header_image_url'] ?? '' ),
			'bottom_image_id'  => self::absint( $entry['bottom_image_id'] ?? 0 ),
			'bottom_image_url' => self::sanitize_url( $entry['bottom_image_url'] ?? '' ),
			'images'           => self::sanitize_images( $entry['images'] ?? array() ),
			'field_options'    => self::sanitize_field_options( $entry['field_options'] ?? array() ),
			'data'             => self::sanitize_project_data( $entry['data'] ?? array() ),
		);
	}

	/** Saneo del grupo `data` de un proyecto. */
	private static function sanitize_project_data( $raw ): array
	{
		$raw   = is_array( $raw ) ? $raw : array();
		$clean = self::default_project_settings()['data'];

		foreach ( array( 'project_name', 'operator_name', 'operator_role', 'sales_room_name', 'sales_room_address', 'sales_room_phone', 'sales_room_email', 'sales_room_hours', 'document_title', 'uf_value_clp', 'property_value_uf', 'operator_email', 'uf_source' ) as $key ) {
			$value = trim( (string) ( $raw[ $key ] ?? '' ) );
			if ( '' !== $value && function_exists( 'sanitize_text_field' ) ) {
				$value = sanitize_text_field( $value );
			}
			$clean[ $key ] = $value;
		}

		$notes = (string) ( $raw['notes'] ?? '' );
		if ( function_exists( 'sanitize_textarea_field' ) ) {
			$notes = sanitize_textarea_field( $notes );
		}
		$clean['notes'] = trim( $notes );
		if ( '' === $clean['notes'] ) {
			$clean['notes'] = self::default_notes();
		}

		$clean['conditions'] = self::sanitize_text_list( $raw['conditions'] ?? array() );
		if ( empty( $clean['conditions'] ) ) {
			$clean['conditions'] = self::default_conditions();
		}

		$fin = isset( $raw['financing'] ) && is_array( $raw['financing'] ) ? $raw['financing'] : array();
		$clean['financing'] = array();
		foreach ( array( 'credit_percent', 'annual_rate_percent', 'income_multiple', 'terms_years' ) as $key ) {
			if ( 'terms_years' === $key ) {
				$years = $fin[ $key ] ?? array();
				if ( is_string( $years ) ) {
					$years = preg_split( '/[,\s;]+/', $years );
				}
				$years = is_array( $years ) ? array_map( 'intval', $years ) : array();
				$years = array_values( array_filter( $years, function ( $y ) {
					return $y > 0;
				} ) );
				$clean['financing'][ $key ] = $years;
				continue;
			}
			$clean['financing'][ $key ] = trim( (string) ( $fin[ $key ] ?? '' ) );
		}

		return $clean;
	}

	/** Sanea una plantilla de documento (ids, colores, URLs). */
	private static function sanitize_template( array $entry ): array
	{
		$id = self::slug_key( (string) ( $entry['id'] ?? '' ) );
		if ( '' === $id ) {
			$id = self::default_template_id();
		}

		$name = trim( (string) ( $entry['name'] ?? '' ) );
		if ( '' !== $name && function_exists( 'sanitize_text_field' ) ) {
			$name = sanitize_text_field( $name );
		}

		return array(
			'id'             => $id,
			'name'           => $name,
			'background_id'  => self::absint( $entry['background_id'] ?? 0 ),
			'background_url' => self::sanitize_url( $entry['background_url'] ?? '' ),
			'bottom_id'      => self::absint( $entry['bottom_id'] ?? 0 ),
			'bottom_url'     => self::sanitize_url( $entry['bottom_url'] ?? '' ),
			'primary_color'  => self::sanitize_color( $entry['primary_color'] ?? '' ),
			'accent_color'   => self::sanitize_color( $entry['accent_color'] ?? '' ),
		);
	}

	/** Lista de textos saneada (acepta array o string con una línea por ítem). */
	private static function sanitize_text_list( $raw ): array
	{
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/\r\n|\r|\n/', $raw );
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}
			$line = trim( (string) $item );
			if ( '' === $line ) {
				continue;
			}
			if ( function_exists( 'sanitize_text_field' ) ) {
				$line = sanitize_text_field( $line );
			}
			$out[] = $line;
		}
		return $out;
	}

	/** Sanea un color hex (`#rgb`/`#rrggbb`/`#rrggbbaa`); inválido → ''. */
	private static function sanitize_color( $value ): string
	{
		$color = trim( (string) $value );
		if ( '' === $color ) {
			return '';
		}
		if ( ! preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $color ) ) {
			return '';
		}
		return $color;
	}

	/** Entero no negativo (tolerante sin `absint`). */
	private static function absint( $value ): int
	{
		if ( function_exists( 'absint' ) ) {
			return (int) absint( $value );
		}
		return max( 0, (int) $value );
	}

	/** Normaliza un campo de branding (`name`/logos/`color`/`header_image`/`images`). */
	private static function sanitize_branding( array $entry ): array
	{
		$name = isset( $entry['name'] ) ? trim( (string) $entry['name'] ) : '';
		if ( '' !== $name && function_exists( 'sanitize_text_field' ) ) {
			$name = sanitize_text_field( $name );
		}

		$logo_id  = isset( $entry['logo_id'] ) ? (int) $entry['logo_id'] : 0;
		$logo_url = self::sanitize_url( $entry['logo_url'] ?? '' );

		$color = isset( $entry['color'] ) ? trim( (string) $entry['color'] ) : '';
		if ( '' !== $color && ! preg_match( '/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $color ) ) {
			$color = '';
		}

		return array(
			'name'             => $name,
			'logo_id'          => $logo_id,
			'logo_url'         => $logo_url,
			'color'            => $color,
			'header_image_id'  => isset( $entry['header_image_id'] ) ? (int) $entry['header_image_id'] : 0,
			'header_image_url' => self::sanitize_url( $entry['header_image_url'] ?? '' ),
			'images'           => self::sanitize_images( $entry['images'] ?? array() ),
		);
	}

	/** Lista de imágenes de proyecto saneada: `[['id'=>int,'url'=>string,'caption'=>string], ...]`. */
	private static function sanitize_images( $raw ): array
	{
		$items = self::raw_list( $raw );
		$out   = array();
		foreach ( $items as $raw_item ) {
			if ( is_string( $raw_item ) ) {
				$url = self::sanitize_url( $raw_item );
				if ( '' !== $url ) {
					$out[] = array( 'id' => 0, 'url' => $url, 'caption' => '' );
				}
				continue;
			}
			if ( ! is_array( $raw_item ) ) {
				continue;
			}
			$id      = isset( $raw_item['id'] ) ? (int) $raw_item['id'] : 0;
			$url     = self::sanitize_url( $raw_item['url'] ?? '' );
			$caption = isset( $raw_item['caption'] ) ? trim( (string) $raw_item['caption'] ) : '';
			if ( '' !== $caption && function_exists( 'sanitize_text_field' ) ) {
				$caption = sanitize_text_field( $caption );
			}
			if ( '' === $url && $id > 0 ) {
				$url = self::attachment_url( $id );
			}
			if ( $id > 0 || '' !== $url ) {
				$out[] = array( 'id' => $id, 'url' => $url, 'caption' => $caption );
			}
		}
		// Límite duro de la galería del proyecto (hasta 6).
		return array_slice( $out, 0, self::MAX_PROJECT_IMAGES );
	}

	/**
	 * Sanea una galería recibida de fuera (POST/JSON): misma regla que la de
	 * settings (`[['id','url','caption'], ...]`, máx. 6). Público para que el
	 * editor de cotización comparta una única fuente de saneo.
	 */
	public static function sanitize_project_images( $raw ): array
	{
		return self::sanitize_images( $raw );
	}

	/** Resuelve imágenes de proyecto a `[['url'=>string,'caption'=>string], ...]` para el documento. */
	private static function normalize_images( $raw ): array
	{
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();
		foreach ( $raw as $item ) {
			if ( is_string( $item ) ) {
				$url = self::sanitize_url( $item );
				if ( '' !== $url ) {
					$out[] = array( 'url' => $url, 'caption' => '' );
				}
				continue;
			}
			if ( ! is_array( $item ) ) {
				continue;
			}
			$url = self::sanitize_url( $item['url'] ?? '' );
			if ( '' === $url ) {
				$url = self::attachment_url( $item['id'] ?? 0 );
			}
			if ( '' === $url ) {
				continue;
			}
			$out[] = array(
				'url'     => $url,
				'caption' => isset( $item['caption'] ) ? (string) $item['caption'] : '',
			);
		}
		return $out;
	}

	/** Saneo de URL con fallback tolerante (sin WP devuelve el string trimming). */
	private static function sanitize_url( $url ): string
	{
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		if ( function_exists( 'esc_url_raw' ) ) {
			return (string) esc_url_raw( $url );
		}
		return $url;
	}

	/** Normaliza `$raw` a lista (array o JSON string). */
	private static function raw_list( $raw ): array
	{
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}
		return is_array( $raw ) ? $raw : array();
	}

	/** Clave normalizada de slug (sin WP usa fallback propio). */
	private static function slug_key( string $slug ): string
	{
		$slug = trim( $slug );
		if ( '' === $slug ) {
			return '';
		}
		$slug = strtolower( $slug );
		if ( function_exists( 'sanitize_title' ) ) {
			return (string) sanitize_title( $slug );
		}
		$slug = (string) preg_replace( '/[^a-z0-9\-_]/', '-', $slug );
		$slug = (string) preg_replace( '/-+/', '-', $slug );
		return trim( $slug, '-' );
	}

	/** Datos de correo del módulo (remitente, asunto, copias). */
	public static function email(): array
	{
		$s = self::all();
		return array(
			'sender_name'      => (string) ( $s['sender_name'] ?? '' ),
			'sender_email'     => (string) ( $s['sender_email'] ?? '' ),
			'subject_template' => (string) ( $s['email_subject_template'] ?? '' ),
			'cc'               => (string) ( $s['email_cc'] ?? '' ),
			'bcc'              => (string) ( $s['email_bcc'] ?? '' ),
			'operator_copy'    => (string) ( $s['operator_copy'] ?? '' ),
		);
	}

	/** Condiciones como lista de líneas (trim, sin vacíos). */
	public static function conditions_lines(): array
	{
		$raw = (string) ( self::all()['conditions'] ?? '' );
		if ( '' === trim( $raw ) ) {
			return array();
		}
		$parts = preg_split( '/\r\n|\r|\n/', $raw );
		$out   = array();
		foreach ( (array) $parts as $line ) {
			$line = trim( (string) $line );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return $out;
	}

	/** Defaults de financiamiento (perfil inmobiliario). */
	public static function financing_defaults(): array
	{
		$s   = self::all();
		$out = array(
			'credit_percent'      => $s['credit_percent'] ?? '',
			'annual_rate_percent' => $s['annual_rate_percent'] ?? '',
			'income_multiple'     => $s['income_multiple'] ?? '',
		);
		if ( array_key_exists( 'terms_years', $s ) ) {
			$out['terms_years'] = $s['terms_years'];
		}
		return $out;
	}

	/** Días de validez (entero > 0). Vacío/0 → 15. */
	public static function validity_days(): int
	{
		$days = (int) ( self::all()['validity_days'] ?? 0 );
		return $days > 0 ? $days : 15;
	}

	/**
	 * Asunto interpolado del correo: `{quote_number}`, `{title}`, `{site_name}`.
	 * Plantilla vacía → `Cotización {quote_number}`.
	 */
	public static function subject_for( array $doc ): string
	{
		$template = trim( (string) ( self::all()['email_subject_template'] ?? '' ) );
		if ( '' === $template ) {
			$template = 'Cotización {quote_number}';
		}

		$quote_number = (string) self::val( $doc, 'meta.quote_number', self::val( $doc, 'quote_number', '' ) );
		$title        = (string) self::val( $doc, 'meta.title', self::val( $doc, 'title', '' ) );

		$site_name = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '';
		if ( '' === $site_name ) {
			$site_name = (string) self::val( $doc, 'issuer.company_name', '' );
		}

		$subject = trim( strtr( $template, array(
			'{quote_number}' => $quote_number,
			'{title}'        => $title,
			'{site_name}'    => $site_name,
		) ) );

		return '' !== $subject ? $subject : 'Cotización ' . $quote_number;
	}

	/** Cuerpo configurable del correo (texto plano/HTML breve). Vacío → ''. */
	public static function email_body(): string
	{
		return trim( (string) ( self::all()['email_body'] ?? '' ) );
	}

	/** Cuerpo por defecto cuando no hay `email_body` configurado. */
	private static function default_email_body(): string
	{
		return "Estimado(a) {client_name},\n\nAdjuntamos su cotización {quote_number}.\n\nSaludos,\n{company_name}";
	}

	/**
	 * Interpola el cuerpo del correo con datos del documento. Tolerante a
	 * faltantes (placeholder sin valor → cadena vacía) y nunca fataliza.
	 * Placeholders: `{client_name}`, `{quote_number}`, `{company_name}`,
	 * `{valid_until}`, `{project_name}`.
	 */
	public static function render_email_body( array $doc ): string
	{
		$template = self::email_body();
		if ( '' === $template ) {
			$template = self::default_email_body();
		}

		$client_name = trim( (string) self::val( $doc, 'client.name', '' ) );
		if ( '' === $client_name ) {
			$client_name = 'cliente';
		}

		$quote_number = (string) self::val( $doc, 'meta.quote_number', self::val( $doc, 'quote_number', '' ) );

		$company_name = (string) self::val( $doc, 'issuer.company_name', '' );
		if ( '' === $company_name ) {
			$company_name = (string) self::val( $doc, 'branding.company.name', '' );
		}
		if ( '' === $company_name && function_exists( 'get_bloginfo' ) ) {
			$company_name = (string) get_bloginfo( 'name' );
		}

		$valid_until  = (string) self::val( $doc, 'meta.valid_until', self::val( $doc, 'valid_until', '' ) );
		$project_name = (string) self::val( $doc, 'project.name', '' );

		return trim( strtr( $template, array(
			'{client_name}'  => $client_name,
			'{quote_number}' => $quote_number,
			'{company_name}' => $company_name,
			'{valid_until}'  => $valid_until,
			'{project_name}' => $project_name,
		) ) );
	}

	// ── Helpers PURE ────────────────────────────────────────────────────────

	/** Resuelve una clave punteada sobre un array (soporta claves planas). */
	private static function val( array $data, string $key, $default = '' )
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
}
