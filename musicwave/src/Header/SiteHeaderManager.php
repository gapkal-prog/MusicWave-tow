<?php
declare(strict_types=1);

namespace ManaCore\MusicWave\Theme\Header;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns every site-wide header decision and nothing else.
 *
 * Responsibilities, in boot order:
 *
 * 1. Active header template part — Customizer select, `render_block` remap so
 *    every template (bundled, saved, or previewed) resolves the same part.
 * 2. Bottom navigation bar — one Customizer setting decides whether the
 *    `mw-rail__tabbar` navigation exists at all and on which breakpoints. The
 *    bar is written into *every* header template part content, so it is a real,
 *    editable `core/navigation` block in the Site Editor for all headers, not
 *    markup injected after rendering. Turning it off strips it again and
 *    collapses `--mw-rail-tabbar-height`, which re-docks the global player to
 *    the bottom edge and releases the reserved page padding.
 * 3. Header width block style (`wide` | `full`) — Customizer default plus a
 *    render-time stamp on `.mw-site-header__inner`.
 * 4. Header-scoped commerce de-duplication and header nest tracking.
 *
 * Everything content-related (brand, links, icons, search, theme toggle,
 * account) intentionally lives in the Site Editor template parts. The theme
 * ships no dedicated header admin screen: selection belongs to the Customizer,
 * composition belongs to the Site Editor.
 */
final class SiteHeaderManager {

	/** Default active header template part. */
	private const DEFAULT_HEADER = 'header-stream';

	/** Theme mod holding the active header template part slug. */
	private const HEADER_MOD = 'musicwave_site_header';

	/** Theme mod holding the bottom bar scope. */
	private const TABBAR_MOD = 'musicwave_chrome_tabbar';

	/** Theme mod holding the bottom bar label visibility flag. */
	private const TABBAR_LABELS_MOD = 'musicwave_chrome_tabbar_labels';

	/** Theme mod holding the header width block style. */
	private const WIDTH_MOD = 'musicwave_header_width';

	/** Default bottom bar scope. */
	private const DEFAULT_TABBAR = 'both';

	/**
	 * Request-scoped cache for Site Editor header template parts.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $registered_choices_cache = null;

	/** @var bool */
	private bool $loading_choices = false;

	/** @var int */
	private int $render_depth = 0;

	/**
	 * Register header chrome hooks across frontend, Site Editor, and Customizer.
	 */
	public function register(): void {
		add_filter( 'language_attributes', array( $this, 'filter_language_attributes' ), 20 );
		add_filter( 'render_block', array( $this, 'remap_header_template_part' ), 9, 2 );
		add_filter( 'get_block_file_template', array( $this, 'sync_header_template_content' ), 10, 3 );
		add_filter( 'get_block_template', array( $this, 'sync_header_template_content' ), 10, 3 );
		add_filter( 'get_block_templates', array( $this, 'sync_header_templates_content' ), 10, 3 );
		add_filter( 'pre_render_block', array( $this, 'strip_header_commerce_blocks' ), 9, 2 );
		add_filter( 'pre_render_block', array( $this, 'flag_header_template_part' ), 10, 2 );
		add_filter( 'render_block', array( $this, 'append_header_tabbar' ), 12, 2 );
		add_filter( 'render_block', array( $this, 'apply_header_width_mode' ), 15, 2 );
		add_filter( 'render_block', array( $this, 'release_header_template_part' ), 20, 2 );
		add_action( 'customize_register', array( $this, 'register_customizer' ) );
		add_filter( 'body_class', array( $this, 'filter_release_body_class' ) );
	}

	/**
	 * Bundled header template part choices.
	 *
	 * @return array<string, string>
	 */
	public function builtin_choices(): array {
		return array(
			'header'            => __( 'سربرگ ساده (افقی)', 'musicwave' ),
			'header-centered'   => __( 'سربرگ وسط‌چین', 'musicwave' ),
			'header-minimal'    => __( 'سربرگ کمینه', 'musicwave' ),
			'header-stream'     => __( 'سربرگ استریم (ریل شروع اینلاین)', 'musicwave' ),
			'header-stream-end' => __( 'سربرگ استریم (ریل انتها)', 'musicwave' ),
		);
	}

	/**
	 * Header template parts registered in the Site Editor.
	 *
	 * @return array<string, string>
	 */
	public function registered_choices(): array {
		if ( is_array( $this->registered_choices_cache ) ) {
			return $this->registered_choices_cache;
		}
		if ( $this->loading_choices || ! function_exists( 'get_block_templates' ) ) {
			return array();
		}

		$this->loading_choices = true;
		$found                 = array();
		$parts                 = get_block_templates( array( 'area' => 'header' ), 'wp_template_part' );
		if ( is_array( $parts ) ) {
			foreach ( $parts as $part ) {
				$slug = is_object( $part ) && property_exists( $part, 'slug' ) ? sanitize_key( (string) $part->slug ) : '';
				if ( '' === $slug || isset( $found[ $slug ] ) ) {
					continue;
				}
				$title          = is_object( $part ) && property_exists( $part, 'title' ) && is_string( $part->title ) && '' !== $part->title
					? $part->title
					: $slug;
				$found[ $slug ] = $title;
			}
		}
		$this->loading_choices          = false;
		$this->registered_choices_cache = $found;

		return $this->registered_choices_cache;
	}

	/**
	 * All selectable header template part slugs and titles.
	 *
	 * @return array<string, string>
	 */
	public function site_header_choices(): array {
		return array_merge( $this->builtin_choices(), $this->registered_choices() );
	}

	/**
	 * Sanitize a header template-part slug against selectable choices.
	 *
	 * @param mixed $value Raw theme mod input.
	 */
	public function sanitize_site_header( $value ): string {
		$slug = is_scalar( $value ) ? sanitize_key( (string) $value ) : '';

		return isset( $this->site_header_choices()[ $slug ] ) ? $slug : self::DEFAULT_HEADER;
	}

	/**
	 * Registry-free active header slug for hot-path block rendering.
	 */
	public function active_header_slug(): string {
		$slug = get_theme_mod( self::HEADER_MOD, self::DEFAULT_HEADER );
		$slug = is_scalar( $slug ) ? sanitize_key( (string) $slug ) : '';

		return '' !== $slug ? $slug : self::DEFAULT_HEADER;
	}

	/**
	 * Validated active header slug for UI controls.
	 */
	public function site_header_slug(): string {
		return $this->sanitize_site_header( get_theme_mod( self::HEADER_MOD, self::DEFAULT_HEADER ) );
	}

	/**
	 * Supported mobile navigation modes.
	 *
	 * `tabs` stays selectable through the `musicwave_mobile_chrome` filter for
	 * child themes that want the bar without the drawer; the Customizer exposes
	 * the friendlier bottom-bar scopes below.
	 *
	 * @return array<string, string>
	 */
	public function mobile_header_choices(): array {
		return array(
			'drawer' => __( 'موبایل: سربرگ ساده + منوی کناری', 'musicwave' ),
			'tabs'   => __( 'موبایل: سربرگ + نوار پایین', 'musicwave' ),
			'both'   => __( 'موبایل: منوی کناری + نوار پایین', 'musicwave' ),
		);
	}

	/**
	 * Sanitize mobile navigation mode.
	 *
	 * @param mixed $value Raw input.
	 */
	public function sanitize_mobile_header( $value ): string {
		$slug = is_scalar( $value ) ? sanitize_key( (string) $value ) : '';

		return isset( $this->mobile_header_choices()[ $slug ] ) ? $slug : 'both';
	}

	/**
	 * Bottom navigation bar scopes offered in the Customizer.
	 *
	 * @return array<string, string>
	 */
	public function tabbar_choices(): array {
		return array(
			'off'    => __( 'خاموش (بدون نوار پایین)', 'musicwave' ),
			'mobile' => __( 'فقط موبایل (تا ۴۸rem)', 'musicwave' ),
			'both'   => __( 'موبایل و تبلت (تا ۶۴rem)', 'musicwave' ),
		);
	}

	/**
	 * Sanitize the bottom navigation bar scope.
	 *
	 * @param mixed $value Raw input.
	 */
	public function sanitize_tabbar_scope( $value ): string {
		$scope = is_scalar( $value ) ? sanitize_key( (string) $value ) : '';

		return isset( $this->tabbar_choices()[ $scope ] ) ? $scope : self::DEFAULT_TABBAR;
	}

	/**
	 * Active bottom navigation bar scope (`off` | `mobile` | `both`).
	 */
	public function tabbar_scope(): string {
		$scope = $this->sanitize_tabbar_scope( get_theme_mod( self::TABBAR_MOD, self::DEFAULT_TABBAR ) );

		return (string) apply_filters( 'musicwave_tabbar_scope', $scope );
	}

	/**
	 * Whether the bottom bar navigation is part of every header template part.
	 */
	public function tabbar_visible(): bool {
		return in_array( $this->tabbar_scope(), array( 'mobile', 'both' ), true );
	}

	/**
	 * Sanitize the bottom bar label flag.
	 *
	 * @param mixed $value Raw input.
	 */
	public function sanitize_tabbar_labels( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		return is_scalar( $value ) && in_array( (string) $value, array( '1', 'true', 'on' ), true );
	}

	/**
	 * Whether bottom bar items render their text label under the icon.
	 */
	public function tabbar_labels_enabled(): bool {
		$enabled = $this->sanitize_tabbar_labels( get_theme_mod( self::TABBAR_LABELS_MOD, true ) );

		return (bool) apply_filters( 'musicwave_tabbar_labels_enabled', $enabled );
	}

	/**
	 * Active mobile navigation mode written to `<html data-mw-mobile-chrome>`.
	 *
	 * Kept in sync with the bottom bar scope so the existing drawer/tabbar CSS
	 * contracts keep working: no bar means drawer-only chrome.
	 */
	public function mobile_header_slug(): string {
		$slug = $this->tabbar_visible() ? 'both' : 'drawer';

		return $this->sanitize_mobile_header( apply_filters( 'musicwave_mobile_chrome', $slug ) );
	}

	/**
	 * HTML chrome data attributes exposed on the root `<html>` element.
	 *
	 * @return array<string, string>
	 */
	public function chrome_attrs(): array {
		return array(
			'data-mw-tabbar'        => $this->tabbar_scope(),
			'data-mw-tabbar-labels' => $this->tabbar_labels_enabled() ? '1' : '0',
			'data-mw-mobile-chrome' => $this->mobile_header_slug(),
		);
	}

	/**
	 * Append chrome data attributes onto <html> language_attributes output.
	 */
	public function filter_language_attributes( string $output ): string {
		$bits = array();
		foreach ( $this->chrome_attrs() as $name => $value ) {
			$bits[] = $name . '="' . esc_attr( $value ) . '"';
		}

		return trim( $output . ' ' . implode( ' ', $bits ) );
	}

	/**
	 * Canonical bottom bar block markup shipped into every header part.
	 *
	 * A plain `core/navigation` block (not a `ref`), so each header owns its own
	 * editable copy: administrators add, remove, rename, and relink items in the
	 * Site Editor exactly like any other navigation block.
	 */
	public function tabbar_block_markup(): string {
		$links = array(
			array(
				'label' => __( 'خانه', 'musicwave' ),
				'url'   => '/',
				'icon'  => 'home',
				'home'  => true,
			),
			array(
				'label' => __( 'مرور', 'musicwave' ),
				'url'   => '/browse/',
				'icon'  => 'browse',
				'home'  => false,
			),
			array(
				'label' => __( 'قطعه‌ها', 'musicwave' ),
				'url'   => '/music/',
				'icon'  => 'tracks',
				'home'  => false,
			),
			array(
				'label' => __( 'هنرمندان', 'musicwave' ),
				'url'   => '/artists/',
				'icon'  => 'artists',
				'home'  => false,
			),
			array(
				'label' => __( 'فروشگاه', 'musicwave' ),
				'url'   => '/shop/',
				'icon'  => 'charts',
				'home'  => false,
			),
		);

		$inner = array();
		foreach ( $links as $link ) {
			$classname = sanitize_html_class( 'mw-icon-' . $link['icon'] );
			$payload   = $link['home']
				? array(
					'label'     => $link['label'],
					'className' => $classname,
				)
				: array(
					'label'     => $link['label'],
					'url'       => $link['url'],
					'kind'      => 'custom',
					'className' => $classname,
				);
			$json      = wp_json_encode( $payload );
			if ( ! is_string( $json ) ) {
				continue;
			}
			$inner[] = '<!-- wp:' . ( $link['home'] ? 'home-link' : 'navigation-link' ) . ' ' . $json . ' /-->';
		}

		$attrs = wp_json_encode(
			array(
				'metadata'    => array( 'name' => __( 'نوار پایین موبایل', 'musicwave' ) ),
				'ariaLabel'   => __( 'ناوبری پایین', 'musicwave' ),
				'overlayMenu' => 'never',
				'className'   => 'mw-rail__tabbar mw-rail__nav--primary',
				'layout'      => array(
					'type'           => 'flex',
					'flexWrap'       => 'nowrap',
					'justifyContent' => 'space-around',
				),
			)
		);

		if ( ! is_string( $attrs ) ) {
			return '';
		}

		return '<!-- wp:navigation ' . $attrs . ' -->' . implode( '', $inner ) . '<!-- /wp:navigation -->';
	}

	/**
	 * Whether a parsed block is the bottom navigation bar.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 */
	private function is_tabbar_block( array $block ): bool {
		if ( 'core/navigation' !== ( $block['blockName'] ?? '' ) ) {
			return false;
		}
		$attrs     = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$classname = isset( $attrs['className'] ) && is_scalar( $attrs['className'] ) ? (string) $attrs['className'] : '';

		return false !== strpos( $classname, 'mw-rail__tabbar' );
	}

	/**
	 * Drop the bottom navigation bar from one parsed block, keeping the tree valid.
	 *
	 * `WP_Block_Parser` describes a container block with two parallel lists:
	 * `innerContent` (HTML chunks plus one `null` placeholder per child) and
	 * `innerBlocks` (the children in placeholder order). Removing a child from
	 * `innerBlocks` alone desynchronises both lists, so `serialize_block()`
	 * renders the *next* child where the removed one used to be, leaves orphan
	 * wrapper HTML behind, and hands WordPress a malformed tree. Core then
	 * explodes inside `traverse_and_serialize_blocks()` when it applies block
	 * hooks to that content (`wp-includes/blocks.php`: "Undefined array key 2",
	 * "Trying to access array offset on null", "foreach() argument must be of
	 * type array|object, null given", 'Undefined array key "blockName"').
	 *
	 * Returning `null` removes the block; every surviving block gets both lists
	 * rewritten in lockstep.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @return array<string, mixed>|null
	 */
	private function strip_tabbar_block( array $block ): ?array {
		if ( $this->is_tabbar_block( $block ) ) {
			return null;
		}

		$children = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();
		if ( array() === $children ) {
			return $block;
		}

		$content       = isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ? $block['innerContent'] : array();
		$kept_children = array();
		$kept_content  = array();
		$child_index   = 0;

		foreach ( $content as $chunk ) {
			if ( is_string( $chunk ) ) {
				$kept_content[] = $chunk;
				continue;
			}

			$child = isset( $children[ $child_index ] ) && is_array( $children[ $child_index ] ) ? $children[ $child_index ] : null;
			++$child_index;
			if ( null === $child ) {
				continue;
			}

			$stripped = $this->strip_tabbar_block( $child );
			if ( null === $stripped ) {
				continue;
			}

			$kept_children[] = $stripped;
			$kept_content[]  = null;
		}

		$block['innerBlocks'] = $kept_children;
		if ( isset( $block['innerContent'] ) ) {
			$block['innerContent'] = $kept_content;
		}

		return $block;
	}

	/**
	 * Drop the bottom navigation bar from a parsed block tree.
	 *
	 * @param array<int, mixed> $blocks Parsed blocks.
	 * @return array<int, array<string, mixed>>
	 */
	private function strip_tabbar_blocks( array $blocks ): array {
		$kept = array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$stripped = $this->strip_tabbar_block( $block );
			if ( null !== $stripped ) {
				$kept[] = $stripped;
			}
		}

		return $kept;
	}

	/**
	 * Add or remove the bottom bar inside one header template part content.
	 *
	 * The transform is a no-op whenever the content already matches the active
	 * setting, so `header-stream` (which ships the bar inline) and custom parts
	 * never get duplicate navigation blocks.
	 */
	public function shape_header_part_content( string $content ): string {
		if ( '' === trim( $content ) ) {
			return $content;
		}

		$has_bar = false !== strpos( $content, 'mw-rail__tabbar' );

		if ( $this->tabbar_visible() ) {
			return $has_bar ? $content : rtrim( $content ) . "\n\n" . $this->tabbar_block_markup() . "\n";
		}

		if ( ! $has_bar || ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
			return $content;
		}

		$blocks = parse_blocks( $content );
		if ( ! is_array( $blocks ) ) {
			return $content;
		}

		$shaped = serialize_blocks( $this->strip_tabbar_blocks( $blocks ) );

		return '' !== trim( $shaped ) ? $shaped : $content;
	}

	/**
	 * Whether a resolved template object is a header-area template part.
	 *
	 * @param mixed $template Template object.
	 */
	private function is_header_part( $template ): bool {
		if ( ! is_object( $template ) || ! isset( $template->slug ) ) {
			return false;
		}
		$area = property_exists( $template, 'area' ) && is_string( $template->area ) ? $template->area : '';
		if ( 'header' === $area ) {
			return true;
		}

		$slug = sanitize_key( (string) $template->slug );

		return 'header' === $slug || 0 === strpos( $slug, 'header-' );
	}

	/**
	 * Remap rendered header template parts to the active site-wide header choice.
	 *
	 * @param string               $block_content Rendered HTML.
	 * @param array<string, mixed> $block         Parsed block.
	 */
	public function remap_header_template_part( string $block_content, array $block ): string {
		if ( 'core/template-part' !== ( $block['blockName'] ?? '' ) ) {
			return $block_content;
		}

		$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$slug  = isset( $attrs['slug'] ) ? sanitize_key( (string) $attrs['slug'] ) : '';
		if ( ! isset( $this->builtin_choices()[ $slug ] ) ) {
			return $block_content;
		}

		$wanted = $this->active_header_slug();
		if ( $wanted === $slug ) {
			return $block_content;
		}

		static $remapping = false;
		if ( $remapping ) {
			return $block_content;
		}

		$remapping = true;
		$html      = do_blocks( '<!-- wp:template-part {"slug":"' . $wanted . '","tagName":"header"} /-->' );
		$remapping = false;

		return is_string( $html ) ? $html : $block_content;
	}

	/**
	 * Rewrite header template-part slugs inside serialized template content.
	 */
	public function rewrite_header_part_slugs( string $content ): string {
		if ( false === strpos( $content, 'wp:template-part' ) ) {
			return $content;
		}

		$active = $this->active_header_slug();
		$known  = $this->builtin_choices();

		$rewritten = preg_replace_callback(
			'/<!--\s*wp:template-part\s+(\{.*?\})\s*\/-->/',
			static function ( array $part_match ) use ( $active, $known ): string {
				$attrs = json_decode( $part_match[1], true );
				if ( ! is_array( $attrs ) || empty( $attrs['slug'] ) ) {
					return $part_match[0];
				}
				$slug = sanitize_key( (string) $attrs['slug'] );
				if ( '' === $slug || ! isset( $known[ $slug ] ) || $slug === $active ) {
					return $part_match[0];
				}
				$attrs['slug'] = $active;
				$json          = wp_json_encode( $attrs );

				return is_string( $json ) ? '<!-- wp:template-part ' . $json . ' /-->' : $part_match[0];
			},
			$content
		);

		return is_string( $rewritten ) ? $rewritten : $content;
	}

	/**
	 * Sync the active header part slug and bottom bar into single resolved templates.
	 *
	 * @param mixed  $template      Template object or null.
	 * @param string $id            Template identifier.
	 * @param string $template_type Template type.
	 * @return mixed
	 */
	public function sync_header_template_content( $template, string $id, string $template_type ) {
		unset( $id );
		if ( ! is_object( $template ) || ! isset( $template->content ) || ! is_string( $template->content ) ) {
			return $template;
		}

		if ( 'wp_template' === $template_type ) {
			$template->content = $this->rewrite_header_part_slugs( $template->content );

			return $template;
		}

		if ( 'wp_template_part' === $template_type && $this->is_header_part( $template ) ) {
			$template->content = $this->shape_header_part_content( $template->content );
		}

		return $template;
	}

	/**
	 * Sync the active header part slug and bottom bar across template lists.
	 *
	 * @param array<int, mixed>    $templates     Template objects.
	 * @param array<string, mixed> $query         Query args.
	 * @param string               $template_type Template type.
	 * @return array<int, mixed>
	 */
	public function sync_header_templates_content( array $templates, array $query, string $template_type ): array {
		unset( $query );
		if ( 'wp_template' !== $template_type && 'wp_template_part' !== $template_type ) {
			return $templates;
		}

		foreach ( $templates as $template ) {
			if ( ! is_object( $template ) || ! isset( $template->content ) || ! is_string( $template->content ) ) {
				continue;
			}
			if ( 'wp_template' === $template_type ) {
				$template->content = $this->rewrite_header_part_slugs( $template->content );
				continue;
			}
			if ( $this->is_header_part( $template ) ) {
				$template->content = $this->shape_header_part_content( $template->content );
			}
		}

		return $templates;
	}

	/**
	 * Build direct Site Editor canvas edit link for a template part.
	 */
	public function site_editor_part_url( string $slug ): string {
		return add_query_arg(
			array(
				'postType' => 'wp_template_part',
				'postId'   => get_stylesheet() . '//' . sanitize_key( $slug ),
				'canvas'   => 'edit',
			),
			admin_url( 'site-editor.php' )
		);
	}

	/**
	 * Duplicate WooCommerce blocks stripped inside header template parts.
	 *
	 * @return array<int, string>
	 */
	public function replaced_commerce_blocks(): array {
		$blocks   = array(
			'woocommerce/customer-account',
			'woocommerce/mini-cart',
		);
		$filtered = apply_filters( 'musicwave_header_replaced_blocks', $blocks );

		return is_array( $filtered ) ? array_values( array_map( 'strval', $filtered ) ) : $blocks;
	}

	/**
	 * Track nesting depth while rendering header template parts.
	 */
	public function render_scope( string $action, string $slug = '' ): int {
		if ( 'peek' === $action ) {
			return $this->render_depth;
		}

		if ( ! isset( $this->site_header_choices()[ $slug ] ) ) {
			return $this->render_depth;
		}

		if ( 'open' === $action ) {
			++$this->render_depth;
		} elseif ( 'close' === $action && $this->render_depth > 0 ) {
			--$this->render_depth;
		}

		return $this->render_depth;
	}

	/**
	 * Enter header render scope before rendering a header template part.
	 *
	 * @param mixed                $pre          Pre-rendered content or null.
	 * @param array<string, mixed> $parsed_block Parsed block.
	 * @return mixed
	 */
	public function flag_header_template_part( $pre, array $parsed_block ) {
		if ( 'core/template-part' === ( $parsed_block['blockName'] ?? '' ) ) {
			$slug = isset( $parsed_block['attrs']['slug'] ) ? sanitize_key( (string) $parsed_block['attrs']['slug'] ) : '';
			$this->render_scope( 'open', $slug );
		}

		return $pre;
	}

	/**
	 * Exit header render scope after rendering a header template part.
	 *
	 * @param string               $block_content Rendered HTML.
	 * @param array<string, mixed> $block         Parsed block.
	 */
	public function release_header_template_part( string $block_content, array $block ): string {
		if ( 'core/template-part' === ( $block['blockName'] ?? '' ) ) {
			$slug = isset( $block['attrs']['slug'] ) ? sanitize_key( (string) $block['attrs']['slug'] ) : '';
			$this->render_scope( 'close', $slug );
		}

		return $block_content;
	}

	/**
	 * Guarantee the bottom bar on the front end for every header part.
	 *
	 * The Site Editor reads header parts through `get_block_template*`, where
	 * the bar is already part of the block tree. Older saved parts and plugins
	 * that render parts directly can bypass that path, so the rendered HTML is
	 * topped up here when — and only when — the bar is missing.
	 *
	 * @param string               $block_content Rendered HTML.
	 * @param array<string, mixed> $block         Parsed block.
	 */
	public function append_header_tabbar( string $block_content, array $block ): string {
		if ( 'core/template-part' !== ( $block['blockName'] ?? '' ) || ! $this->tabbar_visible() ) {
			return $block_content;
		}
		if ( false !== strpos( $block_content, 'mw-rail__tabbar' ) ) {
			return $block_content;
		}

		$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$slug  = isset( $attrs['slug'] ) ? sanitize_key( (string) $attrs['slug'] ) : '';
		$area  = isset( $attrs['area'] ) && is_scalar( $attrs['area'] ) ? sanitize_key( (string) $attrs['area'] ) : '';

		if ( 'header' !== $area && 'header' !== $slug && 0 !== strpos( $slug, 'header-' ) ) {
			return $block_content;
		}

		$html = function_exists( 'do_blocks' ) ? do_blocks( $this->tabbar_block_markup() ) : '';

		return is_string( $html ) && '' !== $html ? $block_content . $html : $block_content;
	}

	/**
	 * Strip duplicate commerce blocks inside header template parts only.
	 *
	 * @param mixed                $pre          Pre-rendered HTML or null.
	 * @param array<string, mixed> $parsed_block Parsed block.
	 * @return mixed
	 */
	public function strip_header_commerce_blocks( $pre, array $parsed_block ) {
		$name = (string) ( $parsed_block['blockName'] ?? '' );
		if ( '' === $name || ! in_array( $name, $this->replaced_commerce_blocks(), true ) ) {
			return $pre;
		}

		return $this->render_scope( 'peek' ) > 0 ? '' : $pre;
	}

	/**
	 * Register the Customizer section with every site-wide chrome decision.
	 *
	 * @param mixed $wp_customize WP_Customize_Manager instance.
	 */
	public function register_customizer( $wp_customize ): void {
		if ( ! ( $wp_customize instanceof \WP_Customize_Manager ) ) {
			return;
		}

		$wp_customize->add_section(
			'musicwave_chrome',
			array(
				'title'       => __( 'سربرگ MusicWave', 'musicwave' ),
				'priority'    => 30,
				'description' => __( 'چیدمان سربرگ (برند، منو، جست‌وجو، تغییر پوسته، حساب، آیکون‌ها) را در ویرایشگر سایت → بخش‌های قالب ویرایش کنید. این بخش فقط تصمیم‌های سراسری را می‌گیرد: کدام سربرگ فعال باشد، عرض آن، و نوار پایین موبایل/تبلت.', 'musicwave' ),
			)
		);

		$wp_customize->add_setting(
			self::HEADER_MOD,
			array(
				'default'           => self::DEFAULT_HEADER,
				'sanitize_callback' => 'musicwave_sanitize_site_header',
				'transport'         => 'refresh',
			)
		);
		$wp_customize->add_control(
			self::HEADER_MOD,
			array(
				'type'        => 'select',
				'section'     => 'musicwave_chrome',
				'label'       => __( 'سربرگ سراسری', 'musicwave' ),
				'description' => sprintf(
					/* translators: %s: Site Editor template-part URL. */
					__( 'هر سربرگ یا بخش سربرگی که در ویرایشگر سایت می‌سازید اینجا فهرست می‌شود. <a href="%s">همین سربرگ را در ویرایشگر سایت ویرایش کنید</a>.', 'musicwave' ),
					esc_url( $this->site_editor_part_url( $this->site_header_slug() ) )
				),
				'choices'     => $this->site_header_choices(),
			)
		);

		$wp_customize->add_setting(
			self::WIDTH_MOD,
			array(
				'default'           => 'wide',
				'sanitize_callback' => 'musicwave_sanitize_header_width',
				'transport'         => 'refresh',
			)
		);
		$wp_customize->add_control(
			self::WIDTH_MOD,
			array(
				'type'        => 'select',
				'section'     => 'musicwave_chrome',
				'label'       => __( 'عرض سربرگ', 'musicwave' ),
				'description' => __( 'پیش‌فرض همهٔ سربرگ‌ها. هر سربرگ می‌تواند در ویرایشگر سایت استایل عرض خودش را داشته باشد.', 'musicwave' ),
				'choices'     => $this->header_width_choices(),
			)
		);

		$wp_customize->add_setting(
			self::TABBAR_MOD,
			array(
				'default'           => self::DEFAULT_TABBAR,
				'sanitize_callback' => 'musicwave_sanitize_tabbar_scope',
				'transport'         => 'refresh',
			)
		);
		$wp_customize->add_control(
			self::TABBAR_MOD,
			array(
				'type'        => 'select',
				'section'     => 'musicwave_chrome',
				'label'       => __( 'نوار پایین (تبلت و موبایل)', 'musicwave' ),
				'description' => __( 'روشن: نوار پایین به همهٔ سربرگ‌ها اضافه می‌شود و در ویرایشگر سایت قابل ویرایش است. خاموش: نوار حذف می‌شود و پخش‌کنندهٔ سراسری به لبهٔ پایین صفحه می‌چسبد.', 'musicwave' ),
				'choices'     => $this->tabbar_choices(),
			)
		);

		$wp_customize->add_setting(
			self::TABBAR_LABELS_MOD,
			array(
				'default'           => '1',
				'sanitize_callback' => 'musicwave_sanitize_tabbar_labels',
				'transport'         => 'refresh',
			)
		);
		$wp_customize->add_control(
			self::TABBAR_LABELS_MOD,
			array(
				'type'    => 'checkbox',
				'section' => 'musicwave_chrome',
				'label'   => __( 'نمایش برچسب کنار آیکون‌های نوار پایین', 'musicwave' ),
			)
		);
	}

	/**
	 * Append release type classes onto body_class for singular releases.
	 *
	 * @param array<int, string> $classes Body classes.
	 * @return array<int, string>
	 */
	public function filter_release_body_class( array $classes ): array {
		if ( ! function_exists( 'is_singular' ) || ! is_singular( 'mw_release' ) ) {
			return $classes;
		}

		$classes[] = 'mw-is-release';
		$terms     = get_the_terms( (int) get_the_ID(), 'mw_release_type' );
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				if ( is_object( $term ) && property_exists( $term, 'slug' ) ) {
					$classes[] = 'mw-release-type-' . sanitize_html_class( (string) $term->slug );
				}
			}
		}

		return $classes;
	}

	/**
	 * Supported header width modes.
	 *
	 * @return array<string, string>
	 */
	public function header_width_choices(): array {
		return array(
			'wide' => __( 'گسترده', 'musicwave' ),
			'full' => __( 'تمام‌عرض', 'musicwave' ),
		);
	}

	/**
	 * Sanitize header width mode.
	 *
	 * @param mixed $value Raw input.
	 */
	public function sanitize_header_width( $value ): string {
		$mode = is_scalar( $value ) ? sanitize_key( (string) $value ) : '';

		return isset( $this->header_width_choices()[ $mode ] ) ? $mode : 'wide';
	}

	/**
	 * Active site-wide header width mode.
	 */
	public function header_width_mode(): string {
		$mode = $this->sanitize_header_width( get_theme_mod( self::WIDTH_MOD, 'wide' ) );

		return (string) apply_filters( 'musicwave_header_width_mode', $mode );
	}

	/**
	 * CSS block-style class name for a header width mode.
	 */
	public function header_width_style( string $mode = '' ): string {
		$resolved = '' !== $mode ? $mode : $this->header_width_mode();
		$resolved = isset( $this->header_width_choices()[ $resolved ] ) ? $resolved : 'wide';

		return 'is-style-mw-header-' . $resolved;
	}

	/**
	 * Stamp the active header width block style onto .mw-site-header__inner when not explicitly set.
	 *
	 * @param string               $block_content Rendered HTML.
	 * @param array<string, mixed> $block         Parsed block.
	 */
	public function apply_header_width_mode( string $block_content, array $block ): string {
		if ( 'core/template-part' !== ( $block['blockName'] ?? '' ) || false === strpos( $block_content, 'mw-site-header__inner' ) ) {
			return $block_content;
		}

		$mode = '';
		if ( false !== strpos( $block_content, 'is-style-mw-header-' ) ) {
			if ( preg_match( '/class="(?=[^"]*\bmw-site-header__inner\b)(?=[^"]*\bis-style-mw-header-(wide|full)\b)[^"]*"/', $block_content ) ) {
				return $block_content;
			}
			if ( preg_match( '/"className":"[^"]*is-style-mw-header-(wide|full)/', $block_content, $comment_match ) ) {
				$mode = 'full' === $comment_match[1] ? 'full' : 'wide';
			}
		}

		if ( '' === $mode ) {
			$attrs     = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
			$classname = isset( $attrs['className'] ) && is_scalar( $attrs['className'] ) ? (string) $attrs['className'] : '';
			foreach ( array_keys( $this->header_width_choices() ) as $candidate ) {
				if ( false !== strpos( $classname, 'is-style-mw-header-' . $candidate ) ) {
					$mode = $candidate;
					break;
				}
			}
		}

		$style_class = $this->header_width_style( $mode );
		$rewritten   = preg_replace(
			'/class="([^"]*\bmw-site-header__inner\b[^"]*)"/',
			'class="$1 ' . $style_class . '"',
			$block_content,
			1
		);

		return is_string( $rewritten ) ? $rewritten : $block_content;
	}
}
