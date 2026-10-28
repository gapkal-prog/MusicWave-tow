( function () {
	'use strict';

	if ( window._mwPreviewRuntimeInitialized ) {
		return;
	}
	window._mwPreviewRuntimeInitialized = true;

	var selector = '[data-mw-preview-player]';
	var initialSettings = window.musicWavePreviewPlayer || {};
	var playerController = null;

	function currentSettings() {
		return window.musicWavePreviewPlayer || initialSettings;
	}

	function currentLabels() {
		var cfg = currentSettings();
		return cfg && cfg.labels ? cfg.labels : {};
	}

	var labels =
		typeof window.Proxy === 'function'
			? new window.Proxy(
					{},
					{
						get( target, prop ) {
							var active = currentLabels();
							return active[ prop ];
						},
					}
			  )
			: initialSettings.labels || {};

	/**
	 * Legacy glyph swap for text-only play triggers (`▶` / `❚❚`).
	 *
	 * Vector triggers now ship both states inside `.mw-icon-swap` and are
	 * toggled purely by CSS from `aria-pressed` / `data-mw-playing`, so the
	 * glyph rewrite must never clobber that SVG markup.
	 */
	function setPlayGlyph( icon, active ) {
		if ( ! icon || icon.querySelector( '.mw-icon-swap' ) ) {
			return;
		}
		icon.textContent = active ? '❚❚' : '▶';
	}

	function trackFromButton( button ) {
		var explicitId =
			parseInt(
				button.getAttribute( 'data-mw-release-id' ) ||
					button.getAttribute( 'data-release-id' ),
				10
			) || 0;
		return {
			releaseId: explicitId,
			rootId: explicitId,
			url: button.getAttribute( 'data-preview-url' ) || '',
			title: button.getAttribute( 'data-preview-title' ) || '',
			artist: button.getAttribute( 'data-preview-artist' ) || '',
			image: button.getAttribute( 'data-preview-image' ) || '',
			link: button.getAttribute( 'data-preview-link' ) || '',
			previewLimit:
				parseInt( button.getAttribute( 'data-preview-limit' ), 10 ) ||
				30,
			full: false,
			quality: '',
			limited: false,
			lyricsLrc: '',
			lyricsOffset: 0,
			hasLyrics: false,
		};
	}

	function trackFromApi( item, meta ) {
		var full = Boolean( item.full && item.quality );
		return {
			releaseId: parseInt( item.releaseId, 10 ) || 0,
			rootId: parseInt( item.rootId, 10 ) || 0,
			url: full ? '' : item.previewUrl || '',
			title: item.title || '',
			artist: item.artist || '',
			image:
				item.image ||
				( meta && meta.release ? meta.release.image : '' ),
			link: item.link || '',
			previewLimit: full ? 0 : parseInt( item.previewLimit, 10 ) || 30,
			full,
			quality: full ? item.quality : '',
			fileKey: item.fileKey || '',
			singleFile: false,
			limited: false,
			lyricsLrc: item.lyricsLrc || '',
			lyricsOffset: parseInt( item.lyricsOffset, 10 ) || 0,
			hasLyrics: Boolean( item.hasLyrics ) || Boolean( item.lyricsLrc ),
		};
	}

	function secureButtonQuality( button ) {
		if (
			window._mwDownloadControls &&
			typeof window._mwDownloadControls.selectedQuality === 'function'
		) {
			return window._mwDownloadControls.selectedQuality( button ) || '';
		}
		var row =
			button && button.closest
				? button.closest(
						'.mw-download-file-row, .mw-download-inline, .mw-collection-list__row, .mw-download-action'
				  )
				: null;
		var select = row ? row.querySelector( '.mw-download-quality' ) : null;
		if ( select && select.value ) {
			return select.value;
		}
		return button && button.getAttribute
			? button.getAttribute( 'data-download-quality' ) || ''
			: '';
	}

	function syncSecureButton( button, active ) {
		if (
			window._mwDownloadControls &&
			typeof window._mwDownloadControls.setPlayLabel === 'function'
		) {
			window._mwDownloadControls.setPlayLabel( button, active );
			return;
		}
		var settings = window.musicWaveDownload || {};
		var playText =
			button.getAttribute( 'data-play-label' ) ||
			settings.playLabel ||
			'پخش';
		var pauseText =
			button.getAttribute( 'data-pause-label' ) ||
			settings.pauseLabel ||
			'مکث';
		var text = active ? pauseText : playText;
		var trackTitle = button.getAttribute( 'data-mw-track-title' ) || '';
		var icon = button.querySelector( '.mw-secure-play-button__icon' );
		var label = button.querySelector( '.mw-secure-play-button__label' );

		button.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
		button.setAttribute(
			'aria-label',
			trackTitle ? text + ' ' + trackTitle : text
		);
		setPlayGlyph( icon, active );
		if ( label ) {
			label.textContent = text;
		} else if ( ! icon ) {
			button.textContent = text;
		}
	}

	function trackFromSecureButton( button, quality ) {
		var releaseId =
			parseInt( button.getAttribute( 'data-release-id' ), 10 ) || 0;
		var row = button.closest
			? button.closest( '.mw-download-file-row' )
			: null;
		var rowTitle = row
			? row.querySelector( '.mw-download-file-row__title strong' )
			: null;
		var title =
			button.getAttribute( 'data-mw-track-title' ) ||
			( rowTitle ? rowTitle.textContent.trim() : '' );
		return {
			releaseId,
			rootId: releaseId,
			url: '',
			title: title || '',
			artist: button.getAttribute( 'data-mw-track-artist' ) || '',
			image: button.getAttribute( 'data-mw-track-image' ) || '',
			link: button.getAttribute( 'data-mw-track-link' ) || '',
			previewLimit: 0,
			full: true,
			quality,
			fileKey: button.getAttribute( 'data-mw-file-key' ) || '',
			singleFile: true,
			limited: false,
			lyricsLrc: '',
			lyricsOffset: 0,
			hasLyrics: false,
		};
	}

	function parseLrc( raw ) {
		var lines = [];
		String( raw || '' )
			.split( /\r\n|\r|\n/ )
			.forEach( function ( row ) {
				row = row.trim();
				if ( ! row || /^\[[a-z]+:/i.test( row ) ) {
					return;
				}
				var stamps = [];
				var stampRe = /\[(\d{1,2}):(\d{2})(?:\.(\d{1,3}))?\]/g;
				var match;
				while ( ( match = stampRe.exec( row ) ) ) {
					stamps.push( match );
				}
				if ( stamps.length ) {
					var text = row
						.replace( /\[\d{1,2}:\d{2}(?:\.\d{1,3})?\]/g, '' )
						.trim();
					if ( ! text ) {
						return;
					}
					var parts = text.split( /\s*(?:\||\/\/)\s+/ );
					var primary = ( parts[ 0 ] || text ).trim();
					var translation = ( parts[ 1 ] || '' ).trim();
					stamps.forEach( function ( stamp ) {
						var ms = stamp[ 3 ]
							? parseFloat( '0.' + stamp[ 3 ] )
							: 0;
						lines.push( {
							time:
								parseInt( stamp[ 1 ], 10 ) * 60 +
								parseInt( stamp[ 2 ], 10 ) +
								ms,
							text: primary,
							translation,
						} );
					} );
					return;
				}
				lines.push( { time: -1, text: row, translation: '' } );
			} );
		lines.sort( function ( a, b ) {
			return a.time - b.time;
		} );
		return lines;
	}

	function formatLyricClock( seconds ) {
		if ( seconds < 0 ) {
			return '';
		}
		var minutes = Math.floor( seconds / 60 );
		var remain = Math.floor( seconds ) % 60;
		return (
			String( minutes ).padStart( 2, '0' ) +
			':' +
			String( remain ).padStart( 2, '0' )
		);
	}

	function formatTime( seconds ) {
		seconds = Math.max( 0, Math.floor( seconds || 0 ) );
		return (
			Math.floor( seconds / 60 ) +
			':' +
			String( seconds % 60 ).padStart( 2, '0' )
		);
	}

	function restHeaders() {
		var cfg = currentSettings();
		var nonce =
			( cfg && cfg.restNonce ) ||
			( window.musicWaveListening &&
				window.musicWaveListening.restNonce ) ||
			( window.musicWavePlaylists &&
				window.musicWavePlaylists.restNonce ) ||
			'';
		return nonce ? { 'X-WP-Nonce': nonce } : {};
	}

	function fetchReleaseQueue( releaseId ) {
		if ( ! window.wp || ! window.wp.apiFetch ) {
			return Promise.reject( { message: labels.error } );
		}
		return window.wp.apiFetch( {
			path: '/music-wave/v1/releases/' + releaseId + '/playback-queue',
			method: 'GET',
			headers: restHeaders(),
		} );
	}

	function fetchPlaylistQueue( playlistId, share ) {
		if ( ! window.wp || ! window.wp.apiFetch ) {
			return Promise.reject( { message: labels.error } );
		}
		var path = '/music-wave/v1/playlists/' + playlistId + '/playback-queue';
		if ( share ) {
			path += '?share=' + encodeURIComponent( share );
		} else {
			try {
				var currentUrl = new URL( window.location.href );
				var urlShare = currentUrl.searchParams.get( 'mw-share' );
				if ( urlShare ) {
					path += '?share=' + encodeURIComponent( urlShare );
				}
			} catch ( e ) {}
		}
		return window.wp.apiFetch( {
			path,
			method: 'GET',
			headers: restHeaders(),
		} );
	}

	function fetchStreamToken( track ) {
		return window.wp.apiFetch( {
			path:
				'/music-wave/v1/releases/' +
				track.releaseId +
				'/download-token?quality=' +
				encodeURIComponent( track.quality ) +
				'&purpose=stream',
			method: 'POST',
			headers: restHeaders(),
		} );
	}

	function streamUrl( track, response ) {
		var cfg = currentSettings();
		var base =
			( cfg && cfg.restUrl ) ||
			( window.musicWavePlaylists &&
				window.musicWavePlaylists.restUrl ) ||
			'/wp-json/';
		var url = base + 'music-wave/v1/streams/' + track.releaseId;
		url +=
			'?token=' +
			encodeURIComponent( response.token ) +
			'&nonce=' +
			encodeURIComponent( response.nonce );
		if ( response.rest_nonce ) {
			url += '&_wpnonce=' + encodeURIComponent( response.rest_nonce );
		}
		return url;
	}

	function errorMessage( error, fallback ) {
		if (
			error &&
			( error.status === 401 ||
				error.code === 'mw_authentication_required' )
		) {
			return labels.sessionError || fallback;
		}
		return error && error.message ? error.message : fallback;
	}

	function initialize( player ) {
		if ( player.getAttribute( 'data-mw-ready' ) ) {
			return;
		}
		player.setAttribute( 'data-mw-ready', '1' );
		var audio = player.querySelector( 'audio' );
		var title = player.querySelector( '.mw-global-player__title' );
		var artist = player.querySelector( '.mw-global-player__artist' );
		var art = player.querySelector( '.mw-global-player__art' );
		var toggle = player.querySelector( '.mw-global-player__toggle' );
		var previous = player.querySelector( '.mw-global-player__previous' );
		var next = player.querySelector( '.mw-global-player__next' );
		var close = player.querySelector( '.mw-global-player__close' );
		var progress = player.querySelector(
			'.mw-global-player__progress input'
		);
		var time = player.querySelector( '.mw-global-player__time' );
		var duration = player.querySelector( '.mw-global-player__duration' );
		var volume = player.querySelector( 'input.mw-global-player__volume' );
		var mute = player.querySelector( '.mw-global-player__mute' );
		var queueToggle = player.querySelector(
			'.mw-global-player__queue-toggle'
		);
		var queuePanel = player.querySelector( '[data-mw-queue]' );
		var queueList = player.querySelector( '.mw-global-player__queue-list' );
		var queueClose = player.querySelector(
			'.mw-global-player__queue-close'
		);
		var lyricsToggle = player.querySelector(
			'.mw-global-player__lyrics-toggle'
		);
		var lyricsPanel = player.querySelector( '[data-mw-player-lyrics]' );
		var lyricsClose = player.querySelector(
			'.mw-global-player__lyrics-close'
		);
		var lyricsRoot = player.querySelector( '[data-mw-lyrics]' );
		var lyricsStage = player.querySelector( '[data-mw-lyrics-stage]' );
		var notice = player.querySelector( '.mw-global-player__notice' );
		var noticeMessage = player.querySelector(
			'.mw-global-player__notice-message'
		);
		var noticeCta = player.querySelector( '.mw-global-player__notice-cta' );
		var noticeLogin = player.querySelector(
			'.mw-global-player__notice-login'
		);
		var noticeClose = player.querySelector(
			'.mw-global-player__notice-close'
		);
		var queue = [];
		var queueMeta = null;
		var current = -1;
		var busy = false;

		function currentTrack() {
			return current >= 0 && queue[ current ] ? queue[ current ] : null;
		}

		function playGlyph() {
			return audio.paused ? '▶' : '❚❚';
		}

		// The play/pause button ships one SVG per state (play, pause,
		// spinner); the stylesheet reveals the icon named by data-state.
		// Legacy markup without icons keeps the text glyph so the control
		// never renders empty.
		var buffering = false;

		function renderToggle() {
			var waiting = busy || buffering;
			var state = 'playing';
			if ( waiting ) {
				state = 'loading';
			} else if ( audio.paused ) {
				state = 'paused';
			}
			toggle.setAttribute( 'data-state', state );
			toggle.setAttribute( 'aria-busy', busy ? 'true' : 'false' );
			if ( ! toggle.querySelector( 'svg' ) ) {
				toggle.textContent = waiting ? '…' : playGlyph();
			}
		}

		function setLoading( loading ) {
			busy = loading;
			renderToggle();
		}

		// Elapsed / total, mirrored into aria-valuetext so the range input
		// announces "0:42 / 3:10" instead of a bare percentage. The total is
		// the full stream length (the preview limit is explained by the
		// upsell notice, not by shortening the timeline).
		function renderTime() {
			var total = audio.duration || 0;
			var elapsed = formatTime( audio.currentTime );
			var length = total ? formatTime( total ) : '';
			if ( duration ) {
				time.textContent = elapsed;
				duration.textContent = length || '–:––';
			} else {
				time.textContent = elapsed + ( length ? ' / ' + length : '' );
			}
			progress.setAttribute(
				'aria-valuetext',
				length ? elapsed + ' / ' + length : elapsed
			);
		}

		// layout.css keeps page content and sticky rails clear of the bar by
		// reading --mw-player-offset from <html>. The stylesheet ships a rem
		// estimate; measuring the rendered footprint (height plus the gap to
		// the viewport edge) covers zoom, font size, and the docked phone
		// layout. Transforms from the entrance animation do not affect
		// offsetHeight or the computed inset, so this is safe mid-animation.
		function measureFootprint() {
			var root = document.documentElement;
			if ( player.hidden ) {
				root.style.removeProperty( '--mw-player-offset' );
				return;
			}
			var inset =
				parseFloat( window.getComputedStyle( player ).bottom ) || 0;
			var covered = player.offsetHeight + inset;
			if ( covered > 0 ) {
				root.style.setProperty(
					'--mw-player-offset',
					Math.ceil( covered ) + 'px'
				);
			}
		}

		function setPresence( active ) {
			document.documentElement.classList.toggle(
				'mw-has-player',
				active
			);
			if ( window.requestAnimationFrame ) {
				window.requestAnimationFrame( measureFootprint );
			} else {
				measureFootprint();
			}
		}

		if ( 'ResizeObserver' in window ) {
			new window.ResizeObserver( measureFootprint ).observe( player );
		} else {
			window.addEventListener( 'resize', measureFootprint );
		}

		function updateButtons() {
			var track = currentTrack();
			var playing = Boolean( track ) && ! audio.paused;
			var fullReleaseQueueId =
				queueMeta && queueMeta.release
					? parseInt( queueMeta.release.id, 10 ) || 0
					: 0;
			document
				.querySelectorAll( '.mw-secure-play-button' )
				.forEach( function ( button ) {
					var secReleaseId =
						parseInt(
							button.getAttribute( 'data-release-id' ),
							10
						) || 0;
					var secQuality = secureButtonQuality( button );
					var active = Boolean(
						playing &&
							track.full &&
							secReleaseId > 0 &&
							track.releaseId === secReleaseId &&
							secQuality !== '' &&
							track.quality === secQuality
					);
					syncSecureButton( button, active );
					var fileRow = button.closest
						? button.closest( '.mw-download-file-row' )
						: null;
					if ( fileRow ) {
						if ( active ) {
							fileRow.setAttribute( 'data-mw-playing', 'true' );
						} else {
							fileRow.removeAttribute( 'data-mw-playing' );
						}
					}
				} );
			document
				.querySelectorAll( '.mw-preview-button' )
				.forEach( function ( button ) {
					// Two trigger shapes share the class: preview-url buttons
					// match the playing URL, while release-queue buttons (the
					// hero "پخش الان" / "پخش همه" trigger) match either the
					// loaded full release queue ("all") or single release id.
					var active = false;
					if ( playing ) {
						var releaseId =
							parseInt(
								button.getAttribute( 'data-mw-release-id' ),
								10
							) || 0;
						if ( releaseId > 0 ) {
							if (
								button.getAttribute( 'data-mw-play-mode' ) ===
								'all'
							) {
								active = fullReleaseQueueId === releaseId;
							} else {
								active =
									track.rootId === releaseId ||
									track.releaseId === releaseId;
							}
						} else {
							active =
								! track.full &&
								track.url !== '' &&
								button.getAttribute( 'data-preview-url' ) ===
									track.url;
						}
					}
					button.setAttribute(
						'aria-pressed',
						active ? 'true' : 'false'
					);
					var icon = button.querySelector(
						'.mw-preview-button__icon'
					);
					setPlayGlyph( icon, active );
				} );
			document
				.querySelectorAll( '.mw-card-play[data-mw-release-id]' )
				.forEach( function ( button ) {
					var releaseId =
						parseInt(
							button.getAttribute( 'data-mw-release-id' ),
							10
						) || 0;
					var active = false;
					if ( playing && releaseId > 0 ) {
						if (
							button.getAttribute( 'data-mw-play-mode' ) === 'all'
						) {
							active = fullReleaseQueueId === releaseId;
						} else {
							active =
								track.rootId === releaseId ||
								track.releaseId === releaseId;
						}
					}
					button.setAttribute(
						'aria-pressed',
						active ? 'true' : 'false'
					);
					var icon = button.querySelector( '.mw-card-play__icon' );
					setPlayGlyph( icon, active );
				} );
			// Now-playing rows: flag the list item that owns the active
			// trigger so the theme can light up an equalizer (CSS-only).
			document
				.querySelectorAll(
					'.mw-collection-list__row, .mw-collection-list__item, .mw-global-player__queue-item, .mw-public-playlists__track, .mw-playlists__track'
				)
				.forEach( function ( row ) {
					var trigger = row.querySelector(
						'.mw-secure-play-button, ' +
							'.mw-preview-button[data-preview-url], ' +
							'.mw-preview-button[data-mw-release-id], ' +
							'.mw-card-play[data-mw-release-id]'
					);
					var active = false;
					if ( trigger && playing ) {
						if ( trigger.matches( '.mw-secure-play-button' ) ) {
							var inlineReleaseId =
								parseInt(
									trigger.getAttribute( 'data-release-id' ),
									10
								) || 0;
							var inlineQuality = secureButtonQuality( trigger );
							active = Boolean(
								track.full &&
									inlineReleaseId > 0 &&
									track.releaseId === inlineReleaseId &&
									inlineQuality !== '' &&
									track.quality === inlineQuality
							);
						} else if (
							trigger.matches(
								'.mw-card-play[data-mw-release-id], .mw-preview-button[data-mw-release-id]'
							)
						) {
							var rowReleaseId =
								parseInt(
									trigger.getAttribute(
										'data-mw-release-id'
									),
									10
								) || 0;
							active =
								rowReleaseId > 0 &&
								( track.rootId === rowReleaseId ||
									track.releaseId === rowReleaseId );
						} else {
							active =
								! track.full &&
								track.url !== '' &&
								trigger.getAttribute( 'data-preview-url' ) ===
									track.url;
						}
					}
					if ( active ) {
						row.setAttribute( 'data-mw-playing', 'true' );
					} else {
						row.removeAttribute( 'data-mw-playing' );
					}
				} );
			// Playlist Play all buttons: active when current queue is that playlist
			var playlistId =
				queueMeta && queueMeta.playlist
					? parseInt( queueMeta.playlist.id, 10 )
					: 0;
			document
				.querySelectorAll( '[data-mw-playlist-play]' )
				.forEach( function ( button ) {
					var btnId =
						parseInt(
							button.getAttribute( 'data-playlist-id' ) ||
								button.getAttribute( 'data-mw-playlist-id' ),
							10
						) || 0;
					var active =
						playing && playlistId > 0 && btnId === playlistId;
					button.setAttribute(
						'aria-pressed',
						active ? 'true' : 'false'
					);
					var icon = button.querySelector(
						'.mw-playlists__play-icon'
					);
					setPlayGlyph( icon, active );
				} );
		}

		function renderQueuePanel() {
			if ( ! queueToggle || ! queuePanel || ! queueList ) {
				return;
			}
			queueToggle.hidden = queue.length < 2;
			queueList.replaceChildren();
			queue.forEach( function ( track, index ) {
				var item = document.createElement( 'li' );
				var button = document.createElement( 'button' );
				button.type = 'button';
				button.className = 'mw-global-player__queue-item';
				if ( index === current ) {
					button.setAttribute( 'aria-current', 'true' );
				}
				var position = document.createElement( 'span' );
				position.className = 'mw-global-player__queue-position';
				position.textContent = String( index + 1 );
				var name = document.createElement( 'span' );
				name.className = 'mw-global-player__queue-title';
				name.textContent = track.title;
				button.appendChild( position );
				button.appendChild( name );
				if ( ! track.full ) {
					var badge = document.createElement( 'span' );
					badge.className = 'mw-global-player__queue-badge';
					badge.textContent = labels.previewBadge || 'پیش‌نمایش';
					button.appendChild( badge );
				}
				button.addEventListener( 'click', function () {
					select( index, true );
				} );
				item.appendChild( button );
				queueList.appendChild( item );
			} );
		}

		function setQueueOpen( open ) {
			if ( ! queuePanel || ! queueToggle ) {
				return;
			}
			if ( open ) {
				setLyricsOpen( false );
			}
			queuePanel.hidden = ! open;
			queueToggle.setAttribute(
				'aria-expanded',
				open ? 'true' : 'false'
			);
		}

		function setLyricsOpen( open ) {
			if ( ! lyricsPanel || ! lyricsToggle ) {
				return;
			}
			if ( open ) {
				if ( queuePanel && queueToggle ) {
					queuePanel.hidden = true;
					queueToggle.setAttribute( 'aria-expanded', 'false' );
				}
				renderLyricsPanel();
			}
			lyricsPanel.hidden = ! open;
			lyricsToggle.setAttribute(
				'aria-expanded',
				open ? 'true' : 'false'
			);
		}

		function renderLyricsPanel() {
			if (
				! lyricsToggle ||
				! lyricsPanel ||
				! lyricsStage ||
				! lyricsRoot
			) {
				return;
			}
			var track = currentTrack();
			var raw = track && track.lyricsLrc ? String( track.lyricsLrc ) : '';
			var has = Boolean( track && ( track.hasLyrics || raw.trim() ) );
			lyricsToggle.hidden = ! has;
			if ( ! has ) {
				setLyricsOpen( false );
				lyricsStage.replaceChildren();
				return;
			}
			lyricsRoot.setAttribute(
				'data-mw-lyric-offset',
				String( track.lyricsOffset || 0 )
			);
			if ( track.releaseId ) {
				lyricsRoot.setAttribute(
					'data-release-id',
					String( track.releaseId )
				);
			}
			var lines = parseLrc( raw );
			lyricsStage.replaceChildren();
			lines.forEach( function ( line, index ) {
				var timed = line.time >= 0;
				var node = document.createElement( timed ? 'button' : 'p' );
				if ( timed ) {
					node.type = 'button';
					node.setAttribute(
						'data-mw-lyric-time',
						String( line.time )
					);
				}
				node.className = 'mw-lyrics__line';
				node.setAttribute( 'data-mw-lyric-index', String( index ) );
				var text = document.createElement( 'span' );
				text.className = 'mw-lyrics__text';
				text.textContent = line.text;
				node.appendChild( text );
				if ( line.translation ) {
					var sub = document.createElement( 'span' );
					sub.className = 'mw-lyrics__sub';
					sub.textContent = line.translation;
					node.appendChild( sub );
				}
				if ( timed ) {
					var stamp = document.createElement( 'span' );
					stamp.className = 'mw-lyrics__stamp';
					stamp.textContent = formatLyricClock( line.time );
					node.appendChild( stamp );
				}
				lyricsStage.appendChild( node );
			} );
		}

		function hideNotice() {
			if ( notice ) {
				notice.hidden = true;
			}
		}

		function showNotice( message, upsell ) {
			if ( ! notice || ! noticeMessage ) {
				return;
			}
			noticeMessage.textContent = message;
			if ( noticeCta ) {
				var hasCta = Boolean(
					upsell && upsell.ctaUrl && upsell.ctaLabel
				);
				noticeCta.hidden = ! hasCta;
				if ( hasCta ) {
					noticeCta.href = upsell.ctaUrl;
					noticeCta.textContent = upsell.ctaLabel;
				}
			}
			if ( noticeLogin ) {
				var hasLogin = Boolean(
					upsell && upsell.loginUrl && upsell.loginLabel
				);
				noticeLogin.hidden = ! hasLogin;
				if ( hasLogin ) {
					noticeLogin.href = upsell.loginUrl;
					noticeLogin.textContent = upsell.loginLabel;
				}
			}
			notice.hidden = false;
		}

		function announcePreviewMode() {
			if ( ! queueMeta || ! queueMeta.previewOnly ) {
				return;
			}
			var upsell = queueMeta.upsell || {};
			var message = upsell.previewNote || labels.previewNote || '';
			if ( upsell.message ) {
				message = message
					? message + ' ' + upsell.message
					: upsell.message;
			}
			if ( message ) {
				showNotice( message, upsell );
			}
		}

		function mediaSession( track ) {
			if (
				! ( 'mediaSession' in navigator ) ||
				! window.MediaMetadata ||
				! track
			) {
				return;
			}
			navigator.mediaSession.metadata = new MediaMetadata( {
				title: track.title,
				artist: track.artist,
				artwork: track.image ? [ { src: track.image } ] : [],
			} );
			var handlers = {
				play() {
					audio.play();
				},
				pause() {
					audio.pause();
				},
				previoustrack() {
					select( current - 1, true );
				},
				nexttrack() {
					select( current + 1, true );
				},
				seekto( details ) {
					if (
						audio.duration &&
						'number' === typeof details.seekTime
					) {
						audio.currentTime = details.seekTime;
					}
				},
				seekbackward() {
					seekBy( -10 );
				},
				seekforward() {
					seekBy( 10 );
				},
			};
			Object.keys( handlers ).forEach( function ( action ) {
				try {
					navigator.mediaSession.setActionHandler(
						action,
						handlers[ action ]
					);
				} catch ( e ) {}
			} );
		}

		function seekBy( delta ) {
			if ( ! audio.duration ) {
				return;
			}
			audio.currentTime = Math.min(
				audio.duration,
				Math.max( 0, audio.currentTime + delta )
			);
		}

		function render() {
			var track = currentTrack();
			if ( ! track ) {
				return;
			}
			title.textContent = track.title;
			artist.textContent = track.artist;
			if ( track.image ) {
				art.src = track.image;
				art.hidden = false;
			} else {
				art.removeAttribute( 'src' );
				art.hidden = true;
			}
			// Now-playing signal for the theme equalizer (CSS-only consumer).
			player.setAttribute(
				'data-mw-state',
				audio.paused ? 'paused' : 'playing'
			);
			player.hidden = false;
			setPresence( true );
			renderToggle();
			toggle.setAttribute(
				'aria-label',
				audio.paused
					? labels.play || 'پخش پیش‌نمایش'
					: labels.pause || 'توقف پیش‌نمایش'
			);
			previous.disabled = current <= 0;
			next.disabled = current >= queue.length - 1;
			updateButtons();
			renderQueuePanel();
			renderLyricsPanel();
		}

		function loadSource( track ) {
			if ( ! track.full ) {
				return Promise.resolve( track.url );
			}
			return fetchStreamToken( track ).then( function ( response ) {
				return streamUrl( track, response );
			} );
		}

		/* -------------------------------------------------------------- *
		 * Continue-listening progress tracker (local + REST sync)         *
		 * -------------------------------------------------------------- */

		var HISTORY_KEY = 'mw-continue-listening';
		var HISTORY_LIMIT = 24;
		var PROGRESS_SYNC_INTERVAL = 15000;
		var lastProgressSyncAt = 0;
		var serverConsentState =
			window.musicWaveListening &&
			typeof window.musicWaveListening.hasConsent === 'boolean'
				? window.musicWaveListening.hasConsent
				: null;

		function isViewerLoggedIn() {
			var cfg = currentSettings();
			if ( cfg && typeof cfg.loggedIn === 'boolean' ) {
				return cfg.loggedIn;
			}
			return Boolean(
				window.musicWaveListening && window.musicWaveListening.loggedIn
			);
		}

		function readLocalHistory() {
			try {
				var raw = window.localStorage.getItem( HISTORY_KEY );
				if ( ! raw ) {
					return [];
				}
				var parsed = JSON.parse( raw );
				return Array.isArray( parsed ) ? parsed : [];
			} catch ( e ) {
				return [];
			}
		}

		function writeLocalHistory( items ) {
			try {
				window.localStorage.setItem(
					HISTORY_KEY,
					JSON.stringify( items.slice( 0, HISTORY_LIMIT ) )
				);
			} catch ( e ) {}
		}

		function emitListeningUpdate( detail ) {
			try {
				document.dispatchEvent(
					new window.CustomEvent( 'mw-listening-updated', {
						detail: detail || {},
					} )
				);
			} catch ( e ) {}
		}

		function postListeningActivity( track, eventType, positionSeconds ) {
			if (
				! isViewerLoggedIn() ||
				serverConsentState === false ||
				! window.wp ||
				! window.wp.apiFetch
			) {
				return Promise.resolve();
			}
			var releaseId =
				parseInt( track.releaseId || track.rootId, 10 ) || 0;
			var releaseUrl = track.link || '';
			if ( releaseId < 1 && ! releaseUrl ) {
				return Promise.resolve();
			}
			return window.wp
				.apiFetch( {
					path: '/music-wave/v1/listening/progress',
					method: 'POST',
					headers: restHeaders(),
					data: {
						release_id: releaseId,
						release_url: releaseUrl,
						event: eventType === 'played' ? 'played' : 'progress',
						position: Math.max(
							0,
							Math.floor( positionSeconds || 0 )
						),
					},
				} )
				.then( function ( response ) {
					serverConsentState = true;
					if (
						response &&
						response.release_id &&
						! track.releaseId
					) {
						track.releaseId = parseInt( response.release_id, 10 );
					}
					emitListeningUpdate( {
						source: 'server',
						event: eventType,
						releaseId: track.releaseId || releaseId,
					} );
				} )
				.catch( function ( error ) {
					if (
						error &&
						error.code === 'mw_listening_consent_required'
					) {
						serverConsentState = false;
					}
				} );
		}

		function isListeningEnabled() {
			var cfg = currentSettings();
			return ! ( cfg && cfg.listeningEnabled === false );
		}

		function recordTrackActivity( track, eventType, positionSeconds ) {
			if (
				! track ||
				! isListeningEnabled() ||
				( isViewerLoggedIn() && serverConsentState === false )
			) {
				return;
			}
			var releaseId =
				parseInt( track.releaseId || track.rootId, 10 ) || 0;
			var keyUrl = track.link || track.url || '';
			if ( releaseId < 1 && ! keyUrl ) {
				return;
			}
			var pos = Math.max( 0, Math.floor( positionSeconds || 0 ) );
			var total =
				audio && audio.duration && isFinite( audio.duration )
					? Math.max( 0, Math.floor( audio.duration ) )
					: 0;
			var completed = eventType === 'played';
			var now = Date.now();
			var items = readLocalHistory().filter( function ( entry ) {
				if ( ! entry ) {
					return false;
				}
				if ( releaseId > 0 && entry.releaseId === releaseId ) {
					return false;
				}
				if ( keyUrl && entry.link === keyUrl ) {
					return false;
				}
				return true;
			} );
			items.unshift( {
				releaseId,
				rootId: parseInt( track.rootId, 10 ) || releaseId,
				title: track.title || '',
				artist: track.artist || '',
				image: track.image || '',
				link: track.link || '',
				previewUrl: track.full ? '' : track.url || '',
				position: completed ? 0 : pos,
				duration: total,
				completed,
				updatedAt: Math.floor( now / 1000 ),
			} );
			writeLocalHistory( items );
			emitListeningUpdate( {
				source: 'local',
				event: eventType,
				releaseId,
				position: pos,
				duration: total,
			} );
			postListeningActivity( track, eventType, pos );
		}

		function getLocalHistory( source, limit ) {
			var maxItems = Math.max( 1, parseInt( limit, 10 ) || 8 );
			var wantPlayed = source === 'played';
			return readLocalHistory()
				.filter( function ( item ) {
					if ( ! item || ( ! item.releaseId && ! item.link ) ) {
						return false;
					}
					return wantPlayed
						? Boolean( item.completed )
						: ! item.completed;
				} )
				.slice( 0, maxItems );
		}

		function removeLocalHistory( releaseId ) {
			var target = parseInt( releaseId, 10 ) || 0;
			var filtered = readLocalHistory().filter( function ( item ) {
				return item && item.releaseId !== target;
			} );
			writeLocalHistory( filtered );
			emitListeningUpdate( {
				source: 'local-remove',
				releaseId: target,
			} );
		}

		function clearLocalHistory( source ) {
			if ( source === 'continue' || source === 'played' ) {
				var keepCompleted = source === 'continue';
				var remaining = readLocalHistory().filter( function ( item ) {
					return item && Boolean( item.completed ) === keepCompleted;
				} );
				writeLocalHistory( remaining );
			} else {
				writeLocalHistory( [] );
			}
			emitListeningUpdate( { source: 'local-clear' } );
		}

		function setListeningConsent( granted ) {
			serverConsentState = Boolean( granted );
			if ( ! serverConsentState ) {
				return Promise.resolve();
			}
			var pending = [];
			var active = currentTrack();
			if ( active ) {
				pending.push(
					postListeningActivity(
						active,
						'progress',
						audio.currentTime || 0
					)
				);
			}
			readLocalHistory()
				.slice( 0, 8 )
				.reverse()
				.forEach( function ( item ) {
					if ( item && ( item.releaseId || item.link ) ) {
						pending.push(
							postListeningActivity(
								{
									releaseId: item.releaseId,
									rootId: item.rootId,
									link: item.link,
								},
								item.completed ? 'played' : 'progress',
								item.position || 0
							)
						);
					}
				} );
			return Promise.all( pending );
		}

		function select( index, autoplay, resumePosition ) {
			if ( index < 0 || index >= queue.length ) {
				return;
			}
			var outgoing = currentTrack();
			if (
				outgoing &&
				queue[ index ] !== outgoing &&
				audio.currentTime >= 2
			) {
				recordTrackActivity( outgoing, 'progress', audio.currentTime );
			}
			current = index;
			var track = queue[ current ];
			var targetTime = Math.max( 0, parseFloat( resumePosition ) || 0 );
			audio.pause();
			try {
				audio.currentTime = 0;
			} catch ( e ) {}
			hideNotice();
			setLoading( true );
			render();
			persistState( true );
			loadSource( track )
				.then( function ( url ) {
					if ( currentTrack() !== track ) {
						return;
					}
					setLoading( false );
					audio.src = url;
					audio.load();
					mediaSession( track );
					var startPlayback = function () {
						audio.removeEventListener(
							'loadedmetadata',
							startPlayback
						);
						if ( targetTime > 0 ) {
							var maxSeek =
								audio.duration && isFinite( audio.duration )
									? Math.max( 0, audio.duration - 1.5 )
									: targetTime;
							try {
								audio.currentTime = Math.min(
									targetTime,
									maxSeek
								);
							} catch ( e ) {}
							renderTime();
						}
						if ( autoplay ) {
							audio.play().catch( function () {
								render();
							} );
						}
					};
					if ( targetTime > 0 && audio.readyState < 1 ) {
						audio.addEventListener(
							'loadedmetadata',
							startPlayback
						);
					} else {
						startPlayback();
					}
				} )
				.catch( function ( error ) {
					if ( currentTrack() !== track ) {
						return;
					}
					setLoading( false );
					render();
					showNotice(
						errorMessage( error, labels.streamError ),
						queueMeta ? queueMeta.upsell : null
					);
				} );
		}

		function startSecureFile( button ) {
			if ( ! button ) {
				return;
			}
			var releaseId =
				parseInt( button.getAttribute( 'data-release-id' ), 10 ) || 0;
			var quality = secureButtonQuality( button );
			if ( releaseId < 1 || ! quality ) {
				showNotice( labels.streamError || labels.error, null );
				return;
			}

			var active = currentTrack();
			if (
				active &&
				active.full &&
				active.releaseId === releaseId &&
				active.quality === quality
			) {
				if ( queue.length !== 1 || ! active.singleFile || queueMeta ) {
					active.singleFile = true;
					queue = [ active ];
					queueMeta = null;
					renderQueuePanel();
					persistState( true );
				}
				if ( audio.paused ) {
					resumeOrReselect();
				} else {
					audio.pause();
				}
				render();
				return;
			}

			var track = trackFromSecureButton( button, quality );
			queue = [ track ];
			queueMeta = null;
			select( 0, true );
		}

		function startPreviewButton( button ) {
			var clicked = trackFromButton( button );
			clicked.singleFile = true;
			var active = currentTrack();
			if (
				active &&
				! active.full &&
				active.url &&
				active.url === clicked.url
			) {
				if ( queue.length !== 1 || ! active.singleFile || queueMeta ) {
					active.singleFile = true;
					queue = [ active ];
					queueMeta = null;
					renderQueuePanel();
					persistState( true );
				}
				if ( audio.paused ) {
					audio.play().catch( function () {} );
				} else {
					audio.pause();
				}
				render();
				return;
			}
			queue = [ clicked ];
			queueMeta = null;
			select( 0, true );
		}

		function releasePlaying( releaseId ) {
			var track = currentTrack();
			return (
				Boolean( track ) &&
				( track.rootId === releaseId || track.releaseId === releaseId )
			);
		}

		function canResumeAudio() {
			return Boolean(
				audio.src && ! audio.error && audio.readyState > 0
			);
		}

		function resumeOrReselect() {
			if ( canResumeAudio() ) {
				audio.play().catch( function () {
					select( current, true );
				} );
			} else if ( current >= 0 && current < queue.length ) {
				select( current, true );
			}
		}

		// Shuffle start position: resolved after the queue is known so every
		// click begins from a fresh random track (0 for single-track queues).
		function randomStart( count ) {
			return count > 1 ? Math.floor( Math.random() * count ) : 0;
		}

		function startRelease(
			button,
			forcedReleaseId,
			startAt,
			explicitResume
		) {
			var releaseId =
				forcedReleaseId ||
				parseInt( button.getAttribute( 'data-mw-release-id' ), 10 ) ||
				0;
			if ( releaseId < 1 ) {
				return;
			}
			var isShuffle = 'function' === typeof startAt;
			var wantsFullReleaseQueue =
				isShuffle ||
				Boolean(
					button &&
						button.getAttribute &&
						button.getAttribute( 'data-mw-play-mode' ) === 'all'
				);
			var fullQueueAlreadyLoaded = Boolean(
				queueMeta &&
					queueMeta.release &&
					parseInt( queueMeta.release.id, 10 ) === releaseId
			);
			var resumeAt =
				parseFloat( explicitResume ) ||
				( button && button.getAttribute
					? parseFloat(
							button.getAttribute( 'data-mw-resume-position' )
					  ) || 0
					: 0 );
			if (
				! isShuffle &&
				releasePlaying( releaseId ) &&
				( ! wantsFullReleaseQueue || fullQueueAlreadyLoaded )
			) {
				if ( queueMeta && queueMeta.playlist ) {
					var solo = currentTrack();
					if ( solo ) {
						solo.singleFile = true;
					}
					queue = solo ? [ solo ] : [];
					queueMeta = null;
					renderQueuePanel();
					updateButtons();
					persistState( true );
					if ( audio.paused ) {
						resumeOrReselect();
					}
					return;
				}
				if (
					resumeAt > 0 &&
					Math.abs( ( audio.currentTime || 0 ) - resumeAt ) > 2
				) {
					try {
						audio.currentTime = resumeAt;
					} catch ( e ) {}
					if ( audio.paused ) {
						resumeOrReselect();
					}
					return;
				}
				if ( audio.paused ) {
					resumeOrReselect();
				} else {
					audio.pause();
				}
				return;
			}
			var isSingleTrackRow = Boolean(
				button &&
					button.matches &&
					button.matches(
						'.mw-public-playlists__track-play, .mw-playlists__track-play'
					)
			);
			if ( button && button.classList ) {
				button.classList.add( 'is-loading' );
			}
			fetchReleaseQueue( releaseId )
				.then( function ( data ) {
					var tracks = Array.isArray( data.tracks )
						? data.tracks
						: [];
					queue = tracks
						.map( function ( item ) {
							return trackFromApi( item, data );
						} )
						.filter( function ( track ) {
							return track.full || track.url;
						} );
					if ( ! queue.length ) {
						throw { message: labels.error };
					}
					if ( isSingleTrackRow ) {
						queue = queue.slice( 0, 1 );
						queue[ 0 ].singleFile = true;
						queueMeta = null;
					} else {
						queueMeta = data;
					}
					// startAt may be a number (clamped to the queue) or a
					// function of the queue length (shuffle's random position).
					var startIndex = 0;
					if ( 'function' === typeof startAt ) {
						startIndex = startAt( queue.length );
					} else if ( startAt ) {
						startIndex = Math.min(
							Math.max( parseInt( startAt, 10 ) || 0, 0 ),
							queue.length - 1
						);
					}
					select( startIndex, true, resumeAt );
					announcePreviewMode();
				} )
				.catch( function ( error ) {
					showNotice( errorMessage( error, labels.error ), null );
				} )
				.finally( function () {
					if ( button && button.classList ) {
						button.classList.remove( 'is-loading' );
					}
				} );
		}

		function resolvePlaylistShare( button ) {
			if ( button && button.getAttribute ) {
				var own =
					button.getAttribute( 'data-mw-share' ) ||
					button.getAttribute( 'data-share' ) ||
					'';
				if ( own ) {
					return own;
				}
			}
			if ( button && button.closest ) {
				var host = button.closest( '[data-mw-share]' );
				if ( host ) {
					var hostShare = host.getAttribute( 'data-mw-share' ) || '';
					if ( hostShare ) {
						return hostShare;
					}
				}
			}
			return '';
		}

		function startPlaylist( button ) {
			var playlistId =
				parseInt(
					button.getAttribute( 'data-playlist-id' ) ||
						button.getAttribute( 'data-mw-playlist-id' ),
					10
				) || 0;
			if ( playlistId < 1 ) {
				return;
			}

			var samePlaylist =
				queue.length > 0 &&
				queueMeta &&
				queueMeta.playlist &&
				parseInt( queueMeta.playlist.id, 10 ) === playlistId;

			// If button is already marking the playing queue, toggle pause/resume.
			if ( samePlaylist ) {
				if ( ! audio.paused ) {
					audio.pause();
				} else {
					resumeOrReselect();
				}
				return;
			}

			if ( button.classList ) {
				button.classList.add( 'is-loading' );
			}
			if ( button.setAttribute ) {
				button.setAttribute( 'aria-busy', 'true' );
			}
			fetchPlaylistQueue( playlistId, resolvePlaylistShare( button ) )
				.then( function ( data ) {
					var tracks = Array.isArray( data.tracks )
						? data.tracks
						: [];
					queue = tracks
						.map( function ( item ) {
							return trackFromApi( item, data );
						} )
						.filter( function ( track ) {
							return track.full || track.url;
						} );
					queueMeta = data;
					if ( ! queue.length ) {
						throw { message: labels.noPlayable || labels.error };
					}
					// Update all playlist buttons pressed state
					document
						.querySelectorAll( '[data-mw-playlist-play]' )
						.forEach( function ( btn ) {
							btn.setAttribute(
								'aria-pressed',
								parseInt(
									btn.getAttribute( 'data-playlist-id' ) ||
										btn.getAttribute(
											'data-mw-playlist-id'
										),
									10
								) === playlistId
									? 'true'
									: 'false'
							);
						} );
					select( 0, true );
					announcePreviewMode();
				} )
				.catch( function ( error ) {
					showNotice(
						errorMessage( error, labels.error ),
						queueMeta ? queueMeta.upsell : null
					);
				} )
				.finally( function () {
					if ( button.classList ) {
						button.classList.remove( 'is-loading' );
					}
					if ( button.removeAttribute ) {
						button.removeAttribute( 'aria-busy' );
					}
					updateButtons();
				} );
		}

		function startCustomQueue( button, releaseIds, preferences ) {
			if ( ! Array.isArray( releaseIds ) || ! releaseIds.length ) {
				return;
			}
			if ( preferences && 'object' === typeof preferences ) {
				setQueuePreferences( preferences );
			}
			var ids = releaseIds
				.map( function ( id ) {
					return parseInt( id, 10 ) || 0;
				} )
				.filter( function ( id ) {
					return id > 0;
				} );
			if ( ! ids.length ) {
				return;
			}
			if ( button ) {
				button.classList.add( 'is-loading' );
				button.setAttribute( 'aria-busy', 'true' );
			}
			Promise.all(
				ids.map( function ( rid ) {
					return fetchReleaseQueue( rid ).then(
						function ( data ) {
							return data && Array.isArray( data.tracks )
								? data
								: null;
						},
						function () {
							return null;
						}
					);
				} )
			)
				.then( function ( payloads ) {
					var builtTracks = [];
					var firstMeta = null;
					payloads.forEach( function ( payload ) {
						if ( ! payload ) {
							return;
						}
						if ( ! firstMeta ) {
							firstMeta = payload;
						}
						payload.tracks.forEach( function ( item ) {
							builtTracks.push( trackFromApi( item, payload ) );
						} );
					} );
					queue = builtTracks.filter( function ( t ) {
						return t.full || t.url;
					} );
					if ( ! queue.length ) {
						startRelease( button || {}, ids[ 0 ] );
						return;
					}
					queueMeta = firstMeta || {};
					queueMeta.customQueue = true;
					queueMeta.customIds = ids;
					var startIdx = queuePreferences.shuffle
						? randomStart( queue.length )
						: 0;
					select( startIdx, true );
					announcePreviewMode();
				} )
				.catch( function ( error ) {
					showNotice( errorMessage( error, labels.error ), null );
				} )
				.finally( function () {
					if ( button ) {
						button.classList.remove( 'is-loading' );
						button.removeAttribute( 'aria-busy' );
					}
					updateButtons();
				} );
		}

		document.addEventListener( 'click', function ( event ) {
			var playlistButton = event.target.closest(
				'[data-mw-playlist-play]'
			);
			if ( playlistButton && ! playlistButton.disabled ) {
				event.preventDefault();
				event.stopPropagation();
				startPlaylist( playlistButton );
				return;
			}
			var previewButton = event.target.closest( '.mw-preview-button' );
			if ( previewButton ) {
				event.preventDefault();
				// Release-queue triggers (the hero "پخش الان" fallback without
				// a public preview URL) must resolve the playback queue instead
				// of scanning the page for preview-url buttons — that scan
				// misses them and surfaced "پخش امن شروع نشد".
				var previewReleaseId = parseInt(
					previewButton.getAttribute( 'data-mw-release-id' ),
					10
				);
				if ( previewReleaseId > 0 ) {
					startRelease( previewButton );
				} else {
					startPreviewButton( previewButton );
				}
				return;
			}
			var cardButton = event.target.closest(
				'.mw-card-play[data-mw-release-id]'
			);
			if ( cardButton ) {
				event.preventDefault();
				event.stopPropagation();
				startRelease( cardButton );
			}
		} );
		toggle.addEventListener( 'click', function () {
			if ( busy ) {
				return;
			}
			if ( audio.paused ) {
				resumeOrReselect();
			} else {
				audio.pause();
			}
		} );
		previous.addEventListener( 'click', function () {
			select( current - 1, true );
		} );
		next.addEventListener( 'click', function () {
			select( current + 1, true );
		} );
		if ( queueToggle ) {
			queueToggle.addEventListener( 'click', function () {
				setQueueOpen( queuePanel.hidden );
			} );
		}
		if ( queueClose ) {
			queueClose.addEventListener( 'click', function () {
				setQueueOpen( false );
			} );
		}
		if ( lyricsToggle ) {
			lyricsToggle.addEventListener( 'click', function () {
				setLyricsOpen( lyricsPanel.hidden );
			} );
		}
		if ( lyricsClose ) {
			lyricsClose.addEventListener( 'click', function () {
				setLyricsOpen( false );
			} );
		}
		if ( noticeClose ) {
			noticeClose.addEventListener( 'click', hideNotice );
		}
		close.addEventListener( 'click', function () {
			audio.pause();
			audio.removeAttribute( 'src' );
			current = -1;
			queue = [];
			queueMeta = null;
			player.hidden = true;
			player.removeAttribute( 'data-mw-state' );
			setPresence( false );
			setQueueOpen( false );
			setLyricsOpen( false );
			hideNotice();
			clearState();
			updateButtons();
		} );
		var VOLUME_KEY = 'mw-player-volume';
		var lastAudibleVolume = 1;

		function sliderMaxScale() {
			var maxScale = volume ? parseFloat( volume.max ) : NaN;

			return isFinite( maxScale ) && maxScale > 1 ? maxScale : 1;
		}

		function normalizeVolume( raw, fromSlider ) {
			var parsed = parseFloat( raw );
			if ( ! isFinite( parsed ) ) {
				return 1;
			}
			var scale = sliderMaxScale();
			if ( fromSlider ) {
				parsed = parsed / scale;
			} else if ( parsed > 1 ) {
				parsed = parsed / 100;
			}
			return Math.min(
				1,
				Math.max( 0, Math.round( parsed * 100 ) / 100 )
			);
		}

		function renderVolume() {
			var level = audio.muted || 0 === audio.volume ? 'muted' : 'audible';
			var pct = Math.round( ( audio.muted ? 0 : audio.volume ) * 100 );
			player.setAttribute( 'data-mw-volume', level );
			if ( volume ) {
				volume.style.setProperty( '--mw-volume', pct + '%' );
				volume.setAttribute( 'aria-valuenow', String( pct ) );
				volume.setAttribute( 'aria-valuetext', pct + '%' );
			}
			if ( mute ) {
				mute.setAttribute(
					'aria-pressed',
					'muted' === level ? 'true' : 'false'
				);
				mute.setAttribute(
					'aria-label',
					'muted' === level
						? labels.unmute || 'باصدا'
						: labels.mute || 'بی‌صدا'
				);
			}
		}

		function persistVolume() {
			try {
				window.localStorage.setItem(
					VOLUME_KEY,
					String( audio.muted ? 0 : audio.volume )
				);
			} catch ( e ) {}
		}

		function applyVolume( raw, fromSlider ) {
			var value = normalizeVolume( raw, fromSlider );
			var scale = sliderMaxScale();
			audio.volume = value;
			if ( value > 0 ) {
				lastAudibleVolume = value;
				audio.muted = false;
			}
			if ( volume && ! fromSlider ) {
				volume.value = String(
					scale > 1 ? Math.round( value * scale ) : value
				);
			}
			renderVolume();

			return value;
		}

		// Mute and unmute are the only bimodal volume actions; the slider
		// uses a 0..1 scale (`min="0" max="1" step="0.01"`) so live drags
		// apply normalized [0..1] audio.volume without clobbering the thumb.
		if ( volume ) {
			volume.addEventListener( 'input', function () {
				applyVolume( volume.value, true );
				persistVolume();
			} );
			volume.addEventListener( 'change', function () {
				applyVolume( volume.value, false );
				persistVolume();
			} );
			try {
				var savedVolume = window.localStorage.getItem( VOLUME_KEY );
				if ( null !== savedVolume && '' !== savedVolume ) {
					applyVolume( savedVolume, false );
				}
			} catch ( e ) {}
		}
		if ( mute ) {
			mute.addEventListener( 'click', function () {
				if ( audio.muted || 0 === audio.volume ) {
					audio.muted = false;
					if ( 0 === audio.volume ) {
						applyVolume( lastAudibleVolume || 1, false );
					}
				} else {
					audio.muted = true;
				}
				renderVolume();
				persistVolume();
			} );
		}
		audio.addEventListener( 'volumechange', renderVolume );
		renderVolume();

		/* -------------------------------------------------------------- *
		 * Session persistence — the Spotify/Apple Music behavior.         *
		 *                                                                 *
		 * The queue, current track, and exact position are written to     *
		 * sessionStorage continuously, so a hard reload (F5), a link      *
		 * opened where soft navigation is excluded, or a new visit to     *
		 * the site brings the player bar back with everything intact:     *
		 * track, artwork, queue panel, and resume position. Playback      *
		 * resumes automatically where the browser allows it; otherwise    *
		 * the bar sits paused at the exact spot until one tap.            *
		 * -------------------------------------------------------------- */

		var STATE_KEY = 'mw-player-state';
		var STATE_TTL = 30 * 60 * 1000;
		var PERSIST_MIN_INTERVAL = 3000;
		var lastPersistAt = 0;

		function persistState( force ) {
			var track = currentTrack();
			if ( ! track ) {
				return;
			}
			var now = Date.now();
			if ( ! force && now - lastPersistAt < PERSIST_MIN_INTERVAL ) {
				return;
			}
			lastPersistAt = now;
			try {
				window.sessionStorage.setItem(
					STATE_KEY,
					JSON.stringify( {
						v: 1,
						at: now,
						index: current,
						position: audio.currentTime || 0,
						playing: ! audio.paused,
						queueOpen: Boolean( queuePanel ) && ! queuePanel.hidden,
						lyricsOpen:
							Boolean( lyricsPanel ) && ! lyricsPanel.hidden,
						queue,
						meta: queueMeta,
					} )
				);
			} catch ( e ) {}
		}

		function clearState() {
			try {
				window.sessionStorage.removeItem( STATE_KEY );
			} catch ( e ) {}
		}

		function readState() {
			try {
				var raw = window.sessionStorage.getItem( STATE_KEY );
				if ( ! raw ) {
					return null;
				}
				var data = JSON.parse( raw );
				if (
					! data ||
					data.v !== 1 ||
					! Array.isArray( data.queue ) ||
					! data.queue.length
				) {
					return null;
				}
				if ( Date.now() - ( data.at || 0 ) > STATE_TTL ) {
					clearState();

					return null;
				}
				var index = parseInt( data.index, 10 ) || 0;
				if ( index < 0 || index >= data.queue.length ) {
					return null;
				}

				return data;
			} catch ( e ) {
				return null;
			}
		}

		function restoreSession() {
			var data = readState();
			if ( ! data || currentTrack() ) {
				return;
			}
			queue = data.queue.filter( function ( item ) {
				return item && ( item.full || item.url );
			} );
			queueMeta = data.meta || null;
			if ( ! queue.length ) {
				clearState();

				return;
			}
			current = Math.min(
				parseInt( data.index, 10 ) || 0,
				queue.length - 1
			);
			var position = parseFloat( data.position ) || 0;
			var shouldResume = data.playing === true;
			var track = queue[ current ];
			render();
			if ( data.queueOpen ) {
				setQueueOpen( true );
			}
			if ( data.lyricsOpen ) {
				setLyricsOpen( true );
			}
			announcePreviewMode();
			setLoading( true );

			loadSource( track )
				.then( function ( url ) {
					if ( currentTrack() !== track ) {
						return;
					}
					setLoading( false );
					audio.src = url;
					audio.load();
					mediaSession( track );

					var resume = function () {
						audio.removeEventListener( 'loadedmetadata', resume );
						if (
							position > 0 &&
							audio.duration &&
							position < audio.duration - 1
						) {
							try {
								audio.currentTime = position;
							} catch ( e ) {}
						}
						progress.value = audio.duration
							? String(
									( audio.currentTime / audio.duration ) * 100
							  )
							: '0';
						progress.style.setProperty(
							'--mw-progress',
							progress.value + '%'
						);
						renderTime();
						if ( shouldResume ) {
							audio.play().catch( function () {
								// Autoplay blocked after reload: stay paused
								// at the restored position until one tap.
								render();
							} );
						} else {
							render();
						}
					};
					if ( audio.readyState >= 1 ) {
						resume();
					} else {
						audio.addEventListener( 'loadedmetadata', resume );
					}
				} )
				.catch( function () {
					setLoading( false );
					render();
				} );
		}

		audio.addEventListener( 'play', function () {
			persistState( true );
			var active = currentTrack();
			if ( active ) {
				lastProgressSyncAt = Date.now();
				recordTrackActivity( active, 'progress', audio.currentTime );
			}
		} );
		audio.addEventListener( 'pause', function () {
			persistState( true );
			var active = currentTrack();
			if ( active && audio.currentTime >= 2 && ! audio.ended ) {
				lastProgressSyncAt = Date.now();
				recordTrackActivity( active, 'progress', audio.currentTime );
			}
		} );
		audio.addEventListener( 'seeked', function () {
			persistState( true );
			var active = currentTrack();
			if ( active && audio.currentTime >= 1 ) {
				lastProgressSyncAt = Date.now();
				recordTrackActivity( active, 'progress', audio.currentTime );
			}
		} );
		audio.addEventListener( 'ended', function () {
			persistState( true );
			var finished = currentTrack();
			if ( finished ) {
				recordTrackActivity(
					finished,
					'played',
					audio.duration || audio.currentTime || 0
				);
			}
		} );
		audio.addEventListener( 'timeupdate', function () {
			persistState( false );
			var active = currentTrack();
			if ( active && ! audio.paused && audio.currentTime >= 3 ) {
				var nowTs = Date.now();
				if ( nowTs - lastProgressSyncAt >= PROGRESS_SYNC_INTERVAL ) {
					lastProgressSyncAt = nowTs;
					recordTrackActivity(
						active,
						'progress',
						audio.currentTime
					);
				}
			}
		} );
		window.addEventListener( 'pagehide', function () {
			persistState( true );
			var active = currentTrack();
			if ( active && audio.currentTime >= 2 ) {
				recordTrackActivity( active, 'progress', audio.currentTime );
			}
		} );
		document.addEventListener( 'visibilitychange', function () {
			if ( document.hidden ) {
				persistState( true );
				var active = currentTrack();
				if ( active && audio.currentTime >= 2 ) {
					recordTrackActivity(
						active,
						'progress',
						audio.currentTime
					);
				}
			}
		} );
		restoreSession();
		audio.addEventListener( 'play', render );
		audio.addEventListener( 'pause', render );
		// Network stalls surface as the spinner without blocking the toggle,
		// so a visitor can still pause a stream that is buffering.
		audio.addEventListener( 'waiting', function () {
			buffering = true;
			renderToggle();
		} );
		[ 'playing', 'canplay', 'pause', 'emptied', 'error' ].forEach(
			function ( name ) {
				audio.addEventListener( name, function () {
					buffering = false;
					renderToggle();
				} );
			}
		);
		var queuePreferences = {
			shuffle: false,
			repeat: 'off',
		};
		function setQueuePreferences( prefs ) {
			if ( ! prefs || 'object' !== typeof prefs ) {
				return;
			}
			if ( 'boolean' === typeof prefs.shuffle ) {
				queuePreferences.shuffle = prefs.shuffle;
			}
			if (
				'string' === typeof prefs.repeat &&
				-1 !== [ 'off', 'all', 'one' ].indexOf( prefs.repeat )
			) {
				queuePreferences.repeat = prefs.repeat;
			}
		}
		audio.addEventListener( 'ended', function () {
			if ( ! queue.length ) {
				return;
			}
			if ( 'one' === queuePreferences.repeat ) {
				audio.currentTime = 0;
				audio.play().catch( function () {} );
				return;
			}
			if ( queuePreferences.shuffle && queue.length > 1 ) {
				var nextRandom = Math.floor( Math.random() * queue.length );
				if ( nextRandom === current ) {
					nextRandom = ( current + 1 ) % queue.length;
				}
				select( nextRandom, true );
				return;
			}
			if ( current < queue.length - 1 ) {
				select( current + 1, true );
			} else if ( 'all' === queuePreferences.repeat ) {
				select( 0, true );
			} else {
				audio.currentTime = 0;
				render();
			}
		} );
		audio.addEventListener( 'error', function () {
			if ( ! currentTrack() || ! audio.src ) {
				return;
			}
			setLoading( false );
			showNotice(
				labels.streamError || labels.error,
				queueMeta ? queueMeta.upsell : null
			);
			render();
		} );
		audio.addEventListener( 'timeupdate', function () {
			var total = audio.duration || 0;
			var track = currentTrack();
			if (
				track &&
				track.previewLimit > 0 &&
				audio.currentTime >= track.previewLimit
			) {
				audio.pause();
				audio.currentTime = 0;
				track.limited = true;
				if (
					queueMeta &&
					queueMeta.upsell &&
					( queueMeta.upsell.message || queueMeta.upsell.previewNote )
				) {
					var upsell = queueMeta.upsell;
					showNotice( upsell.message || upsell.previewNote, upsell );
				}
				render();
				return;
			}
			progress.value = total
				? String( ( audio.currentTime / total ) * 100 )
				: '0';
			// Feeds the webkit slider gradient stop in the theme stylesheet.
			progress.style.setProperty( '--mw-progress', progress.value + '%' );
			renderTime();
		} );
		audio.addEventListener( 'loadedmetadata', renderTime );
		audio.addEventListener( 'durationchange', renderTime );
		progress.addEventListener( 'input', function () {
			progress.style.setProperty( '--mw-progress', progress.value + '%' );
			if ( audio.duration ) {
				audio.currentTime =
					( parseFloat( progress.value ) / 100 ) * audio.duration;
			}
			renderTime();
		} );

		playerController = {
			playRelease( trigger, releaseId, options ) {
				var resumePos =
					options && typeof options === 'object'
						? parseFloat( options.resumePosition ) || 0
						: 0;
				startRelease( trigger, releaseId, 0, resumePos );
			},
			playSecureFile( trigger ) {
				startSecureFile( trigger );
			},
			// Same queue resolution as playRelease, but playback starts from a
			// fresh random position on every call (shuffle button).
			playShuffled( trigger, releaseId ) {
				startRelease( trigger, releaseId, randomStart );
			},
			playCustomQueue( trigger, releaseIds, preferences ) {
				startCustomQueue( trigger, releaseIds, preferences );
			},
			setPreferences( preferences ) {
				setQueuePreferences( preferences );
			},
			playPlaylist( trigger, playlistId, share ) {
				if ( trigger ) {
					trigger.setAttribute(
						'data-playlist-id',
						String( playlistId )
					);
					if ( share ) {
						trigger.setAttribute( 'data-mw-share', share );
					}
				}
				startPlaylist(
					trigger || {
						getAttribute( name ) {
							if (
								'data-playlist-id' === name ||
								'data-mw-playlist-id' === name
							) {
								return String( playlistId );
							}
							if (
								'data-mw-share' === name ||
								'data-share' === name
							) {
								return share ? String( share ) : null;
							}
							return null;
						},
						classList: {
							add() {},
							remove() {},
						},
						setAttribute() {},
					}
				);
			},
			togglePlayback() {
				if ( ! currentTrack() || busy ) {
					return;
				}
				if ( audio.paused ) {
					resumeOrReselect();
				} else {
					audio.pause();
				}
			},
			isActive() {
				return Boolean( currentTrack() );
			},
			getLocalHistory,
			removeLocalHistory,
			clearLocalHistory,
			setListeningConsent,
			seekBy,
			sync() {
				// Soft navigation swaps <body>; the presence flag lives on
				// <html> so it survives, but re-assert it in case the
				// incoming page reset the element.
				setPresence( Boolean( currentTrack() ) );
				updateButtons();
			},
		};
		// Expose for playlists.js progressive enhancement.
		window._mwPreviewController = playerController;
	}

	/* Keyboard shortcuts: Space or K toggles playback, arrow keys seek.
	 * Only while a track is loaded, and never while typing in a field. */
	document.addEventListener( 'keydown', function ( event ) {
		if (
			! playerController ||
			! playerController.isActive() ||
			event.defaultPrevented
		) {
			return;
		}
		var target = event.target;
		if (
			target &&
			target.closest &&
			target.closest(
				'input, textarea, select, button, a[href], [contenteditable="true"], [role="slider"]'
			)
		) {
			return;
		}
		if ( event.altKey || event.ctrlKey || event.metaKey ) {
			return;
		}
		var key = 'string' === typeof event.key ? event.key.toLowerCase() : '';
		if ( ' ' === event.key || 'k' === key || 'spacebar' === key ) {
			event.preventDefault();
			playerController.togglePlayback();
		} else if ( 'arrowleft' === key ) {
			playerController.seekBy( -5 );
		} else if ( 'arrowright' === key ) {
			playerController.seekBy( 5 );
		}
	} );

	// Secure download rows stream only their own file & selected quality
	// through the persistent global player.
	document.addEventListener(
		'click',
		function ( event ) {
			var button =
				event.target && event.target.closest
					? event.target.closest( '.mw-secure-play-button' )
					: null;
			if ( ! button || button.disabled ) {
				return;
			}
			var controller = playerController || ensurePlayerReady();
			if ( ! controller ) {
				return;
			}
			event.preventDefault();
			event.stopPropagation();
			controller.playSecureFile( button );
		},
		true
	);

	/* ------------------------------------------------------------------ *
	 * Persistent application binding (`window.MusicWaveApp` bridge)      *
	 * ------------------------------------------------------------------ */

	function ensurePlayerReady() {
		var player = document.querySelector( selector );
		if ( player ) {
			initialize( player );
		}
		if (
			window.MusicWaveApp &&
			typeof window.MusicWaveApp.bindPlayer === 'function'
		) {
			window.MusicWaveApp.bindPlayer( playerController );
		}
		return playerController;
	}

	var previewLifecycleSpec = {
		mount() {
			ensurePlayerReady();
			if (
				playerController &&
				typeof playerController.sync === 'function'
			) {
				playerController.sync();
			}
		},
	};

	if (
		window.MusicWaveApp &&
		typeof window.MusicWaveApp.registerController === 'function'
	) {
		window.MusicWaveApp.registerController(
			'preview-player',
			previewLifecycleSpec
		);
	} else {
		window.MusicWaveAppQueue = window.MusicWaveAppQueue || [];
		window.MusicWaveAppQueue.push( {
			id: 'preview-player',
			definition: previewLifecycleSpec,
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', ensurePlayerReady );
	} else {
		ensurePlayerReady();
	}
} )();
