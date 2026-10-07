document.addEventListener('DOMContentLoaded', function () {
    var copyBtn = document.querySelector('.estandar-ss-copy-btn');
    if (copyBtn) {
        copyBtn.addEventListener('click', function () {
            var code = document.querySelector('.estandar-ss-token-result code');
            if (!code) return;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(code.textContent.trim());
            }
            copyBtn.textContent = '¡Copiado!';
            setTimeout(function () { copyBtn.textContent = 'Copiar enlace'; }, 2000);
        });
    }

    var genBtn = document.querySelector('.estandar-ss-generate-btn');
    if (genBtn) {
        genBtn.addEventListener('click', function () {
            var ta = document.querySelector('[name="estandar_ss_secret"]');
            if (!ta) return;
            var chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*()-_=+';
            var len = 32;
            var result = '';
            var arr = new Uint32Array(len);
            crypto.getRandomValues(arr);
            for (var i = 0; i < len; i++) {
                result += chars[arr[i] % chars.length];
            }
            ta.value = result;
        });
    }
});
