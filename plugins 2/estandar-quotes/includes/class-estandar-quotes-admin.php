<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** Small server-rendered operational workspace. Controllers only orchestrate existing Quotes services. */
class Estandar_Quotes_Admin {
	const CAP_VIEW = 'estandar_quotes_view'; const CAP_EDIT = 'estandar_quotes_edit_drafts'; const CAP_ISSUE = 'estandar_quotes_issue'; const CAP_PDF = 'estandar_quotes_generate_pdf'; const CAP_SEND = 'estandar_quotes_send'; const CAP_SETTINGS = 'estandar_quotes_manage_settings';
	public static function init(): void { self::activate_capabilities(); add_filter( 'estandar_core_navigation_schema', array( __CLASS__, 'navigation_schema' ) ); add_action( 'admin_menu', array( __CLASS__, 'menu' ) ); add_action( 'admin_init', array( __CLASS__, 'settings' ) ); add_action( 'admin_post_estandar_quotes_artifact', array( __CLASS__, 'download_artifact' ) ); add_action( 'admin_post_estandar_quotes_export_settings', array( __CLASS__, 'export_settings' ) ); add_action( 'admin_post_estandar_quotes_import_settings', array( __CLASS__, 'import_settings' ) ); }
	public static function activate_capabilities(): void { if ( $role = get_role( 'administrator' ) ) { foreach ( array( self::CAP_VIEW, self::CAP_EDIT, self::CAP_ISSUE, self::CAP_PDF, self::CAP_SEND, self::CAP_SETTINGS ) as $cap ) { $role->add_cap( $cap ); } } }
	public static function navigation_schema( array $schema ): array { $schema['top_nav']['quotes'] = array( 'label'=>'Cotizaciones', 'bar_title'=>'Cotizaciones', 'url'=>admin_url( 'admin.php?page=estandar-quotes' ), 'capability'=>self::CAP_VIEW, 'order'=>55, 'module'=>'quotes' ); $schema['pages']['estandar-quotes'] = array( 'title'=>'Cotizaciones · Estandar', 'menu_title'=>'Cotizaciones', 'visible'=>false, 'shell'=>true, 'module'=>'quotes' ); $schema['pages']['estandar-quotes-settings'] = array( 'title'=>'Ajustes de cotizaciones · Estandar', 'menu_title'=>'Ajustes', 'visible'=>false, 'shell'=>true, 'module'=>'quotes' ); return $schema; }
	public static function menu(): void { if ( function_exists( 'estandar_register_navigation_page' ) ) { estandar_register_navigation_page( array( 'slug'=>'estandar-quotes', 'title'=>'Cotizaciones · Estandar', 'capability'=>self::CAP_VIEW, 'callback'=>array( __CLASS__, 'render_workspace' ), 'header_slot'=>static function (): void { self::tabs( 'workspace' ); } ) ); estandar_register_navigation_page( array( 'slug'=>'estandar-quotes-settings', 'title'=>'Ajustes de cotizaciones · Estandar', 'capability'=>self::CAP_SETTINGS, 'callback'=>array( __CLASS__, 'render_settings' ), 'header_slot'=>static function (): void { self::tabs( 'settings' ); } ) ); } else { add_submenu_page( null, 'Cotizaciones', '', self::CAP_VIEW, 'estandar-quotes', array( __CLASS__, 'render_workspace' ) ); add_submenu_page( null, 'Ajustes de cotizaciones', '', self::CAP_SETTINGS, 'estandar-quotes-settings', array( __CLASS__, 'render_settings' ) ); } }
	public static function settings(): void { register_setting( 'estandar_quotes_settings', 'estandar_quotes_defaults', array( 'sanitize_callback' => array( __CLASS__, 'sanitize_defaults' ) ) ); 		add_settings_section( 'estandar_quotes_defaults', 'Valores predeterminados', '__return_false', 'estandar-quotes-settings' ); add_settings_field( 'estandar_quotes_defaults', 'Valores predeterminados', array( __CLASS__, 'settings_field' ), 'estandar-quotes-settings', 'estandar_quotes_defaults' ); }
	public static function sanitize_defaults( $value ): array {
		$v = is_array( $value ) ? $value : array();
		return array(
			'active_profile'        => sanitize_key( $v['active_profile'] ?? Estandar_Quotes_Real_Estate_Profile::KEY ),
			'company_name'          => sanitize_text_field( $v['company_name'] ?? '' ),
			'company_logo_id'       => absint( $v['company_logo_id'] ?? 0 ),
			'company_website'       => esc_url_raw( $v['company_website'] ?? '' ),
			'company_phone'         => sanitize_text_field( $v['company_phone'] ?? '' ),
			'company_email'         => sanitize_email( $v['company_email'] ?? '' ),
			'company_address'       => sanitize_textarea_field( $v['company_address'] ?? '' ),
			'primary_color'         => preg_match( '/^#[a-fA-F0-9]{6}$/', (string) ( $v['primary_color'] ?? '' ) ) ? sanitize_text_field( $v['primary_color'] ) : '#1d2327',
			'accent_color'          => preg_match( '/^#[a-fA-F0-9]{6}$/', (string) ( $v['accent_color'] ?? '' ) ) ? sanitize_text_field( $v['accent_color'] ) : '#2271b1',
			'font_family'           => sanitize_text_field( $v['font_family'] ?? '' ),
			'document_title'        => sanitize_text_field( $v['document_title'] ?? 'Cotización' ),
			'document_subtitle'     => sanitize_text_field( $v['document_subtitle'] ?? '' ),
			'validity_days'         => max( 1, absint( $v['validity_days'] ?? 7 ) ),
			'uf_source'             => sanitize_text_field( $v['uf_source'] ?? 'manual' ),
			'uf_value_clp'          => is_numeric( $v['uf_value_clp'] ?? null ) ? (float) $v['uf_value_clp'] : null,
			'annual_rate_percent'   => is_numeric( $v['annual_rate_percent'] ?? null ) ? (float) $v['annual_rate_percent'] : null,
			'credit_percent'        => min( 100, max( 0, (float) ( $v['credit_percent'] ?? 80 ) ) ),
			'income_multiple'       => max( 1, (float) ( $v['income_multiple'] ?? 4 ) ),
			'sales_room_name'       => sanitize_text_field( $v['sales_room_name'] ?? '' ),
			'sales_room_address'    => sanitize_text_field( $v['sales_room_address'] ?? '' ),
			'sales_room_phone'      => sanitize_text_field( $v['sales_room_phone'] ?? '' ),
			'sales_room_email'      => sanitize_email( $v['sales_room_email'] ?? '' ),
			'sales_room_hours'      => sanitize_text_field( $v['sales_room_hours'] ?? '' ),
			'authority_name'        => sanitize_text_field( $v['authority_name'] ?? '' ),
			'authority_role'        => sanitize_text_field( $v['authority_role'] ?? '' ),
			'authority_id'          => sanitize_text_field( $v['authority_id'] ?? '' ),
			'operator_copy'         => sanitize_email( $v['operator_copy'] ?? '' ),
			'conditions'            => sanitize_textarea_field( $v['conditions'] ?? '' ),
			'footer_legal'          => sanitize_textarea_field( $v['footer_legal'] ?? '' ),
			'footer_company'        => sanitize_text_field( $v['footer_company'] ?? '' ),
			'sender_name'           => sanitize_text_field( $v['sender_name'] ?? '' ),
			'sender_email'          => sanitize_email( $v['sender_email'] ?? '' ),
			'email_subject_template' => sanitize_text_field( $v['email_subject_template'] ?? 'Cotización' ),
			'email_cc'              => sanitize_text_field( $v['email_cc'] ?? '' ),
			'email_bcc'             => sanitize_text_field( $v['email_bcc'] ?? '' ),
			'renderer'              => sanitize_key( $v['renderer'] ?? 'dompdf' ),
		);
	}
	public static function settings_field(): void {
		$v = self::defaults();
		echo '<h3>Plantilla</h3>';
		$profile_options = array(
			Estandar_Quotes_Real_Estate_Profile::KEY  => 'Real Estate (genérico)',
			Estandar_Quotes_Acacios_Profile::KEY       => 'Acacios Real Estate',
		);
		echo '<p><label>Perfil activo<br><select name="estandar_quotes_defaults[active_profile]">';
		foreach ( $profile_options as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $v['active_profile'], $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label></p>';

		self::settings_row( $v, 'document_title', 'Título del documento' );
		self::settings_row( $v, 'document_subtitle', 'Subtítulo' );

		echo '<h3>Empresa / Branding</h3>';
		self::settings_row( $v, 'company_name', 'Nombre comercial' );
		self::settings_row( $v, 'company_logo_id', 'ID del logo (adjunto WordPress)' );
		self::settings_row( $v, 'company_website', 'Sitio web' );
		self::settings_row( $v, 'company_phone', 'Teléfono' );
		self::settings_row( $v, 'company_email', 'Email corporativo' );
		self::settings_row( $v, 'company_address', 'Dirección', true );
		self::settings_color( $v, 'primary_color', 'Color principal' );
		self::settings_color( $v, 'accent_color', 'Color de acento' );
		self::settings_row( $v, 'font_family', 'Tipografía (CSS font-family)' );

		echo '<h3>Sala de ventas</h3>';
		self::settings_row( $v, 'sales_room_name', 'Nombre' );
		self::settings_row( $v, 'sales_room_address', 'Dirección' );
		self::settings_row( $v, 'sales_room_phone', 'Teléfono' );
		self::settings_row( $v, 'sales_room_email', 'Email' );
		self::settings_row( $v, 'sales_room_hours', 'Horario' );

		echo '<h3>Emisor / Firma</h3>';
		self::settings_row( $v, 'authority_name', 'Nombre del emisor' );
		self::settings_row( $v, 'authority_role', 'Cargo' );
		self::settings_row( $v, 'authority_id', 'Identificador' );

		echo '<h3>Valores predeterminados de cotización</h3>';
		self::settings_row( $v, 'validity_days', 'Vigencia (días)' );
		self::settings_row( $v, 'uf_source', 'Fuente UF' );
		self::settings_row( $v, 'uf_value_clp', 'Valor UF en CLP' );
		self::settings_row( $v, 'annual_rate_percent', 'Tasa anual (%)' );
		self::settings_row( $v, 'credit_percent', 'Crédito (%)' );
		self::settings_row( $v, 'income_multiple', 'Multiplicador de renta' );

		echo '<h3>Condiciones y cierre</h3>';
		echo '<p><label>Condiciones predeterminadas<br><textarea class="large-text" rows="4" name="estandar_quotes_defaults[conditions]">' . esc_textarea( $v['conditions'] ?? '' ) . '</textarea></label></p>';
		echo '<p><label>Información legal (pie de página)<br><textarea class="large-text" rows="3" name="estandar_quotes_defaults[footer_legal]">' . esc_textarea( $v['footer_legal'] ?? '' ) . '</textarea></label></p>';
		self::settings_row( $v, 'footer_company', 'Cierre corporativo' );

		echo '<h3>Correo</h3>';
		self::settings_row( $v, 'sender_name', 'Nombre del remitente' );
		self::settings_row( $v, 'sender_email', 'Email del remitente' );
		self::settings_row( $v, 'email_subject_template', 'Asunto del correo' );
		self::settings_row( $v, 'operator_copy', 'Copia al operador (email)' );
		self::settings_row( $v, 'email_cc', 'CC' );
		self::settings_row( $v, 'email_bcc', 'BCC' );

		echo '<h3>Renderer</h3>';
		self::settings_row( $v, 'renderer', 'Renderer PDF (dompdf)' );
	}

	private static function settings_row( array $defaults, string $key, string $label, bool $textarea = false ): void {
		$value = (string) ( $defaults[ $key ] ?? '' );
		echo '<p><label>' . esc_html( $label ) . '<br>';
		if ( $textarea ) {
			echo '<textarea class="large-text" rows="2" name="estandar_quotes_defaults[' . esc_attr( $key ) . ']">' . esc_textarea( $value ) . '</textarea>';
		} else {
			echo '<input class="regular-text" name="estandar_quotes_defaults[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '">';
		}
		echo '</label></p>';
	}

	private static function settings_color( array $defaults, string $key, string $label ): void {
		$value = (string) ( $defaults[ $key ] ?? '#000000' );
		echo '<p><label>' . esc_html( $label ) . '<br><input type="color" name="estandar_quotes_defaults[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '"> ' . esc_html( $value ) . '</label></p>';
	}
	public static function render_settings(): void {
		if ( ! current_user_can( self::CAP_SETTINGS ) ) { wp_die( 'No autorizado.' ); }
		echo '<div class="wrap">';
		echo '<form method="post" action="options.php">';
		settings_fields( 'estandar_quotes_settings' );
		do_settings_sections( 'estandar-quotes-settings' );
		submit_button();
		echo '</form>';

		echo '<hr>';
		echo '<h2>Exportar / Importar configuración</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:12px">';
		echo '<input type="hidden" name="action" value="estandar_quotes_export_settings">';
		wp_nonce_field( 'estandar_quotes_export_settings' );
		submit_button( 'Exportar configuración (JSON)', 'secondary', '', false );
		echo '</form>';

		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block">';
		echo '<input type="hidden" name="action" value="estandar_quotes_import_settings">';
		wp_nonce_field( 'estandar_quotes_import_settings' );
		echo '<input type="file" name="settings_file" accept=".json"> ';
		submit_button( 'Importar configuración', 'secondary', '', false );
		echo '</form>';
		echo '</div>';
	}

	/** Export settings as a JSON download. Excludes storage-only fields. */
	public static function export_settings(): void {
		if ( ! current_user_can( self::CAP_SETTINGS ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'estandar_quotes_export_settings' ) ) {
			wp_die( 'No autorizado.' );
		}
		$settings = self::defaults();
		unset( $settings['company_logo_id'] );
		$json = wp_json_encode( $settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$filename = 'estandar-quotes-settings-' . gmdate( 'Ymd-His' ) . '.json';
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $json ) );
		echo $json;
		exit;
	}

	/** Import settings from uploaded JSON file. Merges with existing, does not replace. */
	public static function import_settings(): void {
		if ( ! current_user_can( self::CAP_SETTINGS ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'estandar_quotes_import_settings' ) ) {
			wp_die( 'No autorizado.' );
		}
		$notify = 'import_failed';
		if ( ! empty( $_FILES['settings_file']['tmp_name'] ) && is_uploaded_file( $_FILES['settings_file']['tmp_name'] ) ) {
			$size = (int) ( $_FILES['settings_file']['size'] ?? 0 );
			if ( $size > 0 && $size < 512 * 1024 ) {
				$contents = file_get_contents( $_FILES['settings_file']['tmp_name'] );
				$imported = json_decode( $contents, true );
				if ( is_array( $imported ) ) {
					$existing = get_option( 'estandar_quotes_defaults', array() );
					$merged = array_merge( $existing, $imported );
					$sanitized = self::sanitize_defaults( $merged );
					update_option( 'estandar_quotes_defaults', $sanitized, false );
					$notify = 'imported';
				}
			}
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'estandar-quotes-settings', 'esq_notice' => $notify ), admin_url( 'admin.php' ) ) );
		exit;
	}
	public static function render_workspace(): void {
		if ( ! current_user_can( self::CAP_VIEW ) ) { wp_die( esc_html__( 'No autorizado.', 'estandar-quotes' ) ); }
		self::handle_handoff(); self::handle_action(); $quote = self::requested_quote(); self::notice(); if ( $quote ) { self::editor( $quote ); } else { self::listing(); }
	}
	private static function handle_handoff(): void { $token = sanitize_text_field( wp_unslash( $_GET['estandar_quotes_handoff'] ?? '' ) ); if ( $token === '' ) { return; } if ( ! current_user_can( self::CAP_EDIT ) ) { self::redirect_notice( 'unauthorized' ); } $peek = Estandar_Quotes_Lead_Integration::peek_handoff( $token ); $op_id = absint( $peek['operator_id'] ?? 0 ); if ( $op_id > 0 ) { wp_safe_redirect( home_url( '/cotizacion/' . rawurlencode( $token ) ) ); exit; } $data = Estandar_Quotes_Lead_Integration::consume_handoff( $token, $op_id ); if ( ! $data ) { $submission_id = absint( $peek['submission_id'] ?? Estandar_Quotes_Lead_Integration::resolve_submission_from_token( $token ) ); if ( $submission_id > 0 ) { $existing = Estandar_Quotes_Repository::get_by_submission( $submission_id ); if ( $existing ) { $session_token = Estandar_Quotes_Operator_Session::create( (int) $existing['id'], $op_id ?: get_current_user_id() ); wp_safe_redirect( Estandar_Quotes_Operator_Session::url( $session_token ) ); exit; } } self::redirect_notice( 'handoff_invalid' ); } $existing = Estandar_Quotes_Repository::get_by_submission( (int) $data['submission_id'] ); if ( $existing ) { $session_token = Estandar_Quotes_Operator_Session::create( (int) $existing['id'], $op_id ?: get_current_user_id() ); wp_safe_redirect( Estandar_Quotes_Operator_Session::url( $session_token ) ); exit; } $hydrated = Estandar_Quotes_Hydration_Pipeline::hydrate_new( $data['lead_snapshot'], self::active_profile_key(), self::defaults() ); $defaults = $hydrated->to_array(); $id = Estandar_Quotes_Repository::create_draft( array_merge( $data, array( 'profile_key' => self::active_profile_key(), 'created_by' => get_current_user_id(), 'source' => 'lead_handoff' ) ) ); if ( ! $id || ! Estandar_Quotes_Repository::initialize_draft( $id, $defaults ) ) { self::redirect_notice( 'draft_failed' ); } $session_token = Estandar_Quotes_Operator_Session::create( $id, $op_id ?: get_current_user_id() ); wp_safe_redirect( Estandar_Quotes_Operator_Session::url( $session_token ) ); exit; }
	private static function handle_action(): void {
		if ( empty( $_POST['esq_action'] ) ) { return; } $action = sanitize_key( wp_unslash( $_POST['esq_action'] ) ); $uuid = sanitize_text_field( wp_unslash( $_POST['quote_uuid'] ?? '' ) ); $quote = Estandar_Quotes_Repository::get_by_uuid( $uuid ); if ( ! $quote || ! self::authorized( $quote ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ?? '' ) ), 'esq_' . $action . '_' . $uuid ) ) { self::redirect_notice( 'invalid_request' ); }
		try { if ( $action === 'save' ) { self::need( self::CAP_EDIT ); if ( ! Estandar_Quotes_Repository::save_draft( (int) $quote['id'], self::document_from_request( $quote ), get_current_user_id() ) ) { throw new RuntimeException(); } self::redirect_quote( $uuid, 'saved' ); }
			if ( $action === 'issue' ) { self::need( self::CAP_ISSUE ); $doc = self::document_from_request( $quote ); if ( ! Estandar_Quotes_Repository::save_draft( (int) $quote['id'], $doc ) ) { throw new RuntimeException(); } if ( $quote['status'] === 'draft' && ! Estandar_Quotes_Repository::transition( (int) $quote['id'], 'review' ) ) { throw new RuntimeException(); } $revision = Estandar_Quotes_Repository::add_revision( (int) $quote['id'], $doc ); if ( ! $revision || ! Estandar_Quotes_Repository::transition( (int) $quote['id'], 'issued' ) ) { throw new RuntimeException(); } self::redirect_quote( $uuid, 'issued' ); }
			if ( $action === 'pdf' ) { self::need( self::CAP_PDF ); $revision = self::revision( $quote, sanitize_text_field( wp_unslash( $_POST['revision_uuid'] ?? '' ) ) ); $preview = Estandar_Quotes_Preview_Service::resolve( $uuid, 'revision', $revision['revision_uuid'] ); if ( self::defaults()['renderer'] !== 'dompdf' ) { throw new RuntimeException( 'Renderer not supported.' ); } $renderer = Estandar_Quotes_PDF_Dompdf_Adapter::resolve(); Estandar_Quotes_Artifacts::generate( $quote, $preview, $renderer, $revision['revision_uuid'], ! empty( $_POST['regenerate'] ) ); self::redirect_quote( $uuid, 'pdf_ready' ); }
			if ( $action === 'send' ) { self::need( self::CAP_SEND ); $revision = self::revision( $quote, sanitize_text_field( wp_unslash( $_POST['revision_uuid'] ?? '' ) ) ); $artifact = self::artifact( $revision['revision_uuid'], absint( $_POST['artifact_id'] ?? 0 ) ); if ( (int) $artifact['byte_size'] < 100 || substr( $artifact['pdf_sha256'] ?? '', 0, 4 ) === '' ) { self::redirect_quote( $uuid, 'invalid_artifact' ); } $emails = array_filter( array_map( 'sanitize_email', explode( ',', (string) wp_unslash( $_POST['recipients'] ?? '' ) ) ) ); $operator_email = self::defaults()['operator_copy'] ?? ''; if ( is_email( $operator_email ) ) { $emails[] = sanitize_email( $operator_email ); } $emails = array_values( array_unique( array_filter( $emails, 'is_email' ) ) ); 			$doc = self::draft( $quote ); $delivery_meta = array( 'customer_name' => $doc['client']['name'] ?? '', 'summary' => ( $doc['project']['name'] ?? '' ) !== '' ? 'Cotización ' . sanitize_text_field( $doc['project']['name'] ) : '', 'executive_name' => $doc['issuer']['authority_name'] ?? '', 'expires_at' => $doc['validity']['expires_at'] ?? '', 'operator_email' => $operator_email, 'delivery_meta' => array( 'project_slug' => $quote['project_slug'] ?? '', 'quote_number' => $quote['quote_number'] ?? '000', 'revision_no' => $revision['revision_no'] ?? 1, ), ); $delivery = Estandar_Quotes_Delivery_Service::create( $revision, $artifact, $emails, sanitize_text_field( wp_unslash( $_POST['subject'] ?? 'Cotización' ) ), 'client', 'es_CL', $delivery_meta ); $ok = Estandar_Quotes_Delivery_Service::send( $delivery, $artifact, new Estandar_Quotes_WP_Mail_Transport() ); self::redirect_quote( $uuid, $ok ? 'sent' : 'send_failed' ); }
		} catch ( Throwable $e ) {
			error_log( '[estandar-quotes] Action failed: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
			if ( $action === 'pdf' ) {
				wp_die( esc_html( 'Error PDF: ' . $e->getMessage() . ' [' . basename( $e->getFile() ) . ':' . $e->getLine() . ']' ) );
			}
			self::redirect_quote( $uuid, 'action_failed&error=' . rawurlencode( $e->getMessage() ) );
		}
	}
	private static function listing(): void { global $wpdb; $search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ); $table = Estandar_Quotes_Schema::quotes_table(); $like = '%' . $wpdb->esc_like( $search ) . '%'; $rows = $search === '' ? $wpdb->get_results( "SELECT * FROM {$table} ORDER BY updated_at DESC LIMIT 100", ARRAY_A ) : $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE project_slug LIKE %s OR lead_snapshot_json LIKE %s OR status LIKE %s ORDER BY updated_at DESC LIMIT 100", $like, $like, $like ), ARRAY_A ); echo '<form method="get"><input type="hidden" name="page" value="estandar-quotes"><p><input name="s" value="' . esc_attr( $search ) . '" placeholder="Cliente, proyecto o estado"> <button class="button">Buscar</button></p></form><table class="widefat"><thead><tr><th>Cliente</th><th>Proyecto</th><th>Estado</th><th>Actualizada</th><th></th></tr></thead><tbody>'; foreach ( (array) $rows as $row ) { if ( ! self::authorized( $row ) ) { continue; } $lead = json_decode( $row['lead_snapshot_json'], true ); echo '<tr><td>' . esc_html( $lead['lead']['name'] ?? '' ) . '</td><td>' . esc_html( $row['project_slug'] ) . '</td><td>' . esc_html( $row['status'] ) . '</td><td>' . esc_html( $row['updated_at'] ) . '</td><td><a class="button" href="' . esc_url( add_query_arg( array( 'page'=>'estandar-quotes','quote'=>$row['quote_uuid'] ), admin_url( 'admin.php' ) ) ) . '">Abrir</a></td></tr>'; } echo '</tbody></table>'; }
	private static function editor( array $quote ): void { $doc = self::draft( $quote ); $readonly = $quote['status'] !== 'draft'; echo '<h2>' . esc_html( $quote['status'] ) . '</h2><form method="post">'; wp_nonce_field( 'esq_save_' . $quote['quote_uuid'] ); echo '<input type="hidden" name="quote_uuid" value="' . esc_attr( $quote['quote_uuid'] ) . '"><input type="hidden" name="esq_action" value="save">'; self::fields( $doc, $readonly ); if ( ! $readonly ) { submit_button( 'Guardar borrador' ); } echo '</form>'; if ( ! $readonly ) { self::action_form( $quote, 'issue', 'Emitir revisión', array() ); $preview = add_query_arg( array( 'page'=>'estandar-quotes-preview','quote'=>$quote['quote_uuid'],'source'=>'draft','_wpnonce'=>wp_create_nonce( 'estandar_quotes_preview_' . $quote['quote_uuid'] ) ), admin_url( 'admin.php' ) ); echo '<p><a class="button" target="_blank" href="' . esc_url( $preview ) . '">Previsualizar borrador</a></p>'; } self::history( $quote ); }
	private static function fields( array $d, bool $readonly ): void { echo Estandar_Quotes_Document_Form::render_fields( $d, $readonly, 'q' ); }
	private static function action_form( array $quote, string $action, string $label, array $extra ): void { echo '<form method="post" style="display:inline-block;margin-right:8px"><input type="hidden" name="quote_uuid" value="' . esc_attr( $quote['quote_uuid'] ) . '"><input type="hidden" name="esq_action" value="' . esc_attr( $action ) . '">'; wp_nonce_field( 'esq_' . $action . '_' . $quote['quote_uuid'] ); foreach ( $extra as $key=>$value ) { echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">'; } submit_button( $label, 'secondary', '', false ); echo '</form>'; }
	private static function history( array $quote ): void { global $wpdb; $revisions = Estandar_Quotes_Repository::revisions( (int) $quote['id'] ); echo '<h2>Historial</h2><ul>'; foreach ( $revisions as $r ) { $artifact = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Estandar_Quotes_Schema::artifacts_table() . ' WHERE revision_uuid=%s AND status=\'ready\' ORDER BY generation_no DESC LIMIT 1', $r['revision_uuid'] ), ARRAY_A ); echo '<li>Revisión ' . esc_html( $r['revision_no'] ) . ' · ' . esc_html( $r['created_at'] ) . ' '; $preview = add_query_arg( array( 'page'=>'estandar-quotes-preview','quote'=>$quote['quote_uuid'],'source'=>'revision','revision'=>$r['revision_uuid'],'_wpnonce'=>wp_create_nonce( 'estandar_quotes_preview_' . $quote['quote_uuid'] ) ), admin_url( 'admin.php' ) ); echo '<a target="_blank" href="' . esc_url( $preview ) . '">Preview</a> '; self::action_form( $quote, 'pdf', $artifact ? 'Regenerar PDF' : 'Generar PDF', array( 'revision_uuid'=>$r['revision_uuid'], 'regenerate'=>$artifact ? '1' : '' ) ); if ( $artifact ) { $pdf = add_query_arg( array( 'action'=>'estandar_quotes_artifact','quote'=>$quote['quote_uuid'],'artifact'=>$artifact['artifact_uuid'],'_wpnonce'=>wp_create_nonce( 'estandar_quotes_artifact_' . $artifact['artifact_uuid'] ) ), admin_url( 'admin-post.php' ) ); echo '<a class="button" target="_blank" href="' . esc_url( $pdf ) . '">Ver PDF</a> <form method="post" style="display:inline-block"><input type="hidden" name="quote_uuid" value="' . esc_attr( $quote['quote_uuid'] ) . '"><input type="hidden" name="esq_action" value="send"><input type="hidden" name="revision_uuid" value="' . esc_attr( $r['revision_uuid'] ) . '"><input type="hidden" name="artifact_id" value="' . absint( $artifact['id'] ) . '"><input name="recipients" placeholder="correo@cliente.cl"><input name="subject" value="Cotización - ' . esc_attr( ( self::draft( $quote )['project']['name'] ?? $quote['project_slug'] ?: 'Cotización' ) ) . '"><input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'esq_send_' . $quote['quote_uuid'] ) ) . '"><button class="button">Enviar</button></form>'; } echo '</li>'; } echo '</ul>'; $deliveries = $wpdb->get_results( $wpdb->prepare( 'SELECT delivery_uuid,status,attempts,sent_at,created_at FROM ' . Estandar_Quotes_Schema::deliveries_table() . ' WHERE revision_uuid IN (SELECT revision_uuid FROM ' . Estandar_Quotes_Schema::revisions_table() . ' WHERE quote_id=%d) ORDER BY id DESC LIMIT 20', $quote['id'] ), ARRAY_A ); echo '<h3>Entregas</h3><ul>'; foreach ( (array) $deliveries as $delivery ) { echo '<li>' . esc_html( $delivery['status'] ) . ' · intentos ' . esc_html( $delivery['attempts'] ) . ' · ' . esc_html( $delivery['sent_at'] ?: $delivery['created_at'] ) . '</li>'; } echo '</ul>'; }
	public static function download_artifact(): void { global $wpdb; $quote = Estandar_Quotes_Repository::get_by_uuid( sanitize_text_field( wp_unslash( $_GET['quote'] ?? '' ) ) ); $artifact_uuid = sanitize_text_field( wp_unslash( $_GET['artifact'] ?? '' ) ); if ( ! $quote || ! self::authorized( $quote ) || ! current_user_can( self::CAP_VIEW ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'estandar_quotes_artifact_' . $artifact_uuid ) ) { wp_die( esc_html__( 'No autorizado.', 'estandar-quotes' ), 403 ); } $artifact = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Estandar_Quotes_Schema::artifacts_table() . ' WHERE artifact_uuid=%s AND quote_id=%d AND status=\'ready\' LIMIT 1', $artifact_uuid, $quote['id'] ), ARRAY_A ); $key = is_array( $artifact ) ? (string) $artifact['storage_key'] : ''; if ( ! preg_match( '#^estandar-quotes/[a-f0-9-]+\\.pdf$#', $key ) ) { wp_die( esc_html__( 'PDF no disponible.', 'estandar-quotes' ), 404 ); } $path = trailingslashit( wp_upload_dir()['basedir'] ) . $key; 	if ( ! is_readable( $path ) ) { wp_die( esc_html__( 'PDF no disponible.', 'estandar-quotes' ), 404 ); } $project = sanitize_title( $quote['project_slug'] ?? 'cotizacion' ); $number = $quote['quote_number'] ?? '000'; $revision_no = $quote['current_revision_no'] ?? 1; $filename = 'Cotizacion-' . $project . '-' . $number . '-' . sprintf( '%02d', $revision_no ) . '.pdf'; nocache_headers(); header( 'Content-Type: application/pdf' ); header( 'Content-Length: ' . (string) filesize( $path ) ); header( 'Content-Disposition: inline; filename="' . $filename . '"' ); readfile( $path ); exit; }
	/**
	 * Active profile key for draft initialization. Reads the configured setting,
	 * falling back to the generic real_estate@1 profile.
	 */
	public static function active_profile_key(): string {
		$settings = self::defaults();
		$configured = sanitize_key( (string) ( $settings['active_profile'] ?? '' ) );
		if ( $configured === '' || ! in_array( $configured, array( Estandar_Quotes_Real_Estate_Profile::KEY, Estandar_Quotes_Acacios_Profile::KEY ), true ) ) {
			return Estandar_Quotes_Real_Estate_Profile::KEY;
		}
		return $configured;
	}


	private static function document_from_request( array $quote ): array { $q = (array) wp_unslash( $_POST['q'] ?? array() ); return Estandar_Quotes_Document_Form::document_from_request( self::draft( $quote ), $q, (string) $quote['profile_key'] ); }
	private static function draft( array $quote ): array { $d = json_decode( (string) ( $quote['draft_document_json'] ?? '' ), true ); if ( is_array( $d ) ) { return $d; } $hydrated = Estandar_Quotes_Hydration_Pipeline::hydrate_new( (array) json_decode( (string) $quote['lead_snapshot_json'], true ), (string) $quote['profile_key'], self::defaults() ); return $hydrated->to_array(); }
	private static function revision( array $quote, string $uuid ): array { global $wpdb; $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Estandar_Quotes_Schema::revisions_table() . ' WHERE quote_id=%d AND revision_uuid=%s LIMIT 1', $quote['id'], $uuid ), ARRAY_A ); if ( ! is_array( $row ) ) { throw new InvalidArgumentException(); } return $row; }
	private static function artifact( string $revision_uuid, int $id ): array { global $wpdb; $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Estandar_Quotes_Schema::artifacts_table() . ' WHERE id=%d AND revision_uuid=%s AND status=\'ready\' LIMIT 1', $id, $revision_uuid ), ARRAY_A ); if ( ! is_array( $row ) ) { throw new InvalidArgumentException(); } return $row; }
	private static function requested_quote(): ?array { $uuid = sanitize_text_field( wp_unslash( $_GET['quote'] ?? '' ) ); $quote = $uuid ? Estandar_Quotes_Repository::get_by_uuid( $uuid ) : null; return $quote && self::authorized( $quote ) ? $quote : null; }
	private static function authorized( array $quote ): bool { return (bool) apply_filters( 'estandar_quotes_authorized', current_user_can( 'manage_options' ) || (int) $quote['created_by'] === get_current_user_id(), $quote, get_current_user_id() ); }
	private static function need( string $cap ): void { if ( ! current_user_can( $cap ) ) { throw new RuntimeException(); } }
	private static function defaults(): array {
		return wp_parse_args( get_option( 'estandar_quotes_defaults', array() ), array(
			'active_profile'        => Estandar_Quotes_Real_Estate_Profile::KEY,
			'company_name'          => '',
			'company_logo_id'       => '',
			'company_website'       => '',
			'company_phone'         => '',
			'company_email'         => '',
			'company_address'       => '',
			'primary_color'         => '#1d2327',
			'accent_color'          => '#2271b1',
			'font_family'           => '',
			'document_title'        => 'Cotización',
			'document_subtitle'     => '',
			'validity_days'         => 7,
			'uf_source'             => 'manual',
			'uf_value_clp'          => null,
			'annual_rate_percent'   => null,
			'credit_percent'        => 80,
			'income_multiple'       => 4,
			'sales_room_name'       => '',
			'sales_room_address'    => '',
			'sales_room_phone'      => '',
			'sales_room_email'      => '',
			'sales_room_hours'      => '',
			'authority_name'        => '',
			'authority_role'        => '',
			'authority_id'          => '',
			'operator_copy'         => '',
			'conditions'            => '',
			'footer_legal'          => '',
			'footer_company'        => '',
			'sender_name'           => '',
			'sender_email'          => '',
			'email_subject_template' => 'Cotización',
			'email_cc'              => '',
			'email_bcc'             => '',
			'renderer'              => 'dompdf',
		) );
	}

	private static function notice(): void { $n = sanitize_key( wp_unslash( $_GET['esq_notice'] ?? '' ) ); $error_detail = sanitize_text_field( wp_unslash( $_GET['error'] ?? '' ) ); $messages = array( 'draft_created'=>'Borrador creado.','draft_existing'=>'Se abrió el borrador existente.','saved'=>'Borrador guardado.','issued'=>'Revisión emitida.','pdf_ready'=>'PDF generado.','sent'=>'Cotización enviada.','send_failed'=>'No fue posible enviar.','handoff_invalid'=>'El enlace expiró o ya fue utilizado.','unauthorized'=>'No autorizado.','action_failed'=>'No fue posible completar la acción.','invalid_request'=>'Solicitud no válida.','draft_failed'=>'No fue posible crear el borrador.','imported'=>'Configuración importada correctamente.','import_failed'=>'No fue posible importar el archivo.','invalid_artifact'=>'El PDF no es válido o está vacío. Regenera el PDF antes de enviar.' ); if ( isset( $messages[$n] ) ) { $msg = $messages[$n]; if ( $error_detail !== '' ) { $msg .= ' <code>' . esc_html( $error_detail ) . '</code>'; } echo '<div class="notice notice-' . ( strpos( $n, 'failed' ) !== false || strpos( $n, 'invalid' ) !== false || strpos( $n, 'unauthorized' ) !== false ? 'error' : 'success' ) . '"><p>' . $msg . '</p></div>'; } }
	private static function tabs( string $active ): void { $tabs = array( 'workspace'=>array( 'label'=>'Cotizaciones', 'url'=>admin_url( 'admin.php?page=estandar-quotes' ) ) ); if ( current_user_can( self::CAP_SETTINGS ) ) { $tabs['settings'] = array( 'label'=>'Ajustes', 'url'=>admin_url( 'admin.php?page=estandar-quotes-settings' ) ); } if ( function_exists( 'estandar_render_tabs' ) ) { estandar_render_tabs( $tabs, $active ); return; } echo '<h2 class="nav-tab-wrapper">'; foreach ( $tabs as $key=>$tab ) { echo '<a class="nav-tab' . ( $key === $active ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $tab['url'] ) . '">' . esc_html( $tab['label'] ) . '</a>'; } echo '</h2>'; }
	private static function redirect_quote( string $uuid, string $notice ): void { wp_safe_redirect( add_query_arg( array( 'page'=>'estandar-quotes','quote'=>$uuid,'esq_notice'=>$notice ), admin_url( 'admin.php' ) ) ); exit; }
	private static function redirect_notice( string $notice ): void { wp_safe_redirect( add_query_arg( array( 'page'=>'estandar-quotes','esq_notice'=>$notice ), admin_url( 'admin.php' ) ) ); exit; }
}
