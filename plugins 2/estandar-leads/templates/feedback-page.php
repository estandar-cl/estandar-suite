<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Evaluar lead</title>
    <style>
        body{margin:0;background:#f7f5f1;color:#1d2327;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
        .page-head{max-width:560px;margin:0 auto;padding:14px 16px 0;display:flex;justify-content:space-between;align-items:baseline;gap:12px}
        .page-brand{font-size:13px;font-weight:700;color:#50575e;text-transform:uppercase;letter-spacing:.4px}
        .page-studio{font-size:11px;color:#a7aaad;text-decoration:none;white-space:nowrap}.page-studio:hover{color:#50575e;text-decoration:underline}
        main{max-width:560px;margin:0 auto;padding:14px 16px 24px}.box{background:#fff;border:1px solid #ddd8cf;border-radius:8px;padding:22px}
        h1{font-size:28px;margin:0 0 14px}
        .context{border:1px solid #e3ded4;border-radius:8px;margin:20px 0;padding:14px;background:#fbfaf7}.context h2,.section-title{font-size:18px;margin:0 0 12px}
        .row{border-top:1px solid #ece7dc;padding:9px 0}.row:first-of-type{border-top:0}.k{display:block;color:#646970;font-size:12px;text-transform:uppercase;letter-spacing:.4px}.v{display:block;font-size:15px;margin-top:2px;white-space:pre-wrap}
	        fieldset{border:0;margin:22px 0 0;padding:0}legend{font-size:18px;font-weight:700;margin-bottom:10px}.option{display:block;border:1px solid #b8b5aa;border-radius:8px;padding:13px;margin:9px 0;font-size:16px}
	        .quick{background:#f8fbff;border:1px solid #c9ddf2;border-radius:8px;padding:14px;margin:0 0 20px}.quick legend{font-size:17px}.quick .hint{margin:0 0 10px;color:#50575e;font-size:14px}
        .invalid-reasons{margin:8px 0 0 18px}textarea,select,input[type=text]{width:100%;border:1px solid #b8b5aa;border-radius:8px;font-size:15px;padding:10px;box-sizing:border-box}textarea{min-height:76px;margin-top:8px}
        [data-step]{display:none}[data-step].is-visible{display:block}.muted{color:#646970;font-size:14px;margin:6px 0 0}
        .btn{width:100%;border:0;border-radius:8px;background:#1d2327;color:#fff;padding:16px;font-size:18px;margin-top:18px}
    </style>
</head>
<body>
<header class="page-head">
    <span class="page-brand"><?php echo esc_html(get_bloginfo('name')); ?></span>
    <a class="page-studio" href="https://www.estandar.cl" target="_blank" rel="noopener">Estandar Studio</a>
</header>
<main>
    <div class="box">
        <h1>Evaluar lead</h1>

        <?php if (!empty($context_rows)) : ?>
            <section class="context" aria-label="Información para evaluar">
                <h2>Información del lead</h2>
                <?php foreach ($context_rows as $label => $value) : ?>
                    <div class="row">
                        <span class="k"><?php echo esc_html($label); ?></span>
                        <span class="v"><?php echo esc_html((string) $value); ?></span>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

	        <form method="post">
	            <input type="hidden" name="estandar_leads_nonce" value="<?php echo esc_attr(wp_create_nonce('estandar_leads_feedback_' . $lead->token_id)); ?>">

	            <fieldset class="quick is-visible" data-step="contact">
	                <legend>Contacto</legend>
	                <label class="option"><input type="radio" name="contact_action" value="phone" required> Llamado</label>
	                <label class="option"><input type="radio" name="contact_action" value="whatsapp" required> WhatsApp</label>
	                <label class="option"><input type="radio" name="contact_action" value="email" required> Email</label>
	                <label class="option"><input type="radio" name="contact_action" value="none" required> Sin contacto</label>
	            </fieldset>

	            <fieldset data-step="conversation">
	                <legend>¿Hubo conversación?</legend>
	                <label class="option"><input type="radio" name="conversation_happened" value="yes"> Sí</label>
	                <label class="option"><input type="radio" name="conversation_happened" value="no"> No</label>
	            </fieldset>

            <fieldset data-step="target">
                <legend>¿Corresponde al público objetivo?</legend>
                <label class="option"><input type="radio" name="is_valid_lead" value="yes"> Sí</label>
                <label class="option"><input type="radio" name="is_valid_lead" value="no"> No</label>
                <div class="invalid-reasons" data-step="invalid_reason">
                    <p style="margin:12px 0 8px;color:#646970;">Motivo:</p>
                    <?php foreach ($invalid_reason_labels as $reason_key => $reason_label) : ?>
                        <label class="option" style="font-size:15px;"><input type="radio" name="invalid_reason" value="<?php echo esc_attr($reason_key); ?>"> <?php echo esc_html($reason_label); ?></label>
                    <?php endforeach; ?>
                </div>
            </fieldset>

            <fieldset data-step="result">
                <legend>Resultado</legend>
                <label class="option"><input type="radio" name="commercial_result" value="info_only"> Solo información</label>
                <label class="option"><input type="radio" name="commercial_result" value="opportunity"> Oportunidad</label>
                <label class="option"><input type="radio" name="commercial_result" value="next_step"> Siguiente paso acordado</label>
                <label class="option"><input type="radio" name="commercial_result" value="customer"> Cliente</label>
                <label class="option"><input type="radio" name="commercial_result" value="lost"> Perdido</label>
            </fieldset>

            <fieldset data-step="need">
                <legend>Necesidad principal</legend>
                <?php if (!empty($need_options)) : ?>
                    <?php foreach ($need_options as $need_option) : ?>
                        <label class="option"><input type="radio" name="primary_need" value="<?php echo esc_attr($need_option); ?>"> <?php echo esc_html($need_option); ?></label>
                    <?php endforeach; ?>
                    <label class="option"><input type="radio" name="primary_need" value="__other"> Otro</label>
                    <input type="text" name="primary_need_text" placeholder="Especificar necesidad">
                <?php else : ?>
                    <p class="muted">Proyecto sin opciones configuradas.</p>
                    <input type="hidden" name="primary_need" value="__other">
                    <input type="text" name="primary_need_text" placeholder="Escribir necesidad principal">
                <?php endif; ?>
            </fieldset>

            <fieldset data-step="more">
                <legend>¿Quieres más leads como este?</legend>
                <label class="option"><input type="radio" name="would_buy_again" value="yes"> Sí</label>
                <label class="option"><input type="radio" name="would_buy_again" value="no"> No</label>
            </fieldset>

            <fieldset data-step="missing">
                <legend><?php echo esc_html($context_gap_template['question'] ?? '¿Faltó información?'); ?></legend>
                <?php foreach (($context_gap_template['options'] ?? array()) as $gap_key => $gap_label) : ?>
                    <label class="option"><input type="checkbox" name="context_gap[]" value="<?php echo esc_attr($gap_key); ?>"> <?php echo esc_html($gap_label); ?></label>
                <?php endforeach; ?>
            </fieldset>

            <label style="display:block;margin-top:20px;font-size:16px;">Comentario opcional<textarea name="reason"></textarea></label>
            <button class="btn" type="submit">Guardar evaluación</button>
        </form>
    </div>
</main>
<script>
(function(){
    const form = document.querySelector('form');
    const steps = {
        conversation: document.querySelector('[data-step="conversation"]'),
        target: document.querySelector('[data-step="target"]'),
        invalid: document.querySelector('[data-step="invalid_reason"]'),
        result: document.querySelector('[data-step="result"]'),
        need: document.querySelector('[data-step="need"]'),
        more: document.querySelector('[data-step="more"]'),
        missing: document.querySelector('[data-step="missing"]')
    };
    function value(name) {
        const el = form.querySelector('input[name="'+name+'"]:checked');
        return el ? el.value : '';
    }
    function setVisible(step, show) {
        if (!step) return;
        step.classList.toggle('is-visible', show);
        step.querySelectorAll('input').forEach(function(input){ input.disabled = !show; });
    }
    function sync() {
        const contact = value('contact_action');
        const talked = value('conversation_happened');
        const valid = value('is_valid_lead');
        const hasResult = value('commercial_result') !== '';
        setVisible(steps.conversation, contact !== '' && contact !== 'none');
        setVisible(steps.target, contact !== '' && contact !== 'none' && talked === 'yes');
        setVisible(steps.invalid, valid === 'no');
        setVisible(steps.result, valid === 'yes');
        setVisible(steps.need, valid === 'yes' && hasResult);
        setVisible(steps.more, valid === 'yes' && hasResult);
        setVisible(steps.missing, valid === 'yes' && hasResult);
    }
    form.addEventListener('change', sync);
    sync();
})();
</script>
</body>
</html>
