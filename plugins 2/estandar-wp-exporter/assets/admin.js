/* =========================================================
   Estandar WP Exporter — Admin JS
   Responsabilidad ÚNICA:
   - Orquestar UI
   - Disparar AJAX
   - Mostrar progreso
   - Manejar estados de forma segura
========================================================= */

(function ($) {
	'use strict';

	if (typeof EstandarWPE === 'undefined') {
		return;
	}

	/* ======================================================
	   Estado interno
	====================================================== */

	let state = {
		jobId: null,
		token: null,
		running: false,
	};

	/* ======================================================
	   Utilidades UI
	====================================================== */

	function setStatus(text) {
		$('#estandar-wpe-status').text(text);
	}

	function setCounts(data) {
		const parts = [];

		if (typeof data.files_added !== 'undefined') {
			if (typeof data.files_total !== 'undefined' && data.files_total > 0) {
				parts.push('Archivos: ' + data.files_added + ' / ' + data.files_total);
			} else {
				parts.push('Archivos: ' + data.files_added);
			}
		}

		if (typeof data.bytes_added !== 'undefined') {
			const mb = (data.bytes_added / (1024 * 1024)).toFixed(2);
			if (typeof data.bytes_total !== 'undefined' && data.bytes_total > 0) {
				const totalMb = (data.bytes_total / (1024 * 1024)).toFixed(2);
				parts.push('Tamaño ~ ' + mb + ' / ' + totalMb + ' MB');
			} else {
				parts.push('Tamaño ~ ' + mb + ' MB');
			}
		}

		if (typeof data.files_skipped !== 'undefined' && data.files_skipped > 0) {
			parts.push('Saltados: ' + data.files_skipped);
		}

		$('#estandar-wpe-counts').text(parts.join(' · '));
	}

	function setProgress(percent) {
		percent = Math.max(0, Math.min(100, percent));
		$('.wpe-progress-fill').css('width', percent + '%');
	}

	function disableUI(disabled) {
		$('#estandar-wpe-start').prop('disabled', disabled);
		$('#batch_files').prop('disabled', disabled);
		$('#max_file_mb').prop('disabled', disabled);
	}

	function resetUI() {
		state.jobId = null;
		state.token = null;
		state.running = false;

		setProgress(0);
		setStatus('En espera…');
		$('#estandar-wpe-counts').text('');
		$('#estandar-wpe-progress').hide();

		disableUI(false);
	}

	/* ======================================================
	   Selección
	====================================================== */

	function getSelection() {
		const plugins = [];
		const themes = [];

		$('input[name="plugins[]"]:checked').each(function () {
			plugins.push(this.value);
		});

		$('input[name="themes[]"]:checked').each(function () {
			themes.push(this.value);
		});

		return {
			plugins: plugins,
			themes: themes,
		};
	}

	function applyPreset(name) {
		const presets = EstandarWPE.presets || {};
		let plugins = [];
		let themes = [];

		if (name === 'active-plugins') {
			plugins = presets.activePlugins || [];
		} else if (name === 'theme-stack') {
			themes = presets.themeStack || [];
		} else if (name === 'visual-stack') {
			themes = presets.themeStack || [];
			plugins = presets.activePlugins || [];
		} else if (name === 'plugin-only') {
			plugins = presets.plugin || [];
		}

		$('input[name="plugins[]"]').prop('checked', false);
		$('input[name="themes[]"]').prop('checked', false);

		plugins.forEach(function (pluginFile) {
			$('input[name="plugins[]"][value="' + pluginFile.replace(/"/g, '\\"') + '"]').prop('checked', true);
		});

		themes.forEach(function (stylesheet) {
			$('input[name="themes[]"][value="' + stylesheet.replace(/"/g, '\\"') + '"]').prop('checked', true);
		});
	}

	/* ======================================================
	   Inicio de exportación
	====================================================== */

	$('#estandar-wpe-start').on('click', function () {

		if (state.running) {
			return;
		}

		const selection = getSelection();

		if (selection.plugins.length === 0 && selection.themes.length === 0) {
			alert('Selecciona al menos un plugin o un theme.');
			return;
		}

		const batchFiles = parseInt($('#batch_files').val(), 10) || 25;
		const maxFileMb = parseInt($('#max_file_mb').val(), 10) || 25;

		state.running = true;
		disableUI(true);

		$('#estandar-wpe-progress').show();
		setStatus('Inicializando exportación…');
		setProgress(10);

		$.ajax({
			url: EstandarWPE.ajaxurl,
			method: 'POST',
			dataType: 'json',
			data: {
				action: 'estandar_wpe_init',
				nonce: EstandarWPE.nonce,
				plugins: selection.plugins,
				themes: selection.themes,
				batch_files: batchFiles,
				max_file_mb: maxFileMb,
			}
		})
		.done(function (res) {

			if (!res || !res.success || !res.data || !res.data.job_id) {
				throw new Error('Respuesta inválida del servidor.');
			}

			state.jobId = res.data.job_id;
			state.token = res.data.token || '';

			setStatus('Analizando selección…');
			setProgress(5);

			stepJob();
		})
		.fail(function (xhr) {
			resetUI();
			alert(xhr.responseJSON?.data?.error || xhr.responseJSON?.error || 'Error iniciando exportación.');
		});
	});

	$('[data-wpe-preset]').on('click', function () {
		const preset = $(this).data('wpe-preset');

		if (preset === 'clear') {
			$('input[name="plugins[]"]').prop('checked', false);
			$('input[name="themes[]"]').prop('checked', false);
			return;
		}

		applyPreset(preset);
	});

	/* ======================================================
	   Ciclo de pasos
	====================================================== */

	function stepJob() {

		if (!state.running || !state.jobId) {
			return;
		}

		$.ajax({
			url: EstandarWPE.ajaxurl,
			method: 'POST',
			dataType: 'json',
			data: {
				action: 'estandar_wpe_step',
				nonce: EstandarWPE.nonce,
				job_id: state.jobId,
				token: state.token,
			}
		})
		.done(function (res) {

			if (!res || !res.success || !res.data) {
				throw new Error('Respuesta inválida del servidor.');
			}

			const data = res.data;

			if (data.message) {
				setStatus(data.message);
			}

			setCounts(data);

			if (data.done) {

				setProgress(100);
				setStatus('Exportación completada. Abriendo descarga…');

				state.running = false;
				disableUI(false);

				if (data.download_url) {
					const cleanupJobId = state.jobId;
					const cleanupToken = state.token;

					window.open(data.download_url, '_blank', 'noopener');

					setTimeout(function () {
						$.ajax({
							url: EstandarWPE.ajaxurl,
							method: 'POST',
							dataType: 'json',
							data: {
								action: 'estandar_wpe_cleanup',
								nonce: EstandarWPE.nonce,
								job_id: cleanupJobId,
								token: cleanupToken,
							}
						});
					}, 30000);
				}

				state.jobId = null;
				state.token = null;
				return;
			}

			setProgress(typeof data.percent !== 'undefined' ? data.percent : 10);

			setTimeout(stepJob, 250);
		})
		.fail(function (xhr) {
			resetUI();
			alert(xhr.responseJSON?.data?.error || xhr.responseJSON?.error || 'Error durante la exportación.');
		});
	}

})(jQuery);
