<?php
/**
 * Shared release-card markup.
 *
 * The release card (`mw-release-shelf__item`) used to be written out three
 * times: `ReleaseBlocks::related_card()`, `ListeningBlocks::history_card()`
 * and the theme's release shelf. The artwork wrapper alone was byte-identical
 * in all three. This class owns that skeleton once.
 *
 * The split rule is deliberate: this class owns the markup and the parts that
 * were identical everywhere, while each caller keeps its own *policy* — the
 * play-overlay fallback, the thumbnail loading hints, the meta line and the
 * in-body preview button. Those genuinely differ per surface, so they arrive
 * as resolved strings rather than as configuration this class would have to
 * interpret.
 *
 * Markup parity is pinned by `tests/card-markup.php`; any change here has to
 * be an intentional, re-recorded change to the rendered card.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ReleaseCard {

	/**
	 * First character of a title, used as the artwork placeholder.
	 *
	 * Multibyte-safe so Persian titles yield a real glyph rather than a broken
	 * partial character.
	 */
	public static function initial( string $title ): string {
		return function_exists( 'mb_substr' ) ? (string) mb_substr( $title, 0, 1 ) : (string) substr( $title, 0, 1 );
	}

	/**
	 * Presentation data for one release.
	 *
	 * Returns an empty array when the release cannot be linked, which is the
	 * signal callers already use to skip a card.
	 *
	 * @return array{id:int,link:string,title:string,artist:string,initial:string}|array{}
	 */
	public static function presentation_data( int $release_id ): array {
		$release_id = absint( $release_id );
		$link       = get_permalink( $release_id );
		if ( $release_id < 1 || ! is_string( $link ) || '' === $link ) {
			return array();
		}

		$title   = (string) get_the_title( $release_id );
		$artists = wp_get_post_terms( $release_id, 'mw_artist', array( 'fields' => 'names' ) );
		$artist  = is_array( $artists ) && ! empty( $artists ) ? implode( ', ', $artists ) : '';

		return array(
			'id'      => $release_id,
			'link'    => $link,
			'title'   => $title,
			'artist'  => $artist,
			'initial' => self::initial( $title ),
		);
	}

	/**
	 * The artwork unit: linked cover, initial placeholder, play overlay.
	 *
	 * @param array{id:int,link:string,title:string,artist:string,initial:string} $data    Presentation data.
	 * @param array<string, mixed>                                                $options {
	 *     Card options.
	 *
	 *     @type string               $shape            Art shape modifier: square|circle|landscape|portrait.
	 *     @type array<string,string> $image_attributes Extra `get_the_post_thumbnail()` attributes, merged
	 *                                                  after the fixed class and alt. Callers use this for
	 *                                                  their own loading / fetchpriority / decoding hints.
	 *     @type string               $open_label       Already-translated accessible label for the cover link.
	 *     @type string               $overlay          Resolved play-overlay HTML, spliced in verbatim.
	 * }
	 */
	public static function artwork( array $data, array $options = array() ): string {
		$release_id       = isset( $data['id'] ) ? (int) $data['id'] : 0;
		$link             = isset( $data['link'] ) ? (string) $data['link'] : '';
		$initial          = isset( $data['initial'] ) ? (string) $data['initial'] : '';
		$shape            = isset( $options['shape'] ) ? (string) $options['shape'] : 'square';
		$image_attributes = isset( $options['image_attributes'] ) && is_array( $options['image_attributes'] ) ? $options['image_attributes'] : array();
		$open_label       = isset( $options['open_label'] ) ? (string) $options['open_label'] : '';
		$overlay          = isset( $options['overlay'] ) ? (string) $options['overlay'] : '';

		$image = get_the_post_thumbnail(
			$release_id,
			'medium_large',
			array_merge(
				array(
					'class' => 'mw-release-shelf__image',
					'alt'   => '',
				),
				$image_attributes
			)
		);

		return '<div class="mw-release-shelf__artwrap"><a class="mw-release-shelf__art mw-release-shelf__art--' . esc_attr( $shape ) . '" href="' . esc_url( $link ) . '" aria-label="' . esc_attr( $open_label ) . '">' . ( '' !== $image ? $image : '<span class="mw-release-shelf__placeholder" aria-hidden="true">' . esc_html( $initial ) . '</span>' ) . '</a>' . $overlay . '</div>';
	}

	/**
	 * Translate block/shelf attributes into the card options this class renders.
	 *
	 * One mapping feeds both card producers — the PHP shelf list and the
	 * `music-wave/release-card` block used inside a Query Loop — so a given
	 * attribute set can only ever produce one card.
	 *
	 * @param array<string, mixed> $attributes Block or shelf attributes.
	 * @param int                  $release_id Release being rendered.
	 * @return array<string, mixed>
	 */
	public static function options_from_attributes( array $attributes, int $release_id ): array {
		$release_id = absint( $release_id );
		$shape      = isset( $attributes['imageShape'] ) ? sanitize_key( (string) $attributes['imageShape'] ) : 'square';
		$shape      = in_array( $shape, array( 'square', 'landscape', 'portrait', 'circle' ), true ) ? $shape : 'square';
		$title      = (string) get_the_title( $release_id );

		/* translators: %s: music release title. */
		$open_label = sprintf( __( 'باز کردن %s', 'music-wave-core' ), $title );

		$overlay = '';
		if ( ! isset( $attributes['showPlayButton'] ) || false !== $attributes['showPlayButton'] ) {
			$overlay = apply_filters( 'music_wave_card_play_button', '', $release_id, 'mw-release-shelf__play' );
			$overlay = is_string( $overlay ) && '' !== $overlay
				? $overlay
				: '<span class="mw-release-shelf__play" aria-hidden="true">&#9654;</span>';
		}

		$meta_parts = array();
		if ( ! empty( $attributes['showDate'] ) ) {
			$meta_parts[] = '<time datetime="' . esc_attr( (string) get_the_date( 'c', $release_id ) ) . '">' . esc_html( (string) get_the_date( '', $release_id ) ) . '</time>';
		}
		if ( ! empty( $attributes['showViews'] ) ) {
			$views = absint( get_post_meta( $release_id, 'mw_views', true ) );
			if ( $views > 0 ) {
				$meta_parts[] = '<span class="mw-release-shelf__views">' . esc_html( number_format_i18n( $views ) ) . ' ' . esc_html__( 'بازدیدها', 'music-wave-core' ) . '</span>';
			}
		}

		$action_label = isset( $attributes['actionLabel'] ) ? sanitize_text_field( (string) $attributes['actionLabel'] ) : '';

		return array(
			'shape'            => $shape,
			'image_attributes' => array(
				'loading'  => 'lazy',
				'decoding' => 'async',
			),
			'open_label'       => $open_label,
			'overlay'          => $overlay,
			'show_artwork'     => ! isset( $attributes['showArtwork'] ) || false !== $attributes['showArtwork'],
			'show_artist'      => ! isset( $attributes['showArtist'] ) || false !== $attributes['showArtist'],
			'show_excerpt'     => ! empty( $attributes['showExcerpt'] ),
			'show_action'      => ! isset( $attributes['showAction'] ) || false !== $attributes['showAction'],
			'action_label'     => '' !== $action_label ? $action_label : __( 'باز کردن انتشار', 'music-wave-core' ),
			'meta_html'        => implode( ' ', $meta_parts ),
		);
	}

	/**
	 * Render the card for one post from its block attributes.
	 *
	 * Used by the `music-wave/release-card` block inside `core/post-template`,
	 * so a Query Loop renders the identical card the shelf renders in PHP.
	 *
	 * @param int                  $release_id Release ID (usually the loop's `postId` context).
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public static function render_for_post( int $release_id, array $attributes ): string {
		$data = self::presentation_data( $release_id );
		if ( empty( $data ) ) {
			return '';
		}

		return self::render( $data, self::options_from_attributes( $attributes, (int) $data['id'] ) );
	}

	/**
	 * One complete release card.
	 *
	 * @param array{id:int,link:string,title:string,artist:string,initial:string} $data    Presentation data.
	 * @param array<string, mixed>                                                $options {
	 *     Card options. Everything `artwork()` accepts, plus:
	 *
	 *     @type bool   $show_artwork       Whether to render the artwork unit at all.
	 *     @type bool   $show_artist        Whether to render the artist line.
	 *     @type bool   $show_excerpt       Whether to render a trimmed excerpt.
	 *     @type bool   $show_action        Whether to render the trailing action link.
	 *     @type string $action_label       Already-translated action link label.
	 *     @type string $extra_item_classes Extra classes for the `<article>`, e.g. the
	 *                                      continue-listening hook.
	 *     @type string $meta_html          Resolved `<time>` element. A release date on the
	 *                                      shelves, a "played ago" stamp in continue-listening.
	 *     @type string $body_html          Resolved markup appended after the meta line, used by
	 *                                      related-releases for its in-body preview button.
	 * }
	 */
	public static function render( array $data, array $options = array() ): string {
		$link       = isset( $data['link'] ) ? (string) $data['link'] : '';
		$title      = isset( $data['title'] ) ? (string) $data['title'] : '';
		$artist     = isset( $data['artist'] ) ? (string) $data['artist'] : '';
		$release_id = isset( $data['id'] ) ? (int) $data['id'] : 0;

		if ( '' === $link ) {
			return '';
		}

		// Every `show_*` flag is opt-in: callers pass their already-resolved
		// booleans, so a missing key hides the part rather than guessing.
		$show_artwork = isset( $options['show_artwork'] ) && false !== $options['show_artwork'];
		$show_artist  = isset( $options['show_artist'] ) && false !== $options['show_artist'];
		$show_excerpt = isset( $options['show_excerpt'] ) && false !== $options['show_excerpt'];
		$show_action  = isset( $options['show_action'] ) && false !== $options['show_action'];
		$action_label = isset( $options['action_label'] ) ? (string) $options['action_label'] : '';
		$extra        = isset( $options['extra_item_classes'] ) ? (string) $options['extra_item_classes'] : '';
		$meta_html    = isset( $options['meta_html'] ) ? (string) $options['meta_html'] : '';
		$body_html    = isset( $options['body_html'] ) ? (string) $options['body_html'] : '';

		$art           = $show_artwork ? self::artwork( $data, $options ) : '';
		$artist_markup = $show_artist && '' !== $artist
			? '<span class="mw-release-shelf__artist" dir="auto">' . esc_html( $artist ) . '</span>'
			: '';

		$excerpt = '';
		if ( $show_excerpt ) {
			$excerpt_text = get_the_excerpt( $release_id );
			if ( '' !== $excerpt_text ) {
				$excerpt = '<p dir="auto">' . esc_html( wp_trim_words( $excerpt_text, 18 ) ) . '</p>';
			}
		}

		$action = $show_action
			? '<a class="mw-release-shelf__action" href="' . esc_url( $link ) . '">' . esc_html( $action_label ) . '</a>'
			: '';

		$item_class = 'mw-release-shelf__item' . ( '' !== $extra ? ' ' . $extra : '' );

		return '<article class="' . esc_attr( $item_class ) . '">' . $art
			. '<div class="mw-release-shelf__body"><h3 dir="auto"><a href="' . esc_url( $link ) . '">' . esc_html( $title ) . '</a></h3>'
			. $artist_markup . $meta_html . $excerpt . $body_html . $action . '</div>'
			. '</article>';
	}
}
