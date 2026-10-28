/**
 * Interactive access-mode controller, VIP membership level quick-selector and
 * «عوامل انتشار» repeater for `mw_release` meta boxes and MusicWave Core
 * settings screens.
 */
( function () {
	'use strict';

	const MODE_HINTS = {
		public: 'عمومی: همه بازدیدکنندگان (حتی مهمانان) به صفحه و محتوای انتشار دسترسی دارند.',
		logged_in:
			'فقط کاربران ثبت‌نام‌شده: دسترسی رایگان برای تمام اعضای واردشده؛ مهمانان به صفحه ورود/ثبت‌نام هدایت می‌شوند.',
		purchase:
			'خرید تکی الزامی: فقط خریداران محصول WooCommerce پیوندشده دسترسی کامل و دانلود دریافت می‌کنند.',
		membership:
			'اشتراک VIP الزامی: مشترکین دارای یکی از کلیدهای سطح عضویت (مانند mw_vip) دسترسی کامل دارند.',
		purchase_or_membership:
			'دوگانه هوشمند (خرید یا اشتراک VIP): کاربر هم با خرید تکی محصول WooCommerce و هم با داشتن اشتراک فعال VIP فوراً دسترسی کامل می‌گیرد.',
		restricted:
			'محدودشده: دسترسی عمومی بسته است و فقط مدیران یا دارندگان مجوز دستی به آن دسترسی دارند.',
	};

	/** Mirrors `ReleaseMetaBox::CREDIT_INDEX_PLACEHOLDER` in the row template. */
	const CREDIT_INDEX_PLACEHOLDER = '__mw_credit_index__';

	function parseLevels( raw ) {
		return String( raw || '' )
			.split( /[\s,|]+/ )
			.map( function ( part ) {
				return part
					.trim()
					.toLowerCase()
					.replace( /[^a-z0-9_-]/g, '' );
			} )
			.filter( Boolean );
	}

	function initPicker( picker ) {
		const targetId = picker.getAttribute( 'data-mw-level-picker' );
		const input = targetId ? document.getElementById( targetId ) : null;
		if ( ! input ) {
			return;
		}
		const chips = Array.prototype.slice.call(
			picker.querySelectorAll( '[data-mw-level-slug]' )
		);

		function syncChips() {
			const active = parseLevels( input.value );
			chips.forEach( function ( chip ) {
				const slug = chip.getAttribute( 'data-mw-level-slug' ) || '';
				const isOn = active.indexOf( slug ) !== -1;
				chip.classList.toggle( 'is-active', isOn );
				chip.setAttribute( 'aria-pressed', isOn ? 'true' : 'false' );
			} );
		}

		chips.forEach( function ( chip ) {
			chip.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				const slug = chip.getAttribute( 'data-mw-level-slug' ) || '';
				if ( ! slug ) {
					return;
				}
				const current = parseLevels( input.value );
				const idx = current.indexOf( slug );
				if ( idx !== -1 ) {
					current.splice( idx, 1 );
				} else {
					current.push( slug );
				}
				input.value = current.join( ', ' );
				syncChips();
			} );
		} );

		input.addEventListener( 'input', syncChips );
		syncChips();

		const modeSelect = document.getElementById( 'mw_access_mode' );
		if ( modeSelect ) {
			modeSelect.addEventListener( 'change', function () {
				const mode = modeSelect.value;
				if (
					( mode === 'membership' ||
						mode === 'purchase_or_membership' ) &&
					! parseLevels( input.value ).length &&
					chips.length
				) {
					const defaultChip =
						chips.find( function ( c ) {
							return (
								c.getAttribute( 'data-mw-level-slug' ) ===
								'mw_vip'
							);
						} ) || chips[ 0 ];
					if ( defaultChip ) {
						input.value =
							defaultChip.getAttribute( 'data-mw-level-slug' ) ||
							'';
						syncChips();
					}
				}
			} );
		}
	}

	function initAccessModeVisibility() {
		const modeSelect = document.getElementById( 'mw_access_mode' );
		if ( ! modeSelect ) {
			return;
		}
		const productField = document.querySelector(
			'[data-mw-field-key="mw_product_ids"]'
		);
		const levelField = document.querySelector(
			'[data-mw-field-key="mw_membership_levels"]'
		);
		const hintEl = document.querySelector( '[data-mw-access-hint]' );

		function syncMode() {
			const mode = modeSelect.value || 'public';
			const usesPurchase =
				mode === 'purchase' || mode === 'purchase_or_membership';
			const usesMembership =
				mode === 'membership' || mode === 'purchase_or_membership';

			if ( productField ) {
				productField.classList.toggle( 'is-highlighted', usesPurchase );
				productField.classList.toggle( 'is-muted', ! usesPurchase );
			}
			if ( levelField ) {
				levelField.classList.toggle( 'is-highlighted', usesMembership );
				levelField.classList.toggle( 'is-muted', ! usesMembership );
			}
			if ( hintEl ) {
				hintEl.textContent = MODE_HINTS[ mode ] || '';
			}
		}

		modeSelect.addEventListener( 'change', syncMode );
		syncMode();
	}

	/**
	 * Wire the «عوامل انتشار» repeater: append rows from the server-rendered
	 * template and drop rows the editor removes. Row indices only need to stay
	 * unique, so the next one is taken from the highest index still on screen and
	 * the server reindexes whatever arrives.
	 *
	 * @param {HTMLElement} root Repeater wrapper.
	 */
	function initCreditsRepeater( root ) {
		const rows = root.querySelector( '[data-mw-credit-rows]' );
		const template = root.querySelector( '[data-mw-credit-template]' );
		const addButton = root.querySelector( '[data-mw-credit-add]' );
		if (
			! rows ||
			! template ||
			! addButton ||
			! template.content.firstElementChild
		) {
			return;
		}

		function nextIndex() {
			let highest = -1;
			Array.prototype.forEach.call(
				rows.querySelectorAll( '[data-mw-credit-index]' ),
				function ( row ) {
					const index = parseInt(
						row.getAttribute( 'data-mw-credit-index' ),
						10
					);
					highest = Math.max( highest, isNaN( index ) ? -1 : index );
				}
			);
			return String( highest + 1 );
		}

		function addRow() {
			const row = template.content.firstElementChild.cloneNode( true );
			const index = nextIndex();
			row.setAttribute( 'data-mw-credit-index', index );
			Array.prototype.forEach.call(
				row.querySelectorAll( '[name]' ),
				function ( input ) {
					input.setAttribute(
						'name',
						input
							.getAttribute( 'name' )
							.split( CREDIT_INDEX_PLACEHOLDER )
							.join( index )
					);
				}
			);
			rows.appendChild( row );
			const nameInput = row.querySelector( '.mw-credits-repeater__name' );
			if ( nameInput ) {
				nameInput.focus();
			}
		}

		addButton.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			addRow();
		} );

		rows.addEventListener( 'click', function ( event ) {
			const button = event.target.closest( '[data-mw-credit-remove]' );
			if ( ! button ) {
				return;
			}
			event.preventDefault();
			const row = button.closest( '[data-mw-credit-row]' );
			if ( row ) {
				row.remove();
			}
		} );
	}

	function boot() {
		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-mw-level-picker]' ),
			initPicker
		);
		initAccessModeVisibility();
		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-mw-credits-repeater]' ),
			initCreditsRepeater
		);
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
