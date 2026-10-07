(function () {
  'use strict';

  var cfg     = window.epConfig || {};
  var popups  = cfg.popups  || [];
  var ajaxurl = cfg.ajaxurl || '';
  var nonce   = cfg.nonce   || '';
  // SUBMITTED_TTL_V1: duración (días) de la restricción "ya envió". 0 = sin
  // restricción. Sin valor, se asume el default histórico (365).
  var ttlDays = parseInt(cfg.submittedTtlDays, 10);
  if (isNaN(ttlDays)) ttlDays = 365;

  if (!popups.length) return;

  // ── Focusable selector ────────────────────────────────────────────────────

  var FOCUSABLE = 'a[href], button:not([disabled]), textarea, input, select, [tabindex]:not([tabindex="-1"])';

  // ── Body scroll lock (iOS-safe) ───────────────────────────────────────────
  // overflow:hidden alone does not prevent scroll on iOS Safari.
  // position:fixed + saved scrollY is the correct technique.

  var epScrollY = 0;

  function applyBodyScrollLock() {
    var body = document.body;
    body.classList.add('ep-modal-open');
    if (body.getAttribute('data-ep-scroll-locked') === '1') return;
    epScrollY = window.pageYOffset || document.documentElement.scrollTop || 0;
    body.setAttribute('data-ep-scroll-locked', '1');
    body.style.position = 'fixed';
    body.style.top      = '-' + epScrollY + 'px';
    body.style.left     = '0';
    body.style.right    = '0';
    body.style.width    = '100%';
  }

  function releaseBodyScrollLock() {
    var body = document.body;
    body.classList.remove('ep-modal-open');
    if (body.getAttribute('data-ep-scroll-locked') !== '1') return;
    body.removeAttribute('data-ep-scroll-locked');
    body.style.removeProperty('position');
    body.style.removeProperty('top');
    body.style.removeProperty('left');
    body.style.removeProperty('right');
    body.style.removeProperty('width');
    window.scrollTo(0, epScrollY);
  }

  // Check whether ANY popup is still open before releasing scroll.
  // Prevents premature unlock when two popups are open simultaneously.
  function syncBodyScrollLock() {
    var anyOpen = document.querySelector('.ep-popup.ep-is-open, .ep-popup.ep-is-closing');
    if (anyOpen) {
      applyBodyScrollLock();
    } else {
      releaseBodyScrollLock();
    }
  }

  // ── Focus trap ────────────────────────────────────────────────────────────

  function getFocusable(popupEl) {
    var container = popupEl.querySelector('.ep-popup__container');
    if (!container) return [];
    return Array.prototype.slice
      .call(container.querySelectorAll(FOCUSABLE))
      .filter(function (n) {
        return n.offsetWidth > 0 || n.offsetHeight > 0;
      });
  }

  // ── Frequency ────────────────────────────────────────────────────────────

  // POPUP_SKIP_SUBMITTED_V2: ¿este popup debe ocultarse porque el navegador ya
  // envió alguno de SUS formularios (popup.formIds) o uno del mismo proyecto
  // (popup.projectScopes)? NUNCA por un envío global en otra landing.
  // SUBMITTED_TTL_V1: respeta la duración configurable (ttlDays). Con 0 la
  // restricción está desactivada y el popup siempre puede abrirse.
  function popupSubmitted(popup) {
    if (!(ttlDays > 0)) return false;
    var cutoff = Date.now() - ttlDays * 86400000;
    var forms  = popup.formIds || [];
    var projs  = popup.projectScopes || [];

    function ls(n) { try { return localStorage.getItem(n); } catch (e) { return null; } }
    function ck(n) { return document.cookie.indexOf(n + '=') !== -1; }

    if (forms.length || projs.length) {
      for (var i = 0; i < forms.length; i++) {
        if (ls('es_fv2_done_' + forms[i])) {
          var ts = parseInt(ls('es_fv2_done_' + forms[i] + '_t') || '0', 10);
          if (!ts || ts >= cutoff) return true;
        }
        if (ck('estandar_fv2_done_' + forms[i])) return true;
      }
      for (var j = 0; j < projs.length; j++) {
        if (ls('es_fv2_done_project_' + projs[j])) return true;
        if (ck('estandar_fv2_done_project_' + projs[j])) return true;
      }
      return false;
    }

    // Sin scope conocido: criterio global histórico (cualquier envío).
    try {
      for (var k = 0; k < localStorage.length; k++) {
        var key = localStorage.key(k);
        if (!key || key.indexOf('es_fv2_done_') !== 0 || /_t$/.test(key)) continue;
        var t2 = parseInt(localStorage.getItem(key + '_t') || '0', 10);
        if (!t2 || t2 >= cutoff) return true;
      }
      if (document.cookie.indexOf('estandar_fv2_done_') !== -1) return true;
    } catch (e) {}
    return false;
  }

  var freq = {
    storageKey: function (id) { return 'ep_' + id; },

    canShow: function (popup) {
      var mode = popup.frequency.mode;
      var days = popup.frequency.days;
      var key  = freq.storageKey(popup.id);

      // POPUP_SKIP_SUBMITTED_V2: si el popup contiene un form de captura y este
      // navegador ya envió ESE form (o uno del mismo proyecto), no se abre.
      if (popup.hideIfSubmitted && popupSubmitted(popup)) return false;

      if (mode === 'always') return true;
      if (mode === 'session') return !sessionStorage.getItem(key);
      if (mode === 'once_ever') return !localStorage.getItem(key);

      var stored = localStorage.getItem(key);
      if (!stored) return true;

      var daysMap = { '1day': 1, '7days': 7, '30days': 30 };
      var d = mode === 'custom' ? (parseInt(days, 10) || 7) : (daysMap[mode] || 7);
      return (Date.now() - parseInt(stored, 10)) > d * 86400 * 1000;
    },

    mark: function (popup) {
      var mode = popup.frequency.mode;
      var key  = freq.storageKey(popup.id);
      if (mode === 'always') return;
      if (mode === 'session') { sessionStorage.setItem(key, '1'); return; }
      localStorage.setItem(key, Date.now().toString());
    },
  };

  // ── Analytics ─────────────────────────────────────────────────────────────

  function track(popupId, counter) {
    var data = new FormData();
    data.append('action',   'ep_track');
    data.append('nonce',    nonce);
    data.append('popup_id', popupId);
    data.append('counter',  counter);
    if (navigator.sendBeacon) {
      navigator.sendBeacon(ajaxurl, data);
    } else {
      fetch(ajaxurl, { method: 'POST', body: data });
    }
  }

  function getPopup(popupId) {
    popupId = parseInt(popupId, 10);
    for (var i = 0; i < popups.length; i++) {
      if (parseInt(popups[i].id, 10) === popupId) return popups[i];
    }
    return null;
  }

  function trackAnalytics(popupId, eventType) {
    if (typeof window.estandarAnalyticsTrackEvent !== 'function') return;
    var popup = getPopup(popupId) || {};
    window.estandarAnalyticsTrackEvent(eventType, {
      label: popup.name || String(popupId),
      value: String(popupId),
      source: 'popup',
      popup_id: String(popupId),
      popup_name: popup.name || '',
      landing_url: window.location.href
    });
  }

  // ── Open / Close ──────────────────────────────────────────────────────────

  function openPopup(id, forceOpen) {
    id = parseInt(id, 10);
    var el = document.getElementById('ep-popup-' + id);
    if (!el || el.classList.contains('ep-is-open')) return;

    // Buscar popup en la array (popups clásicos)
    var popup = null;
    for (var i = 0; i < popups.length; i++) {
      if (popups[i].id === id) { popup = popups[i]; break; }
    }

    // Si el popup está en la array, verificar frecuencia
    if (popup && !forceOpen && !freq.canShow(popup)) return;

    // Save focus so we can restore it when the popup closes
    el._epPreviousFocus = document.activeElement;

    // POPUP_ANIM_LIB_V1: fuerza el estado inicial antes de animar (evita que el
    // navegador colapse la transición; Safari lo necesita explícitamente).
    el.classList.remove('ep-is-closing');
    void el.offsetWidth;
    el.classList.add('ep-is-open');
    applyBodyScrollLock();

    // Solo marcar frecuencia si el popup está en la array (no para bloques)
    if (popup && !forceOpen) {
      freq.mark(popup);
    }

    track(id, 'opens');
    trackAnalytics(id, 'popup_opened');

    // Move focus into the popup container after paint
    setTimeout(function () {
      var container = el.querySelector('.ep-popup__container');
      if (container) container.focus();
    }, 30);
  }

  function closePopup(id) {
    id = parseInt(id, 10);
    var el = document.getElementById('ep-popup-' + id);
    if (!el) return;

    // POPUP_ANIM_LIB_V1: reproduce el EFECTO DE SALIDA antes de desmontar.
    var durStr = getComputedStyle(el).getPropertyValue('--ep-dur').trim();
    var durMs  = parseFloat(durStr) > 0 ? parseFloat(durStr) * 1000 : 400;
    el.classList.remove('ep-is-open');
    el.classList.add('ep-is-closing');
    var elClosing = el;
    setTimeout(function () {
      elClosing.classList.remove('ep-is-closing');
      syncBodyScrollLock();
    }, durMs + 60);

    syncBodyScrollLock();
    trackAnalytics(id, 'popup_closed');

    // Return focus to the element that triggered the popup
    if (el._epPreviousFocus && typeof el._epPreviousFocus.focus === 'function') {
      el._epPreviousFocus.focus();
    }
    el._epPreviousFocus = null;
  }

  // ── Triggers ──────────────────────────────────────────────────────────────

  function setupTriggers(popup) {
    var id             = popup.id;
    var time           = popup.triggers.time;
    var scroll         = popup.triggers.scroll;
    var inactivity     = popup.triggers.inactivity;
    var click_selector = popup.triggers.click_selector;

    // TRIGGER_ONCE_V1: el popup se abre con el PRIMER trigger que ocurra y los
    // demás se desactivan (no se re-abre al alcanzar el otro umbral).
    var fired = false;
    var timeTimer = null;
    var inactTimer = null;
    var scrollHandler = null;
    var inactHandler = null;

    function fire() {
      if (fired) return;
      fired = true;
      if (timeTimer) { clearTimeout(timeTimer); timeTimer = null; }
      if (inactTimer) { clearTimeout(inactTimer); inactTimer = null; }
      if (scrollHandler) { window.removeEventListener('scroll', scrollHandler); scrollHandler = null; }
      if (inactHandler) {
        ['mousemove', 'keydown', 'scroll', 'touchstart'].forEach(function (ev) {
          document.removeEventListener(ev, inactHandler);
        });
        inactHandler = null;
      }
      openPopup(id);
    }

    // Time
    if (time > 0) {
      timeTimer = setTimeout(fire, time * 1000);
    }

    // Scroll %
    if (scroll > 0) {
      scrollHandler = function () {
        var scrollable = document.documentElement.scrollHeight - window.innerHeight;
        if (scrollable <= 0) return;
        var pct = (window.scrollY / scrollable) * 100;
        if (pct >= scroll) fire();
      };
      window.addEventListener('scroll', scrollHandler, { passive: true });
    }

    // Inactivity
    if (inactivity > 0) {
      var reset = function () {
        clearTimeout(inactTimer);
        inactTimer = setTimeout(fire, inactivity * 1000);
      };
      inactHandler = reset;
      ['mousemove', 'keydown', 'scroll', 'touchstart'].forEach(function (ev) {
        document.addEventListener(ev, reset, { passive: true });
      });
      reset();
    }

    // Click selector
    if (click_selector) {
      document.querySelectorAll(click_selector).forEach(function (el) {
        el.addEventListener('click', function (e) {
          e.preventDefault();
          fire();
        });
      });
    }
  }

  // ── Global event listeners ────────────────────────────────────────────────

  // data-ep-open
  document.addEventListener('click', function (e) {
    var el = e.target.closest && e.target.closest('[data-ep-open]');
    if (el) {
      e.preventDefault();
      openPopup(el.dataset.epOpen, true);
    }
  });

  // data-ep-close
  document.addEventListener('click', function (e) {
    var el = e.target.closest && e.target.closest('[data-ep-close]');
    if (el) {
      e.preventDefault();
      closePopup(el.dataset.epClose);
    }
  });

  // JS Event API
  document.addEventListener('estandar_pop:open', function (e) {
    if (e.detail && e.detail.id) openPopup(e.detail.id);
  });

  // Escape key + focus trap (Tab / Shift+Tab)
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape' && e.key !== 'Tab') return;

    var openEl = document.querySelector('.ep-popup.ep-is-open');
    if (!openEl) return;

    if (e.key === 'Escape') {
      e.preventDefault();
      closePopup(parseInt(openEl.id.replace('ep-popup-', ''), 10));
      return;
    }

    // Tab: keep focus within the open popup
    var focusable = getFocusable(openEl);
    if (focusable.length === 0) return;
    var first = focusable[0];
    var last  = focusable[focusable.length - 1];

    if (e.shiftKey) {
      if (document.activeElement === first) {
        e.preventDefault();
        last.focus();
      }
    } else {
      if (document.activeElement === last) {
        e.preventDefault();
        first.focus();
      }
    }
  });

  // Click tracking inside open popups
  document.addEventListener('click', function (e) {
    if (e.target.closest('[data-ep-close]') || e.target.closest('[data-ep-open]')) return;
    var popup = e.target.closest('.ep-popup.ep-is-open');
    if (!popup) return;
    track(popup.id.replace('ep-popup-', ''), 'clicks');
    trackAnalytics(popup.id.replace('ep-popup-', ''), 'popup_cta_clicked');
  });

  document.addEventListener('focusin', function (e) {
    var popup = e.target.closest('.ep-popup.ep-is-open');
    if (!popup || !e.target.closest('form')) return;
    if (popup.getAttribute('data-ep-form-started') === '1') return;
    popup.setAttribute('data-ep-form-started', '1');
    trackAnalytics(popup.id.replace('ep-popup-', ''), 'popup_form_started');
  });

  // Submit tracking
  document.addEventListener('submit', function (e) {
    var popup = e.target.closest('.ep-popup.ep-is-open');
    if (!popup) return;
    track(popup.id.replace('ep-popup-', ''), 'submits');
    trackAnalytics(popup.id.replace('ep-popup-', ''), 'popup_form_submitted');
  });

  // ── Init ──────────────────────────────────────────────────────────────────

  // POPUP_PORTAL_V1: mover el shell a <body> para que position:fixed sea SIEMPRE
  // relativo al viewport. Si un ancestro tuviera transform/filter/will-change,
  // el popup quedaría anclado a esa caja y el overlay no cubriría la ventana.
  popups.forEach(function (popup) {
    var el = document.getElementById('ep-popup-' + popup.id);
    if (el && el.parentNode && el.parentNode !== document.body) {
      document.body.appendChild(el);
    }
  });

  popups.forEach(function (popup) {
    track(popup.id, 'views');
    trackAnalytics(popup.id, 'popup_impression');
    setupTriggers(popup);
  });

})();
