/* Estandar Suite — Capture · Progressive form steps (PROGRESSIVE_FORM_V1).
 * Ejecuta `actions_json.flow.mode=progressive` de un form v2: muestra el paso
 * inicial, valida cada paso y revela el siguiente al hacer clic en "Continuar".
 * Sin dependencias. Coexiste con estandar-conditional.js (mostrar/ocultar campos
 * dentro de un paso). Aditivo: si no hay pasos, no hace nada.
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

		form.addEventListener('click', function (e) {
			var btn = e.target.closest ? e.target.closest('[data-es-step-next]') : null;
			if (!btn) { return; }
			e.preventDefault();
			var cur = btn.closest('[data-es-step]');
			if (!cur || !validateStep(cur)) { return; }
			var nextId = btn.getAttribute('data-es-step-next');
			var next = form.querySelector('[data-es-step="' + nextId + '"]');
			if (!next) { return; }
			cur.style.display = 'none';
			next.style.display = '';
			updateProgress(form, stepIndex(form, next));
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
