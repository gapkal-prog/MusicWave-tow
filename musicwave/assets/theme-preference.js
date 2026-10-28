( function () {
	'use strict';

	var storageKey = 'musicwave-theme';
	var themes = [ 'system', 'light', 'dark' ];
	var readyAttribute = 'data-mw-theme-ready';

	function savedTheme() {
		try {
			var saved = window.localStorage.getItem( storageKey );
			return themes.indexOf( saved ) !== -1 ? saved : 'system';
		} catch ( error ) {
			return 'system';
		}
	}

	function applyTheme( theme ) {
		if ( 'system' === theme ) {
			document.documentElement.removeAttribute( 'data-mw-theme' );
			return;
		}

		document.documentElement.setAttribute( 'data-mw-theme', theme );
	}

	function storeTheme( theme ) {
		try {
			if ( 'system' === theme ) {
				window.localStorage.removeItem( storageKey );
			} else {
				window.localStorage.setItem( storageKey, theme );
			}
		} catch ( error ) {}
	}

	function labels() {
		return window.musicwaveThemePreference &&
			window.musicwaveThemePreference.labels
			? window.musicwaveThemePreference.labels
			: {};
	}

	function updateButton( button, theme ) {
		var text = labels()[ theme ] || theme;
		button.setAttribute( 'data-mw-theme-value', theme );
		button.setAttribute( 'aria-label', text );
		button.setAttribute( 'title', text );
	}

	function nextTheme( theme ) {
		return themes[ ( themes.indexOf( theme ) + 1 ) % themes.length ];
	}

	function allButtons() {
		return Array.prototype.slice.call(
			document.querySelectorAll(
				'.mw-theme-toggle, .nbp-theme-toggle-fallback'
			)
		);
	}

	function syncButtons( theme ) {
		allButtons().forEach( function ( button ) {
			updateButton( button, theme );
		} );
	}

	function createIcons() {
		var wrapper = document.createElement( 'span' );
		wrapper.innerHTML =
			'<svg class="mw-theme-toggle__icon mw-theme-toggle__icon--system" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm0 2v16a8 8 0 0 1 0-16Z"/></svg>' +
			'<svg class="mw-theme-toggle__icon mw-theme-toggle__icon--light" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 7a5 5 0 1 0 0 10 5 5 0 0 0 0-10Zm0-6a1 1 0 0 1 1 1v2a1 1 0 1 1-2 0V2a1 1 0 0 1 1-1Zm0 19a1 1 0 0 1 1 1v2a1 1 0 1 1-2 0v-2a1 1 0 0 1 1-1ZM1 12a1 1 0 0 1 1-1h2a1 1 0 1 1 0 2H2a1 1 0 0 1-1-1Zm19 0a1 1 0 0 1 1-1h2a1 1 0 1 1 0 2h-2a1 1 0 0 1-1-1ZM4.22 4.22a1 1 0 0 1 1.42 0l1.41 1.41a1 1 0 0 1-1.41 1.42L4.22 5.64a1 1 0 0 1 0-1.42Zm12.73 12.73a1 1 0 0 1 1.41 0l1.42 1.41a1 1 0 0 1-1.42 1.42l-1.41-1.42a1 1 0 0 1 0-1.41Zm2.83-12.73a1 1 0 0 1 0 1.42l-1.42 1.41a1 1 0 1 1-1.41-1.41l1.41-1.42a1 1 0 0 1 1.42 0ZM7.05 16.95a1 1 0 0 1 0 1.41l-1.41 1.42a1 1 0 0 1-1.42-1.42l1.42-1.41a1 1 0 0 1 1.41 0Z"/></svg>' +
			'<svg class="mw-theme-toggle__icon mw-theme-toggle__icon--dark" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path fill="currentColor" d="M21.64 13.4A9 9 0 0 1 10.6 2.36a1 1 0 0 0-1.2-1.3A11 11 0 1 0 22.94 14.6a1 1 0 0 0-1.3-1.2ZM12 21a9 9 0 0 1-3.87-17.13A11 11 0 0 0 20.13 15.87 9 9 0 0 1 12 21Z"/></svg>';
		return wrapper.innerHTML;
	}

	function ensureFallback() {
		// If a toggle already exists in any header, we are done – the required
		// class nbp-theme-toggle-fallback is already present via PHP render.
		if (
			document.querySelector(
				'.mw-theme-toggle, .nbp-theme-toggle-fallback'
			)
		) {
			return null;
		}
		// Create fallback button and inject into top bar / header actions.
		var host =
			document.querySelector( '.mw-stream-topbar .mw-header-actions' ) ||
			document.querySelector( '.mw-site-header .mw-header-actions' ) ||
			document.querySelector( '.mw-site-header__inner' ) ||
			document.querySelector( '.mw-site-header' );
		if ( ! host ) {
			return null;
		}

		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className = 'mw-theme-toggle nbp-theme-toggle-fallback';
		btn.setAttribute( 'data-mw-theme-value', 'system' );
		btn.innerHTML = createIcons();
		host.appendChild( btn );
		return btn;
	}

	function initialize( button ) {
		button.addEventListener( 'click', function () {
			var theme = nextTheme( savedTheme() );
			storeTheme( theme );
			applyTheme( theme );
			syncButtons( theme );
		} );
	}

	function initializeAll() {
		var fallback = ensureFallback();
		var theme = savedTheme();
		applyTheme( theme );
		allButtons().forEach( function ( button ) {
			updateButton( button, theme );
			if ( ! button.getAttribute( readyAttribute ) ) {
				button.setAttribute( readyAttribute, '1' );
				initialize( button );
			}
		} );
		// If fallback was created after first query, ensure it is bound
		if ( fallback && ! fallback.getAttribute( readyAttribute ) ) {
			fallback.setAttribute( readyAttribute, '1' );
			updateButton( fallback, theme );
			initialize( fallback );
		}
	}

	window.addEventListener( 'storage', function ( event ) {
		if ( event.key === storageKey || null === event.key ) {
			var theme = savedTheme();
			applyTheme( theme );
			syncButtons( theme );
		}
	} );

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', initializeAll );
	} else {
		initializeAll();
	}
	document.addEventListener( 'mw-page-rendered', initializeAll );
} )();
