# Estandar Pop

Sistema de popups con Gutenberg — Workflows First Edition. Versión unificada: 13.0.173.

## Inicio rápido

### Click en un botón
1. WordPress Admin → Popups → Nuevo (título + contenido).
2. Panel "🎯 Cómo se abre" → **👆 Click en botón** → campo "¿En qué elemento?": `.mi-boton`.
3. Código: `<button class="mi-boton">Abrir Popup</button>`.
4. Publicar.

### Automático (tiempo/scroll/inactividad)
- Panel "Cómo se abre" → **⏱️ Automático** → "Después de (segundos)": `5`, o "Al hacer scroll (%)": `50`, o "Si el usuario no se mueve (segundos)": `30`.

### Manual (JS)
```javascript
document.dispatchEvent(new CustomEvent("estandar_pop:open", { detail: { id: 123 } }));
```

## Configuración avanzada

- **Segmentación:** IDs de páginas, URL contiene, rango de fechas. Vacío = todo el sitio.
- **Frecuencia:** Siempre / Por sesión / 7 días (default) / Solo una vez / Personalizado.
- **Diseño:** Pequeño/Mediano/Grande/Pantalla completa, overlay, animación (Fade/Slide/Ninguna), ancho manual.
- **CSS avanzado:** CSS puro por popup; modo heredado o encapsulado.

## Para desarrolladores

- **Shortcode:** `[ep_open id="123"]Abrir Popup[/ep_open]`
- **data-ep-open:** `<button data-ep-open="123">Abrir Popup</button>`
- **Meta fields:** `_ep_open_method`, `_ep_triggers`, `_ep_design` (ver histórico).

## Analytics

Registra vistas, aperturas, clicks y submits. Ver columna "Aperturas" en el listado de Popups.

## Estado en la Suite

- **Clasificación portfolio:** ACCESORIO (UX) + COMPATIBILIDAD LEGACY — MANTENER POR COMPATIBILIDAD.
- **Equivalente v2:** `Capture\Popups` (solo schema, sin renderizado). Usado en sitios cliente.

## Licencia

Propietario — Estandar Studio
