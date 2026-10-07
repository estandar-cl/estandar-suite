# Estandar Pop v10.5.0
**Sistema de popups con Gutenberg — Workflows First Edition**

---

## 🚀 Inicio Rápido

### Caso 1: Popup que se abre al hacer click en un botón

1. **Crear popup**
   - WordPress Admin → Popups → Nuevo
   - Título: "Mi Popup"
   - Contenido: Escribe lo que quieras mostrar

2. **Configurar apertura**
   - Sidebar derecha → Panel "🎯 Cómo se abre"
   - Selecciona: **👆 Click en botón** (ya está seleccionado por defecto)
   - Campo "¿En qué elemento?": Escribe `.mi-boton`

3. **Instrucciones automáticas**
   - Aparecen dos opciones:
     - **Opción 1**: Si el botón ya existe → agrega clase
     - **Opción 2**: Crea un botón nuevo → copia código

4. **Ejemplo:**
   ```html
   <button class="mi-boton">Abrir Popup</button>
   ```

5. **Publicar y listo ✓**

---

### Caso 2: Popup automático (después de X segundos)

1. Crear popup (igual a arriba)

2. Configurar apertura
   - Panel "🎯 Cómo se abre" → **⏱️ Automático**
   - "Después de (segundos)": `5`
   - Guardar

3. El popup se abrirá automáticamente a los 5 segundos en todas las páginas

---

### Caso 3: Popup con scroll (cuando usuario hace scroll al 50%)

1. Panel "🎯 Cómo se abre" → **⏱️ Automático**
2. "Al hacer scroll (% de página)": `50`
3. Guardar

---

### Caso 4: Popup si usuario está inactivo

1. Panel "🎯 Cómo se abre" → **⏱️ Automático**
2. "Si el usuario no se mueve (segundos)": `30`
3. Guardar → Se abre si están 30s sin mover el mouse/teclado

---

### Caso 5: Control manual con JavaScript

1. Panel "🎯 Cómo se abre" → **⚙️ Uso manual**
2. Ve dos métodos:

**Método A: Atributo data-ep-open**
```html
<button data-ep-open="123">Abrir Popup</button>
```
Reemplaza `123` con el ID del popup (ves en URL al editar)

**Método B: Evento JavaScript**
```javascript
document.dispatchEvent(new CustomEvent("estandar_pop:open", { 
  detail: { id: 123 } 
}));
```

---

## 📋 Configuración Avanzada

### Segmentación: Mostrar popup solo en páginas específicas

Panel → **Segmentación**

- **IDs de páginas**: `12, 45, 89` (solo en esas páginas)
- **URL contiene**: una por línea (ej: `/blog`, `/productos`)
- **Fecha inicio/fin**: Mostrar solo en rango de fechas

Dejar vacío = mostrar en todo el sitio

---

### Frecuencia: Con qué frecuencia mostrar

Panel → **Comportamiento**

- **Siempre**: Cada visita (puede molestar)
- **Por sesión**: Una vez por sesión del usuario
- **7 días**: Una vez cada 7 días (default)
- **Solo una vez**: Nunca más aparece (para usuarios que vieron)
- **Personalizado**: Define tus propios días

---

### Diseño: Tamaño, overlay, animación

Panel → **Diseño**

- **Tamaño**: Pequeño/Mediano/Grande/Pantalla completa
- **Overlay**: Fondo oscuro detrás del popup (recomendado)
- **Animación**: Fade/Slide/Ninguna
- **Personalizado**: Define ancho manual (ej: `600px` o `80%`)

---

### Vista Previa

Mientras editas, usa el panel **"👁️ Vista Previa"** para:
- Ver cómo se vería el popup
- Probar botón de cierre
- Validar tamaño y animación

---

## 🎨 CSS Personalizado

Panel → **CSS avanzado**

Escribe CSS puro. Se aplica **solo a este popup**:

```css
.mi-popup-title {
  color: red;
  font-size: 24px;
}

button {
  background: green;
}
```

**Opciones CSS:**
- **Heredar estilos**: El popup usa los estilos de tu tema
- **Encapsulado**: CSS aislado (recomendado si tienes conflictos)

---

## ✨ Nuevas Características v10.5

✅ **MethodPicker** — Elige claramente cómo abre el popup  
✅ **Validación en tiempo real** — Sabe si tu selector CSS existe  
✅ **Instrucciones inline** — Código copy-paste listo  
✅ **Preview en sidebar** — Ve popup mientras editas  
✅ **Help simplificado** — Sin jerga técnica  
✅ **Mobile responsive** — Funciona en phones  

---

## 🔍 Solución de Problemas

### "Hice click pero el popup no abre"

1. Verifica que el popup esté **Activo** (toggle en "General")
2. Verifica que el **selector CSS sea correcto**
   - En panel "Cómo se abre" aparece validación
   - Busca elemento en inspector (F12)
3. Verifica que el **elemento exista en la página**
4. Recarga la página (cache)

### "El popup no se ve bien"

1. Prueba en **Vista Previa** (panel derecho)
2. Ajusta **Tamaño** en panel Diseño
3. Revisa **CSS personalizado** (¿hay conflictos?)
4. Intenta modo **Encapsulado** en CSS avanzado

### "Se abre en todas las páginas, pero solo quiero en una"

1. Ve a panel **Segmentación**
2. Agrega **ID de página específica**
3. O agrega **URL contiene** con parte de la URL

### "Quiero múltiples formas de abrir"

⚠️ Por ahora, **elige una**. Si activas Automático + Click, puede confundir:
- Usa un método principal
- Para alternativas, crea popup adicional

---

## 📊 Analytics

El plugin registra automáticamente:
- **Vistas**: Cuántas veces se cargó la página con el popup
- **Aperturas**: Cuántas veces fue abierto
- **Clicks**: Clicks dentro del popup
- **Submits**: Envíos de formulario dentro

Ver en listado Popups → columna "Aperturas"

---

## 🔗 Métodos de Integración

| Método | Uso | Ejemplo |
|--------|-----|---------|
| **CSS Selector** | Click en elemento | `.btn-popup` |
| **data-ep-open** | Atributo HTML | `data-ep-open="123"` |
| **Automático** | Sin acción usuario | Tiempo/Scroll/Inactividad |
| **JavaScript** | Evento customizado | `estandar_pop:open` |

---

## ⚙️ Para Desarrolladores

### Hook: Abrir popup desde PHP/JS

```javascript
document.dispatchEvent(new CustomEvent('estandar_pop:open', {
  detail: { id: 123 }
}));
```

### Meta fields (WordPress)

```php
// Leer configuración
$open_method = get_post_meta($popup_id, '_ep_open_method', true);
$triggers = json_decode(get_post_meta($popup_id, '_ep_triggers', true), true);
$design = json_decode(get_post_meta($popup_id, '_ep_design', true), true);
```

### Shortcode

```php
[ep_open id="123"]Abrir Popup[/ep_open]
```

---

## 📝 Changelog

### v10.5.0 (2026-06-16)
- ✨ UI/UX refactorization (Workflows First)
- ✨ MethodPicker component
- ✨ Real-time CSS selector validation
- ✨ Preview pane
- ✨ Inline instructions with code snippets
- 🔄 100% backward compatible

### v10.4.2
- Minor fixes

---

## 📞 Soporte

Preguntas o issues:
- Revisa este README
- Inspecciona el popup en devtools (F12)
- Verifica que WordPress 6.4+ esté actualizado

---

**Hecho con ❤️ por Estandar Studios CL**
