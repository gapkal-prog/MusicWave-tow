<?php
/**
 * Public search metadata, structured data graph, and crawler discovery module.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Modules;

use ManaCore\MusicWave\Core\Contracts\Module;
use ManaCore\MusicWave\Core\Seo\CrawlerDiscovery;
use ManaCore\MusicWave\Core\Seo\ReleaseJsonLd;
use ManaCore\MusicWave\Core\Seo\ReleaseMetadata;
use ManaCore\MusicWave\Core\Seo\SearchSeo;
use ManaCore\MusicWave\Core\Seo\SiteStructuredData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Seo implements Module {
	/** @var ReleaseJsonLd */
	private $json_ld;
	/** @var ReleaseMetadata */
	private $metadata;
	/** @var SearchSeo */
	private $search_seo;
	/** @var SiteStructuredData */
	private $site_schema;
	/** @var CrawlerDiscovery */
	private $crawlers;

	public function __construct(
		ReleaseJsonLd $json_ld,
		ReleaseMetadata $metadata,
		?SearchSeo $search_seo = null,
		?SiteStructuredData $site_schema = null,
		?CrawlerDiscovery $crawlers = null
	) {
		$this->json_ld     = $json_ld;
		$this->metadata    = $metadata;
		$this->search_seo  = null !== $search_seo ? $search_seo : new SearchSeo();
		$this->site_schema = null !== $site_schema ? $site_schema : new SiteStructuredData();
		$this->crawlers    = null !== $crawlers ? $crawlers : new CrawlerDiscovery();
	}

	/**
	 * @return void
	 */
	public function register(): void {
		$this->site_schema->register();
		$this->json_ld->register();
		$this->metadata->register();
		$this->search_seo->register();
		$this->crawlers->register();
	}
}
