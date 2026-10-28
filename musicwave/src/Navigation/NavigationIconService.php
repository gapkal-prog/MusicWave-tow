<?php
declare(strict_types=1);

namespace ManaCore\MusicWave\Theme\Navigation;

use WP_HTML_Tag_Processor;
use ManaCore\MusicWave\Core\Account\AuthPolicy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Injects accessible 24x24 SVG glyphs and avatars into navigation items and
 * renders the stream rail user account card.
 */
final class NavigationIconService {

	/**
	 * Cached compiled SVG icon markup keyed by slug.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $icons = null;

	/**
	 * Register block context propagation and render filters.
	 */
	public function register(): void {
		add_filter( 'register_block_type_args', array( $this, 'filter_block_context' ), 10, 2 );
		add_filter( 'render_block', array( $this, 'render_nav_icon' ), 10, 3 );
		add_filter( 'render_block', array( $this, 'render_rail_user' ), 10, 2 );
	}

	/**
	 * Compiled 24x24 stroke SVG icon map.
	 *
	 * @return array<string, string>
	 */
	public function icons(): array {
		if ( null !== $this->icons ) {
			return $this->icons;
		}

		$paths = array(
			'home'      => '<path d="M3 11.5 12 4l9 7.5"/><path d="M5.5 10.5V20h13v-9.5"/><path d="M10 20v-5.5h4V20"/>',
			'discover'  => '<circle cx="12" cy="12" r="9"/><path d="m15.5 8.5-2 5-5 2 2-5z"/>',
			'browse'    => '<rect x="4" y="4" width="6.5" height="6.5" rx="1.5"/><rect x="13.5" y="4" width="6.5" height="6.5" rx="1.5"/><rect x="4" y="13.5" width="6.5" height="6.5" rx="1.5"/><rect x="13.5" y="13.5" width="6.5" height="6.5" rx="1.5"/>',
			'tracks'    => '<path d="M9 18V5.5l11-2.5v12"/><circle cx="6.5" cy="18" r="2.5"/><circle cx="17.5" cy="15" r="2.5"/>',
			'albums'    => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="2.5"/>',
			'artists'   => '<circle cx="9.5" cy="8" r="3.5"/><path d="M3.5 20a6 6 0 0 1 12 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7"/><path d="M17.5 14.5a6 6 0 0 1 3 5.5"/>',
			'playlists' => '<path d="M4 6h11M4 12h11M4 18h6"/><path d="M19 6v10"/><circle cx="16.5" cy="17" r="2.5"/>',
			'podcasts'  => '<rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5.5 11a6.5 6.5 0 0 0 13 0"/><path d="M12 17.5V21M9 21h6"/>',
			'library'   => '<path d="M3.5 7.5a2 2 0 0 1 2-2H9l2 2h7.5a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2h-13a2 2 0 0 1-2-2z"/>',
			'favorites' => '<path d="M12 20.5 4 12.9A4.8 4.8 0 1 1 12 6.7a4.8 4.8 0 1 1 8 6.2Z"/>',
			'downloads' => '<path d="M12 4v11"/><path d="m7.5 10.5 4.5 4.5 4.5-4.5"/><path d="M5 19.5h14"/>',
			'history'   => '<circle cx="12" cy="12" r="9"/><path d="M12 7.5V12l3 2"/>',
			'charts'    => '<path d="M4 17.5 10 11l4 4 6-7"/><path d="M15 8h5v5"/>',
			'radio'     => '<circle cx="12" cy="12" r="2.5"/><path d="M7 17a7 7 0 0 1 0-10M17 7a7 7 0 0 1 0 10M4.5 19.5a10.5 10.5 0 0 1 0-15M19.5 4.5a10.5 10.5 0 0 1 0 15"/>',
			'video'     => '<rect x="3" y="6" width="13" height="12" rx="2"/><path d="m16 10 5-2.5v9L16 14"/>',
			'genres'    => '<path d="M3.5 12.5v-8a1 1 0 0 1 1-1h8l8 8-9 9z"/><circle cx="8" cy="8" r="1.2"/>',
			'lyrics'    => '<path d="M4 6h16M4 11h11M4 16h8"/>',
			'search'    => '<circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5"/>',
			'settings'  => '<path d="M21 6h-6M9 6H3M21 12h-8M7 12H3M21 18h-4M11 18H3"/><path d="M15 3v6M7 9v6M17 15v6"/>',
			'account'   => '<circle cx="12" cy="8.5" r="3.75"/><path d="M4.75 20a7.25 7.25 0 0 1 14.5 0"/>',
			'bell'      => '<path d="M6 16.5V11a6 6 0 0 1 12 0v5.5l1.5 2h-15z"/><path d="M10 21a2 2 0 0 0 4 0"/>',
			'vip'       => '<path d="m4 8 4 4 4-6 4 6 4-4-1.5 10h-13z"/>',
			'logout'    => '<path d="M14 4h4a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-4"/><path d="M10 8l-4 4 4 4M6 12h9"/>',
			'dot'       => '<circle cx="12" cy="12" r="3.5"/>',
		);

		$this->icons = array();
		foreach ( $paths as $key => $path ) {
			$this->icons[ $key ] = '<svg class="mw-nav-icon__svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $path . '</svg>';
		}

		return $this->icons;
	}

	/**
	 * Persian and English keyword map resolving labels/URLs to icon slugs.
	 *
	 * @return array<string, array<int, string>>
	 */
	public function keywords(): array {
		return array(
			'home'      => array( 'خانه', 'صفحه اصلی', 'home' ),
			'discover'  => array( 'کشف', 'اکسپلور', 'پیشنهاد', 'discover', 'explore' ),
			'browse'    => array( 'مرور', 'دسته', 'browse', 'categor' ),
			'charts'    => array( 'برتر', 'چارت', 'ترند', 'داغ', 'chart', 'trending', 'top' ),
			'podcasts'  => array( 'پادکست', 'podcast' ),
			'playlists' => array( 'پلی', 'فهرست پخش', 'playlist' ),
			'albums'    => array( 'آلبوم', 'album' ),
			'artists'   => array( 'هنرمند', 'خواننده', 'artist' ),
			'tracks'    => array( 'قطعه', 'آهنگ', 'ترک', 'موزیک', 'track', 'song', 'mw_release', 'release' ),
			'favorites' => array( 'علاقه', 'محبوب', 'favorite', 'favourite', 'liked', 'loved' ),
			'downloads' => array( 'دانلود', 'download' ),
			'history'   => array( 'تاریخچه', 'اخیر', 'history', 'recent' ),
			'library'   => array( 'کتابخانه', 'گنجینه', 'library' ),
			'radio'     => array( 'رادیو', 'زنده', 'radio', 'live' ),
			'video'     => array( 'ویدیو', 'ویدئو', 'کلیپ', 'video' ),
			'genres'    => array( 'ژانر', 'سبک', 'genre' ),
			'lyrics'    => array( 'متن', 'lyric' ),
			'search'    => array( 'جستجو', 'جست‌وجو', 'search' ),
			'settings'  => array( 'تنظیمات', 'setting' ),
			'bell'      => array( 'اعلان', 'notification' ),
			'vip'       => array( 'vip', 'ویژه', 'اشتراک', 'premium' ),
			'account'   => array( 'حساب', 'پروفایل', 'ورود', 'account', 'profile', 'login' ),
		);
	}

	/**
	 * Resolve icon key from explicit CSS class or keyword match.
	 */
	public function resolve_key( string $class_name, string $label, string $url, bool $auto ): string {
		$icons = $this->icons();
		if ( preg_match( '/\bmw-(?:icon|rail__item)-{1,2}([a-z0-9]+)/', $class_name, $m ) && isset( $icons[ $m[1] ] ) ) {
			return $m[1];
		}
		if ( ! $auto ) {
			return '';
		}
		$haystack = mb_strtolower( $label . ' ' . rawurldecode( $url ) );
		foreach ( $this->keywords() as $key => $words ) {
			foreach ( $words as $word ) {
				if ( false !== mb_strpos( $haystack, mb_strtolower( $word ) ) ) {
					return $key;
				}
			}
		}

		return 'dot';
	}

	/**
	 * Propagate core/navigation className into child navigation links via block context.
	 *
	 * @param array<string, mixed> $args Block type registration args.
	 * @return array<string, mixed>
	 */
	public function filter_block_context( array $args, string $name ): array {
		if ( 'core/navigation' === $name ) {
			$args['provides_context']                       = isset( $args['provides_context'] ) && is_array( $args['provides_context'] ) ? $args['provides_context'] : array();
			$args['provides_context']['musicwave/navClass'] = 'className';
		}
		if ( in_array( $name, array( 'core/navigation-link', 'core/home-link', 'core/navigation-submenu' ), true ) ) {
			$args['uses_context']   = isset( $args['uses_context'] ) && is_array( $args['uses_context'] ) ? $args['uses_context'] : array();
			$args['uses_context'][] = 'musicwave/navClass';
		}

		return $args;
	}

	/**
	 * Inject inline SVG icon or logged-in avatar into navigation link markup.
	 *
	 * @param string               $content  Rendered HTML.
	 * @param array<string, mixed> $block    Parsed block.
	 * @param mixed                $instance Block instance.
	 */
	public function render_nav_icon( string $content, array $block, $instance = null ): string {
		$name = (string) ( $block['blockName'] ?? '' );
		if ( ! in_array( $name, array( 'core/navigation-link', 'core/home-link', 'core/navigation-submenu' ), true ) || '' === $content ) {
			return $content;
		}

		$attrs     = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$class     = (string) ( $attrs['className'] ?? '' );
		$label     = (string) ( $attrs['label'] ?? '' );
		$url       = (string) ( $attrs['url'] ?? '' );
		$nav_class = is_object( $instance ) && isset( $instance->context['musicwave/navClass'] ) ? (string) $instance->context['musicwave/navClass'] : '';
		$auto      = (bool) preg_match( '/\bmw-(?:rail__nav|icon-nav|stream-account)\b/', $nav_class );
		if ( 'core/home-link' === $name ) {
			$url   = home_url( '/' );
			$label = '' !== $label ? $label : __( 'خانه', 'musicwave' );
		}

		$key = $this->resolve_key( $class, $label, $url, $auto );
		if ( '' === $key ) {
			return $content;
		}

		$icons = $this->icons();
		$glyph = '<span class="mw-nav-icon mw-nav-icon--' . esc_attr( $key ) . '" aria-hidden="true">' . $icons[ $key ] . '</span>';

		if ( 'account' === $key && is_user_logged_in() ) {
			$avatar = get_avatar( get_current_user_id(), 64, '', '', array( 'class' => 'mw-nav-avatar__img' ) );
			if ( is_string( $avatar ) && '' !== $avatar ) {
				$glyph = '<span class="mw-nav-icon mw-nav-avatar" aria-hidden="true">' . $avatar . '</span>';
			}
		}

		if ( 'core/home-link' === $name && false === strpos( $content, 'wp-block-navigation-item__label' ) ) {
			$content = preg_replace( '/(<a\b[^>]*>)(.*?)(<\/a>)/s', '$1<span class="wp-block-navigation-item__label">$2</span>$3', $content, 1 ) ?? $content;
		}

		$content = preg_replace( '/(<a\b[^>]*>|<button\b[^>]*>)/', '$1' . $glyph, $content, 1 ) ?? $content;

		if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
			$processor = new WP_HTML_Tag_Processor( $content );
			if ( $processor->next_tag( array( 'tag_name' => 'li' ) ) ) {
				$processor->add_class( 'mw-nav-item' );
				$processor->add_class( 'mw-nav-item--' . $key );
				$content = $processor->get_updated_html();
			}
		}

		return $content;
	}

	/**
	 * Render user profile / sign-in card inside the navigation rail.
	 *
	 * @param string               $content Rendered HTML.
	 * @param array<string, mixed> $block   Parsed block.
	 */
	public function render_rail_user( string $content, array $block ): string {
		if ( 'core/loginout' !== ( $block['blockName'] ?? '' ) ) {
			return $content;
		}
		$class = (string) ( $block['attrs']['className'] ?? '' );
		if ( false === strpos( $class, 'mw-rail__user' ) ) {
			return $content;
		}

		$icons   = $this->icons();
		$account = (string) apply_filters( 'musicwave_account_url', home_url( '/account/' ) );

		if ( ! is_user_logged_in() ) {
			return '<div class="mw-rail__user mw-rail__user--guest">'
				. '<a class="mw-rail__user-link" href="' . esc_url( AuthPolicy::login_url( $account ) ) . '">'
				. '<span class="mw-rail__avatar mw-rail__avatar--icon" aria-hidden="true">' . $icons['account'] . '</span>'
				. '<span class="mw-rail__user-text"><span class="mw-rail__user-name">' . esc_html__( 'ورود / ثبت‌نام', 'musicwave' ) . '</span>'
				. '<span class="mw-rail__user-badge">' . esc_html__( 'به ما بپیوندید', 'musicwave' ) . '</span></span></a></div>';
		}

		$user  = wp_get_current_user();
		$badge = user_can( $user, 'manage_options' ) ? __( 'مدیر سایت', 'musicwave' ) : __( 'عضو', 'musicwave' );
		$badge = (string) apply_filters( 'musicwave_user_badge', $badge, $user );

		return '<div class="mw-rail__user">'
			. '<a class="mw-rail__user-link" href="' . esc_url( $account ) . '">'
			. get_avatar( $user->ID, 72, '', '', array( 'class' => 'mw-rail__avatar' ) )
			. '<span class="mw-rail__user-text"><span class="mw-rail__user-name">' . esc_html( $user->display_name ) . '</span>'
			. '<span class="mw-rail__user-badge"><span class="mw-rail__user-dot" aria-hidden="true"></span>' . esc_html( $badge ) . '</span></span></a>'
			. '<a class="mw-rail__user-out" href="' . esc_url( wp_logout_url( home_url( '/' ) ) ) . '" aria-label="' . esc_attr__( 'خروج از حساب', 'musicwave' ) . '" title="' . esc_attr__( 'خروج', 'musicwave' ) . '">' . $icons['logout'] . '</a>'
			. '</div>';
	}
}
