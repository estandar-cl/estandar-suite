/**
 * ESTANDAR Delivery Locator — frontend (Leaflet, Photon/Nominatim, AJAX).
 * Soporta varias instancias [estandar_delivery_locator] en la misma página.
 */
(function () {
	'use strict';

	if (typeof window.estandarDl === 'undefined' || typeof L === 'undefined') {
		return;
	}

	var cfg = window.estandarDl;
	var i18n = cfg.i18n || {};
	var mapCfg = cfg.map || {};
	var radiusKm = parseFloat(String(mapCfg.radiusKm), 10) || 25;

	// ── Analytics helper ───────────────────────────────────────────────────────
	function dlTrackEvent(eventType) {
		var endpoint = window.eaTrackerConfig && window.eaTrackerConfig.endpoint;
		if (!endpoint) return;
		var sid = '';
		try { var m = document.cookie.match(/(?:^|;\s*)ea_sid=([^;]+)/); if (m && m[1]) sid = decodeURIComponent(m[1]); } catch (e) {}
		if (!sid) { try { sid = (window.localStorage && window.localStorage.getItem('ea_sid')) || ''; } catch (e) {} }
		if (!sid) return;
		try {
			var body = JSON.stringify({ session_id: sid, events: [{ type: eventType, page_url: String(window.location.href || '') }] });
			if (navigator.sendBeacon) { navigator.sendBeacon(endpoint, new Blob([body], { type: 'application/json' })); }
			else { fetch(endpoint, { method: 'POST', body: body, headers: { 'Content-Type': 'application/json' }, credentials: 'omit' }).catch(function () {}); }
		} catch (e) {}
	}
	// ──────────────────────────────────────────────────────────────────────────
	var centerLat = parseFloat(String(mapCfg.centerLat), 10);
	var centerLng = parseFloat(String(mapCfg.centerLng), 10);

	function distanceKm(lat1, lon1, lat2, lon2) {
		var R = 6371;
		var dLat = ((lat2 - lat1) * Math.PI) / 180;
		var dLon = ((lon2 - lon1) * Math.PI) / 180;
		var a =
			Math.sin(dLat / 2) * Math.sin(dLat / 2) +
			Math.cos((lat1 * Math.PI) / 180) *
				Math.cos((lat2 * Math.PI) / 180) *
				Math.sin(dLon / 2) *
				Math.sin(dLon / 2);
		var c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
		return R * c;
	}

	function photonUrl(q) {
		var base = 'https://photon.komoot.io/api/?';
		var p = new URLSearchParams();
		p.set('q', q);
		p.set('lat', String(centerLat));
		p.set('lon', String(centerLng));
		p.set('limit', '8');
		return base + p.toString();
	}

	function nominatimUrl(q) {
		var base = 'https://nominatim.openstreetmap.org/search?';
		var p = new URLSearchParams();
		p.set('q', q + ', Chile');
		p.set('format', 'json');
		p.set('limit', '6');
		p.set('countrycodes', 'cl');
		return base + p.toString();
	}

	function parsePhoton(json) {
		var out = [];
		if (!json || !json.features) return out;
		json.features.forEach(function (f) {
			var c = f.geometry && f.geometry.coordinates;
			if (!c || c.length < 2) return;
			var props = f.properties || {};
			var label = props.name || props.street || '';
			if (props.city) label = label ? label + ', ' + props.city : props.city;
			if (props.state) label = label ? label + ', ' + props.state : props.state;
			if (!label) label = props.country || '—';
			out.push({
				label: label,
				lat: c[1],
				lng: c[0],
			});
		});
		return out;
	}

	function parseNominatim(arr) {
		var out = [];
		if (!arr || !arr.length) return out;
		arr.forEach(function (x) {
			out.push({
				label: x.display_name || '',
				lat: parseFloat(x.lat, 10),
				lng: parseFloat(x.lon, 10),
			});
		});
		return out;
	}

	function fetchJson(url) {
		return fetch(url, {
			method: 'GET',
			headers: { Accept: 'application/json' },
			credentials: 'omit',
		}).then(function (r) {
			if (!r.ok) throw new Error('http');
			return r.json();
		});
	}

	function searchAddress(q) {
		return fetchJson(photonUrl(q))
			.then(function (j) {
				var list = parsePhoton(j);
				if (list.length) return list;
				return fetchJson(nominatimUrl(q)).then(parseNominatim);
			})
			.catch(function () {
				return fetchJson(nominatimUrl(q)).then(parseNominatim);
			});
	}

	function parseAjaxJson(r) {
		return r.text().then(function (text) {
			try {
				return JSON.parse(text);
			} catch (e) {
				return { success: false, data: { message: 'Error' } };
			}
		});
	}

	function buildWaLink(num, text) {
		var n = String(num || '').replace(/\D/g, '');
		if (!n) return '#';
		return 'https://wa.me/' + encodeURIComponent(n) + '?text=' + encodeURIComponent(text);
	}

	function buildMessage(name, phone, address, gmaps) {
		var t = (cfg.waBody || '').trim();
		if (!t) {
			return [
				'Hola, solicito coordinar despacho.',
				'',
				'Nombre: ' + name,
				'Teléfono: ' + phone,
				'Dirección: ' + address,
				'Ubicación (mapa): ' + gmaps,
			].join('\n');
		}
		return t
			.replace(/\{name\}/g, name)
			.replace(/\{phone\}/g, phone)
			.replace(/\{address\}/g, address)
			.replace(/\{map\}/g, gmaps);
	}

	/**
	 * @param {HTMLElement} root Contenedor .estandar-dl
	 */
	function initInstance(root) {
		var prefix = root.id;
		if (!prefix) {
			return;
		}

		function gid(suf) {
			return document.getElementById(prefix + '-' + suf);
		}

		var el = {
			root: root,
			name: gid('name'),
			phone: gid('phone'),
			address: gid('address'),
			suggest: gid('suggest'),
			btnLocate: gid('locate'),
			mapWrap: gid('map-wrap'),
			map: gid('map'),
			zoneMsg: gid('zone-msg'),
			btnCurrent: gid('current'),
			btnConfirm: gid('confirm'),
			contact: gid('contact'),
			finalZone: gid('final-zone'),
			btnWa: gid('wa'),
			btnEmail: gid('send-email'),
			emailStatus: gid('email-status'),
			err: gid('error'),
		};

		if (!el.map) {
			return;
		}

		var map = null;
		var marker = null;
		var zoneCircle = null;
		var mapReady = false;
		var orderId = null;
		var emailToken = null;
		var suggestTimer = null;

		function isInsideZone(lat, lng) {
			return distanceKm(centerLat, centerLng, lat, lng) <= radiusKm;
		}

		function showError(msg) {
			if (!el.err) return;
			el.err.textContent = msg || '';
			el.err.hidden = !msg;
		}

		function clearError() {
			showError('');
		}

		function updateZoneMessage(lat, lng) {
			if (!el.zoneMsg) return;
			var inside = isInsideZone(lat, lng);
			el.zoneMsg.textContent = inside ? i18n.inZone : i18n.outZone;
			el.zoneMsg.className = 'estandar-dl__zone' + (inside ? ' estandar-dl__zone--ok' : ' estandar-dl__zone--warn');
		}

		function ensureMap(lat, lng) {
			if (mapReady && map) {
				map.setView([lat, lng], 15);
				marker.setLatLng([lat, lng]);
				updateZoneMessage(lat, lng);
				return;
			}

			map = L.map(el.map, {
				zoomControl: true,
				scrollWheelZoom: true,
			}).setView([lat, lng], 15);

			L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
				maxZoom: 19,
				attribution: '&copy; OpenStreetMap',
			}).addTo(map);

			zoneCircle = L.circle([centerLat, centerLng], {
				radius: radiusKm * 1000,
				color: '#2563eb',
				weight: 2,
				fillColor: '#3b82f6',
				fillOpacity: 0.08,
			}).addTo(map);

			marker = L.marker([lat, lng], { draggable: true }).addTo(map);

			marker.on('dragend', function () {
				var ll = marker.getLatLng();
				updateZoneMessage(ll.lat, ll.lng);
			});

			map.on('click', function (e) {
				marker.setLatLng(e.latlng);
				updateZoneMessage(e.latlng.lat, e.latlng.lng);
			});

			mapReady = true;
			updateZoneMessage(lat, lng);
			setTimeout(function () {
				map.invalidateSize();
			}, 200);
		}

		function renderSuggest(items) {
			if (!el.suggest) return;
			el.suggest.innerHTML = '';
			if (!items.length) {
				el.suggest.hidden = true;
				return;
			}
			items.forEach(function (item) {
				var b = document.createElement('button');
				b.type = 'button';
				b.className = 'estandar-dl__suggest-item';
				b.textContent = item.label;
				b.addEventListener('click', function () {
					el.address.value = item.label;
					el.suggest.hidden = true;
					ensureMap(item.lat, item.lng);
					el.mapWrap.classList.remove('estandar-dl__step--hidden');
					clearError();
					setTimeout(function () {
						if (map) map.invalidateSize();
					}, 100);
				});
				el.suggest.appendChild(b);
			});
			el.suggest.hidden = false;
		}

		function onAddressInput() {
			clearError();
			if (suggestTimer) clearTimeout(suggestTimer);
			var q = (el.address.value || '').trim();
			if (q.length < 3) {
				if (el.suggest) el.suggest.hidden = true;
				return;
			}
			suggestTimer = setTimeout(function () {
				searchAddress(q).then(function (items) {
					renderSuggest(items.slice(0, 6));
				});
			}, 350);
		}

		function locateFromAddress() {
			clearError();
			var q = (el.address.value || '').trim();
			if (!q) {
				showError(i18n.needAddress || '');
				return;
			}
			if (!el.name.value.trim() || !el.phone.value.trim()) {
				showError(i18n.needFields || '');
				return;
			}
			el.btnLocate.disabled = true;
			el.btnLocate.textContent = i18n.locating || '…';

			searchAddress(q)
				.then(function (items) {
					if (!items.length) {
						showError(i18n.geocodeError || '');
						return;
					}
					var first = items[0];
					el.address.value = first.label || q;
					ensureMap(first.lat, first.lng);
					el.mapWrap.classList.remove('estandar-dl__step--hidden');
					setTimeout(function () {
						if (map) map.invalidateSize();
					}, 150);
				})
				.catch(function () {
					showError(i18n.geocodeError || '');
				})
				.finally(function () {
					el.btnLocate.disabled = false;
					var orig = el.btnLocate.getAttribute('data-label');
					if (orig) el.btnLocate.textContent = orig;
				});
		}

		if (el.btnLocate) {
			el.btnLocate.setAttribute('data-label', el.btnLocate.textContent);
		}

		if (el.address) {
			el.address.addEventListener('input', onAddressInput);
		}

		if (el.btnLocate) {
			el.btnLocate.addEventListener('click', locateFromAddress);
		}

		if (el.btnCurrent) {
			el.btnCurrent.addEventListener('click', function () {
				clearError();
				if (!navigator.geolocation) {
					showError(i18n.geoError || '');
					return;
				}
				el.btnCurrent.disabled = true;
				navigator.geolocation.getCurrentPosition(
					function (pos) {
						var la = pos.coords.latitude;
						var ln = pos.coords.longitude;
						if (!mapReady) {
							ensureMap(la, ln);
							el.mapWrap.classList.remove('estandar-dl__step--hidden');
						} else {
							marker.setLatLng([la, ln]);
							map.panTo([la, ln]);
							updateZoneMessage(la, ln);
						}
						el.btnCurrent.disabled = false;
						setTimeout(function () {
							if (map) map.invalidateSize();
						}, 100);
					},
					function () {
						showError(i18n.geoDenied || i18n.geoError || '');
						el.btnCurrent.disabled = false;
					},
					{ enableHighAccuracy: true, timeout: 12000, maximumAge: 0 }
				);
			});
		}

		if (el.btnConfirm) {
			el.btnConfirm.addEventListener('click', function () {
				clearError();
				var name = el.name.value.trim();
				var phone = el.phone.value.trim();
				var address = el.address.value.trim();
				if (!name || !phone || !address) {
					showError(i18n.needFields || '');
					return;
				}
				if (!marker || !mapReady) {
					showError(i18n.needAddress || '');
					return;
				}
				var ll = marker.getLatLng();

				var fd = new FormData();
				fd.append('action', cfg.actions.confirm);
				fd.append('nonce', cfg.nonce);
				fd.append('name', name);
				fd.append('phone', phone);
				fd.append('address', address);
				fd.append('lat', String(ll.lat));
				fd.append('lng', String(ll.lng));
				// Pasar ea_sid para atribución en el ledger de Leads
				var dlSid = '';
				try { var m2 = document.cookie.match(/(?:^|;\s*)ea_sid=([^;]+)/); if (m2 && m2[1]) dlSid = decodeURIComponent(m2[1]); } catch (e) {}
				if (!dlSid) { try { dlSid = (window.localStorage && window.localStorage.getItem('ea_sid')) || ''; } catch (e) {} }
				if (dlSid) { fd.append('ea_sid', dlSid); }
				fd.append('source_page_url', String(window.location.href || ''));

				el.btnConfirm.disabled = true;
				var prev = el.btnConfirm.textContent;
				el.btnConfirm.textContent = i18n.saving || '…';

				fetch(cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
					.then(parseAjaxJson)
					.then(function (json) {
						if (!json || !json.success) {
							var m = (json && json.data && json.data.message) || 'Error';
							throw new Error(m);
						}
						var d = json.data;
						orderId = d.order_id;
						emailToken = d.email_token || null;
						var gmaps = d.gmaps_link;
						var wa = d.whatsapp;
						var inZone = !!d.in_zone;

						el.finalZone.textContent = inZone ? i18n.inZone : i18n.outZone;
						el.finalZone.className = 'estandar-dl__zone' + (inZone ? ' estandar-dl__zone--ok' : ' estandar-dl__zone--warn');

						var msg = buildMessage(name, phone, address, gmaps);
						el.btnWa.href = buildWaLink(wa, msg);
						if (!wa) {
							el.btnWa.classList.add('estandar-dl__btn--disabled');
							el.btnWa.setAttribute('aria-disabled', 'true');
						}

						el.contact.classList.remove('estandar-dl__step--hidden');
						el.contact.scrollIntoView({ behavior: 'smooth', block: 'start' });
						dlTrackEvent('delivery_locator_confirm');
					})
					.catch(function (e) {
						showError(e.message || 'Error');
					})
					.finally(function () {
						el.btnConfirm.disabled = false;
						el.btnConfirm.textContent = prev;
					});
			});
		}

		if (el.btnEmail) {
			el.btnEmail.addEventListener('click', function () {
				clearError();
				if (!orderId || !emailToken) {
					showError(i18n.emailInvalid || 'Error');
					return;
				}
				var fd = new FormData();
				fd.append('action', cfg.actions.email);
				fd.append('nonce', cfg.nonce);
				fd.append('order_id', String(orderId));
				fd.append('email_token', emailToken);

				el.btnEmail.disabled = true;
				fetch(cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
					.then(parseAjaxJson)
					.then(function (json) {
						if (!json || !json.success) {
							var m = (json && json.data && json.data.message) || 'Error';
							throw new Error(m);
						}
						if (el.emailStatus) {
							el.emailStatus.textContent = (json.data && json.data.message) || i18n.emailSent || '';
						}
						el.btnEmail.disabled = true;
					})
					.catch(function (e) {
						showError(e.message || 'Error');
						el.btnEmail.disabled = false;
					});
			});
		}
	}

	// delivery_locator_whatsapp — delegado en document para capturar el click
	// en el botón WhatsApp que se construye dinámicamente tras confirmar ubicación.
	document.addEventListener('click', function (e) {
		var btn = e.target && e.target.closest ? e.target.closest('.estandar-dl__btn--wa') : null;
		if (btn) dlTrackEvent('delivery_locator_whatsapp');
	});

	function boot() {
		var nodes = document.querySelectorAll('.estandar-dl[data-estandar-dl]:not([data-estandar-dl-init])');
		var count = 0;
		for (var i = 0; i < nodes.length; i++) {
			initInstance(nodes[i]);
			nodes[i].setAttribute('data-estandar-dl-init', '1');
			count++;
		}
		if (count > 0) {
			try {
				document.dispatchEvent(
					new CustomEvent('estandar-dl:ready', {
						detail: { initialized: count },
					})
				);
			} catch (e) {}
		}
		return count;
	}

	window.estandarDeliveryLocator = {
		/**
		 * Inicializa instancias aún no montadas (p. ej. HTML insertado por AJAX).
		 * @returns {number} Cantidad de bloques inicializados en esta llamada.
		 */
		init: boot,
		get version() {
			return (cfg && cfg.pluginVersion) ? cfg.pluginVersion : '';
		},
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
