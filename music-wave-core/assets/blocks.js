/**
 * Editor counterparts for the PHP-rendered MusicWave blocks.
 *
 * @package
 */
( function (
	blocks,
	element,
	blockEditor,
	components,
	i18n,
	serverSideRender,
	dynamicBlocks
) {
	'use strict';

	if (
		! blocks ||
		! element ||
		! blockEditor ||
		! components ||
		! i18n ||
		! serverSideRender ||
		! dynamicBlocks
	) {
		return;
	}

	var createElement = element.createElement;
	var Fragment = element.Fragment;
	var useState =
		'function' === typeof element.useState
			? element.useState
			: function ( initial ) {
					return [ initial, function () {} ];
			  };
	var useEffect =
		'function' === typeof element.useEffect
			? element.useEffect
			: function () {};

	// Shared editor sample preview store across Core & Theme blocks so choosing
	// a sample release/album once updates the entire template canvas in unison.
	var WP_RESERVED_SUPPORT_ATTRS = {
		style: true,
		className: true,
		align: true,
		backgroundColor: true,
		textColor: true,
		gradient: true,
		fontSize: true,
		fontFamily: true,
		borderColor: true,
		anchor: true,
		metadata: true,
		lock: true,
	};

	function sharedPreviewStore() {
		if ( ! window.__mwPreviewSampleStore ) {
			window.__mwPreviewSampleStore = {
				release: '',
				artist: '',
				term: '',
				playlist: '',
				query: '',
			};
		}
		return window.__mwPreviewSampleStore;
	}

	function previewChannelFor( config ) {
		if ( config.previewArtist ) {
			return 'artist';
		}
		if ( config.previewTerm ) {
			return 'term';
		}
		if ( config.previewPlaylist ) {
			return 'playlist';
		}
		if ( config.previewQuery ) {
			return 'query';
		}
		return 'release';
	}

	function useSharedPreviewSample( channel ) {
		var store = sharedPreviewStore();
		var pair = useState( store[ channel ] || '' );
		var value = pair[ 0 ];
		var setValue = pair[ 1 ];

		useEffect(
			function () {
				function onSync( event ) {
					var detail = ( event && event.detail ) || {};
					if ( detail.channel === channel ) {
						setValue( detail.value || '' );
					}
				}
				if ( window.addEventListener ) {
					window.addEventListener(
						'mw:preview-sample-change',
						onSync
					);
				}
				return function () {
					if ( window.removeEventListener ) {
						window.removeEventListener(
							'mw:preview-sample-change',
							onSync
						);
					}
				};
			},
			[ channel, setValue ]
		);

		function updateSample( next ) {
			var normalized = String( next || '' );
			store[ channel ] = normalized;
			setValue( normalized );
			if ( window.dispatchEvent && 'function' === typeof CustomEvent ) {
				window.dispatchEvent(
					new CustomEvent( 'mw:preview-sample-change', {
						detail: { channel, value: normalized },
					} )
				);
			}
		}

		return [ value, updateSample ];
	}
	var __ = i18n.__;
	// Always-callable data selector: on legacy WordPress builds without the
	// data store the callback runs with a null select and helpers degrade to
	// their defaults, while hooks keep an unconditional call order.
	var useSelect =
		window.wp && window.wp.data && window.wp.data.useSelect
			? window.wp.data.useSelect
			: function ( callback ) {
					return callback( null );
			  };

	var ACTION_BUTTON_STYLE_OPTIONS = [
		[ 'icon', __( 'فقط آیکون', 'music-wave-core' ) ],
		[ 'button', __( 'دکمه کامل با متن', 'music-wave-core' ) ],
	];

	var ACTION_BUTTON_VARIANT_OPTIONS = [
		[ 'accent', __( 'تأکیدی توپر (اصلی)', 'music-wave-core' ) ],
		[ 'surface', __( 'سطحی هماهنگ با پوسته', 'music-wave-core' ) ],
		[ 'outline', __( 'حاشیه‌دار (خطی)', 'music-wave-core' ) ],
		[ 'ghost', __( 'مینیمال بی‌زمینه', 'music-wave-core' ) ],
	];

	var ACTION_BUTTON_SIZE_OPTIONS = [
		[ 'sm', __( 'کوچک و جمع‌وجور (SM)', 'music-wave-core' ) ],
		[ 'md', __( 'استاندارد متعادل (MD)', 'music-wave-core' ) ],
		[ 'lg', __( 'درشت و شاخص (LG)', 'music-wave-core' ) ],
	];

	var ACTION_BUTTON_SHAPE_OPTIONS = [
		[ 'pill', __( 'کپسولی / کاملاً گرد', 'music-wave-core' ) ],
		[ 'rounded', __( 'گوشه‌گرد مدرن', 'music-wave-core' ) ],
	];

	function actionButtonAppearanceGroup( defaultHelp, defaultIcon ) {
		return {
			title: __( 'سبک، اندازه و آیکون دکمه', 'music-wave-core' ),
			controls: [
				[
					'select',
					'buttonStyle',
					__( 'حالت نمایش', 'music-wave-core' ),
					ACTION_BUTTON_STYLE_OPTIONS,
					__(
						'«فقط آیکون» دکمه جمع‌وجور بدون متن و «دکمه کامل با متن» برچسب را کنار نماد نمایش می‌دهد.',
						'music-wave-core'
					),
				],
				[
					'select',
					'variant',
					__( 'سبک رنگی دکمه', 'music-wave-core' ),
					ACTION_BUTTON_VARIANT_OPTIONS,
				],
				[
					'select',
					'size',
					__( 'اندازه دکمه', 'music-wave-core' ),
					ACTION_BUTTON_SIZE_OPTIONS,
				],
				[
					'select',
					'shape',
					__( 'فرم گوشه‌ها', 'music-wave-core' ),
					ACTION_BUTTON_SHAPE_OPTIONS,
				],
				[
					'customIcon',
					'icon',
					__( 'آیکون سفارشی (اختیاری)', 'music-wave-core' ),
					defaultIcon || 'play-filled',
				],
				[
					'text',
					'label',
					__( 'متن سفارشی دکمه', 'music-wave-core' ),
					'',
					defaultHelp ||
						__(
							'برای استفاده از متن پیش‌فرض ترجمه‌شده خالی بگذارید.',
							'music-wave-core'
						),
				],
			],
		};
	}

	var fieldConfig = {
		'music-wave/release-meta': {
			releaseId: true,
			compact: true,
			compactDefault: true,
			groups: [
				{
					title: __( 'فیلدها', 'music-wave-core' ),
					controls: [
						[
							'toggle',
							'showCatalogNumber',
							__( 'نمایش شماره کاتالوگ', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showReleaseDate',
							__( 'نمایش تاریخ انتشار', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showDuration',
							__( 'نمایش مدت زمان', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showBpm',
							__( 'نمایش تمپو (BPM)', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showKey',
							__( 'نمایش کلید موسیقی', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showArtist',
							__( 'نمایش هنرمند', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showGenre',
							__( 'نمایش ژانر', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showMood',
							__( 'نمایش حال و هوا', 'music-wave-core' ),
							false,
						],
						[
							'toggle',
							'showLabel',
							__( 'نمایش برچسب ناشر', 'music-wave-core' ),
							false,
						],
						[
							'toggle',
							'showReleaseType',
							__( 'نمایش نوع انتشار', 'music-wave-core' ),
							false,
						],
						[
							'toggle',
							'fullSpecs',
							__(
								'نمایش همهٔ جزئیات انتشار MusicWave',
								'music-wave-core'
							),
							false,
						],
					],
					help: __(
						'در حالت پیش‌فرض (فشرده)، مشخصات بدون کادر و با جداکننده نقطه (·) کنار هم قرار می‌گیرند. طبقه‌بندی‌ها به‌طور خودکار پیوند می‌شوند و فیلدهای خالی پنهان می‌مانند.',
						'music-wave-core'
					),
				},
				{
					title: __( 'چیدمان و برچسب‌ها', 'music-wave-core' ),
					controls: [
						[
							'select',
							'layout',
							__(
								'چیدمان فراداده (حالت غیرفشرده)',
								'music-wave-core'
							),
							[
								[
									'grid',
									__(
										'شبکه (کارت‌های برچسب و مقدار)',
										'music-wave-core'
									),
								],
								[
									'inline',
									__(
										'خطی (یک ردیف روان)',
										'music-wave-core'
									),
								],
								[
									'stack',
									__(
										'ردیف‌های پشته‌ای (برچسب در کنار مقدار)',
										'music-wave-core'
									),
								],
							],
						],
						[
							'toggle',
							'showLabels',
							__( 'نمایش برچسب‌های فیلد', 'music-wave-core' ),
							true,
							__(
								'برچسب‌های پنهان همچنان برای صفحه‌خوان‌ها قابل دسترس می‌مانند.',
								'music-wave-core'
							),
						],
					],
				},
			],
		},
		'music-wave/access-panel': {
			releaseId: true,
			groups: [
				{
					title: __( 'نمایش', 'music-wave-core' ),
					controls: [
						[
							'select',
							'layout',
							__( 'چیدمان پنل', 'music-wave-core' ),
							[
								[
									'banner',
									__(
										'بنر (پیام و اقدام در یک ردیف)',
										'music-wave-core'
									),
								],
								[
									'stack',
									__(
										'روی‌هم‌چیده (اقدام زیر پیام)',
										'music-wave-core'
									),
								],
							],
						],
						[
							'toggle',
							'showWhenGranted',
							__(
								'نمایش وضعیت دسترسی اعطاشده',
								'music-wave-core'
							),
							true,
							__(
								'وقتی خاموش است، بلوک برای بازدیدکنندگانی که از قبل دسترسی دارند پنهان می‌ماند.',
								'music-wave-core'
							),
						],
					],
				},
				{
					title: __( 'جایگزینی پیام‌ها', 'music-wave-core' ),
					controls: [
						[
							'text',
							'grantedMessage',
							__( 'پیام دسترسی اعطاشده', 'music-wave-core' ),
							'',
						],
						[
							'text',
							'restrictedMessage',
							__( 'پیام دسترسی محدودشده', 'music-wave-core' ),
							'',
						],
						[
							'text',
							'purchaseMessage',
							__( 'پیام خرید', 'music-wave-core' ),
							'',
						],
						[
							'text',
							'purchaseCtaLabel',
							__( 'برچسب دکمه خرید', 'music-wave-core' ),
							'',
						],
						[
							'text',
							'membershipMessage',
							__( 'پیام عضویت', 'music-wave-core' ),
							'',
						],
						[
							'text',
							'membershipCtaLabel',
							__( 'برچسب دکمه عضویت', 'music-wave-core' ),
							'',
						],
						[
							'text',
							'membershipCtaUrl',
							__( 'نشانی دکمهٔ عضویت', 'music-wave-core' ),
							'',
						],
					],
					help: __(
						'برای استفاده از پیام یا تنظیم جهانی MusicWave، خالی بگذارید.',
						'music-wave-core'
					),
				},
			],
		},
		'music-wave/release-credits': {
			releaseId: true,
			groups: [
				{
					title: __( 'محتوا', 'music-wave-core' ),
					controls: [
						[
							'text',
							'heading',
							__( 'عنوان بخش', 'music-wave-core' ),
							'',
							__(
								'در صورت خالی بودن، عنوان بخش نمایش داده نمی‌شود.',
								'music-wave-core'
							),
						],
						[
							'toggle',
							'showRole',
							__( 'نمایش نقش عوامل', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'groupByRole',
							__(
								'گروه‌بندی عوامل بر اساس نقش',
								'music-wave-core'
							),
							false,
						],
					],
				},
				{
					title: __( 'چیدمان', 'music-wave-core' ),
					controls: [
						[
							'select',
							'layout',
							__( 'چیدمان عوامل', 'music-wave-core' ),
							[
								[ 'list', __( 'فهرست', 'music-wave-core' ) ],
								[ 'grid', __( 'شبکه', 'music-wave-core' ) ],
								[
									'inline',
									__( 'درون‌خطی', 'music-wave-core' ),
								],
							],
						],
					],
				},
			],
		},
		'music-wave/collection-list': {
			releaseId: true,
			groups: [
				{
					title: __(
						'چیدمان و ستون‌های فهرست قطعه',
						'music-wave-core'
					),
					controls: [
						[
							'text',
							'heading',
							__( 'عنوان بخش', 'music-wave-core' ),
							'',
							__(
								'در صورت خالی بودن، از عنوان پیش‌فرض استفاده می‌شود.',
								'music-wave-core'
							),
						],
						[
							'select',
							'density',
							__( 'تراکم و چیدمان ردیف‌ها', 'music-wave-core' ),
							[
								[
									'comfortable',
									__( 'استاندارد متعادل', 'music-wave-core' ),
								],
								[
									'compact',
									__( 'فشرده استودیویی', 'music-wave-core' ),
								],
								[
									'cards',
									__( 'کارتی برجسته', 'music-wave-core' ),
								],
							],
						],
						[
							'toggle',
							'showHeader',
							__( 'نمایش ردیف سرستون جدول', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showPosition',
							__( 'نمایش شماره قطعه', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showArtwork',
							__( 'نمایش تصویر کاور قطعه', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showArtist',
							__( 'نمایش ستون هنرمند', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showBpm',
							__( 'نمایش ستون تمپو (BPM)', 'music-wave-core' ),
							false,
						],
						[
							'toggle',
							'showQuality',
							__( 'نمایش ستون کیفیت صدا', 'music-wave-core' ),
							false,
						],
						[
							'toggle',
							'showDuration',
							__( 'نمایش مدت زمان هر قطعه', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showTotalDuration',
							__(
								'نمایش تعداد و زمان کل مجموعه',
								'music-wave-core'
							),
							true,
						],
						[
							'toggle',
							'groupByDisc',
							__(
								'گروه‌بندی قطعه‌ها بر اساس دیسک',
								'music-wave-core'
							),
							false,
						],
					],
				},
				{
					title: __( 'دکمه پخش و عملیات هر قطعه', 'music-wave-core' ),
					controls: [
						[
							'select',
							'actionStyle',
							__(
								'سبک نمایش دکمه‌های انتهای ردیف',
								'music-wave-core'
							),
							[
								[
									'icon',
									__(
										'فقط آیکون جمع‌وجور',
										'music-wave-core'
									),
								],
								[
									'button',
									__(
										'دکمه با برچسب متنی',
										'music-wave-core'
									),
								],
							],
						],
						[
							'select',
							'actionsVisibility',
							__(
								'نحوه نمایش عملیات ردیف در دسکتاپ',
								'music-wave-core'
							),
							[
								[
									'always',
									__(
										'همیشه قابل مشاهده',
										'music-wave-core'
									),
								],
								[
									'hover',
									__(
										'آشکار شدن هنگام هاور روی ردیف',
										'music-wave-core'
									),
								],
							],
						],
						[
							'toggle',
							'showPreview',
							__( 'دکمه پخش در انتهای ردیف', 'music-wave-core' ),
							true,
						],
						[
							'customIcon',
							'playIcon',
							__(
								'آیکون سفارشی دکمه پخش (اختیاری)',
								'music-wave-core'
							),
							'play-filled',
						],
						[
							'toggle',
							'showDownload',
							__( 'دکمه دانلود مستقیم قطعه', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showDownloadQuality',
							__(
								'انتخابگر کیفیت دانلود در هر ردیف',
								'music-wave-core'
							),
							true,
							__(
								'کیفیت‌های صوتی موجود برای هر قطعه (مانند 320kbps، 128kbps یا FLAC) را کنار دکمه دانلود نمایش می‌دهد.',
								'music-wave-core'
							),
						],
						[
							'customIcon',
							'downloadIcon',
							__(
								'آیکون سفارشی دکمه دانلود (اختیاری)',
								'music-wave-core'
							),
							'download',
						],
					],
				},
			],
		},
		'music-wave/catalog-filters': {
			groups: [
				{
					title: __( 'فیلدهای فیلتر', 'music-wave-core' ),
					controls: [
						[
							'toggle',
							'showSearch',
							__( 'نمایش فیلد جست‌وجو', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showArtistFilter',
							__( 'نمایش فیلتر هنرمند', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showGenreFilter',
							__( 'نمایش فیلتر ژانر', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showMoodFilter',
							__( 'نمایش فیلتر حال‌وهوا', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showTypeFilter',
							__( 'نمایش فیلتر نوع انتشار', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showSort',
							__( 'نمایش انتخابگر مرتب‌سازی', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showReset',
							__( 'نمایش پیوند بازنشانی', 'music-wave-core' ),
							true,
						],
					],
					help: __(
						'کشویی بدون عبارات منتشرشده به‌طور خودکار پنهان می‌شود.',
						'music-wave-core'
					),
				},
				{
					title: __( 'چیدمان و برچسب‌ها', 'music-wave-core' ),
					controls: [
						[
							'select',
							'layout',
							__( 'چیدمان فیلترها', 'music-wave-core' ),
							[
								[
									'inline',
									__( 'نوار درون‌خطی', 'music-wave-core' ),
								],
								[
									'stacked',
									__(
										'ردیف‌های تمام عرض',
										'music-wave-core'
									),
								],
							],
							__(
								'حالت روی‌هم‌چیده نیز در پنل سبک‌های بلوک در دسترس است.',
								'music-wave-core'
							),
						],
						[
							'range',
							'maxTerms',
							__( 'گزینه‌های هر فیلتر', 'music-wave-core' ),
							10,
							200,
							__(
								'تعداد اصطلاحات را در هر فهرست کشویی محدود می‌کند.',
								'music-wave-core'
							),
							50,
						],
						[
							'text',
							'searchPlaceholder',
							__( 'متن جایگزین جست‌وجو', 'music-wave-core' ),
							'',
						],
						[
							'text',
							'submitLabel',
							__( 'اعمال برچسب دکمه', 'music-wave-core' ),
							'',
						],
						[
							'text',
							'resetLabel',
							__( 'بازنشانی برچسب پیوند', 'music-wave-core' ),
							'',
						],
					],
					help: __(
						'برای استفاده از پیش‌فرض‌های ترجمه‌شده، برچسب‌ها را خالی بگذارید. مرتب‌سازی پیش‌فرض از تنظیمات بایگانی MusicWave پیروی می‌کند.',
						'music-wave-core'
					),
				},
			],
		},
		'music-wave/catalog-results': {
			toggles: [
				[
					'showCount',
					__( 'نمایش تعداد نتایج', 'music-wave-core' ),
					true,
					__(
						'نشان می‌دهد که چند انتشار با فیلترهای فعلی مطابقت دارند.',
						'music-wave-core'
					),
				],
				[
					'showChips',
					__( 'نمایش تراشه‌های فیلتر فعال', 'music-wave-core' ),
					true,
					__(
						'هر تراشه بدون آن فیلتر به کاتالوگ بازمی‌گردد.',
						'music-wave-core'
					),
				],
			],
		},
		'music-wave/search-unified': {
			previewQuery: true,
			groups: [
				{
					title: __( 'گروه‌ها', 'music-wave-core' ),
					controls: [
						[
							'toggle',
							'showReleases',
							__( 'نمایش انتشارها', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showArtists',
							__( 'نمایش هنرمندان', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showGenres',
							__( 'نمایش سبک‌ها', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showProducts',
							__( 'نمایش محصولات', 'music-wave-core' ),
							true,
						],
						[
							'range',
							'limitPerGroup',
							__( 'تعداد در هر گروه', 'music-wave-core' ),
							1,
							20,
							__(
								'حداکثر نتایج نمایش داده شده برای هر گروه.',
								'music-wave-core'
							),
							6,
						],
					],
					help: __(
						'نتایج جستجو را گروه‌بندی شده نمایش می‌دهد. در صفحه جستجو، عبارت جستجو از query اصلی خوانده می‌شود.',
						'music-wave-core'
					),
				},
			],
		},
		'music-wave/preview-player': {
			releaseId: true,
			managesOwnStyles: true,
			groups: [
				{
					title: __(
						'سبک، اندازه و آیکون پخش‌کننده',
						'music-wave-core'
					),
					controls: [
						[
							'select',
							'buttonStyle',
							__( 'حالت نمایش', 'music-wave-core' ),
							ACTION_BUTTON_STYLE_OPTIONS,
							__(
								'«فقط آیکون» دکمه گرد شاخص و «دکمه کامل با متن» کپسول همراه با برچسب نمایش می‌دهد.',
								'music-wave-core'
							),
						],
						[
							'select',
							'variant',
							__( 'سبک رنگی دکمه', 'music-wave-core' ),
							ACTION_BUTTON_VARIANT_OPTIONS,
						],
						[
							'select',
							'size',
							__( 'اندازه دکمه', 'music-wave-core' ),
							ACTION_BUTTON_SIZE_OPTIONS,
						],
						[
							'select',
							'shape',
							__( 'فرم گوشه‌ها', 'music-wave-core' ),
							ACTION_BUTTON_SHAPE_OPTIONS,
						],
						[
							'customIcon',
							'icon',
							__( 'آیکون سفارشی (اختیاری)', 'music-wave-core' ),
							'play-filled',
						],
						[
							'text',
							'label',
							__( 'متن سفارشی دکمه', 'music-wave-core' ),
							'',
						],
						[
							'toggle',
							'showIcon',
							__( 'نمایش نماد در حالت متنی', 'music-wave-core' ),
							true,
						],
					],
				},
			],
		},
		'music-wave/download-button': {
			releaseId: true,
			compact: true,
			groups: [
				{
					title: __(
						'سبک، اندازه و آیکون دکمه دانلود',
						'music-wave-core'
					),
					controls: [
						[
							'select',
							'buttonStyle',
							__( 'حالت نمایش دکمه', 'music-wave-core' ),
							ACTION_BUTTON_STYLE_OPTIONS,
						],
						[
							'select',
							'variant',
							__( 'سبک رنگی دکمه', 'music-wave-core' ),
							ACTION_BUTTON_VARIANT_OPTIONS,
						],
						[
							'select',
							'size',
							__( 'اندازه دکمه', 'music-wave-core' ),
							ACTION_BUTTON_SIZE_OPTIONS,
						],
						[
							'select',
							'shape',
							__( 'فرم گوشه‌ها', 'music-wave-core' ),
							ACTION_BUTTON_SHAPE_OPTIONS,
						],
						[
							'customIcon',
							'icon',
							__(
								'آیکون دکمه دانلود (اختیاری)',
								'music-wave-core'
							),
							'download',
						],
						[
							'customIcon',
							'playIcon',
							__( 'آیکون دکمه پخش (اختیاری)', 'music-wave-core' ),
							'play-filled',
						],
					],
					help: __(
						'حالت نمایش، سبک رنگی، اندازه و فرم گوشه‌ها به‌صورت هماهنگ روی دکمه دانلود، دکمه پخش و انتخابگر کیفیت اعمال می‌شوند.',
						'music-wave-core'
					),
				},
				{
					title: __(
						'باکس دانلود، کیفیت‌ها و برچسب‌ها',
						'music-wave-core'
					),
					controls: [
						[
							'toggle',
							'showHeading',
							__( 'نمایش عنوان باکس دانلود', 'music-wave-core' ),
							true,
						],
						[
							'text',
							'heading',
							__( 'عنوان باکس دانلود', 'music-wave-core' ),
							'',
						],
						[
							'toggle',
							'showDescription',
							__(
								'نمایش توضیح کوتاه زیر عنوان',
								'music-wave-core'
							),
							true,
						],
						[
							'text',
							'description',
							__( 'متن توضیح کوتاه', 'music-wave-core' ),
							'',
						],
						[
							'toggle',
							'showQuality',
							__( 'انتخابگر کیفیت فایل صوتی', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showStream',
							__(
								'نمایش دکمه پخش آنلاین کنار دانلود',
								'music-wave-core'
							),
							true,
						],
						[
							'text',
							'downloadLabel',
							__( 'برچسب دکمه دانلود', 'music-wave-core' ),
							'',
						],
						[
							'text',
							'playLabel',
							__( 'برچسب دکمه پخش کامل', 'music-wave-core' ),
							'',
						],
						[
							'text',
							'loginLabel',
							__( 'برچسب دکمه ورود مهمان', 'music-wave-core' ),
							'',
						],
					],
					help: __(
						'در حالت پیش‌فرض باکس کامل «دانلود این انتشار» نمایش داده می‌شود؛ برای نوار عملیات بالای صفحه «حالت فشرده» را روشن کنید.',
						'music-wave-core'
					),
				},
			],
		},
		'music-wave/related-releases': {
			releaseId: true,
			groups: [
				{
					title: __( 'بخش‌ها', 'music-wave-core' ),
					controls: [
						[
							'select',
							'sameArtistSection',
							__( 'بخش همان هنرمند', 'music-wave-core' ),
							[
								[
									'inherit',
									__(
										'پیش‌فرض (تنظیم جهانی)',
										'music-wave-core'
									),
								],
								[
									'enabled',
									__( 'همیشه نشان دهید', 'music-wave-core' ),
								],
								[
									'disabled',
									__( 'پنهان کردن', 'music-wave-core' ),
								],
							],
						],
						[
							'select',
							'similarSection',
							__( 'بخش انتشارات مشابه', 'music-wave-core' ),
							[
								[
									'inherit',
									__(
										'پیش‌فرض (تنظیم جهانی)',
										'music-wave-core'
									),
								],
								[
									'enabled',
									__( 'همیشه نشان دهید', 'music-wave-core' ),
								],
								[
									'disabled',
									__( 'پنهان کردن', 'music-wave-core' ),
								],
							],
						],
						[
							'text',
							'sameArtistHeading',
							__( 'عنوان همان هنرمند', 'music-wave-core' ),
							'',
						],
						[
							'text',
							'similarHeading',
							__( 'عنوان انتشارهای مشابه', 'music-wave-core' ),
							'',
						],
						[
							'toggle',
							'showSectionLink',
							__( 'نمایش پیوند بخش', 'music-wave-core' ),
							false,
							__(
								'بخش مربوط به همان هنرمند را به آرشیو هنرمند و بخش مشابه را به کاتالوگ پیوند می‌دهد.',
								'music-wave-core'
							),
						],
						[
							'text',
							'sectionLinkLabel',
							__( 'برچسب پیوند بخش', 'music-wave-core' ),
							'',
						],
					],
				},
				{
					title: __( 'پرس‌وجو', 'music-wave-core' ),
					controls: [
						[
							'range',
							'itemsToShow',
							__( 'موارد در هر بخش', 'music-wave-core' ),
							2,
							12,
							__(
								'تا زمان تغییر از پیش‌فرض جهانی استفاده می‌کند.',
								'music-wave-core'
							),
							4,
						],
						[
							'select',
							'orderBy',
							__( 'به ترتیب', 'music-wave-core' ),
							[
								[
									'date',
									__( 'تاریخ انتشار', 'music-wave-core' ),
								],
								[
									'modified',
									__(
										'به‌تازگی به‌روزشده',
										'music-wave-core'
									),
								],
								[ 'title', __( 'عنوان', 'music-wave-core' ) ],
								[ 'rand', __( 'تصادفی', 'music-wave-core' ) ],
							],
						],
						[
							'select',
							'order',
							__( 'سفارش', 'music-wave-core' ),
							[
								[ 'DESC', __( 'نزولی', 'music-wave-core' ) ],
								[ 'ASC', __( 'صعودی', 'music-wave-core' ) ],
							],
						],
						[
							'toggle',
							'matchGenre',
							__( 'مطابقت با ژانرهای مشترک', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'matchMood',
							__( 'تطبیق حال‌وهواهای مشترک', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'matchType',
							__( 'مطابقت با نوع انتشار', 'music-wave-core' ),
							true,
						],
					],
				},
				{
					title: __( 'چیدمان', 'music-wave-core' ),
					controls: [
						[
							'select',
							'layout',
							__( 'چیدمان', 'music-wave-core' ),
							[
								[ 'grid', __( 'شبکه', 'music-wave-core' ) ],
								[
									'scroll',
									__( 'طومار افقی', 'music-wave-core' ),
								],
								[ 'list', __( 'فهرست', 'music-wave-core' ) ],
							],
						],
						[
							'range',
							'columns',
							__( 'ستون‌های شبکه', 'music-wave-core' ),
							2,
							6,
							undefined,
							4,
						],
						[
							'select',
							'imageShape',
							__( 'شکل اثر هنری', 'music-wave-core' ),
							[
								[ 'square', __( 'مربع', 'music-wave-core' ) ],
								[
									'landscape',
									__( 'منظره', 'music-wave-core' ),
								],
								[
									'portrait',
									__( 'پرتره', 'music-wave-core' ),
								],
								[ 'circle', __( 'دایره', 'music-wave-core' ) ],
							],
						],
					],
				},
				{
					title: __( 'محتوای کارت', 'music-wave-core' ),
					controls: [
						[
							'toggle',
							'showArtwork',
							__( 'نمایش آثار هنری', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showArtist',
							__( 'نمایش هنرمند', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showDate',
							__( 'نمایش تاریخ انتشار', 'music-wave-core' ),
							false,
						],
						[
							'toggle',
							'showExcerpt',
							__( 'نمایش گزیده', 'music-wave-core' ),
							false,
						],
						[
							'toggle',
							'showPreview',
							__( 'نمایش دکمه پیش‌نمایش', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showAction',
							__( 'نمایش پیوند اقدام', 'music-wave-core' ),
							false,
						],
						[
							'text',
							'actionLabel',
							__( 'برچسب پیوند اقدام', 'music-wave-core' ),
							'',
						],
					],
				},
			],
		},
		'music-wave/preview-button': {
			releaseId: true,
			compact: true,
			managesOwnStyles: true,
			textFields: [ [ 'label', __( 'برچسب دکمه', 'music-wave-core' ) ] ],
			groups: [
				{
					title: __( 'سبک، اندازه و آیکون دکمه', 'music-wave-core' ),
					controls: [
						[
							'select',
							'buttonStyle',
							__( 'سبک نمایش دکمه', 'music-wave-core' ),
							[
								[
									'solid',
									__(
										'جامد (اصلی با متن)',
										'music-wave-core'
									),
								],
								[
									'icon',
									__(
										'فقط آیکون جمع‌وجور',
										'music-wave-core'
									),
								],
								[
									'outline',
									__( 'طرح کلی (خطی)', 'music-wave-core' ),
								],
								[
									'ghost',
									__( 'بی‌زمینه (شفاف)', 'music-wave-core' ),
								],
								[
									'vinyl',
									__(
										'صفحهٔ وینیل (چرخان)',
										'music-wave-core'
									),
								],
							],
						],
						[
							'select',
							'variant',
							__( 'سبک رنگی', 'music-wave-core' ),
							ACTION_BUTTON_VARIANT_OPTIONS,
						],
						[
							'select',
							'size',
							__( 'اندازه دکمه', 'music-wave-core' ),
							ACTION_BUTTON_SIZE_OPTIONS,
						],
						[
							'select',
							'shape',
							__( 'فرم گوشه‌ها', 'music-wave-core' ),
							ACTION_BUTTON_SHAPE_OPTIONS,
						],
						[
							'customIcon',
							'icon',
							__( 'آیکون سفارشی (اختیاری)', 'music-wave-core' ),
							'play-filled',
						],
						[
							'toggle',
							'showIcon',
							__(
								'نمایش نماد پخش در حالت متنی',
								'music-wave-core'
							),
							true,
						],
						[
							'toggle',
							'fullWidth',
							__( 'دکمه تمام‌عرض', 'music-wave-core' ),
							false,
						],
					],
				},
			],
		},
		'music-wave/account-dashboard': {
			dashboardPanelSorter: true,
			groups: [
				{
					title: __( 'فعال‌سازی بخش‌های داشبورد', 'music-wave-core' ),
					controls: [
						[
							'toggle',
							'showLibrary',
							__( 'پنل کتابخانه موسیقی', 'music-wave-core' ),
							true,
							__(
								'انتشارهای ذخیره‌شده و فایل‌های حفاظت‌شده کاربر.',
								'music-wave-core'
							),
						],
						[
							'toggle',
							'showMembershipPanel',
							__( 'پنل عضویت ویژه (VIP)', 'music-wave-core' ),
							true,
							__(
								'سطح اشتراک فعال، تاریخ انقضا و طرح‌های عضویت.',
								'music-wave-core'
							),
						],
						[
							'toggle',
							'showPlaylists',
							__( 'پنل فهرست‌های پخش شخصی', 'music-wave-core' ),
							true,
							__(
								'مدیریت و پخش فهرست‌های پخش ساخته‌شده توسط کاربر.',
								'music-wave-core'
							),
						],
						[
							'toggle',
							'showOrders',
							__( 'پنل سفارش‌ها', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showDownloads',
							__( 'پنل دانلودها', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showAddresses',
							__( 'پنل آدرس‌ها', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showPaymentMethods',
							__( 'پنل روش‌های پرداخت', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showAccountDetails',
							__( 'پنل جزئیات و ویرایش حساب', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showRequests',
							__( 'پنل پیگیری درخواست‌ها', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showSignOut',
							__( 'دکمه خروج از حساب', 'music-wave-core' ),
							true,
						],
					],
				},
				{
					title: __( 'سربرگ و آمار', 'music-wave-core' ),
					controls: [
						[
							'toggle',
							'showWelcome',
							__( 'نمایش خوش‌آمدگویی و VIP', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showStats',
							__( 'ردیف آمار کاربر', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showQuickLinks',
							__( 'نوار زبانه‌ها', 'music-wave-core' ),
							true,
							__(
								'در صورت خاموش‌بودن، همه پنل‌های فعال زیر هم نمایش داده می‌شوند.',
								'music-wave-core'
							),
						],
						[
							'text',
							'introText',
							__( 'متن خوش‌آمدگویی', 'music-wave-core' ),
							'',
						],
					],
				},
				{
					title: __( 'عناوین پنل‌ها', 'music-wave-core' ),
					controls: [
						[
							'text',
							'libraryHeading',
							__(
								'عنوان کتابخانه علاقه‌مندی‌ها',
								'music-wave-core'
							),
							'',
						],
						[
							'text',
							'membershipHeading',
							__( 'عنوان عضویت', 'music-wave-core' ),
							'',
						],
						[
							'text',
							'playlistsHeading',
							__( 'عنوان فهرست‌های پخش', 'music-wave-core' ),
							'',
						],
						[
							'text',
							'ordersHeading',
							__( 'عنوان سفارش‌ها', 'music-wave-core' ),
							'',
						],
						[
							'text',
							'downloadsHeading',
							__( 'عنوان دانلودها', 'music-wave-core' ),
							'',
						],
						[
							'text',
							'addressesHeading',
							__( 'عنوان آدرس‌ها', 'music-wave-core' ),
							'',
						],
						[
							'text',
							'paymentHeading',
							__( 'عنوان روش‌های پرداخت', 'music-wave-core' ),
							'',
						],
						[
							'text',
							'accountHeading',
							__(
								'عنوان جزئیات و ویرایش حساب',
								'music-wave-core'
							),
							'',
						],
						[
							'text',
							'requestsHeading',
							__( 'عنوان پیگیری درخواست‌ها', 'music-wave-core' ),
							'',
						],
					],
					help: __(
						'در صورت پر بودن، عنوان سفارشی جایگزین می‌شود.',
						'music-wave-core'
					),
				},
			],
		},
		'music-wave/dashboard-welcome': {
			groups: [
				{
					title: __( 'خوش‌آمدگویی', 'music-wave-core' ),
					controls: [
						[
							'toggle',
							'showAvatar',
							__( 'نمایش آواتار', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showVipBadge',
							__( 'نشان VIP واقعی', 'music-wave-core' ),
							true,
							__(
								'نشان VIP از سیستم اشتراک واقعی نمایش داده می‌شود، نه فقط UI.',
								'music-wave-core'
							),
						],
						[
							'toggle',
							'showSubscriptionMeta',
							__(
								'اطلاعات اشتراک (سطح، انقضا، باقی‌مانده)',
								'music-wave-core'
							),
							true,
						],
						[
							'toggle',
							'showRenewal',
							__( 'دکمه تمدید پس از ۹۰٪', 'music-wave-core' ),
							true,
						],
						[
							'text',
							'introText',
							__( 'متن خوش‌آمدگویی', 'music-wave-core' ),
							'',
						],
					],
				},
			],
		},
		'music-wave/dashboard-stats': {
			groups: [
				{
					title: __( 'آمار', 'music-wave-core' ),
					controls: [
						[
							'toggle',
							'showEntitled',
							__( 'دانلودهای موجود', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showSaved',
							__( 'علاقه‌مندی‌ها', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showArtists',
							__( 'هنرمندان دنبال‌شده', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'compact',
							__( 'حالت فشرده', 'music-wave-core' ),
							true,
						],
					],
				},
			],
		},
		'music-wave/dashboard-subscription': {
			groups: [
				{
					title: __( 'کارت اشتراک VIP', 'music-wave-core' ),
					controls: [
						[
							'toggle',
							'showActive',
							__( 'نمایش سطوح فعال', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showProgress',
							__( 'نوار پیشرفت', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showOrderLink',
							__( 'لینک سفارش', 'music-wave-core' ),
							false,
						],
						[
							'toggle',
							'showRenewal',
							__( 'دکمه تمدید', 'music-wave-core' ),
							true,
						],
						[
							'text',
							'heading',
							__( 'عنوان کارت', 'music-wave-core' ),
							'',
						],
					],
				},
			],
		},
		'music-wave/dashboard-account': {
			groups: [
				{
					title: __( 'جزئیات و ویرایش حساب', 'music-wave-core' ),
					controls: [
						[
							'text',
							'heading',
							__( 'عنوان بخش', 'music-wave-core' ),
							'',
							__(
								'در صورت خالی بودن، عنوان بخش نمایش داده نمی‌شود.',
								'music-wave-core'
							),
						],
						[
							'text',
							'description',
							__( 'توضیح کوتاه زیر عنوان', 'music-wave-core' ),
							'',
						],
						[
							'toggle',
							'compact',
							__( 'حالت فشرده مدرن', 'music-wave-core' ),
							true,
							__(
								'فرم یکپارچه نام، ایمیل و تغییر رمز عبور در کارت فشرده.',
								'music-wave-core'
							),
						],
						[
							'toggle',
							'showAvatar',
							__( 'نمایش آواتار کاربر', 'music-wave-core' ),
							false,
						],
						[
							'toggle',
							'showNotices',
							__(
								'نمایش پیام‌ها و اعلان‌های فرم',
								'music-wave-core'
							),
							true,
						],
					],
				},
			],
		},
		'music-wave/dashboard-addresses': {
			groups: [
				{
					title: __( 'آدرس‌ها', 'music-wave-core' ),
					controls: [
						[
							'text',
							'heading',
							__( 'عنوان بخش', 'music-wave-core' ),
							'',
						],
						[
							'toggle',
							'compact',
							__( 'حالت فشرده', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showBilling',
							__( 'آدرس صورتحساب', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showShipping',
							__( 'آدرس حمل‌ونقل', 'music-wave-core' ),
							true,
						],
					],
				},
			],
		},
		'music-wave/dashboard-payment': {
			groups: [
				{
					title: __( 'روش‌های پرداخت', 'music-wave-core' ),
					controls: [
						[
							'text',
							'heading',
							__( 'عنوان بخش', 'music-wave-core' ),
							'',
						],
						[
							'toggle',
							'compact',
							__( 'حالت فشرده', 'music-wave-core' ),
							true,
						],
					],
				},
			],
		},
		'music-wave/dashboard-orders': {
			groups: [
				{
					title: __( 'سفارش‌ها', 'music-wave-core' ),
					controls: [
						[
							'text',
							'heading',
							__( 'عنوان بخش', 'music-wave-core' ),
							'',
						],
						[
							'toggle',
							'compact',
							__( 'جدول فشرده', 'music-wave-core' ),
							true,
						],
						[
							'range',
							'perPage',
							__( 'تعداد سفارش', 'music-wave-core' ),
							1,
							20,
							__(
								'تعداد سفارش نمایش داده شده در این بلوک.',
								'music-wave-core'
							),
							6,
						],
					],
				},
			],
		},
		'music-wave/dashboard-downloads': {
			groups: [
				{
					title: __( 'دانلودها', 'music-wave-core' ),
					controls: [
						[
							'text',
							'heading',
							__( 'عنوان بخش', 'music-wave-core' ),
							'',
						],
						[
							'toggle',
							'compact',
							__( 'حالت فشرده', 'music-wave-core' ),
							true,
						],
					],
				},
			],
		},
		'music-wave/dashboard-requests': {
			groups: [
				{
					title: __( 'پیگیری درخواست‌ها', 'music-wave-core' ),
					controls: [
						[
							'text',
							'heading',
							__( 'عنوان بخش', 'music-wave-core' ),
							'',
						],
						[
							'toggle',
							'compact',
							__( 'حالت فشرده', 'music-wave-core' ),
							true,
						],
						[
							'range',
							'perPage',
							__( 'تعداد درخواست‌ها', 'music-wave-core' ),
							1,
							20,
							__(
								'حداکثر تعداد درخواست‌های نمایش‌داده‌شده.',
								'music-wave-core'
							),
							6,
						],
						[
							'toggle',
							'showStatus',
							__( 'نمایش وضعیت', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showReference',
							__( 'نمایش کد رهگیری', 'music-wave-core' ),
							true,
						],
					],
				},
			],
		},
		'music-wave/membership-panel': {
			toggles: [
				[
					'showActive',
					__( 'نمایش سطوح فعال و انقضا', 'music-wave-core' ),
					true,
					__(
						'کمک هزینه عضویت فعلی بیننده را فهرست می‌کند.',
						'music-wave-core'
					),
				],
				[
					'showPlans',
					__( 'نمایش محصولات طرح', 'music-wave-core' ),
					true,
					__(
						'محصولات برنامه VIP پیکربندی‌شده WooCommerce را فهرست می‌کند.',
						'music-wave-core'
					),
				],
				[
					'showBuyButtons',
					__( 'نمایش دکمه‌های خرید', 'music-wave-core' ),
					true,
					__(
						'یک دکمه افزودن به سبد خرید را به هر محصول طرح اضافه می‌کند.',
						'music-wave-core'
					),
				],
			],
			textFields: [
				[ 'heading', __( 'عنوان بخش', 'music-wave-core' ) ],
				[ 'emptyText', __( 'پیام حالت خالی', 'music-wave-core' ) ],
			],
		},
		'music-wave/music-library': {
			groups: [
				{
					title: __( 'سربرگ', 'music-wave-core' ),
					controls: [
						[
							'text',
							'heading',
							__( 'عنوان بخش', 'music-wave-core' ),
							'',
						],
						[
							'text',
							'intro',
							__( 'متن مقدمه', 'music-wave-core' ),
							'',
						],
						[
							'toggle',
							'showFilters',
							__( 'نمایش زبانه‌های فیلتر', 'music-wave-core' ),
							true,
							__(
								'زبانه‌ها موارد ذخیره‌شده را بر اساس نوع انتشار و هنرمندان دنبال‌شده گروه‌بندی می‌کنند.',
								'music-wave-core'
							),
						],
						[
							'toggle',
							'showCounts',
							__( 'نمایش تعداد اقلام', 'music-wave-core' ),
							true,
						],
						[
							'text',
							'emptyMessage',
							__( 'پیام حالت خالی', 'music-wave-core' ),
							'',
						],
					],
					help: __(
						'برای استفاده از پیش‌فرض‌های ترجمه‌شده، متون را خالی بگذارید.',
						'music-wave-core'
					),
				},
				{
					title: __( 'چیدمان', 'music-wave-core' ),
					controls: [
						[
							'select',
							'layout',
							__( 'طرح کتابخانه', 'music-wave-core' ),
							[
								[ 'list', __( 'فهرست', 'music-wave-core' ) ],
								[ 'grid', __( 'شبکه', 'music-wave-core' ) ],
							],
						],
						[
							'range',
							'columns',
							__( 'ستون‌های شبکه', 'music-wave-core' ),
							2,
							6,
							undefined,
							4,
						],
						[
							'range',
							'itemsToShow',
							__( 'موارد در هر صفحه', 'music-wave-core' ),
							1,
							100,
							undefined,
							24,
						],
					],
				},
				{
					title: __( 'نمایش آیتم', 'music-wave-core' ),
					controls: [
						[
							'toggle',
							'showArtist',
							__( 'نمایش نام هنرمند', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showType',
							__( 'نمایش نوع نشان', 'music-wave-core' ),
							true,
							__(
								'آهنگ، آلبوم، پادکست یا برچسب هنرمند را نمایش می‌دهد.',
								'music-wave-core'
							),
						],
						[
							'toggle',
							'showYear',
							__( 'نمایش سال انتشار', 'music-wave-core' ),
							false,
						],
						[
							'toggle',
							'showRemove',
							__( 'نمایش دکمه‌های حذف', 'music-wave-core' ),
							true,
						],
					],
				},
			],
		},
		'music-wave/library-button': {
			releaseId: true,
			compact: true,
			groups: [
				{
					title: __( 'سبک، اندازه و آیکون دکمه', 'music-wave-core' ),
					controls: [
						[
							'select',
							'buttonStyle',
							__( 'حالت نمایش دکمه', 'music-wave-core' ),
							[
								[
									'heart',
									__(
										'فقط آیکون جمع‌وجور',
										'music-wave-core'
									),
								],
								[
									'button',
									__(
										'طرح کامل دکمه (آیکون و متن)',
										'music-wave-core'
									),
								],
							],
						],
						[
							'select',
							'variant',
							__( 'سبک رنگی دکمه', 'music-wave-core' ),
							ACTION_BUTTON_VARIANT_OPTIONS,
						],
						[
							'select',
							'size',
							__( 'اندازه دکمه', 'music-wave-core' ),
							ACTION_BUTTON_SIZE_OPTIONS,
						],
						[
							'select',
							'shape',
							__( 'فرم گوشه‌ها', 'music-wave-core' ),
							ACTION_BUTTON_SHAPE_OPTIONS,
						],
						[
							'customIcon',
							'icon',
							__( 'آیکون سفارشی (اختیاری)', 'music-wave-core' ),
							'heart',
						],
						[
							'select',
							'itemType',
							__( 'هدف ذخیره‌سازی دکمه', 'music-wave-core' ),
							[
								[
									'release',
									__(
										'ذخیره انتشار در کتابخانه شخصی',
										'music-wave-core'
									),
								],
								[
									'wishlist',
									__(
										'افزودن به فهرست علاقه‌مندی‌ها',
										'music-wave-core'
									),
								],
								[
									'presave',
									__(
										'پیش‌ذخیره انتشار آینده',
										'music-wave-core'
									),
								],
								[
									'artist',
									__(
										'دنبال‌کردن هنرمند',
										'music-wave-core'
									),
								],
							],
						],
						[
							'intText',
							'termId',
							__(
								'شناسه هنرمند (۰ = هنرمند انتشار یا آرشیو فعلی)',
								'music-wave-core'
							),
							'',
						],
						[
							'text',
							'label',
							__( 'متن دکمه', 'music-wave-core' ),
							'',
						],
						[
							'text',
							'addedLabel',
							__( 'متن حالت ذخیره‌شده', 'music-wave-core' ),
							'',
						],
					],
				},
			],
		},
		'music-wave/artist-profile': {
			artistProfile: true,
			previewArtist: true,
		},
		'music-wave/artists-shelf': {
			groups: [
				{
					title: __( 'سربرگ', 'music-wave-core' ),
					controls: [
						[ 'text', 'eyebrow', __( 'ابرو', 'music-wave-core' ) ],
						[
							'text',
							'heading',
							__( 'عنوان بخش', 'music-wave-core' ),
							'',
							__(
								'برای استفاده از عنوان پیش‌فرض ترجمه‌شده خالی بگذارید.',
								'music-wave-core'
							),
						],
						[
							'text',
							'description',
							__( 'متن مقدمه', 'music-wave-core' ),
						],
						[
							'text',
							'sectionUrl',
							__( 'مشاهدهٔ همهٔ نشانی پیوند', 'music-wave-core' ),
							'',
							__(
								'معمولاً صفحه کاتالوگ یا صفحه فهرست هنرمندان.',
								'music-wave-core'
							),
						],
						[
							'text',
							'sectionLinkLabel',
							__(
								'برچسب پیوند «مشاهدهٔ همه»',
								'music-wave-core'
							),
						],
					],
				},
				{
					title: __( 'منبع', 'music-wave-core' ),
					controls: [
						[
							'select',
							'source',
							__(
								'کدام هنرمندان ظاهر می‌شوند',
								'music-wave-core'
							),
							[
								[
									'all',
									__(
										'همه هنرمندان کاتالوگ',
										'music-wave-core'
									),
								],
								[
									'genre',
									__( 'هنرمندان یک ژانر', 'music-wave-core' ),
								],
								[
									'mood',
									__(
										'هنرمندان یک حال‌وهوا',
										'music-wave-core'
									),
								],
								[
									'manual',
									__(
										'فهرست دست‌چین‌شده',
										'music-wave-core'
									),
								],
							],
						],
						[
							'text',
							'termSlug',
							__( 'ژانر یا حال‌وهوا', 'music-wave-core' ),
							'',
							__(
								'برای منبع ژانر و حال‌وهوا استفاده می‌شود. مثال: پاپ.',
								'music-wave-core'
							),
						],
						[
							'text',
							'artistIds',
							__( 'شناسه‌های اصطلاح هنرمند', 'music-wave-core' ),
							'',
							__(
								'استفاده شده توسط منبع منتخب. شناسه‌های عددی جداشده با ویرگول، ترتیب دقیق شما را حفظ می‌کنند.',
								'music-wave-core'
							),
						],
						[
							'select',
							'orderBy',
							__( 'به ترتیب', 'music-wave-core' ),
							[
								[
									'count',
									__(
										'بیشترین انتشار اول',
										'music-wave-core'
									),
								],
								[ 'name', __( 'نام A–Z', 'music-wave-core' ) ],
								[ 'rand', __( 'تصادفی', 'music-wave-core' ) ],
							],
							__(
								'شمارش فقط انتشارهای منتشرشده را پوشش می‌دهد.',
								'music-wave-core'
							),
						],
						[
							'range',
							'itemsToShow',
							__( 'حداکثر هنرمندان', 'music-wave-core' ),
							1,
							24,
							undefined,
							8,
						],
						[
							'toggle',
							'pagination',
							__( 'صفحه‌بندی هنرمندان', 'music-wave-core' ),
							false,
							__(
								'زیر ویترین، ناوبری صفحه‌بندی با شمارهٔ صفحه، «قبلی/بعدی» و شمارش هنرمندان اضافه می‌شود. برای فهرست کامل هنرمندان در صفحهٔ هنرمندان مناسب است.',
								'music-wave-core'
							),
						],
						[
							'range',
							'perPage',
							__( 'هنرمند در هر صفحه', 'music-wave-core' ),
							4,
							24,
							__(
								'هنگام روشن‌بودن صفحه‌بندی، تعداد هنرمند هر صفحه را تعیین می‌کند و بر «حداکثر هنرمندان» اولویت دارد.',
								'music-wave-core'
							),
							12,
						],
						[
							'text',
							'emptyMessage',
							__( 'پیام حالت خالی', 'music-wave-core' ),
						],
					],
				},
				{
					title: __( 'چیدمان و استایل', 'music-wave-core' ),
					controls: [
						[
							'select',
							'layout',
							__( 'چیدمان', 'music-wave-core' ),
							[
								[ 'grid', __( 'شبکه', 'music-wave-core' ) ],
								[
									'scroll',
									__( 'قفسه افقی', 'music-wave-core' ),
								],
								[
									'list',
									__( 'فهرست جمع‌وجور', 'music-wave-core' ),
								],
							],
							__(
								'از کروم اشتراک‌گذاری قفسه استفادهٔ مجدد می‌کند، بنابراین با قفسه‌های دیگر مطابقت دارد.',
								'music-wave-core'
							),
						],
						[
							'styleClass',
							[ 'editorial', 'minimal', 'glass' ],
							'editorial',
							__( 'سبک نمایش', 'music-wave-core' ),
							[
								[
									'editorial',
									__( 'تحریریه', 'music-wave-core' ),
								],
								[
									'minimal',
									__( 'مینیمال', 'music-wave-core' ),
								],
								[
									'glass',
									__( 'شیشه‌ای (گلس)', 'music-wave-core' ),
								],
							],
						],
						[
							'range',
							'columns',
							__( 'ستون‌های شبکه', 'music-wave-core' ),
							2,
							6,
							undefined,
							4,
						],
						[
							'select',
							'imageShape',
							__( 'شکل آواتار', 'music-wave-core' ),
							[
								[ 'circle', __( 'دایره', 'music-wave-core' ) ],
								[
									'rounded',
									__( 'گوشه‌های گرد', 'music-wave-core' ),
								],
							],
						],
						[
							'select',
							'imageSize',
							__( 'اندازه تصویر', 'music-wave-core' ),
							[
								[
									'thumbnail',
									__( 'تصویر کوچک', 'music-wave-core' ),
								],
								[ 'medium', __( 'متوسط', 'music-wave-core' ) ],
								[ 'large', __( 'بزرگ', 'music-wave-core' ) ],
								[ 'full', __( 'کامل', 'music-wave-core' ) ],
							],
						],
						[
							'toggle',
							'showImage',
							__( 'نمایش آواتار', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showName',
							__( 'نمایش نام هنرمند', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showReleaseCount',
							__( 'نمایش تعداد انتشارها', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showBio',
							__( 'نمایش گزیده بیوگرافی', 'music-wave-core' ),
							false,
						],
						[
							'range',
							'bioLength',
							__( 'طول بیوگرافی (کلمات)', 'music-wave-core' ),
							5,
							80,
							undefined,
							20,
						],
						[
							'toggle',
							'showFollowButton',
							__( 'نمایش دکمه دنبال هنرمند', 'music-wave-core' ),
							true,
							__(
								'از دکمه دنبال‌کردن کتابخانه شخصی دوباره استفاده می‌کند، بنابراین دنبال کنندگان در همه جا همگام می‌مانند.',
								'music-wave-core'
							),
						],
					],
				},
			],
		},
		'music-wave/taxonomy-shelf': {
			groups: [
				{
					title: __( 'سربرگ', 'music-wave-core' ),
					controls: [
						[ 'text', 'eyebrow', __( 'ابرو', 'music-wave-core' ) ],
						[
							'text',
							'heading',
							__( 'عنوان بخش', 'music-wave-core' ),
							'',
							__(
								'برای استفاده از عنوان پیش‌فرض ترجمه‌شده خالی بگذارید.',
								'music-wave-core'
							),
						],
						[
							'text',
							'description',
							__( 'متن مقدمه', 'music-wave-core' ),
						],
						[
							'text',
							'sectionUrl',
							__( 'مشاهدهٔ همهٔ نشانی پیوند', 'music-wave-core' ),
						],
						[
							'text',
							'sectionLinkLabel',
							__(
								'برچسب پیوند «مشاهدهٔ همه»',
								'music-wave-core'
							),
						],
					],
				},
				{
					title: __( 'منبع', 'music-wave-core' ),
					controls: [
						[
							'select',
							'taxonomy',
							__(
								'کدام طبقه‌بندی را مرور کنیم؟',
								'music-wave-core'
							),
							[
								[
									'mw_genre',
									__( 'سبک‌ها', 'music-wave-core' ),
								],
								[
									'mw_mood',
									__( 'حال‌وهواها', 'music-wave-core' ),
								],
								[
									'mw_label',
									__( 'برچسب‌ها', 'music-wave-core' ),
								],
							],
						],
						[
							'select',
							'source',
							__( 'کدام عبارت ظاهر می‌شود', 'music-wave-core' ),
							[
								[
									'all',
									__(
										'همه اصطلاحات در حال استفاده',
										'music-wave-core'
									),
								],
								[
									'manual',
									__(
										'فهرست دست‌چین‌شده',
										'music-wave-core'
									),
								],
							],
						],
						[
							'text',
							'termIds',
							__( 'اصطلاح شناسه‌ها', 'music-wave-core' ),
							'',
							__(
								'استفاده شده توسط منبع منتخب. شناسه‌های عددی جداشده با ویرگول، ترتیب دقیق شما را حفظ می‌کنند.',
								'music-wave-core'
							),
						],
						[
							'select',
							'orderBy',
							__( 'به ترتیب', 'music-wave-core' ),
							[
								[
									'count',
									__(
										'بیشترین انتشار اول',
										'music-wave-core'
									),
								],
								[ 'name', __( 'نام A–Z', 'music-wave-core' ) ],
								[ 'rand', __( 'تصادفی', 'music-wave-core' ) ],
							],
						],
						[
							'range',
							'itemsToShow',
							__( 'حداکثر کاشی', 'music-wave-core' ),
							1,
							24,
							undefined,
							8,
						],
						[
							'text',
							'emptyMessage',
							__( 'پیام حالت خالی', 'music-wave-core' ),
						],
					],
				},
				{
					title: __( 'چیدمان و استایل', 'music-wave-core' ),
					controls: [
						[
							'select',
							'layout',
							__( 'چیدمان', 'music-wave-core' ),
							[
								[ 'grid', __( 'شبکه', 'music-wave-core' ) ],
								[
									'scroll',
									__( 'ریل افقی', 'music-wave-core' ),
								],
								[
									'list',
									__( 'لیست ردیف‌ها', 'music-wave-core' ),
								],
							],
							__(
								'از کروم اشتراک‌گذاری قفسه استفادهٔ مجدد می‌کند، بنابراین با قفسه‌های دیگر مطابقت دارد.',
								'music-wave-core'
							),
						],
						[
							'range',
							'columns',
							__( 'ستون‌های شبکه', 'music-wave-core' ),
							2,
							6,
							undefined,
							4,
						],
						[
							'select',
							'cardStyle',
							__( 'سبک کاشی', 'music-wave-core' ),
							[
								[
									'colorful',
									__(
										'رنگارنگ (رنگ‌های انتخاب‌شده)',
										'music-wave-core'
									),
								],
								[
									'plain',
									__( 'ساده (سطح آرام)', 'music-wave-core' ),
								],
							],
						],
						[
							'toggle',
							'showCount',
							__( 'نمایش تعداد انتشارها', 'music-wave-core' ),
							true,
						],
					],
				},
			],
		},
		'music-wave/term-hero': {
			previewTerm: true,
			groups: [
				{
					title: __( 'چیدمان', 'music-wave-core' ),
					controls: [
						[
							'select',
							'layout',
							__( 'چیدمان', 'music-wave-core' ),
							[
								[
									'banner',
									__(
										'بنر (جلد تمام عرض)',
										'music-wave-core'
									),
								],
								[
									'compact',
									__(
										'فشرده (ردیف کوچک)',
										'music-wave-core'
									),
								],
							],
						],
						[
							'select',
							'size',
							__( 'ارتفاع بنر', 'music-wave-core' ),
							[
								[ 'short', __( 'کوتاه', 'music-wave-core' ) ],
								[ 'medium', __( 'متوسط', 'music-wave-core' ) ],
								[ 'tall', __( 'بلند', 'music-wave-core' ) ],
							],
						],
						[
							'select',
							'imageShape',
							__( 'شکل تصویر فشرده', 'music-wave-core' ),
							[
								[
									'rounded',
									__( 'گوشه‌های گرد', 'music-wave-core' ),
								],
								[ 'circle', __( 'دایره', 'music-wave-core' ) ],
								[ 'square', __( 'مربع', 'music-wave-core' ) ],
							],
						],
					],
				},
				{
					title: __( 'محتوا', 'music-wave-core' ),
					controls: [
						[
							'toggle',
							'showImage',
							__( 'نمایش تصویر جلد', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showEyebrow',
							__(
								'نمایش برچسب بالای طبقه‌بندی',
								'music-wave-core'
							),
							true,
						],
						[
							'toggle',
							'showName',
							__( 'نمایش نام', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showCount',
							__( 'نمایش تعداد انتشارها', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showDescription',
							__( 'نمایش توضیحات', 'music-wave-core' ),
							true,
						],
						[
							'range',
							'descriptionLength',
							__( 'طول توضیحات (کلمات)', 'music-wave-core' ),
							0,
							120,
							__(
								'۰ برای نمایش توضیحات کامل است؛ هر مقدار دیگری گزیده‌ای کوتاه‌شده را نشان می‌دهد.',
								'music-wave-core'
							),
							40,
						],
						[
							'toggle',
							'showFollowButton',
							__( 'نمایش دکمه دنبال هنرمند', 'music-wave-core' ),
							true,
							__(
								'دکمه فقط برای هنرمندان نمایش داده می‌شود؛ برای ژانرها، حال‌وهواها و برچسب‌ها پنهان می‌ماند.',
								'music-wave-core'
							),
						],
					],
				},
				{
					title: __( 'یک اصطلاح را سنجاق کنید', 'music-wave-core' ),
					controls: [
						[
							'select',
							'taxonomy',
							__( 'طبقه‌بندی', 'music-wave-core' ),
							[
								[
									'',
									__(
										'خودکار (بایگانی فعلی)',
										'music-wave-core'
									),
								],
								[
									'mw_artist',
									__( 'هنرمند', 'music-wave-core' ),
								],
								[ 'mw_genre', __( 'سبک', 'music-wave-core' ) ],
								[
									'mw_mood',
									__( 'حال‌وهوا', 'music-wave-core' ),
								],
								[
									'mw_label',
									__( 'برچسب', 'music-wave-core' ),
								],
							],
						],
						[
							'intText',
							'termId',
							__( 'اصطلاح ID', 'music-wave-core' ),
						],
					],
					help: __(
						'عبارت ID را روی 0 بگذارید تا آرشیو مشاهده‌شده به‌طور خودکار نمایش داده شود.',
						'music-wave-core'
					),
				},
			],
		},
		'music-wave/playlists': {
			groups: [
				{
					title: __( 'عنوان', 'music-wave-core' ),
					controls: [
						[
							'text',
							'heading',
							__( 'عنوان بخش', 'music-wave-core' ),
							'',
						],
					],
					help: __(
						'شنوندگان فهرست‌های پخش را در اینجا مدیریت می‌کنند. هر کنترل بدون JavaScript کار می‌کند.',
						'music-wave-core'
					),
				},
			],
		},
		'music-wave/playback-queue': {
			groups: [
				{
					title: __( 'نمایش', 'music-wave-core' ),
					controls: [
						[
							'text',
							'heading',
							__( 'عنوان بخش', 'music-wave-core' ),
							'',
						],
						[
							'toggle',
							'showArtwork',
							__( 'نمایش آثار هنری', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showPosition',
							__( 'نمایش شماره موقعیت', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showArtist',
							__( 'نمایش نام هنرمندان', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showControls',
							__(
								'نمایش کنترل‌های کلیک و تکرار',
								'music-wave-core'
							),
							true,
						],
						[
							'toggle',
							'showPlayAll',
							__( 'نمایش دکمه پخش همه', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showShuffle',
							__( 'نمایش دکمه پخش تصادفی', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showRepeat',
							__( 'نمایش انتخاب تکرار', 'music-wave-core' ),
							true,
						],
						[
							'text',
							'playAllLabel',
							__( 'برچسب پخش همه', 'music-wave-core' ),
						],
						[
							'text',
							'shuffleLabel',
							__(
								'برچسب پخش تصادفی (خالی = خودکار)',
								'music-wave-core'
							),
						],
						[
							'toggle',
							'showClear',
							__( 'نمایش دکمه صف پاک', 'music-wave-core' ),
							true,
						],
						[
							'text',
							'emptyMessage',
							__( 'پیام حالت خالی', 'music-wave-core' ),
						],
					],
					help: __(
						'پخش همه قبل از تصادفی قرار می‌گیرد و هر دو از کلاس mw-shuffle-button استفاده می‌کنند؛ کلیک‌ها با JavaScript بدون رفرش مدیریت می‌شوند و فرم‌ها به‌عنوان بازگشت بدون JS باقی می‌مانند.',
						'music-wave-core'
					),
				},
			],
		},
		'music-wave/add-to-playlist': {
			releaseId: true,
			groups: [
				actionButtonAppearanceGroup(
					__(
						'متن دکمه در حالت «دکمه کامل با متن»؛ خالی = پیش‌فرض.',
						'music-wave-core'
					),
					'plus'
				),
			],
		},
		'music-wave/share-button': {
			releaseId: true,
			groups: [
				actionButtonAppearanceGroup(
					__(
						'متن دکمه اشتراک‌گذاری در حالت «دکمه کامل با متن»؛ خالی = پیش‌فرض.',
						'music-wave-core'
					),
					'share'
				),
			],
		},
		'music-wave/request-form': {
			groups: [
				{
					title: __( 'حالت فرم', 'music-wave-core' ),
					controls: [
						[
							'select',
							'mode',
							__( 'حالت', 'music-wave-core' ),
							[
								[
									'both',
									__(
										'ترکیبی (سفارش آهنگ + همکاری)',
										'music-wave-core'
									),
								],
								[
									'song',
									__(
										'فقط سفارش آهنگ اختصاصی',
										'music-wave-core'
									),
								],
								[
									'collab',
									__(
										'فقط همکاری (هنری، برند، رویداد)',
										'music-wave-core'
									),
								],
							],
							__(
								'در حالت ترکیبی انتخابگر نوع درخواست نمایش داده می‌شود؛ در حالت‌های تک‌منظوره نوع درخواست به‌صورت خودکار تنظیم می‌شود.',
								'music-wave-core'
							),
						],
						[
							'select',
							'defaultType',
							__( 'نوع پیش‌فرض', 'music-wave-core' ),
							[
								[
									'',
									__( 'اولین نوع مجاز', 'music-wave-core' ),
								],
								[
									'song',
									__(
										'سفارش آهنگ اختصاصی',
										'music-wave-core'
									),
								],
								[
									'collab',
									__( 'همکاری هنری', 'music-wave-core' ),
								],
								[
									'advertising',
									__( 'تبلیغات و برند', 'music-wave-core' ),
								],
								[
									'event',
									__( 'اجرا و رویداد', 'music-wave-core' ),
								],
								[
									'other',
									__( 'موضوع دیگر', 'music-wave-core' ),
								],
							],
						],
						[
							'toggle',
							'showRoles',
							__( 'انتخاب نقش درخواست‌دهنده', 'music-wave-core' ),
							true,
						],
						[
							'select',
							'defaultRole',
							__( 'نقش پیش‌فرض', 'music-wave-core' ),
							[
								[ '', __( 'پیش‌فرض حالت', 'music-wave-core' ) ],
								[
									'singer',
									__( 'خواننده', 'music-wave-core' ),
								],
								[
									'producer',
									__(
										'تهیه‌کننده / آهنگساز',
										'music-wave-core'
									),
								],
								[
									'band',
									__( 'گروه موسیقی', 'music-wave-core' ),
								],
								[
									'advertiser',
									__(
										'برند / آژانس تبلیغاتی',
										'music-wave-core'
									),
								],
								[
									'business',
									__(
										'کسب‌وکار / رویداد',
										'music-wave-core'
									),
								],
								[
									'fan',
									__(
										'شنونده و علاقه‌مند',
										'music-wave-core'
									),
								],
								[ 'other', __( 'سایر', 'music-wave-core' ) ],
							],
						],
					],
				},
				{
					title: __( 'فیلدهای فرم', 'music-wave-core' ),
					controls: [
						[
							'toggle',
							'showBudget',
							__( 'بودجهٔ تقریبی', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showDeadline',
							__( 'زمان مورد نظر', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showPhone',
							__( 'شمارهٔ تماس', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showLinks',
							__( 'پیوندهای نمونه‌کار', 'music-wave-core' ),
							true,
						],
						[
							'text',
							'submitLabel',
							__( 'برچسب دکمهٔ ارسال', 'music-wave-core' ),
						],
					],
					help: __(
						'نام، ایمیل، عنوان و توضیحات همیشه الزامی‌اند. عنوان، معرفی و ستون کناری صفحه با بلوک‌های مستقل وردپرس روی بوم قابل چیدمان و ویرایش هستند.',
						'music-wave-core'
					),
				},
			],
		},
		'music-wave/shuffle-button': {
			releaseId: true,
			groups: [
				Object.assign(
					{},
					actionButtonAppearanceGroup(
						__(
							'متن دکمه پخش تصادفی در حالت «دکمه کامل با متن»؛ خالی = پیش‌فرض.',
							'music-wave-core'
						),
						'shuffle'
					),
					{
						help: __(
							'پخش تصادفی صف پیش‌نمایش را به‌صورت تصادفی مرتب می‌کند.',
							'music-wave-core'
						),
					}
				),
			],
		},
		'music-wave/add-to-queue': {
			releaseId: true,
			groups: [
				actionButtonAppearanceGroup(
					__(
						'برای استفاده از برچسب پخش بعدی یا افزودن به صف، خالی بگذارید.',
						'music-wave-core'
					),
					'queue'
				),
				{
					title: __( 'موقعیت افزودن به صف', 'music-wave-core' ),
					controls: [
						[
							'select',
							'position',
							__( 'موقعیت فرود در صف', 'music-wave-core' ),
							[
								[
									'next',
									__(
										'پخش بعدی (بلافاصله بعد از قطعه فعلی)',
										'music-wave-core'
									),
								],
								[
									'end',
									__(
										'افزودن به انتهای صف',
										'music-wave-core'
									),
								],
							],
						],
					],
				},
			],
		},
		'music-wave/public-playlists': {
			previewPlaylist: true,
			groups: [
				{
					title: __( 'سربرگ', 'music-wave-core' ),
					controls: [
						[
							'text',
							'eyebrow',
							__( 'ابرو', 'music-wave-core' ),
							'',
						],
						[
							'text',
							'heading',
							__( 'عنوان بخش', 'music-wave-core' ),
							'',
						],
						[
							'text',
							'intro',
							__( 'متن مقدمه', 'music-wave-core' ),
							'',
						],
						[
							'toggle',
							'showSearch',
							__( 'نمایش جست‌وجو', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showCount',
							__( 'نمایش کل فهرست پخش', 'music-wave-core' ),
							true,
						],
						[
							'text',
							'searchPlaceholder',
							__( 'متن جایگزین جست‌وجو', 'music-wave-core' ),
							'',
						],
						[
							'text',
							'emptyMessage',
							__( 'پیام حالت خالی', 'music-wave-core' ),
							'',
						],
						[
							'text',
							'sectionUrl',
							__( 'مشاهدهٔ همهٔ نشانی پیوند', 'music-wave-core' ),
							'',
							__(
								'پیوند سربرگ قفسه انتشار را منعکس می‌کند. خالی بگذارید تا پنهان شود.',
								'music-wave-core'
							),
						],
						[
							'text',
							'sectionLinkLabel',
							__(
								'برچسب پیوند «مشاهدهٔ همه»',
								'music-wave-core'
							),
							'',
							__(
								'برای به ارث بردن برچسب پیش‌فرض MusicWave ترجمه‌شده، خالی بگذارید.',
								'music-wave-core'
							),
						],
					],
				},
				{
					title: __( 'چیدمان', 'music-wave-core' ),
					controls: [
						[
							'select',
							'layout',
							__( 'چیدمان', 'music-wave-core' ),
							[
								[ 'grid', __( 'شبکه', 'music-wave-core' ) ],
								[
									'scroll',
									__( 'قفسه افقی', 'music-wave-core' ),
								],
								[ 'list', __( 'فهرست', 'music-wave-core' ) ],
							],
							__(
								'قفسه افقی با رفتار قفسه انتشار MusicWave مطابقت دارد.',
								'music-wave-core'
							),
						],
						[
							'range',
							'columns',
							__( 'ستون‌های شبکه', 'music-wave-core' ),
							2,
							6,
							undefined,
							3,
						],
						[
							'select',
							'imageShape',
							__( 'شکل اثر هنری', 'music-wave-core' ),
							[
								[ 'square', __( 'مربع', 'music-wave-core' ) ],
								[
									'landscape',
									__( 'منظره', 'music-wave-core' ),
								],
								[
									'portrait',
									__( 'پرتره', 'music-wave-core' ),
								],
								[ 'circle', __( 'دایره', 'music-wave-core' ) ],
							],
						],
						[
							'range',
							'itemsToShow',
							__( 'موارد در هر صفحه', 'music-wave-core' ),
							4,
							24,
							undefined,
							12,
						],
						[
							'select',
							'orderby',
							__( 'به ترتیب', 'music-wave-core' ),
							[
								[
									'updated_at',
									__(
										'به‌تازگی به‌روزشده',
										'music-wave-core'
									),
								],
								[
									'created_at',
									__( 'جدیدترین اول', 'music-wave-core' ),
								],
								[
									'title',
									__( 'عنوان A–Z', 'music-wave-core' ),
								],
							],
						],
						[
							'toggle',
							'showPagination',
							__( 'نمایش صفحه‌بندی', 'music-wave-core' ),
							true,
						],
					],
				},
				{
					title: __( 'مشخصات کارت', 'music-wave-core' ),
					controls: [
						[
							'toggle',
							'showArt',
							__( 'نمایش شبکهٔ تصاویر جلد', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showPlayButton',
							__( 'نمایش دکمه پخش', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showToggle',
							__(
								'نمایش تغییر فهرست قطعه‌ها',
								'music-wave-core'
							),
							true,
						],
						[
							'toggle',
							'showAuthor',
							__( 'نمایش نام متصدی', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showUpdated',
							__( 'نمایش تاریخ به‌روزرسانی', 'music-wave-core' ),
							true,
						],
					],
				},
			],
		},
		'music-wave/continue-listening': {
			groups: [
				{
					title: __( 'سربرگ', 'music-wave-core' ),
					controls: [
						[
							'text',
							'heading',
							__( 'عنوان بخش', 'music-wave-core' ),
							'',
							__(
								'برای استفاده از پیش‌فرض هوشمند، خالی بگذارید: به گوش‌دادن ادامه دهید یا اخیراً پخش‌شده است.',
								'music-wave-core'
							),
						],
						[
							'text',
							'intro',
							__( 'متن مقدمه', 'music-wave-core' ),
						],
						[
							'select',
							'source',
							__(
								'کدام فعالیت ریل را تغذیه می‌کند',
								'music-wave-core'
							),
							[
								[
									'continue',
									__(
										'انتشارهای در حال پیشرفت',
										'music-wave-core'
									),
								],
								[
									'played',
									__(
										'انتشارهای اخیراً پخش‌شده',
										'music-wave-core'
									),
								],
							],
						],
						[
							'text',
							'sectionUrl',
							__( 'مشاهدهٔ همهٔ نشانی پیوند', 'music-wave-core' ),
						],
						[
							'text',
							'sectionLinkLabel',
							__(
								'برچسب پیوند «مشاهدهٔ همه»',
								'music-wave-core'
							),
						],
					],
				},
				{
					title: __( 'چیدمان', 'music-wave-core' ),
					controls: [
						[
							'select',
							'layout',
							__( 'چیدمان', 'music-wave-core' ),
							[
								[
									'scroll',
									__( 'قفسه افقی', 'music-wave-core' ),
								],
								[ 'grid', __( 'شبکه', 'music-wave-core' ) ],
								[ 'list', __( 'فهرست', 'music-wave-core' ) ],
							],
							__(
								'از کروم اشتراک‌گذاری قفسه استفادهٔ مجدد می‌کند، بنابراین با قفسه‌های دیگر مطابقت دارد.',
								'music-wave-core'
							),
						],
						[
							'range',
							'columns',
							__( 'ستون‌های شبکه', 'music-wave-core' ),
							2,
							6,
							undefined,
							4,
						],
						[
							'range',
							'itemsToShow',
							__( 'حداکثر موارد', 'music-wave-core' ),
							2,
							24,
							undefined,
							8,
						],
						[
							'select',
							'imageShape',
							__( 'شکل اثر هنری', 'music-wave-core' ),
							[
								[ 'square', __( 'مربع', 'music-wave-core' ) ],
								[ 'circle', __( 'دایره', 'music-wave-core' ) ],
								[
									'landscape',
									__( 'منظره', 'music-wave-core' ),
								],
								[
									'portrait',
									__( 'پرتره', 'music-wave-core' ),
								],
							],
						],
						[
							'toggle',
							'showArtwork',
							__( 'نمایش آثار هنری', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showArtist',
							__( 'نمایش نام هنرمندان', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showWhen',
							__( 'نمایش زمان پخش قبلی', 'music-wave-core' ),
							true,
						],
						[
							'toggle',
							'showPreview',
							__( 'نمایش همپوشانی پخش', 'music-wave-core' ),
							true,
						],
					],
				},
				{
					title: __( 'پیام‌ها', 'music-wave-core' ),
					controls: [
						[
							'text',
							'emptyMessage',
							__( 'پیام حالت خالی', 'music-wave-core' ),
						],
						[
							'text',
							'guestMessage',
							__( 'درخواست مهمان', 'music-wave-core' ),
						],
						[
							'text',
							'consentMessage',
							__( 'متن پانل رضایت', 'music-wave-core' ),
						],
						[
							'text',
							'consentButtonLabel',
							__( 'برچسب دکمه رضایت', 'music-wave-core' ),
						],
					],
					help: __(
						'تاریخچه گوش‌دادن چیزی را ضبط نمی‌کند تا زمانی که هر شنونده ای را انتخاب کند. پس گرفتن رضایت، تاریخچه ذخیره‌شده را فوراً پاک می‌کند.',
						'music-wave-core'
					),
				},
			],
		},
	};

	/**
	 * Resolve the post the editor is currently rendering this block against.
	 *
	 * Inside a Query Loop the per-row context wins, so every card previews its
	 * own post; on a post editor screen the edited post is used; in the Site
	 * Editor there is no content post at all and the server resolves the latest
	 * published catalog release automatically.
	 *
	 * @param {Object} props Block edit props.
	 * @return {{postId: number, postType: string}|null} The contextual post.
	 */
	function useEditorContextPost( props ) {
		var context = props.context || {};
		var contextId = parseInt( context.postId, 10 );

		// Both selectors return primitives: @wordpress/data compares results by
		// identity, so a selector that built a fresh object on every store
		// change would re-render the block in a loop.
		var editorId = useSelect( function ( select ) {
			var editor = select ? select( 'core/editor' ) : null;
			if ( ! editor || ! editor.getCurrentPostId ) {
				return 0;
			}
			var id = parseInt( editor.getCurrentPostId(), 10 );
			return id > 0 ? id : 0;
		}, [] );

		var editorType = useSelect( function ( select ) {
			var editor = select ? select( 'core/editor' ) : null;
			if ( ! editor || ! editor.getCurrentPostType ) {
				return '';
			}
			return String( editor.getCurrentPostType() || '' );
		}, [] );

		if ( contextId > 0 ) {
			return {
				postId: contextId,
				postType: String( context.postType || '' ),
			};
		}

		if ( editorId > 0 ) {
			return { postId: editorId, postType: editorType };
		}

		return null;
	}

	/**
	 * Release the block should render when it is bound to the current post.
	 *
	 * @param {{postId: number, postType: string}|null} contextPost Contextual post.
	 * @return {number} The release ID, or 0 when the context is not a release.
	 */
	function editorReleaseId( contextPost ) {
		return contextPost && 'mw_release' === contextPost.postType
			? contextPost.postId
			: 0;
	}

	function editorSamplePreviewPanel(
		blockName,
		previewSample,
		setPreviewSample
	) {
		var config = fieldConfig[ blockName ] || {};
		var previews = window.musicWaveEditorPreviews || {};
		var rawItems = null;
		var label = '';
		var isQuery = false;

		if ( config.releaseId ) {
			rawItems = previews.releases;
			label = __(
				'انتشار یا آلبوم نمونه برای پیش‌نمایش',
				'music-wave-core'
			);
		} else if ( config.previewArtist ) {
			rawItems = previews.artists;
			label = __( 'هنرمند نمونه برای پیش‌نمایش', 'music-wave-core' );
		} else if ( config.previewTerm ) {
			rawItems = previews.terms;
			label = __(
				'اصطلاح نمونه (هنرمند / سبک / حال‌وهوا)',
				'music-wave-core'
			);
		} else if ( config.previewPlaylist ) {
			rawItems = previews.playlists;
			label = __( 'فهرست پخش نمونه برای پیش‌نمایش', 'music-wave-core' );
		} else if ( config.previewQuery ) {
			rawItems = previews.queries;
			label = __(
				'عبارت جستجوی نمونه برای پیش‌نمایش',
				'music-wave-core'
			);
			isQuery = true;
		}

		if ( ! Array.isArray( rawItems ) || ! rawItems.length ) {
			return null;
		}

		var selectOptions = [
			{
				label: __(
					'خودکار (محتوای واقعی فعلی سایت)',
					'music-wave-core'
				),
				value: '',
			},
		];
		rawItems.forEach( function ( item ) {
			if ( isQuery && 'string' === typeof item ) {
				selectOptions.push( { label: item, value: item } );
			} else if ( item && item.id ) {
				selectOptions.push( {
					label: String( item.title || item.name || '#' + item.id ),
					value: String( item.id ),
				} );
			}
		} );

		return createElement(
			components.PanelBody,
			{
				title: __( 'نمونه پیش‌نمایش در ویرایشگر', 'music-wave-core' ),
				initialOpen: false,
				key: 'mw-editor-sample-preview',
			},
			createElement( components.SelectControl, {
				label,
				value: previewSample || '',
				options: selectOptions,
				help: __(
					'فقط پیش‌نمایش داخل ویرایشگر را با داده‌های واقعی سایت تغییر می‌دهد و قالب فرانت را ثابت نمی‌کند.',
					'music-wave-core'
				),
				onChange( value ) {
					setPreviewSample( value || '' );
				},
			} )
		);
	}

	function renderCustomIconField( label, currentValue, onSelect ) {
		var previews = window.musicWaveEditorPreviews || {};
		var icons = Array.isArray( previews.icons ) ? previews.icons : [];

		return createElement(
			'div',
			{ className: 'mw-editor-icon-picker' },
			icons.length
				? createElement(
						'div',
						{
							className: 'mw-editor-icon-picker__presets',
							role: 'group',
							'aria-label': label,
						},
						createElement(
							'button',
							{
								type: 'button',
								className:
									'mw-editor-icon-picker__chip' +
									( ! currentValue ? ' is-active' : '' ),
								onClick() {
									onSelect( '' );
								},
								title: __(
									'بازگشت به آیکون پیش‌فرض استاندارد',
									'music-wave-core'
								),
							},
							__( 'پیش‌فرض', 'music-wave-core' )
						),
						icons.map( function ( item ) {
							var active = currentValue === item.slug;
							return createElement( 'button', {
								key: item.slug,
								type: 'button',
								className:
									'mw-editor-icon-picker__chip' +
									( active ? ' is-active' : '' ),
								onClick() {
									onSelect( active ? '' : item.slug );
								},
								title: item.label + ' (' + item.slug + ')',
								'aria-label': item.label,
								dangerouslySetInnerHTML: {
									__html: item.svg,
								},
							} );
						} )
				  )
				: null,
			createElement( components.TextControl, {
				label,
				value: currentValue || '',
				placeholder:
					'play-filled / dashicons dashicons-controls-play / <svg>…',
				help: __(
					'روش ۱: روی یکی از آیکون‌های بالا کلیک کنید. روش ۲: کلاس CSS فونت آیکون (مثل dashicons dashicons-download) بنویسید. روش ۳: کد <svg>…</svg> دلخواه را جای‌گذاری کنید (خالی = آیکون پیش‌فرض).',
					'music-wave-core'
				),
				onChange( value ) {
					onSelect( value );
				},
			} )
		);
	}

	function artistProfilePanels( props ) {
		// Mirrors ArtistProfileBlock::resolve_layout(): `layout` decides, the legacy
		// `compact` boolean only promotes the default `list`, `slider` is the
		// retired slug for `spotlight`.
		var rawLayout = props.attributes.layout || 'list';
		if ( 'slider' === rawLayout ) {
			rawLayout = 'spotlight';
		}
		if ( 'list' === rawLayout && props.attributes.compact ) {
			rawLayout = 'compact';
		}
		var activeLayout =
			-1 !==
			[ 'compact', 'list', 'card', 'spotlight' ].indexOf( rawLayout )
				? rawLayout
				: 'list';
		var isCompact = 'compact' === activeLayout;
		var showImage = false !== props.attributes.showImage;
		var showBio = ! isCompact && false !== props.attributes.showBio;
		var showLink = ! isCompact && false !== props.attributes.showLink;
		var showLibraryButton = false !== props.attributes.showLibraryButton;
		var managedStyles = [ 'editorial', 'minimal', 'glass' ];
		var currentStyle = 'editorial';
		( props.attributes.className || '' )
			.split( /\s+/ )
			.forEach( function ( token ) {
				if ( 0 === token.indexOf( 'is-style-' ) ) {
					var slug = token.slice( 9 );
					if ( -1 !== managedStyles.indexOf( slug ) ) {
						currentStyle = slug;
					}
				}
			} );

		return [
			createElement(
				components.PanelBody,
				{
					title: __(
						'چیدمان و منبع پروفایل هنرمند',
						'music-wave-core'
					),
					initialOpen: true,
					key: 'artist-source-layout',
				},
				createElement( components.SelectControl, {
					key: 'layout',
					label: __( 'الگوی چیدمان پروفایل', 'music-wave-core' ),
					value: activeLayout,
					options: [
						{
							label: __(
								'چیپ جمع‌وجور (مناسب بالای صفحه تک‌آهنگ)',
								'music-wave-core'
							),
							value: 'compact',
						},
						{
							label: __(
								'کارت افقی متعادل (کنار هم)',
								'music-wave-core'
							),
							value: 'list',
						},
						{
							label: __( 'کارت پرتره عمودی', 'music-wave-core' ),
							value: 'card',
						},
						{
							label: __(
								'بنر شاخص هنرمند (Spotlight)',
								'music-wave-core'
							),
							value: 'spotlight',
						},
					],
					onChange( value ) {
						props.setAttributes( {
							layout: value,
							compact: 'compact' === value,
						} );
					},
				} ),
				createElement( components.SelectControl, {
					key: 'artistStyleVariant',
					label: __( 'سبک ظاهری کارت', 'music-wave-core' ),
					value: currentStyle,
					options: [
						{
							label: __( 'تحریریه', 'music-wave-core' ),
							value: 'editorial',
						},
						{
							label: __( 'مینیمال', 'music-wave-core' ),
							value: 'minimal',
						},
						{
							label: __( 'شیشه‌ای (گلس)', 'music-wave-core' ),
							value: 'glass',
						},
					],
					onChange( value ) {
						var tokens = ( props.attributes.className || '' )
							.split( /\s+/ )
							.filter( function ( token ) {
								if ( ! token ) {
									return false;
								}
								if ( 0 === token.indexOf( 'is-style-' ) ) {
									return (
										-1 ===
										managedStyles.indexOf(
											token.slice( 9 )
										)
									);
								}
								return true;
							} );
						if ( value && 'editorial' !== value ) {
							tokens.push( 'is-style-' + value );
						}
						props.setAttributes( {
							className: tokens.join( ' ' ),
						} );
					},
				} ),
				createElement( components.TextControl, {
					key: 'termId',
					label: __(
						'شناسه هنرمند ثابت (اختیاری)',
						'music-wave-core'
					),
					help: __(
						'خالی بگذارید تا هنرمند صفحه فعلی به شکل خودکار نمایش داده شود.',
						'music-wave-core'
					),
					value: props.attributes.termId
						? String( props.attributes.termId )
						: '',
					onChange( value ) {
						var id = parseInt( value, 10 );
						props.setAttributes( { termId: isNaN( id ) ? 0 : id } );
					},
				} )
			),
			createElement(
				components.PanelBody,
				{
					title: __( 'آواتار و رنگ تأکیدی', 'music-wave-core' ),
					initialOpen: false,
					key: 'artist-media-color',
				},
				createElement( components.ToggleControl, {
					key: 'showImage',
					label: __(
						'نمایش تصویر / آواتار هنرمند',
						'music-wave-core'
					),
					checked: showImage,
					onChange( value ) {
						props.setAttributes( { showImage: !! value } );
					},
				} ),
				showImage
					? createElement( components.SelectControl, {
							key: 'imageShape',
							label: __( 'فرم آواتار', 'music-wave-core' ),
							value: props.attributes.imageShape || 'circle',
							options: [
								{
									label: __( 'دایره‌ای', 'music-wave-core' ),
									value: 'circle',
								},
								{
									label: __(
										'گوشه‌گرد مدرن',
										'music-wave-core'
									),
									value: 'rounded',
								},
								{
									label: __( 'مربعی', 'music-wave-core' ),
									value: 'square',
								},
							],
							onChange( value ) {
								props.setAttributes( { imageShape: value } );
							},
					  } )
					: null,
				showImage
					? createElement( components.SelectControl, {
							key: 'imageSize',
							label: __(
								'کیفیت و اندازه تصویر',
								'music-wave-core'
							),
							value: props.attributes.imageSize || 'medium',
							options: [
								{
									label: __(
										'بندانگشتی (سریع)',
										'music-wave-core'
									),
									value: 'thumbnail',
								},
								{
									label: __( 'متوسط', 'music-wave-core' ),
									value: 'medium',
								},
								{
									label: __(
										'متوسط رو به بزرگ',
										'music-wave-core'
									),
									value: 'medium_large',
								},
								{
									label: __(
										'بزرگ (کیفیت کامل)',
										'music-wave-core'
									),
									value: 'large',
								},
							],
							onChange( value ) {
								props.setAttributes( { imageSize: value } );
							},
					  } )
					: null,
				createElement(
					'div',
					{
						key: 'accentColor',
						className: 'mw-block-editor-color-field',
					},
					createElement(
						'p',
						{ className: 'components-base-control__label' },
						__( 'رنگ تأکیدی اختصاصی', 'music-wave-core' )
					),
					createElement( components.ColorPalette, {
						value: props.attributes.accentColor || undefined,
						clearable: true,
						onChange( color ) {
							props.setAttributes( {
								accentColor: color || '',
							} );
						},
					} )
				)
			),
			createElement(
				components.PanelBody,
				{
					title: __( 'محتوا و دکمه‌ها', 'music-wave-core' ),
					initialOpen: false,
					key: 'artist-content-actions',
				},
				createElement( components.ToggleControl, {
					key: 'showTitle',
					label: __( 'نمایش نام هنرمند', 'music-wave-core' ),
					checked: false !== props.attributes.showTitle,
					onChange( value ) {
						props.setAttributes( { showTitle: !! value } );
					},
				} ),
				createElement( components.ToggleControl, {
					key: 'showReleaseCount',
					label: __( 'نمایش تعداد آثار منتشرشده', 'music-wave-core' ),
					checked: false !== props.attributes.showReleaseCount,
					onChange( value ) {
						props.setAttributes( { showReleaseCount: !! value } );
					},
				} ),
				! isCompact
					? createElement( components.ToggleControl, {
							key: 'showBio',
							label: __(
								'نمایش بیوگرافی کوتاه',
								'music-wave-core'
							),
							checked: showBio,
							onChange( value ) {
								props.setAttributes( { showBio: !! value } );
							},
					  } )
					: null,
				showBio
					? createElement( components.RangeControl, {
							key: 'bioLength',
							label: __(
								'حداکثر کلمات بیوگرافی (۰ = کامل)',
								'music-wave-core'
							),
							value: props.attributes.bioLength || 0,
							min: 0,
							max: 200,
							onChange( value ) {
								props.setAttributes( {
									bioLength: parseInt( value, 10 ) || 0,
								} );
							},
					  } )
					: null,
				createElement( components.ToggleControl, {
					key: 'showLibraryButton',
					label: __(
						'نمایش دکمه دنبال‌کردن هنرمند',
						'music-wave-core'
					),
					checked: showLibraryButton,
					onChange( value ) {
						props.setAttributes( {
							showLibraryButton: !! value,
						} );
					},
				} ),
				showLibraryButton
					? createElement( components.SelectControl, {
							key: 'libraryButtonStyle',
							label: __(
								'سبک دکمه دنبال‌کردن',
								'music-wave-core'
							),
							value:
								props.attributes.libraryButtonStyle || 'button',
							options: [
								{
									label: __(
										'کپسول همراه با متن',
										'music-wave-core'
									),
									value: 'button',
								},
								{
									label: __(
										'فقط آیکون دنبال‌کردن',
										'music-wave-core'
									),
									value: 'heart',
								},
							],
							onChange( value ) {
								props.setAttributes( {
									libraryButtonStyle: value,
								} );
							},
					  } )
					: null,
				showLibraryButton
					? renderCustomIconField(
							__(
								'آیکون سفارشی دنبال‌کردن (اختیاری)',
								'music-wave-core'
							),
							props.attributes.followIcon || '',
							function ( value ) {
								props.setAttributes( {
									followIcon: value || '',
								} );
							}
					  )
					: null,
				! isCompact
					? createElement( components.ToggleControl, {
							key: 'showLink',
							label: __(
								'نمایش دکمه مشاهده همه آثار',
								'music-wave-core'
							),
							checked: showLink,
							onChange( value ) {
								props.setAttributes( { showLink: !! value } );
							},
					  } )
					: null,
				showLink
					? createElement( components.TextControl, {
							key: 'ctaLabel',
							label: __(
								'متن دکمه مشاهده آثار',
								'music-wave-core'
							),
							help: __(
								'برای استفاده از برچسب پیش‌فرض خالی بگذارید.',
								'music-wave-core'
							),
							value: props.attributes.ctaLabel || '',
							onChange( value ) {
								props.setAttributes( { ctaLabel: value } );
							},
					  } )
					: null
			),
		];
	}

	/**
	 * Build one inspector control from a declarative group descriptor.
	 *
	 * Descriptor formats:
	 * ['text', attr, label, default?, help?]             TextControl
	 * ['intText', attr, label]                           TextControl storing an integer
	 * ['toggle', attr, label, default, help?]            ToggleControl
	 * ['select', attr, label, [[value, label]], help?]   SelectControl
	 * ['range', attr, label, min, max, help?, fallback?]   RangeControl
	 */
	function buildGroupControl( descriptor, props, blockName ) {
		var type = descriptor[ 0 ];
		var attr = descriptor[ 1 ];
		var label = descriptor[ 2 ];

		if (
			'music-wave/release-meta' === blockName &&
			'layout' === attr &&
			false !== props.attributes.compact
		) {
			return null;
		}
		if (
			'music-wave/library-button' === blockName &&
			'termId' === attr &&
			'artist' !== props.attributes.itemType &&
			! ( props.attributes.termId && props.attributes.termId > 0 )
		) {
			return null;
		}
		// Context-aware Inspector cleanup: hide fields that do not apply to the active mode.
		var curBtnMode =
			props.attributes.buttonStyle ||
			( 'string' === typeof props.attributes.style
				? props.attributes.style
				: '' );
		if (
			( 'icon' === curBtnMode || 'heart' === curBtnMode ) &&
			-1 !==
				[
					'label',
					'downloadLabel',
					'addedLabel',
					'showIcon',
					'fullWidth',
				].indexOf( attr )
		) {
			return null;
		}
		// Compact download-button hides panel-only heading, description, and guest prompt fields.
		if ( 'music-wave/download-button' === blockName ) {
			if (
				props.attributes.compact &&
				( -1 !== [ 'heading', 'description' ].indexOf( attr ) ||
					-1 !==
						[
							'showHeading',
							'showDescription',
							'loginLabel',
						].indexOf( attr ) )
			) {
				return null;
			}
			if (
				'heading' === attr &&
				false === props.attributes.showHeading
			) {
				return null;
			}
			if (
				'description' === attr &&
				false === props.attributes.showDescription
			) {
				return null;
			}
			if (
				'playLabel' === attr &&
				false === props.attributes.showStream
			) {
				return null;
			}
		}
		if (
			'columns' === attr &&
			props.attributes.layout &&
			'grid' !== props.attributes.layout &&
			'bento' !== props.attributes.layout
		) {
			return null;
		}
		if (
			'term-hero' === blockName.replace( 'music-wave/', '' ) &&
			( ( 'size' === attr && 'compact' === props.attributes.layout ) ||
				( 'imageShape' === attr &&
					'compact' !== props.attributes.layout ) )
		) {
			return null;
		}
		if (
			'descriptionLength' === attr &&
			false === props.attributes.showDescription
		) {
			return null;
		}
		// Artists index pagination: page size only matters while it is on, and
		// paging is impossible for curated or shuffled shelves.
		if ( 'music-wave/artists-shelf' === blockName ) {
			if ( 'perPage' === attr && ! props.attributes.pagination ) {
				return null;
			}
			if (
				'pagination' === attr &&
				( ( props.attributes.source &&
					'all' !== props.attributes.source ) ||
					'rand' === props.attributes.orderBy )
			) {
				return null;
			}
		}
		if ( 'bioLength' === attr && ! props.attributes.showBio ) {
			return null;
		}
		if (
			( 'artistIds' === attr || 'termIds' === attr ) &&
			'manual' !== props.attributes.source
		) {
			return null;
		}
		if (
			'termSlug' === attr &&
			props.attributes.source &&
			-1 === [ 'genre', 'mood' ].indexOf( props.attributes.source )
		) {
			return null;
		}
		if (
			'sectionLinkLabel' === attr &&
			Object.prototype.hasOwnProperty.call(
				props.attributes,
				'sectionUrl'
			) &&
			! props.attributes.sectionUrl
		) {
			return null;
		}
		if ( 'actionLabel' === attr && ! props.attributes.showAction ) {
			return null;
		}
		if (
			'playAllLabel' === attr &&
			false === props.attributes.showPlayAll
		) {
			return null;
		}
		if (
			'shuffleLabel' === attr &&
			false === props.attributes.showShuffle
		) {
			return null;
		}
		if ( 'music-wave/collection-list' === blockName ) {
			if (
				( 'heading' === attr || 'showTotalDuration' === attr ) &&
				false === props.attributes.showHeading
			) {
				return null;
			}
			if (
				'playIcon' === attr &&
				false === props.attributes.showPreview
			) {
				return null;
			}
			if (
				( 'showDownloadQuality' === attr || 'downloadIcon' === attr ) &&
				false === props.attributes.showDownload
			) {
				return null;
			}
		}
		if ( 'text' === type ) {
			return createElement( components.TextControl, {
				key: attr,
				label,
				value: props.attributes[ attr ] || '',
				help:
					'music-wave/related-releases' === blockName &&
					[ 'sameArtistHeading', 'similarHeading' ].indexOf(
						attr
					) !== -1
						? __(
								'هر دو عنوان را خالی بگذارید تا عنوان را با بلوک مستقل بسازید؛ خالی بماند تا سربرگ این بخش چاپ نشود.',
								'music-wave-core'
						  )
						: descriptor[ 4 ] || undefined,
				onChange( value ) {
					props.setAttributes(
						attributeUpdate( props, blockName, attr, value )
					);
				},
			} );
		}

		if ( 'intText' === type ) {
			return createElement( components.TextControl, {
				key: attr,
				label,
				value: props.attributes[ attr ]
					? String( props.attributes[ attr ] )
					: '',
				onChange( value ) {
					var parsed = parseInt( value, 10 );
					props.setAttributes(
						attributeUpdate(
							props,
							blockName,
							attr,
							isNaN( parsed ) ? 0 : parsed
						)
					);
				},
			} );
		}

		if ( 'toggle' === type ) {
			return createElement( components.ToggleControl, {
				key: attr,
				label,
				checked:
					props.attributes[ attr ] === undefined
						? !! descriptor[ 3 ]
						: !! props.attributes[ attr ],
				help: descriptor[ 4 ] || undefined,
				onChange( value ) {
					props.setAttributes(
						attributeUpdate( props, blockName, attr, !! value )
					);
				},
			} );
		}

		if ( 'select' === type ) {
			var selectOpts = descriptor[ 3 ] || [];
			var selectVal = props.attributes[ attr ];
			if (
				'music-wave/library-button' === blockName &&
				'buttonStyle' === attr &&
				selectVal &&
				'heart' !== selectVal &&
				'button' !== selectVal
			) {
				selectVal = 'button';
			}
			var syncCfg = styleBackedAttributes[ blockName ];
			if ( syncCfg && syncCfg.attribute === attr ) {
				var syncList = syncCfg.styles || [ syncCfg.style ];
				var activeCls = String( props.attributes.className || '' )
					.split( /\s+/ )
					.find( function ( token ) {
						return (
							0 === token.indexOf( 'is-style-' ) &&
							-1 !==
								syncList.indexOf(
									token.slice( 'is-style-'.length )
								)
						);
					} );
				if ( activeCls ) {
					selectVal = activeCls.slice( 'is-style-'.length );
				}
			}
			return createElement( components.SelectControl, {
				key: attr,
				label,
				value:
					selectVal ||
					( selectOpts[ 0 ] ? selectOpts[ 0 ][ 0 ] : '' ),
				options: selectOpts.map( function ( option ) {
					return { value: option[ 0 ], label: option[ 1 ] };
				} ),
				help: descriptor[ 4 ] || undefined,
				onChange( value ) {
					props.setAttributes(
						attributeUpdate( props, blockName, attr, value )
					);
				},
			} );
		}

		if ( 'customIcon' === type ) {
			return createElement(
				'div',
				{ key: attr },
				renderCustomIconField(
					label,
					props.attributes[ attr ] || '',
					function ( value ) {
						props.setAttributes(
							attributeUpdate(
								props,
								blockName,
								attr,
								value || ''
							)
						);
					}
				)
			);
		}

		if ( 'styleClass' === type ) {
			var managedStyles = descriptor[ 1 ] || [];
			var defaultStyle = descriptor[ 2 ] || '';
			var activeStyle = defaultStyle;
			String( ( props.attributes || {} ).className || '' )
				.split( /\s+/ )
				.forEach( function ( part ) {
					if ( 0 === part.indexOf( 'is-style-' ) ) {
						var slug = part.slice( 'is-style-'.length );
						if ( -1 !== managedStyles.indexOf( slug ) ) {
							activeStyle = slug;
						}
					}
				} );
			return createElement( components.SelectControl, {
				key: 'styleClass-' + managedStyles.join( '-' ),
				label: descriptor[ 3 ],
				value: activeStyle,
				options: ( descriptor[ 4 ] || [] ).map( function ( option ) {
					return { value: option[ 0 ], label: option[ 1 ] };
				} ),
				onChange( value ) {
					var kept = withoutBlockStyles(
						( props.attributes || {} ).className,
						managedStyles
					);
					if (
						value &&
						value !== defaultStyle &&
						-1 !== managedStyles.indexOf( value )
					) {
						kept.push( 'is-style-' + value );
					}
					props.setAttributes( { className: kept.join( ' ' ) } );
				},
			} );
		}

		if ( 'range' === type ) {
			return createElement( components.RangeControl, {
				key: attr,
				label,
				value:
					props.attributes[ attr ] ||
					descriptor[ 6 ] ||
					descriptor[ 3 ],
				min: descriptor[ 3 ],
				max: descriptor[ 4 ],
				help: descriptor[ 5 ] || undefined,
				onChange( value ) {
					props.setAttributes(
						attributeUpdate(
							props,
							blockName,
							attr,
							parseInt( value, 10 ) || descriptor[ 3 ]
						)
					);
				},
			} );
		}

		return null;
	}

	/*
	 * Split the current className into the parts we keep and the variation
	 * class we are about to write. Only styles registered for this block are
	 * removed, so classes from other plugins survive untouched.
	 */
	function withoutBlockStyles( className, names ) {
		return String( className || '' )
			.split( /\s+/ )
			.filter( function ( part ) {
				if ( ! part ) {
					return false;
				}
				if ( 0 !== part.indexOf( 'is-style-' ) ) {
					return true;
				}
				return -1 === names.indexOf( part.slice( 'is-style-'.length ) );
			} );
	}

	/*
	 * Attributes that predate, and duplicate, a registered block style. Both
	 * switches produce the same look and the style wins on the server, so
	 * changing one has to update the other; otherwise a control appears to do
	 * nothing while the other one holds the value. `fallback` is the attribute
	 * value that matches the default (style-less) look.
	 */
	var DASHBOARD_PANEL_META = [
		{
			id: 'library',
			toggle: 'showLibrary',
			label: __( 'کتابخانه موسیقی', 'music-wave-core' ),
		},
		{
			id: 'playlists',
			toggle: 'showPlaylists',
			label: __( 'فهرست‌های پخش', 'music-wave-core' ),
		},
		{
			id: 'orders',
			toggle: 'showOrders',
			label: __( 'سفارش‌ها', 'music-wave-core' ),
		},
		{
			id: 'downloads',
			toggle: 'showDownloads',
			label: __( 'دانلودها', 'music-wave-core' ),
		},
		{
			id: 'addresses',
			toggle: 'showAddresses',
			label: __( 'آدرس‌ها', 'music-wave-core' ),
		},
		{
			id: 'payment',
			toggle: 'showPaymentMethods',
			label: __( 'روش‌های پرداخت', 'music-wave-core' ),
		},
		{
			id: 'account',
			toggle: 'showAccountDetails',
			label: __( 'جزئیات و ویرایش حساب', 'music-wave-core' ),
		},
		{
			id: 'requests',
			toggle: 'showRequests',
			label: __( 'پیگیری درخواست‌ها', 'music-wave-core' ),
		},
		{
			id: 'membership',
			toggle: 'showMembershipPanel',
			label: __( 'عضویت ویژه (VIP)', 'music-wave-core' ),
		},
	];

	var DASHBOARD_PRESETS = {
		default: [
			'library',
			'playlists',
			'orders',
			'downloads',
			'addresses',
			'payment',
			'account',
			'requests',
			'membership',
		],
		commerce_first: [
			'orders',
			'downloads',
			'addresses',
			'payment',
			'account',
			'requests',
			'library',
			'playlists',
			'membership',
		],
		membership_first: [
			'membership',
			'library',
			'playlists',
			'orders',
			'downloads',
			'addresses',
			'payment',
			'account',
			'requests',
		],
	};

	function resolveDashboardOrder( rawOrder ) {
		var trimmed = ( rawOrder || 'default' ).trim();
		if ( DASHBOARD_PRESETS[ trimmed ] ) {
			return DASHBOARD_PRESETS[ trimmed ].slice();
		}
		var aliases = {
			'payment-methods': 'payment',
			'account-details': 'account',
		};
		var validIds = DASHBOARD_PRESETS.default;
		var parsed = trimmed
			.split( ',' )
			.map( function ( item ) {
				var clean = item.trim();
				return aliases[ clean ] || clean;
			} )
			.filter( function ( item, idx, arr ) {
				return (
					validIds.indexOf( item ) !== -1 &&
					arr.indexOf( item ) === idx
				);
			} );
		validIds.forEach( function ( item ) {
			if ( parsed.indexOf( item ) === -1 ) {
				parsed.push( item );
			}
		} );
		return parsed;
	}

	function dashboardPanelOrderControl( props ) {
		var attributes = props.attributes;
		var setAttributes = props.setAttributes;
		var rawOrder = attributes.panelOrder || 'default';
		var orderedIds = resolveDashboardOrder( rawOrder );
		var presetValue = DASHBOARD_PRESETS[ rawOrder ] ? rawOrder : 'custom';

		function movePanel( index, delta ) {
			var target = index + delta;
			if ( target < 0 || target >= orderedIds.length ) {
				return;
			}
			var next = orderedIds.slice();
			var moved = next[ index ];
			next[ index ] = next[ target ];
			next[ target ] = moved;
			setAttributes( { panelOrder: next.join( ',' ) } );
		}

		var metaMap = {};
		DASHBOARD_PANEL_META.forEach( function ( item ) {
			metaMap[ item.id ] = item;
		} );

		return createElement(
			components.PanelBody,
			{
				title: __( 'ترتیب و چیدمان پنل‌ها', 'music-wave-core' ),
				initialOpen: true,
				key: 'dashboard-panel-order',
			},
			createElement( components.SelectControl, {
				label: __( 'الگوی چیدمان پنل‌ها', 'music-wave-core' ),
				value: presetValue,
				options: [
					{
						value: 'default',
						label: __(
							'موسیقی در ابتدا (پیش‌فرض)',
							'music-wave-core'
						),
					},
					{
						value: 'commerce_first',
						label: __(
							'فروشگاه و سفارش‌ها در ابتدا',
							'music-wave-core'
						),
					},
					{
						value: 'membership_first',
						label: __( 'عضویت ویژه در ابتدا', 'music-wave-core' ),
					},
					{
						value: 'custom',
						label: __( 'چیدمان سفارشی', 'music-wave-core' ),
					},
				],
				onChange( next ) {
					if ( DASHBOARD_PRESETS[ next ] ) {
						setAttributes( { panelOrder: next } );
					}
				},
			} ),
			createElement(
				'div',
				{
					style: {
						display: 'flex',
						flexDirection: 'column',
						gap: '6px',
						marginTop: '8px',
					},
				},
				orderedIds.map( function ( id, idx ) {
					var info = metaMap[ id ];
					if ( ! info ) {
						return null;
					}
					var isEnabled = false !== attributes[ info.toggle ];
					return createElement(
						'div',
						{
							key: id,
							style: {
								display: 'flex',
								alignItems: 'center',
								justifyContent: 'space-between',
								gap: '6px',
								padding: '6px 8px',
								borderRadius: '6px',
								border: '1px solid #ddd',
								background: isEnabled ? '#fff' : '#f6f7f7',
								opacity: isEnabled ? 1 : 0.6,
							},
						},
						createElement(
							'span',
							{
								style: {
									fontSize: '12px',
									fontWeight: 500,
									flex: '1 1 auto',
								},
							},
							String( idx + 1 ) + '. ' + info.label
						),
						createElement(
							'div',
							{
								style: {
									display: 'inline-flex',
									gap: '4px',
								},
							},
							createElement( components.Button, {
								size: 'small',
								variant: 'secondary',
								disabled: 0 === idx,
								label: __(
									'انتقال به بالا',
									'music-wave-core'
								),
								onClick() {
									movePanel( idx, -1 );
								},
								text: '↑',
							} ),
							createElement( components.Button, {
								size: 'small',
								variant: 'secondary',
								disabled: orderedIds.length - 1 === idx,
								label: __(
									'انتقال به پایین',
									'music-wave-core'
								),
								onClick() {
									movePanel( idx, 1 );
								},
								text: '↓',
							} )
						)
					);
				} )
			),
			createElement(
				'p',
				{
					style: {
						margin: '8px 0 0',
						fontSize: '12px',
						color: '#757575',
					},
				},
				__(
					'با دکمه‌های ↑ و ↓ ترتیب دقیق نمایش زبانه‌ها و پنل‌ها را در داشبورد تنظیم کنید.',
					'music-wave-core'
				)
			)
		);
	}

	var styleBackedAttributes = {
		'music-wave/preview-player': {
			attribute: 'variant',
			styles: [ 'outline', 'ghost' ],
			fallback: 'accent',
		},
		'music-wave/preview-button': {
			attribute: 'buttonStyle',
			styles: [ 'solid', 'outline', 'ghost', 'vinyl' ],
			fallback: 'solid',
		},
		'music-wave/release-meta': {
			attribute: 'layout',
			styles: [ 'grid', 'inline', 'stack' ],
			fallback: 'grid',
		},
		'music-wave/release-credits': {
			attribute: 'layout',
			styles: [ 'list', 'grid', 'inline' ],
			fallback: 'list',
		},
		'music-wave/catalog-filters': {
			attribute: 'layout',
			style: 'stacked',
			fallback: 'inline',
		},
	};

	/**
	 * Build a control's attribute update, keeping any style-backed attribute
	 * and its block style in agreement.
	 *
	 * @param {Object} props     Block edit props.
	 * @param {string} blockName Registered block name.
	 * @param {string} attribute Attribute the control writes.
	 * @param {*}      value     New attribute value.
	 * @return {Object} Attributes to hand to setAttributes().
	 */
	function attributeUpdate( props, blockName, attribute, value ) {
		var update = {};
		update[ attribute ] = value;

		var sync = styleBackedAttributes[ blockName ];
		if ( ! sync || sync.attribute !== attribute ) {
			return update;
		}

		var managed = sync.styles || [ sync.style ];
		var className = withoutBlockStyles(
			( props.attributes || {} ).className,
			managed
		);
		if (
			value &&
			value !== sync.fallback &&
			-1 !== managed.indexOf( value )
		) {
			className.push( 'is-style-' + value );
		}
		update.className = className.join( ' ' );

		return update;
	}

	function inspectorControls(
		props,
		blockName,
		previewSample,
		setPreviewSample
	) {
		var config = fieldConfig[ blockName ] || {};
		var controls = [];
		var customPanels = [];
		var samplePanel = editorSamplePreviewPanel(
			blockName,
			previewSample,
			setPreviewSample
		);
		if ( samplePanel ) {
			customPanels.push( samplePanel );
		}

		if ( config.artistProfile ) {
			customPanels = customPanels.concat( artistProfilePanels( props ) );
		}

		if ( config.compact ) {
			controls.push(
				createElement( components.ToggleControl, {
					key: 'compact',
					label: __( 'حالت فشرده', 'music-wave-core' ),
					checked: config.compactDefault
						? false !== props.attributes.compact
						: !! props.attributes.compact,
					onChange( value ) {
						props.setAttributes( { compact: !! value } );
					},
					help:
						'music-wave/release-meta' === blockName
							? __(
									'مشخصات را بدون کادر و در یک خط با جداکننده نقطه (·) نمایش می‌دهد.',
									'music-wave-core'
							  )
							: __(
									'از چیدمان متراکم‌تر برای کارت‌ها، فهرست‌ها و نوارهای کناری استفاده کنید.',
									'music-wave-core'
							  ),
				} )
			);
		}

		( config.textFields || [] ).forEach( function ( field ) {
			controls.push(
				createElement( components.TextControl, {
					key: field[ 0 ],
					label: field[ 1 ],
					value: props.attributes[ field[ 0 ] ] || '',
					onChange( value ) {
						var update = {};
						update[ field[ 0 ] ] = value;
						props.setAttributes( update );
					},
					help: __(
						'برای به ارث بردن برچسب پیش‌فرض MusicWave ترجمه‌شده، خالی بگذارید.',
						'music-wave-core'
					),
				} )
			);
		} );

		( config.toggles || [] ).forEach( function ( field ) {
			controls.push(
				createElement( components.ToggleControl, {
					key: field[ 0 ],
					label: field[ 1 ],
					checked: false !== props.attributes[ field[ 0 ] ],
					onChange( value ) {
						var update = {};
						update[ field[ 0 ] ] = !! value;
						props.setAttributes( update );
					},
				} )
			);
		} );

		if ( config.range ) {
			controls.push(
				createElement( components.RangeControl, {
					key: config.range[ 0 ],
					label: config.range[ 1 ],
					value: props.attributes[ config.range[ 0 ] ] || 4,
					min: config.range[ 2 ],
					max: config.range[ 3 ],
					onChange( value ) {
						var update = {};
						update[ config.range[ 0 ] ] =
							parseInt( value, 10 ) || 4;
						props.setAttributes( update );
					},
				} )
			);
		}

		if (
			! controls.length &&
			! customPanels.length &&
			! config.groups &&
			! config.dashboardPanelSorter
		) {
			return null;
		}

		var panels = customPanels.slice();
		if ( controls.length ) {
			panels.push(
				createElement(
					components.PanelBody,
					{
						title: __(
							'تنظیمات بلوک MusicWave',
							'music-wave-core'
						),
						initialOpen: true,
						key: 'music-wave-settings',
					},
					controls
				)
			);
		}
		if ( config.dashboardPanelSorter ) {
			panels.push( dashboardPanelOrderControl( props ) );
		}

		( config.groups || [] ).forEach( function ( group, index ) {
			var groupControls = ( group.controls || [] )
				.map( function ( descriptor ) {
					return buildGroupControl( descriptor, props, blockName );
				} )
				.filter( function ( control ) {
					return null !== control;
				} );
			if ( group.help ) {
				groupControls.push(
					createElement(
						'p',
						{
							className: 'components-base-control__help',
							key: 'group-help',
						},
						group.help
					)
				);
			}
			if ( groupControls.length ) {
				panels.push(
					createElement(
						components.PanelBody,
						{
							title: group.title,
							initialOpen: false,
							key: 'music-wave-group-' + index,
						},
						groupControls
					)
				);
			}
		} );

		return panels.length
			? createElement( blockEditor.InspectorControls, null, panels )
			: null;
	}

	function editorFallbackPreview( block, errorMessage ) {
		return createElement(
			'div',
			{
				className:
					'mw-editor-live-fallback' +
					( errorMessage ? ' mw-editor-live-fallback--error' : '' ),
				'data-mw-block': block.name,
			},
			createElement( 'span', {
				className: errorMessage
					? 'dashicons dashicons-warning'
					: 'dashicons dashicons-controls-play',
				'aria-hidden': 'true',
			} ),
			createElement( 'span', null, block.title ),
			errorMessage
				? createElement(
						'span',
						{ className: 'mw-editor-live-fallback__reason' },
						String( errorMessage )
				  )
				: null
		);
	}

	function sanitizeSsrAttributes( block, rawAttributes, allowedAttrs ) {
		var source = rawAttributes || {};
		var attrs = {};

		if ( allowedAttrs && Object.keys( allowedAttrs ).length ) {
			Object.keys( source ).forEach( function ( key ) {
				if (
					! WP_RESERVED_SUPPORT_ATTRS[ key ] &&
					Object.prototype.hasOwnProperty.call( allowedAttrs, key )
				) {
					attrs[ key ] = source[ key ];
				}
			} );
		} else {
			Object.keys( source ).forEach( function ( key ) {
				if ( ! WP_RESERVED_SUPPORT_ATTRS[ key ] ) {
					attrs[ key ] = source[ key ];
				}
			} );
		}

		// Seamlessly forward legacy string `attributes.style` saved in pre-existing
		// DB templates into `buttonStyle` without ever triggering WP REST 400 errors.
		if ( 'string' === typeof source.style && source.style ) {
			var canReceiveBtnStyle =
				! allowedAttrs ||
				Object.prototype.hasOwnProperty.call(
					allowedAttrs,
					'buttonStyle'
				);
			if ( canReceiveBtnStyle && ! attrs.buttonStyle ) {
				attrs.buttonStyle = source.style;
			}
		}

		return attrs;
	}

	function editorPreview(
		props,
		block,
		contextualId,
		contextPost,
		wrapperProps,
		previewSample
	) {
		var config = fieldConfig[ block.name ] || {};
		var registeredBlock =
			blocks.getBlockType && blocks.getBlockType( block.name );
		var allowedAttrs =
			( registeredBlock && registeredBlock.attributes ) ||
			block.attributes;
		var rawAttrs = props.attributes || {};
		var attrs = sanitizeSsrAttributes( block, rawAttrs, allowedAttrs );

		if (
			config.releaseId &&
			! ( attrs.releaseId > 0 ) &&
			contextualId > 0
		) {
			attrs.releaseId = contextualId;
		}

		var styleTokens = ( rawAttrs.className || '' )
			.split( /\s+/ )
			.filter( function ( token ) {
				return 0 === token.indexOf( 'is-style-' );
			} )
			.join( ' ' );
		var queryArgs = {};
		var rawPostId = contextPost ? contextPost.postId : 0;
		var numericPostId =
			'number' === typeof rawPostId
				? Math.floor( rawPostId )
				: parseInt( String( rawPostId || '' ), 10 );
		if (
			numericPostId > 0 &&
			String( numericPostId ) === String( rawPostId ).trim()
		) {
			queryArgs.post_id = numericPostId;
		}
		if ( styleTokens ) {
			queryArgs.mw_style_class = styleTokens;
		}
		if ( previewSample ) {
			if ( config.previewQuery ) {
				queryArgs.mw_preview_query = String( previewSample );
			} else {
				queryArgs.mw_preview_sample = String( previewSample );
			}
		}

		return createElement(
			'div',
			wrapperProps,
			createElement( serverSideRender, {
				block: block.name,
				attributes: attrs,
				skipBlockSupportAttributes: true,
				urlQueryArgs: Object.keys( queryArgs ).length
					? queryArgs
					: undefined,
				EmptyResponsePlaceholder() {
					return editorFallbackPreview( block, '' );
				},
				ErrorResponsePlaceholder( errorProps ) {
					var reason =
						errorProps &&
						errorProps.response &&
						errorProps.response.message
							? errorProps.response.message
							: __(
									'خطا در دریافت پیش‌نمایش از سرور وردپرس.',
									'music-wave-core'
							  );
					return editorFallbackPreview( block, reason );
				},
				LoadingResponsePlaceholder() {
					return createElement( components.Spinner );
				},
			} )
		);
	}

	// Icons arrive either as a Dashicon slug or as path data (see
	// Rendering::request_form_icon()); path data becomes an inline SVG so
	// the icon inherits the editor's current colour like core icons do.
	function blockIcon( icon ) {
		if ( ! icon || 'string' === typeof icon ) {
			return icon || 'album';
		}
		if ( ! Array.isArray( icon.paths ) ) {
			return 'album';
		}
		return createElement(
			'svg',
			{
				xmlns: 'http://www.w3.org/2000/svg',
				viewBox: icon.viewBox || '0 0 24 24',
				width: 24,
				height: 24,
				'aria-hidden': 'true',
				focusable: 'false',
			},
			icon.paths.map( function ( path, index ) {
				return createElement(
					'path',
					Object.assign(
						{
							key: 'p' + index,
							strokeLinecap: 'round',
							strokeLinejoin: 'round',
						},
						path
					)
				);
			} )
		);
	}

	dynamicBlocks.forEach( function ( block ) {
		var existing = blocks.getBlockType( block.name );
		if ( existing ) {
			// Server hydration from block.json registers a bare dynamic block.
			// Re-registration only adds the editor experience: every schema
			// field the server provided (attributes, supports, context, titles)
			// stays authoritative, so block.json remains the single source of
			// truth (PROJECT_PLAN.md Stage 4 deliverable 3).
			//
			// The unregister/register pair must always leave a definition
			// behind: ServerSideRender sanitizes attributes against the
			// registered type and throws when it is missing, and inside a Query
			// Loop that throw is reported against the Query block itself.
			blocks.unregisterBlockType( block.name );
		}

		var mergedAttrs = Object.assign(
			{},
			block.attributes || {},
			( existing && existing.attributes ) || {}
		);
		var hasButtonStyle = Object.prototype.hasOwnProperty.call(
			mergedAttrs,
			'buttonStyle'
		);

		function ReleaseFieldsEdit( props ) {
			var blockCfg = fieldConfig[ block.name ] || {};
			var sampleState = useSharedPreviewSample(
				previewChannelFor( blockCfg )
			);
			var previewSample = sampleState[ 0 ];
			var setPreviewSample = sampleState[ 1 ];
			var wrapperProps = blockEditor.useBlockProps( {
				className: 'mw-block-editor-shell',
				'data-mw-block': block.name,
			} );
			var contextPost = useEditorContextPost( props );
			var contextualId = editorReleaseId( contextPost );
			var setBlockAttributes = props.setAttributes;
			var rawLegacyStyle =
				props.attributes && 'string' === typeof props.attributes.style
					? props.attributes.style
					: '';
			var currentButtonStyle =
				( props.attributes && props.attributes.buttonStyle ) || '';

			useEffect(
				function () {
					if ( hasButtonStyle && rawLegacyStyle ) {
						setBlockAttributes( {
							buttonStyle: currentButtonStyle || rawLegacyStyle,
							style: undefined,
						} );
					}
				},
				[ rawLegacyStyle, currentButtonStyle, setBlockAttributes ]
			);

			return createElement(
				Fragment,
				null,
				inspectorControls(
					props,
					block.name,
					previewSample,
					setPreviewSample
				),
				editorPreview(
					props,
					block,
					contextualId,
					contextPost,
					wrapperProps,
					previewSample
				)
			);
		}

		var legacyDeprecated = hasButtonStyle
			? [
					{
						attributes: Object.assign( {}, mergedAttrs, {
							style: { type: 'string', default: '' },
						} ),
						migrate( attributes ) {
							var next = Object.assign( {}, attributes );
							if (
								'string' === typeof next.style &&
								next.style
							) {
								next.buttonStyle = next.style;
							}
							delete next.style;
							return next;
						},
						save() {
							return null;
						},
					},
			  ]
			: undefined;

		blocks.registerBlockType(
			block.name,
			Object.assign( {}, existing || {}, {
				apiVersion:
					existing && existing.apiVersion ? existing.apiVersion : 3,
				title: ( existing && existing.title ) || block.title,
				description:
					( existing && existing.description ) || block.description,
				category: ( existing && existing.category ) || 'music-wave',
				icon: blockIcon( block.icon || ( existing && existing.icon ) ),
				keywords: block.keywords || [],
				attributes: mergedAttrs,
				deprecated: legacyDeprecated,
				usesContext:
					existing &&
					existing.usesContext &&
					existing.usesContext.length
						? existing.usesContext
						: block.usesContext || [],
				supports: Object.assign(
					{},
					block.supports || {},
					( existing && existing.supports ) || {}
				),
				styles:
					existing && existing.styles ? existing.styles : undefined,
				example:
					block.example ||
					( existing && existing.example
						? existing.example
						: undefined ),
				edit: ReleaseFieldsEdit,
				save() {
					return null;
				},
			} )
		);

		// registerBlockType() drops settings it cannot validate (a missing
		// title, for instance) and returns undefined. Put the server definition
		// back so the block still renders instead of breaking every template
		// that contains it.
		if ( existing && ! blocks.getBlockType( block.name ) ) {
			blocks.registerBlockType( block.name, existing );
		}
	} );
	// Hidden legacy alias: `music-wave/dashboard-edit` merged into
	// `music-wave/dashboard-account` so saved legacy templates never break.
	var unifiedAccount = blocks.getBlockType( 'music-wave/dashboard-account' );
	if (
		unifiedAccount &&
		! blocks.getBlockType( 'music-wave/dashboard-edit' )
	) {
		blocks.registerBlockType( 'music-wave/dashboard-edit', {
			apiVersion: 3,
			title: unifiedAccount.title,
			description: unifiedAccount.description,
			category: unifiedAccount.category || 'music-wave',
			icon: unifiedAccount.icon,
			attributes: Object.assign( {}, unifiedAccount.attributes ),
			supports: {
				html: false,
				inserter: false,
			},
			transforms: {
				to: [
					{
						type: 'block',
						blocks: [ 'music-wave/dashboard-account' ],
						transform( attributes ) {
							return blocks.createBlock(
								'music-wave/dashboard-account',
								attributes
							);
						},
					},
				],
			},
			edit: unifiedAccount.edit,
			save() {
				return null;
			},
		} );
	}
} )(
	window.wp && window.wp.blocks,
	window.wp && window.wp.element,
	window.wp && window.wp.blockEditor,
	window.wp && window.wp.components,
	window.wp && window.wp.i18n,
	window.wp && window.wp.serverSideRender,
	window.musicWaveDynamicBlocks || []
);
