<?php
/**
 * Estandar KickOff - Progressive Reveal.
 *
 * @package Estandar_KickOff
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ESTANDAR_PR_VERSION', '13.0.0' );
define( 'ESTANDAR_PR_MODULE_DIR', plugin_dir_path( __FILE__ ) );
define( 'ESTANDAR_PR_ASSETS_URL', plugin_dir_url( __FILE__ ) . 'assets/' );
define( 'ESTANDAR_PR_PAGE_SLUG', 'estandar-pr-progressive-reveal' );
define( 'ESTANDAR_PR_PREVIEW_PARAM', 'estandar_pr_preview' );
define( 'ESTANDAR_PR_COOKIE_NAME', 'estandar_pr_preview' );
define( 'ESTANDAR_PR_NONCE_SAVE', 'estandar_pr_save' );
define( 'ESTANDAR_PR_NONCE_REGEN', 'estandar_pr_regen' );

add_action( 'admin_enqueue_scripts', 'estandar_pr_enqueue_admin_assets' );
add_action( 'admin_post_estandar_pr_save', 'estandar_pr_handle_save' );
add_action( 'admin_post_estandar_pr_regen_token', 'estandar_pr_handle_regen_token' );
add_action( 'template_redirect', 'estandar_pr_intercept_request', 1 );
add_filter( 'estandar_kickoff_tabs', 'estandar_pr_register_kickoff_tab' );

function estandar_pr_enqueue_admin_assets( $hook ) {
	$current_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

	if ( false === strpos( (string) $hook, ESTANDAR_PR_PAGE_SLUG ) && ESTANDAR_PR_PAGE_SLUG !== $current_tab ) {
		return;
	}

	wp_enqueue_style(
		'estandar-pr-admin',
		ESTANDAR_PR_ASSETS_URL . 'admin.css',
		[],
		ESTANDAR_PR_VERSION
	);
}

function estandar_pr_register_kickoff_tab( $tabs ) {
	$tabs[ ESTANDAR_PR_PAGE_SLUG ] = [
		'slug'   => ESTANDAR_PR_PAGE_SLUG,
		'label'  => __( 'Progressive Reveal', 'estandar-kickoff' ),
		'icon'   => '▦',
		'order'  => 75,
		'render' => 'estandar_pr_render_admin_page',
	];

	return $tabs;
}

function estandar_pr_render_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'No tienes permisos para acceder.', 'estandar-kickoff' ) );
	}

	// submit_button() vive en template.php que no siempre está cargado (ej. admin-post.php).
	if ( ! function_exists( 'submit_button' ) ) {
		require_once ABSPATH . 'wp-admin/includes/template.php';
	}

	$settings = estandar_pr_get_settings();
	$pages    = estandar_pr_get_published_pages();
	$progress = estandar_pr_get_progress();

	include ESTANDAR_PR_MODULE_DIR . 'views/admin-settings.php';
}

function estandar_pr_get_settings() {
	return [
		'active'           => (int) get_option( 'estandar_pr_active', 0 ),
		'allow_editors'    => (int) get_option( 'estandar_pr_allow_editors', 0 ),
		'whitelist_ips'    => (string) get_option( 'estandar_pr_whitelist_ips', '' ),
		'preview_token'    => estandar_pr_get_preview_token(),
		'token_expiry'     => (int) get_option( 'estandar_pr_token_expiry', 0 ),
		'released_pages'   => estandar_pr_get_released_page_ids(),
		'title'            => (string) get_option( 'estandar_pr_title', __( 'Estamos preparando una nueva experiencia', 'estandar-kickoff' ) ),
		'subtitle'         => (string) get_option( 'estandar_pr_subtitle', __( 'Algunas secciones ya están disponibles mientras completamos el despliegue.', 'estandar-kickoff' ) ),
		'redirect_page_id' => (int) get_option( 'estandar_pr_redirect_page_id', 0 ),
		'redirect_delay'   => max( 1, (int) get_option( 'estandar_pr_redirect_delay', 10 ) ),
		'track_analytics'  => (int) get_option( 'estandar_pr_track_analytics', 0 ),
	];
}

function estandar_pr_get_preview_token() {
	$token = (string) get_option( 'estandar_pr_preview_token', '' );
	if ( '' !== $token ) {
		return $token;
	}

	$token = estandar_pr_generate_token();
	update_option( 'estandar_pr_preview_token', $token, false );
	update_option( 'estandar_pr_token_expiry', time() + WEEK_IN_SECONDS, false );

	return $token;
}

function estandar_pr_generate_token() {
	try {
		return bin2hex( random_bytes( 16 ) );
	} catch ( Exception $e ) {
		return wp_generate_password( 32, false, false );
	}
}

function estandar_pr_handle_save() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'No tienes permisos para guardar.', 'estandar-kickoff' ) );
	}

	check_admin_referer( ESTANDAR_PR_NONCE_SAVE );

	update_option( 'estandar_pr_active', isset( $_POST['estandar_pr_active'] ) ? 1 : 0, 'yes' );
	update_option( 'estandar_pr_allow_editors', isset( $_POST['estandar_pr_allow_editors'] ) ? 1 : 0, 'yes' );
	update_option( 'estandar_pr_whitelist_ips', estandar_pr_sanitize_whitelist_ips( wp_unslash( $_POST['estandar_pr_whitelist_ips'] ?? '' ) ), false );
	update_option( 'estandar_pr_token_expiry', estandar_pr_sanitize_token_expiry( wp_unslash( $_POST['estandar_pr_token_expiry'] ?? '' ) ), false );
	update_option( 'estandar_pr_released_pages', estandar_pr_sanitize_page_ids( wp_unslash( $_POST['estandar_pr_released_pages'] ?? [] ) ), false );
	update_option( 'estandar_pr_title', sanitize_text_field( wp_unslash( $_POST['estandar_pr_title'] ?? '' ) ), false );
	update_option( 'estandar_pr_subtitle', sanitize_textarea_field( wp_unslash( $_POST['estandar_pr_subtitle'] ?? '' ) ), false );
	update_option( 'estandar_pr_redirect_page_id', absint( wp_unslash( $_POST['estandar_pr_redirect_page_id'] ?? 0 ) ), false );
	update_option( 'estandar_pr_redirect_delay', max( 1, (int) wp_unslash( $_POST['estandar_pr_redirect_delay'] ?? 10 ) ), false );
	update_option( 'estandar_pr_track_analytics', isset( $_POST['estandar_pr_track_analytics'] ) ? 1 : 0, 'yes' );

	wp_safe_redirect( add_query_arg( 'estandar_pr_saved', '1', estandar_pr_admin_url() ) );
	exit;
}

function estandar_pr_handle_regen_token() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'No tienes permisos para regenerar.', 'estandar-kickoff' ) );
	}

	check_admin_referer( ESTANDAR_PR_NONCE_REGEN );

	update_option( 'estandar_pr_preview_token', estandar_pr_generate_token(), false );

	wp_safe_redirect( add_query_arg( 'estandar_pr_regenerated', '1', estandar_pr_admin_url() ) );
	exit;
}

function estandar_pr_admin_url() {
	return admin_url( 'admin.php?page=estandar-kickoff&tab=' . ESTANDAR_PR_PAGE_SLUG );
}

function estandar_pr_sanitize_whitelist_ips( $value ) {
	$lines = preg_split( '/[\r\n,]+/', (string) $value );
	$ips   = [];

	foreach ( $lines as $line ) {
		$ip = trim( $line );
		if ( '' !== $ip && filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			$ips[] = $ip;
		}
	}

	return implode( "\n", array_unique( $ips ) );
}

function estandar_pr_sanitize_token_expiry( $value ) {
	$value = trim( (string) $value );
	if ( '' === $value ) {
		return 0;
	}

	try {
		$date = new DateTimeImmutable( $value, wp_timezone() );
		return $date->getTimestamp();
	} catch ( Exception $e ) {
		return 0;
	}
}

function estandar_pr_sanitize_page_ids( $value ) {
	if ( ! is_array( $value ) ) {
		return [];
	}

	$published_ids = estandar_pr_get_published_page_ids();
	$ids           = array_map( 'absint', $value );
	$ids           = array_values( array_unique( array_filter( $ids ) ) );

	return array_values( array_intersect( $ids, $published_ids ) );
}

function estandar_pr_get_released_page_ids() {
	$ids = get_option( 'estandar_pr_released_pages', [] );
	if ( ! is_array( $ids ) ) {
		return [];
	}

	return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
}

function estandar_pr_get_published_pages() {
	return get_posts(
		[
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'orderby'        => [
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			],
			'posts_per_page' => -1,
			'no_found_rows'  => true,
		]
	);
}

function estandar_pr_get_published_page_ids() {
	return get_posts(
		[
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
		]
	);
}

function estandar_pr_get_progress() {
	$all_ids      = estandar_pr_get_published_page_ids();
	$released_ids = array_intersect( estandar_pr_get_released_page_ids(), $all_ids );
	$total        = count( $all_ids );

	return [
		'total'    => $total,
		'released' => count( $released_ids ),
		'percent'  => $total > 0 ? (int) floor( ( count( $released_ids ) / $total ) * 100 ) : 0,
	];
}

function estandar_pr_intercept_request() {
	if ( ! (int) get_option( 'estandar_pr_active', 0 ) ) {
		return;
	}

	if ( estandar_pr_is_hard_excluded() ) {
		return;
	}

	if ( estandar_pr_is_admin_bypass() ) {
		return;
	}

	if ( estandar_pr_is_editor_bypass() ) {
		return;
	}

	if ( estandar_pr_is_ip_bypass() ) {
		return;
	}

	if ( estandar_pr_is_preview_bypass() ) {
		return;
	}

	if ( estandar_pr_is_released_request() ) {
		return;
	}

	estandar_pr_render_maintenance();
}

function estandar_pr_is_hard_excluded() {
	$path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '';
	$path = '/' . ltrim( (string) $path, '/' );

	if ( isset( $_GET['wc-ajax'] ) ) {
		return true;
	}

	foreach ( [ '/wp-admin', '/wp-login.php', '/wp-cron.php', '/wp-json', '/xmlrpc.php', '/wp-comments-post.php' ] as $excluded ) {
		if ( 0 === strpos( $path, $excluded ) ) {
			return true;
		}
	}

	if ( function_exists( 'is_cart' ) && is_cart() ) {
		return true;
	}

	if ( function_exists( 'is_checkout' ) && is_checkout() ) {
		return true;
	}

	return function_exists( 'is_account_page' ) && is_account_page();
}

function estandar_pr_is_admin_bypass() {
	return is_user_logged_in() && current_user_can( 'manage_options' );
}

function estandar_pr_is_editor_bypass() {
	return (int) get_option( 'estandar_pr_allow_editors', 0 ) && is_user_logged_in() && current_user_can( 'edit_pages' );
}

function estandar_pr_is_ip_bypass() {
	$remote_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	if ( '' === $remote_ip ) {
		return false;
	}

	$ips = preg_split( '/[\r\n,]+/', (string) get_option( 'estandar_pr_whitelist_ips', '' ) );
	return in_array( $remote_ip, array_map( 'trim', $ips ), true );
}

function estandar_pr_is_preview_bypass() {
	$token  = (string) get_option( 'estandar_pr_preview_token', '' );
	$expiry = (int) get_option( 'estandar_pr_token_expiry', 0 );

	if ( '' === $token || ( $expiry > 0 && $expiry < time() ) ) {
		return false;
	}

	$get_token = isset( $_GET[ ESTANDAR_PR_PREVIEW_PARAM ] ) ? sanitize_text_field( wp_unslash( $_GET[ ESTANDAR_PR_PREVIEW_PARAM ] ) ) : '';
	if ( '' !== $get_token && hash_equals( $token, $get_token ) ) {
		estandar_pr_set_preview_cookie( $token, $expiry );
		estandar_pr_redirect_clean_preview_url();
	}

	$cookie_token = isset( $_COOKIE[ ESTANDAR_PR_COOKIE_NAME ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ ESTANDAR_PR_COOKIE_NAME ] ) ) : '';
	return '' !== $cookie_token && hash_equals( $token, $cookie_token );
}

function estandar_pr_set_preview_cookie( $token, $expiry ) {
	setcookie(
		ESTANDAR_PR_COOKIE_NAME,
		$token,
		[
			'expires'  => $expiry,
			'path'     => COOKIEPATH ? COOKIEPATH : '/',
			'domain'   => COOKIE_DOMAIN,
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Strict',
		]
	);

	$_COOKIE[ ESTANDAR_PR_COOKIE_NAME ] = $token;
}

function estandar_pr_redirect_clean_preview_url() {
	$url = remove_query_arg( ESTANDAR_PR_PREVIEW_PARAM );
	wp_safe_redirect( $url );
	exit;
}

function estandar_pr_is_released_request() {
	$released_ids = estandar_pr_get_released_page_ids();

	if ( is_singular( 'page' ) ) {
		return in_array( (int) get_queried_object_id(), $released_ids, true );
	}

	if ( is_home() && ! is_front_page() ) {
		$posts_page_id = (int) get_option( 'page_for_posts', 0 );
		return $posts_page_id > 0 && in_array( $posts_page_id, $released_ids, true );
	}

	return false;
}

function estandar_pr_get_redirect_url( array $settings ) {
	$page_id = (int) ( $settings['redirect_page_id'] ?? 0 );
	if ( $page_id > 0 ) {
		$url = get_permalink( $page_id );
		if ( ! empty( $url ) ) {
			return $url;
		}
	}

	return home_url( '/' );
}

function estandar_pr_maybe_track_analytics( array $settings ) {
	if ( empty( $settings['track_analytics'] ) ) {
		return;
	}

	if ( ! class_exists( 'EA_Events' ) || ! class_exists( 'EA_Utils' ) ) {
		return;
	}

	$session_id = isset( $_COOKIE['ea_sid'] )
		? sanitize_text_field( wp_unslash( $_COOKIE['ea_sid'] ) )
		: '';

	if ( $session_id === '' ) {
		return;
	}

	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
	$page_url    = esc_url_raw( home_url( $request_uri ) );

	EA_Events::record_single(
		$session_id,
		[
			'type'     => 'pr_maintenance_view',
			'label'    => get_bloginfo( 'name' ),
			'page_url' => $page_url,
			'value'    => '',
		]
	);
}

function estandar_pr_render_maintenance() {
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}

	status_header( 200 );
	header( 'Content-Type: text/html; charset=UTF-8' );
	header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
	header( 'Pragma: no-cache' );
	header( 'X-Robots-Tag: noindex,nofollow' );

	$settings = estandar_pr_get_settings();
	$progress = estandar_pr_get_progress();
	$branding = estandar_pr_get_branding();

	estandar_pr_maybe_track_analytics( $settings );

	$redirect_url = estandar_pr_get_redirect_url( $settings );

	include ESTANDAR_PR_MODULE_DIR . 'views/maintenance.php';
	exit;
}

function estandar_pr_get_branding() {
	$logo_url       = '';
	$custom_logo_id = (int) get_theme_mod( 'custom_logo' );

	if ( $custom_logo_id ) {
		$image = wp_get_attachment_image_src( $custom_logo_id, [ 300, 120 ] );
		if ( ! empty( $image[0] ) ) {
			$logo_url = $image[0];
		}
	}

	if ( '' === $logo_url ) {
		$logo_url = get_site_icon_url( 128 );
	}

	return [
		'logo_url' => $logo_url,
		'initials' => estandar_pr_get_site_initials(),
	];
}

function estandar_pr_get_site_initials() {
	$name  = trim( wp_strip_all_tags( get_bloginfo( 'name' ) ) );
	$words = preg_split( '/\s+/', $name );
	$chars = '';

	foreach ( $words as $word ) {
		if ( '' === $word ) {
			continue;
		}
		$chars .= function_exists( 'mb_substr' ) ? mb_substr( $word, 0, 1 ) : substr( $word, 0, 1 );
		if ( strlen( $chars ) >= 2 ) {
			break;
		}
	}

	return strtoupper( $chars ? $chars : 'E' );
}
