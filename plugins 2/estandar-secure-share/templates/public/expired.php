<?php
$settings = get_option('estandar_secure_share_settings', []);
$brand = esc_html($settings['brand_name'] ?? 'ESTANDAR');
$tagline = esc_html($settings['brand_tagline'] ?? 'Security Solutions');
$token = $token ?? '';
$nonce = wp_create_nonce('estandar_ss_request_' . $token);
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title><?php echo $brand; ?> — La clave ya expiró</title>
<?php wp_head(); ?>
</head>
<body class="estandar-ss-body">
<main class="estandar-ss-card">
    <header class="estandar-ss-header">
        <h1><?php echo $brand; ?></h1>
        <p class="estandar-ss-tagline"><?php echo $tagline; ?></p>
    </header>

    <div class="estandar-ss-content">
        <div class="estandar-ss-icon">&#x23F3;</div>
        <h2>La clave ya expiró</h2>
        <p>Este enlace ya no está disponible. La información fue consumida, expiró o fue revocada.</p>

        <div class="estandar-ss-request-section">
            <h3>Solicitar una nueva entrega</h3>
            <p>Si necesitas acceder nuevamente, puedes solicitar una nueva entrega. El administrador revisará tu solicitud.</p>
            <form method="post" class="estandar-ss-form">
                <input type="hidden" name="estandar_ss_action" value="request">
                <input type="hidden" name="estandar_ss_nonce" value="<?php echo esc_attr($nonce); ?>">
                <input type="email" name="estandar_ss_email" class="estandar-ss-input" placeholder="tu@correo.com" required>
                <button type="submit" class="estandar-ss-btn estandar-ss-btn-secondary">
                    Solicitar nueva entrega
                </button>
            </form>
        </div>
    </div>

    <footer class="estandar-ss-footer">
        <p><?php echo $brand; ?> — <?php echo $tagline; ?></p>
    </footer>
</main>
<?php wp_footer(); ?>
</body>
</html>
