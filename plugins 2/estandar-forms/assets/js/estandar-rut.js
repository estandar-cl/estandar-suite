(function () {
    const phonePatterns = {
        CL: /^(\+?56)?[2-9]\d{8}$/,
        AR: /^(\+?54)?\d{10}$/,
        PE: /^(\+?51)?9\d{8}$/,
        MX: /^(\+?52)?\d{10}$/,
        ES: /^(\+?34)?[6-9]\d{8}$/,
        US: /^(\+?1)?\d{10}$/
    };

    function cleanRut(rut) {
        return rut.replace(/[^0-9kK]/g, '').toUpperCase();
    }

    function formatRut(rut) {
        const body = rut.slice(0, -1);
        const dv = rut.slice(-1);
        return body.replace(/\B(?=(\d{3})+(?!\d))/g, '.') + '-' + dv;
    }

    function validarRut(rut) {
        rut = cleanRut(rut);

        if (rut.length < 8) {
            return false;
        }

        const body = rut.slice(0, -1);
        const dv = rut.slice(-1);
        let suma = 0;
        let multiplo = 2;

        for (let i = body.length - 1; i >= 0; i -= 1) {
            suma += multiplo * parseInt(body[i], 10);
            multiplo = multiplo < 7 ? multiplo + 1 : 2;
        }

        const res = 11 - (suma % 11);
        const dvCalc = res === 11 ? '0' : res === 10 ? 'K' : res.toString();

        return dv === dvCalc;
    }

    function normalizePhone(value) {
        return value.replace(/[^\d+]/g, '');
    }

    function validarTelefono(value, country) {
        const normalized = normalizePhone(value);
        const pattern = phonePatterns[country] || /^\+?\d{8,15}$/;
        return pattern.test(normalized);
    }

    document.addEventListener('input', function (event) {
        const target = event.target;

        if (target.classList.contains('estandar-rut-field')) {
            const rut = cleanRut(target.value);

            if (rut.length >= 8) {
                target.value = formatRut(rut);
                target.setCustomValidity(validarRut(rut) ? '' : 'RUT inválido');
            } else if (rut.length > 0) {
                // Short but non-empty: mark invalid so progressive forms don't advance
                target.setCustomValidity('RUT demasiado corto');
            } else {
                target.setCustomValidity('');
            }
        }

        if (target.classList.contains('estandar-phone-field')) {
            const country = target.dataset.country || 'CL';
            const message = target.dataset.phoneMessage || 'Telefono invalido';
            target.setCustomValidity(target.value === '' || validarTelefono(target.value, country) ? '' : message);
        }
    });

})();
