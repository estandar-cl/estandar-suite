/**
 * Estandar Suite — Site Map lightweight pageview tracker.
 *
 * Envía UNA señal por vista vía sendBeacon (o fetch keepalive) al endpoint
 * público de la suite. No bloquea el render: se dispara tras window.load
 * (o DOMContentLoaded como fallback), async y sin dependencias.
 * El backend limita a 1 señal por IP por minuto.
 */
(function () {
	var cfg = window.ES_SITE_MAP;
	if (!cfg || !cfg.endpoint || typeof navigator === 'undefined') {
		return;
	}

	function send() {
		var body = JSON.stringify({
			url: window.location.href,
			title: (document.title || '').slice(0, 500),
			post_id: Number(cfg.post_id) || 0
		});
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
