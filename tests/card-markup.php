<?php
/**
 * Release-card markup parity gate.
 *
 * The release card (`mw-release-shelf__item`) is emitted from several places:
 * `ReleaseBlocks::related_card()`, `ListeningBlocks::history_card()` and the
 * theme's release shelf. Stage B of docs/composability-architecture.md moves
 * the shared skeleton into `Core\Blocks\ReleaseCard`; this gate pins the
 * rendered markup so that extraction (and any later change) is provably
 * behaviour-preserving.
 *
 * Run standalone — it installs its own WordPress doubles and must not be
 * required from tests/run.php, which defines conflicting ones:
 *
 *     php tests/card-markup.php            # compare against fixtures
 *     php tests/card-markup.php --update   # (re)record fixtures
 *
 * The thumbnail double echoes back the attribute array it receives, so the
 * fixtures also pin the per-caller `loading` / `fetchpriority` / `decoding`
 * hints — the one place where the three card producers genuinely differ.
 *
 * @package MusicWave
 */

declare(strict_types=1);

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "tests/card-markup.php must run from the command line.\n" );
	exit( 1 );
}

$mw_card_update  = in_array( '--update', $argv, true );
$mw_card_root    = dirname( __DIR__ );
$mw_card_fixture = __DIR__ . '/fixtures/card-markup.json';

/*
 * ---------------------------------------------------------------------------
 * WordPress doubles. Every stub is deterministic: fixed permalinks, a fixed
 * post date, and thumbnails that report the attributes they were handed.
 * ---------------------------------------------------------------------------
 */

define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MW_CARD_FIXED_TIME', 1700000000 );

// The theme files open with an `if ( ! defined( 'ABSPATH' ) ) { exit; }`
// direct-access guard, so the harness has to look like WordPress.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );

$GLOBALS['mw_card_titles']      = array();
$GLOBALS['mw_card_links']       = array();
$GLOBALS['mw_card_artists']     = array();
$GLOBALS['mw_card_excerpts']    = array();
$GLOBALS['mw_card_thumbnails']  = array();
$GLOBALS['mw_card_post_types']  = array();
$GLOBALS['mw_card_query_ids']   = array();
$GLOBALS['mw_card_filters']     = array();
$GLOBALS['mw_card_unique_id']   = 0;

if ( ! function_exists( '__' ) ) {
	/**
	 * @param string $text   Source string.
	 * @param string $domain Text domain.
	 */
	function __( $text, $domain = 'default' ) {
		unset( $domain );
		return $text;
	}
}

if ( ! function_exists( '_n' ) ) {
	/**
	 * @param string $single Singular form.
	 * @param string $plural Plural form.
	 * @param int    $number Count.
	 * @param string $domain Text domain.
	 */
	function _n( $single, $plural, $number, $domain = 'default' ) {
		unset( $domain );
		return 1 === (int) $number ? $single : $plural;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		return esc_html( __( $text, $domain ) );
	}
}

if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( $text, $domain = 'default' ) {
		return esc_attr( __( $text, $domain ) );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return (string) $url;
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( (string) $url, $component );
	}
}

if ( ! function_exists( 'wp_trim_words' ) ) {
	function wp_trim_words( $text, $num_words = 55, $more = null ) {
		unset( $more );
		$words = preg_split( '/\s+/u', trim( (string) $text ) );
		$words = is_array( $words ) ? array_values( array_filter( $words, 'strlen' ) ) : array();
		if ( count( $words ) <= (int) $num_words ) {
			return trim( (string) $text );
		}

		return implode( ' ', array_slice( $words, 0, (int) $num_words ) );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( wp_strip_all_tags( (string) $str ) );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $string, $remove_breaks = false ) {
		$string = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $string );
		$string = strip_tags( $string );
		return $remove_breaks ? preg_replace( '/[\r\n\t\s]+/', ' ', $string ) : trim( $string );
	}
}

if ( ! function_exists( 'sanitize_html_class' ) ) {
	function sanitize_html_class( $class, $fallback = '' ) {
		$safe = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $class );
		return '' !== $safe ? $safe : (string) $fallback;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $title, $fallback = '', $context = 'save' ) {
		unset( $context );
		$slug = strtolower( trim( (string) preg_replace( '/[^A-Za-z0-9]+/', '-', (string) $title ), '-' ) );
		return '' !== $slug ? $slug : (string) $fallback;
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $data ) {
		return (string) $data;
	}
}

if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( $post = 0 ) {
		$id = (int) $post;
		return isset( $GLOBALS['mw_card_titles'][ $id ] ) ? $GLOBALS['mw_card_titles'][ $id ] : '';
	}
}

if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $post = 0 ) {
		$id = (int) $post;
		return isset( $GLOBALS['mw_card_links'][ $id ] ) ? $GLOBALS['mw_card_links'][ $id ] : false;
	}
}

if ( ! function_exists( 'get_the_excerpt' ) ) {
	function get_the_excerpt( $post = null ) {
		$id = is_object( $post ) ? (int) $post->ID : (int) $post;
		return isset( $GLOBALS['mw_card_excerpts'][ $id ] ) ? $GLOBALS['mw_card_excerpts'][ $id ] : '';
	}
}

if ( ! function_exists( 'get_the_date' ) ) {
	function get_the_date( $format = '', $post = null ) {
		unset( $post );
		return 'c' === $format ? gmdate( 'c', MW_CARD_FIXED_TIME ) : gmdate( 'j F Y', MW_CARD_FIXED_TIME );
	}
}

if ( ! function_exists( 'get_post_modified_time' ) ) {
	function get_post_modified_time( $format = '', $gmt = false, $post = null, $translate = false ) {
		unset( $gmt, $post, $translate );
		return 'c' === $format ? gmdate( 'c', MW_CARD_FIXED_TIME ) : MW_CARD_FIXED_TIME;
	}
}

if ( ! function_exists( 'get_the_post_thumbnail' ) ) {
	/**
	 * Deterministic thumbnail double.
	 *
	 * Reports the attribute array it received so fixtures pin the per-caller
	 * loading / fetchpriority / decoding hints. Returns '' for releases with
	 * no artwork, exercising the placeholder branch.
	 *
	 * @param int          $post_id    Release ID.
	 * @param string|int[] $size       Image size.
	 * @param array        $attributes Attribute array.
	 */
	function get_the_post_thumbnail( $post_id = null, $size = 'post-thumbnail', $attributes = array() ) {
		$id = (int) $post_id;
		if ( empty( $GLOBALS['mw_card_thumbnails'][ $id ] ) ) {
			return '';
		}

		$parts = array();
		foreach ( (array) $attributes as $key => $value ) {
			$parts[] = $key . '=' . $value;
		}

		return '<img src="https://example.test/art-' . $id . '.jpg" data-attrs="' . esc_attr( implode( '|', $parts ) ) . '" data-size="' . esc_attr( is_array( $size ) ? implode( 'x', $size ) : (string) $size ) . '" />';
	}
}

if ( ! function_exists( 'get_the_post_thumbnail_url' ) ) {
	function get_the_post_thumbnail_url( $post = null, $size = 'thumbnail' ) {
		unset( $size );
		$id = (int) $post;
		return empty( $GLOBALS['mw_card_thumbnails'][ $id ] ) ? false : 'https://example.test/art-' . $id . '.jpg';
	}
}

if ( ! function_exists( 'has_post_thumbnail' ) ) {
	function has_post_thumbnail( $post = null ) {
		return ! empty( $GLOBALS['mw_card_thumbnails'][ (int) $post ] );
	}
}

if ( ! function_exists( 'wp_get_post_terms' ) ) {
	function wp_get_post_terms( $post_id = 0, $taxonomy = 'category', $args = array() ) {
		unset( $args );
		$key = (int) $post_id . ':' . $taxonomy;
		return isset( $GLOBALS['mw_card_artists'][ $key ] ) ? $GLOBALS['mw_card_artists'][ $key ] : array();
	}
}

if ( ! function_exists( 'get_the_terms' ) ) {
	function get_the_terms( $post_id, $taxonomy ) {
		$names = wp_get_post_terms( $post_id, $taxonomy );
		if ( array() === $names ) {
			return false;
		}

		$terms = array();
		foreach ( $names as $index => $name ) {
			$terms[] = (object) array(
				'term_id' => $index + 1,
				'name'    => $name,
				'slug'    => sanitize_title( (string) $name ),
			);
		}

		return $terms;
	}
}

if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( $post = null ) {
		$id = (int) $post;
		return isset( $GLOBALS['mw_card_post_types'][ $id ] ) ? $GLOBALS['mw_card_post_types'][ $id ] : false;
	}
}

if ( ! function_exists( 'post_type_exists' ) ) {
	function post_type_exists( $post_type ) {
		return in_array( (string) $post_type, array( 'mw_release', 'mw_playlist', 'page', 'post' ), true );
	}
}

if ( ! function_exists( 'taxonomy_exists' ) ) {
	function taxonomy_exists( $taxonomy ) {
		return in_array( (string) $taxonomy, array( 'mw_artist', 'mw_genre', 'mw_mood', 'mw_release_type' ), true );
	}
}

if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( $args = array() ) {
		unset( $args );
		return $GLOBALS['mw_card_query_ids'];
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook_name, $value, ...$args ) {
		unset( $args );
		return isset( $GLOBALS['mw_card_filters'][ $hook_name ] ) ? $GLOBALS['mw_card_filters'][ $hook_name ] : $value;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
		unset( $hook_name, $callback, $priority, $accepted_args );
		return true;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
		return add_filter( $hook_name, $callback, $priority, $accepted_args );
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook_name, ...$args ) {
		unset( $hook_name, $args );
	}
}

if ( ! function_exists( 'did_action' ) ) {
	function did_action( $hook_name ) {
		unset( $hook_name );
		return 0;
	}
}

if ( ! function_exists( 'wp_unique_id' ) ) {
	function wp_unique_id( $prefix = '' ) {
		return $prefix . (string) ++$GLOBALS['mw_card_unique_id'];
	}
}

if ( ! function_exists( 'get_block_wrapper_attributes' ) ) {
	function get_block_wrapper_attributes( $extra = array() ) {
		$class = isset( $extra['class'] ) ? (string) $extra['class'] : '';
		return 'class="' . esc_attr( $class ) . '"';
	}
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $args = false ) {
		unset( $handle, $src, $deps, $ver, $args );
	}
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) {
		unset( $handle, $src, $deps, $ver, $media );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default_value = false ) {
		return $default_value;
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() {
		return false;
	}
}

if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '', $scheme = null ) {
		unset( $scheme );
		return 'https://example.test' . (string) $path;
	}
}

if ( ! function_exists( 'get_template_directory' ) ) {
	function get_template_directory() {
		return dirname( __DIR__ ) . '/musicwave';
	}
}

if ( ! function_exists( 'get_stylesheet_directory' ) ) {
	function get_stylesheet_directory() {
		return dirname( __DIR__ ) . '/musicwave';
	}
}

if ( ! function_exists( 'get_template_directory_uri' ) ) {
	function get_template_directory_uri() {
		return 'https://example.test/wp-content/themes/musicwave';
	}
}

if ( ! function_exists( 'get_stylesheet_directory_uri' ) ) {
	function get_stylesheet_directory_uri() {
		return get_template_directory_uri();
	}
}

if ( ! function_exists( 'get_post_type_archive_link' ) ) {
	function get_post_type_archive_link( $post_type ) {
		return 'https://example.test/releases/';
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability, ...$args ) {
		unset( $capability, $args );
		return false;
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return 0;
	}
}

if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $number, $decimals = 0 ) {
		return number_format( (float) $number, (int) $decimals );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

require_once $mw_card_root . '/music-wave-core/src/Support/Autoloader.php';
ManaCore\MusicWave\Core\Support\Autoloader::register();

/**
 * Release persistence double. Only `get()` matters for card markup: it drives
 * the preview-URL checks behind the play overlay and the preview button.
 */
final class MW_Card_Repository implements ManaCore\MusicWave\Core\Contracts\ReleaseRepository {
	/** @var array<int, array<string, mixed>> */
	public $values = array();

	public function get( int $release_id, string $key ) {
		return isset( $this->values[ $release_id ][ $key ] ) ? $this->values[ $release_id ][ $key ] : null;
	}

	public function update( int $release_id, string $key, $value ): bool {
		$this->values[ $release_id ][ $key ] = $value;
		return true;
	}

	public function delete( int $release_id, string $key ): bool {
		unset( $this->values[ $release_id ][ $key ] );
		return true;
	}

	public function product_ids( int $release_id ): array {
		unset( $release_id );
		return array();
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

/*
 * ---------------------------------------------------------------------------
 * Fixtures. Two releases: one fully populated with artwork, artists, an
 * excerpt and an HTTPS preview URL; one bare, to exercise the placeholder and
 * no-preview branches.
 * ---------------------------------------------------------------------------
 */

$mw_card_repository = new MW_Card_Repository();

$mw_card_repository->values[11] = array(
	'mw_preview_url'      => 'https://cdn.example.test/previews/11.mp3',
	'mw_preview_duration' => 45,
);
$mw_card_repository->values[13] = array(
	'mw_preview_url'      => 'http://insecure.example.test/13.mp3',
	'mw_preview_duration' => 5,
);

$GLOBALS['mw_card_titles']     = array(
	11 => 'آلبوم نمونه',
	12 => 'تک‌آهنگ بدون کاور',
	13 => 'آلبوم سوم',
	14 => '',
);
$GLOBALS['mw_card_links']      = array(
	11 => 'https://example.test/release/sample-album/',
	12 => 'https://example.test/release/no-cover/',
	13 => 'https://example.test/release/third-album/',
	14 => 'https://example.test/release/untitled/',
);
$GLOBALS['mw_card_post_types'] = array(
	11 => 'mw_release',
	12 => 'mw_release',
	13 => 'mw_release',
	14 => 'mw_release',
);
$GLOBALS['mw_card_artists']    = array(
	'11:mw_artist' => array( 'هنرمند اول', 'هنرمند دوم' ),
	'12:mw_artist' => array(),
	'13:mw_artist' => array( 'هنرمند سوم' ),
	'14:mw_artist' => array(),
);
$GLOBALS['mw_card_excerpts']   = array(
	11 => 'توضیح کوتاه برای انتشار نمونه که در کارت‌ها کوتاه می‌شود.',
	12 => '',
	13 => '',
);
// 12 deliberately has no artwork (placeholder branch) and 13 sits third in the
// shelf query, which is where the theme drops to lazy/low loading hints.
$GLOBALS['mw_card_thumbnails'] = array(
	11 => true,
	13 => true,
);

/**
 * Build a class instance without running its constructor and inject the
 * repository double. The card methods only touch `$this->repository`, so the
 * full policy/graph dependency chain is unnecessary here.
 *
 * @param string $class_name Fully-qualified class name.
 */
function mw_card_instantiate( string $class_name, MW_Card_Repository $repository ): object {
	$reflection = new ReflectionClass( $class_name );
	$instance   = $reflection->newInstanceWithoutConstructor();
	if ( $reflection->hasProperty( 'repository' ) ) {
		$property = $reflection->getProperty( 'repository' );
		if ( PHP_VERSION_ID < 80100 ) {
			// Required for private properties before PHP 8.1; a no-op (and
			// deprecated) afterwards.
			$property->setAccessible( true );
		}
		$property->setValue( $instance, $repository );
	}

	return $instance;
}

/**
 * Invoke a private method through reflection.
 *
 * @param array<int, mixed> $arguments Positional arguments.
 */
function mw_card_call( object $instance, string $method, array $arguments ): string {
	$reflection = new ReflectionMethod( $instance, $method );
	if ( PHP_VERSION_ID < 80100 ) {
		$reflection->setAccessible( true );
	}
	$value = $reflection->invokeArgs( $instance, $arguments );

	return is_string( $value ) ? $value : '';
}

/**
 * Normalise the one volatile value in the captured markup.
 *
 * `ListeningBlocks::history_card()` stamps `<time datetime>` from the real
 * clock (`gmdate( 'c', $updated_at )`), so that attribute differs on every
 * run. The surrounding markup and the relative "played ago" text — which is
 * derived from a fixed offset — stay fully asserted.
 */
function mw_card_normalize( string $html ): string {
	return (string) preg_replace( '/datetime="\d{4}-\d{2}-\d{2}T[^"]*"/', 'datetime="{TIMESTAMP}"', $html );
}

$mw_card_cases = array();

/*
 * ReleaseBlocks::related_card() — the related-releases shelf card. `$options`
 * mirrors ReleaseBlocks::related_card_options() defaults plus overrides.
 */
$mw_card_related  = mw_card_instantiate( 'ManaCore\MusicWave\Core\Blocks\ReleaseBlocks', $mw_card_repository );
$mw_card_defaults = array(
	'layout'       => 'grid',
	'columns'      => 4,
	'shape'        => 'square',
	'show_artwork' => true,
	'show_artist'  => true,
	'show_date'    => false,
	'show_excerpt' => false,
	'show_preview' => true,
	'show_action'  => false,
	'action_label' => 'انتشار را باز کنید',
	'variation'    => '',
);

$mw_card_cases['related:defaults:with-art']       = mw_card_call( $mw_card_related, 'related_card', array( 11, $mw_card_defaults ) );
$mw_card_cases['related:defaults:no-art']         = mw_card_call( $mw_card_related, 'related_card', array( 12, $mw_card_defaults ) );
$mw_card_cases['related:everything-on']           = mw_card_call(
	$mw_card_related,
	'related_card',
	array(
		11,
		array_merge(
			$mw_card_defaults,
			array(
				'shape'        => 'circle',
				'show_date'    => true,
				'show_excerpt' => true,
				'show_action'  => true,
				'action_label' => 'مشاهده',
			)
		),
	)
);
$mw_card_cases['related:no-artwork:no-preview']   = mw_card_call(
	$mw_card_related,
	'related_card',
	array(
		12,
		array_merge(
			$mw_card_defaults,
			array(
				'show_artwork' => false,
				'show_preview' => false,
				'show_artist'  => false,
			)
		),
	)
);

/*
 * ListeningBlocks::history_card() — continue-listening. `$updated_at` is
 * expressed relative to time() so human_time_diff() stays deterministic no
 * matter when the suite runs.
 */
$mw_card_listening = mw_card_instantiate( 'ManaCore\MusicWave\Core\Blocks\ListeningBlocks', $mw_card_repository );
$mw_card_history   = array(
	'layout'       => 'scroll',
	'columns'      => 3,
	'shape'        => 'square',
	'show_artwork' => true,
	'show_artist'  => true,
	'show_when'    => true,
	'show_preview' => true,
);

$mw_card_cases['history:defaults:with-art']     = mw_card_call( $mw_card_listening, 'history_card', array( 11, time() - ( 3 * DAY_IN_SECONDS ) - 120, $mw_card_history ) );
$mw_card_cases['history:defaults:no-art']       = mw_card_call( $mw_card_listening, 'history_card', array( 12, time() - ( 2 * HOUR_IN_SECONDS ) - 30, $mw_card_history ) );
$mw_card_cases['history:bare:minutes']          = mw_card_call(
	$mw_card_listening,
	'history_card',
	array(
		12,
		time() - 90,
		array_merge(
			$mw_card_history,
			array(
				'show_artwork' => false,
				'show_artist'  => false,
				'shape'        => 'portrait',
			)
		),
	)
);
$mw_card_cases['history:untitled-release']      = mw_card_call( $mw_card_listening, 'history_card', array( 14, time() - 45, $mw_card_history ) );

/*
 * The `music_wave_card_play_button` filter is the shared seam the preview
 * player and the VIP plugin use to replace the decorative play glyph. Each
 * producer must splice its return value in verbatim.
 */
$GLOBALS['mw_card_filters']['music_wave_card_play_button'] = '<button type="button" class="mw-release-shelf__play mw-card-play" data-mw-filtered="1">FILTERED</button>';

$mw_card_cases['related:filtered-overlay'] = mw_card_call( $mw_card_related, 'related_card', array( 11, $mw_card_defaults ) );
$mw_card_cases['related:filtered-no-art']  = mw_card_call( $mw_card_related, 'related_card', array( 12, $mw_card_defaults ) );
$mw_card_cases['history:filtered-overlay'] = mw_card_call( $mw_card_listening, 'history_card', array( 11, time() - ( 5 * DAY_IN_SECONDS ), $mw_card_history ) );

unset( $GLOBALS['mw_card_filters']['music_wave_card_play_button'] );

/*
 * Theme release shelf. functions.php is loaded here because the card is built
 * inline in musicwave_render_release_shelf(); the <article> fragments are
 * extracted from the rendered section.
 */
$mw_card_theme_loaded = false;
if ( is_file( get_template_directory() . '/functions.php' ) ) {
	require_once get_template_directory() . '/functions.php';
	$mw_card_theme_loaded = function_exists( 'musicwave_render_release_shelf' );
}

if ( $mw_card_theme_loaded ) {
	$GLOBALS['mw_card_query_ids'] = array( 11, 12, 13 );

	foreach ( array( 'defaults', 'full', 'filtered' ) as $mw_card_pass ) {
		$mw_card_first_pass = 'full' === $mw_card_pass;
		if ( 'filtered' === $mw_card_pass ) {
			$GLOBALS['mw_card_filters']['music_wave_card_play_button'] = '<button type="button" class="mw-release-shelf__play mw-card-play" data-mw-filtered="1">FILTERED</button>';
		}
		$mw_card_attributes = array(
			'layout'      => 'grid',
			'columns'     => 4,
			'imageShape'  => $mw_card_first_pass ? 'circle' : 'square',
			'showArtwork' => true,
			'showArtist'  => true,
			'showDate'    => $mw_card_first_pass,
			'showExcerpt' => $mw_card_first_pass,
			'showAction'  => true,
			'actionLabel' => 'مشاهده',
			'title'       => 'انتشارات برگزیده',
			'eyebrow'     => 'منتخب',
		);

		$mw_card_section = musicwave_render_release_shelf( $mw_card_attributes );
		preg_match_all( '/<article class="mw-release-shelf__item".*?<\/article>/su', $mw_card_section, $mw_card_matches );
		$mw_card_articles = isset( $mw_card_matches[0] ) ? $mw_card_matches[0] : array();
		foreach ( $mw_card_articles as $mw_card_index => $mw_card_article ) {
			$mw_card_cases[ 'theme-shelf:' . $mw_card_pass . ':item-' . $mw_card_index ] = $mw_card_article;
		}

		unset( $GLOBALS['mw_card_filters']['music_wave_card_play_button'] );
	}

	$GLOBALS['mw_card_query_ids'] = array();
}

/*
 * Section headers. `musicwave_render_shelf_header()` and
 * `ReleaseBlocks::related_section()` share the same "see all" link shape with
 * different BEM roots, so both are pinned here before Stage B extracts it.
 */
if ( $mw_card_theme_loaded ) {
	$mw_card_cases['header:shelf:full']         = musicwave_render_shelf_header( 'منتخب', 'انتشارات برگزیده', 'توضیح بخش', 'https://example.test/releases/', 'مشاهده همه' );
	$mw_card_cases['header:shelf:title-only']   = musicwave_render_shelf_header( '', 'انتشارات برگزیده' );
	$mw_card_cases['header:shelf:empty']        = musicwave_render_shelf_header( '', '', '', '' );
	$mw_card_cases['header:shelf:url-no-label'] = musicwave_render_shelf_header( '', 'عنوان', '', 'https://example.test/releases/', '' );
	$mw_card_cases['header:shelf:desc-only']    = musicwave_render_shelf_header( '', '', 'فقط توضیح' );
}

$mw_card_section_options = array_merge( $mw_card_defaults, array( 'layout' => 'list', 'columns' => 3 ) );
$mw_card_cases['related-section:with-more']      = mw_card_call( $mw_card_related, 'related_section', array( 'شبیه این', array( 11, 12 ), $mw_card_section_options, 'https://example.test/releases/', 'همه را ببینید' ) );
$mw_card_cases['related-section:no-more']        = mw_card_call( $mw_card_related, 'related_section', array( 'شبیه این', array( 11 ), $mw_card_section_options ) );
$mw_card_cases['related-section:label-only']     = mw_card_call( $mw_card_related, 'related_section', array( 'شبیه این', array( 11 ), $mw_card_section_options, '', 'همه را ببینید' ) );
$mw_card_cases['related-section:empty']          = mw_card_call( $mw_card_related, 'related_section', array( 'شبیه این', array(), $mw_card_section_options, 'https://example.test/releases/', 'همه' ) );
$mw_card_cases['related-section:editorial']      = mw_card_call( $mw_card_related, 'related_section', array( 'شبیه این', array( 13 ), array_merge( $mw_card_section_options, array( 'variation' => 'editorial', 'layout' => 'grid' ) ) ) );

$mw_card_cases = array_map( 'mw_card_normalize', $mw_card_cases );
ksort( $mw_card_cases );

/*
 * ---------------------------------------------------------------------------
 * Compare or record.
 * ---------------------------------------------------------------------------
 */

$mw_card_encoded = json_encode( $mw_card_cases, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

if ( $mw_card_update ) {
		if ( ! is_dir( dirname( $mw_card_fixture ) ) ) {
		mkdir( dirname( $mw_card_fixture ), 0777, true );
	}
	file_put_contents( $mw_card_fixture, $mw_card_encoded . "\n" );
	echo 'Card markup fixtures recorded: ' . count( $mw_card_cases ) . " cases.\n";
	echo 'Theme shelf covered: ' . ( $mw_card_theme_loaded ? 'yes' : 'NO — theme functions unavailable' ) . "\n";
	exit( 0 );
}

if ( ! is_file( $mw_card_fixture ) ) {
	fwrite( STDERR, "Missing fixtures: run `php tests/card-markup.php --update` first.\n" );
	exit( 1 );
}

$mw_card_expected = json_decode( (string) file_get_contents( $mw_card_fixture ), true );
if ( ! is_array( $mw_card_expected ) ) {
	fwrite( STDERR, "Fixtures are not a valid JSON object.\n" );
	exit( 1 );
}

$mw_card_failures = 0;
foreach ( $mw_card_expected as $mw_card_key => $mw_card_html ) {
	if ( ! array_key_exists( $mw_card_key, $mw_card_cases ) ) {
		++$mw_card_failures;
		echo "MISSING CASE  {$mw_card_key}\n";
		continue;
	}
	if ( $mw_card_cases[ $mw_card_key ] !== $mw_card_html ) {
		++$mw_card_failures;
		echo "MARKUP CHANGED  {$mw_card_key}\n";
		echo '  expected: ' . $mw_card_html . "\n";
		echo '  actual:   ' . $mw_card_cases[ $mw_card_key ] . "\n";
	}
}

foreach ( $mw_card_cases as $mw_card_key => $mw_card_html ) {
	if ( ! array_key_exists( $mw_card_key, $mw_card_expected ) ) {
		++$mw_card_failures;
		echo "NEW CASE (record with --update)  {$mw_card_key}\n";
	}
}

if ( ! $mw_card_theme_loaded ) {
	++$mw_card_failures;
	echo "Theme functions could not be loaded; the theme card and header producers are uncovered.\n";
}

if ( $mw_card_failures > 0 ) {
	fwrite( STDERR, "\nCard markup parity FAILED: {$mw_card_failures} problem(s).\n" );
	exit( 1 );
}

echo 'Card markup parity OK (' . count( $mw_card_cases ) . " cases: 3 card producers + 2 header producers).\n";
exit( 0 );
