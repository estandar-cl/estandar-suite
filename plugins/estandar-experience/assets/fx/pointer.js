/**
 * Estandar FX — Pointer module (pointer-driven, continuous).
 *
 * Fine-pointer devices only (hover + pointer:fine). Every effect uses the
 * shared pointer state and the shared ticker; loops run only while the
 * pointer is inside the element and stop the moment values settle.
 *
 * Effects / options:
 *   tilt      strength (deg, default 10) · glare (bool, default true)
 *   magnetic  strength (0..1, default 0.35) · range (px, default 80)
 *   spotlight size (px, default 320)  — soft orb follows the pointer
 *   emoji-burst count (default 10) · intensity (default 1)
 */
( function () {
	'use strict';

	var FX = window.EstandarFX;
	if ( ! FX ) {
		return;
	}

	function finePointerOnly( mount ) {
		return function ( el, opts, ctx ) {
			if ( ! ctx.env.finePointer ) {
				return null;
			}
			return mount( el, opts, ctx );
		};
	}

	/* ------------------------------------------------------------------ */
	/*  tilt                                                               */
	/* ------------------------------------------------------------------ */

	function tilt( el, opts, ctx ) {
		var strength = ctx.math.clamp( 'number' === typeof opts.strength ? opts.strength : 10, 1, 30 );
		var wantGlare = false !== opts.glare;

		var rect = null;
		var inside = false;
		var tickId = null;
		var rx = 0;
		var ry = 0;
		var glare = null;
		var release = null;

		if ( wantGlare ) {
			glare = document.createElement( 'span' );
			glare.className = 'efx-glare';
			glare.setAttribute( 'aria-hidden', 'true' );
			el.classList.add( 'efx-host' );
			el.appendChild( glare );
		}

		function ensure() {
			if ( null === tickId ) {
				tickId = ctx.ticker.add( step );
			}
		}

		function step( dt ) {
			var trx = 0;
			var trY = 0;

			if ( inside && rect ) {
				var px = ctx.math.clamp( ( ctx.pointer.state.x - rect.left ) / rect.width, 0, 1 );
				var py = ctx.math.clamp( ( ctx.pointer.state.y - rect.top ) / rect.height, 0, 1 );
				trx = ( 0.5 - py ) * strength * 2;
				trY = ( px - 0.5 ) * strength * 2;
			}

			rx = ctx.math.damp( rx, trx, 14, dt );
			ry = ctx.math.damp( ry, trY, 14, dt );

			el.style.transform = 'perspective(900px) rotateX(' + rx.toFixed( 2 ) + 'deg) rotateY(' + ry.toFixed( 2 ) + 'deg)';

			if ( glare ) {
				var gx = ( ry / strength ) * 40;
				var gy = ( -rx / strength ) * 40;
				glare.style.transform = 'translate3d(' + gx.toFixed( 1 ) + '%,' + gy.toFixed( 1 ) + '%,0)';
				glare.style.opacity = inside ? '1' : '0';
			}

			if ( ! inside && Math.abs( rx ) < 0.02 && Math.abs( ry ) < 0.02 ) {
				el.style.transform = '';
				ctx.ticker.remove( tickId );
				tickId = null;
			}
		}

		function onEnter() {
			rect = el.getBoundingClientRect();
			inside = true;
			release = release || ctx.pointer.use();
			ensure();
		}

		function onLeave() {
			inside = false;
			ensure();
		}

		el.addEventListener( 'pointerenter', onEnter, { passive: true } );
		el.addEventListener( 'pointerleave', onLeave, { passive: true } );

		return function () {
			el.removeEventListener( 'pointerenter', onEnter );
			el.removeEventListener( 'pointerleave', onLeave );
			if ( null !== tickId ) {
				ctx.ticker.remove( tickId );
			}
			if ( release ) {
				release();
			}
			if ( glare && glare.parentNode ) {
				glare.parentNode.removeChild( glare );
			}
			el.classList.remove( 'efx-host' );
			el.style.transform = '';
		};
	}

	/* ------------------------------------------------------------------ */
	/*  magnetic                                                           */
	/* ------------------------------------------------------------------ */

	function magnetic( el, opts, ctx ) {
		var strength = ctx.math.clamp( 'number' === typeof opts.strength ? opts.strength : 0.35, 0.05, 1 );
		var range = ctx.math.clamp( 'number' === typeof opts.range ? opts.range : 80, 10, 400 );
		var MAX = 32;

		var center = null;
		var inside = false;
		var pressed = false;
		var tickId = null;
		var x = 0;
		var y = 0;
		var scale = 1;
		var release = null;

		function measure() {
			var rect = el.getBoundingClientRect();
			center = {
				x: rect.left + rect.width / 2,
				y: rect.top + rect.height / 2,
				half: Math.max( rect.width, rect.height ) / 2,
			};
		}

		function ensure() {
			if ( null === tickId ) {
				tickId = ctx.ticker.add( step );
			}
		}

		function step( dt ) {
			var tx = 0;
			var ty = 0;

			if ( inside && center ) {
				var dx = ctx.pointer.state.x - center.x;
				var dy = ctx.pointer.state.y - center.y;

				if ( Math.sqrt( dx * dx + dy * dy ) < range + center.half ) {
					tx = ctx.math.clamp( dx * strength, -MAX, MAX );
					ty = ctx.math.clamp( dy * strength, -MAX, MAX );
				}
			}

			var tScale = pressed ? 0.94 : inside ? 1.03 : 1;

			x = ctx.math.damp( x, tx, 11, dt );
			y = ctx.math.damp( y, ty, 11, dt );
			scale = ctx.math.damp( scale, tScale, 18, dt );

			el.style.transform = 'translate3d(' + x.toFixed( 2 ) + 'px,' + y.toFixed( 2 ) + 'px,0) scale(' + scale.toFixed( 3 ) + ')';

			if ( ! inside && Math.abs( x ) < 0.05 && Math.abs( y ) < 0.05 && Math.abs( scale - 1 ) < 0.002 ) {
				el.style.transform = '';
				ctx.ticker.remove( tickId );
				tickId = null;
			}
		}

		function onEnter() {
			measure();
			inside = true;
			release = release || ctx.pointer.use();
			ensure();
		}

		function onLeave() {
			inside = false;
			pressed = false;
			ensure();
		}

		function onDown() {
			pressed = true;
			ensure();
		}

		function onUp() {
			pressed = false;
			ensure();
		}

		el.addEventListener( 'pointerenter', onEnter, { passive: true } );
		el.addEventListener( 'pointerleave', onLeave, { passive: true } );
		el.addEventListener( 'pointerdown', onDown, { passive: true } );
		window.addEventListener( 'pointerup', onUp, { passive: true } );

		return function () {
			el.removeEventListener( 'pointerenter', onEnter );
			el.removeEventListener( 'pointerleave', onLeave );
			el.removeEventListener( 'pointerdown', onDown );
			window.removeEventListener( 'pointerup', onUp );
			if ( null !== tickId ) {
				ctx.ticker.remove( tickId );
			}
			if ( release ) {
				release();
			}
			el.style.transform = '';
		};
	}

	/* ------------------------------------------------------------------ */
	/*  spotlight                                                          */
	/* ------------------------------------------------------------------ */

	function spotlight( el, opts, ctx ) {
		var size = ctx.math.clamp( 'number' === typeof opts.size ? opts.size : 320, 80, 1200 );

		var orb = document.createElement( 'span' );
		orb.className = 'efx-spotlight-orb';
		orb.setAttribute( 'aria-hidden', 'true' );
		orb.style.width = size + 'px';
		orb.style.height = size + 'px';

		el.classList.add( 'efx-host' );
		el.appendChild( orb );

		var rect = null;
		var inside = false;
		var tickId = null;
		var x = 0;
		var y = 0;
		var release = null;

		function ensure() {
			if ( null === tickId ) {
				tickId = ctx.ticker.add( step );
			}
		}

		function step( dt ) {
			if ( inside && rect ) {
				var tx = ctx.pointer.state.x - rect.left - size / 2;
				var ty = ctx.pointer.state.y - rect.top - size / 2;
				x = ctx.math.damp( x, tx, 16, dt );
				y = ctx.math.damp( y, ty, 16, dt );
				orb.style.transform = 'translate3d(' + x.toFixed( 1 ) + 'px,' + y.toFixed( 1 ) + 'px,0)';
			} else {
				ctx.ticker.remove( tickId );
				tickId = null;
			}
		}

		function onEnter() {
			rect = el.getBoundingClientRect();
			inside = true;
			release = release || ctx.pointer.use();
			orb.style.opacity = '1';
			ensure();
		}

		function onLeave() {
			inside = false;
			orb.style.opacity = '0';
		}

		el.addEventListener( 'pointerenter', onEnter, { passive: true } );
		el.addEventListener( 'pointerleave', onLeave, { passive: true } );

		return function () {
			el.removeEventListener( 'pointerenter', onEnter );
			el.removeEventListener( 'pointerleave', onLeave );
			if ( null !== tickId ) {
				ctx.ticker.remove( tickId );
			}
			if ( release ) {
				release();
			}
			if ( orb.parentNode ) {
				orb.parentNode.removeChild( orb );
			}
			el.classList.remove( 'efx-host' );
		};
	}

	/* ------------------------------------------------------------------ */
	/*  emoji burst                                                        */
	/* ------------------------------------------------------------------ */

	function emojiBurst( el, opts, ctx ) {
		var count = ctx.math.clamp( 'number' === typeof opts.count ? opts.count : 10, 4, 28 );
		var intensity = ctx.math.clamp( 'number' === typeof opts.intensity ? opts.intensity : 1, 0.4, 2 );
		var glyphs = 'string' === typeof opts.glyphs && opts.glyphs.trim() ? opts.glyphs.trim() : '✦✧★•';
		var last = 0;
		var live = [];

		el.classList.add( 'efx-host' );

		function pickGlyph() {
			return Array.from( glyphs )[ Math.floor( Math.random() * Array.from( glyphs ).length ) ] || '✦';
		}

		function burst( event ) {
			var now = performance.now();
			if ( now - last < 420 ) {
				return;
			}
			last = now;

			var rect = el.getBoundingClientRect();
			var originX = event.clientX - rect.left;
			var originY = event.clientY - rect.top;

			for ( var i = 0; i < count; i++ ) {
				var dot = document.createElement( 'span' );
				dot.className = 'efx-emoji-burst-item';
				dot.setAttribute( 'aria-hidden', 'true' );
				dot.textContent = pickGlyph();
				dot.style.left = originX + 'px';
				dot.style.top = originY + 'px';
				el.appendChild( dot );
				live.push( dot );

				var angle = Math.random() * Math.PI * 2;
				var distance = ( 34 + Math.random() * 72 ) * intensity;
				var dx = Math.cos( angle ) * distance;
				var dy = Math.sin( angle ) * distance - 18 * intensity;
				var rotate = ( Math.random() - 0.5 ) * 160;
				var anim = dot.animate(
					[
						{ opacity: 0, transform: 'translate3d(-50%,-50%,0) scale(0.65) rotate(0deg)' },
						{ opacity: 1, offset: 0.18, transform: 'translate3d(calc(-50% + ' + ( dx * 0.25 ).toFixed( 1 ) + 'px), calc(-50% + ' + ( dy * 0.25 ).toFixed( 1 ) + 'px), 0) scale(1) rotate(' + ( rotate * 0.25 ).toFixed( 1 ) + 'deg)' },
						{ opacity: 0, transform: 'translate3d(calc(-50% + ' + dx.toFixed( 1 ) + 'px), calc(-50% + ' + dy.toFixed( 1 ) + 'px), 0) scale(0.35) rotate(' + rotate.toFixed( 1 ) + 'deg)' },
					],
					{ duration: 820 + Math.random() * 260, easing: 'cubic-bezier(0.16, 1, 0.3, 1)', fill: 'both' }
				);
				anim.onfinish = ( function ( node ) {
					return function () {
						if ( node.parentNode ) {
							node.parentNode.removeChild( node );
						}
						live = live.filter( function ( item ) { return item !== node; } );
					};
				} )( dot );
			}
		}

		el.addEventListener( 'pointerenter', burst, { passive: true } );
		el.addEventListener( 'click', burst, { passive: true } );

		return function () {
			el.removeEventListener( 'pointerenter', burst );
			el.removeEventListener( 'click', burst );
			live.forEach( function ( node ) {
				if ( node.parentNode ) {
					node.parentNode.removeChild( node );
				}
			} );
			live = [];
			el.classList.remove( 'efx-host' );
		};
	}

	FX.register( 'pointer', {
		tilt: finePointerOnly( tilt ),
		magnetic: finePointerOnly( magnetic ),
		spotlight: finePointerOnly( spotlight ),
		'emoji-burst': finePointerOnly( emojiBurst ),
	} );
} )();
