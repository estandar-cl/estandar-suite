# Event Catalog

Estado: **catálogo v1 congelado para el cierre de Fase 1**.

## Estabilidad del contrato público

Los hooks internos de WordPress, los adapters, el transporte y la cola pueden cambiar sin previo aviso. **Solo el contrato público del evento es estable**, identificado exclusivamente por `schema_version` en el payload.

| Capa | Puede cambiar | Versión estable |
|------|--------------|----------------|
| Hooks internos (`do_action`) | ✅ Sí | No |
| Adapters (transporte) | ✅ Sí | No |
| Cola (`es_signal_queue`) | ✅ Sí | No |
| **Contrato público del evento** | ❌ No dentro del mismo major | `schema_version` |

## Namespace canónico de eventos

Los eventos públicos usan **dot-notation** con dominio, entidad y acción en pasado:

```
{dominio}.{entidad}.{accion_pasado}
```

Ejemplos:
- `lead.created` — lead creado
- `lead.status.changed` — estado del lead cambió
- `submission.created` — nueva submission
- `quote.issued` — cotización emitida
- `survey.completed` — encuesta completada
- `destination.delivery.failed` — entrega fallida

**Los nombres de hooks internos de WordPress** (como `estandar_lead_ingested`) **NO forman parte del contrato público.** Un consumidor externo nunca debe depender del nombre de un hook interno. El contrato público se expresa exclusivamente en el `schema_version` + `event` del payload JSON.

**Regla de evolución:**
- Nuevos eventos se agregan al catálogo sin modificar los existentes
- El namespace `{dominio}.{entidad}.{accion}` se establece en el primer release del evento y no cambia
- Si un evento queda obsoleto, se documenta como `DEPRECATED` pero nunca se reutiliza su nombre para otro propósito

## Semántica de los identificadores

### `event_id`

Identifica una **publicación única** del evento.

- Se genera en el origen (Leads, Signal Core, etc.)
- **Nunca cambia** para la misma ocurrencia del hecho
- Es determinístico: mismo hecho produce mismo `event_id` en caso de reintento
- El consumidor debe usarlo para **idempotencia**: si ya procesó este `event_id`, ignora el evento duplicado
- Formato: `ev_` + 20 caracteres alfanuméricos (`ev_2xK9mR4nP7qA3cV8wE5bH1jF`)
- Se genera como: `sha256(producer + "|" + event + "|" + entity_id + "|" + occurred_at)[:20]`

### `correlation_id`

Identifica un **flujo completo** de principio a fin.

- Se genera en el punto de captura inicial (Forms, Chatbot, API)
- **Permanece estable** a través de todos los eventos relacionados (captura → clasificación → journey → destino)
- Todos los eventos de un mismo flujo comparten el mismo `correlation_id`
- El consumidor puede usarlo para reconstruir la historia completa de una interacción
- Formato: `c_` + 32 caracteres hexadecimales (`c_8f7a3b2c1d9e4f5a6b7c8d9e0f1a2b3c`)

### `entity_id`

Identifica la **entidad de negocio** afectada.

- Es el ID de la entidad en el sistema de origen (lead_id, quote_id, survey_response_id, etc.)
- Es **independiente** del `event_id` — una misma entidad puede tener múltiples eventos (created, updated, deleted)
- No tiene formato fijo — depende del dominio
- El consumidor puede usarlo para agregar eventos por entidad

### Reglas de uso

| Identificador | Nuevo evento | Mismo flujo | Misma entidad |
|--------------|-------------|-------------|---------------|
| `event_id` | ✅ Siempre nuevo | ❌ Nunca se reutiliza | ❌ Distinto por evento |
| `correlation_id` | ❌ Se hereda del origen | ✅ Se mantiene | ✅ Puede ser el mismo |
| `entity_id` | ❌ Se hereda de la entidad | ❌ Puede ser el mismo | ✅ Siempre el mismo |

## Envelope público v1

El siguiente es el **contrato estable para consumidores externos**. Solo los campos listados aquí son públicos. Cualquier campo adicional en `data` es opcional y puede no estar presente.

```json
{
  "schema_version": "1.0",
  "event": "lead.created",
  "event_id": "ev_2xK9mR4nP7qA3cV8wE5bH1jF",
  "occurred_at": "2026-07-21T15:30:00Z",
  "producer": "estandar-leads",
  "project": "ak-contadores",
  "source": "estandar-forms",
  "correlation_id": "c_8f7a3b2c1d9e4f5a6b7c8d9e0f1a2b3c",
  "entity": { "type": "lead", "id": 1234 },
  "data": { ... }
}
```

| Campo | Tipo | Requisito | Descripción |
|-------|------|-----------|-------------|
| `schema_version` | string | Siempre | Versión del contrato. Semver `major.minor`. |
| `event` | string | Siempre | Nombre canónico dot-notation del evento. |
| `event_id` | string | Siempre | ID único de esta publicación. Idempotencia. |
| `occurred_at` | string | Siempre | UTC ISO-8601 del momento del hecho. |
| `producer` | string | Siempre | Plugin o sistema que originó el evento. |
| `project` | string | Opcional | Slug del proyecto multi-tenant. |
| `source` | string | Opcional | Canal de captura original. |
| `correlation_id` | string | Opcional | Traza el flujo completo. |
| `entity` | object | Opcional | `{ type, id }` — entidad de negocio afectada. |
| `data` | object | Opcional | Payload específico del evento. Sin credenciales. |

## Envelope interno (Signal Core)

El envelope interno contiene campos adicionales para el pipeline que **no forman parte del contrato público**:

| Campo | Ámbito | Descripción |
|-------|--------|-------------|
| `event_version` | Interno | Entero de versión del evento interno. |
| `recorded_at` | Interno | Timestamp de persistencia. |
| `lead_id` | Interno | ID interno del lead. |
| `lead_uuid` | Interno | UUID interoperable del lead. |
| `idempotency_key` | Interno | Clave UNIQUE en es_signal_events. |
| `source_plugin` | Interno | Plugin que emitió el hook. |
| `source_event_id` | Interno | ID del evento origen en el plugin. |
| `actor` | Interno | `{ type, id }` del actor. |

La separación entre envelope público y campos internos es explícita: los campos internos **no deben exponerse** en webhooks o integraciones externas.

## Catálogo de eventos públicos v1

### Eventos de lead

| Evento | Productor | `data` mínimo | Estabilidad |
|--------|-----------|---------------|-------------|
| `lead.created` | Leads | `submission_id`, `channel` | ✅ Estable |
| `lead.recontacted` | Leads | `submission_id`, `channel` | ✅ Estable |
| `lead.status.changed` | Leads | `from`, `to`, `reason` | ✅ Estable |
| `lead.quality.changed` | Leads | `from`, `to` | ✅ Estable |
| `lead.rejected` | Leads | `reason`, `status` | ✅ Estable |
| `sale.closed` | Leads | `stage`, `value`, `currency` | ✅ Estable |

### Eventos de clasificación

| Evento | Productor | `data` mínimo | Estabilidad |
|--------|-----------|---------------|-------------|
| `classification.automatic.recorded` | Leads | `decision`, `qualification`, `commercial_fit`, `rule_id` | ✅ Estable |
| `classification.human.recorded` | Leads | `decision`, `qualification`, `commercial_fit`, `overridden_fields` | ✅ Estable |

### Eventos de contacto y journey

| Evento | Productor | `data` mínimo | Estabilidad |
|--------|-----------|---------------|-------------|
| `contact.attempted` | Leads | `channel`, `outcome` | ✅ Estable |
| `meeting.booked` | Leads | `stage` | ✅ Estable |
| `journey.stage.changed` | Leads | `from`, `to`, `reason` | ✅ Estable |

### Eventos de delivery (Destinations)

| Evento | Productor | `data` mínimo | Estabilidad |
|--------|-----------|---------------|-------------|
| `destination.delivery.requested` | Signal Core | `journey_event_id`, `destination_id` | ✅ Estable |
| `destination.delivery.succeeded` | Destination | `journey_event_id`, `destination_id`, `provider_event_id` | ✅ Estable |
| `destination.delivery.failed` | Destination | `journey_event_id`, `destination_id`, `retryable`, `attempt`, `error_code` | ✅ Estable |

### Eventos de futuros dominios

| Evento | Productor | Estado |
|--------|-----------|--------|
| `submission.created` | Forms | 📋 Planificado |
| `quote.issued` | Quotes | 📋 Planificado |
| `survey.completed` | Forms | 📋 Planificado |

Estos eventos siguen el mismo namespace `{dominio}.{entidad}.{accion}` y el mismo envelope v1.

## Reglas del catálogo

1. **El catálogo es add-only.** Los eventos existentes nunca se eliminan. Si un evento queda obsoleto se marca como `DEPRECATED` pero su nombre no se reutiliza.
2. **Un hecho nuevo genera un evento nuevo**; una corrección no reescribe el evento anterior.
3. **Los hooks internos de WordPress pueden cambiar** sin afectar el catálogo público. El contrato público se versiona únicamente mediante `schema_version`.
4. **Los adapters de transporte pueden cambiar** (Webhook → Pub/Sub → SQS) sin alterar el payload del evento.
5. **La cola puede cambiar** (`es_signal_queue` → otra tecnología) sin afectar el formato del evento.
6. **Los datos personales crudos se limitan al dominio Leads.** Los eventos hacia destinos externos usan identificadores normalizados; nunca secretos, tokens internos ni PII no esencial.
7. **Los nombres de proveedores externos** (`Purchase`, conversion action IDs) pertenecen al adapter, no al catálogo.
8. **Cambios incompatibles** en el catálogo incrementan `schema_version` (major). Consumidores deben rechazar versiones desconocidas explícitamente.
