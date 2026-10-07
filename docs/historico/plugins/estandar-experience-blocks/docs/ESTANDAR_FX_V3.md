# Estandar FX — Experience Blocks V3

**Status:** `ACTIVE` · **Supersedes:** Experience Engine V2 (and the V1 Signature Hero block)
**Plugin dir:** `estandar-experience-blocks` · **Runtime namespace:** `window.EstandarFX` · **Class prefix:** `efx-`

V3 is a ground-up redesign. It was informed by a reverse-engineering pass over a
third-party block suite (KineticHub) — *concepts only, no code, no naming, no
architecture carried over*. The lessons taken: IO-gated animation loops, CSS
custom properties as the JS→CSS bridge, a11y-safe text splitting, per-page
asset loading. The lessons rejected: one manager per block (12 parallel
MutationObservers), one hard-coded effect per block, zero composability.

---

## 1. Product thesis

The product is a **motion system**, not a bag of blocks:

- **Experiences attach to native blocks.** Heading, Paragraph, Image, Cover,
  Group, Button… get an "Experiencia" panel. No forks, no custom copies.
- **Effects compose.** One element can carry `reveal + text + hover + scroll`
  simultaneously; each module owns its own channel (WAAPI, CSS var, class).
- **Blocks exist only where DOM structure is required:** Marquee,
  Before/After, Stack Cards.

## 2. Architecture

```
                       ┌────────────────────────────────────┐
 EDITOR (parent frame) │ catalog.js  → panel.js             │
                       │  · attribute estandarFx (object)   │
                       │  · generic controls per manifest   │
                       │  · presets (built-in + REST user)  │
                       │  · copy/paste/reset/preview        │
                       │  · live canvas class/var sync      │
                       └────────────────┬───────────────────┘
                                        │ one attribute, saved in delimiter
                                        ▼
 SERVER (per render)   ┌────────────────────────────────────┐
                       │ class-efx-frontend.php             │
                       │  · sanitize → compact payload      │
                       │  · stamp wrapper (WP_HTML_Tag_     │
                       │    Processor): .efx classes,       │
                       │    CSS vars, data-efx JSON         │
                       │  · enqueue ONLY used modules       │
                       └────────────────┬───────────────────┘
                                        ▼
 BROWSER               ┌────────────────────────────────────┐
                       │ core.js — KERNEL                   │
                       │  env · math · ticker (1 rAF)       │
                       │  visibility (pooled IO)            │
                       │  pointer (1 listener)              │
                       │  scroll (cached rects, progress)   │
                       │  registry + scanner + lifecycle    │
                       ├────────────────────────────────────┤
                       │ FX MODULES (independently loaded)  │
                       │  reveal.js   text.js   scroll.js   │
                       │  pointer.js  particles.js          │
                       │  hover.css   ambient.css (0 JS)    │
                       │  marquee.js  before-after.js       │
                       │  stack-cards.js                    │
                       └────────────────────────────────────┘
```

### PHP modules

| File | Responsibility |
|---|---|
| `class-efx-manifest.php` | SSOT: module → assets/kind, CSS effect whitelist, CSS-var map, supported blocks |
| `class-efx-assets.php` | Registers every handle once; editor + canvas enqueues; block category |
| `class-efx-attributes.php` | Server mirror of the `estandarFx` attribute |
| `class-efx-frontend.php` | Sanitize → stamp → per-page enqueue (`render_block`) |
| `class-efx-blocks.php` | Structural block registration + module bridge |
| `class-efx-presets.php` | User presets (option + REST `estandar-efx/v1/presets`) |

## 3. Data model

One attribute per block:

```jsonc
"estandarFx": {
  "preset": "hero-estandar",                  // reference only; config is materialized
  "reveal":  { "effect": "rise", "distance": 36, "children": false },
  "text":    { "effect": "glitch", "intensity": 1 },
  "scroll":  { "effect": "parallax", "speed": 0.25 },
  "pointer": { "effect": "tilt", "strength": 10, "glare": true },
  "hover":   { "effect": "lift", "intensity": 1 },
  "ambient": { "effect": "gradient", "color": "#10b981", "colorB": "#6366f1" },
  "particles": { "effect": "line-ripple", "density": 1, "speed": 0.8, "length": 1, "force": 1 },
  "timing":  { "trigger": "visible", "duration": 700, "delay": 0, "stagger": 45, "easing": "expo" },
  "advanced": { "once": true, "mobile": true }
}
```

Server output: CSS-only modules (`hover`, `ambient`) become classes + CSS vars
(zero JS shipped); JS modules travel as compact JSON in `data-efx`.

## 4. Implemented effects (51)

| Module | Effects |
|---|---|
| **Reveal** (12) | fade · rise · drop · slide-left · slide-right · zoom · zoom-out · blur · flip · rotate · mask · wipe — all with optional per-child stagger |
| **Text** (6) | cascade (words/chars) · typewriter (caret, cps) · rotating-typewriter (`|` separated phrases) · scramble (width-frozen decode) · glitch · reveal-wall |
| **Scroll** (4) | parallax · zoom · rotate · progress (`--efx-progress` for custom CSS) |
| **Pointer** (4) | tilt 3D (+glare) · magnetic · spotlight · emoji-burst |
| **Hover** (10, CSS-only) | lift · glow · shine · underline · zoom-media · frame · electric-border · dot-image-reveal · image-reveal · mesh-text |
| **Ambient** (7, CSS-only) | float · pulse · gradient (text-clips on headings) · blob · noise · glass · spotlight-text |
| **Particles** (3) | dots (density/speed/color/link) · line-ripple (flow-field streaks with cursor swirl) · blinking-squares |
| **Blocks** (5) | Marquee infinito · Antes/Después · Tarjetas apiladas · Coverflow Gallery · Magnetic Carousel |

Compatibility badges in the editor: `D/M` means desktop + mobile suitable; `D` means desktop-first or pointer/hover-heavy. The global `advanced.mobile` switch remains the runtime opt-out for JS/CSS effects on small screens.

Easings: `expo`, `quint`, `spring`, `snap`, `linear` (tokens `--efx-ease-*`).

## 5. Reusable APIs

### Runtime (`window.EstandarFX`)

```js
EstandarFX.register( 'reveal', { fade: buildFn }, { kind: 'enter' } );
// enter contract:      build(el, opts, ctx) -> { prepare(), play(), reset() }
// continuous contract: mount(el, opts, ctx) -> destroy()

EstandarFX.component( '.efx-marquee', mountFn ); // structural widgets
EstandarFX.play( el, config );                   // imperative entrance (editor preview)
EstandarFX.scan( root );                         // bind dynamically injected DOM

// Shared engines available to any module (and other Estandar plugins):
EstandarFX.ticker.add(fn)/remove(id)     // THE rAF loop; auto-starts/stops
EstandarFX.visibility.observe(el, cb, {margin, threshold})  // pooled IO
EstandarFX.pointer.use() + .state        // 1 passive listener, refcounted
EstandarFX.scroll.track(el, cb)          // progress 0..1, cached rects
EstandarFX.math.lerp/damp/clamp/map      // damp = frame-rate independent
```

Adding an effect = implement in its module + declare in `catalog.js`
(id, label, controls). The panel renders it automatically; no engine edits.

### PHP

```php
\Estandar\FX\Frontend::instance()->enqueue_module( 'marquee' );  // reuse pipeline
apply_filters( 'estandar_efx_supported_blocks', $blocks );        // extend coverage
```

## 6. Gutenberg integration

- Attribute via `blocks.registerBlockType` filter (JS) + `register_block_type_args` (PHP).
- Panel via `editor.BlockEdit` HOC — only on supported blocks, only when selected.
- **Live canvas sync:** CSS effects (hover/ambient) are mirrored onto the canvas
  DOM node with the exact classes/vars PHP will stamp → hovering in the editor
  previews the real effect.
- **Entrance preview** plays on a positioned **clone overlay**, never mutating
  the React-managed DOM (text splitting is destructive; the clone makes it safe).
- Presets: built-ins in `catalog.js`; user presets persisted via REST
  (`edit_theme_options` to save), applied by materializing config (stays editable).
- Copy/Paste settings via localStorage; Reset = `{}`.
- Structural blocks: server `block.json` (+`render.php`), buildless
  `edit/save` in `assets/editor/blocks.js` (InnerBlocks for Marquee/Stack).

## 7. Performance decisions

1. **Zero-runtime baseline.** No experience on the page → not one byte of FX
   CSS/JS is enqueued (verified: home ships 0 handles).
2. **Per-page module loading.** `render_block` enqueues exactly the modules in
   use; CSS-only modules never ship JS.
3. **One rAF loop** (`ticker`), refcounted; it does not run when no effect is
   animating. Dampers release their ticker slot on convergence.
4. **Pooled IntersectionObservers** keyed by margin/threshold; **one** passive
   `pointermove` listener; **one** debounced MutationObserver for the whole
   page (connectivity sweep instead of per-node traversal).
5. **No per-frame layout reads.** Scroll progress uses cached absolute rects
   (measured on visibility-enter and debounced resize); pointer effects cache
   rects on enter.
6. **Compositor-only output:** transform/opacity/clip-path; JS writes CSS
   custom properties consumed by one shared transform chain (`.efx-transform`),
   so effects compose without fighting over `transform`.
7. **WAAPI for entrances** (off-main-thread where possible), `fill: both` +
   cancel-on-finish → zero inline residue, no `will-change` leaks.
8. **CSS animations wherever sufficient** (hover, ambient, marquee run on the
   compositor with no per-frame JS at all).
9. **Canvas discipline:** DPR cap 2, area-derived particle count (clamped),
   loop paused off-viewport, batched resize via ResizeObserver.
10. **Scramble without reflow:** char widths frozen in one batched read→write
    pass before the per-frame glyph swaps.

### Accessibility / progressive enhancement

- Content is **always visible without JS**; hidden pre-states are applied
  just-in-time by the runtime.
- `prefers-reduced-motion`: entrances skip (content stays visible), decorative
  loops stop (CSS kill-switch in core.css), Before/After stays fully
  functional (it is interaction, not decoration).
- Text splitting labels the container (`aria-label`) and hides fragments
  (`aria-hidden`); original DOM is restored after the animation completes.
- Marquee clones are `aria-hidden` with focusables detabbed; Before/After is a
  real `<input type=range>` (keyboard + SR native).
- Per-block mobile opt-out (`advanced.mobile:false`) gates both CSS (media
  query) and JS (kernel check).

## 8. QA

`tools/efx-qa.php` (localhost-gated) renders every effect through the real
pipeline (`do_blocks` + `wp_head`/`wp_footer`). Verified in browser this
session: stamping, selective enqueue, pre-states below the fold, entrance
restore, replay re-arm (`once:false`), parallax var streaming, tilt/glare
axis signs, magnetic pull+press, marquee compositor motion + cloning,
Before/After drag + range sync + label orientation, stack scale/dim, particle
painting, zero console errors, zero assets on non-FX pages.

Pending manual QA (needs a logged-in editor): panel UX, preset save (REST),
entrance preview overlay, structural block editing.

## 9. Roadmap

- **Editor:** responsive per-breakpoint values (today: on/off per block);
  hover-state preview toggle for pointer effects.
- **Modules:** page-level cursor experience (dot/ring/trail — deliberately
  deferred: page takeover needs a site-wide policy, not a block toggle);
  image distortion (WebGL, behind capability gate); pinned scenes/timeline
  (scroll-driven animations API when browser support matures).
- **Tokens:** read `--efx-accent` defaults from theme.json palette.
- **Ecosystem:** expose the kernel (`ticker`/`visibility`/`scroll`) as the
  canonical motion substrate for other Estandar plugins (chatbot bubble,
  forms micro-interactions) — rule of two applies before any extraction.
