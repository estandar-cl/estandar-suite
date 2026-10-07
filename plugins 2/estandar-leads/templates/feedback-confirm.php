<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Confirmar cambio</title>
    <style>
        body{margin:0;background:#f7f5f1;color:#1d2327;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
        main{max-width:480px;margin:0 auto;padding:24px 16px}
        .box{background:#fff;border:1px solid #ddd8cf;border-radius:8px;padding:22px}
        h1{font-size:28px;margin:0 0 12px}.lead{font-size:20px;margin:0 0 4px}.notice{font-size:18px;line-height:1.45;margin:22px 0;color:#1d2327}
        .btn{width:100%;border:0;border-radius:8px;background:#1d2327;color:#fff;padding:16px;font-size:18px;margin-top:12px}
        .cancel{display:block;text-align:center;border:1px solid #b8b5aa;border-radius:8px;background:#fff;color:#1d2327;text-decoration:none;padding:15px;font-size:18px;margin-top:10px}
    </style>
</head>
<body>
<main>
    <div class="box">
        <h1>Confirmar cambio</h1>
        <p class="lead"><?php echo esc_html($lead->name ?: 'Lead recibido'); ?></p>
        <?php if ($same_operator) : ?>
            <p class="notice">Ya clasificaste este lead como <?php echo esc_html($old_label); ?>. ¿Cambiar a <?php echo esc_html($new_label); ?>?</p>
        <?php else : ?>
            <p class="notice">Este lead ya fue clasificado por <?php echo esc_html($previous_operator); ?> como <?php echo esc_html($old_label); ?>. ¿Deseas cambiarlo a <?php echo esc_html($new_label); ?>?</p>
        <?php endif; ?>
        <form method="post">
            <input type="hidden" name="estandar_leads_nonce" value="<?php echo esc_attr(wp_create_nonce('estandar_leads_feedback_' . $lead->token_id)); ?>">
            <input type="hidden" name="status" value="<?php echo esc_attr($new_status); ?>">
            <input type="hidden" name="reason" value="<?php echo esc_attr($reason); ?>">
            <input type="hidden" name="is_valid_lead" value="<?php echo esc_attr(isset($signals['is_valid_lead']) && (int) $signals['is_valid_lead'] === 1 ? 'yes' : 'no'); ?>">
            <input type="hidden" name="invalid_reason" value="<?php echo esc_attr($signals['invalid_reason'] ?? ''); ?>">
            <input type="hidden" name="would_buy_again" value="<?php echo esc_attr($signals['would_buy_again'] ?? ''); ?>">
            <input type="hidden" name="quality" value="<?php echo esc_attr($signals['quality'] ?? ''); ?>">
            <input type="hidden" name="sales_stage" value="<?php echo esc_attr($signals['sales_stage'] ?? ''); ?>">
            <input type="hidden" name="context_gap" value="<?php echo esc_attr($signals['context_gap'] ?? 'NO'); ?>">
            <input type="hidden" name="contact_action" value="<?php echo esc_attr($signals['contact_action'] ?? ''); ?>">
            <input type="hidden" name="contact_result" value="<?php echo esc_attr($signals['contact_result'] ?? ''); ?>">
            <input type="hidden" name="confirm_override" value="1">
            <button class="btn" type="submit">Confirmar cambio</button>
        </form>
        <a class="cancel" href="<?php echo esc_url(Estandar_Leads_Tokens::url(isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '')); ?>">Cancelar</a>
    </div>
</main>
</body>
</html>
