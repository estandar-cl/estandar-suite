# Proyectos de Arquitectura — Estandar Suite

## Proyecto 1: `estandar-pdf-service` — Extraer generación PDF de Quotes

**Estado:** Aprobado, pendiente de implementación

### Objetivo
Reducir `estandar-quotes` de 138K a ~3K líneas extrayendo el motor de PDF a un plugin independiente.

### Arquitectura objetivo

```
estandar-quotes (3K líneas)
  │
  ├── Document_Engine::render_print() → HTML con @media print
  ├── interface Estandar_Quotes_PDF_Adapter  (ya existe)
  │
  └── Requires Plugins: estandar-pdf-service  (opcional)
         │
         ▼
estandar-pdf-service (70K líneas)
  └── Estandar_PDF_Service::generate(string $html): string
      └── dompdf (única copia, sin duplicar)
```

### Cambios necesarios

1. Eliminar `vendor/dompdf/` e `includes/lib/dompdf/` (duplicado) de Quotes
2. Crear `estandar-pdf-service` como plugin independiente
3. Quotes declara `Requires Plugins: estandar-pdf-service`
4. Sin PDF Service instalado: Quotes funciona con botón "Imprimir PDF" vía navegador (`window.print()`)
5. Con PDF Service instalado: Quotes genera PDFs automáticos para email

### Impacto
- Quotes: 138K → 3K líneas (-98%)
- Sin pérdida de funcionalidad: PDFs automáticos requieren el service; sin él, el operador imprime manualmente
- dompdf se mantiene en un solo lugar, actualizable independientemente

---

## Proyecto 2: Unificación — 20 plugins → 5 plugins

**Estado:** Aprobado, pendiente de implementación

### Objetivo
Consolidar la suite de ~20 plugins en 5 plugins funcionales, manteniendo toda la funcionalidad existente y sin pérdida de datos en producción.

### Categorías

| # | Nombre | Plugins que absorbe | Rol |
|---|---|---|---|
| 1 | `estandar-showcase` | experience-blocks, pop, kickoff (parcial) | Presentación, diseño, efectos, "la ropa linda" |
| 2 | `estandar-capture` | forms, chatbot | Captura de leads, formularios, conversación |
| 3 | `estandar-brain` | leads, analytics, seo-intelligence, signal-core, cpt-manager | Análisis, scoring, pipeline comercial, auditoría |
| 4 | `estandar-voice` | notifications, destinations, secure-share, firmas, wp-exporter, quotes, delivery-locator, website-scanner | Emisores: notificaciones, export, reportes, webhooks |
| 5 | `estandar-core` | primer, acceptance, duplicate-post, kickoff (resto) | Runtime base, menú unificado, CLI, settings, utilidades |

### Requisitos no negociables

1. **Cero pérdida de datos en producción.** Tablas, opciones y post types mantienen sus nombres. Solo cambia qué plugin las registra.

2. **Hooks y filtros preservados.** Todos los `estandar_*` hooks mantienen nombre, firma y orden de prioridad. El consumidor externo no nota el cambio.

3. **Tokens, APIs y claves preservados.** `wp_options` no se migran, se siguen leyendo de donde están.

4. **CLI unificada.** Todos los comandos bajo `wp estandar`:
   ```
   wp estandar forms list
   wp estandar leads export 618 --context=ai
   wp estandar quotes generate --lead=618
   wp estandar doctor
   ```

5. **Settings page unificada.** Extender `estandar_core_diagnostics` como settings central.

6. **Activación granular.** Que se pueda activar `estandar-capture` sin `estandar-voice`. Cada plugin funcione con sus dependencias mínimas.

7. **Rollback posible.** El deploy produce nuevos plugins. Los viejos se desactivan pero no se borran hasta verificar.

### Riesgos identificados

1. **Quotes (138K) y website-scanner (75K) son muy pesados.** Al unificar en `estandar-voice`, ese plugin cargaría demasiado código en cada request. Solución: usar autoloader condicional (solo carga clases cuando se invocan, no en cada request).

2. **Dependencias en cadena.** Leads depende de Forms. Quotes depende de Leads. Si se instala `estandar-voice` sin `estandar-capture`, Quotes no funciona. Solución: `Requires Plugins` de WordPress nativo.

3. **Actualizaciones.** Hoy cada plugin tiene su propio ciclo. Unificados, un cambio en Forms requiere deploy de todo `estandar-capture`. Solución: versionado semántico interno con changelog por módulo.

4. **Debugging.** Con 20 plugins, un error deja claro qué módulo falló. Con 5, hay que identificar el submódulo. Solución: namespaces PHP por dominio (ej: `Estandar\Capture\Forms\Handler`).

### Plan de implementación sugerido

1. **Fase 0:** Extraer `estandar-pdf-service` (Proyecto 1). Esto limpia Quotes antes de cualquier merge.
2. **Fase 1:** Unificar `estandar-core` (primer + utilidades pequeñas). Es el de menor riesgo y todos dependen de él.
3. **Fase 2:** Unificar `estandar-capture` (forms + chatbot). Probar en SALADEPRUEBAS con formularios reales.
4. **Fase 3:** Unificar `estandar-brain` (leads + analytics + SEO + signal). El más complejo por el pipeline comercial.
5. **Fase 4:** Unificar `estandar-voice` (quotes + destinations + notifications + resto). El más pesado.
6. **Fase 5:** Unificar `estandar-showcase` (experience-blocks + pop). El más simple.

Cada fase incluye: migración → test suite → deploy en SALADEPRUEBAS → verificación → producción.
