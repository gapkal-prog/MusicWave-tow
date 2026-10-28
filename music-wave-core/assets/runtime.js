/**
 * MusicWave Persistent Application Runtime (4-Layer SPA Shell Architecture).
 *
 * Layer 1 — Persistent Site Shell:
 *   Preserves `#mw-global-player` (`<audio>`), `#wpadminbar`, progress indicator,
 *   live route announcer, and hydrated site chrome across all soft transitions
 *   without ever detaching active media elements from `document.body`.
 *
 * Layer 2 — Soft Navigation Router:
 *   Handles URL normalization, eligibility checks, `AbortController` cancellation,
 *   redirect inspection, bounded O(1) LRU prefetching with mutation invalidation,
 *   atomic History state updates (`pushState` / `replaceState` / `popstate`), and
 *   exact per-entry `scrollY` + hash restoration.
 *
 * Layer 3 — Swappable Content Root:
 *   Swaps only the active route outlet (`.mw-app-shell` / `<main>`) whenever shell
 *   chrome matches, preloads incoming route stylesheets before reveal, and switches
 *   non-persistent siblings cleanly without detaching `#mw-global-player` when layouts change.
 *
 * Layer 4 — JavaScript Lifecycle & Dependency Manager:
 *   Synchronizes route configuration strictly through pure `JSON.parse()` (`#mw-route-state`
 *   and allowlisted localized config blocks — zero `<head>` inline script re-execution),
 *   updates `wp.apiFetch` REST nonces in place without stacking middlewares, loads unseen
 *   first-party external `.js` modules once in topological order, and coordinates deterministic
 *   `mount(context)` / `unmount(context)` + per-route `AbortSignal` teardown across controllers.
 */
( function () {
	'use strict';

	if ( window.MusicWaveApp && window.MusicWaveApp._booted ) {
		return;
	}

	var PLAYER_SELECTOR = '[data-mw-preview-player]';
	var ROUTE_STATE_ID = 'mw-route-state';
	var PROGRESS_ID = 'mw-nav-progress';
	var ANNOUNCER_ID = 'mw-route-announcer';
	var STYLE_TIMEOUT_MS = 2000;
	var SCRIPT_TIMEOUT_MS = 10000;
	var PREFETCH_TTL_MS = 30000;
	var PREFETCH_LIMIT = 12;

	var FILE_HREF_RE =
		/\.(mp3|m4a|aac|ogg|opus|wav|flac|zip|pdf|png|jpe?g|webp|gif|svg|mp4|webm|xml|json)$/i;

	var NAV_CURRENT_CLASSES = [
		'current-menu-item',
		'current-menu-ancestor',
		'current-menu-parent',
	];

	var NAV_ITEM_SELECTOR =
		'.wp-block-navigation-item, .wp-block-pages-list__item';

	var ALLOWED_CONFIG_GLOBALS = {
		mwLibrary: true,
		musicWavePreviewPlayer: true,
		musicWaveLibrary: true,
		musicWaveDownload: true,
		musicWavePlaylists: true,
		musicWaveListening: true,
		musicWaveQueue: true,
		musicWaveReleaseActions: true,
		musicWaveRequestChat: true,
		musicWaveRequestForm: true,
		musicWaveCatalogFilters: true,
		musicWaveCatalogSuggest: true,
		musicWaveViewCounter: true,
		musicwaveSlider: true,
		musicwaveHeroSlider: true,
		musicwaveHeaderSearch: true,
		musicwaveLyrics: true,
		musicwaveReleaseHero: true,
		musicwaveRail: true,
		musicwaveTheme: true,
		musicwaveThemePreference: true,
		musicwavePresentationBlocks: true,
	};

	// Only skip non-essential emoji shims and already-booted singleton core shells;
	// WordPress `dist/*` packages (`hooks`, `i18n`, `url`, `api-fetch`, `a11y`, `dom-ready`,
	// `interactivity`) MUST load once via `loadedPromises` whenever the initial landing
	// route omitted them so soft-navigated routes always have full `window.wp.*` APIs.
	var CORE_PERSISTENT_SCRIPT_PATTERNS = [
		/\/wp-includes\/js\/wp-emoji/i,
		/\/music-wave-core\/assets\/(runtime|preview-player)\.js/i,
	];

	function copyAttributes( source, target ) {
		for ( var i = 0; i < source.attributes.length; i += 1 ) {
			target.setAttribute(
				source.attributes[ i ].name,
				source.attributes[ i ].value
			);
		}
	}

	function parseRelaxedJsonObject( raw ) {
		if ( ! raw || 'string' !== typeof raw ) {
			return null;
		}
		var trimmed = raw.trim();
		try {
			var parsed = JSON.parse( trimmed );
			return parsed && 'object' === typeof parsed ? parsed : null;
		} catch ( error ) {
			// Support clean JS object literals in static test fixtures without eval().
			var normalized = trimmed
				.replace(
					/([{,]\s*)([A-Za-z_$][A-Za-z0-9_$]*)\s*:/g,
					'$1"$2":'
				)
				.replace( /,\s*([}\]])/g, '$1' );
			try {
				var fallback = JSON.parse( normalized );
				return fallback && 'object' === typeof fallback
					? fallback
					: null;
			} catch ( innerError ) {
				return null;
			}
		}
	}

	/* -------------------------------------------------------------------------
	 * Layer 4A — Declarative Route State & Nonce Synchronizer (Zero eval / Zero <head> exec)
	 * ---------------------------------------------------------------------- */
	function extractBalancedBraceSlice( source, startIndex ) {
		if ( ! source || '{' !== source.charAt( startIndex ) ) {
			return '';
		}
		var depth = 0;
		var quote = '';
		var escaped = false;
		for ( var i = startIndex; i < source.length; i += 1 ) {
			var ch = source.charAt( i );
			if ( quote ) {
				if ( escaped ) {
					escaped = false;
				} else if ( '\\' === ch ) {
					escaped = true;
				} else if ( ch === quote ) {
					quote = '';
				}
				continue;
			}
			if ( '"' === ch || "'" === ch ) {
				quote = ch;
			} else if ( '{' === ch ) {
				depth += 1;
			} else if ( '}' === ch ) {
				depth -= 1;
				if ( 0 === depth ) {
					return source.slice( startIndex, i + 1 );
				}
			}
		}
		return '';
	}

	class RouteStateManager {
		constructor() {
			this.lastEnvelope = null;
		}

		syncFromDocument( doc ) {
			// Reset route-scoped single-entity identifiers before applying incoming state.
			if (
				window.musicWaveViewCounter &&
				'object' === typeof window.musicWaveViewCounter
			) {
				window.musicWaveViewCounter = Object.assign(
					{},
					window.musicWaveViewCounter,
					{ releaseId: 0 }
				);
			}

			var applied = Object.create( null );

			// 1. Primary source: inert `<script type="application/json" id="mw-route-state">`.
			var envelopeNode = doc.getElementById( ROUTE_STATE_ID );
			if ( envelopeNode && envelopeNode.textContent ) {
				var envelope = parseRelaxedJsonObject(
					envelopeNode.textContent
				);
				if ( envelope ) {
					this.lastEnvelope = envelope;
					this.applyEnvelope( envelope, applied );
				}
			}

			// 2. Fallback pure-data parser for allowlisted localized script data blocks.
			var scripts = doc.querySelectorAll( 'script:not([src])' );
			for ( var i = 0; i < scripts.length; i += 1 ) {
				var type = ( scripts[ i ].getAttribute( 'type' ) || '' )
					.trim()
					.toLowerCase();
				if (
					'' !== type &&
					'text/javascript' !== type &&
					'application/javascript' !== type
				) {
					continue;
				}
				var source = scripts[ i ].textContent || '';
				if ( ! source.trim() ) {
					continue;
				}
				this.extractAllowlistedAssignments( source, applied );
			}

			this.ensureApiFetchConfigured( this.lastEnvelope );

			return applied;
		}

		applyEnvelope( envelope, applied ) {
			if ( envelope.configs && 'object' === typeof envelope.configs ) {
				var keys = Object.keys( envelope.configs );
				for ( var i = 0; i < keys.length; i += 1 ) {
					var key = keys[ i ];
					var val = envelope.configs[ key ];
					if (
						ALLOWED_CONFIG_GLOBALS[ key ] &&
						val &&
						'object' === typeof val
					) {
						window[ key ] = val;
						applied[ key ] = val;
					}
				}
			}
			if ( 'number' === typeof envelope.releaseId ) {
				window.musicWaveViewCounter = Object.assign(
					{},
					window.musicWaveViewCounter || {},
					{ releaseId: envelope.releaseId }
				);
			}
			this.ensureApiFetchConfigured( envelope );
			if (
				window.musicWavePreviewPlayer &&
				'object' === typeof window.musicWavePreviewPlayer
			) {
				if ( 'boolean' === typeof envelope.loggedIn ) {
					window.musicWavePreviewPlayer.loggedIn = envelope.loggedIn;
				}
				if (
					'string' === typeof envelope.restNonce &&
					envelope.restNonce
				) {
					window.musicWavePreviewPlayer.restNonce =
						envelope.restNonce;
				}
			}
		}

		extractAllowlistedAssignments( source, applied ) {
			var pattern = /(?:window\.|var\s+)([A-Za-z0-9_]+)\s*=\s*\{/g;
			var match = pattern.exec( source );
			while ( match ) {
				var identifier = match[ 1 ];
				var braceIndex = match.index + match[ 0 ].length - 1;
				var jsonSlice = extractBalancedBraceSlice( source, braceIndex );
				if ( jsonSlice && ALLOWED_CONFIG_GLOBALS[ identifier ] ) {
					var parsed = parseRelaxedJsonObject( jsonSlice );
					if ( parsed ) {
						window[ identifier ] = parsed;
						applied[ identifier ] = parsed;
						var candidateNonce =
							parsed.restNonce || parsed.nonce || '';
						if (
							'string' === typeof candidateNonce &&
							candidateNonce
						) {
							this.updateApiFetchNonce( candidateNonce );
						}
					}
				}
				if ( jsonSlice ) {
					pattern.lastIndex = braceIndex + jsonSlice.length;
				}
				match = pattern.exec( source );
			}
		}

		ensureApiFetchConfigured( envelope ) {
			var apiFetch = window.wp && window.wp.apiFetch;
			if ( ! apiFetch ) {
				return;
			}
			var restUrl =
				( envelope && envelope.restUrl ) ||
				( window.musicWavePreviewPlayer &&
					window.musicWavePreviewPlayer.restUrl ) ||
				'';
			var restNonce =
				( envelope && envelope.restNonce ) ||
				( window.musicWavePreviewPlayer &&
					window.musicWavePreviewPlayer.restNonce ) ||
				'';

			if (
				restUrl &&
				'function' === typeof apiFetch.createRootURLMiddleware &&
				'function' === typeof apiFetch.use &&
				! apiFetch._mwRootConfigured
			) {
				apiFetch.use( apiFetch.createRootURLMiddleware( restUrl ) );
				apiFetch._mwRootConfigured = true;
			}

			if ( restNonce ) {
				if ( apiFetch.nonceMiddleware ) {
					apiFetch.nonceMiddleware.nonce = restNonce;
				} else if (
					'function' === typeof apiFetch.createNonceMiddleware &&
					'function' === typeof apiFetch.use
				) {
					apiFetch.nonceMiddleware =
						apiFetch.createNonceMiddleware( restNonce );
					apiFetch.use( apiFetch.nonceMiddleware );
				}
			}
		}

		updateApiFetchNonce( nonce ) {
			this.ensureApiFetchConfigured( {
				restNonce: nonce,
				restUrl:
					( this.lastEnvelope && this.lastEnvelope.restUrl ) || '',
			} );
		}
	}

	/* -------------------------------------------------------------------------
	 * Layer 4B — One-Time Topological External Module Loader
	 * ---------------------------------------------------------------------- */
	class ModuleLoader {
		constructor() {
			this.loadedPromises = new window.Map();
			this.seedInitialScripts();
		}

		normalizeKey( src ) {
			try {
				var resolved = new window.URL( src, window.location.href );
				if ( resolved.origin !== window.location.origin ) {
					return null;
				}
				return resolved.origin + resolved.pathname;
			} catch ( error ) {
				return null;
			}
		}

		isPersistentCoreScript( key ) {
			for (
				var i = 0;
				i < CORE_PERSISTENT_SCRIPT_PATTERNS.length;
				i += 1
			) {
				if ( CORE_PERSISTENT_SCRIPT_PATTERNS[ i ].test( key ) ) {
					return true;
				}
			}
			return false;
		}

		seedInitialScripts() {
			var scripts = document.getElementsByTagName( 'script' );
			for ( var i = 0; i < scripts.length; i += 1 ) {
				var rawSrc =
					scripts[ i ].src || scripts[ i ].getAttribute( 'src' );
				if ( ! rawSrc ) {
					continue;
				}
				var key = this.normalizeKey( rawSrc );
				if ( key ) {
					this.loadedPromises.set( key, Promise.resolve() );
				}
			}
		}

		collectMissingUrls( doc ) {
			this.seedInitialScripts();
			var queue = [];
			var seenInDoc = Object.create( null );
			var nodes = doc.querySelectorAll( 'script[src]' );
			for ( var i = 0; i < nodes.length; i += 1 ) {
				var type = ( nodes[ i ].getAttribute( 'type' ) || '' )
					.trim()
					.toLowerCase();
				if (
					'' !== type &&
					'text/javascript' !== type &&
					'application/javascript' !== type &&
					'module' !== type
				) {
					continue;
				}
				var rawSrc = nodes[ i ].getAttribute( 'src' );
				var key = this.normalizeKey( rawSrc );
				if (
					! key ||
					seenInDoc[ key ] ||
					this.isPersistentCoreScript( key ) ||
					this.loadedPromises.has( key )
				) {
					continue;
				}
				seenInDoc[ key ] = true;
				queue.push( {
					key,
					url: new window.URL( rawSrc, window.location.href ).href,
					node: nodes[ i ],
				} );
			}
			return queue;
		}

		loadSingle( entry ) {
			if ( this.loadedPromises.has( entry.key ) ) {
				return this.loadedPromises.get( entry.key );
			}
			var promise = new Promise( function ( resolve ) {
				var tag = document.createElement( 'script' );
				copyAttributes( entry.node, tag );
				tag.src = entry.url;
				tag.removeAttribute( 'defer' );
				tag.async = false;
				var settled = false;
				var finish = function () {
					if ( settled ) {
						return;
					}
					settled = true;
					resolve();
				};
				tag.addEventListener( 'load', finish );
				tag.addEventListener( 'error', finish );
				window.setTimeout( finish, SCRIPT_TIMEOUT_MS );
				( document.head || document.documentElement ).appendChild(
					tag
				);
			} );
			this.loadedPromises.set( entry.key, promise );
			return promise;
		}

		preloadEntries( entries ) {
			var head = document.head || document.documentElement;
			if ( ! head ) {
				return;
			}
			for ( var i = 0; i < entries.length; i += 1 ) {
				var href = entries[ i ].url;
				if (
					head.querySelector(
						'link[rel="preload"][href="' + href + '"]'
					)
				) {
					continue;
				}
				var link = document.createElement( 'link' );
				link.rel = 'preload';
				link.as = 'script';
				link.href = href;
				head.appendChild( link );
			}
		}

		loadMissingFromDocument( doc ) {
			var self = this;
			var missing = this.collectMissingUrls( doc );
			if ( ! missing.length ) {
				return Promise.resolve();
			}
			// Warm HTTP/2 parallel fetches immediately while preserving strict
			// topological execution order (`hooks` -> `i18n` -> `url` -> `api-fetch` -> consumer).
			this.preloadEntries( missing );
			return missing.reduce( function ( chain, entry ) {
				return chain.then( function () {
					return self.loadSingle( entry );
				} );
			}, Promise.resolve() );
		}
	}

	/* -------------------------------------------------------------------------
	 * Layer 4C — Deterministic Controller Lifecycle Registry & Route AbortSignal
	 * ---------------------------------------------------------------------- */
	class LifecycleManager {
		constructor() {
			this.controllers = new window.Map();
			this.routeAbort = null;
			this.cleanups = [];
			this.activeContext = null;
			this.mounted = false;
		}

		createContext( options ) {
			var self = this;
			if ( this.routeAbort ) {
				this.routeAbort.abort();
			}
			this.routeAbort = new window.AbortController();
			this.cleanups = [];
			var ctx = {
				root:
					options.root ||
					document.querySelector( 'main' ) ||
					document.body,
				doc: document,
				url: options.url || window.location.href,
				isInitial: Boolean( options.isInitial ),
				signal: this.routeAbort.signal,
				onCleanup( fn ) {
					if ( 'function' === typeof fn ) {
						self.cleanups.push( fn );
					}
				},
			};
			this.activeContext = ctx;
			return ctx;
		}

		register( id, definition ) {
			if ( ! id || ! definition || 'object' !== typeof definition ) {
				return;
			}
			this.controllers.set( id, definition );
			// Late-arriving modules (lazy-loaded during a soft transition) mount immediately!
			if (
				this.mounted &&
				this.activeContext &&
				'function' === typeof definition.mount
			) {
				try {
					definition.mount( this.activeContext );
				} catch ( error ) {}
			}
		}

		unmountAll( nextUrl ) {
			var ctx = this.activeContext || {
				root: document.querySelector( 'main' ) || document.body,
				doc: document,
				url: window.location.href,
				nextUrl: nextUrl || '',
			};
			ctx.nextUrl = nextUrl || '';

			if ( this.routeAbort ) {
				try {
					this.routeAbort.abort();
				} catch ( error ) {}
				this.routeAbort = null;
			}

			var cleanupFns = this.cleanups.splice( 0, this.cleanups.length );
			for ( var i = cleanupFns.length - 1; i >= 0; i -= 1 ) {
				try {
					cleanupFns[ i ]();
				} catch ( error ) {}
			}

			this.controllers.forEach( function ( definition ) {
				if ( 'function' === typeof definition.unmount ) {
					try {
						definition.unmount( ctx );
					} catch ( error ) {}
				}
			} );

			this.mounted = false;
			document.dispatchEvent(
				new window.CustomEvent( 'mw:before-navigate', {
					detail: { from: window.location.href, to: nextUrl || '' },
				} )
			);
		}

		mountAll( options ) {
			var ctx = this.createContext( options || {} );
			this.mounted = true;
			this.controllers.forEach( function ( definition ) {
				if ( 'function' === typeof definition.mount ) {
					try {
						definition.mount( ctx );
					} catch ( error ) {}
				}
			} );
			return ctx;
		}
	}

	/* -------------------------------------------------------------------------
	 * Layer 1 & Layer 3 — Persistent Shell & Surgical Content Outlet Swapper
	 * ---------------------------------------------------------------------- */
	class ShellOutletSwapper {
		findChrome( doc ) {
			var header = doc.querySelector( '.mw-site-header' );
			if ( ! header ) {
				return null;
			}
			return header.closest( '.wp-block-template-part' ) || header;
		}

		findFooter( doc ) {
			return doc.querySelector( 'footer.wp-block-template-part' );
		}

		chromeSignature( root ) {
			var header = root.querySelector( '.mw-site-header' ) || root;
			var items = root.querySelectorAll( NAV_ITEM_SELECTOR );
			var parts = [ root.className, header.className ];
			for ( var i = 0; i < items.length; i += 1 ) {
				parts.push( ( items[ i ].textContent || '' ).trim() );
			}
			return parts.join( '|' );
		}

		canCarryChrome( live, fresh ) {
			return Boolean(
				live &&
					fresh &&
					this.chromeSignature( live ) ===
						this.chromeSignature( fresh )
			);
		}

		syncChromeState( live, fresh ) {
			var liveLinks = live.querySelectorAll( 'a[href]' );
			var freshLinks = fresh.querySelectorAll( 'a[href]' );
			if ( liveLinks.length === freshLinks.length ) {
				for ( var i = 0; i < liveLinks.length; i += 1 ) {
					liveLinks[ i ].setAttribute(
						'href',
						freshLinks[ i ].getAttribute( 'href' )
					);
					if ( freshLinks[ i ].hasAttribute( 'aria-current' ) ) {
						liveLinks[ i ].setAttribute(
							'aria-current',
							freshLinks[ i ].getAttribute( 'aria-current' )
						);
					} else {
						liveLinks[ i ].removeAttribute( 'aria-current' );
					}
				}
			}

			var liveItems = live.querySelectorAll( NAV_ITEM_SELECTOR );
			var freshItems = fresh.querySelectorAll( NAV_ITEM_SELECTOR );
			if ( liveItems.length !== freshItems.length ) {
				return;
			}
			for ( var idx = 0; idx < liveItems.length; idx += 1 ) {
				for ( var c = 0; c < NAV_CURRENT_CLASSES.length; c += 1 ) {
					liveItems[ idx ].classList.toggle(
						NAV_CURRENT_CLASSES[ c ],
						freshItems[ idx ].classList.contains(
							NAV_CURRENT_CLASSES[ c ]
						)
					);
				}
			}
		}

		closeResponsiveNavOverlay( root ) {
			if ( ! root ) {
				return;
			}
			var open = root.querySelector(
				'.wp-block-navigation__responsive-container.is-menu-open'
			);
			var close = open
				? open.querySelector(
						'.wp-block-navigation__responsive-container-close'
				  )
				: null;
			if ( close ) {
				close.click();
			}
			var openSearch = root.querySelector(
				'.mw-header-search-block[data-mw-search-open="1"]'
			);
			if ( openSearch ) {
				var popupNode =
					openSearch._mwPopup ||
					openSearch.querySelector(
						'.mw-header-search-block__popup'
					);
				var searchClose = popupNode
					? popupNode.querySelector(
							'.mw-header-search-block__close'
					  )
					: null;
				if ( searchClose ) {
					searchClose.click();
				}
			}
		}

		hasStylesheet( href ) {
			var links = document.head.querySelectorAll(
				'link[rel="stylesheet"]'
			);
			for ( var i = 0; i < links.length; i += 1 ) {
				if ( links[ i ].getAttribute( 'href' ) === href ) {
					return true;
				}
			}
			return false;
		}

		hasInlineStyle( css ) {
			var styles = document.head.getElementsByTagName( 'style' );
			for ( var i = 0; i < styles.length; i += 1 ) {
				if ( ! styles[ i ].id && styles[ i ].textContent === css ) {
					return true;
				}
			}
			return false;
		}

		mergeStyles( fresh ) {
			var self = this;
			var pending = [];

			Array.prototype.forEach.call(
				fresh.body.querySelectorAll( 'link[rel="stylesheet"][href]' ),
				function ( late ) {
					fresh.head.appendChild( late );
				}
			);

			var nodes = fresh.head.querySelectorAll(
				'link[rel="stylesheet"][href], style'
			);
			Array.prototype.forEach.call( nodes, function ( node ) {
				if ( 'LINK' === node.tagName ) {
					var href = node.getAttribute( 'href' );
					if ( self.hasStylesheet( href ) ) {
						return;
					}
					var link = document.createElement( 'link' );
					copyAttributes( node, link );
					pending.push(
						new Promise( function ( resolve ) {
							link.addEventListener( 'load', resolve );
							link.addEventListener( 'error', resolve );
							window.setTimeout( resolve, STYLE_TIMEOUT_MS );
						} )
					);
					document.head.appendChild( link );
					return;
				}

				var css = node.textContent || '';
				if ( ! css.trim() ) {
					return;
				}
				var current = node.id
					? document.getElementById( node.id )
					: null;
				if ( current && 'STYLE' === current.tagName ) {
					if ( current.textContent.indexOf( css ) === -1 ) {
						current.appendChild(
							document.createTextNode( '\n' + css )
						);
					}
					return;
				}
				if ( ! node.id && self.hasInlineStyle( css ) ) {
					return;
				}
				var style = document.createElement( 'style' );
				copyAttributes( node, style );
				style.textContent = css;
				document.head.appendChild( style );
			} );

			return Promise.all( pending );
		}

		updateHeadMetadata( fresh ) {
			if ( fresh.title ) {
				document.title = fresh.title;
			}
			[ 'lang', 'dir', 'data-mw-scheme' ].forEach( function ( name ) {
				var value = fresh.documentElement.getAttribute( name );
				if ( value ) {
					document.documentElement.setAttribute( name, value );
				}
			} );
			[
				[ 'link[rel="canonical"]', 'href' ],
				[ 'meta[name="description"]', 'content' ],
			].forEach( function ( pair ) {
				var freshNode = fresh.head.querySelector( pair[ 0 ] );
				var currentNode = document.head.querySelector( pair[ 0 ] );
				if ( freshNode && currentNode ) {
					currentNode.setAttribute(
						pair[ 1 ],
						freshNode.getAttribute( pair[ 1 ] ) || ''
					);
				}
			} );
		}

		isPersistentBodyNode( node ) {
			if ( ! node || 1 !== node.nodeType ) {
				return false;
			}
			if (
				node.id === 'wpadminbar' ||
				node.id === PROGRESS_ID ||
				node.id === ANNOUNCER_ID
			) {
				return true;
			}
			return Boolean( node.matches && node.matches( PLAYER_SELECTOR ) );
		}

		stripInertAndDuplicateBodyNodes( fresh ) {
			var selectors = [
				PLAYER_SELECTOR,
				'#wpadminbar',
				'#' + PROGRESS_ID,
				'#' + ANNOUNCER_ID,
				'script',
			];
			Array.prototype.forEach.call(
				fresh.body.querySelectorAll( selectors.join( ', ' ) ),
				function ( node ) {
					if ( node.parentNode ) {
						node.parentNode.removeChild( node );
					}
				}
			);
		}

		findMatchingOutletPair( fresh ) {
			var liveAppShell = document.body.querySelector( '.mw-app-shell' );
			var freshAppShell = fresh.body.querySelector( '.mw-app-shell' );
			if ( liveAppShell && freshAppShell ) {
				return { live: liveAppShell, fresh: freshAppShell };
			}
			if ( liveAppShell || freshAppShell ) {
				return null;
			}

			var liveMain = document.body.querySelector( 'main' );
			var freshMain = fresh.body.querySelector( 'main' );
			if ( liveMain && freshMain ) {
				return { live: liveMain, fresh: freshMain };
			}
			return null;
		}

		swap( fresh ) {
			// Clear transient scroll locks left by overlays/modals from outgoing view.
			document.body.style.overflow = '';
			document.documentElement.removeAttribute( 'data-mw-search-open' );

			this.updateHeadMetadata( fresh );
			this.stripInertAndDuplicateBodyNodes( fresh );

			var liveChrome = this.findChrome( document );
			var freshChrome = this.findChrome( fresh );
			var sameChrome = this.canCarryChrome( liveChrome, freshChrome );
			var outletPair = this.findMatchingOutletPair( fresh );

			document.body.className = fresh.body.className;

			// Fast Path: identical persistent shell chrome — replace ONLY the content outlet!
			// Header, footer, admin bar, and `#mw-global-player` stay 100% mounted in place!
			if ( sameChrome && outletPair && outletPair.live.parentNode ) {
				this.syncChromeState( liveChrome, freshChrome );
				this.closeResponsiveNavOverlay( liveChrome );

				var liveFooter = this.findFooter( document );
				var freshFooter = this.findFooter( fresh );
				if (
					liveFooter &&
					freshFooter &&
					liveFooter !== outletPair.live &&
					liveFooter.outerHTML !== freshFooter.outerHTML &&
					liveFooter.parentNode
				) {
					liveFooter.parentNode.replaceChild(
						document.adoptNode( freshFooter ),
						liveFooter
					);
				}

				var adoptedOutlet = document.adoptNode( outletPair.fresh );
				outletPair.live.parentNode.replaceChild(
					adoptedOutlet,
					outletPair.live
				);
				return adoptedOutlet;
			}

			// Fallback Path (layout mode changed or custom minimal template):
			// Replace non-persistent siblings strictly BEFORE persistent shell nodes
			// so `#mw-global-player` (`<audio>`) NEVER detaches from `document.body`!
			var slot = null;
			if ( sameChrome && freshChrome && freshChrome.parentNode ) {
				this.syncChromeState( liveChrome, freshChrome );
				slot = fresh.createElement( 'div' );
				freshChrome.parentNode.replaceChild( slot, freshChrome );
			}

			var anchor =
				document.querySelector( PLAYER_SELECTOR ) ||
				document.getElementById( PROGRESS_ID ) ||
				null;

			var existingChildren = Array.prototype.slice.call(
				document.body.childNodes
			);
			for ( var i = 0; i < existingChildren.length; i += 1 ) {
				var current = existingChildren[ i ];
				if ( this.isPersistentBodyNode( current ) ) {
					continue;
				}
				if ( slot && current === liveChrome ) {
					continue;
				}
				if ( current.parentNode === document.body ) {
					document.body.removeChild( current );
				}
			}

			var incomingFragment = document.createDocumentFragment();
			var freshChildren = Array.prototype.slice.call(
				fresh.body.childNodes
			);
			for ( var j = 0; j < freshChildren.length; j += 1 ) {
				incomingFragment.appendChild(
					document.adoptNode( freshChildren[ j ] )
				);
			}
			document.body.insertBefore( incomingFragment, anchor );

			if ( slot && slot.parentNode ) {
				slot.parentNode.replaceChild( liveChrome, slot );
				this.closeResponsiveNavOverlay( liveChrome );
			}

			return document.querySelector( 'main' ) || document.body;
		}

		announceRoute() {
			var region = document.getElementById( ANNOUNCER_ID );
			if ( ! region ) {
				region = document.createElement( 'div' );
				region.id = ANNOUNCER_ID;
				region.setAttribute( 'aria-live', 'polite' );
				region.setAttribute( 'role', 'status' );
				region.style.position = 'absolute';
				region.style.width = '1px';
				region.style.height = '1px';
				region.style.overflow = 'hidden';
				region.style.clipPath = 'inset(50%)';
				document.body.appendChild( region );
			}
			region.textContent = document.title;
			var main = document.querySelector( 'main' );
			if ( main ) {
				main.setAttribute( 'tabindex', '-1' );
				main.focus( { preventScroll: true } );
			}
		}

		startProgress( stateHolder ) {
			var bar = document.getElementById( PROGRESS_ID );
			if ( ! bar && document.body ) {
				bar = document.createElement( 'div' );
				bar.id = PROGRESS_ID;
				bar.className = 'mw-nav-progress';
				bar.setAttribute( 'aria-hidden', 'true' );
				document.body.appendChild( bar );
			}
			if ( ! bar ) {
				return;
			}
			if ( stateHolder.progressTimer ) {
				window.clearInterval( stateHolder.progressTimer );
				stateHolder.progressTimer = null;
			}
			var pct = 0.24;
			bar.classList.add( 'is-active' );
			bar.style.transform = 'scaleX(' + pct + ')';
			stateHolder.progressTimer = window.setInterval( function () {
				pct = pct + ( 0.88 - pct ) * 0.25;
				bar.style.transform = 'scaleX(' + pct.toFixed( 3 ) + ')';
			}, 160 );
		}

		finishProgress( stateHolder ) {
			var bar = document.getElementById( PROGRESS_ID );
			if ( stateHolder.progressTimer ) {
				window.clearInterval( stateHolder.progressTimer );
				stateHolder.progressTimer = null;
			}
			if ( ! bar ) {
				return;
			}
			bar.style.transform = 'scaleX(1)';
			window.setTimeout( function () {
				bar.classList.remove( 'is-active' );
				window.setTimeout( function () {
					if ( ! bar.classList.contains( 'is-active' ) ) {
						bar.style.transform = 'scaleX(0)';
					}
				}, 200 );
			}, 140 );
		}
	}

	/* -------------------------------------------------------------------------
	 * Layer 2 — Soft Navigation Router & O(1) LRU Prefetch Engine
	 * ---------------------------------------------------------------------- */
	class SoftNavigationRouter {
		constructor( deps ) {
			this.stateManager = deps.stateManager;
			this.moduleLoader = deps.moduleLoader;
			this.lifecycle = deps.lifecycle;
			this.swapper = deps.swapper;
			this.onSyncPlayer = deps.onSyncPlayer;

			this.abortController = null;
			this.sequence = 0;
			this.swapped = false;
			this.prefetchCache = new window.Map();
			this.prefetchControllers = new window.Map();
			this.hoverTimer = null;
			this.progressState = { progressTimer: null };
			this.lastPathSearch =
				window.location.pathname + window.location.search;
		}

		isEnabled() {
			var cfg = window.musicWavePreviewPlayer || {};
			return false !== cfg.persistentNav;
		}

		isSupported() {
			return (
				this.isEnabled() &&
				Boolean(
					window.fetch &&
						window.DOMParser &&
						window.URL &&
						window.CustomEvent &&
						window.history &&
						window.history.pushState
				)
			);
		}

		isExcludedUrl( url ) {
			if (
				url.searchParams.has( 'add-to-cart' ) ||
				url.searchParams.has( 'wc-ajax' ) ||
				url.searchParams.has( 'remove_item' ) ||
				'logout' === url.searchParams.get( 'action' )
			) {
				return true;
			}
			var path = url.pathname;
			return (
				/\/wp-(admin|login|json)/.test( path ) ||
				/\/(cart|checkout)(\/|$)/.test( path ) ||
				FILE_HREF_RE.test( path )
			);
		}

		shouldIntercept( link, event ) {
			if (
				! this.isSupported() ||
				event.defaultPrevented ||
				0 !== event.button ||
				event.metaKey ||
				event.ctrlKey ||
				event.shiftKey ||
				event.altKey
			) {
				return false;
			}
			if (
				! link ||
				( link.target && '_self' !== link.target ) ||
				link.hasAttribute( 'download' ) ||
				link.hasAttribute( 'data-mw-no-swap' )
			) {
				return false;
			}
			// Catalog block filter form, active filter reset chips, and query pagination own their AJAX swap;
			// release / artist / genre links inside `.mw-catalog-results` flow cleanly through this router!
			if (
				link.closest(
					'form.mw-catalog-filters, ' +
						'.mw-catalog-active-filters, ' +
						'.mw-catalog-results__reset, ' +
						'.wp-block-query-pagination'
				)
			) {
				return false;
			}
			if (
				link.closest(
					'.mw-user-dashboard__tabs, ' +
						'[data-mw-dashboard-tab], ' +
						'[data-mw-library-panel] .mw-music-library__tabs, ' +
						'[data-mw-library-panel] .mw-music-library__pagination, ' +
						'.mw-playlists__toggle, ' +
						'.mw-public-playlists__toggle, ' +
						'.mw-public-playlists__pagination, ' +
						'[data-mw-public-toggle], ' +
						'[data-mw-public-page], ' +
						'[data-mw-public-clear]'
				)
			) {
				return false;
			}
			if ( link.closest( '#wpadminbar' ) ) {
				return false;
			}
			if (
				link.hasAttribute( 'data-wp-on--click' ) ||
				link.hasAttribute( 'data-wp-on-async--click' )
			) {
				return false;
			}

			var href = link.getAttribute( 'href' );
			if (
				! href ||
				/^(#|mailto:|tel:|javascript:|blob:|data:)/i.test( href )
			) {
				return false;
			}

			var url;
			try {
				url = new window.URL( href, window.location.href );
			} catch ( error ) {
				return false;
			}
			if (
				url.origin !== window.location.origin ||
				! /^https?:$/.test( url.protocol )
			) {
				return false;
			}
			if ( this.isExcludedUrl( url ) ) {
				return false;
			}
			return (
				url.pathname !== window.location.pathname ||
				url.search !== window.location.search
			);
		}

		mergeHistoryState( extra ) {
			var state = {};
			if (
				window.history.state &&
				'object' === typeof window.history.state
			) {
				Object.keys( window.history.state ).forEach( function ( key ) {
					state[ key ] = window.history.state[ key ];
				} );
			}
			Object.keys( extra ).forEach( function ( key ) {
				state[ key ] = extra[ key ];
			} );
			return state;
		}

		buildRouteState( scrollY ) {
			var state = { mwNav: true, mwScrollY: scrollY || 0 };
			if (
				window.history.state &&
				window.history.state.wpInteractivityId
			) {
				state.wpInteractivityId =
					window.history.state.wpInteractivityId;
			}
			return state;
		}

		scrollToTarget( url, scrollY ) {
			try {
				var parsed = new window.URL( url, window.location.href );
				if (
					parsed.hash &&
					parsed.hash.length > 1 &&
					0 !== parsed.hash.indexOf( '#mw-' )
				) {
					var target = document.getElementById(
						decodeURIComponent( parsed.hash.slice( 1 ) )
					);
					if ( target && target.scrollIntoView ) {
						target.scrollIntoView( { behavior: 'auto' } );
						return;
					}
				}
			} catch ( error ) {}
			try {
				window.scrollTo( 0, scrollY || 0 );
			} catch ( scrollError ) {}
		}

		resolveFinalUrl( requestedUrl, responseUrl ) {
			if ( ! responseUrl ) {
				return requestedUrl;
			}
			try {
				var req = new window.URL( requestedUrl, window.location.href );
				var res = new window.URL( responseUrl, window.location.href );
				if ( req.hash && ! res.hash ) {
					res.hash = req.hash;
				}
				return res.href;
			} catch ( error ) {
				return requestedUrl;
			}
		}

		abortBackgroundPrefetches( keepKey ) {
			this.prefetchControllers.forEach( function ( ctrl, key ) {
				if ( key !== keepKey ) {
					ctrl.abort();
				}
			} );
			this.prefetchControllers.clear();
		}

		invalidatePrefetchCache() {
			this.abortBackgroundPrefetches( '' );
			this.prefetchCache.clear();
		}

		isPrefetchStale( url ) {
			var entry = this.prefetchCache.get( url );
			return ! entry || Date.now() - entry.created > PREFETCH_TTL_MS;
		}

		fetchDocumentPayload( fetchUrl, signal ) {
			return window
				.fetch( fetchUrl, {
					credentials: 'same-origin',
					redirect: 'follow',
					headers: { 'X-MW-Nav': '1' },
					signal,
				} )
				.then( function ( response ) {
					var contentType = response.headers
						? response.headers.get( 'content-type' ) || ''
						: 'text/html';
					if (
						response.status >= 500 ||
						( contentType &&
							contentType.indexOf( 'text/html' ) === -1 )
					) {
						throw new Error( 'invalid-response' );
					}
					return response.text().then( function ( html ) {
						return {
							url: response.url || fetchUrl,
							status: response.status,
							contentType,
							html,
						};
					} );
				} );
		}

		prefetch( link ) {
			var self = this;
			if ( ! this.isSupported() || this.abortController ) {
				return;
			}
			var connection = navigator.connection || {};
			if (
				connection.saveData ||
				'slow-2g' === connection.effectiveType ||
				'2g' === connection.effectiveType
			) {
				return;
			}
			if (
				! this.shouldIntercept( link, {
					defaultPrevented: false,
					button: 0,
				} )
			) {
				return;
			}
			var parsed = new window.URL(
				link.getAttribute( 'href' ),
				window.location.href
			);
			parsed.hash = '';
			var fetchKey = parsed.href;

			if ( ! this.isPrefetchStale( fetchKey ) ) {
				var hit = this.prefetchCache.get( fetchKey );
				this.prefetchCache.delete( fetchKey );
				this.prefetchCache.set( fetchKey, hit );
				return;
			}

			// Cap concurrent background prefetches to 2 so card hover sweeps never
			// starve real click navigations on the browser's per-origin TCP pool.
			if ( this.prefetchControllers.size >= 2 ) {
				var oldestActiveKey = this.prefetchControllers
					.keys()
					.next().value;
				var oldestCtrl =
					this.prefetchControllers.get( oldestActiveKey );
				if ( oldestCtrl ) {
					oldestCtrl.abort();
				}
				this.prefetchControllers.delete( oldestActiveKey );
				this.prefetchCache.delete( oldestActiveKey );
			}

			while ( this.prefetchCache.size >= PREFETCH_LIMIT ) {
				var oldestKey = this.prefetchCache.keys().next().value;
				this.prefetchCache.delete( oldestKey );
			}

			var prefetchAbort = new window.AbortController();
			this.prefetchControllers.set( fetchKey, prefetchAbort );

			var promise = this.fetchDocumentPayload(
				fetchKey,
				prefetchAbort.signal
			)
				.then( function ( payload ) {
					self.prefetchControllers.delete( fetchKey );
					return payload;
				} )
				.catch( function ( err ) {
					self.prefetchControllers.delete( fetchKey );
					self.prefetchCache.delete( fetchKey );
					throw err;
				} );

			this.prefetchCache.set( fetchKey, {
				created: Date.now(),
				promise,
			} );
		}

		commitDocument( fresh, finalUrl, push, scrollY ) {
			this.swapped = true;
			this.sequence += 1;

			// 1. Cleanly unmount outgoing controllers before mutating the live DOM.
			this.lifecycle.unmountAll( finalUrl );

			// 2. Commit new URL into History BEFORE mounting controllers so `window.location`
			//    (including `.pathname`, `.search`, and `#mw-*` tab hash) matches the new route!
			if ( push ) {
				window.history.pushState(
					this.buildRouteState( 0 ),
					'',
					finalUrl
				);
			}
			this.lastPathSearch =
				window.location.pathname + window.location.search;

			// 3. Synchronize pure JSON route configuration, `wp.apiFetch` middleware & nonces.
			this.stateManager.syncFromDocument( fresh );

			// 4. Atomically swap the content outlet while keeping `#mw-global-player` untouched.
			var liveOutlet = this.swapper.swap( fresh );

			// 5. Scroll & accessible announcement immediately after DOM swap.
			this.scrollToTarget( finalUrl, scrollY );
			this.swapper.announceRoute();
			this.onSyncPlayer();

			// 6. All required external modules are ALREADY loaded before `commitDocument()`,
			//    so EVERY controller mounts synchronously in the exact same paint frame!
			this.lifecycle.mountAll( {
				root: liveOutlet,
				url: finalUrl,
				isInitial: false,
			} );
			document.dispatchEvent(
				new window.CustomEvent( 'mw-page-rendered', {
					detail: { url: finalUrl, phase: 'ready' },
				} )
			);
			document.dispatchEvent(
				new window.CustomEvent( 'mw:after-navigate', {
					detail: { url: finalUrl },
				} )
			);
			return Promise.resolve();
		}

		navigate( targetUrl, push, scrollY ) {
			var self = this;
			if ( ! this.isSupported() ) {
				window.location.assign( targetUrl );
				return Promise.resolve();
			}

			if ( push ) {
				window.history.replaceState(
					this.mergeHistoryState( {
						mwNav: true,
						mwScrollY: window.scrollY || 0,
					} ),
					''
				);
			}

			if ( this.hoverTimer ) {
				window.clearTimeout( this.hoverTimer );
				this.hoverTimer = null;
			}
			if ( this.abortController ) {
				this.abortController.abort();
			}
			var controller = new window.AbortController();
			this.abortController = controller;
			this.swapper.startProgress( this.progressState );

			var hardFallback = function ( dest ) {
				self.swapper.finishProgress( self.progressState );
				if ( ! controller.signal.aborted ) {
					window.location.assign( dest || targetUrl );
				}
			};

			var fetchKeyUrl = new window.URL( targetUrl, window.location.href );
			fetchKeyUrl.hash = '';
			var fetchKey = fetchKeyUrl.href;

			// Immediately cancel unrelated background prefetches so user click gets 100% bandwidth!
			this.abortBackgroundPrefetches( fetchKey );

			var request;
			if (
				! this.prefetchCache.has( fetchKey ) ||
				this.isPrefetchStale( fetchKey )
			) {
				request = this.fetchDocumentPayload(
					targetUrl,
					controller.signal
				);
			} else {
				request = this.prefetchCache.get( fetchKey ).promise;
				this.prefetchCache.delete( fetchKey );
			}

			var resolvedUrl = targetUrl;

			return request
				.then( function ( payload ) {
					if ( controller !== self.abortController ) {
						return undefined;
					}
					resolvedUrl = self.resolveFinalUrl(
						targetUrl,
						payload.url
					);
					var finalParsed = new window.URL(
						resolvedUrl,
						window.location.href
					);
					if ( self.isExcludedUrl( finalParsed ) ) {
						throw new Error( 'excluded-redirect' );
					}

					var fresh = new window.DOMParser().parseFromString(
						payload.html,
						'text/html'
					);
					if ( ! fresh.body || ! fresh.body.children.length ) {
						hardFallback( resolvedUrl );
						return undefined;
					}

					// Pre-prime incoming globals (`window.musicWave*`) BEFORE executing any
					// newly fetched IIFE script, and load missing CSS + JS concurrently!
					self.stateManager.syncFromDocument( fresh );

					return Promise.all( [
						self.swapper.mergeStyles( fresh ),
						self.moduleLoader.loadMissingFromDocument( fresh ),
					] ).then( function () {
						if ( controller !== self.abortController ) {
							return undefined;
						}
						return self
							.commitDocument(
								fresh,
								resolvedUrl,
								push,
								push ? 0 : scrollY
							)
							.then( function () {
								self.abortController = null;
								self.swapper.finishProgress(
									self.progressState
								);
							} );
					} );
				} )
				.catch( function ( error ) {
					if ( controller === self.abortController ) {
						self.abortController = null;
					}
					if ( error && 'AbortError' === error.name ) {
						return;
					}
					hardFallback( resolvedUrl );
				} );
		}

		bindEvents() {
			var self = this;

			document.addEventListener(
				'pointerover',
				function ( event ) {
					var link =
						event.target && event.target.closest
							? event.target.closest( 'a[href]' )
							: null;
					if ( self.hoverTimer ) {
						window.clearTimeout( self.hoverTimer );
						self.hoverTimer = null;
					}
					if ( link ) {
						self.hoverTimer = window.setTimeout( function () {
							self.hoverTimer = null;
							self.prefetch( link );
						}, 65 );
					}
				},
				{ passive: true }
			);

			document.addEventListener( 'focusin', function ( event ) {
				var link =
					event.target && event.target.closest
						? event.target.closest( 'a[href]' )
						: null;
				if ( link ) {
					self.prefetch( link );
				}
			} );

			// Intercept at bubble tail (`window`) so local interactive controls run first.
			window.addEventListener(
				'click',
				function ( event ) {
					var link =
						event.target && event.target.closest
							? event.target.closest( 'a[href]' )
							: null;
					if ( ! self.shouldIntercept( link, event ) ) {
						return;
					}
					event.preventDefault();
					self.navigate(
						new window.URL(
							link.getAttribute( 'href' ),
							window.location.href
						).href,
						true,
						0
					);
				},
				false
			);

			window.addEventListener( 'popstate', function ( event ) {
				var currentPathSearch =
					window.location.pathname + window.location.search;
				// In-page hash transition on the exact same route: never hard-reload!
				if ( currentPathSearch === self.lastPathSearch ) {
					window.dispatchEvent( new window.Event( 'mw:hash-sync' ) );
					return;
				}

				if ( ! self.swapped ) {
					self.lastPathSearch = currentPathSearch;
					return;
				}

				if ( event.state && event.state.mwNav ) {
					self.navigate(
						window.location.href,
						false,
						event.state.mwScrollY || 0
					);
					return;
				}
				if (
					event.state &&
					event.state.mwCatalog &&
					document.querySelector( 'form.mw-catalog-filters' )
				) {
					self.lastPathSearch = currentPathSearch;
					return;
				}
				if (
					event.state &&
					event.state.mwLibraryNav &&
					document.querySelector( '[data-mw-library-panel]' )
				) {
					self.lastPathSearch = currentPathSearch;
					return;
				}
				if (
					event.state &&
					'undefined' !== typeof event.state.mwPublicSearch &&
					document.querySelector( '[data-mw-public-playlists]' )
				) {
					self.lastPathSearch = currentPathSearch;
					return;
				}

				// Soft-restore even if history entry lost custom state, falling back cleanly if unsupported.
				self.navigate( window.location.href, false, 0 );
			} );

			document.addEventListener( 'submit', function () {
				self.invalidatePrefetchCache();
			} );

			document.addEventListener( 'mw:invalidate-cache', function () {
				self.invalidatePrefetchCache();
			} );

			// Automatically invalidate stale prefetch entries whenever `wp.apiFetch` runs a mutation.
			if (
				window.wp &&
				window.wp.apiFetch &&
				'function' === typeof window.wp.apiFetch.use &&
				! window.wp.apiFetch._mwPrefetchGuardInstalled
			) {
				window.wp.apiFetch._mwPrefetchGuardInstalled = true;
				window.wp.apiFetch.use( function ( options, next ) {
					var method = String(
						( options && options.method ) || 'GET'
					).toUpperCase();
					if ( 'GET' !== method && 'HEAD' !== method ) {
						self.invalidatePrefetchCache();
					}
					return next( options );
				} );
			}
		}

		bootInitialHistory() {
			this.lastPathSearch =
				window.location.pathname + window.location.search;
			if ( this.isSupported() ) {
				window.history.replaceState(
					this.mergeHistoryState( { mwNav: true } ),
					''
				);
			}
		}
	}

	/* -------------------------------------------------------------------------
	 * Facade — `window.MusicWaveApp`
	 * ---------------------------------------------------------------------- */
	class MusicWaveApplication {
		constructor() {
			var self = this;
			this._booted = true;
			this.player = null;
			this.stateManager = new RouteStateManager();
			this.moduleLoader = new ModuleLoader();
			this.lifecycle = new LifecycleManager();
			this.swapper = new ShellOutletSwapper();
			this.router = new SoftNavigationRouter( {
				stateManager: this.stateManager,
				moduleLoader: this.moduleLoader,
				lifecycle: this.lifecycle,
				swapper: this.swapper,
				onSyncPlayer() {
					if (
						self.player &&
						'function' === typeof self.player.sync
					) {
						self.player.sync();
					}
				},
			} );
		}

		bindPlayer( controller ) {
			this.player = controller;
		}

		registerController( id, definition ) {
			this.lifecycle.register( id, definition );
		}

		invalidateCache() {
			this.router.invalidatePrefetchCache();
		}

		navigate( url ) {
			return this.router.navigate(
				new window.URL( url, window.location.href ).href,
				true,
				0
			);
		}

		boot() {
			this.router.bootInitialHistory();
			this.lifecycle.mountAll( {
				root: document.querySelector( 'main' ) || document.body,
				url: window.location.href,
				isInitial: true,
			} );
		}
	}

	var app = new MusicWaveApplication();
	var queued = Array.isArray( window.MusicWaveAppQueue )
		? window.MusicWaveAppQueue.splice( 0, window.MusicWaveAppQueue.length )
		: [];

	window.MusicWaveApp = app;
	window._mwNavigate = function ( url ) {
		return app.navigate( url );
	};

	app.router.bindEvents();

	for ( var q = 0; q < queued.length; q += 1 ) {
		if ( queued[ q ] && queued[ q ].id && queued[ q ].definition ) {
			app.registerController( queued[ q ].id, queued[ q ].definition );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			app.boot();
		} );
	} else {
		app.boot();
	}
} )();
