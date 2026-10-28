<?php
/**
 * Persist administrator defaults on newly created releases.
 *
 * Applies `default_access_mode`, `default_preview_duration`, and
 * `default_membership_levels` both when WordPress creates a new `mw_release`
 * post (`! $update`) and when an initial `auto-draft` transitions to an active
 * editorial status without previously stored metadata — while never rewriting
 * existing releases when global settings change later.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Catalog;

use ManaCore\MusicWave\Core\Access\AccessModePolicy;
use ManaCore\MusicWave\Core\Contracts\ReleaseRepository;
use ManaCore\MusicWave\Core\Support\Settings;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ReleaseDefaults {
	/** @var ReleaseRepository */
	private $releases;

	public function __construct( ReleaseRepository $releases ) {
		$this->releases = $releases;
	}

	public function register(): void {
		add_action( 'wp_after_insert_post', array( $this, 'apply' ), 10, 3 );
		add_action( 'transition_post_status', array( $this, 'apply_on_transition' ), 10, 3 );
	}

	/**
	 * Store defaults once on newly inserted `mw_release` posts.
	 */
	public function apply( int $post_id, WP_Post $post, bool $update ): void {
		if ( $update || ReleasePostType::KEY !== $post->post_type ) {
			return;
		}

		$this->seed_missing_defaults( $post_id );
	}

	/**
	 * Ensure Block Editor / REST transitions out of `auto-draft` receive any missing defaults.
	 */
	public function apply_on_transition( string $new_status, string $old_status, WP_Post $post ): void {
		if ( 'auto-draft' !== $old_status || 'auto-draft' === $new_status || 'trash' === $new_status || ReleasePostType::KEY !== $post->post_type ) {
			return;
		}

		$this->seed_missing_defaults( (int) $post->ID );
	}

	private function seed_missing_defaults( int $post_id ): void {
		$default_mode = AccessModePolicy::sanitize_mode( (string) Settings::get( 'default_access_mode' ), 'public' );

		if ( ! metadata_exists( 'post', $post_id, 'mw_access_mode' ) ) {
			$this->releases->update( $post_id, 'mw_access_mode', $default_mode );
		}

		if ( ! metadata_exists( 'post', $post_id, 'mw_preview_duration' ) ) {
			$this->releases->update( $post_id, 'mw_preview_duration', (int) Settings::get( 'default_preview_duration' ) );
		}

		$effective_mode = (string) $this->releases->get( $post_id, 'mw_access_mode' );
		if ( ! metadata_exists( 'post', $post_id, 'mw_membership_levels' ) && AccessModePolicy::uses_membership( $effective_mode ) ) {
			$this->releases->update( $post_id, 'mw_membership_levels', AccessModePolicy::default_membership_levels() );
		}
	}
}
