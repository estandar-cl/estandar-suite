/* global EstandarSurvey */
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
        const cfg = window.EstandarSurvey || {};
        const ajaxUrl = cfg.ajaxUrl || '';
        const nonce   = cfg.nonce || '';
        const formId  = parseInt(wrap.dataset.formId || cfg.formId || '0', 10);
        const totalQ  = parseInt(wrap.dataset.total || cfg.totalQuestions || '0', 10);
        const idMode  = wrap.dataset.idMode || cfg.idMode || 'anonymous';

        // Session state — token is UUID generated client-side.
        // entryId is returned by handle_start() (form_entries.id).
        const surveyToken = generateToken();
        let entryId       = 0;
        let answered      = 0;
        let currentScreen = null;
        let screenStartTime = Date.now();

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
            ajax('estandar_survey_event', {
                form_id:        formId,
                entry_id:       entryId,   // server needs this to persist survey_abandoned status
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

            if (nextScreen.dataset.screen === 'question') {
                logEvent('survey_question_view', parseInt(nextScreen.dataset.questionIndex || '0', 10));
            }
        }

        function updateProgress(screen) {
            const bar   = wrap.querySelector('.esvy-progress-bar');
            const label = wrap.querySelector('.esvy-progress-label');
            if (!bar || !label) return;

            const qScreens = wrap.querySelectorAll('.esvy-screen--question');
            let current = 0;
            qScreens.forEach(function (qs, i) { if (qs === screen) current = i + 1; });

            const pct = totalQ > 0 ? Math.round(current / totalQ * 100) : 0;
            bar.style.setProperty('--esvy-progress', pct);
            label.textContent = current + ' / ' + totalQ;
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
            ajax('estandar_survey_start', payload, function (json) {
                if (json && json.success && json.data) {
                    entryId = parseInt(json.data.entry_id || '0', 10);
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

            } else {
                const ta = screen.querySelector('textarea, .esvy-textarea');
                if (ta) { value = ta.value.trim(); label = value; }
            }

            return { qType, qName, qLabel, value, label };
        }

        function saveAnswer(screen, cb) {
            const qi      = parseInt(screen.dataset.questionIndex || '0', 10);
            const ans     = getAnswerFromScreen(screen);
            const elapsed = Math.round((Date.now() - screenStartTime) / 1000);

            ajax('estandar_survey_answer', {
                form_id:            formId,
                session_token:      surveyToken,
                question_index:     qi,
                question_label:     ans.qLabel,
                question_type:      ans.qType,
                answer_value:       ans.value,
                answer_label:       ans.label,
                time_spent_seconds: elapsed,
            }, function () {
                if (ans.value !== '') answered++;
                cb && cb();
            });
        }

        function requiresAnswer(screen) {
            return screen.dataset.required === '1';
        }

        function hasAnswer(screen) {
            return getAnswerFromScreen(screen).value !== '';
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
            ajax('estandar_survey_complete', {
                form_id:       formId,
                session_token: surveyToken,
                entry_id:      entryId,
            }, function () {
                const done = wrap.querySelector('.esvy-screen--completion');
                if (done) {
                    transitionTo(done);
                    const progress = wrap.querySelector('.esvy-progress-wrap');
                    if (progress) progress.style.display = 'none';
                }
            });
        }

        /* ── Event delegation ─────────────────────────────── */
        wrap.addEventListener('click', function (e) {
            const tgt    = e.target.closest('[data-action]');
            const screen = e.target.closest('.esvy-screen');
            if (!screen) return;

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
                    showError(screen, 'Por favor, selecciona una opción antes de continuar.');
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
