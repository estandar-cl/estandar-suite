<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo esc_html($title); ?></title>
    <style>
        body{margin:0;background:#f7f5f1;color:#1d2327;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
        main{max-width:480px;margin:0 auto;padding:24px 16px}.box{background:#fff;border:1px solid #ddd8cf;border-radius:8px;padding:22px}h1{font-size:28px;margin:0 0 8px}p{color:#50575e}
    </style>
</head>
<body><main><div class="box"><h1><?php echo esc_html($title); ?></h1><p><?php echo esc_html($message); ?></p></div></main></body>
</html>

