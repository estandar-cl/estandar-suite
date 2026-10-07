<?php
if (!defined('ABSPATH')) exit;

// Datos de empresa entregados por el form-handler
$company = isset($company_data) ? $company_data : null;
if (!$company) {
  echo '<div class="signature-generator"><p style="color:#c00;">Error: no se recibieron datos de empresa.</p></div>';
  return;
}

$company_key   = $company['key'] ?? '';
$primary_color = $company['primary_color'] ?? '#44655d';
$site_url      = $company['site'] ?? '#';
$email_domain  = $company['email_domain'] ?? '@empresa.cl';
$gif_folder    = $company['gif_folder'] ?? (plugin_dir_url(__DIR__) . "assets/gifs/{$company_key}/");
$address_text  = $company['address'] ?? ''; // multilínea
?>

<div id="signature-generator"
     class="signature-generator"
     data-company="<?php echo esc_attr($company_key); ?>"
     data-color="<?php echo esc_attr($primary_color); ?>"
     data-site="<?php echo esc_url($site_url); ?>"
     data-domain="<?php echo esc_attr($email_domain); ?>"
     data-address="<?php echo esc_attr($address_text); ?>">

  <form id="signatureForm" class="signature-generator-form" autocomplete="off">
    <div class="form-group">
      <label>Nombre completo</label><br>
      <input type="text" id="nombre" name="nombre" placeholder="Nombre Apellido" required>
    </div>

    <div class="form-group">
      <label>Cargo</label><br>
      <input type="text" id="cargo" name="cargo" placeholder="Cargo o puesto" required>
    </div>

    <div class="form-group">
      <label>Correo institucional</label><br>
      <div style="display:flex;align-items:center;gap:6px;">
        <input type="text" id="correo_local" name="correo_local" placeholder="nombre.apellido" required style="flex:1;">
        <span id="correo_domain" style="white-space:nowrap;"><?php echo esc_html($email_domain); ?></span>
      </div>
      <small style="color:#666;">Solo escribe la primera parte; el dominio institucional se completa automáticamente.</small>
    </div>

    <div class="form-group">
      <label>Teléfono móvil</label><br>
      <input type="text" id="telefono" name="telefono" placeholder="+56 9 1234 5678">
    </div>

    <div class="form-group">
      <label>Teléfono oficina</label><br>
      <input type="text" id="telefono2" name="telefono2" placeholder="+72 2 859138">
    </div>

    <div class="form-group">
      <label>Selecciona imagen o GIF</label><br>
      <div id="gifGallery" class="gif-gallery" data-folder="<?php echo esc_url($gif_folder); ?>"></div>
      <button type="button" id="verMasGifs" class="btn-vermas">Ver más GIFs</button>
    </div>

    <div class="form-group">
      <label>Subir imagen personalizada (GIF o JPG)</label><br>
      <input type="file" id="customImage" accept=".gif,.jpg,.jpeg,.png">
      <small>Recomendado: 114×114 px, máx. 200 KB</small>
      <div id="imagePreviewWrapper" style="display:none; margin-top:10px;">
        <p id="imageWarning" style="color:#d9534f; font-size:13px;"></p>
        <canvas id="imageCanvas" width="114" height="114" style="border:1px solid #ccc;"></canvas>
      </div>
    </div>

    <div class="form-group">
      <button type="button" id="generateSignature">Generar firma</button>
    </div>
  </form>

  <div id="signaturePreview" class="signature-preview" style="display:none;">
    <h3>Vista previa</h3>
    <div id="signatureHTML" class="signature-html"></div>
    <div class="actions">
      <button id="copyHTML" type="button">📋 Copiar HTML</button>
      <button id="downloadSignature" type="button">⬇️ Descargar</button>
    </div>
  </div>
  <?php
echo '
<div class="signature-help" style="margin-top:30px; padding:15px 20px; background:#f9f9f9; border:1px solid #ddd; border-radius:8px; font-size:14px; line-height:1.6; color:#333;">
  <h3 style="margin-top:0; color:#222;">🧭 Cómo usar tu firma</h3>
  <p>Sigue estos pasos según el programa o servicio de correo que utilices:</p>

  <h4 style="margin-bottom:5px;">📧 Outlook (Windows / Office 365)</h4>
  <ol style="margin-top:5px;">
    <li>Haz clic en <strong>📋 Copiar HTML</strong> para copiar tu firma al portapapeles.</li>
    <li>Abre Outlook → <em>Archivo</em> → <em>Opciones</em> → <em>Correo</em> → <em>Firmas...</em></li>
    <li>Crea una nueva firma y pégala directamente en el editor de texto.</li>
    <li>Guarda y asígnala a tus correos nuevos y/o respuestas.</li>
  </ol>

  <h4 style="margin-bottom:5px;">🌐 Gmail (web)</h4>
  <ol style="margin-top:5px;">
    <li>Haz clic en <strong>📋 Copiar HTML</strong>.</li>
    <li>En Gmail, ve a <em>Configuración ⚙️</em> → <em>Ver toda la configuración</em> → pestaña <em>General</em>.</li>
    <li>Baja hasta <em>Firma</em> → <em>Crear nueva</em> → y pega el contenido.</li>
    <li>Guarda los cambios al final de la página.</li>
  </ol>

  <h4 style="margin-bottom:5px;">🍎 Apple Mail</h4>
  <ol style="margin-top:5px;">
    <li>Presiona <strong>⬇️ Descargar</strong> y abre el archivo <code>firma.html</code> con tu navegador.</li>
    <li>Selecciona y copia todo (Ctrl+C o Cmd+C).</li>
    <li>En Apple Mail, ve a <em>Mail → Preferencias → Firmas</em> y crea una nueva.</li>
    <li>Pega el contenido en el editor y cierra la ventana para guardar.</li>
  </ol>

  <h4 style="margin-bottom:5px;">📱 Dispositivos móviles</h4>
  <p>En la mayoría de los teléfonos (Android o iPhone), Gmail o Outlook no permiten pegar HTML completo.  
  En ese caso, envíate un correo a ti mismo con la firma generada y <em>cópiala desde ese mensaje</em> directamente en la app móvil.</p>

  <p style="margin-top:15px; font-style:italic; color:#555;">Consejo: revisa siempre que la imagen o GIF se vea correctamente al enviar un correo de prueba. Si no aparece, usa la opción “Subir imagen personalizada” en el generador.</p>
</div>
';
?>
</div>