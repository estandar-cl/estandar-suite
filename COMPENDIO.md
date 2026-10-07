### 2026-09-30 (código fuente, SIN release) — QUALIFICATION_V1 / LEAD_QUALITY_ALIGN_V1 (cualificación del form → score y calidad del radar)
Necesidad (AK): el formulario YA pregunta situación de la empresa, contador actual, urgencia y
necesidad, pero el scoring de la Suite las ignoraba → todos los leads quedaban TIBIO (50) y el
radar mostraba 0 HIGH. Objetivo del cliente: `maximize_customer_value` (señal de valor real a Google).
- `estandar-brain` (`LeadScorer`): nuevo `submission_signals()` que lee la submission que originó el
  lead de `es_lead_submissions.form_fields_json` (por `submission_id`; fallback por `lead_id`), deriva
  `qualification` 0–100 con `qualification_from_fields()` y la pasa a `Scoring::calculate()`
  (`qualification` ya no es `null`; peso 0.40). También puebla `es_leads.lead_intent` desde la
  necesidad si estaba vacío (alimenta `IntentMap`). Soporta los 3 vocabularios de form de AK
  (sections nuevas `que_necesitas_resolver/...`; legacy `necesidad_principal/...`; variante
  `tipo_cliente/servicio/urgencia`), normaliza slugs con guion, y aplica señales LOW (L2/L3/L6/L7) y
  H7 del `objectives.yaml`. Si no hay preguntas de cualificación → `null` (sin regresión).
  Cache de columnas versionada (`COLS_REV=2`, +`lead_intent`).
- `estandar-brain` (`SignalLedger::on_capture`): el signal de captura también incluye la
  `qualification` (antes `null`) → el score/temperatura de la captura coincide con el del lead.
- `estandar-core` (`AdsDataManager`): `quality_for_capture()` y el fallback ahora mapean la
  **temperatura** del score a HIGH/MEDIUM/LOW (CALIENTE/TIBIO/FRIO). El umbral numérico 80 era
  inalcanzable con el motor ponderado (máx 70 con `behavior=0`) → el radar nunca podía dar HIGH.
  El fallback también deriva la cualificación de `fields_json` (misma lógica que el scorer).
- **Gate de identidad semántica (anti-regresión):** `qualification_from_fields` solo aplica cuando la
  señal primaria (situación de la empresa) es un **valor reconocido**; con el mismo nombre de campo pero
  otro vocabulario (p. ej. `estandar.cl`: `situacion_empresa=operando`) devuelve `null` → sin cambio.
  Verificado contra las capturas reales de acacios/estandar/guillaume/rodrigovaldes: solo AK coincide.
- Tests: `estandar-brain/tests/run-brain.php` **73/0** (+11a–11m), run-brain-admin 24/0,
  run-brain-contactability 9/0, run-brain-exporters 30/0, run-signal-adoption 11/0; `estandar-core`
  run-core 178/0, run-ads-data-manager 28/0; `estandar-capture` run-capture 713/0. E2E en Playground
  (wp-cli, symlink al source): ideal → CALIENTE/HIGH (70), anti-perfil → FRIO/LOW (30), sin
  cualificación → TIBIO/MEDIUM (50, sin regresión). `php -l` OK en los 3 archivos.
- **PUBLICADO (2026-09-30):** canal `updates.estandar.cl/staging/v2` release_id `20260930-213840`
  (core 3.2.6→3.2.7, brain 2.9.1→2.9.2) y `20260930-215103` (brain 2.9.2→2.9.3, con el gate).
- Rollback: republicar la versión anterior del plugin en el canal.

### 2026-09-20 (release) — Core 3.0.1 → 3.0.3: UPDATE_SLUGS_FILTER + AUTO_REPAIR_SEED (auto-update de plugins externos)
Necesidad: que un plugin Estandar **fuera de la suite** (p. ej. `veloz`, que vive en
otro proyecto) se auto-actualice igual que los demás, sin hardcodearlo en el core.
- `Updates::slugs()` **filtrable** (`estandar_suite_update_slugs`); los 8 usos de
  `SUITE_SLUGS` pasan a `slugs()`. `veloz` se suma por filtro desde `class-veloz-suite.php`
  (con su header `Update URI: https://updates.estandar.cl/staging/v2/`).
- `AUTO_REPAIR_SEED`: la siembra del toggle nativo de WP guarda una **firma** (md5 del
  conjunto de slugs) y re-siembra cuando el conjunto cambia → un plugin externo instalado
  después de la primera siembra entra al auto-update sin intervención manual. La siembra
  se difiere a `init` (después de que todos los plugins registren sus filtros).
- `run-updates` 40/0 (+3). Release `20260920-013837`: core 3.0.3, veloz 0.5.2 en el canal.
  `veloz.estandar.cl`: core 3.0.3, veloz 0.5.2, `is_auto_updatable(veloz)=true`.

### 2026-09-19 (código fuente, SIN release) — Core 3.0.0 → 3.0.1: DYNAMIC_TABS_V1 + OTHERS_PLUGINS_V1
Los tabs de producto se pintaban siempre (lista fija) aunque su plugin no estuviera activo → abrían
una página cuyo submenú nunca se registró. Además "Otros plugins" solo aceptaba directorios `estandar-*`,
por lo que plugins Estandar sin prefijo (p. ej. `veloz`) nunca aparecían.
- `Admin.php`: const `PRODUCT_PLUGINS` (capture/brain/flow/insights) + `Admin::plugin_active($file)`
  robusto (is_plugin_active → active_plugins → active_sitewide_plugins → true si indeterminable).
  `products()` descarta los productos con plugin inactivo y pasa por el filtro `estandar_suite_products`
  (`inicio`/`core`/`otros` siempre). Retrocompatible: con todos los plugins activos la lista es idéntica.
- `Admin.php::render_otros()`: nuevo filtro `estandar_suite_otros_plugins` (recibe/devuelve plugin
  files); valida existencia en `get_plugins()`, actividad y excluye productos; dedup por directorio,
  orden por nombre. La autodetección `estandar-*` y el embedding `estandar_suite_otros_content` siguen igual.
- Tests: `tests/run-core.php` 223/0 (+11q-11v), `tests/run-visibility.php` 43/0. Sin release
  (restricción de la sesión dedicada 030: solo código + tests locales).

### 2026-09-09 (código fuente, SIN release) — Capture: LEAD_FLAT_PHOTOS_V1 (webhook gf_compat incluye las fotos de un form lead)
El Excel de Google (webhook n8n, `webhook_payload_mode=gf_compat`) dejaba vacías las columnas "Foto 1..4"
cuando el form 21 (lead) enviaba fotos. Causa: `Webhook::build_gf_payload` mapeaba las fotos a las keys
GF `31/32/34/36` solo desde `posted['incidents'][].fotos` (repeater del survey); un form lead guarda cada
foto en un campo plano `type=file` (`adjunta_o_toma_una_imagen_con_tu_telefono[_N]`) → no las encontraba.
- `Webhook.php`: si el incident no trae fotos, se busca la foto plana del bloque N vía
  `flat_photo_url_for()` (bloque 1 = campo file sin sufijo `_N`; bloques 2-4 = con sufijo `_N`), mismo
  emparejamiento que `GfPostventaBridge::flat_incidents_from_fields`. Los incidents[] del repeater
  siguen mapeando igual (retrocompatible).
- Tests run-capture **577/0** (19q-19u). Sin release.


### 2026-09-09 (código fuente, SIN release) — Capture: MAQ_ROUTE_PRESERVE_V1 (el route map no se vacía al guardar desde otra sub-vista)
El form 21 (acacios) volvió a quedar con `notify_route_map_text` vacío tras guardar desde la sub-vista
diseño. Causa: la serialización del maquetador recomponía `notify_route_map_text` desde las filas
`.es-route-map`, que solo existen en correos→notify → al guardar desde otra sub-vista el JS pisaba el
route map con ''. Misma clase de bug que la degradación del tipo del campo (perdida de config de
sub-vistas no visibles).
- `maquetador.php`: `notify_route_map_text` solo se recompone si hay filas `.es-route-map` en el DOM
  (sub-vista notify visible); si no hay filas se preserva el valor actual. Vaciar el routing a propósito
  sigue siendo posible guardando desde correos→notify con las filas borradas.
- Producción acacios: route_map del form 21 restaurado igual al 16 (DOCUMENTOS→postventa@;
  SERVICIO→postventa@+serviciotecnico@). Tests run-capture **572/0** (25i1). Sin release.

### 2026-09-09 (código fuente, SIN release) — Capture: LEAD_PHOTO_V1 (fotos en form LEAD: subida real + fila URL + adjuntos al correo)
Caso acacios form 21 (lead, rama SERVICIO): un campo type=file adjuntaba una imagen pero el correo de
notificación no la incluía. Causas: (1) la ruta lead solo guardaba el NOMBRE del archivo (no lo subía);
(2) el contrato gf_postventa solo leía `posted['incidents']` (repeater del survey), ignoraba los campos
planos de un form lead; (3) notify_submission no adjuntaba archivos.
- `Forms::store_uploaded_file()`: guarda el archivo de un campo file en `uploads/estandar-capture/survey/`
  (validación JPG/PNG/WebP ≤5MB, misma que `SurveyHandler::handle_upload`) y devuelve la URL.
- `FormRenderer::handle_submit()`: los campos `file` ahora suben el archivo y el campo guarda la URL.
- `GfPostventaBridge::flat_incidents_from_fields()` (PURE): agrupa los campos planos del lead
  (`lugar_problema_N`/`problema_N` + la foto file de cada bloque, emparejada por el orden en config)
  en incidents con la misma forma que el repeater → el contrato emite "Lugar del problema #N / Problema
  #N / Fotografía #N: URL". Vacío si no hay problemas planos (rama DOCUMENTOS).
- `Forms::notify_submission()`: adjunta al correo los archivos reales de los campos file (URL→path
  local vía `SurveyHandler::resolve_attachment_paths`), igual que el survey.
- Tests: capture **571/0** · gf-postventa **59/0** (i17-i22) · core 217/0 · native 13/0. Sin release.

### 2026-09-09 (código fuente, SIN release) — Capture: MAQ_DUPE_FIELD_V1 (duplicar/clonar campos en el maquetador)
Solicitud del usuario: los campos de un formulario deben poder duplicarse/clonarse. Se añade el botón
⧉ "Duplicar campo (clon)" en cada campo del editor de campos del maquetador.
- `maquetador.php`: botón `.maq-dupe` en el header del campo; `dupeField(si,fi)` hace copia profunda
  (JSON clone) del campo e inserta la copia justo después del original, abriendo su edición.
  `uniqueFieldName(si,base)` genera un `name` único con sufijo `_N` si el base ya existe (evita que
  dos campos compartan name y se pisen en el capture). Guards de dragstart para que ⧉ no inicie drag.
- Render verificado en Playground. Tests run-capture **571/0** (25g1-25g3). Sin release.


### 2026-09-09 (código fuente, SIN release) — Capture: MAQ_TYPE_GUARD_V1 (guard de servidor anti-degradación dependent_select→text)
La degradación de `calle` (dependent_select → text) volvió a ocurrir aunque 2.5.3 (fix JS) estaba en
disco: el navegador puede usar el JS viejo en memoria/caché y reescribir el tipo al guardar. Fix JS
solo protege si corre el JS nuevo → se añade un GUARD EN EL SERVIDOR.
- `Forms::repair_dependent_fields()` (PURE): un campo con `depends_on` no vacío y type que NO soporta
  dependencia (`text`) es inconsistente en el motor → se repara a `dependent_select`. Se aplica en
  `sanitize()` (toda escritura/update/create) sobre `sections` y `fields_json`. Un `text` con
  depends_on no tiene sentido y rompería el desplegable de calles.
- Doble capa definitiva: (1) JS del maquetador no degrada tipos no listados (MAQ_TYPE_PRESERVE_V1,
  2.5.3); (2) el servidor repara cualquier degradación que llegue (JS viejo/caché/u otra vía).
- Tests run-capture **568/0** (25f9-25f12). Sin release.
- Nota hora: Chile en horario de verano (UTC-3); WP sigue con gmt_offset -4 (hora vieja).

### 2026-09-09 (código fuente, SIN release) — Capture: MAQ_TYPE_PRESERVE_V1 (el maquetador de formularios ya no degrada dependent_select/project_select a text)
El usuario detectó que el campo `calle` (dependent_select) del form 21 (lead) se corrompió a `text`
al guardar cambios de ancho/obligatorio en el maquetador. Causa raíz: `feature-forms-product.php`
`$field_types` no incluía `dependent_select`/`project_select`; el `<select class="maq-type">` del
editor solo lista TYPES conocidos → para un campo con tipo no listado el select caía a su primera
opción ('text') y al guardar `f.type = typeSel.value` sobrescribía el tipo real. Corrupción silenciosa
en cada guardado del maquetador de formularios (mismo patrón de degradación que la corrupción de texto
de TinyMCE: el cliente reescribe el dato).
- `feature-forms-product.php`: `$field_types` añade `project_select`, `dependent_select`, `heading`,
  `html`, `hidden` (con etiquetas; se muestran correctamente).
- `maquetador.php` (JS): `f.type` solo se sobrescribe si el select contiene el tipo real del campo
  (`typeSel.querySelector('option[value="' + (f.type||'') + '"]')`); si el editor no lo lista, el tipo
  se preserva. Compatible con campos de sistema (hidden, _*) y futuros tipos del motor.
- Producción acacios: form 21 `calle` restaurado a `dependent_select` conservando ancho (75) y
  obligatoriedad del usuario (backup `/home/customer/tmp/form21-calle-restore-20260909-010406.json`).
- Tests run-capture **564/0** (25f7/25f8). Sin release (agrupar con 2.5.2+).

### 2026-09-09 (PUBLICADO en 2.5.2) — Capture: fix fatal en conversión de tipo (CONVERSION_TYPES_V1)
La conversión de un form de tipo incident→lead (y cualquier cambio de tipo) explotaba con
TypeError fatal: `ConversationEngine::on_type_changed(): Argument #1 ($form_id) must be of type
int, array given`. `Forms::convert_type()` dispara el hook `estandar_suite_capture_form_type_changed`
con UN payload array (`do_action($payload)`, Forms.php:4043), pero el listener estaba registrado con
3 args `(int $form_id, string $from, string $to)` → el array caía en `$form_id`. Bug preexistente
(observado en tests el 01/09 y anotado sin corregir); el usuario lo activó en producción al convertir
la copia del form postventa (form 20) a formulario.
- `ConversationEngine::on_type_changed( array $payload )`: extrae `form_id`/`new_type` del payload;
  `form_id <= 0` se ignora. Registro con accepted_args=1. Compatible con cualquier listener futuro
  del mismo hook (todos reciben el payload).
- Tests run-capture **562/0** (14z1-14z4: payload array, form_id 0, payload vacío).
- Verificación en producción: conversión form 20 lead→incident→lead sin fatal (draft).
- Funcional: run-subtabs-functional.sh corregido (default/fallback = `configuracion`, no `diseno`
  del orden antiguo; grep case-insensitive en "Configuración") → **26/0**.

### 2026-09-08 (PUBLICADO en 2.5.1) — Capture: MAIL_HTML_V1 (cuerpos de correo del maquetador sin TinyMCE)
Causa raíz de la corrupción recurrente en textos de correo (form 18 acacios: "gracias" →
"gotected r-->acias"; mail_body "margin" → "maotected otected…r--&gt;gin"): los 4 cuerpos del
maquetador usaban wp_editor (TinyMCE) con `protect` de `{{...}}`; al re-serializar, ese mecanismo
incrusta marcadores mce:protected rotos DENTRO de palabras (no `<!--mce:…-->` completos → el guard
CONFIG_GUARD no los atrapa) y se acumulan con cada guardado.
- `estandar-capture/includes/templates/maquetador.php`: `es-corr-ar-body`, `es-corr-ar-body-rec`,
  `es-corr-df-body`, `es-corr-nf-body` pasan de `wp_editor()` a `<textarea>` HTML plano (mono, con
  el preview en vivo intacto). Eliminado `$es_mce` (config TinyMCE con protect). Retrocompatible:
  lo ya guardado se conserva; serialización igual (triggerSave es no-op sin editores).
- Verificado: render Playground (4 textareas, 0 tinymce.init) · run-capture **558/0**.
- Saneo producción acacios (config directo): form 18 `mail_body` restaurado a canonical (backup
  `/home/customer/tmp/form18-mailbody-fix-20260908-234306.json`); 0 corrupción en form 18.
- Sin release (pendiente de agrupar).

### 2026-09-08 (código fuente, SIN release) — Capture: MAIL_TEMPLATES_V1 Fase 2 (inyectar body en la plantilla de correo elegida)
Cablea los envíos a las plantillas del hub de Core (Fase 1). OFF por defecto: sin plantilla elegida
(form/proyecto/default global) el cuerpo sale idéntico a hoy. Contrato GF (`<html>`) nunca se toca.
- `estandar-capture/includes/Forms.php`: `decorate_with_template()` (PURE) — si
  `Core\Mail::resolve_template()` devuelve plantilla, quita `{{logo}}`/`{{footer_estandar}}` del body
  (la marca ahora la pone la plantilla) e inyecta el body en `{{content}}` vía `wrap_template()`;
  resuelve tokens de identidad ({{site_name}}/{{brand_color}}/{{footer_text}}) con la identidad del
  form. Doc completo `<html>` → intacto (protección contrato GF). Cableado en `notify_submission`
  (solo `!== gf_postventa`) y `autoresponse_submission` antes del envío.
- `estandar-capture/includes/SurveyHandler.php`: `send_completion_email()` decora en la rama branding
  (el contrato GF ya devolvió `<html>` y decorate lo conserva).
- Tests run-capture **558/0** (22m1-22m9). Sin release.
- Resuelve el "color huérfano": al elegir plantilla, la identidad se aplica vía header de plantilla.

### 2026-09-08 (código fuente, SIN release) — Core: MAIL_TEMPLATES_V1 Fase 1 (registro de plantillas de correo + editor + preview)
Base para centralizar el sistema de correos en Core (contexto: identidad/color "huérfana" en flujo
postventa — el color solo aplica vía {{logo}} y el contrato GF no lleva branding). FASE 1 aditiva y
en OFF: ninguna ruta de envío consume plantillas todavía. Registro + editor + resolución pura.
- `estandar-core/includes/Mail.php`: `TEMPLATES_OPTION` (`estandar_suite_core_mail_templates`),
  `mail_template_seed()` (plantilla base muy simple con slot {{content}}), `mail_templates()`
  (siembra base si vacío), `mail_template()`, `save_mail_templates()` (kses con guard),
  `wrap_template()` (PURE: header + body con content_style + footer, reemplaza {{content}}),
  `resolve_template()` (PURE: form config `mail_template`/'none' → skin proyecto → default global).
- `estandar-core/includes/Admin.php`: handlers `handle_save_mail_templates`, `handle_set_default_template`,
  `handle_delete_template` (nunca borra 'base').
- `estandar-core/includes/templates/core-admin.php`: nueva sub-vista `cc=plantillas` en Correo.
- `estandar-core/includes/templates/feature-mail-templates.php` (nuevo): lista + editor HTML por
  plantilla (header/body estilos/footer) + preview en vivo (iframe) + default global + ?new=slug.
- Tests run-core **217/0** (21r–21z2). Render y guardado verificados en Playground. Sin release.
- Fase siguiente (por tipología de form): que autoresponse/notify por form inyecten su contenido en
  {{content}} (con opción form/proyecto de elegir plantilla o 'none'); contrato GF siempre sin plantilla.

### 2026-09-08 (código fuente, SIN release) — Capture: POSTVENTA_PHOTO_ATTACH_V1 (el correo del operador lleva las fotos adjuntas)
Caso real postventa acacios (form 16, 12:43): el correo al operador llegaba con la foto solo como
URL en texto dentro del body Label:Value. Requisito: el operador recibe los archivos adjuntos reales.
- `estandar-capture/includes/SurveyHandler.php`: `resolve_attachment_paths()` (PURE, URL → ruta local
  bajo uploads, sin traversal) + `response_attachments()` (filtra legibles vía wp_get_upload_dir);
  `send_completion_email()` pasa `'attachments'` a `Core\Mail::send` (ruta del motor survey/incident
  = form 16 acacios). El body Label:Value NO cambia (la plataforma externa lo parsea); sin fotos →
  sin adjunto. El autoresponder al cliente NO lleva adjuntos (los subió él).
- Tests run-capture **549/0** (nuevos 22k1-22k8). Sin release.

### 2026-09-08 (código fuente, SIN release) — Capture: GF_BRIDGE_VISIBILITY_V1 (ocultar UI del puente GF si Gravity Forms no está instalado)
En acacios el plugin Gravity Forms fue desinstalado (quedan tablas residuales): el bloque
"Puente provisional Gravity Forms → Capture inactivo" del sub-tab Postventa solo ensuciaba el
admin. Sin embargo GfPostventaBridge NO es solo el puente GF: sus renderers del contrato
(`contract_from_capture`, `all_fields_table`, `field_rows`) alimentan el correo de postventa
NATIVA (form 16, `mail_contract='gf_postventa'`) aunque GF no exista → la clase NO se elimina.
- `estandar-capture/includes/GfPostventaBridge.php`: nuevo `ui_visible( bool $gf_active ): bool`
  (PURE) — la UI solo aplica cuando GF está presente.
- `estandar-capture/includes/Admin.php`: `render_gf_postventa_bridge()` retorna sin renderizar si
  `! GfPostventaBridge::ui_visible( class_exists('GFAPI') )`. El código del puente y los renderers
  del contrato se conservan intactos.
- Tests run-gf-postventa-bridge **53/0** (nuevos i15/i16). Sin release.


Feedback OCI con leads de AK: para atribuir el resultado de un chat de WhatsApp a su clic de Google
(hoy solo funciona si el teléfono del formulario coincide y la mayoría de contactos están guardados
por nombre), el click al botón ahora guarda la **entrada** desde la que se abrió el WhatsApp.
- `estandar-capture/includes/WhatsAppClickTracking.php`: schema **1.2.0** con columnas nuevas
  `slug varchar(64)` y `message varchar(512)` (prefill enviado por el botón/enlace). `record_click`
  lee y persiste `slug`+`message` del payload (dbDelta añade columnas sobre la tabla existente).
- `estandar-capture/includes/FloatingWhatsApp.php`: nuevo `resolved_slug()` (regla de página → rama →
  `global`, con slug derivado del basename en reglas path sin slug); `print_widget` emite
  `data-wa-slug`/`data-wa-message`; el JS envía `slug`+`message` en el POST a `/wa/click` y en el
  evento `whatsapp_click` de dataLayer. En enlaces wa.me sueltos extrae el `?text=` del href
  (slug `link`). Solo se captura el **prefill** (config del sitio), nunca el texto del usuario.
- Tests run-capture **541/0** (nuevos 27S: resolved_slug por regla path/slug/global + data attrs en
  el widget; 27z4b schema 1.2.0). Regresión run-core **207/0** y run-ads-data-manager **28/0**.
- Config de mensajes por entrada para AK: pendiente de aplicar en el backend del sitio (valores en
  `documentos/CONFIG_MENSAJES_WHATSAPP_POR_ENTRADA_AK.md` del proyecto 042). Sin release.

### 2026-09-07 (código fuente, 2.3.5 pendiente de release) — Updates: auto-update propio de la Suite
Tras publicar 2.3.4 al canal ningún sitio SSH se actualizó solo (mecanismo nativo de WP = cron
propio + transient `update_plugins`; no es inmediato y no corría). Decisión del usuario:
**auto-update automático en rodrigovaldes y todos los sitios**.
- `estandar-core/includes/Updates.php`: cron propio `estandar_suite_updates_auto` (cada 6 h,
  primera pasada ~10 min tras arranque; intervalo `cron_schedules` registrado) que con política
  `auto` (`is_auto`) refresca el manifiesto y actualiza **solo** los plugins de la Suite con
  versión mayor vía `update_plugin()` secuencial (`candidates()` PURE, candado
  `AUTO_LOCK_OPTION` TTL 15 min, try/finally, log best-effort). off/pin bloquean (`is_blocked`).
- Tests: `run-updates.php` ampliado **27/0** (candidatos, política, agendado en WP, guardas).
  Regresión run-core 202/0. `php -l` OK. Versión de fuente: 2.3.4 → release será **2.3.5**.
- Runbook: publicar 2.3.5 → **deploy UNA vez** (--deploy-all SSH + ak manual) para sembrar el
  código auto; de ahí en adelante publicar el release alcanza (sitios se auto-actualizan ≤6 h).

### 2026-09-07 (código fuente, 2.3.6 pendiente) — Capture: CONFIG_GUARD_V1 (saneo de config al guardar)
Prevención de la corrupción del config de forms (caso acacios form 17: marcadores TinyMCE
`<!--mce:*-->` + página de error de WP incrustada en `autoresponse.body`/`mail_body`). En
`estandar-capture/includes/Forms.php`, `Forms::sanitize()` (punto único de create/update) sanea el
config con `sanitize_config_text()`/`sanitize_config_tree()` (PURE): elimina marcadores TinyMCE y
corta documentos HTML completos pegados (`<!DOCTYPE`/`<html`); solo toca cadenas con marcadores.
run-capture 525/0 (10e1-10e4). Detalle: sesión 025.

### 2026-09-07 (release pipeline) — dry-run 2.3.4 VERDE + gates excluyen lib vendida
`./release.command --dry-run` → **EXIT 0** (no publicado): 45 runners de tests OK (incluye
run-event-bus 19/0, event-bus-harness 10/0, pdf-real-harness 6/0), preservación/smoke/seguridad/
símbolos/constantes/matrix PHP 8.2+8.4 (770 archivos)/temas/salud remota/headers/zip/WP-API OK.
Bump 2.3.3→2.3.4 probado y revertido. Fix gates (código de terceros vendido no pasa por gates
propios): `gate-constants-defined.php`, `gate-wp-api-compat.php` y **`gate-php-matrix.php`**
excluyen `includes/lib/dompdf/` (p. ej. thecodingmachine/safe con constantes de ext ssh2 ausentes;
lint serial ×PHP pasó de 770 a 224 archivos, ~35 s). Pendiente: publicar 2.3.4 con OK (piloto
acacios).

### 2026-09-07 (código fuente, SIN release) — S16: motor PDF único real (dompdf embebido en estandar-core)
Cierre del Document Engine. Auditoría: en el repo v2 NO había motor duplicado — único
`Estandar\Core\Communications\Documents\Pdf` (consumido por `LeadsPanel::export_pdf`); el legacy
estandar-quotes con su copia de dompdf fue purgado en la limpieza v2-only, y
`Pdf::dompdf_paths()` apuntaba a una ruta legacy inexistente → dompdf ausente → todo "PDF" era el
fallback de texto mínimo.
- **dompdf 3.1.6** vendido UNA sola vez en `estandar-core/includes/lib/dompdf/` (composer
  vendor-dir=".", autoload canónico `includes/lib/dompdf/autoload.php`; se podaron tests de los
  paquetes). ~13 MB (fonts DejaVu incluidas para render correcto). Licencia LGPL-2.1.
- `Core\Pdf` (`Communications/Documents/Pdf.php`): `dompdf_paths()` resuelve la ruta canónica
  (`ESTANDAR_SUITE_CORE_PATH` con fallback `WP_PLUGIN_DIR`, filtrable por
  `estandar_suite_core_dompdf_autoload`); `render()` usa dompdf real con postura segura
  (`isRemoteEnabled=false`, `isPhpEnabled=false`, `defaultFont=Helvetica`); si dompdf no está o
  falla (host PHP<8.1, ext ausente) → **fallback ruidoso** (do_action
  `estandar_suite_core_pdf_fallback`) + `minimal_pdf` de texto multi-línea legible.
- Verificación: harness funcional `estandar-core/tests/functional/pdf-real-harness.php` **6/0**
  (dompdf_available true, output `%PDF-1.7` real, >1800 B) + `event-bus-harness.php` **10/0** —
  ambos añadidos a GATE_TESTS. Regresión run-core **202/0**. Docs ESTANDAR_SUITE actualizado.
- Sin release. Detalle: sesión 025 proyecto 050.

### 2026-09-10 (código fuente, SIN release) — Brain: feedback a Meta (CAPI) reactivado
Completa el pipeline de feedback publicitario que quedó muerto al retirar el legacy
`estandar-signal-core` (último `META_UPLOAD_SUCCESS` 24-ago; cola v2 con 1156 pendientes
sin procesar). El token del pixel SÍ servía (`POST /{pixel}/events` → `events_received:1`).
- `estandar-brain/includes/MetaCapi.php` (nuevo, ns `Estandar\Brain`): exportador CAPI v2.
  Mapeo `lead_created→Lead`, `lead_qualified→LeadQualified`, `meeting_booked→Schedule`,
  `sale_closed→Purchase`; `lead_rejected` NO se exporta (no debe contar como conversión);
  `lead_created` server OFF por defecto (`send_lead`) para no duplicar el pixel del navegador.
  user_data con em/ph = **SHA-256 de los normalizados** (nunca el HMAC interno) + `fbc`/`fbp`/
  `external_id`; `event_id` = `entity_uuid`.
- `estandar-brain/includes/SignalQueue.php` (nuevo): procesador de
  `estandar_brain_signal_queue` con reintentos/backoff, cron horario
  `estandar_brain_process_signal_queue`; estados pending→exported|skipped|failed.
- `estandar-brain/includes/SignalLedger.php`: `on_capture` enriquecido (resuelve lead,
  `project_slug`, email/phone hash, fbc/fbp, external_id) + `on_feedback()` (calidad desde
  el feedback del operador) + esquema de cola con `next_run_at`/`updated_at` (VERSION 1.1.0).
- `estandar-core/includes/LeadsPanel.php`: emite `estandar_suite_lead_feedback` al guardar la
  calificación. `estandar-brain/includes/Admin.php`: campo `send_lead`. CLI:
  `wp estandar-suite brain signals-process|signals-backfill [--limit=N]` (nombres hermanos: WP-CLI
  no admite un comando con acción por defecto + subcomandos).
- Tests: `estandar-brain/tests/run-brain-exporters.php` **23/0** (nuevo). Regresión run-brain
  29/0. Gates: PHP_MATRIX/SCOPE/SYMBOL/CONSTANTS/HEADER_META/REST_LINK/WP_API_OK; smoke
  all-together `fatals_nuevos=0`.
- Pendiente: release/deploy; luego configurar `exportadores → meta` (pixel/token/test_event_code)
  y correr `signals-backfill` (en modo `test_event_code` primero). Detalle: 041/050.

### 2026-09-07 (código fuente, SIN release) — bus de eventos: survey_completed + quote_issued (S15)
Cierre del catálogo de eventos planificados del bus `Destinations`. Corrección documentada: el
bus SÍ corre en runtime para `capture` (`estandar_suite_capture_event`, emitido por
`Ingestion::ingest`) y para señales de Brain (`estandar_suite_signal_recorded`); faltaban
`survey.completed` y `quote.issued` del catálogo histórico (`submission.created` ya estaba
cubierto por `capture`).
- `estandar-core/includes/Destinations.php`: `canonical_events()` += `survey_completed`,
  `quote_issued` (catálogo allow-list de event_map; aditivo).
- `estandar-core/estandar-core.php`: listeners prio 20 (patrón capture/signal):
  `estandar_suite_survey_completed` → `dispatch_event('survey_completed','survey',session_id,…)`
  y `estandar_suite_quote_issued` → `dispatch_event('quote_issued','quote',id,…)`.
- `estandar-capture/includes/SurveyHandler.php`: `emit_completed(int $session_id,int $form_id,
  string $form_type='survey')` (payload mínimo sin PII) llamado en `handle_complete` al marcar
  la sesión completed.
- `estandar-flow/includes/Quotes.php`: `issued_payload(array $quote): array` (PURE, mínimo) +
  `emit_issued(array $quote)`; `send()` lo emite en la rama OK tras `status=sent`.
- Inerte sin mapeo: sin destino en event_map, `dispatch_event` no encola ni hace red → cero
  cambio de comportamiento por defecto en cualquier instalación.
- Tests: `estandar-core/tests/run-event-bus.php` standalone **19/0** (nuevo, catálogo/mapa/
  inercia/idempotencia; añadido a GATE_TESTS) + harness funcional `estandar-core/tests/
  functional/event-bus-harness.php` (Playground) **10/0** (destino webhook sink → ambos eventos
  encolan filas en `estandar_core_deliveries`). Regresión run-core **201/0** · run-capture
  **520/0** · run-flow **92/0**. `php -l` OK. Detalle: sesión 025 del proyecto 050.
- Pendiente: release y deploy (policy por sitio en release.command; piloto acacios).

### 2026-09-06 (código fuente, SIN release) — estandar-core: AutoDiag (Módulo A · estado honesto)
Cada instalación detecta sola cuándo algo deja de funcionar y lo muestra. Estados honestos:
ausencia de evidencia → UNKNOWN (nunca se inventa éxito). Es el "health profundo" de la Suite
(el `Health` estático/`doctor` queda intacto y convive; AutoDiag no repite php/wp/secret).
- `estandar-core/includes/AutoDiag.php` (nuevo, ns `Estandar\Core`, AUTO_DIAG_V1): núcleo PURE
  (`check_*` sobre `evidence`, `overall_from_checks`, `snapshot_from_evidence`) + `collect()`
  DB-guarded (nunca lanza). 14 checks agrupados (sistema/tracker/captura/esquema/integraciones):
  tracker (track_enabled + sesiones/pageviews últimos 7d + **sospecha de ceguera** si hay
  relleno externo reciente y el beacon no registra), capturas (`estandar_capture_submissions`
  no-spam, 7d), esquema por tabla (core/pageviews/sessions/capture/insights events-analytics-
  external, const de cada clase, nada hardcodeado), conexiones/destinos, errores de telemetría,
  cron de refresco, plugins instalados-vs-activos (caza el overwrite de deploy que deja core
  inactive). Overall OK/ATENCION/FALLO/UNKNOWN (unknown informativo no degrada si hay OK).
- Snapshot persistido en option `estandar_suite_core_selfcheck_snapshot` (TTL 12h; `force`),
  refrescado por cron diario `estandar_suite_selfcheck_daily` (init en plugins_loaded) + bajo
  demanda. Detección de instalaciones recientes (tablas vivas en 0) = UNKNOWN, no OK.
- REST `GET /estandar-suite/core/v1/selfcheck[?force=1]` (manage_options). CLI
  `wp estandar-suite core selfcheck [--refresh]`. UI: sub **Autodiagnóstico** en
  Core → Estado (resumen→autodiagnostico→datos→esquema→mapa), semáforo overall + botón
  "Ejecutar ahora" (nonce) + tabla de checks con nivel y motivo. Aditivo (catálogo de
  visibilidad y `$subs` sincronizados).
- NO duplica: `Health` (doctor/`/health`), Observability (insights), WhatsAppBot/ChatbotHealth
  (por catcher), telemetría (Eventos), embudo WebFunnel (insights). Sin tablas nuevas.
- Tests: `tests/run-auto-diag.php` **39/0** (nuevo, standalone; incluye 9a/9b: inducir
  tracker apagado cambia overall OK→ATENCION). Regresión run-core **201/0**. `php -l` OK en
  6 archivos tocados + `bash -n` release. **Verificación real en Playground:** snapshot real
  OK → `track_enabled=0` → ATENCION (tracker=Desactivado) → restaura → OK (3/3); render del
  sub UI 4/4. ⚠ wp-cli 2.12 + PHP 8.5 local rompe el registro de subcomandos de toda la
  Suite (pre-existente); verificación por harness wp-load, no por wp-cli.
- Pendiente: release y deploy a instalaciones (policy por sitio en release.command).
  Conexión futura (Módulo B/C): el radar/IA lee este snapshot vía REST.

### 2026-09-06 (código fuente, SIN release) — estandar-insights: embudo web visitas→leads (analytics equivalente GA)
Producto: el Insights lee VISITAS (sesiones/pageviews ya registradas por el tracker de
estandar-core) y los LEADS de estandar-capture, y los une en un embudo web con cuellos.
- `estandar-insights/includes/WebFunnel.php` (nuevo, ns `Estandar\Insights`): embudo
  SESIONES→PAGEVIEWS→LEADS. `daily()` reutiliza `Analytics::series_between()` (misma vista que
  el Panorama: espejos legacy + tablas vivas estandar_core_sessions/pageviews + ExternalDaily)
  y suma LEADS por día leyendo directo `estandar_capture_submissions` (no-spam, status active,
  mismo criterio que AdsDataManager). `compute()` porta la semántica de v1
  (estandar-analytics admin/funnel.php): tasa = etapa/anterior, **cuello cuando <30%**.
  `snapshot()` = rango + conteos + embudo + serie diaria. Sin tablas ni migración nuevas.
- REST (`includes/Rest.php`): `GET insights/v1/funnel/web[?from&to]` y
  `GET insights/v1/analytics/visits[?from&to]` (serie diaria + totales), `manage_options`.
- CLI (`includes/Cli.php`): `wp estandar-suite insights funnel-web [--from --to]` y
  `... insights visits [--from --to]`.
- UI: sub-sub-tab **Embudo web** en Analytics (ssubs + bloque en feature-analytics.php):
  presets de rango (reusa panorama_presets/panorama_range), KPI cards y tabla de etapas con
  badge ⚠ Cuello.
- NO duplica: tracker de visitas (estandar-core, intacto), funnel comercial
  (Funnel.php/estandar-flow, intacto), lectura de leads con calidad (core/v1/ads-oci/captures,
  intacta).
- Tests: `tests/run-web-funnel.php` 21/0 (nuevo, standalone), agregado a GATE_TESTS del
  release. Regresión: run-insights 35/0. `php -l` OK en los 6 archivos tocados.
- Pendiente: release y deploy a instalaciones (ver policy por sitio en release.command).

### 2026-09-06 (código fuente 2.2.8, SIN release) — relleno externo + exclusión tráfico propio
Dos adiciones a la base 2.2.8 (pendientes de release):
1. **Relleno de días sin registro con data del hosting (ExternalDaily):**
   - `estandar-insights`: nueva tabla `estandar_insights_external_daily` (metric/date/value/
     origin) + clase `ExternalDaily`. `Analytics::series_between()` rellena SOLO los días en
     que la fuente real (espejo+viva) es 0 con estos valores (nunca pisa datos reales).
   - Carga automática en boot: seed por host `includes/data/external-sessions/{host}.json`
     (se importa una vez, opción `estandar_suite_insights_external_seeded_files`). Incluido
     seed de acacios.cl (sep 1-6, visitantes únicos/páginas de SiteGround).
   - CLI: `wp estandar-suite insights external import|status|purge [--metric= --file=]`.
2. **Excluir tráfico propio (default = excluido):** `estandar-core` SiteMap settings suma
   `exclude_self=1` (default) y `exclude_ips=[]`. `rest_pageview` descarta
   (`ignored:self`) las peticiones de admin logueado (`manage_options`) o de IPs propias.
   UI en Ajustes del mapa (checkbox + input de IPs). Con `exclude_self=0` se incluyen.
Pendiente: release y deploy; seeds de otros hosts (estandar/guillaume/rodrigovaldes/
akcontadores) requieren sus números diarios de SG (recopilar o capturar).

### 2026-09-06 (código fuente, pendiente release) — política de updates por instalación
`estandar-core` Updates: control por sitio de si la Suite actualiza o no, sin tocar el canal.
- Opción `estandar_suite_updates_policy` (default `auto`): `auto` (recibe updates) | `off`
  (esta instalación NO ofrece updates de la Suite) | `pin:X.Y.Z` (congelado en esa versión).
- Gate en `Updates::check_update()` (no ofrece update si `is_blocked`), manifest expone
  `policy` + `blocked` por plugin, `set_policy` limpia el transient `update_plugins`.
- UI: panel Actualizaciones (Core → Avanzado → Actualizaciones) selector de política.
- CLI: `wp estandar-suite core updates policy [--set=auto|off|pin:X.Y.Z]`.
- Tests: run-updates 15/0, run-core 201/0, verificación policy POLICY_OK.

### 2026-09-06 (release.command) — administración de instalaciones
`release.command` gana modos de administración por sitio (no hacen release ni bump; usan
el canal v2 actual):
- `--status` — versión local + política por instalación (matriz SSH + akcontadores REST si
  hay env ESTANDAR_AK_APP_PASS).
- `--deploy-all` / `--deploy=LISTA` — despliega la versión del canal vía `wp plugin install
  <slug>.zip --force` a las instalaciones SSH, RESPETANDO la política local del sitio
  (off/pin:… → SKIP; akcontadores manual). Activa `estandar-core` tras overwrite (gotcha).
- `--policy=SITIO:VAL` — fija `estandar_suite_updates_policy` (auto|off|pin:X.Y.Z) vía
  wp-cli. Uso previsto: rollout controlado / desactivar sitios al terminar la suscripción
  o cuando un cliente deja de serlo.
- Matriz de instalaciones en `SITES` (nombre|user|host|port|key|wppath|tipo). Fix: `ssh -n`
  en el helper (evita que ssh consuma el stdin del loop). Validado: `bash -n`, `--status`
  real (acacios/estandar/guillaume 2.3.1, rodrigovaldes 2.2.8 atrás), `--deploy --dry-run`.

### 2026-09-06 (código, pendiente release) — cron diario + auto-update controlado
`estandar-core` Updates (caso rodrigovaldes.cl no recogía el 2.3.2: nadie abría el panel y
el manifiesto quedaba cacheado viejo). Adiciones:
- Cron diario `estandar_suite_updates_daily_refresh` → `Updates::refresh()`; se re-agenda
  solo si falta (`ensure_cron` en `boot`) → sobrevive a overwrites/reinstalaciones.
- Auto-update de la Suite vía filtro WP `auto_update_plugin` SOLO con política `auto`
  (off/pin → false). Default auto → los sitios "recogen" solos en background.
- Verificado: run-updates 15/0, run-core 201/0, CRON_OK (agendado + gate política).
