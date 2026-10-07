# AK — ARTEFACTO EJECUTABLE GTM (Fase A · medición online)
**Objetivo:** 1 envío = 1 lead `es_leads` = 1 conversión Ads. · **No publicar hasta completar checklist.**
Contenedor `GTM-TJCMH7ZB`. Valores verificados en código + DOM en vivo.

## Datos verificados
- Evento real emitido por Estandar Forms: **`estandar_form_success`** (`estandar-forms-public.js:632`). *(No existe `estandar_forms_submit`.)*
- Payload dataLayer: `form_id, form_name, project_slug, page_url`.
- **form_id del formulario de cotización = `2`** (hidden `estandar_form_id`, submit "Solicitar cotización").
- gclid online lo aporta **Conversion Linker** (cookie `_gcl`), no el dataLayer.
- Anti-spam ya presente en el form: reCAPTCHA + honeypot.

---

## 1. VARIABLE (DLV)
- **Nombre:** `DLV - form_id`
- **Tipo:** Variable de capa de datos
- **Data Layer Variable Name:** `form_id`
- **Versión:** 2 · Default: (vacío)

## 2. TRIGGER (reutilizar el existente `estandar_forms_submit`, repuntado)
- **Tipo:** Custom Event
- **Event name:** `estandar_form_success`   ← cambiar (hoy dice `estandar_forms_submit`)
- **This trigger fires on:** Some Custom Events → **`DLV - form_id` equals `2`**
- (Renombrar el trigger a `CE - estandar_form_success - cotizacion` para claridad.)

## 3. TAG (nuevo)
- **Nombre:** `Google Ads - Lead Estandar Forms`
- **Tipo:** Google Ads Conversion Tracking
- **Conversion ID:** `384378550`  (cuenta AK)
- **Conversion Label:** `<el de la conversión nueva A1>`  ← de Ads (paso 0)
- **Enhanced Conversions:** dejar OFF en esta fase (email/phone no están en dataLayer; no bloquea)
- **Trigger:** el del paso 2

## 0. CONVERSIÓN ADS (requisito del tag — crear primero)
- **Acción:** Importar no; **Sitio web / desde GTM** → "Enviar formulario de clientes potenciales"
- **Nombre:** `Lead Estandar Forms`
- **Categoría:** Cliente potencial (Submit lead form)
- **Recuento:** Una · **Valor:** sin valor (lead) · **Ventana:** 90 días
- Copiar el **Conversion Label** generado → al tag (paso 3).
> Conversión NUEVA y dedicada (baseline limpio; no reutilizar la histórica `sw1ACM2v…`).

---

## CHECKLIST DE VALIDACIÓN (Preview, sin publicar)
1. ☐ GTM **Preview** sobre `akcontadores.cl/contacto` (o home).
2. ☐ Enviar el form de cotización con datos de prueba (marcar Nombre "PRUEBA").
3. ☐ Tag Assistant: aparece evento **`estandar_form_success`** con `form_id=2`.
4. ☐ El tag **`Google Ads - Lead Estandar Forms` dispara 1×** (no en otros forms, no en `form_submit`).
5. ☐ Verificar lead en **`es_leads`** (1 fila, gclid presente si vino de Ads).
6. ☐ Tras publicar: en Ads, la conversión "Lead Estandar Forms" registra el evento (24–48h).

## ROLLBACK
- **Antes de publicar:** "Abandon changes" del workspace → queda v8 intacta. Cero impacto.
- **Después de publicar:** GTM → **Versions → re-publicar v8**. En Ads: pausar la conversión nueva. `es_leads` nunca se toca.

---

## LAS 3 ACCIONES PARA DEJAR LA MEDICIÓN ONLINE LISTA PARA PUBLICAR
1. **Ads:** crear la conversión `Lead Estandar Forms` (paso 0) y copiar su **Conversion Label**.
2. **GTM (workspace, sin publicar):** crear `DLV - form_id`; repuntar el trigger a `estandar_form_success` + condición `form_id=2`; crear el tag con el label del paso 1.
3. **QA en Preview** (checklist 1–5). Si pasa → **publicar GTM** → luego pausar/eliminar las heredadas (metform tag, Renta CF7, Compra Wena/Piola/Crack) **solo tras confirmar** que la nueva registra.

> Con esto la cadena **Estandar Forms → Estandar Leads → Estandar Analytics → Google Ads** queda limpia y reemplaza el tracking heredado. OCI = activación operativa posterior (Fase B), no proyecto técnico.
