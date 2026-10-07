<?php
/**
 * Admin — Email settings page.
 * Variables: $s (current settings array from Estandar_Leads_Email::get_settings())
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
    <p style="margin-bottom:18px;">
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=estandar-leads-settings' ) ); ?>">
            ← Volver a Configuración
        </a>
    </p>

    <?php if ( ! empty( $_GET['email_saved'] ) ) : ?>
        <div class="notice notice-success is-dismissible"><p>Configuración de email guardada.</p></div>
    <?php endif; ?>

<?php
// ── SMTP Health panel ─────────────────────────────────────────────────────────
$smtp_h = Estandar_Leads_Email::smtp_health();
$smtp_bc = array(
    'PASS'    => array( 'border' => '#00a32a', 'bg' => '#f0fdf4', 'badge_bg' => '#00a32a', 'badge_text' => '#fff' ),
    'WARNING' => array( 'border' => '#dba617', 'bg' => '#fffbeb', 'badge_bg' => '#dba617', 'badge_text' => '#fff' ),
    'FAIL'    => array( 'border' => '#d63638', 'bg' => '#fef2f2', 'badge_bg' => '#d63638', 'badge_text' => '#fff' ),
);
$sbc = $smtp_bc[ $smtp_h['state'] ];
?>
<div style="border-left:4px solid <?php echo esc_attr( $sbc['border'] ); ?>;background:<?php echo esc_attr( $sbc['bg'] ); ?>;padding:14px 18px;margin-bottom:24px;border-radius:0 4px 4px 0;">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
        <strong style="font-size:13px;">SMTP Health</strong>
        <span style="background:<?php echo esc_attr( $sbc['badge_bg'] ); ?>;color:<?php echo esc_attr( $sbc['badge_text'] ); ?>;font-size:11px;font-weight:700;padding:2px 8px;border-radius:3px;letter-spacing:.5px;">
            <?php echo esc_html( $smtp_h['state'] ); ?>
        </span>
        <?php if ( $smtp_h['smtp_plugin'] ) : ?>
            <span style="font-size:11px;color:#6b7280;background:#f3f4f6;padding:2px 7px;border-radius:3px;"><?php echo esc_html( $smtp_h['smtp_plugin'] ); ?></span>
        <?php endif; ?>
    </div>
    <table style="width:100%;border-collapse:collapse;font-size:12px;">
        <?php foreach ( $smtp_h['layers'] as $layer ) : ?>
            <tr style="border-top:1px solid rgba(0,0,0,.06);">
                <td style="padding:5px 8px 5px 0;width:90px;color:#6b7280;font-size:11px;text-transform:uppercase;letter-spacing:.4px;">
                    <?php echo esc_html( $layer['label'] ); ?>
                </td>
                <td style="padding:5px 8px 5px 0;width:22px;text-align:center;">
                    <?php echo $layer['ok']
                        ? '<span style="color:#00a32a;font-weight:700;">✓</span>'
                        : '<span style="color:#d63638;font-weight:700;">⚠</span>'; ?>
                </td>
                <td style="padding:5px 0;color:#374151;"><?php echo esc_html( $layer['detail'] ); ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
    <?php if ( $smtp_h['state'] === 'FAIL' ) : ?>
        <p style="margin:10px 0 0;font-size:12px;color:#d63638;font-weight:600;">
            Acción requerida: configura un email de remitente válido en el formulario a continuación.
        </p>
    <?php elseif ( $smtp_h['state'] === 'WARNING' && ! $smtp_h['smtp_plugin'] ) : ?>
        <p style="margin:10px 0 0;font-size:12px;color:#92400e;">
            Acción sugerida: instala y configura un plugin SMTP (WP Mail SMTP, FluentSMTP o Brevo) para garantizar la entrega. Sin él, los correos dependen del servidor PHP y suelen caer en spam o rebotar.
        </p>
    <?php elseif ( $smtp_h['state'] === 'WARNING' && ! $smtp_h['last_success'] ) : ?>
        <p style="margin:10px 0 0;font-size:12px;color:#92400e;">
            Usa <strong>"Enviar email de prueba"</strong> para confirmar que la entrega funciona y registrar el primer éxito.
        </p>
    <?php endif; ?>
</div>

    <div style="display:flex;gap:24px;align-items:flex-start;flex-wrap:wrap;">

        <!-- ── SETTINGS FORM ────────────────────────────────────────────── -->
        <div style="flex:1;min-width:380px;max-width:680px;">
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'estandar_leads_save_email_settings' ); ?>
                <input type="hidden" name="action" value="estandar_leads_save_email_settings">

                <h2 style="margin-top:4px;">Remitente</h2>
                <table class="form-table">
                    <tr>
                        <th><label for="sender_name">Nombre del remitente</label></th>
                        <td>
                            <input class="regular-text" id="sender_name" name="sender_name"
                                   value="<?php echo esc_attr( $s['sender_name'] ); ?>">
                            <p class="description">Aparece como "De:" en el cliente de email.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="sender_email">Email del remitente</label></th>
                        <td>
                            <input class="regular-text" id="sender_email" name="sender_email"
                                   type="email" value="<?php echo esc_attr( $s['sender_email'] ); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="reply_to_email">Reply-To</label></th>
                        <td>
                            <input class="regular-text" id="reply_to_email" name="reply_to_email"
                                   type="email" value="<?php echo esc_attr( $s['reply_to_email'] ?? '' ); ?>">
                            <p class="description">Correo al que responderán los destinatarios.</p>
                        </td>
                    </tr>
                </table>

                <h2>Política de notificaciones</h2>
                <table class="form-table">
                    <tr>
                        <th><label for="notification_cc_email">CC global</label></th>
                        <td>
                            <textarea class="large-text" id="notification_cc_email" name="notification_cc_email" rows="2"><?php echo esc_textarea( $s['notification_cc_email'] ?? '' ); ?></textarea>
                            <p class="description">Uno o más correos separados por coma o salto de línea.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="notification_bcc_email">BCC global</label></th>
                        <td>
                            <textarea class="large-text" id="notification_bcc_email" name="notification_bcc_email" rows="2"><?php echo esc_textarea( $s['notification_bcc_email'] ?? '' ); ?></textarea>
                        </td>
                    </tr>
                </table>

                <h2>Contenido</h2>
                <table class="form-table">
                    <tr>
                        <th><label for="subject">Asunto</label></th>
                        <td>
                            <input class="large-text" id="subject" name="subject"
                                   value="<?php echo esc_attr( $s['subject'] ); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="preheader">Preheader</label></th>
                        <td>
                            <input class="large-text" id="preheader" name="preheader"
                                   value="<?php echo esc_attr( $s['preheader'] ); ?>">
                            <p class="description">Texto invisible que aparece en la vista previa del inbox.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="intro_text">Texto introductorio</label></th>
                        <td>
                            <textarea class="large-text" id="intro_text" name="intro_text"
                                      rows="3"><?php echo esc_textarea( $s['intro_text'] ); ?></textarea>
                            <p class="description">Aparece debajo del saludo. Se permiten: &lt;strong&gt;, &lt;em&gt;, &lt;a href=...&gt;, &lt;br&gt;.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="button_label">Texto del botón CTA</label></th>
                        <td>
                            <input class="regular-text" id="button_label" name="button_label"
                                   value="<?php echo esc_attr( $s['button_label'] ); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th>Contexto de negocio</th>
                        <td>
                            <label>
                                <input type="checkbox" name="show_business_context" value="1"
                                       <?php checked( 1, (int) $s['show_business_context'] ); ?>>
                                Mostrar sección "¿Por qué clasificar?"
                            </label>
                            <p class="description">Explica al operador el impacto de clasificar correctamente.</p>
                        </td>
                    </tr>
                </table>

                <h2>Branding</h2>
                <table class="form-table">
                    <tr>
                        <th><label for="brand_color">Color principal</label></th>
                        <td>
                            <input type="color" id="brand_color" name="brand_color"
                                   value="<?php echo esc_attr( $s['brand_color'] ); ?>"
                                   style="height:36px;width:80px;cursor:pointer;border:1px solid #ddd;border-radius:4px;padding:2px;">
                            <input type="text"  id="brand_color_text" name="brand_color_text_display"
                                   value="<?php echo esc_attr( $s['brand_color'] ); ?>"
                                   style="width:90px;font-family:monospace;" readonly>
                            <p class="description">Color del encabezado y botón CTA.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="logo_url">URL del logo</label></th>
                        <td>
                            <input class="large-text" id="logo_url" name="logo_url" type="url"
                                   value="<?php echo esc_attr( $s['logo_url'] ); ?>"
                                   placeholder="https://ejemplo.com/logo.png">
                            <p class="description">Debe ser una URL HTTPS accesible públicamente. Déjalo vacío para usar el nombre del sitio.</p>
                            <?php if ( ! empty( $s['logo_url'] ) ) : ?>
                                <img src="<?php echo esc_url( $s['logo_url'] ); ?>"
                                     alt="Logo actual" style="display:block;max-height:40px;margin-top:8px;">
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>

                <?php submit_button( 'Guardar configuración' ); ?>
            </form>
        </div>

        <!-- ── PREVIEW PANEL ────────────────────────────────────────────── -->
        <div style="flex:0 0 360px;position:sticky;top:32px;">
            <h2 style="margin-top:4px;">Vista previa</h2>
            <p style="color:#666;font-size:13px;margin-top:0;">
                Guarda los cambios y pulsa "Actualizar vista previa" para ver el email con datos de ejemplo.
            </p>
            <div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;">
                <button type="button" id="es-email-preview-btn" class="button">
                    Actualizar vista previa
                </button>
                <button type="button" id="es-email-test-btn" class="button button-secondary">
                    Enviar email de prueba
                </button>
            </div>
            <p id="es-test-status" style="font-size:13px;color:#666;margin:0 0 8px;min-height:18px;"></p>
            <div id="es-email-preview-frame"
                 style="border:1px solid #ddd;border-radius:4px;overflow:hidden;background:#f0f2f5;min-height:200px;display:flex;align-items:center;justify-content:center;">
                <span style="color:#999;font-size:13px;">La vista previa aparecerá aquí.</span>
            </div>
        </div>

    </div><!-- /flex wrapper -->

<script>
(function($) {
    var ajaxNonce = <?php echo wp_json_encode( wp_create_nonce( 'estandar_leads_email_preview' ) ); ?>;
    var adminEmail = <?php echo wp_json_encode( get_option( 'admin_email' ) ); ?>;

    $('#brand_color').on('input change', function() {
        $('#brand_color_text').val($(this).val());
    });

    $('#es-email-preview-btn').on('click', function() {
        var $frame = $('#es-email-preview-frame');
        $frame.html('<span style="color:#999;font-size:13px;padding:32px;">Cargando…</span>');
        $.post(ajaxurl, {
            action: 'estandar_leads_preview_email',
            nonce:  ajaxNonce
        }, function(resp) {
            if (resp.success) {
                $frame.html(
                    '<iframe srcdoc="' + $('<div>').text(resp.data.html).html().replace(/"/g, '&quot;') + '"' +
                    ' style="width:100%;height:600px;border:0;" scrolling="yes"></iframe>'
                );
            } else {
                $frame.html('<span style="color:#c00;padding:16px;font-size:13px;">Error al generar la vista previa.</span>');
            }
        });
    });

    $('#es-email-test-btn').on('click', function() {
        var to = prompt('Enviar email de prueba a:', adminEmail);
        if (!to) return;
        var $btn = $(this);
        var $status = $('#es-test-status');
        $btn.prop('disabled', true);
        $status.text('Enviando…');
        $.post(ajaxurl, {
            action:  'estandar_leads_test_email',
            nonce:   ajaxNonce,
            test_to: to
        }, function(resp) {
            $btn.prop('disabled', false);
            $status.css('color', resp.success ? '#197a1f' : '#c00')
                   .text(resp.success ? '✓ Email enviado a ' + to : '✗ ' + (resp.data.message || 'Error al enviar.'));
        });
    });
})(jQuery);
</script>
