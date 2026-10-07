(function () {
    const attributionFields = [
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
	        'utm_content',
	        'gclid',
	        'gbraid',
	        'wbraid',
	        'fbclid',
	        'meta_fbc',
	        'meta_fbp',
	        'ttclid',
	        'msclkid',
	        'campaign_id',
	        'adgroup_id',
	        'asset_group_id',
	        'creative_id',
	        'keyword',
	        'match_type',
	        'network',
	        'device',
	        'placement',
	        'campaign_type',
	        'landing_url',
        'referrer',
        'session_id',
        'current_page_url'
    ];

    function getCookie(name) {
        const parts = String(document.cookie || '').split(';');
        for (let i = 0; i < parts.length; i++) {
            const pair = parts[i].split('=');
            if (decodeURIComponent(String(pair.shift() || '').trim()) === name) {
                return decodeURIComponent(pair.join('=') || '');
            }
        }
        return '';
    }

    function getStoredValue(key) {
        const keys = [key, 'ea_' + key, 'estandar_' + key, 'estandar_attribution_' + key];
        for (let i = 0; i < keys.length; i++) {
            const name = keys[i];
            const cookieValue = getCookie(name);
            if (cookieValue) {
                return cookieValue;
            }
            try {
                const localValue = window.localStorage ? window.localStorage.getItem(name) : '';
                if (localValue) {
                    return localValue;
                }
            } catch (error) {}
            try {
                const sessionValue = window.sessionStorage ? window.sessionStorage.getItem(name) : '';
                if (sessionValue) {
                    return sessionValue;
                }
            } catch (error) {}
        }
        return getAttributionEngineValue(key);
    }

    // Single source of truth: estandar-attribution.js owns capture/persistence/restoration.
    // Reuse its live state instead of re-implementing storage parsing here.
    function getAttributionEngineValue(key) {
        const live = window.estandarAttribution;
        if (live) {
            if (live.last_touch && live.last_touch[key]) {
                return live.last_touch[key];
            }
            if (live.first_touch && live.first_touch[key]) {
                return live.first_touch[key];
            }
        }
        // Script-order safety net: read the engine's own persisted JSON directly.
        const last = readAttributionJson('estandar_attribution_last_touch');
        if (last && last[key]) {
            return last[key];
        }
        const first = readAttributionJson('estandar_attribution_first_touch');
        if (first && first[key]) {
            return first[key];
        }
        return '';
    }

    function readAttributionJson(storageKey) {
        try {
            return window.localStorage ? JSON.parse(window.localStorage.getItem(storageKey) || 'null') : null;
        } catch (error) {
            return null;
        }
    }

    function getAttributionValue(field, params) {
        const aliases = {
            campaign_id: ['gad_campaignid', 'campaignid'],
            adgroup_id: ['adgroupid'],
            asset_group_id: ['assetgroupid'],
            creative_id: ['creative'],
            match_type: ['matchtype']
        };
        let fromUrl = params.get(field);
        if (!fromUrl && aliases[field]) {
            aliases[field].some(function (alias) {
                fromUrl = params.get(alias);
                return !!fromUrl;
            });
        }
        if (fromUrl) {
            return fromUrl;
        }
        if (field === 'session_id') {
            return getCookie('ea_sid') || getStoredValue(field);
        }
        if (field === 'current_page_url') {
            return String(window.location.href || '');
        }
        if (field === 'landing_url') {
            return getStoredValue(field) || String(window.location.href || '');
        }
        if (field === 'referrer') {
            return getStoredValue(field) || String(document.referrer || '');
        }
        return getStoredValue(field);
    }

    function populateAttributionFields(form) {
        const params = new URLSearchParams(window.location.search || '');
        attributionFields.forEach(function (field) {
            const input = form.querySelector('input[type="hidden"][name="' + field + '"]');
            if (input && !input.value) {
                input.value = getAttributionValue(field, params);
            }
        });
    }

    function isVisibleInput(input) {
        return !input.disabled && input.type !== 'hidden' && input.offsetParent !== null;
    }

    function getWrapper(input) {
        return input.closest('.estandar-form-field') || input.parentElement;
    }

    function cssEscape(value) {
        if (window.CSS && typeof window.CSS.escape === 'function') {
            return window.CSS.escape(value);
        }
        return String(value).replace(/["\\]/g, '\\$&');
    }

    function clearClientErrors(form) {
        form.querySelectorAll('.estandar-field-error.is-client-error, .estandar-form-error.is-client-error').forEach(function (error) {
            error.remove();
        });
        form.querySelectorAll('.estandar-form-field.has-client-error').forEach(function (wrapper) {
            wrapper.classList.remove('has-error', 'has-client-error');
        });
        form.querySelectorAll('[data-estandar-client-invalid="1"]').forEach(function (input) {
            input.removeAttribute('aria-invalid');
            input.removeAttribute('data-estandar-client-invalid');
            input.removeAttribute('aria-describedby');
        });
    }

    function getFieldLabel(input) {
        const wrapper = getWrapper(input);
        if (!wrapper) {
            return input.name || 'Campo';
        }
        const label = wrapper.querySelector('label, .estandar-radio-label');
        return label ? label.textContent.replace('*', '').trim() : (input.name || 'Campo');
    }

    function focusField(input) {
        scrollToElement(input);
        window.setTimeout(function () {
            input.focus({ preventScroll: true });
        }, 250);
    }

    function findField(form, name) {
        return form.querySelector('[name="' + cssEscape(name) + '"]');
    }

    function addTopAlert(form, items) {
        const alert = document.createElement('div');
        const count = items.length;
        alert.className = 'estandar-form-error estandar-form-error-summary is-client-error';
        alert.setAttribute('role', 'alert');
        alert.setAttribute('aria-live', 'polite');

        const title = document.createElement('p');
        const strong = document.createElement('strong');
        strong.textContent = count > 1 ? 'Se encontraron ' + count + ' errores:' : 'Se encontró 1 error:';
        title.appendChild(strong);
        alert.appendChild(title);

        const list = document.createElement('ul');
        items.forEach(function (item) {
            const li = document.createElement('li');
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'estandar-error-link';
            button.textContent = item.label + ': ' + item.message;
            button.addEventListener('click', function () {
                focusField(item.input);
            });
            li.appendChild(button);
            list.appendChild(li);
        });
        alert.appendChild(list);
        form.insertBefore(alert, form.firstElementChild);
    }

    function getMessage(input) {
        if (input.validity.valueMissing) {
            if (input.tagName === 'SELECT') {
                return 'Debes seleccionar una opción.';
            }
            if (input.type === 'checkbox' || input.type === 'radio') {
                return 'Debes seleccionar una opción.';
            }
            return 'Este campo es obligatorio.';
        }
        if (input.validity.typeMismatch && input.type === 'email') {
            return 'Ingresa un correo electrónico válido.';
        }
        if ((input.validity.badInput || input.validity.rangeUnderflow || input.validity.rangeOverflow) && input.type === 'date') {
            return 'Fecha inválida.';
        }
        if (input.validationMessage) {
            return input.validationMessage;
        }
        return 'Revisa este campo.';
    }

    function addFieldError(input, message, index) {
        const wrapper = getWrapper(input);
        const id = (input.id || input.name || 'field') + '-client-error-' + index;

        if (wrapper) {
            wrapper.classList.add('has-error', 'has-client-error');
        }

        input.setAttribute('aria-invalid', 'true');
        input.setAttribute('data-estandar-client-invalid', '1');
        input.setAttribute('aria-describedby', id);

        const error = document.createElement('p');
        error.className = 'estandar-field-error is-client-error';
        error.id = id;
        error.setAttribute('role', 'alert');
        error.setAttribute('aria-live', 'polite');
        error.textContent = message;

        if (wrapper) {
            wrapper.appendChild(error);
        }
    }

    function scrollToElement(element) {
        const yOffset = -100;
        const y = element.getBoundingClientRect().top + window.pageYOffset + yOffset;
        window.scrollTo({ top: Math.max(0, y), behavior: 'smooth' });
    }

    function validateForm(form) {
        clearClientErrors(form);

        const invalid = [];
        const seen = {};
        const fields = Array.prototype.slice.call(form.querySelectorAll('input, select, textarea'));

        fields.forEach(function (input) {
            if (!isVisibleInput(input) || input.closest('.estandar-form-hp')) {
                return;
            }

            const key = input.name || input.id;
            if (!input.checkValidity() && !seen[key]) {
                seen[key] = true;
                invalid.push(input);
            }
        });

        const items = [];
        invalid.forEach(function (input, index) {
            const message = getMessage(input);
            addFieldError(input, message, index);
            items.push({ input: input, label: getFieldLabel(input), message: message });
        });

        if (invalid.length > 0) {
            addTopAlert(form, items);
            focusField(invalid[0]);
            return false;
        }

        return true;
    }

    function setSubmitting(form, active, disableFields) {
        const buttons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
        form.classList.toggle('is-submitting', active);
        if (disableFields) {
            form.querySelectorAll('input, select, textarea, button').forEach(function (control) {
                if (!control.dataset.estandarWasDisabled) {
                    control.dataset.estandarWasDisabled = control.disabled ? '1' : '0';
                }
                control.disabled = active || control.dataset.estandarWasDisabled === '1';
            });
        }
        buttons.forEach(function (button) {
            if (!button.dataset.originalText) {
                button.dataset.originalText = button.tagName === 'INPUT' ? button.value : button.textContent;
            }
            button.disabled = active;
            if (active) {
                if (button.tagName === 'INPUT') {
                    button.value = 'Enviando...';
                } else {
                    button.textContent = 'Enviando...';
                }
            } else {
                if (button.tagName === 'INPUT') {
                    button.value = button.dataset.originalText || 'Enviar';
                } else {
                    button.textContent = button.dataset.originalText || 'Enviar';
                }
            }
        });
    }

    function showMessage(form, type, message) {
        const alert = document.createElement('div');
        alert.className = (type === 'success' ? 'estandar-form-success' : 'estandar-form-error') + ' is-client-response';
        alert.setAttribute('role', 'alert');
        alert.setAttribute('aria-live', 'polite');
        alert.textContent = message;
        form.insertBefore(alert, form.firstElementChild);
        scrollToElement(alert);
        return alert;
    }

    function clearResponseMessages(form) {
        form.querySelectorAll('.is-client-response').forEach(function (message) {
            message.remove();
        });
    }

    function getFormId(form) {
        const input = form.querySelector('[name="estandar_form_id"]');
        return input ? input.value : '';
    }

    function confirmationKey(form) {
        return 'estandar_form_confirmed_' + getFormId(form) + '_' + window.location.pathname;
    }

    function storeConfirmation(form, message) {
        try {
            if (window.sessionStorage) {
                window.sessionStorage.setItem(confirmationKey(form), message);
            }
        } catch (error) {}
    }

    function readConfirmation(form) {
        try {
            return window.sessionStorage ? window.sessionStorage.getItem(confirmationKey(form)) : '';
        } catch (error) {
            return '';
        }
    }

    function replaceWithConfirmation(form, message) {
        const existing = form.parentNode ? form.parentNode.querySelector('.estandar-form-confirmation.is-client-response') : null;
        if (existing) {
            return existing;
        }

        const box = document.createElement('div');
        box.className = 'estandar-form-success estandar-form-confirmation is-client-response';
        box.setAttribute('role', 'status');
        box.setAttribute('aria-live', 'polite');
        box.setAttribute('tabindex', '-1');

        const title = document.createElement('p');
        const strong = document.createElement('strong');
        strong.textContent = 'Solicitud enviada correctamente';
        title.appendChild(strong);
        box.appendChild(title);

        const copy = document.createElement('p');
        copy.textContent = message || form.dataset.successMessage || 'Hemos recibido tu información.';
        box.appendChild(copy);

        setSubmitting(form, true, true);
        form.dataset.submittedSuccess = '1';
        form.hidden = true;
        form.setAttribute('aria-hidden', 'true');
        form.classList.add('is-submit-success');
        form.parentNode.insertBefore(box, form);
        scrollToElement(box);
        window.setTimeout(function () {
            box.focus({ preventScroll: true });
        }, 250);
        return box;
    }

    function replaceWithRecontactScreen(form, projectName) {
        var existing = form.parentNode ? form.parentNode.querySelector('.estandar-form-recontact.is-client-response') : null;
        if (existing) { return existing; }

        var box = document.createElement('div');
        box.className = 'estandar-form-success estandar-form-recontact is-client-response';
        box.setAttribute('role', 'status');
        box.setAttribute('aria-live', 'polite');
        box.setAttribute('tabindex', '-1');

        var greeting = document.createElement('p');
        greeting.className = 'estandar-recontact-title';
        var strong = document.createElement('strong');
        strong.textContent = '¡Hola de nuevo!';
        greeting.appendChild(strong);
        box.appendChild(greeting);

        var intro = document.createElement('p');
        intro.className = 'estandar-recontact-description';
        intro.textContent = 'Vemos que ya nos contactaste antes' + (projectName ? ' por ' + projectName : '') + '. Tu información ya está registrada.';
        box.appendChild(intro);

        var question = document.createElement('p');
        question.className = 'estandar-recontact-question';
        question.textContent = '¿En qué podemos ayudarte ahora?';
        box.appendChild(question);

        var actions = [
            { key: 'update_info', icon: '📋', label: 'Actualizar mis datos de contacto' },
            { key: 'schedule_visit', icon: '📅', label: 'Agendar una visita' },
            { key: 'pricing', icon: '💰', label: 'Consultar precios o disponibilidad' },
            { key: 'status', icon: '📊', label: 'Saber el estado de mi consulta' },
            { key: 'new_project', icon: '🏠', label: 'Consultar por otro proyecto' },
            { key: 'other', icon: '💬', label: 'Otra consulta' }
        ];

        var actionsList = document.createElement('div');
        actionsList.className = 'estandar-recontact-actions';

        var textarea = null;
        var selectedAction = null;
        var submitBtn = null;

        actions.forEach(function (action) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'estandar-recontact-action';
            btn.dataset.action = action.key;
            btn.innerHTML = '<span class="estandar-recontact-action-icon" aria-hidden="true">' + action.icon + '</span><span class="estandar-recontact-action-label">' + action.label + '</span>';
            btn.addEventListener('click', function () {
                actionsList.querySelectorAll('.estandar-recontact-action').forEach(function (b) {
                    b.classList.remove('is-selected');
                });
                btn.classList.add('is-selected');
                selectedAction = action.key;
                if (action.key === 'other' && textarea) {
                    textarea.parentNode.hidden = false;
                    textarea.focus();
                } else if (textarea) {
                    textarea.parentNode.hidden = true;
                }
                if (submitBtn) { submitBtn.disabled = false; }
            });
            actionsList.appendChild(btn);
        });

        box.appendChild(actionsList);

        var textareaWrap = document.createElement('div');
        textareaWrap.className = 'estandar-recontact-detail';
        textareaWrap.hidden = true;
        textarea = document.createElement('textarea');
        textarea.className = 'estandar-recontact-textarea';
        textarea.placeholder = 'Cuéntanos en qué podemos ayudarte...';
        textarea.rows = 3;
        textareaWrap.appendChild(textarea);
        box.appendChild(textareaWrap);

        submitBtn = document.createElement('button');
        submitBtn.type = 'button';
        submitBtn.className = 'estandar-recontact-submit';
        submitBtn.disabled = true;
        submitBtn.textContent = 'Enviar';
        submitBtn.addEventListener('click', function () {
            if (!selectedAction) { return; }
            submitBtn.disabled = true;
            submitBtn.textContent = 'Enviando...';

            var eventData = {
                action: selectedAction,
                detail: textarea ? textarea.value : '',
                project: projectName
            };

            document.dispatchEvent(new CustomEvent('estandar:recontact:action', { detail: eventData }));
            if (window.dataLayer) {
                window.dataLayer.push({ event: 'estandar_recontact_submitted', recontact_action: selectedAction, recontact_detail: !!(textarea && textarea.value), recontact_project: projectName });
            }

            var thankYou = document.createElement('div');
            thankYou.className = 'estandar-form-success estandar-recontact-confirmation is-client-response';
            thankYou.innerHTML = '<p><strong>¡Recibido!</strong></p><p>Tu solicitud fue registrada. Nos pondremos en contacto contigo pronto.</p>';
            box.parentNode.insertBefore(thankYou, box);
            box.hidden = true;
            scrollToElement(thankYou);
        });
        box.appendChild(submitBtn);

        setSubmitting(form, true, true);
        form.dataset.submittedSuccess = '1';
        form.hidden = true;
        form.setAttribute('aria-hidden', 'true');
        form.classList.add('is-submit-success');
        form.parentNode.insertBefore(box, form);
        scrollToElement(box);
        window.setTimeout(function () { box.focus({ preventScroll: true }); }, 250);
        return box;
    }

    function syncFormSuccessInstances(formId, message) {
        if (!formId) {
            return;
        }
        document.querySelectorAll('form.estandar-form').forEach(function (form) {
            if (getFormId(form) !== formId || form.dataset.submittedSuccess === '1') {
                return;
            }
            replaceWithConfirmation(form, message);
        });
    }

    function getAjaxMessage(payload, fallback) {
        const data = payload && payload.data ? payload.data : {};
        let message = fallback;
        if (data.user_message) {
            message = data.user_message;
        } else if (data.message) {
            message = data.message;
        } else if (Array.isArray(data.messages) && data.messages.length > 0) {
            message = data.messages[0];
        }

        return message;
    }

    function showFieldErrors(form, payload) {
        const data = payload && payload.data ? payload.data : {};
        const fieldErrors = data.field_errors || {};
        const items = [];
        let index = 0;

        clearClientErrors(form);
        Object.keys(fieldErrors).forEach(function (name) {
            const input = findField(form, name);
            const message = String(fieldErrors[name] || '');
            if (!input || message === '') {
                return;
            }
            addFieldError(input, message, index);
            items.push({ input: input, label: getFieldLabel(input), message: message });
            index++;
        });

        if (items.length === 0) {
            return false;
        }

        addTopAlert(form, items);
        focusField(items[0].input);
        return true;
    }

    function classifyAjaxFailure(error, response) {
        if (error && error.name === 'AbortError') {
            return 'request_timeout';
        }
        if (!response) {
            return 'network_failure';
        }
        if (!response.ok) {
            return 'http_' + response.status;
        }
        if (error && error.message === 'Invalid JSON response') {
            return 'invalid_json';
        }
        if (error && error.message === 'Malformed response') {
            return 'malformed_response';
        }
        return 'unknown_failure';
    }

    function findMetaPixelId() {
        const selectors = [
            'script[src*="connect.facebook.net/signals/config/"]',
            'noscript img[src*="facebook.com/tr"]'
        ];
        for (let i = 0; i < selectors.length; i++) {
            const node = document.querySelector(selectors[i]);
            const source = node ? (node.src || node.textContent || '') : '';
            const match = source.match(/(?:signals\/config\/|[?&]id=)(\d+)/);
            if (match && match[1]) {
                return match[1];
            }
        }
        return '';
    }

    function metaLeadEventId(ajaxData) {
        // Contrato CAPTURED: el event_id lo genera SIEMPRE el servidor desde el
        // lead persistido (misma derivación que el Lead Server de CAPI). El
        // browser jamás lo reconstruye — si el servidor no lo entregó es que
        // no hay persistencia confirmada, y sin hecho no hay evento.
        if (ajaxData && typeof ajaxData.meta_lead_event_id === 'string' && ajaxData.meta_lead_event_id !== '') {
            return ajaxData.meta_lead_event_id;
        }
        return '';
    }

    function trackMetaLead(form, ajaxData) {
        if (form.dataset.metaLeadEvent !== '1') {
            return;
        }

        const eventId = metaLeadEventId(ajaxData);
        if (!eventId) {
            return;
        }

        if (typeof window.fbq === 'function') {
            window.fbq('track', 'Lead', {}, { eventID: eventId });
            return;
        }

        const pixelId = findMetaPixelId();
        if (!pixelId) {
            return;
        }

        const params = new URLSearchParams({
            id: pixelId,
            ev: 'Lead',
            dl: window.location.href,
            rl: document.referrer || '',
            if: 'false',
            eid: eventId
        });
        const img = new Image();
        img.src = 'https://www.facebook.com/tr?' + params.toString();
    }

    function pushMarketingEvent(form, ajaxData) {
        if (form.dataset.tracked === '1') {
            return;
        }

        form.dataset.tracked = '1';
        window.dataLayer = window.dataLayer || [];
        const projectInput = form.querySelector('[name="project_slug"]');
        const eventPayload = {
            form_id: getFormId(form),
            form_name: form.dataset.name || '',
            project_slug: projectInput ? projectInput.value || '' : '',
            page_url: window.location.href
        };
        window.dataLayer.push(Object.assign({ event: 'estandar_form_success' }, eventPayload));
        window.dataLayer.push(Object.assign({ event: 'form_submit' }, eventPayload));
        trackMetaLead(form, ajaxData);
    }

    function dispatchSuccessEvent(form, message) {
        const detail = {
                formId: getFormId(form),
                formName: form.dataset.name || '',
                pageUrl: window.location.href,
                message: message || form.dataset.successMessage || ''
            };
        document.dispatchEvent(new CustomEvent('estandar_form_submit_success', { detail: detail }));
        document.dispatchEvent(new CustomEvent('estandar:form:success', { detail: detail }));
    }

    function createSubmissionToken() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }

        return Date.now().toString(36) + Math.random().toString(36).slice(2);
    }

    async function submitAjax(form) {
        setSubmitting(form, true, false);
        clearResponseMessages(form);

        let timeout = null;
        let response = null;

        try {
            const ajaxInput = form.querySelector('[name="estandar_ajax"]');
            const token = createSubmissionToken();
            const tokenInput = form.querySelector('[name="_estandar_token"]');
            if (ajaxInput) {
                ajaxInput.value = '1';
            }
            if (tokenInput) {
                tokenInput.value = token;
            }
            populateAttributionFields(form);

            const formData = new FormData(form);
            formData.set('estandar_ajax', '1');
            formData.set('_estandar_token', token);
            setSubmitting(form, true, true);
            const controller = new AbortController();
            timeout = window.setTimeout(function () {
                controller.abort();
            }, 30000);

            response = await fetch(form.getAttribute('action'), {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                signal: controller.signal
            });
            const raw = await response.text();
            let result;

            try {
                result = JSON.parse(raw);
            } catch (error) {
                if (window.console && typeof window.console.warn === 'function') {
                    window.console.warn('Estandar Forms invalid JSON response', {
                        status: response.status,
                        body: raw.slice(0, 500)
                    });
                }
                throw new Error('Invalid JSON response');
            }

            if (!result || typeof result.success === 'undefined') {
                throw new Error('Malformed response');
            }

            if (!response.ok || !result.success) {
                if (!showFieldErrors(form, result)) {
                    showMessage(form, 'error', getAjaxMessage(result, 'No fue posible enviar el formulario.'));
                }
                return;
            }

            var ajaxData = result && result.data ? result.data : {};
            if (ajaxData.recontact) {
                replaceWithRecontactScreen(form, ajaxData.recontact_project || '');
                eaTrackEvent('form_submit', form);
                pushMarketingEvent(form, ajaxData);
                form.reset();
                dispatchSuccessEvent(form, 'recontact');
            } else {
                var successMessage = getAjaxMessage(result, 'Gracias. Hemos recibido tu información correctamente. Pronto nos pondremos en contacto contigo.');
                storeConfirmation(form, successMessage);
                replaceWithConfirmation(form, successMessage);
                syncFormSuccessInstances(getFormId(form), successMessage);
                eaTrackEvent('form_submit', form);
                pushMarketingEvent(form, ajaxData);
                form.reset();
                dispatchSuccessEvent(form, successMessage);
            }
        } catch (error) {
            if (window.console && typeof window.console.warn === 'function') {
                window.console.warn('Estandar Forms AJAX failure', {
                    code: classifyAjaxFailure(error, response),
                    message: error && error.message ? error.message : ''
                });
            }
            showMessage(form, 'error', 'No fue posible enviar el formulario. Intenta nuevamente.');
        } finally {
            if (timeout) {
                window.clearTimeout(timeout);
            }
            if (form.dataset.submittedSuccess !== '1') {
                setSubmitting(form, false, true);
            }
        }
    }

    // ── Analytics helpers ─────────────────────────────────────────────────────
    // Usa el endpoint de Estandar Analytics si está disponible en la página.
    // No bloquea, no lanza errores, no tiene dependencias externas.

    function eaGetSid() {
        try {
            var m = document.cookie.match(/(?:^|;\s*)ea_sid=([^;]+)/);
            if (m && m[1]) return decodeURIComponent(m[1]);
        } catch (e) {}
        try { return (window.localStorage && window.localStorage.getItem('ea_sid')) || ''; } catch (e) {}
        return '';
    }

    function eaTrackEvent(eventType, form) {
        var endpoint = window.eaTrackerConfig && window.eaTrackerConfig.endpoint;
        var sid = eaGetSid();
        if (!endpoint || !sid) return;
        try {
            var projectInput = form && form.querySelector ? form.querySelector('[name="project_slug"]') : null;
            var event = { type: eventType, page_url: String(window.location.href || '') };
            if (projectInput && projectInput.value) {
                event.project_slug = projectInput.value;
            }
            var body = JSON.stringify({
                session_id: sid,
                events: [event]
            });
            if (navigator.sendBeacon) {
                navigator.sendBeacon(endpoint, new Blob([body], { type: 'application/json' }));
            } else {
                fetch(endpoint, { method: 'POST', body: body,
                    headers: { 'Content-Type': 'application/json' }, credentials: 'omit' })
                    .catch(function () {});
            }
        } catch (e) {}
    }

    // Rastrear primer campo completado como form_start (una sola vez por form).
    var formStartFired = new WeakSet ? new WeakSet() : { _s: [], has: function(f){ return this._s.indexOf(f) > -1; }, add: function(f){ this._s.push(f); } };

    function maybeFireFormStart(form) {
        if (formStartFired.has(form)) return;
        formStartFired.add(form);
        eaTrackEvent('form_start', form);
    }
    // ─────────────────────────────────────────────────────────────────────────

    // Resolver for project_selector routing mode (Routing V2).
    // The system-generated <select name="__project_routing"> holds the resolved slug directly.
    // Returns true if a valid slug is selected (or mode is not project_selector).
    // Returns false and marks the select as invalid — caller must abort submit.
    function resolveProjectSelectorSlug(form) {
        if (form.dataset.routingMode !== 'project_selector') {
            return true;
        }
        const selectorInput = form.querySelector('[name="__project_routing"]');
        const resolvedSlug  = selectorInput ? selectorInput.value.trim() : '';

        const projectInput = form.querySelector('[name="project_slug"]');
        if (projectInput) {
            projectInput.value = resolvedSlug;
        }

        if (resolvedSlug === '') {
            if (selectorInput) {
                selectorInput.setCustomValidity('Seleccione una opción válida.');
                selectorInput.reportValidity();
                selectorInput.addEventListener('change', function () {
                    selectorInput.setCustomValidity('');
                }, { once: true });
            }
            return false;
        }
        return true;
    }

    function bindForms() {
        document.querySelectorAll('form.estandar-form').forEach(function (form) {
            form.setAttribute('novalidate', 'novalidate');

            const storedConfirmation = readConfirmation(form);
            if (storedConfirmation && form.dataset.sessionConfirmationShown !== '1') {
                form.dataset.sessionConfirmationShown = '1';
                replaceWithConfirmation(form, storedConfirmation);
                return;
            }

            // Populate lead_uuid client-side so page caches never freeze a shared UUID.
            // Falls back to a PRNG mix if crypto.randomUUID is unavailable.
            var uuidInput = form.querySelector('input[name="lead_uuid"]');
            if (uuidInput && !uuidInput.value) {
                uuidInput.value = createSubmissionToken();
            }

            populateAttributionFields(form);
            if (!form.dataset.estandarServerErrorFocused) {
                const firstInvalid = form.querySelector('[aria-invalid="true"]');
                if (firstInvalid) {
                    form.dataset.estandarServerErrorFocused = '1';
                    focusField(firstInvalid);
                }
            }
        });
    }

    bindForms();
    document.addEventListener('DOMContentLoaded', bindForms);
    document.addEventListener('estandarPopupOpened', bindForms);
    document.addEventListener('estandar_form_submit_success', function (event) {
        const detail = event.detail || {};
        syncFormSuccessInstances(String(detail.formId || ''), String(detail.message || ''));
    });

    document.addEventListener('submit', function (event) {
        const form = event.target && event.target.closest ? event.target.closest('form.estandar-form') : null;
        if (!form) {
            return;
        }
        if (form.classList.contains('is-submitting') || form.dataset.submittedSuccess === '1') {
            event.preventDefault();
            event.stopPropagation();
            return;
        }

        form.setAttribute('novalidate', 'novalidate');
        if (!validateForm(form)) {
            event.preventDefault();
            event.stopPropagation();
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        if (!resolveProjectSelectorSlug(form)) {
            return;
        }

        submitAjax(form);
    }, true);

    document.addEventListener('input', function (event) {
        const input = event.target;
        if (!input || !input.closest || !input.closest('form.estandar-form')) {
            return;
        }
        // Analytics: form_start — primer input real en un formulario.
        maybeFireFormStart(input.closest('form.estandar-form'));
        const wrapper = getWrapper(input);
        if (wrapper) {
            wrapper.querySelectorAll('.estandar-field-error').forEach(function (error) {
                error.remove();
            });
            wrapper.classList.remove('has-error', 'has-client-error');
        }
        input.removeAttribute('aria-invalid');
        input.removeAttribute('data-estandar-client-invalid');
        input.removeAttribute('aria-describedby');
    });

    document.addEventListener('click', function (event) {
        const link = event.target && event.target.closest ? event.target.closest('[data-estandar-error-field]') : null;
        if (!link) {
            return;
        }
        const form = link.closest('.estandar-form-error-summary') ? link.closest('.estandar-form-error-summary').nextElementSibling : null;
        const field = form && form.matches('form.estandar-form') ? findField(form, link.getAttribute('data-estandar-error-field')) : null;
        if (field) {
            focusField(field);
        }
    });

    // Whatsapp contextual URL enrichment.
    // Reads city and campaign_location from the nearest estandar-form and injects them
    // as GET params into every /go/whatsapp link on the same page.
    (function () {
        function getFormField(form, fieldName) {
            if (!form) return '';
            const el = form.querySelector('[name="' + fieldName + '"]');
            return el ? String(el.value || '') : '';
        }

        function updateWhatsappLinks(city, campaignCity) {
            const links = document.querySelectorAll('a[href*="go/whatsapp"]');
            links.forEach(function (a) {
                try {
                    const url = new URL(a.href, window.location.origin);
                    if (city !== '') {
                        url.searchParams.set('city', city);
                    } else {
                        url.searchParams.delete('city');
                    }
                    if (campaignCity !== '') {
                        url.searchParams.set('campaign_location', campaignCity);
                    } else {
                        url.searchParams.delete('campaign_location');
                    }
                    a.href = url.toString();
                } catch (e) {}
            });
        }

        function bindWhatsappEnrichment(form) {
            const cityInput = form.querySelector('[name="city"]');
            if (!cityInput) return;

            function refresh() {
                updateWhatsappLinks(
                    getFormField(form, 'city'),
                    getFormField(form, 'campaign_location')
                );
            }

            // Seed on init (picks up campaign_location hidden default).
            refresh();
            cityInput.addEventListener('input', refresh);
            cityInput.addEventListener('change', refresh);
        }

        function initWhatsappEnrichment() {
            document.querySelectorAll('form.estandar-form').forEach(bindWhatsappEnrichment);
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initWhatsappEnrichment);
        } else {
            initWhatsappEnrichment();
        }
    }());

    // Dataset-driven dependent selects.
    // Renders all options server-side with data-parent attributes;
    // this handler filters them client-side when the parent field changes.
    (function () {
        function bindDatasetDependent(form) {
            form.querySelectorAll('[data-ef-dataset-depends-on]').forEach(function (sel) {
                var dependsOn = sel.getAttribute('data-ef-dataset-depends-on');
                if (!dependsOn) { return; }

                var parentEl = form.querySelector('[name="' + dependsOn + '"]');
                if (!parentEl) { return; }

                // Snapshot all options that carry a data-parent attribute.
                // Clone them so DOM manipulation doesn't lose the originals.
                if (!sel._efDsOptions) {
                    sel._efDsOptions = Array.from(
                        sel.querySelectorAll('option[data-parent]')
                    ).map(function (o) { return o.cloneNode(true); });
                }

                function applyFilter(parentValue) {
                    // Keep only the placeholder (first option).
                    while (sel.options.length > 1) { sel.remove(1); }

                    if (parentValue) {
                        // Enable and populate matching options.
                        sel.disabled = false;
                        sel._efDsOptions.forEach(function (opt) {
                            if (opt.getAttribute('data-parent') === parentValue) {
                                sel.appendChild(opt.cloneNode(true));
                            }
                        });
                        // Clear selection if previously chosen value is no longer in the new list.
                        if (sel.value && !sel.querySelector('option[value="' + sel.value + '"]')) {
                            sel.value = '';
                        }
                    } else {
                        // No parent selected — disable and reset.
                        sel.disabled = true;
                        sel.value = '';
                    }
                }

                parentEl.addEventListener('change', function () {
                    sel.value = '';  // Always clear on explicit region change.
                    applyFilter(parentEl.value);
                });
                // Apply on init (handles old_value repopulation after server-side error).
                applyFilter(parentEl.value);
            });
        }

        function initDatasetDependents() {
            document.querySelectorAll('form.estandar-form').forEach(bindDatasetDependent);
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initDatasetDependents);
        } else {
            initDatasetDependents();
        }
    }());

    // Test hook (inerte en producción): expone las funciones puras del contrato
    // Meta Lead para la suite Node (tests/run-meta-lead-browser.test.js).
    if (window.__ESTANDAR_FORMS_TEST__) {
        window.__ESTANDAR_FORMS_TEST__.trackMetaLead = trackMetaLead;
        window.__ESTANDAR_FORMS_TEST__.metaLeadEventId = metaLeadEventId;
    }
})();
