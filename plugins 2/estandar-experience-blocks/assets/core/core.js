/**
 * Estandar FX — kernel.
 *
 * One shared runtime for every FX module:
 *
 *   env         capability + preference gates (reduced motion, mobile, pointer)
 *   math        lerp / frame-rate independent damp / clamp / map
 *   ticker      the ONLY requestAnimationFrame loop on the page
 *   visibility  pooled IntersectionObservers
 *   pointer     one passive pointermove listener, shared state
 *   scroll      per-element scroll progress (cached rects, no per-frame layout)
 *   registry    FX modules register effects; core dispatches [data-efx]
 *   components  structural widgets (marquee, before/after…) mount via selector
 *
 * Contracts:
 *   Entrance effect  build(el, opts, ctx) -> { prepare(), play(), reset() }
 *   Continuous effect mount(el, opts, ctx) -> destroy()   (or { destroy })
 *
 * Everything animates transform / opacity / clip-path only. Effects write CSS
 * custom properties or use WAAPI; they never touch layout properties.
 */
( function () {
	'use strict';

	if ( window.EstandarFX ) {
		return;
	}

	/* ------------------------------------------------------------------ */
	/*  Environment                                                        */
	/* ------------------------------------------------------------------ */

	var mqReduce = window.matchMedia( '(prefers-reduced-motion: reduce)' );
	var mqMobile = window.matchMedia( '(max-width: 781px)' );
	var mqFine = window.matchMedia( '(hover: hover) and (pointer: fine)' );

	var env = {
		get reducedMotion() {
			return mqReduce.matches;
		},
		get mobile() {
			return mqMobile.matches;
		},
		get finePointer() {
			return mqFine.matches;
		},
		get dpr() {
			return Math.min( window.devicePixelRatio || 1, 2 );
		},
	};

	/* ------------------------------------------------------------------ */
	/*  Math                                                               */
	/* ------------------------------------------------------------------ */

	var math = {
		lerp: function ( a, b, t ) {
			return a + ( b - a ) * t;
		},
		/** Frame-rate independent lerp. lambda ≈ speed (8–20 feels premium). */
		damp: function ( current, target, lambda, dt ) {
			return math.lerp( current, target, 1 - Math.exp( -lambda * dt ) );
		},
		clamp: function ( v, min, max ) {
			return Math.max( min, Math.min( max, v ) );
		},
		map: function ( v, inMin, inMax, outMin, outMax ) {
			if ( inMax === inMin ) {
				return outMin;
			}
			var t = ( v - inMin ) / ( inMax - inMin );
			return outMin + ( outMax - outMin ) * math.clamp( t, 0, 1 );
		},
	};

	/* ------------------------------------------------------------------ */
	/*  Ticker — the single rAF loop                                       */
	/* ------------------------------------------------------------------ */

	var ticker = ( function () {
		var subs = new Map();
		var nextId = 1;
		var rafId = null;
		var last = 0;

		function frame( now ) {
			var dt = math.clamp( ( now - last ) / 1000, 0.001, 0.064 );
			last = now;

			subs.forEach( function ( fn ) {
				fn( dt, now );
			} );

			rafId = subs.size > 0 ? requestAnimationFrame( frame ) : null;
		}

		return {
			add: function ( fn ) {
				var id = nextId++;
				subs.set( id, fn );
				if ( null === rafId ) {
					last = performance.now();
					rafId = requestAnimationFrame( frame );
				}
				return id;
			},
			remove: function ( id ) {
				subs.delete( id );
			},
		};
	} )();

	/* ------------------------------------------------------------------ */
	/*  Visibility — pooled IntersectionObservers                          */
	/* ------------------------------------------------------------------ */

	var visibility = ( function () {
		var pools = new Map(); // key -> { io, handlers: Map<el, cb> }

		function pool( margin, threshold ) {
			var key = margin + '|' + threshold;
			var entry = pools.get( key );

			if ( ! entry ) {
				var handlers = new Map();
				var io = new IntersectionObserver(
					function ( entries ) {
						entries.forEach( function ( e ) {
							var cb = handlers.get( e.target );
							if ( cb ) {
								cb( e );
							}
						} );
					},
					{ rootMargin: margin, threshold: threshold }
				);
				entry = { io: io, handlers: handlers };
				pools.set( key, entry );
			}

			return entry;
		}

		return {
			observe: function ( el, cb, options ) {
				options = options || {};
				var margin = options.margin || '0px';
				var threshold = options.threshold || 0;
				var entry = pool( margin, threshold );

				entry.handlers.set( el, cb );
				entry.io.observe( el );

				return function unobserve() {
					entry.handlers.delete( el );
					entry.io.unobserve( el );
				};
			},
		};
	} )();

	/* ------------------------------------------------------------------ */
	/*  Pointer — one listener, shared state                               */
	/* ------------------------------------------------------------------ */

	var pointer = ( function () {
		var state = { x: -1, y: -1, nx: 0, ny: 0, active: false };
		var subs = new Set();
		var listening = false;

		function onMove( e ) {
			state.x = e.clientX;
			state.y = e.clientY;
			state.nx = ( e.clientX / window.innerWidth ) * 2 - 1;
			state.ny = ( e.clientY / window.innerHeight ) * 2 - 1;
			state.active = true;
		}

		function start() {
			if ( ! listening ) {
				listening = true;
				window.addEventListener( 'pointermove', onMove, { passive: true } );
			}
		}

		return {
			state: state,
			use: function () {
				start();
				var token = {};
				subs.add( token );
				return function release() {
					subs.delete( token );
				};
			},
		};
	} )();

	/* ------------------------------------------------------------------ */
	/*  Scroll — element progress without per-frame layout reads           */
	/* ------------------------------------------------------------------ */

	var scroll = ( function () {
		var entries = new Set();
		var tickId = null;
		var lastY = -1;
		var needsMeasure = false;

		function measure( entry ) {
			var rect = entry.el.getBoundingClientRect();
			entry.top = rect.top + window.scrollY;
			entry.height = rect.height;
			entry.measured = true;
		}

		function update( force ) {
			var y = window.scrollY;

			if ( ! force && y === lastY && ! needsMeasure ) {
				return;
			}

			var vh = window.innerHeight;

			entries.forEach( function ( entry ) {
				if ( needsMeasure || ! entry.measured ) {
					measure( entry );
				}
				if ( ! entry.active ) {
					return;
				}

				// 0 → element top at viewport bottom, 1 → element bottom at top.
				var progress = math.clamp(
					( y + vh - entry.top ) / ( vh + entry.height ),
					0,
					1
				);

				if ( Math.abs( progress - entry.progress ) > 0.0005 || force ) {
					entry.progress = progress;
					entry.cb( progress );
				}
			} );

			lastY = y;
			needsMeasure = false;
		}

		function ensureLoop() {
			if ( null === tickId && entries.size > 0 ) {
				tickId = ticker.add( function () {
					update( false );
				} );
			}
		}

		function stopLoopIfIdle() {
			if ( null !== tickId && 0 === entries.size ) {
				ticker.remove( tickId );
				tickId = null;
			}
		}

		var resizeTimer = null;
		window.addEventListener(
			'resize',
			function () {
				clearTimeout( resizeTimer );
				resizeTimer = setTimeout( function () {
					needsMeasure = true;
					lastY = -1;
				}, 120 );
			},
			{ passive: true }
		);

		return {
			/**
			 * Track scroll progress of an element. cb(progress 0..1).
			 * Progress only streams while the element is near the viewport.
			 */
			track: function ( el, cb ) {
				var entry = {
					el: el,
					cb: cb,
					top: 0,
					height: 0,
					progress: -1,
					active: false,
					measured: false,
				};

				var unobserve = visibility.observe(
					el,
					function ( e ) {
						entry.active = e.isIntersecting;
						if ( entry.active ) {
							measure( entry );
							update( true );
						}
					},
					{ margin: '25% 0px 25% 0px' }
				);

				entries.add( entry );
				ensureLoop();

				return function untrack() {
					unobserve();
					entries.delete( entry );
					stopLoopIfIdle();
				};
			},
		};
	} )();

	/* ------------------------------------------------------------------ */
	/*  Registry + dispatch                                                */
	/* ------------------------------------------------------------------ */

	var EASINGS = {
		expo: 'cubic-bezier(0.16, 1, 0.3, 1)',
		quint: 'cubic-bezier(0.22, 1, 0.36, 1)',
		spring: 'cubic-bezier(0.34, 1.56, 0.64, 1)',
		snap: 'cubic-bezier(0.7, 0, 0.2, 1)',
		linear: 'linear',
	};

	var TIMING_DEFAULTS = { trigger: 'visible', duration: 700, delay: 0, stagger: 45, easing: 'expo' };

	var registry = {}; // module -> { kind, effects: { id: fn } }
	var cleanups = new Map(); // el -> [fn]
	var pendingScan = false;

	function addCleanup( el, fn ) {
		var list = cleanups.get( el );
		if ( ! list ) {
			list = [];
			cleanups.set( el, list );
		}
		list.push( fn );
	}

	function destroyEl( el ) {
		var list = cleanups.get( el );
		if ( list ) {
			cleanups.delete( el );
			list.forEach( function ( fn ) {
				try {
					fn();
				} catch ( err ) {
					/* effect cleanup must never break the page */
				}
			} );
		}
	}

	function resolveTiming( raw ) {
		raw = raw || {};
		var t = {
			trigger: 'load' === raw.trigger ? 'load' : TIMING_DEFAULTS.trigger,
			duration: math.clamp( parseInt( raw.duration, 10 ) || TIMING_DEFAULTS.duration, 50, 5000 ),
			delay: math.clamp( parseInt( raw.delay, 10 ) || 0, 0, 5000 ),
			stagger: math.clamp( 'number' === typeof raw.stagger ? raw.stagger : TIMING_DEFAULTS.stagger, 0, 500 ),
			easingName: EASINGS[ raw.easing ] ? raw.easing : TIMING_DEFAULTS.easing,
		};
		t.easing = EASINGS[ t.easingName ];
		return t;
	}

	function makeContext( timing, advanced ) {
		return {
			env: env,
			math: math,
			ticker: ticker,
			visibility: visibility,
			pointer: pointer,
			scroll: scroll,
			timing: timing,
			once: ! advanced || false !== advanced.once,
		};
	}

	/**
	 * Drive an entrance effect instance through its trigger.
	 */
	function driveEntrance( el, instance, ctx ) {
		if ( env.reducedMotion ) {
			return; // Content is already fully visible; do nothing.
		}

		if ( 'load' === ctx.timing.trigger ) {
			instance.prepare();
			requestAnimationFrame( function () {
				instance.play();
			} );
			return;
		}

		var vh = window.innerHeight;
		var rect = el.getBoundingClientRect();
		var alreadyVisible = rect.top < vh * 0.88 && rect.bottom > 0;

		if ( alreadyVisible && ctx.once ) {
			// Above the fold: animate immediately, never pre-hide long.
			instance.prepare();
			requestAnimationFrame( function () {
				instance.play();
			} );
			return;
		}

		instance.prepare();

		var played = false;
		var unobserveEnter = visibility.observe(
			el,
			function ( e ) {
				if ( e.isIntersecting && ! played ) {
					played = true;
					instance.play();
					if ( ctx.once ) {
						unobserveEnter();
					}
				}
			},
			{ margin: '0px 0px -12% 0px' }
		);
		addCleanup( el, unobserveEnter );

		if ( ! ctx.once ) {
			// Re-arm only when the element is fully outside the viewport.
			var unobserveExit = visibility.observe(
				el,
				function ( e ) {
					if ( ! e.isIntersecting && played ) {
						played = false;
						instance.reset();
						instance.prepare();
					}
				},
				{ margin: '10% 0px 10% 0px' }
			);
			addCleanup( el, unobserveExit );
		}
	}

	function moduleKeys( config ) {
		return Object.keys( config ).filter( function ( key ) {
			return 'timing' !== key && 'advanced' !== key;
		} );
	}

	function bind( el ) {
		if ( el.hasAttribute( 'data-efx-bound' ) ) {
			return;
		}

		var config;
		try {
			config = JSON.parse( el.getAttribute( 'data-efx' ) || '{}' );
		} catch ( err ) {
			el.setAttribute( 'data-efx-bound', '' );
			return;
		}

		// Wait until every module referenced by this element has registered,
		// so a partial bind can never dispatch the same effect twice. A module
		// that IS registered but lacks the effect id counts as ready (stale
		// content simply skips that effect at dispatch).
		var ready = moduleKeys( config ).every( function ( module ) {
			var opts = config[ module ];
			return ! ( opts && opts.effect ) || !! registry[ module ];
		} );

		if ( ! ready ) {
			return;
		}

		el.setAttribute( 'data-efx-bound', '' );

		var advanced = config.advanced || {};

		if ( false === advanced.mobile && env.mobile ) {
			return;
		}

		var timing = resolveTiming( config.timing );

		moduleKeys( config ).forEach( function ( module ) {
			var mod = registry[ module ];
			var opts = config[ module ];

			if ( ! mod || ! opts || ! opts.effect || ! mod.effects[ opts.effect ] ) {
				return;
			}

			var ctx = makeContext( timing, advanced );

			if ( 'enter' === mod.kind ) {
				var instance = mod.effects[ opts.effect ]( el, opts, ctx );
				if ( instance ) {
					addCleanup( el, function () {
						if ( instance.reset ) {
							instance.reset();
						}
					} );
					driveEntrance( el, instance, ctx );
				}
			} else {
				if ( env.reducedMotion && false !== mod.decorative ) {
					return; // Decorative continuous motion is skipped entirely.
				}
				var destroy = mod.effects[ opts.effect ]( el, opts, ctx );
				if ( 'function' === typeof destroy ) {
					addCleanup( el, destroy );
				} else if ( destroy && 'function' === typeof destroy.destroy ) {
					addCleanup( el, destroy.destroy );
				}
			}
		} );
	}

	function scan( root ) {
		( root || document )
			.querySelectorAll( '[data-efx]:not([data-efx-bound])' )
			.forEach( bind );

		components.forEach( mountComponent );
	}

	/* ------------------------------------------------------------------ */
	/*  Components — structural widgets by selector                        */
	/* ------------------------------------------------------------------ */

	var components = [];

	function mountComponent( component ) {
		document
			.querySelectorAll( component.selector + ':not([data-efx-mounted])' )
			.forEach( function ( el ) {
				el.setAttribute( 'data-efx-mounted', '' );
				var destroy = component.mount( el, makeContext( resolveTiming( {} ), {} ) );
				if ( 'function' === typeof destroy ) {
					addCleanup( el, destroy );
				}
			} );
	}

	/* ------------------------------------------------------------------ */
	/*  DOM lifecycle                                                      */
	/* ------------------------------------------------------------------ */

	var mutationTimer = null;

	function watchDom() {
		if ( ! window.MutationObserver || ! document.body ) {
			return;
		}

		new MutationObserver( function () {
			clearTimeout( mutationTimer );
			mutationTimer = setTimeout( function () {
				// Cheap sweep: destroy anything no longer connected, bind new.
				cleanups.forEach( function ( _list, el ) {
					if ( ! el.isConnected ) {
						destroyEl( el );
					}
				} );
				scan();
			}, 150 );
		} ).observe( document.body, { childList: true, subtree: true } );
	}

	/* ------------------------------------------------------------------ */
	/*  Public API                                                         */
	/* ------------------------------------------------------------------ */

	var api = {
		env: env,
		math: math,
		ticker: ticker,
		visibility: visibility,
		pointer: pointer,
		scroll: scroll,
		easings: EASINGS,

		/**
		 * Register a module's effects.
		 *
		 * register('reveal', { fade: buildFn }, { kind: 'enter' })
		 * register('pointer', { tilt: mountFn })            // continuous
		 */
		register: function ( module, effects, options ) {
			options = options || {};
			var mod = registry[ module ] || { kind: options.kind || 'continuous', effects: {}, decorative: options.decorative };
			mod.kind = options.kind || mod.kind;
			Object.keys( effects ).forEach( function ( id ) {
				mod.effects[ id ] = effects[ id ];
			} );
			registry[ module ] = mod;

			// Modules load deferred in arbitrary order: coalesce one re-scan.
			if ( ! pendingScan ) {
				pendingScan = true;
				requestAnimationFrame( function () {
					pendingScan = false;
					scan();
				} );
			}
		},

		/**
		 * Register a structural component (marquee, before/after…).
		 */
		component: function ( selector, mount ) {
			components.push( { selector: selector, mount: mount } );
			mountComponent( components[ components.length - 1 ] );
		},

		scan: scan,

		/**
		 * Editor preview: run an entrance config immediately on an element,
		 * regardless of triggers. Returns true when something played.
		 */
		play: function ( el, config ) {
			var timing = resolveTiming( config && config.timing );
			var played = false;

			Object.keys( config || {} ).forEach( function ( module ) {
				var mod = registry[ module ];
				var opts = config[ module ];
				if ( ! mod || 'enter' !== mod.kind || ! opts || ! opts.effect || ! mod.effects[ opts.effect ] ) {
					return;
				}
				var instance = mod.effects[ opts.effect ]( el, opts, makeContext( timing, { once: true } ) );
				if ( instance ) {
					instance.prepare();
					requestAnimationFrame( function () {
						instance.play();
					} );
					played = true;
				}
			} );

			return played;
		},

		destroy: destroyEl,
	};

	Object.defineProperty( window, 'EstandarFX', { value: api, writable: false } );

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			scan();
			watchDom();
		} );
	} else {
		scan();
		watchDom();
	}
} )();
