/**
 * Estandar FX — Stack Cards runtime.
 *
 * Layout (sticky stacking) is pure CSS and works with JS disabled. This
 * runtime only adds the depth cue: as the next card slides over, the one
 * beneath scales down and dims slightly — driven by the kernel scroll
 * engine, writing compositor-only custom properties.
 */
( function () {
	'use strict';

	var FX = window.EstandarFX;
	if ( ! FX ) {
		return;
	}

	FX.component( '.efx-stack', function ( el, ctx ) {
		if ( ctx.env.reducedMotion ) {
			return null; // Sticky layout stands on its own.
		}

		var cards = Array.prototype.slice.call( el.children );

		var untracks = [];

		cards.forEach( function ( card, i ) {
			var next = cards[ i + 1 ];
			if ( ! next ) {
				return;
			}

			var untrack = ctx.scroll.track( next, function ( progress ) {
				// The covering card travels 0 → 0.5 while sliding in.
				var t = ctx.math.map( progress, 0, 0.5, 0, 1 );
				card.style.setProperty( '--efx-stack-scale', ( 1 - t * 0.05 ).toFixed( 4 ) );
				card.style.setProperty( '--efx-stack-dim', ( t * 0.35 ).toFixed( 3 ) );
			} );

			untracks.push( untrack );
		} );

		return function () {
			untracks.forEach( function ( untrack ) {
				untrack();
			} );
			cards.forEach( function ( card ) {
				card.style.removeProperty( '--efx-stack-scale' );
				card.style.removeProperty( '--efx-stack-dim' );
			} );
		};
	} );
} )();
