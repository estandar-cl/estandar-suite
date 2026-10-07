(function () {
  "use strict";

  function normalizeIntentText(value) {
    const normalized = String(value || "")
      .toLowerCase()
      .normalize("NFD")
      .replace(/[\u0300-\u036f]/g, "")
      .trim();

    return normalized
      .replace(/\b(kiero|qiero|kiro|qro)\b/g, "quiero")
      .replace(/\b(kontratar|contrtar|contratarr)\b/g, "contratar")
      .replace(/\b(kotizar|cotisar|cotisar|cotisacion)\b/g, "cotizar")
      .replace(/\b(wsp|wasap|watsap|guasap)\b/g, "whatsapp")
      .replace(/\b(price|cost|pricing|quote|quotation|how much)\b/g, "precio")
      .replace(/\b(contact|call|advisor|human|agent)\b/g, "contactar")
      .replace(/\b(buy|purchase|hire|contract)\b/g, "contratar")
      .replace(/\b(info|information|details)\b/g, "informacion");
  }

  function extractWebsiteInput(message) {
    const source = String(message || "").trim();
    if (!source) {
      return "";
    }

    const match = source.match(
      /(?:^|[\s:])((?:https?:\/\/|www\.)?[a-z0-9][a-z0-9-]{0,62}(?:\.[a-z0-9-]{1,63})+(?:\/[^\s]*)?)/i
    );
    const candidate = String(match?.[1] || "").trim().replace(/[),.;!?]+$/, "");
    if (!candidate || candidate.includes("@")) {
      return "";
    }

    const normalized = /^https?:\/\//i.test(candidate) ? candidate : `https://${candidate}`;

    try {
      const parsed = new URL(normalized);
      return parsed.hostname ? normalized : "";
    } catch {
      return "";
    }
  }

  function isUrlInput(message) {
    return !!extractWebsiteInput(message);
  }

  function isConsultativeServiceIntent(message) {
    const normalized = normalizeIntentText(message);
    if (!normalized) {
      return false;
    }

    return /(renta|declaracion de renta|declaración de renta|contabilidad|contador|tributaria|tributario|remuneraciones|payroll|inicio de actividades|cambio de contador|cambiar de contador)/i.test(
      normalized
    );
  }

  function classifyIntent(message) {
    const normalized = normalizeIntentText(message);

    if (!normalized) {
      return null;
    }

    if (isUrlInput(message)) {
      return "website_input";
    }

    if (/(quiero mas informacion|quiero más información|mas informacion|más información|informacion|información)/i.test(normalized)) {
      return "info";
    }

    if (
      /(whatsapp|contactar|contacten|contacto|llamar|llamen|llame|hablar.*con.*alguien|hablar.*con.*una persona|asesor|humano|联系|聯繫|联系我|客服|顾问|顧問|人工)/i.test(
        normalized
      )
    ) {
      return "contact";
    }

    if (
      /(quiero contratar|quiero cotizar|quiero reservar|quiero agendar|quiero avanzar|quiero comprar|contratar ahora|cotizar ahora|reservar ahora|agendar ahora|avanzar ahora|comprar ahora|pagar ahora|ahora mismo|lo quiero ahora|quiero hacerlo|购买|購買|买|買| contratar|cotizar|reservar|agendar|comprar|pagar)/i.test(
        normalized
      )
    ) {
      return "high_intent_strong";
    }

    if (isConsultativeServiceIntent(normalized)) {
      return "consultative_service_intent";
    }

    if (
      /(cuanto tengo que pagar|cuanto cuesta|cuánto cuesta|precio|precios|costo|costos|valor|vale|cuanto vale|cuánto vale|cotizacion|cotización|plan|价格|價錢|多少钱|多少錢|报价|報價|费用|費用)/i.test(
        normalized
      )
    ) {
      return "price";
    }

    if (
      /(no se|no sé|conviene|vale la pena|es mucho|es poco|me da miedo|me preocupa)/i.test(
        normalized
      )
    ) {
      return "doubt";
    }

    if (/(contratar|comprar|avanzar|pagar)/i.test(normalized)) {
      return "high_intent";
    }

    return null;
  }

  function resolveDecision(input) {
    const data = input && typeof input === "object" ? input : {};
    const intent = String(data.intent || "").trim();
    const state = data.state && typeof data.state === "object" ? data.state : {};
    const signals = data.signals && typeof data.signals === "object" ? data.signals : {};

    if (signals.deliveredData && signals.hasCompleteLead === true) {
      return "lead_complete";
    }

    if (intent === "website_input" && signals.enableWebsiteDemo === true) {
      return "website_input";
    }

    if (intent === "contact") {
      return signals.hasWhatsAppCta ? "whatsapp" : "fallback_ia";
    }

    if (intent === "high_intent_strong") {
      return "direct_close_with_optional_context";
    }

    if (signals.deliveredData && signals.hasAnyLead === true) {
      return "lead_store";
    }

    if (state.leadCaptured === true || state.chatState === "lead_captured") {
      return "post_lead";
    }

    switch (intent) {
      case "info":
        return "fallback_ia";

      case "price":
        if (signals.useContextualAI === true) {
          return "fallback_ia";
        }
        return signals.isTaxQuestion ? "cta_tax" : "cta_price";

      case "high_intent":
        return "cta_direct";

      case "doubt":
        return "handle_objection";

      default:
        return "fallback_ia";
    }
  }

  window.EstandarChatbotDecisionEngine = {
    extractWebsiteInput,
    isUrlInput,
    isConsultativeServiceIntent,
    normalizeIntentText,
    classifyIntent,
    resolveDecision,
  };
})();
