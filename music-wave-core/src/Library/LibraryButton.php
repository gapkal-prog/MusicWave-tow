<?php
/**
 * Shared add-to-library button markup and frontend behavior.
 *
 * Clean 2-mode presentation (`heart` icon-only disc vs `button` full pill with icon + text),
 * unified editor placeholder parity, DRY label resolution, SOLID SRP.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Library;

use ManaCore\MusicWave\Core\Blocks\BlockSupport;
use ManaCore\MusicWave\Core\Blocks\Concerns\ActionButtonStyle;
use ManaCore\MusicWave\Core\Account\AuthPolicy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LibraryButton {
	public const SCRIPT = 'music-wave-library';
	public const STYLE  = 'music-wave-library-style';

	/**
	 * Inline heart glyph used by both icon-only and full button modes.
	 */
	public const HEART_ICON = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false" aria-hidden="true" class="mw-library-button__heart-svg"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>';

	public const FOLLOW_ICON = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false" aria-hidden="true" class="mw-library-button__follow-svg"><path d="M15.5 19.5v-1.2a3.8 3.8 0 0 0-3.8-3.8H6.8a3.8 3.8 0 0 0-3.8 3.8v1.2"/><circle cx="9.25" cy="7.5" r="3.5"/><path class="mw-library-button__follow-plus" d="M19 8.5v6M16 11.5h6"/><path class="mw-library-button__follow-check" d="m16 11.8 2 2 4-4"/></svg>';

	/** @var LibraryRepository|null */
	private static $repository = null;

	/**
	 * Bind the shared library repository used for state lookups.
	 *
	 * @return void
	 */
	public static function bind( LibraryRepository $repository ): void {
		self::$repository = $repository;
	}

	/**
	 * Return the current repository binding, when available.
	 */
	public static function repository(): ?LibraryRepository {
		return self::$repository;
	}

	/**
	 * Normalize legacy style names into clean 2-mode presentation (`heart` vs `button`).
	 */
	public static function normalize_style( string $style ): string {
		$clean = sanitize_key( $style );
		if ( in_array( $clean, array( 'heart', 'icon' ), true ) ) {
			return 'heart';
		}

		return 'button';
	}

	/**
	 * Sanitized host classes injected by composing renderers (`collection-list` rows, cards).
	 *
	 * Keeps one button implementation while letting a parent layout align the
	 * trigger with its own action cluster (DRY, Open/Closed).
	 *
	 * @param array<string, mixed> $settings Button settings.
	 * @return array<int, string>
	 */
	private static function extra_classes( array $settings ): array {
		$raw = $settings['class'] ?? array();
		$raw = is_array( $raw ) ? $raw : array( $raw );
		$out = array();
		foreach ( $raw as $chunk ) {
			if ( ! is_scalar( $chunk ) ) {
				continue;
			}
			$parts = preg_split( '/\s+/', trim( (string) $chunk ) );
			foreach ( ( is_array( $parts ) ? $parts : array() ) as $candidate ) {
				if ( '' === $candidate ) {
					continue;
				}
				$clean = sanitize_html_class( $candidate );
				if ( '' !== $clean ) {
					$out[] = $clean;
				}
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Build the aggregate "saved by N" badge.
	 *
	 * Only a total is resolved — never member identities — and the value is
	 * served from the repository's short-lived cache. The badge is rendered
	 * even at zero (visually hidden) so the client can keep it in sync while
	 * the visitor toggles the button. Purely decorative for assistive tech:
	 * the button's accessible name stays the stable action label.
	 *
	 * @param string               $type     Library item type.
	 * @param int                  $item_id  Target item ID.
	 * @param array<string, mixed> $settings Button settings.
	 * @param bool                 $sample   Render a sample value for the editor preview.
	 */
	private static function count_badge( string $type, int $item_id, array $settings, bool $sample = false ): string {
		if ( empty( $settings['showCount'] ) ) {
			return '';
		}

		$count = 0;
		if ( $sample ) {
			$count = 12;
		} elseif ( null !== self::$repository ) {
			$count = self::$repository->count_for_item( $type, $item_id );
		}

		$formatted = function_exists( 'number_format_i18n' ) ? number_format_i18n( $count ) : (string) $count;

		return '<span class="mw-library-button__count" data-mw-library-count-value="' . esc_attr( (string) $count ) . '" aria-hidden="true"'
			. ( $count > 0 ? '' : ' hidden' ) . '>' . esc_html( $formatted ) . '</span>';
	}

	/**
	 * Render one library toggle button for a release or artist.
	 *
	 * @param string               $type     Library item type (`release`|`artist`|`wishlist`|`presave`).
	 * @param int                  $item_id  Release post ID or artist term ID.
	 * @param array<string, mixed> $settings Optional label, style, and size overrides.
	 * @return string
	 */
	public static function markup( string $type, int $item_id, array $settings = array() ): string {
		$supported = array(
			LibraryRepository::TYPE_RELEASE,
			LibraryRepository::TYPE_ARTIST,
			LibraryRepository::TYPE_WISHLIST,
			LibraryRepository::TYPE_PRESAVE,
		);
		if ( $item_id < 1 || ! in_array( $type, $supported, true ) ) {
			return '';
		}
		if ( LibraryRepository::TYPE_PRESAVE === $type && null !== self::$repository && ! self::$repository->is_upcoming( $item_id ) ) {
			return '';
		}

		self::enqueue_assets();

		$raw_style = isset( $settings['style'] ) && is_scalar( $settings['style'] ) ? sanitize_key( (string) $settings['style'] ) : 'button';
		$mode      = self::normalize_style( $raw_style );
		$compact   = ! empty( $settings['compact'] );
		$is_heart  = 'heart' === $mode;
		$show_icon = $is_heart || ! isset( $settings['showIcon'] ) || false !== $settings['showIcon'];

		$in_library = null !== self::$repository && self::$repository->has( get_current_user_id(), $type, $item_id );
		$label      = self::label( $type, $in_library, $settings );
		$badge      = self::count_badge( $type, $item_id, $settings );

		$action_mode = $is_heart ? 'icon' : 'button';
		$variant     = ActionButtonStyle::resolve_variant( $settings, ActionButtonStyle::VARIANT_SURFACE );
		$size        = $compact ? ActionButtonStyle::SIZE_SM : ActionButtonStyle::resolve_size( $settings, ActionButtonStyle::SIZE_MD );
		$shape       = ActionButtonStyle::resolve_shape( $settings, ActionButtonStyle::SHAPE_PILL );
		$classes     = array(
			'mw-library-button',
			'mw-action-button',
			'mw-action-button--' . $action_mode,
			'mw-action-button--' . $variant,
			'mw-action-button--' . $size,
			'mw-action-button--' . $shape,
			'mw-library-button--' . $mode,
		);
		if ( ! $is_heart && in_array( $raw_style, array( 'solid', 'outline', 'ghost' ), true ) ) {
			$classes[] = 'mw-library-button--' . $raw_style;
		}
		if ( $compact ) {
			$classes[] = 'mw-library-button--compact';
		}
		if ( $is_heart ) {
			$classes[] = 'mw-library-button--icon-only';
		}
		if ( $in_library ) {
			$classes[] = 'is-saved';
		}
		if ( '' !== $badge ) {
			$classes[] = 'mw-library-button--has-count';
		}
		foreach ( self::extra_classes( $settings ) as $extra_class ) {
			$classes[] = $extra_class;
		}
		$class = implode( ' ', array_unique( $classes ) );

		$default_svg = LibraryRepository::TYPE_ARTIST === $type ? self::FOLLOW_ICON : self::HEART_ICON;
		$default_key = LibraryRepository::TYPE_ARTIST === $type ? 'user-plus' : 'heart';
		$raw_icon    = isset( $settings['icon'] ) && is_scalar( $settings['icon'] ) ? trim( (string) $settings['icon'] ) : '';
		$icon_svg    = ( '' === $raw_icon || $default_key === $raw_icon )
			? $default_svg
			: ( 'heart' === $raw_icon ? self::HEART_ICON : ActionButtonStyle::render_icon( $settings, $default_key, 20 ) );
		$icon        = '';
		if ( $show_icon ) {
			$icon = '<span class="mw-library-button__icon" aria-hidden="true">' . $icon_svg . '</span>';
		}

		$label_class = $is_heart ? 'mw-library-button__label screen-reader-text' : 'mw-library-button__label';
		$label_html  = '<span class="' . esc_attr( $label_class ) . '">' . esc_html( $label ) . '</span>';

		if ( get_current_user_id() < 1 ) {
			return '<a class="' . esc_attr( $class ) . ' mw-library-button--guest" href="' . esc_url( AuthPolicy::login_url( BlockSupport::current_url() ) ) . '" aria-label="' . esc_attr( $label ) . '">' . $icon . $label_html . $badge . '</a>';
		}

		return '<button type="button" class="' . esc_attr( $class ) . '" data-mw-library-type="' . esc_attr( $type ) . '" data-mw-library-id="' . esc_attr( (string) $item_id ) . '" data-mw-library-state="' . ( $in_library ? 'in' : 'out' ) . '" data-mw-library-label-add="' . esc_attr( self::label( $type, false, $settings ) ) . '" data-mw-library-label-added="' . esc_attr( self::label( $type, true, $settings ) ) . '" aria-pressed="' . ( $in_library ? 'true' : 'false' ) . '" aria-label="' . esc_attr( $label ) . '">' . $icon . $label_html . $badge . '<span class="mw-library-button__status" role="status" aria-live="polite"></span></button>';
	}

	/**
	 * Render a faithful editor preview button matching the exact active `style`,
	 * `variant`, `size`, `shape`, `icon`, `itemType`, and `label` settings.
	 *
	 * @param string               $type     Target item type.
	 * @param array<string, mixed> $settings Button settings.
	 */
	public static function placeholder_markup( string $type, array $settings = array() ): string {
		$mode        = self::normalize_style( isset( $settings['style'] ) && is_scalar( $settings['style'] ) ? (string) $settings['style'] : 'heart' );
		$compact     = ! empty( $settings['compact'] );
		$is_heart    = 'heart' === $mode;
		$label       = self::label( $type, false, $settings );
		$badge       = self::count_badge( $type, 0, $settings, true );
		$action_mode = $is_heart ? 'icon' : 'button';
		$variant     = ActionButtonStyle::resolve_variant( $settings, ActionButtonStyle::VARIANT_SURFACE );
		$size        = $compact ? ActionButtonStyle::SIZE_SM : ActionButtonStyle::resolve_size( $settings, ActionButtonStyle::SIZE_MD );
		$shape       = ActionButtonStyle::resolve_shape( $settings, ActionButtonStyle::SHAPE_PILL );
		$classes     = 'mw-library-button mw-action-button mw-action-button--' . $action_mode
			. ' mw-action-button--' . $variant
			. ' mw-action-button--' . $size
			. ' mw-action-button--' . $shape
			. ' mw-library-button--' . $mode
			. ( $compact ? ' mw-library-button--compact' : '' )
			. ( $is_heart ? ' mw-library-button--icon-only' : '' )
			. ( '' !== $badge ? ' mw-library-button--has-count' : '' )
			. ' mw-library-button--placeholder';
		$extras      = self::extra_classes( $settings );
		if ( array() !== $extras ) {
			$classes .= ' ' . implode( ' ', $extras );
		}
		$default_svg = LibraryRepository::TYPE_ARTIST === $type ? self::FOLLOW_ICON : self::HEART_ICON;
		$default_key = LibraryRepository::TYPE_ARTIST === $type ? 'user-plus' : 'heart';
		$raw_icon    = isset( $settings['icon'] ) && is_scalar( $settings['icon'] ) ? trim( (string) $settings['icon'] ) : '';
		$icon_svg    = ( '' === $raw_icon || $default_key === $raw_icon )
			? $default_svg
			: ( 'heart' === $raw_icon ? self::HEART_ICON : ActionButtonStyle::render_icon( $settings, $default_key, 20 ) );
		$icon        = '<span class="mw-library-button__icon" aria-hidden="true">' . $icon_svg . '</span>';
		$label_class = $is_heart ? 'mw-library-button__label screen-reader-text' : 'mw-library-button__label';

		return '<button type="button" class="' . esc_attr( $classes ) . '" aria-label="' . esc_attr( $label ) . '">'
			. $icon
			. '<span class="' . esc_attr( $label_class ) . '">' . esc_html( $label ) . '</span>'
			. $badge
			. '</button>';
	}

	/**
	 * Load the library controller script and its style once.
	 *
	 * @return void
	 */
	public static function enqueue_assets(): void {
		if ( function_exists( 'wp_enqueue_style' ) ) {
			wp_enqueue_style( self::STYLE, MUSIC_WAVE_CORE_URL . 'assets/library.css', array(), MUSIC_WAVE_CORE_VERSION );
		}

		if ( is_admin() ) {
			return;
		}

		wp_enqueue_script(
			self::SCRIPT,
			MUSIC_WAVE_CORE_URL . 'assets/library.js',
			array( 'wp-api-fetch' ),
			MUSIC_WAVE_CORE_VERSION,
			true
		);
		wp_localize_script(
			self::SCRIPT,
			'musicWaveLibrary',
			array(
				'restUrl'      => esc_url_raw( rest_url() ),
				'restNonce'    => wp_create_nonce( 'wp_rest' ),
				'errorMessage' => __( 'کتابخانه شما به‌روز نشد. دوباره امتحان کنید.', 'music-wave-core' ),
				'sessionError' => __( 'جلسه شما تمام شده است. صفحه را تازه کنید یا دوباره وارد شوید.', 'music-wave-core' ),
			)
		);
	}

	/**
	 * Resolve the visible button label for a state.
	 *
	 * @param string               $type       Library item type.
	 * @param bool                 $in_library Whether the item is saved.
	 * @param array<string, mixed> $settings   Label overrides.
	 */
	public static function label( string $type, bool $in_library, array $settings ): string {
		$override_key = $in_library ? 'addedLabel' : 'label';
		if ( isset( $settings[ $override_key ] ) && is_scalar( $settings[ $override_key ] ) ) {
			$override = sanitize_text_field( (string) $settings[ $override_key ] );
			if ( '' !== $override ) {
				return $override;
			}
		}

		if ( LibraryRepository::TYPE_ARTIST === $type ) {
			return $in_library ? __( 'دنبال', 'music-wave-core' ) : __( 'هنرمند را دنبال‌کردن', 'music-wave-core' );
		}
		if ( LibraryRepository::TYPE_WISHLIST === $type ) {
			return $in_library ? __( 'در فهرست علاقه‌مندی‌های شما', 'music-wave-core' ) : __( 'افزودن به فهرست علاقه‌مندی‌ها', 'music-wave-core' );
		}
		if ( LibraryRepository::TYPE_PRESAVE === $type ) {
			return $in_library ? __( 'از پیش ذخیره‌شده', 'music-wave-core' ) : __( 'پیش ذخیره', 'music-wave-core' );
		}

		return $in_library ? __( 'در کتابخانه شما', 'music-wave-core' ) : __( 'افزودن به کتابخانه', 'music-wave-core' );
	}
}
