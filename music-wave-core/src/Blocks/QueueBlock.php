<?php
/**
 * Playback queue block: the listener's durable "up next" manager.
 *
 * Renders the signed-in listener's durable queue with reorder, remove,
 * clear, shuffle, and repeat controls that work as plain form posts —
 * zero JavaScript required — plus a sign-in panel for guests. Mirrors the
 * playlist manager contract so both surfaces behave identically
 * (PROJECT_PLAN.md Stage 4 deliverable 6, Stage 5 deliverable 2).
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Blocks;

use ManaCore\MusicWave\Core\Blocks\Concerns\ActionButtonStyle;
use ManaCore\MusicWave\Core\Blocks\Concerns\ReleaseResolver;
use ManaCore\MusicWave\Core\Listening\ListeningRepository;
use ManaCore\MusicWave\Core\Listening\QueueFormHandler;
use ManaCore\MusicWave\Core\Account\AuthPolicy;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class QueueBlock {
	/** @var ListeningRepository */
	private $repository;

	/** @var QueueFormHandler */
	private $forms;

	/** @var \WP_Block|null Block instance of the add-control currently rendering. */
	private $render_context;

	public function __construct( ListeningRepository $repository, QueueFormHandler $forms ) {
		$this->repository = $repository;
		$this->forms      = $forms;
	}

	/**
	 * Register the server-rendered playback queue block.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		BlockSupport::register_dynamic(
			'music-wave/playback-queue',
			array( $this, 'render' ),
			array(
				'api_version' => 3,
				'supports'    => BlockSupport::appearance_tools(),
			)
		);

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		BlockSupport::register_dynamic(
			'music-wave/add-to-queue',
			function ( $attributes, $content, $block ): string {
				unset( $content );
				$previous             = $this->render_context;
				$this->render_context = is_object( $block ) ? $block : null;

				try {
					return $this->render_add( is_array( $attributes ) ? $attributes : array() );
				} finally {
					$this->render_context = $previous;
				}
			},
			array(
				'api_version'  => 3,
				'uses_context' => array( 'postId', 'postType' ),
				'supports'     => BlockSupport::appearance_tools(),
			)
		);
	}

	/**
	 * Render the "add to queue" control for one release.
	 *
	 * Mirrors the add-to-playlist picker: resolves the current or pinned
	 * release, shows a plain form post for signed-in listeners, a sign-in
	 * button for guests, and an in-queue badge once queued.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render_add( array $attributes ): string {
		$position    = isset( $attributes['position'] ) ? sanitize_key( (string) $attributes['position'] ) : 'next';
		$position    = in_array( $position, array( 'next', 'end' ), true ) ? $position : 'next';
		$mode        = ActionButtonStyle::resolve( $attributes, ActionButtonStyle::MODE_BUTTON );
		$show_label  = ActionButtonStyle::shows_label( $mode );
		$btn_classes = ActionButtonStyle::button_classes(
			$attributes,
			'mw-add-to-queue__button',
			ActionButtonStyle::MODE_BUTTON,
			ActionButtonStyle::VARIANT_SURFACE
		);
		$icon_html   = '<span class="mw-add-to-queue__icon" aria-hidden="true">' . ActionButtonStyle::render_icon( $attributes, 'queue', 18 ) . '</span>';
		$label       = isset( $attributes['label'] ) && '' !== sanitize_text_field( (string) $attributes['label'] )
			? sanitize_text_field( (string) $attributes['label'] )
			: ( 'end' === $position
				? __( 'افزودن به صف', 'music-wave-core' )
				: __( 'پخش بعدی', 'music-wave-core' ) );
		$label_out   = $show_label ? esc_html( $label ) : '<span class="screen-reader-text">' . esc_html( $label ) . '</span>';

		$release_id = $this->resolve_release_id( $attributes );
		if ( $release_id < 1 ) {
			if ( ! BlockSupport::is_editor_context() ) {
				return '';
			}
			return '<div ' . BlockSupport::wrapper_attributes( 'mw-add-to-queue mw-add-to-queue--placeholder' ) . '>'
				. '<button type="button" class="' . esc_attr( $btn_classes ) . '" aria-label="' . esc_attr( $label ) . '">'
				. $icon_html . $label_out
				. '</button>'
				. '</div>';
		}

		$user_id = get_current_user_id();

		if ( $user_id < 1 ) {
			$guest_label = __( 'ورود به صف', 'music-wave-core' );
			$guest_out   = $show_label ? esc_html( $guest_label ) : '<span class="screen-reader-text">' . esc_html( $guest_label ) . '</span>';
			return '<div ' . BlockSupport::wrapper_attributes( 'mw-add-to-queue mw-add-to-queue--guest' ) . '>'
				. '<a class="' . esc_attr( $btn_classes . ' wp-element-button' ) . '" href="' . esc_url( AuthPolicy::login_url( BlockSupport::current_url() ) ) . '" aria-label="' . esc_attr( $guest_label ) . '">'
				. $icon_html . $guest_out
				. '</a></div>';
		}

		$queued = in_array( $release_id, $this->repository->queue( $user_id )['ids'], true );
		if ( $queued ) {
			return '<div ' . BlockSupport::wrapper_attributes( 'mw-add-to-queue mw-add-to-queue--queued' ) . '><span class="mw-add-to-queue__in">'
				. '<span aria-hidden="true">&#10003;</span> '
				. esc_html( __( 'در صف شما', 'music-wave-core' ) )
				. '</span></div>';
		}

		return '<div ' . BlockSupport::wrapper_attributes( 'mw-add-to-queue' ) . '><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'
			. '<input type="hidden" name="mw_operation" value="add">'
			. '<input type="hidden" name="mw_value" value="' . esc_attr( $position ) . '">'
			. '<input type="hidden" name="mw_release_id" value="' . esc_attr( (string) $release_id ) . '">'
			. wp_nonce_field( QueueFormHandler::NONCE, '_wpnonce', true, false )
			. '<input type="hidden" name="action" value="' . esc_attr( QueueFormHandler::ACTION ) . '">'
			. '<input type="hidden" name="mw_redirect" value="' . esc_attr( BlockSupport::current_url() ) . '">'
			. '<button type="submit" class="' . esc_attr( $btn_classes . ' wp-element-button' ) . '" aria-label="' . esc_attr( $label ) . '">'
			. $icon_html . $label_out
			. '</button>'
			. '</form></div>';
	}

	/**
	 * Resolve the release the add-control acts on – DRY via ReleaseResolver.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private function resolve_release_id( array $attributes ): int {
		return ReleaseResolver::resolve( $attributes, $this->render_context );
	}

	/**
	 * Render the queue surface for the current viewer.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render( array $attributes ): string {
		$user_id = get_current_user_id();

		if ( $user_id < 1 ) {
			return $this->render_guest( $attributes );
		}

		$queue = $this->repository->queue( $user_id );
		$ids   = $queue['ids'];

		$options = array(
			'heading'        => BlockSupport::text_attribute( $attributes, 'heading', __( 'بعدی', 'music-wave-core' ) ),
			'show_artwork'   => ! isset( $attributes['showArtwork'] ) || (bool) $attributes['showArtwork'],
			'show_position'  => ! isset( $attributes['showPosition'] ) || (bool) $attributes['showPosition'],
			'show_artist'    => ! isset( $attributes['showArtist'] ) || (bool) $attributes['showArtist'],
			'show_controls'  => ! isset( $attributes['showControls'] ) || (bool) $attributes['showControls'],
			'show_play_all'  => ! isset( $attributes['showPlayAll'] ) || (bool) $attributes['showPlayAll'],
			'show_shuffle'   => ! isset( $attributes['showShuffle'] ) || (bool) $attributes['showShuffle'],
			'show_repeat'    => ! isset( $attributes['showRepeat'] ) || (bool) $attributes['showRepeat'],
			'play_all_label' => BlockSupport::text_attribute( $attributes, 'playAllLabel', __( 'پخش همه', 'music-wave-core' ) ),
			'shuffle_label'  => BlockSupport::text_attribute( $attributes, 'shuffleLabel', '' ),
			'show_clear'     => ! isset( $attributes['showClear'] ) || (bool) $attributes['showClear'],
		);

		$header = '<header class="mw-playback-queue__header"><div>'
			. '<h2 class="mw-playback-queue__title">' . esc_html( (string) $options['heading'] ) . '</h2>'
			. '</div>'
			. /* translators: %d: number of queued releases. */
			'<span class="mw-playback-queue__count" aria-label="' . esc_attr( sprintf( __( '%d در صف', 'music-wave-core' ), count( $ids ) ) ) . '">' . esc_html( number_format_i18n( count( $ids ) ) ) . '</span>'
			. '</header>';

		if ( array() === $ids ) {
			$empty = BlockSupport::text_attribute( $attributes, 'emptyMessage', __( 'صف شما خالی است. برای ساختن آن، روی هر انتشار «پخش بعدی» را انتخاب کنید.', 'music-wave-core' ) );

			return '<section ' . BlockSupport::wrapper_attributes( 'mw-playback-queue mw-playback-queue--empty' ) . '>'
				. $header
				. $this->notice_markup()
				. '<p class="mw-playback-queue__empty">' . esc_html( $empty ) . '</p>'
				. '</section>';
		}

		$rows = array();
		foreach ( array_values( $ids ) as $index => $release_id ) {
			$rows[] = $this->row( (int) $release_id, $index, count( $ids ), $options );
		}

		$encoded_ids  = function_exists( 'wp_json_encode' ) ? (string) wp_json_encode( array_values( $ids ), JSON_UNESCAPED_UNICODE ) : (string) json_encode( array_values( $ids ), JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- standalone CLI test fallback.
		$queue_json   = esc_attr( $encoded_ids );
		$nonce        = esc_attr( wp_create_nonce( QueueFormHandler::NONCE ) );
		$shuffle_attr = $queue['shuffle'] ? '1' : '0';
		$repeat_attr  = in_array( $queue['repeat'], array( 'off', 'all', 'one' ), true ) ? $queue['repeat'] : 'off';

		return '<section ' . BlockSupport::wrapper_attributes( 'mw-playback-queue' ) . ' data-mw-queue data-mw-queue-ids="' . $queue_json . '" data-mw-queue-shuffle="' . esc_attr( $shuffle_attr ) . '" data-mw-queue-repeat="' . esc_attr( $repeat_attr ) . '" data-mw-queue-nonce="' . $nonce . '">'
			. $header
			. $this->notice_markup()
			. ( $options['show_controls'] ? $this->controls_markup( $queue, $options ) : '' )
			. '<ol class="mw-playback-queue__list">' . implode( '', $rows ) . '</ol>'
			. ( $options['show_clear'] ? $this->clear_markup() : '' )
			. '</section>';
	}

	/**
	 * Guest state: a compact sign-in prompt keeps the layout reserved.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	private function render_guest( array $attributes ): string {
		$heading = BlockSupport::text_attribute( $attributes, 'heading', __( 'بعدی', 'music-wave-core' ) );

		return '<section ' . BlockSupport::wrapper_attributes( 'mw-playback-queue mw-playback-queue--guest' ) . '>'
			. '<div class="mw-playback-queue__panel"><span class="mw-playback-queue__panel-icon" aria-hidden="true">☰</span>'
			. '<div><h2 class="mw-playback-queue__title">' . esc_html( $heading ) . '</h2>'
			. '<p class="mw-playback-queue__guest-text">' . esc_html__( 'برای حفظ صف پخش در بین صفحات و بازدیدها وارد سیستم شوید.', 'music-wave-core' ) . '</p></div>'
			. '<a class="wp-element-button mw-playback-queue__guest-cta" href="' . esc_url( AuthPolicy::login_url( BlockSupport::current_url() ) ) . '">' . esc_html__( 'وارد شوید', 'music-wave-core' ) . '</a></div>'
			. '</section>';
	}

	/**
	 * One queued release row with its per-item forms.
	 *
	 * @param int                  $release_id Queued release.
	 * @param int                  $index      Zero-based position.
	 * @param int                  $total      Row count for edge detection.
	 * @param array<string, mixed> $options    Resolved options.
	 */
	private function row( int $release_id, int $index, int $total, array $options ): string {
		$title = get_the_title( $release_id );
		$link  = get_permalink( $release_id );
		if ( $release_id < 1 || ! is_string( $link ) || '' === $link ) {
			return '';
		}
		$title = '' !== $title ? $title : __( 'انتشار بدون عنوان', 'music-wave-core' );

		/* translators: %s: release title. */
		$move_up_label = sprintf( __( '%s را به بالا منتقل کنید', 'music-wave-core' ), $title );
		/* translators: %s: release title. */
		$move_down_label = sprintf( __( '%s را به پایین حرکت دهید', 'music-wave-core' ), $title );
		/* translators: %s: release title. */
		$remove_label = sprintf( __( '%s را از صف حذف کنید', 'music-wave-core' ), $title );

		$art = '';
		if ( $options['show_artwork'] ) {
			$image   = get_the_post_thumbnail(
				$release_id,
				'thumbnail',
				array(
					'class'    => 'mw-playback-queue__image',
					'alt'      => '',
					'loading'  => 'lazy',
					'decoding' => 'async',
				)
			);
			$initial = function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 1 ) : substr( $title, 0, 1 );
			$art     = '<a class="mw-playback-queue__art" href="' . esc_url( $link ) . '" tabindex="-1" aria-hidden="true">'
				. ( '' !== $image ? $image : '<span class="mw-playback-queue__initial">' . esc_html( $initial ) . '</span>' )
				. '</a>';
		}

		$position = '';
		if ( $options['show_position'] ) {
			$position = '<span class="mw-playback-queue__position" aria-hidden="true">' . esc_html( number_format_i18n( $index + 1 ) ) . '</span>';
		}

		$artist = '';
		if ( $options['show_artist'] ) {
			$artists = wp_get_post_terms( $release_id, 'mw_artist', array( 'fields' => 'names' ) );
			if ( is_array( $artists ) && ! empty( $artists ) ) {
				$artist = '<span class="mw-playback-queue__artist">' . esc_html( implode( ', ', $artists ) ) . '</span>';
			}
		}

		$actions = '<form class="mw-playback-queue__actions" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-mw-queue-form>';
		if ( $index > 0 ) {
			$actions .= '<button type="submit" name="mw_operation" value="move-up" class="mw-queue-action-button" data-mw-queue-action="move-up" data-mw-release-id="' . esc_attr( (string) $release_id ) . '" aria-label="' . esc_attr( $move_up_label ) . '" title="' . esc_attr( $move_up_label ) . '">&uarr;</button>';
		}
		if ( $index < $total - 1 ) {
			$actions .= '<button type="submit" name="mw_operation" value="move-down" class="mw-queue-action-button" data-mw-queue-action="move-down" data-mw-release-id="' . esc_attr( (string) $release_id ) . '" aria-label="' . esc_attr( $move_down_label ) . '" title="' . esc_attr( $move_down_label ) . '">&darr;</button>';
		}
		$actions .= '<input type="hidden" name="mw_release_id" value="' . esc_attr( (string) $release_id ) . '">'
			. '<button type="submit" name="mw_operation" value="remove-item" class="mw-playback-queue__remove mw-queue-action-button" data-mw-queue-action="remove-item" data-mw-release-id="' . esc_attr( (string) $release_id ) . '" aria-label="' . esc_attr( $remove_label ) . '" title="' . esc_attr( $remove_label ) . '">&times;</button>'
			. $this->hidden_fields()
			. '</form>';

		return '<li class="mw-playback-queue__item">'
			. $position
			. $art
			. '<div class="mw-playback-queue__body"><h3 class="mw-playback-queue__name"><a href="' . esc_url( $link ) . '">' . esc_html( $title ) . '</a></h3>' . $artist . '</div>'
			. $actions
			. '</li>';
	}

	/**
	 * Shuffle, play-all and repeat preference forms.
	 *
	 * Progressive enhancement: forms remain functional without JS, but every
	 * control carries data-* hooks so queue.js can intercept and avoid a full
	 * page refresh. Play-all sits before shuffle per design request and both
	 * share the mw-shuffle-button token family for consistent styling.
	 *
	 * @param array<string, mixed> $queue   Normalized queue snapshot.
	 * @param array<string, mixed> $options Resolved block options.
	 */
	private function controls_markup( array $queue, array $options = array() ): string {
		$show_play_all = ! isset( $options['show_play_all'] ) || (bool) $options['show_play_all'];
		$show_shuffle  = ! isset( $options['show_shuffle'] ) || (bool) $options['show_shuffle'];
		$show_repeat   = ! isset( $options['show_repeat'] ) || (bool) $options['show_repeat'];

		$play_all_label = isset( $options['play_all_label'] ) && '' !== (string) $options['play_all_label']
			? (string) $options['play_all_label']
			: __( 'پخش همه', 'music-wave-core' );

		$shuffle_custom = isset( $options['shuffle_label'] ) && '' !== (string) $options['shuffle_label']
			? (string) $options['shuffle_label']
			: '';
		$shuffle_label  = '' !== $shuffle_custom
			? $shuffle_custom
			: ( $queue['shuffle'] ? __( 'تصادفی: روشن', 'music-wave-core' ) : __( 'پخش تصادفی: خاموش', 'music-wave-core' ) );

		$markup = '<div class="mw-playback-queue__controls">';

		if ( $show_play_all ) {
			$markup .= '<button type="button" class="mw-play-all-button mw-shuffle-button" data-mw-queue-play-all aria-label="' . esc_attr( $play_all_label ) . '">'
				. '<span aria-hidden="true">&#9654;</span> '
				. esc_html( $play_all_label )
				. '</button>';
		}

		if ( $show_shuffle ) {
			$markup .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-mw-queue-form data-mw-queue-action="shuffle">'
				. '<input type="hidden" name="mw_operation" value="shuffle">'
				. '<input type="hidden" name="mw_value" value="' . ( $queue['shuffle'] ? 'off' : 'on' ) . '" data-mw-queue-value>'
				. '<button type="submit" class="mw-shuffle-button" data-mw-queue-action="shuffle" data-mw-value="' . esc_attr( $queue['shuffle'] ? 'off' : 'on' ) . '"' . ( $queue['shuffle'] ? ' aria-pressed="true"' : ' aria-pressed="false"' ) . '>'
				. esc_html( $shuffle_label )
				. '</button>'
				. $this->hidden_fields()
				. '</form>';
		}

		if ( $show_repeat ) {
			// Professional repeat: segmented control for JS (auto-save), select fallback for no-JS.
			$repeat_current = in_array( $queue['repeat'], array( 'off', 'all', 'one' ), true ) ? $queue['repeat'] : 'off';
			$markup        .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-mw-queue-form data-mw-queue-action="repeat" class="mw-queue-repeat-form">'
				. '<div class="mw-queue-repeat-segmented" role="group" aria-label="' . esc_attr__( 'حالت تکرار', 'music-wave-core' ) . '">'
				. '<button type="button" class="mw-queue-repeat-option' . ( 'off' === $repeat_current ? ' is-active' : '' ) . '" data-mw-queue-repeat-option value="off" aria-pressed="' . ( 'off' === $repeat_current ? 'true' : 'false' ) . '">' . esc_html__( 'تکرار: خاموش', 'music-wave-core' ) . '</button>'
				. '<button type="button" class="mw-queue-repeat-option' . ( 'all' === $repeat_current ? ' is-active' : '' ) . '" data-mw-queue-repeat-option value="all" aria-pressed="' . ( 'all' === $repeat_current ? 'true' : 'false' ) . '">' . esc_html__( 'تکرار: همه', 'music-wave-core' ) . '</button>'
				. '<button type="button" class="mw-queue-repeat-option' . ( 'one' === $repeat_current ? ' is-active' : '' ) . '" data-mw-queue-repeat-option value="one" aria-pressed="' . ( 'one' === $repeat_current ? 'true' : 'false' ) . '">' . esc_html__( 'تکرار: یک', 'music-wave-core' ) . '</button>'
				. '</div>'
				. '<label class="screen-reader-text" for="mw-queue-repeat">' . esc_html__( 'حالت تکرار', 'music-wave-core' ) . '</label>'
				. '<select id="mw-queue-repeat" name="mw_value" class="mw-queue-repeat-fallback" data-mw-queue-value>'
				. '<option value="off"' . selected( 'off', $repeat_current, false ) . '>' . esc_html__( 'تکرار: خاموش', 'music-wave-core' ) . '</option>'
				. '<option value="all"' . selected( 'all', $repeat_current, false ) . '>' . esc_html__( 'تکرار: همه', 'music-wave-core' ) . '</option>'
				. '<option value="one"' . selected( 'one', $repeat_current, false ) . '>' . esc_html__( 'تکرار: یک', 'music-wave-core' ) . '</option>'
				. '</select>'
				. '<input type="hidden" name="mw_operation" value="repeat">'
				. '<button type="submit" class="mw-queue-repeat-save" data-mw-queue-action="repeat">' . esc_html__( 'ذخیره تکرار', 'music-wave-core' ) . '</button>'
				. $this->hidden_fields()
				. '</form>';
		}

		$markup .= '</div>';

		return $markup;
	}

	/**
	 * Clear-the-whole-queue form.
	 */
	private function clear_markup(): string {
		return '<form class="mw-playback-queue__clear" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-mw-queue-form data-mw-queue-action="clear">'
			. '<input type="hidden" name="mw_operation" value="clear">'
			. '<button type="submit" data-mw-queue-action="clear">' . esc_html__( 'پاک کردن صف', 'music-wave-core' ) . '</button>'
			. $this->hidden_fields()
			. '</form>';
	}

	/**
	 * Shared hidden fields for every queue form.
	 */
	private function hidden_fields(): string {
		return BlockSupport::hidden_action_fields( QueueFormHandler::NONCE, QueueFormHandler::ACTION );
	}

	/**
	 * Post/redirect/get notice rendered in a polite live region.
	 */
	private function notice_markup(): string {
		$notice  = isset( $_GET[ QueueFormHandler::NOTICE_ARG ] ) && is_scalar( $_GET[ QueueFormHandler::NOTICE_ARG ] ) ? sanitize_key( wp_unslash( (string) $_GET[ QueueFormHandler::NOTICE_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$message = '' !== $notice ? $this->forms->notice_message( $notice ) : '';

		return BlockSupport::prg_notice( $message, '' !== $notice && $this->forms->notice_is_error( $notice ), 'mw-playback-queue__notice' );
	}

	/**
	 * Enqueue progressive-enhancement script for queue controls.
	 * Robust gating: queue is only for signed-in listeners, has_block() only scans main post,
	 * so we enqueue for any logged-in front-end view where block might appear.
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {
		if ( is_admin() || get_current_user_id() < 1 ) {
			return;
		}
		wp_enqueue_script(
			'music-wave-queue',
			MUSIC_WAVE_CORE_URL . 'assets/queue.js',
			array(),
			MUSIC_WAVE_CORE_VERSION,
			true
		);
		wp_localize_script(
			'music-wave-queue',
			'musicWaveQueue',
			array(
				'adminPostUrl' => esc_url_raw( admin_url( 'admin-post.php' ) ),
				'restUrl'      => esc_url_raw( rest_url() ),
				'restNonce'    => wp_create_nonce( 'wp_rest' ),
				'labels'       => array(
					'removed'        => __( 'از صف حذف شد.', 'music-wave-core' ),
					'moved'          => __( 'ترتیب صف به‌روز شد.', 'music-wave-core' ),
					'cleared'        => __( 'صف پاک شد.', 'music-wave-core' ),
					'shuffleUpdated' => __( 'اولویت ترکیبی به‌روزرسانی شد.', 'music-wave-core' ),
					'repeatUpdated'  => __( 'تکرار اولویت به‌روزرسانی شد.', 'music-wave-core' ),
					'shuffleOn'      => __( 'تصادفی: روشن', 'music-wave-core' ),
					'shuffleOff'     => __( 'پخش تصادفی: خاموش', 'music-wave-core' ),
					'empty'          => __( 'صف شما خالی است.', 'music-wave-core' ),
					'queued'         => __( 'به صف افزوده شد.', 'music-wave-core' ),
					'queueRemoved'   => __( 'از صف حذف شد.', 'music-wave-core' ),
					'error'          => __( 'عملیات انجام نشد. دوباره تلاش کنید.', 'music-wave-core' ),
				),
			)
		);
	}
}
