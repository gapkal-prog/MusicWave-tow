( function () {
	'use strict';

	var cfg = window.musicWaveVipAdmin || {};

	function syncDurationInputs( scope ) {
		if ( ! scope ) {
			return;
		}
		var preset = scope.querySelector( '[data-mw-vip-preset]' );
		if ( ! preset ) {
			return;
		}
		var mode = preset.value;
		var daysWrap = scope.querySelector( '[data-mw-vip-custom-days]' );
		var dateWrap = scope.querySelector( '[data-mw-vip-custom-date]' );
		if ( daysWrap ) {
			daysWrap.hidden = mode !== 'custom_days';
		}
		if ( dateWrap ) {
			dateWrap.hidden = mode !== 'exact_date';
		}
	}

	document
		.querySelectorAll( '[data-mw-vip-form-scope]' )
		.forEach( syncDurationInputs );

	document.addEventListener( 'change', function ( event ) {
		var target = event.target;
		if ( ! target || ! target.matches ) {
			return;
		}
		if ( target.matches( '[data-mw-vip-preset]' ) ) {
			syncDurationInputs( target.closest( '[data-mw-vip-form-scope]' ) );
		}
		if ( target.matches( 'select[name="mw_vip_level_filter"]' ) ) {
			var otherName =
				target.id === 'mw-vip-level-top'
					? 'mw-vip-level-bottom'
					: 'mw-vip-level-top';
			var twin = document.getElementById( otherName );
			if ( twin ) {
				twin.value = target.value;
			}
		}
	} );

	document.addEventListener( 'click', function ( event ) {
		var quickBtn =
			event.target && event.target.closest
				? event.target.closest( '[data-mw-vip-ajax-btn]' )
				: null;
		if ( ! quickBtn || ! cfg.ajaxUrl ) {
			return;
		}

		var container = quickBtn.closest( '[data-mw-vip-form-scope]' );
		var cell = quickBtn.closest( '[data-mw-vip-user-cell]' );
		if ( ! container || ! cell ) {
			return;
		}

		var op = quickBtn.getAttribute( 'data-mw-vip-ajax-btn' ) || 'grant';
		if (
			op === 'revoke_all' &&
			'true' !== quickBtn.getAttribute( 'data-mw-confirmed' )
		) {
			event.preventDefault();
			quickBtn.setAttribute( 'data-mw-confirmed', 'true' );
			quickBtn.setAttribute( 'data-mw-orig', quickBtn.textContent || '' );
			quickBtn.textContent = cfg.confirmRevoke || 'تأیید لغو؟';
			window.setTimeout( function () {
				if ( quickBtn.isConnected ) {
					quickBtn.removeAttribute( 'data-mw-confirmed' );
					var prev = quickBtn.getAttribute( 'data-mw-orig' );
					if ( prev ) {
						quickBtn.textContent = prev;
					}
				}
			}, 4000 );
			return;
		}

		event.preventDefault();

		var statusEl = container.querySelector( '[data-mw-vip-status]' );
		var payload = new window.FormData();
		payload.append( 'action', 'mw_vip_quick_manage' );
		payload.append( '_ajax_nonce', cfg.nonce || '' );
		payload.append(
			'user_id',
			container.getAttribute( 'data-user-id' ) || '0'
		);
		payload.append( 'vip_op', op );

		var levelEl = container.querySelector( '[name="vip_level"]' );
		var presetEl = container.querySelector( '[name="vip_preset"]' );
		var daysEl = container.querySelector( '[name="vip_days"]' );
		var dateEl = container.querySelector( '[name="vip_exact_date"]' );
		var modeEl = container.querySelector( '[name="vip_apply_mode"]' );
		var noteEl = container.querySelector( '[name="vip_note"]' );

		if ( levelEl ) {
			payload.append( 'vip_level', levelEl.value );
		}
		if ( presetEl ) {
			payload.append( 'vip_preset', presetEl.value );
		}
		if ( daysEl ) {
			payload.append( 'vip_days', daysEl.value );
		}
		if ( dateEl ) {
			payload.append( 'vip_exact_date', dateEl.value );
		}
		if ( modeEl ) {
			payload.append( 'vip_apply_mode', modeEl.value );
		}
		if ( noteEl ) {
			payload.append( 'vip_note', noteEl.value );
		}

		quickBtn.disabled = true;
		if ( statusEl ) {
			statusEl.classList.remove( 'is-error' );
			statusEl.textContent = cfg.savingText || '...';
		}

		window
			.fetch( cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: payload,
			} )
			.then( function ( res ) {
				return res.json();
			} )
			.then( function ( body ) {
				if (
					! body ||
					! body.success ||
					! body.data ||
					typeof body.data.cell_html !== 'string'
				) {
					var msg =
						( body && body.data && body.data.message ) ||
						cfg.errorText ||
						'Error';
					throw new Error( msg );
				}
				var temp = document.createElement( 'div' );
				temp.innerHTML = body.data.cell_html;
				var nextCell = temp.firstElementChild;
				if ( nextCell ) {
					nextCell.classList.add( 'is-updated' );
					cell.replaceWith( nextCell );
					nextCell
						.querySelectorAll( '[data-mw-vip-form-scope]' )
						.forEach( syncDurationInputs );
				}
			} )
			.catch( function ( err ) {
				quickBtn.disabled = false;
				if ( statusEl ) {
					statusEl.classList.add( 'is-error' );
					statusEl.textContent =
						( err && err.message ) || cfg.errorText || 'Error';
				}
			} );
	} );
} )();
