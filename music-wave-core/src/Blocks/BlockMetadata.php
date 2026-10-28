<?php
/**
 * Block metadata bridge for the bundled block.json definitions.
 *
 * Single source of truth (DRY) for block attributes, supports, and context
 * across server registration and client editor localization.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Blocks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class BlockMetadata {
	public const PREFIX = 'music-wave/';

	/** @var array<string, array<string, mixed>> */
	private static $cache = array();

	/**
	 * Return the directory holding the block.json for a MusicWave block.
	 *
	 * @param string $block_name Full block name, e.g. music-wave/release-meta.
	 */
	public static function directory( string $block_name ): string {
		$relative = 0 === strpos( $block_name, self::PREFIX ) ? substr( $block_name, strlen( self::PREFIX ) ) : $block_name;

		return dirname( __DIR__, 2 ) . '/blocks/' . $relative;
	}

	/**
	 * Load and cache the decoded block.json metadata for one block.
	 *
	 * @param string $block_name Full block name, e.g. music-wave/release-meta.
	 * @return array<string, mixed>|null Null when the metadata file is missing or invalid.
	 */
	public static function load( string $block_name ): ?array {
		if ( isset( self::$cache[ $block_name ] ) ) {
			return self::$cache[ $block_name ];
		}

		$file = self::directory( $block_name ) . '/block.json';
		if ( ! is_readable( $file ) ) {
			return null;
		}

		// Local bundled metadata file, never a remote URL.
		$metadata = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $metadata ) || empty( $metadata['name'] ) ) {
			return null;
		}

		self::$cache[ $block_name ] = $metadata;

		return $metadata;
	}

	/**
	 * List the full names of every bundled MusicWave block.
	 *
	 * @return array<int, string>
	 */
	public static function block_names(): array {
		$directories = glob( dirname( __DIR__, 2 ) . '/blocks/*/block.json' );

		return array_values(
			array_map(
				static function ( string $file ): string {
					return self::PREFIX . basename( dirname( $file ) );
				},
				is_array( $directories ) ? $directories : array()
			)
		);
	}

	/**
	 * Convert block.json metadata into register_block_type() arguments.
	 *
	 * @param array<string, mixed> $metadata Decoded block.json contents.
	 * @return array<string, mixed>
	 */
	public static function registration_args( array $metadata ): array {
		$args = array();
		if ( isset( $metadata['apiVersion'] ) ) {
			$args['api_version'] = (int) $metadata['apiVersion'];
		}
		if ( isset( $metadata['attributes'] ) && is_array( $metadata['attributes'] ) ) {
			$args['attributes'] = $metadata['attributes'];
		}
		if ( isset( $metadata['supports'] ) && is_array( $metadata['supports'] ) ) {
			$args['supports'] = $metadata['supports'];
		}
		if ( isset( $metadata['usesContext'] ) && is_array( $metadata['usesContext'] ) ) {
			$args['uses_context'] = array_values( $metadata['usesContext'] );
		}

		return $args;
	}

	/**
	 * Build the client-side editor block definition from canonical block.json metadata
	 * merged with localized UI copy (title, description, keywords, icon).
	 *
	 * @param string               $block_name Full block name, e.g. music-wave/release-meta.
	 * @param array<string, mixed> $localized  Localized UI properties (title, description, keywords, icon).
	 * @return array<string, mixed>
	 */
	public static function editor_entry( string $block_name, array $localized ): array {
		$metadata = self::load( $block_name );
		$server   = is_array( $metadata ) ? self::registration_args( $metadata ) : array();

		$entry = array(
			'name'        => $block_name,
			'title'       => isset( $localized['title'] ) ? (string) $localized['title'] : (string) ( $metadata['title'] ?? '' ),
			'description' => isset( $localized['description'] ) ? (string) $localized['description'] : (string) ( $metadata['description'] ?? '' ),
			'category'    => isset( $metadata['category'] ) ? (string) $metadata['category'] : 'music-wave',
			'icon'        => $localized['icon'] ?? ( $metadata['icon'] ?? 'format-audio' ),
			'keywords'    => isset( $localized['keywords'] ) && is_array( $localized['keywords'] )
				? array_values( $localized['keywords'] )
				: ( isset( $metadata['keywords'] ) && is_array( $metadata['keywords'] ) ? array_values( $metadata['keywords'] ) : array() ),
			'attributes'  => $server['attributes'] ?? array(),
			'supports'    => $server['supports'] ?? BlockSupport::appearance_tools(),
		);

		if ( ! empty( $server['uses_context'] ) ) {
			$entry['usesContext'] = $server['uses_context'];
		}
		if ( isset( $metadata['example'] ) && is_array( $metadata['example'] ) ) {
			$entry['example'] = $metadata['example'];
		}

		return $entry;
	}
}
