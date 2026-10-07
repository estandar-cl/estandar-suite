<?php
$settings = get_option('estandar_secure_share_settings', []);
$brand = esc_html($settings['brand_name'] ?? 'ESTANDAR');
$tagline = esc_html($settings['brand_tagline'] ?? 'Security Solutions');
$secret = $secret ?? '';
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title><?php echo $brand; ?> — Clave revelada</title>
<?php wp_head(); ?>
</head>
<body class="estandar-ss-body">
<main class="estandar-ss-card">
    <header class="estandar-ss-header">
        <h1><?php echo $brand; ?></h1>
        <p class="estandar-ss-tagline"><?php echo $tagline; ?></p>
    </header>

    <div class="estandar-ss-content">
        <div class="estandar-ss-icon estandar-ss-icon-success">&#x2705;</div>
        <h2>Tu clave</h2>

        <div class="estandar-ss-secret-box">
            <code class="estandar-ss-secret" id="estandar-ss-secret"><?php echo esc_html($secret); ?></code>
            <button type="button" class="estandar-ss-btn estandar-ss-btn-copy" onclick="estandar_ss_copy()">
                Copiar
            </button>
        </div>

        <div class="estandar-ss-destroyed">
            <strong>&#x26A0;&#xFE0F; Esta clave ha sido destruida.</strong>
            No podrás verla de nuevo. Si cierras esta página, no podrás recuperarla.
        </div>
    </div>

    <footer class="estandar-ss-footer">
        <p><?php echo $brand; ?> — <?php echo $tagline; ?></p>
    </footer>
</main>

<script>
function estandar_ss_copy() {
    var el = document.getElementById('estandar-ss-secret');
    if (!el) return;
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(el.textContent).then(function () {
            var btn = document.querySelector('.estandar-ss-btn-copy');
            if (btn) { btn.textContent = '¡Copiado!'; btn.classList.add('copied'); }
        });
    } else {
        var range = document.createRange();
        range.selectNode(el);
        window.getSelection().removeAllRanges();
        window.getSelection().addRange(range);
        document.execCommand('copy');
        window.getSelection().removeAllRanges();
        var btn = document.querySelector('.estandar-ss-btn-copy');
        if (btn) { btn.textContent = '¡Copiado!'; btn.classList.add('copied'); }
    }
}
</script>
<?php wp_footer(); ?>
</body>
</html>
