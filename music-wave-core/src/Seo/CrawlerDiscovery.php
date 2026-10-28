<?php
/**
 * Search Engine & AI Search Crawler Discovery (`robots.txt` + `/llms.txt`).
 *
 * Optimizes indexing efficiency for Googlebot, Bingbot, Applebot, and AI search
 * crawlers (`OAI-SearchBot`, `ChatGPT-User`, `PerplexityBot`, `ClaudeBot`,
 * `Google-Extended`) while preventing crawl budget waste on parameterized URLs.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Seo;

use ManaCore\MusicWave\Core\Catalog\ReleasePostType;
use ManaCore\MusicWave\Core\Catalog\ReleaseVisibility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CrawlerDiscovery {

	public const QUERY_VAR = 'mw_llms_txt';

	private const AI_AND_SEARCH_BOTS = array(
		'Googlebot',
		'Googlebot-Image',
		'Bingbot',
		'Applebot',
		'OAI-SearchBot',
		'ChatGPT-User',
		'GPTBot',
		'PerplexityBot',
		'ClaudeBot',
		'Google-Extended',
	);

	/** @var ReleaseVisibility */
	private $visibility;

	public function __construct( ?ReleaseVisibility $visibility = null ) {
		$this->visibility = null !== $visibility ? $visibility : new ReleaseVisibility();
	}

	public function register(): void {
		add_action( 'init', array( $this, 'register_rewrite' ) );
		add_filter( 'query_vars', array( $this, 'register_query_var' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render_llms_txt' ), 1 );
		add_filter( 'robots_txt', array( $this, 'enhance_robots_txt' ), 20, 2 );
	}

	public function register_rewrite(): void {
		if ( function_exists( 'add_rewrite_rule' ) ) {
			add_rewrite_rule( '^llms\.txt$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
		}
	}

	/**
	 * @param array<int, string> $vars Registered query variables.
	 * @return array<int, string>
	 */
	public function register_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Enhance virtual `robots.txt` when the site is public.
	 *
	 * @param mixed           $output    Generated `robots.txt` text.
	 * @param bool|int|string $is_public Site visibility flag (`blog_public` option).
	 */
	public function enhance_robots_txt( $output, $is_public = '1' ): string {
		$output = is_scalar( $output ) ? (string) $output : '';
		if ( '0' === (string) $is_public || false === $is_public ) {
			return $output;
		}

		$lines   = array( rtrim( $output ), '' );
		$lines[] = '# MusicWave Search & AI Discovery';
		$lines[] = 'Disallow: /*?mw_sort=';
		$lines[] = 'Disallow: /*?add-to-cart=';
		$lines[] = 'Disallow: /*?s=';
		$lines[] = '';

		foreach ( self::AI_AND_SEARCH_BOTS as $bot ) {
			$lines[] = 'User-agent: ' . $bot;
			$lines[] = 'Allow: /';
			$lines[] = 'Disallow: /wp-admin/';
			$lines[] = 'Allow: /wp-admin/admin-ajax.php';
			$lines[] = '';
		}

		$sitemap_url = function_exists( 'get_sitemap_url' ) ? get_sitemap_url( 'index' ) : '';
		if ( is_string( $sitemap_url ) && '' !== $sitemap_url && false === strpos( $output, 'Sitemap:' ) ) {
			$lines[] = 'Sitemap: ' . esc_url_raw( $sitemap_url );
		}
		$lines[] = '# AI Catalog Index: ' . esc_url_raw( home_url( '/llms.txt' ) );

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Serve `/llms.txt` when requested via pretty permalink or query var.
	 */
	public function maybe_render_llms_txt(): void {
		$flag = function_exists( 'get_query_var' ) ? (string) get_query_var( self::QUERY_VAR ) : '';
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

		if ( '1' !== $flag && '/llms.txt' !== $path ) {
			return;
		}

		if ( ! headers_sent() ) {
			status_header( 200 );
			header( 'Content-Type: text/markdown; charset=utf-8' );
			header( 'X-Robots-Tag: index, follow' );
		}

		echo $this->build_llms_markdown(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text/markdown response body with sanitized titles and URLs.
		if ( ! defined( 'MUSIC_WAVE_TESTING' ) || ! MUSIC_WAVE_TESTING ) {
			exit;
		}
	}

	/**
	 * Build structured `llms.txt` Markdown index of public music catalog entities.
	 */
	public function build_llms_markdown(): string {
		if ( '0' === (string) get_option( 'blog_public', '1' ) ) {
			return "# Private Site\n";
		}

		$site_name = trim( (string) get_bloginfo( 'name' ) );
		if ( '' === $site_name ) {
			$site_name = 'MusicWave';
		}
		$tagline = trim( (string) get_bloginfo( 'description' ) );

		$out   = array();
		$out[] = '# ' . $site_name;
		if ( '' !== $tagline ) {
			$out[] = '';
			$out[] = '> ' . $tagline;
		}
		$out[] = '';
		$out[] = '## Navigation';
		$out[] = '- [Home](' . esc_url_raw( home_url( '/' ) ) . ')';

		$archive_link = function_exists( 'get_post_type_archive_link' ) ? get_post_type_archive_link( ReleasePostType::KEY ) : '';
		if ( is_string( $archive_link ) && '' !== $archive_link ) {
			$out[] = '- [Music Releases Catalog](' . esc_url_raw( $archive_link ) . ')';
		}
		$sitemap_url = function_exists( 'get_sitemap_url' ) ? get_sitemap_url( 'index' ) : '';
		if ( is_string( $sitemap_url ) && '' !== $sitemap_url ) {
			$out[] = '- [XML Sitemap](' . esc_url_raw( $sitemap_url ) . ')';
		}

		if ( function_exists( 'get_posts' ) ) {
			$release_ids = get_posts(
				array(
					'post_type'      => ReleasePostType::KEY,
					'post_status'    => 'publish',
					'posts_per_page' => 25,
					'fields'         => 'ids',
					'orderby'        => 'date',
					'order'          => 'DESC',
				)
			);
			if ( is_array( $release_ids ) && ! empty( $release_ids ) ) {
				$release_lines = array();
				foreach ( $release_ids as $raw_id ) {
					$id = absint( $raw_id );
					if ( $id < 1 || ! $this->visibility->is_public( $id ) ) {
						continue;
					}
					$url   = get_permalink( $id );
					$title = wp_strip_all_tags( (string) get_the_title( $id ) );
					if ( is_string( $url ) && '' !== $url && '' !== $title ) {
						$release_lines[] = '- [' . $title . '](' . esc_url_raw( $url ) . ')';
					}
				}
				if ( ! empty( $release_lines ) ) {
					$out[] = '';
					$out[] = '## Recent Public Releases';
					$out   = array_merge( $out, $release_lines );
				}
			}
		}

		if ( function_exists( 'get_terms' ) ) {
			$artists = get_terms(
				array(
					'taxonomy'   => 'mw_artist',
					'hide_empty' => true,
					'number'     => 25,
				)
			);
			if ( is_array( $artists ) && ! empty( $artists ) ) {
				$artist_lines = array();
				foreach ( $artists as $artist ) {
					if ( ! $artist instanceof \WP_Term ) {
						continue;
					}
					$url = get_term_link( $artist );
					if ( is_string( $url ) && '' !== $url ) {
						$artist_lines[] = '- [' . wp_strip_all_tags( $artist->name ) . '](' . esc_url_raw( $url ) . ')';
					}
				}
				if ( ! empty( $artist_lines ) ) {
					$out[] = '';
					$out[] = '## Artists';
					$out   = array_merge( $out, $artist_lines );
				}
			}
		}

		return implode( "\n", $out ) . "\n";
	}
}
