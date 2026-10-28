<?php
/**
 * Optional author enumeration protection (FIX-22).
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AuthorEnumerationProtection {
	public function register(): void {
		add_action( 'init', array( $this, 'maybe_block' ) );
		add_filter( 'rest_endpoints', array( $this, 'filter_rest_users' ) );
	}

	public function maybe_block(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}
		// Block ?author=1, ?author_name etc for low-priv users.
		if ( is_admin() ) {
			return;
		}
		if ( current_user_can( 'edit_posts' ) ) {
			return;
		}
		// Filter allows override per site.
		$disable = apply_filters( 'music_wave_disable_author_enumeration', true );
		if ( ! $disable ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only block, no state change.
		if ( isset( $_REQUEST['author'] ) || isset( $_REQUEST['author_name'] ) ) {
			wp_safe_redirect( home_url(), 301 );
			exit;
		}
		// Also block author archives via template_redirect.
		add_action( 'template_redirect', array( $this, 'block_author_archive' ) );
	}

	public function block_author_archive(): void {
		if ( ! $this->is_enabled() ) {
			return;
		}
		if ( ! is_author() ) {
			return;
		}
		if ( current_user_can( 'edit_posts' ) ) {
			return;
		}
		$disable = apply_filters( 'music_wave_disable_author_enumeration', true );
		if ( ! $disable ) {
			return;
		}
		wp_safe_redirect( home_url(), 301 );
		exit;
	}

	/**
	 * @param array $endpoints
	 * @return array
	 */
	public function filter_rest_users( $endpoints ) {
		if ( ! $this->is_enabled() ) {
			return $endpoints;
		}
		if ( current_user_can( 'edit_posts' ) ) {
			return $endpoints;
		}
		$disable = apply_filters( 'music_wave_disable_author_enumeration', true );
		if ( ! $disable ) {
			return $endpoints;
		}
		// Remove /wp/v2/users and /wp/v2/users/<id> for low-priv.
		if ( is_array( $endpoints ) ) {
			foreach ( array_keys( $endpoints ) as $route ) {
				if ( 0 === strpos( $route, '/wp/v2/users' ) ) {
					unset( $endpoints[ $route ] );
				}
			}
		}
		return $endpoints;
	}

	private function is_enabled(): bool {
		$settings = Settings::all();
		return isset( $settings['disable_author_enumeration'] ) && 'enabled' === $settings['disable_author_enumeration'];
	}
}
