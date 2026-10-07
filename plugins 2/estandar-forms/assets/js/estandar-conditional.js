(function () {
    function escapeName(name) {
        if (window.CSS && typeof window.CSS.escape === 'function') {
            return window.CSS.escape(name);
        }

        return name.replace(/["\\]/g, '\\$&');
    }

    function getFieldValue(form, field) {
        if (!field) {
            return '';
        }

        if (field.type === 'checkbox') {
            return field.checked ? '1' : '';
        }

        if (field.type === 'radio') {
            const checked = form.querySelector(`input[name="${escapeName(field.name)}"]:checked`);
            return checked ? checked.value : '';
        }

        return (field.value || '').toString().trim();
    }

    function matchesCondition(actual, operator, expected) {
        switch (operator) {
            case 'not_equals':
                return actual !== expected;
            case 'contains':
                return expected !== '' && actual.indexOf(expected) !== -1;
            case 'not_empty':
                return actual !== '';
            case 'empty':
                return actual === '';
            case 'equals':
            default:
                return actual === expected;
        }
    }

    function updateConditionalFields(form) {
        form.querySelectorAll('.estandar-form-field[data-conditional-field], .estandar-form-incidents[data-conditional-field]').forEach(function (wrapper) {
            const sourceName = wrapper.dataset.conditionalField || '';
            const operator = wrapper.dataset.conditionalOperator || 'equals';
            const expected = wrapper.dataset.conditionalValue || '';
            const source = form.querySelector(`[name="${escapeName(sourceName)}"]`);
            const actual = getFieldValue(form, source);
            const active = matchesCondition(actual, operator, expected);

            wrapper.hidden = !active;
            wrapper.classList.toggle('is-hidden', !active);

            wrapper.querySelectorAll('input, select, textarea').forEach(function (input) {
                if (input.type === 'hidden') {
                    return;
                }

                if (!input.dataset.originalRequired) {
                    input.dataset.originalRequired = input.required ? '1' : '0';
                }

                input.disabled = !active;
                input.required = active && input.dataset.originalRequired === '1';
                if (!active) {
                    input.setCustomValidity('');
                }
            });
        });
    }

    function bindConditionalLogic(form) {
        if (form.dataset.estandarConditionalBound === '1') {
            updateConditionalFields(form);
            return;
        }

        form.dataset.estandarConditionalBound = '1';
        updateConditionalFields(form);

        form.addEventListener('change', function () {
            updateConditionalFields(form);
        });

        form.addEventListener('input', function () {
            updateConditionalFields(form);
        });
    }

    function bindAllForms() {
        document.querySelectorAll('.estandar-form').forEach(bindConditionalLogic);
    }

    bindAllForms();
    document.addEventListener('DOMContentLoaded', bindAllForms);
    document.addEventListener('estandarPopupOpened', bindAllForms);
})();
