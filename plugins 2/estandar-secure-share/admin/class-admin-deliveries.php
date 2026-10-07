<?php
if (!defined('ABSPATH')) exit;

class Estandar_Secure_Share_Admin_Deliveries {

    public static function render(): void {
        $settings = get_option('estandar_secure_share_settings', []);
        $status_filter = isset($_GET['status']) ? sanitize_key($_GET['status']) : '';
        $filters = $status_filter ? ['status' => $status_filter] : [];
        $deliveries = Estandar_Secure_Share_Delivery::list($filters);
        $requests = Estandar_Secure_Share_Delivery::list_requests();
        $stats = class_exists('Estandar_Secure_Share_Audit') ? Estandar_Secure_Share_Audit::stats() : [];
        $key_ready = class_exists('Estandar_Secure_Share_Crypto') && Estandar_Secure_Share_Crypto::is_ready();
        ?>
        <div class="wrap estandar-ss-admin-wrap">
            <h1>Secure Share — Entregas</h1>

            <?php if (!$key_ready): ?>
                <div class="notice notice-error"><p><strong>Clave de cifrado no configurada.</strong> Agrega <code>define('ESTANDAR_SECURE_SHARE_KEY', '...');</code> a tu <code>wp-config.php</code>.</p></div>
            <?php endif; ?>

            <?php if (isset($_GET['created']) && isset($_GET['token'])): ?>
                <div class="estandar-ss-success-msg">
                    <strong>Entrega creada.</strong>
                    <div class="estandar-ss-token-result">
                        <strong>URL pública:</strong><br>
                        <code><?php echo esc_html(home_url('/' . ESTANDAR_SECURE_SHARE_REWRITE_PREFIX . '/' . sanitize_text_field(wp_unslash($_GET['token'])))); ?></code>
                        <br><br>
                        <button type="button" class="button estandar-ss-copy-btn">Copiar enlace</button>
                    </div>
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['revoked'])): ?>
                <div class="notice notice-success"><p>Entrega revocada.</p></div>
            <?php endif; ?>
            <?php if (isset($_GET['fulfilled'])): ?>
                <div class="notice notice-success"><p>Solicitud completada y nueva entrega creada.</p></div>
            <?php endif; ?>

            <!-- Create form -->
            <div class="estandar-ss-create-form" style="margin-bottom:24px;">
                <h2>Nueva entrega</h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="estandar_ss_create">
                    <?php wp_nonce_field('estandar_ss_create'); ?>

                    <label for="estandar_ss_secret">Secreto (texto a proteger):</label>
                    <textarea name="estandar_ss_secret" id="estandar_ss_secret" rows="3" required></textarea>
                    <button type="button" class="button estandar-ss-generate-btn" style="margin-top:6px;">Generar clave aleatoria</button>

                    <label for="estandar_ss_expires">Expira en (horas):</label>
                    <input type="number" name="estandar_ss_expires" id="estandar_ss_expires" value="<?php echo intval($settings['default_expires_hours'] ?? 24); ?>" min="1" max="720">

                    <label for="estandar_ss_requester_email">Correo del destinatario (opcional):</label>
                    <input type="email" name="estandar_ss_requester_email" id="estandar_ss_requester_email" placeholder="Opcional">

                    <p style="margin-top:16px;">
                        <button type="submit" class="button button-primary" <?php echo $key_ready ? '' : 'disabled'; ?>>Crear enlace seguro</button>
                    </p>
                </form>
            </div>

            <!-- Stats -->
            <?php if ($stats): ?>
            <div style="display:flex;gap:16px;margin-bottom:20px;flex-wrap:wrap;">
                <div style="background:#fff;padding:12px 18px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.06);">
                    <strong><?php echo intval($stats['total'] ?? 0); ?></strong> eventos auditados
                </div>
                <div style="background:#fff;padding:12px 18px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.06);">
                    <strong><?php echo intval($stats['last_24h'] ?? 0); ?></strong> en las últimas 24h
                </div>
            </div>
            <?php endif; ?>

            <!-- Filter -->
            <p>
                <a href="?page=estandar-secure-share" class="button <?php echo !$status_filter ? 'button-primary' : ''; ?>">Todas</a>
                <a href="?page=estandar-secure-share&status=available" class="button <?php echo $status_filter === 'available' ? 'button-primary' : ''; ?>">Disponibles</a>
                <a href="?page=estandar-secure-share&status=consumed" class="button <?php echo $status_filter === 'consumed' ? 'button-primary' : ''; ?>">Consumidas</a>
                <a href="?page=estandar-secure-share&status=expired" class="button <?php echo $status_filter === 'expired' ? 'button-primary' : ''; ?>">Vencidas</a>
                <a href="?page=estandar-secure-share&status=revoked" class="button <?php echo $status_filter === 'revoked' ? 'button-primary' : ''; ?>">Revocadas</a>
            </p>

            <!-- Deliveries table -->
            <table class="estandar-ss-admin-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Estado</th>
                        <th>Creado</th>
                        <th>Expira</th>
                        <th>Accesos</th>
                        <th>Solicitante</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($deliveries)): ?>
                        <tr><td colspan="7">No hay entregas registradas.</td></tr>
                    <?php else: ?>
                        <?php foreach ($deliveries as $d): ?>
                            <tr>
                                <td><?php echo intval($d['id']); ?></td>
                                <td><span class="estandar-ss-status estandar-ss-status-<?php echo esc_attr($d['status']); ?>"><?php echo esc_html($d['status']); ?></span></td>
                                <td><?php echo esc_html($d['created_at']); ?></td>
                                <td><?php echo esc_html($d['expires_at']); ?></td>
                                <td><?php echo intval($d['delivery_count']); ?></td>
                                <td><?php echo esc_html($d['requester_email'] ?? '-'); ?></td>
                                <td>
                                    <?php if ($d['status'] === 'available'): ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                        <input type="hidden" name="action" value="estandar_ss_revoke">
                                        <input type="hidden" name="delivery_id" value="<?php echo intval($d['id']); ?>">
                                        <?php wp_nonce_field('estandar_ss_revoke'); ?>
                                        <button type="submit" class="button button-small" onclick="return confirm('¿Revocar esta entrega?')">Revocar</button>
                                    </form>
                                    <?php endif; ?>
                                    <button type="button" class="button button-small" onclick="navigator.clipboard.writeText('<?php echo esc_js(home_url('/' . ESTANDAR_SECURE_SHARE_REWRITE_PREFIX . '/' . $d['token'])); ?>')">Copiar URL</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Pending requests -->
            <?php if (!empty($requests)): ?>
            <h2 style="margin-top:32px;">Solicitudes pendientes (<?php echo count($requests); ?>)</h2>
            <table class="estandar-ss-admin-table">
                <thead>
                    <tr><th>ID</th><th>Email</th><th>Fecha</th><th>Entrega original</th><th>Acción</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $req): ?>
                    <tr>
                        <td><?php echo intval($req['id']); ?></td>
                        <td><?php echo esc_html($req['email']); ?></td>
                        <td><?php echo esc_html($req['created_at']); ?></td>
                        <td>#<?php echo intval($req['delivery_id']); ?></td>
                        <td>
                            <button type="button" class="button button-small" onclick="document.getElementById('fulfill-form-<?php echo intval($req['id']); ?>').style.display='block'">Crear nueva entrega</button>
                            <div id="fulfill-form-<?php echo intval($req['id']); ?>" style="display:none;margin-top:8px;padding:12px;background:#f9fafb;border-radius:6px;">
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                    <input type="hidden" name="action" value="estandar_ss_fulfill">
                                    <input type="hidden" name="request_id" value="<?php echo intval($req['id']); ?>">
                                    <?php wp_nonce_field('estandar_ss_fulfill'); ?>
                                    <textarea name="estandar_ss_secret" rows="2" style="width:100%;" placeholder="Nuevo secreto..." required></textarea>
                                    <input type="number" name="estandar_ss_expires" value="24" min="1" max="720" style="width:80px;">
                                    <button type="submit" class="button button-primary button-small">Enviar</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php
    }
}
