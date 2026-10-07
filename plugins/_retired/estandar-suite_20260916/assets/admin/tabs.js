/* Estandar Suite — tabs de settings (navegación por teclado).
 * Las pestañas son enlaces server-side; aquí sólo se mejora la accesibilidad.
 * Sin drag & drop (decisión de diseño).
 */
( function () {
	'use strict';

	function init() {
		var navs = document.querySelectorAll( '.es-tabs' );
		navs.forEach( function ( nav ) {
			var tabs = Array.prototype.slice.call( nav.querySelectorAll( '.es-tab' ) );
			tabs.forEach( function ( tab, index ) {
				tab.addEventListener( 'keydown', function ( event ) {
					var next = null;
					if ( event.key === 'ArrowRight' ) {
						next = tabs[ ( index + 1 ) % tabs.length ];
					} else if ( event.key === 'ArrowLeft' ) {
						next = tabs[ ( index - 1 + tabs.length ) % tabs.length ];
					}
					if ( next ) {
						event.preventDefault();
						next.focus();
					}
				} );
			} );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
