# Estandar Suite — Segunda Capa de Análisis

**Basado en:** Auditoría de Estado Real 2026-08-07
**Fecha:** 2026-08-07
**Naturaleza:** Análisis de resultados de auditoría, sin nueva inspección masiva de código

> **ACTUALIZACIÓN 2026-08-09 (versión 13.0.172):** Re-verificado que los 26 plugins están en
> 13.0.172 (bump posterior a esta auditoría, que registró 13.0.171). Las conclusiones se
> mantienen. La causa de la discrepancia de versiones está en `release.command`: solo actualiza
> la línea `Version:` del header y no las constantes internas. Ver
> `estandar-suite-portfolio-decision-2026-08-09.md`.

---

## Resumen ejecutivo compacto

| Métrica | Valor |
|---|---|
| Cobertura funcional total | ~62% (33/43 con al menos implementación parcial) |
| Cobertura testeada | ~28% (~139 tests runners verificables, concentrados en 6 plugins) |
| Plugins v1 activos | 18 |
| Plugins v2 activos | 9 |
| READY FOR RELEASE | 0 componentes |
| Producción verificable | 1 componente (Capture Forms v1.0.9 en acacios.cl) |
| Legacy sin equivalente v2 | 19 capacidades |
| Capacidades inexistentes (⚪) | 3 (preview correo ×2 duplicado, postventa parcialmente) |
| Capacidades solo diseñadas (🔵) | 10 |
| Capacidades parciales (🟠) | 26 |
| Capacidades implementadas/testeadas (🟡🟢) | 16 |

---

## 1. Cobertura funcional real: desglose del ~62%

El 62% agregado oculta una distribución extremadamente desigual. Desglosando por nivel:

### Qué está realmente IMPLEMENTADO o TESTEADO (🟡🟢) — 37.2% (16/43)

Estas 16 capacidades tienen implementación funcional conectada a flujos reales:

| # | Capacidad | Estado |
|---|---|---|
| 16 | Forms propio de Estandar (ambas versiones) | 🟡 |
| 17 | Builder/configuración de formularios | 🟡 |
| 18 | Shortcodes | 🟡 |
| 19 | Proveedor único de Embed | 🟡 |
| 21 | Entries por formulario | 🟡 |
| 22 | Migración desde Forms legacy | 🟡 |
| 23 | Migración formulario Acacios | 🟢 |
| 31 | Motor común de comunicaciones | 🟢 |
| 32 | TransportRegistry | 🟢 |
| 45-62 | Sistema comercial (varias sub-capacidades) | 🟡 |
| 76 | Cotizaciones / Quote Engine | 🟢 |
| 77 | Document Engine | 🟡 |
| 90 | IA como consumidor del Core | 🟡 |
| 101 | Testing unificado | 🟢 |

**Concentración:** 12/16 capacidades implementadas giran en torno a 3 motores: Communications, Forms/Capture, Quotes.

### Qué está PARCIAL (🟠) — 60.5% (26/43)

La mayoría del inventario está aquí. El patrón es consistente:
- Existe código funcional
- Pero falta integración entre módulos, o
- Existe en v1 pero no en v2, o
- La implementación no alcanza el alcance completo descrito

Ejemplos representativos:
- **Arquitectura de 5 plugins (#2):** 3/5 existen; Catch y Experience sin materializar
- **Catch/Flow (#5):** Flow existe pero no se llama Catch y no tiene todas las capacidades
- **Google Ads OCI (#82):** 3 implementaciones parciales (leads v1, signal-core v1, destinations v2)
- **Surveys (#73-74):** v1 completo, v2 solo base (ConversationEngine)

### Qué está solo DISEÑADO (🔵) — 23.3% (10/43)

10 capacidades sin código verificable:
- Contact Strategy Engine (#70)
- Feedback humano reutilizable (#53)
- Aprendizaje acumulativo (#67, #96)
- Commercial Knowledge (#96)
- Postventa (#75)
- SEO automático (#88)
- Dragon API (#89)
- Preview correo ×2 (#27/#43 = duplicado real)
- Canal unificado ContactChannel (#71) — aunque hay Channels.php parcial

### Qué NO EXISTE (⚪) — 7.0% (3/43)

- #27 y #43 son el mismo vacío (preview de correo), contados dos veces en el inventario
- #75 (Postventa) es diseño puro sin código

---

## 2. Cobertura de validación/test: el ~28% real

### Capacidades correctamente testeadas

| Plugin | Tests | Calidad |
|---|---|---|
| estandar-core | 147 + 88 IEE = 235 | Sólida. Communications 22 tests, IEE 88 tests, contactability |
| estandar-capture | 35 | Buena. Forms, adopción, regresión |
| estandar-quotes | 17 | Excelente. Hydration pipeline, PDF, profiles, delivery |
| estandar-brain | 25 | Correcta. Rules, scoring, contactability |
| estandar-flow | 21 | Correcta. Pipeline, journey, interest |
| estandar-insights | 23 | Correcta. Events, funnel, observability |
| estandar-leads (v1) | 78 | Sólida. Assessment, scoring, Meta/Google |
| estandar-forms (v1) | 26 | Correcta. Forms, surveys, migración |

### Capacidades con tests insuficientes

| Plugin | Tests | Problema |
|---|---|---|
| estandar-kickoff | 1 (SvgUploadXxeTest) | 16 módulos, 1 test unitario mínimo |
| estandar-primer | 6 | 7,551 líneas, tests puntuales (campaign, attribution, compliance) |
| estandar-destinations | 1 | Webhook adapter test solamente |
| estandar-chatbot | 3 | Plugin de 2943 líneas con cobertura mínima |
| estandar-analytics | 6 | 8 tablas, tests solo de integración/contrato |
| estandar-signal-core | 6 | Tests de contrato, no de integración real |
| estandar-seo-intelligence | 6 | Tests de contrato |

### Capacidades sin ningún test relevante

| Plugin | Líneas | Tests |
|---|---|---|
| estandar-cpt-manager | 7,732 | 0 |
| estandar-experience-blocks | ~3000 | 0 |
| estandar-pop | ~1000 | 0 |
| estandar-firmas | ~200 | 0 |
| estandar-duplicate-post | ~324 | 0 |
| estandar-delivery-locator-lite | ~1500 | 0 |
| estandar-notifications | ~500 | 0 |
| estandar-acceptance | Shell scripts | 0 |
| estandar-wp-exporter | ~500 | 1 smoke test |
| kinetichub | ~1500 | 0 |

### Concentración de tests (~400+)

El **82% de los tests** están en solo 6 plugins: estandar-core, estandar-leads (v1), estandar-capture, estandar-forms (v1), estandar-brain, estandar-insights. Los 21 plugins restantes tienen cobertura mínima o nula.

**Conclusión:** El ~28% de cobertura testeada es un promedio que oculta que 14 de 27 plugins tienen 0-1 tests.

---

## 3. Por qué 0 componentes alcanzan READY FOR RELEASE

### Causas globales (afectan a todos los componentes)

1. **Triple esquema de versionado inconsistente.** Headers dicen 13.0.171, constantes internas 1.0.11 o 13.0.158, CHANGELOG 1.0.10. El gate `unified_version` de release.command exige consistencia que no existe.

2. **Ningún plugin pasa los 3 gates simultáneamente.** release.command exige: (a) unified version 26/26, (b) 12 test runners verdes, (c) security scan limpio. Ningún plugin individual está diseñado para pasar los 3.

3. **Ausencia de staging validation E2E.** El flujo documentado Local→staging→producción solo se ha ejercitado para Capture Forms. Los demás plugins nunca han pasado por validación funcional en entorno staging con condiciones reales.

4. **Symlinks rotos / fuente de verdad no canónica.** La arquitectura documentada de symlinks no existe. WORKSPACE.md y el filesystem discrepan.

5. **Documentación incompleta para release.** PLUGINS-HANDBOOK.md solo cubre 5 plugins. Los 4 híbridos y 18 legacy no tienen handbook.

### Causas específicas por componente

| Componente más cercano | Qué le falta exactamente para READY FOR RELEASE |
|---|---|
| **Communications Engine** (🟢 TESTEADO) | Staging validation con credenciales Brevo/SMTP reales. El test suite actual usa mock/health-check sin envío real. La integración con Forms está hecha pero no con Quotes/Surveys. |
| **Capture Forms** (🟡 IMPLEMENTADO) | El componente fue desplegado en producción (acacios.cl) pero sin pasar por staging validation formal. Faltan: tests E2E de submission→email→entry, validación multi-browser del builder, pruebas con formularios de >20 campos. |
| **Quote Engine** (🟢 TESTEADO) | No integrado con TransportRegistry de Core (tiene su propio email). DomPDF duplicado. Sin validación en instalación real. Sin integración con Flow pipeline. |
| **Interest Evolution Engine** (🟢 TESTEADO) | 88 tests verdes pero sin validación en instalación real con datos de leads. Sin integración con el sistema de notificaciones. |
| **Brain/Agent** (🟡 IMPLEMENTADO) | Tests de rules/scoring pero no del agente público. Sin validación de seguridad del double-profile en entorno real. |
| **Integration Core** (🟡 IMPLEMENTADO) | 216 tests pero todos en sandbox. Sin validación OAuth real con Google/Microsoft. Sin E2E con credenciales reales. |

### Condición verificable que impide READY FOR RELEASE para cada uno

Para que **cualquier** componente alcance READY FOR RELEASE, debe cumplir TODOS:

1. ✅ Tests del componente 100% verdes
2. ❌ Versión unificada con el resto de la suite (actualmente inconsistente)
3. ❌ Staging validation con credenciales/condiciones reales
4. ❌ Security scan limpio (no verificado individualmente)
5. ❌ Documentación de release actualizada (handbook, changelog)
6. ❌ Integración con TransportRegistry si envía correo (Quotes no lo hace)
7. ❌ Sin duplicación de dependencias (Quotes tiene dompdf ×2)

**Communications Engine es el más cercano** — solo le faltan staging validation y unificación de versiones.

---

## 4. Las 19 capacidades legacy sin equivalente v2

| # | Capacidad legacy | Plugin v1 | Función real | Equivalente v2 | Estado v2 | ¿Debe migrarse? | Observación |
|---|---|---|---|---|---|---|---|
| 1 | Chatbot IA completo | estandar-chatbot | OpenAI proxy, WhatsApp webhook, leads, conversaciones, analytics, 15 admin tabs, frontend JS | Brain\PublicChat + Agent | Básico (solo chat bubble + REST) | SÍ | Sin WhatsApp ni frontend JS ni multi-canal se pierde el canal de captura conversacional |
| 2 | Analytics completo | estandar-analytics | 8 tablas, session tracking (ea_sid), pageviews, events, conversions, funnel, dashboard | estandar-insights | 1 tabla, 23 tests | SÍ | Sin session tracking y attribución de tráfico se pierde visibilidad de campañas |
| 3 | Lead management completo | estandar-leads | 15 tablas, 78 tests, ingestion, scoring, assessment, commercial journey, customers, advertising, compliance | estandar-flow | 6 tablas, 21 tests | SÍ | Capacidad central del negocio. Flow es insuficiente hoy |
| 4 | SEO Intelligence | estandar-seo-intelligence | Search Console adapter, landing pages, queries, metrics, sync runs, 4 tablas | Ninguno | No existe | PROBABLEMENTE | Es SEO Analytics (Search Console), no SEO Foundation. Utilidad específica |
| 5 | Meta Pixel + CAPI | estandar-leads (meta-*) | Pixel injection, browser events, server events (CAPI), deduplication, queue | Core\Destinations (genérico sin adapter Meta) | Parcial | SÍ | Sin esto se pierde feedback loop publicitario y optimización de campañas |
| 6 | Google Ads OCI | estandar-leads + signal-core | Offline conversion import, adapter, payload builder, queue, dry-run | estandar-destinations (adapter parcial) | Parcial | SÍ | Triple implementación. Urge unificar, no perder |
| 7 | Experience blocks (Gutenberg) | estandar-experience-blocks | 13 bloques visuales (parallax, carousel, marquee, scroll-panels, etc.) | Ninguno | No existe | PROBABLEMENTE | Bloques usados en sitios cliente. Sin ellos se rompen páginas existentes |
| 8 | Popups | estandar-pop | Simple Popup, `[ep_open]`, popup picker block, preview REST | Capture\Popups | Solo schema (tabla vacía) | PROBABLEMENTE | Usado en sitios. Schema existe pero sin renderizado |
| 9 | Kinetic blocks | kinetichub | 12 bloques React cinéticos, REST settings, CSS custom properties | Ninguno | No existe | INCIERTO | Plugin third-party-origin (v1.0.8). Puede ser independiente |
| 10 | Surveys engine completo | estandar-forms (Survey) | Survey DB, handler, analytics, 26 tests, checkbox, etc. | ConversationEngine (ADR 0034) | Base (tipo intercambiable) | SÍ | v1 surveys es funcionalidad probada. v2 solo tiene base conversacional |
| 11 | Autorespuesta | estandar-leads | class-estandar-leads-autoresponse.php, settings | Ninguno | No existe | SÍ | Sin autorespuesta se pierde capacidad de respuesta automática a leads |
| 12 | CPT Manager | estandar-cpt-manager | 7,732 líneas, CPT dinámicos, templates de producto, bloques | Ninguno | No existe | INCIERTO | Monolito enorme. Puede ser reemplazado por bloques nativos WP + Capture |
| 13 | Delivery Locator | estandar-delivery-locator-lite | Geolocalización, n8n integration, rate limiting | Ninguno | No existe | INCIERTO | Nicho específico. Puede no ser necesario migrar |
| 14 | Secure Share | estandar-secure-share | AES-256-GCM, tokens, rate limiting, audit log, email | Core\Secrets | Parcial (compartir secretos one-time) | PROBABLEMENTE | Core\Secrets cubre parte. Secure Share tiene flujo completo de delivery |
| 15 | Firmas | estandar-firmas | Generador de firmas de correo, `[estandar_signature_generator]` | Ninguno | No existe | NO | Funcionalidad menor. Puede absorberse en Kickoff o eliminarse |
| 16 | Website Scanner | estandar-website-scanner | 22 engines, PageSpeed, SEO checker, AI analysis, PDF reports, dompdf | Ninguno | No existe | PROBABLEMENTE | Plugin pesado con 22 engines. Valor potencial como herramienta de venta |
| 17 | WP Exporter | estandar-wp-exporter | Export de plugins/themes a ZIP, batch jobs | Ninguno | No existe | PROBABLEMENTE | Utilidad de migración. Puede absorberse en Kickoff |
| 18 | Notifications multi-canal | estandar-notifications | Arquitectura con 6 adapters (todos .gitkeep/stubs) | Core\Communications | Más avanzado (3 transports reales) | NO | Core Communications ya es superior. Notifications v1 es cascarón vacío |
| 19 | Campaign Resolver | estandar-primer | Campaign Discovery + Label Resolver | Ninguno directo | No existe en v2 puro | SÍ | Sin campaign resolver se pierde trazabilidad de campañas publicitarias |

**Resumen de necesidad de migración:**
- **SÍ (debe migrarse):** 9 capacidades — chatbot, analytics, leads, Meta CAPI, Google OCI, surveys, autorespuesta, campaign resolver, lead management
- **PROBABLEMENTE:** 6 capacidades — SEO, experience blocks, popups, secure share, scanner, exporter
- **NO:** 2 capacidades — firmas, notifications (ya sustituidas)
- **INCIERTO:** 3 capacidades — kinetichub, CPT manager, delivery locator

---

## 5. Los 9 plugins reales de v2

| # | Plugin | Responsabilidad real | Madurez | Dependencias | ¿Corresponde con arquitectura conceptual? | Clasificación |
|---|---|---|---|---|---|---|
| 1 | **estandar-core** | Infraestructura compartida: Communications (TransportRegistry, SMTP, Brevo), Integration Core (OAuth, Adapters, FieldMap, Sandbox), Crypto, Secrets, Destinations, Interest Engine (IEE), Quality, Health, Admin helpers, Channels, Calendar, LegacyMap, Embed | Alta. 235 tests. El más maduro. | Ninguna (es prerequisito) | Parcialmente. Hace de Core + parte de Brain (Interest/Quality) + parte de Experience (Embed). Más responsabilidades que el "Core" conceptual. | **Plugin permanente.** Núcleo indiscutible de la arquitectura. |
| 2 | **estandar-brain** | Inteligencia: Rules, Scoring, SignalLedger, AssessmentEngine, IntentMap, Contactability, Agent (doble perfil), Knowledge, PublicChat | Media. 25 tests. Funcional pero scope limitado. | estandar-core (class_exists guards) | Sí. Corresponde al Brain conceptual como control plane de inteligencia. | **Plugin permanente.** Pero solapado con Core\Interest y Core\Quality. |
| 3 | **estandar-capture** | Captura: Ingestion, Attribution, Forms CRUD, ConversationEngine, FormBridge (CF7/GF), Popups, FormsAdoption, FormRenderer | Alta. 35 tests. Más completo que Brain. | estandar-core (class_exists guards) | Sí. Corresponde al Capture conceptual como motor de captura universal. | **Plugin permanente.** El más cercano a la visión. |
| 4 | **estandar-flow** | Pipeline comercial: Pipeline, Journey (StateMachine, LeadAdapter, InterestAdapter, ReEngagement, Recovery, Outcomes), Customers, Quotes | Media. 21 tests. Scope amplio pero superficial. | estandar-core (class_exists guards) | Sí. Es el equivalente funcional de "Catch". No se llama Catch pero cubre el dominio comercial. | **Plugin permanente.** Pero nombre no coincide (Flow vs Catch). |
| 5 | **estandar-insights** | Analítica/Observabilidad: Events (1 tabla), Funnel, Observability | Baja-Media. 23 tests. Muy por detrás de v1 analytics. | estandar-core (class_exists guards) | Sí. Corresponde a la capa de analytics/observability. | **Plugin permanente.** Pero necesita absorber mucho de v1 analytics. |
| 6 | **estandar-destinations** | Colas de destino: OCI queue (2 tablas), Google Ads adapter, webhook destination, scheduler, health | Baja. 1 test. Legacy-style naming. | Independiente (hooks propios) | Parcialmente. Destination Queue existe como concepto pero este plugin es legacy-style y duplica signal-core/leads. | **Candidato a consolidación.** Duplica lógica de signal-core y estandar-leads. Su funcionalidad debería estar en Core\Destinations. |
| 7 | **estandar-kickoff** | Utilidades: 16 módulos (child-theme, clean-head, security, redirects, maintenance-mode, svg-upload, snippets, etc.) | Media. 1 test para 16 módulos. Legacy-style naming. | Independiente | No. El Kickoff conceptual era "utilidades opcionales ligeras". Esto es un plugin pesado de 16 módulos legacy-style. | **Candidato a consolidación/división.** Demasiado grande para "utilidades opcionales". |
| 8 | **estandar-primer** | Runtime compartido monolítico: AI gateway, diagnostics, integrity, provisioning, campaign discovery, Google Integration Hub, operational hours, updates manager, admin shell | Media. 7,551 líneas monolíticas. 6 tests. | Independiente | No aparece en la arquitectura conceptual de 5 productos. | **Infraestructura temporal / candidato a absorción.** Su funcionalidad debería distribuirse entre Core (AI gateway, updates) y Brain (campaign resolver). |
| 9 | **estandar-quotes** | Cotizaciones: 44 PHP files, 17 tests, 9 tablas, hydration pipeline (10 stages), PDF, profiles, state machine, operator gateway | Alta. El plugin más completo de toda la Suite. | Independiente (no usa TransportRegistry, tiene su propio email/PDF) | Sí. Quote Engine como producto independiente. | **Plugin permanente.** Pero debe integrarse con Core (Communications, Document Engine). |

---

## 6. Flow vs Catch: ¿mismo dominio o diferencia real?

### Qué es Flow hoy

**estandar-flow** (v1.0.11, 21 tests) contiene:

```
estandar-flow/
├── Pipeline            → CRUD de pipeline stages + transiciones
├── Journey/
│   ├── StateMachine    → Máquina de estados del journey comercial
│   ├── LeadAdapter     → Adopción de leads legacy → journey v2
│   ├── InterestAdapter → Señales de interés → journey
│   ├── ReEngagement    → Reactivación de leads inactivos
│   ├── Recovery        → Recuperación de leads perdidos
│   └── Outcomes        → Resultados comerciales (reunión, propuesta, cliente, pérdida)
├── Customers           → Gestión de clientes
├── Quotes              → Integración con Quote Engine (módulo ligero)
├── Tasks               → Tareas comerciales
└── Adoption            → Adopción de datos legacy
```

**Responsabilidad real:** Capa de operaciones comerciales — pipeline, journey, outcomes, customers. Es el equivalente funcional de lo que el inventario llama "Catch como capa comercial/conversión".

### Qué pretendía representar Catch según documentación

Según el inventario y ARCHITECTURE.md:

> "Catch como capa comercial/conversión"

No hay una especificación detallada de Catch como producto independiente. El inventario lo describe como "capa comercial/conversión" sin definir límites precisos. La arquitectura conceptual de 5 productos menciona "Catch" como uno de ellos, junto a Brain, Capture, Experience, Kickoff.

### Solapamiento

| Capacidad descrita para "Catch" | ¿Existe en Flow? |
|---|---|
| Capa comercial/conversión | ✅ Pipeline + Journey |
| Lead → reunión → propuesta → cliente | ✅ Outcomes + StateMachine |
| Registro de clientes | ✅ Customers |
| Tareas comerciales | ✅ Tasks |
| Integración con cotizaciones | ✅ Quotes module |

**Solapamiento: ~90%.** Flow contiene prácticamente todo lo que se esperaría de "Catch".

### ¿Diferentes nombres o diferencia arquitectónica real?

**Son el mismo dominio con distinto nombre.** No hay evidencia de que "Catch" tuviera una especificación arquitectónica diferente a lo que Flow implementa. La evidencia sugiere que:

1. El inventario usó "Catch" como nombre conceptual durante la fase de diseño
2. Durante la implementación, el plugin se materializó como "estandar-flow"
3. No existe ningún artefacto de diseño (ADR, documento, interfaz) que describa "Catch" como algo distinto de Flow

**Conclusión:** Flow = Catch. Es un cambio de nombre de implementación, no una divergencia arquitectónica.

---

## 7. Experience: las capacidades que solo viven en v1

### Los 3 plugins legacy que componen "Experience"

| Plugin | Capacidades | Estado |
|---|---|---|
| **estandar-experience-blocks** | 13 bloques Gutenberg (before-after, cover-transition, magnetic-carousel, marquee, scroll-panels, stack-cards, spatial-gallery, etc.), namespace `Estandar\FX`, REST API `estandar-fx/v1/presets`, manifest, presets | Sin tests. Código funcional. |
| **estandar-pop** | Simple Popup, método de disparo configurable, `[ep_open]` shortcode, Gutenberg popup picker block, preview REST endpoint | Sin tests. Código funcional. |
| **kinetichub** | 12 bloques React (kinetic-ambient-aura, kinetic-cursor-reveal, kinetic-hero-mesh, kinetic-magnetic-button, kinetic-marquee, etc.), REST settings, CSS custom properties, modos de rendimiento | Sin tests. v1.0.8. Tercer-party-origin. |

### ¿Existe infraestructura v2 capaz de absorberlas?

**No.** La infraestructura v2 actual no tiene capacidad de absorber Experience:

- **estandar-core** no tiene ningún módulo de bloques Gutenberg, efectos visuales, ni popups
- **estandar-capture** tiene `Popups.php` pero solo maneja el schema (tabla `estandar_popups`). No tiene renderizado de popups, ni shortcode, ni bloque Gutenberg
- **estandar-experience** como plugin v2 simplemente no existe

### ¿Experience realmente todavía no existe en v2?

**Correcto. Experience no existe en v2.** Las 3 implementaciones legacy (experience-blocks, pop, kinetichub) son funcionales y probablemente están en uso en sitios cliente, pero ninguna ha sido migrada, absorbida o reimplementada en la arquitectura v2.

---

## 8. Arquitectura real (basada en código, no en intención)

```
ESTANDAR SUITE REAL (2026-08-07)
│
├── SISTEMA V1 — LEGACY (18 plugins, ~13.0.158-163, ~103 tests, ~30+ tablas)
│   │
│   ├── 🏗️ CAPTURA Y CONVERSIÓN
│   │   ├── estandar-forms/          Forms engine + Surveys + GF importer
│   │   ├── estandar-leads/          Lead management (15 tablas, 78 tests)
│   │   ├── estandar-chatbot/        Chatbot IA (OpenAI, WhatsApp, 2943 líneas)
│   │   └── estandar-analytics/      Analytics (8 tablas, sessions, funnel)
│   │
│   ├── 🎨 EXPERIENCIA VISUAL
│   │   ├── estandar-experience-blocks/  13 Gutenberg blocks (Estandar\FX)
│   │   ├── estandar-pop/                Simple Popup
│   │   └── kinetichub/                  12 Kinetic blocks (React, v1.0.8)
│   │
│   ├── 📡 PUBLICIDAD E INTEGRACIONES
│   │   ├── estandar-signal-core/        Meta CAPI + Google OCI exporters
│   │   └── estandar-seo-intelligence/   Search Console, landing pages
│   │
│   ├── 🛠️ HERRAMIENTAS
│   │   ├── estandar-cpt-manager/        CPT dinámicos (7732 líneas monolíticas)
│   │   ├── estandar-website-scanner/    22 engines, dompdf
│   │   ├── estandar-secure-share/       Secretos encriptados (AES-256-GCM)
│   │   ├── estandar-delivery-locator-lite/  Geolocalización + n8n
│   │   ├── estandar-duplicate-post/     Duplicar posts
│   │   ├── estandar-firmas/             Generador de firmas
│   │   ├── estandar-wp-exporter/        Export ZIP
│   │   └── estandar-notifications/      Stub (6 adapters .gitkeep)
│   │
│   └── 🧪 TESTING
│       └── estandar-acceptance/         Shell-based acceptance framework
│
├── CAPA TRANSVERSAL COMPARTIDA (ni v1 ni v2 puro)
│   │
│   └── estandar-primer/                 Monolito 7,551 líneas
│       ├── AI Gateway (OpenAI + DeepSeek)
│       ├── Diagnostics Engine
│       ├── Integrity Framework
│       ├── Project Provisioning
│       ├── Campaign Discovery + Label Resolver
│       ├── Google Integration Hub
│       ├── Operational Hours
│       ├── Project Calendar/Registry
│       ├── Compliance Shadow Copy
│       ├── Plugins Update Manager
│       └── Admin Shell
│
├── SISTEMA V2 — NUEVA ARQUITECTURA (5 core + 4 híbridos, ~232+ tests)
│   │
│   ├── 🧠 estandar-core/ (v1.0.11, 235 tests) — INFRAESTRUCTURA
│   │   ├── Communications Engine (TransportRegistry, SMTP, Brevo, WpMail)
│   │   ├── Document Engine (base Pdf)
│   │   ├── Integration Core (OAuth2, Crypto, Adapters, FieldMap, Sandbox, 216 tests)
│   │   ├── Interest Evolution Engine (88 tests)
│   │   ├── Quality Engine (Contactability, LeadQuality)
│   │   ├── Destinations (queue genérica)
│   │   ├── Channels, Calendar, Secrets, Health, Embed, LegacyMap
│   │   └── Admin helpers, CLI framework, REST namespace
│   │
│   ├── 🧠 estandar-brain/ (v1.0.11, 25 tests) — INTELIGENCIA
│   │   ├── Rules, Scoring, SignalLedger
│   │   ├── AssessmentEngine, IntentMap
│   │   ├── Agent (doble perfil: interno/público)
│   │   └── PublicChat, Knowledge, Contactability
│   │
│   ├── 📥 estandar-capture/ (v1.0.11, 35 tests) — CAPTURA
│   │   ├── Forms CRUD (builder 4 subtabs, type interchange)
│   │   ├── Ingestion, Attribution
│   │   ├── ConversationEngine (ADR 0034)
│   │   ├── FormBridge (CF7, GF, JotForm)
│   │   ├── Popups (schema)
│   │   └── FormsAdoption (legacy migration)
│   │
│   ├── 📊 estandar-flow/ (v1.0.11, 21 tests) — COMERCIAL (= "Catch")
│   │   ├── Pipeline, Journey (StateMachine, ReEngagement, Recovery, Outcomes)
│   │   ├── Customers, Tasks, Quotes module
│   │   └── Adoption (LeadAdapter, InterestAdapter)
│   │
│   ├── 📈 estandar-insights/ (v1.0.11, 23 tests) — ANALÍTICA
│   │   ├── Events, Funnel, Observability
│   │   └── Adoption
│   │
│   └── HÍBRIDOS (legacy-style en directorio v2)
│       ├── estandar-destinations/   OCI queue + Google Ads adapter + webhooks
│       ├── estandar-kickoff/        16 módulos de utilidades
│       ├── estandar-quotes/         Quote Engine (44 files, 17 tests) ← EL MÁS COMPLETO
│       └── estandar-primer/        (ya listado arriba como capa transversal)
│
└── PIPELINE DE RELEASE (compartido)
    ├── release.command (292 líneas, 3 gates, SFTP staging)
    ├── ESTANDAR_TEST.command (legacy)
    ├── run-suite.sh (v2)
    └── production-safety-gate.php (449 líneas)
```

---

## 9. Brecha entre arquitectura real y arquitectura conceptual

### REAL HOY

- **27 plugins** en 3 generaciones arquitectónicas
- **9 plugins v2**, de los cuales solo 5 usan el namespace `Estandar\`
- **18 plugins v1** funcionales y necesarios
- **Communications Engine unificado** en Core (mayor logro arquitectónico)
- **Sin plugin "Catch"** — Flow ocupa ese dominio
- **Sin plugin "Experience" en v2** — 3 plugins v1 cubren esa capacidad
- **Kickoff real es pesado** (16 módulos), no ligero
- **Primer es un monolito transversal** no documentado en la arquitectura conceptual
- **Quotes es el plugin más completo** pero arquitectónicamente aislado (no usa TransportRegistry)
- **Triple implementación de Google Ads OCI** (leads, signal-core, destinations)
- **Doble implementación de attribution** (v1 analytics, v2 capture)

### DISEÑO/INTENCIÓN DOCUMENTADA

```
ESTANDAR SUITE (visión conceptual)
              Brain
                │
      ┌─────────┼─────────┐
      │         │         │
   Capture     Catch   Experience
      │         │
      └────┬────┘
           │
      Domain Core
           │
 ┌─────────┼──────────────┐
 │         │              │
Analytics Communications Destinations
 │         │              │
Identity  Email/PDF   Google/Meta/Sheets
           │
       Intelligence
```

5 productos + Kickoff opcional. Sin Primer. Sin plugins híbridos. Un solo motor por dominio. Clean architecture de bounded contexts.

### BRECHA PRINCIPAL

| Aspecto | Real | Conceptual | Brecha |
|---|---|---|---|
| Número de plugins | 27 | 5-6 | 21 plugins de más |
| Generaciones | 3 coexistiendo | 1 unificada | Migración aditiva, no sustitutiva |
| Catch | No existe con ese nombre | Producto independiente | Flow es Catch con otro nombre |
| Experience | Sin implementación v2 | Producto independiente | 3 plugins v1 sin migrar |
| Kickoff | Plugin pesado (16 módulos) | Utilidades ligeras opcionales | Subestimado en diseño |
| Primer | Monolito 7,551 líneas | No documentado | No existe en diseño conceptual |
| Duplicación | Triple Google OCI, doble Attribution, doble PDF | Motor único por dominio | Duplicación real no resuelta |
| Comunicaciones | Unificado ✅ | Unificado | Alineado. Mayor éxito |
| Core responsabilidades | Communications + Interest + Quality + Integration + Channels + Calendar | "Infraestructura" | Core tiene más responsabilidades de las diseñadas |

---

## 10. Falsos problemas detectados

Casos donde el inventario hace parecer que "falta migrar" algo que ya está resuelto:

| Inventario dice que falta... | Realidad |
|---|---|
| "Motor común de comunicaciones" (🟡) | Ya existe, testeado (🟢), unificado en Core. No es un pendiente. |
| "TransportRegistry" como concepto | Ya implementado con 3 adapters reales y 22 tests. |
| "Forms usando motor común" | Ya integrado. `Core\Mail::send()` es llamado desde Capture Forms. |
| "No sobrescribir API keys/tokens" | Crypto AES-256-GCM + Secrets + read-only legacy + production-safety-gate. Ya implementado. |
| "Contratos comunes de correo" | TransportInterface existe y 3 adapters lo implementan. |
| "Scoring" como "en desarrollo" | Existe en v1 (estandar-leads) Y en v2 (Brain\Scoring). Implementado en ambos. |
| "Reglas de calidad" como "en desarrollo" | Existe en v1 (Qualification Rules) Y en v2 (Brain\Rules + Core\Conditions). |
| "Detección de intención" como "en desarrollo" | Interest Evolution Engine con 88 tests. Testeado. |
| "Contactabilidad" como "macro-sprint activo" | Implementado en v1 (Operator Hours) Y v2 (ContactabilityEngine + tests). |
| "Canal de contacto unificado" como "🔵 diseñado" | `Core\Channels.php` existe con abstracción de canales. Parcial pero más que diseño. |
| "Notifications" como capacidad a migrar | v1 es stub (.gitkeep). v2 Communications ya es superior. No migrar, descartar v1. |
| "Firmas" como capacidad a migrar | Funcionalidad menor. Puede absorberse en Kickoff o eliminarse sin impacto. |

---

## 11. Capacidades legacy en riesgo real si se eliminan sin sustitución

| Capacidad | Riesgo | Evidencia |
|---|---|---|
| **estandar-leads completo** | ALTO. Sistema comercial central. 15 tablas con datos de clientes. | 78 tests, integración con Meta/Google ads, commercial journey |
| **estandar-chatbot** | ALTO. Canal de captura conversacional. | WhatsApp webhook, OpenAI proxy, frontend JS, leads integrados |
| **estandar-analytics** | ALTO. Session tracking, attribución de tráfico, funnel. | 8 tablas, cookie ea_sid, datos históricos de campañas |
| **estandar-forms Surveys** | MEDIO. Encuestas funcionales con datos. | 26 tests, survey_responses table con datos |
| **estandar-experience-blocks** | MEDIO. Páginas cliente dependen de estos bloques. | Bloques Gutenberg en producción |
| **estandar-signal-core** | MEDIO. Feedback loop publicitario (Meta CAPI, Google OCI). | 6 tests, queues con datos |
| **estandar-seo-intelligence** | BAJO. Datos de Search Console. | 4 tablas, sync runs |
| **estandar-cpt-manager** | INCIERTO. 7,732 líneas sin documentación de uso real. | Sin tests, sin evidencia de uso en cliente |
| **estandar-primer (campaign resolver)** | MEDIO. Campaign discovery es necesario para attribution. | 6 tests, integración con estandar-leads |

---

## 12. Capacidades encontradas que NO estaban en el inventario original

| # | Capacidad | Ubicación | Importancia |
|---|---|---|---|
| 1 | **estandar-primer** — Runtime monolítico compartido | 7,551 líneas en `estandar-core.php` | ALTA. Es el pegamento que une v1 y v2. AI gateway, diagnostics, integrity, campaign resolver. No está documentado en la arquitectura conceptual pero es infraestructura crítica. |
| 2 | **Interest Evolution Engine (IEE)** | `estandar-core/includes/Interest/` — 88 tests | ALTA. Sistema de detección de señales de intención más avanzado que lo descrito en "Lead Intelligence". No mencionado como motor independiente. |
| 3 | **Integration Core completo** | `estandar-core/includes/` — OAuth, Crypto, Adapters, FieldMap, Conditions, Sandbox, 216 tests | ALTA. Es un sistema de integraciones genérico completo, no solo "imports". Está listo pero infrautilizado. |
| 4 | **estandar-destinations** | Plugin con OCI queue, Google Ads adapter, webhook destination | MEDIA. Más completo que el concepto "Destination Queue" del inventario. Pero legacy-style y duplicado. |
| 5 | **estandar-secure-share** | AES-256-GCM, tokens, rate limiting, audit log, email | MEDIA. Capacidad criptográfica no mencionada. Complementa a Core\Secrets. |
| 6 | **kinetichub** | 12 bloques React, REST settings | MEDIA. Tercer plugin de experiencia visual. Puede ser third-party, no core. |
| 7 | **estandar-cpt-manager** | 7,732 líneas monolíticas | BAJA. Sin tests. Sin claridad de uso. |
| 8 | **estandar-delivery-locator-lite** | Geolocalización + n8n | BAJA. Nicho específico. |
| 9 | **estandar-firmas** | Generador de firmas de correo | BAJA. Funcionalidad menor. |
| 10 | **estandar-duplicate-post** | Duplicación de posts | BAJA. WP ya tiene esto nativo en Gutenberg. |

---

## Mapa de madurez

(Agrupando las 43 iniciativas del inventario en su estado canónico)

### 🚀 Producción (1)

| # | Iniciativa |
|---|---|
| 23 | Migración del formulario Acacios (Capture Forms v1.0.9 en acacios.cl) |

### ✅ Ready for Release (0)

*Ningún componente cumple todos los gates simultáneamente.*

### 🟢 Testeado (4)

| # | Iniciativa |
|---|---|
| 31 | Motor común de comunicaciones (TransportRegistry + 3 adapters, 22 tests) |
| 32 | TransportRegistry (integrado en Communications Engine) |
| 76 | Cotizaciones / Quote Engine (17 tests, hydration pipeline) |
| 101 | Testing unificado de plugins (ESTANDAR_TEST.command + run-suite.sh) |

### 🟡 Implementado (27)

| # | Iniciativa |
|---|---|
| 1 | Estandar Suite v2 — consolidación arquitectónica (5 core + 4 híbridos) |
| 3 | Brain como control plane (Rules, Scoring, SignalLedger, Assessment, Agent) |
| 4 | Capture como motor de captura (Forms, Ingestion, ConversationEngine) |
| 9 | Mantener compatibilidad con Suite v1 (LegacyMap, AdoptionProbe, read-only) |
| 10 | Contratos compartidos v1/v2 (interfaces, hooks cross-plugin) |
| 11 | Absorber/reutilizar antes de reescribir (AdoptionProbe, TransportRegistry discovery) |
| 13 | release.command único (292 líneas, 3 gates, SFTP, reproducible ZIPs) |
| 14 | Local → pruebas → validación → producción (WORKSPACE.md flujo documentado) |
| 16 | Forms propio de Estandar (v1: 26 tests, v2: 35 tests) |
| 17 | Builder/configuración de formularios (v1: form-edit, v2: 4 subtabs) |
| 18 | Shortcodes (`[estandar_form]`, `[estandar_form_v2]`) |
| 19 | Proveedor único de Embed en Capture v2 (Core\Embed) |
| 21 | Entries por formulario (v1: form_entries, v2: Entries subtab) |
| 22 | Migración desde Forms legacy (FormsAdoption, 598 entries preservados) |
| 29 | Campos/contratos reutilizables (FieldMap, Contracts.php) |
| 30 | Formularios como infraestructura común (ConversationEngine, Popups, FormBridge) |
| 33 | SMTP adapter (SmtpTransport con discovery) |
| 34 | Brevo adapter (BrevoTransport con API key discovery) |
| 35 | Reutilizar configuraciones existentes (TransportRegistry auto-discovery) |
| 36 | No sobrescribir API keys/tokens (Crypto AES-256-GCM, Secrets, read-only) |
| 37 | Contratos comunes de correo (TransportInterface, DeliveryLog) |
| 38 | Forms usando motor común (Core\Mail::send() desde Capture) |
| 45 | Registro central de leads (v1: 15 tablas, v2: Pipeline) |
| 46 | Fuente/canal/origen (v1: Ingestion, v2: Channels + Attribution) |
| 47 | Operador asignado (v1: operators, v2: Pipeline) |
| 48 | Estados comerciales (v1: Commercial Journey, v2: StateMachine) |
| 49 | Clasificación HIGH/MEDIUM/LOW (v1: Assessment, v2: Brain Assessment) |
| 50 | HIGH_INTENT (v1: Intent Score, v2: IntentMap + IEE) |
| 54 | Reglas de calidad (v1: Qualification Rules, v2: Brain\Rules) |
| 55 | Scoring (v1: scoring module, v2: Brain\Scoring) |
| 56 | Detección de intención (IEE, 88 tests) |
| 58 | Contactabilidad (v1: Operator Hours, v2: ContactabilityEngine + tests) |
| 59 | Journey del lead (v1: Commercial Journey, v2: Journey\StateMachine) |
| 61 | Outcomes comerciales (v1: Outcomes Log, v2: Journey\Outcomes) |
| 62 | Compatibilidad v1/v2 (AdoptionProbe, LegacyMap, Data-Settings Matrix) |
| 63 | Lead Quality / Intelligence Engine (v1: Qualification + Assessment, v2: IEE + Brain) |
| 77 | Document Engine (Core\Documents\Pdf + Quotes Document Engine) |
| 90 | IA como consumidor del Core (Agent doble perfil, PublicChat, REST) |
| 92 | Forms + Chatbot como fuentes equivalentes (ConversationEngine + FormBridge) |
| 93 | Funnel (v1: analytics funnel, v2: insights funnel) |
| 97 | Configuración federada (Admin helpers compartidos, `estandar_suite_admin_register`) |
| 102 | Release safety / producción (ADR 0032, production-safety-gate, data-settings-matrix) |
| 103 | Compatibilidad de configuración existente (TransportRegistry discovery, LegacyMap) |
| 104 | Documentación canónica (15 docs v2, 11 ADRs Suite, CHANGELOG, WORKSPACE) |

### 🟠 Parcial (26)

| # | Iniciativa |
|---|---|
| 2 | Arquitectura de 5 plugins (solo 3/5 existen como v2) |
| 5 | Catch como capa comercial/conversión (Flow existe pero no se llama Catch) |
| 6 | Experience como efectos/UX (3 plugins v1, sin v2) |
| 7 | Kickoff como utilidades opcionales (existe pero es pesado legacy-style) |
| 8 | Reducir ~21 plugins a estos dominios (27 plugins, sin reducción) |
| 12 | Fuente de verdad única del código (symlinks no existen, dompdf duplicado) |
| 15 | Migración controlada Acacios → AK → otros (solo Acacios parcial) |
| 24 | Notificaciones configurables (Mail + TransportRegistry funcional, UI parcial) |
| 25 | Recipients configurables (config básica, sin multi-recipient condicional) |
| 26 | Editor HTML del correo (template existe, sin WYSIWYG completo) |
| 28 | Autorespuesta (solo v1, sin v2) |
| 40 | Quotes usando motor común (Quotes tiene su propio email, no usa TransportRegistry) |
| 42 | PDF adjunto (Core\Pdf + Quotes PDF separados, sin integración) |
| 44 | Auditabilidad del envío (DeliveryLog 50 entradas, sin dashboard completo) |
| 51 | Lead válido / basura / no contesta (v1 completo, v2 básico) |
| 52 | Importar feedback histórico (AdoptionProbe existe, sin import completo) |
| 60 | Lead → reunión → propuesta → cliente (ambas versiones parciales) |
| 68 | Retorno del feedback hacia plataformas publicitarias (v1 funcional, v2 genérico sin adapters) |
| 69 | Segundo intento y reactivación de leads (ReEngagement + IEE parcial) |
| 71 | Canal de contacto unificado (Core\Channels parcial, no es ContactChannel canónico) |
| 72 | Calendar / reuniones (Core\Calendar con tabla, sin integración Flow) |
| 73 | Surveys Engine (v1 completo, v2 solo ConversationEngine base) |
| 78 | Imports Engine (Adapters GoogleSheets/Excel, sin paste→tasks) |
| 79 | Integración Google Sheets / Excel (adapters existen, sin E2E real) |
| 80 | Attribution Engine (fragmentado v1+v2, sin unificar) |
| 81 | Campaign Resolver (solo v1 primer, sin v2) |
| 82 | Google Ads OCI (triple implementación fragmentada) |
| 83 | Meta Pixel + CAPI (solo v1, sin v2 directo) |
| 84 | Destination Queue (colas separadas v1 + genérica v2, sin unificar) |
| 85 | Analytics / Journey (v1 8 tablas, v2 1 tabla, muy desigual) |
| 86 | Identidad persistente del visitante (ea_sid cookie v1, sin v2) |
| 87 | SEO Foundation Engine (Search Console v1 ≠ SEO Foundation descrito) |
| 91 | Chatbot IA (v1 completo, v2 solo básico) |
| 94 | Autoresponse Engine (solo v1, sin v2 — duplicado #28) |
| 95 | Feedback Loop publicitario (fragmentado v1 signal-core + v2 destinations) |
| 98 | Experience consolidación visual (3 plugins v1, sin v2) |
| 99 | Kickoff consolidación utilidades (plugin legacy pesado ≠ diseño ligero) |
| 100 | Herramientas legacy a absorber/evaluar (18 plugins aún activos) |
| 105 | Motor único de entidades/eventos (Capture→Ingestion parcial, no canónico) |
| 106 | Eliminación progresiva de proveedores (Forms eliminó GF en Acacios, parcial) |
| 107 | Estandar Suite como plataforma (arquitectura v2 existe, 18 legacy siguen) |

### 🔵 Diseñado (10)

| # | Iniciativa |
|---|---|
| 20 | Mostrar/copiar shortcode (UI no inspeccionada visualmente) |
| 39 | Surveys usando motor común |
| 41 | Postventa usando motor común |
| 53 | Feedback humano reutilizable |
| 67 | Aprendizaje desde feedback del operador |
| 70 | Contact Strategy Engine |
| 75 | Postventa |
| 88 | SEO automático basado en Capture |
| 89 | Dragon ↔ Estandar Suite API |
| 96 | Commercial Knowledge / aprendizaje acumulativo |

### ⚪ No existe (3)

| # | Iniciativa |
|---|---|
| 27 | Preview visual del correo |
| 43 | Preview HTML (DUPLICADO de #27) |

---

## Brechas críticas de conocimiento

Lo que NO puede determinarse con evidencia disponible actualmente:

| Brecha | Qué se necesita para determinarlo |
|---|---|
| **Uso real en producción de plugins legacy** | Acceso a Analytics/Logs de instalaciones cliente (akcontadores.cl, acacios.cl) para determinar qué plugins están activos y con datos reales |
| **Estado de la instalación AK Contadores** | Acceso a WordPress admin :8889 para ver plugins activos, datos de leads, formularios, campañas |
| **Estado de la instalación Acacios completa** | Acceso a WordPress admin :8887 para ver qué plugins están activos además de Capture Forms |
| **Credenciales OAuth reales** | No verificables sin credenciales Google/Microsoft. Integration Core está testeado en sandbox (216 tests) pero sin validación real |
| **Envíos de correo reales Brevo/SMTP** | TransportRegistry tiene health checks pero sin envío E2E con credenciales reales en staging |
| **Funcionamiento de Meta CAPI/Google OCI en producción** | Sin acceso a cuentas publicitarias reales no se puede verificar si los eventos se están enviando correctamente |
| **Dependencia real de clients en experience-blocks/pop/kinetichub** | Sin acceso a páginas cliente no se puede determinar si eliminar estos plugins rompería sitios |
| **Uso real de estandar-cpt-manager** | 7,732 líneas sin tests ni documentación de uso. No se sabe si está activo en algún sitio |
| **Si los symlinks deberían existir o se eliminaron deliberadamente** | WORKSPACE.md dice que existen. Filesystem dice que no. Sin registro de decisión de eliminarlos |
| **Si estandar-quotes fue diseñado para ser independiente o debe integrarse** | Quotes tiene su propio Transport y Document Engine. Sin ADR que explique si esto es intencional o deuda técnica |
| **Estado del deployment v1.0.10 (no publicado según CHANGELOG)** | CHANGELOG dice "ready but not published". Sin evidencia de por qué no se publicó |

---

## Diagnóstico final

### 1. ¿Qué porcentaje aproximado de la visión completa de Estandar Suite existe realmente hoy?

**~45-50%** si medimos contra la visión de "5 productos unificados con motores compartidos". El ~62% de cobertura funcional incluye implementaciones v1 que no son parte de la visión v2. Si medimos solo la visión v2 (5 productos + motores transversales), el porcentaje real es menor: Communications Engine (~90%), Capture/Forms (~70%), Brain (~50%), Flow/Catch (~40%), Insights (~25%), Experience (0%), Kickoff unificado (0%).

### 2. ¿Qué porcentaje está suficientemente validado?

**~15-20%.** De ~400+ tests existentes, la mayoría son tests de contrato/unitarios sin validación E2E en entornos reales. Solo Communications Engine (22 tests), IEE (88 tests), y Quotes (17 tests) tienen cobertura razonable. El resto de los 27 plugins tiene cobertura mínima. Además, solo 1 componente tiene validación en producción (Capture Forms en acacios.cl). El sistema carece completamente de staging validation para cualquier otro componente.

### 3. ¿La principal deuda actual es construcción, consolidación, migración, testing o release?

**Consolidación y testing**, en ese orden:
- **Consolidación:** 27 plugins con 3 generaciones arquitectónicas, triple implementación de Google OCI, doble de Attribution, doble de PDF/Document Engine, Communications unificado pero no usado por Quotes, Interest/Quality disperso entre Core y Brain.
- **Testing:** 14/27 plugins tienen 0-1 tests. Los ~400 tests están en solo 6 plugins. Sin E2E staging validation para ningún componente excepto Forms.
- **Release:** La pipeline funciona pero ningún componente pasa los 3 gates. El versionado es inconsistente.
- **Migración:** Es consecuencia de consolidación. No se puede migrar sin antes decidir qué se consolida.

### 4. ¿La arquitectura real converge hacia la visión conceptual o se está desviando?

**Converge parcialmente pero con desviaciones significativas:**

- **Converge:** Communications Engine unificado. Capture como motor único. Brain como capa de inteligencia. Cross-plugin hooks. Adoption probes.
- **Desvía:** 4 plugins híbridos fuera de la arquitectura de 5 productos. Monolito Primer no documentado. Flow ≠ Catch (nombre solamente). Experience completamente ausente en v2. Triple implementación de advertising (Google OCI). Quotes como isla arquitectónica.

La dirección es correcta pero la ejecución está generando un archipiélago, no una plataforma unificada. Cada nuevo motor (IEE, Integration Core) se añade sin absorber/eliminar los anteriores.

### 5. ¿Existe evidencia de duplicación innecesaria entre v1 y v2?

**Sí, en 4 áreas críticas:**

| Área | v1 | v2 | Gravedad |
|---|---|---|---|
| Google Ads OCI | estandar-leads google-adapter + signal-core google-oci-exporter | estandar-destinations google-ads-destination | ALTA. Triple implementación |
| Attribution | estandar-analytics Attribution + primer Campaign Resolver | Capture\Attribution + Core\Channels | ALTA. Doble implementación sin integración |
| Document/PDF Engine | estandar-website-scanner dompdf | Core\Documents\Pdf + Quotes Document Engine (dompdf duplicado) | MEDIA. Triple dependencia de dompdf |
| Lead Quality/Intelligence | estandar-leads Assessment + Qualification | Brain Assessment + Core Quality + IEE | MEDIA. Funcional pero fragmentado |
| Forms | estandar-forms (v1 completo) | estandar-capture Forms (v2) | BAJA. v2 está diseñado para absorber v1, no duplicar |

### 6. ¿Existe riesgo real de perder funcionalidades legacy si continuamos consolidando sin una matriz de equivalencia?

**Sí, riesgo ALTO para 9 capacidades.** La matriz de la sección 4 de este análisis identifica 9 capacidades que DEBEN migrarse (chatbot, analytics, leads, Meta CAPI, Google OCI, surveys, autorespuesta, campaign resolver, lead management). Si se desactivan plugins v1 sin tener equivalente v2 funcional y validado, se perderían:

- Canal de captura conversacional (chatbot + WhatsApp)
- Visibilidad de tráfico y campañas (analytics)
- Sistema comercial central (leads)
- Feedback loop publicitario (Meta/Google)
- Encuestas funcionales (surveys)
- Respuesta automática a leads (autorespuesta)

### 7. ¿Cuáles son los componentes más maduros actualmente?

En orden de madurez:

1. **Communications Engine** (🟢 TESTEADO, ~90% completo) — TransportRegistry + 3 adapters + 22 tests. Unificado. Mejor ejemplo de la visión.
2. **Quote Engine** (🟢 TESTEADO, ~85% completo) — 44 files, 17 tests, hydration pipeline, PDF, profiles. Aislado pero excelente.
3. **Integration Core** (🟡 IMPLEMENTADO, ~75% completo) — 216 tests en sandbox. OAuth, adapters, FieldMap. Listo pero sin validación real.
4. **Capture Forms** (🟡 IMPLEMENTADO, ~70% completo) — Builder 4 subtabs, FormBridge, adopción legacy, 35 tests. Probado en producción parcialmente.
5. **Interest Evolution Engine** (🟢 TESTEADO, ~80% completo) — 88 tests. Detección de señales. Sin integración E2E con notificaciones.

### 8. ¿Cuáles son los mayores vacíos funcionales?

En orden de criticidad:

1. **Experience (v2):** 0% implementado. 3 plugins v1 sin plan de migración. Bloques Gutenberg usados en páginas cliente.
2. **Analytics unificado:** v1 tiene 8 tablas. v2 solo 1 tabla + funnel. Brecha enorme.
3. **Lead management unificado:** v1 15 tablas + 78 tests. v2 6 tablas + 21 tests. Flow es insuficiente solo.
4. **Meta/Google Ads feedback loop:** Fragmentado en 3 implementaciones parciales. Sin unificación.
5. **Chatbot multicanal:** v1 completo (OpenAI + WhatsApp). v2 solo chat bubble básico.
6. **Surveys en v2:** Solo ConversationEngine base. Sin survey analytics, sin tipos de pregunta avanzados.
7. **Postventa:** 0% implementado en cualquier versión.
8. **Contact Strategy Engine:** Solo diseño.
9. **Dragon REST API consolidada:** 37 endpoints dispersos sin capa unificada de API.
10. **Preview de correo:** 0% en v1 y v2.

---

**ANÁLISIS DE AUDITORÍA FINALIZADO — SIN CAMBIOS REALIZADOS**
