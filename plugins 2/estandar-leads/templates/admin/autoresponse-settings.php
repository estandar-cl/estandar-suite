<?php
if (!defined('ABSPATH')) {
    exit;
}
?>

<?php if (!empty($_GET['saved'])) : ?>
    <div class="notice notice-success is-dismissible"><p>Autorespuesta guardada.</p></div>
<?php endif; ?>
<?php if (isset($_GET['test_sent'])) : ?>
    <div class="notice <?php echo $_GET['test_sent'] === '1' ? 'notice-success' : 'notice-error'; ?> is-dismissible">
        <p><?php echo $_GET['test_sent'] === '1' ? 'Prueba enviada.' : 'wp_mail() devolvió false.'; ?></p>
    </div>
<?php endif; ?>

<p>Configura el contenido mínimo por proyecto. La firma usa automáticamente el operador asignado.</p>
<p class="description">
    Variables disponibles:
    <code>{{nombre}}</code>,
    <code>{{proyecto}}</code>,
    <code>{{operador}}</code>,
    <code>{{email}}</code>,
    <code>{{telefono}}</code>,
    <code>{{mensaje}}</code>.
</p>

<?php if (empty($projects)) : ?>
    <div class="notice notice-warning"><p>No hay proyectos activos en Project Registry.</p></div>
<?php endif; ?>

<?php foreach ($projects as $project) :
    $project_slug = (string) ($project['project_slug'] ?? '');
    $project_uuid = (string) ($project['project_uuid'] ?? '');
    if ($project_slug === '') {
        continue;
    }
    $project_name = (string) ($project['name'] ?? $project_slug);
    $settings = Estandar_Leads_Email::get_autoresponse_settings($project_slug);
    $attachment_id = absint($settings['pdf_attachment_id'] ?? 0);
    $attachment_title = $attachment_id ? get_the_title($attachment_id) : '';
    $last_test = is_array($last_tests[$project_slug] ?? null) ? $last_tests[$project_slug] : null;
?>
    <div class="postbox" style="margin:20px 0;max-width:900px;">
        <div class="postbox-header">
            <h2 style="padding:0 12px;"><?php echo esc_html($project_name); ?> <code><?php echo esc_html($project_slug); ?></code></h2>
        </div>
        <div class="inside">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('estandar_leads_save_autoresponse_settings'); ?>
                <input type="hidden" name="action" value="estandar_leads_save_autoresponse_settings">
                <input type="hidden" name="project_slug" value="<?php echo esc_attr($project_slug); ?>">
                <input type="hidden" name="project_uuid" value="<?php echo esc_attr($project_uuid); ?>">

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Activado</th>
                        <td>
                            <label>
                                <input type="checkbox" name="enabled" value="1" <?php checked(!empty($settings['enabled'])); ?>>
                                Enviar autorespuesta para este proyecto
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="subject-<?php echo esc_attr($project_slug); ?>">Asunto</label></th>
                        <td>
                            <input class="large-text" id="subject-<?php echo esc_attr($project_slug); ?>" name="subject"
                                   value="<?php echo esc_attr((string) ($settings['subject'] ?? '')); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="body-<?php echo esc_attr($project_slug); ?>">Mensaje</label></th>
                        <td>
                            <textarea class="large-text" rows="8" id="body-<?php echo esc_attr($project_slug); ?>"
                                      name="body"><?php echo esc_textarea((string) ($settings['body'] ?? '')); ?></textarea>
                            <p class="description">Texto plano dentro de la plantilla segura del email.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">PDF opcional</th>
                        <td>
                            <input type="hidden" class="es-autoresponse-pdf-id" name="pdf_attachment_id"
                                   value="<?php echo esc_attr((string) $attachment_id); ?>">
                            <span class="es-autoresponse-pdf-name">
                                <?php echo $attachment_title !== '' ? esc_html($attachment_title) : 'Sin PDF asociado'; ?>
                            </span>
                            <button type="button" class="button es-autoresponse-select-pdf" style="margin-left:8px;">Seleccionar PDF</button>
                            <button type="button" class="button-link-delete es-autoresponse-remove-pdf" style="margin-left:8px;">Quitar</button>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Guardar autorespuesta'); ?>
            </form>

            <hr>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;align-items:start;">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('estandar_leads_preview_autoresponse'); ?>
                    <input type="hidden" name="action" value="estandar_leads_preview_autoresponse">
                    <input type="hidden" name="project_slug" value="<?php echo esc_attr($project_slug); ?>">
                    <input type="hidden" name="project_uuid" value="<?php echo esc_attr($project_uuid); ?>">
                    <?php submit_button('Vista previa', 'secondary', 'submit', false); ?>
                </form>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('estandar_leads_test_autoresponse'); ?>
                    <input type="hidden" name="action" value="estandar_leads_test_autoresponse">
                    <input type="hidden" name="project_slug" value="<?php echo esc_attr($project_slug); ?>">
                    <input type="hidden" name="project_uuid" value="<?php echo esc_attr($project_uuid); ?>">
                    <label>
                        Correo destino:
                        <input type="email" name="test_email" class="regular-text" required>
                    </label>
                    <?php submit_button('Enviar prueba', 'secondary', 'submit', false); ?>
                </form>

                <div>
                    <strong>Último envío de prueba</strong><br>
                    <?php if ($last_test) : ?>
                        Proyecto: <?php echo esc_html((string) ($last_test['project_name'] ?? $project_name)); ?><br>
                        Hora: <?php echo esc_html((string) ($last_test['ts'] ?? '')); ?><br>
                        Destino: <?php echo esc_html((string) ($last_test['to'] ?? '')); ?><br>
                        Resultado: <?php echo !empty($last_test['ok']) ? 'OK' : 'ERROR'; ?>
                    <?php else : ?>
                        <span class="description">Sin pruebas registradas.</span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($preview && ($preview['project_slug'] ?? '') === $project_slug) : ?>
                <div style="margin-top:16px;padding:12px;border:1px solid #ccd0d4;background:#fff;">
                    <h3>Vista previa</h3>
                    <p><strong>Asunto:</strong> <?php echo esc_html((string) ($preview['subject'] ?? '')); ?></p>
                    <p><strong>Texto final:</strong></p>
                    <pre style="white-space:pre-wrap;background:#f6f7f7;padding:10px;"><?php echo esc_html((string) ($preview['body'] ?? '')); ?></pre>
                    <p><strong>HTML final:</strong></p>
                    <iframe title="Vista previa autorespuesta" style="width:100%;height:520px;border:1px solid #ccd0d4;background:#fff;" srcdoc="<?php echo esc_attr((string) ($preview['html'] ?? '')); ?>"></iframe>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>

<script>
document.addEventListener('click', function (event) {
    if (event.target.classList.contains('es-autoresponse-select-pdf')) {
        event.preventDefault();
        var row = event.target.closest('td');
        var frame = wp.media({
            title: 'Seleccionar PDF',
            button: { text: 'Usar este PDF' },
            library: { type: 'application/pdf' },
            multiple: false
        });
        frame.on('select', function () {
            var attachment = frame.state().get('selection').first().toJSON();
            row.querySelector('.es-autoresponse-pdf-id').value = attachment.id;
            row.querySelector('.es-autoresponse-pdf-name').textContent = attachment.title || attachment.filename;
        });
        frame.open();
    }
    if (event.target.classList.contains('es-autoresponse-remove-pdf')) {
        event.preventDefault();
        var row = event.target.closest('td');
        row.querySelector('.es-autoresponse-pdf-id').value = '0';
        row.querySelector('.es-autoresponse-pdf-name').textContent = 'Sin PDF asociado';
    }
});
</script>
