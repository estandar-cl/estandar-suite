# SALES_ACTIVATION_ENGINE_V1

## Propósito

Convertir evidencia comercial persistida en una lista breve y accionable de oportunidades por operador. El motor no reemplaza Behavioral Score, Commercial Intelligence, Recovery, Compliance ni las notificaciones de ingreso.

## Límites de fase 1

- Único canal: email al operador o destinatario administrativo configurado.
- Máximo configurable de 1 a 5 recomendaciones por operador.
- Sin WhatsApp saliente, dashboard, widget, API ni integraciones externas.
- Sin cambios de estado, clasificación, Behavioral Score o política comercial.
- Sin tabla nueva: configuración/estado mínimo en `wp_options` y auditoría en `es_lead_events`.

## Arquitectura

`Sales Activation Engine -> Recommendations -> Email Renderer`

`Estandar_Leads_Sales_Activation::evaluate()` es el contrato puro y reutilizable. Entrega recomendaciones con prioridad, journey, razones, última actividad y datos de contacto. `templates/email/sales-activation.php` solo presenta ese contrato; no consulta datos ni selecciona leads.

Evolución prevista:

1. Fase 2: WhatsApp al operador como nuevo consumidor del mismo contrato.
2. Fases posteriores: dashboard/widget, API, Teams/Slack y otros canales.

## Elegibilidad

Una recomendación requiere simultáneamente:

- asignación vigente al operador del último interés y proyecto canónico resoluble;
- operador activo con email;
- política QA y política comercial canónicas habilitadas;
- teléfono normalizado utilizable (8 a 15 dígitos);
- estado no terminal, o recontacto/reapertura posterior al cierre terminal;
- ausencia de contacto comercial efectivo, o actividad nueva del prospecto posterior al último contacto;
- al menos una señal de intención, recuperación, SLA o información comercial explícita.

Se excluyen QA, `routing/notification=skip`, teléfono inválido, operador incorrecto, proyecto no resuelto, terminal sin reapertura y contacto efectivo sin actividad posterior.

## Activation score y explicabilidad

`activation_score` es independiente de `behavioral_score`; no se persiste en la entidad Lead ni se muestra en el email. Suma contribuciones explicables y limita el total a 100:

| Familia | Base máxima |
|---|---:|
| Recontacto o múltiples submissions equivalentes | 24 |
| Interacción multicanal | 12 |
| Clic en email | 18 |
| Apertura de WhatsApp | 16 |
| Aperturas de email, con cap | 10 |
| Perfil comercial explícito de alto valor | 16 |
| Inicio inmediato | 14 |
| Empresa operando | 10 |
| Evaluación de cambio de proveedor | 12 |
| SLA escalado / vencido | 18 / 10 |
| Sin gestión efectiva | 10 |
| Actividad reciente | 10 |
| Completitud del contacto | 6 |

Las señales temporales usan multiplicadores por antigüedad: `1.0` hasta 3 días, `0.85` hasta 7, `0.60` hasta 14 y `0.35` hasta 30. Los hechos comerciales explícitos conservan un piso `0.5`; SLA vigente y ausencia de gestión reflejan estado actual. Aperturas repetidas tienen cap y recontacto/múltiples submissions comparten una sola familia para evitar inflación equivalente.

Los hechos comerciales se aceptan solo desde claves/valores estructurados persistidos en `context_json`, `form_fields_json`, `chatbot_json` o `scoring_json`. No se interpreta texto libre ni se inventa intención. Cada recomendación entrega de 2 a 5 razones verificables cuando existe evidencia auxiliar suficiente.

Prioridad de activación: `ALTA >= 60`, `MEDIA >= 35`, `BAJA < 35`. El umbral administrativo decide la inclusión.

## Orden y deduplicación

Orden determinista: `activation_score DESC`, `latest_activity DESC`, `lead_id ASC`. Después se aplica el máximo configurado.

Por operador se calcula un fingerprint SHA-256 sobre leads, prioridad, última actividad y códigos de razón. Un conjunto idéntico no vuelve a enviarse. Un cambio de actividad, razones, prioridad o composición habilita una nueva activación. Solo un envío exitoso actualiza el fingerprint.

## Configuración

La única pantalla vive en **Estandar Primer → Configuración compartida → Sales Activation Engine** (`admin.php?page=estandar_core_diagnostics&tab=configuracion#estandar-settings-sales-activation`). Se registra mediante `estandar_settings_sections`; no se duplica dentro de Configuración de Leads.

La sección expone: habilitado, días, hora en timezone WordPress, máximo, score mínimo, destinatarios opcionales, CC, tono desactivado/profesional/cercano/inspirador/personalizado, textos administrativos, **Enviar prueba ahora**, próximo envío, último envío, cantidad de recomendaciones y destinatarios efectivos.

El envío inmediato ejecuta el mismo motor, usa datos persistidos reales y entrega sus `Recommendations` al renderer definitivo `templates/email/sales-activation.php`. Si no puede enviar, muestra un resultado explícito: motor deshabilitado, operadores ausentes, leads pendientes ausentes, score insuficiente, elegibilidad insuficiente, destinatario inválido, conjunto sin cambios, error de renderer o rechazo de `wp_mail()`.

Si `destinatarios` está vacío se usa el email canónico del operador. Si se configura, reemplaza el destinatario operativo; los emails adicionales se envían como CC. No existen citas atribuidas a celebridades.

## Observabilidad y seguridad

La generación y el resultado se registran por lead mediante:

- `sales_activation_recommendation_generated`
- `sales_activation_email_sent`
- `sales_activation_email_failed`

Cada evento contiene `batch_id`, destinatario, IDs recomendados, razones, prioridad, resultado, indicador de prueba y versión del motor. Los enlaces de WhatsApp, llamada, clasificación y ficha reutilizan tokens/enlaces firmados existentes. No se registran secretos ni scores internos en el correo.

El estado operativo del último run se conserva en `estandar_leads_sales_activation_run_state`. El cron es un evento único reprogramable que calcula la próxima fecha desde `send_days`, `send_time` y `wp_timezone()`; al guardar configuración o finalizar un run programado elimina el evento anterior y agenda el siguiente.

## Roadmap de canales

- Fase 2: envío de las recomendaciones al operador por WhatsApp.
- Posteriores: dashboard/widget, API, Teams/Slack y otros adaptadores.
- Todo canal futuro consume `Recommendations`; ninguna selección puede vivir en un renderer o adaptador.
