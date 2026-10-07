# estandar-brain — Inteligencia transversal

**Propósito:** reglas deterministas, scoring (misma semántica wC/wQ/wB + monotónico), intents, ledger de señales canónicas y adopción de señales históricas de `es_lead_events`/`es_leads`.

**Módulos:** `Rules` (config-driven, data-tier, first-writer-wins, stop) · `Scoring` (pure) · `IntentMap` (vocabulario configurable) · `SignalLedger` (señales idempotentes + cola) · `Adoption` (behavior_signals/import) · `Rest` · `Cli`.

**Tablas:** `estandar_brain_signals`, `estandar_brain_signal_queue`. **Options:** `estandar_suite_brain_schema_version`, `estandar_suite_brain_intent_map`, `estandar_suite_brain_rules`.
**REST** (`estandar-suite/brain/v1`): `POST /score`, `GET /signals`, `POST /rules`.
**WP-CLI:** `wp estandar-suite brain score|signals|adoption import --lead_id=N [--commit]`.
**Contrato:** consume `estandar_suite_capture_event` (scoring + señal por captura). **Nunca** escribe en `es_signal_*`.
**Tests:** `php tests/run-brain.php` (25 assertions).
