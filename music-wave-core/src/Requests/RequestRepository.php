<?php
/**
 * Persistence for custom-song requests and collaboration proposals.
 *
 * Every request is one `mw_request` post: the subject is the title, the
 * lifecycle status is the post status, and the structured fields live in a
 * fixed set of `mw_request_*` post meta keys. Replies and internal notes are
 * an append-only log stored as meta, so no database table or schema bump is
 * required and the regular WordPress export/erase tooling keeps working.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Requests;

use ManaCore\MusicWave\Core\Discovery\ScriptAwareSearchQuery;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RequestRepository {
	public const META_NAME      = 'mw_request_name';
	public const META_EMAIL     = 'mw_request_email';
	public const META_PHONE     = 'mw_request_phone';
	public const META_ROLE      = 'mw_request_role';
	public const META_TYPE      = 'mw_request_type';
	public const META_MESSAGE   = 'mw_request_message';
	public const META_BUDGET    = 'mw_request_budget';
	public const META_DEADLINE  = 'mw_request_deadline';
	public const META_LINKS     = 'mw_request_links';
	public const META_PRIORITY  = 'mw_request_priority';
	public const META_SOURCE    = 'mw_request_source';
	public const META_USER      = 'mw_request_user_id';
	public const META_REFERENCE = 'mw_request_reference';
	public const META_LOG       = 'mw_request_log';
	public const META_TOKEN     = 'mw_request_token';
	public const META_CONSENT   = 'mw_request_consent_at';

	public const LOG_REPLY = 'reply';
	public const LOG_NOTE  = 'note';
	public const LOG_EVENT = 'event';

	public const MAX_LOG_ENTRIES = 200;

	/**
	 * Store a validated public submission.
	 *
	 * @param array<string, mixed> $data Clean payload from RequestSubmission::validate().
	 * @return int New request ID, or 0 on failure.
	 */
	public function create( array $data, int $user_id = 0, string $source = 'form' ): int {
		if ( ! function_exists( 'wp_insert_post' ) ) {
			return 0;
		}

		$subject = isset( $data['subject'] ) ? (string) $data['subject'] : '';
		$post_id = wp_insert_post(
			array(
				'post_type'      => RequestPostType::KEY,
				'post_status'    => RequestPostType::STATUS_NEW,
				'post_title'     => '' !== $subject ? $subject : __( 'درخواست بدون عنوان', 'music-wave-core' ),
				'post_author'    => $user_id,
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			),
			true
		);
		if ( ! is_int( $post_id ) || $post_id < 1 ) {
			return 0;
		}

		$this->write_fields( $post_id, $data );
		update_post_meta( $post_id, self::META_SOURCE, sanitize_key( $source ) );
		update_post_meta( $post_id, self::META_USER, $user_id );
		update_post_meta( $post_id, self::META_PRIORITY, 'normal' );
		update_post_meta( $post_id, self::META_REFERENCE, self::reference( $post_id ) );
		update_post_meta( $post_id, self::META_TOKEN, $this->generate_token() );
		update_post_meta( $post_id, self::META_CONSENT, time() );
		update_post_meta( $post_id, self::META_LOG, array() );

		return $post_id;
	}

	/**
	 * Manager-side edit of the structured fields.
	 *
	 * @param array<string, mixed> $data Clean payload.
	 */
	public function update_fields( int $request_id, array $data ): bool {
		if ( null === $this->find( $request_id ) ) {
			return false;
		}
		$this->write_fields( $request_id, $data );
		if ( isset( $data['subject'] ) && function_exists( 'wp_update_post' ) ) {
			wp_update_post(
				array(
					'ID'         => $request_id,
					'post_title' => (string) $data['subject'],
				)
			);
		}

		return true;
	}

	/**
	 * Load one request as a normalized array.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find( int $request_id ): ?array {
		if ( $request_id < 1 || RequestPostType::KEY !== get_post_type( $request_id ) ) {
			return null;
		}
		$status = get_post_status( $request_id );
		if ( ! is_string( $status ) || ! RequestPostType::is_status( $status ) ) {
			return null;
		}

		$links = get_post_meta( $request_id, self::META_LINKS, true );
		$log   = get_post_meta( $request_id, self::META_LOG, true );

		return array(
			'id'        => $request_id,
			'reference' => (string) get_post_meta( $request_id, self::META_REFERENCE, true ),
			'status'    => $status,
			'subject'   => get_the_title( $request_id ),
			'name'      => (string) get_post_meta( $request_id, self::META_NAME, true ),
			'email'     => (string) get_post_meta( $request_id, self::META_EMAIL, true ),
			'phone'     => (string) get_post_meta( $request_id, self::META_PHONE, true ),
			'role'      => (string) get_post_meta( $request_id, self::META_ROLE, true ),
			'type'      => (string) get_post_meta( $request_id, self::META_TYPE, true ),
			'message'   => (string) get_post_meta( $request_id, self::META_MESSAGE, true ),
			'budget'    => (string) get_post_meta( $request_id, self::META_BUDGET, true ),
			'deadline'  => (string) get_post_meta( $request_id, self::META_DEADLINE, true ),
			'links'     => is_array( $links ) ? array_values( array_map( 'strval', $links ) ) : array(),
			'priority'  => (string) get_post_meta( $request_id, self::META_PRIORITY, true ),
			'source'    => (string) get_post_meta( $request_id, self::META_SOURCE, true ),
			'user_id'   => (int) get_post_meta( $request_id, self::META_USER, true ),
			'token'     => (string) get_post_meta( $request_id, self::META_TOKEN, true ),
			'log'       => is_array( $log ) ? array_values( $log ) : array(),
		);
	}

	/**
	 * Move a request through its lifecycle.
	 */
	public function set_status( int $request_id, string $status, int $actor_id = 0 ): bool {
		if ( ! RequestPostType::is_status( $status ) || ! function_exists( 'wp_update_post' ) ) {
			return false;
		}
		$request = $this->find( $request_id );
		if ( null === $request ) {
			return false;
		}
		if ( $request['status'] === $status ) {
			return true;
		}

		$updated = wp_update_post(
			array(
				'ID'          => $request_id,
				'post_status' => $status,
			),
			true
		);
		if ( ! is_int( $updated ) || $updated < 1 ) {
			return false;
		}

		$labels = RequestPostType::status_labels();
		$this->append_log(
			$request_id,
			self::LOG_EVENT,
			sprintf(
				/* translators: 1: previous status label, 2: new status label. */
				__( 'وضعیت از «%1$s» به «%2$s» تغییر کرد.', 'music-wave-core' ),
				isset( $labels[ $request['status'] ] ) ? $labels[ $request['status'] ] : $request['status'],
				isset( $labels[ $status ] ) ? $labels[ $status ] : $status
			),
			$actor_id
		);

		/**
		 * Fires after a request changes status.
		 *
		 * @param int    $request_id Request ID.
		 * @param string $status     New status.
		 * @param string $previous   Previous status.
		 */
		do_action( 'music_wave_request_status_changed', $request_id, $status, $request['status'] );

		return true;
	}

	/**
	 * Flag or unflag a request as urgent.
	 */
	public function set_priority( int $request_id, string $priority ): bool {
		if ( ! isset( RequestPostType::priority_labels()[ $priority ] ) || null === $this->find( $request_id ) ) {
			return false;
		}
		update_post_meta( $request_id, self::META_PRIORITY, $priority );

		return true;
	}

	/**
	 * Append a reply, note or event to the request log.
	 *
	 * @return array<string, mixed>|null The stored entry.
	 */
	public function append_log( int $request_id, string $kind, string $body, int $actor_id = 0, array $extra = array() ): ?array {
		if ( ! in_array( $kind, array( self::LOG_REPLY, self::LOG_NOTE, self::LOG_EVENT ), true ) || null === $this->find( $request_id ) ) {
			return null;
		}
		$body = trim( $body );
		if ( '' === $body ) {
			return null;
		}

		$log   = get_post_meta( $request_id, self::META_LOG, true );
		$log   = is_array( $log ) ? array_values( $log ) : array();
		$entry = array_merge(
			$extra,
			array(
				'kind'  => $kind,
				'body'  => $body,
				'actor' => $actor_id,
				'time'  => time(),
			)
		);
		$log[] = $entry;
		if ( count( $log ) > self::MAX_LOG_ENTRIES ) {
			$log = array_slice( $log, -self::MAX_LOG_ENTRIES );
		}
		update_post_meta( $request_id, self::META_LOG, $log );

		return $entry;
	}

	/**
	 * Permanently delete a request and its data.
	 */
	public function delete( int $request_id ): bool {
		if ( null === $this->find( $request_id ) || ! function_exists( 'wp_delete_post' ) ) {
			return false;
		}

		$deleted = wp_delete_post( $request_id, true );

		return false !== $deleted && null !== $deleted;
	}

	/**
	 * Paginated, filterable list for the admin screen.
	 *
	 * @param array<string, mixed> $filters status|type|role|priority|search|user_id.
	 * @return array{items: array<int, array<string, mixed>>, total: int, pages: int}
	 */
	public function query( array $filters, int $page = 1, int $per_page = 20 ): array {
		if ( ! class_exists( '\WP_Query' ) ) {
			return array(
				'items' => array(),
				'total' => 0,
				'pages' => 0,
			);
		}

		$status = isset( $filters['status'] ) && is_string( $filters['status'] ) && RequestPostType::is_status( $filters['status'] ) ? array( $filters['status'] ) : RequestPostType::statuses();
		$args   = array(
			'post_type'              => RequestPostType::KEY,
			'post_status'            => $status,
			'posts_per_page'         => max( 1, min( 100, $per_page ) ),
			'paged'                  => max( 1, $page ),
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'fields'                 => 'ids',
			'no_found_rows'          => false,
			'update_post_term_cache' => false,
			'suppress_filters'       => false,
		);
		if ( isset( $filters['search'] ) && is_string( $filters['search'] ) && '' !== trim( $filters['search'] ) ) {
			$args['s'] = trim( $filters['search'] );
			// Requests have no taxonomies and keep their data in meta, so the
			// script-aware search is scoped to the requester fields: subject
			// (post_title) plus name, email, phone, reference and message.
			$args[ ScriptAwareSearchQuery::QUERY_VAR ]      = true;
			$args[ ScriptAwareSearchQuery::TAXONOMIES_VAR ] = array();
			$args[ ScriptAwareSearchQuery::META_KEYS_VAR ]  = array( self::META_NAME, self::META_EMAIL, self::META_PHONE, self::META_REFERENCE, self::META_MESSAGE );
		}

		$meta_query = array();
		foreach ( array(
			'type'     => self::META_TYPE,
			'role'     => self::META_ROLE,
			'priority' => self::META_PRIORITY,
		) as $filter => $meta_key ) {
			if ( isset( $filters[ $filter ] ) && is_string( $filters[ $filter ] ) && '' !== $filters[ $filter ] ) {
				$meta_query[] = array(
					'key'   => $meta_key,
					'value' => sanitize_key( $filters[ $filter ] ),
				);
			}
		}
		if ( isset( $filters['user_id'] ) && (int) $filters['user_id'] > 0 ) {
			$meta_query[] = array(
				'key'   => self::META_USER,
				'value' => (int) $filters['user_id'],
				'type'  => 'NUMERIC',
			);
		}
		if ( array() !== $meta_query ) {
			$args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded admin listing on indexed keys.
		}

		$query = new \WP_Query( $args );
		$items = array();
		foreach ( is_array( $query->posts ) ? $query->posts : array() as $post_id ) {
			$request = $this->find( (int) $post_id );
			if ( null !== $request ) {
				$items[] = $request;
			}
		}

		return array(
			'items' => $items,
			'total' => (int) $query->found_posts,
			'pages' => (int) $query->max_num_pages,
		);
	}

	/**
	 * Per-status counts for the filter tabs and the menu badge.
	 *
	 * @return array<string, int>
	 */
	public function counts(): array {
		$counts = array_fill_keys( RequestPostType::statuses(), 0 );
		if ( ! function_exists( 'wp_count_posts' ) ) {
			return $counts;
		}
		$raw = wp_count_posts( RequestPostType::KEY );
		foreach ( $counts as $status => $unused ) {
			if ( is_object( $raw ) && isset( $raw->{$status} ) ) {
				$counts[ $status ] = (int) $raw->{$status};
			}
		}

		return $counts;
	}

	/**
	 * Requests submitted with one email address (privacy tooling).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function find_by_email( string $email, int $limit = 100 ): array {
		if ( ! function_exists( 'get_posts' ) ) {
			return array();
		}
		$email = strtolower( trim( $email ) );
		if ( '' === $email ) {
			return array();
		}
		$ids = get_posts(
			array(
				'post_type'      => RequestPostType::KEY,
				'post_status'    => RequestPostType::statuses(),
				'posts_per_page' => max( 1, min( 500, $limit ) ),
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => self::META_EMAIL, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- privacy export lookup.
				'meta_value'     => $email, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		$items = array();
		foreach ( is_array( $ids ) ? $ids : array() as $post_id ) {
			$request = $this->find( (int) $post_id );
			if ( null !== $request && $request['email'] === $email ) {
				$items[] = $request;
			}
		}

		return $items;
	}

	/**
	 * Whether a request belongs to the signed-in user (by user_id or guest email match).
	 *
	 * @param array<string, mixed> $request Normalized request.
	 */
	public function is_owned_by_user( array $request, int $user_id, string $user_email = '' ): bool {
		if ( $user_id < 1 ) {
			return false;
		}
		$owner_id = isset( $request['user_id'] ) ? (int) $request['user_id'] : 0;
		if ( $owner_id > 0 ) {
			return $owner_id === $user_id;
		}
		$email = strtolower( trim( $user_email ) );
		return '' !== $email && isset( $request['email'] ) && strtolower( trim( (string) $request['email'] ) ) === $email;
	}

	/**
	 * Paginated requests belonging to a signed-in user (including prior guest submissions under same email).
	 *
	 * @return array{items: array<int, array<string, mixed>>, total: int, pages: int}
	 */
	public function query_for_user( int $user_id, string $user_email = '', int $page = 1, int $per_page = 10 ): array {
		if ( $user_id < 1 || ! class_exists( '\WP_Query' ) ) {
			return array(
				'items' => array(),
				'total' => 0,
				'pages' => 0,
			);
		}

		$email = strtolower( trim( $user_email ) );
		if ( '' === $email ) {
			return $this->query( array( 'user_id' => $user_id ), $page, $per_page );
		}

		$query = new \WP_Query(
			array(
				'post_type'              => RequestPostType::KEY,
				'post_status'            => RequestPostType::statuses(),
				'posts_per_page'         => max( 1, min( 100, $per_page ) ),
				'paged'                  => max( 1, $page ),
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'fields'                 => 'ids',
				'no_found_rows'          => false,
				'update_post_term_cache' => false,
				'suppress_filters'       => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'OR',
					array(
						'key'   => self::META_USER,
						'value' => $user_id,
						'type'  => 'NUMERIC',
					),
					array(
						'relation' => 'AND',
						array(
							'key'   => self::META_USER,
							'value' => 0,
							'type'  => 'NUMERIC',
						),
						array(
							'key'   => self::META_EMAIL,
							'value' => $email,
						),
					),
				),
			)
		);

		$items = array();
		foreach ( is_array( $query->posts ) ? $query->posts : array() as $post_id ) {
			$request = $this->find( (int) $post_id );
			if ( null !== $request && $this->is_owned_by_user( $request, $user_id, $email ) ) {
				$items[] = $request;
			}
		}

		return array(
			'items' => $items,
			'total' => (int) $query->found_posts,
			'pages' => (int) $query->max_num_pages,
		);
	}

	/**
	 * Normalized requester-visible conversation messages in chronological order (DRY).
	 *
	 * @param array<string, mixed> $request Normalized request.
	 * @return array<int, array{by: string, body: string, time: int, time_label: string}>
	 */
	public static function conversation_entries( array $request ): array {
		$log     = isset( $request['log'] ) && is_array( $request['log'] ) ? $request['log'] : array();
		$entries = array();
		foreach ( $log as $entry ) {
			if ( ! is_array( $entry ) || self::LOG_REPLY !== ( $entry['kind'] ?? '' ) ) {
				continue;
			}
			$body = isset( $entry['body'] ) ? trim( (string) $entry['body'] ) : '';
			if ( '' === $body ) {
				continue;
			}
			$by         = isset( $entry['by'] ) && 'user' === $entry['by'] ? 'user' : 'admin';
			$time       = isset( $entry['time'] ) ? (int) $entry['time'] : 0;
			$time_label = '';
			if ( $time > 0 ) {
				$time_label = function_exists( 'wp_date' )
					? (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time )
					: gmdate( 'Y/m/d H:i', $time );
			}
			$entries[] = array(
				'by'         => $by,
				'body'       => $body,
				'time'       => $time,
				'time_label' => $time_label,
			);
		}
		return $entries;
	}

	/**
	 * Whether a secret token belongs to a request (status page links).
	 */
	public function verify_token( int $request_id, string $token ): bool {
		$request = $this->find( $request_id );
		if ( null === $request || '' === $request['token'] || '' === $token ) {
			return false;
		}

		return hash_equals( $request['token'], $token );
	}

	/**
	 * Human reference such as MW-2026-000123.
	 */
	public static function reference( int $request_id ): string {
		return sprintf( 'MW-%s-%06d', gmdate( 'Y' ), $request_id );
	}

	/**
	 * Persist the structured fields.
	 *
	 * @param array<string, mixed> $data Clean payload.
	 */
	private function write_fields( int $post_id, array $data ): void {
		$map = array(
			'name'     => self::META_NAME,
			'email'    => self::META_EMAIL,
			'phone'    => self::META_PHONE,
			'role'     => self::META_ROLE,
			'type'     => self::META_TYPE,
			'message'  => self::META_MESSAGE,
			'budget'   => self::META_BUDGET,
			'deadline' => self::META_DEADLINE,
		);
		foreach ( $map as $field => $meta_key ) {
			if ( array_key_exists( $field, $data ) ) {
				update_post_meta( $post_id, $meta_key, is_scalar( $data[ $field ] ) ? (string) $data[ $field ] : '' );
			}
		}
		if ( array_key_exists( 'links', $data ) ) {
			$links = is_array( $data['links'] ) ? array_values( array_map( 'strval', $data['links'] ) ) : array();
			update_post_meta( $post_id, self::META_LINKS, $links );
		}
	}

	/**
	 * Unguessable token for requester-facing links.
	 */
	private function generate_token(): string {
		if ( function_exists( 'wp_generate_password' ) ) {
			return (string) wp_generate_password( 32, false, false );
		}

		return bin2hex( random_bytes( 16 ) );
	}
}
