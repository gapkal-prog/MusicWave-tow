/**
 * Personal music library controller: toggle buttons and removal actions.
 * Refactored: icon-only heart with fill animation, robust state sync.
 *
 * @package
 */
( function () {
	'use strict';

	if ( window._mwLibraryInitialized ) {
		return;
	}
	window._mwLibraryInitialized = true;

	function settings() {
		return window.musicWaveLibrary || window.mwLibrary || {};
	}

	function request( method, body ) {
		if ( ! window.wp || ! window.wp.apiFetch ) {
			return Promise.reject( new Error( 'wp.apiFetch unavailable' ) );
		}
		var config = settings();
		var nonce = config.restNonce || config.nonce || '';
		var options = {
			path: '/music-wave/v1/library/items',
			method,
			headers: nonce ? { 'X-WP-Nonce': nonce } : {},
		};
		if ( body ) {
			options.data = body;
		}

		return window.wp.apiFetch( options );
	}

	function errorMessage( error ) {
		var config = settings();
		if (
			error &&
			( error.status === 401 ||
				error.code === 'mw_authentication_required' )
		) {
			return config.sessionError || config.errorMessage;
		}

		return error && error.message ? error.message : config.errorMessage;
	}

	function formatCount( value ) {
		if ( window.wp && window.wp.i18n && window.wp.i18n.numberFormat ) {
			return window.wp.i18n.numberFormat( value );
		}

		return String( value );
	}

	function syncLibraryCount( button, wasInLibrary, inLibrary ) {
		var count = button.querySelector( '.mw-library-button__count' );
		if ( ! count || wasInLibrary === inLibrary ) {
			return;
		}

		var current = parseInt(
			count.getAttribute( 'data-mw-library-count-value' ),
			10
		);
		var next = Math.max(
			0,
			( isNaN( current ) ? 0 : current ) + ( inLibrary ? 1 : -1 )
		);
		count.setAttribute( 'data-mw-library-count-value', String( next ) );
		count.textContent = formatCount( next );
		count.hidden = next < 1;
	}

	function setButtonState( button, inLibrary ) {
		var label = button.querySelector( '.mw-library-button__label' );
		var wasInLibrary =
			button.getAttribute( 'data-mw-library-state' ) === 'in';
		var isHeart =
			button.classList.contains( 'mw-library-button--heart' ) ||
			button.classList.contains( 'mw-library-button--icon-only' );
		var next = inLibrary
			? button.getAttribute( 'data-mw-library-label-added' )
			: button.getAttribute( 'data-mw-library-label-add' );

		button.setAttribute(
			'data-mw-library-state',
			inLibrary ? 'in' : 'out'
		);
		button.setAttribute( 'aria-pressed', inLibrary ? 'true' : 'false' );
		button.classList.toggle( 'is-saved', inLibrary );
		syncLibraryCount( button, wasInLibrary, inLibrary );
		if ( next ) {
			button.setAttribute( 'aria-label', next );
			if ( label ) {
				label.textContent = next;
			}
		}
		// Icon glyphs are never rewritten: the server-rendered SVG stays intact
		// and the saved/unsaved visuals come from the state classes below, so
		// `heart` discs, `button` pills, and `follow` icons stay identical
		// between the initial render and every later toggle.
		if ( isHeart ) {
			if ( inLibrary ) {
				// Restart pop + fill celebration, YouTube/Spotify style.
				button.classList.remove( 'is-pop' );
				// Force reflow to restart animation.
				void button.offsetWidth;
				button.classList.add( 'is-pop' );
				window.setTimeout( function () {
					button.classList.remove( 'is-pop' );
				}, 500 );
			} else {
				button.classList.remove( 'is-pop' );
			}
		}
	}

	function syncButtons( type, id, inLibrary ) {
		document
			.querySelectorAll(
				'.mw-library-button[data-mw-library-id="' +
					id +
					'"][data-mw-library-type="' +
					type +
					'"]'
			)
			.forEach( function ( button ) {
				setButtonState( button, inLibrary );
			} );
	}

	function updateCounts( counts ) {
		if ( ! counts || typeof counts !== 'object' ) {
			return;
		}

		Object.keys( counts ).forEach( function ( key ) {
			document
				.querySelectorAll( '[data-mw-library-count="' + key + '"]' )
				.forEach( function ( element ) {
					element.textContent = String( counts[ key ] );
				} );
		} );
	}

	function showStatus( button, message ) {
		var container =
			button.closest( '.mw-release-meta__actions' ) ||
			button.closest( '.mw-artist-profile__actions' ) ||
			button.closest( '[data-mw-library-item]' ) ||
			button.parentNode;
		var status = container
			? container.querySelector( '.mw-library-button__status' )
			: null;
		if ( ! status && container ) {
			status = document.createElement( 'p' );
			status.className = 'mw-library-button__status';
			status.setAttribute( 'role', 'status' );
			status.setAttribute( 'aria-live', 'polite' );
			container.appendChild( status );
		}
		if ( status ) {
			status.textContent = message;
			window.setTimeout( function () {
				status.textContent = '';
			}, 4000 );
		}
	}

	function removeItemCard( type, id ) {
		var card = document.querySelector(
			'[data-mw-library-item="' + type + '-' + id + '"]'
		);
		if ( ! card ) {
			return;
		}

		card.classList.add( 'is-removing' );
		window.setTimeout( function () {
			card.remove();
			var list = document.querySelector( '[data-mw-library-items]' );
			if ( list && ! list.querySelector( '[data-mw-library-item]' ) ) {
				var empty = document.querySelector( '[data-mw-library-empty]' );
				if ( empty ) {
					empty.hidden = false;
				}
				list.remove();
			}
		}, 180 );
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest(
			'.mw-library-button[data-mw-library-id]'
		);
		if ( ! button || button.disabled ) {
			return;
		}
		event.preventDefault();

		var type = button.getAttribute( 'data-mw-library-type' );
		var id = parseInt( button.getAttribute( 'data-mw-library-id' ), 10 );
		if ( ! type || ! id ) {
			return;
		}

		var inLibrary = button.getAttribute( 'data-mw-library-state' ) === 'in';
		button.disabled = true;
		// Optimistic UI for heart: immediate fill for responsiveness, revert on error.
		if (
			button.classList.contains( 'mw-library-button--heart' ) &&
			! inLibrary
		) {
			setButtonState( button, true );
		}

		request( inLibrary ? 'DELETE' : 'POST', { type, id } )
			.then( function ( response ) {
				var next = ! inLibrary;
				if ( response && typeof response.state === 'string' ) {
					next = response.state === 'in';
				}
				syncButtons( type, id, next );
				if ( response && response.counts ) {
					updateCounts( response.counts );
				}
			} )
			.catch( function ( error ) {
				// Revert optimistic if failed.
				syncButtons( type, id, inLibrary );
				showStatus( button, errorMessage( error ) );
			} )
			.finally( function () {
				button.disabled = false;
			} );
	} );

	document.addEventListener( 'click', function ( event ) {
		var removeButton = event.target.closest( '.mw-library-remove' );
		if ( ! removeButton || removeButton.disabled ) {
			return;
		}
		event.preventDefault();

		var type = removeButton.getAttribute( 'data-mw-library-type' );
		var id = parseInt(
			removeButton.getAttribute( 'data-mw-library-id' ),
			10
		);
		if ( ! type || ! id ) {
			return;
		}

		removeButton.disabled = true;
		request( 'DELETE', { type, id } )
			.then( function ( response ) {
				removeItemCard( type, id );
				syncButtons( type, id, false );
				if ( response && response.counts ) {
					updateCounts( response.counts );
				}
			} )
			.catch( function ( error ) {
				showStatus( removeButton, errorMessage( error ) );
			} )
			.finally( function () {
				var card = document.querySelector(
					'[data-mw-library-item="' + type + '-' + id + '"]'
				);
				if ( ! card ) {
					removeButton.disabled = false;
				}
			} );
	} );

	/* ------------------------------------------------------------------ *
	 * In-place filter tabs & pagination
	 * ------------------------------------------------------------------ */

	var panelBusyClass = 'is-busy';
	var swapSupported = Boolean(
		window.fetch &&
			window.DOMParser &&
			window.history &&
			window.history.pushState
	);

	function libraryPanel() {
		return document.querySelector( '[data-mw-library-panel]' );
	}

	function setPanelBusy( panel, busy ) {
		if ( ! panel ) {
			return;
		}
		panel.classList.toggle( panelBusyClass, busy );
		panel.setAttribute( 'aria-busy', busy ? 'true' : 'false' );
	}

	function syncPlayerButtons() {
		if ( window._mwPreviewController && window._mwPreviewController.sync ) {
			window._mwPreviewController.sync();
		}
	}

	var activeSwap = null;

	function renderPanel( freshPanel, url ) {
		var panel = libraryPanel();
		if ( ! panel || ! freshPanel ) {
			return false;
		}
		panel.replaceChildren.apply(
			panel,
			Array.prototype.slice.call( freshPanel.childNodes )
		);
		panel.classList.remove( panelBusyClass );
		panel.removeAttribute( 'aria-busy' );
		syncPlayerButtons();
		document.dispatchEvent(
			new window.CustomEvent( 'mw-library-rendered', {
				detail: { url },
			} )
		);

		return true;
	}

	function fetchPanel( url, push ) {
		if ( ! swapSupported ) {
			window.location.assign( url );

			return;
		}
		if ( activeSwap ) {
			activeSwap.abort();
		}
		var controller = new window.AbortController();
		activeSwap = controller;
		setPanelBusy( libraryPanel(), true );

		window
			.fetch( url, {
				credentials: 'same-origin',
				headers: { 'X-MW-Library-Nav': '1' },
				signal: controller.signal,
			} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'پیمایش کتابخانه ناموفق بود.' );
				}

				return response.text();
			} )
			.then( function ( html ) {
				if ( controller !== activeSwap ) {
					return;
				}
				var fresh = new window.DOMParser().parseFromString(
					html,
					'text/html'
				);
				if (
					! renderPanel(
						fresh.querySelector( '[data-mw-library-panel]' ),
						url
					)
				) {
					window.location.assign( url );

					return;
				}
				if ( push ) {
					window.history.pushState( { mwLibraryNav: true }, '', url );
				}
				panelSwapped = true;
				var panel = libraryPanel();
				var tabs =
					panel && panel.querySelector( '.mw-music-library__tabs' );
				if ( tabs && tabs.getBoundingClientRect().top < 0 ) {
					tabs.scrollIntoView( {
						behavior: 'smooth',
						block: 'start',
					} );
				}
			} )
			.catch( function ( error ) {
				if ( error && error.name === 'AbortError' ) {
					return;
				}
				window.location.assign( url );
			} )
			.finally( function () {
				if ( controller === activeSwap ) {
					activeSwap = null;
					setPanelBusy( libraryPanel(), false );
				}
			} );
	}

	document.addEventListener( 'click', function ( event ) {
		if (
			! swapSupported ||
			event.defaultPrevented ||
			event.button !== 0 ||
			event.metaKey ||
			event.ctrlKey ||
			event.shiftKey ||
			event.altKey
		) {
			return;
		}
		var link = event.target.closest(
			'[data-mw-library-panel] .mw-music-library__tabs a, [data-mw-library-panel] .mw-music-library__pagination a'
		);
		if ( ! link ) {
			return;
		}
		event.preventDefault();
		fetchPanel( link.href, true );
	} );

	var panelSwapped = false;
	window.addEventListener( 'popstate', function () {
		if ( ! libraryPanel() || ! swapSupported ) {
			return;
		}
		var owned = window.history.state && window.history.state.mwLibraryNav;
		if ( owned || panelSwapped ) {
			panelSwapped = true;
			fetchPanel( window.location.href, false );
		}
	} );
} )();
