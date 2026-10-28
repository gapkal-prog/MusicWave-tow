/**
 * Auto-fill music metadata & cover art - editor panel.
 *
 * Drives the "Auto-fill metadata & cover art" meta box on the release editor.
 * Talks to the REST endpoints registered by MetadataLookupRoutes:
 *   GET  /music-wave/v1/metadata-lookup?q=
 *   POST /music-wave/v1/metadata-lookup/apply
 *
 * @package
 */
( function () {
	'use strict';

	var config = window.musicWaveMetadataLookup || {};
	var strings = config.strings || {};
	var nonce = config.nonce || '';
	var root = config.root || '';

	// Plain permalinks use ?rest_route=; never strip that routing parameter.
	function endpoint( suffix, query ) {
		var url = new URL( root, window.location.href );
		if ( url.searchParams.has( 'rest_route' ) ) {
			url.searchParams.set(
				'rest_route',
				url.searchParams.get( 'rest_route' ).replace( /\/$/, '' ) +
					suffix
			);
		} else {
			url.pathname = url.pathname.replace( /\/$/, '' ) + suffix;
		}
		new URLSearchParams( query || '' ).forEach( function ( value, key ) {
			url.searchParams.append( key, value );
		} );
		return url.toString();
	}

	function t( key, fallback ) {
		return strings[ key ] || fallback || key;
	}

	function ready( fn ) {
		if ( document.readyState !== 'loading' ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	function el( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( undefined !== text ) {
			node.textContent = text;
		}
		return node;
	}

	function buildUi( container ) {
		var postId =
			parseInt( container.getAttribute( 'data-post-id' ), 10 ) || 0;
		var releaseTypes = [];
		try {
			releaseTypes = JSON.parse(
				container.getAttribute( 'data-release-types' ) || '[]'
			);
		} catch ( e ) {}
		if ( ! Array.isArray( releaseTypes ) ) {
			releaseTypes = [];
		}

		container.innerHTML = '';

		var form = el( 'div', 'mw-metadata-lookup__form' );
		var input = document.createElement( 'input' );
		input.type = 'search';
		input.className = 'mw-metadata-lookup__input';
		input.placeholder = t( 'queryPlaceholder', 'قطعه، هنرمند یا آلبوم…' );
		input.setAttribute(
			'aria-label',
			t( 'queryPlaceholder', 'قطعه، هنرمند یا آلبوم' )
		);

		var button = el(
			'button',
			'button button-secondary mw-metadata-lookup__search',
			t( 'search', 'جست‌وجو' )
		);
		button.type = 'button';

		form.appendChild( input );
		form.appendChild( button );

		// Phase 2: debounce + abort controller for better UX and less load.
		var debounceTimer = null;
		var currentAbort = null;

		var advanced = document.createElement( 'details' );
		advanced.className = 'mw-metadata-lookup__advanced';
		var advancedFields = el( 'div', 'mw-metadata-lookup__advanced-fields' );
		var artistInput = searchField( 'artist', t( 'artist', 'هنرمند' ) );
		var albumInput = searchField( 'album', t( 'album', 'آلبوم' ) );
		var yearInput = searchField( 'year', t( 'year', 'سال' ) );
		yearInput.inputMode = 'numeric';
		yearInput.maxLength = 4;
		advanced.appendChild(
			el( 'summary', '', t( 'refineSearch', 'اصلاح جست‌وجو' ) )
		);
		advancedFields.appendChild( artistInput.parentNode );
		advancedFields.appendChild( albumInput.parentNode );
		advancedFields.appendChild( yearInput.parentNode );
		advanced.appendChild( advancedFields );

		var status = el( 'p', 'mw-metadata-lookup__status' );
		status.setAttribute( 'role', 'status' );

		var results = el( 'div', 'mw-metadata-lookup__results' );

		container.appendChild( form );
		container.appendChild( advanced );
		container.appendChild( status );
		container.appendChild( results );

		function searchField( name, labelText ) {
			var label = el( 'label', 'mw-metadata-lookup__advanced-field' );
			var field = document.createElement( 'input' );
			field.type = 'text';
			field.name = name;
			field.className = 'widefat';
			label.appendChild( el( 'span', '', labelText ) );
			label.appendChild( field );
			return field;
		}

		function setStatus( message, isError ) {
			status.textContent = message || '';
			status.classList.toggle(
				'mw-metadata-lookup__status--error',
				!! isError
			);
		}

		function headers( includeContentType ) {
			var output = { 'X-WP-Nonce': nonce, Accept: 'application/json' };
			if ( includeContentType ) {
				output[ 'Content-Type' ] = 'application/json';
			}
			return output;
		}

		function parseResponse( response ) {
			return response
				.json()
				.catch( function () {
					return {};
				} )
				.then( function ( payload ) {
					if ( ! response.ok ) {
						throw new Error(
							payload.message ||
								t(
									'error',
									'جست‌وجو انجام نشد. دوباره امتحان کنید.'
								)
						);
					}
					return payload;
				} );
		}

		function search() {
			if ( button.disabled ) {
				return;
			}
			var q = input.value.trim();
			var artist = artistInput.value.trim();
			var album = albumInput.value.trim();
			if ( ! q && ! artist && ! album ) {
				setStatus(
					t(
						'queryPlaceholder',
						'یک قطعه، هنرمند یا آلبوم وارد کنید.'
					),
					true
				);
				return;
			}
			var year = yearInput.value.trim();
			button.disabled = true;
			setStatus( t( 'searching', 'در حال جست‌وجو…' ) );
			results.innerHTML = '';

			// Abort previous search if still pending.
			if ( currentAbort ) {
				try {
					currentAbort.abort();
				} catch ( e ) {}
			}
			currentAbort =
				typeof AbortController !== 'undefined'
					? new AbortController()
					: null;

			var params = [ 'limit=8' ];
			if ( q ) {
				params.push(
					( artist || album ? 'track=' : 'q=' ) +
						encodeURIComponent( q )
				);
			}
			if ( artist ) {
				params.push( 'artist=' + encodeURIComponent( artist ) );
			}
			if ( album ) {
				params.push( 'album=' + encodeURIComponent( album ) );
			}
			if ( year ) {
				params.push( 'year=' + encodeURIComponent( year ) );
			}
			releaseTypes.forEach( function ( releaseType ) {
				params.push(
					'release_types[]=' + encodeURIComponent( releaseType )
				);
			} );

			var fetchOpts = {
				method: 'GET',
				headers: headers( false ),
				credentials: 'same-origin',
			};
			if ( currentAbort ) {
				fetchOpts.signal = currentAbort.signal;
			}

			fetch( endpoint( '', params.join( '&' ) ), fetchOpts )
				.then( parseResponse )
				.then( function ( payload ) {
					button.disabled = false;
					if (
						! payload ||
						! payload.success ||
						! payload.results ||
						! payload.results.length
					) {
						setStatus(
							( payload && payload.message ) ||
								t( 'noResults', 'هیچ نتیجه‌ای پیدا نشد.' ),
							true
						);
						return;
					}
					renderResults( payload.results, payload );
					setStatus(
						payload.label
							? t( 'providedBy', 'منبع' ) + ': ' + payload.label
							: ''
					);
				} )
				.catch( function ( error ) {
					// Ignore abort errors.
					if ( error && error.name === 'AbortError' ) {
						return;
					}
					button.disabled = false;
					setStatus(
						error.message ||
							t(
								'error',
								'جست‌وجو انجام نشد. دوباره امتحان کنید.'
							),
						true
					);
				} );
		}

		function renderResults( list, payload ) {
			results.innerHTML = '';
			list.forEach( function ( item ) {
				var card = el( 'div', 'mw-metadata-lookup__card' );

				if ( item.cover_url ) {
					var coverWrap = el(
						'div',
						'mw-metadata-lookup__cover-wrap'
					);
					var img = document.createElement( 'img' );
					img.src = item.cover_url;
					img.alt = t( 'coverAlt', 'پیش‌نمایش جلد آلبوم' );
					img.className = 'mw-metadata-lookup__cover';
					coverWrap.appendChild( img );

					// Show simple badge that cover exists from this source (quality ignored).
					var badge = el(
						'span',
						'mw-metadata-lookup__cover-badge',
						t( 'hasCover', 'کاور' )
					);
					coverWrap.appendChild( badge );

					card.appendChild( coverWrap );
				}

				var body = el( 'div', 'mw-metadata-lookup__body' );
				var title = el(
					'strong',
					'mw-metadata-lookup__title',
					item.title || ''
				);
				// Phase 4: smart meta based on entity_type and available fields.
				var metaParts = [];
				if ( item.artist ) {
					metaParts.push( item.artist );
				}
				// For track/single show album, for album hide redundant.
				if ( item.album && item.album !== item.title ) {
					// Only show album if entity is track or explicit album different.
					if (
						! item.entity_type ||
						item.entity_type === 'track' ||
						item.entity_type === 'episode'
					) {
						metaParts.push( item.album );
					}
				}
				if ( item.year ) {
					metaParts.push( item.year );
				}
				if ( item.entity_type ) {
					var typeLabels = {
						track: 'ترک',
						album: 'آلبوم',
						playlist: 'پلی‌لیست',
						show: 'پادکست',
						episode: 'قسمت پادکست',
					};
					metaParts.push(
						typeLabels[ item.entity_type ] || item.entity_type
					);
				}
				var meta = el(
					'span',
					'mw-metadata-lookup__meta',
					metaParts.filter( Boolean ).join( ' • ' )
				);

				// Smart details: include relevant fields per type.
				var detailParts = [];
				var genreStr =
					entityNames( item.genres ).join( ', ' ) || item.genre || '';
				if ( genreStr ) {
					detailParts.push( genreStr );
				}
				var labelStr = entityNames( item.labels ).join( ', ' );
				if ( labelStr ) {
					detailParts.push( labelStr );
				}
				if ( item.publisher ) {
					detailParts.push( item.publisher );
				}
				if ( item.country ) {
					detailParts.push( item.country );
				}
				if ( item.language ) {
					detailParts.push( item.language );
				}
				if ( item.track_number && item.total_tracks ) {
					detailParts.push(
						item.track_number + '/' + item.total_tracks
					);
				} else if ( item.track_number ) {
					// For podcast episode, this will be mapped to episode number server-side.
					detailParts.push( 'ترک ' + item.track_number );
				} else if ( item.total_tracks ) {
					detailParts.push( item.total_tracks + ' ترک' );
				}
				if ( item.duration ) {
					detailParts.push( formatDuration( item.duration ) );
				}
				if ( item.isrc ) {
					detailParts.push( 'ISRC: ' + item.isrc );
				}
				if ( item.barcode ) {
					detailParts.push( 'بارکد: ' + item.barcode );
				}
				if ( item.explicit ) {
					detailParts.push( 'Explicit' );
				}
				var moodStr = entityNames( item.moods ).join( ', ' );
				if ( moodStr ) {
					detailParts.push( moodStr );
				}
				var details = el(
					'span',
					'mw-metadata-lookup__details',
					detailParts.filter( Boolean ).join( ' • ' )
				);

				var applyBtn = el(
					'button',
					'button button-primary mw-metadata-lookup__apply',
					t( 'apply', 'اعمال' )
				);
				applyBtn.type = 'button';
				applyBtn.addEventListener( 'click', function () {
					applyResult( item, payload, applyBtn );
				} );

				body.appendChild( title );
				body.appendChild( meta );
				if ( details.textContent ) {
					body.appendChild( details );
				}
				body.appendChild( applyBtn );

				card.appendChild( body );
				results.appendChild( card );
			} );
		}

		function formatDuration( seconds ) {
			var total = parseInt( seconds, 10 ) || 0;
			var minutes = Math.floor( total / 60 );
			var remainder = total % 60;
			return minutes + ':' + ( remainder < 10 ? '0' : '' ) + remainder;
		}

		function entityNames( entities ) {
			if ( ! Array.isArray( entities ) ) {
				return [];
			}
			return entities
				.map( function ( entity ) {
					return entity && entity.name ? entity.name : '';
				} )
				.filter( Boolean );
		}

		function applyResult( item, payload, btn ) {
			btn.disabled = true;
			btn.textContent = t( 'applying', 'در حال اعمال…' );

			var body = {
				post_id: postId,
				reference_id: item.reference_id || '',
				entity_type: item.entity_type || '',
				title: item.title || '',
				artist: item.artist || '',
				album: item.album || '',
				year: item.year || '',
				release_date: item.release_date || '',
				genre: item.genre || '',
				duration: item.duration || 0,
				isrc: item.isrc || '',
				cover_url: item.cover_url || '',
				cover_width: item.cover_width || 0,
				cover_height: item.cover_height || 0,
				provider:
					item.provider || ( payload && payload.provider ) || '',
				source_url: item.source_url || '',
				description: item.description || '',
				artists: item.artists || [],
				genres: item.genres || [],
				labels: item.labels || [],
				moods: item.moods || [],
				tags: item.tags || [],
				track_number: item.track_number || 0,
				total_tracks: item.total_tracks || 0,
				country: item.country || '',
				barcode: item.barcode || '',
				explicit: !! item.explicit,
				publisher: item.publisher || '',
				language: item.language || '',
				fill_content: editorFieldIsEmpty( 'content' ),
				fill_excerpt: editorFieldIsEmpty( 'excerpt' ),
			};

			fetch( endpoint( '/apply' ), {
				method: 'POST',
				headers: headers( true ),
				credentials: 'same-origin',
				body: JSON.stringify( body ),
			} )
				.then( parseResponse )
				.then( function ( responsePayload ) {
					if ( ! responsePayload || ! responsePayload.success ) {
						btn.disabled = false;
						btn.textContent = t( 'apply', 'اعمال' );
						setStatus(
							( responsePayload && responsePayload.message ) ||
								t(
									'error',
									'جست‌وجو انجام نشد. دوباره امتحان کنید.'
								),
							true
						);
						return;
					}
					var warningText =
						responsePayload.warnings &&
						responsePayload.warnings.length
							? ' ' + responsePayload.warnings.join( ' ' )
							: '';
					setStatus(
						( responsePayload.message ||
							t( 'applied', 'فراداده اعمال شد.' ) ) + warningText,
						false
					);
					reflectSavedFields( responsePayload );
					btn.disabled = true;
					btn.textContent = t( 'applied', 'فراداده اعمال شد.' );
					if (
						responsePayload.attachment &&
						responsePayload.attachment.url
					) {
						refreshFeaturedImage( responsePayload.attachment.id );
					}
				} )
				.catch( function ( error ) {
					btn.disabled = false;
					btn.textContent = t( 'apply', 'اعمال' );
					setStatus(
						error.message ||
							t(
								'error',
								'جست‌وجو انجام نشد. دوباره امتحان کنید.'
							),
						true
					);
				} );
		}

		function reflectSavedFields( payload ) {
			var fields = payload.fields || {};
			var fieldMap = {
				album: 'mw_album',
				year: 'mw_release_year',
				date: 'mw_release_date',
				duration: 'mw_duration',
				isrc: 'mw_isrc',
				track_number: 'mw_track_number',
				total_tracks: 'mw_total_tracks',
				country: 'mw_country',
				barcode: 'mw_barcode',
				publisher: 'mw_publisher',
				language: 'mw_language',
				explicit: 'mw_explicit',
			};

			Object.keys( fieldMap ).forEach( function ( key ) {
				if (
					undefined === fields[ key ] ||
					null === fields[ key ] ||
					'' === fields[ key ] ||
					0 === fields[ key ]
				) {
					return;
				}
				var inputField = document.getElementById( fieldMap[ key ] );
				if ( inputField ) {
					inputField.value = fields[ key ];
					inputField.dispatchEvent(
						new Event( 'change', { bubbles: true } )
					);
				}
			} );

			var titleField = document.getElementById( 'title' );
			if ( titleField && fields.title ) {
				titleField.value = fields.title;
				titleField.dispatchEvent(
					new Event( 'input', { bubbles: true } )
				);
			}

			var artistTags = document.querySelector(
				'input[name="tax_input[mw_artist]"]'
			);
			syncClassicTaxonomy( 'mw_artist', payload, false, artistTags );
			syncClassicTaxonomy( 'mw_label', payload, false );
			syncClassicTaxonomy( 'mw_genre', payload, true );
			syncClassicTaxonomy( 'mw_mood', payload, false );

			if ( fields.description && fields.content_applied ) {
				var contentField = document.getElementById( 'content' );
				if ( contentField ) {
					contentField.value = fields.description;
				}
				if ( window.tinymce && tinymce.get( 'content' ) ) {
					tinymce.get( 'content' ).setContent( fields.description );
				}
			}
			if ( fields.description && fields.excerpt_applied ) {
				var excerptField = document.getElementById( 'excerpt' );
				if ( excerptField ) {
					excerptField.value = fields.description
						.replace( /<[^>]+>/g, '' )
						.split( /\s+/ )
						.slice( 0, 40 )
						.join( ' ' );
				}
			}

			if ( window.wp && wp.data && wp.data.dispatch ) {
				try {
					var edits = {};
					var meta = {};
					if ( fields.title ) {
						edits.title = fields.title;
					}
					if ( fields.description && fields.content_applied ) {
						edits.content = fields.description;
					}
					if ( fields.description && fields.excerpt_applied ) {
						edits.excerpt = fields.description
							.replace( /<[^>]+>/g, '' )
							.split( /\s+/ )
							.slice( 0, 40 )
							.join( ' ' );
					}
					Object.keys( fieldMap ).forEach( function ( key ) {
						if (
							undefined !== fields[ key ] &&
							null !== fields[ key ] &&
							'' !== fields[ key ] &&
							0 !== fields[ key ]
						) {
							meta[ fieldMap[ key ] ] = fields[ key ];
						}
					} );
					if ( Object.keys( meta ).length ) {
						edits.meta = meta;
					}
					if (
						payload.terms &&
						payload.terms.mw_artist &&
						payload.terms.mw_artist.length
					) {
						edits.mw_artist = payload.terms.mw_artist;
					}
					if (
						payload.terms &&
						payload.terms.mw_genre &&
						payload.terms.mw_genre.length
					) {
						edits.mw_genre = payload.terms.mw_genre;
					}
					if (
						payload.terms &&
						payload.terms.mw_label &&
						payload.terms.mw_label.length
					) {
						edits.mw_label = payload.terms.mw_label;
					}
					if (
						payload.terms &&
						payload.terms.mw_mood &&
						payload.terms.mw_mood.length
					) {
						edits.mw_mood = payload.terms.mw_mood;
					}
					wp.data.dispatch( 'core/editor' ).editPost( edits );
				} catch ( e ) {}
			}
		}

		function syncClassicTaxonomy(
			taxonomy,
			payload,
			hierarchical,
			existingField
		) {
			var ids =
				payload.terms && payload.terms[ taxonomy ]
					? payload.terms[ taxonomy ].map( String )
					: [];
			var names =
				payload.term_names && payload.term_names[ taxonomy ]
					? payload.term_names[ taxonomy ]
					: [];
			if ( ! hierarchical ) {
				var field =
					existingField ||
					document.querySelector(
						'input[name="tax_input[' +
							taxonomy +
							']"], textarea[name="tax_input[' +
							taxonomy +
							']"]'
					);
				if ( field && names.length ) {
					field.value = names.join( ', ' );
					field.dispatchEvent(
						new Event( 'change', { bubbles: true } )
					);
				}
				return;
			}

			var container =
				document.getElementById( taxonomy + 'div' ) ||
				document.getElementById( taxonomy + '-all' );
			if ( ! container || ! ids.length ) {
				return;
			}
			var checkboxes = container.querySelectorAll(
				'input[type="checkbox"][name="tax_input[' + taxonomy + '][]"]'
			);
			var rendered = {};
			checkboxes.forEach( function ( checkbox ) {
				rendered[ String( checkbox.value ) ] = true;
				checkbox.checked =
					ids.indexOf( String( checkbox.value ) ) !== -1;
			} );
			container
				.querySelectorAll( '.mw-metadata-assigned' )
				.forEach( function ( field ) {
					field.remove();
				} );
			ids.forEach( function ( id ) {
				if ( rendered[ id ] ) {
					return;
				}
				var hidden = document.createElement( 'input' );
				hidden.type = 'hidden';
				hidden.name = 'tax_input[' + taxonomy + '][]';
				hidden.value = id;
				hidden.className = 'mw-metadata-assigned';
				container.appendChild( hidden );
			} );
		}

		function editorFieldIsEmpty( fieldName ) {
			var value = '';
			if ( window.wp && wp.data && wp.data.select ) {
				try {
					value =
						wp.data
							.select( 'core/editor' )
							.getEditedPostAttribute( fieldName ) || '';
				} catch ( e ) {}
			}
			if (
				! value &&
				'content' === fieldName &&
				window.tinymce &&
				tinymce.get( 'content' )
			) {
				value =
					tinymce.get( 'content' ).getContent( { format: 'text' } ) ||
					'';
			}
			if ( ! value ) {
				var field = document.getElementById( fieldName );
				value = field ? field.value : '';
			}
			return ! String( value )
				.replace( /<[^>]+>/g, '' )
				.trim();
		}

		function refreshFeaturedImage( attachmentId ) {
			// Block editor: ask the editor to re-read the entity record.
			if ( window.wp && wp.data && wp.data.dispatch ) {
				try {
					wp.data.dispatch( 'core/editor' ).editPost( {
						featured_media: parseInt( attachmentId, 10 ),
					} );
				} catch ( e ) {}
			}
			// Classic editor meta box refreshes on next save; nothing else to do.
		}

		button.addEventListener( 'click', search );
		input.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key ) {
				e.preventDefault();
				search();
			}
		} );
		// Phase 2: debounce on typing (400ms) to reduce API load.
		input.addEventListener( 'input', function () {
			if ( debounceTimer ) {
				clearTimeout( debounceTimer );
			}
			debounceTimer = setTimeout( function () {
				if ( input.value.trim().length >= 3 ) {
					search();
				}
			}, 400 );
		} );
	}

	ready( function () {
		var containers = document.querySelectorAll(
			'.mw-metadata-lookup[data-post-id]'
		);
		containers.forEach( buildUi );
	} );
} )();
