/**
 * Requests admin screen: two-step confirmations, select-all, canned replies.
 *
 * Progressive: every action is a plain form post; this only adds guards.
 * Destructive buttons use the same arm-then-confirm pattern as the playlist
 * manager (no window.confirm), so keyboard and screen-reader users get an
 * explicit, labelled second step.
 *
 * @package
 */
( function () {
	'use strict';

	var labels = window.musicWaveRequestsAdmin || {};
	var ARM_TIMEOUT = 4000;

	function armButton( button, armedLabel ) {
		if ( 'true' === button.getAttribute( 'data-mw-armed' ) ) {
			return true;
		}
		button.setAttribute( 'data-mw-armed', 'true' );
		button.setAttribute( 'data-mw-label', button.textContent );
		button.textContent = armedLabel;
		button.classList.add( 'mw-requests__armed' );
		window.setTimeout( function () {
			if ( 'true' !== button.getAttribute( 'data-mw-armed' ) ) {
				return;
			}
			button.removeAttribute( 'data-mw-armed' );
			button.textContent = button.getAttribute( 'data-mw-label' ) || '';
			button.removeAttribute( 'data-mw-label' );
			button.classList.remove( 'mw-requests__armed' );
		}, ARM_TIMEOUT );

		return false;
	}

	function confirmDelete( event ) {
		var button = event.currentTarget;
		if ( ! armButton( button, labels.confirmDelete || '…' ) ) {
			event.preventDefault();
		}
	}

	function confirmBulk( event ) {
		var form = event.target;
		var action = form.querySelector( '[name="mw_bulk_action"]' );
		var checked = form.querySelectorAll(
			'[name="mw_request_ids[]"]:checked'
		);
		if ( ! action || ! action.value || ! checked.length ) {
			event.preventDefault();
			return;
		}
		var button = form.querySelector( 'button[type="submit"]' );
		var destructive =
			action.value === 'delete' ||
			action.value.indexOf( 'status:' ) === 0;
		if (
			destructive &&
			button &&
			! armButton( button, labels.confirmBulk || '…' )
		) {
			event.preventDefault();
		}
	}

	function selectAll( event ) {
		var boxes = document.querySelectorAll( '[name="mw_request_ids[]"]' );
		Array.prototype.forEach.call( boxes, function ( box ) {
			box.checked = event.target.checked;
		} );
	}

	function insertTemplate( event ) {
		var button = event.target.closest( '[data-mw-template]' );
		if ( ! button ) {
			return;
		}
		var wrapper = button.closest( 'form' );
		var textarea = wrapper
			? wrapper.querySelector( 'textarea[name="mw_body"]' )
			: null;
		if ( ! textarea ) {
			return;
		}
		var template = button.getAttribute( 'data-mw-template' ) || '';
		textarea.value = textarea.value.trim()
			? textarea.value.replace( /\s+$/, '' ) + '\n\n' + template
			: template;
		textarea.focus();
		textarea.setSelectionRange(
			textarea.value.length,
			textarea.value.length
		);
	}

	function init() {
		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-mw-confirm="delete"]' ),
			function ( button ) {
				button.addEventListener( 'click', confirmDelete );
			}
		);
		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-mw-bulk]' ),
			function ( form ) {
				form.addEventListener( 'submit', confirmBulk );
			}
		);
		var all = document.querySelector( '[data-mw-select-all]' );
		if ( all ) {
			all.addEventListener( 'change', selectAll );
		}
		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-mw-templates]' ),
			function ( bar ) {
				bar.addEventListener( 'click', insertTemplate );
			}
		);
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
