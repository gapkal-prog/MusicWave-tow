<?php
/**
 * Shared streamable-track metadata, recursive collection expansion, and upsell presenter.
 *
 * Centralizes playable track payloads, recursive album/collection/playlist release
 * expansion, streamable asset selection, preview URL validation, preview duration
 * clamping, and restricted playback CTA payloads shared across PlaybackQueueRoutes,
 * PlaylistRoutes, PreviewPlayer, and ReleasePlayability (SOLID SRP + DRY).
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
use ManaCore\MusicWave\Core\Downloads\ReleaseDownloadCatalog;
use ManaCore\MusicWave\Core\Support\Settings;
use ManaCore\MusicWave\Core\Account\AuthPolicy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TrackStreamPresenter {
	public const MAX_EXPANSION_DEPTH = 3;

	/**
	 * Recursively expand a release (single track, podcast episode, multi-track album,
	 * EP, mix, podcast show, or editorial collection/playlist) into ordered playable
	 * queue entries with cycle protection.
	 *
	 * @param array<int, true> $visited Visited release IDs along the active recursion branch.
	 * @return array<int, array<string, mixed>>
	 */
	public static function expand_release_tracks(
		int $root_id,
		int $release_id,
		AccessSubject $subject,
		?AccessPolicyEngine $policy = null,
		?ReleaseRepository $repository = null,
		int $limit = 50,
		array $visited = array(),
		int $depth = 0
	): array {
		if ( $release_id < 1 || $limit < 1 || isset( $visited[ $release_id ] ) || $depth > self::MAX_EXPANSION_DEPTH ) {
			return array();
		}
		if ( ReleasePostType::KEY !== get_post_type( $release_id ) || 'publish' !== get_post_status( $release_id ) ) {
			return array();
		}

		$visited[ $release_id ] = true;
		$children               = self::child_release_ids( $release_id, $repository );

		if ( ! empty( $children ) && $depth < self::MAX_EXPANSION_DEPTH ) {
			$tracks = array();
			$seen   = array();
			foreach ( $children as $child_id ) {
				$remaining = $limit - count( $tracks );
				if ( $remaining < 1 ) {
					break;
				}
				$expanded = self::expand_release_tracks(
					$root_id > 0 ? $root_id : $release_id,
					$child_id,
					$subject,
					$policy,
					$repository,
					$remaining,
					$visited,
					$depth + 1
				);
				foreach ( $expanded as $entry ) {
					$entry_id   = isset( $entry['releaseId'] ) ? (int) $entry['releaseId'] : 0;
					$entry_qual = isset( $entry['quality'] ) && is_scalar( $entry['quality'] ) ? (string) $entry['quality'] : '';
					$seen_key   = $entry_id . ':' . $entry_qual;
					if ( $entry_id > 0 && isset( $seen[ $seen_key ] ) ) {
						continue;
					}
					if ( $entry_id > 0 ) {
						$seen[ $seen_key ] = true;
					}
					// Inherit immediate container artwork when child + root lacked thumbnails.
					if ( empty( $entry['image'] ) ) {
						$parent_thumb = get_the_post_thumbnail_url( $release_id, 'thumbnail' );
						if ( is_string( $parent_thumb ) && '' !== $parent_thumb ) {
							$entry['image'] = $parent_thumb;
						}
					}
					$tracks[] = $entry;
					if ( count( $tracks ) >= $limit ) {
						break 2;
					}
				}
			}

			if ( ! empty( $tracks ) ) {
				return $tracks;
			}
		}

		$effective_root = $root_id > 0 ? $root_id : $release_id;
		$single         = self::track_payload(
			$effective_root,
			$release_id,
			$subject,
			$policy,
			$repository,
			$effective_root !== $release_id
		);
		if ( null === $single ) {
			return array();
		}

		if ( ! empty( $single['full'] ) ) {
			$file_groups = self::streamable_file_groups( $release_id, $repository );
			if ( count( $file_groups ) > 1 ) {
				$tracks = array();
				foreach ( $file_groups as $group ) {
					if ( count( $tracks ) >= $limit ) {
						break;
					}
					$entry            = $single;
					$entry['quality'] = $group['quality'];
					$entry['fileKey'] = $group['fileKey'];
					$entry['title']   = self::format_file_track_title( (string) $single['title'], (string) $group['label'], true );
					$tracks[]         = $entry;
				}
				return $tracks;
			}
		}

		return array( $single );
	}

	/**
	 * Ordered child release IDs of a collection release (album, EP, mix, playlist, podcast show).
	 *
	 * @return array<int, int>
	 */
	public static function child_release_ids( int $release_id, ?ReleaseRepository $repository = null ): array {
		$raw = array();
		if ( null !== $repository ) {
			$raw = $repository->collection_items( $release_id );
		} else {
			$meta = get_post_meta( $release_id, 'mw_collection_items', true );
			$raw  = is_array( $meta ) ? $meta : array();
		}

		$ids = array();
		foreach ( $raw as $item ) {
			$child_id = is_array( $item ) && isset( $item['release_id'] ) ? absint( $item['release_id'] ) : 0;
			if ( $child_id > 0 && $child_id !== $release_id ) {
				$ids[] = $child_id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Build one access-aware queue track entry, or null when nothing is playable.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function track_payload(
		int $root_id,
		int $release_id,
		AccessSubject $subject,
		?AccessPolicyEngine $policy = null,
		?ReleaseRepository $repository = null,
		bool $fallback_root_thumbnail = false
	): ?array {
		if ( ReleasePostType::KEY !== get_post_type( $release_id ) || 'publish' !== get_post_status( $release_id ) ) {
			return null;
		}

		$quality = '';
		if ( null !== $policy && $policy->decide( $release_id, $subject )->is_allowed() ) {
			$quality = self::streamable_quality( $release_id, $repository );
		}

		$preview_url = self::preview_url( $release_id, $repository );
		if ( '' === $quality && '' === $preview_url ) {
			return null;
		}

		$artists = wp_get_post_terms( $release_id, 'mw_artist', array( 'fields' => 'names' ) );
		$image   = get_the_post_thumbnail_url( $release_id, 'thumbnail' );
		if ( ( ! is_string( $image ) || '' === $image ) && $fallback_root_thumbnail ) {
			$root_image = get_the_post_thumbnail_url( $root_id, 'thumbnail' );
			$image      = is_string( $root_image ) ? $root_image : '';
		}
		$image = is_string( $image ) ? $image : '';
		$link  = get_permalink( $release_id );
		$full  = '' !== $quality;
		$lrc   = (string) get_post_meta( $release_id, 'mw_lyrics_lrc', true );

		return array(
			'releaseId'    => $release_id,
			'rootId'       => $root_id,
			'title'        => get_the_title( $release_id ),
			'artist'       => is_array( $artists ) && ! empty( $artists ) ? implode( ', ', $artists ) : '',
			'image'        => $image,
			'link'         => is_string( $link ) ? $link : '',
			'previewUrl'   => $preview_url,
			'previewLimit' => $full ? 0 : self::preview_limit( $release_id, $repository ),
			'full'         => $full,
			'quality'      => $quality,
			'lyricsLrc'    => $lrc,
			'lyricsOffset' => (int) get_post_meta( $release_id, 'mw_lyrics_offset', true ),
			'hasLyrics'    => '' !== trim( $lrc ),
		);
	}

	/**
	 * Ordered browser-streamable file groups for a release (one entry per distinct downloadable file).
	 *
	 * @return array<int, array{fileKey: string, label: string, quality: string}>
	 */
	public static function streamable_file_groups( int $release_id, ?ReleaseRepository $repository = null ): array {
		$assets = self::read_meta( $release_id, 'mw_download_assets', $repository );
		$list   = is_array( $assets ) ? $assets : array();
		if ( empty( $list ) ) {
			$legacy = self::read_meta( $release_id, 'mw_download_asset_id', $repository );
			if ( is_string( $legacy ) && '' !== $legacy ) {
				$list[] = array(
					'key'      => 'standard',
					'label'    => __( 'دانلود استاندارد', 'music-wave-core' ),
					'asset_id' => $legacy,
				);
			}
		}

		$normalized = array();
		foreach ( $list as $asset ) {
			if ( ! is_array( $asset ) || empty( $asset['key'] ) || empty( $asset['asset_id'] ) ) {
				continue;
			}
			$key = sanitize_key( (string) $asset['key'] );
			if ( '' === $key ) {
				continue;
			}
			$label        = isset( $asset['label'] ) && '' !== trim( (string) $asset['label'] ) ? sanitize_text_field( (string) $asset['label'] ) : $key;
			$normalized[] = array(
				'key'        => $key,
				'label'      => $label,
				'file_key'   => isset( $asset['file_key'] ) ? sanitize_key( (string) $asset['file_key'] ) : '',
				'file_label' => isset( $asset['file_label'] ) ? sanitize_text_field( (string) $asset['file_label'] ) : '',
				'streamable' => ReleaseDownloadCatalog::is_asset_streamable( $asset ),
			);
		}

		$groups = array();
		foreach ( ReleaseDownloadCatalog::group_files( $normalized, true ) as $group ) {
			foreach ( $group['qualities'] as $quality ) {
				if ( ! empty( $quality['streamable'] ) && '' !== (string) $quality['key'] ) {
					$groups[] = array(
						'fileKey' => (string) $group['key'],
						'label'   => (string) $group['label'],
						'quality' => (string) $quality['key'],
					);
					break;
				}
			}
		}

		return $groups;
	}

	/**
	 * Format display title for a single downloadable file track.
	 */
	public static function format_file_track_title( string $release_title, string $file_label, bool $multiple_files ): string {
		$clean_release = trim( $release_title );
		$clean_file    = trim( $file_label );
		$default_label = __( 'دانلود اصلی', 'music-wave-core' );

		if ( ! $multiple_files || '' === $clean_file || $clean_file === $clean_release || $clean_file === $default_label ) {
			return '' !== $clean_release ? $clean_release : $clean_file;
		}

		return '' !== $clean_release ? $clean_release . ' — ' . $clean_file : $clean_file;
	}

	/**
	 * Return the first browser-streamable download quality key for a release.
	 */
	public static function streamable_quality( int $release_id, ?ReleaseRepository $repository = null ): string {
		$groups = self::streamable_file_groups( $release_id, $repository );

		return isset( $groups[0]['quality'] ) ? $groups[0]['quality'] : '';
	}

	/**
	 * Validate a public preview URL:
	 * - Any valid `https://` URL is allowed.
	 * - Same-host `http://` URLs are allowed ONLY when the WordPress site itself runs
	 *   over `http://` (local/staging dev without TLS), preventing mixed content &
	 *   rejecting third-party `http://` URLs on HTTPS sites.
	 *
	 * @param mixed $url Candidate URL.
	 */
	public static function is_valid_preview_url( $url ): bool {
		if ( ! is_string( $url ) || '' === trim( $url ) ) {
			return false;
		}
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( 'https' === $scheme ) {
			return '' !== (string) wp_parse_url( $url, PHP_URL_HOST );
		}
		if ( 'http' === $scheme && function_exists( 'home_url' ) ) {
			$home        = home_url( '/' );
			$home_scheme = strtolower( (string) wp_parse_url( $home, PHP_URL_SCHEME ) );
			$home_host   = strtolower( (string) wp_parse_url( $home, PHP_URL_HOST ) );
			$url_host    = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			return 'http' === $home_scheme && '' !== $home_host && $home_host === $url_host;
		}

		return false;
	}

	/**
	 * Return the validated public preview URL for a release, or '' when absent/insecure.
	 */
	public static function preview_url( int $release_id, ?ReleaseRepository $repository = null ): string {
		$url = self::read_meta( $release_id, 'mw_preview_url', $repository );

		return is_string( $url ) && self::is_valid_preview_url( $url ) ? $url : '';
	}

	/**
	 * Resolve the configured preview cutoff with safe bounds (10–120s, default 30s).
	 */
	public static function preview_limit( int $release_id, ?ReleaseRepository $repository = null ): int {
		$limit = absint( self::read_meta( $release_id, 'mw_preview_duration', $repository ) );

		return $limit >= 10 && $limit <= 120 ? $limit : 30;
	}

	/**
	 * Build the subscription/purchase upsell prompt shown during restricted playback.
	 *
	 * @return array<string, string>
	 */
	public static function upsell_payload( int $release_id, ?AccessDecision $decision, ?ReleaseRepository $repository = null ): array {
		$link      = get_permalink( $release_id );
		$link      = is_string( $link ) ? $link : '';
		$message   = '';
		$cta_url   = '';
		$cta_label = '';

		if ( null !== $decision && ! $decision->is_allowed() ) {
			switch ( $decision->reason() ) {
				case 'purchase_required':
					$message   = (string) Settings::get( 'purchase_message' );
					$message   = '' !== $message ? $message : __( 'این انتشار را بخرید تا قفل پخش کامل باز شود.', 'music-wave-core' );
					$cta_label = (string) Settings::get( 'purchase_cta_label' );
					$cta_label = '' !== $cta_label ? $cta_label : __( 'مشاهده گزینه‌های خرید', 'music-wave-core' );
					if ( null !== $repository ) {
						foreach ( $repository->product_ids( $release_id ) as $product_id ) {
							if ( 'product' !== get_post_type( $product_id ) ) {
								continue;
							}
							$product_link = get_permalink( $product_id );
							if ( is_string( $product_link ) && '' !== $product_link ) {
								$cta_url = $product_link;
								break;
							}
						}
					}
					$cta_url = '' !== $cta_url ? $cta_url : $link;
					break;
				case 'membership_required':
					$message   = (string) Settings::get( 'membership_message' );
					$message   = '' !== $message ? $message : __( 'برای گوش‌دادن به قطعه‌های کامل بدون محدودیت مشترک شوید.', 'music-wave-core' );
					$cta_label = (string) Settings::get( 'membership_cta_label' );
					$cta_label = '' !== $cta_label ? $cta_label : __( 'مشاهده گزینه‌های عضویت', 'music-wave-core' );
					$cta_url   = (string) Settings::get( 'membership_cta_url' );
					if ( '' === $cta_url ) {
						$cta_url = self::default_membership_cta_url();
					}
					break;
				default:
					$message = (string) Settings::get( 'restricted_message' );
					$message = '' !== $message ? $message : __( 'این انتشار در حال حاضر در دسترس نیست.', 'music-wave-core' );
					break;
			}
		}

		$is_guest = get_current_user_id() < 1 && '' !== $link;

		return array(
			'message'     => $message,
			'ctaLabel'    => $cta_label,
			'ctaUrl'      => $cta_url,
			'previewNote' => __( 'شما در حال گوش‌دادن به یک پیش‌نمایش محدود هستید. دسترسی داشته باشید تا از پخش کامل لذت ببرید.', 'music-wave-core' ),
			'loginUrl'    => $is_guest ? AuthPolicy::login_url( $link ) : '',
			'loginLabel'  => $is_guest ? __( 'وارد شوید', 'music-wave-core' ) : '',
		);
	}

	/**
	 * Default membership plans destination when `membership_cta_url` is unconfigured.
	 */
	private static function default_membership_cta_url(): string {
		$filtered = apply_filters( 'music_wave_membership_cta_url_fallback', '' );
		if ( is_string( $filtered ) && '' !== $filtered ) {
			return $filtered;
		}
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$wc_url = wc_get_page_permalink( 'myaccount' );
			if ( is_string( $wc_url ) && '' !== $wc_url ) {
				return $wc_url;
			}
		}
		return function_exists( 'home_url' ) ? home_url( '/account/' ) : '';
	}

	/**
	 * Read release metadata via repository when injected, falling back to post meta.
	 *
	 * @return mixed
	 */
	private static function read_meta( int $release_id, string $key, ?ReleaseRepository $repository ) {
		if ( null !== $repository ) {
			try {
				return $repository->get( $release_id, $key );
			} catch ( \InvalidArgumentException $exception ) {
				return null;
			}
		}

		return get_post_meta( $release_id, $key, true );
	}
}
