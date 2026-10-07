# Estandar Core

## Dependencia operativa de UI admin

Desde Sprint 1 de consolidacion de plataforma, Estandar Core es la fuente unica del UI administrativo compartido.

Los siguientes plugins requieren Core para renderizar correctamente su interfaz administrativa:

- Estandar Forms
- Estandar Delivery Locator
- Estandar Duplicate Post
- Estandar KickOff
- Estandar CPT Manager

Si Core esta inactivo:

- La funcionalidad de negocio de esos plugins permanece intacta.
- La interfaz administrativa no esta garantizada visualmente.

No se reintroducen copias locales de `estandar-admin-ui.css`.
