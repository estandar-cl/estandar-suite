(function () {
	'use strict';

	var MAP = {
		tema: { container: 'en-temas', template: 'en-tpl-tema' },
		edicion: { container: 'en-ediciones', template: 'en-tpl-edicion' }
	};

	function addRow(type) {
		var cfg = MAP[type];
		if (!cfg) {
			return;
		}
		var tpl = document.getElementById(cfg.template);
		var box = document.getElementById(cfg.container);
		if (!tpl || !box) {
			return;
		}
		var empty = box.querySelector('.en-empty');
		if (empty) {
			empty.remove();
		}
		var node = tpl.content.cloneNode(true);
		var uid = 'n' + Date.now() + Math.floor(Math.random() * 1000);
		node.querySelectorAll('[name]').forEach(function (el) {
			el.name = el.name.replace(/__new__/g, uid);
		});
		node.querySelectorAll('[data-en-feedname]').forEach(function (el) {
			el.setAttribute('data-en-feedname', el.getAttribute('data-en-feedname').replace(/__new__/g, uid));
		});
		box.appendChild(node);
	}

	function feedNode(name, url) {
		var label = document.createElement('label');
		label.className = 'en-feed';
		var cb = document.createElement('input');
		cb.type = 'checkbox';
		cb.name = name;
		cb.value = url;
		cb.checked = true;
		var span = document.createElement('span');
		span.textContent = url;
		label.appendChild(cb);
		label.appendChild(span);
		return label;
	}

	function discover(btn) {
		var row = btn.closest('.en-row');
		if (!row) {
			return;
		}
		var nombre = (row.querySelector('.en-nombre') || {}).value || '';
		var msg = row.querySelector('.en-discover-msg');
		var box = row.querySelector('[data-en-feeds]');
		if (!nombre.trim()) {
			if (msg) { msg.textContent = ' Pon primero un nombre de tema.'; }
			return;
		}
		if (msg) { msg.textContent = ' Buscando y validando fuentes…'; }
		btn.disabled = true;

		var body = new URLSearchParams();
		body.append('action', 'en_discover_feeds');
		body.append('nonce', window.EN_NOTICIAS ? EN_NOTICIAS.nonce : '');
		body.append('tema', nombre);

		var existing = {};
		row.querySelectorAll('.en-feeds input[type=checkbox]').forEach(function (c) {
			existing[c.value] = true;
		});

		fetch((window.EN_NOTICIAS ? EN_NOTICIAS.ajax : ''), { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (r) { return r.json(); })
			.then(function (d) {
				btn.disabled = false;
				if (!d || !d.success || !d.data || !d.data.feeds) {
					if (msg) { msg.textContent = ' No se pudieron validar fuentes.'; }
					return;
				}
				var fname = box ? (box.getAttribute('data-en-feedname') || EN_NOTICIAS.feedname) : EN_NOTICIAS.feedname;
				var added = 0;
				d.data.feeds.forEach(function (f) {
					if (existing[f.url] || !box) {
						return;
					}
					existing[f.url] = true;
					box.appendChild(feedNode(fname, f.url));
					added++;
				});
				if (msg) {
					msg.textContent = added ? (' +' + added + ' fuentes añadidas (revisa y guarda).') : ' No hay fuentes nuevas válidas.';
				}
			})
			.catch(function () {
				btn.disabled = false;
				if (msg) { msg.textContent = ' Error de red.'; }
			});
	}

	function filterModels() {
		var prov = document.getElementById('en-provider');
		var model = document.getElementById('en-model');
		if (!prov || !model) {
			return;
		}
		var p = prov.value;
		Array.prototype.forEach.call(model.options, function (o) {
			if (!o.value) {
				return;
			}
			var match = o.getAttribute('data-provider') === p;
			o.hidden = !match;
			o.disabled = !match;
			if (!match && o.selected) {
				model.value = '';
			}
		});
	}

	document.addEventListener('click', function (e) {
		var add = e.target.closest('[data-en-add]');
		if (add) {
			e.preventDefault();
			addRow(add.getAttribute('data-en-add'));
			return;
		}
		var disc = e.target.closest('[data-en-discover]');
		if (disc) {
			e.preventDefault();
			discover(disc);
			return;
		}
		var rm = e.target.closest('.en-remove');
		if (rm) {
			e.preventDefault();
			var row = rm.closest('.en-row');
			if (row) {
				row.remove();
			}
		}
	});

	var prov = document.getElementById('en-provider');
	if (prov) {
		prov.addEventListener('change', filterModels);
		filterModels();
	}
})();
