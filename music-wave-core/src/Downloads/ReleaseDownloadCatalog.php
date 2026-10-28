<?php
/**
 * Shared customer-facing catalog of protected download variants for a release.
 *
 * Centralizes normalization and file-grouping previously duplicated across
 * ReleaseBlocks and AccountLibrary while keeping provider storage IDs private
 * (SOLID SRP + DRY).
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Downloads;

use ManaCore\MusicWave\Core\Contracts\ReleaseRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ReleaseDownloadCatalog {
	public const STREAMABLE_FORMATS = array( 'mp3', 'm4a', 'aac', 'ogg', 'oga', 'opus', 'wav', 'flac' );

	/** @var ReleaseRepository */
	private $releases;

	public function __construct( ReleaseRepository $releases ) {
		$this->releases = $releases;
	}

	/**
	 * Single source of truth for whether a download variant can stream in browser audio.
	 *
	 * Handles `direct:<https-url>?query` by inspecting the URL path rather than
	 * the raw query-bearing string.
	 *
	 * @param array<string, mixed> $asset Stored or legacy-normalized asset entry.
	 */
	public static function is_asset_streamable( array $asset ): bool {
		$format = isset( $asset['format'] ) && is_scalar( $asset['format'] ) ? sanitize_key( strtolower( (string) $asset['format'] ) ) : '';
		if ( '' === $format && isset( $asset['file_name'] ) && is_scalar( $asset['file_name'] ) ) {
			$file_ext = pathinfo( (string) $asset['file_name'], PATHINFO_EXTENSION );
			$format   = '' !== $file_ext ? sanitize_key( strtolower( $file_ext ) ) : '';
		}
		if ( '' === $format && isset( $asset['asset_id'] ) && is_scalar( $asset['asset_id'] ) ) {
			$raw_id = trim( (string) $asset['asset_id'] );
			if ( DirectLinkAsset::has_direct_scheme( $raw_id ) ) {
				$direct_url = DirectLinkAsset::extract_url( $raw_id );
				$url_path   = function_exists( 'wp_parse_url' ) ? wp_parse_url( $direct_url, PHP_URL_PATH ) : parse_url( $direct_url, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
				$ext        = is_string( $url_path ) && '' !== $url_path ? pathinfo( $url_path, PATHINFO_EXTENSION ) : '';
			} else {
				$ext = pathinfo( $raw_id, PATHINFO_EXTENSION );
			}
			$format = '' !== $ext ? sanitize_key( strtolower( $ext ) ) : '';
		}

		return in_array( $format, self::STREAMABLE_FORMATS, true );
	}

	/**
	 * Normalized customer-facing download variants without private storage paths/IDs.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function assets( int $release_id ): array {
		$variants = $this->releases->get( $release_id, 'mw_download_assets' );
		$legacy   = $this->releases->get( $release_id, 'mw_download_asset_id' );
		$assets   = is_array( $variants ) ? $variants : array();
		if ( empty( $assets ) && is_string( $legacy ) && '' !== $legacy ) {
			$assets[] = array(
				'key'      => 'standard',
				'label'    => __( 'دانلود استاندارد', 'music-wave-core' ),
				'asset_id' => $legacy,
			);
		}

		$result = array();
		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) || empty( $asset['key'] ) || empty( $asset['label'] ) || empty( $asset['asset_id'] ) ) {
				continue;
			}
			$key   = sanitize_key( (string) $asset['key'] );
			$label = sanitize_text_field( (string) $asset['label'] );
			if ( '' === $key || '' === $label ) {
				continue;
			}
			$result[] = array(
				'key'        => $key,
				'label'      => $label,
				'file_key'   => isset( $asset['file_key'] ) ? sanitize_key( (string) $asset['file_key'] ) : '',
				'file_label' => isset( $asset['file_label'] ) ? sanitize_text_field( (string) $asset['file_label'] ) : '',
				'streamable' => self::is_asset_streamable( $asset ),
			);
		}

		return $result;
	}

	/**
	 * Group normalized variants by downloadable file key.
	 *
	 * @param array<int, array<string, mixed>> $assets Normalized variants from assets().
	 * @return array<string, array<string, mixed>>
	 */
	public static function group_files( array $assets, bool $include_streamable_flag = true ): array {
		$files = array();
		foreach ( $assets as $asset ) {
			$file_key   = isset( $asset['file_key'] ) && '' !== (string) $asset['file_key'] ? sanitize_key( (string) $asset['file_key'] ) : 'main-download';
			$file_label = isset( $asset['file_label'] ) && '' !== (string) $asset['file_label'] ? sanitize_text_field( (string) $asset['file_label'] ) : __( 'دانلود اصلی', 'music-wave-core' );
			if ( ! isset( $files[ $file_key ] ) ) {
				$files[ $file_key ] = array(
					'key'       => $file_key,
					'label'     => $file_label,
					'qualities' => array(),
				);
			}
			$quality = array(
				'key'   => (string) $asset['key'],
				'label' => (string) $asset['label'],
			);
			if ( $include_streamable_flag ) {
				$quality['streamable'] = ! empty( $asset['streamable'] );
			}
			$files[ $file_key ]['qualities'][] = $quality;
		}

		return $files;
	}
}
