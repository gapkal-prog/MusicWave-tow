<?php
/**
 * Optional detailed metadata provider contract.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Metadata;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface MetadataEnrichmentProvider {
	/**
	 * Fetch richer details for one selected search result.
	 *
	 * @param MetadataResult $result Selected normalized result.
	 */
	public function enrich( MetadataResult $result ): MetadataResult;
}
