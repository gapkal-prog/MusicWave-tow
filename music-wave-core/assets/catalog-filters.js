/**
 * Instant catalog filtering for the MusicWave release archive.
 *
 * Progressive enhancement only: the catalog filter form remains a standard
 * GET form for visitors without JavaScript. When supported, submissions,
 * active-filter chips, and pagination links load results in place without a
 * full page refresh, keeping the URL shareable via the History API.
 */
( function () {
	'use strict';

	if ( window._mwCatalogFiltersInitialized ) {
		return;
	}
	if (
		! window.fetch ||
		! window.DOMParser ||
		! window.history ||
		! history.pushState
	) {
		return;
	}
	window._mwCatalogFiltersInitialized = true;

	function getConfig() {
		return window.musicWaveCatalogFilters || {};
	}

	var REGION_SELECTORS = [
		'form.mw-catalog-filters',
		'.mw-catalog-results',
		'.wp-block-query',
	];
	var inFlight = null;

	function hasCatalogSurface() {
		return Boolean(
			document.querySelector( 'form.mw-catalog-filters' ) &&
				document.querySelector( '.wp-block-query' )
		);
	}

	function mergeHistoryState( extra ) {
		var next = {};
		if ( history.state && typeof history.state === 'object' ) {
			Object.keys( history.state ).forEach( function ( key ) {
				next[ key ] = history.state[ key ];
			} );
		}
		Object.keys( extra ).forEach( function ( key ) {
			next[ key ] = extra[ key ];
		} );
		return next;
	}

	function setLoading( loading ) {
		var form = document.querySelector( 'form.mw-catalog-filters' );
		if ( ! form ) {
			return;
		}
		var config = getConfig();
		var button = form.querySelector( 'button[type="submit"]' );
		var status = form.querySelector( '[data-mw-filter-status]' );
		if ( loading ) {
			form.setAttribute( 'data-mw-busy', 'true' );
			form.setAttribute( 'aria-busy', 'true' );
			if ( button ) {
				button.setAttribute(
					'data-mw-original-label',
					button.textContent
				);
				button.textContent = config.applying || button.textContent;
				button.disabled = true;
			}
			if ( status ) {
				status.textContent = config.applying || '';
			}
			return;
		}
		form.removeAttribute( 'data-mw-busy' );
		form.removeAttribute( 'aria-busy' );
		if ( button ) {
			var original = button.getAttribute( 'data-mw-original-label' );
			if ( original ) {
				button.textContent = original;
			}
			button.disabled = false;
		}
		if ( status ) {
			status.textContent = '';
		}
	}

	/**
	 * Build the GET URL exactly like a native form submission would, minus
	 * the empty controls and the hidden post type context input.
	 */
	function buildSubmitUrl( form ) {
		var data = new window.FormData( form );
		var params = new window.URLSearchParams();
		data.forEach( function ( value, key ) {
			if ( 'post_type' === key || '' === value ) {
				return;
			}
			params.append( key, value );
		} );
		var actionAttr = form.getAttribute ? form.getAttribute( 'action' ) : '';
		var base = String( actionAttr || window.location.href ).split(
			/[?#]/
		)[ 0 ];
		var query = params.toString();
		return query ? base + '?' + query : base;
	}

	/**
	 * Replace the catalog regions with their counterparts from a parsed page.
	 */
	function swapRegions( doc ) {
		var swapped = false;
		REGION_SELECTORS.forEach( function ( selector ) {
			var current = document.querySelector( selector );
			var next = doc.querySelector( selector );
			if ( ! current || ! next || ! current.parentNode ) {
				return;
			}
			current.parentNode.replaceChild( next, current );
			swapped = true;
		} );
		return swapped;
	}

	function loadCatalogUrl( url, push ) {
		if ( inFlight ) {
			inFlight.abort();
		}
		var controller = window.AbortController
			? new window.AbortController()
			: null;
		inFlight = controller;
		setLoading( true );

		return window
			.fetch( url, {
				credentials: 'same-origin',
				headers: { Accept: 'text/html' },
				signal: controller ? controller.signal : undefined,
			} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'HTTP ' + response.status );
				}
				return response.text();
			} )
			.then( function ( html ) {
				var doc = new window.DOMParser().parseFromString(
					html,
					'text/html'
				);
				if ( ! swapRegions( doc ) ) {
					window.location.assign( url );
					return;
				}
				if ( doc.title ) {
					document.title = doc.title;
				}
				if ( push ) {
					history.pushState(
						mergeHistoryState( { mwCatalog: true } ),
						'',
						url
					);
				}
				// Swapped markup is unenhanced: let re-entrant modules such as
				// the catalog suggest combobox rebind, mirroring the event the
				// persistent player dispatches after its own soft navigations.
				document.dispatchEvent(
					new window.CustomEvent( 'mw-page-rendered', {
						detail: { url },
					} )
				);
			} )
			.catch( function ( error ) {
				if ( error && 'AbortError' === error.name ) {
					return;
				}
				// Network or parse failure: degrade to a regular navigation.
				window.location.assign( url );
			} )
			.then( function () {
				if ( inFlight === controller ) {
					inFlight = null;
				}
				setLoading( false );
			} );
	}

	// Delegated submit listener survives region swaps replacing the form.
	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		if (
			! form ||
			! form.matches ||
			! form.matches( 'form.mw-catalog-filters' ) ||
			! hasCatalogSurface()
		) {
			return;
		}
		event.preventDefault();
		loadCatalogUrl( buildSubmitUrl( form ), true );
	} );

	// Active filter chips, reset buttons, and archive pagination load in place;
	// release/artist/genre cards inside `.mw-catalog-results` flow directly to `MusicWaveApp.router`.
	document.addEventListener( 'click', function ( event ) {
		if ( ! hasCatalogSurface() ) {
			return;
		}
		var link =
			event.target && event.target.closest
				? event.target.closest(
						'.mw-catalog-active-filters a, .mw-catalog-results__reset, .mw-catalog-filters a, .wp-block-query-pagination a'
				  )
				: null;
		if ( ! link ) {
			return;
		}
		var href = link.getAttribute( 'href' );
		if ( ! href || '#' === href.charAt( 0 ) ) {
			return;
		}
		event.preventDefault();
		loadCatalogUrl(
			new window.URL( href, window.location.href ).href,
			true
		);
	} );

	window.addEventListener( 'popstate', function ( event ) {
		if (
			! event.state ||
			! event.state.mwCatalog ||
			! hasCatalogSurface()
		) {
			return;
		}
		loadCatalogUrl( window.location.href, false );
	} );

	function cancelInFlight() {
		if ( inFlight ) {
			inFlight.abort();
			inFlight = null;
		}
		setLoading( false );
	}

	function markCatalogRoute() {
		if ( hasCatalogSurface() ) {
			history.replaceState(
				mergeHistoryState( { mwCatalog: true } ),
				''
			);
		}
	}

	var catalogFiltersController = {
		mount: markCatalogRoute,
		unmount: cancelInFlight,
	};

	if (
		window.MusicWaveApp &&
		'function' === typeof window.MusicWaveApp.registerController
	) {
		window.MusicWaveApp.registerController(
			'catalog-filters',
			catalogFiltersController
		);
	} else {
		window.MusicWaveAppQueue = window.MusicWaveAppQueue || [];
		window.MusicWaveAppQueue.push( {
			id: 'catalog-filters',
			definition: catalogFiltersController,
		} );
	}

	document.addEventListener( 'mw-page-rendered', markCatalogRoute );
	markCatalogRoute();
} )();
