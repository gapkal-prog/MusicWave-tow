<?php
/**
 * Release-type aware permalinks.
 *
 * Releases share one post type, but a visitor expects an album, a single, a
 * mix, or a podcast episode to live under a matching URL base rather than a
 * generic `/music/` prefix. This service maps the primary `mw_release_type`
 * term of a release to a URL base (`/album/`, `/track/`, `/podcast/`, ...),
 * registers a WordPress permastruct for every base so all core rewrite rules
 * (pagination, comment pages, feeds, embeds, attachments, endpoints) exist for
 * each of them, and permanently redirects legacy or stale base requests to the
 * canonical permalink. Releases without a mapped type keep the post type's own
 * `/music/` structure, so existing links never break.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Catalog;

use WP_Post;
use WP_Term;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ReleasePermalinks {
	/** Filter: adjust the release-type slug => URL base map. */
	public const FILTER_BASES = 'music_wave_release_permalink_bases';

	/** URL base owned by the post type registration (kept for untyped releases and the archive). */
	public const DEFAULT_BASE = 'music';

	/** Taxonomy carrying the release type. */
	public const TAXONOMY = 'mw_release_type';

	/** Option remembering which base map the persisted rewrite rules were generated for. */
	public const OPTION_RULES_SIGNATURE = 'music_wave_release_permalink_rules';

	/** Bump when the generated rule set changes without the base map changing. */
	private const RULES_VERSION = '1';

	/** Maximum ancestor depth consulted when a child release type is not mapped itself. */
	private const MAX_TERM_DEPTH = 5;

	/** @var array<string, string>|null Sanitized type => base map, memoized per request. */
	private $bases = null;

	/**
	 * Hook the service into WordPress.
	 *
	 * Permastructs register after the post type (priority 5) and taxonomies
	 * (priority 6) so the `%mw_release%` rewrite tag already exists.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_permastructs' ), 8 );
		add_action( 'wp_loaded', array( $this, 'maybe_flush_rewrite_rules' ) );
		add_filter( 'post_type_link', array( $this, 'filter_post_type_link' ), 10, 4 );
		add_action( 'template_redirect', array( $this, 'redirect_stale_permalink' ), 15 );
	}

	/**
	 * Default release-type slug => URL base map.
	 *
	 * Order doubles as priority: a release tagged with several types takes
	 * the first matching entry, so collections (album, EP) win over tracks.
	 *
	 * @return array<string, string>
	 */
	public static function default_bases(): array {
		return array(
			'album'           => 'album',
			'ep'              => 'ep',
			'mix'             => 'mix',
			'playlist'        => 'playlist',
			'podcast_show'    => 'podcast',
			'podcast_episode' => 'episode',
			'single'          => 'track',
			'track'           => 'track',
		);
	}

	/**
	 * Sanitized, filterable release-type => URL base map.
	 *
	 * Bases may contain several segments (`music/album`) but never leading or
	 * trailing slashes, characters outside `a-z0-9-`, or an empty segment;
	 * invalid entries are dropped so a bad filter cannot break routing.
	 *
	 * @return array<string, string>
	 */
	public function bases(): array {
		if ( null !== $this->bases ) {
			return $this->bases;
		}

		$raw       = apply_filters( self::FILTER_BASES, self::default_bases() );
		$sanitized = array();
		foreach ( is_array( $raw ) ? $raw : array() as $type => $base ) {
			$type = is_string( $type ) ? sanitize_key( $type ) : '';
			$base = is_string( $base ) ? self::sanitize_base( $base ) : '';
			if ( '' === $type || '' === $base ) {
				continue;
			}
			$sanitized[ $type ] = $base;
		}

		$this->bases = $sanitized;

		return $this->bases;
	}

	/**
	 * Normalize a URL base to lowercase slug segments separated by `/`.
	 *
	 * @param string $base Raw base.
	 * @return string Sanitized base or an empty string when unusable.
	 */
	public static function sanitize_base( string $base ): string {
		$segments = array();
		foreach ( explode( '/', trim( $base, "/ \t\n\r\0\x0B" ) ) as $segment ) {
			$segment = strtolower( trim( $segment ) );
			if ( '' === $segment || 1 !== preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $segment ) ) {
				return '';
			}
			$segments[] = $segment;
		}

		return implode( '/', $segments );
	}

	/**
	 * Distinct URL bases that need their own rewrite structure.
	 *
	 * The default base is excluded because the post type registration
	 * already owns it.
	 *
	 * @return array<int, string>
	 */
	public function extra_bases(): array {
		$bases = array_values( array_unique( array_values( $this->bases() ) ) );

		return array_values(
			array_filter(
				$bases,
				static function ( string $base ): bool {
					return self::DEFAULT_BASE !== $base;
				}
			)
		);
	}

	/**
	 * Permastruct name for a URL base.
	 *
	 * The `mw_release_base_` prefix keeps the names clear of the post type
	 * (`mw_release`) and taxonomy (`mw_release_type`) permastructs.
	 *
	 * @param string $base Sanitized URL base.
	 */
	public static function permastruct_name( string $base ): string {
		return 'mw_release_base_' . str_replace( array( '/', '-' ), '_', $base );
	}

	/**
	 * Resolve the URL base for a set of release-type slugs.
	 *
	 * @param array<int, string> $type_slugs Release-type slugs (own terms plus ancestors).
	 */
	public function base_for_types( array $type_slugs ): string {
		$type_slugs = array_map( 'sanitize_key', array_filter( $type_slugs, 'is_string' ) );
		foreach ( $this->bases() as $type => $base ) {
			if ( in_array( $type, $type_slugs, true ) ) {
				return $base;
			}
		}

		return self::DEFAULT_BASE;
	}

	/**
	 * Resolve the URL base for one release from its release-type terms.
	 *
	 * Child types inherit the base of the nearest mapped ancestor, so a
	 * "Live album" child of "Album" still lives under `/album/`.
	 *
	 * @param int $release_id Release post ID.
	 */
	public function base_for( int $release_id ): string {
		return $this->base_for_types( $this->type_slugs( $release_id ) );
	}

	/**
	 * Register one permastruct per extra URL base.
	 *
	 * Mirrors WP_Post_Type::add_rewrite_rules(): the same `with_front`,
	 * endpoint mask and feed flags as the release post type, so every base
	 * produces the identical rule set WordPress generates for `/music/`.
	 * `walk_dirs` is off because the base segment alone is not a route.
	 *
	 * @return void
	 */
	public function register_permastructs(): void {
		if ( ! function_exists( 'add_permastruct' ) || ! function_exists( 'get_post_type_object' ) ) {
			return;
		}
		if ( ! is_admin() && '' === (string) get_option( 'permalink_structure', '' ) ) {
			return;
		}

		$post_type = get_post_type_object( ReleasePostType::KEY );
		if ( ! ( $post_type instanceof \WP_Post_Type ) || false === $post_type->rewrite ) {
			return;
		}
		$rewrite = is_array( $post_type->rewrite ) ? $post_type->rewrite : array();

		foreach ( $this->extra_bases() as $base ) {
			add_permastruct(
				self::permastruct_name( $base ),
				$base . '/%' . ReleasePostType::KEY . '%',
				array(
					'with_front' => ! isset( $rewrite['with_front'] ) || false !== $rewrite['with_front'],
					'ep_mask'    => isset( $rewrite['ep_mask'] ) ? (int) $rewrite['ep_mask'] : ( defined( 'EP_PERMALINK' ) ? EP_PERMALINK : 1 ),
					'feed'       => ! empty( $rewrite['feeds'] ),
					'walk_dirs'  => false,
				)
			);
		}
	}

	/**
	 * Fingerprint of the rule set the current base map produces.
	 *
	 * Independent of WordPress helpers so it can be computed anywhere.
	 */
	public function rules_signature(): string {
		$pairs = array();
		foreach ( $this->bases() as $type => $base ) {
			$pairs[] = $type . '=' . $base;
		}

		return md5( self::RULES_VERSION . '|' . implode( ';', $pairs ) );
	}

	/**
	 * Persist the rewrite rules once whenever the base map changes.
	 *
	 * Runs on `wp_loaded`, i.e. after every plugin registered its rewrites
	 * and before the main query parses the request, so the very first
	 * request after an update (or after a site changes the map through the
	 * filter) already resolves `/album/<slug>/` instead of returning a 404
	 * until an administrator re-saves the permalink settings. Sites that
	 * never change the map pay one option read per request and nothing else.
	 *
	 * @return void
	 */
	public function maybe_flush_rewrite_rules(): void {
		if ( ! function_exists( 'flush_rewrite_rules' ) ) {
			return;
		}
		if ( function_exists( 'wp_installing' ) && wp_installing() ) {
			return;
		}

		$signature = $this->rules_signature();
		if ( (string) get_option( self::OPTION_RULES_SIGNATURE, '' ) === $signature ) {
			return;
		}

		// Remember the signature before flushing so concurrent first requests
		// do not all regenerate the rules.
		update_option( self::OPTION_RULES_SIGNATURE, $signature );
		flush_rewrite_rules( false );
	}

	/**
	 * Swap the post type base for the release-type base in pretty permalinks.
	 *
	 * Plain links (`?mw_release=`, `?post_type=&p=` for drafts) and releases
	 * without a mapped type are returned untouched. With `$leavename` the
	 * `%mw_release%` token is preserved so the editor slug UI keeps working.
	 *
	 * @param mixed $post_link Permalink computed by WordPress.
	 * @param mixed $post      Post object.
	 * @param mixed $leavename Whether to keep the post name token.
	 * @param mixed $sample    Whether this is a sample permalink.
	 * @return mixed
	 */
	public function filter_post_type_link( $post_link, $post, $leavename = false, $sample = false ) {
		unset( $sample );
		if ( ! is_string( $post_link ) || ! $post instanceof WP_Post || ReleasePostType::KEY !== $post->post_type ) {
			return $post_link;
		}
		if ( false !== strpos( $post_link, '?' ) ) {
			return $post_link;
		}

		$base = $this->base_for( (int) $post->ID );
		if ( self::DEFAULT_BASE === $base ) {
			return $post_link;
		}

		$structure = $this->extra_permastruct( self::permastruct_name( $base ) );
		if ( '' === $structure ) {
			return $post_link;
		}

		$token = '%' . ReleasePostType::KEY . '%';
		if ( ! $leavename ) {
			$slug = (string) $post->post_name;
			if ( '' === $slug ) {
				return $post_link;
			}
			$structure = str_replace( $token, $slug, $structure );
		}

		return home_url( user_trailingslashit( $structure ) );
	}

	/**
	 * Permanently redirect a release served under a stale URL base.
	 *
	 * Legacy `/music/<slug>/` links and links minted before a release changed
	 * type still resolve through the rewrite rules; this sends them to the
	 * canonical permalink with a 301 while preserving sub-routes such as
	 * `/2/` or `/comment-page-2/`. Feeds, embeds, trackbacks, previews and
	 * non-GET requests are never redirected.
	 *
	 * @return void
	 */
	public function redirect_stale_permalink(): void {
		if ( is_admin() || ! is_singular( ReleasePostType::KEY ) ) {
			return;
		}
		if ( is_preview() || is_feed() || is_embed() || is_trackback() ) {
			return;
		}
		if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
			return;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_scalar( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return;
		}

		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		$canonical = get_permalink( $post );
		if ( ! is_string( $canonical ) || '' === $canonical ) {
			return;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Parsed and re-encoded below.
		$target      = self::redirect_target( $request_uri, $canonical, (string) $post->post_name );
		if ( null === $target ) {
			return;
		}

		wp_safe_redirect( $target, 301 );
		exit;
	}

	/**
	 * Compute the canonical redirect target for a request, or null when the
	 * request already uses the canonical base (or cannot be matched safely).
	 *
	 * Paths are compared percent-decoded so encoded non-ASCII slugs match
	 * regardless of hex case; the preserved tail is re-encoded per segment.
	 *
	 * @param string $request_uri Raw request URI (path plus optional query string).
	 * @param string $canonical   Canonical permalink from get_permalink().
	 * @param string $slug        Release post name.
	 */
	public static function redirect_target( string $request_uri, string $canonical, string $slug ): ?string {
		if ( '' === $slug || '' === $request_uri || false !== strpos( $canonical, '?' ) ) {
			return null;
		}

		$request_path   = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
		$canonical_path = (string) wp_parse_url( $canonical, PHP_URL_PATH );
		if ( '' === $request_path || '' === $canonical_path ) {
			return null;
		}

		$request_base   = untrailingslashit( rawurldecode( $request_path ) );
		$canonical_base = untrailingslashit( rawurldecode( $canonical_path ) );
		if ( $request_base === $canonical_base || 0 === strpos( $request_base, $canonical_base . '/' ) ) {
			return null;
		}

		$pattern = '#/' . preg_quote( rawurldecode( $slug ), '#' ) . '(?=/|$)#u';
		if ( 1 !== preg_match( $pattern, $request_base, $match, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}

		$tail = (string) substr( $request_base, (int) $match[0][1] + strlen( (string) $match[0][0] ) );
		if ( '' !== $tail ) {
			$tail = implode( '/', array_map( 'rawurlencode', explode( '/', $tail ) ) );
		}

		$target = user_trailingslashit( untrailingslashit( $canonical ) . $tail );
		$query  = wp_parse_url( $request_uri, PHP_URL_QUERY );
		if ( is_string( $query ) && '' !== $query ) {
			$target .= '?' . $query;
		}

		return $target;
	}

	/**
	 * Release-type slugs for a release, including ancestors of child types.
	 *
	 * @param int $release_id Release post ID.
	 * @return array<int, string>
	 */
	private function type_slugs( int $release_id ): array {
		if ( $release_id < 1 || ! function_exists( 'get_the_terms' ) ) {
			return array();
		}

		$terms = get_the_terms( $release_id, self::TAXONOMY );
		if ( ! is_array( $terms ) ) {
			return array();
		}

		$slugs = array();
		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term ) {
				continue;
			}
			$slugs[] = (string) $term->slug;
			$parent  = (int) $term->parent;
			$depth   = 0;
			while ( $parent > 0 && $depth < self::MAX_TERM_DEPTH && function_exists( 'get_term' ) ) {
				$ancestor = get_term( $parent, self::TAXONOMY );
				if ( ! $ancestor instanceof WP_Term ) {
					break;
				}
				$slugs[] = (string) $ancestor->slug;
				$parent  = (int) $ancestor->parent;
				++$depth;
			}
		}

		return array_values( array_unique( $slugs ) );
	}

	/**
	 * Registered permastruct for a name, or an empty string when unavailable.
	 *
	 * @param string $name Permastruct name.
	 */
	private function extra_permastruct( string $name ): string {
		$rewrite = isset( $GLOBALS['wp_rewrite'] ) ? $GLOBALS['wp_rewrite'] : null;
		if ( ! is_object( $rewrite ) || ! method_exists( $rewrite, 'get_extra_permastruct' ) ) {
			return '';
		}

		$structure = $rewrite->get_extra_permastruct( $name );

		return is_string( $structure ) ? $structure : '';
	}
}
