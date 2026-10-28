/**
 * Stream rail + universal mobile chrome: collapse/expand, drawer, tabbar, current-item, search shortcut.
 *
 * Upgraded to work for ALL headers (simple, centered, minimal, stream):
 * - Rail collapse only applies when .mw-rail exists (stream).
 * - Mobile chrome (drawer/tabs/both) now works for every header because tabbar
 *   exists in all bundled parts and drawer fallback uses core navigation overlay
 *   styled as side drawer.
 * - Current-item marking covers rail, tabbar, and primary nav in simple headers.
 * - Search shortcut expands both legacy core/search and new header-search block.
 */
( function () {
	'use strict';

	var storageKey = 'musicwave-rail';
	var attribute = 'data-mw-rail';
	var readyAttribute = 'data-mw-rail-ready';
	var states = [ 'expanded', 'collapsed' ];
	var html = document.documentElement;

	function labels() {
		return ( window.musicwaveRail && window.musicwaveRail.labels ) || {};
	}
	function savedState() {
		try {
			var s = window.localStorage.getItem( storageKey );
			return states.indexOf( s ) !== -1 ? s : 'expanded';
		} catch ( e ) {
			return 'expanded';
		}
	}
	function storeState( s ) {
		try {
			window.localStorage.setItem( storageKey, s );
		} catch ( e ) {}
	}
	function currentState() {
		var s = html.getAttribute( attribute );
		return states.indexOf( s ) !== -1 ? s : savedState();
	}
	function isNarrow() {
		return window.matchMedia( '(max-width: 63.99rem)' ).matches;
	}
	function isDrawerMobile() {
		return isNarrow();
	}
	function drawerOpen() {
		return '1' === html.getAttribute( 'data-mw-rail-open' );
	}
	function allButtons() {
		return Array.prototype.slice.call(
			document.querySelectorAll( '.mw-rail__toggle' )
		);
	}

	/*
	 * The toggle is the `music-wave/rail-toggle` block, rendered by PHP and
	 * editable (or removable) in the Site Editor. Never synthesise one here:
	 * a JS-only duplicate both contradicted a removed block and left a button
	 * that had no block settings behind it.
	 */
	function bindButtons() {
		allButtons().forEach( function ( button ) {
			if ( button.getAttribute( 'data-mw-rail-bound' ) ) {
				return;
			}
			button.setAttribute( 'data-mw-rail-bound', '1' );
			button.addEventListener( 'click', onToggleClick );
		} );
	}

	function applyState( state ) {
		html.setAttribute( attribute, state );
		Array.prototype.forEach.call(
			document.querySelectorAll( '.mw-rail [data-mw-label]' ),
			function ( link ) {
				if ( 'collapsed' === state ) {
					link.setAttribute(
						'title',
						link.getAttribute( 'data-mw-label' )
					);
				} else {
					link.removeAttribute( 'title' );
				}
			}
		);
	}

	function setButton( button, expanded ) {
		var text = expanded
			? labels().collapse || 'Collapse menu'
			: labels().expand || 'Expand menu';
		button.setAttribute( 'aria-expanded', expanded ? 'true' : 'false' );
		button.setAttribute( 'aria-label', text );
		button.setAttribute( 'title', text );
	}

	function setDrawerOpen( open ) {
		html.setAttribute( 'data-mw-rail-open', open ? '1' : '0' );
		if ( document.body ) {
			document.body.style.overflow =
				open && isDrawerMobile() ? 'hidden' : '';
		}
		allButtons().forEach( function ( b ) {
			setButton( b, open );
		} );
		if ( open ) {
			var first = document.querySelector(
				'.mw-rail .wp-block-navigation-item__content, .mw-site-header .mw-rail__tabbar .wp-block-navigation-item__content'
			);
			if ( first ) {
				first.focus( { preventScroll: true } );
			}
		}
	}

	function syncButtons( state ) {
		if ( isDrawerMobile() ) {
			setDrawerOpen( drawerOpen() );
			return;
		}
		allButtons().forEach( function ( b ) {
			setButton( b, 'collapsed' !== state );
		} );
	}

	function onToggleClick( event ) {
		event.preventDefault();
		if ( isDrawerMobile() ) {
			setDrawerOpen( ! drawerOpen() );
			return;
		}
		var next = 'collapsed' === currentState() ? 'expanded' : 'collapsed';
		storeState( next );
		applyState( next );
		syncButtons( next );
	}

	/* Highlight custom destinations core cannot resolve (e.g. /account/...). */
	function markCurrent( root ) {
		var here = window.location.pathname.replace( /\/+$/, '' ) || '/';
		var scope = [];

		// Rails
		document.querySelectorAll( '.mw-rail' ).forEach( function ( r ) {
			scope.push( r );
		} );
		// Tabbars (stream + simple)
		document
			.querySelectorAll( '.mw-rail__tabbar' )
			.forEach( function ( t ) {
				if ( scope.indexOf( t ) === -1 ) {
					scope.push( t );
				}
			} );
		// Simple headers primary nav (when no rail)
		document
			.querySelectorAll(
				'.mw-site-header--simple .mw-site-nav, .mw-site-header--centered .mw-site-nav, .mw-site-header--minimal .mw-site-nav'
			)
			.forEach( function ( n ) {
				// Avoid duplicating if already in scope via rail
				if ( scope.indexOf( n ) === -1 ) {
					scope.push( n );
				}
			} );

		// If a specific root is given (enhance path), ensure it's included
		if ( root && scope.indexOf( root ) === -1 ) {
			scope.push( root );
		}

		scope.forEach( function ( node ) {
			if ( ! node ) {
				return;
			}
			Array.prototype.forEach.call(
				node.querySelectorAll( '.wp-block-navigation-item__content' ),
				function ( link ) {
					var item = link.closest( '.wp-block-navigation-item' );
					if ( ! item ) {
						return;
					}
					if ( item.getAttribute( 'data-mw-current' ) ) {
						item.removeAttribute( 'data-mw-current' );
						item.classList.remove( 'current-menu-item' );
						link.removeAttribute( 'aria-current' );
					}
					if ( item.classList.contains( 'current-menu-item' ) ) {
						return;
					}
					var url;
					try {
						url = new URL(
							link.getAttribute( 'href' ),
							window.location.href
						);
					} catch ( e ) {
						return;
					}
					if ( url.origin !== window.location.origin ) {
						return;
					}
					var target = url.pathname.replace( /\/+$/, '' ) || '/';
					var hereType = new URLSearchParams(
						window.location.search
					).get( 'post_type' );
					var matches =
						( url.searchParams.has( 'post_type' ) &&
							url.searchParams.get( 'post_type' ) ===
								hereType ) ||
						( ! url.search &&
							'/' !== target &&
							( here === target ||
								0 === here.indexOf( target + '/' ) ) );
					if ( matches ) {
						item.setAttribute( 'data-mw-current', '1' );
						item.classList.add( 'current-menu-item' );
						link.setAttribute( 'aria-current', 'page' );
					}
				}
			);
		} );

		// Only most specific match stays active per rail/tabbar
		scope.forEach( function ( node ) {
			var current = node.querySelectorAll( '[data-mw-current]' );
			if ( current.length > 1 ) {
				var best = null,
					bestLen = -1;
				Array.prototype.forEach.call( current, function ( item ) {
					var len = (
						item.querySelector( 'a' ).getAttribute( 'href' ) || ''
					).length;
					if ( len > bestLen ) {
						best = item;
						bestLen = len;
					}
				} );
				Array.prototype.forEach.call( current, function ( item ) {
					if ( item !== best ) {
						item.removeAttribute( 'data-mw-current' );
						item.classList.remove( 'current-menu-item' );
						var a = item.querySelector( 'a' );
						if ( a ) {
							a.removeAttribute( 'aria-current' );
						}
					}
				} );
			}
		} );
	}

	function enhance( rail ) {
		if ( rail.getAttribute( readyAttribute ) ) {
			markCurrent( rail );
			return;
		}
		rail.setAttribute( readyAttribute, '1' );

		Array.prototype.forEach.call(
			rail.querySelectorAll( '.wp-block-navigation-item__content' ),
			function ( link ) {
				var labelNode = link.querySelector(
					'.wp-block-navigation-item__label'
				);
				var label = ( ( labelNode || link ).textContent || '' ).trim();
				if ( label ) {
					link.setAttribute( 'data-mw-label', label );
				}
			}
		);
		markCurrent( rail );

		bindButtons();

		if (
			rail.classList.contains( 'mw-rail' ) &&
			rail.parentNode &&
			! document.querySelector( '.mw-rail__backdrop' )
		) {
			var backdrop = document.createElement( 'div' );
			backdrop.className = 'mw-rail__backdrop';
			backdrop.addEventListener( 'click', function () {
				setDrawerOpen( false );
			} );
			rail.parentNode.insertBefore( backdrop, rail );
		}

		rail.addEventListener( 'click', function ( e ) {
			if ( isDrawerMobile() && e.target.closest( 'a' ) ) {
				setDrawerOpen( false );
			}
		} );
	}

	function initializeAll() {
		// Enhance rails (stream)
		var rails = document.querySelectorAll( '.mw-rail' );
		Array.prototype.forEach.call( rails, enhance );

		// Enhance tabbars even when no rail (simple headers)
		var tabbars = document.querySelectorAll( '.mw-rail__tabbar' );
		Array.prototype.forEach.call( tabbars, function ( tb ) {
			if ( ! tb.getAttribute( readyAttribute ) ) {
				tb.setAttribute( readyAttribute, '1' );
				markCurrent( tb );
				tb.addEventListener( 'click', function ( e ) {
					if ( e.target.closest( 'a' ) && isDrawerMobile() ) {
						// Close any rail drawer when tabbar link clicked
						setDrawerOpen( false );
					}
				} );
			} else {
				markCurrent( tb );
			}
		} );

		// If no rail at all (simple headers only), still need button binding for mobile chrome?
		if ( ! rails.length ) {
			bindButtons();
		}

		applyState( savedState() );
		syncButtons( currentState() );
		if ( ! isDrawerMobile() ) {
			setDrawerOpen( false );
		}

		// Mark simple navs
		markCurrent();
	}

	window.addEventListener( 'storage', function ( e ) {
		if ( e.key === storageKey || null === e.key ) {
			var s = savedState();
			applyState( s );
			syncButtons( s );
		}
	} );

	// Ctrl/⌘ + K or "/" opens search – works for both legacy and new block
	function expandCollapsedSearch() {
		// New block popup: open it
		var newBlock = document.querySelector(
			'.mw-header-search-block--popup'
		);
		if ( newBlock ) {
			var toggle = newBlock.querySelector(
				'.mw-header-search-block__toggle'
			);
			var popup = newBlock.querySelector(
				'.mw-header-search-block__popup'
			);
			if ( toggle && popup && popup.hidden ) {
				toggle.click();
				return;
			}
		}
		// Legacy core/search button-only
		var field = document.querySelector(
			'.mw-stream-search .wp-block-search__input, .mw-header-search .wp-block-search__input, .mw-header-search-block input[type="search"]'
		);
		if ( ! field ) {
			return;
		}
		var wrapper = field.closest( '.wp-block-search__inside-wrapper' );
		if (
			wrapper &&
			wrapper.classList.contains( 'wp-block-search__searchfield-hidden' )
		) {
			wrapper.classList.remove( 'wp-block-search__searchfield-hidden' );
			var btn = wrapper.querySelector( '.wp-block-search__button' );
			if ( btn ) {
				btn.setAttribute( 'aria-expanded', 'true' );
			}
		}
		field.focus();
		try {
			field.select();
		} catch ( e ) {}
	}

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key && drawerOpen() ) {
			setDrawerOpen( false );
			return;
		}
		var typing =
			/^(INPUT|TEXTAREA|SELECT)$/.test(
				( e.target && e.target.tagName ) || ''
			) ||
			( e.target && e.target.isContentEditable );
		if (
			( ( e.ctrlKey || e.metaKey ) && 'k' === e.key.toLowerCase() ) ||
			( '/' === e.key && ! typing )
		) {
			e.preventDefault();
			expandCollapsedSearch();
		}
	} );

	window.addEventListener( 'resize', function () {
		if ( ! isDrawerMobile() ) {
			setDrawerOpen( false );
			syncButtons( currentState() );
		}
	} );

	var railController = {
		mount: initializeAll,
		unmount() {
			setDrawerOpen( false );
		},
	};
	if (
		window.MusicWaveApp &&
		'function' === typeof window.MusicWaveApp.registerController
	) {
		window.MusicWaveApp.registerController( 'rail', railController );
	} else {
		window.MusicWaveAppQueue = window.MusicWaveAppQueue || [];
		window.MusicWaveAppQueue.push( {
			id: 'rail',
			definition: railController,
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', initializeAll );
	} else {
		initializeAll();
	}
	document.addEventListener( 'mw-page-rendered', initializeAll );
} )();
