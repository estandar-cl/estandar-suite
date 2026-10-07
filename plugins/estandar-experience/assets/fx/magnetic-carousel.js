/**
 * Estandar FX — Magnetic Carousel runtime.
 */
( function () {
	'use strict';

	var FX = window.EstandarFX;
	if ( ! FX ) {
		return;
	}

	function smoothstep( edge0, edge1, x ) {
		var t = Math.max( 0, Math.min( 1, ( x - edge0 ) / ( edge1 - edge0 ) ) );
		return t * t * ( 3 - 2 * t );
	}

	FX.component( '[data-efx-magnetic-carousel]', function ( el, ctx ) {
		var track = el.querySelector( '.efx-mc-track' );
		var items = Array.prototype.slice.call( el.querySelectorAll( '.efx-mc-item' ) );
		var pointerX = 0;
		var hovering = false;
		var tickId = null;
		var current = [];
		var target = [];
		var openItem = null;
		var influence = parseFloat( getComputedStyle( el ).getPropertyValue( '--efx-mc-influence' ) ) || 220;
		var collapsed = parseFloat( getComputedStyle( el ).getPropertyValue( '--efx-mc-collapsed' ) ) || 86;
		var hover = parseFloat( getComputedStyle( el ).getPropertyValue( '--efx-mc-hover' ) ) || 210;
		var isCoarse = window.matchMedia( '(pointer: coarse)' ).matches;
		var clickHandlers = [];

		if ( ! track || ! items.length ) {
			return null;
		}

		items.forEach( function ( item, i ) {
			current[ i ] = collapsed;
			target[ i ] = collapsed;
			item.style.setProperty( '--efx-mc-width', collapsed + 'px' );
		} );

		function stopTickIfSettled() {
			if ( hovering || openItem ) {
				return;
			}
			var moving = current.some( function ( value ) {
				return Math.abs( value - collapsed ) > 0.5;
			} );
			if ( ! moving && tickId ) {
				ctx.ticker.remove( tickId );
				tickId = null;
			}
		}

		function ensureTick() {
			if ( tickId ) {
				return;
			}
			tickId = ctx.ticker.add( function ( dt ) {
				items.forEach( function ( item, i ) {
					current[ i ] = ctx.math.damp( current[ i ], target[ i ], 13, dt );
					item.style.setProperty( '--efx-mc-width', current[ i ].toFixed( 2 ) + 'px' );
					item.style.setProperty( '--efx-mc-scale', ( 1 + ( ( current[ i ] - collapsed ) / Math.max( 1, hover - collapsed ) ) * 0.045 ).toFixed( 4 ) );
					item.style.setProperty( '--efx-mc-title-opacity', Math.max( 0, Math.min( 1, ( current[ i ] - collapsed ) / Math.max( 1, hover - collapsed ) ) ).toFixed( 3 ) );
				} );
				stopTickIfSettled();
			} );
		}

		function updateTargets() {
			if ( openItem ) {
				items.forEach( function ( item, i ) {
					target[ i ] = item === openItem ? hover : collapsed;
				} );
				ensureTick();
				return;
			}

			items.forEach( function ( item, i ) {
				var rect = item.getBoundingClientRect();
				var center = rect.left + rect.width / 2;
				var distance = Math.abs( pointerX - center );
				var weight = hovering ? 1 - smoothstep( 0, influence, distance ) : 0;
				target[ i ] = collapsed + ( hover - collapsed ) * weight;
			} );
			ensureTick();
		}

		function onPointerMove( event ) {
			if ( ctx.env.reducedMotion || isCoarse ) {
				return;
			}
			pointerX = event.clientX;
			hovering = true;
			updateTargets();
		}

		function onPointerLeave() {
			hovering = false;
			if ( ! openItem ) {
				target = target.map( function () {
					return collapsed;
				} );
				ensureTick();
			}
		}

		function toggleOpen( item ) {
			openItem = openItem === item ? null : item;
			items.forEach( function (candidate) {
				candidate.classList.toggle( 'is-open', candidate === openItem );
			} );
			track.classList.toggle( 'has-open', !! openItem );
			updateTargets();
		}

		items.forEach( function ( item ) {
			var onClick = function () {
				toggleOpen( item );
			};
			clickHandlers.push( { item: item, handler: onClick } );
			item.addEventListener( 'click', onClick );
		} );

		track.addEventListener( 'pointermove', onPointerMove );
		track.addEventListener( 'pointerleave', onPointerLeave );

		return function () {
			if ( tickId ) {
				ctx.ticker.remove( tickId );
			}
			track.removeEventListener( 'pointermove', onPointerMove );
			track.removeEventListener( 'pointerleave', onPointerLeave );
			clickHandlers.forEach( function ( entry ) {
				entry.item.removeEventListener( 'click', entry.handler );
			} );
		};
	} );
} )();
