<?php
/*
Plugin Name: Estandar Firmas
Plugin URI: https://www.estandar.cl
Description: Generador de firmas institucionales HTML para empresas clientes de Estandar Studio.
Version: 13.0.173
Author: Luis Valdés de Estandar Studios CL
Author URI: https://www.estandar.cl
Text Domain: estandar-firmas
Update URI: https://updates.estandar.cl
*/

if (!defined('ABSPATH')) exit;

// ===================================================
// 🎨 Encolar scripts y estilos públicos
// ===================================================
add_action('wp_enqueue_scripts', function() {
    $base = plugin_dir_path(__FILE__);
    $url  = plugin_dir_url(__FILE__);

    wp_enqueue_style(
        'signature-generator-style',
        $url . 'assets/style.css',
        [],
        filemtime($base . 'assets/style.css')
    );

    wp_enqueue_script(
        'signature-generator-script',
        $url . 'assets/script.js',
        ['jquery'],
        filemtime($base . 'assets/script.js'),
        true
    );

    wp_localize_script('signature-generator-script', 'ESTANDAR', [
        'ajaxurl' => esc_url(admin_url('admin-ajax.php')),
        'pluginUrl' => esc_url($url),
    ]);
});

// ===================================================
// 🧩 Shortcode principal
// ===================================================
add_shortcode('estandar_signature_generator', function() {
    require_once plugin_dir_path(__FILE__) . 'includes/form-handler.php';
    return estandar_signature_handle_form();
});

// ===================================================
// 🧱 Registro de activación (crea carpetas necesarias)
// ===================================================
register_activation_hook(__FILE__, function() {
    $dirs = [
        'assets/gifs/',
        'assets/gifs/acacios/',
    ];

    foreach ($dirs as $dir) {
        $path = plugin_dir_path(__FILE__) . $dir;
        if (!file_exists($path)) wp_mkdir_p($path);
    }
});

// ===================================================
// 🧠 Integración al menú principal “Estandar Studio”
// ===================================================
add_action('admin_menu', function() {
    $parent = defined('ESTANDAR_CORE_MENU_SLUG') ? ESTANDAR_CORE_MENU_SLUG : '';
    if ($parent) {
        add_submenu_page(
            $parent,
            'Generador de Firmas',
            'Firmas',
            'manage_options',
            'estandar-signature-settings',
            'estandar_signature_settings_page'
        );
        return;
    }

    add_menu_page(
        'Generador de Firmas',
        'Firmas',
        'manage_options',
        'estandar-signature-settings',
        'estandar_signature_settings_page',
        'dashicons-id',
        58
    );
}, 20);

// ===================================================
// ⚙️ Página de ajustes del generador
// ===================================================
function estandar_signature_settings_page() {
    require_once plugin_dir_path(__FILE__) . 'admin/settings-page.php';
}

// ===================================================
// 🎨 Encolar color picker solo en la página del plugin
// ===================================================
add_action('admin_enqueue_scripts', function($hook) {
    if (strpos($hook, 'estandar-signature-settings') !== false) {
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
    }
});

// ===================================================
// ⚙️ Funciones AJAX globales
// ===================================================
require_once plugin_dir_path(__FILE__) . 'includes/form-handler.php';
