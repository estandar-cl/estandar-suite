(function () {
	'use strict';

	var cfg = window.eaTrackerConfig || {};
	if (!cfg.endpoint) {
		return;
	}

	var SID_KEY = 'ea_sid';
	var LS_RETURN = 'ea_returning';
	var milestones = [25, 50, 75, 100];
	var firedScroll = {};
	var rageClicks = {};
	var recentWhatsApp = {};
	var pageStart = Date.now();
	var lastActivity = Date.now();
	var queue = [];
	var flushTimer = null;

	function uuid() {
		if (window.crypto && crypto.randomUUID) {
			return crypto.randomUUID();
		}
		return 'ea-' + Math.random().toString(36).slice(2) + Date.now().toString(36);
	}

	function getSessionId() {
		try {
			var m = document.cookie.match(/(?:^|;\s*)ea_sid=([^;]+)/);
			if (m && m[1]) {
				return decodeURIComponent(m[1]);
			}
		} catch (e) {}
		try {
			var ls = localStorage.getItem(SID_KEY);
			if (ls) {
				return ls;
			}
		} catch (e2) {}
		var sid = uuid();
		document.cookie = 'ea_sid=' + encodeURIComponent(sid) + ';path=/;max-age=' + (60 * 60 * 24 * 30) + ';SameSite=Lax';
		try {
			localStorage.setItem(SID_KEY, sid);
		} catch (e3) {}
		return sid;
	}

	function isReturning() {
		try {
			if (localStorage.getItem(LS_RETURN)) {
				return true;
			}
			localStorage.setItem(LS_RETURN, '1');
		} catch (e) {}
		return false;
	}

	function parseUtm() {
		var params = new URLSearchParams(window.location.search);
		return {
			source:          params.get('utm_source')      || '',
			medium:          params.get('utm_medium')      || '',
			campaign:        params.get('utm_campaign')    || '',
			content:         params.get('utm_content')     || '',
			term:            params.get('utm_term')        || '',
			campaign_id:     params.get('campaign_id')     || params.get('utm_campaign_id') || '',
			adset_id:        params.get('adset_id')        || params.get('utm_adset_id')    || '',
			ad_id:           params.get('ad_id')           || params.get('utm_ad_id')       || '',
			// Google Ads — standard + privacy-safe (iOS14 / Privacy Sandbox)
			gclid:           params.get('gclid')           || '',
			gbraid:          params.get('gbraid')          || '', // Google: iOS app attribution
			wbraid:          params.get('wbraid')          || '', // Google: web-to-app attribution
			gad_source:      params.get('gad_source')      || '',
			gad_campaignid:  params.get('gad_campaignid')  || '',
			adgroup_id:      params.get('adgroup_id')      || params.get('adgroupid') || '',
			asset_group_id:  params.get('asset_group_id')  || params.get('assetgroupid') || '',
			creative_id:     params.get('creative_id')     || params.get('creative') || '',
			keyword:         params.get('keyword')         || '',
			match_type:      params.get('match_type')      || params.get('matchtype') || '',
			network:         params.get('network')         || '',
			device:          params.get('device')          || '',
			placement:       params.get('placement')       || '',
			campaign_type:   params.get('campaign_type')   || '',
			// Meta
			fbclid:          params.get('fbclid')          || '',
			// TikTok
			ttclid:          params.get('ttclid')          || '',
			// Microsoft / Bing
			msclkid:         params.get('msclkid')         || ''
		};
	}

	// Lee _fbp y _fbc de cookies (Meta Pixel / Conversions API)
	function parseMetaCookies() {
		var out = { fbp: '', fbc: '' };
		try {
			document.cookie.split(';').forEach(function (c) {
				var p = c.trim();
				if (p.indexOf('_fbp=') === 0) out.fbp = p.slice(5);
				if (p.indexOf('_fbc=') === 0) out.fbc = p.slice(5);
			});
		} catch (e) {}
		return out;
	}

	function detectDevice() {
		var ua = navigator.userAgent || '';
		var device = 'desktop';
		if (/Mobi|Android/i.test(ua)) {
			device = 'mobile';
		} else if (/Tablet|iPad/i.test(ua)) {
			device = 'tablet';
		}
		var browser = 'unknown';
		if (/Edg\//.test(ua)) browser = 'edge';
		else if (/Chrome\//.test(ua)) browser = 'chrome';
		else if (/Firefox\//.test(ua)) browser = 'firefox';
		else if (/Safari\//.test(ua)) browser = 'safari';

		var os = 'unknown';
		if (/Windows/.test(ua)) os = 'windows';
		else if (/Mac OS/.test(ua)) os = 'macos';
		else if (/Android/.test(ua)) os = 'android';
		else if (/iPhone|iPad/.test(ua)) os = 'ios';
		else if (/Linux/.test(ua)) os = 'linux';

		return {
			browser: browser,
			os: os,
			device_type: device,
			viewport: window.innerWidth + 'x' + window.innerHeight,
			language: navigator.language || '',
			timezone: (Intl.DateTimeFormat && Intl.DateTimeFormat().resolvedOptions().timeZone) || ''
		};
	}

	function currentPage() {
		return {
			page_url: window.location.href,
			page_title: document.title || ''
		};
	}

	function enqueue(payload) {
		queue.push(payload);
		if (!flushTimer) {
			flushTimer = setTimeout(flush, 1200);
		}
	}

	function send(body) {
		var json = JSON.stringify(body);
		if (navigator.sendBeacon) {
			try {
				var blob = new Blob([json], { type: 'application/json' });
				if (navigator.sendBeacon(cfg.endpoint, blob)) {
					return Promise.resolve();
				}
			} catch (e) {}
		}
		if (window.fetch) {
			return fetch(cfg.endpoint, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: json,
				keepalive: true,
				credentials: 'same-origin'
			}).catch(function () {});
		}
		return Promise.resolve();
	}

	function flush() {
		flushTimer = null;
		if (!queue.length) {
			return;
		}
		var batch = queue.splice(0, queue.length);
		batch.forEach(function (item) {
			send(item);
		});
	}

	function basePayload() {
		var meta = parseMetaCookies();
		return {
			session_id:  getSessionId(),
			is_returning: isReturning() ? 1 : 0,
			referrer:    document.referrer || '',
			utm:         parseUtm(),
			landing_page: sessionStorage.getItem('ea_landing') || window.location.href,
			device:      detectDevice(),
			// Meta Pixel cookies — requeridos para Conversions API server-side
			meta_fbp:    meta.fbp,
			meta_fbc:    meta.fbc
		};
	}

	function trackEvent(type, extra) {
		extra = extra || {};
		var meta = {};
		Object.keys(extra).forEach(function (key) {
			if (key === 'label' || key === 'value') return;
			var value = extra[key];
			if (value === undefined || value === null || value === '') return;
			meta[key] = value;
		});
		var payload = basePayload();
		var event = {
			type: type,
			label: extra.label || '',
			value: extra.value || '',
			page_url: window.location.href
		};
		if (Object.keys(meta).length) {
			event.meta = meta;
		}
		payload.events = [event];
		enqueue(payload);
	}

	function whatsappEventIdFor(destination) {
		var key = String(destination || 'unknown');
		var now = Date.now();
		var recent = recentWhatsApp[key];
		if (recent && now - recent.time < 1000) return recent.eventId;
		var eventId = uuid();
		recentWhatsApp[key] = { eventId: eventId, time: now };
		return eventId;
	}

	window.estandarAnalyticsTrackEvent = function (type, extra) {
		if (!type) return;
		trackEvent(String(type), extra || {});
	};

	function trackPageview() {
		if (!sessionStorage.getItem('ea_landing')) {
			sessionStorage.setItem('ea_landing', window.location.href);
		}
		var payload = basePayload();
		payload.pageview = Object.assign({ scroll_depth: 0, time_on_page: 0 }, currentPage());
		payload.duration = Math.round((Date.now() - pageStart) / 1000);
		payload.page_count = 1;
		enqueue(payload);
	}

	function onScroll() {
		if (!cfg.scrollTracking) return;
		var doc = document.documentElement;
		var scrollTop = window.pageYOffset || doc.scrollTop || 0;
		var height = Math.max(doc.scrollHeight - window.innerHeight, 1);
		var percent = Math.min(100, Math.round((scrollTop / height) * 100));
		milestones.forEach(function (m) {
			if (percent >= m && !firedScroll[m]) {
				firedScroll[m] = true;
				trackEvent('scroll_depth', { label: m + '%', value: String(m) });
			}
		});
	}

	function matchesSelector(el, selector) {
		try {
			return el.matches(selector);
		} catch (e) {
			return false;
		}
	}

	function closestSelector(el, selector) {
		while (el && el !== document) {
			if (matchesSelector(el, selector)) {
				return el;
			}
			el = el.parentElement;
		}
		return null;
	}

	function onClick(ev) {
		if (!cfg.clickTracking && !cfg.whatsappTracking) return;
		var el = ev.target;
		if (!el) return;

		var href = '';
		if (el.closest) {
			var a = el.closest('a');
			if (a) href = a.getAttribute('href') || '';
		}

		if (cfg.whatsappTracking && /^(?:https?:\/\/(?:wa\.me|api\.whatsapp\.com\/send|web\.whatsapp\.com\/)|whatsapp:\/\/)/i.test(href)) {
			var whatsappEventId = whatsappEventIdFor(href);
			trackEvent('WhatsAppClick', {
				event_id: whatsappEventId,
				destination_url: href,
				timestamp: new Date().toISOString(),
				source: 'detector',
				reliability: 'observed',
				label: href
			});
			return;
		}
		if (cfg.whatsappTracking && href && (cfg.whatsappBridgeSlugs || []).some(function (slug) {
			return href.indexOf(String(cfg.whatsappBridgeBase || '') + slug) === 0 || href.indexOf('/wp-json/estandar-leads/v1/whatsapp-redirect') !== -1;
		})) {
			var bridgeEventId = whatsappEventIdFor(href);
			try {
				var bridgeUrl = new URL(href, window.location.href);
				bridgeUrl.searchParams.set('event_id', bridgeEventId);
				if (!bridgeUrl.searchParams.get('session_id')) bridgeUrl.searchParams.set('session_id', getSessionId());
				a.setAttribute('href', bridgeUrl.toString());
				href = bridgeUrl.toString();
			} catch (e4) {}
			trackEvent('WhatsAppClick', {
				event_id: bridgeEventId,
				destination_url: href,
				timestamp: new Date().toISOString(),
				source: 'detector',
				reliability: 'observed',
				label: href
			});
			return;
		}
		if (!cfg.clickTracking) return;
		if (/^mailto:/i.test(href)) {
			trackEvent('email_click', { label: href });
			return;
		}
		if (/^tel:/i.test(href)) {
			trackEvent('phone_click', { label: href });
			return;
		}

		if (href) {
			try {
				var url = new URL(href, window.location.href);
				if (url.hostname && cfg.siteHost && url.hostname.replace(/^www\./, '') !== cfg.siteHost.replace(/^www\./, '')) {
					trackEvent('outbound_click', { label: href });
				} else if (url.pathname) {
					trackEvent('internal_nav_click', { label: href });
				}
				if (/\.(pdf|zip|doc|docx|xls|xlsx|ppt|pptx)$/i.test(url.pathname)) {
					trackEvent('file_download', { label: href });
				}
			} catch (e) {}
		}

		if (el.closest && el.closest('[data-ea-cta], .ea-cta, .cta')) {
			trackEvent('cta_click', { label: (el.textContent || '').trim().slice(0, 120) });
		}

		(cfg.selectors || []).forEach(function (sel) {
			if (closestSelector(el, sel)) {
				trackEvent('custom_selector', { label: sel, value: sel });
			}
		});

		(cfg.conversions || []).forEach(function (conv) {
			if (conv.type === 'custom_selector' && conv.value && closestSelector(el, conv.value)) {
				trackEvent('custom_selector', { label: conv.key, value: conv.value });
			}
		});

		var key = ev.clientX + ':' + ev.clientY;
		var now = Date.now();
		if (!rageClicks[key]) rageClicks[key] = [];
		rageClicks[key] = rageClicks[key].filter(function (t) { return now - t < 800; });
		rageClicks[key].push(now);
		if (rageClicks[key].length >= 4) {
			trackEvent('rage_click', { label: key });
			rageClicks[key] = [];
		}
	}

	function onForm(ev) {
		if (!cfg.formTracking) return;
		var form = ev.target;
		if (!form || form.tagName !== 'FORM') return;
		if (ev.type === 'submit') {
			trackEvent('form_submit_attempt', { label: form.getAttribute('id') || form.getAttribute('name') || 'form' });
		} else {
			trackEvent('form_interaction', { label: form.getAttribute('id') || 'form' });
		}
	}

	function normalizePath(path) {
		path = (path || '/').split('?')[0].split('#')[0];
		if (path.length > 1 && path.charAt(path.length - 1) === '/') {
			path = path.slice(0, -1);
		}
		return path || '/';
	}

	function matchesThankYouPath(path) {
		var current = normalizePath(path);
		return (cfg.thankYouPaths || []).some(function (p) {
			if (!p) {
				return false;
			}
			var target = normalizePath(p);
			return current === target;
		});
	}

	function onVisibility() {
		if (document.visibilityState !== 'hidden') {
			return;
		}
		var seconds = Math.round((Date.now() - pageStart) / 1000);
		var payload = basePayload();
		payload.duration = seconds;
		payload.events = [{
			type: 'time_on_page',
			label: 'seconds',
			value: String(seconds),
			page_url: window.location.href
		}];
		enqueue(payload);
		flush();
	}

	function checkThankYou() {
		var path = window.location.pathname || '/';
		if (matchesThankYouPath(path)) {
			trackEvent('thank_you_page', { label: normalizePath(path) });
		}
	}

	function init() {
		trackPageview();
		checkThankYou();
		window.addEventListener('scroll', onScroll, { passive: true });
		document.addEventListener('click', onClick, true);
		document.addEventListener('focusin', onForm, true);
		document.addEventListener('submit', onForm, true);
		document.addEventListener('visibilitychange', onVisibility);
		window.addEventListener('pagehide', onVisibility);
		setInterval(function () {
			lastActivity = Date.now();
		}, 15000);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
