/**
 * MusicWave VIP Settings Admin UI Controller (CSP-clean, zero inline scripts, zero browser alerts).
 *
 * Synchronized 1:1 with `ManaCore\MusicWave\Vip\SettingsPage`:
 * - `.mwvip-tabs button[data-tab]` <-> `.mwvip-panel[data-panel]` (`.active` + ARIA + RTL arrows + URL/session persistence)
 * - `#mwvip-delivery-provider` & `#mwvip-sendfile-mode` <-> `[data-mwvip-section]` collapse, `[data-mwvip-mode-hint]` and `tr.mwvip-xaccel-row`
 * - `#mwvip-upload-enabled` & `#mwvip-upload-mode` <-> `.mwvip-upload-group` / `.mwvip-upload-creds[data-modes]`
 * - `#mwvip-module-enabled` <-> `.mwvip-module-control`, `[data-mwvip-controls-unlocked]` & `#mwvip-membership-note`
 * - `#mwvip-provision-btn`, `#mwvip-vault-upload-btn`, `#mwvip-vault-search`, `[data-mwvip-copy]`, `[data-mwvip-delete-asset]`
 * - `[data-mwvip-generate]` & `[data-mwvip-reveal]` <-> the secret input named in their `data-*` attribute
 */
( function () {
	'use strict';

	const STORAGE_KEY = 'mwvip_active_tab';
	const cfg = window.mwVipSettings || {};
	const i18n = cfg.i18n || {};

	function getTabSlug( tab ) {
		if ( ! tab ) {
			return '';
		}
		return (
			tab.getAttribute( 'data-tab' ) ||
			tab.getAttribute( 'data-mwvip-tab' ) ||
			''
		);
	}

	function initTabs() {
		const tabs = Array.prototype.slice.call(
			document.querySelectorAll(
				'.mwvip-tabs [data-tab], .mwvip-tabs [role="tab"]'
			)
		);
		const panels = Array.prototype.slice.call(
			document.querySelectorAll( '.mwvip-panel[data-panel]' )
		);
		if ( ! tabs.length || ! panels.length ) {
			return;
		}

		function findTab( slug ) {
			if ( ! slug ) {
				return null;
			}
			return (
				tabs.find( function ( btn ) {
					return getTabSlug( btn ) === slug;
				} ) || null
			);
		}

		function activateTab( tab, moveFocus, persist ) {
			const slug = getTabSlug( tab );
			if ( ! tab || ! slug ) {
				return;
			}

			tabs.forEach( function ( btn ) {
				const isCurrent = btn === tab;
				btn.classList.toggle( 'active', isCurrent );
				btn.classList.toggle( 'is-active', isCurrent );
				btn.setAttribute(
					'aria-selected',
					isCurrent ? 'true' : 'false'
				);
				btn.setAttribute( 'tabindex', isCurrent ? '0' : '-1' );
			} );

			panels.forEach( function ( panel ) {
				const matches = panel.getAttribute( 'data-panel' ) === slug;
				panel.classList.toggle( 'active', matches );
				panel.classList.toggle( 'is-active', matches );
				panel.hidden = ! matches;
			} );

			if ( moveFocus ) {
				tab.focus();
			}

			if ( persist ) {
				try {
					window.sessionStorage.setItem( STORAGE_KEY, slug );
				} catch ( err ) {}
				if (
					window.history &&
					typeof window.history.replaceState === 'function'
				) {
					try {
						const nextUrl = new window.URL( window.location.href );
						nextUrl.searchParams.set( 'tab', slug );
						window.history.replaceState(
							null,
							'',
							nextUrl.toString()
						);
					} catch ( err ) {}
				}
			}
		}

		let initialTab = null;
		try {
			const params = new window.URL( window.location.href ).searchParams;
			const requested =
				params.get( 'tab' ) || params.get( 'mwvip_tab' ) || '';
			if ( requested ) {
				initialTab = findTab( requested );
			} else if ( params.get( 'mwvip_action' ) ) {
				initialTab = findTab( 'membership' );
			}
		} catch ( err ) {}

		if ( ! initialTab && window.location.hash ) {
			const hashClean = window.location.hash
				.replace( /^#/, '' )
				.replace( /^mwvip-(?:panel|tab)-/, '' );
			initialTab = findTab( hashClean );
		}

		if ( ! initialTab ) {
			try {
				const saved = window.sessionStorage.getItem( STORAGE_KEY );
				if ( saved ) {
					initialTab = findTab( saved );
				}
			} catch ( err ) {}
		}

		if ( ! initialTab ) {
			initialTab =
				tabs.find( function ( btn ) {
					return btn.classList.contains( 'active' );
				} ) || tabs[ 0 ];
		}

		activateTab( initialTab, false, false );

		tabs.forEach( function ( tab, idx ) {
			tab.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				activateTab( tab, false, true );
			} );

			tab.addEventListener( 'keydown', function ( e ) {
				let nextIdx = -1;
				const isRtl =
					document.documentElement.dir === 'rtl' ||
					( document.body &&
						document.body.classList.contains( 'rtl' ) );
				if ( e.key === 'ArrowRight' ) {
					nextIdx = isRtl
						? ( idx - 1 + tabs.length ) % tabs.length
						: ( idx + 1 ) % tabs.length;
				} else if ( e.key === 'ArrowLeft' ) {
					nextIdx = isRtl
						? ( idx + 1 ) % tabs.length
						: ( idx - 1 + tabs.length ) % tabs.length;
				} else if ( e.key === 'Home' ) {
					nextIdx = 0;
				} else if ( e.key === 'End' ) {
					nextIdx = tabs.length - 1;
				}
				if ( nextIdx >= 0 ) {
					e.preventDefault();
					activateTab( tabs[ nextIdx ], true, true );
				}
			} );
		} );
	}

	function renderDiagnostics( diag ) {
		if ( ! diag || typeof diag !== 'object' ) {
			return;
		}
		const pill = document.querySelector( '[data-mwvip-overall-pill]' );
		if ( pill ) {
			pill.classList.toggle( 'is-ok', Boolean( diag.overall_ready ) );
			pill.classList.toggle( 'is-warn', ! diag.overall_ready );
		}
		if ( diag.local ) {
			const pathEl = document.querySelector(
				'[data-mwvip-resolved-path]'
			);
			if ( pathEl && diag.local.resolved_path ) {
				pathEl.textContent = diag.local.resolved_path;
			}
			const countEl = document.querySelector(
				'[data-mwvip-inventory-count]'
			);
			if (
				countEl &&
				typeof diag.local.inventory_count !== 'undefined'
			) {
				countEl.textContent = String( diag.local.inventory_count );
			}
			if ( diag.local.checks ) {
				Object.keys( diag.local.checks ).forEach( function ( key ) {
					const item = document.querySelector(
						'[data-check-key="' + key + '"]'
					);
					if ( ! item ) {
						return;
					}
					const ok = Boolean( diag.local.checks[ key ] );
					item.classList.toggle( 'is-ok', ok );
					item.classList.toggle( 'is-fail', ! ok );
					const dot = item.querySelector( '.mwvip-check-item__dot' );
					if ( dot ) {
						dot.textContent = ok ? '✓' : '✕';
					}
				} );
			}
		}
	}

	function formatSize( bytes ) {
		const n = parseInt( bytes, 10 ) || 0;
		if ( n >= 1048576 ) {
			return ( n / 1048576 ).toFixed( 1 ) + ' MB';
		}
		if ( n >= 1024 ) {
			return Math.round( n / 1024 ) + ' KB';
		}
		return n + ' B';
	}

	function renderVaultItems( items ) {
		const tbody = document.getElementById( 'mwvip-vault-tbody' );
		if ( ! tbody ) {
			return;
		}
		tbody.innerHTML = '';
		if ( ! Array.isArray( items ) || ! items.length ) {
			const emptyRow = document.createElement( 'tr' );
			emptyRow.setAttribute( 'data-empty-row', '1' );
			const td = document.createElement( 'td' );
			td.colSpan = 5;
			td.textContent = i18n.emptyVault || 'No protected assets found.';
			emptyRow.appendChild( td );
			tbody.appendChild( emptyRow );
			return;
		}

		items.forEach( function ( asset ) {
			const assetId = String( asset.id || '' );
			const rawKey =
				assetId.indexOf( 'vip:' ) === 0 ? assetId.slice( 4 ) : '';
			const tr = document.createElement( 'tr' );
			tr.setAttribute( 'data-asset-row', rawKey );

			const tdName = document.createElement( 'td' );
			const strong = document.createElement( 'strong' );
			strong.textContent = asset.name || '';
			tdName.appendChild( strong );

			const tdSpecs = document.createElement( 'td' );
			let specText = String( asset.format || '' ).toUpperCase();
			if ( asset.bitrate ) {
				specText += ' • ' + asset.bitrate + ' kbps';
			}
			tdSpecs.textContent = specText || '—';

			const tdSize = document.createElement( 'td' );
			tdSize.textContent = formatSize( asset.size || 0 );

			const tdId = document.createElement( 'td' );
			const code = document.createElement( 'code' );
			code.textContent = assetId;
			tdId.appendChild( code );

			const tdAct = document.createElement( 'td' );
			tdAct.className = 'mwvip-vault-row-actions';
			const copyBtn = document.createElement( 'button' );
			copyBtn.type = 'button';
			copyBtn.className = 'button button-small';
			copyBtn.setAttribute( 'data-mwvip-copy', assetId );
			copyBtn.textContent = i18n.copyId || 'Copy ID';
			tdAct.appendChild( copyBtn );

			if ( rawKey ) {
				const delBtn = document.createElement( 'button' );
				delBtn.type = 'button';
				delBtn.className = 'button-link button-link-delete';
				delBtn.setAttribute( 'data-mwvip-delete-asset', rawKey );
				delBtn.setAttribute(
					'data-mwvip-confirm',
					i18n.confirmDelete || 'Confirm?'
				);
				delBtn.textContent = i18n.deleteAsset || 'Delete';
				tdAct.appendChild( document.createTextNode( ' ' ) );
				tdAct.appendChild( delBtn );
			}

			tr.appendChild( tdName );
			tr.appendChild( tdSpecs );
			tr.appendChild( tdSize );
			tr.appendChild( tdId );
			tr.appendChild( tdAct );
			tbody.appendChild( tr );
		} );
	}

	function refreshVault( searchTerm ) {
		if ( ! cfg.restBase ) {
			return Promise.resolve();
		}
		const url =
			cfg.restBase +
			'?per_page=25' +
			( searchTerm ? '&search=' + encodeURIComponent( searchTerm ) : '' );
		return window
			.fetch( url, {
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': cfg.restNonce || '' },
			} )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( data ) {
				if ( data && Array.isArray( data.items ) ) {
					renderVaultItems( data.items );
				}
			} )
			.catch( function () {} );
	}

	function initVaultAndProvisionControls() {
		const provisionBtn = document.getElementById( 'mwvip-provision-btn' );
		const provisionStatus = document.getElementById(
			'mwvip-provision-status'
		);

		if ( provisionBtn && cfg.restBase ) {
			provisionBtn.addEventListener( 'click', function () {
				provisionBtn.disabled = true;
				if ( provisionStatus ) {
					provisionStatus.textContent =
						i18n.provisioning || 'Provisioning…';
				}
				window
					.fetch( cfg.restBase + '/provision', {
						method: 'POST',
						credentials: 'same-origin',
						headers: { 'X-WP-Nonce': cfg.restNonce || '' },
					} )
					.then( function ( r ) {
						return r.json();
					} )
					.then( function ( res ) {
						provisionBtn.disabled = false;
						if ( res && res.diagnostics ) {
							renderDiagnostics( res.diagnostics );
						}
						if ( provisionStatus ) {
							provisionStatus.textContent =
								res && res.ok
									? i18n.provisionDone || 'Provisioned.'
									: ( res && res.message ) || 'Error';
						}
						refreshVault( '' );
					} )
					.catch( function () {
						provisionBtn.disabled = false;
					} );
			} );
		}

		const vaultUploadBtn = document.getElementById(
			'mwvip-vault-upload-btn'
		);
		const vaultFileInput = document.getElementById( 'mwvip-vault-file' );
		const vaultStatus = document.getElementById( 'mwvip-vault-status' );

		if ( vaultUploadBtn && vaultFileInput && cfg.restBase ) {
			vaultUploadBtn.addEventListener( 'click', function () {
				const file = vaultFileInput.files && vaultFileInput.files[ 0 ];
				if ( ! file ) {
					vaultFileInput.click();
					return;
				}
				vaultUploadBtn.disabled = true;
				if ( vaultStatus ) {
					vaultStatus.textContent = i18n.uploading || 'Uploading…';
				}
				const fd = new window.FormData();
				fd.append( 'file', file );
				window
					.fetch( cfg.restBase, {
						method: 'POST',
						credentials: 'same-origin',
						headers: { 'X-WP-Nonce': cfg.restNonce || '' },
						body: fd,
					} )
					.then( function ( r ) {
						return r.json();
					} )
					.then( function ( res ) {
						vaultUploadBtn.disabled = false;
						if ( res && res.asset ) {
							vaultFileInput.value = '';
							if ( vaultStatus ) {
								vaultStatus.textContent =
									( i18n.uploadDone || 'Uploaded:' ) +
									' ' +
									res.asset.id;
							}
							refreshVault( '' );
							window
								.fetch( cfg.restBase + '/diagnostics', {
									credentials: 'same-origin',
									headers: {
										'X-WP-Nonce': cfg.restNonce || '',
									},
								} )
								.then( function ( r ) {
									return r.json();
								} )
								.then( renderDiagnostics )
								.catch( function () {} );
						} else if ( vaultStatus ) {
							vaultStatus.textContent =
								( res && res.message ) ||
								i18n.uploadFailed ||
								'Upload failed.';
						}
					} )
					.catch( function () {
						vaultUploadBtn.disabled = false;
						if ( vaultStatus ) {
							vaultStatus.textContent =
								i18n.uploadFailed || 'Upload failed.';
						}
					} );
			} );
		}

		const vaultSearch = document.getElementById( 'mwvip-vault-search' );
		if ( vaultSearch ) {
			let vaultTimer = null;
			vaultSearch.addEventListener( 'input', function () {
				window.clearTimeout( vaultTimer );
				vaultTimer = window.setTimeout( function () {
					refreshVault( vaultSearch.value.trim() );
				}, 250 );
			} );
		}

		document.addEventListener( 'click', function ( event ) {
			const copyBtn =
				event.target && event.target.closest
					? event.target.closest( '[data-mwvip-copy]' )
					: null;
			if ( copyBtn ) {
				const val = copyBtn.getAttribute( 'data-mwvip-copy' ) || '';
				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( val ).then( function () {
						const prev = copyBtn.textContent;
						copyBtn.textContent = i18n.copied || 'Copied!';
						window.setTimeout( function () {
							copyBtn.textContent = prev;
						}, 1400 );
					} );
				}
				return;
			}

			const delBtn =
				event.target && event.target.closest
					? event.target.closest( '[data-mwvip-delete-asset]' )
					: null;
			if (
				delBtn &&
				cfg.restBase &&
				delBtn.getAttribute( 'data-mwvip-confirmed' ) === 'true'
			) {
				event.preventDefault();
				const key =
					delBtn.getAttribute( 'data-mwvip-delete-asset' ) || '';
				if ( ! key ) {
					return;
				}
				delBtn.disabled = true;
				window
					.fetch( cfg.restBase + '/' + encodeURIComponent( key ), {
						method: 'DELETE',
						credentials: 'same-origin',
						headers: { 'X-WP-Nonce': cfg.restNonce || '' },
					} )
					.then( function ( r ) {
						return r.json();
					} )
					.then( function ( res ) {
						if ( res && res.deleted ) {
							const tr = delBtn.closest( 'tr' );
							if ( tr ) {
								tr.remove();
							}
							if ( res.diagnostics ) {
								renderDiagnostics( res.diagnostics );
							}
						} else {
							delBtn.disabled = false;
						}
					} )
					.catch( function () {
						delBtn.disabled = false;
					} );
			}
		} );
	}

	function initDeliveryAndSecretControls() {
		const providerSelect = document.getElementById(
			'mwvip-delivery-provider'
		);
		const sendfileSelect = document.getElementById( 'mwvip-sendfile-mode' );
		const xaccelRows = Array.prototype.slice.call(
			document.querySelectorAll( '.mwvip-xaccel-row' )
		);

		function syncDeliveryRows() {
			const mode = providerSelect ? providerSelect.value : 'local';
			const isLocalLike = mode === 'local' || mode === 'proxy';

			// Every bounded section collapses when the chosen mode does not use
			// it: the page then shows only what can take effect, while the header
			// (and its badge) stays visible so the admin still sees the section.
			Array.prototype.forEach.call(
				document.querySelectorAll( '[data-mwvip-section]' ),
				function ( section ) {
					const modes = (
						section.getAttribute( 'data-mwvip-section-modes' ) || ''
					)
						.split( /\s+/ )
						.filter( Boolean );
					let applies =
						modes.length === 0 || modes.indexOf( mode ) !== -1;
					if (
						applies &&
						section.getAttribute( 'data-mwvip-needs-upload' ) ===
							'1' &&
						mode === 'remote_redirect'
					) {
						const queue = document.getElementById(
							'mwvip-upload-enabled'
						);
						applies = Boolean( queue && queue.checked );
					}
					section.classList.toggle( 'is-collapsed', ! applies );

					const badge = section.querySelector(
						'[data-mwvip-applies]'
					);
					if ( ! badge ) {
						return;
					}
					badge.classList.toggle( 'is-on', applies );
					badge.classList.toggle( 'is-off', ! applies );
					badge.textContent = applies
						? i18n.appliesNow || badge.textContent
						: i18n.appliesNo || badge.textContent;
				}
			);

			// Exactly one "what to fill for this mode" hint under the chooser.
			Array.prototype.forEach.call(
				document.querySelectorAll( '[data-mwvip-mode-hint]' ),
				function ( hint ) {
					const hintMode =
						hint.getAttribute( 'data-mwvip-mode-hint' ) || '';
					hint.style.display = hintMode === mode ? '' : 'none';
				}
			);

			// Keep the quick-guide matrix in step with the chooser.
			Array.prototype.forEach.call(
				document.querySelectorAll( '[data-mwvip-matrix-mode]' ),
				function ( row ) {
					row.classList.toggle(
						'is-current',
						row.getAttribute( 'data-mwvip-matrix-mode' ) === mode
					);
				}
			);

			const showXaccel =
				isLocalLike &&
				Boolean( sendfileSelect ) &&
				sendfileSelect.value === 'xaccel';
			xaccelRows.forEach( function ( row ) {
				row.style.display = showXaccel ? '' : 'none';
			} );
		}

		if ( providerSelect ) {
			providerSelect.addEventListener( 'change', syncDeliveryRows );
		}
		if ( sendfileSelect ) {
			sendfileSelect.addEventListener( 'change', syncDeliveryRows );
		}
		if ( providerSelect || sendfileSelect ) {
			syncDeliveryRows();
		}

		// Remote-upload: show the pipeline rows only when enabled, and only the
		// credential rows belonging to the selected transfer method.
		const uploadToggle = document.getElementById( 'mwvip-upload-enabled' );
		const uploadMode = document.getElementById( 'mwvip-upload-mode' );
		const uploadGroups = Array.prototype.slice.call(
			document.querySelectorAll( '.mwvip-upload-group' )
		);

		function syncUploadRows() {
			const on = uploadToggle ? uploadToggle.checked : false;
			const mode = uploadMode ? uploadMode.value : 'webhook';
			uploadGroups.forEach( function ( row ) {
				let visible = on;
				if (
					visible &&
					row.classList.contains( 'mwvip-upload-creds' )
				) {
					const modes = (
						row.getAttribute( 'data-modes' ) || ''
					).split( /\s+/ );
					visible = modes.indexOf( mode ) !== -1;
				}
				row.style.display = visible ? '' : 'none';
				// A hidden row must not submit: several modes share
				// `remote_upload_endpoint`, `remote_upload_auth_token` and
				// `remote_upload_access_key`, and an empty duplicate would
				// override the value typed into the visible row.
				Array.prototype.forEach.call(
					row.querySelectorAll( 'input, button, select, textarea' ),
					function ( control ) {
						control.disabled = ! visible;
					}
				);
			} );
		}

		if ( uploadToggle ) {
			uploadToggle.addEventListener( 'change', syncUploadRows );
			// The storage section only applies in remote_redirect when the queue
			// is on, so its collapse state tracks this toggle too.
			uploadToggle.addEventListener( 'change', syncDeliveryRows );
		}
		if ( uploadMode ) {
			uploadMode.addEventListener( 'change', syncUploadRows );
		}
		if ( uploadToggle || uploadMode ) {
			syncUploadRows();
		}

		// Master VIP toggle live control state + browser unlock flag.
		const moduleToggle = document.getElementById( 'mwvip-module-enabled' );
		const unlockedInput = document.querySelector(
			'[data-mwvip-controls-unlocked]'
		);
		if ( moduleToggle ) {
			moduleToggle.addEventListener( 'change', function () {
				const enabled = Boolean( moduleToggle.checked );
				if ( unlockedInput ) {
					unlockedInput.value = enabled ? '1' : '0';
				}
				Array.prototype.forEach.call(
					document.querySelectorAll( '.mwvip-module-control' ),
					function ( ctrl ) {
						ctrl.disabled = ! enabled;
					}
				);
				const note = document.getElementById( 'mwvip-membership-note' );
				if ( note ) {
					note.style.display = enabled ? 'none' : '';
				}
			} );
		}
	}

	// Delivery tools: edge connection test, upload connection test, library
	// sync, and queue status. Each posts to its REST route with the WP nonce and
	// reports the result inline (no browser alerts).
	function initTools() {
		const tools = cfg.tools || {};

		function setFeedback( el, text, ok ) {
			if ( ! el ) {
				return;
			}
			el.textContent = text;
			el.style.color = ok ? '#166534' : '#b32d2e';
		}

		function post( url, body ) {
			return window
				.fetch( url, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'X-WP-Nonce': cfg.restNonce || '',
						'Content-Type': 'application/json',
					},
					body: body ? JSON.stringify( body ) : undefined,
				} )
				.then( function ( r ) {
					return r.json().then( function ( data ) {
						return { status: r.status, data };
					} );
				} );
		}

		function wire( btnId, feedbackId, url, busyText, map ) {
			const btn = document.getElementById( btnId );
			if ( ! btn || ! url ) {
				return;
			}
			const fb = document.getElementById( feedbackId );
			btn.addEventListener( 'click', function () {
				const prev = btn.textContent;
				btn.disabled = true;
				setFeedback( fb, busyText, true );
				post( url )
					.then( function ( res ) {
						map( res, fb, setFeedback );
					} )
					.catch( function () {
						setFeedback( fb, i18n.testFailed || 'Error', false );
					} )
					.then( function () {
						btn.disabled = false;
						btn.textContent = prev;
					} );
			} );
		}

		wire(
			'mwvip-edge-test',
			'mwvip-edge-feedback',
			tools.edgeTest,
			i18n.testing || '...',
			function ( res, fb, set ) {
				const data = res.data || {};
				if ( res.status >= 200 && res.status < 300 && data.ok ) {
					set( fb, i18n.testOk || 'OK', true );
				} else {
					set(
						fb,
						( i18n.testFailed || '' ) +
							' ' +
							( data.error || data.message || res.status ),
						false
					);
				}
			}
		);

		wire(
			'mwvip-upload-test',
			'mwvip-upload-feedback',
			tools.uploadTest,
			i18n.testing || '...',
			function ( res, fb, set ) {
				const data = res.data || {};
				if ( res.status >= 200 && res.status < 300 && data.ok ) {
					set( fb, i18n.testOk || 'OK', true );
				} else {
					set(
						fb,
						( i18n.testFailed || '' ) +
							' ' +
							( data.error || data.message || res.status ),
						false
					);
				}
			}
		);

		wire(
			'mwvip-upload-sync',
			'mwvip-upload-feedback',
			tools.uploadSync,
			i18n.syncing || '...',
			function ( res, fb, set ) {
				const data = res.data || {};
				if ( res.status >= 200 && res.status < 300 ) {
					set(
						fb,
						( i18n.syncDone || 'OK' ) +
							' — synced: ' +
							( data.synced || 0 ) +
							', failed: ' +
							( data.failed || 0 ),
						true
					);
				} else {
					set( fb, data.message || res.status, false );
				}
			}
		);

		wire(
			'mwvip-upload-status',
			'mwvip-upload-feedback',
			tools.uploadStatus,
			i18n.statusLoading || '...',
			function ( res, fb, set ) {
				const q = ( res.data && res.data.queue ) || {};
				if ( res.status >= 200 && res.status < 300 ) {
					set(
						fb,
						'pending: ' +
							( q.pending || 0 ) +
							', uploading: ' +
							( q.uploading || 0 ) +
							', synced: ' +
							( q.synced || 0 ) +
							', failed: ' +
							( q.failed || 0 ),
						Number( q.failed || 0 ) === 0
					);
				} else {
					set( fb, res.status, false );
				}
			}
		);
	}

	// Secure-key generation and reveal toggles for every secret field.
	function initSecretTools() {
		document.addEventListener( 'click', function ( event ) {
			const target = event.target;
			if ( ! target || ! target.closest ) {
				return;
			}

			const genBtn = target.closest( '[data-mwvip-generate]' );
			if ( genBtn ) {
				event.preventDefault();
				const input = document.getElementById(
					genBtn.getAttribute( 'data-mwvip-generate' ) || ''
				);
				if ( ! input ) {
					return;
				}
				const bytes =
					parseInt(
						genBtn.getAttribute( 'data-mwvip-generate-bytes' ),
						10
					) || 32;
				if ( window.crypto && window.crypto.getRandomValues ) {
					const arr = new Uint8Array( bytes );
					window.crypto.getRandomValues( arr );
					input.value = Array.from( arr, function ( b ) {
						return b.toString( 16 ).padStart( 2, '0' );
					} ).join( '' );
					input.type = 'text';
					input.focus();
				}
				return;
			}

			const revealBtn = target.closest( '[data-mwvip-reveal]' );
			if ( revealBtn ) {
				event.preventDefault();
				const input = document.getElementById(
					revealBtn.getAttribute( 'data-mwvip-reveal' ) || ''
				);
				if ( input ) {
					input.type =
						input.type === 'password' ? 'text' : 'password';
				}
			}
		} );
	}

	function initConfirmations() {
		document.addEventListener( 'click', function ( event ) {
			const btn =
				event.target && event.target.closest
					? event.target.closest( '[data-mwvip-confirm]' )
					: null;
			if (
				! btn ||
				btn.getAttribute( 'data-mwvip-confirmed' ) === 'true'
			) {
				return;
			}
			event.preventDefault();
			const label =
				btn.getAttribute( 'data-mwvip-confirm' ) || 'تأیید حذف؟';
			const prev = btn.textContent || '';
			btn.setAttribute( 'data-mwvip-confirmed', 'true' );
			btn.textContent = label;
			window.setTimeout( function () {
				if ( btn.isConnected ) {
					btn.removeAttribute( 'data-mwvip-confirmed' );
					btn.textContent = prev;
				}
			}, 4000 );
		} );
	}

	function boot() {
		initTabs();
		initConfirmations();
		initVaultAndProvisionControls();
		initDeliveryAndSecretControls();
		initTools();
		initSecretTools();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
