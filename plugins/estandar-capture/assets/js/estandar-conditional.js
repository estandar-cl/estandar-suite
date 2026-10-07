/* Estandar Suite — Capture · Conditional fields (progressive disclosure).
 * Procesa `data-es-show-when` (regla con condiciones) y `data-es-gate-fields`
 * (habilita el botón de envío sólo cuando los campos visibles listados están
 * completos). Sin dependencias (no jQuery). Aditivo.
 *
 * Gramática de regla (SHOW_WHEN_EXPR_V1):
 *   regla  := grupo ('||' grupo)*         (OR)
 *   grupo  := token ('&&' token)*         (AND)
 *   token  := campo ('==' | '!=') valor
 *     - valor '*'  → "cualquier valor"  ( `campo!=*` = campo NO vacío,
 *                                         `campo==*` = campo vacío )
 *     - otro valor → comparación de igualdad/desigualdad exacta.
 * Backward compatible con el formato previo: `campo==valor` y `campo!=*`.
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

	function evalToken(form, tok) {
		tok = (tok || '').trim();
		if (!tok) { return true; }
		var op = tok.indexOf('!=') !== -1 ? '!=' : (tok.indexOf('==') !== -1 ? '==' : '');
		if (!op) { return true; }
		var idx   = tok.indexOf(op);
		var field = tok.slice(0, idx).trim();
		var val   = tok.slice(idx + op.length).trim();
		var cur   = sourceValue(form, field);
		if (val === '*') {
			return op === '!=' ? (cur !== '') : (cur === '');
		}
		return op === '==' ? (cur === val) : (cur !== val);
	}

	function evalRule(form, rule) {
		rule = (rule || '').trim();
		if (!rule) { return true; }
		var groups = rule.split('||');
		for (var g = 0; g < groups.length; g++) {
			var toks = groups[g].split('&&');
			var ok = true;
			for (var i = 0; i < toks.length; i++) {
				if (!evalToken(form, toks[i])) { ok = false; break; }
			}
			if (ok) { return true; }
		}
		return false;
	}

	function evaluate(container, form) {
		var rule = container.getAttribute('data-es-show-when') || '';
		var show = evalRule(form, rule);
		container.style.display = show ? '' : 'none';
		// No enviar el valor de campos ocultos (evita leads con datos vacíos).
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

	// SUBMIT_GATE_V1: el botón con `data-es-gate-fields="a,b,c"` se habilita sólo
	// cuando todos esos campos QUE ESTÉN VISIBLES tienen valor (los ocultos por
	// una rama condicional no cuentan).
	function applyGate(form) {
		var gate = form.querySelector('[data-es-gate-fields]');
		if (!gate) { return; }
		var fields = (gate.getAttribute('data-es-gate-fields') || '').split(',')
			.map(function (s) { return s.trim(); }).filter(Boolean);
		var ok = true;
		fields.forEach(function (fn) {
			var el = form.querySelector('[name="' + fn + '"]');
			if (!el) { return; }
			var box = el.closest('[data-es-show-when]');
			if (box && box.style.display === 'none') { return; }
			if ((sourceValue(form, fn) || '') === '') { ok = false; }
		});
		var isBtn = gate.tagName === 'BUTTON' || gate.getAttribute('type') === 'submit';
		if (isBtn) {
			gate.disabled = !ok;
			gate.style.opacity = ok ? '' : '.55';
			gate.style.cursor = ok ? '' : 'not-allowed';
		}
	}

	function applyAll(form) {
		containers().forEach(function (c) {
			if (c.closest('form') === form) { evaluate(c, form); }
		});
		applyGate(form);
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
			if (form.querySelector('[data-es-show-when]') || form.querySelector('[data-es-gate-fields]')) {
				bind(form);
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
