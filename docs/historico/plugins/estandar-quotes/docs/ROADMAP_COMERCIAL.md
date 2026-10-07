# Roadmap Comercial — Estandar Quotes

> Basado en hallazgos de producción y Customer Journey Suite.
> Sprint enfocado exclusivamente en UX, branding y diseño editorial.
> Sin mezclar con infraestructura ni funcionalidades nuevas.

---

## Épica 1 — Comunicación Comercial

Mejora la percepción del cliente en cada punto de contacto por correo.

### 1.1 Revisión visual del email al cliente

| Campo | Detalle |
|---|---|
| **Problema** | Template funcional (13.0.149) pero sin revisión de diseño definitiva |
| **Impacto cliente** | Percepción de marca |
| **Impacto operador** | Profesionalismo al enviar |
| **Prioridad** | Alta |
| **Complejidad** | Baja (CSS+HTML) |
| **Dependencias** | Ninguna |
| **Criterio de aceptación** | Email con branding Estandar Studio, saludo personalizado, firma operador, footer legal |

### 1.2 Rediseño del correo de confirmación al operador

| Campo | Detalle |
|---|---|
| **Problema** | Template escueto, sin valor informativo |
| **Impacto cliente** | Indirecto |
| **Impacto operador** | Trazabilidad inmediata del envío |
| **Prioridad** | Media |
| **Complejidad** | Baja |
| **Dependencias** | 1.1 |
| **Criterio de aceptación** | Template incluye: destinatarios, archivo, revisión, hash, fecha, resultado |

### 1.3 Personalización de asuntos por proyecto/cliente

| Campo | Detalle |
|---|---|
| **Problema** | Asunto genérico "Cotización" |
| **Impacto cliente** | Apertura y reconocimiento |
| **Impacto operador** | Organización de bandeja |
| **Prioridad** | Media |
| **Complejidad** | Baja (template string) |
| **Dependencias** | Settings con asunto por proyecto |
| **Criterio de aceptación** | Asunto incluye: proyecto, número, nombre del cliente |

### 1.4 Firmas configurables por operador

| Campo | Detalle |
|---|---|
| **Problema** | Firma genérica desde settings |
| **Impacto cliente** | Trato personalizado |
| **Prioridad** | Media |
| **Complejidad** | Media (nuevo campo en perfil operador) |
| **Dependencias** | Leads operator profile |

---

## Épica 2 — Documentos Comerciales

Transforma el PDF de funcional a profesional.

### 2.1 Diseño editorial del PDF

| Campo | Detalle |
|---|---|
| **Problema** | Tablas de financiamiento básicas, sin diseño profesional |
| **Impacto cliente** | Percepción de calidad (el PDF es el entregable final) |
| **Prioridad** | Muy alta |
| **Complejidad** | Alta (CSS Print + DOMPDF) |
| **Dependencias** | DOMPDF operativo |
| **Criterio de aceptación** | PDF con márgenes, tipografía corporativa, colores de marca, bloques visuales, numeración de páginas |

### 2.2 Nombre de archivo comercial

| Campo | Detalle |
|---|---|
| **Problema** | Formato implementado pero pendiente de revisión de mayúsculas, tildes y separadores |
| **Impacto cliente** | Identificación del documento |
| **Prioridad** | Alta |
| **Complejidad** | Baja |
| **Dependencias** | Ninguna |
| **Criterio de aceptación** | `Cotizacion-Proyecto-001-01.pdf` sin caracteres especiales |

### 2.3 Bloques configurables por perfil

| Campo | Detalle |
|---|---|
| **Problema** | Los 7 bloques (client, property, investment...) son fijos |
| **Impacto cliente** | Documento adaptado a cada tipo de propiedad |
| **Prioridad** | Media |
| **Complejidad** | Media (arquitectura ya preparada) |
| **Dependencias** | Profile system |

### 2.4 Branding por proyecto

| Campo | Detalle |
|---|---|
| **Problema** | PDF usa mismo branding para todos los proyectos |
| **Impacto cliente** | Identificación con su proyecto |
| **Prioridad** | Media |
| **Complejidad** | Media |
| **Dependencias** | 2.1, Settings por proyecto |

---

## Épica 3 — Experiencia del Operador

Reduce fricción y tiempo por cotización.

### 3.1 Diseño del workspace del operador (Gateway)

| Campo | Detalle |
|---|---|
| **Problema** | Interfaz funcional, sin diseño Estandar Studio |
| **Impacto operador** | Velocidad y precisión |
| **Prioridad** | Alta |
| **Complejidad** | Alta (HTML+CSS sin wp-admin) |
| **Dependencias** | Gateway existe |
| **Criterio de aceptación** | Workspace con diseño acorde a Estandar Suite, acciones visibles, navegación clara |

### 3.2 Acciones por revisión

| Campo | Detalle |
|---|---|
| **Problema** | Cada revisión debe mostrar: Ver, Preview, Descargar PDF, Reenviar, Historial |
| **Impacto operador** | Trazabilidad y control |
| **Prioridad** | Alta |
| **Complejidad** | Media (UI + lógica) |
| **Dependencias** | 3.1 |

### 3.3 Timeline visual del lead

| Campo | Detalle |
|---|---|
| **Problema** | Lista plana de eventos en el backend |
| **Impacto operador** | Comprensión rápida del historial |
| **Prioridad** | Media |
| **Complejidad** | Media (CSS + agrupación por fecha) |
| **Dependencias** | Portal template |

### 3.4 AI Context formateado

| Campo | Detalle |
|---|---|
| **Problema** | Texto denso sin formato visual |
| **Impacto operador** | Lectura lenta del contexto |
| **Prioridad** | Baja |
| **Complejidad** | Baja (Markdown → HTML) |

---

## Épica 4 — Experiencia del Cliente

Mejora la percepción desde el primer contacto.

### 4.1 Feedback post-submit en formularios

| Campo | Detalle |
|---|---|
| **Problema** | Divi AJAX no muestra confirmación/error visible al usuario |
| **Impacto cliente** | Incertidumbre después de enviar |
| **Prioridad** | Alta |
| **Complejidad** | Media (JS + integración Divi) |
| **Dependencias** | Formulario existente |
| **Criterio de aceptación** | Mensaje visible y claro después de submit (éxito o error) |

### 4.2 Confirmación al cliente por email

| Campo | Detalle |
|---|---|
| **Problema** | Cliente no recibe confirmación de que su solicitud fue recibida |
| **Impacto cliente** | Tranquilidad |
| **Prioridad** | Alta |
| **Complejidad** | Baja (autoresponder existe) |
| **Dependencias** | Autoresponder configurado |

### 4.3 Portal público de cotización

| Campo | Detalle |
|---|---|
| **Problema** | Cliente debe abrir PDF, no hay vista web |
| **Impacto cliente** | Acceso inmediato sin descargar |
| **Prioridad** | Media |
| **Complejidad** | Alta (nueva ruta pública + auth) |
| **Dependencias** | Preview Service |

---

## Resumen de sprints comerciales

| Sprint | Épica | Ítems | Complejidad estimada |
|---|---|---|---|
| **S1** | Comunicación Comercial | 1.1, 1.2, 1.4 | Baja |
| **S2** | Documentos Comerciales | 2.1, 2.2, 2.3 | Alta |
| **S3** | Experiencia del Operador | 3.1, 3.2, 3.3, 3.4 | Alta |
| **S4** | Experiencia del Cliente | 4.1, 4.2, 4.3 | Media |

**Orden recomendado por retorno comercial:** S1 → S2 → S4 → S3

- S1 (Comunicación): impacto inmediato, baja complejidad, visible para el cliente en el próximo envío.
- S2 (Documentos): el entregable principal del sistema, máxima calidad visible.
- S4 (Cliente): cierra el ciclo de experiencia del cliente.
- S3 (Operador): importante pero no visible para el cliente; puede programarse en paralelo con S4.

---

## Pruebas

```
Customer Journey Suite: 30 PASS (1 suite)
estandar-quotes: 476 PASS (17 suites)
estandar-leads: 1062 PASS (67 suites)
Total: 1604 tests, 0 FAIL
```
