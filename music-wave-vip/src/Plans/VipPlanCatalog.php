<?php
/**
 * Canonical VIP Plan Preset Catalog, Copy Parser & Tier SVG Icons.
 *
 * Centralizes preset plan definitions (`vip-1m`, `vip-6m`, `vip-12m`), rich
 * HTML product descriptions with capabilities lists, legacy boilerplate
 * detection, product copy parsing, and inline stroke SVG icons (DRY).
 *
 * @package ManaCore\MusicWave\Vip
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Vip\Plans;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class VipPlanCatalog {

	/**
	 * Legacy 1-line placeholder created by earlier versions of `PlanProducts::create_defaults()`.
	 */
	private const LEGACY_BOILERPLATE = 'طرح عضویت VIP MusicWave. خرید این طرح سطح عضویت منطبق را برای مدت طرح فوراً اعطا می‌کند؛ بازپرداخت یا لغو، آن را بلافاصله پس می‌گیرد.';

	/**
	 * Rich preset catalog for 1, 6, and 12-month VIP plans.
	 *
	 * @return array<int, array{
	 *   level: string,
	 *   title: string,
	 *   days: int,
	 *   icon: string,
	 *   badge: string,
	 *   summary: string,
	 *   features: array<int, string>
	 * }>
	 */
	public static function presets(): array {
		return array(
			array(
				'level'    => 'vip-1m',
				'title'    => __( 'عضویت VIP — ۱ ماه', 'music-wave-vip' ),
				'days'     => 30,
				'icon'     => 'bolt',
				'badge'    => __( 'شروع سریع', 'music-wave-vip' ),
				'summary'  => __( 'دسترسی کامل یک‌ماهه به آرشیو ویژه، پخش آنلاین بدون محدودیت و دانلود مستقیم با بالاترین کیفیت صوتی.', 'music-wave-vip' ),
				'features' => array(
					__( 'دانلود مستقیم تک‌آهنگ‌ها و آلبوم‌های ویژه با کیفیت اصلی (320kbps / FLAC)', 'music-wave-vip' ),
					__( 'پخش آنلاین کامل و بدون وقفه تمامی آثار اختصاصی آرشیو', 'music-wave-vip' ),
					__( 'فعال‌سازی آنی بلافاصله پس از پرداخت آنلاین با قابلیت تمدید انباشتی', 'music-wave-vip' ),
					__( 'ساخت فهرست‌های پخش نامحدود و ثبت درخواست اختصاصی موسیقی', 'music-wave-vip' ),
				),
			),
			array(
				'level'    => 'vip-6m',
				'title'    => __( 'عضویت VIP — ۶ ماه', 'music-wave-vip' ),
				'days'     => 180,
				'icon'     => 'diamond',
				'badge'    => __( 'پیشنهاد محبوب', 'music-wave-vip' ),
				'summary'  => __( 'اشتراک شش‌ماهه اقتصادی برای شنوندگان حرفه‌ای با دسترسی بی‌وقفه به تمام انتشارهای جدید و آرشیو کامل باکیفیت.', 'music-wave-vip' ),
				'features' => array(
					__( 'دسترسی کامل ۶ ماهه به تمام آلبوم‌ها، تک‌آهنگ‌ها و مجموعه‌های ویژه', 'music-wave-vip' ),
					__( 'دانلود با لینک امن پرسرعت در بالاترین کیفیت استودیویی موجود', 'music-wave-vip' ),
					__( 'اولویت بررسی و اجابت درخواست‌های موسیقی در پنل کاربری', 'music-wave-vip' ),
					__( 'امکان خرید مجدد و افزودن خودکار مدت جدید به انتهای اعتبار فعلی', 'music-wave-vip' ),
				),
			),
			array(
				'level'    => 'vip-12m',
				'title'    => __( 'عضویت VIP — ۱۲ ماه', 'music-wave-vip' ),
				'days'     => 365,
				'icon'     => 'crown',
				'badge'    => __( 'بهترین ارزش سالانه', 'music-wave-vip' ),
				'summary'  => __( 'کامل‌ترین عضویت یک‌ساله MusicWave با بیشترین صرفه‌جویی، دانلود نامحدود کیفیت مستر و اولویت ویژه پشتیبانی.', 'music-wave-vip' ),
				'features' => array(
					__( 'یک سال کامل دسترسی نامحدود به تمام آرشیو ویژه و انتشارهای روز', 'music-wave-vip' ),
					__( 'دانلود نامحدود نسخه‌های اورجینال و Lossless / Hi-Res بدون افت کیفیت', 'music-wave-vip' ),
					__( 'بالاترین اولویت در پیگیری درخواست‌های آهنگ و پشتیبانی اختصاصی مشترکین', 'music-wave-vip' ),
					__( 'نشان ویژه VIP در حساب کاربری با تضمین حفظ کامل سابقه و پلی‌لیست‌ها', 'music-wave-vip' ),
				),
			),
		);
	}

	/**
	 * Find matching preset metadata for a level slug or duration in days.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function preset_for( string $level, int $days = 0 ): ?array {
		$clean = sanitize_key( $level );
		foreach ( self::presets() as $preset ) {
			if ( $preset['level'] === $clean ) {
				return $preset;
			}
		}
		foreach ( self::presets() as $preset ) {
			if ( $days > 0 && $preset['days'] === $days ) {
				return $preset;
			}
		}
		return null;
	}

	/**
	 * Build structured HTML product content (`post_content`) from a preset definition.
	 *
	 * @param array{summary: string, features: array<int, string>} $preset Preset entry.
	 */
	public static function build_preset_html( array $preset ): string {
		$lines   = array();
		$lines[] = '<p>' . esc_html( $preset['summary'] ) . '</p>';
		if ( ! empty( $preset['features'] ) ) {
			$lines[] = '<ul>';
			foreach ( $preset['features'] as $feature ) {
				$lines[] = '<li>' . esc_html( $feature ) . '</li>';
			}
			$lines[] = '</ul>';
		}

		return implode( "\n", $lines );
	}

	/**
	 * Determine whether a product still carries empty or legacy 1-line boilerplate content
	 * (so clicking "ایجاد طرح‌های پیش‌فرض" can safely upgrade it without touching custom edits).
	 */
	public static function is_legacy_or_empty_content( string $raw_content ): bool {
		$trimmed = trim( wp_strip_all_tags( $raw_content ) );
		if ( '' === $trimmed ) {
			return true;
		}

		return self::LEGACY_BOILERPLATE === $trimmed;
	}

	/**
	 * Parse a WooCommerce product's `post_content` ("توضیحات محصول") & `post_excerpt`
	 * into a clean prose summary + bullet feature items for `.mw-membership__plan` cards.
	 *
	 * If the product has empty or legacy placeholder content, falls back to the matching
	 * tier preset so cards always display meaningful capabilities out of the box while any
	 * custom description written on the WooCommerce product page takes immediate precedence.
	 *
	 * @return array{description: string, features: array<int, string>}
	 */
	/**
	 * Read a post field safely via `get_post_field()` or `get_post()` (DRY).
	 */
	public static function post_field( string $field, int $product_id ): string {
		if ( function_exists( 'get_post_field' ) ) {
			return (string) get_post_field( $field, $product_id );
		}
		if ( function_exists( 'get_post' ) ) {
			$post = get_post( $product_id );
			if ( is_object( $post ) && isset( $post->{$field} ) ) {
				return (string) $post->{$field};
			}
		}

		return '';
	}

	public static function extract_product_copy( int $product_id, string $level = '', int $days = 0 ): array {
		$raw_content = self::post_field( 'post_content', $product_id );
		$raw_excerpt = self::post_field( 'post_excerpt', $product_id );

		$features = array();
		$combined = $raw_content . "\n" . $raw_excerpt;
		if ( preg_match_all( '/<li\b[^>]*>(.*?)<\/li>/is', $combined, $matches ) ) {
			foreach ( $matches[1] as $item ) {
				$clean = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $item ) ) ?? '' );
				if ( '' !== $clean && ! in_array( $clean, $features, true ) ) {
					$features[] = $clean;
				}
			}
		}

		// Also support newline checkmarks / bullets written in plain text in product description.
		if ( empty( $features ) && '' !== trim( $combined ) ) {
			$split_lines = preg_split( '/\r\n|\r|\n/', wp_strip_all_tags( $combined ) );
			foreach ( is_array( $split_lines ) ? $split_lines : array() as $line ) {
				$line = trim( (string) $line );
				if ( 1 === preg_match( '/^(?:[✓✔•\-–*])\s*(.+)$/u', $line, $m ) ) {
					$clean = trim( $m[1] );
					if ( '' !== $clean && ! in_array( $clean, $features, true ) ) {
						$features[] = $clean;
					}
				}
			}
		}

		$without_lists = preg_replace( '/<(ul|ol)\b[^>]*>.*?<\/\1>/is', '', $raw_content );
		$prose_lines   = array();
		$raw_lines     = preg_split( '/\r\n|\r|\n/', wp_strip_all_tags( (string) $without_lists ) );
		foreach ( is_array( $raw_lines ) ? $raw_lines : array() as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line || 1 === preg_match( '/^(?:[✓✔•\-–*])\s+/u', $line ) ) {
				continue;
			}
			$prose_lines[] = $line;
		}

		$description = trim( implode( ' ', $prose_lines ) );
		$excerpt_txt = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $raw_excerpt ) ) ?? '' );

		// Prioritize custom product body copy over excerpt text.
		if ( self::is_legacy_or_empty_content( $raw_content ) ) {
			$description = '' !== $excerpt_txt ? $excerpt_txt : '';
		} elseif ( '' === $description && '' !== $excerpt_txt ) {
			$description = $excerpt_txt;
		}

		// Fallback to preset capabilities if neither custom prose nor custom bullets exist yet.
		$preset = self::preset_for( $level, $days );
		if ( null !== $preset ) {
			if ( '' === $description ) {
				$description = (string) $preset['summary'];
			}
			if ( empty( $features ) ) {
				$features = $preset['features'];
			}
		}

		if ( function_exists( 'mb_strlen' ) && mb_strlen( $description ) > 220 ) {
			$description = rtrim( mb_substr( $description, 0, 217 ) ) . '…';
		}

		return array(
			'description' => $description,
			'features'    => array_slice( $features, 0, 6 ),
		);
	}

	public static function resolve_icon( string $level, int $days ): string {
		$preset = self::preset_for( $level, $days );
		if ( null !== $preset && ! empty( $preset['icon'] ) ) {
			return (string) $preset['icon'];
		}
		if ( 0 === $days && '' !== $level ) {
			return 'infinity';
		}
		if ( $days >= 300 ) {
			return 'crown';
		}
		if ( $days >= 90 ) {
			return 'diamond';
		}

		return 'bolt';
	}

	public static function resolve_badge( string $level, int $days ): string {
		$preset = self::preset_for( $level, $days );
		if ( null !== $preset && ! empty( $preset['badge'] ) ) {
			return (string) $preset['badge'];
		}
		if ( 0 === $days && '' !== $level ) {
			return __( 'دسترسی دائمی', 'music-wave-vip' );
		}

		return '';
	}

	/**
	 * Clean inline stroke SVG icon markup matching MusicWave UI tokens.
	 */
	public static function icon_svg( string $name, int $size = 18, string $extra_class = '' ): string {
		$size      = max( 12, min( 28, $size ) );
		$class_att = '' !== trim( $extra_class ) ? 'class="' . esc_attr( trim( $extra_class ) ) . '" ' : '';
		$attr      = $class_att . 'width="' . (int) $size . '" height="' . (int) $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"';

		switch ( $name ) {
			case 'crown':
				return '<svg ' . $attr . '><path d="M3 18h18l-2-11-5 4-2-6-2 6-5-4-2 11z"/><path d="M3 21h18"/></svg>';
			case 'diamond':
				return '<svg ' . $attr . '><path d="M6 3h12l4 6-10 12L2 9z"/><path d="M2 9h20"/><path d="M12 21L8 9l4-6 4 6z"/></svg>';
			case 'infinity':
				return '<svg ' . $attr . '><path d="M18.178 8c5.096 0 5.096 8 0 8-5.095 0-7.133-8-12.739-8-4.585 0-4.585 8 0 8 5.606 0 7.644-8 12.74-8z"/></svg>';
			case 'shield':
				return '<svg ' . $attr . '><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>';
			case 'check':
				return '<svg ' . $attr . '><path d="M20 6 9 17l-5-5"/></svg>';
			case 'bolt':
			default:
				return '<svg ' . $attr . '><path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/></svg>';
		}
	}
}
