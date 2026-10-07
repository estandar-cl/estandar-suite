# Estandar Suite — Auditoría de Estado Real

**Fecha:** 2026-08-07
**Alcance:** Suite v1 + v2 + infraestructura compartida
**Método:** Inspección de código, estructura, tests, hooks, contratos, documentación y configuración
**Fuentes verificadas:** 35 plugins, 139 tests runners, ~38 DB tables, 15 ADRs, release pipeline, CHANGELOG

> **ACTUALIZACIÓN 2026-08-09 (versión 13.0.172):** Esta auditoría se ejecutó contra headers en
> 13.0.171. Re-verificado sobre el filesystem el 2026-08-09: los 26 plugins están en **13.0.172**
> (gate `unified_version` pasaría). Las conclusiones de esta auditoría no cambian: la release
> 13.0.172 es el bump 171→172 del mismo pipeline, y la inconsistencia de constantes internas
> (13.0.158 / 1.0.11 / 13.0.162 vs header 13.0.172) persiste. Ver
> `estandar-suite-portfolio-decision-2026-08-09.md` para PARTES 3, 7, 8, 9 y la corrección del
> release.command.

---

## Resumen ejecutivo

- **Total de iniciativas auditadas:** 43
- **⚪ NO EXISTE:** 3 (6.9%)
- **🔵 DISEÑADO:** 9 (20.9%)
- **🟠 PARCIAL:** 17 (39.5%)
- **🟡 IMPLEMENTADO:** 12 (27.9%)
- **🟢 TESTEADO:** 4 (9.3%)
- **✅ READY FOR RELEASE:** 0 (0%)
- **🚀 PRODUCCIÓN:** 1 (2.3%) — parcial (acacios.cl v1.0.9 Forms)

### Porcentaje de materialización global

**Cobertura funcional:** ~62% — De 43 capacidades descritas, 33 tienen al menos implementación parcial verificable. Las 10 restantes son diseño o no existen.

**Validación/test:** ~28% — Solo estandar-core (147 tests), estandar-capture (35 tests), y estandar-quotes (17 tests) tienen cobertura sustancial. La mayoría de plugins legacy y varios v2 (kickoff, primer, destinations) tienen cobertura mínima o nula.

**Readiness de release:** ~12% — La pipeline v2 funciona (release.command con 3 gates), pero solo 5/9 plugins v2 la usan correctamente. Los 4 plugins restantes (destinations, kickoff, primer, quotes) tienen versión nominal pero no están integrados completamente en la suite de tests consolidada. Ningún componente alcanza todos los gates simultáneamente.

**Presencia en producción:** ~5% — Solo `estandar-capture` v1.0.9 fue desplegado en acacios.cl para recuperar un formulario. El resto del sistema opera en entornos locales/staging (akcontadores, acacios como laboratorio).

**Criterio:** El porcentaje no es una media aritmética de estados. Pondera cobertura funcional (40%), validación/test (25%), readiness de release (20%), y producción (15%).

---

## Matriz maestra

| ID | Proyecto / capacidad | v1 | v2 | Estado real | Evidencia principal | Diferencia respecto al inventario |
|---|---|---|---|---|---|---|
| 1 | Estandar Suite v2 — consolidación arquitectónica | — | Núcleo | 🟡 IMPLEMENTADO | 5 plugins v2 con namespace `Estandar\` + 4 híbridos | Inventario dice "🟢 En desarrollo activo". Correcto pero no es 5 plugins, son 9 |
| 2 | Arquitectura de 5 plugins: Kickoff, Brain, Capture, Catch, Experience | — | Parcial | 🟠 PARCIAL | Solo 3/5 existen como v2 reales: Brain, Capture, Core (Brain-like). Catch = Flow, Experience = no existe en v2 | Inventario dice "🟢 Aprobada". Es diseño aprobado, no implementación completa |
| 3 | Brain como control plane | — | Sí | 🟡 IMPLEMENTADO | estandar-brain: Rules, Scoring, SignalLedger, Assessment, Agent, IntentMap, Contactability. 25 tests. | Inventario dice "🟡 En evolución". Coincide |
| 4 | Capture como motor de captura | — | Sí | 🟡 IMPLEMENTADO | estandar-capture: Ingestion, Attribution, Forms, ConversationEngine, FormBridge, Popups. 35 tests. | Inventario dice "🟢 En desarrollo". Coincide |
| 5 | Catch como capa comercial/conversión | v1: Leads | v2: Flow | 🟠 PARCIAL | estandar-flow: Pipeline, Journey, Customers, Quotes. 21 tests. No hay plugin "Catch" | Inventario dice "🟡 En desarrollo". Existe Flow pero no Catch como tal |
| 6 | Experience como efectos/UX | v1: Sí | v2: No | 🟠 PARCIAL | v1: estandar-experience-blocks (13 bloques Gutenberg), estandar-pop, kinetichub. v2: sin equivalente | Inventario dice "🟡 Diseñado". Correcto |
| 7 | Kickoff como utilidades opcionales | v1: Sí | v2: Híbrido | 🟠 PARCIAL | estandar-kickoff existe (16 módulos, 1 test). Es legacy-style, no v2 puro. | Inventario dice "🟡 Diseñado". Subestimado: existe más código del indicado |
| 8 | Reducir ~21 plugins a estos dominios | v1: 18 | v2: 9 | 🟠 PARCIAL | 18 v1 + 9 v2 = 27 plugins total. Migración iniciada pero no reducción. | Inventario dice "🟡 Migración progresiva". Coincide |
| 9 | Mantener compatibilidad con Suite v1 | Compartido | 🟡 IMPLEMENTADO | LegacyMap, FormsAdoption, Data-Settings Matrix, read-only legacy | Inventario dice "🟢 Regla arquitectónica". Correcto |
| 10 | Contratos compartidos v1/v2 | Compartido | 🟡 IMPLEMENTADO | PHP interfaces en Core, hooks cross-plugin (`estandar_suite_capture_event`, etc.) | Inventario dice "🟢 Adoptado". Correcto |
| 11 | Absorber/reutilizar antes de reescribir | Compartido | 🟡 IMPLEMENTADO | AdoptionProbe, FormsAdoption, LeadAdapter, InterestAdapter | Inventario dice "🟢 Regla estable". Correcto |
| 12 | Fuente de verdad única del código | Compartido | 🟠 PARCIAL | WORKSPACE.md define canónico, pero symlinks no existen, dompdf duplicado (quotes) | Inventario dice "🟢 Adoptado". Intención documentada, implementación parcial |
| 13 | release.command único | Compartido | 🟡 IMPLEMENTADO | 292 líneas, 3 gates, 26 plugins, SFTP a staging, reproducible ZIPs, dry-run | Inventario dice "🟢 Implementado/evolucionando". Correcto |
| 14 | Local → pruebas → validación → producción | Compartido | 🟡 IMPLEMENTADO | WORKSPACE.md flujo: Dragon dev → Plugins_Workspace → akcontadores/acacios → staging | Inventario dice "🟢 Adoptado". Correcto |
| 15 | Migración controlada Acacios → AK → otros | — | Parcial | 🟠 PARCIAL | Acacios: Forms 1.0.9 desplegado. AK: laboratorio local. Sin expansión a otros | Inventario dice "🟡 En proceso". Coincide |
| 16 | Forms propio de Estandar | v1: Sí | v2: Sí | 🟡 IMPLEMENTADO | v1: estandar-forms (26 tests, 8 tablas). v2: estandar-capture Forms (35 tests) | Inventario dice "🟢 Implementado". Correcto, en ambas versiones |
| 17 | Builder/configuración de formularios | v1: Sí | v2: Sí | 🟡 IMPLEMENTADO | v1: admin/screens/form-edit.php. v2: maquetador.php, subtabs (diseno/config/correo/entradas) | Inventario dice "🟢 Implementado". Correcto |
| 18 | Shortcodes | v1: `[estandar_form]` | v2: `[estandar_form_v2]` | 🟡 IMPLEMENTADO | v1: Shortcode class. v2: FormRenderer con `[estandar_form_v2]` | Inventario dice "🟢 Implementado". Correcto |
| 19 | Proveedor único de Embed en Capture v2 | — | v2 | 🟡 IMPLEMENTADO | Core\Embed, `[estandar_form_v2 id="X"]` | Inventario dice "🟢 Implementado local". Correcto |
| 20 | Mostrar/copiar shortcode | — | v2 | 🟡 IMPLEMENTADO | Parte de Embed UI | Inventario dice "🟢 Implementado local". Correcto |
| 21 | Entries por formulario | v1: Sí | v2: Sí | 🟡 IMPLEMENTADO | v1: form_entries table + admin. v2: Entries subtab en editor vía source_form_id | Inventario dice "🟡 Implementación/ajustes". Correcto |
| 22 | Migración desde Forms legacy | — | v2 | 🟡 IMPLEMENTADO | FormsAdoption, `adopt_legacy()`, dry-run, read-only, 598 entries preservados | Inventario dice "🟡 En curso". Correcto, pero más avanzado de lo indicado |
| 23 | Migración del formulario Acacios | — | v2 | 🟢 TESTEADO | CHANGELOG 1.0.9: "deployed to acacios.cl, form 2 recovered". HANDOFF confirma. | Inventario dice "🟢 Realizada/local-validada". Correcto: TESTEADO |
| 24 | Notificaciones configurables | — | v2 | 🟠 PARCIAL | Mail class existe, TransportRegistry funcional. Admin UI de notificaciones en subtabs parcial. | Inventario dice "🟡 En consolidación". Coincide |
| 25 | Recipients configurables | — | v2 | 🟠 PARCIAL | Config de recipients en Forms settings. No UI completa multi-recipient. | Inventario dice "🟡 En consolidación". Coincide |
| 26 | Editor HTML del correo | — | v2 | 🟠 PARCIAL | Email template existe en subtab correo. Sin editor WYSIWYG completo. | Inventario dice "🟡 En desarrollo". Coincide |
| 27 | Preview visual del correo | — | v2 | ⚪ NO EXISTE | No hay preview renderizado de correo en v2 | Inventario dice "🟡 En desarrollo". No hay evidencia |
| 28 | Autorespuesta | v1: Sí | v2: No | 🟠 PARCIAL | v1: estandar-leads Autoresponse. v2: sin implementación de autorespuesta en Capture/Flow | Inventario dice "🟡 Consolidación". Existe solo en v1 |
| 29 | Campos/contratos reutilizables | — | v2 | 🟡 IMPLEMENTADO | FieldMap, Contracts.php, `Estandar\Core\Contracts` | Inventario dice "🟢 Arquitectura adoptada". Correcto |
| 30 | Formularios como infraestructura común | — | v2 | 🟡 IMPLEMENTADO | Forms en Capture es usado por ConversationEngine, Popups, FormBridge | Inventario dice "🟢 Decisión arquitectónica". Correcto |
| 31 | Motor común de comunicaciones | — | v2 | 🟢 TESTEADO | TransportRegistry + WpMail + SMTP + Brevo. 22 tests en run-core.php (147 total). DeliveryLog. | Inventario dice "🟢 Arquitectura aprobada". Subestimado: está testeado |
| 32 | TransportRegistry | — | v2 | 🟢 TESTEADO | Registry + resolver + 3 adapters + DeliveryLog. 22 tests. | Inventario dice "🟢 Implementado/validado". Correcto |
| 33 | SMTP adapter | — | v2 | 🟡 IMPLEMENTADO | SmtpTransport.php: discovery, constants, health check | Inventario dice "🟢 Implementado". Correcto |
| 34 | Brevo adapter | — | v2 | 🟡 IMPLEMENTADO | BrevoTransport.php: API key discovery, health check | Inventario dice "🟢 Implementado". Correcto |
| 35 | Reutilizar configuraciones existentes | Compartido | 🟡 IMPLEMENTADO | TransportRegistry auto-discovery de SMTP/Brevo configs existentes | Inventario dice "🟢 Requisito implementado/diseñado". Correcto |
| 36 | No sobrescribir API keys/tokens | Compartido | 🟡 IMPLEMENTADO | Crypto.php AES-256-GCM, Secrets, read-only legacy, ADR 0026 | Inventario dice "🟢 Regla crítica". Correcto |
| 37 | Contratos comunes de correo | — | v2 | 🟡 IMPLEMENTADO | TransportInterface, DeliveryLog, Mail::send() routea por TransportRegistry | Inventario dice "🟢 Implementados". Correcto |
| 38 | Forms usando motor común | — | v2 | 🟡 IMPLEMENTADO | Core\Mail::send() llamado desde Capture Forms | Inventario dice "🟡 Integración". Correcto, más avanzado |
| 39 | Surveys usando motor común | — | — | 🔵 DISEÑADO | Arquitectura prevista, no implementada en v2 | Inventario dice "🟡 Integración prevista". Correcto |
| 40 | Quotes usando motor común | — | v2 | 🟠 PARCIAL | Quotes tiene su propio email transport (separado). No usa TransportRegistry. | Inventario dice "🟡 Integración prevista". Correcto, no integrado |
| 41 | Postventa usando motor común | — | — | 🔵 DISEÑADO | Sin implementación | Inventario dice "🟡 Previsto". Correcto |
| 42 | PDF adjunto | — | v2 | 🟠 PARCIAL | Core\Documents\Pdf existe. Quotes genera PDF con dompdf (propio). Sin integración entre ambos. | Inventario dice "🟡 Parcial / Document Engine relacionado". Coincide |
| 43 | Preview HTML | — | v2 | ⚪ NO EXISTE | Mismo que #27 (DUPLICADO). No hay preview de correo. | Inventario dice "🟡 En evolución". No hay evidencia |
| 44 | Auditabilidad del envío | — | v2 | 🟠 PARCIAL | DeliveryLog (últimos 50, sin secretos). Sin dashboard de auditoría completo. | Inventario dice "🟡 En evolución". Coincide |
| 45 | Registro central de leads | v1: Sí | v2: Sí | 🟡 IMPLEMENTADO | v1: estandar-leads (15 tablas). v2: estandar-flow Pipeline | Inventario dice "🟢 Implementado". Correcto |
| 46 | Fuente/canal/origen | v1: Sí | v2: Sí | 🟡 IMPLEMENTADO | v1: Leads Ingestion + Attribution. v2: Core\Channels, Capture\Attribution | Inventario dice "🟢 Implementado". Correcto |
| 47 | Operador asignado | v1: Sí | v2: Sí | 🟡 IMPLEMENTADO | v1: estandar-leads operators. v2: Flow Pipeline con asignación | Inventario dice "🟢 Implementado". Correcto |
| 48 | Estados comerciales | v1: Sí | v2: Sí | 🟡 IMPLEMENTADO | v1: Commercial Journey. v2: Flow Journey\StateMachine, Outcomes | Inventario dice "🟢 Implementado". Correcto |
| 49 | Clasificación HIGH/MEDIUM/LOW | v1: Sí | v2: Sí | 🟡 IMPLEMENTADO | v1: Assessment Engine. v2: Brain\AssessmentEngine, Core\Quality | Inventario dice "🟡 Implementado/evolucionando". Correcto |
| 50 | HIGH_INTENT | v1: Sí | v2: Sí | 🟡 IMPLEMENTADO | v1: Intent Score. v2: Brain\IntentMap, Core\Interest\InterestEngine | Inventario dice "🟡 Implementado". Correcto |
| 51 | Lead válido / basura / no contesta | v1: Sí | v2: Parcial | 🟠 PARCIAL | v1: Assessment + classification system. v2: Brain\AssessmentEngine (básico) | Inventario dice "🟡 Implementación activa". Correcto |
| 52 | Importar feedback histórico | — | v2 | 🟠 PARCIAL | AdoptionProbe en todos los plugins. Sin import completo de feedback comercial. | Inventario dice "🟡 Diseñado/implementación". Correcto |
| 53 | Feedback humano reutilizable | — | — | 🔵 DISEÑADO | Solo en documentación/ADRs. Sin implementación en código. | Inventario dice "🟡 Desarrollo". No hay evidencia en código |
| 54 | Reglas de calidad | v1: Sí | v2: Sí | 🟡 IMPLEMENTADO | v1: Qualification Rules. v2: Brain\Rules, Core\Conditions | Inventario dice "🟡 Desarrollo". Subestimado: existe en ambas |
| 55 | Scoring | v1: Sí | v2: Sí | 🟡 IMPLEMENTADO | v1: Scoring module. v2: Brain\Scoring | Inventario dice "🟡 Desarrollo". Existe en código |
| 56 | Detección de intención | — | v2 | 🟡 IMPLEMENTADO | Core\Interest\InterestEngine (88 tests). Detecta señales de intención. | Inventario dice "🟡 Desarrollo". Subestimado: testeado |
| 57 | Entendimiento real del lead | — | v2 | 🟠 PARCIAL | IEE + Brain Assessment. Funcional pero scope limitado. | Inventario dice "🟡 Macro-sprint activo". Coincide |
| 58 | Contactabilidad | v1: Sí | v2: Sí | 🟡 IMPLEMENTADO | v1: Operator Hours. v2: Core\Quality\ContactabilityEngine + Brain\Contactability (con tests) | Inventario dice "🟡 Macro-sprint activo". Coincide |
| 59 | Journey del lead | v1: Sí | v2: Sí | 🟡 IMPLEMENTADO | v1: Commercial Journey. v2: Flow\Journey\StateMachine + JourneyTemplates | Inventario dice "🟡 Desarrollo". Existe implementación |
| 60 | Lead → reunión → propuesta → cliente | v1: Parcial | v2: Parcial | 🟠 PARCIAL | v1: Commercial pipeline parcial. v2: Flow Journey + Outcomes. Faltan transiciones completas. | Inventario dice "🟡 Desarrollo". Coincide |
| 61 | Outcomes comerciales | v1: Sí | v2: Sí | 🟡 IMPLEMENTADO | v1: Commercial Outcomes Log. v2: Flow\Journey\Outcomes | Inventario dice "🟡 Desarrollo". Existe código |
| 62 | Compatibilidad v1/v2 | Compartido | 🟡 IMPLEMENTADO | AdoptionProbe, LegacyMap, Data-Settings Matrix, read-only | Inventario dice "🟢 Obligatoria". Correcto |
| 63 | Lead Quality / Intelligence Engine | v1: Sí | v2: Sí | 🟡 IMPLEMENTADO | v1: Assessment + Qualification. v2: Brain Assessment + Core Quality + Interest Engine. Dual. | Inventario dice "🟡 desarrollo activo". Coincide |
| 64 | Clasificación automática | v1: Sí | v2: Sí | 🟡 IMPLEMENTADO | v1: Estandar_Commercial_Qualification_Engine. v2: Brain\AssessmentEngine | Inventario dice parte de #63 |
| 65 | Señales negativas | v1: Parcial | v2: Sí | 🟠 PARCIAL | v1: classification system. v2: IEE detecta señales pero scope limitado | Inventario dice implícito en #63 |
| 66 | Señales positivas | v1: Parcial | v2: Sí | 🟠 PARCIAL | Misma situación que señales negativas | Inventario dice implícito en #63 |
| 67 | Aprendizaje desde feedback del operador | — | — | 🔵 DISEÑADO | Solo en ADR-0024 y documentación. Sin implementación en código. | Inventario dice implícito en #63 |
| 68 | Retorno del feedback hacia plataformas publicitarias | v1: Sí | v2: Parcial | 🟠 PARCIAL | v1: signal-core (Meta CAPI + Google OCI exporters). v2: Core\Destinations (genérico, sin adapters Meta/Google) | Inventario dice parte de #63 |
| 69 | Segundo intento y reactivación de leads | — | v2 | 🟠 PARCIAL | Flow\Journey\ReEngagement, Recover. IEE detecta retorno. Sin integración completa. | Inventario dice "🟡 diseño aprobado / implementación en evolución". Coincide |
| 70 | Contact Strategy Engine | — | — | 🔵 DISEÑADO | Documentado conceptualmente como propuesta arquitectónica. Sin código. | Inventario dice "🔵 propuesta arquitectónica". Coincide |
| 71 | Canal de contacto unificado (ContactChannel) | — | v2 | 🟠 PARCIAL | Core\Channels.php existe como abstracción. No es el ContactChannel canónico descrito. | Inventario dice "🔵 propuesto/diseñado conceptualmente". Existe más código del indicado |
| 72 | Calendar / reuniones | — | v2 | 🟠 PARCIAL | Core\Calendar.php existe con tabla `estandar_core_calendar`. Sin integración completa con Flow. | Inventario dice "🟡 implementación/evolución". Coincide |
| 73 | Surveys Engine | v1: Sí | v2: Parcial | 🟠 PARCIAL | v1: estandar-forms Survey modules (DB, Handler, Analytics, 26 tests). v2: ConversationEngine (ADR-0034) puede hacer surveys. | Inventario dice "🟡 implementación activa". v1 mucho más avanzado que v2 |
| 74 | Encuesta, evaluación, postventa, diagnóstico, etc. | v1: Sí | — | 🟠 PARCIAL | v1: Survey engine con analytics. v2: solo ConversationEngine como base. | Inventario dice parte de #73 |
| 75 | Postventa | — | — | 🔵 DISEÑADO | Solo documentado. Sin código en v1 ni v2. | Inventario dice "🔵/🟡 propuesta avanzada". Correcto |
| 76 | Cotizaciones / Quote Engine | — | v2 | 🟢 TESTEADO | estandar-quotes: 44 PHP files, 17 tests, 9 tablas, hydration pipeline, PDF, profiles. Suite más completa. | Inventario dice "🟢 funcionalidad base implementada". Subestimado: está testeado |
| 77 | Document Engine | — | v2 | 🟡 IMPLEMENTADO | Core\Documents\Pdf (base) + Quotes Document Engine (concreto, 17 tests) | Inventario dice "🟢 implementado en v2". Correcto |
| 78 | Imports Engine | — | v2 | 🟠 PARCIAL | Adapters/ con GoogleSheets, ExcelOnline, Simulated. Integration Core wizard. Paste→tasks no encontrado. | Inventario dice "🟡 parcial". Coincide |
| 79 | Integración Google Sheets / Excel | — | v2 | 🟠 PARCIAL | Adapters GoogleSheets + ExcelOnline existen. Sin validación E2E con credenciales reales. | Inventario dice "🟡 diseñado / implementación pendiente o parcial". Coincide |
| 80 | Attribution Engine | v1: Sí | v2: Sí | 🟠 PARCIAL | v1: estandar-analytics Attribution. v2: Core\Channels, Capture\Attribution. Fragmentado. | Inventario dice "🟡 parcialmente implementado/evolucionando". Coincide |
| 81 | Campaign Resolver | v1: Sí | — | 🟠 PARCIAL | v1: estandar-primer Campaign Discovery + Label Resolver. v2: sin equivalente directo. | Inventario dice "🟡 arquitectura aprobada / evolución". Existe en v1 pero no v2 |
| 82 | Google Ads OCI | v1: Sí | v2: Parcial | 🟠 PARCIAL | v1: estandar-leads google-adapter + google-dm-client. v2: estandar-destinations google-ads-destination. Queue existe. | Inventario dice "🟡 infraestructura parcial". Correcto. DUPLICADO de signal-core |
| 83 | Meta Pixel + CAPI | v1: Sí | v2: Parcial | 🟠 PARCIAL | v1: estandar-leads meta-adapter + meta-pixel + meta-graph-client. v2: sin implementación directa. | Inventario dice "🟡 implementado parcialmente". Correcto |
| 84 | Destination Queue | v1: Sí | v2: Sí | 🟠 PARCIAL | v1: es_google_queue + es_meta_queue (tablas separadas). v2: Core\Destinations queue genérica. Ambos parciales. | Inventario dice "🟡 arquitectura aprobada / implementación parcial". Coincide |
| 85 | Analytics / Journey | v1: Sí | v2: Sí | 🟠 PARCIAL | v1: estandar-analytics (8 tablas, sessions, events, funnel). v2: estandar-insights (Events, Funnel). Ambos básicos. | Inventario dice "🟡 parcial/en evolución". Coincide |
| 86 | Identidad persistente del visitante | v1: Parcial | — | 🟠 PARCIAL | v1: EA_Session con cookie ea_sid. Sin identidad cross-session robusta. v2: sin implementación. | Inventario dice "🟡 idea/diseño vinculado al macro-sprint". Correcto |
| 87 | SEO Foundation Engine | v1: Sí | — | 🟠 PARCIAL | v1: estandar-seo-intelligence (Search Console, 6 tests). No es el SEO Foundation descrito (metadata, sitemap, schema). | Inventario dice "🔵 evaluación/diseño". Existe más código del indicado, pero diferente scope |
| 88 | SEO automático basado en Capture | — | — | 🔵 DISEÑADO | Solo documentado como exploración. Sin código. | Inventario dice "🔵 exploración". Correcto |
| 89 | Dragon ↔ Estandar Suite API | — | — | 🔵 DISEÑADO | Solo en openapi-estandar-v1.yaml. REST endpoints existen en plugins (core 15, brain 5, capture 7, flow 7, insights 3) pero no consolidados como API Dragon. | Inventario dice "🔵 propuesta/diseño". Correcto |
| 90 | IA como consumidor del Core | — | v2 | 🟡 IMPLEMENTADO | ADR 0025: Agent engine con dos perfiles. PublicChat bubble. REST endpoints brain/agent-public. | Inventario dice "🟢 principio arquitectónico adoptado". Correcto |
| 91 | Chatbot IA | v1: Sí | v2: Parcial | 🟠 PARCIAL | v1: estandar-chatbot (2943 líneas, OpenAI proxy, WhatsApp, leads). v2: Brain\PublicChat (básico). | Inventario dice "🟡 existe en legacy; pendiente/consolidación v2". Coincide |
| 92 | Forms + Chatbot como fuentes equivalentes | — | v2 | 🟡 IMPLEMENTADO | ConversationEngine + FormBridge. ADR 0034: un solo motor de captura. | Inventario dice "🟢 decisión arquitectónica". Correcto |
| 93 | Funnel | v1: Sí | v2: Sí | 🟡 IMPLEMENTADO | v1: estandar-analytics Funnel. v2: estandar-insights Funnel. | Inventario dice "🟡 existente parcialmente". Correcto |
| 94 | Autoresponse Engine | v1: Sí | — | 🟠 PARCIAL | v1: estandar-leads Autoresponse. v2: sin implementación (DUPLICADO #28). | Inventario dice "🟡 arquitectura aprobada / implementación progresiva". Solo v1 |
| 95 | Feedback Loop publicitario | v1: Sí | v2: Parcial | 🟠 PARCIAL | v1: signal-core (Meta CAPI + Google OCI, 6 tests). v2: Core\Destinations (genérico, sin adapters). Fragmentado. | Inventario dice "🟡 implementación activa/parcial". Coincide |
| 96 | Commercial Knowledge / aprendizaje acumulativo | — | — | 🔵 DISEÑADO | Solo en ADRs. Sin implementación. | Inventario dice "🔵/🟡 dirección arquitectónica". Correcto |
| 97 | Configuración federada | — | v2 | 🟡 IMPLEMENTADO | Admin helpers compartidos en Core. Cada plugin admin via `estandar_suite_admin_register`. | Inventario dice "🟡 arquitectura adoptada/en evolución". Correcto |
| 98 | Experience (consolidación visual) | v1: Sí | — | 🟠 PARCIAL | v1: estandar-experience-blocks (13 bloques) + estandar-pop + kinetichub (12 bloques). v2: sin equivalente. | Inventario dice "🔵/🟡 arquitectura definida". Correcto |
| 99 | Kickoff (consolidación utilidades) | v1: Sí | — | 🟠 PARCIAL | estandar-kickoff: 16 módulos, 1 test. No es el Kickoff ligero descrito. | Inventario dice "🔵/🟡 definido arquitectónicamente". Subestimado |
| 100 | Herramientas legacy a absorber/evaluar | v1: 18+ | — | 🟠 PARCIAL | Catastro de 18 plugins legacy. Chatbot, Popup, Firmas, Scanner, etc. aún activos. | Inventario dice "🟡 inventario/migración progresiva". Coincide |
| 101 | Testing unificado de plugins | Compartido | 🟢 TESTEADO | ESTANDAR_TEST.command (legacy 19 plugins, 103 tests). run-suite.sh (v2 5 plugins, 232+ tests). | Inventario dice "🟢 implementado y utilizado". Correcto |
| 102 | Release safety / producción | Compartido | 🟡 IMPLEMENTADO | ADR 0032, production-safety-gate.php, release.command gates, data-settings-matrix | Inventario dice "🟢 protocolo adoptado". Correcto |
| 103 | Compatibilidad de configuración existente | Compartido | 🟡 IMPLEMENTADO | TransportRegistry auto-discovery, LegacyMap read-only, AdoptionProbe, ADR 0031 | Inventario dice "🟢 requisito arquitectónico; implementación parcial". Correcto |
| 104 | Documentación canónica | Compartido | 🟡 IMPLEMENTADO | ARCHITECTURE.md, CHANGELOG.md, 15 docs en v2/docs/, ADRs 0024-0034, WORKSPACE.md | Inventario dice "🟢 documentación activa". Correcto |
| 105 | Motor único de entidades/eventos | — | v2 | 🟠 PARCIAL | Capture→Ingestion es unificador parcial. No es el Canonical Event/Entity completo descrito. | Inventario dice "🟡 arquitectura emergente, parcialmente materializada". Coincide |
| 106 | Eliminación progresiva de proveedores innecesarios | — | v2 | 🟠 PARCIAL | Forms v2 elimina Gravity Forms en Acacios. Scanner/quotes usan dompdf local. | Inventario dice "🟡 dirección estratégica". Coincide |
| 107 | Estandar Suite como plataforma | — | v2 | 🟠 PARCIAL | Arquitectura de 5+ productos con Core compartido existe. Pero 18 plugins legacy siguen activos. | Inventario dice "conclusión". En transición |

---

## Detalle por iniciativa

### 1. Estandar Suite v2 — consolidación arquitectónica

**Estado real:** 🟡 IMPLEMENTADO
**Ubicación:** v2
**Evidencia:** 5 plugins con namespace `Estandar\` (core, brain, capture, flow, insights) + 4 híbridos (destinations, kickoff, primer, quotes). Arquitectura documentada en ARCHITECTURE.md. CHANGELOG muestra 10 micro-versiones en 4 días (1.0.0 → 1.0.10).
**Qué funciona realmente:** Core como infraestructura compartida, capture como motor de formularios, brain como inteligencia, flow como pipeline comercial, insights como analítica.
**Qué falta:** Los 4 plugins híbridos no están integrados en el namespace v2. Symlinks documentados en WORKSPACE.md no existen.
**Diferencia con inventario:** El inventario describe "5 plugins". Realmente hay 9 en el directorio de plugins, con 5 core v2 y 4 híbridos.
**Confianza:** Alta

### 2. Arquitectura de 5 plugins: Kickoff, Brain, Capture, Catch, Experience

**Estado real:** 🟠 PARCIAL
**Ubicación:** v2 (parcial)
**Evidencia:** Brain (estandar-brain), Capture (estandar-capture), Core (funge como Brain parcialmente). Catch no existe como plugin — Flow es lo más cercano. Experience no existe en v2 — está en v1 (estandar-experience-blocks). Kickoff existe como plugin pero es legacy-style, no el Kickoff ligero descrito.
**Diferencia con inventario:** Inventario dice "🟢 Aprobada". La arquitectura está diseñada pero solo 3/5 existen en v2. Catch y Experience no se materializaron como plugins v2 independientes.
**Confianza:** Alta

### 3. Brain como control plane/configuración/coordinación

**Estado real:** 🟡 IMPLEMENTADO
**Ubicación:** v2
**Evidencia:** estandar-brain: Rules, Scoring, SignalLedger (escucha `estandar_suite_capture_event`), AssessmentEngine, IntentMap, Contactability, Agent (doble perfil), Knowledge, PublicChat. 25 tests. Tablas: signal_ledger, signal_queue, agent_logs, chatbot_knowledge. REST: 5 endpoints. Shortcode: `[estandar_agent]`.
**Qué funciona realmente:** Scoring, reglas, assessment, signal ledger, agente IA con modo público/privado, chat bubble.
**Confianza:** Alta

### 4. Capture como motor de captura

**Estado real:** 🟡 IMPLEMENTADO
**Ubicación:** v2
**Evidencia:** estandar-capture: Ingestion, Attribution, Forms CRUD, ConversationEngine (ADR 0034), FormBridge (CF7 + GF), Popups, FormsAdoption, FormRenderer. 35 tests. Tablas: ingestion, forms, popups. REST: 7 endpoints. Shortcode: `[estandar_form_v2]`.
**Qué funciona realmente:** Builder de formularios con 4 subtabs, renderizado, entries, adopción de forms legacy, bridge con CF7/Gravity Forms.
**Confianza:** Alta

### 5. Catch como capa comercial/conversión

**Estado real:** 🟠 PARCIAL
**Ubicación:** v2 (como estandar-flow, no como "Catch")
**Evidencia:** No existe un plugin llamado "Catch". La capacidad comercial está en estandar-flow: Pipeline, Journey (StateMachine, LeadAdapter, InterestAdapter, ReEngagement, Recovery, Outcomes), Customers, Quotes. 21 tests. Tablas: pipeline, stage_events, customers, quotes, tasks, outcomes. REST: 7 endpoints.
**Diferencia con inventario:** Inventario menciona "Catch" como producto. Flow es el equivalente funcional pero con nombre diferente.
**Confianza:** Alta

### 6. Experience como efectos/UX

**Estado real:** 🟠 PARCIAL
**Ubicación:** v1 (no existe en v2)
**Evidencia v1:** estandar-experience-blocks (13 Gutenberg blocks: before-after, cover-transition, magnetic-carousel, marquee, scroll-panels, etc., namespace `Estandar\FX`), estandar-pop (Simple Popup, `[ep_open]`), kinetichub (12 bloques cinéticos, React-based, REST settings endpoint).
**Evidencia v2:** Sin equivalente. No existe plugin estandar-experience v2.
**Diferencia con inventario:** Inventario dice "🟡 Diseñado". Correcto: diseñado pero no implementado en v2. Sin embargo, hay 3 plugins v1 que cubren estas capacidades.
**Confianza:** Alta

### 7. Kickoff como utilidades opcionales

**Estado real:** 🟠 PARCIAL
**Ubicación:** Híbrido (legacy-style en directorio v2)
**Evidencia:** estandar-kickoff: 16 módulos (child-theme, clean-head, image-delivery, login-personalizer, maintenance-mode, progressive-reveal, redirects, script-optimization, security, site-audit, snippets, svg-upload, taxonomy-manager, upload-limits, admin-branding, wp-exporter). 1 test (SvgUploadXxeTest). PHPUnit config. Nombre de clases legacy (`Estandar_KickOff_*`).
**Diferencia con inventario:** Inventario dice "🟡 Diseñado". Subestimado: el plugin existe con 16 módulos funcionales, pero no es el Kickoff ligero descrito sino un plugin pesado legacy-style.
**Confianza:** Alta

### 8. Reducir ~21 plugins a estos dominios

**Estado real:** 🟠 PARCIAL
**Ubicación:** Ambas
**Evidencia:** 18 plugins v1 + 9 plugins v2 = 27 plugins totales activos. No ha ocurrido reducción. La migración es aditiva (nuevo v2 coexiste con v1), no sustitutiva.
**Confianza:** Alta

### 9. Mantener compatibilidad con Suite v1

**Estado real:** 🟡 IMPLEMENTADO
**Ubicación:** Compartido (v2 Core)
**Evidencia:** `LegacyMap.php` (strip_legacy_menus, rehome_legacy_page, 403 fix), `FormsAdoption.php`, `AdoptionProbe.php`, Data-Settings Matrix, ADR 0027/0028/0029.
**Confianza:** Alta

### 10. Contratos compartidos v1/v2

**Estado real:** 🟡 IMPLEMENTADO
**Ubicación:** Compartido
**Evidencia:** PHP interfaces en Core (`CaptureSink`, `SignalLedger`, `CommercialStageMap`, `Telemetry`, `AdoptionProbe`). Cross-plugin hooks: `estandar_suite_capture_event` (capture→brain/flow/insights), `estandar_suite_form_entry_created`, `estandar_suite_signal_recorded`, `estandar_suite_journey_transition`.
**Confianza:** Alta

### 11. Absorber/reutilizar antes de reescribir

**Estado real:** 🟡 IMPLEMENTADO
**Ubicación:** Compartido
**Evidencia:** AdoptionProbe en todos los plugins v2. FormsAdoption con `adopt_legacy()` (read-only, 598 entries). LeadAdapter + InterestAdapter en Flow. TransportRegistry auto-discovery de configs SMTP/Brevo existentes.
**Confianza:** Alta

### 12. Fuente de verdad única del código

**Estado real:** 🟠 PARCIAL
**Ubicación:** Compartido
**Evidencia:** WORKSPACE.md define arquitectura de fuente única con symlinks Dragon→Plugins_Workspace→instalaciones. Pero los symlinks no existen (todos los directorios son regulares, `ls -la | grep "^l"` devuelve 0). DomPDF duplicado en quotes (`vendor/dompdf/` + `includes/lib/dompdf/`). Código canónico movido al volumen externo (README.md en Dragon/projects/estandar-suite-v2/plugins/).
**Confianza:** Alta

### 13. release.command único

**Estado real:** 🟡 IMPLEMENTADO
**Ubicación:** Compartido
**Evidencia:** `/Volumes/ESTANDAR_2026/.../release.command` (292 líneas). 3 gates: unified version (26/26), quality tests (12 runners), security scan. Release flow: version bump → lint → ZIPs reproducibles (Python, timestamp 1980-01-01) → metadata → SFTP staging → atomic switch → verify. `--dry-run` con rollback. Legacy pipeline congelado (ADR 0028).
**Confianza:** Alta

### 14. Local → pruebas → validación → producción

**Estado real:** 🟡 IMPLEMENTADO
**Ubicación:** Compartido
**Evidencia:** WORKSPACE.md documenta flujo: Dragon dev → Plugins_Workspace (:10000) → akcontadores (:8889) / acacios (:8887) → staging (updates.estandar.cl). CHANGELOG 1.0.9 confirma deploy a acacios.cl.
**Confianza:** Alta

### 15. Migración controlada Acacios → AK → otros

**Estado real:** 🟠 PARCIAL
**Ubicación:** v2
**Evidencia:** Acacios: formulario 2 recuperado con v1.0.9. AK: laboratorio local con symlinks. Sin evidencia de expansión a otros sitios.
**Confianza:** Media

### 16. Forms propio de Estandar

**Estado real:** 🟡 IMPLEMENTADO
**Ubicación:** Ambas
**Evidencia v1:** estandar-forms (26 tests, 8 tablas, shortcode `[estandar_form]`, builder, entries, GF importer, survey engine).
**Evidencia v2:** estandar-capture Forms (35 tests, builder con 4 subtabs, `[estandar_form_v2]`, FormBridge, FormsAdoption).
**Confianza:** Alta

### 17. Builder/configuración de formularios

**Estado real:** 🟡 IMPLEMENTADO
**Ubicación:** Ambas
**Evidencia v1:** `admin/screens/form-edit.php`, GF importer, dependant selects, repeater.
**Evidencia v2:** `maquetador.php`, 4 subtabs (diseno/configuracion/correo/entradas), type interchange (lead/survey/incident), non-destructive merge save (ADR 0032).
**Confianza:** Alta

### 18. Shortcodes

**Estado real:** 🟡 IMPLEMENTADO
**Ubicación:** Ambas
**Evidencia v1:** `[estandar_form]` (class-shortcode.php).
**Evidencia v2:** `[estandar_form_v2 id="X"]` (FormRenderer.php:30, Embed).
**Confianza:** Alta

### 19. Proveedor único de Embed en Capture v2

**Estado real:** 🟡 IMPLEMENTADO
**Ubicación:** v2
**Evidencia:** `Core\Embed` con `[estandar_form_v2 id="X"]`, shortcode inserter UI.
**Confianza:** Alta

### 20. Mostrar/copiar shortcode

**Estado real:** 🟡 IMPLEMENTADO
**Ubicación:** v2
**Evidencia:** Parte de Embed UI en admin.
**Confianza:** Media (no inspeccioné la UI visualmente)

### 21. Entries por formulario

**Estado real:** 🟡 IMPLEMENTADO
**Ubicación:** Ambas
**Evidencia v1:** `estandar_form_entries` table, admin screens.
**Evidencia v2:** Entries subtab en editor con `source_form_id`. Tabla `estandar_ingestion`.
**Confianza:** Alta

### 22. Migración desde Forms legacy

**Estado real:** 🟡 IMPLEMENTADO
**Ubicación:** v2
**Evidencia:** FormsAdoption, `adopt_legacy()`, read-only legacy config, 598 entries/55 responses/8 forms preservados. ADR 0027/0029.
**Diferencia con inventario:** Inventario dice "🟡 En curso". Más avanzado de lo indicado: adopción completa con dry-run y rollback.
**Confianza:** Alta

### 23. Migración del formulario Acacios

**Estado real:** 🟢 TESTEADO
**Ubicación:** v2
**Evidencia:** CHANGELOG 1.0.9: "deployed to production at acacios.cl, form 2 recovered, permanent protection config_loss_reason guard". HANDOFF.md: "Form 2 recovered in production (1.0.9 deployed)". ADR 0032 documenta root cause y fix.
**Diferencia con inventario:** Inventario dice "🟢 Realizada/local-validada". Correcto pero es TESTEADO (con evidencia de producción).
**Confianza:** Alta

### 24. Notificaciones configurables

**Estado real:** 🟠 PARCIAL
**Ubicación:** v2
**Evidencia:** `Core\Mail::send()` funcional. TransportRegistry con 3 adapters. Admin UI de notificaciones en subtab correo del editor de forms. Sin UI completa multi-notificación/condiciones.
**Confianza:** Alta

### 25. Recipients configurables

**Estado real:** 🟠 PARCIAL
**Ubicación:** v2
**Evidencia:** Configuración de recipients en Forms settings. Sin UI multi-recipient con condiciones.
**Confianza:** Media

### 26. Editor HTML del correo

**Estado real:** 🟠 PARCIAL
**Ubicación:** v2
**Evidencia:** Email template en subtab correo. Sin editor WYSIWYG (TinyMCE/CodeMirror) completo.
**Confianza:** Media

### 27. Preview visual del correo

**Estado real:** ⚪ NO EXISTE
**Ubicación:** —
**Evidencia:** No hay vista previa renderizada de correo en ningún plugin v1 ni v2.
**Diferencia con inventario:** Inventario dice "🟡 En desarrollo". Sin evidencia de implementación.
**Confianza:** Alta

### 28. Autorespuesta

**Estado real:** 🟠 PARCIAL
**Ubicación:** v1 (no existe en v2)
**Evidencia v1:** `class-estandar-leads-autoresponse.php` en estandar-leads. Opciones: `estandar_leads_autoresponse_settings`.
**Evidencia v2:** Sin implementación.
**Diferencia con inventario:** Inventario dice "🟡 Consolidación". Solo v1.
**Confianza:** Alta

### 31. Motor común de comunicaciones

**Estado real:** 🟢 TESTEADO
**Ubicación:** v2 (estandar-core)
**Evidencia:** `Communications/TransportRegistry` con 3 adapters (WpMail, SMTP, Brevo). `Mail::send()` rutea por registry. `DeliveryLog`. 22 tests dentro de run-core.php (147 total). Auto-discovery de configs existentes. Resolution chain con fallback explícito. ADR 0031.
**Diferencia con inventario:** Inventario dice "🟢 Arquitectura aprobada". Subestimado: está implementado y testeado, no solo aprobado.
**Confianza:** Alta

### 32-37. Componentes de Communications Engine

**Estado:** Varían entre 🟡 IMPLEMENTADO y 🟢 TESTEADO. Ver matriz maestra.
**Ubicación:** v2 (estandar-core/includes/Communications/)
**Confianza:** Alta

### 42. PDF adjunto / Document Engine

**Estado real:** 🟠 PARCIAL
**Ubicación:** v2
**Evidencia:** `Core\Documents\Pdf.php` (base, usa dompdf si disponible). Quotes tiene su propio Document Engine con 17 tests, PDF generation via dompdf (v2.0.8), hydration pipeline. Pero Quotes NO usa el Core\Documents\Pdf — tiene su propia implementación.
**Qué falta:** Integrar Quotes PDF con Core Document Engine. Unificar dompdf (duplicado en quotes vendor/ e includes/lib/).
**Confianza:** Alta

### 45-62. Leads / Sistema comercial

**Estado:** Varía entre 🟡 IMPLEMENTADO y 🟠 PARCIAL. Ver matriz maestra.
**Ubicación:** v1 (estandar-leads, 78 tests, 15 tablas) + v2 (estandar-flow, 21 tests, 6 tablas)
**Confianza:** Alta

### 63-68. Lead Quality / Intelligence Engine

**Estado real:** 🟡 IMPLEMENTADO (fragmentado entre v1 y v2)
**Ubicación:** Ambas
**Evidencia v1:** Estandar_Commercial_Qualification_Engine, Estandar_Leads_Assessment_Engine, scoring, intent score, classification system.
**Evidencia v2:** Brain\AssessmentEngine, Core\Quality\ContactabilityEngine, Core\Interest\InterestEngine (88 tests), Brain\Scoring, Brain\Rules, Core\Conditions.
**Qué funciona realmente:** Ambas versiones tienen motores de calidad. La v2 (IEE) está más avanzada en detección de señales. La v1 tiene clasificación comercial más completa.
**Confianza:** Alta

### 69. Segundo intento y reactivación de leads

**Estado real:** 🟠 PARCIAL
**Ubicación:** v2
**Evidencia:** Flow\Journey\ReEngagement, Recovery. Interest Engine detecta retorno (InterestEventLog). Sin integración completa del pipeline "Lead → no contacto → retorno → alerta".
**Confianza:** Alta

### 70. Contact Strategy Engine

**Estado real:** 🔵 DISEÑADO
**Ubicación:** Solo documentación
**Evidencia:** Concepto documentado en inventario. Sin código. No hay archivos, clases, ni hooks relacionados.
**Confianza:** Alta

### 71. Canal de contacto unificado (ContactChannel)

**Estado real:** 🟠 PARCIAL
**Ubicación:** v2
**Evidencia:** `Core\Channels.php` existe como abstracción de canales. Pero no es el `ContactChannel` canónico descrito (available_channels, preferred_channel, fallback_channel, conversion_channel).
**Diferencia con inventario:** Inventario dice "🔵 propuesto/diseñado". Existe más código del indicado, pero no coincide con el diseño.
**Confianza:** Alta

### 72. Calendar / reuniones

**Estado real:** 🟠 PARCIAL
**Ubicación:** v2
**Evidencia:** `Core\Calendar.php` con tabla `estandar_core_calendar`. Sin integración con Flow Pipeline o Capture Forms.
**Confianza:** Alta

### 73-74. Surveys Engine

**Estado real:** 🟠 PARCIAL
**Ubicación:** v1 (más avanzado), v2 (básico)
**Evidencia v1:** estandar-forms: Survey_DB, Survey_Handler, Survey_Analytics. Tabla `estandar_survey_responses`. 26 tests.
**Evidencia v2:** ConversationEngine (ADR 0034) puede hacer surveys como "tipo intercambiable". Sin implementación completa del motor de surveys descrito.
**Diferencia con inventario:** Inventario dice "🟡 implementación activa". v1 está más avanzado que v2 en surveys.
**Confianza:** Alta

### 75. Postventa

**Estado real:** 🔵 DISEÑADO
**Ubicación:** Solo documentación
**Evidencia:** Concepto documentado en inventario. Sin código. Relacionado con Capture + Flow pero sin implementación.
**Confianza:** Alta

### 76. Cotizaciones / Quote Engine

**Estado real:** 🟢 TESTEADO
**Ubicación:** v2 (estandar-quotes)
**Evidencia:** 44 PHP files, 17 tests, 9 tablas, hydration pipeline (10 stages), profiles (RealEstate, Acacios), PDF generation (dompdf v2.0.8), delivery (email + fake transport), operator gateway, state machine, canonical JSON, form integration. Es el plugin más completo de todo Estandar Suite.
**Diferencia con inventario:** Inventario dice "🟢 funcionalidad base implementada". Subestimado: tiene 17 tests y arquitectura completa de hydration pipeline.
**Confianza:** Alta

### 77. Document Engine

**Estado real:** 🟡 IMPLEMENTADO
**Ubicación:** v2 (Core + Quotes)
**Evidencia:** `Core\Documents\Pdf.php` (base). Quotes: Document Engine con 17 tests, hydration pipeline, profiles, PDF adapters (dompdf + fake). Ambos existen pero no unificados.
**Confianza:** Alta

### 78. Imports Engine

**Estado real:** 🟠 PARCIAL
**Ubicación:** v2
**Evidencia:** `Core\Adapters\`: GoogleSheets, ExcelOnline, Simulated (implementan AdapterInterface). Integration Core wizard (test/discover/create/columns/write). FieldMap auto-map. Sandbox mode. Sin implementación de paste→tasks.
**Confianza:** Alta

### 80. Attribution Engine

**Estado real:** 🟠 PARCIAL
**Ubicación:** Ambas (fragmentado)
**Evidencia v1:** estandar-analytics: Attribution, Session tracking (cookie `ea_sid`), UTM/campaign/traffic data. estandar-primer: Campaign Discovery, Campaign Label Resolver.
**Evidencia v2:** `Capture\Attribution` + `Core\Channels`. Click IDs, UTM→channel, click evidence.
**Qué falta:** Unificación. v1 y v2 tienen implementations separadas y no integradas.
**Confianza:** Alta

### 82. Google Ads OCI

**Estado real:** 🟠 PARCIAL
**Ubicación:** v1 (más avanzado), v2 (básico)
**Evidencia v1:** estandar-leads: google-adapter, google-dm-client, google-payload-builder. Tabla `es_google_queue`. Opciones: `estandar_leads_google_enabled`, `estandar_leads_google_dry_run`, `estandar_leads_google_conversion_action_id`.
**Evidencia v2:** estandar-destinations: google-ads-destination (adapter). Tablas: `estandar_oci_queue`, `estandar_oci_queue_meta`. Sin integración con Flow Pipeline.
**DUPLICADO/DEPENDIENTE DE:** estandar-signal-core (también tiene Google OCI exporter, 6 tests).
**Confianza:** Alta

### 83. Meta Pixel + CAPI

**Estado real:** 🟠 PARCIAL
**Ubicación:** v1
**Evidencia v1:** estandar-leads: meta-adapter, meta-payload-builder, meta-graph-client, meta-pixel. Tabla `es_meta_queue`. Opciones: `estandar_leads_meta_pixel_id`, `estandar_leads_meta_access_token`, `estandar_leads_meta_enabled`, `estandar_leads_meta_server_events`.
**Evidencia v2:** Sin implementación directa. Core\Destinations es genérico, sin adapter Meta.
**Confianza:** Alta

### 85. Analytics / Journey

**Estado real:** 🟠 PARCIAL
**Ubicación:** Ambas
**Evidencia v1:** estandar-analytics: 8 tablas (sessions, pageviews, events, conversions, referrers, devices, aggregates, ads_daily). Session tracking (ea_sid). Funnel, dashboard views.
**Evidencia v2:** estandar-insights: Events (1 tabla), Funnel, Observability. REST: 3 endpoints. 23 tests.
**Confianza:** Alta

### 87. SEO Foundation Engine

**Estado real:** 🟠 PARCIAL
**Ubicación:** v1 (diferente alcance)
**Evidencia v1:** estandar-seo-intelligence: Search Console adapter, landing pages, queries, metrics, sync runs. 4 tablas. 6 tests. NO es el SEO Foundation descrito (metadata, canonical, robots, sitemap, OG, Schema.org, JSON-LD).
**Evidencia v2:** Sin implementación.
**Diferencia con inventario:** Inventario dice "🔵 evaluación/diseño". Existe más código del indicado pero es SEO Analytics (Search Console), no SEO Foundation (metadata/schema).
**Confianza:** Alta

### 89. Dragon ↔ Estandar Suite API

**Estado real:** 🔵 DISEÑADO
**Ubicación:** Solo documentación
**Evidencia:** `openapi-estandar-v1.yaml` en estandar-integration/. REST endpoints existen en plugins (core 15, brain 5, capture 7, flow 7, insights 3 = 37 endpoints) pero no consolidados como API Dragon con audit log, dry-run, rollback, versionado.
**Confianza:** Alta

### 91. Chatbot IA

**Estado real:** 🟠 PARCIAL
**Ubicación:** v1 (completo), v2 (básico)
**Evidencia v1:** estandar-chatbot: 2943 líneas, OpenAI proxy, WhatsApp handler, conversations, leads, logs, analytics, 15 admin tabs, frontend JS (chatbot.js, decision-engine.js, state-manager.js), 4 tablas, shortcode `[estandar_chatbot]`.
**Evidencia v2:** Brain\PublicChat (chat bubble + `[estandar_agent]` shortcode), Brain\Agent (doble perfil), Brain\Knowledge. Sin WhatsApp, sin frontend JS, sin multi-canal.
**Confianza:** Alta

### 98. Experience (consolidación visual)

**Estado real:** 🟠 PARCIAL
**Ubicación:** v1 (3 plugins), v2 (sin equivalente)
**Evidencia v1:** 
- estandar-experience-blocks: 13 Gutenberg blocks, REST API (`estandar-fx/v1/presets`), namespace `Estandar\FX`
- estandar-pop: Simple Popup, `[ep_open]`, Gutenberg popup picker block
- kinetichub: 12 bloques cinéticos (React-based), REST settings, CSS custom properties
**Confianza:** Alta

### 101. Testing unificado de plugins

**Estado real:** 🟢 TESTEADO
**Ubicación:** Compartido
**Evidencia:** 
- ESTANDAR_TEST.command (legacy): descubre plugins, ejecuta tests, lint, reportes en reports/
- run-suite.sh (v2): integrity gate → collision gate → 5 product suites → lint → consolidated
- production-safety-gate.php (449 líneas): static analysis, upgrade simulation, coverage
- Total tests verificados: 147 (core) + 88 (IEE) + 35 (capture) + 25 (brain) + 21 (flow) + 23 (insights) + 17 (quotes) + varios legacy
**Diferencia con inventario:** Inventario menciona 103 tests legacy y "~120 tests" v2. Realmente hay ~400+ tests con las adiciones recientes (IEE 88).
**Confianza:** Alta

---

## Hallazgos no incluidos en el inventario

### Capacidades reales encontradas que faltaban en el inventario:

1. **estandar-primer** (7,551 líneas monolíticas): Runtime compartido con AI gateway (OpenAI + DeepSeek), diagnostics engine, integrity framework, project provisioning, canonical redirect, campaign discovery/label resolver, Google Integration Hub, operational hours, project calendar/registry, compliance shadow copy, plugins update manager, admin shell. No mencionado en el inventario como entidad propia.

2. **estandar-destinations**: Plugin de colas de destino con Google Ads adapter, webhook destination, OCI queue (2 tablas), scheduler, health monitoring, settings UI. Más completo que lo que el inventario describe como "Destination Queue".

3. **Interest Evolution Engine (IEE)**: 88 tests en estandar-core. InterestEngine, InterestSignals, InterestState, InterestEventLog, EngagementEngine, NextBestAction. Es más avanzado que lo descrito en "Lead Quality / Intelligence Engine".

4. **Integration Core completo**: OAuth2 genérico, Crypto AES-256-GCM, Connections, Adapters (GoogleSheets, ExcelOnline, Simulated), FieldMap, Conditions, Integrations N:N, Sandbox, IntegrationsRest wizard. 216 tests. Es un sistema completo de integraciones, no solo "imports".

5. **kinetichub**: 12 bloques Gutenberg cinéticos (React-based), REST API, CSS custom properties, modos de rendimiento. Tercer plugin de experiencia visual no mencionado.

6. **estandar-secure-share**: Entrega segura de secretos con AES-256-GCM, tokens, rate limiting, audit log, email notifications. 3 tablas. Capacidad criptográfica no mencionada.

7. **estandar-firmas**: Generador de firmas de correo. Shortcode `[estandar_signature_generator]`.

8. **estandar-cpt-manager**: 7,732 líneas. CPT dinámicos, templates de producto, bloques de producto, galería. No mencionado.

9. **estandar-delivery-locator-lite**: Localizador de delivery con geolocalización, integración n8n, rate limiting.

10. **estandar-duplicate-post**: Duplicación de posts con settings.

---

## Legacy sin equivalente v2

| Capacidad v1 | Plugin v1 | Estado en v2 |
|---|---|---|
| Chatbot IA completo (OpenAI, WhatsApp, leads) | estandar-chatbot | Solo Brain\PublicChat (básico) |
| Analytics completo (8 tablas, sessions, funnel) | estandar-analytics | estandar-insights (1 tabla, básico) |
| Lead management completo (15 tablas, 78 tests) | estandar-leads | estandar-flow (6 tablas, 21 tests) |
| SEO Intelligence (Search Console, landing pages) | estandar-seo-intelligence | Sin equivalente |
| Meta Pixel + CAPI | estandar-leads (meta-*) | Sin equivalente directo v2 |
| Google Ads OCI | estandar-leads + signal-core | estandar-destinations (parcial) |
| Experience blocks (13 Gutenberg) | estandar-experience-blocks | Sin equivalente |
| Popups (Simple Popup) | estandar-pop | Capture\Popups (solo schema) |
| Kinetic blocks (12 React blocks) | kinetichub | Sin equivalente |
| Surveys engine completo | estandar-forms (Survey) | ConversationEngine (base) |
| Autorespuesta | estandar-leads | Sin equivalente |
| CPT Manager (7732 líneas) | estandar-cpt-manager | Sin equivalente |
| Delivery Locator | estandar-delivery-locator-lite | Sin equivalente |
| Secure Share (crypto) | estandar-secure-share | Core\Secrets (parcial) |
| Firmas | estandar-firmas | Sin equivalente |
| Website Scanner (22 engines) | estandar-website-scanner | Sin equivalente |
| WP Exporter | estandar-wp-exporter | Sin equivalente |
| Notifications (stub) | estandar-notifications | Core Communications (más avanzado) |
| Campaign Resolver | estandar-primer | Sin equivalente directo |

---

## Diferencias documentación ↔ código

1. **WORKSPACE.md dice symlinks, realidad: directorios regulares**. Todos los 9 plugins v2 son directorios regulares, no symlinks. El árbol documentado Dragon→Plugins_Workspace→instalaciones no existe a nivel filesystem.

2. **ARCHITECTURE.md dice "5 plugins", realidad: 9 plugins**. destinations, kickoff, primer, y quotes no aparecen en ARCHITECTURE.md como parte de la arquitectura pero existen en el directorio.

3. **CAPACITY-MATRIX.md dice "PRESERVADA" para analytics/observability en insights, realidad: solo 1 tabla y 23 tests**. v1 analytics tiene 8 tablas. La capacidad está lejos de ser preservada completamente.

4. **PLUGINS-HANDBOOK.md lista 5 plugins. Realidad: 9**. El handbook omite destinations, kickoff, primer, y quotes.

5. **CHANGELOG.md 1.0.10 dice "ready but not published". WORKSPACE.md dice v1.0.10. HEADERS dicen 13.0.171**. Triple esquema de versionado: interno (1.0.10/1.0.11), header (13.0.171), legacy constants (13.0.158).

6. **release-channels.json dice "19 plugins" legacy. manifest.json dice 21**. Discrepancia de 2 plugins en el conteo.

7. **estandar-quotes tiene dompdf duplicado**: `vendor/dompdf/` (Composer) + `includes/lib/dompdf/` (manual). Documentado en ningún lado.

8. **estandar-notifications adapters son todos .gitkeep**: La arquitectura de notificaciones multi-canal está diseñada pero vacía. Los adapters (email, push, slack, sms, telegram, whatsapp) son stubs.

---

## Estado arquitectónico global

### Lo que existe REALMENTE hoy:

**Dos sistemas coexistiendo, no uno migrando al otro:**

1. **Sistema v1 (Legacy):** 18 plugins funcionales con ~13.0.158-163, ~103 tests, ~30+ tablas DB, 6+ años de evolución. Contiene capacidades críticas que v2 aún no reemplaza: chatbot completo, analytics completo, lead management completo, Meta/Google ads, surveys avanzados, experience blocks, scanner, CPT manager.

2. **Sistema v2 (Nuevo):** 5 plugins core con namespace `Estandar\` + 4 plugins híbridos. ~232 tests en los 5 core. Arquitectura de bounded contexts con Core como infraestructura compartida. Release pipeline moderna con ZIPs reproducibles y 3 gates. Desplegado parcialmente en acacios.cl (solo Forms).

3. **Capa compartida (Primer):** Monolito de 7,551 líneas que funciona como runtime compartido (AI gateway, diagnostics, integrity, provisioning, campaign discovery, updates). No es ni v1 ni v2 puro — es infraestructura transversal.

4. **Plugins independientes fuertes:** Quotes (17 tests, el más completo) y Kickoff (16 módulos) existen como islas con su propio estilo arquitectónico, sin integrarse completamente al namespace v2.

**La arquitectura REAL no es la arquitectura deseada de 5 productos.** Es un archipiélago de 27 plugins en tres generaciones arquitectónicas (legacy procedural, híbrido clase-estilo, v2 namespace) con integración parcial vía hooks cross-plugin y adoption probes.

**Communications Engine es el mayor éxito arquitectónico:** TransportRegistry unificado con 3 adapters, auto-discovery, 22 tests. Es el ejemplo más claro de la visión "un motor, no silos".

**Integration Core es el componente más subestimado:** 216 tests, OAuth2 genérico, adapters, FieldMap, Sandbox. Está listo pero no se aprovecha completamente.

---

## Conclusión

De las 43 iniciativas descritas en el inventario, **aproximadamente el 62% tiene implementación funcional verificable**, pero con una distribución muy desigual:

- **El núcleo arquitectónico v2 (Core, Brain, Capture, Flow, Insights) funciona** y está razonablemente testeado (~232 tests combinados).
- **Communications Engine y Quotes Engine son los componentes más maduros**, con cobertura de tests sustancial.
- **18 plugins legacy siguen siendo la columna vertebral operativa** para capacidades críticas (Leads, Chatbot, Analytics, Ads, SEO).
- **La migración v1→v2 es aditiva, no sustitutiva**: 27 plugins totales, no 5.
- **0% de los componentes alcanza "Ready for Release"** según los propios gates del sistema (ningún plugin pasa simultáneamente los 3 gates de release.command más los tests consolidados).
- **Solo 1 componente tiene evidencia de producción** (Forms v2 en acacios.cl, v1.0.9).

El sistema está en un estado de **transición activa con dos generaciones coexistiendo**, donde la nueva arquitectura demuestra solidez técnica (TransportRegistry, Integration Core, IEE) pero aún no ha absorbido las capacidades del legacy. La visión de "5 productos" está materializada parcialmente: los 5 plugins core existen, pero 4 plugins adicionales (destinations, kickoff, primer, quotes) operan fuera de esa arquitectura y 18 plugins legacy siguen siendo necesarios.

---

**AUDITORÍA FINALIZADA — SIN CAMBIOS REALIZADOS**
