# Webhook Guide — Integración con sistemas externos

**Versión del contrato:** 1.0  
**Documentos relacionados:** `docs/architecture/event-catalog.md` (especificación canónica)  
**Estado:** Estable — cambios solo incrementan `schema_version`

---

## 1. Introducción

Los Webhooks de Estándar Suite permiten recibir eventos comerciales en tiempo real en sistemas externos como CRMs, ERPs, n8n, aplicaciones propias o cualquier servicio que acepte HTTP POST.

Los eventos se envían inmediatamente después de que un lead es capturado y procesado por el sistema. No hay polling, no hay latencia programada.

### ¿Cuándo se envía un evento?

| Evento | Disparo |
|--------|---------|
| `lead.created` | Nuevo lead capturado desde formulario, chatbot, API o carga manual |
| `lead.recontacted` | Lead existente vuelve a contactar |
| `lead.status.changed` | Estado comercial del lead cambia |
| `sale.closed` | Venta cerrada |

### Estabilidad del contrato

**El contrato público del evento es estable.** Se versiona exclusivamente mediante el campo `schema_version` en el body. Nunca se eliminan campos existentes. Los cambios incompatibles producen un incremento de `schema_version` (major).

Los hooks internos de WordPress, los adapters de transporte y la implementación de la cola **pueden cambiar** sin afectar el formato del evento. Los consumidores solo dependen del payload documentado aquí.

---

## 2. Payload

### Ejemplo completo

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
  "entity": {
    "type": "lead",
    "id": 1234
  },
  "data": {
    "lead": {
      "id": 1234,
      "name": "Juan Pérez",
      "email": "juan@ejemplo.cl",
      "phone": "+56912345678"
    },
    "submission": {
      "id": 5678,
      "submitted_at": "2026-07-21T15:29:45Z",
      "source_plugin": "estandar-forms",
      "utm_source": "google",
      "utm_medium": "cpc",
      "utm_campaign": "casas-2026"
    },
    "commercial": {
      "project_slug": "ak-contadores"
    }
  }
}
```

### Campos del payload

| Campo | Tipo | Siempre presente | Descripción |
|-------|------|-----------------|-------------|
| `schema_version` | string | ✅ | Versión del contrato (`major.minor`). El consumidor debe rechazar majors desconocidas. |
| `event` | string | ✅ | Nombre canónico del evento en dot-notation. Ej: `lead.created`, `sale.closed`. |
| `event_id` | string | ✅ | Identificador único de esta publicación. **Sirve para idempotencia.** |
| `occurred_at` | string | ✅ | Timestamp ISO 8601 UTC del momento en que ocurrió el hecho. |
| `producer` | string | ✅ | Plugin o sistema que originó el evento (`estandar-leads`, `estandar-forms`, etc.). |
| `project` | string | — | Slug del proyecto multi-tenant cuando aplica. Vacío si no hay proyecto asociado. |
| `source` | string | — | Canal de captura original (`estandar-forms`, `estandar-chatbot`, `api`, `manual`). |
| `correlation_id` | string | — | Identificador del flujo completo. Mismo valor en todos los eventos relacionados (captura → clasificación → venta). |
| `entity` | object | — | `{ type, id }` — tipo y ID de la entidad de negocio afectada (`lead`, `quote`, etc.). |
| `data.lead` | object | — | Datos del lead: `id`, `name`, `email`, `phone`. |
| `data.submission` | object | — | Metadatos de la captura: `id`, `submitted_at`, `source_plugin`, campos UTM. |
| `data.commercial` | object | — | Contexto comercial: `project_slug`. Puede incluir `score`, `temperature`, `priority` en eventos futuros. |

### Semántica de los identificadores

| Identificador | Propósito | Persiste entre eventos relacionados |
|--------------|-----------|-----------------------------------|
| `event_id` | **Publicación única.** Identifica este evento específico. Se genera al publicar. | ❌ Cada evento tiene su propio `event_id`. |
| `correlation_id` | **Flujo completo.** Identifica la interacción del principio al fin. Se genera en la captura inicial. | ✅ Sí. Todos los eventos de un mismo lead comparten el mismo `correlation_id`. |
| `entity.id` | **Entidad de negocio.** Identifica el lead, cotización o entidad afectada. | ✅ Sí. Una misma entidad puede tener múltiples eventos. |

**Ejemplo de uso:** Un lead es capturado → se recibe `lead.created` con `event_id=ev_A`. Luego el lead se califica → se recibe `lead.status.changed` con `event_id=ev_B`. Ambos eventos tienen el mismo `correlation_id` y `entity.id`. El consumidor puede:
- Usar `event_id` para evitar procesar duplicados.
- Usar `correlation_id` para reconstruir la historia completa de la interacción.
- Usar `entity.id` para actualizar el registro de la entidad en su sistema.

---

## 3. Headers

Cada solicitud HTTP incluye los siguientes headers:

| Header | Ejemplo | Obligatorio | Finalidad |
|--------|---------|-------------|-----------|
| `Content-Type` | `application/json` | ✅ | Formato del body |
| `X-Estandar-Event` | `lead.created` | ✅ | Tipo de evento para que el consumidor rutee sin parsear el body |
| `X-Estandar-Event-ID` | `ev_2xK9mR4nP7qA3cV8wE5bH1jF` | ✅ | Mismo valor que `event_id` en el body. Idempotencia a nivel header. |
| `X-Estandar-Schema-Version` | `1.0` | ✅ | Versión del contrato. El consumidor puede rechazar temprano versiones no soportadas. |
| `X-Estandar-Timestamp` | `2026-07-21T15:30:00Z` | ✅ | Timestamp ISO 8601 UTC del envío. Sirve para ventanas de replay y protección contra ataques de replay. |
| `X-Estandar-Signature` | `sha256=a1b2c...` | ✅ | Firma HMAC del body (ver sección 4). |
| `User-Agent` | `Estandar-Webhook/1.0` | ✅ | Identificación del emisor. |

---

## 4. Firma HMAC

### Algoritmo

```
firma = HMAC-SHA256(
    clave = secreto_compartido,
    mensaje = timestamp + "." + event_id + "." + body
)
```

Donde:
- `secreto_compartido` = clave configurada por proyecto en la administración de Destinations
- `timestamp` = valor del header `X-Estandar-Timestamp` (ISO 8601 UTC)
- `event_id` = valor del header `X-Estandar-Event-ID`
- `body` = body HTTP exacto (raw JSON, sin transformación)

La firma se envía como: `sha256=<hex-digest>` en el header `X-Estandar-Signature`.

### Validación

```python
import hmac, hashlib

secret = b"tu-secreto-compartido"
body = request.body     # raw bytes
timestamp = request.headers["X-Estandar-Timestamp"]
event_id = request.headers["X-Estandar-Event-ID"]
received_sig = request.headers["X-Estandar-Signature"].replace("sha256=", "")

message = f"{timestamp}.{event_id}.{body.decode()}".encode()
expected_sig = hmac.new(secret, message, hashlib.sha256).hexdigest()

if not hmac.compare_digest(expected_sig, received_sig):
    raise Exception("Invalid signature")
```

### Protección contra replay

1. Verificar que `X-Estandar-Timestamp` esté dentro de una ventana de ±5 minutos respecto a la hora del servidor.
2. Almacenar `X-Estandar-Event-ID` ya procesados. Si un ID ya fue procesado, responder 200 sin procesar de nuevo.

### Recomendaciones de seguridad

- La firma cubre el body exacto. Cualquier transformación (re-encoding por proxy, cambio de whitespace) invalidará la firma.
- No compartir el secreto entre proyectos. Cada proyecto debe tener su propio secreto.
- Rotar el secreto periódicamente desde la administración de Destinations.
- Si el secreto está vacío, la firma no se genera. En producción siempre debe configurarse un secreto.

---

## 5. Respuestas esperadas

### HTTP 200 — Aceptado

Procesamiento exitoso. El sistema no reintentará el envío.

```json
{ "received": true }
```

### HTTP 400 — Solicitud mal formada

El body no es JSON válido o falta un campo obligatorio. El sistema marcará el evento como `dead` (no reintenta).

### HTTP 401 — No autorizado

Firma HMAC inválida o faltante. El sistema reintentará (puede ser error de configuración del secreto).

### HTTP 404 — No encontrado

Endpoint no existe. El sistema marcará el evento como `dead`.

### HTTP 429 — Demasiadas solicitudes

Rate limiting. El sistema reintentará con backoff exponencial (1 min, 5 min, 15 min, 30 min, 60 min).

### HTTP 500 — Error interno del servidor

Error transitorio. El sistema reintentará.

### Timeout (sin respuesta en 15 segundos)

El sistema reintentará como si fuera un error transitorio.

### Política de reintentos

| Condición | Comportamiento | Máximo de intentos |
|-----------|---------------|-------------------|
| HTTP 200 | ✅ Éxito — no reintenta | 1 |
| HTTP 400 | ❌ Falla permanente (`dead`) | 1 |
| HTTP 401 | ⚠️ Reintenta (error de configuración) | 5 |
| HTTP 404 | ❌ Falla permanente (`dead`) | 1 |
| HTTP 429 | 🔄 Reintenta con backoff | 5 |
| HTTP 500 | 🔄 Reintenta con backoff | 5 |
| Timeout/red | 🔄 Reintenta con backoff | 5 |

Backoff: 1 min → 5 min → 15 min → 30 min → 60 min.

### Idempotencia

Un mismo `event_id` nunca representa dos eventos distintos. Si el consumidor ya procesó un `event_id`, debe responder 200 sin procesar de nuevo. El sistema puede reenviar el mismo evento si no recibió respuesta (timeout, error de red), pero siempre con el mismo `event_id`.

---

## 6. Ejemplos de integración

### n8n

1. Crear un Webhook node como trigger.
2. Configurar:
   - **Method:** POST
   - **Respond:** Webhook Response Node con Response Code = 200 y body = `{ "received": true }`
3. Opcional: agregar un Function node para validar la firma:

```javascript
const crypto = require('crypto');
const secret = 'tu-secreto-compartido';

const timestamp = $input.headers['x-estandar-timestamp'];
const eventId = $input.headers['x-estandar-event-id'];
const receivedSig = $input.headers['x-estandar-signature'].replace('sha256=', '');
const body = $input.body;
const message = timestamp + '.' + eventId + '.' + JSON.stringify(body);
const expectedSig = crypto.createHmac('sha256', secret).update(message).digest('hex');

if (expectedSig !== receivedSig) {
  throw new Error('Invalid signature');
}
```

4. Usar `$input.body.event` para rutear a diferentes workflows (lead.created → actualizar CRM, sale.closed → enviar factura, etc.).

### PHP

```php
<?php
$secret = 'tu-secreto-compartido';

// Leer headers
$timestamp = $_SERVER['HTTP_X_ESTANDAR_TIMESTAMP'] ?? '';
$eventId   = $_SERVER['HTTP_X_ESTANDAR_EVENT_ID'] ?? '';
$sigHeader = $_SERVER['HTTP_X_ESTANDAR_SIGNATURE'] ?? '';

// Validar firma
$receivedSig = str_replace('sha256=', '', $sigHeader);
$body = file_get_contents('php://input');
$message = $timestamp . '.' . $eventId . '.' . $body;
$expectedSig = hash_hmac('sha256', $message, $secret);

if (!hash_equals($expectedSig, $receivedSig)) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

// Procesar evento
$event = json_decode($body, true);
$eventName = $event['event'] ?? '';
$leadId    = $event['entity']['id'] ?? 0;

// Almacenar event_id para idempotencia (ej: Redis o DB)
// if (already_processed($eventId)) { http_response_code(200); exit; }

// Procesar según tipo de evento
switch ($eventName) {
    case 'lead.created':
        // Guardar en CRM
        break;
    case 'sale.closed':
        // Enviar a contabilidad
        break;
}

http_response_code(200);
echo json_encode(['received' => true]);
```

### Node.js

```javascript
const crypto = require('crypto');
const express = require('express');
const app = express();

const SECRET = 'tu-secreto-compartido';

app.post('/webhook/estandar', express.json(), (req, res) => {
  const timestamp = req.headers['x-estandar-timestamp'];
  const eventId = req.headers['x-estandar-event-id'];
  const sigHeader = req.headers['x-estandar-signature'] || '';
  const receivedSig = sigHeader.replace('sha256=', '');
  const body = JSON.stringify(req.body);
  const message = `${timestamp}.${eventId}.${body}`;
  const expectedSig = crypto.createHmac('sha256', SECRET).update(message).digest('hex');

  if (!crypto.timingSafeEqual(Buffer.from(expectedSig), Buffer.from(receivedSig))) {
    return res.status(401).json({ error: 'Invalid signature' });
  }

  // Idempotencia
  const processed = processEvent(req.body);
  
  res.json({ received: true });
});

app.listen(3000);
```

---

## 7. Compatibilidad y evolución

### Versionado

- El contrato se versiona mediante `schema_version` (semver simplificado: `major.minor`).
- El consumidor debe verificar `schema_version` y rechazar majors que no soporte.
- Los cambios minor (1.0 → 1.1) agregan campos opcionales. No rompen consumidores existentes.
- Los cambios major (1.x → 2.0) pueden eliminar campos o cambiar semántica. El consumidor debe adaptarse.

### Reglas add-only

- Los campos existentes nunca se eliminan dentro del mismo major.
- Los nuevos campos agregados en el mismo major son opcionales. El consumidor debe ignorarlos si no los reconoce.
- Los nombres de evento (namespace `{dominio}.{entidad}.{accion}`) nunca se reutilizan para otro propósito.

### Buenas prácticas para consumidores

1. **Siempre validar la firma** antes de procesar el body.
2. **Usar `event_id` para idempotencia.** Almacenar IDs procesados y rechazar duplicados.
3. **Verificar `schema_version`.** Rechazar majors desconocidas. Ignorar minors desconocidas.
4. **Responder rápido.** La ventana de timeout del emisor es de 15 segundos. Devolver 200 inmediatamente después de validar la firma y encolar el procesamiento si es pesado.
5. **Registrar `correlation_id`.** Permite correlacionar eventos en los logs del consumidor con el sistema origen.
6. **No depender de headers para lógica de negocio.** Los headers son metadata de transporte. La información de negocio está en el body.
7. **Prepararse para reenvíos.** Un mismo evento puede llegar más de una vez por timeouts o errores de red. La idempotencia vía `event_id` lo resuelve.
