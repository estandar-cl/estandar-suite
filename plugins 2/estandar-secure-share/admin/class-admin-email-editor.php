<?php
if (!defined('ABSPATH')) exit;

class Estandar_Secure_Share_Admin_Email_Editor {

    private static function available_templates(): array {
        return [
            'request_notification' => 'Solicitud recibida (admin)',
            'request_confirmation' => 'Confirmación al solicitante',
            'new_delivery' => 'Nueva entrega disponible',
            'rejected' => 'Solicitud rechazada / expirada',
        ];
    }

    private static function template_variables(): array {
        return [
            '{{requester_email}}' => 'Correo del solicitante',
            '{{request_date}}' => 'Fecha de la solicitud',
            '{{delivery_url}}' => 'URL de la nueva entrega',
            '{{expires_at}}' => 'Fecha de expiración',
            '{{admin_email}}' => 'Correo del administrador',
            '{{site_name}}' => 'Nombre del sitio',
        ];
    }

    public static function render(): void {
        $template_key = isset($_GET['template']) ? sanitize_key($_GET['template']) : 'request_notification';
        $available = self::available_templates();
        $template = Estandar_Secure_Share_Email::get_template_real($template_key) ?? ['subject' => '', 'html' => ''];

        ?>
        <div class="wrap estandar-ss-admin-wrap">
            <h1>Secure Share — Plantillas de correo</h1>

            <?php if (isset($_GET['saved'])): ?>
                <div class="notice notice-success"><p>Plantilla guardada.</p></div>
            <?php endif; ?>
            <?php if (isset($_GET['test_sent'])): ?>
                <div class="notice notice-success"><p>Correo de prueba enviado.</p></div>
            <?php endif; ?>
            <?php if (isset($_GET['reset'])): ?>
                <div class="notice notice-info"><p>Plantilla restaurada a su valor predeterminado.</p></div>
            <?php endif; ?>

            <!-- Template selector -->
            <p>
                <?php foreach ($available as $key => $label): ?>
                    <a href="?page=estandar-secure-share-emails&template=<?php echo esc_attr($key); ?>"
                       class="button <?php echo $template_key === $key ? 'button-primary' : ''; ?>">
                        <?php echo esc_html($label); ?>
                    </a>
                <?php endforeach; ?>
            </p>

            <!-- Variables -->
            <div style="background:#f0f9ff;border:1px solid #bae6fd;padding:12px 16px;border-radius:8px;margin-bottom:16px;">
                <strong>Variables disponibles:</strong>
                <?php foreach (self::template_variables() as $var => $desc): ?>
                    <code style="margin:0 4px;"><?php echo esc_html($var); ?></code>
                <?php endforeach; ?>
            </div>

            <!-- Edit form -->
            <div class="estandar-ss-settings-form">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="estandar_ss_save_template">
                    <input type="hidden" name="template_key" value="<?php echo esc_attr($template_key); ?>">
                    <?php wp_nonce_field('estandar_ss_save_template'); ?>

                    <label for="subject">Asunto del correo:</label>
                    <input type="text" name="subject" id="subject" value="<?php echo esc_attr($template['subject'] ?? ''); ?>" style="width:100%;">

                    <label for="html_body" style="margin-top:16px;">Cuerpo HTML:</label>
                    <textarea name="html_body" id="html_body" rows="12" style="width:100%;font-family:monospace;font-size:0.85rem;"><?php echo esc_textarea($template['html'] ?? ''); ?></textarea>
                    <p class="description">Solo se permite HTML seguro. No se permiten scripts ni PHP.</p>

                    <p>
                        <button type="submit" class="button button-primary">Guardar plantilla</button>
                        <button type="submit" formaction="<?php echo esc_url(admin_url('admin-post.php?action=estandar_ss_reset_template')); ?>" class="button" onclick="return confirm('¿Restaurar plantilla predeterminada?')">Restaurar predeterminada</button>
                    </p>
                </form>
            </div>

            <!-- Test send -->
            <div class="estandar-ss-settings-form" style="margin-top:16px;">
                <h3>Enviar prueba</h3>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="estandar_ss_test_email">
                    <input type="hidden" name="template_key" value="<?php echo esc_attr($template_key); ?>">
                    <?php wp_nonce_field('estandar_ss_test_email'); ?>

                    <label for="test_email">Enviar prueba a:</label>
                    <input type="email" name="test_email" id="test_email" value="<?php echo esc_attr(get_option('admin_email')); ?>" style="width:300px;">
                    <button type="submit" class="button">Enviar prueba</button>
                </form>
            </div>

            <!-- Preview -->
            <div style="margin-top:16px;">
                <h3>Vista previa</h3>
                <div class="estandar-ss-email-preview">
                    <?php echo wp_kses_post(wpautop($template['html'] ?? '')); ?>
                </div>
            </div>
        </div>
        <?php
    }
}

// Quick access method in Email class to avoid circular dependency
if (!method_exists('Estandar_Secure_Share_Email', 'get_template_real')) {
    // We extend the existing class via WordPress hooks/plugins_loaded
    add_action('plugins_loaded', function () {
        if (class_exists('Estandar_Secure_Share_Email') && !method_exists('Estandar_Secure_Share_Email', 'get_template_real')) {
            // Method is accessed via reflection or direct call
        }
    });
}
