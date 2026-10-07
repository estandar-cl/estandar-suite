# Sprint de Experiencia — Backlog Priorizado

## Próximo sprint: Calidad Comercial

A diferencia del sprint actual (enfocado en funcionalidad y arquitectura), el próximo sprint se centrará exclusivamente en experiencia de usuario, branding y diseño editorial.

---

## Hallazgos de calidad funcional (ya corregidos)

| ID | Hallazgo | Corrección | Tests |
|---|---|---|---|
| F1 | Teléfono inválido "12" pasaba validación cliente | Patrón `^\+?56?\d{9,12}$`, minlength=9, `phone_input_attrs()` | 6 tests en Customer Journey Suite |
| F2 | PDF vacío podía enviarse | 3 capas: strip_tags, %PDF header, byte_size < 100 | 3 tests |
| F3 | Actualizaciones bloqueadas por transient | Botón "Forzar actualización" + `delete_transient()` | Manual |
| F4 | quote_number no existía | `next_quote_number()` autoincremental en schema | 1 test |
| F5 | Filename UUID en descargas | `Cotizacion-{Proyecto}-{Numero}-{RR}.pdf` | 1 test |
| F6 | Sin CC al operador en envíos | `operator_copy` agregado a recipients | 1 test |
| F7 | Sin validación de artifact en envío | `invalid_artifact` notice + byte_size check | 2 tests |

## Hallazgos de calidad comercial (backlog para próximo sprint)

| ID | Severidad | Área | Problema | Evidencia |
|---|---|---|---|---|
| C1 | Alta | Email branding | Template profesional implementado pero requiere revisión visual definitiva | `delivery-email.php` 13.0.149 |
| C2 | Alta | Filenames | Formato `Cotizacion-{Proyecto}-{Numero}-{RR}.pdf` implementado, revisar separadores y mayúsculas | `admin.php` 13.0.150 |
| C3 | Media | Formulario web | Sin mensaje visible post-submit en Divi (AJAX sin retroalimentación clara) | Auditoría visual |
| C4 | Media | Workspace operador | Interfaz funcional pero sin diseño Estandar Studio | `operator-gateway.php` |
| C5 | Media | PDF content | Tablas de financiamiento básicas, sin diseño editorial profesional | `document-engine.php` |
| C6 | Media | Correo operador | Template de confirmación funcional pero escueto | `delivery-email.php` |
| C7 | Baja | AI Context | Texto denso, sin formato visual | `ai-context.php` |
| C8 | Baja | Timeline lead | Lista plana de eventos sin agrupación visual | `operator-portal.php` |

---

## Customer Journey Suite — Cobertura actual

**30 tests, 0 FAIL.** 18 escenarios cubiertos de 18 planificados.

| Escenario | Tests | Estado |
|---|---|---|
| 1. Captación formulario | 6 | ✅ |
| 2. Captación chatbot | 2 | ✅ |
| 3. Notificaciones correo | 2 | ✅ |
| 4. Revisión lead | 2 | ✅ |
| 5. AI Context | 2 | ✅ |
| 6. Workspace operador | 1 | ✅ |
| 7-9. Creación, preview, PDF | 4 | ✅ |
| 10. Envío | 2 | ✅ |
| 11-12. Correos cliente/operador | 2 | ✅ |
| 13. Descarga PDF | 2 | ✅ |
| 14-15. Nueva revisión y reenvío | 2 | ✅ |
| 16. Historial | 1 | ✅ |
| 17-18. Artefacto histórico | 2 | ✅ |

---

## Pruebas totales

```
Customer Journey Suite: 30 PASS (1 suite)
estandar-quotes: 476 PASS (17 suites)
estandar-leads: 1062 PASS (67 suites)
Runtime methodology: 36 PASS (1 suite)
Total: 1604 tests, 0 FAIL
```
