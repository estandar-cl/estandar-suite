(function () {
	'use strict';

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
		var layout = d3.tree().size([height - 40, width - 100]);
		layout(hierarchy);
		g.selectAll('.link').data(hierarchy.links()).enter().append('line').attr('x1', function (d) { return d.source.y; }).attr('y1', function (d) { return d.source.x; }).attr('x2', function (d) { return d.target.y; }).attr('y2', function (d) { return d.target.x; }).attr('stroke', '#94a3b8');
		var node = g.selectAll('.node').data(hierarchy.descendants()).enter().append('g').attr('transform', function (d) { return 'translate(' + d.y + ',' + d.x + ')'; });
		node.append('circle').attr('r', 4).attr('fill', '#1d4ed8');
		node.append('text').text(function (d) { return (d.data.url || '').replace(/^https?:\/\//, ''); }).attr('x', 8).attr('y', 4).style('font-size', '10px');
	}

	function doughnut(id, score) {
		var canvas = document.getElementById(id);
		if (!canvas || !window.Chart) {
			return;
		}
		new window.Chart(canvas.getContext('2d'), {
			type: 'doughnut',
			data: { datasets: [{ data: [score, Math.max(0, 10 - score)], backgroundColor: ['#1d4ed8', '#dbeafe'], borderWidth: 0 }] },
			options: { plugins: { legend: { display: false } }, cutout: '70%' }
		});
	}

	function radar(id, c) {
		var canvas = document.getElementById(id);
		if (!canvas || !window.Chart) {
			return;
		}
		new window.Chart(canvas.getContext('2d'), {
			type: 'radar',
			data: {
				labels: ['Performance', 'SEO', 'Accessibility', 'Conversion', 'Structure'],
				datasets: [{ data: [Number(c.performance || 0), Number(c.seo_technical || 0), Number(c.accessibility || 0), Number(c.conversion_signals || 0), Number(c.crawler_structure || 0)], backgroundColor: 'rgba(37,99,235,0.2)', borderColor: '#2563eb' }]
			},
			options: { scales: { r: { suggestedMin: 0, suggestedMax: 100 } } }
		});
	}

	function bars(id, rows) {
		var canvas = document.getElementById(id);
		if (!canvas || !window.Chart || !rows || !rows.length) {
			return;
		}
		new window.Chart(canvas.getContext('2d'), {
			type: 'bar',
			data: { labels: rows.map(function (r) { return r.domain || 'n/a'; }), datasets: [{ label: 'Performance', data: rows.map(function (r) { return Number(r.performance || 0); }), backgroundColor: '#0ea5e9' }] },
			options: { scales: { y: { suggestedMin: 0, suggestedMax: 100 } } }
		});
	}

	var container = document.querySelector('.ews-public-report[data-ews-public]');
	if (!container) {
		return;
	}

	var payload = {};
	try {
		payload = JSON.parse(container.getAttribute('data-ews-public') || '{}');
	} catch (e) {
		payload = {};
	}

	doughnut('ews-public-gauge', Number(payload.score || 0));
	radar('ews-public-radar', payload.components || {});
	bars('ews-public-competitors', payload.competitors || []);
	renderTree('ews-public-tree', payload.tree_map || {});
})();
