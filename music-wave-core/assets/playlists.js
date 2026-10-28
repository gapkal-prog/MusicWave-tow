/**
 * Playlists progressive enhancement: picker popover + inline create + Play all fallback.
 *
 * Security: all mutating requests use wp.apiFetch with X-WP-Nonce; failed nonce
 * yields 403 and is surfaced as sessionError, never silent.
 * Performance: no polling, event-delegated, <8KB gz.
 *
 * @package
 */
( function () {
	'use strict';

	if ( window._mwPlaylistsInitialized ) {
		return;
	}
	window._mwPlaylistsInitialized = true;

	var initialSettings = window.musicWavePlaylists || {};

	function currentSettings() {
		return window.musicWavePlaylists || initialSettings;
	}

	var settings =
		typeof window.Proxy === 'function'
			? new window.Proxy(
					{},
					{
						get( target, prop ) {
							var active = currentSettings();
							return active[ prop ];
						},
					}
			  )
			: initialSettings;

	var labels =
		typeof window.Proxy === 'function'
			? new window.Proxy(
					{},
					{
						get( target, prop ) {
							var active = currentSettings();
							return active && active.labels
								? active.labels[ prop ]
								: undefined;
						},
					}
			  )
			: initialSettings.labels || {};

	function restHeaders() {
		var nonce =
			settings.restNonce ||
			( window.musicWavePreviewPlayer &&
				window.musicWavePreviewPlayer.restNonce ) ||
			'';
		return nonce ? { 'X-WP-Nonce': nonce } : {};
	}

	function errorMessage( error, fallback ) {
		if (
			error &&
			( error.status === 403 ||
				error.status === 401 ||
				error.code === 'mw_authentication_required' ||
				error.code === 'rest_forbidden' )
		) {
			return labels.sessionError || fallback;
		}
		// Specific backend codes carry a translated message — prefer it verbatim.
		if (
			error &&
			error.code &&
			( error.code === 'mw_playlist_invalid_release' ||
				error.code === 'mw_playlist_limit_reached' ||
				error.code === 'mw_playlist_create_failed' ||
				error.code === 'mw_playlist_invalid_title' )
		) {
			return error.message || fallback;
		}
		return error && error.message ? error.message : fallback;
	}

	// Mark JS enabled for CSS fallback hiding.
	try {
		document.documentElement.classList.add( 'js' );
	} catch ( e ) {}

	var boundContainers =
		typeof window.WeakSet === 'function' ? new window.WeakSet() : null;

	function markContainerBound( node ) {
		if ( ! node ) {
			return false;
		}
		if ( boundContainers ) {
			if ( boundContainers.has( node ) ) {
				return false;
			}
			boundContainers.add( node );
			return true;
		}
		if ( node.__mwBound ) {
			return false;
		}
		node.__mwBound = true;
		return true;
	}

	function escapeHtml( str ) {
		var div = document.createElement( 'div' );
		div.textContent = str;
		return div.innerHTML.replace( /"/g, '&quot;' ).replace( /'/g, '&#39;' );
	}

	function syncPlayer() {
		if ( window._mwPreviewController && window._mwPreviewController.sync ) {
			window._mwPreviewController.sync();
		}
	}

	function apiFetch( options ) {
		if ( ! window.wp || ! window.wp.apiFetch ) {
			return Promise.reject( {
				message: labels.error || 'رابط API در دسترس نیست',
			} );
		}
		options.headers = Object.assign(
			{},
			restHeaders(),
			options.headers || {}
		);
		return window.wp.apiFetch( options );
	}

	/* ------------------------------------------------------------------ */
	/* Picker popover                                                     */
	/* ------------------------------------------------------------------ */
	function closeAllPickers( except ) {
		document
			.querySelectorAll( '[data-mw-picker-panel]:not([hidden])' )
			.forEach( function ( panel ) {
				if ( except && panel === except ) {
					return;
				}
				panel.hidden = true;
				var trigger = document.querySelector(
					'[aria-controls="' + panel.id + '"]'
				);
				if ( trigger ) {
					trigger.setAttribute( 'aria-expanded', 'false' );
				}
			} );
	}

	function togglePicker( trigger ) {
		var panelId = trigger.getAttribute( 'aria-controls' );
		var panel = panelId ? document.getElementById( panelId ) : null;
		if ( ! panel ) {
			return;
		}
		var willOpen = panel.hidden;
		closeAllPickers( panel );
		panel.hidden = ! willOpen;
		trigger.setAttribute( 'aria-expanded', willOpen ? 'true' : 'false' );
		if ( willOpen ) {
			var first = panel.querySelector( 'input, [data-mw-picker-option]' );
			if ( first ) {
				try {
					first.focus( { preventScroll: true } );
				} catch ( e ) {
					first.focus();
				}
			}
		}
	}

	function setPickerStatus( panel, message, isError ) {
		var status = panel
			? panel.querySelector( '[data-mw-picker-status]' )
			: null;
		if ( ! status ) {
			return;
		}
		status.textContent = message || '';
		status.classList.toggle( 'is-error', !! isError && !! message );
		status.classList.toggle( 'is-success', ! isError && !! message );
	}

	function markOptionSelected( option, selected ) {
		option.classList.toggle( 'is-selected', !! selected );
		option.setAttribute( 'aria-selected', selected ? 'true' : 'false' );
	}

	document.addEventListener( 'click', function ( event ) {
		var trigger = event.target.closest( '[data-mw-picker-trigger]' );
		if ( trigger ) {
			event.preventDefault();
			togglePicker( trigger );
			return;
		}
		var closeBtn = event.target.closest( '[data-mw-picker-close]' );
		if ( closeBtn ) {
			var panel = closeBtn.closest( '[data-mw-picker-panel]' );
			if ( panel ) {
				panel.hidden = true;
				var t = document.querySelector(
					'[aria-controls="' + panel.id + '"]'
				);
				if ( t ) {
					t.setAttribute( 'aria-expanded', 'false' );
					try {
						t.focus();
					} catch ( e ) {}
				}
			}
			return;
		}
		// Close when clicking outside any picker.
		if ( ! event.target.closest( '[data-mw-playlist-picker]' ) ) {
			closeAllPickers();
		}
	} );

	document.addEventListener( 'keydown', function ( event ) {
		if ( event.key === 'Escape' || event.key === 'Esc' ) {
			var open = document.querySelector(
				'[data-mw-picker-panel]:not([hidden])'
			);
			if ( open ) {
				open.hidden = true;
				var t = document.querySelector(
					'[aria-controls="' + open.id + '"]'
				);
				if ( t ) {
					t.setAttribute( 'aria-expanded', 'false' );
					try {
						t.focus();
					} catch ( e ) {}
				}
				event.preventDefault();
			}
		}
	} );

	// Add to playlist via option button
	document.addEventListener( 'click', function ( event ) {
		var option = event.target.closest( '[data-mw-picker-option]' );
		if ( ! option || option.disabled ) {
			return;
		}
		var panel = option.closest( '[data-mw-picker-panel]' );
		var picker = option.closest( '[data-mw-playlist-picker]' );
		if ( ! panel || ! picker ) {
			return;
		}

		var playlistId =
			parseInt( option.getAttribute( 'data-playlist-id' ), 10 ) || 0;
		var releaseId =
			parseInt( picker.getAttribute( 'data-release-id' ), 10 ) || 0;
		if ( ! playlistId || ! releaseId ) {
			// Show a visible error instead of silently doing nothing — the common case is a missing
			// data-release-id when the block is rendered outside the loop (single template). PHP
			// fallback now fixes it, but keep JS defensive and user-visible.
			event.preventDefault();
			setPickerStatus(
				panel,
				errorMessage(
					{
						message:
							labels.error ||
							'این انتشار را نمی‌توان به فهرست پخش اضافه کرد.',
					},
					labels.error
				),
				true
			);
			return;
		}

		event.preventDefault();
		option.classList.add( 'is-adding' );
		option.disabled = true;
		setPickerStatus( panel, '', false );

		apiFetch( {
			path: '/music-wave/v1/playlists/' + playlistId + '/items',
			method: 'POST',
			data: { release_id: releaseId },
		} )
			.then( function ( data ) {
				markOptionSelected( option, true );
				setPickerStatus(
					panel,
					labels.added || 'به فهرست پخش اضافه شد.',
					false
				);
				option.disabled = false;
				option.classList.remove( 'is-adding' );
				// Keep the count badge in the picker and any Your playlists counters in sync without reload.
				try {
					var meta = option.querySelector(
						'.mw-playlist-picker__option-meta'
					);
					// Prefer server count when available (data.count from view), fallback to +1.
					var serverCount =
						data && data.count ? parseInt( data.count, 10 ) : 0;
					if ( meta ) {
						if ( serverCount > 0 ) {
							var plural =
								serverCount === 1
									? labels.trackSingular || 'قطعه'
									: labels.trackPlural || 'قطعه‌ها';
							meta.textContent = serverCount + ' ' + plural;
						} else {
							var current = parseInt( meta.textContent, 10 );
							if ( ! isNaN( current ) ) {
								var next = current + 1;
								var p2 =
									next === 1
										? labels.trackSingular || 'قطعه'
										: labels.trackPlural || 'قطعه‌ها';
								meta.textContent = next + ' ' + p2;
							}
						}
					}
					// Update any Your playlists manager counters on the same page.
					var countEl = document.querySelector(
						'[data-mw-playlist-count="' + playlistId + '"]'
					);
					if ( countEl ) {
						if ( serverCount > 0 ) {
							// view.count is numeric; render as "X releases" like PHP.
							countEl.textContent =
								serverCount === 1
									? '1 ' + ( labels.trackSingular || 'قطعه' )
									: serverCount +
									  ' ' +
									  ( labels.trackPlural || 'قطعه‌ها' );
						} else {
							var c = parseInt( countEl.textContent, 10 );
							if ( ! isNaN( c ) ) {
								countEl.textContent =
									c + 1 === 1
										? '1 ' +
										  ( labels.trackSingular || 'قطعه' )
										: c +
										  1 +
										  ' ' +
										  ( labels.trackPlural || 'قطعه‌ها' );
							}
						}
					}
				} catch ( e ) {}
			} )
			.catch( function ( error ) {
				option.disabled = false;
				option.classList.remove( 'is-adding' );
				var msg = errorMessage( error, labels.error );
				// Legacy server: generic mw_playlist_rejected could be duplicate or a real error.
				// Distinguish by message hint: limit / not available = real error -> show red.
				if ( error && error.code === 'mw_playlist_rejected' ) {
					if (
						error.message &&
						( /limit|maximu/i.test( error.message ) ||
							/not a release|not available/i.test(
								error.message
							) )
					) {
						setPickerStatus( panel, msg, true );
						return;
					}
					markOptionSelected( option, true );
					msg = labels.added || msg;
					setPickerStatus( panel, msg, false );
					return;
				}
				setPickerStatus( panel, msg, true );
			} );
	} );

	// Inline create & add
	document.addEventListener( 'submit', function ( event ) {
		var form = event.target.closest( '[data-mw-picker-create]' );
		if ( ! form ) {
			return;
		}
		event.preventDefault();
		var picker = form.closest( '[data-mw-playlist-picker]' );
		var releaseId =
			parseInt(
				form.getAttribute( 'data-release-id' ) ||
					( picker ? picker.getAttribute( 'data-release-id' ) : '0' ),
				10
			) || 0;
		var input = form.querySelector( 'input[type="text"]' );
		var title = input ? input.value.trim() : '';
		if ( ! title ) {
			if ( input ) {
				input.focus();
			}
			return;
		}
		var panel = form.closest( '[data-mw-picker-panel]' );
		var submitBtn = form.querySelector( 'button[type="submit"]' );
		if ( submitBtn ) {
			submitBtn.disabled = true;
			submitBtn.setAttribute( 'aria-busy', 'true' );
		}
		setPickerStatus( panel, '', false );

		apiFetch( {
			path: '/music-wave/v1/playlists',
			method: 'POST',
			data: { title, visibility: 'private' },
		} )
			.then( function ( created ) {
				var newId =
					created && created.id ? parseInt( created.id, 10 ) : 0;
				if ( ! newId ) {
					throw { message: labels.error };
				}
				// If we have a release context, add it; otherwise just create the empty playlist.
				if ( releaseId > 0 ) {
					return apiFetch( {
						path: '/music-wave/v1/playlists/' + newId + '/items',
						method: 'POST',
						data: { release_id: releaseId },
					} ).then( function () {
						return newId;
					} );
				}
				return newId;
			} )
			.then( function ( newId ) {
				var wasAdded = releaseId > 0;
				setPickerStatus(
					panel,
					wasAdded
						? labels.added || 'به فهرست پخش اضافه شد.'
						: labels.created || 'فهرست پخش ایجاد شد.',
					false
				);
				if ( input ) {
					input.value = '';
				}
				if ( submitBtn ) {
					submitBtn.disabled = false;
					submitBtn.removeAttribute( 'aria-busy' );
				}
				// Inject new option into the list so user sees it without reload
				var optionsWrap = panel
					? panel.querySelector( '[data-mw-picker-options]' )
					: null;
				if ( optionsWrap ) {
					var btn = document.createElement( 'button' );
					btn.type = 'button';
					btn.className = 'mw-playlist-picker__option is-selected';
					btn.setAttribute( 'role', 'option' );
					btn.setAttribute( 'aria-selected', 'true' );
					btn.setAttribute( 'data-mw-picker-option', '' );
					btn.setAttribute( 'data-playlist-id', String( newId ) );
					var t = document.createElement( 'span' );
					t.className = 'mw-playlist-picker__option-title';
					t.textContent = title;
					var m = document.createElement( 'span' );
					m.className = 'mw-playlist-picker__option-meta';
					m.textContent = wasAdded
						? labels.oneTrack || '۱ قطعه'
						: '0 ' + ( labels.trackPlural || 'قطعه‌ها' );
					var c = document.createElement( 'span' );
					c.className = 'mw-playlist-picker__option-check';
					c.setAttribute( 'aria-hidden', 'true' );
					c.textContent = '✓';
					btn.appendChild( t );
					btn.appendChild( m );
					btn.appendChild( c );
					optionsWrap.appendChild( btn );
				}
				// If the Your playlists manager is on the same page, bump its total.
				try {
					var totalEl = document.querySelector(
						'[data-mw-playlists] .mw-playlists__total'
					);
					if ( totalEl ) {
						var cur = parseInt( totalEl.textContent, 10 );
						if ( ! isNaN( cur ) ) {
							totalEl.textContent = String( cur + 1 );
						}
					}
				} catch ( e2 ) {}
			} )
			.catch( function ( error ) {
				if ( submitBtn ) {
					submitBtn.disabled = false;
					submitBtn.removeAttribute( 'aria-busy' );
				}
				setPickerStatus(
					panel,
					errorMessage( error, labels.error ),
					true
				);
			} );
	} );

	/* ------------------------------------------------------------------ */
	/* Public playlists — instant search, pagination & Show tracks (no reload) */
	/* ------------------------------------------------------------------ */
	var activePublicController = null;

	function initPublicPlaylists( publicRoot ) {
		if ( ! markContainerBound( publicRoot ) ) {
			return;
		}

		var searchForm = publicRoot.querySelector(
			'[data-mw-public-search-form]'
		);
		var searchInput = publicRoot.querySelector(
			'[data-mw-public-search-input]'
		);
		var grid = publicRoot.querySelector( '.mw-public-playlists__grid' );
		var liveRegion = publicRoot.querySelector( '[data-mw-public-live]' );
		var headerTotal = publicRoot.querySelector(
			'.mw-public-playlists__total'
		);
		var debounceTimer = null;
		var currentRequest = null;
		var currentSearch = searchInput ? searchInput.value.trim() : '';
		// Card display toggles configured on the block (server-rendered JSON).
		var cardOptions = {
			showArt: true,
			showAuthor: true,
			showUpdated: true,
			showPlayButton: true,
			showToggle: true,
			showPagination: true,
			layout: 'grid',
			columns: 3,
			imageShape: 'square',
		};
		try {
			var rawOptions = publicRoot.getAttribute(
				'data-mw-public-options'
			);
			if ( rawOptions ) {
				Object.assign( cardOptions, JSON.parse( rawOptions ) );
			}
		} catch ( e ) {}

		function setLive( message ) {
			if ( liveRegion ) {
				liveRegion.textContent = message || '';
			}
		}

		/**
		 * Cover stack markup matching PlaylistBlocks::playlist_covers_markup():
		 * up to four cells (image / title initial / empty pad) or the empty
		 * sleeve with a note glyph, so client-rendered cards share the
		 * theme's sleeve-deck styling with server-rendered ones.
		 *
		 * @param {Array}  covers Cover descriptors ({ title, image }).
		 * @param {string} title  Playlist title (fallback initial source).
		 * @return {string} HTML string.
		 */
		function buildCoverStack( covers, title ) {
			var cells = '';
			var count = 0;

			covers.slice( 0, 4 ).forEach( function ( cover ) {
				var image =
					cover && typeof cover.image === 'string' ? cover.image : '';
				var label =
					cover && typeof cover.title === 'string' && cover.title
						? cover.title
						: title;
				var initial = label ? Array.from( label )[ 0 ] : '♫';

				count += 1;
				if ( /^https?:\/\//i.test( image ) ) {
					cells +=
						'<span class="mw-playlists__art-cell"><img src="' +
						escapeHtml( image ) +
						'" alt="" loading="lazy" decoding="async"></span>';
				} else {
					cells +=
						'<span class="mw-playlists__art-cell mw-playlists__art-cell--fallback"><span aria-hidden="true">' +
						escapeHtml( initial ) +
						'</span></span>';
				}
			} );

			if ( 0 === count ) {
				return '<div class="mw-playlists__art-grid mw-playlists__art-grid--empty"><span class="mw-playlists__art-placeholder" aria-hidden="true">♫</span></div>';
			}
			for ( ; count < 4; count += 1 ) {
				cells +=
					'<span class="mw-playlists__art-cell mw-playlists__art-cell--empty" aria-hidden="true"></span>';
			}

			return '<div class="mw-playlists__art-grid">' + cells + '</div>';
		}

		function buildPublicCard( playlist ) {
			var pid = parseInt( playlist.id, 10 ) || 0;
			var title = playlist.title || '';
			var author = playlist.author || '';
			var count = parseInt( playlist.count, 10 ) || 0;
			var updated = playlist.updated_at
				? new Date( playlist.updated_at * 1000 ).toLocaleDateString()
				: '';
			var panelId = 'mw-public-playlist-panel-' + pid;
			var artMarkup = buildCoverStack(
				Array.isArray( playlist.covers ) ? playlist.covers : [],
				title
			);
			// Layered sleeve deck: the main cover returns behind itself as the
			// dark glass sleeve (mirrors PlaylistBlocks::playlist_covers_markup
			// with $layered). Only a real remote image can back the sleeve.
			var mainCover =
				Array.isArray( playlist.covers ) &&
				playlist.covers.length &&
				playlist.covers[ 0 ] &&
				typeof playlist.covers[ 0 ].image === 'string' &&
				/^https?:\/\//i.test( playlist.covers[ 0 ].image )
					? playlist.covers[ 0 ].image
					: '';
			var backMarkup = mainCover
				? '<div class="mw-public-playlists__art-back"><img src="' +
				  escapeHtml( mainCover ) +
				  '" alt="" loading="lazy" decoding="async"></div>'
				: '';
			var shapeClass =
				cardOptions.imageShape && cardOptions.imageShape !== 'square'
					? ' mw-public-playlists__art--' + cardOptions.imageShape
					: '';
			// MASTER stamp removed per spec – clean minimal card.
			var art = cardOptions.showArt
				? '<div class="mw-public-playlists__art' +
				  shapeClass +
				  '" aria-hidden="true">' +
				  backMarkup +
				  artMarkup +
				  '</div>'
				: '';
			var playDisabled =
				count === 0 ? ' disabled aria-disabled="true"' : '';
			var playAria = (
				labels.playAllAria || 'پخش همهٔ قطعه‌ها در %s'
			).replace( '%s', title );
			var playBtn = cardOptions.showPlayButton
				? '<button type="button" class="mw-public-playlists__play" data-mw-playlist-play data-playlist-id="' +
				  pid +
				  '" aria-label="' +
				  escapeHtml( playAria ) +
				  '"' +
				  playDisabled +
				  '><span class="mw-playlists__play-icon" aria-hidden="true">▶</span><span>' +
				  escapeHtml( labels.playAll || 'پخش همه' ) +
				  '</span><span class="mw-public-playlists__play-count" aria-hidden="true">' +
				  count +
				  '</span></button>'
				: '';
			var toggle = cardOptions.showToggle
				? '<button type="button" class="mw-public-playlists__toggle" aria-expanded="false" aria-controls="' +
				  panelId +
				  '" data-mw-public-toggle data-playlist-id="' +
				  pid +
				  '">' +
				  escapeHtml( labels.showTracks || 'نمایش قطعه‌ها' ) +
				  '</button>'
				: '';
			var meta = '<p class="mw-public-playlists__meta">';
			if ( cardOptions.showAuthor && author ) {
				meta +=
					'<span class="mw-public-playlists__author">' +
					escapeHtml( author ) +
					'</span><span class="mw-public-playlists__dot" aria-hidden="true">·</span>';
			}
			meta +=
				'<span class="mw-public-playlists__count">' +
				'<span class="mw-public-playlists__count-icon" aria-hidden="true">♪</span>' +
				count +
				' ' +
				escapeHtml(
					count === 1
						? labels.trackSingular || 'قطعه'
						: labels.trackPlural || 'قطعه‌ها'
				) +
				'</span>';
			if ( cardOptions.showUpdated && updated ) {
				meta +=
					'<span class="mw-public-playlists__dot" aria-hidden="true">·</span><time>' +
					escapeHtml( updated ) +
					'</time>';
			}
			meta += '</p>';
			return (
				'<article class="mw-public-playlists__card" data-mw-playlist-id="' +
				pid +
				'">' +
				'<div class="mw-public-playlists__frame">' +
				art +
				'<div class="mw-public-playlists__main"><h3 class="mw-public-playlists__name">' +
				escapeHtml( title ) +
				'</h3>' +
				meta +
				'<div class="mw-public-playlists__actions">' +
				playBtn +
				toggle +
				'</div></div></div><div class="mw-public-playlists__panel" id="' +
				panelId +
				'" hidden data-mw-public-panel data-playlist-id="' +
				pid +
				'"></div></article>'
			);
		}

		function renderPublicGrid( items, total, page, perPage, pages ) {
			if ( ! grid ) {
				// If grid doesn't exist (empty state), recreate container.
				var empty = publicRoot.querySelector(
					'.mw-public-playlists__empty'
				);
				if ( empty ) {
					empty.remove();
				}
				grid = document.createElement( 'div' );
				grid.className =
					'mw-public-playlists__grid mw-public-playlists__grid--' +
					( cardOptions.layout || 'grid' ) +
					' mw-public-playlists__grid--columns-' +
					( cardOptions.columns || 3 );
				publicRoot.appendChild( grid );
			}
			if ( ! items || ! items.length ) {
				grid.innerHTML = '';
				grid.hidden = true;
				var existingEmpty = publicRoot.querySelector(
					'.mw-public-playlists__empty'
				);
				if ( ! existingEmpty ) {
					var emptyDiv = document.createElement( 'div' );
					emptyDiv.className = 'mw-public-playlists__empty';
					emptyDiv.innerHTML =
						'<p>' +
						escapeHtml(
							labels.noResults || 'هیچ فهرست پخشی پیدا نشد.'
						) +
						'</p>';
					publicRoot.appendChild( emptyDiv );
				}
			} else {
				grid.hidden = false;
				var emptyEl = publicRoot.querySelector(
					'.mw-public-playlists__empty'
				);
				if ( emptyEl ) {
					emptyEl.remove();
				}
				grid.innerHTML = items.map( buildPublicCard ).join( '' );
			}
			// Update total
			if ( headerTotal ) {
				headerTotal.textContent = String( total );
			}
			// Pagination
			var pagination = publicRoot.querySelector(
				'.mw-public-playlists__pagination'
			);
			if ( pages > 1 && false !== cardOptions.showPagination ) {
				var paginationHtml = '';
				for ( var i = 1; i <= pages; i++ ) {
					if ( i === page ) {
						paginationHtml +=
							'<span class="mw-public-playlists__page is-active" aria-current="page">' +
							i +
							'</span>';
					} else {
						paginationHtml +=
							'<a class="mw-public-playlists__page" href="#" data-mw-public-page data-page="' +
							i +
							'">' +
							i +
							'</a>';
					}
				}
				if ( ! pagination ) {
					pagination = document.createElement( 'nav' );
					pagination.className = 'mw-public-playlists__pagination';
					pagination.setAttribute(
						'aria-label',
						labels.paginationLabel || 'صفحات فهرست پخش عمومی'
					);
					publicRoot.appendChild( pagination );
				}
				pagination.innerHTML = paginationHtml;
			} else if ( pagination ) {
				pagination.remove();
			}
			// Update URL without reload
			try {
				var url = new URL( window.location.href );
				if ( currentSearch ) {
					url.searchParams.set( 'mw-playlist-search', currentSearch );
				} else {
					url.searchParams.delete( 'mw-playlist-search' );
				}
				if ( page > 1 ) {
					url.searchParams.set( 'mw-playlists-page', String( page ) );
				} else {
					url.searchParams.delete( 'mw-playlists-page' );
				}
				url.searchParams.delete( 'mw-playlist' );
				window.history.replaceState(
					{ mwPublicSearch: currentSearch, mwPublicPage: page },
					'',
					url.toString()
				);
			} catch ( e ) {}
			setLive(
				( labels.showingCount || 'نمایش %1$d از %2$d فهرست پخش' )
					.replace( '%1$d', String( items ? items.length : 0 ) )
					.replace( '%2$d', String( total ) )
			);
		}

		function fetchPublic( page, search ) {
			if ( currentRequest && currentRequest.cancel ) {
				try {
					currentRequest.cancel();
				} catch ( e ) {}
			}
			var form = searchForm;
			var perPage = form
				? parseInt( form.getAttribute( 'data-per-page' ), 10 ) || 12
				: 12;
			var orderby = form
				? form.getAttribute( 'data-orderby' ) || 'updated_at'
				: 'updated_at';
			var path =
				'/music-wave/v1/playlists/public?page=' +
				page +
				'&per_page=' +
				perPage +
				'&orderby=' +
				encodeURIComponent( orderby );
			if ( search ) {
				path += '&search=' + encodeURIComponent( search );
			}
			currentSearch = search;
			setLive( 'در حال بارگذاری…' );
			// Show loading state on grid
			if ( grid ) {
				grid.style.opacity = '0.5';
				grid.classList.add( 'is-loading' );
			}
			var req = apiFetch( { path, method: 'GET' } );
			currentRequest = req;
			req.then( function ( data ) {
				if ( req !== currentRequest ) {
					return;
				}
				var items =
					data && Array.isArray( data.items ) ? data.items : [];
				var total =
					data && typeof data.total === 'number'
						? data.total
						: items.length;
				var pages =
					data && typeof data.pages === 'number'
						? data.pages
						: Math.ceil( total / perPage ) || 1;
				var respPage =
					data && typeof data.page === 'number' ? data.page : page;
				renderPublicGrid( items, total, respPage, perPage, pages );
			} )
				.catch( function ( error ) {
					setLive( errorMessage( error, labels.error ) );
				} )
				.finally( function () {
					if ( grid ) {
						grid.style.opacity = '';
						grid.classList.remove( 'is-loading' );
					}
				} );
		}

		// Search form submit — no reload
		if ( searchForm ) {
			searchForm.addEventListener( 'submit', function ( event ) {
				event.preventDefault();
				var val = searchInput
					? searchInput.value.trim().slice( 0, 60 )
					: '';
				fetchPublic( 1, val );
			} );
			// Instant search as you type (debounced)
			if ( searchInput ) {
				searchInput.addEventListener( 'input', function () {
					clearTimeout( debounceTimer );
					debounceTimer = setTimeout( function () {
						var val = searchInput.value.trim().slice( 0, 60 );
						// Only auto-search if length 0 or >=2 to avoid noisy single-char
						if ( val.length === 0 || val.length >= 2 ) {
							fetchPublic( 1, val );
						}
					}, 380 );
				} );
			}
		}

		// Clear search & Pagination — delegated, no reload (works for dynamic clear links too)
		publicRoot.addEventListener( 'click', function ( event ) {
			var clearLink = event.target.closest( '[data-mw-public-clear]' );
			if ( clearLink ) {
				event.preventDefault();
				if ( searchInput ) {
					searchInput.value = '';
				}
				fetchPublic( 1, '' );
				return;
			}
			var pageLink = event.target.closest( '[data-mw-public-page]' );
			if ( pageLink ) {
				event.preventDefault();
				var p =
					parseInt( pageLink.getAttribute( 'data-page' ), 10 ) || 1;
				var s = searchInput
					? searchInput.value.trim().slice( 0, 60 )
					: currentSearch;
				fetchPublic( p, s );
				// Scroll to top of grid for UX
				try {
					publicRoot.scrollIntoView( {
						behavior: 'smooth',
						block: 'start',
					} );
				} catch ( e ) {}
			}
		} );

		// Show tracks — instant without reload
		publicRoot.addEventListener( 'click', function ( event ) {
			var toggle = event.target.closest( '[data-mw-public-toggle]' );
			if ( ! toggle ) {
				return;
			}
			event.preventDefault();
			var pid =
				parseInt( toggle.getAttribute( 'data-playlist-id' ), 10 ) || 0;
			if ( ! pid ) {
				return;
			}
			var panelId = toggle.getAttribute( 'aria-controls' );
			var card = toggle.closest( '.mw-public-playlists__card' );
			var panel = null;
			if ( panelId ) {
				panel = document.getElementById( panelId );
			} else if ( card ) {
				panel = card.querySelector( '[data-mw-public-panel]' );
			}
			if ( ! panel ) {
				return;
			}
			var isExpanded = toggle.getAttribute( 'aria-expanded' ) === 'true';
			if ( isExpanded ) {
				toggle.setAttribute( 'aria-expanded', 'false' );
				toggle.textContent = labels.showTracks || 'نمایش قطعه‌ها';
				panel.hidden = true;
				return;
			}
			// Expand
			toggle.setAttribute( 'aria-expanded', 'true' );
			toggle.textContent = labels.hideTracks || 'پنهان‌کردن قطعه‌ها';
			if ( panel.getAttribute( 'data-loaded' ) === 'true' ) {
				panel.hidden = false;
				return;
			}
			panel.hidden = false;
			panel.innerHTML =
				'<p class="mw-public-playlists__loading">' +
				escapeHtml( labels.loading || 'در حال بارگذاری…' ) +
				'</p>';
			apiFetch( {
				path: '/music-wave/v1/playlists/' + pid + '/playback-queue',
				method: 'GET',
			} )
				.then( function ( data ) {
					var tracks =
						data && Array.isArray( data.tracks ) ? data.tracks : [];
					if ( ! tracks.length ) {
						panel.innerHTML =
							'<p class="mw-public-playlists__empty">' +
							escapeHtml(
								labels.emptyOrNotPlayable ||
									'این فهرست پخش خالی است یا قابل پخش نیست.'
							) +
							'</p>';
						panel.setAttribute( 'data-loaded', 'true' );
						return;
					}
					var html = '<div class="mw-public-playlists__tracks-list">';
					tracks.forEach( function ( track, idx ) {
						var title =
							track.title ||
							( labels.releaseFallback || 'انتشار #%d' ).replace(
								'%d',
								String( track.releaseId )
							);
						var trackAria = (
							labels.playTrackAria || 'پخش %s'
						).replace( '%s', title );
						var titleLink = track.link
							? '<a href="' +
							  escapeHtml( track.link ) +
							  '">' +
							  escapeHtml( title ) +
							  '</a>'
							: escapeHtml( title );
						// Track art and fallback removed per spec – clean minimal row with linked title/artist.
						html +=
							'<div class="mw-public-playlists__track" data-mw-playlist-track="' +
							track.releaseId +
							'"><span class="mw-public-playlists__position">' +
							( idx + 1 ) +
							'</span><span class="mw-public-playlists__track-title">' +
							titleLink +
							( track.artist
								? '<span class="mw-public-playlists__track-artist">' +
								  escapeHtml( track.artist ) +
								  '</span>'
								: '' ) +
							'</span><button type="button" class="mw-public-playlists__track-play mw-card-play" data-mw-release-id="' +
							track.releaseId +
							'" aria-pressed="false" aria-label="' +
							escapeHtml( trackAria ) +
							'"><span class="mw-card-play__icon" aria-hidden="true">▶</span></button></div>';
					} );
					html += '</div>';
					panel.innerHTML = html;
					panel.setAttribute( 'data-loaded', 'true' );
					syncPlayer();
				} )
				.catch( function ( error ) {
					var msg = errorMessage( error, labels.error );
					// If 404 means no playable audio, show friendly empty.
					if ( error && error.code === 'mw_playback_unavailable' ) {
						panel.innerHTML =
							'<p class="mw-public-playlists__empty">' +
							escapeHtml( labels.noPlayable || msg ) +
							'</p>';
					} else {
						panel.innerHTML =
							'<p class="mw-public-playlists__empty">' +
							escapeHtml( msg ) +
							'</p>';
					}
					panel.setAttribute( 'data-loaded', 'true' );
				} );
		} );

		activePublicController = {
			root: publicRoot,
			restore( state ) {
				var s = state.mwPublicSearch || '';
				var p = state.mwPublicPage || 1;
				if ( searchInput ) {
					searchInput.value = s;
				}
				fetchPublic( p, s );
			},
		};
	}

	window.addEventListener( 'popstate', function ( event ) {
		if (
			activePublicController &&
			document.body.contains( activePublicController.root ) &&
			event.state &&
			typeof event.state.mwPublicSearch !== 'undefined'
		) {
			activePublicController.restore( event.state );
		}
	} );

	/* ------------------------------------------------------------------ */
	/* Own playlist manager — mutations without page reloads               */
	/*                                                                    */
	/* Every control stays a real form/link so it keeps working without   */
	/* JavaScript. When JS is present we answer with REST calls and swap  */
	/* only the affected nodes instead of a full post/redirect/reload.    */
	/* ------------------------------------------------------------------ */
	function initPlaylistManager( root ) {
		if (
			! window.wp ||
			! window.wp.apiFetch ||
			! markContainerBound( root )
		) {
			return;
		}

		function api( options ) {
			options.headers = Object.assign(
				{},
				restHeaders(),
				options.headers || {}
			);
			return window.wp.apiFetch( options );
		}

		var statusTimer = null;

		function setStatus( message, isError ) {
			if ( ! message ) {
				return;
			}
			var existing = root.querySelector( '.mw-playlists__notice' );
			if ( ! existing ) {
				existing = document.createElement( 'p' );
				existing.className = 'mw-playlists__notice';
				existing.setAttribute( 'role', 'status' );
				existing.setAttribute( 'aria-live', 'polite' );
				var header = root.querySelector( '.mw-playlists__header' );
				root.insertBefore(
					existing,
					header ? header.nextSibling : root.firstChild
				);
			}
			existing.classList.toggle(
				'mw-playlists__notice--error',
				!! isError
			);
			existing.textContent = message;
			window.clearTimeout( statusTimer );
			statusTimer = window.setTimeout( function () {
				existing.textContent = '';
			}, 4000 );
		}

		function fail( error ) {
			setStatus( errorMessage( error, labels.error ), true );
		}

		function itemFor( playlistId ) {
			return root.querySelector(
				'.mw-playlists__item[data-mw-playlist-id="' + playlistId + '"]'
			);
		}

		function setCount( playlistId, count ) {
			var counter = root.querySelector(
				'[data-mw-playlist-count="' + playlistId + '"]'
			);
			if ( counter ) {
				counter.textContent = String( count );
			}
		}

		/* Show / hide tracks without reloading ---------------------------- */
		root.addEventListener( 'click', function ( event ) {
			var toggle = event.target.closest( '.mw-playlists__toggle' );
			if ( ! toggle ) {
				return;
			}
			event.preventDefault();

			var expanded = toggle.getAttribute( 'aria-expanded' ) === 'true';
			var panelId = toggle.getAttribute( 'aria-controls' );
			var panel = panelId ? document.getElementById( panelId ) : null;
			if ( ! panel ) {
				window.location.assign( toggle.href );

				return;
			}

			toggle.setAttribute( 'aria-expanded', expanded ? 'false' : 'true' );
			panel.hidden = expanded;

			if ( expanded ) {
				try {
					var clean = new window.URL( toggle.href );
					clean.searchParams.delete( 'mw-playlist' );
					clean.hash = '';
					window.history.replaceState( {}, '', clean.toString() );
				} catch ( e ) {}
				return;
			}

			toggle.classList.add( 'is-loading' );
			window
				.fetch( toggle.href, { credentials: 'same-origin' } )
				.then( function ( response ) {
					if ( ! response.ok ) {
						throw new Error( 'بارگذاری پنل ناموفق بود.' );
					}
					return response.text();
				} )
				.then( function ( html ) {
					var fresh = new window.DOMParser()
						.parseFromString( html, 'text/html' )
						.getElementById( panelId );
					panel.replaceChildren.apply(
						panel,
						fresh
							? Array.prototype.slice.call( fresh.childNodes )
							: []
					);
					syncPlayer();
				} )
				.catch( fail )
				.finally( function () {
					toggle.classList.remove( 'is-loading' );
				} );
		} );

		function trackIds( panel ) {
			return Array.prototype.map
				.call(
					panel.querySelectorAll( '[data-mw-playlist-track]' ),
					function ( row ) {
						return (
							parseInt(
								row.getAttribute( 'data-mw-playlist-track' ),
								10
							) || 0
						);
					}
				)
				.filter( function ( id ) {
					return id > 0;
				} );
		}

		/* Remove / reorder / rename / delete without reloading ------------- */
		document.addEventListener( 'submit', function ( event ) {
			var form = event.target.closest( '[data-mw-playlist-action]' );
			if ( ! form || ! root.contains( form ) ) {
				return;
			}
			var pidInput = form.querySelector( 'input[name="mw_playlist_id"]' );
			var playlistId = pidInput ? parseInt( pidInput.value, 10 ) || 0 : 0;
			if ( ! playlistId ) {
				return;
			}
			var action = form.getAttribute( 'data-mw-playlist-action' );
			var releaseField = form.querySelector(
				'input[name="mw_release_id"]'
			);
			var releaseId = releaseField
				? parseInt( releaseField.value, 10 ) || 0
				: 0;

			event.preventDefault();
			var submitButtons = form.querySelectorAll(
				'button[type="submit"]'
			);
			submitButtons.forEach( function ( button ) {
				button.disabled = true;
			} );

			var done = function () {
				submitButtons.forEach( function ( button ) {
					button.disabled = false;
				} );
			};

			if ( 'remove-item' === action && releaseId ) {
				// DELETE with JSON body is stripped by some proxies/CDNs — send as query string for robustness.
				api( {
					path:
						'/music-wave/v1/playlists/' +
						playlistId +
						'/items?release_id=' +
						encodeURIComponent( String( releaseId ) ),
					method: 'DELETE',
				} )
					.then( function () {
						var row = form.closest( '[data-mw-playlist-track]' );
						var panel = form.closest(
							'[data-mw-playlist-panel], .mw-playlists__panel'
						);
						if ( row ) {
							row.remove();
						}
						if ( panel ) {
							var count = trackIds( panel ).length;
							setCount( playlistId, count );
							if ( ! count ) {
								var empty = document.createElement( 'p' );
								empty.className = 'mw-playlists__empty';
								empty.textContent =
									labels.emptyPlaylist ||
									labels.noPlayable ||
									'';
								panel.textContent = '';
								panel.appendChild( empty );
							}
						}
						setStatus( labels.itemRemoved, false );
						syncPlayer();
					} )
					.catch( fail )
					.finally( done );

				return;
			}

			if ( 'move-up' === action || 'move-down' === action ) {
				var row = form.closest( '[data-mw-playlist-track]' );
				var list = row ? row.parentNode : null;
				if ( ! row || ! list ) {
					done();
					return;
				}
				var swapRow =
					'move-up' === action
						? row.previousElementSibling
						: row.nextElementSibling;
				if ( ! swapRow ) {
					done();
					return;
				}
				list.insertBefore(
					row,
					'move-up' === action ? swapRow : swapRow.nextSibling
				);
				syncPlayer();
				api( {
					path: '/music-wave/v1/playlists/' + playlistId + '/order',
					method: 'POST',
					data: { release_ids: trackIds( list ) },
				} )
					.then( function () {
						setStatus( labels.orderUpdated, false );
					} )
					.catch( function ( error ) {
						// Undo the optimistic swap.
						if ( 'move-up' === action ) {
							list.insertBefore( swapRow, row );
						} else {
							list.insertBefore( row, swapRow );
						}
						syncPlayer();
						fail( error );
					} )
					.finally( done );

				return;
			}

			if ( 'update' === action ) {
				var title =
					( form.querySelector( 'input[name="mw_title"]' ) || {} )
						.value || '';
				var visibility =
					(
						form.querySelector( 'select[name="mw_visibility"]' ) ||
						{}
					).value || '';
				api( {
					path: '/music-wave/v1/playlists/' + playlistId,
					method: 'POST',
					data: { title: title.trim(), visibility },
				} )
					.then( function ( updated ) {
						var card = itemFor( playlistId );
						var nextTitle =
							updated && updated.title
								? String( updated.title )
								: title.trim();
						var nextVis =
							updated && updated.visibility
								? String( updated.visibility )
								: visibility || 'private';
						var shareUrl =
							updated && updated.share_url
								? String( updated.share_url )
								: '';
						var shareToken =
							updated && updated.share_token
								? String( updated.share_token )
								: '';

						var name = root.querySelector(
							'[data-mw-playlist-name="' + playlistId + '"]'
						);
						if ( name && nextTitle ) {
							name.textContent = nextTitle;
						}

						if ( card ) {
							var badge = card.querySelector(
								'.mw-playlists__badge'
							);
							if ( badge ) {
								badge.className =
									'mw-playlists__badge mw-playlists__badge--' +
									nextVis;
								if ( 'public' === nextVis ) {
									badge.textContent =
										labels.visibilityPublic || 'عمومی';
								} else if ( 'unlisted' === nextVis ) {
									badge.textContent =
										labels.visibilityUnlisted ||
										'هر کسی پیوند';
								} else {
									badge.textContent =
										labels.visibilityPrivate || 'خصوصی';
								}
							}

							var playBtn = card.querySelector(
								'[data-mw-playlist-play]'
							);
							if ( playBtn ) {
								if ( shareToken ) {
									playBtn.setAttribute(
										'data-mw-share',
										shareToken
									);
								} else {
									playBtn.removeAttribute( 'data-mw-share' );
								}
							}

							var existingShare = card.querySelector(
								'.mw-playlists__share'
							);
							if ( 'private' === nextVis || ! shareUrl ) {
								if ( existingShare ) {
									existingShare.remove();
								}
							} else {
								var box = existingShare;
								if ( ! box ) {
									box = document.createElement( 'div' );
									box.className = 'mw-playlists__share';
									box.setAttribute(
										'data-mw-share-box',
										String( playlistId )
									);
									var delForm = card.querySelector(
										'.mw-playlists__delete'
									);
									if ( delForm && delForm.parentNode ) {
										delForm.parentNode.insertBefore(
											box,
											delForm.nextSibling
										);
									} else {
										card.appendChild( box );
									}
								}
								box.innerHTML = '';
								var lbl = document.createElement( 'label' );
								lbl.className = 'mw-playlists__share-label';
								lbl.appendChild(
									document.createTextNode(
										labels.shareLabel ||
											'پیوند را به‌اشتراک بگذارید'
									)
								);
								var rowWrap = document.createElement( 'span' );
								rowWrap.className = 'mw-playlists__share-row';

								var inp = document.createElement( 'input' );
								inp.type = 'url';
								inp.readOnly = true;
								inp.spellcheck = false;
								inp.value = shareUrl;
								inp.setAttribute( 'data-mw-share-input', '' );

								var copyBtn =
									document.createElement( 'button' );
								copyBtn.type = 'button';
								copyBtn.className = 'mw-playlists__share-copy';
								copyBtn.setAttribute(
									'data-mw-copy-share',
									shareUrl
								);
								copyBtn.textContent =
									labels.copyLink || 'کپی پیوند';

								var openLink = document.createElement( 'a' );
								openLink.className = 'mw-playlists__share-open';
								openLink.href = shareUrl;
								openLink.target = '_blank';
								openLink.rel = 'noopener';
								openLink.textContent =
									labels.openPublicPage || 'مشاهده صفحه ↗';

								rowWrap.appendChild( inp );
								rowWrap.appendChild( copyBtn );
								rowWrap.appendChild( openLink );
								lbl.appendChild( rowWrap );

								var hint = document.createElement( 'span' );
								hint.className = 'mw-playlists__share-hint';
								hint.textContent =
									labels.shareHint ||
									'هر کسی که این پیوند را داشته باشد می‌تواند فهرست پخش را بدون نیاز به ورود باز و پخش کند.';

								box.appendChild( lbl );
								box.appendChild( hint );
							}
						}

						setStatus( labels.updated, false );
					} )
					.catch( fail )
					.finally( done );

				return;
			}

			if ( 'delete' === action ) {
				// Two-step confirmation on the button itself: the first tap
				// arms it, the second tap deletes. Avoids window.confirm()
				// while staying keyboard- and screen-reader-friendly.
				var deleteButton = form.querySelector(
					'button[type="submit"]'
				);
				if ( 'true' !== form.getAttribute( 'data-mw-confirmed' ) ) {
					form.setAttribute( 'data-mw-confirmed', 'true' );
					if ( deleteButton ) {
						deleteButton.setAttribute(
							'data-mw-label',
							deleteButton.textContent
						);
						deleteButton.textContent =
							labels.confirmDelete || 'حذف تأیید شود';
					}
					window.setTimeout( function () {
						form.removeAttribute( 'data-mw-confirmed' );
						if ( deleteButton ) {
							deleteButton.textContent =
								deleteButton.getAttribute( 'data-mw-label' );
							deleteButton.removeAttribute( 'data-mw-label' );
						}
					}, 4000 );
					done();

					return;
				}
				var card = itemFor( playlistId );
				if ( card ) {
					card.classList.add( 'is-removing' );
				}
				api( {
					path: '/music-wave/v1/playlists/' + playlistId,
					method: 'DELETE',
				} )
					.then( function () {
						if ( card ) {
							window.setTimeout( function () {
								card.remove();
							}, 180 );
						}
						setStatus( labels.deleted, false );
						// Update header total.
						var totalEl = root.querySelector(
							'.mw-playlists__total'
						);
						if ( totalEl ) {
							var current = parseInt( totalEl.textContent, 10 );
							if ( ! isNaN( current ) && current > 0 ) {
								totalEl.textContent = String( current - 1 );
							}
						}
						// If last playlist removed, show empty state without reload.
						if ( ! root.querySelector( '.mw-playlists__item' ) ) {
							var list = root.querySelector(
								'.mw-playlists__list'
							);
							if ( list ) {
								list.remove();
							}
							if (
								! root.querySelector(
									'.mw-playlists__empty-state'
								)
							) {
								var empty = document.createElement( 'div' );
								empty.className = 'mw-playlists__empty-state';
								empty.innerHTML =
									'<div class="mw-playlists__empty-icon" aria-hidden="true">♫</div><p class="mw-playlists__empty">' +
									escapeHtml(
										labels.emptyPlaylist ||
											'هنوز هیچ فهرست پخشی ندارید.'
									) +
									'</p>';
								var createFormEl = root.querySelector(
									'.mw-playlists__create'
								);
								root.insertBefore(
									empty,
									createFormEl || null
								);
							}
						}
					} )
					.catch( function ( error ) {
						if ( card ) {
							card.classList.remove( 'is-removing' );
						}
						fail( error );
					} )
					.finally( done );
			}
		} );

		// Create playlist from the Your playlists manager — REST + instant DOM inject (no reload).
		document.addEventListener( 'submit', function ( event ) {
			var createForm = event.target.closest( '.mw-playlists__create' );
			if ( ! createForm || ! root.contains( createForm ) ) {
				return;
			}
			event.preventDefault();
			var titleInput = createForm.querySelector(
				'input[name="mw_title"]'
			);
			var title = titleInput ? titleInput.value.trim() : '';
			if ( ! title ) {
				if ( titleInput ) {
					titleInput.focus();
				}
				return;
			}
			var visSelect = createForm.querySelector(
				'select[name="mw_visibility"]'
			);
			var visibility = visSelect ? visSelect.value : 'private';
			var submitBtn = createForm.querySelector( 'button[type="submit"]' );
			if ( submitBtn ) {
				submitBtn.disabled = true;
				submitBtn.setAttribute( 'aria-busy', 'true' );
			}
			api( {
				path: '/music-wave/v1/playlists',
				method: 'POST',
				data: { title, visibility: visibility || 'private' },
			} )
				.then( function ( created ) {
					var newId =
						created && created.id ? parseInt( created.id, 10 ) : 0;
					if ( ! newId ) {
						throw { message: labels.error };
					}
					// Clear input and show success.
					if ( titleInput ) {
						titleInput.value = '';
					}
					setStatus( labels.created || labels.updated, false );
					// Inject a minimal card so the user sees the new playlist without reload.
					// Full card markup is complex (art grid, share, settings) — reload the list fragment via fetch for fidelity.
					var listEl = root.querySelector( '.mw-playlists__list' );
					if ( ! listEl ) {
						// First playlist — replace empty state with a list.
						var emptyState = root.querySelector(
							'.mw-playlists__empty-state'
						);
						if ( emptyState ) {
							emptyState.remove();
						}
						listEl = document.createElement( 'ul' );
						listEl.className = 'mw-playlists__list';
						root.insertBefore( listEl, createForm );
					}
					// Fetch the fresh page fragment to render the new card server-side (keeps PHP as source of truth).
					return window
						.fetch( window.location.href, {
							credentials: 'same-origin',
						} )
						.then( function ( resp ) {
							if ( ! resp.ok ) {
								throw new Error(
									'تازه‌سازی فهرست ناموفق بود.'
								);
							}
							return resp.text();
						} )
						.then( function ( html ) {
							var doc = new window.DOMParser().parseFromString(
								html,
								'text/html'
							);
							var freshRoot = doc.querySelector(
								'[data-mw-playlists]'
							);
							if ( freshRoot ) {
								var freshList = freshRoot.querySelector(
									'.mw-playlists__list'
								);
								if ( freshList && listEl ) {
									listEl.innerHTML = freshList.innerHTML;
								}
								var freshTotal = freshRoot.querySelector(
									'.mw-playlists__total'
								);
								var currentTotal = root.querySelector(
									'.mw-playlists__total'
								);
								if ( freshTotal && currentTotal ) {
									currentTotal.textContent =
										freshTotal.textContent;
								}
								syncPlayer();
							}
						} )
						.catch( function () {
							// Fallback: at least update total optimistically.
							var totalEl2 = root.querySelector(
								'.mw-playlists__total'
							);
							if ( totalEl2 ) {
								var cur = parseInt( totalEl2.textContent, 10 );
								if ( ! isNaN( cur ) ) {
									totalEl2.textContent = String( cur + 1 );
								}
							}
							// Inject a simple placeholder card.
							if (
								listEl &&
								! listEl.querySelector(
									'[data-mw-playlist-id="' + newId + '"]'
								)
							) {
								var li = document.createElement( 'li' );
								li.className = 'mw-playlists__item';
								li.setAttribute(
									'data-mw-playlist-id',
									String( newId )
								);
								li.innerHTML =
									'<div class="mw-playlists__card"><div class="mw-playlists__art" aria-hidden="true"><div class="mw-playlists__art-grid mw-playlists__art-grid--empty"><span class="mw-playlists__art-placeholder" aria-hidden="true">♫</span></div></div><div class="mw-playlists__main"><div class="mw-playlists__row"><h3 class="mw-playlists__name" data-mw-playlist-name="' +
									newId +
									'">' +
									escapeHtml( title ) +
									'</h3><p class="mw-playlists__meta"><span class="mw-playlists__badge mw-playlists__badge--private">' +
									escapeHtml( labels.private || 'خصوصی' ) +
									'</span> <span class="mw-playlists__count" data-mw-playlist-count="' +
									newId +
									'">0 ' +
									escapeHtml(
										labels.trackPlural || 'قطعه‌ها'
									) +
									'</span></p></div></div></div>';
								listEl.appendChild( li );
							}
						} );
				} )
				.catch( fail )
				.finally( function () {
					if ( submitBtn ) {
						submitBtn.disabled = false;
						submitBtn.removeAttribute( 'aria-busy' );
					}
				} );
		} );
	}

	function hydratePlaylistSurfaces() {
		document
			.querySelectorAll( '[data-mw-public-playlists]' )
			.forEach( initPublicPlaylists );
		document
			.querySelectorAll( '[data-mw-playlists]' )
			.forEach( initPlaylistManager );
	}

	hydratePlaylistSurfaces();
	document.addEventListener( 'mw-page-rendered', hydratePlaylistSurfaces );

	/* ------------------------------------------------------------------ */
	/* Play all fallback (if preview-player not handling)                 */
	/* ------------------------------------------------------------------ */
	// Preview-player now handles [data-mw-playlist-play] natively.
	// This fallback ensures a toast if that script is blocked and provides
	// a non-JS accessible alternative: the buttons are already rendered and
	// keyboard operable; this only adds loading semantics when preview-player
	// is absent.
	function announce( message ) {
		var toast = document.querySelector( '[data-mw-playlist-toast]' );
		if ( ! toast ) {
			toast = document.createElement( 'p' );
			toast.className = 'mw-playlists__notice';
			toast.setAttribute( 'data-mw-playlist-toast', '' );
			toast.setAttribute( 'role', 'status' );
			toast.setAttribute( 'aria-live', 'polite' );
			document.body.appendChild( toast );
		}
		toast.textContent = message || '';
		window.setTimeout( function () {
			toast.textContent = '';
		}, 4000 );
	}

	function fallbackResolveStreamUrl( track ) {
		if ( track.full && track.quality ) {
			return apiFetch( {
				path:
					'/music-wave/v1/releases/' +
					track.releaseId +
					'/download-token?quality=' +
					encodeURIComponent( track.quality ) +
					'&purpose=stream',
				method: 'POST',
			} ).then( function ( response ) {
				var url =
					( settings.restUrl || '/wp-json/' ) +
					'music-wave/v1/streams/' +
					track.releaseId +
					'?token=' +
					encodeURIComponent( response.token ) +
					'&nonce=' +
					encodeURIComponent( response.nonce );
				if ( response.rest_nonce ) {
					url +=
						'&_wpnonce=' +
						encodeURIComponent( response.rest_nonce );
				}
				return url;
			} );
		}
		if ( track.previewUrl ) {
			return Promise.resolve( track.previewUrl );
		}
		return Promise.reject( { message: labels.noPlayable || labels.error } );
	}

	document.addEventListener( 'click', function ( event ) {
		if ( event.defaultPrevented ) {
			return;
		}
		var btn = event.target.closest( '[data-mw-playlist-play]' );
		if ( ! btn || btn.disabled ) {
			return;
		}
		// Delegate to global queue controller whenever available.
		if (
			window._mwPreviewController &&
			window._mwPreviewController.playPlaylist
		) {
			event.preventDefault();
			window._mwPreviewController.playPlaylist(
				btn,
				parseInt( btn.getAttribute( 'data-playlist-id' ), 10 ) || 0,
				btn.getAttribute( 'data-mw-share' ) || ''
			);
			return;
		}
		// Standalone fallback when custom theme template omits global player markup.
		var playlistId =
			parseInt( btn.getAttribute( 'data-playlist-id' ), 10 ) || 0;
		if ( ! playlistId || ! window.wp || ! window.wp.apiFetch ) {
			return;
		}
		event.preventDefault();
		var share = btn.getAttribute( 'data-mw-share' ) || '';
		var path =
			'/music-wave/v1/playlists/' +
			playlistId +
			'/playback-queue' +
			( share ? '?share=' + encodeURIComponent( share ) : '' );
		btn.classList.add( 'is-loading' );
		btn.setAttribute( 'aria-busy', 'true' );
		apiFetch( { path, method: 'GET' } )
			.then( function ( data ) {
				var tracks =
					data && Array.isArray( data.tracks ) ? data.tracks : [];
				if ( ! tracks.length ) {
					throw { message: labels.noPlayable };
				}
				var audio = document.querySelector(
					'[data-mw-preview-player] audio, [data-mw-fallback-audio]'
				);
				if ( ! audio ) {
					audio = document.createElement( 'audio' );
					audio.controls = true;
					audio.setAttribute( 'data-mw-fallback-audio', '1' );
					audio.style.position = 'fixed';
					audio.style.bottom = '1rem';
					audio.style.left = '1rem';
					audio.style.right = '1rem';
					audio.style.zIndex = '100';
					document.body.appendChild( audio );
				}
				return fallbackResolveStreamUrl( tracks[ 0 ] ).then(
					function ( url ) {
						audio.src = url;
						return audio.play().catch( function () {} );
					}
				);
			} )
			.catch( function ( error ) {
				announce( errorMessage( error, labels.error ) );
			} )
			.finally( function () {
				btn.classList.remove( 'is-loading' );
				btn.removeAttribute( 'aria-busy' );
			} );
	} );

	/* 1-click Copy Share URL across account manager & public shared page ---- */
	document.addEventListener( 'click', function ( event ) {
		var btn =
			event.target && event.target.closest
				? event.target.closest( '[data-mw-copy-share]' )
				: null;
		if ( ! btn ) {
			return;
		}
		event.preventDefault();

		var url = btn.getAttribute( 'data-mw-copy-share' ) || '';
		var wrap = btn.closest( '.mw-playlists__share' );
		var input = wrap ? wrap.querySelector( '[data-mw-share-input]' ) : null;
		if ( ! url && input ) {
			url = input.value || '';
		}
		if ( ! url ) {
			return;
		}

		var markCopied = function () {
			if ( ! btn.hasAttribute( 'data-orig-label' ) ) {
				btn.setAttribute( 'data-orig-label', btn.textContent || '' );
			}
			btn.textContent = labels.linkCopied || 'کپی شد ✓';
			window.setTimeout( function () {
				var orig = btn.getAttribute( 'data-orig-label' );
				if ( orig ) {
					btn.textContent = orig;
					btn.removeAttribute( 'data-orig-label' );
				}
			}, 2200 );
		};

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard
				.writeText( url )
				.then( markCopied )
				.catch( function () {
					if ( input ) {
						input.focus();
						input.select();
						try {
							document.execCommand( 'copy' );
							markCopied();
						} catch ( e ) {}
					}
				} );
			return;
		}

		if ( input ) {
			input.focus();
			input.select();
			try {
				document.execCommand( 'copy' );
				markCopied();
			} catch ( e ) {}
		}
	} );
} )();
