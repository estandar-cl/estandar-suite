# PLUGIN-STATUS — estandar-capture

**Última sesión:** 2026-08-03 · **Estado:** estable · **Tests:** 35/35 PASS

## Lo que se hizo en esta sesión
- `includes/Admin.php`: `adopt_leads_credentials()` — absorbe credenciales Meta/Google
  del legado (`estandar_leads_meta_*`, `estandar_leads_google_*`) a las settings v2
  (first-writer-wins, NUNCA pisa ni borra el legado; flag + CLI `adopt`).
  `popups_settings_groups()`, `leads_settings_groups()`, `chatbot_settings_groups()` +
  handlers de save por grupo.
- `includes/templates/feature-chatbot.php`: nuevo sub-tab **Chat (agente público)** que
  usa el motor de Brain (burbuja inline → endpoint `/agent-public`). Se quitaron los
  sub-tabs `analitica` y `leads` (su contenido vive en Insights → Analytics → Chatbot y
  Capture → Leads → Chatbot). El JS duplicado se reemplazó por `PublicChat::bubble()`.
- `includes/templates/feature-leads.php`: sub-tab **Chatbot** (leads capturados por el
  chatbot, ecb_leads) + KPI en resumen. 11 sub-sub-tabs.
- `includes/templates/feature-popups.php`: 5 sub-sub-tabs (resumen/popups/tracking/
  configuracion/settings).

## Estado actual
- Chatbot: solo operativo (Conversación/Captación/IA/Apariencia/Conversaciones/Logs/
  Sugerencias/Integraciones/Sistema) + Chat (agente público de Brain). Analítica y
  leads delegados a las áreas especializadas (sin duplicación).
- El motor de chat público NO vive aquí: vive en Brain (`[estandar_agent]`).

## Puntos de entrada
- `includes/Admin.php` — features, settings groups, handlers, `feature_data()`.
- `includes/templates/feature-{chatbot,leads,popups,forms}.php`.
- `includes/Forms.php`, `Popups.php` — APIs v2 (usar SIEMPRE estas, nunca SQL manual).

## Notas
- Brain lee los settings de Capture como datos (WhatsApp de escalada = `human_whatsapp_number`,
  email = `notify_email_to`) para las acciones públicas del agente.
- Dirección de dependencia: Capture → llama a Brain (motor). Brain → lee settings de Capture.
