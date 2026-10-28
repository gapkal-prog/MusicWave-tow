/**
 * Request form enhancement: live character counter and error focus.
 *
 * The form is fully functional without this script; it only improves
 * feedback. Safe to run more than once (soft navigation re-dispatches
 * `mw-page-rendered`), every wrapper is marked when enhanced.
 *
 * @package
 */
( function () {
	'use strict';

	if ( window._mwRequestFormInitialized ) {
		return;
	}
	window._mwRequestFormInitialized = true;

	function getLabels() {
		return window.musicWaveRequestForm || {};
	}

	function format( template, values ) {
		return String( template ).replace(
			/%(\d)\$s/g,
			function ( match, index ) {
				var value = values[ parseInt( index, 10 ) - 1 ];
				return value === undefined ? match : String( value );
			}
		);
	}

	function localizeDigits( value ) {
		try {
			return Number( value ).toLocaleString(
				document.documentElement.lang || undefined
			);
		} catch ( error ) {
			return String( value );
		}
	}

	function enhanceCounter( form ) {
		var field = form.querySelector( '[data-mw-counter]' );
		var output = form.querySelector( '[data-mw-counter-output]' );
		if ( ! field || ! output ) {
			return;
		}
		var min = parseInt( field.getAttribute( 'minlength' ), 10 ) || 0;
		var max = parseInt( field.getAttribute( 'maxlength' ), 10 ) || 0;

		function update() {
			var length = field.value.length;
			if ( length === 0 ) {
				output.textContent = '';
				output.classList.remove( 'is-short' );
				return;
			}
			var labels = getLabels();
			if ( min > 0 && length < min ) {
				output.textContent = labels.minimum || '';
				output.classList.add( 'is-short' );
				return;
			}
			output.classList.remove( 'is-short' );
			output.textContent = max
				? format( labels.counter || '%1$s / %2$s', [
						localizeDigits( length ),
						localizeDigits( max ),
				  ] )
				: localizeDigits( length );
		}

		field.addEventListener( 'input', update );
		update();
	}

	function focusFirstError( form ) {
		var invalid = form.querySelector( '[aria-invalid="true"]' );
		if ( invalid && typeof invalid.focus === 'function' ) {
			try {
				invalid.focus( { preventScroll: false } );
			} catch ( error ) {
				invalid.focus();
			}
		}
	}

	function init() {
		var forms = document.querySelectorAll( '[data-mw-request-form]' );
		Array.prototype.forEach.call( forms, function ( form ) {
			if ( form.dataset.mwRequestReady === '1' ) {
				return;
			}
			form.dataset.mwRequestReady = '1';
			enhanceCounter( form );
			if ( window.location.hash === '#mw-request-form' ) {
				focusFirstError( form );
			}
		} );
	}

	var requestFormController = { mount: init };
	if (
		window.MusicWaveApp &&
		'function' === typeof window.MusicWaveApp.registerController
	) {
		window.MusicWaveApp.registerController(
			'request-form',
			requestFormController
		);
	} else {
		window.MusicWaveAppQueue = window.MusicWaveAppQueue || [];
		window.MusicWaveAppQueue.push( {
			id: 'request-form',
			definition: requestFormController,
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
	document.addEventListener( 'mw-page-rendered', init );
} )();
