<?php
/**
 * Public submission endpoint for song requests and collaboration proposals.
 *
 * Works without JavaScript: the block posts a regular form to
 * `admin-post.php`, this handler validates it (nonce, honeypot, timing
 * check, per-actor rate limit, field validation), stores it, sends the
 * receipt and the manager alert, then redirects back with a notice
 * (post/redirect/get). Field errors travel back through a short-lived
 * transient keyed by a random token so the URL never carries user data.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Requests;

use ManaCore\MusicWave\Core\Discovery\DiscoveryRateLimiter;
use ManaCore\MusicWave\Core\Support\FormActionResponse;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RequestFormHandler {
	use FormActionResponse;

	public const ACTION     = 'music_wave_submit_request';
	public const NONCE      = 'music_wave_request';
	public const NOTICE_ARG = 'mw-request';
	public const TOKEN_ARG  = 'mw-request-token';
	public const HONEYPOT   = 'mw_request_website';
	public const TIMER      = 'mw_request_opened';
	public const KINDS      = 'mw_request_kinds';
	public const MIN_FILL   = 3;

	/** @var RequestRepository */
	private $repository;

	/** @var RequestMailer */
	private $mailer;

	/** @var RequestSettings */
	private $settings;

	/** @var DiscoveryRateLimiter|null */
	private $rate_limiter;

	public function __construct( RequestRepository $repository, ?RequestMailer $mailer = null, ?RequestSettings $settings = null, ?DiscoveryRateLimiter $rate_limiter = null ) {
		$this->repository   = $repository;
		$this->settings     = null !== $settings ? $settings : new RequestSettings();
		$this->mailer       = null !== $mailer ? $mailer : new RequestMailer( $this->settings );
		$this->rate_limiter = $rate_limiter;
	}

	/**
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Handle one form submission end-to-end.
	 *
	 * @return void
	 */
	public function handle(): void {
		$redirect = $this->redirect_target();
		// wp_verify_nonce() instead of check_admin_referer(): the latter dies
		// with a bare "link expired" screen, which is the wrong experience for
		// a visitor whose form sat in a page cache. They get a notice instead.
		$nonce = isset( $_POST['_wpnonce'] ) && is_scalar( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['_wpnonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- this is the verification.
		$kinds = isset( $_POST[ self::KINDS ] ) && is_scalar( $_POST[ self::KINDS ] ) ? self::parse_kinds( sanitize_text_field( wp_unslash( (string) $_POST[ self::KINDS ] ) ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- bound into the nonce action verified next.
		if ( '' === $nonce || false === wp_verify_nonce( $nonce, self::nonce_action( $kinds ) ) ) {
			$this->redirect( $redirect, 'invalid' );

			return;
		}

		$input = array();
		foreach ( array_merge( RequestSubmission::fields(), array( self::HONEYPOT, self::TIMER, self::KINDS ) ) as $field ) {
			if ( ! isset( $_POST[ $field ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by check_admin_referer() above.
				continue;
			}
			$raw             = wp_unslash( $_POST[ $field ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field by RequestSubmission::validate().
			$input[ $field ] = is_array( $raw ) ? array_map( 'strval', $raw ) : (string) $raw;
		}

		$result = $this->run( $input, get_current_user_id(), time() );
		$this->redirect( $redirect, $result['notice'], $result['token'] );
	}

	/**
	 * Execute one submission without superglobals or output (test seam).
	 *
	 * @param array<string, mixed> $input Unslashed form values.
	 * @return array{notice: string, token: string, request_id: int, errors: array<string, string>}
	 */
	public function run( array $input, int $user_id, int $now ): array {
		if ( ! $this->settings->form_enabled() ) {
			return $this->outcome( 'closed' );
		}

		// Bots fill hidden fields and submit instantly; humans do neither.
		if ( isset( $input[ self::HONEYPOT ] ) && '' !== trim( (string) $input[ self::HONEYPOT ] ) ) {
			return $this->outcome( 'sent' ); // Silent success: never teach the bot.
		}
		$opened = isset( $input[ self::TIMER ] ) ? (int) $input[ self::TIMER ] : 0;
		if ( $opened > 0 && $now - $opened < self::MIN_FILL ) {
			return $this->outcome( 'too-fast' );
		}

		if ( ! $this->limiter()->allow( 'requests' ) ) {
			return $this->outcome( 'throttled' );
		}

		// Smart: if user is logged in, enforce their real profile data for name/email/phone
		// to prevent spoofing and to make the form professional (readonly in UI).
		if ( $user_id > 0 ) {
			$profile = $this->resolve_user_profile( $user_id );
			if ( '' !== $profile['name'] ) {
				$input['name'] = $profile['name'];
			}
			if ( '' !== $profile['email'] ) {
				$input['email'] = $profile['email'];
			}
			// Phone is optional – if profile has one, enforce it; otherwise keep submitted value.
			if ( '' !== $profile['phone'] ) {
				$input['phone'] = $profile['phone'];
			}
		}

		$validated = RequestSubmission::validate( $input, $now, $this->allowed_types( $input ) );
		if ( array() !== $validated['errors'] ) {
			return $this->outcome( 'errors', '', 0, $validated['errors'], $validated['data'] );
		}

		$request_id = $this->repository->create( $validated['data'], $user_id, 'form' );
		if ( $request_id < 1 ) {
			return $this->outcome( 'failed' );
		}

		$request = $this->repository->find( $request_id );
		if ( null !== $request ) {
			$this->mailer->send_receipt( $request );
			$this->mailer->send_manager_alert( $request, RequestsAdminPage::url( array( 'request' => $request_id ) ) );

			/**
			 * Fires after a public request was stored and notifications sent.
			 *
			 * @param int                  $request_id Request ID.
			 * @param array<string, mixed> $request    Normalized request.
			 */
			do_action( 'music_wave_request_created', $request_id, $request );
		}

		return $this->outcome( 'sent', '', $request_id );
	}

	/**
	 * Nonce action for a form that offers the given request kinds.
	 *
	 * Binding the kinds into the action means a dedicated page (song-only,
	 * collaboration-only) issues nonces that are only valid for its own list;
	 * a POST claiming a wider list fails verification. Forms without a kinds
	 * field (cached pages from before this field existed) keep the bare
	 * action.
	 *
	 * @param array<int, string> $kinds Request kinds offered by the form.
	 */
	public static function nonce_action( array $kinds ): string {
		$kinds = self::parse_kinds( implode( ',', $kinds ) );
		if ( array() === $kinds ) {
			return self::NONCE;
		}
		sort( $kinds );

		return self::NONCE . ':' . implode( ',', $kinds );
	}

	/**
	 * Request kinds this submission may file.
	 *
	 * Always a subset of the kinds the manager enabled; when the form sent
	 * its own (nonce-bound) list, that list narrows it further. An empty
	 * intersection falls back to the manager's list so an outdated form can
	 * never lock visitors out entirely.
	 *
	 * @param array<string, mixed> $input Unslashed form values.
	 * @return array<int, string>
	 */
	public function allowed_types( array $input ): array {
		$enabled = array_map( 'strval', array_keys( $this->settings->enabled_types() ) );
		$kinds   = isset( $input[ self::KINDS ] ) && is_scalar( $input[ self::KINDS ] ) ? self::parse_kinds( (string) $input[ self::KINDS ] ) : array();
		if ( array() === $kinds ) {
			return $enabled;
		}
		$allowed = array_values( array_intersect( $enabled, $kinds ) );

		return array() !== $allowed ? $allowed : $enabled;
	}

	/**
	 * Parse a comma-separated kinds list into known, unique request kinds.
	 *
	 * @return array<int, string>
	 */
	private static function parse_kinds( string $raw ): array {
		$known = array_keys( RequestPostType::type_labels() );
		$kinds = array();
		foreach ( explode( ',', strtolower( $raw ) ) as $kind ) {
			$kind = (string) preg_replace( '/[^a-z0-9_\-]/', '', trim( $kind ) );
			if ( '' !== $kind && in_array( $kind, $known, true ) && ! in_array( $kind, $kinds, true ) ) {
				$kinds[] = $kind;
			}
		}

		return $kinds;
	}

	/**
	 * Translated notice for a notice code; empty for unknown codes.
	 */
	public function notice_message( string $notice ): string {
		$custom = $this->settings->success_notice();
		switch ( $notice ) {
			case 'sent':
				return '' !== $custom ? $custom : __( 'درخواست شما ثبت شد. یک ایمیل تأیید برایتان فرستادیم و به‌زودی پاسخ می‌دهیم.', 'music-wave-core' );
			case 'errors':
				return __( 'لطفاً موارد مشخص‌شده را اصلاح کنید و دوباره ارسال کنید.', 'music-wave-core' );
			case 'invalid':
				return __( 'فرم منقضی شده بود. لطفاً دوباره تلاش کنید.', 'music-wave-core' );
			case 'too-fast':
				return __( 'ارسال خیلی سریع انجام شد. لطفاً چند لحظه صبر کنید و دوباره ارسال کنید.', 'music-wave-core' );
			case 'throttled':
				return __( 'در مدت کوتاهی چند درخواست فرستاده‌اید. لطفاً کمی بعد دوباره تلاش کنید.', 'music-wave-core' );
			case 'closed':
				return __( 'در حال حاضر درخواست جدیدی پذیرفته نمی‌شود.', 'music-wave-core' );
			case 'failed':
				return __( 'ذخیرهٔ درخواست ممکن نشد. لطفاً بعداً دوباره تلاش کنید.', 'music-wave-core' );
		}

		return '';
	}

	/**
	 * Whether a notice code represents a failure.
	 */
	public function notice_is_error( string $notice ): bool {
		return in_array( $notice, array( 'errors', 'invalid', 'too-fast', 'throttled', 'closed', 'failed' ), true );
	}

	/**
	 * Field errors and previous values stashed for one redirect round-trip.
	 *
	 * @return array{errors: array<string, string>, values: array<string, mixed>}
	 */
	public function stashed( string $token ): array {
		$empty = array(
			'errors' => array(),
			'values' => array(),
		);
		$token = (string) preg_replace( '/[^a-f0-9]/', '', strtolower( $token ) );
		if ( '' === $token ) {
			return $empty;
		}
		$stash = get_transient( 'mw_request_form_' . $token );
		if ( ! is_array( $stash ) ) {
			return $empty;
		}

		return array(
			'errors' => isset( $stash['errors'] ) && is_array( $stash['errors'] ) ? $stash['errors'] : array(),
			'values' => isset( $stash['values'] ) && is_array( $stash['values'] ) ? $stash['values'] : array(),
		);
	}

	/**
	 * Shape one outcome and persist field errors for the redirect.
	 *
	 * @param array<string, string> $errors Field errors.
	 * @param array<string, mixed>  $values Sanitized values to refill.
	 * @return array{notice: string, token: string, request_id: int, errors: array<string, string>}
	 */
	private function outcome( string $notice, string $token = '', int $request_id = 0, array $errors = array(), array $values = array() ): array {
		if ( array() !== $errors ) {
			$token = bin2hex( random_bytes( 8 ) );
			unset( $values['consent'] );
			set_transient(
				'mw_request_form_' . $token,
				array(
					'errors' => $errors,
					'values' => $values,
				),
				10 * 60
			);
		}

		return array(
			'notice'     => $notice,
			'token'      => $token,
			'request_id' => $request_id,
			'errors'     => $errors,
		);
	}

	/**
	 * Resolve logged-in user profile for smart form prefilling.
	 * Returns name, email, phone – all sanitized, phone from billing_phone if available.
	 *
	 * @return array{name: string, email: string, phone: string}
	 */
	private function resolve_user_profile( int $user_id ): array {
		$user = function_exists( 'get_user_by' ) ? get_user_by( 'id', $user_id ) : null;
		if ( ! $user ) {
			return array(
				'name'  => '',
				'email' => '',
				'phone' => '',
			);
		}
		$name  = '' !== $user->display_name ? $user->display_name : $user->user_login;
		$email = strtolower( (string) $user->user_email );

		$phone = '';
		if ( function_exists( 'get_user_meta' ) ) {
			// WooCommerce billing_phone is the most reliable.
			$candidates = array( 'billing_phone', '_billing_phone', 'phone', 'mw_phone', 'billing_phone_number' );
			foreach ( $candidates as $key ) {
				$val = get_user_meta( $user_id, $key, true );
				if ( is_string( $val ) && '' !== trim( $val ) ) {
					$phone = trim( $val );
					break;
				}
			}
		}

		return array(
			'name'  => sanitize_text_field( $name ),
			'email' => sanitize_email( $email ),
			'phone' => sanitize_text_field( $phone ),
		);
	}

	private function limiter(): DiscoveryRateLimiter {
		if ( null === $this->rate_limiter ) {
			$this->rate_limiter = new DiscoveryRateLimiter( $this->settings->rate_limit(), $this->settings->rate_window() );
		}

		return $this->rate_limiter;
	}

	/**
	 * @return void
	 */
	private function redirect( string $target, string $notice, string $token = '' ): void {
		$target = remove_query_arg( array( self::NOTICE_ARG, self::TOKEN_ARG ), $target );
		$args   = array( self::NOTICE_ARG => $notice );
		if ( '' !== $token ) {
			$args[ self::TOKEN_ARG ] = $token;
		}
		$location = add_query_arg( $args, $target ) . ( 'sent' !== $notice ? '#mw-request-form' : '#mw-request-notice' );

		if ( function_exists( 'wp_safe_redirect' ) ) {
			wp_safe_redirect( $location, 303 );
		}
		$this->finish_request();
	}
}
