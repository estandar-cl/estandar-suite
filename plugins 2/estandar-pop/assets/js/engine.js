(function () {
  'use strict';

  var cfg     = window.epConfig || {};
  var popups  = cfg.popups  || [];
  var ajaxurl = cfg.ajaxurl || '';
  var nonce   = cfg.nonce   || '';

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
    var anyOpen = document.querySelector('.ep-popup.ep-is-open');
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

  var freq = {
    storageKey: function (id) { return 'ep_' + id; },

    canShow: function (popup) {
      var mode = popup.frequency.mode;
      var days = popup.frequency.days;
      var key  = freq.storageKey(popup.id);

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

    el.classList.remove('ep-is-open');
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

    // Time
    if (time > 0) {
      setTimeout(function () { openPopup(id); }, time * 1000);
    }

    // Scroll %
    if (scroll > 0) {
      var checkScroll = function () {
        var scrollable = document.documentElement.scrollHeight - window.innerHeight;
        if (scrollable <= 0) return;
        var pct = (window.scrollY / scrollable) * 100;
        if (pct >= scroll) {
          openPopup(id);
          window.removeEventListener('scroll', checkScroll);
        }
      };
      window.addEventListener('scroll', checkScroll, { passive: true });
    }

    // Inactivity
    if (inactivity > 0) {
      var timer;
      var reset = function () {
        clearTimeout(timer);
        timer = setTimeout(function () { openPopup(id); }, inactivity * 1000);
      };
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
          openPopup(id);
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

  popups.forEach(function (popup) {
    track(popup.id, 'views');
    trackAnalytics(popup.id, 'popup_impression');
    setupTriggers(popup);
  });

})();
