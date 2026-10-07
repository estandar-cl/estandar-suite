# _retired — A92 (subsistema Core Integraciones/Destinos/Entregas/Secrets/Sandbox/Calendar) + A93 (SecureToken)

**Retirado:** 2026-09-28 · **Sesión:** 050/sesiones/035_Limpieza-Adopcion-Retiros.md (A92 + A124 + A93)
**Decisión del usuario:** A92 "Retirar UI y motor → _retired"; A93 "Retirar **solo SecureToken**"
(gate de adopción: 0 uso medible en 7 instalaciones).

## Qué hacía

Motor técnico del Core para entregar eventos a sistemas externos (webhooks/Google Sheets/Excel),
con UI propia en Core → Configuración (API-Connect + Integraciones + Enlaces seguros) y CLI/REST.

| Archivo | Rol |
|---|---|
| `Destinations.php` | Motor de destinos + cola/entregas (event → destination), reintentos, idempotencia. |
| `Integrations.php` | Bindings formulario → destino (Formularios vinculados). |
| `IntegrationsRest.php` | REST del asistente (discover/columns/preview/bind/sheets/light). |
| `Connections.php` | Cuentas conectadas (OAuth) para adapters. |
| `OAuth.php` | Flujo OAuth (Google/Microsoft). |
| `Adapters.php` + `Adapters/` | Adaptadores (GoogleSheets, GoogleSheetsLight, ExcelOnline, NoOAuth, Simulated). |
| `AppScriptDeploy.php` | Despliegue del Apps Script para la "Conexión liviana". |
| `Sandbox.php` | Simulador de planilla en BD. |
| `Secrets.php` | Secretos cifrados de un solo uso (Enlaces seguros / Secure Share). |
| `Calendar.php` | Eventos de reunión (citas) — **0 call sites** (código muerto). |
| `templates/feature-secure.php` | UI de Secure Share legado. |
| `SecureToken.php` (A93) | Tokens de un solo uso para la ruta pública `?estandar_capture_token=` (0-uso real). |

> **A93 descartado en parte:** el subsistema **incident** (`IncidentDb`/`IncidentEngine`/`IncidentRenderer`/`Repeater`
> y las tablas `capture_requests`/`capture_request_attachments`/`capture_incidents`) **NO se retira**: el form
> #16 `postventa-nativo` de acacios es `form_type=incident` y está activo; `FormRenderer` lo usa. Solo se retiró
> `SecureToken` (0-uso). Ver 050/sesiones/000_comodin.md 2026-09-28.

## Por qué se retira (evidencia)

- **8 tablas en 0 filas en las 7 instalaciones**: `core_destinations` · `core_deliveries` ·
  `core_integrations` · `core_integration_logs` · `core_connections` · `core_secrets` ·
  `core_sandbox_files` · `core_calendar_events` (acacios, acacios/inmobiliaria, estandar,
  guillaume, rodrigovaldes, veloz, akcontadores). Medición `SHOW TABLES` + `COUNT(*)` (2026-09-28).
- `Calendar::` sin ningún call site. `Connections` (OAuth Google) nunca conectó una cuenta.
  `Integrations::bind` sin bindings. Motor `Destinations` cableado pero **no-op** (sin destinos).
- Confirmado contra informe previo `050/documentos/informe-adopcion-suite.md` (2026-09-16).

## Qué se conservó

- **Mapa del sitio (SiteMap)**: se mantiene. Su extra GA4 usaba `Connections` vía
  `class_exists` → ahora degrada con gracia (GA4 no disponible; el mapa y el tracking propio
  `estandar_core_pageviews` siguen intactos).
- **Crypto.php** (`estandar_suite_core_secret_key`): se conserva (lo usa `AdsDataManager`).
- **Correo (Communications/DeliveryLog)**: intacto (es el log de correo, no la cola de destinos).

## Reversión

Restaurar estos archivos a `plugins/estandar-core/includes/` y reponer en `estandar-core.php`
los `require_once`, `register_activation_hook(Calendar)`, `OAuth::init()`, `Adapters::init()`,
`Secrets::init_frontend()`, el drain `estandar_suite_core_drain` y los 4 `dispatch_event`
(capture/signal/survey_completed/quote_issued) + `Integrations::handle_form_entry`; y en
`Admin.php`/`Cli.php`/`Rest.php`/`core-admin.php` reponer UI, handlers y comandos.
Las **tablas NO se borraron** (siguen vacías en la BD); solo se quitaron sus definiciones de
`Schema.php` (no se recrean en instalaciones nuevas).
