( function () {
	'use strict';

	if ( window._mwDownloadInitialized ) {
		return;
	}
	window._mwDownloadInitialized = true;

	function downloadConfig() {
		return window.musicWaveDownload || {};
	}

	function rowFor( element ) {
		return element && element.closest
			? element.closest(
					'.mw-download-file-row, .mw-download-inline, .mw-collection-list__row, .mw-download-action'
			  )
			: null;
	}

	function selectedQuality( button ) {
		var row = rowFor( button );
		var select = row ? row.querySelector( '.mw-download-quality' ) : null;
		if ( select && select.value ) {
			return select.value;
		}
		return button && button.getAttribute
			? button.getAttribute( 'data-download-quality' ) || ''
			: '';
	}

	function statusNode( button ) {
		var container = button.closest( '.mw-download-action' );
		return container
			? container.querySelector( '.mw-download-status' )
			: null;
	}

	function setStatus( node, message, isError ) {
		if ( ! node ) {
			return;
		}
		node.textContent = message;
		node.setAttribute( 'data-error', isError ? '1' : '0' );
	}

	function errorMessage( error, fallback ) {
		var settings = downloadConfig();
		if (
			error &&
			( error.status === 401 ||
				error.code === 'mw_authentication_required' )
		) {
			return settings.sessionError || fallback;
		}
		return error && error.message ? error.message : fallback;
	}

	function redemptionUrl( releaseId, response ) {
		var settings = downloadConfig();
		var url = settings.restUrl + 'music-wave/v1/downloads/' + releaseId;
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

	function requestToken( releaseId, quality, purpose ) {
		var settings = downloadConfig();
		var path = '/music-wave/v1/releases/' + releaseId + '/download-token';
		var params = [];

		if ( quality ) {
			params.push( 'quality=' + encodeURIComponent( quality ) );
		}
		if ( purpose ) {
			params.push( 'purpose=' + encodeURIComponent( purpose ) );
		}
		if ( params.length ) {
			path += '?' + params.join( '&' );
		}

		return window.wp.apiFetch( {
			path,
			method: 'POST',
			headers: { 'X-WP-Nonce': settings.restNonce },
		} );
	}

	function setPlayLabel( button, playing ) {
		if ( ! button ) {
			return;
		}
		var settings = downloadConfig();
		var playText =
			button.getAttribute( 'data-play-label' ) ||
			settings.playLabel ||
			'پخش';
		var pauseText =
			button.getAttribute( 'data-pause-label' ) ||
			settings.pauseLabel ||
			'مکث';
		var text = playing ? pauseText : playText;
		var trackTitle = button.getAttribute( 'data-mw-track-title' ) || '';
		var icon = button.querySelector( '.mw-secure-play-button__icon' );
		var label = button.querySelector( '.mw-secure-play-button__label' );

		button.setAttribute( 'aria-pressed', playing ? 'true' : 'false' );
		button.setAttribute(
			'aria-label',
			trackTitle ? text + ' ' + trackTitle : text
		);
		// Vector play triggers (.mw-icon-swap) toggle through CSS from
		// aria-pressed, so only text-only glyph icons are rewritten here.
		if ( icon && ! icon.querySelector( '.mw-icon-swap' ) ) {
			icon.textContent = playing ? '❚❚' : '▶';
		}
		if ( label ) {
			label.textContent = text;
		} else if ( ! icon ) {
			button.textContent = text;
		}
	}

	window._mwDownloadControls = {
		selectedQuality,
		setPlayLabel,
	};

	document.addEventListener( 'change', function ( event ) {
		var select = event.target.closest( '.mw-download-quality' );
		if ( ! select ) {
			return;
		}

		var row = rowFor( select );
		var playButton = row
			? row.querySelector( '.mw-secure-play-button' )
			: null;
		if ( ! playButton ) {
			return;
		}

		var option = select.options[ select.selectedIndex ];
		var streamable =
			option && option.getAttribute( 'data-streamable' ) === '1';
		playButton.disabled = ! streamable;
		if ( ! streamable ) {
			setPlayLabel( playButton, false );
		}
		if (
			window._mwPreviewController &&
			typeof window._mwPreviewController.sync === 'function'
		) {
			window._mwPreviewController.sync();
		}
	} );

	document.addEventListener( 'click', function ( event ) {
		var playButton = event.target.closest( '.mw-secure-play-button' );
		if ( playButton ) {
			event.preventDefault();
			if ( playButton.disabled ) {
				return;
			}
			if (
				window._mwPreviewController &&
				typeof window._mwPreviewController.playSecureFile === 'function'
			) {
				window._mwPreviewController.playSecureFile( playButton );
			}
			return;
		}

		var button = event.target.closest( '.mw-download-button' );
		if ( ! button || button.disabled ) {
			return;
		}

		var settings = downloadConfig();
		var releaseId = parseInt(
			button.getAttribute( 'data-release-id' ),
			10
		);
		var status = statusNode( button );
		if ( ! releaseId || ! window.wp || ! window.wp.apiFetch ) {
			setStatus(
				status,
				settings.errorMessage || 'دانلود شروع نشد.',
				true
			);
			return;
		}

		var quality = selectedQuality( button );
		event.preventDefault();
		button.disabled = true;
		button.setAttribute( 'aria-busy', 'true' );
		setStatus( status, '', false );

		requestToken( releaseId, quality, 'download' )
			.then( function ( response ) {
				window.location.assign( redemptionUrl( releaseId, response ) );
			} )
			.catch( function ( error ) {
				setStatus(
					status,
					errorMessage(
						error,
						settings.errorMessage || 'دانلود شروع نشد.'
					),
					true
				);
			} )
			.finally( function () {
				button.disabled = false;
				button.removeAttribute( 'aria-busy' );
			} );
	} );

	function syncDownloadRows( root ) {
		var scope = root && root.querySelectorAll ? root : document;
		scope
			.querySelectorAll( '.mw-download-quality' )
			.forEach( function ( select ) {
				var row = rowFor( select );
				if ( ! row ) {
					return;
				}
				var play = row.querySelector( '.mw-secure-play-button' );
				var option = select.options[ select.selectedIndex ];
				if ( play && option ) {
					play.disabled =
						option.getAttribute( 'data-streamable' ) !== '1';
				}
			} );
	}

	var downloadController = {
		mount() {
			syncDownloadRows( document );
		},
	};
	if (
		window.MusicWaveApp &&
		'function' === typeof window.MusicWaveApp.registerController
	) {
		window.MusicWaveApp.registerController(
			'download',
			downloadController
		);
	} else {
		window.MusicWaveAppQueue = window.MusicWaveAppQueue || [];
		window.MusicWaveAppQueue.push( {
			id: 'download',
			definition: downloadController,
		} );
	}
	syncDownloadRows( document );
	document.addEventListener( 'mw-page-rendered', function () {
		syncDownloadRows( document );
	} );
} )();
