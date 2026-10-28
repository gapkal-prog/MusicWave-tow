<?php
/**
 * REST response boundary for MusicWave release body content.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Infrastructure;

use ManaCore\MusicWave\Core\Access\AccessPolicyEngine;
use ManaCore\MusicWave\Core\Access\AccessSubject;
use ManaCore\MusicWave\Core\Catalog\ReleasePostType;
use WP_Post;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ReleaseRestVisibilityPolicy {
	/** @var AccessPolicyEngine */
	private $policy;

	public function __construct( AccessPolicyEngine $policy ) {
		$this->policy = $policy;
	}

	/**
	 * Register REST filters for public release projections.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'rest_prepare_' . ReleasePostType::KEY, array( $this, 'prepare' ), 10, 3 );
	}

	/**
	 * Redact gated body/excerpt fields for callers without content access.
	 *
	 * Editors retain full edit-context responses through WordPress object
	 * capabilities. Customers/members with current entitlement retain the body.
	 * Denied visitors keep discoverable release fields but receive no body copy.
	 *
	 * @param mixed $response REST response object.
	 * @param mixed $post     Prepared release post.
	 * @param mixed $request  REST request (unused; accepted for hook parity).
	 * @return mixed
	 */
	public function prepare( $response, $post, $request ) {
		unset( $request );
		if ( ! $post instanceof WP_Post || ReleasePostType::KEY !== $post->post_type ) {
			return $response;
		}
		if ( current_user_can( 'edit_post', (int) $post->ID ) ) {
			return $response;
		}
		if ( $this->policy->decide( (int) $post->ID, AccessSubject::current() )->is_allowed() ) {
			return $response;
		}
		if ( ! is_object( $response ) || ! method_exists( $response, 'get_data' ) || ! method_exists( $response, 'set_data' ) ) {
			return $response;
		}

		$data = $response->get_data();
		if ( ! is_array( $data ) ) {
			return $response;
		}

		foreach ( array( 'content', 'excerpt' ) as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$data[ $field ] = $this->redacted_field( $data[ $field ] );
			}
		}
		$data['music_wave_access'] = array(
			'allowed' => false,
			'reason'  => 'content_restricted',
		);

		$response->set_data( $data );

		return $response;
	}

	/**
	 * Preserve the REST field shape while removing rendered/raw body text.
	 *
	 * @param mixed $field Existing REST field value.
	 * @return array<string, mixed>
	 */
	private function redacted_field( $field ): array {
		unset( $field );

		return array(
			'rendered'  => '',
			'protected' => true,
		);
	}
}
