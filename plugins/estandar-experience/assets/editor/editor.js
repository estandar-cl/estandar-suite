/* Estandar Experience — editor (Gutenberg / FSE).
 *
 * 1. Registra los bloques estructurales (render server-side, sin build).
 * 2. Añade el atributo `estandarFx` a los bloques soportados.
 * 3. Panel "Experiencia Estandar" en el inspector, generado desde el catálogo
 *    (window.EstandarFXCatalog), con presets, timing y opciones avanzadas.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.element ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var __ = wp.i18n ? wp.i18n.__ : function ( s ) { return s; };
	var components = wp.components || {};
	var blockEditor = wp.blockEditor || {};
	var hooks = wp.hooks || {};
	var useBlockProps = blockEditor.useBlockProps || function ( props ) { return props || {}; };
	var InnerBlocks = blockEditor.InnerBlocks;
	var catalog = window.EstandarFXCatalog || null;
	var meta = window.ESTANDAR_EXPERIENCE_BLOCKS || {};
	var supported = window.ESTANDAR_EXPERIENCE_SUPPORTED || [];
	var ATTR = ( catalog && catalog.attribute ) || 'estandarFx';

	/* Preview liviano: abre el bloque renderizado (con su FX) en una pestaña. */
	function buildPreviewUrl( props ) {
		var cfg = window.ESTANDAR_EXPERIENCE_PREVIEW;
		if ( ! cfg || ! cfg.url || ! wp.blocks.serialize || ! wp.blocks.createBlock ) { return null; }
		var markup;
		try {
			markup = wp.blocks.serialize( [ wp.blocks.createBlock( props.name, props.attributes ) ] );
		} catch ( e ) { return null; }
		var b64 = btoa( unescape( encodeURIComponent( markup ) ) );
		return cfg.url + '&markup=' + encodeURIComponent( b64 );
	}

	function previewControl( props ) {
		return el( components.Button, {
			key: 'preview',
			variant: 'secondary',
			onClick: function () {
				var u = buildPreviewUrl( props );
				if ( u ) { window.open( u, '_blank', 'noopener' ); }
			}
		}, __( 'Abrir preview en vivo', 'estandar-experience' ) );
	}

	/* ── 1. Atributo estandarFx en bloques soportados ──────────────── */
	if ( hooks.addFilter ) {
		hooks.addFilter( 'blocks.registerBlockType', 'estandar/experience', function ( settings, name ) {
			if ( supported.indexOf( name ) === -1 ) {
				return settings;
			}
			var attrs = settings.attributes || {};
			if ( ! attrs[ ATTR ] ) {
				attrs = Object.assign( {}, attrs );
				attrs[ ATTR ] = { type: 'object', default: {} };
			}
			settings.attributes = attrs;
			return settings;
		} );
	}

	/* ── 2b. Editor específico: galería horizontal parallax ─────────── */
	function updateSlideList( slides, i, patch ) {
		return slides.map( function ( s, idx ) { return idx === i ? Object.assign( {}, s, patch ) : s; } );
	}

	function HPGEdit( props ) {
		var attrs = props.attributes;
		var slides = Array.isArray( attrs.slides ) ? attrs.slides : [];
		var title = ( meta[ props.name ] || {} ).title || props.name;
		var blockProps = useBlockProps( { className: 'efx-editor efx-editor--gallery' } );

		function setSlides( next ) { props.setAttributes( { slides: next } ); }
		function addMedia( media ) {
			var arr = Array.isArray( media ) ? media : [ media ];
			setSlides( slides.concat( arr.map( function ( mm ) {
				return { id: mm.id, url: mm.url, alt: mm.alt || '', title: mm.caption || mm.title || '' };
			} ) ) );
		}
		function removeAt( i ) { var n = slides.slice(); n.splice( i, 1 ); setSlides( n ); }
		function move( i, dir ) { var n = slides.slice(), j = i + dir; if ( j < 0 || j >= n.length ) { return; } var t = n[ i ]; n[ i ] = n[ j ]; n[ j ] = t; setSlides( n ); }

		var MediaUpload = blockEditor.MediaUpload;
		var MediaUploadCheck = blockEditor.MediaUploadCheck;
		var Button = components.Button;
		var TextControl = components.TextControl;
		var RangeControl = components.RangeControl;
		var ToggleControl = components.ToggleControl;

		var rows = slides.map( function ( s, i ) {
			return el( 'div', { className: 'efx-editor-slide', key: i },
				el( 'img', { className: 'efx-editor-slide__thumb', src: s.url, alt: s.alt || '' } ),
				el( 'div', { className: 'efx-editor-slide__fields' },
					el( TextControl, { label: __( 'Título', 'estandar-experience' ), value: s.title || '', onChange: function ( v ) { setSlides( updateSlideList( slides, i, { title: v } ) ); } } ),
					el( TextControl, { label: 'Alt', value: s.alt || '', onChange: function ( v ) { setSlides( updateSlideList( slides, i, { alt: v } ) ); } } )
				),
				el( 'div', { className: 'efx-editor-slide__actions' },
					el( Button, { size: 'small', variant: 'tertiary', onClick: function () { move( i, -1 ); } }, '↑' ),
					el( Button, { size: 'small', variant: 'tertiary', onClick: function () { move( i, 1 ); } }, '↓' ),
					el( Button, { size: 'small', variant: 'tertiary', isDestructive: true, onClick: function () { removeAt( i ); } }, __( 'Quitar', 'estandar-experience' ) )
				)
			);
		} );

		var addButton = ( MediaUpload && MediaUploadCheck ) ? el( MediaUploadCheck, null,
			el( MediaUpload, {
				multiple: true,
				gallery: true,
				allowedTypes: [ 'image' ],
				value: slides.map( function ( s ) { return s.id; } ).filter( Boolean ),
				onSelect: addMedia,
				render: function ( o ) {
					return el( Button, { variant: 'secondary', onClick: o.open }, slides.length ? __( 'Agregar imágenes', 'estandar-experience' ) : __( 'Elegir imágenes', 'estandar-experience' ) );
				}
			} )
		) : null;

		var inspector = [
			el( RangeControl, { key: 'imageWidth', label: __( 'Ancho de imagen (px)', 'estandar-experience' ), value: attrs.imageWidth, min: 180, max: 1600, step: 10, onChange: function ( v ) { props.setAttributes( { imageWidth: v } ); } } ),
			el( RangeControl, { key: 'imageHeight', label: __( 'Alto de imagen (px)', 'estandar-experience' ), value: ( attrs.imageHeight != null ? attrs.imageHeight : 460 ), min: 120, max: 1200, step: 10, onChange: function ( v ) { props.setAttributes( { imageHeight: v } ); } } ),
			el( RangeControl, { key: 'radius', label: __( 'Redondeo de esquinas (px)', 'estandar-experience' ), value: ( attrs.radius != null ? attrs.radius : 21 ), min: 0, max: 60, step: 1, onChange: function ( v ) { props.setAttributes( { radius: v } ); } } ),
			el( 'div', { key: 'bgColor', className: 'efx-control-color' },
				el( 'span', { className: 'efx-control-color__label' }, __( 'Color de fondo', 'estandar-experience' ) ),
				el( 'input', { type: 'color', value: ( attrs.bgColor || '#050505' ), onChange: function ( e ) { props.setAttributes( { bgColor: e.target.value } ); } } )
			),
			el( RangeControl, { key: 'gap', label: __( 'Separación (px)', 'estandar-experience' ), value: attrs.gap, min: 0, max: 90, step: 2, onChange: function ( v ) { props.setAttributes( { gap: v } ); } } ),
			el( RangeControl, { key: 'speed', label: __( 'Velocidad', 'estandar-experience' ), help: __( '0.3 muy lento · 1 normal · 3 rápido.', 'estandar-experience' ), value: attrs.speed, min: 0.3, max: 3, step: 0.1, onChange: function ( v ) { props.setAttributes( { speed: v } ); } } ),
			el( RangeControl, { key: 'depth', label: __( 'Profundidad', 'estandar-experience' ), value: attrs.depth, min: 0, max: 2.5, step: 0.1, onChange: function ( v ) { props.setAttributes( { depth: v } ); } } ),
			el( ToggleControl, { key: 'heroMode', label: __( 'Modo hero', 'estandar-experience' ), checked: !! attrs.heroMode, onChange: function ( v ) { props.setAttributes( { heroMode: v } ); } } ),
			el( ToggleControl, { key: 'showCaptions', label: __( 'Mostrar títulos', 'estandar-experience' ), checked: !! attrs.showCaptions, onChange: function ( v ) { props.setAttributes( { showCaptions: v } ); } } ),
			el( components.SelectControl, { key: 'endAlign', label: __( 'Alineación final', 'estandar-experience' ), help: __( 'Dónde queda la última foto al terminar el carrusel.', 'estandar-experience' ), value: attrs.endAlign, options: [ { value: 'right', label: 'Última foto a la derecha' }, { value: 'center', label: 'Centrada' }, { value: 'left', label: 'Última foto a la izquierda' } ], onChange: function ( v ) { props.setAttributes( { endAlign: v } ); } } ),
			previewControl( props )
		];

		return el( Fragment, null,
			el( blockEditor.InspectorControls, null, el( components.PanelBody, { title: title, initialOpen: true }, inspector ) ),
			el( 'div', blockProps,
				el( 'div', { className: 'efx-editor__hint' }, el( 'strong', null, title ) ),
				el( 'div', { className: 'efx-editor-gallery__preview' }, slides.length
					? slides.map( function ( s, i ) { return el( 'img', { key: i, src: s.url, alt: s.alt || '' } ); } )
					: el( 'span', { className: 'es-muted' }, __( 'Sin imágenes todavía.', 'estandar-experience' ) ) ),
				addButton,
				rows.length ? el( 'div', { className: 'efx-editor-slides' }, rows ) : null
			)
		);
	}

	function PinEdit( props ) {
		var attrs = props.attributes;
		var title = ( meta[ props.name ] || {} ).title || props.name;
		var blockProps = useBlockProps( { className: 'efx-editor efx-editor--pin' } );
		var MediaUpload = blockEditor.MediaUpload;
		var MediaUploadCheck = blockEditor.MediaUploadCheck;
		var Button = components.Button;
		var TextControl = components.TextControl;
		var TextareaControl = components.TextareaControl;
		var SelectControl = components.SelectControl;
		var RangeControl = components.RangeControl;

		var picker = ( MediaUpload && MediaUploadCheck ) ? el( MediaUploadCheck, null,
			el( MediaUpload, {
				multiple: false,
				allowedTypes: [ 'image' ],
				value: attrs.imageUrl || '',
				onSelect: function ( m ) { props.setAttributes( { imageUrl: m.url } ); },
				render: function ( o ) {
					return el( Button, { variant: 'secondary', onClick: o.open }, attrs.imageUrl ? __( 'Cambiar imagen', 'estandar-experience' ) : __( 'Elegir imagen', 'estandar-experience' ) );
				}
			} )
		) : null;

		var inspector = [
			el( SelectControl, { key: 'mode', label: __( 'Modo', 'estandar-experience' ), value: attrs.mode, options: [
				{ value: 'mask-zoom', label: 'Mask + Zoom' },
				{ value: 'circle', label: 'Circle' },
				{ value: 'zoom', label: 'Zoom' },
				{ value: 'cinematic', label: 'Cinematic' }
			], onChange: function ( v ) { props.setAttributes( { mode: v } ); } } ),
			el( RangeControl, { key: 'height', label: __( 'Alto de la sección (vh)', 'estandar-experience' ), value: attrs.height, min: 110, max: 320, step: 5, onChange: function ( v ) { props.setAttributes( { height: v } ); } } ),
			el( RangeControl, { key: 'startScale', label: __( 'Escala inicial', 'estandar-experience' ), value: attrs.startScale, min: 1, max: 2, step: 0.01, onChange: function ( v ) { props.setAttributes( { startScale: v } ); } } ),
			el( RangeControl, { key: 'endScale', label: __( 'Escala final', 'estandar-experience' ), value: attrs.endScale, min: 0.75, max: 1.4, step: 0.01, onChange: function ( v ) { props.setAttributes( { endScale: v } ); } } ),
			el( RangeControl, { key: 'radius', label: __( 'Redondeo de esquinas (px)', 'estandar-experience' ), value: attrs.radius, min: 0, max: 80, step: 1, onChange: function ( v ) { props.setAttributes( { radius: v } ); } } ),
			el( RangeControl, { key: 'overlayOpacity', label: __( 'Opacidad del oscurecido', 'estandar-experience' ), value: attrs.overlayOpacity, min: 0, max: 0.85, step: 0.05, onChange: function ( v ) { props.setAttributes( { overlayOpacity: v } ); } } ),
			previewControl( props )
		];

		return el( Fragment, null,
			el( blockEditor.InspectorControls, null, el( components.PanelBody, { title: title, initialOpen: true }, inspector ) ),
			el( 'div', blockProps,
				el( 'div', { className: 'efx-editor__hint' }, el( 'strong', null, title ) ),
				attrs.imageUrl ? el( 'img', { className: 'efx-editor-pin__preview', src: attrs.imageUrl, alt: attrs.title || '' } ) : el( 'span', { className: 'es-muted' }, __( 'Sin imagen todavía.', 'estandar-experience' ) ),
				picker,
				el( TextControl, { label: __( 'Título', 'estandar-experience' ), value: attrs.title || '', onChange: function ( v ) { props.setAttributes( { title: v } ); } } ),
				el( TextareaControl, { label: __( 'Texto', 'estandar-experience' ), value: attrs.body || '', onChange: function ( v ) { props.setAttributes( { body: v } ); } } )
			)
		);
	}

	function MarqueeEdit( props ) {
		var attrs = props.attributes;
		var title = ( meta[ props.name ] || {} ).title || props.name;
		var blockProps = useBlockProps( { className: 'efx-editor efx-editor--marquee' } );
		var RangeControl = components.RangeControl;
		var SelectControl = components.SelectControl;
		var ToggleControl = components.ToggleControl;
		var inspector = [
			el( RangeControl, { key: 'speed', label: __( 'Velocidad', 'estandar-experience' ), value: attrs.speed, min: 0, max: 200, step: 5, onChange: function ( v ) { props.setAttributes( { speed: v } ); } } ),
			el( SelectControl, { key: 'direction', label: __( 'Dirección', 'estandar-experience' ), value: attrs.direction, options: [ { value: 'left', label: 'Izquierda' }, { value: 'right', label: 'Derecha' } ], onChange: function ( v ) { props.setAttributes( { direction: v } ); } } ),
			el( RangeControl, { key: 'gap', label: __( 'Separación (px)', 'estandar-experience' ), value: attrs.gap, min: 0, max: 120, step: 4, onChange: function ( v ) { props.setAttributes( { gap: v } ); } } ),
			el( ToggleControl, { key: 'hoverPause', label: __( 'Pausar al pasar el cursor', 'estandar-experience' ), checked: !! attrs.hoverPause, onChange: function ( v ) { props.setAttributes( { hoverPause: v } ); } } ),
			el( ToggleControl, { key: 'fade', label: __( 'Degradado en los bordes', 'estandar-experience' ), checked: !! attrs.fade, onChange: function ( v ) { props.setAttributes( { fade: v } ); } } ),
			previewControl( props )
		];
		return el( Fragment, null,
			el( blockEditor.InspectorControls, null, el( components.PanelBody, { title: title, initialOpen: true }, inspector ) ),
			el( 'div', blockProps,
				el( 'div', { className: 'efx-editor__hint' }, el( 'strong', null, title ), el( 'span', null, ' — ' + __( 'contenido de la cinta', 'estandar-experience' ) ) ),
				InnerBlocks ? el( InnerBlocks ) : null
			)
		);
	}

	/* Editor genérico para bloques con colecciones de imágenes (slides/items/panels). */
	var COLLECTION_EDITORS = {
		'estandar/coverflow-gallery': { key: 'slides', image: 'url', fields: [ 'title', 'alt' ], controls: [
			{ k: 'autoplay', t: 'toggle', l: __( 'Autoplay', 'estandar-experience' ) },
			{ k: 'interval', t: 'range', l: __( 'Intervalo (ms)', 'estandar-experience' ), min: 1600, max: 15000, step: 100 },
			{ k: 'cardWidth', t: 'range', l: __( 'Ancho tarjeta (px)', 'estandar-experience' ), min: 180, max: 720, step: 10 },
			{ k: 'gap', t: 'range', l: __( 'Separación (px)', 'estandar-experience' ), min: 24, max: 220, step: 2 },
			{ k: 'tilt', t: 'range', l: __( 'Inclinación', 'estandar-experience' ), min: 0, max: 80, step: 1 },
			{ k: 'radius', t: 'range', l: __( 'Redondeo (px)', 'estandar-experience' ), min: 0, max: 80, step: 1 },
			{ k: 'showTitles', t: 'toggle', l: __( 'Mostrar títulos', 'estandar-experience' ) }
		] },
		'estandar/spatial-gallery': { key: 'slides', image: 'url', fields: [ 'title', 'alt' ], controls: [
			{ k: 'height', t: 'range', l: __( 'Alto (px)', 'estandar-experience' ), min: 300, max: 1000, step: 10 },
			{ k: 'imageWidth', t: 'range', l: __( 'Ancho imagen (px)', 'estandar-experience' ), min: 120, max: 520, step: 10 },
			{ k: 'spread', t: 'range', l: __( 'Dispersión', 'estandar-experience' ), min: 0.5, max: 2, step: 0.05 },
			{ k: 'focusScale', t: 'range', l: __( 'Escala foco', 'estandar-experience' ), min: 1, max: 2, step: 0.01 },
			{ k: 'showCaptions', t: 'toggle', l: __( 'Mostrar títulos', 'estandar-experience' ) },
			{ k: 'showIndex', t: 'toggle', l: __( 'Mostrar índice', 'estandar-experience' ) }
		] },
		'estandar/magnetic-carousel': { key: 'slides', image: 'url', fields: [ 'title', 'alt' ], controls: [
			{ k: 'collapsedWidth', t: 'range', l: __( 'Ancho colapsado (px)', 'estandar-experience' ), min: 40, max: 200, step: 2 },
			{ k: 'hoverWidth', t: 'range', l: __( 'Ancho activo (px)', 'estandar-experience' ), min: 120, max: 400, step: 5 },
			{ k: 'height', t: 'range', l: __( 'Alto (px)', 'estandar-experience' ), min: 200, max: 800, step: 10 },
			{ k: 'gap', t: 'range', l: __( 'Separación (px)', 'estandar-experience' ), min: 0, max: 60, step: 2 },
			{ k: 'influence', t: 'range', l: __( 'Influencia', 'estandar-experience' ), min: 80, max: 400, step: 10 },
			{ k: 'radius', t: 'range', l: __( 'Redondeo (px)', 'estandar-experience' ), min: 0, max: 80, step: 1 },
			{ k: 'blur', t: 'range', l: __( 'Desenfoque', 'estandar-experience' ), min: 0, max: 20, step: 1 },
			{ k: 'showTitles', t: 'toggle', l: __( 'Mostrar títulos', 'estandar-experience' ) }
		] },
		'estandar/sticky-grid-scroll': { key: 'slides', image: 'url', fields: [ 'title', 'alt' ], controls: [
			{ k: 'columns', t: 'range', l: __( 'Columnas', 'estandar-experience' ), min: 1, max: 6, step: 1 },
			{ k: 'height', t: 'range', l: __( 'Alto (vh)', 'estandar-experience' ), min: 80, max: 400, step: 10 },
			{ k: 'gap', t: 'range', l: __( 'Separación (px)', 'estandar-experience' ), min: 0, max: 60, step: 2 },
			{ k: 'radius', t: 'range', l: __( 'Redondeo (px)', 'estandar-experience' ), min: 0, max: 60, step: 1 },
			{ k: 'travel', t: 'range', l: __( 'Recorrido', 'estandar-experience' ), min: 0, max: 500, step: 10 },
			{ k: 'showCaptions', t: 'toggle', l: __( 'Mostrar títulos', 'estandar-experience' ) }
		] },
		'estandar/scroll-synced-carousel': { key: 'slides', image: 'url', fields: [ 'title', 'alt' ], controls: [
			{ k: 'height', t: 'range', l: __( 'Alto (vh)', 'estandar-experience' ), min: 40, max: 160, step: 2 },
			{ k: 'cardWidth', t: 'range', l: __( 'Ancho tarjeta (px)', 'estandar-experience' ), min: 200, max: 900, step: 10 },
			{ k: 'gap', t: 'range', l: __( 'Separación (px)', 'estandar-experience' ), min: 0, max: 80, step: 2 },
			{ k: 'showProgress', t: 'toggle', l: __( 'Mostrar progreso', 'estandar-experience' ) },
			{ k: 'snap', t: 'toggle', l: __( 'Ajuste a tarjeta', 'estandar-experience' ) },
			{ k: 'accentColor', t: 'color', l: __( 'Color de acento', 'estandar-experience' ) }
		] },
		'estandar/cover-transition': { key: 'items', image: 'imageUrl', fields: [ 'title' ], controls: [
			{ k: 'height', t: 'range', l: __( 'Alto (px)', 'estandar-experience' ), min: 300, max: 1000, step: 10 },
			{ k: 'duration', t: 'range', l: __( 'Duración (ms)', 'estandar-experience' ), min: 200, max: 2000, step: 20 },
			{ k: 'showMeta', t: 'toggle', l: __( 'Mostrar meta', 'estandar-experience' ) },
			{ k: 'overlayColor', t: 'color', l: __( 'Color overlay', 'estandar-experience' ) },
			{ k: 'textColor', t: 'color', l: __( 'Color de texto', 'estandar-experience' ) }
		] },
		'estandar/scroll-panels': { key: 'panels', image: 'imageUrl', fields: [ 'title', 'body' ], controls: [
			{ k: 'height', t: 'range', l: __( 'Alto (vh)', 'estandar-experience' ), min: 55, max: 120, step: 1 },
			{ k: 'effect', t: 'select', l: __( 'Efecto', 'estandar-experience' ), options: [ { value: 'slide', label: 'Slide' }, { value: 'scale', label: 'Scale' }, { value: 'fade', label: 'Fade' } ] },
			{ k: 'showProgress', t: 'toggle', l: __( 'Mostrar progreso', 'estandar-experience' ) },
			{ k: 'stickyMedia', t: 'toggle', l: __( 'Media fija', 'estandar-experience' ) },
			{ k: 'accentColor', t: 'color', l: __( 'Color de acento', 'estandar-experience' ) }
		] },
		'estandar/container-split-text': { key: 'panels', image: 'imageUrl', fields: [ 'title', 'body' ], controls: [
			{ k: 'height', t: 'range', l: __( 'Alto (vh)', 'estandar-experience' ), min: 40, max: 160, step: 2 },
			{ k: 'textEffect', t: 'select', l: __( 'Efecto de texto', 'estandar-experience' ), options: [ { value: 'cascade', label: 'Cascade' }, { value: 'typewriter', label: 'Typewriter' }, { value: 'scramble', label: 'Scramble' }, { value: 'glitch', label: 'Glitch' } ] },
			{ k: 'showProgress', t: 'toggle', l: __( 'Mostrar progreso', 'estandar-experience' ) },
			{ k: 'backgroundColor', t: 'color', l: __( 'Color de fondo', 'estandar-experience' ) },
			{ k: 'textColor', t: 'color', l: __( 'Color de texto', 'estandar-experience' ) },
			{ k: 'accentColor', t: 'color', l: __( 'Color de acento', 'estandar-experience' ) }
		] }
	};

	function collectionControl( def, attrs, set ) {
		var val = attrs[ def.k ];
		if ( def.t === 'toggle' ) {
			return el( components.ToggleControl, { key: def.k, label: def.l, checked: !! val, onChange: function ( v ) { set( def.k, v ); } } );
		}
		if ( def.t === 'range' ) {
			return el( components.RangeControl, { key: def.k, label: def.l, value: ( val != null ? val : def.min ), min: def.min, max: def.max, step: def.step, onChange: function ( v ) { set( def.k, v ); } } );
		}
		if ( def.t === 'select' ) {
			return el( components.SelectControl, { key: def.k, label: def.l, value: val, options: def.options, onChange: function ( v ) { set( def.k, v ); } } );
		}
		if ( def.t === 'color' ) {
			return el( 'div', { key: def.k, className: 'efx-control-color' },
				el( 'span', { className: 'efx-control-color__label' }, def.l ),
				el( 'input', { type: 'color', value: val || '#111111', onChange: function ( e ) { set( def.k, e.target.value ); } } )
			);
		}
		return null;
	}

	function CollectionEdit( props ) {
		var cfg = COLLECTION_EDITORS[ props.name ];
		var attrs = props.attributes;
		var items = Array.isArray( attrs[ cfg.key ] ) ? attrs[ cfg.key ] : [];
		var title = ( meta[ props.name ] || {} ).title || props.name;
		var blockProps = useBlockProps( { className: 'efx-editor efx-editor--gallery' } );
		var MediaUpload = blockEditor.MediaUpload;
		var MediaUploadCheck = blockEditor.MediaUploadCheck;
		var Button = components.Button;
		var TextControl = components.TextControl;
		var TextareaControl = components.TextareaControl;

		function setItems( next ) { props.setAttributes( cfg.key, next ); }
		function addMedia( media ) {
			var arr = Array.isArray( media ) ? media : [ media ];
			var mapped = arr.map( function ( mm ) {
				var o = { id: mm.id, alt: mm.alt || '', title: mm.caption || mm.title || '' };
				o[ cfg.image ] = mm.url;
				return o;
			} );
			setItems( items.concat( mapped ) );
		}
		function removeAt( i ) { var n = items.slice(); n.splice( i, 1 ); setItems( n ); }
		function move( i, d ) { var n = items.slice(), j = i + d; if ( j < 0 || j >= n.length ) { return; } var t = n[ i ]; n[ i ] = n[ j ]; n[ j ] = t; setItems( n ); }
		function patchAt( i, patch ) { setItems( items.map( function ( s, idx ) { return idx === i ? Object.assign( {}, s, patch ) : s; } ) ); }

		var imageUrl = function ( s ) { return s[ cfg.image ] || ''; };

		var rows = items.map( function ( s, i ) {
			var fields = ( cfg.fields || [] ).map( function ( f ) {
				if ( f === 'body' ) {
					return el( TextareaControl, { key: f, label: __( 'Texto', 'estandar-experience' ), value: s.body || '', onChange: function ( v ) { patchAt( i, { body: v } ); } } );
				}
				if ( f === 'alt' ) {
					return el( TextControl, { key: f, label: 'Alt', value: s.alt || '', onChange: function ( v ) { patchAt( i, { alt: v } ); } } );
				}
				return el( TextControl, { key: f, label: __( 'Título', 'estandar-experience' ), value: s.title || '', onChange: function ( v ) { patchAt( i, { title: v } ); } } );
			} );
			return el( 'div', { className: 'efx-editor-slide', key: i },
				el( 'img', { className: 'efx-editor-slide__thumb', src: imageUrl( s ), alt: s.alt || '' } ),
				el( 'div', { className: 'efx-editor-slide__fields' }, fields ),
				el( 'div', { className: 'efx-editor-slide__actions' },
					el( Button, { size: 'small', variant: 'tertiary', onClick: function () { move( i, -1 ); } }, '↑' ),
					el( Button, { size: 'small', variant: 'tertiary', onClick: function () { move( i, 1 ); } }, '↓' ),
					el( Button, { size: 'small', variant: 'tertiary', isDestructive: true, onClick: function () { removeAt( i ); } }, __( 'Quitar', 'estandar-experience' ) )
				)
			);
		} );

		var addButton = ( MediaUpload && MediaUploadCheck ) ? el( MediaUploadCheck, null,
			el( MediaUpload, {
				multiple: true,
				gallery: true,
				allowedTypes: [ 'image' ],
				value: items.map( function ( s ) { return s.id; } ).filter( Boolean ),
				onSelect: addMedia,
				render: function ( o ) {
					return el( Button, { variant: 'secondary', onClick: o.open }, items.length ? __( 'Agregar imágenes', 'estandar-experience' ) : __( 'Elegir imágenes', 'estandar-experience' ) );
				}
			} )
		) : null;

		var inspector = ( cfg.controls || [] ).map( function ( def ) {
			return collectionControl( def, attrs, function ( k, v ) { props.setAttributes( { [ k ]: v } ); } );
		} );
		inspector.push( previewControl( props ) );

		return el( Fragment, null,
			el( blockEditor.InspectorControls, null, el( components.PanelBody, { title: title, initialOpen: true }, inspector ) ),
			el( 'div', blockProps,
				el( 'div', { className: 'efx-editor__hint' }, el( 'strong', null, title ) ),
				el( 'div', { className: 'efx-editor-gallery__preview' }, items.length
					? items.map( function ( s, i ) { return el( 'img', { key: i, src: imageUrl( s ), alt: s.alt || '' } ); } )
					: el( 'span', { className: 'es-muted' }, __( 'Sin imágenes todavía.', 'estandar-experience' ) ) ),
				addButton,
				rows.length ? el( 'div', { className: 'efx-editor-slides' }, rows ) : null
			)
		);
	}

	function BeforeAfterEdit( props ) {
		var attrs = props.attributes;
		var title = ( meta[ props.name ] || {} ).title || props.name;
		var blockProps = useBlockProps( { className: 'efx-editor efx-editor--pin' } );
		var MediaUpload = blockEditor.MediaUpload;
		var MediaUploadCheck = blockEditor.MediaUploadCheck;
		var Button = components.Button;
		var TextControl = components.TextControl;
		var RangeControl = components.RangeControl;

		function picker( label, key ) {
			if ( ! MediaUpload || ! MediaUploadCheck ) { return null; }
			return el( MediaUploadCheck, null,
				el( MediaUpload, {
					multiple: false,
					allowedTypes: [ 'image' ],
					value: attrs[ key ] || '',
					onSelect: function ( m ) { props.setAttributes( { [ key ]: m.url } ); },
					render: function ( o ) { return el( Button, { variant: 'secondary', onClick: o.open }, attrs[ key ] ? ( label + ' ✓' ) : label ); }
				} )
			);
		}

		var inspector = [
			el( RangeControl, { key: 'startPosition', label: __( 'Posición inicial (%)', 'estandar-experience' ), value: attrs.startPosition, min: 0, max: 100, step: 1, onChange: function ( v ) { props.setAttributes( { startPosition: v } ); } } ),
			previewControl( props )
		];

		return el( Fragment, null,
			el( blockEditor.InspectorControls, null, el( components.PanelBody, { title: title, initialOpen: true }, inspector ) ),
			el( 'div', blockProps,
				el( 'div', { className: 'efx-editor__hint' }, el( 'strong', null, title ) ),
				el( 'div', { className: 'efx-editor-slides' },
					el( 'div', { className: 'efx-editor-slide' }, attrs.beforeUrl ? el( 'img', { className: 'efx-editor-slide__thumb', src: attrs.beforeUrl, alt: '' } ) : null, el( TextControl, { label: __( 'Antes', 'estandar-experience' ), value: attrs.beforeLabel || '', onChange: function ( v ) { props.setAttributes( { beforeLabel: v } ); } } ), picker( __( 'Elegir "antes"', 'estandar-experience' ), 'beforeUrl' ) ),
					el( 'div', { className: 'efx-editor-slide' }, attrs.afterUrl ? el( 'img', { className: 'efx-editor-slide__thumb', src: attrs.afterUrl, alt: '' } ) : null, el( TextControl, { label: __( 'Después', 'estandar-experience' ), value: attrs.afterLabel || '', onChange: function ( v ) { props.setAttributes( { afterLabel: v } ); } } ), picker( __( 'Elegir "después"', 'estandar-experience' ), 'afterUrl' ) )
				)
			)
		);
	}

	function makeEditor( name, m ) {
		if ( name === 'estandar/horizontal-parallax-gallery' && blockEditor.MediaUpload ) {
			return HPGEdit;
		}
		if ( name === 'estandar/pinned-image-reveal' && blockEditor.MediaUpload ) {
			return PinEdit;
		}
		if ( COLLECTION_EDITORS[ name ] && blockEditor.MediaUpload ) {
			return CollectionEdit;
		}
		if ( name === 'estandar/before-after' && blockEditor.MediaUpload ) {
			return BeforeAfterEdit;
		}
		if ( name === 'estandar/marquee' ) {
			return MarqueeEdit;
		}
		return function ( props ) {
			var blockProps = useBlockProps( { className: 'efx-editor' } );
			var hint = el(
				'div',
				{ className: 'efx-editor__hint' },
				el( 'strong', null, m.title || name ),
				m.description ? el( 'span', null, ' — ' + m.description ) : null
			);
			if ( m.innerBlocks && InnerBlocks ) {
				return el( 'div', blockProps, hint, el( InnerBlocks, { template: m.template || undefined } ) );
			}
			var keys = Object.keys( props.attributes || {} );
			return el( 'div', blockProps, hint, keys.length ? el( 'ul', { className: 'efx-editor__attrs' }, keys.map( function ( k ) {
				return el( 'li', { key: k }, k + ': ' + String( props.attributes[ k ] ) );
			} ) ) : null, previewControl( props ) );
		};
	}

	/* ── 2. Bloques estructurales (registro cliente) ───────────────── */
	Object.keys( meta ).forEach( function ( name ) {
		if ( wp.blocks.getBlockType && wp.blocks.getBlockType( name ) ) {
			return;
		}

		var m = meta[ name ];

		wp.blocks.registerBlockType( name, {
			apiVersion: 3,
			title: m.title || name,
			description: m.description || '',
			icon: m.icon || 'star-filled',
			category: 'estandar-experience',
			keywords: m.keywords || [],
			attributes: m.attributes || {},
			supports: m.supports || {},

			edit: makeEditor( name, m ),

			save: function () {
				return null;
			},
		} );
	} );

	/* ── 3. Panel de Experiencia ───────────────────────────────────── */
	if ( ! catalog || ! components.PanelBody || ! blockEditor.InspectorControls || ! hooks.addFilter ) {
		return;
	}

	function deepCopy( obj ) {
		return JSON.parse( JSON.stringify( obj || {} ) );
	}

	function renderControl( ctrl, value, onChange ) {
		var val = ( value === undefined || value === null ) ? ctrl.default : value;

		switch ( ctrl.type ) {
			case 'toggle':
				return el( components.ToggleControl, { key: ctrl.key, label: ctrl.label, checked: !! val, onChange: onChange } );
			case 'range':
				return el( components.RangeControl, { key: ctrl.key, label: ctrl.label, value: val, min: ctrl.min, max: ctrl.max, step: ctrl.step, onChange: onChange } );
			case 'select':
				return el( components.SelectControl, { key: ctrl.key, label: ctrl.label, value: val, options: ctrl.options, onChange: onChange } );
			case 'color':
				return el(
					'div',
					{ key: ctrl.key, className: 'efx-control-color' },
					el( 'span', { className: 'efx-control-color__label' }, ctrl.label ),
					el( 'input', {
						type: 'color',
						value: val || '#ffffff',
						onChange: function ( e ) { onChange( e.target.value ); },
					} )
				);
			default:
				return null;
		}
	}

	var Panel = function ( props ) {
		var fx = props.attributes[ ATTR ] || {};

		function update( mutator ) {
			var next = deepCopy( fx );
			mutator( next );
			var patch = {};
			patch[ ATTR ] = next;
			props.setAttributes( patch );
		}

		var children = [];

		// Presets.
		if ( catalog.presets && catalog.presets.length ) {
			var presetOpts = [ { value: '', label: '— Elegir un preset —' } ].concat(
				catalog.presets.map( function ( p ) { return { value: p.id, label: p.label }; } )
			);
			children.push( el( components.SelectControl, {
				key: 'presets',
				label: __( 'Presets', 'estandar-experience' ),
				value: '',
				options: presetOpts,
				onChange: function ( id ) {
					var p = catalog.presets.filter( function ( x ) { return x.id === id; } )[ 0 ];
					if ( p ) {
						props.setAttributes( { [ ATTR ]: deepCopy( p.config ) } );
					}
				},
			} ) );
		}

		// Módulos.
		catalog.order.forEach( function ( mid ) {
			var mod = catalog.modules[ mid ];
			if ( ! mod ) {
				return;
			}

			var cur = fx[ mid ];
			var enabled = !! cur;
			var effect = cur && cur.effect;
			var controls = [];

			if ( enabled ) {
				var effects = ( mod.effects || [] ).map( function ( e ) {
					return { value: e.id, label: e.label + ( e.compat === 'd' ? ' · desktop' : '' ) };
				} );

				controls.push( el( components.SelectControl, {
					key: 'effect',
					label: __( 'Efecto', 'estandar-experience' ),
					value: effect,
					options: effects,
					onChange: function ( v ) {
						update( function ( n ) {
							n[ mid ] = n[ mid ] || {};
							n[ mid ].effect = v;
						} );
					},
				} ) );

				( mod.controls || [] ).forEach( function ( ctrl ) {
					if ( ctrl.showFor && effect && ctrl.showFor.indexOf( effect ) === -1 ) {
						return;
					}
					controls.push( renderControl( ctrl, cur ? cur[ ctrl.key ] : undefined, function ( v ) {
						update( function ( n ) {
							n[ mid ] = n[ mid ] || {};
							n[ mid ][ ctrl.key ] = v;
						} );
					} ) );
				} );
			}

			children.push( el(
				'div',
				{ key: mid, className: 'efx-module' + ( enabled ? ' is-active' : '' ) },
				el( components.ToggleControl, {
					label: mod.label,
					checked: enabled,
					onChange: function ( on ) {
						update( function ( n ) {
							if ( on ) {
								n[ mid ] = { effect: ( mod.effects && mod.effects[ 0 ] ? mod.effects[ 0 ].id : '' ) };
							} else {
								delete n[ mid ];
							}
						} );
					},
				} ),
				controls
			) );
		} );

		// Timing.
		var tDefaults = ( catalog.timing && catalog.timing.defaults ) || {};
		var timing = fx.timing || {};
		children.push( el( 'div', { key: 'timing', className: 'efx-module' },
			el( components.SelectControl, {
				label: __( 'Disparador', 'estandar-experience' ),
				value: timing.trigger || tDefaults.trigger,
				options: catalog.timing.triggers,
				onChange: function ( v ) { update( function ( n ) { n.timing = n.timing || {}; n.timing.trigger = v; } ); },
			} ),
			el( components.RangeControl, { label: __( 'Duración (ms)', 'estandar-experience' ), value: timing.duration !== undefined ? timing.duration : tDefaults.duration, min: 50, max: 5000, step: 50, onChange: function ( v ) { update( function ( n ) { n.timing = n.timing || {}; n.timing.duration = v; } ); } } ),
			el( components.RangeControl, { label: __( 'Retardo (ms)', 'estandar-experience' ), value: timing.delay !== undefined ? timing.delay : tDefaults.delay, min: 0, max: 3000, step: 50, onChange: function ( v ) { update( function ( n ) { n.timing = n.timing || {}; n.timing.delay = v; } ); } } ),
			el( components.RangeControl, { label: __( 'Cascada (ms)', 'estandar-experience' ), value: timing.stagger !== undefined ? timing.stagger : tDefaults.stagger, min: 0, max: 500, step: 5, onChange: function ( v ) { update( function ( n ) { n.timing = n.timing || {}; n.timing.stagger = v; } ); } } ),
			el( components.SelectControl, {
				label: __( 'Curva', 'estandar-experience' ),
				value: timing.easing || tDefaults.easing,
				options: catalog.timing.easings,
				onChange: function ( v ) { update( function ( n ) { n.timing = n.timing || {}; n.timing.easing = v; } ); },
			} )
		) );

		// Avanzado (responsive).
		var adv = fx.advanced || { once: true, mobile: true };
		children.push( el( 'div', { key: 'advanced', className: 'efx-module' },
			el( components.ToggleControl, {
				label: __( 'Animar una sola vez', 'estandar-experience' ),
				checked: adv.once !== false,
				onChange: function ( v ) { update( function ( n ) { n.advanced = n.advanced || {}; n.advanced.once = v; } ); },
			} ),
			el( components.ToggleControl, {
				label: __( 'Activar en móvil', 'estandar-experience' ),
				help: __( 'Si lo desactivas, los efectos no se aplicarán en pantallas pequeñas.', 'estandar-experience' ),
				checked: adv.mobile !== false,
				onChange: function ( v ) { update( function ( n ) { n.advanced = n.advanced || {}; n.advanced.mobile = v; } ); },
			} ),
			el( 'p', { className: 'efx-compat-note' }, __( 'Los efectos marcados “· desktop” están pensados para pantallas grandes; revisa el resultado en móvil.', 'estandar-experience' ) )
		) );

		// Limpiar.
		if ( Object.keys( fx ).length ) {
			children.push( el( components.Button, {
				key: 'clear',
				isDestructive: true,
				variant: 'secondary',
				onClick: function () { props.setAttributes( { [ ATTR ]: {} } ); },
			}, __( 'Quitar efectos', 'estandar-experience' ) ) );
		}

		return el(
			components.PanelBody,
			{ title: __( 'Experiencia Estandar', 'estandar-experience' ), initialOpen: false, className: 'efx-panel' },
			children
		);
	};

	var withInspector = wp.compose.createHigherOrderComponent( function ( BlockEdit ) {
		return function ( props ) {
			if ( supported.indexOf( props.name ) === -1 ) {
				return el( BlockEdit, props );
			}
			return el(
				Fragment,
				null,
				el( BlockEdit, props ),
				el( blockEditor.InspectorControls, null, el( Panel, props ) )
			);
		};
	}, 'withEstandarExperience' );

	hooks.addFilter( 'editor.BlockEdit', 'estandar/experience', withInspector );
}( window.wp ) );
