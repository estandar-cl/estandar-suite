<?php
$settings = get_option('estandar_secure_share_settings', []);
$brand = esc_html($settings['brand_name'] ?? 'ESTANDAR');
?><!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><meta name="robots" content="noindex"><title>Demasiadas solicitudes</title></head>
<body style="font-family:-apple-system,BlinkMacSystemFont,sans-serif;text-align:center;padding:60px 20px;color:#555;"><h1>Demasiadas solicitudes</h1><p>Has excedido el límite de intentos. Intenta de nuevo más tarde.</p></body>
</html>
