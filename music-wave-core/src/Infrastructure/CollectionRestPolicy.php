<?php
/**
 * REST boundary for collection relationships.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Infrastructure;

use ManaCore\MusicWave\Core\Catalog\ReleasePostType;
use WP_Error;
use WP_REST_Request;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CollectionRestPolicy {
	/** @var WordPressReleaseRepository */
	private $repository;

	/** @var array<int, array<int, array<string, int|string|null>>> */
	private $previous_items = array();

	/** @var WP_Error|null Deferred error for a mutation discarded after insert. */
	private $pending_error = null;

	public function __construct( WordPressReleaseRepository $repository ) {
		$this->repository = $repository;
	}

	public function register(): void {
		add_filter( 'rest_pre_insert_' . ReleasePostType::KEY, array( $this, 'pre_insert' ), 10, 2 );
		add_action( 'rest_after_insert_' . ReleasePostType::KEY, array( $this, 'after_insert' ), 10, 3 );
		add_filter( 'rest_request_after_callbacks', array( $this, 'fail_discarded_mutation' ), 10, 3 );
	}

	/**
	 * Require edit access to both sides of a relation before WordPress writes it.
	 *
	 * @param mixed $prepared_post Prepared post object or error.
	 * @param mixed $request REST request.
	 * @return mixed
	 */
	public function pre_insert( $prepared_post, $request ) {
		if ( is_wp_error( $prepared_post ) || ! $request instanceof WP_REST_Request ) {
			return $prepared_post;
		}

		$meta  = $request->get_param( 'meta' );
		$items = is_array( $meta ) && array_key_exists( 'mw_collection_items', $meta ) ? $meta['mw_collection_items'] : null;
		if ( null === $items ) {
			return $prepared_post;
		}
		if ( ! is_array( $items ) ) {
			return new WP_Error( 'mw_invalid_collection_items', __( 'اقلام مجموعه باید یک آرایه باشند.', 'music-wave-core' ), array( 'status' => 400 ) );
		}

		$post_id = is_object( $prepared_post ) && isset( $prepared_post->ID ) ? absint( $prepared_post->ID ) : absint( $request->get_param( 'id' ) );
		if ( $post_id > 0 ) {
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return new WP_Error( 'mw_forbidden_collection', __( 'شما نمی‌توانید این مجموعه را ویرایش کنید.', 'music-wave-core' ), array( 'status' => 403 ) );
			}
		} elseif ( ! current_user_can( 'edit_mw_releases' ) ) {
			return new WP_Error( 'mw_forbidden_collection', __( 'شما نمی‌توانید یک مجموعه ایجاد کنید.', 'music-wave-core' ), array( 'status' => 403 ) );
		}

		foreach ( $items as $item ) {
			$child_id = is_array( $item ) && isset( $item['release_id'] ) && is_scalar( $item['release_id'] ) ? absint( $item['release_id'] ) : 0;
			if ( $child_id < 1 || ReleasePostType::KEY !== get_post_type( $child_id ) || ! current_user_can( 'edit_post', $child_id ) ) {
				return new WP_Error( 'mw_forbidden_collection_item', __( 'شما نمی‌توانید به یکی از انتشارهای انتخاب‌شده ارجاع دهید.', 'music-wave-core' ), array( 'status' => 403 ) );
			}
		}

		try {
			if ( $post_id > 0 ) {
				$this->repository->validate_collection_items_for_write( $post_id, $items );
				$this->previous_items[ $post_id ] = $this->repository->collection_items( $post_id );
			} else {
				// Creation: the parent does not exist yet, but shape, uniqueness,
				// and child-existence rules must still fail before insert.
				$this->repository->validate_collection_items_for_create( $items );
			}
		} catch ( \InvalidArgumentException $exception ) {
			return new WP_Error( 'mw_invalid_collection_items', $exception->getMessage(), array( 'status' => 400 ) );
		}

		return $prepared_post;
	}

	/**
	 * Keep the private reverse lookup cache in sync with a successful REST save.
	 *
	 * @param mixed $post Saved release post.
	 * @param mixed $request REST request.
	 * @param bool  $creating Whether this request created the release.
	 * @return void
	 */
	public function after_insert( $post, $request, bool $creating ): void {
		unset( $creating );
		if ( ! is_object( $post ) || ! isset( $post->ID ) || ! $request instanceof WP_REST_Request ) {
			return;
		}

		$meta = $request->get_param( 'meta' );
		if ( ! is_array( $meta ) || ! array_key_exists( 'mw_collection_items', $meta ) || ! is_array( $meta['mw_collection_items'] ) ) {
			return;
		}

		$post_id  = absint( $post->ID );
		$previous = isset( $this->previous_items[ $post_id ] ) ? $this->previous_items[ $post_id ] : array();
		unset( $this->previous_items[ $post_id ] );

		try {
			$this->repository->replace_collection_items_with_previous( $post_id, $previous, $meta['mw_collection_items'] );
		} catch ( \InvalidArgumentException $exception ) {
			update_post_meta( $post_id, 'mw_collection_items', $previous );
			// Never report a discarded relation mutation as success: convert the
			// eventual REST response into a structured error while keeping the
			// stored data consistent (PROJECT_PLAN.md §5.2, Stage 1 deliverable 4).
			$this->pending_error = new WP_Error(
				'mw_collection_items_discarded',
				$exception->getMessage(),
				array(
					'status'              => 400,
					'release_id'          => $post_id,
					'relations_discarded' => true,
					'detail'              => __( 'انتشار ذخیره شد اما موارد مجموعه ارسال شده رد شد و روابط قبلی بازیابی شد.', 'music-wave-core' ),
				)
			);
		}
	}

	/**
	 * Replace a would-be success response with the deferred relation error.
	 *
	 * @param mixed $response Result of the REST handler.
	 * @param mixed $handler  Matched route handler (unused).
	 * @param mixed $request  REST request (unused).
	 * @return mixed
	 */
	public function fail_discarded_mutation( $response, $handler = null, $request = null ) {
		unset( $handler, $request );
		if ( null === $this->pending_error ) {
			return $response;
		}

		$error               = $this->pending_error;
		$this->pending_error = null;

		return is_wp_error( $response ) ? $response : $error;
	}
}
