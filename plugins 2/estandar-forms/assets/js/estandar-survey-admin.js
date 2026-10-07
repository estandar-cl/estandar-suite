/* global jQuery */
(function ($) {
    'use strict';

    /* ── Question type definitions ────────────────────────── */
    var QUESTION_TYPES = {
        nps:      { label: 'NPS (0-7)',           hasOptions: false },
        rating:   { label: 'Rating (Estrellas)',   hasOptions: false, hasMax: true },
        matrix:   { label: 'Evaluación múltiple',   hasOptions: false, hasRows: true },
        emoji:    { label: 'Emojis',              hasOptions: true  },
        cards:    { label: 'Cards de selección',  hasOptions: true  },
        checkbox: { label: 'Checkbox múltiple',    hasOptions: true, hasAllowOther: true },
        text:     { label: 'Texto libre',          hasOptions: false },
        select:   { label: 'Select',              hasOptions: true  },
        radio:    { label: 'Radio',               hasOptions: true  },
    };

    var DEFAULT_EMOJI_OPTIONS = [
        { value: '1', label: 'Muy malo',   emoji: '😞' },
        { value: '2', label: 'Regular',    emoji: '😐' },
        { value: '3', label: 'Bueno',      emoji: '😊' },
        { value: '4', label: 'Excelente',  emoji: '😁' },
    ];

    var DEFAULT_CARD_OPTIONS = [
        { value: 'opcion_1', label: 'Opción 1', description: '' },
        { value: 'opcion_2', label: 'Opción 2', description: '' },
    ];

    /* ── State ────────────────────────────────────────────── */
    var questions = [];
    var expanded = {}; // question id → true/false

    function _generateId() {
        return 'q_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 8);
    }

    /* ── Persistence (localStorage, por usuario y encuesta) ── */
    function _storageKey() {
        var sid = $('#survey-questions-list').data('survey-id') || 0;
        return 'esvy_expanded_' + sid;
    }

    function _saveExpanded() {
        var ids = questions.filter(function (q) { return expanded[q.id] === true; }).map(function (q) { return q.id; });
        try { localStorage.setItem(_storageKey(), JSON.stringify(ids)); } catch (e) { /* quota exceeded */ }
    }

    function _loadExpanded() {
        expanded = {};
        try {
            var stored = JSON.parse(localStorage.getItem(_storageKey()) || '[]');
            if (!Array.isArray(stored)) return;
            questions.forEach(function (q) {
                if (q.id && stored.indexOf(q.id) !== -1) {
                    expanded[q.id] = true;
                }
            });
        } catch (e) { /* corrupted data */ }
    }

    function _cleanExpanded() {
        var activeIds = {};
        questions.forEach(function (q) { if (q.id) activeIds[q.id] = true; });
        Object.keys(expanded).forEach(function (id) {
            if (!activeIds[id]) delete expanded[id];
        });
    }

    function loadFromJSON(json) {
        try {
            var parsed = JSON.parse(json || '[]');
            questions = Array.isArray(parsed) ? parsed : [];
            questions.forEach(function (q) {
                if (q && (q.type === 'select-cards' || q.type === 'select_cards' || q.type === 'card')) {
                    q.type = 'cards';
                }
            });
            // Ensure every question has a stable id
            questions.forEach(function (q) {
                if (!q.id) q.id = _generateId();
            });
            // Restore expanded state from localStorage
            _loadExpanded();
            _cleanExpanded();
        } catch (e) {
            questions = [];
        }
    }

    function saveToJSON() {
        $('#survey-fields-json').val(JSON.stringify(questions));
    }

    /* ── Update question count ─────────────────────────────── */
    function _updateCount() {
        var total = questions.length;
        var expandedCount = questions.filter(function (q) { return expanded[q.id] === true; }).length;
        $('#esvy-q-count').text(total + ' preguntas · ' + expandedCount + ' expandidas');
    }

    /* ── Render ───────────────────────────────────────────── */
    function renderAll() {
        var list = $('#survey-questions-list');
        list.empty();

        if (questions.length === 0) {
            list.append('<p style="color:#6b7280;padding:16px 0">Sin preguntas. Agrega una usando los botones de arriba.</p>');
            return;
        }

        questions.forEach(function (q, idx) {
            list.append(buildQuestionCard(q, idx));
        });

        _updateCount();

        // Refresh sortable after render
        list.sortable('refresh');

        list.sortable({
            handle: '.esvy-q-drag',
            update: function () {
                var newOrder = [];
                list.find('.esvy-q-card').each(function () {
                    var i = parseInt($(this).data('idx'), 10);
                    newOrder.push(questions[i]);
                });
                questions = newOrder;
                renderAll();
                saveToJSON();
            }
        });
    }

    function toggleCard(idx) {
        var q = questions[idx];
        if (!q || !q.id) return;
        expanded[q.id] = !expanded[q.id];
        var $card = $('#survey-questions-list .esvy-q-card[data-idx="' + idx + '"]');
        $card.find('.esvy-q-body').toggle(expanded[q.id]);
        $card.find('.esvy-q-collapse-icon').text(expanded[q.id] ? '▼' : '▶');
        $card.toggleClass('esvy-q-card--collapsed', !expanded[q.id]);
        _saveExpanded();
        _updateCount();
    }

    function expandAll() {
        questions.forEach(function (q) { if (q.id) expanded[q.id] = true; });
        _saveExpanded();
        renderAll();
    }

    function collapseAll() {
        questions.forEach(function (q) { if (q.id) expanded[q.id] = false; });
        _saveExpanded();
        renderAll();
    }

    function buildQuestionCard(q, idx) {
        var def     = QUESTION_TYPES[q.type] || { label: q.type, hasOptions: false };
        var typeLbl = def.label;
        var hasOpts = def.hasOptions;
        var isExp   = expanded[q.id] || false;

        var $card = $('<div class="esvy-q-card' + (isExp ? '' : ' esvy-q-card--collapsed') + '" style="border:1px solid #e5e7eb;border-radius:8px;margin-bottom:8px;background:#fff" data-idx="' + idx + '"></div>');

        /* ── Header (siempre visible) ──────────────────────── */
        var $header = $('<div class="esvy-q-header" style="display:flex;align-items:center;gap:8px;padding:10px 14px;cursor:pointer"></div>');

        /* Collapse toggle */
        $header.append('<span class="esvy-q-collapse-icon" style="flex-shrink:0;color:#9ca3af;font-size:12px;width:16px;transition:transform .2s">' + (isExp ? '▼' : '▶') + '</span>');

        /* Drag handle */
        $header.append('<span class="esvy-q-drag" style="cursor:grab;color:#9ca3af;font-size:16px;flex-shrink:0" title="Arrastrar">⠿</span>');

        /* Question number */
        $header.append('<span class="esvy-q-num" style="background:#f3f4f6;color:#6b7280;padding:1px 8px;border-radius:50px;font-size:.75rem;font-weight:600;flex-shrink:0;min-width:22px;text-align:center">' + (idx + 1) + '</span>');

        /* Required indicator */
        if (q.required) {
            $header.append('<span style="color:#dc2626;font-size:1rem;flex-shrink:0" title="Obligatoria">*</span>');
        }

        /* Question label (truncated) */
        var labelText = q.label || 'Sin título';
        $header.append('<span class="esvy-q-label-preview" style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.85rem;color:#374151">' + escHtml(labelText) + '</span>');

        /* Type badge */
        $header.append('<span class="esvy-q-type-badge" style="background:#eff6ff;color:#0069ff;padding:2px 8px;border-radius:50px;font-size:.72rem;font-weight:600;flex-shrink:0;white-space:nowrap">' + escHtml(typeLbl) + '</span>');

        /* Actions */
        var $actions = $('<div style="margin-left:auto;display:flex;gap:4px;flex-shrink:0"></div>');
        $actions.append('<button type="button" class="button button-small esvy-q-duplicate" data-idx="' + idx + '" title="Duplicar" style="font-size:.75rem;padding:2px 8px">⧉</button>');
        $actions.append('<button type="button" class="button button-small esvy-q-move-up" data-idx="' + idx + '" title="Subir" style="font-size:.75rem;padding:2px 8px">↑</button>');
        $actions.append('<button type="button" class="button button-small esvy-q-move-down" data-idx="' + idx + '" title="Bajar" style="font-size:.75rem;padding:2px 8px">↓</button>');
        $actions.append('<button type="button" class="button button-small esvy-q-delete" data-idx="' + idx + '" title="Eliminar" style="color:#dc2626;font-size:.75rem;padding:2px 8px">✕</button>');
        $header.append($actions);

        $card.append($header);

        /* ── Body (colapsable) ─────────────────────────────── */
        var $body = $('<div class="esvy-q-body" style="display:' + (isExp ? 'grid' : 'none') + ';grid-template-columns:1fr 1fr;gap:12px;padding:0 14px 14px 14px;border-top:1px solid #f3f4f6"></div>');

        /* Label */
        var $labelGroup = $('<div style="grid-column:1/-1;padding-top:12px"></div>');
        $labelGroup.append('<label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:4px">Pregunta</label>');
        $labelGroup.append('<input type="text" class="large-text esvy-q-label" data-idx="' + idx + '" value="' + escAttr(q.label || '') + '" placeholder="Ej: ¿Qué tan probable es que nos recomiendes?" />');
        $body.append($labelGroup);

        /* Name */
        var $nameGroup = $('<div></div>');
        $nameGroup.append('<label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:4px">Nombre de campo</label>');
        $nameGroup.append('<input type="text" class="regular-text esvy-q-name" data-idx="' + idx + '" value="' + escAttr(q.name || '') + '" placeholder="ej: recommend_score" />');
        $body.append($nameGroup);

        /* Required */
        var checkedAttr = q.required ? ' checked' : '';
        var $reqGroup = $('<div style="display:flex;align-items:center;gap:8px;padding-top:20px"></div>');
        $reqGroup.append('<input type="checkbox" class="esvy-q-required" data-idx="' + idx + '"' + checkedAttr + ' id="esvy-req-' + idx + '" />');
        $reqGroup.append('<label for="esvy-req-' + idx + '" style="font-size:.85rem">Obligatoria</label>');
        $body.append($reqGroup);

        /* Type-specific */
        if (q.type === 'rating') {
            var $maxGroup = $('<div></div>');
            $maxGroup.append('<label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:4px">Máximo de estrellas</label>');
            var $maxSel = $('<select class="esvy-q-max" data-idx="' + idx + '"></select>');
            [3,4,5,7,10].forEach(function (n) {
                $maxSel.append('<option value="' + n + '"' + (q.max == n ? ' selected' : '') + '>' + n + '</option>');
            });
            $maxGroup.append($maxSel);
            $body.append($maxGroup);
        }

        if (q.type === 'matrix') {
            /* Scale selector */
            var $scaleGroup = $('<div style="grid-column:1/-1"></div>');
            $scaleGroup.append('<label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:4px">Escala</label>');
            var scale = q.scale || 7;
            var $scaleSel = $('<select class="esvy-q-matrix-scale" data-idx="' + idx + '"></select>');
            [3,5,7,10].forEach(function (n) {
                $scaleSel.append('<option value="' + n + '"' + (scale == n ? ' selected' : '') + '>' + n + ' puntos</option>');
            });
            $scaleGroup.append($scaleSel);
            $body.append($scaleGroup);

            /* End labels */
            var $lblMin = $('<div></div>');
            $lblMin.append('<label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:4px">Etiqueta mínima</label>');
            $lblMin.append('<input type="text" class="regular-text esvy-q-matrix-lbl-min" data-idx="' + idx + '" value="' + escAttr(q.label_min || 'Muy en desacuerdo') + '" />');
            $body.append($lblMin);

            var $lblMax = $('<div></div>');
            $lblMax.append('<label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:4px">Etiqueta máxima</label>');
            $lblMax.append('<input type="text" class="regular-text esvy-q-matrix-lbl-max" data-idx="' + idx + '" value="' + escAttr(q.label_max || 'Muy de acuerdo') + '" />');
            $body.append($lblMax);

            /* Column labels toggle */
            var hasColLbls = Array.isArray(q.column_labels) && q.column_labels.length > 0;
            var $colLblToggle = $('<div style="grid-column:1/-1;padding-top:4px"></div>');
            $colLblToggle.append('<label style="font-size:.82rem"><input type="checkbox" class="esvy-q-matrix-has-col-lbls" data-idx="' + idx + '"' + (hasColLbls ? ' checked' : '') + ' /> Etiquetas por columna</label>');
            $body.append($colLblToggle);

            var $colLblSection = $('<div class="esvy-q-matrix-col-lbls" style="grid-column:1/-1;display:' + (hasColLbls ? 'block' : 'none') + ';margin-top:4px"></div>');
            $colLblSection.append('<p style="font-size:.78rem;color:#6b7280;margin:0 0 6px">Etiqueta opcional para cada valor:</p>');
            var $colLblGrid = $('<div style="display:flex;flex-wrap:wrap;gap:6px"></div>');
            var cl = hasColLbls ? q.column_labels : [];
            for (var s = 1; s <= scale; s++) {
                var val = cl[s] || '';
                $colLblGrid.append('<div style="flex:1;min-width:60px;text-align:center"><div style="font-size:.78rem;color:#6b7280;margin-bottom:2px">' + s + '</div><input type="text" class="esvy-q-matrix-col-lbl" data-idx="' + idx + '" data-n="' + s + '" value="' + escAttr(val) + '" style="width:100%;text-align:center;font-size:.8rem" placeholder="—" /></div>');
            }
            $colLblSection.append($colLblGrid);
            $body.append($colLblSection);

            /* Rows editor */
            var $rowsSection = $('<div style="grid-column:1/-1;margin-top:8px"></div>');
            $rowsSection.append('<p style="font-size:.82rem;font-weight:600;color:#374151;margin:0 0 8px">Filas</p>');
            var $rowsList = $('<div class="esvy-q-matrix-rows" data-idx="' + idx + '"></div>');

            var rows = Array.isArray(q.rows) ? q.rows : [];
            rows.forEach(function (row, ri) {
                $rowsList.append(buildMatrixRow(row, idx, ri));
            });

            $rowsSection.append($rowsList);
            var $addRow = $('<button type="button" class="button esvy-q-matrix-add-row" data-idx="' + idx + '" style="margin-top:6px">+ Agregar fila</button>');
            $rowsSection.append($addRow);
            $body.append($rowsSection);
        }

        /* Hint */
        var $hintGroup = $('<div style="grid-column:1/-1"></div>');
        $hintGroup.append('<label style="display:block;font-size:.82rem;font-weight:600;margin-bottom:4px">Pista (opcional)</label>');
        $hintGroup.append('<input type="text" class="large-text esvy-q-hint" data-idx="' + idx + '" value="' + escAttr(q.hint || '') + '" placeholder="Texto de ayuda bajo la pregunta" />');
        $body.append($hintGroup);

        $card.append($body);

        /* Options editor */
        if (hasOpts) {
            var $optSection = $('<div class="esvy-q-options-section" style="margin-top:16px;border-top:1px solid #f3f4f6;padding-top:14px"></div>');

            // Checkbox: allow_other toggle
            if (q.type === 'checkbox') {
                var $otherToggle = $('<div style="margin-bottom:10px"></div>');
                $otherToggle.append('<label style="font-size:.82rem"><input type="checkbox" class="esvy-q-allow-other" data-idx="' + idx + '"' + (q.allow_other ? ' checked' : '') + ' /> Incluir opción "Otro" con campo de texto</label>');
                $optSection.append($otherToggle);
            }

            $optSection.append('<p style="font-size:.82rem;font-weight:600;color:#374151;margin:0 0 8px">Opciones</p>');
            var $optsList = $('<div class="esvy-q-options-list" data-idx="' + idx + '"></div>');

            var opts = Array.isArray(q.options) ? q.options : [];
            opts.forEach(function (opt, oi) {
                $optsList.append(buildOptionRow(q.type, opt, idx, oi));
            });

            $optSection.append($optsList);
            var $addOpt = $('<button type="button" class="button esvy-q-add-option" data-idx="' + idx + '" style="margin-top:8px">+ Agregar opción</button>');
            $optSection.append($addOpt);
            $body.append($optSection);
        }

        return $card;
    }

    function buildOptionRow(qType, opt, idx, oi) {
        var $row = $('<div class="esvy-opt-row" style="display:flex;gap:8px;align-items:center;margin-bottom:6px" data-q-idx="' + idx + '" data-o-idx="' + oi + '"></div>');

        if (qType === 'emoji') {
            $row.append('<input type="text" class="esvy-opt-emoji" value="' + escAttr(opt.emoji || '') + '" placeholder="😊" style="width:48px;text-align:center" />');
        }

        $row.append('<input type="text" class="esvy-opt-value" value="' + escAttr(opt.value || '') + '" placeholder="Valor" style="width:100px" />');
        $row.append('<input type="text" class="esvy-opt-label" value="' + escAttr(opt.label || '') + '" placeholder="Etiqueta" style="flex:1" />');

        if (qType === 'cards') {
            $row.append('<input type="text" class="esvy-opt-desc" value="' + escAttr(opt.description || '') + '" placeholder="Descripción (opcional)" style="flex:1" />');
        }

        $row.append('<button type="button" class="button esvy-opt-delete" data-q-idx="' + idx + '" data-o-idx="' + oi + '" style="color:#dc2626;flex-shrink:0">✕</button>');

        return $row;
    }

    /* ── Read back from DOM ───────────────────────────────── */
    function syncFromDOM() {
        $('#survey-questions-list .esvy-q-card').each(function () {
            var $card = $(this);
            var idx   = parseInt($card.data('idx'), 10);
            if (!questions[idx]) return;

            questions[idx].label    = $card.find('.esvy-q-label').val() || '';
            questions[idx].name     = sanitizeKey($card.find('.esvy-q-name').val() || '');
            questions[idx].hint     = $card.find('.esvy-q-hint').val() || '';
            questions[idx].required = $card.find('.esvy-q-required').is(':checked');

            if (questions[idx].type === 'rating') {
                questions[idx].max = parseInt($card.find('.esvy-q-max').val() || '5', 10);
            }

            if (questions[idx].type === 'matrix') {
                questions[idx].scale     = parseInt($card.find('.esvy-q-matrix-scale').val() || '7', 10);
                questions[idx].label_min = $card.find('.esvy-q-matrix-lbl-min').val() || 'Muy en desacuerdo';
                questions[idx].label_max = $card.find('.esvy-q-matrix-lbl-max').val() || 'Muy de acuerdo';

                /* Column labels */
                var hasColLbls = $card.find('.esvy-q-matrix-has-col-lbls').is(':checked');
                if (hasColLbls) {
                    var cls = [];
                    $card.find('.esvy-q-matrix-col-lbl').each(function () {
                        var n   = parseInt($(this).data('n'), 10);
                        var val = $(this).val() || '';
                        cls[n] = val;
                    });
                    questions[idx].column_labels = cls;
                } else {
                    questions[idx].column_labels = [];
                }

                /* Rows */
                var rows = [];
                $card.find('.esvy-matrix-row-editor').each(function () {
                    var $r = $(this);
                    var qIdx = parseInt($r.data('q-idx'), 10);
                    var rIdx = parseInt($r.data('r-idx'), 10);
                    var label = $r.find('.esvy-q-matrix-row-label').val() || '';
                    var existing = questions[qIdx] && questions[qIdx].rows && questions[qIdx].rows[rIdx];
                    rows.push({
                        id: (existing && existing.id) || _generateId(),
                        label: label
                    });
                });
                questions[idx].rows = rows;
            }

            var def = QUESTION_TYPES[questions[idx].type] || {};
            if (def.hasOptions) {
                var opts = [];
                $card.find('.esvy-opt-row').each(function () {
                    var $row = $(this);
                    var opt  = {
                        value: $row.find('.esvy-opt-value').val() || '',
                        label: $row.find('.esvy-opt-label').val() || '',
                    };
                    if (questions[idx].type === 'emoji') {
                        opt.emoji = $row.find('.esvy-opt-emoji').val() || '';
                    }
                    if (questions[idx].type === 'cards') {
                        opt.description = $row.find('.esvy-opt-desc').val() || '';
                    }
                    if (opt.value !== '' || opt.label !== '') {
                        opts.push(opt);
                    }
                });
                questions[idx].options = opts;
                if (def.hasAllowOther) {
                    questions[idx].allow_other = $card.find('.esvy-q-allow-other').is(':checked');
                }
            }
        });
        saveToJSON();
    }

    /* ── Helpers ──────────────────────────────────────────── */
    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function escAttr(s) { return escHtml(s); }
    function sanitizeKey(s) { return s.toLowerCase().replace(/[^a-z0-9_]/g, '_').replace(/__+/g,'_').replace(/^_+|_+$/g,''); }

    function defaultQuestion(type) {
        var q = { id: _generateId(), type: type, name: type + '_' + Date.now(), label: '', required: false };
        if (type === 'rating') { q.max = 5; }
        if (type === 'emoji')  { q.options = DEFAULT_EMOJI_OPTIONS.slice(); }
        if (type === 'cards')  { q.options = DEFAULT_CARD_OPTIONS.slice(); }
        if (type === 'matrix') {
            q.scale = 7;
            q.rows = [ {id: _generateId(), label: 'Afirmación 1'}, {id: _generateId(), label: 'Afirmación 2'} ];
            q.label_min = 'Muy en desacuerdo';
            q.label_max = 'Muy de acuerdo';
        }
        if (type === 'select' || type === 'radio') {
            q.options = [ {value:'opcion_1',label:'Opción 1'}, {value:'opcion_2',label:'Opción 2'} ];
        }
        if (type === 'checkbox') {
            q.options = [ {value:'opcion_1',label:'Opción 1'}, {value:'opcion_2',label:'Opción 2'} ];
            q.allow_other = false;
        }
        return q;
    }

    function buildMatrixRow(row, idx, ri) {
        var $row = $('<div class="esvy-matrix-row-editor" style="display:flex;gap:6px;align-items:center;margin-bottom:6px" data-q-idx="' + idx + '" data-r-idx="' + ri + '"></div>');
        $row.append('<input type="text" class="esvy-q-matrix-row-label" value="' + escAttr(row.label || '') + '" placeholder="Texto de la fila" style="flex:1" />');
        $row.append('<button type="button" class="button button-small esvy-q-matrix-row-del" data-q-idx="' + idx + '" data-r-idx="' + ri + '" title="Eliminar fila" style="color:#dc2626">✕</button>');
        return $row;
    }

    /* ── Event handlers ───────────────────────────────────── */
    $(document).on('click', '.esvy-q-header', function (e) {
        // Don't toggle when clicking buttons or inputs inside header
        if ($(e.target).is('button, input, select, .esvy-q-drag')) return;
        var idx = parseInt($(this).closest('.esvy-q-card').data('idx'), 10);
        toggleCard(idx);
    });

    $(document).on('click', '.esvy-add-question', function () {
        syncFromDOM();
        var type = $(this).data('type');
        var q = defaultQuestion(type);
        questions.push(q);
        // Auto-expand new question (by id)
        expanded[q.id] = true;
        _saveExpanded();
        renderAll();
        saveToJSON();
        var $last = $('#survey-questions-list .esvy-q-card').last();
        if ($last.length) { $('html,body').animate({scrollTop: $last.offset().top - 80}, 300); }
    });

    $(document).on('click', '.esvy-expand-all', function () {
        expandAll();
        saveToJSON();
    });

    $(document).on('click', '.esvy-collapse-all', function () {
        collapseAll();
        saveToJSON();
    });

    $(document).on('click', '.esvy-q-delete', function () {
        syncFromDOM();
        var idx = parseInt($(this).data('idx'), 10);
        if (confirm('¿Eliminar esta pregunta?')) {
            var q = questions[idx];
            if (q) delete expanded[q.id];
            questions.splice(idx, 1);
            _cleanExpanded();
            _saveExpanded();
            renderAll();
            saveToJSON();
        }
    });

    $(document).on('click', '.esvy-q-duplicate', function () {
        syncFromDOM();
        var idx = parseInt($(this).data('idx'), 10);
        var copy = JSON.parse(JSON.stringify(questions[idx]));
        copy.id = _generateId();
        copy.name = copy.name + '_copy';
        questions.splice(idx + 1, 0, copy);
        // Expand the duplicated card
        expanded[copy.id] = true;
        _saveExpanded();
        renderAll();
        saveToJSON();
        var $dup = $('#survey-questions-list .esvy-q-card').eq(idx + 1);
        if ($dup.length) { $('html,body').animate({scrollTop: $dup.offset().top - 80}, 300); }
    });

    $(document).on('click', '.esvy-q-move-up', function () {
        syncFromDOM();
        var idx = parseInt($(this).data('idx'), 10);
        if (idx > 0) {
            var tmp = questions[idx - 1]; questions[idx - 1] = questions[idx]; questions[idx] = tmp;
            renderAll(); saveToJSON();
        }
    });

    $(document).on('click', '.esvy-q-move-down', function () {
        syncFromDOM();
        var idx = parseInt($(this).data('idx'), 10);
        if (idx < questions.length - 1) {
            var tmp = questions[idx + 1]; questions[idx + 1] = questions[idx]; questions[idx] = tmp;
            renderAll(); saveToJSON();
        }
    });

    $(document).on('click', '.esvy-q-add-option', function () {
        syncFromDOM();
        var idx = parseInt($(this).data('idx'), 10);
        if (!Array.isArray(questions[idx].options)) { questions[idx].options = []; }
        var n = questions[idx].options.length + 1;
        var opt = { value: 'opcion_' + n, label: 'Opción ' + n };
        if (questions[idx].type === 'emoji') { opt.emoji = '⭐'; }
        if (questions[idx].type === 'cards') { opt.description = ''; }
        questions[idx].options.push(opt);
        renderAll(); saveToJSON();
    });

    $(document).on('click', '.esvy-opt-delete', function () {
        syncFromDOM();
        var qi = parseInt($(this).data('q-idx'), 10);
        var oi = parseInt($(this).data('o-idx'), 10);
        if (Array.isArray(questions[qi] && questions[qi].options)) {
            questions[qi].options.splice(oi, 1);
            renderAll(); saveToJSON();
        }
    });

    /* ── Matrix row handlers ──────────────────────────────── */
    $(document).on('click', '.esvy-q-matrix-add-row', function () {
        syncFromDOM();
        var idx = parseInt($(this).data('idx'), 10);
        if (!Array.isArray(questions[idx].rows)) { questions[idx].rows = []; }
        questions[idx].rows.push({ id: _generateId(), label: 'Afirmación ' + (questions[idx].rows.length + 1) });
        renderAll(); saveToJSON();
    });

    $(document).on('click', '.esvy-q-matrix-row-del', function () {
        syncFromDOM();
        var qi = parseInt($(this).data('q-idx'), 10);
        var ri = parseInt($(this).data('r-idx'), 10);
        if (Array.isArray(questions[qi] && questions[qi].rows)) {
            questions[qi].rows.splice(ri, 1);
            renderAll(); saveToJSON();
        }
    });

    /* ── Matrix: toggle column labels —─────────────── */
    $(document).on('change', '.esvy-q-matrix-has-col-lbls', function () {
        syncFromDOM();
        var $card = $(this).closest('.esvy-q-card');
        var idx   = parseInt($card.data('idx'), 10);
        renderAll();
        saveToJSON();
    });

    /* ── Matrix: scale change rebuilds column label grid ── */
    $(document).on('change', '.esvy-q-matrix-scale', function () {
        syncFromDOM();
        renderAll();
        saveToJSON();
    });

    $(document).on('change input', '.esvy-q-label, .esvy-q-name, .esvy-q-hint, .esvy-q-required, .esvy-q-max, .esvy-q-matrix-lbl-min, .esvy-q-matrix-lbl-max, .esvy-q-matrix-row-label, .esvy-q-matrix-col-lbl', function () {
        syncFromDOM();
        // Update label preview in header
        var $card = $(this).closest('.esvy-q-card');
        var idx   = parseInt($card.data('idx'), 10);
        if (questions[idx]) {
            $card.find('.esvy-q-label-preview').text(questions[idx].label || 'Sin título');
        }
    });

    $(document).on('change input', '.esvy-opt-value, .esvy-opt-label, .esvy-opt-desc, .esvy-opt-emoji', function () {
        syncFromDOM();
    });

    $('#estandar-survey-edit-form').on('submit', function () {
        syncFromDOM();
    });

    /* ── Init ─────────────────────────────────────────────── */
    $(function () {
        var $jsonField = $('#survey-fields-json');
        if ($jsonField.length) {
            loadFromJSON($jsonField.val());
            renderAll();
        }
    });

}(jQuery));
