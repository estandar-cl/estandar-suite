# Estandar Suite (hub)

Plugin **contenedor** mínimo de Estandar Suite. No aporta funcionalidad de negocio: agrupa y
ordena otros plugins de Estandar, aplica el branding común y provee el sistema de pestañas.

Diseño **puramente aditivo**: si este plugin se desinstala, **los plugins alojados siguen
funcionando igual** (no dependen de él en runtime).

## Qué hace

1. **Página única** `Estandar Suite` con **tabs anidados**:
   - Nivel 1: **plugins**.
   - Nivel 2: **ítems de menú** de cada plugin.
   - Nivel 3+: **subitems** (recursivo).
   - Ruta activa: `admin.php?page=estandar-suite&path=plugin/item/subitem`.
2. **Menú lateral:** top-level `Estandar Suite` + accesos rápidos por plugin (`&path=<plugin>`).
3. **Barra superior (admin bar):** nodo `Estandar Suite` con los plugins y sus submenús
   (hasta 3 niveles).
4. **Branding:** cabecera de marca (`estandar_suite_brand_header()`) y tokens CSS
   (`--es-brand`) aplicados a las páginas de settings.
5. **Sistema de tabs recursivo:** orden determinista (`order`), capability por pestaña,
   contenido por `callback` en la hoja. **Sin drag & drop.**

## API para plugins alojados

Un plugin se aloja llamando a la API **sólo si existe** (para seguir funcionando sin el hub):

```php
add_action( 'plugins_loaded', function () {
	if ( function_exists( 'estandar_suite_register_plugin' ) ) {
		estandar_suite_register_plugin( array(
			'id'          => 'mi-plugin',
			'name'        => 'Mi Plugin',
			'menu_title'  => 'Mi Plugin',
			'version'     => '1.0.0',
			'description' => 'Qué hace mi plugin.',
			'icon'        => 'dashicons-email-alt',
			'position'    => 30,
			'page_slug'   => 'mi-plugin',
			'capability'  => 'manage_options',
			'tabs'        => array(
				array(
					'id'       => 'general',
					'label'    => 'General',
					'order'    => 10,
					'callback' => 'mi_plugin_render_general',
				),
				array(
					'id'       => 'avanzado',
					'label'    => 'Avanzado',
					'order'    => 20,
					'children' => array(
						array( 'id' => 'api', 'label' => 'API', 'order' => 10, 'callback' => 'mi_plugin_render_api' ),
					),
				),
			),
		) );
	}
} );
```

Alternativa (hook del hub):

```php
add_action( 'estandar_suite_register', function () {
	estandar_suite_register_plugin( array( /* ... */ ) );
} );
```

Agregar una pestaña después del registro:

```php
estandar_suite_register_tab( 'mi-plugin', array(
	'id'       => 'avanzado',
	'label'    => 'Avanzado',
	'order'    => 20,
	'callback' => 'mi_plugin_render_avanzado',
) );
```

### Funciones públicas

| Función | Descripción |
|---|---|
| `estandar_suite_register_plugin( array $args ): bool` | Registra un plugin en el hub. |
| `estandar_suite_register_tab( string $id, array $tab ): bool` | Agrega una pestaña. |
| `estandar_suite_brand_header( array $args = [] ): void` | Imprime la cabecera de marca. |
| `estandar_suite_registered_plugins(): array` | Lista los plugins registrados. |

### Args de `register_plugin`

| Clave | Tipo | Default | Notas |
|---|---|---|---|
| `id` | string | — | Requerido. Slug único. |
| `name` | string | — | Requerido. |
| `menu_title` | string | `name` | Texto en el menú. |
| `version` | string | `''` | Se muestra en la cabecera/tarjeta. |
| `description` | string | `''` | Para la home del hub. |
| `capability` | string | `manage_options` | Capability del ítem. |
| `icon` | string | `dashicons-admin-generic` | Dashicon (home/tarjeta). |
| `position` | int | `70` | Orden en el menú del hub. |
| `page_slug` | string | `id` | Slug de la página (`admin.php?page=...`). |
| `callback` | callable\|null | `null` | Render propio (si no usa tabs). |
| `tabs` | array | `[]` | Pestañas (ver abajo). |
| `standalone` | bool | `true` | Documental: el plugin funciona sin el hub. |

### Args de un tab

| Clave | Tipo | Default | Notas |
|---|---|---|---|
| `id` | string | — | Requerido. |
| `label` | string | — | Requerido. |
| `order` | int | `50` | Orden ascendente. |
| `capability` | string | `manage_options` | Capability de la pestaña. |
| `callback` | callable\|null | `null` | Render del contenido (hoja). |
| `children` | array | `[]` | Subtabs (mismo esquema, recursivo). Alias: `items`. |

## Filtros

- `estandar_suite_plugins` — modifica la lista de plugins registrados.
- `estandar_suite_brand` — modifica los datos de marca (nombre, tagline, colores, links).

## Estructura

```
estandar-suite/
├── estandar-suite.php        # header, constantes, API pública, boot
├── includes/
│   ├── Registry.php          # registro de plugins/tabs
│   ├── Branding.php          # tokens + cabecera de marca
│   ├── Settings.php          # home del hub + páginas con tabs
│   ├── AdminMenu.php         # menú lateral
│   ├── AdminBar.php          # barra superior
│   └── Assets.php            # CSS/JS de admin (sólo en pantallas del hub)
├── assets/admin/
│   ├── branding.css
│   └── tabs.js
└── README.md
```

## Notas de diseño

- **Sin dependencias:** no requiere jQuery ni page-builders. `tabs.js` sólo mejora teclado.
- **Anidamiento recursivo:** un tab puede tener `children` (subitems) a cualquier profundidad.
  Nivel 1 = plugins; nivel 2 = ítems del plugin; nivel 3+ = subitems. Ruta activa en `?path=a/b/c`.
- **Orden de pestañas:** determinista por `order` (int). **Sin drag & drop** (decisión: se mantiene
  simple; sin JS sortable, sin persistencia, sin endpoints).
- **Requiere** PHP ≥ 8.0 y WP ≥ 6.0.
