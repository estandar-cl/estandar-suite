/**
 * Estandar FX — Text engine (split-text entrances).
 *
 * Splitting is accessibility-safe: the original markup is preserved for
 * restore, the container is labelled with the plain text, and every visual
 * fragment is aria-hidden. Screen readers always get one clean string.
 *
 * Effects:
 *   cascade              words/chars rise in with stagger      (WAAPI, no rAF)
 *   typewriter           chars appear sequentially with caret  (single ticker sub)
 *   rotating-typewriter  phrase loop split by |                (single ticker sub)
 *   scramble             chars decode from random glyphs       (single ticker sub)
 *   glitch               cinematic flicker reveal              (WAAPI, no rAF)
 *   reveal-wall          dense word wall reveal                 (WAAPI, no rAF)
 *
 * Options:
 *   split   'words' | 'chars'   (cascade only; default 'words')
 *   speed   chars per second    (typewriter; default 28)
 */
( function () {
	'use strict';

	var FX = window.EstandarFX;
	if ( ! FX ) {
		return;
	}

	var GLYPHS = '!<>-_\\/[]{}—=+*^?#________';

	/* ------------------------------------------------------------------ */
	/*  Split util                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Wrap the text nodes of `el` into word/char spans, preserving any inline
	 * elements (strong, em, a…). Returns the created fragment spans.
	 */
	function split( el, mode ) {
		var fragments = [];
		var walker = document.createTreeWalker( el, NodeFilter.SHOW_TEXT, null );
		var textNodes = [];
		var node;

		while ( ( node = walker.nextNode() ) ) {
			if ( node.nodeValue && node.nodeValue.trim() !== '' ) {
				textNodes.push( node );
			}
		}

		textNodes.forEach( function ( textNode ) {
			var text = textNode.nodeValue;
			var container = document.createDocumentFragment();
			var words = text.split( /(\s+)/ );

			words.forEach( function ( word ) {
				if ( '' === word ) {
					return;
				}
				if ( /^\s+$/.test( word ) ) {
					container.appendChild( document.createTextNode( ' ' ) );
					return;
				}

				var wordSpan = document.createElement( 'span' );
				wordSpan.className = 'efx-word';
				wordSpan.setAttribute( 'aria-hidden', 'true' );

				if ( 'chars' === mode ) {
					Array.from( word ).forEach( function ( ch ) {
						var charSpan = document.createElement( 'span' );
						charSpan.className = 'efx-char';
						charSpan.textContent = ch;
						wordSpan.appendChild( charSpan );
						fragments.push( charSpan );
					} );
				} else {
					wordSpan.textContent = word;
					fragments.push( wordSpan );
				}

				container.appendChild( wordSpan );
			} );

			textNode.parentNode.replaceChild( container, textNode );
		} );

		return fragments;
	}

	/**
	 * Shared prepare/restore plumbing for all text effects.
	 */
	function makeSplitState( el, mode ) {
		return {
			original: null,
			fragments: [],
			prepare: function () {
				if ( null !== this.original ) {
					return this.fragments;
				}
				this.original = el.innerHTML;
				var label = ( el.textContent || '' ).replace( /\s+/g, ' ' ).trim();
				if ( label && ! el.hasAttribute( 'aria-label' ) ) {
					el.setAttribute( 'aria-label', label );
					el.setAttribute( 'data-efx-labelled', '' );
				}
				this.fragments = split( el, mode );
				return this.fragments;
			},
			restore: function () {
				if ( null === this.original ) {
					return;
				}
				el.innerHTML = this.original;
				this.original = null;
				this.fragments = [];
				if ( el.hasAttribute( 'data-efx-labelled' ) ) {
					el.removeAttribute( 'aria-label' );
					el.removeAttribute( 'data-efx-labelled' );
				}
			},
		};
	}

	/* ------------------------------------------------------------------ */
	/*  cascade                                                            */
	/* ------------------------------------------------------------------ */

	function cascade( el, opts, ctx ) {
		var mode = 'chars' === opts.split ? 'chars' : 'words';
		var state = makeSplitState( el, mode );
		var running = [];

		return {
			prepare: function () {
				state.prepare().forEach( function ( fragment ) {
					fragment.style.opacity = '0';
				} );
			},

			play: function () {
				var stagger = 'chars' === mode ? Math.max( 8, ctx.timing.stagger / 2 ) : ctx.timing.stagger;

				state.fragments.forEach( function ( fragment, i ) {
					fragment.style.opacity = '';

					var anim = fragment.animate(
						[
							{ opacity: 0, transform: 'translate3d(0, 0.55em, 0) rotate(2deg)' },
							{ opacity: 1, transform: 'none' },
						],
						{
							duration: ctx.timing.duration,
							delay: ctx.timing.delay + i * stagger,
							easing: ctx.timing.easing,
							fill: 'both',
						}
					);
					running.push( anim );
					anim.onfinish = function () {
						anim.cancel();
					};
				} );

				var total = running.length;
				if ( total > 0 ) {
					running[ total - 1 ].addEventListener( 'finish', function () {
						state.restore();
						running = [];
					}, { once: true } );
				}
			},

			reset: function () {
				running.forEach( function ( anim ) {
					anim.cancel();
				} );
				running = [];
				state.restore();
			},
		};
	}

	/* ------------------------------------------------------------------ */
	/*  typewriter                                                         */
	/* ------------------------------------------------------------------ */

	function typewriter( el, opts, ctx ) {
		var state = makeSplitState( el, 'chars' );
		var tickId = null;
		var caret = null;

		function stopTick() {
			if ( null !== tickId ) {
				ctx.ticker.remove( tickId );
				tickId = null;
			}
		}

		function removeCaret() {
			if ( caret && caret.parentNode ) {
				caret.parentNode.removeChild( caret );
			}
			caret = null;
		}

		return {
			prepare: function () {
				state.prepare().forEach( function ( ch ) {
					ch.style.visibility = 'hidden';
				} );
			},

			play: function () {
				var chars = state.fragments;
				var cps = ctx.math.clamp( 'number' === typeof opts.speed ? opts.speed : 28, 2, 120 );
				var shown = 0;
				var elapsed = -ctx.timing.delay / 1000;

				caret = document.createElement( 'span' );
				caret.className = 'efx-caret';
				caret.setAttribute( 'aria-hidden', 'true' );
				el.appendChild( caret );

				stopTick();
				tickId = ctx.ticker.add( function ( dt ) {
					elapsed += dt;
					var target = Math.floor( Math.max( 0, elapsed ) * cps );

					while ( shown < chars.length && shown < target ) {
						chars[ shown ].style.visibility = '';
						// Caret rides right behind the last revealed character.
						// Hidden chars keep their space, so layout never reflows.
						chars[ shown ].insertAdjacentElement( 'afterend', caret );
						shown++;
					}

					if ( shown >= chars.length ) {
						stopTick();
						removeCaret();
						state.restore();
					}
				} );
			},

			reset: function () {
				stopTick();
				removeCaret();
				state.restore();
			},
		};
	}

	/* ------------------------------------------------------------------ */
	/*  scramble                                                           */
	/* ------------------------------------------------------------------ */

	function scramble( el, opts, ctx ) {
		var state = makeSplitState( el, 'chars' );
		var tickId = null;
		var finals = [];

		function stopTick() {
			if ( null !== tickId ) {
				ctx.ticker.remove( tickId );
				tickId = null;
			}
		}

		return {
			prepare: function () {
				var chars = state.prepare();

				// Batched read → write: freeze every char at its natural width
				// so random glyphs can never reflow the paragraph per frame.
				var widths = chars.map( function ( ch ) {
					return ch.offsetWidth;
				} );

				finals = chars.map( function ( ch, i ) {
					var value = ch.textContent;
					if ( widths[ i ] > 0 ) {
						ch.style.display = 'inline-block';
						ch.style.width = widths[ i ] + 'px';
						ch.style.textAlign = 'center';
					}
					ch.style.opacity = '0';
					return value;
				} );
			},

			play: function () {
				var chars = state.fragments;
				var stagger = Math.max( 14, ctx.timing.stagger / 2 ) / 1000;
				var hold = ctx.timing.duration / 1000 * 0.55;
				var elapsed = -ctx.timing.delay / 1000;

				chars.forEach( function ( ch ) {
					ch.style.opacity = '';
				} );

				stopTick();
				tickId = ctx.ticker.add( function ( dt ) {
					elapsed += dt;
					var done = true;

					chars.forEach( function ( ch, i ) {
						var start = i * stagger;
						var settle = start + hold;

						if ( elapsed < start ) {
							done = false;
							ch.textContent = ' ';
						} else if ( elapsed < settle ) {
							done = false;
							ch.textContent = GLYPHS.charAt( Math.floor( Math.random() * GLYPHS.length ) );
						} else {
							ch.textContent = finals[ i ];
						}
					} );

					if ( done ) {
						stopTick();
						state.restore();
					}
				} );
			},

			reset: function () {
				stopTick();
				state.restore();
			},
		};
	}

	function rotatingTypewriter( el, opts, ctx ) {
		var original = null;
		var tickId = null;
		var caret = null;

		function stopTick() {
			if ( null !== tickId ) {
				ctx.ticker.remove( tickId );
				tickId = null;
			}
		}

		function restore() {
			stopTick();
			if ( caret && caret.parentNode ) {
				caret.parentNode.removeChild( caret );
			}
			caret = null;
			if ( null !== original ) {
				el.textContent = original;
				original = null;
			}
		}

		return {
			prepare: function () {
				if ( null === original ) {
					original = el.textContent || '';
				}
				el.textContent = '';
			},

			play: function () {
				var phrases = ( original || el.textContent || '' ).split( '|' ).map( function ( item ) {
					return item.trim();
				} ).filter( Boolean );
				if ( phrases.length < 1 ) {
					return;
				}
				var cps = ctx.math.clamp( 'number' === typeof opts.speed ? opts.speed : 28, 4, 90 );
				var hold = ctx.math.clamp( 'number' === typeof opts.hold ? opts.hold : 1.4, 0.4, 6 );
				var deleting = false;
				var phraseIndex = 0;
				var charIndex = 0;
				var wait = 0;
				caret = document.createElement( 'span' );
				caret.className = 'efx-caret';
				caret.setAttribute( 'aria-hidden', 'true' );

				stopTick();
				tickId = ctx.ticker.add( function ( dt ) {
					if ( wait > 0 ) {
						wait -= dt;
						return;
					}

					var phrase = phrases[ phraseIndex ];
					var delta = Math.max( 1, Math.floor( cps * dt ) );
					if ( deleting ) {
						charIndex = Math.max( 0, charIndex - delta );
						if ( 0 === charIndex ) {
							deleting = false;
							phraseIndex = ( phraseIndex + 1 ) % phrases.length;
						}
					} else {
						charIndex = Math.min( phrase.length, charIndex + delta );
						if ( charIndex === phrase.length ) {
							if ( phrases.length === 1 ) {
								stopTick();
							} else {
								deleting = true;
								wait = hold;
							}
						}
					}

					el.textContent = phrase.slice( 0, charIndex );
					el.appendChild( caret );
				} );
			},

			reset: restore,
		};
	}

	function glitch( el, opts, ctx ) {
		var state = makeSplitState( el, 'chars' );
		var running = [];
		var intensity = ctx.math.clamp( 'number' === typeof opts.intensity ? opts.intensity : 1, 0.2, 2 );

		return {
			prepare: function () {
				state.prepare().forEach( function ( ch ) {
					ch.style.opacity = '0';
					ch.style.filter = 'blur(3px)';
				} );
			},

			play: function () {
				state.fragments.forEach( function ( ch, i ) {
					ch.style.opacity = '';
					var shift = ( i % 2 ? -1 : 1 ) * 0.08 * intensity;
					var anim = ch.animate(
						[
							{ opacity: 0, transform: 'translate3d(' + shift + 'em,0,0)', filter: 'blur(4px)', textShadow: '0 0 0 currentColor' },
							{ opacity: 1, transform: 'translate3d(' + -shift + 'em,0,0)', filter: 'blur(1px)', textShadow: shift + 'em 0 var(--efx-accent), ' + -shift + 'em 0 var(--efx-accent-b)' },
							{ opacity: 1, transform: 'none', filter: 'none', textShadow: 'none' },
						],
						{
							duration: Math.max( 280, ctx.timing.duration * 0.75 ),
							delay: ctx.timing.delay + i * Math.max( 10, ctx.timing.stagger / 3 ),
							easing: ctx.timing.easing,
							fill: 'both',
						}
					);
					running.push( anim );
					anim.onfinish = function () {
						anim.cancel();
					};
				} );

				if ( running.length ) {
					running[ running.length - 1 ].addEventListener( 'finish', function () {
						state.restore();
						running = [];
					}, { once: true } );
				}
			},

			reset: function () {
				running.forEach( function ( anim ) {
					anim.cancel();
				} );
				running = [];
				state.restore();
			},
		};
	}

	function revealWall( el, opts, ctx ) {
		var state = makeSplitState( el, 'words' );
		var running = [];

		return {
			prepare: function () {
				state.prepare().forEach( function ( word ) {
					word.style.opacity = '0';
				} );
			},

			play: function () {
				state.fragments.forEach( function ( word, i ) {
					word.style.opacity = '';
					var rowOffset = ( i % 5 ) * 0.04;
					var anim = word.animate(
						[
							{ opacity: 0, transform: 'translate3d(0,0.9em,0) scale(0.96)', filter: 'blur(8px)' },
							{ opacity: 1, transform: 'translate3d(0,' + rowOffset + 'em,0) scale(1)', filter: 'blur(0)' },
							{ opacity: 1, transform: 'none', filter: 'none' },
						],
						{
							duration: ctx.timing.duration,
							delay: ctx.timing.delay + i * Math.max( 18, ctx.timing.stagger / 2 ),
							easing: ctx.timing.easing,
							fill: 'both',
						}
					);
					running.push( anim );
					anim.onfinish = function () {
						anim.cancel();
					};
				} );

				if ( running.length ) {
					running[ running.length - 1 ].addEventListener( 'finish', function () {
						state.restore();
						running = [];
					}, { once: true } );
				}
			},

			reset: function () {
				running.forEach( function ( anim ) {
					anim.cancel();
				} );
				running = [];
				state.restore();
			},
		};
	}

	FX.register( 'text', {
		cascade: cascade,
		typewriter: typewriter,
		'rotating-typewriter': rotatingTypewriter,
		scramble: scramble,
		glitch: glitch,
		'reveal-wall': revealWall,
	}, { kind: 'enter' } );
} )();
