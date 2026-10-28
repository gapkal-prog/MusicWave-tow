<?php
/**
 * Release persistence contract.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface ReleaseRepository {
	/** @return mixed */
	public function get( int $release_id, string $key );

	/** @param mixed $value */
	public function update( int $release_id, string $key, $value ): bool;

	public function delete( int $release_id, string $key ): bool;

	/** @return array<int, int> */
	public function product_ids( int $release_id ): array;

	/** @return array<int, int> */
	public function collection_ids( int $release_id ): array;

	/**
	 * Read the canonical ordered children of a collection release.
	 *
	 * @return array<int, array<string, int|string|null>>
	 */
	public function collection_items( int $collection_id ): array;

	/**
	 * Replace the ordered children of a collection release atomically.
	 *
	 * @param array<int, array<string, mixed>> $items Ordered child definitions.
	 */
	public function replace_collection_items( int $collection_id, array $items ): bool;
}
