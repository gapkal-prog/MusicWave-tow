/**
 * MusicWave VIP Settings Admin UI Controller (CSP-clean, zero inline scripts, zero browser alerts).
 *
 * Synchronized 1:1 with `ManaCore\MusicWave\Vip\SettingsPage`:
 * - `.mwvip-tabs button[data-tab]` <-> `.mwvip-panel[data-panel]` (`.active` + ARIA + RTL arrows + URL/session persistence)
 * - `#mwvip-delivery-provider` & `#mwvip-sendfile-mode` <-> `tr.mwvip-local-row` / `tr.mwvip-remote-row` / `tr.mwvip-xaccel-row`
 * - `#mwvip-module-enabled` <-> `.mwvip-module-control`, `[data-mwvip-controls-unlocked]` & `#mwvip-membership-note`
 * - `#mwvip-provision-btn`, `#mwvip-vault-upload-btn`, `#mwvip-vault-search`, `[data-mwvip-copy]`, `[data-mwvip-delete-asset]`
 * - `#mwvip-toggle-secret` & `#mwvip-gen-secret` <-> `#mwvip-remote-secret`
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
		const localRows = Array.prototype.slice.call(
			document.querySelectorAll( '.mwvip-local-row' )
		);
		const remoteRows = Array.prototype.slice.call(
			document.querySelectorAll( '.mwvip-remote-row' )
		);
		const xaccelRows = Array.prototype.slice.call(
			document.querySelectorAll( '.mwvip-xaccel-row' )
		);

		function syncDeliveryRows() {
			const isRemote =
				Boolean( providerSelect ) &&
				providerSelect.value === 'remote_redirect';
			localRows.forEach( function ( row ) {
				row.style.display = isRemote ? 'none' : '';
			} );
			remoteRows.forEach( function ( row ) {
				row.style.display = isRemote ? '' : 'none';
			} );
			const showXaccel =
				! isRemote &&
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

		// Remote signing secret visibility + CSPRNG hex generator.
		const secretInput = document.getElementById( 'mwvip-remote-secret' );
		const toggleBtn = document.getElementById( 'mwvip-toggle-secret' );
		const genBtn = document.getElementById( 'mwvip-gen-secret' );

		if ( secretInput && toggleBtn ) {
			toggleBtn.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				secretInput.type =
					secretInput.type === 'password' ? 'text' : 'password';
			} );
		}
		if (
			secretInput &&
			genBtn &&
			window.crypto &&
			window.crypto.getRandomValues
		) {
			genBtn.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				const bytes = new Uint8Array( 32 );
				window.crypto.getRandomValues( bytes );
				secretInput.value = Array.from( bytes, function ( b ) {
					return b.toString( 16 ).padStart( 2, '0' );
				} ).join( '' );
				secretInput.type = 'text';
				secretInput.focus();
			} );
		}
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
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
