<?php
$delivery = $delivery ?? null;
$token = $token ?? '';
$nonce = wp_create_nonce('estandar_ss_reveal_' . $token);
$settings = get_option('estandar_secure_share_settings', []);
$brand = esc_html($settings['brand_name'] ?? 'ESTANDAR');
$tagline = esc_html($settings['brand_tagline'] ?? 'Security Solutions');
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title><?php echo $brand; ?> — <?php echo $tagline; ?></title>
<?php wp_head(); ?>
</head>
<body class="estandar-ss-body">
<main class="estandar-ss-card">
    <header class="estandar-ss-header">
        <h1><?php echo $brand; ?></h1>
        <p class="estandar-ss-tagline"><?php echo $tagline; ?></p>
    </header>

    <div class="estandar-ss-content">
        <div class="estandar-ss-icon">&#x1F512;</div>
        <h2>Información confidencial</h2>
        <p>Alguien te ha enviado información protegida.</p>
        <div class="estandar-ss-warning">
            <strong>&#x26A0;&#xFE0F; Importante:</strong> Esta clave solo puede verse <em>una vez</em>.
            Al hacer clic en el botón, se destruirá permanentemente.
            Asegúrate de estar listo para copiarla o anotarla.
        </div>

        <form method="post" class="estandar-ss-form">
            <input type="hidden" name="estandar_ss_action" value="reveal">
            <input type="hidden" name="estandar_ss_nonce" value="<?php echo esc_attr($nonce); ?>">
            <button type="submit" class="estandar-ss-btn estandar-ss-btn-primary">
                Mostrar clave
            </button>
        </form>

        <?php if ($delivery && !empty($delivery['expires_at'])): ?>
        <p class="estandar-ss-expiry">
            Este enlace expira el <?php echo esc_html($delivery['expires_at']); ?>
        </p>
        <?php endif; ?>
    </div>

    <footer class="estandar-ss-footer">
        <p><?php echo $brand; ?> — <?php echo $tagline; ?></p>
    </footer>
</main>
<?php wp_footer(); ?>
</body>
</html>
