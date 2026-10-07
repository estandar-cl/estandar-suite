/**
 * Estandar Suite — Site Map tracker v2 (pageview + sesión viva).
 *
 * Envía UNA señal por vista vía sendBeacon (o fetch keepalive) al endpoint
 * público de la suite con identidad de visitante/sesión:
 *  - visitor (v): UUID persistente en localStorage → distingue "nuevo" vs
 *    "recurrente" entre visitas.
 *  - session (s): UUID de la sesión actual; rota tras 30 min de inactividad
 *    (SESSION_IDLE) → el servidor crea/actualiza una sesión viva por beacon.
 * No bloquea el render: async tras window.load (o DOMContentLoaded), sin deps.
 */
(function () {
	var cfg = window.ES_SITE_MAP;
	if (!cfg || !cfg.endpoint || typeof navigator === 'undefined') {
		return;
	}

	var LS_PREFIX = 'es_trk_';
	var KEY_VID = LS_PREFIX + 'vid';
	var KEY_SID = LS_PREFIX + 'sid';
	var KEY_LAST = LS_PREFIX + 'last';
	var IDLE_MS = 1800000; // 30 min

	function readLS() {
		try {
			if (!window.localStorage) { return null; }
			return window.localStorage;
		} catch (e) { return null; }
	}

	function uuid() {
		var u = '', i, r;
		for (i = 0; i < 36; i++) {
			if (i === 8 || i === 13 || i === 18 || i === 23) { u += '-'; continue; }
			r = (Math.random() * 16) | 0;
			if (i === 14) { r = 4; }
			if (i === 19) { r = (r & 3) | 8; }
			u += r.toString(16);
		}
		return u;
	}

	var ls = readLS();
	var vid = '';
	var sid = '';
	if (ls) {
		try {
			vid = ls.getItem(KEY_VID) || '';
			sid = ls.getItem(KEY_SID) || '';
			var last = parseInt(ls.getItem(KEY_LAST) || '0', 10) || 0;
			var now = Date.now();
			if (!vid) { vid = uuid(); ls.setItem(KEY_VID, vid); }
			if (!sid || (now - last) > IDLE_MS) { sid = uuid(); }
			ls.setItem(KEY_SID, sid);
			ls.setItem(KEY_LAST, String(now));
		} catch (e) { /* si localStorage falla, no enviamos identidad */ }
	}

	function send() {
		var body = JSON.stringify({
			url: window.location.href,
			title: (document.title || '').slice(0, 500),
			post_id: Number(cfg.post_id) || 0,
			v: vid || '',
			s: sid || '',
			ref: (typeof document.referrer === 'string' ? document.referrer : '').slice(0, 2048)
		});
		if (ls) {
			try { ls.setItem(KEY_LAST, String(Date.now())); } catch (e) { /* no-op */ }
		}
		try {
			if (navigator.sendBeacon) {
				var blob = new Blob([body], { type: 'application/json' });
				navigator.sendBeacon(cfg.endpoint, blob);
				return;
			}
		} catch (e) { /* fallback abajo */ }
		try {
			fetch(cfg.endpoint, { method: 'POST', body: body, keepalive: true, credentials: 'same-origin' });
		} catch (e) { /* no-op */ }
	}

	if (document.readyState === 'complete' || document.readyState === 'interactive') {
		setTimeout(send, 800);
	} else {
		window.addEventListener('load', function () { setTimeout(send, 800); }, { once: true });
	}
})();
