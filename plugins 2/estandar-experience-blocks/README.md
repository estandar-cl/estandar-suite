# Estandar Experience Blocks

Motion system del ecosistema Estandar: motor de animación en capas (visibility, scroll, pointer, text, particles) que se acopla a bloques Gutenberg nativos, más bloques estructurales de movimiento. Versión unificada: 13.0.173.

> Documento técnico canónico (SSOT): `docs/historico/plugins/estandar-experience-blocks/docs/ESTANDAR_FX_V3.md`

## Arquitectura (V3 — ACTIVE)

**Tesis de producto:** sistema de motion, no una bolsa de bloques.

- **Las experiencias se acoplan a bloques nativos** (Heading, Paragraph, Image, Cover, Group, Button) vía panel "Experiencia". Sin forks ni copias.
- **Los efectos componen:** un elemento puede llevar `reveal + text + hover + scroll` a la vez; cada módulo usa su propio canal (WAAPI, CSS var, clase).
- **Bloques solo donde el DOM lo requiere:** Marquee, Before/After, Stack Cards.

**Flujo:** editor (atributo `estandarFx` → payload compacto) → server (`class-efx-frontend.php`: sanitiza, estampa `.efx`, CSS vars, `data-efx`; enqueue solo módulos usados) → browser (`core.js` kernel: ticker 1 rAF, visibility pool IO, pointer, scroll; módulos FX: reveal/text/scroll/pointer/particles/hover/ambient/marquee/before-after).

**Runtime:** `window.EstandarFX` · **Class prefix:** `efx-`

## Estado en la Suite

- **Clasificación portfolio:** PRODUCTO PRINCIPAL (UX) + COMPATIBILIDAD LEGACY — MANTENER POR COMPATIBILIDAD.
- 13 bloques en producción en sitios cliente; sin equivalente v2. Retirar sin migrar rompería páginas.

## Licencia

Propietario — Estandar Studio
