/**
 * Flagship responsive controller for `music-wave/header-search`.
 *
 * Capabilities:
 * - Multi-viewport presentation (`bar` | `compact` | `popup` | `expand` | `hidden`)
 * - Global Command Palette shortcuts (`Ctrl+K` / `⌘K` and `/`)
 * - Scope filter pills (`همه`, `آهنگ و آلبوم`, `هنرمندان`, `پلی‌لیست`)
 * - Recent searches persisted in `localStorage` with single/bulk clear
 * - Curated quick-discovery tags that trigger instant live suggestions
 * - Optional Web Speech API voice dictation (`fa-IR` / `en-US`)
 * - Full ARIA 1.2 modal focus restoration without disturbing `#mw-global-player`
 */
( function () {
	'use strict';

	var POPUP_OPEN_ATTR = 'data-mw-search-open';
	var EXPAND_OPEN_ATTR = 'data-mw-search-expanded';
	var RECENT_STORAGE_KEY = 'mw_recent_searches_v1';
	var MAX_RECENT = 6;
	var activeRecognition = null;

	function getSpeechRecognitionCtor() {
		return (
			window.SpeechRecognition || window.webkitSpeechRecognition || null
		);
	}

	function readRecentSearches() {
		try {
			var raw = window.localStorage.getItem( RECENT_STORAGE_KEY );
			if ( ! raw ) {
				return [];
			}
			var parsed = JSON.parse( raw );
			return Array.isArray( parsed )
				? parsed
						.filter( function ( v ) {
							return 'string' === typeof v && '' !== v.trim();
						} )
						.slice( 0, MAX_RECENT )
				: [];
		} catch ( err ) {
			return [];
		}
	}

	function saveRecentSearches( items ) {
		try {
			window.localStorage.setItem(
				RECENT_STORAGE_KEY,
				JSON.stringify( items.slice( 0, MAX_RECENT ) )
			);
		} catch ( err ) {}
	}

	function pushRecentSearch( query ) {
		var clean = String( query || '' ).trim();
		if ( clean.length < 2 ) {
			return;
		}
		var existing = readRecentSearches().filter( function ( item ) {
			return item.toLowerCase() !== clean.toLowerCase();
		} );
		existing.unshift( clean );
		saveRecentSearches( existing.slice( 0, MAX_RECENT ) );
	}

	function removeRecentSearch( query ) {
		var target = String( query || '' ).toLowerCase();
		var next = readRecentSearches().filter( function ( item ) {
			return item.toLowerCase() !== target;
		} );
		saveRecentSearches( next );
	}

	function getPopup( block ) {
		if ( ! block ) {
			return null;
		}
		if ( block._mwPopup ) {
			return block._mwPopup;
		}
		return block.querySelector( '.mw-header-search-block__popup' );
	}

	function ensurePopupPortaled( block ) {
		var popup = getPopup( block );
		if ( ! popup ) {
			return null;
		}
		block._mwPopup = popup;
		popup._mwOwnerBlock = block;
		if (
			document.body &&
			popup.parentNode !== document.body &&
			! block.closest( '.mw-block-editor-shell, .editor-styles-wrapper' )
		) {
			document.body.appendChild( popup );
		}
		return popup;
	}

	function getToggle( block ) {
		return block
			? block.querySelector( '.mw-header-search-block__toggle' )
			: null;
	}

	function getInner( block ) {
		var popup = getPopup( block );
		return popup
			? popup.querySelector( '.mw-header-search-block__popup-inner' )
			: null;
	}

	function getDialogInput( block ) {
		var popup = getPopup( block );
		if ( popup ) {
			var inside = popup.querySelector( 'input[type="search"]' );
			if ( inside ) {
				return inside;
			}
		}
		return block ? block.querySelector( 'input[type="search"]' ) : null;
	}

	function getInlineInput( block ) {
		var inlineWrap = block
			? block.querySelector( '.mw-header-search-block__inline' )
			: null;
		return inlineWrap
			? inlineWrap.querySelector( 'input[type="search"]' )
			: null;
	}

	function currentViewportMode( block ) {
		if ( ! block ) {
			return 'bar';
		}
		var width = window.innerWidth || 1280;
		if ( width < 768 ) {
			return block.getAttribute( 'data-mobile-mode' ) || 'popup';
		}
		if ( width < 1024 ) {
			return block.getAttribute( 'data-tablet-mode' ) || 'compact';
		}
		return (
			block.getAttribute( 'data-desktop-mode' ) ||
			( block.classList.contains( 'mw-header-search-block--popup' )
				? 'popup'
				: 'bar' )
		);
	}

	function syncClearButton( input ) {
		if ( ! input || ! input.parentElement ) {
			return;
		}
		var clearBtn = input.parentElement.querySelector(
			'[data-mw-search-clear]'
		);
		if ( clearBtn ) {
			clearBtn.hidden = '' === String( input.value || '' ).trim();
		}
	}

	function triggerSuggestInput( input ) {
		if ( ! input ) {
			return;
		}
		syncClearButton( input );
		try {
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} catch ( err ) {
			var fallback = document.createEvent( 'Event' );
			fallback.initEvent( 'input', true, true );
			input.dispatchEvent( fallback );
		}
	}

	function renderRecentChips( block ) {
		if ( ! block || '0' === block.getAttribute( 'data-mw-recent' ) ) {
			return;
		}
		var popup = getPopup( block ) || block;
		var section = popup.querySelector( '[data-mw-search-recent]' );
		var list = popup.querySelector( '[data-mw-search-recent-list]' );
		if ( ! section || ! list ) {
			return;
		}
		var items = readRecentSearches();
		list.textContent = '';
		if ( ! items.length ) {
			section.hidden = true;
			return;
		}
		section.hidden = false;
		items.forEach( function ( term ) {
			var pill = document.createElement( 'span' );
			pill.className = 'mw-header-search-block__recent-item';

			var selectBtn = document.createElement( 'button' );
			selectBtn.type = 'button';
			selectBtn.className = 'mw-header-search-block__recent-btn';
			selectBtn.textContent = term;
			selectBtn.addEventListener( 'click', function () {
				var input = getDialogInput( block );
				if ( input ) {
					input.value = term;
					input.focus();
					triggerSuggestInput( input );
				}
			} );

			var removeBtn = document.createElement( 'button' );
			removeBtn.type = 'button';
			removeBtn.className = 'mw-header-search-block__recent-remove';
			removeBtn.setAttribute( 'aria-label', 'حذف ' + term );
			removeBtn.textContent = '×';
			removeBtn.addEventListener( 'click', function ( ev ) {
				ev.stopPropagation();
				removeRecentSearch( term );
				renderRecentChips( block );
			} );

			pill.appendChild( selectBtn );
			pill.appendChild( removeBtn );
			list.appendChild( pill );
		} );
	}

	function stopVoiceRecognition() {
		if ( activeRecognition ) {
			try {
				activeRecognition.stop();
			} catch ( err ) {}
			activeRecognition = null;
		}
		document
			.querySelectorAll( '[data-mw-voice-search].is-listening' )
			.forEach( function ( btn ) {
				btn.classList.remove( 'is-listening' );
			} );
	}

	function openPopup( block, initialText ) {
		var popup = ensurePopupPortaled( block );
		var toggle = getToggle( block );
		if ( ! popup || ! toggle ) {
			return;
		}
		block.removeAttribute( EXPAND_OPEN_ATTR );
		popup.hidden = false;
		popup.setAttribute( POPUP_OPEN_ATTR, '1' );
		block.setAttribute( POPUP_OPEN_ATTR, '1' );
		toggle.setAttribute( 'aria-expanded', 'true' );
		document.documentElement.setAttribute( POPUP_OPEN_ATTR, '1' );
		if ( document.body ) {
			document.body.style.overflow = 'hidden';
		}
		renderRecentChips( block );

		var input = getDialogInput( block );
		if ( input ) {
			if ( 'string' === typeof initialText && '' !== initialText ) {
				input.value = initialText;
				syncClearButton( input );
			}
			window.setTimeout( function () {
				input.focus();
				try {
					input.select();
				} catch ( err ) {}
			}, 45 );
		}
	}

	function closePopup( block, restoreFocus ) {
		stopVoiceRecognition();
		var popup = getPopup( block );
		if ( ! popup ) {
			return;
		}
		popup.hidden = true;
		popup.removeAttribute( POPUP_OPEN_ATTR );
		if (
			block &&
			popup.parentNode !== block &&
			document.contains( block )
		) {
			block.appendChild( popup );
		}
		var toggle = getToggle( block );
		if ( block ) {
			block.removeAttribute( POPUP_OPEN_ATTR );
			block.removeAttribute( EXPAND_OPEN_ATTR );
		}
		if (
			! document.querySelector(
				'.mw-header-search-block[' + POPUP_OPEN_ATTR + '="1"]'
			)
		) {
			document.documentElement.removeAttribute( POPUP_OPEN_ATTR );
			if ( document.body ) {
				document.body.style.overflow = '';
			}
		}
		if ( toggle ) {
			toggle.setAttribute( 'aria-expanded', 'false' );
			if ( restoreFocus && toggle.offsetParent !== null ) {
				toggle.focus();
			}
		}
	}

	function closeAllPopups() {
		document
			.querySelectorAll(
				'.mw-header-search-block[' +
					POPUP_OPEN_ATTR +
					'="1"], .mw-header-search-block[' +
					EXPAND_OPEN_ATTR +
					'="1"]'
			)
			.forEach( function ( b ) {
				closePopup( b, false );
			} );
	}

	function toggleExpandMode( block ) {
		var toggle = getToggle( block );
		var isExpanded = '1' === block.getAttribute( EXPAND_OPEN_ATTR );
		if ( isExpanded ) {
			block.removeAttribute( EXPAND_OPEN_ATTR );
			if ( toggle ) {
				toggle.setAttribute( 'aria-expanded', 'false' );
			}
			return;
		}
		block.setAttribute( EXPAND_OPEN_ATTR, '1' );
		if ( toggle ) {
			toggle.setAttribute( 'aria-expanded', 'true' );
		}
		var inlineInput = getInlineInput( block );
		if ( inlineInput ) {
			window.setTimeout( function () {
				inlineInput.focus();
			}, 45 );
		}
	}

	function bindVoiceButton( btn ) {
		var SpeechCtor = getSpeechRecognitionCtor();
		if ( ! SpeechCtor ) {
			btn.hidden = true;
			return;
		}
		btn.hidden = false;
		btn.addEventListener( 'click', function ( ev ) {
			ev.preventDefault();
			if ( btn.classList.contains( 'is-listening' ) ) {
				stopVoiceRecognition();
				return;
			}
			stopVoiceRecognition();
			var wrapper = btn.closest( '.wp-block-search__inside-wrapper' );
			var input = wrapper
				? wrapper.querySelector( 'input[type="search"]' )
				: null;
			if ( ! input ) {
				return;
			}
			try {
				var recognition = new SpeechCtor();
				recognition.lang =
					document.documentElement.lang &&
					0 === document.documentElement.lang.indexOf( 'en' )
						? 'en-US'
						: 'fa-IR';
				recognition.interimResults = true;
				recognition.maxAlternatives = 1;
				activeRecognition = recognition;
				btn.classList.add( 'is-listening' );

				recognition.onresult = function ( event ) {
					var transcript = '';
					for (
						var i = event.resultIndex;
						i < event.results.length;
						i++
					) {
						transcript += event.results[ i ][ 0 ].transcript;
					}
					if ( transcript ) {
						input.value = transcript.trim();
						input.focus();
						triggerSuggestInput( input );
					}
				};
				recognition.onerror = function () {
					stopVoiceRecognition();
				};
				recognition.onend = function () {
					btn.classList.remove( 'is-listening' );
					activeRecognition = null;
				};
				recognition.start();
			} catch ( err ) {
				stopVoiceRecognition();
			}
		} );
	}

	function bindBlock( block ) {
		if ( ! block || block.getAttribute( 'data-mw-search-bound' ) ) {
			return;
		}
		block.setAttribute( 'data-mw-search-bound', '1' );

		var toggle = getToggle( block );
		var popup = getPopup( block );
		var inner = getInner( block );
		var closeBtn = block.querySelector( '.mw-header-search-block__close' );
		var backdrop = block.querySelector( '[data-mw-search-backdrop]' );

		if ( toggle && popup ) {
			toggle.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var mode = currentViewportMode( block );
				if ( 'expand' === mode ) {
					toggleExpandMode( block );
					return;
				}
				if ( '1' === block.getAttribute( POPUP_OPEN_ATTR ) ) {
					closePopup( block, true );
				} else {
					closeAllPopups();
					openPopup( block );
				}
			} );
		}

		block
			.querySelectorAll( '[data-mw-search-palette-trigger]' )
			.forEach( function ( triggerBtn ) {
				triggerBtn.addEventListener( 'click', function ( ev ) {
					ev.preventDefault();
					var inlineInput = getInlineInput( block );
					closeAllPopups();
					openPopup( block, inlineInput ? inlineInput.value : '' );
				} );
			} );

		if ( closeBtn ) {
			closeBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				closePopup( block, true );
			} );
		}

		if ( backdrop ) {
			backdrop.addEventListener( 'click', function () {
				closePopup( block, true );
			} );
		}

		if ( popup ) {
			popup.addEventListener( 'click', function ( e ) {
				var clickedLink =
					e.target && e.target.closest
						? e.target.closest( 'a[href]' )
						: null;
				if ( clickedLink ) {
					closePopup( block, false );
					return;
				}
				if ( inner && ! inner.contains( e.target ) ) {
					closePopup( block, true );
				}
			} );
		}

		block
			.querySelectorAll( 'input[type="search"]' )
			.forEach( function ( input ) {
				syncClearButton( input );
				input.addEventListener( 'input', function () {
					syncClearButton( input );
				} );
			} );

		block
			.querySelectorAll( '[data-mw-search-clear]' )
			.forEach( function ( clearBtn ) {
				clearBtn.addEventListener( 'click', function ( ev ) {
					ev.preventDefault();
					var wrap = clearBtn.closest(
						'.wp-block-search__inside-wrapper'
					);
					var input = wrap
						? wrap.querySelector( 'input[type="search"]' )
						: null;
					if ( input ) {
						input.value = '';
						input.focus();
						triggerSuggestInput( input );
					}
				} );
			} );

		var scopesRoot = popup || block;
		scopesRoot
			.querySelectorAll( '[data-mw-search-scope]' )
			.forEach( function ( tab ) {
				tab.addEventListener( 'click', function () {
					var postType =
						tab.getAttribute( 'data-mw-post-type' ) || '';
					scopesRoot
						.querySelectorAll( '[data-mw-search-scope]' )
						.forEach( function ( peer ) {
							var active = peer === tab;
							peer.classList.toggle( 'is-active', active );
							peer.setAttribute(
								'aria-selected',
								active ? 'true' : 'false'
							);
						} );
					[ block, popup ].forEach( function ( container ) {
						if ( ! container ) {
							return;
						}
						container
							.querySelectorAll( '[data-mw-search-post-type]' )
							.forEach( function ( hiddenField ) {
								hiddenField.value = postType;
								hiddenField.disabled = '' === postType;
							} );
					} );
					var dialogInput = getDialogInput( block );
					if ( dialogInput && '' !== dialogInput.value.trim() ) {
						triggerSuggestInput( dialogInput );
					} else if ( dialogInput ) {
						dialogInput.focus();
					}
				} );
			} );

		block
			.querySelectorAll( '[data-mw-search-tag]' )
			.forEach( function ( chip ) {
				chip.addEventListener( 'click', function () {
					var tagValue =
						chip.getAttribute( 'data-mw-search-tag' ) || '';
					var input = getDialogInput( block );
					if ( input && tagValue ) {
						pushRecentSearch( tagValue );
						renderRecentChips( block );
						input.value = tagValue;
						input.focus();
						triggerSuggestInput( input );
					}
				} );
			} );

		var clearRecentBtn = block.querySelector(
			'[data-mw-search-recent-clear]'
		);
		if ( clearRecentBtn ) {
			clearRecentBtn.addEventListener( 'click', function () {
				saveRecentSearches( [] );
				renderRecentChips( block );
			} );
		}

		block.querySelectorAll( 'form' ).forEach( function ( form ) {
			form.addEventListener( 'submit', function ( ev ) {
				var input = form.querySelector( 'input[type="search"]' );
				if ( input && '' === String( input.value || '' ).trim() ) {
					ev.preventDefault();
					input.focus();
					return;
				}
				if ( input && input.value ) {
					pushRecentSearch( input.value );
				}
			} );
		} );

		block
			.querySelectorAll( '[data-mw-voice-search]' )
			.forEach( bindVoiceButton );

		renderRecentChips( block );
	}

	function initAll() {
		document
			.querySelectorAll( 'body > .mw-header-search-block__popup' )
			.forEach( function ( portaled ) {
				if (
					portaled._mwOwnerBlock &&
					! document.contains( portaled._mwOwnerBlock )
				) {
					portaled.remove();
				}
			} );
		document
			.querySelectorAll( '.mw-header-search-block' )
			.forEach( bindBlock );
	}

	function isTypingTarget( target ) {
		if ( ! target || ! target.tagName ) {
			return false;
		}
		var tag = target.tagName.toLowerCase();
		return (
			'input' === tag ||
			'textarea' === tag ||
			'select' === tag ||
			true === target.isContentEditable
		);
	}

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key ) {
			var openBlock = document.querySelector(
				'.mw-header-search-block[' +
					POPUP_OPEN_ATTR +
					'="1"], .mw-header-search-block[' +
					EXPAND_OPEN_ATTR +
					'="1"]'
			);
			if ( openBlock ) {
				e.preventDefault();
				closePopup( openBlock, true );
			}
			return;
		}

		var isCmdK =
			( e.ctrlKey || e.metaKey ) &&
			! e.altKey &&
			'k' === String( e.key || '' ).toLowerCase();
		var isSlash =
			! e.ctrlKey &&
			! e.metaKey &&
			! e.altKey &&
			'/' === e.key &&
			! isTypingTarget( e.target );

		if ( isCmdK || isSlash ) {
			var candidate = document.querySelector(
				'.mw-header-search-block:not([data-mw-shortcut="0"])'
			);
			if ( candidate ) {
				e.preventDefault();
				if ( '1' === candidate.getAttribute( POPUP_OPEN_ATTR ) ) {
					closePopup( candidate, true );
				} else {
					var inlineField = getInlineInput( candidate );
					closeAllPopups();
					openPopup(
						candidate,
						inlineField ? inlineField.value : ''
					);
				}
			}
		}
	} );

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', initAll );
	} else {
		initAll();
	}

	document.addEventListener( 'mw:page-loaded', initAll );
	document.addEventListener( 'mw:navigated', initAll );
} )();
