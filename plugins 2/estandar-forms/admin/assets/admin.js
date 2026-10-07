jQuery(function ($) {

    const $builder = $('#fields-builder');
    const $preview = $('#form-preview');
    const $json = $('#fields_json');
    const $fieldCount = $('#estandar-field-count');
    const $builderHint = $('#estandar-builder-hint');
    const $emptyState = $('#fields-empty-state');
    const $slugInput = $('#form_slug');
    const $nameInput = $('#form_name');
    const $formId = $('#form_id');
    const $formCountry = $('#form_country');
    const $shortcodeSlug = $('#estandar-shortcode-slug');
    const $shortcodeId = $('#estandar-shortcode-id');
    const PHONE_PLACEHOLDERS = {
        CL: '+56 9 1234 5678',
        AR: '+54 11 1234 5678',
        PE: '+51 912 345 678',
        MX: '+52 55 1234 5678',
        ES: '+34 612 345 678',
        US: '+1 202 555 0123'
    };
    const FIELD_TYPES = [
        { value: 'text',             label: 'Texto' },
        { value: 'email',            label: 'Email' },
        { value: 'tel',              label: 'Telefono' },
        { value: 'number',           label: 'Numero' },
        { value: 'textarea',         label: 'Textarea' },
        { value: 'date',             label: 'Fecha' },
        { value: 'select',           label: 'Lista desplegable' },
        { value: 'dependent_select', label: 'Select dependiente' },
        { value: 'checkbox',         label: 'Checkbox' },
        { value: 'radio',            label: 'Radio buttons' },
        { value: 'heading',          label: 'Titulo / Texto' },
        { value: 'html',             label: 'Bloque HTML' },
        { value: 'rut',              label: 'RUT (Chile)' },
        { value: 'file',             label: 'Archivo / Imagen' }
    ];

    // Location presets — each key expands into N field definitions when inserted.
    // V2: add a 'world' preset with country → region → city.
    const LOCATION_PRESETS = {
        chile: {
            fields: [
                { type: 'select',           name: 'region', label: 'Región',  dataset: 'cl_regions',          required: true, column_width: '3', placeholder: 'Seleccione una región' },
                { type: 'dependent_select', name: 'city',   label: 'Comuna', dataset: 'cl_regions_communes', depends_on: 'region', required: true, column_width: '3', placeholder: 'Seleccione una comuna' }
            ]
        }
    };

    function renderTypeOptions(selected) {
        return FIELD_TYPES.map(type =>
            `<option value="${type.value}" ${selected === type.value ? 'selected' : ''}>${type.label}</option>`
        ).join('');
    }

    function normalizeOptions(raw) {
        if (Array.isArray(raw)) {
            return raw;
        }

        if (!raw) {
            return [];
        }

        return raw.split('\n').map(line => {
            const clean = line.trim();
            if (!clean) {
                return null;
            }

            const parts = clean.split('|').map(part => part.trim());
            return { value: parts[0], label: parts[1] || parts[0] };
        }).filter(Boolean);
    }

    function optionsToMultiline(options) {
        return normalizeOptions(options)
            .map(option => option.label !== option.value ? `${option.value}|${option.label}` : option.value)
            .join('\n');
    }

    function escapeHtml(str) {
        return $('<div>').text(str || '').html();
    }

    function escapeAttr(str) {
        return escapeHtml(str).replace(/"/g, '&quot;');
    }

    function typeLabel(type) {
        const match = FIELD_TYPES.find(item => item.value === type);
        return match ? match.label : type;
    }

    function widthLabel(value) {
        if (value === '3') {
            return 'Ancho completo';
        }

        if (value === '2') {
            return '2 columnas';
        }

        return '3 columnas';
    }

    function normalizeConditionalLogic(raw) {
        if (!raw || typeof raw !== 'object') {
            return {};
        }

        const operator = ['equals', 'not_equals', 'contains', 'not_empty', 'empty'].includes(raw.operator)
            ? raw.operator
            : 'equals';
        const field = (raw.field || '').toString().trim().replace(/[^a-z0-9_]/gi, '').toLowerCase();

        if (!field) {
            return {};
        }

        return {
            field,
            operator,
            value: (raw.value || '').toString()
        };
    }

    function getFieldChoices(currentRow) {
        const choices = [];

        $builder.find('.field-row').each(function () {
            const $row = $(this);
            if (currentRow && $row.is(currentRow)) {
                return;
            }

            const type = $row.find('.field-type').val();
            if (type === 'heading' || type === 'html') {
                return;
            }

            const name = ($row.find('.field-name').val() || '').trim();
            if (!name) {
                return;
            }

            choices.push({
                name,
                label: ($row.find('.field-label').val() || name).trim()
            });
        });

        return choices;
    }

    function renderConditionalOptions(selected, currentRow) {
        const choices = getFieldChoices(currentRow);
        let html = '<option value="">Sin condicion</option>';

        choices.forEach(choice => {
            html += `<option value="${escapeAttr(choice.name)}" ${selected === choice.name ? 'selected' : ''}>${escapeHtml(choice.label)} (${escapeHtml(choice.name)})</option>`;
        });

        return html;
    }

    function toSlug(value) {
        return (value || '')
            .toString()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
    }

    function inferFieldName($row) {
        const $name = $row.find('.field-name');
        if ($name.val()) {
            return;
        }

        const label = $row.find('.field-label').val();
        if (label) {
            $name.val(toSlug(label).replace(/-/g, '_'));
        }
    }

    function updateRowUI($row) {
        const type = $row.find('.field-type').val();
        const isVisual = type === 'html' || type === 'heading';

        const hasDataset = !!$row.find('.field-dataset').val();
        $row.find('.field-options-wrap').toggle((type === 'select' || type === 'radio') && !hasDataset);
        $row.find('.field-dataset-info').toggle(hasDataset);
        $row.find('.field-type').prop('disabled', hasDataset);
        $row.find('.field-html-wrap').toggle(type === 'html');
        $row.find('.field-heading-wrap').toggle(type === 'heading');
        $row.find('.field-error-wrap').toggle(!isVisual);
        $row.find('.field-conditional-wrap').toggle(!isVisual);

        $row.find('.field-name, .field-label, .field-required, .field-error-message')
            .prop('disabled', isVisual);
        $row.find('.field-column-width, .field-conditional-field, .field-conditional-operator, .field-conditional-value')
            .prop('disabled', isVisual);

        const title = isVisual
            ? type === 'heading'
                ? ($row.find('.field-heading-text').val() || 'Titulo sin texto')
                : 'Bloque HTML'
            : ($row.find('.field-label').val() || $row.find('.field-name').val() || 'Campo sin etiqueta');

        $row.find('.field-card-title').text(title);
        const conditionalField = $row.find('.field-conditional-field').val();
        const conditionMeta = conditionalField ? ' · Condicional' : '';
        $row.find('.field-card-meta').text(`${typeLabel(type)} · ${widthLabel($row.find('.field-column-width').val() || '3')}${conditionMeta}`);
        $row.find('.field-conditional-field').html(renderConditionalOptions(conditionalField, $row));
        const operator = $row.find('.field-conditional-operator').val() || 'equals';
        const needsValue = !['not_empty', 'empty'].includes(operator);
        $row.find('.field-conditional-value').prop('disabled', !needsValue).closest('label').toggleClass('is-disabled', !needsValue);
    }

    function updateBuilderState(fields) {
        const count = fields.length;
        $fieldCount.text(count);

        if (count === 0) {
            $builderHint.text('Agrega un campo para comenzar.');
            $emptyState.prop('hidden', false);
        } else {
            $builderHint.text(count === 1 ? '1 bloque en tu formulario.' : `${count} bloques listos para publicar.`);
            $emptyState.prop('hidden', true);
        }
    }

    function updateShortcodes() {
        const slug = $slugInput.val() || 'mi-formulario';
        const id = $formId.val();

        $shortcodeSlug.text(`[estandar_form slug="${slug}"]`);
        $shortcodeId.text(id ? `[estandar_form id="${id}"]` : 'Disponible al guardar');
    }

    function renderPreview(fields) {
        let html = '<form class="estandar-preview-grid">';
        const currentCountry = $formCountry.val() || 'CL';

        fields.forEach(field => {
            if (field.type === 'heading') {
                const level = field.level || 'h3';
                const align = field.align || 'left';
                html += `<${level} style="text-align:${align};margin:16px 0 8px;">${escapeHtml(field.text || '')}</${level}>`;
                return;
            }

            if (field.type === 'html') {
                html += `<div class="estandar-form-html">${field.html || ''}</div>`;
                return;
            }

            html += `<div class="estandar-preview-span-${field.column_width || '3'}" style="margin:12px 0;">`;

            if (field.label) {
                html += `<label style="display:block;font-weight:600;">${escapeHtml(field.label)}</label>`;
            }

            switch (field.type) {
                case 'textarea':
                    html += '<textarea></textarea>';
                    break;
                case 'select':
                    if (field.dataset) {
                        html += `<select><option value="">— ${escapeHtml(field.label || 'Selecciona')} —</option></select>`;
                    } else {
                        html += '<select><option value="">Selecciona</option>';
                        normalizeOptions(field.options).forEach(option => {
                            html += `<option>${escapeHtml(option.label)}</option>`;
                        });
                        html += '</select>';
                    }
                    break;
                case 'dependent_select':
                    html += `<select><option value="">— ${escapeHtml(field.label || 'Selecciona')} —</option></select>`;
                    break;
                case 'radio':
                    normalizeOptions(field.options).forEach(option => {
                        html += `<label><input type="radio"> ${escapeHtml(option.label)}</label><br>`;
                    });
                    break;
                case 'checkbox':
                    html += `<label><input type="checkbox"> ${escapeHtml(field.label || 'Acepto')}</label>`;
                    break;
                case 'file':
                    html += '<input type="file">';
                    break;
                case 'tel':
                    html += `<input type="tel" placeholder="${escapeAttr(PHONE_PLACEHOLDERS[currentCountry] || PHONE_PLACEHOLDERS.CL)}">`;
                    break;
                case 'rut':
                    html += '<input type="text" placeholder="12.345.678-5">';
                    break;
                default:
                    html += `<input type="${escapeAttr(field.type)}">`;
            }

            html += '</div>';
        });

        html += '</form>';
        $preview.html(html);
    }

    function sync() {
        const fields = [];

        $builder.find('.field-row').each(function () {
            const $row = $(this);
            const type = $row.find('.field-type').val();
            const field = { type };

            if (type === 'heading') {
                field.text = $row.find('.field-heading-text').val() || '';
                field.level = $row.find('.field-heading-level').val() || 'h3';
                field.align = $row.find('.field-heading-align').val() || 'left';
            } else if (type === 'html') {
                field.html = $row.find('.field-html').val() || '';
            } else {
                field.name = $row.find('.field-name').val() || '';
                field.label = $row.find('.field-label').val() || '';
                field.required = $row.find('.field-required').is(':checked');
                field.error_message = $row.find('.field-error-message').val() || '';
                field.column_width = $row.find('.field-column-width').val() || '3';
                field.conditional_logic = normalizeConditionalLogic({
                    field: $row.find('.field-conditional-field').val() || '',
                    operator: $row.find('.field-conditional-operator').val() || 'equals',
                    value: $row.find('.field-conditional-value').val() || ''
                });

                if (type === 'select' || type === 'radio') {
                    field.options = normalizeOptions($row.find('.field-options').val());
                }

                // Preserve dataset-backed configuration (select with dataset, dependent_select).
                const fieldDataset = $row.find('.field-dataset').val();
                if (fieldDataset) {
                    field.dataset = fieldDataset;
                    const fieldDependsOn = $row.find('.field-depends-on').val();
                    if (fieldDependsOn) field.depends_on = fieldDependsOn;
                    const fieldValueKey  = $row.find('.field-value-key').val();
                    if (fieldValueKey)  field.value_key = fieldValueKey;
                    const fieldLabelKey  = $row.find('.field-label-key').val();
                    if (fieldLabelKey)  field.label_key = fieldLabelKey;
                    const fieldParentKey = $row.find('.field-parent-key').val();
                    if (fieldParentKey) field.parent_key = fieldParentKey;
                    delete field.options; // dataset fields don't use inline options
                }
            }

            fields.push(field);
            updateRowUI($row);
        });

        $json.val(JSON.stringify(fields));
        updateBuilderState(fields);
        updateShortcodes();
        renderPreview(fields);
    }

    function rowTemplate(field = {}) {
        const type = field.type || 'text';
        const title = type === 'heading'
            ? (field.text || 'Titulo sin texto')
            : type === 'html'
                ? 'Bloque HTML'
                : (field.label || field.name || 'Campo sin etiqueta');

        return `
            <div class="field-row">
                <div class="field-card">
                    <div class="field-card-header">
                        <div class="field-card-header-main">
                            <span class="dashicons dashicons-move"></span>
                            <div>
                                <strong class="field-card-title">${escapeHtml(title)}</strong>
                                <span class="field-card-meta">${escapeHtml(typeLabel(type))}</span>
                            </div>
                        </div>
                        <div class="field-card-actions">
                            <button type="button" class="button-link field-toggle">Plegar</button>
                            <button type="button" class="button clone-field">Clonar</button>
                            <button type="button" class="button remove-field">Eliminar</button>
                        </div>
                    </div>
                    <div class="field-card-body">
                        <div class="field-row-grid">
                            <label>
                                <span>Nombre tecnico</span>
                                <input class="field-name" placeholder="nombre_campo" value="${escapeAttr(field.name || '')}" ${type === 'html' || type === 'heading' ? 'disabled' : ''}>
                            </label>
                            <label>
                                <span>Etiqueta visible</span>
                                <input class="field-label" placeholder="Nombre" value="${escapeAttr(field.label || '')}" ${type === 'html' || type === 'heading' ? 'disabled' : ''}>
                            </label>
                            <label>
                                <span>Tipo</span>
                                <select class="field-type">${renderTypeOptions(type)}</select>
                            </label>
                            <label class="field-required-label">
                                <span>Requerido</span>
                                <input type="checkbox" class="field-required" ${field.required ? 'checked' : ''} ${type === 'html' || type === 'heading' ? 'disabled' : ''}>
                            </label>
                            <label>
                                <span>Ancho</span>
                                <select class="field-column-width" ${type === 'html' || type === 'heading' ? 'disabled' : ''}>
                                    <option value="3" ${!field.column_width || field.column_width === '3' ? 'selected' : ''}>Ancho completo</option>
                                    <option value="2" ${field.column_width === '2' ? 'selected' : ''}>2 columnas</option>
                                    <option value="1" ${field.column_width === '1' ? 'selected' : ''}>3 columnas</option>
                                </select>
                            </label>
                        </div>

                        <div class="field-error-wrap" style="display:${type === 'html' || type === 'heading' ? 'none' : 'block'};">
                            <label>
                                <span>Mensaje de error personalizado</span>
                                <input class="field-error-message" placeholder="Ej: Debes completar este campo" value="${escapeAttr(field.error_message || '')}">
                            </label>
                        </div>

                        <div class="field-conditional-wrap" style="display:${type === 'html' || type === 'heading' ? 'none' : 'block'};">
                            <div class="field-row-grid">
                                <label>
                                    <span>Mostrar solo si</span>
                                    <select class="field-conditional-field">
                                        ${renderConditionalOptions((field.conditional_logic || {}).field || '', null)}
                                    </select>
                                </label>
                                <label>
                                    <span>Condicion</span>
                                    <select class="field-conditional-operator">
                                        <option value="equals" ${!field.conditional_logic || field.conditional_logic.operator === 'equals' ? 'selected' : ''}>Es igual a</option>
                                        <option value="not_equals" ${field.conditional_logic && field.conditional_logic.operator === 'not_equals' ? 'selected' : ''}>Es distinto de</option>
                                        <option value="contains" ${field.conditional_logic && field.conditional_logic.operator === 'contains' ? 'selected' : ''}>Contiene</option>
                                        <option value="not_empty" ${field.conditional_logic && field.conditional_logic.operator === 'not_empty' ? 'selected' : ''}>Tiene valor</option>
                                        <option value="empty" ${field.conditional_logic && field.conditional_logic.operator === 'empty' ? 'selected' : ''}>Esta vacio</option>
                                    </select>
                                </label>
                                <label>
                                    <span>Valor esperado</span>
                                    <input class="field-conditional-value" value="${escapeAttr(field.conditional_logic && field.conditional_logic.value ? field.conditional_logic.value : '')}" placeholder="Ej: empresa">
                                </label>
                            </div>
                            <p class="description">Permite mostrar el campo solo cuando otro campo cumple la condicion.</p>
                        </div>

                        <div class="field-options-wrap" style="display:${type === 'select' || type === 'radio' ? 'block' : 'none'};">
                            <label>
                                <span>Opciones</span>
                                <textarea class="field-options" rows="4" placeholder="opcion|Etiqueta visible">${optionsToMultiline(field.options || [])}</textarea>
                            </label>
                            <p class="description">Usa una linea por opcion. Formato opcional: <code>valor|Etiqueta</code>.</p>
                        </div>

                        <div class="field-html-wrap" style="display:${type === 'html' ? 'block' : 'none'};">
                            <label>
                                <span>HTML</span>
                                <textarea class="field-html" rows="5">${escapeHtml(field.html || '')}</textarea>
                            </label>
                        </div>

                        <div class="field-heading-wrap" style="display:${type === 'heading' ? 'block' : 'none'};">
                            <label>
                                <span>Texto del titulo</span>
                                <input class="field-heading-text" value="${escapeAttr(field.text || '')}">
                            </label>
                            <div class="field-row-grid">
                                <label>
                                    <span>Jerarquia</span>
                                    <select class="field-heading-level">
                                        <option value="h1" ${field.level === 'h1' ? 'selected' : ''}>H1</option>
                                        <option value="h2" ${field.level === 'h2' ? 'selected' : ''}>H2</option>
                                        <option value="h3" ${!field.level || field.level === 'h3' ? 'selected' : ''}>H3</option>
                                        <option value="h4" ${field.level === 'h4' ? 'selected' : ''}>H4</option>
                                    </select>
                                </label>
                                <label>
                                    <span>Alineacion</span>
                                    <select class="field-heading-align">
                                        <option value="left" ${!field.align || field.align === 'left' ? 'selected' : ''}>Izquierda</option>
                                        <option value="center" ${field.align === 'center' ? 'selected' : ''}>Centro</option>
                                        <option value="right" ${field.align === 'right' ? 'selected' : ''}>Derecha</option>
                                    </select>
                                </label>
                            </div>
                        </div>

                        <!-- Dataset hidden inputs: preserve dataset-backed field config through sync. -->
                        <input type="hidden" class="field-dataset"     value="${escapeAttr(field.dataset     || '')}">
                        <input type="hidden" class="field-depends-on"  value="${escapeAttr(field.depends_on  || '')}">
                        <input type="hidden" class="field-value-key"   value="${escapeAttr(field.value_key   || '')}">
                        <input type="hidden" class="field-label-key"   value="${escapeAttr(field.label_key   || '')}">
                        <input type="hidden" class="field-parent-key"  value="${escapeAttr(field.parent_key  || '')}">

                        <!-- Dataset info badge: visible when field is backed by a named dataset. -->
                        <div class="field-dataset-info" style="display:${field.dataset ? 'block' : 'none'};">
                            <p class="description" style="margin:8px 0 0;">
                                📊 Dataset: <code>${escapeHtml(field.dataset || '')}</code>${field.depends_on ? ` &nbsp;·&nbsp; Filtrado por: <code>${escapeHtml(field.depends_on)}</code>` : ''}
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }

    function appendField(field = {}) {
        const $row = $(rowTemplate(field));
        $builder.append($row);
        updateRowUI($row);
        sync();
    }

    $builder.sortable({
        handle: '.dashicons-move',
        items: '.field-row',
        tolerance: 'pointer',
        update: sync
    });

    $('#add-field').on('click', function () {
        appendField();
    });

    $('.estandar-quick-add').on('click', function () {
        const type = $(this).data('type') || 'text';
        if (type === 'ubicacion') {
            // Expand the macro: insert each field of the Chile preset independently.
            LOCATION_PRESETS.chile.fields.forEach(field => appendField(Object.assign({}, field)));
            return;
        }
        appendField({ type });
    });

    $builder.on('input', '.field-label', function () {
        inferFieldName($(this).closest('.field-row'));
        sync();
    });

    $builder.on('input change', 'input, select, textarea', function () {
        const $row = $(this).closest('.field-row');
        if ($(this).hasClass('field-type')) {
            updateRowUI($row);
        }
        sync();
    });

    $builder.on('click', '.remove-field', function () {
        $(this).closest('.field-row').remove();
        sync();
    });

    $builder.on('click', '.clone-field', function () {
        const $row = $(this).closest('.field-row');
        const $clone = $(rowTemplate({
            type: $row.find('.field-type').val(),
            name: '',
            label: $row.find('.field-label').val(),
            required: $row.find('.field-required').is(':checked'),
            error_message: $row.find('.field-error-message').val(),
            column_width: $row.find('.field-column-width').val() || '3',
            conditional_logic: normalizeConditionalLogic({
                field: $row.find('.field-conditional-field').val() || '',
                operator: $row.find('.field-conditional-operator').val() || 'equals',
                value: $row.find('.field-conditional-value').val() || ''
            }),
            options: normalizeOptions($row.find('.field-options').val()),
            html: $row.find('.field-html').val(),
            text: $row.find('.field-heading-text').val(),
            level: $row.find('.field-heading-level').val(),
            align: $row.find('.field-heading-align').val(),
            // Dataset-backed fields.
            dataset:     $row.find('.field-dataset').val()    || undefined,
            depends_on:  $row.find('.field-depends-on').val() || undefined,
            value_key:   $row.find('.field-value-key').val()  || undefined,
            label_key:   $row.find('.field-label-key').val()  || undefined,
            parent_key:  $row.find('.field-parent-key').val() || undefined
        }));

        $clone.insertAfter($row);
        updateRowUI($clone);
        sync();
    });

    $builder.on('click', '.field-toggle', function () {
        const $button = $(this);
        const $row = $button.closest('.field-row');
        $row.toggleClass('is-collapsed');
        $button.text($row.hasClass('is-collapsed') ? 'Expandir' : 'Plegar');
    });

    $slugInput.on('input', updateShortcodes);
    $formCountry.on('change', sync);
    $nameInput.on('input', function () {
        if (!$slugInput.val()) {
            $slugInput.val(toSlug($nameInput.val()));
        }
        updateShortcodes();
    });

    if (window.ESTANDAR_FORM_FIELDS && window.ESTANDAR_FORM_FIELDS.length) {
        window.ESTANDAR_FORM_FIELDS.forEach(field => {
            const $row = $(rowTemplate(field));
            $builder.append($row);
            updateRowUI($row);
        });
        sync();
    } else {
        updateShortcodes();
        updateBuilderState([]);
    }
});
