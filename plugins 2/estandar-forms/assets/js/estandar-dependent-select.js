/**
 * Estandar Forms — Dependent Select
 *
 * Escucha cambios en un project_select y puebla el dependent_select
 * correspondiente via AJAX (ef_get_streets).
 *
 * Selectores:
 *   [data-ef-project-select]  — el select de proyectos
 *   [data-ef-depends-on]      — el select dependiente (value = nombre del campo fuente)
 *
 * Datos globales inyectados via wp_localize_script:
 *   window.EfDependentSelect = { ajaxUrl, nonce, loadingText, emptyText }
 *
 * @since 4.0.0
 */

(function () {
  'use strict';

  var cfg = window.EfDependentSelect || {};
  var ajaxUrl   = cfg.ajaxUrl   || (window.ajaxurl || '');
  var nonce     = cfg.nonce     || '';
  var loadingText = cfg.loadingText || 'Cargando…';
  var emptyText   = cfg.emptyText   || '— Selecciona proyecto primero —';
  var noStreetsText = cfg.noStreetsText || '— Sin calles registradas —';

  // ─── Inicializar en cada formulario del DOM ────────────────────────────────

  function init() {
    var forms = document.querySelectorAll('.estandar-form');
    if (!forms.length) return;

    forms.forEach(function (form) {
      wireForm(form);
    });
  }

  function wireForm(form) {
    var projectSelects = form.querySelectorAll('[data-ef-project-select]');
    projectSelects.forEach(function (projectSelect) {
      var fieldName = projectSelect.getAttribute('data-ef-project-select') || projectSelect.name;

      // Buscar el dependent_select que depende de este campo
      var dependent = form.querySelector('[data-ef-depends-on="' + fieldName + '"]');
      if (!dependent) return;

      // Cuando cambia el proyecto: fetch calles
      projectSelect.addEventListener('change', function () {
        loadStreets(projectSelect.value, dependent);
      });

      // Si ya hay un valor seleccionado al cargar (old_input tras error de validación),
      // disparar la carga inmediata. El servidor ya pre-pobló las opciones en PHP,
      // pero el JS garantiza que si el servidor no lo hizo, el estado sea consistente.
      if (projectSelect.value && projectSelect.value !== '') {
        // Si el dependent ya tiene opciones > 1 (el PHP ya lo pre-pobló), no refetch.
        // Si solo tiene el placeholder, hacer el fetch.
        var optionCount = dependent.querySelectorAll('option').length;
        if (optionCount <= 1) {
          loadStreets(projectSelect.value, dependent, true);
        }
      }
    });
  }

  // ─── Fetch calles ──────────────────────────────────────────────────────────

  function loadStreets(projectId, dependent, silent) {
    if (!projectId || projectId === '') {
      resetDependent(dependent, emptyText);
      return;
    }

    if (!silent) {
      setDisabled(dependent, true);
      setPlaceholder(dependent, loadingText);
    }

    var url = ajaxUrl
      + '?action=ef_get_streets'
      + '&project_id=' + encodeURIComponent(projectId)
      + '&nonce='      + encodeURIComponent(nonce);

    fetch(url, { method: 'GET', credentials: 'same-origin' })
      .then(function (res) {
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.json();
      })
      .then(function (json) {
        if (!json.success || !Array.isArray(json.data.streets)) {
          resetDependent(dependent, noStreetsText);
          return;
        }
        populateStreets(dependent, json.data.streets);
      })
      .catch(function () {
        resetDependent(dependent, '— Error al cargar calles —');
      })
      .finally(function () {
        setDisabled(dependent, false);
      });
  }

  // ─── DOM helpers ───────────────────────────────────────────────────────────

  function populateStreets(select, streets) {
    var currentValue = select.getAttribute('data-ef-restore-value') || '';

    clearOptions(select);

    var placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = '— Selecciona calle —';
    select.appendChild(placeholder);

    streets.forEach(function (street) {
      var opt = document.createElement('option');
      opt.value = street;
      opt.textContent = street;
      if (street === currentValue) {
        opt.selected = true;
      }
      select.appendChild(opt);
    });

    // Borrar el restore value después de usarlo
    select.removeAttribute('data-ef-restore-value');
  }

  function resetDependent(select, placeholderText) {
    clearOptions(select);
    var placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = placeholderText;
    select.appendChild(placeholder);
  }

  function clearOptions(select) {
    while (select.firstChild) {
      select.removeChild(select.firstChild);
    }
  }

  function setPlaceholder(select, text) {
    clearOptions(select);
    var opt = document.createElement('option');
    opt.value = '';
    opt.textContent = text;
    select.appendChild(opt);
  }

  function setDisabled(select, disabled) {
    select.disabled = disabled;
  }

  // ─── Iniciar en DOMContentLoaded ──────────────────────────────────────────

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();
