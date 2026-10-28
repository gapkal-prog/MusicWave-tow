<?php
/**
 * Requester-side conversation controller for request tracking.
 *
 * Allows a signed-in requester to view conversation history and send follow-up
 * replies whenever the manager enables chat in admin settings. Reuses
 * FormActionResponse, RouteRateLimiter, RequestRepository, and RequestMailer
 * (100% SOLID + DRY).
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Requests;

use ManaCore\MusicWave\Core\Support\FormActionResponse;
use ManaCore\MusicWave\Core\Support\RouteRateLimiter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use ManaCore\MusicWave\Core\Account\AuthPolicy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RequestChatHandler {
	use FormActionResponse;

	public const ACTION     = 'mw_request_chat';
	public const NONCE      = 'mw_request_chat';
	public const NOTICE_ARG = 'mw-request-chat';

	/** @var RequestRepository */
	private $repository;

	/** @var RequestSettings */
	private $settings;

	/** @var RequestMailer */
	private $mailer;

	/** @var RouteRateLimiter|null */
	private $rate_limiter;

	public function __construct( RequestRepository $repository, ?RequestSettings $settings = null, ?RequestMailer $mailer = null, ?RouteRateLimiter $rate_limiter = null ) {
		$this->repository   = $repository;
		$this->settings     = null !== $settings ? $settings : new RequestSettings();
		$this->mailer       = null !== $mailer ? $mailer : new RequestMailer( $this->settings );
		$this->rate_limiter = $rate_limiter;
	}

	public function register(): void {
		$this->allow_frontend_admin_post( self::ACTION );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'handle_unauthenticated' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest' ) );
	}

	public function register_rest(): void {
		register_rest_route(
			'music-wave/v1',
			'/requests/(?P<id>\d+)/thread',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_thread' ),
				'permission_callback' => array( $this, 'can_read_thread' ),
			)
		);
		register_rest_route(
			'music-wave/v1',
			'/requests/(?P<id>\d+)/chat',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_rest' ),
				'permission_callback' => array( $this, 'can_post_chat' ),
			)
		);
	}

	/** @return true|WP_Error */
	public function can_read_thread( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			return new WP_Error( 'mw_auth', __( 'برای مشاهده گفتگو وارد شوید.', 'music-wave-core' ), array( 'status' => 401 ) );
		}
		$found = $this->repository->find( absint( $request->get_param( 'id' ) ) );
		if ( null === $found ) {
			return new WP_Error( 'mw_not_found', __( 'درخواست یافت نشد.', 'music-wave-core' ), array( 'status' => 404 ) );
		}
		if ( ! $this->user_owns_or_manages( $found, $user_id ) ) {
			return new WP_Error( 'mw_forbidden', __( 'شما اجازه دسترسی به این درخواست را ندارید.', 'music-wave-core' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/** @return true|WP_Error */
	public function can_post_chat( WP_REST_Request $request ) {
		$read_check = $this->can_read_thread( $request );
		if ( is_wp_error( $read_check ) ) {
			return $read_check;
		}
		if ( ! $this->settings->chat_enabled() ) {
			return new WP_Error( 'mw_chat_disabled', __( 'چت و گفتگو در حال حاضر غیرفعال است.', 'music-wave-core' ), array( 'status' => 403 ) );
		}
		$limiter = null !== $this->rate_limiter ? $this->rate_limiter : new RouteRateLimiter();
		if ( ! $limiter->allow( 'listening', 'reqchat_u' . (string) get_current_user_id() ) ) {
			return new WP_Error( 'mw_rate_limited', __( 'تعداد پیام‌های ارسالی بیش از حد مجاز است. کمی بعد دوباره تلاش کنید.', 'music-wave-core' ), array( 'status' => 429 ) );
		}
		return true;
	}

	public function get_thread( WP_REST_Request $request ): WP_REST_Response {
		$request_id = absint( $request->get_param( 'id' ) );
		return new WP_REST_Response( $this->thread_payload( $request_id, 'ok', false ), 200 );
	}

	/** @return WP_REST_Response|WP_Error */
	public function handle_rest( WP_REST_Request $request ) {
		$request_id = absint( $request->get_param( 'id' ) );
		$raw        = $request->get_param( 'message' );
		$message    = $this->clean_message( is_scalar( $raw ) ? (string) $raw : '' );

		$notice = $this->submit_message( $request_id, get_current_user_id(), $message );
		if ( 'sent' !== $notice ) {
			return new WP_Error( 'mw_' . $notice, $this->notice_message( $notice ), array( 'status' => 400 ) );
		}

		return new WP_REST_Response( $this->thread_payload( $request_id, 'sent', false ), 200 );
	}

	public function handle(): void {
		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			$this->handle_unauthenticated();
			return;
		}
		$redirect = $this->redirect_target();
		if ( ! $this->verify_form_nonce( self::NONCE ) ) {
			$this->respond( $redirect, 'invalid', true, 0 );
			return;
		}
		if ( ! $this->settings->chat_enabled() ) {
			$this->respond( $redirect, 'chat-disabled', true, 0 );
			return;
		}

		$request_id = isset( $_POST['mw_request_id'] ) ? absint( wp_unslash( (string) $_POST['mw_request_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$raw        = isset( $_POST['mw_chat_message'] ) && is_scalar( $_POST['mw_chat_message'] ) ? (string) wp_unslash( $_POST['mw_chat_message'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$message    = $this->clean_message( $raw );

		$notice = $this->submit_message( $request_id, $user_id, $message );
		$this->respond( $redirect, $notice, 'sent' !== $notice, $request_id );
	}

	public function handle_unauthenticated(): void {
		if ( $this->is_ajax_request() ) {
			$this->send_json_payload( $this->thread_payload( 0, 'guest', true ), 401 );
			return;
		}
		$target = AuthPolicy::login_url( $this->redirect_target() );
		if ( function_exists( 'wp_safe_redirect' ) ) {
			wp_safe_redirect( $target, 302 );
			$this->finish_request();
		}
	}

	/**
	 * Validate ownership & message, append log entry, update status, notify managers.
	 */
	public function submit_message( int $request_id, int $user_id, string $message ): string {
		if ( $user_id < 1 ) {
			return 'guest';
		}
		if ( ! $this->settings->chat_enabled() ) {
			return 'chat-disabled';
		}
		if ( $request_id < 1 || '' === $message ) {
			return 'empty';
		}
		if ( mb_strlen( $message ) > RequestSubmission::MAX_MESSAGE ) {
			return 'too-long';
		}
		$found = $this->repository->find( $request_id );
		if ( null === $found || ! $this->user_owns_or_manages( $found, $user_id ) ) {
			return 'forbidden';
		}

		if ( 0 === (int) $found['user_id'] ) {
			update_post_meta( $request_id, RequestRepository::META_USER, $user_id );
		}

		$entry = $this->repository->append_log(
			$request_id,
			RequestRepository::LOG_REPLY,
			$message,
			$user_id,
			array( 'by' => 'user' )
		);
		if ( null === $entry ) {
			return 'failed';
		}

		if ( in_array( (string) $found['status'], array( RequestPostType::STATUS_REPLIED, RequestPostType::STATUS_ACCEPTED, RequestPostType::STATUS_DECLINED, RequestPostType::STATUS_ARCHIVED ), true ) ) {
			$this->repository->set_status( $request_id, RequestPostType::STATUS_REVIEW, $user_id );
		}

		$this->mailer->send_user_reply_alert( $found, $message, RequestsAdminPage::url( array( 'request' => $request_id ) ) );

		return 'sent';
	}

	public function notice_message( string $notice ): string {
		$messages = array(
			'sent'          => __( 'پیام شما ارسال شد.', 'music-wave-core' ),
			'empty'         => __( 'لطفاً متن پیام را وارد کنید.', 'music-wave-core' ),
			'failed'        => __( 'ارسال پیام ناموفق بود.', 'music-wave-core' ),
			'invalid'       => __( 'درخواست نامعتبر بود. صفحه را نوسازی کنید.', 'music-wave-core' ),
			'forbidden'     => __( 'شما اجازه ارسال پیام در این درخواست را ندارید.', 'music-wave-core' ),
			'guest'         => __( 'برای ادامه گفتگو وارد حساب کاربری شوید.', 'music-wave-core' ),
			'chat-disabled' => __( 'چت و گفتگو در حال حاضر غیرفعال است.', 'music-wave-core' ),
			'too-long'      => __( 'متن پیام بیش از حد مجاز طولانی است.', 'music-wave-core' ),
		);
		return isset( $messages[ $notice ] ) ? $messages[ $notice ] : '';
	}

	/**
	 * @param array<string, mixed> $request Normalized request.
	 */
	private function user_owns_or_manages( array $request, int $user_id ): bool {
		if ( current_user_can( RequestPostType::MANAGE_CAP ) ) {
			return true;
		}
		$user  = function_exists( 'get_userdata' ) ? get_userdata( $user_id ) : null;
		$email = is_object( $user ) && isset( $user->user_email ) ? (string) $user->user_email : '';
		return $this->repository->is_owned_by_user( $request, $user_id, $email );
	}

	private function clean_message( string $raw ): string {
		$clean = function_exists( 'sanitize_textarea_field' ) ? sanitize_textarea_field( $raw ) : trim( strip_tags( $raw ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return trim( $clean );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function thread_payload( int $request_id, string $notice, bool $is_error ): array {
		$found  = $request_id > 0 ? $this->repository->find( $request_id ) : null;
		$status = is_array( $found ) ? (string) $found['status'] : '';
		$labels = RequestPostType::status_labels();
		return array(
			'notice'       => $notice,
			'message'      => $this->notice_message( $notice ),
			'is_error'     => $is_error,
			'status'       => $status,
			'status_label' => isset( $labels[ $status ] ) ? $labels[ $status ] : $status,
			'thread'       => is_array( $found ) ? RequestRepository::conversation_entries( $found ) : array(),
		);
	}

	private function respond( string $redirect, string $notice, bool $is_error, int $request_id ): void {
		if ( $this->is_ajax_request() ) {
			$this->send_json_payload( $this->thread_payload( $request_id, $notice, $is_error ), $is_error ? 400 : 200 );
			return;
		}
		$this->redirect_with_args( $redirect, array( self::NOTICE_ARG => $notice ), 303 );
	}
}
