/**
 * Continue-listening interactive controller.
 *
 * Progressive enhancement for `music-wave/continue-listening`:
 * 1. One-click consent opt-in (`[data-mw-listening-consent]`) saves consent via
 *    REST, flushes active/local playback progress to the server, and hydrates
 *    the live shelf rail in place without reloading the page.
 * 2. Per-item removal (`[data-mw-listening-remove]`) and full history clear
 *    (`[data-mw-listening-clear]`) update both local storage and server DB.
 * 3. Guest sessions (`mw-continue-listening--guest`) progressively render local
 *    browser playback items above the compact sign-in banner so visitors can
 *    immediately resume where they stopped across pages.
 * 4. Re-entrant across persistent background navigations (`mw-page-rendered`)
 *    and real-time player activity (`mw-listening-updated`).
 *
 * @package
 */
( function () {
	'use strict';

	if ( window._mwListeningInitialized ) {
		return;
	}
	window._mwListeningInitialized = true;

	var refreshTimer = null;

	function getSettings() {
		return window.musicWaveListening || {};
	}

	function getLabels() {
		var cfg = getSettings();
		return cfg && cfg.labels ? cfg.labels : {};
	}

	function label( key, fallback ) {
		var map = getLabels();
		return typeof map[ key ] === 'string' && map[ key ] !== ''
			? map[ key ]
			: fallback;
	}

	function isLoggedIn() {
		var cfg = getSettings();
		if ( cfg && typeof cfg.loggedIn === 'boolean' ) {
			return cfg.loggedIn;
		}
		return Boolean(
			window.musicWavePreviewPlayer &&
				window.musicWavePreviewPlayer.loggedIn
		);
	}

	function restHeaders() {
		var cfg = getSettings();
		var nonce =
			( cfg && cfg.restNonce ) ||
			( window.musicWavePreviewPlayer &&
				window.musicWavePreviewPlayer.restNonce ) ||
			'';
		return nonce ? { 'X-WP-Nonce': nonce } : {};
	}

	function formatClock( seconds ) {
		var total = Math.max( 0, Math.floor( seconds || 0 ) );
		var hours = Math.floor( total / 3600 );
		var minutes = Math.floor( ( total % 3600 ) / 60 );
		var remain = total % 60;
		if ( hours > 0 ) {
			return (
				String( hours ) +
				':' +
				String( minutes ).padStart( 2, '0' ) +
				':' +
				String( remain ).padStart( 2, '0' )
			);
		}
		return String( minutes ) + ':' + String( remain ).padStart( 2, '0' );
	}

	function replaceToken( template, value ) {
		return String( template || '%s' ).replace( '%s', String( value ) );
	}

	function sectionConfig( section ) {
		return {
			source:
				section.getAttribute( 'data-mw-listening-source' ) ||
				'continue',
			layout:
				section.getAttribute( 'data-mw-listening-layout' ) || 'scroll',
			columns:
				parseInt(
					section.getAttribute( 'data-mw-listening-columns' ),
					10
				) || 4,
			limit:
				parseInt(
					section.getAttribute( 'data-mw-listening-limit' ),
					10
				) || 8,
			shape:
				section.getAttribute( 'data-mw-listening-shape' ) || 'square',
			heading: section.getAttribute( 'data-mw-listening-heading' ) || '',
			intro: section.getAttribute( 'data-mw-listening-intro' ) || '',
			emptyMessage:
				section.getAttribute( 'data-mw-listening-empty' ) || '',
			consentMessage:
				section.getAttribute( 'data-mw-optin-message' ) ||
				label(
					'consentMessage',
					'سابقهٔ گوش‌دادن را روشن کنید تا انتشارهای اخیراً پخش‌شده‌تان را اینجا ببینید. شما در کنترل باقی می‌مانید - سابقهٔ خصوصی است و در هر زمان می‌توان آن را پاک کرد.'
				),
			consentLabel:
				section.getAttribute( 'data-mw-optin-label' ) ||
				label( 'consentLabel', 'تاریخچه گوش‌دادن را روشن کنید' ),
			moreUrl: section.getAttribute( 'data-mw-listening-more-url' ) || '',
			moreLabel:
				section.getAttribute( 'data-mw-listening-more-label' ) || '',
			showHeading:
				section.getAttribute( 'data-mw-listening-show-heading' ) !==
				'0',
			showArtwork:
				section.getAttribute( 'data-mw-listening-show-artwork' ) !==
				'0',
			showArtist:
				section.getAttribute( 'data-mw-listening-show-artist' ) !== '0',
			showWhen:
				section.getAttribute( 'data-mw-listening-show-when' ) !== '0',
			showPreview:
				section.getAttribute( 'data-mw-listening-show-preview' ) !==
				'0',
		};
	}

	function setStatus( section, message ) {
		var status = section
			? section.querySelector( '[data-mw-listening-status]' )
			: null;
		if ( ! status ) {
			return;
		}
		status.textContent = message || '';
		status.hidden = ! message;
	}

	function createDisableButton() {
		var disableBtn = document.createElement( 'button' );
		disableBtn.type = 'button';
		disableBtn.className = 'mw-continue-listening__disable';
		disableBtn.setAttribute( 'data-mw-listening-disable', '' );
		disableBtn.textContent = label( 'disable', 'غیرفعال‌سازی سابقه' );
		return disableBtn;
	}

	function createHeaderNode( cfg, showClear, showDisable ) {
		if ( ! cfg.showHeading ) {
			return null;
		}
		var header = document.createElement( 'header' );
		header.className = 'mw-continue-listening__header';

		var headingWrap = document.createElement( 'div' );
		var h2 = document.createElement( 'h2' );
		h2.textContent =
			cfg.heading ||
			( cfg.source === 'played'
				? 'اخیراً پخش‌شده'
				: 'به گوش‌دادن ادامه دهید' );
		headingWrap.appendChild( h2 );

		if ( cfg.intro ) {
			var intro = document.createElement( 'p' );
			intro.className = 'mw-continue-listening__intro';
			intro.textContent = cfg.intro;
			headingWrap.appendChild( intro );
		}
		header.appendChild( headingWrap );

		var actions = document.createElement( 'div' );
		actions.className = 'mw-continue-listening__actions';
		var hasAction = false;

		if ( showClear ) {
			var clearBtn = document.createElement( 'button' );
			clearBtn.type = 'button';
			clearBtn.className = 'mw-continue-listening__clear';
			clearBtn.setAttribute( 'data-mw-listening-clear', '' );
			clearBtn.textContent = label( 'clear', 'پاک‌سازی سابقه' );
			actions.appendChild( clearBtn );
			hasAction = true;
		}

		if ( showDisable ) {
			actions.appendChild( createDisableButton() );
			hasAction = true;
		}

		if ( cfg.moreUrl ) {
			var more = document.createElement( 'a' );
			more.className = 'mw-continue-listening__more';
			more.href = cfg.moreUrl;
			more.textContent = cfg.moreLabel || 'همه را ببینید';
			var arrow = document.createElement( 'span' );
			arrow.setAttribute( 'aria-hidden', 'true' );
			arrow.textContent = '→';
			more.appendChild( arrow );
			actions.appendChild( more );
			hasAction = true;
		}

		if ( hasAction ) {
			header.appendChild( actions );
		}
		return header;
	}

	function buildLocalCard( item, cfg ) {
		var releaseId = parseInt( item.releaseId, 10 ) || 0;
		var titleText = item.title || label( 'untitled', 'انتشار بدون عنوان' );
		var linkHref = item.link || '#';
		var article = document.createElement( 'article' );
		article.className =
			'mw-release-shelf__item mw-continue-listening__item';

		if ( cfg.showArtwork ) {
			var artWrap = document.createElement( 'div' );
			artWrap.className = 'mw-release-shelf__artwrap';

			var artLink = document.createElement( 'a' );
			artLink.className =
				'mw-release-shelf__art mw-release-shelf__art--' + cfg.shape;
			artLink.href = linkHref;
			artLink.setAttribute(
				'aria-label',
				replaceToken( label( 'openTitle', 'باز کردن %s' ), titleText )
			);

			if ( item.image ) {
				var img = document.createElement( 'img' );
				img.className = 'mw-release-shelf__image';
				img.src = item.image;
				img.alt = '';
				img.loading = 'lazy';
				img.decoding = 'async';
				artLink.appendChild( img );
			} else {
				var ph = document.createElement( 'span' );
				ph.className = 'mw-release-shelf__placeholder';
				ph.setAttribute( 'aria-hidden', 'true' );
				ph.textContent = titleText.charAt( 0 ) || '♪';
				artLink.appendChild( ph );
			}
			artWrap.appendChild( artLink );

			if ( cfg.showPreview && ( releaseId > 0 || item.previewUrl ) ) {
				var playBtn = document.createElement( 'button' );
				playBtn.type = 'button';
				playBtn.setAttribute( 'aria-pressed', 'false' );
				playBtn.setAttribute(
					'aria-label',
					replaceToken( label( 'playTitle', 'پخش %s' ), titleText )
				);
				if ( releaseId > 0 ) {
					playBtn.className = 'mw-release-shelf__play mw-card-play';
					playBtn.setAttribute(
						'data-mw-release-id',
						String( releaseId )
					);
					if ( item.position > 0 && cfg.source !== 'played' ) {
						playBtn.setAttribute(
							'data-mw-resume-position',
							String( Math.floor( item.position ) )
						);
					}
					var playIcon = document.createElement( 'span' );
					playIcon.className = 'mw-card-play__icon';
					playIcon.setAttribute( 'aria-hidden', 'true' );
					playIcon.textContent = '▶';
					playBtn.appendChild( playIcon );
				} else {
					playBtn.className =
						'mw-release-shelf__play mw-preview-button mw-preview-button--compact';
					playBtn.setAttribute( 'data-preview-url', item.previewUrl );
					playBtn.setAttribute( 'data-preview-title', titleText );
					playBtn.setAttribute(
						'data-preview-artist',
						item.artist || ''
					);
					playBtn.setAttribute(
						'data-preview-image',
						item.image || ''
					);
					playBtn.setAttribute( 'data-preview-link', linkHref );
					var pIcon = document.createElement( 'span' );
					pIcon.className = 'mw-preview-button__icon';
					pIcon.setAttribute( 'aria-hidden', 'true' );
					pIcon.textContent = '▶';
					playBtn.appendChild( pIcon );
				}
				artWrap.appendChild( playBtn );
			}

			if ( releaseId > 0 ) {
				var dismiss = document.createElement( 'button' );
				dismiss.type = 'button';
				dismiss.className = 'mw-continue-listening__dismiss';
				dismiss.setAttribute(
					'data-mw-listening-remove',
					String( releaseId )
				);
				dismiss.setAttribute(
					'aria-label',
					label( 'removeItem', 'حذف از فهرست ادامهٔ پخش' )
				);
				dismiss.textContent = '×';
				artWrap.appendChild( dismiss );
			}

			article.appendChild( artWrap );
		}

		var body = document.createElement( 'div' );
		body.className = 'mw-release-shelf__body';

		var h3 = document.createElement( 'h3' );
		h3.setAttribute( 'dir', 'auto' );
		var titleAnchor = document.createElement( 'a' );
		titleAnchor.href = linkHref;
		titleAnchor.textContent = titleText;
		h3.appendChild( titleAnchor );
		body.appendChild( h3 );

		if ( cfg.showArtist && item.artist ) {
			var artistSpan = document.createElement( 'span' );
			artistSpan.className = 'mw-release-shelf__artist';
			artistSpan.setAttribute( 'dir', 'auto' );
			artistSpan.textContent = item.artist;
			body.appendChild( artistSpan );
		}

		if ( cfg.showWhen && item.updatedAt ) {
			var timeEl = document.createElement( 'time' );
			timeEl.dateTime = new Date( item.updatedAt * 1000 ).toISOString();
			timeEl.textContent = label( 'justNow', 'همین الان' );
			body.appendChild( timeEl );
		}

		if ( item.position > 0 && cfg.source !== 'played' ) {
			var resumeWrap = document.createElement( 'div' );
			resumeWrap.className = 'mw-continue-listening__resume';
			resumeWrap.setAttribute(
				'data-mw-resume-seconds',
				String( Math.floor( item.position ) )
			);

			var bar = document.createElement( 'span' );
			bar.className = 'mw-continue-listening__bar';
			bar.setAttribute( 'aria-hidden', 'true' );
			var fill = document.createElement( 'i' );
			fill.setAttribute( 'data-mw-progress-fill', '' );
			if ( item.duration > 0 ) {
				var pct = Math.min(
					98,
					Math.max(
						6,
						Math.round( ( item.position / item.duration ) * 100 )
					)
				);
				fill.style.setProperty( '--mw-item-progress', pct + '%' );
			}
			bar.appendChild( fill );
			resumeWrap.appendChild( bar );

			var stamp = document.createElement( 'span' );
			stamp.className = 'mw-continue-listening__stamp';
			stamp.textContent = replaceToken(
				label( 'resumeFrom', 'ادامه از %s' ),
				formatClock( item.position )
			);
			resumeWrap.appendChild( stamp );
			body.appendChild( resumeWrap );
		}

		article.appendChild( body );
		return article;
	}

	function syncProgressBars( scope ) {
		var ctrl = window._mwPreviewController;
		if ( ! ctrl || typeof ctrl.getLocalHistory !== 'function' ) {
			return;
		}
		var localMap = {};
		ctrl.getLocalHistory( 'continue', 24 ).forEach( function ( entry ) {
			if ( entry && entry.releaseId && entry.duration > 0 ) {
				localMap[ entry.releaseId ] = entry;
			}
		} );
		( scope || document )
			.querySelectorAll( '.mw-continue-listening__item' )
			.forEach( function ( card ) {
				var play = card.querySelector( '[data-mw-release-id]' );
				var rid = play
					? parseInt( play.getAttribute( 'data-mw-release-id' ), 10 )
					: 0;
				var fill = card.querySelector( '[data-mw-progress-fill]' );
				var resumeNode = card.querySelector(
					'[data-mw-resume-seconds]'
				);
				if ( ! rid || ! fill || ! resumeNode ) {
					return;
				}
				var info = localMap[ rid ];
				if ( info && info.duration > 0 ) {
					var pos =
						parseInt(
							resumeNode.getAttribute( 'data-mw-resume-seconds' ),
							10
						) ||
						info.position ||
						0;
					var pct = Math.min(
						98,
						Math.max(
							6,
							Math.round( ( pos / info.duration ) * 100 )
						)
					);
					fill.style.setProperty( '--mw-item-progress', pct + '%' );
				}
			} );
	}

	function shelfClassName( cfg ) {
		var cls = 'mw-release-shelf mw-release-shelf--' + cfg.layout;
		if ( cfg.layout === 'grid' ) {
			cls += ' mw-release-shelf--columns-' + cfg.columns;
		}
		return cls;
	}

	function renderConsentSection( section, cfg ) {
		section.classList.remove( 'mw-continue-listening--empty' );
		section.classList.add( 'mw-continue-listening--consent' );
		section.replaceChildren();

		var panel = document.createElement( 'div' );
		panel.className = 'mw-continue-listening__panel';

		var icon = document.createElement( 'span' );
		icon.className = 'mw-continue-listening__panel-icon';
		icon.setAttribute( 'aria-hidden', 'true' );
		icon.textContent = '◎';
		panel.appendChild( icon );

		var text = document.createElement( 'p' );
		text.className = 'mw-continue-listening__panel-text';
		text.textContent =
			cfg.consentMessage ||
			'سابقهٔ گوش‌دادن را روشن کنید تا انتشارهای اخیراً پخش‌شده‌تان را اینجا ببینید. شما در کنترل باقی می‌مانید - سابقهٔ خصوصی است و در هر زمان می‌توان آن را پاک کرد.';
		panel.appendChild( text );

		var cta = document.createElement( 'button' );
		cta.type = 'button';
		cta.className = 'wp-element-button mw-continue-listening__panel-cta';
		cta.setAttribute( 'data-mw-listening-consent', '' );
		cta.textContent = cfg.consentLabel || 'تاریخچه گوش‌دادن را روشن کنید';
		panel.appendChild( cta );

		var status = document.createElement( 'p' );
		status.className = 'mw-continue-listening__status';
		status.setAttribute( 'role', 'status' );
		status.setAttribute( 'aria-live', 'polite' );
		status.setAttribute( 'data-mw-listening-status', '' );
		status.hidden = true;
		panel.appendChild( status );

		section.appendChild( panel );
	}

	function renderEmptySection( section, cfg ) {
		section.classList.remove( 'mw-continue-listening--consent' );
		section.classList.add( 'mw-continue-listening--empty' );
		section.replaceChildren();

		var row = document.createElement( 'div' );
		row.className = 'mw-continue-listening__empty-row';

		var p = document.createElement( 'p' );
		p.className = 'mw-continue-listening__empty';
		p.textContent =
			cfg.emptyMessage ||
			label(
				'enabled',
				'سابقهٔ گوش‌دادن روشن است. برای پرکردن این ریل، چیزی پخش کنید.'
			);
		row.appendChild( p );

		if ( isLoggedIn() ) {
			row.appendChild( createDisableButton() );
		}
		section.appendChild( row );

		var status = document.createElement( 'p' );
		status.className = 'mw-continue-listening__status';
		status.setAttribute( 'role', 'status' );
		status.setAttribute( 'aria-live', 'polite' );
		status.setAttribute( 'data-mw-listening-status', '' );
		status.hidden = true;
		section.appendChild( status );
	}

	function renderServerSection( section, cfg, cardsHtml ) {
		if ( ! cardsHtml || ! String( cardsHtml ).trim() ) {
			renderEmptySection( section, cfg );
			return;
		}
		section.classList.remove(
			'mw-continue-listening--consent',
			'mw-continue-listening--empty'
		);
		section.replaceChildren();
		var header = createHeaderNode( cfg, true, true );
		if ( header ) {
			section.appendChild( header );
		}
		var shelf = document.createElement( 'div' );
		shelf.className = shelfClassName( cfg );
		var itemsWrap = document.createElement( 'div' );
		itemsWrap.className = 'mw-release-shelf__items';
		itemsWrap.setAttribute( 'data-mw-listening-items', '' );
		itemsWrap.innerHTML = cardsHtml;
		shelf.appendChild( itemsWrap );
		section.appendChild( shelf );
		syncProgressBars( section );
		if ( window._mwPreviewController && window._mwPreviewController.sync ) {
			window._mwPreviewController.sync();
		}
		if ( window.MusicWaveShelves && window.MusicWaveShelves.sync ) {
			window.MusicWaveShelves.sync();
		}
	}

	function renderGuestLocalRail( section ) {
		var slot = section.querySelector( '[data-mw-listening-guest-rail]' );
		var ctrl = window._mwPreviewController;
		if ( ! slot || ! ctrl || typeof ctrl.getLocalHistory !== 'function' ) {
			return;
		}
		var cfg = sectionConfig( section );
		var items = ctrl.getLocalHistory( cfg.source, cfg.limit );
		if ( ! items.length ) {
			slot.hidden = true;
			slot.replaceChildren();
			return;
		}
		slot.replaceChildren();
		var header = createHeaderNode( cfg, true );
		if ( header ) {
			slot.appendChild( header );
		}
		var shelf = document.createElement( 'div' );
		shelf.className = shelfClassName( cfg );
		var list = document.createElement( 'div' );
		list.className = 'mw-release-shelf__items';
		list.setAttribute( 'data-mw-listening-items', '' );
		items.forEach( function ( item ) {
			list.appendChild( buildLocalCard( item, cfg ) );
		} );
		shelf.appendChild( list );
		slot.appendChild( shelf );
		slot.hidden = false;
		if ( ctrl.sync ) {
			ctrl.sync();
		}
		if ( window.MusicWaveShelves && window.MusicWaveShelves.sync ) {
			window.MusicWaveShelves.sync();
		}
	}

	function fetchServerSection( section ) {
		if ( ! window.wp || ! window.wp.apiFetch ) {
			return Promise.resolve();
		}
		var cfg = sectionConfig( section );
		var query =
			'?source=' +
			encodeURIComponent( cfg.source ) +
			'&per_page=' +
			encodeURIComponent( String( cfg.limit ) ) +
			'&shape=' +
			encodeURIComponent( cfg.shape ) +
			'&show_artwork=' +
			( cfg.showArtwork ? '1' : '0' ) +
			'&show_artist=' +
			( cfg.showArtist ? '1' : '0' ) +
			'&show_when=' +
			( cfg.showWhen ? '1' : '0' ) +
			'&show_preview=' +
			( cfg.showPreview ? '1' : '0' );

		return window.wp
			.apiFetch( {
				path: '/music-wave/v1/listening/continue' + query,
				method: 'GET',
				headers: restHeaders(),
			} )
			.then( function ( response ) {
				if ( ! response || response.consent !== true ) {
					return;
				}
				renderServerSection( section, cfg, response.cards_html || '' );
			} )
			.catch( function () {} );
	}

	function refreshAllSections() {
		var sections = document.querySelectorAll( '.mw-continue-listening' );
		if ( ! sections.length ) {
			return;
		}
		sections.forEach( function ( section ) {
			if (
				section.classList.contains( 'mw-continue-listening--guest' )
			) {
				renderGuestLocalRail( section );
				return;
			}
			if (
				section.classList.contains( 'mw-continue-listening--consent' )
			) {
				return;
			}
			if ( isLoggedIn() ) {
				fetchServerSection( section );
			} else {
				syncProgressBars( section );
			}
		} );
	}

	function scheduleRefresh() {
		if ( refreshTimer ) {
			window.clearTimeout( refreshTimer );
		}
		refreshTimer = window.setTimeout( function () {
			refreshTimer = null;
			refreshAllSections();
		}, 350 );
	}

	document.addEventListener( 'click', function ( event ) {
		var consentBtn = event.target.closest( '[data-mw-listening-consent]' );
		if ( consentBtn && ! consentBtn.disabled ) {
			event.preventDefault();
			if ( ! window.wp || ! window.wp.apiFetch ) {
				return;
			}
			var section = consentBtn.closest( '.mw-continue-listening' );
			consentBtn.disabled = true;
			consentBtn.setAttribute( 'aria-busy', 'true' );
			setStatus( section, label( 'saving', '' ) );

			window.wp
				.apiFetch( {
					path: '/music-wave/v1/listening/consent',
					method: 'POST',
					data: { consent: true },
					headers: restHeaders(),
				} )
				.then( function ( response ) {
					if ( ! response || response.consent !== true ) {
						throw new Error( 'consent not granted' );
					}
					if ( getSettings() ) {
						getSettings().hasConsent = true;
					}
					var flushDone =
						window._mwPreviewController &&
						typeof window._mwPreviewController
							.setListeningConsent === 'function'
							? window._mwPreviewController.setListeningConsent(
									true
							  )
							: Promise.resolve();
					consentBtn.hidden = true;
					consentBtn.removeAttribute( 'aria-busy' );
					if ( section ) {
						return Promise.resolve( flushDone ).then( function () {
							return fetchServerSection( section );
						} );
					}
					return undefined;
				} )
				.catch( function () {
					consentBtn.disabled = false;
					consentBtn.removeAttribute( 'aria-busy' );
					setStatus(
						section,
						label(
							'error',
							'سابقهٔ گوش‌دادن فعال نشد. دوباره امتحان کنید.'
						)
					);
				} );
			return;
		}

		var disableBtn = event.target.closest( '[data-mw-listening-disable]' );
		if ( disableBtn && ! disableBtn.disabled ) {
			event.preventDefault();
			if ( ! window.wp || ! window.wp.apiFetch ) {
				return;
			}
			var disableSection = disableBtn.closest( '.mw-continue-listening' );
			disableBtn.disabled = true;
			disableBtn.setAttribute( 'aria-busy', 'true' );
			setStatus( disableSection, label( 'disabling', '' ) );

			window.wp
				.apiFetch( {
					path: '/music-wave/v1/listening/consent',
					method: 'POST',
					data: { consent: false },
					headers: restHeaders(),
				} )
				.then( function ( response ) {
					if ( ! response || response.consent !== false ) {
						throw new Error( 'consent not revoked' );
					}
					if ( getSettings() ) {
						getSettings().hasConsent = false;
					}
					if ( window._mwPreviewController ) {
						if (
							typeof window._mwPreviewController
								.setListeningConsent === 'function'
						) {
							window._mwPreviewController.setListeningConsent(
								false
							);
						}
						if (
							typeof window._mwPreviewController
								.clearLocalHistory === 'function'
						) {
							window._mwPreviewController.clearLocalHistory();
						}
					}
					document
						.querySelectorAll( '.mw-continue-listening' )
						.forEach( function ( target ) {
							if (
								target.classList.contains(
									'mw-continue-listening--guest'
								)
							) {
								return;
							}
							renderConsentSection(
								target,
								sectionConfig( target )
							);
						} );
				} )
				.catch( function () {
					disableBtn.disabled = false;
					disableBtn.removeAttribute( 'aria-busy' );
					setStatus(
						disableSection,
						label(
							'disableError',
							'غیرفعال کردن سابقهٔ گوش‌دادن انجام نشد. دوباره امتحان کنید.'
						)
					);
				} );
			return;
		}

		var removeBtn = event.target.closest( '[data-mw-listening-remove]' );
		if ( removeBtn && ! removeBtn.disabled ) {
			event.preventDefault();
			event.stopPropagation();
			var releaseId =
				parseInt(
					removeBtn.getAttribute( 'data-mw-listening-remove' ),
					10
				) || 0;
			var card = removeBtn.closest( '.mw-continue-listening__item' );
			var ownerSection = removeBtn.closest( '.mw-continue-listening' );
			var cfg = ownerSection ? sectionConfig( ownerSection ) : null;
			removeBtn.disabled = true;
			if ( card ) {
				card.classList.add( 'is-removing' );
			}
			if (
				window._mwPreviewController &&
				typeof window._mwPreviewController.removeLocalHistory ===
					'function'
			) {
				window._mwPreviewController.removeLocalHistory( releaseId );
			}
			var finalizeRemoval = function () {
				if ( card && card.parentNode ) {
					card.parentNode.removeChild( card );
				}
				if ( ! ownerSection ) {
					return;
				}
				if (
					ownerSection.classList.contains(
						'mw-continue-listening--guest'
					)
				) {
					renderGuestLocalRail( ownerSection );
					return;
				}
				var remaining = ownerSection.querySelectorAll(
					'.mw-continue-listening__item'
				);
				if ( ! remaining.length && cfg ) {
					renderEmptySection( ownerSection, cfg );
				}
			};
			if (
				isLoggedIn() &&
				releaseId > 0 &&
				window.wp &&
				window.wp.apiFetch
			) {
				window.wp
					.apiFetch( {
						path:
							'/music-wave/v1/listening/items/' +
							releaseId +
							'?source=' +
							encodeURIComponent( cfg ? cfg.source : '' ),
						method: 'DELETE',
						headers: restHeaders(),
					} )
					.catch( function () {} )
					.finally( finalizeRemoval );
			} else {
				window.setTimeout( finalizeRemoval, 160 );
			}
			return;
		}

		var clearBtn = event.target.closest( '[data-mw-listening-clear]' );
		if ( clearBtn && ! clearBtn.disabled ) {
			event.preventDefault();
			var targetSection = clearBtn.closest( '.mw-continue-listening' );
			if ( ! targetSection ) {
				return;
			}
			var sectionCfg = sectionConfig( targetSection );
			clearBtn.disabled = true;
			if (
				window._mwPreviewController &&
				typeof window._mwPreviewController.clearLocalHistory ===
					'function'
			) {
				window._mwPreviewController.clearLocalHistory(
					sectionCfg.source
				);
			}
			if (
				targetSection.classList.contains(
					'mw-continue-listening--guest'
				)
			) {
				renderGuestLocalRail( targetSection );
				return;
			}
			if ( isLoggedIn() && window.wp && window.wp.apiFetch ) {
				window.wp
					.apiFetch( {
						path:
							'/music-wave/v1/listening/history?source=' +
							encodeURIComponent( sectionCfg.source ),
						method: 'DELETE',
						headers: restHeaders(),
					} )
					.catch( function () {} )
					.finally( function () {
						renderEmptySection( targetSection, sectionCfg );
					} );
			} else {
				renderEmptySection( targetSection, sectionCfg );
			}
		}
	} );

	function boot() {
		document
			.querySelectorAll( '.mw-continue-listening' )
			.forEach( function ( section ) {
				if (
					section.classList.contains( 'mw-continue-listening--guest' )
				) {
					renderGuestLocalRail( section );
				} else {
					syncProgressBars( section );
				}
			} );
	}

	document.addEventListener( 'mw-page-rendered', refreshAllSections );
	document.addEventListener( 'mw-listening-updated', function ( event ) {
		var detail = ( event && event.detail ) || {};
		if ( detail.source === 'server' ) {
			scheduleRefresh();
			return;
		}
		document
			.querySelectorAll( '.mw-continue-listening--guest' )
			.forEach( renderGuestLocalRail );
		syncProgressBars( document );
	} );

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
