/* global EstandarCaptureSurvey */
(function () {
    'use strict';

    /* Generate a UUID v4 for the survey session token (client-side). */
    function generateToken() {
        if (typeof crypto !== 'undefined' && crypto.randomUUID) {
            return crypto.randomUUID();
        }
        return 'esvy-' + Date.now() + '-' + Math.random().toString(36).slice(2, 10);
    }

    /* Read the Estandar Analytics session ID from localStorage (if Analytics active). */
    function getEaSid() {
        try { return localStorage.getItem('ea_sid') || ''; } catch (e) { return ''; }
    }

    function initSurvey(wrap) {
        const cfg = window.EstandarCaptureSurvey || {};
        const ajaxUrl = cfg.ajaxUrl || '';
        const nonce   = cfg.nonce || '';
        const formId  = parseInt(wrap.dataset.formId || cfg.formId || '0', 10);
        const totalQ  = parseInt(wrap.dataset.total || cfg.totalQuestions || '0', 10);
        const idMode  = wrap.dataset.idMode || cfg.idMode || 'anonymous';

        // Session state — token is UUID generated client-side.
        // entryId is returned by handle_start() (session id nativo v2).
        const surveyToken = generateToken();
        let entryId       = 0;
        let answered      = 0;
        let currentScreen = null;
        let screenStartTime = Date.now();

        /* ── Dependent select (calle por proyecto) ────────── */
        function normKey(s) {
            // DEPENDENT_ACCENTS_V1: quita acentos (paridad con remove_accents de PHP)
            // para que un value con ñ/tilde calce con la clave ascii del mapa.
            return String(s || '')
                .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
                .toLowerCase().replace(/[^a-z0-9]+/g, '');
        }
        function escOpt(s) {
            return String(s)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }
        function fillDependent(depSelect, streets) {
            let html = '<option value="">— Selecciona —</option>';
            (streets || []).forEach(function (s) {
                const v = escOpt(s);
                html += '<option value="' + v + '">' + v + '</option>';
            });
            depSelect.innerHTML = html;
        }
        function loadDependentStreets(depSelect) {
            const depends  = depSelect.getAttribute('data-depends-on') || '';
            if (!depends) { return; }
            const source = wrap.querySelector('[name="' + depends + '"]');
            const project = source ? (source.value || '') : '';
            if (!project) { fillDependent(depSelect, []); return; }
            // DEPENDENT_INLINE_MAP_V1: mapa embebido → sin AJAX/nonce.
            // GENERIC_DEPENDENT_MAP_V1: `data-options-map` precede a `data-streets`.
            const rawMap = depSelect.getAttribute('data-options-map') || depSelect.getAttribute('data-streets') || '';
            if (rawMap) {
                let map = {};
                try { map = JSON.parse(rawMap) || {}; } catch (e) { map = {}; }
                fillDependent(depSelect, map[normKey(project)] || []);
                return;
            }
            const nonceEl = wrap.querySelector('.esvy-dependent-nonce');
            const ajaxEndpoint = nonceEl ? nonceEl.getAttribute('data-ajax') : '';
            const nonceVal = nonceEl ? nonceEl.getAttribute('data-nonce') : '';
            if (!project || !ajaxEndpoint || !nonceVal) { return; }
            fetch(ajaxEndpoint + '?action=ef_get_streets&project_id=' + encodeURIComponent(project) + '&nonce=' + encodeURIComponent(nonceVal))
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    const streets = (data && data.success && data.data && data.data.streets) || [];
                    fillDependent(depSelect, streets);
                })
                .catch(function () {});
        }

        // Escucha cambios en cualquier select del survey y repuebla los
        // dependientes cuyo data-depends-on coincida con el campo cambiado.
        wrap.addEventListener('change', function (ev) {
            const target = ev.target;
            if (!target) return;
            if (target.tagName === 'SELECT') {
                wrap.querySelectorAll('.esvy-dependent-select').forEach(function (dep) {
                    if ((dep.getAttribute('data-depends-on') || '') === (target.getAttribute('name') || '')) {
                        loadDependentStreets(dep);
                    }
                });
                return;
            }
            // Archivo de imagen: sube y guarda el URL en el campo oculto.
            if (target.matches && target.matches('[data-role="photo-file"]') && target.files && target.files.length > 0) {
                uploadPhoto(target);
            }
        });

        // Contador de caracteres en textareas con límite (maxlength).
        function updateCharCount(textarea) {
            const counter = textarea.parentElement ? textarea.parentElement.querySelector('[data-role="char-counter"]') : null;
            if (!counter) return;
            const max = parseInt(counter.getAttribute('data-max') || '0', 10);
            const len = String(textarea.value).length;
            counter.textContent = len + ' / ' + max;
        }
        wrap.addEventListener('input', function (ev) {
            if (ev.target && ev.target.tagName === 'TEXTAREA') {
                updateCharCount(ev.target);
            }
        });

        /* ── AJAX helper ──────────────────────────────────── */
        function ajax(action, data, cb) {
            const body = new FormData();
            body.append('action', action);
            body.append('nonce', nonce);
            body.append('ea_sid', getEaSid());
            Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
            fetch(ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (json) { cb && cb(json); })
                .catch(function () { cb && cb(null); });
        }

        function logEvent(eventType, questionIndex) {
            ajax('estandar_capture_survey_event', {
                form_id:        formId,
                session_id:     entryId,   // server needs this to persist survey_abandoned status
                session_token:  surveyToken,
                event_type:     eventType,
                question_index: questionIndex !== undefined ? questionIndex : '',
            });
        }

        /* ── Screens ──────────────────────────────────────── */
        function getActiveScreen() {
            return wrap.querySelector('.esvy-screen--active');
        }

        function transitionTo(nextScreen, dir) {
            const active = getActiveScreen();
            if (active && active === nextScreen) return;
            if (active) {
                active.classList.remove('esvy-screen--active');
                active.classList.add('esvy-screen--exit');
                setTimeout(function () {
                    active.classList.remove('esvy-screen--exit');
                    active.style.position = 'absolute';
                }, 360);
            }
            nextScreen.style.position = '';
            nextScreen.classList.remove('esvy-screen--enter');
            if (dir === 'prev') {
                nextScreen.style.transform = 'translateY(-20px)';
                nextScreen.style.opacity   = '0';
            }
            requestAnimationFrame(function () {
                nextScreen.classList.add('esvy-screen--active');
                nextScreen.style.transform = '';
                nextScreen.style.opacity   = '';
            });
            currentScreen   = nextScreen;
            screenStartTime = Date.now();
            updateProgress(nextScreen);

            // Repuebla la calle si el proyecto ya fue elegido (volver atrás).
            if (nextScreen.dataset.screen === 'question') {
                const dep = nextScreen.querySelector('.esvy-dependent-select');
                if (dep) {
                    const depends = dep.getAttribute('data-depends-on') || '';
                    const source = wrap.querySelector('[name="' + depends + '"]');
                    if (source && source.value) {
                        loadDependentStreets(dep);
                    }
                }
                logEvent('survey_question_view', parseInt(nextScreen.dataset.questionIndex || '0', 10));
            }
        }

        /* ── Progreso sobre el camino REAL del usuario ─────── */
        // Cuenta cuántas preguntas faltan desde `idx` hasta el fin siguiendo el
        // flujo (nextMap). Para ramas ya respondidas usa la respuesta real; para
        // preguntas futuras toma el peor caso (camino más largo). Así un flujo
        // que se corta antes (ej. solo documentación) no muestra "X de 20".
        function remainingFrom(idx) {
            const qs = wrap.querySelectorAll('.esvy-screen--question');
            if (idx >= qs.length) { return 0; }
            const entry = (cfg.nextMap || {})[idx];
            let nexts = [];
            if (entry) {
                (entry.rules || []).forEach(function (r) {
                    if (typeof r.next === 'number') { nexts.push({ n: r.next, answers: r.answers || [] }); }
                });
                if (typeof entry.catchall === 'number') { nexts.push({ n: entry.catchall, answers: null }); }
            } else if (idx + 1 < qs.length) {
                nexts.push({ n: idx + 1, answers: null });
            }
            if (nexts.length === 0) { return 1; } // la pregunta actual y luego fin.

            // ¿La rama de esta pregunta ya fue decidida por una respuesta previa?
            let chosen = null;
            if (idx < answered) {
                const ans = getAnswerFromScreen(qs[idx]).value;
                const match = nexts.find(function (nx) {
                    return nx.answers && nx.answers.indexOf(ans) !== -1;
                });
                if (match) { chosen = match.n; }
                else if (typeof entry.catchall === 'number') { chosen = entry.catchall; }
            }
            if (chosen === null) {
                // Futuro incierto → peor caso (camino más largo).
                let worst = 0;
                nexts.forEach(function (nx) { worst = Math.max(worst, remainingFrom(nx.n)); });
                chosen = worst;
            }
            return 1 + remainingFrom(chosen);
        }

        function updateProgress(screen) {
            const bar   = wrap.querySelector('.esvy-progress-bar');
            const label = wrap.querySelector('.esvy-progress-label');
            if (!bar || !label) return;

            const qScreens = wrap.querySelectorAll('.esvy-screen--question');
            let pos = 0;
            qScreens.forEach(function (qs, i) { if (qs === screen) pos = i; });

            const remaining = remainingFrom(pos);
            const total = answered + remaining;
            const pct   = total > 0 ? Math.round((answered + 1) / total * 100) : 0;

            bar.style.setProperty('--esvy-progress', pct);
            label.textContent = (answered + 1) + ' / ' + total;
        }

        /* ── Session start ────────────────────────────────── */
        function startSession(identData, cb) {
            const payload = {
                form_id:             formId,
                session_token:       surveyToken,
                total_questions:     totalQ,
                identification_mode: idMode,
            };
            if (identData) {
                Object.assign(payload, identData);
            }
            ajax('estandar_capture_survey_start', payload, function (json) {
                if (json && json.success && json.data) {
                    entryId = parseInt(json.data.session_id || '0', 10);
                }
                cb && cb();
            });
        }

        /* ── Answer collection ────────────────────────────── */
        function getAnswerFromScreen(screen) {
            const qType  = screen.dataset.questionType;
            const qName  = screen.dataset.questionName;
            const qLabel = screen.dataset.questionLabel;

            let value = '';
            let label = '';

            if (qType === 'nps') {
                const sel = screen.querySelector('.esvy-nps-btn.is-selected');
                if (sel) { value = sel.dataset.value; label = sel.dataset.value; }

            } else if (qType === 'rating') {
                const activeStars = Array.from(screen.querySelectorAll('.esvy-star-btn.is-active'));
                const sel = activeStars.reduce(function (max, btn) {
                    return parseInt(btn.dataset.value || '0', 10) > parseInt((max && max.dataset.value) || '0', 10) ? btn : max;
                }, null);
                if (sel) { value = sel.dataset.value; label = sel.dataset.value + ' estrella(s)'; }

            } else if (qType === 'matrix') {
                const rows = {};
                screen.querySelectorAll('.esvy-matrix-row').forEach(function (row) {
                    const rowId = row.dataset.rowId;
                    const sel = row.querySelector('.esvy-matrix-btn.is-selected');
                    if (sel) { rows[rowId] = parseInt(sel.dataset.value, 10); }
                });
                const keys = Object.keys(rows);
                if (keys.length > 0) {
                    value = JSON.stringify(rows);
                    label = 'Matriz (' + keys.length + ' filas respondidas)';
                }

            } else if (qType === 'emoji' || qType === 'cards') {
                const sel = screen.querySelector('.is-selected');
                if (sel) { value = sel.dataset.value; label = sel.dataset.label || sel.dataset.value; }

            } else if (qType === 'select') {
                const sel = screen.querySelector('select');
                if (sel && sel.value !== '') {
                    value = sel.value;
                    label = sel.options[sel.selectedIndex].text;
                }

            } else if (qType === 'radio') {
                const sel = screen.querySelector('input[type="radio"]:checked');
                if (sel) {
                    value = sel.value;
                    const lbl = screen.querySelector('label[for="' + sel.id + '"] .esvy-radio-label');
                    label = lbl ? lbl.textContent.trim() : sel.value;
                    // "Otra": el valor sigue siendo "_other" (para el branching),
                    // pero el label/texto real es lo escrito.
                    if (sel.value === '_other') {
                        const otherInput = screen.querySelector('.esvy-other-text');
                        const text = otherInput ? otherInput.value.trim() : '';
                        if (text) { label = text; }
                    }
                }

            } else if (qType === 'checkbox') {
                const checkboxes = screen.querySelectorAll('input[type="checkbox"]:checked');
                const payload = { checked: [] };
                const labels = [];
                let otherText = '';
                checkboxes.forEach(function (cb) {
                    if (cb.value === '_other') {
                        const otherInput = screen.querySelector('.esvy-other-text');
                        if (otherInput && otherInput.value.trim() !== '') {
                            payload.other_text = otherInput.value.trim();
                        }
                    } else {
                        payload.checked.push(cb.value);
                        const lblEl = screen.querySelector('label[for="' + cb.id + '"] .esvy-checkbox-label');
                        labels.push(lblEl ? lblEl.textContent.trim() : cb.value);
                    }
                });
                if (payload.checked.length > 0 || payload.other_text) {
                    value = JSON.stringify(payload);
                    label = labels.join(', ');
                    if (payload.other_text) {
                        label += (label ? ' + ' : '') + 'Otro: ' + payload.other_text;
                    }
                }

            } else if (qType === 'dependent_select') {
                const sel = screen.querySelector('select');
                if (sel && sel.value !== '') {
                    value = sel.value;
                    label = sel.options[sel.selectedIndex].text;
                }

            } else {
                const inp = screen.querySelector('input:not([type="radio"]):not([type="checkbox"]), textarea, .esvy-input');
                if (inp) { value = inp.value.trim(); label = value; }
            }

            return { qType, qName, qLabel, value, label };
        }

        function getAttachmentFromScreen(screen) {
            const urlField = screen.querySelector('[data-role="photo-url"]');
            return urlField ? urlField.value.trim() : '';
        }

        function saveAnswer(screen, cb) {
            const qi      = parseInt(screen.dataset.questionIndex || '0', 10);
            const ans     = getAnswerFromScreen(screen);
            const elapsed = Math.round((Date.now() - screenStartTime) / 1000);

            ajax('estandar_capture_survey_answer', {
                form_id:            formId,
                session_token:      surveyToken,
                question_index:     qi,
                question_label:     ans.qLabel,
                question_type:      ans.qType,
                answer_value:       ans.value,
                answer_label:       ans.label,
                answer_attachment:  getAttachmentFromScreen(screen),
                time_spent_seconds: elapsed,
            }, function () {
                if (ans.value !== '') answered++;
                cb && cb();
            });
        }

        function requiresAnswer(screen) {
            return screen.dataset.required === '1';
        }

        /* Validación RUT chileno (módulo 11) — igual que el motor server-side. */
        function validRut(value) {
            const rut = String(value).replace(/[^0-9kK]/g, '').toUpperCase();
            if (rut.length < 8) return false;
            const body = rut.slice(0, -1);
            const dv = rut.slice(-1);
            if (!/^\d+$/.test(body)) return false;
            let sum = 0, mul = 2;
            for (let i = body.length - 1; i >= 0; i--) {
                sum += mul * parseInt(body[i], 10);
                mul = mul < 7 ? mul + 1 : 2;
            }
            const res = 11 - (sum % 11);
            const dvCalc = res === 11 ? '0' : res === 10 ? 'K' : String(res);
            return dv === dvCalc;
        }

        /* Validación teléfono chileno: 9 dígitos (opcional prefijo +56). */
        function validPhone(value) {
            const digits = String(value).replace(/\D/g, '');
            if (digits.length === 9) {
                return /^[2-9]\d{8}$/.test(digits);
            }
            if (digits.length === 11 && /^56[2-9]\d{8}$/.test(digits)) {
                return true;
            }
            return false;
        }

        function hasAnswer(screen) {
            const qType = screen.dataset.questionType;
            const ans = getAnswerFromScreen(screen);
            if (ans.value === '') return false;
            // Nombre no incompleto: al menos 2 letras.
            if (qType === 'text' && /nombre/i.test(screen.dataset.questionName || '')) {
                const clean = String(ans.value).trim();
                if (clean.length < 2 || !/[A-Za-zÁÉÍÓÚÑÜáéíóúñü\s]/.test(clean)) return false;
            }
            // RUT chileno válido (módulo 11 real).
            if (/rut/i.test(screen.dataset.questionName || '') && !validRut(ans.value)) return false;
            // Teléfono chileno válido (9 dígitos).
            if (/telefono|phone|celular|movil/i.test(screen.dataset.questionName || '') && !validPhone(ans.value)) return false;
            // Email válido (no inventado): formato real con dominio.
            if (qType === 'email' || /email|correo/i.test(screen.dataset.questionName || '')) {
                if (!/^[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}$/.test(String(ans.value).trim())) return false;
            }
            return true;
        }

        function showError(screen, msg) {
            let err = screen.querySelector('.esvy-error-msg');
            if (!err) {
                err = document.createElement('p');
                err.className = 'esvy-error-msg';
                const body = screen.querySelector('.esvy-question-body');
                if (body) body.appendChild(err);
            }
            err.textContent = msg;
            err.classList.add('is-visible');
        }

        function clearError(screen) {
            const err = screen.querySelector('.esvy-error-msg');
            if (err) err.classList.remove('is-visible');
        }

        /* ── Navigation ───────────────────────────────────── */
        /* MS-3 (ADR 0034): branching opcional server-driven vía cfg.nextMap.
           Sin nextMap (o sin regla coincidente) → navegación lineal intacta. */
        function nextFromMap(qi, value) {
            const map = cfg.nextMap || {};
            const entry = map[qi];
            if (!entry) return null;
            for (let i = 0; i < (entry.rules || []).length; i++) {
                const r = entry.rules[i];
                if ((r.answers || []).indexOf(value) !== -1) return r.next;
            }
            return (entry.catchall !== undefined && entry.catchall !== null) ? entry.catchall : null;
        }

        function findNextQuestionScreen(current) {
            const qScreens = Array.from(wrap.querySelectorAll('.esvy-screen--question'));
            const idx = qScreens.indexOf(current);
            if (idx < 0) return null;
            const mapped = nextFromMap(idx, getAnswerFromScreen(current).value);
            if (mapped === 'end') return null;
            if (typeof mapped === 'number') return qScreens[mapped] || null;
            return idx < qScreens.length - 1 ? qScreens[idx + 1] : null;
        }

        function findPrevQuestionScreen(current) {
            const qScreens = Array.from(wrap.querySelectorAll('.esvy-screen--question'));
            const idx = qScreens.indexOf(current);
            return idx > 0 ? qScreens[idx - 1] : null;
        }

        function proceedNext(screen, skip) {
            const btn = screen.querySelector('[data-action="next"], [data-action="submit"]');
            if (btn) btn.classList.add('is-loading');

            const proceed = function () {
                if (btn) btn.classList.remove('is-loading');
                const action = btn ? btn.dataset.action : 'next';

                if (action === 'submit') {
                    completeAndShowDone();
                    return;
                }

                const next = findNextQuestionScreen(screen);
                if (next) {
                    transitionTo(next);
                } else {
                    completeAndShowDone();
                }
            };

            if (skip || !hasAnswer(screen)) {
                proceed();
            } else {
                saveAnswer(screen, proceed);
            }
        }

        function completeAndShowDone() {
            ajax('estandar_capture_survey_complete', {
                form_id:       formId,
                session_token: surveyToken,
                session_id:    entryId,
            }, function () {
                const done = wrap.querySelector('.esvy-screen--completion');
                if (done) {
                    transitionTo(done);
                    const progress = wrap.querySelector('.esvy-progress-wrap');
                    if (progress) progress.style.display = 'none';
                }
            });
        }

        /* ── Upload de foto adjunta (problemas postventa) ──── */
        function uploadPhoto(fileInput, cb) {
            const block = fileInput.closest('[data-role="photo-block"]');
            const urlField = block ? block.querySelector('[data-role="photo-url"]') : null;
            const errEl   = block ? block.querySelector('[data-role="photo-error"]') : null;
            const file = fileInput.files && fileInput.files[0];
            if (!file || !urlField) { cb && cb(null); return; }

            if (errEl) { errEl.hidden = true; }
            if (block) { block.classList.add('esvy-photo-uploading'); }

            const body = new FormData();
            body.append('action', 'estandar_capture_survey_upload');
            body.append('nonce', nonce);
            body.append('file', file);
            fetch(ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (json) {
                    if (block) { block.classList.remove('esvy-photo-uploading'); }
                    if (json && json.success && json.data && json.data.url) {
                        urlField.value = json.data.url;
                        const preview = block.querySelector('[data-role="photo-preview"]');
                        const img = block.querySelector('[data-role="photo-preview-img"]');
                        if (preview && img) {
                            img.src = json.data.url;
                            preview.hidden = false;
                        }
                        const lbl = block.querySelector('[data-role="photo-file-label"]');
                        if (lbl) { lbl.textContent = file.name; }
                        cb && cb(json.data.url);
                    } else {
                        if (errEl) { errEl.textContent = (json && json.data && json.data.message) || 'No se pudo subir la imagen.'; errEl.hidden = false; }
                        cb && cb(null);
                    }
                })
                .catch(function () {
                    if (block) { block.classList.remove('esvy-photo-uploading'); }
                    if (errEl) { errEl.textContent = 'Error de red al subir la imagen.'; errEl.hidden = false; }
                    cb && cb(null);
                });
        }

        /* ── Event delegation ─────────────────────────────── */
        wrap.addEventListener('click', function (e) {
            const tgt    = e.target.closest('[data-action]');
            const screen = e.target.closest('.esvy-screen');
            if (!screen) return;

            /* Adjuntar imagen: toggle del área de subida */
            const photoToggle = e.target.closest('[data-role="photo-toggle"]');
            if (photoToggle) {
                const block = photoToggle.closest('[data-role="photo-block"]');
                const upload = block ? block.querySelector('[data-role="photo-upload"]') : null;
                if (upload) { upload.hidden = !photoToggle.checked; }
                clearError(screen);
                return;
            }

            /* Adjuntar imagen: quitar la foto subida */
            const photoRemove = e.target.closest('[data-role="photo-remove"]');
            if (photoRemove) {
                const block = photoRemove.closest('[data-role="photo-block"]');
                if (block) {
                    const urlField = block.querySelector('[data-role="photo-url"]');
                    const preview = block.querySelector('[data-role="photo-preview"]');
                    const fileInput = block.querySelector('[data-role="photo-file"]');
                    const toggle = block.querySelector('[data-role="photo-toggle"]');
                    const lbl = block.querySelector('[data-role="photo-file-label"]');
                    if (urlField) { urlField.value = ''; }
                    if (preview) { preview.hidden = true; }
                    if (fileInput) { fileInput.value = ''; }
                    if (toggle) { toggle.checked = false; }
                    if (lbl) { lbl.textContent = 'Subir imagen o tomar con la cámara'; }
                }
                return;
            }

            /* NPS */
            const npsBtn = e.target.closest('.esvy-nps-btn');
            if (npsBtn) {
                screen.querySelectorAll('.esvy-nps-btn').forEach(function (b) { b.classList.remove('is-selected'); });
                npsBtn.classList.add('is-selected');
                clearError(screen);
                return;
            }

            /* Stars */
            const starBtn = e.target.closest('.esvy-star-btn');
            if (starBtn) {
                const val = parseInt(starBtn.dataset.value || '0', 10);
                screen.querySelectorAll('.esvy-star-btn').forEach(function (b, i) {
                    b.classList.toggle('is-active', i < val);
                });
                clearError(screen);
                return;
            }

            /* Matrix */
            const matrixBtn = e.target.closest('.esvy-matrix-btn');
            if (matrixBtn) {
                const row = matrixBtn.closest('.esvy-matrix-row, .esvy-matrix-mobile-row');
                if (row) {
                    row.querySelectorAll('.esvy-matrix-btn').forEach(function (b) { b.classList.remove('is-selected'); });
                }
                matrixBtn.classList.add('is-selected');
                clearError(screen);
                return;
            }

            /* Checkbox / Radio — clear error on change */
            const checkboxInput = e.target.closest('.esvy-checkbox-option input[type="checkbox"]');
            if (checkboxInput) {
                clearError(screen);
                // Toggle "Other" text field
                if (checkboxInput.value === '_other') {
                    const wrap = checkboxInput.closest('.esvy-checkbox-group');
                    if (wrap) {
                        const otherWrap = wrap.querySelector('.esvy-other-text-wrap');
                        const otherInput = wrap.querySelector('.esvy-other-text');
                        if (otherWrap) {
                            if (checkboxInput.checked) {
                                otherWrap.style.display = '';
                                if (otherInput) otherInput.focus();
                            } else {
                                otherWrap.style.display = 'none';
                                if (otherInput) otherInput.value = '';
                            }
                        }
                    }
                }
                return;
            }

            /* Radio "Otra" — toggle campo de texto */
            const radioInput = e.target.closest('input[type="radio"][value="_other"]');
            if (radioInput) {
                clearError(screen);
                const group = radioInput.closest('.esvy-radio-group');
                if (group) {
                    const otherWrap = group.querySelector('.esvy-other-text-wrap');
                    const otherInput = group.querySelector('.esvy-other-text');
                    if (otherWrap) {
                        otherWrap.style.display = '';
                        if (otherInput) otherInput.focus();
                    }
                }
                return;
            }
            /* Al elegir otra opción de radio distinta de "Otra", ocultar el texto */
            if (e.target.matches && e.target.matches('input[type="radio"]')) {
                const group = e.target.closest('.esvy-radio-group');
                if (group && e.target.value !== '_other') {
                    const otherWrap = group.querySelector('.esvy-other-text-wrap');
                    if (otherWrap) { otherWrap.style.display = 'none'; }
                }
                clearError(screen);
                return;
            }

            /* Emoji / Cards (auto-advance) */
            const emojiBtn = e.target.closest('.esvy-emoji-btn, .esvy-card');
            if (emojiBtn) {
                const container = emojiBtn.closest('.esvy-emoji, .esvy-cards');
                if (container) {
                    container.querySelectorAll('.esvy-emoji-btn, .esvy-card').forEach(function (b) { b.classList.remove('is-selected'); });
                }
                emojiBtn.classList.add('is-selected');
                clearError(screen);
                setTimeout(function () { proceedNext(screen, false); }, 280);
                return;
            }

            if (!tgt) return;
            const action = tgt.dataset.action;

            if (action === 'start') {
                tgt.classList.add('is-loading');
                if (entryId === 0) {
                    startSession(null, function () {
                        tgt.classList.remove('is-loading');
                        const idScreen = wrap.querySelector('.esvy-screen--identification');
                        const firstQ   = wrap.querySelector('.esvy-screen--question');
                        const next     = idScreen || firstQ;
                        if (next) transitionTo(next);
                    });
                } else {
                    tgt.classList.remove('is-loading');
                    const idScreen = wrap.querySelector('.esvy-screen--identification');
                    const firstQ   = wrap.querySelector('.esvy-screen--question');
                    const next     = idScreen || firstQ;
                    if (next) transitionTo(next);
                }
                return;
            }

            if (action === 'submit-identification') {
                const nameInput  = wrap.querySelector('input[name="respondent_name"]');
                const emailInput = wrap.querySelector('input[name="respondent_email"]');
                const phoneInput = wrap.querySelector('input[name="respondent_phone"]');
                const identData  = {
                    respondent_name:  nameInput  ? nameInput.value.trim()  : '',
                    respondent_email: emailInput ? emailInput.value.trim() : '',
                    respondent_phone: phoneInput ? phoneInput.value.trim() : '',
                };
                tgt.classList.add('is-loading');
                startSession(identData, function () {
                    tgt.classList.remove('is-loading');
                    const firstQ = wrap.querySelector('.esvy-screen--question');
                    if (firstQ) transitionTo(firstQ);
                });
                return;
            }

            if (action === 'skip-identification') {
                if (entryId === 0) {
                    startSession(null, function () {
                        const firstQ = wrap.querySelector('.esvy-screen--question');
                        if (firstQ) transitionTo(firstQ);
                    });
                } else {
                    const firstQ = wrap.querySelector('.esvy-screen--question');
                    if (firstQ) transitionTo(firstQ);
                }
                return;
            }

            if (action === 'next' || action === 'submit') {
                if (requiresAnswer(screen) && !hasAnswer(screen)) {
                    const qName = screen.dataset.questionName || '';
                    let msg = 'Por favor, completa este paso antes de continuar.';
                    if (/rut/i.test(qName)) msg = 'El RUT no es válido. Verifica el dígito verificador (ej. 12.345.678-4).';
                    else if (/nombre/i.test(qName)) msg = 'El nombre no puede estar incompleto.';
                    else if (/telefono/i.test(qName)) msg = 'Ingresa un teléfono válido (9 dígitos, ej. 9 1234 5678).';
                    else if (/email/i.test(qName)) msg = 'Ingresa un correo válido.';
                    else if (/calle/i.test(qName)) msg = 'Selecciona la calle de tu vivienda.';
                    showError(screen, msg);
                    return;
                }
                // Checkbox "other" validation: other checked but empty text
                const qType = screen.dataset.questionType;
                if (qType === 'checkbox') {
                    const otherCb = screen.querySelector('input[value="_other"]');
                    if (otherCb && otherCb.checked) {
                        const otherInput = screen.querySelector('.esvy-other-text');
                        if (otherInput && otherInput.value.trim() === '') {
                            showError(screen, 'Escribe una descripción para la opción "Otro".');
                            return;
                        }
                    }
                }
                // Radio "Otra": si está seleccionada pero el texto está vacío, no avanza.
                if (qType === 'radio') {
                    const otherRadio = screen.querySelector('input[type="radio"][value="_other"]:checked');
                    if (otherRadio) {
                        const otherInput = screen.querySelector('.esvy-other-text');
                        if (otherInput && otherInput.value.trim() === '') {
                            showError(screen, 'Escribe tu requerimiento para la opción "Otra".');
                            return;
                        }
                    }
                }
                if (entryId === 0 && action === 'next') {
                    startSession(null, function () { proceedNext(screen, false); });
                } else {
                    proceedNext(screen, false);
                }
                return;
            }

            if (action === 'skip') {
                proceedNext(screen, true);
                return;
            }

            if (action === 'prev') {
                const prevScreen = findPrevQuestionScreen(screen);
                if (prevScreen) transitionTo(prevScreen, 'prev');
                return;
            }
        });

        /* ── Star hover ───────────────────────────────────── */
        wrap.addEventListener('mouseover', function (e) {
            const starBtn = e.target.closest('.esvy-star-btn');
            if (!starBtn) return;
            const val = parseInt(starBtn.dataset.value || '0', 10);
            const container = starBtn.closest('.esvy-rating');
            if (!container) return;
            container.querySelectorAll('.esvy-star-btn').forEach(function (b, i) {
                b.classList.toggle('is-hovered', i < val);
            });
        });

        wrap.addEventListener('mouseout', function (e) {
            const container = e.target.closest('.esvy-rating');
            if (!container) return;
            if (!container.contains(e.relatedTarget)) {
                container.querySelectorAll('.esvy-star-btn').forEach(function (b) {
                    b.classList.remove('is-hovered');
                });
            }
        });

        /* ── Initial setup ────────────────────────────────── */
        logEvent('survey_view');

        const firstActive = wrap.querySelector('.esvy-screen--active');
        if (firstActive) {
            currentScreen = firstActive;
            updateProgress(firstActive);
        }

        /* Auto-start session if no hero and no identification screens. */
        const hasHero  = wrap.querySelector('.esvy-screen--hero');
        const hasIdent = wrap.querySelector('.esvy-screen--identification');
        if (!hasHero && !hasIdent && entryId === 0) {
            startSession(null);
        }
    }

    /* ── Bootstrap ────────────────────────────────────────── */
    function bootstrap() {
        document.querySelectorAll('.esvy-wrap').forEach(function (wrap) {
            if (!wrap.dataset.svyInit) {
                wrap.dataset.svyInit = '1';
                initSurvey(wrap);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootstrap);
    } else {
        bootstrap();
    }

})();
