(function (wp) {
  'use strict';

  var el         = wp.element.createElement;
  var Fragment   = wp.element.Fragment;
  var useState   = wp.element.useState;
  var useEffect  = wp.element.useEffect;
  var useSelect  = wp.data.useSelect;
  var useDispatch = wp.data.useDispatch;
  var registerPlugin             = wp.plugins.registerPlugin;
  var PluginSidebar              = wp.editPost.PluginSidebar;
  var PluginSidebarMoreMenuItem  = wp.editPost.PluginSidebarMoreMenuItem;

  var PanelBody      = wp.components.PanelBody;
  var ToggleControl  = wp.components.ToggleControl;
  var TextControl    = wp.components.TextControl;
  var SelectControl  = wp.components.SelectControl;
  var TextareaControl = wp.components.TextareaControl;
  var RangeControl   = wp.components.RangeControl;
  var Button         = wp.components.Button;
  var __             = wp.i18n.__;

  // ─────────────────────────────────────────────────────────────────────────────
  // HELPERS
  // ─────────────────────────────────────────────────────────────────────────────

  function parseMeta(raw, fallback) {
    try { return Object.assign({}, fallback, JSON.parse(raw || '{}')); }
    catch (e) { return Object.assign({}, fallback); }
  }

  // Validar selector CSS (MEJORADO v10.5.1)
  function validateSelector(selector) {
    if (!selector) return { valid: true, message: '', count: 0, suggestion: null };

    try {
      // Detectar errores comunes
      if (selector.startsWith('>')) {
        return {
          valid: false,
          message: '✗ La clase CSS no debería comenzar con ">"',
          count: 0,
          issue: 'INVALID_PREFIX',
          suggestion: selector.substring(1),
          help: 'Cambiar "' + selector + '" por "' + selector.substring(1) + '"'
        };
      }

      // Detectar espacios innecesarios
      if (selector.includes(' ') && !selector.includes('[')) {
        return {
          valid: false,
          message: '✗ Los selectores de clase no pueden tener espacios',
          count: 0,
          issue: 'SPACES_IN_CLASS',
          help: 'Cambia los espacios por guiones: .mi-clase'
        };
      }

      var count = document.querySelectorAll(selector).length;

      if (count > 0) {
        return {
          valid: true,
          message: '✓ Encontrados ' + count + ' elemento(s)',
          count: count,
          suggestion: null
        };
      }

      // Sin coincidencias - sugerir similares
      var suggestions = findSimilarSelectors(selector);
      return {
        valid: false,
        message: '⚠ Selector válido pero sin coincidencias en la página',
        count: 0,
        issue: 'NO_MATCHES',
        suggestions: suggestions,
        help: suggestions.length > 0 ?
          '¿Quisiste decir: ' + suggestions[0] + '?' :
          'Usa el botón "Detectar elementos" para ver las clases disponibles'
      };
    } catch (e) {
      return {
        valid: false,
        message: '✗ Selector CSS inválido',
        count: 0,
        issue: 'SYNTAX_ERROR',
        help: 'Sintaxis: .clase, #id, elemento, o combinaciones como button.primario'
      };
    }
  }

  // Encontrar selectores similares (NUEVO)
  function findSimilarSelectors(selector) {
    try {
      var all_classes = new Set();
      var buttons = document.querySelectorAll('button, a[role="button"], [data-toggle="modal"]');

      buttons.forEach(function(btn) {
        if (btn.className) {
          btn.className.split(' ').forEach(function(cls) {
            if (cls.trim()) all_classes.add('.' + cls.trim());
          });
        }
      });

      // Buscar similares usando distancia de Levenshtein simple
      var target = selector.replace(/[.#]/g, '');
      var matches = Array.from(all_classes).filter(function(cls) {
        return cls.toLowerCase().includes(target.toLowerCase());
      }).slice(0, 3);

      return matches;
    } catch (e) {
      return [];
    }
  }

  // Detectar elementos clickeables en la página (NUEVO)
  function detectClickableElements() {
    var elements = [];
    try {
      var clickables = document.querySelectorAll('button, a, [role="button"], input[type="button"], input[type="submit"]');
      clickables.forEach(function(el, idx) {
        if (idx < 20) { // Limitar a 20 elementos
          var classes = el.className ? '.' + el.className.split(' ').filter(Boolean).join('.') : '(sin clase)';
          var text = el.innerText || el.placeholder || el.value || '(sin texto)';
          text = text.substring(0, 40); // Truncar

          elements.push({
            text: text,
            classes: classes,
            tag: el.tagName.toLowerCase(),
            suggested_selector: el.className ? '.' + el.className.split(' ')[0] : el.id ? '#' + el.id : el.tagName.toLowerCase()
          });
        }
      });
    } catch (e) {}
    return elements;
  }

  // ─────────────────────────────────────────────────────────────────────────────
  // COMPONENTES
  // ─────────────────────────────────────────────────────────────────────────────

  /**
   * MethodPicker: Seleccionar cómo se abre el popup
   */
  function MethodPicker(props) {
    var value = props.value || 'click';
    var onChange = props.onChange || function() {};

    var methods = [
      {
        id: 'click',
        icon: '👆',
        label: 'Click en botón',
        description: 'El usuario hace click en un elemento específico',
      },
      {
        id: 'auto',
        icon: '⏱️',
        label: 'Automático',
        description: 'Se abre por tiempo, scroll o inactividad',
      },
      {
        id: 'manual',
        icon: '⚙️',
        label: 'Uso manual',
        description: 'Controlar con data-ep-open o JavaScript',
      },
    ];

    return el(
      'fieldset',
      { style: {
        border: 'none',
        padding: '12px 0',
      }},

      el('legend', { className: 'ep-method-picker-label' }, '¿Cómo se abre el popup?'),

      methods.map(function (method) {
        var isSelected = value === method.id;
        return el(
          'div',
          {
            key: method.id,
            onClick: function () { onChange(method.id); },
            className: 'ep-method-option' + (isSelected ? ' active' : ''),
          },
          el('input', {
            type: 'radio',
            name: 'ep-method',
            value: method.id,
            checked: isSelected,
            onChange: function () {},
            style: { cursor: 'pointer' },
          }),
          el('div', { className: 'ep-method-option-content' },
            el('div', { className: 'ep-method-option-label' }, method.icon + ' ' + method.label),
            el('div', { className: 'ep-method-option-description' }, method.description)
          )
        );
      })
    );
  }

  /**
   * ElementPickerControl: Elegir elemento que abre el popup (MEJORADO v10.5.1)
   */
  function ElementPickerControl(props) {
    var selector = props.value || '';
    var onChange = props.onChange || function() {};
    var popupId = props.popupId || '123';
    var popupTitle = props.popupTitle || 'Popup';
    var showDetector = useState(false);
    var isShowingDetector = showDetector[0];
    var setShowDetector = showDetector[1];

    var validation = validateSelector(selector);
    var detectedElements = detectClickableElements();

    return el(
      Fragment,
      null,

      // Input mejorado con ayuda contextual
      el('div', null,
        el('div', { style: { display: 'flex', gap: '8px', alignItems: 'flex-start' } },
          el('div', { style: { flex: 1 } },
            el(TextControl, {
              label: '¿En qué elemento?',
              help: 'Selector CSS: .clase, #id, elemento, o button.primario',
              placeholder: '.btn-cotizar',
              value: selector,
              onChange: onChange,
            })
          ),
          el(Button, {
            onClick: function() { setShowDetector(!isShowingDetector); },
            style: { marginTop: '28px', padding: '8px 12px', fontSize: '12px' },
            variant: 'secondary',
          }, '🔍 Detectar')
        )
      ),

      // Detector de elementos (NUEVO)
      isShowingDetector && detectedElements.length > 0 && el('div', {
        className: 'ep-detector-modal',
        style: {
          marginTop: '12px',
          padding: '12px',
          backgroundColor: '#f5f5f5',
          border: '1px solid #ddd',
          borderRadius: '4px',
          maxHeight: '250px',
          overflowY: 'auto',
        }
      },
        el('div', { style: { fontSize: '12px', fontWeight: '600', marginBottom: '8px' } },
          '🔍 Elementos clickeables en esta página:'),

        detectedElements.map(function(el_info, idx) {
          return el(
            'div',
            {
              key: idx,
              onClick: function() {
                if (el_info.suggested_selector) {
                  onChange(el_info.suggested_selector);
                  setShowDetector(false);
                }
              },
              style: {
                padding: '8px',
                marginBottom: '6px',
                backgroundColor: '#fff',
                border: '1px solid #ddd',
                borderRadius: '3px',
                cursor: 'pointer',
                fontSize: '11px',
              }
            },
            el('div', { style: { fontWeight: '500', marginBottom: '2px' } },
              '[' + el_info.tag + '] ' + el_info.text),
            el('div', { style: { color: '#666', fontSize: '10px' } },
              'Clase: ' + el_info.classes),
            el('div', { style: { color: '#999', fontSize: '10px', marginTop: '2px' } },
              'Usar: ' + el_info.suggested_selector)
          );
        })
      ),

      // Validación mejorada con sugerencias
      selector && el('div', {
        className: 'ep-selector-validation' + (validation.valid && validation.count > 0 ? ' valid' : validation.valid ? ' warning' : ' invalid'),
        style: {
          padding: '8px 12px',
          marginTop: '8px',
          borderRadius: '4px',
          fontSize: '12px',
          fontWeight: '500',
        }
      },
        el('span', { style: { marginRight: '6px' } },
          validation.valid && validation.count > 0 ? '✓' :
          validation.valid ? '⚠' : '✗'),
        el('span', null, validation.message),

        // Sugerencias dinámicas
        validation.suggestion && el('div', { style: { marginTop: '6px', fontSize: '11px' } },
          el('strong', null, '¿Quisiste decir: '),
          el('code', { style: { backgroundColor: '#fff', padding: '2px 4px', borderRadius: '2px' } }, validation.suggestion),
          el('button', {
            onClick: function() { onChange(validation.suggestion); },
            style: {
              marginLeft: '6px',
              padding: '2px 6px',
              fontSize: '11px',
              border: '1px solid #0073aa',
              backgroundColor: '#0073aa',
              color: '#fff',
              cursor: 'pointer',
              borderRadius: '2px',
            }
          }, 'Usar')
        ),

        // Mostrar sugerencias si no hay coincidencias
        !validation.valid && validation.suggestions && validation.suggestions.length > 0 &&
        el('div', { style: { marginTop: '6px', fontSize: '11px' } },
          'Clases similares: ',
          validation.suggestions.map(function(sug, i) {
            return el('button', {
              key: i,
              onClick: function() { onChange(sug); },
              style: {
                marginRight: '4px',
                padding: '2px 6px',
                fontSize: '11px',
                border: '1px solid #999',
                backgroundColor: '#f9f9f9',
                cursor: 'pointer',
                borderRadius: '2px',
              }
            }, sug);
          })
        )
      ),

      // Sugerencias de uso
      selector && validation.count > 0 && el('div', { className: 'ep-usage-instructions' },
        el('span', { className: 'ep-usage-instructions-title' }, '💡 ¿Cómo usar esto?'),

        el('div', { className: 'ep-usage-option' },
          el('span', { className: 'ep-usage-option-title' }, 'Opción 1: Si el elemento YA existe'),
          el('span', { className: 'ep-usage-option-desc' }, 'Agrega la clase al botón:'),
          el('code', { className: 'ep-usage-code' }, '<button class="' + selector + '">Abrir</button>')
        ),

        el('div', { className: 'ep-usage-option' },
          el('span', { className: 'ep-usage-option-title' }, 'Opción 2: Crear un botón nuevo'),
          el('span', { className: 'ep-usage-option-desc' }, 'Copia este código donde quieras:'),
          el('code', { className: 'ep-usage-code' }, '<button class="' + selector + '">Abrir ' + popupTitle + '</button>')
        )
      )
    );
  }

  /**
   * AutoTriggerPanel: Configurar triggers automáticos
   */
  function AutoTriggerPanel(props) {
    var triggers = props.triggers || {};
    var onChange = props.onChange || function() {};

    return el(
      Fragment,
      null,

      el(TextControl, {
        label: 'Después de (segundos)',
        help: '0 = desactivado. Ejemplo: 5 para que se abra a los 5 segundos',
        type: 'number',
        min: 0,
        value: triggers.time || 0,
        onChange: function (v) { onChange({ time: parseInt(v, 10) || 0 }); },
      }),

      el(TextControl, {
        label: 'Al hacer scroll (% de página)',
        help: '0 = desactivado. Ejemplo: 50 para la mitad de la página',
        type: 'number',
        min: 0,
        max: 100,
        value: triggers.scroll || 0,
        onChange: function (v) { onChange({ scroll: parseInt(v, 10) || 0 }); },
      }),

      el(TextControl, {
        label: 'Si el usuario no se mueve (segundos)',
        help: '0 = desactivado. Ejemplo: 30 segundos sin actividad',
        type: 'number',
        min: 0,
        value: triggers.inactivity || 0,
        onChange: function (v) { onChange({ inactivity: parseInt(v, 10) || 0 }); },
      })
    );
  }

  /**
   * ManualUsagePanel: Instrucciones para uso manual
   */
  function ManualUsagePanel(props) {
    var popupId = props.popupId || '123';
    var popupTitle = props.popupTitle || 'Popup';

    return el(
      Fragment,
      null,

      el('div', { className: 'ep-manual-usage-section' },
        el('div', { className: 'ep-manual-code-block' },
          el('span', { className: 'ep-manual-code-block-title' }, 'Método 1: Atributo data-ep-open'),
          el('span', { className: 'ep-manual-code-block-desc' }, 'Añade este atributo a cualquier elemento:'),
          el('code', { className: 'ep-usage-code' }, '<button data-ep-open="' + popupId + '">Abrir</button>')
        ),

        el('div', { className: 'ep-manual-code-block' },
          el('span', { className: 'ep-manual-code-block-title' }, 'Método 2: Evento JavaScript'),
          el('span', { className: 'ep-manual-code-block-desc' }, 'Desde cualquier script:'),
          el('code', { className: 'ep-usage-code' }, 'document.dispatchEvent(new CustomEvent("estandar_pop:open", { detail: { id: ' + popupId + ' } }));')
        )
      )
    );
  }

  /**
   * PreviewPane: Vista previa del popup
   */
  function PreviewPane(props) {
    var design = props.design || {};
    var popupTitle = props.popupTitle || 'Popup';
    var useState_state = useState(false);
    var isOpen = useState_state[0];
    var setIsOpen = useState_state[1];

    return el(
      'div',
      { className: 'ep-preview-pane' },

      el('div', { className: 'ep-preview-pane-title' }, '👁️ Vista Previa'),

      el('div', { className: 'ep-preview-pane-window' },
        isOpen
          ? el('div', { className: 'ep-preview-popup-content' },
            el('p', null, 'Contenido del popup'),
            el('button', {
              onClick: function() { setIsOpen(false); },
              className: 'ep-preview-close-btn',
            }, 'Cerrar')
          )
          : el('div', { style: { textAlign: 'center', color: '#666' } },
            el('p', null, 'Sin abrir'),
            el('p', { style: { fontSize: '11px', color: '#999' } },
              'Tamaño: ' + (design.size || 'medium'))
          )
      ),

      el('button', {
        onClick: function() { setIsOpen(!isOpen); },
        className: 'ep-preview-toggle-btn',
      }, isOpen ? 'Cerrar preview' : 'Abrir preview')
    );
  }

  // ─────────────────────────────────────────────────────────────────────────────
  // SIDEBAR PRINCIPAL (REFACTORIZADO)
  // ─────────────────────────────────────────────────────────────────────────────

  function EpSidebar() {
    var postType = useSelect(function (select) {
      return select('core/editor').getCurrentPostType();
    });

    if (postType !== 'estandar_pop') return null;

    var postId = useSelect(function (select) {
      return select('core/editor').getCurrentPostId();
    });

    var postTitle = useSelect(function (select) {
      return select('core/editor').getEditedPostAttribute('title');
    });

    var meta = useSelect(function (select) {
      return select('core/editor').getEditedPostAttribute('meta') || {};
    });

    var editPost = useDispatch('core/editor').editPost;

    function setMeta(key, value) {
      var update = {};
      update[key] = value;
      editPost({ meta: update });
    }

    // Parsed meta
    var active = !!meta._ep_active;
    var openMethod = meta._ep_open_method || 'click';

    var triggers = parseMeta(meta._ep_triggers, {
      time: 0, scroll: 0, inactivity: 0, click_selector: '',
    });

    var seg = parseMeta(meta._ep_segmentation, {
      post_ids: [], url_contains: [], date_start: '', date_end: '',
    });

    var freq = parseMeta(meta._ep_frequency, { mode: '7days', days: 0 });

    var design = parseMeta(meta._ep_design, {
      size: 'medium', custom_width: '', css_mode: 'inherit',
      overlay: true, overlay_color: '#000000', overlay_opacity: 0.6,
      animation: 'fade', z_index: 99999,
    });

    var customCss = meta._ep_custom_css || '';

    // Setters
    function setTriggers(patch) {
      setMeta('_ep_triggers', JSON.stringify(Object.assign({}, triggers, patch)));
    }

    function setOpenMethod(method) {
      setMeta('_ep_open_method', method);
      // Reset triggers when changing method
      if (method === 'manual') {
        setTriggers({ time: 0, scroll: 0, inactivity: 0, click_selector: '' });
      }
    }

    function setSeg(patch) {
      setMeta('_ep_segmentation', JSON.stringify(Object.assign({}, seg, patch)));
    }

    function setFreq(patch) {
      setMeta('_ep_frequency', JSON.stringify(Object.assign({}, freq, patch)));
    }

    function setDesign(patch) {
      setMeta('_ep_design', JSON.stringify(Object.assign({}, design, patch)));
    }

    var urlText = (seg.url_contains || []).join('\n');
    function setUrlContains(text) {
      var lines = text.split('\n').map(function (s) { return s.trim(); }).filter(Boolean);
      setSeg({ url_contains: lines });
    }

    var idsText = (seg.post_ids || []).join(', ');
    function setPostIds(text) {
      var ids = text.split(',').map(function (s) { return parseInt(s.trim(), 10); }).filter(function (n) { return !isNaN(n) && n > 0; });
      setSeg({ post_ids: ids });
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // RENDER
    // ─────────────────────────────────────────────────────────────────────────────

    return el(
      Fragment,
      null,

      el(PluginSidebarMoreMenuItem, { target: 'ep-sidebar' }, 'Estandar Pop'),

      el(
        PluginSidebar,
        { name: 'ep-sidebar', title: 'Estandar Pop' },

        // ════ GENERAL ════
        el(PanelBody, { title: 'General', initialOpen: true },
          el(ToggleControl, {
            label:    'Popup activo',
            checked:  active,
            onChange: function (v) { setMeta('_ep_active', v); },
          })
        ),

        // ════ CÓMO SE ABRE (IMPORTANTE - PRIMERO) ════
        el(PanelBody, {
          title: '🎯 Cómo se abre',
          initialOpen: true,
          className: 'ep-panel-important',
        },
          el(MethodPicker, {
            value: openMethod,
            onChange: setOpenMethod,
          }),

          el('div', { style: { marginTop: '16px' } },
            openMethod === 'click' && el(
              ElementPickerControl,
              {
                value: triggers.click_selector,
                onChange: function (v) { setTriggers({ click_selector: v }); },
                popupId: postId,
                popupTitle: postTitle || 'Popup',
              }
            ),

            openMethod === 'auto' && el(
              AutoTriggerPanel,
              {
                triggers: triggers,
                onChange: setTriggers,
              }
            ),

            openMethod === 'manual' && el(
              ManualUsagePanel,
              {
                popupId: postId,
                popupTitle: postTitle || 'Popup',
              }
            )
          )
        ),

        // ════ SEGMENTACIÓN ════
        el(PanelBody, { title: 'Segmentación', initialOpen: false },
          el(TextControl, {
            label:    'IDs de páginas (separados por coma)',
            value:    idsText,
            onChange: setPostIds,
            help:     'Vacío = todo el sitio.',
          }),
          el(TextareaControl, {
            label:    'URL contiene (una por línea)',
            value:    urlText,
            onChange: setUrlContains,
            help:     'El popup aparece si la URL contiene alguno de estos textos.',
            rows:     3,
          }),
          el(TextControl, {
            label:    'Fecha inicio (YYYY-MM-DD)',
            type:     'date',
            value:    seg.date_start || '',
            onChange: function (v) { setSeg({ date_start: v }); },
          }),
          el(TextControl, {
            label:    'Fecha fin (YYYY-MM-DD)',
            type:     'date',
            value:    seg.date_end || '',
            onChange: function (v) { setSeg({ date_end: v }); },
          })
        ),

        // ════ COMPORTAMIENTO ════
        el(PanelBody, { title: 'Comportamiento', initialOpen: false },
          el(SelectControl, {
            label:    'Frecuencia',
            value:    freq.mode || '7days',
            options:  [
              { label: 'Siempre',      value: 'always'     },
              { label: 'Por sesión',   value: 'session'    },
              { label: '1 día',        value: '1day'       },
              { label: '7 días',       value: '7days'      },
              { label: '30 días',      value: '30days'     },
              { label: 'Solo una vez', value: 'once_ever'  },
              { label: 'Personalizado', value: 'custom'    },
            ],
            onChange: function (v) { setFreq({ mode: v }); },
          }),
          freq.mode === 'custom' && el(TextControl, {
            label:    'Días entre apariciones',
            type:     'number',
            min:      1,
            value:    freq.days || 1,
            onChange: function (v) { setFreq({ days: parseInt(v, 10) || 1 }); },
          })
        ),

        // ════ DISEÑO ════
        el(PanelBody, { title: 'Diseño', initialOpen: false },
          el(SelectControl, {
            label:    'Tamaño',
            value:    design.size || 'medium',
            options:  [
              { label: 'Pequeño (400px)',  value: 'small'      },
              { label: 'Mediano (600px)',  value: 'medium'     },
              { label: 'Grande (800px)',   value: 'large'      },
              { label: 'Pantalla completa', value: 'fullscreen' },
              { label: 'Personalizado',    value: 'custom'     },
            ],
            onChange: function (v) { setDesign({ size: v }); },
          }),
          design.size === 'custom' && el(TextControl, {
            label:    'Ancho (ej: 720px)',
            value:    design.custom_width || '',
            onChange: function (v) { setDesign({ custom_width: v }); },
          }),
          el(ToggleControl, {
            label:    'Overlay (fondo oscuro)',
            checked:  !!design.overlay,
            onChange: function (v) { setDesign({ overlay: v }); },
          }),
          design.overlay && el(RangeControl, {
            label:    'Opacidad del overlay',
            value:    parseFloat(design.overlay_opacity) || 0.6,
            min:      0,
            max:      1,
            step:     0.05,
            onChange: function (v) { setDesign({ overlay_opacity: v }); },
          }),
          el(SelectControl, {
            label:    'Animación',
            value:    design.animation || 'fade',
            options:  [
              { label: 'Ninguna', value: 'none'  },
              { label: 'Fade',    value: 'fade'  },
              { label: 'Slide',   value: 'slide' },
            ],
            onChange: function (v) { setDesign({ animation: v }); },
          })
        ),

        // ════ VISTA PREVIA ════
        el(PreviewPane, {
          design: design,
          popupTitle: postTitle || 'Popup',
        }),

        // ════ CSS (AVANZADO) ════
        el(PanelBody, { title: 'CSS avanzado', initialOpen: false },
          el(SelectControl, {
            label:    'Modo CSS',
            value:    design.css_mode || 'inherit',
            options:  [
              { label: 'Heredar estilos del tema', value: 'inherit'     },
              { label: 'Encapsulado',              value: 'encapsulated' },
            ],
            onChange: function (v) { setDesign({ css_mode: v }); },
            help:     '"Encapsulado" normaliza box-model. No soporta @keyframes ni @font-face.',
          }),
          el(TextareaControl, {
            label:    'CSS personalizado',
            value:    customCss,
            onChange: function (v) { setMeta('_ep_custom_css', v); },
            rows:     6,
          })
        )
      )
    );
  }

  registerPlugin('estandar-pop', { render: EpSidebar });

})(window.wp);
