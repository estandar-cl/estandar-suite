# ESTANDAR_CLEANUP_V1

STATUS: SPEC_DRAFT · 2026-06-12
SCOPE: Media · Plugin · Theme · Table · Option
EXCLUSIONES VINCULANTES: Scanner permanece READ ONLY · nunca delete directo · todo pasa por cuarentena · multisitio NO requerido · sin código · sin UI detallada.

---

## 0. Principio rector (invariante de diseño)

**V1 solo cuarentena objetos cuyo retiro NO debe cambiar el comportamiento del sitio.** Si poner algo en cuarentena cambia el comportamiento, el finding era falso positivo y RESTORE lo revierte. La cuarentena no es un "delete suave": es un experimento reversible con ventana de observación. Esto define la elegibilidad: solo objetos sin efecto runtime esperado (plugin ya inactivo, theme no activo, tabla de plugin ausente, media sin referencias, option huérfana).

Corolario: **V1 nunca desactiva nada activo.** Desactivar es un cambio de comportamiento, no un cleanup (V2 puede proponer "deactivate first" como flujo separado).

---

## 1. Arquitectura

### 1.1 Decisiones

1. **Cleanup = plugin separado (`estandar-cleanup`)**, no extensión de Scanner. El contrato READ ONLY de Scanner es un activo arquitectónico; mezclar ejecución en él lo destruye. Misma separación que Leads/Conversations: detección = evidencia, cleanup = consecuencia.
2. **Acoplamiento por datos, no por código.** Scanner produce findings (id, dominio, target, evidencia). Cleanup los consume y conserva un snapshot inmutable de la evidencia al momento de proponer. Sin Scanner instalado, Cleanup no propone nada — **sin modo autónomo en V1** (evita duplicar detección en dos plugins).
3. **Core MU opcional** — polyfill V1.1 dinámico obligatorio (canon CORE_OPTIONALITY_V1.1), guards `function_exists`/`class_exists` en toda llamada a símbolos de core, pasa `estandar-preflight.php` antes de cualquier deploy.
4. **Una máquina de estados, cinco handlers.** El ciclo de vida (proponer→aprobar→cuarentenar→restaurar/purgar) es idéntico para los 5 dominios; lo que varía es el mecanismo de cuarentena. Cada dominio implementa un handler con contrato común: `quarantine()`, `restore()`, `purge()`, `verify()`.
5. **Purge nunca automático en V1.** Manual, con doble confirmación, fuera del happy path. Sin cron de purga.

### 1.2 Componentes

| Componente | Responsabilidad |
|---|---|
| **Finding Ingestor** | Lee findings de Scanner, congela snapshot de evidencia, crea propuestas en batch |
| **Policy Engine** | Elegibilidad por dominio + denylist vinculante + cap de batch. Cualquier item que no pasa = excluido con razón registrada |
| **Quarantine Manager** | Orquesta handlers por dominio; idempotente; aborta batch ante primer fallo |
| **Handlers (×5)** | Media / Plugin / Theme / Table / Option — mecanismo nativo más barato y reversible de cada dominio |
| **Action Journal** | Registro append-only de toda transición de estado, con actor y timestamp. Fuente única de verdad del estado |
| **Restore Engine** | Reversa exacta por handler; aborta ante conflicto (nunca sobrescribe) |

### 1.3 Mecanismo de cuarentena por dominio

| Dominio | Mecanismo | Reversa |
|---|---|---|
| Media | `move` archivo a `wp-content/estandar-quarantine/media/{batch}/` preservando ruta relativa; fila attachment se conserva flageada | move back |
| Plugin | `move` directorio (solo inactivos) a `estandar-quarantine/plugins/{batch}/` | move back |
| Theme | `move` directorio (no activo, no padre del activo, no fallback) | move back |
| Table | `RENAME TABLE` a `{prefix}zzz_quarantine_{ts}_{nombre}` — instantáneo, sin mover datos, sin DROP | RENAME inverso |
| Option | snapshot serializado completo (valor + autoload) al journal → `delete_option` | re-crear desde snapshot |

Directorio de cuarentena: **fuera de `uploads/`** (evita re-indexación por media library), protegido contra acceso web (deny + index vacío) — media en cuarentena puede contener PII.

---

## 2. Modelo de datos

### 2.1 `wp_estandar_cleanup_actions` (journal, append-only en transiciones)

| Campo | Notas |
|---|---|
| `action_id` | PK |
| `batch_id` | FK a batches |
| `domain` | media · plugin · theme · table · option |
| `target_key` | identificador estable: attachment_id+path · plugin slug · theme slug · table name · option name |
| `finding_ref` | id del finding de Scanner |
| `evidence_snapshot` | JSON inmutable: la evidencia tal como estaba al proponer |
| `state` | ver máquina de estados |
| `quarantine_ref` | ruta/nombre en cuarentena |
| `size_bytes`, `checksum` | para verificación de restore (media/plugin/theme) |
| `value_snapshot` | solo options: valor serializado + autoload flag |
| `approved_by`, timestamps por transición | auditoría |
| `resolution_note` | razón de restore/reject |

### 2.2 `wp_estandar_cleanup_batches`

`batch_id` · `source_scan_id` · `created_at` · `status` · contadores (proposed/approved/quarantined/restored/purged/rejected).

### 2.3 Política — `estandar_cleanup_option` (wp_option)

Ventana de observación (default 30d) · cap de items por batch (default 25) · flags enable por dominio · denylist patterns.

### 2.4 Máquina de estados

```
PROPOSED → REJECTED                    (operador descarta; terminal)
PROPOSED → APPROVED → QUARANTINED
QUARANTINED → RESTORED                 (terminal; reversa exacta)
QUARANTINED → PURGED                   (terminal; solo manual, doble confirmación,
                                        solo si ventana cumplida)
```

Sin estado "expirado": ventana cumplida = *elegible* para purge, no purga. Un item puede vivir en QUARANTINED indefinidamente — eso es correcto, no un bug.

### 2.5 Denylist vinculante (no configurable hacia abajo)

- `wp_ecb_conversations` y toda tabla de plugins estandar (canon CONVERSATION_DATASET: PROHIBIDO eliminar/truncar/migrar).
- Tablas core de WordPress.
- Options core de WP y options `estandar_*`.
- `estandar-core-mu` y todo plugin del stack estandar; mu-plugins fuera de alcance total.
- Theme activo, su padre, y un theme default de respaldo (mínimo un twenty* se conserva siempre).
- Transients activos: fuera de alcance V1 (dominio propio, V2).

---

## 3. Flujo operativo

```
SCAN (Scanner, read-only)
  → INGEST        findings → snapshot de evidencia
  → PROPOSE       batch con dry-run report: qué, por qué, evidencia, mecanismo, reversa
  ── GATE 1: BACKUP_VERIFIED — backup completo verificado antes de ejecutar cualquier batch
  → APPROVE       manual, item por item o batch completo (Rodrigo). Sin auto-approve.
  → QUARANTINE    ejecución idempotente, journaled, aborta ante primer fallo
  ── GATE 2: POST_ACTION_CHECK — smoke check inmediato (admin carga, frontend carga,
     leads siguen capturando). FAIL = restore automático del batch completo.
  → OBSERVE       ventana 30d. El sitio en producción ES el test.
  → RESOLVE       RESTORE (1 acción, reversa exacta) ó PURGE (manual, doble confirmación)
```

Reglas del flujo: WIP = 1 batch en cuarentena por sitio a la vez (no apilar experimentos). Restore aborta si el destino ya existe (tabla recreada, option re-creada, archivo con mismo nombre) — conflicto = resolución manual, nunca sobrescritura. Mismo principio que preflight: **FAIL falso cuesta 2 minutos; PASS falso cuesta producción.**

### Elegibilidad por dominio (Policy Engine)

| Dominio | Elegible solo si |
|---|---|
| Media | attachment sin referencias detectadas (post_parent, post_content, thumbnail, meta conocida) **+ límite declarado**: referencias en datos serializados de page builders / CSS / consumidores externos no son 100% detectables — la ventana de observación ES la mitigación, no la detección |
| Plugin | inactivo ≥ N días + no estandar + sin dependientes conocidos |
| Theme | no activo + no padre + no fallback reservado |
| Table | prefijo de plugin **ausente** (owner desinstalado) — tabla de plugin instalado nunca es elegible aunque parezca vacía |
| Option | prefijo de plugin ausente + no autoload crítica + no transient |

---

## 4. Riesgos

| # | Riesgo | Dominio | Mitigación |
|---|---|---|---|
| R1 | Falso positivo de media "sin uso" (builders serializados, CSS, hotlinks externos, emails que referencian la URL) | Media | Cuarentena + ventana 30d; declarar el límite de detección en el dry-run; restore 1-acción. **Este es el riesgo central del producto — la cuarentena existe por él** |
| R2 | Plugin reinstalado durante la ventana recrea su tabla vacía → restore en conflicto | Table | Restore aborta ante destino existente; resolución manual; nunca merge automático |
| R3 | Option huérfana mal clasificada (otro plugin la lee por nombre, no por prefijo) | Option | Snapshot completo en journal; restore exacto incluye autoload; denylist core+estandar. **Dominio de mayor riesgo/menor valor de los 5** |
| R4 | Cuarentena de media servible públicamente con PII | Media | Directorio fuera de uploads + deny web access |
| R5 | Batch grande = blast radius grande + carga cognitiva de revisión | Todos | Cap 25 items/batch · WIP=1 · approve item-por-item disponible |
| R6 | Evidencia de Scanner se desactualiza entre scan y approve | Todos | Snapshot inmutable al proponer + re-verificación (`verify()`) justo antes de ejecutar; divergencia = item se cae del batch |
| R7 | Cleanup se desplega sin core / con core como plugin normal | Infra | Polyfill V1.1 + preflight gate (clase de bug ya conocida, Casa Encuentro) |
| R8 | "Purge automático para ahorrar disco" como feature request futura | Proceso | El canon de este doc: purge manual es decisión de diseño, no deuda. Reabrirlo requiere evidencia de costo real de almacenamiento |
| R9 | Scope creep hacia "optimizador" (transients, revisiones, postmeta huérfana, cron) | Proceso | Lista cerrada de 5 dominios en V1; todo lo demás se evalúa contra backlog V2/V3, no se cuela |

---

## 5. MVP V1

**Dentro:**

- Plugin `estandar-cleanup` con polyfill V1.1 + guards.
- 5 handlers (media, plugin, theme, table, option) con el contrato común y los mecanismos de §1.3, **en dos tiers de confianza**: Tier 1 = media/plugin/theme (filesystem, reversa trivial, habilitados default) · Tier 2 = table/option (DB, blast radius mayor, deshabilitados default — se activan por sitio cuando Tier 1 acumule corridas limpias).
- Journal + batches + máquina de estados completa.
- Gates: BACKUP_VERIFIED + POST_ACTION_CHECK con restore automático ante FAIL.
- Restore 1-acción por item y por batch.
- Purge manual con doble confirmación.
- Pantalla mínima: lista de propuestas con evidencia + lista de cuarentena con edad (sin UI detallada, por restricción).

**Fuera (explícito):**

- Modo autónomo sin Scanner · cron de purga · desactivación de plugins/themes activos · transients/revisiones/postmeta · multisitio · scheduled scans · métricas de disco recuperado · wp-cli.

**Recomendación crítica:** si hay que recortar V1, cortar **Option Cleanup** primero (R3: mayor riesgo, menor valor — las options huérfanas pesan KB; el valor real está en media/GB y tablas muertas). Mantengo los 5 porque el contrato de handler es idéntico y Tier 2 deshabilitado default ya contiene el riesgo.

**Secuencia de validación:** SALADEPRUEBAS (corrida completa: propose→quarantine→restore→quarantine→purge, los 5 dominios) → sitio real de bajo riesgo (Guillaume/Estandar) Tier 1 only → ventana 30d → recién entonces Tier 2 y sitios con leads activos. Nunca estrenar en Acacios/AK.

---

## 6. Backlog V2/V3

**V2 (requiere V1 con ≥2 ciclos completos quarantine→purge sin restore forzado):**

- Transient cleanup (dominio propio, expiración nativa como señal).
- Post revisions + postmeta/usermeta huérfana (alto volumen, requiere detección más profunda en Scanner primero — Scanner manda, Cleanup sigue).
- Flujo "deactivate first": proponer desactivación de plugin activo aparentemente sin uso como acción previa a cuarentena (rompe el invariante de V1, por eso es V2 con gate propio).
- Métricas: disco recuperado, filas recuperadas, badge de confianza por dominio (patrón DATA_QUALITY_BADGE).
- Purge asistido: notificación cuando un batch cumple ventana (sigue siendo manual — solo recordatorio, vía canal de escalación existente, sin motor nuevo).
- Detección de referencias en datos serializados (reduce R1) — vive en Scanner, no en Cleanup.

**V3:**

- Rollout flota: política por cliente (templates de denylist/ventana/caps por instalación — principio QUALIFIED_MODEL: configurable por instalación, prohibido `if site == X`).
- wp-cli para corridas headless.
- Integración con telemetría core (eventos QUARANTINE/RESTORE/PURGE observables).
- Retention scheduling (solo si para entonces existe evidencia de que purge manual no escala — ver R8).

**Lista negativa permanente (mismo patrón OPERATIONAL_PIPELINE):** NO "optimizador de base de datos" genérico · NO compresión/conversión de media (otro dominio) · NO cleanup de leads/conversations jamás (canon CONVERSATION_DATASET) · NO delete directo bajo ninguna feature futura — toda propuesta que salte la cuarentena se rechaza antes de especificarse.
