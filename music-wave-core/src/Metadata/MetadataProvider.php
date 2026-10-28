<?php
/**
 * Music metadata provider contract.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Metadata;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface MetadataProvider {
	/**
	 * Stable machine name, used as the transient cache namespace.
	 */
	public function name(): string;

	/**
	 * Whether the provider has enough configuration to run.
	 */
	public function is_enabled(): bool;

	/**
	 * Priority for the fallback chain. Lower runs first.
	 */
	public function priority(): int;

	/**
	 * Search the remote catalog and return normalized results.
	 *
	 * @return array<int, MetadataResult>
	 *
	 * @throws RateLimitException When the provider throttles or depletes its quota.
	 */
	public function search( MetadataQuery $query ): array;

	/**
	 * Try to resolve a cover art URL for a result that lacks one.
	 */
	public function cover_for( MetadataResult $result ): string;
}
