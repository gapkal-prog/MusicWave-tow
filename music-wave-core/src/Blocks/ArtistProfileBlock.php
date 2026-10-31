<?php
/**
 * Public artist profile block (`music-wave/artist-profile`).
 *
 * Clean Architecture / OOP / DRY:
 * - Resolves the active `mw_artist` taxonomy term automatically across artist
 *   archives, single release templates, explicit `termId` pins, and Site Editor
 *   canvas previews.
 * - Uses ONE canonical `render_profile_card()` template for both real artists
 *   and editor sample previews so every setting (`layout`, `imageShape`,
 *   `libraryButtonStyle`, `showBio`, `showLink`, `showReleaseCount`,
 *   `accentColor`, style variation) behaves 1:1 across Editor and Frontend.
 * - Emits the avatar frame only for artists that really have a photo, so a
 *   missing image can never masquerade as a portrait.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Blocks;

use ManaCore\MusicWave\Core\Blocks\Concerns\ActionButtonStyle;
use ManaCore\MusicWave\Core\Blocks\Concerns\EditorPreviewCatalog;
use ManaCore\MusicWave\Core\Blocks\Concerns\ReleaseResolver;
use ManaCore\MusicWave\Core\Catalog\ReleasePostType;
use ManaCore\MusicWave\Core\Library\LibraryButton;
use ManaCore\MusicWave\Core\Library\LibraryRepository;
use WP_Term;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ArtistProfileBlock {
	/**
	 * Register the server-rendered public artist profile block.
	 */
	public function register(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		BlockSupport::register_dynamic(
			'music-wave/artist-profile',
			array( $this, 'render' ),
			array(
				'api_version' => 3,
				'supports'    => BlockSupport::appearance_tools(),
			)
		);
	}

	/**
	 * Render `music-wave/artist-profile` cleanly across all layouts and viewports.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render( array $attributes = array() ): string {
		$term = $this->resolve_term( $attributes );
		if ( ! $term instanceof WP_Term ) {
			return BlockSupport::is_editor_context() ? $this->render_editor_placeholder( $attributes ) : '';
		}

		$archive_link = get_term_link( $term );
		$canonical    = trim( (string) get_term_meta( $term->term_id, 'mw_artist_canonical_url', true ) );
		$profile_url  = ! is_wp_error( $archive_link ) && is_string( $archive_link ) && '' !== $archive_link
			? $archive_link
			: $canonical;

		$biography = trim( (string) get_term_meta( $term->term_id, 'mw_artist_biography', true ) );
		if ( '' === $biography && '' !== trim( $term->description ) ) {
			$biography = trim( $term->description );
		}

		$profile = array(
			'term_id'       => (int) $term->term_id,
			'name'          => (string) $term->name,
			'biography'     => $biography,
			'image_id'      => absint( get_term_meta( $term->term_id, 'mw_artist_image_id', true ) ),
			'profile_url'   => $profile_url,
			'external_url'  => $canonical,
			'release_count' => max( 0, (int) $term->count ),
			'is_sample'     => false,
		);

		return $this->render_profile_card( $profile, $attributes );
	}

	/**
	 * Shared 1:1 DOM builder used for both live `mw_artist` terms and Editor sample previews.
	 *
	 * @param array<string, mixed> $profile    Normalized artist data.
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private function render_profile_card( array $profile, array $attributes ): string {
		$layout     = self::resolve_layout( $attributes );
		$is_compact = 'compact' === $layout;

		$variation = BlockSupport::style_variation( $attributes, array( 'editorial', 'minimal', 'glass' ) );
		$variation = '' !== $variation ? $variation : 'editorial';
		$shape     = BlockSupport::key_attribute( $attributes, 'imageShape', array( 'square', 'rounded', 'circle' ), 'circle' );
		$size      = BlockSupport::key_attribute( $attributes, 'imageSize', array( 'thumbnail', 'medium', 'medium_large', 'large' ), 'medium' );
		$lib_style = BlockSupport::key_attribute( $attributes, 'libraryButtonStyle', array( 'button', 'heart' ), 'button' );

		$show_image   = ! isset( $attributes['showImage'] ) || (bool) $attributes['showImage'];
		$show_title   = ! isset( $attributes['showTitle'] ) || (bool) $attributes['showTitle'];
		$show_bio     = ! $is_compact && ( ! isset( $attributes['showBio'] ) || (bool) $attributes['showBio'] );
		$show_link    = ! $is_compact && ( ! isset( $attributes['showLink'] ) || (bool) $attributes['showLink'] );
		$show_lib     = ! isset( $attributes['showLibraryButton'] ) || (bool) $attributes['showLibraryButton'];
		$show_count   = ! empty( $attributes['showReleaseCount'] );
		$bio_length   = isset( $attributes['bioLength'] ) ? min( 200, absint( $attributes['bioLength'] ) ) : 0;
		$accent_color = $this->sanitize_color( $attributes['accentColor'] ?? '' );

		$name        = (string) $profile['name'];
		$profile_url = (string) $profile['profile_url'];
		$image_id    = (int) $profile['image_id'];

		/*
		 * An artist without a photo simply has no avatar: the frame owns the size,
		 * ratio and accent ring, so a monogram stand-in would only pretend to be a
		 * portrait. `imageShape` lives on the root class instead of a second frame
		 * modifier, keeping one owner per rule.
		 */
		$media_html = '';
		if ( $show_image && $image_id > 0 ) {
			$img = wp_get_attachment_image(
				$image_id,
				$size,
				false,
				array(
					'class'   => 'mw-artist-profile__image',
					'loading' => 'lazy',
				)
			);

			if ( '' !== $img ) {
				$media_html = '' !== $profile_url
					? '<div class="mw-artist-profile__media"><a class="mw-artist-profile__avatar-link" href="' . esc_url( $profile_url ) . '" tabindex="-1" aria-hidden="true">' . $img . '</a></div>'
					: '<div class="mw-artist-profile__media">' . $img . '</div>';
			}
		}

		$eyebrow_html = 'spotlight' === $layout
			? '<span class="mw-artist-profile__eyebrow">' . esc_html__( 'هنرمند برگزیده', 'music-wave-core' ) . '</span>'
			: '';

		$title_html = '';
		if ( $show_title && '' !== $name ) {
			$heading_text = '' !== $profile_url
				? '<a class="mw-artist-profile__title-link" href="' . esc_url( $profile_url ) . '">' . esc_html( $name ) . '</a>'
				: esc_html( $name );
			$tag          = $is_compact ? 'span' : 'h2';
			$title_html   = '<' . $tag . ' class="mw-artist-profile__name" dir="auto">' . $heading_text . '</' . $tag . '>';
		}

		$count_html = '';
		if ( $show_count ) {
			$count_text = sprintf(
				/* translators: %s: localized number of published releases. */
				__( '%s انتشار', 'music-wave-core' ),
				number_format_i18n( max( 0, (int) $profile['release_count'] ) )
			);
			$count_html = '<span class="mw-artist-profile__count">' . esc_html( $count_text ) . '</span>';
		}

		$bio_html = '';
		$raw_bio  = trim( (string) $profile['biography'] );
		if ( $show_bio && '' !== $raw_bio ) {
			if ( $bio_length > 0 ) {
				$raw_bio   = wp_trim_words( wp_strip_all_tags( $raw_bio ), $bio_length );
				$bio_inner = '<p>' . esc_html( $raw_bio ) . '</p>';
			} else {
				$bio_inner = wp_kses_post( wpautop( $raw_bio ) );
			}
			$bio_html = '<div class="mw-artist-profile__bio" dir="auto">' . $bio_inner . '</div>';
		}

		$actions = array();
		if ( $show_lib ) {
			$follow_icon = isset( $attributes['followIcon'] ) && is_scalar( $attributes['followIcon'] )
				? trim( (string) $attributes['followIcon'] )
				: '';
			$btn_opts    = array(
				'style'   => $lib_style,
				'variant' => 'compact' === $layout ? 'ghost' : 'surface',
				'size'    => $is_compact ? 'sm' : 'md',
				'compact' => $is_compact,
				'icon'    => $follow_icon,
			);
			if ( ! empty( $profile['is_sample'] ) ) {
				$actions[] = LibraryButton::placeholder_markup(
					LibraryRepository::TYPE_ARTIST,
					$btn_opts
				);
			} elseif ( (int) $profile['term_id'] > 0 ) {
				$lib_btn = LibraryButton::markup(
					LibraryRepository::TYPE_ARTIST,
					(int) $profile['term_id'],
					$btn_opts
				);
				if ( '' !== $lib_btn ) {
					$actions[] = $lib_btn;
				}
			}
		}

		$cta_url = '' !== (string) $profile['external_url'] ? (string) $profile['external_url'] : $profile_url;
		if ( $show_link && '' !== $cta_url ) {
			$cta_label   = BlockSupport::text_attribute( $attributes, 'ctaLabel', __( 'مشاهده آثار هنرمند', 'music-wave-core' ) );
			$is_ext      = '' !== (string) $profile['external_url'] && (string) $profile['external_url'] === $cta_url;
			$rel_attr    = $is_ext ? ' rel="noopener noreferrer" target="_blank"' : '';
			$cta_btn_cls = ActionButtonStyle::button_classes(
				array(
					'style'   => 'button',
					'variant' => 'outline',
					'size'    => 'sm',
					'shape'   => 'pill',
				),
				'mw-artist-profile__link',
				ActionButtonStyle::MODE_BUTTON,
				ActionButtonStyle::VARIANT_OUTLINE
			);
			$actions[]   = '<a class="' . esc_attr( $cta_btn_cls ) . '" href="' . esc_url( $cta_url ) . '"' . $rel_attr . '>'
				. '<span>' . esc_html( $cta_label ) . '</span>'
				. '</a>';
		}

		$actions_html = ! empty( $actions )
			? '<div class="mw-artist-profile__actions">' . implode( '', $actions ) . '</div>'
			: '';

		$header_meta = '' !== $title_html || '' !== $count_html || '' !== $eyebrow_html
			? '<div class="mw-artist-profile__heading">' . $eyebrow_html . '<div class="mw-artist-profile__title-row">' . $title_html . $count_html . '</div></div>'
			: '';

		$body_html = '<div class="mw-artist-profile__body">'
			. $header_meta
			. $bio_html
			. $actions_html
			. '</div>';

		if ( '' === $media_html && '' === $header_meta && '' === $bio_html && '' === $actions_html ) {
			return '';
		}

		$classes = array(
			'mw-artist-profile',
			'mw-artist-profile--' . $layout,
			'mw-artist-profile--shape-' . $shape,
			'mw-artist-profile--' . $variation,
		);
		if ( '' !== $accent_color ) {
			$classes[] = 'mw-artist-profile--accent';
		}

		$inline_style = '' !== $accent_color ? '--mw-artist-accent:' . $accent_color . ';' : '';

		return '<section ' . BlockSupport::wrapper_attributes( implode( ' ', $classes ), $inline_style ) . '>'
			. $media_html
			. $body_html
			. '</section>';
	}

	/**
	 * Resolve the canonical layout slug for the profile card.
	 *
	 * `layout` is the single source of truth, so an explicit `card`, `spotlight`
	 * or `compact` choice always wins. The `compact` boolean is a legacy alias
	 * kept for blocks saved before the layout picker existed: it only promotes the
	 * default `list` rhythm, never overrides a real selection. The retired
	 * `slider` slug maps onto `spotlight` so old content keeps its featured banner.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string One of `compact`, `list`, `card`, `spotlight`.
	 */
	private static function resolve_layout( array $attributes ): string {
		$layout = isset( $attributes['layout'] ) && is_scalar( $attributes['layout'] )
			? sanitize_key( (string) $attributes['layout'] )
			: '';
		$layout = '' !== $layout ? $layout : 'list';

		if ( 'slider' === $layout ) {
			$layout = 'spotlight';
		}
		if ( 'list' === $layout && ! empty( $attributes['compact'] ) ) {
			$layout = 'compact';
		}

		return in_array( $layout, array( 'compact', 'list', 'card', 'spotlight' ), true ) ? $layout : 'list';
	}

	/**
	 * Render a realistic 1:1 sample artist profile in the Block/Site Editor when no term is bound.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private function render_editor_placeholder( array $attributes ): string {
		return $this->render_profile_card(
			array(
				'term_id'       => 0,
				'name'          => __( 'عنوان هنرمند', 'music-wave-core' ),
				'biography'     => __( 'معرفی کوتاه هنرمند و فهرست آثار منتشرشده در کاتالوگ.', 'music-wave-core' ),
				'image_id'      => 0,
				'profile_url'   => '#artist-preview',
				'external_url'  => '',
				'release_count' => 12,
				'is_sample'     => true,
			),
			$attributes
		);
	}

	/**
	 * Resolve target `mw_artist` term from explicit pin, queried artist archive, or active release.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private function resolve_term( array $attributes ): ?WP_Term {
		$sample_id = EditorPreviewCatalog::requested_sample_id();
		if ( $sample_id > 0 ) {
			if ( ReleasePostType::KEY === get_post_type( $sample_id ) ) {
				$sample_artists = wp_get_post_terms( $sample_id, 'mw_artist', array( 'fields' => 'all' ) );
				if ( is_array( $sample_artists ) && ! empty( $sample_artists[0] ) && $sample_artists[0] instanceof WP_Term ) {
					return $sample_artists[0];
				}
			}
			$sample_term = get_term( $sample_id, 'mw_artist' );
			if ( $sample_term instanceof WP_Term ) {
				return $sample_term;
			}
		}

		$term_id = isset( $attributes['termId'] ) ? absint( $attributes['termId'] ) : 0;
		if ( $term_id > 0 ) {
			$term = get_term( $term_id, 'mw_artist' );
			return $term instanceof WP_Term ? $term : null;
		}

		$queried = get_queried_object();
		if ( $queried instanceof WP_Term && 'mw_artist' === $queried->taxonomy ) {
			return $queried;
		}

		$post_id = function_exists( 'get_the_ID' ) ? absint( get_the_ID() ) : 0;
		if ( $post_id < 1 && $queried instanceof \WP_Post ) {
			$post_id = absint( $queried->ID );
		}
		if ( $post_id < 1 ) {
			$post_id = ReleaseResolver::resolve( $attributes );
		}
		if ( $post_id > 0 && ReleasePostType::KEY === get_post_type( $post_id ) ) {
			$artists = wp_get_post_terms( $post_id, 'mw_artist', array( 'fields' => 'all' ) );
			if ( is_array( $artists ) && ! empty( $artists[0] ) && $artists[0] instanceof WP_Term ) {
				return $artists[0];
			}
		}

		if ( BlockSupport::is_editor_context() && function_exists( 'get_terms' ) ) {
			$artist_terms = get_terms(
				array(
					'taxonomy'   => 'mw_artist',
					'hide_empty' => false,
					'number'     => 1,
					'orderby'    => 'count',
					'order'      => 'DESC',
				)
			);
			if ( is_array( $artist_terms ) && ! empty( $artist_terms[0] ) && $artist_terms[0] instanceof WP_Term ) {
				return $artist_terms[0];
			}
		}

		return null;
	}

	/**
	 * Sanitize a hex color string strictly.
	 *
	 * @param mixed $raw Raw color attribute.
	 */
	private function sanitize_color( $raw ): string {
		if ( ! is_scalar( $raw ) ) {
			return '';
		}
		$value = trim( (string) $raw );
		return 1 === preg_match( '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value ) ? strtolower( $value ) : '';
	}
}
