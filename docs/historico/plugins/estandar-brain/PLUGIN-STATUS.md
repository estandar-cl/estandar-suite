# PLUGIN-STATUS — estandar-brain

**Última sesión:** 2026-08-03 · **Estado:** estable · **Tests:** 25/25 PASS

## Lo que se hizo en esta sesión (plugin más trabajado)
- `includes/Agent.php` — **motor del agente** (un motor, dos perfiles):
  - Tools con `scope` (internal|public) y `level` (read|write|destructive).
  - `Agent::run($peticion, $perfil)`: internal = todas + passthrough WP-CLI + confirm;
    public = solo `scope=public` (conocimiento + enlaces UI), NUNCA WP-CLI.
  - `plan()/classify()/execute()`, `collect_emails()`, `contact_data()`,
    `activation()` (toggles), auditoría en `estandar_agent_logs`.
- `includes/Knowledge.php` — índice `estandar_chatbot_knowledge` (páginas+posts →
  fragmentos ~600 chars con cita). `index_all()/search()/clear()`. Fix chunking por
  palabras (mb_strpos con offset > largo lanzaba ValueError).
- `includes/PublicChat.php` — **burbuja única reutilizable** `bubble($opts)`:
  modos floating|inline, perfiles internal|public, nonce interno, botón Confirmar.
  Se usa en: admin_footer (toda la suite), shortcode `[estandar_agent]`, sub-tab
  Agente (Brain) y sub-tab Chat (Capture).
- `includes/Rest.php` — `/agent` (interno + confirm) y `/agent-public` (rate-limit
  por IP 30/min). Ambos con gate de `activation()`.
- `includes/Admin.php` — sub-tabs config: **señales** (es_signal_events),
  **exportadores** (Meta/Google), **agente** (Activación + Chat + Conocimiento +
  Historial). `adopt_legacy_credentials()` (nunca pisa/borra legado).
  `handle_save_agent_activation()`.
- `includes/Cli.php` — `agent run|chat|history|tools`, `knowledge index|list|clear`,
  `exporters settings get|set|adopt`, `visibility reset`.

## Estado actual
- Agente interno: por reglas (keywords+regex), NO usa LLM. La key de LLM de la sala de
  pruebas es `chatbot_openai_api_key` (legado, solo la usa el chatbot legado).
- Activación por toggles: `estandar_suite_brain_agent_settings`
  (agent_internal / bubble_admin / agent_public). WP-CLI no se desactiva.
- Fuera de alcance (decisión usuario): el agente NO escribe/repara código desde dentro.

## Puntos de entrada
- `includes/Agent.php` (motor) · `Knowledge.php` (índice) · `PublicChat.php` (burbuja) ·
  `Rest.php` (endpoints) · `SignalLedger.php` (señales/exportación).

## Notas
- Dependencia: Brain lee settings de Capture (contacto); Capture llama a Brain (motor).
- Para nuevas tools: definir `level`+`scope`+`match`, ejecutor `do_<tool>` con API oficial.
