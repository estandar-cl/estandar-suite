(function () {
    // Dependent select v3: puebla un select dependiente.
    //  1) DEPENDENT_INLINE_MAP_V1: si el campo trae `data-streets` (mapa
    //     proyecto→calles embebido por el servidor), se usa directo → sin AJAX,
    //     sin nonce y a prueba de caché (el nonce cacheado expiraba y dejaba el
    //     select vacío de forma intermitente).
    //  2) Fallback: endpoint AJAX ef_get_streets (legacy/registry).
    function findForm(ctx) {
        return ctx.closest('form.estandar-form-v2');
    }

    function parentValue(field) {
        var depends = field.getAttribute('data-depends-on') || '';
        if (!depends) return '';
        var f = field.closest('form.estandar-form-v2');
        var el = f ? f.querySelector('[name="' + depends + '"]') : null;
        return el ? (el.value || '') : '';
    }

    function normKey(s) {
        // DEPENDENT_ACCENTS_V1: quita acentos (paridad con remove_accents de PHP)
        // para que un value con ñ/tilde calce con la clave ascii del mapa.
        return String(s || '')
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .toLowerCase().replace(/[^a-z0-9]+/g, '');
    }

    function esc(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function fill(field, options) {
        var html = '<option value="">— Selecciona —</option>';
        (options || []).forEach(function (street) {
            var v = esc(street);
            html += '<option value="' + v + '">' + v + '</option>';
        });
        field.innerHTML = html;
    }

    function inlineOptions(field) {
        // GENERIC_DEPENDENT_MAP_V1: catálogo genérico (`data-options-map`),
        // p.ej. lotes por cámara; fallback al mapa de calles (`data-streets`).
        var raw = field.getAttribute('data-options-map') || field.getAttribute('data-streets') || '';
        if (!raw) return null;
        var map;
        try { map = JSON.parse(raw); } catch (e) { return null; }
        return map[normKey(parentValue(field))] || [];
    }

    function ajaxOptions(field) {
        var form = findForm(field);
        var nonceEl = form ? form.querySelector('.estandar-dependent-nonce') : null;
        var ajax = nonceEl ? nonceEl.getAttribute('data-ajax') : '';
        var nonce = nonceEl ? nonceEl.getAttribute('data-nonce') : '';
        var project = parentValue(field);
        if (!project || !ajax || !nonce) return;

        var xhr = new XMLHttpRequest();
        xhr.open('GET', ajax + '?action=ef_get_streets&project_id=' + encodeURIComponent(project) + '&nonce=' + encodeURIComponent(nonce));
        field.setAttribute('aria-busy', 'true');
        xhr.onload = function () {
            field.removeAttribute('aria-busy');
            var options = [];
            try {
                var data = JSON.parse(xhr.responseText);
                options = (data && data.success && data.data && data.data.streets) || [];
            } catch (e) {
                options = [];
            }
            fill(field, options);
        };
        xhr.onerror = function () {
            field.removeAttribute('aria-busy');
        };
        xhr.send();
    }

    function apply(field) {
        // DEPENDENT_RESET_V1: si el padre quedó vacío, limpiar el dependiente
        // (antes el fallback AJAX hacía `return` y dejaba las calles del
        // proyecto anterior).
        if (!parentValue(field)) {
            field.innerHTML = '<option value="">— Selecciona primero el campo anterior —</option>';
            return;
        }
        var opts = inlineOptions(field);
        if (opts !== null) { fill(field, opts); return; }
        ajaxOptions(field);
    }

    document.addEventListener('change', function (event) {
        var target = event.target;
        if (!target || !target.closest('form.estandar-form-v2')) return;
        var dependents = target.closest('form.estandar-form-v2').querySelectorAll('.estandar-dependent-select');
        dependents.forEach(function (dep) {
            if ((dep.getAttribute('data-depends-on') || '') === (target.getAttribute('name') || '')) {
                apply(dep);
            }
        });
    });

    // DEPENDENT_INIT_V1: init idempotente. Si el script (inline) corre después
    // de DOMContentLoaded, antes quedaba sin inicializar → el dependiente no se
    // poblaba al volver a la página. Se cubre ambos casos y evita doble init.
    function init() {
        document.querySelectorAll('form.estandar-form-v2 .estandar-dependent-select').forEach(function (dep) {
            if (dep.dataset.depInit) { return; }
            dep.dataset.depInit = '1';
            if (parentValue(dep)) apply(dep);
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
