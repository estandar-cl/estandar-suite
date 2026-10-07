# OPERATOR_SLA_OPEN_EVIDENCE_V1

## Propósito

Separar la antigüedad del lead (`lead_age`, desde captura) del SLA de respuesta del operador (`operator_response_sla`, desde entrega efectiva de la notificación). Implementar autorespuesta diferenciada fuera de horario laboral y clasificar la evidencia de apertura de correos (técnica/automática vs humana) sin depender de servicios externos.

## 1. Lead Age vs Operator SLA

### Lead Age

Métrica legacy, sin cambios. Mide las horas transcurridas desde `es_leads.created_at` (captura original). Expuesta como `Lead Age` en el Commercial Snapshot del AI Context Export y en la sección Compliance > SLA.

**Archivo:** `includes/class-estandar-leads-ai-context.php`
**Método:** `build_compliance_sla()` — campo `Lead age: Xh`
**Snapshot:** `build_commercial_snapshot()` — campo `Lead Age`

### Operator Response SLA

Nueva métrica independiente. Mide las horas transcurridas desde el primer evento `operator_notified` (entrega efectiva al operador). Cuando la notificación es diferida por horario laboral, el SLA no comienza hasta que el correo es realmente entregado.

**Archivo:** `includes/class-estandar-leads-ai-context.php`
**Método:** `operator_response_sla_metrics($lead_id, $captured_at)`

**Cálculo (read-only sobre eventos existentes):**
1. Busca el primer `operator_notification_deferred` → `deferral_reason`, `queued_at`
2. Busca el primer `operator_notified` → `started_at` (inicio del SLA)
3. Busca el primer contacto en `es_lead_contacts` → `contacted_at`
4. Calcula gaps: `queued_hours`, `sla_elapsed_hours`, `sla_remaining_hours`

**Estados posibles:**
- `pending_delivery` — sin notificación ni diferimiento
- `awaiting_delivery (deferred: X)` — diferido, no entregado aún
- `active · Xh remaining` — SLA corriendo
- `due_now` — SLA vencido (48h+ desde entrega)
- `contact_registered` — contacto comercial registrado

### Trazabilidad completa (sin reescribir históricos)

| Hito | Evento | Tabla |
|------|--------|-------|
| Captura | `lead_created` | `es_leads.created_at` |
| Encolado | `operator_notification_deferred` | `es_lead_events` |
| Motivo de diferimiento | metadata.reason | `es_lead_events.metadata` |
| Entrega efectiva | `operator_notification_delivered` | `es_lead_events` |
| Inicio SLA humano | `operator_notified` | `es_lead_events` |
| Contacto registrado | — | `es_lead_contacts.contacted_at` |
| Vencimiento | calculado: started_at + 48h | — |

### Exposición

- **AI Context Export (technical view):** Commercial Snapshot (`Lead Age`, `Operator SLA`), Compliance > SLA (`Operator SLA status`, `Operator SLA started at`, `Operator SLA elapsed`, `Operator SLA remaining`), Operator SLA Timeline (v1) en Diagnostics.
- **No se agrega al correo operativo:** `Estandar_Leads_Email::render()` no recibe vars de SLA. Verificado en código (`notifier.php:417-457`).

## 2. Autorespuesta fuera de horario

### Comportamiento

Cuando un lead ingresa fuera del horario laboral del operador:
1. La autorespuesta al prospecto se envía **inmediatamente** (sin cambios en timing).
2. El contenido es idéntico al de la autorespuesta normal, excepto por una línea adicional.
3. La línea se inserta vía placeholder `{{horario_mensaje}}` en el template.
4. La notificación al operador continúa **diferida** según su horario (sin cambios).

**Archivo:** `includes/class-estandar-leads-autoresponse.php`
**Método:** `maybe_send()` — líneas 168-179

### Texto de la línea adicional

El valor por defecto es:
> Recibimos correctamente tu solicitud. Un ejecutivo se pondrá en contacto contigo lo antes posible, dentro de nuestro horario laboral.

Personalizable mediante filtro de WordPress:

```php
apply_filters(
    'estandar_leads_autoresponse_out_of_hours_line',
    __( 'Recibimos correctamente tu solicitud...', 'estandar-leads' ),
    $project_slug,
    $operator
);
```

**Traducción:** el texto usa `__()` con textdomain `estandar-leads`. Los archivos `.po/.mo` estándar lo traducen automáticamente.

### Detección de fuera de horario

Usa `Estandar_Leads_Operator_Hours::is_open($operator)` cuando hay operador asignado. Sin operador, usa `Estandar_Operational_Hours::is_open()` global.

### Registro de variante

El evento `autoresponse_email_sent` incluye en metadata:
```json
{
  "autoresponse_variant": "out_of_hours",  // o "in_hours"
  "out_of_hours": true
}
```

## 3. Clasificación de evidencia de apertura (OPEN_EVIDENCE_V1)

### Arquitectura

Cada apertura de correo genera **dos eventos** en `es_lead_events`:
1. `autoresponse_email_open_signal` — evento bruto, backward compatible, intacto.
2. `autoresponse_open_classified` — clasificación derivada con metadata adicional.

**Archivo:** `includes/class-estandar-leads-autoresponse.php`
**Métodos:** `record_open_signal()`, `classify_open_evidence()`, `upgrade_open_classification()`

### Niveles de clasificación

| Clasificación | Condiciones | Score Eligible | Evidencia |
|--------------|-------------|----------------|-----------|
| `technical_fetch` | < 5s desde envío | **NO** | `open_within_Xs_of_send` |
| `probable_machine_open` | UA de scanner conocido, o apertura aislada ambigua | **NO** | `known_scanner_ua`, `isolated_immediate_open` |
| `probable_human_open` | > 30s desde envío, múltiples aperturas, IPs distintas | **SÍ** | `open_after_Xs`, `multiple_opens`, `multiple_open_ips` |
| `confirmed_human_engagement` | Apertura + clic posterior, o clic previo | **SÍ** | `confirmed_by_click`, `prior_link_click` |

### Evidencia utilizada (sin OCR, sin servicios externos)

1. **Tiempo desde envío:** diferencia entre `autoresponse_email_sent.created_at` y el timestamp actual.
2. **User-Agent:** patrones conocidos de Apple Mail Privacy, Gmail proxy, Outlook, Thunderbird, antivirus, Barracuda, Mimecast, Proofpoint, Symantec, TrendMicro, etc.
3. **Múltiples aperturas:** conteo de `autoresponse_email_open_signal` en 7 días.
4. **IPs distintas:** direcciones IP diferentes entre aperturas (sugiere dispositivo real).
5. **Clic posterior:** `autoresponse_link_click` o `autoresponse_engaged` en la misma submission.
6. **Correlación temporal:** combinación de los factores anteriores.

### Reclasificación (upgrade)

Cuando ocurre un clic después de una apertura clasificada como ambigua, se dispara `upgrade_open_classification()` que inserta un nuevo `autoresponse_open_classified` con `confirmed_human_engagement` y `score_eligible = true`.

**Archivo:** `includes/class-estandar-leads-autoresponse.php`
**Método:** `upgrade_open_classification()` — invocado desde `record_link_click()` línea 378.

### Conservación del evento bruto

El evento `autoresponse_email_open_signal` original **nunca se modifica**. La clasificación es un evento nuevo, aditivo. Los exports anteriores que lean `autoresponse_email_open_signal` siguen funcionando sin cambios.

## 4. Impacto en Scoring

### Behavior Weights actualizado

**Archivo:** `includes/class-estandar-leads-scoring.php`
**Método:** `behavior_weights()` — línea 91

| Evento | Peso | Half-life | Cap |
|--------|------|-----------|-----|
| `autoresponse_email_open_signal` | 3 | 3 días | 6 |
| `autoresponse_open_classified` | 6 | 3 días | 12 |

### Regla de score_eligible

- `read_behavior_events()` (scoring.php:630) filtra por los tipos en `behavior_weights()`.
- Para `autoresponse_open_classified`, el metadata incluye `score_eligible`.
- `technical_fetch` y `probable_machine_open` tienen `score_eligible = false` → **no contribuyen al score.**
- `probable_human_open` y `confirmed_human_engagement` tienen `score_eligible = true` → **sí contribuyen.**

### Backward compatibility

- `autoresponse_email_open_signal` mantiene su peso (w=3, cap=6) sin cambios. Leads sin eventos clasificados se comportan exactamente igual que antes.
- `autoresponse_open_classified` es aditivo: cuando existe, suma peso adicional (w=6) solo si `score_eligible = true`.

## 5. Eventos nuevos

| Evento | Significado | Cuándo se emite |
|--------|------------|-----------------|
| `autoresponse_open_classified` | Clasificación derivada de apertura | Inmediatamente después de cada `autoresponse_email_open_signal` |
| `operator_notification_delivered` | Notificación diferida finalmente entregada | Cuando `send_deferred_notification()` envía el correo que estaba en cola |

## 6. Exposición en AI Context Export

### Secciones afectadas

1. **Commercial Snapshot (§2):** `Lead Age` y `Operator SLA`
2. **Compliance > SLA (§3):** `Operator SLA status`, `Operator SLA started at`, `Operator SLA elapsed`, `Operator SLA remaining`
3. **Behavior Evidence (v1) (§9):** `Open evidence classification` con tally por nivel
4. **Operator SLA Timeline (v1) (§9):** Traza completa: captura → encolado → diferimiento → entrega → SLA → contacto → vencimiento
5. **Runtime Metrics (§10):** `Operator SLA status` y `Notification queued`

## 7. Pruebas

**Archivo:** `tests/run-operator-sla-open-evidence.php`
**Archivo:** `tests/run-audit-function.php` (auditoría funcional completa)

Escenarios cubiertos:
1. Lead fuera de horario → Lead Age ≠ Operator SLA
2. Autorespuesta especial inmediata (variante `out_of_hours`)
3. Notificación diferida
4. SLA humano iniciado al entregar al operador
5. Apertura a <5s sin clic = technical_fetch, score_eligible = false
6. Apertura seguida de clic = confirmed_human_engagement, score_eligible = true
7. Idempotencia y no duplicación

## 8. Riesgos y limitaciones

- **Sin OCR ni servicios externos.** La clasificación de aperturas usa solo heurísticas basadas en timing, UA e IP. No puede distinguir con certeza un humano de un bot sofisticado. Los niveles `probable_*` reflejan esta incertidumbre.
- **El evento bruto nunca se modifica.** Si una heurística futura es mejor, los eventos históricos mantienen su clasificación original; la nueva heurística solo aplica a eventos nuevos.
- **Sin nuevas columnas en BD.** Todo el estado se deriva de `es_lead_events`. Si se pierden eventos (purga), el SLA se degrada a `pending_delivery`.
