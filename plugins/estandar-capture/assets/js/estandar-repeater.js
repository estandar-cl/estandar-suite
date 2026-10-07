/**
 * Estandar Forms — Incident repeater.
 *
 * Maintains compact incidents[] and incident_files[] indexes before submit.
 *
 * @since 4.0.0
 */

(function () {
  'use strict';

  function init() {
    document.querySelectorAll('[data-es2-repeater]').forEach(function (root) {
      wireRepeater(root);
    });
  }

  function wireRepeater(root) {
    var addButton = root.querySelector('[data-es2-repeater-add]');
    var list = root.querySelector('[data-es2-repeater-list]');
    var template = root.querySelector('[data-es2-repeater-template]');

    if (!addButton || !list || !template) return;

    addButton.addEventListener('click', function () {
      addIncident(root);
    });

    root.addEventListener('click', function (event) {
      var removeButton = event.target.closest('[data-es2-repeater-remove]');
      if (!removeButton) return;
      event.preventDefault();
      removeIncident(root, removeButton.closest('[data-es2-incident]'));
    });

    root.closest('form')?.addEventListener('submit', function () {
      reindex(root);
    });

    enforceMinMax(root);
    reindex(root);
  }

  function addIncident(root) {
    var list = root.querySelector('[data-es2-repeater-list]');
    var template = root.querySelector('[data-es2-repeater-template]');
    if (!list || !template) return;

    var max = parseInt(root.getAttribute('data-es2-max') || '0', 10);
    if (max > 0 && list.querySelectorAll('[data-es2-incident]').length >= max) return;

    var wrapper = document.createElement('div');
    wrapper.innerHTML = template.innerHTML.trim();
    var item = wrapper.firstElementChild;
    if (!item) return;

    list.appendChild(item);
    reindex(root);
    enforceMinMax(root);
  }

  function removeIncident(root, item) {
    if (!item) return;
    var list = root.querySelector('[data-es2-repeater-list]');
    var min = parseInt(root.getAttribute('data-es2-min') || '1', 10);
    if (!list || list.querySelectorAll('[data-es2-incident]').length <= min) return;

    item.remove();
    reindex(root);
    enforceMinMax(root);
  }

  function reindex(root) {
    var items = root.querySelectorAll('[data-es2-incident]');
    items.forEach(function (item, index) {
      item.setAttribute('data-index', String(index));
	      item.querySelectorAll('[data-es2-field]').forEach(function (field) {
	        var key = field.getAttribute('data-es2-field');
	        field.name = 'incidents[' + index + '][' + key + ']';
	        if (field.id) {
	          field.id = 'ef-incident-' + index + '-' + key;
	          var label = item.querySelector('label[for$="-' + key + '"]');
	          if (label) label.setAttribute('for', field.id);
	        }
	      });
	      item.querySelectorAll('[data-es2-file]').forEach(function (field) {
	        field.name = 'incident_files[' + index + '][]';
	        if (field.id) {
	          field.id = 'ef-incident-' + index + '-files';
	          var label = item.querySelector('label[for$="-files"]');
	          if (label) label.setAttribute('for', field.id);
	        }
	      });
      item.querySelectorAll('[data-es2-index-label]').forEach(function (label) {
        label.textContent = String(index + 1);
      });
    });
  }

  function enforceMinMax(root) {
    var list = root.querySelector('[data-es2-repeater-list]');
    var addButton = root.querySelector('[data-es2-repeater-add]');
    if (!list) return;

    var count = list.querySelectorAll('[data-es2-incident]').length;
    var min = parseInt(root.getAttribute('data-es2-min') || '1', 10);
    var max = parseInt(root.getAttribute('data-es2-max') || '0', 10);

    root.querySelectorAll('[data-es2-repeater-remove]').forEach(function (button) {
      button.disabled = count <= min;
    });

    if (addButton && max > 0) {
      addButton.disabled = count >= max;
    }
  }

  window.EstandarFormsRepeater = {
    addIncident: addIncident,
    removeIncident: removeIncident,
    reindex: reindex,
    enforceMinMax: enforceMinMax
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
