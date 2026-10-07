<?php

namespace Estandar\Capture;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capture admin page — adoption status, historical preview, import (dry-run /
 * commit) and rollback. All mutations behind nonce + capability.
 */
class Admin
{
	public static function init(): void
	{
		add_action( 'estandar_suite_admin_register', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_estandar_suite_capture_adoption_import', array( __CLASS__, 'handle_import' ) );
		add_action( 'admin_post_estandar_suite_capture_adoption_rollback', array( __CLASS__, 'handle_rollback' ) );
		add_action( 'admin_post_estandar_suite_capture_save_feature', array( __CLASS__, 'handle_save_feature' ) );
		add_action( 'admin_post_estandar_suite_capture_save_form', array( __CLASS__, 'handle_save_form' ) );
		add_action( 'admin_post_estandar_suite_capture_delete_form', array( __CLASS__, 'handle_delete_form' ) );
		add_action( 'admin_post_estandar_suite_capture_restore_form', array( __CLASS__, 'handle_restore_form' ) );
		add_action( 'admin_post_estandar_suite_capture_purge_form', array( __CLASS__, 'handle_purge_form' ) );
		add_action( 'admin_post_estandar_suite_capture_duplicate_form', array( __CLASS__, 'handle_duplicate_form' ) );
		add_action( 'admin_post_estandar_suite_capture_consolidate_forms', array( __CLASS__, 'handle_consolidate_forms' ) );
		add_action( 'admin_post_estandar_suite_capture_form_delete_keep', array( __CLASS__, 'handle_form_delete_keep' ) );
		add_action( 'admin_post_estandar_suite_capture_purge_full', array( __CLASS__, 'handle_purge_full' ) );
		add_action( 'admin_post_estandar_suite_capture_save_popup', array( __CLASS__, 'handle_save_popup' ) );
		add_action( 'admin_post_estandar_suite_capture_delete_popup', array( __CLASS__, 'handle_delete_popup' ) );
		add_action( 'admin_post_estandar_suite_capture_save_capabilities', array( __CLASS__, 'handle_save_capabilities' ) );
		add_action( 'admin_post_estandar_suite_capture_save_caps_matrix', array( __CLASS__, 'handle_save_caps_matrix' ) );
		add_action( 'admin_post_estandar_suite_capture_save_chatbot_settings', array( __CLASS__, 'handle_save_chatbot_settings' ) );
		add_action( 'admin_post_estandar_suite_capture_save_popups_settings', array( __CLASS__, 'handle_save_popups_settings' ) );
		add_action( 'admin_post_estandar_suite_capture_save_popups_general', array( __CLASS__, 'handle_save_popups_general' ) );
		add_action( 'admin_post_estandar_suite_capture_save_leads_settings', array( __CLASS__, 'handle_save_leads_settings' ) );
		add_action( 'admin_post_estandar_suite_capture_save_leads_autoresponse', array( __CLASS__, 'handle_save_leads_autoresponse' ) );
		add_action( 'admin_post_estandar_suite_capture_save_gf_postventa_bridge', array( __CLASS__, 'handle_save_gf_postventa_bridge' ) );
		add_action( 'admin_post_estandar_suite_capture_forms_entries_import', array( __CLASS__, 'handle_forms_entries_import' ) );
		add_action( 'admin_post_estandar_suite_capture_forms_entries_rollback', array( __CLASS__, 'handle_forms_entries_rollback' ) );
		add_action( 'admin_post_estandar_suite_capture_forms_responses_import', array( __CLASS__, 'handle_forms_responses_import' ) );
		add_action( 'admin_post_estandar_suite_capture_forms_responses_rollback', array( __CLASS__, 'handle_forms_responses_rollback' ) );
		add_action( 'admin_post_estandar_suite_capture_migrate_form', array( __CLASS__, 'handle_migrate_form' ) );
		add_action( 'admin_post_estandar_suite_capture_migrate_rollback', array( __CLASS__, 'handle_migrate_rollback' ) );
		add_action( 'admin_post_estandar_suite_capture_trash_submission', array( __CLASS__, 'handle_trash_submission' ) );
		add_action( 'admin_post_estandar_suite_capture_restore_submission', array( __CLASS__, 'handle_restore_submission' ) );
		add_action( 'admin_post_estandar_suite_capture_delete_submission', array( __CLASS__, 'handle_delete_submission' ) );
		// F4 · JSON lifecycle (export/import) + CSV entradas + GF importer.
		add_action( 'admin_post_estandar_suite_capture_form_json_export', array( __CLASS__, 'handle_form_json_export' ) );
		add_action( 'admin_post_estandar_suite_capture_form_json_import', array( __CLASS__, 'handle_form_json_import' ) );
		add_action( 'admin_post_estandar_suite_capture_entries_csv', array( __CLASS__, 'handle_entries_csv' ) );
		add_action( 'admin_post_estandar_suite_capture_gf_import', array( __CLASS__, 'handle_gf_import' ) );
		// Fusión chatbot legacy ↔ capture v2 (ChatbotBridge / LeadsBridge / adopción).
		add_action( 'admin_post_estandar_suite_capture_chatbot_adoption_import', array( __CLASS__, 'handle_chatbot_adoption_import' ) );
		add_action( 'admin_post_estandar_suite_capture_chatbot_adoption_rollback', array( __CLASS__, 'handle_chatbot_adoption_rollback' ) );
		add_action( 'admin_post_estandar_suite_capture_save_whatsapp_settings', array( __CLASS__, 'handle_save_whatsapp_settings' ) );
		add_action( 'admin_post_estandar_suite_capture_save_floating_whatsapp_settings', array( __CLASS__, 'handle_save_floating_whatsapp_settings' ) );
		add_action( 'admin_post_estandar_suite_capture_whatsapp_test', array( __CLASS__, 'handle_whatsapp_test' ) );
		add_action( 'admin_post_estandar_suite_capture_whatsapp_send_test', array( __CLASS__, 'handle_whatsapp_send_test' ) );
	}

	/** admin_post: mover una entrada a la papelera (soft delete). */
	public static function handle_trash_submission(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_submission' );
		$id = (int) ( $_POST['submission_id'] ?? 0 );
		$ok = \Estandar\Capture\Forms::set_submission_status( $id, 'trash' );
		wp_safe_redirect( self::entries_url( $id, $ok ? 'trashed' : 'error' ) );
		exit;
	}

	/** admin_post: restaurar una entrada desde la papelera. */
	public static function handle_restore_submission(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_submission' );
		$id = (int) ( $_POST['submission_id'] ?? 0 );
		$ok = \Estandar\Capture\Forms::set_submission_status( $id, 'active' );
		wp_safe_redirect( self::entries_url( $id, $ok ? 'restored' : 'error' ) );
		exit;
	}

	/** admin_post: eliminar definitivamente una entrada. */
	public static function handle_delete_submission(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_submission' );
		$id = (int) ( $_POST['submission_id'] ?? 0 );
		$ok = \Estandar\Capture\Forms::delete_submission( $id );
		wp_safe_redirect( self::entries_url( $id, $ok ? 'deleted' : 'error' ) );
		exit;
	}

	/** URL de la subtab Entradas del editor con aviso. */
	private static function entries_url( int $submission_id, string $notice ): string
	{
		return add_query_arg(
			array( 'submission_notice' => rawurlencode( $notice ) ),
			admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=forms&ss=formularios&st=entradas' )
		);
	}

	/* ── F4 · JSON lifecycle (export/import) ─────────────────────────────── */

	/** Exporta el config_json de un form como archivo .json descargable. */
	public static function handle_form_json_export(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_form_json' );
		$id = (int) ( $_GET['form_id'] ?? 0 );
		$form = \Estandar\Capture\Forms::get( $id );
		if ( ! $form ) {
			wp_die( 'Formulario no encontrado.' );
		}
		$config = json_decode( (string) $form['config_json'], true );
		$config = is_array( $config ) ? $config : array();
		$envelope = array(
			'$schema'    => 'https://estandar.cl/schemas/capture/form-json/v1',
			'version'    => '1.0',
			'exported_at' => gmdate( 'c' ),
			'source'     => array( 'plugin' => 'estandar-capture', 'plugin_version' => (string) ESTANDAR_SUITE_CAPTURE_VERSION ),
			'form'       => array(
				'name'         => (string) $form['name'],
				'slug'         => (string) $form['slug'],
				'form_type'    => (string) $form['form_type'],
				'form_context' => (string) $form['form_context'],
				'config'       => $config,
			),
		);
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="form-' . (int) $id . '-' . gmdate( 'Ymd-His' ) . '.json"' );
		header( 'Cache-Control: no-store, no-cache' );
		echo wp_json_encode( $envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		exit;
	}

	/**
	 * Importa un JSON de form (envelope v1) creando uno nativo. Protecciones:
	 * JSON válido, name presente, form_type canónico, notify_emails saneado.
	 * Nunca pisa un form existente (siempre crea, slug único vía Forms::create).
	 */
	public static function handle_form_json_import(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_form_json' );
		$redirect = admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=forms&ss=formularios' );

		if ( empty( $_FILES['form_json'] ) || (int) $_FILES['form_json']['error'] !== UPLOAD_ERR_OK ) {
			wp_safe_redirect( add_query_arg( 'json_import', 'error_file', $redirect ) );
			exit;
		}
		$raw = (string) file_get_contents( (string) $_FILES['form_json']['tmp_name'] );
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			wp_safe_redirect( add_query_arg( 'json_import', 'error_json', $redirect ) );
			exit;
		}
		$form_data = $decoded['form'] ?? $decoded;
		if ( ! is_array( $form_data ) || empty( $form_data['name'] ) ) {
			wp_safe_redirect( add_query_arg( 'json_import', 'error_fields', $redirect ) );
			exit;
		}
		$type = sanitize_key( (string) ( $form_data['form_type'] ?? 'lead' ) );
		if ( ! isset( \Estandar\Capture\Forms::types()[ $type ] ) ) {
			$type = 'lead';
		}
		$config = is_array( $form_data['config'] ?? null ) ? $form_data['config'] : $form_data;
		// Seguridad: notificaciones nunca se heredan de un JSON ajeno.
		if ( isset( $config['notify_emails'] ) ) {
			unset( $config['notify_emails'] );
		}
		unset( $config['name'], $config['slug'], $config['form_type'], $config['form_context'], $config['_migrated_legacy'] );

		$new_id = \Estandar\Capture\Forms::create( array(
			'name'         => sanitize_text_field( (string) $form_data['name'] ),
			'form_type'    => $type,
			'form_context' => sanitize_key( (string) ( $form_data['form_context'] ?? 'generic' ) ),
			'status'       => 'active',
			'config_json'  => wp_json_encode( $config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
		) );
		wp_safe_redirect( add_query_arg( 'json_import', $new_id > 0 ? 'ok_' . $new_id : 'error_create', $redirect ) );
		exit;
	}

	/* ── F4 · CSV de entradas ───────────────────────────────────────────── */

	/** Export CSV de entradas (submissions) de un form nativo. */
	public static function handle_entries_csv(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_entries_csv' );
		$id = (int) ( $_GET['form_id'] ?? 0 );
		$form = \Estandar\Capture\Forms::get( $id );
		if ( ! $form ) {
			wp_die( 'Formulario no encontrado.' );
		}

		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT name, email_normalized, phone_normalized, message, fields_json, created_at
			 FROM ' . $wpdb->prefix . \Estandar\Capture\Ingestion::TABLE . '
			 WHERE source = %s AND source_form_id = %s ORDER BY id DESC',
			'form', (string) $id
		), ARRAY_A );

		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="entries-form-' . $id . '-' . gmdate( 'Ymd-His' ) . '.csv"' );
		header( 'Cache-Control: no-store, no-cache' );
		header( 'Pragma: no-cache' );

		$out = fopen( 'php://output', 'wb' );
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, array( 'name', 'email', 'phone', 'message', 'fields_json', 'created_at' ) );
		$csv_safe = static function ( $val ): string {
			$s = (string) $val;
			return ( $s !== '' && in_array( $s[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) ? "'" . $s : $s;
		};
		foreach ( (array) $rows as $r ) {
			fputcsv( $out, array(
				$csv_safe( (string) ( $r['name'] ?? '' ) ),
				$csv_safe( (string) ( $r['email_normalized'] ?? '' ) ),
				$csv_safe( (string) ( $r['phone_normalized'] ?? '' ) ),
				$csv_safe( (string) ( $r['message'] ?? '' ) ),
				$csv_safe( (string) ( $r['fields_json'] ?? '' ) ),
				(string) ( $r['created_at'] ?? '' ),
			) );
		}
		exit;
	}

	/* ── F4 · GF importer ───────────────────────────────────────────────── */

	/** Importa un export JSON de Gravity Forms → form nativo v2. */
	public static function handle_gf_import(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_gf_import' );
		$redirect = admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=forms&ss=formularios' );

		if ( empty( $_FILES['gf_json'] ) || (int) $_FILES['gf_json']['error'] !== UPLOAD_ERR_OK ) {
			wp_safe_redirect( add_query_arg( 'gf_import', 'error_file', $redirect ) );
			exit;
		}
		$raw = (string) file_get_contents( (string) $_FILES['gf_json']['tmp_name'] );
		$importer = new \Estandar\Capture\GfImporter( $raw );
		$report = $importer->analyze();
		if ( empty( $report['can_import'] ) ) {
			wp_safe_redirect( add_query_arg( 'gf_import', 'error_compat', $redirect ) );
			exit;
		}
		$result = $importer->import( array( 'form_type' => 'lead', 'form_context' => 'generic' ) );
		wp_safe_redirect( add_query_arg( 'gf_import', ! empty( $result['form_id'] ) ? 'ok_' . (int) $result['form_id'] : 'error_create', $redirect ) );
		exit;
	}

	/** Generic merge-save for a capture settings group (chatbot/popups/leads). */
	private static function merge_group_save( string $option, array $groups, string $redirect_sub ): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_save_' . $redirect_sub . '_settings' );
		$group = sanitize_key( (string) ( $_POST['group'] ?? '' ) );
		if ( ! isset( $groups[ $group ] ) ) {
			wp_die( 'Grupo desconocido.' );
		}
		$raw = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? $_POST['settings'] : array();
		// FEATURE_GROUP_NESTED_V1: guarda anidado por grupo (igual que leen sus
		// consumidores: PixelManager→integraciones, Mail→email, …) y migra en el
		// guardado cualquier clave plana legacy de la raíz.
		\Estandar\Core\Admin::save_feature_group( $option, $groups[ $group ], $group, $raw );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=' . $redirect_sub . '&ss=' . $group . '&' . $redirect_sub . '_saved=1' ) );
		exit;
	}

	/** Popups settings grouped by sub-tab. */
	public static function popups_settings_groups(): array
	{
		return array(
			'configuracion' => array(
				// SUBMITTED_TTL_V1: duración global de la restricción "ya envió".
				// Reemplaza el año fijo hardcodeado (FormRenderer). 0 = sin restricción.
				// POPUP_DESIGN_LOCAL_V1 (2026-09-21): la APARIENCIA es SIEMPRE por
				// popup (cada uno define tamaño/animación/overlay). No hay ajustes
				// globales de visualización: se retiraron default_size/animation/
				// overlay/color/opacity/z_index/track_* porque no se leían.
				'cookie_days'       => array( 'type' => 'number', 'label' => 'Duración de la restricción "ya envió" (días)', 'desc' => 'Cuántos días el navegador recuerda que la persona ya envió un formulario (y por tanto no se le muestran los popups). 0 = sin restricción. Default: 365.', 'default' => 365, 'min' => 0, 'max' => 3650 ),
			),
		);
	}

	/** admin_post handler: save popups settings group (merge). */
	public static function handle_save_popups_settings(): void
	{
		self::merge_group_save( 'estandar_suite_capture_popups_settings', self::popups_settings_groups(), 'popups' );
	}

	/** POPUPS_GENERAL_V2: filas de control por popup v2 (atajo del settings general). */
	public static function popups_control_rows(): array
	{
		$out = array();
		foreach ( Popups::list() as $p ) {
			$cfg = json_decode( (string) ( $p['config_json'] ?? '' ), true );
			$cfg = is_array( $cfg ) ? $cfg : array();
			$out[] = array(
				'id'     => (int) $p['id'],
				'title'  => (string) $p['name'],
				'active' => ( (string) $p['status'] === 'active' ),
				'hide'   => ! empty( $cfg['hide_if_submitted'] ),
			);
		}
		return $out;
	}

	/** POPUPS_GENERAL_V2: guarda los switches por popup v2 (merge del config). */
	public static function handle_save_popups_general(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_save_popups_general' );
		$rows = isset( $_POST['popups'] ) && is_array( $_POST['popups'] ) ? $_POST['popups'] : array();
		foreach ( $rows as $id => $flags ) {
			$id  = (int) $id;
			$cur = $id > 0 ? Popups::get( $id ) : null;
			if ( ! $cur ) {
				continue;
			}
			$flags = is_array( $flags ) ? $flags : array();
			$cfg = json_decode( (string) ( $cur['config_json'] ?? '' ), true );
			$cfg = is_array( $cfg ) ? $cfg : array();
			$cfg['hide_if_submitted'] = ! empty( $flags['hide'] );
			Popups::update( $id, array(
				'name'        => (string) $cur['name'],
				'slug'        => (string) $cur['slug'],
				'status'      => ! empty( $flags['active'] ) ? 'active' : 'draft',
				'config_json' => wp_json_encode( $cfg ),
			) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=popups&ss=configuracion&popups_general_saved=1' ) );
		exit;
	}

	/** Leads settings grouped by sub-tab. */
	public static function leads_settings_groups(): array
	{
		return array(
			'email' => array(
				'sender_name'    => array( 'type' => 'text', 'label' => 'Remitente (nombre)', 'default' => '' ),
				'sender_email'   => array( 'type' => 'text', 'label' => 'Remitente (email)', 'default' => '' ),
				'reply_to_email' => array( 'type' => 'text', 'label' => 'Responder a', 'default' => '' ),
				'cc_email'       => array( 'type' => 'text', 'label' => 'CC', 'default' => '' ),
				'bcc_email'      => array( 'type' => 'text', 'label' => 'CCO', 'default' => '' ),
				'subject'        => array( 'type' => 'text', 'label' => 'Asunto', 'default' => 'Nuevo Lead Recibido' ),
				'preheader'      => array( 'type' => 'text', 'label' => 'Preheader', 'default' => '' ),
				'intro_text'     => array( 'type' => 'text', 'label' => 'Texto intro', 'default' => '' ),
				'brand_color'    => array( 'type' => 'text', 'label' => 'Color de marca', 'default' => '#000000' ),
				'logo_url'       => array( 'type' => 'text', 'label' => 'Logo (URL)', 'default' => '', 'desc' => 'Vacío = logo personalizado del tema' ),
				'header_text'    => array( 'type' => 'text', 'label' => 'Nombre en cabecera', 'default' => '', 'desc' => 'Vacío = nombre del sitio' ),
				'footer_text'    => array( 'type' => 'textarea', 'label' => 'Footer (HTML)', 'default' => '', 'rows' => 3, 'desc' => 'Vacío = footer Estandar' ),
			),
			'autorespuesta' => array(
				'enabled'        => array( 'type' => 'bool', 'label' => 'Autorespuesta activa', 'default' => 0 ),
				'subject'        => array( 'type' => 'text', 'label' => 'Asunto', 'default' => 'Gracias por tu interés' ),
				'body'           => array( 'type' => 'text', 'label' => 'Cuerpo', 'default' => '' ),
				'throttle_hours' => array( 'type' => 'text', 'label' => 'Espera entre envíos (horas)', 'default' => '24' ),
			),
			'integraciones' => array(
				'meta_pixel_id'      => array( 'type' => 'text', 'label' => 'Meta Pixel ID', 'default' => '', 'desc' => 'Se inyecta el fbq en el <head> (PixelManager). Vacío = no se carga.' ),
				'meta_enabled'       => array( 'type' => 'bool', 'label' => 'Meta CAPI activo', 'default' => 0 ),
				'meta_server_events' => array( 'type' => 'bool', 'label' => 'Server events', 'default' => 1 ),
				'google_enabled'     => array( 'type' => 'bool', 'label' => 'Google Ads activo', 'default' => 0 ),
				'google_ga4_id'      => array( 'type' => 'text', 'label' => 'Google Analytics 4 (Measurement ID)', 'default' => '', 'desc' => 'Ej. G-XXXXXXXXXX. Se inyecta gtag.js en el <head>. Vacío = no se carga.' ),
				'ga4_lead_event'     => array( 'type' => 'text', 'label' => 'Evento GA4 de conversión (key event)', 'default' => 'generate_lead', 'desc' => 'Evento GA4 que se dispara al confirmarse un envío (debe existir como key event en la propiedad). Default: generate_lead.' ),
				'google_customer_id' => array( 'type' => 'text', 'label' => 'Customer ID Google', 'default' => '' ),
				'google_conversion_action_id' => array( 'type' => 'text', 'label' => 'Conversion action ID', 'default' => '', 'desc' => 'Ej. 1234567890 (sin el prefijo AW-). Con Google Ads activo, emite gtag config AW-….' ),
			),
			'calidad' => array(
				'fit_enabled'         => array( 'type' => 'bool', 'label' => 'Indicador de fit activo', 'default' => 0 ),
				'assessment_enabled'  => array( 'type' => 'bool', 'label' => 'Assessment IA activo', 'default' => 0 ),
				'compliance_enabled'  => array( 'type' => 'bool', 'label' => 'Motor de compliance', 'default' => 0 ),
				'feedback_due_hours'  => array( 'type' => 'text', 'label' => 'Feedback vence (horas)', 'default' => '48' ),
				'recovery_enabled'    => array( 'type' => 'bool', 'label' => 'Recuperación de leads', 'default' => 1 ),
				'recovery_delay_hours'=> array( 'type' => 'text', 'label' => 'Espera de recuperación (horas)', 'default' => '24' ),
			),
		);
	}

	/** Leads settings flattened (CLI). */
	public static function leads_settings_fields(): array
	{
		$out = array();
		foreach ( self::leads_settings_groups() as $g => $fields ) { $out = array_merge( $out, $fields ); }
		return $out;
	}

	/** admin_post handler: save leads settings group (merge). */
	public static function handle_save_leads_settings(): void
	{
		self::merge_group_save( 'estandar_suite_capture_leads_settings', self::leads_settings_groups(), 'leads' );
	}

	/**
	 * LEAD_RESPONSE_META_V1 — proyectos candidatos para la autorespuesta de
	 * LEADS (con PDF por proyecto/campaña): proyectos ya configurados, slugs del
	 * mapa de formularios de Meta y del registro de etiquetas de campaña.
	 *
	 * @return array<string,string> slug => label
	 */
	public static function leads_autoresponse_projects(): array
	{
		$out = array();
		foreach ( array_keys( (array) Forms::leads_autoresponse_map() ) as $slug ) {
			$slug = function_exists( 'sanitize_key' ) ? sanitize_key( (string) $slug ) : (string) $slug;
			if ( $slug !== '' ) { $out[ $slug ] = $out[ $slug ] ?? $slug; }
		}
		$forms_map = get_option( 'estandar_suite_capture_meta_forms', array() );
		if ( is_array( $forms_map ) ) {
			foreach ( $forms_map as $slug ) {
				$slug = function_exists( 'sanitize_key' ) ? sanitize_key( (string) $slug ) : (string) $slug;
				if ( $slug !== '' ) { $out[ $slug ] = $out[ $slug ] ?? $slug; }
			}
		}
		$labels = get_option( 'estandar_campaign_labels_v1', array() );
		if ( is_array( $labels ) ) {
			foreach ( array( 'campaigns', 'adsets', 'ads' ) as $bucket ) {
				foreach ( (array) ( $labels[ $bucket ] ?? array() ) as $row ) {
					$slug = function_exists( 'sanitize_key' ) ? sanitize_key( (string) ( $row['project'] ?? '' ) ) : (string) ( $row['project'] ?? '' );
					if ( $slug !== '' ) { $out[ $slug ] = $out[ $slug ] ?? (string) ( $row['label'] ?? $slug ); }
				}
			}
		}
		if ( class_exists( 'Estandar\\Core\\Leads\\ProjectRegistry' ) && method_exists( 'Estandar\\Core\\Leads\\ProjectRegistry', 'manager_list' ) ) {
			foreach ( (array) \Estandar\Core\Leads\ProjectRegistry::manager_list() as $row ) {
				$slug = function_exists( 'sanitize_key' ) ? sanitize_key( (string) ( $row['project_id'] ?? '' ) ) : (string) ( $row['project_id'] ?? '' );
				if ( $slug !== '' ) { $out[ $slug ] = $out[ $slug ] ?? (string) ( $row['label'] ?? $slug ); }
			}
		}
		ksort( $out );
		return $out;
	}

	/** admin_post handler: save per-project LEADS autoresponse map. */
	public static function handle_save_leads_autoresponse(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_save_leads_autoresponse' );

		$map = array();
		$raw = isset( $_POST['lar'] ) && is_array( $_POST['lar'] ) ? wp_unslash( $_POST['lar'] ) : array();
		foreach ( $raw as $slug => $row ) {
			$slug = function_exists( 'sanitize_key' ) ? sanitize_key( (string) $slug ) : (string) $slug;
			if ( $slug === '' || ! is_array( $row ) ) {
				continue;
			}
			$subject = sanitize_text_field( (string) ( $row['subject'] ?? '' ) );
			$body    = wp_kses_post( (string) ( $row['body'] ?? '' ) );
			$pdf     = absint( $row['pdf_attachment_id'] ?? 0 );
			$enabled = ! empty( $row['enabled'] );
			// Se conserva la fila aunque esté apagada, para no perder su texto/PDF.
			$map[ $slug ] = array(
				'enabled'           => $enabled ? 1 : 0,
				'subject'           => $subject,
				'body'              => $body,
				'pdf_attachment_id' => $pdf,
				'landing_url'       => esc_url_raw( (string) ( $row['landing_url'] ?? '' ) ),
			);
		}
		update_option( Forms::OPTION_LEADS_AR, $map, false );
		update_option( Forms::LEADS_AR_ADOPT_FLAG, '1', false );
		// MAIL_TRACKING_V2: interruptor global de aperturas/clics.
		update_option( Forms::OPTION_LEADS_AR_TRACK, empty( $_POST['lar_track'] ) ? '0' : '1', false );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=leads&ss=autorespuesta&lar_saved=1' ) );
		exit;
	}

	const LEADS_ADOPT_FLAG = 'estandar_suite_capture_leads_credentials_adopted';
	/** MAIL_IDENTITY_CANONICAL_V1: flag de adopción única del branding legacy. */
	const LEADS_EMAIL_ADOPT_FLAG = 'estandar_suite_capture_leads_email_adopted';

	/**
	 * Absorb legacy ad credentials (estandar_leads_meta y google) into the
	 * v2 leads settings WITHOUT overwriting v2 values and NEVER touching the
	 * legacy options. Idempotent; run once (flag) or via CLI.
	 */
	public static function adopt_leads_credentials( bool $force = false ): array
	{
		$map = array(
			'meta_pixel_id'             => 'estandar_leads_meta_pixel_id',
			'meta_enabled'              => 'estandar_leads_meta_enabled',
			'google_customer_id'        => 'estandar_leads_google_customer_id',
			'google_conversion_action_id' => 'estandar_leads_google_conversion_action_id',
		);
		$cur = get_option( 'estandar_suite_capture_leads_settings', array() );
		$cur = is_array( $cur ) ? $cur : array();
		$stats = array( 'adopted' => 0, 'kept' => 0, 'legacy_read' => 0 );
		foreach ( $map as $v2key => $legacy_opt ) {
			$legacy = get_option( $legacy_opt, '' );
			if ( is_string( $legacy ) && $legacy !== '' ) {
				$stats['legacy_read']++;
				if ( empty( $cur[ $v2key ] ) ) {
					$cur[ $v2key ] = $legacy;
					$stats['adopted']++;
				} else {
					$stats['kept']++;
				}
			}
		}
		update_option( 'estandar_suite_capture_leads_settings', $cur, false );
		update_option( self::LEADS_ADOPT_FLAG, '1', false );
		return $stats;
	}

	/**
	 * MAIL_IDENTITY_CANONICAL_V1: absorbe UNA vez la identidad de correo legacy
	 * (`estandar_leads_email_settings`, sin editor en la Suite) dentro de la
	 * opción moderna `estandar_suite_capture_leads_settings` (Capture → Panel de
	 * Leads → Email), sin pisar lo ya configurado y SIN tocar la opción legacy.
	 * Necesario porque se retiró la precedencia legacy del correo: sin esto, un
	 * sitio con branding solo-legacy perdería su color/logo.
	 */
	public static function adopt_leads_email_identity( bool $force = false ): array
	{
		$map = array(
			'sender_name'    => 'sender_name',
			'sender_email'   => 'sender_email',
			'reply_to_email' => 'reply_to_email',
			'cc_email'       => 'notification_cc_email',
			'bcc_email'      => 'notification_bcc_email',
			'subject'        => 'subject',
			'preheader'      => 'preheader',
			'intro_text'     => 'intro_text',
			'brand_color'    => 'brand_color',
			'logo_url'       => 'logo_url',
			'header_text'    => 'header_text',
			'footer_text'    => 'footer_text',
		);
		$legacy = get_option( 'estandar_leads_email_settings', array() );
		$legacy = is_array( $legacy ) ? $legacy : array();
		$cur    = get_option( 'estandar_suite_capture_leads_settings', array() );
		$cur    = is_array( $cur ) ? $cur : array();
		$stats  = array( 'adopted' => 0, 'kept' => 0, 'legacy_read' => 0 );
		foreach ( $map as $v2key => $legacy_key ) {
			$val = $legacy[ $legacy_key ] ?? '';
			if ( ! is_string( $val ) || $val === '' ) { continue; }
			$stats['legacy_read']++;
			if ( empty( $cur[ $v2key ] ) ) {
				$cur[ $v2key ] = $val;
				$stats['adopted']++;
			} else {
				$stats['kept']++;
			}
		}
		update_option( 'estandar_suite_capture_leads_settings', $cur, false );
		update_option( self::LEADS_EMAIL_ADOPT_FLAG, '1', false );
		return $stats;
	}

	/** Chatbot settings grouped by sub-tab (config categories). */
	public static function chatbot_settings_groups(): array
	{
		return array(
			'conversacion' => array(
				'activar'         => array( 'type' => 'bool', 'label' => 'Activar chatbot', 'desc' => 'Habilita el chatbot en el sitio.', 'default' => 1 ),
				// CHATBOT_CLEANUP_V1: se retiró "solo_loggeados" (sin uso real).
				'paginas_visibles'=> array( 'type' => 'pages', 'label' => 'Dónde aparece', 'desc' => 'Sin selección = todo el sitio. Para quitar una página haz Cmd/Ctrl + clic. El shortcode [estandar_chatbot] siempre carga sin importar esta lista.', 'default' => array() ),
				'nombre'          => array( 'type' => 'text', 'label' => 'Nombre del asistente', 'desc' => 'Nombre que muestra el chatbot.', 'default' => 'Asistente' ),
				'mostrar_nombre'  => array( 'type' => 'bool', 'label' => 'Mostrar nombre', 'desc' => 'Muestra el nombre en la conversación.', 'default' => 1 ),
				'idioma'          => array( 'type' => 'select', 'label' => 'Idioma', 'options' => array( 'es' => 'Español', 'en' => 'Inglés', 'pt' => 'Portugués' ), 'default' => 'es' ),
				'perfil'          => array( 'type' => 'select', 'label' => 'Perfil / flujo', 'options' => array( 'default' => 'Default', 'lead' => 'Captura de leads', 'survey' => 'Encuesta' ), 'default' => 'default' ),
				'show_advanced_tabs' => array( 'type' => 'bool', 'label' => 'Mostrar pestañas avanzadas', 'desc' => 'Muestra las pestañas técnicas/avanzadas (Sugerencias, Integraciones, Fusión con la Suite, Sistema y Chat del agente público). Desactivado por defecto para simplificar la pantalla.', 'default' => 0 ),
			),
			'captacion' => array(
				'lead_capture_enabled'     => array( 'type' => 'bool', 'label' => 'Captura de leads', 'desc' => 'Pide datos de contacto al visitante.', 'default' => 1 ),
				'auto_open_enabled'        => array( 'type' => 'bool', 'label' => 'Apertura automática', 'desc' => 'Abre la burbuja por sí sola.', 'default' => 0 ),
				'auto_open_type'           => array( 'type' => 'select', 'label' => 'Tipo de apertura', 'options' => array( 'time' => 'Por tiempo', 'scroll' => 'Por scroll' ), 'default' => 'time' ),
				'auto_open_value'          => array( 'type' => 'text', 'label' => 'Valor de apertura', 'desc' => 'Segundos o % de scroll.', 'default' => '6' ),
				'human_escalation_enabled' => array( 'type' => 'bool', 'label' => 'Escalada a humano', 'desc' => 'Permite derivar a una persona.', 'default' => 1 ),
				'human_escalation_mode'    => array( 'type' => 'select', 'label' => 'Modo de escalada', 'options' => array( 'lead_form' => 'Formulario', 'whatsapp' => 'WhatsApp' ), 'default' => 'lead_form' ),
				'human_whatsapp_number'    => array( 'type' => 'text', 'label' => 'WhatsApp de escalada', 'desc' => 'Número para derivar (si modo WhatsApp).', 'default' => '' ),
				'post_lead_message'        => array( 'type' => 'text', 'label' => 'Mensaje post-lead', 'desc' => 'Mensaje tras capturar el lead.', 'default' => 'Perfecto, ya tengo tus datos.' ),
				'business_industry'        => array( 'type' => 'text', 'label' => 'Industria del negocio', 'default' => 'servicios' ),
				'business_goal'            => array( 'type' => 'text', 'label' => 'Objetivo del negocio', 'default' => 'captar contactos' ),
				'business_primary_cta'     => array( 'type' => 'text', 'label' => 'CTA principal', 'default' => 'Quiero avanzar' ),
				'business_tone'            => array( 'type' => 'text', 'label' => 'Tono de voz', 'default' => 'claro y profesional' ),
				'business_sensitive_topics'=> array( 'type' => 'text', 'label' => 'Temas sensibles', 'desc' => 'Temas que derivar a humano.', 'default' => 'precio, plazos' ),
				// CHAT_CAPTURE_FIELDS_V1 / CHAT_SALES_MODE_V1 / CHAT_AGENT_CONTACT_V1 (2026-09-27).
				'capture_fields'           => array( 'type' => 'text', 'label' => 'Datos a captar', 'desc' => 'Lista separada por comas: name,email,phone,rut. Default: name,email,phone.', 'default' => 'name,email,phone' ),
				'sales_mode'               => array( 'type' => 'select', 'label' => 'Modalidad', 'options' => array( '' => 'No aplica', 'ambos' => 'Venta y arriendo', 'venta' => 'Solo venta', 'arriendo' => 'Solo arriendo' ), 'default' => '' ),
				'agent_email'              => array( 'type' => 'text', 'label' => 'Email del equipo (derivación)', 'desc' => 'Contacto humano real que el chatbot puede ofrecer.', 'default' => '' ),
			),
			'ia' => array(
				'openai_model'       => array( 'type' => 'select', 'label' => 'Modelo de IA', 'desc' => 'Modelo usado para responder. Depende del proveedor activo (DeepSeek u OpenAI).', 'options' => array(
					'deepseek-chat'    => 'deepseek-chat (DeepSeek, recomendado)',
					'deepseek-reasoner'=> 'deepseek-reasoner (DeepSeek, razonamiento)',
					'gpt-4o'           => 'gpt-4o (OpenAI)',
					'gpt-4o-mini'      => 'gpt-4o-mini (OpenAI, rápido)',
				), 'default' => 'deepseek-chat' ),
				'openai_max_tokens'  => array( 'type' => 'number', 'label' => 'Máx. tokens', 'desc' => 'Límite de la respuesta (100–8000). Recomendado: 500.', 'min' => 100, 'max' => 8000, 'default' => 500 ),
				'openai_temperature' => array( 'type' => 'number', 'label' => 'Creatividad (temperatura)', 'desc' => '0 = predecible · 1 = variada · 2 = muy creativa. Recomendado: 0.4.', 'min' => 0, 'max' => 2, 'step' => '0.1', 'default' => 0.4 ),
				'system_prompt'      => array( 'type' => 'textarea', 'label' => 'Instrucciones del asistente', 'desc' => 'Reglas de comportamiento. Si lo dejas vacío se usa el prompt por defecto (incluye guardrails anti-inyección). Se añade al final el perfil del negocio y el contexto.', 'rows' => 6, 'default' => '' ),
				'context_txt'        => array( 'type' => 'textarea', 'label' => 'Contexto (base de conocimiento)', 'desc' => 'Información del negocio que el chatbot usa como referencia oficial en cada conversación.', 'rows' => 8, 'default' => '' ),
				'chat_daily_budget'  => array( 'type' => 'number', 'label' => 'Presupuesto diario (llamadas)', 'desc' => 'Tope de respuestas del chatbot por día para controlar el costo de la API. 0 = ilimitado. Recomendado: 300.', 'min' => 0, 'max' => 100000, 'default' => 300 ),
				'ajax_guard_enabled' => array( 'type' => 'bool', 'label' => 'Proteger el widget (rate-limit + presupuesto)', 'desc' => 'Aplica al chat del sitio el límite de 20 mensajes/min por IP y el presupuesto diario. Activado por defecto; desactívalo solo si necesitas otro control.', 'default' => 1 ),
				// CHAT_RAG_V1 (2026-09-27): grounding en el contenido real del sitio.
				'rag_enabled'        => array( 'type' => 'bool', 'label' => 'Usar el contenido del sitio (respuestas fundadas)', 'desc' => 'El chatbot consulta el índice de conocimiento del sitio (Brain) para responder con información real y evitar inventar datos. Recomendado: activado.', 'default' => 1 ),
				'rag_chunks'         => array( 'type' => 'number', 'label' => 'Fragmentos de contexto', 'desc' => 'Cuántos fragmentos del sitio usar como fuente (1–6). Recomendado: 3.', 'min' => 1, 'max' => 6, 'default' => 3 ),
				// CHAT_STREAM_V1 (2026-09-27): respuesta progresiva, con fallback.
				'stream_enabled'     => array( 'type' => 'bool', 'label' => 'Respuesta progresiva (streaming)', 'desc' => 'El chatbot muestra la respuesta mientras se genera. Si el navegador o el servidor no lo soportan, vuelve solo al modo normal. Recomendado: activado.', 'default' => 1 ),
			),
			'apariencia' => array(
				'theme_accent'         => array( 'type' => 'color', 'label' => 'Color de acento', 'default' => '#111827' ),
				'visual_style'         => array( 'type' => 'select', 'label' => 'Estilo visual', 'options' => array( 'default' => 'Default', 'corporativo_minimalista' => 'Corporativo minimalista' ), 'default' => 'default' ),
				'bubble_shape'         => array( 'type' => 'select', 'label' => 'Forma de burbuja', 'options' => array( 'rounded' => 'Redondeada', 'circle' => 'Círculo', 'square' => 'Cuadrada' ), 'default' => 'rounded' ),
				'theme_position'       => array( 'type' => 'select', 'label' => 'Posición', 'options' => array( 'right' => 'Derecha', 'left' => 'Izquierda' ), 'default' => 'right' ),
				'bubble_size'          => array( 'type' => 'number', 'label' => 'Tamaño de burbuja (px)', 'desc' => 'Recomendado: 52–64.', 'min' => 40, 'max' => 88, 'default' => 58 ),
				'bubble_image_url'     => array( 'type' => 'text', 'label' => 'Imagen de burbuja (URL)', 'desc' => 'Imagen o GIF del botón flotante. Si lo dejas vacío se usa el GIF animado por defecto del plugin.', 'default' => '' ),
				'inline_sidebar_enabled' => array( 'type' => 'bool', 'label' => 'Barra lateral inline', 'desc' => 'Muestra el chatbot como panel lateral (solo shortcode inline).', 'default' => 0 ),
				// CHATBOT_BRANDING_V1 (2026-09-21): los colores reales del widget
				// (antes solo en la opción legacy `chatbot_branding_config`, sin UI).
				// Editables aquí; migración inicial copia el valor legacy (no cambia el look).
				'branding_bot_bubble'      => array( 'type' => 'color', 'label' => 'Color burbuja del bot', 'default' => '#f3f4f6' ),
				'branding_user_bubble'     => array( 'type' => 'color', 'label' => 'Color burbuja del visitante', 'default' => '#e8f0fe' ),
				'branding_button_color'    => array( 'type' => 'color', 'label' => 'Color de botones / CTA', 'default' => '#2563eb' ),
				'branding_bg_color'        => array( 'type' => 'color', 'label' => 'Fondo del chat', 'default' => '#ffffff' ),
				'branding_text_color'      => array( 'type' => 'color', 'label' => 'Color de texto', 'default' => '#111827' ),
				'branding_avatar'          => array( 'type' => 'text', 'label' => 'Avatar del bot (URL)', 'default' => '' ),
				'branding_shadow_enabled'  => array( 'type' => 'bool', 'label' => 'Sombra del chat', 'default' => 1 ),
				'branding_shadow_intensity'=> array( 'type' => 'number', 'label' => 'Intensidad de sombra', 'desc' => '0–24.', 'min' => 0, 'max' => 24, 'default' => 6 ),
			),
			'integraciones' => array(
				'webhook_enabled'        => array( 'type' => 'bool', 'label' => 'Webhook', 'desc' => 'Envía eventos a un webhook.', 'default' => 0 ),
				'webhook_url'            => array( 'type' => 'text', 'label' => 'URL del webhook', 'default' => '' ),
				'webhook_provider'       => array( 'type' => 'select', 'label' => 'Proveedor webhook', 'options' => array( 'n8n' => 'n8n', 'generic' => 'Genérico' ), 'default' => 'n8n' ),
				'webhook_secret'         => array( 'type' => 'text', 'label' => 'Secreto del webhook', 'default' => '' ),
				'crm_enabled'            => array( 'type' => 'bool', 'label' => 'CRM', 'desc' => 'Envía leads a un CRM.', 'default' => 0 ),
				'crm_url'                => array( 'type' => 'text', 'label' => 'URL del CRM', 'default' => '' ),
				'crm_secret'             => array( 'type' => 'text', 'label' => 'Secreto del CRM', 'default' => '' ),
				'notify_email_enabled'   => array( 'type' => 'bool', 'label' => 'Notificar por email', 'desc' => 'Avisa al completar un lead.', 'default' => 1 ),
				'notify_email_to'        => array( 'type' => 'text', 'label' => 'Email de notificación', 'default' => '' ),
				'notify_email_subject'   => array( 'type' => 'text', 'label' => 'Asunto', 'default' => 'Nuevo lead desde chatbot' ),
				'whatsapp_enabled'       => array( 'type' => 'bool', 'label' => 'WhatsApp Cloud API', 'desc' => 'Conecta con WhatsApp Cloud API.', 'default' => 0 ),
				'whatsapp_phone_number_id' => array( 'type' => 'text', 'label' => 'Phone Number ID', 'default' => '' ),
				'whatsapp_access_token'  => array( 'type' => 'text', 'label' => 'Access token', 'default' => '' ),
				'whatsapp_verify_token'  => array( 'type' => 'text', 'label' => 'Verify token', 'default' => '' ),
			),
			'sistema' => array(
				// CHATBOT_CLEANUP_V1 (2026-09-21): se retiraron licencia_plan/
				// licencia_key (no existe sistema de licencias; eran huérfanos).
				'logs_retention_days' => array( 'type' => 'text', 'label' => 'Retención de logs (días)', 'desc' => '0 = indefinido. Si es > 0, los logs de chat más antiguos se eliminan (cron diario). Recomendado: 90.', 'default' => '90' ),
				'uninstall_wipe'      => array( 'type' => 'bool', 'label' => 'Borrar datos al desinstalar', 'desc' => 'Si se activa, al desinstalar el plugin se eliminan las tablas de chat. Default: no borrar.', 'default' => 0 ),
			),
			// CHATBOT_MSGS_V1 (2026-09-21): mensajes configurables por instalación
			// (copy de captación y escalado). Se leen con preferencia v2 → legacy →
			// default actual, así que exponerlos NO cambia el comportamiento.
			'mensajes' => array(
				'lead_capture_intro'     => array( 'type' => 'textarea', 'label' => 'Mensaje de bienvenida (captación)', 'desc' => 'Se muestra al iniciar la captación (flujo sin IA).', 'default' => 'Con gusto te ayudo.' ),
				'lead_capture_ask_email' => array( 'type' => 'textarea', 'label' => 'Pedir correo', 'desc' => 'Pregunta cuando falta el correo.', 'default' => '¿Me dejas tu correo para que una persona del equipo te contacte y te dé una respuesta a medida?' ),
				'lead_capture_ask_phone' => array( 'type' => 'textarea', 'label' => 'Pedir teléfono', 'desc' => 'Pregunta cuando falta el teléfono.', 'default' => '¡Gracias! ¿Y un número de WhatsApp o teléfono donde te podamos ubicar?' ),
				'lead_capture_ask_name'  => array( 'type' => 'textarea', 'label' => 'Pedir nombre', 'desc' => 'Pregunta cuando falta el nombre.', 'default' => 'Por último, ¿cómo te llamas?' ),
				'lead_capture_ask_rut'   => array( 'type' => 'textarea', 'label' => 'Pedir RUT', 'desc' => 'Pregunta cuando falta el RUT (si "Datos a captar" incluye rut).', 'default' => '¿Me dejas también tu RUT? Lo usamos para tu ficha.' ),
				'lead_capture_done'      => array( 'type' => 'textarea', 'label' => 'Confirmación de registro', 'desc' => 'Puedes usar {name}, {email} y {phone}.', 'default' => '¡Gracias, {name}! Ya quedó registrado tu contacto ({email} · {phone}). Una persona del equipo te escribirá muy pronto.' ),
				'human_escalation_text'  => array( 'type' => 'textarea', 'label' => 'Ofrecer una persona', 'desc' => 'Texto para ofrecer derivar a un humano.', 'default' => 'Si prefieres, te puedo derivar ahora mismo con una persona del equipo.' ),
				'out_of_scope_reply'     => array( 'type' => 'textarea', 'label' => 'Reconducir temas fuera de alcance', 'desc' => 'Texto sugerido cuando el visitante pregunta por algo ajeno al negocio (empleo, postventa, bromas…).', 'default' => 'Con gusto, aunque eso se escapa de lo que puedo ayudarte desde aquí. Lo que sí puedo hacer es darte información del producto y conectarte con una persona del equipo.' ),
				'human_escalation_whatsapp_message' => array( 'type' => 'text', 'label' => 'Mensaje de WhatsApp al derivar', 'desc' => 'Texto precargado al abrir WhatsApp.', 'default' => 'Hola, quiero hablar con una persona del equipo. Dejé mis datos en la web.' ),
			),
			'fusion' => array(
				'leads_bridge_enabled' => array( 'type' => 'bool', 'label' => 'Puente de leads hacia v1 (estandar-leads)', 'desc' => 'Cuando estandar-leads esté activo, cada captura nativa v2 (form/survey/popup/rest) se re-emite al contrato legacy `estandar_lead_captured` para que el CRM y la notificación al operador sigan funcionando. Opt-in; requiere que estandar-leads esté activo.', 'default' => 0 ),
				'pipeline_mode'        => array( 'type' => 'select', 'label' => 'Pipeline de almacenamiento del chatbot legacy', 'desc' => '"Legacy" mantiene ecb_leads (comportamiento actual). "Capture" hace que el chatbot delega el almacenamiento del lead a esta suite (sin escribir ecb_leads), gated por el oráculo de paridad; reversible.', 'options' => array( 'legacy' => 'Legacy (ecb_leads, actual)', 'capture' => 'Capture (estandar_capture_submissions)' ), 'default' => 'legacy' ),
			),
		);
	}

	/** Chatbot settings flattened (all groups) for CLI. */
	public static function chatbot_settings_fields(): array
	{
		$out = array();
		foreach ( self::chatbot_settings_groups() as $g => $fields ) {
			$out = array_merge( $out, $fields );
		}
		return $out;
	}

	/** Descriptores de settings del bot de WhatsApp (para admin y CLI). */
	public static function whatsapp_settings_fields(): array
	{
		return \Estandar\Capture\WhatsAppBot::fields();
	}

	/** admin_post handler: guarda los settings del bot de WhatsApp. */
	public static function handle_save_whatsapp_settings(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_save_whatsapp_settings' );
		$raw = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? $_POST['settings'] : array();
		\Estandar\Capture\WhatsAppBot::save_settings( $raw );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=whatsapp&wa_saved=1' ) );
		exit;
 	}

	/** admin_post handler: guarda los settings del botón flotante de WhatsApp. */
	public static function handle_save_floating_whatsapp_settings(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_save_floating_whatsapp_settings' );
		$raw = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? $_POST['settings'] : array();
		\Estandar\Capture\FloatingWhatsApp::save_settings( $raw );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=whatsapp&wa_fwa_saved=1' ) );
		exit;
	}

	/** admin_post handler: prueba la conexión con la Cloud API. */
	public static function handle_whatsapp_test(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_whatsapp_test' );
		$r   = \Estandar\Capture\WhatsAppBot::test_connection();
		$msg = ! empty( $r['ok'] )
			? 'Conectado: ' . ( $r['data']['display_phone_number'] ?? $r['data']['id'] ?? '' ) . ' (' . ( $r['data']['verified_name'] ?? '' ) . ') · calidad ' . ( $r['data']['quality_rating'] ?? 'n/a' )
			: (string) ( $r['error'] ?? 'Error desconocido.' );
		set_transient( 'estandar_suite_wa_test_result', array( 'ok' => ! empty( $r['ok'] ), 'msg' => $msg ), MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=whatsapp&wa_tested=1' ) );
		exit;
	}

	/** admin_post handler: envía un mensaje de prueba a un número. */
	public static function handle_whatsapp_send_test(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_whatsapp_test' );
		$phone = sanitize_text_field( (string) ( $_POST['test_phone'] ?? '' ) );
		$r     = \Estandar\Capture\WhatsAppBot::send_test( $phone );
		$msg   = ! empty( $r['ok'] ) ? 'Mensaje de prueba enviado a ' . $phone . '.' : (string) ( $r['error'] ?? 'Error al enviar.' );
		set_transient( 'estandar_suite_wa_test_result', array( 'ok' => ! empty( $r['ok'] ), 'msg' => $msg ), MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=whatsapp&wa_tested=1' ) );
		exit;
	}

	/** admin_post handler: save a chatbot settings group (merge, keeps others). */
	public static function handle_save_chatbot_settings(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_save_chatbot_settings' );
		$group = sanitize_key( (string) ( $_POST['group'] ?? '' ) );
		$groups = self::chatbot_settings_groups();
		if ( ! isset( $groups[ $group ] ) ) {
			wp_die( 'Grupo desconocido.' );
		}
		$cur = get_option( 'estandar_suite_capture_chatbot_settings', array() );
		$cur = is_array( $cur ) ? $cur : array();
		$raw = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? $_POST['settings'] : array();
		$cur = array_merge( $cur, \Estandar\Core\Admin::sanitize_feature_fields( $groups[ $group ], $raw ) );

		// El toggle del puente leads vive en su propia option (se lee desde
		// LeadsBridge en cada evento; no desde la UI del chatbot).
		if ( $group === 'fusion' && isset( $raw['leads_bridge_enabled'] ) ) {
			LeadsBridge::set_enabled( (bool) $raw['leads_bridge_enabled'] );
		}
		update_option( 'estandar_suite_capture_chatbot_settings', $cur, false );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=chatbot&ss=' . $group . '&chatbot_saved=1' ) );
		exit;
	}

	/** Chatbot fusion group: puente leads + modo de pipeline (chatbot legacy). */
	public static function chatbot_pipeline_mode(): string
	{
		$cur = get_option( 'estandar_suite_capture_chatbot_settings', array() );
		$cur = is_array( $cur ) ? $cur : array();
		$mode = sanitize_key( (string) ( $cur['pipeline_mode'] ?? 'legacy' ) );
		return $mode === 'capture' ? 'capture' : 'legacy';
	}

	/** admin_post handler: import adopción de leads + espejos del chatbot. */
	public static function handle_chatbot_adoption_import(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_chatbot_adoption' );
		$dry = empty( $_POST['commit'] );
		$r   = ChatbotAdoption::import_all( $dry, 200 );
		$leads = (int) $r['leads']['imported'];
		$mirrors = 0;
		foreach ( $r['mirrors'] as $m ) { $mirrors += (int) $m['imported']; }
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=chatbot&ss=fusion'
			. ( $dry ? '&chatbot_adopt_dry=' . (int) ( $leads + $mirrors ) : '&chatbot_adopt_ok=' . (int) ( $leads + $mirrors ) ) ) );
		exit;
	}

	/** admin_post handler: rollback de datos del chatbot adoptados. */
	public static function handle_chatbot_adoption_rollback(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_chatbot_adoption' );
		$n = ChatbotAdoption::rollback_all();
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=chatbot&ss=fusion&chatbot_adopt_rolled=' . (int) $n ) );
		exit;
	}

	/** Data del sub-tab chatbot: suma estado de fusión (bridge + adopción). */
	public static function chatbot_fusion_data(): array
	{
		return array(
			'legacy_active'     => ChatbotBridge::legacy_chatbot_active(),
			'bridge_stats'      => ChatbotBridge::stats(),
			'leads_bridge'      => LeadsBridge::enabled(),
			'leads_active'      => LeadsBridge::leads_plugin_active(),
			'pipeline_mode'     => self::chatbot_pipeline_mode(),
			'adoption'          => ChatbotAdoption::counts(),
			'mirror_status'     => ChatbotAdoption::mirror_status(),
		);
	}

	/** admin_post handler: persist the capabilities matrix (type × cap). */
	public static function handle_save_caps_matrix(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_save_caps_matrix' );
		$types = array( 'lead', 'survey', 'incident' );
		$raw = isset( $_POST['matrix'] ) && is_array( $_POST['matrix'] ) ? $_POST['matrix'] : array();
		foreach ( $types as $t ) {
			$catalog = Forms::product_capabilities( $t );
			foreach ( $catalog as $cap => $cfg ) {
				Forms::set_capability( $t, $cap, ! empty( $raw[ $t ][ $cap ] ) );
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&tab=settings&sub=capacidades&matrix_saved=1' ) );
		exit;
	}

	/** admin_post handler: persist per-product capabilities. */
	public static function handle_save_capabilities(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_save_capabilities' );
		$type = sanitize_key( (string) ( $_POST['type'] ?? 'lead' ) );
		$catalog = Forms::product_capabilities( $type );
		$raw = isset( $_POST['caps'] ) && is_array( $_POST['caps'] ) ? $_POST['caps'] : array();
		foreach ( $catalog as $cap => $cfg ) {
			Forms::set_capability( $type, $cap, ! empty( $raw[ $cap ] ) );
		}
		$sub = array( 'lead' => 'forms', 'survey' => 'surveys', 'incident' => 'postventa' )[ $type ] ?? 'forms';
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=' . $sub . '&caps_saved=1' ) );
		exit;
	}

	/** admin_post handler: create or update a v2 popup (editor estructurado). */
	public static function handle_save_popup(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_save_popup' );
		$id = isset( $_POST['popup_id'] ) ? (int) $_POST['popup_id'] : 0;

		// POPUP_EDITOR_V2: el editor estructurado es la fuente. El campo
		// "config_json" SOLO reemplaza la config si trae JSON válido; ante texto
		// vacío o inválido NO se vacía la config (se usa el editor estructurado).
		$advanced = isset( $_POST['config_json'] ) ? trim( (string) wp_unslash( $_POST['config_json'] ) ) : '';
		$cfg      = self::resolve_popup_config( $advanced );

		// POPUP_BLOCKS_V1: conserva el vínculo al post de contenido (editor de
		// bloques) aunque se use el modo JSON avanzado.
		if ( isset( $_POST['content_post_id'] ) && (int) $_POST['content_post_id'] > 0 ) {
			$cfg['content_post_id'] = (int) $_POST['content_post_id'];
		}

		// Preservar metadatos no editables del registro existente.
		if ( $id ) {
			$cur = Popups::get( $id );
			if ( $cur ) {
				$prev = json_decode( (string) ( $cur['config_json'] ?? '' ), true );
				if ( is_array( $prev ) ) {
					foreach ( array( 'legacy_post_id', 'stats', 'open_method', 'active' ) as $k ) {
						if ( isset( $prev[ $k ] ) && ! isset( $cfg[ $k ] ) ) {
							$cfg[ $k ] = $prev[ $k ];
						}
					}
					// POPUP_DESIGN_LOCAL_V1: campos de diseño retirados del editor
					// (css_mode, z_index) se CONSERVAN del popup existente si el
					// POST no los trae — evita reiniciarlos al guardar.
					if ( isset( $prev['design'] ) && is_array( $prev['design'] ) ) {
						foreach ( array( 'css_mode', 'z_index' ) as $dk ) {
							if ( ! isset( $_POST[ 'design_' . $dk ] ) && isset( $prev['design'][ $dk ] ) ) {
								$cfg['design'][ $dk ] = $prev['design'][ $dk ];
							}
						}
					}
				}
			}
		}

		$raw = array(
			'name'   => sanitize_text_field( wp_unslash( (string) ( $_POST['name'] ?? '' ) ) ),
			'slug'   => sanitize_title( wp_unslash( (string) ( $_POST['slug'] ?? '' ) ) ),
			'status' => sanitize_key( wp_unslash( (string) ( $_POST['status'] ?? 'draft' ) ) ),
		);

		if ( $id ) {
			$cfg['custom_css_scoped'] = Popups::scope_css( (string) ( $cfg['custom_css'] ?? '' ), Popups::encode_frontend_id( $id ) );
			$raw['config_json'] = (string) wp_json_encode( $cfg );
			$ok = Popups::update( $id, $raw );
		} else {
			$raw['config_json'] = (string) wp_json_encode( $cfg );
			$id = Popups::create( $raw );
			$ok = $id > 0;
			if ( $ok ) {
				// El scope CSS necesita el id (frontend id) → segundo write.
				$cfg['custom_css_scoped'] = Popups::scope_css( (string) ( $cfg['custom_css'] ?? '' ), Popups::encode_frontend_id( $id ) );
				$raw['config_json'] = (string) wp_json_encode( $cfg );
				Popups::update( $id, $raw );
			}
		}

		// POPUP_BLOCKS_V1: "Guardar y editar contenido" → abre el editor de bloques.
		if ( $ok && $id && ! empty( $_POST['open_blocks'] ) ) {
			$cpid = Popups::ensure_content_post( (int) $id );
			if ( $cpid > 0 ) {
				wp_safe_redirect( admin_url( 'post.php?post=' . $cpid . '&action=edit' ) );
				exit;
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=popups&ss=popups&popup_saved=' . ( $ok ? 1 : 0 ) ) );
		exit;
	}

	/**
	 * POPUP_EDITOR_V2: resuelve la config de un popup. Si `$advanced` trae JSON
	 * válido, ese manda (modo avanzado); si viene vacío o inválido, se compone
	 * desde los campos estructurados del editor (nunca se vacía por un JSON roto).
	 */
	public static function resolve_popup_config( string $advanced ): array
	{
		if ( trim( $advanced ) !== '' ) {
			$decoded = json_decode( $advanced, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return self::popup_config_from_post();
	}

	/** POPUP_EDITOR_V2: compone el config_json desde los campos estructurados. */
	public static function popup_config_from_post(): array
	{
		$num = static function ( string $key, $default = 0 ) {
			return isset( $_POST[ $key ] ) && ! is_array( $_POST[ $key ] )
				? (float) wp_unslash( $_POST[ $key ] )
				: $default;
		};
		$list = static function ( string $key ): array {
			$raw   = isset( $_POST[ $key ] ) ? (string) wp_unslash( $_POST[ $key ] ) : '';
			$parts = preg_split( '/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
			return array_values( array_map( 'sanitize_text_field', (array) $parts ) );
		};

		$content_post_id = isset( $_POST['content_post_id'] ) ? (int) $_POST['content_post_id'] : 0;

		return array(
			// POPUP_BLOCKS_V1: si hay post de contenido (editor de bloques), el
			// contenido vive ahí y no se sobreescribe desde el textarea.
			'content'           => $content_post_id > 0 ? '' : wp_kses_post( (string) wp_unslash( (string) ( $_POST['content'] ?? '' ) ) ),
			'content_post_id'   => $content_post_id,
			'project_slug'      => sanitize_title( (string) wp_unslash( (string) ( $_POST['project_slug'] ?? '' ) ) ),
			'triggers'          => array(
				'time'           => (int) $num( 'trigger_time' ),
				'scroll'         => (int) $num( 'trigger_scroll' ),
				'inactivity'     => (int) $num( 'trigger_inactivity' ),
				'click_selector' => sanitize_text_field( (string) wp_unslash( (string) ( $_POST['trigger_click'] ?? '' ) ) ),
			),
			'segmentation'      => array(
				'post_ids'     => array_map( 'intval', $list( 'seg_post_ids' ) ),
				'url_contains' => $list( 'seg_url_contains' ),
				'date_start'   => sanitize_text_field( (string) wp_unslash( (string) ( $_POST['seg_date_start'] ?? '' ) ) ),
				'date_end'     => sanitize_text_field( (string) wp_unslash( (string) ( $_POST['seg_date_end'] ?? '' ) ) ),
			),
			'frequency'         => array(
				'mode' => sanitize_key( (string) wp_unslash( (string) ( $_POST['freq_mode'] ?? '7days' ) ) ),
				'days' => (int) $num( 'freq_days' ),
			),
			'design'            => array(
				'size'            => sanitize_key( (string) wp_unslash( (string) ( $_POST['design_size'] ?? 'medium' ) ) ),
				'custom_width'    => sanitize_text_field( (string) wp_unslash( (string) ( $_POST['design_custom_width'] ?? '' ) ) ),
				'css_mode'        => sanitize_key( (string) wp_unslash( (string) ( $_POST['design_css_mode'] ?? 'inherit' ) ) ),
				'overlay'         => ! empty( $_POST['design_overlay'] ),
				'overlay_color'   => ( sanitize_hex_color( (string) wp_unslash( (string) ( $_POST['design_overlay_color'] ?? '#000000' ) ) ) ?: '#000000' ),
				'overlay_opacity' => (float) $num( 'design_overlay_opacity', 0.6 ),
				'animation'       => \Estandar\Capture\Popups::normalize_animation( (string) wp_unslash( (string) ( $_POST['design_animation'] ?? 'fade' ) ), 'fade' ),
				'exit_animation'  => \Estandar\Capture\Popups::normalize_animation( (string) wp_unslash( (string) ( $_POST['design_exit_animation'] ?? 'same' ) ), 'same' ),
				'duration'        => \Estandar\Capture\Popups::normalize_duration( $num( 'design_duration', 0.4 ) ),
				'z_index'         => (int) $num( 'design_z_index', 99999 ),
			),
			'hide_if_submitted' => ! empty( $_POST['hide_if_submitted'] ),
			'custom_css'        => (string) wp_unslash( (string) ( $_POST['custom_css'] ?? '' ) ),
		);
	}

	/** admin_post handler: delete a v2-managed popup. */
	public static function handle_delete_popup(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_delete_popup' );
		$id = isset( $_POST['popup_id'] ) ? (int) $_POST['popup_id'] : 0;
		if ( $id && ( empty( $_POST['confirm'] ) || $_POST['confirm'] !== 'yes' ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=popups&ss=popups&popup_deleted=0' ) );
			exit;
		}
		$ok = $id ? Popups::delete( $id ) : false;
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=popups&ss=popups&popup_deleted=' . ( $ok ? 1 : 0 ) ) );
		exit;
	}

	/** admin_post handler: create or update a v2-managed form. */
	public static function handle_save_form(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_save_form' );
		$id = isset( $_POST['form_id'] ) ? (int) $_POST['form_id'] : 0;
		// wp_unslash: WP añade slashes a $_POST (wp_magic_quotes); sin esto el
		// config_json llegaba con \" y el guardado se degradaba a la config previa.
		$raw = array(
			'name'         => sanitize_text_field( wp_unslash( (string) ( $_POST['name'] ?? '' ) ) ),
			'slug'         => sanitize_title( wp_unslash( (string) ( $_POST['slug'] ?? '' ) ) ),
			'form_type'    => sanitize_key( wp_unslash( (string) ( $_POST['form_type'] ?? 'lead' ) ) ),
			'form_context' => sanitize_key( wp_unslash( (string) ( $_POST['form_context'] ?? 'generic' ) ) ),
			'config_json'  => (string) wp_unslash( (string) ( $_POST['config_json'] ?? '{}' ) ),
			'status'       => sanitize_key( wp_unslash( (string) ( $_POST['status'] ?? 'active' ) ) ),
		);
		$is_create = ( $id === 0 );
		$is_edit   = ( $id > 0 );

		// MAQ_RETURN_V1: sub-tab activa del maquetador desde el referer
		// (?st=… y ?sst=… de «Correos»), para volver a la misma pestaña al guardar.
		$raw_referer = (string) wp_unslash( (string) ( $_POST['_wp_http_referer'] ?? $_SERVER['HTTP_REFERER'] ?? '' ) );
		$raw_return_st  = '';
		$raw_return_sst = '';
		if ( $raw_referer !== '' ) {
			$ref_query = array();
			wp_parse_str( (string) wp_parse_url( $raw_referer, PHP_URL_QUERY ), $ref_query );
			$raw_return_st  = sanitize_key( (string) ( $ref_query['st'] ?? '' ) );
			$raw_return_sst = sanitize_key( (string) ( $ref_query['sst'] ?? '' ) );
		}
		if ( $raw_return_sst !== '' ) {
			$_POST['sst'] = $raw_return_sst;
		}

		// ── PROYECTOS (PROJECT_OWNERSHIP_V1): todo catcher queda bajo un proyecto.
		// En creación es obligatorio; si el usuario no elige, se usa/crea el
		// proyecto del sitio (nombre del sitio). En edición solo cambia si el
		// usuario elige otro / "auto" / "nuevo".
		$type_sub = array( 'lead' => 'forms', 'survey' => 'surveys', 'incident' => 'postventa' );
		$sub      = isset( $type_sub[ $raw['form_type'] ] ) ? $type_sub[ $raw['form_type'] ] : 'forms';
		$choice   = sanitize_key( wp_unslash( (string) ( $_POST['form_project'] ?? '' ) ) );
		// En edición, si el proyecto no cambió se deja intacto (nunca se revalida
		// ni reescribe: protege forms con proyecto archivado/inexistente).
		$cur_project = '';
		if ( ! $is_create ) {
			$ex_f = Forms::get( $id );
			if ( $ex_f ) {
				$ex_cfg = json_decode( (string) ( $ex_f['config_json'] ?? '{}' ), true );
				if ( is_array( $ex_cfg ) ) {
					$cur_project = sanitize_key( (string) ( $ex_cfg['form_project'] ?? '' ) );
				}
			}
		}
		$PR         = class_exists( '\Estandar\Core\Leads\ProjectRegistry' ) ? '\Estandar\Core\Leads\ProjectRegistry' : '';
		$want_change = $is_create || ( $choice !== '' && $choice !== $cur_project );
		if ( $PR !== '' && $want_change ) {
			$assign = array( 'ok' => true );
			if ( $is_create || $choice !== '' ) {
				$active_map = array();
				foreach ( $PR::registry() as $pj ) {
					$active_map[ (string) $pj['project_slug'] ] = (string) $pj['name'];
				}
				if ( $choice === '' || $choice === '__auto__' ) {
					$slug   = $PR::auto_project_slug();
					$assign = ( $slug !== '' ) ? array( 'ok' => true, 'slug' => $slug ) : array( 'ok' => false, 'error' => 'project_auto_failed' );
				} elseif ( $choice === '__new__' ) {
					$nlabel = sanitize_text_field( wp_unslash( (string) ( $_POST['form_project_new_label'] ?? '' ) ) );
					if ( $nlabel === '' ) {
						$assign = array( 'ok' => false, 'error' => 'project_new_label_required' );
					} else {
						$ncity  = sanitize_text_field( wp_unslash( (string) ( $_POST['form_project_new_city'] ?? '' ) ) );
						$assign = $PR::create_project( array( 'label' => $nlabel, 'city' => $ncity ) );
					}
				} else {
					$assign = isset( $active_map[ $choice ] )
						? array( 'ok' => true, 'slug' => $choice )
						: array( 'ok' => false, 'error' => 'project_invalid' );
				}
				if ( $assign['ok'] ) {
					$config = json_decode( (string) $raw['config_json'], true );
					if ( ! is_array( $config ) ) {
						$config = array();
					}
					$config['form_project']      = (string) $assign['slug'];
					$config['form_project_area'] = sanitize_text_field( (string) ( $assign['label'] ?? $PR::label_for( (string) $assign['slug'] ) ) );
					$raw['config_json'] = (string) wp_json_encode( $config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
				} else {
					$back = ( $raw['form_type'] === 'lead' ) ? 'sub=forms&fm=formularios' : 'sub=' . $sub;
					wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&' . $back . '&nuevo=1&project_error=' . rawurlencode( (string) $assign['error'] ) ) );
					exit;
				}
			}
		}

		// NOTIFY_GUARANTEE_V1: ningún form puede guardarse sin un operador con
		// mail (ni con `notify_emails`, ni routing, ni operadores del proyecto).
		$cfg_eff = json_decode( (string) $raw['config_json'], true );
		if ( ! is_array( $cfg_eff ) ) {
			$cfg_eff = array();
		}
		if ( $id > 0 ) {
			$ex0 = Forms::get( $id );
			if ( $ex0 ) {
				$merged0 = json_decode( Forms::merge_config_json( (string) $ex0['config_json'], (string) $raw['config_json'] ), true );
				if ( is_array( $merged0 ) ) {
					$cfg_eff = $merged0;
				}
			}
		}
		if ( ! Forms::form_has_designated_recipient( $cfg_eff ) ) {
			if ( $id > 0 ) {
				$q = 'admin.php?page=estandar-suite-capture&tab=config&sub=' . self::form_sub_for( $id ) . '&ss=formularios&editar=' . $id . '&st=correos&notify_error=1';
			} else {
				$back = ( $raw['form_type'] === 'lead' ) ? 'sub=forms&fm=formularios' : 'sub=' . $sub;
				$q = 'admin.php?page=estandar-suite-capture&tab=config&' . $back . '&nuevo=1&notify_error=1';
			}
			wp_safe_redirect( admin_url( $q ) );
			exit;
		}

		if ( $id ) {
			// Fusión no destructiva: el documento existente nunca se pierde por un
			// guardado parcial/vacío (protege config no visible de otras subtabs).
			$existing = Forms::get( $id );
			if ( $existing ) {
				$raw['config_json'] = Forms::merge_config_json( (string) $existing['config_json'], (string) $raw['config_json'] );
				// Cambio de tipo canónico → conversión no destructiva (mismo motor).
				if ( (string) $raw['form_type'] !== (string) $existing['form_type'] ) {
					$conv = Forms::convert_type( $id, (string) $raw['form_type'], $raw );
					$ok = ! empty( $conv['ok'] );
				} else {
					$ok = Forms::update( $id, $raw );
				}
			} else {
				$ok = false;
			}
		} else {
			$id = Forms::create( $raw );
			$ok = $id > 0;
		}

		// MAQ_RETURN_V1: al guardar se vuelve al EDITOR del form (no al listado),
		// conservando la pestaña activa del maquetador (st / sst de «Correos»)
		// desde el referer. Así la edición continúa donde se estaba trabajando.
		// En creación se abre el editor del form recién creado (pestaña por
		// defecto). Si algo falló, se mantiene la vuelta al listado (form_saved=0).
		if ( $ok && $id > 0 ) {
			$feature = self::form_sub_for( $id );
			$return_st  = $raw_return_st;
			if ( ! isset( self::editor_subtabs()[ $return_st ] ) ) {
				$return_st = 'configuracion';
			}
			$query = array(
				'page' => 'estandar-suite-capture', 'tab' => 'config', 'sub' => $feature,
				'ss' => 'formularios', 'editar' => $id, 'st' => $return_st,
			);
			if ( $return_st === 'correos' ) {
				$return_sst = sanitize_key( (string) ( $_POST['sst'] ?? '' ) );
				if ( ! isset( self::editor_sstabs()[ $return_sst ] ) ) {
					$return_sst = 'pantalla';
				}
				$query['sst'] = $return_sst;
			}
			$query['form_saved'] = 1;
			wp_safe_redirect( admin_url( 'admin.php?' . http_build_query( $query ) ) );
			exit;
		}

		$back = ( $raw['form_type'] === 'lead' ) ? 'sub=forms&ss=formularios' : 'sub=' . $sub . '&ss=formularios';
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&' . $back . '&form_saved=' . ( $ok ? 1 : 0 ) ) );
		exit;
	}

	/** admin_post handler: move a v2-managed form to the papelera (soft delete). */
	public static function handle_delete_form(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_delete_form' );
		$id = isset( $_REQUEST['form_id'] ) ? (int) $_REQUEST['form_id'] : 0;
		if ( $id && ( empty( $_REQUEST['confirm'] ) || $_REQUEST['confirm'] !== 'yes' ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=forms&ss=formularios&form_trashed=0' ) );
			exit;
		}
		$ok = $id ? Forms::trash( $id ) : false;
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=' . self::form_sub_for( $id ) . '&ss=formularios&form_trashed=' . ( $ok ? 1 : 0 ) ) );
		exit;
	}

	/** admin_post handler: restore a trashed form. */
	public static function handle_restore_form(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_restore_form' );
		$id = isset( $_REQUEST['form_id'] ) ? (int) $_REQUEST['form_id'] : 0;
		$ok = $id ? Forms::restore( $id ) : false;
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=' . self::form_sub_for( $id ) . '&ss=formularios&papelera=1&form_restored=' . ( $ok ? 1 : 0 ) ) );
		exit;
	}

	/** admin_post handler: permanently delete a trashed form. */
	public static function handle_purge_form(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_purge_form' );
		$id = isset( $_REQUEST['form_id'] ) ? (int) $_REQUEST['form_id'] : 0;
		if ( $id && ( empty( $_REQUEST['confirm'] ) || $_REQUEST['confirm'] !== 'yes' ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=forms&ss=formularios&papelera=1&form_purged=0' ) );
			exit;
		}
		$ok = $id ? Forms::delete( $id ) : false;
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=' . self::form_sub_for( $id ) . '&ss=formularios&papelera=1&form_purged=' . ( $ok ? 1 : 0 ) ) );
		exit;
	}

	/** Redirect limpio al listado del producto del form. */
	private static function form_list_url( int $form_id, string $extra = '' ): string
	{
		return admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=' . self::form_sub_for( $form_id ) . '&ss=formularios' . $extra );
	}

	/** admin_post: consolidar (migrar) un form hacia otro + backup + papelera. */
	public static function handle_consolidate_forms(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_consolidate' );
		$from = isset( $_REQUEST['from_id'] ) ? (int) $_REQUEST['from_id'] : 0;
		$to   = isset( $_REQUEST['to_id'] ) ? (int) $_REQUEST['to_id'] : 0;
		if ( $from <= 0 || $to <= 0 || empty( $_REQUEST['confirm'] ) || $_REQUEST['confirm'] !== 'yes' ) {
			wp_safe_redirect( self::form_list_url( $from, '&consolidado=0' ) );
			exit;
		}
		$r = Forms::consolidate( $from, $to, false );
		if ( empty( $r['ok'] ) ) {
			wp_safe_redirect( self::form_list_url( $from, '&consolidado=err' ) );
			exit;
		}
		$name = isset( $r['backup']['name'] ) ? rawurlencode( (string) $r['backup']['name'] ) : '';
		$extra = sprintf( '&consolidado=%d&moved=%d&pages=%d&bk=%s', (int) $to, (int) $r['submissions_moved'], count( $r['pages_updated'] ), $name );
		wp_safe_redirect( self::form_list_url( $from, $extra ) );
		exit;
	}

	/** admin_post: backup + mover a papelera (conserva entries, sin migrar). */
	public static function handle_form_delete_keep(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_delete_keep' );
		$from = isset( $_REQUEST['form_id'] ) ? (int) $_REQUEST['form_id'] : 0;
		if ( $from <= 0 || empty( $_REQUEST['confirm'] ) || $_REQUEST['confirm'] !== 'yes' ) {
			wp_safe_redirect( self::form_list_url( $from, '&keep_backup=0' ) );
			exit;
		}
		$b = Forms::backup_payload( $from );
		if ( empty( $b['ok'] ) ) {
			wp_safe_redirect( self::form_list_url( $from, '&keep_backup=err' ) );
			exit;
		}
		Forms::trash( $from );
		wp_safe_redirect( self::form_list_url( $from, '&keep_backup=1&bk=' . rawurlencode( (string) $b['name'] ) ) );
		exit;
	}

	/** admin_post: purga definitiva (con o sin sus submissions). */
	public static function handle_purge_full(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_purge_full' );
		$id = isset( $_REQUEST['form_id'] ) ? (int) $_REQUEST['form_id'] : 0;
		if ( $id <= 0 || empty( $_REQUEST['confirm'] ) || $_REQUEST['confirm'] !== 'yes' ) {
			wp_safe_redirect( self::form_list_url( $id, '&form_purged=0' ) );
			exit;
		}
		if ( ! empty( $_REQUEST['delete_entries'] ) ) {
			Forms::purge_submissions( $id );
		}
		Forms::delete( $id );
		wp_safe_redirect( self::form_list_url( $id, '&form_purged=1' ) );
		exit;
	}

	/** Map a form id to its product sub-tab slug. */
	private static function form_sub_for( int $id ): string
	{
		$f = Forms::get( $id );
		if ( $f ) {
			return array( 'lead' => 'forms', 'survey' => 'surveys', 'incident' => 'postventa' )[ $f['form_type'] ] ?? 'forms';
		}
		return 'forms';
	}

	/**
	 * Subtabs del editor (maquetador). Registro canónico y extensible: añadir una
	 * clave aquí y un panel <div class="es-st-panel" data-panel="..."> en el
	 * template. La primera clave es la subtab por defecto.
	 */
	public static function editor_subtabs(): array
	{
		return array(
			'configuracion' => 'Ajustes generales',
			'diseno'        => 'Campos y diseño',
			'correos'       => 'Correos y confirmación',
			'entradas'      => 'Entradas',
		);
	}

	/** Alias de subtabs antiguas absorbidas (deep-links compat). */
	public static function editor_subtab_aliases(): array
	{
		return array(
			'general'      => 'configuracion',
			'repeticion'   => 'correos',
			'confirmacion' => 'correos',
			'correo'       => 'correos',
		);
	}

	/**
	 * Sub-sub-tabs del editor dentro de «Correos y confirmación» (CORREOS_V1).
	 * Orden del pipeline de envío: 1) pantalla, 2) correo al remitente,
	 * 3) correo diferido, 4) notificación al equipo, 5) identidad visual.
	 */
	public static function editor_sstabs(): array
	{
		return array(
			'pantalla'     => '1 · Confirmación en pantalla',
			'autoresponse' => '2 · Correo de confirmación al remitente',
			'diferido'     => '3 · Correo diferido (marketero)',
			'notify'       => '4 · Notificación al equipo',
			'identidad'    => '5 · Identidad visual',
		);
	}

	/** Sub-sub-tab activa de «Correos y confirmación» (?sst=), con mapeo de
	 *  deep-links antiguos: st=confirmacion → pantalla, st=correo → notify. */
	public static function resolve_editor_sst( string $raw_st = '' ): string
	{
		if ( isset( $_GET['sst'] ) && $_GET['sst'] !== '' ) {
			return \Estandar\Core\Admin::resolve_ssub( self::editor_sstabs(), 'pantalla', 'sst' );
		}
		$map = array(
			'confirmacion' => 'pantalla',
			'repeticion'   => 'pantalla',
			'correo'       => 'notify',
		);
		return $map[ sanitize_key( (string) $raw_st ) ] ?? 'pantalla';
	}

	/**
	 * Subtab de editor solicitada (parámetro canónico 'st'), con fallback seguro.
	 * Las subtabs históricas (general/repeticion) mapean a Configuración.
	 */
	public static function resolve_editor_subtab(): string
	{
		$req = sanitize_key( (string) ( $_GET['st'] ?? '' ) );
		$aliases = self::editor_subtab_aliases();
		if ( $req !== '' && isset( $aliases[ $req ] ) ) {
			return $aliases[ $req ];
		}
		return \Estandar\Core\Admin::resolve_ssub( self::editor_subtabs(), (string) array_key_first( self::editor_subtabs() ), 'st' );
	}

	/**
	 * ¿Mostrar el bloque "Configuración heredada" (Forms legacy) bajo la lista de
	 * Forms? Oculta por defecto; recuperable vía option técnica o filtro.
	 */
	public static function show_legacy_config(): bool
	{
		if ( get_option( 'estandar_suite_capture_legacy_config_visible', '0' ) === '1' ) {
			return true;
		}
		return (bool) apply_filters( 'estandar_suite_capture_show_legacy_config', false );
	}

	/**
	 * ¿Mostrar el breadcrumb del editor? Ya no se renderiza en el maquetador
	 * (backend ordenado). Se conserva solo como interruptor por si un filtro o
	 * option técnica lo necesita; por defecto apagado.
	 */
	public static function show_editor_breadcrumb(): bool
	{
		if ( get_option( 'estandar_suite_capture_editor_breadcrumb_visible', '0' ) === '1' ) {
			return true;
		}
		return (bool) apply_filters( 'estandar_suite_capture_show_editor_breadcrumb', false );
	}

	public static function menu(): void
	{
		add_submenu_page( 'estandar-suite', 'Capture', 'Capture', 'manage_options', 'estandar-suite-capture', array( __CLASS__, 'render' ) );
	}

	/** Per-functionality settings descriptors (key => field config). */
	public static function feature_fields(): array
	{
		return array(
			'forms' => array(
				'enabled'      => array( 'type' => 'bool', 'label' => 'Módulo de formularios habilitado', 'desc' => 'Activa el módulo de formularios en la suite.', 'default' => 1 ),
				'notify_email' => array( 'type' => 'text', 'label' => 'Email de notificación', 'desc' => 'Recibe aviso de nuevas entradas de formularios.', 'default' => '' ),
				'store_entries'=> array( 'type' => 'bool', 'label' => 'Almacenar entradas', 'desc' => 'Guarda cada envío en la tabla de entradas.', 'default' => 1 ),
			),
			'surveys' => array(
				'enabled'        => array( 'type' => 'bool', 'label' => 'Módulo de encuestas habilitado', 'desc' => 'Activa el módulo de encuestas en la suite.', 'default' => 1 ),
				'default_mode'   => array( 'type' => 'select', 'label' => 'Modo de identificación por defecto', 'desc' => 'Cómo se identifica al encuestado.', 'default' => 'anonymous', 'options' => array( 'anonymous' => 'Anónimo', 'identity' => 'Con identidad' ) ),
			),
			'postventa' => array(
				'enabled'        => array( 'type' => 'bool', 'label' => 'Módulo de postventa habilitado', 'desc' => 'Activa el módulo de postventa en la suite.', 'default' => 1 ),
				'default_channel'=> array( 'type' => 'select', 'label' => 'Canal de soporte por defecto', 'desc' => 'Canal principal de los casos de postventa.', 'default' => 'incident', 'options' => array( 'incident' => 'Incidente', 'soporte' => 'Soporte', 'postventa' => 'Postventa' ) ),
			),
			// CHATBOT_CLEANUP_V1: se retiró la entrada 'chatbot' de feature_fields.
			// Estaba huérfana (nunca se renderizaba) y su option por defecto
			// (`estandar_suite_capture_chatbot_settings`) COLISIONABA con la config
			// real del bot: un save accidental la habría sobrescrito.
			'popups' => array(
				'enabled'      => array( 'type' => 'bool', 'label' => 'Módulo de popups habilitado', 'desc' => 'Activa el módulo de popups en la suite.', 'default' => 1 ),
				'track_events' => array( 'type' => 'bool', 'label' => 'Registrar views/opens/clicks', 'desc' => 'Anota la actividad de cada popup.', 'default' => 1 ),
			),
			'leads' => array(
				'enabled'        => array( 'type' => 'bool', 'label' => 'Módulo de leads habilitado', 'desc' => 'Activa la vista operativa de leads en la suite.', 'default' => 1 ),
				'default_status' => array( 'type' => 'select', 'label' => 'Estado inicial', 'desc' => 'Estado por defecto de un lead nuevo.', 'default' => 'new', 'options' => array( 'new' => 'New', 'contacted' => 'Contactado', 'qualified' => 'Calificado' ) ),
			),
		);
	}

	/** Data provider for a feature sub-tab (read-only legacy + v2). */
	public static function feature_data( string $name ): array
	{
		$data = array( 'forms' => array(), 'entries' => array(), 'by_form' => array(), 'incidents' => 0, 'surveys' => 0 );
		if ( in_array( $name, array( 'forms', 'surveys', 'postventa' ), true ) ) {
			$data['forms']     = \Estandar\Core\Admin::legacy_rows( 'estandar_forms', 'ORDER BY id DESC LIMIT 200' );
			$data['v2_forms']  = Forms::list();
			$data['entries']   = \Estandar\Core\Admin::legacy_rows( 'estandar_form_entries', 'ORDER BY id DESC LIMIT 500' );
			$data['responses'] = \Estandar\Core\Admin::legacy_rows( 'estandar_survey_responses', 'ORDER BY id DESC LIMIT 500' );
			$data['incidents'] = \Estandar\Core\Admin::legacy_count( 'estandar_incidents' );
			$data['surveys']   = \Estandar\Core\Admin::legacy_count( 'estandar_survey_responses' );
			if ( \Estandar\Core\Admin::legacy_table( 'estandar_form_entries' ) ) {
				global $wpdb;
				$rows = $wpdb->get_results( 'SELECT form_id, COUNT(*) AS n FROM ' . $wpdb->prefix . 'estandar_form_entries GROUP BY form_id', ARRAY_A );
				foreach ( (array) $rows as $r ) {
					$data['by_form'][ (int) $r['form_id'] ] = (int) $r['n'];
				}
			}
		} elseif ( $name === 'chatbot' ) {
			$data['conversations'] = \Estandar\Core\Admin::legacy_rows( 'ecb_conversations', 'ORDER BY id DESC LIMIT 30' );
			$data['leads']         = \Estandar\Core\Admin::legacy_rows( 'ecb_leads', 'ORDER BY id DESC LIMIT 30' );
			$data['events']        = \Estandar\Core\Admin::legacy_rows( 'ecb_events', 'ORDER BY id DESC LIMIT 30' );
			$data['logs']          = \Estandar\Core\Admin::legacy_rows( 'ecb_logs', 'ORDER BY id DESC LIMIT 30' );
			$data['fusion']        = self::chatbot_fusion_data();
		} elseif ( $name === 'popups' ) {
			$data['v2_popups'] = Popups::list();
		} elseif ( $name === 'leads' ) {
			$data['leads']         = \Estandar\Core\Admin::legacy_rows( 'es_leads', 'ORDER BY id DESC LIMIT 50' );
			$data['submissions']   = \Estandar\Core\Admin::legacy_count( 'es_lead_submissions' );
			$data['lead_events']   = \Estandar\Core\Admin::legacy_count( 'es_lead_events' );
			$data['v2_submissions'] = \Estandar\Core\Admin::legacy_count( 'estandar_capture_submissions' );
			$data['customers']     = \Estandar\Core\Admin::legacy_rows( 'es_customers', 'ORDER BY id DESC LIMIT 30' );
			$data['operators']     = \Estandar\Core\Admin::legacy_rows( 'es_operators', 'ORDER BY id DESC LIMIT 30' );
			// Leads capturados por el chatbot (ecb_leads): viven en el área de
			// Leads para no duplicar la vista dentro del chatbot.
			$data['chatbot_leads'] = \Estandar\Core\Admin::legacy_rows( 'ecb_leads', 'ORDER BY id DESC LIMIT 30' );
		}
		return $data;
	}

	/** admin_post handler: persist feature settings (whitelisted fields). */
	public static function handle_save_feature(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_save_feature' );
		$feature = sanitize_key( (string) ( $_POST['feature'] ?? '' ) );
		$fields  = self::feature_fields();
		if ( ! isset( $fields[ $feature ] ) ) {
			wp_die( 'Feature desconocido.' );
		}
		$raw = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? $_POST['settings'] : array();
		update_option( 'estandar_suite_capture_' . $feature . '_settings', \Estandar\Core\Admin::sanitize_feature_fields( $fields[ $feature ], $raw ) );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=' . $feature . '&feature_saved=1' ) );
		exit;
	}

	// ── Read-only helpers over legacy tables (never write to them) ─────────

	private static function legacy_table( string $name ): bool
	{
		return \Estandar\Core\Admin::legacy_table( $name );
	}

	private static function legacy_rows( string $name, string $suffix = 'ORDER BY id DESC LIMIT 50' ): array
	{
		return \Estandar\Core\Admin::legacy_rows( $name, $suffix );
	}

	private static function legacy_count( string $name ): int
	{
 		return \Estandar\Core\Admin::legacy_count( $name );
 	}

	/** Estructura de visibilidad de este producto (para el manager central de core). */
	public static function visibility_structure(): array
	{
		return array(
			'product' => 'capture',
			'page'    => 'estandar-suite-capture',
			'label'   => 'Capture',
			'subs'    => array(
				'estado'   => array( 'resumen' => 'Resumen', 'datos' => 'Datos', 'esquema' => 'Esquema' ),
			'config'   => array( 'chatbot' => 'Chatbot', 'whatsapp' => 'WhatsApp', 'forms' => 'Forms', 'surveys' => 'Surveys', 'popups' => 'Popups', 'leads' => 'Leads' ),
				'datos'    => array( 'adopcion' => 'Adopción', 'historial' => 'Histórico' ),
				'settings' => array( 'capacidades' => 'Capacidades', 'modulos' => 'Módulos' ),
				'avanzado' => array( 'rest' => 'REST', 'cli' => 'WP-CLI', 'inventario' => 'Inventario' ),
			),
			'ss' => array(
				'config' => array(
					'chatbot' => self::chatbot_ssubs(),
					'popups'  => self::popups_ssubs(),
					'leads'   => self::leads_ssubs(),
				),
			),
		);
	}
	/** NOTIFY_GUARANTEE_V1: forms activos sin destinatario de aviso designado. */
	public static function forms_missing_recipient(): array
	{
		global $wpdb;
		$out = array();
		if ( ! isset( $wpdb ) ) {
			return $out;
		}
		$t = $wpdb->prefix . 'estandar_capture_forms';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) {
			return $out;
		}
		$rows = $wpdb->get_results( "SELECT id, name, form_type, config_json FROM {$t} WHERE status = 'active' ORDER BY id", ARRAY_A );
		foreach ( (array) $rows as $r ) {
			$c = json_decode( (string) ( $r['config_json'] ?? '{}' ), true );
			if ( ! is_array( $c ) ) {
				$c = array();
			}
			if ( ! \Estandar\Capture\Forms::form_has_designated_recipient( $c ) ) {
				$out[] = array( 'id' => (int) $r['id'], 'name' => (string) $r['name'], 'type' => (string) $r['form_type'] );
			}
		}
		return $out;
	}

	/** Aviso en la página de configuración: forms sin operador, con enlace directo. */
	public static function render_notify_guard_notice(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$miss = self::forms_missing_recipient();
		if ( ! $miss ) {
			return;
		}
		echo '<div class="notice notice-error" style="border-left-color:#d63638;">';
		echo '<p><strong>Formularios sin operador de aviso (obligatorio).</strong> Estos catchers no tienen «Correos de aviso», ni un campo de routing, ni operadores activos en su proyecto: un lead que entre por ellos <strong>no notifica a nadie</strong>. Asígnalo en «Correos de aviso»:</p>';
		echo '<ul style="margin:6px 0 6px 18px;list-style:disc;">';
		foreach ( $miss as $m ) {
			$link = admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=' . self::form_sub_for( $m['id'] ) . '&ss=formularios&editar=' . $m['id'] . '&st=correos' );
			echo '<li><strong>#' . (int) $m['id'] . '</strong> — ' . esc_html( $m['name'] ) . ' (' . esc_html( $m['type'] ) . ') → <a href="' . esc_url( $link ) . '">asignar operador / correo de aviso</a></li>';
		}
		echo '</ul></div>';
	}

 	public static function render(): void
 	{
 		if ( ! current_user_can( 'manage_options' ) ) {
 			wp_die( 'Sin permisos.' );
 		}
  		// PROJECT_OWNERSHIP_V1 · la guía "sin proyectos → a Proyectos" se resuelve
  		// en feature-forms-product (bloqueo en la pantalla de creación).
  		\Estandar\Core\Admin::render_product_header();
 		$tabs = array(
			'estado'   => 'Estado',
			'config'   => 'Catchers',
			'datos'    => 'Datos',
			'settings' => 'Settings',
			'avanzado' => 'Avanzado',
		);
		$tab     = \Estandar\Core\Admin::resolve_tab( $tabs, 'estado' );
		$subs = array(
			'estado'   => array( 'resumen' => 'Resumen', 'datos' => 'Datos', 'esquema' => 'Esquema' ),
			'config'   => array( 'chatbot' => 'Chatbot', 'whatsapp' => 'WhatsApp', 'forms' => 'Forms', 'surveys' => 'Surveys', 'popups' => 'Popups', 'leads' => 'Leads' ),
			'datos'    => array( 'adopcion' => 'Adopción', 'historial' => 'Histórico' ),
			'settings' => array( 'capacidades' => 'Capacidades', 'modulos' => 'Módulos' ),
			'avanzado' => array( 'rest' => 'REST', 'cli' => 'WP-CLI', 'inventario' => 'Inventario' ),
		);
		// ── Visibilidad: filter tabs/sub-tabs (visual only) ─────────────────
		$visible_tabs = \Estandar\Core\Admin::filter_tabs( 'capture', $tabs, $subs );
		if ( empty( $visible_tabs ) ) {
			\Estandar\Core\Admin::render_visibility_none( 'capture' );
			return;
		}
		foreach ( $subs as $t => $s_list ) {
			$subs[ $t ] = \Estandar\Core\Admin::filter_subs( 'capture', $t, $s_list );
		}
		$tabs = $visible_tabs;
		// Sin parámetros → aterriza en Formularios (primer método de Capture);
		// la infra (estado/datos/settings/avanzado) solo se ve si se pide por URL.
		$default_tab = isset( $_GET['tab'] ) ? 'estado' : 'config';
		$tab = \Estandar\Core\Admin::resolve_tab( $tabs, $default_tab );
		$default_sub = ( $tab === 'config' && ! isset( $_GET['sub'] ) ) ? 'forms' : '';
		$sub = isset( $subs[ $tab ] ) ? \Estandar\Core\Admin::resolve_sub( $subs[ $tab ], $default_sub ) : '';

		// NOTIFY_GUARANTEE_V1: aviso de catchers existentes sin operador de aviso.
		if ( $tab === 'config' ) {
			self::render_notify_guard_notice();
		}

		// ── Settings tab data: capability matrix + feature fields ──────────
		$settings_matrix = Forms::capability_matrix();
		$settings_types  = array( 'lead' => 'Formularios', 'survey' => 'Encuestas' );
		$settings_features = array( 'forms', 'surveys', 'popups', 'leads' );
		$feature_fields_all = self::feature_fields();
		$legacy  = Adoption::legacy_present();
		$history = $legacy ? Adoption::historical( 50, 0 ) : array();
		$imported = isset( $_GET['imported'] ) ? (int) $_GET['imported'] : null;
		$rolled   = isset( $_GET['rolled'] ) ? (int) $_GET['rolled'] : null;

		// ── Feature sub-tab data (forms/chatbot/popups/leads) ───────────────
		$feature_fields = self::feature_fields();
		$feature_view   = array();
		$feature_saved  = isset( $_GET['feature_saved'] );
		if ( $tab === 'config' && isset( $feature_fields[ $sub ] ) ) {
			$feature_view = self::feature_data( $sub );
		}

		// ── Funciones que usan settings (marcadas en el inventario) ─────────
		$fn_settings = array(
			'activate_schema'  => array( 'estandar_suite_capture_schema_version' ),
			'maybe_migrate_schema' => array( 'estandar_suite_capture_schema_version' ),
		);

		// ── Inventario completo de funciones (cobertura 100%) ──────────────
		$sets = array(
			'Esquema (ciclo de vida y tablas)' => array(
				array( 'fn' => 'activate_schema', 'file' => 'Ingestion.php', 'desc' => 'Crea la tabla estandar_capture_submissions al activar.' ),
				array( 'fn' => 'maybe_migrate_schema', 'file' => 'Ingestion.php', 'desc' => 'Migra el esquema según versión guardada.' ),
				array( 'fn' => 'columns', 'file' => 'Ingestion.php', 'desc' => 'Definición de columnas de la tabla de capturas.' ),
				array( 'fn' => 'activate_schema', 'file' => 'Forms.php', 'desc' => 'Crea la tabla estandar_capture_forms (forms v2) al activar.' ),
				array( 'fn' => 'maybe_migrate_schema', 'file' => 'Forms.php', 'desc' => 'Migra el esquema de forms v2 según versión guardada.' ),
				array( 'fn' => 'types', 'file' => 'Forms.php', 'desc' => 'Tipos de formulario extensibles (lead/incident/survey + filter).' ),
			),
			'Dominio (lógica de negocio)' => array(
				array( 'fn' => 'ingest', 'file' => 'Ingestion.php', 'desc' => 'Ingesta normalizada de una captura.' ),
				array( 'fn' => 'list', 'file' => 'Forms.php', 'desc' => 'Lista forms v2 (opcionalmente por tipo).' ),
				array( 'fn' => 'get', 'file' => 'Forms.php', 'desc' => 'Lee un form v2 por id.' ),
				array( 'fn' => 'create', 'file' => 'Forms.php', 'desc' => 'Crea un form v2 (name requerido).' ),
				array( 'fn' => 'update', 'file' => 'Forms.php', 'desc' => 'Actualiza un form v2.' ),
				array( 'fn' => 'delete', 'file' => 'Forms.php', 'desc' => 'Elimina un form v2.' ),
				array( 'fn' => 'sanitize', 'file' => 'Forms.php', 'desc' => 'Sanitiza/defaults el payload de un form v2.' ),
				array( 'fn' => 'product_capabilities', 'file' => 'Forms.php', 'desc' => 'Catálogo de capacidades por tipo de producto (motor compartido).' ),
				array( 'fn' => 'contexts', 'file' => 'Forms.php', 'desc' => 'Lista cerrada de contextos de negocio (5 + otros).' ),
				array( 'fn' => 'context_label', 'file' => 'Forms.php', 'desc' => 'Etiqueta legible de un contexto.' ),
				array( 'fn' => 'duplicate', 'file' => 'Forms.php', 'desc' => 'Duplica un form (copia en borrador, slug único).' ),
				array( 'fn' => 'trash', 'file' => 'Forms.php', 'desc' => 'Mueve un form a la papelera (soft delete).' ),
				array( 'fn' => 'restore', 'file' => 'Forms.php', 'desc' => 'Restaura un form de la papelera.' ),
				array( 'fn' => 'list_trashed', 'file' => 'Forms.php', 'desc' => 'Lista los forms en papelera.' ),
				array( 'fn' => 'is_capability_enabled', 'file' => 'Forms.php', 'desc' => '¿Está habilitada una capacidad para un tipo?' ),
				array( 'fn' => 'set_capability', 'file' => 'Forms.php', 'desc' => 'Habilita/deshabilita una capacidad por tipo.' ),
				array( 'fn' => 'caps_overrides', 'file' => 'Forms.php', 'desc' => 'Overrides guardados de capacidades (por tipo).' ),
				array( 'fn' => 'normalize', 'file' => 'Ingestion.php', 'desc' => 'Normaliza una captura a la estructura canónica.' ),
				array( 'fn' => 'normalize_phone', 'file' => 'Ingestion.php', 'desc' => 'Normaliza el teléfono (formato consistente).' ),
				array( 'fn' => 'fallback_uuid', 'file' => 'Ingestion.php', 'desc' => 'Genera un UUID si la captura no trae uno.' ),
				array( 'fn' => 'row_for_insert', 'file' => 'Ingestion.php', 'desc' => 'Prepara la fila para insertar.' ),
				array( 'fn' => 'persist', 'file' => 'Ingestion.php', 'desc' => 'Guarda la captura en la tabla propia.' ),
				array( 'fn' => 'get', 'file' => 'Ingestion.php', 'desc' => 'Lee capturas recientes.' ),
				array( 'fn' => 'extract_click_ids', 'file' => 'Attribution.php', 'desc' => 'Extrae click IDs de la captura.' ),
				array( 'fn' => 'channel', 'file' => 'Attribution.php', 'desc' => 'Resuelve el canal de la captura.' ),
				array( 'fn' => 'has_click_evidence', 'file' => 'Attribution.php', 'desc' => 'Indica si hay evidencia de click.' ),
				array( 'fn' => 'fallback_click_ids', 'file' => 'Attribution.php', 'desc' => 'Click IDs de respaldo (cookies).' ),
				array( 'fn' => 'fallback_channel', 'file' => 'Attribution.php', 'desc' => 'Canal de respaldo.' ),
				array( 'fn' => 'fallback_evidence', 'file' => 'Attribution.php', 'desc' => 'Evidencia de respaldo para atribución.' ),
			),
			'Adopción (datos legados)' => array(
				array( 'fn' => 'legacy_present', 'file' => 'Adoption.php', 'desc' => 'Detecta tablas legadas de leads/submissions.' ),
				array( 'fn' => 'project_submission', 'file' => 'Adoption.php', 'desc' => 'Proyecta una submission legada a la estructura nueva.' ),
				array( 'fn' => 'historical', 'file' => 'Adoption.php', 'desc' => 'Lista submissions históricas para previsualizar.' ),
				array( 'fn' => 'import', 'file' => 'Adoption.php', 'desc' => 'Importa submissions históricas (dry-run o commit).' ),
				array( 'fn' => 'rollback_import', 'file' => 'Adoption.php', 'desc' => 'Elimina solo los registros adoptados.' ),
			),
			'Eventos (integración)' => array(
				array( 'fn' => 'init', 'file' => 'Admin.php', 'desc' => 'Registra submenú y handlers admin_post.' ),
				array( 'fn' => 'ingest → estandar_suite_capture_event', 'file' => 'Ingestion.php', 'desc' => 'Emite el evento canónico de captura al persistir.' ),
			),
			'REST API' => array(
				array( 'fn' => 'routes', 'file' => 'Rest.php', 'desc' => 'Rutas REST (POST /captures, GET /adoption/historical).' ),
				array( 'fn' => 'init', 'file' => 'Rest.php', 'desc' => 'Registra rutas en rest_api_init.' ),
			),
			'WP-CLI' => array(
				array( 'fn' => 'ingest', 'file' => 'Cli.php', 'desc' => 'Ingesta una captura por CLI.' ),
				array( 'fn' => 'adoption_status', 'file' => 'Cli.php', 'desc' => 'Estado de la adopción de leads.' ),
				array( 'fn' => 'adoption_import', 'file' => 'Cli.php', 'desc' => 'Importa leads históricos (--commit).' ),
				array( 'fn' => 'adoption_rollback', 'file' => 'Cli.php', 'desc' => 'Rollback de adoptados (--confirm=yes).' ),
				array( 'fn' => 'forms_list|legacy|create|update|delete|summary|entries|responses', 'file' => 'Cli.php', 'desc' => 'CRUD de forms v2 + legado + entradas/respuestas.' ),
				array( 'fn' => 'surveys_list|create|update|delete|responses|summary|settings', 'file' => 'Cli.php', 'desc' => 'CRUD de encuestas (producto Surveys) + respuestas.' ),
				array( 'fn' => 'postventa_list|create|update|delete|entries|summary|settings', 'file' => 'Cli.php', 'desc' => 'CRUD de casos de postventa + entradas.' ),
				array( 'fn' => 'products caps get|set', 'file' => 'Cli.php', 'desc' => 'Consulta/configura capacidades por tipo de producto.' ),
				array( 'fn' => 'chatbot_settings_groups', 'file' => 'Admin.php', 'desc' => 'Catálogo de settings del chatbot por sub-tab (6 grupos).' ),
				array( 'fn' => 'chatbot_settings_fields', 'file' => 'Admin.php', 'desc' => 'Settings del chatbot aplanados (CLI).' ),
				array( 'fn' => 'handle_save_chatbot_settings', 'file' => 'Admin.php', 'desc' => 'Handler admin_post: guarda un grupo de settings del chatbot (merge).' ),
				array( 'fn' => 'handle_save_caps_matrix', 'file' => 'Admin.php', 'desc' => 'Handler admin_post para guardar la matriz de capacidades.' ),
				array( 'fn' => 'handle_save_capabilities', 'file' => 'Admin.php', 'desc' => 'Handler admin_post para guardar capacidades por producto.' ),
				array( 'fn' => 'chatbot_list|summary|leads|logs', 'file' => 'Cli.php', 'desc' => 'Lista/resumen/leads/logs del chatbot.' ),
				array( 'fn' => 'popups_list|create|update|delete|settings', 'file' => 'Cli.php', 'desc' => 'CRUD de popups v2.' ),
				array( 'fn' => 'handle_save_popup', 'file' => 'Admin.php', 'desc' => 'Handler admin_post para crear/actualizar popup v2.' ),
				array( 'fn' => 'handle_delete_popup', 'file' => 'Admin.php', 'desc' => 'Handler admin_post para eliminar popup v2.' ),
				array( 'fn' => 'leads_list|summary', 'file' => 'Cli.php', 'desc' => 'Lista/resumen de leads legados.' ),
			),
			'UI (interfaz de administración)' => array(
				array( 'fn' => 'menu', 'file' => 'Admin.php', 'desc' => 'Registra el submenú Capture bajo Estandar Suite.' ),
				array( 'fn' => 'render', 'file' => 'Admin.php', 'desc' => 'Renderiza la página de Capture con sus 4 tabs.' ),
				array( 'fn' => 'feature_data', 'file' => 'Admin.php', 'desc' => 'Datos de los sub-tabs forms/chatbot/popups/leads (read-only legado).' ),
				array( 'fn' => 'handle_save_feature', 'file' => 'Admin.php', 'desc' => 'Handler admin_post para guardar settings de features.' ),
				array( 'fn' => 'handle_save_form', 'file' => 'Admin.php', 'desc' => 'Handler admin_post para crear/actualizar un form v2.' ),
				array( 'fn' => 'handle_delete_form', 'file' => 'Admin.php', 'desc' => 'Handler admin_post para eliminar un form v2.' ),
				array( 'fn' => 'handle_import', 'file' => 'Admin.php', 'desc' => 'Handler admin_post para import de adopción.' ),
				array( 'fn' => 'handle_rollback', 'file' => 'Admin.php', 'desc' => 'Handler admin_post para rollback de adopción.' ),
			),
			'Integración legada (solo lectura)' => array(
				array( 'fn' => 'feature_fields', 'file' => 'Admin.php', 'desc' => 'Descriptor de settings de cada feature.' ),
				array( 'fn' => 'legacy_table', 'file' => 'Admin.php', 'desc' => 'Verifica que exista una tabla legada (delega en Core).' ),
				array( 'fn' => 'legacy_rows', 'file' => 'Admin.php', 'desc' => 'Lee filas de una tabla legada (read-only).' ),
				array( 'fn' => 'legacy_count', 'file' => 'Admin.php', 'desc' => 'Cuenta filas de una tabla legada (read-only).' ),
			),
		);

		include ESTANDAR_SUITE_CAPTURE_PATH . 'includes/templates/capture-admin.php';
	}

	/** Public accessor for the settings descriptors (used by templates). */
	public static function get_settings(): array
	{
		return array(
			array( 'name' => 'estandar_suite_capture_schema_version', 'label' => 'Versión de esquema', 'desc' => 'Versión actual de la tabla estandar_capture_submissions.', 'editable' => false, 'legacy' => false ),
		);
	}

	public static function handle_import(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_adoption_import' );
		$commit = ! empty( $_POST['commit'] );
		$limit  = (int) ( $_POST['limit'] ?? 100 );
		$r = Adoption::import( ! $commit, max( 1, min( 500, $limit ) ) );
		if ( class_exists( 'Estandar\\Core\\Telemetry' ) ) {
			\Estandar\Core\Telemetry::emit( $commit ? 'info' : 'debug', 'CAPTURE_ADOPTION', $commit ? 'Capture adoption committed.' : 'Capture adoption dry-run.', array( 'source_plugin' => 'estandar-capture', 'imported' => $r['imported'], 'dry_run' => ! $commit ) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&imported=' . (int) $r['imported'] ) );
		exit;
	}

	public static function handle_rollback(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_adoption_rollback' );
		$n = Adoption::rollback_import();
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&rolled=' . (int) $n ) );
		exit;
	}

	// ── Forms adoption (entries / survey responses): import + rollback ─────

	public static function handle_forms_entries_import(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_forms_entries_import' );
		$commit = ! empty( $_POST['commit'] );
		$limit  = (int) ( $_POST['limit'] ?? 100 );
		$r = FormsAdoption::import_entries( ! $commit, max( 1, min( 500, $limit ) ) );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&tab=datos&sub=adopcion&forms_imported=' . (int) $r['imported'] ) );
		exit;
	}

	public static function handle_forms_entries_rollback(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_forms_entries_rollback' );
		$n = FormsAdoption::rollback_entries();
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&tab=datos&sub=adopcion&forms_rolled=' . (int) $n ) );
		exit;
	}

	public static function handle_forms_responses_import(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_forms_responses_import' );
		$commit = ! empty( $_POST['commit'] );
		$limit  = (int) ( $_POST['limit'] ?? 100 );
		$r = FormsAdoption::import_responses( ! $commit, max( 1, min( 500, $limit ) ) );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&tab=datos&sub=adopcion&forms_responses_imported=' . (int) $r['imported'] ) );
		exit;
	}

	public static function handle_forms_responses_rollback(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_forms_responses_rollback' );
		$n = FormsAdoption::rollback_responses();
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&tab=datos&sub=adopcion&forms_responses_rolled=' . (int) $n ) );
		exit;
	}

	/** Read-only status for the Forms adoption panel (origin/mode/usage). */
	public static function forms_adoption_summary(): array
	{
		return FormsAdoption::resource_counts();
	}

	/** Duplicar un formulario nativo (copia íntegra; el original no se toca). */
	public static function handle_duplicate_form(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_duplicate_form' );
		$form_id = (int) ( $_POST['form_id'] ?? 0 );
		$form    = \Estandar\Capture\Forms::get( $form_id );
		if ( ! $form ) {
			wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=forms&ss=formularios&form_duplicated=0' ) );
			exit;
		}
		$name   = sanitize_text_field( (string) $form['name'] ) . ' (Copia)';
		$status = in_array( (string) ( $form['status'] ?? 'active' ), array( 'active', 'draft', 'archived' ), true )
			? (string) $form['status']
			: 'draft';
		$new_id = \Estandar\Capture\Forms::create( array(
			'name'         => $name,
			'slug'         => '',
			'form_type'    => (string) $form['form_type'],
			'form_context' => (string) $form['form_context'],
			'config_json'  => (string) $form['config_json'],
			'status'       => $status,
		) );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=forms&ss=formularios&form_duplicated=' . ( $new_id > 0 ? 1 : 0 ) ) );
		exit;
	}

	/** Migrar un formulario legacy a nativo (dry-run por defecto). */
	public static function handle_migrate_form(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_migrate_form' );
		$form_id = (int) ( $_POST['form_id'] ?? 0 );		$commit  = ! empty( $_POST['commit'] );
		$r       = Forms::migrate_legacy( ! $commit, $form_id, true );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=forms&migrated=' . (int) $r['migrated'] . '&migrated_commit=' . ( $commit ? 1 : 0 ) . '&migrated_existing=' . (int) $r['existing'] ) );
		exit;
	}

	/** Rollback de migración (solo nativos migrados/adoptados). */
	public static function handle_migrate_rollback(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_migrate_rollback' );
		$form_id = (int) ( $_POST['form_id'] ?? 0 );
		$n       = Forms::rollback_migration( $form_id );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=forms&migrated_rollback=' . (int) $n ) );
		exit;
	}

	/**
	 * Vista read-only de la configuración heredada de Estandar Forms.
	 * Muestra por formulario legacy: origen, estado, modo, utilización y
	 * config sensible enmascarada. Nunca edita, nunca duplica configuración.
	 */
	public static function render_legacy_forms_config(): void
	{
		$rows = \Estandar\Core\Admin::legacy_rows( 'estandar_forms', 'ORDER BY id ASC LIMIT 200' );
		if ( ! $rows ) {
			echo '<p class="description">Sin formularios legacy (tabla <code>estandar_forms</code> no presente o vacía).</p>';
			return;
		}
		global $wpdb;
		$mask = static function ( string $key, $value ) {
			if ( $value === '' || $value === null ) {
				return '—';
			}
			if ( preg_match( '/(token|secret|password|private_?key|api[_-]?key|client_?secret)/i', $key ) ) {
				return '•••••• (oculto)';
			}
			$s = (string) $value;
			return strlen( $s ) > 120 ? substr( $s, 0, 120 ) . '…' : $s;
		};
		echo '<div class="postbox" style="max-width:100%;margin-top:18px;">';
		echo '<div class="postbox-header"><h2 class="hndle" style="padding:8px 12px;margin:0;font-size:14px;">Configuración heredada — Estandar Forms (solo lectura)</h2></div>';
		echo '<div class="inside"><p class="description">Lee <code>estandar_forms</code> directamente. No se edita aquí; para administrar un formulario como nativo, mígralo a la Suite v2 (el original legacy queda intacto).</p>';

		if ( isset( $_GET['migrated'] ) ) {
			$commit = ! empty( $_GET['migrated_commit'] );
			$n      = (int) $_GET['migrated'];
			echo '<div class="notice notice-' . ( $commit ? 'success' : 'info' ) . ' is-dismissible"><p>Migración ' . ( $commit ? 'aplicada: ' : 'previsualizada: ' ) . '<strong>' . $n . '</strong> formulario(s). Ya existentes: <strong>' . (int) ( $_GET['migrated_existing'] ?? 0 ) . '</strong>. El legacy no se tocó.</p></div>';
		}
		if ( isset( $_GET['migrated_rollback'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>Rollback: <strong>' . (int) $_GET['migrated_rollback'] . '</strong> formulario(s) nativo(s) migrado(s) eliminado(s).</p></div>';
		}

		echo '<table class="widefat striped"><thead><tr><th>Origen</th><th>Estado</th><th>Modo</th><th>Utilización</th><th>Config (lectura)</th><th>Acciones</th></tr></thead><tbody>';
		foreach ( $rows as $f ) {
			$fid      = (int) $f['id'];
			$util     = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'estandar_form_entries WHERE form_id = %d', $fid ) );
			$cfg_bits = array();
			foreach ( array( 'notify_emails' => 'notify', 'webhook_url' => 'webhook', 'send_email' => 'send_email', 'send_webhook' => 'send_webhook', 'require_recaptcha' => 'recaptcha', 'store_entries' => 'store_entries', 'success_message' => 'success' ) as $col => $label ) {
				if ( isset( $f[ $col ] ) && $f[ $col ] !== '' && $f[ $col ] !== 'null' ) {
					$cfg_bits[] = '<code>' . esc_html( $label ) . '=' . esc_html( $mask( $col, $f[ $col ] ) ) . '</code>';
				}
			}
			$mode = (string) ( $f['form_type'] ?? 'lead' );
			if ( ! empty( $f['request_type'] ) && $f['request_type'] !== 'null' ) {
				$mode .= ' / ' . (string) $f['request_type'];
			}
			$migrated  = Forms::is_migrated( $fid );
			$migratable = Forms::legacy_form_type_map( (string) ( $f['form_type'] ?? '' ) ) !== '';
			echo '<tr>'
				. '<td><code>estandar_forms#' . (int) $fid . '</code> ' . esc_html( (string) ( $f['name'] ?? '' ) ) . '</td>'
				. '<td>' . ( $migrated ? '<span style="color:#2271b1;">migrado a nativo</span>' : 'activo' ) . '</td>'
				. '<td>' . esc_html( $mode ) . '</td>'
				. '<td>' . (int) $util . ' entries</td>'
				. '<td>' . ( $cfg_bits ? implode( ' ', $cfg_bits ) : '—' ) . '</td>'
				. '<td>' . ( $migratable
					? '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">
							' . wp_nonce_field( 'estandar_suite_capture_migrate_form', '_wpnonce', true, false ) . '
							<input type="hidden" name="action" value="estandar_suite_capture_migrate_form">
							<input type="hidden" name="form_id" value="' . $fid . '">
							<button class="button button-small" type="submit">' . ( $migrated ? 'Re-migrar' : 'Migrar a nativo' ) . '</button>
						</form>
						<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">
							' . wp_nonce_field( 'estandar_suite_capture_migrate_form', '_wpnonce', true, false ) . '
							<input type="hidden" name="action" value="estandar_suite_capture_migrate_form">
							<input type="hidden" name="form_id" value="' . $fid . '">
							<input type="hidden" name="commit" value="1">
							<button class="button button-small button-primary" type="submit">' . ( $migrated ? 'Re-migrar (commit)' : 'Migrar y aplicar' ) . '</button>
						</form>'
					: '<span class="description">fuera de alcance (survey/postventa)</span>' ) . '</td>'
				. '</tr>';
		}
		echo '</tbody></table>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:10px;" onsubmit="return confirm(\'¿Eliminar los formularios nativos migrados? El legacy no se toca.\')">'
			. wp_nonce_field( 'estandar_suite_capture_migrate_rollback', '_wpnonce', true, false )
			. '<input type="hidden" name="action" value="estandar_suite_capture_migrate_rollback">'
			. '<button class="button" type="submit">Rollback de migración (solo nativos migrados)</button>'
			. '</form>';
		echo '</div></div>';
	}

	/** UI del puente provisional GF→Capture en el sub-tab Postventa.
	 *  GF_BRIDGE_VISIBILITY_V1: sin Gravity Forms instalado no hay entries que
	 *  enganchar y el bloque "inactivo" solo ensucia el admin → no se renderiza.
	 *  El código del puente y los renderers del contrato (contract_from_capture,
	 *  all_fields_table) se conservan: los usa el form nativo de postventa
	 *  (mail_contract='gf_postventa') aunque GF no esté presente. */
	public static function render_gf_postventa_bridge(): void
	{
		$gf_active = class_exists( 'GFAPI' );
		if ( ! GfPostventaBridge::ui_visible( $gf_active ) ) {
			return;
		}
		$s = GfPostventaBridge::settings();
		$panel_url = home_url( '/' . PostventaPanel::PANEL_SLUG . '/' );
		?>
		<hr style="margin:26px 0;border:none;border-top:1px solid #e2e4e7;">
		<h3 style="display:flex;align-items:center;gap:8px;">Puente provisional Gravity Forms → Capture
			<?php if ( ! empty( $s['enabled'] ) ) : ?><span style="background:#d6f0d2;color:#007017;border-radius:10px;padding:1px 10px;font-size:11px;">ACTIVO</span>
			<?php else : ?><span style="background:#f0f0f1;color:#646970;border-radius:10px;padding:1px 10px;font-size:11px;">inactivo</span><?php endif; ?>
		</h3>
		<p class="description">Garantiza durante la convivencia que cada entry del form GF de postventa se <strong>registre</strong> en <code>estandar_capture_submissions</code>, se <strong>publique</strong> en el Panel Postventa web y se <strong>envíe el correo</strong> con el contrato GF (tabla <code>Label: Value</code>, subject «Solicitud PostVenta {Proyecto} desde la web Empresa Demo») desde el remitente configurado. Si <code>disable_gf_notify</code> está activo, se desactiva la notificación admin de GF para evitar el doble correo.</p>
		<?php if ( isset( $_GET['gf_bridge_saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p>Puente guardado.</p></div><?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'estandar_suite_capture_gf_postventa_bridge' ); ?>
			<input type="hidden" name="action" value="estandar_suite_capture_save_gf_postventa_bridge">
			<table class="widefat striped" style="max-width:860px;">
				<tbody>
					<tr><td style="width:240px;"><strong>Puente activo</strong></td><td><label><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $s['enabled'] ) ); ?>> Enviar y registrar las submissions GF de postventa</label></td></tr>
					<tr><td><strong>Forms GF (ids)</strong></td><td><input type="text" name="form_ids" value="<?php echo esc_attr( (string) $s['form_ids'] ); ?>" class="regular-text" placeholder="2,14"></td></tr>
					<tr><td><strong>Destinatarios (admin)</strong></td><td><input type="text" name="notify_to" value="<?php echo esc_attr( (string) $s['notify_to'] ); ?>" class="regular-text" style="width:100%;"></td></tr>
					<tr><td><strong>Remitente</strong></td><td><input type="email" name="from_email" value="<?php echo esc_attr( (string) $s['from_email'] ); ?>" class="regular-text"></td></tr>
					<tr><td><strong>Nombre del remitente</strong></td><td><input type="text" name="from_name" value="<?php echo esc_attr( (string) $s['from_name'] ); ?>" class="regular-text"></td></tr>
					<tr><td><strong>Responder a</strong></td><td><input type="email" name="reply_to" value="<?php echo esc_attr( (string) $s['reply_to'] ); ?>" class="regular-text"></td></tr>
					<tr><td><strong>Asunto</strong></td><td><input type="text" name="subject_template" value="<?php echo esc_attr( (string) $s['subject_template'] ); ?>" class="regular-text" style="width:100%;"></td></tr>
					<tr><td><strong>Intro del cuerpo</strong></td><td><textarea name="intro_html" rows="5" class="large-text"><?php echo esc_textarea( (string) $s['intro_html'] ); ?></textarea></td></tr>
					<tr><td><strong>Desactivar notificación GF</strong></td><td><label><input type="checkbox" name="disable_gf_notify" value="1" <?php checked( ! empty( $s['disable_gf_notify'] ) ); ?>> Evitar el doble correo (desactiva la notificación admin del form GF)</label></td></tr>
					<tr><td><strong>Panel Postventa</strong></td><td><a href="<?php echo esc_url( $panel_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $panel_url ); ?></a></td></tr>
				</tbody>
			</table>
			<p style="margin-top:12px;"><?php submit_button( 'Guardar puente', 'primary', 'save_gf_bridge', false ); ?></p>
		</form>
		<?php
	}

	/** admin_post handler: guarda la config del puente GF→Capture. */
	public static function handle_save_gf_postventa_bridge(): void
	{
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Sin permisos.' );
		}
		check_admin_referer( 'estandar_suite_capture_gf_postventa_bridge' );
		GfPostventaBridge::save_settings( $_POST );
		do_action( 'estandar_suite_capture_gf_postventa_bridge_synced' );
		wp_safe_redirect( admin_url( 'admin.php?page=estandar-suite-capture&tab=config&sub=postventa&ss=formularios&gf_bridge_saved=1' ) );
		exit;
	}

	/** Catálogo de sub-sub-tabs (ss=) del módulo Chatbot (fuente única, compartida
	 *  por el template del feature y el manager de visibilidad). */
	public static function chatbot_ssubs(): array
	{
		// CHATBOT_TABS_V1 (2026-09-21): agrupadas por naturaleza y ordenadas.
		// Las "avanzadas" (al final) se ocultan por defecto en la pantalla; siguen
		// activas y se muestran desde Core → Visibilidad.
		return array(
			// Configuración (día a día)
			'resumen'       => 'Resumen',
			'conversacion'  => 'Conversación',
			'mensajes'      => 'Mensajes',
			'captacion'     => 'Captación de leads',
			'ia'            => 'IA y conocimiento',
			'apariencia'    => 'Apariencia',
			// Actividad
			'conversaciones'=> 'Conversaciones',
			// Avanzado / técnico (ocultas por defecto)
			'sugerencias'   => 'Sugerencias',
			'integraciones' => 'Integraciones',
			'fusion'        => 'Fusión con la Suite',
			'sistema'       => 'Sistema',
			'chat'          => 'Chat (agente público)',
		);
	}

	/** Catálogo de sub-sub-tabs (ss=) del módulo Popups. */
	public static function popups_ssubs(): array
	{
		return array( 'resumen' => 'Resumen', 'popups' => 'Popups', 'tracking' => 'Tracking', 'configuracion' => 'Configuración', 'settings' => 'Settings' );
	}

	/** Catálogo de sub-sub-tabs (ss=) del módulo Leads. */
	public static function leads_ssubs(): array
	{
		return array(
			'resumen' => 'Resumen', 'leads' => 'Leads', 'chatbot' => 'Chatbot', 'reportes' => 'Reportes', 'email' => 'Email',
			'autorespuesta' => 'Autorespuesta', 'exportaciones' => 'Exportaciones', 'clientes' => 'Clientes',
			'operadores' => 'Operadores', 'configuracion' => 'Configuración', 'settings' => 'Settings',
		);
	}
}
