<?php
/**
 * Public term hero block: archive headers for artists, genres, moods, labels.
 *
 * The landing surface global music platforms put at the top of every artist
 * and category page: a full-width media banner or a compact row carrying the
 * term name, a translated taxonomy eyebrow, description excerpt, release
 * count, and the shared follow control for artists. Resolves the currently
 * queried term automatically and stays silent on non-taxonomy routes so any
 * template can include it safely.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Blocks;

use ManaCore\MusicWave\Core\Blocks\Concerns\EditorPreviewCatalog;
use ManaCore\MusicWave\Core\Catalog\TermHue;
use ManaCore\MusicWave\Core\Library\LibraryButton;
use ManaCore\MusicWave\Core\Library\LibraryRepository;
use WP_Term;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TermHeroBlock {
	/** Taxonomies this block may present. */
	private const ALLOWED_TAXONOMIES = array( 'mw_artist', 'mw_genre', 'mw_mood', 'mw_label' );

	/**
	 * Register the server-rendered term hero block.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		BlockSupport::register_dynamic(
			'music-wave/term-hero',
			array( $this, 'render' ),
			array(
				'api_version' => 3,
				'supports'    => BlockSupport::appearance_tools(),
			)
		);
	}

	/**
	 * Render the term hero for the queried or pinned term.
	 *
	 * @param array<string, mixed> $attributes Block attributes from the editor.
	 *
	 * @return string Empty string off-editor when no supported term resolves,
	 *                so unrelated archives keep their templates intact.
	 */
	public function render( array $attributes = array() ): string {
		$term = $this->resolve_term( $attributes );
		if ( ! $term instanceof WP_Term ) {
			return BlockSupport::is_editor_context() ? $this->render_editor_placeholder( $attributes ) : '';
		}

		$options = $this->resolve_options( $attributes );
		$parts   = array(
			$this->media( $term, $options ),
			'<div class="mw-term-hero__content">' . implode( '', $this->content_parts( $term, $options ) ) . '</div>',
		);

		// Style variation: minimal, editorial, glass.
		$variation = \ManaCore\MusicWave\Core\Blocks\BlockSupport::style_variation( $attributes, array( 'minimal', 'editorial', 'glass' ) );

		$classes = array_filter(
			array(
				'mw-term-hero',
				'mw-term-hero--' . $options['layout'],
				'banner' === $options['layout'] ? 'mw-term-hero--size-' . $options['size'] : '',
				'' === $options['accent'] && ! $this->has_media( $term ) ? 'mw-term-hero--hue-' . TermHue::for_term( $term ) : '',
				$this->has_media( $term ) && $options['show_image'] ? '' : 'mw-term-hero--no-media',
				'compact' === $options['layout'] ? 'mw-term-hero--shape-' . $options['compact_shape'] : '',
				'' !== $variation ? 'mw-term-hero--' . $variation . ' is-style-' . $variation : '',
			)
		);

		$inline_style = '' !== $options['accent'] ? '--mw-term-accent:' . $options['accent'] . ';' : '';

		return '<section '
			. BlockSupport::wrapper_attributes( implode( ' ', $classes ), $inline_style )
			. '>' . implode( '', $parts ) . '</section>';
	}

	/**
	 * Resolve sanitized display options once per render.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return array<string, mixed>
	 */
	private function resolve_options( array $attributes ): array {
		$layout = isset( $attributes['layout'] ) ? sanitize_key( (string) $attributes['layout'] ) : 'banner';
		$layout = in_array( $layout, array( 'banner', 'compact' ), true ) ? $layout : 'banner';

		$size = isset( $attributes['size'] ) ? sanitize_key( (string) $attributes['size'] ) : 'medium';
		$size = in_array( $size, array( 'short', 'medium', 'tall' ), true ) ? $size : 'medium';

		$compact_shape = isset( $attributes['imageShape'] ) ? sanitize_key( (string) $attributes['imageShape'] ) : 'rounded';
		$compact_shape = in_array( $compact_shape, array( 'rounded', 'square', 'circle' ), true ) ? $compact_shape : 'rounded';

		$description_length = isset( $attributes['descriptionLength'] ) ? absint( $attributes['descriptionLength'] ) : 0;
		$description_length = min( 120, $description_length );

		$accent = isset( $attributes['accentColor'] ) ? sanitize_hex_color( (string) $attributes['accentColor'] ) : '';
		$accent = is_string( $accent ) ? $accent : '';

		return array(
			'layout'             => $layout,
			'size'               => $size,
			'compact_shape'      => $compact_shape,
			'description_length' => $description_length,
			'accent'             => $accent,
			'show_image'         => ! isset( $attributes['showImage'] ) || (bool) $attributes['showImage'],
			'show_eyebrow'       => ! isset( $attributes['showEyebrow'] ) || (bool) $attributes['showEyebrow'],
			'show_name'          => ! isset( $attributes['showName'] ) || (bool) $attributes['showName'],
			'show_description'   => ! isset( $attributes['showDescription'] ) || (bool) $attributes['showDescription'],
			'show_count'         => ! isset( $attributes['showCount'] ) || (bool) $attributes['showCount'],
			'show_follow'        => ! isset( $attributes['showFollowButton'] ) || (bool) $attributes['showFollowButton'],
		);
	}

	/**
	 * Resolve the hero term from pinned attributes or the current query.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return WP_Term|null
	 */
	private function resolve_term( array $attributes ): ?WP_Term {
		$sample_id = EditorPreviewCatalog::requested_sample_id();
		if ( $sample_id > 0 ) {
			foreach ( self::ALLOWED_TAXONOMIES as $candidate ) {
				$sample_term = get_term( $sample_id, $candidate );
				if ( $sample_term instanceof WP_Term ) {
					return $sample_term;
				}
			}
		}

		$term_id = isset( $attributes['termId'] ) ? absint( $attributes['termId'] ) : 0;

		if ( $term_id > 0 ) {
			$taxonomy   = isset( $attributes['taxonomy'] ) ? sanitize_key( (string) $attributes['taxonomy'] ) : '';
			$candidates = '' !== $taxonomy && in_array( $taxonomy, self::ALLOWED_TAXONOMIES, true )
				? array( $taxonomy )
				: self::ALLOWED_TAXONOMIES;

			foreach ( $candidates as $candidate ) {
				$term = get_term( $term_id, $candidate );
				if ( $term instanceof WP_Term ) {
					return $term;
				}
			}

			return null;
		}

		$queried = function_exists( 'get_queried_object' ) ? get_queried_object() : null;
		if ( $queried instanceof WP_Term && in_array( $queried->taxonomy, self::ALLOWED_TAXONOMIES, true ) ) {
			return $queried;
		}

		if ( BlockSupport::is_editor_context() && function_exists( 'get_terms' ) ) {
			$taxonomy   = isset( $attributes['taxonomy'] ) ? sanitize_key( (string) $attributes['taxonomy'] ) : '';
			$candidates = '' !== $taxonomy && in_array( $taxonomy, self::ALLOWED_TAXONOMIES, true )
				? array( $taxonomy )
				: self::ALLOWED_TAXONOMIES;
			foreach ( $candidates as $candidate_tax ) {
				$terms = get_terms(
					array(
						'taxonomy'   => $candidate_tax,
						'hide_empty' => false,
						'number'     => 1,
						'orderby'    => 'count',
						'order'      => 'DESC',
					)
				);
				if ( is_array( $terms ) && ! empty( $terms[0] ) && $terms[0] instanceof WP_Term ) {
					return $terms[0];
				}
			}
		}

		return null;
	}

	/**
	 * Media layer: cover image when available, curated hue otherwise.
	 *
	 * @param WP_Term              $term    Resolved term.
	 * @param array<string, mixed> $options Resolved options.
	 * @return string
	 */
	private function media( WP_Term $term, array $options ): string {
		$hue_classes = '' !== $options['accent'] ? '' : ' mw-term-media mw-term-hue-' . TermHue::for_term( $term );

		if ( ! $options['show_image'] ) {
			return '<div class="mw-term-hero__media' . $hue_classes . '" aria-hidden="true"></div>';
		}

		$image_id = $this->image_id( $term );
		if ( '' === $image_id ) {
			return '<div class="mw-term-hero__media' . $hue_classes . '" aria-hidden="true"></div>';
		}

		$is_compact = 'compact' === $options['layout'];
		// A compact row renders a ~5rem thumbnail, so ship the medium size and
		// tell the browser the real slot width instead of downloading the full
		// original (Core Web Vitals, no layout shift).
		$image = wp_get_attachment_image(
			absint( $image_id ),
			$is_compact ? 'medium' : 'full',
			false,
			array(
				'class'    => 'mw-term-hero__image',
				'alt'      => '',
				'loading'  => $is_compact ? 'lazy' : 'eager',
				'decoding' => 'async',
				'sizes'    => $is_compact ? '(max-width: 48rem) 3.5rem, 5.25rem' : '(max-width: 48rem) 100vw, 1200px',
			)
		);

		return '<div class="mw-term-hero__media' . $hue_classes . '">'
			. $image
			. '</div><div class="mw-term-hero__scrim" aria-hidden="true"></div>';
	}

	/**
	 * Whether the term carries usable cover media.
	 */
	private function has_media( WP_Term $term ): bool {
		return '' !== $this->image_id( $term );
	}

	/**
	 * Cover attachment ID for a term (artists carry one today).
	 */
	private function image_id( WP_Term $term ): string {
		if ( 'mw_artist' !== $term->taxonomy ) {
			return '';
		}

		return (string) absint( (string) get_term_meta( $term->term_id, 'mw_artist_image_id', true ) );
	}

	/**
	 * Translated eyebrow label for a taxonomy.
	 *
	 * Class constants cannot hold `__()` results, so translation happens at
	 * the point of use instead of in a lookup table.
	 */
	private function taxonomy_eyebrow( string $taxonomy ): string {
		switch ( $taxonomy ) {
			case 'mw_artist':
				return __( 'هنرمند', 'music-wave-core' );
			case 'mw_genre':
				return __( 'سبک', 'music-wave-core' );
			case 'mw_mood':
				return __( 'حال‌وهوا', 'music-wave-core' );
			case 'mw_label':
				return __( 'برچسب', 'music-wave-core' );
		}

		return __( 'مرور', 'music-wave-core' );
	}

	/**
	 * Content parts: eyebrow, name, count, description, follow.
	 *
	 * @param WP_Term              $term    Resolved term.
	 * @param array<string, mixed> $options Resolved options.
	 * @return array<int, string>
	 */
	private function content_parts( WP_Term $term, array $options ): array {
		$parts = array();

		if ( $options['show_eyebrow'] ) {
			$eyebrow = $this->taxonomy_eyebrow( $term->taxonomy );

			$parts[] = '<p class="mw-term-hero__eyebrow">' . esc_html( $eyebrow ) . '</p>';
		}

		if ( $options['show_name'] ) {
			$name = '' !== $term->name ? $term->name : __( 'بدون عنوان', 'music-wave-core' );

			$parts[] = '<h1 class="mw-term-hero__name">' . esc_html( $name ) . '</h1>';
		}

		if ( $options['show_count'] && (int) $term->count > 0 ) {
			$parts[] = '<p class="mw-term-hero__count">' . esc_html(
				sprintf(
					/* translators: %s: number of published releases. */
					_n( 'انتشار %s', 'انتشار %s', (int) $term->count, 'music-wave-core' ),
					number_format_i18n( (int) $term->count )
				)
			) . '</p>';
		}

		$description = $this->description( $term );
		if ( $options['show_description'] && '' !== $description ) {
			if ( $options['description_length'] >= 5 ) {
				$description = wp_trim_words( wp_strip_all_tags( $description ), $options['description_length'] );
				$parts[]     = '<p class="mw-term-hero__description">' . esc_html( $description ) . '</p>';
			} else {
				$parts[] = '<div class="mw-term-hero__description">' . wpautop( wp_kses_post( $description ) ) . '</div>';
			}
		}

		if ( $options['show_follow'] && 'mw_artist' === $term->taxonomy ) {
			$button = LibraryButton::markup(
				LibraryRepository::TYPE_ARTIST,
				(int) $term->term_id,
				array( 'style' => 'outline' )
			);
			if ( '' !== $button ) {
				$parts[] = '<div class="mw-term-hero__follow">' . $button . '</div>';
			}
		}

		return $parts;
	}

	/**
	 * Description source: artist biography meta first, then the term description.
	 */
	private function description( WP_Term $term ): string {
		if ( 'mw_artist' === $term->taxonomy ) {
			$biography = (string) get_term_meta( $term->term_id, 'mw_artist_biography', true );
			if ( '' !== $biography ) {
				return $biography;
			}
		}

		$description = function_exists( 'term_description' ) ? (string) term_description( $term->term_id ) : '';

		return trim( wp_strip_all_tags( $description ) );
	}

	/**
	 * Editor-only placeholder so ServerSideRender never shows an error notice.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private function render_editor_placeholder( array $attributes ): string {
		$label = __( 'معرفی اصطلاح', 'music-wave-core' );
		$help  = isset( $attributes['termId'] ) && absint( $attributes['termId'] ) > 0
			? __( 'عبارت انتخابی یافت نشد. طبقه‌بندی و شناسهٔ عبارت را در نوار کناری بلوک بررسی کنید.', 'music-wave-core' )
			: __( 'این سربرگ به‌طور خودکار در آرشیوهای هنرمند، ژانر، حال‌وهوا و برچسب ارائه می‌شود.', 'music-wave-core' );

		return BlockSupport::editor_placeholder( $label, $help, 'mw-term-hero mw-term-hero--placeholder' );
	}
}
