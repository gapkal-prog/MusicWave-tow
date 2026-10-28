<?php
/**
 * REST route for view counting beacon (FIX-11).
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Analytics;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ViewRoutes {
	/** @var ViewCounter */
	private $counter;

	public function __construct( ?ViewCounter $counter = null ) {
		$this->counter = null !== $counter ? $counter : new ViewCounter();
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			'music-wave/v1',
			'/releases/(?P<id>\d+)/view',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'record' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * @return WP_REST_Response|WP_Error
	 */
	public function record( WP_REST_Request $request ) {
		$release_id = absint( $request->get_param( 'id' ) );
		$raw_ua     = isset( $_SERVER['HTTP_USER_AGENT'] ) && is_string( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$ua         = function_exists( 'sanitize_text_field' ) && function_exists( 'wp_unslash' ) ? sanitize_text_field( wp_unslash( $raw_ua ) ) : $raw_ua;

		$check = $this->counter->should_count( $release_id, $ua );
		if ( true !== $check ) {
			if ( 'rate_limited' === $check ) {
				return new WP_Error( 'mw_view_rate_limited', __( 'تعداد بازدیدها بیش از حد است.', 'music-wave-core' ), array( 'status' => 429 ) );
			}
			// For dedupe, bot, editor, not_published etc, return 200 with counted false (not error) to avoid beacon retry.
			return new WP_REST_Response(
				array(
					'counted' => false,
					'reason'  => $check,
				),
				200
			);
		}

		$ok = $this->counter->record( $release_id );
		return new WP_REST_Response( array( 'counted' => $ok ), $ok ? 200 : 500 );
	}
}
