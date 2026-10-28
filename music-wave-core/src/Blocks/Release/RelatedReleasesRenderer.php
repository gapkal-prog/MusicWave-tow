<?php
/**
 * Same-artist and weighted similarity related-releases renderer
 * (`related-releases`) — SOLID SRP extraction.
 *
 * Delegates every card skeleton strictly to `ReleaseCard::render()`.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Blocks\Release;

use ManaCore\MusicWave\Core\Blocks\BlockSupport;
use ManaCore\MusicWave\Core\Blocks\ReleaseCard;
use ManaCore\MusicWave\Core\Blocks\SectionHeader;
use ManaCore\MusicWave\Core\Catalog\ReleasePostType;
use ManaCore\MusicWave\Core\Support\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RelatedReleasesRenderer {
	/** @var ReleaseBlockContext */
	private $context;

	public function __construct( ReleaseBlockContext $context ) {
		$this->context = $context;
	}

	/**
	 * Render `music-wave/related-releases`.
	 *
	 * @param array<string, mixed> $attributes     Block attributes.
	 * @param object|null          $render_context Active WP_Block context.
	 */
	public function render( array $attributes, $render_context = null ): string {
		$release_id = $this->context->release_id( $attributes, $render_context );
		$is_editor  = BlockSupport::is_editor_context();
		if ( $release_id < 1 && ! $is_editor ) {
			return '';
		}

		$limit = BlockSupport::range_attribute( $attributes, 'itemsToShow', 2, 12, 0 );
		if ( $limit < 2 ) {
			$limit = absint( Settings::get( 'related_items_per_section' ) );
		}
		$limit    = $limit >= 2 && $limit <= 12 ? $limit : 4;
		$order_by = BlockSupport::key_attribute( $attributes, 'orderBy', array( 'date', 'title', 'rand', 'modified' ), 'date' );
		$order    = isset( $attributes['order'] ) && 'ASC' === strtoupper( (string) $attributes['order'] ) ? 'ASC' : 'DESC';
		$options  = $this->related_card_options( $attributes );
		$excluded = $release_id > 0 ? array( $release_id ) : array();
		$sections = array();

		$same_artist_heading = BlockSupport::text_attribute( $attributes, 'sameArtistHeading', '' );
		$similar_heading     = BlockSupport::text_attribute( $attributes, 'similarHeading', '' );
		// Fill one-insert copy only when at least one header is requested.
		if ( '' !== $same_artist_heading || '' !== $similar_heading ) {
			$same_artist_heading = '' !== $same_artist_heading ? $same_artist_heading : __( 'انتشارهای بیشتر از این هنرمند', 'music-wave-core' );
			$similar_heading     = '' !== $similar_heading ? $similar_heading : __( 'انتشارهای مشابه', 'music-wave-core' );
		}
		$show_section_link  = BlockSupport::bool_attribute( $attributes, 'showSectionLink', false );
		$section_link_label = BlockSupport::text_attribute( $attributes, 'sectionLinkLabel', __( 'همه را ببینید', 'music-wave-core' ) );

		if ( $release_id > 0 && $this->context->visibility_attribute( $attributes, 'sameArtistSection', 'show_same_artist_releases' ) ) {
			$artist_ids = wp_get_post_terms( $release_id, 'mw_artist', array( 'fields' => 'ids' ) );
			$artist_ids = is_array( $artist_ids ) ? array_values( array_filter( array_map( 'absint', $artist_ids ) ) ) : array();
			if ( ! empty( $artist_ids ) ) {
				$artist_releases = $this->related_query(
					$excluded,
					$limit,
					array(
						array(
							'taxonomy' => 'mw_artist',
							'field'    => 'term_id',
							'terms'    => $artist_ids,
						),
					),
					$order_by,
					$order
				);
				if ( ! empty( $artist_releases ) ) {
					$artist_link = '';
					if ( $show_section_link ) {
						$term_link   = get_term_link( $artist_ids[0] );
						$artist_link = ! is_wp_error( $term_link ) && is_string( $term_link ) ? $term_link : '';
					}
					$sections[] = $this->related_section( $same_artist_heading, $artist_releases, $options, $artist_link, $section_link_label );
					$excluded   = array_merge( $excluded, $artist_releases );
				}
			}
		}

		if ( $release_id > 0 && $this->context->visibility_attribute( $attributes, 'similarSection', 'show_similar_releases' ) ) {
			$signals   = array();
			$tax_query = array( 'relation' => 'OR' );
			$match     = array(
				'mw_genre'        => array(
					'weight'  => 4,
					'enabled' => BlockSupport::bool_attribute( $attributes, 'matchGenre', true ),
				),
				'mw_mood'         => array(
					'weight'  => 2,
					'enabled' => BlockSupport::bool_attribute( $attributes, 'matchMood', true ),
				),
				'mw_release_type' => array(
					'weight'  => 1,
					'enabled' => BlockSupport::bool_attribute( $attributes, 'matchType', true ),
				),
			);
			foreach ( $match as $taxonomy => $config ) {
				if ( ! $config['enabled'] ) {
					continue;
				}
				$term_ids = wp_get_post_terms( $release_id, $taxonomy, array( 'fields' => 'ids' ) );
				$term_ids = is_array( $term_ids ) ? array_values( array_filter( array_map( 'absint', $term_ids ) ) ) : array();
				if ( ! empty( $term_ids ) ) {
					$signals[ $taxonomy ] = array(
						'terms'  => $term_ids,
						'weight' => (int) $config['weight'],
					);
					$tax_query[]          = array(
						'taxonomy' => $taxonomy,
						'field'    => 'term_id',
						'terms'    => $term_ids,
					);
				}
			}
			if ( count( $tax_query ) > 1 ) {
				$similar = $this->similar_query( $excluded, $limit, $tax_query, $signals, $order_by, $order );
				if ( ! empty( $similar ) ) {
					$archive_link = '';
					if ( $show_section_link ) {
						$archive      = get_post_type_archive_link( ReleasePostType::KEY );
						$archive_link = is_string( $archive ) ? $archive : '';
					}
					$sections[] = $this->related_section( $similar_heading, $similar, $options, $archive_link, $section_link_label );
				}
			}
		}

		if ( empty( $sections ) && $is_editor ) {
			$fallback_ids = $this->related_query( $excluded, $limit, array(), $order_by, $order );
			if ( ! empty( $fallback_ids ) ) {
				$sections[] = $this->related_section( $similar_heading, $fallback_ids, $options, '', $section_link_label );
			} else {
				$sections[] = \ManaCore\MusicWave\Core\Blocks\Concerns\EditorDiagnosticNotice::render(
					$release_id > 0
						? sprintf(
							/* translators: %s: release title. */
							__( 'برای «%s» انتشار مرتبط دیگری در کاتالوگ یافت نشد؛ این بلوک در نمای عمومی پنهان می‌ماند.', 'music-wave-core' ),
							get_the_title( $release_id )
						)
						: __( 'انتشار مرتبط دیگری در کاتالوگ ثبت نشده است؛ پس از افزودن آثار دیگر، این بخش خودکار تکمیل می‌شود.', 'music-wave-core' )
				);
			}
		}

		$root_class = 'mw-related-releases mw-related-releases--' . $options['layout']
			. ( '' !== $options['variation'] ? ' mw-related-releases--' . $options['variation'] : '' );

		return empty( $sections ) ? '' : '<div ' . BlockSupport::wrapper_attributes( $root_class ) . '>' . implode( '', $sections ) . '</div>';
	}

	/**
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return array<string, mixed>
	 */
	private function related_card_options( array $attributes ): array {
		return array(
			'layout'       => BlockSupport::key_attribute( $attributes, 'layout', array( 'grid', 'scroll', 'list' ), 'grid' ),
			'columns'      => BlockSupport::range_attribute( $attributes, 'columns', 2, 6, 4 ),
			'shape'        => BlockSupport::key_attribute( $attributes, 'imageShape', array( 'square', 'landscape', 'portrait', 'circle' ), 'square' ),
			'show_artwork' => BlockSupport::bool_attribute( $attributes, 'showArtwork', true ),
			'show_artist'  => BlockSupport::bool_attribute( $attributes, 'showArtist', true ),
			'show_date'    => BlockSupport::bool_attribute( $attributes, 'showDate', false ),
			'show_excerpt' => BlockSupport::bool_attribute( $attributes, 'showExcerpt', false ),
			'show_preview' => BlockSupport::bool_attribute( $attributes, 'showPreview', true ),
			'show_action'  => BlockSupport::bool_attribute( $attributes, 'showAction', false ),
			'action_label' => BlockSupport::text_attribute( $attributes, 'actionLabel', __( 'انتشار را باز کنید', 'music-wave-core' ) ),
			'variation'    => BlockSupport::style_variation( $attributes, array( 'editorial', 'vinyl' ) ),
		);
	}

	/**
	 * @param array<int, int>                 $excluded  Excluded post IDs.
	 * @param array<int|string, array|string> $tax_query Taxonomy query.
	 * @return array<int, int>
	 */
	private function related_query( array $excluded, int $limit, array $tax_query, string $order_by = 'date', string $order = 'DESC' ): array {
		$order_by = in_array( $order_by, array( 'date', 'title', 'rand', 'modified' ), true ) ? $order_by : 'date';
		$order    = 'ASC' === strtoupper( $order ) ? 'ASC' : 'DESC';
		$posts    = get_posts(
			array(
				'post_type'              => ReleasePostType::KEY,
				'post_status'            => 'publish',
				'posts_per_page'         => $limit,
				'post__not_in'           => array_values( array_unique( array_map( 'absint', $excluded ) ) ),
				'fields'                 => 'ids',
				'orderby'                => $order_by,
				'order'                  => $order,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => true,
				'tax_query'              => $tax_query,
				'suppress_filters'       => false,
			)
		);

		return is_array( $posts ) ? array_values( array_filter( array_map( 'absint', $posts ) ) ) : array();
	}

	/**
	 * @param array<int, int>                     $excluded  Excluded post IDs.
	 * @param array<int|string, array|string>     $tax_query Candidate taxonomy query.
	 * @param array<string, array<string, mixed>> $signals   Shared taxonomy signals.
	 * @return array<int, int>
	 */
	private function similar_query( array $excluded, int $limit, array $tax_query, array $signals, string $order_by = 'date', string $order = 'DESC' ): array {
		$candidate_limit = min( 60, max( 16, $limit * 8 ) );
		$candidates      = $this->related_query( $excluded, $candidate_limit, $tax_query, $order_by, $order );
		$scores          = array();
		$order           = array_flip( $candidates );

		$this->context->term_index()->prime( $candidates, array_map( 'strval', array_keys( $signals ) ) );

		foreach ( $candidates as $candidate_id ) {
			$score = 0;
			foreach ( $signals as $taxonomy => $signal ) {
				$term_ids = $this->context->term_index()->ids( $candidate_id, (string) $taxonomy );
				$shared   = count( array_intersect( $signal['terms'], $term_ids ) );
				$score   += $shared * (int) $signal['weight'];
			}
			$scores[ $candidate_id ] = $score;
		}

		usort(
			$candidates,
			static function ( int $left, int $right ) use ( $scores, $order ): int {
				if ( $scores[ $left ] === $scores[ $right ] ) {
					return $order[ $left ] <=> $order[ $right ];
				}

				return $scores[ $right ] <=> $scores[ $left ];
			}
		);

		return array_slice( $candidates, 0, $limit );
	}

	/**
	 * @param array<int, int>      $release_ids        Related release IDs.
	 * @param array<string, mixed> $options            Resolved card options.
	 */
	public function related_section( string $heading, array $release_ids, array $options, string $section_url = '', string $section_link_label = '' ): string {
		$cards = array();
		foreach ( $release_ids as $release_id ) {
			$card = $this->related_card( $release_id, $options );
			if ( '' !== $card ) {
				$cards[] = $card;
			}
		}
		if ( empty( $cards ) ) {
			return '';
		}

		$more        = SectionHeader::more_link( 'mw-related-releases__more', $section_url, $section_link_label );
		$shelf_class = 'mw-release-shelf mw-release-shelf--' . $options['layout'];
		if ( '' !== $options['variation'] ) {
			$shelf_class .= ' mw-release-shelf--' . $options['variation'];
		}
		if ( 'grid' === $options['layout'] ) {
			$shelf_class .= ' mw-release-shelf--columns-' . $options['columns'];
		}

		$heading_html = '' !== $heading ? '<h2>' . esc_html( $heading ) . '</h2>' : '';
		$header       = '' !== $heading_html || '' !== $more ? '<div class="mw-related-releases__section-header">' . $heading_html . $more . '</div>' : '';

		// ShelfNavMarkup::items_region emits `.mw-release-shelf__items` (plus accessible scroll nav on `scroll`).
		return '<section class="mw-related-releases__section ' . esc_attr( $shelf_class ) . '">'
			. $header
			. \ManaCore\MusicWave\Core\Support\ShelfNavMarkup::items_region(
				(string) $options['layout'],
				implode( '', $cards ),
				$heading
			)
			. '</section>';
	}

	/**
	 * @param array<string, mixed> $options Resolved card options.
	 */
	public function related_card( int $release_id, array $options ): string {
		$data = ReleaseCard::presentation_data( $release_id );
		if ( array() === $data ) {
			return '';
		}

		$overlay = '';
		if ( $options['show_preview'] ) {
			$overlay = apply_filters( 'music_wave_card_play_button', '', $release_id, 'mw-release-shelf__play' );
			$overlay = is_string( $overlay ) ? $overlay : '';
			if ( '' === $overlay && $this->context->playability()->has_preview( $release_id ) ) {
				$overlay = '<span class="mw-release-shelf__play" aria-hidden="true">&#9654;</span>';
			}
		}

		$meta_html = $options['show_date']
			? '<time datetime="' . esc_attr( get_the_date( 'c', $release_id ) ) . '">' . esc_html( get_the_date( '', $release_id ) ) . '</time>'
			: '';

		return ReleaseCard::render(
			$data,
			array(
				'shape'            => (string) $options['shape'],
				'image_attributes' => array(),
				/* translators: %s: music release title. */
				'open_label'       => sprintf( __( 'باز کردن %s', 'music-wave-core' ), $data['title'] ),
				'overlay'          => $overlay,
				'show_artwork'     => $options['show_artwork'],
				'show_artist'      => $options['show_artist'],
				'show_excerpt'     => $options['show_excerpt'],
				'show_action'      => $options['show_action'],
				'action_label'     => (string) $options['action_label'],
				'meta_html'        => $meta_html,
				'body_html'        => $options['show_preview'] ? $this->context->compact_preview_button( $release_id ) : '',
			)
		);
	}
}
