<?php
/**
 * Dynamic blocks coordinator for release metadata, access status, credits,
 * track collections, catalog filters, previews, downloads, and related releases.
 *
 * Delegates all specialized rendering to single-responsibility (SRP) services
 * under `ManaCore\MusicWave\Core\Blocks\Release\*` and reads canonical schemas
 * from `blocks/{slug}/block.json` via `BlockSupport::register_dynamic()`.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Blocks;

use ManaCore\MusicWave\Core\Access\AccessPolicyEngine;
use ManaCore\MusicWave\Core\Access\AccessSubject;
use ManaCore\MusicWave\Core\Blocks\Concerns\ReleaseResolver;
use ManaCore\MusicWave\Core\Blocks\Release\CatalogFilterRenderer;
use ManaCore\MusicWave\Core\Blocks\Release\CollectionAndDownloadRenderer;
use ManaCore\MusicWave\Core\Blocks\Release\RelatedReleasesRenderer;
use ManaCore\MusicWave\Core\Blocks\Release\ReleaseBlockContext;
use ManaCore\MusicWave\Core\Blocks\Release\ReleaseMetaRenderer;
use ManaCore\MusicWave\Core\Catalog\ReleasePostType;
use ManaCore\MusicWave\Core\Catalog\ReleaseTermIndex;
use ManaCore\MusicWave\Core\Contracts\ReleaseRepository;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ReleaseBlocks {
	/**
	 * Canonical attribute inventory across bundled release dynamic blocks.
	 *
	 * @var array<int, string>
	 */
	public const REGISTERED_ATTRIBUTES = array(
		'releaseId',
		'layout',
		'compact',
		'showLabels',
		'linkTerms',
		'showTaxonomyChips',
		'showCatalogNumber',
		'showReleaseDate',
		'showDuration',
		'showBpm',
		'showKey',
		'showArtist',
		'showGenre',
		'showMood',
		'showLabel',
		'showReleaseType',
		'fullSpecs',
		'showLibraryButton',
		'showActions',
		'showWhenGranted',
		'grantedMessage',
		'restrictedMessage',
		'purchaseMessage',
		'purchaseCtaLabel',
		'membershipMessage',
		'membershipCtaLabel',
		'membershipCtaUrl',
		'heading',
		'showHeading',
		'showRole',
		'showPosition',
		'showArtwork',
		'showTotalDuration',
		'showPreview',
		'showDownload',
		'showDownloadQuality',
		'showHeader',
		'density',
		'playIcon',
		'downloadIcon',
		'actionStyle',
		'actionsVisibility',
		'groupByDisc',
		'showSearch',
		'showArtistFilter',
		'showGenreFilter',
		'showMoodFilter',
		'showTypeFilter',
		'showSort',
		'showReset',
		'maxTerms',
		'searchPlaceholder',
		'submitLabel',
		'resetLabel',
		'showCount',
		'showChips',
		'label',
		'style',
		'variant',
		'size',
		'shape',
		'icon',
		'showIcon',
		'description',
		'showDescription',
		'showQuality',
		'showStream',
		'downloadLabel',
		'playLabel',
		'loginLabel',
		'columns',
		'imageShape',
		'showDate',
		'showExcerpt',
		'showAction',
		'actionLabel',
		'itemsToShow',
		'orderBy',
		'order',
		'sameArtistSection',
		'sameArtistHeading',
		'sameArtistIcon',
		'similarSection',
		'similarHeading',
		'similarIcon',
		'matchGenre',
		'matchMood',
		'matchType',
	);

	/** @var AccessPolicyEngine|null */
	private $policy;

	/** @var ReleaseRepository */
	private $repository;

	/** @var ReleaseTermIndex|null */
	private $term_index;

	/** @var ReleaseBlockContext|null */
	private $context;

	/** @var ReleaseMetaRenderer|null */
	private $meta_renderer;

	/** @var CollectionAndDownloadRenderer|null */
	private $collection_renderer;

	/** @var CatalogFilterRenderer|null */
	private $catalog_renderer;

	/** @var RelatedReleasesRenderer|null */
	private $related_renderer;

	/** @var object|null */
	private $render_context;

	public function __construct( AccessPolicyEngine $policy, ReleaseRepository $repository, ?ReleaseTermIndex $term_index = null ) {
		$this->policy     = $policy;
		$this->repository = $repository;
		$this->term_index = $term_index;
	}

	private function context(): ReleaseBlockContext {
		if ( null === $this->context ) {
			$this->context = new ReleaseBlockContext( $this->policy, $this->repository, $this->term_index );
		}

		return $this->context;
	}

	private function meta_renderer(): ReleaseMetaRenderer {
		if ( null === $this->meta_renderer ) {
			$this->meta_renderer = new ReleaseMetaRenderer( $this->context() );
		}

		return $this->meta_renderer;
	}

	private function collection_renderer(): CollectionAndDownloadRenderer {
		if ( null === $this->collection_renderer ) {
			$this->collection_renderer = new CollectionAndDownloadRenderer( $this->context() );
		}

		return $this->collection_renderer;
	}

	private function catalog_renderer(): CatalogFilterRenderer {
		if ( null === $this->catalog_renderer ) {
			$this->catalog_renderer = new CatalogFilterRenderer();
		}

		return $this->catalog_renderer;
	}

	private function related_renderer(): RelatedReleasesRenderer {
		if ( null === $this->related_renderer ) {
			$this->related_renderer = new RelatedReleasesRenderer( $this->context() );
		}

		return $this->related_renderer;
	}

	/**
	 * Register all release dynamic blocks from their canonical `block.json` directories.
	 */
	public function register(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		$this->register_dynamic_block( 'release-meta', 'render_meta' );
		$this->register_dynamic_block( 'access-panel', 'render_access_panel' );
		$this->register_dynamic_block( 'release-credits', 'render_credits' );
		$this->register_dynamic_block( 'collection-list', 'render_collection_list' );
		$this->register_dynamic_block( 'catalog-filters', 'render_catalog_filters' );
		$this->register_dynamic_block( 'catalog-results', 'render_catalog_results' );
		$this->register_dynamic_block( 'preview-player', 'render_preview_player' );
		$this->register_dynamic_block( 'download-button', 'render_download_button' );
		$this->register_dynamic_block( 'related-releases', 'render_related_releases' );
		$this->register_dynamic_block( 'release-card', 'render_release_card_item' );
	}

	public function filter_content( string $content ): string {
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_feed() ) {
			return $content;
		}

		$post = get_post();
		if ( ! $post instanceof WP_Post || ReleasePostType::KEY !== $post->post_type ) {
			return $content;
		}

		if ( $this->context()->policy()->decide( $post->ID, AccessSubject::current() )->is_allowed() ) {
			return $content;
		}

		return $this->render_access_panel( array( 'releaseId' => $post->ID ) );
	}

	public function filter_feed_content( string $content ): string {
		return $this->context()->filter_feed_content( $content );
	}

	/** @param array<string, mixed> $attributes */
	public function render_meta( array $attributes ): string {
		return $this->meta_renderer()->render_meta( $attributes, $this->render_context );
	}

	/** @param array<string, mixed> $attributes */
	public function render_access_panel( array $attributes ): string {
		return $this->meta_renderer()->render_access_panel( $attributes, $this->render_context );
	}

	/** @param array<string, mixed> $attributes */
	public function render_credits( array $attributes ): string {
		return $this->meta_renderer()->render_credits( $attributes, $this->render_context );
	}

	/** @param array<string, mixed> $attributes */
	public function render_collection_list( array $attributes ): string {
		return $this->collection_renderer()->render_collection_list( $attributes, $this->render_context );
	}

	/** @param array<string, mixed> $attributes */
	public function render_catalog_filters( array $attributes ): string {
		return $this->catalog_renderer()->render_catalog_filters( $attributes );
	}

	/** @param array<string, mixed> $attributes */
	public function render_catalog_results( array $attributes ): string {
		return $this->catalog_renderer()->render_catalog_results( $attributes );
	}

	/** @param array<string, mixed> $attributes */
	public function render_preview_player( array $attributes ): string {
		return $this->collection_renderer()->render_preview_player( $attributes, $this->render_context );
	}

	/** @param array<string, mixed> $attributes */
	public function render_download_button( array $attributes ): string {
		return $this->collection_renderer()->render_download_button( $attributes, $this->render_context );
	}

	/** @param array<string, mixed> $attributes */
	public function render_related_releases( array $attributes ): string {
		return $this->related_renderer()->render( $attributes, $this->render_context );
	}

	/**
	 * Render one release card for a Query Loop.
	 *
	 * The card itself comes from `ReleaseCard`, the same class the PHP shelves
	 * use, so a loop renders the identical card the shelf renders.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render_release_card_item( array $attributes ): string {
		$release_id = ReleaseResolver::resolve_explicit_or_contextual( $attributes, $this->render_context );
		if ( $release_id < 1 ) {
			return '';
		}

		return ReleaseCard::render_for_post( $release_id, $attributes );
	}

	/** @param array<string, mixed> $options */
	private function related_card( int $release_id, array $options ): string {
		return $this->related_renderer()->related_card( $release_id, $options );
	}

	/**
	 * @param array<int, int>      $release_ids Related release IDs.
	 * @param array<string, mixed> $options     Resolved card options.
	 */
	private function related_section( string $heading, array $release_ids, array $options ): string {
		return $this->related_renderer()->related_section( $heading, $release_ids, $options );
	}

	private function register_dynamic_block( string $name, string $callback ): void {
		BlockSupport::register_dynamic(
			'music-wave/' . $name,
			function ( $attributes, $content, $block ) use ( $callback ): string {
				unset( $content );
				$previous_context     = $this->render_context;
				$this->render_context = is_object( $block ) ? $block : null;

				try {
					return (string) $this->{$callback}( is_array( $attributes ) ? $attributes : array() );
				} finally {
					$this->render_context = $previous_context;
				}
			}
		);
	}
}
