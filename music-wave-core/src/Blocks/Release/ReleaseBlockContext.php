<?php
/**
 * Shared dependencies & domain helpers for decomposed Release block renderers.
 *
 * Centralizes release resolution, access policy, playability, duration
 * formatting, preview button markup, and linked taxonomy term formatting so
 * each specialized renderer class stays focused on one UI responsibility
 * (SOLID SRP + DIP + DRY).
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Blocks\Release;

use ManaCore\MusicWave\Core\Access\AccessDecision;
use ManaCore\MusicWave\Core\Access\AccessPolicyEngine;
use ManaCore\MusicWave\Core\Access\AccessSubject;
use ManaCore\MusicWave\Core\Blocks\BlockSupport;
use ManaCore\MusicWave\Core\Blocks\Concerns\ReleasePlayability;
use ManaCore\MusicWave\Core\Blocks\Concerns\ReleaseResolver;
use ManaCore\MusicWave\Core\Catalog\ReleasePostType;
use ManaCore\MusicWave\Core\Catalog\ReleaseTermIndex;
use ManaCore\MusicWave\Core\Contracts\ReleaseRepository;
use ManaCore\MusicWave\Core\Playback\TrackStreamPresenter;
use ManaCore\MusicWave\Core\Support\Settings;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ReleaseBlockContext {
	/** @var AccessPolicyEngine|null */
	private $policy;

	/** @var ReleaseRepository */
	private $repository;

	/** @var ReleaseTermIndex */
	private $term_index;

	/** @var ReleasePlayability|null */
	private $playability;

	/** @var array<int, string> */
	private $last_rendered_access_state = array();

	/** @var array<int, bool> */
	private $full_specs_rendered = array();

	public function __construct( ?AccessPolicyEngine $policy, ReleaseRepository $repository, ?ReleaseTermIndex $term_index = null ) {
		$this->policy     = $policy;
		$this->repository = $repository;
		$this->term_index = $term_index ?? new ReleaseTermIndex();
	}

	public function policy(): AccessPolicyEngine {
		if ( null === $this->policy ) {
			throw new \LogicException( 'AccessPolicyEngine was not initialized on ReleaseBlockContext.' );
		}

		return $this->policy;
	}

	/**
	 * Safely check whether the current subject has full access to a release.
	 */
	public function is_access_allowed( int $release_id ): bool {
		if ( null === $this->policy || $release_id <= 0 ) {
			return false;
		}

		return $this->policy->decide( $release_id, AccessSubject::current() )->is_allowed();
	}

	public function repository(): ReleaseRepository {
		return $this->repository;
	}

	public function term_index(): ReleaseTermIndex {
		return $this->term_index;
	}

	public function playability(): ReleasePlayability {
		if ( null === $this->playability ) {
			$this->playability = new ReleasePlayability( $this->repository, $this->policy );
		}

		return $this->playability;
	}

	/**
	 * Resolve release ID from attributes, block context, or global post.
	 *
	 * @param array<string, mixed> $attributes     Block attributes.
	 * @param object|null          $render_context Active WP_Block context.
	 */
	public function release_id( array $attributes, $render_context = null ): int {
		return ReleaseResolver::resolve( $attributes, $render_context );
	}

	public function mark_denied_gate_rendered( int $release_id ): bool {
		return $this->mark_access_panel_rendered( $release_id, 'deny' );
	}

	/**
	 * Ensure a release access panel (denied gate or granted badge) renders at most once per page state.
	 */
	public function mark_access_panel_rendered( int $release_id, string $variant ): bool {
		$is_rest = ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( function_exists( 'wp_is_serving_rest_request' ) && wp_is_serving_rest_request() );
		if ( BlockSupport::is_editor_context() || $is_rest ) {
			return true;
		}
		$state_key = (int) get_current_user_id() . ':' . $variant;
		if ( ( $this->last_rendered_access_state[ $release_id ] ?? null ) === $state_key ) {
			return false;
		}
		$this->last_rendered_access_state[ $release_id ] = $state_key;

		return true;
	}

	/**
	 * Ensure full-specs release metadata renders at most once per release on a frontend HTML page.
	 */
	public function mark_full_specs_rendered( int $release_id ): bool {
		$is_rest = ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( function_exists( 'wp_is_serving_rest_request' ) && wp_is_serving_rest_request() );
		if ( BlockSupport::is_editor_context() || $is_rest ) {
			return true;
		}
		if ( isset( $this->full_specs_rendered[ $release_id ] ) ) {
			return false;
		}
		$this->full_specs_rendered[ $release_id ] = true;

		return true;
	}

	/**
	 * Read a message override: block attribute -> global setting -> default copy.
	 *
	 * @param array<string, mixed> $attributes  Block attributes.
	 */
	public function message_attribute( array $attributes, string $attribute, string $setting_key, string $fallback ): string {
		$value = isset( $attributes[ $attribute ] ) && is_scalar( $attributes[ $attribute ] ) ? sanitize_text_field( (string) $attributes[ $attribute ] ) : '';
		if ( '' !== $value ) {
			return $value;
		}

		$setting = (string) Settings::get( $setting_key );

		return '' !== $setting ? $setting : $fallback;
	}

	/**
	 * Read an HTTP(S) URL attribute, rejecting unsafe schemes.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function url_attribute( array $attributes, string $key ): string {
		$value = isset( $attributes[ $key ] ) && is_scalar( $attributes[ $key ] ) ? esc_url_raw( (string) $attributes[ $key ], array( 'http', 'https' ) ) : '';

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Resolve a tri-state block override against a global enabled/disabled setting.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function visibility_attribute( array $attributes, string $key, string $setting_key ): bool {
		$value = BlockSupport::key_attribute( $attributes, $key, array( 'inherit', 'enabled', 'disabled' ), 'inherit' );
		if ( 'enabled' === $value ) {
			return true;
		}
		if ( 'disabled' === $value ) {
			return false;
		}

		return 'enabled' === Settings::get( $setting_key );
	}

	/**
	 * Shared taxonomy terms formatting (plain comma list or linked archive anchors).
	 *
	 * @param array<int, mixed> $terms      Term names or WP_Term instances.
	 * @param bool              $link_terms Whether to render archive links.
	 */
	public function terms_markup( array $terms, bool $link_terms ): string {
		if ( ! $link_terms ) {
			$names = array();
			foreach ( $terms as $term ) {
				$name = is_object( $term ) && isset( $term->name ) ? (string) $term->name : (string) $term;
				if ( '' !== $name ) {
					$names[] = $name;
				}
			}

			return empty( $names ) ? '' : esc_html( implode( ', ', $names ) );
		}

		$links = array();
		foreach ( $terms as $term ) {
			if ( ! is_object( $term ) || empty( $term->name ) ) {
				continue;
			}
			$url     = get_term_link( $term );
			$links[] = ! is_wp_error( $url ) && is_string( $url ) && '' !== $url
				? '<a href="' . esc_url( $url ) . '">' . esc_html( $term->name ) . '</a>'
				: esc_html( $term->name );
		}

		return implode( ', ', $links );
	}

	/**
	 * Shared compact HTTPS preview button for track rows and related cards.
	 */
	public function compact_preview_button( int $release_id ): string {
		$url = TrackStreamPresenter::preview_url( $release_id, $this->repository );
		if ( '' === $url ) {
			return '';
		}

		$title   = get_the_title( $release_id );
		$artists = wp_get_post_terms( $release_id, 'mw_artist', array( 'fields' => 'names' ) );
		$artist  = is_array( $artists ) && ! empty( $artists ) ? implode( ', ', $artists ) : '';
		$image   = get_the_post_thumbnail_url( $release_id, 'thumbnail' );
		$link    = get_permalink( $release_id );
		$limit   = TrackStreamPresenter::preview_limit( $release_id, $this->repository );

		return '<button class="mw-preview-button mw-preview-button--compact" type="button" aria-pressed="false" data-preview-url="' . esc_url( $url ) . '" data-preview-title="' . esc_attr( $title ) . '" data-preview-artist="' . esc_attr( $artist ) . '" data-preview-image="' . esc_url( is_string( $image ) ? $image : '' ) . '" data-preview-link="' . esc_url( is_string( $link ) ? $link : '' ) . '" data-preview-limit="' . esc_attr( (string) $limit ) . '"><span class="mw-preview-button__icon" aria-hidden="true">▶</span><span>' . esc_html__( 'پیش‌نمایش', 'music-wave-core' ) . '</span></button>';
	}

	/**
	 * Gate restricted release excerpts/content in syndication feeds.
	 */
	public function filter_feed_content( string $content ): string {
		$post = get_post();
		if ( ! $post instanceof WP_Post || ReleasePostType::KEY !== $post->post_type ) {
			return $content;
		}

		$decision = $this->policy->decide( $post->ID, AccessSubject::current() );
		if ( $decision->is_allowed() ) {
			return $content;
		}

		$message = (string) Settings::get( 'restricted_message' );
		if ( 'purchase_required' === $decision->reason() ) {
			$message = (string) Settings::get( 'purchase_message' );
		} elseif ( 'membership_required' === $decision->reason() ) {
			$message = (string) Settings::get( 'membership_message' );
		}
		if ( '' === trim( $message ) ) {
			$message = __( 'این انتشار در حال حاضر در دسترس نیست.', 'music-wave-core' );
		}

		$permalink = get_permalink( $post );
		$link      = is_string( $permalink ) && '' !== $permalink
			? ' <a href="' . esc_url( $permalink ) . '">' . esc_html__( 'مشاهده در وب‌سایت', 'music-wave-core' ) . '</a>'
			: '';

		/**
		 * Filter the gated feed fragment served instead of a denied release body.
		 *
		 * @param string         $markup     Gate markup.
		 * @param int            $release_id Release being gated.
		 * @param AccessDecision $decision   Decision that denied the viewer.
		 */
		return (string) apply_filters(
			'music_wave_feed_gate_markup',
			'<div class="mw-feed-gate mw-feed-gate--' . sanitize_html_class( $decision->reason() ) . '"><p>' . esc_html( $message ) . $link . '</p></div>',
			$post->ID,
			$decision
		);
	}
}
