<?php
/**
 * Public playback queue endpoint for the global release player.
 *
 * Resolves an ordered queue for any release — the release itself or the
 * ordered children of an album, EP, mix, playlist, or podcast show — and
 * pairs every entry with its access-aware playback source: a protected
 * stream when the visitor is allowed, or the public preview URL otherwise.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Playback;

use ManaCore\MusicWave\Core\Access\AccessDecision;
use ManaCore\MusicWave\Core\Access\AccessPolicyEngine;
use ManaCore\MusicWave\Core\Access\AccessSubject;
use ManaCore\MusicWave\Core\Catalog\ReleasePostType;
use ManaCore\MusicWave\Core\Contracts\ReleaseRepository;
use ManaCore\MusicWave\Core\Support\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PlaybackQueueRoutes {
	private const MAX_TRACKS = 50;

	/** @var AccessPolicyEngine */
	private $policy;

	/** @var ReleaseRepository */
	private $repository;

	public function __construct( AccessPolicyEngine $policy, ReleaseRepository $repository ) {
		$this->policy     = $policy;
		$this->repository = $repository;
	}

	/**
	 * Register the playback queue route.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the route; the payload itself never leaks protected URLs,
	 * so anonymous visitors may read it too.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			'music-wave/v1',
			'/releases/(?P<id>\d+)/playback-queue',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'queue' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Build the ordered playback queue for one release.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function queue( WP_REST_Request $request ) {
		$release_id = absint( $request->get_param( 'id' ) );
		if ( $release_id < 1 || ReleasePostType::KEY !== get_post_type( $release_id ) || 'publish' !== get_post_status( $release_id ) ) {
			return new WP_Error(
				'mw_playback_unavailable',
				__( 'این انتشار برای پخش در دسترس نیست.', 'music-wave-core' ),
				array( 'status' => 404 )
			);
		}

		$subject          = AccessSubject::current();
		$release_decision = $this->policy->decide( $release_id, $subject );
		$tracks           = TrackStreamPresenter::expand_release_tracks(
			$release_id,
			$release_id,
			$subject,
			$this->policy,
			$this->repository,
			self::MAX_TRACKS
		);
		if ( empty( $tracks ) ) {
			return new WP_Error(
				'mw_playback_unavailable',
				__( 'این انتشار در حال حاضر صدای قابل پخش ندارد.', 'music-wave-core' ),
				array( 'status' => 404 )
			);
		}

		$preview_only = true;
		foreach ( $tracks as $track ) {
			if ( ! empty( $track['full'] ) ) {
				$preview_only = false;
				break;
			}
		}

		$image = get_the_post_thumbnail_url( $release_id, 'medium' );
		$link  = get_permalink( $release_id );

		return new WP_REST_Response(
			array(
				'release'     => array(
					'id'    => $release_id,
					'title' => get_the_title( $release_id ),
					'link'  => is_string( $link ) ? $link : '',
					'image' => is_string( $image ) ? $image : '',
				),
				'previewOnly' => $preview_only,
				'tracks'      => $tracks,
				'upsell'      => $this->upsell_payload( $release_id, $release_decision ),
			),
			200
		);
	}

	/**
	 * Build the subscription/purchase prompt shown while previews play.
	 *
	 * @return array<string, string>
	 */
	private function upsell_payload( int $release_id, AccessDecision $decision ): array {
		return TrackStreamPresenter::upsell_payload( $release_id, $decision, $this->repository );
	}
}
