<?php

namespace Estandar\Flow;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Flow admin page — pipeline viewer, transition form, customers, quotes, and
 * adoption import/rollback. Mutations behind nonce + capability.
 */
class Admin
{
	public static function init(): void
	{
		add_action( 'estandar_suite_admin_register', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_estandar_suite_flow_transition', array( __CLASS__, 'handle_transition' ) );
		add_action( 'admin_post_estandar_suite_flow_adoption_import', array( __CLASS__, 'handle_import' ) );
		add_action( 'admin_post_estandar_suite_flow_adoption_rollback', array( __CLASS__, 'handle_rollback' ) );
		add_action( 'admin_post_estandar_suite_flow_save_feature', array( __CLASS__, 'handle_save_feature' ) );
		add_action( 'admin_post_estandar_suite_flow_save_quotes_settings', array( __CLASS__, 'handle_save_quotes_settings' ) );
		// Branding por proyecto (mapa slug => marca), opción propia.
		add_action( 'admin_post_estandar_suite_flow_save_project_branding', array( __CLASS__, 'handle_save_project_branding' ) );
		// Plantillas globales de documento y settings por proyecto (rediseño aditivo).
		add_action( 'admin_post_estandar_suite_flow_save_quotes_templates', array( __CLASS__, 'handle_save_quotes_templates' ) );
		add_action( 'admin_post_estandar_suite_flow_save_project_settings', array( __CLASS__, 'handle_save_project_settings' ) );
		// M11: operación real de cotizaciones v2 desde el admin (nonce + cap).
		add_action( 'admin_post_estandar_suite_flow_quote_save', array( __CLASS__, 'handle_quote_save' ) );
		add_action( 'admin_post_estandar_suite_flow_quote_status', array( __CLASS__, 'handle_quote_status' ) );
		add_action( 'admin_post_estandar_suite_flow_quote_send', array( __CLASS__, 'handle_quote_send' ) );
		add_action( 'admin_post_estandar_suite_flow_quote_duplicate', array( __CLASS__, 'handle_quote_duplicate' ) );
		add_action( 'admin_post_estandar_suite_flow_quote_pdf', array( __CLASS__, 'handle_quote_pdf' ) );
		// Wizard "Proyectos" (tab=proyectos): pasos de header/bottom, plantilla,
		// guardado del borrador y previsualización/streaming del PDF.
		add_action( 'admin_post_estandar_suite_flow_proj_header_bottom', array( __CLASS__, 'handle_proj_header_bottom' ) );
		add_action( 'admin_post_estandar_suite_flow_proj_save_template', array( __CLASS__, 'handle_proj_save_template' ) );
		add_action( 'admin_post_estandar_suite_flow_proj_quote_save', array( __CLASS__, 'handle_proj_quote_save' ) );
		add_action( 'admin_post_estandar_suite_flow_quote_preview', array( __CLASS__, 'handle_quote_preview' ) );
		// Paso 4: alta de lead del proyecto en la base general (`es_leads`).
		add_action( 'admin_post_estandar_suite_flow_proj_lead_create', array( __CLASS__, 'handle_proj_lead_create' ) );
		// Wizard "Proyectos": prepara el borrador del paso 5 en `admin_init`,
		// ANTES de que WordPress emita el header de admin (si no, el redirect
		// falla y la página queda en blanco).
		add_action( 'admin_init', array( __CLASS__, 'maybe_prepare_projects' ) );
		// Media (wp.media) solo en la pantalla del wizard.
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_projects_assets' ) );
		// Fallback genérico: los formularios internos del editor (lista de
		// cotizaciones del lead) no llevan campo `action`; se enrutan aquí por
		// `lp_action`. Inerte para cualquier otro POST sin `action`.
		add_action( 'admin_post', array( __CLASS__, 'handle_quote_post' ) );
		// Capability propia de cotizaciones (M10): concedida a `manage_options`.
		add_filter( 'map_meta_cap', array( __CLASS__, 'map_meta_cap' ), 10, 4 );
	}

	/** Capacidad real para operar cotizaciones (admin o cap propia). */
	public static function can_manage_quotes(): bool
	{
		return current_user_can( 'manage_options' ) || current_user_can( 'estandar_manage_quotes' );
	}

	/** Mapea `estandar_manage_quotes` a `manage_options` (aditivo). */
	public static function map_meta_cap( $caps, $cap, $user_id, $args )
	{
		if ( 'estandar_manage_quotes' === $cap ) {
			return array( 'manage_options' );
		}
		return $caps;
	}

	/** Condiciones por defecto (texto, una por línea) para los settings globales. */
	private static function default_conditions_text(): string
	{
		if ( class_exists( 'Estandar\\Flow\\Quotes\\Settings' ) && method_exists( 'Estandar\\Flow\\Quotes\\Settings', 'default_conditions' ) ) {
			return implode( "\n", \Estandar\Flow\Quotes\Settings::default_conditions() );
		}
		return 'La tasa de interés es sólo referencial. Las instituciones financieras cursan los créditos con la tasa vigente a la fecha de escrituración en que se formalice la compraventa.'
			. "\nValidez cotización 7 días corridos a contar de esta fecha, y no constituye reserva de compra."
			. "\nSerán de cargo exclusivo del comprador, todos los gastos que genere esta operación, tales como los gastos asociados al crédito escrituración notariales y de inscripción en el Conservador de Bienes Raíces.";
	}

	/** Quotes settings grouped (rescata estandar-quotes defaults). */
	public static function quotes_settings_groups(): array
	{
		return array(
			'documento' => array(
				'company_name'    => array( 'type' => 'text', 'label' => 'Nombre de la empresa', 'default' => '' ),
				'company_website' => array( 'type' => 'text', 'label' => 'Sitio web', 'default' => '' ),
				'company_phone'   => array( 'type' => 'text', 'label' => 'Teléfono', 'default' => '' ),
				'company_email'   => array( 'type' => 'text', 'label' => 'Email', 'default' => '' ),
				'company_address' => array( 'type' => 'text', 'label' => 'Dirección', 'default' => '' ),
				'primary_color'   => array( 'type' => 'text', 'label' => 'Color primario', 'default' => '#1d2327' ),
				'accent_color'    => array( 'type' => 'text', 'label' => 'Color de acento', 'default' => '#2271b1' ),
				'document_title'  => array( 'type' => 'text', 'label' => 'Título del documento', 'default' => 'Cotización' ),
				'conditions'      => array( 'type' => 'text', 'label' => 'Condiciones', 'default' => self::default_conditions_text() ),
				'footer_legal'    => array( 'type' => 'text', 'label' => 'Pie legal', 'default' => '' ),
				'renderer'        => array( 'type' => 'select', 'label' => 'Renderizador PDF', 'options' => array( 'dompdf' => 'dompdf' ), 'default' => 'dompdf' ),
			),
			'finanzas' => array(
				'validity_days'       => array( 'type' => 'text', 'label' => 'Validez (días)', 'default' => '7' ),
				'uf_source'           => array( 'type' => 'select', 'label' => 'Fuente de UF', 'options' => array( 'manual' => 'Manual', 'api' => 'API' ), 'default' => 'manual' ),
				'uf_value_clp'        => array( 'type' => 'text', 'label' => 'Valor UF (CLP)', 'default' => '' ),
				'annual_rate_percent' => array( 'type' => 'text', 'label' => 'Tasa anual (%)', 'default' => '' ),
				'credit_percent'      => array( 'type' => 'text', 'label' => 'Crédito (%)', 'default' => '80' ),
				'income_multiple'     => array( 'type' => 'text', 'label' => 'Múltiplo de ingreso', 'default' => '4' ),
			),
			'branding' => array(
				'company_name'             => array( 'type' => 'text', 'label' => 'Nombre de la empresa (marca)', 'default' => '' ),
				'company_logo_id'          => array( 'type' => 'text', 'label' => 'Logo empresa (ID de adjunto)', 'default' => '' ),
				'logo_url'                 => array( 'type' => 'text', 'label' => 'Logo (URL directa)', 'default' => '' ),
				'company_header_image_id'  => array( 'type' => 'text', 'label' => 'Banda empresa (ID de adjunto)', 'desc' => 'Imagen de la banda superior (calidad impresión, jpg/png).', 'default' => '' ),
				'company_header_image_url' => array( 'type' => 'text', 'label' => 'Banda empresa (URL directa)', 'default' => '' ),
				'primary_color'            => array( 'type' => 'text', 'label' => 'Color de marca', 'default' => '#1d2327' ),
				'footer_image_id'          => array( 'type' => 'text', 'label' => 'Pie de página (ID de adjunto)', 'desc' => 'Branding de estandar.cl fijo en todas las páginas.', 'default' => '' ),
				'footer_image_url'         => array( 'type' => 'text', 'label' => 'Pie de página (URL directa)', 'default' => '' ),
			),
			'sala_ventas' => array(
				'sales_room_name'    => array( 'type' => 'text', 'label' => 'Nombre sala de ventas', 'default' => '' ),
				'sales_room_address' => array( 'type' => 'text', 'label' => 'Dirección', 'default' => '' ),
				'sales_room_phone'   => array( 'type' => 'text', 'label' => 'Teléfono', 'default' => '' ),
				'sales_room_email'   => array( 'type' => 'text', 'label' => 'Email', 'default' => '' ),
				'sales_room_hours'   => array( 'type' => 'text', 'label' => 'Horario', 'default' => '' ),
			),
			'autoridad' => array(
				'authority_name' => array( 'type' => 'text', 'label' => 'Nombre del firmante', 'default' => '' ),
				'authority_role' => array( 'type' => 'text', 'label' => 'Cargo', 'default' => '' ),
				'authority_id'   => array( 'type' => 'text', 'label' => 'Identificación', 'default' => '' ),
				'operator_copy'  => array( 'type' => 'text', 'label' => 'Copia al operador (email)', 'default' => '' ),
			),
			'email' => array(
				'sender_name'          => array( 'type' => 'text', 'label' => 'Remitente (nombre)', 'default' => '' ),
				'sender_email'         => array( 'type' => 'text', 'label' => 'Remitente (email)', 'default' => '' ),
				'email_subject_template' => array( 'type' => 'text', 'label' => 'Asunto', 'default' => 'Cotización' ),
				'email_body'           => array( 'type' => 'textarea', 'label' => 'Cuerpo del correo', 'rows' => 7, 'desc' => 'Texto del correo (el PDF va adjunto). Placeholders: {client_name}, {quote_number}, {company_name}, {valid_until}, {project_name}.', 'default' => "Estimado(a) {client_name},\n\nAdjuntamos su cotización {quote_number}.\n\nSaludos,\n{company_name}" ),
				'email_cc'             => array( 'type' => 'text', 'label' => 'CC', 'default' => '' ),
				'email_bcc'            => array( 'type' => 'text', 'label' => 'CCO', 'default' => '' ),
			),
		);
	}

	/** Quotes settings flattened (CLI). */
	public static function quotes_settings_fields(): array
	{
		$out = array();
		foreach ( self::quotes_settings_groups() as $g => $fs ) { $out = array_merge( $out, $fs ); }
		return $out;
	}

	/** admin_post handler: save quotes settings group (merge). */
	public static function handle_save_quotes_settings(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_flow_save_quotes_settings' );
		$group  = sanitize_key( (string) ( $_POST['group'] ?? '' ) );
		$groups = self::quotes_settings_groups();
		if ( ! isset( $groups[ $group ] ) ) {
			wp_die( 'Grupo desconocido.' );
		}
		$cur = get_option( 'estandar_suite_flow_quotes_settings', array() );
		$cur = is_array( $cur ) ? $cur : array();
		$raw = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? $_POST['settings'] : array();
		$cur = array_merge( $cur, \Estandar\Core\Admin::sanitize_feature_fields( $groups[ $group ], $raw ) );
		update_option( 'estandar_suite_flow_quotes_settings', $cur, false );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-flow&tab=config&sub=quotes&ss=' . $group . '&quotes_saved=1' ) );
		exit;
	}

	/** admin_post handler: guardar branding por proyecto (nonce + cap). */
	public static function handle_save_project_branding(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_flow_save_project_branding' );

		$raw = isset( $_POST['project_branding'] ) && is_array( $_POST['project_branding'] )
			? wp_unslash( $_POST['project_branding'] )
			: array();

		$map = array();
		foreach ( $raw as $slug => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$color = isset( $entry['color'] ) ? (string) $entry['color'] : '';
			if ( '' !== $color && function_exists( 'sanitize_hex_color' ) ) {
				$color = (string) sanitize_hex_color( $color );
			}

			$map[ (string) $slug ] = array(
				'name'             => isset( $entry['name'] ) ? sanitize_text_field( (string) $entry['name'] ) : '',
				'logo_id'          => isset( $entry['logo_id'] ) ? (int) $entry['logo_id'] : 0,
				'logo_url'         => isset( $entry['logo_url'] ) ? esc_url_raw( (string) $entry['logo_url'] ) : '',
				'color'            => $color,
				'header_image_id'  => isset( $entry['header_image_id'] ) ? (int) $entry['header_image_id'] : 0,
				'header_image_url' => isset( $entry['header_image_url'] ) ? esc_url_raw( (string) $entry['header_image_url'] ) : '',
				'images'           => self::collect_project_images( $entry ),
			);
		}

		if ( class_exists( 'Estandar\\Flow\\Quotes\\Settings' ) ) {
			\Estandar\Flow\Quotes\Settings::save_project_branding( $map );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-flow&tab=config&sub=quotes&ss=branding&project_branding_saved=1' ) );
		exit;
	}

	/** Normaliza las imágenes de un proyecto recibidas del formulario. */
	private static function collect_project_images( array $entry ): array
	{
		$raw = isset( $entry['images'] ) && is_array( $entry['images'] ) ? $entry['images'] : array();
		$out = array();
		foreach ( $raw as $item ) {
			if ( is_string( $item ) ) {
				$url = function_exists( 'esc_url_raw' ) ? esc_url_raw( $item ) : $item;
				if ( '' !== trim( (string) $url ) ) {
					$out[] = array( 'id' => 0, 'url' => (string) $url, 'caption' => '' );
				}
				continue;
			}
			if ( ! is_array( $item ) ) {
				continue;
			}
			$id      = isset( $item['id'] ) ? (int) $item['id'] : 0;
			$url     = isset( $item['url'] ) ? ( function_exists( 'esc_url_raw' ) ? esc_url_raw( (string) $item['url'] ) : (string) $item['url'] ) : '';
			$caption = isset( $item['caption'] ) ? sanitize_text_field( (string) $item['caption'] ) : '';
			if ( $id > 0 || '' !== trim( (string) $url ) ) {
				$out[] = array( 'id' => $id, 'url' => (string) $url, 'caption' => $caption );
			}
		}
		return $out;
	}

	/** admin_post handler: guardar plantillas globales de documento (nonce + cap). */
	public static function handle_save_quotes_templates(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_flow_save_quotes_templates' );
		if ( ! class_exists( 'Estandar\\Flow\\Quotes\\Settings' ) ) {
			wp_die( 'Módulo de cotizaciones no disponible.' );
		}
		$raw = isset( $_POST['quotes_templates'] ) && is_array( $_POST['quotes_templates'] )
			? wp_unslash( $_POST['quotes_templates'] )
			: array();
		\Estandar\Flow\Quotes\Settings::save_templates( $raw );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-flow&tab=config&sub=quotes&ss=templates&templates_saved=1' ) );
		exit;
	}

	/** admin_post handler: guardar settings por proyecto (nonce + cap). */
	public static function handle_save_project_settings(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_flow_save_project_settings' );
		if ( ! class_exists( 'Estandar\\Flow\\Quotes\\Settings' ) ) {
			wp_die( 'Módulo de cotizaciones no disponible.' );
		}
		$raw = isset( $_POST['project_settings'] ) && is_array( $_POST['project_settings'] )
			? wp_unslash( $_POST['project_settings'] )
			: array();
		\Estandar\Flow\Quotes\Settings::save_project_settings( $raw );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-flow&tab=config&sub=quotes&ss=branding&project_settings_saved=1' ) );
		exit;
	}

	/** Editor de plantillas globales de documento (fondo, imagen inferior, colores). */
	public static function render_templates_editor(): string
	{
		if ( ! class_exists( 'Estandar\\Flow\\Quotes\\Settings' ) ) {
			return '';
		}

		$templates = \Estandar\Flow\Quotes\Settings::templates();

		$html = '<h3>Plantillas globales</h3>'
			. '<p class="description">Diseño de las plantillas de documento (imagen de fondo, imagen inferior y colores). Cada proyecto elige su plantilla en el editor de <em>Branding</em>.</p>'
			. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. wp_nonce_field( 'estandar_suite_flow_save_quotes_templates', '_wpnonce', true, false )
			. '<input type="hidden" name="action" value="estandar_suite_flow_save_quotes_templates">';

		if ( function_exists( 'wp_enqueue_media' ) ) {
			wp_enqueue_media();
		}

		$html .= '<table class="widefat striped" style="max-width:1100px;"><thead><tr>'
			. '<th>ID</th><th>Nombre</th><th>Fondo (ID)</th><th>Fondo (URL)</th>'
			. '<th>Inferior (ID)</th><th>Inferior (URL)</th><th>Color primario</th><th>Acento</th>'
			. '</tr></thead><tbody>';

		foreach ( $templates as $id => $t ) {
			$base = 'quotes_templates[' . $id . ']';
			$html .= '<tr>'
				. '<td><code>' . esc_html( (string) $id ) . '</code><input type="hidden" name="' . esc_attr( $base ) . '[id]" value="' . esc_attr( (string) $id ) . '"></td>'
				. '<td><input type="text" name="' . esc_attr( $base ) . '[name]" value="' . esc_attr( (string) ( $t['name'] ?? '' ) ) . '" class="regular-text"></td>'
				. '<td><input type="number" min="0" name="' . esc_attr( $base ) . '[background_id]" value="' . esc_attr( (string) (int) ( $t['background_id'] ?? 0 ) ) . '" style="width:90px;"> '
				. '<button type="button" class="button esq-media-pick" data-id="' . esc_attr( $base ) . '[background_id]" data-url="' . esc_attr( $base ) . '[background_url]">Elegir</button></td>'
				. '<td><input type="url" name="' . esc_attr( $base ) . '[background_url]" value="' . esc_attr( (string) ( $t['background_url'] ?? '' ) ) . '" class="regular-text"></td>'
				. '<td><input type="number" min="0" name="' . esc_attr( $base ) . '[bottom_id]" value="' . esc_attr( (string) (int) ( $t['bottom_id'] ?? 0 ) ) . '" style="width:90px;"> '
				. '<button type="button" class="button esq-media-pick" data-id="' . esc_attr( $base ) . '[bottom_id]" data-url="' . esc_attr( $base ) . '[bottom_url]">Elegir</button></td>'
				. '<td><input type="url" name="' . esc_attr( $base ) . '[bottom_url]" value="' . esc_attr( (string) ( $t['bottom_url'] ?? '' ) ) . '" class="regular-text"></td>'
				. '<td><input type="text" name="' . esc_attr( $base ) . '[primary_color]" value="' . esc_attr( (string) ( $t['primary_color'] ?? '' ) ) . '" style="width:100px;" placeholder="#1d2327"></td>'
				. '<td><input type="text" name="' . esc_attr( $base ) . '[accent_color]" value="' . esc_attr( (string) ( $t['accent_color'] ?? '' ) ) . '" style="width:100px;" placeholder="#2271b1"></td>'
				. '</tr>';
		}

		$html .= '</tbody></table>'
			. '<p><button type="submit" class="button button-primary">Guardar plantillas</button></p>'
			. self::project_branding_media_script()
			. '</form>';

		return $html;
	}

	/** Editor de branding por proyecto (mapa `slug => name/logo/color`). */
	public static function render_project_branding_editor(): string
	{
		if ( ! class_exists( 'Estandar\\Flow\\Quotes\\Settings' ) ) {
			return '';
		}

		$map       = \Estandar\Flow\Quotes\Settings::project_settings_all();
		$templates = \Estandar\Flow\Quotes\Settings::templates();

		$slugs = array();
		if ( class_exists( 'Estandar\\Core\\Leads\\ProjectRegistry' ) ) {
			$slugs = (array) \Estandar\Core\Leads\ProjectRegistry::get_all_slugs();
		}
		foreach ( array_keys( $map ) as $slug ) {
			if ( ! in_array( $slug, $slugs, true ) ) {
				$slugs[] = $slug;
			}
		}
		sort( $slugs );

		$html = '<h3>Settings y branding por proyecto</h3>'
			. '<p class="description">Marca (nombre, logo, banda superior e imágenes), plantilla de documento y datos propios (sala de ventas, UF, condiciones, notas y financiamiento). Lo que quede vacío se hereda de los settings globales del módulo o de la etiqueta del proyecto.</p>'
			. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. wp_nonce_field( 'estandar_suite_flow_save_project_settings', '_wpnonce', true, false )
			. '<input type="hidden" name="action" value="estandar_suite_flow_save_project_settings">';

		if ( empty( $slugs ) ) {
			return $html . '<p>No hay proyectos registrados todavía.</p></form>';
		}

		if ( function_exists( 'wp_enqueue_media' ) ) {
			wp_enqueue_media();
		}

		$html .= '<table class="widefat striped" style="max-width:1100px;"><thead><tr>'
			. '<th>Proyecto</th><th>Nombre</th><th>Logo (ID)</th><th>Logo (URL)</th>'
			. '<th>Banda (ID)</th><th>Banda (URL)</th><th>Color</th>'
			. '</tr></thead><tbody>';

		foreach ( $slugs as $slug ) {
			$entry      = \Estandar\Flow\Quotes\Settings::project_settings( (string) $slug );
			$name       = (string) ( $entry['name'] ?? '' );
			$logo_id    = (int) ( $entry['logo_id'] ?? 0 );
			$logo_url   = (string) ( $entry['logo_url'] ?? '' );
			$color      = (string) ( $entry['color'] ?? '' );
			$header_id  = (int) ( $entry['header_image_id'] ?? 0 );
			$header_url = (string) ( $entry['header_image_url'] ?? '' );
			$images     = isset( $entry['images'] ) && is_array( $entry['images'] ) ? $entry['images'] : array();
			$data       = isset( $entry['data'] ) && is_array( $entry['data'] ) ? $entry['data'] : array();
			$fin        = isset( $data['financing'] ) && is_array( $data['financing'] ) ? $data['financing'] : array();
			$selected   = (string) ( $entry['template'] ?? '' );
			$conditions = isset( $data['conditions'] ) && is_array( $data['conditions'] ) ? implode( "\n", $data['conditions'] ) : (string) ( $data['conditions'] ?? '' );
			$base       = 'project_settings[' . $slug . ']';

			$html .= '<tr>'
				. '<td><code>' . esc_html( $slug ) . '</code></td>'
				. '<td><input type="text" name="' . esc_attr( $base ) . '[name]" value="' . esc_attr( $name ) . '" class="regular-text"></td>'
				. '<td><input type="number" min="0" name="' . esc_attr( $base ) . '[logo_id]" value="' . esc_attr( (string) $logo_id ) . '" style="width:90px;"></td>'
				. '<td><input type="url" name="' . esc_attr( $base ) . '[logo_url]" value="' . esc_attr( $logo_url ) . '" class="regular-text"></td>'
				. '<td><input type="number" min="0" name="' . esc_attr( $base ) . '[header_image_id]" value="' . esc_attr( (string) $header_id ) . '" style="width:90px;"></td>'
				. '<td><input type="url" name="' . esc_attr( $base ) . '[header_image_url]" value="' . esc_attr( $header_url ) . '" class="regular-text"></td>'
				. '<td><input type="text" name="' . esc_attr( $base ) . '[color]" value="' . esc_attr( $color ) . '" style="width:100px;" placeholder="#1d2327"></td>'
				. '</tr>';

			// Plantilla + datos propios del proyecto.
			$html .= '<tr><td colspan="7"><fieldset style="margin:0 0 6px;">'
				. '<label><strong>Plantilla</strong> <select name="' . esc_attr( $base ) . '[template]">';
			foreach ( $templates as $tid => $tpl ) {
				$html .= '<option value="' . esc_attr( (string) $tid ) . '" ' . selected( $selected, (string) $tid, false ) . '>' . esc_html( (string) ( $tpl['name'] ?? $tid ) ) . '</option>';
			}
			$html .= '</select></label></fieldset>';

			$html .= '<fieldset style="display:flex;flex-wrap:wrap;gap:10px;">';
			$data_fields = array(
				'project_name'     => 'Nombre proyecto',
				'operator_name'    => 'Operador',
				'operator_role'    => 'Cargo operador',
				'sales_room_name'  => 'Sala de ventas',
				'sales_room_phone' => 'Teléfono sala',
				'sales_room_email' => 'Email sala',
				'sales_room_hours' => 'Horario sala',
				'document_title'   => 'Título documento',
				'uf_value_clp'     => 'Valor UF (CLP)',
				'uf_source'        => 'Fuente UF',
			);
			foreach ( $data_fields as $key => $label ) {
				$val   = (string) ( $data[ $key ] ?? '' );
				$html .= '<label>' . esc_html( $label ) . '<br>'
					. '<input type="text" name="' . esc_attr( $base ) . '[data][' . esc_attr( $key ) . ']" value="' . esc_attr( $val ) . '" style="width:180px;"></label>';
			}
			$fin_fields = array(
				'credit_percent'      => 'Crédito (%)',
				'annual_rate_percent' => 'Tasa anual (%)',
				'income_multiple'     => 'Múltiplo ingreso',
				'terms_years'         => 'Plazo (años)',
			);
			foreach ( $fin_fields as $key => $label ) {
				$val   = (string) ( $fin[ $key ] ?? '' );
				$html .= '<label>' . esc_html( $label ) . '<br>'
					. '<input type="text" name="' . esc_attr( $base ) . '[data][financing][' . esc_attr( $key ) . ']" value="' . esc_attr( $val ) . '" style="width:120px;"></label>';
			}
			$html .= '</fieldset>'
				. '<fieldset style="display:flex;flex-wrap:wrap;gap:10px;">'
				. '<label>Condiciones (una por línea)<br><textarea name="' . esc_attr( $base ) . '[data][conditions]" rows="3" style="width:340px;">' . esc_textarea( $conditions ) . '</textarea></label>'
				. '<label>Notas<br><textarea name="' . esc_attr( $base ) . '[data][notes]" rows="3" style="width:340px;">' . esc_textarea( (string) ( $data['notes'] ?? '' ) ) . '</textarea></label>'
				. '</fieldset></td></tr>';

			// Galería de imágenes del proyecto (varios ID/URL + leyenda).
			$html .= '<tr><td colspan="7"><strong>Imágenes del proyecto</strong>'
				. '<div class="esq-gallery" data-base="' . esc_attr( $base ) . '">';

			$slots = array_values( $images );
			while ( count( $slots ) < 4 ) {
				$slots[] = array( 'id' => 0, 'url' => '', 'caption' => '' );
			}

			foreach ( $slots as $i => $img ) {
				$img     = is_array( $img ) ? $img : array();
				$img_id  = (int) ( $img['id'] ?? 0 );
				$img_url = (string) ( $img['url'] ?? '' );
				$img_cap = (string) ( $img['caption'] ?? '' );
				$idx     = 'project_settings[' . $slug . '][images][' . (int) $i . ']';

				$html .= '<div class="esq-gallery-row" style="margin:4px 0;">'
					. '<input type="number" min="0" name="' . esc_attr( $idx ) . '[id]" value="' . esc_attr( (string) $img_id ) . '" style="width:90px;" placeholder="ID"> '
					. '<input type="url" name="' . esc_attr( $idx ) . '[url]" value="' . esc_attr( $img_url ) . '" class="regular-text" placeholder="https://..." style="width:340px;"> '
					. '<input type="text" name="' . esc_attr( $idx ) . '[caption]" value="' . esc_attr( $img_cap ) . '" placeholder="Leyenda" style="width:200px;"> '
					. '<button type="button" class="button esq-media-pick" data-id="' . esc_attr( $idx ) . '[id]" data-url="' . esc_attr( $idx ) . '[url]">Elegir imagen</button>'
					. '</div>';
			}

			$html .= '</div>'
				. '<button type="button" class="button esq-gallery-add">Añadir imagen</button>'
				. '</td></tr>';
		}

		$html .= '</tbody></table>'
			. '<p><button type="submit" class="button button-primary">Guardar settings por proyecto</button></p>'
			. self::project_branding_media_script()
			. '</form>';

		return $html;
	}

	/** JS del editor de branding: media uploader de WP (si existe) + alta de filas de galería. */
	private static function project_branding_media_script(): string
	{
		return '<script>(function(){
			var root = document.currentScript ? document.currentScript.parentNode : document;
			function picker(idName, urlName){
				if (typeof wp === "undefined" || ! wp.media) { return; }
				var frame = wp.media({ title: "Elegir imagen", multiple: false, library: { type: "image" } });
				frame.on("select", function(){
					var a = frame.state().get("selection").first().toJSON();
					var idEl = root.querySelector("[name=\'" + idName + "\']");
					var urlEl = root.querySelector("[name=\'" + urlName + "\']");
					if (idEl) { idEl.value = a.id || ""; }
					if (urlEl) { urlEl.value = a.url || ""; }
				});
				frame.open();
			}
			root.querySelectorAll(".esq-media-pick").forEach(function(btn){
				btn.addEventListener("click", function(){ picker(btn.getAttribute("data-id"), btn.getAttribute("data-url")); });
			});
			root.querySelectorAll(".esq-gallery-add").forEach(function(btn){
				btn.addEventListener("click", function(){
					var box = btn.parentNode.querySelector(".esq-gallery");
					if (! box) { return; }
					var base = box.getAttribute("data-base");
					var n = box.querySelectorAll(".esq-gallery-row").length;
					var row = document.createElement("div");
					row.className = "esq-gallery-row";
					row.style.margin = "4px 0";
					row.innerHTML = "<input type=\'number\' min=\'0\' name=\'" + base + "[images][" + n + "][id]\' style=\'width:90px;\' placeholder=\'ID\'> "
						+ "<input type=\'url\' name=\'" + base + "[images][" + n + "][url]\' class=\'regular-text\' placeholder=\'https://...\' style=\'width:340px;\'> "
						+ "<input type=\'text\' name=\'" + base + "[images][" + n + "][caption]\' placeholder=\'Leyenda\' style=\'width:200px;\'>";
					box.appendChild(row);
				});
			});
		})();</script>';
	}

	public static function menu(): void
	{
		add_submenu_page( 'estandar-suite', 'Flow', 'Flow', 'manage_options', 'estandar-suite-flow', array( __CLASS__, 'render' ) );
	}

	/** Per-functionality settings descriptors (key => field config). */
	public static function feature_fields(): array
	{
		return array(
			'quotes' => array(
				'enabled'  => array( 'type' => 'bool', 'label' => 'Módulo de cotizaciones habilitado', 'desc' => 'Activa el módulo de cotizaciones en la suite.', 'default' => 1 ),
				'currency' => array( 'type' => 'text', 'label' => 'Moneda por defecto', 'desc' => 'Código de moneda (CLP, USD, EUR).', 'default' => 'CLP' ),
				'vat'      => array( 'type' => 'text', 'label' => 'IVA (%)', 'desc' => 'Porcentaje de impuesto aplicado por defecto.', 'default' => '19' ),
			),
		);
	}

	/** Data provider for a feature sub-tab (read-only legacy + v2 + adoption). */
	public static function feature_data( string $name ): array
	{
		$data = array( 'quotes' => array(), 'v2_quotes' => 0, 'v2' => array(), 'adoption' => array() );
		if ( $name === 'quotes' ) {
			$data['quotes']    = \Estandar\Core\Admin::legacy_rows( 'es_quotes', 'ORDER BY id DESC LIMIT 50' );
			$data['v2_quotes'] = \Estandar\Core\Admin::legacy_count( 'estandar_flow_quotes' );
			$data['v2']        = self::quotes_v2_rows( 50 );
			$data['adoption']  = class_exists( 'Estandar\\Flow\\QuotesAdoption' ) ? QuotesAdoption::counts() : array();
		}
		return $data;
	}

	/** Filas v2 decoradas para la tabla del admin (cliente + ítems + total). */
	private static function quotes_v2_rows( int $limit = 50 ): array
	{
		if ( ! class_exists( 'Estandar\\Flow\\Quotes' ) ) {
			return array();
		}
		$rows = \Estandar\Flow\Quotes::list( $limit );
		if ( ! $rows ) {
			return array();
		}

		$lead_ids = array();
		foreach ( $rows as $r ) {
			$lead_ids[] = (int) ( $r['lead_id'] ?? 0 );
		}
		$lead_names = self::lead_names( $lead_ids );

		foreach ( $rows as &$r ) {
			$items                 = (array) ( $r['items'] ?? array() );
			$r['items_count']      = count( $items );
			$r['items_summary']    = self::items_summary( $items );
			$r['client_label']     = (string) ( $lead_names[ (int) ( $r['lead_id'] ?? 0 ) ] ?? '' );
			$r['total']            = \Estandar\Flow\Quotes::total( $r );
			$r['number']           = (string) ( $r['quote_number'] ?? '' );
			if ( '' === $r['number'] ) {
				$r['number'] = 'COT-' . (int) ( $r['id'] ?? 0 );
			}
		}
		unset( $r );
		return $rows;
	}

	/** Nombres de lead por id desde la tabla legada (read-only, guardada). */
	private static function lead_names( array $ids ): array
	{
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! isset( $wpdb ) || ! $ids ) {
			return array();
		}
		$table = $wpdb->prefix . 'es_leads';
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}
		$in   = implode( ',', array_map( 'intval', $ids ) );
		$rows = $wpdb->get_results( "SELECT id, name FROM {$table} WHERE id IN ({$in})", ARRAY_A ); // phpcs:ignore WordPress.DB
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['id'] ] = (string) $r['name'];
		}
		return $out;
	}

	/** Resumen textual de los ítems (primeros 2 + conteo). */
	private static function items_summary( array $items ): string
	{
		$labels = array();
		foreach ( $items as $it ) {
			$label = trim( (string) ( $it['label'] ?? $it['description'] ?? '' ) );
			if ( '' !== $label ) {
				$labels[] = $label;
			}
		}
		if ( ! $labels ) {
			return '—';
		}
		$shown = array_slice( $labels, 0, 2 );
		$text  = implode( ', ', $shown );
		if ( count( $labels ) > count( $shown ) ) {
			$text .= ' (+' . ( count( $labels ) - count( $shown ) ) . ')';
		}
		return $text;
	}

	/** Fila del lead (legado es_leads), para poblar el documento del editor. */
	public static function lead_row( int $lead_id ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) || $lead_id <= 0 ) {
			return array();
		}
		$table = $wpdb->prefix . 'es_leads';
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $lead_id ), ARRAY_A );
		return is_array( $row ) ? $row : array();
	}

	/** admin_post handler: persist feature settings (whitelisted fields). */
	public static function handle_save_feature(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_flow_save_feature' );
		$feature = sanitize_key( (string) ( $_POST['feature'] ?? '' ) );
		$fields  = self::feature_fields();
		if ( ! isset( $fields[ $feature ] ) ) {
			wp_die( 'Feature desconocido.' );
		}
		$raw = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? $_POST['settings'] : array();
		update_option( 'estandar_suite_flow_' . $feature . '_settings', \Estandar\Core\Admin::sanitize_feature_fields( $fields[ $feature ], $raw ) );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-flow&tab=config&sub=' . $feature . '&feature_saved=1' ) );
		exit;
	}

	/** Estructura de visibilidad de este producto (para el manager central de core). */
	public static function visibility_structure(): array
	{
		return array(
			'product' => 'flow',
			'page'    => 'estandar-suite-flow',
			'label'   => 'Flow',
			'subs'    => array(
				'estado'    => array( 'resumen' => 'Resumen', 'datos' => 'Datos', 'esquema' => 'Esquema' ),
				'proyectos' => array(),
				'config'    => array( 'etapas' => 'Etapas', 'quotes' => 'Cotizaciones', 'settings' => 'Settings' ),
				'datos'     => array( 'adopcion' => 'Adopción' ),
				'avanzado'  => array( 'rest' => 'REST', 'cli' => 'WP-CLI', 'inventario' => 'Inventario' ),
			),
			'ss' => array(
				'config' => array(
					'quotes' => self::quotes_ssubs(),
				),
			),
		);
	}

 	public static function render(): void
 	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		// Wizard "Proyectos": el borrador del paso 5 se prepara en `admin_init`
		// (ver Admin::init), nunca aquí: a esta altura WordPress ya emitió el
		// header de admin y `wp_safe_redirect` fallaría.
		\Estandar\Core\Admin::render_product_header();
		$tabs = array(
			'estado'    => 'Estado',
			'proyectos' => 'Proyectos',
			'config'    => 'Configuración',
			'datos'     => 'Datos',
			'avanzado'  => 'Avanzado',
		);
		$tab    = \Estandar\Core\Admin::resolve_tab( $tabs, isset( $_GET['tab'] ) ? 'estado' : 'config' );
		$subs = array(
			'estado'    => array( 'resumen' => 'Resumen', 'datos' => 'Datos', 'esquema' => 'Esquema' ),
			'proyectos' => array(),
			'config'    => array( 'etapas' => 'Etapas', 'quotes' => 'Cotizaciones', 'settings' => 'Settings' ),
			'datos'     => array( 'adopcion' => 'Adopción' ),
			'avanzado'  => array( 'rest' => 'REST', 'cli' => 'WP-CLI', 'inventario' => 'Inventario' ),
		);
		// ── Visibilidad: filter tabs/sub-tabs (visual only) ─────────────────
		$visible_tabs = \Estandar\Core\Admin::filter_tabs( 'flow', $tabs, $subs );
		if ( empty( $visible_tabs ) ) {
			\Estandar\Core\Admin::render_visibility_none( 'flow' );
			return;
		}
		foreach ( $subs as $t => $s_list ) {
			$subs[ $t ] = \Estandar\Core\Admin::filter_subs( 'flow', $t, $s_list );
		}
		$tabs = $visible_tabs;
		$tab = \Estandar\Core\Admin::resolve_tab( $tabs, isset( $_GET['tab'] ) ? 'estado' : 'config' );
		$sub = isset( $subs[ $tab ] ) ? \Estandar\Core\Admin::resolve_sub( $subs[ $tab ], ( $tab === 'config' && ! isset( $_GET['sub'] ) ) ? 'etapas' : '' ) : '';
		// M11: pantalla del editor de cotización (se renderiza standalone y corta).
		if ( $tab === 'config' && $sub === 'quotes' && 'editor' === sanitize_key( (string) ( $_GET['ss'] ?? '' ) ) ) {
			echo self::render_quote_editor_screen(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}
		// Wizard Proyectos (lista → header/bottom → plantilla → leads → cotización → envío).
		if ( $tab === 'proyectos' ) {
			echo '<div class="wrap">';
			\Estandar\Core\Admin::render_tabs( 'estandar-suite-flow', $tab, $tabs );
			self::render_projects_screen();
			echo '</div>';
			return;
		}
		$stage = sanitize_key( (string) ( $_GET['stage'] ?? '' ) );
		$pipeline = Pipeline::list( $stage );
		$customers = Customers::list( 50 );
		$quotes    = Quotes::list( 50 );
		$legacy    = Adoption::legacy_present();
		$trans = isset( $_GET['transitioned'] );
		$imp   = isset( $_GET['imported'] ) ? (int) $_GET['imported'] : null;
		$rol   = isset( $_GET['rolled'] ) ? (int) $_GET['rolled'] : null;

		// ── Feature sub-tab data (quotes) ───────────────────────────────────
		$feature_fields = self::feature_fields();
		$feature_view   = array();
		$feature_saved  = isset( $_GET['feature_saved'] );
		if ( $tab === 'config' && isset( $feature_fields[ $sub ] ) ) {
			$feature_view = self::feature_data( $sub );
		}

		// ── Funciones que usan settings (marcadas en el inventario) ─────────
		$fn_settings = array(
			'activate_schema'  => array( 'estandar_suite_flow_schema_version' ),
			'maybe_migrate_schema' => array( 'estandar_suite_flow_schema_version' ),
		);

		// ── Inventario completo de funciones (cobertura 100%) ──────────────
		$sets = array(
			'Esquema (ciclo de vida y tablas)' => array(
				array( 'fn' => 'activate_schema', 'file' => 'Pipeline.php', 'desc' => 'Crea estandar_flow_pipeline, stage_events, customers, quotes, tasks.' ),
				array( 'fn' => 'maybe_migrate_schema', 'file' => 'Pipeline.php', 'desc' => 'Migra el esquema según versión guardada.' ),
				array( 'fn' => 'is_valid_stage', 'file' => 'Pipeline.php', 'desc' => 'Valida una etapa contra las canónicas.' ),
				array( 'fn' => 'status_label', 'file' => 'Pipeline.php', 'desc' => 'Etiqueta legible de una etapa.' ),
				array( 'fn' => 'fingerprint', 'file' => 'Customers.php', 'desc' => 'Huella UNIQUE para evitar clientes duplicados.' ),
				array( 'fn' => 'quote_uuid', 'file' => 'Quotes.php', 'desc' => 'Genera el UUID de una cotización.' ),
			),
			'Dominio (lógica de negocio)' => array(
				array( 'fn' => 'transition', 'file' => 'Pipeline.php', 'desc' => 'Transición auditada entre etapas del pipeline.' ),
				array( 'fn' => 'list', 'file' => 'Pipeline.php', 'desc' => 'Lista el pipeline con filtro por etapa.' ),
				array( 'fn' => 'on_capture', 'file' => 'Pipeline.php', 'desc' => 'Consume estandar_suite_capture_event → crea/actualiza pipeline.' ),
				array( 'fn' => 'create', 'file' => 'Customers.php', 'desc' => 'Crea un cliente con fingerprint único.' ),
				array( 'fn' => 'list', 'file' => 'Customers.php', 'desc' => 'Lista clientes.' ),
				array( 'fn' => 'create', 'file' => 'Quotes.php', 'desc' => 'Crea una cotización.' ),
				array( 'fn' => 'list', 'file' => 'Quotes.php', 'desc' => 'Lista cotizaciones.' ),
			),
			'Adopción (datos legados)' => array(
				array( 'fn' => 'legacy_present', 'file' => 'Adoption.php', 'desc' => 'Detecta estado comercial histórico de es_leads.' ),
				array( 'fn' => 'project_lead', 'file' => 'Adoption.php', 'desc' => 'Proyecta un lead legado al pipeline.' ),
				array( 'fn' => 'historical_leads', 'file' => 'Adoption.php', 'desc' => 'Lista leads históricos para previsualizar.' ),
				array( 'fn' => 'import_pipeline', 'file' => 'Adoption.php', 'desc' => 'Importa el pipeline histórico (dry-run o commit).' ),
				array( 'fn' => 'rollback_pipeline', 'file' => 'Adoption.php', 'desc' => 'Elimina solo los pipelines adoptados.' ),
			),
			'Eventos (integración)' => array(
				array( 'fn' => 'init', 'file' => 'Admin.php', 'desc' => 'Registra submenú y handlers admin_post.' ),
				array( 'fn' => 'on_capture', 'file' => 'Pipeline.php', 'desc' => 'Consume estandar_suite_capture_event.' ),
				array( 'fn' => 'on_signal', 'file' => 'Pipeline.php', 'desc' => 'Consume estandar_suite_signal_recorded y mueve el journey.' ),
				array( 'fn' => 'advance_to', 'file' => 'Pipeline.php', 'desc' => 'Avanza el journey al estado objetivo por el camino válido (best-effort).' ),
				array( 'fn' => 'path_to', 'file' => 'Pipeline.php', 'desc' => 'Camino más corto entre dos estados del journey.' ),
				array( 'fn' => 'signal_target', 'file' => 'Pipeline.php', 'desc' => 'Estado destino que evidencia un evento de señal.' ),
				array( 'fn' => 'resolve_pipeline_id', 'file' => 'Pipeline.php', 'desc' => 'Resuelve el pipeline de una señal (capture o lead).' ),
			),
			'REST API' => array(
				array( 'fn' => 'routes', 'file' => 'Rest.php', 'desc' => 'Rutas REST (GET /pipeline, POST /pipeline/{id}/transition, GET /customers, GET /quotes).' ),
				array( 'fn' => 'init', 'file' => 'Rest.php', 'desc' => 'Registra rutas en rest_api_init.' ),
			),
			'WP-CLI' => array(
				array( 'fn' => 'pipeline', 'file' => 'Cli.php', 'desc' => 'Ver el pipeline.' ),
				array( 'fn' => 'transition', 'file' => 'Cli.php', 'desc' => 'Mover una entidad de etapa.' ),
				array( 'fn' => 'adoption_import', 'file' => 'Cli.php', 'desc' => 'Importa el pipeline histórico (--commit).' ),
				array( 'fn' => 'adoption_rollback', 'file' => 'Cli.php', 'desc' => 'Rollback de pipeline adoptado (--confirm=yes).' ),
				array( 'fn' => 'quotes_list|summary', 'file' => 'Cli.php', 'desc' => 'Lista/resumen de cotizaciones legadas.' ),
			),
			'UI (interfaz de administración)' => array(
				array( 'fn' => 'menu', 'file' => 'Admin.php', 'desc' => 'Registra el submenú Flow bajo Estandar Suite.' ),
				array( 'fn' => 'render', 'file' => 'Admin.php', 'desc' => 'Renderiza la página de Flow con sus 4 tabs.' ),
				array( 'fn' => 'feature_data', 'file' => 'Admin.php', 'desc' => 'Datos del sub-tab quotes (read-only legado, es_quotes).' ),
				array( 'fn' => 'handle_save_feature', 'file' => 'Admin.php', 'desc' => 'Handler admin_post para guardar settings de features.' ),
				array( 'fn' => 'handle_transition', 'file' => 'Admin.php', 'desc' => 'Handler admin_post para transición.' ),
				array( 'fn' => 'handle_import', 'file' => 'Admin.php', 'desc' => 'Handler admin_post para import de pipeline.' ),
				array( 'fn' => 'handle_rollback', 'file' => 'Admin.php', 'desc' => 'Handler admin_post para rollback de pipeline.' ),
			),
			'Integración legada (solo lectura)' => array(
				array( 'fn' => 'feature_fields', 'file' => 'Admin.php', 'desc' => 'Descriptor de settings de cada feature.' ),
			),
		);

		include ESTANDAR_SUITE_FLOW_PATH . 'includes/templates/flow-admin.php';
	}

	/** Public accessor for the settings descriptors (used by templates). */
	public static function get_settings(): array
	{
		return array(
			array( 'name' => 'estandar_suite_flow_schema_version', 'label' => 'Versión de esquema', 'desc' => 'Versión actual de las tablas estandar_flow_*.', 'editable' => false, 'legacy' => false ),
		);
	}

	public static function handle_transition(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_flow_transition' );
		Pipeline::transition( (int) ( $_POST['pipeline_id'] ?? 0 ), sanitize_key( (string) ( $_POST['stage'] ?? '' ) ), (string) ( $_POST['reason'] ?? '' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-flow&transitioned=1' ) );
		exit;
	}

	public static function handle_import(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_flow_adoption_import' );
		$r = Adoption::import_pipeline( empty( $_POST['commit'] ), (int) ( $_POST['limit'] ?? 100 ) );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-flow&imported=' . (int) $r['imported'] ) );
		exit;
	}

	public static function handle_rollback(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_flow_adoption_rollback' );
		$n = Adoption::rollback_pipeline();
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-flow&rolled=' . (int) $n ) );
		exit;
	}

	// ── M11: operación de cotizaciones v2 (admin) ───────────────────────────

	/** URL de la pantalla v2 (lista). */
	public static function quotes_v2_url( array $extra = array() ): string
	{
		$args = array_merge( array( 'page' => 'estandar-suite-flow', 'tab' => 'config', 'sub' => 'quotes', 'ss' => 'v2' ), $extra );
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/** URL de la pantalla del editor de cotización. */
	public static function quote_editor_url( int $lead_id = 0, int $quote_id = 0 ): string
	{
		$args = array( 'page' => 'estandar-suite-flow', 'tab' => 'config', 'sub' => 'quotes', 'ss' => 'editor' );
		if ( $lead_id > 0 ) {
			$args['lead_id'] = $lead_id;
		}
		if ( $quote_id > 0 ) {
			$args['quote_id'] = $quote_id;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/** Operador actual (para la auditoría del ledger). */
	private static function current_operator(): string
	{
		if ( function_exists( 'wp_get_current_user' ) ) {
			$user = wp_get_current_user();
			if ( $user && ! empty( $user->display_name ) ) {
				return (string) $user->display_name;
			}
		}
		return '';
	}

	/** Redirección con aviso a la pantalla v2 o al editor. */
	private static function quote_redirect( string $base, array $args ): void
	{
		wp_safe_redirect( add_query_arg( $args, $base ) );
		exit;
	}

	/** Pantalla del editor de cotización (crear con lead_id / editar con quote_id). */
	public static function render_quote_editor_screen(): string
	{
		if ( ! class_exists( 'Estandar\\Flow\\Quotes\\Editor' ) || ! class_exists( 'Estandar\\Flow\\Quotes\\DocumentBuilder' ) ) {
			return '<div class="notice notice-error inline"><p>El editor de cotizaciones no está disponible (falta el módulo v2).</p></div>';
		}

		$lead_id  = (int) ( $_GET['lead_id'] ?? 0 );
		$quote_id = (int) ( $_GET['quote_id'] ?? 0 );
		if ( $quote_id > 0 && $lead_id <= 0 && class_exists( 'Estandar\\Flow\\Quotes' ) ) {
			$quote   = \Estandar\Flow\Quotes::get( $quote_id );
			$lead_id = is_array( $quote ) ? (int) ( $quote['lead_id'] ?? 0 ) : 0;
		}

		// Sin lead ni cotización: selector de lead por ID (o desde el pipeline).
		if ( $lead_id <= 0 && $quote_id <= 0 ) {
			return '<div class="wrap"><h2>Nueva cotización</h2>'
				. '<p>Indica el <strong>ID del lead</strong> para crear la cotización.</p>'
				. '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" style="max-width:420px;">'
				. '<input type="hidden" name="page" value="estandar-suite-flow">'
				. '<input type="hidden" name="tab" value="config">'
				. '<input type="hidden" name="sub" value="quotes">'
				. '<input type="hidden" name="ss" value="editor">'
				. '<p><label>Lead ID <input type="number" min="1" name="lead_id" required style="width:120px;"></label></p>'
				. '<p><button class="button button-primary" type="submit">Abrir editor</button> '
				. '<a class="button" href="' . esc_url( self::quotes_v2_url() ) . '">Volver</a></p>'
				. '</form></div>';
		}

		$lead  = self::lead_row( $lead_id );
		$doc   = \Estandar\Flow\Quotes\DocumentBuilder::for_quote( $quote_id, $lead );
		$saved = \Estandar\Flow\Quotes\Editor::load_saved( $quote_id );
		if ( ! empty( $saved ) ) {
			$doc = \Estandar\Flow\Quotes\Editor::merge_saved( $doc, $saved );
		}

		$quotes = ( $lead_id > 0 && class_exists( 'Estandar\\Flow\\Quotes' ) ) ? \Estandar\Flow\Quotes::for_lead( $lead_id ) : array();

		$notice      = '';
		$notice_type = 'info';
		if ( isset( $_GET['quote_saved'] ) ) {
			$notice      = 'Cotización guardada.';
			$notice_type = 'ok';
		} elseif ( isset( $_GET['quote_sent'] ) ) {
			$notice      = 'Cotización enviada al cliente.';
			$notice_type = 'ok';
		} elseif ( ! empty( $_GET['quote_error'] ) ) {
			$notice      = 'No se pudo completar la operación: ' . sanitize_text_field( (string) $_GET['quote_error'] );
			$notice_type = 'error';
		}

		return \Estandar\Flow\Quotes\Editor::page(
			$lead_id,
			$quote_id,
			$notice,
			$notice_type,
			admin_url( 'admin-post.php' ),
			wp_create_nonce( 'estandar_suite_flow_quote_save' ),
			$doc,
			array(
				'quotes'      => $quotes,
				'back'        => self::quotes_v2_url(),
				'lead'        => $lead,
				'save_action' => 'estandar_suite_flow_quote_save',
				'send_action' => 'estandar_suite_flow_quote_send',
			)
		);
	}

	/** admin_post genérico: enruta formularios del editor sin campo `action`. */
	public static function handle_quote_post(): void
	{
		$lp_action = sanitize_key( (string) ( $_REQUEST['lp_action'] ?? '' ) );
		if ( 'quote_save' === $lp_action ) {
			self::handle_quote_save();
		} elseif ( 'quote_send' === $lp_action ) {
			self::handle_quote_send();
		}
	}

	/** admin_post: guardar/crear cotización desde el editor (nonce + cap). */
	public static function handle_quote_save(): void
	{
		if ( ! self::can_manage_quotes() ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_flow_quote_save' );
		if ( ! class_exists( 'Estandar\\Flow\\Quotes\\Editor' ) ) {
			self::quote_redirect( self::quotes_v2_url(), array( 'quote_error' => 'editor_no_disponible' ) );
		}
		$lead_id = (int) ( $_POST['lead_id'] ?? 0 );
		$r       = \Estandar\Flow\Quotes\Editor::save_from_post( $lead_id, wp_unslash( $_POST ), self::current_operator() );
		if ( empty( $r['ok'] ) ) {
			self::quote_redirect( self::quote_editor_url( $lead_id, (int) ( $_POST['quote_id'] ?? 0 ) ), array( 'quote_error' => (string) ( $r['error'] ?? 'desconocido' ) ) );
		}
		self::quote_redirect( self::quote_editor_url( $lead_id, (int) $r['quote_id'] ), array( 'quote_saved' => 1 ) );
	}

	/** admin_post: cambiar el estado de una cotización (Lifecycle). */
	public static function handle_quote_status(): void
	{
		if ( ! self::can_manage_quotes() ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_flow_quote_status' );
		$id     = (int) ( $_POST['quote_id'] ?? 0 );
		$status = sanitize_key( (string) ( $_POST['status'] ?? '' ) );
		if ( ! class_exists( 'Estandar\\Flow\\Quotes\\Lifecycle' ) ) {
			self::quote_redirect( self::quotes_v2_url(), array( 'quote_error' => 'lifecycle_no_disponible' ) );
		}
		$ok = \Estandar\Flow\Quotes\Lifecycle::transition( $id, $status, array(
			'reject_reason' => sanitize_text_field( (string) ( $_POST['reason'] ?? '' ) ),
		), self::current_operator() );
		self::quote_redirect( self::quotes_v2_url(), $ok ? array( 'quote_status' => $status ) : array( 'quote_error' => 'transicion_invalida' ) );
	}

	/** admin_post: enviar la cotización al cliente (Quotes::send). */
	public static function handle_quote_send(): void
	{
		if ( ! self::can_manage_quotes() ) {
			wp_die( 'Sin permisos.' );
		}
		$nonce  = (string) ( $_POST['_wpnonce'] ?? '' );
		$valid  = wp_verify_nonce( $nonce, 'estandar_suite_flow_quote_send' ) || wp_verify_nonce( $nonce, 'estandar_suite_flow_quote_save' );
		if ( ! $valid ) {
			wp_die( 'Nonce inválido.' );
		}
		$id    = (int) ( $_POST['quote_id'] ?? 0 );
		$to    = sanitize_email( (string) ( $_POST['to'] ?? '' ) );
		$cc    = (string) ( $_POST['cc'] ?? '' );
		$lead  = array();
		if ( class_exists( 'Estandar\\Flow\\Quotes' ) ) {
			$quote = \Estandar\Flow\Quotes::get( $id );
			$lid   = is_array( $quote ) ? (int) ( $quote['lead_id'] ?? 0 ) : 0;
			if ( $lid > 0 ) {
				$lead = self::lead_row( $lid );
			}
		}
		if ( ! class_exists( 'Estandar\\Flow\\Quotes' ) ) {
			self::quote_redirect( self::quotes_v2_url(), array( 'quote_error' => 'modulo_no_disponible' ) );
		}

		// "Enviar a operador": resuelve el email del operador del proyecto y lo
		// usa como destinatario; si además hay email del lead, va como copia.
		$to_operator = ! empty( $_POST['to_operator'] );
		if ( $to_operator ) {
			$slug = sanitize_key( (string) ( $_POST['project'] ?? '' ) );
			if ( '' === $slug && is_array( $quote ) ) {
				$slug = sanitize_key( (string) ( $quote['project_slug'] ?? ( $quote['project'] ?? '' ) ) );
			}
			$operator_email = self::project_operator_email( $slug );
			if ( '' !== $operator_email ) {
				$lead_email = sanitize_email( (string) ( $lead['email'] ?? '' ) );
				if ( '' !== $lead_email && $lead_email !== $operator_email ) {
					$cc = '' !== trim( $cc ) ? $cc . ',' . $lead_email : $lead_email;
				}
				$to = $operator_email;
			}
		}

		$r = \Estandar\Flow\Quotes::send( $id, $to, $lead, array( 'cc' => $cc ) );
		// Wizard "Proyectos": volver a la previsualización con confirmación.
		if ( 'proyectos' === sanitize_key( (string) ( $_POST['redirect'] ?? '' ) ) ) {
			$project = sanitize_key( (string) ( $_POST['project'] ?? '' ) );
			$args    = array( 'step' => 6, 'quote' => $id, 'project' => $project );
			if ( empty( $r['ok'] ) ) {
				$args['quote_error'] = (string) ( $r['error'] ?? 'envio_fallido' );
			} else {
				$args['sent'] = 1;
			}
			self::quote_redirect( self::projects_url( $args ), array() );
		}
		if ( empty( $r['ok'] ) ) {
			self::quote_redirect( self::quotes_v2_url(), array( 'quote_error' => (string) ( $r['error'] ?? 'envio_fallido' ) ) );
		}
		self::quote_redirect( self::quotes_v2_url(), array( 'quote_sent' => 1 ) );
	}

	/** admin_post: duplicar una cotización como nuevo borrador. */
	public static function handle_quote_duplicate(): void
	{
		if ( ! self::can_manage_quotes() ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_flow_quote_duplicate' );
		if ( ! class_exists( 'Estandar\\Flow\\Quotes' ) ) {
			self::quote_redirect( self::quotes_v2_url(), array( 'quote_error' => 'modulo_no_disponible' ) );
		}
		$id     = (int) ( $_POST['quote_id'] ?? 0 );
		$new_id = \Estandar\Flow\Quotes::duplicate( $id );
		if ( $new_id <= 0 ) {
			self::quote_redirect( self::quotes_v2_url(), array( 'quote_error' => 'no_duplicada' ) );
		}
		self::quote_redirect( self::quotes_v2_url(), array( 'quote_duplicated' => $new_id ) );
	}

	/** admin_post: generar y guardar el PDF de una cotización. */
	public static function handle_quote_pdf(): void
	{
		if ( ! self::can_manage_quotes() ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_flow_quote_pdf' );
		$id = (int) ( $_POST['quote_id'] ?? 0 );
		if ( ! class_exists( 'Estandar\\Flow\\Quotes\\Document\\Pdf' ) ) {
			self::quote_redirect( self::quotes_v2_url(), array( 'quote_error' => 'pdf_no_disponible' ) );
		}
		$doc = class_exists( 'Estandar\\Flow\\Quotes\\DocumentBuilder' )
			? \Estandar\Flow\Quotes\DocumentBuilder::for_quote( $id )
			: array();
		$pdf = \Estandar\Flow\Quotes\Document\Pdf::render( $doc );
		if ( empty( $pdf['ok'] ) || '' === (string) $pdf['bytes'] ) {
			self::quote_redirect( self::quotes_v2_url(), array( 'quote_error' => (string) ( $pdf['error'] ?? 'render_vacio' ) ) );
		}
		$filename = \Estandar\Flow\Quotes\Document\Pdf::filename( $doc );
		$path     = \Estandar\Flow\Quotes\Document\PdfStore::path( $filename );
		@file_put_contents( $path, (string) $pdf['bytes'] );
		$url = \Estandar\Flow\Quotes\Document\PdfStore::url( $filename );
		if ( '' !== $url ) {
			wp_safe_redirect( $url );
			exit;
		}
		self::quote_redirect( self::quotes_v2_url(), array( 'quote_pdf_bytes' => strlen( (string) $pdf['bytes'] ) ) );
	}

	// ── Wizard "Proyectos" (tab=proyectos) ──────────────────────────────────
	//
	// Flujo: lista de proyectos → header/bottom (paso 1) → plantilla (paso 2) →
	// confirmación (paso 3) → leads globales (paso 4) → editor de cotización
	// (paso 5) → previsualización + envío con CC (paso 6). Estado por query
	// params: project, step, lead, quote. Todo con nonce + manage_options.

	/** Encola `wp.media` SOLO en la pantalla del wizard de Proyectos. */
	public static function enqueue_projects_assets( $hook = '' ): void
	{
		$page = sanitize_key( (string) ( $_GET['page'] ?? '' ) );
		$tab  = sanitize_key( (string) ( $_GET['tab'] ?? '' ) );
		if ( 'estandar-suite-flow' === $page && 'proyectos' === $tab && function_exists( 'wp_enqueue_media' ) ) {
			wp_enqueue_media();
			// El binding va enganchado a `media-views` (footer) y en DOM-ready:
			// así `wp.media` ya está cargado cuando el operador pulsa "Elegir".
			$js = "wp.domReady(function(){"
				. "document.querySelectorAll('.esfp-media-pick').forEach(function(btn){"
				. "if(btn.dataset.esfpBound){return;}btn.dataset.esfpBound='1';"
				. "btn.addEventListener('click',function(){"
				. "var f=wp.media({title:'Elegir imagen',multiple:false,library:{type:'image'}});"
				. "f.on('select',function(){var a=f.state().get('selection').first().toJSON();"
				. "var u=document.getElementById(btn.getAttribute('data-url'));var i=document.getElementById(btn.getAttribute('data-id'));"
				. "if(u){u.value=a.url||'';}if(i){i.value=a.id||'';}});f.open();});});"
				. "var MAX=6;"
				. "function gRead(el){try{var v=JSON.parse(el.value||'[]');return Array.isArray(v)?v:[];}catch(e){return [];}}"
				. "function gRender(preview,items){preview.innerHTML='';items.forEach(function(it,idx){"
				. "var w=document.createElement('span');w.style.display='inline-block';w.style.margin='0 6px 6px 0';w.style.textAlign='center';"
				. "var im=document.createElement('img');im.src=it.url||'';im.style.width='90px';im.style.height='70px';im.style.objectFit='cover';im.style.display='block';im.style.border='1px solid #dcdcde';"
				. "var rm=document.createElement('button');rm.type='button';rm.className='button-link esfp-gallery-remove';rm.setAttribute('data-index',idx);rm.textContent='Quitar';rm.style.color='#b32d2e';rm.style.cursor='pointer';"
				. "w.appendChild(im);w.appendChild(rm);preview.appendChild(w);});}"
				. "document.querySelectorAll('.esfp-media-gallery-pick').forEach(function(btn){"
				. "if(btn.dataset.esfpGBound){return;}btn.dataset.esfpGBound='1';"
				. "btn.addEventListener('click',function(){"
				. "if(typeof wp==='undefined'||!wp.media){return;}"
				. "var hidden=document.getElementById(btn.getAttribute('data-json'));var preview=document.getElementById(btn.getAttribute('data-preview'));"
				. "if(!hidden||!preview){return;}var items=gRead(hidden);"
				. "var f=wp.media({title:'Elegir imágenes (hasta '+MAX+')',multiple:'add',library:{type:'image'}});"
				. "f.on('select',function(){f.state().get('selection').toJSON().forEach(function(a){"
				. "if(items.length>=MAX){return;}items.push({id:a.id||0,url:a.url||'',caption:a.title||''});});"
				. "items=items.slice(0,MAX);hidden.value=JSON.stringify(items);gRender(preview,items);});f.open();});});"
				. "document.querySelectorAll('.esfp-gallery-preview').forEach(function(preview){"
				. "preview.addEventListener('click',function(ev){var t=ev.target;"
				. "if(!t||!t.classList||!t.classList.contains('esfp-gallery-remove')){return;}"
				. "var hidden=document.getElementById(preview.getAttribute('data-json'));if(!hidden){return;}"
				. "var items=gRead(hidden);var idx=parseInt(t.getAttribute('data-index'),10);"
				. "if(isNaN(idx)||idx<0||idx>=items.length){return;}items.splice(idx,1);"
				. "hidden.value=JSON.stringify(items);gRender(preview,items);});});"
				. "});";
			if ( function_exists( 'wp_add_inline_script' ) ) {
				wp_add_inline_script( 'media-views', $js );
			}
		}
	}

	/** URL de una pantalla del wizard de Proyectos. */
	public static function projects_url( array $args = array() ): string
	{
		return add_query_arg(
			array_merge( array( 'page' => 'estandar-suite-flow', 'tab' => 'proyectos' ), $args ),
			admin_url( 'admin.php' )
		);
	}

	/** Proyectos de esta instalación (registry + settings): slug => etiqueta. */
	public static function projects_map(): array
	{
		$out = array();
		if ( class_exists( 'Estandar\\Core\\Leads\\ProjectRegistry' ) ) {
			foreach ( (array) \Estandar\Core\Leads\ProjectRegistry::get_all_slugs() as $slug ) {
				$slug = (string) $slug;
				if ( '' === $slug ) {
					continue;
				}
				$label = method_exists( 'Estandar\\Core\\Leads\\ProjectRegistry', 'label_for' )
					? (string) \Estandar\Core\Leads\ProjectRegistry::label_for( $slug )
					: '';
				$out[ $slug ] = '' !== trim( $label ) ? $label : $slug;
			}
		}
		if ( class_exists( 'Estandar\\Flow\\Quotes\\Settings' ) ) {
			foreach ( array_keys( \Estandar\Flow\Quotes\Settings::project_settings_all() ) as $slug ) {
				if ( ! isset( $out[ $slug ] ) ) {
					$out[ $slug ] = (string) $slug;
				}
			}
		}
		asort( $out );
		return $out;
	}

	/** Operador del proyecto (registry) o ''. */
	public static function project_operator( string $slug ): string
	{
		if ( class_exists( 'Estandar\\Core\\Leads\\ProjectRegistry' ) && method_exists( 'Estandar\\Core\\Leads\\ProjectRegistry', 'operator_for' ) ) {
			$op = (array) \Estandar\Core\Leads\ProjectRegistry::operator_for( $slug );
			return trim( (string) ( $op['name'] ?? '' ) );
		}
		return '';
	}

	/**
	 * Email del operador de un proyecto: registry (`operator_for`) →
	 * `data.operator_email` de los settings del proyecto → email de empresa.
	 * Devuelve '' si no hay ninguno (nunca fatal).
	 */
	public static function project_operator_email( string $slug ): string
	{
		$slug = sanitize_key( $slug );
		if ( '' !== $slug && class_exists( 'Estandar\\Core\\Leads\\ProjectRegistry' ) && method_exists( 'Estandar\\Core\\Leads\\ProjectRegistry', 'operator_for' ) ) {
			$op    = (array) \Estandar\Core\Leads\ProjectRegistry::operator_for( $slug );
			$email = sanitize_email( (string) ( $op['email'] ?? '' ) );
			if ( '' !== $email ) {
				return $email;
			}
		}
		if ( class_exists( 'Estandar\\Flow\\Quotes\\Settings' ) ) {
			$data  = \Estandar\Flow\Quotes\Settings::project_settings( $slug )['data'] ?? array();
			$email = sanitize_email( (string) ( is_array( $data ) ? ( $data['operator_email'] ?? '' ) : '' ) );
			if ( '' !== $email ) {
				return $email;
			}
			$issuer = \Estandar\Flow\Quotes\Settings::issuer();
			$email  = sanitize_email( (string) ( $issuer['company_email'] ?? '' ) );
			if ( '' !== $email ) {
				return $email;
			}
		}
		return '';
	}

	/** Leads globales (`es_leads`) con búsqueda y/o proyecto opcionales. */
	public static function project_leads( string $search = '', int $limit = 200, string $project_slug = '' ): array
	{
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return array();
		}
		$table = $wpdb->prefix . 'es_leads';
		if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}
		$limit = max( 1, min( 500, $limit ) );
		$slug  = sanitize_key( $project_slug );
		if ( '' !== trim( $search ) ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			if ( '' !== $slug ) {
				$rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT id, name, email, phone, project_slug FROM {$table} WHERE ( name LIKE %s OR email LIKE %s OR phone LIKE %s ) AND project_slug = %s ORDER BY id DESC LIMIT %d",
					$like, $like, $like, $slug, $limit
				), ARRAY_A );
			} else {
				$rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT id, name, email, phone, project_slug FROM {$table} WHERE name LIKE %s OR email LIKE %s OR phone LIKE %s ORDER BY id DESC LIMIT %d",
					$like, $like, $like, $limit
				), ARRAY_A );
			}
		} elseif ( '' !== $slug ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, name, email, phone, project_slug FROM {$table} WHERE project_slug = %s ORDER BY id DESC LIMIT %d",
				$slug, $limit
			), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, name, email, phone, project_slug FROM {$table} ORDER BY id DESC LIMIT %d",
				$limit
			), ARRAY_A );
		}
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Paso 5 sin borrador: crea la cotización y redirige con `quote=<id>`
	 * (idempotente: refrescar ya no crea). Se ejecuta ANTES de emitir HTML.
	 */
	public static function maybe_prepare_projects(): void
	{
		if ( 'estandar-suite-flow' !== sanitize_key( (string) ( $_GET['page'] ?? '' ) ) ) {
			return;
		}
		if ( 'proyectos' !== sanitize_key( (string) ( $_GET['tab'] ?? '' ) ) ) {
			return;
		}
		if ( 5 !== (int) ( $_GET['step'] ?? 0 ) ) {
			return;
		}
		if ( (int) ( $_GET['quote'] ?? 0 ) > 0 ) {
			return;
		}
		$lead_id = (int) ( $_GET['lead'] ?? 0 );
		if ( $lead_id <= 0 || ! class_exists( 'Estandar\\Flow\\Quotes' ) ) {
			return;
		}
		$lead  = self::lead_row( $lead_id );
		$title = trim( (string) ( $lead['project_name'] ?? '' ) );
		if ( '' === $title ) {
			$name  = trim( (string) ( $lead['name'] ?? '' ) );
			$title = '' !== $name ? 'Cotización — ' . $name : 'Cotización';
		}
		$quote_id = (int) \Estandar\Flow\Quotes::create( array(
			'lead_id' => $lead_id,
			'title'   => $title,
			'status'  => 'draft',
		) );
		// Sin borrador creado NO se redirige (evita el bucle): el paso 5 se
		// renderiza igual, sin `quote`, para que el error sea visible/reintentable.
		if ( $quote_id <= 0 ) {
			return;
		}
		$args = array(
			'project' => sanitize_key( (string) ( $_GET['project'] ?? '' ) ),
			'step'    => 5,
			'lead'    => $lead_id,
			'quote'   => $quote_id,
		);
		wp_safe_redirect( self::projects_url( $args ) );
		exit;
	}

	/** Renderiza el wizard según `step` (incluye la plantilla del producto). */
	public static function render_projects_screen(): void
	{
		$project  = sanitize_key( (string) ( $_GET['project'] ?? '' ) );
		$step     = (int) ( $_GET['step'] ?? 0 );
		$lead_id  = (int) ( $_GET['lead'] ?? 0 );
		$quote_id = (int) ( $_GET['quote'] ?? 0 );
		$search   = isset( $_GET['q'] ) ? sanitize_text_field( (string) $_GET['q'] ) : '';

		$notice      = '';
		$notice_type = 'info';
		if ( isset( $_GET['saved'] ) ) {
			$notice      = 'Datos guardados.';
			$notice_type = 'ok';
		}
		if ( ! empty( $_GET['quote_saved'] ) ) {
			$notice      = 'Cotización guardada.';
			$notice_type = 'ok';
		}
		if ( ! empty( $_GET['sent'] ) ) {
			$notice      = '¡Cotización enviada!';
			$notice_type = 'ok';
		}
		if ( ! empty( $_GET['quote_error'] ) ) {
			$notice      = ( '' !== $notice ? $notice . ' ' : '' ) . 'No se pudo completar la operación: ' . sanitize_text_field( (string) $_GET['quote_error'] );
			$notice_type = 'error';
		}
		if ( ! empty( $_GET['lead_created'] ) ) {
			$notice      = 'Lead añadido al proyecto.';
			$notice_type = 'ok';
		}
		if ( ! empty( $_GET['lead_error'] ) ) {
			$notice      = ( '' !== $notice ? $notice . ' ' : '' ) . 'No se pudo añadir el lead (revisa nombre y email o teléfono).';
			$notice_type = 'error';
		}

		$has_settings = class_exists( 'Estandar\\Flow\\Quotes\\Settings' );
		$has_editor   = class_exists( 'Estandar\\Flow\\Quotes\\Editor' ) && class_exists( 'Estandar\\Flow\\Quotes\\DocumentBuilder' );

		$projects                = self::projects_map();
		$project_label           = ( '' !== $project && isset( $projects[ $project ] ) ) ? $projects[ $project ] : $project;
		$project_operator        = '' !== $project ? self::project_operator( $project ) : '';
		$project_settings        = ( $has_settings && '' !== $project ) ? \Estandar\Flow\Quotes\Settings::project_settings( $project ) : array();
		$templates               = $has_settings ? \Estandar\Flow\Quotes\Settings::templates() : array();
		$selected_template       = (string) ( $project_settings['template'] ?? ( $has_settings ? \Estandar\Flow\Quotes\Settings::default_template_id() : '' ) );

		$doc = array();
		if ( $has_editor && 2 === $step ) {
			$doc = \Estandar\Flow\Quotes\DocumentBuilder::for_quote( 0, array( 'project_slug' => $project ) );
		} elseif ( $has_editor && 5 === $step ) {
			$lead_for_doc = $lead_id > 0 ? self::lead_row( $lead_id ) : array();
			$doc          = \Estandar\Flow\Quotes\DocumentBuilder::for_quote( $quote_id, $lead_for_doc );
			$saved        = \Estandar\Flow\Quotes\Editor::load_saved( $quote_id );
			if ( ! empty( $saved ) ) {
				$doc = \Estandar\Flow\Quotes\Editor::merge_saved( $doc, $saved );
			}
		}

		$leads = 4 === $step ? self::project_leads( $search, 200, $project ) : array();
		$lead  = ( 5 === $step && $lead_id > 0 ) ? self::lead_row( $lead_id ) : array();
		$quote = ( 6 === $step && $quote_id > 0 && class_exists( 'Estandar\\Flow\\Quotes' ) ) ? \Estandar\Flow\Quotes::get( $quote_id ) : null;
		if ( 6 === $step && empty( $lead ) && is_array( $quote ) && (int) ( $quote['lead_id'] ?? 0 ) > 0 ) {
			$lead = self::lead_row( (int) $quote['lead_id'] );
		}
		$lead_email = (string) ( $lead['email'] ?? '' );

		$preview_url = add_query_arg( array(
			'action'   => 'estandar_suite_flow_quote_preview',
			'quote_id' => $quote_id,
			'_wpnonce' => wp_create_nonce( 'estandar_suite_flow_quote_preview' ),
		), admin_url( 'admin-post.php' ) );
		$download_url = add_query_arg( 'download', '1', $preview_url );
		$project_operator_email = '' !== $project ? self::project_operator_email( $project ) : '';

		include ESTANDAR_SUITE_FLOW_PATH . 'includes/templates/feature-projects.php';
	}

	/** admin_post: guarda header/bottom/plantilla del proyecto (paso 1). */
	public static function handle_proj_header_bottom(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_flow_proj_header_bottom' );
		if ( ! class_exists( 'Estandar\\Flow\\Quotes\\Settings' ) ) {
			wp_die( 'Módulo de cotizaciones no disponible.' );
		}
		$slug = sanitize_key( (string) ( $_POST['project'] ?? '' ) );
		if ( '' === $slug ) {
			wp_die( 'Proyecto inválido.' );
		}
		$template = sanitize_key( (string) ( $_POST['template'] ?? '' ) );
		if ( '' === $template ) {
			$template = \Estandar\Flow\Quotes\Settings::default_template_id();
		}

		// Galería del proyecto: JSON de `[{id,url,caption}]` (máx. 6, saneado en Settings).
		// Solo se toca si el campo vino en el POST (no borra la galería si falta).
		$entry = array(
			'header_image_id'  => (int) ( $_POST['header_image_id'] ?? 0 ),
			'header_image_url' => esc_url_raw( (string) ( $_POST['header_image_url'] ?? '' ) ),
			'bottom_image_id'  => (int) ( $_POST['bottom_image_id'] ?? 0 ),
			'bottom_image_url' => esc_url_raw( (string) ( $_POST['bottom_image_url'] ?? '' ) ),
			'template'         => $template,
		);
		if ( isset( $_POST['project_images_json'] ) ) {
			$decoded = json_decode( (string) wp_unslash( $_POST['project_images_json'] ), true );
			$entry['images'] = is_array( $decoded ) ? $decoded : array();
		}

		\Estandar\Flow\Quotes\Settings::save_project_entry( $slug, $entry );
		wp_safe_redirect( self::projects_url( array( 'project' => $slug, 'step' => 2 ) ) );
		exit;
	}

	/**
	 * admin_post: crea un lead en la base general (`es_leads`) para el proyecto
	 * seleccionado y vuelve al paso 4. Decisión: se redirige a `step=4` con
	 * aviso, no a `step=5&lead=<nuevo>`, para conservar el aviso de éxito y
	 * dejar que el nuevo lead (id DESC, primero de la lista) se elija a mano.
	 */
	public static function handle_proj_lead_create(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_flow_proj_lead_create' );
		$project = sanitize_key( (string) ( $_POST['project'] ?? '' ) );
		$name    = sanitize_text_field( (string) wp_unslash( $_POST['name'] ?? '' ) );
		$email   = sanitize_email( (string) wp_unslash( $_POST['email'] ?? '' ) );
		$phone   = sanitize_text_field( (string) wp_unslash( $_POST['phone'] ?? '' ) );

		$base = array( 'project' => $project, 'step' => 4 );
		if ( '' === $name || ( '' === $email && '' === $phone ) ) {
			wp_safe_redirect( self::projects_url( array_merge( $base, array( 'lead_error' => 'datos' ) ) ) );
			exit;
		}
		$lead_id = self::create_project_lead( array(
			'name'         => $name,
			'email'        => $email,
			'phone'        => $phone,
			'project_slug' => $project,
		) );
		if ( $lead_id <= 0 ) {
			wp_safe_redirect( self::projects_url( array_merge( $base, array( 'lead_error' => 'create' ) ) ) );
			exit;
		}
		wp_safe_redirect( self::projects_url( array_merge( $base, array( 'lead_created' => $lead_id ) ) ) );
		exit;
	}

	/**
	 * Crea un lead por el camino canónico: LeadsPanel::operator_create_lead →
	 * CrmIngestion::capture → inserción directa en `es_leads` (último recurso).
	 * Devuelve el ID (>0) o 0. Nunca fatal sin Core.
	 */
	public static function create_project_lead( array $input ): int
	{
		$name    = trim( (string) ( $input['name'] ?? '' ) );
		$email   = (string) ( $input['email'] ?? '' );
		$phone   = (string) ( $input['phone'] ?? '' );
		$project = (string) ( $input['project_slug'] ?? '' );
		if ( '' === $name || ( '' === $email && '' === $phone ) ) {
			return 0;
		}
		if ( class_exists( 'Estandar\\Core\\LeadsPanel' ) && method_exists( 'Estandar\\Core\\LeadsPanel', 'operator_create_lead' ) ) {
			$r   = \Estandar\Core\LeadsPanel::operator_create_lead( array(
				'name'         => $name,
				'email'        => $email,
				'phone'        => $phone,
				'project_slug' => $project,
			) );
			$lid = (int) ( is_array( $r ) ? ( $r['lead_id'] ?? 0 ) : 0 );
			if ( $lid > 0 ) {
				return $lid;
			}
		}
		if ( class_exists( 'Estandar\\Core\\Leads\\CrmIngestion' ) && method_exists( 'Estandar\\Core\\Leads\\CrmIngestion', 'capture' ) ) {
			$lid = (int) \Estandar\Core\Leads\CrmIngestion::capture( array(
				'source_plugin' => 'estandar-flow-projects',
				'contact'       => array( 'name' => $name, 'email' => $email, 'phone' => $phone ),
				'context'       => array( 'project_slug' => sanitize_key( $project ), 'project_area' => '' ),
				'meta'          => array(),
				'attribution'   => array(),
				'request'       => array(),
			) );
			if ( $lid > 0 ) {
				return $lid;
			}
		}
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return 0;
		}
		$now = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
		$ok  = $wpdb->insert( $wpdb->prefix . 'es_leads', array(
			'name'         => $name,
			'email'        => sanitize_email( $email ),
			'phone'        => $phone,
			'project_slug' => sanitize_key( $project ),
			'status'       => 'NEW',
			'created_at'   => $now,
			'updated_at'   => $now,
		) );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/** admin_post: persiste los datos prefijados de la plantilla (paso 2). */
	public static function handle_proj_save_template(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_flow_proj_save_template' );
		if ( ! class_exists( 'Estandar\\Flow\\Quotes\\Settings' ) || ! class_exists( 'Estandar\\Flow\\Quotes\\Editor' ) ) {
			wp_die( 'Módulo de cotizaciones no disponible.' );
		}
		$slug = sanitize_key( (string) ( $_POST['project'] ?? '' ) );
		if ( '' === $slug ) {
			wp_die( 'Proyecto inválido.' );
		}
		$doc   = \Estandar\Flow\Quotes\Editor::normalize_post( wp_unslash( $_POST ), 0 );
		$data  = \Estandar\Flow\Quotes\Settings::project_data_from_document( $doc );
		$entry = array( 'data' => $data );
		// Opciones de campos (selects del editor): una por línea, saneadas.
		if ( isset( $_POST['field_options'] ) ) {
			$entry['field_options'] = \Estandar\Flow\Quotes\Settings::sanitize_field_options( wp_unslash( $_POST['field_options'] ) );
		}
		// Galería editable del editor de plantilla: persiste en los settings del
		// proyecto (solo si el control la envió).
		if ( array_key_exists( 'images', $doc ) && is_array( $doc['images'] ) ) {
			$entry['images'] = $doc['images'];
		}
		\Estandar\Flow\Quotes\Settings::save_project_entry( $slug, $entry );
		wp_safe_redirect( self::projects_url( array( 'project' => $slug, 'step' => 3, 'saved' => 1 ) ) );
		exit;
	}

	/** admin_post: guarda el borrador de cotización del wizard (paso 5). */
	public static function handle_proj_quote_save(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_flow_proj_quote_save' );
		if ( ! class_exists( 'Estandar\\Flow\\Quotes\\Editor' ) ) {
			wp_die( 'Editor no disponible.' );
		}
		$lead_id = (int) ( $_POST['lead_id'] ?? 0 );
		$project = sanitize_key( (string) ( $_POST['project'] ?? '' ) );
		$next    = ! empty( $_POST['wizard_next'] );
		$r       = \Estandar\Flow\Quotes\Editor::save_from_post( $lead_id, wp_unslash( $_POST ), self::current_operator() );
		if ( empty( $r['ok'] ) ) {
			wp_safe_redirect( self::projects_url( array(
				'project'     => $project,
				'step'        => 5,
				'lead'        => $lead_id,
				'quote'       => (int) ( $_POST['quote_id'] ?? 0 ),
				'quote_error' => (string) ( $r['error'] ?? 'desconocido' ),
			) ) );
			exit;
		}
		$qid = (int) ( $r['quote_id'] ?? 0 );
		if ( $next ) {
			wp_safe_redirect( self::projects_url( array( 'project' => $project, 'step' => 6, 'quote' => $qid, 'lead' => $lead_id ) ) );
			exit;
		}
		wp_safe_redirect( self::projects_url( array( 'project' => $project, 'step' => 5, 'quote' => $qid, 'lead' => $lead_id, 'quote_saved' => 1 ) ) );
		exit;
	}

	/** admin_post (GET): stream INLINE del PDF real de una cotización (paso 6). */
	public static function handle_quote_preview(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_flow_quote_preview' );
		$id = (int) ( $_GET['quote_id'] ?? 0 );
		if ( ! class_exists( 'Estandar\\Flow\\Quotes\\DocumentBuilder' ) || ! class_exists( 'Estandar\\Flow\\Quotes\\Document\\Pdf' ) ) {
			wp_die( 'Módulo de PDF no disponible.' );
		}
		$lead = array();
		if ( class_exists( 'Estandar\\Flow\\Quotes' ) ) {
			$quote = \Estandar\Flow\Quotes::get( $id );
			$lid   = is_array( $quote ) ? (int) ( $quote['lead_id'] ?? 0 ) : 0;
			if ( $lid > 0 ) {
				$lead = self::lead_row( $lid );
			}
		}
		$doc = \Estandar\Flow\Quotes\DocumentBuilder::for_quote( $id, $lead );
		$pdf = \Estandar\Flow\Quotes\Document\Pdf::render( $doc );
		if ( empty( $pdf['ok'] ) || '' === (string) ( $pdf['bytes'] ?? '' ) ) {
			wp_die( 'No se pudo generar el PDF: ' . esc_html( (string) ( $pdf['error'] ?? 'error' ) ) );
		}
		// `download=1`: fuerza la descarga (adjunto) con el número de cotización.
		$download = ! empty( $_GET['download'] );
		$filename = '';
		if ( $download ) {
			$number   = trim( (string) ( $doc['meta']['quote_number'] ?? '' ) );
			$filename = '' !== $number ? 'Cotizacion-' . sanitize_file_name( $number ) . '.pdf' : '';
		}
		if ( '' === $filename ) {
			$filename = \Estandar\Flow\Quotes\Document\Pdf::filename( $doc );
		}
		$disposition = $download ? 'attachment' : 'inline';

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: ' . $disposition . '; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( (string) $pdf['bytes'] ) );
		echo (string) $pdf['bytes']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/** Catálogo de sub-sub-tabs (ss=) del sub-tab Cotizaciones (fuente única). */
	public static function quotes_ssubs(): array
	{
		return array(
			'resumen'      => 'Resumen',
			'v2'           => 'Cotizaciones v2',
			'cotizaciones' => 'Cotizaciones',
			'editor'       => 'Editor cotización',
			'branding'     => 'Branding',
			'templates'    => 'Plantillas',
			'documento'    => 'Documento',
			'finanzas'     => 'Finanzas',
			'sala_ventas'  => 'Sala de ventas',
			'autoridad'    => 'Autoridad',
			'email'        => 'Email',
			'settings'     => 'Settings',
		);
	}
}
