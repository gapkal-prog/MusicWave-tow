/**
 * Unified professional catalog autocomplete — single box, grouped results
 *
 * - Progressive enhancement: without JS plain GET form
 * - With JS: ARIA 1.2 combobox, grouped by type (release, artist, genre, product)
 * - ONE suggestion list per field, no duplicate nbp-suggest boxes
 * - Visible empty state + loading state (not just screen-reader)
 * - Integrated with main system: endpoint music-wave/v1/catalog/suggest (now includes products when WooCommerce active via filter)
 * - Fixes hidden input bug: works even if input was previously aria-hidden
 * - Keyboard: ↑↓ across all groups, Enter to go, Esc to close
 * - Icons: SVG (stroke currentColor) consistent with theme nav-icons, not emoji
 */
( function ( config ) {
	'use strict';

	if ( window._mwCatalogSuggestInitialized ) {
		return;
	}
	if ( ! window.fetch ) {
		return;
	}
	window._mwCatalogSuggestInitialized = true;

	var GLOBAL =
		'function' === typeof window.Proxy
			? new window.Proxy(
					{},
					{
						get( _target, prop ) {
							var active =
								window.musicWaveCatalogSuggest || config || {};
							return active[ prop ];
						},
					}
			  )
			: config || {};
	var MIN_LENGTH = parseInt( GLOBAL.minLength, 10 ) || 2;
	var DEBOUNCE = 220;

	// SVG icon library — 24x24, stroke currentColor, matches musicwave/inc/nav-icons.php style
	var ICON_PATHS = {
		release:
			'<path d="M9 18V5.5l11-2.5v12"/><circle cx="6.5" cy="18" r="2.5"/><circle cx="17.5" cy="15" r="2.5"/>',
		track: '<path d="M9 18V5.5l11-2.5v12"/><circle cx="6.5" cy="18" r="2.5"/><circle cx="17.5" cy="15" r="2.5"/>',
		single: '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="2.5"/><path d="M12 9.5v5"/>',
		album: '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="2.5"/>',
		ep: '<rect x="4" y="4" width="16" height="16" rx="2"/><circle cx="12" cy="12" r="3"/><path d="M12 9v6M9 12h6"/>',
		podcast_show:
			'<rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5.5 11a6.5 6.5 0 0 0 13 0"/><path d="M12 17.5V21M9 21h6"/>',
		podcast_episode:
			'<rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5.5 11a6.5 6.5 0 0 0 13 0"/><circle cx="12" cy="12" r="1.5" fill="currentColor" stroke="none"/>',
		mix: '<path d="M3 8a4 4 0 0 1 4-4h2a4 4 0 0 1 4 4v8a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4z"/><path d="M13 8h4a4 4 0 0 1 4 4v4a4 4 0 0 1-4 4h-4"/>',
		playlist:
			'<path d="M4 6h11M4 12h11M4 18h6"/><path d="M19 6v10"/><circle cx="16.5" cy="17" r="2.5"/>',
		product:
			'<path d="M6 7h12l-1 12H7L6 7z"/><path d="M9 7a3 3 0 0 1 6 0"/>',
		artist: '<circle cx="12" cy="8.5" r="3.75"/><path d="M4.75 20a7.25 7.25 0 0 1 14.5 0"/>',
		genre: '<path d="M3.5 12.5v-8a1 1 0 0 1 1-1h8l8 8-9 9z"/><circle cx="8" cy="8" r="1.2"/>',
		mood: '<path d="M12 20.5 4 12.9A4.8 4.8 0 1 1 12 6.7a4.8 4.8 0 1 1 8 6.2Z"/>',
		release_type:
			'<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="2.5"/>',
		search: '<circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5"/>',
		other: '<circle cx="12" cy="12" r="3.5"/>',
	};

	function iconSvg( type, subtype ) {
		var key = type;
		// If release with subtype like track/album/podcast, use subtype icon if available
		if ( 'release' === type && subtype && ICON_PATHS[ subtype ] ) {
			key = subtype;
		}
		var path = ICON_PATHS[ key ] || ICON_PATHS.other;
		return (
			'<svg class="mw-catalog-suggest__icon-svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' +
			path +
			'</svg>'
		);
	}

	function debounce( callback, wait ) {
		var timer = null;
		return function () {
			var args = arguments;
			window.clearTimeout( timer );
			timer = window.setTimeout( function () {
				callback.apply( null, args );
			}, wait );
		};
	}

	function readFieldConfig( wrapper ) {
		var raw = wrapper.getAttribute( 'data-mw-suggest' );
		if ( ! raw ) {
			return {};
		}
		try {
			var parsed = JSON.parse( raw );
			return parsed && 'object' === typeof parsed ? parsed : {};
		} catch ( error ) {
			return {};
		}
	}

	/**
	 * Remove any legacy nbp-suggest boxes that cause duplicate dropdowns
	 */
	function suppressLegacyBoxes( wrapper ) {
		var legacy = document.querySelectorAll(
			'.nbp-suggest, [class*="nbp-suggest__list"], .product-search-suggest, .yith-ajaxsearch-suggest'
		);
		Array.prototype.forEach.call( legacy, function ( el ) {
			if (
				wrapper.contains( el ) ||
				( el.closest &&
					el.closest(
						'.mw-header-suggest, .mw-stream-search, .mw-header-search, .wp-block-search'
					) )
			) {
				el.style.display = 'none';
				el.setAttribute( 'aria-hidden', 'true' );
				el.hidden = true;
			}
		} );
		var extraLists = wrapper.querySelectorAll(
			'ul:not(.mw-catalog-suggest__list)'
		);
		Array.prototype.forEach.call( extraLists, function ( ul ) {
			if (
				ul.className.indexOf( 'nbp-suggest' ) > -1 ||
				ul.className.indexOf( 'suggest' ) > -1
			) {
				ul.style.display = 'none';
				ul.hidden = true;
			}
		} );
	}

	function setup( input ) {
		var wrapper = input.closest( '.mw-catalog-suggest' );
		if ( ! wrapper || wrapper.getAttribute( 'data-mw-suggest-ready' ) ) {
			return;
		}

		if ( input.hasAttribute( 'aria-hidden' ) ) {
			input.removeAttribute( 'aria-hidden' );
		}
		if ( '-1' === input.getAttribute( 'tabindex' ) ) {
			input.removeAttribute( 'tabindex' );
		}
		input.classList.remove( 'wp-block-search__searchfield-hidden' );
		input.removeAttribute( 'hidden' );

		var settings = {};
		var key;
		for ( key in GLOBAL ) {
			if ( Object.prototype.hasOwnProperty.call( GLOBAL, key ) ) {
				settings[ key ] = GLOBAL[ key ];
			}
		}
		var overrides = readFieldConfig( wrapper );
		for ( key in overrides ) {
			if ( Object.prototype.hasOwnProperty.call( overrides, key ) ) {
				settings[ key ] = overrides[ key ];
			}
		}

		if ( ! settings.endpoint ) {
			return;
		}

		var minLength = parseInt( settings.minLength, 10 ) || MIN_LENGTH;
		var limit = parseInt( settings.limit, 10 ) || 0;

		wrapper.setAttribute( 'data-mw-suggest-ready', 'true' );

		var existingLists = wrapper.querySelectorAll(
			'.mw-catalog-suggest__list'
		);
		Array.prototype.forEach.call( existingLists, function ( el ) {
			el.parentNode.removeChild( el );
		} );

		var listId =
			'mw-catalog-suggest-list-' +
			Math.random().toString( 36 ).slice( 2, 8 );
		var list = document.createElement( 'ul' );
		list.id = listId;
		list.className = 'mw-catalog-suggest__list';
		list.setAttribute( 'role', 'listbox' );
		list.hidden = true;
		wrapper.appendChild( list );

		suppressLegacyBoxes( wrapper );
		setTimeout( function () {
			suppressLegacyBoxes( wrapper );
		}, 300 );

		var status = wrapper.querySelector( '[data-mw-suggest-status]' );
		var active = -1;
		var items = [];
		var controller = null;
		var currentTerm = '';

		input.setAttribute( 'role', 'combobox' );
		input.setAttribute( 'aria-expanded', 'false' );
		input.setAttribute( 'aria-controls', listId );
		input.setAttribute( 'aria-autocomplete', 'list' );
		input.setAttribute( 'aria-haspopup', 'listbox' );
		input.setAttribute( 'autocomplete', 'off' );

		function close() {
			list.hidden = true;
			list.innerHTML = '';
			items = [];
			active = -1;
			input.setAttribute( 'aria-expanded', 'false' );
			input.removeAttribute( 'aria-activedescendant' );
			wrapper.classList.remove(
				'is-loading',
				'has-results',
				'has-empty'
			);
		}

		function announce( count ) {
			if ( ! status ) {
				return;
			}
			if ( ! count ) {
				status.textContent = settings.noResults || '';
				return;
			}
			status.textContent = ( settings.resultsCount || '%d' ).replace(
				'%d',
				String( count )
			);
		}

		function highlight( index ) {
			items.forEach( function ( option, position ) {
				var selected = position === index;
				option.setAttribute(
					'aria-selected',
					selected ? 'true' : 'false'
				);
				option.classList.toggle( 'is-active', selected );
			} );
			if ( index > -1 && items[ index ] ) {
				input.setAttribute(
					'aria-activedescendant',
					items[ index ].id
				);
				try {
					items[ index ].scrollIntoView( { block: 'nearest' } );
				} catch ( e ) {}
			} else {
				input.removeAttribute( 'aria-activedescendant' );
			}
			active = index;
		}

		function groupByType( suggestions ) {
			var groups = {};
			var order = [];
			suggestions.forEach( function ( s ) {
				var t = s.type || 'other';
				if ( ! groups[ t ] ) {
					groups[ t ] = [];
					order.push( t );
				}
				groups[ t ].push( s );
			} );
			var preferred = [
				'release',
				'product',
				'artist',
				'genre',
				'mood',
				'release_type',
			];
			order.sort( function ( a, b ) {
				var ia = preferred.indexOf( a );
				var ib = preferred.indexOf( b );
				if ( ia === -1 && ib === -1 ) {
					return 0;
				}
				if ( ia === -1 ) {
					return 1;
				}
				if ( ib === -1 ) {
					return -1;
				}
				return ia - ib;
			} );
			return { groups, order };
		}

		function createOption( suggestion, index, listId ) {
			var option = document.createElement( 'li' );
			option.id = listId + '-option-' + index;
			option.className =
				'mw-catalog-suggest__option mw-catalog-suggest__option--' +
				( suggestion.type || 'other' );
			option.setAttribute( 'role', 'option' );
			option.setAttribute( 'aria-selected', 'false' );
			option.setAttribute( 'data-type', suggestion.type || '' );

			var main = document.createElement( 'span' );
			main.className = 'mw-catalog-suggest__option-main';
			main.textContent = suggestion.label;

			var type = document.createElement( 'span' );
			type.className = 'mw-catalog-suggest__type';
			type.textContent =
				settings.typeLabels && settings.typeLabels[ suggestion.type ]
					? settings.typeLabels[ suggestion.type ]
					: suggestion.type;

			var icon = document.createElement( 'span' );
			icon.className = 'mw-catalog-suggest__icon';
			icon.setAttribute( 'aria-hidden', 'true' );
			// Use SVG icon instead of emoji — clean, standard, harmonious with theme
			icon.innerHTML = iconSvg( suggestion.type || 'other', '' );

			option.appendChild( icon );
			option.appendChild( main );
			option.appendChild( type );

			option.addEventListener( 'mousedown', function ( event ) {
				event.preventDefault();
				if ( suggestion.url ) {
					close();
					if ( 'function' === typeof window._mwNavigate ) {
						window._mwNavigate( suggestion.url );
					} else {
						window.location.href = suggestion.url;
					}
					return;
				}
				input.value = suggestion.label;
				close();
				var form = input.closest( 'form' );
				if ( form ) {
					form.submit();
				}
			} );

			return option;
		}

		function renderLoading() {
			list.innerHTML = '';
			items = [];
			var li = document.createElement( 'li' );
			li.className = 'mw-catalog-suggest__loading';
			li.setAttribute( 'role', 'presentation' );
			li.innerHTML =
				'<span class="mw-catalog-suggest__spinner" aria-hidden="true"></span><span>در حال جستجو...</span>';
			list.appendChild( li );
			list.hidden = false;
			input.setAttribute( 'aria-expanded', 'true' );
			wrapper.classList.add( 'is-loading' );
			wrapper.classList.remove( 'has-results', 'has-empty' );
		}

		function renderEmpty( term ) {
			list.innerHTML = '';
			items = [];
			var li = document.createElement( 'li' );
			li.className = 'mw-catalog-suggest__empty';
			li.setAttribute( 'role', 'presentation' );
			var safeTerm = term
				? term.replace( /</g, '&lt;' ).replace( />/g, '&gt;' )
				: '';
			li.innerHTML =
				'<div class="mw-catalog-suggest__empty-icon" aria-hidden="true">' +
				iconSvg( 'search', '' ) +
				'</div>' +
				'<div class="mw-catalog-suggest__empty-title">نتیجه‌ای پیدا نشد</div>' +
				'<div class="mw-catalog-suggest__empty-desc">برای "' +
				safeTerm +
				'" چیزی پیدا نکردیم. واژه دیگری امتحان کنید یا جستجوی کامل را ببینید.</div>' +
				'<div class="mw-catalog-suggest__empty-actions"><button type="button" class="mw-catalog-suggest__empty-btn">جستجوی کامل</button></div>';
			var btn = li.querySelector( '.mw-catalog-suggest__empty-btn' );
			if ( btn ) {
				btn.addEventListener( 'mousedown', function ( e ) {
					e.preventDefault();
					var form = input.closest( 'form' );
					if ( form ) {
						form.submit();
					} else {
						var searchUrl =
							'/search/?s=' + window.encodeURIComponent( term );
						if ( settings.searchUrl ) {
							searchUrl =
								settings.searchUrl +
								'?s=' +
								window.encodeURIComponent( term );
						}
						if ( 'function' === typeof window._mwNavigate ) {
							close();
							window._mwNavigate( searchUrl );
						} else {
							window.location.href = searchUrl;
						}
					}
				} );
			}
			list.appendChild( li );
			list.hidden = false;
			input.setAttribute( 'aria-expanded', 'true' );
			wrapper.classList.add( 'has-empty' );
			wrapper.classList.remove( 'is-loading', 'has-results' );
			announce( 0 );
		}

		function render( suggestions, term ) {
			list.innerHTML = '';
			items = [];
			if ( ! suggestions.length ) {
				renderEmpty( term || currentTerm );
				return;
			}

			var grouped = groupByType( suggestions );
			var idx = 0;
			grouped.order.forEach( function ( type ) {
				var groupItems = grouped.groups[ type ];
				if ( ! groupItems || ! groupItems.length ) {
					return;
				}

				var header = document.createElement( 'li' );
				header.className = 'mw-catalog-suggest__group';
				header.setAttribute( 'role', 'presentation' );
				var label =
					settings.typeLabels && settings.typeLabels[ type ]
						? settings.typeLabels[ type ]
						: type;
				header.innerHTML =
					'<span class="mw-catalog-suggest__group-title">' +
					label +
					'</span><span class="mw-catalog-suggest__group-count">' +
					groupItems.length +
					'</span>';
				list.appendChild( header );

				groupItems.forEach( function ( suggestion ) {
					var option = createOption( suggestion, idx, listId );
					list.appendChild( option );
					items.push( option );
					idx++;
				} );
			} );

			list.hidden = false;
			input.setAttribute( 'aria-expanded', 'true' );
			highlight( -1 );
			announce( suggestions.length );
			wrapper.classList.add( 'has-results' );
			wrapper.classList.remove( 'is-loading', 'has-empty' );

			suppressLegacyBoxes( wrapper );
		}

		var query = debounce( function ( term ) {
			currentTerm = term;
			if ( controller && controller.abort ) {
				controller.abort();
			}
			controller = window.AbortController
				? new window.AbortController()
				: null;

			renderLoading();

			var url =
				settings.endpoint +
				( settings.endpoint.indexOf( '?' ) > -1 ? '&' : '?' ) +
				'term=' +
				window.encodeURIComponent( term ) +
				( limit > 0 ? '&per_page=' + limit : '' );
			window
				.fetch( url, {
					credentials: 'same-origin',
					signal: controller ? controller.signal : undefined,
					headers: { Accept: 'application/json' },
				} )
				.then( function ( response ) {
					return response.ok ? response.json() : { items: [] };
				} )
				.then( function ( payload ) {
					render(
						payload && payload.items ? payload.items : [],
						term
					);
				} )
				.catch( function ( err ) {
					if ( err && err.name === 'AbortError' ) {
						return;
					}
					renderEmpty( term );
				} );
		}, DEBOUNCE );

		input.addEventListener( 'input', function () {
			var term = input.value.trim();
			if ( term.length < minLength ) {
				close();
				return;
			}
			query( term );
		} );

		input.addEventListener( 'focus', function () {
			suppressLegacyBoxes( wrapper );
			var term = input.value.trim();
			if ( term.length >= minLength ) {
				query( term );
			}
		} );

		input.addEventListener( 'keydown', function ( event ) {
			if ( list.hidden ) {
				if ( 'ArrowDown' === event.key ) {
					var t = input.value.trim();
					if ( t.length >= minLength ) {
						event.preventDefault();
						query( t );
					}
				}
				return;
			}
			if ( ! items.length ) {
				if ( 'Escape' === event.key ) {
					close();
				}
				return;
			}
			if ( 'ArrowDown' === event.key ) {
				event.preventDefault();
				highlight( active + 1 >= items.length ? 0 : active + 1 );
				return;
			}
			if ( 'ArrowUp' === event.key ) {
				event.preventDefault();
				highlight( active - 1 < 0 ? items.length - 1 : active - 1 );
				return;
			}
			if ( 'Enter' === event.key && active > -1 ) {
				event.preventDefault();
				items[ active ].dispatchEvent(
					new window.MouseEvent( 'mousedown' )
				);
				return;
			}
			if ( 'Escape' === event.key ) {
				close();
			}
		} );

		input.addEventListener( 'blur', function () {
			window.setTimeout( close, 180 );
		} );

		var observer = null;
		var observeRoot = wrapper.parentNode || wrapper;
		if ( window.MutationObserver && observeRoot ) {
			observer = new window.MutationObserver( function () {
				suppressLegacyBoxes( wrapper );
			} );
			observer.observe( observeRoot, {
				childList: true,
			} );
			window.setTimeout( function () {
				if ( observer ) {
					observer.disconnect();
				}
			}, 3000 );
		}
	}

	function init( root ) {
		var scope = root && root.querySelectorAll ? root : document;
		var inputs = scope.querySelectorAll(
			'.mw-catalog-suggest input[type="search"]'
		);
		Array.prototype.forEach.call( inputs, setup );

		var legacies = scope.querySelectorAll
			? scope.querySelectorAll( '.nbp-suggest' )
			: [];
		Array.prototype.forEach.call( legacies, function ( el ) {
			if ( ! el.closest( '.mw-catalog-suggest' ) ) {
				if (
					el.closest(
						'.mw-header-suggest, .mw-stream-search, .mw-header-search, header, .site-header, .mw-header'
					)
				) {
					el.style.display = 'none';
					el.hidden = true;
				}
			}
		} );
	}

	var suggestController = {
		mount() {
			init();
		},
	};
	if (
		window.MusicWaveApp &&
		'function' === typeof window.MusicWaveApp.registerController
	) {
		window.MusicWaveApp.registerController(
			'catalog-suggest',
			suggestController
		);
	} else {
		window.MusicWaveAppQueue = window.MusicWaveAppQueue || [];
		window.MusicWaveAppQueue.push( {
			id: 'catalog-suggest',
			definition: suggestController,
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			init();
		} );
	} else {
		init();
	}
	document.addEventListener( 'mw-page-rendered', function () {
		init();
	} );
} )( window.musicWaveCatalogSuggest );
