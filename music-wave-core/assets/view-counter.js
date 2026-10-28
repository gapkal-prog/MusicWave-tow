( function () {
	'use strict';

	if ( window._mwViewCounterInitialized ) {
		return;
	}
	window._mwViewCounterInitialized = true;

	var activeTimer = null;
	var activeReleaseId = 0;
	var activeCounted = false;

	function getReleaseId() {
		var el = document.querySelector(
			'body.single-mw_release [data-mw-release-id], body.mw-is-release [data-mw-release-id]'
		);
		if ( el ) {
			var id = parseInt( el.getAttribute( 'data-mw-release-id' ), 10 );
			if ( id > 0 ) {
				return id;
			}
		}
		var bodyMatch = String(
			document.body ? document.body.className : ''
		).match( /\bpostid-(\d+)\b/ );
		if ( bodyMatch && bodyMatch[ 1 ] ) {
			return parseInt( bodyMatch[ 1 ], 10 ) || 0;
		}
		var settings = window.musicWaveViewCounter || {};
		if ( settings.releaseId ) {
			return parseInt( settings.releaseId, 10 ) || 0;
		}
		return 0;
	}

	function shouldRun() {
		return Boolean(
			document.querySelector(
				'body.single-mw_release, body.mw-is-release'
			)
		);
	}

	function sendBeacon( releaseId ) {
		var settings = window.musicWaveViewCounter || {};
		var restUrl =
			settings.restUrl ||
			( window.musicWavePreviewPlayer &&
				window.musicWavePreviewPlayer.restUrl ) ||
			( window.musicWaveDownload && window.musicWaveDownload.restUrl ) ||
			'/wp-json/';
		var url = restUrl + 'music-wave/v1/releases/' + releaseId + '/view';
		var data = JSON.stringify( {} );

		if ( navigator.sendBeacon ) {
			try {
				var blob = new Blob( [ data ], { type: 'application/json' } );
				var ok = navigator.sendBeacon( url, blob );
				if ( ok ) {
					return;
				}
			} catch ( e ) {
				// fallback to fetch
			}
		}
		if ( window.fetch ) {
			try {
				window.fetch( url, {
					method: 'POST',
					body: data,
					headers: { 'Content-Type': 'application/json' },
					keepalive: true,
					credentials: 'same-origin',
				} );
			} catch ( e2 ) {
				// ignore
			}
		}
	}

	function clearTimer() {
		if ( activeTimer ) {
			window.clearTimeout( activeTimer );
			activeTimer = null;
		}
	}

	function scheduleCurrent() {
		if ( activeCounted || ! activeReleaseId ) {
			return;
		}
		clearTimer();
		var settings = window.musicWaveViewCounter || {};
		var dwell = parseInt( settings.dwellSeconds, 10 );
		if ( ! dwell || dwell < 1 ) {
			dwell = 10;
		}
		var targetId = activeReleaseId;
		activeTimer = window.setTimeout( function () {
			if ( activeCounted || activeReleaseId !== targetId ) {
				return;
			}
			if ( document.hidden ) {
				scheduleCurrent();
				return;
			}
			activeCounted = true;
			sendBeacon( targetId );
		}, dwell * 1000 );
	}

	function init() {
		clearTimer();
		activeReleaseId = 0;
		activeCounted = false;
		if ( ! shouldRun() ) {
			return;
		}
		var releaseId = getReleaseId();
		if ( ! releaseId ) {
			return;
		}
		activeReleaseId = releaseId;
		if ( document.readyState === 'complete' ) {
			scheduleCurrent();
		} else {
			window.addEventListener( 'load', scheduleCurrent, { once: true } );
		}
	}

	function teardown() {
		clearTimer();
		activeReleaseId = 0;
		activeCounted = false;
	}

	var viewCounterController = {
		mount: init,
		unmount: teardown,
	};
	if (
		window.MusicWaveApp &&
		'function' === typeof window.MusicWaveApp.registerController
	) {
		window.MusicWaveApp.registerController(
			'view-counter',
			viewCounterController
		);
	} else {
		window.MusicWaveAppQueue = window.MusicWaveAppQueue || [];
		window.MusicWaveAppQueue.push( {
			id: 'view-counter',
			definition: viewCounterController,
		} );
	}

	document.addEventListener( 'visibilitychange', function () {
		if ( ! document.hidden ) {
			scheduleCurrent();
		}
	} );
	document.addEventListener( 'mw-page-rendered', init );

	window.musicWaveViewCounterBeacon = function ( releaseId ) {
		if ( releaseId ) {
			sendBeacon( releaseId );
		}
	};

	init();
} )();
