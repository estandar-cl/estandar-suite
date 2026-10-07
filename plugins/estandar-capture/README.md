# estandar-capture — Captación omnicanal

**Propósito:** ingesta normalizada de capturas (form/chat/landing/popup/omnichannel), atribución first-touch (click IDs + UTM) y adopción de leads históricos de `es_lead_submissions`/`es_leads` (read-only, first-writer-wins).

**Módulos:** `Ingestion` (normalize/persist/ingest + contrato `estandar_suite_capture_event`) · `Attribution` (delega en `Core\Channels`) · `Adoption` (project_submission/import/rollback) · `Rest` · `Cli` · `WhatsAppBot` (bot de WhatsApp sobre Meta Cloud API, mismo motor IA que el chatbot web).

**Tabla:** `estandar_capture_submissions` (20 columnas, `origin_lead_id` como marcador de adopción). **Options:** `estandar_suite_capture_schema_version`.
**REST** (`estandar-suite/capture/v1`): `POST /captures`, `GET /adoption/historical`, `GET/POST /whatsapp/webhook`.
**WP-CLI:** `wp estandar-suite capture ingest|adoption status|adoption import [--commit]|adoption rollback --confirm=yes` · `wp estandar-suite capture whatsapp status|settings get|settings set|test|debug`.
**Adopción:** dry-run por defecto; idempotente (`origin_lead_id`); rollback borra solo adoptados. **Nunca** escribe en `es_*`.
**Tests:** `php tests/run-capture.php` (35 assertions) · `php tests/run-whatsapp-bot.php` (49 assertions).
