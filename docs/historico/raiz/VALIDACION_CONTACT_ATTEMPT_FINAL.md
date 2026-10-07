# VALIDACIÓN FINAL — CONTACT_ATTEMPT

**Máximo 500 palabras. Evidencia + ubicación exacta + propuesta mínima.**

---

## 1. ¿EXISTE EVENTO CONTACT_ATTEMPT?

**Respuesta: NO**

Búsqueda exhaustiva en codebase:
```
grep -r "insert_event.*contact_attempt" → (sin resultados)
grep -r "'contact_attempt'" → (sin resultados)
```

**Constatado:** No existe evento `contact_attempt` en el código.

---

## 2. ¿PUEDEN USARSE EVENTOS EXISTENTES?

### A. whatsapp_clicked

**Status:** DEFINIDO pero NUNCA EMITIDO

Ubicaciones donde se REFENCIA:
```
class-estandar-leads-portal.php:549 → array( 'whatsapp_clicked', 'email_clicked', 'call_clicked' )
operator-workspace.php:512 → 'whatsapp_clicked' => 'WhatsApp abierto'
```

Ubicación donde se EMITE:
```
Resultado: NINGUNA

Lo que SÍ se emite:
  └─ class-estandar-leads-whatsapp-redirect.php:175
     Estandar_Leads_Repository::insert_event( $lead_id, 'lead_whatsapp_opened', [...] )
```

**Conclusión:** `whatsapp_clicked` es esperado por la UI pero NO se emite. Se emite `lead_whatsapp_opened` en su lugar (evento distinto).

### B. email_clicked

**Status:** DEFINIDO pero NUNCA EMITIDO

Lo que SÍ se emite en autoresponse:
```
class-estandar-leads-autoresponse.php:26
  const EVENT_LINK_CLICK = 'autoresponse_link_click';
  
Línea 338: insert_event($lead_id, self::EVENT_LINK_CLICK, ...)
```

**Conclusión:** Se emite `autoresponse_link_click`, NO `email_clicked`.

### C. call_clicked

**Status:** NO EXISTE en absoluto

No hay implementación de tracking de llamadas.

---

## 3. ANÁLISIS DE LA BRECHA

**Tabla actual:**

| Evento | Emitido | Propósito | Usado en UI |
|--------|---------|-----------|------------|
| `lead_whatsapp_opened` | ✓ | WhatsApp abierto por lead | ✗ |
| `autoresponse_link_click` | ✓ | Lead clickeó PDF/landing | ✗ |
| `autoresponse_email_open_signal` | ✓ | Pixel de apertura | ✗ |
| `whatsapp_clicked` | ✗ | Contacto iniciado | ✓ (buscado) |
| `email_clicked` | ✗ | Contacto iniciado | ✓ (buscado) |
| `call_clicked` | ✗ | Contacto iniciado | ✓ (buscado) |

**Problema visual:**
```
UI busca: 'whatsapp_clicked', 'email_clicked', 'call_clicked'
Código emite: 'lead_whatsapp_opened', 'autoresponse_link_click', (call=none)

Resultado: Consultas SQL retornan cero (eventos no existen)
```

---

## 4. PUNTO EXACTO PARA CONTACT_ATTEMPT

### Ubicación 1: WhatsApp Redirect (PUNTO A)

**Archivo:** `estandar-leads/includes/class-estandar-leads-whatsapp-redirect.php`  
**Función:** `record_lead_whatsapp_opened()` (línea 166)

**Cambio mínimo:**

```php
// LÍNEA 175-183 (actual)
Estandar_Leads_Repository::insert_event( $lead_id, 'lead_whatsapp_opened', [...] );

// AGREGAR DESPUÉS (nueva línea 184):
Estandar_Leads_Repository::insert_event( $lead_id, 'contact_attempt', [
    'submission_id' => $submission_id ?: null,
    'operator_id' => $operator_id ?: null,
    'contact_channel' => 'whatsapp',
    'metadata' => [
        'project_slug' => sanitize_key( $project_slug ),
        'source' => sanitize_key( $source ),
    ],
] );
```

**Impacto:** +1 línea, registra intento de contacto vía WhatsApp

### Ubicación 2: Email Autoresponse (PUNTO B)

**Archivo:** `estandar-leads/includes/class-estandar-leads-autoresponse.php`  
**Función:** `record_link_click()` (línea 338, aprox)

**Cambio mínimo:**

```php
// DESPUÉS de insert_event($lead_id, self::EVENT_LINK_CLICK, ...)
// AGREGAR:
Estandar_Leads_Repository::insert_event( $lead_id, 'contact_attempt', [
    'submission_id' => $submission_id,
    'operator_id' => $operator_id,
    'contact_channel' => 'email',
    'metadata' => [
        'resource_type' => $resource_type,
        'autoresponse_project_id' => $project_slug,
    ],
] );
```

**Impacto:** +1 línea, registra intento de contacto vía email

---

## 5. PROPUESTA MÍNIMA

**Cambio total:** 2 inserciones de líneas  
**Archivo 1:** whatsapp-redirect.php (+1 línea)  
**Archivo 2:** autoresponse.php (+1 línea)

**Payload mínimo:**
```json
{
  "event_type": "contact_attempt",
  "lead_id": 152,
  "submission_id": null,
  "contact_channel": "whatsapp|email",
  "metadata": {...}
}
```

**Efecto inmediato:**
```
✓ Consultas SQL buscarán 'contact_attempt' y encontrarán resultados reales
✓ Dashboard operacional verá: "Lead contactado ✓" vs "Lead sin contacto ✗"
✓ Posibilidad de preguntar: "¿Cuántos leads fueron realmente contactados?"
```

---

## CONCLUSIÓN

**¿Existe contact_attempt?** NO  
**¿Pueden reutilizarse existentes?** NO (emiten eventos distintos)  
**¿Dónde debe emitirse?** Dos puntos (whatsapp-redirect.php + autoresponse.php)  
**¿Cambio mínimo?** 2 líneas de código

**Recomendación:** Implementar inmediatamente. Costo negligible, claridad operativa máxima.

