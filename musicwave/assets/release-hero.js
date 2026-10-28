/**
 * Release hero cover tint — paints the full-bleed hero background from the
 * featured cover's dominant color so each release feels bespoke, like the
 * SonicStream reference where the hero gradient bleeds out of the artwork.
 *
 * Zero dependencies. Samples a 1×1 downscaled copy of the cover via canvas
 * (fast, <1ms) and tolerates CORS or decoding failures by keeping the CSS
 * accent fallback. Only runs on single release pages that actually render
 * the hero.
 *
 * @package
 */
( function () {
	'use strict';

	var SELECTOR = '.mw-release-hero';
	var COVER_SELECTOR = '.mw-release-hero__cover img';

	function isColorLike( value ) {
		return (
			typeof value === 'string' &&
			/^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test( value.trim() )
		);
	}

	function toHex( value ) {
		var hex = Math.max( 0, Math.min( 255, Math.round( value ) ) ).toString(
			16
		);
		return hex.length === 1 ? '0' + hex : hex;
	}

	function luminance( r, g, b ) {
		return 0.2126 * r + 0.7152 * g + 0.0722 * b;
	}

	function tintFromImage( img, hero ) {
		if (
			! img ||
			! hero ||
			img.naturalWidth < 1 ||
			img.naturalHeight < 1
		) {
			return;
		}
		if ( img.naturalWidth < 8 || img.naturalHeight < 8 ) {
			return;
		}
		var canvas = document.createElement( 'canvas' );
		var w = 1;
		var h = 1;
		try {
			canvas.width = w;
			canvas.height = h;
			var ctx = canvas.getContext( '2d', { willReadFrequently: true } );
			if ( ! ctx ) {
				return;
			}
			ctx.drawImage( img, 0, 0, w, h );
			var data = ctx.getImageData( 0, 0, w, h ).data;
			var r = data[ 0 ];
			var g = data[ 1 ];
			var b = data[ 2 ];
			var a = data[ 3 ];
			if ( a < 16 ) {
				return;
			}
			if ( luminance( r, g, b ) < 14 && r < 22 && g < 22 && b < 22 ) {
				return;
			}
			if ( luminance( r, g, b ) > 242 ) {
				return;
			}
			var hex = '#' + toHex( r ) + toHex( g ) + toHex( b );
			if ( ! isColorLike( hex ) ) {
				return;
			}
			hero.style.setProperty( '--mw-hero-cover', hex );
		} catch ( error ) {}
	}

	function init() {
		var hero = document.querySelector( SELECTOR );
		if ( ! hero ) {
			return;
		}
		var img = hero.querySelector( COVER_SELECTOR );
		if ( ! img ) {
			return;
		}
		if ( img.complete && img.naturalWidth > 0 ) {
			tintFromImage( img, hero );
			return;
		}
		img.addEventListener(
			'load',
			function () {
				tintFromImage( img, hero );
			},
			{ once: true }
		);
		img.addEventListener( 'error', function () {}, { once: true } );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init, { once: true } );
	} else {
		init();
	}
	// Release pages reached through the persistent player's soft navigation
	// arrive after load; the tint is idempotent, so re-running is safe.
	document.addEventListener( 'mw-page-rendered', init );
} )();
