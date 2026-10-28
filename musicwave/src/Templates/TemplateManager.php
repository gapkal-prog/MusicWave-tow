<?php
declare(strict_types=1);

namespace ManaCore\MusicWave\Theme\Templates;

use ManaCore\MusicWave\Core\Blocks\BlockSupport;
use ManaCore\MusicWave\Theme\Assets\AssetManager;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages theme supports, accessible skip links, widget areas, self-collapsing
 * sidebars, account template aliasing, and database template override recovery.
 */
final class TemplateManager {

	private const CANONICAL_HERO_SPECS_DIV = '<div class="wp-block-group mw-release-hero__specs"><!-- wp:music-wave/release-meta {"compact":true,"showLabels":true,"showArtist":false,"showGenre":false,"showMood":false,"showLabel":false,"showReleaseType":false,"fullSpecs":true,"metadata":{"name":"جزئیات انتشار MusicWave"}} /--></div>';

	/** @var AssetManager */
	private AssetManager $assets;

	public function __construct( AssetManager $assets ) {
		$this->assets = $assets;
	}

	/**
	 * Register WordPress hooks for templates, sidebars, and theme supports.
	 */
	public function register(): void {
		add_action( 'after_setup_theme', array( $this, 'setup' ) );
		add_action( 'wp_body_open', array( $this, 'skip_link' ), 5 );
		add_filter( 'render_block', array( $this, 'filter_main_id' ), 10, 2 );
		add_action( 'widgets_init', array( $this, 'register_widgets' ) );
		add_filter( 'render_block_core/template-part', array( $this, 'inject_sidebar_widgets' ), 10, 2 );
		add_action( 'admin_menu', array( $this, 'register_template_repair_page' ) );
		add_action( 'admin_post_musicwave_repair_templates', array( $this, 'handle_template_repair' ) );
		add_filter( 'get_block_template', array( $this, 'alias_account_template' ), 10, 3 );
		add_filter( 'get_block_templates', array( $this, 'alias_account_templates_in_query' ), 10, 3 );
		add_filter( 'get_block_template', array( $this, 'filter_block_template_title' ), 20, 3 );
		add_filter( 'get_block_templates', array( $this, 'filter_block_templates_titles' ), 20, 3 );
	}

	/**
	 * @param mixed $template Block template object or null.
	 * @return mixed
	 */
	public function filter_block_template_title( $template, string $id, string $template_type ) {
		unset( $id );
		if ( 'wp_template' === $template_type ) {
			$template = $this->normalize_block_template( $template );
		}
		return $this->apply_template_title( $template );
	}

	/**
	 * @param array<int, mixed>    $templates     Found templates.
	 * @param array<string, mixed> $query         Query arguments.
	 * @return array<int, mixed>
	 */
	public function filter_block_templates_titles( array $templates, array $query, string $template_type ): array {
		unset( $query );
		if ( 'wp_template' !== $template_type ) {
			return $templates;
		}
		return array_map(
			function ( $template ) {
				return $this->apply_template_title( $this->normalize_block_template( $template ) );
			},
			$templates
		);
	}

	/**
	 * Upgrade legacy `single-mw_release` block markup (including saved Site Editor DB overrides)
	 * so `.mw-release-hero__specs` renders a single unified `fullSpecs` block with zero duplication
	 * and legacy hardcoded `access-panel` inside `.mw-release-stage__aside` yields to `sidebar` widgets.
	 *
	 * @param mixed $template Block template object or null.
	 * @return mixed
	 */
	public function normalize_block_template( $template ) {
		if ( ! is_object( $template ) || ! isset( $template->slug, $template->content ) || ! is_string( $template->content ) ) {
			return $template;
		}

		// Migrate consolidated `music-wave/dashboard-edit` blocks to `music-wave/dashboard-account`.
		if ( false !== strpos( $template->content, 'wp:music-wave/dashboard-edit' ) ) {
			$template->content = str_replace( 'wp:music-wave/dashboard-edit', 'wp:music-wave/dashboard-account', $template->content );
		}

		// Strip removed legacy request-form chrome keys from saved custom DB templates.
		if ( false !== strpos( $template->content, 'wp:music-wave/request-form' ) ) {
			$req_cleaned = preg_replace_callback(
				'/<!--\s*wp:music-wave\/request-form\s+(\{.*?\})\s*\/-->/s',
				static function ( array $matches ): string {
					$decoded = json_decode( $matches[1], true );
					if ( ! is_array( $decoded ) ) {
						return $matches[0];
					}
					foreach ( array( 'layout', 'showHeading', 'eyebrow', 'heading', 'intro', 'showHighlights', 'highlightsHeading', 'highlightsBody', 'showSteps', 'showTypeChips' ) as $legacy_key ) {
						unset( $decoded[ $legacy_key ] );
					}
					if ( array() === $decoded ) {
						return '<!-- wp:music-wave/request-form /-->';
					}
					$encoded = wp_json_encode( $decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
					return is_string( $encoded ) ? '<!-- wp:music-wave/request-form ' . $encoded . ' /-->' : $matches[0];
				},
				$template->content
			);
			if ( is_string( $req_cleaned ) ) {
				$template->content = $req_cleaned;
			}
		}

		if ( 'single-mw_release' !== (string) $template->slug ) {
			return $template;
		}

		$content = preg_replace_callback(
			'#<div\s+class="[^"]*\bmw-release-hero__specs\b[^"]*">((?:(?!</div>).)*)</div>#s',
			static function ( array $matches ): string {
				$inner = $matches[1];
				if ( false === strpos( $inner, 'wp:music-wave/release-meta' ) ) {
					return $matches[0];
				}
				if ( substr_count( $inner, 'wp:music-wave/release-meta' ) > 1 || false === strpos( $inner, '"fullSpecs"' ) ) {
					return self::CANONICAL_HERO_SPECS_DIV;
				}

				return $matches[0];
			},
			$template->content
		);
		if ( is_string( $content ) ) {
			$template->content = $content;
		}

		$aside_cleaned = preg_replace(
			'#(<div\s+class="[^"]*\bmw-release-stage__aside\b[^"]*"[^>]*>(?:(?!</div>).)*<!--\s*wp:template-part\s+\{[^}]*"slug"\s*:\s*"sidebar"[^}]*\}\s*/-->(?:(?!</div>).)*?)\s*<!--\s*wp:music-wave/access-panel(?:\s+\{[^}]*\})?\s*/-->#s',
			'$1',
			$template->content
		);
		if ( is_string( $aside_cleaned ) ) {
			$template->content = $aside_cleaned;
		}

		return $template;
	}

	/**
	 * Register theme supports and editor stylesheet parity.
	 */
	public function setup(): void {
		load_theme_textdomain( 'musicwave', get_template_directory() . '/languages' );
		add_theme_support( 'wp-block-styles' );
		add_theme_support( 'title-tag' );
		add_theme_support( 'editor-styles' );
		add_editor_style( array_merge( array( 'style.css' ), $this->assets->editor_style_files() ) );
		add_theme_support( 'responsive-embeds' );
		add_theme_support( 'woocommerce' );
		add_theme_support( 'widgets' );
		add_theme_support( 'widgets-block-editor' );
		add_theme_support( 'block-template-parts' );
		add_theme_support( 'post-thumbnails' );
		add_theme_support( 'html5', array( 'search-form', 'gallery', 'caption', 'style', 'script' ) );
		add_theme_support( 'automatic-feed-links' );
	}

	/**
	 * Output keyboard skip link targeting #main-content.
	 */
	public function skip_link(): void {
		static $printed = false;
		if ( did_action( 'wp_body_open' ) > 1 && $printed ) {
			return;
		}
		$printed = true;
		echo '<a class="skip-link screen-reader-text" href="#main-content">' . esc_html__( 'پرش به محتوا', 'musicwave' ) . '</a>' . "\n";
	}

	/**
	 * Ensure the first rendered <main> element carries id="main-content".
	 *
	 * @param string               $block_content Rendered HTML.
	 * @param array<string, mixed> $block         Parsed block.
	 */
	public function filter_main_id( string $block_content, array $block ): string {
		static $done = false;
		if ( $done ) {
			return $block_content;
		}
		if ( ! isset( $block['blockName'] ) || 'core/group' !== $block['blockName'] ) {
			return $block_content;
		}
		$tag = isset( $block['attrs']['tagName'] ) ? (string) $block['attrs']['tagName'] : '';
		if ( 'main' !== strtolower( $tag ) ) {
			return $block_content;
		}
		if ( false !== strpos( $block_content, ' id=' ) ) {
			if ( false !== strpos( $block_content, 'id="main-content"' ) || false !== strpos( $block_content, "id='main-content'" ) || false !== strpos( $block_content, 'id="main"' ) ) {
				$done = true;
			}
			return $block_content;
		}
		$updated = preg_replace( '/<main(\s)/i', '<main id="main-content"$1', $block_content, 1 );
		$done    = true;

		return is_string( $updated ) ? $updated : $block_content;
	}

	/**
	 * Register widget areas backing sidebar and footer template parts.
	 */
	public function register_widgets(): void {
		$sidebars = array(
			array(
				'name'        => __( 'نوار کناری MusicWave', 'musicwave' ),
				'id'          => 'musicwave-sidebar',
				'description' => __( 'در بخش قالب «نوار کناری» نمایش داده می‌شود (نمایش → ویرایشگر → بخش‌های قالب → نوار کناری). هر بلوک یا ابزارک قدیمی را اینجا اضافه کنید. همه‌چیز بدون نیاز به کدنویسی از رابط بصری قابل ویرایش است.', 'musicwave' ),
			),
			array(
				'name'        => __( 'ابزارک‌های پابرگ MusicWave', 'musicwave' ),
				'id'          => 'musicwave-footer-widgets',
				'description' => __( 'در بخش قالب «ابزارک‌های پابرگ» نمایش داده می‌شود (نمایش → ویرایشگر → بخش‌های قالب → ابزارک‌های پابرگ). هر بلوک یا ابزارک قدیمی را بکشید و رها کنید؛ رنگ‌ها از سبک‌های کلی پیروی می‌کنند.', 'musicwave' ),
			),
			array(
				'name'        => __( 'نوار کناری فروشگاه MusicWave', 'musicwave' ),
				'id'          => 'musicwave-shop-sidebar',
				'description' => __( 'در بخش قالب «نوار کناری فروشگاه» نمایش داده می‌شود (بخش‌های قالب → نوار کناری فروشگاه). از فیلترها و دسته‌بندی‌های ووکامرس یا هر بلوکی استفاده کنید.', 'musicwave' ),
			),
			array(
				'name'        => __( 'ابزارک‌های سربرگ MusicWave', 'musicwave' ),
				'id'          => 'musicwave-header-widgets',
				'description' => __( 'اختیاری: ناحیه کوچک ابزارک برای سربرگ (مثلاً اعلان یا تغییر زبان). بلوک‌ها را از نمایش → ابزارک‌ها یا مستقیماً در بخش قالب سربرگ اضافه کنید.', 'musicwave' ),
			),
		);

		foreach ( $sidebars as $sidebar ) {
			register_sidebar(
				array_merge(
					$sidebar,
					array(
						'before_widget' => '<section id="%1$s" class="widget mw-widget %2$s">',
						'after_widget'  => '</section>',
						'before_title'  => '<h3 class="widget-title">',
						'after_title'   => '</h3>',
					)
				)
			);
		}
	}

	/**
	 * Map sidebar template part slugs to their registered widget areas.
	 *
	 * @return array<string, string>
	 */
	public function sidebar_widget_areas(): array {
		return array(
			'sidebar'      => 'musicwave-sidebar',
			'sidebar-shop' => 'musicwave-shop-sidebar',
		);
	}

	/**
	 * Inject widgets into sidebar template parts, collapsing empty shells cleanly.
	 *
	 * @param string               $content Rendered template part HTML.
	 * @param array<string, mixed> $block   Parsed block.
	 */
	public function inject_sidebar_widgets( string $content, array $block ): string {
		$slug  = isset( $block['attrs']['slug'] ) ? (string) $block['attrs']['slug'] : '';
		$areas = $this->sidebar_widget_areas();

		if ( ! isset( $areas[ $slug ] ) ) {
			return $content;
		}

		$widget_id = $areas[ $slug ];
		$widgets   = '';
		if ( function_exists( 'is_active_sidebar' ) && is_active_sidebar( $widget_id ) ) {
			ob_start();
			dynamic_sidebar( $widget_id );
			$rendered = (string) ob_get_clean();
			// Strip empty `<section class="widget mw-widget ..."></section>` wrappers left
			// behind when dynamic blocks intentionally render nothing on the current route.
			$cleaned = preg_replace( '/<section\b[^>]*\bclass="widget\s+mw-widget[^"]*"[^>]*>\s*<\/section>/i', '', $rendered );
			$widgets = trim( is_string( $cleaned ) ? $cleaned : $rendered );
			if (
				'' !== $widgets
				&& '' === trim( wp_strip_all_tags( $widgets ) )
				&& false === strpos( $widgets, '<input' )
				&& false === strpos( $widgets, '<select' )
				&& false === strpos( $widgets, '<textarea' )
				&& false === strpos( $widgets, '<img' )
				&& false === strpos( $widgets, '<svg' )
			) {
				$widgets = '';
			}
		}

		if ( '' === $widgets ) {
			if ( $this->is_editor_request() ) {
				return $this->sidebar_editor_hint( $content );
			}

			return '' === trim( wp_strip_all_tags( $content ) ) ? '' : $content;
		}

		$widget_markup = '<div class="mw-widget-section mw-widget-section--widgets">' . $widgets . '</div>';
		$root_close    = strrpos( $content, '</div>' );
		if ( false !== strpos( $content, 'mw-sidebar' ) && false !== $root_close ) {
			return substr( $content, 0, $root_close ) . $widget_markup . substr( $content, $root_close );
		}

		return '<div class="mw-sidebar">' . $widget_markup . '</div>';
	}

	/**
	 * Whether the current render happens inside the Site Editor / admin preview.
	 */
	private function is_editor_request(): bool {
		return BlockSupport::is_editor_context();
	}

	/**
	 * Editor-only affordance shown while a sidebar widget area is still empty.
	 *
	 * The frontend keeps collapsing the whole shell (no reserved column), while
	 * the Site Editor explains where the editable widgets live instead of
	 * showing a silently empty template part.
	 *
	 * @param string $content Rendered template part HTML.
	 */
	private function sidebar_editor_hint( string $content ): string {
		$message = __( 'این ناحیه از «نمایش → ابزارک‌ها» با کشیدن هر بلوک یا ابزارک پر می‌شود؛ تا زمانی که ابزارکی اختصاص ندهید، نوار کناری در سایت به‌طور خودکار پنهان می‌ماند.', 'musicwave' );
		$hint    = '<div class="mw-widget-section mw-widget-section--empty-hint"><p class="mw-sidebar__hint">' . esc_html( $message ) . '</p></div>';
		$close   = strrpos( $content, '</div>' );

		if ( false !== strpos( $content, 'mw-sidebar' ) && false !== $close ) {
			return substr( $content, 0, $close ) . $hint . substr( $content, $close );
		}

		return '<div class="mw-sidebar">' . $hint . '</div>';
	}

	/**
	 * Register the Appearance submenu screen for restoring overridden bundled templates.
	 */
	public function register_template_repair_page(): void {
		add_theme_page(
			__( 'تعمیر قالب MusicWave', 'musicwave' ),
			__( 'تعمیر قالب MusicWave', 'musicwave' ),
			'edit_theme_options',
			'musicwave-template-repair',
			'musicwave_render_template_repair_page'
		);
	}

	/**
	 * Bundled templates and template parts safe to restore from theme files.
	 *
	 * @return array<string, array<int, string>>
	 */
	public function repairable_template_slugs(): array {
		return array(
			'wp_template'      => array(
				'404',
				'archive',
				'archive-mw_release',
				'home',
				'index',
				'page',
				'page-account',
				'page-login',
				'page-artists',
				'page-browse',
				'page-genres',
				'page-playlists',
				'page-requests',
				'page-request-song',
				'page-request-collab',
				'page-stream',
				'page-cart',
				'page-checkout',
				'page-music-home',
				'page-no-sidebar',
				'page-wide',
				'page-landing',
				'page-shop',
				'page-with-sidebar',
				'search',
				'single',
				'single-mw_release',
				'single-product',
				'taxonomy-mw_artist',
				'taxonomy-mw_genre',
				'archive-product',
			),
			'wp_template_part' => array(
				'header',
				'header-centered',
				'header-minimal',
				'header-stream',
				'header-stream-end',
				'footer',
				'footer-widgets',
				'footer-simple',
				'sidebar',
				'sidebar-shop',
				'hero',
			),
		);
	}

	/**
	 * Canonical account template slug.
	 */
	public function account_template_slug(): string {
		return 'page-account';
	}

	/**
	 * Legacy account template slugs aliased to the canonical unified account layout.
	 *
	 * @return array<int, string>
	 */
	public function account_template_aliases(): array {
		return array(
			'page-library',
			'page-dashboard',
			'page-my-account',
			'page-music-library',
			'page-music-user-dashboard',
			'page-user-dashboard',
		);
	}

	/**
	 * Alias legacy single-template account lookups to page-account.
	 *
	 * @param mixed  $template      Resolved template or null.
	 * @param string $id            Template identifier.
	 * @param string $template_type Template type.
	 * @return mixed
	 */
	public function alias_account_template( $template, string $id, string $template_type ) {
		if ( null !== $template || 'wp_template' !== $template_type ) {
			return $template;
		}

		$parts = explode( '//', $id );
		$slug  = (string) end( $parts );
		if ( ! in_array( $slug, $this->account_template_aliases(), true ) ) {
			return $template;
		}

		return get_block_template( get_stylesheet() . '//' . $this->account_template_slug(), $template_type );
	}

	/**
	 * Alias legacy plural template hierarchy queries to page-account.
	 *
	 * @param array<int, mixed>    $templates     Found templates.
	 * @param array<string, mixed> $query         Query arguments.
	 * @param string               $template_type Template type.
	 * @return array<int, mixed>
	 */
	public function alias_account_templates_in_query( array $templates, array $query, string $template_type ): array {
		if ( 'wp_template' !== $template_type || empty( $query['slug__in'] ) || ! is_array( $query['slug__in'] ) ) {
			return $templates;
		}
		$aliases = array_values( array_intersect( $query['slug__in'], $this->account_template_aliases() ) );
		if ( empty( $aliases ) ) {
			return $templates;
		}
		foreach ( $templates as $existing ) {
			if ( is_object( $existing ) && in_array( $existing->slug, $aliases, true ) ) {
				return $templates;
			}
		}

		$canonical = get_block_template( get_stylesheet() . '//' . $this->account_template_slug(), 'wp_template' );
		if ( $canonical ) {
			$alias        = clone $canonical;
			$alias->slug  = $aliases[0];
			$alias->id    = get_stylesheet() . '//' . $aliases[0];
			$alias->title = $canonical->title . ' (alias)';
			$templates[]  = $alias;
		}

		return $templates;
	}

	/**
	 * Human titles for bundled templates.
	 *
	 * @return array<string, string>
	 */
	public function template_titles(): array {
		return array(
			'page-playlists'      => __( 'فهرست‌های پخش عمومی', 'musicwave' ),
			'page-requests'       => __( 'درخواست آهنگ و همکاری', 'musicwave' ),
			'page-request-song'   => __( 'سفارش آهنگ اختصاصی', 'musicwave' ),
			'page-request-collab' => __( 'همکاری', 'musicwave' ),
			'page-stream'         => __( 'صفحهٔ استریم (نوار کناری)', 'musicwave' ),
		);
	}

	/**
	 * Apply translated human title to theme-sourced block templates.
	 *
	 * @param mixed $template Block template object or null.
	 * @return mixed
	 */
	public function apply_template_title( $template ) {
		if ( ! is_object( $template ) || 'theme' !== ( isset( $template->source ) ? (string) $template->source : '' ) ) {
			return $template;
		}

		$titles = $this->template_titles();
		$slug   = isset( $template->slug ) ? (string) $template->slug : '';
		if ( '' !== $slug && isset( $titles[ $slug ] ) ) {
			$template->title = $titles[ $slug ];
		}

		return $template;
	}

	/**
	 * Find database template overrides for bundled MusicWave templates.
	 *
	 * @return array<int, WP_Post>
	 */
	public function customized_template_overrides(): array {
		$repairable = $this->repairable_template_slugs();
		$cap        = (int) apply_filters( 'musicwave_template_overrides_limit', 100 );
		$cap        = $cap >= 1 && $cap <= 500 ? $cap : 100;
		$posts      = get_posts(
			array(
				'post_type'      => array_keys( $repairable ),
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => $cap,
				'no_found_rows'  => true,
				'tax_query'      => array(
					array(
						'taxonomy' => 'wp_theme',
						'field'    => 'name',
						'terms'    => get_stylesheet(),
					),
				),
			)
		);

		if ( ! is_array( $posts ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$posts,
				static function ( WP_Post $post ) use ( $repairable ): bool {
					return isset( $repairable[ $post->post_type ] )
						&& in_array( $post->post_name, $repairable[ $post->post_type ], true );
				}
			)
		);
	}

	/**
	 * Render the template repair admin page.
	 */
	public function render_template_repair_page(): void {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}

		$overrides = $this->customized_template_overrides();
		$repaired  = isset( $_GET['musicwave-repaired'] ) && is_scalar( $_GET['musicwave-repaired'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? absint( wp_unslash( (string) $_GET['musicwave-repaired'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: 0;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'تعمیر قالب MusicWave', 'musicwave' ); ?></h1>
			<?php if ( $repaired > 0 ) : ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of restored template overrides. */
							__( '%d مورد سفارشی‌سازی قالب MusicWave به زباله‌دان منتقل شد. فایل‌های معتبر قالب اکنون فعال هستند.', 'musicwave' ),
							$repaired
						)
					);
					?>
				</p></div>
			<?php endif; ?>
			<p><?php esc_html_e( 'سفارشی‌سازی‌های ویرایشگر سایت در پایگاه داده ذخیره می‌شوند و بر فایل‌های به‌روزشده قالب اولویت دارند. فقط زمانی از این ابزار استفاده کنید که قالب MusicWave بخش قالبی ناموجود یا محتوای بلوک نامعتبر گزارش کند.', 'musicwave' ); ?></p>
			<p><strong><?php esc_html_e( 'این کار انتشارها، صفحات، منوها، رسانه‌ها، تنظیمات افزونه یا سبک‌های کلی را حذف نمی‌کند.', 'musicwave' ); ?></strong></p>
			<p>
				<?php
				printf(
					/* translators: %d: number of customized template overrides. */
					esc_html__( 'تعداد سفارشی‌سازی‌های ذخیره‌شده قالب: %d', 'musicwave' ),
					count( $overrides )
				);
				?>
			</p>
			<?php if ( ! empty( $overrides ) ) : ?>
				<ul>
					<?php foreach ( $overrides as $override ) : ?>
						<li><code><?php echo esc_html( $override->post_type . ': ' . $override->post_name ); ?></code></li>
					<?php endforeach; ?>
				</ul>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="musicwave_repair_templates">
					<?php wp_nonce_field( 'musicwave_repair_templates' ); ?>
					<?php submit_button( __( 'بازیابی فایل‌های معتبر قالب', 'musicwave' ), 'primary' ); ?>
				</form>
			<?php else : ?>
				<p><?php esc_html_e( 'هیچ سفارشی‌سازی ذخیره‌شده‌ای پیدا نشد. وردپرس در حال حاضر از فایل‌های قالب بسته استفاده می‌کند.', 'musicwave' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Move customized bundled template overrides to Trash upon confirmation.
	 */
	public function handle_template_repair(): void {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			wp_die( esc_html__( 'شما اجازه تعمیر قالب‌ها را ندارید.', 'musicwave' ) );
		}

		check_admin_referer( 'musicwave_repair_templates' );
		$repaired = 0;
		foreach ( $this->customized_template_overrides() as $override ) {
			if ( wp_trash_post( $override->ID ) ) {
				++$repaired;
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'               => 'musicwave-template-repair',
					'musicwave-repaired' => $repaired,
				),
				admin_url( 'themes.php' )
			)
		);
		exit;
	}
}
