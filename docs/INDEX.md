# Estandar Suite — Índice de Documentación

Generado: 2026-08-12.

## Documentación activa

| Documento | Contenido |
|---|---|
| **`docs/ESTANDAR_SUITE.md`** | **MD general del proyecto**: arquitectura, decisión de portafolio, ADRs, specs vigentes, contrato de eventos/webhooks, journey, release |
| **`plugins/<plugin>/README.md`** | **Un MD por plugin** (26 plugins) con descripción, uso, contratos y estado en la Suite |

## Documentación anterior (histórica)

Todo lo anterior vive en `docs/historico/` (sin eliminar, restaurable):

```
docs/historico/
├── raiz/            ← CLAUDE.md (memoria v1) + specs de raíz (META_IMPORT, DEMAND_INTELLIGENCE, PATCH, ROLLBACK, VALIDACION)
├── especificaciones/ ← ESTANDAR_CAMPAIGN_MAPPINGS_V1, TICKET_CONTACT_ATTEMPT_TRACKING_V1
├── auditorias/      ← portfolio-decision (09-ago), real-state-audit, second-layer-analysis (07-ago)
├── decisiones/      ← ADR-003 PROJECT REGISTRY V2, ESTANDAR_CLEANUP_V1
├── roadmap/         ← ROADMAP.md (Proyecto 1 pdf-service + Proyecto 2 unificación)
├── arquitectura/    ← commercial-journey.md, event-catalog.md (contrato de eventos completo)
├── integraciones/   ← webhook-guide.md (guía completa de webhooks)
├── clientes/        ← AK_GTM, AK_MIGRACION (material de clientes)
└── plugins/         ← documentación original por plugin (README previos, PLUGIN-STATUS, CHANGELOGs, docs técnicas, ADRs)
```

## Metadata y release

| Archivo | Contenido |
|---|---|
| `docs/ecosystem-manifest.json` | Manifest del ecosistema (versiones canónicas e inventario de plugins) |
| `docs/release-channels.json` | Config de canales de release (legacy frozen + v2 staging) |
| `docs/estandar-updates/versions.json` | Versiones de plugins para updates |
| `release.command` | Pipeline de release (gates, bump, build ZIPs, publish SFTP SiteGround) |
| `.release-history.log` | Historial de releases |
| `docs/releases/` | Artefactos ZIP históricos |
