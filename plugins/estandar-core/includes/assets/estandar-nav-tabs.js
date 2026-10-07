/* Estandar Suite — navegación de tabs del admin (mejora de accesibilidad).
 *
 * Las pestañas de la Suite son ENLACES reales (navegan a otra URL), por eso se
 * mantienen como <a> con aria-current="page" en la activa. NO se les asigna
 * role="tab": ese rol es para paneles de la MISMA página, no para navegación.
 *
 * Este script sólo añade navegación por teclado (← → Home End) al enfocar una
 * pestaña. Sin drag & drop. Sin dependencias.
 */
( function () {
	'use strict';

	function enhance( nav ) {
		var tabs = Array.prototype.slice.call( nav.querySelectorAll( '.nav-tab' ) );
		if ( tabs.length < 2 ) {
			return;
		}

		tabs.forEach( function ( tab, index ) {
			tab.addEventListener( 'keydown', function ( event ) {
				var next = null;
				var last = tabs.length - 1;

				if ( event.key === 'ArrowRight' || event.key === 'ArrowDown' ) {
					next = tabs[ index === last ? 0 : index + 1 ];
				} else if ( event.key === 'ArrowLeft' || event.key === 'ArrowUp' ) {
					next = tabs[ index === 0 ? last : index - 1 ];
				} else if ( event.key === 'Home' ) {
					next = tabs[ 0 ];
				} else if ( event.key === 'End' ) {
					next = tabs[ last ];
				}

				if ( next ) {
					event.preventDefault();
					next.focus();
				}
			} );
		} );
	}

	function init() {
		document.querySelectorAll( '.nav-tab-wrapper' ).forEach( enhance );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
