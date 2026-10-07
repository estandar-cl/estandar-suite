# Acceptance Suite — Estandar Suite

## Propósito

Batería de pruebas de aceptación de producción que se ejecuta antes de cualquier release.
Complementa las suites de tests unitarios verificando comportamiento real del sistema.

## Archivo

`wp-content/plugins/bin/acceptance-tests`

## Escenarios cubiertos

| Escenario | Tests | Propósito |
|---|---|---|
| Phone validation | 6 | Validación de teléfono chileno (min 9 dígitos, patrón, mensaje) |
| Form processing | 2 | Existencia de handlers de formulario |
| Quotes plugin | 7 | Sintaxis, quote_number, PDF validation, email branding, filenames |
| Workspace continuity | 5 | recovery path + get_by_submission_any_status + issued filter |
| Handoff mapping | 10 | project_slug en snapshot + raw_fields en capture_context |
| PDF gate | 3 | dompdf-loader carga correctamente DOMPDF desde bundled lib |

## Cómo ejecutar

```bash
./wp-content/plugins/bin/acceptance-tests
```

Debe ejecutarse antes de cada release. Si algún test FALLA, el release se bloquea.

## Agregar nuevos tests

Cada nuevo hallazgo de producción debe convertirse en un test de aceptación:

1. Identificar el comportamiento esperado
2. Escribir un test que verifique ese comportamiento
3. El test debe fallar si el comportamiento se pierde en futuras versiones

## Integración con release pipeline

Agregar al `release.command`:

```bash
if [[ -x "${DRAGON_HOME}/../bin/acceptance-tests" ]]; then
  "${DRAGON_HOME}/../bin/acceptance-tests" || fail "Acceptance tests failed"
fi
```
