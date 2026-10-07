/**
 * Estandar FX — editor "Experiencia" panel.
 *
 * Buildless (wp.* globals). One panel, injected into every supported native
 * block via the standard extension points. Everything renders generically
 * from the catalog:
 *
 *   · preset picker (built-in + user presets, save current as preset)
 *   · per-module effect select + conditional controls
 *   · timing (trigger, duration, delay, stagger, easing) + advanced
 *   · copy / paste / reset
 *   · live canvas sync for CSS effects (hover/ambient preview on hover)
 *   · entrance preview: plays reveal/text on a visual clone of the block,
 *     never mutating the React-managed DOM.
 */
( function ( wp ) {
	'use strict';

	var catalog = window.EstandarFXCatalog;
	var boot = window.estandarEfxEditor || {};

	if ( ! wp || ! catalog ) {
		return;
	}

	var el = wp.element.createElement;
	var useEffect = wp.element.useEffect;
	var useState = wp.element.useState;
	var Fragment = wp.element.Fragment;
	var __ = wp.i18n.__;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var c = wp.components;

	var ATTRIBUTE = catalog.attribute;
	var CLIPBOARD_KEY = 'estandarFxClipboard';
	var supported = boot.supportedBlocks || [];
	var userPresets = ( boot.userPresets || [] ).slice();

	function isSupported( name ) {
		return supported.indexOf( name ) !== -1;
	}

	function clone( value ) {
		return JSON.parse( JSON.stringify( value || {} ) );
	}

	function hasEntrance( config ) {
		return catalog.order.some( function ( moduleId ) {
			var module = catalog.modules[ moduleId ];
			return 'enter' === module.kind && config[ moduleId ] && config[ moduleId ].effect;
		} );
	}

	function isActive( config ) {
		return catalog.order.some( function ( moduleId ) {
			return config[ moduleId ] && config[ moduleId ].effect;
		} );
	}

	/* ------------------------------------------------------------------ */
	/*  Attribute registration                                             */
	/* ------------------------------------------------------------------ */

	wp.hooks.addFilter( 'blocks.registerBlockType', 'estandar/efx/attribute', function ( settings, name ) {
		if ( ! isSupported( name ) ) {
			return settings;
		}
		settings.attributes = settings.attributes || {};
		if ( ! settings.attributes[ ATTRIBUTE ] ) {
			settings.attributes[ ATTRIBUTE ] = { type: 'object', default: {} };
		}
		return settings;
	} );

	/* ------------------------------------------------------------------ */
	/*  Canvas helpers                                                     */
	/* ------------------------------------------------------------------ */

	function canvasDocument() {
		var iframe = document.querySelector( 'iframe[name="editor-canvas"]' );
		if ( iframe && iframe.contentDocument ) {
			return iframe.contentDocument;
		}
		return document;
	}

	function blockNode( clientId ) {
		return canvasDocument().getElementById( 'block-' + clientId );
	}

	/** Mirror of the PHP class stamping, for truthful in-canvas preview. */
	function classesFor( config ) {
		var classes = [];

		[ 'hover', 'ambient' ].forEach( function ( moduleId ) {
			var options = config[ moduleId ];
			if ( options && options.effect ) {
				classes.push( 'efx-' + moduleId + '-' + options.effect );
			}
		} );

		return classes;
	}

	var VAR_MAP = [
		{ module: 'hover', key: 'color', cssVar: '--efx-accent' },
		{ module: 'hover', key: 'intensity', cssVar: '--efx-hover-intensity' },
		{ module: 'ambient', key: 'color', cssVar: '--efx-accent' },
		{ module: 'ambient', key: 'colorB', cssVar: '--efx-accent-b' },
		{ module: 'ambient', key: 'intensity', cssVar: '--efx-ambient-intensity' },
		{ module: 'ambient', key: 'speed', cssVar: '--efx-ambient-speed', suffix: 's' },
	];

	function syncCanvas( clientId, config ) {
		var node = blockNode( clientId );
		if ( ! node ) {
			return;
		}

		var previous = ( node.getAttribute( 'data-efx-editor-classes' ) || '' ).split( ' ' ).filter( Boolean );
		previous.forEach( function ( name ) {
			node.classList.remove( name );
		} );

		VAR_MAP.forEach( function ( map ) {
			node.style.removeProperty( map.cssVar );
		} );

		var classes = classesFor( config );

		if ( classes.length ) {
			node.classList.add( 'efx' );
			classes.forEach( function ( name ) {
				node.classList.add( name );
			} );

			VAR_MAP.forEach( function ( map ) {
				var options = config[ map.module ];
				if ( options && options.effect && options[ map.key ] !== undefined && options[ map.key ] !== '' ) {
					node.style.setProperty( map.cssVar, String( options[ map.key ] ) + ( map.suffix || '' ) );
				}
			} );

			node.setAttribute( 'data-efx-editor-classes', classes.join( ' ' ) );
		} else {
			node.classList.remove( 'efx' );
			node.removeAttribute( 'data-efx-editor-classes' );
		}
	}

	/* ------------------------------------------------------------------ */
	/*  Entrance preview (clone overlay — never touches React's DOM)       */
	/* ------------------------------------------------------------------ */

	function previewEntrance( clientId, config ) {
		var FX = window.EstandarFX;
		var node = blockNode( clientId );

		if ( ! FX || ! node || ! hasEntrance( config ) ) {
			return;
		}

		var doc = node.ownerDocument;
		var rect = node.getBoundingClientRect();
		var ghost = node.cloneNode( true );

		ghost.removeAttribute( 'id' );
		ghost.removeAttribute( 'contenteditable' );
		ghost.querySelectorAll( '[contenteditable]' ).forEach( function ( editable ) {
			editable.removeAttribute( 'contenteditable' );
		} );
		ghost.style.position = 'fixed';
		ghost.style.top = rect.top + 'px';
		ghost.style.left = rect.left + 'px';
		ghost.style.width = rect.width + 'px';
		ghost.style.height = rect.height + 'px';
		ghost.style.margin = '0';
		ghost.style.zIndex = '9999';
		ghost.style.pointerEvents = 'none';

		doc.body.appendChild( ghost );
		node.style.visibility = 'hidden';

		var timing = config.timing || {};
		var total = ( timing.delay || 0 ) + ( timing.duration || 700 ) + 1800;

		FX.play( ghost, config );

		setTimeout( function () {
			node.style.visibility = '';
			if ( ghost.parentNode ) {
				ghost.parentNode.removeChild( ghost );
			}
		}, Math.min( total, 6000 ) );
	}

	/* ------------------------------------------------------------------ */
	/*  Generic control rendering                                          */
	/* ------------------------------------------------------------------ */

	var PALETTE = [
		{ name: 'Esmeralda', color: '#10b981' },
		{ name: 'Índigo', color: '#6366f1' },
		{ name: 'Ámbar', color: '#f59e0b' },
		{ name: 'Rosa', color: '#ec4899' },
		{ name: 'Blanco', color: '#ffffff' },
		{ name: 'Negro', color: '#111111' },
	];

	function renderControl( control, options, update ) {
		var value = options[ control.key ] !== undefined ? options[ control.key ] : control.default;

		switch ( control.type ) {
			case 'range':
				return el( c.RangeControl, {
					key: control.key + ( control.showFor || [] ).join( '-' ),
					label: control.label,
					value: 'number' === typeof value ? value : control.default,
					min: control.min,
					max: control.max,
					step: control.step,
					allowReset: true,
					resetFallbackValue: control.default,
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true,
					onChange: function ( next ) {
						update( control.key, undefined === next ? control.default : next );
					},
				} );

			case 'toggle':
				return el( c.ToggleControl, {
					key: control.key,
					label: control.label,
					checked: !! value,
					__nextHasNoMarginBottom: true,
					onChange: function ( next ) {
						update( control.key, !! next );
					},
				} );

			case 'select':
				return el( c.SelectControl, {
					key: control.key,
					label: control.label,
					value: value || control.default,
					options: control.options,
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true,
					onChange: function ( next ) {
						update( control.key, next );
					},
				} );

			case 'color':
				return el(
					'div',
					{ key: control.key, className: 'efx-control-color' },
					el( 'span', { className: 'efx-control-color__label' }, control.label ),
					el( c.ColorPalette, {
						colors: PALETTE,
						value: value || undefined,
						clearable: true,
						onChange: function ( next ) {
							update( control.key, next || '' );
						},
					} )
				);
		}

		return null;
	}

	function moduleSection( moduleId, config, setConfig ) {
		var module = catalog.modules[ moduleId ];
		var options = config[ moduleId ] || {};
		var effect = options.effect || '';

		function effectLabel( item ) {
			if ( 'd' === item.compat ) {
				return item.label + ' · D';
			}
			if ( 'dm' === item.compat ) {
				return item.label + ' · D/M';
			}
			return item.label;
		}

		function update( key, value ) {
			var next = clone( config );
			next[ moduleId ] = next[ moduleId ] || {};
			next[ moduleId ][ key ] = value;
			setConfig( next );
		}

		var controls = [];

		if ( effect ) {
			module.controls.forEach( function ( control ) {
				if ( control.showFor && control.showFor.indexOf( effect ) === -1 ) {
					return;
				}
				controls.push( renderControl( control, options, update ) );
			} );
		}

		return el(
			'div',
			{ key: moduleId, className: 'efx-module' + ( effect ? ' is-active' : '' ) },
			el( c.SelectControl, {
				label: module.label,
				value: effect,
				options: [ { value: '', label: '—' } ].concat(
					module.effects.map( function ( item ) {
						return { value: item.id, label: effectLabel( item ) };
					} )
				),
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true,
				onChange: function ( next ) {
					var updated = clone( config );
					if ( next ) {
						updated[ moduleId ] = updated[ moduleId ] || {};
						updated[ moduleId ].effect = next;
					} else {
						delete updated[ moduleId ];
					}
					setConfig( updated );
				},
			} ),
			controls
		);
	}

	/* ------------------------------------------------------------------ */
	/*  Panel                                                              */
	/* ------------------------------------------------------------------ */

	function ExperiencePanel( props ) {
		var config = props.attributes[ ATTRIBUTE ] || {};
		var clientId = props.clientId;
		var timing = Object.assign( {}, catalog.timing.defaults, config.timing || {} );
		var advanced = Object.assign( { once: true, mobile: true }, config.advanced || {} );

		var stateHook = useState( '' );
		var presetName = stateHook[ 0 ];
		var setPresetName = stateHook[ 1 ];

		var savingHook = useState( false );
		var saving = savingHook[ 0 ];
		var setSaving = savingHook[ 1 ];

		var refreshHook = useState( 0 );
		var setRefresh = refreshHook[ 1 ];

		function setConfig( next ) {
			var attrs = {};
			attrs[ ATTRIBUTE ] = next;
			props.setAttributes( attrs );
		}

		useEffect(
			function () {
				syncCanvas( clientId, config );
			},
			[ clientId, JSON.stringify( config ) ]
		);

		function setTiming( key, value ) {
			var next = clone( config );
			next.timing = next.timing || {};
			next.timing[ key ] = value;
			setConfig( next );
		}

		function setAdvanced( key, value ) {
			var next = clone( config );
			next.advanced = next.advanced || {};
			next.advanced[ key ] = value;
			setConfig( next );
		}

		var allPresets = catalog.presets.concat( userPresets );

		function applyPreset( id ) {
			if ( ! id ) {
				return;
			}
			var preset = null;
			allPresets.forEach( function ( item ) {
				if ( item.id === id ) {
					preset = item;
				}
			} );
			if ( preset ) {
				var next = clone( preset.config );
				next.preset = preset.id;
				setConfig( next );
			}
		}

		function savePreset() {
			if ( ! presetName || saving ) {
				return;
			}
			setSaving( true );
			wp.apiFetch( {
				path: '/' + ( boot.restNamespace || 'estandar-efx/v1' ) + '/presets',
				method: 'POST',
				data: { label: presetName, config: config },
			} )
				.then( function ( response ) {
					if ( response && response.presets ) {
						userPresets.length = 0;
						response.presets.forEach( function ( preset ) {
							userPresets.push( preset );
						} );
					}
					setPresetName( '' );
					setSaving( false );
					setRefresh( function ( n ) {
						return n + 1;
					} );
				} )
				.catch( function () {
					setSaving( false );
				} );
		}

		var canPaste = !! window.localStorage.getItem( CLIPBOARD_KEY );

		return el(
			InspectorControls,
			null,
			el(
				c.PanelBody,
				{ title: __( 'Experiencia', 'estandar-experience-blocks' ), className: 'efx-panel', initialOpen: isActive( config ) },

				/* Presets ------------------------------------------------ */
				el( c.SelectControl, {
					label: __( 'Preset', 'estandar-experience-blocks' ),
					value: config.preset || '',
					options: [ { value: '', label: __( '— elegir preset —', 'estandar-experience-blocks' ) } ].concat(
						allPresets.map( function ( preset ) {
							return { value: preset.id, label: preset.label };
						} )
					),
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true,
					onChange: applyPreset,
				} ),

				/* Toolbar ------------------------------------------------- */
				el(
					'div',
					{ className: 'efx-toolbar' },
					el(
						c.Button,
						{
							size: 'small',
							variant: 'secondary',
							disabled: ! hasEntrance( config ),
							onClick: function () {
								previewEntrance( clientId, config );
							},
						},
						__( 'Previsualizar', 'estandar-experience-blocks' )
					),
					el(
						c.Button,
						{
							size: 'small',
							variant: 'tertiary',
							disabled: ! isActive( config ),
							onClick: function () {
								window.localStorage.setItem( CLIPBOARD_KEY, JSON.stringify( config ) );
								setRefresh( function ( n ) {
									return n + 1;
								} );
							},
						},
						__( 'Copiar', 'estandar-experience-blocks' )
					),
					el(
						c.Button,
						{
							size: 'small',
							variant: 'tertiary',
							disabled: ! canPaste,
							onClick: function () {
								try {
									var pasted = JSON.parse( window.localStorage.getItem( CLIPBOARD_KEY ) || '{}' );
									setConfig( pasted );
								} catch ( err ) {
									/* ignore malformed clipboard */
								}
							},
						},
						__( 'Pegar', 'estandar-experience-blocks' )
					),
					el(
						c.Button,
						{
							size: 'small',
							variant: 'tertiary',
							isDestructive: true,
							disabled: ! isActive( config ) && ! config.preset,
							onClick: function () {
								setConfig( {} );
							},
						},
						__( 'Reset', 'estandar-experience-blocks' )
					)
				),

				/* Modules -------------------------------------------------- */
				catalog.order.map( function ( moduleId ) {
					return moduleSection( moduleId, config, setConfig );
				} ),

				/* Save as preset ------------------------------------------- */
				boot.canSavePresets && isActive( config )
					? el(
							'div',
							{ className: 'efx-save-preset' },
							el( c.TextControl, {
								label: __( 'Guardar como preset global', 'estandar-experience-blocks' ),
								value: presetName,
								placeholder: __( 'Nombre del preset', 'estandar-experience-blocks' ),
								__nextHasNoMarginBottom: true,
								__next40pxDefaultSize: true,
								onChange: setPresetName,
							} ),
							el(
								c.Button,
								{ size: 'small', variant: 'secondary', isBusy: saving, disabled: ! presetName || saving, onClick: savePreset },
								__( 'Guardar', 'estandar-experience-blocks' )
							)
					  )
					: null
			),

			isActive( config ) &&
				el(
					c.PanelBody,
					{ title: __( 'Tiempo y disparo', 'estandar-experience-blocks' ), initialOpen: false },
					el( c.SelectControl, {
						label: __( 'Disparo', 'estandar-experience-blocks' ),
						value: timing.trigger,
						options: catalog.timing.triggers,
						__nextHasNoMarginBottom: true,
						__next40pxDefaultSize: true,
						onChange: function ( value ) {
							setTiming( 'trigger', value );
						},
					} ),
					el( c.RangeControl, {
						label: __( 'Duración (ms)', 'estandar-experience-blocks' ),
						value: timing.duration,
						min: 100,
						max: 3000,
						step: 50,
						__nextHasNoMarginBottom: true,
						__next40pxDefaultSize: true,
						onChange: function ( value ) {
							setTiming( 'duration', value );
						},
					} ),
					el( c.RangeControl, {
						label: __( 'Retraso (ms)', 'estandar-experience-blocks' ),
						value: timing.delay,
						min: 0,
						max: 2000,
						step: 50,
						__nextHasNoMarginBottom: true,
						__next40pxDefaultSize: true,
						onChange: function ( value ) {
							setTiming( 'delay', value );
						},
					} ),
					el( c.RangeControl, {
						label: __( 'Escalonado (ms)', 'estandar-experience-blocks' ),
						value: timing.stagger,
						min: 0,
						max: 300,
						step: 5,
						help: __( 'Entre hijos, palabras o letras.', 'estandar-experience-blocks' ),
						__nextHasNoMarginBottom: true,
						__next40pxDefaultSize: true,
						onChange: function ( value ) {
							setTiming( 'stagger', value );
						},
					} ),
					el( c.SelectControl, {
						label: __( 'Curva (easing)', 'estandar-experience-blocks' ),
						value: timing.easing,
						options: catalog.timing.easings,
						__nextHasNoMarginBottom: true,
						__next40pxDefaultSize: true,
						onChange: function ( value ) {
							setTiming( 'easing', value );
						},
					} ),
					el( c.ToggleControl, {
						label: __( 'Solo la primera vez', 'estandar-experience-blocks' ),
						checked: !! advanced.once,
						help: __( 'Desactivado: la entrada se repite cada vez que vuelve a pantalla.', 'estandar-experience-blocks' ),
						__nextHasNoMarginBottom: true,
						onChange: function ( value ) {
							setAdvanced( 'once', value );
						},
					} ),
					el( c.ToggleControl, {
						label: __( 'Activar en móvil', 'estandar-experience-blocks' ),
						checked: !! advanced.mobile,
						__nextHasNoMarginBottom: true,
						onChange: function ( value ) {
							setAdvanced( 'mobile', value );
						},
					} )
				)
		);
	}

	var withExperience = wp.compose.createHigherOrderComponent( function ( BlockEdit ) {
		return function ( props ) {
			if ( ! isSupported( props.name ) || ! props.isSelected ) {
				return el( BlockEdit, props );
			}
			return el( Fragment, null, el( BlockEdit, props ), el( ExperiencePanel, props ) );
		};
	}, 'withEstandarExperience' );

	wp.hooks.addFilter( 'editor.BlockEdit', 'estandar/efx/panel', withExperience );
} )( window.wp );
