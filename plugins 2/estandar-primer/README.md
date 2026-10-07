# Estandar Primer

Runtime compartido para plugins Estandar (menú unificado + helpers + assets admin UI). Versión unificada: 13.0.173.

## Rol

**Estandar Core (primer)** es la fuente única del UI administrativo compartido. Los siguientes plugins requieren Core para renderizar correctamente su interfaz:

- Estandar Forms
- Estandar Delivery Locator
- Estandar Duplicate Post
- Estandar KickOff
- Estandar CPT Manager

Si Core está inactivo: la funcionalidad de negocio de esos plugins permanece intacta; la interfaz administrativa no está garantizada visualmente. No se reintroducen copias locales de `estandar-admin-ui.css`.

## Capacidades (más allá del UI)

AI Gateway (OpenAI + DeepSeek), Diagnostics Engine, Integrity Framework, Project Provisioning, Campaign Discovery + Label Resolver, Google Integration Hub, Operational Hours, Project Calendar/Registry, Compliance Shadow Copy, Plugins Update Manager, Admin Shell.

## Estado en la Suite

- **Clasificación portfolio:** CORE (runtime compartido) — candidato a **ABSORCIÓN** (AI gateway/updates → Core; campaign resolver → Brain).
- Monolito ~7.551 líneas; campaña resolver es necesario para attribution.

## Licencia

Propietario — Estandar Studio
