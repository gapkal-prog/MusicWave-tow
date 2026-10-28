<?php
declare(strict_types=1);

namespace ManaCore\MusicWave\Theme\Blocks;

use WP_Block_Pattern_Categories_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers theme block categories, block styles, static container blocks,
 * dynamic presentation blocks, hidden legacy aliases, and editor scripts.
 */
final class BlockRegistry {

	/**
	 * Register block and pattern hooks.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_pattern_categories' ) );
		add_action( 'init', array( $this, 'register_block_styles' ) );
		add_action( 'init', array( $this, 'register_static_blocks' ) );
		add_action( 'init', array( $this, 'register_presentation_blocks' ) );
		add_filter( 'block_categories_all', array( $this, 'register_block_category' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_presentation_editor_blocks' ) );
	}

	/**
	 * Register pattern categories used in the Site Editor inserter.
	 */
	public function register_pattern_categories(): void {
$categories = array(
			'musicwave'         => __( 'MusicWave', 'musicwave' ),
			'featured'          => __( 'منتخب', 'musicwave' ),
			'musicwave-demos'   => __( 'دموهای MusicWave', 'musicwave' ),
			'musicwave-shelves' => __( 'ویترینهای MusicWave', 'musicwave' ),
			'musicwave-cards'   => __( 'کارتهای MusicWave', 'musicwave' ),
		);
		foreach ( $categories as $slug => $label ) {
			if ( ! WP_Block_Pattern_Categories_Registry::get_instance()->is_registered( $slug ) ) {
				register_block_pattern_category(
					$slug,
					array( 'label' => $label )
				);
			}
		}
	}

	/**
	 * Register the shared MusicWave block category when not already present.
	 *
	 * @param array<int, array<string, mixed>> $categories Existing block categories.
	 * @return array<int, array<string, mixed>>
	 */
	public function register_block_category( array $categories ): array {
		foreach ( $categories as $category ) {
			if ( is_array( $category ) && isset( $category['slug'] ) && 'music-wave' === $category['slug'] ) {
				return $categories;
			}
		}

		array_unshift(
			$categories,
			array(
				'slug'  => 'music-wave',
				'title' => __( 'MusicWave', 'musicwave' ),
				'icon'  => 'format-audio',
			)
		);

		return $categories;
	}

	/**
	 * Single source of truth for presentation block style variations.
	 *
	 * @return array<string, array<string, array{label: string, hint: string}>>
	 */
	public function presentation_style_variations(): array {
		return array(
			'release-shelf'  => array(
				'editorial' => array(
					'label' => __( 'سرمقاله‌ای', 'musicwave' ),
					'hint'  => __( 'کارت مجله‌ای با خط تأکیدی، سطح شیشه‌ای و تیتر درشت.', 'musicwave' ),
				),
				'vinyl'     => array(
					'label' => __( 'وینیل', 'musicwave' ),
					'hint'  => __( 'غلاف آلبوم با صفحهٔ بیرون‌زننده که هنگام هاور از غلاف خارج می‌شود.', 'musicwave' ),
				),
				'minimal'   => array(
					'label' => __( 'حداقلی', 'musicwave' ),
					'hint'  => __( 'بدون سطح کارت؛ فقط خطوط و تایپوگرافی. مناسب چیدمان‌های فهرستی.', 'musicwave' ),
				),
			),
			'release-slider' => array(
				'cinema'    => array(
					'label' => __( 'سینمایی', 'musicwave' ),
					'hint'  => __( 'اسلاید تمام‌قاب با تیتر بزرگ و پردهٔ تیره‌تر.', 'musicwave' ),
				),
				'editorial' => array(
					'label' => __( 'سرمقاله‌ای', 'musicwave' ),
					'hint'  => __( 'کارت مجله‌ای با خط تأکیدی، سطح شیشه‌ای و تیتر نمایشی.', 'musicwave' ),
				),
				'vinyl'     => array(
					'label' => __( 'وینیل', 'musicwave' ),
					'hint'  => __( 'غلاف آلبوم با صفحهٔ بیرون‌زننده هنگام قرارگیری نشانگر.', 'musicwave' ),
				),
				'minimal'   => array(
					'label' => __( 'حداقلی', 'musicwave' ),
					'hint'  => __( 'حاشیه‌های خلوت بدون قاب و سایهٔ کارت.', 'musicwave' ),
				),
			),
			'breadcrumb'     => array(
				'minimal'   => array(
					'label' => __( 'حداقلی', 'musicwave' ),
					'hint'  => __( 'بدون قاب و پس‌زمینه؛ فقط مسیر تایپوگرافیک تمیز.', 'musicwave' ),
				),
				'pill'      => array(
					'label' => __( 'کپسولی', 'musicwave' ),
					'hint'  => __( 'نوار کپسولی جمع‌وجور روی سطح کارت.', 'musicwave' ),
				),
				'glass'     => array(
					'label' => __( 'شیشه‌ای', 'musicwave' ),
					'hint'  => __( 'پس‌زمینهٔ نیمه‌شفاف با بلور ظریف.', 'musicwave' ),
				),
				'editorial' => array(
					'label' => __( 'سرمقاله‌ای', 'musicwave' ),
					'hint'  => __( 'خط تأکیدی عمودی در ابتدای مسیر راهنما.', 'musicwave' ),
				),
			),
			'artist-profile' => array(
				'minimal'   => array(
					'label' => __( 'حداقلی', 'musicwave' ),
					'hint'  => __( 'بدون کارت و سایه، تایپوگرافی تمیز.', 'musicwave' ),
				),
				'editorial' => array(
					'label'      => __( 'سرمقالهای', 'musicwave' ),
					'hint'       => __( 'خط تأکیدی عمودی و سلسلهمراتب مجلهای.', 'musicwave' ),
					'is_default' => true,
				),
				'glass'     => array(
					'label' => __( 'شیشه‌ای', 'musicwave' ),
					'hint'  => __( 'سطح شیشه‌ای مات با حاشیه ظریف.', 'musicwave' ),
				),
			),
			'term-hero'      => array(
				'minimal'   => array(
					'label' => __( 'حداقلی', 'musicwave' ),
					'hint'  => __( 'سربرگ ساده بدون پس‌زمینه سنگین.', 'musicwave' ),
				),
				'editorial' => array(
					'label' => __( 'سرمقاله‌ای', 'musicwave' ),
					'hint'  => __( 'تایپوگرافی شاخص و کنتراست مجله‌ای.', 'musicwave' ),
				),
				'glass'     => array(
					'label' => __( 'شیشه‌ای', 'musicwave' ),
					'hint'  => __( 'قاب شیشه‌ای مات روی تصویر بایگانی.', 'musicwave' ),
				),
			),
			'header-search'  => array(
				'minimal' => array(
					'label' => __( 'حداقلی', 'musicwave' ),
					'hint'  => __( 'فیلد بدون سطح؛ فقط خط زیر.', 'musicwave' ),
				),
				'glass'   => array(
					'label' => __( 'شیشه‌ای', 'musicwave' ),
					'hint'  => __( 'پاپ‌آپ شیشه‌ای با بلور بیشتر.', 'musicwave' ),
				),
			),
		);
	}

	/**
	 * Resolve the active style variation slug for a presentation block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param array<int, string>   $allowed    Allowed variation slugs.
	 */
	public function style_variant( array $attributes, array $allowed ): string {
		$class_name = isset( $attributes['className'] ) && is_scalar( $attributes['className'] )
			? trim( (string) $attributes['className'] )
			: '';
		if ( '' === $class_name && defined( 'REST_REQUEST' ) && REST_REQUEST && isset( $_GET['mw_style_class'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only SSR style preview query argument.
			$raw_query_class = wp_unslash( $_GET['mw_style_class'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$class_name      = is_scalar( $raw_query_class ) ? sanitize_text_field( (string) $raw_query_class ) : '';
		}
		if ( '' !== $class_name ) {
			$tokens = preg_split( '/\s+/', $class_name );
			$tokens = is_array( $tokens ) ? $tokens : array();
			foreach ( $allowed as $variation ) {
				if ( in_array( 'is-style-' . $variation, $tokens, true ) ) {
					return $variation;
				}
			}
			if ( in_array( 'is-style-default', $tokens, true ) ) {
				return '';
			}
		}

		$selected = isset( $attributes['styleVariant'] ) && is_scalar( $attributes['styleVariant'] )
			? sanitize_key( (string) $attributes['styleVariant'] )
			: '';

		return in_array( $selected, $allowed, true ) ? $selected : '';
	}

	/**
	 * Build the CSS modifier class suffix for a presentation component.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param array<int, string>   $allowed    Allowed variation slugs.
	 * @param string               $component  Root component CSS class.
	 */
	public function style_variant_class( array $attributes, array $allowed, string $component ): string {
		$variant = $this->style_variant( $attributes, $allowed );

		return '' !== $variant ? ' ' . $component . '--' . $variant : '';
	}

	/**
	 * Return editorial looks offered on the static section-head container block.
	 *
	 * @return array<string, string>
	 */
	public function section_head_styles(): array {
		return array(
			'center' => __( 'وسط‌چین', 'musicwave' ),
			'stack'  => __( 'ستونی', 'musicwave' ),
			'invert' => __( 'معکوس (روی سطح رنگی)', 'musicwave' ),
		);
	}

	/**
	 * Register block styles across Core blocks, section-head, and presentation blocks.
	 */
	public function register_block_styles(): void {
		if ( ! function_exists( 'register_block_style' ) ) {
			return;
		}

		$styles = array(
			array(
				'block' => 'core/button',
				'name'  => 'mw-pill',
				'label' => __( 'کپسولی (MusicWave)', 'musicwave' ),
			),
			array(
				'block' => 'core/button',
				'name'  => 'mw-outline-accent',
				'label' => __( 'خط تأکیدی خطی', 'musicwave' ),
			),
			array(
				'block' => 'core/group',
				'name'  => 'mw-surface',
				'label' => __( 'کارت سطحی', 'musicwave' ),
			),
			array(
				'block' => 'core/group',
				'name'  => 'mw-surface-raised',
				'label' => __( 'سطح برجسته', 'musicwave' ),
			),
			array(
				'block' => 'core/group',
				'name'  => 'mw-header-wide',
				'label' => __( "\u{0639}\u{0631}\u{0636} \u{0647}\u{062F}\u{0631}: \u{06AF}\u{0633}\u{062A}\u{0631}\u{062F}\u{0647}", 'musicwave' ),
			),
			array(
				'block' => 'core/group',
				'name'  => 'mw-header-full',
				'label' => __( "\u{0639}\u{0631}\u{0636} \u{0647}\u{062F}\u{0631}: \u{062A}\u{0645}\u{0627}\u{0645}\u{0639}\u{0631}\u{0636}", 'musicwave' ),
			),
			array(
				'block' => 'core/template-part',
				'name'  => 'mw-header-wide',
				'label' => __( "\u{0639}\u{0631}\u{0636} \u{0647}\u{062F}\u{0631}: \u{06AF}\u{0633}\u{062A}\u{0631}\u{062F}\u{0647}", 'musicwave' ),
			),
			array(
				'block' => 'core/template-part',
				'name'  => 'mw-header-full',
				'label' => __( "\u{0639}\u{0631}\u{0636} \u{0647}\u{062F}\u{0631}: \u{062A}\u{0645}\u{0627}\u{0645}\u{0639}\u{0631}\u{0636}", 'musicwave' ),
			),
			array(
				'block' => 'core/columns',
				'name'  => 'mw-tight-gap',
				'label' => __( 'فاصله کم', 'musicwave' ),
			),
			array(
				'block' => 'core/separator',
				'name'  => 'mw-accent',
				'label' => __( 'خط تأکیدی', 'musicwave' ),
			),
			array(
				'block' => 'core/image',
				'name'  => 'mw-rounded',
				'label' => __( 'گرد', 'musicwave' ),
			),
			array(
				'block' => 'core/image',
				'name'  => 'mw-circle',
				'label' => __( 'دایره', 'musicwave' ),
			),
			array(
				'block' => 'core/list',
				'name'  => 'mw-checklist',
				'label' => __( 'چک‌لیست', 'musicwave' ),
			),
		);

		foreach ( $styles as $style ) {
			register_block_style(
				$style['block'],
				array(
					'name'  => $style['name'],
					'label' => $style['label'],
				)
			);
		}

		foreach ( $this->section_head_styles() as $style_name => $style_label ) {
			register_block_style(
				'music-wave/section-head',
				array(
					'name'  => $style_name,
					'label' => $style_label,
				)
			);
		}

		$core_owned_styles = class_exists( '\\ManaCore\\MusicWave\\Core\\Modules\\Rendering' )
			? array( 'artist-profile', 'term-hero' )
			: array();

		foreach ( $this->presentation_style_variations() as $slug => $variations ) {
			if ( in_array( $slug, $core_owned_styles, true ) ) {
				continue;
			}
			foreach ( array( 'music-wave/' . $slug, 'musicwave/' . $slug ) as $block_name ) {
				foreach ( $variations as $name => $variation ) {
					register_block_style(
						$block_name,
						array(
							'name'  => $name,
							'label' => $variation['label'],
						)
					);
				}
			}
		}
	}

	/**
	 * Load and decode a theme block's bundled block.json file.
	 *
	 * @return array<string, mixed>|null
	 */
	public function load_block_json( string $block_dir ): ?array {
		$file = get_template_directory() . '/blocks/' . $block_dir . '/block.json';
		if ( ! is_readable( $file ) ) {
			return null;
		}
		$raw = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}
		$data = json_decode( $raw, true );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Normalize a theme block's block.json into the client editor payload shape.
	 *
	 * @return array<string, mixed>|null
	 */
	public function block_metadata_entry( string $block_dir ): ?array {
		$meta = $this->load_block_json( $block_dir );
		if ( null === $meta || empty( $meta['name'] ) ) {
			return null;
		}

		return array(
			'name'        => (string) $meta['name'],
			'apiVersion'  => isset( $meta['apiVersion'] ) ? (int) $meta['apiVersion'] : 3,
			'title'       => isset( $meta['title'] ) ? (string) $meta['title'] : '',
			'description' => isset( $meta['description'] ) ? (string) $meta['description'] : '',
			'category'    => isset( $meta['category'] ) ? (string) $meta['category'] : 'music-wave',
			'icon'        => isset( $meta['icon'] ) ? (string) $meta['icon'] : 'format-audio',
			'keywords'    => isset( $meta['keywords'] ) && is_array( $meta['keywords'] ) ? array_values( $meta['keywords'] ) : array(),
			'textdomain'  => isset( $meta['textdomain'] ) ? (string) $meta['textdomain'] : 'musicwave',
			'attributes'  => isset( $meta['attributes'] ) && is_array( $meta['attributes'] ) ? $meta['attributes'] : array(),
			'supports'    => isset( $meta['supports'] ) && is_array( $meta['supports'] ) ? $meta['supports'] : array(),
			'example'     => isset( $meta['example'] ) && is_array( $meta['example'] ) ? $meta['example'] : array(),
			'styles'      => isset( $meta['styles'] ) && is_array( $meta['styles'] ) ? array_values( $meta['styles'] ) : array(),
		);
	}

	/**
	 * Static InnerBlocks container block directories.
	 *
	 * @return array<int, string>
	 */
	public function static_block_dirs(): array {
		return array( 'section-head' );
	}

	/**
	 * Register static container blocks directly from bundled block.json metadata.
	 */
	public function register_static_blocks(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		foreach ( $this->static_block_dirs() as $block_dir ) {
			$path = get_template_directory() . '/blocks/' . $block_dir;
			if ( ! is_readable( $path . '/block.json' ) ) {
				continue;
			}
			register_block_type( $path );
		}
	}

	/**
	 * Dynamic PHP-rendered block map (dir => render callback).
	 *
	 * @return array<string, array{dir: string, callback: string}>
	 */
	public function presentation_blocks_map(): array {
		return array(
			'theme-text'     => array(
				'dir'      => 'theme-text',
				'callback' => 'musicwave_render_theme_text',
			),
			'theme-toggle'   => array(
				'dir'      => 'theme-toggle',
				'callback' => 'musicwave_render_theme_toggle',
			),
			'rail-toggle'    => array(
				'dir'      => 'rail-toggle',
				'callback' => 'musicwave_render_rail_toggle',
			),
			'header-search'  => array(
				'dir'      => 'header-search',
				'callback' => 'musicwave_render_header_search',
			),
			'breadcrumb'     => array(
				'dir'      => 'breadcrumb',
				'callback' => 'musicwave_render_breadcrumb',
			),
			'release-slider' => array(
				'dir'      => 'release-slider',
				'callback' => 'musicwave_render_release_slider',
			),
			'release-shelf'  => array(
				'dir'      => 'release-shelf',
				'callback' => 'musicwave_render_release_shelf',
			),
			'synced-lyrics'  => array(
				'dir'      => 'synced-lyrics',
				'callback' => 'musicwave_render_synced_lyrics',
			),
		);
	}

	/**
	 * Register hidden legacy musicwave/{dir} compatibility alias for saved templates.
	 *
	 * @param array<string, mixed> $metadata Decoded block.json data.
	 */
	public function register_legacy_presentation_block( string $dir, string $callback, array $metadata ): void {
		$legacy_name = 'musicwave/' . $dir;
		$canonical   = isset( $metadata['name'] ) ? (string) $metadata['name'] : '';
		if ( '' === $canonical || $legacy_name === $canonical || ! function_exists( 'register_block_type' ) ) {
			return;
		}

		$textdomain = isset( $metadata['textdomain'] ) ? (string) $metadata['textdomain'] : 'musicwave';
		$keywords   = isset( $metadata['keywords'] ) && is_array( $metadata['keywords'] ) ? array_values( $metadata['keywords'] ) : array();
		$keywords   = array_map(
			static function ( $keyword ) use ( $textdomain ): string {
				return translate( (string) $keyword, $textdomain ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText,WordPress.WP.I18n.NonSingularStringLiteralDomain,WordPress.WP.I18n.LowLevelTranslationFunction
			},
			$keywords
		);

		/** @var array<string, mixed> $args */
		$args                         = array(
			'api_version'     => isset( $metadata['apiVersion'] ) ? (int) $metadata['apiVersion'] : 3,
			'title'           => isset( $metadata['title'] ) ? translate( (string) $metadata['title'], $textdomain ) : $legacy_name, // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText,WordPress.WP.I18n.NonSingularStringLiteralDomain,WordPress.WP.I18n.LowLevelTranslationFunction
			'category'        => isset( $metadata['category'] ) ? (string) $metadata['category'] : 'music-wave',
			'description'     => isset( $metadata['description'] ) ? translate( (string) $metadata['description'], $textdomain ) : '', // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText,WordPress.WP.I18n.NonSingularStringLiteralDomain,WordPress.WP.I18n.LowLevelTranslationFunction
			'icon'            => isset( $metadata['icon'] ) ? (string) $metadata['icon'] : 'format-audio',
			'keywords'        => $keywords,
			'textdomain'      => $textdomain,
			'attributes'      => isset( $metadata['attributes'] ) && is_array( $metadata['attributes'] ) ? $metadata['attributes'] : array(),
			'supports'        => isset( $metadata['supports'] ) && is_array( $metadata['supports'] ) ? $metadata['supports'] : array(),
			'render_callback' => $callback,
		);
		$args['supports']['inserter'] = false;

		register_block_type( $legacy_name, $args );
	}

	/**
	 * Register all PHP-rendered presentation blocks and their hidden legacy aliases.
	 */
	public function register_presentation_blocks(): void {
		foreach ( $this->presentation_blocks_map() as $config ) {
			$dir      = $config['dir'];
			$callback = $config['callback'];
			$path     = get_template_directory() . '/blocks/' . $dir;
			$metadata = $this->load_block_json( $dir );
			if ( is_readable( $path . '/block.json' ) && is_array( $metadata ) ) {
				register_block_type( $path, array( 'render_callback' => $callback ) );
				$this->register_legacy_presentation_block( $dir, $callback, $metadata );
			}
		}
	}

	/**
	 * Enqueue and localize the block editor registration script for both lanes.
	 */
	public function enqueue_presentation_editor_blocks(): void {
		$theme = wp_get_theme();
		wp_enqueue_script(
			'musicwave-presentation-blocks',
			get_template_directory_uri() . '/assets/editor-blocks.js',
			array( 'wp-block-editor', 'wp-blocks', 'wp-components', 'wp-element', 'wp-i18n', 'wp-server-side-render' ),
			(string) $theme->get( 'Version' ),
			true
		);
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'musicwave-presentation-blocks', 'musicwave', get_template_directory() . '/languages' );
		}

		$localized = array();
		foreach ( array_keys( $this->presentation_blocks_map() ) as $dir ) {
			$entry = $this->block_metadata_entry( $dir );
			if ( null === $entry ) {
				continue;
			}
			$localized[] = $entry;

			$legacy_entry                         = $entry;
			$legacy_entry['name']                 = 'musicwave/' . $dir;
			$legacy_entry['supports']             = isset( $legacy_entry['supports'] ) && is_array( $legacy_entry['supports'] ) ? $legacy_entry['supports'] : array();
			$legacy_entry['supports']['inserter'] = false;
			$localized[]                          = $legacy_entry;
		}

		$static_localized = array();
		foreach ( $this->static_block_dirs() as $static_dir ) {
			$static_entry = $this->block_metadata_entry( $static_dir );
			if ( null !== $static_entry ) {
				$static_localized[] = $static_entry;
			}
		}

		wp_localize_script( 'musicwave-presentation-blocks', 'musicwavePresentationBlocks', $localized );
		wp_localize_script( 'musicwave-presentation-blocks', 'musicwaveStaticBlocks', $static_localized );
		wp_localize_script( 'musicwave-presentation-blocks', 'musicwavePresentationVariations', $this->presentation_style_variations() );
	}
}
