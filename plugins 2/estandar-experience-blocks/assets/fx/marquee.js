/**
 * Estandar FX — Infinite Marquee runtime.
 *
 * The motion itself is a CSS animation (compositor thread, zero per-frame
 * JS). JS only measures the content group, clones it enough times to cover
 * 2× the container, and derives the animation duration from the configured
 * speed so the velocity is constant regardless of content length.
 *
 * Under prefers-reduced-motion the row simply stands still (core.css).
 */
( function () {
	'use strict';

	var FX = window.EstandarFX;
	if ( ! FX ) {
		return;
	}

	FX.component( '.efx-marquee', function ( el ) {
		var track = el.querySelector( '.efx-marquee-track' );
		var group = el.querySelector( '.efx-marquee-group' );

		if ( ! track || ! group ) {
			return null;
		}

		var speed = Math.max( 10, parseFloat( el.getAttribute( 'data-speed' ) ) || 60 ); // px/s
		var clones = [];
		var resizeTimer = null;

		function measureAndFill() {
			clones.forEach( function ( clone ) {
				clone.remove();
			} );
			clones = [];

			var groupWidth = group.scrollWidth;
			var containerWidth = el.clientWidth;

			if ( groupWidth < 1 || containerWidth < 1 ) {
				return;
			}

			// Enough copies that one full group can scroll out while the
			// container stays covered.
			var copies = Math.max( 1, Math.ceil( ( containerWidth * 2 ) / groupWidth ) );

			for ( var i = 0; i < copies; i++ ) {
				var clone = group.cloneNode( true );
				clone.setAttribute( 'aria-hidden', 'true' );
				clone.querySelectorAll( 'a, button, input, [tabindex]' ).forEach( function ( focusable ) {
					focusable.setAttribute( 'tabindex', '-1' );
				} );
				track.appendChild( clone );
				clones.push( clone );
			}

			track.style.setProperty( '--efx-marquee-distance', groupWidth + 'px' );
			track.style.setProperty( '--efx-marquee-duration', ( groupWidth / speed ).toFixed( 3 ) + 's' );
			el.classList.add( 'is-ready' );
		}

		var ro = new ResizeObserver( function () {
			clearTimeout( resizeTimer );
			resizeTimer = setTimeout( measureAndFill, 150 );
		} );
		ro.observe( el );

		if ( document.fonts && document.fonts.ready ) {
			document.fonts.ready.then( measureAndFill );
		}
		measureAndFill();

		return function () {
			ro.disconnect();
			clearTimeout( resizeTimer );
			clones.forEach( function ( clone ) {
				clone.remove();
			} );
			el.classList.remove( 'is-ready' );
		};
	} );
} )();
