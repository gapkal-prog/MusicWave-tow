<?php
/**
 * No-JavaScript & AJAX playback queue mutations.
 *
 * Shared transport helpers come from FormActionResponse (DRY + SRP).
 * Queue business logic in run() is shared with ListeningRoutes::queue_action()
 * so REST and plain HTML form POSTs obey one single source of truth.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Listening;

use ManaCore\MusicWave\Core\Support\FormActionResponse;
use ManaCore\MusicWave\Core\Account\AuthPolicy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class QueueFormHandler {
	use FormActionResponse;

	public const ACTION     = 'mw_queue';
	public const NONCE      = 'mw_queue_action';
	public const NOTICE_ARG = 'mw-queue-notice';

	/** @var ListeningRepository */
	private $repository;

	public function __construct( ListeningRepository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * @return void
	 */
	public function register(): void {
		$this->allow_frontend_admin_post( self::ACTION );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'handle_unauthenticated' ) );
	}

	/**
	 * Handle one queue form submission.
	 *
	 * @return void
	 */
	public function handle(): void {
		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			$this->handle_unauthenticated();

			return;
		}

		$redirect = $this->redirect_target();
		if ( ! $this->verify_form_nonce( self::NONCE ) ) {
			if ( $this->is_ajax_request() ) {
				$this->send_json( 'invalid', true, $user_id );
				return;
			}
			$this->redirect_with_args( $redirect, array( self::NOTICE_ARG => 'invalid' ), 303 );

			return;
		}

		$operation  = isset( $_POST['mw_operation'] ) && is_scalar( $_POST['mw_operation'] ) ? sanitize_key( wp_unslash( (string) $_POST['mw_operation'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$release_id = isset( $_POST['mw_release_id'] ) ? absint( wp_unslash( (string) $_POST['mw_release_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$value      = isset( $_POST['mw_value'] ) && is_scalar( $_POST['mw_value'] ) ? sanitize_key( wp_unslash( (string) $_POST['mw_value'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$notice = $this->run( $operation, $user_id, $release_id, $value );

		if ( $this->is_ajax_request() ) {
			$this->send_json( $notice, $this->notice_is_error( $notice ), $user_id );
			return;
		}

		$this->redirect_with_args( $redirect, array( self::NOTICE_ARG => $notice ), 303 );
	}

	/**
	 * Send signed-out visitors to the login screen instead of failing silently.
	 *
	 * @return void
	 */
	public function handle_unauthenticated(): void {
		if ( $this->is_ajax_request() ) {
			$this->send_json( 'guest', true, 0 );
			return;
		}
		$target = AuthPolicy::login_url( $this->redirect_target() );
		if ( function_exists( 'wp_safe_redirect' ) ) {
			wp_safe_redirect( $target, 302 );
			$this->finish_request();
		}
	}

	/**
	 * Build structured response payload shared by admin-post AJAX and REST.
	 *
	 * @return array<string, mixed>
	 */
	public function response_payload( string $notice, int $user_id = 0 ): array {
		$is_error = $this->notice_is_error( $notice );
		$payload  = array(
			'notice'   => $notice,
			'message'  => $this->notice_message( $notice ),
			'is_error' => $is_error,
		);
		if ( $user_id > 0 ) {
			$payload['queue'] = $this->repository->queue( $user_id );
		}
		return $payload;
	}

	/**
	 * Send JSON response for progressive-enhancement queue controls.
	 */
	private function send_json( string $notice, bool $is_error, int $user_id = 0 ): void {
		$payload             = $this->response_payload( $notice, $user_id );
		$payload['is_error'] = $is_error;
		$this->send_json_payload( $payload, $is_error ? 400 : 200 );
	}

	/**
	 * Execute one validated operation.
	 *
	 * Exposed for tests & REST routes: no superglobals, no redirects, no output.
	 *
	 * @return string Notice code.
	 */
	public function run( string $operation, int $user_id, int $release_id = 0, string $value = '' ): string {
		if ( $user_id < 1 ) {
			return 'guest';
		}

		$queue = $this->repository->queue( $user_id );

		switch ( $operation ) {
			case 'add':
				if ( $release_id < 1 ) {
					return 'add-failed';
				}
				if ( in_array( $release_id, $queue['ids'], true ) ) {
					return 'already-queued';
				}

				$ids = $queue['ids'];
				if ( 'end' === $value ) {
					$ids[] = $release_id;
				} else {
					array_splice( $ids, min( count( $ids ), $queue['position'] + 1 ), 0, array( $release_id ) );
				}

				$persisted = $this->persist(
					$user_id,
					array(
						'ids'      => $ids,
						'position' => $queue['position'],
						'shuffle'  => $queue['shuffle'],
						'repeat'   => $queue['repeat'],
					)
				);

				return $persisted && in_array( $release_id, $this->repository->queue( $user_id )['ids'], true )
					? 'added'
					: 'add-failed';

			case 'remove-item':
				$index = array_search( $release_id, $queue['ids'], true );
				if ( false === $index ) {
					return 'item-remove-failed';
				}

				$ids      = array_values( array_diff( $queue['ids'], array( $release_id ) ) );
				$position = $queue['position'];
				if ( (int) $index < $position ) {
					--$position;
				}
				$position = min( $position, max( 0, count( $ids ) - 1 ) );

				return $this->persist(
					$user_id,
					array(
						'ids'      => $ids,
						'position' => $position,
						'shuffle'  => $queue['shuffle'],
						'repeat'   => $queue['repeat'],
					)
				) ? 'item-removed' : 'item-remove-failed';

			case 'move-up':
			case 'move-down':
				return $this->move( $operation, $user_id, $queue, $release_id );

			case 'clear':
				return $this->persist( $user_id, array() ) ? 'cleared' : 'clear-failed';

			case 'shuffle':
				if ( ! in_array( $value, array( 'on', 'off' ), true ) ) {
					$value = $queue['shuffle'] ? 'off' : 'on';
				}
				return $this->persist(
					$user_id,
					array(
						'ids'      => $queue['ids'],
						'position' => $queue['position'],
						'shuffle'  => 'on' === $value,
						'repeat'   => $queue['repeat'],
					)
				) ? 'shuffle-updated' : 'shuffle-failed';

			case 'repeat':
				if ( ! in_array( $value, array( 'off', 'all', 'one' ), true ) ) {
					return 'repeat-failed';
				}

				return $this->persist(
					$user_id,
					array(
						'ids'      => $queue['ids'],
						'position' => $queue['position'],
						'shuffle'  => $queue['shuffle'],
						'repeat'   => $value,
					)
				) ? 'repeat-updated' : 'repeat-failed';
		}

		return 'invalid';
	}

	/**
	 * Translated notice text for one notice code.
	 */
	public function notice_message( string $notice ): string {
		$messages = array(
			'added'              => __( 'به صف شما اضافه شد', 'music-wave-core' ),
			'add-failed'         => __( 'آن انتشار را نمی‌توان به صف اضافه کرد.', 'music-wave-core' ),
			'already-queued'     => __( 'در حال حاضر در صف شما.', 'music-wave-core' ),
			'item-removed'       => __( 'از صف حذف شد.', 'music-wave-core' ),
			'item-remove-failed' => __( 'آن انتشار در صف شما نیست.', 'music-wave-core' ),
			'moved'              => __( 'ترتیب صف به‌روز شد.', 'music-wave-core' ),
			'move-failed'        => __( 'ترتیب صف را نمی‌توان تغییر داد.', 'music-wave-core' ),
			'cleared'            => __( 'صف پاک شد.', 'music-wave-core' ),
			'clear-failed'       => __( 'صف پاک نشد.', 'music-wave-core' ),
			'shuffle-updated'    => __( 'حالت پخش تصادفی به‌روزرسانی شد.', 'music-wave-core' ),
			'shuffle-failed'     => __( 'ترجیح حالت تصادفی ذخیره نشد.', 'music-wave-core' ),
			'repeat-updated'     => __( 'حالت تکرار به‌روزرسانی شد.', 'music-wave-core' ),
			'repeat-failed'      => __( 'اولویت تکرار ذخیره نشد.', 'music-wave-core' ),
			'invalid'            => __( 'آن درخواست صف معتبر نبود. دوباره امتحان کنید.', 'music-wave-core' ),
			'guest'              => __( 'برای مدیریت صف خود وارد سیستم شوید.', 'music-wave-core' ),
		);

		return isset( $messages[ $notice ] ) ? $messages[ $notice ] : '';
	}

	/**
	 * Whether one notice code represents a failure.
	 */
	public function notice_is_error( string $notice ): bool {
		return in_array( $notice, array( 'invalid', 'guest' ), true )
			|| false !== strpos( $notice, '-failed' );
	}

	/**
	 * Swap one release with its neighbor inside the durable queue.
	 *
	 * @param array<string, mixed> $queue Normalized queue snapshot.
	 * @return string Notice code.
	 */
	private function move( string $operation, int $user_id, array $queue, int $release_id ): string {
		$order = $queue['ids'];
		$index = array_search( $release_id, $order, true );
		if ( false === $index ) {
			return 'move-failed';
		}

		$target = 'move-up' === $operation ? (int) $index - 1 : (int) $index + 1;
		if ( $target < 0 || $target > count( $order ) - 1 ) {
			return 'move-failed';
		}

		$swap             = $order[ $target ];
		$order[ $target ] = $release_id;
		$order[ $index ]  = $swap;

		$position = $queue['position'];
		if ( $position === (int) $index ) {
			$position = $target;
		} elseif ( $position === $target ) {
			$position = (int) $index;
		}

		return $this->persist(
			$user_id,
			array(
				'ids'      => $order,
				'position' => $position,
				'shuffle'  => $queue['shuffle'],
				'repeat'   => $queue['repeat'],
			)
		) ? 'moved' : 'move-failed';
	}

	/**
	 * Persist one normalized queue payload through the repository boundary.
	 *
	 * @param array<string, mixed> $payload Raw payload; the repository revalidates everything.
	 */
	private function persist( int $user_id, array $payload ): bool {
		return $this->repository->save_queue( $user_id, $payload );
	}
}
