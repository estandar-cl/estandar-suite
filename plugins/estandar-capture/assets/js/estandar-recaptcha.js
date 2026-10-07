(function () {
    function applyToken(token) {
        document.querySelectorAll('form.estandar-form-v2 .estandar-recaptcha-token').forEach(function (input) {
            input.value = token || '';
        });
    }

    function runRecaptcha() {
        if (
            typeof window.grecaptcha === 'undefined' ||
            !window.EstandarFormsRecaptcha ||
            !window.EstandarFormsRecaptcha.siteKey
        ) {
            return;
        }

        window.grecaptcha.ready(function () {
            window.grecaptcha
                .execute(window.EstandarFormsRecaptcha.siteKey, {
                    action: window.EstandarFormsRecaptcha.action || 'estandar_form_submit'
                })
                .then(applyToken)
                .catch(function () {
                    applyToken('');
                });
        });
    }

    document.addEventListener('DOMContentLoaded', runRecaptcha);
    document.addEventListener('focusin', function (event) {
        if (event.target && event.target.closest('form.estandar-form-v2')) {
            runRecaptcha();
        }
    });
})();
