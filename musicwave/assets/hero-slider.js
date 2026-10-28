/**
 * Hero slider: crossfading full-bleed slides driven by arrows, dots, and
 * autoplay.
 *
 * Slides are stacked and only the `.is-active` slide is painted, so
 * navigation is a class toggle and the fade itself is pure CSS. Dots are
 * server-rendered (one per slide) and this script only keeps state in sync —
 * viewers without JavaScript still receive the first slide with its controls
 * as a static hero.
 *
 * @package
 */
( function () {
	'use strict';

	function getLabels() {
		return window.musicwaveHeroSlider || {};
	}

	var reducedMotion =
		'function' === typeof window.matchMedia
			? window.matchMedia( '(prefers-reduced-motion: reduce)' )
			: { matches: false };

	function setSlideVisibility( slide, active ) {
		slide.classList.toggle( 'is-active', active );
		if ( active ) {
			slide.removeAttribute( 'aria-hidden' );
			slide.removeAttribute( 'inert' );
			slide.inert = false;
		} else {
			slide.setAttribute( 'aria-hidden', 'true' );
			slide.setAttribute( 'inert', '' );
			slide.inert = true;
		}
	}

	function initialize( slider ) {
		var slides = Array.prototype.slice.call(
			slider.querySelectorAll( '.mw-hero-slide' )
		);

		// A single slide is a static hero: no controls to keep in sync.
		if ( slides.length < 2 ) {
			return;
		}

		var previous = slider.querySelector( '[data-mw-hero-previous]' );
		var next = slider.querySelector( '[data-mw-hero-next]' );
		var dots = slider.querySelector( '[data-mw-hero-dots]' );
		var status = slider.querySelector( '[data-mw-hero-status]' );
		var viewport = slider.querySelector( '.mw-hero-slider__viewport' );

		var autoplay = '1' === slider.getAttribute( 'data-autoplay' );
		var pauseOnHover = '1' === slider.getAttribute( 'data-pause-hover' );
		var interval =
			parseInt( slider.getAttribute( 'data-interval' ), 10 ) || 5000;
		var dotButtons = dots
			? Array.prototype.slice.call(
					dots.querySelectorAll( '[data-mw-hero-dot]' )
			  )
			: [];
		var current = 0;
		var timer = null;
		var paused = false;

		// The markup ships with the first slide active; recover the index so
		// a future change of initial slide keeps the controls in sync.
		for ( var initial = 0; initial < slides.length; initial += 1 ) {
			if ( slides[ initial ].classList.contains( 'is-active' ) ) {
				current = initial;
				break;
			}
		}
		slides.forEach( function ( slide, idx ) {
			setSlideVisibility( slide, idx === current );
		} );

		function statusText() {
			var labels = getLabels();
			return ( labels.status || 'اسلاید %1$d از %2$d' )
				.replace( '%1$d', String( current + 1 ) )
				.replace( '%2$d', String( slides.length ) );
		}

		function goTo( index ) {
			if ( index === current ) {
				return;
			}

			setSlideVisibility( slides[ current ], false );
			if ( dotButtons[ current ] ) {
				dotButtons[ current ].classList.remove( 'is-active' );
				dotButtons[ current ].removeAttribute( 'aria-current' );
			}

			// Wrap-around keeps the hero rotating forever.
			current = ( index + slides.length ) % slides.length;

			setSlideVisibility( slides[ current ], true );
			if ( dotButtons[ current ] ) {
				dotButtons[ current ].classList.add( 'is-active' );
				dotButtons[ current ].setAttribute( 'aria-current', 'true' );
			}
			if ( status ) {
				status.textContent = statusText();
			}
		}

		function move( direction ) {
			goTo( current + direction );
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

		// Any manual navigation restarts the autoplay countdown so the next
		// slide does not land immediately after a user action.
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
		dotButtons.forEach( function ( dot, index ) {
			dot.addEventListener( 'click', function () {
				goTo( index );
				start();
			} );
		} );

		if ( viewport ) {
			viewport.addEventListener( 'keydown', function ( event ) {
				if ( 'ArrowRight' === event.key ) {
					event.preventDefault();
					move( 1 );
					start();
				} else if ( 'ArrowLeft' === event.key ) {
					event.preventDefault();
					move( -1 );
					start();
				}
			} );
		}

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

		document.addEventListener( 'visibilitychange', start );
		start();

		return function dispose() {
			stop();
			document.removeEventListener( 'visibilitychange', start );
			slider.removeAttribute( 'data-mw-hero-slider-ready' );
		};
	}

	var heroDisposers = [];

	function teardownAll() {
		while ( heroDisposers.length ) {
			heroDisposers.pop()();
		}
	}

	function initializeAll() {
		document
			.querySelectorAll( '[data-mw-hero-slider]' )
			.forEach( function ( slider ) {
				if ( slider.getAttribute( 'data-mw-hero-slider-ready' ) ) {
					return;
				}
				slider.setAttribute( 'data-mw-hero-slider-ready', '1' );
				var dispose = initialize( slider );
				if ( 'function' === typeof dispose ) {
					heroDisposers.push( dispose );
				}
			} );
	}

	var heroSliderController = {
		mount: initializeAll,
		unmount: teardownAll,
	};
	if (
		window.MusicWaveApp &&
		'function' === typeof window.MusicWaveApp.registerController
	) {
		window.MusicWaveApp.registerController(
			'hero-slider',
			heroSliderController
		);
	} else {
		window.MusicWaveAppQueue = window.MusicWaveAppQueue || [];
		window.MusicWaveAppQueue.push( {
			id: 'hero-slider',
			definition: heroSliderController,
		} );
	}

	// Swapped-in pages from the persistent player navigation reuse the same
	// initialization path through the `mw-page-rendered` event.
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', initializeAll );
	} else {
		initializeAll();
	}
	document.addEventListener( 'mw-page-rendered', initializeAll );
} )();
