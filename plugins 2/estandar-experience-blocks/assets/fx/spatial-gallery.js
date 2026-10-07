/**
 * Estandar FX — Spatial Gallery runtime.
 */
( function () {
	'use strict';

	var FX = window.EstandarFX;
	if ( ! FX ) {
		return;
	}

	FX.component( '[data-efx-spatial-gallery]', function ( el, ctx ) {
		var items = Array.prototype.slice.call( el.querySelectorAll( '.efx-sg-item' ) );
		var index = el.querySelector( '.efx-sg-index' );
		var tickId = null;
		var states = [];
		var isCoarse = window.matchMedia( '(pointer: coarse)' ).matches;

		if ( ! items.length ) {
			return null;
		}

		items.forEach( function ( item, i ) {
			states[ i ] = { x: 0, y: 0, tx: 0, ty: 0, scale: 1, targetScale: 1 };
		} );

		function ensureTick() {
			if ( tickId || ctx.env.reducedMotion || isCoarse ) {
				return;
			}
			tickId = ctx.ticker.add( function ( dt ) {
				var moving = false;
				items.forEach( function ( item, i ) {
					var s = states[ i ];
					s.x = ctx.math.damp( s.x, s.tx, 9, dt );
					s.y = ctx.math.damp( s.y, s.ty, 9, dt );
					s.scale = ctx.math.damp( s.scale, s.targetScale, 9, dt );
					item.style.setProperty( '--efx-sg-dx', s.x.toFixed( 2 ) + 'px' );
					item.style.setProperty( '--efx-sg-dy', s.y.toFixed( 2 ) + 'px' );
					item.style.setProperty( '--efx-sg-scale', s.scale.toFixed( 4 ) );
					if ( Math.abs( s.x - s.tx ) > 0.4 || Math.abs( s.y - s.ty ) > 0.4 || Math.abs( s.scale - s.targetScale ) > 0.003 ) {
						moving = true;
					}
				} );
				if ( ! moving ) {
					ctx.ticker.remove( tickId );
					tickId = null;
				}
			} );
		}

		function setFocus( item ) {
			var active = item && item.classList.contains( 'is-focus' ) ? null : item;
			el.classList.toggle( 'has-focus', !! active );
			items.forEach( function ( candidate, i ) {
				var isActive = candidate === active;
				candidate.classList.toggle( 'is-focus', isActive );
				states[ i ].targetScale = isActive ? parseFloat( getComputedStyle( el ).getPropertyValue( '--efx-sg-focus' ) ) || 1.18 : 1;
			} );
			if ( index && active ) {
				index.textContent = String( items.indexOf( active ) + 1 ).padStart( 2, '0' ) + ' / ' + String( items.length ).padStart( 2, '0' );
			}
			ensureTick();
		}

		function onMove( event ) {
			if ( ctx.env.reducedMotion || isCoarse ) {
				return;
			}
			var rect = el.getBoundingClientRect();
			var x = ( event.clientX - rect.left ) / Math.max( 1, rect.width ) - 0.5;
			var y = ( event.clientY - rect.top ) / Math.max( 1, rect.height ) - 0.5;
			items.forEach( function ( item, i ) {
				var depth = ( i % 3 ) + 1;
				states[ i ].tx = x * depth * 10;
				states[ i ].ty = y * depth * 8;
			} );
			ensureTick();
		}

		function onLeave() {
			items.forEach( function ( item, i ) {
				states[ i ].tx = 0;
				states[ i ].ty = 0;
			} );
			ensureTick();
		}

		items.forEach( function ( item ) {
			item.addEventListener( 'click', function () { setFocus( item ); } );
			item.addEventListener( 'keydown', function ( event ) {
				if ( event.key === 'Enter' || event.key === ' ' ) {
					event.preventDefault();
					setFocus( item );
				}
			} );
		} );

		el.addEventListener( 'pointermove', onMove );
		el.addEventListener( 'pointerleave', onLeave );

		return function () {
			if ( tickId ) {
				ctx.ticker.remove( tickId );
			}
			el.removeEventListener( 'pointermove', onMove );
			el.removeEventListener( 'pointerleave', onLeave );
		};
	} );
} )();
