# ADR-003: Project Registry V2 — FROZEN

Fecha: 2026-06-05
Estado: **FROZEN**
Autor: Estandar Studios CL

---

## Decisión

`PROJECT_REGISTRY_V2` queda congelado en `SCHEMA_VERSION = 2.1`.

No se agregan campos, no se modifican tipos, no se cambian índices
hasta que exista evidencia de validación runtime completa.

---

## Schema Final (2.1)

### Índices

```sql
PRIMARY KEY  (id)
UNIQUE KEY   project_id   (project_id)    -- slug operativo
UNIQUE KEY   project_uuid (project_uuid)  -- identidad estable
KEY          active        (active)
KEY          sort_order    (sort_order)
KEY          company_id    (company_id)
KEY          project_type  (project_type)
```

### Columnas

```
-- V1 (intocables)
id, project_id, label, city, active,
url_patterns, streets_json, meta_json,
sort_order, created_at, updated_at

-- V2 (nuevas)
project_uuid    char(36)           identidad estable permanente
project_type    varchar(64)        qué ES el proyecto
company_id      varchar(64)        slug de empresa
company         varchar(190)       nombre visible de empresa
region          varchar(190)
commune         varchar(190)
price_from      int(10) unsigned   UF sin decimales, 0 = no definido
price_to        int(10) unsigned   UF sin decimales, 0 = no definido
sale_type       varchar(64)
operator_name   varchar(190)
operator_email  varchar(190)
operator_phone  varchar(64)
whatsapp_msg    text
funnel_template varchar(64)        cómo SE VENDE el proyecto
cta_primary     varchar(190)
cta_secondary   varchar(190)
form_id         varchar(190)
landing_url     varchar(500)
hero_image_id   bigint(20) unsigned
```

---

## Regla de Oro (ecosistema)

> Ningún consumer puede depender exclusivamente de campos V2.
> Toda ausencia de datos V2 debe degradar elegantemente al comportamiento V1.

---

## Siguiente Evidencia Requerida

Antes de cualquier modificación adicional al Registry, se requiere evidencia de:

| # | Test                              | Estado    |
|---|-----------------------------------|-----------|
| 1 | Menú Proyectos visible en wp-admin | Pendiente |
| 2 | Crear proyecto `test-v2`           | Pendiente |
| 3 | Editar proyecto `test-v2`          | Pendiente |
| 4 | Eliminar proyecto `test-v2`        | Pendiente |
| 5 | Proyectos existentes intactos      | Pendiente |
| 6 | `SHOW COLUMNS` confirma schema 2.1 | Pendiente |
| 7 | Estandar Forms — PASS              | Pendiente |
| 8 | Estandar Leads — PASS              | Pendiente |
| 9 | Estandar Analytics — PASS          | Pendiente |
|10 | Estandar Chatbot — PASS            | Pendiente |

---

## Protocolo de Despliegue

```
1. Backup: base de datos + wp-content
2. Staging Acacios (no producción)
3. Activar Core con schema 2.1
4. Entrar a wp-admin → admin_init ejecuta migración automática
5. SHOW COLUMNS → confirmar tipos y columnas
6. Ejecutar tests #1–#10
7. Si PASS → cargar AltaCruz con datos V2 reales
8. Si FAIL → investigar antes de continuar
```

## Diagnóstico SQL Post-Migración

### Validar integridad de UUIDs (deben coincidir)

```sql
SELECT
    COUNT(*)                  AS total,
    COUNT(DISTINCT project_uuid) AS unique_uuid
FROM wp_estandar_project_registry;
```

Resultado esperado: `total = unique_uuid`. Si difieren, hay UUIDs vacíos o duplicados — indica que `backfill_project_uuids()` no completó o la columna no existía.

### Confirmar tipos de columnas

```sql
SHOW COLUMNS FROM wp_estandar_project_registry;
```

Verificar:
- `price_from`: `int(10) unsigned` (no `varchar`)
- `price_to`: `int(10) unsigned` (no `varchar`)
- `project_uuid`: `char(36)`
- `company_id`: `varchar(64)`
- `project_type`: `varchar(64)`

### Señales de error a monitorear en debug.log

```
Duplicate entry          → UNIQUE KEY project_uuid colisionó en backfill
ALTER TABLE failed       → Migración de tipo no ejecutó
Unknown column           → Columna V2 no se creó (dbDelta silencioso)
Cannot create unique index → project_uuid tiene vacíos ('' duplicados)
```

Si `Cannot create unique index`: el `UNIQUE KEY project_uuid` falla cuando existen múltiples filas con `project_uuid = ''`. El backfill debe correr *antes* de que dbDelta intente crear el índice — el orden en `install()` garantiza esto: primero `dbDelta`, luego `backfill_project_uuids()`, el índice se crea en la próxima llamada a `dbDelta` una vez que los UUIDs están poblados. Si el timing falla, forzar con `DELETE FROM wp_options WHERE option_name = 'estandar_project_registry_version'` y recargar wp-admin.

---

## Lo que NO se construye hasta PASS completo

- Datos reales de AltaCruz, Alto Jaramillo, Los Parronales
- Consumo activo de campos V2 en Forms, Leads, Analytics, Chatbot
- Landing Builder
- Page Generator
- Visual Funnel Builder

---

## Pendiente V3 (no antes)

- `project_owner` vs `sales_operator` (hoy solo existe `operator_*`)
- Registry de `estandar_companies` vinculado a `company_id`
- `PROJECT_TYPES` configurable (hoy constante; ok hasta ~20 proyectos)
