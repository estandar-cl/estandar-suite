<?php
/**
 * settings-page.php — versión mejorada con campo de dirección, color picker y creación automática de carpeta GIFs
 */
if (!defined('ABSPATH')) exit;

// === GUARDAR o ACTUALIZAR empresa ===
if (isset($_POST['save_company'])) {
    check_admin_referer('estandar_signature_settings');
    $companies = get_option('estandar_signature_companies', []);

    // Clave interna única
    $key = sanitize_title($_POST['company_key']);
    if (empty($key)) {
        $key = 'empresa_' . time();
    }

    // === Crear carpeta de GIFs automáticamente ===
    $base_dir = plugin_dir_path(__DIR__) . 'assets/gifs/' . $key . '/';
    $base_url = plugin_dir_url(__DIR__) . 'assets/gifs/' . $key . '/';
    if (!file_exists($base_dir)) {
        wp_mkdir_p($base_dir);
    }

    // === Guardar datos de la empresa ===
    $companies[$key] = [
        'name'          => sanitize_text_field($_POST['company_name']),
        'primary_color' => sanitize_hex_color($_POST['company_color']),
        'gif_folder'    => esc_url_raw($base_url),
        'site'          => esc_url_raw($_POST['company_site']),
        'email_domain'  => sanitize_text_field($_POST['company_domain']),
        'address'       => wp_kses_post($_POST['company_address']),
    ];

    update_option('estandar_signature_companies', $companies);
    echo '<div class="updated"><p>✅ Empresa guardada correctamente y carpeta creada en <code>' . esc_html($base_dir) . '</code></p></div>';
}

// === ELIMINAR empresa ===
if (isset($_POST['delete_company'])) {
    check_admin_referer('delete_company_action');
    $companies = get_option('estandar_signature_companies', []);
    $key = sanitize_text_field($_POST['delete_key']);
    if (isset($companies[$key])) {
        unset($companies[$key]);
        update_option('estandar_signature_companies', $companies);
        echo '<div class="updated"><p>🗑️ Empresa eliminada correctamente.</p></div>';
    }
}

// === SELECCIONAR EMPRESA para editar ===
$edit_company = null;
if (isset($_POST['edit_company'])) {
    $key = sanitize_text_field($_POST['edit_key']);
    $companies = get_option('estandar_signature_companies', []);
    if (isset($companies[$key])) {
        $edit_company = $companies[$key];
        $edit_company['key'] = $key;
    }
}

// === SUBIDA DE GIFs ===
if (isset($_POST['upload_gif']) && !empty($_FILES['gif_file'])) {
    $folder = sanitize_text_field($_POST['gif_company']);
    check_admin_referer('upload_gif_' . $folder, 'upload_gif_nonce_' . $folder);

    $upload = $_FILES['gif_file'];
    $allowed = ['image/gif', 'image/jpeg', 'image/jpg', 'image/png'];
    if (!in_array($upload['type'], $allowed)) {
        echo '<div class="error"><p>❌ Tipo de archivo no permitido.</p></div>';
    } elseif ($upload['size'] > 500 * 1024) {
        echo '<div class="error"><p>❌ El archivo supera los 500 KB.</p></div>';
    } else {
        $target_dir = plugin_dir_path(__DIR__) . 'assets/gifs/' . $folder . '/';
        if (!file_exists($target_dir)) wp_mkdir_p($target_dir);
        $filename = basename($upload['name']);
        $target_file = $target_dir . $filename;

        if (move_uploaded_file($upload['tmp_name'], $target_file)) {
            echo '<div class="updated"><p>✅ Archivo subido correctamente a ' . esc_html($folder) . '</p></div>';
        } else {
            echo '<div class="error"><p>❌ Error al subir el archivo.</p></div>';
        }
    }
}

// === ELIMINACIÓN de GIFs ===
if (isset($_POST['delete_gif'])) {
    $folder = sanitize_text_field($_POST['delete_company']);
    $file = sanitize_text_field($_POST['delete_file']);
    check_admin_referer('delete_gif_' . $folder, 'delete_gif_nonce_' . $folder);

    $target = plugin_dir_path(__DIR__) . 'assets/gifs/' . $folder . '/' . $file;
    if (file_exists($target)) {
        unlink($target);
        echo '<div class="updated"><p>🗑️ Archivo eliminado: ' . esc_html($file) . '</p></div>';
    } else {
        echo '<div class="error"><p>❌ No se encontró el archivo.</p></div>';
    }
}

// === Recuperar empresas ===
$companies = get_option('estandar_signature_companies', []);
?>

<div class="wrap">
  <h1>Configuración del Generador de Firmas</h1>
  <p>Administra las empresas y sus configuraciones institucionales. Desde aquí puedes crear, editar o eliminar empresas y gestionar sus GIFs.</p>
  <hr>

  <h2>Empresas registradas</h2>
  <?php if (empty($companies)) : ?>
    <p>No hay empresas registradas aún.</p>
  <?php else : ?>
    <table class="widefat striped">
      <thead>
        <tr>
          <th>Nombre</th>
          <th>Color</th>
          <th>Dominio</th>
          <th>GIFs</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($companies as $key => $data) : ?>
          <tr>
            <td><strong><?php echo esc_html($data['name']); ?></strong></td>
            <td>
              <span style="display:inline-block;width:20px;height:20px;background:<?php echo esc_attr($data['primary_color']); ?>;border:1px solid #ccc;"></span>
              <?php echo esc_html($data['primary_color']); ?>
            </td>
            <td><?php echo esc_html($data['email_domain']); ?></td>
            <td><code><?php echo esc_html($data['gif_folder']); ?></code></td>
            <td>
              <form method="post" style="display:inline;">
                <input type="hidden" name="edit_key" value="<?php echo esc_attr($key); ?>">
                <button type="submit" name="edit_company" class="button button-primary">Editar</button>
              </form>
              <form method="post" style="display:inline;">
                <?php wp_nonce_field('delete_company_action'); ?>
                <input type="hidden" name="delete_key" value="<?php echo esc_attr($key); ?>">
                <button type="submit" name="delete_company" class="button button-secondary" onclick="return confirm('¿Eliminar esta empresa?');">Eliminar</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <hr>

  <h2><?php echo $edit_company ? 'Editar empresa' : 'Agregar nueva empresa'; ?></h2>
  <form method="post" action="">
    <?php wp_nonce_field('estandar_signature_settings'); ?>
    <table class="form-table">
      <tr>
        <th>Clave interna (única)</th>
        <td><input type="text" name="company_key" value="<?php echo esc_attr($edit_company['key'] ?? ''); ?>" placeholder="acacios" size="40"></td>
      </tr>
      <tr>
        <th>Nombre</th>
        <td><input type="text" name="company_name" value="<?php echo esc_attr($edit_company['name'] ?? ''); ?>" required size="60"></td>
      </tr>
      <tr>
        <th>Color principal</th>
        <td><input type="text" name="company_color" value="<?php echo esc_attr($edit_company['primary_color'] ?? '#44655d'); ?>" class="color-field"></td>
      </tr>
      <tr>
        <th>Sitio web</th>
        <td><input type="text" name="company_site" value="<?php echo esc_attr($edit_company['site'] ?? ''); ?>" size="80" placeholder="https://www.tusitio.cl"></td>
      </tr>
      <tr>
        <th>Dominio de correo</th>
        <td><input type="text" name="company_domain" value="<?php echo esc_attr($edit_company['email_domain'] ?? '@empresa.cl'); ?>" placeholder="@empresa.cl"></td>
      </tr>
      <tr>
        <th>Dirección institucional (una línea por dirección)</th>
        <td>
          <textarea name="company_address" rows="3" cols="80" placeholder="Línea 1&#10;Línea 2"><?php
            echo esc_textarea($edit_company['address'] ?? '');
          ?></textarea>
          <p class="description">Ejemplo:<br>
            Av. Armando Jaramillo 1053, Nancagua. VI Región • Chile<br>
            Av. Presidente Kennedy 7440, Of. 607, Vitacura. Santiago • Chile
          </p>
        </td>
      </tr>
    </table>

    <p class="submit">
      <input type="submit" name="save_company" class="button-primary" value="Guardar empresa">
    </p>
  </form>

  <?php if ($edit_company): ?>
    <hr>
    <h2>Gestión de GIFs — <?php echo esc_html($edit_company['name']); ?></h2>
    <?php
    $folder = sanitize_title($edit_company['key']);
    $base_dir = plugin_dir_path(__DIR__) . 'assets/gifs/' . $folder . '/';
    $base_url = plugin_dir_url(__DIR__) . 'assets/gifs/' . $folder . '/';
    if (!file_exists($base_dir)) wp_mkdir_p($base_dir);

    echo '<form method="post" enctype="multipart/form-data">';
    wp_nonce_field('upload_gif_' . $folder, 'upload_gif_nonce_' . $folder);
    echo '<input type="file" name="gif_file" accept=".gif,.jpg,.jpeg,.png" required>';
    echo '<input type="hidden" name="gif_company" value="' . esc_attr($folder) . '">';
    echo '<input type="submit" name="upload_gif" class="button" value="Subir archivo">';
    echo '</form>';

    $files = array_diff(scandir($base_dir), ['.', '..']);
    if ($files) {
        echo '<div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:10px;">';
        foreach ($files as $file) {
            $url = $base_url . $file;
            echo '<form method="post" style="display:inline;">';
            wp_nonce_field('delete_gif_' . $folder, 'delete_gif_nonce_' . $folder);
            echo '<input type="hidden" name="delete_company" value="' . esc_attr($folder) . '">';
            echo '<input type="hidden" name="delete_file" value="' . esc_attr($file) . '">';
            echo '<div style="position:relative;display:inline-block;">';
            echo '<img src="' . esc_url($url) . '" style="width:90px;height:90px;object-fit:cover;border:2px solid #ccc;border-radius:6px;">';
            echo '<button type="submit" name="delete_gif" style="position:absolute;top:3px;right:3px;background:#dc3232;color:#fff;border:none;border-radius:4px;padding:2px 5px;cursor:pointer;">✕</button>';
            echo '</div>';
            echo '</form>';
        }
        echo '</div>';
    } else {
        echo '<p><em>No hay GIFs para esta empresa.</em></p>';
    }
    ?>
  <?php endif; ?>
</div>

<script>
jQuery(document).ready(function($){
  $('.color-field').wpColorPicker();
});
</script>
