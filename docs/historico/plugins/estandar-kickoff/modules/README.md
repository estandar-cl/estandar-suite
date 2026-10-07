# Módulos Estandar KickOff

Cada carpeta dentro de `modules/` se detecta automáticamente. Los módulos pueden activarse o desactivarse desde el tab **Ajustes**.

## Crear un nuevo módulo

1. Crea una carpeta: `modules/mi-modulo/`
2. **config.php** (obligatorio para que aparezca en Ajustes sin cargar):

```php
<?php
if ( ! defined( 'ABSPATH' ) ) exit;

return [
    'slug'        => 'mi-modulo',
    'label'       => __( 'Mi Módulo', 'estandar-kickoff' ),
    'description' => __( 'Descripción breve.', 'estandar-kickoff' ),
    'icon'        => '🔧',
    'order'       => 30,
];
```

3. **module.php** (carga la lógica cuando el módulo está activo):

```php
<?php
if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-mi-modulo.php';
Mi_Modulo_Class::get_instance();

return [
    'slug'   => 'mi-modulo',
    'label'  => __( 'Mi Módulo', 'estandar-kickoff' ),
    'icon'   => '🔧',
    'order'  => 30,
    'render' => [ Mi_Modulo_Class::class, 'render_tab' ],
];
```

4. `order` define la posición del tab (menor = primero).
5. Los módulos nuevos se activan por defecto; el usuario puede desactivarlos en Ajustes.
