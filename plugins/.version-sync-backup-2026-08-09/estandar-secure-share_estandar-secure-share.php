<?php
/*
Plugin Name: Estandar Secure Share
Plugin URI: https://www.estandar.cl
Description: Compartición segura de secretos de un solo uso con cifrado AES-256-GCM. Los secretos se destruyen tras el primer acceso.
Version: 13.0.172
Author: Luis Valdés de Estandar Studios CL
Author URI: https://www.estandar.cl
Text Domain: estandar-secure-share
Update URI: https://updates.estandar.cl
*/

if (!defined('ABSPATH')) exit;

define('ESTANDAR_SECURE_SHARE_VERSION', '13.0.158');
define('ESTANDAR_SECURE_SHARE_DIR', plugin_dir_path(__FILE__));
define('ESTANDAR_SECURE_SHARE_URL', plugin_dir_url(__FILE__));
define('ESTANDAR_SECURE_SHARE_SLUG', 'estandar-secure-share');
define('ESTANDAR_SECURE_SHARE_REWRITE_PREFIX', 'secure');

// ── Autoload ────────────────────────────────────────────────────────────────
$includes = glob(ESTANDAR_SECURE_SHARE_DIR . 'includes/class-*.php');
foreach ($includes as $file) {
    require_once $file;
}

// ── Activation / Deactivation ───────────────────────────────────────────────
register_activation_hook(__FILE__, function () {
    require_once ESTANDAR_SECURE_SHARE_DIR . 'includes/class-schema.php';
    Estandar_Secure_Share_Schema::create_tables();
    Estandar_Secure_Share_Schema::ensure_key_constant();
    flush_rewrite_rules();
    Estandar_Secure_Share_Schema::register_capabilities();
});

register_deactivation_hook(__FILE__, function () {
    flush_rewrite_rules();
    // No borrar datos en desactivación — solo en desinstalación
});

// ── Rewrite Rules ───────────────────────────────────────────────────────────
add_action('init', function () {
    add_rewrite_rule(
        '^' . ESTANDAR_SECURE_SHARE_REWRITE_PREFIX . '/([a-zA-Z0-9_-]+)/?$',
        'index.php?estandar_ss_token=$matches[1]',
        'top'
    );
    add_rewrite_tag('%estandar_ss_token%', '([a-zA-Z0-9_-]+)');
});

// ── Template Redirect ───────────────────────────────────────────────────────
add_action('template_redirect', function () {
    $token = get_query_var('estandar_ss_token');
    if (empty($token)) return;

    // Security headers
    nocache_headers();
    header('X-Robots-Tag: noindex, nofollow');
    header_quitar_terceros_safe('Content-Security-Policy', "default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; img-src 'self' data:;");

    $delivery = Estandar_Secure_Share_Delivery::find_by_token($token);

    if (!$delivery) {
        global $wp_query;
        $wp_query->set_404();
        status_header(404);
        include ESTANDAR_SECURE_SHARE_DIR . 'templates/public/not-found.php';
        exit;
    }

    $action = isset($_POST['estandar_ss_action']) ? sanitize_text_field(wp_unslash($_POST['estandar_ss_action'])) : 'view';

    if ($action === 'reveal' && $delivery['status'] === 'available') {
        if (!isset($_POST['estandar_ss_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['estandar_ss_nonce'])), 'estandar_ss_reveal_' . $token)) {
            wp_die('Error de seguridad. Recargue la página e intente de nuevo.');
        }

        $rate_check = Estandar_Secure_Share_Rate_Limiter::check('reveal');
        if (!$rate_check) {
            status_header(429);
            include ESTANDAR_SECURE_SHARE_DIR . 'templates/public/rate-limited.php';
            exit;
        }

        $secret = Estandar_Secure_Share_Delivery::consume($token);
        if ($secret === false) {
            include ESTANDAR_SECURE_SHARE_DIR . 'templates/public/expired.php';
            exit;
        }

        include ESTANDAR_SECURE_SHARE_DIR . 'templates/public/revealed.php';
        exit;
    }

    if ($action === 'request' && in_array($delivery['status'], ['consumed', 'expired', 'revoked'])) {
        if (!isset($_POST['estandar_ss_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['estandar_ss_nonce'])), 'estandar_ss_request_' . $token)) {
            wp_die('Error de seguridad. Recargue la página e intente de nuevo.');
        }

        $rate_check = Estandar_Secure_Share_Rate_Limiter::check('request');
        if (!$rate_check) {
            status_header(429);
            include ESTANDAR_SECURE_SHARE_DIR . 'templates/public/rate-limited.php';
            exit;
        }

        $email = isset($_POST['estandar_ss_email']) ? sanitize_email(wp_unslash($_POST['estandar_ss_email'])) : '';
        if (!empty($email) && is_email($email)) {
            $request_id = Estandar_Secure_Share_Delivery::create_request($delivery['id'], $email);
            if (class_exists('Estandar_Secure_Share_Email')) {
                Estandar_Secure_Share_Email::send_request_notification($email, $delivery, $request_id);
            }
        }
        include ESTANDAR_SECURE_SHARE_DIR . 'templates/public/requested.php';
        exit;
    }

    // Default view
    if ($delivery['status'] === 'available') {
        include ESTANDAR_SECURE_SHARE_DIR . 'templates/public/reveal-page.php';
    } else {
        include ESTANDAR_SECURE_SHARE_DIR . 'templates/public/expired.php';
    }
    exit;
});

// ── Shortcode (alternative to rewrite) ──────────────────────────────────────
add_shortcode('estandar_secure_reveal', function ($atts) {
    $atts = shortcode_atts(['token' => ''], $atts);
    if (empty($atts['token'])) return '<p>Token no proporcionado.</p>';

    nocache_headers();
    header('X-Robots-Tag: noindex, nofollow');

    $delivery = Estandar_Secure_Share_Delivery::find_by_token($atts['token']);
    if (!$delivery) return '<p>Enlace inválido o expirado.</p>';

    ob_start();
    if ($delivery['status'] === 'available') {
        include ESTANDAR_SECURE_SHARE_DIR . 'templates/public/reveal-page.php';
    } else {
        include ESTANDAR_SECURE_SHARE_DIR . 'templates/public/expired.php';
    }
    return ob_get_clean();
});

// ── Admin ────────────────────────────────────────────────────────────────────
add_action('admin_menu', function () {
    $parent = defined('ESTANDAR_CORE_MENU_SLUG') ? ESTANDAR_CORE_MENU_SLUG : '';
    if ($parent) {
        add_submenu_page(
            $parent,
            'Secure Share',
            'Secure Share',
            'estandar_secure_share_manage',
            'estandar-secure-share',
            'estandar_secure_share_admin_page'
        );
        add_submenu_page(
            $parent,
            'Secure Share — Configuración',
            'SS Configuración',
            'manage_options',
            'estandar-secure-share-settings',
            'estandar_secure_share_settings_page'
        );
        add_submenu_page(
            $parent,
            'Secure Share — Plantillas de correo',
            'SS Correos',
            'manage_options',
            'estandar-secure-share-emails',
            'estandar_secure_share_email_page'
        );
        return;
    }
    add_menu_page('Secure Share', 'Secure Share', 'estandar_secure_share_manage', 'estandar-secure-share', 'estandar_secure_share_admin_page', 'dashicons-lock', 59);
});

function estandar_secure_share_admin_page() {
    require_once ESTANDAR_SECURE_SHARE_DIR . 'admin/class-admin-deliveries.php';
    Estandar_Secure_Share_Admin_Deliveries::render();
}

function estandar_secure_share_settings_page() {
    require_once ESTANDAR_SECURE_SHARE_DIR . 'admin/class-admin-settings.php';
    Estandar_Secure_Share_Admin_Settings::render();
}

function estandar_secure_share_email_page() {
    require_once ESTANDAR_SECURE_SHARE_DIR . 'admin/class-admin-email-editor.php';
    Estandar_Secure_Share_Admin_Email_Editor::render();
}

// ── Admin Post Handlers ─────────────────────────────────────────────────────
add_action('admin_post_estandar_ss_create', function () {
    if (!current_user_can('estandar_secure_share_create')) wp_die('Acceso denegado.');
    check_admin_referer('estandar_ss_create');

    $secret = isset($_POST['estandar_ss_secret']) ? wp_unslash($_POST['estandar_ss_secret']) : '';
    $expires_hours = isset($_POST['estandar_ss_expires']) ? intval($_POST['estandar_ss_expires']) : 24;
    $requester_email = isset($_POST['estandar_ss_requester_email']) ? sanitize_email(wp_unslash($_POST['estandar_ss_requester_email'])) : '';

    $delivery = Estandar_Secure_Share_Delivery::create($secret, $expires_hours, $requester_email);
    if (is_wp_error($delivery)) {
        wp_die(esc_html($delivery->get_error_message()));
    }

    wp_redirect(add_query_arg([
        'page' => 'estandar-secure-share',
        'created' => '1',
        'token' => $delivery['token'],
        'id' => $delivery['id']
    ], admin_url('admin.php')));
    exit;
});

add_action('admin_post_estandar_ss_revoke', function () {
    if (!current_user_can('estandar_secure_share_manage')) wp_die('Acceso denegado.');
    check_admin_referer('estandar_ss_revoke');

    $delivery_id = isset($_POST['delivery_id']) ? intval($_POST['delivery_id']) : 0;
    Estandar_Secure_Share_Delivery::revoke($delivery_id);

    wp_redirect(add_query_arg([
        'page' => 'estandar-secure-share',
        'revoked' => '1'
    ], admin_url('admin.php')));
    exit;
});

add_action('admin_post_estandar_ss_fulfill', function () {
    if (!current_user_can('estandar_secure_share_create')) wp_die('Acceso denegado.');
    check_admin_referer('estandar_ss_fulfill');

    $request_id = isset($_POST['request_id']) ? intval($_POST['request_id']) : 0;
    $secret = isset($_POST['estandar_ss_secret']) ? wp_unslash($_POST['estandar_ss_secret']) : '';
    $expires_hours = isset($_POST['estandar_ss_expires']) ? intval($_POST['estandar_ss_expires']) : 24;

    Estandar_Secure_Share_Delivery::fulfill_request($request_id, $secret, $expires_hours);

    wp_redirect(add_query_arg([
        'page' => 'estandar-secure-share',
        'fulfilled' => '1'
    ], admin_url('admin.php')));
    exit;
});

add_action('admin_post_estandar_ss_settings', function () {
    if (!current_user_can('manage_options')) wp_die('Acceso denegado.');
    check_admin_referer('estandar_ss_settings');

    $settings = [
        'admin_email' => sanitize_email(wp_unslash($_POST['estandar_ss_admin_email'] ?? get_option('admin_email'))),
        'default_expires_hours' => intval($_POST['estandar_ss_default_expires'] ?? 24),
        'retention_days' => intval($_POST['estandar_ss_retention_days'] ?? 30),
        'brand_name' => sanitize_text_field(wp_unslash($_POST['estandar_ss_brand_name'] ?? 'ESTANDAR')),
        'brand_tagline' => sanitize_text_field(wp_unslash($_POST['estandar_ss_brand_tagline'] ?? 'Security Solutions')),
    ];
    update_option('estandar_secure_share_settings', $settings);

    wp_redirect(add_query_arg([
        'page' => 'estandar-secure-share-settings',
        'saved' => '1'
    ], admin_url('admin.php')));
    exit;
});

add_action('admin_post_estandar_ss_save_template', function () {
    if (!current_user_can('manage_options')) wp_die('Acceso denegado.');
    check_admin_referer('estandar_ss_save_template');

    $template_key = isset($_POST['template_key']) ? sanitize_key($_POST['template_key']) : '';
    $allowed = ['request_notification', 'request_confirmation', 'new_delivery', 'rejected'];
    if (!in_array($template_key, $allowed)) wp_die('Plantilla no válida.');

    $templates = get_option('estandar_secure_share_templates', []);
    $templates[$template_key] = [
        'subject' => sanitize_text_field(wp_unslash($_POST['subject'] ?? '')),
        'html' => wp_kses_post(wp_unslash($_POST['html_body'] ?? '')),
    ];
    update_option('estandar_secure_share_templates', $templates);

    wp_redirect(add_query_arg([
        'page' => 'estandar-secure-share-emails',
        'template' => $template_key,
        'saved' => '1'
    ], admin_url('admin.php')));
    exit;
});

add_action('admin_post_estandar_ss_test_email', function () {
    if (!current_user_can('manage_options')) wp_die('Acceso denegado.');
    check_admin_referer('estandar_ss_test_email');

    $template_key = isset($_POST['template_key']) ? sanitize_key($_POST['template_key']) : '';
    $test_email = isset($_POST['test_email']) ? sanitize_email(wp_unslash($_POST['test_email'])) : get_option('admin_email');

    if (class_exists('Estandar_Secure_Share_Email')) {
        Estandar_Secure_Share_Email::send_test($template_key, $test_email);
    }

    wp_redirect(add_query_arg([
        'page' => 'estandar-secure-share-emails',
        'template' => $template_key,
        'test_sent' => '1'
    ], admin_url('admin.php')));
    exit;
});

add_action('admin_post_estandar_ss_reset_template', function () {
    if (!current_user_can('manage_options')) wp_die('Acceso denegado.');
    check_admin_referer('estandar_ss_reset_template');

    $template_key = isset($_POST['template_key']) ? sanitize_key($_POST['template_key']) : '';
    if (class_exists('Estandar_Secure_Share_Email')) {
        Estandar_Secure_Share_Email::reset_template($template_key);
    }

    wp_redirect(add_query_arg([
        'page' => 'estandar-secure-share-emails',
        'template' => $template_key,
        'reset' => '1'
    ], admin_url('admin.php')));
    exit;
});

// ── Cron: Cleanup expired deliveries ────────────────────────────────────────
add_action('estandar_ss_hourly_cleanup', function () {
    Estandar_Secure_Share_Delivery::cleanup_expired();
});

if (!wp_next_scheduled('estandar_ss_hourly_cleanup')) {
    wp_schedule_event(time(), 'hourly', 'estandar_ss_hourly_cleanup');
}

// ── Enqueue assets ──────────────────────────────────────────────────────────
add_action('wp_enqueue_scripts', function () {
    if (!get_query_var('estandar_ss_token')) return;
    $css = ESTANDAR_SECURE_SHARE_DIR . 'assets/css/public.css';
    wp_enqueue_style('estandar-ss-public', ESTANDAR_SECURE_SHARE_URL . 'assets/css/public.css', [], filemtime($css));
    wp_enqueue_script('estandar-ss-public', ESTANDAR_SECURE_SHARE_URL . 'assets/js/public.js', [], filemtime(ESTANDAR_SECURE_SHARE_DIR . 'assets/js/public.js'), true);
});

add_action('admin_enqueue_scripts', function ($hook) {
    if (strpos($hook, 'estandar-secure-share') === false) return;
    $css = ESTANDAR_SECURE_SHARE_DIR . 'assets/css/admin.css';
    wp_enqueue_style('estandar-ss-admin', ESTANDAR_SECURE_SHARE_URL . 'assets/css/admin.css', [], filemtime($css));
    wp_enqueue_script('estandar-ss-admin', ESTANDAR_SECURE_SHARE_URL . 'assets/js/admin.js', [], filemtime(ESTANDAR_SECURE_SHARE_DIR . 'assets/js/admin.js'), true);
});

// ── Helper: set header if function available ────────────────────────────────
function header_quitar_terceros_safe($header, $value) {
    if (!headers_sent()) {
        header("$header: $value");
    }
}
