# TICKET · CONTACT_ATTEMPT_TRACKING_V1

Estado: SPEC — no implementar antes del 12-jun · Owner: estandar-leads · Prioridad: P1 post-batch

## Problema
El sistema registra lead creado y lead clasificado, pero no primer contacto. El botón
"Contactar por WhatsApp" del email del operador es `wa.me` directo (`Email::whatsapp_url()`),
invisible para el sistema. Consecuencias: `contact_attempts` = 0 en leads gestionados,
`first_contact_at` vacío, vista "unreached" del portal falsa, compliance escala leads ya
contactados, tiempo de respuesta inmedible.

## Diseño (0 taps adicionales para el operador)
Redirigir el CTA WhatsApp del operador por un endpoint tokenizado propio — mismo patrón que
el `/whatsapp-redirect` existente del lado del lead:

```text
email CTA → GET /wp-json/estandar-leads/v1/operator-contact?token={feedback_token}&channel=whatsapp
          → Estandar_Leads_Portal::log_contact(lead, operator, 'whatsapp', 'attempt')
            (incrementa contact_attempts, setea first_contact_at vía COALESCE — ya implementado)
          → 302 a wa.me/{phone_lead}
```

Reusa: `es_feedback_tokens` (TTL 21d, ya emitido por lead+operador), `es_lead_contacts`,
`log_contact()`. **Cero tablas nuevas, cero columnas nuevas.**

Semántica explícita: clic = intento de contacto, no contacto efectivo. Aceptado.

## Mitigación prefetch (obligatoria)
Outlook SafeLinks / proxies de Gmail hacen GET a los links del correo:
ignorar métodos HEAD y user-agents de prefetch conocidos; registrar máximo 1 intento por
token por hora (transient). El 302 ocurre siempre; solo el logging se filtra.

## Archivos
- `includes/class-estandar-leads-whatsapp-redirect.php` o clase nueva pequeña (ruta REST)
- `includes/class-estandar-leads-email.php` (whatsapp_url → operator-contact URL cuando hay token)
- `templates/email/lead-notification.php` + `compliance-escalation.php` (sin cambio estructural; el href cambia)

## Desbloquea
Métrica "tiempo a primer contacto" en Reports (`first_contact_at − created_at`, espejo de
`avg_days_to_classify` ya existente) y precisión del compliance (no escalar leads con intento registrado < Xh).

## Riesgos
Bajo. Display/routing intacto; si el endpoint falla, fallback href directo a wa.me (nunca bloquear el contacto por tracking).

## Validación
SALADEPRUEBAS: click en CTA desde Preview Email → fila en es_lead_contacts + first_contact_at + 302 correcto.
Verificar no-logging con UA de prefetch simulado.
