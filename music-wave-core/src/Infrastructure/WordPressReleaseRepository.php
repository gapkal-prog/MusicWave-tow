<?php
/**
 * WordPress release repository.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Infrastructure;

use InvalidArgumentException;
use ManaCore\MusicWave\Core\Catalog\ReleasePostType;
use ManaCore\MusicWave\Core\Contracts\ReleaseRepository;
use ManaCore\MusicWave\Core\Schema\ReleaseMetaSchema;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WordPressReleaseRepository implements ReleaseRepository {
	private const COLLECTION_REVERSE_META_KEY = '_mw_collection_ids';

	/** @var ReleaseMetaSchema */
	private $schema;

	public function __construct( ?ReleaseMetaSchema $schema = null ) {
		$this->schema = null !== $schema ? $schema : new ReleaseMetaSchema();
	}

	/** @return mixed */
	public function get( int $release_id, string $key ) {
		$this->assert_release( $release_id );
		$this->assert_key( $key );

		return get_post_meta( $release_id, $key, true );
	}

	/** @param mixed $value */
	public function update( int $release_id, string $key, $value ): bool {
		$this->assert_release( $release_id );
		$definition = $this->assert_key( $key );
		$value      = $definition->sanitize( $value );
		if ( 'mw_product_ids' === $key && is_array( $value ) ) {
			$value = array_values(
				array_filter(
					$value,
					static function ( int $product_id ): bool {
						return 'product' === get_post_type( $product_id );
					}
				)
			);
		}

		if ( false !== update_post_meta( $release_id, $key, $value ) ) {
			return true;
		}

		// update_post_meta() returns false for failed writes AND for no-op
		// writes (unchanged value). Re-reading distinguishes the harmless
		// no-change case so callers do not treat identical re-saves as errors.
		return get_post_meta( $release_id, $key, true ) === $value;
	}

	public function delete( int $release_id, string $key ): bool {
		$this->assert_release( $release_id );
		$this->assert_key( $key );

		return delete_post_meta( $release_id, $key );
	}

	/** @return array<int, int> */
	public function product_ids( int $release_id ): array {
		$value = $this->get( $release_id, 'mw_product_ids' );

		return is_array( $value ) ? array_values( array_map( 'absint', $value ) ) : array();
	}

	/**
	 * Read the canonical ordered children of a collection.
	 *
	 * @return array<int, array<string, int|string|null>>
	 */
	public function collection_items( int $collection_id ): array {
		$this->assert_release( $collection_id );
		$value = $this->get( $collection_id, 'mw_collection_items' );

		return is_array( $value ) ? $this->normalize_positions( $value ) : array();
	}

	/**
	 * Replace all children atomically after validating the relation contract.
	 *
	 * @param array<int, array<string, mixed>> $items Ordered child definitions.
	 */
	public function replace_collection_items( int $collection_id, array $items ): bool {
		$this->assert_release( $collection_id );
		$old_items = $this->collection_items( $collection_id );

		return $this->replace_collection_items_with_previous( $collection_id, $old_items, $items );
	}

	/**
	 * Finalize a REST write after WordPress has persisted its meta payload.
	 *
	 * @param array<int, array<string, int|string|null>> $previous_items Relation before the REST request.
	 * @param array<int, array<string, mixed>>            $items          Requested relation.
	 */
	public function replace_collection_items_with_previous( int $collection_id, array $previous_items, array $items ): bool {
		$this->assert_release( $collection_id );
		$items = $this->validate_collection_items( $collection_id, $items );

		if ( ! $this->update( $collection_id, 'mw_collection_items', $items ) ) {
			return false;
		}

		$this->sync_collection_reverse_index( $collection_id, $previous_items, $items );

		return true;
	}

	/**
	 * Validate relation input for REST or editor integrations without writing it.
	 *
	 * @param array<int, array<string, mixed>> $items Relation input.
	 * @return array<int, array<string, int|string|null>> Canonical relation.
	 */
	public function validate_collection_items_for_write( int $collection_id, array $items ): array {
		$this->assert_release( $collection_id );

		return $this->validate_collection_items( $collection_id, $items );
	}

	/**
	 * Validate relation input for a collection that does not exist yet.
	 *
	 * REST creation requests cannot run parent-dependent checks (cycles,
	 * type compatibility) before the post exists, but every shape, uniqueness,
	 * and child-existence rule must still fail fast with a structured error
	 * instead of surviving until the silent after-insert rollback
	 * (PROJECT_PLAN.md §5.2, Stage 1 deliverable 4).
	 *
	 * @param array<int, array<string, mixed>> $items Relation input.
	 * @return array<int, array<string, int|string|null>> Canonical relation.
	 */
	public function validate_collection_items_for_create( array $items ): array {
		return $this->validate_collection_shape( $items );
	}

	/**
	 * Remove every occurrence of a child from a collection.
	 */
	public function remove_collection_item( int $collection_id, int $release_id ): bool {
		$items = array_values(
			array_filter(
				$this->collection_items( $collection_id ),
				static function ( array $item ) use ( $release_id ): bool {
					return (int) $item['release_id'] !== $release_id;
				}
			)
		);

		return $this->replace_collection_items( $collection_id, $items );
	}

	/**
	 * Return cached reverse lookups for a child release.
	 *
	 * @return array<int, int>
	 */
	public function collection_ids( int $release_id ): array {
		$this->assert_release( $release_id );
		$value = get_post_meta( $release_id, self::COLLECTION_REVERSE_META_KEY, true );

		return is_array( $value ) ? array_values( array_filter( array_map( 'absint', $value ) ) ) : array();
	}

	/** @param array<int, array<string, int|string|null>> $items @return array<int, int> */
	private function item_ids( array $items ): array {
		$ids = array();
		foreach ( $items as $item ) {
			$ids[] = absint( $item['release_id'] );
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $items Candidate items.
	 * @return array<int, array<string, int|string|null>>
	 * @throws InvalidArgumentException When an entry is malformed, duplicated, or cyclic.
	 */
	private function validate_collection_items( int $collection_id, array $items ): array {
		$normalized = $this->validate_collection_shape( $items );

		foreach ( $normalized as $item ) {
			$child_id = (int) $item['release_id'];
			if ( $child_id === $collection_id || $this->would_create_cycle( $collection_id, $child_id ) ) {
				throw new InvalidArgumentException( 'A collection cannot contain itself or one of its ancestors.' );
			}
			$this->assert_compatible_item( $collection_id, $child_id, (string) $item['role'] );
		}

		return $normalized;
	}

	/**
	 * Parent-independent relation validation: shape, uniqueness, child existence.
	 *
	 * @param array<int, array<string, mixed>> $items Relation input.
	 * @return array<int, array<string, int|string|null>> Canonical relation.
	 * @throws InvalidArgumentException When entries are malformed, duplicated, or reference unknown releases.
	 */
	private function validate_collection_shape( array $items ): array {
		$definition = $this->schema->get( 'mw_collection_items' );
		$sanitized  = null === $definition ? array() : $definition->sanitize( $items );
		if ( count( $sanitized ) !== count( $items ) ) {
			throw new InvalidArgumentException( 'Collection items contain malformed entries.' );
		}

		$positions = array();
		$ids       = array();
		foreach ( $sanitized as $item ) {
			$child_id = (int) $item['release_id'];
			$position = (int) $item['position'];
			if ( isset( $ids[ $child_id ] ) || isset( $positions[ $position ] ) ) {
				throw new InvalidArgumentException( 'Collection items must have unique releases and positions.' );
			}
			$ids[ $child_id ]       = true;
			$positions[ $position ] = true;
			$this->assert_release( $child_id );
		}

		usort(
			$sanitized,
			static function ( array $left, array $right ): int {
				return $left['position'] <=> $right['position'];
			}
		);

		return $this->normalize_positions( $sanitized );
	}

	/** @param array<int, array<string, int|string|null>> $items @return array<int, array<string, int|string|null>> */
	private function normalize_positions( array $items ): array {
		$normalized = array();
		foreach ( $items as $index => $item ) {
			$normalized[] = array(
				'release_id' => isset( $item['release_id'] ) ? absint( $item['release_id'] ) : 0,
				'position'   => $index + 1,
				'disc'       => isset( $item['disc'] ) ? absint( $item['disc'] ) : null,
				'role'       => isset( $item['role'] ) ? sanitize_key( (string) $item['role'] ) : 'track',
			);
		}

		return $normalized;
	}

	private function assert_compatible_item( int $collection_id, int $child_id, string $role ): void {
		$parent_types = $this->release_types( $collection_id );
		$child_types  = $this->release_types( $child_id );
		if ( ! function_exists( 'wp_get_post_terms' ) ) {
			return;
		}
		if ( empty( $parent_types ) || empty( $child_types ) ) {
			throw new InvalidArgumentException( 'Collection and child release types must be classified before linking.' );
		}

		$collection_types = array( 'album', 'ep', 'mix', 'playlist', 'podcast_show' );
		$item_types       = 'episode' === $role ? array( 'podcast_episode' ) : array( 'track', 'single' );
		if ( ! array_intersect( $collection_types, $parent_types ) || ! array_intersect( $item_types, $child_types ) ) {
			throw new InvalidArgumentException( 'Collection and child release types are incompatible.' );
		}
		if ( 'episode' === $role && ! array_intersect( array( 'podcast_show' ), $parent_types ) ) {
			throw new InvalidArgumentException( 'Episode items can only belong to a podcast show.' );
		}
	}

	/** @return array<int, string> */
	private function release_types( int $release_id ): array {
		if ( ! function_exists( 'wp_get_post_terms' ) ) {
			return array();
		}

		$terms = wp_get_post_terms( $release_id, 'mw_release_type', array( 'fields' => 'slugs' ) );
		return is_array( $terms ) ? array_values( array_map( 'sanitize_key', $terms ) ) : array();
	}

	private function would_create_cycle( int $collection_id, int $child_id ): bool {
		$seen  = array();
		$stack = array( $child_id );
		while ( ! empty( $stack ) ) {
			$current = array_pop( $stack );
			if ( $current === $collection_id ) {
				return true;
			}
			if ( isset( $seen[ $current ] ) ) {
				continue;
			}
			$seen[ $current ] = true;
			foreach ( $this->collection_items( $current ) as $item ) {
				$stack[] = (int) $item['release_id'];
			}
		}

		return false;
	}

	private function add_reverse_index( int $release_id, int $collection_id ): void {
		$ids   = $this->collection_ids( $release_id );
		$ids[] = $collection_id;
		update_post_meta( $release_id, self::COLLECTION_REVERSE_META_KEY, array_values( array_unique( array_map( 'absint', $ids ) ) ) );
	}

	/** @param array<int, array<string, int|string|null>> $old_items @param array<int, array<string, int|string|null>> $new_items */
	private function sync_collection_reverse_index( int $collection_id, array $old_items, array $new_items ): void {
		$old_ids = $this->item_ids( $old_items );
		$new_ids = $this->item_ids( $new_items );
		foreach ( array_diff( $old_ids, $new_ids ) as $release_id ) {
			$this->remove_reverse_index( $release_id, $collection_id );
		}
		foreach ( array_diff( $new_ids, $old_ids ) as $release_id ) {
			$this->add_reverse_index( $release_id, $collection_id );
		}
	}

	private function remove_reverse_index( int $release_id, int $collection_id ): void {
		$ids = array_values(
			array_filter(
				$this->collection_ids( $release_id ),
				static function ( int $id ) use ( $collection_id ): bool {
					return $id !== $collection_id;
				}
			)
		);
		if ( empty( $ids ) ) {
			delete_post_meta( $release_id, self::COLLECTION_REVERSE_META_KEY );
			return;
		}
		update_post_meta( $release_id, self::COLLECTION_REVERSE_META_KEY, $ids );
	}

	private function assert_release( int $release_id ): void {
		if ( ReleasePostType::KEY !== get_post_type( $release_id ) ) {
			throw new InvalidArgumentException( 'A valid MusicWave release ID is required.' );
		}
	}

	/**
	 * @return \ManaCore\MusicWave\Core\Schema\MetaDefinition
	 * @throws InvalidArgumentException When the metadata key is not registered.
	 */
	private function assert_key( string $key ) {
		$definition = $this->schema->get( $key );
		if ( null === $definition ) {
			throw new InvalidArgumentException( 'Unknown MusicWave release metadata key.' );
		}

		return $definition;
	}
}
