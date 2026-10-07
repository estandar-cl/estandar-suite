# META_INTEGRATION_FORMALIZATION_V1 — estandar-leads 13.0.47 (2026-07-09)

> **STATUS (2026-07-15) — PLATFORM ELIGIBILITY RECONCILED, NO VERSION BUMP.**
> Meta enqueue, worker send and direct/backfill dispatch now require the shared
> commercial gate plus canonical channel `meta_ads` and usable `fbclid`/`fbc`.
> `_fbp` alone is explicitly insufficient. The decision is owned by
> `Estandar_Leads_Attribution::platform_eligibility()` over the existing sole
> `resolve_channel()` resolver. Queue, retry, event_id, payload, hash and dedup
> contracts are unchanged; ineligible historical rows remain observable but
> cannot be rendered as successful eligibility. This status supersedes the V5.1
> historical no-lead send contract: because that mode intentionally has no
> advertising attribution, it is now classified `meta_platform_ineligible`
> before enqueue. Reconciled backfill remains compatible for canonical paid Meta
> leads carrying `fbclid`/`fbc`.

> **STATUS (2026-07-14) — BACKFILL SUBMIT MODE REGRESSION FIXED.** Root cause: Simular y Enviar
> usan el mismo formulario y el mismo handler (`estandar_customers_meta_backfill`), pero después
> de Simular el redirect renderizaba nuevamente el selector con `reconciled`, fecha global vacía,
> filtros vacíos y límite 200. Por eso el segundo submit omitía la selección anterior y el handler,
> correctamente fail-closed, caía al modo reconciliado por defecto al no recibir
> `bf_backfill_mode=historical`.
>
> **Fix exacto**: el handler adjunta al reporte transitorio los parámetros ya sanitizados del
> request (`bf_backfill_mode`, `bf_global_date`, `bf_project`, `bf_stage`, `bf_limit`) y el template
> vuelve a renderizarlos como valores seleccionados. No cambia elegibilidad, clasificadores,
> transporte Meta, event_id, cola, payload ni audit.
>
> **Archivos**: `includes/class-estandar-leads-customers-admin.php` ·
> `templates/admin/customers/list.php` · `docs/META_INTEGRATION_FORMALIZATION_V1.md`.
>
> **Validación**: Simular/Enviar preservan `historical` y su fecha global; Simular/Enviar preservan
> `reconciled`; la simulación histórica mantiene 20 elegibles y el envío histórico reporta
> `Modo: Histórico sin lead`; suites de backfill existentes en verde.

> **ADDENDUM V5.1 (2026-07-14) — HISTORICAL_CUSTOMER_BACKFILL (leads 13.0.77). Segundo modo del
> panel de backfill: clientes SIN `matched_lead_id`.** Complementa (no reemplaza) el modo
> reconciliado de V5. Admin-triggered, histórico, one-shot — mismo audit, mismo transporte.
>
> ```
> es_customers (matched_lead_id IS NULL)
>   → Customer_Backfill::plan(filters, limit, mode='historical')   elegibilidad (read-only)
>   → ::run(dry_run=false, mode='historical')
>       → Conversion_Backfill_Service::enqueue()   event_id del caller (UNIQUE en audit)
>       → Conversion_Backfill_Service::dispatch()  hidrata es_customers → Meta_Adapter
>           → Payload_Builder::build($customer_shape,'CONVERTED') → 'Purchase' → Graph_Client
> ```
>
> **UI**: el panel de Clientes gana selector de modo (`Reconciliados con lead` | `Histórico sin
> lead`); los botones Simular / Enviar a Meta y los filtros proyecto/etapa/límite son los mismos.
> La simulación muestra solo elegibles / ya enviados / omitidos / motivos, y reporta el modo.
> Además (V5.1 ajuste 1): campo opcional **fecha de conversión global** (`bf_global_date`), visible
> SOLO en modo histórico. Request-scoped: se usa únicamente en esa simulación/envío, NO se persiste
> como setting. Validación estricta en `sanitize_fallback_date()`: acepta exactamente `Y-m-d` o
> `Y-m-d H:i:s` (round-trip, 2026-02-30 falla), rechaza fechas futuras, normaliza a `Y-m-d H:i:s`
> UTC (medianoche para fecha sin hora); inválida = ausente.
>
> **Resolución de fecha de conversión (orden vinculante)**: (1) `completed_at` del cliente cuando
> existe → SIEMPRE gana; (2) si no, la fecha global del request; (3) si ninguna existe →
> `completed_at_missing` (envío BLOQUEADO). La fecha resuelta es el `event_time` del Purchase.
>
> **Elegibilidad histórica** (primer disqualificador gana): `already_matched` (cliente reconciliado
> → pertenece al modo reconciliado; evita doble Purchase cross-modo) · `identity_missing` (exige
> email válido O teléfono con dígitos — la misma condición bajo la cual el builder emite em/ph) ·
> `project_slug_missing` · `completed_at_missing` (sin fecha propia NI global no hay event_time
> veraz → el envío queda BLOQUEADO) · `already_dispatched` → duplicate.
>
> **Identidad = del propio registro de cliente** (first/last name, email, phone), normalizada con
> `Estandar_Leads_Normalizer` (email lowercase; teléfono `phone_storage` con prefijo 56) y hasheada
> SHA-256 por el Payload Builder existente. **NADA se inventa**: sin fbp/fbc/fbclid, sin UTM, sin
> IP/UA, sin landing URL, sin lead UUID (sin `external_id`) → `action_source=system_generated`.
> amount/currency solo cuando `amount` existe. `buyer_json` sigue SIN PII: la hidratación de
> identidad ocurre en dispatch desde `es_customers` (mismo contrato que la hidratación es_leads).
>
> **Idempotencia (contrato final, V5.1 ajuste 2)**: `event_id = historical-customer:{customer_id}:Purchase`
> (UNIQUE en `es_conversion_backfill_audit`) — **independiente de la fecha por diseño**: un Purchase
> por cliente, para siempre. Cambiar `completed_at` o la fecha global entre corridas NUNCA
> re-habilita a un cliente ya enviado; re-run reporta `duplicate`. (Supersede el formato inicial
> `historical-customer:{customer_id}:{completed_at}:Purchase`, que nunca llegó a producción.)
>
> **Cambio aditivo en el service**: `normalize_buyer()` acepta `customer_id` + `event_id` en la
> raíz del record (opt-in); `dispatch()` gana rama `elseif customer_id > 0` → `hydrate_customer()`
> (identidad fresca de es_customers; cliente borrado o sin identidad al dispatch = `failed`
> auditado, fail-closed). La rama `lead_id > 0` (reconciliada) se evalúa PRIMERO y no cambia; los
> llamadores legacy con `lead` completo tampoco. Sin QA gate en la rama histórica (no hay lead).
>
> **Invariantes preservadas**: NO crea leads sintéticos · NO escribe `es_leads` ni `es_customers` ·
> NO cambia estado comercial · NO cron/hooks/dispatch automático · sin schema nuevo.
>
> **Archivos**: `includes/class-estandar-leads-customer-backfill.php` (modo + clasificador +
> buyer record histórico) · `includes/class-estandar-leads-conversion-backfill-service.php`
> (hidratación es_customers + event_id del caller) · `includes/class-estandar-leads-customers-admin.php`
> (param `bf_backfill_mode`) · `templates/admin/customers/list.php` (selector de modo + reporte) ·
> `estandar-leads.php` (13.0.76 → **13.0.77**) · `tests/run-historical-customer-backfill.php` (NUEVA).
>
> **Validación**: `php -l` PASS (6 archivos) · `run-historical-customer-backfill` **52/52 PASS**
> (simulación no envía · 1 Purchase por cliente elegible · re-run = duplicate sin envío · identidad
> faltante omitida · sin fecha propia ni global bloquea envío · fecha del cliente GANA sobre la
> global · fecha global rescata al cliente sin completed_at con event_time = fecha global · fechas
> inválidas/futuras descartadas ('not-a-date', 2026-02-30, 20-11-2025, 2099-01-01) · re-run con
> fecha DISTINTA (global o completed_at editado) no envía nada · payload con em/ph/fn/ln hasheados
> y CERO atribución inventada · action_source=system_generated · sin amount → sin value/currency ·
> 5xx→retry / 4xx→failed auditados · buyer_json sin PII · guard cero escrituras) · **48/48 suites
> en verde** (run-customer-backfill 34/34 y run-conversion-backfill 7/7 sin regresión — modo
> reconciliado intacto).
>
> **Límites**: (1) hereda de V5 que `dispatch()` solo drena `pending` — filas `retry` no se
> reintentan en esta fase. (2) Cross-modo: si un cliente enviado por el modo histórico se reconcilia
> DESPUÉS y se incluye en una corrida reconciliada, Meta recibiría un segundo Purchase con otro
> event_id (los dos modos no comparten derivación); operar el modo histórico solo sobre el stock
> actual sin lead — es one-shot por diseño. (3) Consecuencia deliberada del event_id sin fecha:
> un Purchase histórico enviado con fecha equivocada NO puede corregirse reejecutando (el cliente
> queda cerrado para siempre); la corrección exige intervención manual sobre la fila del audit.
> (4) La fecha global aplica a TODOS los clientes sin `completed_at` de la corrida — usar los
> filtros proyecto/etapa/límite para acotar cuando las fechas reales difieren por grupo.

> **ADDENDUM V5 (2026-07-14) — CUSTOMER_BACKFILL_V1 (leads 13.0.72). Cierra el eslabón
> "comprador real → Meta".** Antes de esto, el Customer Registry terminaba en la base de datos
> (`matched_lead_id` escrito, nada emitido) y `Estandar_Leads_Conversion_Backfill_Service` estaba
> cargado pero **sin ningún llamador en producción** (solo su test). Ahora existe un puente
> **admin-triggered, histórico y de una sola pasada**:
>
> ```
> es_customers.matched_lead_id
>   → Estandar_Leads_Customer_Backfill::plan()   elegibilidad (read-only)
>   → ::run(dry_run=false)
>       → Conversion_Backfill_Service::enqueue()   es_conversion_backfill_audit (UNIQUE event_id)
>       → Conversion_Backfill_Service::dispatch()  hidrata es_leads → QA gate → Meta_Adapter
>           → Meta_Payload_Builder::build($lead,'CONVERTED') → 'Purchase'
>           → Meta_Graph_Client::send()  →  graph.facebook.com/v23.0/{pixel}/events
> ```
>
> **Reglas de elegibilidad** (por cliente, primer disqualificador gana): `unmatched` (sin
> `matched_lead_id`) · `lead_missing` (el lead ya no existe) · `lead_uuid_missing` (sin uuid no hay
> `event_id` determinístico → fail-closed) · `qa_blocked` (`QA_Policy::ads_feedback_blocked`) ·
> `already_dispatched` (ya hay fila en el audit) → **duplicate**, no error. El resto = elegible.
>
> **Identidad y atribución = SIEMPRE del row canónico de `es_leads`** (em/ph/fn/ln hasheados, fbc,
> fbp, fbclid, utm_*, ip, user agent, `external_id=lead_uuid`). El registro de cliente aporta SOLO
> hechos comerciales (`amount` → value, currency, `completed_at` → event_time). Consecuencia
> deliberada: `buyer_json` del audit **no contiene PII** (hidratación diferida al dispatch).
>
> **Idempotencia**: `buyer_uuid` = derivación determinística de `es_customers.id` (sin schema nuevo)
> → `event_id = conversion-backfill:{lead_uuid}:{buyer_uuid}:Purchase` con UNIQUE en el audit.
> Reejecutar **nunca** emite un duplicado (además, Meta deduplica por `event_id`).
>
> **Invariantes preservadas**: NO modifica `es_leads` ni `es_customers` · NO cambia el estado
> comercial del lead · NO dispara `estandar_lead_status_changed` · NO agrega cron, hooks ni dispatch
> automático futuro · reusa cola/audit/adapter/builder/client/config Meta existentes.
>
> **Cambio en el service (aditivo, backward-compatible)**: `dispatch()` hidrata desde `es_leads`
> **solo** cuando el record trae `lead_id` en la raíz (opt-in; los llamadores legacy con `lead`
> completo mantienen el comportamiento original) + aplica el QA gate (cierra el hueco #5 del canon:
> el backfill era el único dispatch Meta sin gate) + `update_audit()` distingue **`retry`** (timeout,
> 429, 5xx) de **`failed`** (4xx, payload inválido) y agrega el estado terminal **`skipped`**.
>
> **Límites de esta fase (explícitos)**: `dispatch()` solo drena filas `pending` → una fila `retry`
> queda auditada pero **no se reintenta**, y como su `event_id` ya existe, un re-run la reporta como
> `duplicate`. El drenaje de reintentos es Fase 2. No hay dispatch automático de ventas futuras: hoy
> eso sigue dependiendo de que el operador clasifique el lead (path `estandar_lead_status_changed`).
>
> **Archivos**: `includes/class-estandar-leads-customer-backfill.php` (NUEVO) ·
> `includes/class-estandar-leads-conversion-backfill-service.php` (hidratación + QA gate + estados) ·
> `includes/class-estandar-leads-customers-admin.php` (handler `estandar_customers_meta_backfill` +
> reporte) · `templates/admin/customers/list.php` (panel Simular / Enviar a Meta) ·
> `estandar-leads.php` (require + versión 13.0.71 → **13.0.72**).
>
> **Validación**: `php -l` PASS en los 6 archivos · `tests/run-customer-backfill.php` (NUEVA) =
> **32/32 PASS** (dry-run no envía · 1 Purchase por comprador elegible · re-run sin duplicados ·
> unmatched/lead borrado/sin uuid omitidos · QA-blocked omitido en plan Y en dispatch · fbc/fbp/UTM/
> IP/UA/em/ph canónicos en el payload · `buyer_json` sin PII · 5xx→`retry` / 4xx→`failed` ·
> guard: cero escrituras a `es_leads`/`es_customers`) · **suite completa de estandar-leads: 47/47
> suites en verde** (incl. run-conversion-backfill 7/7 sin regresión, Meta 289 asserts, invariants).

> **ADDENDUM V4 (2026-07-10) — CAPTURED_QUALIFIED_EVENT_CONTRACT (leads 13.0.49 · forms 13.0.48).**
> Supersede la nota de dedup de V3 (que dependía de la ventana de Meta y representaba la
> captura como QUALIFIED — defecto semántico corregido).
>
> **Contrato final:**
> ```
> CAPTURA  (form válido → persistencia OK):
>   event_name 'Lead' · event_id <lead_uuid>:CAPTURED
>   Server: estandar_lead_ingested (post-COMMIT) → es_meta_queue (UNIQUE lead_id+status
>           = doble submit/reintento jamás genera 2º Lead Server) → worker existente.
>   Browser: la respuesta AJAX de forms entrega meta_lead_event_id (derivado del uuid
>           REALMENTE persistido vía Estandar_Leads_Ingestion::last_ingested()); el JS
>           SOLO consume ese valor — jamás lo reconstruye. Sin id del servidor (p.ej.
>           persistencia fallida: 7 early-returns en capture() dejan last_ingested=null)
>           NO se emite Browser Lead. Fallback equivalente (uuid v4 posteado) existe
>           únicamente SERVER-SIDE y solo cuando estandar-leads está ausente
>           (Estandar_Forms_Handler::meta_lead_event_id_for_response()).
>   → mismo hecho, mismo event_name, mismo event_id ⇒ dedup real en Meta.
> CALIFICACIÓN (status → QUALIFIED):
>   event_name 'QualifiedLead' (custom event CAPI, documentado) ·
>   event_id <lead_uuid>:QUALIFIED — nunca deduplica contra la captura
>   (distinto name y distinto id). Resto del mapa comercial sin cambios.
> ```
> Sin cambios: cola/retries/backfill/config/navegación Integraciones. Archivos: ingestion
> (last_ingested + canonical_lead_uuid), meta-adapter (on_lead_ingested + capture_event_id),
> payload-builder (mapa), forms class-forms.php (meta_lead_event_id en respuesta) +
> estandar-forms-public.js (consume server id). Tests: run-meta-captured-contract.php (PHP,
> nueva) + estandar-forms/tests/run-meta-lead-browser.test.js (Node, nueva, archivo JS real).

> **ADDENDUM V3 (2026-07-10) — CONFIG_PRESERVATION + TEST EVIDENCE.**
> - `save_settings()` endurecido: campos de texto solo se escriben si vienen en el POST
>   (guardado parcial no borra claves); **Access Token keep-if-empty** (vacío nunca pisa el
>   guardado; rotación = pegar valor nuevo); el secreto ya no se imprime en el DOM (campo
>   password vacío + placeholder) — ni en el form legacy ni en el provider.
> - wp_options: las 4 legacy (`pixel_id/access_token/test_event_code/test_mode`, prefijo
>   `estandar_leads_meta_`) intactas sin rename/migración/delete; 13.0.47 agregó 4 nuevas
>   (`enabled/browser_events/server_events/debug_logging`). Única escritura en el ecosistema:
>   `Estandar_Leads_Meta_Adapter::save_settings()`. Cero `delete_option`.
> - Dedup Browser/CAPI: forms 13.0.47 emite `fbq('track','Lead',{},{eventID: lead_uuid+':QUALIFIED'})`
>   (+ `eid` en el fallback de imagen) — misma derivación que `event_id()` del adapter. Dedup
>   efectiva solo dentro de la ventana de Meta (~48h) por el desfase captura→QUALIFIED.
> - Suites (harness standalone propio, PHP CLI): run-meta-adapter 17 · payload-builder 27 ·
>   graph-client 24 (+3 test_event_code top-level) · **settings-gates 25 (nueva)** ·
>   **pixel 16 (nueva)** · **config-preservation 28 (nueva)** · commercial-outcomes 29 ·
>   conversion-backfill 7 = **173/173 PASS**.

> **ADDENDUM V2 (2026-07-10) — INTEGRATIONS_HUB_V1.** La UI de configuración Meta se movió de
> Leads → Configuración a **Proyectos → Integraciones** (`?page=estandar_projects_integrations`,
> subtab order 15 del grupo `estandar_projects`: Proyectos · Integraciones · Campaign Discovery ·
> Operadores). Arquitectura multi-proveedor:
> - **core-mu 13.0.47**: `includes/class-integration-registry.php` (contrato abstracto
>   `Estandar_Integration_Provider` + `Estandar_Integration_Registry`, boot lazy vía hook
>   `estandar_integrations_register`; placeholders Google/LinkedIn sin lógica) +
>   `admin/class-integrations-admin.php` (pantalla 100% genérica: dashboard de estado, nav
>   secundaria por proveedor, form desde `settings_fields()` declarativo — cero código de
>   proveedor en la capa admin). Storage abstraído: la Suite consume
>   `Estandar_Integration_Registry::settings('meta')`, nunca option names.
> - **estandar-leads 13.0.48**: `class-estandar-leads-meta-integration-provider.php` (delegado
>   fino sobre Meta_Adapter; names POST `meta_*` idénticos → `save_settings()` sin cambios;
>   require DENTRO del hook = CORE_OPTIONALITY sin fatal) + partial
>   `templates/admin/meta-integration-panel.php` (fuente única de diagnostics/status/visor,
>   consumido por provider y por el fallback standalone). `settings.php`: con registry →
>   puntero a Integraciones; sin core → form legacy + partial (compat standalone intacta).
> - **Dedup preexistente corregido**: `Estandar_Campaign_Labels_Admin::render_local_tabs()`
>   hardcodeaba las 3 subtabs → ahora usa `estandar_core_render_subtabs('estandar_projects', …)`.
> - Adapter/Builder/Client/Pixel/cola/event_id/backfill: **sin cambios** respecto de V1.
> - Verificación: `php -l` 8 archivos PASS · suites Meta+outcomes+backfill 101/101 PASS.

## 1. Arquitectura actual (auditada — ya era correcta)

```
estandar_lead_status_changed (Business Event existente)
  → Estandar_Leads_Meta_Adapter        subscriber · gates · es_meta_queue · worker WP-Cron · retries · dead-letter
      → Estandar_Leads_Meta_Payload_Builder   puro: mapping status→evento, hashing SHA-256 PII, omisión de vacíos
          → Estandar_Leads_Meta_Graph_Client  transporte only: 1 POST, respuesta normalizada de 6 claves
```

- La separación Adapter → Builder → Client ya existía y está limpia. **No se movió ninguna responsabilidad.**
- Cola `es_meta_queue` (UNIQUE lead_id+export_status, idempotente), event_id determinístico
  (`lead_uuid:STATUS`), Purchase Backfill (`dispatch_backfill_purchase`) — **intactos**.
- Commercial Outcomes log (`es_commercial_outcomes_log`) ya registraba cada dispatch Meta.

## 2. Problemas encontrados

| # | Problema | Severidad |
|---|----------|-----------|
| 1 | `test_mode` se guardaba pero **ningún código lo consultaba** — la UI prometía "no se envían" y el worker enviaba igual | **BUG funcional** |
| 2 | `test_event_code` solo se usaba en diagnostics, nunca en envíos reales; además se inyectaba **dentro del evento** (spec Meta: campo top-level del request) | BUG |
| 3 | Browser Pixel inexistente en el plugin — dependencia de snippets `pixel_acacios` / pixel de Facebook; `estandar-forms-public.js:630` dispara `fbq('track','Lead')` que hoy depende del snippet | Gap |
| 4 | Sin interruptor maestro ni toggles Browser/Server/Debug ("configurado = activo") | Gap |
| 5 | Docblocks "SKELETON SCOPE… No Graph API / No worker / No retry" **obsoletos** — todo eso ya estaba implementado (deuda de documentación, del tipo CANON_CODE_RECONCILIATION) | Deuda |
| 6 | Variable muerta `$tec_ok` en diagnostics; enum `processing` reservado pero nunca escrito (por diseño anti-orphan) | Deuda menor |
| 7 | Sin visor de eventos ni duración de envío | Gap |

Sin TODO/FIXME/`__mc_*`/código de commissioning en los archivos Meta.

## 3. Cambios implementados (todos aditivos)

| Archivo | Cambio |
|---------|--------|
| `includes/class-estandar-leads-meta-adapter.php` | Docblock real (fin del "SKELETON"); gates `is_enabled()`/`server_events_enabled()` en subscriber y worker; **enforcement de test_mode** (cola se retiene en `pending`, se despacha al desactivar); `duration_ms` medido alrededor del Graph Client (viaja en `response_json` del outcome log); debug logging opcional (`META_DEBUG_SEND`, sin PII); `save_settings`/`get_settings` con 4 toggles nuevos; `queue_stats()` y `recent_log_rows()` para la UI (nunca seleccionan `payload_json` ni token) |
| `includes/class-estandar-leads-meta-graph-client.php` | `test_event_code` como campo top-level del request en TODOS los envíos; nota AUTH sobre Bearer (ver §6) |
| `includes/class-estandar-leads-meta-pixel.php` | **NUEVO** — Browser Pixel: `fbq('init')` + PageView en todo el frontend, ViewContent en singular; gates enabled + `browser_events==='1'` + pixel_id; jamás imprime si está desactivado; no lee el token |
| `includes/class-estandar-leads-admin.php` | Diagnostics: eliminada inyección incorrecta de test_event_code dentro del evento + `$tec_ok` muerto |
| `templates/admin/settings.php` | Toggles: Activar Meta / Browser Events / Server Events / Debug Logging; tarjeta **Meta Status** (pixel, token, conectividad, cola, último envío, último error, test code, browser/server); visor **Últimos eventos** (timestamp, evento, lead_uuid, event_id, resultado, HTTP, retries, duración — sin PII/token/payloads) |
| `includes/class-estandar-leads-schema.php` | Solo comentario: "SKELETON" → nota real sobre enum `processing` reservado |
| `estandar-leads.php` | require + init de `Estandar_Leads_Meta_Pixel`; versión 13.0.46 → **13.0.47** |

**NO tocado**: event_id, cola/es_meta_queue (cero cambios de schema), Payload Builder, Purchase Backfill,
hooks, API pública, modelo comercial.

### Defaults retro-compatibles (crítico)
- `enabled` y `server_events`: `''` (instalación pre-toggle) **cuenta como activado** — ninguna
  instalación viva se apaga sola al actualizar.
- `browser_events`: default **OFF** — los sitios que aún corren los snippets no duplican PageView.
- `debug_logging` y `test_mode`: default OFF.

### Nota sobre Settings API
La sección usa el patrón canónico del plugin (admin-post + nonce + capability + `sanitize_text_field`
+ `update_option`), igual que Email/Compliance/Google Ads Destination. Migrar solo Meta a
`register_setting()` habría creado dos patrones en la misma pantalla sin ganancia funcional —
las garantías (sanitización, nonce, permisos, persistencia en options) son equivalentes.

## 4. Orden de implementación aplicado
1. Adapter (gates + test_mode + duración + settings) → 2. Graph Client (test_event_code) →
3. Pixel (nuevo) → 4. Bootstrap/versión → 5. UI settings/status/logs → 6. Limpieza de deuda.

## 5. Verificación
- `php -l` en los 7 archivos: PASS.
- Suites: run-meta-adapter 17/17 · run-meta-payload-builder 27/27 · run-meta-graph-client 21/21 ·
  run-commercial-outcomes 29/29 · run-conversion-backfill 7/7 → **101/101 PASS**.

## 6. Evaluación Authorization: Bearer — DECISIÓN: NO MIGRAR (documentado)
- Meta acepta ambos: `access_token` en body (forma oficial documentada de CAPI) y header Bearer.
- Ventaja del header: el token no aparece en logs que capturen request bodies.
- Riesgos: proxies/WAF que strippean `Authorization`; cero ganancia funcional; el body viaja por
  TLS igual que el header. Con el canon "nunca romper producción": se mantiene el parámetro.
- Trigger de reapertura: Meta depreca el parámetro. Nota permanente en el docblock del client.

## 7. Migración segura (por sitio)
1. Deploy 13.0.47. Nada cambia solo: CAPI sigue igual (defaults ON), Pixel sigue en los snippets
   (browser_events OFF).
2. Verificar Meta Status en Leads → Settings: cola, último envío, conectividad.
3. Opcional: activar Debug Logging 48h y revisar `META_DEBUG_SEND` en Telemetría.

## 8. Plan de retiro de pixel_acacios / pixel de facebook
```
FASE A (validación, por sitio):
  1. Confirmar Pixel ID del snippet == Pixel ID configurado en el plugin.
  2. En ventana de bajo tráfico: desactivar el snippet → activar Browser Events → guardar.
     (orden estricto: nunca ambos activos — duplica PageView)
  3. Verificar en Events Manager → Test Events (o Meta Pixel Helper):
     PageView 1× por página, ViewContent en fichas, Lead al enviar formulario.
FASE B (observación 48h):
  4. Events Manager → Data Sources: volumen de PageView/Lead sin caída ni duplicación.
FASE C (retiro definitivo):
  5. ELIMINAR (no solo desactivar) pixel_acacios y "pixel de facebook".
  6. Repetir por sitio: Acacios primero (validación), luego AK y resto.
ROLLBACK: desactivar Browser Events (checkbox) → reactivar snippet. Cero deploy.
```

## Deuda aceptada (registrada, no eliminada)
- Enum `processing` en `es_meta_queue`: valor reservado jamás escrito. ALTER de enum en producción
  por cosmética = riesgo > beneficio. Documentado en schema.
- Lead browser sin `eventID`: `estandar-forms` dispara `fbq('track','Lead')` sin event_id. No hay
  colisión de dedup hoy (CAPI Lead se emite en QUALIFIED, no en captura → eventos distintos).
  Si algún día CAPI emite Lead en captura, agregar eventID compartido (lead_uuid ya está en el DOM).
