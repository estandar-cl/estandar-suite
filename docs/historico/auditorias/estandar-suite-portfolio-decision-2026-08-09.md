# Estandar Suite — Auditoría de Portafolio y Decisión (PARTES 3, 7, 8, 9)

**Fecha:** 2026-08-09
**Versión auditada:** 13.0.172 (26/26 plugins verificados en headers)
**Base:** Informes previos del 2026-08-07 (`estandar-suite-real-state-audit-2026-08-07.md` y `estandar-suite-second-layer-analysis-2026-08-07.md`)
**Naturaleza:** Completar la auditoría general y estratégica con las PARTES 3, 7, 8 y 9 del encargo.
**Método:** Síntesis de evidencia ya verificada + re-verificación de versión unificada 13.0.172 sobre el filesystem.
**Modo:** READ-ONLY. Sin cambios de código.

---

## PARTE 3 — CLASIFICACIÓN

### 3.1 Taxonomía usada

Cada elemento se etiqueta con una o más de estas categorías:

| Categoría | Definición |
|---|---|
| **CORE** | Necesario para que la arquitectura o las capacidades fundamentales funcionen |
| **PRODUCTO / CAPACIDAD PRINCIPAL** | Valor directo relevante para sitios/clientes |
| **MOTOR TRANSVERSAL** | Infraestructura reutilizable por varios productos/plugins |
| **COMPATIBILIDAD LEGACY** | Existe principalmente para mantener instalaciones anteriores |
| **ACCESORIO** | Útil pero no fundamental para la propuesta central |
| **EXPERIMENTAL / I+D** | En evaluación o sin validación suficiente |
| **HERRAMIENTA INTERNA** | Release, migración, testing, desarrollo, observabilidad |
| **OBSOLETO / CANDIDATO A RETIRO** | Su valor actual no justifica mantenerlo independientemente |

### 3.2 Clasificación por elemento

| Elemento | v1/v2 | Categorías |
|---|---|---|
| **estandar-core** | v2 | CORE + MOTOR TRANSVERSAL |
| **estandar-brain** | v2 | PRODUCTO PRINCIPAL (inteligencia) + MOTOR TRANSVERSAL |
| **estandar-capture** | v2 | PRODUCTO PRINCIPAL (captura/forms) + MOTOR TRANSVERSAL |
| **estandar-flow** | v2 | PRODUCTO PRINCIPAL (comercial) |
| **estandar-insights** | v2 | PRODUCTO PRINCIPAL (analítica) — en desarrollo |
| **estandar-quotes** | v2 | PRODUCTO PRINCIPAL (cotizaciones) |
| **estandar-destinations** | v2 (híbrido) | MOTOR TRANSVERSAL — candidato a CONSOLIDACIÓN |
| **estandar-kickoff** | v2 (híbrido) | ACCESORIO — candidato a CONSOLIDACIÓN/DIVISIÓN |
| **estandar-primer** | Compartido | CORE (runtime compartido) — candidato a ABSORCIÓN |
| **estandar-leads** | v1 | PRODUCTO PRINCIPAL + COMPATIBILIDAD LEGACY |
| **estandar-forms** | v1 | PRODUCTO PRINCIPAL + COMPATIBILIDAD LEGACY |
| **estandar-chatbot** | v1 | PRODUCTO PRINCIPAL + COMPATIBILIDAD LEGACY |
| **estandar-analytics** | v1 | PRODUCTO PRINCIPAL + COMPATIBILIDAD LEGACY |
| **estandar-experience-blocks** | v1 | PRODUCTO PRINCIPAL (UX) + COMPATIBILIDAD LEGACY |
| **estandar-pop** | v1 | ACCESORIO (UX) + COMPATIBILIDAD LEGACY |
| **kinetichub** | v1 | ACCESORIO (UX) + COMPATIBILIDAD LEGACY — origen third-party |
| **estandar-signal-core** | v1 | MOTOR TRANSVERSAL (feedback publicitario) + COMPATIBILIDAD LEGACY |
| **estandar-seo-intelligence** | v1 | PRODUCTO PRINCIPAL (SEO Analytics) + COMPATIBILIDAD LEGACY |
| **estandar-cpt-manager** | v1 | ACCESORIO — OBSOLETO probable |
| **estandar-website-scanner** | v1 | ACCESORIO (herramienta) + COMPATIBILIDAD LEGACY |
| **estandar-secure-share** | v1 | ACCESORIO (cripto) + COMPATIBILIDAD LEGACY |
| **estandar-delivery-locator-lite** | v1 | ACCESORIO — nicho |
| **estandar-duplicate-post** | v1 | OBSOLETO (WP nativo lo cubre) |
| **estandar-firmas** | v1 | ACCESORIO — absorbible en Kickoff o retiro |
| **estandar-notifications** | v1 | OBSOLETO (stub, Core Communications lo supera) |
| **estandar-wp-exporter** | v1 | HERRAMIENTA INTERNA (migración) — absorbible en Kickoff |
| **estandar-acceptance** | v1 | HERRAMIENTA INTERNA (testing) |
| **Communications Engine** | v2 (Core) | MOTOR TRANSVERSAL |
| **Integration Core** | v2 (Core) | MOTOR TRANSVERSAL |
| **Interest Evolution Engine (IEE)** | v2 (Core) | MOTOR TRANSVERSAL |
| **Quality Engine (Lead Quality)** | v2 (Core) | MOTOR TRANSVERSAL |
| **Document Engine** | v2 (Core+Quotes) | MOTOR TRANSVERSAL — en desarrollo |
| **Import/Adapters (Sheets/Excel)** | v2 (Core) | MOTOR TRANSVERSAL — parcial |
| **ConversationEngine** | v2 (Capture) | MOTOR TRANSVERSAL |
| **FormBridge (CF7/GF)** | v2 (Capture) | MOTOR TRANSVERSAL |
| **Attribution Engine** | v1+v2 | MOTOR TRANSVERSAL — fragmentado |
| **Campaign Resolver** | v1 (primer) | MOTOR TRANSVERSAL — sin equivalente v2 |
| **Contact Strategy Engine** | — | EXPERIMENTAL / I+D (solo diseño) |
| **Postventa** | — | EXPERIMENTAL / I+D (solo diseño) |
| **Feedback humano reutilizable** | — | EXPERIMENTAL / I+D (solo diseño) |
| **Aprendizaje desde feedback operador** | — | EXPERIMENTAL / I+D (solo diseño) |
| **Commercial Knowledge acumulativo** | — | EXPERIMENTAL / I+D (solo diseño) |
| **SEO automático basado en Capture** | — | EXPERIMENTAL / I+D (solo diseño) |
| **Dragon ↔ Estandar Suite API** | — | EXPERIMENTAL / I+D (solo diseño) |
| **release.command** | Compartido | HERRAMIENTA INTERNA (release) |
| **ESTANDAR_TEST.command / run-suite.sh** | Compartido | HERRAMIENTA INTERNA (testing) |
| **production-safety-gate.php** | Compartido | HERRAMIENTA INTERNA (seguridad) |
| **Ecosystem/Release manifests** | Compartido | HERRAMIENTA INTERNA (config) |

---

## PARTE 7 — DECISIÓN DE PORTAFOLIO

### 7.1 Decisiones por elemento

| Elemento | Decisión | Justificación |
|---|---|---|
| **estandar-core** | **CONTINUAR** | Núcleo indiscutible. Communications, Integration Core, IEE, Quality son el valor arquitectónico real. |
| **estandar-capture** | **CONTINUAR** | Motor de captura más cercano a la visión. Único con producción verificable (acacios.cl). |
| **estandar-brain** | **CONTINUAR** | Inteligencia central. Pero resolver solape con Core\Interest y Core\Quality (consolidar en Brain o en Core, no ambos). |
| **estandar-flow** | **CONTINUAR** | Equivalente de "Catch". Ampliar cobertura de lead management para alcanzar paridad con estandar-leads. |
| **estandar-insights** | **CONTINUAR** | Pero absorber Analytics v1 (8 tablas) antes de declararse sustituto. Hoy 1 tabla es insuficiente. |
| **estandar-quotes** | **CONTINUAR** | Más completo de la Suite. Integrar con Core Communications y Document Engine para quitar la deuda (dompdf ×2, email propio). |
| **estandar-destinations** | **CONSOLIDAR EN Core\Destinations** | Duplica signal-core y estandar-leads (triple Google OCI). Su lógica debe migrar al motor genérico de Core. |
| **estandar-kickoff** | **CONSOLIDAR/DIVIDIR** | 16 módulos legacy-style no es "Kickoff ligero". Dividir: absorber utilidades en Core o retirar las no usadas. |
| **estandar-primer** | **ABSORBER / CONSOLIDAR** | Monolito 7,551 líneas sin documentar en la arquitectura conceptual. Distribuir: AI gateway y updates → Core; campaign resolver → Brain. |
| **estandar-leads** | **MANTENER POR COMPATIBILIDAD + migrar a Flow** | Capacidad comercial central (15 tablas, 78 tests). NO retirar hasta que Flow alcance paridad funcional. |
| **estandar-forms** | **MANTENER POR COMPATIBILIDAD** | v1 funcional (26 tests) con Surveys que v2 aún no iguala. Mantener mientras Capture no absorba Surveys completos. |
| **estandar-chatbot** | **MANTENER POR COMPATIBILIDAD** | Canal conversacional crítico (OpenAI + WhatsApp). NO retirar; Brain\PublicChat no lo sustituye. |
| **estandar-analytics** | **MANTENER POR COMPATIBILIDAD** | Session tracking + attribution con datos históricos. NO retirar hasta que Insights tenga paridad. |
| **estandar-experience-blocks** | **MANTENER POR COMPATIBILIDAD** | Bloques en producción en sitios cliente. Sin migrar, retirar rompería páginas. |
| **estandar-pop** | **MANTENER POR COMPATIBILIDAD** | Popups usados en sitios. Capture\Popups solo tiene schema. |
| **kinetichub** | **MANTENER POR COMPATIBILIDAD / INVESTIGAR** | 12 bloques React. Origen third-party (v1.0.8). Evaluar si es core o independiente. |
| **estandar-signal-core** | **CONSOLIDAR EN Core\Destinations + leads** | Feedback loop publicitario crítico. Consolidar la triple implementación (leads + signal-core + destinations) en un solo motor. |
| **estandar-seo-intelligence** | **MANTENER / INVESTIGAR** | SEO Analytics (Search Console) ≠ SEO Foundation. Utilidad específica; evaluar prioridad de migración. |
| **estandar-cpt-manager** | **PAUSAR / INVESTIGAR** | 7,732 líneas sin tests ni evidencia de uso real. No tocar hasta saber si algún cliente lo usa. |
| **estandar-website-scanner** | **MANTENER / INVESTIGAR** | 22 engines, valor como herramienta de venta. Pesado. Evaluar si queda como herramienta o se retira. |
| **estandar-secure-share** | **CONSOLIDAR EN Core\Secrets** | Core\Secrets cubre la criptografía. Absorber el flujo de delivery one-time. |
| **estandar-delivery-locator-lite** | **PAUSAR** | Nicho específico, sin evidencia de prioridad. |
| **estandar-duplicate-post** | **ABANDONAR** | WP/Gutenberg ya lo cubre nativamente. Sin valor diferencial. |
| **estandar-firmas** | **ABSORBER EN Kickoff o ABANDONAR** | Funcionalidad menor (generador de firmas). No justifica plugin independiente. |
| **estandar-notifications** | **ABANDONAR** | Stub (6 adapters .gitkeep). Core Communications (3 transports reales) lo supera. |
| **estandar-wp-exporter** | **ABSORBER EN Kickoff** | Utilidad de migración. No justifica plugin independiente. |
| **estandar-acceptance** | **MANTENER** | Framework de testing shell. Útil como herramienta interna. |
| **Communications Engine** | **CONTINUAR** | Mayor éxito arquitectónico. Extender a Quotes y Surveys. |
| **Integration Core** | **CONTINUAR + ACTIVAR** | 216 tests listos. Ponerlo en uso real (OAuth con credenciales reales) antes de declararlo producido. |
| **Interest Evolution Engine** | **CONTINUAR** | 88 tests. Integrar con notificaciones y Flow para aprovecharlo de verdad. |
| **Quality Engine** | **CONTINUAR** | Contactabilidad y Lead Quality son valor central. Consolidar con Brain. |
| **Document Engine** | **CONTINUAR** | Unificar Core\Documents\Pdf + Quotes Document Engine y eliminar dompdf duplicado. |
| **ConversationEngine + FormBridge** | **CONTINUAR** | Base del motor conversacional de captura (ADR 0034). |
| **Attribution Engine** | **CONSOLIDAR** | Unificar v1 (analytics + primer) y v2 (Capture) en un solo motor. |
| **Campaign Resolver** | **CONSOLIDAR EN Brain** | Necesario para attribution. Migrar de primer a Brain. |
| **Contact Strategy / Postventa / Feedback / Knowledge / SEO-auto / Dragon API** | **INVESTIGAR ANTES DE DECIDIR** | Solo diseño sin código. No invertir hasta definir el producto objetivo. |
| **release.command** | **CONTINUAR + CORREGIR** | Pipeline funcional pero con bug de versionado (ver PARTE 9 y hallazgo de release). |

### 7.2 Priorización de consolidación (orden sugerido)

1. **Unificar versionado** (release.command — causa raíz de la inconsistencia 171/172).
2. **Consolidar Google OCI / Meta CAPI** (leads + signal-core + destinations → un solo motor).
3. **Unificar Document/PDF** (Core\Documents + Quotes, eliminar dompdf duplicado).
4. **Unificar Attribution** (v1 analytics + primer + v2 Capture).
5. **Absorber primer** en Core/Brain.
6. **Integrar Quotes** con Communications Engine.
7. **Migrar Surveys v1 → Capture v2** (capacidad probada que v2 no iguala).
8. **Alcanzar paridad de Flow vs leads** y **Insights vs analytics** antes de cualquier retiro.

---

## PARTE 8 — MATRIZ EJECUTIVA

Ordenada de mayor a menor relevancia estratégica. % y madurez basados en la evidencia de los informes del 07-08 (re-verificada la versión a 13.0.172).

| Proyecto | Categoría | v1/v2 | Propósito | Esperado | Impl. real % | Madurez | Uso producción | Importancia | Dependencias | Deuda/Riesgo | Recomendación | Confianza |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| estandar-core | CORE + MOTOR TRANSVERSAL | v2 | Infraestructura compartida (Communications, Integration, IEE, Quality) | Núcleo de plataforma | 80% | beta | Local + acacios (via capture) | Crítica | Ninguna | Core acumula demasiadas responsabilidades | CONTINUAR | Alta |
| estandar-capture | PRODUCTO PRINCIPAL | v2 | Motor de captura universal (Forms, Ingestion, ConversationEngine) | 1 motor de captura | 70% | beta | acacios.cl (Forms) | Crítica | estandar-core | Falta Surveys paridad v1, popups reales | CONTINUAR | Alta |
| estandar-leads | PRODUCTO PRINCIPAL + LEGACY | v1 | Lead management (15 tablas, 78 tests) | Capacidad central | 100% (v1) | producción | Sitios cliente | Crítica | primer, signal-core | NO tiene equivalente v2 completo | MANTENER POR COMPATIBILIDAD (migrar a Flow) | Alta |
| estandar-flow | PRODUCTO PRINCIPAL | v2 | Pipeline comercial (= "Catch") | Capa comercial/conversión | 40% | alpha | No | Alta | estandar-core | Insuficiente vs leads (6 vs 15 tablas) | CONTINUAR | Alta |
| estandar-chatbot | PRODUCTO PRINCIPAL + LEGACY | v1 | Chatbot IA (OpenAI + WhatsApp, 2943 líneas) | Canal conversacional | 100% (v1) | producción | Sitios cliente | Alta | primer, composer | Brain\PublicChat no lo sustituye | MANTENER POR COMPATIBILIDAD | Alta |
| estandar-analytics | PRODUCTO PRINCIPAL + LEGACY | v1 | Analytics (8 tablas, sessions, funnel) | Visibilidad de tráfico | 100% (v1) | producción | Sitios cliente | Alta | primer | Insights (1 tabla) muy por detrás | MANTENER POR COMPATIBILIDAD | Alta |
| estandar-brain | PRODUCTO PRINCIPAL + MOTOR | v2 | Inteligencia (Rules, Scoring, Agent, PublicChat) | Control plane de IA | 50% | alpha | No | Alta | estandar-core | Solape con Core\Interest y Core\Quality | CONTINUAR | Alta |
| estandar-quotes | PRODUCTO PRINCIPAL | v2 | Quote Engine (44 files, 17 tests, hydration) | Producto de cotización | 85% | beta | No | Alta | Ninguna (aislado) | No usa TransportRegistry, dompdf ×2 | CONTINUAR + integrar | Alta |
| estandar-insights | PRODUCTO PRINCIPAL | v2 | Analytics/Observabilidad (1 tabla) | Sustituto de analytics | 25% | alpha | No | Media-Alta | estandar-core | Muy por detrás de v1 (8 tablas) | CONTINUAR | Alta |
| estandar-experience-blocks | PRODUCTO PRINCIPAL + LEGACY | v1 | 13 bloques Gutenberg UX | Experience v2 | 100% (v1) | producción | Sitios cliente | Media | Ninguna | Sin equivalente v2; riesgo si se retira | MANTENER POR COMPATIBILIDAD | Media |
| estandar-primer | CORE (runtime) | Compartido | Monolito transversal (AI gateway, diagnostics, campaign resolver) | No documentado | 100% | producción | Todos los sitios | Alta | Ninguna | Monolito 7,551 líneas sin documentar | ABSORBER / CONSOLIDAR | Media |
| estandar-signal-core | MOTOR TRANSVERSAL + LEGACY | v1 | Feedback loop publicitario (Meta CAPI, Google OCI) | 1 motor publicitario | 100% (v1) | beta | Sitios cliente | Alta | leads, destinations | Triple implementación con leads y destinations | CONSOLIDAR EN UNO | Media |
| estandar-destinations | MOTOR TRANSVERSAL (híbrido) | v2 | Colas de destino / Google Ads | Destination Queue unificada | 30% | alpha | No | Media | Ninguna | Duplica signal-core y leads; 1 test | CONSOLIDAR EN Core\Destinations | Media |
| estandar-forms | PRODUCTO PRINCIPAL + LEGACY | v1 | Forms + Surveys (26 tests, 8 tablas) | Forms propio | 100% (v1) | producción | Sitios cliente | Media | Ninguna | Capture v2 no iguala Surveys | MANTENER POR COMPATIBILIDAD | Alta |
| estandar-kickoff | ACCESORIO (híbrido) | v2 | 16 módulos de utilidades | Utilidades ligeras | 50% | alpha | Local | Baja | Ninguna | 1 test para 16 módulos; legacy-style | CONSOLIDAR/DIVIDIR | Media |
| estandar-seo-intelligence | PRODUCTO PRINCIPAL + LEGACY | v1 | SEO Analytics (Search Console) | SEO Foundation | 60% | beta | Sitios cliente | Media | Ninguna | ≠ SEO Foundation (metadata/schema) | MANTENER / INVESTIGAR | Media |
| estandar-cpt-manager | ACCESORIO | v1 | CPT dinámicos (7,732 líneas) | — | 100% (v1) | alpha | Sin evidencia | Baja | Ninguna | 0 tests, sin uso verificado | PAUSAR / INVESTIGAR | Baja |
| estandar-website-scanner | ACCESORIO | v1 | 22 engines, SEO checker, PDF | Herramienta de venta | 100% (v1) | beta | Sin evidencia | Baja | dompdf | Pesado | MANTENER / INVESTIGAR | Baja |
| estandar-pop | ACCESORIO + LEGACY | v1 | Popups (`[ep_open]`) | Popups | 100% (v1) | producción | Sitios cliente | Baja | Ninguna | Capture\Popups solo schema | MANTENER POR COMPATIBILIDAD | Media |
| kinetichub | ACCESORIO + LEGACY | v1 | 12 bloques React cinéticos | — | 100% (v1) | beta | Sin evidencia | Baja | Ninguna | Third-party v1.0.8 | MANTENER / INVESTIGAR | Baja |
| estandar-secure-share | ACCESORIO + LEGACY | v1 | Compartir secretos (AES-256-GCM) | — | 100% (v1) | beta | Sin evidencia | Baja | Ninguna | Solape con Core\Secrets | CONSOLIDAR EN Core\Secrets | Media |
| estandar-notifications | OBSOLETO | v1 | Notificaciones multi-canal (stub) | Motor de notificaciones | 10% | prototipo | No | Baja | Ninguna | 6 adapters .gitkeep; Core lo supera | ABANDONAR | Alta |
| estandar-duplicate-post | OBSOLETO | v1 | Duplicar posts | — | 100% (v1) | producción | Sitios cliente | Baja | Ninguna | WP nativo lo cubre | ABANDONAR | Alta |
| estandar-firmas | ACCESORIO | v1 | Generador de firmas | — | 100% (v1) | beta | Sin evidencia | Baja | Ninguna | Funcionalidad menor | ABSORBER/ABANDONAR | Media |
| estandar-delivery-locator-lite | ACCESORIO | v1 | Localizador de delivery | — | 100% (v1) | beta | Sin evidencia | Baja | Ninguna | Nicho específico | PAUSAR | Baja |
| estandar-wp-exporter | HERRAMIENTA INTERNA | v1 | Export ZIP | — | 100% (v1) | beta | Sin evidencia | Baja | Ninguna | Absorbible en Kickoff | ABSORBER | Media |
| estandar-acceptance | HERRAMIENTA INTERNA | v1 | Framework de testing shell | — | 80% | beta | Local | Baja | Ninguna | — | MANTENER | Media |
| Communications Engine | MOTOR TRANSVERSAL | v2 (Core) | TransportRegistry + 3 adapters | Motor único de correo | 90% | beta | Local (no E2E real) | Crítica | estandar-core | Falta staging con Brevo/SMTP reales | CONTINUAR | Alta |
| Integration Core | MOTOR TRANSVERSAL | v2 (Core) | OAuth2, Adapters, FieldMap, Sandbox (216 tests) | Motor único de integraciones | 75% | beta | Sandbox | Alta | estandar-core | Sin validación OAuth real | CONTINUAR + ACTIVAR | Media |
| Interest Evolution Engine | MOTOR TRANSVERSAL | v2 (Core) | Detección de señales de intención (88 tests) | Motor de interés | 80% | beta | Local | Alta | estandar-core | Sin integración E2E con notificaciones | CONTINUAR | Alta |
| Quality Engine | MOTOR TRANSVERSAL | v2 (Core) | Contactability + Lead Quality | Motor de calidad | 75% | beta | Local | Alta | estandar-core | Solape con Brain | CONTINUAR | Alta |
| Document Engine | MOTOR TRANSVERSAL | v2 (Core+Quotes) | PDF (Core + Quotes separados) | Motor de documentos | 40% | alpha | No | Media | dompdf | dompdf ×2, sin unificación | CONTINUAR (unificar) | Media |
| Import/Adapters | MOTOR TRANSVERSAL | v2 (Core) | GoogleSheets, ExcelOnline | Imports engine | 50% | alpha | Sandbox | Media | Integration Core | Sin E2E con credenciales reales | CONTINUAR | Media |
| Attribution Engine | MOTOR TRANSVERSAL | v1+v2 | Atribución de tráfico/campañas | Motor único | 50% | alpha | Local | Media | analytics, primer, capture | Fragmentado en 2-3 implementaciones | CONSOLIDAR | Media |
| Campaign Resolver | MOTOR TRANSVERSAL | v1 (primer) | Resolución de campañas publicitarias | — | 100% (v1) | beta | Local | Media | primer, leads | Sin equivalente v2 | CONSOLIDAR EN Brain | Media |
| Contact Strategy / Postventa / Knowledge | EXPERIMENTAL | — | Diseño arquitectónico sin código | — | 0% | prototipo (diseño) | No | Baja | — | Sin implementación | INVESTIGAR ANTES DE DECIDIR | Alta |
| Dragon API | EXPERIMENTAL | — | API consolidada Dragon↔Suite | — | 0% (37 endpoints dispersos) | prototipo (diseño) | No | Media | Todos | Sin capa unificada | INVESTIGAR ANTES DE DECIDIR | Media |
| release.command | HERRAMIENTA INTERNA | Compartido | Pipeline de release (3 gates) | Release confiable | 80% | beta | Producción (releases reales) | Alta | SFTP, ssh | Bug de versionado (constantes internas) | CONTINUAR + CORREGIR | Alta |

---

## PARTE 9 — RECOMENDACIÓN GLOBAL

### 9.1 Respuestas

**1. ¿Cuál es realmente el núcleo de Estandar Suite hoy?**
El núcleo operativo es **estandar-leads (v1) + estandar-chatbot (v1) + estandar-analytics (v1) + estandar-primer (runtime compartido)**: son la columna vertebral con datos y producción real. El núcleo arquitectónico de futuro es **estandar-core + estandar-capture** (Communications Engine, Integration Core, IEE como motores). El núcleo NO son los "5 productos conceptuales" sino este doble núcleo: productivo (v1) y arquitectónico (v2).

**2. ¿Qué debería constituir el producto objetivo?**
Una plataforma con un **Core (estandar-core)** como infraestructura compartida y **4 productos**: Capture (captura), Brain (inteligencia), Flow/Catch (comercial), Insights (analítica), más **Quote Engine** como producto independiente de cotización. Experience queda como capa UX absorbida en el Core/Capture o como plugin v2 propio cuando se priorice.

**3. ¿Qué componentes debemos terminar primero?**
En orden: (a) **unificar el versionado** (release.command — bloquea todo), (b) **paridad de Flow vs leads** y **Insights vs analytics** (las dos brechas que impiden retirar v1), (c) **Surveys v1 → Capture v2**, (d) **consolidar advertising** (Google OCI/Meta CAPI en un motor), (e) **integrar Quotes** con Communications y Document Engine.

**4. ¿Cuáles conviene congelar?**
Congelar: **estandar-cpt-manager**, **estandar-delivery-locator-lite**, **estandar-website-scanner** (hasta tener evidencia de uso), y los experimentos de solo diseño (Contact Strategy, Postventa, Commercial Knowledge, SEO auto, Feedback reutilizable). No invertir más en su código.

**5. ¿Cuáles deberían desaparecer como proyectos independientes y ser absorbidos?**
**estandar-primer** (→ Core/Brain), **estandar-destinations** (→ Core\Destinations), **estandar-secure-share** (→ Core\Secrets), **estandar-firmas** y **estandar-wp-exporter** (→ Kickoff), **estandar-signal-core** (→ motor publicitario unificado), **estandar-notifications** y **estandar-duplicate-post** (→ retiro, no absorción).

**6. ¿Cuáles deberíamos abandonar?**
**estandar-notifications** (stub superado por Core Communications) y **estandar-duplicate-post** (WP nativo). Con evidencia de retiro, **estandar-firmas**.

**7. ¿Qué componentes faltan para considerar v2 sustituto funcional de Legacy?**
Faltan: **chatbot completo** (WhatsApp + frontend JS), **analytics completo** (8 tablas de v1), **lead management completo** (Flow alcanzando 15 tablas), **Surveys completos**, **Meta CAPI + Google OCI unificados**, **autorespuesta**, **Campaign Resolver**, y **Experience v2** (bloques). Hasta que estos 8 no tengan equivalente v2 funcional y validado, v2 NO es sustituto.

**8. ¿Qué mantiene todavía justificada la existencia de Legacy?**
Tres cosas: (a) **datos reales** en 30+ tablas (leads, analytics, forms), (b) **capacidades sin paridad v2** (chatbot, analytics, surveys, ads, autorespuesta, experience), (c) **producción activa** en sitios cliente. Mientras exista cualquiera de las tres, Legacy debe seguir.

**9. ¿Cuándo sería técnicamente razonable dejar Legacy sólo en mantenimiento?**
Cuando **todos** estos estén en v2 y validados: leads→Flow, analytics→Insights, chatbot→Brain, surveys→Capture, ads→motor unificado, autorespuesta, campaign resolver, experience. En ese momento Legacy pasa a mantenimiento (bugfixes, sin features nuevas).

**10. ¿Cuándo sería razonable retirarlo definitivamente?**
Cuando exista **evidencia de producción** de que los sitios cliente migraron y operan con v2 durante al menos un ciclo completo (datos históricos migrados, leads/forms activos en v2, feedback publicitario funcionando), y los datos legacy estén respaldados y migrados. Estimar: tras 6-12 meses de operación v2 sin dependencias de v1.

### 9.2 Portafolio objetivo simplificado

**MANTENER Y DESARROLLAR**
estandar-core, estandar-capture, estandar-brain, estandar-flow, estandar-insights, estandar-quotes, Communications Engine, Integration Core, Interest Evolution Engine, Quality Engine, ConversationEngine/FormBridge, Document Engine, Import/Adapters.

**MANTENER / COMPATIBILIDAD** (hasta paridad)
estandar-leads, estandar-forms, estandar-chatbot, estandar-analytics, estandar-experience-blocks, estandar-pop, estandar-signal-core (hasta consolidar), estandar-seo-intelligence, estandar-website-scanner, kinetichub, estandar-acceptance.

**CONSOLIDAR** (absorber en Core/Brain)
estandar-primer, estandar-destinations, estandar-secure-share, estandar-wp-exporter, estandar-firmas, Attribution Engine, Campaign Resolver.

**PAUSAR**
estandar-cpt-manager, estandar-delivery-locator-lite.

**ABANDONAR**
estandar-notifications, estandar-duplicate-post, estandar-firmas (tras consolidar decisión).

**PENDIENTE DE EVIDENCIA**
Contact Strategy Engine, Postventa, Commercial Knowledge, Feedback humano reutilizable, Aprendizaje desde feedback, SEO automático, Dragon API, uso real de cpt-manager/scanner/delivery en clientes, staging OAuth Brevo/SMTP reales.

---

## CHECKPOINT FINAL

**Verificación de cobertura:** todos los elementos hallados en los informes previos, el release.command (26 plugins), los manifests y la documentación fueron clasificados (PARTE 3), decididos (PARTE 7) y tabulados (PARTE 8).

**Contradicción registrada:** El release.command declara "versión unificada 26/26" (lo cual es cierto para el header `Version:`), pero las **constantes internas** de los plugins (p.ej. `ESTANDAR_VERSION`, `1.0.11`, `13.0.158`) quedan desincronizadas tras cada bump. La evidencia de código es la fuente principal: los headers están en 13.0.172 y las constantes internas en valores antiguos.

**Cambios realizados:** NINGUNO en código. Solo documentación.

**AUDITORÍA COMPLETADA — READ-ONLY**
