/**
 * Estandar FX — structural blocks editor (buildless).
 *
 * Server block.json owns the metadata (attributes, supports, title); here we
 * only provide edit/save. Marquee and Stack Cards host InnerBlocks and render
	 * dynamically (save = InnerBlocks.Content); Before/After and Coverflow Gallery
	 * are fully dynamic.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks ) {
		return;
	}

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var registerBlockType = wp.blocks.registerBlockType;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var useInnerBlocksProps = wp.blockEditor.useInnerBlocksProps;
	var InnerBlocks = wp.blockEditor.InnerBlocks;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var MediaUpload = wp.blockEditor.MediaUpload;
	var MediaUploadCheck = wp.blockEditor.MediaUploadCheck;
	var c = wp.components;

	/* ------------------------------------------------------------------ */
	/*  Marquee                                                            */
	/* ------------------------------------------------------------------ */

	registerBlockType( 'estandar/marquee', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;

			var blockProps = useBlockProps( {
				className: 'efx-marquee is-editor',
				style: { '--efx-marquee-gap': attributes.gap + 'px' },
			} );

			var innerProps = useInnerBlocksProps(
				{ className: 'efx-marquee-group' },
				{ orientation: 'horizontal' }
			);

			return el(
				wp.element.Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						c.PanelBody,
						{ title: __( 'Marquee', 'estandar-experience-blocks' ) },
						el( c.RangeControl, {
							label: __( 'Velocidad (px/s)', 'estandar-experience-blocks' ),
							value: attributes.speed,
							min: 10,
							max: 300,
							step: 5,
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
							onChange: function ( value ) {
								setAttributes( { speed: value } );
							},
						} ),
						el( c.SelectControl, {
							label: __( 'Dirección', 'estandar-experience-blocks' ),
							value: attributes.direction,
							options: [
								{ value: 'left', label: __( 'Hacia la izquierda', 'estandar-experience-blocks' ) },
								{ value: 'right', label: __( 'Hacia la derecha', 'estandar-experience-blocks' ) },
							],
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
							onChange: function ( value ) {
								setAttributes( { direction: value } );
							},
						} ),
						el( c.RangeControl, {
							label: __( 'Separación (px)', 'estandar-experience-blocks' ),
							value: attributes.gap,
							min: 0,
							max: 200,
							step: 4,
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
							onChange: function ( value ) {
								setAttributes( { gap: value } );
							},
						} ),
						el( c.ToggleControl, {
							label: __( 'Pausar al pasar el cursor', 'estandar-experience-blocks' ),
							checked: !! attributes.hoverPause,
							__nextHasNoMarginBottom: true,
							onChange: function ( value ) {
								setAttributes( { hoverPause: value } );
							},
						} ),
						el( c.ToggleControl, {
							label: __( 'Desvanecer bordes', 'estandar-experience-blocks' ),
							checked: !! attributes.fade,
							__nextHasNoMarginBottom: true,
							onChange: function ( value ) {
								setAttributes( { fade: value } );
							},
						} )
					)
				),
				el( 'div', blockProps, el( 'div', { className: 'efx-marquee-track' }, el( 'div', innerProps ) ) )
			);
		},
		save: function () {
			return el( InnerBlocks.Content );
		},
	} );

	/* ------------------------------------------------------------------ */
	/*  Before / After                                                     */
	/* ------------------------------------------------------------------ */

	function mediaButton( label, url, onSelect ) {
		return el( MediaUploadCheck, null,
			el( MediaUpload, {
				onSelect: onSelect,
				allowedTypes: [ 'image' ],
				render: function ( args ) {
					return el(
						c.Button,
						{ variant: url ? 'secondary' : 'primary', onClick: args.open, className: 'efx-ba-pick' },
						url ? __( 'Cambiar', 'estandar-experience-blocks' ) + ' — ' + label : label
					);
				},
			} )
		);
	}

	registerBlockType( 'estandar/before-after', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var blockProps = useBlockProps( {
				className: 'efx-ba is-editor',
				style: { '--efx-ba-pos': ( attributes.startPosition || 50 ) + '%' },
			} );

			var ready = attributes.beforeUrl && attributes.afterUrl;

			return el(
				wp.element.Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						c.PanelBody,
						{ title: __( 'Antes / Después', 'estandar-experience-blocks' ) },
						el( c.TextControl, {
							label: __( 'Etiqueta "antes"', 'estandar-experience-blocks' ),
							value: attributes.beforeLabel,
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
							onChange: function ( value ) {
								setAttributes( { beforeLabel: value } );
							},
						} ),
						el( c.TextControl, {
							label: __( 'Etiqueta "después"', 'estandar-experience-blocks' ),
							value: attributes.afterLabel,
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
							onChange: function ( value ) {
								setAttributes( { afterLabel: value } );
							},
						} ),
						el( c.RangeControl, {
							label: __( 'Posición inicial (%)', 'estandar-experience-blocks' ),
							value: attributes.startPosition,
							min: 0,
							max: 100,
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
							onChange: function ( value ) {
								setAttributes( { startPosition: value } );
							},
						} )
					)
				),
				el(
					'div',
					blockProps,
					ready
						? el(
								wp.element.Fragment,
								null,
								el( 'img', { src: attributes.beforeUrl, alt: attributes.beforeLabel } ),
								el( 'div', { className: 'efx-ba-after' }, el( 'img', { src: attributes.afterUrl, alt: attributes.afterLabel } ) ),
								el( 'span', { className: 'efx-ba-label efx-ba-label-before' }, attributes.beforeLabel ),
								el( 'span', { className: 'efx-ba-label efx-ba-label-after' }, attributes.afterLabel ),
								el( 'div', { className: 'efx-ba-handle', 'aria-hidden': 'true' } )
						  )
						: el(
								c.Placeholder,
								{
									icon: 'image-flip-horizontal',
									label: __( 'Antes / Después', 'estandar-experience-blocks' ),
									instructions: __( 'Elige las dos imágenes a comparar.', 'estandar-experience-blocks' ),
								},
								mediaButton( __( 'Imagen "antes"', 'estandar-experience-blocks' ), attributes.beforeUrl, function ( media ) {
									setAttributes( { beforeId: media.id, beforeUrl: media.url } );
								} ),
								mediaButton( __( 'Imagen "después"', 'estandar-experience-blocks' ), attributes.afterUrl, function ( media ) {
									setAttributes( { afterId: media.id, afterUrl: media.url } );
								} )
						  )
				),
				ready
					? el(
							'div',
							{ className: 'efx-ba-editor-actions' },
							mediaButton( __( 'Imagen "antes"', 'estandar-experience-blocks' ), attributes.beforeUrl, function ( media ) {
								setAttributes( { beforeId: media.id, beforeUrl: media.url } );
							} ),
							mediaButton( __( 'Imagen "después"', 'estandar-experience-blocks' ), attributes.afterUrl, function ( media ) {
								setAttributes( { afterId: media.id, afterUrl: media.url } );
							} )
					  )
					: null
			);
		},
		save: function () {
			return null;
		},
	} );

	/* ------------------------------------------------------------------ */
	/*  Stack Cards                                                        */
	/* ------------------------------------------------------------------ */

	registerBlockType( 'estandar/stack-cards', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;

			var blockProps = useBlockProps( {
				className: 'efx-stack is-editor',
				style: {
					'--efx-stack-gap': attributes.gap + 'px',
					'--efx-stack-radius': attributes.radius + 'px',
				},
			} );

			var innerProps = useInnerBlocksProps( blockProps, {
				allowedBlocks: [ 'core/group', 'core/cover', 'core/media-text', 'core/columns' ],
				template: [ [ 'core/group' ], [ 'core/group' ] ],
			} );

			return el(
				wp.element.Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						c.PanelBody,
						{ title: __( 'Tarjetas apiladas', 'estandar-experience-blocks' ) },
						el( c.RangeControl, {
							label: __( 'Separación superior (px)', 'estandar-experience-blocks' ),
							help: __( 'Distancia al borde superior cuando la tarjeta se fija.', 'estandar-experience-blocks' ),
							value: attributes.topOffset,
							min: 0,
							max: 300,
							step: 4,
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
							onChange: function ( value ) {
								setAttributes( { topOffset: value } );
							},
						} ),
						el( c.RangeControl, {
							label: __( 'Espacio entre tarjetas (px)', 'estandar-experience-blocks' ),
							value: attributes.gap,
							min: 0,
							max: 160,
							step: 4,
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
							onChange: function ( value ) {
								setAttributes( { gap: value } );
							},
						} ),
						el( c.RangeControl, {
							label: __( 'Radio de borde (px)', 'estandar-experience-blocks' ),
							value: attributes.radius,
							min: 0,
							max: 60,
							step: 2,
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
							onChange: function ( value ) {
								setAttributes( { radius: value } );
							},
						} )
					)
				),
				el( 'div', innerProps )
			);
		},
		save: function () {
			return el( InnerBlocks.Content );
		},
	} );

	/* ------------------------------------------------------------------ */
	/*  Coverflow Gallery                                                  */
	/* ------------------------------------------------------------------ */

	function normalizeCoverflowSlides( mediaItems ) {
		return ( mediaItems || [] ).map( function ( media ) {
			return {
				id: media.id || 0,
				url: media.url || ( media.sizes && media.sizes.full && media.sizes.full.url ) || '',
				alt: media.alt || '',
				title: media.title || media.caption || '',
			};
		} ).filter( function ( slide ) {
			return !! slide.url;
		} );
	}

	function coverflowPreview( slides, attributes, setAttributes ) {
		if ( ! slides.length ) {
			return el(
				c.Placeholder,
				{
					icon: 'format-gallery',
					label: __( 'Coverflow Gallery', 'estandar-experience-blocks' ),
					instructions: __( 'Selecciona imágenes para construir una galería 3D.', 'estandar-experience-blocks' ),
				},
				el( MediaUploadCheck, null,
					el( MediaUpload, {
						multiple: true,
						gallery: true,
						allowedTypes: [ 'image' ],
						onSelect: function ( media ) {
							setAttributes( { slides: normalizeCoverflowSlides( media ) } );
						},
						render: function ( args ) {
							return el( c.Button, { variant: 'primary', onClick: args.open }, __( 'Elegir imágenes', 'estandar-experience-blocks' ) );
						},
					} )
				)
			);
		}

		return el(
			wp.element.Fragment,
			null,
			el(
				'div',
				{ className: 'efx-coverflow-stage' },
				el(
					'div',
					{ className: 'efx-coverflow-track' },
					slides.slice( 0, 5 ).map( function ( slide, i ) {
						var offset = i - Math.min( 1, slides.length - 1 );
						return el(
							'figure',
							{
								className: 'efx-coverflow-slide',
								key: slide.id || slide.url,
								style: {
									'--efx-coverflow-offset': offset,
									'--efx-coverflow-depth': Math.abs( offset ),
								},
							},
							el( 'img', { src: slide.url, alt: slide.alt || slide.title || '' } ),
							attributes.showTitles && slide.title ? el( 'figcaption', null, slide.title ) : null
						);
					} )
				)
			),
			el(
				'div',
				{ className: 'efx-ba-editor-actions' },
				el( MediaUploadCheck, null,
					el( MediaUpload, {
						multiple: true,
						gallery: true,
						allowedTypes: [ 'image' ],
						value: slides.map( function ( slide ) { return slide.id; } ).filter( Boolean ),
						onSelect: function ( media ) {
							setAttributes( { slides: normalizeCoverflowSlides( media ) } );
						},
						render: function ( args ) {
							return el( c.Button, { variant: 'secondary', onClick: args.open }, __( 'Editar imágenes', 'estandar-experience-blocks' ) );
						},
					} )
				)
			)
		);
	}

	registerBlockType( 'estandar/coverflow-gallery', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var slides = attributes.slides || [];

			var blockProps = useBlockProps( {
				className: 'efx-coverflow is-editor',
				style: {
					'--efx-coverflow-card': attributes.cardWidth + 'px',
					'--efx-coverflow-gap': attributes.gap + 'px',
					'--efx-coverflow-tilt': attributes.tilt + 'deg',
					'--efx-coverflow-radius': attributes.radius + 'px',
				},
			} );

			return el(
				wp.element.Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						c.PanelBody,
						{ title: __( 'Coverflow Gallery', 'estandar-experience-blocks' ) },
						el( 'p', { className: 'efx-compat-note' }, __( 'Compatibilidad: D/M · desktop y mobile.', 'estandar-experience-blocks' ) ),
						el( c.ToggleControl, {
							label: __( 'Autoplay', 'estandar-experience-blocks' ),
							checked: !! attributes.autoplay,
							__nextHasNoMarginBottom: true,
							onChange: function ( value ) {
								setAttributes( { autoplay: value } );
							},
						} ),
						el( c.RangeControl, {
							label: __( 'Intervalo autoplay (ms)', 'estandar-experience-blocks' ),
							value: attributes.interval,
							min: 1600,
							max: 10000,
							step: 200,
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
							onChange: function ( value ) {
								setAttributes( { interval: value } );
							},
						} ),
						el( c.RangeControl, {
							label: __( 'Ancho tarjeta (px)', 'estandar-experience-blocks' ),
							value: attributes.cardWidth,
							min: 180,
							max: 640,
							step: 10,
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
							onChange: function ( value ) {
								setAttributes( { cardWidth: value } );
							},
						} ),
						el( c.RangeControl, {
							label: __( 'Separación 3D (px)', 'estandar-experience-blocks' ),
							value: attributes.gap,
							min: 24,
							max: 180,
							step: 4,
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
							onChange: function ( value ) {
								setAttributes( { gap: value } );
							},
						} ),
						el( c.RangeControl, {
							label: __( 'Inclinación (°)', 'estandar-experience-blocks' ),
							value: attributes.tilt,
							min: 0,
							max: 72,
							step: 2,
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
							onChange: function ( value ) {
								setAttributes( { tilt: value } );
							},
						} ),
						el( c.RangeControl, {
							label: __( 'Radio borde (px)', 'estandar-experience-blocks' ),
							value: attributes.radius,
							min: 0,
							max: 60,
							step: 2,
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
							onChange: function ( value ) {
								setAttributes( { radius: value } );
							},
						} ),
						el( c.ToggleControl, {
							label: __( 'Mostrar títulos', 'estandar-experience-blocks' ),
							checked: !! attributes.showTitles,
							__nextHasNoMarginBottom: true,
							onChange: function ( value ) {
								setAttributes( { showTitles: value } );
							},
						} )
					)
				),
				el( 'div', blockProps, coverflowPreview( slides, attributes, setAttributes ) )
			);
		},
		save: function () {
			return null;
		},
	} );

	/* ------------------------------------------------------------------ */
	/*  Magnetic Carousel                                                  */
	/* ------------------------------------------------------------------ */

	function magneticPreview( slides, attributes, setAttributes ) {
		if ( ! slides.length ) {
			return el(
				c.Placeholder,
				{
					icon: 'images-alt2',
					label: __( 'Magnetic Carousel', 'estandar-experience-blocks' ),
					instructions: __( 'Selecciona imágenes para construir una fila magnética.', 'estandar-experience-blocks' ),
				},
				el( MediaUploadCheck, null,
					el( MediaUpload, {
						multiple: true,
						gallery: true,
						allowedTypes: [ 'image' ],
						onSelect: function ( media ) {
							setAttributes( { slides: normalizeCoverflowSlides( media ) } );
						},
						render: function ( args ) {
							return el( c.Button, { variant: 'primary', onClick: args.open }, __( 'Elegir imágenes', 'estandar-experience-blocks' ) );
						},
					} )
				)
			);
		}

		return el(
			wp.element.Fragment,
			null,
			el(
				'div',
				{ className: 'efx-mc-track' },
				slides.map( function ( slide, i ) {
					var isFocus = i === Math.min( 1, slides.length - 1 );
					return el(
						'button',
						{
							className: 'efx-mc-item' + ( isFocus ? ' is-open' : '' ),
							key: slide.id || slide.url,
							type: 'button',
							style: {
								'--efx-mc-width': ( isFocus ? attributes.hoverWidth : attributes.collapsedWidth ) + 'px',
								'--efx-mc-title-opacity': isFocus ? 1 : 0,
							},
						},
						el( 'img', { src: slide.url, alt: slide.alt || slide.title || '' } ),
						attributes.showTitles && slide.title ? el( 'span', { className: 'efx-mc-title' }, slide.title ) : null
					);
				} )
			),
			el(
				'div',
				{ className: 'efx-ba-editor-actions' },
				el( MediaUploadCheck, null,
					el( MediaUpload, {
						multiple: true,
						gallery: true,
						allowedTypes: [ 'image' ],
						value: slides.map( function ( slide ) { return slide.id; } ).filter( Boolean ),
						onSelect: function ( media ) {
							setAttributes( { slides: normalizeCoverflowSlides( media ) } );
						},
						render: function ( args ) {
							return el( c.Button, { variant: 'secondary', onClick: args.open }, __( 'Editar imágenes', 'estandar-experience-blocks' ) );
						},
					} )
				)
			)
		);
	}

	registerBlockType( 'estandar/magnetic-carousel', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var slides = attributes.slides || [];

			var blockProps = useBlockProps( {
				className: 'efx-magnetic-carousel is-editor',
				style: {
					'--efx-mc-collapsed': attributes.collapsedWidth + 'px',
					'--efx-mc-hover': attributes.hoverWidth + 'px',
					'--efx-mc-height': attributes.height + 'px',
					'--efx-mc-gap': attributes.gap + 'px',
					'--efx-mc-influence': attributes.influence + 'px',
					'--efx-mc-radius': attributes.radius + 'px',
					'--efx-mc-blur': attributes.blur + 'px',
				},
			} );

			return el(
				wp.element.Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						c.PanelBody,
						{ title: __( 'Magnetic Carousel', 'estandar-experience-blocks' ) },
						el( 'p', { className: 'efx-compat-note' }, __( 'Compatibilidad: D · desktop-first por interacción de cursor.', 'estandar-experience-blocks' ) ),
						el( c.RangeControl, {
							label: __( 'Ancho colapsado (px)', 'estandar-experience-blocks' ),
							value: attributes.collapsedWidth,
							min: 36,
							max: 180,
							step: 2,
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
							onChange: function ( value ) {
								setAttributes( { collapsedWidth: value } );
							},
						} ),
						el( c.RangeControl, {
							label: __( 'Ancho activo (px)', 'estandar-experience-blocks' ),
							value: attributes.hoverWidth,
							min: 120,
							max: 360,
							step: 4,
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
							onChange: function ( value ) {
								setAttributes( { hoverWidth: value } );
							},
						} ),
						el( c.RangeControl, {
							label: __( 'Altura (px)', 'estandar-experience-blocks' ),
							value: attributes.height,
							min: 160,
							max: 620,
							step: 10,
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
							onChange: function ( value ) {
								setAttributes( { height: value } );
							},
						} ),
						el( c.RangeControl, {
							label: __( 'Influencia cursor (px)', 'estandar-experience-blocks' ),
							value: attributes.influence,
							min: 60,
							max: 420,
							step: 10,
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
							onChange: function ( value ) {
								setAttributes( { influence: value } );
							},
						} ),
						el( c.RangeControl, {
							label: __( 'Separación (px)', 'estandar-experience-blocks' ),
							value: attributes.gap,
							min: 0,
							max: 48,
							step: 2,
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
							onChange: function ( value ) {
								setAttributes( { gap: value } );
							},
						} ),
						el( c.RangeControl, {
							label: __( 'Radio borde (px)', 'estandar-experience-blocks' ),
							value: attributes.radius,
							min: 0,
							max: 60,
							step: 2,
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
							onChange: function ( value ) {
								setAttributes( { radius: value } );
							},
						} ),
						el( c.RangeControl, {
							label: __( 'Blur inactivos (px)', 'estandar-experience-blocks' ),
							value: attributes.blur,
							min: 0,
							max: 8,
							step: 1,
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
							onChange: function ( value ) {
								setAttributes( { blur: value } );
							},
						} ),
						el( c.ToggleControl, {
							label: __( 'Mostrar títulos', 'estandar-experience-blocks' ),
							checked: !! attributes.showTitles,
							__nextHasNoMarginBottom: true,
							onChange: function ( value ) {
								setAttributes( { showTitles: value } );
							},
						} )
					)
				),
				el( 'div', blockProps, magneticPreview( slides, attributes, setAttributes ) )
			);
		},
		save: function () {
			return null;
		},
	} );

	/* ------------------------------------------------------------------ */
	/*  Codrops-inspired structural blocks                                  */
	/* ------------------------------------------------------------------ */

	function pickGallery( label, slides, onSelect ) {
		return el( MediaUploadCheck, null,
			el( MediaUpload, {
				multiple: true,
				gallery: true,
				allowedTypes: [ 'image' ],
				value: ( slides || [] ).map( function ( slide ) { return slide.id; } ).filter( Boolean ),
				onSelect: function ( media ) { onSelect( normalizeCoverflowSlides( media ) ); },
				render: function ( args ) { return el( c.Button, { variant: slides && slides.length ? 'secondary' : 'primary', onClick: args.open }, label ); },
			} )
		);
	}

	function simpleImageStrip( title, slides, setSlides ) {
		if ( ! slides.length ) {
			return el( c.Placeholder, { icon: 'format-gallery', label: title, instructions: __( 'Selecciona imágenes para construir el bloque.', 'estandar-experience-blocks' ) }, pickGallery( __( 'Elegir imágenes', 'estandar-experience-blocks' ), slides, setSlides ) );
		}
		return el( wp.element.Fragment, null,
			el( 'div', { className: 'efx-editor-strip' }, slides.slice( 0, 8 ).map( function ( slide ) { return el( 'img', { key: slide.id || slide.url, src: slide.url, alt: slide.alt || slide.title || '' } ); } ) ),
			el( 'div', { className: 'efx-ba-editor-actions' }, pickGallery( __( 'Editar imágenes', 'estandar-experience-blocks' ), slides, setSlides ) )
		);
	}

	function mappedItems( slides ) {
		return slides.map( function ( slide, i ) {
			return { id: slide.id || 0, imageUrl: slide.url, url: slide.url, alt: slide.alt || '', title: slide.title || ( 'Item ' + ( i + 1 ) ), meta: String( 2020 + i ), description: __( 'Descripción editable desde atributos del bloque.', 'estandar-experience-blocks' ) };
		} );
	}

	function mappedPanels( slides ) {
		return slides.map( function ( slide, i ) {
			return { id: slide.id || 0, imageUrl: slide.url, title: slide.title || ( 'Panel ' + ( i + 1 ) ), kicker: String( i + 1 ).padStart( 2, '0' ), body: __( 'Narrativa de panel configurable.', 'estandar-experience-blocks' ) };
		} );
	}

	registerBlockType( 'estandar/spatial-gallery', {
		edit: function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;
			var slides = a.slides || [];
			var blockProps = useBlockProps( { className: 'efx-spatial-gallery is-editor', style: { '--efx-sg-height': a.height + 'px', '--efx-sg-width': a.imageWidth + 'px' } } );
			return el( wp.element.Fragment, null,
				el( InspectorControls, null, el( c.PanelBody, { title: __( 'Spatial Gallery', 'estandar-experience-blocks' ) },
					el( c.RangeControl, { label: __( 'Altura', 'estandar-experience-blocks' ), value: a.height, min: 420, max: 1000, step: 20, onChange: function ( v ) { set( { height: v } ); } } ),
					el( c.RangeControl, { label: __( 'Ancho imagen', 'estandar-experience-blocks' ), value: a.imageWidth, min: 160, max: 520, step: 10, onChange: function ( v ) { set( { imageWidth: v } ); } } ),
					el( c.RangeControl, { label: __( 'Escala foco', 'estandar-experience-blocks' ), value: a.focusScale, min: 1, max: 1.7, step: .05, onChange: function ( v ) { set( { focusScale: v } ); } } ),
					el( c.ToggleControl, { label: __( 'Mostrar captions', 'estandar-experience-blocks' ), checked: !! a.showCaptions, onChange: function ( v ) { set( { showCaptions: v } ); } } ) ) ),
				el( 'div', blockProps, simpleImageStrip( 'Spatial Gallery', slides, function ( next ) { set( { slides: next } ); } ) )
			);
		},
		save: function () { return null; },
	} );

	registerBlockType( 'estandar/cover-transition', {
		edit: function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;
			var items = a.items || [];
			var blockProps = useBlockProps( { className: 'efx-cover-transition is-editor' } );
			return el( wp.element.Fragment, null,
				el( InspectorControls, null, el( c.PanelBody, { title: __( 'Cover Transition', 'estandar-experience-blocks' ) },
					el( c.SelectControl, { label: __( 'Estilo', 'estandar-experience-blocks' ), value: a.transitionStyle, options: [ { value: 'split', label: 'Split' }, { value: 'wipe', label: 'Wipe' }, { value: 'lift', label: 'Lift' } ], onChange: function ( v ) { set( { transitionStyle: v } ); } } ),
					el( c.RangeControl, { label: __( 'Duración', 'estandar-experience-blocks' ), value: a.duration, min: 260, max: 1800, step: 20, onChange: function ( v ) { set( { duration: v } ); } } ),
					el( c.TextControl, { label: __( 'Color overlay', 'estandar-experience-blocks' ), value: a.overlayColor, onChange: function ( v ) { set( { overlayColor: v } ); } } ) ) ),
				el( 'div', blockProps, simpleImageStrip( 'Cover Transition', items, function ( next ) { set( { items: mappedItems( next ) } ); } ) )
			);
		},
		save: function () { return null; },
	} );

	registerBlockType( 'estandar/scroll-panels', {
		edit: function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;
			var panels = a.panels || [];
			var blockProps = useBlockProps( { className: 'efx-scroll-panels is-editor' } );
			return el( wp.element.Fragment, null,
				el( InspectorControls, null, el( c.PanelBody, { title: __( 'Scroll Panels', 'estandar-experience-blocks' ) },
					el( c.SelectControl, { label: __( 'Efecto', 'estandar-experience-blocks' ), value: a.effect, options: [ { value: 'slide', label: 'Slide' }, { value: 'scale', label: 'Scale' }, { value: 'fade', label: 'Fade' } ], onChange: function ( v ) { set( { effect: v } ); } } ),
					el( c.RangeControl, { label: __( 'Alto panel (vh)', 'estandar-experience-blocks' ), value: a.height, min: 55, max: 120, step: 1, onChange: function ( v ) { set( { height: v } ); } } ),
					el( c.ToggleControl, { label: __( 'Media sticky', 'estandar-experience-blocks' ), checked: !! a.stickyMedia, onChange: function ( v ) { set( { stickyMedia: v } ); } } ) ) ),
				el( 'div', blockProps, simpleImageStrip( 'Scroll Panels', panels, function ( next ) { set( { panels: mappedPanels( next ) } ); } ) )
			);
		},
		save: function () { return null; },
	} );

	registerBlockType( 'estandar/sticky-grid-scroll', {
		edit: function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;
			var slides = a.slides || [];
			var blockProps = useBlockProps( { className: 'efx-sticky-grid-scroll is-editor' } );
			return el( wp.element.Fragment, null,
				el( InspectorControls, null, el( c.PanelBody, { title: __( 'Sticky Grid Scroll', 'estandar-experience-blocks' ) },
					el( c.RangeControl, { label: __( 'Columnas', 'estandar-experience-blocks' ), value: a.columns, min: 2, max: 6, step: 1, onChange: function ( v ) { set( { columns: v } ); } } ),
					el( c.RangeControl, { label: __( 'Alto imagen', 'estandar-experience-blocks' ), value: a.height, min: 120, max: 320, step: 10, onChange: function ( v ) { set( { height: v } ); } } ),
					el( c.RangeControl, { label: __( 'Recorrido', 'estandar-experience-blocks' ), value: a.travel, min: 40, max: 420, step: 10, onChange: function ( v ) { set( { travel: v } ); } } ) ) ),
				el( 'div', blockProps, simpleImageStrip( 'Sticky Grid Scroll', slides, function ( next ) { set( { slides: next } ); } ) )
			);
		},
		save: function () { return null; },
	} );

	registerBlockType( 'estandar/horizontal-parallax-gallery', {
		edit: function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;
			var slides = a.slides || [];
			var blockProps = useBlockProps( { className: 'efx-horizontal-parallax-gallery is-editor' } );
			return el( wp.element.Fragment, null,
				el( InspectorControls, null, el( c.PanelBody, { title: __( 'Horizontal Parallax Gallery', 'estandar-experience-blocks' ) },
					el( c.RangeControl, { label: __( 'Altura hero (vh)', 'estandar-experience-blocks' ), value: a.height, min: 45, max: 100, step: 1, onChange: function ( v ) { set( { height: v } ); } } ),
					el( c.RangeControl, { label: __( 'Ancho imagen', 'estandar-experience-blocks' ), value: a.imageWidth, min: 180, max: 720, step: 10, onChange: function ( v ) { set( { imageWidth: v } ); } } ),
					el( c.RangeControl, { label: __( 'Velocidad', 'estandar-experience-blocks' ), value: a.speed, min: .2, max: 2.5, step: .1, onChange: function ( v ) { set( { speed: v } ); } } ),
					el( c.ToggleControl, { label: __( 'Modo hero', 'estandar-experience-blocks' ), checked: !! a.heroMode, onChange: function ( v ) { set( { heroMode: v } ); } } ) ) ),
				el( 'div', blockProps, simpleImageStrip( 'Horizontal Parallax Gallery', slides, function ( next ) { set( { slides: next } ); } ) )
			);
		},
		save: function () { return null; },
	} );

	registerBlockType( 'estandar/container-split-text', {
		edit: function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;
			var panels = a.panels || [];
			var blockProps = useBlockProps( { className: 'efx-container-split-text is-editor' } );
			return el( wp.element.Fragment, null,
				el( InspectorControls, null, el( c.PanelBody, { title: __( 'Container SplitText', 'estandar-experience-blocks' ) },
					el( c.SelectControl, { label: __( 'Efecto texto', 'estandar-experience-blocks' ), value: a.textEffect, options: [ { value: 'cascade', label: 'Cascade' }, { value: 'wipe', label: 'Wipe' }, { value: 'scrub', label: 'Scrub' } ], onChange: function ( v ) { set( { textEffect: v } ); } } ),
					el( c.RangeControl, { label: __( 'Altura sticky (vh)', 'estandar-experience-blocks' ), value: a.height, min: 55, max: 100, step: 1, onChange: function ( v ) { set( { height: v } ); } } ),
					el( c.TextControl, { label: __( 'Color acento', 'estandar-experience-blocks' ), value: a.accentColor, onChange: function ( v ) { set( { accentColor: v } ); } } ),
					el( c.ToggleControl, { label: __( 'Mostrar progreso', 'estandar-experience-blocks' ), checked: !! a.showProgress, onChange: function ( v ) { set( { showProgress: v } ); } } ) ) ),
				el( 'div', blockProps, simpleImageStrip( 'Container SplitText', panels, function ( next ) { set( { panels: mappedPanels( next ) } ); } ) )
			);
		},
		save: function () { return null; },
	} );

	registerBlockType( 'estandar/pinned-image-reveal', {
		edit: function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;
			var blockProps = useBlockProps( { className: 'efx-pinned-image-reveal is-editor' } );
			return el( wp.element.Fragment, null,
				el( InspectorControls, null, el( c.PanelBody, { title: __( 'Pinned Image Reveal', 'estandar-experience-blocks' ) },
					el( c.TextControl, { label: __( 'Titulo', 'estandar-experience-blocks' ), value: a.title, onChange: function ( v ) { set( { title: v } ); } } ),
					el( c.SelectControl, { label: __( 'Modo', 'estandar-experience-blocks' ), value: a.mode, options: [ { value: 'mask-zoom', label: 'Mask Zoom' }, { value: 'circle', label: 'Circle' }, { value: 'zoom', label: 'Zoom' }, { value: 'cinematic', label: 'Cinematic' } ], onChange: function ( v ) { set( { mode: v } ); } } ),
					el( c.RangeControl, { label: __( 'Altura scroll (vh)', 'estandar-experience-blocks' ), value: a.height, min: 110, max: 320, step: 5, onChange: function ( v ) { set( { height: v } ); } } ),
					el( c.RangeControl, { label: __( 'Escala inicial', 'estandar-experience-blocks' ), value: a.startScale, min: 1, max: 2, step: .02, onChange: function ( v ) { set( { startScale: v } ); } } ),
					mediaButton( __( 'Imagen', 'estandar-experience-blocks' ), a.imageUrl, function ( media ) { set( { imageUrl: media.url } ); } ) ) ),
				el( 'div', blockProps, a.imageUrl ? el( 'img', { src: a.imageUrl, alt: a.title || '' } ) : el( c.Placeholder, { icon: 'format-image', label: 'Pinned Image Reveal' }, mediaButton( __( 'Elegir imagen', 'estandar-experience-blocks' ), a.imageUrl, function ( media ) { set( { imageUrl: media.url } ); } ) ) )
			);
		},
		save: function () { return null; },
	} );

	registerBlockType( 'estandar/scroll-synced-carousel', {
		edit: function ( props ) {
			var a = props.attributes;
			var set = props.setAttributes;
			var slides = a.slides || [];
			var blockProps = useBlockProps( { className: 'efx-scroll-synced-carousel is-editor' } );
			return el( wp.element.Fragment, null,
				el( InspectorControls, null, el( c.PanelBody, { title: __( 'Scroll Synced Carousel', 'estandar-experience-blocks' ) },
					el( c.RangeControl, { label: __( 'Altura sticky (vh)', 'estandar-experience-blocks' ), value: a.height, min: 60, max: 120, step: 1, onChange: function ( v ) { set( { height: v } ); } } ),
					el( c.RangeControl, { label: __( 'Ancho tarjeta', 'estandar-experience-blocks' ), value: a.cardWidth, min: 220, max: 720, step: 10, onChange: function ( v ) { set( { cardWidth: v } ); } } ),
					el( c.RangeControl, { label: __( 'Separacion', 'estandar-experience-blocks' ), value: a.gap, min: 0, max: 80, step: 2, onChange: function ( v ) { set( { gap: v } ); } } ),
					el( c.TextControl, { label: __( 'Color acento', 'estandar-experience-blocks' ), value: a.accentColor, onChange: function ( v ) { set( { accentColor: v } ); } } ) ) ),
				el( 'div', blockProps, simpleImageStrip( 'Scroll Synced Carousel', slides, function ( next ) { set( { slides: next } ); } ) )
			);
		},
		save: function () { return null; },
	} );
} )( window.wp );
