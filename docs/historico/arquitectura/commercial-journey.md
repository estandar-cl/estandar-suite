# Commercial Journey

Estado: arquitectura objetivo; Fase 1 implementada como shadow write, sin lectura ni despacho productivo.

## Flujo real actual

1. **Forms** (`Estandar_Forms`, `includes/class-forms.php`) valida, ejecuta su propia calificación (`Estandar_Forms_Qualification_Engine`) y emite `estandar_lead_captured`. `Estandar_Forms_Webhook` también escucha ese hook y puede despachar el payload fuera del sistema.
2. **Chatbot** captura por AJAX, OpenAI y WhatsApp (`includes/leads-endpoint.php`, `openai-proxy.php`, `whatsapp-handler.php`), mantiene leads/conversaciones propios y calcula score, prioridad e intención en `includes/leads-handler.php`; luego emite `estandar_lead_captured`.
3. **Leads** (`Estandar_Leads_Ingestion`) escucha `estandar_lead_captured`, normaliza identidad/atribución, crea o reutiliza `es_leads`, agrega submission/eventos y emite `estandar_lead_ingested`.
4. **Clasificación**: `Estandar_Commercial_Qualification_Engine` escucha `estandar_lead_ingested`, resuelve reglas de `Estandar_Commercial_Qualification_Rules` y persiste `qualification_completed`, `qualification_review` o `qualification_manual_override`. La decisión manual tiene precedencia y queda auditada, pero la decisión efectiva se serializa dentro del evento.
5. **Gestión humana**: `Estandar_Leads_Admin` y `Estandar_Leads_Repository` actualizan el lead y registran `status_changed`, `status_overridden`, `quality_changed`, `commercial_stage_changed`, contactos y acciones; `estandar_lead_status_changed` se emite desde el flujo administrativo.
6. **Journey actual**: `Estandar_Leads_Journey_Builder` reconstruye una vista desde `es_lead_events`. La Fase 1 añade `Estandar_Leads_Commercial_Journey` y `es_commercial_journey_events` como ledger sombra; todavía no es fuente de lectura ni dispara integraciones.
7. **Salidas directas en Leads**: `Estandar_Leads_Meta_Adapter` y `Estandar_Leads_Google_Adapter`, sus payload builders/providers/clients y `Estandar_Leads_Conversion_Backfill_Service` contienen selección de estados, payload, credenciales o despacho.
8. **Signal Core**: `Estandar_Signal_Core_Hooks` convierte solo lead nuevo y ciertos cambios de estado en seis señales. `Estandar_Signal_Core_Repository` persiste ledger/cola; `Estandar_Signal_Core_Scheduler` exporta por cron mediante `Estandar_Signal_Core_Meta_CAPI` y `Estandar_Signal_Core_Google_OCI`.
9. **Destinations**: `Estandar_Destinations_Engine` registra providers con `Estandar_Destination`; `Estandar_Google_Ads_Destination` ya implementa cola/worker/retry. No se encontró adapter Meta equivalente en este plugin.

## Duplicaciones, acoplamientos y legacy

- La calificación existe en Forms, Chatbot y Leads; sus conceptos de score/prioridad/intención no forman un contrato único.
- Chatbot conserva almacenamiento y administración de leads propios además de emitir al lead canónico.
- Leads es simultáneamente dueño del dominio, estados, Journey, integración Meta, integración Google y backfill.
- Signal Core contiene credenciales y exporters concretos, contrario a su rol objetivo de orquestador.
- Google Ads tiene al menos tres caminos: adapter de Leads, exporter de Signal Core y destination propia.
- Meta tiene caminos en Leads y Signal Core; la selección de estados exportables también está duplicada (`Estandar_Commercial_Stages` y `Estandar_Leads_Meta_Adapter::EXPORT_STATUSES`).
- `Estandar_Signal_Core_Hooks` depende del hook administrativo `estandar_lead_status_changed`; otros eventos persistidos no entran automáticamente al ledger.
- La equivalencia muchos-estados-a-un-evento (`VISITED` y `MEETING_BOOKED` → `meeting_booked`, por ejemplo) pierde precisión de dominio.
- `WON` se mantiene como alias legacy de `CONVERTED`.
- Las colas y taxonomías de estado difieren entre Signal Core y Destinations.

## Arquitectura canónica

`captura → lead canónico → clasificación automática + clasificación humana → eventos Journey → Signal Core → Destinations → Meta/Google Ads`

- El **Journey** es el ledger canónico, append-only, auditable y versionado. El estado proyectado del lead se deriva de sus eventos; no se borran decisiones anteriores.
- La clasificación automática y la humana son eventos separados. La proyección efectiva puede aplicar precedencia humana sin sobrescribir evidencia automática.
- **Signal Core** recibe eventos canónicos, valida el contrato, decide rutas y registra intentos; no decide qué es calificado, no construye payloads de proveedor y no guarda credenciales.
- **Destinations** aloja providers desacoplados. Cada provider traduce, valida configuración, encola, reintenta y audita su entrega.
- Meta y Google Ads consumen el mismo evento canónico, con traducciones independientes.
- Durante la migración, los hooks/tablas actuales siguen operando; cualquier doble escritura comienza en modo sombra y se compara antes de activar lecturas o entregas nuevas.

## Responsabilidades por plugin

| Plugin | Responsabilidad canónica |
|---|---|
| Forms | Capturar, validar entrada y emitir `capture.submitted`; sin decisión comercial autoritativa. |
| Chatbot | Capturar conversación/contacto y emitir eventos de captura/interacción; su score actual queda como evidencia de origen durante compatibilidad. |
| Leads | Identidad y lead canónico, submissions, clasificación automática/humana y proyección del Journey. |
| Journey (extensión dentro de Leads inicialmente) | Contrato, ledger, versión, orden, auditoría y proyecciones comerciales. No crear plugin nuevo en la primera fase. |
| Signal Core | Consumir eventos Journey y orquestar rutas/observabilidad sin lógica comercial ni secretos. |
| Destinations | Registro y ejecución aislada de providers; ownership de credenciales, payload, cola, retry e idempotencia de entrega. |
| Meta provider | Traducir eventos canónicos a Meta CAPI y auditar respuesta. |
| Google Ads provider | Traducir eventos canónicos a OCI y auditar respuesta. |

## Primera fase de código recomendada

Extender Leads con un **emisor canónico en modo sombra** sobre `Estandar_Leads_Repository::insert_event`: convertir únicamente eventos ya persistidos a un envelope Journey v1 y registrarlos en un ledger append-only, sin cambiar estados, UI, hooks existentes ni despachar a Signal Core/Destinations. Empezar con `lead.created`, `classification.automatic.recorded`, `classification.human.recorded` y `lead.status.changed`; comparar conteos, orden, actor, source event e idempotencia contra `es_lead_events`. Esta fase crea el contrato verificable mínimo y tiene rollback por desactivar el subscriber y descartar solo datos sombra.
