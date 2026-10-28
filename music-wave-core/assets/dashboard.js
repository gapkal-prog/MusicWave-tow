/**
 * Dashboard tab controller: reveals account sections below the quick links.
 *
 * Re-entrant across both full page loads and persistent soft navigations
 * (`mw-page-rendered`), keeping history state intact and always opening the
 * requested tab or defaulting cleanly to the first available tab.
 */
( function () {
	'use strict';

	if ( window._mwDashboardInitialized ) {
		return;
	}
	window._mwDashboardInitialized = true;

	function tabButton( root, key ) {
		return ( root || document ).querySelector(
			'[data-mw-dashboard-tab="' + key + '"]'
		);
	}

	function panelFor( tab ) {
		const key = tab.getAttribute( 'data-mw-dashboard-tab' );
		if ( ! key ) {
			return null;
		}
		const root = tab.closest( '[data-mw-dashboard]' ) || document;

		return root.querySelector( '[data-mw-dashboard-panel="' + key + '"]' );
	}

	function setState( tab, open ) {
		const panel = panelFor( tab );
		if ( ! panel ) {
			return;
		}

		tab.classList.toggle( 'is-active', open );
		tab.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		tab.setAttribute( 'aria-selected', open ? 'true' : 'false' );
		panel.hidden = ! open;
		panel.classList.toggle( 'is-open', open );
	}

	function closeAll( root ) {
		( root || document )
			.querySelectorAll( '[data-mw-dashboard-tab]' )
			.forEach( function ( tab ) {
				setState( tab, false );
			} );
	}

	function updateHash( openKey ) {
		if ( ! window.history || ! window.history.replaceState ) {
			return;
		}

		const url = window.location.href.split( '#' )[ 0 ];
		window.history.replaceState(
			window.history.state,
			'',
			openKey ? url + '#mw-' + openKey : url
		);
	}

	function openOnly( tab, syncHash ) {
		const root = tab.closest( '[data-mw-dashboard]' ) || document;
		const key = tab.getAttribute( 'data-mw-dashboard-tab' );

		closeAll( root );
		setState( tab, true );
		if ( syncHash !== false ) {
			updateHash( key );
		}
	}

	function isStacked( root ) {
		const dashboard =
			root || document.querySelector( '[data-mw-dashboard]' );

		return (
			!! dashboard && dashboard.classList.contains( 'is-style-stacked' )
		);
	}

	function resolveInitialTab( dashboard ) {
		const hash = ( window.location.hash || '' ).replace( /^#mw-/, '' );
		if ( hash && /^[A-Za-z0-9_-]+$/.test( hash ) ) {
			const hashed = tabButton( dashboard, hash );
			if ( hashed ) {
				return hashed;
			}
		}

		try {
			const params = new window.URLSearchParams(
				window.location.search || ''
			);
			const queryTab = params.get( 'mw_tab' ) || '';
			if ( queryTab && /^[A-Za-z0-9_-]+$/.test( queryTab ) ) {
				const queried = tabButton( dashboard, queryTab );
				if ( queried ) {
					return queried;
				}
			}
		} catch ( e ) {}

		const initial = dashboard.getAttribute( 'data-mw-initial-tab' ) || '';
		if ( initial && /^[A-Za-z0-9_-]+$/.test( initial ) ) {
			const configured = tabButton( dashboard, initial );
			if ( configured ) {
				return configured;
			}
		}

		return dashboard.querySelector( '[data-mw-dashboard-tab]' );
	}

	document.addEventListener( 'click', function ( event ) {
		const tab =
			event.target && event.target.closest
				? event.target.closest( '[data-mw-dashboard-tab]' )
				: null;
		if ( ! tab || ( 'BUTTON' !== tab.tagName && 'A' !== tab.tagName ) ) {
			return;
		}
		event.preventDefault();
		const dashboard = tab.closest( '[data-mw-dashboard]' );
		if ( isStacked( dashboard ) ) {
			const panel = panelFor( tab );
			if ( panel && panel.scrollIntoView ) {
				panel.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			}

			return;
		}
		openOnly( tab, true );
	} );

	function enhance() {
		const dashboards = document.querySelectorAll( '[data-mw-dashboard]' );
		if ( ! dashboards.length ) {
			return;
		}

		const pending = [];
		dashboards.forEach( function ( dashboard ) {
			if ( ! dashboard.getAttribute( 'data-mw-enhanced' ) ) {
				dashboard.setAttribute( 'data-mw-enhanced', '1' );
				pending.push( dashboard );
			}
		} );
		if ( ! pending.length ) {
			return;
		}

		if ( pending.length === dashboards.length ) {
			closeAll();
		}

		pending.forEach( function ( dashboard ) {
			if ( dashboard.classList.contains( 'is-style-stacked' ) ) {
				dashboard
					.querySelectorAll( '[data-mw-dashboard-panel]' )
					.forEach( function ( panel ) {
						panel.hidden = false;
						panel.classList.add( 'is-open' );
					} );

				return;
			}

			closeAll( dashboard );
			const targetTab = resolveInitialTab( dashboard );
			if ( targetTab ) {
				setState( targetTab, true );
			}
		} );
	}

	function syncFromUrl() {
		document
			.querySelectorAll( '[data-mw-dashboard]' )
			.forEach( function ( dashboard ) {
				if ( dashboard.classList.contains( 'is-style-stacked' ) ) {
					return;
				}
				const targetTab = resolveInitialTab( dashboard );
				if (
					targetTab &&
					! targetTab.classList.contains( 'is-active' )
				) {
					closeAll( dashboard );
					setState( targetTab, true );
				}
			} );
	}

	var dashboardController = {
		mount() {
			enhance();
			syncFromUrl();
		},
	};

	if (
		window.MusicWaveApp &&
		'function' === typeof window.MusicWaveApp.registerController
	) {
		window.MusicWaveApp.registerController(
			'dashboard',
			dashboardController
		);
	} else {
		window.MusicWaveAppQueue = window.MusicWaveAppQueue || [];
		window.MusicWaveAppQueue.push( {
			id: 'dashboard',
			definition: dashboardController,
		} );
	}

	window.addEventListener( 'hashchange', syncFromUrl );
	window.addEventListener( 'mw:hash-sync', syncFromUrl );

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', enhance );
	} else {
		enhance();
	}
	document.addEventListener( 'mw-page-rendered', enhance );
} )();
