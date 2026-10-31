/**
 * Single release description — progressive "show more" disclosure.
 *
 * Long editorial copy is clamped with a soft gradient and a single toggle that
 * reveals the rest. The control only appears when the clamp actually hides
 * content (measured, not guessed from a pixel threshold), and with JavaScript
 * off every word stays visible because the clamp class is only added here.
 *
 * Re-runs on `mw-page-rendered` for the persistent player's soft navigation,
 * and re-measures once webfonts and images settle so the control never
 * appears (or disappears) because of a late layout shift.
 *
 * @package
 */
( function () {
	'use strict';

	if ( window._mwReleaseContentInitialized ) {
		return;
	}
	window._mwReleaseContentInitialized = true;

	var SELECTOR =
		'.mw-release-content, .mw-release-page .wp-block-post-content';
	var COLLAPSED = 'mw-release-content--clamped';
	// A few pixels of rounding are not worth a control.
	var MIN_OVERFLOW = 24;
	var counter = 0;

	function labels() {
		return window.musicwaveReleaseContent || {};
	}

	function setup( root ) {
		if (
			! root ||
			root.getAttribute( 'data-mw-release-content' ) === 'ready'
		) {
			return;
		}

		// Clamp first, then keep the control only when content is really hidden.
		root.classList.add( COLLAPSED );
		var hiddenPixels = root.scrollHeight - root.clientHeight;
		if ( hiddenPixels <= MIN_OVERFLOW ) {
			root.classList.remove( COLLAPSED );
			// Left unmarked, so a late font or image load can re-measure.
			return;
		}

		root.setAttribute( 'data-mw-release-content', 'ready' );

		if ( ! root.id ) {
			root.id = 'mw-release-content-' + ( counter += 1 );
		}

		var config = labels();
		var moreLabel = config.more || 'نمایش بیشتر محتوا';
		var lessLabel = config.less || 'نمایش کمتر';

		var toggle = document.createElement( 'button' );
		toggle.type = 'button';
		toggle.className = 'mw-release-content__toggle';
		toggle.setAttribute( 'aria-expanded', 'false' );
		toggle.setAttribute( 'aria-controls', root.id );
		toggle.textContent = moreLabel;

		toggle.addEventListener( 'click', function () {
			var expanded = ! root.classList.contains( 'is-expanded' );
			root.classList.toggle( 'is-expanded', expanded );
			root.classList.toggle( COLLAPSED, ! expanded );
			toggle.setAttribute( 'aria-expanded', expanded ? 'true' : 'false' );
			toggle.textContent = expanded ? lessLabel : moreLabel;
			if ( ! expanded ) {
				// Collapsing can leave the button above the viewport.
				toggle.scrollIntoView( { block: 'nearest' } );
			}
		} );

		root.insertAdjacentElement( 'afterend', toggle );
	}

	function init() {
		document.querySelectorAll( SELECTOR ).forEach( setup );
	}

	function schedule() {
		init();
		if ( document.fonts && document.fonts.ready ) {
			document.fonts.ready.then( init );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', schedule, {
			once: true,
		} );
	} else {
		schedule();
	}
	window.addEventListener( 'load', init );
	document.addEventListener( 'mw-page-rendered', init );
} )();
