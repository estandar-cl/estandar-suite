<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Estandar_Signal_Core_Admin {

	const PAGE_SLUG = 'estandar-signal-core-settings';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_menus' ) );
		add_action( 'admin_post_estandar_signal_core_save_settings', array( __CLASS__, 'save_settings' ) );
		add_filter( 'admin_title', array( __CLASS__, 'fix_admin_title' ), 10, 2 );
	}

	public static function register_menus(): void {
		// Attach under estandar-leads if it exists, otherwise create standalone.
		$parent = menu_page_url( 'estandar-leads', false ) !== '' ? 'estandar-leads' : null;

		add_submenu_page(
			$parent,
			'Signal Core — Configuración',
			'Signal Core',
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_settings' )
		);
	}

	public static function render_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No autorizado.' );
		}
		$tpl = ESTANDAR_SIGNAL_CORE_PATH . 'templates/admin/settings.php';
		if ( file_exists( $tpl ) ) {
			include $tpl;
		}
	}

	public static function save_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No autorizado.' );
		}
		check_admin_referer( 'estandar_signal_core_save_settings' );

		// ── client_id ──────────────────────────────────────────────────────────────
		$client_id = sanitize_title( (string) ( $_POST['signal_client_id'] ?? '' ) );
		if ( $client_id !== '' ) {
			update_option( 'estandar_signal_core_client_id', $client_id, false );
		}

		// ── Meta CAPI ─────────────────────────────────────────────────────────────
		$pixel_id = sanitize_text_field( (string) ( $_POST['meta_pixel_id'] ?? '' ) );
		update_option( 'estandar_signal_core_meta_pixel_id', $pixel_id, false );

		// Access token: only update if a new value was submitted (non-empty).
		$access_token = trim( (string) ( $_POST['meta_access_token'] ?? '' ) );
		if ( $access_token !== '' ) {
			update_option( 'estandar_signal_core_meta_access_token', $access_token, false );
		}

		$test_code = sanitize_text_field( (string) ( $_POST['meta_test_event_code'] ?? '' ) );
		update_option( 'estandar_signal_core_meta_test_event_code', $test_code, false );

		// ── Google Ads OCI ────────────────────────────────────────────────────────
		$g_developer_token = sanitize_text_field( (string) ( $_POST['google_developer_token'] ?? '' ) );
		update_option( 'estandar_signal_core_google_developer_token', $g_developer_token, false );

		$g_client_id = sanitize_text_field( (string) ( $_POST['google_client_id'] ?? '' ) );
		update_option( 'estandar_signal_core_google_client_id', $g_client_id, false );

		$g_customer_id = preg_replace( '/[^0-9]/', '', (string) ( $_POST['google_customer_id'] ?? '' ) );
		update_option( 'estandar_signal_core_google_customer_id', $g_customer_id, false );

		// Secrets: only update if non-empty.
		$g_client_secret = trim( (string) ( $_POST['google_client_secret'] ?? '' ) );
		if ( $g_client_secret !== '' ) {
			update_option( 'estandar_signal_core_google_client_secret', $g_client_secret, false );
		}

		$g_refresh_token = trim( (string) ( $_POST['google_refresh_token'] ?? '' ) );
		if ( $g_refresh_token !== '' ) {
			update_option( 'estandar_signal_core_google_refresh_token', $g_refresh_token, false );
			// Invalidate cached access token so next call uses new credentials.
			delete_transient( 'estandar_signal_core_google_access_token' );
		}

		// Conversion action resource names.
		$conv_fields = array(
			'google_conv_lead_created'   => 'estandar_signal_core_google_conv_lead_created',
			'google_conv_meeting_booked' => 'estandar_signal_core_google_conv_meeting_booked',
			'google_conv_sale_closed'    => 'estandar_signal_core_google_conv_sale_closed',
		);
		foreach ( $conv_fields as $post_key => $option_key ) {
			$val = sanitize_text_field( (string) ( $_POST[ $post_key ] ?? '' ) );
			update_option( $option_key, $val, false );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&saved=1' ) );
		exit;
	}

	public static function fix_admin_title( string $admin_title, string $title ): string {
		$page = sanitize_key( (string) ( $_GET['page'] ?? '' ) );
		if ( $page === self::PAGE_SLUG ) {
			return 'Signal Core — Configuración &lsaquo; ' . get_bloginfo( 'name' );
		}
		return $admin_title;
	}
}
