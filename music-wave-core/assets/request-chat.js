/**
 * Request conversation drawer & async reply controller.
 *
 * Keeps request reply threads collapsed initially and expands them cleanly
 * underneath each request card on toggle click, submitting new user replies
 * through `/music-wave/v1/requests/{id}/chat` with graceful `admin-post.php` fallback.
 */
( function () {
	'use strict';

	if ( window._mwRequestChatInitialized ) {
		return;
	}
	window._mwRequestChatInitialized = true;

	function getConfig() {
		return window.musicWaveRequestChat || {};
	}

	function label( key, fallback ) {
		const labels = getConfig().labels || {};
		return typeof labels[ key ] === 'string' && labels[ key ] !== ''
			? labels[ key ]
			: fallback;
	}

	function escapeHtml( text ) {
		const div = document.createElement( 'div' );
		div.textContent =
			typeof text === 'undefined' || text === null ? '' : String( text );
		return div.innerHTML;
	}

	function renderMessage( entry ) {
		const isUser = entry && entry.by === 'user';
		const author = isUser
			? label( 'you', 'شما' )
			: label( 'support', 'پشتیبانی' );
		const timeHtml =
			entry && entry.time_label
				? '<time>' + escapeHtml( entry.time_label ) + '</time>'
				: '';
		const bodyHtml = escapeHtml(
			entry && entry.body ? entry.body : ''
		).replace( /\r?\n/g, '<br>' );

		return (
			'<div class="mw-request-chat__message mw-request-chat__message--' +
			( isUser ? 'user' : 'admin' ) +
			'">' +
			'<div class="mw-request-chat__message-meta"><strong>' +
			escapeHtml( author ) +
			'</strong>' +
			timeHtml +
			'</div>' +
			'<div class="mw-request-chat__message-body">' +
			bodyHtml +
			'</div>' +
			'</div>'
		);
	}

	function resolveDrawer( toggle ) {
		const targetId = toggle.getAttribute( 'aria-controls' );
		if ( targetId ) {
			const byId = document.getElementById( targetId );
			if ( byId ) {
				return byId;
			}
		}
		const card = toggle.closest(
			'[data-mw-request-card], [data-mw-request-thread]'
		);
		return card
			? card.querySelector( '.mw-request-chat__thread-wrap' )
			: null;
	}

	function updateToggleState( card, count ) {
		const toggle = card.querySelector( '.mw-request-chat__toggle' );
		if ( ! toggle || count < 1 ) {
			return;
		}
		const textEl = toggle.querySelector( '.mw-request-chat__toggle-label' );
		if ( textEl ) {
			textEl.textContent = label( 'continue', 'پاسخ و ادامه گفتگو' );
		}
		let badge = toggle.querySelector( '[data-mw-chat-count]' );
		if ( ! badge ) {
			badge = document.createElement( 'span' );
			badge.className = 'mw-request-chat__count';
			badge.setAttribute( 'data-mw-chat-count', '' );
			const chevron = toggle.querySelector( '.mw-request-chat__chevron' );
			if ( chevron ) {
				toggle.insertBefore( badge, chevron );
			} else {
				toggle.appendChild( badge );
			}
		}
		badge.textContent = String( count );
	}

	function updateCard( card, payload ) {
		if ( ! card || ! payload ) {
			return;
		}
		const list = card.querySelector(
			'[data-mw-chat-list], [data-mw-chat-thread]'
		);
		if ( list && Array.isArray( payload.thread ) ) {
			list.innerHTML = payload.thread.length
				? payload.thread.map( renderMessage ).join( '' )
				: '<p class="mw-request-chat__empty" data-mw-chat-empty>' +
				  escapeHtml(
						label(
							'emptyThread',
							'هنوز پیامی در این گفتگو ثبت نشده است.'
						)
				  ) +
				  '</p>';
			list.scrollTop = list.scrollHeight;
			updateToggleState( card, payload.thread.length );
		}
		const statusLabelEl = card.querySelector(
			'[data-mw-request-status-label], .mw-dashboard-requests__status-label'
		);
		if ( statusLabelEl && payload.status_label ) {
			statusLabelEl.textContent = payload.status_label;
		}
	}

	function setFeedback( form, message, isError ) {
		const feedback = form.querySelector( '[data-mw-chat-feedback]' );
		if ( ! feedback ) {
			return;
		}
		feedback.textContent = message || '';
		feedback.classList.toggle( 'is-error', Boolean( isError ) );
	}

	function postReply( requestId, message, form ) {
		const cfg = getConfig();
		const restBase = cfg.restBase || '';
		const restNonce = cfg.restNonce || cfg.nonce || '';
		if ( restBase && restNonce ) {
			const endpoint =
				String( restBase ).replace( /\/+$/, '' ) +
				'/requests/' +
				encodeURIComponent( String( requestId ) ) +
				'/chat';
			return window
				.fetch( endpoint, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': restNonce,
						Accept: 'application/json',
					},
					body: JSON.stringify( { message } ),
				} )
				.then( function ( response ) {
					return response
						.json()
						.catch( function () {
							return null;
						} )
						.then( function ( body ) {
							return { ok: response.ok, body };
						} );
				} );
		}

		// Use getAttribute('action') because <input name="action"> shadows form.action.
		const actionUrl =
			form.getAttribute( 'action' ) || cfg.adminPostUrl || '';
		if ( ! actionUrl ) {
			return Promise.reject( new Error( 'missing-action' ) );
		}
		const data = new window.FormData( form );
		return window
			.fetch( actionUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					Accept: 'application/json',
					'X-Requested-With': 'XMLHttpRequest',
				},
				body: data,
			} )
			.then( function ( response ) {
				return response
					.json()
					.catch( function () {
						return null;
					} )
					.then( function ( body ) {
						return { ok: response.ok, body };
					} );
			} );
	}

	document.addEventListener( 'click', function ( event ) {
		const toggle =
			event.target && event.target.closest
				? event.target.closest( '.mw-request-chat__toggle' )
				: null;
		if ( ! toggle ) {
			return;
		}
		const drawer = resolveDrawer( toggle );
		if ( ! drawer ) {
			return;
		}
		event.preventDefault();
		const nextOpen = drawer.hidden;
		drawer.hidden = ! nextOpen;
		toggle.setAttribute( 'aria-expanded', nextOpen ? 'true' : 'false' );
		if ( nextOpen ) {
			const list = drawer.querySelector(
				'[data-mw-chat-list], [data-mw-chat-thread]'
			);
			if ( list ) {
				list.scrollTop = list.scrollHeight;
			}
		}
	} );

	document.addEventListener( 'submit', function ( event ) {
		const form = event.target;
		if (
			! form ||
			! form.matches ||
			! form.matches( '[data-mw-chat-form], [data-mw-request-chat-form]' )
		) {
			return;
		}
		if ( typeof window.fetch !== 'function' ) {
			return;
		}

		event.preventDefault();

		const requestId =
			form.getAttribute( 'data-mw-chat-form' ) ||
			form.getAttribute( 'data-mw-request-id' );
		const textarea = form.querySelector(
			'textarea[name="mw_chat_message"]'
		);
		const raw = textarea ? textarea.value.trim() : '';

		if ( ! raw ) {
			setFeedback(
				form,
				label( 'empty', 'لطفاً متن پیام را وارد کنید.' ),
				true
			);
			if ( textarea ) {
				textarea.focus();
			}
			return;
		}

		const submitBtn = form.querySelector( 'button[type="submit"]' );
		if ( submitBtn ) {
			submitBtn.disabled = true;
		}
		setFeedback( form, label( 'sending', 'در حال ارسال…' ), false );

		postReply( requestId, raw, form )
			.then( function ( result ) {
				const payload = result && result.body ? result.body : null;
				if ( ! result.ok || ! payload || payload.is_error ) {
					const errText =
						payload && payload.message
							? payload.message
							: label(
									'error',
									'عملیات انجام نشد. دوباره تلاش کنید.'
							  );
					setFeedback( form, errText, true );
					return;
				}
				const card = form.closest(
					'[data-mw-request-card], [data-mw-request-thread]'
				);
				updateCard( card, payload );
				if ( textarea ) {
					textarea.value = '';
				}
				setFeedback(
					form,
					payload.message || label( 'sent', 'پیام شما ارسال شد.' ),
					false
				);
			} )
			.catch( function () {
				setFeedback(
					form,
					label( 'error', 'عملیات انجام نشد. دوباره تلاش کنید.' ),
					true
				);
			} )
			.finally( function () {
				if ( submitBtn ) {
					submitBtn.disabled = false;
				}
			} );
	} );
} )();
