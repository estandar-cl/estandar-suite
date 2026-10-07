<?php
/**
 * Plugin Name: Estandar Pop
 * Description: Sistema de popups con Gutenberg. Workflows First UI — v10.5.3.
 * Version: 13.0.173
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Author:      Luis Valdés de Estandar Studios CL
 * Text Domain: estandar-pop
 * Update URI:  https://updates.estandar.cl
 *
 * @package EstandarPop
 * @version 10.5.4
 *
 * === Changelog v10.5.4 ===
 * - FIXED: Multiple Popup blocks on same page (openPopup now works without cfg.popups)
 * - FIXED: engine.js now handles Gutenberg blocks independently
 * - FIXED: Frequency check only applies to classic popups, not blocks
 * - TESTED: 2+ blocks on same page now work correctly
 *
 * === Changelog v10.5.3 ===
 * - FIXED: save() ahora genera HTML serializable (era null, no se guardaba en BD)
 * - FIXED: Agregado script y style para frontend (engine.js se carga automáticamente)
 * - FIXED: Selector mejorado con lista de popups (SelectControl en lugar de TextControl)
 * - FIXED: render_callback ahora renderiza correctamente en frontend
 * - IMPROVED: Frontend enqueua correctamente engine.js cuando bloque está presente
 * - TESTED: Bloque ahora aparece en frontend y popup se abre al click
 *
 * === Changelog v10.5.2 ===
 * - NEW: 🎯 Bloque Gutenberg "Popup Picker" para insertar popups directamente en páginas
 * - NEW: Selector automático de popups en el panel derecha del editor
 * - NEW: Vista previa de popup en tiempo real
 * - NEW: Modos de visualización: Botón y Banner
 * - IMPROVED: UX completamente intuitiva (sin CSS ni conocimiento técnico)
 * - FIXED: apiVersion 3 para compatibilidad WordPress 6.9+
 * - COMPAT: 100% backward compatible con v10.5 popups
 *
 * === Changelog v10.5.1 ===
 * - NEW: 🔍 Botón "Detectar elementos" para encontrar clases CSS automáticamente
 * - NEW: Validación inteligente que detecta errores comunes (ej: ">" al inicio)
 * - NEW: Sugerencias dinámicas cuando no hay coincidencias de selector
 * - NEW: Help text mejorado con ejemplos contextuales
 * - IMPROVED: Detección de errores de sintaxis CSS comunes
 * - IMPROVED: Sugerencias de selectores similares en tiempo real
 * - IMPROVED: Mensajes de validación más descriptivos y útiles
 * - FIXED: Error "Selector válido pero sin coincidencias" ahora ofrece soluciones
 * - COMPAT: 100% backward compatible con v10.5 popups
 *
 * === Changelog v10.5.0 ===
 * - REFACTOR: UI/UX completa de sidebar (Workflows First)
 * - NEW: MethodPicker para elegir forma de apertura (Click/Automático/Manual)
 * - NEW: ElementPickerControl con validación CSS en tiempo real
 * - NEW: PreviewPane para ver popup mientras editas
 * - NEW: Instrucciones inline con snippets copy-paste
 * - NEW: Meta field _ep_open_method para claridad de modelo
 * - IMPROVED: Help texts simplificados (sin conceptos avanzados)
 * - IMPROVED: Orden de paneles (Método destacado primero)
 * - IMPROVED: Mobile responsive sidebar
 * - COMPAT: 100% backward compatible con v10.4 popups
 *
 * === Uso Rápido (v10.5+) ===
 *
 * 1. Click en botón:
 *    - Nuevo popup → Método: "👆 Click en botón"
 *    - Selector: .mi-clase
 *    - Copia código que aparece
 *
 * 2. Automático (tiempo/scroll/inactividad):
 *    - Método: "⏱️ Automático"
 *    - Rellena números deseados
 *    - Listo
 *
 * 3. Manual (data-ep-open / JavaScript):
 *    - Método: "⚙️ Uso manual"
 *    - Copia snippet que aparece
 *    - Integra en tu código
 */

defined('ABSPATH') || exit;

define('EP_VERSION', '13.0.173');
define('EP_PATH',    plugin_dir_path(__FILE__));
define('EP_URL',     plugin_dir_url(__FILE__));

require EP_PATH . 'includes/post-type.php';
require EP_PATH . 'includes/frontend.php';
require EP_PATH . 'includes/admin.php';
require EP_PATH . 'includes/gutenberg-popup-block.php';
