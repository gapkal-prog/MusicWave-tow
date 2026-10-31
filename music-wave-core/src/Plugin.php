<?php
/**
 * Main plugin composition root.
 *
 * @package ManaCore\MusicWave\Core
 */

declare(strict_types=1);

namespace ManaCore\MusicWave\Core;

use ManaCore\MusicWave\Core\Access\AccessPolicyEngine;
use ManaCore\MusicWave\Core\Access\ManualAccessProvider;
use ManaCore\MusicWave\Core\Access\MembershipProvider;
use ManaCore\MusicWave\Core\Access\NullManualAccessProvider;
use ManaCore\MusicWave\Core\Access\NullMembershipProvider;
use ManaCore\MusicWave\Core\Admin\ProductReleasePanel;
use ManaCore\MusicWave\Core\Admin\EditorAssets;
use ManaCore\MusicWave\Core\Admin\DiagnosticsPage;
use ManaCore\MusicWave\Core\Blocks\ReleaseBlocks;
use ManaCore\MusicWave\Core\Blocks\ArtistProfileBlock;
use ManaCore\MusicWave\Core\Blocks\ArtistShelfBlock;
use ManaCore\MusicWave\Core\Blocks\LibraryBlocks;
use ManaCore\MusicWave\Core\Blocks\TaxonomyShelfBlock;
use ManaCore\MusicWave\Core\Blocks\TermHeroBlock;
use ManaCore\MusicWave\Core\Blocks\PreviewPlayer;
use ManaCore\MusicWave\Core\Blocks\ShareButtonBlock;
use ManaCore\MusicWave\Core\Blocks\ShuffleButtonBlock;
use ManaCore\MusicWave\Core\Admin\ReleaseMetaBox;
use ManaCore\MusicWave\Core\Admin\ReleaseReadiness;
use ManaCore\MusicWave\Core\Admin\CollectionCandidateRoutes;
use ManaCore\MusicWave\Core\Admin\BulkAccessManager;
use ManaCore\MusicWave\Core\Admin\SettingsPage;
use ManaCore\MusicWave\Core\Catalog\ReleasePostType;
use ManaCore\MusicWave\Core\Catalog\ReleaseTaxonomies;
use ManaCore\MusicWave\Core\Catalog\ReleaseVisibility;
use ManaCore\MusicWave\Core\Catalog\ArtistTermMeta;
use ManaCore\MusicWave\Core\Catalog\ReleaseArchiveQuery;
use ManaCore\MusicWave\Core\Catalog\ReleasePermalinks;
use ManaCore\MusicWave\Core\Catalog\ReleaseDefaults;
use ManaCore\MusicWave\Core\Commerce\ProductMapper;
use ManaCore\MusicWave\Core\Commerce\PurchaseChecker;
use ManaCore\MusicWave\Core\Commerce\AccountLibrary;
use ManaCore\MusicWave\Core\Infrastructure\WordPressReleaseRepository;
use ManaCore\MusicWave\Core\Infrastructure\CollectionRestPolicy;
use ManaCore\MusicWave\Core\Infrastructure\ReleaseRestVisibilityPolicy;
use ManaCore\MusicWave\Core\Library\LibraryCatalog;
use ManaCore\MusicWave\Core\Library\LibraryRepository;
use ManaCore\MusicWave\Core\Library\LibraryRoutes;
use ManaCore\MusicWave\Core\Library\PreSaveScheduler;
use ManaCore\MusicWave\Core\Playlists\DatabasePlaylistStore;
use ManaCore\MusicWave\Core\Playlists\PlaylistFormHandler;
use ManaCore\MusicWave\Core\Playlists\PlaylistRepository;
use ManaCore\MusicWave\Core\Playlists\PlaylistRoutes;
use ManaCore\MusicWave\Core\Blocks\PlaylistBlocks;
use ManaCore\MusicWave\Core\Downloads\DownloadResolver;
use ManaCore\MusicWave\Core\Downloads\DownloadRoutes;
use ManaCore\MusicWave\Core\Downloads\DownloadAssetRoutes;
use ManaCore\MusicWave\Core\Downloads\DownloadTokenService;
use ManaCore\MusicWave\Core\Downloads\NullDownloadProvider;
use ManaCore\MusicWave\Core\Downloads\SegmentSigner;
use ManaCore\MusicWave\Core\Downloads\DatabaseReplayStore;
use ManaCore\MusicWave\Core\Downloads\TransientReplayStore;
use ManaCore\MusicWave\Core\Migrations\MigrationSet;
use ManaCore\MusicWave\Core\Metadata\DeezerProvider;
use ManaCore\MusicWave\Core\Metadata\DiscogsProvider;
use ManaCore\MusicWave\Core\Metadata\ITunesProvider;
use ManaCore\MusicWave\Core\Metadata\LastFmProvider;
use ManaCore\MusicWave\Core\Metadata\MetadataLookupRoutes;
use ManaCore\MusicWave\Core\Metadata\MetadataResolver;
use ManaCore\MusicWave\Core\Metadata\MetadataTaxonomyMapper;
use ManaCore\MusicWave\Core\Metadata\MusicBrainzProvider;
use ManaCore\MusicWave\Core\Metadata\SpotifyProvider;
use ManaCore\MusicWave\Core\Modules\Admin;
use ManaCore\MusicWave\Core\Modules\Catalog;
use ManaCore\MusicWave\Core\Modules\Commerce;
use ManaCore\MusicWave\Core\Modules\Foundation;
use ManaCore\MusicWave\Core\Modules\Downloads;
use ManaCore\MusicWave\Core\Modules\Diagnostics;
use ManaCore\MusicWave\Core\Modules\Library;
use ManaCore\MusicWave\Core\Modules\Notifications;
use ManaCore\MusicWave\Core\Modules\Rendering;
use ManaCore\MusicWave\Core\Modules\Seo;
use ManaCore\MusicWave\Core\Notifications\FollowNotifier;
use ManaCore\MusicWave\Core\Notifications\LibraryFollowerDirectory;
use ManaCore\MusicWave\Core\Notifications\NotificationPreferences;
use ManaCore\MusicWave\Core\Playback\PlaybackQueueRoutes;
use ManaCore\MusicWave\Core\Playback\StreamSessionCookie;
use ManaCore\MusicWave\Core\Playback\StreamSessionStore;
use ManaCore\MusicWave\Core\Schema\ReleaseMetaRegistry;
use ManaCore\MusicWave\Core\Schema\ReleaseMetaSchema;
use ManaCore\MusicWave\Core\Seo\ReleaseJsonLd;
use ManaCore\MusicWave\Core\Seo\ReleaseMetadata;
use ManaCore\MusicWave\Core\Support\ModuleRegistry;
use ManaCore\MusicWave\Core\Support\SiteHealth;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {
	/** @var self|null */
	private static $instance;

	/** @var bool */
	private $booted = false;

	/** @var AccessPolicyEngine|null Composed access policy, shared read-only. */
	private $access_policy;

	/**
	 * Get the single composition root instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * The composed access policy engine, or null before boot.
	 *
	 * Exposed so integration plugins (e.g. MusicWave VIP) can reuse Core's
	 * single composed policy instance instead of rebuilding one from scratch —
	 * keeping entitlement decisions consistent across Core and its extensions.
	 */
	public function access_policy(): ?AccessPolicyEngine {
		return $this->access_policy;
	}

	/**
	 * Compose and register all plugin modules once.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$registry            = new ModuleRegistry();
		$schema              = new ReleaseMetaSchema();
		$releases            = new WordPressReleaseRepository( $schema );
		$visibility          = new ReleaseVisibility();
		$mapper              = new ProductMapper();
		$purchase_checker    = new PurchaseChecker( $releases );
		$membership_provider = apply_filters( 'music_wave_membership_provider', new NullMembershipProvider() );
		$manual_provider     = apply_filters( 'music_wave_manual_access_provider', new NullManualAccessProvider() );
		if ( ! $membership_provider instanceof MembershipProvider ) {
			$membership_provider = new NullMembershipProvider();
		}
		if ( ! $manual_provider instanceof ManualAccessProvider ) {
			$manual_provider = new NullManualAccessProvider();
		}
		$policy              = new AccessPolicyEngine( $releases, $purchase_checker, $membership_provider, $manual_provider );
		$this->access_policy = $policy;
		// Membership gating is a monetization layer: when no membership module
		// (e.g. MusicWave VIP) is active, the configured absent-behavior decides
		// whether membership releases stay usable (default) or deny closed.
		add_filter(
			'music_wave_membership_absent_behavior',
			static function (): string {
				$setting = \ManaCore\MusicWave\Core\Support\Settings::get( 'membership_absent_behavior' );

				return \ManaCore\MusicWave\Core\Access\MembershipAbsencePolicy::sanitize( $setting );
			}
		);
		( new \ManaCore\MusicWave\Core\Support\SettingsFilters() )->register();
		$external_provider = apply_filters( 'music_wave_download_provider', new NullDownloadProvider() );
		if ( ! $external_provider instanceof \ManaCore\MusicWave\Core\Downloads\DownloadProvider ) {
			$external_provider = new NullDownloadProvider();
		}
		$direct_provider       = new \ManaCore\MusicWave\Core\Downloads\DirectLinkProvider();
		$download_provider     = new \ManaCore\MusicWave\Core\Downloads\DeliveryRouter( $direct_provider, $external_provider );
		$delivery_capabilities = new \ManaCore\MusicWave\Core\Downloads\DeliveryCapabilities( $external_provider );
		// Dedicated salt scheme (review finding S4): the download-token secret
		// derives from its own wp_salt() scheme instead of 'auth', isolating its
		// blast radius from cookie/nonce secrets. Sites may pin it independently
		// via the MUSIC_WAVE_DOWNLOAD_KEY / MUSIC_WAVE_DOWNLOAD_SALT constants.
		$download_secret = function_exists( 'wp_salt' ) ? wp_salt( 'music_wave_download' ) : hash( 'sha256', MUSIC_WAVE_CORE_FILE );
		$replay_store    = new DatabaseReplayStore( new TransientReplayStore() );
		$ticket_store    = new \ManaCore\MusicWave\Core\Downloads\OpaqueTicketStore();
		$downloads       = new DownloadResolver( $releases, $policy, new DownloadTokenService( $download_secret ), $replay_store, $download_provider, $ticket_store, $delivery_capabilities );

		// Phase 1+2 clean: shared HTTP client + dedicated cover importer + cover resolver (SRP, DRY, DIP).
		// Phase 5: iTunes (no key, 600px), Deezer (no key, 1000px), Last.fm (optional key, artist bio).
		$metadata_http       = new \ManaCore\MusicWave\Core\Metadata\Http\WpHttpClient();
		$cover_importer      = new \ManaCore\MusicWave\Core\Metadata\CoverImportService();
		$cover_resolver      = new \ManaCore\MusicWave\Core\Metadata\CoverArtResolver();
		$metadata_resolver   = new MetadataResolver(
			array(
				new SpotifyProvider( (string) \ManaCore\MusicWave\Core\Support\Settings::get( 'spotify_client_id' ), (string) \ManaCore\MusicWave\Core\Support\Settings::get( 'spotify_client_secret' ), $metadata_http ),
				new DiscogsProvider( (string) \ManaCore\MusicWave\Core\Support\Settings::get( 'discogs_token' ), $metadata_http ),
				new MusicBrainzProvider( $metadata_http ),
				new ITunesProvider( $metadata_http ),
				new DeezerProvider( $metadata_http ),
				new LastFmProvider( (string) \ManaCore\MusicWave\Core\Support\Settings::get( 'lastfm_api_key' ), $metadata_http ),
			),
			$cover_importer,
			$cover_resolver
		);
		$metadata_taxonomies = new MetadataTaxonomyMapper();

		$library_repository = new LibraryRepository( $visibility );
		$library_catalog    = new LibraryCatalog( $library_repository, $visibility );

		$playlists = new PlaylistRepository( new DatabasePlaylistStore(), $visibility );

		$listening_repository = new \ManaCore\MusicWave\Core\Listening\ListeningRepository( $visibility );

		$notification_preferences = new NotificationPreferences();

		$request_repository = new \ManaCore\MusicWave\Core\Requests\RequestRepository();
		$request_settings   = new \ManaCore\MusicWave\Core\Requests\RequestSettings();
		$request_mailer     = new \ManaCore\MusicWave\Core\Requests\RequestMailer( $request_settings );
		$request_forms      = new \ManaCore\MusicWave\Core\Requests\RequestFormHandler( $request_repository, $request_mailer, $request_settings );
		$request_chat       = new \ManaCore\MusicWave\Core\Requests\RequestChatHandler( $request_repository, $request_settings, $request_mailer );

		$account_library = new AccountLibrary(
			$policy,
			$releases,
			$library_repository,
			new \ManaCore\MusicWave\Core\Account\MembershipPresenter( $policy ),
			new \ManaCore\MusicWave\Core\Account\StatsPresenter( $library_repository ),
			new \ManaCore\MusicWave\Core\Account\WooAccountBridge(),
			new \ManaCore\MusicWave\Core\Account\RequestTrackingRenderer( $request_repository, $request_settings, $request_chat )
		);

		// Shared with Activator and WP-CLI via MigrationSet so a new SchemaXxx
		// cannot reach one caller and not the others (review findings B1, B4).
		$migration_runner = MigrationSet::runner();

		$registry->add( new Foundation() );
		$registry->add(
			new Catalog(
				new ReleasePostType(),
				new ReleaseTaxonomies(),
				new ReleaseMetaRegistry( $schema ),
				$migration_runner,
				new CollectionRestPolicy( $releases ),
				new ArtistTermMeta(),
				new ReleaseArchiveQuery(),
				new ReleaseDefaults( $releases ),
				new ReleasePermalinks(),
				new \ManaCore\MusicWave\Core\Discovery\ScriptAwareSearchQuery()
			)
		);
		$registry->add( new Admin( new ReleaseMetaBox( $schema, $releases, $mapper ), new EditorAssets(), new ReleaseReadiness( $releases ), new CollectionCandidateRoutes(), new SettingsPage( new BulkAccessManager( $releases ), $playlists, $listening_repository ) ) );
		$registry->add( new \ManaCore\MusicWave\Core\Modules\Auth( new \ManaCore\MusicWave\Core\Account\AuthForms(), new \ManaCore\MusicWave\Core\Account\AuthHandler() ) );
		$registry->add( new Commerce( $mapper, new ProductReleasePanel( $mapper ), $account_library ) );
		$registry->add( new Library( $library_repository, new LibraryRoutes( $library_repository, $library_catalog ), new LibraryBlocks( $library_catalog ), new PreSaveScheduler( $library_repository ) ) );
		$registry->add(
			new Notifications(
				new FollowNotifier( $library_repository, $notification_preferences, $visibility, new LibraryFollowerDirectory( $library_repository, $releases ) )
			)
		);
		$playlist_forms = new PlaylistFormHandler( $playlists );
		$registry->add( new \ManaCore\MusicWave\Core\Modules\Playlists( $playlists, new PlaylistRoutes( $playlists, $policy, $releases ), $playlist_forms, new PlaylistBlocks( $playlists, $playlist_forms ) ) );
		$registry->add( new Rendering( new ReleaseBlocks( $policy, $releases ), new ArtistProfileBlock(), new PreviewPlayer( $releases, $policy ), new PlaybackQueueRoutes( $policy, $releases ), new ReleaseRestVisibilityPolicy( $policy ), new ArtistShelfBlock(), new TaxonomyShelfBlock(), new TermHeroBlock(), new ShareButtonBlock(), new ShuffleButtonBlock( $releases, $policy ), new \ManaCore\MusicWave\Core\Blocks\SearchUnifiedBlock(), $releases, $playlists ) );
		// Playback-session services (ADR 0006). Core owns the session REST routes
		// but never loads a delivery plugin's classes: an integration (e.g.
		// MusicWave VIP) provides the store/cookie/signer through filters, which
		// are resolved here — during Core's boot — so the routes are registered on
		// `rest_api_init`, never earlier.
		$session_store   = apply_filters( 'music_wave_stream_session_store', null );
		$session_cookie  = apply_filters( 'music_wave_stream_session_cookie', null );
		$stream_signer   = apply_filters( 'music_wave_stream_signer', null );
		$download_routes = new DownloadRoutes(
			$downloads,
			null,
			$session_store instanceof StreamSessionStore ? $session_store : null,
			$session_cookie instanceof StreamSessionCookie ? $session_cookie : null,
			$stream_signer instanceof SegmentSigner ? $stream_signer : null
		);
		$registry->add( new Downloads( $download_routes, new DownloadAssetRoutes( $releases ), $replay_store, $ticket_store ) );

		// A playback session must not outlive the login it was created under:
		// revoke the user's sessions on logout, password reset, and deletion.
		if ( $session_store instanceof StreamSessionStore ) {
			$revoke_user_sessions = static function ( $subject ) use ( $session_store ): void {
				$user_id = $subject instanceof \WP_User ? (int) $subject->ID : (int) $subject;
				if ( $user_id > 0 ) {
					$session_store->revoke_for_user( $user_id );
				}
			};
			add_action( 'wp_logout', $revoke_user_sessions );
			add_action( 'after_password_reset', $revoke_user_sessions );
			add_action( 'deleted_user', $revoke_user_sessions );
		}
		$registry->add( new Diagnostics( new DiagnosticsPage(), new SiteHealth() ) );
		$queue_forms = new \ManaCore\MusicWave\Core\Listening\QueueFormHandler( $listening_repository );
		$registry->add(
			new \ManaCore\MusicWave\Core\Modules\Listening(
				$listening_repository,
				new \ManaCore\MusicWave\Core\Listening\ListeningRoutes( $listening_repository, null, $queue_forms ),
				new \ManaCore\MusicWave\Core\Discovery\DiscoveryRoutes(
					new \ManaCore\MusicWave\Core\Discovery\Recommendations( $listening_repository, $visibility ),
					new \ManaCore\MusicWave\Core\Discovery\CatalogSearch( $visibility ),
					new \ManaCore\MusicWave\Core\Discovery\DiscoveryRateLimiter()
				),
				new \ManaCore\MusicWave\Core\Blocks\ListeningBlocks( $listening_repository ),
				$queue_forms,
				new \ManaCore\MusicWave\Core\Blocks\QueueBlock( $listening_repository, $queue_forms )
			)
		);
		$registry->add( new Seo( new ReleaseJsonLd( $releases, $visibility ), new ReleaseMetadata( $releases ) ) );
		$registry->add(
			new \ManaCore\MusicWave\Core\Modules\Requests(
				new \ManaCore\MusicWave\Core\Requests\RequestPostType(),
				$request_forms,
				new \ManaCore\MusicWave\Core\Blocks\RequestFormBlock( $request_forms, $request_settings ),
				new \ManaCore\MusicWave\Core\Requests\RequestsAdminPage( $request_repository, $request_mailer, $request_settings ),
				new \ManaCore\MusicWave\Core\Requests\RequestPrivacy( $request_repository ),
				$request_chat
			)
		);
		$registry->add( new \ManaCore\MusicWave\Core\Modules\Metadata( new MetadataLookupRoutes( $metadata_resolver, $metadata_taxonomies ), $metadata_taxonomies ) );

		/**
		 * Filter additional MusicWave Core modules.
		 *
		 * @param array $modules Module instances implementing the Module contract.
		 */
		$modules = apply_filters( 'music_wave_core_modules', array() );
		$registry->add_filtered( $modules );
		$registry->register_all();

		// The catalog autocomplete is registered (not enqueued) on every request so
		// the theme's header search can reuse the same script handle.
		( new \ManaCore\MusicWave\Core\Support\SuggestAssets() )->register();
		( new \ManaCore\MusicWave\Core\Support\BlockShortcode() )->register();
		( new \ManaCore\MusicWave\Core\Support\FrontendRuntime() )->register();
		( new \ManaCore\MusicWave\Core\Cli\Commands( $migration_runner ) )->register();
		( new \ManaCore\MusicWave\Core\Privacy\PersonalData( $library_repository, $listening_repository, $playlists, $notification_preferences ) )->register();
		( new \ManaCore\MusicWave\Core\Privacy\PolicyContent() )->register();
		( new \ManaCore\MusicWave\Core\Analytics\ViewCounter() )->register();
		( new \ManaCore\MusicWave\Core\Analytics\ViewRoutes() )->register();
		( new \ManaCore\MusicWave\Core\Support\AuthorEnumerationProtection() )->register();
		( new \ManaCore\MusicWave\Core\Playlists\PlaylistLimits() )->register();
		( new \ManaCore\MusicWave\Core\Playlists\PublicPlaylistSeo() )->register();

		$this->booted = true;
		do_action( 'music_wave_core_loaded', $this );
	}

	private function __construct() {}
}
