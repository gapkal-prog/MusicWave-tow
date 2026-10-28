<?php
/**
 * User Request Tracking & Collapsible Conversation Drawer Presenter.
 *
 * Renders the requester's submitted requests inside the unified account dashboard
 * panel or the standalone `music-wave/dashboard-requests` block. All reply and
 * conversation content stays cleanly collapsed underneath each item until the user
 * clicks the action button (never shown upfront).
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Account;

use ManaCore\MusicWave\Core\Blocks\BlockSupport;
use ManaCore\MusicWave\Core\Requests\RequestChatHandler;
use ManaCore\MusicWave\Core\Requests\RequestPostType;
use ManaCore\MusicWave\Core\Requests\RequestRepository;
use ManaCore\MusicWave\Core\Requests\RequestSettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RequestTrackingRenderer {
	public const SCRIPT_HANDLE = 'music-wave-request-chat';
	public const STYLE_HANDLE  = 'music-wave-request-chat';

	/** @var RequestRepository */
	private $repository;

	/** @var RequestSettings */
	private $settings;

	/** @var RequestChatHandler */
	private $chat_handler;

	public function __construct(
		?RequestRepository $repository = null,
		?RequestSettings $settings = null,
		?RequestChatHandler $chat_handler = null
	) {
		$this->repository   = null !== $repository ? $repository : new RequestRepository();
		$this->settings     = null !== $settings ? $settings : new RequestSettings();
		$this->chat_handler = null !== $chat_handler ? $chat_handler : new RequestChatHandler( $this->repository, $this->settings );
	}

	/**
	 * Render the request tracking block or dashboard panel.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render( array $attributes = array() ): string {
		$compact        = BlockSupport::bool_attribute( $attributes, 'compact', true );
		$per_page       = BlockSupport::range_attribute( $attributes, 'perPage', 1, 20, 6 );
		$show_status    = BlockSupport::bool_attribute( $attributes, 'showStatus', true );
		$show_reference = BlockSupport::bool_attribute( $attributes, 'showReference', true );
		$heading_text   = BlockSupport::optional_text_attribute( $attributes, 'heading', __( 'پیگیری درخواست‌ها', 'music-wave-core' ), 'showHeading' );
		$show_heading   = '' !== $heading_text;

		$classes = array( 'mw-dashboard-requests' );
		if ( $compact ) {
			$classes[] = 'mw-dashboard-requests--compact';
		}
		$wrapper_attrs = BlockSupport::wrapper_attributes( implode( ' ', $classes ) );

		$this->enqueue_styles();

		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			return '<section ' . $wrapper_attrs . '>'
				. ( $show_heading ? '<h3 class="mw-dashboard-requests__heading">' . esc_html( $heading_text ) . '</h3>' : '' )
				. '<div class="mw-dashboard-requests__empty">'
				. '<p>' . esc_html__( 'برای مشاهده درخواست‌های خود وارد شوید.', 'music-wave-core' ) . '</p>'
				. '<a class="wp-element-button is-style-outline" href="' . esc_url( AuthPolicy::login_url( BlockSupport::current_url() ) ) . '">' . esc_html__( 'ورود', 'music-wave-core' ) . '</a>'
				. '</div></section>';
		}

		$repo = function_exists( 'apply_filters' ) ? apply_filters( 'music_wave_request_repository', $this->repository ) : $this->repository;
		if ( ! ( $repo instanceof RequestRepository ) ) {
			$repo = $this->repository;
		}

		$filtered_chat = function_exists( 'apply_filters' ) ? apply_filters( 'music_wave_request_chat_enabled', null ) : null;
		$chat_enabled  = null !== $filtered_chat ? (bool) $filtered_chat : $this->settings->chat_enabled();

		$user_obj   = function_exists( 'get_userdata' ) ? get_userdata( $user_id ) : null;
		$user_email = is_object( $user_obj ) && isset( $user_obj->user_email ) ? (string) $user_obj->user_email : '';
		$result     = $repo->query_for_user( $user_id, $user_email, 1, $per_page );
		$items      = isset( $result['items'] ) && is_array( $result['items'] ) ? $result['items'] : array();
		$total      = isset( $result['total'] ) ? (int) $result['total'] : count( $items );

		$status_labels = RequestPostType::status_labels();
		$type_labels   = RequestPostType::type_labels();
		$redirect_url  = BlockSupport::current_url();
		$requests_url  = $this->requests_url();

		$chat_notice = isset( $_GET[ RequestChatHandler::NOTICE_ARG ] ) && is_scalar( $_GET[ RequestChatHandler::NOTICE_ARG ] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? sanitize_key( wp_unslash( (string) $_GET[ RequestChatHandler::NOTICE_ARG ] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: '';
		$notice_text = '' !== $chat_notice ? $this->chat_handler->notice_message( $chat_notice ) : '';

		if ( ! empty( $items ) ) {
			$this->enqueue_scripts();
		}

		ob_start();
		echo '<section ' . $wrapper_attrs . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		if ( $show_heading && '' !== $heading_text ) {
			echo '<h3 class="mw-dashboard-requests__heading">' . esc_html( $heading_text ) . '</h3>';
		}
		if ( '' !== $notice_text ) {
			echo '<div class="mw-request-chat__banner" role="status">' . esc_html( $notice_text ) . '</div>';
		}

		if ( array() === $items ) {
			echo '<div class="mw-dashboard-requests__empty">'
				. '<span class="mw-dashboard-requests__empty-icon" aria-hidden="true">✦</span>'
				. '<p>' . esc_html__( 'هنوز درخواستی ثبت نکرده‌اید.', 'music-wave-core' ) . '</p>'
				. '<a class="wp-element-button is-style-outline" href="' . esc_url( $requests_url ) . '">' . esc_html__( 'ثبت درخواست جدید', 'music-wave-core' ) . '</a>'
				. '</div>';
		} else {
			echo '<div class="mw-dashboard-requests__list">';
			foreach ( $items as $req ) {
				if ( ! is_array( $req ) ) {
					continue;
				}
				echo $this->render_item( $req, $status_labels, $type_labels, $show_status, $show_reference, $chat_enabled, $redirect_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside render_item().
			}
			echo '</div>';
			if ( $total > $per_page ) {
				echo '<div class="mw-dashboard-requests__more"><a class="wp-element-button is-style-outline" href="' . esc_url( $requests_url ) . '">' . esc_html__( 'مشاهده همه درخواست‌ها', 'music-wave-core' ) . '</a></div>';
			}
		}

		echo '</section>';
		return (string) ob_get_clean();
	}

	/**
	 * Render one request card with its collapsible reply & conversation drawer.
	 *
	 * @param array<string, mixed>  $req            Request item record.
	 * @param array<string, string> $status_labels  Map of status key => translated label.
	 * @param array<string, string> $type_labels    Map of request type key => translated label.
	 */
	private function render_item(
		array $req,
		array $status_labels,
		array $type_labels,
		bool $show_status,
		bool $show_reference,
		bool $chat_enabled,
		string $redirect_url
	): string {
		$request_id   = isset( $req['id'] ) ? (int) $req['id'] : 0;
		$subject      = isset( $req['subject'] ) && '' !== trim( (string) $req['subject'] )
			? (string) $req['subject']
			: ( isset( $req['title'] ) ? (string) $req['title'] : '' );
		$reference    = isset( $req['reference'] ) && '' !== (string) $req['reference']
			? (string) $req['reference']
			: ( $request_id > 0 ? RequestRepository::reference( $request_id ) : '' );
		$status_key   = isset( $req['status'] ) ? (string) $req['status'] : '';
		$status_label = isset( $status_labels[ $status_key ] ) ? $status_labels[ $status_key ] : ( '' !== $status_key ? $status_key : __( 'ثبت شده', 'music-wave-core' ) );
		$type_key     = isset( $req['type'] ) ? (string) $req['type'] : '';
		$type_label   = isset( $type_labels[ $type_key ] ) ? $type_labels[ $type_key ] : $type_key;
		$date_label   = $this->resolve_date( $req, $request_id );

		$thread      = RequestRepository::conversation_entries( $req );
		$reply_count = count( $thread );
		$can_expand  = $request_id > 0 && ( $chat_enabled || $reply_count > 0 );
		$drawer_id   = 'mw-request-thread-' . $request_id;
		$state_class = $this->status_class( $status_key );

		ob_start();
		?>
		<article
			class="mw-dashboard-requests__item <?php echo esc_attr( $state_class ); ?> mw-dashboard-requests__item--<?php echo esc_attr( $status_key ); ?>"
			data-mw-request-card="<?php echo esc_attr( (string) $request_id ); ?>"
			data-mw-request-thread="<?php echo esc_attr( (string) $request_id ); ?>"
		>
			<div class="mw-dashboard-requests__item-head">
				<div class="mw-dashboard-requests__item-main">
					<h4 class="mw-dashboard-requests__item-title"><?php echo esc_html( $subject ); ?></h4>
					<div class="mw-dashboard-requests__item-meta">
						<?php if ( $show_reference && '' !== $reference ) : ?>
							<span class="mw-dashboard-requests__ref"><?php echo esc_html( $reference ); ?></span>
						<?php endif; ?>
						<?php if ( '' !== $type_label ) : ?>
							<span class="mw-dashboard-requests__type"><?php echo esc_html( $type_label ); ?></span>
						<?php endif; ?>
						<?php if ( '' !== $date_label ) : ?>
							<time class="mw-dashboard-requests__date"><?php echo esc_html( $date_label ); ?></time>
						<?php endif; ?>
					</div>
				</div>
				<?php if ( $show_status ) : ?>
					<div class="mw-dashboard-requests__item-aside">
						<span class="mw-dashboard-requests__item-status" data-mw-request-status>
							<span class="mw-dashboard-requests__status-dot" aria-hidden="true"></span>
							<span class="mw-dashboard-requests__status-label" data-mw-request-status-label><?php echo esc_html( $status_label ); ?></span>
						</span>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( $can_expand ) : ?>
				<div class="mw-request-chat__bar">
					<button
						type="button"
						class="mw-request-chat__toggle"
						data-mw-chat-toggle="<?php echo esc_attr( (string) $request_id ); ?>"
						aria-expanded="false"
						aria-controls="<?php echo esc_attr( $drawer_id ); ?>"
					>
						<span class="mw-request-chat__toggle-label">
							<?php
							if ( ! $chat_enabled ) {
								echo esc_html__( 'مشاهده پاسخ', 'music-wave-core' );
							} elseif ( $reply_count > 0 ) {
								echo esc_html__( 'پاسخ و ادامه گفتگو', 'music-wave-core' );
							} else {
								echo esc_html__( 'ارسال پیام / گفتگو', 'music-wave-core' );
							}
							?>
						</span>
						<?php if ( $reply_count > 0 ) : ?>
							<span class="mw-request-chat__count" data-mw-chat-count><?php echo esc_html( (string) $reply_count ); ?></span>
						<?php endif; ?>
						<svg class="mw-request-chat__chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
							<polyline points="6 9 12 15 18 9"></polyline>
						</svg>
					</button>
				</div>

				<div id="<?php echo esc_attr( $drawer_id ); ?>" class="mw-request-chat__thread-wrap" hidden>
					<div class="mw-request-chat__thread" data-mw-chat-list data-mw-chat-thread>
						<?php if ( empty( $thread ) ) : ?>
							<p class="mw-request-chat__empty" data-mw-chat-empty><?php echo esc_html__( 'هنوز پیامی در این گفتگو ثبت نشده است.', 'music-wave-core' ); ?></p>
						<?php else : ?>
							<?php foreach ( $thread as $entry ) : ?>
								<?php $is_user = 'user' === $entry['by']; ?>
								<div class="mw-request-chat__message mw-request-chat__message--<?php echo $is_user ? 'user' : 'admin'; ?>">
									<div class="mw-request-chat__message-meta">
										<strong><?php echo esc_html( $is_user ? __( 'شما', 'music-wave-core' ) : __( 'پشتیبانی', 'music-wave-core' ) ); ?></strong>
										<?php if ( '' !== $entry['time_label'] ) : ?>
											<time><?php echo esc_html( $entry['time_label'] ); ?></time>
										<?php endif; ?>
									</div>
									<div class="mw-request-chat__message-body"><?php echo nl2br( esc_html( $entry['body'] ) ); ?></div>
								</div>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>

					<?php if ( $chat_enabled ) : ?>
						<form
							class="mw-request-chat__form"
							method="post"
							action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
							data-mw-chat-form="<?php echo esc_attr( (string) $request_id ); ?>"
							data-mw-request-chat-form
							data-mw-request-id="<?php echo esc_attr( (string) $request_id ); ?>"
						>
							<?php echo wp_nonce_field( RequestChatHandler::NONCE, '_wpnonce', true, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by WordPress helper. ?>
							<input type="hidden" name="action" value="<?php echo esc_attr( RequestChatHandler::ACTION ); ?>">
							<input type="hidden" name="mw_request_id" value="<?php echo esc_attr( (string) $request_id ); ?>">
							<input type="hidden" name="mw_redirect" value="<?php echo esc_attr( $redirect_url ); ?>">
							<label class="screen-reader-text" for="mw-chat-<?php echo esc_attr( (string) $request_id ); ?>"><?php echo esc_html__( 'پیام شما', 'music-wave-core' ); ?></label>
							<textarea
								id="mw-chat-<?php echo esc_attr( (string) $request_id ); ?>"
								name="mw_chat_message"
								rows="2"
								maxlength="4000"
								required
								placeholder="<?php esc_attr_e( 'پاسخ یا پیام خود را بنویسید…', 'music-wave-core' ); ?>"
							></textarea>
							<div class="mw-request-chat__actions">
								<button type="submit" class="wp-element-button mw-request-chat__submit"><?php echo esc_html__( 'ارسال پیام', 'music-wave-core' ); ?></button>
								<span class="mw-request-chat__feedback" role="status" aria-live="polite" data-mw-chat-feedback></span>
							</div>
						</form>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</article>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * @param array<string, mixed> $req        Request item record.
	 * @param int                  $request_id Post ID.
	 */
	private function resolve_date( array $req, int $request_id ): string {
		if ( isset( $req['created'] ) && (int) $req['created'] > 0 && function_exists( 'wp_date' ) ) {
			return (string) wp_date( get_option( 'date_format' ), (int) $req['created'] );
		}
		if ( $request_id > 0 && function_exists( 'get_the_date' ) ) {
			return (string) get_the_date( '', $request_id );
		}
		return '';
	}

	private function enqueue_styles(): void {
		if ( BlockSupport::is_editor_context() || ! function_exists( 'wp_enqueue_style' ) ) {
			return;
		}
		wp_enqueue_style(
			self::STYLE_HANDLE,
			MUSIC_WAVE_CORE_URL . 'assets/request-chat.css',
			array(),
			MUSIC_WAVE_CORE_VERSION
		);
	}

	private function enqueue_scripts(): void {
		if ( BlockSupport::is_editor_context() || ! function_exists( 'wp_enqueue_script' ) ) {
			return;
		}
		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			MUSIC_WAVE_CORE_URL . 'assets/request-chat.js',
			array(),
			MUSIC_WAVE_CORE_VERSION,
			true
		);
		wp_localize_script(
			self::SCRIPT_HANDLE,
			'musicWaveRequestChat',
			array(
				'restBase'     => esc_url_raw( rest_url( 'music-wave/v1' ) ),
				'adminPostUrl' => esc_url_raw( admin_url( 'admin-post.php' ) ),
				'restNonce'    => wp_create_nonce( 'wp_rest' ),
				'labels'       => array(
					'sent'        => __( 'پیام شما ارسال شد.', 'music-wave-core' ),
					'sending'     => __( 'در حال ارسال…', 'music-wave-core' ),
					'empty'       => __( 'لطفاً متن پیام را وارد کنید.', 'music-wave-core' ),
					'emptyThread' => __( 'هنوز پیامی در این گفتگو ثبت نشده است.', 'music-wave-core' ),
					'you'         => __( 'شما', 'music-wave-core' ),
					'support'     => __( 'پشتیبانی', 'music-wave-core' ),
					'continue'    => __( 'پاسخ و ادامه گفتگو', 'music-wave-core' ),
					'error'       => __( 'عملیات انجام نشد. دوباره تلاش کنید.', 'music-wave-core' ),
				),
			)
		);
	}

	private function requests_url(): string {
		if ( function_exists( 'musicwave_route_url' ) ) {
			$route = (string) musicwave_route_url( 'requests' );
			if ( '' !== $route ) {
				return $route;
			}
		}

		return home_url( '/requests/' );
	}

	private function status_class( string $status ): string {
		if ( in_array( $status, array( RequestPostType::STATUS_ACCEPTED, RequestPostType::STATUS_REPLIED, 'publish' ), true ) ) {
			return 'mw-dashboard-requests__item--approved';
		}
		if ( in_array( $status, array( RequestPostType::STATUS_DECLINED, RequestPostType::STATUS_ARCHIVED ), true ) ) {
			return 'mw-dashboard-requests__item--rejected';
		}

		return 'mw-dashboard-requests__item--pending';
	}
}
