# GOOGLE_ADS_OCI — API AUDIT V1 (estandar-leads)

Módulo: `Estandar_Leads_Google_Adapter` + `Estandar_Leads_Google_Payload_Builder` + `Estandar_Leads_Google_DM_Client`.
Panel: Integration Registry → "Google Ads (Data Manager)" (`Estandar_Leads_Google_Integration_Provider` + `templates/admin/google-integration-panel.php`).
Opciones: prefijo `estandar_leads_google_`. Cola: `es_google_queue`.

## Diagnóstico (con evidencia)

| Punto | Resultado | Evidencia |
|---|---|---|
| API real | **Data Manager API** (NO Google Ads API / NO ConversionUploadService) | `class-estandar-leads-google-dm-client.php:33` `API_BASE = https://datamanager.googleapis.com/v1` |
| Endpoint | `POST /v1/events:ingest` (reconciliación: `GET {requestId}`) | dm-client:70 `API_BASE . '/events:ingest'` |
| OAuth scope | `https://www.googleapis.com/auth/datamanager` | dm-client:16; provider:113 |
| OAuth flujo | refresh-token grant → access token cacheado en transient (~60s antes de expiry) | dm-client `access_token()` :150-195, `TOKEN_URL = oauth2.googleapis.com/token` |
| Developer token | **NO requerido** (concepto de Google Ads API, no de Data Manager) | dm-client:17; el adapter no define opción `developer_token` |
| Credenciales | customer_id, conversion_action_id, (login_customer_id opcional), oauth_client_id/secret/refresh_token | adapter:551-553, `is_configured()` |
| Payload OCI por clic | Data Manager `Event`: `adIdentifiers{gclid,gbraid,wbraid}`, `eventTimestamp` (RFC3339 UTC = momento de calificación), `conversionValue`+`currency`, `transactionId` (dedup) | payload-builder:44-70, 105-107, 181-192 |
| Ruteo a conversión | `destinations[].operatingAccount.accountId=customer_id`, `productDestinationId=conversion_action_id`, `loginAccount=login_customer_id` (MCC, opcional) | dm-client `destination()` :205-229 |
| Dry-run | `validateOnly=true` → Google valida sin registrar | dm-client:67; adapter `dry_run` DEFAULT ON (:544) |
| Secretos en logs | client_secret / refresh_token / access_token nunca salen de `access_token()`; debug = request_id/http/duration, sin PII | dm-client:12; adapter debug logging |

**Por qué Data Manager API es compatible con "Importar desde clics":** `events:ingest` con `adIdentifiers` (gclid/gbraid/wbraid) y `productDestinationId = conversion_action_id` entrega conversiones basadas en clic a la conversion action cuya fuente es *Sitio web (Importar desde clics)*. Es la vía sucesora de OCI para conversiones offline por clic; no usa el `conversionAction` resource ni el developer token de la Google Ads API clásica.

## Consistencia UI / código / doc

La "pantalla actual" (panel Integration Registry) pide exactamente: Customer ID · Login Customer ID · Conversion Action ID · OAuth Client ID/Secret · Refresh Token · Dry-run · Modo test · Debug Logging. **No pide Developer Token.** Coincide 1:1 con las opciones del adapter y con la API real. Provider y panel dicen "Data Manager API" y scope `datamanager`. **No hay inconsistencia dentro del módulo → no se cambió código.** (Rama del prompt: "Si el código usa Data Manager API: no cambiar a Google Ads API, documentar.")

## Flujo operativo

1. Config: cargar customer_id, conversion_action_id, OAuth client + refresh_token (scope `datamanager`). `enabled` DEFAULT OFF, `dry_run` DEFAULT ON.
2. Dry-run: lead `QUALIFIED` → `es_google_queue` → worker → `events:ingest` con `validateOnly=true` → fila queda `validated` (Google acepta forma, no registra).
3. Envío real: apagar dry_run → filas `validated` se re-despachan → `validateOnly=false` → `completed` con `requestId`. `UNIQUE(lead_id, export_status)` + `transactionId` garantizan un solo envío real.
4. Retries: backoff exponencial (15m→24h), `MAX_ATTEMPTS`; cron = capa de recovery del dispatch inmediato.

## Riesgos / bloqueos confirmados

- **Coexistencia de dos integraciones Google distintas** (NO tocada — otra plugin/otro API): `settings.php` renderiza un bloque legacy "Google Ads OCI" con campo **Developer Token** vía `Estandar_Google_Ads_Destination` (definida en `estandar-destinations/adapters/class-google-ads-destination.php` = Google Ads API clásica). Es una integración separada, no el módulo Data Manager. Riesgo: doble envío de conversiones si ambas se configuran para el mismo lead. Fuera de alcance de esta fase (plugin distinto).
- **Bloqueo externo (Google Cloud):** el módulo Data Manager no puede enviar hasta contar con credenciales OAuth de un proyecto de Google Cloud con **Data Manager API habilitada** y un refresh_token con scope `https://www.googleapis.com/auth/datamanager`.

## Próximo paso exacto (Google Cloud)

1. Habilitar **Data Manager API** en el proyecto de Google Cloud.
2. Crear credencial OAuth (Client ID + Secret).
3. Generar un **refresh_token con scope `https://www.googleapis.com/auth/datamanager`** (NO `auth/adwords`, NO developer token).
4. En Google Ads, asegurar la conversion action "Lead Calificado" con fuente *Sitio web (Importar desde clics)* y anotar su Conversion Action ID.
5. Cargar customer_id, conversion_action_id, (login_customer_id si hay MCC), client_id/secret, refresh_token en el panel. Mantener dry_run ON hasta ver `validateOnly OK`, luego apagarlo.
