/**
 * Estandar FX — Before/After comparison runtime.
 *
 * Interaction, not decoration: it stays fully functional under
 * prefers-reduced-motion. A real <input type="range"> drives the position,
 * so keyboard and assistive tech work natively; pointer drag simply writes
 * back to the same input. The divider is a clip-path — GPU-friendly and
 * layout-free.
 */
( function () {
	'use strict';

	var FX = window.EstandarFX;
	if ( ! FX ) {
		return;
	}

	FX.component( '.efx-ba', function ( el ) {
		var range = el.querySelector( '.efx-ba-range' );

		if ( ! range ) {
			return null;
		}

		var dragging = false;

		function setPosition( percent ) {
			percent = Math.max( 0, Math.min( 100, percent ) );
			el.style.setProperty( '--efx-ba-pos', percent + '%' );
		}

		function onInput() {
			setPosition( parseFloat( range.value ) );
		}

		function fromPointer( e ) {
			var rect = el.getBoundingClientRect();
			var percent = ( ( e.clientX - rect.left ) / rect.width ) * 100;
			range.value = String( Math.round( percent ) );
			setPosition( percent );
		}

		function onDown( e ) {
			if ( 'mouse' === e.pointerType && 0 !== e.button ) {
				return;
			}
			dragging = true;
			el.classList.add( 'is-dragging' );
			el.setPointerCapture && el.setPointerCapture( e.pointerId );
			fromPointer( e );
		}

		function onMove( e ) {
			if ( dragging ) {
				fromPointer( e );
			}
		}

		function onUp() {
			dragging = false;
			el.classList.remove( 'is-dragging' );
		}

		range.addEventListener( 'input', onInput );
		el.addEventListener( 'pointerdown', onDown );
		el.addEventListener( 'pointermove', onMove );
		el.addEventListener( 'pointerup', onUp );
		el.addEventListener( 'pointercancel', onUp );

		setPosition( parseFloat( range.value ) || 50 );
		el.classList.add( 'is-ready' );

		return function () {
			range.removeEventListener( 'input', onInput );
			el.removeEventListener( 'pointerdown', onDown );
			el.removeEventListener( 'pointermove', onMove );
			el.removeEventListener( 'pointerup', onUp );
			el.removeEventListener( 'pointercancel', onUp );
			el.classList.remove( 'is-ready', 'is-dragging' );
		};
	} );
} )();
