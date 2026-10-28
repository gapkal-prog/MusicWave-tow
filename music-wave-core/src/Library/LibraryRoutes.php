<?php
/**
 * Personal library REST routes for signed-in customers.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Library;

use ManaCore\MusicWave\Core\Support\Concerns\AuthenticatedRestPermissions;
use ManaCore\MusicWave\Core\Support\RouteRateLimiter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LibraryRoutes {
	use AuthenticatedRestPermissions;

	/** @var LibraryRepository */
	private $repository;

	/** @var LibraryCatalog */
	private $catalog;

	public function __construct( LibraryRepository $repository, LibraryCatalog $catalog, ?RouteRateLimiter $rate_limiter = null ) {
		$this->repository   = $repository;
		$this->catalog      = $catalog;
		$this->rate_limiter = $rate_limiter;
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			'music-wave/v1',
			'/library',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'index' ),
				'permission_callback' => array( $this, 'authenticated' ),
			)
		);
		register_rest_route(
			'music-wave/v1',
			'/library/items',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'store' ),
					'permission_callback' => array( $this, 'may_write' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'destroy' ),
					'permission_callback' => array( $this, 'may_write' ),
				),
			)
		);
	}

	protected function unauthenticated_message(): string {
		return __( 'برای مدیریت کتابخانه موسیقی شخصی خود وارد سیستم شوید.', 'music-wave-core' );
	}

	protected function default_rate_bucket(): string {
		return 'library';
	}

	/**
	 * List the caller's library with display summaries and filter counts.
	 *
	 * @return WP_REST_Response
	 */
	public function index( WP_REST_Request $request ) {
		$filter = $request->get_param( 'filter' );
		$filter = is_scalar( $filter ) ? sanitize_key( (string) $filter ) : LibraryCatalog::FILTER_ALL;

		return new WP_REST_Response( $this->payload( get_current_user_id(), $filter ), 200 );
	}

	/**
	 * Add one release or artist to the caller's library.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function store( WP_REST_Request $request ) {
		list( $type, $item_id ) = $this->item_params( $request );
		if ( '' === $type || $item_id < 1 ) {
			return new WP_Error( 'mw_library_invalid_item', __( 'یک انتشار معتبر یا هنرمند را برای افزودن انتخاب کنید.', 'music-wave-core' ), array( 'status' => 400 ) );
		}

		$user_id = get_current_user_id();
		if ( $this->repository->has( $user_id, $type, $item_id ) ) {
			return new WP_REST_Response( $this->state_payload( $user_id, 'in' ), 200 );
		}

		if ( ! $this->repository->add( $user_id, $type, $item_id ) ) {
			return new WP_Error( 'mw_library_add_failed', __( 'این مورد به کتابخانه شما اضافه نشد.', 'music-wave-core' ), array( 'status' => 422 ) );
		}

		/**
		 * Fires after a customer adds an item to the personal library.
		 *
		 * @param string $type    Item type (release|artist).
		 * @param int    $item_id Release or artist term ID.
		 * @param int    $user_id Library owner.
		 */
		do_action( 'music_wave_library_item_added', $type, $item_id, $user_id );

		return new WP_REST_Response( $this->state_payload( $user_id, 'in' ), 201 );
	}

	/**
	 * Remove one release or artist from the caller's library.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function destroy( WP_REST_Request $request ) {
		list( $type, $item_id ) = $this->item_params( $request );
		if ( '' === $type || $item_id < 1 ) {
			return new WP_Error( 'mw_library_invalid_item', __( 'انتشار یا هنرمند معتبری را برای حذف انتخاب کنید.', 'music-wave-core' ), array( 'status' => 400 ) );
		}

		$user_id = get_current_user_id();
		if ( $this->repository->remove( $user_id, $type, $item_id ) ) {
			do_action( 'music_wave_library_item_removed', $type, $item_id, $user_id );
		}

		return new WP_REST_Response( $this->state_payload( $user_id, 'out' ), 200 );
	}

	/**
	 * Read and validate the shared type/id request parameters.
	 *
	 * @return array{0: string, 1: int}
	 */
	private function item_params( WP_REST_Request $request ): array {
		$type = $request->get_param( 'type' );
		$type = is_scalar( $type ) ? sanitize_key( (string) $type ) : '';
		$type = in_array( $type, $this->repository->types(), true ) ? $type : '';

		return array( $type, absint( $request->get_param( 'id' ) ) );
	}

	/**
	 * Response payload returned after every add/remove operation.
	 *
	 * @return array<string, mixed>
	 */
	private function state_payload( int $user_id, string $state ): array {
		return array(
			'state'  => $state,
			'total'  => $this->repository->count( $user_id ),
			'counts' => $this->catalog->counts( $user_id ),
		);
	}

	/**
	 * Full library payload for GET requests.
	 *
	 * @return array<string, mixed>
	 */
	private function payload( int $user_id, string $filter ): array {
		return array(
			'items'  => $this->catalog->summaries( $user_id, $filter ),
			'total'  => $this->repository->count( $user_id ),
			'counts' => $this->catalog->counts( $user_id ),
			'filter' => $filter,
		);
	}
}
