/* Estandar Suite — Capture · Conditional fields (progressive disclosure).
 * Procesa los atributos data-es-show-when="<campo>==<valor>" que emite
 * Fields::render: muestra/oculta el contenedor del campo según el valor del
 * campo origen (mismo patrón que survey). No depende de jQuery. Aditivo.
 */
(function () {
	'use strict';

	function containers() {
		return document.querySelectorAll('[data-es-show-when]');
	}

	function sourceValue(form, field) {
		var el = form.querySelector('[name="' + field + '"]');
		if (!el) { return ''; }
		if (el.type === 'checkbox') { return el.checked ? '1' : ''; }
		if (el.type === 'radio') {
			var checked = form.querySelector('[name="' + field + '"]:checked');
			return checked ? checked.value : '';
		}
		return el.value || '';
	}

	function evaluate(container, form) {
		var rule = container.getAttribute('data-es-show-when') || '';
		var show = true;
		if (rule.indexOf('==') !== -1) {
			var parts = rule.split('==');
			if (parts.length === 2) {
				show = (sourceValue(form, parts[0].trim()) === parts[1].trim());
			}
		} else if (rule.indexOf('!=') !== -1) {
			// SHOW_WHEN_ANY_V1: '<campo>!=*' → mostrar si el campo se completó.
			var neq = rule.split('!=');
			if (neq.length === 2) {
				show = (sourceValue(form, neq[0].trim()) !== '');
			}
		}
		container.style.display = show ? '' : 'none';
		// No enviar el valor de campos ocultos (evita leads con datos vacíos
		// cuando el condicional no se cumple).
		container.querySelectorAll('input, select, textarea').forEach(function (el) {
			if (!show) {
				el.setAttribute('data-es-disabled', '1');
				el.disabled = true;
			} else if (el.getAttribute('data-es-disabled')) {
				el.removeAttribute('data-es-disabled');
				el.disabled = false;
			}
		});
	}

	function applyAll(form) {
		containers().forEach(function (c) {
			if (c.closest('form') === form) { evaluate(c, form); }
		});
	}

	function bind(form) {
		applyAll(form);
		form.addEventListener('input', function () { applyAll(form); });
		form.addEventListener('change', function () { applyAll(form); });
		form.addEventListener('click', function () { applyAll(form); });
	}

	function init() {
		var forms = document.querySelectorAll('form.estandar-form-v2');
		forms.forEach(function (form) {
			if (form.querySelector('[data-es-show-when]')) { bind(form); }
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
