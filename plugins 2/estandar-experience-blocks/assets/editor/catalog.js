/**
 * Estandar FX — editor catalog.
 *
 * Single source of truth for the editor: modules, effects, their controls and
 * the built-in presets. The panel renders 100% generically from this file, so
 * adding an effect here (plus its runtime implementation) is all it takes for
 * it to appear in the editor.
 *
 * Control shape:
	 *   { key, label, type: 'range'|'toggle'|'select'|'color',
	 *     min?, max?, step?, options?, default?, showFor?: [effectIds] }
	 * Effect shape supports compat: 'dm' desktop+mobile, 'd' desktop-first.
 */
( function () {
	'use strict';

	window.EstandarFXCatalog = {
		attribute: 'estandarFx',

		order: [ 'reveal', 'text', 'scroll', 'pointer', 'hover', 'ambient', 'particles' ],

		modules: {
			reveal: {
				label: 'Entrada',
				kind: 'enter',
				effects: [
					{ id: 'fade', label: 'Fade', compat: 'dm' },
					{ id: 'rise', label: 'Rise (sube)', compat: 'dm' },
					{ id: 'drop', label: 'Drop (baja)', compat: 'dm' },
					{ id: 'slide-left', label: 'Slide desde la izquierda', compat: 'dm' },
					{ id: 'slide-right', label: 'Slide desde la derecha', compat: 'dm' },
					{ id: 'zoom', label: 'Zoom in', compat: 'dm' },
					{ id: 'zoom-out', label: 'Zoom out', compat: 'dm' },
					{ id: 'blur', label: 'Blur', compat: 'dm' },
					{ id: 'flip', label: 'Flip 3D', compat: 'd' },
					{ id: 'rotate', label: 'Rotate', compat: 'dm' },
					{ id: 'mask', label: 'Mask (cortina vertical)', compat: 'dm' },
					{ id: 'wipe', label: 'Wipe (cortina horizontal)', compat: 'dm' },
				],
				controls: [
					{
						key: 'distance',
						label: 'Distancia (px)',
						type: 'range',
						min: 4,
						max: 200,
						step: 2,
						default: 28,
						showFor: [ 'rise', 'drop', 'slide-left', 'slide-right' ],
					},
					{ key: 'children', label: 'Animar hijos en cascada', type: 'toggle', default: false },
				],
			},

			text: {
				label: 'Texto',
				kind: 'enter',
				effects: [
					{ id: 'cascade', label: 'Cascada (palabras/letras)', compat: 'dm' },
					{ id: 'typewriter', label: 'Máquina de escribir', compat: 'dm' },
					{ id: 'rotating-typewriter', label: 'Typewriter rotativo', compat: 'dm' },
					{ id: 'scramble', label: 'Decodificar (scramble)', compat: 'dm' },
					{ id: 'glitch', label: 'Glitch Text', compat: 'd' },
					{ id: 'reveal-wall', label: 'Text Reveal Wall', compat: 'dm' },
				],
				controls: [
					{
						key: 'split',
						label: 'Dividir por',
						type: 'select',
						options: [
							{ value: 'words', label: 'Palabras' },
							{ value: 'chars', label: 'Letras' },
						],
						default: 'words',
						showFor: [ 'cascade' ],
					},
					{
						key: 'speed',
						label: 'Letras por segundo',
						type: 'range',
						min: 4,
						max: 90,
						step: 1,
						default: 28,
						showFor: [ 'typewriter', 'rotating-typewriter' ],
					},
					{ key: 'hold', label: 'Pausa por frase (s)', type: 'range', min: 0.4, max: 6, step: 0.1, default: 1.4, showFor: [ 'rotating-typewriter' ] },
					{ key: 'intensity', label: 'Intensidad', type: 'range', min: 0.2, max: 2, step: 0.1, default: 1, showFor: [ 'glitch' ] },
				],
			},

			scroll: {
				label: 'Scroll',
				kind: 'continuous',
				effects: [
					{ id: 'parallax', label: 'Parallax', compat: 'dm' },
					{ id: 'zoom', label: 'Zoom al entrar', compat: 'dm' },
					{ id: 'rotate', label: 'Rotación sutil', compat: 'dm' },
					{ id: 'progress', label: 'Variable --efx-progress (CSS propio)', compat: 'dm' },
				],
				controls: [
					{ key: 'speed', label: 'Velocidad parallax', type: 'range', min: -1, max: 1, step: 0.05, default: 0.25, showFor: [ 'parallax' ] },
					{ key: 'from', label: 'Escala inicial', type: 'range', min: 0.5, max: 1.5, step: 0.01, default: 0.9, showFor: [ 'zoom' ] },
					{ key: 'degrees', label: 'Grados totales', type: 'range', min: -45, max: 45, step: 1, default: 10, showFor: [ 'rotate' ] },
				],
			},

			pointer: {
				label: 'Puntero',
				kind: 'continuous',
				effects: [
					{ id: 'tilt', label: 'Tilt 3D', compat: 'd' },
					{ id: 'magnetic', label: 'Magnético', compat: 'd' },
					{ id: 'spotlight', label: 'Spotlight', compat: 'd' },
					{ id: 'emoji-burst', label: 'Emoji Burst', compat: 'd' },
				],
				controls: [
					{ key: 'strength', label: 'Intensidad tilt (°)', type: 'range', min: 2, max: 25, step: 1, default: 10, showFor: [ 'tilt' ] },
					{ key: 'glare', label: 'Brillo (glare)', type: 'toggle', default: true, showFor: [ 'tilt' ] },
					{ key: 'strength', label: 'Fuerza magnética', type: 'range', min: 0.1, max: 1, step: 0.05, default: 0.35, showFor: [ 'magnetic' ] },
					{ key: 'range', label: 'Radio de atracción (px)', type: 'range', min: 20, max: 300, step: 10, default: 80, showFor: [ 'magnetic' ] },
					{ key: 'size', label: 'Tamaño del foco (px)', type: 'range', min: 120, max: 900, step: 20, default: 320, showFor: [ 'spotlight' ] },
					{ key: 'count', label: 'Cantidad', type: 'range', min: 4, max: 28, step: 1, default: 10, showFor: [ 'emoji-burst' ] },
					{ key: 'intensity', label: 'Intensidad', type: 'range', min: 0.4, max: 2, step: 0.1, default: 1, showFor: [ 'emoji-burst' ] },
				],
			},

			hover: {
				label: 'Hover',
				kind: 'css',
				effects: [
					{ id: 'lift', label: 'Lift (eleva)', compat: 'dm' },
					{ id: 'glow', label: 'Glow', compat: 'dm' },
					{ id: 'shine', label: 'Shine (destello)', compat: 'd' },
					{ id: 'underline', label: 'Subrayado animado', compat: 'dm' },
					{ id: 'zoom-media', label: 'Zoom de imagen', compat: 'dm' },
					{ id: 'frame', label: 'Marco de acento', compat: 'dm' },
					{ id: 'electric-border', label: 'Electric Border', compat: 'd' },
					{ id: 'dot-image-reveal', label: 'Dot Image Reveal', compat: 'dm' },
					{ id: 'image-reveal', label: 'Hover Image Reveal', compat: 'dm' },
					{ id: 'mesh-text', label: 'Mesh Text Hover', compat: 'd' },
				],
				controls: [
					{ key: 'intensity', label: 'Intensidad', type: 'range', min: 0.2, max: 2, step: 0.1, default: 1 },
					{ key: 'color', label: 'Color de acento', type: 'color', default: '', showFor: [ 'glow', 'frame', 'electric-border', 'mesh-text' ] },
					{ key: 'colorB', label: 'Color secundario', type: 'color', default: '', showFor: [ 'electric-border', 'mesh-text' ] },
				],
			},

			ambient: {
				label: 'Ambiente',
				kind: 'css',
				effects: [
					{ id: 'float', label: 'Flotación', compat: 'dm' },
					{ id: 'pulse', label: 'Pulso', compat: 'dm' },
					{ id: 'gradient', label: 'Gradiente en movimiento', compat: 'dm' },
					{ id: 'blob', label: 'Blob orgánico', compat: 'dm' },
					{ id: 'noise', label: 'Grano (noise)', compat: 'dm' },
					{ id: 'glass', label: 'Vidrio (glass)', compat: 'dm' },
					{ id: 'spotlight-text', label: 'Spotlight Text', compat: 'dm' },
				],
				controls: [
					{ key: 'intensity', label: 'Intensidad', type: 'range', min: 0.2, max: 2, step: 0.1, default: 1 },
					{ key: 'speed', label: 'Periodo (s)', type: 'range', min: 2, max: 30, step: 1, default: 8, showFor: [ 'float', 'pulse', 'gradient', 'blob', 'spotlight-text' ] },
					{ key: 'color', label: 'Color A', type: 'color', default: '', showFor: [ 'gradient', 'spotlight-text' ] },
					{ key: 'colorB', label: 'Color B', type: 'color', default: '', showFor: [ 'gradient' ] },
				],
			},

			particles: {
				label: 'Partículas',
				kind: 'continuous',
				effects: [
					{ id: 'dots', label: 'Puntos ambientales', compat: 'dm' },
					{ id: 'line-ripple', label: 'Line Ripple', compat: 'd' },
					{ id: 'blinking-squares', label: 'Blinking Squares', compat: 'dm' },
				],
				controls: [
					{ key: 'density', label: 'Densidad', type: 'range', min: 0.5, max: 2, step: 0.1, default: 1 },
					{ key: 'speed', label: 'Velocidad', type: 'range', min: 0.2, max: 2, step: 0.1, default: 1 },
					{ key: 'color', label: 'Color', type: 'color', default: '#ffffff' },
					{ key: 'link', label: 'Conectar con líneas', type: 'toggle', default: false, showFor: [ 'dots' ] },
					{ key: 'length', label: 'Largo de líneas', type: 'range', min: 0.5, max: 2, step: 0.1, default: 1, showFor: [ 'line-ripple' ] },
					{ key: 'force', label: 'Fuerza cursor', type: 'range', min: 0, max: 2, step: 0.1, default: 1, showFor: [ 'line-ripple' ] },
				],
			},
		},

		timing: {
			triggers: [
				{ value: 'visible', label: 'Al entrar en pantalla' },
				{ value: 'load', label: 'Al cargar la página' },
			],
			easings: [
				{ value: 'expo', label: 'Expo (suave premium)' },
				{ value: 'quint', label: 'Quint (editorial)' },
				{ value: 'spring', label: 'Spring (con rebote)' },
				{ value: 'snap', label: 'Snap (decidido)' },
				{ value: 'linear', label: 'Lineal' },
			],
			defaults: { trigger: 'visible', duration: 700, delay: 0, stagger: 45, easing: 'expo' },
		},

		/* Built-in presets: compositions only, never code. */
		presets: [
			{
				id: 'hero-estandar',
				label: 'Hero Estandar',
				config: {
					reveal: { effect: 'rise', distance: 36 },
					text: { effect: 'cascade', split: 'words' },
					timing: { trigger: 'visible', duration: 850, delay: 0, stagger: 60, easing: 'expo' },
				},
			},
			{
				id: 'editorial-suave',
				label: 'Editorial suave',
				config: {
					reveal: { effect: 'fade' },
					timing: { trigger: 'visible', duration: 950, delay: 0, stagger: 0, easing: 'quint' },
				},
			},
			{
				id: 'titulo-kinetico',
				label: 'Título kinético',
				config: {
					text: { effect: 'scramble' },
					timing: { trigger: 'visible', duration: 900, delay: 0, stagger: 40, easing: 'expo' },
				},
			},
			{
				id: 'tarjeta-producto',
				label: 'Tarjeta de producto',
				config: {
					reveal: { effect: 'rise', distance: 24 },
					hover: { effect: 'lift', intensity: 1 },
					timing: { trigger: 'visible', duration: 650, delay: 0, stagger: 45, easing: 'quint' },
				},
			},
			{
				id: 'cta-magnetico',
				label: 'CTA magnético',
				config: {
					pointer: { effect: 'magnetic', strength: 0.35, range: 90 },
					hover: { effect: 'glow', intensity: 1 },
				},
			},
			{
				id: 'media-inmersiva',
				label: 'Media inmersiva',
				config: {
					reveal: { effect: 'mask' },
					scroll: { effect: 'parallax', speed: 0.2 },
					hover: { effect: 'zoom-media', intensity: 1 },
					timing: { trigger: 'visible', duration: 900, delay: 0, stagger: 0, easing: 'expo' },
				},
			},
			{
				id: 'panel-vidrio',
				label: 'Panel de vidrio',
				config: {
					ambient: { effect: 'glass', intensity: 1 },
					reveal: { effect: 'blur' },
					timing: { trigger: 'visible', duration: 800, delay: 0, stagger: 0, easing: 'quint' },
				},
			},
			{
				id: 'fondo-vivo',
				label: 'Fondo vivo (cover)',
				config: {
					particles: { effect: 'dots', density: 1, speed: 0.8, link: false, color: '#ffffff' },
					scroll: { effect: 'zoom', from: 0.96 },
				},
			},
		],
	};
} )();
