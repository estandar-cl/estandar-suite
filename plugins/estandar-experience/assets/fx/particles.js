/**
 * Estandar FX — Particle engine (canvas, continuous).
 *
 * A soft ambient particle field behind the block's content. Performance
 * posture: DPR capped at 2, particle count derived from area and clamped,
 * the loop runs only while the block is near the viewport, and the canvas
 * is a composited layer (absolute + transform-free drawing).
 *
 * Effects / options:
 *   dots          density 0.5..2 · speed 0.2..2 · color (hex) · link (bool)
 *   line-ripple       density 0.5..2 · speed 0.2..2 · color (hex) · length 0.5..2 · force 0..2
 *   blinking-squares  density 0.5..2 · speed 0.2..2 · color (hex)
 */
( function () {
	'use strict';

	var FX = window.EstandarFX;
	if ( ! FX ) {
		return;
	}

	function hexToRgb( hex ) {
		var value = /^#?([a-f\d]{3}|[a-f\d]{6})$/i.exec( ( hex || '' ).trim() );
		if ( ! value ) {
			return { r: 255, g: 255, b: 255 };
		}
		var h = value[ 1 ];
		if ( 3 === h.length ) {
			h = h[ 0 ] + h[ 0 ] + h[ 1 ] + h[ 1 ] + h[ 2 ] + h[ 2 ];
		}
		var n = parseInt( h, 16 );
		return { r: ( n >> 16 ) & 255, g: ( n >> 8 ) & 255, b: n & 255 };
	}

	function dots( el, opts, ctx ) {
		var density = ctx.math.clamp( 'number' === typeof opts.density ? opts.density : 1, 0.5, 2 );
		var speed = ctx.math.clamp( 'number' === typeof opts.speed ? opts.speed : 1, 0.2, 2 );
		var link = true === opts.link;
		var rgb = hexToRgb( 'string' === typeof opts.color ? opts.color : '#ffffff' );

		var canvas = document.createElement( 'canvas' );
		canvas.className = 'efx-particles-canvas';
		canvas.setAttribute( 'aria-hidden', 'true' );

		el.classList.add( 'efx-host' );
		el.insertBefore( canvas, el.firstChild );

		var g = canvas.getContext( '2d' );
		var particles = [];
		var width = 0;
		var height = 0;
		var rectLeft = 0;
		var rectTop = 0;
		var active = false;
		var tickId = null;
		var release = ctx.pointer.use();

		function seed() {
			var count = Math.round( ctx.math.clamp( ( width * height ) / 22000, 8, 90 ) * density );
			particles = [];
			for ( var i = 0; i < count; i++ ) {
				particles.push( {
					x: Math.random() * width,
					y: Math.random() * height,
					vx: ( Math.random() - 0.5 ) * 14 * speed,
					vy: ( Math.random() - 0.5 ) * 14 * speed,
					r: 0.8 + Math.random() * 1.8,
					a: 0.25 + Math.random() * 0.5,
				} );
			}
		}

		function measure() {
			var rect = el.getBoundingClientRect();
			rectLeft = rect.left;
			rectTop = rect.top;
			return rect;
		}

		function resize() {
			var rect = measure();
			var dpr = ctx.env.dpr;
			width = Math.max( 1, rect.width );
			height = Math.max( 1, rect.height );
			canvas.width = Math.round( width * dpr );
			canvas.height = Math.round( height * dpr );
			g.setTransform( dpr, 0, 0, dpr, 0, 0 );
			seed();
			draw( 0 );
		}

		function draw( dt ) {
			g.clearRect( 0, 0, width, height );

			var driftX = ctx.pointer.state.active ? ctx.pointer.state.nx * 5 : 0;
			var driftY = ctx.pointer.state.active ? ctx.pointer.state.ny * 5 : 0;
			var base = 'rgba(' + rgb.r + ',' + rgb.g + ',' + rgb.b + ',';
			var i;
			var p;

			for ( i = 0; i < particles.length; i++ ) {
				p = particles[ i ];
				p.x += ( p.vx + driftX ) * dt;
				p.y += ( p.vy + driftY ) * dt;

				if ( p.x < -4 ) {
					p.x = width + 4;
				} else if ( p.x > width + 4 ) {
					p.x = -4;
				}
				if ( p.y < -4 ) {
					p.y = height + 4;
				} else if ( p.y > height + 4 ) {
					p.y = -4;
				}

				g.beginPath();
				g.arc( p.x, p.y, p.r, 0, Math.PI * 2 );
				g.fillStyle = base + p.a + ')';
				g.fill();
			}

			if ( link ) {
				var maxDist = 110;
				for ( i = 0; i < particles.length; i++ ) {
					for ( var j = i + 1; j < particles.length; j++ ) {
						var dx = particles[ i ].x - particles[ j ].x;
						var dy = particles[ i ].y - particles[ j ].y;
						var dist = dx * dx + dy * dy;
						if ( dist < maxDist * maxDist ) {
							g.beginPath();
							g.moveTo( particles[ i ].x, particles[ i ].y );
							g.lineTo( particles[ j ].x, particles[ j ].y );
							g.strokeStyle = base + ( 0.12 * ( 1 - Math.sqrt( dist ) / maxDist ) ).toFixed( 3 ) + ')';
							g.lineWidth = 1;
							g.stroke();
						}
					}
				}
			}
		}

		function ensure() {
			if ( null === tickId && active ) {
				tickId = ctx.ticker.add( draw );
			}
		}

		function halt() {
			if ( null !== tickId ) {
				ctx.ticker.remove( tickId );
				tickId = null;
			}
		}

		var unobserve = ctx.visibility.observe(
			el,
			function ( entry ) {
				active = entry.isIntersecting;
				if ( active ) {
					ensure();
				} else {
					halt();
				}
			},
			{ margin: '10% 0px 10% 0px' }
		);

		var resizeTimer = null;
		var ro = new ResizeObserver( function () {
			clearTimeout( resizeTimer );
			resizeTimer = setTimeout( resize, 120 );
		} );
		ro.observe( el );

		resize();

		return function () {
			halt();
			unobserve();
			ro.disconnect();
			clearTimeout( resizeTimer );
			release();
			if ( canvas.parentNode ) {
				canvas.parentNode.removeChild( canvas );
			}
			el.classList.remove( 'efx-host' );
		};
	}

	function lineRipple( el, opts, ctx ) {
		var density = ctx.math.clamp( 'number' === typeof opts.density ? opts.density : 1, 0.5, 2 );
		var speed = ctx.math.clamp( 'number' === typeof opts.speed ? opts.speed : 1, 0.2, 2 );
		var length = ctx.math.clamp( 'number' === typeof opts.length ? opts.length : 1, 0.5, 2 );
		var force = ctx.math.clamp( 'number' === typeof opts.force ? opts.force : 1, 0, 2 );
		var rgb = hexToRgb( 'string' === typeof opts.color ? opts.color : '#ffffff' );

		var canvas = document.createElement( 'canvas' );
		canvas.className = 'efx-particles-canvas efx-line-ripple-canvas';
		canvas.setAttribute( 'aria-hidden', 'true' );

		el.classList.add( 'efx-host' );
		el.insertBefore( canvas, el.firstChild );

		var g = canvas.getContext( '2d' );
		var lines = [];
		var width = 0;
		var height = 0;
		var rectLeft = 0;
		var rectTop = 0;
		var active = false;
		var tickId = null;
		var release = ctx.pointer.use();
		var time = Math.random() * 1000;

		function flow( x, y, t ) {
			var nx = width ? x / width : 0;
			var ny = height ? y / height : 0;
			return Math.sin( nx * 7.4 + t * 0.85 ) + Math.cos( ny * 6.1 - t * 0.62 ) + Math.sin( ( nx + ny ) * 4.7 + t * 0.35 );
		}

		function seed() {
			var count = Math.round( ctx.math.clamp( ( width * height ) / 12000, 18, 120 ) * density );
			lines = [];
			for ( var i = 0; i < count; i++ ) {
				lines.push( {
					x: Math.random() * width,
					y: Math.random() * height,
					o: Math.random() * Math.PI * 2,
					a: 0.22 + Math.random() * 0.42,
					s: 0.65 + Math.random() * 0.7,
				} );
			}
		}

		function measure() {
			var rect = el.getBoundingClientRect();
			rectLeft = rect.left;
			rectTop = rect.top;
			return rect;
		}

		function resize() {
			var rect = measure();
			var dpr = ctx.env.dpr;
			width = Math.max( 1, rect.width );
			height = Math.max( 1, rect.height );
			canvas.width = Math.round( width * dpr );
			canvas.height = Math.round( height * dpr );
			g.setTransform( dpr, 0, 0, dpr, 0, 0 );
			seed();
			draw( 0 );
		}

		function draw( dt ) {
			time += dt * speed;
			g.clearRect( 0, 0, width, height );
			g.lineCap = 'round';
			g.lineWidth = Math.max( 0.7, length );

			var hasPointer = force > 0 && ctx.env.finePointer && ctx.pointer.state.active;
			var px = hasPointer ? ctx.pointer.state.x - rectLeft : -9999;
			var py = hasPointer ? ctx.pointer.state.y - rectTop : -9999;
			var radius = Math.max( 90, Math.min( width, height ) * 0.32 ) * force;
			var base = 'rgba(' + rgb.r + ',' + rgb.g + ',' + rgb.b + ',';
			var step = 14 * speed;

			for ( var i = 0; i < lines.length; i++ ) {
				var p = lines[ i ];
				var angle = flow( p.x, p.y, time ) + p.o * 0.16;
				var dx = p.x - px;
				var dy = p.y - py;
				var dist = Math.sqrt( dx * dx + dy * dy );

				if ( hasPointer && dist < radius ) {
					var pull = ( 1 - dist / radius ) * force;
					angle += Math.atan2( dy, dx ) * pull + Math.PI * 0.35 * pull;
					p.x += Math.cos( angle ) * step * dt * ( 1 + pull * 1.2 );
					p.y += Math.sin( angle ) * step * dt * ( 1 + pull * 1.2 );
				} else {
					p.x += Math.cos( angle ) * step * dt * p.s;
					p.y += Math.sin( angle ) * step * dt * p.s;
				}

				if ( p.x < -24 ) {
					p.x = width + 24;
				} else if ( p.x > width + 24 ) {
					p.x = -24;
				}
				if ( p.y < -24 ) {
					p.y = height + 24;
				} else if ( p.y > height + 24 ) {
					p.y = -24;
				}

				var lineLength = ( 18 + p.s * 24 ) * length;
				var tx = Math.cos( angle ) * lineLength;
				var ty = Math.sin( angle ) * lineLength;
				var alpha = p.a;
				if ( hasPointer && dist < radius ) {
					alpha = Math.min( 0.86, alpha + ( 1 - dist / radius ) * 0.38 );
				}

				g.beginPath();
				g.moveTo( p.x - tx * 0.5, p.y - ty * 0.5 );
				g.lineTo( p.x + tx * 0.5, p.y + ty * 0.5 );
				g.strokeStyle = base + alpha.toFixed( 3 ) + ')';
				g.stroke();
			}
		}

		function ensure() {
			if ( null === tickId && active && ! ctx.env.reducedMotion ) {
				tickId = ctx.ticker.add( draw );
			}
		}

		function halt() {
			if ( null !== tickId ) {
				ctx.ticker.remove( tickId );
				tickId = null;
			}
		}

		var unobserve = ctx.visibility.observe(
			el,
			function ( entry ) {
				active = entry.isIntersecting;
				if ( active ) {
					ensure();
				} else {
					halt();
				}
			},
			{ margin: '10% 0px 10% 0px' }
		);

		var resizeTimer = null;
		var ro = new ResizeObserver( function () {
			clearTimeout( resizeTimer );
			resizeTimer = setTimeout( resize, 120 );
		} );
		ro.observe( el );
		window.addEventListener( 'scroll', measure, { passive: true } );

		resize();

		return function () {
			halt();
			unobserve();
			ro.disconnect();
			window.removeEventListener( 'scroll', measure );
			clearTimeout( resizeTimer );
			release();
			if ( canvas.parentNode ) {
				canvas.parentNode.removeChild( canvas );
			}
			el.classList.remove( 'efx-host' );
		};
	}

	function blinkingSquares( el, opts, ctx ) {
		var density = ctx.math.clamp( 'number' === typeof opts.density ? opts.density : 1, 0.5, 2 );
		var speed = ctx.math.clamp( 'number' === typeof opts.speed ? opts.speed : 1, 0.2, 2 );
		var rgb = hexToRgb( 'string' === typeof opts.color ? opts.color : '#ffffff' );

		var canvas = document.createElement( 'canvas' );
		canvas.className = 'efx-particles-canvas efx-blinking-squares-canvas';
		canvas.setAttribute( 'aria-hidden', 'true' );

		el.classList.add( 'efx-host' );
		el.insertBefore( canvas, el.firstChild );

		var g = canvas.getContext( '2d' );
		var cells = [];
		var width = 0;
		var height = 0;
		var active = false;
		var tickId = null;
		var time = Math.random() * 10;

		function resize() {
			var rect = el.getBoundingClientRect();
			var dpr = ctx.env.dpr;
			width = Math.max( 1, rect.width );
			height = Math.max( 1, rect.height );
			canvas.width = Math.round( width * dpr );
			canvas.height = Math.round( height * dpr );
			g.setTransform( dpr, 0, 0, dpr, 0, 0 );
			seed();
			draw( 0 );
		}

		function seed() {
			var size = Math.round( ctx.math.clamp( 34 / density, 16, 52 ) );
			var cols = Math.ceil( width / size );
			var rows = Math.ceil( height / size );
			cells = [];
			for ( var y = 0; y < rows; y++ ) {
				for ( var x = 0; x < cols; x++ ) {
					cells.push( {
						x: x * size,
						y: y * size,
						size: size,
						phase: Math.random() * Math.PI * 2,
						amp: 0.08 + Math.random() * 0.35,
					} );
				}
			}
		}

		function draw( dt ) {
			time += dt * speed;
			g.clearRect( 0, 0, width, height );
			for ( var i = 0; i < cells.length; i++ ) {
				var c = cells[ i ];
				var pulse = ( Math.sin( time * 2.2 + c.phase ) + 1 ) / 2;
				var alpha = c.amp * Math.pow( pulse, 2.4 );
				if ( alpha < 0.025 ) {
					continue;
				}
				var inset = c.size * 0.25;
				g.fillStyle = 'rgba(' + rgb.r + ',' + rgb.g + ',' + rgb.b + ',' + alpha.toFixed( 3 ) + ')';
				g.fillRect( c.x + inset, c.y + inset, c.size - inset * 2, c.size - inset * 2 );
			}
		}

		function ensure() {
			if ( null === tickId && active && ! ctx.env.reducedMotion ) {
				tickId = ctx.ticker.add( draw );
			}
		}

		function halt() {
			if ( null !== tickId ) {
				ctx.ticker.remove( tickId );
				tickId = null;
			}
		}

		var unobserve = ctx.visibility.observe( el, function ( entry ) {
			active = entry.isIntersecting;
			if ( active ) {
				ensure();
			} else {
				halt();
			}
		}, { margin: '10% 0px 10% 0px' } );

		var resizeTimer = null;
		var ro = new ResizeObserver( function () {
			clearTimeout( resizeTimer );
			resizeTimer = setTimeout( resize, 120 );
		} );
		ro.observe( el );
		resize();

		return function () {
			halt();
			unobserve();
			ro.disconnect();
			clearTimeout( resizeTimer );
			if ( canvas.parentNode ) {
				canvas.parentNode.removeChild( canvas );
			}
			el.classList.remove( 'efx-host' );
		};
	}

	FX.register( 'particles', { dots: dots, 'line-ripple': lineRipple, 'blinking-squares': blinkingSquares } );
} )();
