<?php
/**
 * Frontend release rendering & editor localization module.
 *
 * Uses `BlockMetadata::editor_entry()` so every block's attributes, supports,
 * context, category, and example stay defined in a single place (`block.json`)
 * across PHP registration and client editor localization (SOLID SRP + DRY).
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core\Modules;

use ManaCore\MusicWave\Core\Blocks\ArtistProfileBlock;
use ManaCore\MusicWave\Core\Blocks\ArtistShelfBlock;
use ManaCore\MusicWave\Core\Blocks\BlockMetadata;
use ManaCore\MusicWave\Core\Blocks\Concerns\EditorPreviewCatalog;
use ManaCore\MusicWave\Core\Blocks\PreviewPlayer;
use ManaCore\MusicWave\Core\Blocks\ReleaseBlocks;
use ManaCore\MusicWave\Core\Blocks\SearchUnifiedBlock;
use ManaCore\MusicWave\Core\Blocks\ShareButtonBlock;
use ManaCore\MusicWave\Core\Blocks\ShuffleButtonBlock;
use ManaCore\MusicWave\Core\Blocks\TaxonomyShelfBlock;
use ManaCore\MusicWave\Core\Blocks\TermHeroBlock;
use ManaCore\MusicWave\Core\Contracts\Module;
use ManaCore\MusicWave\Core\Contracts\ReleaseRepository;
use ManaCore\MusicWave\Core\Infrastructure\ReleaseRestVisibilityPolicy;
use ManaCore\MusicWave\Core\Infrastructure\WordPressReleaseRepository;
use ManaCore\MusicWave\Core\Playback\PlaybackQueueRoutes;
use ManaCore\MusicWave\Core\Playlists\PlaylistRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Rendering implements Module {
	/**
	 * Canonical attribute inventory localized from bundled `blocks/{slug}/block.json` schemas.
	 *
	 * @var array<int, string>
	 */
	public const LOCALIZED_ATTRIBUTE_KEYS = array(
		'accentColor',
		'accountHeading',
		'actionLabel',
		'actionStyle',
		'actionsVisibility',
		'addedLabel',
		'addressesHeading',
		'allLabel',
		'alphabeticalLabel',
		'artistIds',
		'bioLength',
		'browseIndex',
		'buttonStyle',
		'cardStyle',
		'columns',
		'compact',
		'consentButtonLabel',
		'consentMessage',
		'ctaLabel',
		'defaultRole',
		'defaultSort',
		'defaultType',
		'density',
		'description',
		'descriptionLength',
		'downloadIcon',
		'downloadLabel',
		'downloadsHeading',
		'emptyMessage',
		'emptyText',
		'eyebrow',
		'followIcon',
		'fullSpecs',
		'fullWidth',
		'grantedMessage',
		'groupByDisc',
		'groupByRole',
		'guestMessage',
		'heading',
		'icon',
		'imageShape',
		'imageSize',
		'intro',
		'introText',
		'itemType',
		'itemsToShow',
		'label',
		'layout',
		'level',
		'libraryButtonStyle',
		'libraryHeading',
		'limitPerGroup',
		'loginLabel',
		'matchGenre',
		'matchMood',
		'matchType',
		'maxTerms',
		'membershipCtaLabel',
		'membershipCtaUrl',
		'membershipHeading',
		'membershipMessage',
		'mode',
		'noResultsMessage',
		'order',
		'orderBy',
		'orderby',
		'ordersHeading',
		'panelOrder',
		'pagination',
		'paymentHeading',
		'perPage',
		'playAllLabel',
		'playIcon',
		'playLabel',
		'playlistsHeading',
		'popularLabel',
		'position',
		'purchaseCtaLabel',
		'purchaseMessage',
		'releaseId',
		'requestsHeading',
		'resetLabel',
		'restrictedMessage',
		'sameArtistHeading',
		'sameArtistIcon',
		'sameArtistSection',
		'searchPlaceholder',
		'sectionLinkLabel',
		'sectionUrl',
		'shape',
		'showAccountDetails',
		'showAction',
		'showActive',
		'showAddresses',
		'showAll',
		'showArt',
		'showArtist',
		'showArtistFilter',
		'showArtists',
		'showArtwork',
		'showAuthor',
		'showAvatar',
		'showBilling',
		'showBio',
		'showBpm',
		'showBudget',
		'showBuyButtons',
		'showCatalogNumber',
		'showChips',
		'showClear',
		'showControls',
		'showCount',
		'showCounts',
		'showCrowns',
		'showDate',
		'showDeadline',
		'showDescription',
		'showDownload',
		'showDownloadQuality',
		'showDownloads',
		'showDuration',
		'showEntitled',
		'showExcerpt',
		'showEyebrow',
		'showFilters',
		'showFollowButton',
		'showGenre',
		'showGenreFilter',
		'showGenres',
		'showHeader',
		'showHeading',
		'showIcon',
		'showImage',
		'showKey',
		'showLabel',
		'showLabels',
		'showLibrary',
		'showLibraryButton',
		'showLink',
		'showLinks',
		'showMembershipPanel',
		'showMood',
		'showMoodFilter',
		'showName',
		'showNotices',
		'showOrderLink',
		'showOrders',
		'showPagination',
		'showPaymentMethods',
		'showPhone',
		'showPlans',
		'showPlayAll',
		'showPlayButton',
		'showPlaylists',
		'showPosition',
		'showPreview',
		'showProducts',
		'showProgress',
		'showQuality',
		'showQuickLinks',
		'showReference',
		'showReleaseCount',
		'showReleaseDate',
		'showReleaseType',
		'showReleases',
		'showRemove',
		'showRenewal',
		'showRepeat',
		'showRequests',
		'showReset',
		'showResultCount',
		'showRole',
		'showRoles',
		'showSaved',
		'showSearch',
		'showShipping',
		'showShuffle',
		'showSignOut',
		'showSort',
		'showSortTabs',
		'showStats',
		'showStatus',
		'showStream',
		'showSubscriptionMeta',
		'showTitle',
		'showToggle',
		'showTotalDuration',
		'showType',
		'showTypeFilter',
		'showUpdated',
		'showVipBadge',
		'showViews',
		'showWelcome',
		'showWhen',
		'showWhenGranted',
		'showYear',
		'shuffleLabel',
		'similarHeading',
		'similarIcon',
		'similarSection',
		'size',
		'source',
		'style',
		'submitLabel',
		'taxonomy',
		'termId',
		'termIds',
		'termSlug',
		'variant',
	);

	/** @var ReleaseBlocks */
	private $blocks;

	/** @var ArtistProfileBlock|null */
	private $artist_profile;

	/** @var ArtistShelfBlock|null */
	private $artist_shelf;

	/** @var TaxonomyShelfBlock|null */
	private $taxonomy_shelf;

	/** @var TermHeroBlock|null */
	private $term_hero;

	/** @var PreviewPlayer|null */
	private $preview_player;

	/** @var ShareButtonBlock|null */
	private $share_button;

	/** @var ShuffleButtonBlock|null */
	private $shuffle_button;

	/** @var SearchUnifiedBlock|null */
	private $search_unified;

	/** @var PlaybackQueueRoutes|null */
	private $playback_queue;

	/** @var ReleaseRestVisibilityPolicy|null */
	private $rest_visibility;

	/** @var ReleaseRepository|null */
	private $releases;

	/** @var PlaylistRepository|null */
	private $playlists;

	public function __construct(
		ReleaseBlocks $blocks,
		?ArtistProfileBlock $artist_profile = null,
		?PreviewPlayer $preview_player = null,
		?PlaybackQueueRoutes $playback_queue = null,
		?ReleaseRestVisibilityPolicy $rest_visibility = null,
		?ArtistShelfBlock $artist_shelf = null,
		?TaxonomyShelfBlock $taxonomy_shelf = null,
		?TermHeroBlock $term_hero = null,
		?ShareButtonBlock $share_button = null,
		?ShuffleButtonBlock $shuffle_button = null,
		?SearchUnifiedBlock $search_unified = null,
		?ReleaseRepository $releases = null,
		?PlaylistRepository $playlists = null
	) {
		$this->blocks          = $blocks;
		$this->artist_profile  = $artist_profile;
		$this->artist_shelf    = $artist_shelf;
		$this->taxonomy_shelf  = $taxonomy_shelf;
		$this->term_hero       = $term_hero;
		$this->share_button    = $share_button;
		$this->shuffle_button  = $shuffle_button;
		$this->search_unified  = $search_unified;
		$this->preview_player  = $preview_player;
		$this->playback_queue  = $playback_queue;
		$this->rest_visibility = $rest_visibility;
		$this->releases        = $releases;
		$this->playlists       = $playlists;
	}

	public function register(): void {
		add_action( 'init', array( $this->blocks, 'register' ), 20 );
		if ( null !== $this->artist_profile ) {
			add_action( 'init', array( $this->artist_profile, 'register' ), 21 );
		}
		if ( null !== $this->artist_shelf ) {
			add_action( 'init', array( $this->artist_shelf, 'register' ), 21 );
		}
		if ( null !== $this->taxonomy_shelf ) {
			add_action( 'init', array( $this->taxonomy_shelf, 'register' ), 21 );
		}
		if ( null !== $this->term_hero ) {
			add_action( 'init', array( $this->term_hero, 'register' ), 21 );
		}
		if ( null !== $this->share_button ) {
			add_action( 'init', array( $this->share_button, 'register' ), 22 );
		}
		if ( null !== $this->shuffle_button ) {
			add_action( 'init', array( $this->shuffle_button, 'register' ), 22 );
		}
		if ( null !== $this->search_unified ) {
			add_action( 'init', array( $this->search_unified, 'register' ), 22 );
		}
		if ( null !== $this->preview_player ) {
			add_action( 'init', array( $this->preview_player, 'register' ), 22 );
		}
		if ( null !== $this->playback_queue ) {
			$this->playback_queue->register();
		}
		if ( null !== $this->rest_visibility ) {
			$this->rest_visibility->register();
		}
		add_action( 'init', array( $this, 'register_block_styles' ), 30 );
		add_filter( 'block_categories_all', array( $this, 'register_block_category' ) );
		add_filter( 'the_content', array( $this->blocks, 'filter_content' ), 20 );
		add_filter( 'the_content_feed', array( $this->blocks, 'filter_feed_content' ), 20 );
		add_filter( 'the_excerpt_rss', array( $this->blocks, 'filter_feed_content' ), 20 );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_blocks' ) );
		add_action( 'enqueue_block_assets', array( $this, 'enqueue_editor_canvas_styles' ) );
	}

	/**
	 * Add the dedicated MusicWave category to the block inserter.
	 *
	 * @param mixed $categories Existing block categories.
	 * @return array<int, array<string, mixed>>
	 */
	public function register_block_category( $categories ): array {
		$categories = is_array( $categories ) ? $categories : array();
		foreach ( $categories as $category ) {
			if ( is_array( $category ) && isset( $category['slug'] ) && 'music-wave' === $category['slug'] ) {
				return $categories;
			}
		}

		array_unshift(
			$categories,
			array(
				'slug'  => 'music-wave',
				'title' => __( 'MusicWave', 'music-wave-core' ),
				'icon'  => 'format-audio',
			)
		);

		return $categories;
	}

	/**
	 * Expose existing layout modifiers as editor style variations.
	 */
	public function register_block_styles(): void {
		if ( ! function_exists( 'register_block_style' ) ) {
			return;
		}

		foreach ( self::style_variations() as $block_name => $variations ) {
			foreach ( $variations as $variation ) {
				register_block_style( $block_name, $variation );
			}
		}
	}

	/**
	 * Style variations offered by the dynamic blocks.
	 *
	 * Single source of truth for the Site Editor Styles panel and
	 * `assets/blocks.js`.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function style_variations(): array {
		$styles = array(
			'music-wave/preview-player'    => array(
				array(
					'name'  => 'outline',
					'label' => __( 'طرح کلی', 'music-wave-core' ),
				),
				array(
					'name'  => 'ghost',
					'label' => __( 'بی‌زمینه', 'music-wave-core' ),
				),
			),
			'music-wave/account-dashboard' => array(
				array(
					'name'       => 'tabs',
					'label'      => __( 'برگه‌ها (یک بخش در یک زمان)', 'music-wave-core' ),
					'is_default' => true,
				),
				array(
					'name'  => 'stacked',
					'label' => __( 'روی‌هم‌چیده (همهٔ بخش‌ها نمایش داده می‌شوند)', 'music-wave-core' ),
				),
			),
			'music-wave/preview-button'    => array(
				array(
					'name'  => 'outline',
					'label' => __( 'طرح کلی', 'music-wave-core' ),
				),
				array(
					'name'  => 'ghost',
					'label' => __( 'بی‌زمینه', 'music-wave-core' ),
				),
				array(
					'name'  => 'vinyl',
					'label' => __( 'صفحهٔ وینیل (چرخان)', 'music-wave-core' ),
				),
			),
			'music-wave/release-meta'      => array(
				array(
					'name'  => 'inline',
					'label' => __( 'درون‌خطی', 'music-wave-core' ),
				),
				array(
					'name'  => 'stack',
					'label' => __( 'ردیف‌های انباشته', 'music-wave-core' ),
				),
				array(
					'name'  => 'stamp',
					'label' => __( 'مهر سرمقاله‌ای', 'music-wave-core' ),
				),
			),
			'music-wave/catalog-filters'   => array(
				array(
					'name'  => 'stacked',
					'label' => __( 'انباشته شده', 'music-wave-core' ),
				),
			),
			'music-wave/collection-list'   => array(
				array(
					'name'  => 'tracklist',
					'label' => __( 'فهرست وینیل (شماره‌دار)', 'music-wave-core' ),
				),
			),
			'music-wave/related-releases'  => array(
				array(
					'name'  => 'editorial',
					'label' => __( 'سرمقاله‌ای', 'music-wave-core' ),
				),
				array(
					'name'  => 'vinyl',
					'label' => __( 'وینیل', 'music-wave-core' ),
				),
			),
			'music-wave/catalog-results'   => array(
				array(
					'name'  => 'editorial',
					'label' => __( 'سرمقاله‌ای', 'music-wave-core' ),
				),
			),
			'music-wave/artist-profile'    => array(
				array(
					'name'       => 'editorial',
					'label'      => __( 'سرمقالهای قابدار', 'music-wave-core' ),
					'is_default' => true,
				),
				array(
					'name'  => 'minimal',
					'label' => __( 'مینیمال بی‌کادر', 'music-wave-core' ),
				),
				array(
					'name'  => 'glass',
					'label' => __( 'شیشه‌ای مات', 'music-wave-core' ),
				),
			),
			'music-wave/artists-shelf'     => array(
				array(
					'name'  => 'minimal',
					'label' => __( 'حداقلی', 'music-wave-core' ),
				),
				array(
					'name'  => 'editorial',
					'label' => __( 'سرمقاله‌ای', 'music-wave-core' ),
				),
				array(
					'name'  => 'glass',
					'label' => __( 'شیشه‌ای', 'music-wave-core' ),
				),
			),
			'music-wave/term-hero'         => array(
				array(
					'name'  => 'minimal',
					'label' => __( 'حداقلی', 'music-wave-core' ),
				),
				array(
					'name'  => 'editorial',
					'label' => __( 'سرمقاله‌ای', 'music-wave-core' ),
				),
				array(
					'name'  => 'glass',
					'label' => __( 'شیشه‌ای', 'music-wave-core' ),
				),
			),
			'music-wave/public-playlists'  => array(
				array(
					'name'       => 'cards',
					'label'      => __( 'کارت (سطحی)', 'music-wave-core' ),
					'is_default' => true,
				),
				array(
					'name'  => 'minimal',
					'label' => __( 'حداقل (بدون سطح)', 'music-wave-core' ),
				),
				array(
					'name'  => 'vinyl',
					'label' => __( 'ویترین صفحه (وینیل)', 'music-wave-core' ),
				),
			),
		);

		return $styles;
	}

	/**
	 * Register JavaScript editor counterparts for PHP-rendered blocks.
	 */
	public function enqueue_editor_blocks(): void {
		wp_enqueue_script(
			'music-wave-dynamic-blocks',
			MUSIC_WAVE_CORE_URL . 'assets/blocks.js',
			array( 'wp-block-editor', 'wp-blocks', 'wp-components', 'wp-element', 'wp-i18n', 'wp-data', 'wp-server-side-render' ),
			MUSIC_WAVE_CORE_VERSION,
			true
		);
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'music-wave-dynamic-blocks', 'music-wave-core', MUSIC_WAVE_CORE_PATH . 'languages' );
		}
		wp_localize_script(
			'music-wave-dynamic-blocks',
			'musicWaveDynamicBlocks',
			$this->editor_blocks()
		);
		wp_localize_script(
			'music-wave-dynamic-blocks',
			'musicWaveBlockStyles',
			self::style_variations()
		);
		wp_localize_script(
			'music-wave-dynamic-blocks',
			'musicWaveEditorPreviews',
			EditorPreviewCatalog::editor_choices(
				null !== $this->releases ? $this->releases : new WordPressReleaseRepository(),
				null !== $this->playlists ? $this->playlists : new PlaylistRepository()
			)
		);
	}

	/**
	 * Inserter icon for the request form: a studio microphone with a plus.
	 *
	 * @return array{viewBox: string, paths: array<int, array<string, string>>}
	 */
	private static function request_form_icon(): array {
		return array(
			'viewBox' => '0 0 24 24',
			'paths'   => array(
				array(
					'd'    => 'M12 2.5a3.25 3.25 0 0 1 3.25 3.25v5.5a3.25 3.25 0 0 1-6.5 0v-5.5A3.25 3.25 0 0 1 12 2.5Z',
					'fill' => 'currentColor',
				),
				array(
					'd'           => 'M6.25 10.75a5.75 5.75 0 0 0 11.5 0M12 16.5v3.75M8.75 20.25h6.5',
					'fill'        => 'none',
					'stroke'      => 'currentColor',
					'strokeWidth' => '1.7',
				),
				array(
					'd'           => 'M19.25 2.75v4M17.25 4.75h4',
					'fill'        => 'none',
					'stroke'      => 'currentColor',
					'strokeWidth' => '1.7',
				),
			),
		);
	}

	/**
	 * Load all Core block stylesheets inside the Block/Site Editor canvas (iframe & desktop)
	 * so server-side rendered dynamic blocks (`request-form`, `library-button`, `music-library`,
	 * `request-chat`, custom Dashicon classes, etc.) render with full parity in real time.
	 */
	public function enqueue_editor_canvas_styles(): void {
		if ( ! is_admin() ) {
			return;
		}
		wp_enqueue_style( 'dashicons' );
		wp_enqueue_style(
			'music-wave-request-form',
			MUSIC_WAVE_CORE_URL . 'assets/request-form.css',
			array(),
			MUSIC_WAVE_CORE_VERSION
		);
		wp_enqueue_style(
			'music-wave-library-style',
			MUSIC_WAVE_CORE_URL . 'assets/library.css',
			array(),
			MUSIC_WAVE_CORE_VERSION
		);
		wp_enqueue_style(
			'music-wave-request-chat',
			MUSIC_WAVE_CORE_URL . 'assets/request-chat.css',
			array(),
			MUSIC_WAVE_CORE_VERSION
		);
		wp_enqueue_style(
			'music-wave-editor',
			MUSIC_WAVE_CORE_URL . 'assets/editor.css',
			array( 'music-wave-request-form', 'music-wave-library-style', 'music-wave-request-chat' ),
			MUSIC_WAVE_CORE_VERSION
		);
	}

	/**
	 * Return the client registration metadata for every Core dynamic block.
	 *
	 * Every schema (`attributes`, `supports`, `usesContext`, `category`, `example`)
	 * is read directly from its `blocks/{slug}/block.json` via `BlockMetadata::editor_entry()`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function editor_blocks(): array {
		$localized_ui = array(
			array(
				'name'        => 'music-wave/release-meta',
				'title'       => __( 'فرادادهٔ انتشار', 'music-wave-core' ),
				'description' => __( 'فرادادهٔ انتشار را از هسته MusicWave نمایش می‌دهد.', 'music-wave-core' ),
				'icon'        => 'list-view',
				'keywords'    => array( __( 'شماره کاتالوگ', 'music-wave-core' ), __( 'bpm', 'music-wave-core' ), __( 'جزئیات', 'music-wave-core' ) ),
			),
			array(
				'name'        => 'music-wave/access-panel',
				'title'       => __( 'پنل دسترسی آزاد', 'music-wave-core' ),
				'description' => __( 'وضعیت دسترسی انتشار فعلی و اقدامات را نشان می‌دهد.', 'music-wave-core' ),
				'icon'        => 'lock',
				'keywords'    => array( __( 'خرید', 'music-wave-core' ), __( 'عضویت', 'music-wave-core' ), __( 'محدود', 'music-wave-core' ) ),
			),
			array(
				'name'        => 'music-wave/release-credits',
				'title'       => __( 'عوامل انتشار', 'music-wave-core' ),
				'description' => __( 'عوامل و نقش‌های انتشار را نشان می‌دهد.', 'music-wave-core' ),
				'icon'        => 'id',
				'keywords'    => array( __( 'نقش‌ها', 'music-wave-core' ), __( 'تهیه‌کنندگان', 'music-wave-core' ) ),
			),
			array(
				'name'        => 'music-wave/collection-list',
				'title'       => __( 'لیست قطعه مجموعه', 'music-wave-core' ),
				'description' => __( 'انتشارهای کودک را در یک مجموعه نمایش می‌دهد.', 'music-wave-core' ),
				'icon'        => 'playlist-audio',
				'keywords'    => array( __( 'قطعه‌ها', 'music-wave-core' ), __( 'قسمت‌ها', 'music-wave-core' ), __( 'دیسک', 'music-wave-core' ) ),
			),
			array(
				'name'        => 'music-wave/catalog-filters',
				'title'       => __( 'فیلترهای کاتالوگ', 'music-wave-core' ),
				'description' => __( 'فیلترهای کاتالوگ انتشار را نمایش می‌دهد.', 'music-wave-core' ),
				'icon'        => 'filter',
				'keywords'    => array( __( 'جست‌وجو', 'music-wave-core' ), __( 'آرشیو', 'music-wave-core' ) ),
			),
			array(
				'name'        => 'music-wave/catalog-results',
				'title'       => __( 'نتایج کاتالوگ', 'music-wave-core' ),
				'description' => __( 'خلاصهٔ نتایج کاتالوگ فعلی را نمایش می‌دهد.', 'music-wave-core' ),
				'icon'        => 'chart-bar',
				'keywords'    => array( __( 'شمارش', 'music-wave-core' ), __( 'فیلترهای فعال', 'music-wave-core' ) ),
			),
			array(
				'name'        => 'music-wave/search-unified',
				'title'       => __( 'نتایج جستجوی یکپارچه', 'music-wave-core' ),
				'description' => __( 'نتایج جستجو را گروه‌بندی شده مثل باکس پیشنهاد (انتشار، هنرمند، سبک، محصول) نمایش می‌دهد.', 'music-wave-core' ),
				'icon'        => 'search',
				'keywords'    => array( __( 'جستجو', 'music-wave-core' ), __( 'نتایج', 'music-wave-core' ), __( 'یکپارچه', 'music-wave-core' ) ),
			),
			array(
				'name'        => 'music-wave/preview-player',
				'title'       => __( 'پخش‌کننده پیش‌نمایش', 'music-wave-core' ),
				'description' => __( 'پخش‌کننده پیش‌نمایش صوتی را برای انتشار نمایش می‌دهد.', 'music-wave-core' ),
				'icon'        => 'controls-play',
				'keywords'    => array( __( 'صوتی', 'music-wave-core' ), __( 'گوش کن', 'music-wave-core' ) ),
			),
			array(
				'name'        => 'music-wave/download-button',
				'title'       => __( 'دانلود ایمن', 'music-wave-core' ),
				'description' => __( 'اقدامات دانلود مجاز را برای یک انتشار نمایش می‌دهد.', 'music-wave-core' ),
				'icon'        => 'download',
				'keywords'    => array( __( 'کیفیت', 'music-wave-core' ), __( 'جریان', 'music-wave-core' ), __( 'فایل‌ها', 'music-wave-core' ) ),
			),
			array(
				'name'        => 'music-wave/related-releases',
				'title'       => __( 'انتشارهای مرتبط', 'music-wave-core' ),
				'description' => __( 'انتشارهای مرتبط MusicWave را نمایش می‌دهد.', 'music-wave-core' ),
				'icon'        => 'share',
				'keywords'    => array( __( 'مشابه', 'music-wave-core' ), __( 'همان هنرمند', 'music-wave-core' ), __( 'ویترین', 'music-wave-core' ) ),
			),
			array(
				'name'        => 'music-wave/release-card',
				'title'       => __( 'کارت انتشار', 'music-wave-core' ),
				'description' => __( 'کارت انتشار یادشده در حلقهٔ کوئری: جلد، کنترل پخش، عنوان، هنرمند و پیوند اقدام.', 'music-wave-core' ),
				'icon'        => 'format-image',
				'keywords'    => array( __( 'انتشار', 'music-wave-core' ), __( 'کارت', 'music-wave-core' ), __( 'حلقه', 'music-wave-core' ) ),
			),
			array(
				'name'        => 'music-wave/artist-profile',
				'title'       => __( 'مشخصات هنرمند', 'music-wave-core' ),
				'description' => __( 'نمایهٔ طبقه‌بندی هنرمند را در صفحات بایگانی یا هر الگوی دیگری نمایش می‌دهد.', 'music-wave-core' ),
				'icon'        => 'admin-users',
				'keywords'    => array( __( 'بیوگرافی', 'music-wave-core' ), __( 'هنرمند', 'music-wave-core' ) ),
			),
			array(
				'name'        => 'music-wave/artists-shelf',
				'title'       => __( 'ویترین هنرمندان', 'music-wave-core' ),
				'description' => __( 'هنرمندان کاتالوگ را به‌صورت شبکهٔ واکنش‌گرا، نوار افقی یا فهرست جمع‌وجور با صفحهٔ وینیل پشت جلد، نشان محبوبیت، تعداد آثار و دکمه‌های دنبال‌کردن نمایش می‌دهد.', 'music-wave-core' ),
				'icon'        => 'admin-users',
				'keywords'    => array( __( 'هنرمندان', 'music-wave-core' ), __( 'خواننده‌ها', 'music-wave-core' ), __( 'ویترین', 'music-wave-core' ), __( 'شبکه', 'music-wave-core' ), __( 'دنبال‌کردن', 'music-wave-core' ) ),
			),
			array(
				'name'        => 'music-wave/artists-hero',
				'title'       => __( 'بنر هنرمندان', 'music-wave-core' ),
				'description' => __( 'بنر آغازین فهرست هنرمندان: ابرو، عنوان، مقدمه، آمار کاتالوگ و مجموعه‌ای از صفحه‌های هم‌پوشان هنرمندان برتر.', 'music-wave-core' ),
				'icon'        => 'admin-users',
				'keywords'    => array( __( 'هنرمندان', 'music-wave-core' ), __( 'خواننده‌ها', 'music-wave-core' ), __( 'بنر', 'music-wave-core' ), __( 'سربرگ', 'music-wave-core' ) ),
			),
			array(
				'name'        => 'music-wave/artists-toolbar',
				'title'       => __( 'ابزارهای هنرمندان', 'music-wave-core' ),
				'description' => __( 'نوار ابزار فهرست هنرمندان: جست‌وجوی خواننده، تب‌های ترتیب و کلید شبکه/فهرست؛ بدون نیاز به جاوااسکریپت.', 'music-wave-core' ),
				'icon'        => 'search',
				'keywords'    => array( __( 'هنرمندان', 'music-wave-core' ), __( 'جست‌وجو', 'music-wave-core' ), __( 'مرتب‌سازی', 'music-wave-core' ), __( 'نمایش', 'music-wave-core' ) ),
			),
			array(
				'name'        => 'music-wave/artists-alphabet',
				'title'       => __( 'فیلتر الفبایی هنرمندان', 'music-wave-core' ),
				'description' => __( 'ردیف حروف الفبای فارسی برای مرور خوانندگان؛ حروف بدون هنرمند غیرقابل‌کلیک می‌شوند.', 'music-wave-core' ),
				'icon'        => 'editor-textcolor',
				'keywords'    => array( __( 'هنرمندان', 'music-wave-core' ), __( 'الفبا', 'music-wave-core' ), __( 'فیلتر', 'music-wave-core' ), __( 'حروف', 'music-wave-core' ) ),
			),
			array(
				'name'        => 'music-wave/taxonomy-shelf',
				'title'       => __( 'ویترین طبقه‌بندی', 'music-wave-core' ),
				'description' => __( 'ژانرها، حال‌وهواها یا برچسب‌ها را به‌صورت کاشی‌های مرور قابل کلیک، همراه با تعداد انتشار، در شبکه، نوار یا فهرست نمایش می‌دهد.', 'music-wave-core' ),
				'icon'        => 'tag',
				'keywords'    => array( __( 'ژانرها', 'music-wave-core' ), __( 'حال‌وهواها', 'music-wave-core' ), __( 'برچسب‌ها', 'music-wave-core' ), __( 'مرور', 'music-wave-core' ), __( 'دسته‌ها', 'music-wave-core' ), __( 'ویترین', 'music-wave-core' ) ),
			),
			array(
				'name'        => 'music-wave/term-hero',
				'title'       => __( 'معرفی اصطلاح', 'music-wave-core' ),
				'description' => __( 'سربرگ بایگانی برای صفحات هنرمند، ژانر، حال‌وهوا و برچسب؛ با بنر جلد یا ردیف فشرده شامل نام، توضیحات، تعداد انتشار و گزینهٔ دنبال‌کردن.', 'music-wave-core' ),
				'icon'        => 'format-image',
				'keywords'    => array( __( 'معرفی', 'music-wave-core' ), __( 'سربرگ', 'music-wave-core' ), __( 'هنرمند', 'music-wave-core' ), __( 'ژانر', 'music-wave-core' ), __( 'آرشیو', 'music-wave-core' ), __( 'بنر', 'music-wave-core' ) ),
			),
			array(
				'name'        => 'music-wave/playback-queue',
				'title'       => __( 'صف پخش', 'music-wave-core' ),
				'description' => __( 'صف پخش پایدار شنوندهٔ واردشده با کنترل‌های مرتب‌سازی مجدد، حذف، پاک‌کردن، پخش تصادفی و تکرار؛ بدون نیاز به JavaScript.', 'music-wave-core' ),
				'icon'        => 'playlist-audio',
				'keywords'    => array( __( 'صف', 'music-wave-core' ), __( 'بعدی', 'music-wave-core' ), __( 'پخش‌کننده', 'music-wave-core' ), __( 'کلیک', 'music-wave-core' ), __( 'تکرار', 'music-wave-core' ) ),
			),
			array(
				'name'        => 'music-wave/add-to-queue',
				'title'       => __( 'افزودن به صف', 'music-wave-core' ),
				'description' => __( 'انتشار فعلی یا سنجاق‌شده را به صف پخش شنونده اضافه می‌کند — بعد از قطعهٔ فعلی یا در پایان صف — بدون نیاز به JavaScript.', 'music-wave-core' ),
				'icon'        => 'controls-play',
				'keywords'    => array( __( 'صف', 'music-wave-core' ), __( 'پخش بعدی', 'music-wave-core' ), __( 'اضافه کردن', 'music-wave-core' ), __( 'پخش‌کننده', 'music-wave-core' ) ),
			),
			array(
				'name'        => 'music-wave/account-dashboard',
				'title'       => __( 'داشبورد موسیقی کاربر', 'music-wave-core' ),
				'description' => __( 'منطقه حساب یکپارچه: کتابخانه موسیقی، سفارش‌ها، بارگیری‌ها، آدرس‌ها، روش‌های پرداخت، عضویت و فهرست‌های پخش در یک پیمایش.', 'music-wave-core' ),
				'icon'        => 'dashboard',
				'keywords'    => array( __( 'کتابخانه', 'music-wave-core' ), __( 'حساب کاربری', 'music-wave-core' ) ),
			),
			array(
				'name'        => 'music-wave/dashboard-welcome',
				'title'       => __( 'خوش‌آمدگویی داشبورد', 'music-wave-core' ),
				'description' => __( 'بخش خوش‌آمدگویی حساب با آواتار، نشان VIP، وضعیت اشتراک واقعی و دکمه تمدید.', 'music-wave-core' ),
				'icon'        => 'admin-users',
				'keywords'    => array( __( 'خوش‌آمدگویی', 'music-wave-core' ), __( 'VIP', 'music-wave-core' ) ),
			),
			array(
				'name'        => 'music-wave/dashboard-stats',
				'title'       => __( 'آمار داشبورد', 'music-wave-core' ),
				'description' => __( 'آمار حساب: دانلودها، علاقه‌مندی‌ها، هنرمندان.', 'music-wave-core' ),
				'icon'        => 'chart-bar',
				'keywords'    => array( __( 'آمار', 'music-wave-core' ), __( 'حساب', 'music-wave-core' ) ),
			),
			array(
				'name'        => 'music-wave/dashboard-subscription',
				'title'       => __( 'کارت اشتراک VIP', 'music-wave-core' ),
				'description' => __( 'کارت اشتراک VIP با سطح فعال، نوار پیشرفت و تمدید.', 'music-wave-core' ),
				'icon'        => 'awards',
				'keywords'    => array( __( 'اشتراک', 'music-wave-core' ), __( 'VIP', 'music-wave-core' ) ),
			),
			array(
				'name'        => 'music-wave/dashboard-account',
				'title'       => __( 'جزئیات و ویرایش حساب', 'music-wave-core' ),
				'description' => __( 'فرم یکپارچه جزئیات و ویرایش حساب کاربری با طراحی مدرن.', 'music-wave-core' ),
				'icon'        => 'id',
				'keywords'    => array( __( 'حساب', 'music-wave-core' ), __( 'ویرایش', 'music-wave-core' ), __( 'پروفایل', 'music-wave-core' ) ),
			),
			array(
				'name'        => 'music-wave/dashboard-addresses',
				'title'       => __( 'آدرس‌های حساب', 'music-wave-core' ),
				'description' => __( 'کارت‌های آدرس صورتحساب و حمل‌ونقل.', 'music-wave-core' ),
				'icon'        => 'location',
				'keywords'    => array( __( 'آدرس', 'music-wave-core' ), __( 'حساب', 'music-wave-core' ) ),
			),
			array(
				'name'        => 'music-wave/dashboard-payment',
				'title'       => __( 'روش‌های پرداخت', 'music-wave-core' ),
				'description' => __( 'روش‌های پرداخت ذخیره‌شده.', 'music-wave-core' ),
				'icon'        => 'money-alt',
				'keywords'    => array( __( 'پرداخت', 'music-wave-core' ), __( 'حساب', 'music-wave-core' ) ),
			),
			array(
				'name'        => 'music-wave/dashboard-orders',
				'title'       => __( 'سفارش‌های حساب', 'music-wave-core' ),
				'description' => __( 'جدول سفارش‌ها با حالت فشرده.', 'music-wave-core' ),
				'icon'        => 'list-view',
				'keywords'    => array( __( 'سفارش', 'music-wave-core' ), __( 'حساب', 'music-wave-core' ) ),
			),
			array(
				'name'        => 'music-wave/dashboard-downloads',
				'title'       => __( 'دانلودهای حساب', 'music-wave-core' ),
				'description' => __( 'فهرست دانلودها.', 'music-wave-core' ),
				'icon'        => 'download',
				'keywords'    => array( __( 'دانلود', 'music-wave-core' ), __( 'حساب', 'music-wave-core' ) ),
			),
			array(
				'name'        => 'music-wave/dashboard-requests',
				'title'       => __( 'پیگیری درخواست‌ها', 'music-wave-core' ),
				'description' => __( 'فهرست درخواست‌های ارسال‌شده توسط کاربر با وضعیت و پاسخ مدیریت.', 'music-wave-core' ),
				'icon'        => 'megaphone',
				'keywords'    => array( __( 'درخواست', 'music-wave-core' ), __( 'پیگیری', 'music-wave-core' ), __( 'حساب', 'music-wave-core' ) ),
			),
			array(
				'name'        => 'music-wave/membership-panel',
				'title'       => __( 'عضویت و طرح‌ها', 'music-wave-core' ),
				'description' => __( 'سطوح عضویت فعال مشتری را با انقضا و محصولات طرح VIP قابل خرید نشان می‌دهد.', 'music-wave-core' ),
				'icon'        => 'awards',
				'keywords'    => array( __( 'عضویت', 'music-wave-core' ), __( 'vip', 'music-wave-core' ), __( 'طرح‌ها', 'music-wave-core' ), __( 'اشتراک', 'music-wave-core' ) ),
			),
			array(
				'name'        => 'music-wave/music-library',
				'title'       => __( 'کتابخانه شخصی موسیقی', 'music-wave-core' ),
				'description' => __( 'آهنگ‌ها، آلبوم‌ها، پادکست‌ها و هنرمندانی را که بازدیدکننده ذخیره کرده‌اند را نمایش می‌دهد.', 'music-wave-core' ),
				'icon'        => 'albums',
				'keywords'    => array( __( 'مورد علاقه', 'music-wave-core' ), __( 'ذخیره‌شده', 'music-wave-core' ), __( 'مجموعه', 'music-wave-core' ) ),
			),
			array(
				'name'        => 'music-wave/library-button',
				'title'       => __( 'دکمه افزودن به کتابخانه', 'music-wave-core' ),
				'description' => __( 'به بازدیدکنندگان اجازه می‌دهد انتشاری را ذخیره کنند، آن را در فهرست علاقه‌مندی‌ها قرار دهند، انتشاری آینده را از قبل ذخیره کنند، یا هنرمندی را دنبال کنند.', 'music-wave-core' ),
				'icon'        => 'plus-alt',
				'keywords'    => array( __( 'ذخیره', 'music-wave-core' ), __( 'دنبال‌کردن', 'music-wave-core' ), __( 'مورد علاقه', 'music-wave-core' ) ),
			),
			array(
				'name'        => 'music-wave/playlists',
				'title'       => __( 'فهرست‌های پخش', 'music-wave-core' ),
				'description' => __( 'به شنوندگانی که وارد سیستم شده‌اند اجازه می‌دهد فهرست‌های پخش خود را ایجاد، سفارش، اشتراک‌گذاری و حذف کنند.', 'music-wave-core' ),
				'icon'        => 'playlist-audio',
				'keywords'    => array( __( 'فهرست پخش', 'music-wave-core' ), __( 'صف', 'music-wave-core' ), __( 'مجموعه', 'music-wave-core' ) ),
			),
			array(
				'name'        => 'music-wave/add-to-playlist',
				'title'       => __( 'افزودن به فهرست پخش', 'music-wave-core' ),
				'description' => __( 'انتشار فعلی را به یکی از فهرست‌های پخش شنونده اضافه می‌کند.', 'music-wave-core' ),
				'icon'        => 'plus-alt',
				'keywords'    => array( __( 'فهرست پخش', 'music-wave-core' ), __( 'ذخیره', 'music-wave-core' ), __( 'صف', 'music-wave-core' ) ),
			),
			array(
				'name'        => 'music-wave/public-playlists',
				'title'       => __( 'فهرست پخش عمومی', 'music-wave-core' ),
				'description' => __( 'فهرست‌های پخش عمومی جامعه را با جست‌وجو و صفحه‌بندی مرور و پخش کنید.', 'music-wave-core' ),
				'icon'        => 'groups',
				'keywords'    => array( __( 'فهرست پخش', 'music-wave-core' ), __( 'جامعه', 'music-wave-core' ), __( 'عمومی', 'music-wave-core' ) ),
			),
			array(
				'name'        => 'music-wave/continue-listening',
				'title'       => __( 'به گوش‌دادن ادامه دهید', 'music-wave-core' ),
				'description' => __( 'به هر شنونده انتشارهای اخیراً پخش‌شده و در دست اجرا خود را نشان می‌دهد، با انتخاب اول رضایت برای بازدیدکنندگانی که سابقهٔ گوش‌دادن را فعال نکرده‌اند.', 'music-wave-core' ),
				'icon'        => 'controls-back',
				'keywords'    => array( __( 'ادامه', 'music-wave-core' ), __( 'اخیراً پخش‌شده', 'music-wave-core' ), __( 'تاریخ', 'music-wave-core' ), __( 'سابقه', 'music-wave-core' ), __( 'گوش‌دادن', 'music-wave-core' ) ),
			),
			array(
				'name'        => 'music-wave/preview-button',
				'title'       => __( 'دکمه پیش‌نمایش', 'music-wave-core' ),
				'description' => __( 'یک دکمه پیش‌نمایش صوتی انتشار را نمایش می‌دهد.', 'music-wave-core' ),
				'icon'        => 'controls-play',
				'keywords'    => array( __( 'پخش', 'music-wave-core' ), __( 'گوش کن', 'music-wave-core' ) ),
			),
			array(
				'name'        => 'music-wave/share-button',
				'title'       => __( 'اشتراک‌گذاری انتشار', 'music-wave-core' ),
				'description' => __( 'دکمه اشتراک‌گذاری بومی برای انتشار فعلی با Web Share API و بازگشت کپی پیوند.', 'music-wave-core' ),
				'icon'        => 'share',
				'keywords'    => array( __( 'اشتراک', 'music-wave-core' ), __( 'share', 'music-wave-core' ), __( 'پیوند', 'music-wave-core' ) ),
			),
			array(
				'name'        => 'music-wave/shuffle-button',
				'title'       => __( 'پخش تصادفی', 'music-wave-core' ),
				'description' => __( 'انتشار فعلی یا مجموعه را به صورت تصادفی پخش می‌کند.', 'music-wave-core' ),
				'icon'        => 'randomize',
				'keywords'    => array( __( 'تصادفی', 'music-wave-core' ), __( 'shuffle', 'music-wave-core' ), __( 'پخش', 'music-wave-core' ) ),
			),
			array(
				'name'        => 'music-wave/request-form',
				'title'       => __( 'فرم درخواست آهنگ و همکاری', 'music-wave-core' ),
				'description' => __( 'فرم عمومی سفارش آهنگ اختصاصی و پیشنهاد همکاری؛ با حالت ترکیبی یا اختصاصی (فقط سفارش آهنگ / فقط همکاری). درخواست‌ها در بخش «درخواست‌ها و همکاری» مدیریت می‌شوند.', 'music-wave-core' ),
				'icon'        => self::request_form_icon(),
				'keywords'    => array( __( 'درخواست', 'music-wave-core' ), __( 'همکاری', 'music-wave-core' ), __( 'سفارش آهنگ', 'music-wave-core' ), __( 'آهنگ اختصاصی', 'music-wave-core' ) ),
			),
		);

		return array_values(
			array_map(
				static function ( array $item ): array {
					return BlockMetadata::editor_entry( (string) $item['name'], $item );
				},
				$localized_ui
			)
		);
	}
}
