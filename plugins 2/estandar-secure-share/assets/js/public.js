document.addEventListener('DOMContentLoaded', function () {
    var form = document.querySelector('.estandar-ss-form');
    if (!form) return;

    var action = form.querySelector('input[name="estandar_ss_action"]');
    if (!action || action.value !== 'reveal') return;

    form.addEventListener('submit', function () {
        var btn = form.querySelector('.estandar-ss-btn-primary');
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Procesando...';
        }
    });
});
