<?php
/**
 * Authenticated listening REST surface: consent, progress, continue, history management, queue, queue/action.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Listening;

use ManaCore\MusicWave\Core\Blocks\BlockSupport;
use ManaCore\MusicWave\Core\Blocks\ListeningBlocks;
use ManaCore\MusicWave\Core\Blocks\ReleaseCard;
use ManaCore\MusicWave\Core\Support\Concerns\AuthenticatedRestPermissions;
use ManaCore\MusicWave\Core\Support\RouteRateLimiter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ListeningRoutes {
	use AuthenticatedRestPermissions;

	/** @var ListeningRepository */
	private $repository;

	/** @var QueueFormHandler */
	private $queue_forms;

	public function __construct( ListeningRepository $repository, ?RouteRateLimiter $rate_limiter = null, ?QueueFormHandler $queue_forms = null ) {
		$this->repository   = $repository;
		$this->rate_limiter = $rate_limiter;
		$this->queue_forms  = null !== $queue_forms ? $queue_forms : new QueueFormHandler( $repository );
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			'music-wave/v1',
			'/listening/consent',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'consent' ),
				'permission_callback' => array( $this, 'may_write' ),
			)
		);
		register_rest_route(
			'music-wave/v1',
			'/listening/progress',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'progress' ),
				'permission_callback' => function () {
					return $this->may_write( 'listening-progress' );
				},
			)
		);
		register_rest_route(
			'music-wave/v1',
			'/listening/continue',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'continue_listening' ),
				'permission_callback' => array( $this, 'authenticated' ),
			)
		);
		register_rest_route(
			'music-wave/v1',
			'/listening/items/(?P<release_id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'remove_item' ),
				'permission_callback' => array( $this, 'may_write' ),
			)
		);
		register_rest_route(
			'music-wave/v1',
			'/listening/history',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'clear_history' ),
				'permission_callback' => array( $this, 'may_write' ),
			)
		);
		register_rest_route(
			'music-wave/v1',
			'/listening/queue',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_queue' ),
					'permission_callback' => array( $this, 'authenticated' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'save_queue' ),
					'permission_callback' => array( $this, 'may_write' ),
				),
			)
		);
		register_rest_route(
			'music-wave/v1',
			'/listening/queue/action',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'queue_action' ),
				'permission_callback' => array( $this, 'may_write' ),
			)
		);
	}

	protected function unauthenticated_message(): string {
		return __( 'برای استفاده از ویژگی‌های گوش‌دادن وارد سیستم شوید.', 'music-wave-core' );
	}

	protected function default_rate_bucket(): string {
		return 'listening';
	}

	/** @return WP_REST_Response|WP_Error */
	public function consent( WP_REST_Request $request ) {
		$raw = $request->get_param( 'consent' );
		if ( null === $raw ) {
			return new WP_Error( 'mw_consent_flag_required', __( 'پرچم رضایت الزامی است.', 'music-wave-core' ), array( 'status' => 400 ) );
		}
		$consent = function_exists( 'rest_sanitize_boolean' ) ? rest_sanitize_boolean( $raw ) : filter_var( $raw, FILTER_VALIDATE_BOOLEAN );
		if ( (bool) $consent && ! \ManaCore\MusicWave\Core\Support\Settings::listening_enabled() ) {
			return new WP_Error( 'mw_listening_disabled', __( 'تاریخچه گوش‌دادن توسط مدیر سایت غیرفعال شده است.', 'music-wave-core' ), array( 'status' => 403 ) );
		}
		$this->repository->set_consent( get_current_user_id(), (bool) $consent );

		return new WP_REST_Response( array( 'consent' => $this->repository->has_consent( get_current_user_id() ) ), 200 );
	}

	/** @return WP_REST_Response|WP_Error */
	public function progress( WP_REST_Request $request ) {
		if ( ! \ManaCore\MusicWave\Core\Support\Settings::listening_enabled() ) {
			return new WP_Error( 'mw_listening_disabled', __( 'تاریخچه گوش‌دادن غیرفعال است.', 'music-wave-core' ), array( 'status' => 403 ) );
		}
		$user_id    = get_current_user_id();
		$release_id = absint( $request->get_param( 'release_id' ) );
		if ( $release_id < 1 ) {
			$raw_url = $request->get_param( 'release_url' );
			if ( is_string( $raw_url ) && '' !== trim( $raw_url ) ) {
				$clean_url = esc_url_raw( trim( $raw_url ) );
				if ( '' !== $clean_url && function_exists( 'url_to_postid' ) ) {
					$release_id = absint( url_to_postid( $clean_url ) );
				}
				if ( $release_id < 1 && '' !== $clean_url ) {
					$query = wp_parse_url( $clean_url, PHP_URL_QUERY );
					if ( is_string( $query ) && '' !== $query ) {
						$params = array();
						parse_str( $query, $params );
						$release_id = isset( $params['p'] ) ? absint( $params['p'] ) : 0;
					}
				}
			}
		}
		$event    = 'played' === sanitize_key( (string) $request->get_param( 'event' ) ) ? ListeningRepository::EVENT_PLAYED : ListeningRepository::EVENT_PROGRESS;
		$position = absint( $request->get_param( 'position' ) );

		if ( ! $this->repository->has_consent( $user_id ) ) {
			return new WP_Error( 'mw_listening_consent_required', __( 'برای ذخیره پیشرفت پخش، سابقهٔ گوش‌دادن را در حساب خود فعال کنید.', 'music-wave-core' ), array( 'status' => 403 ) );
		}
		if ( ! $this->repository->record( $user_id, $release_id, $event, $position ) ) {
			return new WP_Error( 'mw_listening_unavailable', __( 'پیشرفت پخش ذخیره نشد.', 'music-wave-core' ), array( 'status' => 400 ) );
		}

		return new WP_REST_Response(
			array(
				'saved'      => true,
				'release_id' => $release_id,
				'event'      => $event,
				'position'   => $position,
			),
			200
		);
	}

	/** @return WP_REST_Response */
	public function continue_listening( WP_REST_Request $request ): WP_REST_Response {
		if ( ! \ManaCore\MusicWave\Core\Support\Settings::listening_enabled() ) {
			return new WP_REST_Response(
				array(
					'consent'    => false,
					'enabled'    => false,
					'items'      => array(),
					'cards_html' => '',
				),
				200
			);
		}
		$user_id = get_current_user_id();
		$limit   = absint( $request->get_param( 'per_page' ) );
		$source  = 'played' === sanitize_key( (string) $request->get_param( 'source' ) ) ? 'played' : 'continue';
		$event   = 'played' === $source ? ListeningRepository::EVENT_PLAYED : ListeningRepository::EVENT_PROGRESS;
		$shape   = sanitize_key( (string) $request->get_param( 'shape' ) );
		$shape   = in_array( $shape, array( 'square', 'circle', 'landscape', 'portrait' ), true ) ? $shape : 'square';

		$card_options = array(
			'shape'        => $shape,
			'show_artwork' => $this->bool_param( $request, 'show_artwork', true ),
			'show_artist'  => $this->bool_param( $request, 'show_artist', true ),
			'show_when'    => $this->bool_param( $request, 'show_when', true ),
			'show_preview' => $this->bool_param( $request, 'show_preview', true ),
		);

		$renderer   = new ListeningBlocks( $this->repository );
		$items      = array();
		$cards_html = array();

		foreach ( $this->repository->recent( $user_id, $event, $limit > 0 ? $limit : 12 ) as $row ) {
			$release_id = (int) $row['release_id'];
			$position   = (int) $row['position'];
			$updated_at = (int) $row['updated_at'];
			$data       = ReleaseCard::presentation_data( $release_id );
			if ( array() === $data ) {
				continue;
			}
			$image   = get_the_post_thumbnail_url( $release_id, 'medium_large' );
			$card    = $renderer->render_item_card( $release_id, $updated_at, $card_options, 'played' === $source ? 0 : $position, true );
			$items[] = array(
				'release_id'    => $release_id,
				'position'      => $position,
				'updated_at'    => $updated_at,
				'relative_time' => $updated_at > 0 ? BlockSupport::relative_time_label( $updated_at ) : '',
				'title'         => '' !== $data['title'] ? $data['title'] : __( 'انتشار بدون عنوان', 'music-wave-core' ),
				'artist'        => $data['artist'],
				'initial'       => $data['initial'],
				'image'         => is_string( $image ) ? $image : '',
				'url'           => $data['link'],
			);
			if ( '' !== $card ) {
				$cards_html[] = $card;
			}
		}

		return new WP_REST_Response(
			array(
				'consent'    => $this->repository->has_consent( $user_id ),
				'source'     => $source,
				'items'      => $items,
				'cards_html' => implode( '', $cards_html ),
			),
			200
		);
	}

	/** @return WP_REST_Response|WP_Error */
	public function remove_item( WP_REST_Request $request ) {
		$user_id    = get_current_user_id();
		$release_id = absint( $request->get_param( 'release_id' ) );
		if ( $release_id < 1 ) {
			return new WP_Error( 'mw_listening_invalid_release', __( 'انتشار نامعتبر است.', 'music-wave-core' ), array( 'status' => 400 ) );
		}
		$source = sanitize_key( (string) $request->get_param( 'source' ) );
		$event  = 'played' === $source ? ListeningRepository::EVENT_PLAYED : ( 'continue' === $source ? ListeningRepository::EVENT_PROGRESS : '' );
		$this->repository->remove_item( $user_id, $release_id, $event );

		return new WP_REST_Response(
			array(
				'removed'    => true,
				'release_id' => $release_id,
			),
			200
		);
	}

	/** @return WP_REST_Response */
	public function clear_history( WP_REST_Request $request ): WP_REST_Response {
		$user_id = get_current_user_id();
		$source  = sanitize_key( (string) $request->get_param( 'source' ) );
		$event   = 'played' === $source ? ListeningRepository::EVENT_PLAYED : ( 'continue' === $source ? ListeningRepository::EVENT_PROGRESS : '' );
		$this->repository->clear_history( $user_id, $event );

		return new WP_REST_Response( array( 'cleared' => true ), 200 );
	}

	/** @return WP_REST_Response */
	public function get_queue(): WP_REST_Response {
		return new WP_REST_Response( $this->repository->queue( get_current_user_id() ), 200 );
	}

	/** @return WP_REST_Response|WP_Error */
	public function save_queue( WP_REST_Request $request ) {
		$queue = $request->get_param( 'queue' );
		if ( ! is_array( $queue ) ) {
			return new WP_Error( 'mw_queue_invalid', __( 'محموله صف پخش باید یک شی باشد.', 'music-wave-core' ), array( 'status' => 400 ) );
		}
		if ( ! $this->repository->save_queue( get_current_user_id(), $queue ) ) {
			return new WP_Error( 'mw_queue_unavailable', __( 'صف پخش ذخیره نشد.', 'music-wave-core' ), array( 'status' => 400 ) );
		}

		return new WP_REST_Response( $this->repository->queue( get_current_user_id() ), 200 );
	}

	/**
	 * Execute an atomic queue control action (shuffle, repeat, reorder, remove, clear, add).
	 * Delegates directly to QueueFormHandler::run() for 100% DRY parity with HTML forms.
	 */
	public function queue_action( WP_REST_Request $request ): WP_REST_Response {
		$user_id    = get_current_user_id();
		$raw_op     = $request->get_param( 'operation' ) ?? $request->get_param( 'mw_operation' );
		$raw_rel    = $request->get_param( 'release_id' ) ?? $request->get_param( 'mw_release_id' );
		$raw_val    = $request->get_param( 'value' ) ?? $request->get_param( 'mw_value' );
		$operation  = is_scalar( $raw_op ) ? sanitize_key( (string) $raw_op ) : '';
		$release_id = absint( $raw_rel );
		$value      = is_scalar( $raw_val ) ? sanitize_key( (string) $raw_val ) : '';

		$notice  = $this->queue_forms->run( $operation, $user_id, $release_id, $value );
		$payload = $this->queue_forms->response_payload( $notice, $user_id );

		return new WP_REST_Response( $payload, ! empty( $payload['is_error'] ) ? 400 : 200 );
	}

	private function bool_param( WP_REST_Request $request, string $key, bool $fallback ): bool {
		$raw = $request->get_param( $key );
		if ( null === $raw ) {
			return $fallback;
		}
		return function_exists( 'rest_sanitize_boolean' ) ? (bool) rest_sanitize_boolean( $raw ) : (bool) filter_var( $raw, FILTER_VALIDATE_BOOLEAN );
	}
}
