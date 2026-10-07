(function () {
	'use strict';

	var data = window.eaAdminCharts || {};

	function $(sel, ctx) {
		return (ctx || document).querySelector(sel);
	}

	function initFilters() {
		var toggle = $('#ea-toggle-filters');
		var panel = $('#ea-filter-panel');
		if (!toggle || !panel) return;
		toggle.addEventListener('click', function () {
			var open = panel.hasAttribute('hidden');
			if (open) {
				panel.removeAttribute('hidden');
				toggle.setAttribute('aria-expanded', 'true');
			} else {
				panel.setAttribute('hidden', 'hidden');
				toggle.setAttribute('aria-expanded', 'false');
			}
		});
	}

	function initTooltips() {
		var tip = document.createElement('div');
		tip.className = 'ea-tooltip';
		tip.setAttribute('role', 'tooltip');
		tip.hidden = true;
		document.body.appendChild(tip);

		document.querySelectorAll('[data-ea-tip]').forEach(function (btn) {
			btn.addEventListener('mouseenter', function () {
				tip.textContent = btn.getAttribute('data-ea-tip') || '';
				tip.hidden = false;
				var r = btn.getBoundingClientRect();
				tip.style.left = Math.min(r.left, window.innerWidth - 280) + 'px';
				tip.style.top = (r.bottom + 8 + window.scrollY) + 'px';
			});
			btn.addEventListener('mouseleave', function () {
				tip.hidden = true;
			});
			btn.addEventListener('focus', function () {
				btn.dispatchEvent(new Event('mouseenter'));
			});
			btn.addEventListener('blur', function () {
				btn.dispatchEvent(new Event('mouseleave'));
			});
		});
	}

	function drawLine(canvas, rows, valueKey) {
		if (!canvas || !rows || !rows.length) return;
		var ctx = canvas.getContext('2d');
		var w = canvas.width = canvas.clientWidth || 600;
		var h = canvas.height = 220;
		var pad = 34;
		var values = rows.map(function (r) { return Number(r[valueKey] || 0); });
		var max = Math.max.apply(null, values.concat([1]));
		ctx.clearRect(0, 0, w, h);
		ctx.strokeStyle = '#e8eaed';
		ctx.fillStyle = '#64748b';
		ctx.font = '11px system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
		for (var g = 0; g <= 4; g++) {
			var gy = pad + ((h - pad * 2) / 4) * g;
			var tick = Math.round(max - (max / 4) * g);
			ctx.beginPath();
			ctx.moveTo(pad, gy);
			ctx.lineTo(w - pad, gy);
			ctx.stroke();
			ctx.fillText(String(tick), 4, gy + 4);
		}
		ctx.beginPath();
		ctx.strokeStyle = '#1a5cff';
		ctx.lineWidth = 2;
		values.forEach(function (v, i) {
			var x = pad + (i / Math.max(values.length - 1, 1)) * (w - pad * 2);
			var y = h - pad - (v / max) * (h - pad * 2);
			if (i === 0) ctx.moveTo(x, y);
			else ctx.lineTo(x, y);
		});
		ctx.stroke();
		ctx.fillStyle = '#1a5cff';
		values.forEach(function (v, i) {
			var x = pad + (i / Math.max(values.length - 1, 1)) * (w - pad * 2);
			var y = h - pad - (v / max) * (h - pad * 2);
			ctx.beginPath();
			ctx.arc(x, y, 3, 0, Math.PI * 2);
			ctx.fill();
		});
		if (rows.length) {
			ctx.fillStyle = '#64748b';
			ctx.fillText(String(rows[0].day || ''), pad, h - 8);
			ctx.textAlign = 'right';
			ctx.fillText(String(rows[rows.length - 1].day || ''), w - pad, h - 8);
			ctx.textAlign = 'left';
		}
	}

	function drawDonut(canvas, rows) {
		if (!canvas || !rows || !rows.length) return;
		var ctx = canvas.getContext('2d');
		var w = canvas.width = canvas.clientWidth || 320;
		var h = canvas.height = 220;
		var cx = Math.min(w * 0.36, 180);
		var cy = h / 2;
		var radius = Math.min(cx, h / 2) - 24;
		var total = rows.reduce(function (s, r) { return s + Number(r.total || 0); }, 0) || 1;
		var colors = ['#1a5cff', '#0d9488', '#f59e0b', '#ef4444', '#8b5cf6', '#64748b', '#06b6d4', '#84cc16'];
		var angle = -Math.PI / 2;
		ctx.clearRect(0, 0, w, h);
		rows.forEach(function (row, i) {
			var slice = (Number(row.total || 0) / total) * Math.PI * 2;
			ctx.beginPath();
			ctx.moveTo(cx, cy);
			ctx.fillStyle = colors[i % colors.length];
			ctx.arc(cx, cy, radius, angle, angle + slice);
			ctx.closePath();
			ctx.fill();
			angle += slice;
		});
		ctx.beginPath();
		ctx.fillStyle = '#fff';
		ctx.arc(cx, cy, radius * 0.55, 0, Math.PI * 2);
		ctx.fill();
		ctx.fillStyle = '#0f172a';
		ctx.font = '600 13px system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
		ctx.textAlign = 'center';
		ctx.fillText(String(total), cx, cy + 4);
		ctx.textAlign = 'left';
		ctx.font = '11px system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
		rows.slice(0, 5).forEach(function (row, i) {
			var y = 42 + i * 24;
			var x = Math.max(cx + radius + 28, w * 0.55);
			var pct = Math.round((Number(row.total || 0) / total) * 100);
			ctx.fillStyle = colors[i % colors.length];
			ctx.fillRect(x, y - 9, 9, 9);
			ctx.fillStyle = '#334155';
			ctx.fillText(String(row.label || 'Sin etiqueta') + ' · ' + pct + '%', x + 14, y);
		});
	}

	function drawCharts() {
		drawLine($('#ea-chart-visitors'), data.visitorsTrend || [], 'visitors');
		drawDonut($('#ea-chart-sources'), data.sources || []);
		drawDonut($('#ea-chart-devices'), data.devices || []);
		drawLine($('#ea-chart-conversions'), data.conversions || [], 'total');
	}

	function initCharts() {
		drawCharts();
		window.addEventListener('resize', function () {
			drawCharts();
		});
	}

	function initCopyButtons() {
		document.querySelectorAll('[data-ea-copy]').forEach(function (button) {
			button.addEventListener('click', function () {
				var input = document.querySelector(button.getAttribute('data-ea-copy') || '');
				if (!input || !navigator.clipboard) return;
				navigator.clipboard.writeText(input.value || '');
			});
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		initFilters();
		initTooltips();
		initCharts();
		initCopyButtons();
	});
})();
