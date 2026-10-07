(function () {
  "use strict";

  if (window.__ESTANDAR_CHATBOT_LOADER_INITIALIZED) return;
  window.__ESTANDAR_CHATBOT_LOADER_INITIALIZED = true;

  const cfg = window.chatbotConfig || {};
  if (cfg.activo === false || cfg.activo === "0") return;

  const scriptUrls = cfg.scriptUrls || {};
  const mounts = Array.from(
    document.querySelectorAll('.estandar-chatbot-shortcode[data-ecb-shortcode="1"]')
  );
  let loadingPromise = window.__ESTANDAR_CHATBOT_LOADING_PROMISE || null;
  let autoOpenTimer = null;

  function escapeHTML(str) {
    return String(str)
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  function loadScript(src) {
    return new Promise((resolve, reject) => {
      if (!src) {
        reject(new Error("Missing chatbot script URL"));
        return;
      }
      const existing = document.querySelector(`script[data-ecb-src="${src}"]`);
      if (existing) {
        if (existing.dataset.ecbLoaded === "1") {
          resolve();
          return;
        }
        existing.addEventListener("load", resolve, { once: true });
        existing.addEventListener("error", reject, { once: true });
        return;
      }
      const script = document.createElement("script");
      script.src = src;
      script.async = false;
      script.dataset.ecbSrc = src;
      script.onload = () => {
        script.dataset.ecbLoaded = "1";
        resolve();
      };
      script.onerror = reject;
      document.head.appendChild(script);
    });
  }

  function normalizeMode(mount) {
    return String(mount?.dataset?.mode || "inline").toLowerCase();
  }

  function getInlineMounts() {
    return mounts.filter((mount) => normalizeMode(mount) !== "widget");
  }

  function getWidgetMounts() {
    return mounts.filter((mount) => normalizeMode(mount) === "widget");
  }

  function getFloatingPositionConfig() {
    const theme = cfg.theme || {};
    const pos = cfg.posicion || {};
    const mode = String(pos.modo || theme.position || "right").trim().toLowerCase();
    const parsePos = (value) => {
      if (value === "" || value === null || typeof value === "undefined") return null;
      const parsed = Number(value);
      return Number.isFinite(parsed) ? Math.max(0, parsed) : null;
    };
    return {
      mode,
      bottom: Math.max(0, Number(pos.bottom || 22) || 22),
      left: parsePos(pos.left),
      right: parsePos(pos.right),
    };
  }

  function applyThemeVars(root) {
    const theme = cfg.theme || {};
    const branding = cfg.branding && typeof cfg.branding === "object" ? cfg.branding : {};
    const accent = branding.button_color || theme.accent || "#111827";
    const bubbleSize = Math.max(40, Math.min(88, Number(theme.bubbleSize || 58) || 58));
    root.style.setProperty("--ecb-accent", accent);
    root.style.setProperty("--ecb-float-size", `${bubbleSize}px`);

    const radius = Number(theme.radius || 22) || 22;
    const bubbleShape = String(theme.bubbleShape || "circle").toLowerCase();
    if (bubbleShape === "square") {
      root.style.setProperty("--ecb-float-radius", "0px");
    } else if (bubbleShape === "circle" || bubbleShape === "rounded") {
      root.style.setProperty("--ecb-float-radius", "9999px");
    } else {
      root.style.setProperty("--ecb-float-radius", `${Math.max(0, radius)}px`);
    }
  }

  function getFloatingStackOffset(anchor) {
    const selector = `[data-estandar-floating-module="wsp-bubble"].estandar-wsp-container--${anchor}`;
    const wspBubble = document.querySelector(selector);
    if (!wspBubble) return 0;

    const rect = wspBubble.getBoundingClientRect();
    const height = rect && rect.height ? rect.height : 60;
    return Math.ceil(height + 14);
  }

  function applyFloatingPosition(dom) {
    const theme = cfg.theme || {};
    const cfgPos = getFloatingPositionConfig();
    const bubbleSize = Math.max(40, Math.min(88, Number(theme.bubbleSize || 58) || 58));
    const anchor =
      cfgPos.mode === "left" ? "left" : cfgPos.mode === "custom" && cfgPos.left !== null ? "left" : "right";
    const sideValue =
      anchor === "left" ? (cfgPos.left !== null ? cfgPos.left : 22) : (cfgPos.right !== null ? cfgPos.right : 22);

    dom.root.dataset.position = anchor;
    const stackOffset = getFloatingStackOffset(anchor);
    dom.floatBtn.style.bottom = `${cfgPos.bottom + stackOffset}px`;
    if (anchor === "left") {
      dom.floatBtn.style.left = `${sideValue}px`;
      dom.floatBtn.style.right = "auto";
    } else {
      dom.floatBtn.style.right = `${sideValue}px`;
      dom.floatBtn.style.left = "auto";
    }
  }

  function createBubble(mount, index) {
    const instanceKey = mount ? `shortcode_${index}` : "floating_widget";
    const host = document.createElement("div");
    host.id = mount ? `estandar-chatbot-loader-host-${instanceKey}` : "estandar-chatbot-loader-host";
    host.dataset.ecbLoader = "1";
    host.dataset.ecbInstanceKey = instanceKey;

    if (mount) {
      mount.innerHTML = "";
      mount.appendChild(host);
    } else {
      document.body.appendChild(host);
    }

    const root = host.attachShadow ? host.attachShadow({ mode: "open" }) : host;
    if (cfg.cssUrl) {
      const link = document.createElement("link");
      link.rel = "stylesheet";
      link.href = cfg.cssUrl;
      root.appendChild(link);
    }

    const wrap = document.createElement("div");
    wrap.id = "estandar-chatbot";
    applyThemeVars(wrap);
    const bubbleTooltip = String(cfg.bubbleTooltip || "¿Necesitas ayuda?").trim();
    const bubbleImageUrl = String(cfg.bubbleImageUrl || "").trim();
    const imageStyle = bubbleImageUrl
      ? ` style="background-image:url('${escapeHTML(bubbleImageUrl)}');background-size:cover;background-position:center;"`
      : "";
    wrap.innerHTML = `
      <div class="ecb-float" id="ecbFloat" title="${escapeHTML(bubbleTooltip)}"${imageStyle}>
        <span class="ecb-float__icon" id="ecbFloatIcon"${bubbleImageUrl ? " hidden" : ""}>💬</span>
        <span class="ecb-float__badge" id="ecbBadge" hidden></span>
      </div>
    `;
    root.appendChild(wrap);

    const dom = {
      host,
      root: wrap,
      floatBtn: root.querySelector("#ecbFloat"),
      instanceKey,
    };
    applyFloatingPosition(dom);
    dom.floatBtn.addEventListener("click", (event) => {
      event.preventDefault();
      event.stopPropagation();
      loadFullChatbot(instanceKey, true);
    });
  }

  function removeLoaderBubbles() {
    document.querySelectorAll('[data-ecb-loader="1"]').forEach((node) => node.remove());
  }

  function markOpen(instanceKey) {
    try {
      localStorage.setItem(`estandar_chatbot_open_${instanceKey || "floating_widget"}`, "1");
    } catch {}
  }

  function loadFullChatbot(instanceKey, openAfterLoad) {
    if (openAfterLoad) markOpen(instanceKey);
    if (!loadingPromise) {
      clearAutoOpen();
      removeLoaderBubbles();
      loadingPromise = loadScript(scriptUrls.stateManager)
        .then(() => loadScript(scriptUrls.decisionEngine))
        .then(() => loadScript(scriptUrls.chatbot))
        .catch((error) => {
          loadingPromise = null;
          window.__ESTANDAR_CHATBOT_LOADING_PROMISE = null;
          throw error;
        });
      window.__ESTANDAR_CHATBOT_LOADING_PROMISE = loadingPromise;
    }
    return loadingPromise;
  }

  function hasAutoOpened(instanceKey) {
    try {
      return sessionStorage.getItem(`estandar_chatbot_auto_opened_${instanceKey}`) === "1";
    } catch {
      return false;
    }
  }

  function hasStoredOpen(instanceKey) {
    try {
      return localStorage.getItem(`estandar_chatbot_open_${instanceKey}`) === "1";
    } catch {
      return false;
    }
  }

  function clearAutoOpen() {
    if (autoOpenTimer) {
      window.clearTimeout(autoOpenTimer);
      autoOpenTimer = null;
    }
    window.removeEventListener("scroll", handleScrollAutoOpen);
  }

  function shouldAutoOpen(instanceKey) {
    const rawAutoOpen = cfg.autoOpen && typeof cfg.autoOpen === "object" ? cfg.autoOpen : {};
    const enabled = rawAutoOpen.enabled === true || rawAutoOpen.enabled === "1";
    if (!enabled) return false;
    if (hasAutoOpened(instanceKey)) return false;
    if (hasStoredOpen(instanceKey)) return false;
    return true;
  }

  function getScrollPercent() {
    const scrollTop = Math.max(window.scrollY || 0, document.documentElement.scrollTop || 0, document.body.scrollTop || 0);
    const docHeight = Math.max(document.body.scrollHeight || 0, document.documentElement.scrollHeight || 0);
    const viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
    const maxScroll = docHeight - viewportHeight;
    if (maxScroll <= 0) return 100;
    return (scrollTop / maxScroll) * 100;
  }

  function handleScrollAutoOpen() {
    const rawAutoOpen = cfg.autoOpen && typeof cfg.autoOpen === "object" ? cfg.autoOpen : {};
    const value = Math.max(1, Math.min(100, Number(rawAutoOpen.value || 6) || 6));
    if (getScrollPercent() >= value) {
      loadFullChatbot("floating_widget", true);
    }
  }

  function scheduleAutoOpen() {
    const instanceKey = "floating_widget";
    if (!shouldAutoOpen(instanceKey)) return;
    const rawAutoOpen = cfg.autoOpen && typeof cfg.autoOpen === "object" ? cfg.autoOpen : {};
    const type = String(rawAutoOpen.type || "time").trim().toLowerCase() === "scroll" ? "scroll" : "time";
    const value = Math.max(1, Math.min(100, Number(rawAutoOpen.value || 6) || 6));
    if (type === "scroll") {
      window.addEventListener("scroll", handleScrollAutoOpen, { passive: true });
      handleScrollAutoOpen();
      return;
    }
    autoOpenTimer = window.setTimeout(() => {
      loadFullChatbot(instanceKey, true);
    }, value * 1000);
  }

  function bootLoader() {
    const inlineMounts = getInlineMounts();
    if (inlineMounts.length) {
      loadFullChatbot("floating_widget", false);
      return;
    }

    const widgetMounts = getWidgetMounts();
    if (widgetMounts.length) {
      widgetMounts.forEach((mount, index) => createBubble(mount, index));
    } else {
      createBubble(null, 0);
    }
    scheduleAutoOpen();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", bootLoader, { once: true });
  } else {
    bootLoader();
  }
})();
