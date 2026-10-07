# Memory

## Me
Rodrigo (gestion@estandar.cl) — Estandar Studios CL. Plugin WordPress ecosystem para inmobiliarias y servicios.

## COMMERCIAL_AI_CONTEXT_FIXES_V1 — STATUS = IMPLEMENTED_SALADEPRUEBAS / TESTS_GREEN (2026-07-16)

9 fixes aprobados sobre el Commercial AI Context Export + Leads/Ads Feedback. Doc canónico:
`estandar-leads/docs/COMMERCIAL_AI_CONTEXT_FIXES_V1.md`. **VERSION_BUMP estandar-leads 13.0.82 → 13.0.83.**

Separación canónica nueva (vinculante): **Admission Policy (ALLOWED/DISALLOWED, engine de reglas)
≠ Commercial Profile (COMMERCIAL_PROFILE_V1, derivado de campos capturados) ≠ Qualification
(reservado al operador; sin clasificación = PENDING, jamás inferida)**. El export ya no imprime
`DEFAULT (qualified — no rules matched)`.

- **COMMERCIAL_PROFILE_V1** (`class-estandar-leads-commercial-profile.php` NUEVO): perfil desde
  necesidad_principal/situacion_empresa/contador_actual/plazo_inicio/ciudad (diccionario genérico
  filterable, keywords ES, breakdown auditable). High-intent = operando + contador en juego +
  inicio inmediato. Alimenta SOLO scoring (`W_PROFILE=0.20`, reweight si ausente) y prioridad —
  nunca QUALIFIED, nunca ads.
- **OPERATIONAL_PRIORITY_V2**: click autorespuesta (deep engagement reciente) y perfil high-intent
  elevan prioridad con reason explícita (`tibio_engagement_reciente_sin_gestion`,
  `tibio_perfil_comercial_alto_sin_gestion`); apertura sola nunca. Comportamiento ≠ calificación.
- **META_GATE_V1 / GOOGLE_GATE_V1**: `dispatch_gate()` único en ambos adapters (QA + ads_feedback_policy
  + `Attribution::platform_eligibility`). `_fbp` solo JAMÁS habilita Meta; adquisición Google no
  produce eventos Meta. Rechazo persistido: evento `ads_dispatch_skipped` + outcomes log
  `status='skipped'` + cola `dead` con prefijo `skipped/not_eligible:` (observability renderiza
  NOT_ELIGIBLE, nunca FAILED). Sin cambio de schema.
- **OCI_OBSERVABILITY_V1**: cada intento Google persiste decision/mode(validateOnly|send)/
  identifier/conversion_action/attempted_at/resultado API en `es_commercial_outcomes_log`;
  sin ejecución → `PLATFORM_CONFIRMATION_UNKNOWN` (sin cambios de config ni envíos reales).
- Export: Attribution Status Meta (`explicit_meta_attribution`/`captured`, nunca `unavailable`
  con evidencia) · timestamps `UTC (America/Santiago)` display-only · landing fallback
  sesión→submission.source_page_url→lead.first_landing_url con procedencia y WARNING intacto ·
  Compliance SSOT (`compliance_engine_state()`: pipeline y AI Summary misma fuente).

**Validación**: `php -l` 12 archivos PASS · 3 suites nuevas (run-commercial-profile 20/20 ·
run-meta-gate-skip 11/11 · run-google-oci-observability 9/9) · run-ai-context-export +7 checks ·
**55/55 suites de estandar-leads en verde**. Aceptación A–E completa.
**GATE deploy**: suite en máquina de Rodrigo + export real en SALADEPRUEBAS (lead AK + lead Meta)
antes de producción.

## CUSTOMER_BACKFILL_V1 — STATUS = IMPLEMENTED_SALADEPRUEBAS / TESTS_GREEN (2026-07-14)

Cierra el eslabón **"comprador real AltaCruz → Meta CAPI"** detectado por el audit del mismo día.
Doc canónico: `estandar-leads/docs/META_INTEGRATION_FORMALIZATION_V1.md` **ADDENDUM V5**.

**Hallazgo que lo motivó (audit 14-jul)**: la infraestructura Meta estaba completa y viva, pero
(1) el Customer Registry terminaba en la BD — `matched_lead_id` escrito, **nada emitido**; y
(2) `Estandar_Leads_Conversion_Backfill_Service` estaba cargado en el bootstrap pero **sin ningún
llamador en producción** (solo su test). Meta solo se enteraba de una venta si el operador
clasificaba el lead a mano (path `estandar_lead_status_changed` → Purchase), que sigue vivo.

**Flujo implementado (admin-triggered, histórico, one-shot):**
```
es_customers.matched_lead_id
  → Estandar_Leads_Customer_Backfill::plan()    elegibilidad (read-only)
  → ::run(dry_run=false)
      → Conversion_Backfill_Service::enqueue()    es_conversion_backfill_audit (UNIQUE event_id)
      → Conversion_Backfill_Service::dispatch()   hidrata es_leads → QA gate → Meta_Adapter
          → Payload_Builder::build($lead,'CONVERTED') → 'Purchase' → Graph_Client → Meta
```
UI: Clientes → panel "Backfill histórico a Meta (Purchase)" con **Simular** (dry-run, default) y
**Enviar a Meta** (confirm). Filtros proyecto/etapa/límite.

**Reglas de elegibilidad** (primer disqualificador gana): `unmatched` · `lead_missing` ·
`lead_uuid_missing` (sin uuid no hay event_id determinístico → fail-closed) · `qa_blocked`
(`QA_Policy::ads_feedback_blocked`) · `already_dispatched` → cuenta como **duplicate**, no error.

**Invariantes duras**: identidad y atribución **SIEMPRE** del row canónico de `es_leads`
(em/ph hasheados, fbc, fbp, utm_*, ip, UA, `external_id=lead_uuid`); el cliente aporta solo hechos
comerciales (amount/currency/completed_at) ⇒ **`buyer_json` sin PII**. NO modifica `es_leads` ni
`es_customers` · NO cambia estado comercial · NO dispara `estandar_lead_status_changed` · NO agrega
cron ni dispatch automático. Idempotencia: `buyer_uuid` determinístico desde `es_customers.id` →
`event_id = conversion-backfill:{lead_uuid}:{buyer_uuid}:Purchase` (UNIQUE) → **re-run nunca duplica**.

**Cambio aditivo en el service**: `dispatch()` hidrata desde `es_leads` solo con `lead_id` en la raíz
del record (opt-in; legacy intacto) + **aplica el QA gate** (cierra el hueco #5 del canon: el backfill
era el único dispatch Meta sin gate) + `update_audit()` distingue `retry` (429/5xx/timeout) de
`failed` (4xx) y agrega estado terminal `skipped`.

**LÍMITE (Fase 2)**: `dispatch()` solo drena `pending` → una fila `retry` queda auditada pero **no se
reintenta**, y como su `event_id` ya existe un re-run la reporta como `duplicate`. El drenaje de
reintentos NO está en esta fase. Tampoco hay dispatch automático de ventas futuras.

**VERSION_BUMP**: estandar-leads **13.0.71 → 13.0.72**. Archivos: `class-estandar-leads-customer-backfill.php`
(NUEVO) · `class-estandar-leads-conversion-backfill-service.php` · `class-estandar-leads-customers-admin.php` ·
`templates/admin/customers/list.php` · `estandar-leads.php`.

**Validación**: `php -l` PASS (6 archivos) · `tests/run-customer-backfill.php` (NUEVA) **32/32 PASS** ·
**47/47 suites de estandar-leads en verde** (run-conversion-backfill 7/7 sin regresión). PHP 8.5 local.
**GATE deploy**: correr la suite en la máquina de Rodrigo + dry-run en Acacios ANTES de "Enviar a Meta".

### CUSTOMER_BACKFILL_MENU_PERMISSION_FIX (2026-07-14, estandar-leads 13.0.73 → 13.0.74)

**Síntoma prod**: abrir Clientes → "Lo siento, no tienes permisos para acceder a esta página" (mensaje
CORE de WP), aunque el MISMO usuario abre las pantallas normales de Leads. Capability real del usuario
= `manage_options` (accede a Leads).

**Root cause**: NO era la capability (Customers y Leads usan `manage_options` idéntico). Era el
**registro del menú divergente**. `Estandar_Leads_Customers_Admin::menu()` usaba un gate bespoke
(`defined('ESTANDAR_CORE_MENU_SLUG')` + fallback `isset($admin_page_hooks['estandar-leads'])`) en vez
del patrón canónico que usan TODAS las demás páginas de Leads (`estandar_core_mu_is_active()` +
`estandar_register_navigation_page`). Cuando el hook de la página no se registra bajo el mismo parent
que Leads (hazard de orden de carga, CORE_OPTIONALITY), WP no encuentra el page-hook y cae a
`current_user_can('estandar-customers')` — el slug tratado como capability, que ningún rol tiene →
denegación genérica pese a `manage_options`. Reproducido con harness fiel de la menu API de WP
(add_menu_page/add_submenu_page/get_plugin_page_hook + fallback de admin.php): hook ausente ⇒ slug-as-cap
⇒ mensaje exacto; en core mode Leads vive bajo `estandar_main_menu` y NO crea top-level `estandar-leads`,
así que el fallback bespoke de Customers quedaba huérfano/divergente.

**Fix (1 archivo, `class-estandar-leads-customers-admin.php::menu()`)**: reescrito para espejar
`Estandar_Leads_Admin::menu()` EXACTO — gate `estandar_core_mu_is_active()` → `estandar_admin_menu_register_parent()`
+ `estandar_register_navigation_page([slug,capability,callback])` (mismo parent que Leads); standalone →
`add_submenu_page('estandar-leads', …)` (Leads init corre antes, línea 148<149); fallback final
`add_menu_page`. **Capability sin cambio: `manage_options` antes y después** (era el helper/gate el
inconsistente, no el valor). `page()` y `handle_meta_backfill()` intactos: `current_user_can(self::CAP)`
+ `check_admin_referer` preservados (fail-closed). Sin roles nuevos, sin `map_meta_cap`.

**Validación**: `php -l` PASS · 47/47 suites en verde · harness WP confirma que Customers ahora resuelve
el page-hook bajo el mismo parent que Leads (core y standalone). Usuario sin `manage_options` sigue
bloqueado (gate del menú + doble check en page() + nonce en el handler). **VERSION_BUMP 13.0.73 → 13.0.74.**
**Nota honesta**: el árbol SALADEPRUEBAS actual registraba accesible en los escenarios estándar
reproducibles; el fix elimina la CLASE de bug (registro divergente/dependiente de orden de carga) que
es el disparador plausible en Acacios, alineando a Customers con el patrón probado de Leads.

## MUTEX_LEAK_FIX_V1 — STATUS = IMPLEMENTED_SALADEPRUEBAS / TESTS_GREEN (2026-07-14)

Corrige la **fuga del Identity Mutex** que producía la cadena
`LEADS_IDENTITY_MUTEX_UNAVAILABLE → FORMS_INGESTION_NOT_CONFIRMED → FORMS_SUBMISSION_ERROR_RESPONSE`
(síntoma observado: Alto Jaramillo fallaba; Parronales/AltaCruz OK). Tres defectos, todos en `estandar-leads/includes/class-estandar-leads-ingestion.php`.

**CAUSA RAÍZ CONFIRMADA — lock expirado persistente**: `acquire_identity_mutex()` limpiaba el lock expirado con `$wpdb->query("DELETE … AND option_value = %s")`. El match por `option_value` exacto fallaba en silencio ante desajuste caché/serialización → 0 filas borradas → `$deleted` falsy → NUNCA reintentaba el `add_option` → **el lock expirado quedaba indefinidamente en `wp_options` y bloqueaba toda nueva submission de esa identidad** (evidencia directa de producción: `seconds_remaining = -1902`, lock caducado ~31 min sin limpiar). Esta es la causa raíz del incidente. La llave es SOLO identidad (`email_hash`/`phone_hash`), sin proyecto → el mutex es el síntoma; el bloqueo por-formulario es artefacto de qué identidades quedaron atascadas. **HIPÓTESIS DESCARTADA**: la "identidad constante en el formulario de Alto Jaramillo" NO era la causa — el disparador real fue el lock expirado no recuperado; se retira esa hipótesis.

**DEFECTO ESTRUCTURAL ADICIONAL — ausencia previa de `try/finally`**: `capture()` adquiría el mutex y su sección crítica (transacción + persistencia + eventos + hooks) NO estaba bajo `try/finally`. Un `Throwable`/fatal entre la adquisición y el `COMMIT` saltaba las 7 liberaciones manuales → lock huérfano (TTL 30 s). No fue el disparador del incidente observado (ese fue el expirado persistente), pero era una segunda vía de fuga latente; se corrige junto.

**DEFECTO DE TIMING — release tardío**: incluso con `try/finally`, el release quedaba solo en el `finally`, reteniendo el lock durante los listeners y tareas post-COMMIT (`estandar_lead_ingested`). Corregido: release inmediato tras `COMMIT`, antes de los listeners.

**Garantía nueva del mutex + timing** — additivo, sin schema, sin reglas comerciales:
- Sección crítica (post-acquire) en `try { … } catch (Throwable $e) { … } finally { … }`.
- **Orden exacto en el camino feliz: `COMMIT → release → listeners`.** Tras `COMMIT`: `$in_transaction=false` → `release_identity_mutex()` inmediato → `$mutex_released=true`; SOLO después `$last_ingested`, `do_action('estandar_lead_ingested')` y telemetría post-ingesta. El lock ya no se retiene durante los listeners y permite reentrada de la MISMA identidad desde `estandar_lead_ingested`.
- **`finally` = red de seguridad**: libera solo si `$mutex_released === false` (retornos pre-COMMIT: error, duplicado idempotente, y `Throwable` pre-COMMIT). En el camino feliz no ejecuta una segunda liberación. Se eliminaron las 7 liberaciones manuales internas; `release_identity_mutex()` es además idempotente.
- `catch`: `ROLLBACK` **solo si la transacción sigue activa** (`$in_transaction`, se apaga tras `COMMIT` → un `Throwable` post-COMMIT en un listener NO deshace datos ya persistidos) + telemetría `LEADS_INGESTION_EXCEPTION` (clase, mensaje, `has_email_hash`/`has_phone_hash` — SIN PII/secretos) + **re-throw** para preservar el manejo de Forms.
- Fail-closed: NO reintentos generales, NO ingesta sin mutex; retornos/persistencia/dedup/eventos/`$last_ingested` intactos.

Diff conceptual del estado `$mutex_released`: `false` al entrar al `try` → tras `COMMIT` se ejecuta el release y pasa a `true` → el `finally` lo lee: `true`⇒no-op, `false`⇒libera (única vez). Retornos pre-COMMIT nunca lo tocan ⇒ el `finally` es su liberación única.

**Estrategia de recuperación de expirados (B)**: si `expires <= now` → `delete_option($value_key)` (API WP, coherente con caché, borra por NOMBRE) → **un** reintento de `add_option` (INSERT … ON DUPLICATE KEY UPDATE sobrescribe el expirado con nuestro token). Si aun así no se adquiere → fail-closed con telemetría **`LEADS_IDENTITY_MUTEX_RECOVERY_FAILED`** y etapa exacta derivada del retorno real de `delete_option()`: `delete_failed` (no removió fila) vs `reacquire_failed` (removió pero no re-adquirió). Lock vigente (no expirado) → `LEADS_IDENTITY_MUTEX_CONTENDED` con `stage=active_lock` + `seconds_remaining` (positivo distingue contención legítima de la fuga por expirado). `key_type` (email/phone) sin el hash.

**VERSION_BUMP**: estandar-leads **13.0.68 → 13.0.69** (header + `ESTANDAR_LEADS_VERSION`; A y B en el mismo release sin desplegar).

**Pruebas** — `tests/run-identity-mutex-release.php` (NUEVO), **48/48 PASS** en entorno local real:
- (1-3) new/recontact/duplicate idempotente liberan el lock, sin regresión.
- (4) error-return → rollback + libera. (5) Throwable pre-COMMIT → rollback + libera + re-throw + `LEADS_INGESTION_EXCEPTION`. (6) **re-submit inmediato de la MISMA identidad tras el Throwable = PASS**.
- (7) lock expirado se borra y se re-adquiere. (8) `delete_failed` y (9) `reacquire_failed` emiten telemetría con etapa exacta (proxy `$wpdb` acotado a la llave). (10) lock vigente → `CONTENDED/active_lock`, lock intacto.
- Timing (MUTEX_RELEASE_TIMING_V1): (11) **el listener `estandar_lead_ingested` observa el lock YA liberado** (orden `COMMIT→release→listener`) + **reentrada de la misma identidad dentro del listener = PASS**. (12) **una sola liberación lógica** (email-only → 1 borrado del lock, el `finally` no borra de nuevo). (13) **Throwable post-COMMIT en listener** → dato PRESERVADO (lead commiteado) + lock ya liberado + re-throw + telemetría. (balance) locks vuelven a baseline.
- Regresión: `run-terminal-reopen` + `run-deferred-submission-binding` + `run-commercial-journey-wordpress` (44) + `run-identity-collision` + `run-invariants` (10) = todas PASS. `php -l` OK.

**Riesgos residuales**: **fatales NO capturables** (agotar `memory_limit`, `max_execution_time`/E_ERROR, `wp_die`/`exit` dentro de un listener) siguen saltando el `finally` de PHP → un lock podría quedar huérfano hasta su expiración de 30 s; ahora **auto-recuperable** en el próximo submit de esa identidad (fix B) en vez de bloqueo indefinido. La causa raíz específica de Alto Jaramillo (identidad constante en el form o fatal upstream) NO se aborda aquí; requiere la config/logs de ese formulario. **GATE deploy**: `php -l` + `php tests/run-identity-mutex-release.php` (48/48) en la máquina de Rodrigo. **Versión sin cambio: sigue 13.0.69** (no desplegada — el timing se folda en el mismo release).

## PROJECT_RESOLUTION_ARCHITECTURE (frente transversal abierto 2026-06-18)

**AK_PROJECT_RESOLUTION_AUDIT_V1 = VERIFIED/CONFIRMED** (doc: `AK_PROJECT_RESOLUTION_AUDIT_V1.md`). Supersede la hipótesis "Forms bug" — el lead AK no vino por form. **Ya NO es caso AK: es bug de arquitectura transversal de proyectos.**

**ROOT CAUSE: el Project Registry NO está enforced.** Dos defectos encadenados:
1. Múltiple SSOT: el chatbot resuelve project_slug desde sus reglas (`leads-handler.php:677` `project_slug = $rule['project_slug'] ?? $rule['area']`), independiente del Registry; corta en Prioridad 3 antes del fallback monoproyecto (Prioridad 5 = ak-contadores).
2. Resolver no-enforcing: `resolve_slug()` (`class-estandar-leads-projects.php:56`) hace `return $candidate` cuando no matchea → persiste `lead.project_slug='contabilidad'` como proyecto válido → notifier no encuentra operador → fallback broadcast. Explica AK end-to-end (Registry=ak-contadores, lead=contabilidad, routing=fallback).

**PROJECT_REGISTRY_CONSUMERS_AUDIT_V1 = VERIFIED** (doc separado). Hallazgo: hay DOS funciones sin enforcement (`resolve_slug` pass-through + `normalize_slug` que nunca consulta registry). El cambio `return $candidate→''` NO es local: vía el WRITE de ingestion (`ingestion.php:560`) ripplea a Analytics (7 archivos), UI, webhook n8n. `resolve_slug` = nodo de propagación, NO origen.

**PROJECT_REGISTRY_ENFORCEMENT_V1 = DESIGN / NO_CODE_YET.** 5 reglas CONGELADAS (Rodrigo):
- RULE_01: project_slug solo puede provenir del Registry.
- RULE_02: project_area nunca es project_slug.
- RULE_03: service_category nunca es project_slug.
- RULE_04: slugs desconocidos son `unresolved`, no proyectos válidos.
- RULE_05: operadores solo pueden asociarse a proyectos existentes en Registry.

**PROJECT_REGISTRY_ENFORCEMENT_SPEC_V1 = APPROVED** (doc separado). Decisiones congeladas Rodrigo: unresolved=`''` (reusa máquina notifier existente) · compat histórica = display-mapping + backfill solo monoproyecto · RULE_05 (operadores) = Phase 3 posterior.

**PROJECT_REGISTRY_ENFORCEMENT_V1 / PHASE_1 = DESIGN_REVIEW** (doc `PROJECT_REGISTRY_ENFORCEMENT_V1_PHASE_1_DESIGN.md`). Scope = estandar-chatbot solo; enforce RULE_01/02/03 en origen. **HALLAZGO CRÍTICO**: el registry del chatbot (`build_project_registry`, estandar-chatbot.php:1003-1024) se AUTO-CONTAMINA — sintetiza proyectos `active=true` desde el `area` de las reglas → validar contra el registry del chatbot NO enforcearía RULE_01. **RULE_01 debe validar contra Registry CORE (`Estandar_Core_Project_Registry::get_all()`), no el derivado del chatbot.** Gate `resolver(candidate)→slug|''` en los emit points (leads-handler.php:677 elimina `?? area`, 705-708, 610-636, 724). No toca resolve_slug/ingestion/notifier/analytics/webhooks.

**PHASE_1 = IMPLEMENTED_SALADEPRUEBAS (2026-06-18, chatbot 10.6.1→10.6.2).** Aprobado por Rodrigo. Helper `estandar_chatbot_registry_resolve_slug()` valida contra `Estandar_Core_Project_Registry::get_all()` (CORE_OPTIONALITY guard; core ausente→'' sin promover) + telemetría `CHATBOT_PROJECT_UNRESOLVED`. Emit points enrutados: source_intent (572, quita `?? area ?? slug`), responsable (601, quita `?? area`), text_intent (677, quita `?? area`), analytics first_project_slug (724). NO tocado: resolve_slug, ingestion, notifier, analytics, webhooks, workspace, operadores, **build_project_registry (síntesis 1003-1024 = diferida)**, Prio 5 monoproyecto.
**EDGE flag (Rodrigo decide):** Prio 5 usa el registry contaminado del chatbot; si una regla sintetiza `contabilidad active=true`, count≠1 → no auto-asigna ak-contadores → lead queda `''`. ACEPTABLE (success criteria permite `''`): en AK monoproyecto el notifier hace broadcast = único operador (Juan Ignacio) → routing correcto; persistido='' (unresolved, honesto, visible por telemetría) en vez de 'ak-contadores'. Para persistir ak-contadores: sanear build_project_registry (Phase diferida) o apuntar Prio 5 a core. **GATE: `php -l` leads-handler.php + estandar-chatbot.php (sin PHP en sandbox).**

**PHASE_1 NEXT = OBSERVATION (no más código, decisión Rodrigo 18-jun).** Validar con leads reales: AK chatbot intención contabilidad → `project_slug != contabilidad` + `operator_assigned=Juan Ignacio`; Acacios Parronales → `parronales`/Valentina sin regresión; revisar telemetría `CHATBOT_PROJECT_UNRESOLVED` (sitio/regla/candidate_slug). **EDGE=OPEN → `''`→`ak-contadores` canónico = PHASE_2.**
**Dos precisiones de lectura (Claude):** (1) Test de reaparición = discriminador de Phase 2: si `Proyecto: contabilidad` reaparece, el emisor es NO-chatbot → ingestion via `resolve_slug` pass-through → evidencia directa de que Phase 2 se justifica; si no reaparece, chatbot era el único emisor y Phase 2 puede esperar. (2) `CHATBOT_PROJECT_UNRESOLVED` SUB-CUENTA: solo dispara con candidato project_slug no-vacío; fugas area-only (project_slug vacío) caen a '' SIN telemetría. ⇒ pocos eventos ≠ prueba de éxito; la verdad de campo es el outcome en la ficha (que `contabilidad` desaparezca), no el conteo.

**MATRIZ DE LECTURA DE OBSERVACIÓN (Rodrigo, congelada):**
```
A  contabilidad NO reaparece            → Phase 1 corrige origen; Phase 2 puede esperar
B  contabilidad reaparece               → emisor no-chatbot vía ingestion → Phase 2 NECESARIO
C  project_slug='' + asigna Juan Ignacio → routing OK; persistencia canónica = Phase 2, no bloqueo
D  project_slug='' + NO asigna operador  → NO es resolución de proyecto: es operador/notifier
                                           (LEADS_NOTIFIER_NO_OPERATORS / sync). Investigar esa capa, no el chatbot.
Telemetría: alto=deuda de config visible · bajo=NO prueba ausencia de fuga → revisar ficha real.
```
**Estado: PHASE_1=IMPLEMENTED · STATUS=OBSERVATION · NEXT_VALID_SIGNAL=próximo lead AK real · DO_NOT_PATCH=TRUE.**

## Proyectos activos

| Codename | Qué es | Estado |
|----------|--------|--------|
| **AK** / **AK Contadores** | akcontadores.cl — contabilidad empresas, chatbot lead_services | Deploy pendiente v10.2.4 |
| **Acacios** | acacios.cl — inmobiliaria multiproyecto, compliance activo | En observación (7-day post-bootstrap) |
| **SALADEPRUEBAS** | Entorno local SQLite de desarrollo y pruebas | Activo |

## Stack de plugins

| Plugin | Rol |
|--------|-----|
| **estandar-chatbot** | Chatbot IA, leads capture, configuración |
| **estandar-core-mu** | Core MU, project registry, seeding |
| **estandar-forms** | Formularios, routing por proyecto |
| **estandar-leads** | BD de leads, clasificación, compliance |
| **estandar-analytics** | Analytics, attribution, funnel |

## Personas clave

| Quién | Rol / Contexto |
|-------|----------------|
| **Valentina** | Operadora Acacios — recibe notificaciones de leads |
| **cm@acacios.cl** | CC en emails de leads Acacios |

## Términos técnicos frecuentes

| Término | Significado |
|---------|-------------|
| **SALADEPRUEBAS** | Entorno local de pruebas (SQLite) |
| **monoproyecto** | Sitio con un solo proyecto (AK, mayoría de clientes) |
| **multiproyecto** | Sitio con varios proyectos (Acacios) |
| **maybe_seed** | `maybe_seed_default_project()` — auto-siembra proyecto default |
| **compliance engine** | Motor de escalación de leads no clasificados (48h/96h/7d/14d) |
| **feedback_link** | Enlace de clasificación enviado al operador con cada lead |
| **classification rate** | % leads con quality/sales_stage/feedback_completed registrado |
| **FEEDBACK_LINK PASS** | Token de clasificación generado y enviado correctamente |

## Observaciones activas

| ID | Qué observar | Cuándo |
|----|-------------|--------|
| **Acacios Lead #41** | ¿Operador clasificó? Ver: quality, sales_stage, feedback_completed, last_classified_at | 48–96h desde 2026-06-08 |
| **Acacios adopción** | 9 leads acumulados al 10-jun (#41–#49: Altacruz 4 / Parronales 5, Marta/Valentina, 2 canales). Captura/atribución/routing/notificaciones = PASS. Medir: clasificados, contactados, sales_stage. Masa crítica suficiente para evaluar adopción el 12. **11-jun: captura continua post-consolidación de campañas** — 6 leads en ~2.5h (13:42 Yonathan → 16:07 Bryan, Parronales/ig·paid, IDs Meta 120248585650840457/…820457 crudos en email = evidencia P0). Decisión campañas 11-jun: mantener las 2 ganadoras y observar (regla NO EVALUABLE: 0 contactados registrados ≠ campaña mala). Benchmark adopción: AK 6/7 clasificados | Revisión 12-jun |
| **AK snapshot 11-jun** | 7 leads/~7d: **6/7 clasificados (~86%) = adopción feedback links alta** (benchmark para revisión adopción Acacios 12-jun; n=7, 1 operador — señal fuerte, muestra chica). 1 reunión agendada (Marcela = primer dato real para flip `qualified_event` AK), 2 contactados, 3 junk/spam, 1 perdido. Todos Contactos=0 pese a clasificación completa = firma del gap `contacted` hardcoded 0 en feedback.php:~195 (DECLARACIÓN sin EVENTO, en producción) — NO es inacción del operador; evidencia para P4, sin re-secuenciar. Campaña `22756503777` cruda en columna = evidencia viva de P0 CAMPAIGN_MAPPINGS. Denes routing fallback `contabilidad` = item ya cubierto por deploy v10.2.4. Compliance badges OK | Insumo para decisión qualified_event AK (30-60d post-P4) |
| **Polyfill V1.1 deploy** (Acacios, AK, Guillaume, Estandar, Casa Encuentro, 10-jun) | (1) Menús reagrupados donde core es plugin normal — en sitios con core en mu-plugins/ "sin cambios" = PASS. (2) Leads siguen llegando. (3) Atribución intacta. (4) Detector contaminación: telemetría `LEADS_NOTIFIER_PROJECT_ROUTE` con `project_slug=altacruz` fuera de Acacios = lead real mal ruteado (H2). Proyectos Acacios visibles en forms Guillaume = esperado hasta limpieza de datos, NO es deploy fallido | 24–48h → 12-jun |

→ Detalle completo: `memory/observations/acacios-lead-41.md`

## Reglas de desarrollo

- **Nunca romper producción** — no perder configuración ni leads existentes
- **CLIENT_ISOLATION_V1 (2026-07-17, ratificada 2026-07-17)**: Los plugins de la Suite son reutilizables entre clientes. **Prohibido hardcodear** nombres, slugs, IDs, semillas, dominios, correos, campañas, operadores, proyectos ni reglas específicas de ningún cliente en el core de ningún plugin. Esto aplica a: código ejecutable, defaults, seeds, herramientas compartidas, fixtures, ejemplos y documentación normativa. La configuración específica de cliente debe vivir en datos persistidos (DB, opciones, project registry), archivos de configuración externos al plugin (`data/`), hooks, o migraciones de instalación por sitio. Herramientas genéricas (`tools/`) deben recibir datos desde configuración externa, nunca hardcodearlos. Antes de implementar, revisar siempre esta regla.
    - **Regla para agentes**: antes de cualquier modificación, leer este documento. Confirmar que el cambio es tenant-agnostic. Buscar hardcodes al tocar defaults, seeds, fixtures, herramientas o ejemplos. Usar datos genéricos: `example-client`, `example-project`, `operator@example.test`, `https://example.test`. Mantener datos específicos fuera del plugin reutilizable. Documentar cualquier excepción antes de implementarla.
- **Regla UX transversal (ratificada 12-jun):** no simular información — si no hay evidencia, mostrar ausencia de evidencia (aplicada en Telemetría, Compliance, Leads, Analytics y Analítica Chatbot). Gráfico/lectura/insights deben derivar del MISMO dataset (patrón `$timeline_points`). Futuro V2: DATA_QUALITY_BADGE sobre gráficos (🟢 >50 eventos · 🟡 10–50 · ⚪ <10) — responde "¿puedo confiar en esta lectura?".
- **name= attributes** deben mantenerse idénticos al refactorizar forms
- Cambios en SALADEPRUEBAS → deploy manual a producción por Rodrigo
- Backlog congelado durante observación Acacios: P1 BEHAVIOR_ANALYTICS, P2 PROJECT_PERFORMANCE_V1, P3 PROJECT_MAP_V1
- **CORE_OPTIONALITY_V1.1** (2026-06-10, supersede V1): Core MU es opcional para todo plugin consumidor. Cada plugin define el polyfill `estandar_core_mu_is_active() → defined('ESTANDAR_CORE_MU_VERSION')` (detección dinámica en call-time). **Prohibido `return false` estático**: con core como plugin normal, los consumidores alfabéticamente anteriores definen el polyfill antes que core y la versión real nunca se registra → menús standalone con core vivo (regresión guillaume.cl, ver `PROJECT_CONTEXT_AUDIT_V1.md`). Matriz A/B incluye probe 1b (is_active vs core cargado, orden de carga real). Llamadas a símbolos de core (`estandar_core_*`, `estandar_render_*`, `estandar_register_*`, `estandar_admin_menu_*`, `Estandar_Core_*`, `ESTANDAR_CORE_*`) fuera de rama `is_active()` llevan `function_exists`/`class_exists`. Las ramas standalone no pueden llamar símbolos de core (bug clásico: `estandar_render_tabs` en el fallback). Patrones canónicos: gate `kickoff/class-kickoff-admin.php:340` · fallback `delivery-locator/class-settings.php:251`.
- **Canon CONVERSATION_DATASET** (congelado 2026-06-12): `wp_ecb_conversations` = ACTIVO ESTRATÉGICO (corpus conversacional: hasta 80 msgs/sesión rolling, incluye sesiones sin lead, sin purga automática; join nativo a leads vía `lead_email`/`lead_phone` seteados por `bind_conversation_to_lead`). PROHIBIDO: eliminar, truncar, migrar destructivamente, consolidar dentro de Leads. Lead = consecuencia; conversación = evidencia. Dominios separados: Leads = comercial (persona→contacto→clasificación→resultado) · Conversations = conversacional (sesión→mensajes→intención→fricción→abandono→aprendizaje). Scripts read-only: `estandar-chatbot/tools/estandar-transcript-audit.php` + `estandar-conversation-dataset-audit.php` (correr por sitio: SALADEPRUEBAS → Acacios → AK; primera corrida valida el script mismo). **Regla de decisión congelada 12-jun:** ratio sesiones/leads > 3:1 → pantalla propia de Inteligencia Conversacional; ≤ 3:1 → bloque E dentro de Captación IA. No tocar UI antes de números reales. Contexto: CHATBOT_LEADS_REFACTOR F1-F4 (extraer CRM del chatbot preservando el sistema conversacional; tab futuro = "Captación IA" con bloque E Inteligencia Conversacional; join comercial `chatbot_json.chatbot_lead_id ↔ ecb_leads.id`).
- **CONVERSATION_RETENTION_POLICY_V1** — STATUS = FUTURE · PRIORITY = P3: transcripts contienen PII en texto libre (y viaja en emails vía `{{conversation_recent}}`), sin cifrado ni política de purga. Definir retención por instalación cuando el corpus escale (~100k+ msgs). No actuar ahora; nunca delete ad-hoc.
- **Canon /go/whatsapp** (congelado 2026-06-10): owner estandar-leads; alias de rewrite sobre `/estandar-leads/v1/whatsapp-redirect` (un solo handler); evento `whatsapp_click` registrado por Leads → Analytics opcional (function_exists, ya implementado); Signal Core NO es requisito (futuro opcional). Prohibido `wa.me`/`api.whatsapp.com` directo en forms/chatbot/emails/botones. Tracking server-side (302 no dispara pixels client-side).

## Batch CHATBOT_UX_REVIEW 2026-06-12 (PENDING_DEPLOY)

6/6 tabs revisados e implementados en SALADEPRUEBAS: `inicio.php` (mapa geo dominante + ranking), `configuracion.php` (perfiles compactos, preview auto-visible, bloque modifica/no-modifica, flow editor visual, cobertura páginas), `leads-captacion.php` (CAPTURA_V3: confirm recomendada, acordeón Motor conversacional, CTAs contextuales, toggle técnico), `apariencia.php` (preview sticky lateral, header compacto, branding en acordeón; fix: picker burbuja estaba muerto), `ia-contexto.php` (card resumen, Motor IA acordeón, Conocimiento de la empresa; fix: form anidado rollback), `analitica.php` (trend chart: dataset único `$timeline_points`, empty/low states, oportunidades N-bajo). Aparte, **core-mu**: `telemetry.php` (info=Evento sin Resolver, 3 vistas) → va en deploy de core, NO en batch chatbot. Secuencia aprobada: F1 php -l 7 archivos + QA visual SALADEPRUEBAS → F2 deploy Acacios + observación 24-48h → F3 recién entonces Conversation Dataset Audit (scripts en `estandar-chatbot/tools/`). No abrir Captación IA / Conversations Intelligence / Project Registry SSOT antes de SALADEPRUEBAS=PASS, ACACIOS=PASS, DATASET_AUDIT=REAL_DATA.

## Release batch 2026-06-12

- **EMAIL_NOTIFICATION_UX_V2** — IMPLEMENTED en SALADEPRUEBAS 2026-06-10 (items 1–6; item 7 onboarding-removal diferido por decisión de Rodrigo). Archivos: `templates/email/lead-notification.php` + `class-estandar-leads-email.php` (blocklist apellido + `full_lead_name()`). Presentacional puro. Pendiente: deploy manual a producción en batch 12-jun. No desplegar antes del 12 (observación Lead #41 en curso).
- **EMAIL_PHONE_DISPLAY_NORMALIZATION_V1** — IMPLEMENTED en SALADEPRUEBAS 2026-06-10. Teléfono visible en email pasa por `Estandar_Leads_Normalizer::phone()` con fallback a raw. Display only: BD, captura, atribución y link WhatsApp intactos. Mismo batch 12-jun.
- **SHEETS_PHONE_EXPORT_V1** — IMPLEMENTED en SALADEPRUEBAS 2026-06-10. `your-phone` en payload cf7_compat = normalizado sin `+` (dígitos puros), vía `sheets_safe_phone()` en webhook dispatcher. Decisión: dígitos sin `+` en vez de apóstrofe (apóstrofe falla con valueInputOption RAW en n8n y contamina otros consumidores). Fallback solo-dígitos bloquea formula injection. Email mantiene `+`, BD/WhatsApp intactos. Mismo batch 12-jun.
- **PREVIEW_REQUIRED** (gate pre-deploy): Rodrigo ejecuta `Leads → Preview Email` con 4 casos: con teléfono / sin teléfono / chatbot / formulario. Parser PASS ≠ Render PASS. Adicional: correr `tests/run-webhook-dispatcher.php` en local (requiere WP vivo) y verificar `your-phone` sin `+` en payload cf7_compat.
- **PREFLIGHT_REQUIRED** (gate pre-deploy, estático): `php tools/estandar-preflight.php` — sweep de símbolos de core sin guard + presencia de polyfill. Cualquier FAIL = DEPLOY_BLOCKED. No requiere WP vivo. **Primera corrida local valida el script mismo** (escrito en sandbox sin PHP). Si arroja falsos positivos: mejorar el tracker de bloques (`function_exists`/`defined`), **nunca ensanchar la ventana de 3 líneas** (ventana mayor = riesgo de falso negativo). Principio del gate: FAIL falso = 2 minutos; PASS falso = producción. Criterio de aceptación: corrida → revisar FPs → mejorar tracker → segunda corrida → PASS.
- **RELEASE_RULE_V1** (15-jun, Rodrigo, refinada por VERSION_BUMP_AUDIT_V1): objetivo = evitar deuda de liberación indefinida (hallazgo VERSION_BUMP_MATRIX_V1: 7/8 plugins acumulan delta sin bump desde 10-jun). **Versión adoptada = ceiling temporal, no conteo de implementaciones**: ninguna iniciativa en estado IMPLEMENTED/ACCEPTED_FOR_QA puede esperar más de **5 días** sin bump+deploy del plugin que toca, sin importar cuántas otras iniciativas se acumulen en paralelo. Razón del cambio vs. propuesta original ("máx 2 implementaciones sin bump"): un conteo por-implementación penaliza trabajo en batch coherente (10-jun: 4 iniciativas de leads landed el mismo día como un solo release candidato) y no captura el fallo real observado (batch listo 10-jun, agendado 12-jun, aún sin desplegar 15-jun = slip de tiempo, no de conteo). El conteo de iniciativas sigue siendo útil como SEÑAL (>3 iniciativas acumuladas en un plugin = revisar si siguen siendo "un batch" o ya son dos), pero el GATE es el reloj.

## Estado congelado 2026-06-10 (sesión Core Optionality + Conversión)

**DEPLOY NOW** (independiente del batch): hotfix polyfill `estandar-leads.php` al sitio del incidente (Casa Encuentro). 5 líneas, cero cambio funcional, elimina fatal de admin + captura/email/cron. **Usar la versión V1.1 dinámica** (`defined('ESTANDAR_CORE_MU_VERSION')`), no el `return false` original — el estático reintroduce la regresión de menús si core corre como plugin normal.

**READY IN SALADEPRUEBAS** (aplicado 2026-06-10): CORE_OPTIONALITY_CLOSURE — polyfill V1.1 dinámico en 6 plugins (analytics, chatbot, kickoff, signal-core, forms, leads) + 4 guards (`leads-admin:76`, `forms/dashboard`, `cpt-manager`, `analytics/admin-ui`) + gate notice en analytics dashboard. Validación: `estandar-leads/tests/run-core-optionality-matrix.php` (escenario A normal; escenario B renombrando `estandar-core-mu`; ambos exit 0; probe 1b nuevo asierta is_active == core cargado bajo orden real de carga). **Pendiente: ejecución local de la matriz** (sandbox sin PHP).

**PROJECT_CONTEXT_AUDIT_V1** (2026-06-10, post-deploy guillaume.cl — detalle en `PROJECT_CONTEXT_AUDIT_V1.md`): H4 menús = polyfill estático ganaba por orden de carga → RESUELTO (V1.1). H2 ABIERTO post-12: read API del registry ignora `client_id` (`get_all`/`get_for_select` en `class-project-registry.php:159,298` + `Estandar_Leads_Projects::registry()` doble-fuente con duplicados) — selects pueden ofrecer proyectos Acacios en cualquier sitio; el fix rompe tests forms S15/R3/R4 (asumen seed Acacios local), van juntos. P2 datos: desactivar filas `client_id='acacios'` en DB guillaume (backfill ya las etiquetó; verificar con SELECT antes). H3 descartado: email ALTACRUZ = demo data de `preview()` (`class-estandar-leads-email.php:128`); cosmético pendiente: demo data neutra + `utm_campaign='altacruz'` en `?estandar_test_lead` (`estandar-leads.php:98`).

**SPECS ABIERTAS** (no implementar antes del 12): `TICKET_CONTACT_ATTEMPT_TRACKING_V1.md` (P1 — CTA WhatsApp del operador vía redirect tokenizado → log_contact, 0 taps) · `TICKET_NO_ANSWER_STATE_V1.md` (P2 — triage 5 estados operacionales; "no contestó" hoy es irrepresentable en el feedback form; `contacted` hardcoded 0 en feedback.php:~195; incluye P3 QUICK_ACTIONS_EMAIL como extensión opcional, 2 taps con confirmación POST anti-prefetch). **WHATSAPP_ASSISTED_CLASSIFICATION_V1 no es ticket separado** — es el workflow que P1+P2+P3 componen al completarse (decisión 2026-06-10, evita spec duplicada). **OCI tampoco es ticket nuevo** (decisión 2026-06-10): `Estandar_Leads_Ads_OCI` ya existe y exporta estados observables (`CONVERSION_STATUSES`, ver `ADS_FEEDBACK_MAPPING_v1.md`); está "inactivo" porque los estados upstream nunca se generan — P1+P2 lo alimentan sin tocar OCI. Reglas: NO exportar estados subjetivos (HOT/WARM/COLD); INVALID/SPAM = triage interno, no export (Google OCI no tiene conversiones negativas); Meta offline = infra inexistente, no abrir frente. **QUALIFIED_MODEL (decisión 2026-06-10, corrige modelado anterior)**: NO existe `AK_QUALIFIED` ni ninguna constante por cliente en código. `QUALIFIED` = primer hito comercial observable, **configurable por instalación** (`qualified_event` en settings de Leads). Prohibido `if site == X`. Dos contratos distintos, no mezclar: (1) **VOCABULARIO de eventos** = todos los observables (LEAD_CREATED, CONTACT_ATTEMPT, NO_ANSWER, CONTACTED, QUALIFIED, MEETING_BOOKED, PROPOSAL_SENT, SALE, DELIVERED, INVALID, SPAM) — alimentan Leads/Analytics/Compliance/reportes/recordatorios; (2) **OCI_EXPORT_WHITELIST** = solo intención comercial (QUALIFIED, MEETING_BOOKED, PROPOSAL_SENT, SALE/RESERVED, DELIVERED) — neutros NUNCA se exportan. Nota: `CONVERSION_STATUSES` actual ya contiene hitos inmobiliarios hardcoded (VISIT_SCHEDULED/VISITED/RESERVED) — generalizar en spec P1/P2. **Candidato AK: SIN CONGELAR** — flip en sesión (PROPOSAL_SENT ↔ MEETING_SCHEDULED, argumentos válidos ambos); decidir con 30-60 días de datos reales post P1/P2/P3. Descartado definitivo: HOT/WARM/COLD y opiniones del operador. MATRIX_A_B: STATUS = UNKNOWN hasta corrida local (verificación estática ≠ runtime PASS). Acacios usa `VISIT_SCHEDULED`/`VISITED`/`RESERVED`, sin QUALIFIED. Verificar vigencia de Google Ads API **v24** (`class-estandar-leads-ads-oci.php:42`; corregido 11-jun, nota anterior decía v18) antes de activar. WHATSAPP_BOT_CLASSIFICATION_V2 (bot/parser/n8n): solo si alguna vez se justifica, y se evalúa primero contra la lista negativa de OPERATIONAL_PIPELINE_V1.

**Roadmap post-12 congelado v3** (re-secuenciado 2026-06-11 PM — segunda re-secuencia del día; supersede v2 de la mañana): P0 CAMPAIGN_MAPPINGS_V1 → P1 MULTI_PROJECT_DEDUP_AUDIT → P2 ANALYTICS_NORMALIZATION_V1 → P3 ANALYTICS_V2 → P4 CONTACT_ATTEMPT_TRACKING → (diferidos post-P4, NO cancelados: NO_ANSWER_STATE → QUICK_ACTIONS_EMAIL → FOLLOW_UP_COMPLIANCE_V1 → INTEGRATION_READINESS_AUDIT_V1). **Razón del cambio de orden** (evidencia Acacios Leads #81/#84/#85, sesión 11-jun): 1 lead → múltiples proyectos/operadores/submissions/conversiones potenciales. Si la capa de identidad del lead está rota, todo lo medido y exportado hereda el error — la integridad de identidad/atribución precede a la producción de eventos. La composición WHATSAPP_ASSISTED_CLASSIFICATION (CONTACT_ATTEMPT + NO_ANSWER + QUICK_ACTIONS) sigue viva, solo pospuesta; dependencia dura de FOLLOW_UP_COMPLIANCE en CONTACT_ATTEMPT se mantiene. **GATE SPEC_REQUIRED: CERRADO 11-jun PM** — specs escritas: `ESTANDAR_CAMPAIGN_MAPPINGS_V1.md` (P0: ID→etiqueta; diccionario wp_option + resolver único + pantalla; sin sync/API/OAuth/tablas/cron; el resolver ES la fuente única "campañas" de Normalization) y `ESTANDAR_MULTI_PROJECT_DEDUP_AUDIT_V1.md` (P1: protocolo audit-only; pregunta central "¿qué representa un lead?" — A) 1 lead / B) 3 leads / C) 1 persona + N intereses / D) otro; NO produce fixes). **Evidencia fijada** (Acacios 11-jun): CASE_A #81 = routing bug (project_slug vacío → broadcast 5 operadores; fix desplegado) · CASE_B #84 = stressor de identidad (1 persona, 3 proyectos/forms en 51s → 1 lead, 3 submissions, múltiples operadores) · CASE_C #85 = routing correcto post-fix (Parronales→Valentina, 1 notificación). Separación clave: routing bug ≠ identity model bug. **Hallazgo de código 11-jun**: la ingestión YA deduplica por persona (`find_lead_id(email_hash, phone_hash)` + identity_mutex, `class-estandar-leads-ingestion.php:83-96`) — modelo implícito = 1 persona/1 lead/N submissions (variante de C); la pregunta real del audit es si los CONSUMIDORES (notifier/feedback/compliance/analytics/OCI/webhooks) respetan o colapsan esa separación (¿dónde viven project_slug/status/conversión: lead o submission?). **Pregunta derivada agregada 11-jun (probable centro del veredicto): ¿qué es una conversión — 1 por persona o 1 por interés/proyecto?** Test: si #84 compra Parronales, ¿qué reporta Analytics en cada proyecto, qué gclid recibe OCI, qué operador recibe crédito? Veredicto = **ENTITY_MODEL_MAP** (cada consumidor declara Lead-level o Submission-level con evidencia citada; mapa se llena solo en replay, hipótesis previas registradas aparte — regla anti-sesgo). Dato ya verificado que contradice la intuición: **OCI hoy es lead-level** (`enqueue()` lee `$lead->gclid`, `class-estandar-leads-ads-oci.php:113-119`) — un gclid por lead; en #84 exportaría el click "ganador" de la fila del lead, no necesariamente el del proyecto que convirtió. STATUS ambas specs = SPEC_READY · ROADMAP_V3 = COHERENT · WIP=1. Próximo trabajo post-batch limpio: replay forense de #84 (paso 2 del protocolo) → ENTITY_MODEL_MAP → veredicto. WIP=1 se mantiene. Construcción post-batch 12-jun; nada de esto toca el batch ni sus gates. OCI_SETTINGS_UX_V1: fuera del top-6 por decisión 11-jun (UI de adapter inactivo = churn presentacional, coherente con COMMERCIAL_EVENT_EXPORT_V1) → FUTURE. Conclusión operativa 11-jun: NO abrir Meta OCI, NO nuevos adapters, NO export layer; SÍ Campaign Mappings + Dedup Audit + Normalization (impacto visible inmediato en Acacios y AK). Consolidación de plugins diferida: no fusionar antes de estabilizar contratos de la capa comercial.

**COMMERCIAL_EVENT_EXPORT_V1** (2026-06-11, `ESTANDAR_COMMERCIAL_EVENT_EXPORT_V1.md`): **STATUS = ACCEPTED · IMPLEMENTATION = NONE · EFFECT = naming + canon.** OCI = adapter, no producto (evidencia: subscriber de `estandar_lead_status_changed`, whitelist, cola idempotente, cero dominio propio). El activo = historia de eventos comerciales (ratifica QUALIFIED_MODEL). La capa se llama COMMERCIAL_EVENT_EXPORT; adapters: Google Ads Adapter (OCI) + Webhook Adapter (n8n/Sheets). Spine de eventos ya existe: hooks `estandar_lead_captured` / `estandar_lead_ingested` / `estandar_lead_status_changed`. **Rule of two**: ninguna abstracción de export con n=1 consumidor real — fase de extracción solo si aparece segundo consumidor pagado. Drift detectado: `CONVERSION_STATUSES` en código (contiene CONVERTED/DEED_SIGNED, omite PROPOSAL_SENT/SALE) ≠ whitelist canónico — reconciliar cuando se toque el contrato (Normalization/P4), no antes; OCI inactivo = drift sin efecto en producción hoy. UI "Google Ads OCI" se queda como está. Audit absorbido por INTEGRATION_READINESS_AUDIT cuando llegue su turno.

**ANALYTICS_NORMALIZATION_V1 + ANALYTICS_V2** (congelado 2026-06-11, specs en `ESTANDAR_ANALYTICS_NORMALIZATION_V1.md` / `ESTANDAR_ANALYTICS_V2.md`): Auditoría de código encontró que Analytics actual ya tiene casi todo pero calcula mal — D1 (atribución gateada por `INNER JOIN ea_sessions` ignorando `first_utm_*` del lead → leads válidos invisibles) y D2 (3 definiciones de "venta", sets de estados hardcoded en ~9 queries; Acacios con RESERVED/SALE = 0 ventas en embudo). Orden vinculante: NORMALIZATION_V1 (5 fuentes únicas: atribución/embudo/ventas/estados→buckets/campañas; **read-side only, sin tablas ni columnas ni lead_status** — drift hacia estados = detener) → luego V2 como capa visual (Operador/Campaña/Proyecto → Resultado, panel 30-segundos). Posición en roadmap: **P2, tras MULTI_PROJECT_DEDUP_AUDIT** (re-secuencia v3 2026-06-11 PM); CONTACTADO queda subregistrado hasta CONTACT_ATTEMPT_TRACKING (ahora P4) — el criterio de aceptación del diff debe declararlo explícito. Regla congelada: campaña con leads y 0 contactados = NO EVALUABLE (cuello operativo), nunca "campaña mala". Aceptación: diff script viejo/nuevo, todo delta explicable por D1/D2; los números cambian y eso es el fix. Buckets configurables por instalación (principio QUALIFIED_MODEL). Dato verificado: `contact_attempts`/`first_contact_at` ya se escriben desde Portal (`class-estandar-leads-portal.php:376`) — CONTACTADO es calculable hoy, subregistrado hasta P1.

- **FOLLOW_UP_COMPLIANCE_V1** (APROBADO, spec pendiente; diferido post-P4 en roadmap v3 — numeración P# anterior obsoleta): Compliance pasa de "¿clasificaste?" a también "¿hubo seguimiento?". Detecta lead prometedor sin evidencia posterior (umbrales por estado/cliente: AK PROPOSAL_SENT+21d, MEETING_BOOKED+14d; Acacios VISITED+60d, CONTACTED+14d; VISIT_SCHEDULED/RESERVED sin seguimiento). Pasó evaluación contra lista negativa OPERATIONAL_PIPELINE_V1. Modelo canónico (ratificado 2026-06-10 tras dos intentos de drift): **solo historia de eventos, sin estados** — no existe `lead_status = gestionado/trabajando/pendiente`. Trigger = último evento observable + edad + **política por TIPO de evento**, 3 clases: (1) VENTANA — PROPOSAL_SENT+21d, VISITED+60d, MEETING_BOOKED+Xd → alerta; (2) RE-ARME — NO_ANSWER silencia 30d y re-arma (evita loop de Compliance reclamando la misma acción); (3) TERMINAL — SALE/DELIVERED/LOST/INVALID/SPAM silencian para siempre (sin esto, `now - last_event` alertaría sobre leads cerrados). Política = configuración por instalación, no estado en el lead. Regla de silenciado: DECLARACIÓN no silencia ("en gestión", "revisado", "seguimiento realizado", "interesado", HOT/WARM/COLD), EVENTO registrado por el sistema sí silencia (CONTACT_ATTEMPT, CONTACTED, NO_ANSWER, MEETING_BOOKED, PROPOSAL_SENT, SALE, LOST, INVALID, SPAM). Reminder por canal de escalación existente (email), SIN entidad tarea, SIN done/dismiss, SIN motor nuevo (extensión de Compliance, no "REMINDER_ENGINE"). **Dependencia dura: CONTACT_ATTEMPT_TRACKING** (P4 en roadmap v3). Si alguien pide "marcar como gestionado" → es CRM, rechazar.
- **INTEGRATION_READINESS_AUDIT_V1** (APROBADO, audit-only, no construir integraciones; diferido post-P4 en roadmap v3; absorbe conclusiones de COMMERCIAL_EVENT_EXPORT_V1): matriz ENTITY × CREATE/UPDATE/EXPORT (Lead, Classification, Contact Attempt, Sales Stage) + readiness flags (WEBHOOK/API/N8N/ZAPIER/CRM READY). Preliminar 2026-06-10: salida n8n parcialmente lista (`Estandar_Leads_Webhook_Dispatcher` + hooks `estandar_lead_captured`/`estandar_lead_status_changed`); entrada externa (REST autenticada para clasificación/sales_stage) probablemente inexistente. Trabajo de lectura, cero riesgo, sesión corta post-12.

**FUTURE**: WHATSAPP_REDIRECT_V1 (canon congelado, ver Reglas) · OPERATIONAL_PIPELINE_V1 — frontera congelada 2026-06-10: **objetivo = medir operación comercial; no-objetivo = gestionarla**. Vocabulario permitido (cerrado): lead creado · intento de contacto · no respondió · contactado · visita agendada · perdido · inválido · clasificación. Lista negativa (vinculante): NO CRM, NO pipeline drag-drop, NO notas internas, NO tareas, NO asignaciones automáticas, NO seguimiento comercial, NO calendarios, NO bandejas, NO hilos de conversación, NO scoring complejo, NO automatizaciones comerciales. Toda propuesta futura que agregue entidades/columnas de operación se evalúa contra esta lista antes de especificarse.

**Decisión infraestructura 2026-06-10**: ESTANDAR_PREFLIGHT_V1 = APPROVED e implementado (`tools/estandar-preflight.php`, gate estático, ver PREFLIGHT_REQUIRED). CORE_RECOVERY_MU / Dependency Registry / Health subsystem / safe mode propio / rollback = **DEFERRED**. Trigger de reapertura: segundo incidente que haya pasado preflight + matriz A/B + revisión y aun así rompa producción (= evidencia de fallo no detectable estáticamente). Razón: Casa Encuentro fue estáticamente detectable; n=1 incidente de clase ya mitigada no justifica subsistema permanente. Recovery, si algún día existe, va como MU standalone — nunca dentro de core-mu (el extintor no vive en la habitación que se incendia).

**Hallazgo rector de la semana**: la mejora ya no está en adquisición (captura/atribución/notificación/email/chatbot/forms = PASS) sino en evidencia operacional post-primer-contacto: quién fue contactado, quién respondió, quién pidió visita, quién desapareció. Docs: `CONVERSION_OPTIMIZATION_BACKLOG_V1.md` · `CORE_OPTIONALITY_AUDIT_V1.md` · `CORE_OPTIONALITY_REMEDIATION_PLAN_V1.md`.

## Deploy pendiente AK Contadores (v10.2.4)

Orden: estandar-core-mu → estandar-chatbot → estandar-forms  
Post-deploy: routing form 2 → `ak-contadores` · perfil `lead_services` · activar Compliance Engine

## HISTORICAL_INTELLIGENCE_V1 — STATUS = CLOSED (2026-06-14)

Cierra el frente abierto por HISTORICAL_SHEET_AUDIT_V1. Secuencia completa: audit (`ACACIOS_HISTORICAL_CONTACT_INTELLIGENCE_AUDIT_V1.md`) → sizing pass (`HISTORICAL_REPEAT_PROSPECTS_SIZING_V0.md`: 546 personas / 65 reincidentes / **repeat_rate = 11.9%** / 18 cross-project) → arquitectura (`COMPLIANCE_ROUTING_CONTEXT_AUDIT_V1.md`).

**Veredicto**: útil pero no estratégico. 11.9% cae en banda 5–15% (regla de decisión original) → lista estática útil, NO justifica MVP operativo nuevo.

**Conclusión rectora (la que importa, no el MVP)**:
```
HISTORICAL_INTELLIGENCE ≠ IDENTITY_SYSTEM
HISTORICAL_INTELLIGENCE ≠ COMPLIANCE
HISTORICAL_INTELLIGENCE ≠ ROUTING
```
`cross_project` NO participa en routing · `touch_count` NO participa en scoring · historical touches NO participan en compliance. Decisión que evita contaminar los tres sistemas con riesgo activo (identidad, compliance, routing) — previene deriva futura tipo CAMPAIGN_MAPPINGS/identity-model.

**HISTORICAL_CONTEXT_MVP** — STATUS = APPROVED_CONCEPTUALLY · **PARKED**. Diseño validado (portal-only, lazy lookup, read-only, sin schema, sin dedup, sin routing, sin compliance) pero no se implementa ahora. Reabrir solo cuando haya capacidad fuera del P1 activo.

**CONTACT_QUALITY_SSOT** — STATUS = **PARKED**. Mismo criterio: no abrir hasta cerrar P1 activo.

Prohibiciones de HISTORICAL_SHEET_AUDIT_V1 (importar/sincronizar/crear tablas/tocar Leads) se mantienen vigentes para cualquier reapertura futura de estos dos frentes parked.

**NEXT / WIP=1**: `INTEREST_ASSIGNMENT_V1` (Fase 1 de roadmap INTERESES_POR_EVENTO, ver sección MULTI_PROJECT_DEDUP_AUDIT_V1 = CLOSED). No abrir desarrollo nuevo hasta SPEC_REQUIRED de esa fase.

## MULTI_PROJECT_DEDUP_AUDIT_V1 — STATUS = CLOSED (2026-06-15)

Sucesor de COMPLIANCE_ROUTING_CONTEXT_AUDIT (evidence source) y COMPLIANCE_ROUTING_ROOT_CAUSE_AUDIT (verdict A: datos persistidos incorrectamente — `project_slug` mutable sobreescrito en cada recontacto vs `assigned_operator_*` frozen write-once, `class-estandar-leads-notifier.php:227-246`). Protocolo completo: `ESTANDAR_MULTI_PROJECT_DEDUP_AUDIT_V1.md`. Entregables Paso1/3 y Paso4/5: `MULTI_PROJECT_DEDUP_AUDIT_V1_PASO1_PASO3.md`, `MULTI_PROJECT_DEDUP_AUDIT_V1_PASO4_PASO5.md`.

**Veredicto ratificado**:
- ¿Qué representa un lead? → **C** (1 persona + N intereses). Ya implementado en almacenamiento (`find_lead_id(email_hash, phone_hash)` + identity_mutex) — falta en consumidores.
- ¿Qué es una conversión? → **por interés/proyecto**, no por persona.
- **Mecanismo: D, no C literal.** `es_lead_events.submission_id` (nullable FK) ya existe — es la misma columna que el canon FOLLOW_UP_COMPLIANCE (ratificado 2×, "historia de eventos sin estados") ya diseñó. "Interés" = proyección de `es_lead_events` filtrada por `submission_id`. Cero migración de columnas en `es_feedback_tokens`/`es_lead_classifications`/`es_lead_contacts`/`es_oci_queue`/`assigned_operator_*`.

**Patrón descubierto**: capa de ingesta (Notifier-routing, Webhook dispatcher) ya es submission-level/D-compatible — sin gap. Capa de estado comercial (Notifier-assignment, Feedback/Clasificación, Compliance, Analytics, OCI) es lead-level — 5 gaps declarados.

**Decisión de secuenciamiento (15-jun, Rodrigo)**: NO ticket único para los 5 gaps (riesgo de mezclar cambio de modelo + routing + compliance + analytics + export en una sola intervención inaislable). SÍ implementación escalonada bajo mecanismo D, cada fase = validación práctica antes de tocar el siguiente consumidor:

```
Fase 1 — INTEREST_ASSIGNMENT_V1  (NEXT/WIP=1, ORIGEN=MULTI_PROJECT_DEDUP_AUDIT_V1, DEPENDENCIA=ninguna)
  Gap: Notifier-assignment (assigned_operator_id global write-once → bug de routing ya verificado en prod: Samuel/Matías/Leonardo/Sergio)
  Entregable: evento OPERATOR_ASSIGNED{submission_id, operator_id}; lectura por interés donde hoy se lee assigned_operator_id

Fase 2 — COMPLIANCE_BY_INTEREST_V1     (depende de Fase 1)
Fase 3 — OCI_BY_INTEREST_V1            (impacto económico — gclid correcto por interés)
Fase 4 — ANALYTICS_NORMALIZATION_V1    (ya P2 en roadmap v3; absorbe gap D3 — funnel multi-interés cuenta 0/1 en vez de 1/proyecto)
Fase 5 — CLASSIFICATION_BY_INTEREST_V1 (más invasiva en UX/operación — feedback_link por interés)
```

Paso 2 (replay forense #84) queda como evidencia confirmatoria, anexable cuando Rodrigo tenga datos reales de Acacios — no bloquea ninguna fase.

**Orden ratificado (15-jun, Rodrigo)**: INTEREST_ASSIGNMENT_V1 primero (P1), CAMPAIGN_MAPPINGS_V1 después (P2). Razón: INTEREST_ASSIGNMENT corrige semántica operacional (asignación/compliance/seguimiento/clasificación/OCI futuro ya afectados, evidencia real #84/Samuel/Matías/Leonardo/Sergio); CAMPAIGN_MAPPINGS mejora observabilidad pero no corrige una decisión operacional incorrecta — generar más eventos/reporting sobre un modelo de interés aún no corregido sería observar mejor algo conceptualmente mal. Fase 1 es intervención acotada (NO toca Compliance/Analytics/OCI/Feedback) → riesgo contenido.

## Canon operativo (2026-06-16, actualizado WHATSAPP_FUNNEL_VISIBILITY_V1 + ROUTING_MATCH_V2 + CHATBOT_ATTRIBUTION_AUDIT_V1)

```
CLOSED: Dominio Acacios · Compliance Timing V2 · Contact Normalization · Historical Repeat Prospects · Historical Intelligence V1 · COMPLIANCE_ROUTING_CONTEXT_AUDIT · COMPLIANCE_ROUTING_ROOT_CAUSE_AUDIT · MULTI_PROJECT_DEDUP_AUDIT_V1 · VERSION_BUMP_AUDIT_V1 · UUID_IDENTITY_AUDIT_V1 · CHATBOT_ATTRIBUTION_AUDIT_V1
PARKED: Contact Quality SSOT · Historical Context MVP · COMPLIANCE_BY_INTEREST_V1 (Fase 2) · OCI_BY_INTEREST_V1 (Fase 3) · ANALYTICS_NORMALIZATION_V1 (Fase 4) · CLASSIFICATION_BY_INTEREST_V1 (Fase 5) · WHATSAPP_CHANNEL_V1 (dependencia: CONVERSATION_ENGINE_V2 + CONVERSATION_EFFECTIVENESS_AUDIT_V1 + telemetría LEAD_RESPONSE_ACCELERATION_V1)
WIP=1 (build) = INTEREST_ASSIGNMENT_V1 — STATUS = ACCEPTED FOR QA / OBSERVACIÓN (ver VERSION_SPLIT 10.3.9/10.3.10)
PARALLEL TRACK (audit, no consume WIP build) = CONVERSATION_EFFECTIVENESS_AUDIT_V1 — STATUS = READY_TO_RUN
HOTFIX PARALLEL = UUID_INTEGRITY_FIX_V1 — STATUS = IMPLEMENTED_PENDING_QA (15-jun, 3 archivos, ver sección abajo)
IMPLEMENTED_LOCAL / DEPLOY_PENDING = ROUTING_MATCH_V2 — operators_for_project() considera allowed_projects_json como Tier 2 (project_match → allowed_project_match → fallback); routing_mode en telemetría. Blocker: INTEREST_ASSIGNMENT_V1_OBSERVATION. Target: próximo release estandar-leads post-cierre P1. Archivo: class-estandar-leads-notifier.php
READY_FOR_DEPLOY = WHATSAPP_FUNNEL_VISIBILITY_V1 — desglose de intención comercial por canal (💬 WhatsApp / 📝 Formulario / 🤖 Chatbot / Otros) bajo etapa "Intención comercial" del funnel. Archivos: class-dashboard.php + admin/funnel.php. Release: estandar-analytics 10.4.1 (independiente de Leads)
NEXT_BUILD (al cerrar INTEREST_ASSIGNMENT_V1) = CAMPAIGN_MAPPINGS_V1 — STATUS = READY
QUEUED (tras CAMPAIGN_MAPPINGS_V1) = LEAD_RESPONSE_OBSERVABILITY_V1 — STATUS = APPROVED / IMPLEMENTATION_READY (dependencia: LEAD_RESPONSE_ACCELERATION_V1 PRODUCTION_VALIDATED; ver sección abajo)
PRODUCTION_VALIDATED / OBSERVATION_ACTIVE = LEAD_RESPONSE_ACCELERATION_V1 — infraestructura en producción Acacios, eventos reales confirmados (#152–#155), efectividad comercial aún no establecida (ver sección abajo)
```

## CHATBOT_ATTRIBUTION_AUDIT_V1 — STATUS = CLOSED (16-jun)

**Veredicto**: NO hay bug en Chatbot ni en el pipeline de Leads. La arquitectura funciona correctamente.

**Pipeline confirmado (end-to-end)**:
```
chatbot.js → ea_sid (cookie/localStorage)
→ POST payload: attribution.session_id = ea_sid
→ leads-endpoint.php → do_action('estandar_lead_captured', [...attribution.session_id...])
→ class-estandar-leads-ingestion.php:30 → Estandar_Leads_Attribution::merge_analytics($attr)
→ merge_analytics(): session_id presente → estandar_analytics_get_attribution_by_session()
→ SELECT ea_sessions WHERE session_id = ea_sid → retorna utm_*, gclid, fbclid
→ first_session_id, first_fbclid, first_utm_* escritos en es_leads ✓
```

**Causa raíz del Lead #42 (session_id vacío, fbclid vacío)**: no es bug del chatbot. Es D1 de ANALYTICS_NORMALIZATION_V1: `ea_sessions` no se actualiza cuando llega un fbclid sobre una sesión preexistente → `merge_analytics()` retorna la sesión antigua sin fbclid. Usuario recurrente (o sesión iniciada antes del click de Meta Ads) → sesión existe pero sin fbclid → `first_fbclid = ''`.

**OCI Risk**: revisado de HIGH → MEDIUM-LOW. El problema queda acotado a D1 (update de sesión). La atribución funciona correctamente en primeras visitas o cuando no hay sesión previa.

**Absorbe en**: ANALYTICS_NORMALIZATION_V1 (P4 en roadmap). No requiere fix aislado del chatbot. No bloquea P1, CAMPAIGN_MAPPINGS_V1, ROUTING_MATCH_V2, COMPLIANCE_WORKLIST_V1.

**D1 confirmado por evidencia real** (Lead #42): `ea_sessions` no hace UPDATE de fbclid cuando la sesión preexiste. Fix: cuando llega fbclid en request y la sesión ya existe con fbclid vacío → actualizar.

## UUID_INTEGRITY_FIX_V1 — STATUS = IMPLEMENTED_PENDING_QA (15-jun)

Origen: `UUID_IDENTITY_AUDIT_V1` — leads #154 y #155 en Acacios comparten UUID `9724cf00-ad56-4418-b5ed-641d979bf806`. Severidad HIGH. Causa raíz: `form-render.php` generaba `lead_uuid` server-side con `wp_generate_uuid4()` baked en HTML estático → page cache congela ese valor → múltiples usuarios distintos reciben el mismo UUID → `find_lead_id()` no hace match (emails/phones diferentes) → dos leads nuevos con UUID idéntico. Agravante: `es_leads.lead_uuid` solo tiene `KEY` (índice normal), sin `UNIQUE` constraint — ningún guard de DB.

**3 archivos modificados (additivos, sin schema, sin migración)**:
1. `estandar-forms/public/form-render.php:137` — campo `lead_uuid` ahora `value=""` (vacío). Comentario explícito: "UUID populated client-side".
2. `estandar-forms/assets/js/estandar-forms-public.js` — `bindForms()` rellena `lead_uuid` con `createSubmissionToken()` (ya existía: `crypto.randomUUID()` + fallback PRNG). Condición `!uuidInput.value` → idempotente si ya tiene valor.
3. `estandar-leads/includes/class-estandar-leads-ingestion.php:42` — llama `ensure_unique_lead_uuid($uuid)` nuevo método privado: SELECT por UUID en `es_leads`, si existe regenera con `wp_generate_uuid4()`, hasta 3 intentos. Red de seguridad para cache miss, payload manipulado, importación externa.

**Chatbot path no afectado**: chatbot no envía `lead_uuid` en payload → siempre genera server-side en ingestion, no en render → no susceptible al cache bug.

**Fase 3 — UNIQUE INDEX — PARKED**: requiere auditar cuántos duplicados existen en producción antes de ALTER TABLE. Query de auditoría (correr en phpMyAdmin/WP-CLI en Acacios):
```sql
-- Paso 1: contar duplicados en es_leads
SELECT lead_uuid, COUNT(*) AS n
FROM wp_es_leads
GROUP BY lead_uuid
HAVING COUNT(*) > 1
ORDER BY n DESC;

-- Paso 2: ver qué leads están afectados
SELECT id, lead_uuid, name, email, created_at
FROM wp_es_leads
WHERE lead_uuid IN (
    SELECT lead_uuid FROM wp_es_leads
    GROUP BY lead_uuid HAVING COUNT(*) > 1
)
ORDER BY lead_uuid, id;

-- Paso 3: mismo check en submissions
SELECT lead_uuid, COUNT(DISTINCT lead_id) AS leads_distintos, COUNT(*) AS total_submissions
FROM wp_es_lead_submissions
GROUP BY lead_uuid
HAVING COUNT(DISTINCT lead_id) > 1
ORDER BY leads_distintos DESC;
```
Resultado del Paso 1 determina si se necesita proceso de reparación histórica antes del UNIQUE INDEX. Si n=2 (solo #154/#155): migration simple. Si n > 20: backfill script primero.

**QA gate (antes de deploy a Acacios y AK)**:
- `php -l` en los 3 archivos modificados
- Caso sintético: cargar form → inspeccionar field `lead_uuid` en DOM → debe ser UUID válido v4, distinto en cada page load incluso con cache purgada
- Verificar fallback: deshabilitar `crypto.randomUUID` en devtools (override) → campo debe recibir el token PRNG (no vacío)
- En ingestion: enviar payload con UUID ya existente en DB → verificar que ingestion genera UUID nuevo para el lead nuevo

**Prioridad de deploy**: HOTFIX — antes de CAMPAIGN_MAPPINGS_V1. Puede ir junto con INTEREST_ASSIGNMENT_V1 (estandar-leads 10.3.10) o en bundle 10.3.9 si se decide incluirlo. Decisión: estandar-forms toca archivos distintos → puede ir como release separado o bundled. No bloquea la observación P1 de INTEREST_ASSIGNMENT_V1.

**LEAD_RESPONSE_ACCELERATION_V1 — STATUS = PRODUCTION_VALIDATED / OBSERVATION_ACTIVE (actualizado 15-jun)**: infraestructura validada en producción Acacios. Evidencia real: leads #152–#155 con eventos `autoresponse_email_sent`, `autoresponse_email_failed(no_email)`, `autoresponse_link_click`, `autoresponse_engaged` registrados. Efectividad comercial aún no establecida (observación activa). **OBSERVATION_WINDOW_48H** (criterio de evaluación, sin UI ni código — revisar a las 48h desde 15-jun): `autoresponse_sent` · `autoresponse_failed_real` (excluyendo no_email) · `pdf_click` · `whatsapp_click` · `engaged_unique` · `engagement_rate = engaged_unique / sent`. Motor declarado OPERACIONALMENTE ESTABLE; lo que falta es % de interacción real con prospectos externos (≠ prueba interna #152). Separación canónica ratificada: LEAD_RESPONSE_ACCELERATION_V1 = infraestructura (emails + tracking + eventos); LEAD_RESPONSE_OBSERVABILITY_V1 = capa de visualización encima (ver sección siguiente). `LEAD_INTENT_OBSERVABILITY_V1` queda como descriptor de la capacidad derivada, no como iniciativa propia — no rename. Implementación original: Fases 1-2 completas (autoresponse email + link/pixel tracking vía `/go/{token}`) — 8 archivos, additivo, detalle en `ESTANDAR_LEAD_RESPONSE_ACCELERATION_V1.md`. Eventos: `autoresponse_email_sent/failed`, `autoresponse_email_open_signal`, `autoresponse_link_click`, `autoresponse_engaged`; todos con metadata `autoresponse_project_id`/`autoresponse_operator_id`.

**LEAD_RESPONSE_OBSERVABILITY_V1 — STATUS = APPROVED / IMPLEMENTATION_READY (15-jun)**: capa de visualización encima de LEAD_RESPONSE_ACCELERATION_V1. **Alcance canónico**: (1) bloque en ficha lead — muestra eventos de respuesta del prospecto por interés/submission; (2) indicadores en listado — señal visual de engagement en la tabla de leads; (3) contadores en dashboard. **Contratos duros**: NO genera eventos, NO modifica comportamiento, NO toca scoring, NO toca clasificación. Solo consume `autoresponse_email_sent`, `autoresponse_email_failed`, `autoresponse_link_click`, `whatsapp_click`, `autoresponse_engaged` ya presentes en `es_lead_events`. Dependencia: LEAD_RESPONSE_ACCELERATION_V1 PRODUCTION_VALIDATED ✓ (cumplida). Criterio de éxito: Valentina y Marta pueden ver en la ficha del lead si el prospecto abrió el email o clickó un enlace, sin abrir una segunda herramienta. Queued tras CAMPAIGN_MAPPINGS_V1 (slot NEXT_BUILD post-cierre INTEREST_ASSIGNMENT_V1).

**ORDEN ABIERTO 15-jun: RESUELTO (mismo día, Rodrigo)** — se adopta la lectura "lista numerada": CAMPAIGN_MAPPINGS_V1 ocupa NEXT_BUILD; CONVERSATION_EFFECTIVENESS_AUDIT_V1 corre en paralelo como audit read-only — no compite por el slot de build. LEAD_RESPONSE_OBSERVABILITY_V1 entra al backlog detrás de CAMPAIGN_MAPPINGS_V1.

## COMPLIANCE_OPERATOR_WORKLIST_V1 — STATUS = SPEC_PARTIAL (15-jun)

**Alias registrado**: `COMPLIANCE_V2_EMAIL_VOLUME_CONTROL` = mismo trabajo. Nombre canónico: `COMPLIANCE_OPERATOR_WORKLIST_V1 Fase 1`. No usar el alias en changelogs ni specs.

Spec: `ESTANDAR_COMPLIANCE_OPERATOR_WORKLIST_V1.md`. Origen: hallazgo operacional de Rodrigo — el Compliance Engine actual (recordatorios 48h/96h individuales + escalamiento 7d/14d + resumen diario condicional) genera ruido sin aumentar clasificaciones → fatiga de operadoras.

- **FASE 1** (eliminar 48h/96h individuales, resumen diario incondicional, mantener escalamiento supervisor 7d/14d/weekly) = **SPEC_READY + DESBLOQUEADA** (17-jun, P1 OBSERVATION cerrado). Nota de diseño: el "resumen diario" actual (`send_daily_digest`) es un fallback condicional al nivel 48h + threshold de 3, no un digest incondicional — FASE 1 requiere volverlo el mecanismo primario, no solo borrar los individuales. Secuencia de implementación: CAMPAIGN_MAPPINGS_V1 → COMPLIANCE_OPERATOR_WORKLIST_V1 Fase 1 (próximo slot build, mismo ciclo o inmediato).
- **FASE 2/3 → RENOMBRADO `COMPLIANCE_OPERATOR_DASHBOARD_V1`** (15-jun, Rodrigo elige explícitamente opción (b))**: APPROVED CONCEPTUALMENTE, sin spec detallada ni slot asignado. Características acordadas: solo lectura, vista resumida + filtros + agrupación, enlaza al flujo `feedback_link` existente, NO modifica estado/asigna/comenta/gestiona — responde únicamente "¿qué leads debo clasificar ahora?". Respeta `OPERATIONAL_PIPELINE_V1` sin reabrirlo (opción (a) descartada). **Diagnóstico de fondo (15-jun)**: el dolor no es "demasiados leads", es "el correo se volvió la interfaz de trabajo" — por eso DASHBOARD_V1 se evalúa como el de mayor alivio percibido/adopción de las 4 iniciativas activas, aunque sin slot. **Nota de diseño para cuando le toque turno**: NO mostrar conteo total crudo ("117 pendientes" → parálisis); mostrar buckets de acción inmediata por antigüedad, ej. `🔴 N críticos (>7d) · 🟠 N vencidos (4-7d) · 🟢 N nuevos (<48h)` + "Total visible hoy: N" — mismo principio "panel de 30 segundos" de ANALYTICS_V2.

**Hallazgo 15-jun RETIRADO por Rodrigo**: la hipótesis de nombres repetidos (Ignacio, Marcela, Poulette, Alexander, Christian, Tanya) recibiendo compliance 2x fue una inferencia no verificada (mezcla de correos masivos vistos + suposición), no evidencia confirmada. Retirada explícitamente — no tratar como hallazgo real. `COMPLIANCE_NOTIFICATION_DEDUP_AUDIT_V1` descartado (no solo "no crear nueva iniciativa" sino "no hay evidencia de duplicación que auditar"). Lo que sí hay evidencia de: alto volumen percibido + fatiga operacional + necesidad de mejor interfaz — ya cubierto por COMPLIANCE_OPERATOR_WORKLIST_V1/DASHBOARD_V1. La observación P1 activa (Evidencia A, Señales 2-3) sigue siendo el canal correcto si aparece duplicación real de compliance en Acacios.

Roadmap (15-jun, ratificado): INTEREST_ASSIGNMENT_V1 observación → CAMPAIGN_MAPPINGS_V1 → COMPLIANCE_OPERATOR_WORKLIST_V1 Fase 1 (al cierre de observación P1) → LEAD_RESPONSE_ACCELERATION_V1 (queued) → CONVERSATION_EFFECTIVENESS_AUDIT_V1 (paralelo). APPROVED_CONCEPT sin slot: COMPLIANCE_OPERATOR_DASHBOARD_V1. **Nada se mueve hasta cierre de observación P1** — esa observación entrega datos reales de recontactos/asignación por interés/compliance que informan decisiones posteriores.

**OBSERVATION_WINDOW_P1 — CERRADO (17-jun)**. Veredicto: **PASS**. Próximo release: CAMPAIGN_MAPPINGS_V1 + ROUTING_MATCH_V2 (bloqueador removido; antes era "tras P1 cierre"). COMPLIANCE_OPERATOR_WORKLIST_V1 Fase 1 entra al siguiente slot (secuencia: CAMPAIGN_MAPPINGS → COMPLIANCE_WORKLIST). LEAD_RESPONSE_OBSERVABILITY_V1 queued (no abre COMPLIANCE_OPERATOR_DASHBOARD_V1 ni Meta durante este ciclo).

**WHATSAPP_REDIRECT_V1 vs WHATSAPP_CHANNEL_V1 (aclaración 15-jun, evita confusión futura)**: son dos cosas distintas con nombres parecidos.
- **WHATSAPP_REDIRECT_V1** = "Canon /go/whatsapp" (arriba, congelado 2026-06-10) — YA IMPLEMENTADO, owner estandar-leads, redirect tokenizado server-side `/go/whatsapp` → operador. Sigue activo, NO está parked, no depende de nada nuevo.
- **WHATSAPP_CHANNEL_V1** = concepto nuevo (15-jun), hipotético canal conversacional completo vía WhatsApp (el chatbot conversa por WhatsApp, no solo redirige). STATUS=PARKED, dependencia=CONVERSATION_ENGINE_V2 (evidencia de CONVERSATION_EFFECTIVENESS_AUDIT_V1).

Parking WHATSAPP_CHANNEL_V1 no afecta WHATSAPP_REDIRECT_V1 en absoluto — son capas distintas (tracking de un CTA existente vs. un canal conversacional nuevo).

## INTEREST_ASSIGNMENT_V1 — STATUS = IMPLEMENTED_PENDING_QA (2026-06-15)

Spec: `ESTANDAR_INTEREST_ASSIGNMENT_V1.md`. GO de Rodrigo: D1 (no tocar `sync_assigned_operator()`), D2 (incluir lista+filtro "mis leads"), D3 (sin backfill). Implementado en SALADEPRUEBAS, additivo, sin schema/migración:

- `class-estandar-leads-notifier.php` — `notify_operators()` emite `operator_assigned{submission_id, operator_id, project_slug}` por operador notificado. `sync_assigned_operator()` intacto.
- `class-estandar-leads-repository.php` — nuevos `operators_by_submission()` / `operator_for_submission()` (leen `es_lead_events`, sin columnas nuevas).
- `class-estandar-leads-portal.php` — filtro `operator_id` en `build_where()`: `assigned_operator_id = %d OR EXISTS(operator_assigned event)`.

**ACCEPTED FOR QA (15-jun, Rodrigo)** — checklist D1/D2/Schema/Migration/Compliance/Analytics/OCI todos PASS. Secuencia de QA:
```
1. php -l + caso sintético en SALADEPRUEBAS:
   Lead A — Submission 1 (Proyecto X → Operador X), Submission 2 (Proyecto Y → Operador Y)
   Validar: 2 eventos operator_assigned · history correcto · "mis leads" muestra el lead
   para Operador X Y para Operador Y · assigned_operator_id sin cambios
2. Deploy Acacios
3. Observación real 48-72h
```
`INTEREST_SCORE_V1` = PARKED (requiere INTEREST_ASSIGNMENT_V1 estable primero).

**VERSION_SPLIT (15-jun, Rodrigo, ratifica VERSION_BUMP_MATRIX_V1 §3)**: estandar-leads se libera en DOS versiones, no una:
```
10.3.9  = bundle estable (CORE_OPTIONALITY_V1.1 + EMAIL_NOTIFICATION_UX_V2 +
          EMAIL_PHONE_DISPLAY_NORMALIZATION_V1 + SHEETS_PHONE_EXPORT_V1)
          → ya pasó gate, listo para red completa, libera deuda 10-jun

10.3.10 = INTEREST_ASSIGNMENT_V1 únicamente
          → gate propio: QA local → Acacios-only → observación 48-72h → red completa
```
Razón: INTEREST_ASSIGNMENT_V1 modifica comportamiento (asignación por interés); el resto modifica infraestructura (polyfill, presentación, export). Gates distintos → versiones distintas. Si un problema aparece en Acacios post-10.3.10, la causa queda aislada (no mezclada con el bundle de infraestructura ya estable).

**Protocolo de observación P1 — evidencia a seguir (lotes de emails reales)**:
- A. Duplicados de compliance (mismo lead, mismo nivel, misma ventana)
- B. Reincidentes cross-project (Karen, Matías y similares)
- C. Routing vs proyecto actual (**riesgo principal**)
- D. Asignación operador vs último submission
- E. Escalamiento 48h/96h/7d/14d sin duplicación

**Señales que disparan investigación inmediata**:
1. Operador A recibe lead de Proyecto B
2. Mismo lead recibe dos tokens distintos para el mismo estado
3. 96h enviado dos veces al mismo destinatario
4. Recontacto generado sobre proyecto incorrecto
5. Lead reincidente cambia proyecto pero conserva operador histórico

**PASS confirmado (correos recientes)**: leads entran · dominio funciona · Meta genera volumen · teléfonos normalizados · "sin email" aparece correctamente · recontacto detecta reincidencia.

## OBSERVACIÓN P1 — ESTADO = CERRADO (2026-06-17)

**Evidencia decisiva**: Lead #87 (José Muñoz, Acacios).

**Validación final P1**:
- Recontacto = PASS (3 submissions, 123 horas, consolidadas correctamente)
- Attribution = PASS (utm_source/medium/campaign/fbclid/session_id presentes)
- Lead consolidation = PASS (1 lead, 3 submissions, sin duplicados)
- Compliance engine = PASS (48h/96h/7d ejecutados sin duplicación)
- Workspace adoption = PASS (portal abierto 2×, operador accedió)

**Hallazgo arquitectónico rector**: OPENED_WORKSPACE ≠ MANAGED_LEAD. Tras 3 ingresos, 2 aperturas de portal, compliance ejecutado 2×, sigue SIN clasificación, SIN WhatsApp, SIN acción posterior. Eso demuestra que el cuello de botella ya no está en captura/notificación/compliance — está en **acción comercial post-notificación**.

**Nueva métrica emergente**: RECONTACT_WITHOUT_ACTION (telemetría operacional, no KPI). Definición:
```
lead_recontacted = true
AND classification = none
AND whatsapp_clicked = false
AND email_clicked = false
AND workspace_opened = true
```

Versión más robusta:
```
lead_recontacted = true
AND last_operator_action IS NULL
AND workspace_opened = true
```

Valor: diferencia entre "lead sin clasificar" (antiguo) y "operador abrió pero desistió" (nuevo, más predictivo).

## ROADMAP FINAL — DEPLOY SEQUENCE (2026-06-17, decisión Rodrigo)

```
PHASE 1 — IMPLEMENTATION (NOW)
1. CAMPAIGN_MAPPINGS_V1
   STATUS = READY_FOR_IMPLEMENTATION
   Fases A/B/C en paralelo a QA rápida ACTOR_AUDIT_FIX_V1

2. ACTOR_AUDIT_FIX_V1
   STATUS = READY_FOR_DEPLOYMENT
   QA rápida: no bloquea CAMPAIGN_MAPPINGS_V1

PHASE 2 — BUNDLE DEPLOY (cuando ambas SALADEPRUEBAS PASS)
   → Deploy estandar-leads (ACTOR_AUDIT_FIX_V1)
   → Deploy estandar-analytics (CAMPAIGN_MAPPINGS_V1)

PHASE 3 — PRODUCTION OBSERVATION
   24–48h en Acacios
   Búsqueda: leads con trazabilidad completa (actor identified + campaign mapped)

PHASE 4 — OPERATIONAL IMPROVEMENTS
   3. COMPLIANCE_OPERATOR_WORKLIST_V1 Fase 1
      (reduce 48h/96h individuales)
   4. LEAD_RESPONSE_OBSERVABILITY_V1
      (mide comportamiento post-notificación)
```

**Decisión Rodrigo (17-jun)**:
- NO esperar QA completa ACTOR_AUDIT_FIX_V1
- PROCEDER CAMPAIGN_MAPPINGS_V1 YA
- Riesgo ACTOR_AUDIT: bajo (solo agrega contexto, no cambia operación)
- ROI CAMPAIGN_MAPPINGS: inmediato (4 IDs crudos aparecen constantemente en Acacios)
- QA rápida ACTOR_AUDIT previa al bundle de deploy

## CAMPAIGN_MAPPINGS_V1 — SPEC FINAL + FASES (2026-06-17)

**STATUS:** IMPLEMENTED + DEPLOYED, SIN POBLAR (corregido 17-jun por evidencia de código + lead Rayen/Altacruz).

**REALIDAD vs ESTA SPEC (la spec de abajo está DESFASADA — reconciliar, no reconstruir)**: las 3 fases ya existen y están cableadas en `estandar-core-mu` (bootstrap `estandar-core.php:~4548-4575`): Fase A UI `Estandar_Campaign_Labels_Admin` (submenú + save/delete) · Fase B resolver `Estandar_Campaign_Label_Resolver` (en uso real en leads notifier/email/portal/reports + analytics attribution/traffic/results) · Fase C `Estandar_Campaign_Discovery` (tabla propia, hook `estandar_lead_ingested` prio 30, `get_by_type`). Sin stubs.
- Option real = `estandar_campaign_labels_v1` (NO `campaign_mappings_v1` de esta spec).
- 3 niveles: campaigns/adsets/ads (NO 1 mapa). Schema real `label/platform/project/active` (sin `notes`).
- Fallback real type-aware: "Campaña/Conjunto/Anuncio no clasificada (#ID)" (ya iterado: CAMPAIGN_FALLBACK_FIX_V1).
- **Resolver CONFIRMADO vivo en Acacios** (lead Rayen muestra "Campaña no clasificada (#ID)", string que solo emite `fallback_label()`; si no estuviera desplegado vería dígitos crudos). El gap NO es deploy: el diccionario de etiquetas (wp_option) está vacío.
- **Acción pendiente = poblar etiquetas vía Discovery Queue (data entry del operador), NO build/deploy.** Discovery ya colecta los IDs con lead_count por proyecto.

**STATUS original (obsoleto):** READY_FOR_IMPLEMENTATION (Rodrigo GO)

**SSOT:** wp_option['campaign_mappings_v1']

**Schema:**
```json
{
  "120248585033280457": {
    "label": "Altacruz · Instagram · Junio 2026",
    "platform": "meta",
    "active": true,
    "notes": ""
  }
}
```

**Fields:**
- `campaign_id` (key) — ID de plataforma (Meta, Google, etc)
- `label` — nombre legible para operadores
- `platform` — enum: meta|google|tiktok|manual
- `active` — bool (false = archivado, sin borrar)
- `notes` — texto libre (Campaña principal, Retargeting, Lead form, Prueba A/B, etc)

---

**FASES DE IMPLEMENTACIÓN:**

**Fase A — Storage + UI simple**
- wp_option storage (llave `campaign_mappings_v1`)
- UI settings: ID | Plataforma | Etiqueta | Activo | Notas
- Guardar/editar/archivar sin borrar

**Fase B — Resolver único (SSOT)**
```php
function resolve_campaign_label($campaign_id, $platform = '')
  ├─ lookup wp_option['campaign_mappings_v1'][$campaign_id]
  └─ fallback: "Platform #ID (No clasificada)"
```
Consumido por: Workspace, Leads list, Analytics

**Fase C — Discovery Queue**
```
Campañas sin mapping
Meta #120248585650840457   43 leads
Meta #120248585033280457   17 leads
Google #989898989          12 leads
```
Ordenadas por: lead_count DESC
Propósito: reduce mantenimiento manual reactivo

---

**Outputs Visibles:**

**1. Leads list:**
```
Campaña
Altacruz · Instagram · Junio 2026
Meta Ads #120248585033280457
```

**2. Analytics — tabla nueva "Campañas detectadas":**
```
Altacruz · Instagram · Junio 2026    43 leads
Parronales · Facebook · Junio 2026   27 leads
```

**3. Discovery Queue — "Campañas sin mapping":**
```
Meta #120248585650840457   43 leads
Meta #120248585033280457   17 leads
```

**4. Fallback (ID desconocido):**
```
Meta Ads
#120248585033280457
(No clasificada)
```

---

**Justificación urgencia:**
Acacios leaders reales (últimos 7 días):
```
120248585033280457  (Altacruz)
120248585650840457  (Parronales)
120248585033290457  (crudo)
120248585650820457  (crudo)
```
Aparecen constantemente en Workspace, Leads, Compliance, Analytics sin traducción operacional.

---

## LEAD_RESPONSE_OBSERVABILITY_V1 — SCOPE MÍNIMO (2026-06-17)

**Status redefinido**: SPECIFICATION_REQUIRED (fue APPROVED/IMPLEMENTATION_READY, ahora precisa scope claro).

**Contratos duros** (sin cambios):
- NO genera eventos
- NO modifica comportamiento
- NO toca scoring, clasificación, compliance

**Nuevos eventos capturados** (ya disponibles en es_lead_events):
```
workspace_opened          (timestamp)
workspace_time_spent      (seconds, derivado de eventos)
whatsapp_clicked          (timestamp)
email_clicked             (timestamp)
phone_clicked             (timestamp)
classify_started          (timestamp)
classify_completed        (timestamp)
```

**Interfaz nueva — Bloque "Respuesta Operador" en ficha lead**:
```
Portal abierto:        17/06 01:10
Última interacción:    17/06 01:12
Acción realizada:      WhatsApp [enlace]
Clasificación:         Pendiente
```

O, si sin interacción posterior:
```
Portal abierto:        17/06 01:10
Sin interacción posterior
```

**Dashboard — Bloque nuevo "Leads sin acción"**:
```
Portal abierto pero sin gestión      12 leads
Recontactados sin acción             4 leads
Sin abrir workspace                  9 leads
```

**Scope = lectura pura**: no agregar recordatorios, compliance nuevo ni IA. Solo eventos existentes + cálculo derivado (time_spent) + vista.

**Dependencia**: LEAD_RESPONSE_ACCELERATION_V1 PRODUCTION_VALIDATED ✓. El motor de autoresponse ya existe; esta iniciativa es la capa visual encima.

**Criterio de éxito**: Valentina abre Lead #87 en Workspace y ve "Portal abierto: 01:10 / Sin interacción posterior" sin necesidad de abrir otra herramienta o revisar emails.

## ACTOR_AUDIT_FIX_V1 — STATUS = SPEC_READY / NEXT_BUILD (2026-06-17)

**Hallazgo**: Eventos de autoresponse (email_open_signal, link_click, engaged) no registran actor, IP, user_agent → imposible auditar si fueron disparados por prospecto, operador, admin o bot.

**Gap arquitectónico**: Portal (lead_list_opened) SÍ registra `wp_user_id, ip_address, user_agent, device` → existe capacidad, no existe en autoresponse.

**Modelo de actor canónico**:
```
actor_type enum:
  - lead        (prospecto que abrió enlace)
  - admin       (usuario WP autenticado)
  - system      (auto-scanner: Apple Mail Privacy, Gmail proxy, Outlook, etc.)
  - unknown     (indeterminable, fallback)
```

**Payload canónico para eventos de autoresponse**:
```json
{
  "actor_type": "lead|admin|unknown",
  "ip_address": "192.168.x.x",
  "user_agent": "Mozilla/5.0...",
  "referer": "...",
  "session_id": "..."
}
```

**Implementación por evento**:

**1. record_open_signal()** — `autoresponse_email_open_signal`
```php
actor_type = 'unknown'  // No asumir lead (puede ser Gmail proxy, Apple Mail Privacy, Outlook scanner)
ip_address = $_SERVER['REMOTE_ADDR']
user_agent = $_SERVER['HTTP_USER_AGENT']
referer = $_SERVER['HTTP_REFERER'] ?? ''
```

**2. record_link_click()** — `autoresponse_link_click`
```php
actor_type = 'lead'     // Click en enlace firmado
ip_address = $_SERVER['REMOTE_ADDR']
user_agent = $_SERVER['HTTP_USER_AGENT']
referer = $_SERVER['HTTP_REFERER'] ?? ''
session_id = (session_id() ?: wp_get_session_token())
```

**3. record_engagement_if_new()** — `autoresponse_engaged`
```php
Hereda actor_type, ip_address, user_agent, session_id
desde el evento que disparó el engagement (pdf/landing/whatsapp click)
```

**Bonus: Detector admin automático**
```php
if (is_user_logged_in()) {
    actor_type = 'admin'
    actor_user_id = get_current_user_id()
}
```
Resuelve inmediatamente casos como #87 (Rodrigo auditando = evento disparado por admin, no lead).

**Caso #87 — Resultado esperado post-fix**:
```
01:10 autoresponse_email_open_signal
      actor_type = unknown
      ip_address = 192.168.x.x (IP Rodrigo)
      user_agent = Mozilla Mac

01:12 autoresponse_link_click
      actor_type = admin
      ip_address = 192.168.x.x (IP Rodrigo)
      user_agent = Mozilla Mac
      wp_user_id = Rodrigo

01:16 lead_list_opened
      actor_type = admin
      wp_user_id = Rodrigo
      ip_address = 192.168.x.x

Conclusión: todos los eventos fueron disparados por admin (Rodrigo), no por María Elena.
```

**Criterios de aceptación**:
✓ record_open_signal() captura actor_type, IP, user_agent
✓ record_link_click() captura actor_type, IP, user_agent, session_id
✓ record_engaged() hereda contexto
✓ Admin detector automático activo
✓ Schema: metadata JSON (sin columnas nuevas)
✓ Backward compatible (actor_type = 'unknown' por defecto)

**Archivos modificados**:
- `class-estandar-leads-autoresponse.php` — record_open_signal(), record_link_click(), record_engagement_if_new()
- `class-estandar-leads-link-redirect.php` — pasar contexto HTTP a autoresponse

**Bloquea**: LEAD_RESPONSE_OBSERVABILITY_V1, engagement scoring, hot lead scoring

**Desbloqueado por P1**: ACTOR_AUDIT_FIX_V1 (feedback de #87 mostró gap)

VALUE = HIGH (cierra modelo arquitectónico de actor)
RISK = LOW (metadata JSON, sin tablas, sin schema changes)
STATUS = APPROVED_FOR_DEPLOYMENT (17-jun, Rodrigo)

**IMPLEMENTED_SALADEPRUEBAS (17-jun)**:
- ✓ record_open_signal() → actor_type='system'|'unknown' (heurística UA), ip_address, user_agent, referer
  - Detecta: Apple Mail Privacy, Gmail proxy, Outlook scanner, Thunderbird, etc.
- ✓ record_link_click() → actor_type='lead'|'admin', ip_address, user_agent, referer, session_id
  - Si admin: actor_user_id, actor_email (para auditoría)
- ✓ record_engagement_if_new() → hereda metadata del evento que disparó engagement
- ✓ Admin detector automático (is_user_logged_in() → actor_type='admin' + user data)
- ✓ Archivos: class-estandar-leads-autoresponse.php, class-estandar-leads-link-redirect.php

**QA gate antes de deploy**:
- [ ] Caso sintético: cargar form, abrir link en incógnito → actor_type='lead'
- [ ] Caso sintético: admin abierto, abrir link → actor_type='admin', actor_user_id, actor_email presentes
- [ ] Verificar que metadata serializa correctamente en es_lead_events.metadata
- [ ] Caso: email_open_signal con AppleMail UA → actor_type='system'
- [ ] Caso: email_open_signal con UA desconocido → actor_type='unknown'

**Siguiente movimiento**: 
1. CAMPAIGN_MAPPINGS_V1 (paralelo a QA) → implementación inmediata
2. Cuando ambas SALADEPRUEBAS PASS → DEPLOY bundle
3. Post-deploy: buscar primer lead con trazabilidad completa (lead_submitted → ... → lead_classified con todos los actors identificados)

## ACTOR_AUDIT_FIX_V2 — STATUS = IMPLEMENTED_SALADEPRUEBAS (2026-06-17)

Cierra la zona ciega detectada en #182 (Freddy): `assigned_operator_id` cambiaba sin evidencia histórica. Ahora todo cambio emite evento `operator_reassigned`. Additivo, sin schema, sin migración, backward-safe (APPROVED, RISK=LOW).

**2 archivos** (estandar-leads):
- `class-estandar-leads-admin.php` — `ajax_assign_operator()` lee operador previo y emite `operator_reassigned{from,to,source:manual}` solo si cambió (cubre también "quitar asignación"). `recover_orphan_operators()` emite `operator_reassigned{from,to,source:orphan_recovery}` por fila recuperada (`$affected>0`).
- `templates/admin/operator-portal.php` — label "Operador reasignado" + rama de detalle (De X → Y · fuente · por actor_email).

`actor_user_id/actor_email/actor_type=admin/ip/user_agent` los inyecta `insert_event()→with_actor_context()` automáticamente (sesión admin) — no se setean a mano. Campo clave = `source` (manual vs orphan_recovery) → distingue error humano de reparación automática sin SQL.

**VERSION_BUMP**: estandar-leads **10.5.7 → 10.5.8** (header + `ESTANDAR_LEADS_VERSION`). Decisión Rodrigo: este fix tiene su propio número (10.5.7 era versión previa; no re-deployar).

**GATE pendiente**: `php -l` local de admin.php + operator-portal.php + estandar-leads.php (no corrió en sandbox, sin PHP). Revisión manual de sintaxis OK.

**Límite del fix (no confundir)**: traza hacia adelante, NO retroactivo (punto 7 spec = read-only migration). #182 NO recibe evento retroactivo — su cambio histórico Valentina→Marta sigue sin registrar; eso lo responde solo el SQL de `routing_mode` (ya disponible en metadata de `operator_notified`, ver abajo). Este fix evita el próximo #182, no explica el actual.

## ROUTING_AUDIT_V1 — STATUS = APPROVED / P3 DIFERIDO (2026-06-17)

**Corrección de precisión**: `routing_mode` NO es zona ciega total. `operator_assigned`/`operator_notified` YA serializan `routing_mode` (`project`|`fallback`) + `project_slug`; `routing_fallback_used` ya guarda `operators_count`. Para #182 el SQL corre sobre datos existentes hoy. ROUTING_AUDIT_V1 = **enrichment de evento existente**, NO subsistema nuevo: agrega granularidad (`project_match`|`allowed_project_match`, `matched_operator_ids`, `fallback_reason`, `active_operator_count`). Bajo costo (los valores ya se computan en `operators_for_project()` al insert). Sin urgencia.

**Secuencia post-ACTOR_AUDIT (ratificada Rodrigo 17-jun)**: P0 Deploy 10.5.8 ✓ DESPLEGADO 17-jun · P1 ejecutar recuperación de huérfanos (= primera validación en prod del nuevo evento; revisar `orphan_count` vs `recoverable_count` residual — recovery es parcial: solo operadores BORRADOS + match único de proyecto) · P2 verificar AltoJaramillo→Marta en leads nuevos (mirar `routing_mode='project'` en `operator_notified`) · P3 ROUTING_AUDIT_V1. No abrir más frentes.

## ESTANDAR_FORMS_3.1 — STATUS = APPROVED / NEXT_BUILD (2026-06-17, decisión Rodrigo)

**WIP=1 mantenido**: NO consume WIP ahora. Entra como NEXT_BUILD **después de**: (1) Leads 10.5.8 deploy, (2) CAMPAIGN_MAPPINGS_V1 validado. Fixture de validación: Acacios Postventa. Decisión rectora declarada por Rodrigo: "platform feature, not client-specific migration".

**Scope canon (7 features)**: F1 Conditional Engine V1 · F2 Dependent Select V1 · F3 Repeater V1 · F4 Upload Engine V2 · F5 Webhook Engine V2 · F6 Dispatch Queue · F7 Integration Logs.
**OUT OF SCOPE 3.1**: multi-page, calculations, payments, advanced logic builder, nested repeaters, drag-drop builder redesign.

**REFRAME CRÍTICO (hallazgo de código 17-jun — NO empezar el build sin esto)**: estandar-forms 10.5.5 NO es greenfield. El **Incident Engine (since 4.0.0)** ya implementa `Request → Incident[] → Attachment[]` (tablas `estandar_requests`/`estandar_incidents`/`estandar_request_attachments`) = exactamente la arquitectura postventa (Proyecto → problemas repetibles → fotos). Delta real vs scope:
```
F2 Dependent Select  = YA BUILT (class-dependent-select.php: project_select→dependent_select,
                       calles vía Project Registry AJAX ef_get_streets). VALIDAR, no construir.
                       ⚠ El JSON hard-coded {ALTACRUZ:[...]} del spec es REGRESIÓN vs dinámico actual.
F3 Repeater          = YA BUILT (class-repeater.php + Incident Engine). Exponer min/max (default 1/4).
F4 Upload            = SUBSTRATE BUILT (estandar_request_attachments + helpers-files.php). Validar mime/size/multiple.
F1 Conditional       = NET-NEW (no existe motor de visibilidad; los str_contains son anti-spam). Acotado: JS + schema.
F5 Webhook V2        = dispatch existe; SIN secret/retry/signature/per-form. Net-new = superficie de config.
F7 Integration Logs  = SUBSTRATE (tabla estandar_form_logs existe). UI net-new.
F6 Dispatch Queue    = NET-NEW, KEYSTONE DE RIESGO.
```
**Conclusión**: 3.1 ≈ F1 + F5-config + F7 sobre base ya shipeada en 4.0.0. Único subsistema nuevo y caro = F6.

**3 riesgos a resolver en SPEC antes de codear**:
1. **F6 = cola durable con retry dentro de WP** → wp-cron no-determinista (reintentos que no disparan o duplican); proyecto de ingeniería + deuda permanente. Acacios shipea HOY síncrono (email + n8n). Recomendación: **diferir F6** hasta evidencia de fallos de entrega reales; F7 puede leer `estandar_form_logs` sin F6.
2. **F5 colisiona con `Estandar_Leads_Webhook_Dispatcher`** (n8n/Sheets, vive en estandar-leads). Dos motores webhook en dos plugins = drift. Decidir ownership antes.
3. **Migración producción** = mayor riesgo de "romper producción". F2/F3 cambian forma del payload; regla canon "name= idénticos al refactorizar". n8n/Sheets dependen de nombres actuales → mapear old→new y validar payload n8n antes del cutover.

**UNKNOWN gating (sin responder)**: ¿Acacios Postventa en producción corre HOY sobre Gravity Forms o ya sobre Incident Engine? Si Gravity → migración real + riesgo de payload. Si Incident Engine → 3.1 = polish + F1 + observabilidad, sin migración de motor. Resolver al abrir el slot.

## CANON_CODE_RECONCILIATION_V1 — STATUS = DONE (2026-06-17)

Detonante: 5/5 "siguientes builds" del canon resultaron features ya construidas → **problema de inventario, no de desarrollo** (Rodrigo). Pasada read-only sobre el código. Detalle completo: `CANON_CODE_RECONCILIATION_V1.md`.

**Estados reales corregidos:**
```
CAMPAIGN_MAPPINGS_V1            = DEPLOYED, sin poblar   → 0 build (operar: Discovery Queue)
LEAD_RESPONSE_OBSERVABILITY_V1 = ~80% PARTIAL           → build chico (bloque ficha + 2 buckets)
COMPLIANCE_OPERATOR_WORKLIST_V1 F1 = mostly EXISTS      → verificar legacy muerto, no es build
ANALYTICS_NORMALIZATION_V1     = MISSING D2 / PARTIAL D1 → ÚNICO build real (corrige 0-ventas Acacios)
```

**INVERSIÓN DE PRIORIDAD (hallazgo rector)**: los 3 items en cola de build están hechos/casi; el item que el canon difería (ANALYTICS_NORMALIZATION, P4) es el único con trabajo real Y con impacto comercial vivo — `class-dashboard.php:1892/1917/1938` define `ventas=status='CONVERTED'` mientras Acacios usa RESERVED/SALE → **Acacios = 0 ventas en el embudo**. Reordenar roadmap.

**SECUENCIA REAL recomendada**: (0) operar CAMPAIGN mappings · (1) OBSERVABILITY delta · (2) verificar COMPLIANCE_WORKLIST F1 · (3) ANALYTICS_NORMALIZATION D2. WIP=1 se mantiene.

**Regla nueva**: antes de asignar slot de build a un item del backlog, verificar EXISTS/PARTIAL/MISSING contra código. El canon va por detrás del código.

## ROADMAP RE-ORDENADO POST-RECONCILIACIÓN (2026-06-17 PM, Rodrigo)

```
P0  Operación        — poblar Campaign labels (Discovery Queue)   0 build, <1h
P1  Observability Delta — bloque ficha + 2 buckets                build chico
P2  Analytics Normalization — D2 (+D1)                            ÚNICO build estructural
P3  OCI V1           — ACTIVACIÓN CONDICIONAL (ver gate abajo)
P4  Forms 3.1        — expansión plataforma (F6 diferir)
P5  Compliance Cleanup — verificación, no build
```

Razón del re-orden: cerrar el ciclo económico Anuncio→Lead→Operador→Resultado→Meta/Google (negocio) antes que Forms 3.1 (plataforma).

**GATE OCI (P3) — RATIFICADO 17-jun PM (Rodrigo + Claude convergen)**: OCI deja de ser "build programado" → **feature condicionada**. Adapter ya existe inactivo; blocker = eventos comerciales reales upstream. Observability (P1) MIDE la brecha Operador→Resultado, no la cierra.

**Criterio del gate (PRE-REGISTRADO, evaluar tras P1+P2 sobre últimos 30d, def. normalizadas de P2):**
- Métrica = classification_rate + conteo de eventos profundos (RESERVED/SALE/MEETING) por proyecto.
- **FAIL** = classification_rate ≈ 0 (patrón #87/Daniela es la norma) → NO encender OCI.
- **PASS** = stream consistente de eventos profundos reales.
- Marco honesto: a escala Acacios (~120 leads/30d) NUNCA se alcanza el volumen de optimización plena de Meta (~50 conv/sem). El gate NO es "¿alcanza para que Meta optimice?" sino "¿el eslabón Operador→Resultado está VIVO o MUERTO?". OCI a bajo volumen = señal direccional, no motor de optimización.

**Refinamiento clave (no son alternativas, son secuenciales)**: como el adapter YA existe, "OCI ON" es casi gratis (cablear eventos + contrato de conversión de P2). Por tanto **el Workflow Operador→Resultado es el build real en AMBAS ramas**: si NO hay eventos el workflow los crea; si hay POCOS los aumenta. OCI = toggle barato encima del workflow, no su alternativa. El trabajo caro está en el workflow (WHATSAPP_ASSISTED_CLASSIFICATION / recordatorios / seguimiento / nudges), no en el export.
- Disparador prototípico del workflow (evidencia Daniela): re-entrada de alta intención detectada por chatbot + cero respuesta del operador = momento exacto del nudge.
- Al encender: empezar por eventos profundos y limpios (RESERVED/SALE), nunca subjetivos (OCI_EXPORT_WHITELIST ya en canon).
- Dependencia dura: Normalization (P2) ANTES de OCI (reconciliar `CONVERSION_STATUSES` con def. única de venta; si no, OCI exporta sobre el bug RESERVED/SALE vs CONVERTED).

**Roadmap final ratificado**: P0 Campaign Labels · P1 Observability Delta · P2 Analytics Normalization · **GATE** (¿eventos comerciales suficientes?) → SÍ: OCI V1 (toggle) / NO: Workflow Operador→Resultado · luego Forms 3.1 · al final Compliance Cleanup.

### COMMERCIAL_SIGNAL_GATE_V1 (nombre formal del gate, ratificado 17-jun)
Evaluar tras P1+P2 sobre últimos 30d (varias semanas), por proyecto, con definiciones normalizadas de P2. Métricas: `classification_rate`, `visit_rate`, `reserve_rate`.
- **PASS** = `classification_rate > 0` Y existen eventos profundos (QUALIFIED/VISIT_SCHEDULED/VISITED/RESERVED/SALE). → OCI ON.
- **FAIL** = 120 leads / 0 clasificados / 0 visitas / 0 reservas (patrón #87/Daniela). → OPERATOR_RESULT_WORKFLOW_V1.
- No es volumen, es existencia del eslabón Operador→Resultado en el mundo real.
- **GUARD DE MEDICIÓN (Claude, crítico)**: classification_rate bajo = inacción real (a) O captura incompleta (b). Fuentes conocidas de (b) en canon: `contacted` hardcoded 0 (gap CONTACT_ATTEMPT_TRACKING) + regla DECLARACIÓN≠EVENTO. ANTES del veredicto: confirmar que el bajo rate refleja (a), no (b) — si no, se construiría el Workflow para arreglar un bug de instrumentación. P1 + ACTOR_AUDIT_FIX_V2 mitigan; el criterio de lectura debe declararlo.

### OPERATOR_RESULT_WORKFLOW_V1 (rama FAIL del gate — STATUS = CONDICIONAL, no programado)
NO es dashboard ni tracking — es **intervención operacional**. Apuesta probabilística actual (Rodrigo+Claude, evidencia #87+Daniela): probable que venga ANTES que OCI. Ejemplos de disparador→acción:
- Lead reincidente + sin gestión → WhatsApp automático al operador.
- Workspace abierto + 48h sin acción → recordatorio contextual.
- 2 ingresos + 0 gestión → escalación a supervisor.
Caso de prueba canónico = Daniela (re-entrada alta intención detectada por chatbot + cero respuesta operador). Respeta lista negativa OPERATIONAL_PIPELINE_V1 (sin CRM/pipeline/tareas) — son nudges sobre el canal de escalación existente, no entidades nuevas. Spec al abrir el slot (post-gate).

**DESCUBRIMIENTO RECTOR DE LA SESIÓN (17-jun)**: el roadmap implícito de hace una semana era Captura→OCI→Meta. Tras reconciliación + casos reales (#87, Daniela) el roadmap correcto es Captura→Observability→Normalization→Workflow Operador→Resultado→OCI. Razón: activar OCI hoy no resuelve el problema fundamental — el sistema **detecta intención pero no garantiza gestión**. El cuello dejó de ser técnico (captura/export) y pasó a ser disciplina operacional de clasificación/seguimiento.

## PROJECT_CALENDAR_V1 — STATUS = IMPLEMENTED_PENDING_FINAL_QA (2026-06-18)

**ENGINE = PASS PARCIAL** (evidencia #109: asignado 17/06 19:58 → observado 18/06 08:29 = 0 min hábiles = CORRECTO). El motor no muestra errores. Único QA pendiente: caso viernes 17:55 → lunes 09:10 para validar acumulación hábil positiva (>0). El "0 min" de #109 NO es bug del motor — es ancla equivocada (ver OBSERVABILITY DEFECT_1).

### Histórico (status inicial 2026-06-17)

Convierte tiempo-calendario en tiempo-hábil de proyecto antes de consolidar métricas de operador. Arquitectura APROBADA por Rodrigo; DEPLOY = NO TODAVÍA; QA OBLIGATORIA.

**Decisiones aprobadas**: (1) NO tocar Project Registry (ADR-003 FROZEN) → storage en `wp_option estandar_project_calendars_v1` + default sistema (America/Santiago, lun-vie, 09:00–18:00). (2) NO reescribir Compliance — solo dejar el util listo (sin cambio de comportamiento). (3) Edad del lead ≠ tiempo disponible para responder (métricas distintas, ambas válidas).

**Archivos**: NUEVO `estandar-core-mu/includes/class-project-calendar.php` (`Estandar_Project_Calendar`) + `tests/run-project-calendar.php` (8 casos). core-mu 10.5.5→**10.5.6**. estandar-leads: portal.php (2 buckets + 3 helpers + 2 views) + operator-portal.php (bloque "Respuesta Operador") → 10.5.8→**10.5.9**. Additivo, sin schema, sin migración, CORE_OPTIONALITY guard.

**Validación**: algoritmo portado a Python = 8/8 PASS, incl. acceptance (Viernes 17:55 → Lunes 09:05 = **10 min hábiles**, no 63h; UTC y America/Santiago). **GATE pendiente en máquina de Rodrigo**: `php -l` de los 4 archivos + `php tests/run-project-calendar.php` (esperar 8 passed) — no hay PHP en sandbox.

**ENTIDAD CANÓNICA DE TIEMPO (regla nueva, ratificada Rodrigo 17-jun)**: `business_elapsed_minutes` / `business_elapsed_hours` son la entidad canónica. **PROHIBIDO** `hours_since_first_contact` en cualquier feature NUEVA. OBSERVABILITY · COMPLIANCE · WORKFLOW · GATE deben consumir `business_elapsed_*` por defecto, no tiempo calendario. (El resolver `business_elapsed()` expone ambos; aliases legacy `business_minutes/hours` solo por contrato del EXECUTION SPEC original.)

**DECISIÓN ABIERTA (no congelar sin QA visual en Acacios)**: "Horas desde primer contacto" quedó en tiempo calendario (es edad de lead, no SLA de respuesta). Antes de congelarlo, QA visual: confirmar que ningún dashboard lo interpreta como SLA de operador. Si lo hace → convertir a business_elapsed.

## OBSERVABILITY_DELTA — STATUS = IMPLEMENTED_PENDING_SEMANTIC_FIX (2026-06-18)

Downgrade desde PENDING_SEMANTIC_QA tras evidencia real (Nicolás #109, Freddy #182 + aperturas admin de Rodrigo). El bloque "Respuesta Operador" y los buckets NO deben usarse para decisiones operativas hasta corregir 3 defectos SEMÁNTICOS (ninguno en el calendario):

**DISTINCIÓN CRÍTICA (#109) — el calendario funciona, la observabilidad no.** "Tiempo hábil desde asignación: 0 min" con asignado 17/06 19:58 → ahora 18/06 08:29 es **MATEMÁTICAMENTE CORRECTO** (asignado tras cierre 18:00, mirado antes de apertura 09:00 = 0 min hábiles; sin fin de semana). #109 es evidencia POSITIVA de PROJECT_CALENDAR_V1. NO tocar business_elapsed. El bug es el ANCLA, no el cálculo.

**3 defectos semánticos (todos en el bloque/buckets, no en el calendario):**
1. **Ancla mutable**: el bloque usa `$lead->assigned_at` (reescrito a `now` por `ajax_assign_operator` en cada reasignación) en vez del PRIMER evento `operator_assigned` (write-once). → mide business_elapsed desde la reasignación reciente, no desde la asignación comercial original. Evidencia #109: clasificado/propuesta 12/06, pero assigned_at=17/06 19:58 (reasignación de auditoría).
2. **Contaminación admin** (= OBSERVABILITY_ACTOR_SEMANTICS): workspace_opened / interacción cuentan aperturas wp-admin de Rodrigo como actividad de operador.
3. **Mezcla estado-actual + historia**: "asignado 17/06" junto a "clasificado 12/06" en una narrativa = incoherente.

**Fixes SEMANTIC_FIX (no build ahora):** (1) anclar en primer `operator_assigned`, no en `$lead->assigned_at`; (2) excluir `actor_type='admin'`; (3) separar "respuesta inicial operador" de "estado comercial actual".

**QA real (corregido):** QA#1 business_elapsed = evidencia positiva parcial (#109 ok; falta viernes→lunes >0). QA#2 bucket workspace-sin-gestión = FAIL semántico. QA#3 bucket asignado-sin-abrir = AÚN NO PROBADO (Nicolás tiene aperturas, no entra al bucket; la inconsistencia de fecha es del bloque QA#4, no de este bucket). QA#4 bloque = FAIL (los 3 defectos viven aquí).

---

### Histórico previo (status anterior IMPLEMENTED_PENDING_SEMANTIC_QA, 2026-06-17)

Implementado pero NO validado en instalación real. Falta QA de: buckets, bloque ficha, cálculos. **No abrir otro build hasta cerrar este QA.** No rompe datos/routing/compliance/asignación — pero la semántica de actor (abajo) puede inflar métricas de actividad si Rodrigo audita mucho desde wp-admin.

### OBSERVABILITY_ACTOR_SEMANTICS (hallazgo 17-jun — cambia la lectura de los QA)
Cómo se estampa el `operator_id` (y por ende el nombre vía JOIN `o.name`) en eventos de vista:
```
lead_viewed (wp-admin, admin.php:1418-1420)  = assigned_operator_id  ≠ identidad del visor
lead_viewed (workspace, operator-workspace.php:177-180) = operator.id = identidad del visor
```
- **wp-admin**: "Lead visto · Valentina" significa "ALGUIEN abrió + el lead estaba asignado a Valentina", NO que Valentina abrió. El flip Valentina→Marta de #182 es compatible con: Rodrigo abre → reasigna → abre de nuevo. NO es evidencia de actividad de Valentina/Marta.
- **Verdad del visor**: en metadata — `wp_user_id` (log_portal_event) + `actor_type`/`actor_email` (with_actor_context). `actor_type='admin'` (login WP, ej. Rodrigo) vs `actor_type='operator'` (workspace por token). SQL #182: `SELECT created_at, operator_id, metadata FROM wp_es_lead_events WHERE lead_id=182 AND event_type IN ('lead_viewed','lead_list_opened') ORDER BY created_at` → mirar `actor_type`/`wp_user_id`.

**RIESGO**: `workspace_opened_without_management`, `Lead visto`, `Última interacción observable`, `workspace_open_rate`, `operator_engagement` pueden contar aperturas ADMIN (auditoría/soporte/revisión técnica de Rodrigo) como actividad de operador.

**QA #2 corregido**: PASS → **PASS PARCIAL** (el bucket funciona técnicamente, pero no sabemos si la señal = actividad operador o actividad operador + admin).

**REGLA OPERATIVA**: NO usar "Lead visto" como proxy de actividad comercial real hasta separar `actor_type='operator'` vs `'admin'` en los indicadores operativos. También alimenta el guard de medición de COMMERCIAL_SIGNAL_GATE_V1 (no confundir engagement de operador con auditoría de admin).

**PENDING (no es build ahora; barato cuando le toque)**: agregar `actor_type='admin'` exclusion a las 2 bucket queries + bloque ficha (filtro additivo sobre `es_lead_events.metadata`). Resolver tras cerrar PROJECT_CALENDAR_V1 QA y ANTES de declarar OBSERVABILITY_DELTA = DONE.

**Checklist QA (Acacios) — 4 puntos; si pasan → PROJECT_CALENDAR_V1 = DONE y OBSERVABILITY_DELTA = DONE**:
1. Lead viernes 17:55, proyecto calendario normal → `business_elapsed` correcto (≈10 min hábiles el lunes 09:05, no 63h).
2. Bucket "Workspace abierto sin gestión" aparece correctamente.
3. Bucket "Asignado sin abrir workspace" aparece correctamente.
4. Bloque "Respuesta Operador" muestra eventos reales.

**Recién entonces** avanzar a ANALYTICS_NORMALIZATION_V1 (único bug comercial confirmado que afecta decisiones hoy: ventas=CONVERTED vs RESERVED/SALE en Acacios).

## LEADS_TABLE_UX_V1 + LEADS_EXPORT_CENTER_V2 — STATUS = READY_FOR_RUNTIME_QA (2026-06-20)

**APPROVED para merge (Rodrigo, 20-jun).** Cambios en SALADEPRUEBAS:

**LEADS_TABLE_UX_V1** (estandar-leads):
- Lead ID visible en tabla (#224, #225, …)
- Búsqueda exacta por `224` y `#224` (`WHERE id = %d`)
- Nombre completo = `full_lead_name()` (nombre + apellido de `form_fields_json`) — chatbot: nombre solo, sin error
- Proyecto visible bajo el nombre como texto secundario
- Columna Origen con clases CSS canónicas `es-origin-badge es-origin-{chatbot|form|manual|api|unknown}`

**LEADS_EXPORT_CENTER_V1** (estandar-leads):
- Nueva sección: Leads → Exportaciones
- Filtros: Período / Origen / Proyecto / Operador / Estado / UTM
- Export = submissions (no leads). 1 fila = 1 submission. Lead #87 = 2 filas
- 26 columnas CSV: `lead_id · submission_id · lead_uuid · created_at · submitted_at · nombre · apellido · nombre_completo · email · telefono · proyecto · operador · source_plugin · source_form_id · utm_* · fbclid · gclid · landing_url · referrer · session_id · estado_comercial · calidad`
- UTF-8 BOM para Excel
- Vista previa 10 filas + conteo + banner

**DESTINATIONS_ENGINE_PLACEHOLDER_V1** (estandar-destinations, plugin nuevo):
- Placeholder arquitectónico. Sin funcionalidad operativa
- Reserva el espacio de exportaciones e integraciones externas
- Visible en nav principal de Core (después de Chatbot)
- Regla canónica: prohibido crear integraciones directas Google/Microsoft/CRM dentro de Forms, Chatbot, Leads, Analytics

**CSS canónico** (estandar-core-mu/admin.css):
- `.es-origin-badge` + `.es-origin-{chatbot|form|manual|api|unknown}` definidos en Core
- Consumibles por cualquier template del ecosistema sin duplicar colores

**Evidencia que motivó el Export Center**:
```
Forms  → Google Sheets ✓  (source_plugin='estandar-forms', ~199 submissions)
Chatbot → Google Sheets ✗  (source_plugin='estandar-chatbot', ~51 submissions)
Bloqueador: Estandar_Forms_Webhook::maybe_dispatch():39 — source_plugin !== 'estandar-forms' → return
```

**RESCATE_CHATBOT_SUBMISSIONS_V1 — STATUS = NEXT_OPERATION (no es build)**:
```
1. Deploy SALADEPRUEBAS → producción
2. Leads → Exportaciones / Origen=estandar-chatbot / Período=Todo el historial
3. Verificar conteo (esperado ≈51)
4. Descargar CSV
5. Comparar contra Google Sheets por submission_id/lead_uuid
6. Determinar: ¿cuántos faltan realmente? ¿desde qué fecha?
7. Cargar faltantes manualmente a Sheet
8. Luego, separado: corregir dispatcher definitivo (LEADS_WEBHOOK_OWNER_SWITCH)
```
No son necesariamente 51 perdidos — pueden ser 45, 38, o solo los posteriores a una fecha.

**V2 fix (2026-06-20)**: `exports_parse_filters()` ahora acepta `array $input` — preview pasa `$_GET`, export pasa `$_POST`. SSOT garantizado. Auditoría: `filter_hash` (md5 8 chars) presente en: pantalla (#hash bajo botón) · CSV (líneas `#` de cabecera) · log servidor (ambas líneas con hash idéntico). Nombre de archivo incluye row_count: `leads-export-20260620-231400-51rows.csv`.

**Runtime QA de cierre (1 caso real)**:
```
Origen=estandar-chatbot / Período=Todo
→ Preview: N  (banner "Registros encontrados: N · #hash")
→ CSV: línea "# Exported Rows: N" == Preview N
→ Log: exports_preview filter_hash=X  +  export_csv filter_hash=X row_count=N
→ Preview Count == CSV Rows → CLOSED
```

**EXPORT_FORMAT_HARDENING_V1 — STATUS = FUTURE / PRIORIDAD BAJA**:
Riesgo registrado (Rodrigo, 20-jun): líneas `#` de cabecera + BOM rompen importación automática sin configuración (Google Sheets import directo, Power BI, Looker Studio, pandas `read_csv()`, n8n Read Binary → CSV). Objetivo inmediato (auditoría humana + rescate) no se ve afectado. Fix futuro: generar archivo gemelo `.meta.txt` separado, o agregar `export_filter_hash`/`export_generated_at` como columnas repetidas en cada fila. NO abrir antes de que un consumidor automático real falle.

---

## ESTADO CONSOLIDADO (2026-06-20, actualizado con Lead #224)

**PHASE_1_PRODUCTION_VALIDATION = COMPLETE (2026-06-20)**
Evidencia: Lead #87 José Muñoz + Lead #224 Joselyn. Las cuatro capas críticas validadas en producción real:
```
CAPTURE_PIPELINE_V1   = PASS
ROUTING_V1            = PASS
WORKSPACE_TRACKING_V1 = PASS
SESSION_LINKING_V1    = PASS
```

**PATRON CONFIRMADO: workspace_opened_without_action (N=2)**
Secuencia idéntica en ambos casos: `lead_created → operator_assigned → operator_notified → workspace_opened → (sin acción) (sin clasificación) (sin contacto registrado)`. Esto descarta las hipótesis de no recepción/no acceso — el operador recibió, abrió, entró, y no ejecutó acción observable. El tramo `workspace_opened → first_action` es hoy una caja negra.

**TRANSICIÓN DE DIAGNÓSTICO (20-jun, Rodrigo):** El riesgo principal del ecosistema ya no está en tecnología. Migra hacia adopción / comportamiento / disciplina operacional. Los próximos puntos de mejora entregan más ROI que agregar tracking. LEAD_RESPONSE_OBSERVABILITY_V1 deja de ser mejora UX y pasa a ser infraestructura de medición operacional — la pregunta cambió de "¿llegó el lead?" a "¿qué ocurre entre workspace_opened y first_action?".

**Prioridad post Lead #224 (Rodrigo, 20-jun):** ANALYTICS_NORMALIZATION_V1 → CAMPAIGN_MAPPINGS_V1 → LEAD_RESPONSE_OBSERVABILITY_V1 → LEADS_UI_CHATBOT_SIGNAL_V1 → OCI/Meta Feedback Expansion.

**Bug Analytics "Proyecto no soportado":** project_slug=parronales en submission vs resolver derivado que produce `los-parronales` en la vista. Normalización de slug, no pérdida de datos. Absorber en ANALYTICS_NORMALIZATION_V1, no frente separado.

Dataset canónico chatbot = `submission.name/phone + context_json + scoring_json + chatbot_json` (form_fields_json=[] es correcto para chatbot, no es bug).

```
PROJECT_CALENDAR_V1        = IMPLEMENTED_PENDING_FINAL_QA   (engine PASS PARCIAL; falta caso vie→lun >0)
ACTOR_AUDIT_FIX_V2         = IMPLEMENTED_PENDING_PROD_VALIDATION (desplegado 10.5.8; falta SQL D + corrida orphan)
OBSERVABILITY_DELTA        = IMPLEMENTED_PENDING_SEMANTIC_FIX (3 defectos: ancla mutable · admin · mezcla)
ANALYTICS_NORMALIZATION_V1 = NEXT_HIGH_IMPACT_BUILD
```

QA snapshot: QA1 PASS PARCIAL · QA2 FAIL SEMÁNTICO · QA3 NO PROBADO · QA4 FAIL SEMÁNTICO.

**Prioridad post Lead #224 (Rodrigo, 20-jun):** ANALYTICS_NORMALIZATION_V1 → LEAD_RESPONSE_OBSERVABILITY_V1 → CAMPAIGN_MAPPINGS_V1 → LEADS_UI_CHATBOT_SIGNAL_V1. Razón: OBSERVABILITY ya no es especulativa — hay evidencia de campo (N=2 workspace-sin-gestión). CHATBOT_SIGNAL baja porque mostrar cold/30/nurturing no resuelve el problema de acción; medir el comportamiento primero. OBSERVABILITY no depende de NORMALIZATION (lee `es_lead_events`, ortogonal a ventas/funnel) pero NORMALIZATION tiene mayor impacto sistémico y bloquea COMMERCIAL_SIGNAL_GATE → orden correcto.

**Pasos pendientes (lado Rodrigo, no build):** A) lead nuevo AltoJaramillo → validar `routing_mode='project'` + Marta · B) `php -l` plugins modificados · C) `php tests/run-project-calendar.php` (8/8) · D) SQL #182 (`actor_type` + `routing_mode`). Cierran PROJECT_CALENDAR_V1 + ACTOR_AUDIT_FIX_V2; NO cierran OBSERVABILITY (semantic fix aparte).

**Decisión de orden (post A-D):** ANALYTICS_NORMALIZATION_V1 antes que OBSERVABILITY_SEMANTIC_FIX. Razón: impacto comercial directo (ventas/funnel/ROI/Meta) vs interpretación operativa. **Seguro hacerlo:** diferir el semantic fix NO bloquea COMMERCIAL_SIGNAL_GATE — el gate lee classification_rate/visit_rate/reserve_rate (eventos de clasificación), no los eventos de vista contaminados. Sin acoplamiento oculto.

**Riesgo (Rodrigo):** Routing BAJO · Integridad leads BAJO · Compliance BAJO · Observabilidad MEDIO (contenido por regla "no usar Lead visto como proxy") · Analytics comercial ALTO. El cuello pasó de captura/asignación a calidad de medición/atribución.

**ENGANCHE NORMALIZATION→GATE (anti-drift, 18-jun)**: ANALYTICS_NORMALIZATION_V1 no "desbloquea OCI" — su salida (reservas/ventas/visitas reales correctas, Acacios 30d) **ES** el input del COMMERCIAL_SIGNAL_GATE_V1. Normalizar "venta" la hace contable, NO la hace ocurrir. Secuencia correcta: P1 Normalization (D2 primero) → GATE → PASS: OCI_FOUNDATION+adapters / FAIL (patrón #87/#224/Daniela, ~0 reservas): OPERATOR_RESULT_WORKFLOW. **NO pre-construir OCI_FOUNDATION antes del veredicto del gate**; el adapter ya existe inactivo, encenderlo es barato; el trabajo caro está en Operador→Resultado si el gate da FAIL. Verificar al abrir OCI: canon dice `Estandar_Leads_Ads_OCI` ya tiene cola idempotente (`es_oci_queue`) — la nota "retry=no/idempotencia=no" puede ser stale o referirse al dispatcher n8n; chequear contra código (patrón reconciliación).

---

## OPERATOR_SLA_OPEN_EVIDENCE_V1 — STATUS = IMPLEMENTED_SALADEPRUEBAS / TESTS_GREEN (2026-07-20)

Implementa la separación entre antigüedad del lead (`lead_age`, desde captura) y SLA de respuesta del operador (`operator_response_sla`, desde entrega efectiva de la notificación), más clasificación heurística de evidencia de apertura de correos y autorespuesta diferenciada fuera de horario laboral.

**Arquitectura**: todo el estado deriva de `es_lead_events` — cero columnas nuevas en BD. Sin OCR, sin servicios externos.

**Doc canónico**: `estandar-leads/docs/OPERATOR_SLA_OPEN_EVIDENCE_V1.md` (198 líneas) + tests en `tests/run-operator-sla-open-evidence.php`.

### 1. Lead Age vs Operator SLA

- **Lead Age** — métrica legacy, horas desde `es_leads.created_at`. Sin cambios.
- **Operator SLA** — métrica nueva, horas desde el primer `operator_notified`. Método `operator_response_sla_metrics()` en `class-estandar-leads-ai-context.php:831-926`.
- **Estados**: `pending_delivery`, `awaiting_delivery (deferred: X)`, `active · Xh remaining`, `due_now`, `contact_registered`.

### 2. Autorespuesta fuera de horario

Cuando un lead ingresa fuera del horario laboral del operador:
- Autorespuesta al prospecto se envía **inmediatamente** (sin cambiar timing).
- Línea adicional vía placeholder `{{horario_mensaje}}` en el template.
- Texto default: "Recibimos correctamente tu solicitud. Un ejecutivo se pondrá en contacto contigo lo antes posible, dentro de nuestro horario laboral."
- Filtrable via `estandar_leads_autoresponse_out_of_hours_line`.
- Detección via `Estandar_Leads_Operator_Hours::is_open()`.
- Evento `autoresponse_email_sent` incluye metadata `autoresponse_variant: out_of_hours|in_hours`.

**Archivo**: `class-estandar-leads-autoresponse.php:172-189,210,318-319` — método `maybe_send()`.

### 3. Clasificación de evidencia de apertura (OPEN_EVIDENCE_V1)

Cada apertura de correo genera **dos eventos** en `es_lead_events`:
1. `autoresponse_email_open_signal` — raw, backward compatible, intacto.
2. `autoresponse_open_classified` — clasificación derivada, aditivo.

**4 niveles de clasificación** (en `classify_open_evidence()`, autoresponse.php:472-556):

| Clasificación | Score Eligible | Evidencia |
|---|---|---|
| `technical_fetch` | NO | <5s desde envío |
| `probable_machine_open` | NO | UA scanner conocido o apertura ambigua aislada |
| `probable_human_open` | SÍ | >30s, múltiples aperturas, IPs distintas |
| `confirmed_human_engagement` | SÍ | Apertura + clic posterior, o clic previo |

**Reclasificación (upgrade)**: cuando ocurre un clic después de una apertura ambigua, `upgrade_open_classification()` inserta `confirmed_human_engagement` con `score_eligible=true`.

**Arquitectura de detección** — heurísticas sin OCR ni servicios externos:
1. Tiempo desde envío (`autoresponse_email_sent.created_at` vs now)
2. User-Agent: Apple Mail Privacy, Gmail proxy, Outlook, Thunderbird, antivirus, Barracuda, Mimecast, Proofpoint, Symantec, TrendMicro, crawlers, bots (`classify_open_signal_actor()`, línea 608-645)
3. Múltiples aperturas (7d window)
4. IPs distintas entre aperturas
5. Clic posterior (`autoresponse_link_click` o `autoresponse_engaged`)
6. Correlación temporal

### 4. Impacto en Scoring

`class-estandar-leads-scoring.php` — `behavior_weights()`:

| Evento | w | halflife | cap |
|---|---|---|---|
| `autoresponse_email_open_signal` (raw, legacy) | 3 | 3d | 6 |
| `autoresponse_open_classified` (nuevo) | 6 | 3d | 12 |

Filtro `score_eligible` en `behavior_score()` (línea 302-303): `technical_fetch` y `probable_machine_open` con `score_eligible=false` → NO contribuyen al score. Backward compatible: leads sin eventos clasificados se comportan igual que antes.

### 5. Journey Builder

`class-estandar-leads-journey-builder.php` — `event_flags()` y `build()` usan `autoresponse_open_classified` para determinar `email_opened`. Solo `probable_human_open` o `confirmed_human_engagement` activan el paso "Email abierto". `technical_fetch` y `probable_machine_open` NO.

### 6. Notifier — notificación diferida

- Nuevo método `record_delivered_event()`: registra `operator_notification_delivered` con metadata `deferred_event_id`, `deferred_at`, `delay_hours`, `deferral_reason`, `sla_start`.
- Nuevo método `has_human_email_open()`: retorna true si existe `autoresponse_open_classified` con nivel humano.
- `send_deferred_notification()` modificado para invocar `record_delivered_event()` antes de re-enviar (línea 618-622).

### 7. AI Context Export — secciones afectadas

- Commercial Snapshot: `Lead Age` + `Operator SLA`
- Compliance > SLA: `Operator SLA status/started_at/elapsed/remaining`
- Behavior Evidence (v1): `Open evidence classification` con tally por nivel
- Operator SLA Timeline (v1): traza completa (captura → cola → deferral → entrega → SLA → contacto → vencimiento)
- Runtime Metrics: `Operator SLA status` + `Notification queued`

### 8. Pruebas

- `tests/run-operator-sla-open-evidence.php` (NUEVO, 263 líneas) — 7 escenarios: lead OOH, autorespuesta out_of_hours, notificación diferida, SLA humano, technical_fetch, confirmed_human_engagement, idempotencia. **PASS**.
- `tests/run-audit-function.php` (NUEVO, 21K, 5 fases funcionales) — auditoría completa del comportamiento SLA/OpenEvidence. **PASS**.
- `tests/run-production-cases.php` (NUEVO, 21K) — 5 casos con datos reales de producción. **PASS**.
- `tests/run-visual-audit.php` + `audit-config.json` — auditoría visual de 7 pantallas admin con Playwright. 3 runs, **7/7 PASS, 46/46 checks, 0 regresiones**.

**Archivos modificados** (additivos, sin schema, 100% spec coverage): `class-estandar-leads-ai-context.php` · `class-estandar-leads-autoresponse.php` · `class-estandar-leads-scoring.php` · `class-estandar-leads-notifier.php` · `class-estandar-leads-journey-builder.php`.

**Archivo NO tocado**: `class-estandar-leads-email.php` (el correo operativo no recibe vars de SLA, verificado).

**VERSION_BUMP**: estandar-leads **se mantiene en 13.0.101** (no se emitió release build; los cambios están en disco desde 20-jul).

## EXPERIENCE_BLOCKS_V3 — STATUS = IMPLEMENTED_SALADEPRUEBAS (2026-07-20)

Ground-up redesign del sistema de animación Estandar. Arquitectura como **motion system componible** (no bolsa de bloques): efectos se adhieren a bloques nativos de Gutenberg, se componen entre sí (reveal + text + hover + scroll simultáneamente), y cada módulo usa su propio canal (WAAPI, CSS var, clase).

**Doc canónico**: `estandar-experience-blocks/docs/ESTANDAR_FX_V3.md` (225 líneas).

**Arquitectura** (5 clases PHP booteadas en `plugins_loaded`):
- `class-efx-manifest.php` — SSOT: módulo → assets JS/CSS, CSS effects whitelist, CSS-var schema, supported blocks
- `class-efx-assets.php` — registra handles, maneja enqueues editor/canvas
- `class-efx-attributes.php` — server mirror del atributo `estandarFx`
- `class-efx-frontend.php` — sanitize → stamp wrapper (classes/CSS vars/data-efx JSON) → enqueue por página (solo módulos usados)
- `class-efx-blocks.php` — registra 13 bloques estructurales de motion
- `class-efx-presets.php` — presets de usuario via REST `estandar-efx/v1/presets`

**51 efectos implementados en 8 módulos**: Reveal (12), Text (6), Scroll (4), Pointer (4), Hover (10 CSS-only), Ambient (7 CSS-only), Particles (3), Blocks (13).

**13 bloques estructurales**: marquee, before-after, stack-cards, coverflow-gallery, magnetic-carousel, spatial-gallery, cover-transition, scroll-panels, sticky-grid-scroll, horizontal-parallax-gallery, container-split-text, pinned-image-reveal, scroll-synced-carousel.

**QA Harness**: `tools/efx-qa.php` — renderiza cada efecto via `do_blocks()` real. Prueba stamping, selective enqueue, pre-states, entrance restore, replay, parallax, tilt/glare, magnetic, marquee, before/after, stack cards, particles, zero console errors.

**SPEC DRIFT DETECTADO**: la spec `ESTANDAR_FX_V3.md` (línea 109) documenta 5 bloques estructurales; el código implementa 13. Los 8 bloques adicionales no tienen entrada en la spec. Posible causa: implementación posterior a la spec o alcance expandido durante el build.

**Core Optionality**: polyfill dinámico `primer_is_active()` — funciona standalone sin Estandar Primer.

**VERSION**: estandar-experience-blocks **13.0.113** (independiente del release bundle; no reflejado en `manifest.json` que aún muestra 13.0.101).

## PRIMER_ADMIN_SHELL_V1 — STATUS = IMPLEMENTED_SALADEPRUEBAS (2026-07-20)

Reestructuración del runtime compartido (`estandar-primer/estandar-core.php`) con nueva arquitectura de admin shell y registro de módulos.

**Cambios principales**:

- **Canonical rename**: runtime se denomina "PRIMER". Constantes `PRIMER_VERSION`, `PRIMER_FILE`, `PRIMER_DIR`, `PRIMER_URL`, `PRIMER_MENU_SLUG` como alias de las legacy `ESTANDAR_CORE_MU_*`.
- **Admin Shell**: `estandar_core_render_admin_shell()` — layout canónico: brand bar, sticky chrome, breadcrumb, navigation tabs, toolbar, surface, footer slot.
- **Navigation Schema** (línea 1287-1354): SSOT `estandar_core_navigation_schema()` con `top_nav` (Dashboard, Forms, Chatbot, Proyectos, Leads, Destinations, Analytics), `tabs`, y `pages` (30+ páginas con shell/module/visibility).
- **Module Registry** (línea 1815-2046): `estandar_register_module()` / `estandar_register_module_page()` — plugins agrupan páginas admin bajo módulos con hub pages, late-registered en `admin_menu` priority 999.
- **Communications Page** (`admin/class-communications-admin.php`): página `estandar_communications` con 7 paneles de configuración (Identidad, Notificaciones de Leads, Autorespuestas, Compliance, Digest Operadores, Reportes Cliente, Inventario read-only). CARDS_UNIFY_V1 design: health-cards grid con toggle-to-panel. Reusa opciones existentes — cero opciones nuevas.
- **Client Context** (línea 2391-2547): `estandar_core_render_client_context_page()` — registro operacional del cliente en `estandar_client_context_record`.
- **Shared Secrets** (línea 436-493): `estandar_core_shared_secret_options()` — OpenAI, Anthropic, Google AI, Pinecone, ElevenLabs, Runway.
- **Plugin Registry** (línea 2048-2154): inventario de todos los plugins Estandar instalados con versión, fecha de archivo, estado activo.
- **Cloud Architecture** (línea 2183-2361): página de documentación no funcional preservando diseño de Estandar Cloud futuro.
- **Diagnostics Contracts** (línea 1001-1009): `estandar_core_diagnostics_contracts()` — filter-based contract registry para diagnósticos pasivos.

**VERSION**: estandar-primer **13.0.101** (sin bump; coincide con el release sellado).

## FORMS_UI_IMPROVEMENTS_V1 — STATUS = IMPLEMENTED_SALADEPRUEBAS (2026-07-20)

Mejoras en la interfaz de administración de estandar-forms:

- **form-preview.php**: visor de submissions/entries con 3 vistas — listado (últimas 200 entries), detalle de entry (con cross-ref a `es_lead_submissions`), detalle de submission (campos capturados, normalizados, atribución UTM, payload JSON completo). Embedded admin shell support.
- **survey-list.php**: listado de encuestas con acciones (Editar, Resultados, Analytics, Duplicar, Eliminar). Sub-navegación para tabs de resultados/analytics. Embedded admin shell support.

## ESTADO CONSOLIDADO POST-20-JUL (2026-07-21)

### Plugin versions reales vs manifest.json

| Plugin | Código | manifest.json (13.0.101) |
|---|---|---|
| estandar-leads | 13.0.101 | 13.0.101 |
| estandar-primer | 13.0.101 | 13.0.101 |
| estandar-experience-blocks | **13.0.113** | **13.0.101** (desactualizado) |
| estandar-forms | sin declarar en archivos examinados | 13.0.101 |
| resto de plugins | — | 13.0.101 |

### Estado de iniciativas (actualizado)

```
OPERATOR_SLA_OPEN_EVIDENCE_V1     = IMPLEMENTED_SALADEPRUEBAS / TESTS GREEN (20-jul)
EXPERIENCE_BLOCKS_V3               = IMPLEMENTED_SALADEPRUEBAS (20-jul) — spec drift 5→13 bloques
PRIMER_ADMIN_SHELL_V1              = IMPLEMENTED_SALADEPRUEBAS (20-jul)
FORMS_UI_IMPROVEMENTS_V1           = IMPLEMENTED_SALADEPRUEBAS (20-jul)
CUSTOMER_UI_V2 (Meta backfill UI)  = MODIFICADO (20-jul) — cambios additivos
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
PROJECT_CALENDAR_V1                = IMPLEMENTED_PENDING_FINAL_QA (18-jun)
ACTOR_AUDIT_FIX_V2                 = IMPLEMENTED_PENDING_PROD_VALIDATION (17-jun)
OBSERVABILITY_DELTA                = IMPLEMENTED_PENDING_SEMANTIC_FIX (18-jun)
LEADS_TABLE_UX_V1                  = READY_FOR_RUNTIME_QA (20-jun)
LEADS_EXPORT_CENTER_V1             = READY_FOR_RUNTIME_QA (20-jun)
CAMPAIGN_MAPPINGS_V1               = DEPLOYED, SIN POBLAR — resolver activo en primer
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
ANALYTICS_NORMALIZATION_V1         = NEXT_HIGH_IMPACT_BUILD (prioridad, junio→julio)
```

### Roadmap actual (revisado 21-jul)

La prioridad documentada en junio sigue vigente — OPERATOR_SLA se implementó como iniciativa paralela no prevista en el roadmap pero validada con tests completos:

```
P0  — Campaign Labels (poblar diccionario)             0 build
P1  — ANALYTICS_NORMALIZATION_V1 (D2 primero)          NEXT BUILD
P2  — LEAD_RESPONSE_OBSERVABILITY_V1 (bloque ficha + buckets)  ~80% partial
P3  — CAMPAIGN_MAPPINGS_V1 (poblar)                    deployed sin datos
P4  — LEADS_UI_CHATBOT_SIGNAL_V1                       pendiente
P5  — COMMERCIAL_SIGNAL_GATE → OCI / WORKFLOW          condicionado
```

### Decisión de release

**NO crear release 13.0.102 ahora.** Los cambios del 20-jul están en disco pero:
1. No han pasado validación en producción (solo SALADEPRUEBAS local).
2. OPERATOR_SLA altera scoring y journey — requiere observación antes de deploy a Acacios/AK.
3. EFX V3 tiene spec drift (13 bloques vs 5 documentados) que debe resolverse antes de release.
4. ANALYTICS_NORMALIZATION_V1 es el siguiente build planeado; tiene más sentido agruparlo con un release que incluya OPERATOR_SLA para desplegar un bundle coherente testado en conjunto.

**Recomendación**: completar ANALYTICS_NORMALIZATION_V1 (D2), luego release 13.0.102 con (OPERATOR_SLA + ANALYTICS_NORMALIZATION + EFX V3 + PRIMER_SHELL).

---

## ANALYTICS_NORMALIZATION_V1 (D2) — STATUS = IMPLEMENTED_SALADEPRUEBAS (2026-07-21)

Completa la migración de todos los statuses comerciales hardcodeados restantes en `class-dashboard.php` hacia la fuente canónica `Estandar_Analytics_Commercial_Stage_Map`.

### Arquitectura

Read-side only. Cero nuevas tablas, columnas, migraciones o writes. `Estandar_Analytics_Commercial_Stage_Map` ya existía y estaba cargado en `estandar-analytics.php:60` — extendió su cobertura a los 6 puntos que aún tenían listas hardcodeadas.

Protegido por la constante `ESTANDAR_ANALYTICS_NORMALIZATION_V1` (definida en `estandar-analytics.php:27-29`, default `true`). Con el flag desactivado, el comportamiento es idéntico al anterior (hardcode legacy + bug de undefined vars preservados).

### Fases implementadas

| Fase | Descripción | Archivo | Líneas |
|---|---|---|---|
| **F1** | Bugfix `get_behavior_to_result()` — define `$_meet_sql`, `$_resv_sql`, `$_sold_sql` desde Stage Map (antes: undefined → SQL `IN ()` → 0 conversiones) | `class-dashboard.php` | 4158-4163 |
| **F2** | Migrar `identity_diagnostics()` — reemplaza 7 statuses hardcodeados por `qualified`+`meeting`+`reserved`+`sold` del Stage Map | `class-dashboard.php` | 1900-1902 |
| **F3** | Migrar `get_system_health()` — reemplaza `'DEED_SIGNED','DELIVERED'` por `Estandar_Analytics_Commercial_Stage_Map::sql_list('sold')` | `class-dashboard.php` | 3693-3695 |
| **F4** | Migrar `ai_bucket_columns_sql()` — reemplaza `'PROPOSAL_SENT'` y `'NEGOTIATION','BANK_EVALUATION'` por `proposal_sent`/`proposal_accepted` del Stage Map | `class-dashboard.php` | 287-299 |
| **F5** | Migrar `lead_terminal_statuses()` fallback — construye dinámicamente desde `Estandar_Analytics_Commercial_Stage_Map::map()` | `class-dashboard.php` | 3455-3466 |
| **F6** | Definir constante `ESTANDAR_ANALYTICS_NORMALIZATION_V1` con default `false` | `estandar-analytics.php` | 27-29 |
| **F7** | Script read-only de reconciliación — compara legacy vs canonical counts | `tools/reconcile-normalization-v1.php` | nuevo |

### Extensiones al Stage Map

Se agregaron 2 entradas a `defaults()` en `class-estandar-analytics-commercial-stage-map.php`:
- `proposal_sent` → `['PROPOSAL_SENT']`
- `proposal_accepted` → `['NEGOTIATION', 'BANK_EVALUATION']`

### Pruebas

| Prueba | Resultado |
|---|---|
| `php -l` en 4 archivos | PASS |
| `run-taxonomy-coverage.php` (9 assertions) | 9/9 PASS |
| `run-conversion-contract.php` | PASS |
| `run-attribution-bridge.php` (5 assertions) | 5/5 PASS |
| `run-whatsapp-health.php` (6 assertions) | 6/6 PASS |
| `run-invariants.php` (leads, 10 assertions) | 10/10 PASS |
| `reconcile-normalization-v1.php` (144 leads, 8 stages) | 0 deltas, todos explicables |

### Verificación de no alteración de datos

- `grep` de writes (`update_option`, `delete_option`, `add_option`, `INSERT INTO`, `UPDATE`, `CREATE TABLE`) en los 3 archivos modificados: **0 ocurrencias** (excepto `set_transient` preexistente)
- SHA256 checkpoint preservado en `.checkpoint-ANALYTICS_NORMALIZATION/`
- Rollback probado: restauración desde checkpoint → `php -l` PASS en los 3 archivos

### Compatibilidad hacia atrás

- `ESTANDAR_ANALYTICS_NORMALIZATION_V1` desactivado (`define(..., false)`) → comportamiento idéntico al pre-cambio: hardcodes legacy preservados, bug de undefined variables en `get_behavior_to_result()` preservado
- `ESTANDAR_ANALYTICS_NORMALIZATION_V1` activado (`define(... true)`) → todas las queries usan Stage Map, bug corregido
- Todos los statuses del Stage Map default son un superconjunto de los legacy → ningún lead pierde su bucket
