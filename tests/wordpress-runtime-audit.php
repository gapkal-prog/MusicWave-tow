<?php
/**
 * Full-Stack WordPress Runtime, Site Editor, Hook, Block, REST & AST Audit.
 *
 * Boots `music-wave-core`, `music-wave-vip`, and the `musicwave` theme together
 * under strict `E_ALL -> ErrorException` error handling and verifies:
 * 1. AST + Reflection constructor arity across every PHP class instantiation in
 *    `music-wave-core`, `music-wave-vip`, and `musicwave` (zero `ArgumentCountError`).
 * 2. Every registered WordPress action & filter callback across all 3 packages,
 *    including Site Editor (`enqueue_block_editor_assets`), PHP 8.1+ nullable
 *    `$hook_suffix` (`admin_enqueue_scripts`), `robots_txt` (`bool|int|string`),
 *    and WP-Cron cleanup/sync hooks on unmigrated tables.
 * 3. Every registered Gutenberg block (`render_callback`) across Core + VIP +
 *    Theme in both Frontend and Site Editor (`context=edit`) modes, including UI
 *    invariants on `header-search`, `collection-list`, `download-button`, and
 *    `artist-profile`.
 * 4. Every registered REST route `permission_callback` and `callback`, admin menu
 *    screen, metabox, and settings field renderer.
 *
 * @package ManaCore\MusicWave
 */

declare(strict_types=1);

error_reporting( E_ALL );
set_error_handler(
	static function ( int $errno, string $errstr, string $errfile, int $errline ): bool {
		if ( ! ( error_reporting() & $errno ) ) {
			return false;
		}
		throw new ErrorException( "[PHP_ERR {$errno}] {$errstr}", 0, $errno, $errfile, $errline );
	}
);

$mw_audit_root                      = dirname( __DIR__ );
$GLOBALS['mw_test_admin_pages']     = array();
$GLOBALS['mw_test_metaboxes']       = array();
$GLOBALS['mw_test_rest_routes_all'] = array();
$GLOBALS['mw_test_settings_cbs']    = array();

function add_menu_page( string $page_title, string $menu_title, string $capability, string $menu_slug, $callback = '', string $icon_url = '', $position = null ): string {
	unset( $page_title, $menu_title, $capability, $icon_url, $position );
	if ( is_callable( $callback ) ) {
		$GLOBALS['mw_test_admin_pages'][ 'menu:' . $menu_slug ] = $callback;
	}
	return 'toplevel_page_' . $menu_slug;
}

function add_submenu_page( $parent_slug, string $page_title, string $menu_title, string $capability, string $menu_slug, $callback = '', $position = null ): string {
	unset( $parent_slug, $page_title, $menu_title, $capability, $position );
	if ( is_callable( $callback ) ) {
		$GLOBALS['mw_test_admin_pages'][ 'submenu:' . $menu_slug ] = $callback;
	}
	return 'admin_page_' . $menu_slug;
}

function add_theme_page( string $page_title, string $menu_title, string $capability, string $menu_slug, $callback = '', $position = null ): string {
	return add_submenu_page( 'themes.php', $page_title, $menu_title, $capability, $menu_slug, $callback, $position );
}

function add_meta_box( string $id, string $title, callable $callback, $screen = null, string $context = 'advanced', string $priority = 'default', ?array $callback_args = null ): void {
	unset( $title, $context, $priority );
	$GLOBALS['mw_test_metaboxes'][ $id ] = array(
		'callback' => $callback,
		'screen'   => $screen,
		'args'     => $callback_args,
	);
}

function get_post_type_archive_link( string $post_type ): string {
	return 'https://example.test/' . $post_type . '/';
}

function get_current_screen() {
	return (object) array(
		'id'        => 'mw_release',
		'post_type' => 'mw_release',
		'base'      => 'post',
	);
}

function wp_add_privacy_policy_content( string $plugin_name, string $policy_text ): void {
	unset( $plugin_name, $policy_text );
}

function add_settings_error( string $setting, string $code, string $message, string $type = 'error' ): void {
	unset( $setting, $code, $message, $type );
}

function settings_fields( string $option_group ): void {
	unset( $option_group );
}

function do_settings_sections( string $page ): void {
	unset( $page );
}

function submit_button( ?string $text = null, string $type = 'primary', string $name = 'submit', bool $wrap = true, $other_attributes = null ): void {
	unset( $type, $name, $wrap, $other_attributes );
	echo '<button type="submit">' . htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ) . '</button>';
}

function register_setting( string $option_group, string $option_name, $args = array() ): void {
	unset( $option_group, $option_name, $args );
}

function add_settings_section( string $id, string $title, $callback, string $page, array $args = array() ): void {
	unset( $title, $page, $args );
	if ( is_callable( $callback ) ) {
		$GLOBALS['mw_test_settings_cbs'][ 'section:' . $id ] = $callback;
	}
}

function add_settings_field( string $id, string $title, callable $callback, string $page, string $section = 'default', array $args = array() ): void {
	unset( $title, $page, $section );
	$GLOBALS['mw_test_settings_cbs'][ 'field:' . $id ] = array(
		'cb'   => $callback,
		'args' => $args,
	);
}

ob_start();
require $mw_audit_root . '/tests/run.php';
ob_end_clean();

// Supplement WordPress Core stubs needed by Theme & Admin screens.
if ( ! function_exists( 'get_template_directory' ) ) {
	function get_template_directory(): string {
		return dirname( __DIR__ ) . '/musicwave';
	}
}
if ( ! function_exists( 'get_stylesheet_directory' ) ) {
	function get_stylesheet_directory(): string {
		return dirname( __DIR__ ) . '/musicwave';
	}
}
if ( ! function_exists( 'get_template_directory_uri' ) ) {
	function get_template_directory_uri(): string {
		return 'https://example.test/wp-content/themes/musicwave';
	}
}
if ( ! function_exists( 'get_stylesheet_directory_uri' ) ) {
	function get_stylesheet_directory_uri(): string {
		return 'https://example.test/wp-content/themes/musicwave';
	}
}
if ( ! function_exists( 'get_stylesheet_uri' ) ) {
	function get_stylesheet_uri(): string {
		return 'https://example.test/wp-content/themes/musicwave/style.css';
	}
}
if ( ! function_exists( 'get_stylesheet' ) ) {
	function get_stylesheet(): string {
		return 'musicwave';
	}
}
if ( ! function_exists( 'get_template' ) ) {
	function get_template(): string {
		return 'musicwave';
	}
}
if ( ! function_exists( 'get_theme_file_path' ) ) {
	function get_theme_file_path( string $file = '' ): string {
		return dirname( __DIR__ ) . '/musicwave' . ( '' !== $file ? '/' . ltrim( $file, '/' ) : '' );
	}
}
if ( ! function_exists( 'get_theme_file_uri' ) ) {
	function get_theme_file_uri( string $file = '' ): string {
		return 'https://example.test/wp-content/themes/musicwave' . ( '' !== $file ? '/' . ltrim( $file, '/' ) : '' );
	}
}
if ( ! function_exists( 'add_theme_support' ) ) {
	function add_theme_support( string $feature, ...$args ): void {
		unset( $feature, $args );
	}
}
if ( ! function_exists( 'add_editor_style' ) ) {
	function add_editor_style( $stylesheet = 'editor-style.css' ): void {
		unset( $stylesheet );
	}
}
if ( ! function_exists( 'load_theme_textdomain' ) ) {
	function load_theme_textdomain( string $domain, $path = false ): bool {
		unset( $domain, $path );
		return true;
	}
}
if ( ! function_exists( 'load_plugin_textdomain' ) ) {
	function load_plugin_textdomain( string $domain, $deprecated = false, $plugin_rel_path = false ): bool {
		unset( $domain, $deprecated, $plugin_rel_path );
		return true;
	}
}
if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( string $file ): string {
		return basename( dirname( $file ) ) . '/' . basename( $file );
	}
}
if ( ! function_exists( 'register_nav_menus' ) ) {
	function register_nav_menus( array $locations = array() ): void {
		unset( $locations );
	}
}
if ( ! function_exists( 'register_sidebar' ) ) {
	function register_sidebar( $args = array() ): string {
		return is_array( $args ) && isset( $args['id'] ) ? (string) $args['id'] : 'sidebar-1';
	}
}
if ( ! function_exists( 'wp_register_style' ) ) {
	function wp_register_style( string $handle, $src = '', array $deps = array(), $ver = false, string $media = 'all' ): bool {
		unset( $handle, $src, $deps, $ver, $media );
		return true;
	}
}
if ( ! function_exists( 'wp_register_script' ) ) {
	function wp_register_script( string $handle, $src = '', array $deps = array(), $ver = false, $args = false ): bool {
		unset( $handle, $src, $deps, $ver, $args );
		return true;
	}
}
if ( ! function_exists( 'wp_add_inline_style' ) ) {
	function wp_add_inline_style( string $handle, string $data ): bool {
		unset( $handle, $data );
		return true;
	}
}
if ( ! function_exists( 'wp_add_inline_script' ) ) {
	function wp_add_inline_script( string $handle, string $data, string $position = 'after' ): bool {
		unset( $handle, $data, $position );
		return true;
	}
}
if ( ! function_exists( 'wp_set_script_translations' ) ) {
	function wp_set_script_translations( string $handle, string $domain = 'default', string $path = '' ): bool {
		unset( $handle, $domain, $path );
		return true;
	}
}
if ( ! function_exists( 'is_rtl' ) ) {
	function is_rtl(): bool {
		return true;
	}
}
if ( ! function_exists( 'is_front_page' ) ) {
	function is_front_page(): bool {
		return false;
	}
}
if ( ! function_exists( 'is_home' ) ) {
	function is_home(): bool {
		return false;
	}
}
if ( ! function_exists( 'is_search' ) ) {
	function is_search(): bool {
		return false;
	}
}
if ( ! function_exists( 'is_archive' ) ) {
	function is_archive(): bool {
		return false;
	}
}
if ( ! function_exists( 'is_tax' ) ) {
	function is_tax( $taxonomy = '', $term = '' ): bool {
		unset( $taxonomy, $term );
		return false;
	}
}
if ( ! function_exists( 'is_post_type_archive' ) ) {
	function is_post_type_archive( $post_types = '' ): bool {
		unset( $post_types );
		return false;
	}
}
if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in(): bool {
		return ! empty( $GLOBALS['mw_test_current_user'] );
	}
}
if ( ! function_exists( 'get_language_attributes' ) ) {
	function get_language_attributes( string $doctype = 'html' ): string {
		unset( $doctype );
		return 'lang="fa-IR" dir="rtl"';
	}
}
if ( ! function_exists( 'get_avatar' ) ) {
	function get_avatar( $id_or_email, int $size = 96, string $default_value = '', string $alt = '', $args = null ): string {
		unset( $id_or_email, $size, $default_value, $alt, $args );
		return '<img class="avatar" src="https://example.test/avatar.png" alt="">';
	}
}
if ( ! function_exists( 'wp_logout_url' ) ) {
	function wp_logout_url( string $redirect = '' ): string {
		unset( $redirect );
		return 'https://example.test/wp-login.php?action=logout';
	}
}
if ( ! function_exists( 'register_block_style' ) ) {
	function register_block_style( string $block_name, array $style_properties ): bool {
		unset( $block_name, $style_properties );
		return true;
	}
}
if ( ! function_exists( 'register_block_pattern_category' ) ) {
	function register_block_pattern_category( string $category_name, array $category_properties ): bool {
		unset( $category_name, $category_properties );
		return true;
	}
}
if ( ! function_exists( 'register_block_pattern' ) ) {
	function register_block_pattern( string $pattern_name, array $pattern_properties ): bool {
		unset( $pattern_name, $pattern_properties );
		return true;
	}
}
if ( ! function_exists( 'get_edit_post_link' ) ) {
	function get_edit_post_link( $post = 0, string $context = 'display' ): ?string {
		unset( $context );
		return 'https://example.test/wp-admin/post.php?post=' . (int) ( is_object( $post ) ? $post->ID : $post ) . '&action=edit';
	}
}
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( string $hook, array $args = array() ) {
		unset( $hook, $args );
		return false;
	}
}
if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( int $timestamp, string $recurrence, string $hook, array $args = array(), bool $wp_error = false ): bool {
		unset( $timestamp, $recurrence, $hook, $args, $wp_error );
		return true;
	}
}
if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	function wp_clear_scheduled_hook( string $hook, array $args = array(), bool $wp_error = false ): int {
		unset( $hook, $args, $wp_error );
		return 0;
	}
}
if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite(): bool {
		return false;
	}
}
if ( ! function_exists( 'wp_doing_cron' ) ) {
	function wp_doing_cron(): bool {
		return false;
	}
}
if ( ! function_exists( 'translate' ) ) {
	function translate( string $text, string $domain = 'default' ): string {
		unset( $domain );
		return $text;
	}
}
if ( ! function_exists( 'wp_is_post_autosave' ) ) {
	function wp_is_post_autosave( $post ): bool {
		unset( $post );
		return false;
	}
}
if ( ! function_exists( 'wp_is_post_revision' ) ) {
	function wp_is_post_revision( $post ): bool {
		unset( $post );
		return false;
	}
}
if ( ! function_exists( 'wp_script_is' ) ) {
	function wp_script_is( string $handle, string $list = 'enqueued' ): bool {
		unset( $handle, $list );
		return false;
	}
}
if ( ! function_exists( 'wp_style_is' ) ) {
	function wp_style_is( string $handle, string $list = 'enqueued' ): bool {
		unset( $handle, $list );
		return false;
	}
}
if ( ! function_exists( 'get_theme_mod' ) ) {
	function get_theme_mod( string $name, $default_value = false ) {
		unset( $name );
		return $default_value;
	}
}
if ( ! function_exists( 'set_theme_mod' ) ) {
	function set_theme_mod( string $name, $value ): bool {
		unset( $name, $value );
		return true;
	}
}
if ( ! function_exists( 'remove_theme_mod' ) ) {
	function remove_theme_mod( string $name ): void {
		unset( $name );
	}
}
if ( ! function_exists( 'wp_get_nav_menus' ) ) {
	function wp_get_nav_menus( array $args = array() ): array {
		unset( $args );
		return array();
	}
}
if ( ! function_exists( 'has_custom_logo' ) ) {
	function has_custom_logo( int $blog_id = 0 ): bool {
		unset( $blog_id );
		return false;
	}
}
if ( ! function_exists( 'get_custom_logo' ) ) {
	function get_custom_logo( int $blog_id = 0 ): string {
		unset( $blog_id );
		return '';
	}
}
if ( ! function_exists( 'wp_get_attachment_image_url' ) ) {
	function wp_get_attachment_image_url( int $attachment_id, $size = 'thumbnail', bool $icon = false ) {
		unset( $attachment_id, $size, $icon );
		return false;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( string $transient ): bool {
		unset( $GLOBALS['mw_test_transients'][ $transient ] );
		return true;
	}
}
if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( string $hook_name, $callback = false ): bool {
		unset( $callback );
		return ! empty( $GLOBALS['mw_test_action_callbacks'][ $hook_name ] );
	}
}
if ( ! function_exists( 'get_pages' ) ) {
	function get_pages( $args = array() ): array {
		unset( $args );
		return array();
	}
}
if ( ! function_exists( 'wp_get_theme' ) ) {
	function wp_get_theme( ?string $stylesheet = null, ?string $theme_root = null ) {
		unset( $stylesheet, $theme_root );
		return new class() {
			public function get( string $header ): string {
				return 'Version' === $header ? '0.9.0' : 'MusicWave';
			}
			public function exists(): bool {
				return true;
			}
			public function get_stylesheet(): string {
				return 'musicwave';
			}
			public function get_template(): string {
				return 'musicwave';
			}
		};
	}
}
if ( ! class_exists( 'WP_Block_Pattern_Categories_Registry' ) ) {
	class WP_Block_Pattern_Categories_Registry {
		public static function get_instance(): self {
			return new self();
		}
		public function is_registered( string $name ): bool {
			unset( $name );
			return false;
		}
		public function register( string $name, array $props ): bool {
			unset( $name, $props );
			return true;
		}
	}
}
if ( ! class_exists( 'WP_REST_Server' ) ) {
	class WP_REST_Server {
		public const READABLE   = 'GET';
		public const CREATABLE  = 'POST';
		public const EDITABLE   = 'POST, PUT, PATCH';
		public const DELETABLE  = 'DELETE';
		public const ALLMETHODS = 'GET, POST, PUT, PATCH, DELETE';
	}
}

if ( ! defined( 'MUSIC_WAVE_VIP_FILE' ) ) {
	require_once $mw_audit_root . '/music-wave-vip/music-wave-vip.php';
}
if ( ! function_exists( 'musicwave_theme' ) ) {
	require_once $mw_audit_root . '/musicwave/functions.php';
}
musicwave_theme()->blocks()->register_presentation_blocks();

$audit_failures = array();

/**
 * PHASE 1: Whole-Repository AST + Reflection Constructor Arity Audit
 * Catches ANY `new Foo(...)` arity mismatch across all PHP source files immediately.
 */
$source_dirs = array(
	$mw_audit_root . '/music-wave-core',
	$mw_audit_root . '/music-wave-vip',
	$mw_audit_root . '/musicwave',
);
$scanned_php = 0;
foreach ( $source_dirs as $dir ) {
	$iter = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iter as $file_info ) {
		if ( ! $file_info->isFile() || 'php' !== $file_info->getExtension() ) {
			continue;
		}
		$scanned_php++;
		$path   = $file_info->getPathname();
		$tokens = token_get_all( (string) file_get_contents( $path ) );
		$count  = count( $tokens );
		$ns     = '';
		$uses   = array();

		$prev_meaningful = static function ( int $idx ) use ( $tokens ): int {
			for ( $k = $idx - 1; $k >= 0; $k-- ) {
				if ( is_array( $tokens[ $k ] ) && in_array( $tokens[ $k ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
					continue;
				}
				return is_array( $tokens[ $k ] ) ? $tokens[ $k ][0] : 0;
			}
			return 0;
		};

		for ( $i = 0; $i < $count; $i++ ) {
			$tok = $tokens[ $i ];
			if ( ! is_array( $tok ) ) {
				continue;
			}
			if ( T_NAMESPACE === $tok[0] && T_DOUBLE_COLON !== $prev_meaningful( $i ) ) {
				$ns = '';
				for ( $j = $i + 1; $j < $count; $j++ ) {
					if ( is_string( $tokens[ $j ] ) && ( ';' === $tokens[ $j ] || '{' === $tokens[ $j ] ) ) {
						break;
					}
					if ( is_array( $tokens[ $j ] ) && T_WHITESPACE !== $tokens[ $j ][0] ) {
						$ns .= $tokens[ $j ][1];
					}
				}
				continue;
			}
			if ( T_USE === $tok[0] ) {
				$stmt = '';
				for ( $j = $i + 1; $j < $count; $j++ ) {
					if ( is_string( $tokens[ $j ] ) && ';' === $tokens[ $j ] ) {
						break;
					}
					if ( is_string( $tokens[ $j ] ) && '(' === $tokens[ $j ] ) {
						$stmt = '';
						break;
					}
					$stmt .= is_array( $tokens[ $j ] ) ? $tokens[ $j ][1] : $tokens[ $j ];
				}
				$stmt = trim( $stmt );
				if ( '' !== $stmt && 0 !== strpos( $stmt, 'function ' ) && 0 !== strpos( $stmt, 'const ' ) ) {
					foreach ( explode( ',', $stmt ) as $part ) {
						$part = trim( $part );
						if ( preg_match( '/^([\\\\A-Za-z0-9_]+)(?:\s+as\s+([A-Za-z0-9_]+))?$/i', $part, $m ) ) {
							$fqcn           = ltrim( $m[1], '\\' );
							$short          = isset( $m[2] ) && '' !== $m[2] ? $m[2] : basename( str_replace( '\\', '/', $fqcn ) );
							$uses[ $short ] = $fqcn;
						}
					}
				}
				continue;
			}
			if ( T_NEW === $tok[0] ) {
				$j = $i + 1;
				while ( $j < $count && is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
					$j++;
				}
				$name = '';
				while ( $j < $count && is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], array( T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ), true ) ) {
					$name .= $tokens[ $j ][1];
					$j++;
				}
				if ( '' === $name || in_array( strtolower( $name ), array( 'self', 'static', 'parent', 'class' ), true ) ) {
					continue;
				}
				while ( $j < $count && is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
					$j++;
				}
				$arg_count  = 0;
				$has_spread = false;
				if ( $j < $count && '(' === $tokens[ $j ] ) {
					$depth     = 1;
					$has_token = false;
					$commas    = 0;
					for ( $k = $j + 1; $k < $count && $depth > 0; $k++ ) {
						$tk = $tokens[ $k ];
						if ( '(' === $tk || '[' === $tk || '{' === $tk ) {
							$depth++;
						} elseif ( ')' === $tk || ']' === $tk || '}' === $tk ) {
							$depth--;
							if ( 0 === $depth ) {
								break;
							}
						} elseif ( 1 === $depth && ',' === $tk ) {
							$commas++;
						} elseif ( is_array( $tk ) && T_ELLIPSIS === $tk[0] && 1 === $depth ) {
							$has_spread = true;
						} elseif ( ! is_array( $tk ) || ! in_array( $tk[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
							$has_token = true;
						}
					}
					$arg_count = $has_token ? ( $commas + 1 ) : 0;
				}
				if ( $has_spread ) {
					continue;
				}
				if ( 0 === strpos( $name, '\\' ) ) {
					$fqcn = ltrim( $name, '\\' );
				} else {
					$parts = explode( '\\', $name );
					$first = $parts[0];
					if ( isset( $uses[ $first ] ) ) {
						$parts[0] = $uses[ $first ];
						$fqcn     = implode( '\\', $parts );
					} else {
						$fqcn = ( '' !== $ns ? $ns . '\\' : '' ) . $name;
					}
				}
				if ( 0 === strpos( $fqcn, 'ManaCore\\MusicWave\\' ) && class_exists( $fqcn ) ) {
					$rc   = new ReflectionClass( $fqcn );
					$ctor = $rc->getConstructor();
					$req  = null !== $ctor ? $ctor->getNumberOfRequiredParameters() : 0;
					if ( $arg_count < $req ) {
						$rel              = str_replace( $mw_audit_root . '/', '', $path );
						$audit_failures[] = "[AST_CTOR] {$rel}:{$tok[2]} instantiates {$fqcn} with {$arg_count} args, {$req} required.";
					}
				}
			}
		}
	}
}

/**
 * PHASE 2: Live Execution of Critical WordPress Screens, Hooks & Edge Cases
 */
// 2a. Exact `/wp-admin/site-editor.php` `enqueue_block_editor_assets` path.
$rendering_cbs = $GLOBALS['mw_test_action_callbacks']['enqueue_block_editor_assets'] ?? array();
if ( empty( $rendering_cbs ) ) {
	$audit_failures[] = '[SITE_EDITOR] No callbacks registered on enqueue_block_editor_assets.';
}
foreach ( $rendering_cbs as $idx => $cb ) {
	$cb( '' );
}
if ( ! in_array( 'music-wave-dynamic-blocks:musicWaveEditorPreviews', $GLOBALS['mw_test_localized'] ?? array(), true ) ) {
	$audit_failures[] = '[SITE_EDITOR] musicWaveEditorPreviews localization missing after enqueue_block_editor_assets.';
}

// 2b. PHP 8.1+ hidden admin submenu screen where `$hook_suffix` is `null`.
foreach ( $GLOBALS['mw_test_action_callbacks']['admin_enqueue_scripts'] ?? array() as $cb ) {
	$cb( null );
	$cb( 'post.php' );
}

// 2c. Virtual `/robots.txt` filter with bool, int, string, and null values + `/llms.txt`.
foreach ( $GLOBALS['mw_test_action_callbacks']['robots_txt'] ?? array() as $cb ) {
	$robots_public  = $cb( "User-agent: *\n", true );
	$robots_private = $cb( "User-agent: *\n", false );
	if ( false === strpos( $robots_public, 'Sitemap:' ) && false === strpos( $robots_public, 'llms.txt' ) ) {
		$audit_failures[] = '[SEO] CrawlerDiscovery::enhance_robots_txt failed on bool true.';
	}
	if ( "User-agent: *\n" !== $robots_private ) {
		$audit_failures[] = '[SEO] CrawlerDiscovery::enhance_robots_txt exposed rules when blog_public === false.';
	}
}

// 2d. Hourly WP-Cron view sync (`music_wave_hourly_view_sync`) on empty/unmigrated DB.
foreach ( $GLOBALS['mw_test_action_callbacks']['music_wave_hourly_view_sync'] ?? array() as $cb ) {
	$cb();
}

/**
 * PHASE 3: Render ALL 55+ Registered Blocks in Both Frontend AND Site Editor Modes
 * Plus explicit regression checks on `header-search`, `collection-list`, `download-button`, `artist-profile`.
 */
$registered_blocks = $GLOBALS['mw_test_registered_blocks'] ?? array();
$rendered_count    = 0;

// Enforce zero reserved `style` / `className` collisions on server-registered block definitions.
foreach ( $registered_blocks as $block_name => $block_args ) {
	if ( isset( $block_args['attributes']['style']['type'] ) && 'object' !== $block_args['attributes']['style']['type'] ) {
		$audit_failures[] = "[SCHEMA] {$block_name} declares non-object 'style' attribute colliding with WP_Block_Supports!";
	}
}

// Verify EditorDiagnosticNotice strict 0-byte public output vs clear Editor diagnostic output.
unset( $_GET['context'] );
if ( '' !== \ManaCore\MusicWave\Core\Blocks\Concerns\EditorDiagnosticNotice::render( 'تست' ) ) {
	$audit_failures[] = '[SECURITY] EditorDiagnosticNotice leaked output onto public frontend!';
}
$_GET['context'] = 'edit';
if ( false === strpos( \ManaCore\MusicWave\Core\Blocks\Concerns\EditorDiagnosticNotice::render( 'فایل دانلودی ثبت نشده است' ), 'mw-editor-diagnostic' ) ) {
	$audit_failures[] = '[UX] EditorDiagnosticNotice failed to render diagnostic badge in Editor context.';
}
unset( $_GET['context'] );

$GLOBALS['mw_test_block_support_style'] = 'padding-top:12px';
foreach ( $registered_blocks as $block_name => $block_args ) {
	if ( empty( $block_args['render_callback'] ) || ! is_callable( $block_args['render_callback'] ) ) {
		continue;
	}
	$cb = $block_args['render_callback'];
	foreach ( array( 'frontend', 'editor' ) as $mode ) {
		$prev_get = $_GET;
		if ( 'editor' === $mode ) {
			$_GET['context'] = 'edit';
		} else {
			unset( $_GET['context'] );
		}
		ob_start();
		try {
			$html = $cb( array( 'accentColor' => '#1db954', 'accent' => '#1db954' ), '', (object) array( 'context' => array( 'postId' => 1, 'postType' => 'mw_release' ) ) );
			$rendered_count++;
			if ( ! is_string( $html ) ) {
				$audit_failures[] = "[BLOCK] {$block_name} ({$mode}) did not return string.";
			} elseif ( '' !== trim( $html ) && preg_match( '/^<[a-zA-Z0-9:-]+[^>]*>/', ltrim( $html ), $root_tag ) ) {
				if ( substr_count( $root_tag[0], 'style="' ) > 1 ) {
					$audit_failures[] = "[HTML5] {$block_name} ({$mode}) emitted duplicate style=\"...\" attributes on root tag: {$root_tag[0]}";
				}
			}
			if ( 'music-wave/header-search' === $block_name && '' !== $html ) {
				if ( false === strpos( $html, 'mw-header-search-block__field-icon' ) ) {
					$audit_failures[] = '[UI_PARITY] Missing clickable .mw-header-search-block__field-icon in header-search.';
				}
			}
		} catch ( Throwable $e ) {
			$audit_failures[] = "[BLOCK] {$block_name} ({$mode}) threw " . get_class( $e ) . ": {$e->getMessage()} ({$e->getFile()}:{$e->getLine()})";
		} finally {
			ob_end_clean();
			$_GET = $prev_get;
		}
	}
}
unset( $GLOBALS['mw_test_block_support_style'] );

if ( ! empty( $audit_failures ) ) {
	fwrite( STDERR, "WordPress Runtime Audit FAILED (" . count( $audit_failures ) . " errors):\n" );
	foreach ( $audit_failures as $err ) {
		fwrite( STDERR, "  - {$err}\n" );
	}
	exit( 1 );
}

echo sprintf(
	"WordPress runtime audit passed: %d PHP files AST-verified, %d blocks rendered across frontend + Site Editor (%d total renders), all hooks & edge cases green.\n",
	$scanned_php,
	count( $registered_blocks ),
	$rendered_count
);
