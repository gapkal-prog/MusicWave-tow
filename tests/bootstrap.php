<?php
/**
 * Shared WordPress mock bootstrap for the MusicWave test suites.
 *
 * The dependency-free suites (`run.php`, `block-architecture-refactor-test.php`,
 * …) all need the same WordPress surface: option/user/post meta stores, a
 * `register_block_type()` recorder, block-editor helpers and `mw_assert_same()`.
 * Keeping one copy means a suite can never silently test a different stub than
 * its siblings — and a suite that forgets to load it fails loudly instead of
 * passing by doing nothing.
 *
 * @package MusicWave\Tests
 */

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

$GLOBALS['mw_test_registered_blocks'] = array();

if ( ! function_exists( 'register_block_type' ) ) {
	/**
	 * Capture real block registrations from both block slugs and block.json paths.
	 *
	 * @param string               $block_type_or_path Block name or block.json path/dir.
	 * @param array<string, mixed> $args               Registration args.
	 * @return object
	 */
	function register_block_type( string $block_type_or_path, array $args = array() ) {
		$name = $block_type_or_path;
		if ( is_dir( $block_type_or_path ) && is_file( $block_type_or_path . '/block.json' ) ) {
			$meta = json_decode( (string) file_get_contents( $block_type_or_path . '/block.json' ), true );
			if ( is_array( $meta ) && isset( $meta['name'] ) ) {
				$name = (string) $meta['name'];
				$args = array_merge( $meta, $args );
			}
		} elseif ( is_file( $block_type_or_path ) ) {
			$meta = json_decode( (string) file_get_contents( $block_type_or_path ), true );
			if ( is_array( $meta ) && isset( $meta['name'] ) ) {
				$name = (string) $meta['name'];
				$args = array_merge( $meta, $args );
			}
		}
		$GLOBALS['mw_test_registered_blocks'][ $name ] = $args;
		return (object) array( 'name' => $name );
	}
}

$GLOBALS['mw_test_meta']          = array();
$GLOBALS['mw_test_options']       = array();
$GLOBALS['mw_test_types']         = array(
	1  => 'mw_release',
	10 => 'product',
	11 => 'product',
);
$GLOBALS['mw_test_release_types'] = array(
	1 => array( 'album' ),
	2 => array( 'track' ),
	3 => array( 'podcast_episode' ),
);
$GLOBALS['mw_test_users']         = array( 7 => (object) array( 'user_email' => 'buyer@example.test' ) );
$GLOBALS['mw_test_purchases']     = array();
$GLOBALS['mw_test_actions']       = array();
$GLOBALS['mw_test_terms']         = array();
$GLOBALS['mw_test_term_meta']     = array();
$GLOBALS['mw_test_object_terms']  = array();
$GLOBALS['mw_test_user_meta']     = array();
$GLOBALS['mw_test_statuses']      = array();
$GLOBALS['mw_test_capabilities']  = array();
$GLOBALS['mw_test_terms_by_tax']  = array();
$GLOBALS['mw_test_transients']    = array();
$GLOBALS['mw_test_redirects']     = array();
$GLOBALS['mw_test_scripts']       = array();
$GLOBALS['mw_test_localized']     = array();
$GLOBALS['mw_test_term_queries']  = array(
	'object' => 0,
	'post'   => 0,
);
$GLOBALS['mw_test_synth_terms']   = array();
$GLOBALS['mw_test_orders']        = array();
$GLOBALS['mw_test_filters']       = array();
$GLOBALS['mw_test_titles']        = array();
$GLOBALS['mw_test_thumbnails']    = array();
$GLOBALS['mw_test_rewrite_flushes'] = array();
$GLOBALS['mw_test_mail']            = array();
$GLOBALS['mw_test_deleted']         = array();
$GLOBALS['mw_test_styles']          = array();

final class WP_Post {
	/** @var int */
	public $ID;

	/** @var string */
	public $post_type;

	/** @var string */
	public $post_name = '';

	/** @var string */
	public $post_title = '';

	/** @var string */
	public $post_content = '';

	/** @var string */
	public $post_excerpt = '';

	/** @var string */
	public $post_status = 'publish';

	/** @var int Owning post ID; track rows read it to resolve release cover art. */
	public $post_parent = 0;

	public function __construct( int $post_id, string $post_type, string $post_name = '' ) {
		$this->ID        = $post_id;
		$this->post_type = $post_type;
		$this->post_name = $post_name;
	}
}

final class WP_Term {
	/** @var int Object ID set by batched taxonomy lookups. */
	public $object_id = 0;

	/** @var int */
	public $term_id;

	/** @var string */
	public $taxonomy;

	/** @var string */
	public $name;

	/** @var string */
	public $slug;

	/** @var int Published-object total maintained by core term counting. */
	public $count = 0;

	/** @var string Archive description stored on the term row. */
	public $description = '';

	/** @var int Parent term ID for hierarchical taxonomies. */
	public $parent = 0;

	public function __construct( int $term_id, string $taxonomy, string $name, string $slug ) {
		$this->term_id  = $term_id;
		$this->taxonomy = $taxonomy;
		$this->name     = $name;
		$this->slug     = $slug;
	}
}

final class WP_Error {
	/** @var string */
	private $code;

	/** @var string */
	private $message;

	public function __construct( string $code = '', string $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_code(): string {
		return $this->code;
	}

	public function get_error_message(): string {
		return $this->message;
	}
}

final class WP_REST_Response {
	/** @var mixed */
	private $data;

	/** @var int */
	private $status;

	/** @var array<string, string> */
	private $headers = array();

	/**
	 * @param mixed                $data    Response payload.
	 * @param int                  $status  HTTP status code.
	 * @param array<string,string> $headers Response headers.
	 */
	public function __construct( $data = null, int $status = 200, array $headers = array() ) {
		$this->data    = $data;
		$this->status  = $status;
		$this->headers = $headers;
	}

	/** @return mixed */
	public function get_data() {
		return $this->data;
	}

	public function get_status(): int {
		return $this->status;
	}

	public function header( string $key, string $value ): void {
		$this->headers[ $key ] = $value;
	}

	/** @return array<string, string> */
	public function get_headers(): array {
		return $this->headers;
	}
}

/**
 * Mirrors the WordPress request object closely enough for route handlers:
 * `get_param()` plus ArrayAccess (`$request['key']`), which core supports.
 */
final class WP_REST_Request implements ArrayAccess {
	/** @var array<string, mixed> */
	private $params;

	/** @param array<string, mixed> $params Request parameters. */
	public function __construct( array $params = array() ) {
		$this->params = $params;
	}

	public function get_header( string $key ): string {
		$header_key = 'header:' . strtolower( $key );
		return isset( $this->params[ $header_key ] ) ? (string) $this->params[ $header_key ] : '';
	}

	/** @return mixed */
	public function get_param( string $key ) {
		return isset( $this->params[ $key ] ) ? $this->params[ $key ] : null;
	}

	/** @param mixed $offset Parameter name. */
	#[\ReturnTypeWillChange]
	public function offsetExists( $offset ): bool {
		return isset( $this->params[ $offset ] );
	}

	/**
	 * @param mixed $offset Parameter name.
	 * @return mixed
	 */
	#[\ReturnTypeWillChange]
	public function offsetGet( $offset ) {
		return isset( $this->params[ $offset ] ) ? $this->params[ $offset ] : null;
	}

	/**
	 * @param mixed $offset Parameter name.
	 * @param mixed $value  Parameter value.
	 */
	#[\ReturnTypeWillChange]
	public function offsetSet( $offset, $value ): void {
		$this->params[ $offset ] = $value;
	}

	/** @param mixed $offset Parameter name. */
	#[\ReturnTypeWillChange]
	public function offsetUnset( $offset ): void {
		unset( $this->params[ $offset ] );
	}
}

if ( ! function_exists( 'wp_unique_id' ) ) {
	function wp_unique_id( string $prefix = '' ): string {
		static $id = 0;
		return $prefix . (string) ( ++$id );
	}
}

final class WP_Query {
	/** @var bool */
	public $is_search = false;

	/** @var bool */
	public $mw_test_is_main = true;

	/** @var array<int, string> */
	public $mw_test_archive_types = array();

	/** @var array<int, string> Active taxonomy archive slugs. */
	public $mw_test_tax_archives = array();

	/** @var array<string, mixed> */
	public $query_vars = array();

	/** @var array<int, int> Matching post IDs (fields=ids semantics). */
	public $posts = array();

	/** @var int */
	public $found_posts = 0;

	/** @var int */
	public $max_num_pages = 0;

	/**
	 * In-memory query over the harness fixtures: post_type, post_status,
	 * equality meta_query clauses, pagination. Enough for list screens.
	 *
	 * @param array<string, mixed> $query_vars Seed query vars.
	 */
	public function __construct( array $query_vars = array() ) {
		$this->query_vars = $query_vars;
		if ( ! isset( $query_vars['post_type'] ) ) {
			return;
		}
		$types    = (array) $query_vars['post_type'];
		$statuses = isset( $query_vars['post_status'] ) ? (array) $query_vars['post_status'] : array( 'publish' );
		$matches  = array();
		foreach ( $GLOBALS['mw_test_types'] as $post_id => $post_type ) {
			if ( ! in_array( $post_type, $types, true ) || ( ! in_array( 'any', $statuses, true ) && ! in_array( (string) get_post_status( (int) $post_id ), $statuses, true ) ) ) {
				continue;
			}
			$keep = true;
			foreach ( isset( $query_vars['meta_query'] ) && is_array( $query_vars['meta_query'] ) ? $query_vars['meta_query'] : array() as $clause ) {
				if ( is_array( $clause ) && isset( $clause['key'] ) && (string) get_post_meta( (int) $post_id, (string) $clause['key'], true ) !== (string) $clause['value'] ) {
					$keep = false;
				}
			}
			if ( $keep ) {
				$matches[] = (int) $post_id;
			}
		}
		rsort( $matches );
		$this->found_posts   = count( $matches );
		$per_page            = isset( $query_vars['posts_per_page'] ) ? max( 1, (int) $query_vars['posts_per_page'] ) : 10;
		$page                = isset( $query_vars['paged'] ) ? max( 1, (int) $query_vars['paged'] ) : 1;
		$this->max_num_pages = (int) ceil( $this->found_posts / $per_page );
		$this->posts         = array_slice( $matches, ( $page - 1 ) * $per_page, $per_page );
	}

	/** @return mixed */
	public function get( string $query_var, $default_value = '' ) {
		return array_key_exists( $query_var, $this->query_vars ) ? $this->query_vars[ $query_var ] : $default_value;
	}

	/** @param mixed $value Query var value. */
	public function set( string $query_var, $value ): void {
		$this->query_vars[ $query_var ] = $value;
	}

	public function is_main_query(): bool {
		return $this->mw_test_is_main;
	}

	public function is_search(): bool {
		return $this->is_search;
	}

	/** @param mixed $post_type */
	public function is_post_type_archive( $post_type = '' ): bool {
		return in_array( (string) $post_type, $this->mw_test_archive_types, true );
	}

	/**
	 * @param mixed $taxonomy Taxonomy slug or list of slugs.
	 * @param mixed $term     Term slug or ID (ignored by this fixture).
	 */
	public function is_tax( $taxonomy = '', $term = '' ): bool {
		unset( $term );
		if ( empty( $this->mw_test_tax_archives ) ) {
			return false;
		}
		if ( '' === $taxonomy ) {
			return true;
		}

		foreach ( (array) $taxonomy as $candidate ) {
			if ( in_array( (string) $candidate, $this->mw_test_tax_archives, true ) ) {
				return true;
			}
		}

		return false;
	}
}

/**
 * Minimal wpdb double: real prepare()/esc_like() semantics, no database.
 */
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}

final class wpdb { // phpcs:ignore
	/** @var string */
	public $prefix = 'wp_';

	/** @var string */
	public $posts = 'wp_posts';

	/** @var string */
	public $options = 'wp_options';

	/** @var string */
	public $postmeta = 'wp_postmeta';

	/** @var string */
	public $usermeta = 'wp_usermeta';

	/** @var string */
	public $sitemeta = 'wp_sitemeta';

	/** @var string */
	public $terms = 'wp_terms';

	/** @var string */
	public $term_taxonomy = 'wp_term_taxonomy';

	/** @var string */
	public $term_relationships = 'wp_term_relationships';

	/** @var bool */
	public $mw_enable_activity_table = false;

	/** @var \PDO|null */
	private $sqlite = null;

	private function pdo(): \PDO {
		if ( null === $this->sqlite ) {
			$this->sqlite = new \PDO( 'sqlite::memory:' );
			$this->sqlite->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
			$this->sqlite->exec(
				'CREATE TABLE wp_options (option_id INTEGER PRIMARY KEY AUTOINCREMENT, option_name TEXT UNIQUE, option_value TEXT, autoload TEXT DEFAULT "yes");
				 CREATE TABLE wp_postmeta (meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER, meta_key TEXT, meta_value TEXT);
				 CREATE TABLE wp_usermeta (umeta_id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, meta_key TEXT, meta_value TEXT);
				 CREATE TABLE wp_mw_user_activity (
					user_id INTEGER NOT NULL,
					release_id INTEGER NOT NULL,
					event TEXT NOT NULL,
					position INTEGER NOT NULL DEFAULT 0,
					updated_at INTEGER NOT NULL DEFAULT 0,
					PRIMARY KEY (user_id, release_id, event)
				)'
			);
		}
		return $this->sqlite;
	}

	public function get_var( string $sql ) {
		if ( ! $this->mw_enable_activity_table ) {
			return null;
		}
		if ( false !== stripos( $sql, 'SHOW TABLES LIKE' ) ) {
			return $this->prefix . 'mw_user_activity';
		}
		return $this->pdo()->query( $sql )->fetchColumn();
	}

	public function query( string $sql ) {
		if ( ! $this->mw_enable_activity_table ) {
			return false;
		}
		$translated = preg_replace(
			'/ON DUPLICATE KEY UPDATE\s+position\s*=\s*VALUES\(position\),\s*updated_at\s*=\s*VALUES\(updated_at\)/i',
			'ON CONFLICT(user_id, release_id, event) DO UPDATE SET position = excluded.position, updated_at = excluded.updated_at',
			$sql
		);
		return $this->pdo()->exec( (string) $translated );
	}

	public function get_results( string $sql, $output = OBJECT ): array {
		unset( $output );
		if ( ! $this->mw_enable_activity_table ) {
			return array();
		}
		$stmt = $this->pdo()->query( $sql );
		return false !== $stmt ? $stmt->fetchAll( \PDO::FETCH_ASSOC ) : array();
	}

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	/** @param mixed ...$args Placeholder values. */
	public function prepare( string $query, ...$args ): string {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$escaped = array();
		foreach ( $args as $arg ) {
			$escaped[] = is_int( $arg ) || is_float( $arg ) ? $arg : "'" . addslashes( (string) $arg ) . "'";
		}
		$query = str_replace( array( '%s', '%d' ), '%s', $query );

		return vsprintf( $query, $escaped );
	}
}

function is_wp_error( $value ): bool {
	return $value instanceof WP_Error;
}

function plugin_dir_path( string $file ): string {
	return dirname( $file ) . DIRECTORY_SEPARATOR;
}

function plugin_dir_url( string $file ): string {
	unset( $file );
	return 'https://example.test/wp-content/plugins/music-wave-core/';
}

function register_activation_hook( string $file, callable $callback ): void {
	unset( $file, $callback );
}

function register_deactivation_hook( string $file, callable $callback ): void {
	unset( $file, $callback );
}

function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
	unset( $priority, $accepted_args );
	$GLOBALS['mw_test_actions'][]                  = $hook;
	$GLOBALS['mw_test_action_callbacks'][ $hook ][] = $callback;
}

function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
	add_action( $hook, $callback, $priority, $accepted_args );
}

function add_shortcode( string $tag, callable $callback ): void {
	unset( $tag, $callback );
}

$GLOBALS['mw_test_rendered_blocks'] = array();

/**
 * Minimal renderer used by the page-builder shortcode bridge.
 *
 * @param array<string, mixed> $block Parsed block.
 */
function render_block( array $block ): string {
	$GLOBALS['mw_test_rendered_blocks'][] = $block;
	$name                                 = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';

	return '' === $name ? '' : '<div class="mw-test-block" data-block="' . $name . '"></div>';
}

function apply_filters( string $hook, $value, ...$args ) {
	if ( isset( $GLOBALS['mw_test_filters'][ $hook ] ) ) {
		$filter = $GLOBALS['mw_test_filters'][ $hook ];
		return is_callable( $filter ) ? $filter( $value, ...$args ) : $filter;
	}

	return $value;
}

function do_action( string $hook, ...$arguments ): void {
	unset( $arguments );
	$GLOBALS['mw_test_actions'][] = $hook;
}

function is_admin(): bool {
	return false;
}

if ( ! function_exists( 'nocache_headers' ) ) {
	function nocache_headers(): void {}
}

function esc_html__( string $text, string $domain = '' ): string {
	unset( $domain );
	return esc_html( $text );
}

function esc_attr__( string $text, string $domain = '' ): string {
	unset( $domain );
	return esc_attr( $text );
}

function _n( string $single, string $plural, int $number, string $domain = '' ): string {
	unset( $domain );
	return 1 === $number ? $single : $plural;
}

function _x( string $text, string $context, string $domain = '' ): string {
	unset( $context, $domain );
	return $text;
}

function home_url( string $path = '/' ): string {
	return 'https://example.test' . ( '' === $path ? '/' : $path );
}

function admin_url( string $path = '' ): string {
	return 'https://example.test/wp-admin/' . $path;
}

function rest_url( string $path = '' ): string {
	return 'https://example.test/wp-json/' . $path;
}

function wp_login_url( string $redirect = '' ): string {
	return 'https://example.test/wp-login.php?redirect_to=' . rawurlencode( $redirect );
}

function wp_create_nonce( string $action = '' ): string {
	return 'nonce-' . md5( $action );
}

/** @return int|false */
function wp_verify_nonce( string $nonce, string $action = '' ) {
	return wp_create_nonce( $action ) === $nonce ? 1 : false;
}

function wp_nonce_field( string $action = '', string $name = '_wpnonce', bool $referer = true, bool $display = true ): string {
	unset( $referer );
	$field = '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( wp_create_nonce( $action ) ) . '">';
	if ( $display ) {
		echo $field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	return $field;
}

function check_admin_referer( string $action = '', string $name = '_wpnonce' ): bool {
	unset( $action, $name );
	return true;
}

function wp_get_referer() {
	return 'https://example.test/account/';
}

function did_action( string $hook ): int {
	return 'plugins_loaded' === $hook ? 1 : 0;
}

function wp_safe_redirect( string $location, int $status = 302 ): bool {
	$GLOBALS['mw_test_redirects'][] = array( $location, $status );
	return true;
}

function selected( $selected, $current = true, bool $display = true ): string {
	$markup = (string) $selected === (string) $current ? ' selected="selected"' : '';
	if ( $display ) {
		echo $markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	return $markup;
}

if ( ! function_exists( 'checked' ) ) {
	function checked( $checked, $current = true, bool $display = true ): string {
		$markup = (string) $checked === (string) $current ? ' checked="checked"' : '';
		if ( $display ) {
			echo $markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		return $markup;
	}
}

if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( string $format, ?int $timestamp = null ): string {
		return gmdate( $format, null !== $timestamp ? $timestamp : time() );
	}
}

function remove_query_arg( $keys, string $url = '' ): string {
	unset( $keys );
	return $url;
}

/**
 * Core `paginate_links()` double.
 *
 * Mirrors the parts of wp-includes/general-template.php the artists index uses:
 * `%#%` substitution in `base`, a `<span aria-current="page">` for the current
 * page, dotted gaps, and `prev`/`next` anchors. Keeping the shape faithful
 * means the block's output can be asserted against real WordPress markup.
 *
 * @param array<string, mixed> $args Pagination arguments.
 */
function paginate_links( array $args = array() ): string {
	$args = array_merge(
		array(
			'base'      => '%_%',
			'format'    => '?page=%#%',
			'total'     => 1,
			'current'   => 1,
			'mid_size'  => 2,
			'end_size'  => 1,
			'type'      => 'plain',
			'prev_text' => '&laquo;',
			'next_text' => '&raquo;',
		),
		$args
	);

	$total   = max( 1, (int) $args['total'] );
	$current = max( 1, min( $total, (int) $args['current'] ) );
	if ( $total < 2 ) {
		return '';
	}

	$link = static function ( int $page ) use ( $args ): string {
		$base = str_replace( '%_%', (string) $args['format'], (string) $args['base'] );

		return str_replace( '%#%', (string) $page, $base );
	};

	$parts = array();
	if ( $current > 1 ) {
		$parts[] = '<a class="prev page-numbers" href="' . $link( $current - 1 ) . '">' . $args['prev_text'] . '</a>';
	}
	for ( $page = 1; $page <= $total; $page++ ) {
		if ( $page === $current ) {
			$parts[] = '<span aria-current="page" class="page-numbers current">' . $page . '</span>';
			continue;
		}
		$parts[] = '<a class="page-numbers" href="' . $link( $page ) . '">' . $page . '</a>';
	}
	if ( $current < $total ) {
		$parts[] = '<a class="next page-numbers" href="' . $link( $current + 1 ) . '">' . $args['next_text'] . '</a>';
	}

	return implode( "\n", $parts );
}

function is_singular( $post_types = '' ): bool {
	if ( empty( $GLOBALS['mw_test_singular_type'] ) ) {
		return false;
	}
	$active = (string) $GLOBALS['mw_test_singular_type'];
	if ( '' === $post_types ) {
		return true;
	}
	return is_array( $post_types )
		? in_array( $active, $post_types, true )
		: $active === (string) $post_types;
}

/**
 * Taxonomy archive conditional backed by `$GLOBALS['mw_test_tax_archives']`.
 *
 * @param mixed $taxonomy Taxonomy slug or list of slugs.
 * @param mixed $term     Term slug or ID (ignored by this fixture).
 */
function is_tax( $taxonomy = '', $term = '' ): bool {
	unset( $term );
	$active = isset( $GLOBALS['mw_test_tax_archives'] ) ? (array) $GLOBALS['mw_test_tax_archives'] : array();
	if ( empty( $active ) ) {
		return false;
	}
	if ( '' === $taxonomy ) {
		return true;
	}

	foreach ( (array) $taxonomy as $candidate ) {
		if ( in_array( (string) $candidate, $active, true ) ) {
			return true;
		}
	}

	return false;
}

function get_the_ID(): int {
	return isset( $GLOBALS['mw_test_current_post'] ) ? (int) $GLOBALS['mw_test_current_post'] : 0;
}

function get_queried_object_id(): int {
	return get_the_ID();
}

function wp_enqueue_script( string $handle, string $src = '', array $dependencies = array(), $version = false, $args = array() ): void {
	unset( $src, $dependencies, $version, $args );
	$GLOBALS['mw_test_scripts'][] = $handle;
}

function wp_localize_script( string $handle, string $object_name, array $data ): bool {
	unset( $data );
	$GLOBALS['mw_test_localized'][] = $handle . ':' . $object_name;
	return true;
}

function get_block_wrapper_attributes( array $attributes = array() ): string {
	$style_parts = array();
	if ( ! empty( $GLOBALS['mw_test_block_support_style'] ) ) {
		$style_parts[] = trim( (string) $GLOBALS['mw_test_block_support_style'] );
	}
	if ( isset( $attributes['style'] ) && '' !== trim( (string) $attributes['style'] ) ) {
		$style_parts[] = trim( (string) $attributes['style'] );
	}
	$out = 'class="' . esc_attr( isset( $attributes['class'] ) ? (string) $attributes['class'] : '' ) . '"';
	if ( ! empty( $style_parts ) ) {
		$out .= ' style="' . esc_attr( implode( ';', $style_parts ) ) . '"';
	}
	return $out;
}

function __( string $text, string $domain = '' ): string {
	unset( $domain );
	return $text;
}

function esc_html_e( string $text, string $domain = '' ): void {
	unset( $domain );
	echo esc_html( $text );
}

function esc_attr_e( string $text, string $domain = '' ): void {
	unset( $domain );
	echo esc_attr( $text );
}

function sanitize_text_field( string $value ): string {
	return trim( strip_tags( $value ) );
}

function wp_unslash( $value ) {
	if ( is_array( $value ) ) {
		return array_map( 'wp_unslash', $value );
	}
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function sanitize_key( string $value ): string {
	return trim( strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $value ) ) );
}

function sanitize_file_name( string $value ): string {
	return trim( preg_replace( '/[^A-Za-z0-9._-]/', '-', $value ) );
}

function sanitize_title( string $value ): string {
	$value = strtolower( trim( $value ) );
	return trim( preg_replace( '/[^a-z0-9\p{L}]+/u', '-', $value ), '-' );
}

function remove_accents( string $value ): string {
	return $value;
}

function wp_strip_all_tags( string $value ): string {
	return trim( strip_tags( $value ) );
}

function wp_kses_post( string $value ): string {
	return strip_tags( $value, '<p><br><strong><em><a><ul><ol><li>' );
}

function wp_json_encode( $data, int $options = 0, int $depth = 512 ) {
	return json_encode( $data, $options, $depth );
}

function wp_trim_words( string $value, int $count, string $more = '…' ): string {
	$words = preg_split( '/\s+/', trim( $value ) );
	if ( ! is_array( $words ) || count( $words ) <= $count ) {
		return $value;
	}
	return implode( ' ', array_slice( $words, 0, $count ) ) . $more;
}

function sanitize_hex_color( $color ) {
	if ( ! is_string( $color ) ) {
		return '';
	}
	$color = trim( $color );
	return 1 === preg_match( '/^#([A-Fa-f0-9]{3}){1,2}$/', $color ) ? $color : '';
}

function wpautop( string $text ): string {
	$paragraphs = array_filter( array_map( 'trim', preg_split( '/\n{2,}/', (string) preg_replace( '/\r\n/', "\n", $text ) ) ) ?: array() );
	return '<p>' . implode( "</p>\n<p>", $paragraphs ) . '</p>';
}

function term_description( $term, $taxonomy = '' ): string {
	unset( $taxonomy );
	return $term instanceof WP_Term && isset( $term->description ) ? (string) $term->description : '';
}

function absint( $value ): int {
	return abs( (int) $value );
}

function esc_url_raw( string $url, array $protocols = array() ): string {
	$scheme = (string) parse_url( $url, PHP_URL_SCHEME );
	return filter_var( $url, FILTER_VALIDATE_URL ) && ( empty( $protocols ) || in_array( $scheme, $protocols, true ) ) ? $url : '';
}

function wp_parse_url( string $url, int $component = -1 ) {
	return parse_url( $url, $component );
}

function get_post_type( int $post_id ): string {
	return isset( $GLOBALS['mw_test_types'][ $post_id ] ) ? $GLOBALS['mw_test_types'][ $post_id ] : '';
}

function post_type_exists( string $post_type ): bool {
	return 'product' === $post_type || 'mw_release' === $post_type;
}

function wp_insert_post( array $post, bool $wp_error = false ) {
	unset( $wp_error );
	static $next_id      = 9000;
	$post_id             = $next_id++;
	$GLOBALS['mw_test_types'][ $post_id ]    = isset( $post['post_type'] ) ? (string) $post['post_type'] : 'post';
	$GLOBALS['mw_test_titles'][ $post_id ]   = isset( $post['post_title'] ) ? (string) $post['post_title'] : '';
	$GLOBALS['mw_test_contents'][ $post_id ] = isset( $post['post_content'] ) ? (string) $post['post_content'] : '';
	$GLOBALS['mw_test_excerpts'][ $post_id ] = isset( $post['post_excerpt'] ) ? (string) $post['post_excerpt'] : '';
	$GLOBALS['mw_test_statuses'][ $post_id ] = isset( $post['post_status'] ) ? (string) $post['post_status'] : 'draft';

	return $post_id;
}

function get_post_status( int $post_id ) {
	if ( '' === get_post_type( $post_id ) ) {
		return false;
	}
	return isset( $GLOBALS['mw_test_statuses'][ $post_id ] ) ? $GLOBALS['mw_test_statuses'][ $post_id ] : 'publish';
}

/** @param array<string, mixed> $post Post fields (ID required). */
function wp_update_post( array $post, bool $wp_error = false ) {
	unset( $wp_error );
	$post_id = isset( $post['ID'] ) ? (int) $post['ID'] : 0;
	if ( $post_id < 1 || '' === get_post_type( $post_id ) ) {
		return 0;
	}
	if ( isset( $post['post_status'] ) ) {
		$GLOBALS['mw_test_statuses'][ $post_id ] = (string) $post['post_status'];
	}
	if ( isset( $post['post_title'] ) ) {
		$GLOBALS['mw_test_titles'][ $post_id ] = (string) $post['post_title'];
	}
	if ( isset( $post['post_content'] ) ) {
		$GLOBALS['mw_test_contents'][ $post_id ] = (string) $post['post_content'];
	}
	if ( isset( $post['post_excerpt'] ) ) {
		$GLOBALS['mw_test_excerpts'][ $post_id ] = (string) $post['post_excerpt'];
	}
	return $post_id;
}

function wp_delete_post( int $post_id, bool $force = false ) {
	unset( $force );
	if ( '' === get_post_type( $post_id ) ) {
		return false;
	}
	$deleted = new WP_Post( $post_id, get_post_type( $post_id ) );
	unset( $GLOBALS['mw_test_types'][ $post_id ], $GLOBALS['mw_test_statuses'][ $post_id ], $GLOBALS['mw_test_titles'][ $post_id ], $GLOBALS['mw_test_meta'][ $post_id ] );
	$GLOBALS['mw_test_deleted'][] = $post_id;
	return $deleted;
}

function wp_count_posts( string $type = 'post' ): object {
	$counts = array();
	foreach ( $GLOBALS['mw_test_types'] as $post_id => $post_type ) {
		if ( $post_type !== $type ) {
			continue;
		}
		$status            = (string) get_post_status( (int) $post_id );
		$counts[ $status ] = ( isset( $counts[ $status ] ) ? $counts[ $status ] : 0 ) + 1;
	}
	return (object) $counts;
}

/** @param mixed $headers Headers. */
function wp_mail( string $to, string $subject, string $message, $headers = array() ): bool {
	$GLOBALS['mw_test_mail'][] = array(
		'to'      => $to,
		'subject' => $subject,
		'message' => $message,
		'headers' => $headers,
	);
	return empty( $GLOBALS['mw_test_mail_fails'] );
}

function is_email( string $email ) {
	return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
}

function sanitize_textarea_field( string $value ): string {
	return trim( strip_tags( $value ) );
}

function get_bloginfo( string $show = '' ): string {
	return 'admin_email' === $show ? 'owner@example.test' : 'MusicWave Test';
}

function wp_specialchars_decode( string $value, int $quote_style = ENT_NOQUOTES ): string {
	return htmlspecialchars_decode( $value, $quote_style );
}

function wp_generate_password( int $length = 12, bool $special = true, bool $extra = false ): string {
	unset( $special, $extra );
	return substr( bin2hex( random_bytes( 32 ) ), 0, $length );
}

function esc_textarea( string $value ): string {
	return htmlspecialchars( $value, ENT_QUOTES );
}

function checked( $checked, $current = true, bool $display = true ): string {
	$markup = (string) $checked === (string) $current ? ' checked="checked"' : '';
	if ( $display ) {
		echo $markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	return $markup;
}

function wp_enqueue_style( string $handle, string $src = '', array $deps = array(), $ver = false ): void {
	unset( $src, $deps, $ver );
	$GLOBALS['mw_test_styles'][] = $handle;
}

function current_user_can( string $capability, ...$arguments ): bool {
	unset( $arguments );
	$granted = isset( $GLOBALS['mw_test_capabilities'] ) && is_array( $GLOBALS['mw_test_capabilities'] ) ? $GLOBALS['mw_test_capabilities'] : array();
	return in_array( $capability, $granted, true );
}

function has_block( string $block_name, $post = null ): bool {
	unset( $block_name, $post );
	return ! empty( $GLOBALS['mw_test_has_block'] );
}

function get_site_icon_url( int $size = 512, string $url = '', int $blog_id = 0 ): string {
	unset( $size, $url, $blog_id );
	return isset( $GLOBALS['mw_test_site_icon'] ) && is_string( $GLOBALS['mw_test_site_icon'] ) ? $GLOBALS['mw_test_site_icon'] : '';
}

function wp_get_current_user() {
	return (object) array( 'ID' => isset( $GLOBALS['mw_test_current_user'] ) ? (int) $GLOBALS['mw_test_current_user'] : 0 );
}

function get_the_title( int $post_id ): string {
	if ( isset( $GLOBALS['mw_test_titles'][ $post_id ] ) ) {
		return $GLOBALS['mw_test_titles'][ $post_id ];
	}

	return '' !== get_post_type( $post_id ) ? 'Release ' . $post_id : '';
}

function get_permalink( int $post_id ) {
	return '' !== get_post_type( $post_id ) ? 'https://example.test/?p=' . $post_id : false;
}

function url_to_postid( string $url ): int {
	if ( preg_match( '/[?&]p=(\d+)/', $url, $matches ) || preg_match( '#/release/(\d+)/?#', $url, $matches ) ) {
		$id = (int) $matches[1];
		return '' !== get_post_type( $id ) ? $id : 0;
	}
	return 0;
}

function get_the_post_thumbnail( int $post_id, $size = 'post-thumbnail', $attributes = array() ): string {
	unset( $post_id, $size, $attributes );
	return '';
}

/** @return array<int, string> */
function wp_get_post_terms( int $post_id, string $taxonomy, array $arguments = array() ): array {
	unset( $arguments );
	if ( isset( $GLOBALS['mw_test_terms_by_tax'][ $post_id ][ $taxonomy ] ) ) {
		return $GLOBALS['mw_test_terms_by_tax'][ $post_id ][ $taxonomy ];
	}
	return isset( $GLOBALS['mw_test_release_types'][ $post_id ] ) ? $GLOBALS['mw_test_release_types'][ $post_id ] : array();
}

/**
 * Batched taxonomy lookup used by ReleaseTermIndex.
 *
 * @param array<int, int>          $object_ids Object IDs.
 * @param array<int, string>|string $taxonomies Taxonomies.
 * @param array<string, mixed>     $arguments  Query arguments.
 * @return array<int, WP_Term>
 */
function wp_get_object_terms( array $object_ids, $taxonomies, array $arguments = array() ) {
	unset( $arguments );
	++$GLOBALS['mw_test_term_queries']['object'];
	$taxonomies = is_array( $taxonomies ) ? $taxonomies : array( (string) $taxonomies );
	$terms      = array();

	foreach ( $object_ids as $object_id ) {
		$object_id = (int) $object_id;
		foreach ( $taxonomies as $taxonomy ) {
			$taxonomy = (string) $taxonomy;
			$values   = isset( $GLOBALS['mw_test_terms_by_tax'][ $object_id ][ $taxonomy ] )
				? $GLOBALS['mw_test_terms_by_tax'][ $object_id ][ $taxonomy ]
				: ( 'mw_release_type' === $taxonomy && isset( $GLOBALS['mw_test_release_types'][ $object_id ] ) ? $GLOBALS['mw_test_release_types'][ $object_id ] : array() );

			foreach ( is_array( $values ) ? $values : array() as $value ) {
				if ( $value instanceof WP_Term ) {
					$term            = clone $value;
					$term->object_id = $object_id;
					$terms[]         = $term;
					continue;
				}
				if ( ! is_scalar( $value ) || '' === (string) $value ) {
					continue;
				}
				$slug = sanitize_title( (string) $value );
				$key  = $taxonomy . '|' . $slug;
				if ( ! isset( $GLOBALS['mw_test_synth_terms'][ $key ] ) ) {
					$GLOBALS['mw_test_synth_terms'][ $key ] = 900 + count( $GLOBALS['mw_test_synth_terms'] );
				}
				$term            = new WP_Term( (int) $GLOBALS['mw_test_synth_terms'][ $key ], $taxonomy, (string) $value, $slug );
				$term->object_id = $object_id;
				$terms[]         = $term;
			}
		}
	}

	return $terms;
}

function get_post_modified_time( string $format = 'U', bool $gmt = false, $post_id = 0 ) {
	unset( $format, $gmt, $post_id );
	return '';
}

function get_the_post_thumbnail_url( int $post_id, $size = 'post-thumbnail' ) {
	unset( $size );
	if ( isset( $GLOBALS['mw_test_thumbnails'][ $post_id ] ) ) {
		return $GLOBALS['mw_test_thumbnails'][ $post_id ];
	}

	return false;
}

/**
 * @param mixed $output    Unused.
 * @param mixed $post_type Unused.
 * @return WP_Post|null
 */
function get_page_by_path( string $path, $output = null, $post_type = 'page' ) {
	unset( $output, $post_type );
	if ( isset( $GLOBALS['mw_test_pages_by_path'][ $path ] ) ) {
		return $GLOBALS['mw_test_pages_by_path'][ $path ];
	}

	return null;
}

function get_the_excerpt( int $post_id ): string {
	unset( $post_id );
	return '';
}

function esc_html( string $value ): string {
	return htmlspecialchars( $value, ENT_QUOTES );
}

function esc_attr( string $value ): string {
	return htmlspecialchars( $value, ENT_QUOTES );
}

function esc_url( string $url ): string {
	return $url;
}

function number_format_i18n( float $number, int $decimals = 0 ): string {
	return number_format( $number, absint( $decimals ) );
}

function sanitize_html_class( string $value ): string {
	return (string) preg_replace( '/[^A-Za-z0-9_-]/', '', $value );
}

function is_feed(): bool {
	return false;
}

function get_post_field( string $field, $post = null ): string {
	$resolved = get_post( $post );
	if ( is_object( $resolved ) && isset( $resolved->{$field} ) ) {
		return (string) $resolved->{$field};
	}
	return '';
}

function get_post( $post = null ) {
	if ( $post instanceof WP_Post ) {
		return $post;
	}
	if ( is_numeric( $post ) ) {
		$id   = (int) $post;
		$type = get_post_type( $id );
		if ( '' === $type ) {
			return null;
		}
		$obj               = new WP_Post( $id, $type );
		$obj->post_title   = isset( $GLOBALS['mw_test_titles'][ $id ] ) ? (string) $GLOBALS['mw_test_titles'][ $id ] : '';
		$obj->post_content = isset( $GLOBALS['mw_test_contents'][ $id ] ) ? (string) $GLOBALS['mw_test_contents'][ $id ] : '';
		$obj->post_excerpt = isset( $GLOBALS['mw_test_excerpts'][ $id ] ) ? (string) $GLOBALS['mw_test_excerpts'][ $id ] : '';
		$obj->post_status  = (string) get_post_status( $id );
		return $obj;
	}
	return isset( $GLOBALS['mw_test_post'] ) ? $GLOBALS['mw_test_post'] : null;
}

function untrailingslashit( string $value ): string {
	return rtrim( $value, '/\\' );
}

function user_trailingslashit( string $value, string $type_of_url = '' ): string {
	unset( $type_of_url );
	return untrailingslashit( $value ) . '/';
}

function get_the_terms( int $post_id, string $taxonomy ) {
	if ( isset( $GLOBALS['mw_test_term_objects'][ $post_id ][ $taxonomy ] ) ) {
		return $GLOBALS['mw_test_term_objects'][ $post_id ][ $taxonomy ];
	}
	$slugs = wp_get_post_terms( $post_id, $taxonomy );
	if ( array() === $slugs ) {
		return false;
	}
	$terms = array();
	foreach ( $slugs as $index => $slug ) {
		$terms[] = new WP_Term( 700 + $index, $taxonomy, (string) $slug, (string) $slug );
	}
	return $terms;
}

function add_query_arg( $args, $url_or_val = '', string $maybe_url = '' ): string {
	if ( is_string( $args ) ) {
		$url  = '' !== $maybe_url ? $maybe_url : '';
		$args = array( $args => $url_or_val );
	} else {
		$url  = (string) $url_or_val;
		$args = is_array( $args ) ? $args : array();
	}
	$pairs = array();
	foreach ( $args as $key => $value ) {
		$pairs[] = rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
	}
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . implode( '&', $pairs );
}

if ( ! function_exists( 'get_the_date' ) ) {
	function get_the_date( string $format = '', $post = null ): string {
		unset( $post );
		return '' !== $format ? gmdate( $format, 1700000000 ) : '2026-10-22';
	}
}

function get_transient( string $key ) {
	return isset( $GLOBALS['mw_test_transients'][ $key ] ) ? $GLOBALS['mw_test_transients'][ $key ] : false;
}

function set_transient( string $key, $value, int $expiration = 0 ): bool {
	unset( $expiration );
	$GLOBALS['mw_test_transients'][ $key ] = $value;
	return true;
}

function get_user_by( string $field, $value ) {
	foreach ( $GLOBALS['mw_test_users'] as $user_id => $user ) {
		if ( ( 'id' === $field || 'ID' === $field ) && (int) $user_id === (int) $value ) {
			$found     = clone $user;
			$found->ID = (int) $user_id;
			return $found;
		}
		if ( 'email' === $field && isset( $user->user_email ) && $user->user_email === (string) $value ) {
			$found     = clone $user;
			$found->ID = (int) $user_id;
			return $found;
		}
	}
	return false;
}

/** @return array<int, int> */
function get_posts( array $arguments = array() ) {
	$type = isset( $arguments['post_type'] ) ? (string) $arguments['post_type'] : 'post';
	$ids  = array();
	foreach ( $GLOBALS['mw_test_types'] as $post_id => $post_type ) {
		if ( $post_type === $type ) {
			$ids[] = (int) $post_id;
		}
	}
	return $ids;
}

/**
 * Post-cache priming is a query optimisation. The doubles below read their
 * fixtures straight from globals, so there is nothing to warm.
 *
 * @param array<int, int> $ids               Post IDs.
 * @param bool            $update_term_cache Whether the caller wants terms.
 * @param bool            $update_meta_cache Whether the caller wants meta.
 * @return void
 */
function _prime_post_caches( $ids, $update_term_cache = true, $update_meta_cache = true ) {
	unset( $ids, $update_term_cache, $update_meta_cache );
}

function get_term_link( $term ) {
	return $term instanceof WP_Term ? 'https://example.test/artist/' . $term->term_id : new WP_Error( 'invalid_term', 'Invalid term.' );
}

if ( ! function_exists( 'get_post_type_archive_link' ) ) {
	function get_post_type_archive_link( $post_type ) {
		return '' === (string) $post_type ? false : 'https://example.test/releases/';
	}
}

function wp_get_attachment_image( int $attachment_id, $size = 'thumbnail', bool $icon = false, $attributes = array() ): string {
	unset( $attachment_id, $size, $icon, $attributes );
	return '';
}

function get_current_user_id(): int {
	return isset( $GLOBALS['mw_test_current_user'] ) ? (int) $GLOBALS['mw_test_current_user'] : 0;
}

function user_can( int $user_id, string $capability, ...$arguments ): bool {
	unset( $arguments );
	return $user_id > 0 && current_user_can( $capability );
}

function taxonomy_exists( string $taxonomy ): bool {
	return in_array( $taxonomy, array( 'mw_artist', 'mw_genre', 'mw_label', 'mw_mood', 'mw_release_type' ), true );
}

function get_term( int $term_id, string $taxonomy = '' ) {
	$term = isset( $GLOBALS['mw_test_terms'][ $term_id ] ) ? $GLOBALS['mw_test_terms'][ $term_id ] : null;
	return $term instanceof WP_Term && ( '' === $taxonomy || $term->taxonomy === $taxonomy ) ? $term : null;
}

function get_term_by( string $field, string $value, string $taxonomy ) {
	foreach ( $GLOBALS['mw_test_terms'] as $term ) {
		if ( $term instanceof WP_Term && $term->taxonomy === $taxonomy && isset( $term->{$field} ) && (string) $term->{$field} === $value ) {
			return $term;
		}
	}
	return false;
}

function get_queried_object() {
	return isset( $GLOBALS['mw_test_queried_object'] ) ? $GLOBALS['mw_test_queried_object'] : null;
}

function get_terms( array $arguments = array() ) {
	$taxonomy = isset( $arguments['taxonomy'] ) ? (string) $arguments['taxonomy'] : '';
	$include  = isset( $arguments['include'] ) && is_array( $arguments['include'] ) ? array_map( 'absint', $arguments['include'] ) : array();
	$terms    = array_values(
		array_filter(
			$GLOBALS['mw_test_terms'],
			static function ( $term ) use ( $taxonomy, $include ): bool {
				return $term instanceof WP_Term
					&& ( '' === $taxonomy || $term->taxonomy === $taxonomy )
					&& ( empty( $include ) || in_array( $term->term_id, $include, true ) );
			}
		)
	);
	if ( isset( $arguments['meta_key'], $arguments['meta_value'] ) ) {
		$terms = array_values(
			array_filter(
				$terms,
				static function ( WP_Term $term ) use ( $arguments ): bool {
					return get_term_meta( $term->term_id, (string) $arguments['meta_key'], true ) === $arguments['meta_value'];
				}
			)
		);
	}
	if ( ! empty( $include ) && isset( $arguments['orderby'] ) && 'include' === $arguments['orderby'] ) {
		$by_id = array();
		foreach ( $terms as $term ) {
			$by_id[ $term->term_id ] = $term;
		}
		$ordered = array();
		foreach ( $include as $included_id ) {
			if ( isset( $by_id[ $included_id ] ) ) {
				$ordered[] = $by_id[ $included_id ];
			}
		}
		$terms = $ordered;
	}
	if ( isset( $arguments['offset'] ) ) {
		$terms = array_slice( $terms, max( 0, (int) $arguments['offset'] ) );
	}
	if ( isset( $arguments['number'] ) ) {
		$terms = array_slice( $terms, 0, (int) $arguments['number'] );
	}
	if ( isset( $arguments['fields'] ) && 'names' === $arguments['fields'] ) {
		return array_map(
			static function ( WP_Term $term ): string {
				return $term->name;
			},
			$terms
		);
	}
	return $terms;
}

function wp_count_terms( $args = array(), string $deprecated = '' ) {
	unset( $deprecated );
	$taxonomy = is_array( $args ) ? (string) ( $args['taxonomy'] ?? '' ) : (string) $args;
	$count    = 0;
	foreach ( $GLOBALS['mw_test_terms'] as $term ) {
		if ( $term instanceof WP_Term && ( '' === $taxonomy || $term->taxonomy === $taxonomy ) ) {
			++$count;
		}
	}
	return $count;
}

function wp_insert_term( string $name, string $taxonomy, array $arguments = array() ) {
	$term_id                              = empty( $GLOBALS['mw_test_terms'] ) ? 100 : max( array_keys( $GLOBALS['mw_test_terms'] ) ) + 1;
	$slug                                 = isset( $arguments['slug'] ) ? (string) $arguments['slug'] : sanitize_title( $name );
	$GLOBALS['mw_test_terms'][ $term_id ] = new WP_Term( $term_id, $taxonomy, $name, $slug );
	return array(
		'term_id'          => $term_id,
		'term_taxonomy_id' => $term_id,
	);
}

function wp_set_object_terms( int $post_id, array $term_ids, string $taxonomy, bool $append = false ) {
	unset( $append );
	$GLOBALS['mw_test_object_terms'][ $post_id ][ $taxonomy ] = array_values( array_map( 'absint', $term_ids ) );
	return $GLOBALS['mw_test_object_terms'][ $post_id ][ $taxonomy ];
}

function get_term_meta( int $term_id, string $key, bool $single = false ) {
	unset( $single );
	return isset( $GLOBALS['mw_test_term_meta'][ $term_id ][ $key ] ) ? $GLOBALS['mw_test_term_meta'][ $term_id ][ $key ] : '';
}

function update_term_meta( int $term_id, string $key, $value ) {
	$GLOBALS['mw_test_term_meta'][ $term_id ][ $key ] = $value;
	return true;
}

function register_term_meta( string $taxonomy, string $key, array $arguments ): bool {
	unset( $taxonomy, $key, $arguments );
	return true;
}

function get_post_meta( int $post_id, string $key, bool $single = false ) {
	unset( $single );
	return isset( $GLOBALS['mw_test_meta'][ $post_id ][ $key ] ) ? $GLOBALS['mw_test_meta'][ $post_id ][ $key ] : '';
}

function update_post_meta( int $post_id, string $key, $value ) {
	$GLOBALS['mw_test_meta'][ $post_id ][ $key ] = $value;
	return true;
}

function delete_post_meta( int $post_id, string $key ): bool {
	unset( $GLOBALS['mw_test_meta'][ $post_id ][ $key ] );
	return true;
}

function metadata_exists( string $meta_type, int $object_id, string $meta_key ): bool {
	unset( $meta_type );
	return array_key_exists( $meta_key, isset( $GLOBALS['mw_test_meta'][ $object_id ] ) ? $GLOBALS['mw_test_meta'][ $object_id ] : array() );
}

function get_option( string $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['mw_test_options'] ) ? $GLOBALS['mw_test_options'][ $key ] : $default;
}

function add_option( string $key, $value, string $deprecated = '', bool $autoload = true ): bool {
	unset( $deprecated, $autoload );
	if ( array_key_exists( $key, $GLOBALS['mw_test_options'] ) ) {
		return false;
	}
	$GLOBALS['mw_test_options'][ $key ] = $value;
	return true;
}

function update_option( string $key, $value, $autoload = null ): bool {
	unset( $autoload );
	$GLOBALS['mw_test_options'][ $key ] = $value;
	return true;
}

function flush_rewrite_rules( bool $hard = true ): void {
	$GLOBALS['mw_test_rewrite_flushes'][] = $hard;
}

function delete_option( string $key ): bool {
	unset( $GLOBALS['mw_test_options'][ $key ] );
	return true;
}

function get_userdata( int $user_id ) {
	return isset( $GLOBALS['mw_test_users'][ $user_id ] ) ? $GLOBALS['mw_test_users'][ $user_id ] : false;
}

function get_user_meta( int $user_id, string $key, bool $single = false ) {
	unset( $single );
	return isset( $GLOBALS['mw_test_user_meta'][ $user_id ][ $key ] ) ? $GLOBALS['mw_test_user_meta'][ $user_id ][ $key ] : '';
}

function update_user_meta( int $user_id, string $key, $value ) {
	if ( isset( $GLOBALS['mw_test_meta_write_hook'] ) && is_callable( $GLOBALS['mw_test_meta_write_hook'] ) ) {
		$hooked = call_user_func( $GLOBALS['mw_test_meta_write_hook'], 'update', $user_id, $key, $value );
		if ( null !== $hooked ) {
			return (bool) $hooked;
		}
	}
	$GLOBALS['mw_test_user_meta'][ $user_id ][ $key ] = $value;
	return true;
}

function add_user_meta( int $user_id, string $key, $value, bool $unique = false ): bool {
	if ( isset( $GLOBALS['mw_test_meta_write_hook'] ) && is_callable( $GLOBALS['mw_test_meta_write_hook'] ) ) {
		$hooked = call_user_func( $GLOBALS['mw_test_meta_write_hook'], 'add', $user_id, $key, $value );
		if ( null !== $hooked ) {
			return (bool) $hooked;
		}
	}
	if ( $unique && isset( $GLOBALS['mw_test_user_meta'][ $user_id ][ $key ] ) ) {
		return false;
	}
	$GLOBALS['mw_test_user_meta'][ $user_id ][ $key ] = $value;
	return true;
}

function delete_user_meta( int $user_id, string $key ): bool {
	unset( $GLOBALS['mw_test_user_meta'][ $user_id ][ $key ] );
	return true;
}

function wc_customer_bought_product( string $email, int $user_id, int $product_id ): bool {
	unset( $email );
	$key = $user_id . ':' . $product_id;
	return ! empty( $GLOBALS['mw_test_purchases'][ $key ] );
}

final class TestWcOrderItem {
	/** @var int */
	private $product_id;

	/** @var int */
	private $variation_id;

	public function __construct( int $product_id, int $variation_id = 0 ) {
		$this->product_id   = $product_id;
		$this->variation_id = $variation_id;
	}

	public function get_product_id(): int {
		return $this->product_id;
	}

	public function get_variation_id(): int {
		return $this->variation_id;
	}
}

final class TestWcOrder {
	/** @var int */
	private $customer_id;

	/** @var array<int, TestWcOrderItem> */
	private $items;

	/**
	 * @param array<int, TestWcOrderItem> $items Order line items.
	 */
	public function __construct( int $customer_id, array $items ) {
		$this->customer_id = $customer_id;
		$this->items       = $items;
	}

	public function get_customer_id(): int {
		return $this->customer_id;
	}

	/** @return array<int, TestWcOrderItem> */
	public function get_items(): array {
		return $this->items;
	}
}

function wc_get_order( int $order_id ) {
	return isset( $GLOBALS['mw_test_orders'][ $order_id ] ) ? $GLOBALS['mw_test_orders'][ $order_id ] : false;
}

require dirname( __DIR__ ) . '/music-wave-core/src/Support/Autoloader.php';
ManaCore\MusicWave\Core\Support\Autoloader::register();

final class TestReleaseRepository implements ManaCore\MusicWave\Core\Contracts\ReleaseRepository {
	/** @var array<int, int> */
	public $products = array();

	public function get( int $release_id, string $key ) {
		unset( $release_id, $key );
		return null;
	}

	public function update( int $release_id, string $key, $value ): bool {
		unset( $release_id, $key, $value );
		return true;
	}

	public function delete( int $release_id, string $key ): bool {
		unset( $release_id, $key );
		return true;
	}

	public function product_ids( int $release_id ): array {
		unset( $release_id );
		return $this->products;
	}

	public function collection_ids( int $release_id ): array {
		unset( $release_id );
		return array();
	}

	public function collection_items( int $collection_id ): array {
		unset( $collection_id );
		return array();
	}

	public function replace_collection_items( int $collection_id, array $items ): bool {
		unset( $collection_id, $items );
		return true;
	}
}

final class TestPolicyRepository implements ManaCore\MusicWave\Core\Contracts\ReleaseRepository {
	/** @var array<string, mixed> */
	public $values = array(
		'mw_access_mode'       => 'public',
		'mw_membership_levels' => array(),
	);

	public function get( int $release_id, string $key ) {
		unset( $release_id );
		return isset( $this->values[ $key ] ) ? $this->values[ $key ] : null;
	}

	public function update( int $release_id, string $key, $value ): bool {
		unset( $release_id );
		$this->values[ $key ] = $value;
		return true;
	}

	public function delete( int $release_id, string $key ): bool {
		unset( $release_id, $key );
		return true;
	}

	/** @return array<int, int> */
	public function product_ids( int $release_id ): array {
		unset( $release_id );
		return isset( $this->values['mw_product_ids'] ) ? $this->values['mw_product_ids'] : array( 10 );
	}

	/** @return array<int, int> */
	public function collection_ids( int $release_id ): array {
		unset( $release_id );
		return array();
	}

	/** @return array<int, array<string, int|string|null>> */
	public function collection_items( int $collection_id ): array {
		unset( $collection_id );
		return isset( $this->values['mw_collection_items'] ) && is_array( $this->values['mw_collection_items'] ) ? $this->values['mw_collection_items'] : array();
	}

	public function replace_collection_items( int $collection_id, array $items ): bool {
		$this->values['mw_collection_items'] = $items;
		unset( $collection_id );
		return true;
	}
}

final class TestMembershipProvider implements ManaCore\MusicWave\Core\Access\MembershipProvider {
	/** @var bool */
	public $granted = false;

	/** @param array<int, string> $levels */
	public function has_access( int $user_id, array $levels ): bool {
		return $this->granted && $user_id > 0 && ! empty( $levels );
	}
}

final class TestCollectionRepository implements ManaCore\MusicWave\Core\Contracts\ReleaseRepository {
	/** @var array<int, array<string, int|string|null>> */
	public $items = array();

	public function get( int $release_id, string $key ) {
		unset( $release_id, $key );
		return null;
	}

	public function update( int $release_id, string $key, $value ): bool {
		unset( $release_id, $key, $value );
		return true;
	}

	public function delete( int $release_id, string $key ): bool {
		unset( $release_id, $key );
		return true;
	}

	/** @return array<int, int> */
	public function product_ids( int $release_id ): array {
		unset( $release_id );
		return array();
	}

	/** @return array<int, int> */
	public function collection_ids( int $release_id ): array {
		unset( $release_id );
		return array();
	}

	/** @return array<int, array<string, int|string|null>> */
	public function collection_items( int $collection_id ): array {
		unset( $collection_id );
		return $this->items;
	}

	public function replace_collection_items( int $collection_id, array $items ): bool {
		unset( $collection_id );
		$this->items = $items;
		return true;
	}
}

final class TestRestResponse {
	/** @var mixed */
	private $data;

	/** @param mixed $data Initial response payload. */
	public function __construct( $data ) {
		$this->data = $data;
	}

	/** @return mixed */
	public function get_data() {
		return $this->data;
	}

	/** @param mixed $data Replacement payload. */
	public function set_data( $data ): void {
		$this->data = $data;
	}
}

final class TestDownloadProvider implements ManaCore\MusicWave\Core\Downloads\DownloadProvider {
	/** @var array<int, string> */
	public $delivered = array();

	public function deliver( string $asset_id, ManaCore\MusicWave\Core\Downloads\DownloadTokenClaims $claims ): bool {
		unset( $claims );
		$this->delivered[] = $asset_id;
		return true;
	}
}

final class TestCatalogSearchAdapter implements ManaCore\MusicWave\Core\Discovery\CatalogSearchAdapter {
	/** @var array<int, int>|null */
	public $proposed = null;

	/** @var array<int, string> */
	public $terms = array();

	/** @return array<int, int>|null */
	public function search( string $term, int $limit ): ?array {
		unset( $limit );
		$this->terms[] = $term;

		return $this->proposed;
	}
}

final class TestPlaylistStore implements ManaCore\MusicWave\Core\Playlists\PlaylistStore {
	/** @var array<int, array<string, mixed>> */
	public $rows = array();

	/** @var array<int, array<int, array{release_id: int, position: int, added_at: int}>> */
	public $stored_items = array();

	/** @var int */
	private $next_id = 1;

	public function available(): bool {
		return true;
	}

	/** @return array<string, mixed>|null */
	public function find( int $playlist_id ): ?array {
		return isset( $this->rows[ $playlist_id ] ) ? $this->rows[ $playlist_id ] : null;
	}

	/** @return array<string, mixed>|null */
	public function find_by_share_token( string $share_token ): ?array {
		foreach ( $this->rows as $row ) {
			if ( '' !== $share_token && isset( $row['share_token'] ) && (string) $row['share_token'] === $share_token ) {
				return $row;
			}
		}

		return null;
	}

	/** @return array<int, array<string, mixed>> */
	public function for_user( int $user_id, int $limit = 50, int $offset = 0 ): array {
		$rows = array();
		foreach ( $this->rows as $row ) {
			if ( (int) $row['user_id'] === $user_id ) {
				$rows[] = $row;
			}
		}

		return array_slice( $rows, $offset, $limit );
	}

	public function count_for_user( int $user_id ): int {
		return count( $this->for_user( $user_id, 1000 ) );
	}

	/** @param array<string, mixed> $row Row values. */
	public function insert( array $row ): int {
		$id                = $this->next_id;
		++$this->next_id;
		$row['id']         = $id;
		$this->rows[ $id ] = $row;

		return $id;
	}

	/** @param array<string, mixed> $fields Column values. */
	public function update( int $playlist_id, array $fields ): bool {
		if ( ! isset( $this->rows[ $playlist_id ] ) ) {
			return false;
		}
		foreach ( $fields as $column => $value ) {
			$this->rows[ $playlist_id ][ $column ] = $value;
		}

		return true;
	}

	public function delete( int $playlist_id ): bool {
		if ( ! isset( $this->rows[ $playlist_id ] ) ) {
			return false;
		}
		unset( $this->rows[ $playlist_id ], $this->stored_items[ $playlist_id ] );

		return true;
	}

	/** @return array<int, array{release_id: int, position: int, added_at: int}> */
	public function items( int $playlist_id ): array {
		return isset( $this->stored_items[ $playlist_id ] ) ? $this->stored_items[ $playlist_id ] : array();
	}

	/** @param array<int, int> $release_ids Ordered release IDs. */
	public function replace_items( int $playlist_id, array $release_ids ): bool {
		$items    = array();
		$position = 0;
		foreach ( $release_ids as $release_id ) {
			$items[] = array(
				'release_id' => (int) $release_id,
				'position'   => $position,
				'added_at'   => 1000 + $position,
			);
			++$position;
		}
		$this->stored_items[ $playlist_id ] = $items;

		return true;
	}

	public function delete_for_user( int $user_id ): bool {
		$deleted = false;
		foreach ( $this->for_user( $user_id, 1000 ) as $row ) {
			$deleted = $this->delete( (int) $row['id'] ) || $deleted;
		}

		return $deleted;
	}

	public function purge_release( int $release_id ): void {
		foreach ( array_keys( $this->stored_items ) as $playlist_id ) {
			$kept = array();
			foreach ( $this->stored_items[ $playlist_id ] as $item ) {
				if ( (int) $item['release_id'] !== $release_id ) {
					$kept[] = (int) $item['release_id'];
				}
			}
			$this->replace_items( (int) $playlist_id, $kept );
		}
	}

	/** @return array<int, array<string, mixed>> */
	public function public_playlists( int $limit = 24, int $offset = 0, string $search = '', string $orderby = 'updated_at' ): array {
		$rows = array();
		foreach ( $this->rows as $row ) {
			if ( 'public' !== ( $row['visibility'] ?? '' ) ) {
				continue;
			}
			if ( '' !== $search && false === stripos( (string) ( $row['title'] ?? '' ), $search ) ) {
				continue;
			}
			$rows[] = $row;
		}
		if ( 'title' === $orderby ) {
			usort( $rows, static function ( $a, $b ): int { return strcmp( (string) ( $a['title'] ?? '' ), (string) ( $b['title'] ?? '' ) ); } );
		} elseif ( 'created_at' === $orderby ) {
			usort( $rows, static function ( $a, $b ): int { return (int) ( $b['created_at'] ?? 0 ) <=> (int) ( $a['created_at'] ?? 0 ); } );
		} else {
			usort( $rows, static function ( $a, $b ): int { return (int) ( $b['updated_at'] ?? 0 ) <=> (int) ( $a['updated_at'] ?? 0 ); } );
		}
		return array_slice( $rows, $offset, $limit );
	}

	public function count_public( string $search = '' ): int {
		return count( $this->public_playlists( 1000, 0, $search ) );
	}

	/** @var array<int, array<int, int>> Recorded item_counts_for() calls. */
	public $count_calls = array();

	/**
	 * Grouped item counts, mirroring the SQL COUNT(DISTINCT release_id)
	 * semantics the database store uses (review finding P4).
	 *
	 * @param array<int, int> $playlist_ids Playlist IDs.
	 * @return array<int, int>
	 */
	public function item_counts_for( array $playlist_ids ): array {
		$ids                = array_map( 'absint', array_values( $playlist_ids ) );
		$this->count_calls[] = $ids;
		$counts              = array();
		foreach ( $this->stored_items as $playlist_id => $items ) {
			if ( in_array( (int) $playlist_id, $ids, true ) ) {
				$counts[ (int) $playlist_id ] = count( array_unique( array_column( $items, 'release_id' ) ) );
			}
		}

		return $counts;
	}
}

final class TestMigration implements ManaCore\MusicWave\Core\Contracts\Migration {
	/** @var string */
	private $version;

	public function __construct( string $version ) {
		$this->version = $version;
	}

	public function version(): string {
		return $this->version;
	}

	public function up(): void {}
}

/**
 * Fail the current suite loudly when an expectation does not hold.
 *
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @param string $message  Human-readable contract description.
 */
function mw_assert_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
		fwrite( STDERR, 'Expected: ' . var_export( $expected, true ) . PHP_EOL );
		fwrite( STDERR, 'Actual: ' . var_export( $actual, true ) . PHP_EOL );
		exit( 1 );
	}
}

if ( ! function_exists( 'wp_logout_url' ) ) {
	/**
	 * Logout URL used by account surfaces.
	 *
	 * @param string $redirect Optional redirect target.
	 */
	function wp_logout_url( string $redirect = '' ): string {
		unset( $redirect );

		return 'https://example.test/logout';
	}
}

if ( ! function_exists( 'mw_test_load_plugins' ) ) {
	/**
	 * Load the Core, VIP and Theme runtime exactly once for a suite.
	 *
	 * Suites that exercise real services instead of static files call this
	 * instead of hand-rolling their own includes, so they all see the same
	 * boot order the plugin and theme use in production.
	 */
	function mw_test_load_plugins(): void {
		$root = dirname( __DIR__ );
		require_once $root . '/music-wave-core/music-wave-core.php';
		require_once $root . '/music-wave-vip/src/Autoloader.php';
		\ManaCore\MusicWave\Vip\Autoloader::register();
		require_once $root . '/musicwave/src/Autoloader.php';
		\ManaCore\MusicWave\Theme\Autoloader::register( $root . '/musicwave/src' );
	}
}

// WordPress time/size constants the runtime reads directly.
foreach ( array(
	'MINUTE_IN_SECONDS' => 60,
	'HOUR_IN_SECONDS'   => 3600,
	'DAY_IN_SECONDS'    => 86400,
	'WEEK_IN_SECONDS'   => 604800,
	'MONTH_IN_SECONDS'  => 2592000,
	'YEAR_IN_SECONDS'   => 31536000,
	'KB_IN_BYTES'       => 1024,
	'MB_IN_BYTES'       => 1048576,
	'GB_IN_BYTES'       => 1073741824,
) as $mw_test_const_name => $mw_test_const_value ) {
	if ( ! defined( $mw_test_const_name ) ) {
		define( $mw_test_const_name, $mw_test_const_value );
	}
}
unset( $mw_test_const_name, $mw_test_const_value );

if ( ! function_exists( 'wp_is_writable' ) ) {
	/**
	 * Filesystem writability check used by the protected-asset resolver.
	 *
	 * @param string $path Absolute path.
	 */
	function wp_is_writable( string $path ): bool {
		return is_dir( $path ) ? is_writable( $path ) : ( file_exists( $path ) && is_writable( $path ) );
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	/**
	 * Recursive directory creation.
	 *
	 * @param string $target Absolute path.
	 */
	function wp_mkdir_p( string $target ): bool {
		return is_dir( $target ) || mkdir( $target, 0777, true );
	}
}

if ( ! function_exists( 'wp_normalize_path' ) ) {
	/**
	 * Normalize directory separators.
	 *
	 * @param string $path Path.
	 */
	function wp_normalize_path( string $path ): string {
		return str_replace( '\\', '/', $path );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	/**
	 * Append a trailing slash.
	 *
	 * @param string $value Value.
	 */
	function trailingslashit( string $value ): string {
		return rtrim( $value, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'wp_upload_dir' ) ) {
	/**
	 * Upload directory record for the test sandbox.
	 *
	 * @return array<string, string|bool>
	 */
	function wp_upload_dir(): array {
		$base = sys_get_temp_dir() . '/musicwave-test-uploads';

		return array(
			'path'    => $base,
			'url'     => 'https://example.test/uploads',
			'subdir'  => '',
			'basedir' => $base,
			'baseurl' => 'https://example.test/uploads',
			'error'   => false,
		);
	}
}

if ( ! function_exists( 'wp_get_upload_dir' ) ) {
	/**
	 * Cached upload directory record.
	 *
	 * @return array<string, string|bool>
	 */
	function wp_get_upload_dir(): array {
		return wp_upload_dir();
	}
}

if ( ! function_exists( 'wp_tempnam' ) ) {
	/**
	 * Create a temporary file name.
	 *
	 * @param string $filename Optional base name.
	 */
	function wp_tempnam( string $filename = '' ): string {
		$path = tempnam( sys_get_temp_dir(), 'mw' );
		unset( $filename );

		return false === $path ? '' : $path;
	}
}

if ( ! function_exists( 'size_format' ) ) {
	/**
	 * Human readable byte size.
	 *
	 * @param int|float $bytes     Bytes.
	 * @param int       $decimals  Decimals.
	 */
	function size_format( $bytes, int $decimals = 0 ): string {
		$bytes = (float) $bytes;
		if ( $bytes >= GB_IN_BYTES ) {
			return number_format_i18n( $bytes / GB_IN_BYTES, $decimals ) . ' GB';
		}
		if ( $bytes >= MB_IN_BYTES ) {
			return number_format_i18n( $bytes / MB_IN_BYTES, $decimals ) . ' MB';
		}
		if ( $bytes >= KB_IN_BYTES ) {
			return number_format_i18n( $bytes / KB_IN_BYTES, $decimals ) . ' KB';
		}

		return number_format_i18n( $bytes, $decimals ) . ' bytes';
	}
}
