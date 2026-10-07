# Estandar Inicio

Plugin **mínimo y extensible** con las capacidades base de Estandar. Se aloja en
**Estandar Suite** (hub) si está presente; si no, funciona igual con un menú propio.

## Capacidades

| Capacidad | Qué hace | Tab en el hub |
|---|---|---|
| **Generar child theme** | Crea un child theme (`style.css` + `functions.php`) desde un tema padre; permite **subir un screenshot** (PNG/JPG/GIF) o usa uno **por defecto** (nunca queda sin imagen). | `child-theme` |
| **SVG en la biblioteca** | Permite subir SVG a los administradores, **sanitizando** (quita `<script>`, `<foreignObject>`, atributos `on*` y `href:javascript:`). | `svg` |

## Integración con el hub

Al cargar, si existe `estandar_suite_register_plugin()` se registra:

```php
array(
  'id' => 'estandar-inicio', 'name' => 'Estandar Inicio',
  'tabs' => array(
    array( 'id' => 'child-theme', 'label' => 'Child theme', 'order' => 10, 'callback' => [ChildTheme::class,'render'] ),
    array( 'id' => 'svg',         'label' => 'SVG',          'order' => 20, 'callback' => [SvgUploads::class,'render'] ),
  ),
)
```

Si el hub no está, `Hub::fallback_menu()` crea un menú propio con las dos capacidades.

## Estructura

```
estandar-inicio/
├── estandar-inicio.php        # header, constantes, boot
├── includes/
│   ├── Hub.php                # registro en el hub (o menú de respaldo)
│   ├── ChildTheme.php         # capacidad: child theme
│   └── SvgUploads.php         # capacidad: SVG
└── README.md
```

## Seguridad

- Ambas capacidades exigen `manage_options`.
- El generador de child theme valida nonce y no sobreescribe carpetas existentes.
- La subida de SVG sanitiza el contenido y **rechaza** el archivo si quedan patrones peligrosos.

## Requisitos

PHP ≥ 8.0, WordPress ≥ 6.0.
