/**
 * Estandar FX — Reveal module (entrance animations).
 *
 * Every effect is a Web Animations API one-shot from a hidden pre-state to the
 * element's natural state. On finish the animation is cancelled and every
 * inline style is cleared, so the DOM ends exactly as the theme rendered it —
 * no residue, no will-change leaks, GPU-composited while running.
 *
 * Options:
 *   distance  px travel for directional effects (default 28)
 *   children  animate direct children with the timing stagger
 */
( function () {
	'use strict';

	var FX = window.EstandarFX;
	if ( ! FX ) {
		return;
	}

	/** Hidden pre-states per effect. `null` values are skipped. */
	var FROM = {
		fade: function () {
			return { opacity: '0' };
		},
		rise: function ( d ) {
			return { opacity: '0', transform: 'translate3d(0,' + d + 'px,0)' };
		},
		drop: function ( d ) {
			return { opacity: '0', transform: 'translate3d(0,' + -d + 'px,0)' };
		},
		'slide-left': function ( d ) {
			return { opacity: '0', transform: 'translate3d(' + -d * 1.6 + 'px,0,0)' };
		},
		'slide-right': function ( d ) {
			return { opacity: '0', transform: 'translate3d(' + d * 1.6 + 'px,0,0)' };
		},
		zoom: function () {
			return { opacity: '0', transform: 'scale(0.92)' };
		},
		'zoom-out': function () {
			return { opacity: '0', transform: 'scale(1.08)' };
		},
		blur: function () {
			return { opacity: '0', filter: 'blur(14px)' };
		},
		flip: function () {
			return { opacity: '0', transform: 'perspective(900px) rotateX(-55deg)', transformOrigin: '50% 100%' };
		},
		rotate: function () {
			return { opacity: '0', transform: 'rotate(-6deg) scale(0.96)' };
		},
		mask: function () {
			return { clipPath: 'inset(0 0 100% 0)' };
		},
		wipe: function () {
			return { clipPath: 'inset(0 100% 0 0)' };
		},
	};

	/** Natural end-state for the animatable properties of a pre-state. */
	var NEUTRAL = {
		opacity: '1',
		transform: 'none',
		filter: 'none',
		clipPath: 'inset(0 0 0 0)',
	};

	function build( effect ) {
		return function ( el, opts, ctx ) {
			var distance = ctx.math.clamp( 'number' === typeof opts.distance ? opts.distance : 28, 4, 400 );
			var targets = opts.children && el.children.length > 0 ? Array.prototype.slice.call( el.children ) : [ el ];
			var from = FROM[ effect ]( distance );
			var running = [];
			var prepared = false;

			function styleKeys() {
				return Object.keys( from );
			}

			function applyPre( target ) {
				styleKeys().forEach( function ( key ) {
					target.style[ key ] = from[ key ];
				} );
			}

			function clearPre( target ) {
				styleKeys().forEach( function ( key ) {
					target.style[ key ] = '';
				} );
			}

			return {
				prepare: function () {
					if ( prepared ) {
						return;
					}
					prepared = true;
					targets.forEach( applyPre );
				},

				play: function () {
					targets.forEach( function ( target, i ) {
						var to = {};
						styleKeys().forEach( function ( key ) {
							if ( 'transformOrigin' === key ) {
								return;
							}
							to[ key ] = NEUTRAL[ key ];
						} );

						var kfFrom = {};
						styleKeys().forEach( function ( key ) {
							if ( 'transformOrigin' !== key ) {
								kfFrom[ key ] = from[ key ];
							}
						} );

						var anim = target.animate( [ kfFrom, to ], {
							duration: ctx.timing.duration,
							delay: ctx.timing.delay + i * ctx.timing.stagger,
							easing: ctx.timing.easing,
							// `both`: pre-state holds through the stagger delay and the
							// final frame holds until cleanup — zero flash on either end.
							fill: 'both',
						} );

						running.push( anim );

						anim.onfinish = function () {
							clearPre( target );
							anim.cancel();
						};
					} );
				},

				reset: function () {
					prepared = false;
					running.forEach( function ( anim ) {
						anim.cancel();
					} );
					running = [];
					targets.forEach( clearPre );
				},
			};
		};
	}

	var effects = {};
	Object.keys( FROM ).forEach( function ( id ) {
		effects[ id ] = build( id );
	} );

	FX.register( 'reveal', effects, { kind: 'enter' } );
} )();
