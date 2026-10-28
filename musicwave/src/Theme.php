<?php
declare(strict_types=1);

namespace ManaCore\MusicWave\Theme;

use ManaCore\MusicWave\Theme\Assets\AssetManager;
use ManaCore\MusicWave\Theme\Blocks\BlockRegistry;
use ManaCore\MusicWave\Theme\Blocks\BreadcrumbRenderer;
use ManaCore\MusicWave\Theme\Blocks\ChromeBlocksRenderer;
use ManaCore\MusicWave\Theme\Blocks\ShelfAndSliderRenderer;
use ManaCore\MusicWave\Theme\Header\HeaderSearchEnhancer;
use ManaCore\MusicWave\Theme\Header\SiteHeaderManager;
use ManaCore\MusicWave\Theme\Navigation\NavigationIconService;
use ManaCore\MusicWave\Theme\Routing\RouteManager;
use ManaCore\MusicWave\Theme\Templates\TemplateManager;
use ManaCore\MusicWave\Theme\Widgets\WidgetAreaSeeder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central service container and lifecycle kernel for the MusicWave theme.
 */
final class Theme {

	/** @var self|null */
	private static ?self $instance = null;

	/** @var AssetManager */
	private AssetManager $assets;

	/** @var TemplateManager */
	private TemplateManager $templates;

	/** @var BlockRegistry */
	private BlockRegistry $blocks;

	/** @var ShelfAndSliderRenderer */
	private ShelfAndSliderRenderer $shelves;

	/** @var ChromeBlocksRenderer */
	private ChromeBlocksRenderer $chrome;

	/** @var BreadcrumbRenderer */
	private BreadcrumbRenderer $breadcrumbs;

	/** @var SiteHeaderManager */
	private SiteHeaderManager $header;

	/** @var HeaderSearchEnhancer */
	private HeaderSearchEnhancer $header_search;

	/** @var NavigationIconService */
	private NavigationIconService $nav_icons;

	/** @var RouteManager */
	private RouteManager $routes;

	/** @var WidgetAreaSeeder */
	private WidgetAreaSeeder $widget_seeder;

	/** @var bool */
	private bool $booted = false;

	public function __construct(
		?AssetManager $assets = null,
		?TemplateManager $templates = null,
		?BlockRegistry $blocks = null,
		?ShelfAndSliderRenderer $shelves = null,
		?ChromeBlocksRenderer $chrome = null,
		?SiteHeaderManager $header = null,
		?HeaderSearchEnhancer $header_search = null,
		?NavigationIconService $nav_icons = null,
		?RouteManager $routes = null,
		?WidgetAreaSeeder $widget_seeder = null,
		?BreadcrumbRenderer $breadcrumbs = null
	) {
		$this->assets        = $assets ?? new AssetManager();
		$this->blocks        = $blocks ?? new BlockRegistry();
		$this->templates     = $templates ?? new TemplateManager( $this->assets );
		$this->shelves       = $shelves ?? new ShelfAndSliderRenderer( $this->blocks );
		$this->chrome        = $chrome ?? new ChromeBlocksRenderer();
		$this->breadcrumbs   = $breadcrumbs ?? new BreadcrumbRenderer( $this->blocks, $this->chrome );
		$this->routes        = $routes ?? new RouteManager();
		$this->header        = $header ?? new SiteHeaderManager( $this->routes );
		$this->header_search = $header_search ?? new HeaderSearchEnhancer( $this->header );
		$this->nav_icons     = $nav_icons ?? new NavigationIconService();
		$this->widget_seeder = $widget_seeder ?? new WidgetAreaSeeder();
	}

	/**
	 * Shared singleton instance for theme bootstrap and template helpers.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register all WordPress hooks across every theme service once.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->assets->register();
		$this->templates->register();
		$this->widget_seeder->register();
		$this->blocks->register();
		$this->chrome->register();
		$this->routes->register();
		$this->header->register();
		$this->header_search->register();
		$this->nav_icons->register();

		$this->booted = true;
	}

	public function assets(): AssetManager {
		return $this->assets;
	}

	public function templates(): TemplateManager {
		return $this->templates;
	}

	public function blocks(): BlockRegistry {
		return $this->blocks;
	}

	public function shelves(): ShelfAndSliderRenderer {
		return $this->shelves;
	}

	public function chrome(): ChromeBlocksRenderer {
		return $this->chrome;
	}

	public function breadcrumbs(): BreadcrumbRenderer {
		return $this->breadcrumbs;
	}

	public function header(): SiteHeaderManager {
		return $this->header;
	}

	public function header_search(): HeaderSearchEnhancer {
		return $this->header_search;
	}

	public function nav_icons(): NavigationIconService {
		return $this->nav_icons;
	}

	public function routes(): RouteManager {
		return $this->routes;
	}

	public function widget_seeder(): WidgetAreaSeeder {
		return $this->widget_seeder;
	}
}
