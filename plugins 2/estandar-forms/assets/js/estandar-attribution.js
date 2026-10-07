/**
 * Estandar Attribution Layer
 *
 * Captura parámetros de atribución (UTMs, click IDs, metadatos de sesión)
 * desde la URL y los persiste en localStorage con modelo first-touch / last-touch.
 *
 * En cada formulario Estandar Forms (.estandar-form), inyecta los valores
 * capturados como inputs hidden antes del submit.
 *
 * @package Estandar_Forms
 * @since   4.1.0
 */
(function () {
    'use strict';

    var CLICK_PARAMS = ['gclid', 'gbraid', 'wbraid', 'fbclid', 'ttclid', 'msclkid'];
    var UTM_PARAMS   = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];
	var VALUE_TRACK_PARAMS = ['campaign_id', 'adgroup_id', 'asset_group_id', 'creative_id', 'keyword', 'match_type', 'network', 'device', 'placement', 'campaign_type'];
	var VALUE_TRACK_ALIASES = {
		campaign_id: ['gad_campaignid', 'campaignid'],
		adgroup_id: ['adgroupid'],
		asset_group_id: ['assetgroupid'],
		creative_id: ['creative'],
		match_type: ['matchtype']
	};
    var ALL_PARAMS   = CLICK_PARAMS.concat(UTM_PARAMS, VALUE_TRACK_PARAMS);
	    var ALL_FIELDS   = ALL_PARAMS.concat([
	        'meta_fbc',
	        'meta_fbp',
	        'landing_url',
        'referrer',
        'first_touch_timestamp',
        'last_touch_timestamp',
        'session_id',
    ]);

    var PREFIX       = 'estandar_attribution_';
    var SESSION_KEY  = PREFIX + 'session_id';

    /* ── Helpers ── */

    function readJson(key) {
        try { return JSON.parse(localStorage.getItem(key) || 'null'); } catch (e) { return null; }
    }

    function writeJson(key, value) {
        try { localStorage.setItem(key, JSON.stringify(value)); } catch (e) {}
    }

	    function getOrCreateSessionId() {
        // Prefer the Analytics Engine's session ID (ea_sid) — it is the SSOT.
        var eaSid = getCookie('ea_sid');
        if (!eaSid) {
            try { eaSid = localStorage.getItem('ea_sid') || ''; } catch (e) {}
        }
        if (eaSid) {
            return eaSid;
        }
        // Fallback only when Analytics tracker is absent.
        var sid = sessionStorage.getItem(SESSION_KEY);
        if (!sid) {
            sid = 'sid_' + Date.now() + '_' + Math.random().toString(36).slice(2, 9);
            sessionStorage.setItem(SESSION_KEY, sid);
        }
	        return sid;
	    }

	    function getCookie(name) {
	        var parts = String(document.cookie || '').split(';');
	        for (var i = 0; i < parts.length; i++) {
	            var pair = parts[i].split('=');
	            if (decodeURIComponent(String(pair.shift() || '').trim()) === name) {
	                return decodeURIComponent(pair.join('=') || '');
	            }
	        }
	        return '';
	    }

    /* ── Captura ── */

    function capture() {
        var search  = new URLSearchParams(window.location.search);
        var now     = new Date().toISOString();
        var current = {
            landing_url: window.location.href.split('#')[0],
            referrer:    document.referrer || '',
            timestamp:   now,
        };

	        ALL_PARAMS.forEach(function (key) {
	            var val = search.get(key);
			if (!val && VALUE_TRACK_ALIASES[key]) {
				VALUE_TRACK_ALIASES[key].some(function (alias) {
					val = search.get(alias);
					return !!val;
				});
			}
	            if (val) { current[key] = val; }
	        });
	        current.meta_fbc = getCookie('_fbc') || search.get('meta_fbc') || search.get('_fbc') || '';
	        current.meta_fbp = getCookie('_fbp') || search.get('meta_fbp') || search.get('_fbp') || '';

        var first = readJson(PREFIX + 'first_touch');
        if (!first) {
            first = current;
            writeJson(PREFIX + 'first_touch', first);
        }

        var last = Object.assign({}, readJson(PREFIX + 'last_touch') || {}, current, { timestamp: now });
        writeJson(PREFIX + 'last_touch', last);

        // Per-project last_touch (2026-08-05).
        // Prevents cross-project attribution contamination when a user
        // navigates between projects on the same domain with a shared ea_sid.
        var projectSlug = getProjectSlug();
        if (projectSlug) {
            var lastKey = PREFIX + 'last_touch_' + projectSlug;
            var lastPerProject = Object.assign({}, readJson(lastKey) || {}, current, { timestamp: now });
            writeJson(lastKey, lastPerProject);
        }

        window.estandarAttribution = {
            first_touch: first,
            last_touch:  last,
            session_id:  getOrCreateSessionId(),
        };
    }

    /* ── Resolución de valor por campo ── */

    function getProjectSlug() {
        // 1. From URL path (e.g., /altacruz/ or /parronales/)
        var path = window.location.pathname;
        var match = path.match(/^\/([a-z0-9_-]+)\//);
        if (match && match[1]) return match[1];
        // 2. From form data attribute
        var form = document.querySelector('.estandar-form[data-project-slug]');
        if (form) return form.getAttribute('data-project-slug');
        // 3. From global WordPress variable
        if (typeof estandarProjectSlug !== 'undefined') return estandarProjectSlug;
        return '';
    }

    function valueFor(name) {
        var d     = window.estandarAttribution || {};
        var first = d.first_touch || readJson(PREFIX + 'first_touch') || {};
        var last  = d.last_touch  || readJson(PREFIX + 'last_touch')  || {};

        // Prefer per-project last_touch when available (2026-08-05 cross-project guard)
        var projectSlug = getProjectSlug();
        if (projectSlug) {
            var perProject = readJson(PREFIX + 'last_touch_' + projectSlug);
            if (perProject) last = Object.assign({}, last, perProject);
        }

        switch (name) {
            case 'session_id':            return getOrCreateSessionId();
            case 'landing_url':           return first.landing_url || last.landing_url || '';
            case 'referrer':              return first.referrer    || last.referrer    || '';
            case 'first_touch_timestamp': return first.timestamp   || '';
            case 'last_touch_timestamp':  return last.timestamp    || '';
            default:                      return last[name] || first[name] || '';
        }
    }

    /* ── Inyección en formulario ── */

    function fillForm(form) {
        ALL_FIELDS.forEach(function (name) {
            var input = form.querySelector('input[name="' + name + '"]');
            if (!input) {
                input = document.createElement('input');
                input.type  = 'hidden';
                input.name  = name;
                form.appendChild(input);
            }
            input.value = valueFor(name);
        });
    }

    function fillAllForms() {
        document.querySelectorAll('form.estandar-form').forEach(fillForm);
    }

    /* ── Init ── */

    capture();
    document.addEventListener('DOMContentLoaded', fillAllForms);
    window.addEventListener('load', fillAllForms);

    // Rellenar justo antes del submit para capturar last_touch actualizado
    document.addEventListener('submit', function (event) {
        if (event.target && event.target.matches('form.estandar-form')) {
            fillForm(event.target);
        }
    }, true);

})();
