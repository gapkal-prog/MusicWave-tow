/**
 * Release action bar helpers: system share sheet + clipboard fallback + shuffle.
 *
 * Share: uses navigator.share when available and canShare({url}) passes; otherwise
 * copies the canonical link (navigator.clipboard → legacy execCommand fallback).
 * Shuffle: randomizes the resolved playback queue before handing it to the global player.
 *
 * Accessible, RTL-safe, progressive enhancement: buttons are server-rendered.
 */

( function () {
	'use strict';

	if ( window._mwReleaseActionsInitialized ) {
		return;
	}
	window._mwReleaseActionsInitialized = true;

	function copiedLabel() {
		var settings = window.musicWaveReleaseActions || {};
		return settings.copied || 'پیوند کپی شد';
	}

	function copyFailedLabel() {
		var settings = window.musicWaveReleaseActions || {};
		return settings.copyFailed || 'کپی انجام نشد.';
	}

	function announce( button, message ) {
		var status = button.parentElement
			? button.parentElement.querySelector( '[data-mw-share-status]' )
			: null;
		if ( ! status ) {
			return;
		}
		status.textContent = message || '';
		status.hidden = ! message;
		if ( message ) {
			window.setTimeout( function () {
				if ( status.textContent === message ) {
					status.textContent = '';
					status.hidden = true;
				}
			}, 3200 );
		}
	}

	function copyTextFallback( text ) {
		try {
			var helper = document.createElement( 'textarea' );
			helper.value = text;
			helper.setAttribute( 'readonly', '' );
			helper.style.position = 'fixed';
			helper.style.opacity = '0';
			helper.style.pointerEvents = 'none';
			document.body.appendChild( helper );
			helper.select();
			helper.setSelectionRange( 0, helper.value.length );
			var ok = document.execCommand( 'copy' );
			helper.remove();
			return ok;
		} catch ( e ) {
			return false;
		}
	}

	function copyText( text ) {
		if (
			navigator.clipboard &&
			typeof navigator.clipboard.writeText === 'function'
		) {
			return navigator.clipboard.writeText( text ).then(
				function () {
					return true;
				},
				function () {
					return copyTextFallback( text );
				}
			);
		}
		return Promise.resolve( copyTextFallback( text ) );
	}

	function handleShareClick( event ) {
		var button = event.target.closest( '[data-mw-share-button]' );
		if ( ! button ) {
			return;
		}
		event.preventDefault();
		var url =
			button.getAttribute( 'data-mw-share-url' ) || window.location.href;
		var title =
			button.getAttribute( 'data-mw-share-title' ) || document.title;
		var text = button.getAttribute( 'data-mw-share-text' ) || title;

		if (
			navigator.share &&
			typeof navigator.canShare === 'function' &&
			! navigator.canShare( { url } )
		) {
			// canShare explicitly says no — skip to clipboard rather than throwing.
			copyText( url ).then( function ( ok ) {
				announce( button, ok ? copiedLabel() : copyFailedLabel() );
			} );
			return;
		}

		if ( navigator.share ) {
			navigator.share( { title, text, url } ).then(
				function () {},
				function ( error ) {
					if ( error && error.name === 'AbortError' ) {
						return;
					}
					copyText( url ).then( function ( ok ) {
						announce(
							button,
							ok ? copiedLabel() : copyFailedLabel()
						);
					} );
				}
			);
			return;
		}

		copyText( url ).then( function ( ok ) {
			announce( button, ok ? copiedLabel() : copyFailedLabel() );
		} );
	}

	function handleShuffleClick( event ) {
		var button = event.target.closest( '[data-mw-shuffle-button]' );
		if ( ! button ) {
			return;
		}
		event.preventDefault();
		var releaseId =
			parseInt( button.getAttribute( 'data-mw-release-id' ), 10 ) || 0;
		if ( releaseId < 1 ) {
			return;
		}

		var controller = window._mwPreviewController;
		if ( button.getAttribute( 'aria-busy' ) === 'true' ) {
			return;
		}
		if ( ! controller || typeof controller.playShuffled !== 'function' ) {
			// The global player script has not initialized (still loading or
			// blocked); do nothing rather than guessing an async race.
			return;
		}

		button.setAttribute( 'aria-busy', 'true' );
		button.classList.add( 'is-loading' );

		// The controller resolves the playback queue via REST and — unlike a
		// plain playRelease — starts from a fresh random track each call,
		// then keeps playing the queue sequentially after that.
		var release = function () {
			button.removeAttribute( 'aria-busy' );
			button.classList.remove( 'is-loading' );
		};
		try {
			controller.playShuffled( button, releaseId );
		} catch ( e ) {
			release();
			return;
		}
		// Loading state until the queue fetch settles or playback starts;
		// both paths in the player flip the buttons back via the same DOM
		// classes, so a bounded timer is only the safety net.
		window.setTimeout( release, 6000 );
	}

	document.addEventListener( 'click', handleShareClick );
	document.addEventListener( 'click', handleShuffleClick );
} )();
