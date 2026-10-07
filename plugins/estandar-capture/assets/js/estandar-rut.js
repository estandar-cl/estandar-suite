(function () {
    // RUT chileno: formateo + validación módulo 11 (puerto del legacy).
    function cleanRut(rut) {
        return rut.replace(/[^0-9kK]/g, '').toUpperCase();
    }

    function formatRut(rut) {
        var body = rut.slice(0, -1);
        var dv = rut.slice(-1);
        return body.replace(/\B(?=(\d{3})+(?!\d))/g, '.') + '-' + dv;
    }

    function validarRut(rut) {
        rut = cleanRut(rut);
        if (rut.length < 8) return false;
        var body = rut.slice(0, -1);
        var dv = rut.slice(-1);
        var suma = 0;
        var multiplo = 2;
        for (var i = body.length - 1; i >= 0; i -= 1) {
            suma += multiplo * parseInt(body[i], 10);
            multiplo = multiplo < 7 ? multiplo + 1 : 2;
        }
        var res = 11 - (suma % 11);
        var dvCalc = res === 11 ? '0' : res === 10 ? 'K' : res.toString();
        return dv === dvCalc;
    }

    function normalizePhone(value) {
        return value.replace(/[^\d+]/g, '');
    }

    var phonePatterns = {
        CL: /^(\+?56)?[2-9]\d{8}$/,
        AR: /^(\+?54)?\d{10}$/,
        PE: /^(\+?51)?9\d{8}$/,
        MX: /^(\+?52)?\d{10}$/,
        ES: /^(\+?34)?[6-9]\d{8}$/,
        US: /^(\+?1)?\d{10}$/
    };

    function validarTelefono(value, country) {
        var normalized = normalizePhone(value);
        var pattern = phonePatterns[country] || /^\+?\d{8,15}$/;
        return pattern.test(normalized);
    }

    document.addEventListener('input', function (event) {
        var target = event.target;
        if (!target || !target.closest('form.estandar-form-v2')) return;

        if (target.classList.contains('estandar-rut-field')) {
            var rut = cleanRut(target.value);
            if (rut.length >= 8) {
                target.value = formatRut(rut);
                target.setCustomValidity(validarRut(rut) ? '' : 'RUT inválido');
            } else if (rut.length > 0) {
                target.setCustomValidity('RUT demasiado corto');
            } else {
                target.setCustomValidity('');
            }
        }

        if (target.classList.contains('estandar-phone-field')) {
            var country = target.dataset.country || 'CL';
            target.setCustomValidity(target.value === '' || validarTelefono(target.value, country) ? '' : 'Teléfono inválido');
        }
    });
})();
