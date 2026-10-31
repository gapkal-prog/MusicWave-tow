<?php
/**
 * Release identity, access panel, and credits renderer (`release-meta`,
 * `access-panel`, `release-credits`) — SOLID SRP extraction.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Blocks\Release;

use ManaCore\MusicWave\Core\Access\AccessSubject;
use ManaCore\MusicWave\Core\Blocks\BlockSupport;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ReleaseMetaRenderer {
	/** @var ReleaseBlockContext */
	private $context;

	public function __construct( ReleaseBlockContext $context ) {
		$this->context = $context;
	}

	/**
	 * Render `music-wave/release-meta`.
	 *
	 * @param array<string, mixed> $attributes     Block attributes.
	 * @param object|null          $render_context Active WP_Block context.
	 */
	public function render_meta( array $attributes, $render_context = null ): string {
		$release_id = $this->context->release_id( $attributes, $render_context );
		$is_editor  = BlockSupport::is_editor_context();
		if ( $release_id <= 0 && ! $is_editor ) {
			return '';
		}

		if ( $release_id > 0 && ! $is_editor && 'publish' !== (string) get_post_status( $release_id ) ) {
			return '';
		}

		$compact             = BlockSupport::bool_attribute( $attributes, 'compact', true );
		$has_explicit_layout = array_key_exists( 'layout', $attributes ) && is_string( $attributes['layout'] ) && '' !== trim( $attributes['layout'] );
		$layout              = BlockSupport::key_attribute( $attributes, 'layout', array( 'grid', 'inline', 'stack' ), 'grid' );
		$variation           = BlockSupport::style_variation( $attributes, array( 'inline', 'stack', 'stamp' ) );
		if ( ! $has_explicit_layout && in_array( $variation, array( 'inline', 'stack' ), true ) ) {
			$layout = $variation;
		}
		$show_labels = BlockSupport::bool_attribute( $attributes, 'showLabels', true );
		$full_specs  = $release_id > 0 && BlockSupport::bool_attribute( $attributes, 'fullSpecs', false ) && ! $this->is_single_fact_selection( $attributes );
		if ( $full_specs && ! $this->context->mark_full_specs_rendered( $release_id ) ) {
			return '';
		}
		$items = $release_id > 0 ? $this->collect_meta_items( $release_id, $attributes, $show_labels, $full_specs ) : array();

		$taxonomies = array(
			'mw_artist'       => array( __( 'هنرمند', 'music-wave-core' ), 'showArtist', true ),
			'mw_genre'        => array( __( 'سبک', 'music-wave-core' ), 'showGenre', true ),
			'mw_mood'         => array( __( 'حال‌وهوا', 'music-wave-core' ), 'showMood', false ),
			'mw_label'        => array( __( 'برچسب', 'music-wave-core' ), 'showLabel', false ),
			'mw_release_type' => array( __( 'نوع انتشار', 'music-wave-core' ), 'showReleaseType', false ),
		);
		if ( $release_id > 0 ) {
			foreach ( $taxonomies as $taxonomy => $field ) {
				if ( ! BlockSupport::bool_attribute( $attributes, (string) $field[1], (bool) $field[2] ) ) {
					continue;
				}
				$terms = wp_get_post_terms( $release_id, $taxonomy, array( 'fields' => 'all' ) );
				if ( is_wp_error( $terms ) || empty( $terms ) ) {
					continue;
				}
				$value = $this->context->terms_markup( $terms, true );
				if ( '' === $value ) {
					continue;
				}
				$items[] = $this->meta_item_markup( (string) $field[0], $value, $show_labels );
			}
		}

		if ( empty( $items ) && $is_editor ) {
			$items = $this->editor_sample_meta_items( $attributes, $show_labels );
		}

		if ( empty( $items ) ) {
			return '';
		}

		if ( $compact ) {
			$class = 'mw-release-meta mw-release-meta--compact'
				. ( 'stamp' === $variation ? ' mw-release-meta--stamp' : '' );

			return '<dl ' . BlockSupport::wrapper_attributes( $class ) . '>' . implode( '', $items ) . '</dl>';
		}

		$facts_class   = 'mw-release-meta__facts' . ( 'grid' !== $layout ? ' mw-release-meta__facts--' . $layout : '' );
		$facts         = '<dl class="' . esc_attr( $facts_class ) . '">' . implode( '', $items ) . '</dl>';
		$wrapper_class = 'mw-release-meta mw-release-meta--panel'
			. ( '' !== $variation ? ' mw-release-meta--' . $variation : '' );

		return '<section ' . BlockSupport::wrapper_attributes( $wrapper_class ) . ' aria-label="' . esc_attr__( 'جزئیات انتشار', 'music-wave-core' ) . '">' . $facts . '</section>';
	}

	/**
	 * Render `music-wave/access-panel`.
	 *
	 * @param array<string, mixed> $attributes     Block attributes.
	 * @param object|null          $render_context Active WP_Block context.
	 */
	public function render_access_panel( array $attributes, $render_context = null ): string {
		$release_id = $this->context->release_id( $attributes, $render_context );
		$layout     = BlockSupport::key_attribute( $attributes, 'layout', array( 'banner', 'stack' ), 'banner' );
		$is_editor  = BlockSupport::is_editor_context();

		if ( $release_id <= 0 ) {
			return $is_editor ? $this->editor_sample_access_panel( $attributes, $layout ) : '';
		}

		$decision = $this->context->policy()->decide( $release_id, AccessSubject::current() );
		if ( $decision->is_allowed() && 'public' === $decision->mode() ) {
			return $is_editor ? $this->editor_sample_access_panel( $attributes, $layout ) : '';
		}
		if ( $decision->is_allowed() ) {
			if ( ! BlockSupport::bool_attribute( $attributes, 'showWhenGranted', true ) ) {
				return '';
			}
			if ( ! $this->context->mark_access_panel_rendered( $release_id, 'allow' ) ) {
				return '';
			}
			$message = $this->context->message_attribute( $attributes, 'grantedMessage', 'access_granted_message', __( 'دسترسی اعطا شد', 'music-wave-core' ) );

			return '<aside ' . BlockSupport::wrapper_attributes( 'mw-access-panel mw-access-panel--' . $layout . ' mw-access-panel--granted' ) . '><strong class="mw-access-panel__message">' . esc_html( $message ) . '</strong></aside>';
		}

		if ( ! $this->context->mark_denied_gate_rendered( $release_id ) ) {
			return '';
		}

		$resolved = ( new \ManaCore\MusicWave\Core\Access\AccessCtaResolver( $this->context ) )->resolve_panel_content( $release_id, $decision, $attributes );

		return '<aside ' . BlockSupport::wrapper_attributes( 'mw-access-panel mw-access-panel--' . $layout . ' mw-access-panel--' . sanitize_html_class( $decision->reason() ) ) . '><strong class="mw-access-panel__message">' . esc_html( $resolved['message'] ) . '</strong>' . $resolved['actions_html'] . '</aside>';
	}

	/**
	 * Render a realistic preview of `music-wave/access-panel` in the Site/Block Editor.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $layout     `banner` | `stack`.
	 */
	private function editor_sample_access_panel( array $attributes, string $layout ): string {
		$message = $this->context->message_attribute(
			$attributes,
			'membershipMessage',
			'membership_required_message',
			__( 'پخش کامل و دانلود مستقیم با اشتراک ویژه فعال است', 'music-wave-core' )
		);
		$cta     = BlockSupport::text_attribute( $attributes, 'membershipCtaLabel', __( 'ارتقا به ویژه', 'music-wave-core' ) );
		$actions = BlockSupport::bool_attribute( $attributes, 'showActions', true )
			? '<div class="mw-access-panel__actions"><span class="mw-action-button mw-action-button--button mw-action-button--accent mw-action-button--sm mw-action-button--pill">' . esc_html( $cta ) . '</span></div>'
			: '';

		return '<aside ' . BlockSupport::wrapper_attributes( 'mw-access-panel mw-access-panel--' . $layout . ' mw-access-panel--preview' ) . '><strong class="mw-access-panel__message">' . esc_html( $message ) . '</strong>' . $actions . '</aside>';
	}

	/**
	 * Render `music-wave/release-credits`.
	 *
	 * @param array<string, mixed> $attributes     Block attributes.
	 * @param object|null          $render_context Active WP_Block context.
	 */
	public function render_credits( array $attributes, $render_context = null ): string {
		$release_id = $this->context->release_id( $attributes, $render_context );
		$is_editor  = BlockSupport::is_editor_context();
		if ( $release_id > 0 && ! $is_editor && 'publish' !== (string) get_post_status( $release_id ) ) {
			return '';
		}
		if ( $release_id <= 0 && ! $is_editor ) {
			return '';
		}
		$credits = $release_id > 0 ? $this->context->repository()->get( $release_id, 'mw_credits' ) : array();
		if ( ! is_array( $credits ) || empty( $credits ) ) {
			if ( ! $is_editor ) {
				return '';
			}
			$heading      = BlockSupport::optional_text_attribute( $attributes, 'heading', __( 'اعتبارات', 'music-wave-core' ), 'showHeading' );
			$heading_html = '' !== $heading ? '<h2 class="mw-release-credits__heading">' . esc_html( $heading ) . '</h2>' : '';
			$diag_note    = \ManaCore\MusicWave\Core\Blocks\Concerns\EditorDiagnosticNotice::render(
				$release_id > 0
					/* translators: %s: release title. */
					? sprintf( __( 'برای «%s» هنوز عوامل تولید (Credits) ثبت نشده است؛ در نمای عمومی نمایش داده نمی‌شود.', 'music-wave-core' ), get_the_title( $release_id ) )
					: __( 'برای این انتشار هنوز عوامل تولید (Credits) ثبت نشده است؛ در نمای عمومی نمایش داده نمی‌شود.', 'music-wave-core' )
			);

			return '<section ' . BlockSupport::wrapper_attributes( 'mw-release-credits mw-release-credits--empty' ) . '>' . $heading_html . $diag_note . '</section>';
		}

		$show_role = BlockSupport::bool_attribute( $attributes, 'showRole', true );
		$names     = array();
		foreach ( $credits as $credit ) {
			if ( ! is_array( $credit ) || empty( $credit['name'] ) || ! is_scalar( $credit['name'] ) ) {
				continue;
			}
			$role    = $show_role && isset( $credit['role'] ) && is_scalar( $credit['role'] ) && '' !== (string) $credit['role'] ? (string) $credit['role'] : '';
			$names[] = array(
				'name' => (string) $credit['name'],
				'role' => $role,
			);
		}
		if ( empty( $names ) ) {
			return '';
		}

		$layout = BlockSupport::key_attribute( $attributes, 'layout', array( 'list', 'grid', 'inline' ), 'list' );
		if ( $show_role && BlockSupport::bool_attribute( $attributes, 'groupByRole', false ) ) {
			$body = $this->credit_groups_markup( $names );
		} else {
			$body = '<ul class="mw-release-credits__list mw-release-credits__list--' . esc_attr( $layout ) . '">' . implode( '', array_map( array( $this, 'credit_item_markup' ), $names ) ) . '</ul>';
		}
		if ( '' === $body ) {
			return '';
		}

		$heading      = BlockSupport::optional_text_attribute( $attributes, 'heading', __( 'اعتبارات', 'music-wave-core' ), 'showHeading' );
		$show_heading = '' !== $heading;
		$heading_html = $show_heading ? '<h2 class="mw-release-credits__heading">' . esc_html( $heading ) . '</h2>' : '';
		$aria_label   = $show_heading ? '' : ' aria-label="' . esc_attr__( 'اعتبارات', 'music-wave-core' ) . '"';

		return '<section ' . BlockSupport::wrapper_attributes( 'mw-release-credits' ) . $aria_label . '>' . $heading_html . $body . '</section>';
	}

	/**
	 * Build realistic editor preview facts when editing templates without a bound release post.
	 *
	 * @param array<string, mixed> $attributes  Block attributes.
	 * @param bool                 $show_labels Whether labels are visible.
	 * @return array<int, string>
	 */
	private function editor_sample_meta_items( array $attributes, bool $show_labels ): array {
		$samples = array(
			array( 'showReleaseType', false, __( 'نوع انتشار', 'music-wave-core' ), __( 'آلبوم', 'music-wave-core' ) ),
			array( 'showArtist', true, __( 'هنرمند', 'music-wave-core' ), __( 'نام هنرمند', 'music-wave-core' ) ),
			array( 'showReleaseDate', true, __( 'تاریخ انتشار', 'music-wave-core' ), '۱۴۰۵' ),
			array( 'showDuration', true, __( 'مدت زمان', 'music-wave-core' ), '۳:۴۵' ),
			array( 'showBpm', true, __( 'تمپو', 'music-wave-core' ), '۱۲۰ BPM' ),
			array( 'showKey', true, __( 'گام موسیقی', 'music-wave-core' ), 'Am' ),
			array( 'showGenre', true, __( 'سبک', 'music-wave-core' ), __( 'الکترونیک', 'music-wave-core' ) ),
			array( 'showMood', false, __( 'حال‌وهوا', 'music-wave-core' ), __( 'شبانه', 'music-wave-core' ) ),
			array( 'showLabel', false, __( 'برچسب', 'music-wave-core' ), 'MusicWave' ),
			array( 'showCatalogNumber', true, __( 'شماره کاتالوگ', 'music-wave-core' ), 'MW-104' ),
		);
		$items   = array();
		foreach ( $samples as $sample ) {
			if ( ! BlockSupport::bool_attribute( $attributes, (string) $sample[0], (bool) $sample[1] ) ) {
				continue;
			}
			$items[] = $this->meta_item_markup( (string) $sample[2], esc_html( (string) $sample[3] ), $show_labels );
		}

		return $items;
	}

	/**
	 * Detect block variations that explicitly request only one fact cell.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private function is_single_fact_selection( array $attributes ): bool {
		$primary_toggles = array(
			'showCatalogNumber',
			'showReleaseDate',
			'showDuration',
			'showBpm',
			'showKey',
		);
		$explicit_false  = 0;
		$enabled_count   = 0;
		foreach ( $primary_toggles as $toggle ) {
			if ( array_key_exists( $toggle, $attributes ) && false === (bool) $attributes[ $toggle ] ) {
				++$explicit_false;
			}
			if ( BlockSupport::bool_attribute( $attributes, $toggle, true ) ) {
				++$enabled_count;
			}
		}

		return $explicit_false >= 3 && $enabled_count <= 1;
	}

	/**
	 * Build ordered HTML `<div class="mw-release-meta__item">` entries across all
	 * populated «جزئیات انتشار MusicWave» fields.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return array<int, string>
	 */
	private function collect_meta_items( int $release_id, array $attributes, bool $show_labels, bool $full_specs ): array {
		$items = array();
		$repo  = $this->context->repository();

		if ( BlockSupport::bool_attribute( $attributes, 'showCatalogNumber', true ) ) {
			$catalog = trim( (string) $repo->get( $release_id, 'mw_catalog_number' ) );
			if ( '' !== $catalog ) {
				$items[] = $this->meta_item_markup( __( 'شماره کاتالوگ', 'music-wave-core' ), esc_html( $catalog ), $show_labels );
			}
		}

		if ( $full_specs ) {
			$album = trim( (string) $repo->get( $release_id, 'mw_album' ) );
			if ( '' !== $album ) {
				$items[] = $this->meta_item_markup( __( 'آلبوم', 'music-wave-core' ), esc_html( $album ), $show_labels );
			}
		}

		if ( BlockSupport::bool_attribute( $attributes, 'showReleaseDate', true ) ) {
			$release_date = trim( (string) $repo->get( $release_id, 'mw_release_date' ) );
			$release_year = (int) $repo->get( $release_id, 'mw_release_year' );
			if ( '' !== $release_date ) {
				$items[] = $this->meta_item_markup( __( 'تاریخ انتشار', 'music-wave-core' ), esc_html( $release_date ), $show_labels );
			} elseif ( $full_specs && $release_year > 0 ) {
				$items[] = $this->meta_item_markup( __( 'سال انتشار', 'music-wave-core' ), esc_html( (string) $release_year ), $show_labels );
			}
		}

		if ( BlockSupport::bool_attribute( $attributes, 'showDuration', true ) ) {
			$seconds = (int) $repo->get( $release_id, 'mw_duration' );
			if ( $seconds > 0 ) {
				$formatted_duration = $seconds >= 3600
					? sprintf( '%d:%02d:%02d', intdiv( $seconds, 3600 ), intdiv( $seconds % 3600, 60 ), $seconds % 60 )
					: sprintf( '%d:%02d', intdiv( $seconds, 60 ), $seconds % 60 );
				$items[]            = $this->meta_item_markup( __( 'مدت زمان', 'music-wave-core' ), esc_html( $formatted_duration ), $show_labels );
			}
		}

		if ( BlockSupport::bool_attribute( $attributes, 'showBpm', true ) ) {
			$bpm = (int) $repo->get( $release_id, 'mw_bpm' );
			if ( $bpm > 0 ) {
				$items[] = $this->meta_item_markup( __( 'BPM', 'music-wave-core' ), esc_html( (string) $bpm ), $show_labels );
			}
		}

		if ( BlockSupport::bool_attribute( $attributes, 'showKey', true ) ) {
			$musical_key = trim( (string) $repo->get( $release_id, 'mw_musical_key' ) );
			if ( '' !== $musical_key ) {
				$items[] = $this->meta_item_markup( __( 'کلید', 'music-wave-core' ), esc_html( $musical_key ), $show_labels );
			}
		}

		if ( ! $full_specs ) {
			return $items;
		}

		$track_number = (int) $repo->get( $release_id, 'mw_track_number' );
		$total_tracks = (int) $repo->get( $release_id, 'mw_total_tracks' );
		if ( $track_number > 0 && $total_tracks > 0 ) {
			$items[] = $this->meta_item_markup( __( 'شماره قطعه', 'music-wave-core' ), esc_html( $track_number . ' / ' . $total_tracks ), $show_labels );
		} elseif ( $track_number > 0 ) {
			$items[] = $this->meta_item_markup( __( 'شماره قطعه', 'music-wave-core' ), esc_html( (string) $track_number ), $show_labels );
		} elseif ( $total_tracks > 0 ) {
			$items[] = $this->meta_item_markup( __( 'تعداد کل قطعات', 'music-wave-core' ), esc_html( (string) $total_tracks ), $show_labels );
		}

		$season_number  = (int) $repo->get( $release_id, 'mw_season_number' );
		$episode_number = (int) $repo->get( $release_id, 'mw_episode_number' );
		if ( $season_number > 0 && $episode_number > 0 ) {
			$episode_text = sprintf(
				/* translators: 1: season number, 2: episode number. */
				__( 'فصل %1$d · قسمت %2$d', 'music-wave-core' ),
				$season_number,
				$episode_number
			);
			$items[] = $this->meta_item_markup( __( 'پادکست', 'music-wave-core' ), esc_html( $episode_text ), $show_labels );
		} elseif ( $episode_number > 0 ) {
			$items[] = $this->meta_item_markup( __( 'شماره قسمت', 'music-wave-core' ), esc_html( (string) $episode_number ), $show_labels );
		} elseif ( $season_number > 0 ) {
			$items[] = $this->meta_item_markup( __( 'شماره فصل', 'music-wave-core' ), esc_html( (string) $season_number ), $show_labels );
		}

		$text_specs = array(
			'mw_isrc'      => __( 'ISRC', 'music-wave-core' ),
			'mw_language'  => __( 'زبان', 'music-wave-core' ),
			'mw_country'   => __( 'کشور', 'music-wave-core' ),
			'mw_publisher' => __( 'ناشر', 'music-wave-core' ),
			'mw_barcode'   => __( 'بارکد', 'music-wave-core' ),
		);
		foreach ( $text_specs as $meta_key => $label ) {
			$text_value = trim( (string) $repo->get( $release_id, $meta_key ) );
			if ( '' !== $text_value ) {
				$items[] = $this->meta_item_markup( $label, esc_html( $text_value ), $show_labels );
			}
		}

		if ( (bool) $repo->get( $release_id, 'mw_explicit' ) ) {
			$items[] = $this->meta_item_markup( __( 'رده‌بندی', 'music-wave-core' ), esc_html__( 'محتوای صریح', 'music-wave-core' ), $show_labels );
		}

		return $items;
	}

	private function meta_item_markup( string $label, string $value_html, bool $show_label ): string {
		$label_html = $show_label
			? '<dt>' . esc_html( $label ) . '</dt>'
			: '<dt class="screen-reader-text">' . esc_html( $label ) . '</dt>';

		return '<div class="mw-release-meta__item">' . $label_html . '<dd>' . $value_html . '</dd></div>';
	}

	/**
	 * @param array<string, string> $credit Normalized credit entry.
	 */
	private function credit_item_markup( array $credit ): string {
		$role = '' !== $credit['role'] ? ' <span class="mw-release-credits__role">' . esc_html( $credit['role'] ) . '</span>' : '';

		return '<li><strong>' . esc_html( $credit['name'] ) . '</strong>' . $role . '</li>';
	}

	/**
	 * @param array<int, array<string, string>> $credits Normalized credit entries.
	 */
	private function credit_groups_markup( array $credits ): string {
		$groups = array();
		foreach ( $credits as $credit ) {
			$role = '' !== $credit['role'] ? $credit['role'] : __( 'اعتبارات اضافی', 'music-wave-core' );
			if ( ! isset( $groups[ $role ] ) ) {
				$groups[ $role ] = array();
			}
			$groups[ $role ][] = array(
				'name' => $credit['name'],
				'role' => '',
			);
		}

		$sections = array();
		foreach ( $groups as $role => $items ) {
			$sections[] = '<section class="mw-release-credits__group"><h3 class="mw-release-credits__role-heading">' . esc_html( $role ) . '</h3><ul class="mw-release-credits__list">' . implode( '', array_map( array( $this, 'credit_item_markup' ), $items ) ) . '</ul></section>';
		}

		return empty( $sections ) ? '' : '<div class="mw-release-credits__groups">' . implode( '', $sections ) . '</div>';
	}
}
