/* Estandar Suite — Capture · Progressive form steps (PROGRESSIVE_FORM_V1).
 * Ejecuta `actions_json.flow.mode=progressive` de un form v2: muestra el paso
 * inicial, valida cada paso y revela el siguiente al hacer clic en "Continuar".
 * Sin dependencias. Coexiste con estandar-conditional.js (mostrar/ocultar campos
 * dentro de un paso). Aditivo: si no hay pasos, no hace nada.
 *
 * STEP_GATE_V1: el botón "Continuar" (y el submit final) se HABILITAN sólo
 * cuando todos los campos VISIBLES del paso están completos. Los campos ocultos
 * por una condición (skip) no cuentan, de modo que una rama corta habilita
 * "Continuar" en cuanto su(s) pregunta(s) visible(s) está(n) respondida(s).
 */
(function () {
	'use strict';

	function currentStep(form) {
		var steps = form.querySelectorAll('[data-es-step]');
		for (var i = 0; i < steps.length; i++) {
			if (steps[i].style.display !== 'none') {
				return steps[i];
			}
		}
		return steps[0] || null;
	}

	function stepIndex(form, step) {
		var steps = form.querySelectorAll('[data-es-step]');
		for (var i = 0; i < steps.length; i++) {
			if (steps[i] === step) { return i; }
		}
		return 0;
	}

	function updateProgress(form, index) {
		var bar = form.parentElement ? form.parentElement.querySelector('[data-es-progress]') : null;
		if (!bar) { return; }
		bar.querySelectorAll('[data-es-progress-item]').forEach(function (el) {
			var i = parseInt(el.getAttribute('data-es-progress-item'), 10);
			el.style.background = (i <= index) ? '#1a73e8' : '#f1f3f4';
			el.style.color = (i <= index) ? '#fff' : '#5f6368';
		});
	}

	// Campos "de captura" del paso (excluye hidden, honeypot y los que un
	// condicional dejó ocultos).
	function visibleFields(scope, form) {
		var out = [];
		scope.querySelectorAll('input, select, textarea').forEach(function (el) {
			if (el.disabled) { return; }
			if (el.type === 'hidden' || el.type === 'submit' || el.type === 'button') { return; }
			var n = el.name || '';
			if (n === '' || n.indexOf('estandar_form_v2') === 0 || n === '_estandar_token' || n === '_origin') { return; }
			var box = el.closest('[data-es-show-when]');
			if (box && box.style.display === 'none') { return; }
			out.push(el);
		});
		return out;
	}

	function isFilled(el, form) {
		if (el.type === 'radio') {
			var group = form.querySelectorAll('[name="' + el.name + '"]');
			for (var i = 0; i < group.length; i++) { if (group[i].checked) { return true; } }
			return false;
		}
		if (el.type === 'checkbox') { return el.checked; }
		return (el.value || '').trim() !== '';
	}

	function stepComplete(step, form) {
		var fields = visibleFields(step, form);
		for (var i = 0; i < fields.length; i++) {
			if (!isFilled(fields[i], form)) { return false; }
		}
		return true;
	}

	function refreshButtons(form) {
		form.querySelectorAll('[data-es-step]').forEach(function (step) {
			var ok = stepComplete(step, form);
			var next = step.querySelector('[data-es-step-next]');
			if (next) { next.disabled = !ok; next.style.opacity = ok ? '' : '.55'; next.style.cursor = ok ? '' : 'not-allowed'; }
			var sub = step.querySelector('button[type="submit"]');
			if (sub) { sub.disabled = !ok; sub.style.opacity = ok ? '' : '.55'; sub.style.cursor = ok ? '' : 'not-allowed'; }
		});
	}

	function validateStep(step) {
		var ok = true;
		step.querySelectorAll('input, select, textarea').forEach(function (el) {
			if (el.disabled) { return; }
			if (el.checkValidity && !el.checkValidity()) {
				ok = false;
				if (el.reportValidity) { el.reportValidity(); }
			}
		});
		return ok;
	}

	function bind(form) {
		var steps = form.querySelectorAll('[data-es-step]');
		if (!steps.length) { return; }

		var initial = form.querySelector('[data-es-initial="1"]') || steps[0];
		steps.forEach(function (s) {
			s.style.display = (s === initial) ? '' : 'none';
		});
		updateProgress(form, stepIndex(form, initial));
		refreshButtons(form);

		// Re-evaluar el gate tras los cambios (y tras estandar-conditional, en el
		// mismo tick) para leer la visibilidad ya aplicada.
		var refresh = function () { refreshButtons(form); setTimeout(function () { refreshButtons(form); }, 0); };
		form.addEventListener('input', refresh);
		form.addEventListener('change', refresh);

		form.addEventListener('click', function (e) {
			var btn = e.target.closest ? e.target.closest('[data-es-step-next]') : null;
			if (!btn) { return; }
			e.preventDefault();
			var cur = btn.closest('[data-es-step]');
			if (!cur || btn.disabled || !stepComplete(cur, form) || !validateStep(cur)) { return; }
			var nextId = btn.getAttribute('data-es-step-next');
			var next = form.querySelector('[data-es-step="' + nextId + '"]');
			if (!next) { return; }
			cur.style.display = 'none';
			next.style.display = '';
			updateProgress(form, stepIndex(form, next));
			refreshButtons(form);
			var top = form.getBoundingClientRect().top + window.pageYOffset - 24;
			try { window.scrollTo({ top: top, behavior: 'smooth' }); } catch (err) { window.scrollTo(0, top); }
		});
	}

	function init() {
		var forms = document.querySelectorAll('form.estandar-form-v2');
		forms.forEach(function (form) {
			if (form.querySelector('[data-es-step]')) { bind(form); }
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
