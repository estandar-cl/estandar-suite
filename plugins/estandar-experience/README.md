# Estandar Experience

Motion system v2 del ecosistema Estandar. Portado desde el legacy
`estandar-experience-blocks` (Estandar FX V3) bajo la regla de dos: el legacy
sigue operando en producción hasta alcanzar paridad.

## Estado (F3 — kernel + módulos base + 13 bloques estructurales)

- Plugin v2 nuevo, bounded context hermano de `estandar-quotes`.
- Manifiesto portado (`Manifest`): 20 módulos FX + 13 bloques estructurales +
  bloques nativos soportados.
- Kernel + 7 módulos base portados (`assets/core`, `assets/fx`) + **mobile boost**
  (`assets/core/mobile.css` → móvil ≥ desktop).
- 13 bloques estructurales portados (`blocks/`, render.php v2) + registrador
  `Blocks` con gating de convivencia.
- Guarda owner + flag `estandar_experience_frontend_enabled` (default OFF).
- Integrado a `release.command` (V2_PLUGINS + versión + gate de tests).
- Tests: `tests/run-experience.php` (48 checks).
- **F4 pendiente (editor panel + catálogo JS)** — documentado en el sprint.
- No se añade frontend activo hasta el flag; el legacy sigue siendo dueño.

## Principio

La "pirotecnia" existe para capturar leads: el kernel FX se expone como
sustrato de motion y `estandar-capture` lo consume (forms, burbuja de chatbot).

## Doc canónico del motor

`docs/historico/plugins/estandar-experience-blocks/docs/ESTANDAR_FX_V3.md`
