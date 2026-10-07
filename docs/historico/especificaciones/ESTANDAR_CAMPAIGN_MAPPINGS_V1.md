# ESTANDAR_CAMPAIGN_MAPPINGS_V1 — Spec

**Fecha:** 2026-06-11
**Posición:** P0 roadmap v3
**Gate que satisface:** SPEC_REQUIRED
**Tamaño deliberado:** mínimo. Si esta spec crece, es drift.

---

## Problema

Los IDs de campaña (`utm_campaign` y afines) llegan como identificadores opacos (IDs numéricos de Meta/Google o slugs crípticos). Operadores y reportes ven IDs donde deberían ver nombres.

## Objetivo

```text
ID → Etiqueta humana
```

Nada más.

## Alcance (cerrado)

Tres componentes, cero infraestructura:

1. **Diccionario** — mapa `campaign_id → label`, almacenado en una `wp_option` por instalación. Sin tablas, sin cron, sin sync, sin API Meta/Google, sin OAuth.
2. **Resolver** — una función única (p.ej. `Estandar_Leads_Campaigns::label($id)`). Regla de fallback: ID no mapeado → se muestra el ID crudo, **nunca bloquea ni oculta**. Regla de exclusividad: **nadie más traduce IDs** — todo punto de display (emails, admin, analytics, reportes) pasa por el resolver.
3. **Pantalla** — CRUD simple del diccionario en settings de Leads. Opcional de UX (sigue siendo read-only, riesgo cero): pre-poblar la pantalla con los `utm_campaign` distintos ya vistos en leads (un SELECT DISTINCT), para que Rodrigo solo complete etiquetas en vez de adivinar IDs.

## Relación con ANALYTICS_NORMALIZATION_V1 (vinculante)

Normalization define 5 fuentes únicas; una es **campañas**. Este resolver **es** esa fuente única — Normalization lo consume, no lo duplica. Por eso Campaign Mappings va P0: existe antes de que Normalization lo necesite.

## No-objetivos (lista negativa)

Sincronización con plataformas · API Meta · API Google Ads · OAuth · tablas nuevas · cron · validación de IDs contra plataformas · jerarquías campaña/adset/ad · métricas de campaña. Cualquiera de estos = spec nueva, evaluada contra la lista negativa de OPERATIONAL_PIPELINE_V1.

## Aceptación

1. Todo lugar que hoy muestra un `utm_campaign` muestra la etiqueta si existe mapping, el ID crudo si no.
2. El resolver es el único punto de traducción (grep: ninguna otra ruta de display traduce).
3. Instalación sin mappings = comportamiento idéntico al actual (fallback total).
4. Cero escrituras fuera de la `wp_option` del diccionario.

## Puntos abiertos (decidir en build, no bloquean spec)

- Ubicación exacta de la pantalla (tab en settings Leads vs sección propia).
- Si el pre-poblado DISTINCT entra en V1 o queda como follow-up.
