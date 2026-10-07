(function () {
  "use strict";

  if (window.__ESTANDAR_CHATBOT_INITIALIZED) return;
  window.__ESTANDAR_CHATBOT_INITIALIZED = true;

  const decisionEngine = window.EstandarChatbotDecisionEngine || {};
  const stateManager = window.EstandarChatbotStateManager || {};
  const globalCfg = window.chatbotConfig || {};
  const globalBehaviorCopy =
    globalCfg.behaviorCopy && typeof globalCfg.behaviorCopy === "object"
      ? globalCfg.behaviorCopy
      : {};
  const globalCTAConfig =
    globalCfg.cta && typeof globalCfg.cta === "object" ? globalCfg.cta : {};
  const mounts = Array.from(
    document.querySelectorAll('.estandar-chatbot-shortcode[data-ecb-shortcode="1"]')
  );

  function escapeHTML(str) {
    return String(str)
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  function renderBotText(text) {
    const source = String(text || "");
    const ctaLabel = getContextualLinkLabel(source);
    const parts = source.split(/(https?:\/\/[^\s]+)/g);
    const html = parts
      .map((part) => {
        if (!part) return "";
        if (/^https?:\/\/[^\s]+$/.test(part)) {
          const safeUrl = escapeHTML(part);
          return `<span class="ecb-cta"><button type="button" class="ecb-link-btn" data-ecb-link-url="${safeUrl}">${escapeHTML(ctaLabel)}</button></span>`;
        }
        return escapeHTML(part);
      })
      .join("");

    return html.replaceAll("\n", "<br>");
  }

  function getContextualLinkLabel(text) {
    const normalized = String(text || "")
      .toLowerCase()
      .normalize("NFD")
      .replace(/[\u0300-\u036f]/g, "");
    const specialServiceKeyword = String(globalBehaviorCopy.special_service_keyword || "renta")
      .toLowerCase()
      .normalize("NFD")
      .replace(/[\u0300-\u036f]/g, "")
      .trim();
    const specialServiceCtaLabel = String(
      globalBehaviorCopy.special_service_cta_label || "Hacer mi renta"
    ).trim();

    function getCTA(key, fallback) {
      const value = String(globalCTAConfig[key] || "").trim();
      if (value) return value;
      const defaultValue = String(globalCTAConfig.default || "").trim();
      return defaultValue || fallback;
    }

    if (
      normalized.includes("wa.me/") ||
      normalized.includes("whatsapp") ||
      normalized.includes("abrir whatsapp")
    ) {
      return "Abrir WhatsApp";
    }
    if (normalized.includes("pagar") || normalized.includes("pago")) {
      return getCTA("pagar", "Pagar ahora");
    }
    if (normalized.includes("precio") || normalized.includes("precios")) {
      return getCTA("precio", "Ver precios");
    }
    if (normalized.includes("cotizar") || normalized.includes("cotizacion") || normalized.includes("cotizacion")) {
      return getCTA("cotizar", "Cotizar ahora");
    }
    if (normalized.includes("contratar") || normalized.includes("contrato")) {
      return getCTA("contratar", "Contratar ahora");
    }
    if (specialServiceKeyword && normalized.includes(specialServiceKeyword)) {
      return specialServiceCtaLabel || getCTA("contratar", "Contratar ahora");
    }
    return getCTA("default", "Ver mas");
  }

  function getVisualPreset(style) {
    const normalized = String(style || "").trim().toLowerCase();
    if (normalized === "corporativo_minimalista" || normalized === "minimal_corporate") {
      return {
        radius: "12px",
        shadow: "0 4px 12px rgba(0,0,0,0.06)",
        shadowStrong: "0 8px 20px rgba(0,0,0,0.08)",
        bg: "#ffffff",
        bgSoft: "#f3f4f6",
        text: "#111827",
        muted: "#6b7280",
        border: "#e5e7eb",
        primary: "#2563eb",
        inputBg: "#f3f4f6",
        buttonBg: "#2563eb",
        userBubble: "#e8f0fe",
        botBubble: "#f3f4f6",
      };
    }
    return {};
  }

  function isValidUrl(url) {
    try {
      new URL(url);
      return true;
    } catch {
      return false;
    }
  }

  function normalizeButtons(raw) {
    let arr = raw;
    if (typeof raw === "string") {
      try {
        arr = JSON.parse(raw);
      } catch {
        arr = [];
      }
    }
    if (!Array.isArray(arr)) arr = [];

    return arr
      .filter((b) => b && (b.texto || b.text))
      .map((b) => ({
        label: String(b.texto || b.text || ""),
        type: String(b.tipo || "info"),
        value: String(b.payload || ""),
      }));
  }

  function normalizeLeadFields(raw) {
    const fallback = [
      { key: "name", label: "¿Cómo te llamas?", required: true },
      { key: "email", label: "¿Cuál es tu correo electrónico?", required: true },
      { key: "phone", label: "¿Tu teléfono?", required: false },
    ];

    const source = Array.isArray(raw) && raw.length ? raw : fallback;
    return source
      .map((f) => ({
        key: String(f.key || "").trim().toLowerCase().replace(/[^a-z0-9_]/g, ""),
        label: String(f.label || "").trim(),
        required: Boolean(f.required),
      }))
      .filter((f) => f.key && f.label);
  }

  function normalizeLeadIntents(raw) {
    let arr = raw;
    if (typeof raw === "string") {
      try {
        arr = JSON.parse(raw);
      } catch {
        arr = [];
      }
    }
    if (!Array.isArray(arr)) arr = [];

    return arr
      .map((item) => ({
        id: String(item?.id || "").trim(),
        label: String(item?.label || "").trim(),
      }))
      .filter((item) => item.id && item.label)
      .map((item) => ({
        label: item.label,
        type: "lead_intent",
        value: item.id,
      }));
  }

  function scrollToBottom(el) {
    if (el) el.scrollTop = el.scrollHeight;
  }

  function updateViewportHeightVar() {
    const viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
    if (!viewportHeight) return;
    document.documentElement.style.setProperty("--vh", `${viewportHeight * 0.01}px`);
  }

  function createSessionId() {
    // P6 — crypto.getRandomValues: 128 bits de entropía criptográfica
    if ( window.crypto && window.crypto.getRandomValues ) {
      const arr = new Uint8Array(16);
      window.crypto.getRandomValues(arr);
      return 'ecb_' + Array.from(arr).map(b => b.toString(16).padStart(2, '0')).join('');
    }
    // Fallback para browsers sin crypto API (IE11)
    const now = Date.now().toString(36);
    const rand = Math.random().toString(36).slice(2, 10);
    return `ecb_${now}_${rand}`;
  }

  function getCookie(name) {
    const parts = String(document.cookie || "").split(";");
    for (let i = 0; i < parts.length; i += 1) {
      const pair = parts[i].split("=");
      if (decodeURIComponent(String(pair.shift() || "").trim()) === name) {
        return decodeURIComponent(pair.join("=") || "");
      }
    }
    return "";
  }

  function getAnalyticsSessionId() {
    const cookieSid = getCookie("ea_sid");
    if (cookieSid) return cookieSid;
    try {
      return localStorage.getItem("ea_sid") || "";
    } catch {
      return "";
    }
  }

  function trackAnalyticsEvent(type, extra = {}) {
    if (typeof window.estandarAnalyticsTrackEvent !== "function") return;
    window.estandarAnalyticsTrackEvent(type, Object.assign({
      source: "chatbot",
      chatbot_session_key: "",
    }, extra));
  }

  function initInstance(mount, index) {
    if (mount && mount.dataset.ecbMounted === "1") return;
    if (globalCfg.activo === false || globalCfg.activo === "0") return;

    // CONFIG
    const cfg = globalCfg;
    const contextSlug = String(mount?.dataset?.contextSlug || "").trim();
    const ajaxUrl = cfg.ajaxUrl || "";
    const cssUrl = cfg.cssUrl || "";
    let nonce = "";
    let noncePromise = null;
	    const whatsappRedirectUrl = String(cfg.whatsappRedirectUrl || "").trim();
	    const projectRegistry = Array.isArray(cfg.projectRegistry) ? cfg.projectRegistry : [];
	    const showBotName = cfg.mostrarNombre !== false && cfg.mostrarNombre !== "0";
    const botName = showBotName ? (cfg.botName || "Asistente") : "";
    const lang = cfg.idioma || "es";
    const theme = cfg.theme || {};
    const branding = cfg.branding && typeof cfg.branding === "object" ? cfg.branding : {};
    const bubblePosition = cfg.posicion || {};
    const bubbleImageUrl = String(cfg.bubbleImageUrl || "").trim();
    const bubbleTooltip = String(cfg.bubbleTooltip || "¿Necesitas ayuda?").trim();
    const siteName = String(cfg.siteName || "").trim();
    const leadConfirmMsg = String(cfg.leadConfirmMsg || "¡Gracias! Hemos recibido tu información. Te contactaremos pronto.").trim();
    const errorFriendly = cfg.errorFriendly || {};
    const leadCaptureEnabled = cfg.leadCaptureEnabled !== false && cfg.leadCaptureEnabled !== "0";
    const rawAutoOpen = cfg.autoOpen && typeof cfg.autoOpen === "object" ? cfg.autoOpen : {};
    const autoOpenEnabled = rawAutoOpen.enabled === true || rawAutoOpen.enabled === "1";
    const autoOpenType = String(rawAutoOpen.type || "time").trim().toLowerCase() === "scroll" ? "scroll" : "time";
    const autoOpenValue = Math.max(1, Math.min(100, Number(rawAutoOpen.value || 6) || 6));
    const hideMenuOnClick = cfg.hideMenuOnClick !== false && cfg.hideMenuOnClick !== "0";
    const humanEscalationEnabled =
      cfg.humanEscalationEnabled !== false && cfg.humanEscalationEnabled !== "0";
    const humanEscalationText = String(
      cfg.humanEscalationText ||
        "Si prefieres, te puedo derivar ahora mismo con una persona del equipo."
    );
    const humanEscalationMode = String(cfg.humanEscalationMode || "lead_form").trim();
    const humanEscalationWhatsAppNumber = String(cfg.humanEscalationWhatsAppNumber || "").replace(/\D+/g, "");
    const humanEscalationWhatsAppMessage = String(
      cfg.humanEscalationWhatsAppMessage ||
      "Hola, quiero hablar con una persona del equipo. Dejé mis datos en la web."
    ).trim();
    const postLeadMessage = String(
      cfg.postLeadMessage ||
      "Perfecto 👌 ya tengo tus datos.\n\nSi quieres avanzar ahora mismo, podemos verlo por WhatsApp."
    ).trim();
    const postLeadActions = Array.isArray(cfg.postLeadActions) ? cfg.postLeadActions : [];
    const leadIntroText = String(
      cfg.leadIntroText ||
        "Te ayudo rápidamente. Primero dime qué necesitas para derivarte con el equipo correcto."
    );
    const leadFields = normalizeLeadFields(Array.isArray(cfg.leadFields) ? cfg.leadFields : []);
    const leadIntentButtons = normalizeLeadIntents(
      Array.isArray(cfg.leadIntents) ? cfg.leadIntents : []
    );
    const buttons = normalizeButtons(cfg.botones || []);
    function parseFlow(raw) {
      const out = { intro: [], tags: [] };
      if (!raw || !String(raw).trim()) return out;
      try {
        const j = JSON.parse(String(raw));
        if (j && Array.isArray(j.intro)) out.intro = j.intro.map((x) => String(x));
        if (j && Array.isArray(j.tags)) out.tags = j.tags.map((x) => String(x));
      } catch {
        return { intro: [], tags: [] };
      }
      return out;
    }
    const flowCfg = parseFlow(cfg.flowJson);
    const flowEnabled = cfg.flowEnabled === true || cfg.flowEnabled === "1";
    const inlineFeatures = cfg.inlineFeatures || {};
    const behaviorCopy = cfg.behaviorCopy && typeof cfg.behaviorCopy === "object" ? cfg.behaviorCopy : {};
    const capabilities = cfg.capabilities && typeof cfg.capabilities === "object" ? cfg.capabilities : {};
    const enableWebsiteDemo = capabilities.enableWebsiteDemo === true || capabilities.enableWebsiteDemo === "1";
    const requiresDisambiguation =
      capabilities.requiresDisambiguation === true || capabilities.requiresDisambiguation === "1";
    const businessContext = cfg.businessContext && typeof cfg.businessContext === "object" ? cfg.businessContext : {};
    const businessPrimaryCta = String(businessContext.primaryCta || "Quiero avanzar").trim();
    const mode = String(mount?.dataset?.mode || "widget").toLowerCase();
    const inlineMode = !!mount && mode !== "widget";
    const inlineHeight = Number(mount?.dataset?.height || 680);
    const inlineSidebarEnabled = inlineMode && inlineFeatures.sidebar === true;
    const inlineFixedComposerEnabled = inlineMode && inlineFeatures.fixedComposer === true;
    const inlineFullpageEnabled = inlineMode && inlineFeatures.fullpage === true;
    const instanceKey = mount ? `shortcode_${index}` : "floating_widget";
    const storageKey = `estandar_chatbot_open_${instanceKey}`;
    const memoryKey = `estandar_chatbot_memory_${instanceKey}`;
    const sessionKey = `estandar_chatbot_session_id_${instanceKey}`;
    const storedLeadKey = `estandar_chatbot_lead_${instanceKey}`;
    const redirectDoneBaseKey = `estandar_chatbot_redirect_done_${instanceKey}`;
    const autoOpenKey = `estandar_chatbot_auto_opened_${instanceKey}`;
    const chatStateKey = `estandar_chatbot_chat_state_${instanceKey}`;
    const memoryLimit = 12;
    const conversionPrioritaria = true;
    const primaryLinkButton = buttons.find((button) => button.type === "link" && isValidUrl(button.value));

    async function ensureNonce(force = false) {
      if (nonce && !force) return nonce;
      if (noncePromise && !force) return noncePromise;
      noncePromise = (async () => {
        if (!ajaxUrl) return "";
        try {
          const p = new URLSearchParams({ action: "estandar_chatbot_runtime_nonce" });
          const res = await fetch(ajaxUrl, {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
            body: p.toString(),
            credentials: "same-origin",
          });
          const json = await res.json().catch(() => null);
          const fresh = String(json?.data?.nonce || "").trim();
          nonce = fresh;
          return fresh;
        } catch {
          return nonce || String(cfg.nonce || "");
        } finally {
          noncePromise = null;
        }
      })();
      return noncePromise;
    }

    function isNonceInvalidResponse(res, json) {
      return (
        res?.status === 403 &&
        String(json?.data?.code || "") === "nonce_invalid"
      );
    }

    async function postChatbotAction(action, data = {}, retry = true) {
      const currentNonce = await ensureNonce();
      if (!ajaxUrl || !currentNonce) return null;
      const payload = new URLSearchParams(Object.assign({ action, nonce: currentNonce }, data));
      const res = await fetch(ajaxUrl, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
        body: payload.toString(),
        credentials: "same-origin",
      });
      const json = await res.json().catch(() => null);
      if (retry && isNonceInvalidResponse(res, json)) {
        nonce = "";
        await ensureNonce(true);
        return postChatbotAction(action, data, false);
      }
      return json;
    }
    const hotLeadUrl = primaryLinkButton ? primaryLinkButton.value : "";
    const hotLeadLabel = primaryLinkButton ? primaryLinkButton.label : "Ver más información";
    const contactTeamText = siteName ? `una persona de ${siteName}` : "una persona del equipo";
    const hasStrongConfiguredExit = !!hotLeadUrl || !!humanEscalationWhatsAppNumber || !!buttons.length;
    const hotLeadIntentPatterns = [
      "ayudame",
      "ayudame ya",
      "ya quiero",
      "hagamoslo",
      "dejate de",
      "quiero hacerlo",
      "me interesa",
      "como pago",
      "cómo pago",
      "ahora",
      "si!",
      "sí!",
      "si,",
      "sí,",
      "quiero pagar",
      "quiero contratar",
      "quiero avanzar",
    ];
    const defaultHighIntentKeywords = [
      "pagar",
      "contratar",
      "cotizar",
      "reservar",
      "agendar",
      "comprar",
      "avanzar",
    ];
    const defaultTaxQuestionKeywords = [
      "cuanto tengo que pagar",
      "cuánto tengo que pagar",
      "devolucion",
      "devolución",
      "impuesto",
    ];
    function splitKeywords(value, fallback) {
      const source = String(value || "").trim();
      const items = source
        ? source
            .split(/\r?\n|,/)
            .map((item) => normalizeIntentText(item))
            .filter(Boolean)
        : [];
      return items.length ? items : fallback.map((item) => normalizeIntentText(item)).filter(Boolean);
    }
    function includesAnyKeyword(normalizedText, keywords) {
      if (!normalizedText) return false;
      return keywords.some((keyword) => normalizedText.includes(keyword));
    }
    const specialServiceKeyword = normalizeIntentText(
      behaviorCopy.special_service_keyword || "renta"
    );
    const specialServiceTransitionMessage = String(
      behaviorCopy.special_service_transition_message ||
        "Perfecto 👌\n\nPara ayudarte con tu renta, déjame tu nombre 👇"
    ).trim();
    const highIntentTransitionMessage = String(
      behaviorCopy.high_intent_transition_message ||
        "Perfecto 👌\n\nPara avanzar ahora mismo, te pido un dato breve.\n¿Cuál es tu nombre? 👇"
    ).trim();
    const priceTransitionMessage = String(
      behaviorCopy.price_transition_message ||
        "Depende de tu caso.\n\nEl valor final puede cambiar según lo que tengas que pagar o incluso si te corresponde devolución.\nPara decirte bien cómo aplica, dime tu nombre 👇"
    ).trim();
    const priceCtaMessage = String(
      behaviorCopy.price_cta_message || "Depende del caso, pero puedes revisar opciones ahora mismo 👇"
    ).trim();
    const priceWhatsAppMessage = String(
      behaviorCopy.price_whatsapp_message ||
        "Depende del caso, pero te dejo el acceso directo para verlo contigo 👇"
    ).trim();
    const objectionMessage = String(
      behaviorCopy.objection_message ||
        "Da lo mismo el monto 👌\n\nLo importante es que no pagues de más.\nMuchas veces incluso puedes recibir devolución."
    ).trim();
    const objectionRepeatMessage = String(
      behaviorCopy.objection_repeat_message ||
        "Lo clave aquí es que revisemos tu caso para que no pagues de más 👇"
    ).trim();
    const taxCtaMessage = String(
      behaviorCopy.tax_cta_message ||
        "Depende de tu caso, pero muchas veces se puede bajar bastante el monto o incluso generar devolución."
    ).trim();
    const postLeadHotMessage = String(
      behaviorCopy.post_lead_hot_message ||
        "Perfecto 👌\n\nYa tengo tus datos.\n\nSi quieres que te contacten ahora, toca el botón de abajo 👇"
    ).trim();
    const highIntentKeywords = splitKeywords(
      behaviorCopy.high_intent_keywords,
      defaultHighIntentKeywords
    );
    const taxQuestionKeywords = splitKeywords(
      behaviorCopy.tax_question_keywords,
      defaultTaxQuestionKeywords
    );

    const chatbotConfig = {
      ui: {
        showBotName,
        botName,
        lang,
        theme,
        bubblePosition,
        bubbleImageUrl,
        bubbleTooltip,
        buttons,
        inlineFeatures,
        inlineMode,
        inlineHeight,
        inlineSidebarEnabled,
        inlineFixedComposerEnabled,
        inlineFullpageEnabled,
      },
      flow: {
        enabled: flowEnabled,
        intro: flowCfg.intro,
        tags: flowCfg.tags,
        hideMenuOnClick,
        hotLeadIntentPatterns,
      },
      leadCapture: {
        enabled: leadCaptureEnabled,
        introText: leadIntroText,
        fields: leadFields,
        confirmMessage: leadConfirmMsg,
        humanEscalationEnabled,
        humanEscalationText,
        humanEscalationMode,
        humanEscalationWhatsAppNumber,
        humanEscalationWhatsAppMessage,
      },
      postLead: {
        message: postLeadMessage,
        actions: postLeadActions,
      },
      routing: {
        siteName,
        contactTeamText,
        hotLeadUrl,
        hotLeadLabel,
        conversionPrioritaria,
      },
      transport: {
        ajaxUrl,
        cssUrl,
        nonce,
      },
      storage: {
        instanceKey,
        storageKey,
        memoryKey,
        sessionKey,
        redirectDoneBaseKey,
        autoOpenKey,
        chatStateKey,
        memoryLimit,
      },
    };
    void ensureNonce(true);

    // STATE
    const chatbotState =
      typeof stateManager.createState === "function"
        ? stateManager.createState({
            leadCaptureEnabled,
            leadFields,
          })
        : {
            chatState: "default",
            lastAction: null,
            conversationClosed: false,
            currentStep: 0,
            awaitingLead: false,
            leadCaptured: false,
            lastIntent: "",
            intent: null,
            isTyping: false,
            currentButtons: [],
            lastUserMessage: null,
            lastGoodAnswer: null,
            retryCount: 0,
            offline: false,
            sessionId: "",
            skipDefaultWelcome: false,
            lastResponseType: "",
            leadFlow: {
              enabled: leadCaptureEnabled,
              active: false,
              completed: false,
              awaitingLead: false,
              lastBotHadCTA: false,
              index: 0,
              askedRequirement: false,
              fields: leadFields,
              intent: "",
              data: {},
              requirement: "",
            },
            pendingLeadStart: null,
          };
    const state = chatbotState;
    let userHasInteracted = false;
    state.activeOwner = "IDLE";
    state.processingInput = false;
    state.pendingInputQueue = [];
    state.activeRequestId = 0;
    state.pendingContactCorrection = null;

    const $ = (sel, root = document) => root.querySelector(sel);

    function syncLeadStateSnapshot() {
      state.currentStep = Number(state.leadFlow.index || 0);
      state.awaitingLead = state.leadFlow.awaitingLead === true;
      state.leadCaptured = state.leadFlow.completed === true || state.chatState === "lead_captured";
      state.lastIntent = String(state.leadFlow.intent || "").trim();
    }

    function setConversationOwner(owner) {
      state.activeOwner = String(owner || "IDLE").trim() || "IDLE";
    }

    function clearIncompatibleState(owner) {
      const nextOwner = String(owner || "IDLE").trim() || "IDLE";
      if (nextOwner !== "DISAMBIGUATION") state.pendingDisambiguation = null;
      if (nextOwner !== "LEAD_CAPTURE") state.pendingLeadStart = null;
      if (nextOwner !== "LEAD_CAPTURE" && state.leadFlow.active) {
        state.leadFlow.active = false;
        state.leadFlow.completed = false;
        state.leadFlow.awaitingLead = false;
        state.leadFlow.index = 0;
        state.leadFlow.askedRequirement = false;
        state.leadFlow.intent = "";
        state.leadFlow.requirement = "";
      }
      setConversationOwner(nextOwner);
      syncLeadStateSnapshot();
    }

    function getPageUrl() {
      try {
        return String(window.location.href || "");
      } catch {
        return "";
      }
    }

    function getStoredLead() {
      try {
        const raw = localStorage.getItem(storedLeadKey);
        if (!raw) return null;
        const parsed = JSON.parse(raw);
        if (!parsed || typeof parsed !== "object") return null;
        const lead = {
          name: String(parsed.name || "").trim(),
          email: String(parsed.email || "").trim(),
          phone: String(parsed.phone || "").trim(),
        };
        return lead.name || lead.email || lead.phone ? lead : null;
      } catch {
        return null;
      }
    }

    function saveStoredLead(data) {
      // CONTACT_MERGE_PRESERVATION_V1: keep previously stored values when the
      // current flow did not collect them (e.g. "Actualizar datos" on a
      // profile whose fields are name+phone must not drop a known email).
      const previousLead = getStoredLead() || {};
      const nextLead = {
        name: String(data?.name || "").trim() || String(previousLead.name || ""),
        email: String(data?.email || "").trim() || String(previousLead.email || ""),
        phone: String(data?.phone || "").trim() || String(previousLead.phone || ""),
      };
      if (!nextLead.name && !nextLead.email && !nextLead.phone) return;
      try {
        localStorage.setItem(storedLeadKey, JSON.stringify(nextLead));
      } catch {}
    }

    function getLeadIdentifier() {
      const d = state.leadFlow.data || getStoredLead() || {};
      const e = String(d.email || "").trim();
      if (e) return e;
      return String(d.phone || "").trim();
    }

    function logClientEvent(payload) {
      void postChatbotAction(
        "estandar_chatbot_log_client_event",
        {
          interaction_type: payload.interaction_type || "button_click",
          label: payload.label || "",
          payload: payload.payload || "",
          session_id: state.sessionId || "",
          ea_sid: getAnalyticsSessionId(),
          channel: "web",
          page_url: getPageUrl(),
          lead_identifier: getLeadIdentifier(),
        },
        true,
      ).catch(() => {});
    }

    function saveOpenState(open) {
      try {
        localStorage.setItem(storageKey, open ? "1" : "0");
      } catch {}
    }

    function loadOpenState() {
      try {
        return localStorage.getItem(storageKey) === "1";
      } catch {
        return false;
      }
    }

    function getMemory() {
      try {
        const raw = sessionStorage.getItem(memoryKey);
        return raw ? JSON.parse(raw) : [];
      } catch {
        return [];
      }
    }

    function saveMemory(memory) {
      try {
        sessionStorage.setItem(memoryKey, JSON.stringify(memory.slice(-memoryLimit)));
      } catch {}
    }

    function clearMemory() {
      try {
        sessionStorage.removeItem(memoryKey);
      } catch {}
    }

    function getRecentConversationText(limit = 10) {
      return getMemory()
        .slice(-limit)
        .map((item) => {
          const role = item && item.role === "assistant" ? "B" : "U";
          const content = String(item?.content || "").replace(/\s+/g, " ").trim();
          return content ? `${role}: ${content}` : "";
        })
        .filter(Boolean)
        .join("\n");
    }

    function getLastUserMessage() {
      const last = getMemory()
        .slice()
        .reverse()
        .find((item) => item && item.role === "user" && String(item.content || "").trim());
      return last ? String(last.content || "").trim() : "";
    }

    function detectLeadIntentFromConversation(flow) {
      const source = [
        flow?.intent,
        flow?.data?.intent_label,
        flow?.requirement,
        getLastUserMessage(),
        getRecentConversationText(6),
      ]
        .join(" ")
        .toLowerCase()
        .normalize("NFD")
        .replace(/[\u0300-\u036f]/g, "");

      if (/nancagua|casa|departamento|terreno|proyecto|subsidio|financiamiento|visita/.test(source)) {
        return "cotizacion_inmobiliaria";
      }
      if (/renta|declaracion/.test(source)) return "declaracion_renta";
      if (/cambiar de contador|cambio de contador/.test(source)) return "cambio_contador";
      if (/contabilidad|contador/.test(source)) return "contabilidad_mensual";
      if (/tributari|impuesto|sii/.test(source)) return "tributaria";
      if (/remuneracion|payroll|sueldo/.test(source)) return "remuneraciones";
      if (/inicio de actividades|crear empresa|constituir/.test(source)) return "inicio_actividades";
      if (/cotizar|cotizacion|precio|valor|cuanto cuesta/.test(source)) return "cotizacion";
      if (/humano|asesor|ejecutivo|whatsapp|contact/.test(source)) return "contacto_humano";
      return String(flow?.intent || "").trim() || "consulta_general";
    }

    function buildConversationSummary(flow, leadIntent, lastUserMessage, recentConversation = "") {
      const source = [flow?.requirement, lastUserMessage, recentConversation]
        .join(" ")
        .toLowerCase()
        .normalize("NFD")
        .replace(/[\u0300-\u036f]/g, "");
      if (leadIntent === "declaracion_renta") {
        const detail = /persona natural/.test(source) ? " como persona natural" : "";
        const parts = [`Usuario busca ayuda para declaración de renta${detail}.`];
        if (lastUserMessage) parts.push(`Último mensaje: "${lastUserMessage}"`);
        return parts.join(" ");
      }
      if (leadIntent === "cotizacion_inmobiliaria") {
        const parts = [/nancagua/.test(source) ? "Interesado en proyecto Nancagua." : "Usuario busca cotización inmobiliaria."];
        if (/subsidio/.test(source)) parts.push("Consulta subsidio.");
        if (/contact|asesor|whatsapp|humano/.test(source)) parts.push("Solicita contacto.");
        return parts.join(" ");
      }
      const labels = {
        declaracion_renta: "Usuario busca apoyo para declaración de renta.",
        cambio_contador: "Usuario evalúa cambiar de contador.",
        contabilidad_mensual: "Usuario consulta por contabilidad mensual.",
        tributaria: "Usuario solicita orientación tributaria.",
        remuneraciones: "Usuario consulta por remuneraciones o payroll.",
        inicio_actividades: "Usuario consulta por inicio de actividades o creación de empresa.",
        cotizacion: "Usuario quiere cotizar o conocer valores.",
        contacto_humano: "Usuario pidió contacto humano.",
      };
      const parts = [labels[leadIntent] || "Usuario realizó una consulta comercial."];
      if (lastUserMessage) {
        parts.push(`Último mensaje: "${lastUserMessage}"`);
      }
      if (flow?.requirement) {
        parts.push(`Requerimiento: ${String(flow.requirement).trim()}`);
      }
      return parts.join(" ");
    }

    function loadChatState() {
      try {
        return sessionStorage.getItem(chatStateKey) || "default";
      } catch {
        return "default";
      }
    }

    function saveChatState(nextState) {
      state.chatState = nextState || "default";
      syncLeadStateSnapshot();
      try {
        sessionStorage.setItem(chatStateKey, state.chatState);
      } catch {}
    }

    function getRedirectDoneKey() {
      return `${redirectDoneBaseKey}_${state.sessionId || "anon"}`;
    }

    function hasAutoOpened() {
      try {
        return sessionStorage.getItem(autoOpenKey) === "1";
      } catch {
        return false;
      }
    }

    function markAutoOpened() {
      try {
        sessionStorage.setItem(autoOpenKey, "1");
      } catch {}
    }

    function markUserInteracted() {
      userHasInteracted = true;
    }

    function hasRedirectBeenDone() {
      try {
        return sessionStorage.getItem(getRedirectDoneKey()) === "1";
      } catch {
        return false;
      }
    }

    function markRedirectDone() {
      try {
        sessionStorage.setItem(getRedirectDoneKey(), "1");
      } catch {}
    }

    function normalizeIntentText(value) {
      if (typeof decisionEngine.normalizeIntentText === "function") {
        return decisionEngine.normalizeIntentText(value);
      }
      return String(value || "")
        .toLowerCase()
        .normalize("NFD")
        .replace(/[\u0300-\u036f]/g, "")
        .trim();
    }

  function isHotIntentText(value) {
    const normalized = normalizeIntentText(value);
    if (!normalized) return false;
    if (
      typeof decisionEngine.isConsultativeServiceIntent === "function" &&
      decisionEngine.isConsultativeServiceIntent(normalized)
    ) {
      return false;
    }
    return hotLeadIntentPatterns.some((pattern) =>
      normalized.includes(normalizeIntentText(pattern))
    );
  }

  function isHumanContactIntentText(value) {
    const normalized = normalizeIntentText(value);
    if (!normalized) return false;
    return /(whatsapp|contactar|contacten|contacto|llamar|llamen|llame|hablar.*con.*alguien|hablar.*con.*una persona|asesor|humano|agente|ejecutivo|persona)/i.test(normalized);
  }

  function hasPriceIntentText(value) {
    const normalized = normalizeIntentText(value);
    if (!normalized) return false;
    return /(cuanto tengo que pagar|cuanto cuesta|precio|precios|costo|costos|valor|vale|cuanto vale|cotizar|cotizacion|plan|pagar)/i.test(normalized);
  }

  function hasCommercialInfoIntentText(value) {
    const normalized = normalizeIntentText(value);
    if (!normalized) return false;
    return /(subsidio|financiamiento|financiar|credito|crédito|entrega|entregan|disponibilidad|disponible|stock|quedan|ubicacion|ubicación|donde|dónde|diferencia|diferencias)/i.test(normalized);
  }

  function isFreshInterruptIntent(value, classifiedIntent) {
    return (
      classifiedIntent === "price" ||
      classifiedIntent === "contact" ||
      hasPriceIntentText(value) ||
      isHumanContactIntentText(value) ||
      hasCommercialInfoIntentText(value)
    );
  }

  function hasValueFirstQuestionText(value) {
    const normalized = normalizeIntentText(value);
    if (!normalized) return false;
    return (
      /[?¿]/.test(String(value || "")) ||
      /(cuanto|cuánto|precio|precios|costo|costos|valor|vale|disponibilidad|disponible|quedan|diferencia|financiamiento|financiar|credito|crédito|ubicacion|ubicación|donde|dónde|entrega|cuando entregan|cuándo entregan|incluye|caracteristicas|características)/i.test(normalized)
    );
  }

  function shouldUseSmartDefaultFallback() {
    return !hasStrongConfiguredExit && !String(siteName || "").trim();
  }

  function showSmartDefaultFallback(dom, intent) {
    const normalizedIntent = String(intent || "").trim();
    let response = "";

    if (normalizedIntent === "price") {
      response = "Te puedo orientar rápido 👇\n¿Qué necesitas exactamente?";
    } else if (normalizedIntent === "info") {
      response = "Perfecto 👌\n¿Qué te gustaría saber?";
    } else if (normalizedIntent === "contact") {
      response = "Perfecto 👌\n¿Prefieres que te contacten?";
    } else if (normalizedIntent === "high_intent" || normalizedIntent === "high_intent_strong") {
      response = "Vamos directo 👇\n¿Prefieres avanzar ahora o que te contacten?";
    }

    if (!response) return false;
    if (isRepeatingResponseType(`smart_default_${normalizedIntent}`)) {
      response = "Te ayudo con eso 👇\nCuéntame un poco más para orientarte mejor.";
    }

    addMessage(dom, "bot", renderBotText(response));
    setLastResponseType(`smart_default_${normalizedIntent}`);
    return true;
  }

  function evaluateUserState(userInput, chatbotState) {
    const text = String(userInput || "");
    const normalized = normalizeIntentText(text);
    const currentLead =
      chatbotState?.leadFlow?.data && Object.keys(chatbotState.leadFlow.data).length
        ? chatbotState.leadFlow.data
        : null;
    const storedLead = typeof getStoredLead === "function" ? getStoredLead() : null;
    const lead = currentLead || storedLead || null;

    return {
      text,
      normalized,
      hasLead: !!(lead && (lead.name || lead.email || lead.phone)),
      deliveredData:
        /(mi nombre es|me llamo|mi numero es|mi número es|mi telefono es|mi teléfono es|mi correo es|mi email es)/i.test(normalized) ||
        /\b\d{8,}\b/.test(text) ||
        /[^\s@]+@[^\s@]+\.[^\s@]+/.test(text),
      wantsWhatsApp: /whatsapp/.test(normalized),
      hasDoubt: /(no se|no sé|conviene|vale la pena|es mucho|es poco|me da miedo|me preocupa)/.test(normalized),
      asksTax: includesAnyKeyword(normalized, taxQuestionKeywords),
      highIntent: includesAnyKeyword(normalized, highIntentKeywords),
    };
  }

  function detectIntentSignals(value) {
    const text = String(value || "");
    const normalized = normalizeIntentText(text);
    return {
      miedo: /(no se si conviene|no se si vale|es mucho|es poco|no se si me conviene|no se si vale la pena|vale la pena|me da miedo|me preocupa)/i.test(normalized),
      entregaDatos:
        /(mi nombre es|me llamo|mi numero es|mi número es|mi telefono es|mi teléfono es|mi correo es|mi email es)/i.test(normalized) ||
        /\b\d{8,}\b/.test(text) ||
        /[^\s@]+@[^\s@]+\.[^\s@]+/.test(text),
      quiereContacto: /(whatsapp|contactar|contacten|contacto|llamar|llamen|llame|hablar.*con.*alguien|hablar.*con.*una persona|asesor|humano)/i.test(normalized),
      preguntaImpuesto: includesAnyKeyword(normalized, taxQuestionKeywords),
      altaIntencion: includesAnyKeyword(normalized, highIntentKeywords),
    };
  }

    function hasUrlInText(value) {
    return /https?:\/\/[^\s]+/i.test(String(value || ""));
  }

  function extractWebsiteInput(value) {
    if (typeof decisionEngine.extractWebsiteInput === "function") {
      return String(decisionEngine.extractWebsiteInput(value) || "").trim();
    }
    return "";
  }

  function formatWebsiteLabel(value) {
    const websiteUrl = String(value || "").trim();
    if (!websiteUrl) return "";
    try {
      return String(new URL(websiteUrl).hostname || "").replace(/^www\./i, "");
    } catch {
      return websiteUrl.replace(/^https?:\/\//i, "").replace(/^www\./i, "");
    }
  }

  function getBehaviorCopyText(key, fallback, replacements = {}) {
    const configured = String(behaviorCopy?.[key] || "").trim();
    const base = configured || String(fallback || "").trim();
    if (!base) return "";

    return Object.keys(replacements).reduce((message, token) => {
      return message.replaceAll(`{{${token}}}`, String(replacements[token] || "").trim());
    }, base);
  }

  function findMatchingLeadIntent(text, explicitIntentId, explicitIntentLabel) {
    const normalizedText = normalizeIntentText(text);
    const explicitId = normalizeIntentText(explicitIntentId || "");
    const explicitLabel = normalizeIntentText(explicitIntentLabel || "");

    if (explicitId || explicitLabel) {
      return {
        id: String(explicitIntentId || explicitIntentLabel || "").trim(),
        label: String(explicitIntentLabel || text || "").trim(),
      };
    }

    if (!normalizedText) return null;

    const matched = leadIntentButtons.find((item) => {
      const itemLabel = normalizeIntentText(item.label || "");
      const itemValue = normalizeIntentText(item.value || "");
      return normalizedText === itemLabel || normalizedText === itemValue;
    });

    if (!matched) return null;

    return {
      id: String(matched.value || matched.label || "").trim(),
      label: String(matched.label || text || "").trim(),
    };
  }

  function hasDisambiguationOptions() {
    if (Array.isArray(flowCfg.tags) && flowCfg.tags.some((item) => String(item || "").trim())) {
      return true;
    }
    if (Array.isArray(leadIntentButtons) && leadIntentButtons.length > 0) {
      return true;
    }
    return Array.isArray(buttons) && buttons.length > 0;
  }

  function getDisambiguationOptionLabels() {
    const source = Array.isArray(leadIntentButtons) && leadIntentButtons.length
      ? leadIntentButtons
      : Array.isArray(buttons) && buttons.length
        ? buttons
        : [];
    const labels = source
      .map((item) => String(item?.label || "").trim())
      .filter(Boolean);

    if (labels.length) return labels;

    return Array.isArray(flowCfg.tags)
      ? flowCfg.tags.map((item) => String(item || "").trim()).filter(Boolean)
      : [];
  }

  function formatDisambiguationOptions(labels) {
    if (labels.length === 2) return `${labels[0]} o ${labels[1]}`;
    if (labels.length === 3) return `${labels[0]}, ${labels[1]} o ${labels[2]}`;
    return labels[0] || "";
  }

  function getMentionedDisambiguationOption(text) {
    const normalizedText = normalizeIntentText(text);
    if (!normalizedText) return "";
    return (
      getDisambiguationOptionLabels().find((label) => {
        const normalizedLabel = normalizeIntentText(label);
        return normalizedLabel && normalizedText.includes(normalizedLabel);
      }) || ""
    );
  }

  function shouldAnswerBeforeLeadCapture(userText, classifiedIntent) {
    if (!hasValueFirstQuestionText(userText)) return false;
    if (isHumanContactIntentText(userText)) return false;
    if (classifiedIntent === "contact" || classifiedIntent === "high_intent_strong") return false;
    if (!requiresDisambiguation) return true;
    if (String(state.selectedContext || "").trim()) return true;
    return !!getMentionedDisambiguationOption(userText);
  }

  // 2.0.93 Bug C: Detect general informational questions that don't need project/context
  // selection to be answered (e.g. "¿aceptan subsidio?", "¿cómo financian?").
  // These should go straight to AI rather than triggering disambiguation.
  // Criteria: looks like a question AND is not a price quote, contact, or hot conversion action.
  function isContextIndependentQuestion(userText, classifiedIntent) {
    if (!hasValueFirstQuestionText(userText)) return false;
    if (isHumanContactIntentText(userText)) return false;
    if (hasPriceIntentText(userText)) return false;
    if (isHotIntentText(userText)) return false;
    if (classifiedIntent === "high_intent" || classifiedIntent === "high_intent_strong") return false;
    return true;
  }

  function getValueFirstNextStepCTA(userText) {
    const normalized = normalizeIntentText(userText);
    if (/(visita|reservar|reserva|agenda|agendar)/i.test(normalized)) {
      return "¿Quieres agendar visita o revisar disponibilidad?";
    }
    if (/(soporte|problema|error|ayuda|reclamo)/i.test(normalized)) {
      return humanEscalationEnabled
        ? "¿Quieres que sigamos aquí o prefieres escalarlo con soporte?"
        : "¿Quieres que sigamos revisándolo aquí?";
    }
    if (humanEscalationEnabled) {
      return buildHumanEscalationWhatsAppUrl()
        ? "¿Quieres revisar opciones o hablar con un asesor por WhatsApp?"
        : "¿Quieres que revisemos opciones o prefieres que te contacten?";
    }
    return "¿Quieres avanzar ahora o resolver otra duda?";
  }

  function appendValueFirstCTA(answerText, userText) {
    const answer = String(answerText || "").trim();
    if (!answer) return answer;
    if (/(quieres|prefieres|te gustaria|te gustaría|puedo ayudarte|agenda|agendar|whatsapp|contact)/i.test(normalizeIntentText(answer))) {
      return answer;
    }
    return `${answer}\n\n${getValueFirstNextStepCTA(userText)}`;
  }

  function compactHighIntentAnswer(userText, answerText) {
    const answer = String(answerText || "").trim();
    if (!answer) return answer;
    if (!isHotIntentText(userText) || !hasUrlInText(answer)) {
      return answer;
    }

    const urlMatch = answer.match(/https?:\/\/[^\s]+/i);
    const url = urlMatch ? urlMatch[0] : "";
    const normalizedUserText = normalizeIntentText(userText);

    let actionLine = "Puedes avanzar ahora mismo";
    if (normalizedUserText.includes("pagar") || normalizedUserText.includes("pago")) {
      actionLine = "Puedes pagar ahora mismo";
    } else if (normalizedUserText.includes("precio")) {
      actionLine = "Puedes ver los precios ahora mismo";
    } else if (normalizedUserText.includes("cotizar")) {
      actionLine = "Puedes cotizar ahora mismo";
    } else if (normalizedUserText.includes("contratar") || normalizedUserText.includes("avanzar")) {
      actionLine = "Puedes avanzar ahora mismo";
    }

    return [
      "Listo.",
      `${actionLine} 👇`,
      url,
      "Si prefieres, lo vemos contigo paso a paso.",
    ].join("\n");
  }

    function extractLeadFromText(text) {
      const source = String(text || "");
      const extracted = {
        name: "",
        email: "",
        phone: "",
      };

      const emailMatch = source.match(/([^\s@]+@[^\s@]+\.[^\s@]+)/i);
      if (emailMatch) {
        extracted.email = String(emailMatch[1] || "").trim();
      }

      const phoneMatch = source.match(/(\+?\d[\d\s().-]{6,}\d)/);
      if (phoneMatch) {
        extracted.phone = String(phoneMatch[1] || "").replace(/[^\d+]/g, "").trim();
      }

      const normalized = source.replace(/\s+/g, " ").trim();
      const namePatterns = [
	        /nombre\s*:\s*([a-z0-9áéíóúñ]+(?:\s+[a-z0-9áéíóúñ]+){0,3})(?=\s+(?:correo|email|tel[eé]fono|fono|celular|whatsapp)\s*:|$)/i,
	        /mi nombre es\s+([a-z0-9áéíóúñ]+(?:\s+[a-z0-9áéíóúñ]+){0,3})/i,
	        /me llamo\s+([a-z0-9áéíóúñ]+(?:\s+[a-z0-9áéíóúñ]+){0,3})/i,
      ];

      namePatterns.some((pattern) => {
        const match = normalized.match(pattern);
        if (!match) return false;
        extracted.name = String(match[1] || "").trim();
        return !!extracted.name;
      });

      return extracted;
    }

    function hasAnyLeadData(lead) {
      return !!String(lead?.name || lead?.email || lead?.phone || "").trim();
    }

    function normalizeChileanMobilePhone(value) {
      const raw = String(value || "").trim();
      if (!raw || !/^\+?[\d\s().-]+$/.test(raw)) return "";
      let digits = raw.replace(/\D+/g, "");
      if (/^9\d{8}$/.test(digits)) digits = `56${digits}`;
      return /^569\d{8}$/.test(digits) ? digits : "";
    }

    function normalizeContactEmail(value) {
      const email = String(value || "").trim().toLowerCase();
      return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) ? email : "";
    }

    function hasCompleteLeadForFastTrack(lead) {
      const name = String(lead?.name || "").trim();
      const email = String(lead?.email || "").trim();

      if (name && normalizeChileanMobilePhone(lead?.phone)) {
        return true;
      }

      return !!(name && email);
    }

    function setLastResponseType(type) {
      state.lastResponseType = String(type || "").trim();
    }

    function isRepeatingResponseType(type) {
      return state.lastResponseType === String(type || "").trim();
    }

    function hasLeadContactData() {
      const data = state.leadFlow.data || getStoredLead() || {};
      const name = String(data.name || "").trim();
      const phone = String(data.phone || "").trim();
      return !!name && normalizeChileanMobilePhone(phone) !== "";
    }

    function buildHumanEscalationWhatsAppMessage() {
      const data = state.leadFlow.data || {};
      const name = String(data.name || "").trim();
      const phone = String(data.phone || "").trim();
      const intent = String(data.intent_label || data.intent || "").trim();
      let message = humanEscalationWhatsAppMessage || "Hola, quiero hablar con una persona del equipo. Dejé mis datos en la web.";

      message = message
        .replaceAll("{{name}}", name)
        .replaceAll("{{phone}}", phone)
        .replaceAll("{{intent}}", intent);

      return message.replace(/\s{2,}/g, " ").trim();
    }

    function normalizePostLeadActions(raw) {
      if (!Array.isArray(raw)) return [];
      const allowed = new Set(["whatsapp", "deferred", "reset"]);
      return raw
        .map((item) => ({
          action: String(item?.action || "").trim().toLowerCase(),
          label: String(item?.label || "").trim(),
          enabled: item?.enabled === 1 || item?.enabled === "1" || item?.enabled === true,
        }))
        .filter((item) => allowed.has(item.action) && item.enabled && item.label);
    }

	    function buildWhatsAppURL(number, message = "") {
	      let digits = String(number || "").replace(/\D+/g, "");
      digits = digits.replace(/^0+/, "");

      if (digits.length === 9 && digits.startsWith("9")) {
        digits = `56${digits}`;
      }

      if (digits.length < 8 || digits.length > 15) {
        return "";
      }

      const query = String(message || "").trim()
        ? `?text=${encodeURIComponent(String(message).trim())}`
        : "";
	      return `https://wa.me/${digits}${query}`;
	    }

	    function normalizeProjectSlug(value) {
	      return String(value || "")
	        .toLowerCase()
	        .normalize("NFD")
	        .replace(/[\u0300-\u036f]/g, "")
	        .replace(/[^a-z0-9]+/g, "-")
	        .replace(/^-+|-+$/g, "");
	    }

	    function getCurrentPagePath() {
	      try {
	        return String(window.location.pathname || "").toLowerCase().replace(/\/+$/, "");
	      } catch {
	        return "";
	      }
	    }

	    function resolveProjectSlugFromPage() {
	      const path = getCurrentPagePath();
	      if (!path) return "";
	      for (const project of projectRegistry) {
	        const slug = normalizeProjectSlug(project?.project_slug || project?.slug || "");
	        if (!slug) continue;
	        const patterns = Array.isArray(project?.url_patterns) ? project.url_patterns : [];
	        const matchedPattern = patterns.some((pattern) => {
	          const normalizedPattern = String(pattern || "").toLowerCase().replace(/\/+$/, "");
	          return normalizedPattern && path.includes(normalizedPattern);
	        });
	        if (matchedPattern || path.includes(`/${slug}`)) {
	          return slug;
	        }
	      }
	      return "";
	    }

	    function buildHumanEscalationWhatsAppUrl() {
	      // Use server-side redirect endpoint when available — it resolves the correct operator,
	      // generates a SID reference, and records the click for analytics.
	      if (whatsappRedirectUrl) {
	        const params = new URLSearchParams({ source: "chatbot" });
	        const eaSid = getAnalyticsSessionId();
	        if (eaSid) params.set("session_id", eaSid);
	        const projectSlug = resolveProjectSlugFromPage();
	        if (projectSlug) params.set("project_slug", projectSlug);
	        return whatsappRedirectUrl + "?" + params.toString();
	      }
      // Legacy fallback: direct wa.me URL when redirect endpoint is not configured.
      if (!humanEscalationWhatsAppNumber) {
        return "";
      }
      const message = buildHumanEscalationWhatsAppMessage();
      return buildWhatsAppURL(humanEscalationWhatsAppNumber, message);
    }

    function buildWhatsAppCTAHtml(label = "Hablar ahora por WhatsApp") {
      const url = buildHumanEscalationWhatsAppUrl();
      if (!url) return "";
      const isRedirect = url.includes("whatsapp-redirect");
      const target = isRedirect ? "" : ' target="_blank" rel="noopener noreferrer"';
      const onclick = isRedirect ? ` onclick="event.preventDefault();window.location.href=this.href;"` : "";
      return `<div class="ecb-cta"><a href="${url}"${target}${onclick} class="ecb-link-btn ecb-whatsapp-btn" data-ecb-whatsapp-cta="1">${escapeHTML(label)}</a></div>`;
    }

    function registerWhatsAppClick() {
      try {
        console.log("click_whatsapp");
      } catch {}

      try {
        if (typeof window._paq !== "undefined" && Array.isArray(window._paq)) {
          window._paq.push(["trackEvent", "Chatbot", "Click WhatsApp"]);
        }
      } catch {}

      try {
        if (typeof window.gtag !== "undefined") {
          window.gtag("event", "click_whatsapp_chatbot");
        }
      } catch {}

      // Analytics: chatbot_whatsapp_click — señal de intención comercial máxima.
      logClientEvent({ interaction_type: "button_click", label: "chatbot_whatsapp_click" });
    }

    function tryOpenExternalUrl(url) {
      const targetUrl = String(url || "").trim();
      if (!targetUrl) return false;

      // WhatsApp redirect endpoint uses window.location.href for mobile/Safari compatibility.
      if (targetUrl.includes("whatsapp-redirect") || targetUrl.includes("wa.me")) {
        try {
          window.location.href = targetUrl;
          return true;
        } catch {}
      }

      try {
        const opened = window.open(targetUrl, "_blank", "noopener");
        if (opened) return true;
      } catch {}

      try {
        const link = document.createElement("a");
        link.href = targetUrl;
        link.target = "_blank";
        link.rel = "noopener noreferrer";
        link.style.display = "none";
        document.body.appendChild(link);
        link.click();
        window.setTimeout(() => {
          try {
            link.remove();
          } catch {}
        }, 0);
        return true;
      } catch {}

      return false;
    }

    function showPostLeadWhatsAppCTA(dom) {
      const ctaHtml = buildWhatsAppCTAHtml("💬 Hablar por WhatsApp ahora");
      if (!ctaHtml) return;
      addMessage(dom, "bot", ctaHtml);
    }

    function showWhatsAppIntentCTA(dom, introText = "Te dejo el acceso directo por WhatsApp 👇") {
      const ctaHtml = buildWhatsAppCTAHtml("Abrir WhatsApp");
      if (!ctaHtml) return false;
      const message = isRepeatingResponseType("whatsapp_cta")
        ? "Aquí lo tienes otra vez 👇"
        : introText;
      addMessage(dom, "bot", `${renderBotText(message)}<br><br>${ctaHtml}`);
      setLastResponseType("whatsapp_cta");
      return true;
    }

    function showDirectIntentCTA(dom) {
      if (hotLeadUrl) {
        addMessage(
          dom,
          "bot",
          `Perfecto.<br><br><div class="ecb-cta"><a href="${escapeHTML(hotLeadUrl)}" target="_blank" rel="noopener noreferrer" class="ecb-link-btn">Contratar ahora</a></div>`
        );
        setLastResponseType("direct_cta");
        return true;
      }

      if (buildHumanEscalationWhatsAppUrl()) {
        if (state.leadCaptured || state.chatState === "lead_captured") {
          return openHumanEscalationWhatsApp(dom, "decision_engine_direct_hot");
        }
        return showWhatsAppIntentCTA(dom, "Perfecto. Te abro WhatsApp para avanzar ahora mismo 👇");
      }

      if (state.leadCaptured || state.chatState === "lead_captured") {
        return showPostLeadBlock(dom, {
          message: postLeadHotMessage,
          showMessage: true,
          forceWhatsAppCTA: true,
          overrideButtons: [
            { label: "Quiero que me contacten", type: "post_lead_deferred", value: "deferred" },
          ],
        });
      }

      if (state.leadFlow.enabled && !state.leadFlow.completed) {
        iniciarCapturaLead(dom, {
          intentId: "contratar",
          data: { intent: "contratar", intent_label: "Contratar" },
          transitionMessage: highIntentTransitionMessage,
        });
        setLastResponseType("direct_cta");
        return true;
      }

      return false;
    }

    function showPriceCTA(dom, options = {}) {
      if (requiresDisambiguation && !String(state.selectedContext || "").trim()) {
        state.pendingDisambiguation = {
          action: "cta_price",
          context: Object.assign({}, options || {}),
        };
        addMessage(dom, "bot", renderBotText(getDisambiguationQuestion()));
        setLastResponseType(options.responseType || "price_disambiguation");
        return true;
      }

      if (hotLeadUrl) {
        addMessage(
          dom,
          "bot",
          `${renderBotText(priceCtaMessage)}<br><br><div class="ecb-cta"><a href="${escapeHTML(hotLeadUrl)}" target="_blank" rel="noopener noreferrer" class="ecb-link-btn">Ver precios</a></div>`
        );
        setLastResponseType(options.responseType || "price_cta");
        return true;
      }

      // 2.0.94 Bug 2: The 2.0.93 Bug A fix removed the humanEscalationMode check from
      // buildHumanEscalationWhatsAppUrl(), so that function now returns a URL for any install
      // that has a WA number configured regardless of mode. Using it here unconditionally caused
      // ALL price queries to escalate to WhatsApp even when admin chose "lead_form" mode.
      // Guard: only use WhatsApp for price CTA when admin explicitly set mode="whatsapp".
      // Quote intent ≠ human escalation intent — separate these paths cleanly.
      if (humanEscalationMode === "whatsapp" && buildHumanEscalationWhatsAppUrl()) {
        addMessage(
          dom,
          "bot",
          `${renderBotText(priceWhatsAppMessage)}<br><br>${buildWhatsAppCTAHtml("Abrir WhatsApp")}`
        );
        setLastResponseType(options.responseType || "price_cta");
        return true;
      }

      if (state.leadFlow.enabled && !state.leadFlow.completed) {
        iniciarCapturaLead(dom, {
          intentId: "precio",
          data: { intent: "precio", intent_label: "Ver precios" },
          transitionMessage: priceTransitionMessage,
        });
        setLastResponseType(options.responseType || "price_cta");
        return true;
      }

      return false;
    }

    function showWebsiteInputIntro(dom, websiteUrl) {
      const safeUrl = String(websiteUrl || "").trim();
      if (!safeUrl) return false;

      state.userWebsite = safeUrl;
      state.lastUserIntent = "website_input";
      state.lastAction = "website_input";
      state.conversationClosed = false;

      const websiteLabel = formatWebsiteLabel(safeUrl);
      const lines = getBehaviorCopyText(
        "website_input_message",
        "Perfecto 👌\n\nYa vi tu web: {{website}}.\n\nVeamos cómo funcionaría ahí 👇",
        { website: websiteLabel || safeUrl }
      );

      addMessage(dom, "bot", renderBotText(lines));
      setLastResponseType("website_input");
      return true;
    }

    function getPostLeadButtons() {
      return normalizePostLeadActions(postLeadActions)
        .map((item) => {
          if (item.action === "whatsapp") {
            const url = buildHumanEscalationWhatsAppUrl();
            if (!url) return null;
            return { label: item.label, type: "post_lead_whatsapp", value: url };
          }
          if (item.action === "deferred") {
            return { label: item.label, type: "post_lead_deferred", value: "deferred" };
          }
          if (item.action === "reset") {
            return { label: item.label, type: "post_lead_reset", value: "reset" };
          }
          return null;
        })
        .filter(Boolean);
    }

    function renderPostLeadButtons(dom, buttonsOverride) {
      const buttons = Array.isArray(buttonsOverride) ? buttonsOverride : getPostLeadButtons();
      renderActionButtons(dom.actions, buttons);
    }

    function promptStoredLeadChoice(dom, config) {
      state.pendingLeadStart = Object.assign({}, config);
      addMessage(
        dom,
        "bot",
        renderBotText("Ya tengo tus datos de antes 👌\n\n¿Quieres continuar o prefieres actualizarlos?")
      );
      renderActionButtons(dom.actions, [
        { label: "Continuar", type: "lead_continue_existing", value: "continue" },
        { label: "Actualizar datos", type: "lead_refresh_existing", value: "refresh" },
      ]);
    }

    function fallbackToSafeIntent(dom) {
      addMessage(dom, "bot", renderBotText("Perfecto 👌\n\nDime qué necesitas y te ayudo a avanzar."));
      setLastResponseType("safe_fallback");
      return true;
    }

    function showPostLeadBlock(dom, options = {}) {
      const message = typeof options.message === "string" ? options.message : postLeadMessage;
      const messageHtml = renderBotText(message);
      const buttonsOverride = Array.isArray(options.overrideButtons) ? options.overrideButtons : null;
      const shouldAppendWhatsApp =
        buildHumanEscalationWhatsAppUrl() &&
        (/whatsapp/i.test(message) || options.forceWhatsAppCTA === true);

      if (options.showMessage !== false) {
        addMessage(
          dom,
          "bot",
          shouldAppendWhatsApp ? `${messageHtml}<br><br>${buildWhatsAppCTAHtml()}` : messageHtml
        );
      }
      renderPostLeadButtons(dom, buttonsOverride);
      state.lastAction = "postLead";
      state.conversationClosed = false;
      setLastResponseType("post_lead");
      saveChatState("lead_captured");
      return true;
    }

    function showConversionIntro(dom) {
      addMessage(
        dom,
        "bot",
        renderBotText(leadIntroText)
      );
      renderLeadIntentButtons(dom.actions);
    }

    function showLeadDeliveredMessage(dom) {
      const lines = [
        "Perfecto 👌",
        "",
        "Ya tengo tus datos.",
        "Si quieres avanzar ahora mismo, te dejo el siguiente paso aquí abajo.",
      ].join("\n");

      state.leadFlow.active = false;
      state.leadFlow.completed = true;
      state.leadFlow.awaitingLead = false;
      state.pendingLeadStart = null;
      syncLeadStateSnapshot();
      saveChatState("lead_captured");
      setHeaderMeta(dom, "Lead listo");
      updateProgressBar(dom);
      updateInputPlaceholder(dom);
      updateHumanButtonVisibility(dom);
      addMessage(dom, "bot", renderBotText(lines));
      renderPostLeadButtons(dom);
      if (!getPostLeadButtons().length && buildHumanEscalationWhatsAppUrl()) {
        showPostLeadWhatsAppCTA(dom);
      }
      setLastResponseType("lead_complete");
      return true;
    }

    function showFearResponse(dom) {
      const ctaHtml = hotLeadUrl
        ? `<div class="ecb-cta"><a href="${escapeHTML(hotLeadUrl)}" target="_blank" rel="noopener noreferrer" class="ecb-link-btn">Ver cómo avanzar</a></div>`
        : buildWhatsAppCTAHtml();
      const baseMessage = objectionMessage;
      const message = isRepeatingResponseType("fear_response")
        ? objectionRepeatMessage
        : baseMessage;
      addMessage(
        dom,
        "bot",
        ctaHtml ? `${renderBotText(message)}<br><br>${ctaHtml}` : renderBotText(message)
      );
      setLastResponseType("fear_response");
      return true;
    }

    function showTaxClarification(dom) {
      const ctaHtml = hotLeadUrl
        ? `<div class="ecb-cta"><a href="${escapeHTML(hotLeadUrl)}" target="_blank" rel="noopener noreferrer" class="ecb-link-btn">${escapeHTML(hotLeadLabel)}</a></div>`
        : buildWhatsAppCTAHtml();
      const message = taxCtaMessage;
      addMessage(
        dom,
        "bot",
        ctaHtml ? `${renderBotText(message)}<br><br>${ctaHtml}` : renderBotText(message)
      );
      setLastResponseType("tax_cta");
      return true;
    }

    function isCommercialDecisionAction(action) {
      return ["cta_direct"].includes(String(action || ""));
    }

    function getDisambiguationQuestion() {
      const contextOptions = getDisambiguationOptionLabels();

      if (contextOptions.length >= 2 && contextOptions.length <= 3) {
        return `Perfecto 👌 ¿Te interesa ${formatDisambiguationOptions(contextOptions)}?`;
      }

      if (contextOptions.length === 1) {
        return `Perfecto 👌 ¿Te interesa ${contextOptions[0]} u otra opción?`;
      }

      if (contextOptions.length > 3) {
        return "Perfecto 👌 ¿Cuál de estas opciones te interesa?";
      }

      return "Perfecto 👌 ¿Qué opción te interesa?";
    }

    function shouldAskForDisambiguation(action) {
      if (!requiresDisambiguation) return false;
      if (!isCommercialDecisionAction(action)) return false;
      if (String(state.selectedContext || "").trim()) return false;
      if (state.pendingDisambiguation) return false;
      return true;
    }

    function askForDisambiguation(dom, action, context) {
      clearIncompatibleState("DISAMBIGUATION");
      state.pendingDisambiguation = {
        action: String(action || ""),
        context: Object.assign({}, context || {}),
      };
      state.conversationClosed = false;
      renderActionButtons(dom.actions, []);
      addMessage(dom, "bot", renderBotText(getDisambiguationQuestion()));
      setLastResponseType("disambiguation");
      return true;
    }

    async function continueAfterDisambiguation(dom, userText) {
      const answer = String(userText || "").trim();
      if (!answer || !state.pendingDisambiguation) return false;

      // 2.0.95: Fresh intent override — if the user sends a new recognizable intent
      // instead of answering the disambiguation question, do NOT treat it as a context
      // selection. Clear pendingDisambiguation and return false so handleUserMessage
      // can reroute fresh. Without this, "kiero cotisar" typed a second time gets
      // stored as state.selectedContext = "kiero cotisar" (a price keyword used as project
      // context), which poisons all downstream routing for the rest of the session.
      const answerIsKnownContext = !!getMentionedDisambiguationOption(answer);
      const answerIsFreshIntent =
        isFreshInterruptIntent(
          answer,
          typeof decisionEngine.classifyIntent === "function" ? decisionEngine.classifyIntent(answer) : null
        ) ||
        (typeof decisionEngine.classifyIntent === "function" &&
          ["price", "contact", "info"].includes(decisionEngine.classifyIntent(answer) || ""));

      if (answerIsFreshIntent && !answerIsKnownContext) {
        // Discard stale disambiguation state; fall through to fresh routing.
        state.pendingDisambiguation = null;
        return false;
      }

      const pending = state.pendingDisambiguation;
      state.selectedContext = answer;
      state.pendingDisambiguation = null;
      setConversationOwner("IDLE");
      setLastResponseType("");
      return executeDecisionAction(dom, pending.action, Object.assign({}, pending.context || {}, {
        selectedContext: answer,
      }));
    }

    function shouldUseContextualAI(userText) {
      const text = String(userText || "").trim();
      const normalized = normalizeIntentText(text);
      if (!text) return false;

      const hasNumber = /\b\d{2,}\b/.test(text);
      const hasSpecificType =
        /(planta|plantas|cabernet|merlot|santa cruz|proyecto|hectarea|hectárea|equipo|gps|gnss|casa|terreno|departamento|parcela|servicio)/i.test(
          normalized
        );
      const hasMultipleClauses = /\b(para|con|en|de|pero|primero)\b/i.test(normalized);

      return text.length >= 45 || (hasNumber && (hasSpecificType || hasMultipleClauses));
    }

    async function executeDecisionAction(dom, action, context) {
      const userText = String(context?.userText || "").trim();
      const extractedLead = context?.extractedLead || {};
      const mergedLead = Object.assign(
        {},
        getStoredLead() || {},
        state.leadFlow.data || {},
        extractedLead
      );

      if (shouldAskForDisambiguation(action)) {
        return askForDisambiguation(dom, action, context);
      }

      switch (action) {
        case "lead_complete":
          state.leadFlow.active = true;
          state.leadFlow.completed = false;
          state.leadFlow.awaitingLead = false;
          state.leadFlow.index = Array.isArray(state.leadFlow.fields) ? state.leadFlow.fields.length : 0;
          state.leadFlow.intent = String(state.intent || context?.signals?.lastIntent || "contact").trim();
          state.leadFlow.requirement = "";
          state.leadFlow.data = Object.assign({}, mergedLead, {
            intent: state.leadFlow.intent,
            intent_label: state.leadFlow.intent || "contact",
          });
          syncLeadStateSnapshot();
          await submitLeadFlow(dom);
          return true;

        case "lead_store":
          saveStoredLead(mergedLead);
          return false;

        case "website_input":
          return showWebsiteInputIntro(dom, context?.websiteUrl || "");

        case "whatsapp":
          state.lastAction = "whatsapp";
          return openHumanEscalationWhatsApp(dom, "decision_engine_whatsapp");

        case "direct_close_with_optional_context":
          state.lastAction = "direct_close_with_optional_context";
          return showDirectIntentCTA(dom);

        case "cta_direct":
          if (isRepeatingResponseType("direct_cta") || state.lastAction === "direct_cta") {
            if (state.leadCaptured && buildHumanEscalationWhatsAppUrl()) {
              return openHumanEscalationWhatsApp(dom, "decision_engine_repeat_hot");
            }
            return true;
          }
          state.lastAction = "direct_cta";
          return showDirectIntentCTA(dom);

        case "cta_tax":
          state.lastAction = action;
          return showTaxClarification(dom);

        case "cta_price":
          state.lastAction = action;
          return showPriceCTA(dom, { responseType: "price_cta" });

        case "handle_objection":
          state.lastAction = "handle_objection";
          return showFearResponse(dom);

        case "post_lead":
          if (state.lastAction === "postLead" || isRepeatingResponseType("post_lead")) {
            renderPostLeadButtons(dom);
            return !!dom?.actions;
          }
          return showPostLeadBlock(dom);

        default:
          return false;
      }
    }

    async function answerBeforeLeadCapture(dom, userText) {
      const mentionedContext = getMentionedDisambiguationOption(userText);
      if (mentionedContext) {
        state.selectedContext = mentionedContext;
      }
      if (state.leadFlow.active) {
        cancelLeadFlowForConversation(dom);
      }
      state.lastUserMessage = userText;
      state.retryCount = 0;
      await askAI(dom, userText, { appendNextStepCta: true });
      return true;
    }

    async function handleSmartUserIntent(dom, text) {
      const userText = String(text || "").trim();
      if (!userText) return false;

      const intentSignals = evaluateUserState(userText, state);
      const extractedLead = extractLeadFromText(userText);
      const websiteUrl = extractWebsiteInput(userText);
      const classifiedIntent =
        typeof decisionEngine.classifyIntent === "function"
          ? decisionEngine.classifyIntent(userText)
          : null;

      if (classifiedIntent === "website_input" && !enableWebsiteDemo) {
        return false;
      }

      if (shouldAnswerBeforeLeadCapture(userText, classifiedIntent)) {
        return answerBeforeLeadCapture(dom, userText);
      }

      if (
        requiresDisambiguation &&
        !String(state.selectedContext || "").trim() &&
        !state.pendingDisambiguation &&
        hasDisambiguationOptions() &&
        (classifiedIntent === "price" || hasPriceIntentText(userText))
      ) {
        return askForDisambiguation(dom, "cta_price", {
          userText,
          responseType: "price_disambiguation",
        });
      }

      if (shouldUseSmartDefaultFallback() && showSmartDefaultFallback(dom, classifiedIntent)) {
        return true;
      }

      state.intent = classifiedIntent;
      if (classifiedIntent) {
        state.lastUserIntent = String(classifiedIntent).trim();
      }

      const action =
        typeof decisionEngine.resolveDecision === "function"
          ? decisionEngine.resolveDecision({
              intent: classifiedIntent,
              state,
              signals: {
                deliveredData: intentSignals.deliveredData,
                hasLead: intentSignals.hasLead,
                hasWhatsAppCta: !!buildHumanEscalationWhatsAppUrl(),
                hasAnyLead: hasAnyLeadData(extractedLead),
                hasCompleteLead: hasCompleteLeadForFastTrack(extractedLead),
                isTaxQuestion: intentSignals.asksTax,
                enableWebsiteDemo,
                useContextualAI: shouldUseContextualAI(userText),
              },
            })
          : "fallback_ia";

      return executeDecisionAction(dom, action, {
        userText,
        signals: intentSignals,
        extractedLead,
        websiteUrl,
      });
    }

    function resumeStoredLeadIntent(dom, intentId, intentLabel, pendingStart) {
      try {
        const normalizedIntent = normalizeIntentText(intentId || intentLabel || state.lastUserIntent || "");
        if (!normalizedIntent) {
          console.error("resume_intent failed: missing intent", state);
          return fallbackToSafeIntent(dom);
        }

        state.lastUserIntent = normalizedIntent;
        state.lastAction = "resume_intent";
        state.conversationClosed = false;
        renderActionButtons(dom.actions, []);

        if (normalizedIntent === "website_input" && enableWebsiteDemo && state.userWebsite) {
          return showWebsiteInputIntro(dom, state.userWebsite);
        }

        if (normalizedIntent.includes("contact") || normalizedIntent.includes("humano") || normalizedIntent.includes("asesor")) {
          return openHumanEscalationWhatsApp(dom, "resume_stored_lead_contact");
        }

        if (
          normalizedIntent.includes("precio") ||
          normalizedIntent.includes("cotizar") ||
          normalizedIntent.includes("ventas")
        ) {
          return showPriceCTA(dom, { responseType: "resume_price_cta" });
        }

        if (normalizedIntent.includes("contratar") || normalizedIntent.includes("avanzar") || normalizedIntent.includes("buy")) {
          return showDirectIntentCTA(dom);
        }

        const resumePrompt = String(
          pendingStart?.data?.intent_label ||
          intentLabel ||
          pendingStart?.requirement ||
          state.lastUserMessage ||
          ""
        ).trim();

        if (resumePrompt) {
          state.lastUserMessage = resumePrompt;
          state.retryCount = 0;
          askAI(dom, resumePrompt).catch((error) => {
            console.error("resume_intent failed", error, state);
            fallbackToSafeIntent(dom);
          });
          return true;
        }
      } catch (error) {
        console.error("resume_intent failed", error, state);
        return fallbackToSafeIntent(dom);
      }

      return fallbackToSafeIntent(dom);
    }

    function openHumanEscalationWhatsApp(dom, trigger) {
      const url = buildHumanEscalationWhatsAppUrl();
      if (!url) return false;
      const ctaHtml = buildWhatsAppCTAHtml("Abrir WhatsApp");

      addMessage(
        dom,
        "bot",
        `${renderBotText("Perfecto. Te dejo el acceso directo por WhatsApp 👇")}<br><br>${ctaHtml}`
      );

      logClientEvent({
        interaction_type: "human_whatsapp_redirect",
        label: "Human escalation via WhatsApp",
        payload: `${trigger || "unknown"}|${humanEscalationWhatsAppNumber}`,
      });

      tryOpenExternalUrl(url);

      return true;
    }

    function getUrgentCloseMessage(hasContactData) {
      const ctaHtml = hotLeadUrl
        ? `<a class="ecb-link-btn" href="${hotLeadUrl}" target="_blank" rel="noopener noreferrer">👉 ${escapeHTML(hotLeadLabel)}</a>`
        : buildWhatsAppCTAHtml();

      if (ctaHtml) {
        return `Listo.<br><br>Puedes avanzar ahora mismo 👇<br><br>${ctaHtml}`;
      }

      if (hasContactData) {
        return `Listo.<br><br>${contactTeamText.charAt(0).toUpperCase() + contactTeamText.slice(1)} te va a contactar para seguir contigo.`;
      }

      return "Listo.<br><br>Cuéntame qué necesitas y te ayudo a avanzar.";
    }

    function openHotLeadRedirect(dom, trigger, options = {}) {
      const hasContactData = options.hasContactData === true;
      const autoOpen = options.autoOpen !== false;
      const messageHtml = options.messageHtml || getUrgentCloseMessage(hasContactData);
      if (autoOpen && hasRedirectBeenDone()) return false;
      if (autoOpen) {
        markRedirectDone();
      }
      addMessage(
        dom,
        "bot",
        messageHtml
      );
      logClientEvent({
        interaction_type: "hot_lead_redirect",
        label: "Hot lead redirect",
          payload: `${trigger || "unknown"}|${hotLeadUrl || "no_link"}`,
      });
      if (autoOpen && hotLeadUrl) {
        window.setTimeout(() => {
          try {
            window.open(hotLeadUrl, "_blank", "noopener");
          } catch {}
        }, 1000);
      }
      return true;
    }

    function triggerUrgentClose(dom, trigger) {
      if (hasLeadContactData()) {
        state.leadFlow.active = false;
        state.leadFlow.completed = true;
        state.leadFlow.awaitingLead = false;
        syncLeadStateSnapshot();
        setHeaderMeta(dom, "Lead listo · HOT");
        updateProgressBar(dom);
        updateInputPlaceholder(dom);
        updateHumanButtonVisibility(dom);
        renderConfiguredButtons(dom.actions);
        return openHotLeadRedirect(dom, trigger, { hasContactData: true, autoOpen: true });
      }
      return openHotLeadRedirect(dom, trigger, { hasContactData: false, autoOpen: false });
    }

    function getSessionId() {
      try {
        let existing = localStorage.getItem(sessionKey);
        if (!existing) {
          existing = createSessionId();
          localStorage.setItem(sessionKey, existing);
        }
        return existing;
      } catch {
        return createSessionId();
      }
    }

    function rotateSessionId() {
      const next = createSessionId();
      try {
        localStorage.setItem(sessionKey, next);
      } catch {}
      state.sessionId = next;
      return next;
    }

    function applyThemeVars(root) {
      const accent = String(theme.accent || "").trim();
      const visualStyle = String(theme.visualStyle || "default").trim().toLowerCase();
      const shadowEnabled = theme.shadowEnabled !== false && theme.shadowEnabled !== "0";
      const userBubble = String(theme.userBubble || "").trim();
      const botBubble = String(theme.botBubble || "").trim();
      const radius = Number(theme.radius || 22);
      const bubbleSize = Number(theme.bubbleSize || 58);
      const bubbleShape = String(theme.bubbleShape || "rounded").toLowerCase();
      const preset = getVisualPreset(visualStyle);
      const brandingBg = String(branding.bg_color || "").trim();
      const brandingBotBubble = String(branding.bot_bubble_color || "").trim();
      const brandingUserBubble = String(branding.user_bubble_color || "").trim();
      const brandingButton = String(branding.button_color || "").trim();
      const brandingText = String(branding.text_color || "").trim();
      const brandingBgImage = String(branding.background_image || "").trim();
      const brandingShadowEnabled = branding.shadow_enabled !== false && branding.shadow_enabled !== "0" && branding.shadow_enabled !== 0;
      const brandingShadowIntensity = Math.max(0, Math.min(24, Number(branding.shadow_intensity || 6) || 6));

      if (preset.bg) root.style.setProperty("--ecb-bg", preset.bg);
      if (preset.bgSoft) root.style.setProperty("--ecb-bg-soft", preset.bgSoft);
      if (preset.text) root.style.setProperty("--ecb-text", preset.text);
      if (preset.muted) root.style.setProperty("--ecb-muted", preset.muted);
      if (preset.border) root.style.setProperty("--ecb-border", preset.border);
      if (preset.bg) root.style.setProperty("--ecb-panel-bg", preset.bg);
      if (preset.userBubble) root.style.setProperty("--ecb-user-bubble", preset.userBubble);
      if (preset.botBubble) root.style.setProperty("--ecb-bot-bubble", preset.botBubble);
      if (preset.radius) root.style.setProperty("--ecb-bubble-radius", preset.radius);
      root.style.setProperty("--ecb-shadow", shadowEnabled ? (preset.shadow || "0 4px 12px rgba(0,0,0,0.06)") : "none");
      root.style.setProperty("--ecb-shadow-strong", shadowEnabled ? (preset.shadowStrong || preset.shadow || "0 8px 20px rgba(0,0,0,0.08)") : "none");

      if (/^#[0-9a-f]{3,8}$/i.test(accent)) {
        root.style.setProperty("--ecb-accent", accent);
      } else if (preset.primary) {
        root.style.setProperty("--ecb-accent", preset.primary);
      }
      if (/^#[0-9a-f]{3,8}$/i.test(userBubble)) {
        root.style.setProperty("--ecb-user-bubble", userBubble);
      }
      if (/^#[0-9a-f]{3,8}$/i.test(botBubble)) {
        root.style.setProperty("--ecb-bot-bubble", botBubble);
      }
      if (/^#[0-9a-f]{3,8}$/i.test(brandingBg)) {
        root.style.setProperty("--ecb-bg", brandingBg);
        root.style.setProperty("--ecb-panel-bg", brandingBg);
      }
      if (/^#[0-9a-f]{3,8}$/i.test(brandingBotBubble)) {
        root.style.setProperty("--ecb-bot-bubble", brandingBotBubble);
      }
      if (/^#[0-9a-f]{3,8}$/i.test(brandingUserBubble)) {
        root.style.setProperty("--ecb-user-bubble", brandingUserBubble);
      }
      if (/^#[0-9a-f]{3,8}$/i.test(brandingButton)) {
        root.style.setProperty("--ecb-accent", brandingButton);
      }
      if (/^#[0-9a-f]{3,8}$/i.test(brandingText)) {
        root.style.setProperty("--ecb-text", brandingText);
      }
      if (isValidUrl(brandingBgImage)) {
        root.style.setProperty("--ecb-bg-image", `url("${brandingBgImage.replaceAll('"', "%22")}")`);
      } else {
        root.style.setProperty("--ecb-bg-image", "none");
      }
      if (shadowEnabled && brandingShadowEnabled) {
        root.style.setProperty("--ecb-shadow", `0 4px ${brandingShadowIntensity + 6}px rgba(0,0,0,0.06)`);
        root.style.setProperty("--ecb-shadow-strong", `0 4px ${brandingShadowIntensity + 10}px rgba(0,0,0,0.12)`);
      } else if (!brandingShadowEnabled) {
        root.style.setProperty("--ecb-shadow", "none");
        root.style.setProperty("--ecb-shadow-strong", "none");
      }
      if (Number.isFinite(radius)) {
        const rr = Math.max(12, Math.min(28, radius));
        root.style.setProperty("--ecb-radius", `${rr}px`);

        // Forma SOLO del botón flotante (la burbuja de mensajes queda rectangular
        // para evitar que el contenido se vea "roto"/demasiado redondeado).
        if (bubbleShape === "square") {
          root.style.setProperty("--ecb-float-radius", "0px");
        } else if (bubbleShape === "rounded" || bubbleShape === "circle") {
          root.style.setProperty("--ecb-float-radius", "9999px");
        } else {
          root.style.setProperty("--ecb-float-radius", `${rr}px`);
        }
      }
      if (Number.isFinite(bubbleSize)) {
        root.style.setProperty(
          "--ecb-float-size",
          `${Math.max(40, Math.min(88, bubbleSize))}px`
        );
      }
    }

    function getFloatingPositionConfig() {
      const legacyMode = String(theme.position || "right").toLowerCase();
      const rawMode = String(bubblePosition.modo || legacyMode || "right").toLowerCase();
      const mode = ["right", "left", "custom"].includes(rawMode) ? rawMode : "right";
      const bottomRaw = Number(bubblePosition.bottom);
      const bottom = Number.isFinite(bottomRaw) ? Math.max(0, bottomRaw) : 22;
      const leftRaw = String(bubblePosition.left ?? "").trim();
      const rightRaw = String(bubblePosition.right ?? "").trim();
      const left = leftRaw === "" ? null : Math.max(0, Number(leftRaw) || 0);
      const right = rightRaw === "" ? null : Math.max(0, Number(rightRaw) || 0);
      return { mode, bottom, left, right };
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
      if (inlineMode) return;

      const bubbleSize = Math.max(40, Math.min(88, Number(theme.bubbleSize || 58) || 58));
      const panelGap = 12;
      const cfgPos = getFloatingPositionConfig();
      const anchor =
        cfgPos.mode === "left" ? "left" : cfgPos.mode === "custom" && cfgPos.left !== null ? "left" : "right";
      const sideValue =
        anchor === "left" ? (cfgPos.left !== null ? cfgPos.left : 22) : (cfgPos.right !== null ? cfgPos.right : 22);
      const stackOffset = getFloatingStackOffset(anchor);
      const floatBottom = cfgPos.bottom + stackOffset;
      const panelBottom = floatBottom + bubbleSize + panelGap;

      dom.root.dataset.position = anchor;

      dom.floatBtn.style.bottom = `${floatBottom}px`;
      dom.panel.style.bottom = `${panelBottom}px`;

      if (anchor === "left") {
        dom.floatBtn.style.left = `${sideValue}px`;
        dom.floatBtn.style.right = "auto";
        dom.panel.style.left = `${sideValue}px`;
        dom.panel.style.right = "auto";
      } else {
        dom.floatBtn.style.right = `${sideValue}px`;
        dom.floatBtn.style.left = "auto";
        dom.panel.style.right = `${sideValue}px`;
        dom.panel.style.left = "auto";
      }
    }

    function buildBaseDOM() {
      const host = document.createElement("div");
      host.id = index === 0 ? "estandar-chatbot-host" : `estandar-chatbot-host-${instanceKey}`;
      host.dataset.ecbInstanceKey = instanceKey;

      if (mount) {
        if (inlineFullpageEnabled) {
          mount.style.display = "block";
          mount.style.width = "100vw";
          mount.style.maxWidth = "100vw";
          mount.style.marginLeft = "calc(50% - 50vw)";
          mount.style.marginRight = "calc(50% - 50vw)";
          mount.style.paddingLeft = "0";
          mount.style.paddingRight = "0";
        }
        mount.innerHTML = "";
        mount.appendChild(host);
        mount.dataset.ecbMounted = "1";
      } else {
        document.body.appendChild(host);
      }

      const root = host.attachShadow ? host.attachShadow({ mode: "open" }) : host;

      if (cssUrl) {
        const link = document.createElement("link");
        link.rel = "stylesheet";
        link.href = cssUrl;
        root.appendChild(link);
      }

      const wrap = document.createElement("div");
      wrap.id = "estandar-chatbot";
      if (inlineMode) {
        wrap.classList.add("ecb-inline", "ecb-inline-landing");
        if (Number.isFinite(inlineHeight)) {
          wrap.style.setProperty(
            "--ecb-inline-height",
            `${Math.max(420, Math.min(1100, inlineHeight))}px`
          );
        }
      }
      if (inlineFullpageEnabled) {
        document.body.classList.add("page-chatbot");
      }
      if (inlineSidebarEnabled) wrap.classList.add("ecb-inline-with-sidebar");
      if (inlineFixedComposerEnabled) wrap.classList.add("ecb-inline-fixed-composer");
      if (inlineFullpageEnabled) wrap.classList.add("ecb-inline-fullpage");
      applyThemeVars(wrap);

      wrap.innerHTML = `
        <div class="ecb-float" id="ecbFloat" title="${escapeHTML(bubbleTooltip)}">
          <span class="ecb-float__icon" id="ecbFloatIcon">💬</span>
          <span class="ecb-float__badge" id="ecbBadge" hidden></span>
        </div>
        <div class="ecb-panel" id="ecbPanel">
          <div class="ecb-header">
            <div class="ecb-header__body">
              <div class="ecb-header__title">${escapeHTML(botName)}</div>
              <div class="ecb-header__meta" id="ecbHeaderMeta">Listo para conversar</div>
            </div>
            <button class="ecb-header__close" id="ecbClose">×</button>
          </div>
          <div class="ecb-messages" id="ecbMessages"></div>
          <div class="ecb-actions" id="ecbActions"></div>
          <div class="ecb-progress-bar" id="ecbProgressBar" hidden role="progressbar"></div>
          <div class="ecb-input">
            <input id="ecbInput" type="text" placeholder="Escribe tu mensaje…" />
            <button id="ecbSend">Enviar</button>
            <div class="ecb-input__human-wrap" id="ecbHumanWrap" style="display:none;">
              <button type="button" class="ecb-human-btn" id="ecbHumanBtn">👤 Hablar con humano</button>
            </div>
          </div>
        </div>
      `;

      root.appendChild(wrap);

      if (inlineSidebarEnabled) {
        const sidebar = document.createElement("aside");
        sidebar.className = "ecb-inline-sidebar";
        sidebar.innerHTML = `
          <div class="ecb-inline-sidebar__title">${escapeHTML(botName)}</div>
          <button type="button" class="ecb-inline-sidebar__btn" id="ecbInlineNewChat">+ Nuevo chat</button>
          <button type="button" class="ecb-inline-sidebar__btn" id="ecbInlineShowMenu">Mostrar opciones</button>
          <div class="ecb-inline-sidebar__hint">Usa el flujo guiado para captar y derivar leads más rápido.</div>
        `;
        wrap.insertBefore(sidebar, $("#ecbPanel", wrap));
      }

      return {
        host,
        shadowRoot: root,
        root: wrap,
        floatBtn: $("#ecbFloat", wrap),
        floatIcon: $("#ecbFloatIcon", wrap),
        badge: $("#ecbBadge", wrap),
        panel: $("#ecbPanel", wrap),
        closeBtn: $("#ecbClose", wrap),
        messages: $("#ecbMessages", wrap),
        actions: $("#ecbActions", wrap),
        input: $("#ecbInput", wrap),
        send: $("#ecbSend", wrap),
        headerMeta: $("#ecbHeaderMeta", wrap),
        progressBar: $("#ecbProgressBar", wrap),
        humanWrap: $("#ecbHumanWrap", wrap),
        humanBtn: $("#ecbHumanBtn", wrap),
        inlineNewChatBtn: $("#ecbInlineNewChat", wrap),
        inlineShowMenuBtn: $("#ecbInlineShowMenu", wrap),
      };
    }

    function stopEventPropagation(e) {
      e.stopPropagation();
    }

    function isolateChatInteractions(dom) {
      ["click", "touchstart", "touchend"].forEach((eventName) => {
        dom.panel.addEventListener(eventName, stopEventPropagation, { passive: true });
      });

      ["click", "touchstart"].forEach((eventName) => {
        dom.floatBtn.addEventListener(eventName, stopEventPropagation, { passive: true });
      });
    }

    function setHeaderMeta(dom, text) {
      if (!dom.headerMeta) return;
      dom.headerMeta.textContent = String(text || "").trim() || "Listo para conversar";
    }

    function getLeadFlowProgress(flow) {
      const total = Math.max(1, flow.fields.length + 1);
      if (!flow.active) {
        return flow.completed ? "Lead captado" : "Captación guiada activa";
      }
      if (flow.intent === "humano") {
        if (flow.index < flow.fields.length) {
          return `Derivación humana · Paso ${flow.index + 1} de ${total}`;
        }
        if (flow.askedRequirement && !flow.requirement) {
          return `Derivación humana · Paso ${total} de ${total}`;
        }
        return "Derivación humana";
      }
      if (flow.index < flow.fields.length) {
        return `Paso ${flow.index + 1} de ${total}`;
      }
      if (flow.askedRequirement && !flow.requirement) {
        return `Paso ${total} de ${total}`;
      }
      return "Enviando lead";
    }

    function getLeadFlowStepIndex(flow) {
      if (!flow.active) return 0;
      const total = Math.max(1, flow.fields.length + 1);
      if (flow.index < flow.fields.length) return flow.index + 1;
      if (flow.askedRequirement && !flow.requirement) return total;
      return total;
    }

    function updateProgressBar(dom) {
      if (!dom.progressBar) return;
      const flow = state.leadFlow;
      if (!flow.active || flow.completed) {
        dom.progressBar.hidden = true;
        return;
      }
      const total = Math.max(1, flow.fields.length + 1);
      const step = getLeadFlowStepIndex(flow);
      const pct = Math.round((step / total) * 100);
      dom.progressBar.hidden = false;
      dom.progressBar.setAttribute("aria-valuenow", step);
      dom.progressBar.setAttribute("aria-valuemin", 1);
      dom.progressBar.setAttribute("aria-valuemax", total);
      dom.progressBar.setAttribute("aria-label", `Paso ${step} de ${total}`);
      dom.progressBar.style.setProperty("--ecb-progress", `${pct}%`);
    }

    function updateInputPlaceholder(dom) {
      const flow = state.leadFlow;
      if (flow.active && flow.index < flow.fields.length) {
        const field = flow.fields[flow.index];
        const key = (field && field.key) || "";
        const placeholders = {
          email: "Ej: correo@ejemplo.com",
          phone: "Ej: +56 9 1234 5678",
          name: "Escribe tu nombre",
        };
        dom.input.placeholder = placeholders[key] || (field && field.label) || "Escribe aquí…";
      } else {
        dom.input.placeholder = "Escribe tu mensaje…";
      }
    }

    function updateHumanButtonVisibility(dom) {
      if (!dom.humanWrap) return;
      const show = humanEscalationEnabled && !state.leadFlow.active;
      dom.humanWrap.style.display = show ? "block" : "none";
    }

    function setUIBusy(dom, busy) {
      dom.input.disabled = busy;
      dom.send.disabled = busy;
      dom.actions.querySelectorAll("button").forEach((b) => (b.disabled = busy));
      dom.root.classList.toggle("ecb-busy", busy);
    }

    function showBadge(dom) {
      if (!dom.panel.classList.contains("ecb-panel--open")) {
        dom.badge.hidden = false;
        dom.floatBtn.classList.add("ecb-attention");
      }
    }

    function hideBadge(dom) {
      dom.badge.hidden = true;
      dom.floatBtn.classList.remove("ecb-attention");
    }

    function markConversationStarted(dom) {
      markUserInteracted();
      if (!state.analyticsStarted) {
        state.analyticsStarted = true;
        trackAnalyticsEvent("chatbot_started", {
          chatbot_session_key: state.sessionId || "",
        });
      }
      if (!inlineMode) return;
      dom.root.classList.add("ecb-chat-started");
      dom.root.classList.remove("ecb-inline-landing");
    }

    function isPanelOpen(dom) {
      return !!(dom && dom.panel && dom.panel.classList.contains("ecb-panel--open"));
    }

    function openPanel(dom, options = {}) {
      if (inlineMode || !dom || !dom.panel) return false;
      if (isPanelOpen(dom)) return false;
      dom.panel.classList.add("ecb-panel--open");
      hideBadge(dom);
      saveOpenState(true);
      if (options.markAutoOpened === true) {
        markAutoOpened();
      }
      trackAnalyticsEvent("chatbot_opened", {
        chatbot_session_key: state.sessionId || "",
      });
      return true;
    }

    function shouldAutoOpen(dom) {
      if (inlineMode || !autoOpenEnabled) return false;
      if (hasAutoOpened()) return false;
      if (loadOpenState()) return false;
      if (isPanelOpen(dom)) return false;
      if (userHasInteracted) return false;
      return true;
    }

    function tryAutoOpen(dom) {
      if (!shouldAutoOpen(dom)) return false;
      return openPanel(dom, { markAutoOpened: true });
    }

    function getScrollPercent() {
      const scrollTop = Math.max(window.scrollY || 0, document.documentElement.scrollTop || 0, document.body.scrollTop || 0);
      const docHeight = Math.max(
        document.body.scrollHeight || 0,
        document.documentElement.scrollHeight || 0
      );
      const viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
      const maxScroll = docHeight - viewportHeight;
      if (maxScroll <= 0) return 100;
      return (scrollTop / maxScroll) * 100;
    }

    function scheduleAutoOpen(dom, restoredConversation) {
      if (inlineMode || restoredConversation || !autoOpenEnabled) return;

      if (autoOpenType === "scroll") {
        const onScrollAutoOpen = () => {
          if (!shouldAutoOpen(dom)) {
            if (hasAutoOpened() || userHasInteracted || isPanelOpen(dom)) {
              window.removeEventListener("scroll", onScrollAutoOpen);
            }
            return;
          }
          if (getScrollPercent() < autoOpenValue) return;
          if (tryAutoOpen(dom)) {
            window.removeEventListener("scroll", onScrollAutoOpen);
          }
        };
        window.addEventListener("scroll", onScrollAutoOpen, { passive: true });
        onScrollAutoOpen();
        return;
      }

      window.setTimeout(() => {
        tryAutoOpen(dom);
      }, autoOpenValue * 1000);
    }

    function messageHasCTA(html) {
      const source = String(html || "").toLowerCase();
      return (
        source.includes("ecb-cta") ||
        source.includes("ecb-link-btn") ||
        source.includes("data-ecb-whatsapp-cta") ||
        source.includes("<a ")
      );
    }

    function addMessage(dom, who, html) {
      const item = document.createElement("div");
      item.className = `ecb-msg ecb-msg--${who}`;
      const avatarUrl = who === "bot" ? String(branding.bot_avatar || "").trim() : "";
      const avatarHtml = who === "bot" && isValidUrl(avatarUrl)
        ? `<img class="ecb-avatar" src="${escapeHTML(avatarUrl)}" alt="${escapeHTML(botName || "Bot")}" />`
        : "";
      item.innerHTML = `${avatarHtml}<div class="ecb-bubble">${html}</div>`;
      dom.messages.appendChild(item);
      scrollToBottom(dom.messages);
      if (who === "bot") {
        state.leadFlow.lastBotHadCTA = messageHasCTA(html);
        if (!state.leadFlow.lastBotHadCTA) {
          state.lastResponseType = "";
        }
        showBadge(dom);
      }
    }

    function addTyping(dom) {
      const el = document.createElement("div");
      el.className = "ecb-msg ecb-msg--bot ecb-typing";
      const avatarUrl = String(branding.bot_avatar || "").trim();
      const avatarHtml = isValidUrl(avatarUrl)
        ? `<img class="ecb-avatar" src="${escapeHTML(avatarUrl)}" alt="${escapeHTML(botName || "Bot")}" />`
        : "";
      el.innerHTML = `
        ${avatarHtml}
        <div class="ecb-bubble">
          <span class="ecb-dot"></span>
          <span class="ecb-dot"></span>
          <span class="ecb-dot"></span>
        </div>`;
      dom.messages.appendChild(el);
      scrollToBottom(dom.messages);
      return el;
    }

    function renderActionButtons(actionsEl, items) {
      actionsEl.innerHTML = "";
      if (state.conversationClosed) {
        state.currentButtons = [];
        return;
      }
      state.currentButtons = items;
      if (!items.length) return;
      state.leadFlow.lastBotHadCTA = true;

      const list = document.createElement("div");
      list.className = "ecb-btnlist";
      items.forEach((item, idx) => {
        const btn = document.createElement("button");
        btn.className = "ecb-btn";
        btn.dataset.idx = String(idx);
        btn.textContent = item.label;
        list.appendChild(btn);
      });
      actionsEl.appendChild(list);
    }

    function renderConfiguredButtons(actionsEl) {
      const configuredButtons = Array.isArray(buttons) ? buttons : [];
      console.log("Botones cargados:", configuredButtons);
      if (configuredButtons.length > 0) {
        renderActionButtons(actionsEl, configuredButtons);
        return;
      }
      console.warn("No hay botones configurados en el panel");
      renderActionButtons(actionsEl, []);
    }

    function renderLeadIntentButtons(actionsEl) {
      if (leadIntentButtons.length > 0) {
        renderActionButtons(actionsEl, leadIntentButtons);
        return;
      }
      console.warn("No hay botones de entrada configurados en Captura de datos; usando botones secundarios");
      renderConfiguredButtons(actionsEl);
    }

    function ensureInitialLeadOptionsVisible(dom) {
      if (!state.leadFlow.enabled) return;
      if (state.leadFlow.active || state.leadFlow.completed) return;
      if (state.currentButtons.length > 0 && dom.actions.childElementCount > 0) return;
      renderLeadIntentButtons(dom.actions);
    }

    function resetConversation(dom) {
      clearMemory();
      rotateSessionId();
      saveChatState("default");
      dom.messages.innerHTML = "";
      dom.actions.innerHTML = "";
      state.isTyping = false;
      state.lastUserMessage = null;
      state.lastGoodAnswer = null;
      state.retryCount = 0;
      state.offline = false;
      state.lastAction = null;
      state.conversationClosed = false;
      state.leadFlow.active = false;
      state.leadFlow.completed = false;
      state.leadFlow.awaitingLead = false;
      state.leadFlow.lastBotHadCTA = false;
      state.leadFlow.index = 0;
      state.leadFlow.askedRequirement = false;
      state.leadFlow.intent = "";
      state.leadFlow.requirement = "";
      state.leadFlow.data = {};
      state.pendingContactCorrection = null;
      state.skipDefaultWelcome = false;
      syncLeadStateSnapshot();
      setHeaderMeta(dom, state.leadFlow.enabled ? "Captación guiada activa" : "Listo para conversar");
      bootConversation(dom);
    }

    function syncMemoryFromMessages(messages) {
      const memory = messages
        .filter((item) => item && (item.role === "user" || item.role === "assistant"))
        .map((item) => ({ role: item.role, content: String(item.content || "") }));
      saveMemory(memory);
    }

    async function hydrateConversation(dom) {
      if (!state.sessionId) return false;
      try {
        const json = await postChatbotAction("estandar_chatbot_get_conversation", {
          session_id: state.sessionId,
          channel: "web",
        });
        const messages = Array.isArray(json?.data?.messages) ? json.data.messages : [];
        const visible = messages.filter(
          (item) => item && (item.role === "user" || item.role === "assistant")
        );
        if (!visible.length) return false;

        dom.messages.innerHTML = "";
        visible.forEach((item) => {
          addMessage(
            dom,
            item.role === "assistant" ? "bot" : "user",
            item.role === "assistant"
              ? renderBotText(String(item.content || ""))
              : escapeHTML(String(item.content || "")).replaceAll("\n", "<br>")
          );
        });
        syncMemoryFromMessages(visible);
        markConversationStarted(dom);
        renderConfiguredButtons(dom.actions);
        return true;
      } catch {
        return false;
      }
    }

    function handleCommand(dom, text) {
      const cmd = text.trim().toLowerCase();
      if (cmd === "/reset") {
        resetConversation(dom);
        return true;
      }
      if (
        humanEscalationEnabled &&
        ["/humano", "/agente", "/asesor"].includes(cmd)
      ) {
        startHumanEscalation(dom);
        return true;
      }
      return false;
    }

    function validateLeadField(field, value) {
      const v = String(value || "").trim();
      if (field.required && !v) return "Este campo es obligatorio.";
      if (!v) return "";
      if (field.key === "email" && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) {
        return "Parece que el correo podría contener un error.";
      }
      if (field.key === "phone") {
        if (!normalizeChileanMobilePhone(v)) {
          return "Parece que el número ingresado podría estar mal escrito.";
        }
      }
      if (field.key === "name") {
        if (/[0-9@:/]/.test(v) || v.length < 2 || v.split(/\s+/).filter(Boolean).length > 5) {
          return "Ingresa un nombre válido.";
        }
      }
      return "";
    }

    function looksLikeConversationDetour(field, value) {
      const text = String(value || "").trim();
      const normalized = normalizeIntentText(text);
      if (!normalized) return false;
      const words = normalized.split(/\s+/).filter(Boolean);

      // 2.0.95: Price and contact intents are always conversation detours, not lead
      // field answers. Ensures "quiero cotizar" or "hablar con alguien" typed during
      // lead capture cancels the flow and reroutes fresh instead of being stored as
      // a name/phone/email value (TEST 7).
      const classifiedIntent =
        typeof decisionEngine.classifyIntent === "function"
          ? decisionEngine.classifyIntent(text)
          : null;
      if (isFreshInterruptIntent(text, classifiedIntent)) return true;

      const infoPatterns = [
        "quiero saber",
        "saber mas",
        "saber más",
        "mas info",
        "más info",
        "informacion",
        "información",
        "subsidio",
        "financiamiento",
        "entrega",
        "disponibilidad",
        "stock",
        "planes",
        "como funciona",
        "cómo funciona",
        "que incluye",
        "qué incluye",
        "explicame",
        "explícame",
      ];
      const hasInfoIntent = infoPatterns.some((pattern) =>
        normalized.includes(normalizeIntentText(pattern))
      );
      if (/[?¿]/.test(text) || hasInfoIntent) return true;
      if (field.key === "name" && words.length >= 4) return true;
      if (field.key === "phone" && words.length >= 3 && text.replace(/\D+/g, "").length < 7) return true;
      if (field.key === "email" && words.length >= 3 && !text.includes("@")) return true;
      return false;
    }

    function getLeadTransitionMessage(intentId, intentLabel) {
      const normalizedIntent = normalizeIntentText(intentId || intentLabel || "");

      if (normalizedIntent.includes("humano") || normalizedIntent.includes("asesor")) {
        return "Perfecto.\n\nPara que un asesor te contacte, déjame tu nombre 👇";
      }
      if (normalizedIntent.includes("contratar") || normalizedIntent.includes("avanzar")) {
        return "Perfecto.\n\nPara avanzar ahora mismo, te pido un dato breve.\n¿Cuál es tu nombre? 👇";
      }
      if (specialServiceKeyword && normalizedIntent.includes(specialServiceKeyword)) {
        return specialServiceTransitionMessage;
      }
      if (normalizedIntent.includes("cotizar") || normalizedIntent.includes("ventas") || normalizedIntent.includes("precio")) {
        return priceTransitionMessage;
      }

      return "Perfecto.\n\nPara ayudarte mejor, déjame tu nombre 👇";
    }

    // FLOW
    function cancelLeadFlowForConversation(dom) {
      setConversationOwner("IDLE");
      state.leadFlow.active = false;
      state.leadFlow.completed = false;
      state.leadFlow.awaitingLead = false;
      state.leadFlow.index = 0;
      state.leadFlow.askedRequirement = false;
      state.leadFlow.requirement = "";
      syncLeadStateSnapshot();
      setHeaderMeta(dom, "Listo para conversar");
      updateProgressBar(dom);
      updateInputPlaceholder(dom);
      updateHumanButtonVisibility(dom);
      renderConfiguredButtons(dom.actions);
    }

    function interruptStickyConversationState(dom) {
      state.pendingDisambiguation = null;
      state.pendingLeadStart = null;
      state.intent = null;
      state.lastAction = null;
      state.lastUserIntent = "";
      state.conversationClosed = false;

      if (state.leadFlow.active) {
        state.leadFlow.active = false;
        state.leadFlow.completed = false;
        state.leadFlow.awaitingLead = false;
        state.leadFlow.index = 0;
        state.leadFlow.askedRequirement = false;
        state.leadFlow.intent = "";
        state.leadFlow.requirement = "";
      }

      syncLeadStateSnapshot();
      setHeaderMeta(dom, "Listo para conversar");
      updateProgressBar(dom);
      updateInputPlaceholder(dom);
      updateHumanButtonVisibility(dom);
    }

    function promptCurrentLeadStep(dom) {
      setHeaderMeta(dom, getLeadFlowProgress(state.leadFlow));
      updateProgressBar(dom);
      updateInputPlaceholder(dom);
      const flow = state.leadFlow;
      if (flow.index < flow.fields.length) {
        addMessage(dom, "bot", renderBotText(flow.fields[flow.index].label));
        return;
      }
      submitLeadFlow(dom);
    }

    function mostrarFormularioLead(dom, options = {}) {
      const flow = state.leadFlow;
      if (!flow.active || flow.awaitingLead === true) return;

      flow.awaitingLead = true;
      syncLeadStateSnapshot();
      setHeaderMeta(dom, getLeadFlowProgress(flow));
      updateProgressBar(dom);
      updateInputPlaceholder(dom);
      updateHumanButtonVisibility(dom);
      if (hideMenuOnClick) renderActionButtons(dom.actions, []);

      const transitionMessage =
        typeof options.transitionMessage === "string"
          ? options.transitionMessage.trim()
          : getLeadTransitionMessage(flow.intent, flow.data.intent_label);

      if (transitionMessage) {
        addMessage(dom, "bot", renderBotText(transitionMessage));
      }

      promptCurrentLeadStep(dom);
    }

    function iniciarCapturaLead(dom, config = {}) {
      clearIncompatibleState("LEAD_CAPTURE");
      const flow = state.leadFlow;
      flow.active = true;
      flow.awaitingLead = false;
      flow.completed = false;
      flow.index = 0;
      flow.askedRequirement = false;
      flow.intent = String(config.intentId || "").trim();
      flow.requirement = String(config.requirement || "").trim();
      flow.data = Object.assign({}, config.data || {});
      syncLeadStateSnapshot();
      setHeaderMeta(dom, getLeadFlowProgress(flow));

      updateProgressBar(dom);
      updateInputPlaceholder(dom);
      updateHumanButtonVisibility(dom);
      mostrarFormularioLead(dom, {
        transitionMessage:
          typeof config.transitionMessage === "string"
            ? config.transitionMessage
            : getLeadTransitionMessage(flow.intent, flow.data.intent_label),
      });
    }

    function avanzarPasoLead(dom, field, value) {
      const flow = state.leadFlow;
      delete flow.data[`${field.key}_raw`];
      flow.data[field.key] = field.key === "phone"
        ? normalizeChileanMobilePhone(value)
        : field.key === "email"
          ? normalizeContactEmail(value)
          : String(value).trim();
      flow.awaitingLead = false;
      flow.index += 1;
      if (field.key === "phone") {
        trackAnalyticsEvent("chatbot_phone_captured", { chatbot_session_key: state.sessionId || "" });
      } else if (field.key === "email") {
        trackAnalyticsEvent("chatbot_email_captured", { chatbot_session_key: state.sessionId || "" });
      }
      syncLeadStateSnapshot();
      setHeaderMeta(dom, getLeadFlowProgress(flow));
      updateProgressBar(dom);
      updateInputPlaceholder(dom);
      promptCurrentLeadStep(dom);
    }

    function promptContactCorrection(dom, field, value) {
      const raw = String(value || "").trim();
      state.pendingContactCorrection = { field, value: raw };
      const message = field.key === "phone"
        ? `Parece que el número ingresado podría estar mal escrito.\n\n${raw}\n\n¿Quieres corregirlo?`
        : `Parece que el correo podría contener un error.\n\n${raw}\n\n¿Quieres revisarlo antes de continuar?`;
      addMessage(dom, "bot", renderBotText(message));
      renderActionButtons(dom.actions, [
        { label: "Corregir", type: "contact_validation_correct", value: field.key },
        { label: "Continuar", type: "contact_validation_continue", value: field.key },
      ]);
    }

    function finalizarLead(dom, tier) {
      const flow = state.leadFlow;
      flow.active = false;
      flow.completed = true;
      flow.awaitingLead = false;
      state.pendingLeadStart = null;
      syncLeadStateSnapshot();
      saveChatState("lead_captured");
      setHeaderMeta(dom, `Lead listo · ${tier.toUpperCase()}`);
      showPostLeadBlock(dom);
      updateProgressBar(dom);
      updateHumanButtonVisibility(dom);
    }

    function startLeadFlow(dom, intentId, intentLabel) {
      state.lastUserIntent = String(intentId || intentLabel || "").trim();
      trackAnalyticsEvent("chatbot_intent_selected", {
        chatbot_session_key: state.sessionId || "",
        intent: String(intentId || ""),
        intent_label: String(intentLabel || ""),
      });
      const storedLead = getStoredLead();
      if (storedLead) {
        promptStoredLeadChoice(dom, {
          intentId,
          data: { intent: intentId, intent_label: intentLabel },
          transitionMessage: getLeadTransitionMessage(intentId, intentLabel),
        });
        return;
      }
      iniciarCapturaLead(dom, {
        intentId,
        data: { intent: intentId, intent_label: intentLabel },
        transitionMessage: getLeadTransitionMessage(intentId, intentLabel),
      });
    }

	    async function handleUserMessage(dom, text, options = {}) {
	      const userText = String(text || "").trim();
	      if (!userText) return false;

	      const preClassifiedIntent =
	        typeof decisionEngine.classifyIntent === "function"
	          ? decisionEngine.classifyIntent(userText)
	          : null;
	      const directLead = extractLeadFromText(userText);
	      // CONTACT_MERGE_PRESERVATION_V1: a valid email typed on its own after
	      // the lead flow already captured name/phone (common when the profile
	      // does not ask for email) must be persisted, not discarded. Merge with
	      // the stored lead to evaluate completeness; only when the message
	      // actually adds a new valid email, and never while the guided flow is
	      // active (mid-flow answers belong to the flow).
	      const storedLeadForFastTrack = getStoredLead() || {};
	      const directEmailNormalized = normalizeContactEmail(directLead.email);
	      const addsNewValidEmail =
	        !state.leadFlow.active &&
	        !!directEmailNormalized &&
	        directEmailNormalized !== normalizeContactEmail(storedLeadForFastTrack.email);
	      const mergedLeadForFastTrack = {
	        name: directLead.name || storedLeadForFastTrack.name || "",
	        email: directLead.email || storedLeadForFastTrack.email || "",
	        phone: directLead.phone || storedLeadForFastTrack.phone || "",
	      };
	      if (
	        hasCompleteLeadForFastTrack(directLead) ||
	        (addsNewValidEmail && hasCompleteLeadForFastTrack(mergedLeadForFastTrack))
	      ) {
	        state.leadFlow.active = true;
	        state.leadFlow.completed = false;
	        state.leadFlow.awaitingLead = false;
	        state.leadFlow.index = Array.isArray(state.leadFlow.fields) ? state.leadFlow.fields.length : 0;
	        state.leadFlow.intent = String(preClassifiedIntent || state.lastUserIntent || "contact").trim();
	        state.leadFlow.requirement = "";
	        state.leadFlow.data = Object.assign({}, getStoredLead() || {}, state.leadFlow.data || {}, directLead, {
	          intent: state.leadFlow.intent,
	          intent_label: state.leadFlow.intent || "contact",
	        });
	        syncLeadStateSnapshot();
	        await submitLeadFlow(dom);
	        return true;
	      }

	      if (humanEscalationEnabled && (preClassifiedIntent === "contact" || isHumanContactIntentText(userText))) {
	        if (isRepeatingResponseType("human_escalation_choice") && state.currentButtons.some((item) => item && (item.type === "human_whatsapp_direct" || item.type === "human_contact_lead"))) {
	          return true;
	        }
        state.lastUserMessage = userText;
        state.retryCount = 0;
        startHumanEscalation(dom);
        return true;
      }

      if (isHotIntentText(userText)) {
        interruptStickyConversationState(dom);
        if (requiresDisambiguation && !String(state.selectedContext || "").trim()) {
          return askForDisambiguation(dom, "cta_direct", { userText });
        }
        triggerUrgentClose(dom, options.source || "message");
        return true;
      }

      if (state.pendingDisambiguation) {
        const handledDisambiguation = await continueAfterDisambiguation(dom, userText);
        if (handledDisambiguation) return true;
      }

      if (
        requiresDisambiguation &&
        !String(state.selectedContext || "").trim()
      ) {
        const typedContextMatch = getMentionedDisambiguationOption(userText);
        if (typedContextMatch) {
          state.selectedContext = typedContextMatch;
          state.pendingDisambiguation = null;
          setConversationOwner("IDLE");
        }
      }

      if (preClassifiedIntent === "info") {
        if (state.leadFlow.active) {
          cancelLeadFlowForConversation(dom);
        }
        if (shouldUseSmartDefaultFallback()) {
          return showSmartDefaultFallback(dom, "info");
        }
        state.lastUserIntent = "info";
        state.lastUserMessage = userText;
        state.retryCount = 0;
        await askAI(dom, userText);
        return true;
      }

      if (preClassifiedIntent === "consultative_service_intent") {
        if (state.activeOwner === "HUMAN_ESCALATION") {
          clearIncompatibleState("IDLE");
          renderActionButtons(dom.actions, []);
        }
        if (state.leadFlow.active) {
          cancelLeadFlowForConversation(dom);
        }
        state.lastUserIntent = preClassifiedIntent;
        state.lastUserMessage = userText;
        state.retryCount = 0;
        await askAI(dom, userText, { appendNextStepCta: true });
        return true;
      }

      if (await handleSmartUserIntent(dom, userText)) return true;

      const matchedLeadIntent = findMatchingLeadIntent(
        userText,
        options.intentId,
        options.intentLabel
      );

      if (matchedLeadIntent) {
        if (
          requiresDisambiguation &&
          !String(state.selectedContext || "").trim() &&
          !state.pendingDisambiguation &&
          hasDisambiguationOptions()
        ) {
          // 2.0.94 Bug 1: An explicit button click (source="button") means the user already
          // resolved disambiguation by choosing that button. The 2.0.93 substring-matching patch
          // was fragile — failed when labels didn't contain a known option as substring.
          // Direct signal: options.source === "button" + intentLabel/intentId is always set.
          // Set selectedContext from intentLabel and fall through to startLeadFlow immediately.
          if (options.source === "button" && (options.intentLabel || options.intentId)) {
            state.selectedContext = String(options.intentLabel || options.intentId || "").trim();
          // 2.0.93 Bug C: general informational questions (e.g. "¿aceptan subsidio?",
          // "¿cómo financian?") can match a lead intent label and end up here, triggering
          // disambiguation even though the question can be answered without project context.
          // Guard: route to AI instead of forcing context selection.
          } else if (isContextIndependentQuestion(userText, preClassifiedIntent)) {
            state.lastUserMessage = userText;
            state.retryCount = 0;
            await askAI(dom, userText, { appendNextStepCta: true });
            return true;
          } else {
            return askForDisambiguation(dom, "cta_direct", {
              userText,
              intentId: matchedLeadIntent.id,
              intentLabel: matchedLeadIntent.label,
            });
          }
        }
        startLeadFlow(dom, matchedLeadIntent.id, matchedLeadIntent.label);
        return true;
      }

      const leadInput = await handleLeadTextInput(dom, userText);
      if (leadInput.handled) return true;

      if (hasPriceIntentText(userText)) {
        if (shouldAnswerBeforeLeadCapture(userText, preClassifiedIntent)) {
          return answerBeforeLeadCapture(dom, userText);
        }
        if (shouldUseSmartDefaultFallback()) {
          return showSmartDefaultFallback(dom, "price");
        }
        return showPriceCTA(dom, { responseType: "price_cta_fallback" });
      }

      state.lastUserMessage = userText;
      state.retryCount = 0;
      await askAI(dom, userText);
      return true;
    }

    async function drainPendingInputQueue(dom) {
      if (state.processingInput || state.isTyping) return;
      const next = state.pendingInputQueue.shift();
      if (!next) return;
      await processUserMessage(dom, next.text, next.options || {});
    }

    async function processUserMessage(dom, text, options = {}) {
      const userText = String(text || "").trim();
      if (!userText) return false;

      if (state.processingInput || state.isTyping) {
        state.pendingInputQueue.push({ text: userText, options: Object.assign({}, options || {}) });
        return true;
      }

      state.processingInput = true;
      try {
        return await handleUserMessage(dom, userText, options);
      } finally {
        state.processingInput = false;
        if (!state.isTyping && state.pendingInputQueue.length) {
          window.setTimeout(() => {
            drainPendingInputQueue(dom).catch(() => {});
          }, 0);
        }
      }
    }

    function continueWithStoredLead(dom) {
      const storedLead = getStoredLead();
      const pendingStart = state.pendingLeadStart;
      if (!storedLead || !pendingStart) return false;

      state.pendingLeadStart = null;
      const flow = state.leadFlow;
      flow.active = false;
      flow.awaitingLead = false;
      flow.completed = true;
      flow.index = flow.fields.length;
      flow.askedRequirement = false;
      flow.intent = String(pendingStart.intentId || "").trim();
      flow.requirement = String(pendingStart.requirement || "").trim();
      flow.data = Object.assign({}, pendingStart.data || {}, storedLead);
      syncLeadStateSnapshot();
      saveChatState("lead_captured");
      setHeaderMeta(dom, "Lead listo");
      updateProgressBar(dom);
      updateInputPlaceholder(dom);
      updateHumanButtonVisibility(dom);
      const resumed = resumeStoredLeadIntent(
        dom,
        String(pendingStart.intentId || pendingStart.data?.intent || "").trim(),
        String(pendingStart.data?.intent_label || "").trim(),
        pendingStart
      );
      if (resumed) {
        return true;
      }
      state.lastAction = "postLead";
      showPostLeadBlock(dom, {
        message: "Listo.\n\nPuedes avanzar ahora 👇",
        forceWhatsAppCTA: !hotLeadUrl,
      });
      setLastResponseType("post_lead");
      return true;
    }

    function startHumanLeadCapture(dom) {
      iniciarCapturaLead(dom, {
        intentId: "humano",
        data: {
          intent: "humano",
          intent_label: "Hablar con humano",
          force_human: "1",
          escalation_requested: "1",
          escalation_channel: "chat",
        },
        transitionMessage: humanEscalationText,
      });
    }

    function showHumanEscalationChoice(dom) {
      setConversationOwner("HUMAN_ESCALATION");
      const oh = cfg.operationalHours || {};
      const outsideHours = oh.enabled && !oh.isOpen;
      if (outsideHours) {
        const hoursMsg = oh.message || "En este momento estamos fuera de horario de atención.";
        addMessage(dom, "bot", renderBotText(`${hoursMsg}\n\n¿Cómo prefieres avanzar?`));
        renderActionButtons(dom.actions, [
          { label: "Dejar mis datos", type: "human_contact_lead", value: "lead" },
          { label: "Enviar WhatsApp", type: "human_whatsapp_direct", value: "whatsapp" },
        ]);
      } else {
        addMessage(dom, "bot", renderBotText("Perfecto 👌 ¿Cómo prefieres avanzar?"));
        renderActionButtons(dom.actions, [
          { label: "Hablar ahora por WhatsApp", type: "human_whatsapp_direct", value: "whatsapp" },
          { label: "Que me contacten", type: "human_contact_lead", value: "lead" },
        ]);
      }
      setLastResponseType("human_escalation_choice");
      return true;
    }

    function startHumanEscalation(dom) {
      clearIncompatibleState("HUMAN_ESCALATION");

      if (buildHumanEscalationWhatsAppUrl()) {
        showHumanEscalationChoice(dom);
        updateHumanButtonVisibility(dom);
        return;
      }

      startHumanLeadCapture(dom);
    }

    // API / TRANSPORT
    async function submitLeadFlow(dom) {
      const flow = state.leadFlow;
      setConversationOwner("LEAD_CAPTURE");
      state.isTyping = true;
      setUIBusy(dom, true);
      const typing = addTyping(dom);
      try {
        const recentConversation = getRecentConversationText(10);
        const lastUserMessage = getLastUserMessage();
        const leadIntent = detectLeadIntentFromConversation(flow);
        const conversationSummary = buildConversationSummary(flow, leadIntent, lastUserMessage, recentConversation);
        const payload = {
          intent: flow.intent,
          requirement: flow.requirement || "",
          session_id: state.sessionId,
          channel: "web",
          ea_sid: getAnalyticsSessionId(),
          lead_intent: leadIntent,
          conversation_summary: conversationSummary,
          last_user_message: lastUserMessage,
          conversation_recent: recentConversation,
        };
        try {
          payload.source_page_url = String(window.location.href || "");
        } catch {
          payload.source_page_url = "";
        }
        if (contextSlug) {
          payload.context_slug = contextSlug;
        }
        Object.entries(flow.data).forEach(([k, v]) => {
          if (k === "intent" || k === "intent_label") return;
          payload[`lead[${k}]`] = String(v || "");
        });
        const json = await postChatbotAction("estandar_chatbot_save_lead", payload);
        typing.remove();
        if (!json || !json.success) {
          const error = new Error(String(json?.data?.message || "LEAD_FAIL"));
          error.code = String(json?.data?.code || "");
          throw error;
        }
        if (json.data?.blocked) {
          addMessage(dom, "bot", renderBotText(String(json.data.message || "")));
          setUIBusy(dom, false);
          state.isTyping = false;
          return;
        }

        const tier = String(json.data?.tier || "cold");
        saveStoredLead(flow.data);
        if (json.data?.escalation_requested) {
          trackAnalyticsEvent("chatbot_escalation_requested", {
            chatbot_session_key: state.sessionId || "",
            lead_id: String(json.data?.lead_id || ""),
          });
        }
        finalizarLead(dom, tier);
        if (tier === "hot") {
          openHotLeadRedirect(dom, "lead_hot", { hasContactData: hasLeadContactData(), autoOpen: true });
        }

        if (flow.requirement && flow.requirement.length > 2) {
          await askAI(dom, flow.requirement);
        }
      } catch (error) {
        try { typing.remove(); } catch {}
        if (error?.code === "contact_rejected") {
          const contactIndex = flow.fields.findIndex((field) => field?.key === "email" || field?.key === "phone");
          delete flow.data.email;
          delete flow.data.phone;
          flow.active = true;
          flow.completed = false;
          flow.awaitingLead = false;
          flow.index = contactIndex >= 0 ? contactIndex : 0;
          addMessage(dom, "bot", renderBotText(error.message));
          syncLeadStateSnapshot();
          promptCurrentLeadStep(dom);
          return;
        }
        addMessage(dom, "bot", renderBotText(errorFriendly.lead_fail || "No pude registrar tus datos. ¿Quieres intentarlo nuevamente?"));
        flow.active = false;
        flow.completed = false;
        flow.awaitingLead = false;
        syncLeadStateSnapshot();
        setHeaderMeta(dom, "Captación pausada");
        updateProgressBar(dom);
        updateInputPlaceholder(dom);
        updateHumanButtonVisibility(dom);
        renderConfiguredButtons(dom.actions);
      } finally {
        state.isTyping = false;
        setConversationOwner("IDLE");
        setUIBusy(dom, false);
      }
    }

    async function handleLeadTextInput(dom, text) {
      const flow = state.leadFlow;
      if (!flow.active) return { handled: false, passthrough: false };
      if (flow.index < flow.fields.length) {
        const field = flow.fields[flow.index];
        if (looksLikeConversationDetour(field, text)) {
          cancelLeadFlowForConversation(dom);
          addMessage(
            dom,
            "bot",
            renderBotText("Te cuento primero y, si quieres, después me dejas tus datos 👇")
          );
          return { handled: false, passthrough: true };
        }
        const err = validateLeadField(field, text);
        if (err) {
          if (field.key === "email" || field.key === "phone") {
            promptContactCorrection(dom, field, text);
            return { handled: true, passthrough: false };
          }
          addMessage(dom, "bot", renderBotText(err));
          addMessage(dom, "bot", renderBotText(field.label));
          return { handled: true, passthrough: false };
        }
        avanzarPasoLead(dom, field, text);
        return { handled: true, passthrough: false };
      }
      return { handled: false, passthrough: false };
    }

    function handleOfflineFallback(dom) {
      state.offline = true;
      if (state.lastGoodAnswer) {
        addMessage(
          dom,
          "bot",
          `Puedo ayudarte con esto mientras tanto:<br><br>${renderBotText(state.lastGoodAnswer)}`
        );
        return;
      }
      addMessage(dom, "bot", renderBotText(errorFriendly.offline || "En este momento no tengo conexión. Puedes intentar en unos minutos o usar los botones disponibles."));
    }

    async function askAI(dom, userText, options = {}) {
      if (state.retryCount > 1) {
        handleOfflineFallback(dom);
        return;
      }

      const requestId = Number(state.activeRequestId || 0) + 1;
      state.activeRequestId = requestId;
      setConversationOwner(options.appendNextStepCta === true ? "VALUE_FIRST_QA" : "AI_PROCESSING");
      state.isTyping = true;
      setUIBusy(dom, true);
      const typing = addTyping(dom);
      const memory = getMemory();
      memory.push({ role: "user", content: userText });
      saveMemory(memory);

      try {
        const json = await postChatbotAction("estandar_chatbot_ai", {
          message: userText,
          lang,
          history: JSON.stringify(memory),
          session_id: state.sessionId,
          channel: "web",
          page_url: getPageUrl(),
          lead_identifier: getLeadIdentifier(),
          context_slug: contextSlug,
        });
        typing.remove();
        if (!json || !json.success) {
          const msg =
            json?.data?.message ||
            json?.data?.messages ||
            "AI_FAIL";
          const err = new Error(msg);
          err.name = "AI_FAIL";
          throw err;
        }

        if (requestId !== state.activeRequestId) {
          return;
        }

        const answer = String(json.data.answer || "");
        const answerWithCta = options.appendNextStepCta === true
          ? appendValueFirstCTA(answer, userText)
          : answer;
        const renderedAnswer = compactHighIntentAnswer(userText, answerWithCta);
        if (/whatsapp/i.test(renderedAnswer) && buildHumanEscalationWhatsAppUrl()) {
          addMessage(
            dom,
            "bot",
            `${renderBotText(renderedAnswer)}<br><br>${buildWhatsAppCTAHtml()}`
          );
          setLastResponseType("whatsapp_cta");
        } else {
          addMessage(dom, "bot", renderBotText(renderedAnswer));
          setLastResponseType(hasUrlInText(renderedAnswer) ? "cta" : "ai");
        }
        const updated = getMemory();
        updated.push({ role: "assistant", content: renderedAnswer });
        saveMemory(updated);
        state.lastGoodAnswer = renderedAnswer;
        state.retryCount = 0;
        state.offline = false;
      } catch (err) {
        try { typing.remove(); } catch {}
        state.retryCount++;
        if ( state.retryCount === 1 ) {
          // Si el backend informa claramente el problema (p.ej. falta API key),
          // muéstralo en vez de "no tengo conexión".
          const backendMsg = err && err.message ? String(err.message) : "";
          const isGeneric =
            backendMsg === "" ||
            backendMsg === "AI_FAIL" ||
            backendMsg === "AI_RESPONSE_INVALID";
          if (!isGeneric) {
            addMessage(dom, "bot", renderBotText(backendMsg));
            state.retryCount = 2;
            return;
          }

          if (state.lastUserMessage && state.pendingInputQueue.length === 0) {
            setTimeout(() => {
              if (requestId === state.activeRequestId && state.pendingInputQueue.length === 0) {
                askAI(dom, state.lastUserMessage);
              }
            }, 1200);
            return;
          }
        }
        handleOfflineFallback(dom);
      } finally {
        if (requestId === state.activeRequestId) {
          state.isTyping = false;
          setConversationOwner("IDLE");
          setUIBusy(dom, false);
        }
      }
    }

    function bootConversation(dom) {
      updateHumanButtonVisibility(dom);
      setHeaderMeta(
        dom,
        state.leadFlow.enabled && !state.leadFlow.completed
          ? "Captación guiada activa"
          : "Listo para conversar"
      );
      if (inlineMode && dom.root.classList.contains("ecb-inline-landing")) {
        if (state.chatState === "lead_captured" || state.leadFlow.completed) {
          showPostLeadBlock(dom);
          return;
        }
        if (!state.skipDefaultWelcome) {
          if (state.leadFlow.enabled && !state.leadFlow.completed) {
            showConversionIntro(dom);
          } else {
            addMessage(dom, "bot", "¿Cómo te podemos ayudar hoy?");
          }
        }
        state.skipDefaultWelcome = false;
        if (!dom.actions.childElementCount) {
          renderConfiguredButtons(dom.actions);
        }
        return;
      }

      if (state.chatState === "lead_captured" || state.leadFlow.completed) {
        showPostLeadBlock(dom);
        return;
      }
      if (botName) {
        addMessage(dom, "bot", renderBotText(`Hola, soy ${botName}.`));
      }
      if (state.leadFlow.enabled && !state.leadFlow.completed) {
        showConversionIntro(dom);
        return;
      }
      addMessage(dom, "bot", "¿En qué te puedo ayudar?");
      renderConfiguredButtons(dom.actions);
    }

    function openQuickMenu(dom) {
      if (state.leadFlow.active) {
        addMessage(
          dom,
          "bot",
          "Primero terminemos tu captación de datos. Después te muestro el menú completo."
        );
        return;
      }
      if (state.leadFlow.enabled && !state.leadFlow.completed) {
        renderLeadIntentButtons(dom.actions);
        addMessage(dom, "bot", "Aquí tienes las intenciones iniciales.");
        return;
      }
      renderConfiguredButtons(dom.actions);
      addMessage(dom, "bot", "Listo, aquí tienes el menú de opciones.");
    }

    async function start() {
      state.chatState = loadChatState();
      state.sessionId = getSessionId();
      const dom = buildBaseDOM();
      applyFloatingPosition(dom);
      isolateChatInteractions(dom);
      let restoredConversation = false;

      if (isValidUrl(bubbleImageUrl)) {
        dom.floatBtn.style.backgroundImage = `url("${bubbleImageUrl.replaceAll('"', "%22")}")`;
        dom.floatBtn.style.backgroundSize = "cover";
        dom.floatBtn.style.backgroundPosition = "center";
        if (dom.floatIcon) dom.floatIcon.textContent = "";
      }

      const header = dom.panel.querySelector(".ecb-header");
      const resetBtn = document.createElement("button");
      resetBtn.className = "ecb-header__reset";
      resetBtn.type = "button";
      resetBtn.textContent = "↺";
      resetBtn.title = "Nueva conversación";
      header.appendChild(resetBtn);
      resetBtn.onclick = () => resetConversation(dom);

      const menuBtn = document.createElement("button");
      menuBtn.className = "ecb-header__menu";
      menuBtn.type = "button";
      menuBtn.textContent = "≡";
      menuBtn.title = "Mostrar menú";
      header.appendChild(menuBtn);
      menuBtn.onclick = () => openQuickMenu(dom);

      if (humanEscalationEnabled) {
        const humanBtn = document.createElement("button");
        humanBtn.className = "ecb-header__human";
        humanBtn.type = "button";
        humanBtn.textContent = "👤";
        humanBtn.title = "Hablar con humano";
        header.appendChild(humanBtn);
        humanBtn.onclick = () => startHumanEscalation(dom);
      }
      if (dom.humanBtn && humanEscalationEnabled) {
        dom.humanBtn.onclick = () => {
          markConversationStarted(dom);
          startHumanEscalation(dom);
        };
      }

      if (!inlineMode) {
        dom.floatBtn.onclick = (e) => {
          e.stopPropagation();
          markUserInteracted();
          const open = dom.panel.classList.toggle("ecb-panel--open");
          saveOpenState(open);
          if (open) {
            hideBadge(dom);
            ensureInitialLeadOptionsVisible(dom);
            // Analytics: chatbot_open — emite solo la primera vez que el usuario abre
            logClientEvent({ interaction_type: "button_click", label: "chatbot_open" });
            trackAnalyticsEvent("chatbot_opened", {
              chatbot_session_key: state.sessionId || "",
            });
          }
        };
        dom.closeBtn.onclick = (e) => {
          e.stopPropagation();
          markUserInteracted();
          dom.panel.classList.remove("ecb-panel--open");
          saveOpenState(false);
          trackAnalyticsEvent("chatbot_closed", {
            chatbot_session_key: state.sessionId || "",
          });
        };

        // Cerrar al hacer click fuera (compatible con Shadow DOM)
        document.addEventListener("click", (e) => {
          if (!dom.panel.classList.contains("ecb-panel--open")) return;

          const path = e.composedPath ? e.composedPath() : [];
          const clickedInsidePanel = path.includes(dom.panel);
          const clickedFloatBtn = path.includes(dom.floatBtn);

          if (!clickedInsidePanel && !clickedFloatBtn) {
            dom.panel.classList.remove("ecb-panel--open");
            saveOpenState(false);
            trackAnalyticsEvent("chatbot_closed", {
              chatbot_session_key: state.sessionId || "",
            });
          }
        });

        // Cerrar con tecla ESC
        document.addEventListener("keydown", (e) => {
          if (e.key === "Escape" && dom.panel.classList.contains("ecb-panel--open")) {
            dom.panel.classList.remove("ecb-panel--open");
            saveOpenState(false);
            trackAnalyticsEvent("chatbot_closed", {
              chatbot_session_key: state.sessionId || "",
            });
          }
        });
        if (loadOpenState()) {
          dom.panel.classList.add("ecb-panel--open");
          hideBadge(dom);
          ensureInitialLeadOptionsVisible(dom);
        }
      } else {
        dom.panel.classList.add("ecb-panel--open");
        hideBadge(dom);
      }

      if (dom.inlineNewChatBtn) {
        dom.inlineNewChatBtn.onclick = () => {
          dom.root.classList.remove("ecb-chat-started");
          dom.root.classList.add("ecb-inline-landing");
          resetConversation(dom);
        };
      }
      if (dom.inlineShowMenuBtn) {
        dom.inlineShowMenuBtn.onclick = () => {
          openQuickMenu(dom);
          dom.input.focus();
        };
      }

      dom.input.addEventListener("input", () => {
        markUserInteracted();
        hideBadge(dom);
      });

      dom.actions.addEventListener("click", async (e) => {
        const btn = e.target.closest(".ecb-btn");
        if (!btn || state.isTyping || state.processingInput) return;
        const selected = state.currentButtons[btn.dataset.idx];
        if (!selected) return;
        const selectedText = selected.value || selected.label || "";

        markConversationStarted(dom);
        addMessage(dom, "user", escapeHTML(selected.label));
        logClientEvent({
          interaction_type: "button_click",
          label: selected.label || "",
          payload: selected.value || "",
        });
        if (hideMenuOnClick) renderActionButtons(dom.actions, []);

        if (selected.type === "human_escalation" && humanEscalationEnabled) {
          startHumanEscalation(dom);
          return;
        }
        if (selected.type === "human_whatsapp_direct") {
          openHumanEscalationWhatsApp(dom, "human_choice_whatsapp");
          return;
        }
        if (selected.type === "human_contact_lead") {
          startHumanLeadCapture(dom);
          return;
        }
        if (selected.type === "lead_continue_existing") {
          continueWithStoredLead(dom);
          return;
        }
        if (selected.type === "lead_refresh_existing") {
          const pendingStart = state.pendingLeadStart;
          state.pendingLeadStart = null;
          if (pendingStart) {
            addMessage(dom, "bot", renderBotText("Perfecto 👌\n\nActualicemos tus datos. Partamos de nuevo:"));
            iniciarCapturaLead(dom, pendingStart);
          }
          return;
        }
        if (selected.type === "contact_validation_correct") {
          const pending = state.pendingContactCorrection;
          state.pendingContactCorrection = null;
          if (pending?.field) {
            addMessage(dom, "bot", renderBotText(pending.field.label));
          }
          return;
        }
        if (selected.type === "contact_validation_continue") {
          const pending = state.pendingContactCorrection;
          state.pendingContactCorrection = null;
          if (pending?.field) {
            const field = pending.field;
            delete state.leadFlow.data[field.key];
            state.leadFlow.data[`${field.key}_raw`] = pending.value;
            state.leadFlow.awaitingLead = false;
            state.leadFlow.index += 1;
            syncLeadStateSnapshot();
            promptCurrentLeadStep(dom);
          }
          return;
        }
        if (selected.type === "lead_intent") {
          // 2.0.93 Bug B: button label may itself be a disambiguation option (e.g. "Ver proyectos en Nancagua").
          // Without setting selectedContext here, handleUserMessage fires askForDisambiguation again
          // even though the user already made a choice. Detect and pre-set to avoid the re-prompt loop.
          if (!String(state.selectedContext || "").trim()) {
            const mentionedCtx = getMentionedDisambiguationOption(selected.label);
            if (mentionedCtx) {
              state.selectedContext = mentionedCtx;
            }
          }
          await processUserMessage(dom, selected.label, {
            source: "button",
            intentId: selected.value,
            intentLabel: selected.label,
          });
          return;
        }
        if (selected.type === "post_lead_whatsapp" && isValidUrl(selected.value)) {
          registerWhatsAppClick();
          setLastResponseType("whatsapp_cta");
          window.open(selected.value, "_blank", "noopener");
          return;
        }
        if (selected.type === "post_lead_deferred") {
          state.lastAction = "postLead";
          state.conversationClosed = true;
          renderActionButtons(dom.actions, []);
          addMessage(dom, "bot", renderBotText("Listo.\n\nUn asesor te contactará dentro de las próximas horas 👌"));
          saveChatState("lead_captured");
          setLastResponseType("conversation_closed");
          trackAnalyticsEvent("chatbot_completed", {
            chatbot_session_key: state.sessionId || "",
          });
          return;
        }
        if (selected.type === "post_lead_reset") {
          resetConversation(dom);
          return;
        }
        if (selected.type === "link" && isValidUrl(selected.value)) {
          window.open(selected.value, "_blank", "noopener");
          return;
        }
        if (isHotIntentText(selectedText)) {
          triggerUrgentClose(dom, "button");
          return;
        }

        state.lastUserMessage = selectedText;
        state.retryCount = 0;
        await processUserMessage(dom, state.lastUserMessage, { source: "button" });
      });

      dom.messages.addEventListener("click", (e) => {
        const whatsAppLink = e.target.closest("[data-ecb-whatsapp-cta='1']");
        if (whatsAppLink) {
          registerWhatsAppClick();
          return;
        }
        const genericLinkButton = e.target.closest("[data-ecb-link-url]");
        if (!genericLinkButton) return;
        const url = String(genericLinkButton.getAttribute("data-ecb-link-url") || "").trim();
        if (!isValidUrl(url)) return;
        try {
          window.open(url, "_blank", "noopener");
        } catch {}
      });

      dom.send.onclick = async () => {
        const txt = dom.input.value.trim();
        if (!txt) return;
        dom.input.value = "";
        markConversationStarted(dom);
        addMessage(dom, "user", escapeHTML(txt));
        trackAnalyticsEvent("chatbot_message_sent", {
          chatbot_session_key: state.sessionId || "",
        });
        if (txt.startsWith("/") && handleCommand(dom, txt)) return;
        await processUserMessage(dom, txt, { source: "message" });
      };

      dom.input.onkeydown = (e) => {
        if (e.key === "Enter") dom.send.click();
      };

        restoredConversation = await hydrateConversation(dom);
      if (restoredConversation) {
        setHeaderMeta(
          dom,
          state.leadFlow.completed ? "Lead captado" : "Conversación recuperada"
        );
        if (state.chatState === "lead_captured") {
          renderPostLeadButtons(dom);
        }
      } else {
        if (flowEnabled && flowCfg.intro.length) {
          flowCfg.intro.forEach((line) => {
            addMessage(dom, "bot", renderBotText(String(line)));
          });
          if (flowCfg.tags.length) {
            try {
              sessionStorage.setItem("ecb_flow_tags", JSON.stringify(flowCfg.tags));
            } catch {}
          }
          state.skipDefaultWelcome = true;
        }
        bootConversation(dom);
      }

      scheduleAutoOpen(dom, restoredConversation);
    }

    start();
  }

  function bootAll() {
    updateViewportHeightVar();
    mounts.forEach((mount, index) => initInstance(mount, index));

    const hasInlineShortcode = mounts.some(
      (mount) => String(mount?.dataset?.mode || "inline").toLowerCase() !== "widget"
    );
    const hasWidgetShortcode = mounts.some(
      (mount) => String(mount?.dataset?.mode || "inline").toLowerCase() === "widget"
    );

    if (!hasInlineShortcode && !hasWidgetShortcode) {
      initInstance(null, mounts.length);
    }
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", bootAll, { once: true });
  } else {
    bootAll();
  }

  window.addEventListener("resize", updateViewportHeightVar, { passive: true });
  window.addEventListener("orientationchange", updateViewportHeightVar, { passive: true });
})();
