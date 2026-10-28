/**
 * Horizontal release sliders & scroll shelves controller.
 *
 * Shared `ScrollGeometry` encapsulates direction-aware scroll measurements across
 * RTL and LTR writing modes so `[data-mw-slider]` carousels and
 * `.mw-release-shelf--scroll` rails share one tested geometry engine (DRY).
 *
 * @package
 */
( function () {
	'use strict';

	var CHEVRON_PREVIOUS =
		'<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m15 18-6-6 6-6"/></svg>';
	var CHEVRON_NEXT =
		'<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m9 18 6-6-6-6"/></svg>';

	var reducedMotion =
		'function' === typeof window.matchMedia
			? window.matchMedia( '(prefers-reduced-motion: reduce)' )
			: { matches: false };

	function getLabels() {
		return window.musicwaveSlider || {};
	}

	function scheduleFrame( callback ) {
		if ( 'function' === typeof window.requestAnimationFrame ) {
			window.requestAnimationFrame( callback );
		} else {
			callback();
		}
	}

	/**
	 * Shared direction-aware geometry helper (strict DRY across carousels & shelves).
	 */
	var ScrollGeometry = {
		isRtl( element ) {
			if ( ! element ) {
				return 'rtl' === document.documentElement.dir;
			}
			var computed =
				'function' === typeof window.getComputedStyle
					? window.getComputedStyle( element ).direction
					: '';
			if ( computed ) {
				return 'rtl' === computed;
			}
			var ancestorDir = element.closest
				? element.closest( '[dir]' )
				: null;
			if ( ancestorDir ) {
				return 'rtl' === ancestorDir.getAttribute( 'dir' );
			}
			return 'rtl' === document.documentElement.dir;
		},

		maxScroll( viewport ) {
			if ( ! viewport ) {
				return 0;
			}
			return Math.max(
				0,
				( viewport.scrollWidth || 0 ) - ( viewport.clientWidth || 0 )
			);
		},

		logicalScroll( viewport ) {
			if ( ! viewport ) {
				return 0;
			}
			return Math.min(
				this.maxScroll( viewport ),
				Math.max( 0, Math.abs( viewport.scrollLeft || 0 ) )
			);
		},

		isAtStart( viewport ) {
			return this.logicalScroll( viewport ) <= 2;
		},

		isAtEnd( viewport ) {
			return (
				this.logicalScroll( viewport ) >= this.maxScroll( viewport ) - 2
			);
		},

		stride( viewport, first, second ) {
			var measured = 0;
			if (
				first &&
				second &&
				'function' === typeof first.getBoundingClientRect
			) {
				var rectA = first.getBoundingClientRect();
				var rectB = second.getBoundingClientRect();
				measured = Math.abs( rectB.left - rectA.left );
			}
			if ( measured <= 0 && first && second ) {
				measured = Math.abs(
					( second.offsetLeft || 0 ) - ( first.offsetLeft || 0 )
				);
			}
			if ( measured <= 0 && first ) {
				var rect =
					'function' === typeof first.getBoundingClientRect
						? first.getBoundingClientRect()
						: null;
				measured =
					( rect && rect.width ) ||
					first.offsetWidth ||
					first.clientWidth ||
					0;
			}
			if ( measured <= 0 && viewport ) {
				measured = Math.round( ( viewport.clientWidth || 320 ) * 0.8 );
			}
			return Math.max( 1, Math.round( measured ) );
		},

		pageDistance( viewport, itemStep ) {
			var safeStep = Math.max( 1, itemStep || 1 );
			var viewWidth = viewport ? viewport.clientWidth || 0 : 0;
			if ( viewWidth <= 0 ) {
				return safeStep;
			}
			var visible = Math.max( 1, Math.floor( viewWidth / safeStep ) );
			return Math.max( safeStep, visible * safeStep );
		},

		scrollTo( viewport, logicalTarget ) {
			var clamped = Math.max(
				0,
				Math.min( this.maxScroll( viewport ), logicalTarget )
			);
			var rawLeft = this.isRtl( viewport ) ? -clamped : clamped;
			if ( 0 === rawLeft ) {
				rawLeft = 0;
			}
			var behavior = reducedMotion.matches ? 'auto' : 'smooth';

			if ( 'function' === typeof viewport.scrollTo ) {
				try {
					viewport.scrollTo( {
						left: rawLeft,
						behavior,
					} );
				} catch ( err ) {
					viewport.scrollLeft = rawLeft;
				}
			} else {
				viewport.scrollLeft = rawLeft;
			}

			// Headless / non-animating environments implement `scrollTo` as a no-op
			// when smooth scrolling is unimplemented; guarantee immediate fallback.
			if ( 'auto' === behavior && viewport.scrollLeft !== rawLeft ) {
				viewport.scrollLeft = rawLeft;
			}

			return clamped;
		},
	};

	/**
	 * Ensure any `.mw-release-shelf--scroll` (including dynamically hydrated
	 * shelves from `listening.js`) owns accessible prev/next `.mw-release-shelf__nav-button`
	 * controls and viewport attributes.
	 */
	var generatedShelfCounter = 0;

	function ensureShelfChrome( shelf ) {
		var viewport =
			shelf.querySelector( '[data-mw-shelf-viewport]' ) ||
			shelf.querySelector( '.mw-release-shelf__items' );
		if ( ! viewport ) {
			return null;
		}

		if ( ! viewport.id ) {
			generatedShelfCounter += 1;
			viewport.id = 'mw-shelf-auto-viewport-' + generatedShelfCounter;
		}
		if ( ! viewport.hasAttribute( 'data-mw-shelf-viewport' ) ) {
			viewport.setAttribute( 'data-mw-shelf-viewport', '' );
		}
		if ( ! viewport.hasAttribute( 'tabindex' ) ) {
			viewport.setAttribute( 'tabindex', '0' );
		}

		var previous = shelf.querySelector( '[data-mw-shelf-previous]' );
		var next = shelf.querySelector( '[data-mw-shelf-next]' );

		if ( ! previous && ! next ) {
			var labels = getLabels();
			var nav = document.createElement( 'div' );
			nav.className = 'mw-release-shelf__nav';

			previous = document.createElement( 'button' );
			previous.type = 'button';
			previous.className = 'mw-release-shelf__nav-button';
			previous.setAttribute( 'data-mw-shelf-previous', '' );
			previous.setAttribute( 'aria-controls', viewport.id );
			previous.setAttribute( 'aria-label', labels.previous || 'قبلی' );
			previous.disabled = true;
			previous.innerHTML = CHEVRON_PREVIOUS;

			next = document.createElement( 'button' );
			next.type = 'button';
			next.className = 'mw-release-shelf__nav-button';
			next.setAttribute( 'data-mw-shelf-next', '' );
			next.setAttribute( 'aria-controls', viewport.id );
			next.setAttribute( 'aria-label', labels.next || 'بعدی' );
			next.disabled = true;
			next.innerHTML = CHEVRON_NEXT;

			nav.appendChild( previous );
			nav.appendChild( next );
			shelf.insertBefore( nav, viewport );
		}

		return {
			viewport,
			previous,
			next,
			status: shelf.querySelector( '[data-mw-shelf-status]' ),
		};
	}

	/**
	 * Attach observers (`ResizeObserver`, `IntersectionObserver`, `MutationObserver`,
	 * pending `<img>` loads, and window `resize`) to a scrollable surface.
	 */
	function observeSurface( container, viewport, onSync ) {
		var resizeObserver = null;
		var intersectionObserver = null;
		var mutationObserver = null;

		if ( 'function' === typeof window.ResizeObserver ) {
			resizeObserver = new window.ResizeObserver( function () {
				onSync();
			} );
			resizeObserver.observe( container );
			if ( viewport !== container ) {
				resizeObserver.observe( viewport );
			}
		}

		if ( 'function' === typeof window.IntersectionObserver ) {
			intersectionObserver = new window.IntersectionObserver(
				function ( entries ) {
					for ( var i = 0; i < entries.length; i += 1 ) {
						if ( entries[ i ].isIntersecting ) {
							onSync();
							break;
						}
					}
				},
				{ rootMargin: '200px 0px' }
			);
			intersectionObserver.observe( container );
		}

		if ( 'function' === typeof window.MutationObserver ) {
			mutationObserver = new window.MutationObserver( function () {
				onSync();
			} );
			mutationObserver.observe( viewport, { childList: true } );
		}

		window.addEventListener( 'resize', onSync );

		Array.prototype.forEach.call(
			viewport.querySelectorAll( 'img' ),
			function ( image ) {
				if ( ! image.complete ) {
					image.addEventListener( 'load', onSync, { once: true } );
				}
			}
		);

		return function disconnectObservers() {
			if ( resizeObserver ) {
				resizeObserver.disconnect();
			}
			if ( intersectionObserver ) {
				intersectionObserver.disconnect();
			}
			if ( mutationObserver ) {
				mutationObserver.disconnect();
			}
			window.removeEventListener( 'resize', onSync );
		};
	}

	function initializeSlider( slider ) {
		var viewport = slider.querySelector( '[data-mw-slider-viewport]' );
		var previous = slider.querySelector( '[data-mw-slider-previous]' );
		var next = slider.querySelector( '[data-mw-slider-next]' );
		var dots = slider.querySelector( '[data-mw-slider-dots]' );

		if ( ! viewport || ( ! previous && ! next && ! dots ) ) {
			return null;
		}

		function getSlides() {
			return Array.prototype.slice.call(
				slider.querySelectorAll( '.mw-release-slider__slide' )
			);
		}

		if ( getSlides().length < 2 ) {
			return null;
		}

		var status = slider.querySelector( '[data-mw-slider-status]' );
		var autoplay = '1' === slider.getAttribute( 'data-autoplay' );
		var loop = '1' === slider.getAttribute( 'data-loop' );
		var pauseOnHover = '1' === slider.getAttribute( 'data-pause-hover' );
		var interval =
			parseInt( slider.getAttribute( 'data-interval' ), 10 ) || 5000;
		var timer = null;
		var paused = false;
		var pageCount = 1;

		function step() {
			var slides = getSlides();
			return ScrollGeometry.stride( viewport, slides[ 0 ], slides[ 1 ] );
		}

		function visibleCount() {
			return Math.max(
				1,
				Math.round( ( viewport.clientWidth || 1 ) / step() )
			);
		}

		function measurePageCount() {
			pageCount = Math.max(
				1,
				Math.ceil( getSlides().length / visibleCount() )
			);
		}

		function currentPage() {
			var pageWidth = Math.max( 1, step() * visibleCount() );
			return Math.max(
				0,
				Math.min(
					pageCount - 1,
					Math.round(
						ScrollGeometry.logicalScroll( viewport ) / pageWidth
					)
				)
			);
		}

		function update() {
			measurePageCount();
			var page = currentPage();
			var atStart = ScrollGeometry.isAtStart( viewport );
			var atEnd = ScrollGeometry.isAtEnd( viewport );
			var labels = getLabels();

			if ( previous ) {
				previous.disabled = ! loop && atStart;
			}
			if ( next ) {
				next.disabled = ! loop && atEnd;
			}
			if ( dots ) {
				Array.prototype.forEach.call(
					dots.children,
					function ( dot, index ) {
						dot.setAttribute(
							'aria-current',
							index === page ? 'true' : 'false'
						);
					}
				);
			}
			if ( status ) {
				status.textContent = (
					labels.status || 'گروه اسلاید %1$d از %2$d'
				)
					.replace( '%1$d', String( page + 1 ) )
					.replace( '%2$d', String( pageCount ) );
			}
		}

		function goTo( page ) {
			ScrollGeometry.scrollTo( viewport, page * step() * visibleCount() );
			scheduleFrame( update );
		}

		function move( direction ) {
			measurePageCount();
			var target = currentPage() + direction;

			if ( target >= pageCount ) {
				target = loop ? 0 : pageCount - 1;
			}
			if ( target < 0 ) {
				target = loop ? pageCount - 1 : 0;
			}
			goTo( target );
		}

		function buildDots() {
			measurePageCount();
			if ( ! dots ) {
				update();
				return;
			}
			var labels = getLabels();
			if ( dots.children.length !== pageCount ) {
				dots.replaceChildren();
				for ( var index = 0; index < pageCount; index += 1 ) {
					( function ( page ) {
						var dot = document.createElement( 'button' );
						dot.type = 'button';
						dot.setAttribute(
							'aria-label',
							(
								labels.goToGroup || 'رفتن به گروه اسلاید %d'
							).replace( '%d', String( page + 1 ) )
						);
						dot.addEventListener( 'click', function () {
							goTo( page );
							start();
						} );
						dots.appendChild( dot );
					} )( index );
				}
			}
			update();
		}

		function stop() {
			if ( timer ) {
				window.clearInterval( timer );
				timer = null;
			}
		}

		function start() {
			stop();
			if (
				! autoplay ||
				paused ||
				document.hidden ||
				reducedMotion.matches
			) {
				return;
			}
			timer = window.setInterval( function () {
				move( 1 );
			}, interval );
		}

		if ( previous ) {
			previous.addEventListener( 'click', function () {
				move( -1 );
				start();
			} );
		}
		if ( next ) {
			next.addEventListener( 'click', function () {
				move( 1 );
				start();
			} );
		}

		viewport.addEventListener(
			'scroll',
			function () {
				scheduleFrame( update );
			},
			{ passive: true }
		);
		viewport.addEventListener( 'pointerdown', stop );
		viewport.addEventListener( 'pointerup', start );
		viewport.addEventListener( 'keydown', function ( event ) {
			var forwardKey = ScrollGeometry.isRtl( viewport )
				? 'ArrowLeft'
				: 'ArrowRight';
			var backwardKey = ScrollGeometry.isRtl( viewport )
				? 'ArrowRight'
				: 'ArrowLeft';

			if ( backwardKey === event.key ) {
				event.preventDefault();
				move( -1 );
			} else if ( forwardKey === event.key ) {
				event.preventDefault();
				move( 1 );
			}
		} );

		if ( pauseOnHover ) {
			slider.addEventListener( 'mouseenter', function () {
				paused = true;
				stop();
			} );
			slider.addEventListener( 'mouseleave', function () {
				paused = false;
				start();
			} );
			slider.addEventListener( 'focusin', function () {
				paused = true;
				stop();
			} );
			slider.addEventListener( 'focusout', function () {
				paused = false;
				start();
			} );
		}

		var disconnect = observeSurface( slider, viewport, buildDots );
		document.addEventListener( 'visibilitychange', start );
		buildDots();
		start();

		return function disposeSlider() {
			stop();
			disconnect();
			document.removeEventListener( 'visibilitychange', start );
			slider.removeAttribute( 'data-mw-slider-ready' );
		};
	}

	var shelfControllers = new window.WeakMap();

	function initializeShelf( shelf ) {
		var existing = shelfControllers.get( shelf );
		if ( existing ) {
			existing.sync();
			return null;
		}

		var parts = ensureShelfChrome( shelf );
		if ( ! parts ) {
			return null;
		}

		var viewport = parts.viewport;
		var previous = parts.previous;
		var next = parts.next;
		var status = parts.status;

		function step() {
			var first = viewport.firstElementChild;
			var second = first ? first.nextElementSibling : null;
			return ScrollGeometry.stride( viewport, first, second );
		}

		function sync() {
			var scrollable = ScrollGeometry.maxScroll( viewport ) > 2;
			var labels = getLabels();

			shelf.classList.toggle(
				'mw-release-shelf--scrollable',
				scrollable
			);

			if ( previous ) {
				var prevDisabled =
					! scrollable || ScrollGeometry.isAtStart( viewport );
				var prevHadFocus =
					previous.ownerDocument.activeElement === previous;
				previous.disabled = prevDisabled;
				previous.removeAttribute( 'aria-hidden' );
				previous.removeAttribute( 'tabindex' );
				if ( prevHadFocus && prevDisabled && next && ! next.disabled ) {
					next.focus();
				}
			}

			if ( next ) {
				var nextDisabled =
					! scrollable || ScrollGeometry.isAtEnd( viewport );
				var nextHadFocus = next.ownerDocument.activeElement === next;
				next.disabled = nextDisabled;
				next.removeAttribute( 'aria-hidden' );
				next.removeAttribute( 'tabindex' );
				if (
					nextHadFocus &&
					nextDisabled &&
					previous &&
					! previous.disabled
				) {
					previous.focus();
				}
			}

			if ( status ) {
				var total = viewport.children.length;
				var itemStep = step();
				var visible = Math.max(
					1,
					Math.floor(
						( viewport.clientWidth || itemStep ) / itemStep
					)
				);
				var current = Math.min(
					total,
					Math.max(
						1,
						Math.round(
							ScrollGeometry.logicalScroll( viewport ) / itemStep
						) + visible
					)
				);
				status.textContent = (
					labels.shelfStatus || '%1$d از %2$d نمایش'
				)
					.replace( '%1$d', String( current ) )
					.replace( '%2$d', String( total ) );
			}
		}

		function move( direction ) {
			var distance = ScrollGeometry.pageDistance( viewport, step() );
			ScrollGeometry.scrollTo(
				viewport,
				ScrollGeometry.logicalScroll( viewport ) + direction * distance
			);
			scheduleFrame( sync );
		}

		function onKeydown( event ) {
			var forwardKey = ScrollGeometry.isRtl( viewport )
				? 'ArrowLeft'
				: 'ArrowRight';
			var backwardKey = ScrollGeometry.isRtl( viewport )
				? 'ArrowRight'
				: 'ArrowLeft';
			if ( backwardKey === event.key ) {
				event.preventDefault();
				move( -1 );
			} else if ( forwardKey === event.key ) {
				event.preventDefault();
				move( 1 );
			} else if ( 'Home' === event.key ) {
				event.preventDefault();
				ScrollGeometry.scrollTo( viewport, 0 );
				scheduleFrame( sync );
			} else if ( 'End' === event.key ) {
				event.preventDefault();
				ScrollGeometry.scrollTo(
					viewport,
					ScrollGeometry.maxScroll( viewport )
				);
				scheduleFrame( sync );
			}
		}

		function onScroll() {
			scheduleFrame( sync );
		}

		viewport.addEventListener( 'keydown', onKeydown );
		viewport.addEventListener( 'scroll', onScroll, { passive: true } );
		shelf.addEventListener( 'pointerenter', sync );
		shelf.addEventListener( 'focusin', sync );

		var disconnect = observeSurface( shelf, viewport, sync );
		var api = { move, sync };
		shelfControllers.set( shelf, api );
		shelf.setAttribute( 'data-mw-shelf-ready', '1' );
		sync();

		return function disposeShelf() {
			disconnect();
			viewport.removeEventListener( 'keydown', onKeydown );
			viewport.removeEventListener( 'scroll', onScroll );
			shelf.removeEventListener( 'pointerenter', sync );
			shelf.removeEventListener( 'focusin', sync );
			shelfControllers.delete( shelf );
			shelf.removeAttribute( 'data-mw-shelf-ready' );
		};
	}

	/**
	 * Single delegated click handler guarantees `.mw-release-shelf__nav-button`
	 * buttons work reliably across SSR shelves, soft-navigated routes, and
	 * dynamically rendered shelves without duplicate click listeners.
	 */
	document.addEventListener( 'click', function ( event ) {
		var button =
			event.target && event.target.closest
				? event.target.closest(
						'[data-mw-shelf-previous], [data-mw-shelf-next]'
				  )
				: null;
		if ( ! button || button.disabled ) {
			return;
		}
		var shelf = button.closest( '.mw-release-shelf--scroll' );
		if ( ! shelf ) {
			return;
		}
		event.preventDefault();

		if ( ! shelfControllers.has( shelf ) ) {
			var dispose = initializeShelf( shelf );
			if ( 'function' === typeof dispose ) {
				cleanups.push( { node: shelf, dispose } );
			}
		}

		var ctrl = shelfControllers.get( shelf );
		if ( ctrl ) {
			ctrl.move(
				button.hasAttribute( 'data-mw-shelf-previous' ) ? -1 : 1
			);
		}
	} );

	var activeTabFetches = new WeakMap();

	/**
	 * Smooth in-place switching for `.mw-filter-tabs` (`Today / Week / Genre / Type`)
	 * without full-page reload or interrupting `#mw-global-player`. Falls back to normal
	 * link navigation whenever `fetch` / `DOMParser` is unavailable.
	 */
	document.addEventListener( 'click', function ( event ) {
		if (
			event.defaultPrevented ||
			event.metaKey ||
			event.ctrlKey ||
			event.shiftKey ||
			event.altKey ||
			0 !== event.button
		) {
			return;
		}
		var tab =
			event.target && event.target.closest
				? event.target.closest( 'a.mw-filter-tabs__tab' )
				: null;
		if ( ! tab || ! tab.href ) {
			return;
		}
		var scopeNode = tab.closest( '[data-mw-filter-scope]' );
		if (
			! scopeNode ||
			'function' !== typeof window.fetch ||
			'function' !== typeof window.DOMParser
		) {
			return;
		}

		event.preventDefault();
		var scopeId = scopeNode.getAttribute( 'data-mw-filter-scope' );
		var previousAbort = activeTabFetches.get( scopeNode );
		if ( previousAbort ) {
			previousAbort.abort();
		}
		var controller =
			'function' === typeof window.AbortController
				? new window.AbortController()
				: null;
		if ( controller ) {
			activeTabFetches.set( scopeNode, controller );
		}

		scopeNode
			.querySelectorAll( 'a.mw-filter-tabs__tab' )
			.forEach( function ( link ) {
				var on = link === tab;
				link.classList.toggle( 'is-active', on );
				if ( on ) {
					link.setAttribute( 'aria-current', 'true' );
				} else {
					link.removeAttribute( 'aria-current' );
				}
			} );
		scopeNode.setAttribute( 'aria-busy', 'true' );
		scopeNode.classList.add( 'is-switching-tab' );

		window
			.fetch( tab.href, {
				credentials: 'same-origin',
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
				var replacement = doc.querySelector(
					'[data-mw-filter-scope="' + scopeId + '"]'
				);
				if ( ! replacement ) {
					window.location.href = tab.href;
					return;
				}
				scopeNode.innerHTML = replacement.innerHTML;
				scopeNode.removeAttribute( 'aria-busy' );
				scopeNode.classList.remove( 'is-switching-tab' );
				scopeNode.removeAttribute( 'data-mw-slider-ready' );
				shelfControllers.delete( scopeNode );
				if (
					window.history &&
					'function' === typeof window.history.replaceState
				) {
					window.history.replaceState( null, '', tab.href );
				}
				initializeAll();
			} )
			.catch( function ( err ) {
				scopeNode.removeAttribute( 'aria-busy' );
				scopeNode.classList.remove( 'is-switching-tab' );
				if ( err && 'AbortError' === err.name ) {
					return;
				}
				window.location.href = tab.href;
			} );
	} );

	var cleanups = [];

	function teardownDetached() {
		cleanups = cleanups.filter( function ( entry ) {
			if ( entry.node && document.contains( entry.node ) ) {
				return true;
			}
			entry.dispose();
			return false;
		} );
	}

	function teardownAll() {
		while ( cleanups.length ) {
			cleanups.pop().dispose();
		}
	}

	function initializeAll() {
		teardownDetached();
		document
			.querySelectorAll( '[data-mw-slider]' )
			.forEach( function ( slider ) {
				if ( slider.getAttribute( 'data-mw-slider-ready' ) ) {
					return;
				}
				slider.setAttribute( 'data-mw-slider-ready', '1' );
				var dispose = initializeSlider( slider );
				if ( 'function' === typeof dispose ) {
					cleanups.push( { node: slider, dispose } );
				}
			} );

		document
			.querySelectorAll( '.mw-release-shelf--scroll' )
			.forEach( function ( shelf ) {
				var dispose = initializeShelf( shelf );
				if ( 'function' === typeof dispose ) {
					cleanups.push( { node: shelf, dispose } );
				}
			} );
	}

	window.MusicWaveShelves = {
		sync: initializeAll,
		teardown: teardownAll,
		geometry: ScrollGeometry,
	};

	var sliderController = {
		mount: initializeAll,
		unmount: teardownAll,
	};
	if (
		window.MusicWaveApp &&
		'function' === typeof window.MusicWaveApp.registerController
	) {
		window.MusicWaveApp.registerController( 'slider', sliderController );
	} else {
		window.MusicWaveAppQueue = window.MusicWaveAppQueue || [];
		window.MusicWaveAppQueue.push( {
			id: 'slider',
			definition: sliderController,
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', initializeAll );
	} else {
		initializeAll();
	}
	document.addEventListener( 'mw-page-rendered', initializeAll );
} )();
