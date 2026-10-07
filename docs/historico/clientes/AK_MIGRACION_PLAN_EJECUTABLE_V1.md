# AK CONTADORES — PLAN DE MIGRACIÓN EJECUTABLE V1
**Base:** `AK_MIGRACION_MEDICION_CODE_AUDIT_V1` (estado de referencia). · **No implementar · No producción · No publicar GTM · No tocar Ads.**

## 0. Validación de contradicciones internas (tarea 1)
Revisada la auditoría: **sin contradicción dura.** Dos decisiones abiertas (no contradicciones):
- **D-A:** conversión online = **nueva** (baseline limpio) **vs.** reutilizar histórica `sw1ACM2v…` (continuidad, datos sucios). Recomendado: **nueva**.
- **D-B:** archivar tags heredados (metform / PV-Gracias / CE-renta) **solo después** de validar el reemplazo en producción (no antes → evita ventana sin medición).
Consistencias confirmadas: online **no** necesita gclid en dataLayer (Conversion Linker); OCI lead-level es correcto para AK monoproyecto; **cero cambios de código Estandar**.

---

## 1. BACKLOG PRIORIZADO (tareas 2–4)
Tipo: **A)** Configuración · **B)** Código · **C)** Operación. Responsables: GTM-admin (gestion@), Ads-mgr (Rodrigo/Claude), Dev-Estandar, Operación (Juan Ignacio), Rodrigo (aprobaciones/credenciales).

### GTM
| ID | Tarea | Tipo | Prio | Depende | Riesgo | Dur | Resp |
|---|---|---|---|---|---|---|---|
| G1 | DLV `form_id` (y opcional `project_slug`) | A | P1 | — | Bajo | 10m | GTM-admin |
| G2 | Repuntar trigger a Custom Event `estandar_form_success` + condición `form_id == <cotizacion>` | A | P1 | G1, W2 | Bajo | 15m | GTM-admin |
| G3 | Tag online `Google Ads - Lead Estandar Forms` (conv nueva) sobre G2 | A | P1 | G2, A1 | Bajo | 15m | GTM-admin |
| G4 | Archivar (no borrar) tags+triggers heredados | A | P2 | G3 validado en prod (D-B) | Medio | 10m | GTM-admin |
| G5 | (opcional) Enhanced Conversions online | A/B | P3 | F-opt | Bajo | 30m | GTM-admin |

### Google Ads (requiere levantar bloqueo Ads)
| ID | Tarea | Tipo | Prio | Depende | Riesgo | Dur | Resp |
|---|---|---|---|---|---|---|---|
| A1 | Crear conversión ONLINE "Lead Estandar Forms" (label nuevo) | A | P1 | D-A | Bajo | 15m | Ads-mgr |
| A2 | Crear conversión OCI "Lead Calificado (Estandar Leads)" (Importar>CRM, CLP150k, 90d) | A | P1-OCI | — | Bajo | 15m | Ads-mgr |
| A3 | Pausar/eliminar Compra Wena/Piola/Crack (page-load) | A | P2 | A1 live | Bajo | 10m | Ads-mgr |
| A4 | Reemplazar/pausar Renta CF7 page-load | A | P2 | — | Bajo | 10m | Ads-mgr |
| A5 | Limpiar jerarquía primaria/secundaria | A | P2 | A1/A2 | Bajo | 15m | Ads-mgr |

### WordPress
| ID | Tarea | Tipo | Prio | Depende | Riesgo | Dur | Resp |
|---|---|---|---|---|---|---|---|
| W1 | Confirmar MetForm/WPForms OFF + /gracias-renta-empresas despublicada (✔ ya hecho) | A/C | P1 | — | Bajo | — | Rodrigo |
| W2 | Confirmar `form_id` real del form de cotización (Estandar Forms) | A | P1 | — | Bajo | 10m | Dev-Estandar |

### Estandar Forms (Código)
| ID | Tarea | Tipo | Prio | Depende | Riesgo | Dur | Resp |
|---|---|---|---|---|---|---|---|
| F0 | **NINGUNO requerido** (infra completa; evento `estandar_form_success` ya emite payload suficiente) | — | — | — | — | — | — |
| F-opt | (opcional) agregar `lead_uuid`/email/phone al `eventPayload` para EC client-side puro | B | P3 | decisión | Bajo (local, reversible) | 30m | Dev-Estandar |

### Estandar Leads (Código)
| L0 | **NINGUNO** (persiste lead_uuid/gclid/session_id/submission_id/eventos) | — | — | — | — | — | — |
| L1 | (diagnóstico) medir ratio `gclid_capture_method` en datos reales | C | P2 | leads reales | Bajo | 30m | Dev-Estandar |

### Estandar Analytics (Código)
| AN0 | **NINGUNO bloqueante** | — | — | — | — | — | — |
| AN1 | Gap D1 (update de sesión) = ANALYTICS_NORMALIZATION (canon), **fuera de ruta crítica** | B | P3 | — | Bajo | — | Dev-Estandar |

### OCI (Config/Operación)
| ID | Tarea | Tipo | Prio | Depende | Riesgo | Dur | Resp |
|---|---|---|---|---|---|---|---|
| O1 | Cargar 6 credenciales `estandar_leads_gads_*` (incl. conversion_action_id de A2) | A | P1-OCI | A2 + Dev Token + OAuth | Medio (OAuth) | 1–2h | Rodrigo |
| O2 | `test_mode=1` → calificar lead prueba → verificar `es_oci_queue` + log | C | P1-OCI | O1 | Bajo | 30m | Dev/Operación |
| O3 | `test_mode=0` (producción) — sujeto a gate | A | P2-OCI | O2 + gate | Medio | 5m | Rodrigo |

### Operación comercial
| ID | Tarea | Tipo | Prio | Depende | Riesgo | Dur | Resp |
|---|---|---|---|---|---|---|---|
| OP1 | **Generar estados QUALIFIED**: operador clasifica leads (feedback_link) → `estandar_lead_status_changed` → OCI | C | **P1 estratégico** | leads + feedback activo | **Alto (adopción)** | Continuo | Juan Ignacio |
| OP2 | Definir `qualified_event` AK (PROPOSAL_SENT ↔ MEETING_SCHEDULED, sin congelar) | C | P2 | datos 30–60d | Bajo | decisión | Rodrigo |

---

## 2. CAMINO MÍNIMO (tarea 5)
Dos ramas paralelas; ninguna requiere código Estandar.

**Rama ONLINE (rápida, solo config) — quita page-load/heredado:**
`W2 → G1 → A1 → G3 → G2 → QA E2E (Preview) → [aprobación] publicar GTM → A3/A4 + G4 (archivar)`

**Rama OCI (calidad) — cuello = clasificación:**
`A2 → O1 → O2 → OP1 (clasificación real) → [gate] → O3`

**Convergencia a "Forms→Leads→Analytics→OCI→Ads sin heredado":** ambas ramas completas + heredados archivados.
**Cuello real:** OP1 (que los operadores clasifiquen). Lo técnico se resuelve en horas; lo operacional define el calendario.

---

## 3. CHECKLIST DE PUBLICACIÓN (tarea 6)
**GTM listo:** ☐ DLV form_id creada ☐ trigger = `estandar_form_success`+form_id ☐ tag online apunta a conv nueva ☐ Preview: dispara 1×/form correcto y NO en otros forms ☐ heredados pausados (archivar post-validación).
**Ads listo:** ☐ conv "Lead Estandar Forms" creada ☐ conv "Lead Calificado" creada ☐ Compra Wena/Piola/Crack pausadas ☐ Renta CF7 page-load resuelta.
**OCI listo:** ☐ 6 credenciales cargadas (`is_configured()`=true) ☐ test_mode validó encolado ☐ gate evaluado (classification_rate>0 + estados profundos).
**E2E listo:** ☐ 1 envío real = 1 fila `es_leads` (gclid presente) = 1 evento Analytics = 1 conv online ☐ 1 QUALIFIED+gclid = 1 OCI (log OCI_UPLOAD_SUCCESS) ☐ conciliación 1–2 sem contra `es_leads` (no correo).
🚩 **No publicar GTM** hasta marcar todos los "GTM listo" + "E2E".

---

## 4. ORDEN DE EJECUCIÓN
1. W2 (form_id) → G1/G2 (GTM, sin publicar).
2. [Levantar bloqueo Ads] A1 → G3 → QA Preview.
3. **Publicar GTM** (tras checklist) → A3/A4 → G4.
4. En paralelo OCI: A2 → O1 → O2.
5. OP1 (clasificación) — continuo, es el habilitador de OCI.
6. Gate OCI → O3.
7. Solo entonces: Temuco (§6).

---

## 5. CRITERIO DE ÉXITO / ROLLBACK (por rama)
**ONLINE — éxito:** conv "Lead Estandar Forms" cuenta ≈ envíos reales del form de cotización (no todos los forms, no page-load); conciliado con `es_leads`. **Rollback:** GTM Versions → re-publicar v8; Ads → pausar conv nueva. Aditivo, sin pérdida (es_leads intacto).
**OCI — éxito:** lead QUALIFIED con gclid → fila en `es_oci_queue` → `OCI_UPLOAD_SUCCESS` → visible en Ads "Lead Calificado". **Rollback:** `test_mode=1` o vaciar credenciales (`is_configured()`=false → no-op); cola persiste sin enviar. Sin efecto en leads.
**Global rollback:** ningún cambio toca `es_leads`/captura (canon: nunca migración destructiva). Todo es config reversible (GTM versions, Ads pause, OCI toggle).

---

## 6. ACTIVACIÓN TEMUCO (fase posterior — tarea 7)
**Gate de entrada (todos):** (1) conversión online limpia y conciliada; (2) OCI operativo (O3) o al menos decidido por gate; (3) clasificación QUALIFIED funcionando (OP1 con datos).
**Plan (cuando se levante bloqueo Ads):** Search pura · Temuco + Padre Las Casas · "Presencia" · frase/exacta · negativas (empleo/sii/banco/itaú…) · arranque **Maximizar conversiones sin tCPA** (cold-start) · medición = "Lead Estandar Forms" filtrada por `project_slug`/landing Temuco + OCI "Lead Calificado" · excluir Temuco de WhatsappMAX (anti-canibalización) · presupuesto CLP10.000/día.
**Éxito Temuco:** CPA/lead conciliado vs `es_leads`; tasa de calificación Temuco ≥ promedio. **Rollback:** pausar campaña (aislada, no afecta WhatsappMAX ni medición).

---
### Resumen ejecutivo
- **Código Estandar a cambiar: 0** (infra completa). 
- **Ruta crítica online = config GTM+Ads (~1–2h de trabajo).**
- **Ruta crítica OCI = credenciales (~1–2h) + clasificación (operacional, continuo).**
- **El calendario lo define OP1 (adopción de clasificación), no la tecnología.**
