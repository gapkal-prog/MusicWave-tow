<?php
/**
 * No-JavaScript playlist mutations.
 *
 * Every playlist action is reachable through a plain HTML form posted to
 * `admin-post.php`: create, rename, change visibility, delete, add, remove, and
 * reorder. Shared transport concerns live in FormActionResponse (DRY + SRP).
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Playlists;

use ManaCore\MusicWave\Core\Support\FormActionResponse;
use ManaCore\MusicWave\Core\Account\AuthPolicy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PlaylistFormHandler {
	use FormActionResponse;

	public const ACTION      = 'mw_playlist';
	public const NONCE       = 'mw_playlist_action';
	public const NOTICE_ARG  = 'mw-playlist-notice';
	public const CURRENT_ARG = 'mw-playlist';

	/** @var PlaylistRepository */
	private $repository;

	public function __construct( PlaylistRepository $repository ) {
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
	 * Handle one playlist form submission.
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
			$this->redirect( $redirect, 'invalid', 0 );

			return;
		}

		$operation   = isset( $_POST['mw_operation'] ) && is_scalar( $_POST['mw_operation'] ) ? sanitize_key( wp_unslash( (string) $_POST['mw_operation'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$playlist_id = isset( $_POST['mw_playlist_id'] ) ? absint( wp_unslash( (string) $_POST['mw_playlist_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$release_id  = isset( $_POST['mw_release_id'] ) ? absint( wp_unslash( (string) $_POST['mw_release_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$title       = isset( $_POST['mw_title'] ) && is_scalar( $_POST['mw_title'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['mw_title'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$visibility  = isset( $_POST['mw_visibility'] ) && is_scalar( $_POST['mw_visibility'] ) ? sanitize_key( wp_unslash( (string) $_POST['mw_visibility'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		list( $notice, $current ) = $this->run( $operation, $user_id, $playlist_id, $release_id, $title, $visibility );

		$this->redirect( $redirect, $notice, $current );
	}

	/**
	 * Send signed-out visitors to the login screen instead of failing silently.
	 *
	 * @return void
	 */
	public function handle_unauthenticated(): void {
		$target = AuthPolicy::login_url( $this->redirect_target() );
		if ( function_exists( 'wp_safe_redirect' ) ) {
			wp_safe_redirect( $target, 302 );
			$this->finish_request();
		}
	}

	/**
	 * Execute one validated operation.
	 *
	 * Exposed for tests: no superglobals, no redirects, no output.
	 *
	 * @return array{0: string, 1: int} Notice code and the playlist to focus.
	 */
	public function run( string $operation, int $user_id, int $playlist_id = 0, int $release_id = 0, string $title = '', string $visibility = '' ): array {
		if ( $user_id < 1 ) {
			return array( 'guest', 0 );
		}

		switch ( $operation ) {
			case 'create':
				$created = $this->repository->create( $user_id, $title, $visibility );

				return $created > 0
					? array( 'created', $created )
					: array( 'create-failed', 0 );

			case 'update':
				$changes = array();
				if ( '' !== $title ) {
					$changes['title'] = $title;
				}
				if ( '' !== $visibility ) {
					$changes['visibility'] = $visibility;
				}

				return $this->repository->update( $user_id, $playlist_id, $changes )
					? array( 'updated', $playlist_id )
					: array( 'update-failed', $playlist_id );

			case 'delete':
				return $this->repository->delete( $user_id, $playlist_id )
					? array( 'deleted', 0 )
					: array( 'delete-failed', $playlist_id );

			case 'add-item':
				if ( $this->repository->add_item( $user_id, $playlist_id, $release_id ) ) {
					return array( 'item-added', $playlist_id );
				}
				$playlist = $this->repository->find( $playlist_id );
				if ( null !== $playlist && (int) $playlist['user_id'] === $user_id && $this->repository->contains( $playlist_id, $release_id ) ) {
					return array( 'already-in-playlist', $playlist_id );
				}

				return array( 'item-add-failed', $playlist_id );

			case 'remove-item':
				return $this->repository->remove_item( $user_id, $playlist_id, $release_id )
					? array( 'item-removed', $playlist_id )
					: array( 'item-remove-failed', $playlist_id );

			case 'move-up':
			case 'move-down':
				return $this->move( $operation, $user_id, $playlist_id, $release_id );
		}

		return array( 'invalid', $playlist_id );
	}

	/**
	 * Translated notice text for one notice code.
	 */
	public function notice_message( string $notice ): string {
		$messages = array(
			'created'             => __( 'فهرست پخش ایجاد شد.', 'music-wave-core' ),
			'create-failed'       => __( 'ایجاد فهرست پخش ناموفق بود. نامی وارد کنید و محدودیت تعداد فهرست‌ها را بررسی کنید.', 'music-wave-core' ),
			'updated'             => __( 'فهرست پخش به‌روزرسانی شد.', 'music-wave-core' ),
			'update-failed'       => __( 'به‌روزرسانی آن فهرست پخش انجام نشد.', 'music-wave-core' ),
			'deleted'             => __( 'فهرست پخش حذف شد.', 'music-wave-core' ),
			'delete-failed'       => __( 'حذف آن فهرست پخش انجام نشد.', 'music-wave-core' ),
			'item-added'          => __( 'به فهرست پخش اضافه شد.', 'music-wave-core' ),
			'already-in-playlist' => __( 'در حال حاضر در آن فهرست پخش است.', 'music-wave-core' ),
			'item-add-failed'     => __( 'افزودن آن انتشار به فهرست پخش انجام نشد.', 'music-wave-core' ),
			'item-removed'        => __( 'از فهرست پخش حذف شد.', 'music-wave-core' ),
			'item-remove-failed'  => __( 'حذف آن انتشار از فهرست پخش انجام نشد.', 'music-wave-core' ),
			'moved'               => __( 'ترتیب فهرست پخش به‌روز شد.', 'music-wave-core' ),
			'move-failed'         => __( 'تغییر ترتیب آن انتشار انجام نشد.', 'music-wave-core' ),
			'invalid'             => __( 'آن درخواست فهرست پخش معتبر نبود. دوباره امتحان کنید.', 'music-wave-core' ),
			'guest'               => __( 'برای مدیریت فهرست‌های پخش خود وارد سیستم شوید.', 'music-wave-core' ),
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
	 * Swap one release with its neighbor inside a playlist.
	 *
	 * @return array{0: string, 1: int}
	 */
	private function move( string $operation, int $user_id, int $playlist_id, int $release_id ): array {
		$view = $this->repository->view( $playlist_id, $user_id );
		if ( null === $view || empty( $view['owner'] ) ) {
			return array( 'move-failed', $playlist_id );
		}

		$order = array();
		foreach ( $view['items'] as $item ) {
			$order[] = (int) $item['release_id'];
		}

		$index = array_search( $release_id, $order, true );
		if ( false === $index ) {
			return array( 'move-failed', $playlist_id );
		}

		$target = 'move-up' === $operation ? (int) $index - 1 : (int) $index + 1;
		if ( $target < 0 || $target > count( $order ) - 1 ) {
			return array( 'move-failed', $playlist_id );
		}

		$swap             = $order[ $target ];
		$order[ $target ] = $release_id;
		$order[ $index ]  = $swap;

		return $this->repository->reorder( $user_id, $playlist_id, $order )
			? array( 'moved', $playlist_id )
			: array( 'move-failed', $playlist_id );
	}

	/**
	 * @return void
	 */
	private function redirect( string $target, string $notice, int $playlist_id ): void {
		$args = array( self::NOTICE_ARG => $notice );
		if ( $playlist_id > 0 ) {
			$args[ self::CURRENT_ARG ] = (string) $playlist_id;
		}

		$this->redirect_with_args( $target, $args, 303 );
	}
}
