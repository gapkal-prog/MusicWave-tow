<?php
/**
 * External catalog search boundary.
 *
 * Sites whose catalogs outgrow native WordPress search can supply an adapter
 * (Elasticsearch, Meilisearch, a hosted index, ...) through the
 * `music_wave_catalog_search_adapter` filter. The adapter only proposes
 * candidate release IDs: Core still applies its own visibility policy, so an
 * external index that has fallen out of sync — or has been tampered with —
 * can never surface unpublished or private releases
 * (PROJECT_PLAN.md Stage 5 deliverable 6).
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Discovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface CatalogSearchAdapter {
	/**
	 * Propose candidate release IDs for one search term.
	 *
	 * @param string $term  Sanitized search term.
	 * @param int    $limit Maximum number of candidates requested.
	 * @return array<int, int>|null Candidate release IDs, or null to fall back
	 *                             to native WordPress search.
	 */
	public function search( string $term, int $limit ): ?array;
}
