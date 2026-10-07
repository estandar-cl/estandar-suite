/**
 * Estandar FX — Cover Transition runtime.
 *
 * This block is intentionally self-mounting: it must work even if the shared FX
 * kernel is delayed, because its primary action is a direct click interaction.
 */
( function () {
	'use strict';

	function mount( el ) {
		if ( el.dataset.efxCoverReady ) {
			return;
		}
		el.dataset.efxCoverReady = '1';

		var buttons = Array.prototype.slice.call( el.querySelectorAll( '.efx-ct-open' ) );
		var backs = Array.prototype.slice.call( el.querySelectorAll( '.efx-ct-back' ) );
		var previews = Array.prototype.slice.call( el.querySelectorAll( '.efx-ct-preview' ) );
		var overlay = el.querySelector( '.efx-ct-overlay' );
		var rows = overlay ? Array.prototype.slice.call( overlay.children ) : [];
		var active = -1;
		var busy = false;
		var duration = parseInt( getComputedStyle( el ).getPropertyValue( '--efx-ct-duration' ), 10 ) || 760;
		var reduced = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

		function rowsOn( on, instant ) {
			if ( ! overlay ) {
				return;
			}
			overlay.style.visibility = 'visible';
			rows.forEach( function ( row, i ) {
				row.style.transition = ( reduced || instant ) ? 'none' : 'transform ' + duration + 'ms cubic-bezier(.22,1,.36,1)';
				row.style.transitionDelay = ( reduced || instant ) ? '0ms' : ( i * 70 ) + 'ms';
				row.style.transform = el.classList.contains( 'efx-cover-transition--lift' )
					? ( on ? 'translateY(0)' : 'translateY(-100%)' )
					: ( on ? 'scaleX(1)' : 'scaleX(0)' );
			} );
		}

		function activate( index ) {
			previews.forEach( function ( preview, i ) {
				var isActive = i === index;
				preview.classList.toggle( 'is-active', isActive );
				preview.setAttribute( 'aria-hidden', isActive ? 'false' : 'true' );
			} );
			el.classList.add( 'is-open' );
			document.documentElement.classList.add( 'efx-ct-lock' );
			active = index;
		}

		function open( index ) {
			if ( busy || ! previews[ index ] ) {
				return;
			}

			if ( reduced ) {
				activate( index );
				return;
			}

			busy = true;
			rowsOn( true, true );
			activate( index );

			// Force the covered state to paint, then reveal the active detail view.
			if ( overlay ) {
				overlay.offsetHeight;
			}

			requestAnimationFrame( function () {
				rowsOn( false, false );

				window.setTimeout( function () {
					if ( overlay ) {
						overlay.style.visibility = 'hidden';
					}
					busy = false;
				}, duration + 160 );
			} );
		}

		function close() {
			if ( active < 0 ) {
				return;
			}
			previews.forEach( function ( preview ) {
				preview.classList.remove( 'is-active' );
				preview.setAttribute( 'aria-hidden', 'true' );
			} );
			el.classList.remove( 'is-open' );
			document.documentElement.classList.remove( 'efx-ct-lock' );
			active = -1;
		}

		buttons.forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				open( parseInt( button.getAttribute( 'data-index' ), 10 ) || 0 );
			} );
		} );

		backs.forEach( function ( button ) {
			button.addEventListener( 'click', close );
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Escape' ) {
				close();
			}
		} );
	}

	function boot() {
		document.querySelectorAll( '[data-efx-cover-transition]' ).forEach( mount );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
