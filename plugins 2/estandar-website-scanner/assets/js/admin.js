(function () {
	'use strict';

	function esc(value) {
		return String(value || '')
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	function renderList(items) {
		if (!items || !items.length) {
			return '<li>No data available.</li>';
		}
		return items.map(function (item) { return '<li>' + esc(item) + '</li>'; }).join('');
	}

	function formatScore(value) {
		if (value === null || value === undefined || value === '') {
			return 'N/A';
		}
		var num = Number(value);
		return isNaN(num) ? esc(value) : num.toFixed(2);
	}

	function chartDoughnut(canvasId, score) {
		var canvas = document.getElementById(canvasId);
		if (!canvas || !window.Chart) {
			return;
		}
		new window.Chart(canvas.getContext('2d'), {
			type: 'doughnut',
			data: {
				labels: ['Score', 'Remaining'],
				datasets: [{
					data: [Math.max(0, Math.min(10, score)), Math.max(0, 10 - Math.max(0, Math.min(10, score)))],
					backgroundColor: ['#1d4ed8', '#dbeafe'],
					borderWidth: 0
				}]
			},
			options: { plugins: { legend: { display: false } }, cutout: '70%' }
		});
	}

	function chartRadar(canvasId, labels, values) {
		var canvas = document.getElementById(canvasId);
		if (!canvas || !window.Chart) {
			return;
		}
		new window.Chart(canvas.getContext('2d'), {
			type: 'radar',
			data: { labels: labels, datasets: [{ label: 'Score', data: values, backgroundColor: 'rgba(37,99,235,0.2)', borderColor: '#2563eb' }] },
			options: { scales: { r: { suggestedMin: 0, suggestedMax: 100 } } }
		});
	}

	function chartBars(canvasId, rows) {
		var canvas = document.getElementById(canvasId);
		if (!canvas || !window.Chart || !rows || !rows.length) {
			return;
		}
		new window.Chart(canvas.getContext('2d'), {
			type: 'bar',
			data: {
				labels: rows.map(function (r) { return r.domain || 'n/a'; }),
				datasets: [{ label: 'Performance', data: rows.map(function (r) { return Number(r.performance || 0); }), backgroundColor: '#0ea5e9' }]
			},
			options: { scales: { y: { suggestedMin: 0, suggestedMax: 100 } } }
		});
	}

	function chartProgressLine(canvasId, timeline) {
		var canvas = document.getElementById(canvasId);
		if (!canvas || !window.Chart || !timeline || !Array.isArray(timeline) || !timeline.length) {
			return;
		}

		// Sort by time and build points: x=time in seconds, y=progress percent.
		var points = timeline.slice().sort(function (a, b) {
			return Number(a.t_ms || 0) - Number(b.t_ms || 0);
		});

		var dataset = points.map(function (p) {
			return {
				x: Number(p.t_ms || 0) / 1000,
				y: Number(p.progress_percent || 0),
			};
		});

		var ctx = canvas.getContext('2d');
		if (window.__ewsProgressChart && typeof window.__ewsProgressChart.destroy === 'function') {
			window.__ewsProgressChart.destroy();
		}
		window.__ewsProgressChart = new window.Chart(ctx, {
			type: 'line',
			data: {
				datasets: [{
					label: 'Progress',
					data: dataset,
					borderColor: '#2563eb',
					backgroundColor: 'rgba(37,99,235,0.15)',
					borderWidth: 2,
					tension: 0.25,
					pointRadius: 3,
					fill: true,
				}],
			},
			options: {
				plugins: {
					legend: { display: false },
					tooltip: {
						callbacks: {
							label: function (context) {
								var idx = context.dataIndex;
								var p = points[idx] || {};
								var pct = (p.progress_percent !== undefined && p.progress_percent !== null) ? Number(p.progress_percent).toFixed(1) : '0.0';
								var msg = p.message ? String(p.message) : ('Step ' + (p.step || ''));
								return msg + ' (' + pct + '%)';
							}
						}
					}
				},
				scales: {
					x: {
						title: { display: true, text: 'Time (s)' },
						ticks: {
							callback: function (value) {
								var n = Number(value);
								return isNaN(n) ? value : n.toFixed(1);
							}
						}
					},
					y: {
						title: { display: true, text: 'Progress (%)' },
						suggestedMin: 0,
						suggestedMax: 100,
					}
				}
			}
		});
	}

	function bindDesignMarketingApproval(report) {
		var textarea = document.getElementById('ews-design-marketing-textarea');
		var btn = document.getElementById('ews-approve-design-marketing');
		var statusEl = document.getElementById('ews-design-marketing-status');
		var reportIdEl = document.getElementById('ews-design-marketing-report-id');
		if (!textarea || !btn || !reportIdEl) {
			return;
		}

		// Avoid re-binding on repeated renders.
		if (btn.getAttribute('data-ews-bound') === '1') {
			return;
		}
		btn.setAttribute('data-ews-bound', '1');

		btn.addEventListener('click', function () {
			var reportId = reportIdEl.value || '';
			if (!reportId) {
				return;
			}

			btn.disabled = true;
			btn.textContent = 'Saving...';
			if (statusEl) {
				statusEl.textContent = 'Saving...';
			}

			var body = new FormData();
			body.append('action', 'ews_approve_design_marketing');
			body.append('nonce', window.estandarScanner.nonce);
			body.append('report_id', String(reportId));
			body.append('design_marketing_text', textarea.value || '');

			fetch(window.estandarScanner.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
				.then(function (r) { return r.json(); })
				.then(function (payload) {
					if (!payload || !payload.success) {
						var msg = (payload && payload.data && payload.data.message) ? payload.data.message : 'Save failed.';
						btn.disabled = false;
						btn.textContent = 'Approve & Save';
						if (statusEl) {
							statusEl.textContent = msg;
						}
						return;
					}

					btn.disabled = true;
					btn.textContent = 'Approved';
					if (statusEl) {
						statusEl.textContent = 'approved';
					}
				})
				.catch(function () {
					btn.disabled = false;
					btn.textContent = 'Approve & Save';
					if (statusEl) {
						statusEl.textContent = 'Unexpected error while saving.';
					}
				});
		});
	}

	function renderTree(containerId, treeMap) {
		var root = document.getElementById(containerId);
		if (!root || !window.d3 || !treeMap || !treeMap.url) {
			return;
		}
		root.innerHTML = '';
		var width = root.clientWidth || 420;
		var height = 260;
		var svg = d3.select(root).append('svg').attr('width', width).attr('height', height);
		var g = svg.append('g').attr('transform', 'translate(20,20)');
		var hierarchy = d3.hierarchy(treeMap);
		var layout = d3.tree().size([height - 40, width - 90]);
		layout(hierarchy);
		g.selectAll('.link').data(hierarchy.links()).enter().append('line').attr('x1', function (d) { return d.source.y; }).attr('y1', function (d) { return d.source.x; }).attr('x2', function (d) { return d.target.y; }).attr('y2', function (d) { return d.target.x; }).attr('stroke', '#94a3b8');
		var node = g.selectAll('.node').data(hierarchy.descendants()).enter().append('g').attr('transform', function (d) { return 'translate(' + d.y + ',' + d.x + ')'; });
		node.append('circle').attr('r', 4).attr('fill', '#1d4ed8');
		node.append('text').text(function (d) { return (d.data.url || '').replace(/^https?:\/\//, ''); }).attr('x', 8).attr('y', 4).style('font-size', '10px');
	}

	function renderDashboardVisuals(container) {
		if (!container) {
			return;
		}
		var payload = {};
		try {
			payload = JSON.parse(container.getAttribute('data-ews-dashboard') || '{}');
		} catch (e) {
			payload = {};
		}

		chartDoughnut('ews-dashboard-gauge', Number(payload.score || 0));
		var c = payload.components || {};
		chartRadar('ews-dashboard-radar', ['Performance', 'SEO', 'Accessibility', 'Conversion', 'Structure'], [Number(c.performance || 0), Number(c.seo_technical || 0), Number(c.accessibility || 0), Number(c.conversion_signals || 0), Number(c.crawler_structure || 0)]);
		chartBars('ews-dashboard-competitors', payload.competitors || []);
		renderTree('ews-dashboard-tree', payload.tree_map || {});
	}

	function collectQuickIssues(data) {
		var issues = [];
		var seoChecks = ((data.seo || {}).checks || {});
		Object.keys(seoChecks).forEach(function (key) {
			var check = seoChecks[key] || {};
			if (check.passed === false) {
				issues.push('Missing or failing: ' + (check.label || key));
			}
		});
		var a11yChecks = ((data.accessibility || {}).checks || {});
		Object.keys(a11yChecks).forEach(function (key) {
			var check = a11yChecks[key] || {};
			if (check.available === false) {
				return;
			}
			if (check.passed === false) {
				issues.push('Accessibility: ' + (check.label || key));
			}
		});
		if ((data.broken_links || {}).broken_count > 0) {
			issues.push('Broken links detected: ' + data.broken_links.broken_count);
		}
		((data.diagnostics || {}).pagespeed_errors || []).forEach(function (m) { issues.push(m); });
		return issues.slice(0, 8);
	}

	function renderScanResults(data) {
		var score = data.score || {};
		var c = score.components || {};
		var ai = data.ai_analysis || {};
		var dmDraft = data.design_marketing_analysis_draft || '';
		var dmApproved = data.design_marketing_analysis_approved || '';
		var dmStatus = data.design_marketing_analysis_status || '';
		var dmText = dmApproved ? dmApproved : dmDraft;
		var effectiveStatus = dmApproved ? 'approved' : (dmStatus || (dmDraft ? 'pending' : 'unavailable'));
		var quick = collectQuickIssues(data);
		var downloadBase = data.download_url || '';
		var downloadEn = downloadBase ? (downloadBase + (downloadBase.indexOf('?') > -1 ? '&' : '?') + 'lang=en') : '';
		var downloadEs = downloadBase ? (downloadBase + (downloadBase.indexOf('?') > -1 ? '&' : '?') + 'lang=es') : '';
		return '' +
			'<div class="ews-result-head">' +
				'<h2>Website Health Score: ' + esc(score.health_score || 0) + ' / 10</h2>' +
				'<p>Status: <strong>' + esc(score.status || 'N/A') + '</strong></p>' +
				((score.coverage && score.coverage < 100) ? '<p>Score coverage: ' + esc(score.coverage) + '%</p>' : '') +
				(downloadBase ? '<p><strong>Download Report:</strong> <a class="button button-secondary" target="_blank" href="' + esc(downloadEn) + '">English</a> <a class="button button-secondary" target="_blank" href="' + esc(downloadEs) + '">Español</a></p>' : '') +
			'</div>' +
			'<div class="ews-grid">' +
				'<div class="ews-card"><h3>Performance</h3><p>' + formatScore(c.performance) + '/100</p></div>' +
				'<div class="ews-card"><h3>SEO</h3><p>' + formatScore(c.seo_technical) + '/100</p></div>' +
				'<div class="ews-card"><h3>Accessibility</h3><p>' + formatScore(c.accessibility) + '/100</p></div>' +
				'<div class="ews-card"><h3>Infrastructure</h3><p>' + formatScore(c.infrastructure) + '/100</p></div>' +
				'<div class="ews-card"><h3>Architecture</h3><p>' + formatScore(c.architecture) + '/100</p></div>' +
				'<div class="ews-card"><h3>Crawler</h3><p>' + formatScore(c.crawler_structure) + '/100</p></div>' +
				'<div class="ews-card"><h3>Conversion</h3><p>' + formatScore(c.conversion_signals) + '/100</p></div>' +
			'</div>' +
			'<div class="ews-grid ews-grid-2">' +
				'<div class="ews-panel"><h3>Technical Findings</h3><ul>' + renderList((data.summary || {}).opportunities || []) + '</ul></div>' +
				'<div class="ews-panel"><h3>Quick Issues</h3><ul>' + renderList(quick) + '</ul></div>' +
			'</div>' +
			'<div class="ews-panel"><h3>Progress over time</h3><div style="height:280px; margin-top:8px;"><canvas id="ews-progress-line"></canvas></div></div>' +
			'<div class="ews-panel"><h3>AI Strategic Analysis</h3>' + (ai.available ? ('<pre>' + esc(ai.content || '') + '</pre>') : '<p>' + esc(ai.message || 'AI analysis unavailable.') + '</p>') + '</div>' +
			'<div class="ews-panel"><h3>Design & Marketing (approval)</h3>' +
				'<p id="ews-design-marketing-status" style="margin-top:0;color:#666;">' + esc(effectiveStatus) + '</p>' +
				'<textarea id="ews-design-marketing-textarea" style="width:100%;min-height:170px;" ' + (effectiveStatus === 'unavailable' ? 'disabled' : '') + '>' + esc(dmText) + '</textarea>' +
				'<input type="hidden" id="ews-design-marketing-report-id" value="' + esc(data.report_id || '') + '" />' +
				'<div style="margin-top:10px;">' +
					'<button type="button" id="ews-approve-design-marketing" class="button button-secondary" ' + (effectiveStatus === 'approved' || effectiveStatus === 'unavailable' ? 'disabled' : '') + '>' + esc('Approve & Save') + '</button>' +
				'</div>' +
			'</div>';
	}

	var dashboardContainer = document.querySelector('[data-ews-dashboard]');
	renderDashboardVisuals(dashboardContainer);

	function renderQueueTable(rows) {
		var target = document.getElementById('ews-queue-table');
		if (!target) {
			return;
		}
		if (!rows || !rows.length) {
			target.innerHTML = '<p>No queue items yet.</p>';
			return;
		}
		var html = '<table><thead><tr><th>ID</th><th>Domain</th><th>Status</th><th>Progress</th><th>Created</th></tr></thead><tbody>';
		rows.forEach(function (row) {
			html += '<tr>' +
				'<td>' + esc(row.id) + '</td>' +
				'<td>' + esc(row.domain || row.target_url || '-') + '</td>' +
				'<td>' + esc(row.status || '-') + '</td>' +
				'<td>' + esc(row.progress_message || '-') + '</td>' +
				'<td>' + esc(row.created_at || '-') + '</td>' +
			'</tr>';
		});
		html += '</tbody></table>';
		target.innerHTML = html;
	}

	function fetchQueueList() {
		if (!window.estandarScanner) {
			return;
		}
		var body = new FormData();
		body.append('action', 'ews_get_queue_list');
		body.append('nonce', window.estandarScanner.nonce);
		fetch(window.estandarScanner.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) { return r.json(); })
			.then(function (payload) {
				if (payload.success) {
					renderQueueTable((payload.data || {}).rows || []);
				}
			})
			.catch(function () {});
	}

	function setProgress(step, total, message) {
		var box = document.getElementById('ews-scan-progress');
		var bar = document.getElementById('ews-progress-bar');
		var label = document.getElementById('ews-progress-label');
		var msg = document.getElementById('ews-progress-message');
		if (!box || !bar || !label || !msg) {
			return;
		}
		box.hidden = false;
		var safeTotal = total > 0 ? total : (window.estandarScanner && window.estandarScanner.totalSteps ? Number(window.estandarScanner.totalSteps) : 6);
		var progress = Math.max(0, Math.min(100, (step / safeTotal) * 100));
		bar.style.width = progress + '%';
		label.textContent = 'Step ' + step + '/' + safeTotal;
		msg.textContent = message || '';
	}

	function pollQueue(queueId, resultsContainer) {
		if (!window.estandarScanner || !queueId) {
			return;
		}
		var interval = setInterval(function () {
			var body = new FormData();
			body.append('action', 'ews_get_queue_status');
			body.append('nonce', window.estandarScanner.nonce);
			body.append('queue_id', String(queueId));
			fetch(window.estandarScanner.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
				.then(function (response) { return response.json(); })
				.then(function (payload) {
					if (!payload.success) {
						clearInterval(interval);
						resultsContainer.innerHTML = '<p>' + esc((payload.data && payload.data.message) ? payload.data.message : 'Queue status failed.') + '</p>';
						return;
					}
					var data = payload.data || {};
					setProgress(Number(data.step || 0), Number(data.total || 6), data.message || '');
					fetchQueueList();

					if (data.status === 'failed') {
						clearInterval(interval);
						resultsContainer.innerHTML = '<p>Scan failed: ' + esc(data.error || 'Unknown error') + '</p>';
						return;
					}
					if (data.status === 'cancelled') {
						clearInterval(interval);
						if (stopButton) {
							stopButton.disabled = false;
							stopButton.textContent = 'Stop Scan';
						}
						resultsContainer.innerHTML = '<p>Scan cancelled: ' + esc(data.error || '') + '</p>';
						return;
					}
					if (data.status === 'completed') {
						clearInterval(interval);
						var report = data.report || {};
						resultsContainer.innerHTML = renderScanResults(report);
						chartProgressLine('ews-progress-line', report.progress_timeline || []);
						bindDesignMarketingApproval(report);
					}
				})
				.catch(function () {
					clearInterval(interval);
					resultsContainer.innerHTML = '<p>Unexpected error while polling queue status.</p>';
				});
		}, 3000);
	}

	fetchQueueList();

	function bindMediaSelectors() {
		var buttons = document.querySelectorAll('.ews-media-select');
		if (!buttons.length || !window.wp || !window.wp.media) {
			return;
		}
		buttons.forEach(function (button) {
			button.addEventListener('click', function () {
				var targetInputId = button.getAttribute('data-target-input');
				var targetInput = targetInputId ? document.getElementById(targetInputId) : null;
				if (!targetInput) {
					return;
				}

				var frame = window.wp.media({
					title: 'Select image',
					button: { text: 'Use this image' },
					library: { type: 'image' },
					multiple: false
				});

				frame.on('select', function () {
					var attachment = frame.state().get('selection').first().toJSON();
					if (attachment && attachment.url) {
						targetInput.value = attachment.url;
					}
				});

				frame.open();
			});
		});
	}

	bindMediaSelectors();

	var form = document.getElementById('ews-scan-form');
	if (!form || !window.estandarScanner) {
		return;
	}
	var resultsContainer = document.getElementById('ews-scan-results');

	// Stop current queued scan.
	var stopButton = document.getElementById('ews-stop-scan');
	if (stopButton) {
		stopButton.addEventListener('click', function () {
			var queueId = stopButton.getAttribute('data-queue-id') || '';
			if (!queueId) {
				return;
			}

			stopButton.disabled = true;
			stopButton.textContent = 'Stopping...';

			var body = new FormData();
			body.append('action', 'ews_cancel_scan');
			body.append('nonce', window.estandarScanner.nonce);
			body.append('queue_id', String(queueId));

			resultsContainer.innerHTML = '<p>Cancelling scan...</p>';

			fetch(window.estandarScanner.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
				.then(function (r) { return r.json(); })
				.then(function (payload) {
					if (!payload || !payload.success) {
						stopButton.disabled = false;
						stopButton.textContent = 'Stop Scan';
						resultsContainer.innerHTML = '<p>' + esc((payload && payload.data && payload.data.message) ? payload.data.message : 'Cancel failed.') + '</p>';
					}
				})
				.catch(function () {
					stopButton.disabled = false;
					stopButton.textContent = 'Stop Scan';
					resultsContainer.innerHTML = '<p>Unexpected error while cancelling scan.</p>';
				});
		});
	}

	// Bind "repeat scan" buttons.
	var repeatButtons = document.querySelectorAll('.ews-repeat-scan');
	if (repeatButtons && repeatButtons.length) {
		repeatButtons.forEach(function (button) {
			button.addEventListener('click', function () {
				var urlInput = document.getElementById('ews-url');
				var competitorsInput = document.getElementById('ews-competitors');
				var websiteImageInput = document.getElementById('ews-website-image-url');
				var autoCompetitorsInput = document.getElementById('ews-auto-competitors');

				if (!urlInput) {
					return;
				}

				var url = button.getAttribute('data-url') || '';
				urlInput.value = url;

				// Keep it simple: re-run the scan using only the URL.
				if (competitorsInput) {
					competitorsInput.value = '';
				}
				if (autoCompetitorsInput) {
					autoCompetitorsInput.checked = false;
					autoCompetitorsInput.dispatchEvent(new Event('change'));
				}
				if (websiteImageInput) {
					websiteImageInput.value = '';
				}

				// Trigger the same submit handler used by the main form.
				if (typeof form.requestSubmit === 'function') {
					form.requestSubmit();
				} else {
					form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
				}
			});
		});
	}

	form.addEventListener('submit', function (event) {
		event.preventDefault();
		var formData = new FormData();
		var urlInput = document.getElementById('ews-url');
		var competitorsInput = document.getElementById('ews-competitors');
		var autoCompetitorsInput = document.getElementById('ews-auto-competitors');
		var websiteImageInput = document.getElementById('ews-website-image-url');
		formData.append('action', 'estandar_run_scan');
		formData.set('action', 'ews_enqueue_scan');
		formData.append('nonce', window.estandarScanner.nonce);
		formData.append('url', urlInput ? urlInput.value : '');
		formData.append('competitors', competitorsInput ? competitorsInput.value : '');
		formData.append('auto_competitors', autoCompetitorsInput && autoCompetitorsInput.checked ? '1' : '0');
		formData.append('website_image_url', websiteImageInput ? websiteImageInput.value : '');

		resultsContainer.innerHTML = '<p>Scan added to queue. Waiting for worker...</p>';
		setProgress(0, Number(window.estandarScanner.totalSteps || 6), 'Waiting in queue');

		fetch(window.estandarScanner.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: formData })
			.then(function (response) { return response.json(); })
			.then(function (payload) {
				if (!payload.success) {
					resultsContainer.innerHTML = '<p>' + esc((payload.data && payload.data.message) ? payload.data.message : 'Scan failed.') + '</p>';
					return;
				}
				var queueId = Number((payload.data || {}).queue_id || 0);
				if (!queueId) {
					resultsContainer.innerHTML = '<p>Queue created but no queue id was returned.</p>';
					return;
				}
				// Show stop button now that queue exists.
				var stopButton = document.getElementById('ews-stop-scan');
				if (stopButton) {
					stopButton.style.display = 'inline-block';
					stopButton.disabled = false;
					stopButton.setAttribute('data-queue-id', String(queueId));
				}
				resultsContainer.innerHTML = '<p>Queue #' + esc(queueId) + ' created. Processing...</p>';
				fetchQueueList();
				pollQueue(queueId, resultsContainer);
			})
			.catch(function () {
				resultsContainer.innerHTML = '<p>Unexpected error while creating queue item.</p>';
			});
	});

	// Toggle competitors input when auto-detect is enabled.
	var competitorsField = document.getElementById('ews-competitors');
	var autoDetectField = document.getElementById('ews-auto-competitors');
	if (competitorsField && autoDetectField) {
		autoDetectField.addEventListener('change', function () {
			if (autoDetectField.checked) {
				competitorsField.value = '';
				competitorsField.setAttribute('disabled', 'disabled');
			} else {
				competitorsField.removeAttribute('disabled');
			}
		});
	}
})();
