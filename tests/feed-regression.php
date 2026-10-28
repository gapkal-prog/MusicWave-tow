<?php
/**
 * Feed regression test for finding S1 (restricted release body in /feed/).
 *
 * Run: php tests/feed-regression.php
 *
 * Asserts the exact contract the review requires, in three layers:
 *
 *  1. Static scan: both `the_content_feed` and `the_excerpt_rss` carry a gate,
 *     and neither is wired to the web callback (S1 closed, N2 respected).
 *  2. Web contract (unchanged behaviour must keep passing): the canonical
 *     `ReleaseBlocks::filter_content()` gate replaces a denied body with the
 *     access panel on the frontend, leaves public bodies untouched, and
 *     leaves admin contexts untouched.
 *  3. Feed contract: `ReleaseBlocks::filter_feed_content()` re-evaluates the
 *     policy for the feed viewer and strips denied bodies and excerpts, while
 *     `filter_content()` keeps its `is_feed()` early-return. The two callbacks
 *     must stay separate — that early-return is exactly why reusing the web
 *     callback on the feed hooks would be a silent no-op (N2).
 *
 * @package MusicWave\Tests
 */

declare(strict_types=1);

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', dirname( __DIR__ ) . '/music-wave-core/' );
	}
	if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
		define( 'MINUTE_IN_SECONDS', 60 );
	}
	if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
		define( 'HOUR_IN_SECONDS', 3600 );
	}

	$GLOBALS['mw_feed_test_actions']  = array();
	$GLOBALS['mw_feed_test_filters']  = array();
	$GLOBALS['mw_feed_test_is_feed']  = false;
	$GLOBALS['mw_feed_test_is_admin'] = false;
	$GLOBALS['mw_feed_test_rest']     = false;
	$GLOBALS['mw_feed_test_post']     = null;
	$GLOBALS['mw_feed_test_user_id']  = 0;
	$GLOBALS['mw_feed_test_options']  = array();

	function add_action( string $hook, $callback, int $priority = 10, int $args = 1 ): void {
		$GLOBALS['mw_feed_test_actions'][ $hook ][] = array( $callback, $priority );
	}
	function add_filter( string $hook, $callback, int $priority = 10, int $args = 1 ): bool {
		$GLOBALS['mw_feed_test_filters'][ $hook ][] = array( $callback, $priority );
		return true;
	}
	function apply_filters( string $hook, $value, ...$rest ) {
		// The engine under test is the decision itself; every other hook
		// (membership_absent_behavior, purchase_owns_release, …) also passes
		// through untouched because no test callback is registered.
		if ( 'music_wave_access_decision' === $hook || 'music_wave_membership_absent_behavior' === $hook ) {
			return $value;
		}
		foreach ( $GLOBALS['mw_feed_test_filters'][ $hook ] ?? array() as $entry ) {
			$value = call_user_func( $entry[0], $value, ...$rest );
		}
		return $value;
	}
	function is_feed( string $feed = '' ): bool {
		return '' === $feed ? (bool) $GLOBALS['mw_feed_test_is_feed'] : false;
	}
	function is_admin(): bool {
		return (bool) $GLOBALS['mw_feed_test_is_admin'];
	}
	function get_post() {
		return $GLOBALS['mw_feed_test_post'];
	}
	function get_block_wrapper_attributes( array $args = array() ): string {
		return 'class="' . esc_attr( $args['class'] ?? '' ) . '"';
	}
	function get_current_user_id(): int {
		return (int) $GLOBALS['mw_feed_test_user_id'];
	}
	function is_user_logged_in(): bool {
		return (int) $GLOBALS['mw_feed_test_user_id'] > 0;
	}
	function get_userdata( int $user_id ) {
		return false;
	}
	function user_can( int $user_id, string $cap ): bool {
		return false;
	}
	function get_post_type( $post = null ) {
		// Numeric IDs resolve against the simulated "database": only the
		// current release (42) and its purchase CTA product (10) exist.
		if ( is_numeric( $post ) ) {
			$map = array( 42 => 'mw_release', 10 => 'product' );
			return $map[ (int) $post ] ?? false;
		}
		$post = $post ?? $GLOBALS['mw_feed_test_post'];
		return is_object( $post ) ? $post->post_type : false;
	}
	function sanitize_key( string $key ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
	}
	function sanitize_text_field( string $value ): string {
		return trim( preg_replace( '/[\r\n\t ]+/', ' ', $value ) );
	}
	function sanitize_html_class( string $class ): string {
		// Matches WordPress: underscores are legal in class names, so decision
		// reasons like `purchase_required` survive intact.
		return (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', $class );
	}
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8', false );
	}
	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8', false );
	}
	function esc_url( string $url ): string {
		return $url;
	}
	function esc_html__( string $text, string $domain = '' ): string {
		return $text;
	}
	function esc_attr__( string $text, string $domain = '' ): string {
		return $text;
	}
	function __( string $text, string $domain = '' ): string {
		return $text;
	}
	function wp_parse_url( string $url, int $component = -1 ) {
		return parse_url( $url, $component );
	}
	function absint( $value ): int {
		return abs( (int) $value );
	}
	function wp_trim_words( string $text, int $num_words = 55, string $more = '…' ): string {
		return $text;
	}
	function get_term_link( $term ) {
		return '';
	}
	function is_wp_error( $thing ): bool {
		return false;
	}
	function wp_get_post_terms( int $id, string $tax, array $args = array() ): array {
		return array();
	}
	function get_permalink( $post = null ) {
		$id = is_object( $post ) ? $post->ID : (int) $post;
		return 'https://musicwave.test/release/' . ( $id > 0 ? $id : 42 );
	}
	function get_the_title( $post = null ): string {
		return 'Feed Gate Probe';
	}
	function get_post_class( ...$args ) {
		return array();
	}
	function get_the_excerpt( $post = null ): string {
		return '';
	}
	function get_the_date( $format = '', $post = null ): string {
		return '';
	}
	function get_post_types( $args = array(), $output = 'names' ): array {
		return array( 'mw_release' );
	}
	function get_posts( array $args = array() ): array {
		return array();
	}
	function get_terms( array $args = array() ): array {
		return array();
	}
	function is_singular( $types = '' ): bool {
		return false;
	}
	function wp_script_is( string $handle, string $status = '' ): bool {
		return true; // Prevent enqueue side effects in the gate path.
	}
	function wp_enqueue_script( ...$args ): void {
	}
	function wp_localize_script( ...$args ): void {
	}
	function get_avatar( ...$args ): string {
		return '';
	}
	function get_post_modified_time( ...$args ) {
		return 0;
	}
	function get_the_ID() {
		$post = $GLOBALS['mw_feed_test_post'];
		return is_object( $post ) ? (int) $post->ID : 0;
	}

	// Core options store for Support\Settings.
	function get_option( string $name, $default = false ) {
		return $GLOBALS['mw_feed_test_options'][ $name ] ?? $default;
	}
	function add_option( string $name, $value ): bool {
		$GLOBALS['mw_feed_test_options'][ $name ] = $value;
		return true;
	}
	function update_option( string $name, $value ): bool {
		$GLOBALS['mw_feed_test_options'][ $name ] = $value;
		return true;
	}

	// Core autoloader: everything under src/ resolves by PSR-4.
	require_once __DIR__ . '/../music-wave-core/src/Support/Autoloader.php';
	\ManaCore\MusicWave\Core\Support\Autoloader::register();

	use ManaCore\MusicWave\Core\Access\AccessPolicyEngine;
	use ManaCore\MusicWave\Core\Blocks\ReleaseBlocks;
	use ManaCore\MusicWave\Core\Commerce\PurchaseChecker;
	use ManaCore\MusicWave\Core\Contracts\ReleaseRepository;

	$mw_feed_failures = array();
	$mw_feed_checks   = 0;
	function mw_feed_assert_same( $expected, $actual, string $message ): void {
		global $mw_feed_failures, $mw_feed_checks;
		++$mw_feed_checks;
		if ( $expected !== $actual ) {
			$mw_feed_failures[] = sprintf( '[FAIL] %s — expected %s, got %s', $message, var_export( $expected, true ), var_export( $actual, true ) );
		}
	}

	// ---------------------------------------------------------------------
	// 1. Static: both feed hooks carry a gate (S1 fixed — keep it that way).
	// ---------------------------------------------------------------------

	// Scanning only the three shipped packages keeps vendor/ and node_modules/
	// out of the walk.
	$feed_gate_hits = array();
	foreach ( array( 'music-wave-core', 'music-wave-vip', 'musicwave' ) as $package ) {
		$package_root = dirname( __DIR__ ) . '/' . $package;
		if ( ! is_dir( $package_root ) ) {
			continue;
		}
		$prod_files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $package_root, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $prod_files as $file ) {
			// Normalise separators before matching: RecursiveDirectoryIterator
			// yields backslashes on Windows, so a `#/src/#` pattern silently
			// matches nothing there and the scan passes vacuously.
			$path = str_replace( '\\', '/', $file->getPathname() );
			if ( 1 !== preg_match( '#/((src|inc|includes)/.*\.php|functions\.php)$#', $path ) ) {
				continue;
			}
			$code = file_get_contents( $file->getPathname() );
			if ( false === $code ) {
				continue;
			}
			foreach ( array( 'the_content_feed', 'the_excerpt_rss' ) as $hook ) {
				if ( false !== strpos( $code, "add_filter( '" . $hook . "'" ) ) {
					$feed_gate_hits[ $hook ] = true;
				}
			}
		}
	}
	mw_feed_assert_same(
		true,
		isset( $feed_gate_hits['the_content_feed'] ),
		'S1 FIXED: a the_content_feed gate must stay registered, or restricted release bodies are served verbatim from /feed/.'
	);
	mw_feed_assert_same(
		true,
		isset( $feed_gate_hits['the_excerpt_rss'] ),
		'S1 FIXED: a the_excerpt_rss gate must stay registered — feeds that syndicate excerpts are a second leak path.'
	);

	// The gate must not be the web callback: filter_content() early-returns on
	// is_feed(), so wiring it to the feed hooks would be a silent no-op (N2).
	$rendering_module = file_get_contents( dirname( __DIR__ ) . '/music-wave-core/src/Modules/Rendering.php' );
	mw_feed_assert_same(
		false,
		is_string( $rendering_module ) && (
			false !== strpos( $rendering_module, "add_filter( 'the_content_feed', array( \$this->blocks, 'filter_content' )" )
			|| false !== strpos( $rendering_module, "add_filter( 'the_excerpt_rss', array( \$this->blocks, 'filter_content' )" )
		),
		'N2: the feed hooks must not reuse filter_content() — its is_feed() guard makes that a no-op.'
	);

	// ---------------------------------------------------------------------
	// 2. Web contract against the real filter_content().
	// ---------------------------------------------------------------------

	class FeedTestRepository implements ReleaseRepository {
		public $values = array(
			'mw_access_mode' => 'restricted',
		);
		public function get( int $release_id, string $key ) {
			return $this->values[ $key ] ?? '';
		}
		public function update( int $release_id, string $key, $value ): bool {
			return true;
		}
		public function delete( int $release_id, string $key ): bool {
			return true;
		}
		public function product_ids( int $release_id ): array {
			return array();
		}
		public function collection_ids( int $release_id ): array {
			return array();
		}
		public function collection_items( int $collection_id ): array {
			return array();
		}
		public function replace_collection_items( int $collection_id, array $items ): bool {
			return true;
		}
	}

	$repository = new FeedTestRepository();
	$blocks     = new ReleaseBlocks(
		new AccessPolicyEngine( $repository, new PurchaseChecker( $repository ) ),
		$repository
	);

	$SECRET = 'MW-SECRET-SENTENCE-777 must never reach the feed.';
	$body   = '<p>Intro sentence. ' . $SECRET . '</p>';

	// Frontend (web) context: the gate must replace the denied body.
	// WP_Post is emulated with a minimal subclass: filter_content() requires
	// `instanceof WP_Post` (ReleaseBlocks.php:591), and the core autoloader
	// resolves the class name to src/WP_Post.php, which does not exist.
	if ( ! class_exists( 'WP_Post' ) ) {
		eval( 'final class WP_Post { public $ID = 0; public $post_type = \'\'; }' );
	}
	$GLOBALS['mw_feed_test_is_feed']  = false;
	$GLOBALS['mw_feed_test_is_admin'] = false;
	$GLOBALS['mw_feed_test_post']     = new WP_Post();
	$GLOBALS['mw_feed_test_post']->ID        = 42;
	$GLOBALS['mw_feed_test_post']->post_type = 'mw_release';

	$repository->values['mw_access_mode'] = 'restricted';
	$out                                  = $blocks->filter_content( $body );
	$web_gate                             = false !== strpos( $out, 'mw-access-panel' );
	mw_feed_assert_same( false, strpos( $out, $SECRET ), 'Web: a denied release body must never contain the body text.' );
	mw_feed_assert_same( true, $web_gate, 'Web: the denied body must be replaced by a gate fragment (access panel or wrapper).' );

	// Public releases pass through untouched.
	$repository->values['mw_access_mode'] = 'public';
	mw_feed_assert_same( $body, $blocks->filter_content( $body ), 'Web: public release bodies must pass through unchanged.' );
	$repository->values['mw_access_mode'] = 'restricted';

	// Non-release post types keep the content.
	$GLOBALS['mw_feed_test_post'] = new WP_Post();
	$GLOBALS['mw_feed_test_post']->ID        = 9;
	$GLOBALS['mw_feed_test_post']->post_type = 'post';
	mw_feed_assert_same( $body, $blocks->filter_content( $body ), 'Web: non-release posts must pass through unchanged.' );

	// Admin contexts keep the content.
	$GLOBALS['mw_feed_test_post'] = new WP_Post();
	$GLOBALS['mw_feed_test_post']->ID        = 42;
	$GLOBALS['mw_feed_test_post']->post_type = 'mw_release';
	$GLOBALS['mw_feed_test_is_admin'] = true;
	mw_feed_assert_same( $body, $blocks->filter_content( $body ), 'Admin contexts must pass through unchanged.' );
	$GLOBALS['mw_feed_test_is_admin'] = false;

	// ---------------------------------------------------------------------
	// 3. Feed contract (S1 / N2).
	// ---------------------------------------------------------------------

	// Simulated WordPress: in a feed, core runs the_content_feed for the body
	// and the_excerpt_rss for the excerpt. Both are wired to the same real
	// callback, so one probe covers both entry points.
	$GLOBALS['mw_feed_test_is_feed']         = true;
	$GLOBALS['mw_feed_test_post']            = new WP_Post();
	$GLOBALS['mw_feed_test_post']->ID        = 42;
	$GLOBALS['mw_feed_test_post']->post_type = 'mw_release';

	// (a) Pin N2: the web callback still early-returns in feed context, which is
	// exactly why the feed needs its own gate. This is a property of
	// filter_content(), not a defect — the assertion stops anyone from
	// "simplifying" the feed hooks back onto it.
	$repository->values['mw_access_mode'] = 'restricted';
	mw_feed_assert_same(
		$body,
		$blocks->filter_content( $body ),
		'N2: filter_content() must keep its is_feed() early-return, so the feed gate stays a separate callback.'
	);

	// (b) The real feed gate must strip the denied body on both feed hooks.
	$feed_body = $blocks->filter_feed_content( $body );
	mw_feed_assert_same( false, strpos( $feed_body, $SECRET ), 'Feed gate: a denied release body must never reach the feed.' );
	mw_feed_assert_same( true, false !== strpos( $feed_body, 'mw-feed-gate--restricted' ), 'Feed gate: the denied body must be replaced by a gate carrying the decision reason.' );

	$feed_excerpt = $blocks->filter_feed_content( 'Excerpt. ' . $SECRET );
	mw_feed_assert_same( false, strpos( $feed_excerpt, $SECRET ), 'Feed gate: a denied excerpt must never reach the feed.' );

	// Purchase and membership denials carry their own reason class so feed
	// consumers (and the CTA copy) stay distinguishable.
	$repository->values['mw_access_mode'] = 'purchase';
	mw_feed_assert_same(
		true,
		false !== strpos( $blocks->filter_feed_content( $body ), 'mw-feed-gate--purchase_required' ),
		'Feed gate: a purchase-gated release must report purchase_required.'
	);

	// Public release bodies stay intact in feeds.
	$repository->values['mw_access_mode'] = 'public';
	mw_feed_assert_same( $body, $blocks->filter_feed_content( $body ), 'Feed gate: public release bodies must pass through unchanged.' );

	// Non-release post types are none of the gate's business.
	$repository->values['mw_access_mode']    = 'restricted';
	$GLOBALS['mw_feed_test_post']            = new WP_Post();
	$GLOBALS['mw_feed_test_post']->ID        = 9;
	$GLOBALS['mw_feed_test_post']->post_type = 'post';
	mw_feed_assert_same( $body, $blocks->filter_feed_content( $body ), 'Feed gate: non-release posts must pass through unchanged.' );

	$GLOBALS['mw_feed_test_is_feed'] = false;

	// ---------------------------------------------------------------------
	// Report
	// ---------------------------------------------------------------------

	if ( $mw_feed_failures ) {
		echo "MusicWave feed regression tests FAILED:\n";
		foreach ( $mw_feed_failures as $failure ) {
			echo $failure . PHP_EOL;
		}
		exit( 1 );
	}

	echo sprintf( 'MusicWave feed regression tests passed (%d checks) — S1 closed (both feed hooks gated); N2 separation pinned.' . PHP_EOL, $mw_feed_checks );
}
