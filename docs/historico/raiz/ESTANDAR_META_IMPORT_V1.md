# META_IMPORT_V1

**STATUS:** SPEC_FROZEN  
**Fecha:** 2026-06-22  
**Owner:** Rodrigo  
**Plugin:** estandar-analytics  

---

## Objetivo

Eliminar dependencia de CSV manual para Meta Ads.  
Poblar `ea_ads_daily` automáticamente.  
Completar la capa superior del funnel (Impresiones → Clicks → Sesiones).

---

## Alcance V1

| Incluido | Excluido |
|----------|----------|
| Meta Ads | Google Ads |
| 1 ad account por instalación | Multi-account |
| System User Token (no expira) | OAuth / Business App |
| Cron diario | Sync real-time |
| `ea_ads_daily` existente | Tablas nuevas |
| Granularidad día/campaña/adset/anuncio | Agregados |

---

## Decisión arquitectónica (congelada)

**V1 = Meta Marketing API directa con System User Token.**

Razón: n=2 clientes (Acacios, AK). System User Token no expira, no requiere App Review, no requiere OAuth, no requiere data deletion callback. Un campo en settings, un cron.

**Trigger para V2 (Business App + OAuth):** tercer cliente que necesite Meta import. La migración es barata: `ea_ads_daily` no cambia, el cron no cambia, las queries no cambian — solo cambia de dónde viene el token.

**No construir OAuth/App Review/callback para n=2.**

---

## Flujo

```
Meta Business Manager
  └→ System User Token (scope: ads_read)
       └→ WordPress Settings (encrypted wp_option)
            └→ Cron diario (wp_cron, 06:00 UTC)
                 └→ GET /v21.0/act_{AD_ACCOUNT_ID}/insights
                      ?level=ad
                      &fields=campaign_id,campaign_name,adset_id,adset_name,
                              ad_id,ad_name,impressions,reach,clicks,spend,
                              ctr,cpc,cpm
                      &time_range={"since":"YYYY-MM-DD","until":"YYYY-MM-DD"}
                      &time_increment=1
                      &limit=500
                 └→ UPSERT ea_ads_daily (stat_key: date+channel+project+campaign+adset+ad)
                 └→ Telemetría: META_IMPORT_SUCCESS / META_IMPORT_FAILED
```

---

## Datos importados

Por día, por anuncio:

| Campo | Fuente API | Columna ea_ads_daily |
|-------|-----------|---------------------|
| date | date_start | stat_date |
| campaign_id | campaign_id | campaign_id |
| campaign_name | campaign_name | campaign_name |
| adset_id | adset_id | adset_id |
| adset_name | adset_name | adset_name |
| ad_id | ad_id | ad_id |
| ad_name | ad_name | ad_name |
| impressions | impressions | impressions |
| reach | reach | reach |
| clicks | clicks | clicks |
| spend | spend | spend |
| ctr | ctr | ctr |
| cpc | cpc | cpc |
| cpm | cpm | cpm |

Campos fijos al insertar:
- `channel = 'meta'`
- `project_slug` = configuración por ad account (V1: 1 proyecto por cuenta) o vacío
- `created_at` / `updated_at` = now()

**Nada más.** No importar: frequency, actions, conversions, video_views, relevance_score, ni custom conversions.

---

## Schema

**Sin cambios.** `ea_ads_daily` ya tiene todas las columnas necesarias.

```sql
-- Verificar:
UNIQUE KEY stat_key (stat_date, channel, project_slug, campaign_id(80), adset_id(80), ad_id(80))
```

El UPSERT usa este unique key: `INSERT ... ON DUPLICATE KEY UPDATE`.

---

## Configuración (Analytics → Configuración)

```
Meta Ads
─────────────────────────────────────
Estado:          ● Conectado  /  ○ No configurado

Ad Account ID:   [act_123456789      ]
Access Token:    [••••••••••••••••••••]  (System User Token)
Proyecto:        [Dropdown: proyectos] (opcional, default: vacío)

[Probar conexión]  [Sincronizar ahora]  [Desconectar]

Última sincronización: 2026-06-22 06:00 UTC — 847 filas importadas
```

**Storage:**

```php
wp_option: estandar_meta_import_v1
{
    "ad_account_id": "act_123456789",
    "access_token": "EAAxxxxxx...",  // encrypt_option()
    "project_slug": "parronales",    // opcional
    "enabled": true,
    "last_sync": "2026-06-22T06:00:00Z",
    "last_sync_rows": 847,
    "last_error": ""
}
```

**Token security:**
- Guardar con `encrypt_option()` si disponible (WP 6.x), o como option estándar con DB access control.
- No exponer en REST API.
- No loguear en telemetría.
- No incluir en exports/backups de Leads.

---

## Cron

```php
// Hook: estandar_meta_import_daily
// Schedule: daily, 06:00 UTC (configurable)
// Lookback: ayer + últimos 3 días (Meta actualiza métricas hasta 72h)

function run_meta_import() {
    $config = get_option('estandar_meta_import_v1');
    if (!$config['enabled'] || empty($config['access_token'])) return;

    $today = current_time('Y-m-d');
    $dates = [
        date('Y-m-d', strtotime('-1 day', strtotime($today))),  // ayer
        date('Y-m-d', strtotime('-2 days', strtotime($today))), // anteayer
        date('Y-m-d', strtotime('-3 days', strtotime($today))), // hace 3 días
    ];

    // Un solo request con time_increment=1 cubre los 3 días
    $response = meta_insights_request($config, min($dates), max($dates));

    foreach ($response['data'] as $row) {
        upsert_ads_daily($row, 'meta', $config['project_slug']);
    }

    // Paginación: seguir next hasta agotar
    while (!empty($response['paging']['next'])) {
        $response = wp_remote_get($response['paging']['next']);
        foreach ($response['data'] as $row) {
            upsert_ads_daily($row, 'meta', $config['project_slug']);
        }
    }
}
```

**Backfill manual:** "Sincronizar ahora" permite elegir rango (últimos 7/30/90 días). Meta Insights API permite hasta 37 meses de histórico.

---

## API Meta — Detalles técnicos

**Endpoint:**
```
GET https://graph.facebook.com/v21.0/act_{AD_ACCOUNT_ID}/insights
```

**Parámetros:**
```
level=ad
time_increment=1
fields=campaign_id,campaign_name,adset_id,adset_name,ad_id,ad_name,
       impressions,reach,clicks,spend,ctr,cpc,cpm
time_range={"since":"2026-06-19","until":"2026-06-21"}
limit=500
```

**Rate limits (Marketing API v21):**
- 200 calls/hour por ad account (Standard tier)
- Cron diario = 1-5 calls/día (paginación). Muy lejos del límite.
- Si algún día se necesitan más: batch requests (`POST /` con `batch=[...]`).

**Errores esperados:**
| Código | Significado | Acción |
|--------|------------|--------|
| 190 | Token inválido/expirado | Marcar desconectado, notificar admin |
| 17 | Rate limit | Reintentar en 5 min (wp_cron reschedule) |
| 100 | Parámetro inválido | Log, no reintentar |
| 2 | Servicio temporalmente no disponible | Reintentar próximo cron |

---

## Resultado en Funnel (ya implementado)

El funnel ya consume `ea_ads_daily` (implementado hoy, 2026-06-22):

```
◆ Impresiones     ████████████████████  45,230    Inversión: $1,200
   ↓ 2.1 %
◆ Clicks          █████                    952
   ─ ─ ─ ─ ─ ─ ─ Sitio web (tracker)
   ↓ 63.2 %
≈ Sesiones        ███                      602
   ↓ 18.4 %
≈ Intención       █                        111
   ─ ─ ─ ─ ─ ─ ─ Leads (datos comerciales)
   ↓ 45.0 %
✓ Leads           █                         50
   ...
✓ Ventas                                     2
```

**Métricas derivadas** (calculables con datos existentes, sin queries nuevas):
```
CTR                  = clicks / impressions
Costo por sesión     = spend / sessions
Costo por lead       = spend / leads
Costo por clasificado = spend / qualified
Costo por venta      = spend / sales
```

Estas métricas se calculan en el template, no en la query — son divisiones sobre datos ya disponibles en `$funnel['stages']` + `$funnel['spend']`.

---

## Riesgos

| Riesgo | Severidad | Mitigación |
|--------|-----------|------------|
| Token revocado sin aviso | MEDIA | Telemetría META_IMPORT_FAILED + notice en dashboard |
| Meta cambia API version | BAJA | v21.0 es LTS; deprecation notice con 2 años de anticipación |
| Datos de spend en moneda incorrecta | MEDIA | Meta devuelve en moneda de la ad account; documentar, no convertir |
| project_slug incorrecto | BAJA | V1 = 1 proyecto por cuenta; multiproyecto = V2 (campaign→project mapping) |
| Rate limit en backfill grande | BAJA | Paginación + limit=500 + backoff automático |
| CSV import existente colisiona | NULA | UPSERT por stat_key; import manual y cron escriben la misma tabla, el último gana |

---

## Plan de implementación incremental

```
Fase 1 — Conexión + Import (NEXT BUILD)
  - wp_option storage (encrypted)
  - Settings UI en Analytics → Configuración
  - "Probar conexión" (GET /me?fields=id,name con el token)
  - "Sincronizar ahora" (backfill últimos 7 días)
  - UPSERT a ea_ads_daily
  - Telemetría: META_IMPORT_SUCCESS / META_IMPORT_FAILED / META_IMPORT_ROWS

Fase 2 — Cron diario
  - wp_cron hook: estandar_meta_import_daily
  - Lookback 3 días (corrección de métricas retrasadas)
  - Retry en error transitorio
  - Notice en dashboard si falla >24h

Fase 3 — Métricas derivadas en Funnel
  - Costo por etapa (spend / count por stage)
  - Visible bajo el embudo como fila de costos
  - Solo si spend > 0

Fase 4 — Discovery Queue (ya existe)
  - Estandar_Campaign_Discovery ya colecta IDs de ea_ads_daily
  - Con import automático, Discovery Queue se llena sola
  - Campaign Labels UI ya lista para poblar
```

---

## Estrategia de renovación de token

**V1: System User Token = no expira.** No hay renovación. Si se invalida (permiso revocado en Business Manager), el cron falla → telemetría META_IMPORT_FAILED → notice en dashboard → Rodrigo reconecta manualmente.

**V2 (futuro, Business App):**
```
User Token (short-lived, 1h)
  → fb_exchange_token → Long-lived Token (60d)
  → Cron: si token expira en <7d → auto-refresh
  → Si refresh falla → notificar admin
```

V2 no se construye hasta que haya tercer cliente.

---

## Restricciones (vinculantes)

1. No modificar queries existentes de Analytics.
2. No modificar el Funnel (ya consume ea_ads_daily).
3. No incorporar Google Ads — frente separado futuro.
4. No incorporar TikTok / LinkedIn — fuera de alcance.
5. No multi-account por instalación.
6. No OAuth / Business App — System User Token directo.
7. No custom conversions / offline conversions de Meta — solo métricas de delivery.
8. No almacenar creative assets (imágenes, videos, textos de anuncio).
9. No sincronización bidireccional (solo lectura de Meta).
10. CSV import existente sigue funcionando — coexiste con cron.
11. **REUTILIZAR `ea_ads_daily` existente.** No crear `ea_meta_ads`, `ea_meta_daily`, `ea_campaign_stats` ni ninguna tabla nueva. Todo aterriza en `ea_ads_daily`.
12. **Guardar granularidad completa** (`campaign_id`, `adset_id`, `ad_id`) aunque hoy no se consuman todos — la granularidad habilita Campaña→Resultado, Anuncio→Resultado, Costo por Lead, Costo por Venta sin rediseñar la base.

---

## Decisiones de Rodrigo (congeladas 2026-06-22)

**D1 — Completa, no crea.** META_IMPORT_V1 no es un producto nuevo. El pipeline ya resuelve Campaign ID → UTMs → Sesiones → Leads → Clasificaciones. Lo único que falta es la capa superior: Impressions, Reach, Clicks, Spend. Completar esa capa transforma Analytics de analítica web a analítica comercial.

**D2 — `ea_ads_daily` es el SSOT.** Sin tablas nuevas. El schema ya tiene todas las columnas. El UPSERT usa `stat_key` existente.

**D3 — Granularidad día/campaña/adset/anuncio desde día 1.** Aunque V1 solo muestre totales en el funnel, la granularidad por anuncio está persistida para cuando se necesite Anuncio→Resultado y Costo por Venta.

**D4 — System User Token para n≤2.** Trigger de migración a Business App: tercer cliente configurando token manualmente.

---

## Orden de ejecución (ratificado Rodrigo 2026-06-22)

```
1. Updates V1            — corregir versions.json + Update URI gaps
2. Analytics tabs        — completar reorganización 6 tabs
3. META_IMPORT_V1        — conexión + cron + ea_ads_daily
4. Embudo extendido      — Impresiones → Clicks → Sesiones → ... → Ventas
                           + métricas derivadas (CTR, CPC, CPL, CPV)
5. Google Ads Import     — frente separado, mismo ea_ads_daily
```

Punto 4 depende de punto 3: sin META_IMPORT_V1, el embudo empieza en Sesiones.

---

## Dependencias

| Dependencia | Estado |
|-------------|--------|
| `ea_ads_daily` tabla | EXISTS (schema completo) |
| Funnel consume ads | IMPLEMENTED (2026-06-22) |
| Campaign Discovery | DEPLOYED |
| Campaign Labels UI | DEPLOYED |
| ANALYTICS_NORMALIZATION_V1 | INDEPENDENT (no bloquea ni es bloqueado) |

---

## Pregunta respondida (congelada)

> ¿Es más barato usar Meta Marketing API directamente o usar el sistema de partner/business app de Meta para múltiples clientes?

**Para n≤2: API directa con System User Token.** Cero App Review, cero OAuth, cero callback. Un campo, un token, un cron.

**Para n≥3: Business App con OAuth.** Pero la migración es barata: ea_ads_daily no cambia, cron no cambia, queries no cambian. Solo cambia la fuente del token.

**Trigger de migración:** Rodrigo configurando el tercer System User Token manualmente.
