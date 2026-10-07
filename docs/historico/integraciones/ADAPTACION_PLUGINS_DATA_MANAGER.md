# Adaptación de plugins Estándar a la estructura OCI (Data Manager API) — 2026-08-23

> **Contexto:** el radar (`google-ads-radar` / `003_ESTANDAR_RADAR`) migró su
> carga OCI a la **Data Manager API** (Google deprecó
> `ConversionUploadService.UploadClickConversions` para integraciones nuevas).
> Esta nota analiza qué plugins de Estándar deben adaptarse para (a) alinearse
> con esa estructura y (b) ser aptos para que el radar ejecute, diagnostique y
> trabaje eficientemente (vía REST / CLI).

---

## 1. Inventario de vías OCI / Data Manager en plugins

| Plugin | Componente | Vía | Estado |
|---|---|---|---|
| `estandar-leads` | `Estandar_Leads_Google_DM_Client` | **Data Manager API** (`events:ingest` + `requestStatus:retrieve`) | ✅ ALINEADO |
| `estandar-leads` | `Estandar_Leads_Google_Payload_Builder` | Event DM con `conversionValue` + `transactionId` | ✅ ALINEADO |
| `estandar-leads` | `Estandar_Leads_Google_Adapter` + cola `es_google_queue` | DM API, `reconcile_recent()` (diagnostics) | ✅ ALINEADO |
| `estandar-leads` | `Ads_Feedback_Observability` | Resolver SSOT read-only del feedback OCI/Meta | ✅ ALINEADO |
| `estandar-leads` | REST `/estandar/v1/leads` | Consumido por el radar (schema `latest_qualification`, `form_fields`) | ✅ ALINEADO |
| `estandar-signal-core` | `Estandar_Signal_Core_Google_OCI` | **Google Ads API legacy v17** `uploadClickConversions` + developer token | ⚠️ DESALINEADO |
| `estandar-destinations` | `Estandar_Google_Ads_Destination` | **Google Ads API legacy v24** `uploadClickConversions` + developer token | ⚠️ LEGACY (riesgo doble envío) |
| `estandar-forms` | `estandar-forms-public.js` / `estandar-attribution.js` | Captura `gclid/gbraid/wbraid` en el sitio | ✅ ALINEADO |
| `estandar-primer` | `Estandar_Google_Integration_Hub` | OAuth scope `datamanager` incluido por defecto | ✅ ALINEADO |

---

## 2. Hallazgo principal

**`estandar-leads` ya está completamente alineado con la Data Manager API** (su
cliente transport-only, payload builder, adapter y observability usan
`events:ingest` / `requestStatus:retrieve`). La migración del radar lo dejó
coherente con esa vía. No requiere cambios para el loop de valor por calidad:
el `conversionValue` se resuelve por **etapa comercial** (commercial values) y el
radar añade la capa de **valor por calidad de lead** (`oci.value_by_quality`) en
su propia carga — dos capas complementarias que no colisionan (mismo `transactionId`
dedup si ambos cargan el mismo evento).

**Los dos puntos a adaptar son los componentes LEGACY que usan la Google Ads API
clásica (`uploadClickConversions` + developer token):**

1. `estandar-signal-core → Estandar_Signal_Core_Google_OCI`
2. `estandar-destinations → Estandar_Google_Ads_Destination`

Ambos quedaron **bloqueados por Google** para integraciones nuevas (mismo error
que bloqueó al radar antes de la migración). Son los que deben migrar a Data
Manager API **si están configurados y en uso** en un sitio de producción.

---

## 3. Análisis de `estandar-signal-core → Google_OCI`

- **Vía actual:** `POST googleads.googleapis.com/v17/customers/{id}:uploadClickConversions`
  con `developer-token` header + scope `adwords`.
- **Problema:** deprecada para integraciones nuevas (Google exige Data Manager
  API). Si un sitio tiene configuradas las opciones
  `estandar_signal_core_google_developer_token/customer_id/refresh_token`, el
  exporter se registra (`estandar-signal-core.php:74-89`) y sus intentos de envío
  fallarán con `partialFailureError` (como le pasó al radar).
- **Impacto real:** depende de si el sitio usa Signal Core con la vía Google
  configurada. En akcontadores no hay evidencia de que esté activo (el radar usa
  `estandar-leads` vía REST). Verificar por sitio antes de migrar.

### Adaptación recomendada (si el exporter está en uso)
Migrar `Estandar_Signal_Core_Google_OCI` a Data Manager API siguiendo el patrón
ya existente en `estandar-leads`:
- Endpoint: `POST https://datamanager.googleapis.com/v1/events:ingest`.
- Scope: `https://www.googleapis.com/auth/datamanager` (sin developer token).
- `Destination`: `operatingAccount{GOOGLE_ADS, customer_id}` +
  `productDestinationId` (conversion action UPLOAD_CLICKS).
- `Event`: `adIdentifiers{gclid|gbraid|wbraid}`, `eventTimestamp` RFC3339,
  `conversionValue`+`currency`, `transactionId` (dedup) = `signal_uuid`.
- Diagnostics: `GET .../v1/requestStatus:retrieve?requestId=...`.
- Opciones nuevas: `estandar_signal_core_google_conversion_action_id` (numérico)
  en lugar del resource name `customers/{id}/conversionActions/{id}`.

### Decisión requerida
¿Está `estandar-signal-core` con la vía Google en uso en algún sitio? Si NO → se
documenta como "legacy no usado, no migrar ahora". Si SÍ → migrar en el proyecto
de plugins y liberar.

---

## 4. Análisis de `estandar-destinations → Google_Ads_Destination`

- **Vía actual:** `POST googleads.googleapis.com/v24/customers/{id}:uploadClickConversions`
  con `developer-token` (Google Ads API clásica).
- **Riesgo ya documentado** (GOOGLE_ADS_OCI_API_AUDIT_V1.md §"Riesgos"): si se
  configura junto a `estandar-leads` (Data Manager) para el mismo lead, hay
  **doble envío de conversiones**. La acción correcta es:
  - Si está en uso → migrar a Data Manager API (mismo patrón) o desactivar.
  - Si no está en uso → dejarlo documentado como legacy y no usarlo en paralelo
    con la vía Data Manager.
- **No tocar ahora** salvo decisión explícita: el módulo no está en el camino
  crítico del radar (el radar consume `estandar-leads`, no destinations).

---

## 5. Aptitud para que el radar ejecute / diagnostique (vía REST / CLI)

Estado actual y brechas:

| Capacidad | Estado | Nota |
|---|---|---|
| Leer leads clasificados | ✅ REST `/estandar/v1/leads` (radar_wp.py) | schema `latest_qualification.quality`, `form_fields` |
| Saber si un lead fue exportado a DM (requestId, estado) | ⚠️ Parcial | `Ads_Feedback_Observability` existe pero **no está expuesto por REST** al radar; solo en panel admin / AI Context |
| Ejecutar carga OCI (feedback positivo/negativo) | ✅ Radar lo hace con su propio `datamanager.py`/`oci_upload.py` | no depende del plugin |
| Reconciliar `requestId` (diagnostics) | ✅ Radar `retrieve_request_status()` + `google-ads-oci-check` | plugin también tiene `reconcile_recent()` |

### Brecha a cerrar (bajo costo, alto valor)
Exponer **el estado de la vía OCI de `estandar-leads` vía REST** para que el radar
lo consuma sin entrar al panel admin. Endpoint candidato:

```
GET /estandar/v1/ads-oci/status?lead_id=<id>          → Ads_Feedback_Observability::resolve(lead_id)['google']
GET /estandar/v1/ads-oci/reconcile?limit=3            → Google_Adapter::reconcile_recent(limit)  (read-only)
GET /estandar/v1/ads-oci/health                       → resumen global de la vía (config, enabled, dry_run, cola)
```

Esto le daría al radar (y al `google-ads-oci-check`) una vía de diagnóstico del
plugin directamente, cerrando el loop plugin↔radar sin duplicar lógica.

### CLI
`estandar-leads` no registra comandos WP-CLI (solo detecta `WP_CLI` para
`execution_source`). Los endpoints REST cubren el caso; no añadir WP-CLI salvo
que se necesite operación headless sin credenciales REST.

---

## 6. Recomendación de acción

| Prioridad | Acción | Dónde | Bloquea |
|---|---|---|---|
| P0 | Definir con el equipo si `signal-core`/`destinations` (vías legacy) están en uso | proyecto de plugins | migrar o documentar como no usados |
| P1 | (Si en uso) migrar `Signal_Core_Google_OCI` → Data Manager API | `estandar-signal-core` | envíos OCI desde Signal Core |
| P2 | Exponer `ads-oci` status/reconcile/health por REST en `estandar-leads` | `estandar-leads` | diagnóstico radar del plugin |
| P3 | No tocar `destinations` salvo decisión (riesgo doble envío ya documentado) | `estandar-destinations` | — |

> Regla de despliegue: los cambios se hacen en el proyecto canónico
> `02_EstandarIA/Estandar_Suite/plugins/<plugin>/`, se prueban y se liberan con
> `release.command` (o el canal del plugin). El radar solo CONSUME vía REST; no
> depende de versiones internas del plugin.

---

## 7. IMPLEMENTACIÓN — Suite v2 (Core: `AdsDataManager`) [2026-08-23]

**Contexto de la decisión:** el usuario confirmó que los plugins legacy
(`estandar-leads`, `estandar-signal-core`, `estandar-destinations`) están
**instalados pero NO activos** en producción; la implementación debe vivir en la
**suite v2** (estandar-core / capture / brain / flow / insights), ser **aditiva**,
**opt-in** y **retrocompatible** (no romper webs, no pisar tokens/keys/datos de
producción, no perder información).

### Entregado (en `estandar-core`, 2026-08-23)

**Nuevo módulo `includes/AdsDataManager.php`** (namespace `Estandar\Core`):

- **Consume hooks canónicos existentes de la suite v2:**
  - `estandar_suite_ad_conversion_send` (platform=google_ads) — hoy se emitía sin
    listeners; ahora es la vía OCI del journey/flow.
  - `estandar_suite_capture_event` — capturas con click ID → evento CAPTURED.
- **Vía:** `POST https://datamanager.googleapis.com/v1/events:ingest`, scope
  `datamanager`, **sin developer token** (igual que el radar y estandar-leads).
- **Discriminación de valor por calidad:** `value_by_quality` configurable
  (EXCEPTIONAL/HIGH→300k, MEDIUM→100k, LOW→30k, UNCLASSIFIED→0). Los negativos
  (`negative=true`) SIEMPRE fuerzan 0.
- **Dedup:** `transactionId = capture_uuid|lead-id + ':' + EVENT` (idempotencia).
- **Diagnóstico asíncrono:** `requestStatus:retrieve` (reconciliación).
- **Seguridad:** secretos cifrados AES-256-GCM (`Crypto`); `masked()` redacta
  client_secret/refresh_token (nunca en claro por REST/CLI).
- **OPT-IN:** si `estandar_suite_core_ads_dm.enabled=false` o sin credenciales,
  el módulo NO hace nada (no toca nada existente).
- **REST (read-only, `manage_options`):**
  - `GET /estandar-suite/core/v1/ads-oci/status` → config redactada + ready.
  - `GET /estandar-suite/core/v1/ads-oci/reconcile?limit=` → requestIds + status.
  - `GET /estandar-suite/core/v1/ads-oci/captures?from&to&limit` → capturas v2
    con click IDs + calidad (contrato para el radar).

**Config (CLI opcional o vía option):**
- Option `estandar_suite_core_ads_dm`: `enabled`, `dry_run` (default ON),
  `customer_id`, `conversion_action_id`, `login_customer_id` (MCC opcional),
  `oauth_client_id`, `oauth_client_secret`, `oauth_refresh_token` (cifrados),
  `currency` (CLP), `value_by_quality`.

**Tests:** `estandar-core/tests/run-ads-data-manager.php` → **21 passed, 0 failed**.
Suite v2 completa sin regresiones: core 154 · capture 280 · brain 29 · flow 70 ·
insights 23 · leads-panel 92 · updates 12. `php -l` OK · gate-symbol-collisions OK.

### Configuración (por sitio, tras el deploy)
1. Habilitar Data Manager API en el proyecto de Google Cloud (ya hecho para
   akcontadores) + refresh_token con scope `datamanager`.
2. Configurar la option `estandar_suite_core_ads_dm` con customer_id
   (1071498042), conversion_action_id (7635084208 "Lead Calificado"),
   credenciales OAuth y `enabled=true` (mantener `dry_run=true` hasta validar).
3. Validar con `GET /estandar-suite/core/v1/ads-oci/status` y `reconcile`.

### Retrocompatibilidad verificada
- Los plugins legacy NO se tocaron (congelados). No hay doble envío: el radar
  (Python) y la suite v2 (AdsDataManager) comparten la misma vía DM API y el
  mismo `transactionId` dedup, pero NO se activan ambos sobre el mismo evento
  salvo decisión explícita de configuración.
- Cero escrituras sobre datos legacy; tablas/options propias con prefijo
  `estandar_suite_core_ads_dm_*`.
