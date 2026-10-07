/**
 * Estandar FX — Scroll module (scroll-driven, continuous).
 *
 * All effects consume the kernel scroll engine (cached rects, progress only
 * streams near the viewport) and write CSS custom properties consumed by the
 * shared `.efx-transform` chain — compositor-only output.
 *
 * Effects / options:
 *   parallax  speed  −1..1 (default 0.25; negative = counter-scroll)
 *   zoom      from   start scale (default 0.9)
 *   rotate    degrees total rotation across the scroll range (default 10)
 *   progress  (no visuals) exposes --efx-progress for custom CSS
 */
( function () {
	'use strict';

	var FX = window.EstandarFX;
	if ( ! FX ) {
		return;
	}

	/**
	 * Damped value driver: settles toward a target via the shared ticker and
	 * releases its ticker slot the moment it converges.
	 */
	function damper( ctx, apply, epsilon ) {
		var current = null;
		var target = 0;
		var tickId = null;

		function ensure() {
			if ( null === tickId ) {
				tickId = ctx.ticker.add( step );
			}
		}

		function step( dt ) {
			current = ctx.math.damp( current, target, 12, dt );

			if ( Math.abs( current - target ) < epsilon ) {
				current = target;
				ctx.ticker.remove( tickId );
				tickId = null;
			}

			apply( current );
		}

		return {
			set: function ( value ) {
				target = value;
				if ( null === current ) {
					// First value: snap, don't animate from 0.
					current = value;
					apply( value );
					return;
				}
				if ( current !== target ) {
					ensure();
				}
			},
			destroy: function () {
				if ( null !== tickId ) {
					ctx.ticker.remove( tickId );
					tickId = null;
				}
			},
		};
	}

	function parallax( el, opts, ctx ) {
		var speed = ctx.math.clamp( 'number' === typeof opts.speed ? opts.speed : 0.25, -1, 1 );

		el.classList.add( 'efx-transform' );

		var drive = damper( ctx, function ( value ) {
			el.style.setProperty( '--efx-y', value.toFixed( 2 ) + 'px' );
		}, 0.05 );

		var untrack = ctx.scroll.track( el, function ( progress ) {
			// Range: ±25% of the viewport height at full speed.
			drive.set( ( progress - 0.5 ) * -speed * window.innerHeight * 0.5 );
		} );

		return function () {
			untrack();
			drive.destroy();
			el.classList.remove( 'efx-transform' );
			el.style.removeProperty( '--efx-y' );
		};
	}

	function zoom( el, opts, ctx ) {
		var from = ctx.math.clamp( 'number' === typeof opts.from ? opts.from : 0.9, 0.4, 1.6 );

		el.classList.add( 'efx-transform' );

		var drive = damper( ctx, function ( value ) {
			el.style.setProperty( '--efx-scale', value.toFixed( 4 ) );
		}, 0.0005 );

		var untrack = ctx.scroll.track( el, function ( progress ) {
			// Settle at natural scale once the element crosses mid-viewport.
			drive.set( ctx.math.map( progress, 0, 0.5, from, 1 ) );
		} );

		return function () {
			untrack();
			drive.destroy();
			el.classList.remove( 'efx-transform' );
			el.style.removeProperty( '--efx-scale' );
		};
	}

	function rotate( el, opts, ctx ) {
		var degrees = ctx.math.clamp( 'number' === typeof opts.degrees ? opts.degrees : 10, -180, 180 );

		el.classList.add( 'efx-transform' );

		var drive = damper( ctx, function ( value ) {
			el.style.setProperty( '--efx-r', value.toFixed( 2 ) + 'deg' );
		}, 0.02 );

		var untrack = ctx.scroll.track( el, function ( progress ) {
			drive.set( ( progress - 0.5 ) * degrees );
		} );

		return function () {
			untrack();
			drive.destroy();
			el.classList.remove( 'efx-transform' );
			el.style.removeProperty( '--efx-r' );
		};
	}

	function progress( el, opts, ctx ) {
		var untrack = ctx.scroll.track( el, function ( value ) {
			el.style.setProperty( '--efx-progress', value.toFixed( 4 ) );
		} );

		return function () {
			untrack();
			el.style.removeProperty( '--efx-progress' );
		};
	}

	FX.register( 'scroll', {
		parallax: parallax,
		zoom: zoom,
		rotate: rotate,
		progress: progress,
	} );
} )();
