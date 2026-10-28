( function ( wp, document ) {
	'use strict';

	var el = wp.element.createElement;
	var render = wp.element.render;
	var useCallback = wp.element.useCallback;
	var useEffect = wp.element.useEffect;
	var useState = wp.element.useState;
	var Button = wp.components.Button;
	var Notice = wp.components.Notice;
	var SelectControl = wp.components.SelectControl;
	var TextControl = wp.components.TextControl;
	var __ = wp.i18n.__;

	function numericValue( value ) {
		var numeric = parseInt( value || '0', 10 );
		return isFinite( numeric ) && numeric > 0 ? numeric : 0;
	}

	function humanDuration( seconds ) {
		var numeric = numericValue( seconds );
		return numeric
			? Math.floor( numeric / 60 ) +
					':' +
					( '0' + ( numeric % 60 ) ).slice( -2 )
			: '';
	}

	function humanFileSize( bytes ) {
		var numeric = numericValue( bytes );
		var units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		var unit = 0;

		while ( numeric >= 1024 && unit < units.length - 1 ) {
			numeric /= 1024;
			unit += 1;
		}

		return numeric
			? ( numeric >= 10 || unit === 0
					? Math.round( numeric )
					: numeric.toFixed( 1 ) ) +
					' ' +
					units[ unit ]
			: '';
	}

	function extensionFromAsset( asset ) {
		var value = asset.name || asset.file_name || asset.asset_id || '';
		var match = value.toLowerCase().match( /\.([a-z0-9]+)(?:$|\?)/ );
		return match ? match[ 1 ] : '';
	}

	function qualitySummary( asset ) {
		var parts = [];
		var format = (
			asset.format ||
			extensionFromAsset( asset ) ||
			''
		).toUpperCase();
		var bitrate = numericValue( asset.bitrate );
		var duration = numericValue( asset.duration );
		var size = numericValue( asset.file_size || asset.size );

		if ( format ) {
			parts.push( format );
		}
		if ( bitrate ) {
			parts.push( bitrate + ' kbps' );
		}
		if ( duration ) {
			parts.push( humanDuration( duration ) );
		}
		if ( size ) {
			parts.push( humanFileSize( size ) );
		}

		return parts.join( ' · ' );
	}

	function normalizedKey( value ) {
		return (
			( value || 'download' )
				.toLowerCase()
				.replace( /[^a-z0-9_-]/g, '-' )
				.replace( /-+/g, '-' )
				.replace( /^-|-$/g, '' ) || 'download'
		);
	}

	function uniqueQualityKey( base, assets ) {
		var key = normalizedKey( base );
		var candidate = key;
		var suffix = 2;

		while (
			assets.some( function ( asset ) {
				return normalizedKey( asset.key ) === candidate;
			} )
		) {
			candidate = key + '-' + suffix;
			suffix += 1;
		}

		return candidate;
	}

	function qualityPresets() {
		return [
			{
				label: __( 'سفارشی / شناسایی شده', 'music-wave-core' ),
				value: '',
				key: '',
				format: '',
				bitrate: 0,
			},
			{
				label: __( 'MP3 128 kbps', 'music-wave-core' ),
				value: 'mp3-128',
				key: 'mp3-128',
				format: 'mp3',
				bitrate: 128,
			},
			{
				label: __( 'MP3 192 kbps', 'music-wave-core' ),
				value: 'mp3-192',
				key: 'mp3-192',
				format: 'mp3',
				bitrate: 192,
			},
			{
				label: __( 'MP3 320 kbps', 'music-wave-core' ),
				value: 'mp3-320',
				key: 'mp3-320',
				format: 'mp3',
				bitrate: 320,
			},
			{
				label: __( 'AAC 256 kbps', 'music-wave-core' ),
				value: 'aac-256',
				key: 'aac-256',
				format: 'aac',
				bitrate: 256,
			},
			{
				label: __( 'M4A 256 kbps', 'music-wave-core' ),
				value: 'm4a-256',
				key: 'm4a-256',
				format: 'm4a',
				bitrate: 256,
			},
			{
				label: __( 'OGG 320 kbps', 'music-wave-core' ),
				value: 'ogg-320',
				key: 'ogg-320',
				format: 'ogg',
				bitrate: 320,
			},
			{
				label: __( 'FLAC بدون اتلاف', 'music-wave-core' ),
				value: 'flac',
				key: 'flac',
				format: 'flac',
				bitrate: 0,
			},
			{
				label: __( 'WAV بدون اتلاف', 'music-wave-core' ),
				value: 'wav',
				key: 'wav',
				format: 'wav',
				bitrate: 0,
			},
			{
				label: __( 'بسته نرم افزاری ZIP', 'music-wave-core' ),
				value: 'zip',
				key: 'zip',
				format: 'zip',
				bitrate: 0,
			},
		];
	}

	function matchingQualityPreset( asset ) {
		var format = ( asset.format || '' ).toLowerCase();
		var bitrate = numericValue( asset.bitrate );
		var presets = qualityPresets().filter( function ( preset ) {
			return (
				preset.value &&
				preset.format === format &&
				preset.bitrate === bitrate
			);
		} );

		return presets.length ? presets[ 0 ].value : '';
	}

	function qualityFromAsset( asset, existingAssets ) {
		var format = (
			asset.format ||
			extensionFromAsset( asset ) ||
			'download'
		).toLowerCase();
		var bitrate = numericValue( asset.bitrate );
		var label =
			format === 'download'
				? __( 'دانلود', 'music-wave-core' )
				: format.toUpperCase();

		if ( bitrate ) {
			label += ' ' + bitrate + ' kbps';
		}

		return {
			key: uniqueQualityKey(
				format + ( bitrate ? '-' + bitrate : '' ),
				existingAssets
			),
			label,
			asset_id: asset.id || asset.asset_id || '',
			file_name: asset.name || asset.file_name || '',
			format: format === 'download' ? '' : format,
			bitrate,
			duration: numericValue( asset.duration ),
			file_size: numericValue( asset.file_size || asset.size ),
		};
	}

	function fileKeyFor( asset ) {
		return asset.file_key || 'main-download';
	}

	function fileLabelFor( asset ) {
		return asset.file_label || __( 'دانلود اصلی', 'music-wave-core' );
	}

	function fileTitleFromAsset( asset ) {
		var name = asset.file_name || asset.name || '';
		var title = name
			.replace( /\.[a-z0-9]+$/i, '' )
			.replace( /[_-]+/g, ' ' )
			.replace( /\s+/g, ' ' )
			.trim();
		return title || __( 'فایل دانلودی جدید', 'music-wave-core' );
	}

	function uniqueFileKey( base, assets ) {
		var key = normalizedKey( base );
		var candidate = key;
		var suffix = 2;
		var used = {};

		assets.forEach( function ( asset ) {
			used[ fileKeyFor( asset ) ] = true;
		} );
		while ( used[ candidate ] ) {
			candidate = key + '-' + suffix;
			suffix += 1;
		}

		return candidate;
	}

	function groupedDownloadFiles( assets ) {
		var groups = [];
		var indexes = {};

		assets.forEach( function ( asset, index ) {
			var key = fileKeyFor( asset );
			if ( typeof indexes[ key ] === 'undefined' ) {
				indexes[ key ] = groups.length;
				groups.push( {
					key,
					label: fileLabelFor( asset ),
					assets: [],
				} );
			}
			groups[ indexes[ key ] ].assets.push( {
				asset,
				index,
			} );
		} );

		return groups;
	}

	function updateNativeField( key, value ) {
		var input = document.getElementById( key );
		if ( input ) {
			input.value = value;
			input.dispatchEvent(
				new window.Event( 'change', { bubbles: true } )
			);
		}

		if ( wp.data && wp.data.select && wp.data.dispatch ) {
			var editor = wp.data.select( 'core/editor' );
			if ( ! editor || ! editor.getEditedPostAttribute ) {
				return;
			}
			var meta = editor.getEditedPostAttribute( 'meta' ) || {};
			var next = Object.assign( {}, meta );
			next[ key ] = value;
			wp.data.dispatch( 'core/editor' ).editPost( { meta: next } );
		}
	}

	function DeliveryManager( props ) {
		var releaseId = props.releaseId;
		var isCollection = props.isCollection;
		var privateAssetsState = useState( [] );
		var targetFileState = useState( '' );
		var assetSearchState = useState( '' );
		var assetsState = useState( [] );
		var assetPageState = useState( 1 );
		var assetHasMoreState = useState( false );
		var statusState = useState( '' );
		var privateAssets = privateAssetsState[ 0 ];
		var setPrivateAssets = privateAssetsState[ 1 ];
		var targetFileKey = targetFileState[ 0 ];
		var setTargetFileKey = targetFileState[ 1 ];
		var assetSearch = assetSearchState[ 0 ];
		var setAssetSearch = assetSearchState[ 1 ];
		var assets = assetsState[ 0 ];
		var setAssets = assetsState[ 1 ];
		var assetPage = assetPageState[ 0 ];
		var setAssetPage = assetPageState[ 1 ];
		var assetHasMore = assetHasMoreState[ 0 ];
		var setAssetHasMore = assetHasMoreState[ 1 ];
		var status = statusState[ 0 ];
		var setStatus = statusState[ 1 ];

		useEffect(
			function () {
				wp.apiFetch( {
					path:
						'/music-wave/v1/releases/' +
						releaseId +
						'/download-assets',
				} )
					.then( function ( response ) {
						setPrivateAssets( response.items || [] );
					} )
					.catch( function ( error ) {
						setStatus(
							error && error.message
								? error.message
								: __(
										'فایل‌های حفاظت‌شده در دسترس نیستند.',
										'music-wave-core'
								  )
						);
					} );
			},
			[ releaseId, setPrivateAssets, setStatus ]
		);

		function updateReleaseDuration( duration ) {
			var field = document.getElementById( 'mw_duration' );
			if ( ! duration || ( field && numericValue( field.value ) ) ) {
				return;
			}

			updateNativeField( 'mw_duration', duration );
			wp.apiFetch( {
				path: '/wp/v2/mw_release/' + releaseId,
				method: 'POST',
				data: { meta: { mw_duration: duration } },
			} )
				.then( function () {
					setStatus(
						__(
							'مدت زمان انتشار از فایل حفاظت‌شده پر شد. قبل از انتشار آن را مرور کنید.',
							'music-wave-core'
						)
					);
				} )
				.catch( function () {
					setStatus(
						__(
							'جزئیات فایل خوانده شد، اما مدت زمان انتشار به‌طور خودکار ذخیره نشد.',
							'music-wave-core'
						)
					);
				} );
		}

		function maybeUpdateReleaseDuration( nextAssets, duration ) {
			if (
				! isCollection &&
				groupedDownloadFiles( nextAssets ).length === 1
			) {
				updateReleaseDuration( duration );
			}
		}

		function addQualityFromAsset( asset, targetKey ) {
			var quality = qualityFromAsset( asset, privateAssets );
			var targetGroup = groupedDownloadFiles( privateAssets ).filter(
				function ( group ) {
					return group.key === targetKey;
				}
			)[ 0 ];
			if ( ! quality.asset_id ) {
				return;
			}
			if ( ! targetGroup ) {
				addDownloadFileFromAsset( asset );
				return;
			}

			quality.file_key = targetKey;
			quality.file_label = targetGroup.label;
			var nextAssets = privateAssets.concat( [ quality ] );
			setPrivateAssets( nextAssets );
			maybeUpdateReleaseDuration( nextAssets, quality.duration );
			setStatus(
				__(
					'کیفیتی به فایل قابل دانلود انتخابی اضافه شد. آن را مرور کنید، سپس همه تغییرات را ذخیره کنید.',
					'music-wave-core'
				)
			);
		}

		function addDownloadFileFromAsset( asset ) {
			var quality = qualityFromAsset( asset, privateAssets );
			if ( ! quality.asset_id ) {
				return;
			}

			quality.file_key = uniqueFileKey(
				fileTitleFromAsset( quality ),
				privateAssets
			);
			quality.file_label = fileTitleFromAsset( quality );
			var nextAssets = privateAssets.concat( [ quality ] );
			setPrivateAssets( nextAssets );
			setTargetFileKey( quality.file_key );
			maybeUpdateReleaseDuration( nextAssets, quality.duration );
			setStatus(
				__(
					'فایل قابل دانلود جدیدی اضافه شد. در صورت نیاز کیفیت‌های بیشتری را به آن اضافه کنید، سپس همه تغییرات را ذخیره کنید.',
					'music-wave-core'
				)
			);
		}

		function addSelectedAsset( asset ) {
			if ( targetFileKey ) {
				addQualityFromAsset( asset, targetFileKey );
				return;
			}

			addDownloadFileFromAsset( asset );
		}

		function addProviderAsset() {
			var number = privateAssets.length + 1;
			var fileKey =
				targetFileKey ||
				uniqueFileKey( 'download-file-' + number, privateAssets );
			var selectedGroup = targetFileKey
				? groupedDownloadFiles( privateAssets ).filter(
						function ( group ) {
							return group.key === targetFileKey;
						}
				  )[ 0 ]
				: null;
			var fileLabel = selectedGroup
				? selectedGroup.label
				: __( 'دانلود فایل', 'music-wave-core' ) + ' ' + number;
			if ( targetFileKey && ! selectedGroup ) {
				fileKey = uniqueFileKey(
					'download-file-' + number,
					privateAssets
				);
				setTargetFileKey( fileKey );
			}
			var nextAssets = privateAssets.concat( [
				{
					key: uniqueQualityKey(
						'download-' + number,
						privateAssets
					),
					label: __( 'دانلود', 'music-wave-core' ) + ' ' + number,
					asset_id: '',
					file_name: '',
					file_key: fileKey,
					file_label: fileLabel,
					format: '',
					bitrate: 0,
					duration: 0,
					file_size: 0,
				},
			] );
			setPrivateAssets( nextAssets );
			setTargetFileKey( fileKey );
			setStatus(
				targetFileKey
					? __(
							'یک دارایی ارائه‌دهنده امن ID برای کیفیت این فایل وارد کنید.',
							'music-wave-core'
					  )
					: __(
							'یک دارایی ارائه‌دهنده امن ID برای فایل قابل دانلود جدید وارد کنید.',
							'music-wave-core'
					  )
			);
		}

		function uploadAsset( event ) {
			var files = event.target.files;
			if ( ! files || ! files.length ) {
				return;
			}

			var formData = new window.FormData();
			formData.append( 'file', files[ 0 ] );
			setStatus(
				__(
					'آپلود فایل حفاظت‌شده و خواندن فرادادهٔ آن…',
					'music-wave-core'
				)
			);
			wp.apiFetch( {
				path: '/music-wave/v1/protected-assets',
				method: 'POST',
				body: formData,
			} )
				.then( function ( response ) {
					if ( response.asset && response.asset.id ) {
						addSelectedAsset( response.asset );
						setAssets( [ response.asset ].concat( assets ) );
					}
				} )
				.catch( function ( error ) {
					setStatus(
						error && error.message
							? error.message
							: __(
									'فایل حفاظت‌شده آپلود نشد.',
									'music-wave-core'
							  )
					);
				} );
			event.target.value = '';
		}

		function selectMediaLibraryAsset() {
			if ( ! wp.media ) {
				setStatus(
					__(
						'کتابخانهٔ رسانه WordPress در این صفحه در دسترس نیست.',
						'music-wave-core'
					)
				);
				return;
			}

			var frame = wp.media( {
				title: __(
					'یک فایل صوتی را از کتابخانهٔ رسانه انتخاب کنید',
					'music-wave-core'
				),
				button: {
					text: __(
						'کپی در ذخیره‌سازی حفاظت‌شده',
						'music-wave-core'
					),
				},
				library: { type: 'audio' },
				multiple: false,
			} );

			frame.on( 'select', function () {
				var attachment = frame
					.state()
					.get( 'selection' )
					.first()
					.toJSON();
				if ( ! attachment || ! attachment.id ) {
					return;
				}

				setStatus(
					__(
						'در حال کپی کردن فایل کتابخانهٔ رسانه در فضای ذخیره‌سازی حفاظت‌شده…',
						'music-wave-core'
					)
				);
				wp.apiFetch( {
					path: '/music-wave/v1/protected-assets/import',
					method: 'POST',
					data: { attachment_id: attachment.id },
				} )
					.then( function ( response ) {
						if ( response.asset && response.asset.id ) {
							addSelectedAsset( response.asset );
							setAssets( [ response.asset ].concat( assets ) );
						}
					} )
					.catch( function ( error ) {
						setStatus(
							error && error.message
								? error.message
								: __(
										'فایل کتابخانهٔ رسانه را نمی‌توان در فضای ذخیره‌سازی حفاظت‌شده کپی کرد.',
										'music-wave-core'
								  )
						);
					} );
			} );
			frame.open();
		}

		function loadAssets( loadMore ) {
			var page = loadMore ? assetPage + 1 : 1;
			var path =
				'/music-wave/v1/protected-assets?page=' + page + '&per_page=50';
			if ( assetSearch.trim() ) {
				path += '&search=' + encodeURIComponent( assetSearch.trim() );
			}

			setStatus( '' );
			wp.apiFetch( { path } )
				.then( function ( response ) {
					var items = response.items || [];
					setAssets( loadMore ? assets.concat( items ) : items );
					setAssetPage( page );
					setAssetHasMore( !! response.has_more );
				} )
				.catch( function ( error ) {
					setStatus(
						error && error.message
							? error.message
							: __(
									'فایل‌های حفاظت‌شده در دسترس نیستند.',
									'music-wave-core'
							  )
					);
				} );
		}

		function setQualityValue( index, key, value ) {
			var next = privateAssets.slice();
			next[ index ] = Object.assign( {}, next[ index ], {} );
			next[ index ][ key ] = value;
			setPrivateAssets( next );
		}

		function setDownloadFileLabel( fileKey, value ) {
			var label =
				value || __( 'فایل دانلودی بدون عنوان', 'music-wave-core' );
			var next = privateAssets.map( function ( asset ) {
				if ( fileKeyFor( asset ) !== fileKey ) {
					return asset;
				}
				return Object.assign( {}, asset, {
					file_key: fileKey,
					file_label: label,
				} );
			} );
			setPrivateAssets( next );
		}

		function applyPreset( index, presetValue ) {
			var presets = qualityPresets().filter( function ( preset ) {
				return preset.value === presetValue;
			} );
			if ( ! presets.length || ! presets[ 0 ].value ) {
				return;
			}

			var preset = presets[ 0 ];
			var next = privateAssets.slice();
			var others = next.filter( function ( _, itemIndex ) {
				return itemIndex !== index;
			} );
			next[ index ] = Object.assign( {}, next[ index ], {
				key: uniqueQualityKey( preset.key, others ),
				label: preset.label,
				format: preset.format,
				bitrate: preset.bitrate,
			} );
			setPrivateAssets( next );
		}

		function removeQuality( index ) {
			var next = privateAssets.filter( function ( _, itemIndex ) {
				return itemIndex !== index;
			} );
			setPrivateAssets( next );
			if (
				targetFileKey &&
				! next.some( function ( asset ) {
					return fileKeyFor( asset ) === targetFileKey;
				} )
			) {
				setTargetFileKey( '' );
			}
		}

		function removeDownloadFile( fileKey ) {
			var next = privateAssets.filter( function ( asset ) {
				return fileKeyFor( asset ) !== fileKey;
			} );
			setPrivateAssets( next );
			if ( targetFileKey === fileKey ) {
				setTargetFileKey( '' );
			}
		}

		function validQualities() {
			var keys = {};
			return ! privateAssets.some( function ( asset ) {
				var key = normalizedKey( asset.key );
				if (
					! asset.key ||
					! asset.label ||
					! asset.asset_id ||
					keys[ key ] ||
					/^https?:\/\//i.test( asset.asset_id )
				) {
					return true;
				}
				keys[ key ] = true;
				return false;
			} );
		}

		function normalizedDownloadFiles() {
			return privateAssets.map( function ( asset ) {
				return Object.assign( {}, asset, {
					file_key: fileKeyFor( asset ),
					file_label: fileLabelFor( asset ),
				} );
			} );
		}

		function saveQualities() {
			if ( ! validQualities() ) {
				setStatus(
					__(
						'هر کیفیت به یک کلید فنی منحصر به فرد، برچسب مشتری و دارایی ایمن غیر عمومی ID نیاز دارد.',
						'music-wave-core'
					)
				);
				return;
			}

			setStatus(
				__(
					'ذخیره فایل‌های حفاظت‌شده و کیفیت دانلود…',
					'music-wave-core'
				)
			);
			wp.apiFetch( {
				path:
					'/music-wave/v1/releases/' + releaseId + '/download-assets',
				method: 'POST',
				data: { assets: normalizedDownloadFiles() },
			} )
				.then( function ( response ) {
					setPrivateAssets( response.items || [] );
					setStatus(
						__(
							'فایل‌های حفاظت‌شده و کیفیت دانلود ذخیره شدند.',
							'music-wave-core'
						)
					);
				} )
				.catch( function ( error ) {
					setStatus(
						error && error.message
							? error.message
							: __(
									'فایل‌های حفاظت‌شده ذخیره نمی‌شوند.',
									'music-wave-core'
							  )
					);
				} );
		}

		function qualityRow( asset, index ) {
			return el(
				'div',
				{
					className: 'mw-editor-quality',
					key: asset.key + '-' + index,
				},
				el(
					'div',
					{ className: 'mw-editor-quality__heading' },
					el(
						'strong',
						{},
						asset.label ||
							__( 'کیفیت دانلود جدید', 'music-wave-core' )
					),
					el(
						Button,
						{
							isDestructive: true,
							isSmall: true,
							onClick() {
								removeQuality( index );
							},
						},
						__( 'حذف', 'music-wave-core' )
					)
				),
				el( SelectControl, {
					label: __( 'کیفیت از پیش تعیین شده', 'music-wave-core' ),
					help: __(
						'کلید فنی، برچسب مشتری، قالب و میزان بیت را پر می‌کند.',
						'music-wave-core'
					),
					value: matchingQualityPreset( asset ),
					options: qualityPresets().map( function ( preset ) {
						return { label: preset.label, value: preset.value };
					} ),
					onChange( value ) {
						applyPreset( index, value );
					},
				} ),
				el(
					'div',
					{ className: 'mw-editor-grid' },
					el( TextControl, {
						label: __( 'کلید فنی', 'music-wave-core' ),
						value: asset.key || '',
						onChange( value ) {
							setQualityValue( index, 'key', value );
						},
					} ),
					el( TextControl, {
						label: __( 'برچسب مشتری', 'music-wave-core' ),
						value: asset.label || '',
						onChange( value ) {
							setQualityValue( index, 'label', value );
						},
					} ),
					el( TextControl, {
						label: __( 'قالب', 'music-wave-core' ),
						value: asset.format || '',
						onChange( value ) {
							setQualityValue( index, 'format', value );
						},
					} ),
					el( TextControl, {
						label: __(
							'نرخ بیت (کیلوبیت بر ثانیه)',
							'music-wave-core'
						),
						type: 'number',
						min: 0,
						value: asset.bitrate || '',
						onChange( value ) {
							setQualityValue(
								index,
								'bitrate',
								numericValue( value )
							);
						},
					} ),
					el( TextControl, {
						label: __(
							'مدت زمان فایل بر حسب ثانیه',
							'music-wave-core'
						),
						type: 'number',
						min: 0,
						value: asset.duration || '',
						onChange( value ) {
							setQualityValue(
								index,
								'duration',
								numericValue( value )
							);
						},
					} ),
					el( TextControl, {
						label: __(
							'اندازه فایل بر حسب بایت',
							'music-wave-core'
						),
						type: 'number',
						min: 0,
						value: asset.file_size || '',
						onChange( value ) {
							setQualityValue(
								index,
								'file_size',
								numericValue( value )
							);
						},
					} )
				),
				el( TextControl, {
					label: __(
						'دارایی ارائه‌دهنده حفاظت‌شده ID',
						'music-wave-core'
					),
					help: __(
						'از یک شناسهٔ غیرشفاف مانند local:album/track.flac استفاده کنید. نشانی‌های عمومی مجاز نیستند.',
						'music-wave-core'
					),
					value: asset.asset_id || '',
					onChange( value ) {
						setQualityValue( index, 'asset_id', value );
					},
				} ),
				el( TextControl, {
					label: __( 'نام فایل ذخیره‌شده', 'music-wave-core' ),
					help: qualitySummary( asset )
						? __( 'شناسایی شد:', 'music-wave-core' ) +
						  ' ' +
						  qualitySummary( asset )
						: __( 'فقط مرجع ویرایشگر خصوصی', 'music-wave-core' ),
					value: asset.file_name || '',
					onChange( value ) {
						setQualityValue( index, 'file_name', value );
					},
				} ),
				numericValue( asset.duration )
					? el(
							Button,
							{
								variant: 'tertiary',
								onClick() {
									updateNativeField(
										'mw_duration',
										numericValue( asset.duration )
									);
									setStatus(
										__(
											'مدت زمان انتشار از این فایل تنظیم شد.',
											'music-wave-core'
										)
									);
								},
							},
							__(
								'استفاده از این فایل مدت زمان',
								'music-wave-core'
							)
					  )
					: null
			);
		}

		function downloadFileGroup( group ) {
			return el(
				'section',
				{ className: 'mw-editor-download-file', key: group.key },
				el(
					'div',
					{ className: 'mw-editor-download-file__heading' },
					el(
						'div',
						{},
						el( 'strong', {}, group.label ),
						el(
							'span',
							{ className: 'description' },
							group.assets.length === 1
								? __( '1 دانلود با کیفیت', 'music-wave-core' )
								: group.assets.length +
										' ' +
										__(
											'کیفیت‌های دانلود',
											'music-wave-core'
										)
						)
					),
					el(
						Button,
						{
							isDestructive: true,
							isSmall: true,
							onClick() {
								removeDownloadFile( group.key );
							},
						},
						__( 'حذف فایل', 'music-wave-core' )
					)
				),
				el( TextControl, {
					label: __( 'عنوان فایل قابل دانلود', 'music-wave-core' ),
					help: __(
						'این عنوان همه نسخه‌های یک آهنگ، قسمت یا فایل جایزه را گروه‌بندی می‌کند.',
						'music-wave-core'
					),
					value: group.label,
					onChange( value ) {
						setDownloadFileLabel( group.key, value );
					},
				} ),
				group.assets.map( function ( entry ) {
					return qualityRow( entry.asset, entry.index );
				} )
			);
		}

		return el(
			'section',
			{ className: 'mw-release-manager' },
			el( 'h2', {}, __( 'فایل‌ها و کیفیت دانلود', 'music-wave-core' ) ),
			el(
				'p',
				{ className: 'description' },
				__(
					'برای هر آهنگ، قسمت یا بسته یک فایل قابل دانلود ایجاد کنید. سپس تمام کیفیت‌های موجود را به آن فایل اضافه کنید. این کار آلبوم‌ها و پادکست‌ها را بدون آپلود مجدد همان صدا سازماندهی می‌کند.',
					'music-wave-core'
				)
			),
			groupedDownloadFiles( privateAssets ).map( downloadFileGroup ),
			el(
				'div',
				{ className: 'mw-editor-actions' },
				privateAssets.length
					? el( SelectControl, {
							label: __(
								'افزودن فایل بعدی به',
								'music-wave-core'
							),
							value: targetFileKey,
							options: [
								{
									label: __(
										'یک فایل قابل دانلود جدید ایجاد کنید',
										'music-wave-core'
									),
									value: '',
								},
							].concat(
								groupedDownloadFiles( privateAssets ).map(
									function ( group ) {
										return {
											label: group.label,
											value: group.key,
										};
									}
								)
							),
							onChange: setTargetFileKey,
					  } )
					: null,
				el(
					Button,
					{ variant: 'secondary', onClick: addProviderAsset },
					targetFileKey
						? __( 'افزودن کیفیت دستی', 'music-wave-core' )
						: __(
								'افزودن فایل قابل دانلود دستی',
								'music-wave-core'
						  )
				),
				el(
					'label',
					{
						className:
							'components-button is-secondary mw-editor-upload',
					},
					targetFileKey
						? __( 'بارگذاری یک کیفیت', 'music-wave-core' )
						: __( 'آپلود فایل جدید', 'music-wave-core' ),
					el( 'input', {
						type: 'file',
						accept: '.mp3,.m4a,.aac,.ogg,.wav,.flac,.zip',
						onChange: uploadAsset,
					} )
				),
				el(
					Button,
					{ variant: 'secondary', onClick: selectMediaLibraryAsset },
					targetFileKey
						? __(
								'یک کیفیت را از رسانه‌ها انتخاب کنید',
								'music-wave-core'
						  )
						: __(
								'فایلی را از کتابخانهٔ رسانه انتخاب کنید',
								'music-wave-core'
						  )
				),
				el(
					Button,
					{ variant: 'primary', onClick: saveQualities },
					__( 'ذخیره فایل‌های دانلود', 'music-wave-core' )
				)
			),
			el(
				'div',
				{ className: 'mw-editor-browser' },
				el( TextControl, {
					label: __(
						'فایل‌هایی را که از قبل در فضای ذخیره‌سازی حفاظت‌شده هستند پیدا کنید',
						'music-wave-core'
					),
					value: assetSearch,
					onChange: setAssetSearch,
				} ),
				el(
					Button,
					{
						variant: 'secondary',
						onClick() {
							loadAssets( false );
						},
					},
					__( 'مرور فایل‌های حفاظت‌شده', 'music-wave-core' )
				)
			),
			assets.map( function ( asset ) {
				return el(
					'div',
					{ className: 'mw-editor-stored-asset', key: asset.id },
					el(
						'div',
						{},
						el( 'strong', {}, asset.name || asset.id ),
						qualitySummary( asset )
							? el(
									'span',
									{ className: 'description' },
									qualitySummary( asset )
							  )
							: null
					),
					el(
						Button,
						{
							variant: 'secondary',
							onClick() {
								addDownloadFileFromAsset( asset );
							},
						},
						__(
							'افزودن به عنوان فایل دانلودی جدید',
							'music-wave-core'
						)
					),
					privateAssets.length
						? el( SelectControl, {
								label: __(
									'استفاده به عنوان کیفیت برای',
									'music-wave-core'
								),
								hideLabelFromVision: true,
								value: '',
								options: [
									{
										label: __(
											'افزودن به عنوان کیفیت برای…',
											'music-wave-core'
										),
										value: '',
									},
								].concat(
									groupedDownloadFiles( privateAssets ).map(
										function ( group ) {
											return {
												label: group.label,
												value: group.key,
											};
										}
									)
								),
								onChange( value ) {
									if ( value !== '' ) {
										addQualityFromAsset( asset, value );
									}
								},
						  } )
						: null
				);
			} ),
			assetHasMore
				? el(
						Button,
						{
							variant: 'tertiary',
							onClick() {
								loadAssets( true );
							},
						},
						__( 'بارگذاری فایل‌های بیشتر', 'music-wave-core' )
				  )
				: null,
			status
				? el( Notice, { status: 'info', isDismissible: false }, status )
				: null
		);
	}

	function CollectionManager( props ) {
		var releaseId = props.releaseId;
		var role = props.role;
		var searchState = useState( '' );
		var resultsState = useState( [] );
		var itemsState = useState( [] );
		var titlesState = useState( {} );
		var statusState = useState( '' );
		var search = searchState[ 0 ];
		var setSearch = searchState[ 1 ];
		var results = resultsState[ 0 ];
		var setResults = resultsState[ 1 ];
		var items = itemsState[ 0 ];
		var setItems = itemsState[ 1 ];
		var titles = titlesState[ 0 ];
		var setTitles = titlesState[ 1 ];
		var status = statusState[ 0 ];
		var setStatus = statusState[ 1 ];
		var itemLabel =
			role === 'episode'
				? __( 'اپیزود', 'music-wave-core' )
				: __( 'قطعه / تک', 'music-wave-core' );

		useEffect(
			function () {
				wp.apiFetch( {
					path: '/wp/v2/mw_release/' + releaseId + '?context=edit',
				} )
					.then( function ( release ) {
						var saved =
							release.meta &&
							Array.isArray( release.meta.mw_collection_items )
								? release.meta.mw_collection_items
								: [];
						setItems( saved );
					} )
					.catch( function () {
						setStatus(
							__( 'موارد مجموعه بارگیری نشد.', 'music-wave-core' )
						);
					} );
			},
			[ releaseId, setItems, setStatus ]
		);

		useEffect(
			function () {
				var ids = items
					.map( function ( item ) {
						return item.release_id;
					} )
					.filter( function ( id ) {
						return ! titles[ id ];
					} );
				if ( ! ids.length ) {
					return;
				}

				wp.apiFetch( {
					path:
						'/wp/v2/mw_release?context=edit&include=' +
						ids.join( ',' ) +
						'&per_page=100',
				} ).then( function ( releases ) {
					var next = Object.assign( {}, titles );
					releases.forEach( function ( release ) {
						next[ release.id ] =
							release.title && release.title.rendered
								? release.title.rendered
								: '#' + release.id;
					} );
					setTitles( next );
				} );
			},
			[ items, setTitles, titles ]
		);

		useEffect(
			function () {
				var query = search.trim();
				if ( query.length < 2 ) {
					setResults( [] );
					return undefined;
				}

				var timer = window.setTimeout( searchCandidates, 250 );
				return function () {
					window.clearTimeout( timer );
				};
			},
			[ search, searchCandidates, setResults ]
		);

		function normalize( itemsToSave ) {
			return itemsToSave.map( function ( item, index ) {
				return {
					release_id: item.release_id,
					position: index + 1,
					disc: item.disc || null,
					role,
				};
			} );
		}

		function saveItems( itemsToSave ) {
			var normalized = normalize( itemsToSave );
			setStatus( __( 'ذخیره اقلام مجموعه…', 'music-wave-core' ) );
			wp.apiFetch( {
				path: '/wp/v2/mw_release/' + releaseId,
				method: 'POST',
				data: { meta: { mw_collection_items: normalized } },
			} )
				.then( function ( release ) {
					var saved =
						release.meta &&
						Array.isArray( release.meta.mw_collection_items )
							? release.meta.mw_collection_items
							: normalized;
					setItems( saved );
					setStatus(
						__( 'موارد مجموعه ذخیره شد.', 'music-wave-core' )
					);
				} )
				.catch( function ( error ) {
					setStatus(
						error && error.message
							? error.message
							: __(
									'موارد مجموعه را نمی‌توان ذخیره کرد.',
									'music-wave-core'
							  )
					);
				} );
		}

		var searchCandidates = useCallback(
			function () {
				if ( search.trim().length < 2 ) {
					setResults( [] );
					return;
				}

				wp.apiFetch( {
					path:
						'/music-wave/v1/collection-candidates?collection_id=' +
						releaseId +
						'&role=' +
						role +
						'&search=' +
						encodeURIComponent( search.trim() ),
				} )
					.then( function ( response ) {
						setResults( response.items || [] );
					} )
					.catch( function ( error ) {
						setResults( [] );
						setStatus(
							error && error.message
								? error.message
								: __(
										'هیچ انتشار سازگاری یافت نشد.',
										'music-wave-core'
								  )
						);
					} );
			},
			[ search, releaseId, role, setResults, setStatus ]
		);

		function addItem( item ) {
			if (
				items.some( function ( existing ) {
					return existing.release_id === item.id;
				} )
			) {
				return;
			}

			var nextTitles = Object.assign( {}, titles );
			nextTitles[ item.id ] = item.title;
			setTitles( nextTitles );
			saveItems(
				items.concat( [ { release_id: item.id, role, disc: null } ] )
			);
		}

		function move( index, offset ) {
			var next = items.slice();
			var target = index + offset;
			if ( target < 0 || target >= next.length ) {
				return;
			}

			var moved = next[ index ];
			next[ index ] = next[ target ];
			next[ target ] = moved;
			saveItems( next );
		}

		return el(
			'section',
			{ className: 'mw-release-manager mw-release-manager--collection' },
			el(
				'h2',
				{},
				role === 'episode'
					? __( 'قسمت‌های پادکست', 'music-wave-core' )
					: __( 'قطعه و تک قطعه', 'music-wave-core' )
			),
			el(
				'p',
				{ className: 'description' },
				__(
					'جست‌وجو بر اساس عنوان انتشار یا نام فایل حفاظت‌شده که قبلاً به یک قطعه، تک قطعه یا قسمت متصل شده است. برای استفادهٔ مجدد از پیش‌نمایش و دانلودهای امن، آن را یک‌بار اضافه کنید.',
					'music-wave-core'
				)
			),
			el(
				'div',
				{ className: 'mw-editor-browser' },
				el( TextControl, {
					label: __(
						'جست‌وجوی انتشار یا نام فایل',
						'music-wave-core'
					),
					help: __(
						'نتایج هنگام تایپ به‌روز می‌شوند. حداقل 2 کاراکتر وارد کنید.',
						'music-wave-core'
					),
					value: search,
					onChange: setSearch,
					onKeyDown( event ) {
						if ( 'Enter' === event.key ) {
							event.preventDefault();
							searchCandidates();
						}
					},
				} ),
				el(
					Button,
					{ variant: 'secondary', onClick: searchCandidates },
					__( 'جست‌وجو', 'music-wave-core' )
				)
			),
			results.map( function ( item ) {
				var details = [];
				if ( item.number ) {
					details.push( itemLabel + ' ' + item.number );
				}
				if ( item.duration ) {
					details.push( humanDuration( item.duration ) );
				}
				if ( item.file_names && item.file_names.length ) {
					details.push(
						__( 'فایل:', 'music-wave-core' ) +
							' ' +
							item.file_names.join( ', ' )
					);
				} else if ( item.matched_post ) {
					details.push(
						__( 'عنوان انتشار همسان', 'music-wave-core' )
					);
				}
				return el(
					'div',
					{ className: 'mw-editor-stored-asset', key: item.id },
					el(
						'div',
						{},
						el( 'strong', {}, item.title ),
						details.length
							? el(
									'span',
									{ className: 'description' },
									details.join( ' · ' )
							  )
							: null
					),
					el(
						Button,
						{
							variant: 'secondary',
							disabled: items.some( function ( existing ) {
								return existing.release_id === item.id;
							} ),
							onClick() {
								addItem( item );
							},
						},
						__( 'افزودن به مجموعه', 'music-wave-core' )
					)
				);
			} ),
			items.length
				? el(
						'ol',
						{ className: 'mw-editor-collection-list' },
						items.map( function ( item, index ) {
							return el(
								'li',
								{ key: item.release_id },
								el(
									'span',
									{},
									index +
										1 +
										'. ' +
										( titles[ item.release_id ] ||
											'#' + item.release_id )
								),
								el(
									'span',
									{
										className:
											'mw-editor-collection-list__actions',
									},
									el(
										Button,
										{
											isSmall: true,
											disabled: index === 0,
											onClick() {
												move( index, -1 );
											},
										},
										'↑'
									),
									el(
										Button,
										{
											isSmall: true,
											disabled:
												index === items.length - 1,
											onClick() {
												move( index, 1 );
											},
										},
										'↓'
									),
									el(
										Button,
										{
											isSmall: true,
											isDestructive: true,
											onClick() {
												saveItems(
													items.filter(
														function (
															_,
															itemIndex
														) {
															return (
																itemIndex !==
																index
															);
														}
													)
												);
											},
										},
										__( 'حذف', 'music-wave-core' )
									)
								)
							);
						} )
				  )
				: null,
			status
				? el( Notice, { status: 'info', isDismissible: false }, status )
				: null
		);
	}

	function mountManagers() {
		var deliveryRoot = document.getElementById(
			'music-wave-delivery-manager'
		);
		if ( deliveryRoot ) {
			render(
				el( DeliveryManager, {
					releaseId: numericValue(
						deliveryRoot.getAttribute( 'data-release-id' )
					),
					isCollection:
						deliveryRoot.getAttribute( 'data-is-collection' ) ===
						'1',
				} ),
				deliveryRoot
			);
		}

		var collectionRoot = document.getElementById(
			'music-wave-collection-manager'
		);
		if ( collectionRoot ) {
			render(
				el( CollectionManager, {
					releaseId: numericValue(
						collectionRoot.getAttribute( 'data-release-id' )
					),
					role: collectionRoot.getAttribute( 'data-role' ) || 'track',
				} ),
				collectionRoot
			);
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', mountManagers );
	} else {
		mountManagers();
	}
} )( window.wp, document );
