<?php
/**
 * Catalog module composition.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Modules;

use ManaCore\MusicWave\Core\Catalog\ReleasePostType;
use ManaCore\MusicWave\Core\Catalog\ReleaseTaxonomies;
use ManaCore\MusicWave\Core\Catalog\ArtistTermMeta;
use ManaCore\MusicWave\Core\Catalog\ReleaseArchiveQuery;
use ManaCore\MusicWave\Core\Catalog\ReleasePermalinks;
use ManaCore\MusicWave\Core\Catalog\ReleaseDefaults;
use ManaCore\MusicWave\Core\Contracts\Module;
use ManaCore\MusicWave\Core\Discovery\ScriptAwareSearchQuery;
use ManaCore\MusicWave\Core\Migrations\MigrationRunner;
use ManaCore\MusicWave\Core\Schema\ReleaseMetaRegistry;
use ManaCore\MusicWave\Core\Infrastructure\CollectionRestPolicy;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Catalog implements Module {
	/** @var ReleasePostType */
	private $post_type;

	/** @var ReleaseTaxonomies */
	private $taxonomies;

	/** @var ReleaseMetaRegistry */
	private $meta_registry;

	/** @var MigrationRunner */
	private $migrations;

	/** @var CollectionRestPolicy|null */
	private $collection_rest_policy;

	/** @var ArtistTermMeta|null */
	private $artist_term_meta;

	/** @var ReleaseArchiveQuery|null */
	private $archive_query;

	/** @var ReleaseDefaults|null */
	private $release_defaults;

	/** @var ReleasePermalinks|null */
	private $permalinks;

	/** @var ScriptAwareSearchQuery|null */
	private $script_search;

	public function __construct( ReleasePostType $post_type, ReleaseTaxonomies $taxonomies, ReleaseMetaRegistry $meta_registry, MigrationRunner $migrations, ?CollectionRestPolicy $collection_rest_policy = null, ?ArtistTermMeta $artist_term_meta = null, ?ReleaseArchiveQuery $archive_query = null, ?ReleaseDefaults $release_defaults = null, ?ReleasePermalinks $permalinks = null, ?ScriptAwareSearchQuery $script_search = null ) {
		$this->post_type              = $post_type;
		$this->taxonomies             = $taxonomies;
		$this->meta_registry          = $meta_registry;
		$this->migrations             = $migrations;
		$this->collection_rest_policy = $collection_rest_policy;
		$this->artist_term_meta       = $artist_term_meta;
		$this->archive_query          = $archive_query;
		$this->release_defaults       = $release_defaults;
		$this->permalinks             = $permalinks;
		$this->script_search          = $script_search;
	}

	public function register(): void {
		add_action( 'init', array( $this->post_type, 'register' ), 5 );
		add_action( 'init', array( $this->taxonomies, 'register' ), 6 );
		add_action( 'init', array( $this->meta_registry, 'register' ), 7 );
		add_action( 'admin_init', array( $this->migrations, 'maybe_run' ), 5 );
		if ( null !== $this->collection_rest_policy ) {
			$this->collection_rest_policy->register();
		}
		if ( null !== $this->artist_term_meta ) {
			$this->artist_term_meta->register();
		}
		if ( null !== $this->archive_query ) {
			$this->archive_query->register();
		}
		if ( null !== $this->release_defaults ) {
			$this->release_defaults->register();
		}
		if ( null !== $this->permalinks ) {
			$this->permalinks->register();
		}
		if ( null !== $this->script_search ) {
			$this->script_search->register();
		}
	}
}
