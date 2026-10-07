# ADR-001: Hydration Pipeline Architecture

**Estado:** Aprobado  
**Fecha:** 2026-07-22  
**Contexto:** Sistema de cotizaciones estandar-quotes  
**Versión del contrato:** `estandar_quote_document@1`

---

## Decisión

Se adopta un pipeline de hidratación declarativo como el único mecanismo de construcción de cotizaciones, reemplazando los métodos manuales y divergentes que existían anteriormente.

## Fundamentos

- El flujo anterior tenía dos implementaciones de `document_defaults()` (Admin y Gateway) que producían resultados inconsistentes.
- La lógica de hidratación estaba acoplada a los controladores, impidiendo su reutilización por nuevos perfiles comerciales.
- No existía un modelo intermedio entre los datos de origen y la entidad persistente, dificultando la validación y el snapshot.

## Consecuencias

### Positivas

- Consistencia: todos los caminos de creación (Admin, Gateway) producen drafts idénticos.
- Extensibilidad: nuevos perfiles solo requieren registrar Stages y mappings.
- Testeabilidad: cada Stage puede probarse de forma aislada.
- Congelamiento determinístico: el SnapshotStage garantiza reproducibilidad de PDF/Delivery.

### Negativas

- Mayor número de clases (11 nuevos archivos).
- Curva de aprendizaje inicial para entender el pipeline y sus políticas de prioridad/sobrescritura.

---

## Decisiones específicas

### 1. Hydration Pipeline como único mecanismo de construcción

**Decisión:** Toda cotización debe construirse exclusivamente a través de `Estandar_Quotes_Hydration_Pipeline::hydrate_new()` o `hydrate_from_quote()`.

**Prohibido:** Llamar directamente a `Profile::defaults()` para inicializar un draft, o construir documentos parcialmente fuera del pipeline.

**Archivos:** `class-estandar-quotes-hydration-pipeline.php`

---

### 2. QuoteDraft como contrato canónico durante la hidratación

**Decisión:** `Estandar_Quotes_Quote_Draft` es el único modelo utilizado durante toda la construcción. Las entidades persistentes (`es_quotes`) solo se escriben después de que el pipeline y la validación hayan finalizado.

**Prohibido:** Modificar la base de datos desde cualquier Stage.

**Archivos:** `class-estandar-quotes-quote-draft.php`

---

### 3. Persistencia post-validación

**Decisión:** La entidad persistente (`es_quotes` row) solo se genera después de que `ValidationStage` (prioridad 60) haya completado su ejecución.

**Flujo:** `hydrate_new()` → `execute()` → `[Lead → Context → Unit → Form → Settings → Finance → Validation → Snapshot]` → `Repository::initialize_draft()` o `save_draft()`.

---

### 4. SnapshotStage congela toda la información

**Decisión:** `SnapshotStage` (prioridad 70) captura el documento completamente normalizado con SHA-256. El snapshot almacenado en `es_quote_revisions.snapshot_json` es la única fuente de verdad para revisiones, PDF y Delivery.

**Garantía:** El snapshot no contiene referencias a `get_option()`, `wpdb`, APIs externas, ni funciones de tiempo no determinísticas. Es autocontenido y reproducible.

---

### 5. Stages con responsabilidad única y sin acoplamiento

**Decisión:** Cada Stage implementa `Estandar_Quotes_Hydration_Stage`. Ningún Stage referencia a otro Stage. La comunicación entre Stages ocurre exclusivamente a través del objeto `QuoteDraft`.

**Prohibido:** Llamar a métodos de otro Stage, o depender del orden de registro (el orden lo determina `priority()`).

**Archivos:** `interface-estandar-quotes-hydration-stage.php`

---

### 6. Composición por prioridades (Open/Closed)

**Decisión:** El orden de ejecución del pipeline se determina por `Stage::priority()` y `usort`. Nuevos Stages pueden agregarse mediante `Hydration_Pipeline::register()` sin modificar Stages existentes.

**Filtro de extensibilidad:** `estandar_quotes_hydrator_stages` permite a plugins externos registrar Stages adicionales.

**Riesgo conocido:** PHP < 8.0 no garantiza estabilidad de `usort` para prioridades iguales. Mantener prioridades distintas.

---

### 7. FormStage como intérprete declarativo

**Decisión:** `FormStage` no contiene lógica de negocio específica de ningún formulario o perfil. Actúa exclusivamente como intérprete de las definiciones registradas en `Estandar_Quotes_Field_Mapping_Registry`.

**Toda la lógica específica** (transformadores, validadores, condiciones) vive en `Estandar_Quotes_Field_Transformer` como funciones puras.

**Archivos:** `class-estandar-quotes-form-stage.php`, `class-estandar-quotes-field-mapping-registry.php`, `class-estandar-quotes-field-transformer.php`

---

### 8. Perfiles comerciales como configuración

**Decisión:** Los perfiles comerciales (`real_estate@1`, `acacios_real_estate@1`) solo aportan:
- Valores por defecto (`defaults()`)
- Normalizador (`normalize()`)
- Definiciones de mapping (`Field_Mapping_Registry::register()`)

**Prohibido:** Modificar el núcleo del pipeline o los Stages para adaptarse a un perfil específico.

---

### 9. Modelo de dominio organizado en bounded contexts

**Decisión:** El contrato `QuoteDraft` se organiza en 6 bounded contexts:

| Contexto | Secciones | Propósito |
|----------|-----------|-----------|
| Customer | `client` | Identidad y contacto del cliente |
| Commercial | `project`, `property`, `pricing`, `conditions`, `editorial_blocks`, `capture_context` | Datos comerciales de la cotización |
| Financial | `uf_rate`, `financing`, `computed` | Parámetros y resultados financieros |
| Validity | `validity` | Vigencia y fechas |
| Identity | `issuer`, `document_version` | Identidad del emisor |
| Rendering | `visibility` | Metadatos de presentación |

---

### 10. Estabilidad del contrato público

**Decisión:** El contrato `QuoteDraft` (incluyendo todas las propiedades documentadas en los bounded contexts anteriores) se declara estable.

**Cualquier modificación estructural futura** (agregar/renombrar/eliminar secciones, cambiar tipos de datos) requerirá:
1. Un nuevo ADR que documente la necesidad funcional.
2. Compatibilidad hacia atrás con revisiones existentes.
3. Migración de datos si aplica.

**Excepciones:** Se permite agregar nuevas propiedades opcionales dentro de secciones existentes sin ADR, siempre que no afecten la lectura de revisiones existentes.

---

## Referencias

- `docs/ARCHITECTURE_V1.md` — Documentación arquitectónica canónica
- `estandar-quotes/includes/interface-estandar-quotes-hydration-stage.php` — Contrato de Stages
- `estandar-quotes/includes/class-estandar-quotes-quote-draft.php` — Modelo temporal
- `estandar-quotes/includes/class-estandar-quotes-hydration-pipeline.php` — Orquestador
- `estandar-quotes/includes/class-estandar-quotes-validation-stage.php` — Validación
- `estandar-quotes/includes/class-estandar-quotes-snapshot-stage.php` — Snapshot
- `estandar-quotes/includes/class-estandar-quotes-form-stage.php` — Intérprete de mappings
- `estandar-quotes/includes/class-estandar-quotes-field-mapping-registry.php` — Registro de mappings
- `tests/run-hydration-pipeline.php` — Tests de pipeline
- `tests/run-field-mapping.php` — Tests de mappings
