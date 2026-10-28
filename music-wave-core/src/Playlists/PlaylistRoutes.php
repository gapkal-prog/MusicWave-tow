<?php
/**
 * Playlist REST surface.
 *
 * Mutations require authentication and ownership; reads are permitted for
 * anyone but every response is filtered through the playlist privacy rules,
 * so a denied viewer receives the same 404-style error whether the playlist is
 * missing or merely private (PROJECT_PLAN.md Stage 5 deliverable 4).
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Playlists;

use ManaCore\MusicWave\Core\Access\AccessPolicyEngine;
use ManaCore\MusicWave\Core\Access\AccessSubject;
use ManaCore\MusicWave\Core\Catalog\ReleasePostType;
use ManaCore\MusicWave\Core\Catalog\ReleaseVisibility;
use ManaCore\MusicWave\Core\Contracts\ReleaseRepository;
use ManaCore\MusicWave\Core\Playback\TrackStreamPresenter;
use ManaCore\MusicWave\Core\Support\Concerns\AuthenticatedRestPermissions;
use ManaCore\MusicWave\Core\Support\RouteRateLimiter;
use ManaCore\MusicWave\Core\Support\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PlaylistRoutes {
	use AuthenticatedRestPermissions;

	private const PLAYBACK_MAX_TRACKS = 50;

	/** @var PlaylistRepository */
	private $repository;

	/** @var AccessPolicyEngine|null */
	private $policy;

	/** @var ReleaseRepository|null */
	private $releases;

	public function __construct( PlaylistRepository $repository, ?AccessPolicyEngine $policy = null, ?ReleaseRepository $releases = null, ?RouteRateLimiter $rate_limiter = null ) {
		$this->repository   = $repository;
		$this->policy       = $policy;
		$this->releases     = $releases;
		$this->rate_limiter = $rate_limiter;
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			'music-wave/v1',
			'/playlists',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'authenticated' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'store' ),
					'permission_callback' => array( $this, 'may_write' ),
				),
			)
		);
		register_rest_route(
			'music-wave/v1',
			'/playlists/(?P<id>[0-9]+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'show' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'may_write' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'destroy' ),
					'permission_callback' => array( $this, 'may_write' ),
				),
			)
		);
		register_rest_route(
			'music-wave/v1',
			'/playlists/(?P<id>[0-9]+)/items',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'add_item' ),
					'permission_callback' => array( $this, 'may_write' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'remove_item' ),
					'permission_callback' => array( $this, 'may_write' ),
				),
			)
		);
		register_rest_route(
			'music-wave/v1',
			'/playlists/(?P<id>[0-9]+)/order',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'reorder' ),
				'permission_callback' => array( $this, 'may_write' ),
			)
		);
		register_rest_route(
			'music-wave/v1',
			'/playlists/(?P<id>[0-9]+)/playback-queue',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'playback_queue' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id'    => array(
						'type'     => 'integer',
						'required' => true,
					),
					'share' => array(
						'type'     => 'string',
						'required' => false,
					),
				),
			)
		);
		register_rest_route(
			'music-wave/v1',
			'/playlists/public',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'public_index' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 12,
					),
					'search'   => array(
						'type'    => 'string',
						'default' => '',
					),
					'orderby'  => array(
						'type'    => 'string',
						'default' => 'updated_at',
					),
				),
			)
		);
	}

	protected function unauthenticated_message(): string {
		return __( 'برای مدیریت فهرست‌های پخش وارد سیستم شوید.', 'music-wave-core' );
	}

	protected function default_rate_bucket(): string {
		return 'playlists';
	}

	/** @return WP_REST_Response */
	public function index( WP_REST_Request $request ): WP_REST_Response {
		$page  = max( 1, absint( $request->get_param( 'page' ) ) );
		$limit = min( PlaylistRepository::MAX_PLAYLISTS, max( 1, absint( $request->get_param( 'per_page' ) ) ) );

		return new WP_REST_Response(
			array(
				'items' => $this->repository->for_user( get_current_user_id(), $limit, ( $page - 1 ) * $limit ),
				'page'  => $page,
			),
			200
		);
	}

	/** @return WP_REST_Response|WP_Error */
	public function store( WP_REST_Request $request ) {
		$user_id     = get_current_user_id();
		$title_param = trim( (string) $request->get_param( 'title' ) );
		$visibility  = (string) $request->get_param( 'visibility' );

		if ( '' === $title_param ) {
			return new WP_Error( 'mw_playlist_invalid_title', __( 'لطفاً یک نام فهرست پخش وارد کنید.', 'music-wave-core' ), array( 'status' => 422 ) );
		}
		$limits = PlaylistLimits::for_user( $user_id );
		if ( $this->repository->count_for_user( $user_id ) >= $limits['max_playlists'] ) {
			return new WP_Error(
				'mw_playlist_limit_reached',
				sprintf(
					/* translators: %d: max playlists */
					__( 'شما به حداکثر %d فهرست پخش رسیده اید.', 'music-wave-core' ),
					$limits['max_playlists']
				),
				array( 'status' => 422 )
			);
		}

		$playlist_id = $this->repository->create( $user_id, $title_param, $visibility );

		if ( $playlist_id < 1 ) {
			return new WP_Error( 'mw_playlist_create_failed', __( 'فهرست پخش ایجاد نشد. نام و محدودیت فهرست پخش خود را بررسی کنید.', 'music-wave-core' ), array( 'status' => 422 ) );
		}

		return new WP_REST_Response( $this->repository->view( $playlist_id, $user_id ), 201 );
	}

	/** @return WP_REST_Response|WP_Error */
	public function show( WP_REST_Request $request ) {
		$view = $this->repository->view(
			absint( $request->get_param( 'id' ) ),
			get_current_user_id(),
			(string) $request->get_param( 'share' )
		);

		if ( null === $view ) {
			return $this->not_found();
		}

		$response = new WP_REST_Response( $view, 200 );
		// Never let a shared or personal playlist land in a shared cache.
		$response->header( 'Cache-Control', PlaylistRepository::VISIBILITY_PUBLIC === $view['visibility'] && false === $view['owner'] ? 'public, max-age=300' : 'no-store, private' );

		return $response;
	}

	/** @return WP_REST_Response|WP_Error */
	public function update( WP_REST_Request $request ) {
		$playlist_id = absint( $request->get_param( 'id' ) );
		$changes     = array();
		if ( null !== $request->get_param( 'title' ) ) {
			$changes['title'] = (string) $request->get_param( 'title' );
		}
		if ( null !== $request->get_param( 'visibility' ) ) {
			$changes['visibility'] = (string) $request->get_param( 'visibility' );
		}

		if ( ! $this->repository->update( get_current_user_id(), $playlist_id, $changes ) ) {
			return $this->not_found();
		}

		return new WP_REST_Response( $this->repository->view( $playlist_id, get_current_user_id() ), 200 );
	}

	/** @return WP_REST_Response|WP_Error */
	public function destroy( WP_REST_Request $request ) {
		if ( ! $this->repository->delete( get_current_user_id(), absint( $request->get_param( 'id' ) ) ) ) {
			return $this->not_found();
		}

		return new WP_REST_Response( array( 'deleted' => true ), 200 );
	}

	/** @return WP_REST_Response|WP_Error */
	public function add_item( WP_REST_Request $request ) {
		$playlist_id = absint( $request->get_param( 'id' ) );
		$release_id  = absint( $request->get_param( 'release_id' ) );
		$user_id     = get_current_user_id();

		// Explicit validation so the UI can show a precise message instead of a generic 422.
		if ( $playlist_id < 1 || $release_id < 1 ) {
			return $this->rejected( __( 'آن انتشار را نمی‌توان به فهرست پخش اضافه کرد.', 'music-wave-core' ), 'mw_playlist_invalid_release' );
		}

		$playlist = $this->repository->find( $playlist_id );
		if ( null === $playlist || (int) $playlist['user_id'] !== $user_id ) {
			// Don't leak existence of other users' private playlists.
			return $this->not_found();
		}

		$visibility = new ReleaseVisibility();
		if ( ! $visibility->is_release( $release_id ) ) {
			return new WP_Error( 'mw_playlist_invalid_release', __( 'آن مورد منتشر نشده است و نمی‌توان آن را به فهرست پخش اضافه کرد.', 'music-wave-core' ), array( 'status' => 422 ) );
		}
		if ( ! $visibility->can_read( $release_id ) ) {
			return new WP_Error( 'mw_playlist_invalid_release', __( 'این انتشار در حال حاضر برای فهرست‌های پخش در دسترس نیست.', 'music-wave-core' ), array( 'status' => 422 ) );
		}

		// Idempotent: adding the same release twice is a no-op (200) — avoids noisy 422 in console.
		if ( $this->repository->contains( $playlist_id, $release_id ) ) {
			return new WP_REST_Response( $this->repository->view( $playlist_id, $user_id ), 200 );
		}

		$limits = PlaylistLimits::for_user( $user_id );
		if ( $this->repository->count_items( $playlist_id ) >= $limits['max_items'] ) {
			return new WP_Error(
				'mw_playlist_limit_reached',
				sprintf(
					/* translators: %d: max items */
					__( 'این فهرست پخش به حداکثر %d قطعه رسیده است.', 'music-wave-core' ),
					$limits['max_items']
				),
				array( 'status' => 422 )
			);
		}

		if ( ! $this->repository->add_item( $user_id, $playlist_id, $release_id ) ) {
			return $this->rejected( __( 'آن انتشار را نمی‌توان به فهرست پخش اضافه کرد.', 'music-wave-core' ) );
		}

		return new WP_REST_Response( $this->repository->view( $playlist_id, $user_id ), 201 );
	}

	/** @return WP_REST_Response|WP_Error */
	public function remove_item( WP_REST_Request $request ) {
		$playlist_id = absint( $request->get_param( 'id' ) );
		$release_id  = absint( $request->get_param( 'release_id' ) );

		if ( ! $this->repository->remove_item( get_current_user_id(), $playlist_id, $release_id ) ) {
			return $this->rejected( __( 'آن انتشار در این فهرست پخش نیست.', 'music-wave-core' ) );
		}

		return new WP_REST_Response( $this->repository->view( $playlist_id, get_current_user_id() ), 200 );
	}

	/** @return WP_REST_Response|WP_Error */
	public function reorder( WP_REST_Request $request ) {
		$playlist_id = absint( $request->get_param( 'id' ) );
		$ids         = $request->get_param( 'release_ids' );
		if ( ! is_array( $ids ) ) {
			return $this->rejected( __( 'ترتیب فهرست پخش را به‌صورت فهرستی از شناسه‌های انتشار ارسال کنید.', 'music-wave-core' ) );
		}

		if ( ! $this->repository->reorder( get_current_user_id(), $playlist_id, $ids ) ) {
			return $this->not_found();
		}

		return new WP_REST_Response( $this->repository->view( $playlist_id, get_current_user_id() ), 200 );
	}

	/**
	 * One-click playback queue for a playlist: ordered tracks with access-aware sources.
	 *
	 * Mirrors PlaybackQueueRoutes but sources its order from the playlist items
	 * filtered through can_view / items_for_viewer. Respects share tokens and
	 * never leaks private playlist existence (same 404 as missing).
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function playback_queue( WP_REST_Request $request ) {
		$playlist_id = absint( $request->get_param( 'id' ) );
		$share       = (string) $request->get_param( 'share' );
		// Fallback to legacy query arg mw-share used in share links.
		if ( '' === $share && isset( $_GET['mw-share'] ) && is_scalar( $_GET['mw-share'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$share = sanitize_text_field( wp_unslash( (string) $_GET['mw-share'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$viewer_id = get_current_user_id();
		$view      = $this->repository->view( $playlist_id, $viewer_id, $share );
		if ( null === $view ) {
			return $this->not_found();
		}

		$items = $this->repository->items_for_viewer( $playlist_id, $viewer_id, $share );
		if ( empty( $items ) ) {
			return new WP_Error( 'mw_playback_unavailable', __( 'این فهرست پخش در حال حاضر صدای قابل پخش ندارد.', 'music-wave-core' ), array( 'status' => 404 ) );
		}

		$ids = array_slice(
			array_values(
				array_unique(
					array_map(
						static function ( array $item ): int {
							return absint( $item['release_id'] );
						},
						$items
					)
				)
			),
			0,
			self::PLAYBACK_MAX_TRACKS
		);

		$tracks       = array();
		$preview_only = true;
		$subject      = AccessSubject::current();
		$first_image  = '';
		foreach ( $ids as $release_id ) {
			$remaining = self::PLAYBACK_MAX_TRACKS - count( $tracks );
			if ( $remaining < 1 ) {
				break;
			}
			$expanded = TrackStreamPresenter::expand_release_tracks(
				$release_id,
				$release_id,
				$subject,
				$this->policy,
				$this->releases,
				$remaining
			);
			foreach ( $expanded as $track ) {
				$tracks[] = $track;
				if ( '' === $first_image && ! empty( $track['image'] ) ) {
					$first_image = (string) $track['image'];
				}
				if ( ! empty( $track['full'] ) ) {
					$preview_only = false;
				}
			}
		}

		if ( empty( $tracks ) ) {
			return new WP_Error( 'mw_playback_unavailable', __( 'این فهرست پخش در حال حاضر صدای قابل پخش ندارد.', 'music-wave-core' ), array( 'status' => 404 ) );
		}

		$payload = array(
			'playlist'    => array(
				'id'         => (int) $view['id'],
				'title'      => (string) $view['title'],
				'visibility' => (string) $view['visibility'],
			),
			// Playlist IDs are table rows, not posts: link the real playlist
			// page instead of get_permalink(), which would resolve to an
			// unrelated post whenever the IDs happen to collide.
			'release'     => array(
				'id'    => $playlist_id,
				'title' => (string) $view['title'],
				'link'  => $this->repository->public_url( $playlist_id, (string) $view['visibility'], $share ),
				'image' => $first_image,
			),
			'previewOnly' => $preview_only,
			'tracks'      => $tracks,
			'upsell'      => $this->playlist_upsell_payload( $ids[0], $subject ),
		);

		$response = new WP_REST_Response( $payload, 200 );
		$response->header( 'Cache-Control', 'no-store, private' );

		return $response;
	}

	/**
	 * Public community listing: only `public` playlists, paginated & searchable.
	 *
	 * @return WP_REST_Response
	 */
	public function public_index( WP_REST_Request $request ): WP_REST_Response {
		$page     = max( 1, absint( $request->get_param( 'page' ) ) );
		$per_page = min( 24, max( 1, absint( $request->get_param( 'per_page' ) ) ) );
		$search   = isset( $request['search'] ) && is_scalar( $request['search'] ) ? sanitize_text_field( (string) $request['search'] ) : '';
		$orderby  = isset( $request['orderby'] ) && is_scalar( $request['orderby'] ) ? sanitize_key( (string) $request['orderby'] ) : 'updated_at';
		$orderby  = in_array( $orderby, array( 'updated_at', 'created_at', 'title' ), true ) ? $orderby : 'updated_at';

		$viewer_id = get_current_user_id();
		$offset    = ( $page - 1 ) * $per_page;
		$total     = $this->repository->count_public( $search );
		$items     = $this->repository->public_playlists( $per_page, $offset, $search, $orderby, $viewer_id );

		// Normalize for public consumption: strip share_token and author_id (privacy FIX-14), expose only safe fields.
		$public = array();
		foreach ( $items as $playlist ) {
			$pid      = (int) $playlist['id'];
			$public[] = array(
				'id'         => $pid,
				'title'      => (string) $playlist['title'],
				'author'     => isset( $playlist['author_name'] ) ? (string) $playlist['author_name'] : '',
				'author_url' => isset( $playlist['author_url'] ) ? (string) $playlist['author_url'] : '',
				'count'      => isset( $playlist['count'] ) ? (int) $playlist['count'] : 0,
				'updated_at' => isset( $playlist['updated_at'] ) ? (int) $playlist['updated_at'] : 0,
				'visibility' => 'public',
				'url'        => $this->repository->public_url( $pid, PlaylistRepository::VISIBILITY_PUBLIC ),
				'covers'     => $this->public_covers( $pid, $viewer_id ),
			);
		}

		$response = new WP_REST_Response(
			array(
				'items'    => $public,
				'total'    => $total,
				'page'     => $page,
				'per_page' => $per_page,
				'pages'    => $per_page > 0 ? (int) ceil( $total / $per_page ) : 0,
			),
			200
		);
		$response->header( 'Cache-Control', 'public, max-age=60, s-maxage=120, stale-while-revalidate=60' );
		$response->header( 'X-Total-Count', (string) $total );

		return $response;
	}

	/**
	 * Cover previews for a public playlist card: the first four readable
	 * items as `{ title, image }` pairs. Only published releases are
	 * visible to non-owners (items_for_viewer), so no unpublished artwork
	 * leaks; `image` is empty when a release has no thumbnail, letting the
	 * client fall back to the title initial.
	 *
	 * @return array<int, array{title: string, image: string}>
	 */
	private function public_covers( int $playlist_id, int $viewer_id ): array {
		$covers = array();
		foreach ( array_slice( $this->repository->items_for_viewer( $playlist_id, $viewer_id ), 0, 4 ) as $item ) {
			$release_id = isset( $item['release_id'] ) ? (int) $item['release_id'] : 0;
			if ( $release_id < 1 ) {
				continue;
			}
			$image    = function_exists( 'get_the_post_thumbnail_url' ) ? get_the_post_thumbnail_url( $release_id, 'thumbnail' ) : '';
			$covers[] = array(
				'title' => (string) get_the_title( $release_id ),
				'image' => is_string( $image ) ? $image : '',
			);
		}

		return $covers;
	}

	private function not_found(): WP_Error {
		return new WP_Error( 'mw_playlist_not_found', __( 'آن فهرست پخش در دسترس نیست.', 'music-wave-core' ), array( 'status' => 404 ) );
	}

	private function rejected( string $message, string $code = 'mw_playlist_rejected' ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => 422 ) );
	}

	/**
	 * @return array<string, string>
	 */
	private function playlist_upsell_payload( int $release_id, AccessSubject $subject ): array {
		$decision = null !== $this->policy ? $this->policy->decide( $release_id, $subject ) : null;

		return TrackStreamPresenter::upsell_payload( $release_id, $decision, $this->releases );
	}
}
