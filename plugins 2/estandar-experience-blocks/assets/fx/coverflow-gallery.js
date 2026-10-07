/**
 * Estandar FX — Coverflow Gallery runtime.
 */
( function () {
	'use strict';

	var FX = window.EstandarFX;
	if ( ! FX ) {
		return;
	}

	FX.component( '[data-efx-coverflow]', function ( el, ctx ) {
		var slides = Array.prototype.slice.call( el.querySelectorAll( '.efx-coverflow-slide' ) );
		var dots = Array.prototype.slice.call( el.querySelectorAll( '.efx-coverflow-dot' ) );
		var prev = el.querySelector( '.efx-coverflow-prev' );
		var next = el.querySelector( '.efx-coverflow-next' );
		var stage = el.querySelector( '.efx-coverflow-stage' );
		var index = 0;
		var timer = null;
		var autoplay = el.getAttribute( 'data-autoplay' ) === '1' && ! ctx.env.reducedMotion;
		var interval = Math.max( 1600, parseInt( el.getAttribute( 'data-interval' ), 10 ) || 4200 );

		if ( ! slides.length ) {
			return null;
		}

		slides.forEach( function ( slide, i ) {
			slide.style.setProperty( '--efx-coverflow-i', i );
		} );

		function setActive( nextIndex ) {
			index = ( nextIndex + slides.length ) % slides.length;
			el.style.setProperty( '--efx-coverflow-active', index );

			slides.forEach( function ( slide, i ) {
				var offset = i - index;
				slide.style.setProperty( '--efx-coverflow-offset', offset );
				slide.style.setProperty( '--efx-coverflow-depth', Math.abs( offset ) );
				slide.setAttribute( 'aria-hidden', i === index ? 'false' : 'true' );
			} );

			dots.forEach( function ( dot, i ) {
				dot.setAttribute( 'aria-selected', i === index ? 'true' : 'false' );
			} );
		}

		function stop() {
			if ( timer ) {
				window.clearInterval( timer );
				timer = null;
			}
		}

		function start() {
			stop();
			if ( autoplay && slides.length > 1 ) {
				timer = window.setInterval( function () {
					setActive( index + 1 );
				}, interval );
			}
		}

		function userSet( nextIndex ) {
			setActive( nextIndex );
			start();
		}

		function onPrev() {
			userSet( index - 1 );
		}

		function onNext() {
			userSet( index + 1 );
		}

		function onKeydown( event ) {
			if ( event.key === 'ArrowLeft' ) {
				event.preventDefault();
				onPrev();
			} else if ( event.key === 'ArrowRight' ) {
				event.preventDefault();
				onNext();
			}
		}

		if ( prev ) {
			prev.addEventListener( 'click', onPrev );
		}
		if ( next ) {
			next.addEventListener( 'click', onNext );
		}
		if ( stage ) {
			stage.addEventListener( 'keydown', onKeydown );
			stage.addEventListener( 'mouseenter', stop );
			stage.addEventListener( 'mouseleave', start );
			stage.addEventListener( 'focusin', stop );
			stage.addEventListener( 'focusout', start );
		}

		dots.forEach( function ( dot ) {
			dot.addEventListener( 'click', function () {
				userSet( parseInt( dot.getAttribute( 'data-index' ), 10 ) || 0 );
			} );
		} );

		setActive( 0 );
		start();

		return function () {
			stop();
			if ( prev ) {
				prev.removeEventListener( 'click', onPrev );
			}
			if ( next ) {
				next.removeEventListener( 'click', onNext );
			}
			if ( stage ) {
				stage.removeEventListener( 'keydown', onKeydown );
				stage.removeEventListener( 'mouseenter', stop );
				stage.removeEventListener( 'mouseleave', start );
				stage.removeEventListener( 'focusin', stop );
				stage.removeEventListener( 'focusout', start );
			}
		};
	} );
} )();
