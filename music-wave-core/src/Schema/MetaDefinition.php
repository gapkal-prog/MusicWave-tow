<?php
/**
 * Immutable release metadata definition.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MetaDefinition {
	/** @var string */
	private $key;

	/** @var string */
	private $type;

	/** @var mixed */
	private $default;

	/** @var bool|array<string, mixed> */
	private $show_in_rest;

	/** @var array<string, mixed> */
	private $admin;

	/**
	 * @param string                    $key          Meta key.
	 * @param string                    $type         WordPress meta type.
	 * @param mixed                     $default_value Default value.
	 * @param bool|array<string, mixed> $show_in_rest REST exposure configuration.
	 * @param array<string, mixed>      $admin        Admin field configuration.
	 */
	public function __construct( string $key, string $type, $default_value, $show_in_rest, array $admin = array() ) {
		$this->key          = $key;
		$this->type         = $type;
		$this->default      = $default_value;
		$this->show_in_rest = $show_in_rest;
		$this->admin        = $admin;
	}

	public function key(): string {
		return $this->key;
	}

	public function type(): string {
		return $this->type;
	}

	/** @return mixed */
	public function default_value() {
		return $this->default;
	}

	/** @return bool|array<string, mixed> */
	public function show_in_rest() {
		return $this->show_in_rest;
	}

	/** @return array<string, mixed> */
	public function admin(): array {
		return $this->admin;
	}

	public function is_admin_field(): bool {
		return ! empty( $this->admin );
	}

	/**
	 * Sanitize a value according to the canonical field definition.
	 *
	 * @param mixed $value Raw input.
	 * @return mixed
	 */
	public function sanitize( $value ) {
		switch ( $this->key ) {
			case 'mw_release_date':
				if ( ! is_scalar( $value ) ) {
					return '';
				}
				$value = sanitize_text_field( (string) $value );
				return $this->is_iso_date( $value ) ? $value : '';
			case 'mw_release_year':
				$year = absint( $value );
				return $year >= 1000 && $year <= 9999 ? $year : 0;
			case 'mw_isrc':
				if ( ! is_scalar( $value ) ) {
					return '';
				}
				$isrc = strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', (string) $value ) );
				return preg_match( '/^[A-Z]{2}[A-Z0-9]{3}\d{7}$/', $isrc ) ? $isrc : '';
			case 'mw_metadata_source':
				if ( ! is_scalar( $value ) ) {
					return '';
				}
				return esc_url_raw( (string) $value, array( 'https' ) );
			case 'mw_metadata_provider':
				return is_scalar( $value ) ? sanitize_key( (string) $value ) : '';
			case 'mw_preview_url':
				if ( ! is_scalar( $value ) ) {
					return '';
				}
				$url = esc_url_raw( (string) $value, array( 'https' ) );
				return 'https' === wp_parse_url( $url, PHP_URL_SCHEME ) ? $url : '';
			case 'mw_duration':
				return max( 0, absint( $value ) );
			case 'mw_preview_duration':
				$duration = absint( $value );
				return $duration >= 10 && $duration <= 120 ? $duration : 30;
			case 'mw_bpm':
				$bpm = absint( $value );
				return ( 0 === $bpm || ( $bpm >= 20 && $bpm <= 300 ) ) ? $bpm : 0;
			case 'mw_lyrics_lrc':
				if ( ! is_scalar( $value ) ) {
					return '';
				}

				return sanitize_textarea_field( (string) $value );
			case 'mw_lyrics_offset':
				$offset = (int) $value;
				return max( -10000, min( 10000, $offset ) );
			case 'mw_track_number':
			case 'mw_episode_number':
			case 'mw_season_number':
				return absint( $value );
			case 'mw_explicit':
				return in_array( $value, array( true, 1, '1', 'true', 'on' ), true );
			case 'mw_product_ids':
				return $this->sanitize_integer_list( $value );
			case 'mw_membership_levels':
				return $this->sanitize_key_list( $value );
			case 'mw_credits':
				return $this->sanitize_credits( $value );
			case 'mw_collection_items':
				return $this->sanitize_collection_items( $value );
			case 'mw_download_assets':
				return $this->sanitize_download_assets( $value );
			case 'mw_access_mode':
				if ( ! is_scalar( $value ) ) {
					return 'restricted';
				}
				$value = sanitize_key( (string) $value );
				return in_array( $value, array( 'public', 'purchase', 'membership', 'purchase_or_membership', 'restricted' ), true ) ? $value : 'restricted';
			default:
				if ( ! is_scalar( $value ) ) {
					return $this->default;
				}
				return sanitize_text_field( (string) $value );
		}
	}

	private function is_iso_date( string $value ): bool {
		$value = trim( $value );
		if ( '' === $value ) {
			return false;
		}
		if ( 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches ) ) {
			return checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1] );
		}
		if ( 1 === preg_match( '/^(\d{4})-(\d{2})$/', $value, $matches ) ) {
			$y  = (int) $matches[1];
			$mo = (int) $matches[2];
			return $y >= 1000 && $y <= 9999 && $mo >= 1 && $mo <= 12;
		}
		if ( 1 === preg_match( '/^(\d{4})$/', $value, $matches ) ) {
			$y = (int) $matches[1];
			return $y >= 1000 && $y <= 9999;
		}
		return false;
	}

	/** @param mixed $value @return array<int, int> */
	private function sanitize_integer_list( $value ): array {
		$values = is_array( $value ) ? $value : explode( ',', (string) $value );
		$values = array_map( 'absint', $values );
		$values = array_filter( $values );

		return array_values( array_unique( $values ) );
	}

	/** @param mixed $value @return array<int, string> */
	private function sanitize_key_list( $value ): array {
		$values = is_array( $value ) ? $value : explode( ',', (string) $value );
		$values = array_map( 'sanitize_key', $values );
		$values = array_filter( $values );

		return array_values( array_unique( $values ) );
	}

	/** @param mixed $value @return array<int, array<string, string>> */
	private function sanitize_credits( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$credits = array();
		foreach ( $value as $credit ) {
			if ( ! is_array( $credit ) || empty( $credit['name'] ) ) {
				continue;
			}

			/*
			 * The role is displayed verbatim next to the name and doubles as the group
			 * heading, so it is free text: `sanitize_key()` would strip every Persian
			 * label down to ''.
			 */
			$credits[] = array(
				'name' => sanitize_text_field( (string) $credit['name'] ),
				'role' => sanitize_text_field( isset( $credit['role'] ) ? (string) $credit['role'] : '' ),
			);
		}

		return $credits;
	}

	/**
	 * Normalize the ordered collection relation without trusting client input.
	 *
	 * Relation ownership and release-type compatibility are enforced by the
	 * repository. This boundary only accepts a bounded list of well-shaped
	 * values and keeps the public representation deterministic.
	 *
	 * @param mixed $value Raw relation list.
	 * @return array<int, array<string, int|string|null>>
	 */
	private function sanitize_collection_items( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$items = array();
		foreach ( array_slice( $value, 0, 1000 ) as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['release_id'], $item['position'] ) || ! is_scalar( $item['release_id'] ) || ! is_scalar( $item['position'] ) || ( isset( $item['disc'] ) && ! is_scalar( $item['disc'] ) ) ) {
				continue;
			}

			$release_id = absint( $item['release_id'] );
			$position   = absint( $item['position'] );
			$role       = isset( $item['role'] ) && is_scalar( $item['role'] ) ? sanitize_key( (string) $item['role'] ) : '';
			$disc       = isset( $item['disc'] ) && '' !== (string) $item['disc'] ? absint( $item['disc'] ) : null;

			if ( $release_id < 1 || $position < 1 || ! in_array( $role, array( 'track', 'episode' ), true ) ) {
				continue;
			}
			if ( null !== $disc && $disc < 1 ) {
				$disc = null;
			}

			$items[] = array(
				'release_id' => $release_id,
				'position'   => $position,
				'disc'       => $disc,
				'role'       => $role,
			);
		}

		usort(
			$items,
			static function ( array $left, array $right ): int {
				if ( $left['position'] === $right['position'] ) {
					return $left['release_id'] <=> $right['release_id'];
				}

				return $left['position'] <=> $right['position'];
			}
		);

		return $items;
	}

	/**
	 * Normalize private download variants without accepting public URLs.
	 *
	 * @param mixed $value Raw asset variants.
	 * @return array<int, array<string, int|string>>
	 */
	private function sanitize_download_assets( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$assets = array();
		$keys   = array();
		foreach ( $value as $asset ) {
			if ( ! is_array( $asset ) || ! isset( $asset['key'], $asset['label'], $asset['asset_id'] ) || ! is_scalar( $asset['key'] ) || ! is_scalar( $asset['label'] ) || ! is_scalar( $asset['asset_id'] ) ) {
				continue;
			}

			$key      = substr( sanitize_key( (string) $asset['key'] ), 0, 40 );
			$label    = substr( sanitize_text_field( (string) $asset['label'] ), 0, 80 );
			$asset_id = sanitize_text_field( (string) $asset['asset_id'] );
			$scheme   = wp_parse_url( $asset_id, PHP_URL_SCHEME );
			if ( '' === $key || '' === $label || '' === $asset_id || isset( $keys[ $key ] ) || in_array( strtolower( (string) $scheme ), array( 'http', 'https' ), true ) ) {
				continue;
			}

			$normalized = array(
				'key'      => $key,
				'label'    => $label,
				'asset_id' => $asset_id,
			);
			$format     = isset( $asset['format'] ) && is_scalar( $asset['format'] ) ? substr( sanitize_key( strtolower( (string) $asset['format'] ) ), 0, 20 ) : '';
			$file_name  = isset( $asset['file_name'] ) && is_scalar( $asset['file_name'] ) ? substr( sanitize_file_name( (string) $asset['file_name'] ), 0, 200 ) : '';
			$file_key   = isset( $asset['file_key'] ) && is_scalar( $asset['file_key'] ) ? substr( sanitize_key( (string) $asset['file_key'] ), 0, 40 ) : '';
			$file_label = isset( $asset['file_label'] ) && is_scalar( $asset['file_label'] ) ? substr( sanitize_text_field( (string) $asset['file_label'] ), 0, 80 ) : '';
			$bitrate    = isset( $asset['bitrate'] ) && is_scalar( $asset['bitrate'] ) ? absint( $asset['bitrate'] ) : 0;
			$duration   = isset( $asset['duration'] ) && is_scalar( $asset['duration'] ) ? absint( $asset['duration'] ) : 0;
			$file_size  = isset( $asset['file_size'] ) && is_scalar( $asset['file_size'] ) ? absint( $asset['file_size'] ) : 0;

			if ( '' !== $format ) {
				$normalized['format'] = $format;
			}
			if ( '' !== $file_name ) {
				$normalized['file_name'] = $file_name;
			}
			if ( '' !== $file_key && '' !== $file_label ) {
				$normalized['file_key']   = $file_key;
				$normalized['file_label'] = $file_label;
			}
			if ( $bitrate > 0 ) {
				$normalized['bitrate'] = $bitrate;
			}
			if ( $duration > 0 ) {
				$normalized['duration'] = $duration;
			}
			if ( $file_size > 0 ) {
				$normalized['file_size'] = $file_size;
			}

			$keys[ $key ] = true;
			$assets[]     = $normalized;
		}

		return $assets;
	}
}
