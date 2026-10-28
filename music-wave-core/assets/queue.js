/**
 * Playback queue progressive enhancement: no-refresh controls + player sync.
 *
 * Architecture (SOLID / DRY / Clean Code):
 * - Single transport layer `sendQueueMutation()` uses REST endpoint
 *   `POST /music-wave/v1/listening/queue/action` first (never blocked by
 *   `/wp-admin/` access rules) and falls back cleanly to `admin-post.php`.
 * - Avoids `form.action` DOM clobbering (`<input name="action">` shadows
 *   `HTMLFormElement.prototype.action`) by reading `form.getAttribute('action')`.
 * - Syncs authoritative server state (`ids`, `shuffle`, `repeat`) both to DOM
 *   and to `window._mwPreviewController.setPreferences()`.
 * - Works seamlessly across full loads AND persistent SPA body swaps.
 */

( function () {
	'use strict';

	if ( window._mwQueueInitialized ) {
		return;
	}
	window._mwQueueInitialized = true;

	function getSettings() {
		return window.musicWaveQueue || {};
	}

	function label( key, fallback ) {
		var labels = getSettings().labels;
		return labels && labels[ key ] ? labels[ key ] : fallback;
	}

	function closest( el, selector ) {
		return el && el.closest ? el.closest( selector ) : null;
	}

	/**
	 * Read the real action URL string from a <form>.
	 * Never read `form.action`: child `<input name="action">` clobbers it into
	 * an HTMLInputElement whose `.toString()` is `"[object HTMLInputElement]"`.
	 */
	function getFormActionUrl( form ) {
		var attr =
			form && form.getAttribute ? form.getAttribute( 'action' ) : '';
		if ( typeof attr === 'string' && attr.trim() !== '' ) {
			return attr.trim();
		}
		var cfg = getSettings();
		return cfg.adminPostUrl || window.ajaxurl || '/wp-admin/admin-post.php';
	}

	function announce( root, message, isError ) {
		if ( ! root ) {
			return;
		}
		var notice = root.querySelector( '.mw-playback-queue__notice' );
		if ( ! notice ) {
			notice = document.createElement( 'p' );
			notice.className = 'mw-playback-queue__notice';
			notice.setAttribute( 'role', 'status' );
			notice.setAttribute( 'aria-live', 'polite' );
			var header = root.querySelector( '.mw-playback-queue__header' );
			if ( header && header.parentNode ) {
				header.parentNode.insertBefore( notice, header.nextSibling );
			} else {
				root.prepend( notice );
			}
		}
		notice.textContent = message || '';
		notice.classList.toggle(
			'mw-playback-queue__notice--error',
			!! isError
		);
		notice.hidden = ! message;
		if ( message ) {
			window.setTimeout( function () {
				if ( notice.textContent === message ) {
					notice.textContent = '';
					notice.hidden = true;
				}
			}, 4000 );
		}
	}

	function syncPlayerPreferences( root ) {
		if ( ! root ) {
			return;
		}
		var controller = window._mwPreviewController;
		if ( ! controller || typeof controller.setPreferences !== 'function' ) {
			return;
		}
		controller.setPreferences( {
			shuffle: root.getAttribute( 'data-mw-queue-shuffle' ) === '1',
			repeat: root.getAttribute( 'data-mw-queue-repeat' ) || 'off',
		} );
	}

	function applyServerQueueSnapshot( root, queue ) {
		if ( ! root || ! queue || typeof queue !== 'object' ) {
			return;
		}
		if ( Array.isArray( queue.ids ) ) {
			root.setAttribute(
				'data-mw-queue-ids',
				JSON.stringify( queue.ids )
			);
		}
		if ( typeof queue.shuffle === 'boolean' ) {
			root.setAttribute(
				'data-mw-queue-shuffle',
				queue.shuffle ? '1' : '0'
			);
		}
		if ( typeof queue.repeat === 'string' ) {
			root.setAttribute( 'data-mw-queue-repeat', queue.repeat );
		}
		syncPlayerPreferences( root );
	}

	function renumberPositions( list ) {
		if ( ! list ) {
			return;
		}
		var items = list.querySelectorAll( '.mw-playback-queue__item' );
		items.forEach( function ( li, idx ) {
			var pos = li.querySelector( '.mw-playback-queue__position' );
			if ( pos ) {
				pos.textContent = String( idx + 1 );
			}
		} );
		var root = closest( list, 'section[data-mw-queue]' );
		var countEl = root
			? root.querySelector( '.mw-playback-queue__count' )
			: null;
		if ( countEl ) {
			countEl.textContent = String( items.length );
		}
	}

	function updateEmptyState( root ) {
		if ( ! root ) {
			return;
		}
		var list = root.querySelector( '.mw-playback-queue__list' );
		if ( ! list ) {
			return;
		}
		var items = list.querySelectorAll( '.mw-playback-queue__item' );
		if ( items.length === 0 ) {
			root.classList.add( 'mw-playback-queue--empty' );
			var empty = root.querySelector( '.mw-playback-queue__empty' );
			if ( ! empty ) {
				empty = document.createElement( 'p' );
				empty.className = 'mw-playback-queue__empty';
				empty.textContent = label( 'empty', 'صف شما خالی است.' );
				list.parentNode.insertBefore( empty, list.nextSibling );
			}
			var controls = root.querySelector( '.mw-playback-queue__controls' );
			if ( controls ) {
				controls.hidden = true;
			}
			var clear = root.querySelector( '.mw-playback-queue__clear' );
			if ( clear ) {
				clear.hidden = true;
			}
		}
	}

	function parseJsonResponse( resp ) {
		return resp.text().then( function ( txt ) {
			var parsed = null;
			if ( txt ) {
				try {
					parsed = JSON.parse( txt );
				} catch ( err ) {
					parsed = null;
				}
			}
			if ( parsed && typeof parsed === 'object' ) {
				parsed._httpOk = resp.ok;
				parsed._httpStatus = resp.status;
				return parsed;
			}
			throw new Error( 'non-json-response:' + resp.status );
		} );
	}

	/**
	 * Single DRY mutation transport:
	 * 1) Primary: REST API `POST /music-wave/v1/listening/queue/action`
	 * 2) Fallback: Form POST to `admin-post.php` via `getFormActionUrl(form)`
	 */
	function sendQueueMutation( form, operation, extra ) {
		var fd = new FormData( form );
		fd.set( 'mw_operation', operation );
		if ( extra && typeof extra === 'object' ) {
			Object.keys( extra ).forEach( function ( k ) {
				if ( extra[ k ] !== undefined && extra[ k ] !== null ) {
					fd.set( k, String( extra[ k ] ) );
				}
			} );
		}

		var cfg = getSettings();
		var restBase = cfg.restUrl
			? String( cfg.restUrl ).replace( /\/$/, '' )
			: '';
		var restNonce = cfg.restNonce || '';

		var sendViaAdminPost = function () {
			return window
				.fetch( getFormActionUrl( form ), {
					method: 'POST',
					body: fd,
					credentials: 'same-origin',
					headers: {
						'X-Requested-With': 'XMLHttpRequest',
						Accept: 'application/json',
					},
				} )
				.then( parseJsonResponse );
		};

		if ( ! restBase || ! restNonce ) {
			return sendViaAdminPost();
		}

		var payload = {
			operation,
			release_id: parseInt( fd.get( 'mw_release_id' ) || '0', 10 ) || 0,
			value: String( fd.get( 'mw_value' ) || '' ),
		};

		return window
			.fetch( restBase + '/music-wave/v1/listening/queue/action', {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					Accept: 'application/json',
					'X-WP-Nonce': restNonce,
				},
				body: JSON.stringify( payload ),
			} )
			.then( parseJsonResponse )
			.catch( function () {
				return sendViaAdminPost();
			} );
	}

	function setRepeatActiveButton( form, activeValue ) {
		form.querySelectorAll( '[data-mw-queue-repeat-option]' ).forEach(
			function ( btn ) {
				var btnVal = btn.getAttribute( 'value' ) || btn.value || '';
				var active = btnVal === activeValue;
				btn.classList.toggle( 'is-active', active );
				btn.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
			}
		);
		var select = form.querySelector( 'select[name="mw_value"]' );
		if ( select ) {
			select.value = activeValue;
		}
	}

	function getRepeatActiveValue( form ) {
		var current = form.querySelector(
			'[data-mw-queue-repeat-option].is-active, [data-mw-queue-repeat-option][aria-pressed="true"]'
		);
		if ( current ) {
			return current.getAttribute( 'value' ) || current.value || 'off';
		}
		var select = form.querySelector( 'select[name="mw_value"]' );
		return select ? select.value : 'off';
	}

	function handleQueueAction( event ) {
		var button = closest( event.target, '[data-mw-queue-action]' );
		if ( ! button ) {
			return;
		}
		var form = closest( button, 'form[data-mw-queue-form]' );
		if ( ! form ) {
			return;
		}

		if ( event && typeof event.preventDefault === 'function' ) {
			event.preventDefault();
			if ( typeof event.stopPropagation === 'function' ) {
				event.stopPropagation();
			}
		}

		if ( button.getAttribute( 'aria-busy' ) === 'true' ) {
			return;
		}
		button.setAttribute( 'aria-busy', 'true' );
		button.classList.add( 'is-busy' );

		var root = closest( form, 'section[data-mw-queue]' );
		var operation =
			button.getAttribute( 'data-mw-queue-action' ) ||
			button.value ||
			( form.querySelector( 'input[name="mw_operation"]' ) &&
				form.querySelector( 'input[name="mw_operation"]' ).value ) ||
			'';
		var extra = {};
		if ( button.name && button.value ) {
			extra[ button.name ] = button.value;
		}

		sendQueueMutation( form, operation, extra )
			.then( function ( data ) {
				if ( ! root ) {
					return;
				}
				var msg = data && data.message ? data.message : '';
				if ( data && data.is_error ) {
					announce(
						root,
						msg ||
							label(
								'error',
								'عملیات انجام نشد. دوباره تلاش کنید.'
							),
						true
					);
					return;
				}

				applyServerQueueSnapshot(
					root,
					data && data.queue ? data.queue : null
				);

				if ( operation === 'remove-item' ) {
					var li = closest( button, '.mw-playback-queue__item' );
					if ( li ) {
						li.style.opacity = '0';
						li.style.transform = 'translateX(-8px)';
						window.setTimeout( function () {
							li.remove();
							updateEmptyState( root );
							renumberPositions(
								root.querySelector( '.mw-playback-queue__list' )
							);
						}, 220 );
					}
					announce(
						root,
						msg || label( 'removed', 'از صف حذف شد.' )
					);
				} else if (
					operation === 'move-up' ||
					operation === 'move-down'
				) {
					var item = closest( button, '.mw-playback-queue__item' );
					var list = root.querySelector( '.mw-playback-queue__list' );
					if ( item && list ) {
						if (
							operation === 'move-up' &&
							item.previousElementSibling
						) {
							list.insertBefore(
								item,
								item.previousElementSibling
							);
						} else if (
							operation === 'move-down' &&
							item.nextElementSibling
						) {
							list.insertBefore( item.nextElementSibling, item );
						}
						renumberPositions( list );
					}
					announce(
						root,
						msg || label( 'moved', 'ترتیب صف به‌روز شد.' )
					);
				} else if ( operation === 'clear' ) {
					var ol = root.querySelector( '.mw-playback-queue__list' );
					if ( ol ) {
						ol.innerHTML = '';
					}
					updateEmptyState( root );
					announce( root, msg || label( 'cleared', 'صف پاک شد.' ) );
				} else if ( operation === 'shuffle' ) {
					var hidden = form.querySelector( 'input[name="mw_value"]' );
					var nowOn =
						data &&
						data.queue &&
						typeof data.queue.shuffle === 'boolean'
							? data.queue.shuffle
							: ( hidden ? hidden.value : '' ) === 'on';
					var nextVal = nowOn ? 'off' : 'on';
					button.setAttribute(
						'aria-pressed',
						nowOn ? 'true' : 'false'
					);
					button.setAttribute( 'data-mw-value', nextVal );
					if ( hidden ) {
						hidden.value = nextVal;
						hidden.setAttribute( 'data-mw-queue-value', nextVal );
					}
					button.textContent = nowOn
						? label( 'shuffleOn', 'تصادفی: روشن' )
						: label( 'shuffleOff', 'پخش تصادفی: خاموش' );
					announce(
						root,
						msg ||
							label(
								'shuffleUpdated',
								'حالت پخش تصادفی به‌روزرسانی شد.'
							)
					);
				} else if ( operation === 'repeat' ) {
					if ( data && data.queue && data.queue.repeat ) {
						setRepeatActiveButton( form, data.queue.repeat );
					}
					announce(
						root,
						msg ||
							label(
								'repeatUpdated',
								'حالت تکرار به‌روزرسانی شد.'
							)
					);
				} else {
					announce( root, msg || 'به‌روزرسانی شد.' );
				}
			} )
			.catch( function () {
				if ( root ) {
					announce(
						root,
						label( 'error', 'عملیات انجام نشد. دوباره تلاش کنید.' ),
						true
					);
				}
			} )
			.finally( function () {
				button.removeAttribute( 'aria-busy' );
				button.classList.remove( 'is-busy' );
			} );
	}

	function handleRepeatOption( event ) {
		var btn = closest( event.target, '[data-mw-queue-repeat-option]' );
		if ( ! btn ) {
			return;
		}
		var form = closest( btn, 'form[data-mw-queue-form]' );
		if ( ! form ) {
			return;
		}

		event.preventDefault();
		event.stopPropagation();

		if ( form.getAttribute( 'aria-busy' ) === 'true' ) {
			return;
		}
		var value = btn.getAttribute( 'value' ) || btn.value || '';
		if ( ! value ) {
			return;
		}

		var previousValue = getRepeatActiveValue( form );
		var root = closest( form, 'section[data-mw-queue]' );

		form.setAttribute( 'aria-busy', 'true' );
		setRepeatActiveButton( form, value );

		sendQueueMutation( form, 'repeat', { mw_value: value } )
			.then( function ( data ) {
				if ( ! root ) {
					return;
				}
				var msg = data && data.message ? data.message : '';
				if ( data && data.is_error ) {
					setRepeatActiveButton( form, previousValue );
					announce(
						root,
						msg ||
							label(
								'error',
								'عملیات انجام نشد. دوباره تلاش کنید.'
							),
						true
					);
					return;
				}
				applyServerQueueSnapshot(
					root,
					data && data.queue ? data.queue : { repeat: value }
				);
				setRepeatActiveButton(
					form,
					data && data.queue && data.queue.repeat
						? data.queue.repeat
						: value
				);
				announce(
					root,
					msg ||
						label( 'repeatUpdated', 'حالت تکرار به‌روزرسانی شد.' )
				);
			} )
			.catch( function () {
				setRepeatActiveButton( form, previousValue );
				if ( root ) {
					announce(
						root,
						label( 'error', 'عملیات انجام نشد. دوباره تلاش کنید.' ),
						true
					);
				}
			} )
			.finally( function () {
				form.removeAttribute( 'aria-busy' );
			} );
	}

	function handlePlayAll( event ) {
		var button = closest( event.target, '[data-mw-queue-play-all]' );
		if ( ! button ) {
			return;
		}
		if ( event && typeof event.preventDefault === 'function' ) {
			event.preventDefault();
			if ( typeof event.stopPropagation === 'function' ) {
				event.stopPropagation();
			}
		}
		var root = closest( button, 'section[data-mw-queue]' );
		if ( ! root ) {
			return;
		}
		var idsAttr = root.getAttribute( 'data-mw-queue-ids' ) || '[]';
		var ids = [];
		try {
			ids = JSON.parse( idsAttr );
		} catch ( e ) {
			ids = [];
		}
		if ( ! ids.length ) {
			announce( root, label( 'empty', 'صف شما خالی است.' ), true );
			return;
		}
		syncPlayerPreferences( root );
		var controller = window._mwPreviewController;
		if ( controller && typeof controller.playCustomQueue === 'function' ) {
			try {
				controller.playCustomQueue( button, ids, {
					shuffle:
						root.getAttribute( 'data-mw-queue-shuffle' ) === '1',
					repeat:
						root.getAttribute( 'data-mw-queue-repeat' ) || 'off',
				} );
				return;
			} catch ( err ) {}
		}
		if ( controller && typeof controller.playRelease === 'function' ) {
			controller.playRelease( button, parseInt( ids[ 0 ], 10 ) || 0 );
		} else {
			var firstLink = root.querySelector( '.mw-playback-queue__item a' );
			if ( firstLink && firstLink.href ) {
				if ( 'function' === typeof window._mwNavigate ) {
					window._mwNavigate( firstLink.href );
				} else {
					window.location.href = firstLink.href;
				}
			}
		}
	}

	function initQueueSurfaces() {
		document.documentElement.classList.add( 'js' );
		document
			.querySelectorAll( '.mw-queue-repeat-form' )
			.forEach( function ( form ) {
				form.classList.add( 'js-enhanced' );
			} );
		document
			.querySelectorAll( 'section[data-mw-queue]' )
			.forEach( syncPlayerPreferences );
	}

	var queueController = {
		mount: initQueueSurfaces,
	};
	if (
		window.MusicWaveApp &&
		'function' === typeof window.MusicWaveApp.registerController
	) {
		window.MusicWaveApp.registerController( 'queue', queueController );
	} else {
		window.MusicWaveAppQueue = window.MusicWaveAppQueue || [];
		window.MusicWaveAppQueue.push( {
			id: 'queue',
			definition: queueController,
		} );
	}

	initQueueSurfaces();
	document.addEventListener( 'mw-page-rendered', initQueueSurfaces );

	document.addEventListener(
		'click',
		function ( e ) {
			if ( closest( e.target, '[data-mw-queue-repeat-option]' ) ) {
				handleRepeatOption( e );
				return;
			}
			if ( closest( e.target, '[data-mw-queue-play-all]' ) ) {
				handlePlayAll( e );
				return;
			}
			if ( closest( e.target, '[data-mw-queue-action]' ) ) {
				if ( closest( e.target, 'form[data-mw-queue-form]' ) ) {
					handleQueueAction( e );
				}
			}
		},
		true
	);

	document.addEventListener(
		'submit',
		function ( e ) {
			var form = e.target;
			if ( ! form || ! form.matches ) {
				return;
			}
			if ( ! form.matches( 'form[data-mw-queue-form]' ) ) {
				return;
			}
			e.preventDefault();
			e.stopPropagation();
			var submitBtn =
				form.querySelector(
					'button[type="submit"][data-mw-queue-action]:focus'
				) ||
				form.querySelector(
					'button[type="submit"][data-mw-queue-action]'
				);
			if ( submitBtn ) {
				handleQueueAction( {
					target: submitBtn,
					preventDefault() {},
					stopPropagation() {},
					type: 'submit',
				} );
				return;
			}
			var root = closest( form, 'section[data-mw-queue]' );
			var opInput = form.querySelector( 'input[name="mw_operation"]' );
			sendQueueMutation( form, opInput ? opInput.value : '' )
				.then( function ( data ) {
					if ( ! root ) {
						return;
					}
					if ( data && data.is_error ) {
						announce(
							root,
							( data && data.message ) ||
								label(
									'error',
									'عملیات انجام نشد. دوباره تلاش کنید.'
								),
							true
						);
						return;
					}
					applyServerQueueSnapshot(
						root,
						data && data.queue ? data.queue : null
					);
					announce(
						root,
						( data && data.message ) || 'به‌روزرسانی شد.',
						false
					);
				} )
				.catch( function () {
					if ( root ) {
						announce(
							root,
							label(
								'error',
								'عملیات انجام نشد. دوباره تلاش کنید.'
							),
							true
						);
					}
				} );
		},
		true
	);
} )();
