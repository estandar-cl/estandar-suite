/**
 * Estandar Forms — Form Engine (flujo progresivo + condiciones dinámicas).
 *
 * Fuente canónica: actions_json.flow / actions_json.conditions /
 * actions_json.validators, embebidos por form-render.php en un
 * <script type="application/json" class="estandar-form-engine-config">.
 *
 * Genérico por contrato: cero lógica por slug/ID/nombre de formulario.
 * Los formularios sin config quedan intactos (progressive enhancement:
 * sin JS se muestra el formulario completo en un solo paso).
 *
 * Debe encolarse ANTES de estandar-forms-public.js: su listener de submit
 * en fase de captura intercepta el Enter implícito cuando el flujo
 * progresivo aún no está completo.
 */
(function () {
    'use strict';

    /* ── Núcleo puro (sin DOM) ─────────────────────────────────────────── */

    function matchesWhen(when, getValue) {
        if (!when || !when.field) {
            return true;
        }

        var actual = String(getValue(when.field) == null ? '' : getValue(when.field)).trim();
        var operator = when.operator || 'equals';
        var expected = when.value;

        switch (operator) {
            case 'in':
                return Array.isArray(expected) && expected.indexOf(actual) !== -1;
            case 'not_in':
                return !(Array.isArray(expected) && expected.indexOf(actual) !== -1);
            case 'not_equals':
                return actual !== String(expected == null ? '' : expected);
            case 'contains':
                return expected !== '' && expected != null && actual.indexOf(String(expected)) !== -1;
            case 'not_empty':
                return actual !== '';
            case 'empty':
                return actual === '';
            case 'equals':
            default:
                return actual === String(expected == null ? '' : expected);
        }
    }

    // Reduce todas las condiciones a un mapa de efectos por campo.
    function reduceConditions(conditions, getValue) {
        var effects = { visibility: {}, required: {}, clear: {}, meta: {} };

        (conditions || []).forEach(function (condition) {
            if (!condition) {
                return;
            }
            var branch = matchesWhen(condition.when || {}, getValue)
                ? (condition.then || [])
                : (condition['else'] || []);

            branch.forEach(function (action) {
                if (!action || !action.action) {
                    return;
                }
                switch (action.action) {
                    case 'show':
                        effects.visibility[action.target] = true;
                        break;
                    case 'hide':
                        effects.visibility[action.target] = false;
                        break;
                    case 'require':
                        effects.required[action.target] = true;
                        break;
                    case 'optional':
                        effects.required[action.target] = false;
                        break;
                    case 'clear':
                        effects.clear[action.target] = true;
                        break;
                    case 'set_lead_quality':
                        effects.meta.lead_quality = String(action.value == null ? '' : action.value);
                        break;
                    case 'set_lead_reason':
                        effects.meta.lead_reason = String(action.value == null ? '' : action.value);
                        break;
                }
            });
        });

        return effects;
    }

    // Visibilidad de cada paso según visible_when.step_completed.
    function stepVisibility(steps, completed) {
        var visible = {};
        (steps || []).forEach(function (step) {
            if (!step || !step.id) {
                return;
            }
            var gate = step.visible_when && step.visible_when.step_completed;
            visible[step.id] = !gate || completed.indexOf(gate) !== -1;
        });
        return visible;
    }

    /* ── Capa DOM ──────────────────────────────────────────────────────── */

    function cssEscape(value) {
        if (window.CSS && typeof window.CSS.escape === 'function') {
            return window.CSS.escape(value);
        }
        return String(value).replace(/["\\]/g, '\\$&');
    }

    function readConfig(form) {
        var node = form.querySelector('script.estandar-form-engine-config');
        if (!node) {
            return null;
        }
        try {
            var config = JSON.parse(node.textContent || 'null');
            return config && typeof config === 'object' ? config : null;
        } catch (error) {
            return null;
        }
    }

    function fieldInputs(form, name) {
        return Array.prototype.slice.call(
            form.querySelectorAll('[name="' + cssEscape(name) + '"]')
        ).filter(function (input) {
            return input.type !== 'hidden';
        });
    }

    function wrapperFor(form, name) {
        var inputs = fieldInputs(form, name);
        return inputs.length ? inputs[0].closest('.estandar-form-field') : null;
    }

    function readFieldValue(form, name) {
        var inputs = fieldInputs(form, name);
        if (!inputs.length) {
            return '';
        }
        var first = inputs[0];
        if (first.type === 'checkbox') {
            return first.checked ? '1' : '';
        }
        if (first.type === 'radio') {
            var checked = form.querySelector('input[name="' + cssEscape(name) + '"]:checked');
            return checked ? checked.value : '';
        }
        return String(first.value == null ? '' : first.value).trim();
    }

    // RUT validation helpers (módulo 11)
    function cleanRut(rut) {
        return rut.replace(/[^0-9kK]/g, '').toUpperCase();
    }
    function isRutValid(rut) {
        var cleaned = cleanRut(rut);
        if (cleaned.length < 8) return false;
        var body = cleaned.slice(0, -1);
        var dv = cleaned.slice(-1);
        if (!/^\d+$/.test(body)) return false;
        var suma = 0, mul = 2;
        for (var i = body.length - 1; i >= 0; i--) {
            suma += mul * parseInt(body[i], 10);
            mul = mul < 7 ? mul + 1 : 2;
        }
        var res = 11 - (suma % 11);
        var dvCalc = res === 11 ? '0' : res === 10 ? 'K' : String(res);
        return dv === dvCalc;
    }

    // Name validation: at least two words, minimum length
    function isValidName(val) {
        var trimmed = val.trim();
        if (trimmed.length < 3) return false;
        var words = trimmed.split(/\s+/);
        return words.length >= 2;
    }

    // Mark a field wrapper with valid/invalid styling + error message.
    function markField(form, name, valid) {
        var wrapper = wrapperFor(form, name);
        if (!wrapper) return;
        if (valid) {
            wrapper.classList.remove('has-error');
            var existing = wrapper.querySelector('.estandar-field-error');
            if (existing) existing.remove();
        } else {
            wrapper.classList.add('has-error');
            // Add error message if not already present
            if (!wrapper.querySelector('.estandar-field-error')) {
                var msg = getFieldHint(name);
                var err = document.createElement('p');
                err.className = 'estandar-field-error';
                err.textContent = msg;
                err.style.cssText = 'border-left:3px solid #d93025;margin:4px 0 0;padding:4px 0 4px 10px;font-size:13px;color:#b42318;line-height:1.4;';
                var input = wrapper.querySelector('input, select, textarea');
                if (input) {
                    input.parentNode.insertBefore(err, input.nextSibling);
                } else {
                    wrapper.appendChild(err);
                }
            }
        }
    }

    // Human-readable error message per field type.
    function getFieldHint(name) {
        if (name.indexOf('nombre') !== -1) return 'Ingresa nombre y apellido (al menos dos palabras).';
        if (name.indexOf('rut') !== -1) return 'Ingresa un RUT chileno válido (ej: 12.345.678-5).';
        if (name === 'email') return 'Ingresa un correo electrónico válido.';
        if (name.indexOf('telefono') !== -1) return 'Ingresa un número de teléfono válido.';
        return 'Completa este campo correctamente.';
    }

    function clearFieldValue(form, name) {
        fieldInputs(form, name).forEach(function (input) {
            if (input.type === 'checkbox' || input.type === 'radio') {
                input.checked = false;
            } else if (input.tagName === 'SELECT') {
                input.selectedIndex = 0;
                if (input.value !== '') {
                    input.value = '';
                }
            } else {
                input.value = '';
            }
        });
    }

    function clearFieldErrors(wrapper) {
        if (!wrapper) {
            return;
        }
        wrapper.querySelectorAll('.estandar-field-error.is-client-error').forEach(function (error) {
            error.remove();
        });
        wrapper.classList.remove('has-error', 'has-client-error');
        wrapper.querySelectorAll('[data-estandar-client-invalid="1"]').forEach(function (input) {
            input.removeAttribute('aria-invalid');
            input.removeAttribute('data-estandar-client-invalid');
            input.removeAttribute('aria-describedby');
        });
    }

    function defaultMessage(input) {
        if (input.validity.valueMissing) {
            if (input.tagName === 'SELECT' || input.type === 'checkbox' || input.type === 'radio') {
                return 'Debes seleccionar una opción.';
            }
            return 'Este campo es obligatorio.';
        }
        if (input.validity.typeMismatch && input.type === 'email') {
            return 'Ingresa un correo electrónico válido.';
        }
        if (input.validationMessage) {
            return input.validationMessage;
        }
        return 'Revisa este campo.';
    }

    function addFieldError(wrapper, input, message, index) {
        var id = (input.id || input.name || 'field') + '-engine-error-' + index;

        if (wrapper) {
            wrapper.classList.add('has-error', 'has-client-error');
        }

        input.setAttribute('aria-invalid', 'true');
        input.setAttribute('data-estandar-client-invalid', '1');
        input.setAttribute('aria-describedby', id);

        var error = document.createElement('p');
        error.className = 'estandar-field-error is-client-error';
        error.id = id;
        error.setAttribute('role', 'alert');
        error.setAttribute('aria-live', 'polite');
        error.textContent = message;

        if (wrapper) {
            wrapper.appendChild(error);
        }
    }

    function focusField(input) {
        var yOffset = -100;
        var y = input.getBoundingClientRect().top + window.pageYOffset + yOffset;
        window.scrollTo({ top: Math.max(0, y), behavior: 'smooth' });
        window.setTimeout(function () {
            input.focus({ preventScroll: true });
        }, 250);
    }

    function createEngine(form, config) {
        var steps = (config.flow && Array.isArray(config.flow.steps)) ? config.flow.steps : [];
        var progressive = steps.length > 0 && (!config.flow.mode || config.flow.mode === 'progressive');
        var conditions = Array.isArray(config.conditions) ? config.conditions : [];
        var validators = Array.isArray(config.validators) ? config.validators : [];

        var completed = [];
        var fieldStep = {};
        var messageFor = {};
        var knownFields = {};

        steps.forEach(function (step) {
            (step.fields || []).forEach(function (name) {
                fieldStep[name] = step.id;
                knownFields[name] = true;
            });
        });
        conditions.forEach(function (condition) {
            (condition.then || []).concat(condition['else'] || []).forEach(function (action) {
                if (action && action.target) {
                    knownFields[action.target] = true;
                }
            });
        });
        validators.forEach(function (validator) {
            if (validator && validator.field && validator.message) {
                messageFor[validator.field] = validator.message;
            }
            if (validator && validator.field && validator.rule === 'required') {
                fieldInputs(form, validator.field).forEach(function (input) {
                    input.dataset.estandarBaseRequired = '1';
                });
            }
        });

        function getValue(name) {
            return readFieldValue(form, name);
        }

        function stepOf(id) {
            for (var i = 0; i < steps.length; i++) {
                if (steps[i].id === id) {
                    return steps[i];
                }
            }
            return null;
        }

        // Paso gateado por S: el que declara visible_when.step_completed === S.id
        function gatedBy(stepId) {
            for (var i = 0; i < steps.length; i++) {
                var gate = steps[i].visible_when && steps[i].visible_when.step_completed;
                if (gate === stepId) {
                    return steps[i];
                }
            }
            return null;
        }

        function setFieldVisible(name, visible, requiredOverride) {
            var wrapper = wrapperFor(form, name);
            if (!wrapper) {
                return;
            }

            wrapper.style.display = visible ? '' : 'none';
            wrapper.classList.toggle('is-hidden', !visible);
            wrapper.setAttribute('aria-hidden', visible ? 'false' : 'true');

            fieldInputs(form, name).forEach(function (input) {
                if (input.dataset.estandarBaseRequired === undefined) {
                    input.dataset.estandarBaseRequired = input.required ? '1' : '0';
                }
                input.disabled = !visible;
                var base = input.dataset.estandarBaseRequired === '1';
                var effective = requiredOverride === undefined ? base : requiredOverride;
                input.required = visible && effective;
                if (!visible) {
                    input.setCustomValidity('');
                }
            });

            if (!visible) {
                clearFieldErrors(wrapper);
            }
        }

        function syncMetaInput(name, value) {
            var input = form.querySelector('input[type="hidden"][name="' + cssEscape(name) + '"][data-estandar-engine-meta="1"]');
            if (value) {
                if (!input) {
                    input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = name;
                    input.dataset.estandarEngineMeta = '1';
                    form.appendChild(input);
                }
                input.value = value;
            } else if (input) {
                input.value = '';
            }
        }

        function stepActionsNode(stepId) {
            return form.querySelector('[data-estandar-step-actions="' + cssEscape(stepId) + '"]');
        }

        function ensureContinueButtons() {
            steps.forEach(function (step) {
                if (!gatedBy(step.id) || stepActionsNode(step.id)) {
                    return;
                }
                var lastWrapper = null;
                (step.fields || []).forEach(function (name) {
                    var wrapper = wrapperFor(form, name);
                    if (wrapper) {
                        lastWrapper = wrapper;
                    }
                });
                if (!lastWrapper || !lastWrapper.parentNode) {
                    return;
                }

                var holder = document.createElement('div');
                holder.className = 'estandar-form-field estandar-col-span-3 estandar-form-step-actions';
                holder.setAttribute('data-estandar-step-actions', step.id);

                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'estandar-form-submit estandar-form-step-continue';
                button.textContent = step.next_label || 'Continuar';
                button.addEventListener('click', function () {
                    advanceFrom(step.id);
                });

                holder.appendChild(button);
                lastWrapper.parentNode.insertBefore(holder, lastWrapper.nextSibling);
            });
        }

        function setNodeVisible(node, visible) {
            if (!node) {
                return;
            }
            node.hidden = !visible;
            node.classList.toggle('is-hidden', !visible);
            node.setAttribute('aria-hidden', visible ? 'false' : 'true');
            node.querySelectorAll('button').forEach(function (button) {
                button.disabled = !visible;
            });
        }

        function allStepsVisible(stepVis) {
            for (var i = 0; i < steps.length; i++) {
                if (!stepVis[steps[i].id]) {
                    return false;
                }
            }
            return true;
        }

        function apply() {
            var effects = reduceConditions(conditions, getValue);

            // STEP VALIDATION must run BEFORE field visibility so
            // auto-advance/regression gates are current.
            if (progressive) {
                steps.forEach(function (step) {
                    var gated = gatedBy(step.id);
                    var node = stepActionsNode(step.id);
                    setNodeVisible(node, false);
                });

                completed = [];
                for (var i = 0; i < steps.length; i++) {
                    var step = steps[i];
                    var allValid = (step.fields || []).every(function (name) {
                        var val = readFieldValue(form, name);
                        if (val === '') {
                            markField(form, name, false);
                            return false;
                        }
                        // Name: at least 2 words (nombre + apellido)
                        if (name.indexOf('nombre') !== -1) {
                            if (!isValidName(val)) {
                                markField(form, name, false);
                                return false;
                            }
                        }
                        // RUT: inline validation (módulo 11)
                        if (name.indexOf('rut') !== -1) {
                            if (!isRutValid(val)) {
                                markField(form, name, false);
                                return false;
                            }
                        }
                        // Phone: basic format via native checkValidity
                        var inputs = fieldInputs(form, name);
                        if (inputs.length && !inputs[0].checkValidity()) {
                            markField(form, name, false);
                            return false;
                        }
                        markField(form, name, true);
                        return true;
                    });
                    if (allValid) {
                        completed.push(step.id);
                    } else {
                        break;
                    }
                }
            }

            var stepVis = stepVisibility(steps, completed);

            Object.keys(knownFields).forEach(function (name) {
                var stepId = fieldStep[name];
                var stepOk = !progressive || !stepId || !!stepVis[stepId];
                var condVisible = Object.prototype.hasOwnProperty.call(effects.visibility, name)
                    ? effects.visibility[name]
                    : true;
                var visible = stepOk && condVisible;
                var requiredOverride = Object.prototype.hasOwnProperty.call(effects.required, name)
                    ? effects.required[name]
                    : undefined;

                setFieldVisible(name, visible, requiredOverride);

                if (effects.clear[name] && readFieldValue(form, name) !== '') {
                    clearFieldValue(form, name);
                }
            });

            syncMetaInput('lead_quality', effects.meta.lead_quality || '');
            syncMetaInput('lead_reason', effects.meta.lead_reason || '');

            if (progressive) {
                form.querySelectorAll('.estandar-form-actions:not(.estandar-form-step-actions)').forEach(function (node) {
                    setNodeVisible(node, allStepsVisible(stepVis));
                });
            }

            return { effects: effects, stepVis: stepVis };
        }

        function validateStep(stepId) {
            var step = stepOf(stepId);
            if (!step) {
                return true;
            }

            var invalid = [];
            (step.fields || []).forEach(function (name) {
                var wrapper = wrapperFor(form, name);
                clearFieldErrors(wrapper);
                fieldInputs(form, name).forEach(function (input) {
                    if (input.disabled || invalid.some(function (item) { return item.input.name === input.name; })) {
                        return;
                    }
                    if (!input.checkValidity()) {
                        invalid.push({ input: input, wrapper: wrapper, name: name });
                    }
                });
            });

            invalid.forEach(function (item, index) {
                var message = messageFor[item.name] || defaultMessage(item.input);
                addFieldError(item.wrapper, item.input, message, index);
            });

            if (invalid.length) {
                focusField(invalid[0].input);
                return false;
            }

            return true;
        }

        function advanceFrom(stepId) {
            if (!validateStep(stepId)) {
                return false;
            }
            if (completed.indexOf(stepId) === -1) {
                completed.push(stepId);
            }
            var result = apply();
            var gated = gatedBy(stepId);
            if (gated && result.stepVis[gated.id]) {
                var firstName = (gated.fields || [])[0];
                var inputs = firstName ? fieldInputs(form, firstName) : [];
                if (inputs.length) {
                    focusField(inputs[0]);
                }
            }
            return true;
        }

        // Frontera actual: primer paso visible cuyo paso gateado sigue oculto.
        function frontierStep() {
            var stepVis = stepVisibility(steps, completed);
            for (var i = 0; i < steps.length; i++) {
                var gated = gatedBy(steps[i].id);
                if (stepVis[steps[i].id] && gated && !stepVis[gated.id]) {
                    return steps[i];
                }
            }
            return null;
        }

        return {
            apply: apply,
            advanceFrom: advanceFrom,
            frontierStep: frontierStep,
            ensureContinueButtons: ensureContinueButtons,
            isProgressive: function () {
                return progressive;
            },
            isComplete: function () {
                return allStepsVisible(stepVisibility(steps, completed));
            }
        };
    }

    /* ── Bootstrap ─────────────────────────────────────────────────────── */

    var engines = new WeakMap();

    function bindEngine(form) {
        if (engines.has(form)) {
            engines.get(form).apply();
            return;
        }

        var config = readConfig(form);
        if (!config) {
            return;
        }

        var engine = createEngine(form, config);
        engines.set(form, engine);

        engine.ensureContinueButtons();
        engine.apply();

        form.addEventListener('change', function () {
            // Defer to microtask so engine runs AFTER estandar-conditional.js
            // which also listens on 'change' and sets style.display inline.
            Promise.resolve().then(function () { engine.apply(); });
        });
        form.addEventListener('input', function () {
            Promise.resolve().then(function () { engine.apply(); });
        });
        form.addEventListener('reset', function () {
            window.setTimeout(function () {
                engine.apply();
            }, 0);
        });
    }

    function bindAll() {
        document.querySelectorAll('form.estandar-form[data-estandar-engine="1"]').forEach(bindEngine);
    }

    // Guardia de submit implícito (Enter): mientras el flujo progresivo no esté
    // completo, avanza el paso en vez de enviar. Registrado antes que el
    // listener de estandar-forms-public.js (este script se encola primero).
    document.addEventListener('submit', function (event) {
        var form = event.target && event.target.closest ? event.target.closest('form.estandar-form') : null;
        if (!form || !engines.has(form)) {
            return;
        }
        var engine = engines.get(form);
        if (!engine.isProgressive() || engine.isComplete()) {
            return;
        }
        event.preventDefault();
        event.stopImmediatePropagation();
        var frontier = engine.frontierStep();
        if (frontier) {
            engine.advanceFrom(frontier.id);
        }
    }, true);

    bindAll();
    document.addEventListener('DOMContentLoaded', bindAll);
    document.addEventListener('estandarPopupOpened', bindAll);

    // Hook de test (inerte en producción): expone el núcleo puro para la
    // suite Node (tests/run-form-engine-browser.test.js).
    if (window.__ESTANDAR_FORMS_ENGINE_TEST__) {
        window.__ESTANDAR_FORMS_ENGINE_TEST__.matchesWhen = matchesWhen;
        window.__ESTANDAR_FORMS_ENGINE_TEST__.reduceConditions = reduceConditions;
        window.__ESTANDAR_FORMS_ENGINE_TEST__.stepVisibility = stepVisibility;
    }
})();
