<?php
if (!defined('ABSPATH')) exit;

class Estandar_Secure_Share_Admin_Settings {

    public static function render(): void {
        $settings = get_option('estandar_secure_share_settings', []);
        $defaults = [
            'admin_email' => get_option('admin_email'),
            'default_expires_hours' => 24,
            'retention_days' => 30,
            'brand_name' => 'ESTANDAR',
            'brand_tagline' => 'Security Solutions',
            'delete_on_uninstall' => true,
        ];
        $s = wp_parse_args($settings, $defaults);
        ?>
        <div class="wrap estandar-ss-admin-wrap">
            <h1>Secure Share — Configuración</h1>

            <?php if (isset($_GET['saved'])): ?>
                <div class="notice notice-success"><p>Configuración guardada.</p></div>
            <?php endif; ?>

            <div class="estandar-ss-settings-form">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="estandar_ss_settings">
                    <?php wp_nonce_field('estandar_ss_settings'); ?>

                    <h2>Notificaciones</h2>
                    <label for="estandar_ss_admin_email">Correo del administrador responsable:</label>
                    <input type="email" name="estandar_ss_admin_email" id="estandar_ss_admin_email" value="<?php echo esc_attr($s['admin_email']); ?>">
                    <p class="description">Recibirá notificaciones de nuevas solicitudes de entrega.</p>

                    <h2 style="margin-top:24px;">Entregas</h2>
                    <label for="estandar_ss_default_expires">Expiración por defecto (horas):</label>
                    <input type="number" name="estandar_ss_default_expires" id="estandar_ss_default_expires" value="<?php echo intval($s['default_expires_hours']); ?>" min="1" max="720">

                    <label for="estandar_ss_retention_days">Retención de registros (días):</label>
                    <input type="number" name="estandar_ss_retention_days" id="estandar_ss_retention_days" value="<?php echo intval($s['retention_days']); ?>" min="1" max="365">
                    <p class="description">Los registros de entregas consumidas, vencidas o revocadas se eliminarán después de este período.</p>

                    <h2 style="margin-top:24px;">Branding</h2>
                    <label for="estandar_ss_brand_name">Nombre de marca:</label>
                    <input type="text" name="estandar_ss_brand_name" id="estandar_ss_brand_name" value="<?php echo esc_attr($s['brand_name']); ?>">

                    <label for="estandar_ss_brand_tagline">Bajada:</label>
                    <input type="text" name="estandar_ss_brand_tagline" id="estandar_ss_brand_tagline" value="<?php echo esc_attr($s['brand_tagline']); ?>">

                    <h2 style="margin-top:24px;">Desinstalación</h2>
                    <label>
                        <input type="checkbox" name="estandar_ss_delete_on_uninstall" value="1" <?php checked(!empty($s['delete_on_uninstall'])); ?>>
                        Eliminar todos los datos al desinstalar el plugin
                    </label>

                    <p style="margin-top:20px;">
                        <button type="submit" class="button button-primary">Guardar configuración</button>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }
}
