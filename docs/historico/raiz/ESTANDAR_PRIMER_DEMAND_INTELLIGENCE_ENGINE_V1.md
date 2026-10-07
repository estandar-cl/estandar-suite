# ESTANDAR PRIMER — DEMAND INTELLIGENCE ENGINE
## PHASE 1 — GOOGLE ADS INTELLIGENCE

**STATUS:** ARCHITECTURE_V1 (producto + arquitectura; cero implementación)
**SUPERSEDE:** `ESTANDAR_PRIMER_ADS_INTELLIGENCE_ENGINE_V1.md` — se conserva su gobernanza (evidencia/confianza/determinismo, integración desacoplada); cambia el centro de gravedad: de "términos y keywords" a **Demand Objects** (intención comercial).
**FUENTES FASE 1:** Google Ads · Search Console · Estandar Leads · Estandar Analytics. Nada externo todavía.

---

## 0. Evidencia que gobierna este diseño

La auditoría real de cuenta demostró que el cuello NO es tracking:

```
Search IS ≈ 11% · Lost IS (Rank) ≈ 89% · CTR sobre promedio ·
Ad relevance sobre promedio · Landing experience BAJO promedio ·
3 de 23 keywords concentran las impresiones · demanda comercial sin capturar
```

Lectura arquitectónica de esa evidencia:

1. **El problema es adquisición de demanda, no conversión de clicks.** El mercado busca; nosotros aparecemos 1 de cada 9 veces.
2. **La fuga dominante es RANK, no presupuesto.** Y el componente débil del Quality Score es la landing → la inteligencia de landing no es cosmética: es palanca de rank.
3. **La cuenta piensa en keywords y por eso está ciega**: 20 de 23 keywords son apuestas muertas mientras intenciones completas quedan sin expresión. La unidad correcta de gestión es la **intención comercial**, y el keyword es solo una de sus expresiones.

Por eso el motor administra **Demand Objects**, no listas de keywords. Google Ads es el primer *consumidor* del mapa de demanda — no su dueño. (SEO, Meta, LinkedIn serán consumidores futuros del mismo mapa.)

---

## 1. Arquitectura general

Plataforma de inteligencia en cinco planos. El **Demand Object es una proyección determinista** (se recomputa desde el ledger de evidencia; nunca se edita a mano), lo que preserva replay total con una ergonomía de producto de "un objeto por intención".

```
┌─ PLANO 1 · SENSE (adapters de señal, pull-only) ───────────────────────┐
│  GoogleAdsSignal (search terms, keywords, auction insights, QS, cost)  │
│  SearchConsoleSignal (queries orgánicas = demanda gratis descubierta)  │
│  LeadsSignal (spine de eventos comerciales, nivel submission/interés)  │
│  AnalyticsSignal (sesiones, gclid/utm, atribución normalizada)         │
│        ↓ SignalBatch normalizado → EVIDENCE LEDGER (append-only)       │
└────────────────────────────────────────────────────────────────────────┘
┌─ PLANO 2 · UNDERSTAND (semántica de la demanda) ───────────────────────┐
│  Expression Mining     términos/queries → expresiones normalizadas     │
│  Intent Detection      clase de intención + modificadores              │
│  Intent Clustering     expresiones → UNA intención comercial           │
│  Market Context        volumen, tendencia, banda competitiva           │
│        ↓ nacen / se actualizan los DEMAND OBJECTS                      │
└────────────────────────────────────────────────────────────────────────┘
┌─ PLANO 3 · VALUE (correlación comercial) ──────────────────────────────┐
│  Commercial Correlation: expresión/keyword/campaña ↔ lead ↔ eventos    │
│  (gclid · utm_term · campaign label resolver) → outcomes por intención │
│  Commercial Scoring: VALUE SCORE del Demand Object                     │
└────────────────────────────────────────────────────────────────────────┘
┌─ PLANO 4 · COVER (diagnóstico de captura, por Demand Object) ──────────┐
│  Campaign Auditor      ¿la intención tiene campaña/ad group?           │
│  Auction Intelligence  ¿aparecemos? (IS, lost-rank, top-of-page)       │
│  Landing Intelligence  ¿la landing honra la intención? (palanca QS)    │
│  Negative Intelligence ¿esta demanda resta? (costo sin outcome)        │
│  Budget Intelligence   ¿el dinero sigue al valor?                      │
│        ↓ GAP SCORE + diagnóstico de fuga por etapa                     │
└────────────────────────────────────────────────────────────────────────┘
┌─ PLANO 5 · DECIDE + LEARN ─────────────────────────────────────────────┐
│  Opportunity Engine    Value × Gap ÷ Effort → prioridad + acción       │
│  Commercial Learning   outcomes acumulados → priors reutilizables      │
│        ↓ read models (Primer UI) · export SOLO vía Destinations        │
└────────────────────────────────────────────────────────────────────────┘
```

Los 11 módulos pedidos quedan: Demand Discovery = Expression Mining + Market Context (P2) · Intent Detection/Clustering (P2) · Commercial Scoring (P3) · Campaign/Auction/Landing/Negative/Budget (P4) · Opportunity (P5) · Commercial Learning (P5). Contrato uniforme de módulo: función pura `evaluate(demand_objects, ledger, config, priors) → Verdict[]`, registrable en un registry — agregar un módulo no toca los demás.

---

## 2. La entidad canónica: Demand Object

Un Demand Object = **una intención comercial del mercado** con todo lo que el negocio sabe de ella. Ejemplo rector: intención *"cambiar de contador"* agrega `cambiar contador`, `contador no responde`, `necesito otro contador`, `cambiar empresa contable`…

```
DemandObject
├─ IDENTIDAD
│   demand_key · label ("Cambiar de contador") · service_ref (servicio del
│   negocio al que mapea) · vertical · language/locale
├─ INTENCIÓN
│   intent_class {transactional · commercial-investigation · informational ·
│   navigational · support} · modifiers {urgencia, localidad, precio, marca,
│   dolor/switching} · intent_confidence
├─ EXPRESIONES (evidencia, hijos)
│   expressions[] {texto normalizado, variantes crudas, fuente(s), primera/
│   última vez vista, matched_keyword_ref?}   ← keywords = subconjunto
├─ MERCADO
│   volume {agregado, tendencia} · competition {cpc_band, densidad} ·
│   serp_context (futuro)
├─ COBERTURA (computada, nunca declarada)
│   campaign_coverage {none · partial · full → campaign/adgroup refs}
│   ad_coverage {current_ads[], ctr, ad_relevance}
│   auction_position {impression_share, lost_is_rank, lost_is_budget,
│   top_of_page_rate}
│   quality {qs, components: expected_ctr / ad_relevance / landing_exp}
│   landing_coverage {match_level: exact · partial · generic · missing,
│   landing_ref?}
├─ COMERCIAL (solo eventos observados, nivel submission/interés)
│   outcomes {leads, qualified (qualified_event por instalación), meetings,
│   sales, oci_exports} · quality_dist · cost {spend, qualified_cpa}
├─ VEREDICTOS (derivados, con gobernanza)
│   value_score 0–100 + factores · gap_score 0–100 + tipo_de_fuga ·
│   opportunity_score · priority_band {P0…P3} · leak_stage (ver §4)
│   recommended_actions[] (refs a Recommendation)
├─ APRENDIZAJE
│   priors_applied[] · learning_notes (qué enseñó esta intención)
└─ GOBERNANZA (obligatoria en todo derivado)
    evidence[] · confidence 0–1 · data_quality {🟢>50 obs · 🟡10–50 · ⚪<10}
    · data_window · computed_at · recompute_inputs_hash
```

Entidades satélite (mismas del diseño anterior, ahora orbitando al Demand Object): `Expression`, `AttributionEdge`, `CommercialOutcome`, `AuctionSnapshot`, `LandingMatch`, `NegativeCandidate`, `Opportunity`, `Recommendation`, `LearnedPrior`.

---

## 3. Flujo de datos

```
pull cíclico (4 adapters) → SignalBatch → EVIDENCE LEDGER (append-only)
  → Expression Mining (normaliza, dedupe, agrupa variantes)
  → Intent Detection + Clustering → Demand Objects (proyección recomputable)
  → Commercial Correlation (edges gclid/utm/label → outcomes por intención)
  → módulos COVER anotan cobertura/fricción/landing/negativos/presupuesto
  → Opportunity Engine sintetiza → read models / export (Destinations)
  → decisiones humanas + outcomes posteriores → LEDGER → Commercial Learning
```

Reglas duras: ledger append-only (replay total) · Demand Objects recomputables desde cero · ninguna fuente escribe en otra · ausencia de datos se muestra como ausencia (jamás se simula).

---

## 4. Flujo de inteligencia: el diagnóstico de fuga

La pregunta "¿por qué?" se responde con una cadena de diagnóstico por Demand Object — cada etapa tiene su evidencia y su módulo responsable. El motor no dice "CTR bajo": dice **en qué etapa se fuga cada intención**.

```
ETAPA                 EVIDENCIA (Fase 1)              MÓDULO
1 ¿Existe demanda?    SC queries · search terms ·     Demand Discovery
                      volumen
2 ¿Está expresada?    keyword coverage                Campaign Auditor
   (¿hay keyword/ad group para esta intención?)
3 ¿Aparecemos?        IS · lost-rank · top-of-page    Auction Intelligence
4 ¿Nos eligen?        CTR · ad relevance              Campaign Auditor
5 ¿La landing honra   landing_exp (QS) · match level  Landing Intelligence
   la intención?
6 ¿Convierte?         leads por intención (edges)     Commercial Correlation
7 ¿Vale?              qualified · sales · OCI         Commercial Scoring
```

`leak_stage` = primera etapa que falla con evidencia suficiente. La cuenta auditada, leída por esta cadena: etapa 2 rota para la mayoría de las intenciones (20/23 keywords muertas = intenciones sin expresión útil) y etapa 3/5 rotas para las cubiertas (lost-rank 89% con landing débil como causa de rank). Ese es exactamente el output que el operador necesita: *dónde* se pierde cada demanda, no un promedio de cuenta.

Casos especiales: etapa 6 con leads y **0 contactados = NO EVALUABLE** (cuello operativo, no keyword malo — guard del COMMERCIAL_SIGNAL_GATE) · valor≈0 con gasto>umbral y n suficiente → candidato negativo (etapa 7 en rojo con costo).

---

## 5. Modelo de scoring

Dos scores ortogonales por Demand Object + uno derivado. Score y confianza SIEMPRE separados (un 85 con n=4 sale ⚪, no se esconde ni se infla).

**VALUE SCORE — ¿cuánto vale esta intención?**

| Factor | Fuente | Nota |
|---|---|---|
| Intent strength | P2 | transaccional/switching > investigación > informacional |
| Demand volume + trend | Ads/SC | crecer suma; decaer descuenta |
| Lead generation | Leads | nivel submission/interés |
| Lead quality + qualified rate | Leads | `qualified_event` configurable por instalación; jamás HOT/WARM/COLD |
| Sales / offline conversions | Leads/OCI | el factor dominante |
| Strategic fit | config negocio | servicios que el negocio quiere crecer |

Tope estructural: sin outcomes comerciales, el value score máximo queda topado — un término solo-volumen nunca supera a uno con ventas.

**GAP SCORE — ¿cuánto de esa demanda estamos dejando ir?**
Compuesto por tipo de fuga: sin expresión (etapa 2) · lost-rank/IS (etapa 3) · CTR bajo vs esperado (etapa 4) · landing mismatch/QS landing (etapa 5). La competencia entra aquí como **costo de captura**, nunca como mérito.

**OPPORTUNITY SCORE = f(Value × Gap ÷ Effort)** → `priority_band` P0–P3 y `recommended_action` tipada: `create_campaign · create_adgroup · add_expressions · fix_landing / create_landing · raise_position · add_negative · shift_budget`. Presupuesto: Budget Intelligence compara spend vs value score y marca `budget_shift` (dinero en intenciones de valor bajo mientras P0 pierde IS por rank/budget).

Pesos por **perfil de instalación** (inmobiliaria ≠ servicios profesionales), versionados; prohibido `if site == X` (canon QUALIFIED_MODEL).

---

## 6. Integración sin acoplamiento

| Sistema | Dirección | Mecanismo | Regla |
|---|---|---|---|
| **Primer** (host) | interno | El motor expone read models (Demand Map, Opportunities) + comando de recómputo | Primer consume P5; nunca P1–P4 |
| **Leads** | read-only | Adapter sobre el spine de eventos (`es_lead_events`, hooks `estandar_lead_ingested`/`status_changed`) | Nunca escribe; conversión por interés/submission (canon DEDUP); ausencia → se reporta ausencia (patrón CORE_OPTIONALITY, guards) |
| **Analytics** | read-only | Adapter sobre atribución de sesiones/gclid ya normalizada | No redefine "venta" ni atribución; **ANALYTICS_NORMALIZATION_V1 es prerequisito de calidad del Plano 3** |
| **Campaign labels** | read-only | Resolver de CAMPAIGN_MAPPINGS como SSOT | Ningún campaign_id crudo en ningún output |
| **Destinations** | egress único | Toda salida (sheets, n8n, y en V3 acciones sobre Google) viaja por Destinations con approval gate | Prohibida integración directa Google/CRM desde el motor |

Acoplamiento total: 4 adapters de lectura + 1 sink. Ningún plugin del ecosistema sabe que el motor existe. Conectores futuros (Ahrefs, SEMrush, Trends, DataForSEO, Reddit, Bing, Meta, LinkedIn, TikTok) = nuevos adapters del Plano 1 con el mismo contrato SignalBatch — cero cambios del core; *rule of two* antes de generalizar cada familia de contrato.

**Consumidores** (la otra cara de la plataforma): Google Ads es el primer consumidor del Demand Map; SEO/contenido, Meta y LinkedIn serán consumidores futuros de las MISMAS intenciones con sus propios módulos COVER por canal. La demanda es del negocio; el canal es un medio.

---

## 7. Roadmap

**V1 — El Mapa (Fase 1, read-only, 4 fuentes)**
Ledger + Expression Mining · Intent Detection por reglas + Clustering · Demand Objects con cobertura y diagnóstico de fuga (etapas 1–5 con datos de Ads/SC; 6–7 con Leads/Analytics) · Value/Gap/Opportunity scores con badges de confianza · outputs: intenciones faltantes, campañas/ad groups faltantes, landings faltantes o débiles, negativos propuestos, riesgos de rank/QS.
**Criterio de éxito V1 (calcado de la auditoría):** el operador ve el mapa de intenciones con su leak_stage y puede responder "¿por qué IS 11%?" intención por intención — y actuar a mano.

**V2 — La Memoria**
Commercial Learning activo por instalación (priors: qué patrones de intención producen calificados/ventas por vertical) · calibración de confianza contra outcomes de recomendaciones ejecutadas (accepted → done → ¿mejoró?) · Budget Intelligence completo · primeras fuentes externas por el puerto (Trends/DataForSEO) · detección de decaimiento de demanda con causa.

**V3 — La Plataforma**
Knowledge Packs portables entre instalaciones del mismo vertical ("cambiar de contador" aprende una vez, sirve a todos los sitios de servicios profesionales) · acciones semi-automáticas vía Destinations con approval gate (negativos primero, lo más seguro) · consumidores adicionales del Demand Map: SEO opportunities, Meta/LinkedIn · forecasting de presupuesto por intención · loop cerrado con OCI. Gate de apertura: COMMERCIAL_SIGNAL_GATE del ecosistema (sin eventos comerciales vivos río arriba, V3 no se abre).

---

**Anti-goals permanentes:** no keyword generator · no bid management · no mutaciones sin gate humano · no métricas de Google como fin · no scores subjetivos · no datos simulados · no CRM (lista negativa OPERATIONAL_PIPELINE_V1 vigente).

*Fin de la arquitectura Fase 1. Sin implementación por diseño.*
