/*
 * SPDX-FileCopyrightText: 2026 GoBuildWP <dev@gobuildwp.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * Visual runtime: captures queued pages from a logged-in wp-admin tab by
 * re-rendering them in a same-origin iframe through an SVG foreignObject and a
 * canvas. Kept as readable source with no build step.
 *
 * Data: window.wppilotVisualRuntime = { nonce }.
 */
( function () {
	var config = window.wppilotVisualRuntime || {};
	var apiFetch = window.wp && window.wp.apiFetch;
	if ( ! apiFetch ) {
		return;
	}
	if ( config.nonce && apiFetch.createNonceMiddleware ) {
		apiFetch.use( apiFetch.createNonceMiddleware( config.nonce ) );
	}

	var statusEl = document.getElementById( 'wppilot-visual-status' );
	var logEl = document.getElementById( 'wppilot-visual-log' );
	var frame = document.getElementById( 'wppilot-visual-frame' );
	var busy = false;

	function status( text ) {
		if ( statusEl ) {
			statusEl.textContent = text;
		}
	}

	function log( text ) {
		if ( ! logEl ) {
			return;
		}
		var li = document.createElement( 'li' );
		li.textContent = text;
		logEl.appendChild( li );
	}

	// Load a page into the iframe at a given width and wait for it to settle.
	function loadFrame( url, width ) {
		return new Promise( function ( resolve, reject ) {
			var timer = setTimeout( function () {
				reject( new Error( 'The page did not finish loading within 20 seconds.' ) );
			}, 20000 );

			frame.style.width = width + 'px';
			frame.style.height = '2000px';
			frame.onload = function () {
				clearTimeout( timer );
				// One frame for layout, then a beat for webfonts and lazy images.
				setTimeout( function () {
					resolve( frame.contentDocument );
				}, 1200 );
			};
			frame.onerror = function () {
				clearTimeout( timer );
				reject( new Error( 'The page could not be loaded in the capture frame.' ) );
			};
			frame.src = url;
		} );
	}

	// Inline a same-origin image as a data URL. A cross-origin one cannot be
	// read back out of a canvas, so it is left alone and reported instead.
	function inlineImage( img, notes ) {
		return new Promise( function ( resolve ) {
			var src = img.getAttribute( 'src' ) || '';
			if ( ! src || src.indexOf( 'data:' ) === 0 ) {
				resolve();
				return;
			}
			var absolute = new URL( src, frame.contentWindow.location.href );
			if ( absolute.origin !== window.location.origin ) {
				notes.push( 'cross-origin image not captured: ' + absolute.href );
				resolve();
				return;
			}
			fetch( absolute.href )
				.then( function ( response ) {
					return response.blob();
				} )
				.then( function ( blob ) {
					return new Promise( function ( done ) {
						var reader = new FileReader();
						reader.onloadend = function () {
							img.setAttribute( 'src', String( reader.result ) );
							done();
						};
						reader.readAsDataURL( blob );
					} );
				} )
				.then( resolve )
				.catch( function () {
					notes.push( 'image could not be inlined: ' + absolute.href );
					resolve();
				} );
		} );
	}

	// Copy the computed styles onto each node, because the SVG foreignObject is
	// rendered without the document's stylesheets.
	function inlineStyles( source, clone ) {
		var sourceNodes = source.querySelectorAll( '*' );
		var cloneNodes = clone.querySelectorAll( '*' );
		var view = frame.contentWindow;
		for ( var i = 0; i < sourceNodes.length && i < cloneNodes.length; i++ ) {
			var computed = view.getComputedStyle( sourceNodes[ i ] );
			var text = '';
			for ( var j = 0; j < computed.length; j++ ) {
				var property = computed[ j ];
				text += property + ':' + computed.getPropertyValue( property ) + ';';
			}
			cloneNodes[ i ].setAttribute( 'style', text );
		}
	}

	function rasterise( doc, width, notes ) {
		var body = doc.body;
		var height = Math.min( 8000, Math.max( body.scrollHeight, 600 ) );
		var clone = body.cloneNode( true );

		// Scripts never run inside a foreignObject and would only bloat it.
		Array.prototype.forEach.call( clone.querySelectorAll( 'script, noscript' ), function ( node ) {
			node.parentNode.removeChild( node );
		} );

		var images = Array.prototype.slice.call( clone.querySelectorAll( 'img' ) );
		return Promise.all(
			images.map( function ( img ) {
				return inlineImage( img, notes );
			} )
		).then( function () {
			inlineStyles( body, clone );

			var serialised = new XMLSerializer().serializeToString( clone );
			var svg =
				'<svg xmlns="http://www.w3.org/2000/svg" width="' + width + '" height="' + height + '">' +
				'<foreignObject width="100%" height="100%">' +
				'<div xmlns="http://www.w3.org/1999/xhtml">' + serialised + '</div>' +
				'</foreignObject></svg>';

			return new Promise( function ( resolve, reject ) {
				var image = new Image();
				image.onload = function () {
					var canvas = document.createElement( 'canvas' );
					canvas.width = width;
					canvas.height = height;
					var context = canvas.getContext( '2d' );
					context.fillStyle = '#ffffff';
					context.fillRect( 0, 0, width, height );
					context.drawImage( image, 0, 0 );
					try {
						resolve( canvas.toDataURL( 'image/png' ) );
					} catch ( error ) {
						reject( new Error( 'The canvas was tainted, so the capture could not be read back.' ) );
					}
				};
				image.onerror = function () {
					reject( new Error( 'The page could not be rasterised. It may use markup the capture cannot serialise.' ) );
				};
				image.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent( svg );
			} );
		} );
	}

	function runJob( job ) {
		var viewports = job.viewports && job.viewports.length ? job.viewports : [ 1440 ];
		var chain = Promise.resolve();

		viewports.forEach( function ( width ) {
			chain = chain.then( function () {
				var notes = [];
				status( 'capturing ' + job.url + ' at ' + width + 'px' );
				return loadFrame( job.url, width )
					.then( function ( doc ) {
						return rasterise( doc, width, notes );
					} )
					.then( function ( dataUrl ) {
						return apiFetch( {
							path: '/wppilot/v1/visual/complete',
							method: 'POST',
							data: { job_id: job.id, viewport: width, image: dataUrl, notes: notes },
						} );
					} )
					.then( function () {
						log( 'captured ' + job.url + ' at ' + width + 'px' );
					} );
			} );
		} );

		return chain.catch( function ( error ) {
			log( 'failed: ' + error.message );
			return apiFetch( {
				path: '/wppilot/v1/visual/fail',
				method: 'POST',
				data: { job_id: job.id, message: error.message },
			} );
		} );
	}

	function tick() {
		if ( busy ) {
			return;
		}
		busy = true;
		apiFetch( { path: '/wppilot/v1/visual/claim', method: 'POST' } )
			.then( function ( response ) {
				if ( ! response || ! response.job ) {
					status( 'waiting for a capture request' );
					busy = false;
					return null;
				}
				return runJob( response.job ).then( function () {
					status( 'waiting for a capture request' );
					busy = false;
				} );
			} )
			.catch( function () {
				status( 'could not reach the site; retrying' );
				busy = false;
			} );
	}

	status( 'waiting for a capture request' );
	tick();
	setInterval( tick, 5000 );
} )();
