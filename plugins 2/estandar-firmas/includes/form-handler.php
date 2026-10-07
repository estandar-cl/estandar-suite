<?php
/**
 * form-handler.php
 * Gestiona el flujo de selección de empresa y carga del generador.
 */

if (!defined('ABSPATH')) exit;

function estandar_signature_handle_form() {
    // === Obtener datos desde la base de datos ===
    $companies = get_option('estandar_signature_companies', []);

    $output = '';

    // Si no hay datos cargados aún
    if (empty($companies)) {
        return '<p style="color:red;">⚠️ No hay empresas configuradas todavía. Ve al panel <strong>Firmas Estandar</strong> para crear tus datos.</p>';
    }

    // === Paso 1: Seleccionar empresa ===
    if (!isset($_POST['company'])) {
        $output .= '<form method="post" class="signature-form">';
        $output .= '<h3>Selecciona tu empresa</h3>';
        $output .= '<select name="company" required>';
        $output .= '<option value="">-- Selecciona --</option>';
        foreach ($companies as $key => $data) {
            $output .= '<option value="' . esc_attr($key) . '">' . esc_html($data['name'] ?? ucfirst($key)) . '</option>';
        }
        $output .= '</select>';
        $output .= '<button type="submit">Continuar</button>';
        $output .= '</form>';
        return $output;
    }

    // === Validar empresa seleccionada ===
    $company_key = sanitize_text_field($_POST['company']);
    if (!array_key_exists($company_key, $companies)) {
        return '<p style="color:red;">❌ Empresa no válida o no encontrada.</p>';
    }

    $company = $companies[$company_key];

    // === Paso 2: Mostrar el generador de firmas ===
    ob_start();

    // Normalizar datos para evitar "Undefined index"
    $company_data = [
        'key'           => $company_key,
        'name'          => $company['name'] ?? ucfirst($company_key),
        'primary_color' => $company['primary_color'] ?? '#44655d',
        'site'          => $company['site'] ?? '#',
        'email_domain'  => $company['email_domain'] ?? '@empresa.cl',
        'gif_folder'    => $company['gif_folder'] ?? plugin_dir_url(__DIR__) . "assets/gifs/{$company_key}/",
        'address'       => $company['address'] ?? "Dirección no configurada aún.\nActualiza el panel de empresa.",
    ];

    // Pasar datos al shortcode del generador
    include plugin_dir_path(__DIR__) . 'templates/shortcode-signature.php';

    $output .= ob_get_clean();
    return $output;
}

add_action('wp_ajax_get_company_gifs', 'estandar_ajax_get_company_gifs');
add_action('wp_ajax_nopriv_get_company_gifs', 'estandar_ajax_get_company_gifs');

function estandar_ajax_get_company_gifs() {
    $company_key = sanitize_text_field($_GET['company'] ?? '');
    if (!$company_key) {
        wp_send_json_error(['error' => 'Falta parámetro company']);
    }

    $base_dir = plugin_dir_path(__FILE__);
    $base_url = plugin_dir_url(__FILE__);

    $folder = realpath($base_dir . "../assets/gifs/{$company_key}/");
    $url = str_replace('/includes/', '/', $base_url) . "assets/gifs/{$company_key}/";

    if (!is_dir($folder)) {
        wp_send_json_error([
            'error' => 'Carpeta no encontrada',
        ]);
    }

    $files = glob($folder . '/*.{gif,jpg,jpeg,png}', GLOB_BRACE);
    if (empty($files)) {
        wp_send_json_error([
            'error' => 'No hay imágenes en carpeta',
            'path'  => $folder,
        ]);
    }

    $out = [];
    foreach ($files as $f) {
        $out[] = $url . basename($f);
    }

    wp_send_json_success([
        'company' => $company_key,
        'folder'  => $folder,
        'url'     => $url,
        'found'   => count($out),
        'files'   => $out,
    ]);
}
