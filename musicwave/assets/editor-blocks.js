( function (
	blocks,
	element,
	blockEditor,
	components,
	i18n,
	serverSideRender,
	presentationBlocks,
	staticBlocks
) {
	'use strict';

	if (
		! blocks ||
		! element ||
		! blockEditor ||
		! components ||
		! i18n ||
		! serverSideRender ||
		! presentationBlocks
	) {
		return;
	}

	var createElement = element.createElement;
	var Fragment = element.Fragment;
	var __ = i18n.__;
	var inheritedToggleOptions = [
		{
			label: __( 'استفاده از تنظیمات کلی', 'musicwave' ),
			value: 'inherit',
		},
		{ label: __( 'فعال', 'musicwave' ), value: 'enabled' },
		{ label: __( 'غیرفعال', 'musicwave' ), value: 'disabled' },
	];

	var imageShapeOptions = [
		{ label: __( 'مربع (۱:۱)', 'musicwave' ), value: 'square' },
		{ label: __( 'افقی (۱۶:۱۰)', 'musicwave' ), value: 'landscape' },
		{ label: __( 'عمودی (۴:۵)', 'musicwave' ), value: 'portrait' },
		{ label: __( 'دایره‌ای', 'musicwave' ), value: 'circle' },
	];

	/**
	 * Extract `is-style-*` tokens so `ServerSideRender` can skip duplicate
	 * block-support wrapper spacing while still re-rendering server style classes live.
	 *
	 * @param {string} className Raw block `className` attribute.
	 * @return {string} Space-joined `is-style-*` tokens.
	 */
	function extractStyleTokens( className ) {
		return ( className || '' )
			.split( /\s+/ )
			.filter( function ( token ) {
				return 0 === token.indexOf( 'is-style-' );
			} )
			.join( ' ' );
	}

	/**
	 * Normalize a color input value into a `#rrggbb` string suitable for `<input type="color">`.
	 *
	 * @param {string} raw      Current attribute value.
	 * @param {string} fallback Default hex fallback for swatch display.
	 * @return {string} Normalized `#rrggbb` hex value.
	 */
	function swatchHexValue( raw, fallback ) {
		var trimmed = ( raw || '' ).trim();
		if ( /^#[0-9a-fA-F]{6}$/.test( trimmed ) ) {
			return trimmed.toLowerCase();
		}
		if ( /^[0-9a-fA-F]{6}$/.test( trimmed ) ) {
			return '#' + trimmed.toLowerCase();
		}
		if ( /^#?[0-9a-fA-F]{3}$/.test( trimmed ) ) {
			var clean = trimmed.replace( /^#/, '' ).toLowerCase();
			return (
				'#' +
				clean.charAt( 0 ) +
				clean.charAt( 0 ) +
				clean.charAt( 1 ) +
				clean.charAt( 1 ) +
				clean.charAt( 2 ) +
				clean.charAt( 2 )
			);
		}
		return fallback || '#1db954';
	}

	/**
	 * Render a synchronized visual color swatch + hex input + reset button.
	 *
	 * @param {string}   label       Field label.
	 * @param {string}   help        Help description.
	 * @param {string}   value       Current color value.
	 * @param {Function} onChange    Setter callback.
	 * @param {string}   fallbackHex Default swatch preview color.
	 * @return {Object} React element.
	 */
	function colorFieldControl( label, help, value, onChange, fallbackHex ) {
		return createElement(
			'div',
			{
				style: {
					marginBottom: '16px',
				},
			},
			createElement(
				'div',
				{
					style: {
						display: 'flex',
						alignItems: 'flex-end',
						gap: '8px',
					},
				},
				createElement(
					'div',
					{ style: { flex: '1 1 auto', minWidth: 0 } },
					createElement( components.TextControl, {
						label,
						value: value || '',
						placeholder: fallbackHex || '#ffffff',
						onChange( nextValue ) {
							onChange( nextValue );
						},
					} )
				),
				createElement( 'input', {
					type: 'color',
					'aria-label': label,
					value: swatchHexValue( value, fallbackHex ),
					onChange( event ) {
						onChange( event.target.value );
					},
					style: {
						width: '36px',
						height: '36px',
						padding: '2px',
						marginBottom: '8px',
						border: '1px solid rgba(148, 163, 184, 0.45)',
						borderRadius: '6px',
						background: 'transparent',
						cursor: 'pointer',
						flex: '0 0 auto',
					},
				} ),
				value
					? createElement(
							'button',
							{
								type: 'button',
								className:
									'components-button is-secondary is-small',
								onClick() {
									onChange( '' );
								},
								style: {
									marginBottom: '10px',
									flex: '0 0 auto',
								},
							},
							__( 'پاک‌سازی', 'musicwave' )
					  )
					: null
			),
			help
				? createElement(
						'p',
						{
							className: 'components-base-control__help',
							style: { marginTop: '-4px' },
						},
						help
				  )
				: null
		);
	}

	/**
	 * Optional icon field that sits directly under a section title setting.
	 * Empty stays icon-free — no default glyph is substituted.
	 *
	 * @param {Object} props Block props.
	 * @param {string} attr  Attribute name holding the icon.
	 * @return {Object} TextControl element.
	 */
	function sectionIconControl( props, attr ) {
		return createElement( components.TextControl, {
			label: __( 'آیکون عنوان بخش (اختیاری)', 'musicwave' ),
			value: props.attributes[ attr ] || '',
			placeholder: 'dashicons dashicons-controls-volumeon / <svg>…',
			help: __(
				'کنار عنوان نمایش داده می‌شود؛ خالی یعنی بدون آیکون. یک اسلاگ، کلاس آیکون فونت، یا کد SVG جای‌گذاری کنید.',
				'musicwave'
			),
			onChange( value ) {
				var next = {};
				next[ attr ] = value;
				props.setAttributes( next );
			},
		} );
	}

	/**
	 * Numeric stepper (−/+ around a number field) for precise card-size counts.
	 *
	 * Replaces the coarse range slider so «ستون‌های دسکتاپ» can be dialled in
	 * one column at a time with a visible, clamped number.
	 *
	 * @param {Object} props    Block props.
	 * @param {string} attr     Attribute name.
	 * @param {string} label    Visible label.
	 * @param {number} min      Minimum value.
	 * @param {number} max      Maximum value.
	 * @param {number} fallback Value shown when the attribute is unset.
	 * @return {Object} Stepper element.
	 */
	function numberStepper( props, attr, label, min, max, fallback ) {
		var raw = parseInt( props.attributes[ attr ], 10 );
		// Unset (0) and out-of-range values are the "auto" state several blocks
		// ship with — show the documented fallback instead of a 0 the minus
		// button can never reach.
		var value = isNaN( raw ) || raw < min || raw > max ? fallback : raw;

		function apply( next ) {
			var clamped = Math.max( min, Math.min( max, next ) );
			var update = {};
			update[ attr ] = clamped;
			props.setAttributes( update );
		}

		return createElement(
			'div',
			{ className: 'mw-stepper' },
			createElement( 'div', { className: 'mw-stepper__label' }, label ),
			createElement(
				'div',
				{ className: 'mw-stepper__control' },
				createElement(
					components.Button,
					{
						className: 'mw-stepper__button',
						variant: 'secondary',
						isSmall: true,
						disabled: value <= min,
						onClick() {
							apply( value - 1 );
						},
						'aria-label': __( 'کاهش', 'musicwave' ),
					},
					'\u2212'
				),
				createElement( components.TextControl, {
					className: 'mw-stepper__input',
					type: 'number',
					min,
					max,
					step: 1,
					value,
					__nextHasNoMarginBottom: true,
					onChange( next ) {
						var parsed = parseInt( next, 10 );
						if ( ! isNaN( parsed ) ) {
							apply( parsed );
						}
					},
				} ),
				createElement(
					components.Button,
					{
						className: 'mw-stepper__button',
						variant: 'secondary',
						isSmall: true,
						disabled: value >= max,
						onClick() {
							apply( value + 1 );
						},
						'aria-label': __( 'افزایش', 'musicwave' ),
					},
					'+'
				)
			)
		);
	}

	/**
	 * Inspector for the dynamic breadcrumb block (`music-wave/breadcrumb`).
	 *
	 * @param {Object} props Block props.
	 * @return {Object} InspectorControls element.
	 */
	function breadcrumbInspector( props ) {
		return createElement(
			blockEditor.InspectorControls,
			null,
			createElement(
				components.PanelBody,
				{
					title: __( 'تنظیمات مسیر راهنما', 'musicwave' ),
					initialOpen: true,
				},
				createElement( components.ToggleControl, {
					label: __( 'نمایش آیتم خانه', 'musicwave' ),
					checked: false !== props.attributes.showHome,
					onChange( value ) {
						props.setAttributes( { showHome: !! value } );
					},
				} ),
				createElement( components.TextControl, {
					label: __( 'برچسب صفحه اصلی', 'musicwave' ),
					placeholder: __( 'خانه', 'musicwave' ),
					value: props.attributes.homeLabel || '',
					help: __(
						'برای استفاده از برچسب پیش‌فرض ترجمه‌شده («خانه»)، خالی بگذارید.',
						'musicwave'
					),
					onChange( value ) {
						props.setAttributes( { homeLabel: value } );
					},
				} ),
				createElement( components.ToggleControl, {
					label: __( 'نمایش آیکون خانه', 'musicwave' ),
					checked: false !== props.attributes.showHomeIcon,
					onChange( value ) {
						props.setAttributes( { showHomeIcon: !! value } );
					},
				} ),
				createElement( components.SelectControl, {
					label: __( 'جداکننده مسیر', 'musicwave' ),
					value: props.attributes.separator || 'chevron',
					options: [
						{
							label: __( 'فلش زاویه‌دار (‹ / ›)', 'musicwave' ),
							value: 'chevron',
						},
						{
							label: __( 'خط مورب (/)', 'musicwave' ),
							value: 'slash',
						},
						{
							label: __( 'پیکان (← / →)', 'musicwave' ),
							value: 'arrow',
						},
						{
							label: __( 'نقطه (•)', 'musicwave' ),
							value: 'dot',
						},
						{
							label: __( 'خط عمودی (|)', 'musicwave' ),
							value: 'pipe',
						},
						{
							label: __( 'خط تیره (—)', 'musicwave' ),
							value: 'dash',
						},
					],
					onChange( value ) {
						props.setAttributes( { separator: value } );
					},
				} ),
				createElement( components.ToggleControl, {
					label: __( 'نمایش ریشه کاتالوگ (انتشارها)', 'musicwave' ),
					checked: false !== props.attributes.showCatalogRoot,
					onChange( value ) {
						props.setAttributes( { showCatalogRoot: !! value } );
					},
				} ),
				createElement( components.ToggleControl, {
					label: __( 'پنهان کردن در صفحه اصلی سایت', 'musicwave' ),
					checked: false !== props.attributes.hideOnFrontPage,
					onChange( value ) {
						props.setAttributes( { hideOnFrontPage: !! value } );
					},
				} ),
				createElement( components.ToggleControl, {
					label: __( 'نمایش عنوان صفحه فعلی', 'musicwave' ),
					checked: false !== props.attributes.showCurrent,
					onChange( value ) {
						props.setAttributes( { showCurrent: !! value } );
					},
				} ),
				createElement( components.RangeControl, {
					label: __(
						'حداکثر طول عنوان آیتم‌ها (۰ = نامحدود)',
						'musicwave'
					),
					min: 0,
					max: 120,
					step: 4,
					value:
						'number' === typeof props.attributes.maxLength
							? props.attributes.maxLength
							: 0,
					onChange( value ) {
						props.setAttributes( { maxLength: value || 0 } );
					},
				} ),
				createElement( components.TextControl, {
					label: __( 'برچسب دسترس‌پذیری (ARIA)', 'musicwave' ),
					placeholder: __( 'مسیر راهنما', 'musicwave' ),
					value: props.attributes.ariaLabel || '',
					onChange( value ) {
						props.setAttributes( { ariaLabel: value } );
					},
				} ),
				createElement(
					'p',
					{ className: 'components-base-control__help' },
					__(
						'در ویرایشگر سایت، «نمونه مسیر راهنما (Breadcrumb)» به‌صورت زنده نمایش داده می‌شود تا هر تغییر سبک، جداکننده، رنگ و فاصله بلافاصله قابل مشاهده باشد. در سایت واقعی، مسیر دقیق هر انتشار، هنرمند، سبک، برگه یا جست‌وجو به‌طور خودکار جایگزین و با اسکیمای سئو هماهنگ می‌شود.',
						'musicwave'
					)
				)
			)
		);
	}

	/**
	 * Inspector for `music-wave/theme-text`.
	 *
	 * @param {Object} props Block props.
	 * @return {Object} InspectorControls element.
	 */
	function themeTextInspector( props ) {
		return createElement(
			blockEditor.InspectorControls,
			null,
			createElement(
				components.PanelBody,
				{
					title: __( 'تنظیمات متن پویا', 'musicwave' ),
					initialOpen: true,
				},
				createElement( components.SelectControl, {
					label: __( 'منبع متن قالب', 'musicwave' ),
					value: props.attributes.key || '',
					options: [
						{
							label: __( '— انتخاب کنید —', 'musicwave' ),
							value: '',
						},
						{
							label: __( 'آخرین انتشارها', 'musicwave' ),
							value: 'latest_releases',
						},
						{
							label: __( 'موسیقی‌ای پیدا نشد.', 'musicwave' ),
							value: 'no_music_found',
						},
						{
							label: __( 'عنوان صفحهٔ یافت‌نشد', 'musicwave' ),
							value: 'not_found_title',
						},
						{
							label: __( 'متن صفحهٔ یافت‌نشد', 'musicwave' ),
							value: 'not_found_message',
						},
						{
							label: __( 'عنوان سبد خرید', 'musicwave' ),
							value: 'cart_title',
						},
						{
							label: __( 'عنوان تسویه‌حساب', 'musicwave' ),
							value: 'checkout_title',
						},
						{
							label: __( 'عنوان حساب کاربری', 'musicwave' ),
							value: 'account_title',
						},
						{
							label: __(
								'متن «قدرت‌گرفته از MusicWave»',
								'musicwave'
							),
							value: 'powered_by',
						},
					],
					help: __(
						'هر متن ترجمه‌شده و آماده است؛ برای تغییر خودِ نوشته، ترجمهٔ پوسته را ویرایش کنید تا در همهٔ زبان‌ها هماهنگ بماند.',
						'musicwave'
					),
					onChange( value ) {
						props.setAttributes( { key: value } );
					},
				} ),
				createElement( components.SelectControl, {
					label: __( 'تگ HTML خروجی', 'musicwave' ),
					help: __(
						'تگ را متناسب با جای استفاده انتخاب کنید؛ تگ‌های ناشناخته به پاراگراف تبدیل می‌شوند.',
						'musicwave'
					),
					value: props.attributes.tagName || 'p',
					options: [
						{ label: 'p', value: 'p' },
						{ label: 'span', value: 'span' },
						{ label: 'h2', value: 'h2' },
						{ label: 'h3', value: 'h3' },
					],
					onChange( value ) {
						props.setAttributes( { tagName: value } );
					},
				} )
			)
		);
	}

	/**
	 * Inspector for `music-wave/footer-columns`.
	 *
	 * @return {Object} InspectorControls element.
	 */
	function footerColumnsInspector() {
		return createElement(
			blockEditor.InspectorControls,
			null,
			createElement(
				components.PanelBody,
				{
					title: __( 'ستون‌های ابزارک فوتر', 'musicwave' ),
					initialOpen: true,
				},
				createElement(
					'p',
					{ className: 'components-base-control__help' },
					__(
						'این بلوک سه ناحیه ابزارک فوتر را نمایش می‌دهد. برای ویرایش مستقیم و ماژولار در ویرایشگر سایت، می‌توانید مستقیماً بلوک «ستون‌ها» وردپرس را با بلوک‌های فهرست، پیوند و متن ترکیب کنید.',
						'musicwave'
					)
				)
			)
		);
	}

	/**
	 * Inspector for the rail toggle block.
	 *
	 * @param {Object} props Block props.
	 * @return {Object} InspectorControls element.
	 */
	function railToggleInspector( props ) {
		return createElement(
			blockEditor.InspectorControls,
			null,
			createElement(
				components.PanelBody,
				{ title: __( 'دکمهٔ منو', 'musicwave' ), initialOpen: true },
				createElement( components.SelectControl, {
					label: __( 'جای دکمه', 'musicwave' ),
					help: __(
						'«نوار بالا» در همهٔ عرض‌ها دیده می‌شود (ریل دسکتاپ + کشوی موبایل) و در الگوهای پیش‌فرض استفاده شده است. «داخل برند» دکمه را کنار لوگو در خودِ ریل می‌گذارد و فقط در دسکتاپ دیده می‌شود. برای هر سربرگ فقط یک نمونه بگذارید.',
						'musicwave'
					),
					value: props.attributes.variant || 'bar',
					options: [
						{
							label: __(
								'نوار بالا (همیشه دیده می‌شود)',
								'musicwave'
							),
							value: 'bar',
						},
						{
							label: __(
								'داخل برند ریل (فقط دسکتاپ)',
								'musicwave'
							),
							value: 'brand',
						},
					],
					onChange( value ) {
						props.setAttributes( { variant: value } );
					},
				} ),
				createElement( components.SelectControl, {
					label: __( 'آیکون', 'musicwave' ),
					help: __(
						'آیکون دکمه را انتخاب کنید. در ویرایشگر و سایت به صورت زنده نمایش داده می‌شود.',
						'musicwave'
					),
					value: props.attributes.icon || 'menu',
					options: [
						{
							label: __( 'منو (همبرگری)', 'musicwave' ),
							value: 'menu',
						},
						{ label: __( 'فلش', 'musicwave' ), value: 'chevron' },
						{ label: __( 'نقاط', 'musicwave' ), value: 'dots' },
						{
							label: __( 'پیکان بازگشت', 'musicwave' ),
							value: 'arrow',
						},
					],
					onChange( value ) {
						props.setAttributes( { icon: value } );
					},
				} ),
				createElement( components.ToggleControl, {
					label: __( 'نمایش آیکون', 'musicwave' ),
					help: __(
						'آیکون دکمه را نمایش یا پنهان کنید. وقتی خاموش باشد فقط متن (اگر فعال باشد) نمایش داده می‌شود.',
						'musicwave'
					),
					checked:
						props.attributes.showIcon === undefined
							? true
							: !! props.attributes.showIcon,
					onChange( value ) {
						props.setAttributes( { showIcon: !! value } );
					},
				} ),
				createElement( components.ToggleControl, {
					label: __( 'نمایش متن دکمه', 'musicwave' ),
					help: __(
						'متن دکمه را کنار آیکون نمایش دهید. برای دکمه‌های فقط آیکون خاموش بگذارید.',
						'musicwave'
					),
					checked: !! props.attributes.showLabel,
					onChange( value ) {
						props.setAttributes( { showLabel: !! value } );
					},
				} ),
				createElement( components.TextControl, {
					label: __( 'متن دکمه', 'musicwave' ),
					help: __(
						'هم به عنوان متن روی دکمه و هم به عنوان توضیح صفحه‌خوان استفاده می‌شود.',
						'musicwave'
					),
					value: props.attributes.label || '',
					onChange( value ) {
						props.setAttributes( { label: value } );
					},
				} )
			),
			createElement(
				components.PanelBody,
				{
					title: __( 'آیکون‌های منو', 'musicwave' ),
					initialOpen: false,
				},
				createElement(
					'p',
					{ className: 'components-base-control__help' },
					__(
						'برای تغییر آیکون هر پیوند منو (خانه، مرور، قطعه‌ها، هنرمندان، فروشگاه، درخواست‌ها و…): بلوک Navigation یا Navigation Link را انتخاب کنید → در ستون تنظیمات → پیشرفته → کلاس CSS اضافی، یک کلاس mw-icon-* اضافه کنید. مثلاً: mw-icon-home، mw-icon-browse، mw-icon-tracks، mw-icon-artists، mw-icon-charts، mw-icon-radio (درخواست‌ها)، mw-icon-library، mw-icon-playlists، mw-icon-genres، mw-icon-albums، mw-icon-podcasts، mw-icon-search، mw-icon-account، mw-icon-settings. اگر کلاسی ندهید، آیکون به صورت خودکار از روی برچسب فارسی/انگلیسی تشخیص داده می‌شود (خانه، مرور، قطعه‌ها، هنرمندان، فروشگاه و…).',
						'musicwave'
					)
				)
			)
		);
	}

	/*
	 * Header search — the one shared Inspector model.
	 *
	 * These tables are the only place where the block's breakpoint labels,
	 * options and ready-made presets live. They mirror
	 * `blocks/header-search/block.json` and
	 * `ChromeBlocksRenderer::render_header_search()`, so the Settings panel can
	 * never drift away from what the server really prints.
	 */
	var headerSearchBreakpoints = [
		{
			attribute: 'desktopMode',
			label: __( 'دسکتاپ — از ۱۰۲۴ پیکسل به بالا', 'musicwave' ),
			options: [
				{
					label: __( 'نوار جستجوی کامل (پیشنهادی)', 'musicwave' ),
					value: 'bar',
				},
				{
					label: __( 'فیلد فشرده که با کلیک باز میشود', 'musicwave' ),
					value: 'compact',
				},
				{
					label: __( 'دکمه آیکون + پنجره پاپآپ', 'musicwave' ),
					value: 'popup',
				},
			],
		},
		{
			attribute: 'tabletMode',
			label: __( 'تبلت — ۷۶۸ تا ۱۰۲۳ پیکسل', 'musicwave' ),
			options: [
				{
					label: __( 'فیلد فشرده هوشمند (پیشنهادی)', 'musicwave' ),
					value: 'compact',
				},
				{
					label: __( 'دکمه آیکون + پنجره پاپآپ', 'musicwave' ),
					value: 'popup',
				},
				{ label: __( 'نوار جستجوی کامل', 'musicwave' ), value: 'bar' },
				{
					label: __( 'پنهان در این اندازه', 'musicwave' ),
					value: 'hidden',
				},
			],
		},
		{
			attribute: 'mobileMode',
			label: __( 'موبایل — کمتر از ۷۶۸ پیکسل', 'musicwave' ),
			options: [
				{
					label: __(
						'دکمه آیکون + پاپآپ شناور (پیشنهادی)',
						'musicwave'
					),
					value: 'popup',
				},
				{
					label: __(
						'دکمه آیکون + نوار کشویی بازشونده',
						'musicwave'
					),
					value: 'expand',
				},
				{
					label: __( 'فیلد فشرده داخل سربرگ', 'musicwave' ),
					value: 'compact',
				},
				{
					label: __( 'پنهان در این اندازه', 'musicwave' ),
					value: 'hidden',
				},
			],
		},
	];

	var headerSearchPresets = [
		{
			value: 'responsive',
			label: __(
				'پیشنهاد MusicWave — نوار کامل دسکتاپ، فشرده تبلت، پاپآپ موبایل',
				'musicwave'
			),
			modes: {
				desktopMode: 'bar',
				tabletMode: 'compact',
				mobileMode: 'popup',
			},
		},
		{
			value: 'always-bar',
			label: __( 'نوار جستجوی کامل در همه اندازهها', 'musicwave' ),
			modes: {
				desktopMode: 'bar',
				tabletMode: 'bar',
				mobileMode: 'compact',
			},
		},
		{
			value: 'always-popup',
			label: __(
				'دکمه آیکون + پنجره پاپآپ در همه اندازهها (کمترین فضا)',
				'musicwave'
			),
			modes: {
				desktopMode: 'popup',
				tabletMode: 'popup',
				mobileMode: 'popup',
			},
		},
		{
			value: 'quiet-mobile',
			label: __(
				'نوار دسکتاپ، فیلد فشرده در تبلت و موبایل',
				'musicwave'
			),
			modes: {
				desktopMode: 'bar',
				tabletMode: 'compact',
				mobileMode: 'compact',
			},
		},
		{
			value: 'hide-mobile',
			label: __( 'پنهان کردن جستجو در موبایل', 'musicwave' ),
			modes: {
				desktopMode: 'bar',
				tabletMode: 'compact',
				mobileMode: 'hidden',
			},
		},
	];

	/**
	 * Resolve the effective per-breakpoint modes exactly like the server renderer,
	 * including the legacy `variant="popup"` fallback, so the Settings panel
	 * always shows the mode that is really printed on the front end.
	 *
	 * @param {Object} attributes Block attributes.
	 * @return {{desktopMode: string, tabletMode: string, mobileMode: string}} Modes.
	 */
	function resolvedHeaderSearchModes( attributes ) {
		var attrs = attributes || {};
		var legacyPopup =
			'popup' === ( attrs.variant || 'inline' ) &&
			! attrs.desktopMode &&
			! attrs.tabletMode &&
			! attrs.mobileMode;

		return {
			desktopMode: attrs.desktopMode || ( legacyPopup ? 'popup' : 'bar' ),
			tabletMode:
				attrs.tabletMode || ( legacyPopup ? 'popup' : 'compact' ),
			mobileMode: attrs.mobileMode || 'popup',
		};
	}

	/**
	 * Match a resolved mode triple back to one ready-made preset.
	 *
	 * @param {Object} modes Resolved modes.
	 * @return {string} Preset value, or '' when the combination is custom.
	 */
	function matchedHeaderSearchPreset( modes ) {
		var i;
		for ( i = 0; i < headerSearchPresets.length; i++ ) {
			var preset = headerSearchPresets[ i ].modes;
			if (
				preset.desktopMode === modes.desktopMode &&
				preset.tabletMode === modes.tabletMode &&
				preset.mobileMode === modes.mobileMode
			) {
				return headerSearchPresets[ i ].value;
			}
		}
		return '';
	}

	/**
	 * Preset attributes for one preset, keeping the legacy `variant` in sync.
	 *
	 * @param {Object} preset Preset definition.
	 * @return {Object} Attribute patch.
	 */
	function headerSearchPresetAttributes( preset ) {
		var patch = {
			variant: 'popup' === preset.modes.desktopMode ? 'popup' : 'inline',
		};
		return Object.assign( patch, preset.modes );
	}

	/**
	 * Select for a single breakpoint; desktop keeps `variant` in sync for
	 * templates saved before the per-breakpoint attributes existed.
	 *
	 * @param {Object} props      Block props.
	 * @param {Object} breakpoint Breakpoint definition.
	 * @param {Object} modes      Resolved modes.
	 * @return {Object} Control element.
	 */
	function headerSearchModeSelect( props, breakpoint, modes ) {
		return createElement( components.SelectControl, {
			key: breakpoint.attribute,
			label: breakpoint.label,
			value: modes[ breakpoint.attribute ],
			options: breakpoint.options,
			onChange( value ) {
				var patch = {};
				patch[ breakpoint.attribute ] = value;
				if ( 'desktopMode' === breakpoint.attribute ) {
					patch.variant = 'popup' === value ? 'popup' : 'inline';
				}
				props.setAttributes( patch );
			},
		} );
	}

	/**
	 * Boolean switch bound to one block attribute.
	 *
	 * @param {Object} props Block props.
	 * @param {string} key   Attribute name.
	 * @param {string} label Control label.
	 * @param {string} help  Optional help copy.
	 * @return {Object} Control element.
	 */
	function headerSearchToggle( props, key, label, help ) {
		return createElement( components.ToggleControl, {
			key,
			label,
			help,
			checked: false !== props.attributes[ key ],
			onChange( value ) {
				var patch = {};
				patch[ key ] = !! value;
				props.setAttributes( patch );
			},
		} );
	}

	function headerSearchInspector( props ) {
		var modes = resolvedHeaderSearchModes( props.attributes );

		return createElement(
			blockEditor.InspectorControls,
			null,
			createElement(
				components.PanelBody,
				{
					title: __( 'چیدمان نوار جستجو', 'musicwave' ),
					initialOpen: true,
				},
				createElement( components.SelectControl, {
					label: __( 'پیشتنظیم آماده', 'musicwave' ),
					help: __(
						'جستجوی سربرگ در سه اندازه صفحه (دسکتاپ، تبلت و موبایل) جداگانه نمایش داده میشود. یک پیشتنظیم انتخاب کنید و بعد در صورت نیاز هر اندازه را پایینتر دقیق کنید.',
						'musicwave'
					),
					value: matchedHeaderSearchPreset( modes ),
					options: [
						{
							label: __(
								'سفارشی — تنظیم دستی هر اندازه',
								'musicwave'
							),
							value: '',
						},
					].concat(
						headerSearchPresets.map( function ( preset ) {
							return {
								label: preset.label,
								value: preset.value,
							};
						} )
					),
					onChange( value ) {
						headerSearchPresets.some( function ( preset ) {
							if ( preset.value !== value ) {
								return false;
							}
							props.setAttributes(
								headerSearchPresetAttributes( preset )
							);
							return true;
						} );
					},
				} ),
				headerSearchBreakpoints.map( function ( breakpoint ) {
					return headerSearchModeSelect( props, breakpoint, modes );
				} )
			),
			createElement(
				components.PanelBody,
				{
					title: __( 'متن‌ها و برچسب‌ها', 'musicwave' ),
					initialOpen: false,
				},
				createElement( components.TextControl, {
					label: __( 'متن راهنمای داخل فیلد', 'musicwave' ),
					placeholder: __(
						'جستجوی آهنگ، هنرمند یا آلبوم…',
						'musicwave'
					),
					value: props.attributes.placeholder || '',
					onChange( value ) {
						props.setAttributes( { placeholder: value } );
					},
				} ),
				createElement( components.TextControl, {
					label: __(
						'نام دکمه (برای صفحهخوانها و پنجره پاپآپ)',
						'musicwave'
					),
					help: __(
						'این متن روی دکمه دیده نمیشود و فقط به عنوان برچسب دسترسپذیری استفاده میشود.',
						'musicwave'
					),
					value: props.attributes.buttonText || '',
					onChange( value ) {
						props.setAttributes( { buttonText: value } );
					},
				} ),
				createElement( components.TextControl, {
					label: __(
						'برچسبهای پیشنهادی سریع (جداشده با کاما)',
						'musicwave'
					),
					help: __(
						'خالی بگذارید تا به شکل خودکار از محبوبترین ژانرهای سایت پر شود؛ برای پنهان کردن عبارت off را بنویسید.',
						'musicwave'
					),
					value: props.attributes.quickTags || '',
					onChange( value ) {
						props.setAttributes( { quickTags: value } );
					},
				} )
			),
			createElement(
				components.PanelBody,
				{
					title: __( 'جستجوی هوشمند', 'musicwave' ),
					initialOpen: false,
				},
				createElement( components.RangeControl, {
					label: __( 'تعداد پیشنهادهای زنده', 'musicwave' ),
					help: __(
						'تعداد نتیجههایی که هنگام تایپ، زیر فیلد جستجو پیشنهاد میشود.',
						'musicwave'
					),
					value: props.attributes.suggestLimit || 5,
					min: 1,
					max: 10,
					onChange( value ) {
						props.setAttributes( { suggestLimit: value || 5 } );
					},
				} ),
				headerSearchToggle(
					props,
					'enableShortcut',
					__( 'میانبر سریع صفحهکلید (Ctrl+K و /)', 'musicwave' ),
					__(
						'با زدن Ctrl+K در هر جای سایت، پنجره جستجو باز میشود.',
						'musicwave'
					)
				),
				headerSearchToggle(
					props,
					'showScopeTabs',
					__(
						'تبهای تفکیک محدوده (همه / آهنگ / هنرمند / پلیلیست)',
						'musicwave'
					)
				),
				headerSearchToggle(
					props,
					'showRecentSearches',
					__( 'نمایش تاریخچه جستجوهای اخیر کاربر', 'musicwave' ),
					__(
						'آخرین جستجوهای هر بازدیدکننده فقط در مرورگر خودش ذخیره میشود.',
						'musicwave'
					)
				),
				headerSearchToggle(
					props,
					'showVoiceSearch',
					__( 'جستجوی صوتی فارسی و انگلیسی', 'musicwave' )
				),
				headerSearchToggle(
					props,
					'showFooterHints',
					__( 'نوار راهنمای کلیدهای میانبر در فوتر', 'musicwave' )
				)
			)
		);
	}

	function queryFilteringPanel( props ) {
		function update( key, value ) {
			var attributes = {};
			attributes[ key ] = value;
			props.setAttributes( attributes );
		}

		// Help texts are deliberately verbose so a non-technical buyer never needs to open documentation.
		return createElement(
			components.PanelBody,
			{
				title: __( 'پرس‌وجو و فیلتر', 'musicwave' ),
				initialOpen: false,
			},
			createElement( components.SelectControl, {
				label: __( 'بازهٔ زمانی انتشار', 'musicwave' ),
				help: __(
					'محدود کردن انتشارها به بازهٔ زمانی مشخص (مثلاً داغ‌ترین‌های امروز یا هفته اخیر).',
					'musicwave'
				),
				value: props.attributes.datePeriod || '',
				options: [
					{
						label: __( 'همهٔ زمان‌ها (بدون محدودیت)', 'musicwave' ),
						value: '',
					},
					{
						label: __( 'امروز (۲۴ ساعت اخیر)', 'musicwave' ),
						value: 'today',
					},
					{
						label: __( 'هفته اخیر (۷ روز گذشته)', 'musicwave' ),
						value: 'week',
					},
					{
						label: __( 'ماه اخیر (۳۰ روز گذشته)', 'musicwave' ),
						value: 'month',
					},
					{
						label: __( 'یک سال اخیر', 'musicwave' ),
						value: 'year',
					},
				],
				onChange( value ) {
					update( 'datePeriod', value );
				},
			} ),
			createElement( components.SelectControl, {
				label: __( 'مرتب‌سازی بر اساس', 'musicwave' ),
				help: __(
					'نحوه مرتب‌سازی انتشارها پیش از نمایش.',
					'musicwave'
				),
				value: props.attributes.orderBy || 'date',
				options: [
					{
						label: __( 'تاریخ انتشار', 'musicwave' ),
						value: 'date',
					},
					{
						label: __( 'تاریخ ویرایش', 'musicwave' ),
						value: 'modified',
					},
					{ label: __( 'عنوان', 'musicwave' ), value: 'title' },
					{ label: __( 'تصادفی', 'musicwave' ), value: 'rand' },
					{
						label: __( 'پربازدیدترین', 'musicwave' ),
						value: 'views',
					},
				],
				onChange( value ) {
					update( 'orderBy', value );
				},
			} ),
			createElement( components.SelectControl, {
				label: __( 'جهت', 'musicwave' ),
				value: props.attributes.order || 'DESC',
				options: [
					{ label: __( 'نزولی', 'musicwave' ), value: 'DESC' },
					{ label: __( 'صعودی', 'musicwave' ), value: 'ASC' },
				],
				onChange( value ) {
					update( 'order', value );
				},
			} ),
			createElement( components.SelectControl, {
				label: __( 'فیلتر طبقه‌بندی', 'musicwave' ),
				help: __(
					'ویترین یا اسلایدر را به یک طبقه‌بندی محدود کنید، یا روی «همهٔ انتشارها» بگذارید.',
					'musicwave'
				),
				value: props.attributes.taxonomy || '',
				options: [
					{ label: __( 'همهٔ انتشارها', 'musicwave' ), value: '' },
					{ label: __( 'هنرمند', 'musicwave' ), value: 'mw_artist' },
					{ label: __( 'سبک', 'musicwave' ), value: 'mw_genre' },
					{ label: __( 'حال‌وهوا', 'musicwave' ), value: 'mw_mood' },
					{
						label: __( 'نوع انتشار', 'musicwave' ),
						value: 'mw_release_type',
					},
					{ label: __( 'برچسب', 'musicwave' ), value: 'mw_label' },
				],
				onChange( value ) {
					update( 'taxonomy', value );
				},
			} ),
			createElement( components.TextControl, {
				label: __( 'نامک عبارت', 'musicwave' ),
				help: __(
					'اختیاری. نامک را از فهرست هنرمندان / سبک‌ها / حال‌وهواها در وردپرس → MusicWave کپی کنید. اگر خالی بگذارید، همهٔ موارد نمایش داده می‌شوند. نمونه: pop یا lo-fi.',
					'musicwave'
				),
				placeholder: __( 'مثلاً pop، lo-fi، hip-hop', 'musicwave' ),
				value: props.attributes.termSlug || '',
				onChange( value ) {
					update( 'termSlug', value );
				},
			} ),
			createElement( components.TextControl, {
				label: __( 'نوع انتشار (نامک)', 'musicwave' ),
				help: __(
					'اختیاری. با نامک نوع انتشار مانند album، single، ep، podcast_show یا podcast_episode فیلتر کنید. برای نمایش همه انواع، خالی بگذارید. این موارد در MusicWave → انواع انتشار قرار دارند.',
					'musicwave'
				),
				placeholder: __(
					'مثلاً album، single، podcast_show',
					'musicwave'
				),
				value: props.attributes.contentType || '',
				onChange( value ) {
					update( 'contentType', value );
				},
			} ),
			createElement( components.TextControl, {
				label: __( 'شناسه انتشارهای منتخب', 'musicwave' ),
				help: __(
					'اختیاری. برای تعیین ترتیب دقیق، شناسه انتشارها را با کاما جدا کرده و وارد کنید (مثلاً ۱۲، ۴۵، ۷۸). با بردن نشانگر روی عنوان انتشار در MusicWave → انتشارها، شناسه را در پیش‌نمایش پیوند ببینید. این گزینه همه فیلترهای دیگر را نادیده می‌گیرد.',
					'musicwave'
				),
				placeholder: __( 'مثلاً ۱۲، ۴۵، ۷۸', 'musicwave' ),
				value: props.attributes.releaseIds || '',
				onChange( value ) {
					update( 'releaseIds', value );
				},
			} )
		);
	}

	/**
	 * Shared interactive Filter Tabs panel for `release-shelf` and `release-slider`.
	 *
	 * @param {Object} props Block props.
	 * @return {Object} PanelBody element.
	 */
	function filterTabsPanel( props ) {
		var rawTabs = props.attributes.filterTabs || '';
		var parsedLabels = rawTabs
			.split( /\r\n|\r|\n/ )
			.map( function ( line ) {
				var trimmed = line.trim();
				if ( ! trimmed || -1 === trimmed.indexOf( '|' ) ) {
					return '';
				}
				return trimmed.split( '|' )[ 0 ].trim();
			} )
			.filter( Boolean );

		var presets = [
			{
				label: __(
					'پریست زمانی (امروز / هفته / ماه / همه)',
					'musicwave'
				),
				value:
					'همه|all\n' +
					'امروز|period:today\n' +
					'این هفته|period:week\n' +
					'این ماه|period:month',
			},
			{
				label: __( 'جدیدترین / پربازدید / تصادفی', 'musicwave' ),
				value:
					'جدیدترین|orderBy:date\n' +
					'داغ‌ترین هفته|period:week+orderBy:views\n' +
					'پربازدیدترین|orderBy:views\n' +
					'پیشنهادی|orderBy:rand',
			},
			{
				label: __( 'تفکیک نوع انتشار (تک‌آهنگ / آلبوم)', 'musicwave' ),
				value:
					'همه|all\n' +
					'تک‌آهنگ|type:single\n' +
					'آلبوم|type:album\n' +
					'ئی‌پی|type:ep',
			},
		];

		return createElement(
			components.PanelBody,
			{
				title: __( 'تب‌های فیلتر (Today / Week / سبک)', 'musicwave' ),
				initialOpen: false,
			},
			createElement( components.TextareaControl, {
				label: __( 'تعریف تب‌های فیلتر پویا', 'musicwave' ),
				placeholder:
					'همه|all\nامروز|period:today\nاین هفته|period:week+orderBy:views\nپاپ|taxonomy:mw_genre:pop',
				help: __(
					'در هر خط یک تب بنویسید به شکل «عنوان تب|فرمان فیلتر». حداقل ۲ خط معتبر وارد کنید تا نوار تب‌ها نمایش داده شود و کاربران بدون رفرش صفحه بین تب‌ها جابه‌جا شوند.',
					'musicwave'
				),
				value: rawTabs,
				onChange( value ) {
					props.setAttributes( { filterTabs: value } );
				},
			} ),
			createElement(
				'div',
				{
					style: {
						display: 'flex',
						flexWrap: 'wrap',
						gap: '6px',
						marginBottom: '12px',
					},
				},
				presets.map( function ( preset, index ) {
					return createElement(
						'button',
						{
							key: 'preset-' + index,
							type: 'button',
							className:
								'components-button is-secondary is-small',
							onClick() {
								props.setAttributes( {
									filterTabs: preset.value,
								} );
							},
						},
						preset.label
					);
				} ),
				rawTabs
					? createElement(
							'button',
							{
								key: 'preset-clear',
								type: 'button',
								className:
									'components-button is-link is-destructive',
								onClick() {
									props.setAttributes( { filterTabs: '' } );
								},
							},
							__( 'حذف تب‌ها', 'musicwave' )
					  )
					: null
			),
			parsedLabels.length > 0
				? createElement(
						'p',
						{
							className: 'components-base-control__help',
							style: { fontWeight: '600' },
						},
						__( 'تب‌های شناسایی‌شده: ', 'musicwave' ) +
							parsedLabels.join( ' • ' )
				  )
				: null,
			createElement(
				'div',
				{
					className: 'components-base-control__help',
					style: { lineHeight: '1.65' },
				},
				createElement(
					'strong',
					{ style: { display: 'block', marginBottom: '4px' } },
					__( 'راهنمای کامل فرمان‌های قابل استفاده:', 'musicwave' )
				),
				createElement(
					'div',
					null,
					__( '• همه موارد بدون فیلتر: همه|all', 'musicwave' )
				),
				createElement(
					'div',
					null,
					__(
						'• بازه زمانی: امروز|period:today ، هفته|period:week ، ماه|period:month ، سال|period:year',
						'musicwave'
					)
				),
				createElement(
					'div',
					null,
					__(
						'• مرتب‌سازی: جدیدترین|orderBy:date ، پربازدید|orderBy:views ، تصادفی|orderBy:rand',
						'musicwave'
					)
				),
				createElement(
					'div',
					null,
					__(
						'• سبک یا هنرمند: پاپ|taxonomy:mw_genre:pop یا سنتی|taxonomy:mw_mood:chill',
						'musicwave'
					)
				),
				createElement(
					'div',
					null,
					__(
						'• نوع انتشار و ترکیب هم‌زمان با + : داغ‌ترین هفته|period:week+orderBy:views+type:single',
						'musicwave'
					)
				)
			)
		);
	}

	function resolvePresentationStyles( block ) {
		var slug = String( block.name || '' ).replace( /^music-?wave\//, '' );
		var variations =
			( window.musicwavePresentationVariations || {} )[ slug ] || {};
		var keys = Object.keys( variations );
		if ( ! keys.length ) {
			return block.styles || [];
		}
		return keys.map( function ( name ) {
			return {
				name,
				label: variations[ name ].label,
				isDefault: false,
			};
		} );
	}

	function resolvePresentationStyle( attributes, allowed, fallback ) {
		var attrs = attributes || {};
		var classList = String( attrs.className || '' ).split( /\s+/ );
		var i;
		for ( i = 0; i < allowed.length; i++ ) {
			if (
				-1 !== classList.indexOf( 'is-style-' + allowed[ i ] ) ||
				allowed[ i ] === attrs.styleVariant
			) {
				return allowed[ i ];
			}
		}
		return fallback;
	}

	function applyPresentationStyle( attributes, value, defaultStyle ) {
		var tokens = String( ( attributes || {} ).className || '' )
			.split( /\s+/ )
			.filter( function ( token ) {
				return Boolean( token ) && 0 !== token.indexOf( 'is-style-' );
			} );
		if ( value && value !== defaultStyle ) {
			tokens.push( 'is-style-' + value );
		}
		return {
			styleVariant: value,
			className: tokens.join( ' ' ),
		};
	}

	function sliderInspector( props ) {
		function select( key, label ) {
			return createElement( components.SelectControl, {
				key,
				label,
				value: props.attributes[ key ] || 'inherit',
				options: inheritedToggleOptions,
				onChange( value ) {
					var update = {};
					update[ key ] = value;
					props.setAttributes( update );
				},
			} );
		}

		return createElement(
			blockEditor.InspectorControls,
			null,
			createElement(
				components.PanelBody,
				{
					title: __( 'محتوای اسلایدر', 'musicwave' ),
					initialOpen: true,
				},
				createElement( components.TextControl, {
					label: __( 'برچسب بالایی', 'musicwave' ),
					value: props.attributes.eyebrow || '',
					onChange( value ) {
						props.setAttributes( { eyebrow: value } );
					},
				} ),
				createElement( components.TextControl, {
					label: __( 'عنوان', 'musicwave' ),
					value: props.attributes.title || '',
					help: __(
						'عنوان و برچسب را خالی بگذارید و بلوک عنوان بخش را بالای اسلایدر قرار دهید.',
						'musicwave'
					),
					onChange( value ) {
						props.setAttributes( { title: value } );
					},
				} ),
				sectionIconControl( props, 'icon' ),
				createElement( components.RangeControl, {
					label: __(
						'تعداد انتشارهای اسلایدر',
						'musicwave'
					),
					help: __(
						'فقط تعداد اسلایدهای قابل پیمایش را تعیین می‌کند و روی اندازهٔ کارت‌ها اثری ندارد؛ اندازهٔ کارت از «ستون‌های دسکتاپ» می‌آید.',
						'musicwave'
					),
					value: props.attributes.itemsToShow || 6,
					min: 3,
					max: 12,
					onChange( value ) {
						props.setAttributes( {
							itemsToShow: value || 6,
						} );
					},
				} ),
				createElement( components.ToggleControl, {
					label: __( 'نمایش چکیده‌ها', 'musicwave' ),
					checked: !! props.attributes.showExcerpt,
					onChange( value ) {
						props.setAttributes( { showExcerpt: !! value } );
					},
				} ),
				createElement( components.ToggleControl, {
					label: __( 'نمایش هنرمند', 'musicwave' ),
					checked: false !== props.attributes.showArtist,
					onChange( value ) {
						props.setAttributes( { showArtist: !! value } );
					},
				} ),
				createElement( components.ToggleControl, {
					label: __( 'نمایش تاریخ', 'musicwave' ),
					checked: !! props.attributes.showDate,
					onChange( value ) {
						props.setAttributes( { showDate: !! value } );
					},
				} ),
				createElement( components.ToggleControl, {
					label: __( 'نمایش تعداد بازدید', 'musicwave' ),
					checked: !! props.attributes.showViews,
					onChange( value ) {
						props.setAttributes( { showViews: !! value } );
					},
				} ),
				createElement( components.TextControl, {
					label: __( 'پیوند بخش (مشاهده همه)', 'musicwave' ),
					placeholder: 'https://example.com/releases',
					value: props.attributes.sectionUrl || '',
					onChange( value ) {
						props.setAttributes( { sectionUrl: value } );
					},
				} ),
				createElement( components.TextControl, {
					label: __( 'برچسب پیوند مشاهده همه', 'musicwave' ),
					placeholder: __( 'مشاهده همه', 'musicwave' ),
					value: props.attributes.sectionLinkLabel || '',
					onChange( value ) {
						props.setAttributes( { sectionLinkLabel: value } );
					},
				} )
			),
			createElement(
				components.PanelBody,
				{
					title: __( 'چیدمان و تصویر', 'musicwave' ),
					initialOpen: true,
				},
				createElement( components.SelectControl, {
					label: __( 'سبک نمایش', 'musicwave' ),
					value: resolvePresentationStyle(
						props.attributes,
						[ 'cinema', 'minimal', 'vinyl' ],
						'editorial'
					),
					options: [
						{
							label: __( 'تحریریه', 'musicwave' ),
							value: 'editorial',
						},
						{
							label: __( 'سینمایی', 'musicwave' ),
							value: 'cinema',
						},
						{
							label: __( 'وینیل', 'musicwave' ),
							value: 'vinyl',
						},
						{
							label: __( 'مینیمال', 'musicwave' ),
							value: 'minimal',
						},
					],
					onChange( value ) {
						props.setAttributes(
							applyPresentationStyle(
								props.attributes,
								value,
								'editorial'
							)
						);
					},
				} ),
				createElement( components.SelectControl, {
					label: __( 'شکل تصویر', 'musicwave' ),
					help: __(
						'نسبت تصویر و قاب کاور هر اسلاید را در پیش‌نمایش و سایت تعیین می‌کند.',
						'musicwave'
					),
					value: props.attributes.imageShape || 'square',
					options: imageShapeOptions,
					onChange( value ) {
						props.setAttributes( { imageShape: value } );
					},
				} ),
				numberStepper(
					props,
					'columns',
					__( 'ستون‌های دسکتاپ (اندازه کارت)', 'musicwave' ),
					2,
					6,
					4
				),
				createElement( components.SelectControl, {
					label: __( 'کیفیت تصویر', 'musicwave' ),
					help: __(
						'اندازهٔ فایل تصویر بارگذاری‌شده را تعیین می‌کند و روی تعداد ستون‌ها اثری ندارد.',
						'musicwave'
					),
					value: props.attributes.imageSize || 'medium',
					options: [
						{
							label: __( 'سبک (سریع‌تر)', 'musicwave' ),
							value: 'small',
						},
						{
							label: __( 'متعادل', 'musicwave' ),
							value: 'medium',
						},
						{
							label: __( 'بزرگ (کیفیت بالا)', 'musicwave' ),
							value: 'large',
						},
					],
					onChange( value ) {
						props.setAttributes( { imageSize: value } );
					},
				} ),
				-1 !==
					( props.attributes.className || '' ).indexOf(
						'is-style-cinema'
					) || 'cinema' === props.attributes.styleVariant
					? createElement( components.RangeControl, {
							label: __(
								'تیرگی لایه رویی (سبک سینمایی)',
								'musicwave'
							),
							help: __(
								'شدت سایه‌روشن روی تصویر در حالت نمایش «سینمایی» (۰ تا ۱۰۰).',
								'musicwave'
							),
							value:
								props.attributes.overlay !== undefined
									? props.attributes.overlay
									: 55,
							min: 0,
							max: 100,
							onChange( value ) {
								props.setAttributes( {
									overlay: value !== undefined ? value : 55,
								} );
							},
					  } )
					: null
			),
			queryFilteringPanel( props ),
			filterTabsPanel( props ),
			createElement(
				components.PanelBody,
				{
					title: __( 'رفتار اسلایدر', 'musicwave' ),
					initialOpen: true,
				},
				select( 'enabled', __( 'نمایش اسلایدر', 'musicwave' ) ),
				select( 'autoplay', __( 'پخش خودکار', 'musicwave' ) ),
				select( 'loop', __( 'تکرار', 'musicwave' ) ),
				select(
					'pauseOnHover',
					__( 'توقف هنگام قرارگیری نشانگر یا تمرکز', 'musicwave' )
				),
				select( 'showArrows', __( 'فلش‌های پیمایش', 'musicwave' ) ),
				select( 'showDots', __( 'نقاط صفحه‌بندی', 'musicwave' ) ),
				createElement( components.RangeControl, {
					label: __( 'فاصلهٔ پخش خودکار (میلی‌ثانیه)', 'musicwave' ),
					value: props.attributes.interval || 5000,
					min: 2000,
					max: 20000,
					step: 500,
					onChange( value ) {
						props.setAttributes( { interval: value || 5000 } );
					},
				} )
			)
		);
	}

	function shelfInspector( props ) {
		function update( key, value ) {
			var attributes = {};
			attributes[ key ] = value;
			props.setAttributes( attributes );
		}

		function toggle( key, label ) {
			return createElement( components.ToggleControl, {
				key,
				label,
				checked: false !== props.attributes[ key ],
				onChange( value ) {
					update( key, !! value );
				},
			} );
		}

		var rawLayout = props.attributes.layout || 'grid';
		var hasLegacyBento =
			'bento' === props.attributes.styleVariant ||
			-1 !==
				( props.attributes.className || '' )
					.split( /\s+/ )
					.indexOf( 'is-style-bento' );
		var activeLayout =
			'grid' === rawLayout && hasLegacyBento ? 'bento' : rawLayout;
		var usesColumns =
			'grid' === activeLayout ||
			'bento' === activeLayout ||
			'scroll' === activeLayout;
		var usesCardFields = 'slider' !== activeLayout;
		var source = props.attributes.source || 'releases';
		var isPlaylistSource = 'playlists' === source;

		return createElement(
			blockEditor.InspectorControls,
			null,
			createElement(
				components.PanelBody,
				{
					title: __( 'محتوای ویترین', 'musicwave' ),
					initialOpen: true,
				},
				// «ویژه‌نامه سرمقاله» و «اسلایدر هیرو» فقط انتشار را می‌شناسند، پس
				// انتخاب منبع در آن چیدمان‌ها نمایش داده نمی‌شود.
				'feature' !== activeLayout && 'slider' !== activeLayout
					? createElement( components.SelectControl, {
							label: __( 'منبع محتوا', 'musicwave' ),
							help: __(
								'ویترین را بین انتشارهای کاتالوگ و فهرست‌های پخش عمومی جامعه جابه‌جا کنید.',
								'musicwave'
							),
							value: source,
							options: [
								{
									label: __( 'انتشارها', 'musicwave' ),
									value: 'releases',
								},
								{
									label: __(
										'فهرست‌های پخش عمومی',
										'musicwave'
									),
									value: 'playlists',
								},
							],
							onChange( value ) {
								var next = { source: value };
								// چیدمان‌های مخصوص انتشار با منبع فهرست پخش سازگار
								// نیستند؛ در این حالت به شبکه برمی‌گردیم.
								if (
									'playlists' === value &&
									-1 !==
										[ 'feature', 'slider', 'bento' ].indexOf(
											activeLayout
										)
								) {
									next.layout = 'grid';
								}
								props.setAttributes( next );
							},
					  } )
					: null,
				createElement( components.TextControl, {
					label: __( 'برچسب بالایی', 'musicwave' ),
					value: props.attributes.eyebrow || '',
					onChange( value ) {
						update( 'eyebrow', value );
					},
				} ),
				createElement( components.TextControl, {
					label: __( 'عنوان', 'musicwave' ),
					value: props.attributes.title || '',
					help: __(
						'در صورت پر بودن نمایش داده می‌شود؛ یا خالی بگذارید و بلوک عنوان بخش را بالای ویترین قرار دهید.',
						'musicwave'
					),
					onChange( value ) {
						update( 'title', value );
					},
				} ),
				sectionIconControl( props, 'icon' ),
				createElement( components.TextareaControl, {
					label: __( 'توضیحات', 'musicwave' ),
					value: props.attributes.description || '',
					onChange( value ) {
						update( 'description', value );
					},
				} ),
				'playlists' === ( props.attributes.source || 'releases' ) ||
					! props.attributes.showAll
					? createElement( components.RangeControl, {
							label:
								'playlists' ===
								( props.attributes.source || 'releases' )
									? __(
											'فهرست‌های پخش برای نمایش',
											'musicwave'
									  )
									: __( 'انتشارها برای نمایش', 'musicwave' ),
							value: props.attributes.itemsToShow || 8,
							min: 1,
							max: 24,
							onChange( value ) {
								update( 'itemsToShow', value || 8 );
							},
					  } )
					: null,
				isPlaylistSource ||
				-1 !== [ 'feature', 'slider', 'scroll' ].indexOf( activeLayout )
					? null
					: createElement( components.ToggleControl, {
							label: __( 'نمایش همهٔ انتشارها', 'musicwave' ),
							help: __(
								'با روشن‌کردن این گزینه، کل انتشارهای منطبق با حلقهٔ کوئری استاندارد وردپرس (Query Loop) خوانده می‌شوند و صفحه‌بندی وردپرس زیر همان حلقه نمایش داده می‌شود.',
								'musicwave'
							),
							checked: !! props.attributes.showAll,
							onChange( value ) {
								update( 'showAll', !! value );
							},
					  } ),
				! isPlaylistSource &&
				props.attributes.showAll &&
				-1 === [ 'feature', 'slider', 'scroll' ].indexOf( activeLayout )
					? createElement( components.RangeControl, {
							label: __(
								'انتشار در هر صفحه',
								'musicwave'
							),
							help: __(
								'اندازهٔ صفحهٔ حلقهٔ کوئری؛ شماره‌های صفحه‌بندی وردپرس بر همین اساس ساخته می‌شوند.',
								'musicwave'
							),
							value: props.attributes.perPage || 12,
							min: 3,
							max: 24,
							onChange( value ) {
								update( 'perPage', value || 12 );
							},
					  } )
					: null
			),
			'playlists' === ( props.attributes.source || 'releases' )
				? createElement(
						components.PanelBody,
						{
							title: __( 'پرس‌وجوی فهرست پخش', 'musicwave' ),
							initialOpen: true,
						},
						createElement( components.SelectControl, {
							label: __(
								'مرتب‌سازی فهرست‌های پخش بر اساس',
								'musicwave'
							),
							value:
								props.attributes.playlistOrderBy ||
								'updated_at',
							options: [
								{
									label: __(
										'به‌تازگی به‌روزرسانی‌شده',
										'musicwave'
									),
									value: 'updated_at',
								},
								{
									label: __(
										'جدیدترین‌ها در ابتدا',
										'musicwave'
									),
									value: 'created_at',
								},
								{
									label: __( 'عنوان', 'musicwave' ),
									value: 'title',
								},
							],
							onChange( value ) {
								update( 'playlistOrderBy', value );
							},
						} ),
						createElement( components.TextControl, {
							label: __(
								'فیلتر جست‌وجوی فهرست پخش',
								'musicwave'
							),
							help: __(
								'اختیاری. فقط فهرست‌های پخش عمومی را نمایش بده که عنوانشان با این متن مطابقت دارد.',
								'musicwave'
							),
							value: props.attributes.playlistSearch || '',
							onChange( value ) {
								update( 'playlistSearch', value );
							},
						} )
				  )
				: queryFilteringPanel( props ),
			createElement(
				components.PanelBody,
				{
					title: __( 'چیدمان و تصویر', 'musicwave' ),
					initialOpen: true,
				},
				createElement( components.SelectControl, {
					label: __( 'چیدمان', 'musicwave' ),
					value: activeLayout,
					// فهرست‌های پخش فقط شبکه/ریل/فهرست را پشتیبانی می‌کنند؛
					// گزینه‌های نامرتبط نمایش داده نمی‌شوند.
					options: [
						{
							label: __( 'شبکه واکنش‌گرا', 'musicwave' ),
							value: 'grid',
						},
					]
						.concat(
							isPlaylistSource
								? []
								: [
										{
											label: __(
												'بنتو (چیدمان موزاییکی)',
												'musicwave'
											),
											value: 'bento',
										},
								  ]
						)
						.concat( [
							{
								label: __( 'ویترین افقی', 'musicwave' ),
								value: 'scroll',
							},
							{
								label: __( 'فهرست جمع‌وجور', 'musicwave' ),
								value: 'list',
							},
						] )
						.concat(
							isPlaylistSource
								? []
								: [
										{
											label: __(
												'ویژه‌نامه سرمقاله',
												'musicwave'
											),
											value: 'feature',
										},
										{
											label: __( 'اسلایدر هیرو', 'musicwave' ),
											value: 'slider',
										},
								  ]
						),
					onChange( value ) {
						var cleaned = ( props.attributes.className || '' )
							.split( /\s+/ )
							.filter( function ( token ) {
								return (
									Boolean( token ) &&
									'is-style-bento' !== token
								);
							} )
							.join( ' ' );
						var nextAttrs = {
							layout: value,
							className: cleaned,
						};
						if ( 'bento' === props.attributes.styleVariant ) {
							nextAttrs.styleVariant = '';
						}
						props.setAttributes( nextAttrs );
					},
				} ),
				createElement( components.SelectControl, {
					label: __( 'سبک نمایش', 'musicwave' ),
					value: resolvePresentationStyle(
						props.attributes,
						[ 'vinyl', 'minimal' ],
						'editorial'
					),
					options: [
						{
							label: __( 'تحریریه', 'musicwave' ),
							value: 'editorial',
						},
						{
							label: __( 'وینیل', 'musicwave' ),
							value: 'vinyl',
						},
						{
							label: __( 'مینیمال', 'musicwave' ),
							value: 'minimal',
						},
					],
					onChange( value ) {
						props.setAttributes(
							applyPresentationStyle(
								props.attributes,
								value,
								'editorial'
							)
						);
					},
				} ),
				usesColumns
					? numberStepper(
							props,
							'columns',
							__( 'ستون‌های دسکتاپ', 'musicwave' ),
							2,
							6,
							4
					  )
					: null,
				'feature' !== activeLayout
					? createElement( components.SelectControl, {
							label: __( 'شکل تصویر', 'musicwave' ),
							value: props.attributes.imageShape || 'square',
							options: imageShapeOptions,
							onChange( value ) {
								update( 'imageShape', value );
							},
					  } )
					: null,
				toggle( 'showArtwork', __( 'نمایش تصویر', 'musicwave' ) ),
				toggle(
					'showPlayButton',
					__( 'نمایش کنترل پخش', 'musicwave' )
				),
				toggle( 'showArtist', __( 'نمایش هنرمند', 'musicwave' ) ),
				usesCardFields && ! isPlaylistSource
					? toggle( 'showDate', __( 'نمایش تاریخ', 'musicwave' ) )
					: null,
				usesCardFields && ! isPlaylistSource
					? toggle( 'showExcerpt', __( 'نمایش چکیده', 'musicwave' ) )
					: null,
				usesCardFields && ! isPlaylistSource
					? toggle(
							'showAction',
							__( 'نمایش پیوند اقدام', 'musicwave' )
					  )
					: null,
				usesCardFields &&
				! isPlaylistSource &&
				false !== props.attributes.showAction
					? createElement( components.TextControl, {
							label: __( 'برچسب اقدام', 'musicwave' ),
							value: props.attributes.actionLabel || '',
							onChange( value ) {
								update( 'actionLabel', value );
							},
					  } )
					: null
			),
			// A featured-release slot only exists for release sources: with the
			// «فهرست‌های پخش عمومی» source there is no release to feature, so the
			// whole panel would be a dead setting.
			'feature' === ( props.attributes.layout || 'grid' ) && ! isPlaylistSource
				? createElement(
						components.PanelBody,
						{
							title: __( 'ویژه‌نامه سرمقاله', 'musicwave' ),
							initialOpen: true,
						},
						createElement( components.RangeControl, {
							label: __(
								'شناسه انتشار منتخب (۰ = اولین نتیجه)',
								'musicwave'
							),
							value: props.attributes.featuredReleaseId || 0,
							min: 0,
							max: 99999,
							onChange( value ) {
								update( 'featuredReleaseId', value || 0 );
							},
						} ),
						createElement( components.SelectControl, {
							label: __( 'متن بخش منتخب', 'musicwave' ),
							value: props.attributes.featuredSource || 'excerpt',
							options: [
								{
									label: __(
										'استفاده از چکیده',
										'musicwave'
									),
									value: 'excerpt',
								},
								{
									label: __(
										'استفاده از فیلد توضیحات',
										'musicwave'
									),
									value: 'custom',
								},
								{
									label: __( 'بدون متن بدنه', 'musicwave' ),
									value: 'none',
								},
							],
							onChange( value ) {
								update( 'featuredSource', value );
							},
						} ),
						createElement( components.RangeControl, {
							label: __( 'تیرگی لایه رویی', 'musicwave' ),
							help: __(
								'میزان تیرگی سایه‌روشن روی تصویر کاور اصلی (۰ = شفاف کامل، ۱۰۰ = حداکثر تیرگی).',
								'musicwave'
							),
							value:
								props.attributes.overlay !== undefined
									? props.attributes.overlay
									: 50,
							min: 0,
							max: 100,
							onChange( value ) {
								update(
									'overlay',
									value !== undefined ? value : 50
								);
							},
						} ),
						createElement( components.ToggleControl, {
							label: __( 'نمایش شماره رتبه', 'musicwave' ),
							checked: !! props.attributes.showRank,
							onChange( value ) {
								update( 'showRank', !! value );
							},
						} ),
						createElement( components.ToggleControl, {
							label: __( 'نمایش تعداد بازدید', 'musicwave' ),
							checked: !! props.attributes.showViews,
							onChange( value ) {
								update( 'showViews', !! value );
							},
						} ),
						createElement( components.RangeControl, {
							label: __( 'عرض ستون کناری (پیکسل)', 'musicwave' ),
							help: __(
								'پهنای ستون فهرست کناری در نمایش دسکتاپ (بین ۲۰۰ تا ۵۶۰ پیکسل).',
								'musicwave'
							),
							value: props.attributes.sideColumnWidth || 340,
							min: 200,
							max: 560,
							onChange( value ) {
								update( 'sideColumnWidth', value || 340 );
							},
						} )
				  )
				: null,
			'feature' === ( props.attributes.layout || 'grid' )
				? createElement(
						components.PanelBody,
						{
							title: __( 'عنوان بخش معرفی', 'musicwave' ),
							initialOpen: false,
						},
						createElement( components.TextControl, {
							label: __( 'عنوان فهرست کناری', 'musicwave' ),
							placeholder: __( 'منتخب‌های بعدی', 'musicwave' ),
							help: __(
								'عنوان بالای فهرست کناری در چیدمان ویژه‌نامه. برای پیش‌فرض («منتخب‌های بعدی») خالی بگذارید.',
								'musicwave'
							),
							value: props.attributes.asideTitle || '',
							onChange( value ) {
								update( 'asideTitle', value );
							},
						} ),
						colorFieldControl(
							__( 'رنگ عنوان', 'musicwave' ),
							__(
								'کد هگز (مثلاً #ffffff) یا انتخاب مستقیم از پالت رنگ.',
								'musicwave'
							),
							props.attributes.heroTitleColor || '',
							function ( value ) {
								update( 'heroTitleColor', value );
							},
							'#ffffff'
						),
						createElement( components.RangeControl, {
							label: __(
								'اندازه فونت عنوان (پیکسل، ۰ = پیش‌فرض)',
								'musicwave'
							),
							value: props.attributes.heroTitleSize || 0,
							min: 0,
							max: 96,
							onChange( value ) {
								update( 'heroTitleSize', value || 0 );
							},
						} ),
						colorFieldControl(
							__( 'رنگ متن بدنه', 'musicwave' ),
							__(
								'رنگ نام هنرمند و متن توضیح روی کارت معرفی (مثلاً #e2e8f0).',
								'musicwave'
							),
							props.attributes.heroTextColor || '',
							function ( value ) {
								update( 'heroTextColor', value );
							},
							'#e2e8f0'
						)
				  )
				: null,
			'feature' === ( props.attributes.layout || 'grid' )
				? createElement(
						components.PanelBody,
						{
							title: __( 'دکمه فراخوان اقدام', 'musicwave' ),
							initialOpen: false,
						},
						createElement( components.TextControl, {
							label: __( 'برچسب دکمه', 'musicwave' ),
							placeholder: __(
								'همین الان گوش دهید',
								'musicwave'
							),
							value: props.attributes.ctaLabel || '',
							onChange( value ) {
								update( 'ctaLabel', value );
							},
						} ),
						createElement( components.SelectControl, {
							label: __( 'سبک دکمه', 'musicwave' ),
							value: props.attributes.ctaStyle || 'solid',
							options: [
								{
									label: __( 'پرشدگی یکدست', 'musicwave' ),
									value: 'solid',
								},
								{
									label: __( 'خطی', 'musicwave' ),
									value: 'outline',
								},
								{
									label: __( 'شفاف', 'musicwave' ),
									value: 'ghost',
								},
							],
							onChange( value ) {
								update( 'ctaStyle', value );
							},
						} ),
						colorFieldControl(
							__( 'پس‌زمینه دکمه', 'musicwave' ),
							__(
								'برای استفاده از رنگ تأکیدی قالب، خالی بگذارید.',
								'musicwave'
							),
							props.attributes.ctaBgColor || '',
							function ( value ) {
								update( 'ctaBgColor', value );
							},
							'#1db954'
						),
						colorFieldControl(
							__( 'رنگ متن دکمه', 'musicwave' ),
							__(
								'برای استفاده از مقدار پیش‌فرض قالب، خالی بگذارید.',
								'musicwave'
							),
							props.attributes.ctaTextColor || '',
							function ( value ) {
								update( 'ctaTextColor', value );
							},
							'#ffffff'
						),
						createElement( components.RangeControl, {
							label: __( 'شعاع گوشه دکمه (پیکسل)', 'musicwave' ),
							value:
								props.attributes.ctaRadius !== undefined
									? props.attributes.ctaRadius
									: 999,
							min: 0,
							max: 999,
							onChange( value ) {
								update(
									'ctaRadius',
									value !== undefined ? value : 999
								);
							},
						} )
				  )
				: null,
			'slider' === ( props.attributes.layout || 'grid' )
				? createElement(
						components.PanelBody,
						{
							title: __( 'تنظیمات اسلایدر هیرو', 'musicwave' ),
							initialOpen: true,
						},
						createElement( components.RangeControl, {
							label: __( 'تیرگی لایه رویی', 'musicwave' ),
							value:
								props.attributes.overlay !== undefined
									? props.attributes.overlay
									: 55,
							min: 0,
							max: 100,
							onChange( value ) {
								update(
									'overlay',
									value !== undefined ? value : 55
								);
							},
						} ),
						createElement( components.ToggleControl, {
							label: __( 'پخش خودکار اسلایدها', 'musicwave' ),
							checked:
								props.attributes.autoplay === undefined
									? true
									: !! props.attributes.autoplay,
							onChange( value ) {
								update( 'autoplay', !! value );
							},
						} ),
						createElement( components.RangeControl, {
							label: __(
								'فاصلهٔ تعویض اسلایدها (میلی‌ثانیه)',
								'musicwave'
							),
							value:
								props.attributes.interval !== undefined
									? props.attributes.interval
									: 5000,
							min: 2000,
							max: 20000,
							onChange( value ) {
								update(
									'interval',
									value !== undefined ? value : 5000
								);
							},
						} ),
						createElement( components.ToggleControl, {
							label: __( 'نمایش فلش‌ها', 'musicwave' ),
							checked:
								props.attributes.showArrows === undefined
									? true
									: !! props.attributes.showArrows,
							onChange( value ) {
								update( 'showArrows', !! value );
							},
						} ),
						createElement( components.ToggleControl, {
							label: __( 'نمایش نقاط صفحه‌بندی', 'musicwave' ),
							checked:
								props.attributes.showDots === undefined
									? true
									: !! props.attributes.showDots,
							onChange( value ) {
								update( 'showDots', !! value );
							},
						} )
				  )
				: null,
			createElement(
				components.PanelBody,
				{
					title: __( 'پیوند بخش', 'musicwave' ),
					initialOpen: false,
				},
				createElement( blockEditor.URLInput, {
					label: __( 'پیوند مشاهده همه', 'musicwave' ),
					value: props.attributes.sectionUrl || '',
					onChange( value ) {
						update( 'sectionUrl', value );
					},
				} ),
				createElement( components.TextControl, {
					label: __( 'برچسب مشاهده همه', 'musicwave' ),
					value: props.attributes.sectionLinkLabel || '',
					onChange( value ) {
						update( 'sectionLinkLabel', value );
					},
				} )
			),
			// Filter tabs drive the release query only; a playlist shelf has no
			// release query, so the panel would be a dead setting there.
			isPlaylistSource ? null : filterTabsPanel( props )
		);
	}

	/**
	 * Translate one block.json metadata entry for the browser registry.
	 *
	 * @param {Object} block Localized block.json metadata.
	 * @return {Object} Translated title, description, keywords and textdomain.
	 */
	function translatedMetadata( block ) {
		var textdomain = block.textdomain || 'musicwave';

		return {
			textdomain,
			// Metadata originates in block.json and is intentionally translated at runtime.
			// eslint-disable-next-line @wordpress/i18n-no-variables
			title: __( block.title, textdomain ),
			// eslint-disable-next-line @wordpress/i18n-no-variables
			description: __( block.description, textdomain ),
			keywords: ( block.keywords || [] ).map( function ( keyword ) {
				// eslint-disable-next-line @wordpress/i18n-no-variables
				return __( keyword, textdomain );
			} ),
		};
	}

	presentationBlocks.forEach( function ( block ) {
		var existing = blocks.getBlockType( block.name );
		if ( existing ) {
			if ( existing.edit && existing.edit.__mwEnhanced ) {
				return;
			}
			if ( blocks.unregisterBlockType ) {
				blocks.unregisterBlockType( block.name );
			}
		}

		var meta = translatedMetadata( block );

		function PresentationBlockEdit( props ) {
			var wrapperProps = blockEditor.useBlockProps( {
				className: 'mw-block-editor-shell',
				'data-mw-block': block.name,
			} );
			var reservedSupportKeys = {
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
			var registeredType =
				blocks.getBlockType && blocks.getBlockType( block.name );
			var allowedSchema =
				( registeredType && registeredType.attributes ) ||
				block.attributes;
			var ssrAttributes = {};
			Object.keys( props.attributes || {} ).forEach( function ( key ) {
				if ( reservedSupportKeys[ key ] ) {
					return;
				}
				if (
					! allowedSchema ||
					! Object.keys( allowedSchema ).length ||
					Object.prototype.hasOwnProperty.call( allowedSchema, key )
				) {
					ssrAttributes[ key ] = props.attributes[ key ];
				}
			} );
			var sharedStore = window.__mwPreviewSampleStore || { release: '' };
			var samplePair = element.useState
				? element.useState( sharedStore.release || '' )
				: [ sharedStore.release || '', function () {} ];
			if ( 'function' === typeof element.useEffect ) {
				element.useEffect( function () {
					function onSync( event ) {
						var detail = ( event && event.detail ) || {};
						if ( 'release' === detail.channel ) {
							samplePair[ 1 ]( detail.value || '' );
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
				}, [] );
			}
			var isLyricsBlock =
				'music-wave/synced-lyrics' === block.name ||
				'musicwave/synced-lyrics' === block.name;
			var styleTokens = extractStyleTokens( props.attributes.className );
			var ssrQuery = {};
			if ( styleTokens ) {
				ssrQuery.mw_style_class = styleTokens;
			}
			if ( isLyricsBlock && samplePair[ 0 ] ) {
				ssrQuery.mw_preview_sample = String( samplePair[ 0 ] );
			}
			var preview = createElement(
				'div',
				wrapperProps,
				createElement( serverSideRender, {
					block: block.name,
					attributes: ssrAttributes,
					skipBlockSupportAttributes: true,
					urlQueryArgs: Object.keys( ssrQuery ).length
						? ssrQuery
						: undefined,
					EmptyResponsePlaceholder() {
						return createElement( components.Placeholder, {
							icon: block.icon,
							label: meta.title,
							instructions: __(
								'برای نمایش پیش‌نمایش زنده، انتشار منتشرشده اضافه کنید یا تنظیمات بلوک را بررسی کنید.',
								'musicwave'
							),
						} );
					},
					ErrorResponsePlaceholder( errorProps ) {
						var reason =
							errorProps &&
							errorProps.response &&
							errorProps.response.message
								? errorProps.response.message
								: __(
										'خطا در دریافت پیش‌نمایش از سرور وردپرس.',
										'musicwave'
								  );
						return createElement( components.Placeholder, {
							icon: 'warning',
							label: meta.title,
							instructions: String( reason ),
						} );
					},
				} )
			);

			if (
				'music-wave/breadcrumb' === block.name ||
				'musicwave/breadcrumb' === block.name
			) {
				return createElement(
					Fragment,
					null,
					breadcrumbInspector( props ),
					preview
				);
			}

			if (
				'music-wave/theme-text' === block.name ||
				'musicwave/theme-text' === block.name
			) {
				return createElement(
					Fragment,
					null,
					themeTextInspector( props ),
					preview
				);
			}

			if (
				'music-wave/footer-columns' === block.name ||
				'musicwave/footer-columns' === block.name
			) {
				return createElement(
					Fragment,
					null,
					footerColumnsInspector(),
					preview
				);
			}

			if (
				'music-wave/release-slider' === block.name ||
				'musicwave/release-slider' === block.name
			) {
				return createElement(
					Fragment,
					null,
					sliderInspector( props ),
					preview
				);
			}

			if (
				'music-wave/release-shelf' === block.name ||
				'musicwave/release-shelf' === block.name
			) {
				return createElement(
					Fragment,
					null,
					shelfInspector( props ),
					preview
				);
			}

			if (
				'music-wave/rail-toggle' === block.name ||
				'musicwave/rail-toggle' === block.name
			) {
				return createElement(
					Fragment,
					null,
					railToggleInspector( props ),
					preview
				);
			}

			if (
				'music-wave/header-search' === block.name ||
				'musicwave/header-search' === block.name
			) {
				return createElement(
					Fragment,
					null,
					headerSearchInspector( props ),
					preview
				);
			}

			if ( isLyricsBlock ) {
				var previewReleases =
					( window.musicWaveEditorPreviews || {} ).releases || [];
				var lyricsPreviewPanel = previewReleases.length
					? createElement(
							components.PanelBody,
							{
								title: __(
									'نمونه پیش‌نمایش در ویرایشگر',
									'musicwave'
								),
								initialOpen: false,
							},
							createElement( components.SelectControl, {
								label: __(
									'انتشار یا آلبوم نمونه برای پیش‌نمایش',
									'musicwave'
								),
								value: samplePair[ 0 ] || '',
								options: [
									{
										label: __(
											'خودکار (محتوای واقعی فعلی سایت)',
											'musicwave'
										),
										value: '',
									},
								].concat(
									previewReleases.map( function ( item ) {
										return {
											label: String(
												item.title ||
													item.name ||
													'#' + item.id
											),
											value: String( item.id ),
										};
									} )
								),
								onChange( value ) {
									var normalized = String( value || '' );
									if ( ! window.__mwPreviewSampleStore ) {
										window.__mwPreviewSampleStore = {};
									}
									window.__mwPreviewSampleStore.release =
										normalized;
									samplePair[ 1 ]( normalized );
									if (
										window.dispatchEvent &&
										'function' === typeof CustomEvent
									) {
										window.dispatchEvent(
											new CustomEvent(
												'mw:preview-sample-change',
												{
													detail: {
														channel: 'release',
														value: normalized,
													},
												}
											)
										);
									}
								},
							} )
					  )
					: null;
				return createElement(
					Fragment,
					null,
					createElement(
						blockEditor.InspectorControls,
						null,
						lyricsPreviewPanel,
						createElement(
							components.PanelBody,
							{
								title: __( 'متن هم‌زمان', 'musicwave' ),
								initialOpen: true,
							},
							createElement( components.TextControl, {
								label: __( 'عنوان', 'musicwave' ),
								value: props.attributes.heading || '',
								onChange( value ) {
									props.setAttributes( {
										heading: value,
									} );
								},
							} ),
							createElement( components.TextareaControl, {
								label: __(
									'متن جایگزین (اگر متای LRC خالی باشد)',
									'musicwave'
								),
								value: props.attributes.fallbackText || '',
								onChange( value ) {
									props.setAttributes( {
										fallbackText: value,
									} );
								},
							} )
						)
					),
					preview
				);
			}

			return preview;
		}
		PresentationBlockEdit.__mwEnhanced = true;

		blocks.registerBlockType(
			block.name,
			Object.assign( {}, existing || {}, {
				apiVersion: block.apiVersion || 3,
				title: meta.title,
				description: meta.description,
				category: block.category || 'music-wave',
				icon: block.icon,
				keywords: meta.keywords,
				textdomain: meta.textdomain,
				attributes: Object.assign(
					{},
					block.attributes || {},
					( existing && existing.attributes ) || {}
				),
				supports: Object.assign(
					{},
					block.supports || {},
					( existing && existing.supports ) || {}
				),
				example: block.example || {},
				styles:
					existing && existing.styles && existing.styles.length
						? existing.styles
						: resolvePresentationStyles( block ),
				edit: PresentationBlockEdit,
				save() {
					return null;
				},
			} )
		);
	} );

	/*
	 * Lane 3 — static child-bearing blocks (`music-wave/section-head`).
	 */
	var sectionHeadTemplate = [
		[
			'core/group',
			{ className: 'mw-section-head__text', layout: { type: 'default' } },
			[
				[
					'core/paragraph',
					{
						className: 'mw-eyebrow',
						placeholder: __( 'برچسب بالایی', 'musicwave' ),
					},
				],
				[
					'core/heading',
					{ level: 2, placeholder: __( 'عنوان بخش', 'musicwave' ) },
				],
				[
					'core/paragraph',
					{ placeholder: __( 'توضیح کوتاه بخش', 'musicwave' ) },
				],
			],
		],
	];

	var staticBlockList = Array.isArray( staticBlocks ) ? staticBlocks : [];

	staticBlockList.forEach( function ( block ) {
		var existing = blocks.getBlockType( block.name );
		if ( existing ) {
			if ( existing.edit && existing.edit.__mwEnhanced ) {
				return;
			}
			if ( blocks.unregisterBlockType ) {
				blocks.unregisterBlockType( block.name );
			}
		}

		var meta = translatedMetadata( block );
		var isSectionHead = 'music-wave/section-head' === block.name;

		function StaticBlockEdit( props ) {
			var blockProps = blockEditor.useBlockProps( {
				className: 'mw-section-head',
			} );

			if ( ! isSectionHead ) {
				return createElement(
					'div',
					blockProps,
					createElement( blockEditor.InnerBlocks, {
						template: sectionHeadTemplate,
						templateLock: false,
					} )
				);
			}

			var sectionUrl = props.attributes.sectionUrl || '';
			var sectionLabel = props.attributes.sectionLinkLabel || '';

			var inspector = createElement(
				blockEditor.InspectorControls,
				null,
				createElement(
					components.PanelBody,
					{
						title: __( 'پیوند بخش', 'musicwave' ),
						initialOpen: true,
					},
					createElement( components.TextControl, {
						label: __( 'پیوند مشاهده همه (سمت چپ)', 'musicwave' ),
						placeholder: 'https://example.com/releases',
						value: sectionUrl,
						onChange( value ) {
							props.setAttributes( { sectionUrl: value } );
						},
					} ),
					createElement( components.TextControl, {
						label: __( 'برچسب پیوند', 'musicwave' ),
						placeholder: __( 'مشاهده همه', 'musicwave' ),
						value: sectionLabel,
						onChange( value ) {
							props.setAttributes( {
								sectionLinkLabel: value,
							} );
						},
					} )
				)
			);

			var moreLink = sectionUrl
				? createElement(
						'a',
						{
							className: 'mw-section-head__more',
							href: sectionUrl,
							onClick( e ) {
								e.preventDefault();
							},
						},
						sectionLabel || 'مشاهده همه',
						createElement( 'span', { 'aria-hidden': 'true' }, ' ←' )
				  )
				: null;

			return createElement(
				'div',
				blockProps,
				inspector,
				createElement( blockEditor.InnerBlocks, {
					template: sectionHeadTemplate,
					templateLock: false,
				} ),
				moreLink
			);
		}
		StaticBlockEdit.__mwEnhanced = true;

		blocks.registerBlockType(
			block.name,
			Object.assign( {}, existing || {}, {
				apiVersion: block.apiVersion || 3,
				title: meta.title,
				description: meta.description,
				category: block.category || 'music-wave',
				icon: block.icon,
				keywords: meta.keywords,
				textdomain: meta.textdomain,
				attributes: Object.assign(
					{},
					block.attributes || {},
					( existing && existing.attributes ) || {}
				),
				supports: Object.assign(
					{},
					block.supports || {},
					( existing && existing.supports ) || {}
				),
				example: block.example || {},
				styles:
					existing && existing.styles && existing.styles.length
						? existing.styles
						: block.styles || [],
				edit: StaticBlockEdit,
				save( props ) {
					var blockProps = blockEditor.useBlockProps.save( {
						className: 'mw-section-head',
					} );
					var saveAttrs =
						props && props.attributes ? props.attributes : {};
					var saveUrl = saveAttrs.sectionUrl || '';
					var saveLabel = saveAttrs.sectionLinkLabel || '';
					var saveMore = saveUrl
						? createElement(
								'a',
								{
									className: 'mw-section-head__more',
									href: saveUrl,
								},
								saveLabel || 'مشاهده همه',
								createElement(
									'span',
									{ 'aria-hidden': 'true' },
									' ←'
								)
						  )
						: null;

					return createElement(
						'div',
						blockProps,
						createElement( blockEditor.InnerBlocks.Content ),
						saveMore
					);
				},
			} )
		);
	} );
} )(
	window.wp && window.wp.blocks,
	window.wp && window.wp.element,
	window.wp && window.wp.blockEditor,
	window.wp && window.wp.components,
	window.wp && window.wp.i18n,
	window.wp && window.wp.serverSideRender,
	window.musicwavePresentationBlocks || [],
	window.musicwaveStaticBlocks || []
);
