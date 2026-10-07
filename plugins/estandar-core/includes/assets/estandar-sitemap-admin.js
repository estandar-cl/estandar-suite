/**
 * Estandar Suite — Site Map admin renderer (diagrama de flujo del sitio).
 * Carga en el admin (Core → Estado → Mapa del sitio). No pasa por SG combine.
 * Config en window.ES_SITEMAP_ADMIN (rest, nonce, wp_base, cardW, cardH).
 */
(function () {
	var CFG = window.ES_SITEMAP_ADMIN || {};
	var CARD_W = CFG.cardW || 260, CARD_H = CFG.cardH || 348;
	var graph = null, layout = null, mediaFrame = null;

	function el(id) { return document.getElementById(id); }
	function api(path, opts) {
		opts = opts || {};
		opts.method = opts.method || 'GET';
		opts.headers = Object.assign({ 'X-WP-Nonce': CFG.nonce }, opts.headers || {});
		if (opts.body && typeof opts.body !== 'string') { opts.body = JSON.stringify(opts.body); opts.headers['Content-Type'] = 'application/json'; }
		return fetch(CFG.rest + path, opts).then(function (r) { return r.json(); });
	}

	function badge(node) {
		var b = '';
		if (node.is_front) b += '<span class="es-sm-badge es-b-home">Portada</span>';
		if (node.in_menu) b += '<span class="es-sm-badge es-b-menu">menú</span>';
		if (node.is_landing) b += '<span class="es-sm-badge es-b-landing">landing</span>';
		return b;
	}

	function cardHTML(node) {
		var shot = node.shot || '';
		var img = shot ? '<img loading="lazy" src="' + shot + '" alt="">' : '<div class="es-sm-no-shot">sin captura</div>';
		var meta = '<span>' + (node.author ? node.author : '—') + '</span>';
		if (node.date) meta += '<span>' + node.date.slice(0,10) + '</span>';
		if (node.last_viewed) meta += '<span>última: ' + node.last_viewed.slice(0,10) + '</span>';
		var actions = '<a class="button" href="' + node.url + '" target="_blank" rel="noopener">Ver</a>'
			+ (node.id ? '<a class="button" href="' + CFG.wp_base + 'post.php?post=' + node.id + '&action=edit">Editar</a>' : '')
			+ (node.status === 'publish' && node.id ? '<button type="button" class="button es-sm-trash-card" data-id="' + node.id + '" title="Desactivar: enviar a la papelera">🗑</button>' : '');
		if (node.id) actions += '<button type="button" class="button es-sm-shot-btn" data-id="' + node.id + '" title="' + (node.has_manual_shot ? 'Cambiar captura' : 'Subir captura') + '">🖼</button>';
		var ga4 = (typeof node.ga4_views_total !== 'undefined')
			? '<span class="es-sm-stat es-sm-stat-ga4" title="Visitas según GA4 (referencia extra)"><strong>' + node.ga4_views_total + '</strong>GA4</span>'
			: '';
		return '<div class="es-sm-card es-c-' + node['class'] + '" data-key="' + node.key + '">'
			+ '<div class="es-sm-shot">' + img + '</div>'
			+ '<div class="es-sm-body">'
			+ '<div class="es-sm-title" title="' + node.title + '">' + node.title + badge(node) + '</div>'
			+ '<div class="es-sm-url" title="' + node.url + '">' + node.url + '</div>'
			+ '<div class="es-sm-meta">' + meta + '</div>'
			+ '<div class="es-sm-stats">'
			+ '<span class="es-sm-stat" title="Visitas en los últimos días"><strong>' + node.views_30 + '</strong>visitas ' + graph.meta.days + 'd</span>'
			+ '<span class="es-sm-stat" title="Visitas totales (nuestra analytics)"><strong>' + node.views_total + '</strong>total</span>'
			+ ga4
			+ '</div></div>'
			+ '<div class="es-sm-actions">' + actions + '</div>'
			+ '</div>';
	}

	function draw() {
		var canvas = el('es-sm-canvas');
		canvas.innerHTML = '';
		var w = layout.width, h = layout.height;
		canvas.style.width = w + 'px';
		canvas.style.height = h + 'px';

		var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
		svg.setAttribute('width', w); svg.setAttribute('height', h);
		svg.style.position = 'absolute'; svg.style.left = '0'; svg.style.top = '0';
		svg.style.pointerEvents = 'none';
		canvas.appendChild(svg);

		layout.edges.forEach(function (e) {
			var a = layout.pos[e.from], b = layout.pos[e.to];
			if (!a || !b) return;
			var x1, y1, x2, y2;
			if (a.x < b.x) {
				x1 = a.x + CARD_W; y1 = a.y + CARD_H / 2;
				x2 = b.x;          y2 = b.y + CARD_H / 2;
			} else if (a.x > b.x) {
				x1 = a.x;          y1 = a.y + CARD_H / 2;
				x2 = b.x + CARD_W; y2 = b.y + CARD_H / 2;
			} else {
				x1 = a.x + CARD_W; y1 = a.y + CARD_H / 2;
				x2 = b.x;          y2 = b.y + CARD_H / 2;
			}
			var mx = (x1 + x2) / 2;
			var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
			path.setAttribute('d', 'M ' + x1 + ' ' + y1 + ' C ' + mx + ' ' + y1 + ', ' + mx + ' ' + y2 + ', ' + x2 + ' ' + y2);
			path.setAttribute('stroke', '#b4b9be');
			path.setAttribute('stroke-width', '1.4');
			path.setAttribute('fill', 'none');
			path.setAttribute('opacity', '0.75');
			svg.appendChild(path);
		});

		var byKey = {};
		graph.nodes.forEach(function (n) { byKey[n.key] = n; });

		layout.placed.forEach(function (k) {
			var n = byKey[k]; if (!n) return;
			var d = document.createElement('div');
			d.innerHTML = cardHTML(n);
			d.firstChild.style.left = layout.pos[k].x + 'px';
			d.firstChild.style.top = layout.pos[k].y + 'px';
			canvas.appendChild(d.firstChild);
		});

		if (layout.unplaced.length) {
			var sep = document.createElement('div');
			sep.style.position = 'absolute';
			sep.style.top = (layout.height + 10) + 'px';
			sep.style.left = '0';
			sep.style.fontWeight = '600';
			sep.style.fontSize = '12px';
			sep.style.color = '#50575e';
			sep.textContent = 'Independientes / sin enlace entrante (' + layout.unplaced.length + ')';
			canvas.appendChild(sep);
			layout.unplaced.forEach(function (k, i) {
				var n = byKey[k]; if (!n) return;
				var col = i % 4, row = Math.floor(i / 4);
				var d = document.createElement('div');
				d.innerHTML = cardHTML(n);
				d.firstChild.style.left = (18 + col * (CARD_W + 16)) + 'px';
				d.firstChild.style.top = (layout.height + 30 + row * (CARD_H + 16)) + 'px';
				canvas.appendChild(d.firstChild);
			});
			canvas.style.height = (layout.height + 60 + Math.ceil(layout.unplaced.length / 4) * (CARD_H + 16)) + 'px';
		}

		bindCardActions();
	}

	function bindCardActions() {
		document.querySelectorAll('.es-sm-trash-card').forEach(function (b) {
			b.addEventListener('click', function () {
				var id = b.getAttribute('data-id');
				if (!confirm('¿Enviar esta página a la papelera (desactivarla de publicadas)?')) return;
				api('/sitemap/trash', { method: 'POST', body: { post_id: Number(id) } }).then(function (r) {
					if (r && r.ok) { load(); }
					else { alert((r && r.message) || 'No se pudo enviar a la papelera.'); }
				});
			});
		});
		document.querySelectorAll('.es-sm-shot-btn').forEach(function (b) {
			b.addEventListener('click', function () {
				var id = b.getAttribute('data-id');
				if (typeof wp === 'undefined' || !wp.media) { alert('El selector de medios no está disponible en esta página.'); return; }
				if (mediaFrame) { mediaFrame.open(); return; }
				mediaFrame = wp.media({ title: 'Captura de pantalla de la página', library: { type: 'image' }, button: { text: 'Usar esta imagen' }, multiple: false });
				mediaFrame.on('select', function () {
					var att = mediaFrame.state().get('selection').first().toJSON();
					api('/sitemap/shot', { method: 'POST', body: { post_id: Number(id), attachment_id: Number(att.id) } }).then(function (r) {
						if (r && r.ok) { load(); }
					});
				});
				mediaFrame.open();
			});
		});
		// Si el servicio de captura falla (p. ej. firewall del sitio bloquea el
		// crawler de mshots → 403), mostrar "sin captura" en vez de imagen rota.
		document.querySelectorAll('#es-sm-canvas .es-sm-shot img').forEach(function (img) {
			if (img.dataset.esFallbackBound) { return; }
			img.dataset.esFallbackBound = '1';
			img.addEventListener('error', function () {
				var d = document.createElement('div');
				d.className = 'es-sm-no-shot';
				d.textContent = 'sin captura';
				img.replaceWith(d);
			});
		});
	}

	function load() {
		var days = el('es-sm-days').value;
		api('/sitemap?days=' + days).then(function (d) {
			if (!d || !d.graph) return;
			graph = d.graph; layout = d.layout;
			draw();
		});
	}

	function bind() {
		el('es-sm-refresh').addEventListener('click', load);
		el('es-sm-days').addEventListener('change', load);
		el('es-sm-settings-toggle').addEventListener('click', function () {
			var s = el('es-sm-settings'); s.style.display = s.style.display === 'none' ? 'block' : 'none';
		});
		el('es-sm-save-settings').addEventListener('click', function () {
			var pts = ['page'];
			document.querySelectorAll('#es-sm-settings input[name="es-sm-pt"]:checked').forEach(function (c) {
				if (c.value && pts.indexOf(c.value) === -1) { pts.push(c.value); }
			});
			api('/sitemap/settings', { method: 'POST', body: {
				days: el('es-sm-days').value,
				track_enabled: el('es-sm-track').checked ? 1 : 0,
				exclude_self: el('es-sm-exclude-self').checked ? 1 : 0,
				exclude_ips: el('es-sm-exclude-ips').value,
				screenshots_enabled: el('es-sm-shots').checked ? 1 : 0,
				screenshot_service: 'mshots',
				post_types: pts,
				ga4_enabled: el('es-sm-ga4-enabled').checked ? 1 : 0,
				ga4_connection_id: Number(el('es-sm-ga4-conn').value) || 0,
				ga4_property_id: el('es-sm-ga4-property').value
			} }).then(function (r) {
				if (r && r.ok) { el('es-sm-settings').style.display = 'none'; load(); }
			});
		});
		var ga4Connect = el('es-sm-ga4-connect');
		if (ga4Connect) {
			ga4Connect.addEventListener('click', function () {
				var scope = 'https://www.googleapis.com/auth/analytics.readonly';
				api('/oauth/begin?provider=google&scopes=' + encodeURIComponent(scope) + '&label=Google Analytics', { method: 'GET' }).then(function (r) {
					if (r && r.url) { window.location.href = r.url; }
					else { alert('No se pudo iniciar OAuth: ' + ((r && r.message) || 'desconocido')); }
				});
			});
		}
		var ga4Test = el('es-sm-ga4-test');
		if (ga4Test) {
			ga4Test.addEventListener('click', function () {
				ga4Test.disabled = true;
				api('/sitemap/settings', { method: 'POST', body: {
					days: el('es-sm-days').value,
					ga4_enabled: el('es-sm-ga4-enabled').checked ? 1 : 0,
					ga4_connection_id: Number(el('es-sm-ga4-conn').value) || 0,
					ga4_property_id: el('es-sm-ga4-property').value
				} }).then(function () {
					return api('/sitemap/ga4/test', { method: 'POST', body: {} });
				}).then(function (r) {
					ga4Test.disabled = false;
					var out = el('es-sm-ga4-test-result');
					if (r && r.ok) { out.textContent = 'OK · ' + r.paths + ' páginas · ' + r.total + ' visitas totales en GA4.'; }
					else { out.textContent = 'Fallo: ' + ((r && r.error) || 'desconocido'); out.style.color = '#b32d2e'; }
					load();
				}).catch(function () { ga4Test.disabled = false; });
			});
		}
		document.querySelectorAll('.es-sm-trash').forEach(function (b) {
			b.addEventListener('click', function () {
				var id = b.getAttribute('data-id');
				if (!confirm('¿Enviar a la papelera?')) return;
				api('/sitemap/trash', { method: 'POST', body: { post_id: Number(id) } }).then(function (r) { if (r && r.ok) { location.reload(); } });
			});
		});
		document.querySelectorAll('.es-sm-restore').forEach(function (b) {
			b.addEventListener('click', function () {
				var id = b.getAttribute('data-id');
				api('/sitemap/restore', { method: 'POST', body: { post_id: Number(id) } }).then(function (r) { if (r && r.ok) { location.reload(); } });
			});
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		var g = el('es-sm-graph'), l = el('es-sm-layout');
		if (g && l) { graph = JSON.parse(g.textContent); layout = JSON.parse(l.textContent); }
		bind();
		draw();
	});
})();
