# estandar-core — Fundación de Estandar Suite v2

**Propósito:** infraestructura técnica reutilizable (framework, contratos, telemetría, secretos one-time, destinos, CLI, REST, salud, adopción probe). **Prerrequisito de los otros 4 productos.**

**Módulos** (`includes/`): `Contracts` (interfaces TelemetryBus/CaptureSink/SignalLedger/CommercialStageMap/AdoptionProbe) · `Schema` (tablas `estandar_core_*`, version 1.0.0, prefijos legados protegidos) · `Telemetry` (bus sin PII) · `Secrets` (AES-256-GCM one-time) · `Destinations` (webhook/sheets/CRM con cola idempotente) · `Channels` (primitivas de canal/atribución compartidas) · `AdoptionProbe` (detección de instalación legada) · `Health` (snapshot estructurado) · `Rest` · `Cli`.

**Tablas:** `estandar_core_telemetry`, `estandar_core_secrets`, `estandar_core_destinations`, `estandar_core_deliveries`.
**Options:** `estandar_suite_core_schema_version`, `estandar_suite_core_secret_key`.
**REST** (`estandar-suite/core/v1`): `GET /health`, `GET /telemetry`, `POST /secrets` (manage_options).
**WP-CLI:** `wp estandar-suite doctor|telemetry|secrets purge --confirm=yes|destinations drain|adoption probe`.
**Tests:** `php tests/run-core.php` (26 assertions). **Seguridad:** 0 PII en telemetría; secretos single-use; REST autenticado.
