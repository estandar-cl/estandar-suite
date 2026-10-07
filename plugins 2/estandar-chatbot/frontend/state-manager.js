(function () {
  "use strict";

  function createState(options) {
    const config = options && typeof options === "object" ? options : {};
    const leadFields = Array.isArray(config.leadFields) ? config.leadFields : [];

    return {
      chatState: "default",
      lastAction: null,
      conversationClosed: false,
      currentStep: 0,
      awaitingLead: false,
      leadCaptured: false,
      lastIntent: "",
      lastUserIntent: "",
      intent: null,
      isTyping: false,
      currentButtons: [],
      lastUserMessage: null,
      lastGoodAnswer: null,
      retryCount: 0,
      offline: false,
      sessionId: "",
      userWebsite: "",
      selectedContext: "",
      pendingDisambiguation: null,
      skipDefaultWelcome: false,
      lastResponseType: "",
      leadFlow: {
        enabled: config.leadCaptureEnabled === true,
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
  }

  window.EstandarChatbotStateManager = {
    createState,
  };
})();
